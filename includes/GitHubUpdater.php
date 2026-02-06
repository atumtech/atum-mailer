<?php
/**
 * GitHub release updater integration.
 *
 * @package AtumMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atum_Mailer_GitHub_Updater {
	const RELEASE_CACHE_KEY = 'atum_mailer_github_release_cache';

	/**
	 * Plugin main file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param string $current_version Current version.
	 */
	public function __construct( $plugin_file, $current_version ) {
		$this->plugin_file     = (string) $plugin_file;
		$this->plugin_basename = plugin_basename( $this->plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		if ( '.' === $this->plugin_slug || '' === $this->plugin_slug ) {
			$this->plugin_slug = 'atum-mailer';
		}
		$this->current_version = (string) $current_version;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'add_http_headers' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_release_cache' ), 10, 2 );
	}

	/**
	 * Inject update data into plugin update transient.
	 *
	 * @param object|false $transient Existing transient.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = (object) array(
				'checked'  => array(),
				'response' => array(),
				'no_update'=> array(),
			);
		}

		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release_data();
		if ( false === $release ) {
			return $transient;
		}

		if ( version_compare( (string) $release['version'], $this->current_version, '>' ) ) {
			$item = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => (string) $release['version'],
				'url'         => (string) $release['html_url'],
				'package'     => (string) $release['package'],
				'tested'      => (string) apply_filters( 'atum_mailer_github_tested_up_to', '6.8' ),
			);

			$transient->response[ $this->plugin_basename ] = $item;
		} else {
			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => (string) $release['version'],
				'url'         => (string) $release['html_url'],
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info modal data.
	 *
	 * @param false|object|array<string, mixed> $result Existing result.
	 * @param string                            $action Action.
	 * @param object                            $args API args.
	 * @return false|object|array<string, mixed>
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || $this->plugin_slug !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release_data();
		if ( false === $release ) {
			return $result;
		}

		$changelog = trim( (string) $release['body'] );
		if ( '' === $changelog ) {
			$changelog = __( 'No changelog provided in the latest GitHub release.', 'atum-mailer' );
		}

		return (object) array(
			'name'          => 'atum.mailer',
			'slug'          => $this->plugin_slug,
			'version'       => (string) $release['version'],
			'author'        => '<a href="https://atum.tech">atum.tech</a>',
			'author_profile'=> 'https://atum.tech',
			'homepage'      => (string) $release['html_url'],
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'download_link' => (string) $release['package'],
			'last_updated'  => (string) $release['published_at'],
			'sections'      => array(
				'description' => __( 'Reliable transactional email delivery with Postmark, built for client sites.', 'atum-mailer' ),
				'changelog'   => '<pre>' . esc_html( $changelog ) . '</pre>',
			),
		);
	}

	/**
	 * Add GitHub auth headers for API/package requests.
	 *
	 * @param array<string, mixed> $args HTTP args.
	 * @param string               $url Request URL.
	 * @return array<string, mixed>
	 */
	public function add_http_headers( $args, $url ) {
		$token = $this->get_token();
		$url = (string) $url;
		if ( '' === $token || ! $this->is_repository_api_url( $url ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$args['headers']['User-Agent']    = $this->user_agent();

		if ( false !== strpos( (string) $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Purge cache after plugin updates.
	 *
	 * @param WP_Upgrader          $upgrader Upgrader.
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return void
	 */
	public function purge_release_cache( $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$plugins = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( (string) $hook_extra['plugin'] );
		}

		if ( in_array( $this->plugin_basename, $plugins, true ) ) {
			delete_transient( self::RELEASE_CACHE_KEY );
		}
	}

	/**
	 * Load latest release data with transient cache.
	 *
	 * @param bool $force Force cache refresh.
	 * @return array<string, string>|false
	 */
	private function get_release_data( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::RELEASE_CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}

		$repo = $this->get_repository();
		if ( '' === $repo ) {
			return false;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $this->release_request_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || '' === $body ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['tag_name'] ) ) {
			return false;
		}

		$version = ltrim( (string) $decoded['tag_name'], "vV \t\n\r\0\x0B" );
		if ( '' === $version ) {
			return false;
		}

		$package = $this->resolve_package_url( $decoded );
		if ( '' === $package ) {
			return false;
		}

		$data = array(
			'version'      => $version,
			'package'      => $package,
			'html_url'     => ! empty( $decoded['html_url'] ) ? (string) $decoded['html_url'] : 'https://github.com/' . $repo,
			'body'         => ! empty( $decoded['body'] ) ? (string) $decoded['body'] : '',
			'published_at' => ! empty( $decoded['published_at'] ) ? (string) $decoded['published_at'] : '',
		);

		$ttl = max( 300, (int) apply_filters( 'atum_mailer_github_release_cache_ttl', 6 * HOUR_IN_SECONDS ) );
		set_transient( self::RELEASE_CACHE_KEY, $data, $ttl );
		return $data;
	}

	/**
	 * Resolve package URL from release payload.
	 *
	 * @param array<string, mixed> $release GitHub release payload.
	 * @return string
	 */
	private function resolve_package_url( $release ) {
		$asset_name = $this->asset_name();
		$token      = $this->get_token();

		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
					continue;
				}

				if ( $asset_name !== (string) $asset['name'] ) {
					continue;
				}

				if ( '' !== $token && ! empty( $asset['url'] ) ) {
					return (string) $asset['url'];
				}

				if ( ! empty( $asset['browser_download_url'] ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return ! empty( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	/**
	 * Build release API headers.
	 *
	 * @return array<string, string>
	 */
	private function release_request_headers() {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => $this->user_agent(),
		);

		$token = $this->get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Build user-agent for GitHub API requests.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'atum-mailer/' . $this->current_version . '; ' . home_url( '/' );
	}

	/**
	 * Determine repository slug.
	 *
	 * @return string
	 */
	private function get_repository() {
		$default_repo = defined( 'ATUM_MAILER_GITHUB_REPO' ) ? (string) ATUM_MAILER_GITHUB_REPO : 'atum/atum-mailer';
		return trim( (string) apply_filters( 'atum_mailer_github_repo', $default_repo ) );
	}

	/**
	 * Check whether a URL targets this plugin's configured GitHub repository API.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_repository_api_url( $url ) {
		if ( false === strpos( $url, 'https://api.github.com/repos/' ) ) {
			return false;
		}

		$repo = $this->get_repository();
		if ( '' === $repo ) {
			return false;
		}

		return false !== strpos( $url, '/repos/' . $repo . '/' );
	}

	/**
	 * Get GitHub token.
	 *
	 * @return string
	 */
	private function get_token() {
		$default_token = defined( 'ATUM_MAILER_GITHUB_TOKEN' ) ? (string) ATUM_MAILER_GITHUB_TOKEN : '';
		return trim( (string) apply_filters( 'atum_mailer_github_token', $default_token ) );
	}

	/**
	 * Get release asset filename.
	 *
	 * @return string
	 */
	private function asset_name() {
		$default_asset = defined( 'ATUM_MAILER_GITHUB_RELEASE_ASSET' ) ? (string) ATUM_MAILER_GITHUB_RELEASE_ASSET : 'atum-mailer.zip';
		return trim( (string) apply_filters( 'atum_mailer_github_release_asset', $default_asset ) );
	}

	/**
	 * Check if updater is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$repo    = $this->get_repository();
		$enabled = '' !== $repo;
		return (bool) apply_filters( 'atum_mailer_github_updates_enabled', $enabled, $repo );
	}
}
