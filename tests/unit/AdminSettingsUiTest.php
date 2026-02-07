<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminSettingsUiTest extends TestCase {
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

		$settings   = new Atum_Mailer_Settings_Repository();
		$logs       = new Atum_Mailer_Log_Repository( $settings );
		$client     = new Atum_Mailer_Postmark_Client();
		$queue      = new Atum_Mailer_Db_Queue_Repository( $settings );
		$intercept  = new Atum_Mailer_Mail_Interceptor( $settings, $logs, $client, $queue );
		$this->admin = new Atum_Mailer_Admin_Controller( $settings, $logs, $client, $intercept, $queue );
	}

	public function test_settings_tab_renders_grouped_cards_and_advanced_disclosure(): void {
		$_GET['tab'] = 'settings';

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Core Setup', $html );
		$this->assertStringContainsString( 'Domain DNS Health', $html );
		$this->assertStringContainsString( 'atum-dns-health', $html );
		$this->assertStringContainsString( 'Delivery Behavior', $html );
		$this->assertStringContainsString( 'Security & Privacy', $html );
		$this->assertStringContainsString( 'Queue Tuning', $html );
			$this->assertStringContainsString( 'Retention & Webhooks', $html );
			$this->assertStringContainsString( 'atum-settings-layout', $html );
			$this->assertStringContainsString( 'atum-settings-sidebar-nav', $html );
			$this->assertStringContainsString( '#atum-settings-dns', $html );
			$this->assertStringContainsString( 'atum-field-delivery-mode', $html );
			$this->assertStringContainsString( 'atum-field-webhook-require-signature', $html );
			$this->assertStringContainsString( 'atum-field-webhook-replay-window', $html );
			$this->assertStringContainsString( 'atum-field-webhook-rate-limit', $html );
			$this->assertStringContainsString( 'atum-field-webhook-allowlist', $html );
		}

	public function test_inline_notice_renders_action_link_when_present(): void {
		$_GET['tab']                      = 'settings';
		$_GET['atum_mailer_notice']       = 'success';
		$_GET['atum_mailer_message']      = 'Saved';
		$_GET['atum_mailer_notice_link']  = 'https://example.com/wp-admin/admin.php?page=atum.mailer&tab=logs';
		$_GET['atum_mailer_notice_label'] = 'Open Logs';

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'atum-inline-notice', $html );
		$this->assertStringContainsString( 'atum-inline-notice__action', $html );
		$this->assertStringContainsString( 'Open Logs', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_inline_notice_drops_external_action_link(): void {
		$_GET['tab']                      = 'settings';
		$_GET['atum_mailer_notice']       = 'success';
		$_GET['atum_mailer_message']      = 'Saved';
		$_GET['atum_mailer_notice_link']  = 'https://evil.example/phish';
		$_GET['atum_mailer_notice_label'] = 'Open Logs';

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'atum-inline-notice', $html );
		$this->assertStringNotContainsString( 'atum-inline-notice__action', $html );
	}
}
