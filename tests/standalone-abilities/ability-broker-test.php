<?php
/**
 * The abilities broker (wp_list_abilities, wp_run_ability) against the
 * WordPress Abilities API of core 7.0.2 — the real classes, as fixed copies
 * under tests/standalone-lib/support/abilities-api/ — on the in-memory site
 * of tests/standalone-lib/builder-harness.php.
 *
 * Free since 4.12.0, taken over from AlphaBridge MCP Pro (its
 * test/ability-broker-test.php) with the class names of this plugin and one
 * change of behaviour: an ability runs only once an administrator approved
 * it (Pro: every ability on until switched off). So Pro's blocks on the
 * carry-over of 4.6 and on switching every ability on with write access are
 * the blocks OI here, and Pro's card for a core before 4.5.0 is gone.
 *
 *   main    nothing approved: nothing runs, the listing marks each ability
 *           and names the way; switching on write access approves nothing;
 *           AlphaBridge's own abilities never run, approved or not; then,
 *           with the abilities approved: listing (whether each ability can
 *           run, and the way where not), the approvals (by name, a
 *           namespace pattern, the last-word filter), preview, the
 *           ability's own input and permission checks, the undo point taken
 *           BEFORE the call, read-back with sha256 per copy, keys the call
 *           added, a restore byte for byte, the silent failure, a failure
 *           that changed something, an ability without a post (no undo),
 *           post fields, locks, the size limit, the access level, the log
 *           (no input values); internal and contradictory abilities, more
 *           posts than one undo point takes, an id that names no post, a
 *           post the call deletes, a draft staged in the autosave, no title
 *           of a post the account may not edit, a failure to record the undo
 *           point; write access off (running waits for it; the listing, the
 *           instructions and the switches say so, each with its way); the
 *           switches in the fine-tuning (main group «Abilities of other
 *           plugins»: one form, the badges Reads and Writes only, «deletes»
 *           as text, field names other than Pro's); every refusal with
 *           reason, steps, link and the request to pass it on;
 *   noapi   WordPress without the Abilities API: both tools say so, with the way;
 *   scope   the real tool registry: wp_run_ability only on a full token,
 *           wp_list_abilities on every token; an add-on that registers the
 *           two after this plugin replaces them, and the switches step back;
 *   writers the page-builder writers and the undo of a staged draft ask
 *           this plugin's broker where Pro is not loaded, and Pro's where it
 *           is (a stand-in with Pro's methods).
 *
 * Run: php tests/standalone-abilities/ability-broker-test.php main|noapi|scope|writers|extra [7.0.2|7.1.2]
 *      The second argument picks the Abilities API: of WordPress 7.0.2 (the
 *      default) or of 7.1.2, under tests/standalone-lib/support/
 *      abilities-api-7.1.2/, which resolves show_in_rest from its new meta
 *      public. AbilitiesStandaloneTest runs every scenario.
 */

$scenario = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $scenario, array( 'main', 'noapi', 'scope', 'writers', 'extra' ), true ) ) {
	fwrite( STDERR, "Szenario fehlt oder unbekannt: main, noapi, scope, writers oder extra\n" );
	exit( 2 );
}
$wp_api = isset( $argv[2] ) ? $argv[2] : '7.0.2';
if ( ! in_array( $wp_api, array( '7.0.2', '7.1.2' ), true ) ) {
	fwrite( STDERR, "WordPress-Fassung der Abilities API unbekannt: 7.0.2 oder 7.1.2\n" );
	exit( 2 );
}

/* ================================================== scope: the real registry */

if ( 'scope' === $scenario ) {
	error_reporting( E_ALL );
	define( 'ABSPATH', __DIR__ . '/' );
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
	function __( $s, $d = null ) { return $s; }
	// wp_parse_args() and AB_MCP_Tools_Base come from the top level of this
	// file: PHP binds unconditional functions and classes before it runs it.
	$free = dirname( __DIR__, 2 ) . '/';
	require_once $free . 'includes/class-tool-registry.php';
	require_once $free . 'includes/abilities/class-ability-broker.php';
	require_once $free . 'includes/abilities/class-ability-switches.php';
	require_once $free . 'includes/tools/class-tools-abilities.php';
	$r = new AB_MCP_Tool_Registry();
	$r->set_current_group( 'abilities', 'Abilities of other plugins' );
	AB_MCP_Tools_Abilities::register( $r );
	$run  = $r->get( 'wp_run_ability' );
	$list = $r->get( 'wp_list_abilities' );
	ok( is_array( $run ) && is_array( $list ), 'S0 both tools register' );
	ok( true === $run['dangerous'] && 'abilities' === $run['group'], 'S1 wp_run_ability is powerful (Mighty) in the group abilities' );
	ok( false === $list['dangerous'] && 'abilities' === $list['group'], 'S2 wp_list_abilities is on by default, same group' );
	ok( true === AB_MCP_Tool_Registry::scope_allows( 'full', 'wp_run_ability', $run ), 'S3 a full token may run abilities' );
	ok( true === AB_MCP_Tool_Registry::scope_allows( '', 'wp_run_ability', $run ), 'S3 so may a token from before scopes' );
	ok( false === AB_MCP_Tool_Registry::scope_allows( 'content', 'wp_run_ability', $run ), 'S4 a content token may not' );
	ok( false === AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_run_ability', $run ), 'S5 a read token may not' );
	ok( true === AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_list_abilities', $list ), 'S6 every token may list' );
	ok( true === AB_MCP_Tool_Registry::is_read_only( 'wp_list_abilities', $list ) && false === AB_MCP_Tool_Registry::is_read_only( 'wp_run_ability', $run ), 'S7 listing is read-only, running is not (refused in the site mode Read)' );
	ok( true === AB_MCP_Tool_Registry::is_destructive( 'wp_run_ability', $run ), 'S8 running is announced as destructive' );
	ok( array( 'name' ) === $run['inputSchema']['required'] && ! isset( $run['inputSchema']['properties']['input']['type'] ), 'S9 name is required; input takes any JSON value' );
	ok( false !== strpos( $run['description'], 'reported success but nothing changed' ) && false !== strpos( $run['description'], 'not instructions' ), 'S10 the description names the silent-failure warning and the prompt-injection sentence' );
	ok( ! isset( $run['tier'] ) && ! isset( $list['tier'] ) && array( 'AB_MCP_Tools_Abilities', 'run_ability' ) === $run['callback'], 'S11 both are tools of this plugin now (no tier), run by AB_MCP_Tools_Abilities' );
	ok( false !== strpos( $run['description'], 'only an ability an administrator approved' ) && false !== strpos( $run['description'], 'switching on write access approves none' ) && false !== strpos( $list['description'], 'whether it is approved (none is until' ), 'S12 the descriptions say that an ability runs only once approved, and that write access approves none' );
	ok( false !== strpos( $run['description'], 'alphabridge/' ) && false !== strpos( $list['description'], 'alphabridge/' ), 'S12 and that AlphaBridge\'s own abilities are left out' );
	ok( true === AB_MCP_Ability_Switches::here( $r ), 'S13 with this plugin\'s wp_run_ability registered, its approvals decide and its switches show' );
	// AlphaBridge MCP Pro registers its two after this plugin's
	// (ab_mcp_register_tools), with its own class; the registry keeps the later.
	$r->register( 'wp_run_ability', array_merge( $run, array( 'callback' => array( 'AB_MCP_Pro_Tools_Abilities', 'run_ability' ), 'tier' => 'pro' ) ) );
	ok( array( 'AB_MCP_Pro_Tools_Abilities', 'run_ability' ) === $r->get( 'wp_run_ability' )['callback'] && false === AB_MCP_Ability_Switches::here( $r ), 'S14 an add-on that registers wp_run_ability after this plugin replaces it, and this plugin\'s switches step back for its own' );
	ok( false === AB_MCP_Ability_Switches::here( new stdClass() ), 'S14 without a registry nothing is this plugin\'s' );
	if ( $fail ) {
		printf( "ABILITY-BROKER (scope): FAILED (%d von %d)\n", $fail, $count );
		exit( 1 );
	}
	printf( "ALLE %d ABILITY-BROKER (scope)-CHECKS BESTANDEN (0 Fehler)\n", $count );
	exit( 0 );
}

/* ======================================================= the shared site */

require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';
// Child process CG11: WordPress's own shutdown function (shutdown_action_hook()),
// registered at the start of the request, before anything of AlphaBridge.
if ( 'gate_exit_wp' === ( isset( $argv[3] ) ? $argv[3] : '' ) ) {
	register_shutdown_function( static function () { do_action( 'shutdown' ); } );
}

$GLOBALS['wp_version_test'] = 'noapi' === $scenario ? '6.8.3' : $wp_api;
function get_bloginfo( $what = '' ) { return 'version' === $what ? $GLOBALS['wp_version_test'] : 'Testsite'; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function wp_nonce_field( $a = '', $n = '_wpnonce', $r = true, $echo = true ) { return '<input type="hidden" name="' . $n . '" value="nonce">'; }
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? ' checked="checked"' : ''; }

// The core's log, as a list.
class AB_MCP_Audit_Log {
	public static $rows = array();
	public static function record( $tool, $args, $status, $message = null ) {
		self::$rows[] = array( 'tool' => $tool, 'keys' => is_array( $args ) ? implode( ',', array_keys( $args ) ) : '', 'status' => $status, 'message' => (string) $message );
	}
}

// The undo tool needs the base class of the core's tools; only its argument readers.
abstract class AB_MCP_Tools_Base {
	protected static function s( $a, $k, $d = '' ) { return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d; }
	protected static function i( $a, $k, $d = 0 ) { return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d; }
	protected static function b( $a, $k, $d = false ) { return isset( $a[ $k ] ) ? (bool) $a[ $k ] : $d; }
}
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-undo-core.php';
// The way of every refusal is AB_MCP_Guidance's, which the harness loads.
require_once AB_MCP_PRO_DIR . 'includes/abilities/class-ability-broker.php';
require_once AB_MCP_PRO_DIR . 'includes/abilities/class-ability-switches.php';
AB_MCP_Ability_Switches::hook();
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-abilities.php';
if ( ! function_exists( '_x' ) ) {
	function _x( $s, $c, $d = null ) { return $s; }
}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_text_field( $v ) { return is_array( $v ) ? '' : trim( strip_tags( (string) $v ) ); }
require_once AB_MCP_PRO_DIR . 'includes/class-admin.php';

// The plugin's tool registry as AB_MCP_Ability_Switches::here() asks it: the
// definition of wp_run_ability, by default this plugin's.
$GLOBALS['ab_run_callback'] = array( 'AB_MCP_Tools_Abilities', 'run_ability' );
class AB_Test_Registry {
	public function get( $name ) { return 'wp_run_ability' === $name ? array( 'callback' => $GLOBALS['ab_run_callback'] ) : null; }
}
class AB_MCP_Plugin {
	public $registry;
	public static function instance() {
		static $one = null;
		if ( null === $one ) {
			$one           = new self();
			$one->registry = new AB_Test_Registry();
		}
		return $one;
	}
}

/* ============================================================ noapi */

if ( 'noapi' === $scenario ) {
	ok( false === AB_MCP_Ability_Broker::available(), 'N0 without the Abilities API the broker knows it' );
	$l = AB_MCP_Tools_Abilities::list_abilities( array() );
	$r = AB_MCP_Tools_Abilities::run_ability( array( 'name' => 'core/get-site-info' ) );
	foreach ( array( 'list' => $l, 'run' => $r ) as $k => $res ) {
		ok( is_wp_error( $res ) && 'ab_mcp_abilities_unavailable' === $res->get_error_code(), "N1 $k answers that the API is missing" );
		ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'WordPress 6.8.3' ) && false !== strpos( $res->get_error_message(), '6.9' ) && false !== strpos( $res->get_error_message(), 'Dashboard › Updates' ), "N2 $k names the version, the one needed and the way" );
	}
	ok( '' === AB_MCP_Ability_Broker::instructions(), 'N3 no sentence in the server instructions' );
	$fine = AB_MCP_Ability_Switches::fine_html( AB_MCP_Ability_Switches::state() );
	ok( false !== strpos( $fine, 'WordPress 6.8.3' ) && false !== strpos( $fine, 'WordPress 6.9' ) && false !== strpos( $fine, 'Dashboard → Updates' ) && false === strpos( $fine, '<form' ) && false === strpos( $fine, AB_MCP_Ability_Switches::FIELD_ON ), 'N5 in the fine-tuning: one sentence that WordPress 6.9 is needed, in the core\'s words, and no switches' );
	ab_result( 'ABILITY-BROKER (noapi)' );
}

/* ================================================= main: the Abilities API */

$GLOBALS['ab_wrong'] = array();
function _doing_it_wrong( $fn, $msg, $ver ) { $GLOBALS['ab_wrong'][] = $fn . ': ' . $msg; }
$GLOBALS['ab_init'] = 1;
function did_action( $tag ) { return 'init' === $tag ? ( isset( $GLOBALS['ab_init'] ) ? (int) $GLOBALS['ab_init'] : 1 ) : 0; }
// Registration asks whether its action is running; do_action() of the
// harness notes each action before it runs the callbacks.
function doing_action( $tag = null ) {
	$last = end( $GLOBALS['ab_actions'] );
	return is_array( $last ) && $last[0] === $tag;
}
/*
 * Stand-in for rest_validate_value_from_schema(), narrower than core: type
 * (one or a list; object, array, string, integer, number, boolean, null),
 * required properties, the types of the properties one level down, and
 * additionalProperties false. Enough for the schemas below.
 */
