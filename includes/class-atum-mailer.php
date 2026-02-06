<?php
/**
 * Compatibility facade for atum.mailer plugin runtime.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Atum_Mailer {
	/**
	 * Option storage key.
	 */
	const OPTION_KEY = Atum_Mailer_Settings_Repository::OPTION_KEY;

	/**
	 * Dedicated option for Postmark token.
	 */
	const TOKEN_OPTION_KEY = Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY;

	/**
	 * DB version option key.
	 */
	const DB_VERSION_OPTION = Atum_Mailer_Log_Repository::DB_VERSION_OPTION;

	/**
	 * DB version.
	 */
	const DB_VERSION = Atum_Mailer_Log_Repository::DB_VERSION;

	/**
	 * Last cleanup option key.
	 */
	const LAST_CLEANUP_OPTION = Atum_Mailer_Settings_Repository::LAST_CLEANUP_OPTION;

	/**
	 * Logs table suffix.
	 */
	const LOG_TABLE_SUFFIX = Atum_Mailer_Log_Repository::LOG_TABLE_SUFFIX;

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'atum.mailer';

	/**
	 * Singleton instance.
	 *
	 * @var Atum_Mailer|null
	 */
	private static $instance = null;

	/**
	 * Bootstrap instance.
	 *
	 * @var Atum_Mailer_Bootstrap
	 */
	private $bootstrap;

	/**
	 * Get singleton instance.
	 *
	 * @return Atum_Mailer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		Atum_Mailer_Bootstrap::activate();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Atum_Mailer_Bootstrap::deactivate();
	}

	/**
	 * Backward-compat default options helper.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_options() {
		$settings = new Atum_Mailer_Settings_Repository();
		return $settings->default_options();
	}

	/**
	 * Backward-compat table name helper.
	 *
	 * @return string
	 */
	public static function table_name() {
		return Atum_Mailer_Log_Repository::table_name();
	}

	/**
	 * Backward-compat DB upgrade helper.
	 *
	 * @param bool $force Force upgrade.
	 * @return void
	 */
	public static function maybe_upgrade_database( $force = false ) {
		$settings = new Atum_Mailer_Settings_Repository();
		$logs     = new Atum_Mailer_Log_Repository( $settings );
		$logs->maybe_upgrade_database( $force );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->bootstrap = new Atum_Mailer_Bootstrap();
		$this->bootstrap->register_hooks();
	}
}
