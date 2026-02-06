<?php
/**
 * Queue repository contract.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Atum_Mailer_Queue_Repository_Interface {
	/**
	 * Ensure queue storage schema exists.
	 *
	 * @param bool $force Force schema checks.
	 * @return void
	 */
	public function maybe_upgrade_database( $force = false );

	/**
	 * Migrate legacy option-backed queue jobs into repository.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_queue();

	/**
	 * Enqueue a job.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $log_id Related log id.
	 * @param int                  $first_attempt_at Unix timestamp.
	 * @return string Queue job id.
	 */
	public function enqueue( $payload, $log_id, $first_attempt_at );

	/**
	 * Claim due jobs for processing.
	 *
	 * @param int $limit Max jobs.
	 * @param int $now Unix timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	public function claimDue( $limit, $now );

	/**
	 * Release/Retry a claimed job.
	 *
	 * @param string $job_id Job id.
	 * @param int    $attempt_count Attempt count.
	 * @param int    $next_attempt_at Unix timestamp.
	 * @param string $last_error_code Last error code.
	 * @return void
	 */
	public function release( $job_id, $attempt_count, $next_attempt_at, $last_error_code );

	/**
	 * Mark job as terminal failure.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function fail( $job_id );

	/**
	 * Mark job success and remove from queue.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function succeed( $job_id );

	/**
	 * Count queued/retrying/processing jobs.
	 *
	 * @return int
	 */
	public function countBacklog();

	/**
	 * Get oldest backlog created-at timestamp.
	 *
	 * @return int|null
	 */
	public function oldestCreatedTimestamp();

	/**
	 * Purge queue jobs.
	 *
	 * @param int $older_than_seconds Purge jobs older than N seconds. 0 means all.
	 * @return int Deleted rows.
	 */
	public function purge( $older_than_seconds = 0 );

	/**
	 * Get earliest next-attempt timestamp from backlog.
	 *
	 * @return int|null
	 */
	public function nextDueTimestamp();
}
