<?php
/**
 * Cleanup for atum.mailer plugin uninstall.
 *
 * @package AtumMailer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'atum_mailer_options' );
delete_option( 'atum_mailer_postmark_token' );
delete_option( 'atum_mailer_webhook_secret' );
delete_option( 'atum_mailer_db_version' );
delete_option( 'atum_mailer_last_cleanup' );
delete_option( 'atum_mailer_queue_jobs' );
delete_option( 'atum_mailer_last_api_outage' );
delete_option( 'atum_mailer_queue_db_version' );
delete_option( 'atum_mailer_queue_migrated_from_option' );
delete_option( 'atum_mailer_last_test_email_at' );
delete_option( 'atum_mailer_last_alert_failure_rate' );
delete_option( 'atum_mailer_last_alert_queue_backlog' );

global $wpdb;
$table_name = $wpdb->prefix . 'atum_mailer_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$queue_table = $wpdb->prefix . 'atum_mailer_queue';
$wpdb->query( "DROP TABLE IF EXISTS {$queue_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
