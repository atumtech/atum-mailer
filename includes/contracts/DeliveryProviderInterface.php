<?php
/**
 * Delivery provider contract.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Atum_Mailer_Delivery_Provider_Interface {
	/**
	 * Verify provider credentials.
	 *
	 * @param string $token Provider API token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function verifyToken( $token );

	/**
	 * Send a message payload.
	 *
	 * @param array<string, mixed> $payload Message payload.
	 * @param string               $token Provider API token.
	 * @param array<string, mixed> $atts Original wp_mail() attributes.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send( $payload, $token, $atts = array(), $options = array() );

	/**
	 * Classify if an error is retryable.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public function isRetryable( WP_Error $error );

	/**
	 * Normalize provider error code.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public function normalizeErrorCode( WP_Error $error );
}
