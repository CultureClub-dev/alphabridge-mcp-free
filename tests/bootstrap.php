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
/** @var array<string,int> $ab_test_transient_ttl */
$GLOBALS['ab_test_transient_ttl'] = array();
/** @var array<string,array<int,array{fn:callable,prio:int}>> $ab_test_filters */
$GLOBALS['ab_test_filters']    = array();
/** @var array<int,object> $ab_test_users */
$GLOBALS['ab_test_users']      = array();

/**
 * Reset every emulated store. Called from the test base class.
 */
function ab_test_reset(): void {
	$GLOBALS['ab_test_current_user'] = 0;
	$GLOBALS['ab_test_can']          = null;
	$GLOBALS['ab_test_multisite']    = false;
	$GLOBALS['ab_test_super_admins'] = array();
	$GLOBALS['ab_test_posts']        = array();
	$GLOBALS['ab_test_query']        = null;
	$GLOBALS['ab_test_json_fail']    = false;
	$GLOBALS['ab_test_options']    = array();
	$GLOBALS['ab_test_writes']     = array();
	$GLOBALS['ab_test_transients'] = array();
	$GLOBALS['ab_test_transient_ttl'] = array();
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
	// Die Laufzeit wird mitgeschrieben, weil genau sie zu prüfen ist: ein
	// Zähler, der bei jedem zugelassenen Schreiben eine volle Stunde bekommt,
	// beginnt erst nach einer Stunde ohne zugelassene Anfrage von vorn.
	$GLOBALS['ab_test_transient_ttl'][ $key ] = $ttl;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['ab_test_transients'][ $key ], $GLOBALS['ab_test_transient_ttl'][ $key ] );
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

/* ------------------------------------------- users, capabilities, posts */

/** @var int $ab_test_current_user */
$GLOBALS['ab_test_current_user'] = 0;
/** @var callable|null $ab_test_can Decides current_user_can(): fn( string $cap, array $args ): bool */
$GLOBALS['ab_test_can'] = null;
/** @var bool $ab_test_multisite */
$GLOBALS['ab_test_multisite'] = false;
/** @var int[] $ab_test_super_admins */
$GLOBALS['ab_test_super_admins'] = array();
/** @var array<int,object> $ab_test_posts */
$GLOBALS['ab_test_posts'] = array();

function get_current_user_id() {
	return (int) $GLOBALS['ab_test_current_user'];
}

/**
 * Capabilities are not emulated as a role model: each test says, through a
 * closure, exactly which checks pass. A test that forgets to say so gets
 * "no" for everything — the safe direction.
 */
function current_user_can( $cap, ...$args ) {
	$decide = $GLOBALS['ab_test_can'];
	return is_callable( $decide ) ? (bool) $decide( (string) $cap, $args ) : false;
}

function is_multisite() {
	return (bool) $GLOBALS['ab_test_multisite'];
}

function is_super_admin( $user_id = false ) {
	$id = ( false === $user_id ) ? get_current_user_id() : (int) $user_id;
	return in_array( $id, $GLOBALS['ab_test_super_admins'], true );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function ab_test_add_post( int $id, array $fields = array() ): object {
	$post = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Post ' . $id,
			'post_content'  => 'Text of post ' . $id,
			'post_excerpt'  => '',
			'post_parent'   => 0,
			'post_author'   => 1,
			'menu_order'    => 0,
			'post_name'     => 'post-' . $id,
			'post_date_gmt' => '2026-09-21 10:00:00',
			'post_modified_gmt' => '2026-09-21 10:00:00',
			'post_mime_type' => '',
		),
		$fields
	);
	$GLOBALS['ab_test_posts'][ $id ] = $post;
	return $post;
}

function get_post( $post = null ) {
	return $GLOBALS['ab_test_posts'][ (int) $post ] ?? null;
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** @var bool $ab_test_json_fail When true, wp_json_encode() fails like it does on unencodable input. */
$GLOBALS['ab_test_json_fail'] = false;

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	if ( ! empty( $GLOBALS['ab_test_json_fail'] ) ) {
		return false;
	}
	return json_encode( $data, $options, $depth );
}

/* -------------------------------------------------------- queries, summaries */

/** @var callable|null $ab_test_query Answers WP_Query: fn( array $args ): object[] */
$GLOBALS['ab_test_query'] = null;

class WP_Query {
	public $posts         = array();
	public $found_posts   = 0;
	public $max_num_pages = 0;
	public $args;

	public function __construct( $args = array() ) {
		$this->args          = $args;
		$answer              = $GLOBALS['ab_test_query'];
		$this->posts         = is_callable( $answer ) ? (array) $answer( $args ) : array();
		$this->found_posts   = count( $this->posts );
		$this->max_num_pages = $this->found_posts > 0 ? 1 : 0;
	}
}

function get_the_title( $post ) {
	return (string) $post->post_title;
}

function get_permalink( $post ) {
	return 'https://example.test/?p=' . (int) $post->ID;
}

// Like core: a protected post yields a placeholder, otherwise the stored
// excerpt or the start of the text.
function get_the_excerpt( $post ) {
	if ( '' !== (string) $post->post_password ) {
		return 'There is no excerpt because this is a protected post.';
	}
	return '' !== (string) $post->post_excerpt ? (string) $post->post_excerpt : substr( (string) $post->post_content, 0, 55 );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	return $single ? '' : array();
}