function rest_validate_value_from_schema( $value, $schema, $param = '' ) {
	$is = static function ( $v, $type ) {
		switch ( $type ) {
			case 'object':  return is_array( $v ) || is_object( $v );
			case 'array':   return is_array( $v ) && array_values( $v ) === $v;
			case 'string':  return is_string( $v );
			case 'integer': return is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) );
			case 'number':  return is_int( $v ) || is_float( $v );
			case 'boolean': return is_bool( $v );
			case 'null':    return null === $v;
		}
		return true;
	};
	$types = isset( $schema['type'] ) ? (array) $schema['type'] : array();
	if ( $types ) {
		$fit = false;
		foreach ( $types as $t ) { $fit = $fit || $is( $value, $t ); }
		if ( ! $fit ) { return new WP_Error( 'rest_invalid_type', sprintf( '%s is not of type %s.', $param, implode( ',', $types ) ) ); }
	}
	if ( is_array( $value ) && isset( $schema['properties'] ) ) {
		foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $req ) {
			if ( ! array_key_exists( $req, $value ) ) { return new WP_Error( 'rest_property_required', sprintf( '%s is a required property of %s.', $req, $param ) ); }
		}
		foreach ( $value as $k => $v ) {
			if ( isset( $schema['properties'][ $k ]['type'] ) && ! $is( $v, $schema['properties'][ $k ]['type'] ) ) {
				return new WP_Error( 'rest_invalid_type', sprintf( '%s[%s] is not of type %s.', $param, $k, $schema['properties'][ $k ]['type'] ) );
			}
			if ( ! isset( $schema['properties'][ $k ] ) && isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
				return new WP_Error( 'rest_additional_properties_forbidden', sprintf( '%s is not a valid property of Object.', $k ) );
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

/*
 * WordPress 7.1.2's writes when a plugin is activated and a theme switched,
 * in their order (wp-admin/includes/plugin.php activate_plugin(),
 * wp-includes/theme.php switch_theme()), narrower: no sandbox, one theme
 * root, not in wp-admin, the theme switched to is a block theme.
 */
function ab_activate_plugin( $file ) {
	$current = get_option( 'active_plugins', array() );
	if ( in_array( $file, $current, true ) ) {
		return null;
	}
	do_action( 'activate_plugin', $file, false );
	// The plugin's activation routine.
	update_option( 'activation_routine_ran', $file );
	$current   = get_option( 'active_plugins', array() );
	$current[] = $file;
	sort( $current );
	update_option( 'active_plugins', $current );
	do_action( 'activated_plugin', $file, false );
	return array( 'activated' => in_array( $file, (array) get_option( 'active_plugins', array() ), true ) );
}
function ab_set_theme_mod( $name, $value ) {
	$option          = 'theme_mods_' . get_option( 'stylesheet' );
	$mods            = (array) get_option( $option, array() );
	$mods[ $name ]   = $value;
	update_option( $option, $mods );
}
function ab_switch_theme( $stylesheet ) {
	ab_set_theme_mod( 'sidebars_widgets', array( 'time' => 1, 'data' => get_option( 'sidebars_widgets' ) ) );
	$mods = (array) get_option( 'theme_mods_' . get_option( 'stylesheet' ), array() );
	update_option( 'theme_switch_menu_locations', isset( $mods['nav_menu_locations'] ) ? $mods['nav_menu_locations'] : false, true );
	$old = (string) get_option( 'stylesheet' );
	update_option( 'template', $stylesheet );
	update_option( 'stylesheet', $stylesheet );
	delete_option( 'template_root' );
	delete_option( 'stylesheet_root' );
	update_option( 'current_theme', 'Theme ' . $stylesheet );
	ab_set_theme_mod( 'wp_classic_sidebars', array( 'sidebar-1' => array() ) );
	update_option( 'theme_switched', $old );
	// wp_set_option_autoload_values(): straight in the table, past every
	// option filter, only rows that are there, 'yes' as on and 'no' as off.
	foreach ( array( 'theme_mods_' . $stylesheet => 'on', 'theme_mods_' . $old => 'off' ) as $name => $flag ) {
		if ( array_key_exists( $name, $GLOBALS['ab_opts'] ) ) {
			$GLOBALS['ab_autoload'][ $name ] = $flag;
		}
	}
	do_action( 'switch_theme', 'Theme ' . $stylesheet );
}

/* ---- the abilities of the test plugins ---- */

$GLOBALS['calls']  = array();
$GLOBALS['points'] = array();
function ab_points() { return count( (array) get_option( AB_MCP_Undo::OPTION, array() ) ); }
function ab_ability( $name, array $args ) {
	wp_register_ability(
		$name,
		array_merge(
			array(
				'label'               => 'Label ' . $name,
				'description'         => 'Description of ' . $name,
				'category'            => 'test',
				'permission_callback' => static function () { return true; },
				// Published, as vendors publish what they offer to outside
				// callers; the internal ones below say otherwise.
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true ),
			),
			$args
		)
	);
}
$id_schema   = array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array( 'id' => array( 'type' => 'integer' ) ) );
$meta_schema = array( 'type' => 'object', 'required' => array( 'post_id', 'key', 'value' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'key' => array( 'type' => 'string' ), 'value' => array( 'type' => 'string' ) ), 'additionalProperties' => false );
add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category( 'test', array( 'label' => 'Test', 'description' => 'Abilities of the test plugins.' ) );
	}
);
add_action(
	'wp_abilities_api_init',
	static function () use ( $id_schema, $meta_schema ) {
		$readonly = array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true );
		ab_ability( 'test/read-info', array(
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'fields' => array( 'type' => 'array' ) ), 'default' => array() ),
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'read-info'; return isset( $GLOBALS['read_result'] ) ? $GLOBALS['read_result'] : array( 'name' => 'Testsite' ); },
			'meta'             => $readonly,
		) );
		ab_ability( 'test/no-input', array(
			'execute_callback' => static function () { $GLOBALS['calls'][] = 'no-input'; return 'pong'; },
			'meta'             => $readonly,
		) );
		ab_ability( 'test/sneaky-read', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { update_post_meta( (int) $input['id'], 'sneaky', 'x' ); return array( 'ok' => true ); },
			'meta'             => $readonly,
		) );
		ab_ability( 'test/set-meta', array(
			'input_schema'     => $meta_schema,
			'execute_callback' => static function ( $input ) {
				$GLOBALS['calls'][]  = 'set-meta';
				$GLOBALS['points'][] = ab_points();
				update_post_meta( (int) $input['post_id'], (string) $input['key'], (string) $input['value'] );
				return array( 'updated' => true );
			},
		) );
		ab_ability( 'test/silent', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				$GLOBALS['calls'][] = 'silent';
				if ( isset( $GLOBALS['silent_write'] ) ) {
					update_post_meta( (int) $input['id'], 'farbe', $GLOBALS['silent_write'] );
				}
				return isset( $GLOBALS['silent_result'] ) ? $GLOBALS['silent_result'] : array( 'success' => true );
			},
		) );
		ab_ability( 'test/content-fail', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				$GLOBALS['db_posts'][ (int) $input['id'] ]['post_content'] = 'halb geschrieben';
				return new WP_Error( 'boom', 'Could not finish the layout' );
			},
		) );
		ab_ability( 'test/fail-clean', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'fail-clean'; return new WP_Error( 'nope', 'Refused by the plugin' ); },
		) );
		ab_ability( 'test/global-flush', array(
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string' ) ) ),
			'execute_callback' => static function ( $input ) { update_option( 'test_flushed', 1 ); return array( 'done' => true ); },
		) );
		ab_ability( 'test/admin-only', array(
			'input_schema'        => $id_schema,
			'permission_callback' => static function ( $input ) { return current_user_can( 'manage_options' ); },
			'execute_callback'    => static function ( $input ) { $GLOBALS['calls'][] = 'admin-only'; update_post_meta( (int) $input['id'], 'admin', 'y' ); return array( 'ok' => true ); },
		) );
		ab_ability( 'test/set-title', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['db_posts'][ (int) $input['id'] ]['post_title'] = 'Neuer Titel'; return array( 'ok' => true ); },
		) );
		ab_ability( 'test/throws', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { throw new RuntimeException( 'inside the plugin' ); },
		) );
		ab_ability( 'test/order-update', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { update_option( 'order_' . (int) $input['id'], 'paid' ); return array( 'ok' => true ); },
		) );
		ab_ability( 'test/set-thumb', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { update_post_meta( (int) $input['id'], '_thumbnail_id', '55' ); return array( 'ok' => true ); },
		) );
		ab_ability( 'other/thing', array(
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) ),
			'execute_callback' => static function ( $input ) { update_post_meta( (int) $input['post_id'], 'other', 'z' ); return array( 'ok' => true ); },
		) );
		// The MCP adapter of WordPress that Rank Math and WooCommerce bring
		// along (WP\MCP\Abilities, as measured 02.10.2026, narrower):
		// execute-ability runs any ability by name and answers success/error.
		ab_ability( 'mcp-adapter/execute-ability', array(
			'input_schema'     => array( 'type' => 'object', 'required' => array( 'ability_name', 'parameters' ), 'properties' => array( 'ability_name' => array( 'type' => 'string' ), 'parameters' => array( 'type' => 'object' ) ) ),
			'execute_callback' => static function ( $input ) {
				$GLOBALS['calls'][] = 'execute-ability';
				$r = wp_get_ability( (string) $input['ability_name'] )->execute( $input['parameters'] );
				return is_wp_error( $r ) ? array( 'success' => false, 'error' => $r->get_error_message() ) : array( 'success' => true, 'data' => $r );
			},
			'meta'             => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) ),
		) );
		ab_ability( 'mcp-adapter/discover-abilities', array(
			'execute_callback' => static function () { $GLOBALS['calls'][] = 'discover-abilities'; return array( 'abilities' => array() ); },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
		ab_ability( 'mcp-adapter/get-ability-info', array(
			'input_schema'     => array( 'type' => 'object', 'required' => array( 'ability_name' ), 'properties' => array( 'ability_name' => array( 'type' => 'string' ) ) ),
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'get-ability-info'; return array( 'name' => (string) $input['ability_name'] ); },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
		// AlphaBridge's own namespace, as its tools may be offered to the
		// MCP adapter: published, one reading and one writing.
		ab_ability( 'alphabridge/get-site-info', array(
			'execute_callback' => static function () { $GLOBALS['calls'][] = 'own-read'; return array( 'name' => 'Testsite' ); },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true, 'mcp' => array( 'public' => true ) ),
		) );
		ab_ability( 'alphabridge/update-post', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'own-write'; $GLOBALS['db_posts'][ (int) $input['id'] ]['post_content'] = 'EIGEN'; return array( 'ok' => true ); },
			'meta'             => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true, 'mcp' => array( 'public' => true ) ),
		) );
		// Read-only, kept internal by its plugin (show_in_rest false, the core's default).
		ab_ability( 'test/internal-export', array(
			'execute_callback' => static function () { $GLOBALS['calls'][] = 'internal-export'; return array( 'secret' => 'S3CR3T' ); },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ) ),
		) );
		// Read-only, published for MCP only (meta mcp.public of the MCP adapter).
		ab_ability( 'test/mcp-read', array(
			'execute_callback' => static function () { $GLOBALS['calls'][] = 'mcp-read'; return 'ok'; },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
		) );
		// "false" as text (and nothing about destructive), and read-only and
		// destructive at once: two ways a plugin can mislabel a writing ability.
		ab_ability( 'test/text-false', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'text-false'; $GLOBALS['db_posts'][ (int) $input['id'] ]['post_content'] = 'WEG'; return array( 'ok' => true ); },
			'meta'             => array( 'annotations' => array( 'readonly' => 'false' ), 'show_in_rest' => true ),
		) );
		ab_ability( 'test/contra', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'contra'; $GLOBALS['db_posts'][ (int) $input['id'] ]['post_content'] = 'WEG'; return array( 'ok' => true ); },
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => true ), 'show_in_rest' => true ),
		) );
		ab_ability( 'test/bulk', array(
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'post' => array( 'type' => 'array' ) ) ),
			'execute_callback' => static function ( $input ) {
				$GLOBALS['calls'][] = 'bulk';
				foreach ( (array) $input['post'] as $p ) { update_post_meta( (int) $p, 'bulk', 'ja' ); }
				return array( 'ok' => true );
			},
		) );
		// SiteOrigin Page Builder's layout-update (the page-builder writer
		// uses it where it may run), only for the scenario of the writers.
		if ( 'writers' === $GLOBALS['scenario'] ) {
			ab_ability( 'siteorigin-panels/layout-update', array(
				'input_schema'     => $id_schema,
				'execute_callback' => static function () { return array( 'ok' => true ); },
			) );
		}
		// Abilities that switch code on (AB_MCP_Ability_Code_Gate): as
		// WordPress 7.1.2's activate_plugin() and switch_theme() write it.
		$file_schema = array( 'type' => 'object', 'required' => array( 'file' ), 'properties' => array( 'file' => array( 'type' => 'string' ) ) );
		ab_ability( 'test/code-activate-plugin', array(
			'input_schema'     => $file_schema,
			'execute_callback' => static function ( $input ) { return ab_activate_plugin( (string) $input['file'] ); },
		) );
		ab_ability( 'test/code-deactivate-plugin', array(
			'input_schema'     => $file_schema,
			'execute_callback' => static function ( $input ) {
				update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( (string) $input['file'] ) ) ) );
				return array( 'deactivated' => true );
			},
		) );
		ab_ability( 'test/code-switch-theme', array(
			'input_schema'     => array( 'type' => 'object', 'required' => array( 'stylesheet' ), 'properties' => array( 'stylesheet' => array( 'type' => 'string' ) ) ),
			'execute_callback' => static function ( $input ) {
				// A theme setting written before the switch is the active theme's own.
				ab_set_theme_mod( 'header_textcolor', 'ff0000' );
				ab_switch_theme( (string) $input['stylesheet'] );
				// What it sets up next is meant for the theme it switched to.
				ab_set_theme_mod( 'nav_menu_locations', array( 'primary' => 99 ) );
				update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-9' ) ) );
				return array( 'switched' => get_option( 'stylesheet' ) === (string) $input['stylesheet'] );
			},
		) );
		// Its id is a term's: post #30 has the same number by chance.
		ab_ability( 'test/delete-term', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['calls'][] = 'delete-term'; update_option( 'term_deleted_' . (int) $input['id'], 1 ); return array( 'deleted' => true ); },
		) );
		// Deletes the post outright, not into the trash.
		ab_ability( 'test/delete-post', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				$id = (int) $input['id'];
				unset( $GLOBALS['db_posts'][ $id ] );
				foreach ( $GLOBALS['db_meta'] as $mid => $r ) { if ( $r[0] === $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); } }
				return array( 'deleted' => true );
			},
		) );
		// Scenario extra only (the other scenarios count the abilities):
		// test/set-meta with a default input that names post 30, a call
		// without input changes post 30 (Codex on #105).
		if ( 'extra' === $GLOBALS['scenario'] ) {
		ab_ability( 'test/set-meta-default', array(
			'input_schema'     => $meta_schema + array( 'default' => array( 'post_id' => 30, 'key' => 'standard', 'value' => 'ja' ) ),
			'execute_callback' => static function ( $input ) {
				update_post_meta( (int) $input['post_id'], (string) $input['key'], (string) $input['value'] );
				return array( 'updated' => true );
			},
		) );
		// test/stage-draft, annotated read-only by its plugin (Codex on #105).
		ab_ability( 'test/stage-draft-quiet', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				$id = (int) $input['id'];
				ab_post( 90, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1', 'post_content' => $GLOBALS['db_posts'][ $id ]['post_content'] ) );
				$GLOBALS['ab_autosaves'][ $id ][ get_current_user_id() ] = 90;
				ab_meta_add( 90, '_fake_layout', '[1]' );
				return array( 'read' => true );
			},
			'meta'             => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
		}
		// Stages the change in the account's autosave revision (#90), as
		// Elementor's manage-elements does on a published page.
		ab_ability( 'test/stage-draft', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				$id = (int) $input['id'];
				if ( ! isset( $GLOBALS['db_posts'][90] ) ) {
					ab_post( 90, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1', 'post_content' => $GLOBALS['db_posts'][ $id ]['post_content'] ) );
					$GLOBALS['ab_autosaves'][ $id ][ get_current_user_id() ] = 90;
					ab_meta_add( 90, '_fake_layout', '[1]' );
				} else {
					foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = '[2]'; }
				}
				return array( 'staged' => true );
			},
		) );
		// Elementor 4.3.3's manage-elements on a published page, narrower
		// (modules/mcp/abilities/manage-elements-ability.php,
		// core/utils/document/document-mutator.php:188-279): the change goes
		// into the account's autosave revision; the save of that draft
		// empties the live page's _elementor_page_assets and writes the global
		// class index for the preview on the live page (measured 02.10.2026,
		// belege 03-messung.json C3, 09-build-composition.json). An unknown
		// setting is skipped with a warning, an unknown element fails alone
		// (status partial_error, utils/bulk-operations-result.php).
		ab_ability( 'elementor/manage-elements', array(
			'input_schema'     => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'operations' => array( 'type' => 'array' ) ) ),
			'execute_callback' => static function ( $input ) {
				$id   = (int) $input['post_id'];
				$tree = json_decode( (string) ab_first_meta( $id, '_elementor_data' ), true );
				$rows = array();
				$ok   = 0;
				foreach ( $input['operations'] as $i => $op ) {
					if ( 'f00d002' !== $op['element_id'] ) {
						$rows[] = array( 'index' => $i, 'action' => 'update', 'status' => 'error', 'code' => 'elementor_not_found', 'message' => 'Element not found.' );
						continue;
					}
					$row = array( 'index' => $i, 'action' => 'update', 'status' => 'ok', 'element_id' => 'f00d002' );
					foreach ( $op['settings'] as $k => $v ) {
						if ( 'title' === $k ) {
							$tree[0]['settings']['title'] = $v;
						} else {
							$row['warnings'][] = '[f00d002] Property "' . $k . '" is not supported on element type "e-heading" and was skipped.';
						}
					}
					$rows[] = $row;
					++$ok;
				}
				$status = 0 === $ok ? 'error' : ( $ok < count( $rows ) ? 'partial_error' : 'ok' );
				// The save failed (manage-elements-ability.php:264-266): status
				// error and save_error, the operations listed as they went.
				if ( $ok > 0 && isset( $GLOBALS['ab_el_save_error'] ) ) {
					return array( 'status' => 'error', 'results' => $rows, 'post_id' => $id, 'save_error' => $GLOBALS['ab_el_save_error'] );
				}
				if ( $ok > 0 ) {
					$auto = wp_get_post_autosave( $id, get_current_user_id() );
					if ( ! $auto ) {
						$rid = $GLOBALS['ab_el_rev'];
						ab_post( $rid, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1', 'post_content' => $GLOBALS['db_posts'][ $id ]['post_content'] ) );
						$GLOBALS['ab_autosaves'][ $id ][ get_current_user_id() ] = $rid;
						ab_meta_add( $rid, '_elementor_edit_mode', 'builder' );
						ab_meta_add( $rid, '_elementor_data', '' );
					} else {
						$rid = (int) $auto->ID;
					}
					foreach ( array_keys( ab_meta_rows( $rid, '_elementor_data' ) ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = json_encode( $tree, JSON_UNESCAPED_UNICODE ); }
					delete_post_meta( $id, '_elementor_page_assets' );
					update_post_meta( $id, '_elementor_used_global_class_preview', 'e-466a7731' );
					update_post_meta( $id, '_elementor_global_class_usage_indexed_preview', '1' );
				}
				return array( 'status' => $status, 'results' => $rows, 'post_id' => $id );
			},
		) );
		// Elementor 4.3.3's publish-document, narrower (publish-document-
		// ability.php:61-141): the account's autosave is copied onto the live
		// page (Revisions_Manager::restore_revision(), the _elementor meta
		// only, not post_content) and deleted; without one, nothing happens.
		ab_ability( 'elementor/publish-document', array(
			'input_schema'     => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) ),
			'execute_callback' => static function ( $input ) {
				$id   = (int) $input['post_id'];
				$auto = wp_get_post_autosave( $id, get_current_user_id() );
				if ( $auto ) {
					update_post_meta( $id, '_elementor_data', ab_first_meta( (int) $auto->ID, '_elementor_data' ) );
					wp_delete_post_revision( (int) $auto->ID );
				}
				return array( 'post_id' => $id, 'status' => 'publish', 'previous_status' => 'publish' );
			},
		) );
		// Stages a draft and fails, or stages a draft and changes the live page too.
		ab_ability( 'test/stage-fail', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				wp_get_ability( 'test/stage-draft' )->execute( $input );
				return new WP_Error( 'half', 'Saved the draft, then gave up' );
			},
		) );
		ab_ability( 'test/stage-and-change', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				wp_get_ability( 'test/stage-draft' )->execute( $input );
				update_post_meta( (int) $input['id'], 'farbe', 'lila' );
				return array( 'ok' => true );
			},
		) );
		// Writes the copy of a page visitors do not see (as SeedProd's
		// save-page writes only the JSON its editor loads).
		ab_ability( 'test/hidden-copy', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['db_posts'][ (int) $input['id'] ]['post_content'] = 'nur die Kopie'; return array( 'ok' => true ); },
		) );
		// Fills post_content_filtered (as SeedProd's save-page fills its JSON).
		ab_ability( 'test/set-filtered', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) { $GLOBALS['db_posts'][ (int) $input['id'] ]['post_content_filtered'] = '{"document":[]}'; return array( 'ok' => true ); },
		) );
		// Filters a stored widget the way kses does, for any account (as
		// SiteOrigin's layout-update does): the script tags go, their code stays.
		ab_ability( 'test/kses-meta', array(
			'input_schema'     => $id_schema,
			'execute_callback' => static function ( $input ) {
				foreach ( array_keys( ab_meta_rows( (int) $input['id'], 'html' ) ) as $mid ) {
					$GLOBALS['db_meta'][ $mid ][2] = preg_replace( '~</?script[^>]*>~i', '', $GLOBALS['db_meta'][ $mid ][2] ) . ' ';
				}
				return array( 'ok' => true );
			},
		) );
	}
);

/* ---- the site ---- */

$GLOBALS['ab_caps'] = array( 'read' => true, 'edit_posts' => true, 'edit_post:30' => true, 'edit_post:31' => true, 'edit_post:33' => true, 'edit_post:32' => false, 'manage_options' => false );
ab_post( 30, array( 'post_content' => 'Inhalt «dreissig»', 'post_title' => 'Seite 30' ) );
$m_farbe = ab_meta_add( 30, 'farbe', 'blau' );
ab_meta_add( 30, 'multi', 'eins' );
ab_meta_add( 30, 'multi', 'zwei' );
ab_meta_add( 30, '_elementor_css', 'a:1:{s:3:"css";s:3:"x{}";}' );   // a cache: left out
ab_meta_add( 30, '_edit_last', '1' );                                // WordPress' own: left out
ab_meta_add( 30, '_pingme', '1' );                                   // WordPress' ping marker: left out
ab_fake_page( 31, ab_fake_elements() );
ab_post( 32, array( 'post_title' => 'Fremd' ) );
ab_post( 33, array( 'post_title' => 'Gesperrt' ) );

/**
 * Everything the tables hold for one post, meta ids included — but the caches
 * made from it: a change empties them on purpose (rebuilt on the next view),
 * and so does a restore.
 */
function ab_dump( $id ) {
	$meta = array();
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id && ! in_array( $r[1], AB_MCP_Ability_Broker::skipped_meta(), true ) ) { $meta[ $mid ] = array( $r[1], $r[2] ); }
	}
	ksort( $meta );
	return array( 'post' => $GLOBALS['db_posts'][ $id ], 'meta' => $meta );
}
function ab_run( array $a ) { return AB_MCP_Tools_Abilities::run_ability( $a ); }
function ab_msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function ab_has( array $list, $needle ) {
	foreach ( $list as $s ) { if ( false !== strpos( (string) $s, $needle ) ) { return true; } }
	return false;
}
/**
 * Every registered ability approved but these (names or namespace patterns):
 * the counterpart of Pro's list of switched-off abilities, so the blocks
 * taken over from Pro run with the abilities approved.
 */
function ab_off( array $names ) {
	$on = array();
	foreach ( array_keys( AB_MCP_Ability_Broker::all() ) as $name ) {
		if ( ! AB_MCP_Ability_Broker::listed( $name, $names ) ) {
			$on[] = $name;
		}
	}
	AB_MCP_Ability_Broker::set_on( $on );
}
/** Whether a refusal carries its way: the reason, the link and the request to pass it on. */
function ab_way( $text, $anchor ) {
	return false !== strpos( (string) $text, 'Direct link: http://x/wp-admin/' . $anchor ) && false !== strpos( (string) $text, 'Pass this on to the person you are working for' );
}
/*
 * Autosave revisions: post id => user id => revision id. Narrower than core
 * (wp-includes/revision.php wp_get_post_autosave(): the revision named
 * "<id>-autosave-v1" by that author).
 */
$GLOBALS['ab_autosaves'] = array();
function wp_get_post_autosave( $post_id, $user_id = 0 ) {
	$list = isset( $GLOBALS['ab_autosaves'][ (int) $post_id ] ) ? $GLOBALS['ab_autosaves'][ (int) $post_id ] : array();
	if ( 0 === (int) $user_id ) {
		$first = reset( $list );
		return false === $first ? false : get_post( $first );
	}
	return isset( $list[ (int) $user_id ] ) ? get_post( $list[ (int) $user_id ] ) : false;
}
// wp-includes/revision.php wp_is_post_autosave(): a revision named "<parent>-autosave…".
function wp_is_post_autosave( $post ) {
	$p = get_post( $post );
	if ( ! $p || 'revision' !== $p->post_type ) {
		return false;
	}
	return false !== strpos( (string) $p->post_name, $p->post_parent . '-autosave' ) ? (int) $p->post_parent : false;
}
// wp_delete_post_revision(): a revision only, with its meta; it leaves the autosave list.
$GLOBALS['ab_deleted_revisions'] = array();
function wp_delete_post_revision( $id ) {
	$p = get_post( $id );
	if ( ! $p || 'revision' !== $p->post_type ) {
		return false;
	}
	$GLOBALS['ab_deleted_revisions'][] = (int) $id;
	unset( $GLOBALS['db_posts'][ (int) $id ] );
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $id ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
	}
	foreach ( $GLOBALS['ab_autosaves'] as $pid => $list ) {
		foreach ( $list as $uid => $rid ) {
			if ( (int) $rid === (int) $id ) { unset( $GLOBALS['ab_autosaves'][ $pid ][ $uid ] ); }
		}
	}
	return $p;
}
// Elementor's plugin instance with its db: save_plain_text() writes the text
// copy in post_content from _elementor_data (includes/db.php:225-242; here the
// title of the first element).
eval( 'namespace Elementor; class Plugin { public static $instance; }' );
class AB_Test_El_Db {
	public $fail  = false;
	public $calls = array();
	public function save_plain_text( $post_id ) {
		$this->calls[] = (int) $post_id;
		if ( $this->fail ) {
			throw new RuntimeException( 'kaputt' );
		}
		$tree = json_decode( (string) ab_first_meta( (int) $post_id, '_elementor_data' ), true );
		$GLOBALS['db_posts'][ (int) $post_id ]['post_content'] = '<h1>' . ( isset( $tree[0]['settings']['title'] ) ? $tree[0]['settings']['title'] : '' ) . '</h1>';
	}
}
\Elementor\Plugin::$instance = (object) array( 'db' => new AB_Test_El_Db() );
$GLOBALS['ab_el_rev'] = 91;

/* =================================== Z) found by Codex on #105 (30.10.2026) */

if ( 'extra' === $scenario ) {
echo "Z  defaults of the input schema; drafts of a read-only ability\n";
$prev_on = AB_MCP_Ability_Broker::on_list();
AB_MCP_Ability_Broker::set_on( array_merge( $prev_on, array( 'test/set-meta-default', 'test/stage-draft-quiet' ) ) );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/set-meta-default', 'apply' => true ) );
ok( is_array( $r ) && 'ja' === get_post_meta( 30, 'standard', true ), 'Z1 a call without input runs the ability with its default: ' . ( is_array( $r ) ? '' : ab_msg( $r ) ) );
ok( is_array( $r ) && AB_MCP_Undo::valid_token( $r['undo_token'] ) && $n + 1 === ab_points() && isset( $r['readback'][0] ) && 30 === $r['readback'][0]['post'], 'Z1 the post the default names is snapshotted and read back, with an undo point' );
$p = ab_run( array( 'name' => 'test/set-meta-default', 'apply' => false ) );
ok( is_array( $p ) && true === $p['dry_run'] && in_array( 30, array_column( $p['targets'], 'id' ), true ), 'Z2 the preview names that post as its target' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );

foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
unset( $GLOBALS['db_posts'][90], $GLOBALS['ab_autosaves'][30] );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/stage-draft-quiet', 'input' => array( 'id' => 30 ) ) );
ok( is_array( $r ) && true === $r['readback'][0]['unchanged'] && array( 'revision' => 90, 'change' => 'added' ) === $r['readback'][0]['draft'], 'Z3 the live post is untouched, the draft staged: ' . json_encode( is_array( $r ) ? $r['readback'] : ab_msg( $r ) ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'annotated read-only by its plugin, but it staged a change in a draft of post #30, the autosave revision #90' ) && ab_has( $r['warnings'], 'deletes that draft again' ), 'Z3 the answer says so and names the way back' );
ok( is_array( $r ) && AB_MCP_Undo::valid_token( $r['undo_token'] ) && $n + 1 === ab_points(), 'Z3 an undo point keeps it' );
$GLOBALS['ab_caps']['edit_post:90'] = true; // WordPress maps edit_post on a revision to its parent (map_meta_cap()).
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && ! isset( $GLOBALS['db_posts'][90] ), 'Z4 wp_undo deletes the draft again: ' . json_encode( $u ) );

// Undo switched off: the staged draft is still named, with no way back promised.
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
unset( $GLOBALS['db_posts'][90], $GLOBALS['ab_autosaves'][30] );
$GLOBALS['ab_toolstate']['wp_undo'] = false;
$n = ab_points();
$r = ab_run( array( 'name' => 'test/stage-draft-quiet', 'input' => array( 'id' => 30 ) ) );
unset( $GLOBALS['ab_toolstate']['wp_undo'] );
ok( is_array( $r ) && null === $r['undo_token'] && $n === ab_points() && ab_has( $r['warnings'], 'it staged a change in a draft of post #30' ) && ab_has( $r['warnings'], 'This call has no undo point for the draft' ), 'Z5 no undo point possible: the draft is still named, and no way back promised (Codex, 30.10.2026): ' . json_encode( is_array( $r ) ? $r['warnings'] : ab_msg( $r ) ) );

