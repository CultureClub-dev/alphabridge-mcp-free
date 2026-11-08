#!/usr/bin/env python3
"""Writes tests/mutation/lists/struktur.php: one entry per clause of Z8
branch 11 (heikel/z8-struktur): the structure of a site (decisions 23, 24,
25 and 27): menus and their items (AB_MCP_Sync_Menu_Writer,
AB_MCP_Sync_Menu_Item_Writer: the term of nav_menu, the positions of the
theme as fields of the theme mods, the items as wp_update_nav_menu_item()
writes them, the sanitizers ahead), widget areas (AB_MCP_Sync_Widget_Area_Writer:
the instances by the mapping of the pair, a new one by a free number, what
the area would lose, the update() of the widget ahead, the rows of the
widgets as shared rows), the Additional CSS (AB_MCP_Sync_Css_Writer: linked,
the active theme in the same version, the rule of the Customizer ahead),
core options (AB_MCP_Sync_Option_Writer: under their own name, the schema,
sanitize_option() ahead, the rewrite rules), the raw way back of a shared
row (AB_MCP_Sync_Shared_Row), the exports of the kinds and the engine (the
writer of each kind, objects named by a name, the order of the parts on the
way back, the instances a step made, the fields a new menu took back).

Every search is checked to occur exactly once in its file, and the test of
its expect to be a method of the class its run names, before the list is
written; each entry runs that test only.

    python3 tests/mutation/gen/struktur.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/struktur.php). Development
only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
MW = 'includes/sync/class-sync-menu-writer.php'
WA = 'includes/sync/class-sync-widget-area-writer.php'
CW = 'includes/sync/class-sync-css-writer.php'
OW = 'includes/sync/class-sync-option-writer.php'
SR = 'includes/sync/class-sync-shared-row.php'
EN = 'includes/sync/class-sync-engine.php'
PU = 'includes/sync/class-sync-push.php'
DK = 'includes/sync/class-sync-db-kinds.php'
WW = 'includes/tools/class-widget-writer.php'
PW = 'includes/sync/class-sync-post-writer.php'
FILEVAR = {MW: '$mw', WA: '$wa', CW: '$cw', OW: '$ow', SR: '$sr', EN: '$en', PU: '$pu', DK: '$dk', WW: '$ww', PW: '$pw', 'includes/sync/class-sync-term-writer.php': '$tw'}

RUNS = {
    'u': ('unit', 'SyncStructureWritersTest'),
    'run': ('unit', 'SyncRunTest'),
    'it': ('it', 'SyncStructureTest'),
    'itc': ('it', 'SyncContentTest'),
    'term': ('unit', 'SyncTermWriterTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('struktur/' + id_, clause, file, search, replace, run, expect))


# The tests.
U_ENGINE = 'testTheEngineWritesTheStructureByTheWriterOfEachKindAndNamesItsObjectsByAName'
U_PARTS = 'testThePartsOfAStateOfSharedRowsAreEveryFieldAndThePartsOfAKindGoBackInItsOrder'
U_OPT = 'testACoreOptionIsWrittenWithItsValueInTheIdsOfThisSiteAndAnyOtherIsLocked'
U_OPTCHK = 'testTheChecksOfAnOptionAskTheRightTheRowTheSchemaAndTheSanitizerAhead'
U_CSS = 'testOnlyTheLinkedCssOfTheActiveThemeInTheSameVersionIsChangedAndItsRuleIsAskedAhead'
U_MENU = 'testAMenuIsATermOfNavMenuWithItsPositionsFromItsFormAndWhatTheMenuScreenWouldNotWriteIsLocked'
U_POS = 'testThePositionsOfAMenuAreFieldsOfTheThemeModsThatNameTheMenuItselfAsSelf'
U_ITEM = 'testAMenuItemIsWrittenAsTheMenuScreenWritesItAndWhatItWouldNotWriteIsLocked'
U_ITEMSAN = 'testTheFieldsOfAMenuItemGoThroughWhatTheCoreWouldMakeOfThemBeforeAnythingIsWritten'
U_MAP = 'testTheInstancesOfAnAreaAreOneByTheMappingOfThePairAndANewOneKeepsItsNumberWhereItIsFree'
U_AREA = 'testWhatAnAreaWouldLoseOrCannotTellIsLockedBeforeAnythingIsWritten'
U_AREACHK = 'testTheChecksOfAnAreaAskTheRightTheRowOfEachWidgetAndItsUpdateAhead'
U_VERDICT = 'testWhichEntryOfAReportIsAStepLockedOrNothing'
U_PENDING = 'testTheEngineKnowsWhatItCreatesInBothSpacesAndNoStepWritesAKindWithoutAWriter'
IT_ITEMS = 'testAChangedMenuItemAndANewItemGoOverAndTheWayBackPutsTheChangedOnesBackAndLeavesTheNewOneAsARest'
IT_NEWMENU = 'testANewMenuAtAPositionOfTheThemeGoesOverAndTheWayBackPutsThePositionBackAndLeavesTheMenuAsARest'
IT_WIDGETS = 'testAChangedTextWidgetAndImageWidgetGoOverAndBack'
IT_INSTANCE = 'testANewInstanceMakesTheWholeAreaARestWithItsMappingAndNoLaterRunMakesItTwice'
IT_CSS = 'testChangedAdditionalCssGoesOverAndBackAndThePageShowsIt'
IT_OPTIONS = 'testChangedCoreOptionsGoOverAndBackAndThePermalinksBuildTheirRulesAnew'
IT_SHARED = 'testAFieldOfASharedRowGoesBackOnlyWhereItsBytesAreStillThoseReadAndItsCachesForgetIt'
IT_LOCKS = 'testWhatARunCannotWriteAsTheCoreWouldIsLockedWithItsKeyAndNothingOfItIsWritten'
IT_STALE = 'testAStepWritesFromTheRowsAsTheyAreAndNeverFromAStaleCacheOfThem'
IT_FILTER = 'testAWidgetFilterThatChangesAnotherInstanceAsTheStepWritesKeepsItsChangeAndMakesTheStepUnclear'
IT_CRED = 'testACredentialInTheNameOfAMenuOrOfItsPositionLandsInNoPackage'
IT_FREEPOS = 'testANewMenuAtAPositionNoMenuHoldsYetGoesOverAndTheWayBackLeavesThePositionEmptyAgain'
IT_MOVE = 'testAMenuThatTakesThePositionOfAnotherMenuOfTheRunGoesAfterItAndAnExchangeIsLocked'
IT_UNITREST = 'testAPairThatCannotBeKeptAsARestMakesTheStepUnclearWithItsWay'
IT_REVPAGE = 'testATakeOverOfAPageDeletesNoRevisionOfItWhereTheSiteKeepsANumberOfThem'
IT_NEWREST = 'testANewObjectWhosePairCannotBecomeARestOnTheWayBackLeavesItsStepUnclearWithItsWay'
IT_GUARD = 'testAnotherRequestThatWritesTheThemeModsAsANewMenuGivesItsPositionBackIsHeldOffByTheLock'
U_MOVE = 'testAMenuThatTakesThePositionOfAnotherMenuOfTheRunComesAfterItAndAnExchangeIsLocked'
U_ROWBACK = 'testTheKindReadsAgainAsItWritesAPartBackAndLeavesOneThatIsNotWhatTheStepRead'
U_TERMBACK = 'testTheRowOfATermGoesBackOnlyWhereItIsWhatTheStepWroteAndItsSlugAndParentAreFree'
U_PARTORDER = 'testTheOrderOfThePartsOfEachKindIsWhatTheTokensCover'

# ------------------------------------------------------------ core options
e('option-create', 'target_of(): an option is not made by a run', OW,
  "\t\tif ( 'update' !== $item['op'] ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:create',",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:create',", 'u', U_OPT)
e('option-own-name', 'target_of(): an option is written under its own name only', OW,
  "\t\tif ( (string) $item['live_ref'] !== $name ) {",
  "\t\tif ( false ) {", 'u', U_OPT)
e('option-core', 'target_of(): a core option with a schema only', OW,
  "\t\t$class = AB_MCP_Sync_Option_Schema::classify( $name );\n\t\tif ( 'core' !== $class['status'] ) {\n\t\t\treturn self::unclassified( $name, $class );\n\t\t}\n\t\tif ( ! isset(",
  "\t\t$class = AB_MCP_Sync_Option_Schema::classify( $name );\n\t\tif ( false ) {\n\t\t\treturn self::unclassified( $name, $class );\n\t\t}\n\t\tif ( ! isset(", 'u', U_OPT)
e('option-content', 'target_of(): no value in the package locks', OW,
  "\t\tif ( ! isset( $line['content']['row']['value'], $line['form'] ) || ! is_array( $line['form'] ) ) {",
  "\t\tif ( ! is_array( $line['form'] ) ) {", 'u', U_OPT)
e('option-refs', 'target_of(): an id without a counterpart locks', OW,
  "\t\tif ( array() !== $missing ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:reference_unmapped',",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:reference_unmapped',", 'u', U_OPT)
e('option-refs-value', 'target_of(): a list of ids written as the list it unpacks to', OW,
  "\t\t\t$value = $read['value'];",
  "", 'u', U_OPT)
e('option-right', 'precheck(): manage_options', OW,
  "\t\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the option %s (manage_options)', $name ) );\n\t\t}\n\t\t$class",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the option %s (manage_options)', $name ) );\n\t\t}\n\t\t$class", 'u', U_OPTCHK)
e('option-here', 'precheck(): an option not here is not made', OW,
  "\t\tif ( null === $current ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:create',",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'option:create',", 'u', U_OPTCHK)
e('option-schema', 'precheck(): the value of staging keeps the schema', OW,
  "\t\tif ( ! AB_MCP_Sync_Option_Schema::fits( $prepared['schema'], $prepared['columns']['value'] ) ) {",
  "\t\tif ( false ) {", 'u', U_OPTCHK)
e('option-sanitizer', 'sanitizer_refusal(): sanitize_option() ahead, its bytes as update_option() stores them', OW,
  "\t\treturn null !== $form && $form === (string) $bytes ? null : sprintf(",
  "\t\treturn true ? null : sprintf(", 'u', U_OPTCHK)
e('option-write', 'write_row(): update_option()', OW,
  "\t\tupdate_option( $name, $prepared['value'] );",
  "", 'it', IT_OPTIONS)
e('option-rewrite', 'post_write(): the rewrite rules built anew for the permalinks', OW,
  "\t\t\tflush_rewrite_rules( false );",
  "", 'it', IT_OPTIONS)
e('option-back-changed', 'restore_part(): an option changed since is left', OW,
  "\t\tif ( ! hash_equals( (string) $hash, self::row_hash( $current['row'] ) ) ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'left',\n\t\t\t\t'note'  => sprintf( 'The option %s was changed since the step wrote it, so it is left as it is.', $option ),",
  "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'left',\n\t\t\t\t'note'  => sprintf( 'The option %s was changed since the step wrote it, so it is left as it is.', $option ),", 'u', U_OPTCHK)
e('option-back-sanitizer', 'restore_part(): the value before only where sanitize_option() keeps it now', OW,
  " || ! $read['ok'] || null !== self::sanitizer_refusal( $option, $read['value'], $was ) ) {",
  " || ! $read['ok'] ) {", 'u', U_OPTCHK)
e('option-back-write', 'restore_part(): the row put back raw', OW,
  "\t\tif ( ! AB_MCP_Sync_Shared_Row::put_back( $option, (string) $current['row']['value'], $was ) ) {",
  "\t\tif ( true ) {", 'it', IT_OPTIONS)

# ------------------------------------------------------------- shared rows
e('shared-bytes', 'put_back(): only where the row holds the bytes read', SR,
  " AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( option_value ) = %d\", (string) $bytes, (string) $option, (string) $now, strlen( (string) $now ) ) );",
  " AND OCTET_LENGTH( option_value ) = %d\", (string) $bytes, (string) $option, strlen( (string) $now ) ) );", 'it', IT_SHARED)
e('shared-binary', 'put_back(): the bytes compared as bytes, not by the collation', SR,
  " AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY ) AND",
  " AND option_value = %s AND", 'it', IT_SHARED)
e('shared-forget', 'put_back(): the caches of the option forget it', SR,
  "\t\tself::forget( $option );\n\t\treturn 1 === (int) $done;",
  "\t\treturn 1 === (int) $done;", 'it', IT_SHARED)
e('shared-alloptions', 'forget(): alloptions', SR,
  "\t\twp_cache_delete( 'alloptions', 'options' );",
  "", 'it', IT_SHARED)
e('shared-hash', 'hash(): an absent field is not an empty one', SR,
  "\t\treturn null === $bytes ? hash( 'sha256', 'absent' ) : hash( 'sha256', 'field:' . (string) $bytes );",
  "\t\treturn hash( 'sha256', 'field:' . (string) $bytes );", 'u', U_POS)

# ------------------------------------------------------------ the CSS
e('css-create', 'target_of(): only CSS linked here is changed', CW,
  "\t\tif ( 'update' !== $item['op'] || (string) $item['live_ref'] !== $stylesheet ) {",
  "\t\tif ( false ) {", 'u', U_CSS)
e('css-field-rule', 'target_of(): CSS of a preprocessor or not published locks', CW,
  "\t\tif ( '' !== $row['preprocessed'] || 'publish' !== $row['post_status'] ) {",
  "\t\tif ( false ) {", 'u', U_CSS)
e('css-status', 'target_of(): CSS not published locks', CW,
  "\t\tif ( '' !== $row['preprocessed'] || 'publish' !== $row['post_status'] ) {",
  "\t\tif ( '' !== $row['preprocessed'] ) {", 'u', U_CSS)
e('css-refs', 'target_of(): an address without a counterpart locks', CW,
  "\t\tif ( array() !== $missing ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'custom_css:reference_unmapped',",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'custom_css:reference_unmapped',", 'u', U_CSS)
e('css-right', 'precheck(): edit_css', CW,
  "\t\t$stylesheet = (string) $prepared['stylesheet'];\n\t\tif ( ! current_user_can( 'edit_css' ) ) {",
  "\t\t$stylesheet = (string) $prepared['stylesheet'];\n\t\tif ( false ) {", 'u', U_CSS)
e('css-trash', 'precheck(): a CSS post in the trash is not written', CW,
  "\t\tif ( null === $current || 'trash' === $current['row']['post_status'] ) {",
  "\t\tif ( null === $current ) {", 'u', U_CSS)
e('css-active', 'theme_refusal(): the active theme', CW,
  "\t\tif ( $active !== $stylesheet ) {",
  "\t\tif ( false ) {", 'u', U_CSS)
e('css-version', 'theme_refusal(): the theme in the version of staging', CW,
  "\t\tif ( $here !== $version ) {",
  "\t\tif ( false ) {", 'u', U_CSS)
e('css-markup', 'css_refusal(): the rule of WP_Customize_Custom_CSS_Setting::validate()', CW,
  "\t\tif ( AB_MCP_Sync_Post_Writer::css_markup( $css ) ) {",
  "\t\tif ( false ) {", 'u', U_CSS)
e('css-markup-run', 'css_refusal(): the rule of validate() refuses in the check of the run', CW,
  "\t\tif ( AB_MCP_Sync_Post_Writer::css_markup( $css ) ) {",
  "\t\tif ( false ) {", 'it', IT_LOCKS)
e('css-filter', 'css_refusal(): update_custom_css_data ahead', CW,
  " || (string) $data['css'] !== $css || '' !== (string) $data['preprocessed'] ) {",
  " ) {", 'u', U_CSS)

# ------------------------------------------------------------- menus
e('menu-taxonomy', 'target_of(): a menu is a term of nav_menu', MW,
  "\t\tif ( 'nav_menu' !== $row['taxonomy'] ) {",
  "\t\tif ( false ) {", 'u', U_MENU)
e('menu-term-group', 'target_of(): no term_group', MW,
  "\t\tif ( '0' !== $row['term_group'] ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:term_group', sprintf( 'the menu of the staging site",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:term_group', sprintf( 'the menu of the staging site", 'u', U_MENU)
e('menu-parent', 'target_of(): a menu has no parent', MW,
  "\t\tif ( '0' !== $row['parent'] ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:parent', sprintf( 'the menu of the staging site",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:parent', sprintf( 'the menu of the staging site", 'u', U_MENU)
e('menu-create-meta', 'target_of(): a new menu with meta locks', MW,
  "\t\t\tif ( 'create' === $item['op'] ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:create_with_meta', sprintf( 'meta key %s of the new menu', $key ) );",
  "\t\t\tif ( false ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'term:create_with_meta', sprintf( 'meta key %s of the new menu', $key ) );", 'u', U_MENU)
e('menu-location-name', 'target_of(): a position is a name', MW,
  "\t\t\tif ( ! is_string( $location ) || '' === $location ) {",
  "\t\t\tif ( false ) {", 'u', U_MENU)
e('menu-registered', 'precheck(): a position the theme registers', MW,
  "\t\tforeach ( $prepared['locations'] as $location ) {\n\t\t\tif ( ! isset( $registered[ $location ] ) ) {",
  "\t\tforeach ( $prepared['locations'] as $location ) {\n\t\t\tif ( false ) {", 'u', U_MENU)
e('menu-registered-run', 'precheck(): a position the theme does not register refuses in the check of the run', MW,
  "\t\tforeach ( $prepared['locations'] as $location ) {\n\t\t\tif ( ! isset( $registered[ $location ] ) ) {",
  "\t\tforeach ( $prepared['locations'] as $location ) {\n\t\t\tif ( false ) {", 'it', IT_LOCKS)
e('menu-unset', 'location_targets(): a position the menu holds here and the target does not is unset', MW,
  "\t\t\tif ( 0 === strpos( (string) $name, 'location:' ) && self::SELF === $value ) {\n\t\t\t\t$out[ (string) $name ] = null;",
  "\t\t\tif ( false ) {\n\t\t\t\t$out[ (string) $name ] = null;", 'u', U_POS)
e('menu-unchanged-position', 'journal_parts(): a position whose value stays is no part', MW,
  "\t\t\tif ( $before === $target ) {\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\t$out[] = array(\n\t\t\t\t'part'   => $name,\n\t\t\t\t'way'    => self::WAY_LOCATION,",
  "\t\t\t$out[] = array(\n\t\t\t\t'part'   => $name,\n\t\t\t\t'way'    => self::WAY_LOCATION,", 'u', U_POS)
e('menu-write-refusal', 'write_part(): the position asked again right before it is set', MW,
  "\t\t$refusal  = self::location_refusal( $location, $set );\n\t\tif ( null !== $refusal ) {\n\t\t\treturn $refusal;\n\t\t}",
  "\t\t$refusal  = self::location_refusal( $location, $set );", 'u', U_POS)
e('menu-new-positions', 'with_row(): a new menu names every position it takes as a part of its row', MW,
  "\t\t\t$out[ (string) $name ] = AB_MCP_Sync_Shared_Row::hash( $value );\n\t\t}\n\t\tforeach ( array_keys( self::location_targets( $prepared, $parts ) ) as $name ) {",
  "\t\t}\n\t\tforeach ( array_keys( self::location_targets( $prepared, $parts ) ) as $name ) {", 'u', U_POS)
e('menu-back-changed', 'restore_part(): a position changed since is left', MW,
  "\t\tif ( ! hash_equals( (string) $hash, AB_MCP_Sync_Shared_Row::hash( $is ) ) || null === $positions['raw'] ) {",
  "\t\tif ( null === $positions['raw'] ) {", 'u', U_POS)
e('menu-back-write', 'restore_part(): the position put back raw in the row of the theme mods', MW,
  "\t\tif ( ! AB_MCP_Sync_Shared_Row::put_back( $positions['option'], (string) $positions['raw'], AB_MCP_Sync_Shared_Row::field( $mods ) ) ) {",
  "\t\tif ( true ) {", 'it', IT_NEWMENU)
e('menu-shared-part', 'shared_part(): a position is a field of a shared row', MW,
  "\t\treturn 0 === strpos( (string) $name, 'location:' );\n\t}",
  "\t\treturn false;\n\t}", 'it', IT_NEWMENU)

# --------------------------------------------------------- menu items
e('item-taxonomy', 'target_of(): an item is in menus of nav_menu only', MW,
  "\t\t\tif ( ! is_array( $term ) || 'nav_menu' !== AB_MCP_Sync_Post_Writer::bytes( $term['taxonomy'] ?? '' ) ) {",
  "\t\t\tif ( ! is_array( $term ) ) {", 'u', U_ITEM)
e('item-type', 'target_of(): a post of nav_menu_item', MW,
  "\t\tif ( 'nav_menu_item' !== $row['post_type'] ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape',",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape',", 'u', U_ITEM)
e('item-status', 'target_of(): publish or draft, as the core gives an item', MW,
  "\t\tif ( ! in_array( $row['post_status'], array( 'publish', 'draft' ), true ) ) {",
  "\t\tif ( false ) {", 'u', U_ITEM)
e('item-order', 'target_of(): a position from 1 on', MW,
  "\t\tif ( 1 !== preg_match( '/^[1-9][0-9]*\\z/', $row['menu_order'] ) ) {",
  "\t\tif ( false ) {", 'u', U_ITEM)
e('item-core-meta', 'target_of(): one row of each key the core writes', MW,
  "\t\t\tif ( ! isset( $meta[ $key ] ) || 1 !== count( $meta[ $key ] ) ) {",
  "\t\t\tif ( ! isset( $meta[ $key ] ) ) {", 'u', U_ITEM)
e('item-types', 'target_of(): a type the core knows', MW,
  "\t\tif ( ! in_array( $type, self::TYPES, true ) ) {",
  "\t\tif ( false ) {", 'u', U_ITEM)
e('item-one-menu', 'target_of(): an item in one menu', MW,
  "\t\tif ( 1 !== count( $menus ) ) {",
  "\t\tif ( 0 === count( $menus ) ) {", 'u', U_ITEM)
e('item-menu-mapped', 'target_of(): the menu of the item by its pair or made first', MW,
  "\t\tif ( null === $menu && ! $menu_pending ) {",
  "\t\tif ( false ) {", 'u', U_ITEM)
e('item-classes', 'target_of(): the classes a list of names', MW,
  "\t\tif ( ! $classes['ok'] || ! is_array( $classes['value'] ) || ! array_is_list( $classes['value'] ) || array() !== array_filter( $classes['value'], static fn( $c ) => ! is_string( $c ) ) ) {",
  "\t\tif ( ! $classes['ok'] || ! is_array( $classes['value'] ) ) {", 'u', U_ITEMSAN)
e('item-right-theme', 'precheck(): edit_theme_options', MW,
  "\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the menus of this site (edit_theme_options)' );\n\t\t}\n\t\t$current = null;",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the menus of this site (edit_theme_options)' );\n\t\t}\n\t\t$current = null;", 'u', U_ITEMSAN)
e('item-right-post', 'precheck(): edit_post on an item that is there', MW,
  "\t\t\tif ( ! current_user_can( 'edit_post', (int) $live_id ) ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-right-term', 'precheck(): assign_term on its menu', MW,
  "\t\t\tif ( ! current_user_can( 'assign_term', $menu ) ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-custom-object', 'sanitizer_refusal(): a custom link is of the object custom', MW,
  "\t\t\tif ( sanitize_key( (string) $core[ $key ] ) !== (string) $core[ $key ] || $stored !== (string) $core[ $key ] ) {",
  "\t\t\tif ( sanitize_key( (string) $core[ $key ] ) !== (string) $core[ $key ] ) {", 'u', U_ITEMSAN)
e('item-class-names', 'sanitizer_refusal(): the classes as sanitize_html_class() keeps them', MW,
  "\t\tif ( AB_MCP_Sync_Shared_Row::field( $classes ) !== (string) $core['_menu_item_classes'] ) {",
  "\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-xfn', 'sanitizer_refusal(): xfn as sanitize_html_class() keeps it', MW,
  "\t\tif ( implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) $f['menu-item-xfn'] ) ) ) !== (string) $core['_menu_item_xfn'] ) {",
  "\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-url', 'sanitizer_refusal(): the address as sanitize_url() keeps it', MW,
  "\t\tif ( $url !== (string) $core['_menu_item_url'] ) {",
  "\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-parent-self', 'sanitizer_refusal(): no item its own parent', MW,
  " || ( null !== $live_id && (int) $core['_menu_item_menu_item_parent'] === (int) $live_id ) ) {",
  " ) {", 'u', U_ITEMSAN)
e('item-title-object', 'sanitizer_refusal(): the title of its object is stored as none', MW,
  "\t\t\tif ( $title !== (string) $prepared['columns']['post_title'] ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-blank', 'sanitizer_refusal(): no title and no description become a description of one space', MW,
  "\t\t\tif ( '' === $title && '' === (string) $f['menu-item-description'] ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('item-dates', 'write_row(): the dates of an item here as they are', MW,
  "\t\t\t$fields['menu-item-post-date']     = (string) $row['post_date'];\n\t\t\t$fields['menu-item-post-date-gmt'] = (string) $row['post_date_gmt'];",
  "", 'u', U_ITEM)
e('item-slash', 'write_row(): the fields slashed, as the core unslashes them', MW,
  "\t\t\t$fields[ $field ] = wp_slash( (string) $fields[ $field ] );",
  "\t\t\t$fields[ $field ] = (string) $fields[ $field ];", 'u', U_ITEM)
e('item-same-id', 'write_row(): the core wrote the item it was given', MW,
  "\t\tif ( ! is_int( $id ) || $id < 1 || ( null !== $live_id && $id !== (int) $live_id ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_write_failed', 'WordPress wrote no menu item",
  "\t\tif ( ! is_int( $id ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_write_failed', 'WordPress wrote no menu item", 'u', U_ITEM)

# ------------------------------------------------------------ widget areas
e('area-create', 'target_of(): an area is not made by a run', WA,
  "\t\tif ( 'update' !== $item['op'] || (string) $item['live_ref'] !== $area ) {",
  "\t\tif ( false ) {", 'u', U_AREA)
e('area-number', 'target_of(): an instance with a number and settings', WA,
  "\t\t\tif ( '' === $base || '' === $raw ) {",
  "\t\t\tif ( false ) {", 'u', U_AREA)
e('area-registered', 'target_of(): a widget registered here', WA,
  "\t\t\tif ( null === AB_MCP_Widget_Writer::widget( $base ) ) {",
  "\t\t\tif ( false ) {", 'u', U_AREA)
e('area-remove', 'target_of(): an instance here without a counterpart would be removed: locked', WA,
  "\t\t\tif ( ! isset( $taken[ (string) $live ] ) ) {",
  "\t\t\tif ( false ) {", 'u', U_AREA)
e('area-known-alike', 'known(): the instances at the base by their place only where both lists name the same widgets', WA,
  "\t\t\t\t$alike = $alike && is_string( $id ) && is_string( $l[ $i ] ) && self::base_of( $id ) === self::base_of( $l[ $i ] );",
  "", 'u', U_MAP)
e('area-known-map', 'known(): the mapping a run made', WA,
  "\t\t\t$out[ (string) $id ] = is_string( $live ) ? $live : null;",
  "", 'u', U_MAP)
e('area-unmapped', 'counterpart(): a known instance without its counterpart locks', WA,
  "\t\t\tif ( null === $live || self::base_of( $live ) !== $base || ! isset( $state['parts'][ 'instance:' . $live ] ) || isset( $taken[ $live ] ) ) {",
  "\t\t\tif ( null === $live ) {", 'u', U_MAP)
e('area-elsewhere', 'counterpart(): a counterpart placed in another area locks', WA,
  "\t\t\tif ( null !== $elsewhere ) {",
  "\t\t\tif ( false ) {", 'u', U_MAP)
e('area-free-number', 'counterpart(): a new instance keeps its number where it is free', WA,
  "\t\tif ( $number < 2 || isset( $used[ $number ] ) ) {",
  "\t\tif ( true ) {", 'u', U_MAP)
e('area-taken-number', 'counterpart(): a number taken here gives the next free one', WA,
  "\t\tif ( $number < 2 || isset( $used[ $number ] ) ) {",
  "\t\tif ( $number < 2 ) {", 'u', U_MAP)
e('area-right', 'precheck(): edit_theme_options', WA,
  "\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );\n\t\t}\n\t\t$state",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );\n\t\t}\n\t\t$state", 'u', U_AREACHK)
e('area-sanitizer', 'sanitizer_refusal(): the update() of the widget keeps the settings', WA,
  "\t\tif ( AB_MCP_Sync_Shared_Row::field( $clean ) !== AB_MCP_Sync_Shared_Row::field( $settings ) ) {",
  "\t\tif ( false ) {", 'u', U_AREACHK)
e('area-marked', 'sanitizer_refusal(): a row the widgets of WordPress wrote', WA,
  "\t\tif ( ! isset( $state['rows'][ $base ] ) || ! $state['rows'][ $base ]['marked'] ) {",
  "\t\tif ( ! isset( $state['rows'][ $base ] ) ) {", 'u', U_AREACHK)
e('area-multiwidget', 'write_part(): the row keeps _multiwidget', WA,
  "\t\t$value['_multiwidget']           = 1;",
  "", 'u', U_AREACHK)
e('area-write-instance', 'write_part(): the instance through the update() of its widget', WA,
  "\t\t$value[ (int) $write['number'] ] = $clean;",
  "\t\t$value[ (int) $write['number'] ] = $write['settings'];", 'u', U_AREACHK)
e('area-made', 'made(): an instance not there before the step is made by it', WA,
  "\t\t\tif ( 0 === strpos( $name, 'instance:' ) && AB_MCP_Sync_Shared_Row::hash( null ) === (string) $part['before'] && is_array( $state ) && isset( $state['parts'][ $name ] ) ) {",
  "\t\t\tif ( false ) {", 'it', IT_INSTANCE)
e('area-request-copy', 'post_write(): the copy of the areas the core keeps for the request', WA,
  "\t\t$GLOBALS['_wp_sidebars_widgets'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request copy of the core, cleared as",
  "\t\t// removed: the request copy of the core, cleared as", 'u', U_AREACHK)

# ---------------------------------------------------------------- engine
e('engine-ref', 'live_of(): the object of a kind named by a name', EN,
  "\t\tif ( null !== $w && method_exists( $w, 'ref_of' ) ) {\n\t\t\treturn $w::ref_of( $raw );\n\t\t}",
  "", 'u', U_ENGINE)
e('engine-is-ref', 'is_ref(): a name is a ref', EN,
  "\t\treturn is_int( $live_id ) ? $live_id > 0 : ( is_string( $live_id ) && '' !== $live_id );",
  "\t\treturn is_int( $live_id ) && $live_id > 0;", 'u', U_ENGINE)
e('engine-part-order', 'put_back(): the parts of a kind back in its order', EN,
  "\t\tforeach ( self::part_order( $w ) as $prefix ) {",
  "\t\tforeach ( array( 'row', 'meta:', 'terms:' ) as $prefix ) {", 'it', IT_NEWMENU)
e('engine-shared-back', 'revert: the fields a new object took in shared rows go back', EN,
  "\t\t\t\t$shared = self::shared_back( $run_id, $seq, $journal, $live_id, $what );",
  "\t\t\t\t$shared = null;", 'it', IT_NEWMENU)
e('engine-made-rest', 'revert: a unit with an instance the run made is a rest', EN,
  "\t\tif ( null !== $pair_id && array() !== $made ) {",
  "\t\tif ( false ) {", 'it', IT_INSTANCE)
e('engine-pending-space', 'pending(): a new menu is pending in the space of terms', EN,
  "\t\t\t\tif ( in_array( (string) $one['kind'], $kinds, true ) ) {",
  "\t\t\t\tif ( false ) {", 'run', U_PENDING)

# ------------------------------------------- Codex round 1 of branch 11
# A1: the caches of a shared row emptied right before the core call.
e('a1-menu-forget', 'write_part(): set_theme_mod() reads the theme mods the step read (A1)', MW,
  "\t\tAB_MCP_Sync_Shared_Row::forget( $positions['option'] );\n\t\t$locations = $positions['locations'];",
  "\t\t$locations = $positions['locations'];", 'it', IT_STALE)
e('a1-css-forget', 'write_row(): wp_update_custom_css_post() finds the post by the link the step read (A1)', CW,
  "\t\tAB_MCP_Sync_Shared_Row::forget( 'theme_mods_' . $stylesheet );\n", "", 'it', IT_STALE)
e('a1-option-forget', 'write_row(): update_option() compares with the row as it is (A1)', OW,
  "\t\tAB_MCP_Sync_Shared_Row::forget( $name );\n\t\tupdate_option( $name, $prepared['value'] );",
  "\t\tupdate_option( $name, $prepared['value'] );", 'it', IT_STALE)
e('a1-areas-forget', 'write_row(): wp_set_sidebars_widgets() compares with the row as it is (A1)', WA,
  "\t\tAB_MCP_Sync_Shared_Row::forget( self::AREAS );\n\t\t$areas = AB_MCP_Sync_Shared_Row::read( self::AREAS );",
  "\t\t$areas = AB_MCP_Sync_Shared_Row::read( self::AREAS );", 'it', IT_STALE)
e('a1-widget-forget', 'widget_row(): update_option() of a widget compares with the row as it is (A1)', WA,
  "\t\tAB_MCP_Sync_Shared_Row::forget( 'widget_' . $base );\n\t\t$row = AB_MCP_Sync_Shared_Row::read( 'widget_' . $base );",
  "\t\t$row = AB_MCP_Sync_Shared_Row::read( 'widget_' . $base );", 'it', IT_STALE)
# A2: the row of a widget read after its update() and filters.
e('a2-row-after-sanitize', 'write_part(): the row read again after the update() of the widget (A2)', WA,
  "\t\t// the write no call runs a hook.\n\t\t$value = self::widget_row( $write['base'] );\n\t\tif ( is_wp_error( $value ) ) {\n\t\t\treturn $value;\n\t\t}\n\t\t$value[ (int) $write['number'] ] = $clean;",
  "\t\t// the write no call runs a hook.\n\t\t$value[ (int) $write['number'] ] = $clean;", 'it', IT_FILTER)
# A3: no revision deleted.
e('a3-keep-filter', 'keeping_revisions(): no revision of the post deleted by the call (A3)', PW,
  "\t\tadd_filter( 'wp_revisions_to_keep', $keep, PHP_INT_MAX, 2 );\n", "", 'it', IT_CSS)
e('a3-keep-back', 'restore_part(): no revision deleted on the way back (A3)', CW,
  "\t\t\t(int) $backup['row']['post_id'],\n\t\t\tstatic function () use ( $backup ) {",
  "\t\t\t0,\n\t\t\tstatic function () use ( $backup ) {", 'it', IT_CSS)
e('rev-page-forward', 'write_row(): no revision of a post here deleted by its save (the lead after Codex round 1)', PW,
  "\t\t\t$id = self::keeping_revisions(\n\t\t\t\t(int) $live_id,", "\t\t\t$id = self::keeping_revisions(\n\t\t\t\t0,", 'itc', IT_REVPAGE)
e('rev-page-back', 'restore_row(): nor by the row put back', PW,
  "\t\t$id                     = self::keeping_revisions(\n\t\t\t(int) $live_id,", "\t\t$id                     = self::keeping_revisions(\n\t\t\t0,", 'itc', IT_REVPAGE)
e('rev-item-forward', 'write_row(): no revision of a menu item here deleted by its save', MW,
  "AB_MCP_Sync_Post_Writer::keeping_revisions(\n\t\t\t(int) $live_id,\n\t\t\tstatic function () use ( $menu, $live_id, $fields ) {",
  "AB_MCP_Sync_Post_Writer::keeping_revisions(\n\t\t\t0,\n\t\t\tstatic function () use ( $menu, $live_id, $fields ) {", 'it', IT_ITEMS)
e('rev-item-back', 'restore_part(): nor by the row of an item put back', MW,
  "\t\t\t$done = AB_MCP_Sync_Post_Writer::keeping_revisions(\n\t\t\t\t$id,", "\t\t\t$done = AB_MCP_Sync_Post_Writer::keeping_revisions(\n\t\t\t\t0,", 'it', IT_ITEMS)
e('a3-keep-limit', 'keeping_revisions(): only where the site keeps a number, never revisions where it keeps none (A3)', PW,
  " && (int) $num > 0 ? -1 : $num;", " ? -1 : $num;", 'u', U_CSS)
e('a3-keep-post', 'keeping_revisions(): only the post of the step (A3)', PW,
  "is_object( $post ) && (int) $post->ID === (int) $post_id && ", "is_object( $post ) && ", 'u', U_CSS)
e('a3-keep-goes', 'keeping_revisions(): the filter goes again, also after an error (A3)', PW,
  "\t\t\tremove_filter( 'wp_revisions_to_keep', $keep, PHP_INT_MAX );\n", "", 'u', U_CSS)
# A4: what the content carries beyond the form through the credential check.
e('a4-item-menu-name', 'item(): the names and slugs of the menus of an item through the credential check (A4)', DK,
  "\t\t\t\t\tAB_MCP_Sync_Credentials::note( $acc, 'menu_item', 'term:' . $term['taxonomy'] . ':' . $field, AB_MCP_Sync_Credentials::unit( '', (string) $term[ $field ] ) );\n",
  "", 'it', IT_CRED)
e('a4-menu-location-name', 'more(): the names of the positions of a menu through the credential check (A4)', DK,
  "\t\t\t\tAB_MCP_Sync_Credentials::note( $acc, 'menu', 'locations', AB_MCP_Sync_Credentials::unit( '', (string) $location ) );\n",
  "", 'it', IT_CRED)
# A5: the rights again right before each write.
e('a5-widget-forward', 'write_part(): edit_theme_options again after the update() of the widget (A5)', WA,
  "\t\t// branch 11, A5: they may have changed it), before the last read.\n\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {",
  "\t\t// branch 11, A5: they may have changed it), before the last read.\n\t\tif ( false ) {", 'u', U_AREACHK)
e('a5-widget-back', 'right_now(): edit_theme_options again right before a field of an area goes back (A5)', WA,
  "\t\treturn current_user_can( 'edit_theme_options' ) ? null : array(",
  "\t\treturn true ? null : array(", 'u', U_AREACHK)
e('a5-widget-back-list', 'restore_part(): the list asks it right before it goes back (A5)', WA,
  "\t\t\t$right        = self::right_now();\n\t\t\tif ( null !== $right ) {\n\t\t\t\treturn $right;\n\t\t\t}\n",
  "", 'u', U_AREACHK)
e('a5-option-back', 'restore_part(): manage_options and the class of the name again right before the option goes back (A5)', OW,
  "\t\tif ( true !== $right ) {", "\t\tif ( false ) {", 'u', U_OPTCHK)
e('a5-item-back', 'restore_part(): the rights at the item again right before its row goes back (A5)', MW,
  "\t\t\tif ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $id ) ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('a5-position-back', 'restore_part(): edit_theme_options again right before a position goes back (A5)', MW,
  "\t\t$mods['nav_menu_locations'] = $locations;\n\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {",
  "\t\t$mods['nav_menu_locations'] = $locations;\n\t\tif ( false ) {", 'u', U_POS)
e('a5-css-back', 'restore_part(): edit_css again right before the CSS goes back (A5)', CW,
  "\t\tif ( ! current_user_can( 'edit_css' ) ) {\n\t\t\treturn array(",
  "\t\tif ( false ) {\n\t\t\treturn array(", 'u', U_CSS)
# B1: a new menu at a position no menu holds yet.
e('b1-free-position', 'with_row(): a new menu names the positions it sets that no menu holds yet (B1)', MW,
  "\t\t\tif ( ! isset( $out[ $name ] ) ) {\n\t\t\t\t$out[ $name ] = AB_MCP_Sync_Shared_Row::hash( null );",
  "\t\t\tif ( false ) {\n\t\t\t\t$out[ $name ] = AB_MCP_Sync_Shared_Row::hash( null );", 'it', IT_FREEPOS)

# B2: positions between the menus of a run.
e('b2-takes', 'plan(): the positions a menu takes, from its form (B2)', PU,
  "\t\t\t\t$item['takes'] = AB_MCP_Sync_Menu_Writer::line_positions( $line );", "", 'it', IT_MOVE)
e('b2-holder', 'plan(): a menu after the menu of the run that holds a position it takes (B2)', PU,
  "\t\t\t\tif ( null !== $holder && isset( $menus[ $holder ] ) && $menus[ $holder ] !== $n ) {",
  "\t\t\t\tif ( false ) {", 'it', IT_MOVE)
e('b2-after', 'order(): the menu that gives the position up first (B2)', PU,
  "\t\t\t\t\t$items[ $n ]['after'][] = $m;\n\t\t\t\t\t$exchange[ $n ][ $m ]   = true;",
  "\t\t\t\t\t$exchange[ $n ][ $m ]   = true;", 'run', U_MOVE)
e('b2-exchange', 'order(): menus that take each other\'s positions are locked (B2)', PU,
  "\t\t\t\t\t$items[ $n ]['after'][] = $m;\n\t\t\t\t\t$exchange[ $n ][ $m ]   = true;",
  "\t\t\t\t\t$items[ $n ]['after'][] = $m;", 'run', U_MOVE)
e('b2-held', 'held_positions(): the menu each position names here (B2)', MW,
  "\t\t\tif ( null !== $id && $id > 0 ) {\n\t\t\t\t$out[ (string) $location ] = $id;",
  "\t\t\tif ( false ) {\n\t\t\t\t$out[ (string) $location ] = $id;", 'it', IT_MOVE)
# C1: the saves of a unit as a rest.
e('c1-unit-rest', 'run_post(): a pair that did not become a rest makes the step unclear (C1)', EN,
  "\t\t\t\tif ( is_wp_error( $kept ) ) {\n\t\t\t\t\t$outcome['state']    = 'unclear';",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\t$outcome['state']    = 'unclear';", 'it', IT_UNITREST)
e('c1-set-state', 'unit_rest(): the answer of set_state() (C1)', EN,
  "\t\treturn is_wp_error( $set ) ? $set : true;\n\t}\n\n\t/**\n\t * What a step says",
  "\t\treturn true;\n\t}\n\n\t/**\n\t * What a step says", 'it', IT_UNITREST + ' with data set "the state residual"')
e('c1-rebase', 'unit_rest(): the answer of rebase() (C1)', EN,
  "\t\t\tif ( is_wp_error( $rebased ) ) {\n\t\t\t\treturn $rebased;\n\t\t\t}",
  "\t\t\tif ( false ) {\n\t\t\t\treturn $rebased;\n\t\t\t}", 'it', IT_UNITREST + ' with data set "the new bases"')
e('c1-new-rest', 'revert_boundary(): a new object whose pair did not become residual leaves its step unclear (the lead after round 1)', EN,
  "\t\t\tif ( is_wp_error( $kept ) ) {\n\t\t\t\t$state              = 'unclear';",
  "\t\t\tif ( false ) {\n\t\t\t\t$state              = 'unclear';", 'it', IT_NEWREST)
# A6 (Codex round 2): the rights first, then the last read, then the write.
e('a6-widget-order', 'write_part(): the row read after the hooks of the right, right before the write (A6)', WA,
  "\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );\n\t\t}\n\t\t// The row read again after the update() of the widget, its filters\n\t\t// and the hooks of the right (A2; Codex round 2, A6): what they\n\t\t// changed in it (another instance) stays as they left it, and the\n\t\t// engine reads that change as another hand's; between this read and\n\t\t// the write no call runs a hook.\n\t\t$value = self::widget_row( $write['base'] );\n\t\tif ( is_wp_error( $value ) ) {\n\t\t\treturn $value;\n\t\t}\n",
  "\t\t$value = self::widget_row( $write['base'] );\n\t\tif ( is_wp_error( $value ) ) {\n\t\t\treturn $value;\n\t\t}\n\t\tif ( ! current_user_can( 'edit_theme_options' ) ) {\n\t\t\treturn AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );\n\t\t}\n", 'u', U_AREACHK)
e('a6-css-order', 'restore_part(): edit_css before the row is read and compared (A6)', CW,
  "\t\t// The right first (A5), then the row read and compared, then the\n\t\t// write, no call that runs a hook in between (Codex round 2, A6).\n\t\tif ( ! current_user_can( 'edit_css' ) ) {",
  "\t\t// The right first (A5), then the row read and compared, then the\n\t\t// write, no call that runs a hook in between (Codex round 2, A6).\n\t\tif ( false ) {", 'u', U_CSS)
e('a6-item-last-read', 'restore_part(): the last read of an item right before its row goes back (A6)', MW,
  "\t\t\tif ( ! is_array( $again ) || ! hash_equals( (string) $hash, self::row_hash( $again['row'] ) ) ) {",
  "\t\t\tif ( false ) {", 'u', U_ITEMSAN)
e('a6-post-last-read', 'restore_row(): the last read of a post right before its row goes back (A6)', PW,
  "\t\tif ( ! is_array( $again ) || ! hash_equals( $written, self::row_hash( $again['row'] ) ) ) {",
  "\t\tif ( false ) {", 'run', U_ROWBACK)
e('a6-term-last-read', 'restore_row(): the last read of a term right before its row goes back (A6)', 'includes/sync/class-sync-term-writer.php',
  "\t\tif ( ! is_array( $again ) || ! hash_equals( (string) $written, self::row_hash( $again['row'] ) ) ) {",
  "\t\tif ( false ) {", 'term', U_TERMBACK)
# C2: the order of the parts of each kind.
e('c2-part-order', 'part_order(): the order of the parts of a kind (C2)', EN,
  "\t\treturn null !== $w && defined( $w . '::PART_ORDER' ) ? $w::PART_ORDER : array( 'row', 'meta:', 'terms:' );",
  "\t\treturn array( 'row', 'meta:', 'terms:' );", 'run', U_PARTORDER)
# C3: the lock over the theme mods as the positions of a new menu go back.
e('c3-guard-options', 'shared_back(): the takeover lock covers the options of the writer (C3)', EN,
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], isset( $covers['options'] ) ? $covers['options'] : array(), $covers['terms'] );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn $left(",
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], array(), $covers['terms'] );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn $left(", 'it', IT_GUARD)

# ------------------------------------------------------------------ plan
e('push-structure', 'verdict(): the kinds of the structure are items', PU,
  "\t\t} elseif ( in_array( $entry['kind'], self::STRUCTURE, true ) ) {",
  "\t\t} elseif ( false ) {", 'run', U_VERDICT)
e('push-draft', 'verdict(): no structure as draft', PU,
  "\t\t\tif ( $as_draft ) {\n\t\t\t\treturn $lock( sprintf( 'A %s has no status draft: leave it out of as_draft.', (string) $entry['kind'] ) );",
  "\t\t\tif ( false ) {\n\t\t\t\treturn $lock( sprintf( 'A %s has no status draft: leave it out of as_draft.', (string) $entry['kind'] ) );", 'run', U_VERDICT)
e('push-create', 'verdict(): a widget area, an option and the CSS are not made by a run', PU,
  "\t\t\tif ( 'create' === $action && in_array( $entry['kind'], array( 'widget_area', 'option', 'custom_css' ), true ) ) {",
  "\t\t\tif ( false ) {", 'run', U_VERDICT)


def test_of(expect):
    return expect.split(' with data set "', 1)[0]


ids = set()
problems = []
for (id_, clause, file, search, replace, run, expect) in entries:
    if id_ in ids:
        problems.append('duplicate id ' + id_)
    ids.add(id_)
    text = open(os.path.join(ROOT, file)).read()
    n = text.count(search)
    if n != 1:
        problems.append('%s: search occurs %d times in %s:\n%s' % (id_, n, file, search))
    if search == replace:
        problems.append(id_ + ': the replacement is the search')
    if run not in RUNS:
        problems.append(id_ + ': no run ' + run)
        continue
    suite, cls = RUNS[run]
    path = os.path.join(ROOT, 'tests', 'integration' if 'it' == suite else '', cls + '.php')
    if not re.search(r'\bfunction\s+' + re.escape(test_of(expect)) + r'\s*\(', open(path).read()):
        problems.append('%s: %s is no test of %s' % (id_, test_of(expect), path))
if problems:
    sys.exit('\n'.join(problems))


def php(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('\t', '\\t').replace('\n', '\\n') + '"'


def php_single(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def regex(name):
    q = lambda t: re.sub(r'([\\/.^$|?*+()\[\]{}#])', r'\\\1', t)
    return '/' + q(name) + '/'


out = []
out.append('''<?php
/**
 * The clauses of Z8 branch 11 (heikel/z8-struktur): the structure of a site
 * (decisions 23, 24, 25 and 27): menus and their items (the term of
 * nav_menu, the positions of the theme as fields of the theme mods, the
 * items as wp_update_nav_menu_item() writes them, the sanitizers ahead),
 * widget areas (the instances by the mapping of the pair, a new one by a
 * free number, what the area would lose, the update() of the widget ahead),
 * the Additional CSS (linked, the active theme in the same version, the rule
 * of the Customizer ahead), core options (their own name, the schema,
 * sanitize_option() ahead, the rewrite rules), the raw way back of a shared
 * row, and the engine and the plan (the writer of each kind, objects named
 * by a name, the order of the parts on the way back, the instances a step
 * made, the positions a new menu took back).
 *
 * Written by tests/mutation/gen/struktur.py from the source; change the
 * entries there and run it again.
 *
 * Held by the integration suite (SyncStructureTest; the sites running,
 * AB_IT_ROOT set) and the unit suite (SyncStructureWritersTest,
 * SyncRunTest). Not listed: the texts of refusals, and what
 * GRENZEN-STRUKTUR.md names as not listed.
 *
 * @package AlphaBridge_MCP
 */

$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
''')
for f, v in FILEVAR.items():
    out.append('%s = %s;' % (v, php_single(f)))
out.append('')
out.append('return array(')
for (id_, clause, file, search, replace, run, expect) in entries:
    out.append('\tarray(')
    out.append("\t\t'id'      => %s," % php_single(id_))
    out.append("\t\t'clause'  => %s," % php_single(clause))
    out.append("\t\t'file'    => %s," % FILEVAR[file])
    out.append("\t\t'search'  => %s," % php(search))
    out.append("\t\t'replace' => %s," % php(replace))
    suite, cls = RUNS[run]
    out.append("\t\t'run'     => $%s( %s, %s )," % (suite, php_single(cls), php_single(test_of(expect))))
    out.append("\t\t'expect'  => %s," % php_single(regex(expect)))
    out.append('\t),')
out.append(');')
open(os.path.join(ROOT, 'tests/mutation/lists/struktur.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
