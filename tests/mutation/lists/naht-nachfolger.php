<?php
/**
 * The successors of the entries of the other lists whose clause the seam
 * moved out of AB_MCP_Sync_Push (first step T3, stage 1, decision of the
 * steering 18.11. after 11:16 and the answer of 11:18): the old entries stay
 * word for word until T6, which replaces them by these. Each successor takes
 * the same clause out at its new place, one for each target; its run and
 * expect are those of the old entry word for word, so the same test that
 * held the old one holds it. Written by hand.
 *
 * Part 1 of T3 (the rules of verdict(), B4): fourteen successors of eight
 * old entries. struktur/push-draft has five targets (the five classes of the
 * structure), struktur/push-create three (widget area, option, CSS).
 * medien/verdict-attachment and struktur/push-structure take the kind out of
 * the registration of this plugin at the end of the engine: without a writer
 * verdict() locks the kind, as the old ones made it fall into that lock.
 *
 * Held by the unit suite (SyncRunTest), naht-nachfolger/vorlagen-theme-plan
 * by the integration suite (SyncTemplatesTest).
 *
 * @package AlphaBridge_MCP
 */

$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$tw = 'includes/sync/class-sync-term-writer.php';
$pw = 'includes/sync/class-sync-post-writer.php';
$aw = 'includes/sync/class-sync-attachment-writer.php';
$en = 'includes/sync/class-sync-engine.php';
$mw = 'includes/sync/class-sync-menu-writer.php';
$wa = 'includes/sync/class-sync-widget-area-writer.php';
$cw = 'includes/sync/class-sync-css-writer.php';
$ow = 'includes/sync/class-sync-option-writer.php';

$draft  = "\t\tif ( \$as_draft ) {\n\t\t\t/* translators: %s: kind of the object, as the tools name it (menu, widget_area, option, …). */\n\t\t\treturn \$lock( sprintf( ";
$nodraf = "\t\tif ( false ) {\n\t\t\t/* translators: %s: kind of the object, as the tools name it (menu, widget_area, option, …). */\n\t\t\treturn \$lock( sprintf( ";
$rule   = "\t * seam, D4, asked by AB_MCP_Sync_Push::verdict()).\n\t *\n\t * @param array    \$entry    The entry of the report.\n\t * @param bool     \$as_draft Whether it is taken over as draft.\n\t * @param callable \$lock     The lock of verdict(): reason, stage.\n\t * @return array|null\n\t */\n\tpublic static function plan_lock( array \$entry, \$as_draft, callable \$lock ) {\n\t\t// The structure of the site (branch 11): no choice «as draft»; a\n\t\t// widget area or a core option is changed, never made by a run (the\n\t\t// additional CSS is locked to be made in the report already).\n";
$create = "\t\tif ( 'create' === (string) \$entry['action'] ) {";
$writes = "foreach ( AB_MCP_Sync_Engine::WRITERS as \$ab_mcp_kind => \$ab_mcp_writer ) {";

