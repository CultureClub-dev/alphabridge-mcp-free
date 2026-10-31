<?php
/**
 * AlphaBridge's own tools as WordPress abilities (AB_MCP_Own_Abilities),
 * against the Abilities API of WordPress 7.0.2 or 7.1.2 (the real classes,
 * fixed copies under tests/standalone-lib/support/), on the in-memory site
 * of tests/bootstrap.php with the real classes of this plugin: the tool
 * registry with every tool of tests/../includes/tools, the policy gate, the
 * confirmation token, the log and tools/call.
 *
 *   main   the switch off: no category, no ability; on: one ability per
 *          tool of this plugin except undo, the abilities tools, Site
 *          Deploy, Pro and Agency names, tools without a capability, tools
 *          a class or closure outside includes/ registers (Pro replacing a
 *          tool), switched-off tools; names, labels, descriptions, input
 *          schemas (no empty properties, a type for every property), no
 *          output schema, meta.mcp, no public and no show_in_rest, the
 *          annotations; the permission callback (capability, write access,
 *          the tool's switch, the offer switched off, a tool that is not
 *          offered any more, no count of the rate limit); the execute
 *          callback through the same code as tools/call (the same result,
 *          the argument check, the confirmation token of wp_delete_plugin,
 *          a tool's error, the log with via and without values, one count
 *          of the rate limit); wp_run_ability and wp_list_abilities never
 *          reach alphabridge/…, approved or not; the switch in the
 *          fine-tuning and its save.
 *   noapi  WordPress without the Abilities API: nothing registers, nothing
 *          fails, the switch says what is missing.
 *
 * Run: php tests/standalone-abilities/own-abilities-test.php main [7.0.2|7.1.2]
 *      php tests/standalone-abilities/own-abilities-test.php noapi
 *      AbilitiesStandaloneTest runs every scenario.
 *
 * @package AlphaBridge_MCP
 */

$scenario = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $scenario, array( 'main', 'noapi' ), true ) ) {
	fwrite( STDERR, "Szenario fehlt oder unbekannt: main oder noapi\n" );
	exit( 2 );
}
$wp_api = isset( $argv[2] ) ? $argv[2] : '7.0.2';
if ( ! in_array( $wp_api, array( '7.0.2', '7.1.2' ), true ) ) {
	fwrite( STDERR, "WordPress-Fassung der Abilities API unbekannt: 7.0.2 oder 7.1.2\n" );
	exit( 2 );
}
error_reporting( E_ALL );

$fail  = 0;
$count = 0;
function ok( $cond, $label ) {
	global $fail, $count;
	$count++;
	if ( ! $cond ) {
		$fail++;
		echo "  FAIL: $label\n";
	}
}
function ab_result( $title ) {
	global $fail, $count;
	if ( $fail ) {
		printf( "%s: FAILED (%d von %d)\n", $title, $fail, $count );
		exit( 1 );
	}
	printf( "ALLE %d %s-CHECKS BESTANDEN (0 Fehler)\n", $count, $title );
	exit( 0 );
}

/* ================================================== the site and the plugin */

require dirname( __DIR__ ) . '/bootstrap.php';
ab_test_reset();

// What the tools need beyond the shared bootstrap, as the PHPUnit tests load it.
require_once dirname( __DIR__ ) . '/undo-stubs.php';
require_once dirname( __DIR__ ) . '/wp-admin/includes/plugin.php';
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	}
}
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ) {
		return false;
	}
}
$free = dirname( __DIR__, 2 ) . '/';
require_once $free . 'includes/class-page-cache.php';
require_once $free . 'includes/class-post-status.php';
require_once $free . 'includes/undo/class-undo.php';
require_once $free . 'includes/undo/class-undo-restore.php';
require_once $free . 'includes/class-lifecycle-confirm.php';
require_once $free . 'includes/abilities/class-ability-broker.php';
require_once $free . 'includes/abilities/class-ability-switches.php';
foreach ( glob( $free . 'includes/tools/class-tools-*.php' ) as $file ) {
	require_once $file;
}
require_once $free . 'includes/abilities/class-own-abilities.php';

// The plugin's registry as AB_MCP_Plugin builds it: every tool class of
// includes/tools (99 tools, FreeSinceFourElevenTest), then what an add-on
// registers after it (ab_test_addon()).
$GLOBALS['ab_addon'] = null;
function ab_test_registry() {
	$r = new AB_MCP_Tool_Registry();
	foreach ( get_declared_classes() as $class ) {
		if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
			$class::register( $r );
		}
	}
	if ( is_callable( $GLOBALS['ab_addon'] ) ) {
		( $GLOBALS['ab_addon'] )( $r );
	}
	return $r;
}
final class AB_MCP_Plugin {
	public $registry;
	private static $one = null;
	public static function instance() {
		if ( null === self::$one ) {
			self::$one           = new self();
			self::$one->registry = ab_test_registry();
		}
		return self::$one;
	}
	/** A new registry, as on the next request. */
	public static function reboot() {
		self::$one = null;
		return self::instance();
	}
}

AB_MCP_Own_Abilities::hook();

/* ====================================================================== noapi */

