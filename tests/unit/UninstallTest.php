<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {
	protected function setUp(): void {
		atum_test_reset_globals();
	}

	public function test_uninstall_cleans_queue_storage_options_and_tables(): void {
		update_option( 'atum_mailer_options', array( 'enabled' => 1 ) );
		update_option( 'atum_mailer_queue_db_version', '1.0.0' );
		update_option( 'atum_mailer_queue_migrated_from_option', 1 );
		update_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, current_time( 'mysql' ) );
		update_option( Atum_Mailer_Settings_Repository::LAST_ALERT_FAILURE_OPTION, time() );
		update_option( Atum_Mailer_Settings_Repository::LAST_ALERT_BACKLOG_OPTION, time() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( '', (string) get_option( 'atum_mailer_options', '' ) );
		$this->assertSame( '', (string) get_option( 'atum_mailer_queue_db_version', '' ) );
		$this->assertSame( '', (string) get_option( 'atum_mailer_queue_migrated_from_option', '' ) );
		$this->assertSame( '', (string) get_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, '' ) );
		$this->assertSame( '', (string) get_option( Atum_Mailer_Settings_Repository::LAST_ALERT_FAILURE_OPTION, '' ) );
		$this->assertSame( '', (string) get_option( Atum_Mailer_Settings_Repository::LAST_ALERT_BACKLOG_OPTION, '' ) );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $GLOBALS['wpdb']->last_query );
		$this->assertStringContainsString( 'atum_mailer_queue', $GLOBALS['wpdb']->last_query );
	}
}