function wp_get_attachment_url( $id ) {
	return 'https://example.test/wp-content/uploads/' . (int) $id . '.jpg';
}

function wp_get_attachment_metadata( $id ) {
	return array();
}

/* ---------------------------------------------------------- admin ajax */

/**
 * wp_send_json_*() end the request. Here they end the handler by throwing,
 * so a test can read status and payload and assert on the state left behind.
 */
class AbTestJsonExit extends Exception {
	public $status;
	public $payload;

	public function __construct( int $status, $payload ) {
		parent::__construct( 'json exit ' . $status );
		$this->status  = $status;
		$this->payload = $payload;
	}
}

function wp_send_json_error( $data = null, $status_code = null ) {
	throw new AbTestJsonExit( (int) ( $status_code ?? 500 ), $data );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	throw new AbTestJsonExit( (int) ( $status_code ?? 200 ), $data );
}

function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
	return 1;
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

// Rendering helpers the connections row uses; the row's HTML is not under test.
function esc_attr( $text ) {
	return (string) $text;
}

function esc_attr__( $text, $domain = '' ) {
	return (string) $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	return '';
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $name . '=testnonce';
}

/**
 * The site's timezone as WordPress derives it: the named zone if one is set,
 * otherwise a fixed offset built from gmt_offset (hours, may be fractional).
 */
function wp_timezone_string() {
	$timezone_string = get_option( 'timezone_string' );
	if ( $timezone_string ) {
		return (string) $timezone_string;
	}
	$offset  = (float) get_option( 'gmt_offset' );
	$hours   = (int) $offset;
	$minutes = abs( ( $offset - $hours ) * 60 );
	return sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', abs( $hours ), $minutes );
}

function wp_timezone() {
	return new DateTimeZone( wp_timezone_string() );
}

/**
 * current_time() as WordPress defines it. 'timestamp' and 'U' are time() PLUS
 * the site's UTC offset unless $gmt is set — not a real Unix timestamp. The
 * former stand-in returned time() and so hid exactly the mistake of comparing
 * one kind of timestamp with the other («Last used», 26.09.2026).
 */
function current_time( $type = 'timestamp', $gmt = 0 ) {
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $gmt ? time() : time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}
	if ( 'mysql' === $type ) {
		$type = 'Y-m-d H:i:s';
	}
	$timezone = $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
	return ( new DateTime( 'now', $timezone ) )->format( (string) $type );
}

/**
 * WordPress rounds to minutes, hours, days; this stand-in prints the exact
 * seconds so a test can see a shift of any size. Like WordPress it measures
 * against time() when no second argument is given.
 */
function human_time_diff( $from, $to = 0 ) {
	if ( empty( $to ) ) {
		$to = time();
	}
	return abs( (int) $to - (int) $from ) . ' seconds';
}

/**
 * wp_date() as WordPress defines it: a real Unix timestamp shown in the
 * site's timezone (or the one given). Month and day names are not localised
 * here; numeric formats come out as in WordPress.
 */
function wp_date( $format, $timestamp = null, $timezone = null ) {
	if ( null === $timestamp ) {
		$timestamp = time();
	} elseif ( ! is_numeric( $timestamp ) ) {
		return false;
	}
	$datetime = date_create( '@' . (int) $timestamp );
	$datetime->setTimezone( $timezone ? $timezone : wp_timezone() );
	return $datetime->format( (string) $format );
}

/**
 * date_i18n() as WordPress (5.3 and later) defines it. The plugin no longer
 * calls it; the stand-in is faithful so that a return to it shows up as a
 * wrong time in the tests, not as a missing function. Given a timestamp, it
 * reads that number as ALREADY shifted to local time — its UTC clock reading
 * is taken as the local time. Handed a real Unix timestamp, it therefore
 * prints the UTC clock.
 */
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
	$timestamp = $timestamp_with_offset;
	if ( ! is_numeric( $timestamp ) ) {
		$timestamp = current_time( 'timestamp', $gmt );
	}
	if ( 'U' === $format ) {
		return $timestamp;
	}
	if ( $gmt && false === $timestamp_with_offset ) {
		return wp_date( $format, null, new DateTimeZone( 'UTC' ) );
	}
	if ( false === $timestamp_with_offset ) {
		return wp_date( $format );
	}
	$timezone = wp_timezone();
	$datetime = date_create( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), $timezone );
	return wp_date( $format, $datetime->getTimestamp(), $timezone );
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

define( 'AB_MCP_VERSION', '4.3.5' );
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );
define( 'AB_MCP_PROTOCOL_VERSION', '2025-06-18' );
define( 'AB_MCP_PATH', dirname( __DIR__ ) . '/' );

/* --------------------------------------------------------- classes to test */

require_once __DIR__ . '/../includes/class-tool-registry.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-audit-log.php';
require_once __DIR__ . '/../includes/class-review-notice.php';
require_once __DIR__ . '/../includes/class-auth.php';
require_once __DIR__ . '/../includes/class-oauth.php';
require_once __DIR__ . '/../includes/class-rest-controller.php';
require_once __DIR__ . '/../includes/tools/class-tools-content.php';
require_once __DIR__ . '/../includes/tools/class-tools-search-bulk.php';
require_once __DIR__ . '/../includes/tools/class-tools-media.php';
require_once __DIR__ . '/../includes/class-admin.php';
