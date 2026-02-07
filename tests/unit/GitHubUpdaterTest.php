<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GitHubUpdaterTest extends TestCase {
	protected function setUp(): void {
		atum_test_reset_globals();
	}

	public function test_inject_update_adds_response_when_github_release_is_newer(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name'     => 'v0.7.0',
						'html_url'     => 'https://github.com/atum/atum-mailer/releases/tag/v0.7.0',
						'body'         => "## Changelog\n- Added feature",
						'published_at' => '2026-02-06T10:00:00Z',
						'assets'       => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'browser_download_url' => 'https://github.com/atum/atum-mailer/releases/download/v0.7.0/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );

		$this->assertObjectHasProperty( 'response', $result );
		$this->assertArrayHasKey( 'atum-mailer.php', $result->response );
		$this->assertSame( '0.7.0', $result->response['atum-mailer.php']->new_version );
		$this->assertSame(
			'https://github.com/atum/atum-mailer/releases/download/v0.7.0/atum-mailer.zip',
			$result->response['atum-mailer.php']->package
		);
	}

	public function test_inject_update_populates_no_update_when_versions_match(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v0.6.0',
						'html_url' => 'https://github.com/atum/atum-mailer/releases/tag/v0.6.0',
						'assets'   => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'browser_download_url' => 'https://github.com/atum/atum-mailer/releases/download/v0.6.0/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );

		$this->assertArrayNotHasKey( 'atum-mailer.php', $result->response );
		$this->assertArrayHasKey( 'atum-mailer.php', $result->no_update );
		$this->assertSame( '0.6.0', $result->no_update['atum-mailer.php']->new_version );
	}

	public function test_plugins_api_returns_release_information(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name'     => 'v0.8.0',
						'html_url'     => 'https://github.com/atum/atum-mailer/releases/tag/v0.8.0',
						'body'         => "## Changelog\n- Feature",
						'published_at' => '2026-02-06T11:00:00Z',
						'assets'       => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'browser_download_url' => 'https://github.com/atum/atum-mailer/releases/download/v0.8.0/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$args   = (object) array( 'slug' => 'atum-mailer' );
		$result = $updater->plugins_api( false, 'plugin_information', $args );

		$this->assertIsObject( $result );
		$this->assertSame( '0.8.0', $result->version );
		$this->assertSame( 'atum-mailer', $result->slug );
		$this->assertSame(
			'https://github.com/atum/atum-mailer/releases/download/v0.8.0/atum-mailer.zip',
			$result->download_link
		);
		$this->assertArrayHasKey( 'changelog', $result->sections );
	}

	public function test_token_auth_headers_are_added_for_github_api_requests(): void {
		add_filter(
			'atum_mailer_github_token',
			static function () {
				return 'ghs_testtoken';
			}
		);

		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );
		$args    = $updater->add_http_headers( array(), 'https://api.github.com/repos/atum/atum-mailer/releases/latest' );

		$this->assertArrayHasKey( 'headers', $args );
		$this->assertSame( 'Bearer ghs_testtoken', $args['headers']['Authorization'] );
		$this->assertArrayHasKey( 'User-Agent', $args['headers'] );
	}

	public function test_token_auth_headers_are_not_added_for_other_repository_requests(): void {
		add_filter(
			'atum_mailer_github_token',
			static function () {
				return 'ghs_testtoken';
			}
		);

		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );
		$args    = $updater->add_http_headers( array(), 'https://api.github.com/repos/example/other-plugin/releases/latest' );

		$this->assertArrayNotHasKey( 'headers', $args );
	}

	public function test_token_auth_headers_are_not_added_for_lookalike_urls(): void {
		add_filter(
			'atum_mailer_github_token',
			static function () {
				return 'ghs_testtoken';
			}
		);

		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );
		$args    = $updater->add_http_headers(
			array(),
			'https://evil.example/?next=https://api.github.com/repos/atum/atum-mailer/releases/latest'
		);

		$this->assertArrayNotHasKey( 'headers', $args );
	}

	public function test_private_asset_api_url_is_used_when_token_present(): void {
		add_filter(
			'atum_mailer_github_token',
			static function () {
				return 'ghs_testtoken';
			}
		);

		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );
		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v0.9.0',
						'html_url' => 'https://github.com/atum/atum-mailer/releases/tag/v0.9.0',
						'assets'   => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'url'                  => 'https://api.github.com/repos/atum/atum-mailer/releases/assets/12345',
								'browser_download_url' => 'https://github.com/atum/atum-mailer/releases/download/v0.9.0/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );
		$this->assertSame(
			'https://api.github.com/repos/atum/atum-mailer/releases/assets/12345',
			$result->response['atum-mailer.php']->package
		);
	}

	public function test_inject_update_ignores_untrusted_package_host(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v0.9.0',
						'html_url' => 'https://github.com/atum/atum-mailer/releases/tag/v0.9.0',
						'assets'   => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'browser_download_url' => 'https://evil.example/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );
		$this->assertArrayNotHasKey( 'atum-mailer.php', $result->response );
	}

	public function test_inject_update_rejects_package_url_for_different_repository_path(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v0.9.1',
						'html_url' => 'https://github.com/atum/atum-mailer/releases/tag/v0.9.1',
						'assets'   => array(
							array(
								'name'                 => 'atum-mailer.zip',
								'browser_download_url' => 'https://github.com/example/other-plugin/releases/download/v0.9.1/atum-mailer.zip',
							),
						),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );
		$this->assertArrayNotHasKey( 'atum-mailer.php', $result->response );
	}

	public function test_inject_update_accepts_repo_scoped_zipball_url(): void {
		$updater = new Atum_Mailer_GitHub_Updater( ATUM_MAILER_FILE, '0.6.0' );

		atum_test_push_http_response(
			'GET',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name'    => 'v0.9.2',
						'html_url'    => 'https://github.com/atum/atum-mailer/releases/tag/v0.9.2',
						'zipball_url' => 'https://api.github.com/repos/atum/atum-mailer/zipball/v0.9.2',
						'assets'      => array(),
					)
				),
			)
		);

		$transient = (object) array(
			'checked'  => array( 'atum-mailer.php' => '0.6.0' ),
			'response' => array(),
			'no_update'=> array(),
		);

		$result = $updater->inject_update( $transient );
		$this->assertArrayHasKey( 'atum-mailer.php', $result->response );
		$this->assertSame(
			'https://api.github.com/repos/atum/atum-mailer/zipball/v0.9.2',
			$result->response['atum-mailer.php']->package
		);
	}
}