// A filter on the input runs once: the snapshot aims at the post execute() changes.
add_filter( 'wp_ability_normalize_input', static function ( $input ) { if ( is_array( $input ) && isset( $input['post_id'] ) ) { $input['post_id'] = (int) $input['post_id'] + 1; } return $input; } );
ab_post( 31, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Einunddreissig' ) );
$GLOBALS['ab_caps']['edit_post:31'] = true;
$r = ab_run( array( 'name' => 'test/set-meta-default', 'apply' => true ) );
$changed = 'ja' === get_post_meta( 31, 'standard', true ) ? 31 : ( 'ja' === get_post_meta( 32, 'standard', true ) ? 32 : ( 'ja' === get_post_meta( 30, 'standard', true ) ? 30 : 0 ) );
ok( is_array( $r ) && isset( $r['readback'][0] ) && $changed === $r['readback'][0]['post'], 'Z6 the post read back is the one the ability changed (' . $changed . '), whatever a filter on the input does: ' . json_encode( is_array( $r ) ? array_column( $r['readback'], 'post' ) : ab_msg( $r ) ) );
remove_all_filters( 'wp_ability_normalize_input' );
// A filter that throws (WordPress 7.1 runs it): a failed ability, no uncaught error.
if ( '7.1.2' === $wp_api ) {
	add_filter( 'wp_ability_normalize_input', static function () { throw new RuntimeException( 'boom' ); } );
	$r = ab_run( array( 'name' => 'test/set-meta-default', 'apply' => true ) );
	remove_all_filters( 'wp_ability_normalize_input' );
	ok( is_wp_error( $r ) && 'ab_mcp_ability_threw' === $r->get_error_code(), 'Z6b a filter on the input that throws ends as a failed ability (Codex, 30.10.2026): ' . ( is_wp_error( $r ) ? $r->get_error_code() : json_encode( $r ) ) );
}

// A point that would hold nothing to put back is dropped: the live copy is
// unchanged and the draft cannot be taken (here a revision that is gone).
$kp = new ReflectionMethod( 'AB_MCP_Ability_Broker', 'keep_point' );
if ( PHP_VERSION_ID < 80100 ) {
	$kp->setAccessible( true );
}
$bs = new ReflectionMethod( 'AB_MCP_Ability_Broker', 'before_state' );
if ( PHP_VERSION_ID < 80100 ) {
	$bs->setAccessible( true );
}
$state = $bs->invoke( null, array( 'id' => 30, 'covered' => true, 'builder' => '', 'locs' => array( 'post_content' ) ) );
$n     = ab_points();
$warn  = array();
$cov   = array();
$rbm   = new ReflectionMethod( 'AB_MCP_Ability_Broker', 'read_back' );
if ( PHP_VERSION_ID < 80100 ) {
	$rbm->setAccessible( true );
}
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
unset( $GLOBALS['db_posts'][90], $GLOBALS['ab_autosaves'][30] );
ab_post( 90, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => 30, 'post_name' => '30-autosave-v1' ) );
ab_meta_add( 90, '_fake_layout', str_repeat( 'x', 200000 ) ); // larger than the point may hold
add_filter( 'ab_mcp_undo_snapshot_max', static function () { return 50000; } );
add_filter( 'ab_mcp_undo_raw_max', static function () { return 50000; } );
$rb          = $rbm->invoke( null, $state );
$rb['draft'] = array( 'revision' => 90, 'change' => 'added' );
$tok   = $kp->invokeArgs( null, array( wp_get_ability( 'test/stage-draft-quiet' ), array( array( $state, $rb ) ), &$warn, &$cov ) );
remove_all_filters( 'ab_mcp_undo_snapshot_max' );
remove_all_filters( 'ab_mcp_undo_raw_max' );
ok( null === $tok && $n === ab_points() && array() === $cov, 'Z7 nothing it could put back: no undo point is left (Codex, 30.10.2026): ' . json_encode( array( $tok, $warn ) ) );
AB_MCP_Ability_Broker::set_on( $prev_on );
	ab_result( 'ABILITY-BROKER (extra)' );
}

/* ================= writers: which broker the page-builder writers ask */

// The page-builder writers run Elementor's and SiteOrigin's abilities
// themselves (wp_update_builder_element), inside their own checked change
// with an undo point. Only Pro's switches hold them off, as before 4.12.0:
// this plugin's approvals are for wp_run_ability, the general runner, and a
// free site without approvals keeps writing atomic Elementor elements.
// The undo of a draft a call staged asks wp_run_ability's list of copies
// (locs_of()): this plugin's broker, or Pro's where Pro is loaded.
if ( 'writers' === $scenario ) {
	$reflect = static function ( $class, $method ) {
		$m = new ReflectionMethod( $class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return $m;
	};
	$el_off = $reflect( 'AB_MCP_Builder_Writer_Elementor', 'switched_off' );
	$so     = $reflect( 'AB_MCP_Builder_Writer_SiteOrigin', 'ability' );
	$cc     = $reflect( 'AB_MCP_Undo_Builder', 'created_changes' );
	ok( false === class_exists( 'AB_MCP_Pro_Ability_Broker', false ) && null !== AB_MCP_Ability_Broker::get( 'siteorigin-panels/layout-update' ), 'WR0 no Pro here; SiteOrigin\'s ability is registered' );

	// Nothing approved: the writers use the abilities all the same, as in
	// 4.10.0 and 4.11.0 (an approval is for wp_run_ability only).
	ok( '' === $el_off->invoke( null, 'elementor/manage-elements' ) && '' === $el_off->invoke( null, 'elementor/publish-document' ), 'WR1 without Pro and without approval the Elementor writer still changes atomic elements and publishes' );
	ok( $so->invoke( null ) instanceof WP_Ability, 'WR1 the SiteOrigin writer still uses layout-update' );
	ok( false === AB_MCP_Ability_Broker::decision( AB_MCP_Ability_Broker::get( 'siteorigin-panels/layout-update' ) )['allowed'], 'WR1 while wp_run_ability may not run it: not approved' );

	// Neither an approval nor this plugin's filter changes the writers.
	AB_MCP_Ability_Broker::set_on( array( 'elementor/*', 'siteorigin-panels/layout-update' ) );
	ok( '' === $el_off->invoke( null, 'elementor/manage-elements' ) && $so->invoke( null ) instanceof WP_Ability, 'WR2 approved: the same' );
	add_filter( 'ab_mcp_ability_allowed', static function ( $allowed, $name ) { return 'siteorigin-panels/layout-update' === $name ? false : $allowed; }, 10, 2 );
	ok( $so->invoke( null ) instanceof WP_Ability, 'WR3 the filter of this plugin is about wp_run_ability; the writer keeps its way' );
	remove_all_filters( 'ab_mcp_ability_allowed' );

	// The undo of a staged draft: a meta key the draft got since is found
	// through this plugin's locs_of().
	$GLOBALS['ab_caps']['edit_post:95'] = true;
	ab_post( 95, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => 30, 'post_name' => '30-autosave-v1' ) );
	$item            = AB_MCP_Undo_Builder::snapshot_of( 95, array( 'post_content', 'post_content_filtered' ), '' );
	$item['created'] = true;
	ab_meta_add( 95, 'zusatz', 'im Editor' );
	ok( in_array( 'meta:zusatz', $cc->invoke( null, $item ), true ), 'WR4 the undo of a draft a call created sees a meta key the draft got since, through this plugin\'s broker' );

	// The settings: this plugin's switches only where its wp_run_ability runs.
	$GLOBALS['ab_caps']['manage_options'] = true;
	ok( 0 === strpos( AB_MCP_Ability_Switches::fine_section( '', 'abilities', array( 'abilities' ) ), '<div class="ab-abilities ab-abilities--fine" id="ab-abilities">' ), 'WR5 this plugin\'s wp_run_ability is registered: its switches show in the fine-tuning' );
	$GLOBALS['ab_run_callback'] = array( 'AB_MCP_Pro_Tools_Abilities', 'run_ability' );
	ok( 'x' === AB_MCP_Ability_Switches::fine_section( 'x', 'abilities', array( 'abilities' ) ), 'WR5 Pro\'s registered over it: nothing of this plugin\'s, Pro shows its own' );

	// AlphaBridge MCP Pro loaded: its broker decides (as Pro 4.11.0 offers it:
	// decision() and locs_of()), whatever this plugin approved.
	class AB_MCP_Pro_Ability_Broker {
		public static $off = array();
		public static function decision( $ability ) {
			$allowed = ! in_array( (string) $ability->get_name(), self::$off, true );
			return array( 'allowed' => $allowed, 'reason' => $allowed ? 'on' : 'off', 'how' => $allowed ? '' : 'PRO-WAY' );
		}
		public static function locs_of( $post_id ) { return array( 'meta:pro-only' ); }
	}
	AB_MCP_Ability_Broker::set_on( array() );
	ok( '' === $el_off->invoke( null, 'elementor/manage-elements' ) && $so->invoke( null ) instanceof WP_Ability, 'WR6 with Pro loaded, Pro\'s broker decides: nothing approved here, and the writers use the abilities Pro lets run' );
	AB_MCP_Pro_Ability_Broker::$off = array( 'elementor/manage-elements', 'siteorigin-panels/layout-update' );
	ok( 'PRO-WAY' === $el_off->invoke( null, 'elementor/manage-elements' ) && null === $so->invoke( null ), 'WR6 and holds off what Pro switched off, with Pro\'s way' );
	AB_MCP_Ability_Broker::set_on( array( 'elementor/*', 'siteorigin-panels/layout-update' ) );
	ok( 'PRO-WAY' === $el_off->invoke( null, 'elementor/manage-elements' ) && null === $so->invoke( null ), 'WR6 an approval of this plugin does not overrule it' );
	$changed = $cc->invoke( null, $item );
	ok( in_array( 'meta:pro-only', $changed, true ) && ! in_array( 'meta:zusatz', $changed, true ), 'WR7 the undo of a staged draft asks Pro\'s locs_of() then' );
	ab_result( 'ABILITY-BROKER (writers)' );
}

ok( true === AB_MCP_Ability_Broker::available(), 'M0 the Abilities API of core ' . $wp_api . ' is loaded' );

/* ============================ OI) nothing runs without an administrator's approval */

$before = ab_dump( 30 );
ok( null === get_option( AB_MCP_Ability_Broker::ON_OPTION, null ) && array() === AB_MCP_Ability_Broker::on_list(), 'OI0 a new site: no approval list, nothing approved' );
$l  = AB_MCP_Tools_Abilities::list_abilities( array() );
$by = array();
foreach ( $l['abilities'] as $row ) { $by[ $row['name'] ] = $row; }
$runs = array();
foreach ( $by as $name => $row ) {
	if ( false !== $row['allowed'] || false !== $row['can_run'] || 0 !== strpos( $row['allowed_why'], 'not approved' ) || ! ab_way( $row['way'], 'options-general.php?page=alphabridge-mcp#' . AB_MCP_Ability_Broker::anchor( $name ) ) ) {
		$runs[] = $name;
	}
}
ok( 31 === $l['total'] && 31 === count( $by ) && array() === $runs, 'OI1 the listing marks every ability as not approved and unable to run, each with the way to its switch' . ( $runs ? ': ' . implode( ', ', $runs ) : '' ) );
ok( true === $l['run_enabled'] && ! isset( $l['how_to_run'] ), 'OI1 wp_run_ability itself is on: what is missing is the approval of each ability' );
ok( 1 === $l['hidden'] && ! isset( $by['test/internal-export'] ) && ab_has( $l['notes'], 'not approved and listed to administrators only' ), 'OI1 an internal ability that is not approved is listed to administrators only, and the listing says so' );
$GLOBALS['calls'] = array();
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && array() === $GLOBALS['calls'], 'OI2 a read-only ability that runs at once once approved does not run' );
ok( false !== strpos( ab_msg( $r ), 'The ability "test/read-info" is not approved for AlphaBridge on this site' ) && false !== strpos( ab_msg( $r ), 'Nothing ran.' ) && false !== strpos( ab_msg( $r ), 'Fine-tuning, group «Abilities of other plugins»' ) && false !== strpos( ab_msg( $r ), 'switching on write access approves no ability' ) && ab_way( ab_msg( $r ), 'options-general.php?page=alphabridge-mcp#ab-ability-test__read-info' ), 'OI2 the refusal names the reason, the switch, that write access approves nothing, the link to the switch and the request to pass it on: ' . ab_msg( $r ) );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && $before === ab_dump( 30 ) && 0 === ab_points() && array() === $GLOBALS['calls'], 'OI3 a writing one does not run either: nothing changed, no undo point' );
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ) );
ok( is_array( $p ) && true === $p['dry_run'] && false === $p['allowed'] && 'not_checked' === $p['permission'] && ab_way( $p['how_to_allow'], 'options-general.php?page=alphabridge-mcp#ab-ability-test__set-meta' ) && ab_has( $p['notes'], 'not approved for AlphaBridge' ), 'OI4 the preview says it is not approved, with the way; its permission check is not run' );
$r = ab_run( array( 'name' => 'test/internal-export' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_unknown' === $r->get_error_code() && array() === $GLOBALS['calls'], 'OI5 an internal one answers "not available" to an account that may not see it' );

// Switching on write access (the action the switch fires after it switched
// every tool on) approves no ability: no handler, no list, nothing runs.
do_action( 'ab_mcp_reset_switches', 1, 'settings_form' );
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( null === get_option( AB_MCP_Ability_Broker::ON_OPTION, null ) && is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && array() === $GLOBALS['calls'], 'OI6 switching on write access approves nothing: the list stays unwritten, and the ability does not run' );
ok( null === get_option( 'ab_mcp_pro_abilities_off', null ), 'OI6 Pro\'s list of switched-off abilities is never written here' );

// Approved one by one.
AB_MCP_Ability_Broker::set_on( array( 'test/read-info' ) );
$r = ab_run( array( 'name' => 'test/read-info' ) );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/set-meta' ) );
ok( is_array( $r ) && array( 'read-info' ) === $GLOBALS['calls'] && false === $d['allowed'] && 'off' === $d['reason'], 'OI7 an approved ability runs; the others still do not' );
ok( array( 'test/read-info' ) === get_option( AB_MCP_Ability_Broker::ON_OPTION ), 'OI7 the approvals are stored as names' );
AB_MCP_Ability_Broker::set_on( array( 'other/*' ) );
ok( true === AB_MCP_Ability_Broker::decision( wp_get_ability( 'other/thing' ) )['allowed'] && false === AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/read-info' ) )['allowed'], 'OI8 a namespace pattern (written by code) approves a whole plugin, and only it' );

// AlphaBridge's own abilities: never, approved by name, by pattern or by the filter.
AB_MCP_Ability_Broker::set_on( array( 'alphabridge/*', 'alphabridge/update-post', 'alphabridge/get-site-info', 'test/read-info' ) );
add_filter( 'ab_mcp_ability_allowed', static function () { return true; } );
$GLOBALS['calls'] = array();
$all = AB_MCP_Tools_Abilities::list_abilities( array( 'limit' => 500 ) );
$own = array();
foreach ( $all['abilities'] as $row ) { if ( 0 === strpos( $row['name'], 'alphabridge/' ) ) { $own[] = $row['name']; } }
ok( array() === $own && ! in_array( 'alphabridge', $all['providers'], true ) && null !== wp_get_ability( 'alphabridge/update-post' ), 'OI9 AlphaBridge\'s own abilities are registered, but not listed, approved or not: ' . implode( ', ', $own ) );
$r1 = ab_run( array( 'name' => 'alphabridge/update-post', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$r2 = ab_run( array( 'name' => 'Alphabridge/Get-Site-Info' ) );
$r3 = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'alphabridge/get-site-info' ) );
ok( is_wp_error( $r1 ) && is_wp_error( $r2 ) && is_wp_error( $r3 ) && 'ab_mcp_ability_own' === $r1->get_error_code() && 'ab_mcp_ability_own' === $r2->get_error_code() && 'ab_mcp_ability_own' === $r3->get_error_code() && array() === $GLOBALS['calls'] && $before === ab_dump( 30 ), 'OI10 neither run nor described, the name in capitals too: nothing ran, the post as before' );
ok( false !== strpos( ab_msg( $r1 ), 'one of AlphaBridge\'s own abilities' ) && false !== strpos( ab_msg( $r1 ), 'Nothing ran.' ) && false !== strpos( ab_msg( $r1 ), 'Call the AlphaBridge tool it stands for directly' ), 'OI10 the answer says why, and the way: the tool itself: ' . ab_msg( $r1 ) );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'alphabridge/update-post' ) );
ok( false === $d['allowed'] && 'own' === $d['reason'] && false === AB_MCP_Ability_Broker::is_on( 'alphabridge/update-post' ) && null === AB_MCP_Ability_Broker::get( 'alphabridge/update-post' ), 'OI11 decision() says no for them, with the reason own, whatever the list and the filter say; get() knows none' );
$r = ab_run( array( 'name' => 'mcp-adapter/execute-ability', 'input' => array( 'ability_name' => 'alphabridge/update-post', 'parameters' => array( 'id' => 30 ) ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_runner' === $r->get_error_code() && false === strpos( ab_msg( $r ), 'with name "alphabridge' ) && array() === $GLOBALS['calls'], 'OI12 nor through the MCP adapter\'s execute-ability, and the answer does not offer that name' );
$st = AB_MCP_Ability_Switches::state();
ok( ! isset( $st['groups']['alphabridge'] ) && ! isset( $st['groups']['mcp-adapter'] ), 'OI13 the settings offer no switch for them' );
remove_all_filters( 'ab_mcp_ability_allowed' );
$GLOBALS['calls'] = array();

/* ======================================================== A) listing */

// From here on, as in Pro's test, with the abilities approved (ab_off()
// names the ones that are not).
ab_off( array() );
$l = AB_MCP_Tools_Abilities::list_abilities( array() );
ok( is_array( $l ) && 32 === $l['total'] && 32 === $l['count'] && false === $l['truncated'] && ! isset( $l['hidden'] ), 'A1 all thirty-two abilities are listed, the internal one too: it is approved like every other' );
ok( is_array( $l ) && array( 'elementor', 'other', 'test' ) === $l['providers'], 'A1 providers from the namespaces' );
$by = array();
foreach ( $l['abilities'] as $row ) { $by[ $row['name'] ] = $row; }
ok( true === $by['test/read-info']['allowed'] && true === $by['test/read-info']['can_run'] && 0 === strpos( $by['test/read-info']['allowed_why'], 'approved by an administrator' ) && true === $by['test/read-info']['annotations']['readonly'], 'A2 a read-only ability is approved and can run, and why' );
ok( true === $by['test/set-meta']['allowed'] && true === $by['test/set-meta']['can_run'] && ! isset( $by['test/set-meta']['way'] ) && ! isset( $by['test/set-meta']['how_to_allow'] ), 'A2 so is an approved writing one' );
$no = array();
foreach ( $by as $name => $row ) { if ( true !== $row['can_run'] ) { $no[] = $name; } }
ok( array() === $no, 'A2 every approved ability can run, internal, contradictory and destructive ones included: ' . implode( ', ', $no ) );
ok( 'object: post_id* (integer), key* (string), value* (string)' === $by['test/set-meta']['input'], 'A3 the short form of the input schema: ' . $by['test/set-meta']['input'] );
ok( 'none' === $by['test/no-input']['input'], 'A3 no schema reads as none' );
ok( false !== strpos( $by['test/set-meta']['way_back'], 'post_id' ) && 0 === strpos( $by['test/global-flush']['way_back'], 'none' ), 'A4 the way back: an undo point where the input names a post, none where not' );
ok( 'test' === $by['test/set-meta']['provider'] && 'test' === $by['test/set-meta']['category'] && true === $by['test/read-info']['show_in_rest'] && true === $by['test/read-info']['public'] && false === $by['test/internal-export']['public'], 'A5 provider, category, show_in_rest, published or internal' );
$l2 = AB_MCP_Tools_Abilities::list_abilities( array( 'namespace' => 'other/*' ) );
ok( 1 === $l2['count'] && 'other/thing' === $l2['abilities'][0]['name'] && 32 === $l2['total'], 'A6 the namespace filter' );
$l3 = AB_MCP_Tools_Abilities::list_abilities( array( 'search' => 'SILENT' ) );
ok( 1 === $l3['count'] && 'test/silent' === $l3['abilities'][0]['name'], 'A7 the search filter, case-insensitive' );
$l4 = AB_MCP_Tools_Abilities::list_abilities( array( 'limit' => 3 ) );
ok( 3 === $l4['count'] && 32 === $l4['matched'] && true === $l4['truncated'], 'A8 limit, and the answer says it cut' );
$one = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/set-meta' ) );
ok( is_array( $one ) && $meta_schema === $one['ability']['input_schema'], 'A9 name gives the whole input schema' );
$unk = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/set-metas' ) );
ok( is_wp_error( $unk ) && 'ab_mcp_ability_unknown' === $unk->get_error_code() && false !== strpos( ab_msg( $unk ), 'Similar: ' ) && false !== strpos( ab_msg( $unk ), 'test/set-meta' ), 'A10 an unknown name: the way (wp_list_abilities) and similar names' );
ok( array() === $GLOBALS['ab_wrong'], 'A11 asking for an unknown name raises no notice in WordPress' . ( $GLOBALS['ab_wrong'] ? ': ' . implode( ' | ', $GLOBALS['ab_wrong'] ) : '' ) );
ok( true === $l['run_enabled'] && ! isset( $l['how_to_run'] ), 'A12 wp_run_ability is on, like every tool until an administrator switches it off; the listing has no how_to_run' );
$GLOBALS['ab_toolstate']['wp_run_ability'] = false;
$off = AB_MCP_Tools_Abilities::list_abilities( array() );
$one = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/set-meta' ) );
ok( false === $off['run_enabled'] && false !== strpos( $off['how_to_run'], '"wp_run_ability"' ) && false !== strpos( $off['how_to_run'], 'Fine-tuning, group «Abilities of other plugins»' ) && ab_way( $off['how_to_run'], 'options-general.php?page=alphabridge-mcp#ab-tool-wp_run_ability' ), 'A12 switched off: the listing says so, where to switch it on, with the link to the switch, and asks to pass it on' );
ok( false === $one['run_enabled'] && $off['how_to_run'] === $one['how_to_run'] && false === $one['ability']['can_run'] && $off['how_to_run'] === $one['ability']['way'], 'A12 the listing of one ability says the same, also as its way' );
$rows_off = array();
foreach ( $off['abilities'] as $row ) { $rows_off[ $row['name'] ] = $row; }
ok( false === $rows_off['test/read-info']['can_run'] && true === $rows_off['test/read-info']['allowed'] && false !== strpos( $rows_off['test/read-info']['way'], 'see how_to_run' ), 'A12 in the whole listing each ability says it cannot run and points to how_to_run, which says it once' );
unset( $GLOBALS['ab_toolstate']['wp_run_ability'] );
ok( ab_has( $l['notes'], 'not instructions' ), 'A13 the listing says that descriptions are data' );

/* ======================================================== B) the switches */

ok( array_keys( AB_MCP_Ability_Broker::all() ) === get_option( AB_MCP_Ability_Broker::ON_OPTION ) && null === get_option( 'ab_mcp_pro_abilities_off', null ), 'B0 the approvals are this plugin\'s own list, by name; Pro\'s list of switched-off abilities is not written' );
$before = ab_dump( 30 );
ab_off( array( 'test/set-meta' ) );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'The ability "test/set-meta" is not approved for AlphaBridge on this site' ) && false !== strpos( ab_msg( $r ), 'Nothing ran' ) && false !== strpos( ab_msg( $r ), 'group «Abilities of other plugins»' ) && ab_way( ab_msg( $r ), 'options-general.php?page=alphabridge-mcp#ab-ability-test__set-meta' ), 'B1 not approved: refused with the reason, the steps, the link to its switch and the request to pass it on' );
ok( $before === ab_dump( 30 ) && 0 === ab_points() && ! in_array( 'set-meta', $GLOBALS['calls'], true ), 'B1 nothing ran, nothing changed, no undo point' );
ok( array( 'a/b', 'woo/*' ) === AB_MCP_Ability_Broker::sanitize_list( array( 'woo/*', '*', 'Foo', 'a/b/c', 'A/B', 7, 'x/' ) ), 'B2 the list keeps names and namespace patterns only' );
$l  = AB_MCP_Tools_Abilities::list_abilities( array() );
$by = array();
foreach ( $l['abilities'] as $row ) { $by[ $row['name'] ] = $row; }
ok( false === $by['test/set-meta']['allowed'] && false === $by['test/set-meta']['can_run'] && 0 === strpos( $by['test/set-meta']['allowed_why'], 'not approved' ) && ab_way( $by['test/set-meta']['way'], 'options-general.php?page=alphabridge-mcp#ab-ability-test__set-meta' ) && true === $by['test/silent']['can_run'], 'B3 the listing: that one cannot run, and the way to its switch; the others still can' );
AB_MCP_Ability_Broker::set_on( array( 'other/*' ) );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'other/thing' ) );
ok( true === $d['allowed'] && 'on' === $d['reason'] && false === AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/set-meta' ) )['allowed'], 'B4 a namespace pattern (written by code) approves a whole plugin, and only it' );
ab_off( array() );
add_filter( 'ab_mcp_ability_allowed', static function ( $allowed, $name ) { return 'test/read-info' === $name ? false : $allowed; }, 10, 2 );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/read-info' ) );
ok( false === $d['allowed'] && 'filter' === $d['reason'] && false !== strpos( $d['how'], 'ab_mcp_ability_allowed' ) && ab_way( $d['how'], 'options-general.php?page=alphabridge-mcp#ab-ability-test__read-info' ), 'B5 the last-word filter holds even an approved read-only ability off, and says it is code' );
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && ! in_array( 'read-info', $GLOBALS['calls'], true ), 'B5 and it does not run' );
remove_all_filters( 'ab_mcp_ability_allowed' );
ab_off( array( 'test/global-flush' ) );
add_filter( 'ab_mcp_ability_allowed', static function ( $allowed, $name ) { return 'test/global-flush' === $name ? true : $allowed; }, 10, 2 );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/global-flush' ) );
ok( true === $d['allowed'] && 'filter' === $d['reason'], 'B6 the filter can also let one run that is not approved' );
remove_all_filters( 'ab_mcp_ability_allowed' );
ab_off( array() );

