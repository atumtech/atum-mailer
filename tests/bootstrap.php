<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'ATUM_MAILER_FILE' ) ) {
	define( 'ATUM_MAILER_FILE', dirname( __DIR__ ) . '/atum-mailer.php' );
}
if ( ! defined( 'ATUM_MAILER_VERSION' ) ) {
	define( 'ATUM_MAILER_VERSION', '0.6.0-test' );
}

$GLOBALS['atum_test_options']      = array();
$GLOBALS['atum_test_transients']   = array();
$GLOBALS['atum_test_actions']      = array();
$GLOBALS['atum_test_filters']      = array();
$GLOBALS['atum_test_http_get']     = array();
$GLOBALS['atum_test_http_post']    = array();
$GLOBALS['atum_test_cron_events']  = array();
$GLOBALS['atum_test_rest_routes']  = array();
$GLOBALS['atum_test_redirect_url'] = '';
$GLOBALS['atum_test_current_user'] = 1;

class Atum_Test_Json_Response_Exception extends RuntimeException {
	/** @var array<string, mixed> */
	public $payload;

	/** @var int */
	public $status_code;

	/** @var bool */
	public $success;

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $status_code Status.
	 * @param bool                 $success Success flag.
	 */
	public function __construct( array $payload, int $status_code, bool $success ) {
		parent::__construct( 'JSON response' );
		$this->payload     = $payload;
		$this->status_code = $status_code;
		$this->success     = $success;
	}
}

class Atum_Test_Redirect_Exception extends RuntimeException {}

class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;
	/** @var mixed */
	private $data;

	/**
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param mixed  $data Data.
	 */
	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Request {
	/** @var array<string, mixed> */
	private $headers = array();
	/** @var mixed */
	private $json_params;

	/**
	 * @param array<string, mixed> $headers Headers.
	 * @param mixed                $json_params JSON body.
	 */
	public function __construct( array $headers = array(), $json_params = array() ) {
		$this->headers     = array_change_key_case( $headers, CASE_LOWER );
		$this->json_params = $json_params;
	}

	public function get_header( string $name ): string {
		$key = strtolower( $name );
		return isset( $this->headers[ $key ] ) ? (string) $this->headers[ $key ] : '';
	}

	public function get_json_params() {
		return $this->json_params;
	}
}

class WP_REST_Response {
	/** @var mixed */
	public $data;
	/** @var int */
	public $status;

