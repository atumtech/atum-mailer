<?php
/**
 * Legacy option-backed queue repository.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Option_Queue_Repository implements Atum_Mailer_Queue_Repository_Interface {
	/**
	 * Settings repository.
	 *
	 * @var Atum_Mailer_Settings_Repository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Atum_Mailer_Settings_Repository $settings Settings repository.
	 */
	public function __construct( Atum_Mailer_Settings_Repository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @inheritDoc
	 */
	public function maybe_upgrade_database( $force = false ) {
		unset( $force );
	}

	/**
	 * @inheritDoc
	 */
	public function maybe_migrate_legacy_queue() {
	}

	/**
	 * @inheritDoc
	 */
	public function enqueue( $payload, $log_id, $first_attempt_at ) {
		$jobs   = $this->settings->get_queue_jobs();
		$job_id = (string) ( $log_id > 0 ? $log_id : wp_generate_uuid4() );

		$jobs[ $job_id ] = array(
			'log_id'          => absint( $log_id ),
			'payload'         => is_array( $payload ) ? $payload : array(),
			'attempt_count'   => 0,
			'next_attempt_at' => (int) $first_attempt_at,
			'created_at'      => time(),
			'last_error_code' => '',
		);

		$this->settings->update_queue_jobs( $jobs );
		return $job_id;
	}

	/**
	 * @inheritDoc
	 */
	public function claimDue( $limit, $now ) {
		$jobs    = $this->settings->get_queue_jobs();
		$due     = array();
		$limit   = max( 1, (int) $limit );
		$now     = (int) $now;

		foreach ( $jobs as $job_id => $job ) {
			if ( count( $due ) >= $limit ) {
				break;
			}

			$next_at = isset( $job['next_attempt_at'] ) ? (int) $job['next_attempt_at'] : 0;
			if ( $next_at > $now ) {
				continue;
			}

			$job['job_id'] = (string) $job_id;
			$due[]         = $job;
		}

		return $due;
	}

	/**
	 * @inheritDoc
	 */
	public function release( $job_id, $attempt_count, $next_attempt_at, $last_error_code ) {
		$jobs = $this->settings->get_queue_jobs();
		if ( ! isset( $jobs[ $job_id ] ) ) {
			return;
		}

		$jobs[ $job_id ]['attempt_count']   = absint( $attempt_count );
		$jobs[ $job_id ]['next_attempt_at'] = (int) $next_attempt_at;
		$jobs[ $job_id ]['last_error_code'] = sanitize_key( $last_error_code );
		$this->settings->update_queue_jobs( $jobs );
	}

	/**
	 * @inheritDoc
	 */
	public function fail( $job_id ) {
		$jobs = $this->settings->get_queue_jobs();
		if ( isset( $jobs[ $job_id ] ) ) {
			unset( $jobs[ $job_id ] );
			$this->settings->update_queue_jobs( $jobs );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function succeed( $job_id ) {
		$this->fail( $job_id );
	}

	/**
	 * @inheritDoc
	 */
	public function countBacklog() {
		return $this->settings->get_queue_backlog_count();
	}

	/**
	 * @inheritDoc
	 */
	public function oldestCreatedTimestamp() {
		$jobs = $this->settings->get_queue_jobs();
		if ( empty( $jobs ) ) {
			return null;
		}

		$oldest = null;
		foreach ( $jobs as $job ) {
			$candidate = isset( $job['created_at'] ) ? (int) $job['created_at'] : time();
			if ( null === $oldest || $candidate < $oldest ) {
				$oldest = $candidate;
			}
		}

		return null === $oldest ? null : (int) $oldest;
	}

	/**
	 * @inheritDoc
	 */
	public function purge( $older_than_seconds = 0 ) {
		$older_than_seconds = max( 0, (int) $older_than_seconds );
		$jobs               = $this->settings->get_queue_jobs();
		$original_count     = count( $jobs );

		if ( 0 === $older_than_seconds ) {
			$this->settings->update_queue_jobs( array() );
			return $original_count;
		}

		$threshold = time() - $older_than_seconds;
		foreach ( $jobs as $job_id => $job ) {
			$created_at = isset( $job['created_at'] ) ? (int) $job['created_at'] : time();
			if ( $created_at <= $threshold ) {
				unset( $jobs[ $job_id ] );
			}
		}

		$this->settings->update_queue_jobs( $jobs );
		return $original_count - count( $jobs );
	}

	/**
	 * @inheritDoc
	 */
	public function nextDueTimestamp() {
		$jobs = $this->settings->get_queue_jobs();
		if ( empty( $jobs ) ) {
			return null;
		}

		$next = null;
		foreach ( $jobs as $job ) {
			$candidate = isset( $job['next_attempt_at'] ) ? (int) $job['next_attempt_at'] : time() + 30;
			if ( null === $next || $candidate < $next ) {
				$next = $candidate;
			}
		}

		return null === $next ? null : (int) $next;
	}
}