/* ======================================================== C) preview */

$GLOBALS['calls'] = array();
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ) );
ok( is_array( $p ) && true === $p['dry_run'] && false === $p['applied'], 'C1 a writing ability previews by default' );
ok( $before === ab_dump( 30 ) && 0 === ab_points() && array() === $GLOBALS['calls'], 'C1 nothing ran, nothing changed, no undo point' );
ok( true === $p['allowed'] && true === $p['input_valid'] && 'granted' === $p['permission'] && 'undo_point' === $p['way_back'], 'C2 the preview: allowed, input valid, permission granted, way back' );
ok( 1 === count( $p['targets'] ) && 30 === $p['targets'][0]['id'] && 'post_id' === $p['targets'][0]['key'] && true === $p['targets'][0]['covered'] && 'Seite 30' === $p['targets'][0]['title'], 'C3 the post the input names, by which key, with its title' );
ok( ab_has( $p['notes'], 'apply=true' ), 'C3 and how to run it' );
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe' ) ) );
ok( is_string( $p['input_valid'] ) && false !== strpos( $p['input_valid'], 'value' ) && 'not_checked' === $p['permission'], 'C4 an input that does not fit: the reason, permission not checked' );
ab_off( array( 'test/admin-only' ) );
$p = ab_run( array( 'name' => 'test/admin-only', 'input' => array( 'id' => 30 ), 'apply' => false ) );
ok( 'not_checked' === $p['permission'] && false === $p['allowed'] && ab_way( $p['how_to_allow'], 'options-general.php?page=alphabridge-mcp#ab-ability-test__admin-only' ) && ab_has( $p['notes'], 'not approved for AlphaBridge' ), 'C5 an ability that is not approved: its permission check is not run, and the preview gives the way to its switch' );
ab_off( array() );
$p = ab_run( array( 'name' => 'test/admin-only', 'input' => array( 'id' => 30 ) ) );
ok( 'denied' === $p['permission'] && ab_has( $p['warnings'], 'The permission check of the ability "test/admin-only" refuses the account of this connection' ) && ab_has( $p['warnings'], 'a role that may do this in test under Users' ) && ab_has( $p['warnings'], 'Direct link: http://x/wp-admin/users.php' ) && ab_has( $p['warnings'], 'Pass this on' ), 'C6 the ability\'s own permission check says no: the way, with the link to the users' );
$p = ab_run( array( 'name' => 'test/global-flush', 'input' => array( 'mode' => 'all' ) ) );
ok( 'none' === $p['way_back'] && ab_has( $p['warnings'], 'No way back' ) && null === get_option( 'test_flushed', null ), 'C7 an ability without a post: a clear warning before the call, and nothing ran' );
$p = ab_run( array( 'name' => 'test/set-title', 'input' => array( 'id' => 32 ) ) );
ok( false === $p['targets'][0]['covered'] && false !== strpos( $p['targets'][0]['reason'], 'may not edit' ) && 'none' === $p['way_back'], 'C8 a post the account may not edit: not covered, no way back' );
$p = ab_run( array( 'name' => 'test/order-update', 'input' => array( 'id' => 9999 ) ) );
ok( false === $p['targets'][0]['covered'] && false !== strpos( $p['targets'][0]['reason'], 'target_post' ), 'C9 an id that is no post: the answer points to target_post' );
$GLOBALS['ab_toolstate']['wp_undo'] = false;
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ) );
ok( 'none' === $p['way_back'] && ab_has( $p['warnings'], 'Undo is switched off' ), 'C10 undo switched off: the preview says so' );
unset( $GLOBALS['ab_toolstate']['wp_undo'] );

/* ============================ D) undo point before the call, read-back, restore */

$GLOBALS['calls']  = array();
$GLOBALS['points'] = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_array( $r ) && true === $r['applied'] && array( 'updated' => true ) === $r['result'], 'D1 applied; the ability\'s result is passed on' );
ok( array( 1 ) === $GLOBALS['points'], 'D2 the undo point existed when the ability ran (taken before the call)' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ), 'D3 the answer carries the undo token' );
$rb = $r['readback'][0];
ok( 30 === $rb['post'] && false === $rb['unchanged'] && 1 === count( $rb['changed'] ) && 'meta:farbe' === $rb['changed'][0]['loc'] && 'modified' === $rb['changed'][0]['change'], 'D4 read-back: exactly the copy that changed' );
$sha_blau = AB_MCP_Undo_Builder::hash_state( array( 'existed' => true, 'rows' => array( array( 'meta_id' => 1, 'value' => 'blau' ) ) ) );
$sha_rot  = AB_MCP_Undo_Builder::hash_state( array( 'existed' => true, 'rows' => array( array( 'meta_id' => 1, 'value' => 'rot' ) ) ) );
ok( $sha_blau === $rb['changed'][0]['before'] && $sha_rot === $rb['changed'][0]['after'], 'D5 sha256 before and after of that copy' );
$locs = array_keys( AB_MCP_Undo_Builder::state_hashes( 30, AB_MCP_Ability_Broker::locs_of( 30 ) ) );
ok( array( 'meta:farbe', 'meta:multi', 'post_content', 'post_content_filtered' ) === $locs && 4 === $rb['compared'], 'D6 every copy and meta key is compared, caches and WordPress\' own keys are not: ' . implode( ', ', $locs ) );
$e = AB_MCP_Undo::entry_for( $r['undo_token'] );
ok( 'wp_run_ability' === $e['tool'] && 'builder_data' === $e['kind'] && false !== strpos( $e['summary'], 'test/set-meta #30' ) && false === strpos( $e['summary'], 'rot' ), 'D7 the point: tool, kind builder_data, summary without input values' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && $before === ab_dump( 30 ), 'D8 wp_undo puts the post back byte for byte' );
ok( array() === ab_meta_rows( 30, '_elementor_css' ), 'D8 the cache made from the post was emptied, not put back (rebuilt on the next view)' );

// A key the call adds.
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'neu', 'value' => 'hallo' ), 'apply' => true ) );
$rb = $r['readback'][0];
ok( 1 === count( $rb['changed'] ) && 'meta:neu' === $rb['changed'][0]['loc'] && 'added' === $rb['changed'][0]['change'], 'D9 a meta key the call added is reported as added' );
$e = AB_MCP_Undo::entry_for( $r['undo_token'] );
ok( 1 === count( $e['parts'] ), 'D10 and kept as missing in a part of the same point' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && $before === ab_dump( 30 ), 'D11 wp_undo removes it again: the post as before' );

// Somebody changes the post after the run: wp_undo refuses without force.
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'gelb' ), 'apply' => true ) );
$GLOBALS['db_meta'][ $m_farbe ][2] = 'von Hand';
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array() === $u['restored'] && 'changed_since' === $u['blocked'][0]['reason'], 'D12 a later change by somebody else is not thrown away' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'], 'force' => true ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && $before === ab_dump( 30 ), 'D13 force puts it back over it' );

// A builder page: the item names the builder, the caches are emptied.
$b31 = ab_dump( 31 );
$GLOBALS['ab_meta_deleted'] = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 31, 'key' => '_fake_layout', 'value' => '[]' ), 'apply' => true ) );
ok( is_array( $r ) && 'fake' === $r['targets'][0]['builder'] && isset( $r['caches']['31'] ) && in_array( '31:_elementor_css', $GLOBALS['ab_meta_deleted'], true ), 'D14 on a page-builder page the builder\'s caches are emptied after the change' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && $b31 === ab_dump( 31 ), 'D15 and wp_undo puts the builder page back' );

/* ======================================================== E) silent failure */

$GLOBALS['calls'] = array();
$n = ab_points();
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && array( 'silent' ) === $GLOBALS['calls'] && true === $r['readback'][0]['unchanged'], 'E1 the ability ran and the post is unchanged' );
ok( ab_has( $r['warnings'], 'reported success but nothing changed' ), 'E2 the warning: reported success but nothing changed' );
ok( null === $r['undo_token'] && $n === ab_points() && ab_has( $r['notes'], 'No undo point was kept' ), 'E3 the undo point with nothing to undo is dropped, and the answer says so' );

/* ===================================== F) a failure, with and without a change */

$r = ab_run( array( 'name' => 'test/content-fail', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'Could not finish the layout' ) && false !== strpos( ab_msg( $r ), 'post_content' ), 'F1 the plugin\'s error, and what it changed all the same' );
preg_match( '/undo token (undo_[0-9a-f]+)/', ab_msg( $r ), $mt );
ok( isset( $mt[1] ) && AB_MCP_Undo::valid_token( $mt[1] ), 'F2 the error message carries the undo token' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $mt[1] ) );
ok( is_array( $u ) && $before === ab_dump( 30 ), 'F3 and wp_undo with it puts the post back' );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/fail-clean', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'Refused by the plugin' ) && false !== strpos( ab_msg( $r ), 'Nothing in post #30 changed' ), 'F4 a refusal that changed nothing says so' );
ok( $n === ab_points(), 'F5 and leaves no undo point' );
$r = ab_run( array( 'name' => 'test/throws', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'test/throws' ) && $n === ab_points(), 'F6 an ability that throws: an error, nothing kept' );

/* ======================================== G) an ability without a post */

$r = ab_run( array( 'name' => 'test/global-flush', 'input' => array( 'mode' => 'all' ), 'apply' => true ) );
ok( is_array( $r ) && true === $r['applied'] && null === $r['undo_token'] && 1 === get_option( 'test_flushed' ), 'G1 it runs, without an undo point' );
ok( ab_has( $r['warnings'], 'without a way back' ) && array() === $r['readback'], 'G2 the answer warns that there is no way back' );
$r = ab_run( array( 'name' => 'test/order-update', 'input' => array( 'id' => 30 ), 'target_post' => 0, 'apply' => true ) );
ok( is_array( $r ) && array() === $r['targets'] && null === $r['undo_token'] && ! ab_has( $r['warnings'], 'reported success' ), 'G3 target_post=0: the id is not taken for a post, and no false alarm' );
$r = ab_run( array( 'name' => 'test/order-update', 'input' => array( 'id' => 9999 ), 'target_post' => 30, 'apply' => true ) );
ok( is_array( $r ) && 30 === $r['targets'][0]['id'] && 'target_post' === $r['targets'][0]['key'] && ab_has( $r['warnings'], 'reported success but nothing changed' ), 'G4 target_post names the post explicitly' );

/* =================================================== H) rights */

$n = ab_points();
$r = ab_run( array( 'name' => 'test/admin-only', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'refuses the account of this connection' ) && ab_way( ab_msg( $r ), 'users.php' ) && $before === ab_dump( 30 ) && $n === ab_points() && ! in_array( 'admin-only', $GLOBALS['calls'], true ), 'H1 the ability\'s own permission check stays: refused, with the way, nothing kept' );
$GLOBALS['ab_caps']['manage_options'] = true;
$r = ab_run( array( 'name' => 'test/admin-only', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && AB_MCP_Undo::valid_token( $r['undo_token'] ), 'H2 with the right it runs' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
$GLOBALS['ab_caps']['manage_options'] = false;
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 32, 'key' => 'x', 'value' => 'y' ), 'apply' => true ) );
ok( is_array( $r ) && null === $r['undo_token'] && ab_has( $r['warnings'], 'your account may not edit it' ) && ab_has( $r['warnings'], 'without a way back' ), 'H3 a post the account may not edit: no undo point of it, and the answer says so' );

/* =================================================== I) scope */

$GLOBALS['ab_scope'] = 'content';
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( is_wp_error( $r ) && 'ab_mcp_scope' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'access level "content"' ) && false !== strpos( ab_msg( $r ), 'the access level "Full"' ) && ab_way( ab_msg( $r ), 'options-general.php?page=alphabridge-mcp#ab-connections' ), 'I1 a content token cannot run abilities; the answer names the access level it needs, with the link to the connections' );
$l = AB_MCP_Tools_Abilities::list_abilities( array() );
ok( false === $l['run_enabled'] && ab_way( $l['how_to_run'], 'options-general.php?page=alphabridge-mcp#ab-connections' ), 'I1 and the listing says so for that connection' );
$mt = AB_MCP_Undo_Restore::may_touch( 'wp_run_ability' );
ok( is_wp_error( $mt ) && 'ab_mcp_undo_scope' === $mt->get_error_code() && false !== strpos( ab_msg( $mt ), 'access level "content"' ) && ab_way( ab_msg( $mt ), 'options-general.php?page=alphabridge-mcp#ab-connections' ), 'I2 nor touch their undo points; the answer leads to the connections' );
$mt = AB_MCP_Undo_Restore::may_touch( 'wp_search_replace' );
ok( is_wp_error( $mt ) && 'ab_mcp_undo_forbidden' === $mt->get_error_code() && false !== strpos( ab_msg( $mt ), '(manage_options), and the account of this connection lacks it' ) && ab_way( ab_msg( $mt ), 'users.php' ), 'I2 an undo point of a tool the account\'s role does not reach: the answer leads to the users' );
ok( true === AB_MCP_Undo_Restore::may_touch( 'wp_update_builder_element' ), 'I3 while a builder point stays within its reach' );
$GLOBALS['ab_scope'] = 'full';
ok( true === AB_MCP_Undo_Restore::may_touch( 'wp_run_ability' ), 'I4 a full token may' );
$pol = AB_MCP_Undo_Restore::policy();
ok( 'builder_data' === $pol['wp_run_ability']['kind'] && 'edit_posts' === $pol['wp_run_ability']['cap'] && true === $pol['wp_run_ability']['full_scope'], 'I5 policy: builder_data, edit_posts, full scope' );

/* =================================================== J) read-only abilities */

$GLOBALS['calls'] = array();
$n = ab_points();
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( is_array( $r ) && true === $r['applied'] && array( 'name' => 'Testsite' ) === $r['result'] && null === $r['undo_token'] && $n === ab_points(), 'J1 a read-only ability runs at once, without an undo point' );
$r = ab_run( array( 'name' => 'test/read-info', 'apply' => false ) );
ok( is_array( $r ) && true === $r['dry_run'] && array( 'read-info' ) === $GLOBALS['calls'] && 'not_needed' === $r['way_back'], 'J2 apply=false previews it' );
$r = ab_run( array( 'name' => 'test/no-input', 'input' => array() ) );
ok( is_array( $r ) && 'pong' === $r['result'], 'J3 an ability without input schema: an empty input becomes none' );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/sneaky-read', 'input' => array( 'id' => 30 ) ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'annotated read-only by its plugin, but post #30 changed' ) && 'added' === $r['readback'][0]['changed'][0]['change'], 'J4 a "read-only" ability that changes the post is caught by the read-back' );
ok( is_array( $r ) && AB_MCP_Undo::valid_token( $r['undo_token'] ) && $n + 1 === ab_points() && ab_has( $r['notes'], 'state before the call is kept as an undo point' ), 'J6 and the state before the call is kept as an undo point, recorded only now' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && $before === ab_dump( 30 ), 'J7 wp_undo removes what the "read-only" ability added' );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/read-info', 'input' => array( 'fields' => array() ), 'target_post' => 30 ) );
ok( is_array( $r ) && null === $r['undo_token'] && $n === ab_points() && true === $r['readback'][0]['unchanged'], 'J8 a read-only call that changes nothing records no point' );
$r = ab_run( array( 'name' => 'test/read-info', 'input' => '{"fields":["name"]}' ) );
ok( is_array( $r ) && true === $r['applied'], 'J5 JSON text for an object input is read as the object' );

/* =================================================== K) post fields */

$r = ab_run( array( 'name' => 'test/set-title', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && array( 'post_title' ) === $r['readback'][0]['post_fields_changed'] && 'Seite 30' === $r['readback'][0]['post_fields_before']['post_title'], 'K1 a changed post field, with its old value' );
ok( ab_has( $r['warnings'], 'wp_undo does not restore post fields' ) && null === $r['undo_token'] && ! ab_has( $r['warnings'], 'reported success' ), 'K2 the warning names the way (wp_update_post); no undo point that could not restore it; no false alarm' );
$GLOBALS['db_posts'][30]['post_title'] = 'Seite 30';

// A key WordPress writes through its own functions: compared, not restored.
$r = ab_run( array( 'name' => 'test/set-thumb', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$c = is_array( $r ) ? $r['readback'][0]['changed'] : array();
ok( 1 === count( $c ) && 'meta:_thumbnail_id' === $c[0]['loc'] && 'added' === $c[0]['change'] && false === $c[0]['in_undo'], 'K3 the featured image is read back, marked as outside the undo point' );
ok( is_array( $r ) && null === $r['undo_token'] && ab_has( $r['warnings'], 'wp_undo does not restore them' ) && ! ab_has( $r['warnings'], 'reported success' ), 'K4 the way to set it back, no undo point that could not, no false alarm' );
foreach ( array_keys( ab_meta_rows( 30, '_thumbnail_id' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }

/* =================================================== L) an open editor */

$lock = ab_meta_add( 33, '_edit_lock', time() . ':99' );
$r    = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 33, 'key' => 'a', 'value' => 'b' ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'User #99' ) && false !== strpos( ab_msg( $r ), 'Nothing ran' ) && array() === ab_meta_rows( 33, 'a' ), 'L1 another user\'s open editor: nothing runs' );
unset( $GLOBALS['db_meta'][ $lock ] );

// Somebody saves the post between the undo point and the call.
$GLOBALS['ab_meanwhile'] = false;
add_filter( 'ab_mcp_undo_enabled', static function ( $on ) {
	if ( $GLOBALS['ab_meanwhile'] ) {
		$GLOBALS['ab_meanwhile']           = false;
		$GLOBALS['db_posts'][30]['post_content'] = 'von jemand anderem';
	}
	return $on;
} );
$n                       = ab_points();
$GLOBALS['calls']        = array();
$GLOBALS['ab_meanwhile'] = true;
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_changed_meanwhile' === $r->get_error_code() && array() === $GLOBALS['calls'] && $n === ab_points(), 'L2 a save by somebody else after the undo point: nothing runs, the point is dropped' );
ok( 'von jemand anderem' === $GLOBALS['db_posts'][30]['post_content'], 'L2 and their version is kept' );
$GLOBALS['db_posts'][30]['post_content'] = $before['post']['post_content'];
remove_all_filters( 'ab_mcp_undo_enabled' );

/* =================================================== O) the size limit */

add_filter( 'ab_mcp_undo_snapshot_max', static function () { return 10; } );
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ) );
ok( 'none' === $p['way_back'] && ab_has( $p['warnings'], 'allow_without_undo=true' ), 'O1 the preview says the undo point would be too large' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'allow_without_undo=true' ) && $before === ab_dump( 30 ), 'O2 too large: nothing runs without allow_without_undo' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true, 'allow_without_undo' => true ) );
ok( is_array( $r ) && null === $r['undo_token'] && ab_has( $r['warnings'], 'allow_without_undo' ), 'O3 with it, it runs without a way back and says so' );
remove_all_filters( 'ab_mcp_undo_snapshot_max' );
$GLOBALS['db_meta'][ $m_farbe ][2] = 'blau';
ok( $before === ab_dump( 30 ), 'O4 (the post as before for what follows)' );

/* =================================================== P) the log */

$rows = AB_MCP_Audit_Log::$rows;
$text = '';
foreach ( $rows as $row ) {
	ok( 'wp_run_ability' === $row['tool'] && '' === $row['keys'], 'P1 log line of wp_run_ability without argument keys' );
	$text .= $row['message'] . "\n";
}
ok( count( $rows ) > 10 && false !== strpos( $text, 'ability test/set-meta · post #30 · applied' ), 'P2 the log names the ability and the post' );
ok( false === strpos( $text, 'rot' ) && false === strpos( $text, 'gelb' ) && false === strpos( $text, 'hallo' ) && false === strpos( $text, 'farbe' ), 'P3 and no input value' );

/* =========================================== Q) instructions and switches */

$ins = AB_MCP_Ability_Broker::instructions();
ok( false !== strpos( $ins, 'WooCommerce' ) && false !== strpos( $ins, 'Elementor V4' ) && false !== strpos( $ins, 'undo point' ) && false !== strpos( $ins, 'says for each whether it can run' ) && false !== strpos( $ins, 'An ability runs only once an administrator approved it under Fine-tuning' ), 'Q1 the server instructions name the abilities; wp_run_ability is on: vendors, the way back, and that an ability runs only once approved' );
ok( false === strpos( $ins, 'unless an administrator switched it off' ), 'Q1 and they do not say that every ability runs' );
$GLOBALS['ab_toolstate']['wp_run_ability'] = false;
$ins = AB_MCP_Ability_Broker::instructions();
ok( false !== strpos( $ins, 'running them (wp_run_ability) is switched off' ) && false !== strpos( $ins, 'Settings → AlphaBridge MCP → Fine-tuning (http://x/wp-admin/options-general.php?page=alphabridge-mcp#ab-tool-wp_run_ability)' ) && false !== strpos( $ins, 'tell them that kindly' ) && false === strpos( $ins, 'undo point' ), 'Q2 with wp_run_ability switched off: that running is off, where to switch it on, with the link, and to tell the person kindly' );
$GLOBALS['ab_toolstate']['wp_list_abilities'] = false;
$GLOBALS['ab_toolstate']['wp_run_ability']    = false;
ok( '' === AB_MCP_Ability_Broker::instructions(), 'Q3 with both tools off, no sentence' );
$GLOBALS['ab_toolstate'] = array();

