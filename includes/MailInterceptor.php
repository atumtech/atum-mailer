<?php
/**
 * Mail interception and delivery pipeline.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Mail_Interceptor {
	const QUEUE_CRON_HOOK = 'atum_mailer_process_queue';
	const QUEUE_LOCK_KEY  = 'atum_mailer_queue_processing_lock';

	/**
	 * Settings repository.
	 *
	 * @var Atum_Mailer_Settings_Repository
	 */
	private $settings;

	/**
	 * Log repository.
	 *
	 * @var Atum_Mailer_Log_Repository
	 */
	private $logs;

	/**
	 * Postmark client.
	 *
	 * @var Atum_Mailer_Postmark_Client
	 */
	private $client;

	/**
	 * Queue repository.
	 *
	 * @var Atum_Mailer_Queue_Repository_Interface
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Atum_Mailer_Settings_Repository $settings Settings.
	 * @param Atum_Mailer_Log_Repository      $logs Logs.
	 * @param Atum_Mailer_Postmark_Client     $client Client.
	 * @param Atum_Mailer_Queue_Repository_Interface|null $queue Queue repository.
	 */
	public function __construct( Atum_Mailer_Settings_Repository $settings, Atum_Mailer_Log_Repository $logs, Atum_Mailer_Postmark_Client $client, $queue = null ) {
		$this->settings = $settings;
		$this->logs     = $logs;
		$this->client   = $client;
		$this->queue    = $queue instanceof Atum_Mailer_Queue_Repository_Interface ? $queue : new Atum_Mailer_Option_Queue_Repository( $settings );
	}

	/**
	 * Send WordPress email via Postmark if enabled.
	 *
	 * @param null|bool            $pre_wp_mail Existing pre-filter value.
	 * @param array<string, mixed> $atts wp_mail attributes.
	 * @return null|bool
	 */
	public function maybe_send_with_postmark( $pre_wp_mail, $atts ) {
		if ( null !== $pre_wp_mail ) {
			return $pre_wp_mail;
		}

		$options = $this->settings->get_options();
		$token   = (string) $options['postmark_server_token'];
		if ( empty( $options['enabled'] ) || '' === $token ) {
			return $pre_wp_mail;
		}

		$delivery_mode = (string) ( $options['delivery_mode'] ?? 'immediate' );

		$bypass = apply_filters( 'atum_mailer_bypass', false, $atts, $options );
		if ( $bypass ) {
			$log_id = $this->logs->insert_mail_log(
				$atts,
				'bypassed',
				$options,
				array(
					'error_message' => __( 'Message bypassed by filter: atum_mailer_bypass.', 'atum-mailer' ),
				)
			);
			$this->logs->update_mail_log( $log_id, 'bypassed', array(), $options );
			return $pre_wp_mail;
		}

		$initial_status = 'queue' === $delivery_mode ? 'queued' : 'processing';
		$initial_attempt = 'queue' === $delivery_mode ? 0 : 1;
		$log_id = $this->logs->insert_mail_log(
			$atts,
			$initial_status,
			$options,
			array(
				'attempt_count' => $initial_attempt,
				'delivery_mode' => $delivery_mode,
			)
		);

		$payload = $this->build_payload( $atts, $options );
		if ( is_wp_error( $payload ) ) {
			$this->handle_failure( $payload, $options, $log_id, 1, $delivery_mode );
			return false;
		}

		if ( 'queue' === $delivery_mode ) {
			$this->enqueue_job( $payload, $log_id );
			do_action( 'atum_mailer_queued', $payload, $log_id );
			return true;
		}

		$result = $this->client->send( $payload, $token, $atts, $options );

		if ( is_wp_error( $result ) ) {
			if ( $this->maybe_fallback_to_wp_mail( $result, $options, $log_id, $payload, $atts ) ) {
				return null;
			}

			$this->handle_failure( $result, $options, $log_id, 1, 'immediate' );
			return false;
		}

		$this->logs->update_mail_log(
			$log_id,
			'sent',
			array(
				'provider_message_id' => $result['message_id'],
				'http_status'         => $result['status_code'],
				'request_payload'     => $payload,
				'response_body'       => $result['body'],
				'attempt_count'       => 1,
				'next_attempt_at'     => null,
				'last_error_code'     => '',
				'delivery_mode'       => 'immediate',
			),
			$options
		);

		do_action( 'atum_mailer_sent', $payload, $result['response'] );
		return true;
	}

	/**
	 * Resend a previously logged payload.
	 *
	 * @param array<string, mixed> $payload Saved request payload.
	 * @param string               $mode_override Optional delivery mode override.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resend_saved_payload( $payload, $mode_override = '' ) {
		$options = $this->settings->get_options();
		$token   = (string) $options['postmark_server_token'];
		if ( empty( $options['enabled'] ) || '' === $token ) {
			return new WP_Error( 'atum_mailer_not_configured', __( 'atum.mailer is not enabled or missing an API token.', 'atum-mailer' ) );
		}

		if ( ! is_array( $payload ) || empty( $payload['To'] ) ) {
			return new WP_Error( 'atum_mailer_invalid_payload', __( 'Saved payload is missing required recipient fields.', 'atum-mailer' ) );
		}

		$delivery_mode = sanitize_key( (string) $mode_override );
		if ( ! in_array( $delivery_mode, array( 'immediate', 'queue' ), true ) ) {
			$delivery_mode = (string) ( $options['delivery_mode'] ?? 'immediate' );
		}

		$atts           = $this->payload_to_log_atts( $payload );
		$initial_status = 'queue' === $delivery_mode ? 'queued' : 'processing';
		$initial_count  = 'queue' === $delivery_mode ? 0 : 1;
		$log_id         = $this->logs->insert_mail_log(
			$atts,
			$initial_status,
			$options,
			array(
				'attempt_count' => $initial_count,
				'delivery_mode' => $delivery_mode,
			)
		);

		if ( 'queue' === $delivery_mode ) {
			$this->enqueue_job( $payload, $log_id );
			return array(
				'status' => 'queued',
				'log_id' => $log_id,
			);
		}

		$result = $this->client->send( $payload, $token, $atts, $options );
		if ( is_wp_error( $result ) ) {
			if ( $this->maybe_fallback_to_wp_mail( $result, $options, $log_id, $payload, $atts ) ) {
				return array(
					'status' => 'fallback',
					'log_id' => $log_id,
				);
			}

			$this->handle_failure( $result, $options, $log_id, 1, 'immediate' );
			return $result;
		}

		$this->logs->update_mail_log(
			$log_id,
			'sent',
			array(
				'provider_message_id' => $result['message_id'],
				'http_status'         => $result['status_code'],
				'request_payload'     => $payload,
				'response_body'       => $result['body'],
				'attempt_count'       => 1,
				'next_attempt_at'     => null,
				'last_error_code'     => '',
				'delivery_mode'       => 'immediate',
			),
			$options
		);

		return array(
			'status' => 'sent',
			'log_id' => $log_id,
		);
	}

	/**
	 * Process queued jobs.
	 *
	 * @return void
	 */
	public function process_queue() {
		if ( get_transient( self::QUEUE_LOCK_KEY ) ) {
			return;
		}

		set_transient( self::QUEUE_LOCK_KEY, 1, MINUTE_IN_SECONDS );

		try {
			$options      = $this->settings->get_options();
			$token        = (string) $options['postmark_server_token'];
			$start_time   = microtime( true );
			$max_jobs     = max( 1, (int) apply_filters( 'atum_mailer_max_jobs_per_run', 25, $options ) );
			$max_runtime  = max( 1, (int) apply_filters( 'atum_mailer_max_runtime_seconds', 20, $options ) );
			$processed    = 0;
			$hit_budget   = false;

			while ( $processed < $max_jobs ) {
				$remaining = max( 1, $max_jobs - $processed );
				$jobs      = $this->queue->claimDue( $remaining, time() );
				if ( empty( $jobs ) ) {
					break;
				}

				foreach ( $jobs as $job ) {
					$job_id  = (string) ( $job['job_id'] ?? '' );
					$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
					$log_id  = isset( $job['log_id'] ) ? absint( $job['log_id'] ) : 0;
					$attempt = isset( $job['attempt_count'] ) ? absint( $job['attempt_count'] ) + 1 : 1;

					$result = $this->client->send( $payload, $token, array(), $options );
					if ( ! is_wp_error( $result ) ) {
						$this->logs->update_mail_log(
							$log_id,
							'sent',
							array(
								'provider_message_id' => $result['message_id'],
								'http_status'         => $result['status_code'],
								'request_payload'     => $payload,
								'response_body'       => $result['body'],
								'attempt_count'       => $attempt,
								'next_attempt_at'     => null,
								'last_error_code'     => '',
								'delivery_mode'       => 'queue',
							),
							$options
						);
						$this->queue->succeed( $job_id );
					} else {
						$error_code = $this->client->normalizeErrorCode( $result );
						$data       = $result->get_error_data();
						$status     = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 0;

						$defaults = array(
							'max_attempts' => max( 1, (int) ( $options['queue_max_attempts'] ?? 5 ) ),
							'base_delay'   => max( 5, (int) ( $options['queue_retry_base_delay'] ?? 60 ) ),
							'max_delay'    => max( 60, (int) ( $options['queue_retry_max_delay'] ?? 3600 ) ),
						);
						$policy   = apply_filters( 'atum_mailer_retry_policy', $defaults, $job, $result, $options );
						$policy   = wp_parse_args( is_array( $policy ) ? $policy : array(), $defaults );

						if ( $this->client->isRetryable( $result ) && $attempt < (int) $policy['max_attempts'] ) {
							$delay = min( (int) $policy['max_delay'], (int) $policy['base_delay'] * ( 2 ** ( $attempt - 1 ) ) );
							$this->queue->release( $job_id, $attempt, time() + $delay, $error_code );

							$this->logs->update_mail_log(
								$log_id,
								'retrying',
								array(
									'error_message'   => $result->get_error_message(),
									'http_status'     => $status,
									'attempt_count'   => $attempt,
									'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
									'last_error_code' => $error_code,
									'delivery_mode'   => 'queue',
								),
								$options
							);
						} else {
							do_action( 'atum_mailer_queue_job_failed_terminal', $job, $result, $attempt, $options );
							$this->handle_failure( $result, $options, $log_id, $attempt, 'queue', 'dead_letter' );
							$this->queue->fail( $job_id );
						}
					}

					$processed++;
					if ( $processed >= $max_jobs || ( microtime( true ) - $start_time ) >= $max_runtime ) {
						$hit_budget = true;
						break;
					}
				}

				if ( $hit_budget ) {
					break;
				}
			}

			$this->schedule_next_queue_run( $hit_budget );
		} finally {
			delete_transient( self::QUEUE_LOCK_KEY );
		}
	}

	/**
	 * Build Postmark payload from wp_mail attributes.
	 *
	 * @param array<string, mixed> $atts wp_mail attributes.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_payload( $atts, $options ) {
		$headers = $this->parse_headers( $atts['headers'] ?? array() );
		$to      = $this->normalize_addresses( $atts['to'] ?? array() );

		if ( empty( $to ) ) {
			return new WP_Error( 'atum_mailer_missing_recipient', __( 'No valid recipient was provided to wp_mail().', 'atum-mailer' ) );
		}

		$from_email = ! empty( $options['from_email'] ) ? sanitize_email( $options['from_email'] ) : '';
		$from_name  = ! empty( $options['from_name'] ) ? sanitize_text_field( $options['from_name'] ) : '';

		if ( empty( $options['force_from'] ) && ! empty( $headers['from_email'] ) ) {
			$from_email = $headers['from_email'];
			$from_name  = $headers['from_name'];
		}

		if ( empty( $from_email ) ) {
			$from_email = sanitize_email( get_bloginfo( 'admin_email' ) );
		}

		$from_email = sanitize_email( apply_filters( 'wp_mail_from', $from_email ) );
		$from_name  = sanitize_text_field( apply_filters( 'wp_mail_from_name', $from_name ) );

		if ( empty( $from_email ) ) {
			return new WP_Error( 'atum_mailer_missing_sender', __( 'No sender email is configured.', 'atum-mailer' ) );
		}

		$content_type = strtolower( apply_filters( 'wp_mail_content_type', $headers['content_type'] ) );
		$message      = (string) ( $atts['message'] ?? '' );
		$payload      = array(
			'From'          => $this->format_from_header( $from_email, $from_name ),
			'To'            => implode( ',', $to ),
			'Subject'       => wp_specialchars_decode( (string) ( $atts['subject'] ?? '' ), ENT_QUOTES ),
			'MessageStream' => (string) $options['message_stream'],
			'TrackOpens'    => ! empty( $options['track_opens'] ),
			'TrackLinks'    => (string) $options['track_links'],
		);

		if ( 'text/html' === $content_type ) {
			$payload['HtmlBody'] = $message;
			$payload['TextBody'] = wp_strip_all_tags( $message );
		} else {
			$payload['TextBody'] = $message;
		}

		$cc = $this->normalize_addresses( $headers['cc'] );
		if ( ! empty( $cc ) ) {
			$payload['Cc'] = implode( ',', $cc );
		}

		$bcc = $this->normalize_addresses( $headers['bcc'] );
		if ( ! empty( $bcc ) ) {
			$payload['Bcc'] = implode( ',', $bcc );
		}

		$reply_to = $this->normalize_addresses( $headers['reply_to'] );
		if ( ! empty( $reply_to ) ) {
			$payload['ReplyTo'] = $reply_to[0];
		}

		$attachments = $this->prepare_attachments( $atts['attachments'] ?? array(), $options );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( ! empty( $attachments ) ) {
			$payload['Attachments'] = $attachments;
		}

		if ( ! empty( $headers['custom_headers'] ) ) {
			$payload['Headers'] = $headers['custom_headers'];
		}

		return apply_filters( 'atum_mailer_postmark_payload', $payload, $atts, $options );
	}

	/**
	 * Parse wp_mail headers.
	 *
	 * @param string|array $headers Raw headers.
	 * @return array<string, mixed>
	 */
	private function parse_headers( $headers ) {
		$parsed = array(
			'from_email'     => '',
			'from_name'      => '',
			'cc'             => array(),
			'bcc'            => array(),
			'reply_to'       => array(),
			'content_type'   => 'text/plain',
			'custom_headers' => array(),
		);

		if ( empty( $headers ) ) {
			return $parsed;
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header || false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $content ) = explode( ':', $header, 2 );
			$name_lc = strtolower( trim( $name ) );
			$content = trim( $content );

			switch ( $name_lc ) {
				case 'from':
					$from = $this->parse_single_address( $content );
					if ( ! empty( $from['email'] ) ) {
						$parsed['from_email'] = $from['email'];
						$parsed['from_name']  = $from['name'];
					}
					break;
				case 'cc':
					$parsed['cc'] = array_merge( $parsed['cc'], $this->split_addresses( $content ) );
					break;
				case 'bcc':
					$parsed['bcc'] = array_merge( $parsed['bcc'], $this->split_addresses( $content ) );
					break;
				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], $this->split_addresses( $content ) );
					break;
				case 'content-type':
					$content_parts = array_map( 'trim', explode( ';', $content ) );
					if ( ! empty( $content_parts[0] ) ) {
						$parsed['content_type'] = strtolower( $content_parts[0] );
					}
					break;
				default:
					$parsed['custom_headers'][] = array(
						'Name'  => trim( $name ),
						'Value' => $content,
					);
			}
		}

		return $parsed;
	}

	/**
	 * Parse one email address.
	 *
	 * @param string $raw Raw address.
	 * @return array<string, string>
	 */
	private function parse_single_address( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array( 'email' => '', 'name' => '' );
		}

		$name  = '';
		$email = '';
		if ( preg_match( '/(.*)<(.+)>/', $raw, $matches ) ) {
			$name  = trim( trim( $matches[1] ), "\"'" );
			$email = trim( $matches[2] );
		} else {
			$email = $raw;
		}

		return array(
			'email' => sanitize_email( $email ),
			'name'  => sanitize_text_field( $name ),
		);
	}

	/**
	 * Normalize addresses.
	 *
	 * @param string|array $raw Raw list.
	 * @return array<int, string>
	 */
	private function normalize_addresses( $raw ) {
		$addresses = array();
		$list      = is_array( $raw ) ? $raw : $this->split_addresses( (string) $raw );
		foreach ( $list as $entry ) {
			$parsed = $this->parse_single_address( (string) $entry );
			if ( ! empty( $parsed['email'] ) ) {
				$addresses[] = $parsed['email'];
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Split address string.
	 *
	 * @param string $raw Raw line.
	 * @return array<int, string>
	 */
	private function split_addresses( $raw ) {
		$parts = str_getcsv( $raw, ',', '"', '\\' );
		$parts = array_map( 'trim', $parts );
		return array_values(
			array_filter(
				$parts,
				static function ( $item ) {
					return '' !== $item;
				}
			)
		);
	}

	/**
	 * Format from header.
	 *
	 * @param string $email Email.
	 * @param string $name Name.
	 * @return string
	 */
	private function format_from_header( $email, $name ) {
		$email = sanitize_email( $email );
		$name  = sanitize_text_field( $name );
		if ( '' === $name ) {
			return $email;
		}
		return sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Prepare attachments for Postmark.
	 *
	 * @param mixed               $attachments Attachments.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private function prepare_attachments( $attachments, $options = array() ) {
		if ( empty( $attachments ) ) {
			return array();
		}

		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		} elseif ( ! is_array( $attachments ) ) {
			$attachments = array( $attachments );
		}

		$prepared            = array();
		$total_size          = 0;
		$max_attachment_size = max( 1, (int) apply_filters( 'atum_mailer_max_attachment_bytes', 10 * 1024 * 1024, $options ) );
		$max_total_size      = max( $max_attachment_size, (int) apply_filters( 'atum_mailer_max_total_attachment_bytes', 10 * 1024 * 1024, $options ) );

		foreach ( $attachments as $attachment ) {
			$path = (string) $attachment;
			if ( '' === $path ) {
				continue;
			}

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return new WP_Error( 'atum_mailer_attachment_missing', sprintf( __( 'Attachment cannot be read: %s', 'atum-mailer' ), $path ) );
			}

			$size = filesize( $path );
			if ( false !== $size ) {
				if ( (int) $size > $max_attachment_size ) {
					return new WP_Error(
						'atum_mailer_attachment_too_large',
						sprintf( __( 'Attachment exceeds configured size limit: %s', 'atum-mailer' ), $path )
					);
				}

				$total_size += (int) $size;
				if ( $total_size > $max_total_size ) {
					return new WP_Error(
						'atum_mailer_attachments_too_large',
						__( 'Combined attachment size exceeds configured limit.', 'atum-mailer' )
					);
				}
			}

			$content = file_get_contents( $path );
			if ( false === $content ) {
				return new WP_Error( 'atum_mailer_attachment_error', sprintf( __( 'Attachment cannot be loaded: %s', 'atum-mailer' ), $path ) );
			}

			$file_name = wp_basename( $path );
			$type_info = wp_check_filetype( $file_name );
			$mime_type = ! empty( $type_info['type'] ) ? $type_info['type'] : 'application/octet-stream';

			$prepared[] = array(
				'Name'        => $file_name,
				'Content'     => base64_encode( $content ),
				'ContentType' => $mime_type,
			);
		}

		return $prepared;
	}

	/**
	 * Enqueue job.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $log_id Log id.
	 * @return void
	 */
	private function enqueue_job( $payload, $log_id ) {
		$now = time();
		$this->queue->enqueue( $payload, $log_id, $now );
		$this->schedule_queue_run( $now + 10 );
	}

	/**
	 * Schedule queue run.
	 *
	 * @param int $timestamp Timestamp.
	 * @return void
	 */
	private function schedule_queue_run( $timestamp ) {
		$timestamp = max( time() + 1, (int) $timestamp );
		$next      = wp_next_scheduled( self::QUEUE_CRON_HOOK );
		if ( false !== $next && $next <= $timestamp ) {
			return;
		}

		if ( false !== $next ) {
			wp_unschedule_event( $next, self::QUEUE_CRON_HOOK );
		}
		wp_schedule_single_event( $timestamp, self::QUEUE_CRON_HOOK );
	}

	/**
	 * Schedule next queue run from job state.
	 *
	 * @param bool $hit_budget Whether this run ended because of worker budgets.
	 * @return void
	 */
	private function schedule_next_queue_run( $hit_budget = false ) {
		$backlog = $this->queue->countBacklog();
		if ( $backlog <= 0 ) {
			$next = wp_next_scheduled( self::QUEUE_CRON_HOOK );
			if ( false !== $next ) {
				wp_unschedule_event( $next, self::QUEUE_CRON_HOOK );
			}
			return;
		}

		$next_attempt = $this->queue->nextDueTimestamp();
		if ( $hit_budget ) {
			$next_attempt = time() + 5;
		} elseif ( null === $next_attempt ) {
			$next_attempt = time() + 30;
		}

		$this->schedule_queue_run( (int) $next_attempt );
	}

	/**
	 * Convert provider payload into minimal wp_mail-like atts for logging.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	private function payload_to_log_atts( $payload ) {
		$to      = isset( $payload['To'] ) ? (string) $payload['To'] : '';
		$to      = str_replace( ';', ',', $to );
		$subject = isset( $payload['Subject'] ) ? (string) $payload['Subject'] : '';
		$message = isset( $payload['HtmlBody'] ) ? (string) $payload['HtmlBody'] : (string) ( $payload['TextBody'] ?? '' );
		$headers = array();

		if ( isset( $payload['HtmlBody'] ) ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		return array(
			'to'          => explode( ',', $to ),
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => array(),
		);
	}

	/**
	 * Optionally fallback to native wp_mail transport on retryable outages.
	 *
	 * @param WP_Error             $error Error.
	 * @param array<string, mixed> $options Options.
	 * @param int                  $log_id Log id.
	 * @param array<string, mixed> $payload Payload.
	 * @param array<string, mixed> $atts Original atts.
	 * @return bool
	 */
	private function maybe_fallback_to_wp_mail( WP_Error $error, $options, $log_id, $payload, $atts ) {
		if ( empty( $options['fallback_to_wp_mail'] ) || ! $this->client->isRetryable( $error ) ) {
			return false;
		}

		$error_data  = $error->get_error_data();
		$http_status = is_array( $error_data ) && isset( $error_data['status_code'] ) ? (int) $error_data['status_code'] : 0;

		$this->logs->update_mail_log(
			$log_id,
			'bypassed',
			array(
				'error_message'   => sprintf( __( 'Fell back to native wp_mail(): %s', 'atum-mailer' ), $error->get_error_message() ),
				'http_status'     => $http_status,
				'request_payload' => $payload,
				'response_body'   => is_array( $error_data ) && isset( $error_data['response'] ) ? (string) $error_data['response'] : '',
				'attempt_count'   => 1,
				'next_attempt_at' => null,
				'last_error_code' => $this->client->normalizeErrorCode( $error ),
				'delivery_mode'   => 'immediate',
			),
			$options
		);
		$this->settings->set_last_api_outage( current_time( 'mysql' ) );

		do_action( 'atum_mailer_fallback_to_wp_mail', $error, $atts, $options, $log_id );
		return true;
	}

	/**
	 * Handle failure.
	 *
	 * @param WP_Error             $error Error.
	 * @param array<string, mixed> $options Options.
	 * @param int                  $log_id Log id.
	 * @param int                  $attempt Attempt.
	 * @param string               $delivery_mode Mode.
	 * @param string               $failure_status Failure status.
	 * @return void
	 */
	private function handle_failure( WP_Error $error, $options, $log_id, $attempt, $delivery_mode, $failure_status = 'failed' ) {
		do_action( 'wp_mail_failed', $error );

		$error_data   = $error->get_error_data();
		$http_status  = is_array( $error_data ) && isset( $error_data['status_code'] ) ? (int) $error_data['status_code'] : 0;
		$payload      = is_array( $error_data ) && isset( $error_data['payload'] ) ? $error_data['payload'] : array();
		$response     = is_array( $error_data ) && isset( $error_data['response'] ) ? (string) $error_data['response'] : '';
		$normalized   = $this->client->normalizeErrorCode( $error );

		$this->logs->update_mail_log(
			$log_id,
			sanitize_key( $failure_status ),
			array(
				'error_message'   => $error->get_error_message(),
				'http_status'     => $http_status,
				'request_payload' => $payload,
				'response_body'   => $response,
				'attempt_count'   => $attempt,
				'next_attempt_at' => null,
				'last_error_code' => $normalized,
				'delivery_mode'   => $delivery_mode,
			),
			$options
		);

		if ( $this->client->isRetryable( $error ) ) {
			$this->settings->set_last_api_outage( current_time( 'mysql' ) );
		}

		if ( ! empty( $options['debug_logging'] ) ) {
			error_log( '[atum.mailer] ' . $error->get_error_message() );
		}
	}
}