if ( 'noapi' === $scenario ) {
	AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, true );
	ok( false === AB_MCP_Own_Abilities::available(), 'N0 without the Abilities API the class knows it' );
	AB_MCP_Own_Abilities::register_category();
	AB_MCP_Own_Abilities::register_abilities();
	ok( ! function_exists( 'wp_get_abilities' ), 'N1 switched on, the registration does nothing and fails on nothing' );
	$html = AB_MCP_Own_Abilities::fine_html();
	ok( false !== strpos( $html, 'This site runs WordPress 6.9.1. The Abilities API needs WordPress 6.9 or newer' ) && false !== strpos( $html, 'Dashboard → Updates' ), 'N2 the switch says what is missing and where it is done' );
	ok( false !== strpos( $html, 'name="' . AB_MCP_Own_Abilities::FIELD . '"' ), 'N3 the switch is there all the same (it is saved for the update)' );
	ab_result( 'OWN-ABILITIES (noapi)' );
}

/* ====================================================== the Abilities API */

$GLOBALS['ab_wrong'] = array();
function _doing_it_wrong( $fn, $msg, $ver ) {
	$GLOBALS['ab_wrong'][] = $fn . ': ' . $msg;
}
function did_action( $tag ) {
	return 'init' === $tag ? 1 : 0;
}
// Registration asks whether its action is running; do_action() of the
// bootstrap notes each action before it runs the callbacks.
function doing_action( $tag = null ) {
	$last = end( $GLOBALS['ab_test_actions'] );
	return is_array( $last ) && $last[0] === $tag;
}
/*
 * Stand-in for rest_validate_value_from_schema(), narrower than core: the
 * type (one or a list), required properties, and the types one level down.
 * Like core, it reads properties as an array: core's
 * rest_validate_object_value_from_schema() asks isset( $args['properties'][ $key ] ),
 * which fails with an Error on an object.
 */
function rest_validate_value_from_schema( $value, $schema, $param = '' ) {
	$is = static function ( $v, $type ) {
		switch ( $type ) {
			case 'object':  return is_array( $v ) || is_object( $v );
			case 'array':   return is_array( $v ) && array_values( $v ) === $v;
			case 'string':  return is_string( $v );
			case 'integer': return is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) );
			case 'number':  return is_int( $v ) || is_float( $v ) || ( is_string( $v ) && is_numeric( $v ) );
			case 'boolean': return is_bool( $v );
			case 'null':    return null === $v;
		}
		return true;
	};
	if ( ! isset( $schema['type'] ) ) {
		_doing_it_wrong( __FUNCTION__, sprintf( 'The "type" schema keyword for %s is required.', $param ), '5.5.0' );
	}
	$fits = static function ( $v, $s ) use ( $is ) {
		foreach ( isset( $s['type'] ) ? (array) $s['type'] : array() as $t ) {
			if ( $is( $v, $t ) ) {
				return true;
			}
		}
		return ! isset( $s['type'] );
	};
	if ( ! $fits( $value, $schema ) ) {
		return new WP_Error( 'rest_invalid_type', sprintf( '%s is not of type %s.', $param, implode( ',', (array) $schema['type'] ) ) );
	}
	if ( is_array( $value ) ) {
		foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $req ) {
			if ( ! array_key_exists( $req, $value ) ) {
				return new WP_Error( 'rest_property_required', sprintf( '%s is a required property of %s.', $req, $param ) );
			}
		}
		foreach ( $value as $k => $v ) {
			if ( isset( $schema['properties'][ $k ] ) ) {
				if ( ! isset( $schema['properties'][ $k ]['type'] ) ) {
					_doing_it_wrong( __FUNCTION__, sprintf( 'The "type" schema keyword for %s[%s] is required.', $param, $k ), '5.5.0' );
				} elseif ( ! $fits( $v, $schema['properties'][ $k ] ) ) {
					return new WP_Error( 'rest_invalid_type', sprintf( '%s[%s] is not of type %s.', $param, $k, implode( ',', (array) $schema['properties'][ $k ]['type'] ) ) );
				}
			}
		}
	}
	return true;
}
$sup = dirname( __DIR__ ) . '/standalone-lib/support/abilities-api' . ( '7.1.2' === $wp_api ? '-7.1.2' : '' ) . '/';
if ( '7.1.2' === $wp_api ) {
	require_once $sup . 'class-wp-filter-sentinel.php';
}
require_once $sup . 'class-wp-ability-category.php';
require_once $sup . 'class-wp-ability-categories-registry.php';
require_once $sup . 'class-wp-ability.php';
require_once $sup . 'class-wp-abilities-registry.php';
require_once $sup . 'abilities-api.php';

/**
 * The next request: a new tool registry and new registries of the
 * Abilities API, which fire their actions again on first use.
 */
