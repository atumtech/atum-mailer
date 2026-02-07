<?php
/**
 * Admin controller.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_Admin_Controller {
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
	 * Queue repository.
	 *
	 * @var Atum_Mailer_Queue_Repository_Interface
	 */
	private $queue;

	/**
	 * Hook suffix for plugin page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Atum_Mailer_Settings_Repository $settings Settings.
	 * @param Atum_Mailer_Log_Repository      $logs Logs.
	 * @param Atum_Mailer_Postmark_Client     $client Client.
	 * @param Atum_Mailer_Mail_Interceptor    $mail_interceptor Mail interceptor.
	 * @param Atum_Mailer_Queue_Repository_Interface|null $queue Queue repository.
	 */
	public function __construct( Atum_Mailer_Settings_Repository $settings, Atum_Mailer_Log_Repository $logs, Atum_Mailer_Postmark_Client $client, Atum_Mailer_Mail_Interceptor $mail_interceptor, $queue = null ) {
		$this->settings         = $settings;
		$this->logs             = $logs;
		$this->client           = $client;
		$this->mail_interceptor = $mail_interceptor;
		$this->queue            = $queue instanceof Atum_Mailer_Queue_Repository_Interface ? $queue : new Atum_Mailer_Option_Queue_Repository( $settings );
	}

	/**
	 * Add link to settings in plugin list.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Atum_Mailer::PAGE_SLUG . '&tab=settings' ) ),
			esc_html__( 'Settings', 'atum-mailer' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add top-level admin page.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		$this->page_hook = add_menu_page(
			__( 'atum.mailer', 'atum-mailer' ),
			__( 'atum.mailer', 'atum-mailer' ),
			'manage_options',
			Atum_Mailer::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-email-alt2',
			58
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'atum_mailer_settings',
			Atum_Mailer_Settings_Repository::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize_options' ),
				'default'           => $this->settings->default_options(),
			)
		);

		add_settings_section(
			'atum_mailer_delivery',
			__( 'Delivery', 'atum-mailer' ),
			array( $this, 'render_delivery_section_help' ),
			'atum-mailer-settings'
		);

		add_settings_field( 'enabled', __( 'Enable Postmark Delivery', 'atum-mailer' ), array( $this, 'render_enabled_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'postmark_server_token', __( 'Postmark Server Token', 'atum-mailer' ), array( $this, 'render_server_token_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'message_stream', __( 'Message Stream', 'atum-mailer' ), array( $this, 'render_message_stream_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'from_email', __( 'Default From Email', 'atum-mailer' ), array( $this, 'render_from_email_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'from_name', __( 'Default From Name', 'atum-mailer' ), array( $this, 'render_from_name_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'force_from', __( 'Force Default From Address', 'atum-mailer' ), array( $this, 'render_force_from_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'track_opens', __( 'Track Opens', 'atum-mailer' ), array( $this, 'render_track_opens_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'track_links', __( 'Track Links', 'atum-mailer' ), array( $this, 'render_track_links_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );
		add_settings_field( 'debug_logging', __( 'Enable Debug Logging', 'atum-mailer' ), array( $this, 'render_debug_logging_field' ), 'atum-mailer-settings', 'atum_mailer_delivery' );

		add_settings_section(
			'atum_mailer_security',
			__( 'Security & Privacy', 'atum-mailer' ),
			array( $this, 'render_security_section_help' ),
			'atum-mailer-settings'
		);
		add_settings_field( 'allow_token_reveal', __( 'Allow API Key Reveal', 'atum-mailer' ), array( $this, 'render_allow_token_reveal_field' ), 'atum-mailer-settings', 'atum_mailer_security' );
		add_settings_field( 'log_detail_mode', __( 'Log Detail Mode', 'atum-mailer' ), array( $this, 'render_log_detail_mode_field' ), 'atum-mailer-settings', 'atum_mailer_security' );

		add_settings_section(
			'atum_mailer_reliability',
			__( 'Reliability', 'atum-mailer' ),
			array( $this, 'render_reliability_section_help' ),
			'atum-mailer-settings'
		);
		add_settings_field( 'delivery_mode', __( 'Delivery Mode', 'atum-mailer' ), array( $this, 'render_delivery_mode_field' ), 'atum-mailer-settings', 'atum_mailer_reliability' );
		add_settings_field( 'fallback_to_wp_mail', __( 'Fallback to Native wp_mail()', 'atum-mailer' ), array( $this, 'render_fallback_to_wp_mail_field' ), 'atum-mailer-settings', 'atum_mailer_reliability' );
		add_settings_field( 'queue_max_attempts', __( 'Queue Max Attempts', 'atum-mailer' ), array( $this, 'render_queue_max_attempts_field' ), 'atum-mailer-settings', 'atum_mailer_reliability' );
		add_settings_field( 'queue_retry_base_delay', __( 'Queue Base Delay (s)', 'atum-mailer' ), array( $this, 'render_queue_retry_base_delay_field' ), 'atum-mailer-settings', 'atum_mailer_reliability' );
		add_settings_field( 'queue_retry_max_delay', __( 'Queue Max Delay (s)', 'atum-mailer' ), array( $this, 'render_queue_retry_max_delay_field' ), 'atum-mailer-settings', 'atum_mailer_reliability' );

		add_settings_section(
			'atum_mailer_retention',
			__( 'Mail Retention', 'atum-mailer' ),
			array( $this, 'render_retention_section_help' ),
			'atum-mailer-settings'
		);
		add_settings_field( 'mail_retention', __( 'Store Delivery Logs', 'atum-mailer' ), array( $this, 'render_mail_retention_field' ), 'atum-mailer-settings', 'atum_mailer_retention' );
		add_settings_field( 'retention_days', __( 'Retention Window (days)', 'atum-mailer' ), array( $this, 'render_retention_days_field' ), 'atum-mailer-settings', 'atum_mailer_retention' );

			add_settings_section(
				'atum_mailer_webhooks',
				__( 'Webhooks', 'atum-mailer' ),
				array( $this, 'render_webhook_section_help' ),
				'atum-mailer-settings'
			);
			add_settings_field( 'postmark_webhook_secret', __( 'Webhook Shared Secret', 'atum-mailer' ), array( $this, 'render_postmark_webhook_secret_field' ), 'atum-mailer-settings', 'atum_mailer_webhooks' );
			add_settings_field( 'webhook_require_signature', __( 'Require Signature Verification', 'atum-mailer' ), array( $this, 'render_webhook_require_signature_field' ), 'atum-mailer-settings', 'atum_mailer_webhooks' );
			add_settings_field( 'webhook_replay_window_seconds', __( 'Webhook Replay Window (s)', 'atum-mailer' ), array( $this, 'render_webhook_replay_window_field' ), 'atum-mailer-settings', 'atum_mailer_webhooks' );
			add_settings_field( 'webhook_rate_limit_per_minute', __( 'Webhook Rate Limit (/min/IP)', 'atum-mailer' ), array( $this, 'render_webhook_rate_limit_field' ), 'atum-mailer-settings', 'atum_mailer_webhooks' );
			add_settings_field( 'webhook_allowed_ip_ranges', __( 'Webhook Source IP Allowlist', 'atum-mailer' ), array( $this, 'render_webhook_allowed_ip_ranges_field' ), 'atum-mailer-settings', 'atum_mailer_webhooks' );
		}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$css_file    = ATUM_MAILER_DIR . 'assets/css/admin.css';
		$js_file     = ATUM_MAILER_DIR . 'assets/js/admin.js';
		$css_version = file_exists( $css_file ) ? (string) filemtime( $css_file ) : ATUM_MAILER_VERSION;
		$js_version  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : ATUM_MAILER_VERSION;

		wp_enqueue_style( 'atum-mailer-admin', ATUM_MAILER_URL . 'assets/css/admin.css', array(), $css_version );
		wp_enqueue_script( 'atum-mailer-admin', ATUM_MAILER_URL . 'assets/js/admin.js', array(), $js_version, true );

		$options = $this->settings->get_options();
		wp_localize_script(
			'atum-mailer-admin',
			'atumMailerAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'atum_mailer_admin_nonce' ),
				'i18n'    => array(
					'loading'            => __( 'Loading log details...', 'atum-mailer' ),
					'loadError'          => __( 'Failed to load log details.', 'atum-mailer' ),
					'revealError'        => __( 'Unable to reveal token right now.', 'atum-mailer' ),
					'showKey'            => __( 'Show key', 'atum-mailer' ),
					'hideKey'            => __( 'Hide key', 'atum-mailer' ),
					'unknownLabel'       => __( 'Unknown', 'atum-mailer' ),
					'confirmReveal'      => __( 'Reveal this API key in plain text?', 'atum-mailer' ),
					'tokenRevealDisabled'=> __( 'Token reveal is disabled in settings.', 'atum-mailer' ),
					'invalidEmail'       => __( 'Please enter a valid email address.', 'atum-mailer' ),
					'duplicateEmail'     => __( 'This recipient is already added.', 'atum-mailer' ),
					'recipientAdded'     => __( 'Recipient added.', 'atum-mailer' ),
					'recipientRemoved'   => __( 'Recipient removed.', 'atum-mailer' ),
					'removeRecipient'    => __( 'Remove recipient', 'atum-mailer' ),
					'selectLogsRequired' => __( 'Select at least one log entry first.', 'atum-mailer' ),
					'confirmPurgeFiltered'=> __( 'Purge all logs matching current filters?', 'atum-mailer' ),
					'confirmPurgeFilteredTitle' => __( 'Purge Filtered Logs?', 'atum-mailer' ),
					'confirmPurgeButton' => __( 'Purge Logs', 'atum-mailer' ),
					'selectedLogsLabel'  => __( '%d selected', 'atum-mailer' ),
					'dangerCancel'       => __( 'Cancel', 'atum-mailer' ),
					'dangerConfirm'      => __( 'Confirm', 'atum-mailer' ),
				),
				'tokenRevealAllowed' => ! empty( $options['allow_token_reveal'] ) ? 1 : 0,
			)
		);
	}

	/**
	 * Render warning notice when plugin enabled but unconfigured.
	 *
	 * @return void
	 */
	public function render_configuration_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = $this->settings->get_options();
		if ( empty( $options['enabled'] ) || ! empty( $options['postmark_server_token'] ) ) {
			return;
		}

		$link = admin_url( 'admin.php?page=' . Atum_Mailer::PAGE_SLUG . '&tab=settings' );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s is settings page URL. */
						__( 'atum.mailer is enabled but missing a Postmark Server Token. Configure it in <a href="%s">atum.mailer Settings</a>.', 'atum-mailer' ),
						esc_url( $link )
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle send-test action.
	 *
	 * @return void
	 */
	public function handle_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		check_admin_referer( 'atum_mailer_send_test' );

		$to_raw  = wp_unslash( $_POST['test_email'] ?? '' );
		$to      = $this->parse_email_list( $to_raw );
		$subject = sanitize_text_field( wp_unslash( $_POST['test_subject'] ?? '' ) );
		$message = wp_kses_post( wp_unslash( $_POST['test_message'] ?? '' ) );

		if ( empty( $to ) ) {
			$this->redirect_with_notice( 'send-test', 'error', __( 'Enter at least one valid recipient email address.', 'atum-mailer' ) );
		}
		if ( '' === $subject ) {
			$subject = __( 'atum.mailer Test Email', 'atum-mailer' );
		}
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			$message = '<p>' . esc_html__( 'This is a Postmark delivery test from atum.mailer.', 'atum-mailer' ) . '</p>';
		}

		$options = $this->settings->get_options();
		if ( empty( $options['enabled'] ) || empty( $options['postmark_server_token'] ) ) {
			$this->redirect_with_notice( 'send-test', 'error', __( 'Enable atum.mailer and configure your Postmark token before sending a test.', 'atum-mailer' ) );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'X-Atum-Mailer-Test: 1' );
		$sent    = wp_mail( $to, $subject, $message, $headers );

		if ( $sent ) {
			update_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, current_time( 'mysql' ) );
			$verb = 'queue' === (string) ( $options['delivery_mode'] ?? 'immediate' ) ? __( 'queued', 'atum-mailer' ) : __( 'sent', 'atum-mailer' );
			$this->redirect_with_notice(
				'send-test',
				'success',
				sprintf( __( 'Test email %1$s to %2$d recipient(s).', 'atum-mailer' ), $verb, count( $to ) ),
				add_query_arg(
					array(
						'page'   => Atum_Mailer::PAGE_SLUG,
						'tab'    => 'logs',
						'status' => 'all',
						's'      => $subject,
					),
					admin_url( 'admin.php' )
				),
				__( 'View Mail Logs', 'atum-mailer' )
			);
		}

		$this->redirect_with_notice(
			'send-test',
			'error',
			__( 'Test email failed. Check logs for details.', 'atum-mailer' ),
			add_query_arg(
				array(
					'page' => Atum_Mailer::PAGE_SLUG,
					'tab'  => 'logs',
				),
				admin_url( 'admin.php' )
			),
			__( 'Open Mail Logs', 'atum-mailer' )
		);
	}

	/**
	 * Trigger queue processing from admin dashboard.
	 *
	 * @return void
	 */
	public function handle_process_queue_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		check_admin_referer( 'atum_mailer_process_queue_now' );

		$before = max( 0, (int) $this->queue->countBacklog() );
		$this->mail_interceptor->process_queue();
		$after     = max( 0, (int) $this->queue->countBacklog() );
		$processed = max( 0, $before - $after );

		$type    = $processed > 0 ? 'success' : 'info';
		$message = sprintf(
			/* translators: 1: processed jobs count, 2: remaining backlog count */
			__( 'Queue run complete. Processed: %1$d. Remaining backlog: %2$d.', 'atum-mailer' ),
			$processed,
			$after
		);

		$this->redirect_with_notice( 'dashboard', $type, $message, $this->logs_tab_url(), __( 'Open Mail Logs', 'atum-mailer' ) );
	}

	/**
	 * Purge old logs action.
	 *
	 * @return void
	 */
	public function handle_purge_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		check_admin_referer( 'atum_mailer_purge_logs' );
		$options = $this->settings->get_options();
		$days    = max( 1, (int) $options['retention_days'] );
		$deleted = $this->logs->purge_logs_older_than( $days );

		update_option( Atum_Mailer_Settings_Repository::LAST_CLEANUP_OPTION, time() );
		$this->redirect_with_notice( 'logs', 'success', sprintf( __( 'Purged %d old log entries.', 'atum-mailer' ), (int) $deleted ) );
	}

	/**
	 * Connect and verify API token.
	 *
	 * @return void
	 */
	public function handle_connect_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['atum_mailer_connect_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'atum_mailer_connect_token' ) ) {
			$this->redirect_with_notice( 'settings', 'error', __( 'Security check failed. Please try again.', 'atum-mailer' ) );
		}

		$options      = $this->settings->get_options();
		$posted_token = sanitize_text_field( wp_unslash( $_POST['postmark_server_token'] ?? '' ) );
		$token        = '' !== $posted_token ? $posted_token : (string) $options['postmark_server_token'];

		if ( '' === $token ) {
			$this->redirect_with_notice( 'settings', 'error', __( 'Enter a Postmark Server Token first.', 'atum-mailer' ) );
		}

		$verified = $this->client->verify_token( $token );
		if ( is_wp_error( $verified ) ) {
			$options['token_last_error'] = $verified->get_error_message();
			$this->settings->update_raw_options( $options );
			$this->redirect_with_notice( 'settings', 'error', sprintf( __( 'Token verification failed: %s', 'atum-mailer' ), $verified->get_error_message() ) );
		}

		$options['postmark_server_token'] = $token;
		$options['token_verified']        = 1;
		$options['token_verified_at']     = current_time( 'mysql' );
		$options['token_server_name']     = sanitize_text_field( (string) ( $verified['server_name'] ?? '' ) );
		$options['token_last_error']      = '';
		$options['available_streams']     = ! empty( $verified['available_streams'] ) && is_array( $verified['available_streams'] )
			? $verified['available_streams']
			: array( 'outbound' );

		if ( ! in_array( (string) $options['message_stream'], $options['available_streams'], true ) ) {
			$options['message_stream'] = (string) $options['available_streams'][0];
		}

		$this->settings->set_token( $token );
		$this->settings->update_raw_options( $options );
		$this->redirect_with_notice( 'settings', 'success', __( 'API key connected and verified.', 'atum-mailer' ) );
	}

	/**
	 * Disconnect API token.
	 *
	 * @return void
	 */
	public function handle_disconnect_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['atum_mailer_disconnect_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'atum_mailer_disconnect_token' ) ) {
			$this->redirect_with_notice( 'settings', 'error', __( 'Security check failed. Please try again.', 'atum-mailer' ) );
		}

		$options                          = $this->settings->get_options();
		$options['postmark_server_token'] = '';
		$options['token_verified']        = 0;
		$options['token_verified_at']     = '';
		$options['token_server_name']     = '';
		$options['token_last_error']      = '';
		$options['available_streams']     = array( 'outbound', 'broadcast' );
		$options['message_stream']        = 'outbound';

		$this->settings->clear_token();
		$this->settings->update_raw_options( $options );
		$this->redirect_with_notice( 'settings', 'success', __( 'API key disconnected.', 'atum-mailer' ) );
	}

	/**
	 * Recover token actions when admin-post receives action=update.
	 *
	 * @return void
	 */
	public function handle_misrouted_update_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_page = sanitize_text_field( wp_unslash( $_REQUEST['option_page'] ?? '' ) );
		if ( 'atum_mailer_settings' !== $option_page ) {
			return;
		}

		if ( isset( $_REQUEST['atum_mailer_connect_nonce'] ) ) {
			$this->handle_connect_token();
		}
		if ( isset( $_REQUEST['atum_mailer_disconnect_nonce'] ) ) {
			$this->handle_disconnect_token();
		}
	}

	/**
	 * Export logs as CSV.
	 *
	 * @return void
	 */
	public function handle_export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		check_admin_referer( 'atum_mailer_export_logs' );
		$filters = $this->parse_log_filters( $_POST );
		$rows    = $this->logs->query_logs_for_export( $filters );
		$this->send_logs_csv_download( $rows );
	}

	/**
	 * Handle bulk actions from logs tab.
	 *
	 * @return void
	 */
	public function handle_logs_bulk_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		check_admin_referer( 'atum_mailer_logs_bulk' );
		$filters = $this->parse_log_filters( $_POST );
		$action  = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$log_ids = $this->parse_log_ids(
			$_POST['log_ids'] ?? array(),
			wp_unslash( $_POST['log_ids_csv'] ?? '' )
		);

		if ( 'export_selected' === $action ) {
			if ( empty( $log_ids ) ) {
				$this->redirect_with_notice( 'logs', 'error', __( 'Select at least one log entry to export.', 'atum-mailer' ), $this->logs_tab_url( $filters ), __( 'Back to Logs', 'atum-mailer' ) );
			}
			$rows = $this->logs->query_logs_for_export_by_ids( $log_ids );
			$this->send_logs_csv_download( $rows );
		}

		if ( 'retry_selected' === $action ) {
			if ( empty( $log_ids ) ) {
				$this->redirect_with_notice( 'logs', 'error', __( 'Select at least one log entry to retry.', 'atum-mailer' ), $this->logs_tab_url( $filters ), __( 'Back to Logs', 'atum-mailer' ) );
			}

			$rows    = $this->logs->query_payloads_by_ids( $log_ids );
			$resent  = 0;
			$skipped = 0;
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row->request_payload ?? '' ), true );
				if ( ! is_array( $payload ) || empty( $payload ) ) {
					$skipped++;
					continue;
				}
				$result = $this->mail_interceptor->resend_saved_payload( $payload );
				if ( is_wp_error( $result ) ) {
					$skipped++;
					continue;
				}
				$resent++;
			}

			$type    = $resent > 0 ? 'success' : 'error';
			$message = sprintf(
				/* translators: 1: resent count, 2: skipped count */
				__( 'Retry selected complete. Resent: %1$d. Skipped: %2$d.', 'atum-mailer' ),
				$resent,
				$skipped
			);
			$this->redirect_with_notice( 'logs', $type, $message, $this->logs_tab_url( $filters ), __( 'View Filtered Logs', 'atum-mailer' ) );
		}

		if ( 'purge_filtered' === $action ) {
			$deleted = $this->logs->purge_logs_by_filters( $filters );
			$this->redirect_with_notice(
				'logs',
				'success',
				sprintf( __( 'Purged %d logs matching current filters.', 'atum-mailer' ), (int) $deleted ),
				$this->logs_tab_url( $filters ),
				__( 'View Logs', 'atum-mailer' )
			);
		}

		$this->redirect_with_notice( 'logs', 'error', __( 'Unknown bulk action.', 'atum-mailer' ), $this->logs_tab_url( $filters ), __( 'Back to Logs', 'atum-mailer' ) );
	}

	/**
	 * Output logs CSV.
	 *
	 * @param array<int, object> $rows Rows.
	 * @return void
	 */
	private function send_logs_csv_download( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=atum-mailer-logs-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, array( 'ID', 'Date', 'To', 'Subject', 'Status', 'HTTP Status', 'Provider Message ID', 'Delivery Mode', 'Attempts', 'Last Error Code', 'Error' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					(int) $row->id,
					$this->csv_safe_cell( (string) $row->created_at ),
					$this->csv_safe_cell( $this->logs->format_recipient_list( $row->mail_to ) ),
					$this->csv_safe_cell( (string) $row->subject ),
					$this->csv_safe_cell( (string) $row->status ),
					$this->csv_safe_cell( (string) $row->http_status ),
					$this->csv_safe_cell( (string) $row->provider_message_id ),
					$this->csv_safe_cell( (string) $row->delivery_mode ),
					$this->csv_safe_cell( (string) $row->attempt_count ),
					$this->csv_safe_cell( (string) $row->last_error_code ),
					$this->csv_safe_cell( (string) $row->error_message ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Resend a logged payload from Mail Logs tab.
	 *
	 * @return void
	 */
	public function handle_resend_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'atum-mailer' ) );
		}

		$log_id = absint( wp_unslash( $_POST['log_id'] ?? 0 ) );
		if ( $log_id <= 0 ) {
			$this->redirect_with_notice( 'logs', 'error', __( 'Invalid log id.', 'atum-mailer' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if (
			'' === $nonce
			|| (
				! wp_verify_nonce( $nonce, 'atum_mailer_resend_log_' . $log_id )
				&& ! wp_verify_nonce( $nonce, 'atum_mailer_resend_log' )
			)
		) {
			$this->redirect_with_notice( 'logs', 'error', __( 'Security check failed. Please try again.', 'atum-mailer' ) );
		}

		global $wpdb;
		$table = Atum_Mailer_Log_Repository::table_name();
		$log   = $wpdb->get_row( $wpdb->prepare( "SELECT id, request_payload FROM {$table} WHERE id = %d", $log_id ) );
		if ( ! $log ) {
			$this->redirect_with_notice( 'logs', 'error', __( 'Log not found.', 'atum-mailer' ) );
		}

		$payload = json_decode( (string) ( $log->request_payload ?? '' ), true );
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$this->redirect_with_notice( 'logs', 'error', __( 'This log does not contain a reusable payload. Enable full log detail mode for resend support.', 'atum-mailer' ) );
		}

		$resend_to_raw  = sanitize_text_field( wp_unslash( $_POST['resend_to'] ?? '' ) );
		$resend_subject = sanitize_text_field( wp_unslash( $_POST['resend_subject'] ?? '' ) );
		$resend_mode    = sanitize_key( wp_unslash( $_POST['resend_mode'] ?? '' ) );
		$used_overrides = '' !== $resend_to_raw || '' !== $resend_subject || '' !== $resend_mode;

		if ( $used_overrides ) {
			$payload = $this->apply_resend_overrides( $payload, $resend_to_raw, $resend_subject, $log_id );
			if ( is_wp_error( $payload ) ) {
				$this->redirect_with_notice( 'logs', 'error', $payload->get_error_message(), $this->logs_tab_url(), __( 'Back to Logs', 'atum-mailer' ) );
			}
		}

		if ( ! in_array( $resend_mode, array( 'immediate', 'queue' ), true ) ) {
			$resend_mode = '';
		}

		$result = $this->mail_interceptor->resend_saved_payload( $payload, $resend_mode );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'logs', 'error', sprintf( __( 'Resend failed: %s', 'atum-mailer' ), $result->get_error_message() ) );
		}

		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		if ( 'queued' === $status ) {
			$this->redirect_with_notice( 'logs', 'success', $used_overrides ? __( 'Message queued for resend with overrides.', 'atum-mailer' ) : __( 'Message queued for resend.', 'atum-mailer' ) );
		}
		if ( 'sent' === $status ) {
			$this->redirect_with_notice( 'logs', 'success', $used_overrides ? __( 'Message resent successfully with overrides.', 'atum-mailer' ) : __( 'Message resent successfully.', 'atum-mailer' ) );
		}
		if ( 'fallback' === $status ) {
			$this->redirect_with_notice( 'logs', 'success', __( 'Provider retryable outage detected. Fell back to native wp_mail().', 'atum-mailer' ) );
		}

		$this->redirect_with_notice( 'logs', 'success', __( 'Resend request processed.', 'atum-mailer' ) );
	}

	/**
	 * AJAX handler: fetch full log details.
	 *
	 * @return void
	 */
	public function handle_get_log_details() {
		check_ajax_referer( 'atum_mailer_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'atum-mailer' ) ), 403 );
		}

		$log_id = absint( $_POST['log_id'] ?? 0 );
		if ( $log_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing log ID.', 'atum-mailer' ) ), 400 );
		}

		global $wpdb;
		$table = Atum_Mailer_Log_Repository::table_name();
		$log   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ) );
		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'Log not found.', 'atum-mailer' ) ), 404 );
		}

		$message         = (string) $log->message;
		$headers         = $this->pretty_json_string( $log->headers );
		$attachments     = $this->pretty_json_string( $log->attachments );
		$request_payload = $this->pretty_json_string( $log->request_payload );
		$response_body   = $this->pretty_json_string( $log->response_body );
		$options         = $this->settings->get_options();
		$is_full_detail  = 'full' === sanitize_key( (string) ( $options['log_detail_mode'] ?? 'metadata' ) );

		if ( ! $is_full_detail ) {
			$payload_hint = __( 'Payload details are not stored in metadata mode. Set Log Detail Mode to "Full payload/body" to capture raw payloads.', 'atum-mailer' );
			if ( '' === $message ) {
				$message = __( 'Message body not stored in metadata mode.', 'atum-mailer' );
			}
			if ( '' === $headers ) {
				$headers = __( 'Headers not stored in metadata mode.', 'atum-mailer' );
			}
			if ( '' === $attachments ) {
				$attachments = __( 'Attachments not stored in metadata mode.', 'atum-mailer' );
			}
			if ( '' === $request_payload ) {
				$request_payload = $payload_hint;
			}
			if ( '' === $response_body ) {
				$response_body = __( 'Provider response body not stored for this log entry.', 'atum-mailer' );
			}
		}

		$data = array(
			'id'                  => (int) $log->id,
			'created_at'          => (string) $log->created_at,
			'updated_at'          => (string) $log->updated_at,
			'to'                  => $this->logs->format_recipient_list( $log->mail_to ),
			'subject'             => (string) $log->subject,
			'status'              => (string) $log->status,
			'delivery_mode'       => (string) $log->delivery_mode,
			'attempt_count'       => (string) $log->attempt_count,
			'next_attempt_at'     => (string) $log->next_attempt_at,
			'last_error_code'     => (string) $log->last_error_code,
			'provider'            => (string) ( $log->provider ?? '' ),
			'provider_message_id' => (string) $log->provider_message_id,
			'http_status'         => (string) ( $log->http_status ?? '' ),
			'error_message'       => (string) $log->error_message,
			'recipient_csv'       => $this->recipients_csv_from_mail_to( (string) $log->mail_to ),
			'message'             => $message,
			'headers'             => $headers,
			'attachments'         => $attachments,
			'request_payload'     => $request_payload,
			'response_body'       => $response_body,
			'webhook_event_type'  => $this->webhook_event_label( (string) $log->webhook_event_type ),
			'timeline'            => $this->build_log_timeline( $log ),
		);

		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler: reveal token with explicit confirmation.
	 *
	 * @return void
	 */
	public function handle_reveal_token() {
		check_ajax_referer( 'atum_mailer_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'atum-mailer' ) ), 403 );
		}

		$options = $this->settings->get_options();
		$token   = (string) $options['postmark_server_token'];
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'No token saved.', 'atum-mailer' ) ), 404 );
		}

		if ( empty( $options['allow_token_reveal'] ) ) {
			wp_send_json_success(
				array(
					'allowed' => false,
					'token'   => $this->mask_token( $token ),
					'masked'  => $this->mask_token( $token ),
					'message' => __( 'Token reveal is disabled in settings.', 'atum-mailer' ),
				)
			);
		}

		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? 'request' ) );
		if ( 'confirm' !== $stage ) {
			$session_key = wp_generate_uuid4();
			$fresh_nonce = wp_create_nonce( 'atum_mailer_reveal_confirm_' . $session_key );
			set_transient(
				$this->reveal_transient_key( $session_key ),
				array(
					'user_id' => get_current_user_id(),
					'expires' => time() + 120,
				),
				2 * MINUTE_IN_SECONDS
			);

			wp_send_json_success(
				array(
					'allowed'     => true,
					'needsConfirm'=> true,
					'session'     => $session_key,
					'freshNonce'  => $fresh_nonce,
					'masked'      => $this->mask_token( $token ),
				)
			);
		}

		$session     = sanitize_text_field( wp_unslash( $_POST['session'] ?? '' ) );
		$fresh_nonce = sanitize_text_field( wp_unslash( $_POST['fresh_nonce'] ?? '' ) );
		$session_row = get_transient( $this->reveal_transient_key( $session ) );

		if ( '' === $session || '' === $fresh_nonce || ! is_array( $session_row ) ) {
			wp_send_json_error( array( 'message' => __( 'Reveal session expired. Try again.', 'atum-mailer' ) ), 403 );
		}
		if ( (int) $session_row['user_id'] !== get_current_user_id() || (int) $session_row['expires'] < time() ) {
			delete_transient( $this->reveal_transient_key( $session ) );
			wp_send_json_error( array( 'message' => __( 'Reveal session expired. Try again.', 'atum-mailer' ) ), 403 );
		}
		if ( ! wp_verify_nonce( $fresh_nonce, 'atum_mailer_reveal_confirm_' . $session ) ) {
			delete_transient( $this->reveal_transient_key( $session ) );
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'atum-mailer' ) ), 403 );
		}

		delete_transient( $this->reveal_transient_key( $session ) );
		wp_send_json_success(
			array(
				'allowed' => true,
				'token'   => $token,
				'masked'  => $this->mask_token( $token ),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = $this->settings->get_options();
		$tab     = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) );
		$tabs    = array(
			'dashboard' => __( 'Dashboard', 'atum-mailer' ),
			'send-test' => __( 'Send Test', 'atum-mailer' ),
			'logs'      => __( 'Mail Logs', 'atum-mailer' ),
			'settings'  => __( 'Settings', 'atum-mailer' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		$logo_url = '';
		$logo     = ATUM_MAILER_DIR . 'assets/images/atum-logo.png';
		if ( file_exists( $logo ) ) {
			$logo_url = ATUM_MAILER_URL . 'assets/images/atum-logo.png';
		}
		$settings_url = $this->admin_tab_url( 'settings' );
		$logs_url     = $this->admin_tab_url( 'logs' );
		$send_test_url = $this->admin_tab_url( 'send-test' );
		$dashboard_url = $this->admin_tab_url( 'dashboard' );
		$delivery_mode = ucfirst( (string) ( $options['delivery_mode'] ?? 'immediate' ) );
		$token_state_label = __( 'Token Missing', 'atum-mailer' );
		$token_state_class = 'is-bad';
		if ( ! empty( $options['postmark_server_token'] ) && ! empty( $options['token_verified'] ) ) {
			$token_state_label = __( 'Token Verified', 'atum-mailer' );
			$token_state_class = 'is-good';
		} elseif ( ! empty( $options['postmark_server_token'] ) ) {
			$token_state_label = __( 'Token Unverified', 'atum-mailer' );
			$token_state_class = 'is-warn';
		}
		?>
		<div class="wrap atum-mailer-admin atum-mailer-admin--<?php echo esc_attr( $tab ); ?>">
			<div class="atum-shell">
				<header class="atum-shell-hero">
					<div class="atum-shell-hero__identity">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Atum Tech', 'atum-mailer' ); ?>" class="atum-shell-hero__logo" />
						<?php endif; ?>
						<div class="atum-shell-hero__copy">
							<p class="atum-shell-hero__eyebrow"><?php esc_html_e( 'Postmark Delivery Control Plane', 'atum-mailer' ); ?></p>
							<h1><?php esc_html_e( 'atum.mailer', 'atum-mailer' ); ?></h1>
							<p><?php esc_html_e( 'A complete command center for routing, queueing, observability, and replaying transactional email.', 'atum-mailer' ); ?></p>
						</div>
					</div>
					<div class="atum-shell-hero__meta">
						<div class="atum-shell-hero__status">
							<span class="atum-pill <?php echo esc_attr( ! empty( $options['enabled'] ) ? 'is-good' : 'is-muted' ); ?>"><?php echo ! empty( $options['enabled'] ) ? esc_html__( 'Delivery Enabled', 'atum-mailer' ) : esc_html__( 'Delivery Disabled', 'atum-mailer' ); ?></span>
							<span class="atum-pill is-muted"><?php echo esc_html( sprintf( __( 'Mode: %s', 'atum-mailer' ), $delivery_mode ) ); ?></span>
							<span class="atum-pill <?php echo esc_attr( $token_state_class ); ?>"><?php echo esc_html( $token_state_label ); ?></span>
						</div>
						<div class="atum-shell-hero__actions">
							<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Dashboard', 'atum-mailer' ); ?></a>
							<a href="<?php echo esc_url( $send_test_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Send Test', 'atum-mailer' ); ?></a>
							<a href="<?php echo esc_url( $logs_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Open Logs', 'atum-mailer' ); ?></a>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary"><?php esc_html_e( 'Configure Settings', 'atum-mailer' ); ?></a>
						</div>
					</div>
				</header>

				<nav class="atum-mailer-tabs" role="tablist" aria-label="<?php esc_attr_e( 'atum.mailer sections', 'atum-mailer' ); ?>">
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<?php $tab_url = add_query_arg( array( 'page' => Atum_Mailer::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ); ?>
						<?php $is_active = $tab === $slug; ?>
						<a
							href="<?php echo esc_url( $tab_url ); ?>"
							class="atum-mailer-tab <?php echo esc_attr( $is_active ? 'is-active' : '' ); ?>"
							role="tab"
							aria-selected="<?php echo esc_attr( $is_active ? 'true' : 'false' ); ?>"
							tabindex="<?php echo esc_attr( $is_active ? '0' : '-1' ); ?>"
							<?php echo $is_active ? 'aria-current="page"' : ''; ?>
						><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<?php $this->render_inline_notice(); ?>

				<div class="atum-mailer-panel atum-mailer-panel--<?php echo esc_attr( $tab ); ?>">
					<div>
						<?php
						switch ( $tab ) {
							case 'send-test':
								$this->render_send_test_tab();
								break;
							case 'logs':
								$this->render_logs_tab();
								break;
							case 'settings':
								$this->render_settings_tab();
								break;
							case 'dashboard':
							default:
								$this->render_dashboard_tab( $options );
								break;
						}
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render page-level action notice.
	 *
	 * @return void
	 */
	private function render_inline_notice() {
		$type    = sanitize_key( wp_unslash( $_GET['atum_mailer_notice'] ?? '' ) );
		$message = sanitize_text_field( wp_unslash( $_GET['atum_mailer_message'] ?? '' ) );
		$link    = $this->sanitize_notice_link( wp_unslash( $_GET['atum_mailer_notice_link'] ?? '' ) );
		$label   = sanitize_text_field( wp_unslash( $_GET['atum_mailer_notice_label'] ?? '' ) );

		if ( '' === $type || '' === $message ) {
			return;
		}

		$class = 'notice-info';
		if ( 'success' === $type ) {
			$class = 'notice-success';
		} elseif ( 'error' === $type ) {
			$class = 'notice-error';
		}
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible atum-inline-notice" role="status" aria-live="<?php echo esc_attr( 'error' === $type ? 'assertive' : 'polite' ); ?>">
			<p class="atum-inline-notice__content">
				<span><?php echo esc_html( $message ); ?></span>
				<?php if ( '' !== $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" class="button button-small atum-inline-notice__action"><?php echo esc_html( '' !== $label ? $label : __( 'Open', 'atum-mailer' ) ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Restrict inline notice action links to same-host admin URLs.
	 *
	 * @param string $raw_link Candidate URL.
	 * @return string
	 */
	private function sanitize_notice_link( $raw_link ) {
		$link = trim( (string) $raw_link );
		if ( '' === $link ) {
			return '';
		}

		$parts = wp_parse_url( $link );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		$admin_host = strtolower( (string) wp_parse_url( admin_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $admin_host || $host !== $admin_host ) {
			return '';
		}

		return $link;
	}

	/**
	 * Render dashboard tab.
	 *
	 * @param array<string, mixed> $options Settings.
	 * @return void
	 */
	private function render_dashboard_tab( $options ) {
		$queue_backlog = $this->queue->countBacklog();
		$queue_oldest  = $this->queue->oldestCreatedTimestamp();
		$queue_next_due = $this->queue->nextDueTimestamp();
		$queue_next_cron = wp_next_scheduled( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK );
		$stats         = $this->logs->get_log_stats( $queue_backlog, $queue_oldest );
		$health        = $this->build_runtime_health_checks( (int) $stats['queue_backlog'] );
		$readiness     = $this->build_setup_readiness( $options );
		$retry_parts = array();
		if ( ! empty( $stats['retry_error_breakdown'] ) && is_array( $stats['retry_error_breakdown'] ) ) {
			foreach ( $stats['retry_error_breakdown'] as $row ) {
				$retry_parts[] = sprintf( '%s (%d)', (string) ( $row['code'] ?? 'unknown' ), (int) ( $row['total'] ?? 0 ) );
			}
		}
		$required_percent = 0;
		if ( (int) $readiness['required_total'] > 0 ) {
			$required_percent = (int) floor( ( (int) $readiness['required_done'] / (int) $readiness['required_total'] ) * 100 );
		}
		?>
		<div class="atum-dashboard">
			<section class="atum-card atum-card--full atum-dashboard-readiness">
				<div class="atum-dashboard-readiness__head">
					<div>
						<h2><?php esc_html_e( 'Setup Readiness', 'atum-mailer' ); ?></h2>
						<p><?php echo esc_html( $readiness['summary'] ); ?></p>
					</div>
					<span class="atum-readiness-pill <?php echo esc_attr( $readiness['badge_class'] ); ?>"><?php echo esc_html( $readiness['badge_label'] ); ?></span>
				</div>
				<div class="atum-dashboard-readiness__progress">
					<div class="atum-progress-track" role="presentation">
						<span class="atum-progress-track__fill" style="width: <?php echo esc_attr( $required_percent ); ?>%;"></span>
					</div>
					<div class="atum-dashboard-readiness__meta">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: required steps complete, 2: required total, 3: optional complete, 4: optional total */
									__( 'Required steps: %1$d/%2$d. Optional steps: %3$d/%4$d.', 'atum-mailer' ),
									(int) $readiness['required_done'],
									(int) $readiness['required_total'],
									(int) $readiness['optional_done'],
									(int) $readiness['optional_total']
								)
							);
							?>
						</p>
						<?php if ( ! empty( $readiness['primary_action_url'] ) && ! empty( $readiness['primary_action_label'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $readiness['primary_action_url'] ); ?>"><?php echo esc_html( $readiness['primary_action_label'] ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<ol class="atum-setup-rail">
					<?php foreach ( $readiness['steps'] as $step ) : ?>
						<li class="atum-setup-rail__item <?php echo esc_attr( ! empty( $step['done'] ) ? 'is-complete' : 'is-pending' ); ?>">
							<div class="atum-setup-rail__content">
								<div class="atum-setup-rail__title">
									<strong><?php echo esc_html( $step['title'] ); ?></strong>
									<span class="atum-setup-rail__scope"><?php echo ! empty( $step['required'] ) ? esc_html__( 'Required', 'atum-mailer' ) : esc_html__( 'Optional', 'atum-mailer' ); ?></span>
								</div>
								<p><?php echo esc_html( $step['description'] ); ?></p>
							</div>
							<div class="atum-setup-rail__actions">
								<span class="atum-setup-rail__state <?php echo esc_attr( ! empty( $step['done'] ) ? 'is-good' : 'is-warn' ); ?>"><?php echo ! empty( $step['done'] ) ? esc_html__( 'Done', 'atum-mailer' ) : esc_html__( 'Pending', 'atum-mailer' ); ?></span>
								<?php if ( ! empty( $step['url'] ) ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['action_label'] ); ?></a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>

			<section class="atum-dashboard-kpis">
				<div class="atum-kpi-card">
					<p><?php esc_html_e( 'Total Logged Messages', 'atum-mailer' ); ?></p>
					<strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong>
				</div>
				<div class="atum-kpi-card">
					<p><?php esc_html_e( 'Sent', 'atum-mailer' ); ?></p>
					<strong><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></strong>
				</div>
				<div class="atum-kpi-card">
					<p><?php esc_html_e( 'Failed', 'atum-mailer' ); ?></p>
					<strong><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></strong>
				</div>
				<div class="atum-kpi-card">
					<p><?php esc_html_e( 'Queue Backlog', 'atum-mailer' ); ?></p>
					<strong><?php echo esc_html( number_format_i18n( $stats['queue_backlog'] ) ); ?></strong>
				</div>
				<div class="atum-kpi-card">
					<p><?php esc_html_e( 'Dead Letters', 'atum-mailer' ); ?></p>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $stats['dead_letter'] ?? 0 ) ) ); ?></strong>
				</div>
			</section>

			<div class="atum-dashboard-columns">
				<div class="atum-dashboard-column">
					<section class="atum-card atum-card--full atum-dashboard-panel">
					<h2><?php esc_html_e( 'Delivery Health', 'atum-mailer' ); ?></h2>
					<ul class="atum-dashboard-facts">
						<li><?php echo esc_html( sprintf( __( 'Last 24h failure rate: %s%% (%d/%d).', 'atum-mailer' ), number_format_i18n( (float) $stats['failure_rate_24h'], 2 ), (int) $stats['failures_24h'], (int) $stats['last_24h'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Failure trend vs previous 24h: %+s%%.', 'atum-mailer' ), number_format_i18n( (float) ( $stats['failure_rate_trend'] ?? 0 ), 2 ) ) ); ?></li>
						<li><?php echo esc_html( null !== ( $stats['queue_oldest_age_seconds'] ?? null ) ? sprintf( __( 'Oldest queued age: %s', 'atum-mailer' ), $this->format_age_seconds( (int) $stats['queue_oldest_age_seconds'] ) ) : __( 'Queue is currently empty.', 'atum-mailer' ) ); ?></li>
						<li><?php echo esc_html( $stats['last_sent'] ? sprintf( __( 'Last successful send: %s', 'atum-mailer' ), $stats['last_sent'] ) : __( 'No successful sends recorded yet.', 'atum-mailer' ) ); ?></li>
						<li><?php echo esc_html( $stats['last_api_outage'] ? sprintf( __( 'Last API outage: %s', 'atum-mailer' ), $stats['last_api_outage'] ) : __( 'No API outage recorded.', 'atum-mailer' ) ); ?></li>
						<?php if ( ! empty( $retry_parts ) ) : ?>
							<li><?php echo esc_html( sprintf( __( 'Top retry/failure codes (24h): %s', 'atum-mailer' ), implode( ', ', $retry_parts ) ) ); ?></li>
						<?php endif; ?>
					</ul>
					</section>

					<section class="atum-card atum-card--full atum-dashboard-panel atum-queue-operations">
						<h2><?php esc_html_e( 'Queue Operations', 'atum-mailer' ); ?></h2>
						<ul class="atum-dashboard-facts">
							<li><?php echo esc_html( sprintf( __( 'Current backlog: %d jobs', 'atum-mailer' ), (int) $queue_backlog ) ); ?></li>
							<li><?php echo esc_html( null !== $queue_oldest ? sprintf( __( 'Oldest queued age: %s', 'atum-mailer' ), $this->format_age_seconds( time() - (int) $queue_oldest ) ) : __( 'No queued jobs pending.', 'atum-mailer' ) ); ?></li>
							<li><?php echo esc_html( null !== $queue_next_due ? sprintf( __( 'Next due attempt: %s UTC', 'atum-mailer' ), gmdate( 'Y-m-d H:i:s', (int) $queue_next_due ) ) : __( 'No due attempts currently scheduled.', 'atum-mailer' ) ); ?></li>
							<li><?php echo esc_html( false !== $queue_next_cron ? sprintf( __( 'Queue cron next run: %s UTC', 'atum-mailer' ), gmdate( 'Y-m-d H:i:s', (int) $queue_next_cron ) ) : __( 'Queue cron is currently not scheduled.', 'atum-mailer' ) ); ?></li>
						</ul>
						<div class="atum-queue-operations__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'atum_mailer_process_queue_now' ); ?>
								<input type="hidden" name="action" value="atum_mailer_process_queue_now" />
								<button type="submit" class="button button-secondary"><?php esc_html_e( 'Process Queue Now', 'atum-mailer' ); ?></button>
							</form>
							<a class="button button-secondary" href="<?php echo esc_url( $this->logs_tab_url( array( 'status' => 'queued', 'delivery_mode' => 'queue' ) ) ); ?>"><?php esc_html_e( 'View Queued Logs', 'atum-mailer' ); ?></a>
						</div>
					</section>
				</div>
				<div class="atum-dashboard-column">
					<section class="atum-card atum-card--full atum-dashboard-panel">
						<h2><?php esc_html_e( 'Runtime Integrity', 'atum-mailer' ); ?></h2>
						<ul class="atum-runtime-health">
							<?php foreach ( $health as $row ) : ?>
								<li class="atum-runtime-health__row">
									<div>
										<strong><?php echo esc_html( $row['label'] ); ?></strong>
										<p><?php echo esc_html( $row['value'] ); ?></p>
									</div>
									<span class="atum-runtime-health__badge <?php echo esc_attr( $row['badge_class'] ); ?>"><?php echo esc_html( $row['badge_label'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>

					<section class="atum-card atum-card--full atum-dashboard-panel">
						<h2><?php esc_html_e( 'Retention', 'atum-mailer' ); ?></h2>
						<ul class="atum-dashboard-facts">
							<li><?php echo ! empty( $options['mail_retention'] ) ? esc_html__( 'Log retention is active.', 'atum-mailer' ) : esc_html__( 'Log retention is disabled.', 'atum-mailer' ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Retention window: %d days', 'atum-mailer' ), (int) $options['retention_days'] ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Log detail mode: %s', 'atum-mailer' ), 'full' === (string) $options['log_detail_mode'] ? __( 'Full', 'atum-mailer' ) : __( 'Metadata', 'atum-mailer' ) ) ); ?></li>
						</ul>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Atum_Mailer::PAGE_SLUG . '&tab=logs' ) ); ?>"><?php esc_html_e( 'View Mail Logs', 'atum-mailer' ); ?></a>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build runtime health checks shown on Dashboard.
	 *
	 * @param int $queue_backlog Queue backlog count.
	 * @return array<int, array<string, string>>
	 */
	private function build_runtime_health_checks( $queue_backlog ) {
		$rows        = array();
		$is_db_queue = $this->queue instanceof Atum_Mailer_Db_Queue_Repository;

		$rows[] = $this->make_runtime_health_row(
			__( 'Queue Backend', 'atum-mailer' ),
			$is_db_queue ? __( 'Database queue repository active.', 'atum-mailer' ) : __( 'Legacy option queue repository in use.', 'atum-mailer' ),
			$is_db_queue ? 'good' : 'warn'
		);

		$log_db_version = (string) get_option( Atum_Mailer_Log_Repository::DB_VERSION_OPTION, '' );
		$rows[]         = $this->make_runtime_health_row(
			__( 'Logs Schema', 'atum-mailer' ),
			Atum_Mailer_Log_Repository::DB_VERSION === $log_db_version
				? __( 'Logs table schema is current.', 'atum-mailer' )
				: __( 'Logs table schema is outdated. Re-activate plugin or run upgrade.', 'atum-mailer' ),
			Atum_Mailer_Log_Repository::DB_VERSION === $log_db_version ? 'good' : 'bad'
		);

		$queue_db_version = (string) get_option( Atum_Mailer_Db_Queue_Repository::DB_VERSION_OPTION, '' );
		$rows[]           = $this->make_runtime_health_row(
			__( 'Queue Schema', 'atum-mailer' ),
			Atum_Mailer_Db_Queue_Repository::DB_VERSION === $queue_db_version
				? __( 'Queue table schema is current.', 'atum-mailer' )
				: __( 'Queue table schema is outdated. Re-activate plugin or run upgrade.', 'atum-mailer' ),
			Atum_Mailer_Db_Queue_Repository::DB_VERSION === $queue_db_version ? 'good' : 'bad'
		);

		$cleanup_next = wp_next_scheduled( Atum_Mailer_Bootstrap::CLEANUP_CRON_HOOK );
		$rows[]       = $this->make_runtime_health_row(
			__( 'Retention Cron', 'atum-mailer' ),
			false === $cleanup_next
				? __( 'Daily cleanup cron is not scheduled.', 'atum-mailer' )
				: sprintf( __( 'Next run: %s UTC', 'atum-mailer' ), gmdate( 'Y-m-d H:i:s', (int) $cleanup_next ) ),
			false === $cleanup_next ? 'warn' : 'good'
		);

		$queue_next   = wp_next_scheduled( Atum_Mailer_Mail_Interceptor::QUEUE_CRON_HOOK );
		$queue_status = $queue_backlog > 0 && false === $queue_next ? 'bad' : 'good';
		$queue_value  = $queue_backlog > 0 && false === $queue_next
			? __( 'Queue backlog exists but processor cron is not scheduled.', 'atum-mailer' )
			: ( false === $queue_next
				? __( 'Queue processor idle (no pending jobs).', 'atum-mailer' )
				: sprintf( __( 'Backlog: %1$d, next run: %2$s UTC', 'atum-mailer' ), $queue_backlog, gmdate( 'Y-m-d H:i:s', (int) $queue_next ) ) );

		$rows[] = $this->make_runtime_health_row(
			__( 'Queue Processor', 'atum-mailer' ),
			$queue_value,
			$queue_status
		);

		return $rows;
	}

	/**
	 * Build setup-readiness model for dashboard checklist.
	 *
	 * @param array<string, mixed> $options Settings.
	 * @return array<string, mixed>
	 */
	private function build_setup_readiness( $options ) {
		$last_test = (string) get_option( Atum_Mailer_Settings_Repository::LAST_TEST_EMAIL_OPTION, '' );
		$dns_check = $this->build_postmark_dns_check();

		$steps = array(
			array(
				'title'       => __( 'Connect token', 'atum-mailer' ),
				'description' => __( 'Connect and verify your Postmark Server Token.', 'atum-mailer' ),
				'done'        => ! empty( $options['token_verified'] ) && ! empty( $options['postmark_server_token'] ),
				'required'    => true,
				'url'         => $this->admin_tab_url( 'settings', 'atum-field-postmark-token' ),
				'action_label'=> __( 'Open Token Settings', 'atum-mailer' ),
			),
			array(
				'title'       => __( 'Verify sender', 'atum-mailer' ),
				'description' => __( 'Set a valid default From Email that is verified in Postmark.', 'atum-mailer' ),
				'done'        => '' !== (string) ( $options['from_email'] ?? '' ) && is_email( (string) ( $options['from_email'] ?? '' ) ),
				'required'    => true,
				'url'         => $this->admin_tab_url( 'settings', 'atum-field-from-email' ),
				'action_label'=> __( 'Open Sender Settings', 'atum-mailer' ),
			),
			array(
				'title'       => __( 'Send test', 'atum-mailer' ),
				'description' => '' !== $last_test
					? sprintf( __( 'Last successful test send: %s', 'atum-mailer' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_test ) )
					: __( 'Send at least one test email to confirm end-to-end delivery.', 'atum-mailer' ),
				'done'        => '' !== $last_test,
				'required'    => true,
				'url'         => $this->admin_tab_url( 'send-test', 'atum_test_email_input' ),
				'action_label'=> __( 'Open Send Test', 'atum-mailer' ),
			),
			array(
				'title'       => __( 'Enable delivery', 'atum-mailer' ),
				'description' => __( 'Turn on Postmark routing so wp_mail() messages are handled by atum.mailer.', 'atum-mailer' ),
				'done'        => ! empty( $options['enabled'] ),
				'required'    => true,
				'url'         => $this->admin_tab_url( 'settings', 'atum-field-enabled' ),
				'action_label'=> __( 'Open Delivery Toggle', 'atum-mailer' ),
			),
			array(
				'title'       => __( 'Configure webhook', 'atum-mailer' ),
				'description' => __( 'Set a webhook secret and connect Postmark webhook events for richer status tracking.', 'atum-mailer' ),
				'done'        => '' !== trim( (string) ( $options['postmark_webhook_secret'] ?? '' ) ),
				'required'    => false,
				'url'         => $this->admin_tab_url( 'settings', 'atum-field-webhook-secret' ),
				'action_label'=> __( 'Open Webhook Settings', 'atum-mailer' ),
			),
			array(
				'title'       => __( 'Check domain DNS', 'atum-mailer' ),
				'description' => (string) $dns_check['description'],
				'done'        => ! empty( $dns_check['done'] ),
				'required'    => false,
				'url'         => $this->admin_tab_url( 'settings', 'atum-field-from-email' ),
				'action_label'=> __( 'Open Sender Settings', 'atum-mailer' ),
			),
		);

		$required_total = 0;
		$required_done  = 0;
		$optional_total = 0;
		$optional_done  = 0;
		$next_pending   = null;

		foreach ( $steps as $index => $step ) {
			$is_required = ! empty( $step['required'] );
			$is_done     = ! empty( $step['done'] );

			if ( $is_required ) {
				$required_total++;
				if ( $is_done ) {
					$required_done++;
				}
			} else {
				$optional_total++;
				if ( $is_done ) {
					$optional_done++;
				}
			}

			if ( ! $is_done && null === $next_pending ) {
				$next_pending = $steps[ $index ];
			}
		}

		$is_ready = $required_total > 0 && $required_total === $required_done;

		$summary = __( 'Setup incomplete. Complete required steps below to make delivery reliable.', 'atum-mailer' );
		if ( $is_ready && $optional_total === $optional_done ) {
			$summary = __( 'Production-ready. All required and optional setup steps are complete.', 'atum-mailer' );
		} elseif ( $is_ready ) {
			$summary = __( 'Delivery is ready. Complete optional steps for richer event telemetry.', 'atum-mailer' );
		}

		$badge_label = $is_ready ? __( 'Ready', 'atum-mailer' ) : __( 'Setup Needed', 'atum-mailer' );
		$badge_class = $is_ready ? 'is-ready' : 'is-pending';

		return array(
			'summary'             => $summary,
			'badge_label'         => $badge_label,
			'badge_class'         => $badge_class,
			'required_total'      => $required_total,
			'required_done'       => $required_done,
			'optional_total'      => $optional_total,
			'optional_done'       => $optional_done,
			'primary_action_url'  => is_array( $next_pending ) ? (string) ( $next_pending['url'] ?? '' ) : '',
			'primary_action_label'=> is_array( $next_pending ) ? (string) ( $next_pending['action_label'] ?? '' ) : '',
			'steps'               => $steps,
		);
	}

	/**
	 * Build Postmark DNS verification summary for current site domain.
	 *
	 * @return array{done: bool, description: string}
	 */
	private function build_postmark_dns_check() {
		$domain = $this->resolve_site_domain_for_dns_check();
		if ( '' === $domain ) {
			return array(
				'done'        => false,
				'description' => __( 'Unable to determine the current site domain for DNS checks.', 'atum-mailer' ),
			);
		}

		$spf_pass        = false;
		$dkim_pass       = false;
		$dmarc_pass      = false;
		$return_path_pass = false;

		$root_txt_records = $this->lookup_dns_records( $domain, 'TXT' );
		foreach ( $root_txt_records as $record ) {
			$value = $this->normalize_dns_txt_record( $record );
			if ( '' === $value ) {
				continue;
			}

			if ( 0 === strpos( $value, 'v=spf1' ) && false !== strpos( $value, 'include:spf.mtasv.net' ) ) {
				$spf_pass = true;
				break;
			}
		}

		$dkim_records = $this->lookup_dns_records( 'pm._domainkey.' . $domain, 'TXT' );
		foreach ( $dkim_records as $record ) {
			$value = $this->normalize_dns_txt_record( $record );
			if ( '' === $value ) {
				continue;
			}

			if ( false !== strpos( $value, 'v=dkim1' ) && false !== strpos( $value, 'p=' ) ) {
				$dkim_pass = true;
				break;
			}
		}

		$dmarc_records = $this->lookup_dns_records( '_dmarc.' . $domain, 'TXT' );
		foreach ( $dmarc_records as $record ) {
			$value = $this->normalize_dns_txt_record( $record );
			if ( 0 === strpos( $value, 'v=dmarc1' ) ) {
				$dmarc_pass = true;
				break;
			}
		}

		$return_path_records = $this->lookup_dns_records( 'pm-bounces.' . $domain, 'CNAME' );
		foreach ( $return_path_records as $record ) {
			$target = strtolower( trim( (string) ( $record['target'] ?? '' ), " \t\n\r\0\x0B." ) );
			if ( '' !== $target && false !== strpos( $target, 'pm.mtasv.net' ) ) {
				$return_path_pass = true;
				break;
			}
		}

		$status_found   = __( 'Found', 'atum-mailer' );
		$status_missing = __( 'Missing', 'atum-mailer' );
		$description    = sprintf(
			/* translators: 1: domain, 2: SPF status, 3: DKIM status, 4: DMARC status, 5: return-path status */
			__( 'Domain %1$s checks: SPF: %2$s, DKIM (pm._domainkey): %3$s, DMARC: %4$s, Return-Path (pm-bounces): %5$s.', 'atum-mailer' ),
			$domain,
			$spf_pass ? $status_found : $status_missing,
			$dkim_pass ? $status_found : $status_missing,
			$dmarc_pass ? $status_found : $status_missing,
			$return_path_pass ? $status_found : $status_missing
		);

		return array(
			'done'        => $spf_pass && $dkim_pass,
			'description' => $description,
		);
	}

	/**
	 * Resolve the current site domain for DNS checks.
	 *
	 * @return string
	 */
	private function resolve_site_domain_for_dns_check() {
		$host = strtolower( trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), " \t\n\r\0\x0B." ) );
		if ( '' === $host ) {
			return '';
		}

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Lookup DNS records with optional filter override for tests/integrations.
	 *
	 * @param string $host Hostname to query.
	 * @param string $type Supported values: TXT|CNAME.
	 * @return array<int, array<string, mixed>>
	 */
	private function lookup_dns_records( $host, $type ) {
		$host = strtolower( trim( (string) $host, " \t\n\r\0\x0B." ) );
		$type = strtoupper( sanitize_key( (string) $type ) );
		if ( '' === $host || '' === $type ) {
			return array();
		}

		$filtered = apply_filters( 'atum_mailer_dns_records_lookup', null, $host, $type );
		if ( is_array( $filtered ) ) {
			return $filtered;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}

		$dns_type = null;
		if ( 'TXT' === $type && defined( 'DNS_TXT' ) ) {
			$dns_type = DNS_TXT;
		} elseif ( 'CNAME' === $type && defined( 'DNS_CNAME' ) ) {
			$dns_type = DNS_CNAME;
		}

		if ( null === $dns_type ) {
			return array();
		}

		$records = null;
		set_error_handler(
			static function () {
				return true;
			}
		);
		$records = dns_get_record( $host, $dns_type );
		restore_error_handler();

		return is_array( $records ) ? $records : array();
	}

	/**
	 * Normalize one DNS TXT record payload.
	 *
	 * @param array<string, mixed> $record Raw record payload.
	 * @return string
	 */
	private function normalize_dns_txt_record( $record ) {
		if ( ! is_array( $record ) ) {
			return '';
		}

		if ( isset( $record['txt'] ) ) {
			return strtolower( trim( (string) $record['txt'] ) );
		}

		if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
			$parts = array();
			foreach ( $record['entries'] as $entry ) {
				$parts[] = (string) $entry;
			}
			return strtolower( trim( implode( '', $parts ) ) );
		}

		return '';
	}

	/**
	 * Build admin tab URL with optional fragment.
	 *
	 * @param string $tab Tab slug.
	 * @param string $fragment Fragment ID.
	 * @return string
	 */
	private function admin_tab_url( $tab, $fragment = '' ) {
		$url = add_query_arg(
			array(
				'page' => Atum_Mailer::PAGE_SLUG,
				'tab'  => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);

		$fragment = ltrim( sanitize_text_field( (string) $fragment ), '#' );
		if ( '' !== $fragment ) {
			$url .= '#' . $fragment;
		}

		return $url;
	}

	/**
	 * Build one runtime health row.
	 *
	 * @param string $label Check label.
	 * @param string $value Check details.
	 * @param string $state good|warn|bad.
	 * @return array<string, string>
	 */
	private function make_runtime_health_row( $label, $value, $state ) {
		$state = sanitize_key( (string) $state );
		if ( ! in_array( $state, array( 'good', 'warn', 'bad' ), true ) ) {
			$state = 'warn';
		}

		$badge_label = __( 'Warning', 'atum-mailer' );
		if ( 'good' === $state ) {
			$badge_label = __( 'Healthy', 'atum-mailer' );
		} elseif ( 'bad' === $state ) {
			$badge_label = __( 'Action Required', 'atum-mailer' );
		}

		return array(
			'label'       => $label,
			'value'       => $value,
			'badge_class' => 'is-' . $state,
			'badge_label' => $badge_label,
		);
	}

	/**
	 * Render send-test tab.
	 *
	 * @return void
	 */
	private function render_send_test_tab() {
		$admin_email = sanitize_email( get_bloginfo( 'admin_email' ) );
		$options     = $this->settings->get_options();
		$mode        = (string) ( $options['delivery_mode'] ?? 'immediate' );
		$is_queue    = 'queue' === $mode;
		?>
		<section class="atum-card atum-card--full">
			<h2><?php esc_html_e( 'Send Test Email', 'atum-mailer' ); ?></h2>
			<p><?php esc_html_e( 'Use this to verify Postmark connectivity and sender configuration.', 'atum-mailer' ); ?></p>
			<div class="atum-send-test-context">
				<p>
					<strong><?php esc_html_e( 'Current delivery mode:', 'atum-mailer' ); ?></strong>
					<span class="atum-pill <?php echo esc_attr( $is_queue ? 'is-warn' : 'is-good' ); ?>"><?php echo esc_html( ucfirst( $mode ) ); ?></span>
				</p>
				<p>
					<?php
					echo esc_html(
						$is_queue
							? __( 'Messages are queued first, then processed by cron with retries.', 'atum-mailer' )
							: __( 'Messages are sent to Postmark immediately during request execution.', 'atum-mailer' )
					);
					?>
				</p>
				<p><a href="<?php echo esc_url( $this->admin_tab_url( 'settings', 'atum-field-delivery-mode' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Change Delivery Mode', 'atum-mailer' ); ?></a></p>
			</div>
			<?php if ( empty( $options['enabled'] ) || empty( $options['postmark_server_token'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'Setup is incomplete. Enable delivery and connect a token before using Send Test.', 'atum-mailer' ); ?>
						<a href="<?php echo esc_url( $this->admin_tab_url( 'dashboard' ) ); ?>"><?php esc_html_e( 'Open Setup Checklist', 'atum-mailer' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-mailer-form">
				<?php wp_nonce_field( 'atum_mailer_send_test' ); ?>
				<input type="hidden" name="action" value="atum_mailer_send_test" />

				<label for="atum_test_email_input"><?php esc_html_e( 'Recipient Email(s)', 'atum-mailer' ); ?></label>
				<div class="atum-email-adder" id="atum-test-email-adder">
					<div class="atum-email-adder__chips" id="atum-test-email-chips"></div>
					<div class="atum-email-adder__entry">
						<input type="email" id="atum_test_email_input" placeholder="<?php esc_attr_e( 'name@example.com', 'atum-mailer' ); ?>" />
						<button type="button" class="button button-secondary" id="atum-test-email-add"><?php esc_html_e( 'Add', 'atum-mailer' ); ?></button>
					</div>
					<input type="hidden" id="atum_test_email" name="test_email" value="<?php echo esc_attr( $admin_email ); ?>" />
					<p class="atum-email-adder__feedback" id="atum-test-email-feedback" aria-live="polite"></p>
				</div>
				<p class="description"><?php esc_html_e( 'Add one or more recipients. Press Enter or click Add.', 'atum-mailer' ); ?></p>

				<label for="atum_test_subject"><?php esc_html_e( 'Subject', 'atum-mailer' ); ?></label>
				<input type="text" id="atum_test_subject" name="test_subject" class="regular-text" value="<?php esc_attr_e( 'atum.mailer Test Email', 'atum-mailer' ); ?>" />

				<label for="atum_test_message"><?php esc_html_e( 'Message (HTML allowed)', 'atum-mailer' ); ?></label>
				<textarea id="atum_test_message" name="test_message" rows="6"><p><?php esc_html_e( 'This is a delivery test from atum.mailer.', 'atum-mailer' ); ?></p></textarea>

				<?php submit_button( __( 'Send Test Email', 'atum-mailer' ) ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Render logs tab.
	 *
	 * @return void
	 */
	private function render_logs_tab() {
		$filters = $this->parse_log_filters( $_GET );
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;
		$results  = $this->logs->query_logs( $filters, '', $per_page, $offset );
		$total    = (int) $results['total'];
		$logs     = is_array( $results['logs'] ) ? $results['logs'] : array();
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$options  = $this->settings->get_options();
		$allowed_statuses = $this->allowed_statuses();
		$visible_start    = $total > 0 ? $offset + 1 : 0;
		$visible_end      = $total > 0 ? min( $offset + count( $logs ), $total ) : 0;
		$active_filter_count = 0;
		if ( 'all' !== (string) $filters['status'] ) {
			$active_filter_count++;
		}
		if ( 'all' !== (string) $filters['delivery_mode'] ) {
			$active_filter_count++;
		}
		if ( 'all' !== (string) $filters['retry_state'] ) {
			$active_filter_count++;
		}
		if ( '' !== (string) $filters['date_from'] ) {
			$active_filter_count++;
		}
		if ( '' !== (string) $filters['date_to'] ) {
			$active_filter_count++;
		}
		if ( '' !== (string) $filters['provider_message_id'] ) {
			$active_filter_count++;
		}
		if ( '' !== (string) $filters['s'] ) {
			$active_filter_count++;
		}
		$retention_days      = max( 1, (int) ( $options['retention_days'] ?? 90 ) );
		$purge_confirmation  = sprintf(
			/* translators: %d retention days */
			__( 'This will permanently delete logs older than %d days. This action cannot be undone.', 'atum-mailer' ),
			$retention_days
		);
		?>
		<section class="atum-card atum-card--full atum-logs-shell">
			<div class="atum-logs-shell__header">
				<div class="atum-logs-shell__headline">
					<h2><?php esc_html_e( 'Mail Logs', 'atum-mailer' ); ?></h2>
					<p><?php esc_html_e( 'Search, triage, replay, and export delivery events.', 'atum-mailer' ); ?></p>
				</div>
				<div class="atum-logs-shell__actions">
					<button type="button" class="button button-secondary atum-logs-shell__filter-toggle" data-atum-log-filter-toggle="1" aria-expanded="false" aria-controls="atum-logs-filter-panel">
						<?php esc_html_e( 'Filters', 'atum-mailer' ); ?>
						<span class="atum-logs-shell__filter-count"><?php echo esc_html( (string) $active_filter_count ); ?></span>
					</button>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-logs-shell__action">
						<?php wp_nonce_field( 'atum_mailer_export_logs' ); ?>
						<input type="hidden" name="action" value="atum_mailer_export_logs" />
						<?php $this->render_log_filter_hidden_inputs( $filters ); ?>
						<button class="button button-secondary" type="submit"><?php esc_html_e( 'Export CSV', 'atum-mailer' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-logs-shell__action atum-danger-action" data-atum-danger-form="1" data-danger-title="<?php echo esc_attr__( 'Purge Old Logs?', 'atum-mailer' ); ?>" data-danger-message="<?php echo esc_attr( $purge_confirmation ); ?>" data-danger-confirm="<?php echo esc_attr__( 'Purge Logs', 'atum-mailer' ); ?>">
						<?php wp_nonce_field( 'atum_mailer_purge_logs' ); ?>
						<input type="hidden" name="action" value="atum_mailer_purge_logs" />
						<button class="button button-secondary" type="submit"><?php esc_html_e( 'Purge Old Logs', 'atum-mailer' ); ?></button>
					</form>
				</div>
			</div>

			<div id="atum-logs-filter-panel" class="atum-logs-filter-panel" data-atum-log-filter-panel="1">
				<div class="atum-logs-filter-panel__head">
					<strong><?php esc_html_e( 'Filter Logs', 'atum-mailer' ); ?></strong>
					<button type="button" class="button button-small button-secondary" data-atum-log-filter-close="1"><?php esc_html_e( 'Close', 'atum-mailer' ); ?></button>
				</div>
				<form method="get" class="atum-logs-filter atum-logs-filter--deck">
					<input type="hidden" name="page" value="<?php echo esc_attr( Atum_Mailer::PAGE_SLUG ); ?>" />
					<input type="hidden" name="tab" value="logs" />
					<div class="atum-logs-filter__field">
						<label for="atum-filter-status"><?php esc_html_e( 'Status', 'atum-mailer' ); ?></label>
						<select id="atum-filter-status" name="status">
							<?php foreach ( $allowed_statuses as $status_option ) : ?>
								<?php
								$label = 'all' === $status_option ? __( 'All statuses', 'atum-mailer' ) : ucwords( str_replace( '_', ' ', $status_option ) );
								?>
								<option value="<?php echo esc_attr( $status_option ); ?>" <?php selected( $filters['status'], $status_option ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="atum-logs-filter__field">
						<label for="atum-filter-mode"><?php esc_html_e( 'Delivery mode', 'atum-mailer' ); ?></label>
						<select id="atum-filter-mode" name="delivery_mode">
							<option value="all" <?php selected( $filters['delivery_mode'], 'all' ); ?>><?php esc_html_e( 'All modes', 'atum-mailer' ); ?></option>
							<option value="immediate" <?php selected( $filters['delivery_mode'], 'immediate' ); ?>><?php esc_html_e( 'Immediate', 'atum-mailer' ); ?></option>
							<option value="queue" <?php selected( $filters['delivery_mode'], 'queue' ); ?>><?php esc_html_e( 'Queue', 'atum-mailer' ); ?></option>
						</select>
					</div>
					<div class="atum-logs-filter__field">
						<label for="atum-filter-retry-state"><?php esc_html_e( 'Retry state', 'atum-mailer' ); ?></label>
						<select id="atum-filter-retry-state" name="retry_state">
							<option value="all" <?php selected( $filters['retry_state'], 'all' ); ?>><?php esc_html_e( 'All retry states', 'atum-mailer' ); ?></option>
							<option value="retrying" <?php selected( $filters['retry_state'], 'retrying' ); ?>><?php esc_html_e( 'Retrying', 'atum-mailer' ); ?></option>
							<option value="retried" <?php selected( $filters['retry_state'], 'retried' ); ?>><?php esc_html_e( 'Retried (attempt > 1)', 'atum-mailer' ); ?></option>
							<option value="terminal" <?php selected( $filters['retry_state'], 'terminal' ); ?>><?php esc_html_e( 'Terminal Failures', 'atum-mailer' ); ?></option>
						</select>
					</div>
					<div class="atum-logs-filter__field">
						<label for="atum-filter-date-from"><?php esc_html_e( 'Date from', 'atum-mailer' ); ?></label>
						<input id="atum-filter-date-from" type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
					</div>
					<div class="atum-logs-filter__field">
						<label for="atum-filter-date-to"><?php esc_html_e( 'Date to', 'atum-mailer' ); ?></label>
						<input id="atum-filter-date-to" type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
					</div>
					<div class="atum-logs-filter__field atum-logs-filter__field--wide">
						<label for="atum-filter-provider"><?php esc_html_e( 'Provider Message ID', 'atum-mailer' ); ?></label>
						<input id="atum-filter-provider" type="search" name="provider_message_id" value="<?php echo esc_attr( $filters['provider_message_id'] ); ?>" placeholder="<?php esc_attr_e( 'Provider Message ID', 'atum-mailer' ); ?>" />
					</div>
					<div class="atum-logs-filter__field atum-logs-filter__field--search">
						<label for="atum-filter-query"><?php esc_html_e( 'Search', 'atum-mailer' ); ?></label>
						<input id="atum-filter-query" type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Subject, recipient, error', 'atum-mailer' ); ?>" />
					</div>
					<div class="atum-logs-filter__actions">
						<button class="button button-primary" type="submit"><?php esc_html_e( 'Filter Logs', 'atum-mailer' ); ?></button>
						<a class="button button-secondary" href="<?php echo esc_url( $this->logs_tab_url() ); ?>"><?php esc_html_e( 'Reset', 'atum-mailer' ); ?></a>
					</div>
				</form>
			</div>

			<div class="atum-logs-shell__results">
				<div class="atum-logs-summary">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: first visible row, 2: last visible row, 3: total rows. */
								__( 'Showing %1$d to %2$d of %3$d log entries.', 'atum-mailer' ),
								(int) $visible_start,
								(int) $visible_end,
								(int) $total
							)
						);
						?>
					</p>
					<span class="atum-pill <?php echo esc_attr( $active_filter_count > 0 ? 'is-warn' : 'is-muted' ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d number of active filters. */
								_n( '%d active filter', '%d active filters', (int) $active_filter_count, 'atum-mailer' ),
								(int) $active_filter_count
							)
						);
						?>
					</span>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-logs-bulk-form" data-bulk-form="1" id="atum-logs-bulk-form">
				<?php wp_nonce_field( 'atum_mailer_logs_bulk' ); ?>
				<input type="hidden" name="action" value="atum_mailer_logs_bulk" />
				<?php $this->render_log_filter_hidden_inputs( $filters ); ?>
				<input type="hidden" name="log_ids_csv" value="" class="atum-log-ids-csv" />
				<div class="atum-logs-bulk-controls">
					<label for="atum-logs-bulk-action" class="atum-logs-bulk-label"><?php esc_html_e( 'Bulk actions', 'atum-mailer' ); ?></label>
					<select id="atum-logs-bulk-action" name="bulk_action" class="atum-logs-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'atum-mailer' ); ?></option>
						<option value="retry_selected"><?php esc_html_e( 'Retry selected', 'atum-mailer' ); ?></option>
						<option value="export_selected"><?php esc_html_e( 'Export selected', 'atum-mailer' ); ?></option>
						<option value="purge_filtered"><?php esc_html_e( 'Purge filtered', 'atum-mailer' ); ?></option>
					</select>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Action', 'atum-mailer' ); ?></button>
					<span class="atum-pill is-muted atum-logs-selected-count" data-atum-selected-count="1"><?php esc_html_e( '0 selected', 'atum-mailer' ); ?></span>
				</div>
				<p class="atum-logs-bulk-help"><?php esc_html_e( 'Choose an action and apply it to selected rows.', 'atum-mailer' ); ?></p>
			</form>

				<div class="atum-table-wrap">
					<table class="widefat fixed striped atum-mailer-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'Mail delivery log entries', 'atum-mailer' ); ?></caption>
						<thead>
							<tr>
								<th class="check-column check-column--center"><input type="checkbox" id="atum-log-select-all" aria-label="<?php esc_attr_e( 'Select all logs', 'atum-mailer' ); ?>" /></th>
								<th><?php esc_html_e( 'Date', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'To', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'Subject', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'Status', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'Mode', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'Attempts', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'HTTP', 'atum-mailer' ); ?></th>
								<th><?php esc_html_e( 'Details', 'atum-mailer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr><td colspan="9"><?php esc_html_e( 'No mail logs found for this filter.', 'atum-mailer' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $logs as $log ) : ?>
									<?php $detail_text = $log->error_message ? $log->error_message : __( 'OK', 'atum-mailer' ); ?>
									<?php
									$webhook_type  = sanitize_key( (string) ( $log->webhook_event_type ?? '' ) );
									$webhook_label = '' !== $webhook_type ? $this->webhook_event_label( $webhook_type ) : '';
									?>
									<tr>
										<td class="check-column check-column--center"><input type="checkbox" class="atum-log-select" name="log_ids[]" form="atum-logs-bulk-form" value="<?php echo esc_attr( (string) $log->id ); ?>" /></td>
										<td><?php echo esc_html( $log->created_at ); ?></td>
										<td><?php echo esc_html( $this->logs->format_recipient_list( $log->mail_to ) ); ?></td>
										<td><?php echo esc_html( $log->subject ); ?></td>
										<td>
											<div class="atum-log-status-stack">
												<span class="atum-status atum-status--<?php echo esc_attr( sanitize_html_class( $log->status ) ); ?>"><?php echo esc_html( ucfirst( $log->status ) ); ?></span>
												<?php if ( '' !== $webhook_label ) : ?>
													<span class="atum-event-chip atum-event-chip--<?php echo esc_attr( $this->webhook_event_tone( $webhook_type ) ); ?>"><?php echo esc_html( $webhook_label ); ?></span>
												<?php endif; ?>
											</div>
										</td>
										<td><?php echo esc_html( ucfirst( (string) $log->delivery_mode ) ); ?></td>
										<td><?php echo esc_html( (string) $log->attempt_count ); ?></td>
										<td><?php echo esc_html( $log->http_status ? (string) $log->http_status : '-' ); ?></td>
										<td>
											<div class="atum-log-actions">
												<span class="atum-log-actions__text"><?php echo esc_html( $detail_text ); ?></span>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-log-actions__resend">
													<?php wp_nonce_field( 'atum_mailer_resend_log_' . (int) $log->id ); ?>
													<input type="hidden" name="action" value="atum_mailer_resend_log" />
													<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $log->id ); ?>" />
													<button type="submit" class="button button-small"><?php esc_html_e( 'Resend', 'atum-mailer' ); ?></button>
												</form>
												<button type="button" class="button button-small atum-log-view" data-log-id="<?php echo esc_attr( (string) $log->id ); ?>" aria-haspopup="dialog" aria-controls="atum-log-drawer"><?php esc_html_e( 'View', 'atum-mailer' ); ?></button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg(
											array_merge(
												array(
													'page'  => Atum_Mailer::PAGE_SLUG,
													'tab'   => 'logs',
													'paged' => '%#%',
												),
												$this->build_logs_filter_args( $filters )
											),
											admin_url( 'admin.php' )
										),
										'format'    => '',
										'current'   => $paged,
										'total'     => $pages,
										'prev_text' => __( '&laquo;', 'atum-mailer' ),
										'next_text' => __( '&raquo;', 'atum-mailer' ),
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<div id="atum-log-drawer" class="atum-log-drawer" aria-hidden="true">
			<div class="atum-log-drawer__scrim" data-atum-close="1"></div>
			<aside class="atum-log-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="atum-log-drawer-title" tabindex="-1">
				<header class="atum-log-drawer__header">
					<div>
						<h3 id="atum-log-drawer-title"><?php esc_html_e( 'Log Details', 'atum-mailer' ); ?></h3>
						<p class="atum-log-drawer__subtitle" data-atum-field="subject"></p>
					</div>
					<button type="button" class="button button-secondary" data-atum-close="1" data-atum-initial-focus="1"><?php esc_html_e( 'Close', 'atum-mailer' ); ?></button>
				</header>
				<div class="atum-log-drawer__meta">
					<div><strong><?php esc_html_e( 'Status', 'atum-mailer' ); ?>:</strong> <span data-atum-field="status"></span></div>
					<div><strong><?php esc_html_e( 'Date', 'atum-mailer' ); ?>:</strong> <span data-atum-field="created_at"></span></div>
					<div><strong><?php esc_html_e( 'To', 'atum-mailer' ); ?>:</strong> <span data-atum-field="to"></span></div>
					<div><strong><?php esc_html_e( 'HTTP', 'atum-mailer' ); ?>:</strong> <span data-atum-field="http_status"></span></div>
					<div><strong><?php esc_html_e( 'Provider ID', 'atum-mailer' ); ?>:</strong> <span data-atum-field="provider_message_id"></span></div>
					<div><strong><?php esc_html_e( 'Mode', 'atum-mailer' ); ?>:</strong> <span data-atum-field="delivery_mode"></span></div>
					<div><strong><?php esc_html_e( 'Attempts', 'atum-mailer' ); ?>:</strong> <span data-atum-field="attempt_count"></span></div>
					<div><strong><?php esc_html_e( 'Next Attempt', 'atum-mailer' ); ?>:</strong> <span data-atum-field="next_attempt_at"></span></div>
				</div>
				<div class="atum-log-drawer__scroll">
					<div class="atum-log-drawer__body">
							<section class="atum-log-resend-panel">
								<h4><?php esc_html_e( 'Replay / Resend', 'atum-mailer' ); ?></h4>
								<p><?php esc_html_e( 'Safeguard edits: you can override recipient(s), subject, and delivery mode before resending this payload.', 'atum-mailer' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="atum-log-resend-form">
									<?php wp_nonce_field( 'atum_mailer_resend_log' ); ?>
									<input type="hidden" name="action" value="atum_mailer_resend_log" />
									<input type="hidden" name="log_id" value="" data-atum-resend-log-id="1" />
									<label><?php esc_html_e( 'Recipient override (comma separated)', 'atum-mailer' ); ?></label>
									<input type="text" name="resend_to" value="" data-atum-resend-to="1" />
									<label><?php esc_html_e( 'Subject override', 'atum-mailer' ); ?></label>
									<input type="text" name="resend_subject" value="" data-atum-resend-subject="1" />
									<label><?php esc_html_e( 'Delivery mode override', 'atum-mailer' ); ?></label>
									<select name="resend_mode" data-atum-resend-mode="1">
										<option value=""><?php esc_html_e( 'Use current default mode', 'atum-mailer' ); ?></option>
										<option value="immediate"><?php esc_html_e( 'Immediate', 'atum-mailer' ); ?></option>
										<option value="queue"><?php esc_html_e( 'Queue', 'atum-mailer' ); ?></option>
									</select>
									<button type="submit" class="button button-secondary"><?php esc_html_e( 'Resend With Overrides', 'atum-mailer' ); ?></button>
								</form>
							</section>
							<section class="atum-log-timeline-section">
								<h4><?php esc_html_e( 'Delivery Timeline', 'atum-mailer' ); ?></h4>
								<ol class="atum-log-timeline" data-atum-field="timeline"></ol>
							</section>
							<section class="atum-log-diagnostics">
								<div><h4><?php esc_html_e( 'Error', 'atum-mailer' ); ?></h4><pre data-atum-field="error_message"></pre></div>
								<div><h4><?php esc_html_e( 'Last Error Code', 'atum-mailer' ); ?></h4><pre data-atum-field="last_error_code"></pre></div>
								<div><h4><?php esc_html_e( 'Webhook Event', 'atum-mailer' ); ?></h4><pre data-atum-field="webhook_event_type"></pre></div>
							</section>
							<details class="atum-log-raw-disclosure">
								<summary><?php esc_html_e( 'Raw Payload & Response', 'atum-mailer' ); ?></summary>
								<div class="atum-log-raw-disclosure__body">
									<section><h4><?php esc_html_e( 'Message', 'atum-mailer' ); ?></h4><pre data-atum-field="message"></pre></section>
									<section><h4><?php esc_html_e( 'Headers', 'atum-mailer' ); ?></h4><pre data-atum-field="headers"></pre></section>
									<section><h4><?php esc_html_e( 'Attachments', 'atum-mailer' ); ?></h4><pre data-atum-field="attachments"></pre></section>
									<section><h4><?php esc_html_e( 'Request Payload', 'atum-mailer' ); ?></h4><pre data-atum-field="request_payload"></pre></section>
									<section><h4><?php esc_html_e( 'Response Body', 'atum-mailer' ); ?></h4><pre data-atum-field="response_body"></pre></section>
								</div>
							</details>
					</div>
				</div>
				<footer class="atum-log-drawer__footer">
					<button type="button" class="button button-secondary" data-atum-close="1"><?php esc_html_e( 'Close', 'atum-mailer' ); ?></button>
				</footer>
			</aside>
		</div>

			<div id="atum-danger-modal" class="atum-danger-modal" aria-hidden="true">
				<div class="atum-danger-modal__scrim" data-atum-danger-close="1"></div>
				<div class="atum-danger-modal__panel" role="dialog" aria-modal="true" aria-labelledby="atum-danger-modal-title">
					<h3 id="atum-danger-modal-title" data-atum-danger-title="1"><?php esc_html_e( 'Confirm Action', 'atum-mailer' ); ?></h3>
					<p data-atum-danger-message="1"><?php esc_html_e( 'This action cannot be undone.', 'atum-mailer' ); ?></p>
					<div class="atum-danger-modal__actions">
						<button type="button" class="button button-secondary" data-atum-danger-cancel="1"><?php esc_html_e( 'Cancel', 'atum-mailer' ); ?></button>
						<button type="button" class="button button-primary atum-danger-modal__confirm" data-atum-danger-confirm="1"><?php esc_html_e( 'Confirm', 'atum-mailer' ); ?></button>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render settings tab.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$options = $this->settings->get_options();

		$core_fields = array(
			array(
				'label'   => __( 'Postmark Server Token', 'atum-mailer' ),
				'callback'=> 'render_server_token_field',
			),
			array(
				'label'   => __( 'Default From Email', 'atum-mailer' ),
				'callback'=> 'render_from_email_field',
				'hint'    => __( 'Use a sender address verified in your Postmark account.', 'atum-mailer' ),
			),
			array(
				'label'   => __( 'Default From Name', 'atum-mailer' ),
				'callback'=> 'render_from_name_field',
			),
			array(
				'label'   => __( 'Enable Postmark Delivery', 'atum-mailer' ),
				'callback'=> 'render_enabled_field',
			),
			array(
				'label'   => __( 'Message Stream', 'atum-mailer' ),
				'callback'=> 'render_message_stream_field',
			),
		);

		$delivery_fields = array(
			array(
				'label'   => __( 'Delivery Mode', 'atum-mailer' ),
				'callback'=> 'render_delivery_mode_field',
			),
			array(
				'label'   => __( 'Fallback to Native wp_mail()', 'atum-mailer' ),
				'callback'=> 'render_fallback_to_wp_mail_field',
			),
			array(
				'label'   => __( 'Force Default From Address', 'atum-mailer' ),
				'callback'=> 'render_force_from_field',
			),
			array(
				'label'   => __( 'Track Opens', 'atum-mailer' ),
				'callback'=> 'render_track_opens_field',
			),
			array(
				'label'   => __( 'Track Links', 'atum-mailer' ),
				'callback'=> 'render_track_links_field',
			),
		);

		$advanced_security_fields = array(
			array(
				'label'   => __( 'Allow API Key Reveal', 'atum-mailer' ),
				'callback'=> 'render_allow_token_reveal_field',
			),
			array(
				'label'   => __( 'Log Detail Mode', 'atum-mailer' ),
				'callback'=> 'render_log_detail_mode_field',
			),
			array(
				'label'   => __( 'Enable Debug Logging', 'atum-mailer' ),
				'callback'=> 'render_debug_logging_field',
			),
		);

		$advanced_queue_fields = array(
			array(
				'label'   => __( 'Queue Max Attempts', 'atum-mailer' ),
				'callback'=> 'render_queue_max_attempts_field',
			),
			array(
				'label'   => __( 'Queue Base Delay (s)', 'atum-mailer' ),
				'callback'=> 'render_queue_retry_base_delay_field',
			),
			array(
				'label'   => __( 'Queue Max Delay (s)', 'atum-mailer' ),
				'callback'=> 'render_queue_retry_max_delay_field',
			),
		);

			$advanced_retention_fields = array(
				array(
					'label'   => __( 'Store Delivery Logs', 'atum-mailer' ),
					'callback'=> 'render_mail_retention_field',
				),
			array(
				'label'   => __( 'Retention Window (days)', 'atum-mailer' ),
				'callback'=> 'render_retention_days_field',
			),
				array(
					'label'   => __( 'Webhook Shared Secret', 'atum-mailer' ),
					'callback'=> 'render_postmark_webhook_secret_field',
				),
				array(
					'label'   => __( 'Require Signature Verification', 'atum-mailer' ),
					'callback'=> 'render_webhook_require_signature_field',
				),
				array(
					'label'   => __( 'Webhook Replay Window (s)', 'atum-mailer' ),
					'callback'=> 'render_webhook_replay_window_field',
				),
					array(
						'label'   => __( 'Webhook Rate Limit (/min/IP)', 'atum-mailer' ),
						'callback'=> 'render_webhook_rate_limit_field',
					),
					array(
						'label'   => __( 'Webhook Source IP Allowlist', 'atum-mailer' ),
						'callback'=> 'render_webhook_allowed_ip_ranges_field',
					),
				);
		?>
		<section class="atum-card atum-card--full atum-settings-shell">
			<form method="post" action="options.php" class="atum-settings-layout">
				<?php settings_fields( 'atum_mailer_settings' ); ?>
				<header class="atum-settings-shell__header">
					<div>
						<h2><?php esc_html_e( 'Settings Command Center', 'atum-mailer' ); ?></h2>
						<p class="atum-settings-intro"><?php esc_html_e( 'Design your mail pipeline: credentials, sender identity, queue policy, observability, and webhook trust model.', 'atum-mailer' ); ?></p>
					</div>
					<div class="atum-settings-shell__badges">
						<span class="atum-pill <?php echo esc_attr( ! empty( $options['enabled'] ) ? 'is-good' : 'is-muted' ); ?>"><?php echo ! empty( $options['enabled'] ) ? esc_html__( 'Delivery Enabled', 'atum-mailer' ) : esc_html__( 'Delivery Disabled', 'atum-mailer' ); ?></span>
						<span class="atum-pill is-muted"><?php echo esc_html( sprintf( __( 'Mode: %s', 'atum-mailer' ), ucfirst( (string) ( $options['delivery_mode'] ?? 'immediate' ) ) ) ); ?></span>
						<span class="atum-pill <?php echo esc_attr( ! empty( $options['token_verified'] ) ? 'is-good' : 'is-warn' ); ?>"><?php echo ! empty( $options['token_verified'] ) ? esc_html__( 'Token Verified', 'atum-mailer' ) : esc_html__( 'Token Unverified', 'atum-mailer' ); ?></span>
					</div>
				</header>
				<nav class="atum-settings-shell__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'atum-mailer' ); ?>">
					<a href="#atum-settings-core"><?php esc_html_e( 'Core Setup', 'atum-mailer' ); ?></a>
					<a href="#atum-settings-delivery"><?php esc_html_e( 'Delivery Behavior', 'atum-mailer' ); ?></a>
					<a href="#atum-settings-advanced"><?php esc_html_e( 'Advanced Settings', 'atum-mailer' ); ?></a>
				</nav>
				<div class="atum-settings-shell__content">
					<section id="atum-settings-core" class="atum-settings-zone">
						<?php
						$this->render_settings_card(
							__( 'Core Setup', 'atum-mailer' ),
							__( 'Complete these first for reliable transactional delivery.', 'atum-mailer' ),
							$core_fields
						);
						?>
					</section>
					<section id="atum-settings-delivery" class="atum-settings-zone">
						<?php
						$this->render_settings_card(
							__( 'Delivery Behavior', 'atum-mailer' ),
							__( 'Choose real-time or queued delivery and tracking behavior.', 'atum-mailer' ),
							$delivery_fields
						);
						?>
					</section>
					<details class="atum-settings-disclosure" id="atum-settings-advanced">
						<summary><?php esc_html_e( 'Advanced Settings', 'atum-mailer' ); ?></summary>
						<div class="atum-settings-grid atum-settings-grid--advanced">
							<?php
							$this->render_settings_card(
								__( 'Security & Privacy', 'atum-mailer' ),
								__( 'Control sensitive value visibility and data stored in logs.', 'atum-mailer' ),
								$advanced_security_fields
							);
							$this->render_settings_card(
								__( 'Queue Tuning', 'atum-mailer' ),
								__( 'Adjust retry policy for transient provider outages.', 'atum-mailer' ),
								$advanced_queue_fields
							);
							$this->render_settings_card(
								__( 'Retention & Webhooks', 'atum-mailer' ),
								__( 'Set data retention and webhook integration details.', 'atum-mailer' ),
								$advanced_retention_fields
							);
							?>
						</div>
					</details>
				</div>
				<div class="atum-settings-shell__footer">
					<?php submit_button( __( 'Save Settings', 'atum-mailer' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Render one settings card.
	 *
	 * @param string                     $title Card title.
	 * @param string                     $description Card summary.
	 * @param array<int, array<string, string>> $fields Field rows.
	 * @return void
	 */
	private function render_settings_card( $title, $description, $fields ) {
		$heading_id = 'atum-settings-card-' . substr( md5( $title ), 0, 8 );
		?>
		<section class="atum-settings-card" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<header class="atum-settings-card__header">
				<h3 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $title ); ?></h3>
				<p><?php echo esc_html( $description ); ?></p>
			</header>
			<div class="atum-settings-card__body">
				<?php foreach ( $fields as $field ) : ?>
					<?php
					$label    = (string) ( $field['label'] ?? '' );
					$callback = (string) ( $field['callback'] ?? '' );
					$hint     = (string) ( $field['hint'] ?? '' );
					$this->render_settings_field_row( $label, $callback, $hint );
					?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render one settings field row.
	 *
	 * @param string $label Field label.
	 * @param string $callback Callback method.
	 * @param string $hint Optional hint text.
	 * @return void
	 */
	private function render_settings_field_row( $label, $callback, $hint = '' ) {
		if ( '' === $callback || ! is_callable( array( $this, $callback ) ) ) {
			return;
		}
		?>
		<div class="atum-settings-row">
			<div class="atum-settings-row__head">
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( '' !== $hint ) : ?>
					<p><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</div>
			<div class="atum-settings-row__control">
				<?php call_user_func( array( $this, $callback ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render delivery section help.
	 *
	 * @return void
	 */
	public function render_delivery_section_help() {
		echo '<p>' . esc_html__( 'Configure Postmark delivery and sender identity for this site.', 'atum-mailer' ) . '</p>';
	}

	/**
	 * Render security section help.
	 *
	 * @return void
	 */
	public function render_security_section_help() {
		echo '<p>' . esc_html__( 'Set sensitive data handling defaults for logs and token visibility.', 'atum-mailer' ) . '</p>';
	}

	/**
	 * Render reliability section help.
	 *
	 * @return void
	 */
	public function render_reliability_section_help() {
		echo '<p>' . esc_html__( 'Tune queue behavior and retry policies for transient provider outages.', 'atum-mailer' ) . '</p>';
	}

	/**
	 * Render retention section help.
	 *
	 * @return void
	 */
	public function render_retention_section_help() {
		echo '<p>' . esc_html__( 'Keep a local history of sent/failed/bypassed messages inside WordPress.', 'atum-mailer' ) . '</p>';
	}

	/**
	 * Render webhook section help.
	 *
	 * @return void
	 */
	public function render_webhook_section_help() {
		$route = rest_url( 'atum-mailer/v1/postmark/webhook' );
		echo '<p>' . esc_html__( 'Optional Postmark webhook endpoint. Set a shared secret and configure Postmark to send events to this URL.', 'atum-mailer' ) . '</p>';
		echo '<p>' . esc_html__( 'For hardened deployments, require signatures and set source IP allowlist ranges. If behind a trusted proxy/CDN, enable forwarded-IP trust only when edge headers are protected.', 'atum-mailer' ) . '</p>';
		echo '<p><code>' . esc_html( $route ) . '</code></p>';
	}

	/**
	 * Render generic checkbox.
	 *
	 * @param string $name Key.
	 * @param string $label Label.
	 * @return void
	 */
	private function render_checkbox_field( $name, $label ) {
		$options = $this->settings->get_options();
		$value   = empty( $options[ $name ] ) ? 0 : 1;
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[' . $name . ']' ); ?>" value="1" <?php checked( 1, $value ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * Render enabled field.
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		echo '<div id="atum-field-enabled">';
		$this->render_checkbox_field( 'enabled', __( 'Route WordPress mail through Postmark.', 'atum-mailer' ) );
		echo '</div>';
	}

	/**
	 * Render token field.
	 *
	 * @return void
	 */
	public function render_server_token_field() {
		$options      = $this->settings->get_options();
		$token        = (string) $options['postmark_server_token'];
		$has_key      = '' !== $token;
		$allow_reveal = ! empty( $options['allow_token_reveal'] );
		echo '<div id="atum-field-postmark-token">';

		if ( ! $has_key ) :
			?>
			<div class="atum-api-card atum-api-card--connect">
				<h4><?php esc_html_e( 'Connect Postmark', 'atum-mailer' ); ?></h4>
				<p><?php esc_html_e( 'Sign in your API key and verify it before delivery is enabled.', 'atum-mailer' ); ?></p>
				<input type="hidden" name="atum_mailer_connect_nonce" value="<?php echo esc_attr( wp_create_nonce( 'atum_mailer_connect_token' ) ); ?>" />
				<div class="atum-api-form">
					<input type="password" name="postmark_server_token" class="regular-text" autocomplete="new-password" />
					<button type="submit" class="button button-primary" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=atum_mailer_connect_token' ) ); ?>" formmethod="post" name="action" value="atum_mailer_connect_token"><?php esc_html_e( 'Connect and Verify', 'atum-mailer' ); ?></button>
				</div>
			</div>
			<?php
			echo '</div>';
			return;
		endif;

		$is_verified = ! empty( $options['token_verified'] );
		$masked      = $this->mask_token( $token );
		$verified_at = (string) $options['token_verified_at'];
		$server_name = (string) $options['token_server_name'];
		$last_error  = (string) $options['token_last_error'];
		?>
		<div class="atum-api-card <?php echo esc_attr( $is_verified ? 'is-verified' : 'is-unverified' ); ?>">
			<div class="atum-api-card__head">
				<span class="atum-api-badge <?php echo esc_attr( $is_verified ? 'is-verified' : 'is-unverified' ); ?>"><?php echo esc_html( $is_verified ? __( 'Verified API key', 'atum-mailer' ) : __( 'Unverified API key', 'atum-mailer' ) ); ?></span>
				<?php if ( '' !== $server_name ) : ?><span class="atum-api-meta"><?php echo esc_html( sprintf( __( 'Server: %s', 'atum-mailer' ), $server_name ) ); ?></span><?php endif; ?>
			</div>
			<div class="atum-api-card__token-row">
				<input id="atum-postmark-token-field" class="regular-text atum-api-token" type="password" readonly value="<?php echo esc_attr( $masked ); ?>" data-masked="<?php echo esc_attr( $masked ); ?>" />
				<button type="button" class="button button-secondary atum-token-reveal" id="atum-token-reveal" <?php disabled( ! $allow_reveal ); ?>><?php esc_html_e( 'Show key', 'atum-mailer' ); ?></button>
			</div>
			<p class="description">
				<?php
				if ( '' !== $verified_at ) {
					echo esc_html( sprintf( __( 'Last verified: %s', 'atum-mailer' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $verified_at ) ) );
				} else {
					esc_html_e( 'Key is stored but has not been verified yet.', 'atum-mailer' );
				}
				?>
			</p>
			<?php if ( ! $allow_reveal ) : ?><p class="description"><?php esc_html_e( 'Token reveal is disabled. Enable it in Security & Privacy settings if needed.', 'atum-mailer' ); ?></p><?php endif; ?>
			<?php if ( '' !== $last_error ) : ?><p class="description atum-api-error"><?php echo esc_html( $last_error ); ?></p><?php endif; ?>
			<input type="hidden" name="atum_mailer_connect_nonce" value="<?php echo esc_attr( wp_create_nonce( 'atum_mailer_connect_token' ) ); ?>" />
			<input type="hidden" name="atum_mailer_disconnect_nonce" value="<?php echo esc_attr( wp_create_nonce( 'atum_mailer_disconnect_token' ) ); ?>" />
			<div class="atum-api-card__actions">
				<button type="submit" class="button button-secondary" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=atum_mailer_connect_token' ) ); ?>" formmethod="post" name="action" value="atum_mailer_connect_token"><?php esc_html_e( 'Verify again', 'atum-mailer' ); ?></button>
				<button type="submit" class="button button-secondary atum-button-danger" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=atum_mailer_disconnect_token' ) ); ?>" formmethod="post" name="action" value="atum_mailer_disconnect_token"><?php esc_html_e( 'Disconnect', 'atum-mailer' ); ?></button>
			</div>
		</div>
		<?php
		echo '</div>';
	}

	/**
	 * Mask token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function mask_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return '';
		}
		$length = strlen( $token );
		if ( $length <= 8 ) {
			return str_repeat( '*', $length );
		}
		return substr( $token, 0, 4 ) . str_repeat( '*', max( 4, $length - 8 ) ) . substr( $token, -4 );
	}

	/**
	 * Render message stream field.
	 *
	 * @return void
	 */
	public function render_message_stream_field() {
		$options = $this->settings->get_options();
		$choices = $this->get_message_stream_choices( $options );
		?>
		<select name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[message_stream]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['message_stream'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Choose from available Postmark streams for this token.', 'atum-mailer' ); ?></p>
		<?php
	}

	/**
	 * Build stream choices.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array<string, string>
	 */
	private function get_message_stream_choices( $options ) {
		$streams = ! empty( $options['available_streams'] ) && is_array( $options['available_streams'] ) ? $options['available_streams'] : array( 'outbound' );
		$current = (string) ( $options['message_stream'] ?? '' );
		if ( '' !== $current && ! in_array( $current, $streams, true ) ) {
			$streams[] = $current;
		}

		$choices = array();
		foreach ( $streams as $stream ) {
			$stream = sanitize_text_field( (string) $stream );
			if ( '' === $stream ) {
				continue;
			}
			$choices[ $stream ] = ucwords( str_replace( array( '-', '_' ), ' ', $stream ) );
		}

		return $choices;
	}

	/**
	 * Render from email field.
	 *
	 * @return void
	 */
	public function render_from_email_field() {
		$options = $this->settings->get_options();
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host    = preg_replace( '/^www\./i', '', $host );
		$host    = is_string( $host ) ? $host : '';
		$quick   = array();
		if ( '' !== $host ) {
			$quick = array( 'no-reply@' . $host, 'hello@' . $host, 'support@' . $host, 'info@' . $host );
		}
		?>
		<div id="atum-field-from-email">
		<div class="atum-from-builder">
			<input type="email" id="atum-from-email-field" class="regular-text" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[from_email]' ); ?>" value="<?php echo esc_attr( $options['from_email'] ); ?>" />
			<?php if ( ! empty( $quick ) ) : ?>
				<div class="atum-from-builder__quick">
					<?php foreach ( $quick as $email ) : ?>
						<button type="button" class="button button-secondary atum-quick-fill-email" data-email="<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="atum-from-builder__compose">
					<input type="text" id="atum-from-local-part" placeholder="<?php esc_attr_e( 'local-part', 'atum-mailer' ); ?>" />
					<span>@<?php echo esc_html( $host ); ?></span>
					<button type="button" class="button button-secondary" id="atum-from-build"><?php esc_html_e( 'Use', 'atum-mailer' ); ?></button>
				</div>
			<?php endif; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Must be a verified sender or domain in Postmark.', 'atum-mailer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render from name field.
	 *
	 * @return void
	 */
	public function render_from_name_field() {
		$options = $this->settings->get_options();
		?><input type="text" class="regular-text" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[from_name]' ); ?>" value="<?php echo esc_attr( $options['from_name'] ); ?>" /><?php
	}

	/**
	 * Render force from field.
	 *
	 * @return void
	 */
	public function render_force_from_field() {
		$this->render_checkbox_field( 'force_from', __( 'Always use the default sender above.', 'atum-mailer' ) );
	}

	/**
	 * Render track opens field.
	 *
	 * @return void
	 */
	public function render_track_opens_field() {
		$this->render_checkbox_field( 'track_opens', __( 'Enable open tracking when supported.', 'atum-mailer' ) );
	}

	/**
	 * Render track links field.
	 *
	 * @return void
	 */
	public function render_track_links_field() {
		$options = $this->settings->get_options();
		$current = $options['track_links'];
		$choices = array(
			'None'        => __( 'Disabled', 'atum-mailer' ),
			'HtmlAndText' => __( 'Track HTML and Text', 'atum-mailer' ),
			'HtmlOnly'    => __( 'Track HTML Only', 'atum-mailer' ),
			'TextOnly'    => __( 'Track Text Only', 'atum-mailer' ),
		);
		?>
		<select name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[track_links]' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render debug logging field.
	 *
	 * @return void
	 */
	public function render_debug_logging_field() {
		$this->render_checkbox_field( 'debug_logging', __( 'Write API failures to PHP error log.', 'atum-mailer' ) );
	}

	/**
	 * Render allow token reveal field.
	 *
	 * @return void
	 */
	public function render_allow_token_reveal_field() {
		$this->render_checkbox_field( 'allow_token_reveal', __( 'Permit explicit token reveal (requires confirmation).', 'atum-mailer' ) );
	}

	/**
	 * Render log detail mode field.
	 *
	 * @return void
	 */
	public function render_log_detail_mode_field() {
		$options = $this->settings->get_options();
		$current = (string) $options['log_detail_mode'];
		?>
		<select name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[log_detail_mode]' ); ?>">
			<option value="metadata" <?php selected( $current, 'metadata' ); ?>><?php esc_html_e( 'Metadata only (recommended)', 'atum-mailer' ); ?></option>
			<option value="full" <?php selected( $current, 'full' ); ?>><?php esc_html_e( 'Full payload/body', 'atum-mailer' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Metadata mode reduces sensitive data persisted in DB logs.', 'atum-mailer' ); ?></p>
		<?php
	}

	/**
	 * Render delivery mode field.
	 *
	 * @return void
	 */
	public function render_delivery_mode_field() {
		$options = $this->settings->get_options();
		$current = (string) $options['delivery_mode'];
		?>
		<div id="atum-field-delivery-mode">
		<select name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[delivery_mode]' ); ?>">
			<option value="immediate" <?php selected( $current, 'immediate' ); ?>><?php esc_html_e( 'Immediate', 'atum-mailer' ); ?></option>
			<option value="queue" <?php selected( $current, 'queue' ); ?>><?php esc_html_e( 'Queue with retries', 'atum-mailer' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Queue mode stores pending jobs and retries transient Postmark failures.', 'atum-mailer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render native fallback field.
	 *
	 * @return void
	 */
	public function render_fallback_to_wp_mail_field() {
		$this->render_checkbox_field( 'fallback_to_wp_mail', __( 'On retryable provider outages, allow WordPress native mail transport as fallback.', 'atum-mailer' ) );
	}

	/**
	 * Render queue max attempts field.
	 *
	 * @return void
	 */
	public function render_queue_max_attempts_field() {
		$options = $this->settings->get_options();
		?><input type="number" min="1" max="20" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[queue_max_attempts]' ); ?>" value="<?php echo esc_attr( (string) $options['queue_max_attempts'] ); ?>" /><?php
	}

	/**
	 * Render queue base delay field.
	 *
	 * @return void
	 */
	public function render_queue_retry_base_delay_field() {
		$options = $this->settings->get_options();
		?><input type="number" min="5" max="3600" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[queue_retry_base_delay]' ); ?>" value="<?php echo esc_attr( (string) $options['queue_retry_base_delay'] ); ?>" /><?php
	}

	/**
	 * Render queue max delay field.
	 *
	 * @return void
	 */
	public function render_queue_retry_max_delay_field() {
		$options = $this->settings->get_options();
		?><input type="number" min="60" max="86400" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[queue_retry_max_delay]' ); ?>" value="<?php echo esc_attr( (string) $options['queue_retry_max_delay'] ); ?>" /><?php
	}

	/**
	 * Render retention enabled field.
	 *
	 * @return void
	 */
	public function render_mail_retention_field() {
		$this->render_checkbox_field( 'mail_retention', __( 'Store retained mail logs in this site database.', 'atum-mailer' ) );
	}

	/**
	 * Render retention days field.
	 *
	 * @return void
	 */
	public function render_retention_days_field() {
		$options = $this->settings->get_options();
		?>
		<input type="number" min="1" max="3650" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[retention_days]' ); ?>" value="<?php echo esc_attr( (string) $options['retention_days'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Logs older than this window are purged by daily cron.', 'atum-mailer' ); ?></p>
		<?php
	}

	/**
	 * Render webhook secret field.
	 *
	 * @return void
	 */
	public function render_postmark_webhook_secret_field() {
		$options = $this->settings->get_options();
		$has_secret = '' !== trim( (string) ( $options['postmark_webhook_secret'] ?? '' ) );
		?>
		<div id="atum-field-webhook-secret">
			<input type="password" class="regular-text" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[postmark_webhook_secret]' ); ?>" value="" autocomplete="new-password" />
			<?php if ( $has_secret ) : ?>
				<p class="description"><?php esc_html_e( 'A webhook secret is already saved. Leave blank to keep it unchanged.', 'atum-mailer' ); ?></p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[postmark_webhook_secret_clear]' ); ?>" value="1" />
					<?php esc_html_e( 'Clear saved webhook secret', 'atum-mailer' ); ?>
				</label>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Set a high-entropy shared secret and configure the same value on your webhook sender.', 'atum-mailer' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render webhook signature requirement field.
	 *
	 * @return void
	 */
	public function render_webhook_require_signature_field() {
		?><div id="atum-field-webhook-require-signature"><?php
		$this->render_checkbox_field( 'webhook_require_signature', __( 'Require HMAC signature + timestamp headers on webhook requests.', 'atum-mailer' ) );
		echo '</div>';
	}

	/**
	 * Render webhook replay window field.
	 *
	 * @return void
	 */
	public function render_webhook_replay_window_field() {
		$options = $this->settings->get_options();
		?>
		<div id="atum-field-webhook-replay-window">
			<input type="number" min="30" max="86400" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[webhook_replay_window_seconds]' ); ?>" value="<?php echo esc_attr( (string) $options['webhook_replay_window_seconds'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Signed webhook timestamps outside this window are rejected.', 'atum-mailer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render webhook rate-limit field.
	 *
	 * @return void
	 */
	public function render_webhook_rate_limit_field() {
		$options = $this->settings->get_options();
		?>
		<div id="atum-field-webhook-rate-limit">
			<input type="number" min="1" max="5000" step="1" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[webhook_rate_limit_per_minute]' ); ?>" value="<?php echo esc_attr( (string) $options['webhook_rate_limit_per_minute'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Per-IP webhook request cap per minute.', 'atum-mailer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render webhook source allowlist field.
	 *
	 * @return void
	 */
	public function render_webhook_allowed_ip_ranges_field() {
		$options = $this->settings->get_options();
		$value   = (string) ( $options['webhook_allowed_ip_ranges'] ?? '' );
		?>
		<div id="atum-field-webhook-allowlist">
			<textarea rows="4" class="large-text code" name="<?php echo esc_attr( Atum_Mailer_Settings_Repository::OPTION_KEY . '[webhook_allowed_ip_ranges]' ); ?>" placeholder="203.0.113.10&#10;203.0.113.0/24&#10;2001:db8::/32"><?php echo esc_html( $value ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Optional. One IP or CIDR range per line. When set, webhook requests from non-allowlisted sources are rejected.', 'atum-mailer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Parse log filters from request source.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @return array<string, string>
	 */
	private function parse_log_filters( $source ) {
		$source = is_array( $source ) ? $source : array();
		$filters = array(
			'status'              => sanitize_key( (string) wp_unslash( $source['status'] ?? 'all' ) ),
			's'                   => sanitize_text_field( wp_unslash( $source['s'] ?? '' ) ),
			'date_from'           => sanitize_text_field( wp_unslash( $source['date_from'] ?? '' ) ),
			'date_to'             => sanitize_text_field( wp_unslash( $source['date_to'] ?? '' ) ),
			'delivery_mode'       => sanitize_key( (string) wp_unslash( $source['delivery_mode'] ?? 'all' ) ),
			'retry_state'         => sanitize_key( (string) wp_unslash( $source['retry_state'] ?? 'all' ) ),
			'provider_message_id' => sanitize_text_field( wp_unslash( $source['provider_message_id'] ?? '' ) ),
		);

		if ( ! in_array( $filters['status'], $this->allowed_statuses(), true ) ) {
			$filters['status'] = 'all';
		}
		if ( ! in_array( $filters['delivery_mode'], array( 'all', 'immediate', 'queue' ), true ) ) {
			$filters['delivery_mode'] = 'all';
		}
		if ( ! in_array( $filters['retry_state'], array( 'all', 'retrying', 'retried', 'terminal' ), true ) ) {
			$filters['retry_state'] = 'all';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] ) ) {
			$filters['date_from'] = '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] ) ) {
			$filters['date_to'] = '';
		}

		return $filters;
	}

	/**
	 * Build query args from active log filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, string>
	 */
	private function build_logs_filter_args( $filters ) {
		$filters = $this->parse_log_filters( $filters );
		$args    = array(
			'status' => $filters['status'],
		);

		if ( '' !== $filters['s'] ) {
			$args['s'] = $filters['s'];
		}
		if ( '' !== $filters['date_from'] ) {
			$args['date_from'] = $filters['date_from'];
		}
		if ( '' !== $filters['date_to'] ) {
			$args['date_to'] = $filters['date_to'];
		}
		if ( 'all' !== $filters['delivery_mode'] ) {
			$args['delivery_mode'] = $filters['delivery_mode'];
		}
		if ( 'all' !== $filters['retry_state'] ) {
			$args['retry_state'] = $filters['retry_state'];
		}
		if ( '' !== $filters['provider_message_id'] ) {
			$args['provider_message_id'] = $filters['provider_message_id'];
		}

		return $args;
	}

	/**
	 * Render hidden filter fields for forms.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return void
	 */
	private function render_log_filter_hidden_inputs( $filters ) {
		foreach ( $this->build_logs_filter_args( $filters ) as $key => $value ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php
		}
	}

	/**
	 * Build logs tab URL with optional filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	private function logs_tab_url( $filters = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => Atum_Mailer::PAGE_SLUG,
					'tab'  => 'logs',
				),
				$this->build_logs_filter_args( $filters )
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Parse selected log IDs from array/csv payloads.
	 *
	 * @param mixed  $raw_ids ID array.
	 * @param string $raw_csv CSV IDs.
	 * @return array<int, int>
	 */
	private function parse_log_ids( $raw_ids, $raw_csv = '' ) {
		$ids = array();
		if ( is_array( $raw_ids ) ) {
			$ids = $raw_ids;
		}

		$csv = trim( (string) $raw_csv );
		if ( '' !== $csv ) {
			$ids = array_merge( $ids, explode( ',', $csv ) );
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Apply resend overrides with guardrails.
	 *
	 * @param array<string, mixed> $payload Original payload.
	 * @param string               $resend_to_raw Recipient override list.
	 * @param string               $resend_subject Subject override.
	 * @param int                  $source_log_id Source log id.
	 * @return array<string, mixed>|WP_Error
	 */
	private function apply_resend_overrides( $payload, $resend_to_raw, $resend_subject, $source_log_id ) {
		$payload = is_array( $payload ) ? $payload : array();

		$resend_to_raw = trim( (string) $resend_to_raw );
		if ( '' !== $resend_to_raw ) {
			$emails = $this->parse_email_list( $resend_to_raw );
			if ( empty( $emails ) ) {
				return new WP_Error( 'atum_mailer_invalid_resend_to', __( 'Enter at least one valid recipient email address for resend override.', 'atum-mailer' ) );
			}
			if ( count( $emails ) > 20 ) {
				return new WP_Error( 'atum_mailer_too_many_resend_recipients', __( 'Recipient override supports up to 20 addresses per resend.', 'atum-mailer' ) );
			}

			$payload['To'] = implode( ',', $emails );
			unset( $payload['Cc'], $payload['Bcc'] );
		}

		$resend_subject = trim( (string) $resend_subject );
		if ( '' !== $resend_subject ) {
			$payload['Subject'] = substr( sanitize_text_field( $resend_subject ), 0, 200 );
		}

		$headers = array();
		if ( isset( $payload['Headers'] ) && is_array( $payload['Headers'] ) ) {
			$headers = $payload['Headers'];
		}
		$headers[] = array(
			'Name'  => 'X-Atum-Mailer-Replay-Source',
			'Value' => 'log:' . absint( $source_log_id ),
		);
		$payload['Headers'] = $headers;

		return $payload;
	}

	/**
	 * Decode full recipients CSV from stored mail_to payload.
	 *
	 * @param string $mail_to Encoded recipients.
	 * @return string
	 */
	private function recipients_csv_from_mail_to( $mail_to ) {
		$decoded = json_decode( (string) $mail_to, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$emails = array();
		foreach ( $decoded as $entry ) {
			$email = sanitize_email( (string) $entry );
			if ( '' !== $email ) {
				$emails[] = $email;
			}
		}

		return implode( ',', array_values( array_unique( $emails ) ) );
	}

	/**
	 * Human label for webhook event types.
	 *
	 * @param string $event_type Event type.
	 * @return string
	 */
	private function webhook_event_label( $event_type ) {
		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) {
			return '';
		}

		switch ( $event_type ) {
			case 'delivery':
				return __( 'Delivered', 'atum-mailer' );
			case 'open':
				return __( 'Opened', 'atum-mailer' );
			case 'click':
				return __( 'Clicked', 'atum-mailer' );
			case 'bounce':
				return __( 'Bounced', 'atum-mailer' );
			case 'spamcomplaint':
				return __( 'Complaint', 'atum-mailer' );
			case 'subscriptionchange':
				return __( 'Subscription Change', 'atum-mailer' );
			default:
				return ucwords( str_replace( array( '-', '_' ), ' ', $event_type ) );
		}
	}

	/**
	 * Visual tone for webhook events.
	 *
	 * @param string $event_type Event type.
	 * @return string
	 */
	private function webhook_event_tone( $event_type ) {
		$event_type = sanitize_key( (string) $event_type );
		if ( in_array( $event_type, array( 'delivery', 'open', 'click' ), true ) ) {
			return 'good';
		}
		if ( in_array( $event_type, array( 'bounce', 'spamcomplaint' ), true ) ) {
			return 'bad';
		}
		return 'warn';
	}

	/**
	 * Build timeline entries for log detail drawer.
	 *
	 * @param object $log Log row.
	 * @return array<int, array<string, string>>
	 */
	private function build_log_timeline( $log ) {
		$status      = sanitize_key( (string) ( $log->status ?? '' ) );
		$mode        = sanitize_key( (string) ( $log->delivery_mode ?? 'immediate' ) );
		$attempts    = absint( $log->attempt_count ?? 0 );
		$created_at  = (string) ( $log->created_at ?? '' );
		$updated_at  = (string) ( $log->updated_at ?? '' );
		$next_try_at = (string) ( $log->next_attempt_at ?? '' );
		$error_code  = sanitize_key( (string) ( $log->last_error_code ?? '' ) );
		$provider_id = sanitize_text_field( (string) ( $log->provider_message_id ?? '' ) );
		$webhook     = sanitize_key( (string) ( $log->webhook_event_type ?? '' ) );
		$items       = array();

		$items[] = $this->make_timeline_item(
			'created',
			'queue' === $mode ? __( 'Queued for delivery', 'atum-mailer' ) : __( 'Delivery requested', 'atum-mailer' ),
			$created_at,
			sprintf( __( 'Mode: %s', 'atum-mailer' ), ucfirst( $mode ) ),
			'muted'
		);

		if ( $attempts > 0 ) {
			$items[] = $this->make_timeline_item(
				'attempt',
				sprintf(
					/* translators: %d = attempt count */
					_n( '%d send attempt', '%d send attempts', $attempts, 'atum-mailer' ),
					$attempts
				),
				$updated_at,
				$attempts > 1 ? __( 'Automatic retries were applied.', 'atum-mailer' ) : __( 'First delivery attempt executed.', 'atum-mailer' ),
				$attempts > 1 ? 'warn' : 'good'
			);
		}

		if ( 'retrying' === $status || '' !== $next_try_at ) {
			$retry_detail = '' !== $next_try_at
				? sprintf( __( 'Next attempt: %s', 'atum-mailer' ), $this->format_log_datetime( $next_try_at ) )
				: __( 'Waiting for next retry window.', 'atum-mailer' );
			if ( '' !== $error_code ) {
				$retry_detail .= ' ' . sprintf( __( 'Last error: %s', 'atum-mailer' ), $error_code );
			}

			$items[] = $this->make_timeline_item(
				'retrying',
				__( 'Retry scheduled', 'atum-mailer' ),
				$next_try_at,
				$retry_detail,
				'warn'
			);
		}

		if ( '' !== $provider_id ) {
			$items[] = $this->make_timeline_item(
				'provider_accept',
				__( 'Accepted by Postmark', 'atum-mailer' ),
				$updated_at,
				sprintf( __( 'Provider message ID: %s', 'atum-mailer' ), $provider_id ),
				'good'
			);
		}

		if ( '' !== $webhook ) {
			$items[] = $this->make_timeline_item(
				'webhook',
				sprintf( __( 'Webhook event: %s', 'atum-mailer' ), $this->webhook_event_label( $webhook ) ),
				$updated_at,
				__( 'Postmark webhook status received.', 'atum-mailer' ),
				$this->webhook_event_tone( $webhook )
			);
		}

		$terminal_labels = array(
			'sent'       => __( 'Sent to provider', 'atum-mailer' ),
			'delivered'  => __( 'Delivery confirmed', 'atum-mailer' ),
			'failed'     => __( 'Delivery failed', 'atum-mailer' ),
			'dead_letter'=> __( 'Moved to dead letter', 'atum-mailer' ),
			'bypassed'   => __( 'Bypassed to native mail transport', 'atum-mailer' ),
		);

		if ( isset( $terminal_labels[ $status ] ) ) {
			$tone = in_array( $status, array( 'failed', 'dead_letter' ), true ) ? 'bad' : 'good';
			if ( 'bypassed' === $status ) {
				$tone = 'warn';
			}

			$items[] = $this->make_timeline_item(
				$status,
				$terminal_labels[ $status ],
				$updated_at,
				'',
				$tone
			);
		}

		return array_values( $items );
	}

	/**
	 * Build one timeline item.
	 *
	 * @param string $type Item type.
	 * @param string $label Label.
	 * @param string $timestamp Timestamp.
	 * @param string $detail Detail.
	 * @param string $tone Visual tone.
	 * @return array<string, string>
	 */
	private function make_timeline_item( $type, $label, $timestamp = '', $detail = '', $tone = 'muted' ) {
		$tone = sanitize_key( (string) $tone );
		if ( ! in_array( $tone, array( 'good', 'warn', 'bad', 'muted' ), true ) ) {
			$tone = 'muted';
		}

		return array(
			'type'      => sanitize_key( (string) $type ),
			'label'     => sanitize_text_field( (string) $label ),
			'time'      => '' !== (string) $timestamp ? $this->format_log_datetime( (string) $timestamp ) : '',
			'detail'    => sanitize_text_field( (string) $detail ),
			'tone'      => $tone,
		);
	}

	/**
	 * Format log datetime for display.
	 *
	 * @param string $datetime Datetime string.
	 * @return string
	 */
	private function format_log_datetime( $datetime ) {
		$datetime = sanitize_text_field( (string) $datetime );
		if ( '' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );
		if ( false === $timestamp ) {
			return $datetime;
		}

		$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
		if ( '' === $format ) {
			$format = 'Y-m-d H:i:s';
		}

		return mysql2date( $format, gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	/**
	 * Parse arbitrary email list.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int, string>
	 */
	private function parse_email_list( $raw ) {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} else {
			$normalized = str_replace( array( "\r\n", "\n", ';' ), ',', (string) $raw );
			$list       = explode( ',', $normalized );
		}

		$emails = array();
		foreach ( $list as $entry ) {
			$email = sanitize_email( trim( (string) $entry ) );
			if ( '' !== $email && is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Convert JSON-ish text to readable output.
	 *
	 * @param string|null $value Value.
	 * @return string
	 */
	private function pretty_json_string( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}
		$decoded = json_decode( $value, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $value;
		}
		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT );
	}

	/**
	 * Escape cells that could trigger spreadsheet formulas.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private function csv_safe_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\\s]*[=+\\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Format age in seconds to human text.
	 *
	 * @param int $seconds Age in seconds.
	 * @return string
	 */
	private function format_age_seconds( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) {
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'atum-mailer' ), $seconds );
		}

		$minutes = (int) floor( $seconds / 60 );
		if ( $minutes < 60 ) {
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'atum-mailer' ), $minutes );
		}

		$hours = (int) floor( $minutes / 60 );
		if ( $hours < 24 ) {
			return sprintf( _n( '%d hour', '%d hours', $hours, 'atum-mailer' ), $hours );
		}

		$days = (int) floor( $hours / 24 );
		return sprintf( _n( '%d day', '%d days', $days, 'atum-mailer' ), $days );
	}

	/**
	 * Redirect helper for notices.
	 *
	 * @param string $tab Tab.
	 * @param string $type Type.
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect_with_notice( $tab, $type, $message, $link = '', $label = '' ) {
		$args = array(
			'page'                => Atum_Mailer::PAGE_SLUG,
			'tab'                 => $tab,
			'atum_mailer_notice'  => $type,
			'atum_mailer_message' => $message,
		);
		if ( '' !== $link ) {
			$args['atum_mailer_notice_link']  = $link;
			$args['atum_mailer_notice_label'] = '' !== $label ? $label : __( 'Open', 'atum-mailer' );
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Allowed status filters.
	 *
	 * @return array<int, string>
	 */
	private function allowed_statuses() {
		return array( 'all', 'processing', 'sent', 'failed', 'bypassed', 'queued', 'retrying', 'delivered', 'dead_letter' );
	}

	/**
	 * Reveal transient key helper.
	 *
	 * @param string $session Session key.
	 * @return string
	 */
	private function reveal_transient_key( $session ) {
		return 'atum_mailer_reveal_' . md5( $session );
	}
}
