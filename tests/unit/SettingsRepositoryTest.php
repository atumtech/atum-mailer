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
		$this->assertSame( 0, $defaults['allow_token_reveal'] );
		$this->assertSame( 'metadata', $defaults['log_detail_mode'] );
		$this->assertSame( 'immediate', $defaults['delivery_mode'] );
		$this->assertSame( 0, $defaults['fallback_to_wp_mail'] );
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
		$this->assertArrayHasKey( 'delivery_mode', $merged );
		$this->assertArrayHasKey( 'log_detail_mode', $merged );
		$this->assertArrayHasKey( 'allow_token_reveal', $merged );
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