function ab_request() {
	foreach ( array( 'WP_Abilities_Registry', 'WP_Ability_Categories_Registry' ) as $class ) {
		( new ReflectionProperty( $class, 'instance' ) )->setValue( null, null );
	}
	AB_MCP_Plugin::reboot();
	$GLOBALS['ab_wrong'] = array();
	wp_get_abilities();
}
/** The names of AlphaBridge's abilities registered now. */
function ab_own() {
	$out = array();
	foreach ( wp_get_abilities() as $ability ) {
		if ( 0 === strpos( $ability->get_name(), 'alphabridge/' ) ) {
			$out[] = $ability->get_name();
		}
	}
	sort( $out );
	return $out;
}
function ab_ability( $tool ) {
	$name = AB_MCP_Own_Abilities::ability_name( $tool );
	return wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
}
/** The log, newest last. */
function ab_log() {
	return (array) get_option( AB_MCP_Audit_Log::OPTION, array() );
}
function ab_last_log() {
	$log = ab_log();
	return (array) end( $log );
}
/** How often the rate limit has counted this minute, for user 1. */
function ab_rate() {
	return (int) get_transient( 'ab_mcp_rl_1_' . gmdate( 'YmdHi' ) );
}
/** An administrator of the test site: every capability. */
function ab_admin() {
	ab_test_add_user( 1 );
	$GLOBALS['ab_test_current_user'] = 1;
	$GLOBALS['ab_test_can']          = static function () {
		return true;
	};
}
/** A subscriber: read only. */
function ab_subscriber() {
	ab_test_add_user( 2 );
	$GLOBALS['ab_test_current_user'] = 2;
	$GLOBALS['ab_test_can']          = static function ( $cap ) {
		return 'read' === $cap;
	};
}
function ab_full() {
	AB_MCP_Settings::set( 'site_mode', 'full' );
}
function ab_read() {
	AB_MCP_Settings::set( 'site_mode', 'read' );
}

// A class and a closure outside includes/ of the plugin (this file): what
// AlphaBridge MCP Pro is to this plugin. Pro registers its tools into the
// same registry after this plugin, some under the names of this plugin's
// tools and with classes that extend this plugin's.
class AB_Test_Pro_Content extends AB_MCP_Tools_Content {
	public static function get_post( $a ) {
		$GLOBALS['ab_pro_ran'] = true;
		return array( 'pro' => true );
	}
}

/* ------------------------------------------------------------ A: switch off */

ab_admin();
ab_request();
ok( false === AB_MCP_Own_Abilities::enabled(), 'A0 off by default' );
ok( false === AB_MCP_Settings::defaults()[ AB_MCP_Own_Abilities::SETTING ], 'A0 the default in AB_MCP_Settings is false' );
update_option( AB_MCP_Settings::OPT_OPTIONS, array( 'site_mode' => 'full', 'rate_limit_per_min' => 120 ) );
ok( false === AB_MCP_Own_Abilities::enabled(), 'A1 a site updated from before 4.12.0 (options without the key) stays off' );
ab_request();
ok( array() === ab_own(), 'A2 switched off: no ability alphabridge/…' );
ok( false === wp_has_ability_category( 'alphabridge' ), 'A3 switched off: no category alphabridge' );
ok( count( wp_get_abilities() ) === 0 && array() === $GLOBALS['ab_wrong'], 'A4 and nothing else, no notice' );
ok( 10 === has_action( 'wp_abilities_api_init', array( 'AB_MCP_Own_Abilities', 'register_abilities' ) ) && 10 === has_action( 'wp_abilities_api_categories_init', array( 'AB_MCP_Own_Abilities', 'register_category' ) ) && 10 === has_action( 'ab_mcp_fine_save', array( 'AB_MCP_Own_Abilities', 'fine_save' ) ), 'A5 hooked into the Abilities API and the fine-tuning' );

/* ------------------------------------------------------------- B: switch on */

AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, true );
ok( true === AB_MCP_Own_Abilities::enabled(), 'B0 switched on' );
ab_request();
$registry = AB_MCP_Plugin::instance()->registry;
ok( 99 === count( $registry->all() ), 'B1 the registry holds the 99 tools of this plugin' );
$expected = array();
foreach ( $registry->all() as $tool => $def ) {
	if ( ! in_array( $tool, array( 'wp_undo', 'wp_undo_list', 'wp_undo_discard', 'wp_list_abilities', 'wp_run_ability' ), true ) ) {
		$expected[] = 'alphabridge/' . str_replace( '_', '-', $tool );
	}
}
sort( $expected );
$own = ab_own();
ok( 94 === count( $own ) && $expected === $own, 'B2 94 abilities: every tool but undo (3) and the two abilities tools, named alphabridge/<tool with - for _> (' . count( $own ) . ')' );
ok( array() === $GLOBALS['ab_wrong'], 'B2 registered without a notice: ' . implode( ' | ', $GLOBALS['ab_wrong'] ) );
$cat = wp_get_ability_category( 'alphabridge' );
ok( null !== $cat && 'AlphaBridge MCP' === $cat->get_label() && '' !== $cat->get_description(), 'B3 the category alphabridge' );
$a = ab_ability( 'wp_list_posts' );
$d = $registry->get( 'wp_list_posts' );
ok( null !== $a && 'List posts and pages' === $a->get_label(), 'B4 label: the English short name, as tools/list sends it' );
ok( null !== $a && $d['description'] === $a->get_description() && 'alphabridge' === $a->get_category(), 'B5 description: the tool\'s; category alphabridge' );
$schema = $a->get_input_schema();
ok( 'object' === $schema['type'] && array_keys( (array) $d['inputSchema']['properties'] ) === array_keys( $schema['properties'] ), 'B6 input schema: the tool\'s properties, type object' );
$all_typed = true;
$no_empty  = true;
$walk      = static function ( $s ) use ( &$walk, &$all_typed, &$no_empty ) {
	if ( array_key_exists( 'properties', $s ) ) {
		if ( ! is_array( $s['properties'] ) || array() === $s['properties'] ) {
			$no_empty = false;
			return;
		}
		foreach ( $s['properties'] as $p ) {
			if ( ! isset( $p['type'] ) && ! isset( $p['anyOf'] ) && ! isset( $p['oneOf'] ) ) {
				$all_typed = false;
			}
			$walk( $p );
		}
	}
	if ( isset( $s['items'] ) && is_array( $s['items'] ) ) {
		$walk( $s['items'] );
	}
};
$no_output = true;
$meta_ok   = true;
$hints_ok  = true;
foreach ( $own as $name ) {
	$ab   = wp_get_ability( $name );
	$tool = str_replace( '-', '_', substr( $name, strlen( 'alphabridge/' ) ) );
	$def  = $registry->get( $tool );
	$walk( $ab->get_input_schema() );
	$no_output = $no_output && array() === $ab->get_output_schema();
	$mcp       = $ab->get_meta_item( 'mcp' );
	$meta_ok   = $meta_ok && array( 'public' => true, 'type' => 'tool' ) === $mcp && false === $ab->get_meta_item( 'show_in_rest', null ) && true !== $ab->get_meta_item( 'public', null );
	$ann       = (array) $ab->get_meta_item( 'annotations' );
	$hints_ok  = $hints_ok
		&& AB_MCP_Tool_Registry::is_read_only( $tool, $def ) === $ann['readonly']
		&& AB_MCP_Tool_Registry::is_destructive( $tool, $def ) === $ann['destructive']
		&& AB_MCP_Tool_Registry::is_read_only( $tool, $def ) === $ann['idempotent']
		&& AB_MCP_Tool_Registry::is_open_world( $tool, $def ) === $ann['openWorldHint'];
}
ok( $all_typed, 'B7 every property of every input schema names a type (core\'s check wants one)' );
ok( $no_empty, 'B8 no input schema has empty properties' );
ok( array( 'type' => 'object' ) === ab_ability( 'wp_site_info' )->get_input_schema() && '{"type":"object"}' === wp_json_encode( ab_ability( 'wp_site_info' )->get_input_schema() ), 'B8 a tool without arguments: {"type":"object"}' );
ok( AB_MCP_Own_Abilities::ANY_TYPE === ab_ability( 'wp_update_post_meta' )->get_input_schema()['properties']['value']['type'], 'B9 a property without a type (value of wp_update_post_meta) takes every type' );
ok( $no_output, 'B10 no ability has an output schema' );
ok( $meta_ok, 'B11 meta.mcp = public true, type tool; show_in_rest false and no public true (' . $wp_api . ')' );
if ( '7.1.2' === $wp_api ) {
	ok( false === $a->get_meta_item( 'public' ) && false === $a->get_meta_item( 'show_in_rest' ), 'B11 WordPress 7.1 resolves public and show_in_rest to false' );
} else {
	ok( ! array_key_exists( 'public', $a->get_meta() ), 'B11 meta has no key public' );
}
ok( $hints_ok, 'B12 the annotations readonly, destructive, idempotent and openWorldHint of every ability are the registry\'s' );
ok( true === ab_ability( 'wp_delete_post' )->get_meta_item( 'annotations' )['destructive'] && true === ab_ability( 'wp_upload_media_from_url' )->get_meta_item( 'annotations' )['openWorldHint'] && true === $a->get_meta_item( 'annotations' )['readonly'], 'B12 e.g. wp_delete_post destructive, wp_upload_media_from_url open world, wp_list_posts read-only' );
$confirm_ok = true;
foreach ( array( 'wp_delete_plugin', 'wp_delete_theme', 'wp_update_core', 'wp_install_plugin_zip', 'wp_install_theme_zip' ) as $tool ) {
	$ab         = ab_ability( $tool );
	$confirm_ok = $confirm_ok && null !== $ab && isset( $ab->get_input_schema()['properties'][ AB_MCP_Confirm_Token::PARAM ] ) && false !== strpos( $ab->get_description(), AB_MCP_Confirm_Token::description_note() );
}
ok( $confirm_ok, 'B13 the five tools without a way back: confirm_token in the schema, the note in the description' );
ok( wp_json_encode( $a->get_input_schema() ) === wp_json_encode( AB_MCP_Own_Abilities::input_schema( $d['inputSchema'] ) ) && false !== strpos( (string) wp_json_encode( AB_MCP_Own_Abilities::input_schema( array( 'type' => 'object', 'properties' => (object) array() ) ) ), '"type":"object"' ), 'B14 input_schema() alone' );

/* ------------------------------------------------- C: what is not offered */

