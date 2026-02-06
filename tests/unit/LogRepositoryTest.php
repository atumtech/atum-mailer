<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LogRepositoryTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Log_Repository */
	private $logs;

	protected function setUp(): void {
		atum_test_reset_globals();
		$this->settings = new Atum_Mailer_Settings_Repository();
		$this->logs     = new Atum_Mailer_Log_Repository( $this->settings );
	}

	public function test_metadata_mode_redacts_message_and_payload_fields(): void {
		$options = $this->settings->get_options();
		$options['log_detail_mode'] = 'metadata';

		$atts = array(
			'to'          => array( 'user@example.com' ),
			'subject'     => 'Subject line',
			'message'     => 'Sensitive content',
			'headers'     => array( 'X-Test: 1' ),
			'attachments' => array( '/tmp/file.txt' ),
		);

		$log_id = $this->logs->insert_mail_log(
			$atts,
			'processing',
			$options,
			array(
				'request_payload' => array( 'token' => 'secret' ),
				'response_body'   => '{"token":"secret"}',
			)
		);

		$this->assertGreaterThan( 0, $log_id );
		$row = $GLOBALS['wpdb']->rows[ $log_id ];
		$this->assertSame( '', $row['message'] );
		$this->assertSame( '', $row['headers'] );
		$this->assertSame( '', $row['attachments'] );
		$this->assertArrayNotHasKey( 'request_payload', $row );
		$this->assertArrayNotHasKey( 'response_body', $row );
	}

	public function test_full_mode_persists_message_and_payload_fields(): void {
		$options = $this->settings->get_options();
		$options['log_detail_mode'] = 'full';

		$atts = array(
			'to'          => array( 'user@example.com' ),
			'subject'     => 'Subject line',
			'message'     => '<p>HTML message</p>',
			'headers'     => array( 'X-Test: 1' ),
			'attachments' => array( '/tmp/file.txt' ),
		);

		$log_id = $this->logs->insert_mail_log(
			$atts,
			'processing',
			$options,
			array(
				'request_payload' => array( 'safe' => 'ok' ),
				'response_body'   => '{"status":"ok"}',
			)
		);

		$this->assertGreaterThan( 0, $log_id );
		$row = $GLOBALS['wpdb']->rows[ $log_id ];
		$this->assertSame( '<p>HTML message</p>', $row['message'] );
		$this->assertNotSame( '', $row['headers'] );
		$this->assertNotSame( '', $row['attachments'] );
		$this->assertNotEmpty( $row['request_payload'] );
		$this->assertSame( '{"status":"ok"}', $row['response_body'] );
	}

	public function test_log_record_filter_can_redact_inserted_subject(): void {
		add_filter(
			'atum_mailer_log_record',
			static function ( $data, $context ) {
				unset( $context );
				$data['subject'] = '[redacted]';
				return $data;
			},
			10,
			2
		);

		$options = $this->settings->get_options();
		$atts    = array(
			'to'          => array( 'user@example.com' ),
			'subject'     => 'Original Subject',
			'message'     => 'Body',
			'headers'     => array(),
			'attachments' => array(),
		);

		$log_id = $this->logs->insert_mail_log( $atts, 'processing', $options );
		$this->assertSame( '[redacted]', $GLOBALS['wpdb']->rows[ $log_id ]['subject'] );
	}

	public function test_update_ignores_payload_in_metadata_mode_unless_forced(): void {
		$options = $this->settings->get_options();
		$options['log_detail_mode'] = 'metadata';

		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'a@example.com' ),
				'subject'     => 'S',
				'message'     => 'M',
				'headers'     => array(),
				'attachments' => array(),
			),
			'processing',
			$options
		);

		$this->logs->update_mail_log(
			$log_id,
			'failed',
			array(
				'request_payload' => array( 'secret' => 'value' ),
				'response_body'   => '{"secret":"value"}',
			),
			$options
		);
		$this->assertArrayNotHasKey( 'request_payload', $GLOBALS['wpdb']->last_update_data );
		$this->assertArrayNotHasKey( 'response_body', $GLOBALS['wpdb']->last_update_data );

		$this->logs->update_mail_log(
			$log_id,
			'failed',
			array(
				'request_payload'    => array( 'secret' => 'value' ),
				'response_body'      => '{"secret":"value"}',
				'force_store_payload'=> true,
			),
			$options
		);
		$this->assertArrayHasKey( 'request_payload', $GLOBALS['wpdb']->last_update_data );
		$this->assertArrayHasKey( 'response_body', $GLOBALS['wpdb']->last_update_data );
	}

	public function test_query_logs_accepts_advanced_filter_map(): void {
		$this->logs->query_logs(
			array(
				'status'              => 'failed',
				's'                   => 'mail',
				'date_from'           => '2025-01-01',
				'date_to'             => '2025-01-31',
				'delivery_mode'       => 'queue',
				'retry_state'         => 'terminal',
				'provider_message_id' => 'abc123',
			),
			'',
			20,
			0
		);

		$query = (string) $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'status IN (', $query );
		$this->assertStringContainsString( 'delivery_mode = ', $query );
		$this->assertStringContainsString( 'provider_message_id LIKE ', $query );
		$this->assertStringContainsString( 'created_at >= ', $query );
		$this->assertStringContainsString( 'created_at <= ', $query );
	}
}
