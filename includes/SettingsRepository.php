<?php
/**
 * Settings repository for atum.mailer.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Settings_Repository {
	const OPTION_KEY             = 'atum_mailer_options';
	const TOKEN_OPTION_KEY       = 'atum_mailer_postmark_token';
	const WEBHOOK_SECRET_OPTION_KEY = 'atum_mailer_webhook_secret';
	const SECRET_ENCRYPTION_PREFIX_SODIUM  = 'enc:v1:';
	const SECRET_ENCRYPTION_PREFIX_OPENSSL = 'enc:v1g:';
	const LAST_CLEANUP_OPTION    = 'atum_mailer_last_cleanup';
	const QUEUE_OPTION_KEY       = 'atum_mailer_queue_jobs';
	const LAST_API_OUTAGE_OPTION = 'atum_mailer_last_api_outage';
	const LAST_TEST_EMAIL_OPTION = 'atum_mailer_last_test_email_at';
	const LAST_ALERT_FAILURE_OPTION = 'atum_mailer_last_alert_failure_rate';
	const LAST_ALERT_BACKLOG_OPTION = 'atum_mailer_last_alert_queue_backlog';

	/**
	 * Default options.
	 *
	 * @return array<string, mixed>
	 */
	public function default_options() {
		return array(
			'enabled'                 => 1,
			'postmark_server_token'   => '',
			'token_verified'          => 0,
			'token_verified_at'       => '',
			'token_server_name'       => '',
			'token_last_error'        => '',
			'available_streams'       => array( 'outbound', 'broadcast' ),
			'message_stream'          => 'outbound',
			'from_email'              => '',
			'from_name'               => '',
			'force_from'              => 0,
			'track_opens'             => 0,
			'track_links'             => 'None',
			'debug_logging'           => 0,
			'mail_retention'          => 1,
			'retention_days'          => 90,
			'allow_token_reveal'      => 0,
			'log_detail_mode'         => 'metadata',
			'delivery_mode'           => 'immediate',
				'fallback_to_wp_mail'     => 0,
				'postmark_webhook_secret' => '',
				'webhook_require_signature' => 1,
				'webhook_replay_window_seconds' => 300,
				'webhook_rate_limit_per_minute' => 120,
				'webhook_allowed_ip_ranges' => '',
				'queue_max_attempts'      => 5,
				'queue_retry_base_delay'  => 60,
				'queue_retry_max_delay'   => 3600,
			);
	}

	/**
	 * Migrate legacy values.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_options() {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$token = $this->get_token();
		if ( '' === $token && ! empty( $current['postmark_server_token'] ) ) {
			$token = sanitize_text_field( (string) $current['postmark_server_token'] );
			$this->set_token( $token );
		}
		$webhook_secret = $this->get_webhook_secret();
		if ( '' === $webhook_secret && ! empty( $current['postmark_webhook_secret'] ) ) {
			$webhook_secret = sanitize_text_field( (string) $current['postmark_webhook_secret'] );
			$this->set_webhook_secret( $webhook_secret );
		}

		$merged                            = wp_parse_args( $current, $this->default_options() );
		$merged['postmark_server_token']   = '';
		$merged['postmark_webhook_secret'] = '';
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Get options merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_options() {
		$stored  = get_option( self::OPTION_KEY, array() );
		$options = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->default_options() );
		$token   = $this->get_token();
		$webhook_secret = $this->get_webhook_secret();

		if ( '' === $token && ! empty( $options['postmark_server_token'] ) ) {
			$legacy = sanitize_text_field( (string) $options['postmark_server_token'] );
			if ( '' !== $legacy ) {
				$token = $legacy;
				$this->set_token( $token );
			}
			$options['postmark_server_token'] = '';
			update_option( self::OPTION_KEY, $options );
		}
		if ( '' === $webhook_secret && ! empty( $options['postmark_webhook_secret'] ) ) {
			$legacy_secret = sanitize_text_field( (string) $options['postmark_webhook_secret'] );
			if ( '' !== $legacy_secret ) {
				$webhook_secret = $legacy_secret;
				$this->set_webhook_secret( $webhook_secret );
			}
			$options['postmark_webhook_secret'] = '';
			update_option( self::OPTION_KEY, $options );
		}

		if ( '' !== $token ) {
			$options['postmark_server_token'] = $token;
		} else {
			$options['postmark_server_token'] = '';
		}
		if ( '' !== $webhook_secret ) {
			$options['postmark_webhook_secret'] = $webhook_secret;
		} else {
			$options['postmark_webhook_secret'] = '';
		}

		return $options;
	}

	/**
	 * Update options.
	 *
	 * @param array<string, mixed> $options Sanitized options.
	 * @return void
	 */
	public function update_options( $options ) {
		update_option( self::OPTION_KEY, $this->sanitize_options( $options ) );
	}

	/**
	 * Update options without sanitization.
	 *
	 * @param array<string, mixed> $options Raw options.
	 * @return void
	 */
	public function update_raw_options( $options ) {
		$merged                            = wp_parse_args( is_array( $options ) ? $options : array(), $this->default_options() );
		$merged['postmark_server_token']   = '';
		$merged['postmark_webhook_secret'] = '';
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Set Postmark token.
	 *
	 * @param string $token Token value.
	 * @return void
	 */
	public function set_token( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION_KEY );
			return;
		}

		update_option( self::TOKEN_OPTION_KEY, $this->encrypt_secret( $token ), false );
	}

	/**
	 * Get Postmark token.
	 *
	 * @return string
	 */
	public function get_token() {
		return $this->decrypt_secret( (string) get_option( self::TOKEN_OPTION_KEY, '' ) );
	}

	/**
	 * Clear stored token.
	 *
	 * @return void
	 */
	public function clear_token() {
		delete_option( self::TOKEN_OPTION_KEY );
	}

	/**
	 * Set webhook secret.
	 *
	 * @param string $secret Secret value.
	 * @return void
	 */
	public function set_webhook_secret( $secret ) {
		$secret = sanitize_text_field( (string) $secret );
		if ( '' === $secret ) {
			delete_option( self::WEBHOOK_SECRET_OPTION_KEY );
			return;
		}

		update_option( self::WEBHOOK_SECRET_OPTION_KEY, $this->encrypt_secret( $secret ), false );
	}

	/**
	 * Get webhook secret.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return $this->decrypt_secret( (string) get_option( self::WEBHOOK_SECRET_OPTION_KEY, '' ) );
	}

	/**
	 * Clear webhook secret.
	 *
	 * @return void
	 */
	public function clear_webhook_secret() {
		delete_option( self::WEBHOOK_SECRET_OPTION_KEY );
	}

	/**
	 * Sanitize options.
	 *
	 * @param mixed $input Input options.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = $this->default_options();
		$current  = $this->get_options();

		$output                           = $defaults;
		$output['enabled']                = empty( $input['enabled'] ) ? 0 : 1;
		$output['message_stream']         = sanitize_text_field( $input['message_stream'] ?? $defaults['message_stream'] );
		$output['from_email']             = sanitize_email( $input['from_email'] ?? '' );
		$output['from_name']              = sanitize_text_field( $input['from_name'] ?? '' );
		$output['force_from']             = empty( $input['force_from'] ) ? 0 : 1;
		$output['track_opens']            = empty( $input['track_opens'] ) ? 0 : 1;
		$output['track_links']            = sanitize_text_field( $input['track_links'] ?? $defaults['track_links'] );
		$output['debug_logging']          = empty( $input['debug_logging'] ) ? 0 : 1;
		$output['mail_retention']         = empty( $input['mail_retention'] ) ? 0 : 1;
		$output['retention_days']         = max( 1, min( 3650, (int) ( $input['retention_days'] ?? $defaults['retention_days'] ) ) );
		$output['allow_token_reveal']     = empty( $input['allow_token_reveal'] ) ? 0 : 1;
		$output['log_detail_mode']        = sanitize_key( (string) ( $input['log_detail_mode'] ?? $defaults['log_detail_mode'] ) );
			$output['delivery_mode']          = sanitize_key( (string) ( $input['delivery_mode'] ?? $defaults['delivery_mode'] ) );
			$output['fallback_to_wp_mail']    = empty( $input['fallback_to_wp_mail'] ) ? 0 : 1;
			$output['postmark_webhook_secret'] = '';
			$output['webhook_require_signature'] = empty( $input['webhook_require_signature'] ) ? 0 : 1;
			$output['webhook_replay_window_seconds'] = max( 30, min( DAY_IN_SECONDS, (int) ( $input['webhook_replay_window_seconds'] ?? $defaults['webhook_replay_window_seconds'] ) ) );
			$output['webhook_rate_limit_per_minute'] = max( 1, min( 5000, (int) ( $input['webhook_rate_limit_per_minute'] ?? $defaults['webhook_rate_limit_per_minute'] ) ) );
			$output['webhook_allowed_ip_ranges'] = $this->sanitize_webhook_ip_ranges( $input['webhook_allowed_ip_ranges'] ?? $defaults['webhook_allowed_ip_ranges'] );
			$output['queue_max_attempts']     = max( 1, min( 20, (int) ( $input['queue_max_attempts'] ?? $defaults['queue_max_attempts'] ) ) );
		$output['queue_retry_base_delay'] = max( 5, min( 3600, (int) ( $input['queue_retry_base_delay'] ?? $defaults['queue_retry_base_delay'] ) ) );
		$output['queue_retry_max_delay']  = max( 60, min( DAY_IN_SECONDS, (int) ( $input['queue_retry_max_delay'] ?? $defaults['queue_retry_max_delay'] ) ) );

		$output['postmark_server_token'] = '';
		$output['token_verified']    = array_key_exists( 'token_verified', $input ) ? ( empty( $input['token_verified'] ) ? 0 : 1 ) : ( empty( $current['token_verified'] ) ? 0 : 1 );
		$output['token_verified_at'] = array_key_exists( 'token_verified_at', $input ) ? sanitize_text_field( (string) $input['token_verified_at'] ) : sanitize_text_field( (string) $current['token_verified_at'] );
		$output['token_server_name'] = array_key_exists( 'token_server_name', $input ) ? sanitize_text_field( (string) $input['token_server_name'] ) : sanitize_text_field( (string) $current['token_server_name'] );
		$output['token_last_error']  = array_key_exists( 'token_last_error', $input ) ? sanitize_text_field( (string) $input['token_last_error'] ) : sanitize_text_field( (string) $current['token_last_error'] );
		$output['available_streams'] = array_key_exists( 'available_streams', $input ) && is_array( $input['available_streams'] )
			? $input['available_streams']
			: ( is_array( $current['available_streams'] ?? null ) ? $current['available_streams'] : $defaults['available_streams'] );

		$output['available_streams'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $stream ) {
							return sanitize_text_field( (string) $stream );
						},
						$output['available_streams']
					),
					static function ( $stream ) {
						return '' !== $stream && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $stream );
					}
				)
			)
		);

		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $output['message_stream'] ) ) {
			$output['message_stream'] = $defaults['message_stream'];
		}

		if ( empty( $output['available_streams'] ) ) {
			$output['available_streams'] = $defaults['available_streams'];
		}

		if ( ! in_array( $output['message_stream'], $output['available_streams'], true ) ) {
			$output['message_stream'] = (string) $output['available_streams'][0];
		}

		$allowed_track_links = array( 'None', 'HtmlAndText', 'HtmlOnly', 'TextOnly' );
		if ( ! in_array( $output['track_links'], $allowed_track_links, true ) ) {
			$output['track_links'] = $defaults['track_links'];
		}

		if ( ! in_array( $output['log_detail_mode'], array( 'metadata', 'full' ), true ) ) {
			$output['log_detail_mode'] = $defaults['log_detail_mode'];
		}

		if ( ! in_array( $output['delivery_mode'], array( 'immediate', 'queue' ), true ) ) {
			$output['delivery_mode'] = $defaults['delivery_mode'];
		}

		if ( $output['queue_retry_max_delay'] < $output['queue_retry_base_delay'] ) {
			$output['queue_retry_max_delay'] = $output['queue_retry_base_delay'];
		}

		$current_webhook_secret = $this->get_webhook_secret();
		$webhook_secret         = $current_webhook_secret;
		$clear_webhook_secret   = ! empty( $input['postmark_webhook_secret_clear'] );
		if ( $clear_webhook_secret ) {
			$webhook_secret = '';
		} elseif ( array_key_exists( 'postmark_webhook_secret', $input ) ) {
			$incoming_secret = sanitize_text_field( (string) $input['postmark_webhook_secret'] );
			if ( '' !== trim( $incoming_secret ) ) {
				$webhook_secret = $incoming_secret;
			}
		}
		if ( '' === trim( $webhook_secret ) ) {
			$this->clear_webhook_secret();
		} else {
			$this->set_webhook_secret( $webhook_secret );
		}

		return $output;
	}

	/**
	 * Sanitize webhook allowlisted IP ranges.
	 *
	 * Accepts exact IPs and CIDR ranges (IPv4/IPv6), comma/newline separated.
	 *
	 * @param mixed $raw Raw list.
	 * @return string
	 */
	private function sanitize_webhook_ip_ranges( $raw ) {
		if ( is_array( $raw ) ) {
			$items = $raw;
		} else {
			$items = preg_split( '/[\s,;]+/', (string) $raw );
		}

		if ( ! is_array( $items ) ) {
			return '';
		}

		$sanitized = array();
		foreach ( $items as $item ) {
			$candidate = trim( (string) $item );
			if ( '' === $candidate ) {
				continue;
			}

			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$sanitized[] = $candidate;
				continue;
			}

			if ( false === strpos( $candidate, '/' ) ) {
				continue;
			}

			list( $base, $prefix ) = array_map( 'trim', explode( '/', $candidate, 2 ) );
			if ( false === filter_var( $base, FILTER_VALIDATE_IP ) || ! ctype_digit( $prefix ) ) {
				continue;
			}

			$prefix_int = (int) $prefix;
			$is_ipv6    = false !== strpos( $base, ':' );
			$max_prefix = $is_ipv6 ? 128 : 32;
			if ( $prefix_int < 0 || $prefix_int > $max_prefix ) {
				continue;
			}

			$sanitized[] = $base . '/' . $prefix_int;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		return implode( "\n", $sanitized );
	}

	/**
	 * Encrypt a secret for at-rest storage when cryptography is available.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	private function encrypt_secret( $plaintext ) {
		$plaintext = sanitize_text_field( (string) $plaintext );
		if ( '' === $plaintext ) {
			return '';
		}

		$key = $this->secret_encryption_key();
		if ( '' === $key ) {
			return $plaintext;
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::SECRET_ENCRYPTION_PREFIX_SODIUM . base64_encode( $nonce . $ciphertext );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $ciphertext && '' !== $tag ) {
				return self::SECRET_ENCRYPTION_PREFIX_OPENSSL . base64_encode( $iv . $tag . $ciphertext );
			}
		}

		return $plaintext;
	}

	/**
	 * Decrypt a stored secret while supporting legacy plaintext records.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private function decrypt_secret( $stored ) {
		$stored = trim( (string) $stored );
		if ( '' === $stored ) {
			return '';
		}

		$key = $this->secret_encryption_key();
		if ( '' !== $key && 0 === strpos( $stored, self::SECRET_ENCRYPTION_PREFIX_SODIUM ) ) {
			$payload = base64_decode( substr( $stored, strlen( self::SECRET_ENCRYPTION_PREFIX_SODIUM ) ), true );
			if ( false === $payload || ! defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			if ( strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			if ( false === $plaintext ) {
				return '';
			}

			return sanitize_text_field( (string) $plaintext );
		}

		if ( '' !== $key && 0 === strpos( $stored, self::SECRET_ENCRYPTION_PREFIX_OPENSSL ) ) {
			$payload = base64_decode( substr( $stored, strlen( self::SECRET_ENCRYPTION_PREFIX_OPENSSL ) ), true );
			if ( false === $payload || ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			if ( strlen( $payload ) <= 28 ) {
				return '';
			}

			$iv = substr( $payload, 0, 12 );
			$tag = substr( $payload, 12, 16 );
			$ciphertext = substr( $payload, 28 );
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '' );
			if ( false === $plaintext ) {
				return '';
			}

			return sanitize_text_field( (string) $plaintext );
		}

		return sanitize_text_field( $stored );
	}

	/**
	 * Resolve symmetric key bytes from WordPress salt material.
	 *
	 * @return string
	 */
	private function secret_encryption_key() {
		$material = $this->secret_encryption_material();
		if ( '' === $material ) {
			return '';
		}

		return hash( 'sha256', $material, true );
	}

	/**
	 * Build encryption material from WordPress/site identity.
	 *
	 * @return string
	 */
	private function secret_encryption_material() {
		$parts = array();
		if ( function_exists( 'wp_salt' ) ) {
			$salt = trim( (string) wp_salt( 'auth' ) );
			if ( '' !== $salt ) {
				$parts[] = $salt;
			}
		}
		if ( defined( 'AUTH_KEY' ) ) {
			$auth = trim( (string) AUTH_KEY );
			if ( '' !== $auth ) {
				$parts[] = $auth;
			}
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$secure = trim( (string) SECURE_AUTH_KEY );
			if ( '' !== $secure ) {
				$parts[] = $secure;
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		$parts[] = (string) home_url( '/' );
		$parts[] = 'atum-mailer-secret-v1';
		return implode( '|', $parts );
	}

	/**
	 * Get queue jobs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_queue_jobs() {
		$jobs = get_option( self::QUEUE_OPTION_KEY, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Persist queue jobs.
	 *
	 * @param array<string, array<string, mixed>> $jobs Queue jobs.
	 * @return void
	 */
	public function update_queue_jobs( $jobs ) {
		update_option( self::QUEUE_OPTION_KEY, is_array( $jobs ) ? $jobs : array(), false );
	}

	/**
	 * Queue backlog count.
	 *
	 * @return int
	 */
	public function get_queue_backlog_count() {
		return count( $this->get_queue_jobs() );
	}

	/**
	 * Set last API outage timestamp.
	 *
	 * @param string $mysql_datetime Datetime.
	 * @return void
	 */
	public function set_last_api_outage( $mysql_datetime ) {
		update_option( self::LAST_API_OUTAGE_OPTION, sanitize_text_field( (string) $mysql_datetime ) );
	}

	/**
	 * Get last API outage timestamp.
	 *
	 * @return string
	 */
	public function get_last_api_outage() {
		return (string) get_option( self::LAST_API_OUTAGE_OPTION, '' );
	}
}
