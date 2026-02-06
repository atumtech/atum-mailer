<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminControllerCsvTest extends TestCase {
	/** @var Atum_Mailer_Admin_Controller */
	private $admin;

	protected function setUp(): void {
		atum_test_reset_globals();

		$settings = new Atum_Mailer_Settings_Repository();
		$logs     = new Atum_Mailer_Log_Repository( $settings );
		$client   = new Atum_Mailer_Postmark_Client();
		$mail     = new Atum_Mailer_Mail_Interceptor( $settings, $logs, $client );
		$this->admin = new Atum_Mailer_Admin_Controller( $settings, $logs, $client, $mail );
	}

	public function test_csv_safe_cell_escapes_formula_prefixed_values(): void {
		$method = new ReflectionMethod( Atum_Mailer_Admin_Controller::class, 'csv_safe_cell' );

		$this->assertSame( "'=2+2", $method->invoke( $this->admin, '=2+2' ) );
		$this->assertSame( "'+cmd", $method->invoke( $this->admin, '+cmd' ) );
		$this->assertSame( "'@cmd", $method->invoke( $this->admin, '@cmd' ) );
		$this->assertSame( "'-cmd", $method->invoke( $this->admin, '-cmd' ) );
		$this->assertSame( 'safe-value', $method->invoke( $this->admin, 'safe-value' ) );
	}
}
