<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostmarkClientTest extends TestCase {
	protected function setUp(): void {
		atum_test_reset_globals();
	}

	public function test_retryable_status_classifier(): void {
		$this->assertTrue( Atum_Mailer_Postmark_Client::is_retryable_status( 429 ) );
		$this->assertTrue( Atum_Mailer_Postmark_Client::is_retryable_status( 500 ) );
		$this->assertTrue( Atum_Mailer_Postmark_Client::is_retryable_status( 503 ) );
		$this->assertFalse( Atum_Mailer_Postmark_Client::is_retryable_status( 400 ) );
		$this->assertFalse( Atum_Mailer_Postmark_Client::is_retryable_status( 422 ) );
	}

	public function test_verify_token_fetches_server_and_streams(): void {
		$client = new Atum_Mailer_Postmark_Client();

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"Name":"Primary Server"}',
			)
		);
		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"MessageStreams":[{"ID":"outbound"},{"ID":"broadcast"}]}',
			)
		);

		$result = $client->verify_token( 'token-abc' );
		$this->assertIsArray( $result );
		$this->assertSame( 'Primary Server', $result['server_name'] );
		$this->assertSame( array( 'outbound', 'broadcast' ), $result['available_streams'] );
	}

	public function test_verify_token_falls_back_to_outbound_when_streams_fail(): void {
		$client = new Atum_Mailer_Postmark_Client();

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"Name":"Fallback Server"}',
			)
		);
		atum_test_push_http_response( 'GET', new WP_Error( 'network', 'broken network', array( 'retryable' => true ) ) );

		$result = $client->verify_token( 'token-abc' );
		$this->assertIsArray( $result );
		$this->assertSame( 'Fallback Server', $result['server_name'] );
		$this->assertSame( array( 'outbound' ), $result['available_streams'] );
	}

	public function test_send_email_returns_retryable_error_on_503(): void {
		$client = new Atum_Mailer_Postmark_Client();
		atum_test_push_http_response(
			'POST',
			array(
				'response' => array( 'code' => 503 ),
				'body'     => '{"Message":"Service unavailable"}',
			)
		);

		$result = $client->send_email( array( 'To' => 'user@example.com' ), 'token-abc' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $client->is_retryable_error( $result ) );
		$this->assertSame( 'http_503', $client->normalized_error_code( $result ) );
	}

	public function test_is_retryable_error_prefers_explicit_error_data_flag(): void {
		$client = new Atum_Mailer_Postmark_Client();
		$error  = new WP_Error( 'custom', 'fail', array( 'retryable' => false, 'status_code' => 503 ) );
		$this->assertFalse( $client->is_retryable_error( $error ) );
	}
}
