<?php
/**
 * DB-backed queue repository.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Db_Queue_Repository implements Atum_Mailer_Queue_Repository_Interface {
	const DB_VERSION_OPTION      = 'atum_mailer_queue_db_version';
	const DB_VERSION             = '1.0.0';
	const MIGRATION_OPTION       = 'atum_mailer_queue_migrated_from_option';
	const TABLE_SUFFIX           = 'atum_mailer_queue';
	const STALE_PROCESSING_TTL   = 300;

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
	 * Queue table name helper.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * @inheritDoc
	 */
	public function maybe_upgrade_database( $force = false ) {
		$current_version = get_option( self::DB_VERSION_OPTION, '' );
		if ( ! $force && self::DB_VERSION === $current_version ) {
			return;
		}

		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			log_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			payload LONGTEXT NULL,
			attempt_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			last_error_code VARCHAR(80) NULL,
			lock_token VARCHAR(80) NULL,
			locked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status_next_attempt (status, next_attempt_at),
			KEY log_id (log_id),
			KEY locked_at (locked_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * @inheritDoc
	 */
	public function maybe_migrate_legacy_queue() {
		if ( get_option( self::MIGRATION_OPTION, 0 ) ) {
			return;
		}

		$jobs = $this->settings->get_queue_jobs();
		if ( empty( $jobs ) ) {
			update_option( self::MIGRATION_OPTION, 1 );
			return;
		}

		foreach ( $jobs as $job ) {
			$payload         = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
			$log_id          = isset( $job['log_id'] ) ? absint( $job['log_id'] ) : 0;
			$next_attempt_at = isset( $job['next_attempt_at'] ) ? (int) $job['next_attempt_at'] : time();
			$job_id          = $this->enqueue( $payload, $log_id, $next_attempt_at );

			if ( isset( $job['attempt_count'] ) || isset( $job['last_error_code'] ) ) {
				$this->release(
					$job_id,
					isset( $job['attempt_count'] ) ? absint( $job['attempt_count'] ) : 0,
					$next_attempt_at,
					isset( $job['last_error_code'] ) ? sanitize_key( (string) $job['last_error_code'] ) : ''
				);
			}
		}

		$this->settings->update_queue_jobs( array() );
		update_option( self::MIGRATION_OPTION, 1 );
	}

	/**
	 * @inheritDoc
	 */
	public function enqueue( $payload, $log_id, $first_attempt_at ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'log_id'          => absint( $log_id ),
				'status'          => 'queued',
				'payload'         => wp_json_encode( is_array( $payload ) ? $payload : array() ),
				'attempt_count'   => 0,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', max( time(), (int) $first_attempt_at ) ),
				'last_error_code' => '',
				'lock_token'      => '',
				'locked_at'       => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return (string) absint( $wpdb->insert_id );
	}

	/**
	 * @inheritDoc
	 */
	public function claimDue( $limit, $now ) {
		$limit = max( 1, (int) $limit );
		$now   = (int) $now;
		$this->recover_stale_processing( $now );

		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, log_id, payload, attempt_count, next_attempt_at, last_error_code
				FROM {$table}
				WHERE status IN (%s, %s)
				AND next_attempt_at <= %s
				ORDER BY next_attempt_at ASC, id ASC
				LIMIT %d",
				'queued',
				'retrying',
				gmdate( 'Y-m-d H:i:s', $now ),
				$limit
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$claimed = array();
		$locked  = current_time( 'mysql' );
		foreach ( $rows as $row ) {
			$job_id    = (string) absint( $row->id ?? 0 );
			$lock      = wp_generate_uuid4();
			$updated   = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s, lock_token = %s, locked_at = %s, updated_at = %s
					WHERE id = %d
					AND status IN (%s, %s)",
					'processing',
					$lock,
					$locked,
					$locked,
					absint( $job_id ),
					'queued',
					'retrying'
				)
			);

			if ( 1 !== (int) $updated ) {
				continue;
			}

			$payload = json_decode( (string) ( $row->payload ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			$claimed[] = array(
				'job_id'          => $job_id,
				'log_id'          => absint( $row->log_id ?? 0 ),
				'payload'         => $payload,
				'attempt_count'   => absint( $row->attempt_count ?? 0 ),
				'next_attempt_at' => strtotime( (string) ( $row->next_attempt_at ?? '' ) ),
				'last_error_code' => sanitize_key( (string) ( $row->last_error_code ?? '' ) ),
			);
		}

		return $claimed;
	}

	/**
	 * @inheritDoc
	 */
	public function release( $job_id, $attempt_count, $next_attempt_at, $last_error_code ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->update(
			$table,
			array(
				'status'          => 'retrying',
				'attempt_count'   => absint( $attempt_count ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', max( time() + 1, (int) $next_attempt_at ) ),
				'last_error_code' => sanitize_key( (string) $last_error_code ),
				'lock_token'      => '',
				'locked_at'       => null,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => absint( $job_id ) ),
			null,
			array( '%d' )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function fail( $job_id ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", absint( $job_id ) ) );
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
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN (%s, %s, %s)",
				'queued',
				'retrying',
				'processing'
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function oldestCreatedTimestamp() {
		global $wpdb;
		$table = self::table_name();
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE status IN (%s, %s, %s) ORDER BY created_at ASC, id ASC LIMIT 1",
				'queued',
				'retrying',
				'processing'
			)
		);

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * @inheritDoc
	 */
	public function purge( $older_than_seconds = 0 ) {
		global $wpdb;
		$table = self::table_name();
		$older_than_seconds = max( 0, (int) $older_than_seconds );

		if ( 0 === $older_than_seconds ) {
			$deleted = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return is_numeric( $deleted ) ? (int) $deleted : 0;
		}

		$threshold = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );
		$deleted   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at <= %s",
				$threshold
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function nextDueTimestamp() {
		global $wpdb;
		$table = self::table_name();

		$next = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT next_attempt_at FROM {$table} WHERE status IN (%s, %s, %s) ORDER BY next_attempt_at ASC, id ASC LIMIT 1",
				'queued',
				'retrying',
				'processing'
			)
		);

		if ( ! is_string( $next ) || '' === $next ) {
			return null;
		}

		$timestamp = strtotime( $next );
		return false === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * Return stale processing jobs back to retrying.
	 *
	 * @param int $now Current timestamp.
	 * @return void
	 */
	private function recover_stale_processing( $now ) {
		global $wpdb;
		$table     = self::table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', max( 0, $now - self::STALE_PROCESSING_TTL ) );
		$current   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, lock_token = %s, locked_at = NULL, updated_at = %s, next_attempt_at = %s
				WHERE status = %s AND locked_at IS NOT NULL AND locked_at < %s",
				'retrying',
				'',
				$current,
				gmdate( 'Y-m-d H:i:s', max( time(), $now ) ),
				'processing',
				$threshold
			)
		);
	}
}
