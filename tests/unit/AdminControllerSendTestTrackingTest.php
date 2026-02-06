<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminControllerSendTestTrackingTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Admin_Controller */
	private $admin;

	protected function setUp(): void {
		atum_test_reset_globals();

		$this->settings = new Atum_Mailer_Settings_Repository();
		$logs           = new Atum_Mailer_Log_Repository( $this->settings );
		$client         = new Atum_Mailer_Postmark_Client();
		$queue          = new Atum_Mailer_Db_Queue_Repository( $this->settings );
		$mail           = new Atum_Mailer_Mail_Interceptor( $this->settings, $logs, $client, $queue );
		$this->admin    = new Atum_Mailer_Admin_Controller( $this->settings, $logs, $client, $mail, $queue );

		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );
	}

	public function test_successful_send_test_updates_last_test_timestamp_option(): void {
		$_POST = array(
			'test_email'   => 'recipient@example.com',
			'test_subject' => 'Tracking Test',
			'test_message' => '<p>Body</p>',
		);

		try {
			$this->admin->handle_send_test_email();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertNotSame( '', (string) get_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, '' ) );
			$this->assertStringContainsString( 'atum_mailer_notice=success', $e->getMessage() );
			$this->assertStringContainsString( 'atum_mailer_notice_link=', $e->getMessage() );
			$this->assertStringContainsString( '%26tab%3Dlogs', $e->getMessage() );
		}
	}
}
