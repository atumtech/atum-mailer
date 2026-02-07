<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminLogDetailsTimelineTest extends TestCase {
	/** @var Atum_Mailer_Admin_Controller */
	private $admin;

	protected function setUp(): void {
		atum_test_reset_globals();
		if ( ! defined( 'ATUM_MAILER_DIR' ) ) {
			define( 'ATUM_MAILER_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		if ( ! defined( 'ATUM_MAILER_URL' ) ) {
			define( 'ATUM_MAILER_URL', 'https://example.com/' );
		}

		$settings    = new Atum_Mailer_Settings_Repository();
		$logs        = new Atum_Mailer_Log_Repository( $settings );
		$client      = new Atum_Mailer_Postmark_Client();
		$queue       = new Atum_Mailer_Db_Queue_Repository( $settings );
		$interceptor = new Atum_Mailer_Mail_Interceptor( $settings, $logs, $client, $queue );
		$this->admin = new Atum_Mailer_Admin_Controller( $settings, $logs, $client, $interceptor, $queue );
	}

	public function test_get_log_details_includes_timeline_and_event_labels(): void {
		$GLOBALS['wpdb']->rows[25] = array(
			'id'                 => 25,
			'created_at'         => '2025-01-10 10:00:00',
			'updated_at'         => '2025-01-10 10:05:00',
			'mail_to'            => wp_json_encode( array( 'recipient@example.com' ) ),
			'subject'            => 'Timeline message',
			'status'             => 'failed',
			'provider'           => 'postmark',
			'http_status'        => 500,
			'delivery_mode'      => 'queue',
			'attempt_count'      => 2,
			'next_attempt_at'    => '2025-01-10 10:07:00',
			'last_error_code'    => 'http_500',
			'provider_message_id'=> 'pm-123',
			'webhook_event_type' => 'bounce',
			'error_message'      => 'Temporary failure',
			'message'            => '',
			'headers'            => '',
			'attachments'        => '',
			'request_payload'    => '',
			'response_body'      => '',
		);

		$_POST = array(
			'nonce'  => 'nonce:atum_mailer_admin_nonce',
			'log_id' => 25,
		);

		try {
			$this->admin->handle_get_log_details();
			$this->fail( 'Expected JSON response exception.' );
		} catch ( Atum_Test_Json_Response_Exception $e ) {
			$this->assertTrue( $e->success );
			$this->assertSame( 'Bounced', (string) $e->payload['data']['webhook_event_type'] );
			$this->assertSame( 'recipient@example.com', (string) $e->payload['data']['recipient_csv'] );
			$this->assertIsArray( $e->payload['data']['timeline'] );
			$this->assertGreaterThan( 2, count( $e->payload['data']['timeline'] ) );
			$this->assertSame( 'created', (string) $e->payload['data']['timeline'][0]['type'] );
		}
	}

	public function test_get_log_details_in_metadata_mode_returns_payload_hints_when_raw_data_is_missing(): void {
		$GLOBALS['wpdb']->rows[26] = array(
			'id'                 => 26,
			'created_at'         => '2025-01-10 11:00:00',
			'updated_at'         => '2025-01-10 11:05:00',
			'mail_to'            => wp_json_encode( array( 'recipient@example.com' ) ),
			'subject'            => 'Metadata mode',
			'status'             => 'failed',
			'provider'           => 'postmark',
			'http_status'        => 422,
			'delivery_mode'      => 'immediate',
			'attempt_count'      => 1,
			'next_attempt_at'    => '',
			'last_error_code'    => 'http_422',
			'provider_message_id'=> '',
			'webhook_event_type' => '',
			'error_message'      => 'Provider rejected From header',
			'message'            => '',
			'headers'            => '',
			'attachments'        => '',
			'request_payload'    => '',
			'response_body'      => '',
		);

		$_POST = array(
			'nonce'  => 'nonce:atum_mailer_admin_nonce',
			'log_id' => 26,
		);

		try {
			$this->admin->handle_get_log_details();
			$this->fail( 'Expected JSON response exception.' );
		} catch ( Atum_Test_Json_Response_Exception $e ) {
			$this->assertTrue( $e->success );
			$this->assertStringContainsString( 'metadata mode', (string) $e->payload['data']['request_payload'] );
			$this->assertStringContainsString( 'metadata mode', (string) $e->payload['data']['headers'] );
		}
	}
}
