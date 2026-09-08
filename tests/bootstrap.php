<?php
/**
 * Test bootstrap.
 *
 * The plugin ships without Composer and is loaded by WordPress, so the tests
 * stand up just enough of WordPress to load the classes under test: an
 * in-memory option store, the filter API, and the handful of helpers the
 * classes touch. That keeps the suite fast and dependency-free, and it means a
 * test failure points at plugin code rather than at a WordPress fixture.
 *
 * What is deliberately NOT emulated: the REST dispatcher, capabilities and the
 * database. Anything that needs those belongs in the end-to-end run against a
 * real site, not here.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

/* ----------------------------------------------------------- option store */

/** @var array<string,mixed> $ab_test_options */
$GLOBALS['ab_test_options']    = array();
/** @var array<string,int> $ab_test_writes */
$GLOBALS['ab_test_writes']     = array();
/** @var array<string,mixed> $ab_test_transients */
$GLOBALS['ab_test_transients'] = array();
/** @var array<string,array<int,array{fn:callable,prio:int}>> $ab_test_filters */
$GLOBALS['ab_test_filters']    = array();
/** @var array<int,object> $ab_test_users */
$GLOBALS['ab_test_users']      = array();

/**
 * Reset every emulated store. Called from the test base class.
 */
function ab_test_reset(): void {
	$GLOBALS['ab_test_options']    = array();
	$GLOBALS['ab_test_writes']     = array();
	$GLOBALS['ab_test_transients'] = array();
	$GLOBALS['ab_test_filters']    = array();
	$GLOBALS['ab_test_users']      = array();
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ab_test_options'] ) ? $GLOBALS['ab_test_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['ab_test_options'][ $name ] = $value;
	// Counted so a test can prove that a request which changes nothing also
	// writes nothing. On a real site every write is a database round trip.
	$GLOBALS['ab_test_writes'][ $name ] = ( $GLOBALS['ab_test_writes'][ $name ] ?? 0 ) + 1;
	return true;
}

/**
 * How often update_option() was called for one option since the last reset.
 */
function ab_test_writes( string $name ): int {
	return $GLOBALS['ab_test_writes'][ $name ] ?? 0;
}

function delete_option( $name ) {
	unset( $GLOBALS['ab_test_options'][ $name ] );
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['ab_test_transients'] ) ? $GLOBALS['ab_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['ab_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['ab_test_transients'][ $key ] );
	return true;
}

/* -------------------------------------------------------------- filter API */

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ab_test_filters'][ $hook ][] = array(
		'fn'   => $callback,
		'prio' => $priority,
	);
	usort(
		$GLOBALS['ab_test_filters'][ $hook ],
		static fn( array $a, array $b ): int => $a['prio'] <=> $b['prio']
	);
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $entry ) {
		$value = ( $entry['fn'] )( $value, ...$args );
	}
	return $value;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

/* ------------------------------------------------------------- WP helpers */

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	if ( ! is_array( $args ) ) {
		parse_str( (string) $args, $args );
	}
	return array_merge( (array) $defaults, $args );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function get_bloginfo( $what = '' ) {
	return 'version' === $what ? '6.9.1' : 'Example Site';
}

/**
 * Minimal stand-in for WP_User: the plugin only reads ->ID.
 */
class WP_User {
	public $ID;

	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

function ab_test_add_user( int $id ): void {
	$GLOBALS['ab_test_users'][ $id ] = new WP_User( $id );
}

function get_user_by( $field, $value ) {
	if ( 'id' !== $field ) {
		return false;
	}
	return $GLOBALS['ab_test_users'][ (int) $value ] ?? false;
}

/**
 * Minimal stand-in for WP_REST_Response.
 */
class WP_REST_Response {
	public $data;
	public $status;
	/** @var array<string,string> */
	public $headers = array();

	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_data() {
		return $this->data;
	}
}

/**
 * Minimal stand-in for WP_REST_Request: body params, JSON params and headers.
 */
class WP_REST_Request {
	/** @var array<string,mixed> */
	private $body = array();
	/** @var array<string,mixed>|null */
	private $json = null;
	/** @var array<string,string> */
	private $headers = array();

	public function set_body_params( array $params ): void {
		$this->body = $params;
	}

	public function set_json_params( array $params ): void {
		$this->json = $params;
	}

	public function set_header( string $key, string $value ): void {
		$this->headers[ strtolower( $key ) ] = $value;
	}

	public function get_body_params(): array {
		return $this->body;
	}

	public function get_json_params() {
		return $this->json;
	}

	public function get_header( $key ) {
		return $this->headers[ strtolower( (string) $key ) ] ?? '';
	}

	public function get_param( $key ) {
		return $this->body[ $key ] ?? null;
	}
}

/* ------------------------------------------------------- plugin constants */

// WordPress time constants the plugin relies on.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'AB_MCP_VERSION', '4.3.0' );
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );
define( 'AB_MCP_PROTOCOL_VERSION', '2025-06-18' );
define( 'AB_MCP_PATH', dirname( __DIR__ ) . '/' );

/* --------------------------------------------------------- classes to test */

require_once __DIR__ . '/../includes/class-tool-registry.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-auth.php';
require_once __DIR__ . '/../includes/class-oauth.php';
require_once __DIR__ . '/../includes/class-rest-controller.php';
