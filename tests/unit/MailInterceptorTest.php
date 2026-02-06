<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Atum_Test_Configurable_Postmark_Client extends Atum_Mailer_Postmark_Client {
	/** @var array<int, mixed> */
	public $responses = array();
	/** @var int */
	public $send_calls = 0;

	public function send_email( $payload, $token, $atts = array(), $options = array() ) {
		unset( $payload, $token, $atts, $options );
		$this->send_calls++;
		if ( empty( $this->responses ) ) {
			return new WP_Error( 'no_response', 'No response configured.', array( 'retryable' => true ) );
		}
		return array_shift( $this->responses );
	}
}

final class MailInterceptorTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Log_Repository */
	private $logs;

	/** @var Atum_Test_Configurable_Postmark_Client */
	private $client;

	/** @var Atum_Mailer_Mail_Interceptor */
	private $interceptor;

	protected function setUp(): void {
		atum_test_reset_globals();
		$this->settings    = new Atum_Mailer_Settings_Repository();
		$this->logs        = new Atum_Mailer_Log_Repository( $this->settings );
		$this->client      = new Atum_Test_Configurable_Postmark_Client();
		$this->interceptor = new Atum_Mailer_Mail_Interceptor( $this->settings, $this->logs, $this->client );
	}

	public function test_disabled_mode_passthroughs_to_pre_wp_mail(): void {
		$options            = $this->settings->default_options();
		$options['enabled'] = 0;
		$options['postmark_server_token'] = '';
		$this->settings->update_raw_options( $options );

		$result = $this->interceptor->maybe_send_with_postmark(
			'original-pre',
			array(
				'to'          => 'user@example.com',
				'subject'     => 'Subject',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertSame( 'original-pre', $result );
		$this->assertSame( 0, $this->client->send_calls );
	}

	public function test_non_null_pre_wp_mail_short_circuits_even_when_plugin_enabled(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$result = $this->interceptor->maybe_send_with_postmark(
			true,
			array(
				'to'          => 'user@example.com',
				'subject'     => 'Subject',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 0, $this->client->send_calls );
		$this->assertCount( 0, $GLOBALS['wpdb']->rows );
	}

	public function test_queue_mode_enqueues_job_without_immediate_send(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'queue';
		$options['force_from']             = 1;
		$options['from_email']             = 'sender@example.com';
		$options['mail_retention']         = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$result = $this->interceptor->maybe_send_with_postmark(
			null,
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Queued Message',
				'message'     => 'Hello',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 0, $this->client->send_calls );
		$this->assertCount( 1, $this->settings->get_queue_jobs() );
		$this->assertSame( 'queued', $GLOBALS['wpdb']->last_insert_data['status'] );
	}

	public function test_immediate_failure_marks_log_failed_and_sets_outage(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'immediate';
		$options['force_from']             = 1;
		$options['from_email']             = 'sender@example.com';
		$options['mail_retention']         = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->client->responses[] = new WP_Error(
			'atum_mailer_postmark_error',
			'Provider unavailable',
			array(
				'status_code' => 503,
				'response'    => '{"error":"down"}',
				'payload'     => array( 'To' => 'recipient@example.com' ),
				'retryable'   => true,
			)
		);

		$result = $this->interceptor->maybe_send_with_postmark(
			null,
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Immediate Message',
				'message'     => 'Hello',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertFalse( $result );
		$this->assertSame( 'failed', $GLOBALS['wpdb']->last_update_data['status'] );
		$this->assertSame( 'http_503', $GLOBALS['wpdb']->last_update_data['last_error_code'] );
		$this->assertNotSame( '', (string) get_option( Atum_Mailer_Settings_Repository::LAST_API_OUTAGE_OPTION, '' ) );
	}

	public function test_process_queue_retries_retryable_errors(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'queue';
		$options['queue_max_attempts']     = 3;
		$options['queue_retry_base_delay'] = 60;
		$options['queue_retry_max_delay']  = 600;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->settings->update_queue_jobs(
			array(
				'job-1' => array(
					'log_id'          => 1,
					'payload'         => array( 'To' => 'recipient@example.com' ),
					'attempt_count'   => 0,
					'next_attempt_at' => time() - 1,
					'created_at'      => time() - 5,
					'last_error_code' => '',
				),
			)
		);

		$this->client->responses[] = new WP_Error(
			'atum_mailer_postmark_error',
			'Rate limited',
			array(
				'status_code' => 429,
				'retryable'   => true,
			)
		);

		$this->interceptor->process_queue();

		$jobs = $this->settings->get_queue_jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( 1, $jobs['job-1']['attempt_count'] );
		$this->assertGreaterThan( time(), (int) $jobs['job-1']['next_attempt_at'] );
		$this->assertSame( 'retrying', $GLOBALS['wpdb']->last_update_data['status'] );
	}

	public function test_process_queue_removes_job_on_success(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'queue';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->settings->update_queue_jobs(
			array(
				'job-1' => array(
					'log_id'          => 1,
					'payload'         => array( 'To' => 'recipient@example.com' ),
					'attempt_count'   => 0,
					'next_attempt_at' => time() - 1,
					'created_at'      => time() - 5,
					'last_error_code' => '',
				),
			)
		);

		$this->client->responses[] = array(
			'status_code' => 200,
			'body'        => '{"MessageID":"abc"}',
			'decoded'     => array( 'MessageID' => 'abc' ),
			'message_id'  => 'abc',
			'response'    => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);

		$this->interceptor->process_queue();

		$this->assertSame( array(), $this->settings->get_queue_jobs() );
		$this->assertSame( 'sent', $GLOBALS['wpdb']->last_update_data['status'] );
		$this->assertSame( 'abc', $GLOBALS['wpdb']->last_update_data['provider_message_id'] );
	}

	public function test_process_queue_respects_max_jobs_budget_and_reschedules(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$options['delivery_mode']         = 'queue';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		add_filter(
			'atum_mailer_max_jobs_per_run',
			static function () {
				return 1;
			}
		);

		$this->settings->update_queue_jobs(
			array(
				'job-1' => array(
					'log_id'          => 1,
					'payload'         => array( 'To' => 'first@example.com' ),
					'attempt_count'   => 0,
					'next_attempt_at' => time() - 1,
				),
				'job-2' => array(
					'log_id'          => 2,
					'payload'         => array( 'To' => 'second@example.com' ),
					'attempt_count'   => 0,
					'next_attempt_at' => time() - 1,
				),
			)
		);

		$this->client->responses[] = array(
			'status_code' => 200,
			'body'        => '{"MessageID":"job-1"}',
			'decoded'     => array( 'MessageID' => 'job-1' ),
			'message_id'  => 'job-1',
			'response'    => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);
		$this->client->responses[] = array(
			'status_code' => 200,
			'body'        => '{"MessageID":"job-2"}',
			'decoded'     => array( 'MessageID' => 'job-2' ),
			'message_id'  => 'job-2',
			'response'    => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);

		$this->interceptor->process_queue();

		$remaining = $this->settings->get_queue_jobs();
		$this->assertCount( 1, $remaining );
		$this->assertSame( 1, $this->client->send_calls );
		$this->assertNotFalse( wp_next_scheduled( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK ) );
	}

	public function test_attachment_size_limit_fails_before_provider_call(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'immediate';
		$options['force_from']             = 1;
		$options['from_email']             = 'sender@example.com';
		$options['mail_retention']         = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		add_filter(
			'atum_mailer_max_attachment_bytes',
			static function () {
				return 1;
			}
		);
		add_filter(
			'atum_mailer_max_total_attachment_bytes',
			static function () {
				return 1;
			}
		);

		$path = tempnam( sys_get_temp_dir(), 'atum-mailer-test-' );
		$this->assertNotFalse( $path );
		file_put_contents( (string) $path, '0123456789' );
		$this->assertGreaterThan( 1, filesize( (string) $path ) );

		$result = $this->interceptor->maybe_send_with_postmark(
			null,
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Attachment Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array( $path ),
			)
		);

		@unlink( (string) $path );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->client->send_calls );
		$this->assertSame( 'failed', $GLOBALS['wpdb']->last_update_data['status'] );
		$this->assertSame( 'atum_mailer_attachment_too_large', $GLOBALS['wpdb']->last_update_data['last_error_code'] );
	}

	public function test_retryable_outage_can_fallback_to_native_wp_mail(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$options['delivery_mode']         = 'immediate';
		$options['fallback_to_wp_mail']   = 1;
		$options['force_from']            = 1;
		$options['from_email']            = 'sender@example.com';
		$options['mail_retention']        = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->client->responses[] = new WP_Error(
			'atum_mailer_postmark_error',
			'Provider unavailable',
			array(
				'status_code' => 503,
				'retryable'   => true,
				'response'    => '{}',
				'payload'     => array( 'To' => 'recipient@example.com' ),
			)
		);

		$result = $this->interceptor->maybe_send_with_postmark(
			null,
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Fallback Message',
				'message'     => 'Hello',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertNull( $result );
		$this->assertSame( 'bypassed', $GLOBALS['wpdb']->last_update_data['status'] );
	}

	public function test_queue_terminal_failure_marks_dead_letter(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'queue';
		$options['queue_max_attempts']     = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->settings->update_queue_jobs(
			array(
				'job-1' => array(
					'log_id'          => 1,
					'payload'         => array( 'To' => 'recipient@example.com' ),
					'attempt_count'   => 0,
					'next_attempt_at' => time() - 1,
				),
			)
		);

		$this->client->responses[] = new WP_Error(
			'atum_mailer_postmark_error',
			'Still unavailable',
			array(
				'status_code' => 503,
				'retryable'   => true,
			)
		);

		$this->interceptor->process_queue();

		$this->assertSame( array(), $this->settings->get_queue_jobs() );
		$this->assertSame( 'dead_letter', $GLOBALS['wpdb']->last_update_data['status'] );
	}
}