foreach ( array( 'wp_undo', 'wp_undo_list', 'wp_undo_discard' ) as $tool ) {
	ok( null === ab_ability( $tool ) && true === AB_MCP_Tool_Registry::is_read_only( 'wp_undo_list', $registry->get( 'wp_undo_list' ) ), "C1 undo: $tool is not offered" );
}
ok( null === ab_ability( 'wp_list_abilities' ) && null === ab_ability( 'wp_run_ability' ), 'C2 wp_list_abilities and wp_run_ability are not offered' );
$GLOBALS['ab_addon'] = static function ( $r ) {
	$core = array( 'AB_MCP_Tools_System', 'site_info' );
	$base = array(
		'description' => 'A tool of the test.',
		'capability'  => 'manage_options',
		'readonly'    => true,
		'callback'    => $core,
	);
	// Each excluded by one rule only; the control is offered.
	$r->register( 'wp_test_control', $base );
	$r->register( 'wp_deploy_custom', $base );
	$r->register( 'wp_db_query', $base );
	$r->register( 'wp_blueprint_apply', $base );
	$r->register( 'wp_test_nocap', array_merge( $base, array( 'capability' => null ) ) );
	$r->register( 'wp_test_emptycap', array_merge( $base, array( 'capability' => '' ) ) );
	// Pro replaces tools of this plugin: a class of its own that extends
	// this plugin's, the inherited method of such a class, a closure, a function.
	$r->register( 'wp_get_post', array_merge( $r->get( 'wp_get_post' ), array( 'callback' => array( 'AB_Test_Pro_Content', 'get_post' ) ) ) );
	$r->register( 'wp_list_posts', array_merge( $r->get( 'wp_list_posts' ), array( 'callback' => array( 'AB_Test_Pro_Content', 'list_posts' ) ) ) );
	$r->register( 'wp_get_media', array_merge( $r->get( 'wp_get_media' ), array( 'callback' => static function ( $a ) { return array(); } ) ) );
	$r->register( 'wp_get_term', array_merge( $r->get( 'wp_get_term' ), array( 'callback' => 'ab_test_registry' ) ) );
	$r->register( 'wp_get_comment', array_merge( $r->get( 'wp_get_comment' ), array( 'callback' => 'AB_Test_Pro_Content::get_post' ) ) );
};
ab_request();
ok( null !== ab_ability( 'wp_test_control' ), 'C3 control: a further tool with this plugin\'s callback and a capability is offered' );
ok( null === ab_ability( 'wp_deploy_custom' ), 'C4 Site Deploy: wp_deploy_… is not offered' );
ok( null === ab_ability( 'wp_db_query' ), 'C5 a name of AB_MCP_Guidance::PRO_TOOLS is not offered' );
ok( null === ab_ability( 'wp_blueprint_apply' ), 'C6 a name of AB_MCP_Guidance::AGENCY_TOOLS is not offered' );
ok( null === ab_ability( 'wp_test_nocap' ) && null === ab_ability( 'wp_test_emptycap' ), 'C7 a tool without a capability (null or \'\') is not offered' );
ok( null === ab_ability( 'wp_get_post' ), 'C8 a tool Pro replaced with a class of its own is not offered' );
ok( null === ab_ability( 'wp_list_posts' ), 'C9 nor with a method its class inherits from this plugin\'s' );
ok( null === ab_ability( 'wp_get_media' ) && null === ab_ability( 'wp_get_term' ) && null === ab_ability( 'wp_get_comment' ), 'C10 nor with a closure, a function or "Class::method" from outside includes/' );
ok( 94 - 5 + 1 === count( ab_own() ), 'C11 the rest stays offered (' . count( ab_own() ) . ')' );
ok( true === AB_MCP_Own_Abilities::is_own_callback( array( 'AB_MCP_Tools_Content', 'get_post' ) ) && true === AB_MCP_Own_Abilities::is_own_callback( 'AB_MCP_Connection_Doctor::tool' ) && false === AB_MCP_Own_Abilities::is_own_callback( array( 'AB_No_Such_Class', 'x' ) ) && false === AB_MCP_Own_Abilities::is_own_callback( 'no_such_function' ) && false === AB_MCP_Own_Abilities::is_own_callback( null ), 'C12 is_own_callback(): this plugin\'s classes under includes/ (also outside includes/tools), nothing unknown' );
$GLOBALS['ab_addon'] = null;
update_option( AB_MCP_Settings::OPT_TOOLSTATE, array( 'wp_get_cron' => false ) );
ab_request();
ok( null === ab_ability( 'wp_get_cron' ) && 93 === count( ab_own() ), 'C13 a switched-off tool is not offered, as tools/list does not list it' );
update_option( AB_MCP_Settings::OPT_TOOLSTATE, array() );
ab_request();

/* ------------------------------------------------- D: the permission callback */

