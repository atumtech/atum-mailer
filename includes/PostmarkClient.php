<?php
/**
 * Postmark HTTP client.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Postmark_Client implements Atum_Mailer_Delivery_Provider_Interface {
	/**
	 * Request timeout.
	 *
	 * @var int
	 */
	private $timeout = 20;

	/**
	 * Verify Postmark token and discover streams.
	 *
	 * @param string $token API token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function verify_token( $token ) {
		$endpoint = apply_filters( 'atum_mailer_postmark_verify_endpoint', 'https://api.postmarkapp.com/server' );
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->build_headers( $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'atum_mailer_verify_request_failed',
				sprintf( __( 'Verification request failed: %s', 'atum-mailer' ), $response->get_error_message() ),
				array(
					'retryable' => true,
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = __( 'Unexpected response from Postmark.', 'atum-mailer' );
			if ( is_array( $decoded ) && ! empty( $decoded['Message'] ) ) {
				$message = (string) $decoded['Message'];
			}

			return new WP_Error(
				'atum_mailer_verify_failed',
				$message,
				array(
					'status_code' => $status_code,
					'response'    => $body,
					'retryable'   => self::is_retryable_status( $status_code ),
				)
			);
		}

		$server_name = '';
		if ( is_array( $decoded ) && ! empty( $decoded['Name'] ) ) {
			$server_name = sanitize_text_field( (string) $decoded['Name'] );
		}

		$streams = $this->fetch_message_streams( $token );
		if ( is_wp_error( $streams ) ) {
			$streams = array( 'outbound' );
		}

		return array(
			'server_name'       => $server_name,
			'available_streams' => $streams,
		);
	}

	/**
	 * Interface wrapper.
	 *
	 * @param string $token API token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function verifyToken( $token ) {
		return $this->verify_token( $token );
	}

	/**
	 * Fetch streams list from Postmark.
	 *
	 * @param string $token API token.
	 * @return array<int, string>|WP_Error
	 */
	public function fetch_message_streams( $token ) {
		$endpoint = apply_filters( 'atum_mailer_postmark_streams_endpoint', 'https://api.postmarkapp.com/message-streams?count=500' );
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->build_headers( $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'atum_mailer_streams_request_failed', $response->get_error_message(), array( 'retryable' => true ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'atum_mailer_streams_failed',
				__( 'Unable to fetch Postmark message streams.', 'atum-mailer' ),
				array(
					'status_code' => $status_code,
					'response'    => $body,
					'retryable'   => self::is_retryable_status( $status_code ),
				)
			);
		}

		$streams = array();
		if ( is_array( $decoded ) && ! empty( $decoded['MessageStreams'] ) && is_array( $decoded['MessageStreams'] ) ) {
			foreach ( $decoded['MessageStreams'] as $stream ) {
				if ( is_array( $stream ) && ! empty( $stream['ID'] ) ) {
					$stream_id = sanitize_text_field( (string) $stream['ID'] );
					if ( '' !== $stream_id ) {
						$streams[] = $stream_id;
					}
				}
			}
		}

		if ( empty( $streams ) ) {
			$streams = array( 'outbound' );
		}

		return array_values( array_unique( $streams ) );
	}

	/**
	 * Send transactional email via Postmark.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param string               $token API token.
	 * @param array<string, mixed> $atts Original wp_mail attributes.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_email( $payload, $token, $atts = array(), $options = array() ) {
		$endpoint = apply_filters( 'atum_mailer_postmark_endpoint', 'https://api.postmarkapp.com/email', $atts, $options );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $this->timeout,
				'headers' => $this->build_headers( $token ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'atum_mailer_request_error',
				sprintf( __( 'Postmark request failed: %s', 'atum-mailer' ), $response->get_error_message() ),
				array(
					'payload'   => $payload,
					'retryable' => true,
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = __( 'Postmark API returned an unexpected response.', 'atum-mailer' );
			if ( is_array( $decoded ) && ! empty( $decoded['Message'] ) ) {
				$message = (string) $decoded['Message'];
			}

			return new WP_Error(
				'atum_mailer_postmark_error',
				sprintf( __( 'Postmark API error (%1$d): %2$s', 'atum-mailer' ), $status_code, $message ),
				array(
					'status_code' => $status_code,
					'response'    => $body,
					'payload'     => $payload,
					'retryable'   => self::is_retryable_status( $status_code ),
				)
			);
		}

		$message_id = '';
		if ( is_array( $decoded ) && ! empty( $decoded['MessageID'] ) ) {
			$message_id = sanitize_text_field( (string) $decoded['MessageID'] );
		}

		return array(
			'status_code' => $status_code,
			'body'        => $body,
			'decoded'     => is_array( $decoded ) ? $decoded : array(),
			'message_id'  => $message_id,
			'response'    => $response,
		);
	}

	/**
	 * Interface wrapper.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param string               $token API token.
	 * @param array<string, mixed> $atts Original wp_mail attributes.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send( $payload, $token, $atts = array(), $options = array() ) {
		return $this->send_email( $payload, $token, $atts, $options );
	}

	/**
	 * Retryable classifier by WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public function is_retryable_error( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['retryable'] ) ) {
			return (bool) $data['retryable'];
		}

		if ( is_array( $data ) && isset( $data['status_code'] ) ) {
			return self::is_retryable_status( (int) $data['status_code'] );
		}

		return true;
	}

	/**
	 * Interface wrapper.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public function isRetryable( WP_Error $error ) {
		return $this->is_retryable_error( $error );
	}

	/**
	 * Retryable classifier by HTTP status.
	 *
	 * @param int $status_code HTTP status.
	 * @return bool
	 */
	public static function is_retryable_status( $status_code ) {
		$status_code = (int) $status_code;
		return 429 === $status_code || $status_code >= 500;
	}

	/**
	 * Normalize failure code from WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public function normalized_error_code( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['status_code'] ) ) {
			return 'http_' . absint( $data['status_code'] );
		}

		return sanitize_key( $error->get_error_code() );
	}

	/**
	 * Interface wrapper.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public function normalizeErrorCode( WP_Error $error ) {
		return $this->normalized_error_code( $error );
	}

	/**
	 * Build Postmark request headers.
	 *
	 * @param string $token API token.
	 * @return array<string, string>
	 */
	private function build_headers( $token ) {
		return array(
			'Accept'                  => 'application/json',
			'Content-Type'            => 'application/json',
			'X-Postmark-Server-Token' => $token,
		);
	}
}
