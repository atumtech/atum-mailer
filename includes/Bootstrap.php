<?php
/**
 * Plugin bootstrap.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Bootstrap {
	const CLEANUP_CRON_HOOK = 'atum_mailer_daily_cleanup';
	const ALERT_CRON_HOOK   = 'atum_mailer_threshold_alerts';

	/**
	 * Settings repository.
	 *
	 * @var Atum_Mailer_Settings_Repository
	 */
	private $settings;

	/**
	 * Logs repository.
	 *
	 * @var Atum_Mailer_Log_Repository
	 */
	private $logs;

	/**
	 * Postmark client.
	 *
	 * @var Atum_Mailer_Postmark_Client
	 */
	private $client;

	/**
	 * Mail interceptor.
	 *
	 * @var Atum_Mailer_Mail_Interceptor
	 */
	private $mail_interceptor;

	/**
	 * Admin controller.
	 *
	 * @var Atum_Mailer_Admin_Controller
	 */
	private $admin;

	/**
	 * Queue repository.
	 *
	 * @var Atum_Mailer_Queue_Repository_Interface
	 */
	private $queue;

	/**
	 * GitHub updater service.
	 *
	 * @var Atum_Mailer_GitHub_Updater
	 */
	private $updater;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings         = new Atum_Mailer_Settings_Repository();
		$this->logs             = new Atum_Mailer_Log_Repository( $this->settings );
		$this->queue            = new Atum_Mailer_Db_Queue_Repository( $this->settings );
		$this->client           = new Atum_Mailer_Postmark_Client();
		$this->mail_interceptor = new Atum_Mailer_Mail_Interceptor( $this->settings, $this->logs, $this->client, $this->queue );
		$this->admin            = new Atum_Mailer_Admin_Controller( $this->settings, $this->logs, $this->client, $this->mail_interceptor, $this->queue );
		$plugin_file            = defined( 'ATUM_MAILER_FILE' ) ? (string) ATUM_MAILER_FILE : __FILE__;
		$plugin_version         = defined( 'ATUM_MAILER_VERSION' ) ? (string) ATUM_MAILER_VERSION : '0.0.0';
		$this->updater          = new Atum_Mailer_GitHub_Updater( $plugin_file, $plugin_version );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_upgrade_database' ), 1 );
		add_action( 'init', array( $this, 'ensure_runtime_schedules' ) );
		$this->register_cli_command();
		$this->updater->register_hooks();
		add_action( 'admin_menu', array( $this->admin, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this->admin, 'render_configuration_notice' ) );

		add_action( 'admin_post_atum_mailer_send_test', array( $this->admin, 'handle_send_test_email' ) );
		add_action( 'admin_post_atum_mailer_process_queue_now', array( $this->admin, 'handle_process_queue_now' ) );
		add_action( 'admin_post_atum_mailer_purge_logs', array( $this->admin, 'handle_purge_logs' ) );
		add_action( 'admin_post_atum_mailer_export_logs', array( $this->admin, 'handle_export_logs' ) );
		add_action( 'admin_post_atum_mailer_logs_bulk', array( $this->admin, 'handle_logs_bulk_action' ) );
		add_action( 'admin_post_atum_mailer_resend_log', array( $this->admin, 'handle_resend_log' ) );
		add_action( 'admin_post_atum_mailer_connect_token', array( $this->admin, 'handle_connect_token' ) );
		add_action( 'admin_post_atum_mailer_disconnect_token', array( $this->admin, 'handle_disconnect_token' ) );
		add_action( 'admin_post_update', array( $this->admin, 'handle_misrouted_update_action' ) );

		add_action( 'wp_ajax_atum_mailer_get_log_details', array( $this->admin, 'handle_get_log_details' ) );
		add_action( 'wp_ajax_atum_mailer_reveal_token', array( $this->admin, 'handle_reveal_token' ) );

		add_filter( 'pre_wp_mail', array( $this->mail_interceptor, 'maybe_send_with_postmark' ), 10, 2 );
		$plugin_file = defined( 'ATUM_MAILER_FILE' ) ? (string) ATUM_MAILER_FILE : __FILE__;
		add_filter( 'plugin_action_links_' . plugin_basename( $plugin_file ), array( $this->admin, 'add_action_links' ) );

		add_action( self::CLEANUP_CRON_HOOK, array( $this, 'handle_daily_cleanup' ) );
		add_action( self::ALERT_CRON_HOOK, array( $this, 'handle_threshold_alerts' ) );
		add_action( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK, array( $this->mail_interceptor, 'process_queue' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		$plugin_file = defined( 'ATUM_MAILER_FILE' ) ? (string) ATUM_MAILER_FILE : __FILE__;
		load_plugin_textdomain( 'atum-mailer', false, dirname( plugin_basename( $plugin_file ) ) . '/languages' );
	}

	/**
	 * DB upgrade proxy.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database() {
		$this->logs->maybe_upgrade_database();
		$this->queue->maybe_upgrade_database();
		$this->queue->maybe_migrate_legacy_queue();
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$settings = new Atum_Mailer_Settings_Repository();
		$logs     = new Atum_Mailer_Log_Repository( $settings );
		$queue    = new Atum_Mailer_Db_Queue_Repository( $settings );

		$settings->maybe_migrate_legacy_options();
		$logs->maybe_upgrade_database( true );
		$queue->maybe_upgrade_database( true );
		$queue->maybe_migrate_legacy_queue();
		self::ensure_cron_schedules();
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_CRON_HOOK );
		wp_clear_scheduled_hook( self::ALERT_CRON_HOOK );
		wp_clear_scheduled_hook( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK );
	}

	/**
	 * Ensure cleanup cron exists.
	 *
	 * @return void
	 */
	public static function ensure_cron_schedules() {
		if ( false === wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_CRON_HOOK );
		}
		if ( false === wp_next_scheduled( self::ALERT_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::ALERT_CRON_HOOK );
		}
	}

	/**
	 * Ensure schedules exist during runtime for upgraded sites.
	 *
	 * @return void
	 */
	public function ensure_runtime_schedules() {
		self::ensure_cron_schedules();
	}

	/**
	 * Daily cleanup cron callback.
	 *
	 * @return void
	 */
	public function handle_daily_cleanup() {
		$options = $this->settings->get_options();
		if ( empty( $options['mail_retention'] ) ) {
			return;
		}

		$days = max( 1, (int) $options['retention_days'] );
		$this->logs->purge_logs_older_than( $days );
		update_option( Atum_Mailer_Settings_Repository::LAST_CLEANUP_OPTION, time() );
	}

	/**
	 * Evaluate queue/failure thresholds and emit alert hooks.
	 *
	 * @return void
	 */
	public function handle_threshold_alerts() {
		$options       = $this->settings->get_options();
		$queue_backlog = max( 0, (int) $this->queue->countBacklog() );
		$queue_oldest  = $this->queue->oldestCreatedTimestamp();
		$stats         = $this->logs->get_log_stats( $queue_backlog, $queue_oldest );
		$cooldown      = max( 60, (int) apply_filters( 'atum_mailer_alert_cooldown_seconds', HOUR_IN_SECONDS, $stats, $options ) );

		$failure_threshold = (float) apply_filters( 'atum_mailer_alert_failure_rate_threshold', 10.0, $stats, $options );
		if ( $failure_threshold > 0 && (float) $stats['failure_rate_24h'] >= $failure_threshold ) {
			$context = array(
				'type'              => 'failure_rate',
				'threshold'         => $failure_threshold,
				'failure_rate_24h'  => (float) $stats['failure_rate_24h'],
				'failures_24h'      => (int) $stats['failures_24h'],
				'total_24h'         => (int) $stats['last_24h'],
				'queue_backlog'     => $queue_backlog,
				'evaluated_at_utc'  => gmdate( 'Y-m-d H:i:s' ),
			);

			if ( $this->should_emit_alert( Atum_Mailer_Settings_Repository::LAST_ALERT_FAILURE_OPTION, $cooldown ) ) {
				do_action( 'atum_mailer_alert_failure_rate_threshold', $context );
				do_action( 'atum_mailer_alert_threshold_breach', 'failure_rate', $context );
			}
		}

		$backlog_threshold = max( 1, (int) apply_filters( 'atum_mailer_alert_queue_backlog_threshold', 100, $stats, $options ) );
		if ( $queue_backlog >= $backlog_threshold ) {
			$context = array(
				'type'              => 'queue_backlog',
				'threshold'         => $backlog_threshold,
				'queue_backlog'     => $queue_backlog,
				'queue_oldest_age_s'=> null === $queue_oldest ? 0 : max( 0, time() - (int) $queue_oldest ),
				'evaluated_at_utc'  => gmdate( 'Y-m-d H:i:s' ),
			);

			if ( $this->should_emit_alert( Atum_Mailer_Settings_Repository::LAST_ALERT_BACKLOG_OPTION, $cooldown ) ) {
				do_action( 'atum_mailer_alert_queue_backlog_threshold', $context );
				do_action( 'atum_mailer_alert_threshold_breach', 'queue_backlog', $context );
			}
		}
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'atum-mailer/v1',
			'/postmark/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_receive_webhook' ),
				'callback'            => array( $this, 'handle_webhook_event' ),
			)
		);
	}

	/**
	 * Webhook permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_receive_webhook( WP_REST_Request $request ) {
		$options = $this->settings->get_options();
		$secret  = trim( (string) ( $options['postmark_webhook_secret'] ?? '' ) );
		if ( '' === $secret ) {
			return new WP_Error( 'atum_mailer_webhook_disabled', __( 'Webhook secret is not configured.', 'atum-mailer' ), array( 'status' => 403 ) );
		}

		$provided = (string) $request->get_header( 'x-atum-webhook-secret' );
		if ( '' === $provided || ! hash_equals( $secret, $provided ) ) {
			return new WP_Error( 'atum_mailer_webhook_auth_failed', __( 'Invalid webhook secret.', 'atum-mailer' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Webhook callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook_event( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Invalid JSON payload.', 'atum-mailer' ) ), 400 );
		}

		$event_cache_key = $this->webhook_event_cache_key( $payload );
		if ( false !== get_transient( $event_cache_key ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		set_transient( $event_cache_key, 1, DAY_IN_SECONDS );

		$record_type = sanitize_key( (string) ( $payload['RecordType'] ?? 'unknown' ) );
		$message_id  = sanitize_text_field( (string) ( $payload['MessageID'] ?? $payload['MessageId'] ?? '' ) );
		$status_map  = array(
			'delivery'      => 'delivered',
			'bounce'        => 'failed',
			'spamcomplaint' => 'failed',
			'open'          => 'delivered',
			'click'         => 'delivered',
		);
		$status      = isset( $status_map[ $record_type ] ) ? $status_map[ $record_type ] : 'sent';
		$options     = $this->settings->get_options();

		if ( '' !== $message_id ) {
			$this->logs->update_log_by_provider_message_id(
				$message_id,
				$status,
				array(
					'webhook_event_type' => $record_type,
					'response_body'      => wp_json_encode( $payload ),
					'error_message'      => in_array( $record_type, array( 'bounce', 'spamcomplaint' ), true )
						? sprintf( __( 'Postmark webhook event: %s', 'atum-mailer' ), $record_type )
						: '',
				),
				$options
			);
		}

		do_action( 'atum_mailer_webhook_event', $payload, $status, $message_id );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Register WP-CLI command when available.
	 *
	 * @return void
	 */
	private function register_cli_command() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		$command = new Atum_Mailer_CLI_Command( $this->settings, $this->logs, $this->mail_interceptor, $this->queue );
		$command->register();
	}

	/**
	 * Build deterministic replay-protection cache key for webhook event.
	 *
	 * @param array<string, mixed> $payload Webhook payload.
	 * @return string
	 */
	private function webhook_event_cache_key( $payload ) {
		$event_id    = sanitize_text_field( (string) ( $payload['ID'] ?? $payload['Id'] ?? '' ) );
		$record_type = sanitize_key( (string) ( $payload['RecordType'] ?? 'unknown' ) );
		$message_id  = sanitize_text_field( (string) ( $payload['MessageID'] ?? $payload['MessageId'] ?? '' ) );
		$occurred_at = sanitize_text_field( (string) ( $payload['ReceivedAt'] ?? $payload['OccurredAt'] ?? '' ) );

		$fingerprint = '' !== $event_id
			? $event_id
			: $record_type . '|' . $message_id . '|' . $occurred_at . '|' . wp_json_encode( $payload );

		return 'atum_mailer_webhook_evt_' . md5( $fingerprint );
	}

	/**
	 * Cooldown helper for alert emissions.
	 *
	 * @param string $option_key Last-fired option key.
	 * @param int    $cooldown_seconds Cooldown.
	 * @return bool
	 */
	private function should_emit_alert( $option_key, $cooldown_seconds ) {
		$now  = time();
		$last = (int) get_option( (string) $option_key, 0 );
		if ( $last > 0 && ( $now - $last ) < max( 1, (int) $cooldown_seconds ) ) {
			return false;
		}

		update_option( (string) $option_key, $now );
		return true;
	}
}