ab_test_add_post( 7, array( 'post_title' => 'Seite sieben' ) );
ab_read();
$a = ab_ability( 'wp_list_posts' );
ok( true === $a->check_permissions( array() ), 'D1 an administrator may run a reading tool with write access off' );
ab_subscriber();
$res = $a->check_permissions( array( 'per_page' => 3, 'search' => 'geheimer-wert-d3' ) );
ok( is_wp_error( $res ) && 'ab_mcp_forbidden' === $res->get_error_code() && false !== strpos( $res->get_error_message(), '"edit_posts"' ) && false !== strpos( $res->get_error_message(), 'Direct link: https://example.test/wp-admin/users.php' ), 'D2 a subscriber is refused for lack of the capability, with the way' );
$last = ab_last_log();
ok( 'wp_list_posts' === $last['tool'] && 'denied' === $last['status'] && 'ability' === $last['via'] && 'per_page,search' === $last['keys'] && false === strpos( (string) wp_json_encode( ab_log() ), 'geheimer-wert-d3' ), 'D3 the refusal is logged: denied, via ability, the keys and not the values' );
ab_admin();
$res = ab_ability( 'wp_create_post' )->check_permissions( array( 'title' => 'x' ) );
ok( is_wp_error( $res ) && 'ab_mcp_read_mode' === $res->get_error_code() && false !== strpos( $res->get_error_message(), '#ab-mode' ), 'D4 a writing tool is refused while write access is off, with the way to the switch' );
$res = ab_ability( 'wp_get_error_log' )->check_permissions( array() );
ok( is_wp_error( $res ) && 'ab_mcp_read_mode' === $res->get_error_code(), 'D5 a Mighty reader too' );
ab_full();
ok( true === ab_ability( 'wp_create_post' )->check_permissions( array( 'title' => 'x' ) ), 'D6 with write access on it may' );
update_option( AB_MCP_Settings::OPT_TOOLSTATE, array( 'wp_create_post' => false ) );
$res = ab_ability( 'wp_create_post' )->check_permissions( array( 'title' => 'x' ) );
ok( is_wp_error( $res ) && 'ab_mcp_tool_disabled' === $res->get_error_code() && false !== strpos( $res->get_error_message(), '#ab-tool-wp_create_post' ), 'D7 a tool switched off after the registration is refused, with the way to its switch' );
update_option( AB_MCP_Settings::OPT_TOOLSTATE, array() );
$before = ab_rate();
for ( $i = 0; $i < 5; $i++ ) {
	ab_ability( 'wp_get_post' )->check_permissions( array( 'id' => 7 ) );
}
ok( $before === ab_rate(), 'D8 the permission callback does not count towards the rate limit' );
// The registry changes after the registration (an add-on, a later request).
$r       = AB_MCP_Plugin::instance()->registry;
$keep    = $r->get( 'wp_get_post' );
$r->register( 'wp_get_post', array_merge( $keep, array( 'capability' => null ) ) );
$res = ab_ability( 'wp_get_post' )->check_permissions( array( 'id' => 7 ) );
ok( is_wp_error( $res ) && 'ab_mcp_own_ability_not_offered' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'Settings → AlphaBridge MCP' ), 'D9 a tool that has no capability any more is refused (the check at the call)' );
$res = ab_ability( 'wp_get_post' )->execute( array( 'id' => 7 ) );
ok( is_wp_error( $res ), 'D9 and does not run' );
$r->register( 'wp_get_post', array_merge( $keep, array( 'callback' => array( 'AB_Test_Pro_Content', 'get_post' ) ) ) );
$GLOBALS['ab_pro_ran'] = false;
$res  = ab_ability( 'wp_get_post' )->check_permissions( array( 'id' => 7 ) );
$res2 = AB_MCP_Own_Abilities::execute( 'wp_get_post', array( 'id' => 7 ) );
ok( is_wp_error( $res ) && is_wp_error( $res2 ) && 'ab_mcp_own_ability_not_offered' === $res2->get_error_code() && false === $GLOBALS['ab_pro_ran'], 'D10 nor a tool Pro replaced after the registration, and its callback does not run' );
$last = ab_last_log();
ok( 'wp_get_post' === $last['tool'] && 'denied' === $last['status'] && 'ability' === $last['via'], 'D10 logged as denied, via ability' );
$r->register( 'wp_get_post', $keep );
AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, false );
$res  = ab_ability( 'wp_get_post' )->check_permissions( array( 'id' => 7 ) );
$res2 = AB_MCP_Own_Abilities::execute( 'wp_get_post', array( 'id' => 7 ) );
ok( is_wp_error( $res ) && 'ab_mcp_own_abilities_off' === $res->get_error_code() && is_wp_error( $res2 ) && 'ab_mcp_own_abilities_off' === $res2->get_error_code(), 'D11 switched off after the registration: refused, run or not' );
ok( false !== strpos( $res->get_error_message(), 'Settings → AlphaBridge MCP → Fine-tuning' ) && false !== strpos( $res->get_error_message(), '#ab-own-abilities' ), 'D11 with the way to the switch' );
AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, true );

/* ---------------------------------------------------- E: the execute callback */

