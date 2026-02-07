<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Log_Repository */
	private $logs;

	/** @var Atum_Mailer_Bootstrap */
	private $bootstrap;

	protected function setUp(): void {
		atum_test_reset_globals();

		$this->settings  = new Atum_Mailer_Settings_Repository();
		$this->logs      = new Atum_Mailer_Log_Repository( $this->settings );
		$this->bootstrap = new Atum_Mailer_Bootstrap();

		$options                           = $this->settings->default_options();
		$options['postmark_webhook_secret'] = 'super-secret';
		$options['mail_retention']         = 1;
		$this->settings->update_raw_options( $options );
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'super-secret' );
	}

	public function test_webhook_permission_rejects_invalid_secret(): void {
		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'wrong-secret' ),
			array( 'RecordType' => 'Delivery', 'MessageID' => 'mid-1' )
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_auth_failed', $result->get_error_code() );
	}

	public function test_webhook_updates_log_status_by_provider_message_id(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'sent',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-1',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'RecordType' => 'Delivery',
				'MessageID'  => 'mid-1',
			)
		);

		$permission = $this->bootstrap->can_receive_webhook( $request );
		$this->assertTrue( $permission );

		$response = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertSame( 'delivered', $GLOBALS['wpdb']->last_update_data['status'] );
		$this->assertSame( 'delivery', $GLOBALS['wpdb']->last_update_data['webhook_event_type'] );
	}

	public function test_webhook_does_not_regress_terminal_status(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Regression Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'delivered',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-terminal',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'RecordType' => 'Bounce',
				'MessageID'  => 'mid-terminal',
			)
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $request ) );
		$this->bootstrap->handle_webhook_event( $request );

		$this->assertSame( 'delivered', $GLOBALS['wpdb']->last_update_data['status'] );
	}

	public function test_webhook_duplicate_events_are_deduplicated(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Duplicate Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'sent',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-dup',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'ID'         => 'evt-123',
				'RecordType' => 'Delivery',
				'MessageID'  => 'mid-dup',
			)
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $request ) );

		$first = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$this->assertSame( 200, $first->status );

		$second = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $second );
		$this->assertSame( 200, $second->status );
		$this->assertIsArray( $second->data );
		$this->assertTrue( (bool) ( $second->data['duplicate'] ?? false ) );
	}

	public function test_webhook_permission_accepts_valid_signature_and_timestamp_when_required(): void {
		add_filter(
			'atum_mailer_webhook_require_signature',
			static function () {
				return true;
			}
		);

		$payload   = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-signed',
		);
		$timestamp = (string) time();
		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'super-secret' );

		$request = new WP_REST_Request(
			array(
				'x-atum-webhook-secret'    => 'super-secret',
				'x-atum-webhook-timestamp' => $timestamp,
				'x-atum-webhook-signature' => 'sha256=' . $signature,
			),
			$payload
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertTrue( $result );
	}

	public function test_webhook_permission_rejects_signature_outside_allowed_window(): void {
		add_filter(
			'atum_mailer_webhook_require_signature',
			static function () {
				return true;
			}
		);

		$payload   = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-stale',
		);
		$timestamp = (string) ( time() - 3600 );
		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'super-secret' );

		$request = new WP_REST_Request(
			array(
				'x-atum-webhook-secret'    => 'super-secret',
				'x-atum-webhook-timestamp' => $timestamp,
				'x-atum-webhook-signature' => $signature,
			),
			$payload
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_timestamp_out_of_window', $result->get_error_code() );
	}

	public function test_webhook_permission_rejects_signature_replay(): void {
		add_filter(
			'atum_mailer_webhook_require_signature',
			static function () {
				return true;
			}
		);

		$payload   = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-replay',
		);
		$timestamp = (string) time();
		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'super-secret' );
		$headers   = array(
			'x-atum-webhook-secret'    => 'super-secret',
			'x-atum-webhook-timestamp' => $timestamp,
			'x-atum-webhook-signature' => $signature,
		);

		$first = $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) );
		$this->assertTrue( $first );

		$second = $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'atum_mailer_webhook_replay_detected', $second->get_error_code() );
	}

	public function test_webhook_permission_rate_limits_by_ip(): void {
		add_filter(
			'atum_mailer_webhook_rate_limit_per_minute',
			static function () {
				return 2;
			}
		);

		$payload = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-limit',
		);
		$headers = array(
			'x-atum-webhook-secret' => 'super-secret',
			'x-forwarded-for'       => '203.0.113.10',
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) ) );
		$this->assertTrue( $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) ) );
		$third = $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) );
		$this->assertInstanceOf( WP_Error::class, $third );
		$this->assertSame( 'atum_mailer_webhook_rate_limited', $third->get_error_code() );
	}

	public function test_webhook_rate_limit_ignores_forwarded_header_spoofing_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';

		add_filter(
			'atum_mailer_webhook_rate_limit_per_minute',
			static function () {
				return 1;
			}
		);

		$payload = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-spoof',
		);

		$first = new WP_REST_Request(
			array(
				'x-atum-webhook-secret' => 'super-secret',
				'x-forwarded-for'       => '203.0.113.10',
			),
			$payload
		);
		$second = new WP_REST_Request(
			array(
				'x-atum-webhook-secret' => 'super-secret',
				'x-forwarded-for'       => '203.0.113.11',
			),
			$payload
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $first ) );
		$result = $this->bootstrap->can_receive_webhook( $second );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_rate_limited', $result->get_error_code() );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_webhook_rate_limit_can_trust_forwarded_headers_via_filter(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.88';

		add_filter(
			'atum_mailer_webhook_rate_limit_per_minute',
			static function () {
				return 1;
			}
		);
		add_filter(
			'atum_mailer_webhook_trust_forwarded_ip_headers',
			static function () {
				return true;
			}
		);

		$payload = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-trusted-forwarded',
		);

		$first = new WP_REST_Request(
			array(
				'x-atum-webhook-secret' => 'super-secret',
				'x-forwarded-for'       => '203.0.113.21',
			),
			$payload
		);
		$second = new WP_REST_Request(
			array(
				'x-atum-webhook-secret' => 'super-secret',
				'x-forwarded-for'       => '203.0.113.22',
			),
			$payload
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $first ) );
		$this->assertTrue( $this->bootstrap->can_receive_webhook( $second ) );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_webhook_permission_requires_signature_when_option_enabled(): void {
		$options                              = $this->settings->default_options();
		$options['mail_retention']            = 1;
		$options['postmark_webhook_secret']   = 'super-secret';
		$options['webhook_require_signature'] = 1;
		$this->settings->update_raw_options( $options );
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'super-secret' );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array( 'RecordType' => 'Delivery', 'MessageID' => 'mid-opt-signature' )
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_signature_missing', $result->get_error_code() );
	}

	public function test_webhook_permission_uses_option_replay_window(): void {
		$options                                     = $this->settings->default_options();
		$options['mail_retention']                   = 1;
		$options['postmark_webhook_secret']          = 'super-secret';
		$options['webhook_require_signature']        = 1;
		$options['webhook_replay_window_seconds']    = 30;
		$this->settings->update_raw_options( $options );
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'super-secret' );

		$payload   = array( 'RecordType' => 'Delivery', 'MessageID' => 'mid-opt-window' );
		$timestamp = (string) ( time() - 40 );
		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'super-secret' );
		$request   = new WP_REST_Request(
			array(
				'x-atum-webhook-secret'    => 'super-secret',
				'x-atum-webhook-timestamp' => $timestamp,
				'x-atum-webhook-signature' => $signature,
			),
			$payload
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_timestamp_out_of_window', $result->get_error_code() );
	}

	public function test_webhook_permission_uses_option_rate_limit(): void {
		$options                                   = $this->settings->default_options();
		$options['mail_retention']                 = 1;
		$options['postmark_webhook_secret']        = 'super-secret';
		$options['webhook_rate_limit_per_minute']  = 1;
		$this->settings->update_raw_options( $options );
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'super-secret' );

		$payload = array(
			'RecordType' => 'Delivery',
			'MessageID'  => 'mid-opt-rate-limit',
		);
		$headers = array(
			'x-atum-webhook-secret' => 'super-secret',
			'x-forwarded-for'       => '203.0.113.20',
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) ) );
		$second = $this->bootstrap->can_receive_webhook( new WP_REST_Request( $headers, $payload ) );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'atum_mailer_webhook_rate_limited', $second->get_error_code() );
	}
}