return array(
	array(
		'id'      => 'naht-nachfolger/inhalte-plan-term-taxonomy',
		'clause'  => 'verdict(): a term of a taxonomy the term engine does not write is locked with Z8d (Nachfolger von inhalte/plan-term-taxonomy)',
		'file'    => $tw,
		'search'  => "\t\tif ( ! in_array( (string) ( \$entry['subtype'] ?? '' ), self::TAXONOMIES, true ) ) {",
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/lauf-plan-type',
		'clause'  => 'verdict(): a type the engine does not write is locked with Z8d (Nachfolger von lauf/plan-type)',
		'file'    => $pw,
		'search'  => "\t\tif ( ! in_array( (string) ( \$entry['subtype'] ?? '' ), self::TYPES, true ) ) {",
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/vorlagen-theme-plan',
		'clause'  => 'verdict(): the plan names a template of a theme that is not the active one locked (all leaves it out) (Nachfolger von vorlagen/theme-plan)',
		'file'    => $pw,
		'search'  => "\t\t\tif ( null !== \$other ) {\n\t\t\t\t\$held = AB_MCP_Sync_Kinds::lock( 'post', 'theme_mismatch' );",
		'replace' => "\t\t\tif ( false ) {\n\t\t\t\t\$held = AB_MCP_Sync_Kinds::lock( 'post', 'theme_mismatch' );",
		'run'     => $it( 'SyncTemplatesTest', 'testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten' ),
		'expect'  => '/testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-verdict-no-draft',
		'clause'  => 'verdict(): an attachment has no status draft (Nachfolger von medien/verdict-no-draft)',
		'file'    => $aw,
		'search'  => "\t\tif ( \$as_draft ) {\n\t\t\treturn \$lock( ",
		'replace' => "\t\tif ( false ) {\n\t\t\treturn \$lock( ",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-verdict-attachment',
		'clause'  => 'verdict(): an attachment is an item of its kind (Nachfolger von medien/verdict-attachment)',
		'file'    => $en,
		'search'  => $writes,
		'replace' => "foreach ( array_diff_key( AB_MCP_Sync_Engine::WRITERS, array( 'attachment' => true ) ) as \$ab_mcp_kind => \$ab_mcp_writer ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-structure',
		'clause'  => 'verdict(): the kinds of the structure are items (Nachfolger von struktur/push-structure)',
		'file'    => $en,
		'search'  => $writes,
		'replace' => "foreach ( array_diff_key( AB_MCP_Sync_Engine::WRITERS, array_flip( array( 'menu', 'menu_item', 'widget_area', 'custom_css', 'option' ) ) ) as \$ab_mcp_kind => \$ab_mcp_writer ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-draft-menu',
		'clause'  => 'verdict(): no structure as draft (Nachfolger von struktur/push-draft)',
		'file'    => $mw,
		'search'  => "\t * The lock of the plan for an entry of a menu, or null (rule of the\n" . $rule . $draft,
		'replace' => "\t * The lock of the plan for an entry of a menu, or null (rule of the\n" . $rule . $nodraf,
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-draft-menu_item',
		'clause'  => 'verdict(): no structure as draft (Nachfolger von struktur/push-draft)',
		'file'    => $mw,
		'search'  => "\t * The lock of the plan for an entry of an item of a menu, or null (rule\n" . str_replace( "\t * seam, D4", "\t * of the seam, D4", $rule ) . $draft,
		'replace' => "\t * The lock of the plan for an entry of an item of a menu, or null (rule\n" . str_replace( "\t * seam, D4", "\t * of the seam, D4", $rule ) . $nodraf,
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-draft-widget_area',
		'clause'  => 'verdict(): no structure as draft (Nachfolger von struktur/push-draft)',
		'file'    => $wa,
		'search'  => $draft,
		'replace' => $nodraf,
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-draft-custom_css',
		'clause'  => 'verdict(): no structure as draft (Nachfolger von struktur/push-draft)',
		'file'    => $cw,
		'search'  => $draft,
		'replace' => $nodraf,
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-draft-option',
		'clause'  => 'verdict(): no structure as draft (Nachfolger von struktur/push-draft)',
		'file'    => $ow,
		'search'  => $draft,
		'replace' => $nodraf,
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-create-widget_area',
		'clause'  => 'verdict(): a widget area, an option and the CSS are not made by a run (Nachfolger von struktur/push-create)',
		'file'    => $wa,
		'search'  => $create,
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-create-option',
		'clause'  => 'verdict(): a widget area, an option and the CSS are not made by a run (Nachfolger von struktur/push-create)',
		'file'    => $ow,
		'search'  => $create,
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-push-create-custom_css',
		'clause'  => 'verdict(): a widget area, an option and the CSS are not made by a run (Nachfolger von struktur/push-create)',
		'file'    => $cw,
		'search'  => $create,
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncRunTest', 'testWhichEntryOfAReportIsAStepLockedOrNothing' ),
		'expect'  => '/testWhichEntryOfAReportIsAStepLockedOrNothing/',
	),
);
