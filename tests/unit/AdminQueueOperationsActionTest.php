<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminQueueOperationsActionTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Db_Queue_Repository */
	private $queue;

	/** @var Atum_Mailer_Admin_Controller */
	private $admin;

	protected function setUp(): void {
		atum_test_reset_globals();

		$this->settings = new Atum_Mailer_Settings_Repository();
		$logs           = new Atum_Mailer_Log_Repository( $this->settings );
		$client         = new Atum_Mailer_Postmark_Client();
		$this->queue    = new Atum_Mailer_Db_Queue_Repository( $this->settings );
		$mail           = new Atum_Mailer_Mail_Interceptor( $this->settings, $logs, $client, $this->queue );
		$this->admin    = new Atum_Mailer_Admin_Controller( $this->settings, $logs, $client, $mail, $this->queue );
	}

	public function test_process_queue_now_redirects_with_success_notice_when_jobs_processed(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->queue->enqueue(
			array(
				'To'      => 'recipient@example.com',
				'From'    => 'sender@example.com',
				'Subject' => 'Queued test',
				'TextBody'=> 'Hello',
			),
			10,
			time() - 5
		);

		atum_test_push_http_response(
			'POST',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"MessageID":"processed-1"}',
			)
		);

		try {
			$this->admin->handle_process_queue_now();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=success', $e->getMessage() );
			$this->assertStringContainsString( 'Processed%3A+1', $e->getMessage() );
			$this->assertSame( 0, $this->queue->countBacklog() );
		}
	}

	public function test_process_queue_now_redirects_with_info_when_nothing_processed(): void {
		try {
			$this->admin->handle_process_queue_now();
			$this->fail( 'Expected redirect exception.' );
		} catch ( Atum_Test_Redirect_Exception $e ) {
			$this->assertStringContainsString( 'atum_mailer_notice=info', $e->getMessage() );
			$this->assertStringContainsString( 'Processed%3A+0', $e->getMessage() );
		}
	}
}