// The switch for write access (the site mode), as far as the broker asks
// it: whether write access is on, the rule that with it off only reading
// tools run (wp_list_abilities reads, wp_run_ability does not) and the
// refusal in the plugin's words. Declared only here, so everything above
// ran as without it.
$GLOBALS['ab_site_mode'] = 'read';
if ( ! class_exists( 'AB_MCP_Site_Mode', false ) ) {
	class AB_MCP_Site_Mode {
		public static function is_full() { return 'full' === $GLOBALS['ab_site_mode']; }
		public static function allows( $name, array $def ) { return self::is_full() || 'wp_list_abilities' === $name; }
		public static function refusal( $name ) {
			return new WP_Error( 'ab_mcp_read_mode', AB_MCP_Guidance::compose( sprintf( 'Write access is off on this site, so the tool "%s" did not run: it changes the site, and with write access off AI assistants only read.', $name ), 'To allow it, an administrator can switch on write access at the top of Settings → AlphaBridge MCP and confirm the notice there; that is at the site owner\'s own risk, and a current backup is advised.', AB_MCP_Guidance::settings_url( 'ab-mode' ) ) );
		}
	}
}
$refusal = AB_MCP_Site_Mode::refusal( 'wp_run_ability' )->get_error_message();
ok( false === AB_MCP_Ability_Broker::mode_allows_run(), 'QR1 with write access off wp_run_ability may not run' );
$l   = AB_MCP_Tools_Abilities::list_abilities( array() );
$one = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/read-info' ) );
ok( false === $l['run_enabled'] && $refusal === $l['how_to_run'] && ab_way( $l['how_to_run'], 'options-general.php?page=alphabridge-mcp#ab-mode' ), 'QR2 with write access off the listing says running is not possible, in the plugin\'s words, with the link to the switch' );
ok( false === $one['run_enabled'] && $refusal === $one['how_to_run'] && true === $one['ability']['allowed'] && false === $one['ability']['can_run'] && $refusal === $one['ability']['way'], 'QR2 also for one read-only ability: approved, but it cannot run before write access is on' );
$GLOBALS['ab_toolstate']['wp_run_ability'] = false;
$l = AB_MCP_Tools_Abilities::list_abilities( array() );
ok( $refusal === $l['how_to_run'], 'QR3 with write access off and the tool switched off: write access is named first, the switch that every writing tool waits for' );
$GLOBALS['ab_toolstate'] = array();
$ins = AB_MCP_Ability_Broker::instructions();
ok( false !== strpos( $ins, 'running them (wp_run_ability) writes, so it waits for write access' ) && false === strpos( $ins, 'runs one with a preview' ), 'QR4 with write access off the instructions say that running waits for it, not how a run goes' );
$st   = AB_MCP_Ability_Switches::state();
$html = AB_MCP_Ability_Switches::fine_html( $st );
ok( true === $st['read_mode'] && false !== strpos( $html, '<p class="ab-note ab-abilities__read">' . AB_MCP_Admin::addon_text( 'abilities_write_off' ) . '</p>' ) && false !== strpos( AB_MCP_Admin::addon_text( 'abilities_write_off' ), 'Write access is off, so no ability runs' ), 'QR5 with write access off the switches say that no ability runs, and where to switch it on' );
ok( false !== strpos( $html, 'name="ab_abilities_approved[]" value="test/set-meta" form="ab-caps" checked="checked"' ), 'QR5 and the switches can still be set' );
$GLOBALS['ab_site_mode'] = 'full';
$l = AB_MCP_Tools_Abilities::list_abilities( array() );
ok( true === AB_MCP_Ability_Broker::mode_allows_run() && true === $l['run_enabled'] && ! isset( $l['how_to_run'] ), 'QR7 with write access on the listing says wp_run_ability can run' );
ok( false !== strpos( AB_MCP_Ability_Broker::instructions(), 'runs one with a preview' ) && false === strpos( AB_MCP_Ability_Switches::fine_html( AB_MCP_Ability_Switches::state() ), 'ab-abilities__read' ), 'QR7 the instructions say how a run goes, and the switches say nothing about write access' );
ok( array( 'gone/away', 'test/silent' ) === AB_MCP_Ability_Switches::merge( array( 'gone/away', 'test/set-meta' ), array( 'test/set-meta', 'test/silent' ), array( 'test/silent', 'test/not-shown' ) ), 'Q4 saving: what the screen did not show stays approved; of what it showed, exactly those switched on are, and nothing else gets in' );
ok( array( 'gone/away' ) === AB_MCP_Ability_Switches::merge( array( 'gone/away', 'test/set-meta' ), array( 'test/set-meta' ), array() ), 'Q4 an approved ability switched off leaves the list' );
$q4 = AB_MCP_Ability_Switches::merge( array( 'other/x', 'vendor/*' ), array( 'vendor/read', 'vendor/write' ), array( 'vendor/read' ) );
ok( array( 'other/x', 'vendor/read' ) === $q4 && ! AB_MCP_Ability_Broker::listed( 'vendor/write', $q4 ) && AB_MCP_Ability_Broker::listed( 'vendor/read', $q4 ), 'Q4 one ability of an approved namespace switched off: the pattern goes, the others shown keep their approval by name (Codex, 30.10.2026): ' . implode( ',', $q4 ) );
ok( array( 'vendor/*', 'vendor/read', 'vendor/write' ) === AB_MCP_Ability_Switches::merge( array( 'vendor/*' ), array( 'vendor/read', 'vendor/write' ), array( 'vendor/read', 'vendor/write' ) ), 'Q4 all of them left on: the pattern stays' );
ok( array() === AB_MCP_Ability_Switches::merge( array( 'woo/*' ), array( 'woo/x' ), array() ), 'Q4 a namespace pattern (written by code) goes when the screen showed an ability under it switched off' );
ok( array( 'woo/*' ) === AB_MCP_Ability_Switches::merge( array( 'woo/*' ), array( 'other/y' ), array() ), 'Q4 a pattern none of whose abilities the screen showed stays' );

/* ================================ F) the switches in the fine-tuning */

// The fine-tuning of this plugin draws the switches in its main group
// «Abilities of other plugins» (filter ab_mcp_fine_group_html), for
// administrators, where this plugin's wp_run_ability is the registered one.
$GLOBALS['ab_caps']['manage_options'] = true;
ok( 'x' === AB_MCP_Ability_Switches::fine_section( 'x', 'content', array( 'content', 'seo' ) ), 'F3 nothing in a main group without the tool group abilities' );

ab_off( array( 'test/silent' ) );
AB_MCP_Ability_Broker::set_on( array_merge( AB_MCP_Ability_Broker::on_list(), array( 'gone/away' ) ) );
$fine = apply_filters( 'ab_mcp_fine_group_html', '', 'abilities', array( 'abilities' ) );
ok( 0 === strpos( $fine, '<div class="ab-abilities ab-abilities--fine" id="ab-abilities">' ), 'F4 in the main group that holds the abilities, under the id a link goes to (through the filter the fine-tuning fires)' );
ok( $fine === AB_MCP_Ability_Switches::fine_section( '', 'site', array( 'options', 'abilities' ) ), 'F4 also where a filter moved the tool group' );
ok( false === strpos( $fine, '<form' ) && false === strpos( $fine, 'ab-savebar' ) && false === strpos( $fine, '_wpnonce' ), 'F5 no form, no button and no nonce of its own: the fine-tuning\'s one form and one button save it' );
ok( false !== strpos( $fine, '<input type="hidden" name="ab_abilities_listed" value="elementor/manage-elements,elementor/publish-document,other/thing,test/admin-only,' ) && false !== strpos( $fine, 'form="ab-caps">' ), 'F5 the list of the abilities shown posts with the fine-tuning\'s form' );
ok( false !== strpos( $fine, '<p class="ab-note ab-abilities__lead">' . AB_MCP_Admin::addon_text( 'abilities_approve_lead' ) . '</p>' ) && false !== strpos( AB_MCP_Admin::addon_text( 'abilities_approve_lead' ), 'None of them runs until you switch it on here' ) && false !== strpos( AB_MCP_Admin::addon_text( 'abilities_approve_lead' ), 'switching on write access switches no ability on' ), 'F5 the lead is the plugin\'s text (translated there): none runs until switched on, and write access switches none on' );
ok( false === strpos( $fine, AB_MCP_Admin::addon_text( 'abilities_lead' ) ), 'F5 not the lead of Pro\'s switches, which says every ability runs' );
preg_match_all( '/class="ab-pill[^"]*"/', $fine, $pills );
$kinds = array_keys( array_count_values( $pills[0] ) );
sort( $kinds );
ok( array( 'class="ab-pill ab-pill--reads"', 'class="ab-pill ab-pill--writes"' ) === $kinds, 'F6 the only badges are Reads and Writes: ' . implode( ' ', $kinds ) );
ok( false === strpos( $fine, 'ab-pill--mighty' ) && false === strpos( $fine, 'Mighty' ) && false === strpos( $fine, '>destructive<' ) && false === strpos( $fine, '>read-only<' ), 'F6 no Mighty, no badge «destructive» or «read-only»' );
$row = static function ( $name ) use ( $fine ) {
	return preg_match( '#<li class="ab-tool-row" id="[^"]*"(?:(?!</li>).)*value="' . preg_quote( $name, '#' ) . '"(?:(?!</li>).)*</li>#s', $fine, $m ) ? $m[0] : '';
};
ok( false !== strpos( $row( 'test/set-meta' ), 'id="ab-ability-test__set-meta"' ) && false !== strpos( $row( 'test/set-meta' ), '<span class="ab-tool-row__note ab-tool-row__note--deletes">deletes</span><span class="ab-pill ab-pill--writes">' ), 'F7 a destructive writing ability: the anchor a refusal links to, «deletes» as text and the badge Writes' );
ok( false !== strpos( $row( 'test/internal-export' ), 'ab-pill--reads' ) && false === strpos( $row( 'test/internal-export' ), 'deletes' ), 'F7 an internal read-only one: Reads' );
ok( false !== strpos( $row( 'test/contra' ), 'ab-pill--writes' ), 'F7 one that says read-only and destructive at once counts as writing' );
ok( false !== strpos( $row( 'test/read-info' ), 'ab-pill--reads' ) && false !== strpos( $row( 'test/read-info' ), 'checked="checked"' ), 'F7 an approved read-only one: Reads, switched on' );
ok( 1 === preg_match( '#<label class="ab-switch ab-switch--sm"><input type="checkbox" role="switch" class="ab-item" name="ab_abilities_approved\[\]" value="test/silent" form="ab-caps">.*?<span class="screen-reader-text">test/silent</span></label>#s', $fine ), 'F8 each ability: a switch with role switch, posting with the fine-tuning (form ab-caps), moved by the switches for its group and for all (ab-item), named for screen readers; test/silent is not approved, so off' );
ok( false === strpos( $fine, 'name="ab_abilities_on[]"' ) && false === strpos( $fine, 'name="ab_abilities_shown"' ), 'F8 the fields are not named as Pro\'s, which Pro saves on the same action' );
ok( 3 === substr_count( $fine, 'class="ab-sub"' ) && false !== strpos( $fine, 'aria-controls="ab-sub-ability-test"' ) && false !== strpos( $fine, 'aria-expanded="false"' ) && false !== strpos( $fine, '<span class="ab-sub__name">other</span>' ), 'F8 one folding group per plugin, its button with aria-expanded' );
ok( 1 === preg_match( '#<p class="ab-note ab-abilities__extra">' . preg_quote( AB_MCP_Admin::addon_text( 'abilities_kept_on' ), '#' ) . ' <code>gone/away</code></p>#', $fine ), 'F8 an approved ability that is not registered now is named as kept, and only that one' );
$GLOBALS['ab_site_mode'] = 'read';
$fine = AB_MCP_Ability_Switches::fine_section( '', 'abilities', array( 'abilities' ) );
ok( false !== strpos( $fine, '<p class="ab-note ab-abilities__read">' . AB_MCP_Admin::addon_text( 'abilities_write_off' ) . '</p>' ), 'F9 with write access off: the sentence that no ability runs then' );
$GLOBALS['ab_site_mode'] = 'full';

// Saving with the fine-tuning: AB_MCP_Admin::handle_save() fires
// ab_mcp_fine_save after its check of the capability and the nonce.
$before_on = AB_MCP_Ability_Broker::on_list();
do_action( 'ab_mcp_fine_save', array( 'enabled_tools' => array( 'wp_run_ability' ), 'ab_abilities_listed' => 'test/silent,test/set-meta,evil/x', 'ab_abilities_approved' => array( 'test/silent', 'evil/x' ) ) );
$after_on = AB_MCP_Ability_Broker::on_list();
ok( in_array( 'test/silent', $after_on, true ) && ! in_array( 'test/set-meta', $after_on, true ) && in_array( 'gone/away', $after_on, true ) && ! in_array( 'evil/x', $after_on, true ) && array_values( array_diff( $before_on, array( 'test/set-meta' ) ) ) === array_values( array_diff( $after_on, array( 'test/silent' ) ) ), 'F10 saved with the fine-tuning: test/silent approved, test/set-meta no longer, gone/away and the others kept, an unregistered name the form claims does not get in' );
do_action( 'ab_mcp_fine_save', array( 'enabled_tools' => array() ) );
ok( $after_on === AB_MCP_Ability_Broker::on_list(), 'F10 a save of a form that did not show the abilities changes none' );
do_action( 'ab_mcp_fine_save', array( 'ab_abilities_shown' => 'test/read-info,test/silent', 'ab_abilities_on' => array() ) );
ok( $after_on === AB_MCP_Ability_Broker::on_list(), 'F10 Pro\'s fields change nothing here' );
$GLOBALS['ab_caps']['manage_options'] = false;
do_action( 'ab_mcp_fine_save', array( 'ab_abilities_listed' => 'test/read-info', 'ab_abilities_approved' => array() ) );
ok( $after_on === AB_MCP_Ability_Broker::on_list() && '' === AB_MCP_Ability_Switches::fine_section( '', 'abilities', array( 'abilities' ) ), 'F11 for an account that is no administrator: nothing shown, nothing saved' );
$GLOBALS['ab_caps']['manage_options'] = true;
$GLOBALS['ab_run_callback'] = array( 'AB_MCP_Pro_Tools_Abilities', 'run_ability' );
ok( '' === AB_MCP_Ability_Switches::fine_section( '', 'abilities', array( 'abilities' ) ), 'F12 where Pro\'s wp_run_ability replaced this plugin\'s, its switches are Pro\'s, and these do not show' );
$GLOBALS['ab_run_callback'] = array( 'AB_MCP_Tools_Abilities', 'run_ability' );
$GLOBALS['ab_caps']['manage_options'] = false;
ab_off( array() );

/* ====================== R) published, internal, contradictory, not approved */

