<?php
/**
 * The registration of the write side (AB_MCP_Sync_Writers, the seam, first
 * step T1): the refusals of register() (a subtype twice, an entry with a
 * stage, no class loaded, whole and by subtype at once) and the list of this
 * plugin (a kind struck out, two classes swapped). Written by hand.
 *
 * Not here: n-ganz-vor-teil (of() asks the whole kind before the subtype).
 * It is equivalent while a kind is either whole or by subtype, never both
 * (naht/n-ganz-und-teil holds that); it goes to I1.
 *
 * Held by the unit suite (SyncWritersTest).
 *
 * @package AlphaBridge_MCP
 */

$unit = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncWritersTest' );

$sw = 'includes/sync/class-sync-writers.php';

return array(
	array(
		'id'      => 'naht/n-doppelt',
		'clause'  => 'register(): a kind or subtype registered already is refused',
		'file'    => $sw,
		'search'  => "\t\tif ( count( \$once ) !== count( \$taken ) ) {",
		'replace' => "\t\tif ( false && count( \$once ) !== count( \$taken ) ) {",
		'run'     => $unit,
		'expect'  => '/with data set "a subtype twice"/',
	),
	array(
		'id'      => 'naht/n-etappe',
		'clause'  => 'register(): a subtype whose entry has a stage is refused',
		'file'    => $sw,
		'search'  => "\t\t\tif ( \$kind === \$entry['kind'] && isset( \$entry['stage'] ) && in_array(",
		'replace' => "\t\t\tif ( false && \$kind === \$entry['kind'] && isset( \$entry['stage'] ) && in_array(",
		'run'     => $unit,
		'expect'  => '/with data set "an entry with a stage \(Kinds :159\)"/',
	),
	array(
		'id'      => 'naht/n-klasse',
		'clause'  => 'register(): a class that is not loaded is refused',
		'file'    => $sw,
		'search'  => "\t\tif ( ! is_string( \$class ) || ! class_exists( \$class ) ) {",
		'replace' => "\t\tif ( false && ( ! is_string( \$class ) || ! class_exists( \$class ) ) ) {",
		'run'     => $unit,
		'expect'  => '/with data set "no class, whole"/',
	),
	array(
		'id'      => 'naht/n-ganz-und-teil',
		'clause'  => 'register(): a kind is registered whole or by subtype, never both',
		'file'    => $sw,
		'search'  => "\t\tif ( in_array( self::WHOLE, \$once, true ) && count( \$once ) > 1 ) {",
		'replace' => "\t\tif ( false && in_array( self::WHOLE, \$once, true ) && count( \$once ) > 1 ) {",
		'run'     => $unit,
		'expect'  => '/with data set "whole after a subtype"/',
	),
	array(
		'id'      => 'naht/n-free-liste',
		'clause'  => 'the list of this plugin registers post',
		'file'    => $sw,
		'search'  => "AB_MCP_Sync_Writers::register( 'post', AB_MCP_Sync_Writers::WHOLE, 'AB_MCP_Sync_Post_Writer' );",
		'replace' => '// post struck out (naht/n-free-liste).',
		'run'     => $unit,
		'expect'  => '/testAFreshProcessThatLoadsOnlyTheFileHasEveryKindWhole/',
	),
	array(
		'id'      => 'naht/n-free-klasse',
		'clause'  => 'the list of this plugin gives each kind its class of WRITERS',
		'file'    => $sw,
		'search'  => "( 'post', AB_MCP_Sync_Writers::WHOLE, 'AB_MCP_Sync_Post_Writer' );\nAB_MCP_Sync_Writers::register( 'term', AB_MCP_Sync_Writers::WHOLE, 'AB_MCP_Sync_Term_Writer' );",
		'replace' => "( 'post', AB_MCP_Sync_Writers::WHOLE, 'AB_MCP_Sync_Term_Writer' );\nAB_MCP_Sync_Writers::register( 'term', AB_MCP_Sync_Writers::WHOLE, 'AB_MCP_Sync_Post_Writer' );",
		'run'     => $unit,
		'expect'  => '/testTheWritersOfThisPluginAreTheWritersOfTheEngineForEveryKindAndSubtype/',
	),
);
