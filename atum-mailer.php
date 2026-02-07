<?php
/**
 * Plugin Name:       atum.mailer
 * Plugin URI:        https://atum.tech
 * Description:       Replaces default WordPress mail delivery with Postmark for transactional email.
 * Version:           0.5.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Update URI:        https://github.com/atum/atum-mailer
 * Author:            atum.tech
 * Author URI:        https://atum.tech
 * License:           MIT
 * License URI:       https://opensource.org/license/mit/
 * Text Domain:       atum-mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATUM_MAILER_VERSION', '0.5.3' );
define( 'ATUM_MAILER_FILE', __FILE__ );
define( 'ATUM_MAILER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATUM_MAILER_URL', plugin_dir_url( __FILE__ ) );

require_once ATUM_MAILER_DIR . 'includes/SettingsRepository.php';
require_once ATUM_MAILER_DIR . 'includes/LogRepository.php';
require_once ATUM_MAILER_DIR . 'includes/contracts/DeliveryProviderInterface.php';
require_once ATUM_MAILER_DIR . 'includes/contracts/QueueRepositoryInterface.php';
require_once ATUM_MAILER_DIR . 'includes/PostmarkClient.php';
require_once ATUM_MAILER_DIR . 'includes/OptionQueueRepository.php';
require_once ATUM_MAILER_DIR . 'includes/DbQueueRepository.php';
require_once ATUM_MAILER_DIR . 'includes/MailInterceptor.php';
require_once ATUM_MAILER_DIR . 'includes/AdminController.php';
require_once ATUM_MAILER_DIR . 'includes/CliCommand.php';
require_once ATUM_MAILER_DIR . 'includes/GitHubUpdater.php';
require_once ATUM_MAILER_DIR . 'includes/Bootstrap.php';
require_once ATUM_MAILER_DIR . 'includes/class-atum-mailer.php';

register_activation_hook( __FILE__, array( 'Atum_Mailer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Atum_Mailer', 'deactivate' ) );

Atum_Mailer::instance();