$GLOBALS['calls'] = array();
$l   = AB_MCP_Tools_Abilities::list_abilities( array() );
$all = array();
foreach ( $l['abilities'] as $row ) { $all[] = $row['name']; }
ok( in_array( 'test/internal-export', $all, true ) && ! isset( $l['hidden'] ), 'R1 an ability its plugin keeps internal is listed to every account while it is approved' );
ab_off( array( 'test/internal-export' ) );
$l   = AB_MCP_Tools_Abilities::list_abilities( array() );
$all = array();
foreach ( $l['abilities'] as $row ) { $all[] = $row['name']; }
ok( ! in_array( 'test/internal-export', $all, true ) && 1 === $l['hidden'] && ab_has( $l['notes'], 'not approved and listed to administrators only' ), 'R1 not approved, it is not listed to an account without manage_options; the listing counts it and says why' );
$l = AB_MCP_Tools_Abilities::list_abilities( array( 'search' => 'export' ) );
ok( 0 === $l['count'] && 1 === $l['hidden'], 'R1 the hidden count does not follow a search (it would tell what an internal ability is called)' );
$one = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/internal-export' ) );
$unk = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/zzz' ) );
ok( is_wp_error( $one ) && 'ab_mcp_ability_unknown' === $one->get_error_code() && false !== strpos( ab_msg( $unk ), 'Similar: test/admin-only' ) && false === strpos( ab_msg( $unk ), 'internal-export' ), 'R2 asked by name it answers "not available", as the core\'s REST route answers 404, and no "similar" list names it' );
$r = ab_run( array( 'name' => 'test/internal-export' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_unknown' === $r->get_error_code() && array() === $GLOBALS['calls'], 'R3 nor does it run for that account' );
$GLOBALS['ab_caps']['manage_options'] = true;
$l  = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'test/internal-export' ) );
$ie = is_array( $l ) ? $l['ability'] : array();
ok( false === $ie['allowed'] && false === $ie['can_run'] && false === $ie['public'] && ab_way( $ie['way'], 'options-general.php?page=alphabridge-mcp#ab-ability-test__internal-export' ), 'R4 an administrator sees it: not approved, internal, and the way to its switch' );
$r = ab_run( array( 'name' => 'test/internal-export' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && array() === $GLOBALS['calls'], 'R5 not approved it does not run, not even for an administrator' );
ab_off( array() );
$GLOBALS['ab_caps']['manage_options'] = false;
$r = ab_run( array( 'name' => 'test/internal-export' ) );
ok( is_array( $r ) && array( 'secret' => 'S3CR3T' ) === $r['result'] && array( 'internal-export' ) === $GLOBALS['calls'], 'R6 approved again, it runs (read-only: at once), for every account its own check lets through' );
$d = AB_MCP_Ability_Broker::decision( wp_get_ability( 'test/mcp-read' ) );
ok( true === $d['allowed'] && 'on' === $d['reason'] && true === AB_MCP_Ability_Broker::is_public( wp_get_ability( 'test/mcp-read' ) ), 'R7 published through mcp.public counts as published' );
$GLOBALS['calls'] = array();
$a = AB_MCP_Ability_Broker::annotations( wp_get_ability( 'test/text-false' ) );
$p = ab_run( array( 'name' => 'test/text-false', 'input' => array( 'id' => 30 ) ) );
ok( null === $a['readonly'] && is_array( $p ) && true === $p['dry_run'] && array() === $GLOBALS['calls'] && $before === ab_dump( 30 ), 'R8 readonly "false" as text is no read-only annotation: the call previews, nothing ran' );
$r = ab_run( array( 'name' => 'test/contra', 'input' => array( 'id' => 30 ) ) );
ok( false === AB_MCP_Ability_Broker::is_readonly( wp_get_ability( 'test/contra' ) ) && is_array( $r ) && true === $r['dry_run'] && array() === $GLOBALS['calls'] && $before === ab_dump( 30 ), 'R9 read-only and destructive at once: the reading that it writes wins, the call previews' );
ab_off( array( 'test/read-info' ) );
$r = ab_run( array( 'name' => 'test/read-info' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_not_allowed' === $r->get_error_code() && ab_way( ab_msg( $r ), 'options-general.php?page=alphabridge-mcp#ab-ability-test__read-info' ) && array() === $GLOBALS['calls'], 'R10 an administrator took the approval of a read-only ability back: it does not run, and the answer leads to its switch' );
ab_off( array() );

/* ============================ T) more posts than one undo point takes */

$ids30 = range( 200, 229 );
foreach ( $ids30 as $i ) {
	ab_post( $i, array( 'post_content' => 'Beitrag ' . $i ) );
	$GLOBALS['ab_caps'][ 'edit_post:' . $i ] = true;
}
$dumps = array();
foreach ( $ids30 as $i ) { $dumps[ $i ] = ab_dump( $i ); }
$GLOBALS['calls'] = array();
$p = ab_run( array( 'name' => 'test/bulk', 'input' => array( 'post' => $ids30 ) ) );
ok( is_array( $p ) && 25 === count( $p['targets'] ) && array( 225, 226, 227, 228, 229 ) === $p['not_covered'] && 'partial' === $p['way_back'], 'T1 the preview: 25 posts covered, the other five named, the way back partial' );
ok( ab_has( $p['warnings'], 'The input names 30 posts' ) && ab_has( $p['warnings'], '#225, #226, #227, #228, #229 get no undo point' ) && ab_has( $p['warnings'], 'allow_without_undo=true' ), 'T1 and a warning that names them and the way' );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/bulk', 'input' => array( 'post' => $ids30 ), 'apply' => true ) );
$untouched = true;
foreach ( $ids30 as $i ) { $untouched = $untouched && $dumps[ $i ] === ab_dump( $i ); }
ok( is_wp_error( $r ) && 'ab_mcp_ability_too_many_posts' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'in parts of at most 25' ) && array() === $GLOBALS['calls'] && $untouched && $n === ab_points(), 'T2 applied: refused with the way, nothing ran, no undo point' );
$r = ab_run( array( 'name' => 'test/bulk', 'input' => array( 'post' => $ids30 ), 'apply' => true, 'allow_without_undo' => true ) );
ok( is_array( $r ) && 25 === count( $r['readback'] ) && array( 225, 226, 227, 228, 229 ) === $r['not_covered'] && ab_has( $r['warnings'], 'Ran all the same' ) && AB_MCP_Undo::valid_token( $r['undo_token'] ), 'T3 with allow_without_undo it runs; the answer names the posts without a way back' );
ok( ab_has( $r['notes'], 'of the changed posts in readback back' ), 'T3 and the undo note speaks of the posts in readback only' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
$back = true;
foreach ( range( 200, 224 ) as $i ) { $back = $back && $dumps[ $i ] === ab_dump( $i ); }
ok( is_array( $u ) && 25 === count( $u['restored'] ) && $back && array( 'ja' ) === array_values( ab_meta_rows( 229, 'bulk' ) ), 'T4 wp_undo puts the 25 back; the five beyond stay changed, as the answer said' );
$GLOBALS['calls'] = array();
$p = ab_run( array( 'name' => 'test/bulk', 'input' => array( 'post' => range( 200, 224 ) ) ) );
ok( is_array( $p ) && 'undo_point' === $p['way_back'] && ! isset( $p['not_covered'] ) && ! ab_has( $p['warnings'], 'get no undo point' ), 'T5 exactly 25 posts: the whole way back' );

/* ============================ U) an id that names something else */

$l  = AB_MCP_Tools_Abilities::list_abilities( array() );
$by = array();
foreach ( $l['abilities'] as $row ) { $by[ $row['name'] ] = $row; }
ok( false !== strpos( $by['test/delete-term']['way_back'], 'if id is a post id here' ) && false === strpos( $by['test/set-meta']['way_back'], 'if id' ), 'U1 the listing promises a way back by id only if id is a post id' );
$GLOBALS['calls'] = array();
$p = ab_run( array( 'name' => 'test/delete-term', 'input' => array( 'id' => 30 ) ) );
ok( is_array( $p ) && true === $p['targets'][0]['assumed'] && ab_has( $p['warnings'], 'id is read as post #30 «Seite 30» (page)' ) && ab_has( $p['warnings'], 'target_post=0' ), 'U2 the preview says that it reads id as post #30, and the way out' );
$p = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ) );
ok( is_array( $p ) && ! isset( $p['targets'][0]['assumed'] ) && ! ab_has( $p['warnings'], 'id is read as post' ), 'U2 post_id is no assumption' );
$r = ab_run( array( 'name' => 'test/delete-term', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && array( 'delete-term' ) === $GLOBALS['calls'] && ab_has( $r['warnings'], 'reported success but nothing changed in post #30' ) && ab_has( $r['warnings'], 'if id names something else here' ) && ! ab_has( $r['warnings'], 'changed data kept elsewhere' ), 'U3 after the run the warning allows for an id that is no post' );
$r = ab_run( array( 'name' => 'test/order-update', 'input' => array( 'id' => 9999 ), 'target_post' => 30, 'apply' => true ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'reported success but nothing changed: test/order-update' ) && ! ab_has( $r['warnings'], 'if id names something else' ), 'U4 a post named on purpose keeps the plain warning' );

/* ============================ V) a post the call deletes */

ab_post( 60, array( 'post_content' => 'Bald weg', 'post_title' => 'Seite 60' ) );
ab_meta_add( 60, 'farbe', 'gruen' );
$GLOBALS['ab_caps']['edit_post:60'] = true;
$p = ab_run( array( 'name' => 'test/delete-post', 'input' => array( 'id' => 60 ) ) );
ok( is_array( $p ) && ab_has( $p['notes'], 'Annotated destructive by its plugin' ) && ab_has( $p['notes'], 'cannot bring that post back' ), 'V1 the preview of a destructive ability: an undo point cannot bring back a deleted post' );
$n = ab_points();
$r = ab_run( array( 'name' => 'test/delete-post', 'input' => array( 'id' => 60 ), 'apply' => true ) );
ok( is_array( $r ) && true === $r['readback'][0]['deleted'] && null === $r['undo_token'] && $n === ab_points(), 'V2 the post is gone: read back as deleted, no undo token that could not keep its promise' );
ok( ab_has( $r['warnings'], 'Post #60 («Seite 60», page) was deleted by the call' ) && ab_has( $r['warnings'], 'cannot bring a deleted post back' ) && ab_has( $r['notes'], 'cannot bring back a deleted post' ) && ! ab_has( $r['notes'], 'puts every stored copy' ), 'V3 the answer says so and promises no restore' );

/* ============================ W) a change staged as a draft */

$GLOBALS['ab_caps']['edit_post:90'] = true; // WordPress maps edit_post on a revision to its parent (map_meta_cap()).
$s30 = ab_dump( 30 );
$r   = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && array( 'revision' => 90, 'change' => 'added' ) === $r['readback'][0]['draft'] && true === $r['readback'][0]['unchanged'] && $s30 === ab_dump( 30 ), 'W1 the live page is unchanged, the draft the call staged is read back' );
ok( ab_has( $r['warnings'], 'staged as a draft, the autosave revision #90 of your account' ) && ab_has( $r['warnings'], 'Publish it in the editor' ) && ! ab_has( $r['warnings'], 'reported success but nothing changed' ), 'W2 the answer says where the change is and how to publish it, instead of calling it a silent failure' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && ab_has( $r['warnings'], 'wp_undo with undo_token deletes that draft again' ) && ab_has( $r['notes'], 'takes back the draft this call staged' ), 'W2 an undo point that takes the draft back, and the answer says so' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && true === $u['consumed'] && in_array( 90, $u['restored'], true ) && ! isset( $GLOBALS['db_posts'][90] ) && false === wp_get_post_autosave( 30, 7 ) && $s30 === ab_dump( 30 ), 'W2a wp_undo deletes the draft the call created; the live page stays as it was' );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$d90 = ab_meta_rows( 90, '_fake_layout' );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && array( 'revision' => 90, 'change' => 'modified' ) === $r['readback'][0]['draft'], 'W3 a draft that existed and changed is read back as modified' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && ab_has( $r['warnings'], 'puts that draft back as it was before the call' ), 'W3 its undo point puts that draft back, and the answer says so' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && true === $u['consumed'] && $d90 === ab_meta_rows( 90, '_fake_layout' ) && array( '[1]' ) === array_values( $d90 ) && $s30 === ab_dump( 30 ), 'W3a wp_undo puts the draft back as it was before that call, byte for byte' );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = '[im Editor]'; }
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && ! in_array( 90, $u['restored'], true ) && array( '[im Editor]' ) === array_values( ab_meta_rows( 90, '_fake_layout' ) ) && true === $u['consumed'] && isset( $u['rest_token'] ), 'W3b a draft changed in the editor since is not put back; the point narrows to it (rest_token) for a retry' );
ok( is_array( $u ) && 1 === count( $u['blocked'] ) && 90 === $u['blocked'][0]['ref'] && 'changed_since' === $u['blocked'][0]['reason'], 'W3b it is blocked as changed_since' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $u['rest_token'], 'force' => true ) );
ok( is_array( $u ) && in_array( 90, $u['restored'], true ) && array( '[1]' ) === array_values( ab_meta_rows( 90, '_fake_layout' ) ), 'W3b force puts it back over that change' );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && ! isset( $r['readback'][0]['draft'] ) && ab_has( $r['warnings'], 'reported success but nothing changed' ), 'W4 an untouched draft is no change' );
// Undo switched off: the draft is staged all the same, and the answer says there is no way back for it.
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
unset( $GLOBALS['db_posts'][90], $GLOBALS['ab_autosaves'][30] ); // no draft yet, as before W1
$GLOBALS['ab_toolstate']['wp_undo'] = false;
$n = ab_points();
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
unset( $GLOBALS['ab_toolstate']['wp_undo'] );
ok( is_array( $r ) && array( 'revision' => 90, 'change' => 'added' ) === $r['readback'][0]['draft'] && null === $r['undo_token'] && $n === ab_points(), 'W7 undo switched off: the draft is staged and read back, no undo token: ' . json_encode( is_array( $r ) ? $r['undo_token'] : ab_msg( $r ) ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'staged as a draft, the autosave revision #90' ) && ab_has( $r['warnings'], 'This call has no undo point for the draft: wp_undo cannot take it back' ) && ! ab_has( $r['warnings'], 'deletes that draft again' ) && ! ab_has( $r['notes'], 'takes back the draft this call staged' ), 'W7 the answer names the missing way back for the draft and promises none' );
// A draft the call created and someone changed since in the editor: not deleted without force.
wp_delete_post_revision( 90 );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = '[im Editor]'; }
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && isset( $GLOBALS['db_posts'][90] ) && 90 === $u['blocked'][0]['ref'] && 'changed_since' === $u['blocked'][0]['reason'] && isset( $u['rest_token'] ), 'W3c a created draft changed in the editor since: blocked as changed_since, not deleted' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $u['rest_token'], 'force' => true ) );
ok( is_array( $u ) && ! isset( $GLOBALS['db_posts'][90] ) && true === $u['consumed'], 'W3c force deletes it with that change' );
// The restore checks again right before it deletes: the plan was a moment ago.
$r    = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$item = AB_MCP_Undo_Builder::snapshot_of( 90, AB_MCP_Ability_Broker::locs_of( 90 ), '' );
$item['created'] = true;
foreach ( array_keys( ab_meta_rows( 90, '_fake_layout' ) ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = '[gleich danach]'; }
$res = AB_MCP_Undo_Builder::restore_planned( array( 'ref' => 90, 'item' => $item, 'after' => null, 'force' => false ) );
ok( is_wp_error( $res ) && 'ab_mcp_undo_post_changed' === $res->get_error_code() && isset( $GLOBALS['db_posts'][90] ), 'W3d a draft changed between plan and restore is not deleted' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'], 'force' => true ) );
// Saved again in an editor: a new title of the draft, a meta key it did not have.
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$GLOBALS['db_posts'][90]['post_title'] = 'Im Editor umbenannt';
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && isset( $GLOBALS['db_posts'][90] ) && 'changed_since' === $u['blocked'][0]['reason'] && array( 'post_fields' ) === $u['blocked'][0]['locs'], 'W3e a created draft whose title changed since: blocked (post_fields), not deleted' );
// The live page came back, so the point narrowed to the draft (rest_token).
AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) ( $u['rest_token'] ?? $r['undo_token'] ), 'force' => true ) );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ab_meta_add( 90, '_page_settings_im_editor', 'neu' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && isset( $GLOBALS['db_posts'][90] ) && 'changed_since' === $u['blocked'][0]['reason'] && array( 'meta:_page_settings_im_editor' ) === $u['blocked'][0]['locs'], 'W3e nor one that got a meta key since' );
$item = AB_MCP_Undo_Builder::snapshot_of( 90, array( 'meta:_fake_layout', 'post_content', 'post_content_filtered' ), '' );
$item['created'] = true;
$res = AB_MCP_Undo_Builder::restore_planned( array( 'ref' => 90, 'item' => $item, 'after' => null, 'force' => false ) );
ok( is_wp_error( $res ) && 'ab_mcp_undo_post_changed' === $res->get_error_code() && isset( $GLOBALS['db_posts'][90] ), 'W3e the restore checks the keys again right before it deletes' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) ( $u['rest_token'] ?? $r['undo_token'] ), 'force' => true ) );
ok( ! isset( $GLOBALS['db_posts'][90] ), 'W3e force deletes it all the same' );
// Two calls into the same draft, undone in turn: the second one moves the
// draft's time of saving (Elementor does, measured 03.10.2026), which its own
// undo point does not put back; the first undo point deletes the draft all the same.
$r1 = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$r2 = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
$GLOBALS['db_posts'][90]['post_modified_gmt'] = '2026-10-03 01:02:03';
$u2 = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r2['undo_token'] ) );
$u1 = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r1['undo_token'] ) );
ok( is_array( $u2 ) && is_array( $u1 ) && true === $u2['consumed'] && true === $u1['consumed'] && array() === $u1['blocked'] && ! isset( $GLOBALS['db_posts'][90] ) && $s30 === ab_dump( 30 ), 'W3f A, B, undo B, undo A: the draft is gone, the page as before' );
$item['row'] = 'nicht-hex';
ok( is_wp_error( AB_MCP_Undo_Builder::items_of( AB_MCP_Undo_Builder::data( 'ability', array( $item ) ), array() ) ), 'W3e a row that is no sha256 is a snapshot in no form this class writes' );
// A failure that staged a draft all the same: the error says so, with the token.
$r = ab_run( array( 'name' => 'test/stage-fail', 'input' => array( 'id' => 30 ), 'apply' => true ) );
preg_match( '/The undo token is (undo_[0-9a-f]+)/', ab_msg( $r ), $mt );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'Saved the draft, then gave up' ) && false !== strpos( ab_msg( $r ), 'Post #30: it staged a change in a draft all the same, the autosave revision #90 of your account. wp_undo with undo_token deletes that draft again.' ) && isset( $mt[1] ), 'W4a an error that staged a draft all the same names the draft and the undo token: ' . ab_msg( $r ) );
$u = isset( $mt[1] ) ? AB_MCP_Tools_Undo_Core::undo( array( 'token' => $mt[1] ) ) : null;
ok( is_array( $u ) && ! isset( $GLOBALS['db_posts'][90] ) && $s30 === ab_dump( 30 ), 'W4a and wp_undo with it deletes the draft' );
// A draft staged next to a change of the live page.
$r = ab_run( array( 'name' => 'test/stage-and-change', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_array( $r ) && false === $r['readback'][0]['unchanged'] && ab_has( $r['warnings'], 'Post #30: besides the live page, the call staged a change in a draft, the autosave revision #90 of your account; publishing that draft brings it live too. wp_undo with undo_token deletes that draft again.' ), 'W4b a draft staged next to a live change is named too' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && ! isset( $GLOBALS['db_posts'][90] ) && $s30 === ab_dump( 30 ), 'W4b wp_undo takes back both: the live change and the draft' );
// The draft the call created was published or dropped meanwhile: nothing to do.
wp_delete_post_revision( 90 );
$r = ab_run( array( 'name' => 'test/stage-draft', 'input' => array( 'id' => 30 ), 'apply' => true ) );
wp_delete_post_revision( 90 );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && true === $u['consumed'] && array() === $u['blocked'] && array() === $u['failed'], 'W5 a created draft that is gone by now: nothing to delete, the undo is complete' );
// A point whose created post is no autosave revision (any more) deletes nothing.
$GLOBALS['ab_caps']['edit_post:92'] = true;
ab_post( 92, array( 'post_type' => 'page', 'post_title' => 'Keine Revision' ) );
$item            = AB_MCP_Undo_Builder::snapshot_of( 92, AB_MCP_Ability_Broker::locs_of( 92 ), '' );
$item['created'] = true;
$tok             = AB_MCP_Undo_Builder::record( 'wp_run_ability', 'ability', array( $item ) );
$u               = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tok ) );
ok( is_array( $u ) && isset( $GLOBALS['db_posts'][92] ) && 'not_a_draft' === $u['blocked'][0]['reason'] && array() === $u['restored'], 'W6 "created" on a post that is no autosave revision: blocked, never deleted' );
$res = AB_MCP_Undo_Builder::restore_planned( array( 'ref' => 92, 'item' => $item, 'after' => null, 'force' => true ) );
ok( is_wp_error( $res ) && 'ab_mcp_undo_not_a_draft' === $res->get_error_code() && isset( $GLOBALS['db_posts'][92] ), 'W6 and the restore itself checks it again, with force too' );
// An ordinary revision is no draft either: WordPress' history of the page.
$GLOBALS['ab_caps']['edit_post:93'] = true;
ab_post( 93, array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => 30, 'post_name' => '30-revision-v1' ) );
$rev            = AB_MCP_Undo_Builder::snapshot_of( 93, AB_MCP_Ability_Broker::locs_of( 93 ), '' );
$rev['created'] = true;
$u              = AB_MCP_Tools_Undo_Core::undo( array( 'token' => AB_MCP_Undo_Builder::record( 'wp_run_ability', 'ability', array( $rev ) ) ) );
ok( is_array( $u ) && isset( $GLOBALS['db_posts'][93] ) && 'not_a_draft' === $u['blocked'][0]['reason'], 'W6 nor on a revision that is no autosave: blocked, never deleted' );
unset( $GLOBALS['db_posts'][93] );
$bad            = $item;
$bad['created'] = 'yes';
ok( is_wp_error( AB_MCP_Undo_Builder::items_of( AB_MCP_Undo_Builder::data( 'ability', array( $bad ) ), array() ) ), 'W6 "created" other than true is a snapshot in no form this class writes' );
unset( $GLOBALS['db_posts'][92] );

/* ================== EL) Elementor's abilities: draft, undo, publish, text copy */

// A published V4 page as Elementor leaves it after a view: its data, its
// text copy in post_content, the asset list of the page (a cache).
$GLOBALS['ab_caps']['edit_post:34'] = true;
$GLOBALS['ab_caps']['edit_post:91'] = true; // the autosave revision: map_meta_cap() asks for its parent
ab_post( 34, array( 'post_content' => '<h1>Willkommen</h1>', 'post_title' => 'Elementor-Seite' ) );
ab_meta_add( 34, '_elementor_edit_mode', 'builder' );
ab_meta_add( 34, '_elementor_data', '[{"id":"f00d002","elType":"widget","widgetType":"e-heading","settings":{"title":"Willkommen"}}]' );
ab_meta_add( 34, '_elementor_page_assets', 'a:1:{s:6:"styles";a:0:{}}' );
$e34   = ab_dump( 34 );
$el_op = static function ( array $settings, $eid = 'f00d002' ) {
	return array( 'name' => 'elementor/manage-elements', 'input' => array( 'post_id' => 34, 'operations' => array( array( 'action' => 'update', 'element_id' => $eid, 'settings' => $settings ) ) ), 'apply' => true );
};
$r = ab_run( $el_op( array( 'title' => 'Neu' ) ) );
ok( is_array( $r ) && array() === $r['readback'][0]['changed'] && true === $r['readback'][0]['unchanged'] && array( 'revision' => 91, 'change' => 'added' ) === $r['readback'][0]['draft'], 'EL1 the draft Elementor staged is read back as the only change: its asset list and its class index on the live page are no change of it' );
ok( array() === ab_meta_rows( 34, '_elementor_page_assets' ) && array( 'e-466a7731' ) === array_values( ab_meta_rows( 34, '_elementor_used_global_class_preview' ) ) && $e34 === ab_dump( 34 ), 'EL1 (control) the stand-in did what Elementor does: page assets gone, class index written on the live page; the page otherwise as before' );
ok( ab_has( $r['warnings'], 'Post #34: the change was staged as a draft, the autosave revision #91 of your account, and the live page is unchanged. Publish it in the Elementor editor, or with the ability elementor/publish-document.' ) && ! ab_has( $r['warnings'], 'reported success but nothing changed' ), 'EL2 the answer says it is a draft and how to publish it' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ) && ab_has( $r['warnings'], 'wp_undo with undo_token deletes that draft again' ), 'EL3 its undo point takes the draft back' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && true === $u['consumed'] && false === wp_get_post_autosave( 34, 7 ) && $e34 === ab_dump( 34 ), 'EL4 wp_undo deletes the draft; the live page as before' );
$p = ab_run( array( 'name' => 'elementor/publish-document', 'input' => array( 'post_id' => 34 ), 'apply' => true ) );
ok( is_array( $p ) && $e34 === ab_dump( 34 ) && false !== strpos( (string) ab_first_meta( 34, '_elementor_data' ), 'Willkommen' ), 'EL5 so the next publish-document brings nothing live' );
ok( ab_has( $p['notes'], 'Post #34: nothing to publish' ) && ! ab_has( $p['warnings'], 'reported success but nothing changed' ) && null === $p['undo_token'], 'EL5 and says there was nothing to publish, not that it failed silently' );

// Staged, then published: the text copy in post_content follows.
$GLOBALS['ab_deleted_revisions'] = array();
$r = ab_run( $el_op( array( 'title' => 'Neu' ) ) );
$p = ab_run( array( 'name' => 'elementor/publish-document', 'input' => array( 'post_id' => 34 ), 'apply' => true ) );
$locs = array();
foreach ( is_array( $p ) ? $p['readback'][0]['changed'] : array() as $c ) { $locs[] = $c['loc']; }
ok( is_array( $p ) && array( 'meta:_elementor_data', 'post_content' ) === $locs && array( 91 ) === $GLOBALS['ab_deleted_revisions'], 'EL6 publish-document: the draft promoted and deleted, the data and the text copy changed' );
ok( is_array( $p ) && AB_MCP_Undo::valid_token( $p['undo_token'] ) && ab_has( $p['notes'], 'the draft elementor/publish-document published does not come back' ), 'EL6a the answer says wp_undo puts the live page back but not the draft Elementor deleted' );
ok( '<h1>Neu</h1>' === $GLOBALS['db_posts'][34]['post_content'] && array( 34 ) === \Elementor\Plugin::$instance->db->calls, 'EL7 the text copy in post_content written with Elementor\'s save_plain_text(), so the site search finds the new title' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $p['undo_token'] ) );
ok( is_array( $u ) && true === $u['consumed'] && '<h1>Willkommen</h1>' === $GLOBALS['db_posts'][34]['post_content'] && false !== strpos( (string) ab_first_meta( 34, '_elementor_data' ), 'Willkommen' ), 'EL8 wp_undo puts the data and the text copy back' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) ); // the draft is gone: nothing left to do
\Elementor\Plugin::$instance->db->fail = true;
$r = ab_run( $el_op( array( 'title' => 'Neu' ) ) );
$p = ab_run( array( 'name' => 'elementor/publish-document', 'input' => array( 'post_id' => 34 ), 'apply' => true ) );
ok( is_array( $p ) && ab_has( $p['warnings'], 'Post #34: Elementor published the change, but the text copy of the page in post_content could not be brought in step' ), 'EL9 Elementor fails to write the text copy: the answer says what stays stale' );
\Elementor\Plugin::$instance->db->fail = false;
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $p['undo_token'] ) );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( $e34 === ab_dump( 34 ), 'EL9 and wp_undo puts the page back' );

// What Elementor's own result says: a property it skipped, an operation that failed.
$r = ab_run( array( 'name' => 'elementor/manage-elements', 'input' => array( 'post_id' => 34, 'operations' => array( array( 'action' => 'update', 'element_id' => 'f00d002', 'settings' => array( 'title' => 'Neu', 'farbe' => 'rot' ) ), array( 'action' => 'update', 'element_id' => 'weg0001', 'settings' => array( 'title' => 'x' ) ) ) ), 'apply' => true ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'elementor/manage-elements answered without an error, but its result lists 1 of 2 operations as failed: #1 update: «Element not found.» (elementor_not_found)' ), 'EL10 an operation that failed in Elementor\'s result is named in the warnings' );
ok( is_array( $r ) && ab_has( $r['warnings'], 'elementor/manage-elements reported in its result: «[f00d002] Property "farbe" is not supported on element type "e-heading" and was skipped.»' ), 'EL11 a property Elementor skipped is passed on, quoted' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( $e34 === ab_dump( 34 ) && false === wp_get_post_autosave( 34, 7 ), 'EL11 wp_undo leaves no draft' );
$r = ab_run( $el_op( array( 'title' => 'Neu' ) ) );
ok( is_array( $r ) && ! ab_has( $r['warnings'], 'reported in its result' ) && ! ab_has( $r['warnings'], 'as failed' ), 'EL12 a clean result adds no such warning' );
ok( is_array( $r ) && ! ab_has( $r['notes'], 'does not come back' ), 'EL12 the note on publish-document stays with publish-document' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
// Elementor cannot save the page: status error and save_error, the operations ok.
$GLOBALS['ab_el_save_error'] = 'Could not save the document.';
$n = ab_points();
$r = ab_run( $el_op( array( 'title' => 'Neu' ) ) );
unset( $GLOBALS['ab_el_save_error'] );
ok( is_wp_error( $r ) && 'ab_mcp_ability_failed' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'elementor/manage-elements answered without an error of WordPress, but its result reports one: «Could not save the document.»' ), 'EL14 a save_error in Elementor\'s result is an error, with Elementor\'s words: ' . ab_msg( $r ) );
ok( false !== strpos( ab_msg( $r ), 'Nothing in post #34 changed' ) && false === strpos( ab_msg( $r ), 'reported success' ) && $n === ab_points() && $e34 === ab_dump( 34 ) && false === wp_get_post_autosave( 34, 7 ), 'EL14 nothing changed: it says so, no undo point, no draft' );
// An undo point Pro 4.7.0 recorded: it holds the asset list, which a restore empties.
ab_meta_add( 34, '_elementor_page_assets', 'a:1:{s:6:"styles";a:0:{}}' );
$old = AB_MCP_Undo_Builder::snapshot_of( 34, array( 'meta:_elementor_data', 'meta:_elementor_edit_mode', 'meta:_elementor_page_assets', 'post_content', 'post_content_filtered' ), '' );
$tok = AB_MCP_Undo_Builder::record( 'wp_run_ability', 'ability', array( $old ) );
update_post_meta( 34, '_elementor_data', '[{"id":"f00d002","elType":"widget","widgetType":"e-heading","settings":{"title":"Neu"}}]' );
delete_post_meta( 34, '_elementor_page_assets' );
AB_MCP_Undo_Builder::mark_written( $tok, 34, AB_MCP_Undo_Builder::state_hashes( 34, array_keys( $old['locs'] ) ) );
ab_meta_add( 34, '_elementor_page_assets', 'a:1:{s:7:"scripts";a:0:{}}' ); // a visit built it again
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tok ) );
ok( is_array( $u ) && true === $u['consumed'] && array() === $u['failed'] && array() === $u['blocked'] && $e34 === ab_dump( 34 ) && array() === ab_meta_rows( 34, '_elementor_page_assets' ), 'EL13 an undo point of Pro 4.7.0 with the asset list: restored, the list emptied, no mismatch and no changed_since' );

