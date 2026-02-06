<?php
/**
 * WP-CLI command integration.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_CLI_Command {
	/**
	 * Settings repository.
	 *
	 * @var Atum_Mailer_Settings_Repository
	 */
	private $settings;

	/**
	 * Log repository.
	 *
	 * @var Atum_Mailer_Log_Repository
	 */
	private $logs;

	/**
	 * Mail interceptor.
	 *
	 * @var Atum_Mailer_Mail_Interceptor
	 */
	private $interceptor;

	/**
	 * Queue repository.
	 *
	 * @var Atum_Mailer_Queue_Repository_Interface
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Atum_Mailer_Settings_Repository $settings Settings.
	 * @param Atum_Mailer_Log_Repository      $logs Logs.
	 * @param Atum_Mailer_Mail_Interceptor    $interceptor Interceptor.
	 * @param Atum_Mailer_Queue_Repository_Interface $queue Queue.
	 */
	public function __construct( Atum_Mailer_Settings_Repository $settings, Atum_Mailer_Log_Repository $logs, Atum_Mailer_Mail_Interceptor $interceptor, Atum_Mailer_Queue_Repository_Interface $queue ) {
		$this->settings    = $settings;
		$this->logs        = $logs;
		$this->interceptor = $interceptor;
		$this->queue       = $queue;
	}

	/**
	 * Register command in WP-CLI runtime.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'atum-mailer queue', $this );
		\WP_CLI::add_command( 'atum-mailer logs export', array( $this, 'logs_export' ) );
		\WP_CLI::add_command( 'atum-mailer health check', array( $this, 'health_check' ) );
	}

	/**
	 * Show queue and delivery status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table|json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$backlog = $this->queue->countBacklog();
		$oldest  = $this->queue->oldestCreatedTimestamp();
		$dead    = $this->logs->count_by_status( 'dead_letter' );
		$failed  = $this->logs->count_by_status( 'failed' );

		$data = array(
			'queue_backlog'      => $backlog,
			'oldest_queue_age_s' => null === $oldest ? 0 : max( 0, time() - $oldest ),
			'dead_letter_count'  => $dead,
			'failed_count'       => $failed,
		);

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', array( $data ), array_keys( $data ) );
	}

	/**
	 * Run queue worker once.
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$before = $this->queue->countBacklog();
		$this->interceptor->process_queue();
		$after = $this->queue->countBacklog();

		\WP_CLI::success( sprintf( 'Queue run complete. Backlog before: %d, after: %d.', $before, $after ) );
	}

	/**
	 * Retry failed/dead-letter logs that still contain request payloads.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max logs to retry.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--mode=<mode>]
	 * : Delivery mode to use for retried logs (queue|immediate)
	 * ---
	 * default: queue
	 * ---
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function retry_failed( $args, $assoc_args ) {
		unset( $args );
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$mode  = isset( $assoc_args['mode'] ) ? sanitize_key( (string) $assoc_args['mode'] ) : 'queue';
		if ( ! in_array( $mode, array( 'queue', 'immediate' ), true ) ) {
			$mode = 'queue';
		}

		$rows      = $this->logs->query_retryable_failed_logs( $limit );
		$retried   = 0;
		$skipped   = 0;
		$failures  = 0;

		foreach ( $rows as $row ) {
			$payload = json_decode( (string) ( $row->request_payload ?? '' ), true );
			if ( ! is_array( $payload ) || empty( $payload ) ) {
				$skipped++;
				continue;
			}

			$result = $this->interceptor->resend_saved_payload( $payload, $mode );
			if ( is_wp_error( $result ) ) {
				$failures++;
				continue;
			}

			$retried++;
		}

		\WP_CLI::success( sprintf( 'Retry finished. Retried: %d, skipped: %d, failed: %d.', $retried, $skipped, $failures ) );
	}

	/**
	 * Purge queue jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<seconds>]
	 * : Purge jobs older than this age in seconds. Omit to purge all.
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function purge( $args, $assoc_args ) {
		unset( $args );
		$older_than = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 0;
		$deleted    = $this->queue->purge( $older_than );

		\WP_CLI::success( sprintf( 'Purged %d queue job(s).', (int) $deleted ) );
	}

	/**
	 * Export logs through CLI.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Log status filter (all|sent|failed|queued|retrying|dead_letter|etc).
	 * ---
	 * default: all
	 * ---
	 *
	 * [--search=<search>]
	 * : Search term for subject/recipient/errors.
	 *
	 * [--limit=<limit>]
	 * : Max rows to emit.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table|json|csv).
	 * ---
	 * default: table
	 * ---
	 *
	 * [--output=<path>]
	 * : File path for CSV output. Required when --format=csv.
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function logs_export( $args, $assoc_args ) {
		unset( $args );
		$status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : 'all';
		$search = isset( $assoc_args['search'] ) ? sanitize_text_field( (string) $assoc_args['search'] ) : '';
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 200;
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';

		$rows = $this->logs->query_logs_for_export( $status, $search );
		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'id'                 => (int) ( $row->id ?? 0 ),
				'created_at'         => (string) ( $row->created_at ?? '' ),
				'to'                 => $this->logs->format_recipient_list( (string) ( $row->mail_to ?? '' ) ),
				'subject'            => (string) ( $row->subject ?? '' ),
				'status'             => (string) ( $row->status ?? '' ),
				'http_status'        => (string) ( $row->http_status ?? '' ),
				'provider_message_id'=> (string) ( $row->provider_message_id ?? '' ),
				'delivery_mode'      => (string) ( $row->delivery_mode ?? '' ),
				'attempt_count'      => (int) ( $row->attempt_count ?? 0 ),
				'last_error_code'    => (string) ( $row->last_error_code ?? '' ),
				'error_message'      => (string) ( $row->error_message ?? '' ),
			);
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data ) );
			return;
		}

		if ( 'csv' === $format ) {
			$path = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '';
			if ( '' === $path ) {
				\WP_CLI::error( 'CSV export requires --output=<path>.' );
				return;
			}

			$dir = dirname( $path );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				\WP_CLI::error( sprintf( 'Unable to create output directory: %s', $dir ) );
				return;
			}

			$fh = fopen( $path, 'w' );
			if ( false === $fh ) {
				\WP_CLI::error( sprintf( 'Unable to open output file: %s', $path ) );
				return;
			}

			fputcsv( $fh, array( 'id', 'created_at', 'to', 'subject', 'status', 'http_status', 'provider_message_id', 'delivery_mode', 'attempt_count', 'last_error_code', 'error_message' ) );
			foreach ( $data as $item ) {
				fputcsv( $fh, $item );
			}
			fclose( $fh );

			\WP_CLI::success( sprintf( 'Exported %d log rows to %s', count( $data ), $path ) );
			return;
		}

		if ( empty( $data ) ) {
			\WP_CLI::line( 'No matching logs.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $data, array_keys( $data[0] ) );
	}

	/**
	 * Health check summary for automation/support.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table|json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>   $args Positional args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function health_check( $args, $assoc_args ) {
		unset( $args );
		$backlog          = max( 0, (int) $this->queue->countBacklog() );
		$queue_oldest     = $this->queue->oldestCreatedTimestamp();
		$queue_next_due   = $this->queue->nextDueTimestamp();
		$cleanup_next     = wp_next_scheduled( Atum_Mailer_Bootstrap::CLEANUP_CRON_HOOK );
		$processor_next   = wp_next_scheduled( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK );
		$log_db_version   = (string) get_option( Atum_Mailer_Log_Repository::DB_VERSION_OPTION, '' );
		$queue_db_version = (string) get_option( Atum_Mailer_Db_Queue_Repository::DB_VERSION_OPTION, '' );

		$data = array(
			'queue_backend'              => $this->queue instanceof Atum_Mailer_Db_Queue_Repository ? 'db' : 'legacy_option',
			'queue_backlog'              => $backlog,
			'queue_oldest_age_seconds'   => null === $queue_oldest ? 0 : max( 0, time() - (int) $queue_oldest ),
			'queue_next_due_utc'         => null === $queue_next_due ? '' : gmdate( 'Y-m-d H:i:s', (int) $queue_next_due ),
			'queue_processor_next_utc'   => false === $processor_next ? '' : gmdate( 'Y-m-d H:i:s', (int) $processor_next ),
			'retention_cleanup_next_utc' => false === $cleanup_next ? '' : gmdate( 'Y-m-d H:i:s', (int) $cleanup_next ),
			'logs_schema_ok'             => Atum_Mailer_Log_Repository::DB_VERSION === $log_db_version ? 1 : 0,
			'queue_schema_ok'            => Atum_Mailer_Db_Queue_Repository::DB_VERSION === $queue_db_version ? 1 : 0,
			'dead_letter_count'          => $this->logs->count_by_status( 'dead_letter' ),
			'failed_count'               => $this->logs->count_by_status( 'failed' ),
		);

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', array( $data ), array_keys( $data ) );
	}
}