ab_admin();
ab_read();
update_option( AB_MCP_Audit_Log::OPTION, array() );
$before = ab_rate();
$res    = ab_ability( 'wp_get_post' )->execute( array( 'id' => 7, 'include_meta' => false, 'note' => 'geheimer-wert-e3' ) );
ok( is_array( $res ) && 7 === (int) $res['id'] && 'Seite sieben' === $res['title'], 'E1 a reading tool runs through its ability and answers with its array' );
ok( $before + 1 === ab_rate(), 'E2 one run counts once towards the rate limit (' . ( ab_rate() - $before ) . ')' );
$last = ab_last_log();
ok( 'wp_get_post' === $last['tool'] && 'ok' === $last['status'] && 'ability' === $last['via'] && 'id,include_meta,note' === $last['keys'] && false === strpos( (string) wp_json_encode( ab_log() ), 'geheimer-wert-e3' ), 'E3 logged: ok, via ability, the keys, no values' );
// The same call through tools/call gives the same result, and its entry has no via.
$request = new WP_REST_Request();
$request->set_json_params( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_get_post', 'arguments' => array( 'id' => 7, 'include_meta' => false ) ) ) );
$data = ( new AB_MCP_REST_Controller( AB_MCP_Plugin::instance()->registry ) )->handle( $request )->get_data();
ok( isset( $data['result']['structuredContent'] ) && $data['result']['structuredContent'] === $res, 'E4 tools/call answers the same' );
$last = ab_last_log();
ok( 'wp_get_post' === $last['tool'] && 'ok' === $last['status'] && ! array_key_exists( 'via', $last ), 'E4 and its entry in the log has no via' );
$res = AB_MCP_Own_Abilities::execute( 'wp_get_post', array( 'include_meta' => true ) );
ok( is_wp_error( $res ) && 'ab_mcp_invalid_args' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'missing required "id"' ), 'E5 the arguments are checked as tools/call checks them' );
ok( 'denied' === ab_last_log()['status'] && 'ability' === ab_last_log()['via'], 'E5 logged as denied, via ability' );
$res = ab_ability( 'wp_get_post' )->execute( array( 'id' => 99 ) );
ok( is_wp_error( $res ) && 'ab_mcp_not_found' === $res->get_error_code() && 'error' === ab_last_log()['status'] && 'ability' === ab_last_log()['via'], 'E6 a tool\'s own error comes back as a WP_Error with its code, logged as error' );
$res = AB_MCP_Own_Abilities::execute( 'wp_create_post', array( 'title' => 'x' ) );
ok( is_wp_error( $res ) && 'ab_mcp_read_mode' === $res->get_error_code() && 'denied' === ab_last_log()['status'], 'E7 the execute callback checks the policy gate itself, also when called without the permission callback' );
// The confirmation token of a tool without a way back.
ab_full();
$GLOBALS['ab_test_plugins'] = array( 'hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ) );
$res = ab_ability( 'wp_delete_plugin' )->execute( array( 'plugin' => 'hello.php' ) );
ok( is_array( $res ) && true === $res['confirm_required'] && false === $res['done'] && is_string( $res[ AB_MCP_Confirm_Token::PARAM ] ) && 'Hello Dolly' === $res['preview']['name'], 'E8 the first call of wp_delete_plugin answers with a preview and a token' );
ok( isset( $GLOBALS['ab_test_plugins']['hello.php'] ) && 'preview' === ab_last_log()['status'] && 'ability' === ab_last_log()['via'], 'E8 nothing was deleted; logged as preview, via ability' );
$res = ab_ability( 'wp_delete_plugin' )->execute( array( 'plugin' => 'hello.php', AB_MCP_Confirm_Token::PARAM => 'v1.0.not-a-token' ) );
ok( is_wp_error( $res ) && 'denied' === ab_last_log()['status'] && isset( $GLOBALS['ab_test_plugins']['hello.php'] ), 'E9 a wrong token is refused, nothing deleted' );
ab_read();

/* ------------------------------ F: wp_run_ability never reaches alphabridge/… */

ab_full();
AB_MCP_Ability_Broker::set_on( array( 'alphabridge/*', 'alphabridge/wp-list-posts', 'alphabridge/wp-delete-plugin' ) );
add_filter( 'ab_mcp_ability_allowed', '__return_true' );
ok( array() === array_filter( array_keys( AB_MCP_Ability_Broker::all() ), static function ( $n ) { return 0 === strpos( $n, 'alphabridge/' ); } ), 'F1 the broker lists none of them' );
ok( null === AB_MCP_Ability_Broker::get( 'alphabridge/wp-list-posts' ) && false === AB_MCP_Ability_Broker::is_on( 'alphabridge/wp-list-posts' ), 'F2 nor gets one, approved or not' );
ok( true === AB_MCP_Ability_Broker::is_own( ' AlphaBridge/wp-list-posts ' ) && false === AB_MCP_Ability_Broker::is_own( 'alphabridge-x/wp-list-posts' ), 'F2 is_own() knows the namespace in any case and with spaces around, and no other' );
update_option( AB_MCP_Audit_Log::OPTION, array() );
foreach ( array( 'alphabridge/wp-list-posts', 'AlphaBridge/wp-list-posts', ' alphabridge/wp-delete-plugin ' ) as $n ) {
	$res = AB_MCP_Tools_Abilities::run_ability( array( 'name' => $n, 'apply' => true, 'input' => array( 'plugin' => 'hello.php' ) ) );
	ok( is_wp_error( $res ) && 'ab_mcp_ability_own' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'tools/list' ), "F3 wp_run_ability refuses \"$n\" with the way to the tool" );
}
ok( array() === ab_log() && isset( $GLOBALS['ab_test_plugins']['hello.php'] ), 'F3 and nothing of it ran' );
$list = AB_MCP_Tools_Abilities::list_abilities( array() );
ok( is_array( $list ) && array() === array_filter( (array) $list['abilities'], static function ( $row ) { return 0 === strpos( (string) $row['name'], 'alphabridge/' ); } ) && ! in_array( 'alphabridge', (array) $list['providers'], true ), 'F4 wp_list_abilities lists none of them' );
$res = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'alphabridge/wp-get-post' ) );
ok( is_wp_error( $res ) && 'ab_mcp_ability_own' === $res->get_error_code(), 'F5 asked by name, it answers with the way to the tool' );
$state = AB_MCP_Ability_Switches::state();
ok( ! isset( $state['groups']['alphabridge'] ) && array() === AB_MCP_Ability_Switches::registered( array( 'alphabridge/wp-list-posts' ) ), 'F6 the approvals in the fine-tuning show none and keep none' );
remove_filter( 'ab_mcp_ability_allowed', '__return_true' );
AB_MCP_Ability_Broker::set_on( array() );
ab_read();

