<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Atum_Test_QueueRepo_Postmark_Client extends Atum_Mailer_Postmark_Client {
	/** @var array<int, mixed> */
	public $responses = array();

	/** @var int */
	public $send_calls = 0;

	public function send( $payload, $token, $atts = array(), $options = array() ) {
		unset( $payload, $token, $atts, $options );
		$this->send_calls++;

		if ( empty( $this->responses ) ) {
			return new WP_Error( 'no_response', 'No response configured.', array( 'retryable' => true ) );
		}

		return array_shift( $this->responses );
	}
}

class Atum_Test_In_Memory_Queue_Repository implements Atum_Mailer_Queue_Repository_Interface {
	/** @var array<string, array<string, mixed>> */
	private $jobs = array();

	/** @var int */
	private $next_id = 1;

	/** @var array<int, array<string, mixed>> */
	public $released = array();

	/** @var array<int, string> */
	public $succeeded = array();

	/** @var array<int, string> */
	public $failed = array();

	public function maybe_upgrade_database( $force = false ) {
		unset( $force );
	}

	public function maybe_migrate_legacy_queue() {
	}

	public function enqueue( $payload, $log_id, $first_attempt_at ) {
		$job_id             = (string) $this->next_id++;
		$this->jobs[ $job_id ] = array(
			'job_id'          => $job_id,
			'log_id'          => absint( $log_id ),
			'payload'         => is_array( $payload ) ? $payload : array(),
			'attempt_count'   => 0,
			'next_attempt_at' => (int) $first_attempt_at,
			'last_error_code' => '',
			'created_at'      => time(),
			'status'          => 'queued',
		);

		return $job_id;
	}

	public function claimDue( $limit, $now ) {
		$limit = max( 1, (int) $limit );
		$now   = (int) $now;
		$out   = array();

		foreach ( $this->jobs as $job_id => $job ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'retrying' ), true ) ) {
				continue;
			}
			if ( (int) ( $job['next_attempt_at'] ?? 0 ) > $now ) {
				continue;
			}

			$this->jobs[ $job_id ]['status'] = 'processing';
			$out[]                           = $this->jobs[ $job_id ];
		}

		return $out;
	}

	public function release( $job_id, $attempt_count, $next_attempt_at, $last_error_code ) {
		$job_id = (string) $job_id;
		if ( ! isset( $this->jobs[ $job_id ] ) ) {
			return;
		}

		$this->jobs[ $job_id ]['attempt_count']   = absint( $attempt_count );
		$this->jobs[ $job_id ]['next_attempt_at'] = (int) $next_attempt_at;
		$this->jobs[ $job_id ]['last_error_code'] = sanitize_key( (string) $last_error_code );
		$this->jobs[ $job_id ]['status']          = 'retrying';

		$this->released[] = array(
			'job_id'          => $job_id,
			'attempt_count'   => absint( $attempt_count ),
			'next_attempt_at' => (int) $next_attempt_at,
			'last_error_code' => sanitize_key( (string) $last_error_code ),
		);
	}

	public function fail( $job_id ) {
		$job_id = (string) $job_id;
		if ( isset( $this->jobs[ $job_id ] ) ) {
			unset( $this->jobs[ $job_id ] );
			$this->failed[] = $job_id;
		}
	}

	public function succeed( $job_id ) {
		$job_id = (string) $job_id;
		if ( isset( $this->jobs[ $job_id ] ) ) {
			unset( $this->jobs[ $job_id ] );
			$this->succeeded[] = $job_id;
		}
	}

	public function countBacklog() {
		return count( $this->jobs );
	}

	public function oldestCreatedTimestamp() {
		if ( empty( $this->jobs ) ) {
			return null;
		}

		$oldest = null;
		foreach ( $this->jobs as $job ) {
			$candidate = isset( $job['created_at'] ) ? (int) $job['created_at'] : time();
			if ( null === $oldest || $candidate < $oldest ) {
				$oldest = $candidate;
			}
		}

		return null === $oldest ? null : (int) $oldest;
	}

	public function purge( $older_than_seconds = 0 ) {
		$older_than_seconds = max( 0, (int) $older_than_seconds );
		$count_before       = count( $this->jobs );

		if ( 0 === $older_than_seconds ) {
			$this->jobs = array();
			return $count_before;
		}

		$threshold = time() - $older_than_seconds;
		foreach ( $this->jobs as $job_id => $job ) {
			$created_at = isset( $job['created_at'] ) ? (int) $job['created_at'] : time();
			if ( $created_at <= $threshold ) {
				unset( $this->jobs[ $job_id ] );
			}
		}

		return $count_before - count( $this->jobs );
	}

	public function nextDueTimestamp() {
		if ( empty( $this->jobs ) ) {
			return null;
		}

		$next = null;
		foreach ( $this->jobs as $job ) {
			$candidate = (int) ( $job['next_attempt_at'] ?? 0 );
			if ( null === $next || $candidate < $next ) {
				$next = $candidate;
			}
		}

		return null === $next ? null : (int) $next;
	}
}

final class MailInterceptorQueueRepositoryTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Log_Repository */
	private $logs;

	/** @var Atum_Test_QueueRepo_Postmark_Client */
	private $client;

	/** @var Atum_Test_In_Memory_Queue_Repository */
	private $queue;

	/** @var Atum_Mailer_Mail_Interceptor */
	private $interceptor;

	protected function setUp(): void {
		atum_test_reset_globals();
		$this->settings = new Atum_Mailer_Settings_Repository();
		$this->logs     = new Atum_Mailer_Log_Repository( $this->settings );
		$this->client   = new Atum_Test_QueueRepo_Postmark_Client();
		$this->queue    = new Atum_Test_In_Memory_Queue_Repository();
		$this->interceptor = new Atum_Mailer_Mail_Interceptor( $this->settings, $this->logs, $this->client, $this->queue );
	}

	public function test_queue_mode_enqueue_uses_repository(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$options['delivery_mode']         = 'queue';
		$options['force_from']            = 1;
		$options['from_email']            = 'sender@example.com';
		$options['mail_retention']        = 1;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$result = $this->interceptor->maybe_send_with_postmark(
			null,
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Queue Repo',
				'message'     => 'hello',
				'headers'     => array(),
				'attachments' => array(),
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->queue->countBacklog() );
		$this->assertSame( 0, $this->client->send_calls );
	}

	public function test_process_queue_releases_retryable_errors_via_repository(): void {
		$options                           = $this->settings->default_options();
		$options['enabled']                = 1;
		$options['postmark_server_token']  = 'token-abc';
		$options['delivery_mode']          = 'queue';
		$options['queue_max_attempts']     = 3;
		$options['queue_retry_base_delay'] = 60;
		$options['queue_retry_max_delay']  = 300;
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$this->queue->enqueue( array( 'To' => 'recipient@example.com' ), 7, time() - 1 );

		$this->client->responses[] = new WP_Error(
			'atum_mailer_postmark_error',
			'Rate limited',
			array(
				'status_code' => 429,
				'retryable'   => true,
			)
		);

		$this->interceptor->process_queue();

		$this->assertCount( 1, $this->queue->released );
		$this->assertSame( 'http_429', $this->queue->released[0]['last_error_code'] );
		$this->assertSame( 'retrying', $GLOBALS['wpdb']->last_update_data['status'] );
	}

	public function test_process_queue_marks_success_via_repository(): void {
		$options                          = $this->settings->default_options();
		$options['enabled']               = 1;
		$options['postmark_server_token'] = 'token-abc';
		$options['delivery_mode']         = 'queue';
		$this->settings->set_token( 'token-abc' );
		$this->settings->update_raw_options( $options );

		$job_id = $this->queue->enqueue( array( 'To' => 'recipient@example.com' ), 8, time() - 1 );
		$this->client->responses[] = array(
			'status_code' => 200,
			'body'        => '{"MessageID":"mid-queue"}',
			'decoded'     => array( 'MessageID' => 'mid-queue' ),
			'message_id'  => 'mid-queue',
			'response'    => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
		);

		$this->interceptor->process_queue();

		$this->assertSame( array( $job_id ), $this->queue->succeeded );
		$this->assertSame( 0, $this->queue->countBacklog() );
		$this->assertSame( 'sent', $GLOBALS['wpdb']->last_update_data['status'] );
	}
}
