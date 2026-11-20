<?php
/**
 * The successors of the entries of the other lists whose clause the seam
 * moved out of AB_MCP_Sync_Push (first step T3, stage 1, decision of the
 * steering 18.11. after 11:16 and the answer of 11:18): the old entries stayed
 * word for word until T6, which replaced them by these. Each successor takes
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
 * Part 2a of T3 (the attachment: E7 and N-c no. 2 and 3): five successors
 * of five old entries of medien.php, each in a rule of
 * AB_MCP_Sync_Attachment_Writer.
 *
 * Part 2b of T3 (menu, post and term: E7, the parent of a term, N-c no. 5):
 * four successors of four old entries of struktur.php and inhalte.php, two
 * in rules of AB_MCP_Sync_Menu_Writer, two in rules of
 * AB_MCP_Sync_Term_Writer.
 *
 * T4 (the forward entrance of the code, B5): two successors of the two
 * entries of tausch.php on run_write() of the engine, whose lines changed
 * with the entrance; their test is the one of the old entries
 * (SyncCodeRunTest).
 *
 * Held by the unit suite (SyncRunTest), naht-nachfolger/vorlagen-theme-plan
 * by the integration suite (SyncTemplatesTest), the five of part 2a as well
 * (SyncMediaTest), of part 2b the two of the menus (SyncStructureTest) and
 * the parent of a term (SyncContentTest).
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
	array(
		'id'      => 'naht-nachfolger/medien-plan-made-attachments',
		'clause'  => 'plan(): a new attachment counts as a parent the run creates (a poster under its new video; GitHub Codex on free#140, P2) (Nachfolger von medien/plan-made-attachments)',
		'file'    => $aw,
		'search'  => "\tpublic static function made_in_posts( array \$item ) {\n\t\tunset( \$item );\n\t\treturn true;",
		'replace' => "\tpublic static function made_in_posts( array \$item ) {\n\t\tunset( \$item );\n\t\treturn false;",
		'run'     => $it( 'SyncMediaTest', 'testANewVideoWithANewPosterImageThatHangsUnderItGoesOverAndBack' ),
		'expect'  => '/testANewVideoWithANewPosterImageThatHangsUnderItGoesOverAndBack/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-plan-parent-edge',
		'clause'  => 'plan(): an attachment here comes after the new parent the run creates (Nachfolger von medien/plan-parent-edge)',
		'file'    => $aw,
		'search'  => "\t\t\t\$item['refs'][] = array( 'post', \$item['weak_parent'] );\n",
		'replace' => "/* removed */\n",
		'run'     => $it( 'SyncMediaTest', 'testAChangedTitleAltTextDescriptionAndParentOfAnAttachmentGoOverAndBackWithoutAFile' ),
		'expect'  => '/testAChangedTitleAltTextDescriptionAndParentOfAnAttachmentGoOverAndBackWithoutAFile/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-plan-parent-later',
		'clause'  => 'plan(): a new attachment under a post the run creates is made unattached first (Nachfolger von medien/plan-parent-later)',
		'file'    => $aw,
		'search'  => "\t\t\t\$item['parent_later'] = true;\n",
		'replace' => "/* removed */\n",
		'run'     => $it( 'SyncMediaTest', 'testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles' ),
		'expect'  => '/testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-plan-weak-parent',
		'clause'  => 'plan(): the weak parent of an attachment is read for its parent step (Nachfolger von medien/plan-weak-parent)',
		'file'    => $aw,
		'search'  => "\t\t\t\t\$item['weak_parent'] = (string) (int) \$ref['id'];\n",
		'replace' => "/* removed */\n",
		'run'     => $it( 'SyncMediaTest', 'testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles' ),
		'expect'  => '/testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles/',
	),
	array(
		'id'      => 'naht-nachfolger/medien-plan-shared-paths',
		'clause'  => 'plan(): two attachments of the plan naming one path are locked (Nachfolger von medien/plan-shared-paths)',
		'file'    => $aw,
		'search'  => "\t\t\tif ( count( \$ns ) < 2 ) {",
		'replace' => "\t\t\tif ( true ) {",
		'run'     => $it( 'SyncMediaTest', 'testWhatWouldReplaceShareOrLinkAFileOrIsOfATypeThisAccountMayNotUploadLocksBeforeAnythingIsWritten' ),
		'expect'  => '/testWhatWouldReplaceShareOrLinkAFileOrIsOfATypeThisAccountMayNotUploadLocksBeforeAnythingIsWritten/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-b2-takes',
		'clause'  => 'plan(): the positions a menu takes, from its form (B2) (Nachfolger von struktur/b2-takes)',
		'file'    => $mw,
		'search'  => "\t\t\$item['takes'] = self::line_positions( \$line );",
		'replace' => "/* removed */",
		'run'     => $it( 'SyncStructureTest', 'testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked' ),
		'expect'  => '/testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked/',
	),
	array(
		'id'      => 'naht-nachfolger/struktur-b2-holder',
		'clause'  => 'plan(): a menu after the menu of the run that holds a position it takes (B2) (Nachfolger von struktur/b2-holder)',
		'file'    => $mw,
		'search'  => "\t\t\t\tif ( null !== \$holder && isset( \$menus[ \$holder ] ) && \$menus[ \$holder ] !== \$n ) {",
		'replace' => "\t\t\t\tif ( false ) {",
		'run'     => $it( 'SyncStructureTest', 'testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked' ),
		'expect'  => '/testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked/',
	),
	array(
		'id'      => 'naht-nachfolger/inhalte-push-order-depth',
		'clause'  => 'order(): the terms by their depth in the tree the run leaves, ancestors first (Codex rounds 1 and 2, B4) (Nachfolger von inhalte/push-order-depth)',
		'file'    => $tw,
		'search'  => "\t\treturn array( 0, isset( \$item['depth'] ) ? (int) \$item['depth'] : 0, (int) \$n );",
		'replace' => "\t\treturn array( 0, 0, (int) \$n );",
		'run'     => $unit( 'SyncRunTest', 'testTheTermsOfARunAreWrittenFromTheRootOfTheTreeItLeavesDown' ),
		'expect'  => '/testTheTermsOfARunAreWrittenFromTheRootOfTheTreeItLeavesDown/',
	),
	array(
		'id'      => 'naht-nachfolger/inhalte-push-parent-ref',
		'clause'  => 'plan(): the parent of a term from its references, for the order (Nachfolger von inhalte/push-parent-ref)',
		'file'    => $tw,
		'search'  => "\t\t\t\$item['parent_ref'] = (string) (int) \$ref['id'];\n",
		'replace' => "/* removed */\n",
		'run'     => $it( 'SyncContentTest', 'testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell' ),
		'expect'  => '/testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell/',
	),
	array(
		'id'      => 'naht-nachfolger/tausch-engine-code-plan-code',
		'clause'  => 'run_write(): a plan of the code that does not read is never taken for a plan of the contents (Nachfolger von tausch/engine-code-plan-code)',
		'file'    => $en,
		'search'  => "\t\tif ( null !== \$unit ) {\n\t\t\treturn is_wp_error( \$unit ) ? \$unit : call_user_func( array( self::\$code, 'run' ), \$run, \$step, \$unit );",
		'replace' => "\t\tif ( is_array( \$unit ) ) {\n\t\t\treturn is_wp_error( \$unit ) ? \$unit : call_user_func( array( self::\$code, 'run' ), \$run, \$step, \$unit );",
		'run'     => $it( 'SyncCodeRunTest', 'testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing' ),
		'expect'  => '/testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing/',
	),
	array(
		'id'      => 'naht-nachfolger/tausch-engine-code-plan-refused',
		'clause'  => 'run_write(): a plan of the code that does not read refuses its step with the reason and the way (Nachfolger von tausch/engine-code-plan-refused)',
		'file'    => $en,
		'search'  => "\t\tif ( null !== \$unit ) {\n\t\t\treturn is_wp_error( \$unit ) ? \$unit : call_user_func( array( self::\$code, 'run' ), \$run, \$step, \$unit );",
		'replace' => "\t\tif ( null !== \$unit ) {\n\t\t\treturn call_user_func( array( self::\$code, 'run' ), \$run, \$step, \$unit );",
		'run'     => $it( 'SyncCodeRunTest', 'testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing' ),
		'expect'  => '/testAPlanOfTheCodeThatDoesNotReadInTheRunRefusesItsStepAndWritesNothing/',
	),
);
