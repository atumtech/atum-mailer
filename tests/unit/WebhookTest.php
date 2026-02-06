<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {
	/** @var Atum_Mailer_Settings_Repository */
	private $settings;

	/** @var Atum_Mailer_Log_Repository */
	private $logs;

	/** @var Atum_Mailer_Bootstrap */
	private $bootstrap;

	protected function setUp(): void {
		atum_test_reset_globals();

		$this->settings  = new Atum_Mailer_Settings_Repository();
		$this->logs      = new Atum_Mailer_Log_Repository( $this->settings );
		$this->bootstrap = new Atum_Mailer_Bootstrap();

		$options                           = $this->settings->default_options();
		$options['postmark_webhook_secret'] = 'super-secret';
		$options['mail_retention']         = 1;
		$this->settings->update_raw_options( $options );
	}

	public function test_webhook_permission_rejects_invalid_secret(): void {
		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'wrong-secret' ),
			array( 'RecordType' => 'Delivery', 'MessageID' => 'mid-1' )
		);

		$result = $this->bootstrap->can_receive_webhook( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'atum_mailer_webhook_auth_failed', $result->get_error_code() );
	}

	public function test_webhook_updates_log_status_by_provider_message_id(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'sent',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-1',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'RecordType' => 'Delivery',
				'MessageID'  => 'mid-1',
			)
		);

		$permission = $this->bootstrap->can_receive_webhook( $request );
		$this->assertTrue( $permission );

		$response = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertSame( 'delivered', $GLOBALS['wpdb']->last_update_data['status'] );
		$this->assertSame( 'delivery', $GLOBALS['wpdb']->last_update_data['webhook_event_type'] );
	}

	public function test_webhook_does_not_regress_terminal_status(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Regression Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'delivered',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-terminal',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'RecordType' => 'Bounce',
				'MessageID'  => 'mid-terminal',
			)
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $request ) );
		$this->bootstrap->handle_webhook_event( $request );

		$this->assertSame( 'delivered', $GLOBALS['wpdb']->last_update_data['status'] );
	}

	public function test_webhook_duplicate_events_are_deduplicated(): void {
		$log_id = $this->logs->insert_mail_log(
			array(
				'to'          => array( 'recipient@example.com' ),
				'subject'     => 'Webhook Duplicate Test',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			'sent',
			$this->settings->get_options(),
			array(
				'provider_message_id' => 'mid-dup',
			)
		);
		$this->assertGreaterThan( 0, $log_id );

		$request = new WP_REST_Request(
			array( 'x-atum-webhook-secret' => 'super-secret' ),
			array(
				'ID'         => 'evt-123',
				'RecordType' => 'Delivery',
				'MessageID'  => 'mid-dup',
			)
		);

		$this->assertTrue( $this->bootstrap->can_receive_webhook( $request ) );

		$first = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$this->assertSame( 200, $first->status );

		$second = $this->bootstrap->handle_webhook_event( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $second );
		$this->assertSame( 200, $second->status );
		$this->assertIsArray( $second->data );
		$this->assertTrue( (bool) ( $second->data['duplicate'] ?? false ) );
	}
}
