<?php
/**
 * The registration of the write side (AB_MCP_Sync_Writers, the seam, first
 * step T1): the refusals of register() (a subtype twice, an entry with a
 * stage, no class loaded, whole and by subtype at once) and the list of this
 * plugin at the end of the engine (a kind struck out, two classes swapped).
 * T2: writer() asks with the subtype, and the reader of the subtype of a
 * journal (fixed to post, fixed to ''); the check before the first write
 * asks the rule planned_parent() of a writer (N-c no. 1). Written by hand.
 *
 * Not here: n-ganz-vor-teil (of() asks the whole kind before the subtype).
 * It is equivalent while a kind is either whole or by subtype, never both
 * (naht/n-ganz-und-teil holds that); it goes to I1.
 *
 * Not here either: writer_of() asking the subtype for a kind registered
 * whole as well. It is equivalent: a whole kind answers every subtype.
 *
 * Held by the unit suite (SyncWritersTest, SyncWriterOfTest), n-nc-854 by
 * the integration suite (SyncContentTest).
 *
 * @package AlphaBridge_MCP
 */

$unit = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncWritersTest' );
$of   = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncWriterOfTest' );
$swap = array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]SyncContentTest::testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell( |$)/' );

$sw = 'includes/sync/class-sync-writers.php';
$en = 'includes/sync/class-sync-engine.php';

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
		'file'    => $en,
		'search'  => 'foreach ( AB_MCP_Sync_Engine::WRITERS as $ab_mcp_kind => $ab_mcp_writer ) {',
		'replace' => 'foreach ( array_slice( AB_MCP_Sync_Engine::WRITERS, 1 ) as $ab_mcp_kind => $ab_mcp_writer ) {',
		'run'     => $unit,
		'expect'  => '/testAFreshProcessThatLoadsTheEngineHasEveryKindWhole/',
	),
	array(
		'id'      => 'naht/n-free-klasse',
		'clause'  => 'the list of this plugin gives each kind its class of WRITERS',
		'file'    => $en,
		'search'  => "\tAB_MCP_Sync_Writers::register( \$ab_mcp_kind, AB_MCP_Sync_Writers::WHOLE, \$ab_mcp_writer );",
		'replace' => "\tAB_MCP_Sync_Writers::register( \$ab_mcp_kind, AB_MCP_Sync_Writers::WHOLE, array( 'post' => 'AB_MCP_Sync_Term_Writer', 'term' => 'AB_MCP_Sync_Post_Writer' )[ \$ab_mcp_kind ] ?? \$ab_mcp_writer );",
		'run'     => $unit,
		'expect'  => '/testTheWritersOfThisPluginAreTheWritersOfTheEngineForEveryKindAndSubtype/',
	),
	array(
		'id'      => 'naht/n-writer-untertyp',
		'clause'  => 'writer(): with a subtype, the class registered for it',
		'file'    => $en,
		'search'  => "\t\treturn AB_MCP_Sync_Writers::of( \$kind, (string) \$subtype );",
		'replace' => "\t\treturn AB_MCP_Sync_Writers::of( \$kind, '' );",
		'run'     => $unit,
		'expect'  => '/testWriterAsksTheRegistrationBySubtype/',
	),
	array(
		'id'      => 'naht/n-untertyp',
		'clause'  => 'subtype_in(): the subtype of a journal from its identity, not post',
		'file'    => $en,
		'search'  => "\t\t\$subtype = \$journal['object']['identity']['post_type'] ?? \$journal['object']['identity']['taxonomy'] ?? \$journal['object']['identity']['name'] ?? '';",
		'replace' => "\t\t\$subtype = 'post';",
		'run'     => $of,
		'expect'  => '/testAJournalOfAKindRegisteredBySubtypeGivesTheClassOfItsSubtype/',
	),
	array(
		'id'      => 'naht/n-untertyp-leer',
		'clause'  => 'subtype_in(): the subtype of a journal from its identity, not none',
		'file'    => $en,
		'search'  => "\t\t\$subtype = \$journal['object']['identity']['post_type'] ?? \$journal['object']['identity']['taxonomy'] ?? \$journal['object']['identity']['name'] ?? '';",
		'replace' => "\t\t\$subtype = '';",
		'run'     => $of,
		'expect'  => '/testAJournalOfAKindRegisteredBySubtypeGivesTheClassOfItsSubtype/',
	),
	array(
		'id'      => 'naht/n-nc-854',
		'clause'  => 'planned_parents(): the check asks the rule planned_parent() of the writer of the kind',
		'file'    => $en,
		'search'  => "\t\t\tif ( ! self::has_rule( \$w, 'planned_parent' ) || 'update' !== ( isset( \$one['op'] ) ? (string) \$one['op'] : '' ) ) {",
		'replace' => "\t\t\tif ( true || 'update' !== ( isset( \$one['op'] ) ? (string) \$one['op'] : '' ) ) {",
		'run'     => $swap,
		'expect'  => '/testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell/',
	),
);
