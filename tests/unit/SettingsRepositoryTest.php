<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		atum_test_reset_globals();
	}

	public function test_default_options_include_new_security_and_reliability_keys(): void {
		$repo     = new Atum_Mailer_Settings_Repository();
		$defaults = $repo->default_options();

		$this->assertArrayHasKey( 'allow_token_reveal', $defaults );
		$this->assertArrayHasKey( 'log_detail_mode', $defaults );
		$this->assertArrayHasKey( 'delivery_mode', $defaults );
		$this->assertArrayHasKey( 'fallback_to_wp_mail', $defaults );
			$this->assertArrayHasKey( 'queue_max_attempts', $defaults );
			$this->assertArrayHasKey( 'queue_retry_base_delay', $defaults );
			$this->assertArrayHasKey( 'queue_retry_max_delay', $defaults );
			$this->assertArrayHasKey( 'webhook_require_signature', $defaults );
			$this->assertArrayHasKey( 'webhook_replay_window_seconds', $defaults );
			$this->assertArrayHasKey( 'webhook_rate_limit_per_minute', $defaults );
			$this->assertSame( 0, $defaults['allow_token_reveal'] );
			$this->assertSame( 'metadata', $defaults['log_detail_mode'] );
			$this->assertSame( 'immediate', $defaults['delivery_mode'] );
			$this->assertSame( 0, $defaults['fallback_to_wp_mail'] );
			$this->assertSame( 0, $defaults['webhook_require_signature'] );
			$this->assertSame( 300, $defaults['webhook_replay_window_seconds'] );
			$this->assertSame( 120, $defaults['webhook_rate_limit_per_minute'] );
		}

	public function test_sanitize_options_enforces_enums_and_bounds(): void {
		$repo  = new Atum_Mailer_Settings_Repository();
		$input = array(
			'log_detail_mode'         => 'invalid-mode',
			'delivery_mode'           => 'invalid-mode',
			'track_links'             => 'NotAllowed',
			'queue_max_attempts'      => 100,
			'queue_retry_base_delay'  => -5,
			'queue_retry_max_delay'   => 10,
			'retention_days'          => -8,
			'available_streams'       => array( 'outbound', 'bad stream', '', 'custom_stream' ),
			'message_stream'          => 'bad stream',
				'allow_token_reveal'      => 1,
				'fallback_to_wp_mail'     => 1,
				'postmark_webhook_secret' => 'secret-abc',
				'webhook_require_signature' => 1,
				'webhook_replay_window_seconds' => -1,
				'webhook_rate_limit_per_minute' => 999999,
			);

		$sanitized = $repo->sanitize_options( $input );

		$this->assertSame( 'metadata', $sanitized['log_detail_mode'] );
		$this->assertSame( 'immediate', $sanitized['delivery_mode'] );
		$this->assertSame( 'None', $sanitized['track_links'] );
		$this->assertSame( 20, $sanitized['queue_max_attempts'] );
		$this->assertSame( 5, $sanitized['queue_retry_base_delay'] );
		$this->assertSame( 60, $sanitized['queue_retry_max_delay'] );
		$this->assertSame( 1, $sanitized['retention_days'] );
		$this->assertSame( array( 'outbound', 'custom_stream' ), $sanitized['available_streams'] );
		$this->assertSame( 'outbound', $sanitized['message_stream'] );
		$this->assertSame( 1, $sanitized['allow_token_reveal'] );
			$this->assertSame( 1, $sanitized['fallback_to_wp_mail'] );
			$this->assertSame( 1, $sanitized['webhook_require_signature'] );
			$this->assertSame( 30, $sanitized['webhook_replay_window_seconds'] );
			$this->assertSame( 5000, $sanitized['webhook_rate_limit_per_minute'] );
			$this->assertSame( '', $sanitized['postmark_server_token'] );
		$this->assertSame( '', $sanitized['postmark_webhook_secret'] );
		$this->assertSame( 'secret-abc', (string) get_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, '' ) );
	}

	public function test_sanitize_options_preserves_existing_webhook_secret_when_blank_input_is_submitted(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'keep-me' );

		$sanitized = $repo->sanitize_options(
			array(
				'postmark_webhook_secret' => '',
			)
		);

		$this->assertSame( '', $sanitized['postmark_webhook_secret'] );
		$this->assertSame( 'keep-me', (string) get_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, '' ) );
	}

	public function test_sanitize_options_can_explicitly_clear_existing_webhook_secret(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		update_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, 'clear-me' );

		$sanitized = $repo->sanitize_options(
			array(
				'postmark_webhook_secret'       => '',
				'postmark_webhook_secret_clear' => 1,
			)
		);

		$this->assertSame( '', $sanitized['postmark_webhook_secret'] );
		$this->assertSame( '', (string) get_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, '' ) );
	}

	public function test_legacy_token_is_migrated_to_dedicated_option(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		update_option(
			Atum_Mailer_Settings_Repository::OPTION_KEY,
			array(
				'postmark_server_token' => 'legacy-token-123',
				'enabled'               => 1,
			)
		);

		$this->assertSame( '', (string) get_option( Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY, '' ) );
		$repo->maybe_migrate_legacy_options();

		$this->assertSame( 'legacy-token-123', (string) get_option( Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY ) );

		$merged = get_option( Atum_Mailer_Settings_Repository::OPTION_KEY );
		$this->assertIsArray( $merged );
		$this->assertSame( '', (string) ( $merged['postmark_server_token'] ?? '' ) );
		$this->assertArrayHasKey( 'delivery_mode', $merged );
		$this->assertArrayHasKey( 'log_detail_mode', $merged );
		$this->assertArrayHasKey( 'allow_token_reveal', $merged );
	}

	public function test_get_options_migrates_legacy_token_and_clears_primary_option(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		update_option(
			Atum_Mailer_Settings_Repository::OPTION_KEY,
			array(
				'postmark_server_token' => 'legacy-token-xyz',
				'enabled'               => 1,
			)
		);
		delete_option( Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY );

		$options = $repo->get_options();
		$this->assertSame( 'legacy-token-xyz', $options['postmark_server_token'] );
		$this->assertSame( 'legacy-token-xyz', (string) get_option( Atum_Mailer_Settings_Repository::TOKEN_OPTION_KEY, '' ) );

		$stored = get_option( Atum_Mailer_Settings_Repository::OPTION_KEY, array() );
		$this->assertIsArray( $stored );
		$this->assertSame( '', (string) ( $stored['postmark_server_token'] ?? '' ) );
	}

	public function test_get_options_migrates_legacy_webhook_secret_and_clears_primary_option(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		update_option(
			Atum_Mailer_Settings_Repository::OPTION_KEY,
			array(
				'postmark_webhook_secret' => 'legacy-webhook-secret',
				'enabled'                 => 1,
			)
		);
		delete_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY );

		$options = $repo->get_options();
		$this->assertSame( 'legacy-webhook-secret', $options['postmark_webhook_secret'] );
		$this->assertSame( 'legacy-webhook-secret', (string) get_option( Atum_Mailer_Settings_Repository::WEBHOOK_SECRET_OPTION_KEY, '' ) );

		$stored = get_option( Atum_Mailer_Settings_Repository::OPTION_KEY, array() );
		$this->assertIsArray( $stored );
		$this->assertSame( '', (string) ( $stored['postmark_webhook_secret'] ?? '' ) );
	}

	public function test_queue_jobs_roundtrip_and_backlog_count(): void {
		$repo = new Atum_Mailer_Settings_Repository();
		$jobs = array(
			'job-1' => array( 'log_id' => 10, 'attempt_count' => 1, 'next_attempt_at' => time() + 60 ),
			'job-2' => array( 'log_id' => 11, 'attempt_count' => 0, 'next_attempt_at' => time() + 10 ),
		);

		$repo->update_queue_jobs( $jobs );
		$this->assertSame( $jobs, $repo->get_queue_jobs() );
		$this->assertSame( 2, $repo->get_queue_backlog_count() );
	}
}