/* ========================== SV) the copy visitors see stayed as it was */

ab_fake_page( 36, ab_fake_elements() );
$GLOBALS['ab_caps']['edit_post:36'] = true;
$r = ab_run( array( 'name' => 'test/hidden-copy', 'input' => array( 'id' => 36 ), 'apply' => true ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'Post #36: the call changed post_content, but not meta:_fake_layout, the copy Fake Builder shows to visitors, so the page looks as before' ), 'SV1 only a copy visitors do not see changed: the answer says the page looks as before' );
ok( AB_MCP_Undo::valid_token( $r['undo_token'] ), 'SV1 the change keeps its undo point' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 36, 'key' => '_fake_layout', 'value' => '[]' ), 'apply' => true ) );
ok( is_array( $r ) && ! ab_has( $r['warnings'], 'shows to visitors' ), 'SV2 the copy visitors see changed: no such warning' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
$r = ab_run( array( 'name' => 'test/content-fail', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false === strpos( ab_msg( $r ), 'shows to visitors' ), 'SV3 a post no page builder reads: no such warning' );
preg_match( '/undo token (undo_[0-9a-f]+)/', ab_msg( $r ), $mt );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $mt[1] ) );

// A builder like SeedProd: visitors see post_content, its editor loads
// post_content_filtered, which is no copy while it is empty
// (AB_MCP_Builders::existing_copies()).
class AB_Test_Two_Copy_Adapter extends AB_MCP_Builder_Adapter {
	public function id(): string { return 'twocopy'; }
	public function name(): string { return 'Two Copy Builder'; }
	public function detect( $post ): bool { return '' !== (string) get_post_meta( (int) $post->ID, '_two_copy', true ); }
	public function is_active(): bool { return true; }
	public function copies( $post ): array {
		return AB_MCP_Builders::existing_copies( $post, array( array( 'loc' => 'post_content', 'shown' => true, 'role' => 'copy' ), array( 'loc' => 'post_content_filtered', 'shown' => false, 'role' => 'source' ) ), array() );
	}
	public function outline( $post, array $o ): array { return array(); }
}
add_filter( 'ab_mcp_builder_adapters', static function ( $list ) { $list[] = new AB_Test_Two_Copy_Adapter(); return $list; }, 20 );
AB_MCP_Builders::reset();
ab_post( 37, array( 'post_content' => '<h1>Sichtbar</h1>', 'post_content_filtered' => '' ) );
ab_meta_add( 37, '_two_copy', '1' );
$GLOBALS['ab_caps']['edit_post:37'] = true;
$r = ab_run( array( 'name' => 'test/set-filtered', 'input' => array( 'id' => 37 ), 'apply' => true ) );
ok( is_array( $r ) && 'twocopy' === $r['targets'][0]['builder'] && ab_has( $r['warnings'], 'Post #37: the call changed post_content_filtered, but not post_content, the copy Two Copy Builder shows to visitors' ), 'SV4 a hidden copy the call wrote for the first time counts too' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );

/* ========================== SC) scripts the call took out */

ab_post( 35, array( 'post_title' => 'Mit Skript' ) );
$GLOBALS['ab_caps']['edit_post:35'] = true;
ab_meta_add( 35, 'html', '<div class="roh">Rohes HTML</div><script>window.mzRoh=1;</script>' );
$s35 = ab_dump( 35 );
$r   = ab_run( array( 'name' => 'test/kses-meta', 'input' => array( 'id' => 35 ), 'apply' => true ) );
ok( is_array( $r ) && ab_has( $r['warnings'], 'Post #35: the call removed script elements from meta:html (1 before, 0 now)' ) && ab_has( $r['warnings'], 'can leave their code on the page as visible text' ), 'SC1 a script the call took out: the answer says so, with the copy and the counts' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && $s35 === ab_dump( 35 ), 'SC2 wp_undo puts the script back' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 35, 'key' => 'html', 'value' => '<div>Neu</div><SCRIPT>window.mzRoh=2;</SCRIPT>' ), 'apply' => true ) );
ok( is_array( $r ) && ! ab_has( $r['warnings'], 'removed script elements' ), 'SC3 a change that keeps the script: no such warning' );
AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );

/* ============================ X) titles only of posts the account may edit */

ab_post( 40, array( 'post_title' => 'Geheimer Entwurf Fusion XY', 'post_status' => 'private', 'post_type' => 'post' ) );
$GLOBALS['ab_caps']['edit_post:40'] = false;
$p1 = ab_run( array( 'name' => 'test/read-info', 'input' => array( 'fields' => array() ), 'target_post' => 40, 'apply' => false ) );
$p2 = ab_run( array( 'name' => 'test/contra', 'input' => array( 'id' => 40 ) ) );
$p3 = ab_run( array( 'name' => 'test/contra', 'input' => array( 'id' => 9998 ) ) );
$j  = json_encode( array( $p1, $p2 ), JSON_UNESCAPED_UNICODE );
ok( false === strpos( $j, 'Geheimer' ) && ! isset( $p1['targets'][0]['title'] ) && ! isset( $p2['targets'][0]['type'] ), 'X1 the preview tells neither title nor type of a post the account may not edit, for a read-only and a writing ability' );
ok( str_replace( '#40', '#N', $p2['targets'][0]['reason'] ) === str_replace( '#9998', '#N', $p3['targets'][0]['reason'] ), 'X2 nor whether that post exists: the same answer as for a number without a post' );

/* ============================ Y) the undo point cannot be recorded */

$GLOBALS['ab_opts'][ AB_MCP_Undo::LOCK ] = (string) time();
$GLOBALS['calls'] = array();
$n = ab_points();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_undo_failed' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'Without an undo point nothing ran' ), 'Y1 the undo index is busy: the run is refused, with the reason' );
ok( array() === $GLOBALS['calls'] && $before === ab_dump( 30 ) && $n === ab_points(), 'Y2 the ability was not called, the post is unchanged, no point' );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::LOCK ] );

/* ===================== AD) the MCP adapter's abilities are not passed through */

$GLOBALS['calls'] = array();
$all = AB_MCP_Tools_Abilities::list_abilities( array( 'limit' => 500 ) );
$adapter = array();
foreach ( $all['abilities'] as $row ) { if ( 0 === strpos( $row['name'], 'mcp-adapter/' ) ) { $adapter[] = $row['name']; } }
ok( array() === $adapter && ! in_array( 'mcp-adapter', $all['providers'], true ) && 32 === $all['total'], 'AD1 the listing shows none of the MCP adapter\'s abilities, though they are registered: ' . implode( ', ', $adapter ) );
$s = AB_MCP_Tools_Abilities::list_abilities( array( 'namespace' => 'mcp-adapter' ) );
$q = AB_MCP_Tools_Abilities::list_abilities( array( 'search' => 'execute' ) );
ok( 0 === $s['count'] && 0 === $q['count'] && ! isset( $s['hidden'] ), 'AD1 neither by namespace nor by search, and they do not count as hidden' );
$one = AB_MCP_Tools_Abilities::list_abilities( array( 'name' => 'mcp-adapter/execute-ability' ) );
ok( is_wp_error( $one ) && 'ab_mcp_ability_runner' === $one->get_error_code() && false !== strpos( ab_msg( $one ), 'belongs to the MCP adapter' ) && false !== strpos( ab_msg( $one ), 'wp_run_ability with its name and its parameters' ), 'AD2 asked by name: not passed through, and the way: the ability itself through wp_run_ability' );
$before = ab_dump( 30 );
$n      = ab_points();
ab_off( array( 'test/set-meta' ) );
$r = ab_run( array( 'name' => 'mcp-adapter/execute-ability', 'input' => array( 'ability_name' => 'test/set-meta', 'parameters' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_runner' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'nothing ran' ) && false !== strpos( ab_msg( $r ), 'wp_run_ability with name "test/set-meta" and its parameters as input' ), 'AD3 execute-ability with an ability that is not approved: refused, naming the ability to call directly' );
ok( array() === $GLOBALS['calls'] && $before === ab_dump( 30 ) && $n === ab_points(), 'AD3 neither the adapter nor the ability ran, the post is unchanged, no undo point' );
ab_off( array() );
$r = ab_run( array( 'name' => 'mcp-adapter/execute-ability', 'input' => array( 'ability_name' => 'test/set-meta', 'parameters' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ) ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_runner' === $r->get_error_code() && array() === $GLOBALS['calls'] && $before === ab_dump( 30 ), 'AD4 with that ability approved as well: not passed through' );
$r = ab_run( array( 'name' => 'MCP-Adapter/Execute-Ability', 'input' => '{"ability_name":"test/read-info","parameters":{}}' ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_runner' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'name "test/read-info"' ) && array() === $GLOBALS['calls'], 'AD5 the preview too, the name in capitals, the input as JSON text' );
$r1 = ab_run( array( 'name' => 'mcp-adapter/discover-abilities' ) );
$r2 = ab_run( array( 'name' => 'mcp-adapter/get-ability-info', 'input' => array( 'ability_name' => 'test/set-meta' ) ) );
ok( is_wp_error( $r1 ) && is_wp_error( $r2 ) && 'ab_mcp_ability_runner' === $r1->get_error_code() && 'ab_mcp_ability_runner' === $r2->get_error_code() && array() === $GLOBALS['calls'], 'AD6 its companions, read-only by their annotations, are not passed through either' );
$r = ab_run( array( 'name' => 'mcp-adapter/execute-ability', 'input' => array( 'ability_name' => 'mcp-adapter/execute-ability', 'parameters' => array() ), 'apply' => true ) );
ok( is_wp_error( $r ) && false === strpos( ab_msg( $r ), 'with name "mcp-adapter' ) && false !== strpos( ab_msg( $r ), 'wp_list_abilities lists the abilities' ), 'AD7 an ability_name that is the adapter again: the general way, not that name' );
$r = ab_run( array( 'name' => 'mcp-adapter/execute-ability', 'input' => array( 'ability_name' => 'Keine <b>Ability</b>', 'parameters' => array() ), 'apply' => true ) );
ok( is_wp_error( $r ) && false === strpos( ab_msg( $r ), 'Keine' ) && false !== strpos( ab_msg( $r ), 'wp_list_abilities lists the abilities' ), 'AD7 an ability_name that is no ability name is not repeated' );
ok( null === AB_MCP_Ability_Broker::get( 'mcp-adapter/execute-ability' ) && null !== wp_get_ability( 'mcp-adapter/execute-ability' ), 'AD8 get() knows no such ability, though WordPress has it registered' );
$st = AB_MCP_Ability_Switches::state();
ok( ! isset( $st['groups']['mcp-adapter'] ) && isset( $st['groups']['test'] ), 'AD9 the settings screen offers no switch for them' );

/* ===================== SF) an input schema of several forms (oneOf, anyOf, allOf) */

// WooCommerce 11.1.2's product-update, narrower: one form per product type, each with id.
$woo_schema = array(
	'type'  => 'object',
	'oneOf' => array(
		array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array( 'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
		array( 'type' => 'object', 'required' => array( 'id', 'product_type_alias' ), 'properties' => array( 'id' => array( 'type' => 'integer' ), 'product_type_alias' => array( 'type' => 'string' ), 'regular_price' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
	),
);
ok( 'object, one of 2 forms: id* (integer), name (string), product_type_alias (string), regular_price (string)' === AB_MCP_Ability_Broker::schema_summary( $woo_schema ), 'SF1 the short form names the fields of every form, * where every form requires it: ' . AB_MCP_Ability_Broker::schema_summary( $woo_schema ) );
$woo = new WP_Ability( 'test/one-of', array( 'label' => 'One of', 'description' => 'Forms', 'category' => 'test', 'input_schema' => $woo_schema, 'execute_callback' => static function () { return true; }, 'permission_callback' => static function () { return true; }, 'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true ) ) );
$d   = AB_MCP_Ability_Broker::describe( $woo );
ok( 0 === strpos( $d['way_back'], 'undo point of the posts named by id' ), 'SF2 the listing finds the post key in the forms: a run has a way back, not none: ' . $d['way_back'] );
$any = array( 'type' => 'object', 'anyOf' => array( array( 'required' => array( 'post_id', 'title' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ) ) ), array( 'required' => array( 'post_id' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'excerpt' => array( 'type' => 'string' ) ) ) ) );
ok( 'object, one of 2 forms: post_id* (integer), title (string), excerpt (string)' === AB_MCP_Ability_Broker::schema_summary( $any ), 'SF3 anyOf: required only what every form requires' );
$all = array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string' ) ), 'allOf' => array( array( 'required' => array( 'page_id' ), 'properties' => array( 'page_id' => array( 'type' => 'integer' ) ) ), array( 'properties' => array( 'note' => array( 'type' => 'string', 'required' => true ) ) ) ) );
ok( 'object: mode (string), page_id* (integer), note* (string)' === AB_MCP_Ability_Broker::schema_summary( $all ), 'SF4 allOf: every part\'s fields, required where a part requires them: ' . AB_MCP_Ability_Broker::schema_summary( $all ) );
ok( 'object' === AB_MCP_Ability_Broker::schema_summary( array( 'type' => 'object', 'oneOf' => array( array( 'type' => 'object' ), array( 'type' => 'object' ) ) ) ), 'SF5 forms without fields: the type alone' );
ok( 'object: id* (integer)' === AB_MCP_Ability_Broker::schema_summary( array( 'type' => 'object', 'oneOf' => array( array( 'required' => array( 'id' ), 'properties' => array( 'id' => array( 'type' => 'integer' ) ) ), 'kaputt' ) ) ), 'SF5 a form that is no schema is no form: it neither counts nor takes the required field away' );

/* ===================== RE) an error the ability reports in its result */

$GLOBALS['calls'] = array();
$n                = ab_points();
$before           = ab_dump( 30 );
// Rank Math 1.0.279's set-homepage-seo without fields (measured 02.10.2026).
$GLOBALS['silent_result'] = array( 'error' => array( 'code' => 'no_fields', 'message' => 'No fields provided. Pass at least one of: title, description, focus_keyword.' ) );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_failed' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'test/silent answered without an error of WordPress, but its result reports one: «No fields provided. Pass at least one of: title, description, focus_keyword.» (no_fields).' ), 'RE1 a field error in the result is an error, with the plugin\'s words and code: ' . ab_msg( $r ) );
ok( false !== strpos( ab_msg( $r ), 'Nothing in post #30 changed' ) && false === strpos( ab_msg( $r ), 'reported success' ) && $n === ab_points() && $before === ab_dump( 30 ), 'RE1 nothing changed: it says so, no «reported success», no undo point' );
ok( 0 === strpos( ab_msg( $r ), 'test/silent answered without an error of WordPress' ), 'RE1 the sentence stands first, not behind «reported an error»' );
// The MCP adapter's own answer: success false with an error text.
$GLOBALS['silent_result'] = array( 'success' => false, 'error' => "Ability 'x/y' not found" );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), "its result reports one: «Ability 'x/y' not found»." ), 'RE2 success false with an error text' );
$GLOBALS['silent_result'] = array( 'success' => false );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'its result reports one: (no message).' ), 'RE3 success false without a word: an error all the same' );
$GLOBALS['silent_result'] = array( 'error' => true );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), 'its result reports one: (no message).' ), 'RE3 error true: an error, and no «1» for its message' );
$GLOBALS['silent_result'] = array( 'success' => false, 'message' => 'Quota exceeded' );
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), '«Quota exceeded»' ), 'RE4 its message where the error field has none' );
// An error after a change: the change is named and has its undo point.
$GLOBALS['silent_result'] = array( 'error' => 'Saved half of it' );
$GLOBALS['silent_write']  = 'halb';
$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
preg_match( '/undo token (undo_[0-9a-f]+)/', ab_msg( $r ), $mt );
ok( is_wp_error( $r ) && false !== strpos( ab_msg( $r ), '«Saved half of it»' ) && false !== strpos( ab_msg( $r ), 'It changed the post all the same (#30: meta:farbe)' ) && isset( $mt[1] ), 'RE5 an error after a change: the change is named, with the undo token' );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $mt[1] ) );
ok( is_array( $u ) && $before === ab_dump( 30 ), 'RE5 and wp_undo puts the post back' );
unset( $GLOBALS['silent_write'] );
// Not an error: an empty error field, success true.
foreach ( array( array( 'error' => null, 'ok' => 1 ), array( 'error' => '', 'ok' => 1 ), array( 'error' => false ), array( 'success' => true, 'error' => null ) ) as $i => $res ) {
	$GLOBALS['silent_result'] = $res;
	$r = ab_run( array( 'name' => 'test/silent', 'input' => array( 'id' => 30 ), 'apply' => true ) );
	ok( is_array( $r ) && $res === $r['result'], "RE6 an empty error field or success true is no error ($i)" );
}
unset( $GLOBALS['silent_result'] );
// A read-only ability too (Rank Math's get-post-seo-meta answers { error: "…" }).
$GLOBALS['read_result'] = array( 'error' => 'No post found with the given ID, or the post status is not supported.' );
$r = ab_run( array( 'name' => 'test/read-info', 'input' => array( 'fields' => array() ) ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_failed' === $r->get_error_code() && false !== strpos( ab_msg( $r ), 'test/read-info answered without an error of WordPress, but its result reports one: «No post found' ), 'RE7 a read-only ability\'s result error is an error too' );
unset( $GLOBALS['read_result'] );

/* ===================== CG) code an ability would switch on stays off */

// A plugin that put itself first in the list, as some do to load early.
update_option( 'active_plugins', array( 0 => 'zz-first/zz.php', 2 => 'akismet/akismet.php' ) );
update_option( 'stylesheet', 'twentytwentysix' );
update_option( 'template', 'twentytwentysix' );
update_option( 'current_theme', 'Twenty Twenty-Six' );
update_option( 'theme_switched', '' );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-2' ) ) );
update_option( 'theme_mods_twentytwentysix', array( 'nav_menu_locations' => array( 'primary' => 10 ) ) );
update_option( 'theme_mods_twentytwentyfive', array( 'nav_menu_locations' => array( 'primary' => 20 ) ) );
$GLOBALS['ab_autoload']['theme_mods_twentytwentysix']  = 'on';
$GLOBALS['ab_autoload']['theme_mods_twentytwentyfive'] = 'off';
$plugins_before = get_option( 'active_plugins' );
$n              = ab_points();

// Child process (CG10): a switch_theme callback that ends the request with
// exit. The finally that closes the gate does not run, its shutdown does;
// what this one prints is read after it.
// CG11: and a shutdown callback of the ability that exits as well; what this
// one prints at priority 5 of the shutdown action is read after it.
if ( in_array( isset( $argv[3] ) ? $argv[3] : '', array( 'gate_exit', 'gate_exit_wp' ), true ) ) {
	add_action(
		'switch_theme',
		static function () {
			global $argv;
			$dump = static function () {
				echo 'GATE_EXIT ' . json_encode( array( 'mods' => get_option( 'theme_mods_twentytwentysix' ), 'autoload' => $GLOBALS['ab_autoload'], 'stylesheet' => get_option( 'stylesheet' ) ) ) . "\n";
			};
			if ( 'gate_exit_wp' === $argv[3] ) {
				add_action( 'shutdown', $dump, 5, 0 );
				add_action( 'shutdown', static function () { exit( 0 ); }, 10, 0 );
			} else {
				register_shutdown_function( $dump );
			}
			exit( 0 );
		}
	);
	ab_run( array( 'name' => 'test/code-switch-theme', 'input' => array( 'stylesheet' => 'twentytwentyfive' ), 'apply' => true ) );
	echo "NOT ENDED\n";
	exit( 1 );
}

$r = ab_run( array( 'name' => 'test/code-activate-plugin', 'input' => array( 'file' => 'hello/hello.php' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 'ab_mcp_ability_failed' === $r->get_error_code() && 0 === strpos( ab_msg( $r ), 'test/code-activate-plugin tried to switch on the plugin hello/hello.php, and AlphaBridge kept it switched off' ), 'CG1 an ability that activates a plugin: an error that names the plugin: ' . ab_msg( $r ) );
ok( false !== strpos( ab_msg( $r ), 'Switch it on with wp_activate_plugin, which checks the site' ) && false === strpos( ab_msg( $r ), 'wp_activate_theme' ), 'CG1 it points to wp_activate_plugin, and only to it' );
ok( $plugins_before === get_option( 'active_plugins' ), 'CG1 the list of active plugins is as it was, its order and keys too (WordPress would have sorted it; measured on 7.1.2: a list with a gap was written anew)' );
ok( 'hello/hello.php' === get_option( 'activation_routine_ran' ), 'CG1 what the activation routine wrote stays, as the answer says' );
ok( $n === ab_points(), 'CG1 no undo point: there is nothing to undo' );
update_option( 'active_plugins', array( 'akismet/akismet.php', 'hello/hello.php', 'zz-first/zz.php' ) );
ok( in_array( 'hello/hello.php', (array) get_option( 'active_plugins' ), true ), 'CG1 counter-check: outside a call the list takes the plugin, the filters are gone' );
update_option( 'active_plugins', $plugins_before );

$r = ab_run( array( 'name' => 'test/code-deactivate-plugin', 'input' => array( 'file' => 'zz-first/zz.php' ), 'apply' => true ) );
ok( is_array( $r ) && array( 'akismet/akismet.php' ) === get_option( 'active_plugins' ), 'CG2 an ability that deactivates a plugin runs: deactivating has no test requests either (wp_deactivate_plugin)' );
update_option( 'active_plugins', $plugins_before );

// Out and in at once: what goes out goes out, what comes in stays out.
AB_MCP_Ability_Code_Gate::open();
update_option( 'active_plugins', array( 'akismet/akismet.php', 'new/new.php' ) );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( array( 2 => 'akismet/akismet.php' ) === get_option( 'active_plugins' ) && array( 'new/new.php' => false ) === $kept['plugins'] && '' === $kept['theme'], 'CG3 a write that takes one out and adds one: out goes, the new one stays out and is named' );
update_option( 'active_plugins', $plugins_before );

// The network's list (plugin => time).
AB_MCP_Ability_Code_Gate::open();
$net = AB_MCP_Ability_Code_Gate::keep_network_plugins( array( 'a/a.php' => 1, 'b/b.php' => 2 ), array( 'a/a.php' => 1, 'c/c.php' => 3 ) );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( array( 'a/a.php' => 1 ) === $net && array( 'b/b.php' => true ) === $kept['plugins'], 'CG4 network-wide: the new plugin stays out, the one taken out goes, the rest keeps its time' );
AB_MCP_Ability_Code_Gate::open();
$net = AB_MCP_Ability_Code_Gate::keep_network_plugins( array( 'a/a.php' => 1 ), array( 'a/a.php' => 1, 'c/c.php' => 3 ) );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( array( 'a/a.php' => 1 ) === $net && array() === $kept['plugins'], 'CG4 network-wide deactivation passes and names nothing' );

$theme_before = array();
foreach ( array( 'stylesheet', 'template', 'current_theme', 'theme_switched', 'theme_switch_menu_locations', 'sidebars_widgets' ) as $o ) {
	$theme_before[ $o ] = get_option( $o, 'ABSENT' );
}
$r = ab_run( array( 'name' => 'test/code-switch-theme', 'input' => array( 'stylesheet' => 'twentytwentyfive' ), 'apply' => true ) );
ok( is_wp_error( $r ) && 0 === strpos( ab_msg( $r ), 'test/code-switch-theme tried to switch on the theme twentytwentyfive, and AlphaBridge kept it switched off' ) && false !== strpos( ab_msg( $r ), 'Switch it on with wp_activate_theme' ), 'CG5 an ability that switches the theme: an error that names it and wp_activate_theme: ' . ab_msg( $r ) );
$theme_after = array();
foreach ( array_keys( $theme_before ) as $o ) {
	$theme_after[ $o ] = get_option( $o, 'ABSENT' );
}
ok( $theme_before === $theme_after, 'CG5 the active theme, its name, the switch notes for the next request and the widget areas are as they were' );
$mods = get_option( 'theme_mods_twentytwentysix' );
ok( array( 'primary' => 10 ) === $mods['nav_menu_locations'] && ! isset( $mods['wp_classic_sidebars'] ), 'CG5 the active theme keeps its menus: what the ability set up after the switch did not land on it' );
ok( 'ff0000' === $mods['header_textcolor'], 'CG5 counter-check: what it wrote before the switch is the active theme\'s and stays' );
ok( ! array_key_exists( 'sidebars_widgets', $mods ), 'CG5 the copy of the widget areas switch_theme() wrote into the active theme\'s settings before the switch is gone again' );
ok( 'on' === $GLOBALS['ab_autoload']['theme_mods_twentytwentysix'] && 'off' === $GLOBALS['ab_autoload']['theme_mods_twentytwentyfive'], 'CG5 the autoload flags switch_theme() set past the option functions are back' );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-3' ) ) );
ok( array( 'sidebar-1' => array( 'text-3' ) ) === get_option( 'sidebars_widgets' ), 'CG5 counter-check: after the call the widget areas take writes again' );

// Nested: the inner close hands over nothing, the outer one everything.
AB_MCP_Ability_Code_Gate::open();
AB_MCP_Ability_Code_Gate::open();
update_option( 'active_plugins', array_merge( $plugins_before, array( 'x/x.php' ) ) );
$inner = AB_MCP_Ability_Code_Gate::close();
update_option( 'active_plugins', array_merge( $plugins_before, array( 'y/y.php' ) ) );
$outer = AB_MCP_Ability_Code_Gate::close();
ok( array() === $inner['plugins'] && array( 'x/x.php' => false, 'y/y.php' => false ) === $outer['plugins'] && $plugins_before === get_option( 'active_plugins' ), 'CG6 nested: kept off until the outer close, which names both' );
ok( array( 'plugins' => array(), 'theme' => '' ) === AB_MCP_Ability_Code_Gate::close(), 'CG6 a close without open hands over nothing' );

// Deleted and added again (Codex, 06.10.2026): add_option() has no pre_update
// filter; it runs sanitize_option_<name> before it writes, and writes only
// where nothing is stored.
AB_MCP_Ability_Code_Gate::open();
delete_option( 'active_plugins' );
add_option( 'active_plugins', array( 'akismet/akismet.php', 'new/new.php', 'zz-first/zz.php' ) );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( $plugins_before === get_option( 'active_plugins' ) && array( 'new/new.php' => false ) === $kept['plugins'], 'CG7 delete_option() and add_option() of the list: the new plugin stays out and is named, the plugins active when the call began come back as they were' );
AB_MCP_Ability_Code_Gate::open();
delete_option( 'active_plugins' );
update_option( 'active_plugins', array( 'akismet/akismet.php', 'new/new.php' ) );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( array( 2 => 'akismet/akismet.php' ) === get_option( 'active_plugins' ) && array( 'new/new.php' => false ) === $kept['plugins'], 'CG7 update_option() of the deleted list, which hands over to add_option(): the same' );
AB_MCP_Ability_Code_Gate::open();
ok( false === add_option( 'active_plugins', array( 'new/new.php' ) ) && array( 2 => 'akismet/akismet.php' ) === get_option( 'active_plugins' ), 'CG7 add_option() where the list is stored writes nothing, as in WordPress' );
AB_MCP_Ability_Code_Gate::close();
delete_option( 'active_plugins' );
add_option( 'active_plugins', array( 'new/new.php' ) );
ok( array( 'new/new.php' ) === get_option( 'active_plugins' ), 'CG7 counter-check: outside a call add_option() writes what it is given' );
update_option( 'active_plugins', $plugins_before );

AB_MCP_Ability_Code_Gate::open();
delete_option( 'template' );
delete_option( 'stylesheet' );
add_option( 'template', 'twentytwentyfive' );
add_option( 'stylesheet', 'twentytwentyfive' );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( 'twentytwentysix' === get_option( 'stylesheet' ) && 'twentytwentysix' === get_option( 'template' ) && 'twentytwentyfive' === $kept['theme'], 'CG8 delete_option() and add_option() of the theme: the theme of the call\'s start is written, the other one named' );

AB_MCP_Ability_Code_Gate::open();
$net  = apply_filters( 'pre_add_site_option_active_sitewide_plugins', array( 'n/n.php' => 5 ), 'active_sitewide_plugins', 1 );
$kept = AB_MCP_Ability_Code_Gate::close();
ok( array() === $net && array( 'n/n.php' => true ) === $kept['plugins'], 'CG9 add_network_option() of the network\'s list where none is stored: the new plugin stays out and is named' );
ok( array( 'n/n.php' => 5 ) === apply_filters( 'pre_add_site_option_active_sitewide_plugins', array( 'n/n.php' => 5 ), 'active_sitewide_plugins', 1 ), 'CG9 counter-check: outside a call the filter is gone' );

// An ability that ends the request with exit or die (Codex, 06.10.2026).
$out  = array();
$code = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' main ' . escapeshellarg( $wp_api ) . ' gate_exit 2>&1', $out, $code );
$line = (string) end( $out );
$seen = 0 === strpos( $line, 'GATE_EXIT ' ) ? json_decode( substr( $line, 10 ), true ) : null;
ok( 0 === $code && is_array( $seen ) && 'twentytwentysix' === $seen['stylesheet'], 'CG10 a switch_theme callback that exits: the theme stays as it was (' . $line . ')' );
ok( is_array( $seen ) && is_array( $seen['mods'] ) && ! array_key_exists( 'sidebars_widgets', $seen['mods'] ), 'CG10 the widgets copy switch_theme() wrote into the active theme\'s settings is gone again, at the shutdown of the request' );
ok( is_array( $seen ) && 'on' === $seen['autoload']['theme_mods_twentytwentysix'] && 'off' === $seen['autoload']['theme_mods_twentytwentyfive'], 'CG10 and the autoload flags are back' );
// And one whose own shutdown callback exits too: WordPress's shutdown
// action runs before the gate's shutdown function (Codex, 06.10.2026).
$out  = array();
$code = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' main ' . escapeshellarg( $wp_api ) . ' gate_exit_wp 2>&1', $out, $code );
$line = (string) end( $out );
$seen = 0 === strpos( $line, 'GATE_EXIT ' ) ? json_decode( substr( $line, 10 ), true ) : null;
ok( 0 === $code && is_array( $seen ) && 'twentytwentysix' === $seen['stylesheet'] && is_array( $seen['mods'] ) && ! array_key_exists( 'sidebars_widgets', $seen['mods'] ) && 'on' === $seen['autoload']['theme_mods_twentytwentysix'] && 'off' === $seen['autoload']['theme_mods_twentytwentyfive'], 'CG11 a shutdown callback of the ability that exits too: the gate closes first in WordPress\'s shutdown action (' . $line . ')' );

/* ===================== PC) other plugins' copies of the meta after wp_undo */

// Yoast SEO keeps the SEO fields in its indexable; WooCommerce keeps price and
// stock in its lookup table. A restore past WordPress's meta functions wakes
// neither (measured 02.10.2026); wp_undo asks each plugin to build its copy again.
$GLOBALS['ab_caps']['edit_post:34'] = true;
ab_post( 34, array( 'post_type' => 'product', 'post_title' => 'Produkt' ) );
ab_meta_add( 34, '_price', '19.00' );
ab_meta_add( 34, '_regular_price', '19.00' );
$p34 = ab_dump( 34 );
$b30 = ab_dump( 30 );
// Neither plugin active: nothing to do, nothing to warn about.
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => '_yoast_wpseo_canonical', 'value' => 'https://example.org/fremd/' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && ! isset( $u['warnings'] ) && $b30 === ab_dump( 30 ), 'PC1 without Yoast: restored, no warning' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_price', 'value' => '23.00' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 34 ) === $u['restored'] && ! isset( $u['warnings'] ) && $p34 === ab_dump( 34 ), 'PC2 without WooCommerce: restored, no warning' );

// Yoast SEO 28.6, narrower: YoastSEO()->classes->get() of its container, and
// the post watcher that builds the indexable from the meta as it is then.
class AB_Test_Yoast_Watcher {
	public $built = array();
	public $throw = false;
	public function build_indexable( $id ) {
		if ( $this->throw ) {
			throw new RuntimeException( 'kaputt' );
		}
		$this->built[] = array( (int) $id, ab_first_meta( (int) $id, '_yoast_wpseo_canonical' ) );
	}
}
class AB_Test_Yoast_Classes {
	public $asked   = array();
	public $missing = false;
	public function get( $class ) {
		$this->asked[] = $class;
		if ( $this->missing ) {
			throw new RuntimeException( 'Service not found' );
		}
		return $GLOBALS['ab_yoast_watcher'];
	}
}
if ( ! function_exists( 'YoastSEO' ) ) {
	function YoastSEO() { return $GLOBALS['ab_yoast']; }
}
$GLOBALS['ab_yoast_watcher'] = new AB_Test_Yoast_Watcher();
$GLOBALS['ab_yoast']         = (object) array( 'classes' => new AB_Test_Yoast_Classes() );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => '_yoast_wpseo_canonical', 'value' => 'https://example.org/fremd/' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && ! isset( $u['warnings'] ) && $b30 === ab_dump( 30 ), 'PC3 with Yoast: restored, no warning' );
ok( array( array( 30, '' ) ) === $GLOBALS['ab_yoast_watcher']->built && array( 'Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher' ) === $GLOBALS['ab_yoast']->classes->asked, 'PC3 Yoast\'s post watcher built the indexable of the post once, after the canonical address was gone again' );
$GLOBALS['ab_yoast_watcher']->built = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array() === $GLOBALS['ab_yoast_watcher']->built, 'PC4 a key that is not Yoast\'s: Yoast is left alone' );
$GLOBALS['ab_yoast']->classes->missing = true;
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => '_yoast_wpseo_title', 'value' => 'Fremd' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && $b30 === ab_dump( 30 ) && isset( $u['warnings'] ) && ab_has( $u['warnings'], 'Post 30: Yoast SEO keeps the SEO fields of this post in a table of its own' ) && ab_has( $u['warnings'], '(RuntimeException)' ) && ab_has( $u['warnings'], 'wp_update_post' ), 'PC5 Yoast\'s watcher cannot be had: restored all the same, with the warning and the way' );
$GLOBALS['ab_yoast']->classes->missing = false;
$GLOBALS['ab_yoast_watcher']->throw    = true;
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => '_yoast_wpseo_title', 'value' => 'Fremd' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 30 ) === $u['restored'] && ab_has( $u['warnings'], 'Yoast SEO keeps' ) && ab_has( $u['warnings'], '(RuntimeException)' ), 'PC6 the watcher throws: the same warning' );
$GLOBALS['ab_yoast_watcher']->throw = false;
$GLOBALS['ab_yoast']->classes       = new class() { public function get( $c ) { return new stdClass(); } };
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => '_yoast_wpseo_title', 'value' => 'Fremd' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && ab_has( $u['warnings'], '(its indexable watcher is not available)' ), 'PC7 a service without build_indexable(): the warning says the watcher is missing' );
$GLOBALS['ab_yoast']->classes = new AB_Test_Yoast_Classes();

