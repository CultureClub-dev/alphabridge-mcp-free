#!/usr/bin/env python3
"""Writes tests/mutation/lists/lauf.php: one entry per clause of the run of
the takeover (Z8 branch 8: wp_site_push and wp_site_revert, the engine, the
write boundary, the lock of a boundary, the means, the retention of the
backups). Every search is checked to occur exactly once in its file, and the
test of its expect to be a method of the class its run names, before the
list is written; each entry runs that test only.

    python3 tests/mutation/gen/lauf.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
PU = 'includes/sync/class-sync-push.php'
RV = 'includes/sync/class-sync-revert.php'
EN = 'includes/sync/class-sync-engine.php'
PW = 'includes/sync/class-sync-post-writer.php'
GU = 'includes/sync/class-sync-guard.php'
RS = 'includes/sync/class-sync-resources.php'
RU = 'includes/sync/class-sync-run.php'
SC = 'includes/sync/class-sync-schema.php'
TS = 'includes/tools/class-tools-staging.php'
G = 'includes/class-staging-gate.php'
DK = 'includes/sync/class-sync-db-kinds.php'
FILEVAR = {PU: '$pu', RV: '$rv', EN: '$en', PW: '$pw', GU: '$gu', RS: '$rs', RU: '$ru', SC: '$sc', TS: '$ts', G: '$g', DK: '$dk'}

RUNS = {
    'unit': ('unit', 'SyncRunTest'),
    'gate': ('unit', 'StagingGateTest'),
    'it': ('it', 'SyncRunTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('lauf/' + id_, clause, file, search, replace, run, expect))


def off(search):
    """The condition of an if taken out: if ( false ) {."""
    return re.sub(r'^(\s*)(\}\s*else)?(if|elseif) \(.*\) \{', lambda m: m.group(1) + (m.group(2) or '') + m.group(3) + ' ( false ) {', search, count=1)


# ------------------------------------------------------------- the tools
REG = 'testBothToolsAreRegisteredLikeTheOtherToolsOfTheTakeover'
WHEN = 'testOnlyPlanAsksForTheToken'
e('push-when', 'push_when(): a strict false for next, step, status and abort only (decision 15)', TS,
  "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'step', 'status', 'abort' ), true ) );", "\t\treturn true;", 'unit', WHEN)
e('revert-when', 'revert_when(): a strict false for next and status only', TS,
  "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'status' ), true ) );", "\t\treturn true;", 'unit', WHEN)
e('tool-when', 'AB_MCP_Sync_Push::when(): the same rule in the class', PU,
  "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'step', 'status', 'abort' ), true ) );", "\t\treturn true;", 'unit', WHEN)
e('gate-ways-back', 'WAYS_BACK: wp_site_revert is a way back the gate never holds', G,
  "\tconst WAYS_BACK  = array( 'wp_undo', 'wp_undo_discard', 'wp_set_preview_protection', 'wp_clear_cache', 'wp_site_revert' );",
  "\tconst WAYS_BACK  = array( 'wp_undo', 'wp_undo_discard', 'wp_set_preview_protection', 'wp_clear_cache' );", 'unit', REG)
e('push-dangerous', 'wp_site_push: Mighty', TS,
  "\t\t\t\t'destructive' => true,\n\t\t\t\t'dangerous'   => true,\n\t\t\t\t'inputSchema' => array(\n\t\t\t\t\t'type'       => 'object',\n\t\t\t\t\t'properties' => array(\n\t\t\t\t\t\t'action'    => array(\n\t\t\t\t\t\t\t'type'        => 'string',\n\t\t\t\t\t\t\t'enum'        => array( 'plan', 'next', 'step', 'status', 'abort' ),",
  "\t\t\t\t'destructive' => true,\n\t\t\t\t'dangerous'   => false,\n\t\t\t\t'inputSchema' => array(\n\t\t\t\t\t'type'       => 'object',\n\t\t\t\t\t'properties' => array(\n\t\t\t\t\t\t'action'    => array(\n\t\t\t\t\t\t\t'type'        => 'string',\n\t\t\t\t\t\t\t'enum'        => array( 'plan', 'next', 'step', 'status', 'abort' ),", 'unit', REG)

# ------------------------------------------------------------- the means
MEANS = 'testTheMeansAreCheckedPerCriterionAndOneInsufficientRefusesWithTheTwoWays'
e('means-reserve', 'check(): the booking with the reserve of 20 % and the test write', RS,
  "\t\t$needed   = (int) ceil( max( 0, (int) $bytes ) * ( 1 + self::RESERVE ) ) + AB_MCP_Store_Budget::TEST_WRITE;", "\t\t$needed   = max( 0, (int) $bytes ) + AB_MCP_Store_Budget::TEST_WRITE;", 'unit', MEANS)
e('means-settable', 'check(): a limit set_time_limit() raises is tight at most', RS,
  "\t\t\tif ( self::INSUFFICIENT === $level && $m['time_limit_settable'] ) {", "\t\t\tif ( false ) {", 'unit', MEANS)
e('means-time-level', 'check(): the time limit against MIN_TIME', RS,
  "\t\t\t$level = self::level( (float) $limit, (float) self::MIN_TIME );", "\t\t\t$level = self::OK;", 'unit', MEANS)
e('means-memory', 'check(): the memory limit against MIN_MEMORY', RS,
  "\t\t\t$criteria[] = self::criterion( 'memory', $memory, self::MIN_MEMORY, self::level( (float) $memory, (float) self::MIN_MEMORY ), sprintf( 'Raise the PHP memory limit (memory_limit) to at least %s (today %s).', self::human( self::MIN_MEMORY ), self::human( $memory ) ) );",
  "\t\t\t$criteria[] = self::criterion( 'memory', $memory, self::MIN_MEMORY, self::OK, '' );", 'unit', MEANS)
e('means-space-unknown', 'check(): free space PHP cannot ask is insufficient', RS,
  "\t\tif ( null === $free ) {\n\t\t\t$criteria[] = self::criterion( 'space', 'unknown', $needed, self::INSUFFICIENT,", "\t\tif ( null === $free ) {\n\t\t\t$criteria[] = self::criterion( 'space', 'unknown', $needed, self::OK,", 'unit', MEANS)
e('means-tempo', 'check(): a projected duration over TEMPO_MINUTES is tight', RS,
  "$projected > self::TEMPO_MINUTES * 60 ? self::TIGHT_LEVEL : self::OK", "self::OK", 'unit', MEANS)
e('means-two-ways', 'refusal(): the second way, the live site with the protection as before (15.11)', RS,
  "2. Work directly on the live site with the protection as before (undo, confirmation): switch «Staging first» off for this site on the page AlphaBridge → Staging, or answer the question of «Staging first» with staging_acknowledged.",
  "Call again later.", 'unit', MEANS)
e('means-level-tight', 'level(): under TIGHT times the need is tight', RS,
  "\t\treturn $have < $need * self::TIGHT ? self::TIGHT_LEVEL : self::OK;", "\t\treturn self::OK;", 'unit', 'testTheSizesOfPhpAndTheLevels')

# ------------------------------------------------------------ the boundary
LOCK = 'testTheLockOfABoundaryRefusesOtherWritersOfACoveredObjectAndLetsTheOwnerPass'
HOLDING = "\t\tif ( self::$owner ) {\n\t\t\treturn null;\n\t\t}\n\t\t$row = AB_MCP_Sync_Rows::option( self::OPTION );"
e('guard-owner', 'holding(): the owner of the boundary passes', GU, HOLDING, "\t\t$row = AB_MCP_Sync_Rows::option( self::OPTION );", 'unit', LOCK)
e('guard-until', 'holding(): a record past its time holds nothing', GU,
  "\t\tif ( ! self::shaped( $read ) || (int) $read['until'] <= self::now() ) {", "\t\tif ( ! self::shaped( $read ) ) {", 'unit', LOCK)
e('guard-fresh-read', 'holding(): the record read afresh from its row, past the caches of the request (A1)', GU,
  "\t\t$row = AB_MCP_Sync_Rows::option( self::OPTION );", "\t\t$row = get_option( self::OPTION ) ? array( 'value' => serialize( get_option( self::OPTION ) ) ) : false;", 'unit', LOCK)
e('guard-armed-always', 'arm(): the filters hooked on every request, a record or none (A1)', GU,
  "\t\tadd_action( 'rest_api_init', array( __CLASS__, 'arm_rest' ), 1, 0 );",
  "\t\tif ( null === self::holding() ) {\n\t\t\treturn;\n\t\t}\n\t\tadd_action( 'rest_api_init', array( __CLASS__, 'arm_rest' ), 1, 0 );", 'unit', LOCK)
MARKED = 'testTheFiltersOfTheLockAreHookedOnlyOnASiteThatHasHadARun'
e('guard-mark-gate', 'arm(): the filters are hooked on a site that has had a run only (N3)', GU,
  "\t\tif ( self::$owner || ! self::guarded() ) {", "\t\tif ( self::$owner ) {", 'unit', MARKED)
e('guard-mark-derived', 'guarded(): without a mark, a run in the table marks the site as had (N3)', GU,
  "\t\t\t$on = null !== $row;", "\t\t\t$on = false;", 'unit', MARKED)
e('guard-mark-unreadable', 'guarded(): a table of the runs that cannot be read hooks the filters, fail closed (N3)', GU,
  "\t\t\tif ( '' !== (string) $wpdb->last_error ) {\n\t\t\t\treturn true;", "\t\t\tif ( '' !== (string) $wpdb->last_error ) {\n\t\t\t\treturn false;", 'unit', MARKED)
e('guard-mark-autoload', 'mark(): the mark is autoloaded, read with the options of every request (N3)', GU,
  "\t\t\tupdate_option( self::MARK, $value, true );", "\t\t\tupdate_option( self::MARK, $value, false );", 'unit', MARKED)
e('guard-begin-marks', 'begin(): a boundary marks the site (N3)', GU,
  "\t\tself::$owner = true;\n\t\tself::mark();\n", "\t\tself::$owner = true;\n", 'unit', MARKED)
e('guard-record-expiry', 'record(): a record past its time is removed', GU,
  "\t\tif ( (int) $read['until'] <= self::now() ) {", "\t\tif ( false ) {", 'unit', LOCK)
e('guard-rest-423', 'rest_pre_insert(): the status 423 of a locked object', GU,
  "\t\t\treturn new WP_Error( self::CODE, self::message( $record ), array( 'status' => 423 ) );", "\t\t\treturn new WP_Error( self::CODE, self::message( $record ), array( 'status' => 400 ) );", 'unit', LOCK)
e('guard-empty-content', 'empty_content(): the classic way of a covered post meets the core text', GU,
  "\t\treturn $id > 0 && self::covers( $id ) ? true : $maybe_empty;", "\t\treturn $maybe_empty;", 'unit', LOCK)
e('guard-meta-mid', 'meta_by_mid(): a meta id is refused by its post', GU,
  "\t\treturn is_object( $meta ) && isset( $meta->post_id ) && isset( $record['posts'][ (string) (int) $meta->post_id ] ) ? false : $check;", "\t\treturn $check;", 'unit', LOCK)
e('guard-delete-all', 'meta_delete(): a delete of a key across every post is refused while a boundary covers one (A1)', GU,
  "\t\tif ( $delete_all ) {\n\t\t\t$record = self::holding();", "\t\tif ( false ) {\n\t\t\t$record = self::holding();", 'unit', LOCK)
e('guard-edit-lock', 'begin(): the edit lock of wp-admin on a covered post (decision 19)', GU,
  "\t\t\tupdate_post_meta( (int) $id, '_edit_lock', self::now() . ':' . (int) get_current_user_id() );", '', 'unit', LOCK)
e('guard-option-still', 'pre_update_option(): a covered option keeps its value', GU,
  "\t\treturn null !== $record && in_array( (string) $option, (array) $record['options'], true ) ? $old_value : $value;", "\t\treturn $value;", 'unit', LOCK)
e('guard-option-excluded', 'pre_update_option(): an option of group B is answered without a read of the record', GU,
  "\t\tif ( self::$owner || AB_MCP_Sync_Exclusions::option( (string) $option ) ) {", "\t\tif ( self::$owner ) {", 'unit', LOCK)
e('guard-term', 'pre_insert_term(): a new term of a covered taxonomy is refused', GU,
  "\t\tif ( null !== $record && in_array( (string) $taxonomy, (array) $record['taxonomies'], true ) ) {", "\t\tif ( false ) {", 'unit', LOCK)
e('guard-rest-hook', 'arm_rest(): the REST API of every registered type is hooked (another user sees the message)', GU,
  "\t\t\tadd_filter( 'rest_pre_insert_' . $type, array( __CLASS__, 'rest_pre_insert' ), 1, 2 );", '', 'it', 'testTheLockOfABoundaryRefusesOtherWritersAndLetsTheOwnerPass')

# ------------------------------------------------------- the kind post
META = 'testTheRuleOfThisVersionForMetaKeys'
TARGET = 'testTheTargetOfALineAndTheConfirmedChoice'
LOCKS = 'testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock'
e('writer-meta-credential', 'meta_allowed(): the name of a credential is no key of this version', PW,
  " || AB_MCP_Sync_Credentials::names_credential( $key, AB_MCP_Redactor::extra_keys() ) ) {", " ) {", 'unit', META)
e('writer-meta-builder', 'meta_allowed(): the markup of a page builder is no key of this version', PW,
  "\t\treturn ! AB_MCP_Builders::is_markup_meta_key( $key );", "\t\treturn true;", 'unit', META)
e('writer-as-draft-status', 'target_of(): the confirmed choice makes the status draft (decision 21)', PW,
  "\t\tif ( ! empty( $item['as_draft'] ) ) {\n\t\t\t$status = 'draft';", "\t\tif ( false ) {\n\t\t\t$status = 'draft';", 'unit', TARGET)
e('writer-as-draft-target', 'target_of(): the target carries the choice (decision 17, Sollwert)', PW,
  "\t\t\t$target['row']['post_status'] = 'draft';", '', 'unit', TARGET)
e('writer-gmt-derived', 'target_of(): the UTC date WordPress derives for a published post without one', PW,
  "\t\tif ( ( '' === $columns['post_date_gmt'] || '0000-00-00 00:00:00' === $columns['post_date_gmt'] ) && ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {", "\t\tif ( false ) {", 'unit', TARGET)
e('writer-parent-map', 'target_of(): the parent by its pair', PW,
  "\t\t\t$parent = $mapped;", '', 'unit', TARGET)
e('writer-terms-map', 'target_of(): the terms by their pairs', PW,
  "\t\t\t$terms[ $taxonomy ][] = (int) $mapped;", "\t\t\t$terms[ $taxonomy ][] = (int) $term['term_id'];", 'unit', TARGET)
e('writer-password', 'target_of(): a password-protected post is refused (B9)', PW,
  "\t\tif ( '' !== $row['post_password'] ) {", "\t\tif ( false ) {", 'unit', LOCKS)
e('writer-create-meta', 'target_of(): a new post with plain meta is locked (decision 28)', PW,
  "\t\t\tif ( 'create' === $item['op'] && ! in_array( $key, self::CORE_META, true ) ) {", "\t\t\tif ( false ) {", 'unit', LOCKS)
e('writer-parent-unmapped', 'target_of(): a parent without a pair is refused', PW,
  "\t\t\tif ( null === $mapped ) {\n\t\t\t\treturn self::locked( 'post:reference_unmapped', sprintf( 'post_parent %d of the staging site', $parent ) );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'post:reference_unmapped', sprintf( 'post_parent %d of the staging site', $parent ) );", 'unit', LOCKS)
e('writer-type', 'target_of(): only posts and pages in this version', PW,
  "\t\tif ( ! in_array( $type, self::TYPES, true ) ) {", "\t\tif ( false ) {", 'unit', LOCKS)
e('writer-status', 'target_of(): the trash and an auto-draft are no target', PW,
  "\t\tif ( ! in_array( $status, self::STATUSES, true ) ) {", "\t\tif ( false ) {", 'unit', LOCKS)
e('writer-taxonomy', 'target_of(): a taxonomy outside the rule is locked', PW,
  "\t\t\tif ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {", "\t\t\tif ( false ) {", 'unit', LOCKS)
e('writer-terms-set', 'terms_hash(): a set', PW,
  "\t\t$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );\n\t\tsort( $keys, SORT_STRING );", "\t\t$keys = array_values( array_map( 'strval', $keys ) );", 'unit', 'testTheHashesOfThePartsAreStableAndTermsAreASet')
e('writer-format-slug', 'term_keys(): a format term by its slug (B6)', PW,
  "\t\t\t\t$out[] = $slugs ? (string) $term['slug'] : (int) $term['term_id'];", "\t\t\t\t$out[] = (int) $term['term_id'];", 'unit', 'testTheHashesOfThePartsAreStableAndTermsAreASet')
e('writer-format-target', 'target_term_keys(): the slug of a format as the target names it (B6)', PW,
  "\t\t\t$out[] = $slugs ? (string) $term : (int) $term;", "\t\t\t$out[] = (int) $term;", 'unit', 'testTheHashesOfThePartsAreStableAndTermsAreASet')
e('writer-page-template', 'target_of(): the template through page_template of wp_insert_post() (decisions 17, 28; B7)', PW,
  "\t\t\t$postarr['page_template'] = $meta['_wp_page_template'][0];", '', 'unit', TARGET)
e('writer-template-offered', 'precheck(): the theme of this site has to offer the template (B7)', PW,
  "\t\t\tif ( ! is_array( $templates ) || ! isset( $templates[ $prepared['postarr']['page_template'] ] ) ) {", "\t\t\tif ( false ) {", 'unit', 'testTheTemplateOfAPageGoesThroughTheCoreWayAndTheThemeHasToOfferIt')
e('writer-pending-parent', 'target_of(): a parent the run creates before the object is pending, not missing (B1)', PW,
  "\t\t\tif ( null === $mapped && isset( $pending['post'][ $parent ] ) ) {", "\t\t\tif ( false ) {", 'unit', LOCKS)
e('writer-pending-top', 'precheck(): a page with a pending parent is no page of the top level (B1)', PW,
  "\t\t$top = 0 === (int) $prepared['postarr']['post_parent'] && empty( $prepared['pending'] );", "\t\t$top = 0 === (int) $prepared['postarr']['post_parent'];", 'it', 'testANewParentPageAndItsNewChildPageAreWrittenInOneRun')
e('writer-restore-menu', 'restore_row(): a page back to publish at the top level while a menu adds pages is «check needed» (decisions 21, 23; A3)', PW,
  "\t\tif ( 'page' === $backup['post_type'] && 0 === (int) $backup['post_parent'] && in_array( $backup['post_status'], array( 'publish', 'future' ), true ) && $backup['post_status'] !== $current['row']['post_status'] && self::menu_adds_pages() ) {", "\t\tif ( false ) {", 'it', 'testTheWayBackDoesNotRepublishATopLevelPageWhileAMenuAddsPages')
e('writer-restore-meta-own', 'restore_meta(): back only where the bytes are what the step read back (decision 23)', PW,
  "\t\tif ( ! hash_equals( (string) $written, self::meta_hash( $now ) ) ) {", "\t\tif ( false ) {", 'it', 'testTheWayBackIsByteForByteAndLeavesWhatChangedSince')
e('writer-restore-row-own', 'restore_row(): back only where the row is what the step read back', PW,
  "\t\tif ( ! hash_equals( $written, $hash ) ) {", "\t\tif ( false ) {", 'it', 'testTheWayBackIsByteForByteAndLeavesWhatChangedSince')
e('writer-restore-future', 'restore_row(): a backed-up future whose date passed is «check needed» (decision 23)', PW,
  "\t\tif ( $saved !== $backup['post_status'] || '' !== AB_MCP_Tools_Content::status_date_refusal( $backup['post_type'], $current['row']['post_status'], $backup['post_status'], $gmt, true, (int) $now ) ) {", "\t\tif ( false ) {", 'it', 'testAScheduledStagingPostWhoseDatePassedIsRefusedAndTheWayBackAfterTheDateNeedsACheck')
e('writer-menu-automatic', 'precheck(): a page of the top level with the menu automatic (decision 21)', PW,
  "\t\tif ( 'page' === $type && $top && in_array( $status, array( 'publish', 'future' ), true ) && self::menu_adds_pages() ) {", "\t\tif ( false ) {", 'it', 'testAPageOfTheTopLevelWithTheMenuAutomaticIsLockedAndTakenOverAsDraftWithTheOverride')

# ----------------------------------------------------------- the run
SHAPE = 'testTheShapeOfARunAndWhenItsLockIsFresh'
e('run-lock-fresh', 'lock_fresh(): a row older than TIMEOUT is left behind (decision 18)', RU,
  "\t\treturn $since > 0 && ( self::now() - $since ) < AB_MCP_Change_Lock::TIMEOUT;", "\t\treturn $since > 0;", 'unit', SHAPE)
OWN = "\t\tif ( (int) $run['user_id'] !== (int) get_current_user_id() || ! hash_equals( (string) $run['connection'], self::connection() ) ) {"
e('run-owner-connection', 'owner_refusal(): the same connection (decision 15)', RU, OWN, OWN.replace(" || ! hash_equals( (string) $run['connection'], self::connection() )", ''), 'unit', SHAPE)
e('run-owner-user', 'owner_refusal(): the same account', RU, OWN, OWN.replace("(int) $run['user_id'] !== (int) get_current_user_id() || ", ''), 'unit', SHAPE)
e('run-checked-confirmed', 'close_checked(): a person in wp-admin only', RU,
  "\t\tif ( true !== $confirmed ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_run_unconfirmed'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_run_unconfirmed'", 'unit', SHAPE)
e('run-settle-interrupted', 'settle(): a step begun without a fresh lock makes the run interrupted (decision 18)', RU,
  "\t\t$moved = self::set_state( (int) $run['run_id'], $run['state'], 'interrupted' );", "\t\t$moved = true;", 'it', 'testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun')
e('run-marks', 'create(): making a run marks the site before its first step, so its requests hook the filters of the lock (N3)', RU,
  "\t\tAB_MCP_Sync_Guard::mark();\n\t\tglobal $wpdb;\n\t\t$now = self::now();", "\t\tglobal $wpdb;\n\t\t$now = self::now();", 'it', 'testARunWritesAChangedPageAndANewPageAndTheWayBackLeavesTheNewOneAsARest')
ROOM = 'testARunLeavesRoomForItsOwnWayBack'
e('run-has-room', 'has_room(): the steps of a run with its way back within MAX_RUN_STEPS (B5, N4)', RU,
  "\t\treturn (int) $have + (int) $add <= self::MAX_RUN_STEPS;", "\t\treturn true;", 'unit', ROOM)
e('run-max-run-steps', 'MAX_RUN_STEPS: the forward plan and one step of the way back per object (N4)', RU,
  "\tconst MAX_RUN_STEPS = 2 * self::MAX_STEPS - self::FRAME;", "\tconst MAX_RUN_STEPS = self::MAX_STEPS;", 'unit', ROOM)
e('run-keep-until-stored', 'set_state(): every close stores the period its backups are kept (decision 20; A7)', RU,
  "\t\t\t$set['keep_until'] = AB_MCP_Sync_Schema::keep_until( $now, (bool) $qualified, AB_MCP_Protected_Store::backup_days() );", '', 'it', 'testTheRetentionKeepsTheLastThreeQualifiedClosesAndWhatAWayBackNeeds')
e('plan-max-objects', 'MAX_OBJECTS: the objects of a plan within MAX_STEPS beside the frame (B5, N4)', PU,
  "\tconst MAX_OBJECTS = AB_MCP_Sync_Run::MAX_STEPS - AB_MCP_Sync_Run::FRAME;", "\tconst MAX_OBJECTS = AB_MCP_Sync_Run::MAX_STEPS;", 'unit', ROOM)
e('plan-in-parts', 'too_many(): a selection past MAX_OBJECTS is refused with the way in parts (N4)', PU,
  "\t\tif ( (int) $count <= self::MAX_OBJECTS ) {", "\t\tif ( (int) $count <= self::MAX_OBJECTS + 1 ) {", 'unit', 'testASelectionPastTheObjectsOfOnePlanIsRefusedWithTheWayInParts')

# ------------------------------------------------------- the retention
KEEP = 'testTheRetentionNamesTheRunsWhoseBackupsStay'
e('schema-keep-last', 'backup_runs(): the last KEEP_LAST qualified closes (decision 20, rule 2)', SC,
  "\t\t\tif ( $n <= self::KEEP_LAST ) {", "\t\t\tif ( $n <= self::KEEP_LAST + 1 ) {", 'unit', KEEP)
e('schema-keep-until', 'backup_runs(): a closed run until the keep_until its close stored (rules 3, 5, 6; A7)', SC,
  "\t\t\tif ( 0 === (int) $row['keep_until'] || (int) $row['keep_until'] > $now ) {", "\t\t\tif ( 0 === (int) $row['keep_until'] ) {", 'unit', KEEP)
e('schema-v2-kept', 'backup_runs(): a row without a stored period (version 2, keep_until 0) is kept, fail closed (N1)', SC,
  "\t\t\tif ( 0 === (int) $row['keep_until'] || (int) $row['keep_until'] > $now ) {", "\t\t\tif ( (int) $row['keep_until'] > $now ) {", 'unit', KEEP)
e('schema-keep-until-qualified', 'keep_until(): a qualified close for the days of the filter, the close itself in Free, never 0 (rule 3; N1)', SC,
  "\t\t\treturn (int) $now + $days * 86400;", "\t\t\treturn $days > 0 ? (int) $now + $days * 86400 : 0;", 'unit', KEEP)
e('schema-keep-until-unqualified', 'keep_until(): an unqualified close for UNQUALIFIED_DAYS, or the days of the filter where more (rule 5)', SC,
  "\t\treturn (int) $now + max( self::UNQUALIFIED_DAYS, $days ) * 86400;", "\t\treturn (int) $now + self::UNQUALIFIED_DAYS * 86400;", 'unit', KEEP)
e('schema-reverts', 'backup_runs(): the runs a way back that is not closed needs (rule 4)', SC,
  "\t\t\t\t\t$keep[ (int) $needed ] = true;", '', 'unit', KEEP)
e('schema-qualified-only', 'backup_runs(): only a qualified close counts among the last three', SC,
  "\t\t\tif ( (int) $row['forward_closed_at'] > 0 ) {", "\t\t\tif ( true ) {", 'unit', KEEP)
ROWS = 'testTheRetentionNamesEveryRowSoARunWithoutOneIsToldFromOneWhoseBackupsGo'
e('schema-rows-named', 'backup_rows(): every row is named, false where its backups go, so the clean-up tells it from a run without a row', SC,
  "\t\t\tif ( ! array_key_exists( $id, $keep ) ) {\n\t\t\t\t$keep[ $id ] = false;\n\t\t\t}\n", '', 'unit', ROWS)
e('schema-runs-kept-only', 'backup_runs(): the runs backup_rows() names true, none it names false', SC,
  "\t\treturn null === $rows ? null : array_filter( $rows );", "\t\treturn $rows;", 'unit', ROWS)

# -------------------------------------------------------------- the plan
VERDICT = 'testWhichEntryOfAReportIsAStepLockedOrNothing'
ORDER = 'testTheOrderOfTheGraphPutsATargetBeforeWhatRefersToItAndLocksACycle'
e('plan-unclear', 'verdict(): an unclear pair is written over only after a confirmation', PU,
  "\t\t\tif ( 'unclear' === $entry['result'] ) {", "\t\t\tif ( false ) {", 'unit', VERDICT)
e('plan-type', 'verdict(): a type the engine does not write is locked with Z9', PU,
  "\t\tif ( ! in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Post_Writer::TYPES, true ) ) {", "\t\tif ( false ) {", 'unit', VERDICT)
e('plan-as-draft', 'verdict(): the choice as_draft goes with the item', PU,
  "\t\t\t\t'as_draft'    => (bool) $as_draft,", "\t\t\t\t'as_draft'    => false,", 'unit', VERDICT)
e('plan-choice-keep', 'verdict(): keep_live and leave_open write nothing', PU,
  "\t\t\tif ( 'staging_wins' !== $choice ) {\n\t\t\t\treturn null;", "\t\t\tif ( false ) {\n\t\t\t\treturn null;", 'unit', VERDICT)
e('plan-cycles', 'order(): objects that need each other are locked (mutual references)', PU,
  "\t\tforeach ( AB_MCP_Sync_Decision::cycles( $edges ) as $members ) {", "\t\tforeach ( array() as $members ) {", 'unit', ORDER)
e('plan-depends', 'order(): a target created in the run comes first', PU,
  "\t\t\t\t\t$items[ $n ]['depends_on'][] = $at[ $key ];", '', 'unit', ORDER)
e('plan-replay', 'plan(): a confirmed call meets the open run of the same plan (decision 15)', PU,
  "\t\t\tif ( 'open' === $open['state'] && hash_equals( (string) $open['plan_hash'], $plan_hash ) && null === AB_MCP_Sync_Run::owner_refusal( $open ) ) {", "\t\t\tif ( false ) {", 'it', 'testARunWritesAChangedPageAndANewPageAndTheWayBackLeavesTheNewOneAsARest')
e('plan-another-open', 'plan(): another run that is not closed refuses a new plan', PU,
  "\t\t\t} else {\n\t\t\t\treturn self::another_open( $open );\n\t\t\t}", "\t\t\t} else {\n\t\t\t\t$plan['existing_run'] = (int) $open['run_id'];\n\t\t\t}", 'it', 'testARunWritesAChangedPageAndANewPageAndTheWayBackLeavesTheNewOneAsARest')

# ------------------------------------------------------------ the engine
E2E = 'testARunWritesAChangedPageAndANewPageAndTheWayBackLeavesTheNewOneAsARest'
e('engine-line-hash', 'line_of(): the package holds what the report compared', EN,
  "\t\tif ( ! hash_equals( (string) $item['staging_hash'], (string) $line['hash'] ) ) {", "\t\tif ( false ) {", 'unit', 'testTheLineOfAnItemIsCheckedAgainstTheReport')
e('engine-line-held', 'line_of(): an object the credential check holds is not written', EN,
  "\t\tif ( isset( $lines['held'][ (string) $item['kind'] . \"\\0\" . $sha ] ) || ! isset( $line['hash'] ) ) {", "\t\tif ( ! isset( $line['hash'] ) ) {", 'unit', 'testTheLineOfAnItemIsCheckedAgainstTheReport')
e('engine-check-target', 'check_item(): a target changed since the plan aborts the run (decision 16)', EN,
  "\t\t\tif ( null === $hash || ! hash_equals( (string) $item['live_hash'], $hash ) ) {\n\t\t\t\treturn self::finding( $item, sprintf( 'The %1$s %2$d of this site changed since the plan (or cannot be read whole), so the %3$s is not written over it.'",
  "\t\t\tif ( false ) {\n\t\t\t\treturn self::finding( $item, sprintf( 'The %1$s %2$d of this site changed since the plan (or cannot be read whole), so the %3$s is not written over it.'", 'it', 'testTheCheckBeforeTheFirstWriteAbortsOnADeviationAndWritesNothing')
e('engine-write-ahead', 'boundary(): the journal written ahead of the first part (decision 17)', EN,
  "\t\t$ahead            = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'begun' );", "\t\t$ahead            = true;", 'it', 'testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun')
e('engine-qualified', 'run_verify(): a qualified close only where every write step was written (decision 20)', EN,
  "\t\t$qualified         = $writes_n > 0 && $written === $writes_n;", "\t\t$qualified         = false;", 'it', E2E)
e('engine-phase-named', 'pick(): a named step of phase 7 or 8 waits for the phases before it (decision 16; B2)', EN,
  "\t\t\t$earlier = self::earlier_phase_open( $by, $step );", "\t\t\t$earlier = null;", 'unit', 'testTheStepsOfAPhaseRunOnlyAfterThePhasesBeforeIt')
e('engine-phase-next', 'pick(): next passes no step of a later phase while one of a lower phase is open (B2)', EN,
  "\t\t\tif ( $open || null !== self::earlier_phase_open( $by, $step ) ) {", "\t\t\tif ( $open ) {", 'unit', 'testTheStepsOfAPhaseRunOnlyAfterThePhasesBeforeIt')
e('engine-findings-kept', 'run_check(): the findings of a call are kept with the cursor (B3)', EN,
  "\t\t\t\t$result['findings'] = $findings;\n", '', 'it', 'testTheCheckBeforeTheFirstWriteKeepsItsFindingsAcrossCalls')
e('engine-pending', 'run_check(): an object the run creates is pending for the check of the others (B1)', EN,
  "\t\t\t\t$ctx['pending']['post'][ (int) $one['staging_ref'] ] = true;", '', 'it', 'testANewParentPageAndItsNewChildPageAreWrittenInOneRun')
e('engine-template-with-row', 'write_parts(): the template was written with the row, the meta part is read back only (B7)', EN,
  "\t\t\t\t$done = $core ? true : AB_MCP_Sync_Post_Writer::write_meta( $live_id, $key, $values, $have );",
  "\t\t\t\t$done = AB_MCP_Sync_Post_Writer::write_meta( $live_id, $key, $values, $have );", 'it', 'testThePageTemplateGoesThroughTheCoreWayAndTheThemeHasToOfferIt')
e('engine-rollback-earlier', 'write_parts(): the parts written before a deviating one go back with it (B8)', EN,
  "\t\t\tfor ( $j = count( $journal['parts'] ) - 1; $j >= 0; $j-- ) {", "\t\t\tfor ( $j = $i; $j >= $i; $j-- ) {", 'it', 'testADeviatingMetaPartTakesTheRowBackWithItAndTheStepSaysSo')
WITH_ROW = 'testATemplateWrittenWithTheRowGoesBackWithTheRow'
e('engine-with-row', 'write_parts(): what the core wrote with the row is read back then and stands in the journal as written (N2)', EN,
  "\t\t\t\tif ( is_array( $now ) ) {\n\t\t\t\t\tforeach ( $journal['parts'] as $k => $other ) {", "\t\t\t\tif ( false ) {\n\t\t\t\t\tforeach ( $journal['parts'] as $k => $other ) {", 'it', WITH_ROW)
e('engine-rollback-written', 'write_parts(): a part written with the row goes back with the row, wherever it stands in the journal (N2)', EN,
  "\t\t\t\tif ( $j !== $i && ! in_array( $journal['parts'][ $j ]['state'], array( 'read', 'written' ), true ) ) {",
  "\t\t\t\tif ( $j !== $i && ( $j > $i || ! in_array( $journal['parts'][ $j ]['state'], array( 'read', 'written' ), true ) ) ) {", 'it', WITH_ROW)
# The ownership rule (Codex round 3): a part is the step's own only where the
# step wrote it, through its own call or through a field of the row call
# (with_row()), and reads back what that call was to write.
OWN = 'testACategoryAnotherHandSetToTheTargetIsNotTheStepsOwnAndIsLeft'
DEFAULT = 'testTheDefaultCategoryOfANewPostIsWrittenWithTheRowAndTheJournalSaysSo'
e('with-row-template', 'with_row(): the template goes with the row through page_template, so it is the step\'s own (B7, N2)', PW,
  "\t\t\t$out['meta:_wp_page_template'] = self::meta_hash( array( (string) $prepared['postarr']['page_template'] ) );\n", '', 'it', WITH_ROW)
e('with-row-default-category', 'with_row(): the default category of a new post goes with the row, so it is the step\'s own and never another hand\'s', PW,
  "\t\t\t$out['terms:category'] = self::terms_hash( array( (int) get_option( 'default_category' ) ) );\n", '', 'it', DEFAULT)
e('engine-own-with-row', 'write_parts(): a part changed with the row is the step\'s own only where the row call was to write it and it reads back so; every other change is another hand\'s (Codex round 3)', EN,
  "\t\t\t\t\t\tif ( isset( $own[ $other['part'] ] ) && hash_equals( (string) $own[ $other['part'] ], $hash ) ) {", "\t\t\t\t\t\tif ( true ) {", 'it', OWN)
e('engine-foreign-left-at-turn', 'write_parts(): a part the step did not write that changed since the backup is left when the step turns, and the object is unclear', EN,
  "\t\t\t\t\tif ( null === $hash || ! hash_equals( $was, $hash ) ) {", "\t\t\t\t\tif ( false ) {", 'it', OWN)
e('engine-revert-left', 'revert_boundary(): a part left at the turn is never put back by the way back of the closed run', EN,
  "\t\t\tif ( 'left' === $part['state'] ) {", "\t\t\tif ( false ) {", 'it', OWN)
e('engine-deviating-terms-left', 'restore_part(): a deviating part on a way the lock does not refuse is never put back (A2)', EN,
  "\t\tif ( null !== $part['after'] && ! hash_equals( (string) $part['target'], (string) $part['after'] ) && AB_MCP_Sync_Post_Writer::WAY_TERMS === $part['way'] ) {", "\t\tif ( false ) {", 'it', 'testADeviatingTermPartIsNeverTakenBack')
e('engine-sample-expected', 'sample_finding(): the title of the written object has to be in the body (decision 33; B4)', EN,
  "\t\t\tif ( '' !== $needle && false !== strpos( (string) $body, $needle ) ) {", "\t\t\tif ( true ) {", 'unit', 'testTheSampleOfAPublishedObjectNeedsTheTitleBesides200')
e('engine-sample-200', 'sample_finding(): 200 (decision 33)', EN,
  "\t\tif ( 200 !== (int) $status ) {", "\t\tif ( false ) {", 'unit', 'testTheSampleOfAPublishedObjectNeedsTheTitleBesides200')
e('engine-verify-budget', 'run_verify(): the hashes over calls where the budget runs out (B4)', EN,
  "\t\t\t\tif ( $worked && $left() <= 0 ) {\n\t\t\t\t\t$result['cursor'] = $i;", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$result['cursor'] = $i;", 'it', 'testTheCheckOfTheRunNeedsTheTitleBesides200AndGoesOnOverCalls')
e('revert-wrote', 'plan(): only a step with a journal and a backup wrote anything (B9)', RV,
  "\t\t\t$wrote = null !== $journal && ! in_array( $step['state'], array( 'planned', 'skipped' ), true ) && is_array( $journal['backup'] ) && ( 'create' !== $journal['object']['op'] || null !== $journal['object']['live_ref'] );",
  "\t\t\t$wrote = null !== $journal && ! in_array( $step['state'], array( 'planned', 'skipped' ), true );", 'it', 'testAStepThatFailedBeforeItsBackupIsNoRestOnTheWayBack')
e('revert-under-lock', 'begin(): a way back for a closed run is made under the lock of the store (decision 20, rule 4; A6)', RV,
  "\t\t\t$now    = AB_MCP_Protected_Store::under_lock(", "\t\t\t$now    = call_user_func(", 'it', 'testAWayBackForAClosedRunIsMadeUnderTheLockOfTheStore')
e('export-one-reading', 'export(): form and content from the one reading of the rows (A4)', DK,
  "\t\t\t$result['content'] = self::content_of( $rows );", "\t\t\t$result['content'] = self::content( $id );", 'unit', 'testThePackageOfObjectsCarriesTheContentOfTheReadingItsHashCameFrom')
e('export-term-credentials', 'export(): the names and slugs of the terms through the credential check (A5)', DK,
  "\t\t\t\t\tAB_MCP_Sync_Credentials::note( $acc, 'post', 'term:' . $term['taxonomy'] . ':' . $field, AB_MCP_Sync_Credentials::unit( '', (string) $term[ $field ] ) );", '', 'unit', 'testATermNameWithACredentialHoldsTheObjectOfThePackage')
e('engine-rest-residual', 'revert_boundary(): a new object stays as a rest, its pair residual (rule 0)', EN,
  "\t\t\t\tif ( is_array( $pair ) && 'residual' !== $pair['state'] ) {\n\t\t\t\t\tAB_MCP_Sync_Pairs::set_state( $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\tAB_MCP_Sync_Pairs::set_state( $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );", 'it', E2E)
e('engine-pair-restore', 'revert_boundary(): the pair goes back to its snapshot before the step (decision 10)', EN,
  "\t\t\t\t$restored = AB_MCP_Sync_Pairs::restore( $pair_id, (string) $forward['pair_before'], $now_hash );", "\t\t\t\t$restored = true;", 'it', E2E)
e('engine-reverting-only', 'pick(): a run on its way back runs its steps of the way back only', EN,
  "\t\t$reverting = 'reverting' === $run['state'] || 'revert' === $run['type'];", "\t\t$reverting = false;", 'it', 'testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun')
e('revert-same-run', 'plan(): the way back of a run that is not closed leads the same run (decision 15)', RV,
  "\t\t$same = in_array( $run['state'], array( 'open', 'interrupted' ), true );", "\t\t$same = false;", 'it', 'testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun')


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
    if ' with data set "' in name:
        test, case = name.split(' with data set "', 1)
        case = case.rstrip('"')
        return '/' + q(test) + '(?: with data set "|#)' + q(case) + '/'
    return '/' + q(name) + '/'


out = []
out.append('''<?php
/**
 * The clauses of the run of the takeover (Z8, branch 8 of the plan:
 * decisions 15 to 20, 23, 32 and 33): the two tools and their confirmation
 * in the same call (when a strict false for the actions that run at once,
 * wp_site_revert a way back the gate never holds, push Mighty); the means
 * before a run (15.11: the booking with its reserve, the levels of time,
 * memory, space and tempo, the refusal with the two ways); the lock of a
 * write boundary (decision 19: the owner passes, the record and its time,
 * the REST API with 423, the classic way, meta by id, a covered option and
 * taxonomy, the edit lock); the engine of the kind post (the rule for meta
 * keys, the target with the confirmed choice and the derived UTC date, the
 * parent and the terms by their pairs, each refusal with its lock, the
 * terms as a set, the way back only where a part is still what the step
 * read back, a backed-up future whose date passed, the menu automatic);
 * the run (the lock fresh by TIMEOUT, the owner by account and connection,
 * closing as checked by a person, the interruption); the retention of the
 * backups (decision 20: the last three qualified closes, the days of the
 * filter, what a way back needs, seven days for an unqualified close, only
 * qualified closes count); the plan (an unclear pair, a type Z9 brings, the
 * choice as draft, keep_live and leave_open, a cycle locked, the order of
 * the graph, the replay of decision 15, another open run); the engine (the
 * lines of the packages against the report and the credential check, a
 * target changed since the plan, the journal written ahead, a qualified
 * close, a rest with its pair residual, the pair back to its snapshot, a run
 * on its way back running its steps of the way back only, the same run);
 * and what Codex round 1 found (A1 to A7, B1 to B9): the lock armed on
 * every request and read afresh, a delete across every post, the period a
 * close stores, the room for the way back, the phases in order, the
 * findings of the check kept over calls, a pending parent, the template
 * through the core way, the parts put back together, a deviating term part
 * left, the sample with its title and the check over calls, only steps that
 * wrote on the way back, the way back made under the lock of the store,
 * the package from one reading with its term names checked.
 *
 * Written by tests/mutation/gen/lauf.py from the source; change the entries
 * there and run it again.
 *
 * Held by the unit suite (SyncRunTest) and, for what two WordPress sites
 * show, by the integration suite (SyncRunTest; the sites running, AB_IT_ROOT
 * set). Not listed: the guards against a database that refuses a read or a
 * write (db_error(); no test site refuses), the budget of a long check over
 * calls (a site with thousands of objects), the probe of a published object
 * and the PHP probe (a test replaces the probe, the real request is the live
 * probe), the lock busy (a second request of the same site holding the lock
 * at that moment; the lock itself is held by its own tests), and the texts
 * of refusals (AnswerWayTest holds that each names a way); GRENZEN-LAUF.md.
 *
 * @package AlphaBridge_MCP
 */

/*
 * The run of an entry: the test its expect names, alone, in the unit suite
 * or in the integration suite (-c; the sites running).
 */
$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$pu = 'includes/sync/class-sync-push.php';
$rv = 'includes/sync/class-sync-revert.php';
$en = 'includes/sync/class-sync-engine.php';
$pw = 'includes/sync/class-sync-post-writer.php';
$gu = 'includes/sync/class-sync-guard.php';
$rs = 'includes/sync/class-sync-resources.php';
$ru = 'includes/sync/class-sync-run.php';
$sc = 'includes/sync/class-sync-schema.php';
$ts = 'includes/tools/class-tools-staging.php';
$g  = 'includes/class-staging-gate.php';
$dk = 'includes/sync/class-sync-db-kinds.php';

return array(''')
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
open(os.path.join(ROOT, 'tests/mutation/lists/lauf.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
