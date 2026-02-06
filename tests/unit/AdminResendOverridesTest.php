<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminResendOverridesTest extends TestCase {
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

	public function test_resend_log_accepts_generic_nonce_and_applies_overrides(): void {
		$GLOBALS['wpdb']->rows[5] = array(
			'id'              => 5,
			'request_payload' => wp_json_encode(
				array(
					'To'      => 'old@example.com',
					'From'    => 'sender@example.com',
					'Subject' => 'Old subject',
					'TextBody'=> 'hello',
				)
			),
		);

		atum_test_push_http_response(
			'POST',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"MessageID":"override-1"}',
			)
		);

		$_POST = array(
			'log_id'         => 5,
			'_wpnonce'       => 'nonce:atum_mailer_resend_log',
			'resend_to'      => 'new@example.com',
			'resend_subject' => 'New subject',
			'resend_mode'    => 'immediate',
		);

		try {
			$this->admin->handle_resend_log();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=success', $e->getMessage() );
			$this->assertStringContainsString( 'with+overrides', $e->getMessage() );
			$this->assertSame( 'New subject', (string) $GLOBALS['wpdb']->last_insert_data['subject'] );
			$this->assertStringContainsString( 'new@example.com', (string) $GLOBALS['wpdb']->last_insert_data['mail_to'] );
		}
	}

	public function test_resend_log_rejects_invalid_recipient_override(): void {
		$GLOBALS['wpdb']->rows[6] = array(
			'id'              => 6,
			'request_payload' => wp_json_encode(
				array(
					'To'      => 'old@example.com',
					'From'    => 'sender@example.com',
					'Subject' => 'Subject',
					'TextBody'=> 'hello',
				)
			),
		);

		$_POST = array(
			'log_id'    => 6,
			'_wpnonce'  => 'nonce:atum_mailer_resend_log',
			'resend_to' => 'not-an-email',
		);

		try {
			$this->admin->handle_resend_log();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=error', $e->getMessage() );
			$this->assertStringContainsString( 'valid+recipient+email+address+for+resend+override', $e->getMessage() );
		}
	}
}
