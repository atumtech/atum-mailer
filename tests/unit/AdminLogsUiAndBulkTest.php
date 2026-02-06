<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminLogsUiAndBulkTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

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

		$this->settings = new Atum_Mailer_Settings_Repository();
		$logs           = new Atum_Mailer_Log_Repository( $this->settings );
		$client         = new Atum_Mailer_Postmark_Client();
		$queue          = new Atum_Mailer_Db_Queue_Repository( $this->settings );
		$mail           = new Atum_Mailer_Mail_Interceptor( $this->settings, $logs, $client, $queue );
		$this->admin    = new Atum_Mailer_Admin_Controller( $this->settings, $logs, $client, $mail, $queue );
	}

	public function test_logs_tab_renders_advanced_filters_and_bulk_toolbar(): void {
		$GLOBALS['wpdb']->rows[7] = array(
			'id'                 => 7,
			'created_at'         => '2025-01-12 11:00:00',
			'mail_to'            => wp_json_encode( array( 'x@example.com' ) ),
			'subject'            => 'Invoice reminder',
			'status'             => 'failed',
			'http_status'        => 422,
			'error_message'      => 'Bad request',
			'delivery_mode'      => 'queue',
			'attempt_count'      => 2,
			'provider_message_id'=> 'msg-123',
			'last_error_code'    => 'http_422',
			'webhook_event_type' => 'open',
		);

		$_GET = array(
			'tab'                 => 'logs',
			'status'              => 'failed',
			's'                   => 'invoice',
			'delivery_mode'       => 'queue',
			'retry_state'         => 'terminal',
			'date_from'           => '2025-01-01',
			'date_to'             => '2025-01-31',
			'provider_message_id' => 'msg-123',
		);

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="delivery_mode"', $html );
		$this->assertStringContainsString( 'name="retry_state"', $html );
		$this->assertStringContainsString( 'name="date_from"', $html );
		$this->assertStringContainsString( 'name="date_to"', $html );
		$this->assertStringContainsString( 'name="provider_message_id"', $html );
		$this->assertStringContainsString( 'atum-logs-bulk-form', $html );
		$this->assertStringContainsString( 'Retry selected', $html );
		$this->assertStringContainsString( 'Export selected', $html );
		$this->assertStringContainsString( 'Purge filtered', $html );
		$this->assertStringContainsString( 'atum-log-select-all', $html );
		$this->assertStringContainsString( 'atum-event-chip', $html );
		$this->assertStringContainsString( 'Opened', $html );
		$this->assertStringContainsString( 'Delivery Timeline', $html );
		$this->assertStringContainsString( 'Resend With Overrides', $html );
	}

	public function test_bulk_retry_selected_requires_selection(): void {
		$_POST = array(
			'bulk_action' => 'retry_selected',
			'status'      => 'failed',
		);

		try {
			$this->admin->handle_logs_bulk_action();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=error', $e->getMessage() );
			$this->assertStringContainsString( 'Select+at+least+one+log+entry+to+retry.', $e->getMessage() );
		}
	}

	public function test_bulk_retry_selected_resends_entries_with_saved_payload(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-xyz';
		$this->settings->set_token( 'token-xyz' );
		$this->settings->update_raw_options( $options );

		$GLOBALS['wpdb']->rows[42] = array(
			'id'              => 42,
			'request_payload' => wp_json_encode( array( 'To' => 'recipient@example.com' ) ),
		);

		atum_test_push_http_response(
			'POST',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"MessageID":"xyz"}',
			)
		);

		$_POST = array(
			'bulk_action' => 'retry_selected',
			'log_ids_csv' => '42',
			'status'      => 'failed',
		);

		try {
			$this->admin->handle_logs_bulk_action();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=success', $e->getMessage() );
			$this->assertStringContainsString( 'Resent%3A+1', $e->getMessage() );
		}
	}
}
