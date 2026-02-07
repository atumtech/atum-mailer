<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase {
	protected function setUp(): void {
		atum_test_reset_globals();
	}

	public function test_activate_migrates_legacy_token_and_schedules_cleanup(): void {
		update_option(
			Atum_Mailer_Settings_Repository::OPTION_KEY,
			array(
				'postmark_server_token' => 'legacy-token-xyz',
			)
		);

		Atum_Mailer_Bootstrap::activate();

		$settings = new Atum_Mailer_Settings_Repository();
		$this->assertSame( 'legacy-token-xyz', $settings->get_token() );
		$this->assertNotSame( '', (string) get_option( Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY, '' ) );
		$this->assertNotFalse( wp_next_scheduled( Atum_Mailer_Bootstrap::CLEANUP_CRON_HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Atum_Mailer_Bootstrap::ALERT_CRON_HOOK ) );
		$this->assertSame( Atum_Mailer_Log_Repository::DB_VERSION, (string) get_option( Atum_Mailer_Log_Repository::DB_VERSION_OPTION, '' ) );
	}

	public function test_register_rest_routes_registers_postmark_webhook_endpoint(): void {
		$bootstrap = new Atum_Mailer_Bootstrap();
		$bootstrap->register_rest_routes();

		$this->assertArrayHasKey( 'atum-mailer/v1/postmark/webhook', $GLOBALS['atum_test_rest_routes'] );
	}

	public function test_deactivate_clears_scheduled_hooks(): void {
		wp_schedule_event( time() + 60, 'daily', Atum_Mailer_Bootstrap::CLEANUP_CRON_HOOK );
		wp_schedule_event( time() + 120, 'hourly', Atum_Mailer_Bootstrap::ALERT_CRON_HOOK );
		wp_schedule_single_event( time() + 30, Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK );

		Atum_Mailer_Bootstrap::deactivate();

		$this->assertFalse( wp_next_scheduled( Atum_Mailer_Bootstrap::CLEANUP_CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Atum_Mailer_Bootstrap::ALERT_CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK ) );
	}

	public function test_activate_migrates_legacy_queue_option_to_db_queue(): void {
		update_option(
			Atum_Mailer_Settings_Repository::QUEUE_OPTION_KEY,
			array(
				'job-1' => array(
					'log_id'          => 9,
					'payload'         => array( 'To' => 'recipient@example.com' ),
					'attempt_count'   => 1,
					'next_attempt_at' => time() + 60,
					'last_error_code' => 'http_503',
				),
			)
		);

		Atum_Mailer_Bootstrap::activate();

		$this->assertSame( array(), get_option( Atum_Mailer_Settings_Repository::QUEUE_OPTION_KEY, array() ) );
		$this->assertSame( 1, (int) get_option( Atum_Mailer_Db_Queue_Repository::MIGRATION_OPTION, 0 ) );
		$this->assertNotEmpty( $GLOBALS['wpdb']->rows );
	}

	public function test_register_hooks_wires_logs_bulk_action(): void {
		$bootstrap = new Atum_Mailer_Bootstrap();
		$bootstrap->register_hooks();

		$this->assertArrayHasKey( 'admin_post_atum_mailer_logs_bulk', $GLOBALS['atum_test_actions'] );
		$this->assertNotEmpty( $GLOBALS['atum_test_actions']['admin_post_atum_mailer_logs_bulk'] );
		$this->assertArrayHasKey( 'admin_post_atum_mailer_process_queue_now', $GLOBALS['atum_test_actions'] );
		$this->assertNotEmpty( $GLOBALS['atum_test_actions']['admin_post_atum_mailer_process_queue_now'] );
		$this->assertArrayHasKey( Atum_Mailer_Bootstrap::ALERT_CRON_HOOK, $GLOBALS['atum_test_actions'] );
		$this->assertNotEmpty( $GLOBALS['atum_test_actions'][ Atum_Mailer_Bootstrap::ALERT_CRON_HOOK ] );
	}

	public function test_threshold_alerts_emit_hooks_and_respect_cooldown(): void {
		$GLOBALS['wpdb']->rows = array(
			1 => array(
				'id'         => 1,
				'status'     => 'failed',
				'created_at' => current_time( 'mysql' ),
			),
		);

		$fired = array(
			'failure' => 0,
			'backlog' => 0,
		);

		add_filter(
			'atum_mailer_alert_failure_rate_threshold',
			static function () {
				return 1.0;
			}
		);
		add_filter(
			'atum_mailer_alert_queue_backlog_threshold',
			static function () {
				return 1;
			}
		);
		add_filter(
			'atum_mailer_alert_cooldown_seconds',
			static function () {
				return 3600;
			}
		);
		add_action(
			'atum_mailer_alert_failure_rate_threshold',
			static function () use ( &$fired ) {
				$fired['failure']++;
			}
		);
		add_action(
			'atum_mailer_alert_queue_backlog_threshold',
			static function () use ( &$fired ) {
				$fired['backlog']++;
			}
		);

		$bootstrap = new Atum_Mailer_Bootstrap();
		$bootstrap->handle_threshold_alerts();
		$bootstrap->handle_threshold_alerts();

		$this->assertSame( 1, $fired['failure'] );
		$this->assertSame( 1, $fired['backlog'] );
		$this->assertGreaterThan( 0, (int) get_option( Atum_Mailer_Settings_Repository::LAST_ALERT_FAILURE_OPTION, 0 ) );
		$this->assertGreaterThan( 0, (int) get_option( Atum_Mailer_Settings_Repository::LAST_ALERT_BACKLOG_OPTION, 0 ) );
	}
}