	/**
	 * @param mixed $data Data.
	 * @param int   $status Status code.
	 */
	public function __construct( $data, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Server {
	public const CREATABLE = 'POST';
}

class Atum_Test_WPDB {
	/** @var string */
	public $prefix = 'wp_';
	/** @var int */
	public $insert_id = 0;
	/** @var int */
	private $next_id = 1;
	/** @var array<int, array<string, mixed>> */
	public $rows = array();
	/** @var array<string, mixed> */
	public $last_insert_data = array();
	/** @var array<string, mixed> */
	public $last_update_data = array();
	/** @var array<string, mixed> */
	public $last_update_where = array();
	/** @var string */
	public $last_query = '';
	/** @var array<int, string> */
	public $query_log = array();

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param mixed                $format Format.
	 * @return int
	 */
	public function insert( string $table, array $data, $format = null ): int {
		unset( $format );
		$id                  = $this->next_id++;
		$this->insert_id     = $id;
		$data['id']          = $id;
		$this->rows[ $id ]   = $data;
		$this->last_insert_data = $data;
		return 1;
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param array<string, mixed> $where Where.
	 * @param mixed                $format Format.
	 * @param mixed                $where_format Where format.
	 * @return int
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
		unset( $table, $format, $where_format );
		$this->last_update_data  = $data;
		$this->last_update_where = $where;
		if ( isset( $where['id'] ) && isset( $this->rows[ (int) $where['id'] ] ) ) {
			$this->rows[ (int) $where['id'] ] = array_merge( $this->rows[ (int) $where['id'] ], $data );
		}
		return 1;
	}

	/**
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( string $query ) {
		$this->last_query = $query;
		$this->query_log[] = $query;
		if ( false !== stripos( $query, 'count(*)' ) ) {
			if ( preg_match( "/status = '([^']+)'/", $query, $matches ) ) {
				$needle = (string) $matches[1];
				$count = 0;
				foreach ( $this->rows as $row ) {
					if ( isset( $row['status'] ) && $needle === (string) $row['status'] ) {
						$count++;
					}
				}
				return $count;
			}
			return count( $this->rows );
		}

		if ( false !== stripos( $query, 'select id from' ) && false !== stripos( $query, 'provider_message_id' ) ) {
			if ( preg_match( "/provider_message_id = '([^']+)'/", $query, $matches ) ) {
				$needle = $matches[1];
				$found  = 0;
				foreach ( $this->rows as $id => $row ) {
					if ( isset( $row['provider_message_id'] ) && (string) $row['provider_message_id'] === $needle ) {
						$found = (int) $id;
					}
				}
				return $found > 0 ? $found : null;
			}
		}

		if ( false !== stripos( $query, 'select status from' ) ) {
			if ( preg_match( '/where id = ([0-9]+)/i', $query, $matches ) ) {
				$id = (int) $matches[1];
				if ( isset( $this->rows[ $id ]['status'] ) ) {
					return (string) $this->rows[ $id ]['status'];
				}
			}
		}

		if ( false !== stripos( $query, 'select next_attempt_at from' ) ) {
			$next = null;
			foreach ( $this->rows as $row ) {
				$status = isset( $row['status'] ) ? (string) $row['status'] : '';
				if ( ! in_array( $status, array( 'queued', 'retrying', 'processing' ), true ) ) {
					continue;
				}

				$candidate = isset( $row['next_attempt_at'] ) ? strtotime( (string) $row['next_attempt_at'] ) : false;
				if ( false === $candidate ) {
					continue;
				}

				if ( null === $next || $candidate < $next ) {
					$next = $candidate;
				}
			}

			return null === $next ? null : gmdate( 'Y-m-d H:i:s', $next );
		}

		if ( false !== stripos( $query, 'select created_at from' ) ) {
			if ( false !== stripos( $query, 'status in' ) ) {
				$oldest = null;
				foreach ( $this->rows as $row ) {
					$status = isset( $row['status'] ) ? (string) $row['status'] : '';
					if ( ! in_array( $status, array( 'queued', 'retrying', 'processing' ), true ) ) {
						continue;
					}
					$candidate = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : false;
					if ( false === $candidate ) {
						continue;
					}
					if ( null === $oldest || $candidate < $oldest ) {
						$oldest = $candidate;
					}
				}
				return null === $oldest ? null : gmdate( 'Y-m-d H:i:s', $oldest );
			}

			$latest = '';
			$max_id = 0;
			foreach ( $this->rows as $row ) {
				if ( isset( $row['status'] ) && 'sent' === $row['status'] && isset( $row['id'] ) && $row['id'] > $max_id ) {
					$max_id = (int) $row['id'];
					$latest = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
				}
			}
			return '' !== $latest ? $latest : null;
		}

		return null;
	}

	/**
	 * @param string $query Query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->last_query = $query;
		$this->query_log[] = $query;
		$results          = array();
		foreach ( $this->rows as $row ) {
			$results[] = (object) $row;
		}
		return $results;
	}

	/**
	 * @param string $query Query.
	 * @return object|null
	 */
	public function get_row( string $query ) {
		$this->last_query = $query;
		$this->query_log[] = $query;
		if ( preg_match( '/where id = ([0-9]+)/i', $query, $matches ) ) {
			$id = (int) $matches[1];
			if ( isset( $this->rows[ $id ] ) ) {
				return (object) $this->rows[ $id ];
			}
		}
		return null;
	}

	/**
	 * @param string $query Query.
	 * @return int
	 */
	public function query( string $query ): int {
		$this->last_query = $query;
		$this->query_log[] = $query;
		if ( false !== stripos( $query, 'delete from' ) ) {
			if ( preg_match( '/where id = ([0-9]+)/i', $query, $matches ) ) {
				$id = (int) $matches[1];
				if ( isset( $this->rows[ $id ] ) ) {
					unset( $this->rows[ $id ] );
					return 1;
				}
				return 0;
			}
			$count      = count( $this->rows );
			$this->rows = array();
			return $count;
		}
		return 1;
	}
}

$GLOBALS['wpdb'] = new Atum_Test_WPDB();

/**
 * Reset test globals.
 *
 * @return void
 */
function atum_test_reset_globals() {
	$GLOBALS['atum_test_options']      = array();
	$GLOBALS['atum_test_transients']   = array();
	$GLOBALS['atum_test_actions']      = array();
	$GLOBALS['atum_test_filters']      = array();
	$GLOBALS['atum_test_http_get']     = array();
	$GLOBALS['atum_test_http_post']    = array();
	$GLOBALS['atum_test_cron_events']  = array();
	$GLOBALS['atum_test_rest_routes']  = array();
	$GLOBALS['atum_test_redirect_url'] = '';
	$GLOBALS['atum_test_current_user'] = 1;
	$GLOBALS['wpdb']                   = new Atum_Test_WPDB();
	$_POST                             = array();
	$_GET                              = array();
	$_REQUEST                          = array();
}

/**
 * @param string $method Method.
 * @param mixed  $response Response object/array/WP_Error.
 * @return void
 */
function atum_test_push_http_response( string $method, $response ) {
	$key = 'GET' === strtoupper( $method ) ? 'atum_test_http_get' : 'atum_test_http_post';
	$GLOBALS[ $key ][] = $response;
}

function __($text, $domain = null) {
	unset( $domain );
	return (string) $text;
}

function esc_html__( $text, $domain = null ) {
	unset( $domain );
	return (string) $text;
}

function esc_attr__( $text, $domain = null ) {
	unset( $domain );
	return (string) $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	unset( $domain );
	return 1 === (int) $number ? (string) $single : (string) $plural;
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html__( $text, $domain );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr__( $text, $domain );
}

function esc_html( $text ) {
	return (string) $text;
}

function esc_attr( $text ) {
	return (string) $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_email( $email ) {
	$email = filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
	return false === $email ? '' : (string) $email;
}

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function sanitize_html_class( $class ) {
	return sanitize_key( $class );
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return stripslashes( (string) $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function wp_specialchars_decode( $string, $quote_style = ENT_COMPAT ) {
	return htmlspecialchars_decode( (string) $string, $quote_style );
}

function wp_strip_all_tags( $string ) {
	return strip_tags( (string) $string );
}

function wp_kses_post( $content ) {
	return (string) $content;
}

function wp_kses( $content, $allowed_html ) {
	unset( $allowed_html );
	return (string) $content;
}

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}

function get_bloginfo( $show = '' ) {
	if ( 'admin_email' === $show ) {
		return 'admin@example.com';
	}
	if ( 'charset' === $show ) {
		return 'UTF-8';
	}
	return '';
}

function get_option( $name, $default = false ) {
	if ( isset( $GLOBALS['atum_test_options'][ $name ] ) ) {
		return $GLOBALS['atum_test_options'][ $name ];
	}
	return $default;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['atum_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value ) {
	if ( isset( $GLOBALS['atum_test_options'][ $name ] ) ) {
		return false;
	}
	$GLOBALS['atum_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['atum_test_options'][ $name ] );
	return true;
}

function set_transient( $transient, $value, $expiration ) {
	$GLOBALS['atum_test_transients'][ $transient ] = array(
		'value'      => $value,
		'expiration' => time() + (int) $expiration,
	);
	return true;
}

function get_transient( $transient ) {
	if ( ! isset( $GLOBALS['atum_test_transients'][ $transient ] ) ) {
		return false;
	}
	$row = $GLOBALS['atum_test_transients'][ $transient ];
	if ( time() > $row['expiration'] ) {
		unset( $GLOBALS['atum_test_transients'][ $transient ] );
		return false;
	}
	return $row['value'];
}

function delete_transient( $transient ) {
	unset( $GLOBALS['atum_test_transients'][ $transient ] );
	return true;
}

function current_time( $type = 'mysql' ) {
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	if ( 'timestamp' === $type ) {
		return time();
	}
	return gmdate( 'Y-m-d H:i:s' );
}

function mysql2date( $format, $date ) {
	$timestamp = strtotime( (string) $date );
	if ( false === $timestamp ) {
		return (string) $date;
	}
	return gmdate( $format, $timestamp );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function wp_basename( $path ) {
	return basename( (string) $path );
}

function wp_check_filetype( $filename ) {
	$ext = pathinfo( (string) $filename, PATHINFO_EXTENSION );
	if ( 'txt' === strtolower( $ext ) ) {
		return array( 'type' => 'text/plain' );
	}
	return array( 'type' => 'application/octet-stream' );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals, '.', ',' );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

function wp_remote_get( $url, $args = array() ) {
	unset( $url, $args );
	if ( empty( $GLOBALS['atum_test_http_get'] ) ) {
		return new WP_Error( 'no_mock', 'No mock GET response configured.', array( 'retryable' => true ) );
	}
	return array_shift( $GLOBALS['atum_test_http_get'] );
}

function wp_remote_post( $url, $args = array() ) {
	unset( $url, $args );
	if ( empty( $GLOBALS['atum_test_http_post'] ) ) {
		return new WP_Error( 'no_mock', 'No mock POST response configured.', array( 'retryable' => true ) );
	}
	return array_shift( $GLOBALS['atum_test_http_post'] );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce:' . (string) $action;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( 'nonce:' . (string) $action, (string) $nonce );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	unset( $referer );
	$field = '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( wp_create_nonce( (string) $action ) ) . '" />';
	if ( $display ) {
		echo $field;
	}
	return $field;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	unset( $action, $query_arg );
	return true;
}

function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
	unset( $action, $query_arg, $die );
	return true;
}

function current_user_can( $capability ) {
	unset( $capability );
	return true;
}

function get_current_user_id() {
	return (int) $GLOBALS['atum_test_current_user'];
}

function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
}

function rest_url( $path = '' ) {
	return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' );
}

function plugin_basename( $file ) {
	return basename( (string) $file );
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	unset( $domain, $deprecated, $plugin_rel_path );
	return true;
}

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['atum_test_actions'][ $hook_name ][] = $callback;
	return true;
}

function do_action( $hook_name, ...$args ) {
	if ( empty( $GLOBALS['atum_test_actions'][ $hook_name ] ) ) {
		return;
	}
	foreach ( $GLOBALS['atum_test_actions'][ $hook_name ] as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['atum_test_filters'][ $hook_name ][] = $callback;
	return true;
}

function apply_filters( $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['atum_test_filters'][ $hook_name ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['atum_test_filters'][ $hook_name ] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}
	return $value;
}

function wp_send_json_success( $data = null, $status_code = 200 ) {
	throw new Atum_Test_Json_Response_Exception(
		array(
			'success' => true,
			'data'    => $data,
		),
		(int) $status_code,
		true
	);
}

function wp_send_json_error( $data = null, $status_code = 400 ) {
	throw new Atum_Test_Json_Response_Exception(
		array(
			'success' => false,
			'data'    => $data,
		),
		(int) $status_code,
		false
	);
}

function wp_safe_redirect( $location, $status = 302 ) {
	unset( $status );
	$GLOBALS['atum_test_redirect_url'] = (string) $location;
	throw new Atum_Test_Redirect_Exception( (string) $location );
}

function add_query_arg( $args, $url = '' ) {
	$base  = (string) $url;
	$query = http_build_query( $args );
	return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . $query;
}

function wp_die( $message = '' ) {
	throw new RuntimeException( (string) $message );
}

function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-' . str_pad( (string) random_int( 100000, 999999 ), 12, '0', STR_PAD_LEFT );
}

function wp_next_scheduled( $hook, $args = array() ) {
	unset( $args );
	if ( empty( $GLOBALS['atum_test_cron_events'][ $hook ] ) ) {
		return false;
	}
	return min( array_keys( $GLOBALS['atum_test_cron_events'][ $hook ] ) );
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	unset( $recurrence, $args );
	$timestamp = (int) $timestamp;
	if ( ! isset( $GLOBALS['atum_test_cron_events'][ $hook ] ) ) {
		$GLOBALS['atum_test_cron_events'][ $hook ] = array();
	}
	$GLOBALS['atum_test_cron_events'][ $hook ][ $timestamp ] = true;
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	unset( $args );
	$timestamp = (int) $timestamp;
	if ( ! isset( $GLOBALS['atum_test_cron_events'][ $hook ] ) ) {
		$GLOBALS['atum_test_cron_events'][ $hook ] = array();
	}
	$GLOBALS['atum_test_cron_events'][ $hook ][ $timestamp ] = true;
	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	unset( $args );
	$timestamp = (int) $timestamp;
	if ( isset( $GLOBALS['atum_test_cron_events'][ $hook ][ $timestamp ] ) ) {
		unset( $GLOBALS['atum_test_cron_events'][ $hook ][ $timestamp ] );
	}
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	unset( $args );
	unset( $GLOBALS['atum_test_cron_events'][ $hook ] );
	return 1;
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	unset( $override );
	$GLOBALS['atum_test_rest_routes'][ $namespace . $route ] = $args;
	return true;
}

function nocache_headers() {
	return true;
}

function paginate_links( $args = array() ) {
	unset( $args );
	return '';
}

function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? 'checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
	unset( $type, $name, $wrap, $other_attributes );
	echo '<button type="submit">' . esc_html( (string) $text ) . '</button>';
}

function settings_fields( $option_group ) {
	unset( $option_group );
}

function do_settings_sections( $page ) {
	unset( $page );
}

function register_setting( $option_group, $option_name, $args = array() ) {
	unset( $option_group, $option_name, $args );
	return true;
}

function add_settings_section( $id, $title, $callback, $page ) {
	unset( $id, $title, $callback, $page );
	return true;
}

function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
	unset( $id, $title, $callback, $page, $section, $args );
	return true;
}

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null ) {
	unset( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	return 'toplevel_page_atum.mailer';
}

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	unset( $to, $subject, $message, $headers, $attachments );
	return true;
}

require_once dirname( __DIR__ ) . '/includes/SettingsRepository.php';
require_once dirname( __DIR__ ) . '/includes/LogRepository.php';
require_once dirname( __DIR__ ) . '/includes/contracts/DeliveryProviderInterface.php';
require_once dirname( __DIR__ ) . '/includes/contracts/QueueRepositoryInterface.php';
require_once dirname( __DIR__ ) . '/includes/PostmarkClient.php';
require_once dirname( __DIR__ ) . '/includes/OptionQueueRepository.php';
require_once dirname( __DIR__ ) . '/includes/DbQueueRepository.php';
require_once dirname( __DIR__ ) . '/includes/MailInterceptor.php';
require_once dirname( __DIR__ ) . '/includes/AdminController.php';
require_once dirname( __DIR__ ) . '/includes/CliCommand.php';
require_once dirname( __DIR__ ) . '/includes/GitHubUpdater.php';
require_once dirname( __DIR__ ) . '/includes/Bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-atum-mailer.php';
