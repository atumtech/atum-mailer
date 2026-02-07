<?php
/**
 * Log repository for atum.mailer.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Log_Repository {
	const DB_VERSION_OPTION = 'atum_mailer_db_version';
	const DB_VERSION        = '2.0.0';
	const LOG_TABLE_SUFFIX  = 'atum_mailer_logs';

	/**
	 * Delivery status progression map.
	 *
	 * @var array<string, int>
	 */
	private static $status_rank = array(
		'processing' => 10,
		'queued'     => 10,
		'retrying'   => 20,
		'sent'       => 30,
		'delivered'  => 40,
		'failed'     => 50,
		'bypassed'   => 50,
		'dead_letter'=> 60,
	);

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
	 * Table name helper.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
	}

	/**
	 * Create/upgrade DB structures.
	 *
	 * @param bool $force Force upgrade.
	 * @return void
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
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			mail_to LONGTEXT NULL,
			subject TEXT NULL,
			message LONGTEXT NULL,
			headers LONGTEXT NULL,
			attachments LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'processing',
			provider VARCHAR(30) NOT NULL DEFAULT 'postmark',
			provider_message_id VARCHAR(255) NULL,
			error_message TEXT NULL,
			http_status SMALLINT(5) UNSIGNED NULL,
			request_payload LONGTEXT NULL,
			response_body LONGTEXT NULL,
			attempt_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NULL,
			last_error_code VARCHAR(80) NULL,
			delivery_mode VARCHAR(20) NOT NULL DEFAULT 'immediate',
			webhook_event_type VARCHAR(60) NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY status_created (status, created_at),
			KEY next_attempt_at (next_attempt_at),
			KEY provider_message_id (provider_message_id)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert initial log row.
	 *
	 * @param array<string, mixed> $atts Mail attributes.
	 * @param string               $status Status.
	 * @param array<string, mixed> $options Plugin options.
	 * @param array<string, mixed> $extra Extra fields.
	 * @return int
	 */
	public function insert_mail_log( $atts, $status, $options, $extra = array() ) {
		if ( empty( $options['mail_retention'] ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$detail_mode = (string) ( $options['log_detail_mode'] ?? 'metadata' );
		$is_full     = 'full' === $detail_mode;

		$data = array(
			'created_at'      => $now,
			'updated_at'      => $now,
			'mail_to'         => wp_json_encode( $this->normalize_addresses( $atts['to'] ?? array() ) ),
			'subject'         => sanitize_text_field( (string) ( $atts['subject'] ?? '' ) ),
			'message'         => $is_full ? (string) ( $atts['message'] ?? '' ) : '',
			'headers'         => $is_full ? wp_json_encode( $atts['headers'] ?? array() ) : '',
			'attachments'     => $is_full ? wp_json_encode( $atts['attachments'] ?? array() ) : '',
			'status'          => sanitize_key( $status ),
			'provider'        => 'postmark',
			'attempt_count'   => isset( $extra['attempt_count'] ) ? absint( $extra['attempt_count'] ) : 0,
			'next_attempt_at' => isset( $extra['next_attempt_at'] ) ? sanitize_text_field( (string) $extra['next_attempt_at'] ) : null,
			'last_error_code' => isset( $extra['last_error_code'] ) ? sanitize_key( (string) $extra['last_error_code'] ) : '',
			'delivery_mode'   => isset( $extra['delivery_mode'] ) ? sanitize_key( (string) $extra['delivery_mode'] ) : 'immediate',
		);

		if ( isset( $extra['provider_message_id'] ) ) {
			$data['provider_message_id'] = sanitize_text_field( (string) $extra['provider_message_id'] );
		}
		if ( isset( $extra['error_message'] ) ) {
			$data['error_message'] = sanitize_text_field( (string) $extra['error_message'] );
		}
		if ( isset( $extra['http_status'] ) ) {
			$data['http_status'] = absint( $extra['http_status'] );
		}

		if ( $is_full ) {
			if ( isset( $extra['request_payload'] ) ) {
				$data['request_payload'] = is_string( $extra['request_payload'] ) ? $extra['request_payload'] : wp_json_encode( $extra['request_payload'] );
			}
			if ( isset( $extra['response_body'] ) ) {
				$data['response_body'] = (string) $extra['response_body'];
			}
		}

		$data = apply_filters(
			'atum_mailer_log_record',
			$data,
			array(
				'operation' => 'insert',
				'status'    => $status,
				'options'   => $options,
				'atts'      => $atts,
			)
		);

		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update existing log row.
	 *
	 * @param int                  $log_id Row id.
	 * @param string               $status New status.
	 * @param array<string, mixed> $details Fields.
	 * @param array<string, mixed> $options Plugin options.
	 * @return void
	 */
	public function update_mail_log( $log_id, $status, $details, $options ) {
		if ( $log_id <= 0 || empty( $options['mail_retention'] ) ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		$detail_mode = (string) ( $options['log_detail_mode'] ?? 'metadata' );
		$is_full     = 'full' === $detail_mode;
		$status      = sanitize_key( $status );
		$current     = $this->get_log_status( $log_id );

		if ( '' !== $current && ! $this->is_transition_allowed( $current, $status, array( 'log_id' => $log_id, 'details' => $details ) ) ) {
			$status = $current;
		}

		$data = array(
			'updated_at' => current_time( 'mysql' ),
			'status'     => $status,
		);

		if ( isset( $details['provider_message_id'] ) ) {
			$data['provider_message_id'] = sanitize_text_field( (string) $details['provider_message_id'] );
		}
		if ( isset( $details['error_message'] ) ) {
			$data['error_message'] = sanitize_text_field( (string) $details['error_message'] );
		}
		if ( isset( $details['http_status'] ) ) {
			$data['http_status'] = absint( $details['http_status'] );
		}
		if ( isset( $details['attempt_count'] ) ) {
			$data['attempt_count'] = absint( $details['attempt_count'] );
		}
		if ( isset( $details['next_attempt_at'] ) ) {
			$data['next_attempt_at'] = '' === (string) $details['next_attempt_at'] ? null : sanitize_text_field( (string) $details['next_attempt_at'] );
		}
		if ( isset( $details['last_error_code'] ) ) {
			$data['last_error_code'] = sanitize_key( (string) $details['last_error_code'] );
		}
		if ( isset( $details['delivery_mode'] ) ) {
			$data['delivery_mode'] = sanitize_key( (string) $details['delivery_mode'] );
		}
		if ( isset( $details['webhook_event_type'] ) ) {
			$data['webhook_event_type'] = sanitize_key( (string) $details['webhook_event_type'] );
		}

		if ( $is_full || ! empty( $details['force_store_payload'] ) ) {
			if ( isset( $details['request_payload'] ) ) {
				$data['request_payload'] = is_string( $details['request_payload'] ) ? $details['request_payload'] : wp_json_encode( $details['request_payload'] );
			}
			if ( isset( $details['response_body'] ) ) {
				$data['response_body'] = (string) $details['response_body'];
			}
		}

		$data = apply_filters(
			'atum_mailer_log_record',
			$data,
			array(
				'operation' => 'update',
				'status'    => $status,
				'options'   => $options,
				'log_id'    => $log_id,
			)
		);

		$wpdb->update( $table, $data, array( 'id' => $log_id ), null, array( '%d' ) );
	}

	/**
	 * Update by provider message id.
	 *
	 * @param string               $provider_message_id Provider message id.
	 * @param string               $status Status.
	 * @param array<string, mixed> $details Details.
	 * @param array<string, mixed> $options Plugin options.
	 * @return void
	 */
	public function update_log_by_provider_message_id( $provider_message_id, $status, $details, $options ) {
		if ( '' === $provider_message_id || empty( $options['mail_retention'] ) ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		$log_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE provider_message_id = %s ORDER BY id DESC LIMIT 1", $provider_message_id )
		);

		if ( $log_id > 0 ) {
			$this->update_mail_log( $log_id, $status, $details, $options );
		}
	}

	/**
	 * Resolve current log status by id.
	 *
	 * @param int $log_id Log id.
	 * @return string
	 */
	private function get_log_status( $log_id ) {
		global $wpdb;
		$table  = self::table_name();
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $log_id ) );
		return sanitize_key( (string) $status );
	}

	/**
	 * Determine if status transition is allowed.
	 *
	 * @param string               $from Current status.
	 * @param string               $to Target status.
	 * @param array<string, mixed> $context Context.
	 * @return bool
	 */
	private function is_transition_allowed( $from, $to, $context = array() ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );

		if ( '' === $from || '' === $to || $from === $to ) {
			return true;
		}

		$terminal = array( 'failed', 'bypassed', 'delivered', 'dead_letter' );
		if ( in_array( $from, $terminal, true ) ) {
			$allowed = false;
		} else {
			$from_rank = isset( self::$status_rank[ $from ] ) ? (int) self::$status_rank[ $from ] : 0;
			$to_rank   = isset( self::$status_rank[ $to ] ) ? (int) self::$status_rank[ $to ] : 0;
			$allowed   = $to_rank >= $from_rank;
		}

		return (bool) apply_filters( 'atum_mailer_status_transition_allowed', $allowed, $from, $to, $context );
	}

	/**
	 * Query logs.
	 *
	 * @param string $status Status.
	 * @param string $search Search.
	 * @param int    $limit Limit.
	 * @param int    $offset Offset.
	 * @return array<string, mixed>
	 */
	public function query_logs( $status, $search = '', $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = self::table_name();
		$filters = is_array( $status )
			? $this->normalize_log_filters( $status )
			: $this->normalize_log_filters(
				array(
					'status' => $status,
					's'      => $search,
				)
			);
		$parts   = $this->build_log_filter_where( $filters );
		$where_sql = $parts['where_sql'];
		$params    = $parts['params'];

		$total_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$total_query = $wpdb->prepare( $total_query, $params );
		}
		$total = (int) $wpdb->get_var( $total_query );

		$rows_query  = "SELECT id, created_at, mail_to, subject, status, http_status, error_message, delivery_mode, attempt_count, provider_message_id, last_error_code, webhook_event_type FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $limit, $offset ) );
		$rows_query  = $wpdb->prepare( $rows_query, $rows_params );
		$rows        = $wpdb->get_results( $rows_query );

		return array(
			'total' => $total,
			'logs'  => is_array( $rows ) ? $rows : array(),
		);
	}

	/**
	 * Query logs for export.
	 *
	 * @param string $status Status.
	 * @param string $search Search.
	 * @return array<int, object>
	 */
	public function query_logs_for_export( $status, $search = '' ) {
		global $wpdb;

		$table  = self::table_name();
		$filters = is_array( $status )
			? $this->normalize_log_filters( $status )
			: $this->normalize_log_filters(
				array(
					'status' => $status,
					's'      => $search,
				)
			);
		$parts   = $this->build_log_filter_where( $filters );
		$where_sql = $parts['where_sql'];
		$params    = $parts['params'];
		$query     = "SELECT id, created_at, mail_to, subject, status, http_status, provider_message_id, error_message, delivery_mode, attempt_count, last_error_code FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, $params );
		}

		$rows = $wpdb->get_results( $query );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Query logs for export by explicit IDs.
	 *
	 * @param array<int, int> $ids Log IDs.
	 * @return array<int, object>
	 */
	public function query_logs_for_export_by_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT id, created_at, mail_to, subject, status, http_status, provider_message_id, error_message, delivery_mode, attempt_count, last_error_code
			FROM {$table}
			WHERE id IN ({$placeholders})
			ORDER BY created_at DESC",
			$ids
		);

		$rows = $wpdb->get_results( $query );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Query request payloads by explicit IDs.
	 *
	 * @param array<int, int> $ids Log IDs.
	 * @return array<int, object>
	 */
	public function query_payloads_by_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT id, request_payload
			FROM {$table}
			WHERE id IN ({$placeholders})
			ORDER BY id ASC",
			$ids
		);

		$rows = $wpdb->get_results( $query );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Purge logs by filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function purge_logs_by_filters( $filters ) {
		global $wpdb;

		$table   = self::table_name();
		$filters = $this->normalize_log_filters( $filters );
		$parts   = $this->build_log_filter_where( $filters );
		$query   = "DELETE FROM {$table} WHERE {$parts['where_sql']}";
		if ( ! empty( $parts['params'] ) ) {
			$query = $wpdb->prepare( $query, $parts['params'] );
		}

		$deleted = $wpdb->query( $query );
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Delete logs by explicit IDs.
	 *
	 * @param array<int, int> $ids Log IDs.
	 * @return int Number of deleted rows.
	 */
	public function delete_logs_by_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids );
		$deleted      = $wpdb->query( $query );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}


	/**
	 * Get aggregate dashboard stats.
	 *
	 * @param int      $queue_backlog Queue backlog count.
	 * @param int|null $queue_oldest_created_ts Oldest queue created timestamp.
	 * @return array<string, mixed>
	 */
	public function get_log_stats( $queue_backlog = 0, $queue_oldest_created_ts = null ) {
		global $wpdb;

		$table = self::table_name();

		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$sent    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'sent' ) );
		$failed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'failed' ) );
		$queued   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'queued' ) );
		$retrying = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'retrying' ) );
		$dead_letter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'dead_letter' ) );

		$since_24h       = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$last_24h        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since_24h ) );
		$failures_24h    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = %s", $since_24h, 'failed' ) );
		$failure_rate_24h = $last_24h > 0 ? round( ( $failures_24h / $last_24h ) * 100, 2 ) : 0;

		$last_sent_raw = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 1", 'sent' ) );
		$last_sent     = $last_sent_raw ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sent_raw ) : '';

		$last_api_outage_raw = $this->settings->get_last_api_outage();
		$last_api_outage     = '' !== $last_api_outage_raw ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_api_outage_raw ) : '';
		$queue_oldest_age_seconds = null;
		if ( null !== $queue_oldest_created_ts && (int) $queue_oldest_created_ts > 0 ) {
			$queue_oldest_age_seconds = max( 0, time() - (int) $queue_oldest_created_ts );
		}

		$previous_since  = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );
		$previous_until  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$prev_total      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s", $previous_since, $previous_until ) );
		$prev_failures   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s AND status IN (%s, %s)", $previous_since, $previous_until, 'failed', 'dead_letter' ) );
		$prev_rate       = $prev_total > 0 ? round( ( $prev_failures / $prev_total ) * 100, 2 ) : 0;
		$failure_trend   = round( $failure_rate_24h - $prev_rate, 2 );
		$retry_breakdown = $this->get_retry_error_breakdown();

		return array(
			'total'            => $total,
			'sent'             => $sent,
			'failed'           => $failed,
			'dead_letter'      => $dead_letter,
			'queued'           => $queued,
			'retrying'         => $retrying,
			'last_24h'         => $last_24h,
			'failures_24h'     => $failures_24h,
			'failure_rate_24h' => $failure_rate_24h,
			'failure_rate_prev_24h' => $prev_rate,
			'failure_rate_trend'    => $failure_trend,
			'last_sent'        => $last_sent,
			'queue_backlog'    => (int) $queue_backlog,
			'queue_oldest_age_seconds' => $queue_oldest_age_seconds,
			'retry_error_breakdown' => $retry_breakdown,
			'last_api_outage'  => $last_api_outage,
		);
	}

	/**
	 * Count logs by status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public function count_by_status( $status ) {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", sanitize_key( $status ) ) );
	}

	/**
	 * Retry/failure error-code breakdown for observability.
	 *
	 * @param int $window_seconds Window seconds.
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_retry_error_breakdown( $window_seconds = DAY_IN_SECONDS, $limit = 5 ) {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 60, (int) $window_seconds ) );
		$limit = max( 1, (int) $limit );

		$query = $wpdb->prepare(
			"SELECT last_error_code, COUNT(*) AS total
			FROM {$table}
			WHERE created_at >= %s
			AND last_error_code <> %s
			AND status IN (%s, %s, %s)
			GROUP BY last_error_code
			ORDER BY total DESC
			LIMIT %d",
			$since,
			'',
			'retrying',
			'failed',
			'dead_letter',
			$limit
		);

		$rows = $wpdb->get_results( $query );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'code'  => sanitize_key( (string) ( $row->last_error_code ?? '' ) ),
				'total' => absint( $row->total ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Fetch failed/dead-letter logs that can be retried from saved payload.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public function query_retryable_failed_logs( $limit = 20 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, (int) $limit );
		$query = $wpdb->prepare(
			"SELECT id, request_payload, status
			FROM {$table}
			WHERE status IN (%s, %s)
			AND request_payload IS NOT NULL
			AND request_payload <> %s
			ORDER BY id DESC
			LIMIT %d",
			'failed',
			'dead_letter',
			'',
			$limit
		);

		$rows = $wpdb->get_results( $query );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Purge logs older than N days.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function purge_logs_older_than( $days ) {
		global $wpdb;
		$table     = self::table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$query     = $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold );
		$deleted   = $wpdb->query( $query );
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Format recipient list.
	 *
	 * @param string|null $mail_to Encoded recipients.
	 * @return string
	 */
	public function format_recipient_list( $mail_to ) {
		if ( empty( $mail_to ) ) {
			return '';
		}

		$decoded = json_decode( (string) $mail_to, true );
		if ( ! is_array( $decoded ) ) {
			return (string) $mail_to;
		}

		$decoded = array_slice( array_map( 'sanitize_email', $decoded ), 0, 3 );
		return implode( ', ', array_filter( $decoded ) );
	}

	/**
	 * Normalize addresses for logging.
	 *
	 * @param mixed $raw Raw address list.
	 * @return array<int, string>
	 */
	private function normalize_addresses( $raw ) {
		$list = is_array( $raw ) ? $raw : explode( ',', str_replace( ';', ',', (string) $raw ) );
		$out  = array();

		foreach ( $list as $entry ) {
			$email = sanitize_email( trim( (string) $entry ) );
			if ( '' !== $email ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize logs filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, string>
	 */
	private function normalize_log_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();

		$out = array(
			'status'              => sanitize_key( (string) ( $filters['status'] ?? 'all' ) ),
			's'                   => sanitize_text_field( (string) ( $filters['s'] ?? $filters['search'] ?? '' ) ),
			'date_from'           => sanitize_text_field( (string) ( $filters['date_from'] ?? '' ) ),
			'date_to'             => sanitize_text_field( (string) ( $filters['date_to'] ?? '' ) ),
			'delivery_mode'       => sanitize_key( (string) ( $filters['delivery_mode'] ?? 'all' ) ),
			'retry_state'         => sanitize_key( (string) ( $filters['retry_state'] ?? 'all' ) ),
			'provider_message_id' => sanitize_text_field( (string) ( $filters['provider_message_id'] ?? '' ) ),
		);

		$allowed_statuses = array( 'all', 'processing', 'sent', 'failed', 'bypassed', 'queued', 'retrying', 'delivered', 'dead_letter' );
		if ( ! in_array( $out['status'], $allowed_statuses, true ) ) {
			$out['status'] = 'all';
		}
		if ( ! in_array( $out['delivery_mode'], array( 'all', 'immediate', 'queue' ), true ) ) {
			$out['delivery_mode'] = 'all';
		}
		if ( ! in_array( $out['retry_state'], array( 'all', 'retrying', 'retried', 'terminal' ), true ) ) {
			$out['retry_state'] = 'all';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $out['date_from'] ) ) {
			$out['date_from'] = '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $out['date_to'] ) ) {
			$out['date_to'] = '';
		}

		return $out;
	}

	/**
	 * Build where clause and params for log filters.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array{where_sql: string, params: array<int, mixed>}
	 */
	private function build_log_filter_where( $filters ) {
		global $wpdb;

		$filters = $this->normalize_log_filters( $filters );
		$where   = array( '1=1' );
		$params  = array();

		if ( 'all' !== $filters['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ( '' !== $filters['s'] ) {
			$like     = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$where[]  = '(subject LIKE %s OR mail_to LIKE %s OR error_message LIKE %s OR provider_message_id LIKE %s OR last_error_code LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $filters['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		if ( 'all' !== $filters['delivery_mode'] ) {
			$where[]  = 'delivery_mode = %s';
			$params[] = $filters['delivery_mode'];
		}

		if ( '' !== $filters['provider_message_id'] ) {
			$where[]  = 'provider_message_id LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['provider_message_id'] ) . '%';
		}

		if ( 'retrying' === $filters['retry_state'] ) {
			$where[]  = 'status = %s';
			$params[] = 'retrying';
		} elseif ( 'retried' === $filters['retry_state'] ) {
			$where[]  = 'attempt_count > %d';
			$params[] = 1;
		} elseif ( 'terminal' === $filters['retry_state'] ) {
			$where[]  = 'status IN (%s, %s)';
			$params[] = 'failed';
			$params[] = 'dead_letter';
		}

		return array(
			'where_sql' => implode( ' AND ', $where ),
			'params'    => $params,
		);
	}
}
