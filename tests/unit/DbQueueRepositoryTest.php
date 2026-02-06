<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DbQueueRepositoryTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Db_Queue_Repository */
	private $queue;

	protected function setUp(): void {
		atum_test_reset_globals();
		$this->settings = new Atum_Mailer_Settings_Repository();
		$this->queue    = new Atum_Mailer_Db_Queue_Repository( $this->settings );
	}

	public function test_enqueue_release_and_succeed_job_lifecycle(): void {
		$job_id = $this->queue->enqueue(
			array( 'To' => 'recipient@example.com' ),
			42,
			time() - 30
		);

		$this->assertNotSame( '', $job_id );
		$this->assertSame( 1, $this->queue->countBacklog() );

		$claimed = $this->queue->claimDue( 5, time() );
		$this->assertCount( 1, $claimed );
		$this->assertSame( $job_id, (string) $claimed[0]['job_id'] );
		$this->assertSame( 42, (int) $claimed[0]['log_id'] );

		$this->queue->release( $job_id, 2, time() + 90, 'http_429' );
		$this->assertSame( 'retrying', $GLOBALS['wpdb']->rows[ (int) $job_id ]['status'] );
		$this->assertSame( 2, (int) $GLOBALS['wpdb']->rows[ (int) $job_id ]['attempt_count'] );
		$this->assertSame( 'http_429', (string) $GLOBALS['wpdb']->rows[ (int) $job_id ]['last_error_code'] );

		$this->queue->succeed( $job_id );
		$this->assertArrayNotHasKey( (int) $job_id, $GLOBALS['wpdb']->rows );
	}

	public function test_migrate_legacy_option_queue_moves_jobs_and_sets_flag(): void {
		update_option(
			Atum_Mailer_Settings_Repository::QUEUE_OPTION_KEY,
			array(
				'legacy-1' => array(
					'log_id'          => 12,
					'payload'         => array( 'To' => 'a@example.com' ),
					'attempt_count'   => 1,
					'next_attempt_at' => time() + 60,
					'last_error_code' => 'http_503',
				),
				'legacy-2' => array(
					'log_id'          => 13,
					'payload'         => array( 'To' => 'b@example.com' ),
					'next_attempt_at' => time() + 120,
				),
			)
		);

		$this->queue->maybe_migrate_legacy_queue();

		$this->assertSame( array(), $this->settings->get_queue_jobs() );
		$this->assertSame( 1, (int) get_option( Atum_Mailer_Db_Queue_Repository::MIGRATION_OPTION, 0 ) );
		$this->assertSame( 2, $this->queue->countBacklog() );
	}

	public function test_claim_due_runs_stale_processing_recovery_query(): void {
		$job_id = $this->queue->enqueue(
			array( 'To' => 'stale@example.com' ),
			99,
			time() - 10
		);

		$GLOBALS['wpdb']->rows[ (int) $job_id ]['status']    = 'processing';
		$GLOBALS['wpdb']->rows[ (int) $job_id ]['locked_at'] = gmdate( 'Y-m-d H:i:s', time() - 1000 );

		$this->queue->claimDue( 1, time() );

		$found = false;
		foreach ( $GLOBALS['wpdb']->query_log as $query ) {
			if ( false !== stripos( $query, "where status = 'processing'" ) && false !== stripos( $query, 'locked_at <' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found );
	}

	public function test_fail_only_removes_target_job(): void {
		$job1 = $this->queue->enqueue( array( 'To' => 'first@example.com' ), 1, time() - 5 );
		$job2 = $this->queue->enqueue( array( 'To' => 'second@example.com' ), 2, time() - 5 );
		$this->assertSame( 2, $this->queue->countBacklog() );

		$this->queue->fail( $job1 );

		$this->assertArrayNotHasKey( (int) $job1, $GLOBALS['wpdb']->rows );
		$this->assertArrayHasKey( (int) $job2, $GLOBALS['wpdb']->rows );
		$this->assertSame( 1, $this->queue->countBacklog() );
	}

	public function test_next_due_timestamp_uses_earliest_job(): void {
		$now = time();
		$this->queue->enqueue( array( 'To' => 'late@example.com' ), 1, $now + 120 );
		$this->queue->enqueue( array( 'To' => 'early@example.com' ), 2, $now + 30 );
		$this->queue->enqueue( array( 'To' => 'middle@example.com' ), 3, $now + 60 );

		$next = $this->queue->nextDueTimestamp();
		$this->assertNotNull( $next );
		$this->assertLessThanOrEqual( $now + 35, (int) $next );
	}
}