// WooCommerce 11.1.2, narrower: the data store's refresh_product_lookup_table()
// (WooCommerce 10.8) through WC_Data_Store::__call(), the object cache of the
// product and its transients.
if ( ! class_exists( 'WC_Data_Store' ) ) {
	class WC_Data_Store {
		public static $loaded    = array();
		public static $refreshed = array();
		public static $old       = false;
		public static function load( $type ) {
			self::$loaded[] = $type;
			return new self();
		}
		public function has_callable( $method ) {
			return 'refresh_product_lookup_table' === $method && ! self::$old;
		}
		public function __call( $method, $args ) {
			if ( 'refresh_product_lookup_table' === $method ) {
				self::$refreshed[] = array( $args[0], ab_first_meta( (int) $args[0], '_price' ) );
			}
		}
	}
	class WC_Cache_Helper {
		public static $groups = array();
		public static function invalidate_cache_group( $group ) {
			self::$groups[] = $group;
		}
	}
	function wc_delete_product_transients( $id ) {
		$GLOBALS['ab_wc_transients'][] = (int) $id;
	}
}
$GLOBALS['ab_wc_transients'] = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_price', 'value' => '23.00' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 34 ) === $u['restored'] && ! isset( $u['warnings'] ) && $p34 === ab_dump( 34 ), 'PC8 with WooCommerce: the product restored, no warning' );
ok( array( array( 34, '19.00' ) ) === WC_Data_Store::$refreshed && array( 'product' ) === WC_Data_Store::$loaded && array( 'object_34' ) === WC_Cache_Helper::$groups && array( 34 ) === $GLOBALS['ab_wc_transients'], 'PC8 its lookup row written again from the restored price, through WooCommerce\'s product data store, its object cache and transients emptied' );
WC_Data_Store::$refreshed = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 30, 'key' => 'farbe', 'value' => 'rot' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array() === WC_Data_Store::$refreshed, 'PC9 a page that is no product: WooCommerce is left alone' );
$r = ab_run( array( 'name' => 'test/hidden-copy', 'input' => array( 'id' => 34 ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 34 ) === $u['restored'] && array() === WC_Data_Store::$refreshed && $p34 === ab_dump( 34 ), 'PC10 only the content of the product came back, no meta: nothing to write again' );
WC_Data_Store::$old = true;
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_price', 'value' => '23.00' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 34 ) === $u['restored'] && $p34 === ab_dump( 34 ) && ab_has( $u['warnings'], 'Post 34: WooCommerce keeps the price and stock of this product in its lookup table too' ) && ab_has( $u['warnings'], 'WooCommerce 10.8' ) && ab_has( $u['warnings'], 'saved once in WooCommerce' ), 'PC11 a WooCommerce before 10.8: restored, with the warning and the way' );
WC_Data_Store::$old = false;
// A variation has its own row in the lookup table, written by the same call.
$GLOBALS['ab_caps']['edit_post:38'] = true;
ab_post( 38, array( 'post_type' => 'product_variation', 'post_parent' => 34, 'post_title' => 'Produkt - Rot' ) );
ab_meta_add( 38, '_price', '21.00' );
$p38                         = ab_dump( 38 );
WC_Data_Store::$refreshed    = array();
WC_Data_Store::$loaded       = array();
WC_Cache_Helper::$groups     = array();
$GLOBALS['ab_wc_transients'] = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 38, 'key' => '_price', 'value' => '25.00' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 38 ) === $u['restored'] && ! isset( $u['warnings'] ) && $p38 === ab_dump( 38 ), 'PC12 a variation: restored, no warning' );
ok( array( array( 38, '21.00' ) ) === WC_Data_Store::$refreshed && array( 'product' ) === WC_Data_Store::$loaded && array( 'object_38' ) === WC_Cache_Helper::$groups && array( 38 ) === $GLOBALS['ab_wc_transients'], 'PC12 its lookup row written again from the restored price, its object cache and transients emptied' );

// WooCommerce marks a product out of stock with the term outofstock of
// product_visibility when it saves it, and the shop hides it by the term where
// "Hide out of stock items" is on. The undo of the stock status brings the term
// in step (Codex, 03.10.2026).
$GLOBALS['ab_terms']     = array();
$GLOBALS['ab_term_fail'] = false;
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $t ) { return 'product_visibility' === $t; }
	function wp_get_object_terms( $id, $tax, $args = array() ) { return isset( $GLOBALS['ab_terms'][ (int) $id ] ) ? array_values( $GLOBALS['ab_terms'][ (int) $id ] ) : array(); }
	function wp_add_object_terms( $id, $term, $tax ) {
		if ( $GLOBALS['ab_term_fail'] ) { return new WP_Error( 'db_insert_error', 'Could not insert term relationship into the database.' ); }
		$GLOBALS['ab_terms'][ (int) $id ][] = $term;
		return array( 7 );
	}
	function wp_remove_object_terms( $id, $term, $tax ) {
		if ( $GLOBALS['ab_term_fail'] ) { return new WP_Error( 'db_delete_error', 'Could not delete term relationship from the database.' ); }
		$GLOBALS['ab_terms'][ (int) $id ] = array_values( array_diff( isset( $GLOBALS['ab_terms'][ (int) $id ] ) ? $GLOBALS['ab_terms'][ (int) $id ] : array(), array( $term ) ) );
		return true;
	}
}
ab_meta_add( 34, '_stock_status', 'instock' );
$GLOBALS['ab_terms'][34] = array( 'featured' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_stock_status', 'value' => 'outofstock' ), 'apply' => true ) );
$GLOBALS['ab_terms'][34][] = 'outofstock'; // what WooCommerce's data store writes with it
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array( 34 ) === $u['restored'] && ! isset( $u['warnings'] ) && 'instock' === ab_first_meta( 34, '_stock_status' ), 'PC13 the stock status of the product is restored, no warning' );
ok( array( 'featured' ) === $GLOBALS['ab_terms'][34], 'PC13 and the term outofstock is gone again, so the shop shows the product; its other terms stay' );
// The other way round: out of stock before, in stock through the ability.
foreach ( ab_meta_rows( 34, '_stock_status' ) as $mid => $v ) { $GLOBALS['db_meta'][ $mid ][2] = 'outofstock'; }
$GLOBALS['ab_terms'][34] = array( 'outofstock' );
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_stock_status', 'value' => 'instock' ), 'apply' => true ) );
$GLOBALS['ab_terms'][34] = array();
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && 'outofstock' === ab_first_meta( 34, '_stock_status' ) && array( 'outofstock' ) === $GLOBALS['ab_terms'][34] && ! isset( $u['warnings'] ), 'PC14 out of stock before: the term comes back with the status' );
// The term cannot be written: restored all the same, with the warning and the way.
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_stock_status', 'value' => 'instock' ), 'apply' => true ) );
$GLOBALS['ab_terms'][34] = array();
$GLOBALS['ab_term_fail'] = true;
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && 'outofstock' === ab_first_meta( 34, '_stock_status' ) && isset( $u['warnings'] ) && ab_has( $u['warnings'], 'Post 34: WooCommerce marks a product that is out of stock' ) && ab_has( $u['warnings'], 'Could not insert term relationship' ) && ab_has( $u['warnings'], 'saved once in WooCommerce' ), 'PC15 the term cannot be written: restored, with the warning and the way' );
$GLOBALS['ab_term_fail'] = false;
// A restore of another key leaves the term alone.
$GLOBALS['ab_terms'][34] = array();
$r = ab_run( array( 'name' => 'test/set-meta', 'input' => array( 'post_id' => 34, 'key' => '_price', 'value' => '23.00' ), 'apply' => true ) );
$u = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $r['undo_token'] ) );
ok( is_array( $u ) && array() === $GLOBALS['ab_terms'][34], 'PC16 only the price came back: the term is left as it is' );

/* ===================== MP) the meta public of WordPress 7.1: published or internal */

// Each way a plugin can say whether it publishes an ability, and what the
// broker reads (is_public()): REST as WordPress resolves it (7.1: show_in_rest
// ?? public ?? false), MCP as the dev note of 04.08.2026 asks every channel to
// (mcp.public ?? public ?? false). Last column: on 7.0.2, on 7.1.2.
$cases = array(
	'nothing'                                  => array( array(), false, false ),
	'public true'                              => array( array( 'public' => true ), true, true ),
	'public false'                             => array( array( 'public' => false ), false, false ),
	'show_in_rest true'                        => array( array( 'show_in_rest' => true ), true, true ),
	'public true, show_in_rest false'          => array( array( 'public' => true, 'show_in_rest' => false ), true, true ),
	'public false, show_in_rest true'          => array( array( 'public' => false, 'show_in_rest' => true ), true, true ),
	'mcp.public true'                          => array( array( 'mcp' => array( 'public' => true ) ), true, true ),
	'public true, mcp.public false'            => array( array( 'public' => true, 'mcp' => array( 'public' => false ) ), false, true ),
	'public true, show_in_rest and mcp false'  => array( array( 'public' => true, 'show_in_rest' => false, 'mcp' => array( 'public' => false ) ), false, false ),
	'public false, mcp.public true'            => array( array( 'public' => false, 'mcp' => array( 'public' => true ) ), true, true ),
);
$mp_ability = static function ( array $meta ) {
	return new WP_Ability( 'test/mp', array( 'label' => 'MP', 'description' => 'MP', 'category' => 'test', 'execute_callback' => static function () { return true; }, 'permission_callback' => static function () { return true; }, 'meta' => $meta + array( 'annotations' => array( 'readonly' => true, 'destructive' => false ) ) ) );
};
$wrong = array();
foreach ( $cases as $label => $c ) {
	$want = '7.1.2' === $wp_api ? $c[2] : $c[1];
	if ( $want !== AB_MCP_Ability_Broker::is_public( $mp_ability( $c[0] ) ) ) {
		$wrong[] = $label;
	}
}
ok( array() === $wrong, 'MP1 published or internal, for each way to say it, on WordPress ' . $wp_api . ( $wrong ? ': wrong for ' . implode( '; ', $wrong ) : '' ) );
ok( array() === $GLOBALS['ab_wrong'], 'Z1 no notice of WordPress (_doing_it_wrong) on the whole way' . ( $GLOBALS['ab_wrong'] ? ': ' . implode( ' | ', $GLOBALS['ab_wrong'] ) : '' ) );
ab_result( 'ABILITY-BROKER (main)' );
