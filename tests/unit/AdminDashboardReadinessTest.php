<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminDashboardReadinessTest extends TestCase {
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

	public function test_dashboard_renders_setup_readiness_with_pending_steps_by_default(): void {
		add_filter(
			'atum_mailer_dns_records_lookup',
			static function ( $records, $host, $type ) {
				unset( $records, $host, $type );
				return array();
			},
			10,
			3
		);

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Setup Readiness', $html );
		$this->assertStringContainsString( 'Setup Needed', $html );
		$this->assertStringContainsString( 'Connect token', $html );
		$this->assertStringContainsString( 'Verify sender', $html );
		$this->assertStringContainsString( 'Check domain DNS', $html );
		$this->assertStringContainsString( 'SPF: Missing', $html );
		$this->assertStringContainsString( 'Open Token Settings', $html );
		$this->assertStringContainsString( '#atum-field-postmark-token', $html );
		$this->assertStringContainsString( 'Queue Operations', $html );
		$this->assertStringContainsString( 'Process Queue Now', $html );
	}

	public function test_dashboard_renders_ready_state_when_required_and_optional_steps_complete(): void {
		add_filter(
			'atum_mailer_dns_records_lookup',
			static function ( $records, $host, $type ) {
				unset( $records );
				if ( 'TXT' === $type && 'example.com' === $host ) {
					return array(
						array( 'txt' => 'v=spf1 include:spf.mtasv.net ~all' ),
					);
				}
				if ( 'TXT' === $type && 'pm._domainkey.example.com' === $host ) {
					return array(
						array( 'txt' => 'v=DKIM1; k=rsa; p=abc123' ),
					);
				}
				if ( 'TXT' === $type && '_dmarc.example.com' === $host ) {
					return array(
						array( 'txt' => 'v=DMARC1; p=none' ),
					);
				}
				if ( 'CNAME' === $type && 'pm-bounces.example.com' === $host ) {
					return array(
						array( 'target' => 'pm.mtasv.net.' ),
					);
				}
				return array();
			},
			10,
			3
		);

		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
			$options['postmark_server_token']  = 'token-abc';
			$options['token_verified']         = 1;
			$options['from_email']             = 'sender@example.com';
			$options['postmark_webhook_secret']= 'secret';
			$this->settings->set_token( 'token-abc' );
			$this->settings->update_raw_options( $options );
			update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'secret' );
			update_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, current_time( 'mysql' ) );

		ob_start();
		$this->admin->render_admin_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Production-ready.', $html );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringContainsString( 'Done', $html );
		$this->assertStringContainsString( 'SPF: Found', $html );
	}
}