/* --------------------------------------------- G: the switch in the fine-tuning */

$html = AB_MCP_Own_Abilities::fine_html();
ok( false !== strpos( $html, 'id="ab-own-abilities"' ) && false !== strpos( $html, 'name="ab_own_abilities" value="1" form="ab-caps" checked=\'checked\'' ) && false !== strpos( $html, 'name="ab_own_abilities_shown" value="1" form="ab-caps"' ), 'G1 the switch posts with the fine-tuning\'s form, on while it is on' );
ok( false === strpos( $html, 'ab-tool' ) && false === strpos( $html, 'ab-item' ), 'G2 neither ab-tool nor ab-item: the switches for all tools and the groups do not move it' );
ok( false !== strpos( $html, 'Offer AlphaBridge\'s tools as WordPress abilities, for the MCP Adapter' ) && false !== strpos( $html, 'application password' ) && false !== strpos( $html, 'with their WordPress rights' ) && false !== strpos( $html, 'write access on' ), 'G3 the text: what it offers, who can then use it, writing only with write access' );
AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, false );
ok( false === strpos( AB_MCP_Own_Abilities::fine_html(), 'checked' ), 'G4 off: not checked' );
update_option( AB_MCP_Audit_Log::OPTION, array() );
AB_MCP_Own_Abilities::fine_save( array( 'ab_own_abilities' => '1' ) );
ok( false === AB_MCP_Own_Abilities::enabled(), 'G5 a form that did not show the switch changes nothing' );
ab_subscriber();
AB_MCP_Own_Abilities::fine_save( array( 'ab_own_abilities_shown' => '1', 'ab_own_abilities' => '1' ) );
ok( false === AB_MCP_Own_Abilities::enabled(), 'G6 an account without manage_options changes nothing' );
ab_admin();
AB_MCP_Own_Abilities::fine_save( array( 'ab_own_abilities_shown' => '1', 'ab_own_abilities' => '1' ) );
ok( true === AB_MCP_Own_Abilities::enabled() && 'own_abilities' === ab_last_log()['tool'] && false !== strpos( ab_last_log()['message'], 'switched on by user1 (user 1)' ), 'G7 saved on, and the log says who' );
AB_MCP_Own_Abilities::fine_save( array( 'ab_own_abilities_shown' => '1' ) );
ok( false === AB_MCP_Own_Abilities::enabled() && false !== strpos( ab_last_log()['message'], 'switched off' ), 'G8 saved off (the box not ticked)' );
$n = count( ab_log() );
AB_MCP_Own_Abilities::fine_save( array( 'ab_own_abilities_shown' => '1' ) );
ok( $n === count( ab_log() ), 'G9 saving without a change writes nothing to the log' );
ab_request();
ok( array() === ab_own(), 'G10 off again: the next request registers nothing' );

/* ------------------------------------------- F: refusals do not flood the log */

// The adapter asks the permission callback before the run, which never
// reaches the rate limit: an account that may do nothing must not push the
// earlier entries out of the log (Codex, 30.10.2026).
AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, true );
ab_request();
ab_subscriber();
delete_transient( 'ab_mcp_own_denied_' . get_current_user_id() );
$before = count( ab_log() );
$mine   = 0;
for ( $i = 0; $i < 3 * AB_MCP_Own_Abilities::DENIED_LOG_PER_HOUR; $i++ ) {
	$res  = ab_ability( 'wp_list_posts' )->check_permissions( array( 'search' => 'x' ) );
	$mine = is_wp_error( $res ) ? $mine + 1 : $mine;
}
$logged = count( ab_log() ) - $before;
ok( 3 * AB_MCP_Own_Abilities::DENIED_LOG_PER_HOUR === $mine && AB_MCP_Own_Abilities::DENIED_LOG_PER_HOUR === $logged, 'F1 every call is refused, but only ' . AB_MCP_Own_Abilities::DENIED_LOG_PER_HOUR . ' refusals of one account are logged per hour (' . $logged . ')' );
ab_admin();
$res = ab_ability( 'wp_create_post' )->check_permissions( array( 'title' => 'x' ) );
ok( is_wp_error( $res ) && 'denied' === ab_last_log()['status'] && 'wp_create_post' === ab_last_log()['tool'], 'F2 another account\'s refusal is still logged' );
ab_subscriber();
$key   = 'ab_mcp_own_denied_' . get_current_user_id();
$state = get_transient( $key );
ok( is_array( $state ) && false === AB_MCP_Own_Abilities::denied_log_allowed( (int) $state['since'] + HOUR_IN_SECONDS - 1 ), 'F3 a second before the hour that began with the first entry is over: still nothing more' );
ok( true === AB_MCP_Own_Abilities::denied_log_allowed( (int) $state['since'] + HOUR_IN_SECONDS ), 'F3 from the end of that hour, fixed and not moved by the later entries, refusals are logged again' );
delete_transient( $key );
ab_admin();
AB_MCP_Settings::set( AB_MCP_Own_Abilities::SETTING, false );
ab_request();

ab_result( 'OWN-ABILITIES (main ' . $wp_api . ')' );
