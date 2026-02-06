<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminControllerTokenRevealTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Admin_Controller */
	private $admin;

	protected function setUp(): void {
		atum_test_reset_globals();

		$this->settings = new Atum_Mailer_Settings_Repository();
		$logs           = new Atum_Mailer_Log_Repository( $this->settings );
		$client         = new Atum_Mailer_Postmark_Client();
		$mail           = new Atum_Mailer_Mail_Interceptor( $this->settings, $logs, $client );
		$this->admin    = new Atum_Mailer_Admin_Controller( $this->settings, $logs, $client, $mail );

		$this->settings->set_token( 'token-1234567890' );
	}

	public function test_reveal_returns_masked_token_when_setting_disabled(): void {
		$options                       = $this->settings->default_options();
		$options['allow_token_reveal'] = 0;
		$options['postmark_server_token'] = 'token-1234567890';
		$this->settings->update_raw_options( $options );

		$_POST = array(
			'nonce' => 'nonce:atum_mailer_admin_nonce',
			'stage' => 'request',
		);

		try {
			$this->admin->handle_reveal_token();
			$this->fail( 'Expected JSON response exception.' );
		} catch ( Atum_Test_Json_Response_Exception $e ) {
			$this->assertTrue( $e->success );
			$this->assertFalse( (bool) $e->payload['data']['allowed'] );
			$this->assertNotSame( 'token-1234567890', $e->payload['data']['token'] );
			$this->assertStringContainsString( '*', $e->payload['data']['masked'] );
		}
	}

	public function test_reveal_requires_request_then_confirm_when_enabled(): void {
		$options                       = $this->settings->default_options();
		$options['allow_token_reveal'] = 1;
		$options['postmark_server_token'] = 'token-1234567890';
		$this->settings->update_raw_options( $options );

		$_POST = array(
			'nonce' => 'nonce:atum_mailer_admin_nonce',
			'stage' => 'request',
		);

		$session     = '';
		$fresh_nonce = '';

		try {
			$this->admin->handle_reveal_token();
			$this->fail( 'Expected JSON response exception.' );
		} catch ( Atum_Test_Json_Response_Exception $e ) {
			$this->assertTrue( $e->success );
			$this->assertTrue( (bool) $e->payload['data']['allowed'] );
			$this->assertTrue( (bool) $e->payload['data']['needsConfirm'] );
			$session     = (string) $e->payload['data']['session'];
			$fresh_nonce = (string) $e->payload['data']['freshNonce'];
			$this->assertNotSame( '', $session );
			$this->assertNotSame( '', $fresh_nonce );
		}

		$_POST = array(
			'nonce'      => 'nonce:atum_mailer_admin_nonce',
			'stage'      => 'confirm',
			'session'    => $session,
			'fresh_nonce'=> $fresh_nonce,
		);

		try {
			$this->admin->handle_reveal_token();
			$this->fail( 'Expected JSON response exception.' );
		} catch ( Atum_Test_Json_Response_Exception $e ) {
			$this->assertTrue( $e->success );
			$this->assertTrue( (bool) $e->payload['data']['allowed'] );
			$this->assertSame( 'token-1234567890', $e->payload['data']['token'] );
		}
	}
}
