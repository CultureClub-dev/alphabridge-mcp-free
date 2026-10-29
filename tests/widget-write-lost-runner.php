<?php
/**
 * A WordPress stand-in whose update_option() loses the write of the widget
 * row widget_text — as a pre_update_option filter or a failing database
 * can — while every other option stores. The widget tools read their write
 * back, and this is where that read-back has to speak up.
 *
 * Run by FreeSinceFourNineWidgetsMetaOpsTest with PHP_BINARY, in a process
 * of its own, because the bootstrap's update_option() always stores. Prints
 * the answer of one tool (update, add or delete) as JSON, with the options
 * written and the sidebar map afterwards.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function __( $text, $domain = '' ) {
	return $text;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

$GLOBALS['ab_lost_options'] = array(
	'sidebars_widgets' => array(
		'sidebar-1'     => array( 'text-2' ),
		'array_version' => 3,
	),
	'widget_text'      => array(
		2              => array( 'title' => 'a' ),
		'_multiwidget' => 1,
	),
);
$GLOBALS['ab_lost_writes']  = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ab_lost_options'] ) ? $GLOBALS['ab_lost_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['ab_lost_writes'][] = $name;
	if ( 'widget_text' !== $name ) {
		$GLOBALS['ab_lost_options'][ $name ] = $value;
	}
	return true;
}

class WP_Widget {
	public $id_base;
	public $number = 0;

	public function __construct( $id_base ) {
		$this->id_base = $id_base;
	}

	public function _set( $number ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- WordPress' name.
		$this->number = (int) $number;
	}

	public function update( $new_instance, $old_instance ) {
		return $new_instance;
	}
}

$GLOBALS['wp_widget_factory']      = (object) array( 'widgets' => array( new WP_Widget( 'text' ) ) );
$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Sidebar' ) );

require dirname( __DIR__ ) . '/includes/tools/class-tools-base.php';
require dirname( __DIR__ ) . '/includes/tools/class-tools-widgets.php';

switch ( $argv[1] ?? '' ) {
	case 'update':
		$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'b' ) ) );
		break;
	case 'add':
		$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) );
		break;
	case 'delete':
		$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
		break;
	default:
		fwrite( STDERR, "mode: update, add or delete\n" );
		exit( 2 );
}

echo json_encode(
	array(
		'code'    => is_wp_error( $res ) ? $res->code : null,
		'message' => is_wp_error( $res ) ? $res->message : null,
		'writes'  => $GLOBALS['ab_lost_writes'],
		'map'     => $GLOBALS['ab_lost_options']['sidebars_widgets'],
	)
);
