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
BK = 'includes/sync/class-sync-backup.php'
CN = 'includes/sync/class-sync-canon.php'
FILEVAR = {PU: '$pu', RV: '$rv', EN: '$en', PW: '$pw', GU: '$gu', RS: '$rs', RU: '$ru', SC: '$sc', TS: '$ts', G: '$g', DK: '$dk', BK: '$bk', CN: '$cn'}

RUNS = {
    'unit': ('unit', 'SyncRunTest'),
    'gate': ('unit', 'StagingGateTest'),
    'it': ('it', 'SyncRunTest'),
    'canon': ('unit', 'SyncCanonFormTest'),
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
# The second check of the kind at the moment of writing: put_back() decides on
# a hash read right before (decide()), the kind reads again as it writes.
# Between the two reads the rights at the object are asked (restore_right(),
# since GitHub Codex round 2 on free#137), whose hooks (map_meta_cap,
# user_has_cap) a site may use to change the part: the integration test
# stages that (Codex round 18 of branch 8); the unit test
# testTheKindReadsAgainAsItWritesAPartBackAndLeavesOneThatIsNotWhatTheStepRead
# calls the kind directly besides.
SECOND = 'testAHookOfTheRightsCheckThatChangesAPartAsItGoesBackIsCaughtByTheSecondReadOfTheKind'
e('writer-restore-meta-own', 'restore_meta(): back only where the bytes are what the step read back (decision 23)', PW,
  "\t\tif ( ! hash_equals( (string) $written, self::meta_hash( $now ) ) ) {", "\t\tif ( false ) {", 'it', SECOND)
e('writer-restore-row-own', 'restore_row(): back only where the row is what the step read back', PW,
  "\t\tif ( ! hash_equals( $written, $hash ) ) {", "\t\tif ( false ) {", 'it', SECOND)
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
# Codex round 6 of branch 8: ownership is a mark of its own in the journal
# (own, own_after, own_target), set where a call of the step's own changed
# the part (post other than pre), never taken from the state of a part;
# decide() is the one place that says whether a part goes back.
N1 = 'testAChangeByAnotherHandBeforeARefusedMetaCallIsNotTheStepsOwnAndStays'
N2 = 'testATemplateAHookChangedWithTheRowIsNotTheStepsOwnAndTheWayBackLeavesIt'
N3 = 'testTermsAHookChangedAsTheRowWentBackAreLeftNotWrittenOver'
DEVIATED_BACK = 'testADeviatedObjectIsLeftWholeOnTheWayBackOfTheClosedRunToo'
B8 = 'testADeviatingMetaPartLeavesTheObjectWholeWithTheOldValuesInTheAnswer'
INTERRUPTED = 'testARequestThatExitsInABoundaryLeavesTheRunInterruptedAndTheWayBackLeadsTheSameRun'
MATRIX = 'testDecideOverEveryCombinationOfOwnershipStateDeviationWayAndForeignMark'
NORMAL = 'testAPostWithACategoryAndATagAndAPageWithATemplateGoOverAndBackWithEveryPartRestored'
e('engine-with-row', 'write_parts(): what the row call writes besides the row (with_row()) is read back with the row and is the step\'s own where it reads so; without it the template of an ordinary page is another hand\'s and the step deviates (N2)', EN,
  "\t\t\t\t\t$expected               = $k === $i ? (string) $other['target'] : ( isset( $named[ $other['part'] ] ) ? (string) $named[ $other['part'] ] : null );",
  "\t\t\t\t\t$expected               = $k === $i ? (string) $other['target'] : null;", 'it', NORMAL)
e('engine-own-after', 'seen_after(): the hash read back after the call that changed the part is own_after, what the way back compares with', EN,
  "\t\t\t$part['own_after']  = $post;\n", '', 'it', E2E)
e('engine-changed-owns', 'seen_after(): a call that changed nothing of the part (post as pre) marks nothing of it, neither the step\'s own nor another hand\'s (N1)', EN,
  "\t\tif ( ! self::changed( isset( $part['pre'] ) ? $part['pre'] : null, $post ) ) {\n\t\t\treturn $part;\n\t\t}",
  "\t\tif ( false ) {\n\t\t\treturn $part;\n\t\t}", 'it', NORMAL)
# The one boundary (the decision of 07.11.2026, «A mit altem Wert»): any
# deviation within the step (another hand, a refused or failed call, a read
# back other than written) ends the step; nothing of the object is put back,
# every part not as the backup is left with its value before, and decide()
# answers left for every part of a deviated object on the way back as well.
e('engine-foreign-deviates', 'write_parts(): a part another hand changed within the step (foreign) ends the step as deviated, whichever part; without it the step\'s own call writes the target over the other hand\'s change and the step is done', EN,
  "\t\t\t\tif ( ! empty( $other['foreign'] ) ) {", "\t\t\t\tif ( false ) {", 'it', 'testACategoryAnotherHandSetToTheTargetIsNotTheStepsOwnAndIsLeft')
e('engine-error-deviates', 'write_parts(): a call that answered with an error ends the step as deviated with the error in the journal, whether it changed the part or not (P1)', EN,
  "\t\t\tif ( null !== $error ) {\n\t\t\t\t// The call answered with an error", "\t\t\tif ( false ) {\n\t\t\t\t// The call answered with an error", 'it', 'testAMetaCallThatChangedThePartAndThenFailedLeavesTheObjectWithTheOldValues')
e('engine-deviated-marks-parts', 'deviated(): every part of a deviated object carries the mark, so that decide() leaves it on the way back too', EN,
  "\t\t\t$journal['parts'][ $k ]['deviated'] = true;\n", '', 'it', DEVIATED_BACK)
e('engine-deviated-left-state', 'deviated(): a part not as the backup is left', EN,
  "\t\t\t$journal['parts'][ $k ]['state']        = 'left';\n", '', 'it', B8)
e('engine-deviated-value-before', 'deviated(): a part left names its value before, from the backup (mit altem Wert)', EN,
  "\t\t\t$journal['parts'][ $k ]['value_before'] = AB_MCP_Sync_Post_Writer::before_value( (string) $part['part'], $backup, $state, $live_id );\n", '', 'it', B8)
e('engine-deviated-nothing-left', 'deviated(): where no part differs from the backup the step is failed, not unclear (the row call refused before it wrote)', EN,
  "\t\tif ( 0 === $left ) {", "\t\tif ( false ) {", 'it', 'testARowCallRefusedBeforeItWroteFailsTheStepAndNothingIsLeft')
e('engine-decide-deviated', 'decide(): a part of a deviated object is left, whatever it holds (boundary C)', EN,
  "\t\tif ( ! empty( $part['deviated'] ) ) {", "\t\tif ( false ) {", 'unit', MATRIX)
e('engine-decide-deviated-it', 'decide() on the way back: the row of a deviated object is left though it still holds what the step read back', EN,
  "\t\tif ( ! empty( $part['deviated'] ) ) {", "\t\tif ( false ) {", 'it', DEVIATED_BACK)
e('engine-put-back-value-before', 'put_back(): a part left on the way back names its value before', EN,
  "\t\t\tif ( 'left' === $one['state'] ) {\n\t\t\t\t$one['value_before']", "\t\t\tif ( false ) {\n\t\t\t\t$one['value_before']", 'it', 'testTheWayBackIsByteForByteAndLeavesWhatChangedSince')
e('run-cap-values', 'cap_values(): one answer carries at most ANSWER_VALUE_BYTES of values before, the newest steps first', RU,
  "\t\t\t\t\tif ( $bytes <= $budget ) {", "\t\t\t\t\tif ( true ) {", 'unit', 'testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst')
e('writer-before-credential', 'shown_value(): a value the credential check names is a reference only, never in the clear', PW,
  "\t\tif ( 'clean' !== $check['state'] ) {", "\t\tif ( false ) {", 'unit', 'testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped')
e('writer-before-net', 'shown_value(): a value the net names a credential in is a reference only, never in the clear (Codex round 8)', PW,
  "\t\tif ( 'credential' === $net ) {", "\t\tif ( false ) {", 'unit', 'testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped')
e('run-cap-mark-counts', 'cap_values(): the mark of a value cut counts against the budget; past it the value is left out (Codex round 8)', RU,
  "\t\t\t\t\tif ( strlen( self::VALUE_CUT ) <= $budget ) {", "\t\t\t\t\tif ( true ) {", 'unit', 'testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst')
e('engine-revert-foreign-mark', 'revert_boundary(): a part the journal marks as another hand\'s leaves the whole object on the way back, also where the step did not mark the object (Codex round 8)', EN,
  "\t\t} elseif ( empty( $object['deviated'] ) && self::marked_foreign( $journal ) ) {", "\t\t} elseif ( false ) {", 'it', 'testAJournalThatMarksAPartForeignLeavesTheWholeObjectOnTheWayBack')
e('revert-will-foreign', 'plan(): the preview says left for an object whose journal marks a part as another hand\'s (Codex round 8)', RV,
  "|| ! array_key_exists( 'deviated', $object ) || $foreign ? 'stays'", "|| ! array_key_exists( 'deviated', $object ) ? 'stays'", 'it', 'testAJournalThatMarksAPartForeignLeavesTheWholeObjectOnTheWayBack')
e('writer-before-rules', 'checked_before(): a value before composed by an earlier build (no value_rules of this one) is never shown (Codex round 9)', PW,
  "\t\tif ( self::VALUE_RULES !== $rules ) {", "\t\tif ( false ) {", 'unit', 'testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst')
e('run-cap-checked', 'cap_values(): every value before is checked once more as it leaves (Codex round 9)', RU,
  "\t\t\t\t\t$part['value_before']                                 = AB_MCP_Sync_Post_Writer::checked_before( $part['value_before'], isset( $part['value_rules'] ) ? (int) $part['value_rules'] : null );", "\t\t\t\t\t$part['value_before']                                 = (string) $part['value_before'];", 'unit', 'testAnAnswerCarriesAtMostSoManyBytesOfValuesBeforeNewestStepsFirst')
e('engine-unread-no-own', 'seen_after(): a state not read is no read back and no ownership (Codex round 11)', EN,
  "\t\tif ( ! is_array( $state ) ) {\n\t\t\t// Not read back", "\t\tif ( false ) {\n\t\t\t// Not read back", 'unit', 'testAPartNotReadBackAfterItsCallIsNeverTheStepsOwn')
e('engine-call-changed-besides', 'write_parts(): whatever a meta or terms call changed besides its own part deviates the object (Codex rounds 13 to 15)', EN,
  "\t\t\t\t\tif ( $other_name === $name || ! self::changed( AB_MCP_Sync_Post_Writer::part_hash( $pre_call, $other_name ), AB_MCP_Sync_Post_Writer::part_hash( $post_all, $other_name ) ) ) {", "\t\t\t\t\tif ( true ) {", 'it', 'testAHookThatChangesAPartStillToWriteAndBackAgainDeviatesTheObject')
e('engine-part-names-all', 'part_names(): every meta key of the states, named by the plan or not (Codex round 15)', EN,
  "\t\t\t\t$names[ 'meta:' . (string) $row['key'] ] = true;", "\t\t\t\tunset( $row );", 'it', 'testAHookThatAddsAndTakesAwayAKeyThePlanDoesNotNameDeviatesTheObject')
e('engine-pre-call-terms', 'write_parts(): the state right before the terms call is the one the call is judged against (Codex round 13)', EN,
  "\t\t\t\t$pre_call               = $pre_all;\n\t\t\t\t// Written ahead of the call, as for the meta (F1).", "\t\t\t\t// Written ahead of the call, as for the meta (F1).", 'it', 'testAHookThatChangesAWrittenPartAsALaterPartIsWrittenDeviatesTheObject')
e('engine-revert-stops-besides', 'put_back(): whatever putting a part back changed besides it, named by the plan or not, stops the way back for the object (Codex round 16)', EN,
  "\t\t\t\t\t\tif ( $other_name !== $name && self::changed( AB_MCP_Sync_Post_Writer::part_hash( $seen, $other_name ), AB_MCP_Sync_Post_Writer::part_hash( $again, $other_name ) ) ) {", "\t\t\t\t\t\tif ( false ) {", 'it', 'testAKeyAHookAddsAsACategoryGoesBackStopsTheWayBackOfTheObject')
e('engine-revert-stopped-left', 'put_back(): a part after the way back stopped is left, not put back (Codex round 16)', EN,
  "\t\t\t\tif ( 'put_back' === $decided['state'] ) {\n\t\t\t\t\t$decided = array(\n\t\t\t\t\t\t'state' => 'left',\n\t\t\t\t\t\t'why'   => sprintf( 'The way back stopped for this object: %s; the part %s is left as it is, check it by hand.', $stopped, $name ),",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\t$decided = array(\n\t\t\t\t\t\t'state' => 'left',\n\t\t\t\t\t\t'why'   => sprintf( 'The way back stopped for this object: %s; the part %s is left as it is, check it by hand.', $stopped, $name ),", 'it', 'testAKeyAHookAddsAsACategoryGoesBackStopsTheWayBackOfTheObject')
e('writer-before-password', 'row_before(): post_password is a credential by its name, never shown', PW,
  "\t\t\tif ( 'post_password' === $column || AB_MCP_Sync_Credentials::names_credential( $column, AB_MCP_Redactor::extra_keys() ) ) {", "\t\t\tif ( false ) {", 'unit', 'testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped')
e('writer-before-long', 'row_before(): a long text by its bytes and the revision that holds it, never whole', PW,
  "\t\t\t} elseif ( in_array( $column, self::LONG_COLUMNS, true ) ) {", "\t\t\t} elseif ( false ) {", 'unit', 'testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped')
e('writer-before-cut', 'cut(): a value past its characters is cut with the mark', PW,
  "\t\tif ( mb_strlen( $text, 'UTF-8' ) <= (int) $max ) {", "\t\tif ( true ) {", 'unit', 'testTheValueBeforeOfAPartLeftIsShortNeverACredentialAndCapped')
e('engine-decide-own-mark', 'decide(): the step owns a part by the mark own with own_after, never by a hash it read back (after) whatever hand wrote it (N2)', EN,
  "\t\t$own  = ! empty( $part['own'] ) && isset( $part['own_after'] ) && null !== $part['own_after'];",
  "\t\t$own  = isset( $part['after'] ) && null !== $part['after'];\n\t\t$part['own_after'] = $own ? $part['after'] : null;", 'unit', MATRIX)
e('engine-decide-unchanged', 'decide(): a part as the backup is unchanged, nothing to do', EN,
  "\t\tif ( hash_equals( (string) $backup, (string) $now ) ) {", "\t\tif ( false ) {", 'unit', MATRIX)
e('engine-decide-own-back', 'decide(): a part the step owns, holding what it read back (own_after), goes back', EN,
  "\t\t\tif ( hash_equals( (string) $part['own_after'], (string) $now ) ) {", "\t\t\tif ( false ) {", 'unit', MATRIX)
e('engine-decide-changed-since', 'decide(): a part the step owns that holds other than own_after was changed since, and is left', EN,
  "\t\t\tif ( hash_equals( (string) $part['own_after'], (string) $now ) ) {", "\t\t\tif ( true ) {", 'unit', MATRIX)
e('engine-decide-own-back-it', 'decide() on the way back: a part the step owns, holding what it read back, goes back (an ordinary run)', EN,
  "\t\t\tif ( hash_equals( (string) $part['own_after'], (string) $now ) ) {", "\t\t\tif ( false ) {", 'it', E2E)
# The ownership rule (Codex round 3): a part is the step's own only where the
# step wrote it, through its own call or through a field of the row call
# (with_row()), and reads back what that call was to write.
OWN = 'testACategoryAnotherHandSetToTheTargetIsNotTheStepsOwnAndIsLeft'
DEFAULT = 'testTheDefaultCategoryOfANewPostIsWrittenWithTheRowAndTheJournalSaysSo'
e('engine-save-before-meta-call', 'write_parts(): the journal is written ahead of the meta call, so a request that ends inside it leaves the part without a read back (Codex round 7, F1)', EN,
  "\t\t\t\t\t$save( $journal );\n\t\t\t\t\t$done                   = AB_MCP_Sync_Post_Writer::write_meta(", "\t\t\t\t\t$done                   = AB_MCP_Sync_Post_Writer::write_meta(", 'it', 'testARequestThatEndsInsideAMetaCallLeavesThePartForTheWayBackOfTheSameRun')
e('with-row-template', 'with_row(): the template goes with the row through page_template, so it is the step\'s own and an ordinary page does not deviate (B7, N2)', PW,
  "\t\t\t$out['meta:_wp_page_template'] = self::meta_hash( array( (string) $prepared['postarr']['page_template'] ) );\n", '', 'it', NORMAL)
e('with-row-default-category', 'with_row(): the default category of a new post goes with the row, so it is the step\'s own and never another hand\'s', PW,
  "\t\t\t$out['terms:category'] = self::terms_hash( array( (int) get_option( 'default_category' ) ) );\n", '', 'it', DEFAULT)
e('engine-own-with-row', 'seen_after(): a part changed with the row is the step\'s own only where the row call was to write it (with_row()); every other change with the row is another hand\'s (Codex round 3)', EN,
  "\t\tif ( null !== $expected && ( ! $by_row || hash_equals( (string) $expected, (string) $post ) ) ) {", "\t\tif ( true ) {", 'it', OWN)
e('engine-own-with-row-reads', 'seen_after(): a part the row call was to write is the step\'s own only where it reads back what with_row() names; a hook\'s other change with the row is another hand\'s and the step deviates (N2)', EN,
  "\t\tif ( null !== $expected && ( ! $by_row || hash_equals( (string) $expected, (string) $post ) ) ) {", "\t\tif ( null !== $expected ) {", 'it', 'testTermsAHookChangedWithTheRowOtherThanTheCoreWouldAreAnotherHandsEvenOnAPostWithoutACategory')
e('engine-settled', 'settled(): a part still planned when the request ended the step is not_reached, never the run\'s own (Codex round 4)', EN,
  "\t\t\tif ( 'planned' === $part['state'] ) {\n\t\t\t\t$journal['parts'][ $k ]['state'] = 'not_reached';", "\t\t\tif ( false ) {\n\t\t\t\t$journal['parts'][ $k ]['state'] = 'not_reached';", 'it', 'testAPartTheStepNeverReachedIsNotTakenBackOnTheWayBack')
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

# ------------------------------- GitHub Codex round 1 on free#137 (two P1, four P2)
# The state of a part from the read back, never from what a call answered;
# the parts put back at one place, the row first, every part read again
# after; a target changed since the report; the right to remove a key only
# this site has; a step back once, also across runs of the way back; the
# key slashed as the values.
PARTIAL = 'testAMetaCallThatChangedThePartAndThenFailedLeavesTheObjectWithTheOldValues'
ORDER_T = 'testTheWayBackPutsTheRowBackBeforeTheTermsSoAPostWithoutACategoryStaysWithout'
e('engine-put-back-order', 'put_back(): the row goes back first, then the meta, then the terms, which undo the side effects of wp_update_post() on them (the default category; P2)', EN,
  "\t\tforeach ( array( 'row', 'meta:', 'terms:' ) as $prefix ) {", "\t\tforeach ( array( 'terms:', 'meta:', 'row' ) as $prefix ) {", 'it', ORDER_T)
e('engine-put-back-check', 'put_back(): every part counted as back is read again against the backup; one that reads otherwise is unclear, and so is the object', EN,
  "\t\t\t\tif ( null === $now || ! hash_equals( (string) AB_MCP_Sync_Post_Writer::part_hash( $backup, $name ), $now ) ) {", "\t\t\t\tif ( false ) {", 'it', 'testAPartAHookChangedAfterItWasPutBackIsUnclearNotRestored')
F2 = 'testTheDefaultCategoryOfTheRowGoingBackDoesNotTakeBackTermsAnotherHandChanged'
# The mark of another hand on a part that putting another part back changed
# (Codex round 12; F2) decided nothing the halt of the way back (Codex round
# 16, engine-revert-stops-besides, engine-revert-stopped-left) does not
# decide: the CI of GitHub Codex round 2 on free#137 showed its mutation
# survived, and the block is gone with its three entries (engine-put-back-
# row-effects, engine-put-back-any-effects, engine-put-back-row-foreign); F2
# holds the halt and its wording.
e('plan-report-live', 'plan(): a target that changed since the report is not written over, whatever the report said of it; the hash the plan binds is the one the report compared (P1)', PU,
  "\t\t\tif ( null === $existing && ( ! isset( $compared[ $key ] ) || ! hash_equals( $compared[ $key ], $hash ) ) ) {", "\t\t\tif ( false ) {", 'it', 'testATargetThatChangedSinceTheReportIsNotPlannedWhateverTheReportSaid')
e('writer-delete-right', 'precheck(): a plain key only this site has, which the step removes, needs the right to remove it (delete_post_meta; decision 28; P2)', PW,
  "\t\t\tif ( ! isset( $prepared['meta'][ $row['key'] ] ) && ! in_array( $row['key'], self::CORE_META, true ) && ! current_user_can( 'delete_post_meta', (int) $live_id, $row['key'] ) ) {", "\t\t\tif ( false ) {", 'unit', 'testRemovingAMetaKeyOnlyThisSiteHasNeedsTheRightToDeleteIt')
e('revert-once-across-runs', 'plan(): a step goes back at most once, also across the runs of the way back made for the closed run (P2)', RV,
  "\t\tforeach ( $others as $other ) {\n\t\t\t$ways[ (int) $other['run_id'] ] = AB_MCP_Sync_Run::steps( (int) $other['run_id'] );",
  "\t\tforeach ( array() as $other ) {\n\t\t\t$ways[ (int) $other['run_id'] ] = AB_MCP_Sync_Run::steps( (int) $other['run_id'] );", 'it', 'testAStepGoesBackOnceAlsoAcrossRunsOfTheWayBack')
SLASH = 'testAMetaKeyWithABackslashIsStoredAndRemovedUnderItsOwnName'
e('writer-slash-key-update', 'write_meta(): the key slashed for update_metadata(), which unslashes it (P2)', PW,
  "\t\t\t$done = update_metadata( 'post', $id, wp_slash( (string) $key ), wp_slash( $values[0] ) );", "\t\t\t$done = update_metadata( 'post', $id, $key, wp_slash( $values[0] ) );", 'unit', SLASH)
e('writer-slash-key-delete', 'write_meta(): the key slashed for delete_metadata(), which unslashes it', PW,
  "\t\tif ( array() !== $current && false === delete_metadata( 'post', $id, wp_slash( (string) $key ) ) ) {", "\t\tif ( array() !== $current && false === delete_metadata( 'post', $id, $key ) ) {", 'unit', SLASH)
e('writer-slash-key-add', 'write_meta(): the key slashed for add_metadata(), which unslashes it', PW,
  "\t\t\tif ( false === add_metadata( 'post', $id, wp_slash( (string) $key ), wp_slash( $value ) ) ) {", "\t\t\tif ( false === add_metadata( 'post', $id, $key, wp_slash( $value ) ) ) {", 'unit', SLASH)


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

# ------------------------------- GitHub Codex round 2 on free#137 (one P1, three P2)
# The way back begins with all of its steps or not at all (one transaction,
# also for a run made); the rights at the object asked again on the way back;
# the right to set the terms of every taxonomy the step touches, by id, by
# slug and for one only this site has; the backup gives bytes that are no
# UTF-8 back as bytes.
ATOMIC = 'testTheWayBackOfARunThatIsNotClosedBeginsWithAllOfItsStepsOrNotAtAll'
TX = 'testAWriteOfTheRunAndItsStepsIsOneTransactionAndRefusedWithTheWayWithoutInnoDb'
e('revert-begin-transaction', 'begin(): the state, the forward steps and every step of the way back in one transaction, or nothing (P1)', RV,
  "\t\t\t$began  = AB_MCP_Sync_Run::transaction(", "\t\t\t$began  = call_user_func(", 'it', ATOMIC)
e('run-create-transaction', 'create(): the run and every one of its steps in one transaction, or nothing (P1)', RU,
  "\t\t$run_id = self::transaction(", "\t\t$run_id = call_user_func(", 'it', ATOMIC)
e('run-transaction-engine', 'transaction(): a table whose engine keeps no transactions refuses with the way, before anything is written', RU,
  "\t\t\tif ( ! isset( $by[ $table ] ) || 0 !== strcasecmp( $by[ $table ], 'InnoDB' ) ) {", "\t\t\tif ( false ) {", 'unit', TX)
e('run-transaction-rollback', 'transaction(): a WP_Error rolls back', RU,
  "\t\tif ( is_wp_error( $result ) ) {\n\t\t\t$wpdb->query( 'ROLLBACK' );\n\t\t\treturn $result;\n\t\t}", "\t\tif ( is_wp_error( $result ) ) {\n\t\t\t$wpdb->query( 'COMMIT' );\n\t\t\treturn $result;\n\t\t}", 'unit', TX)
RIGHTS_BACK = 'testTheWayBackAsksTheRightsAtTheObjectAndLeavesAPartTheAccountMayNotWrite'
RIGHTS_UNIT = 'testTheWayBackAsksTheRightsAtTheObjectAsTheForwardWriteDoesBeforeAPartGoesBack'
e('engine-put-back-rights', 'put_back(): a part the account may not write as the forward write asks is left with its value before (decision 28; P2)', EN,
  "\t\t\t\t$may = AB_MCP_Sync_Post_Writer::restore_right( $name, $live_id, $backup, $seen );\n\t\t\t\tif ( true !== $may ) {", "\t\t\t\t$may = AB_MCP_Sync_Post_Writer::restore_right( $name, $live_id, $backup, $seen );\n\t\t\t\tif ( false ) {", 'it', RIGHTS_BACK)
e('writer-restore-right-edit', 'restore_right(): every part asks edit_post first, the terms too (Codex round 18)', PW,
  "\t\tif ( ! current_user_can( 'edit_post', $id ) ) {\n\t\t\treturn sprintf( 'edit the post %1$d (edit_post%2$s)', $id, 'row' === $part ? '' : ', for the part ' . $part );", "\t\tif ( false ) {\n\t\t\treturn sprintf( 'edit the post %1$d (edit_post%2$s)', $id, 'row' === $part ? '' : ', for the part ' . $part );", 'it', 'testWithoutEditPostNothingOfThePostGoesBackNotEvenItsTerms')
e('writer-restore-right-publish', 'restore_right(): a row put back to publish, future or private asks publish_posts', PW,
  "\t\t\tif ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ( ! isset( $now['row']['post_status'] ) || (string) $now['row']['post_status'] !== $status ) && ! current_user_can( (string) $pto->cap->publish_posts ) ) {", "\t\t\tif ( false ) {", 'unit', RIGHTS_UNIT)
e('writer-restore-right-others', 'restore_right(): a row of another author asks edit_others_posts', PW,
  "\t\t\tif ( isset( $backup['row']['post_author'] ) && (int) $backup['row']['post_author'] !== (int) get_current_user_id() && ! current_user_can( (string) $pto->cap->edit_others_posts ) ) {", "\t\t\tif ( false ) {", 'unit', RIGHTS_UNIT)
e('writer-restore-right-meta-edit', 'restore_right(): rows of a key written over ask edit_post_meta', PW,
  "\t\t\tif ( $was > 0 && $has > 0 && ! current_user_can( 'edit_post_meta', $id, $key ) ) {", "\t\t\tif ( false ) {", 'unit', RIGHTS_UNIT)
e('writer-restore-right-meta-add', 'restore_right(): rows of a key put back that the post lacks ask add_post_meta', PW,
  "\t\t\tif ( $was > $has && ! current_user_can( 'add_post_meta', $id, $key ) ) {", "\t\t\tif ( false ) {", 'unit', RIGHTS_UNIT)
e('writer-restore-right-meta-delete', 'restore_right(): rows of a key removed ask delete_post_meta', PW,
  "\t\t\tif ( $has > $was && ! current_user_can( 'delete_post_meta', $id, $key ) ) {", "\t\t\tif ( false ) {", 'unit', RIGHTS_UNIT)
e('writer-restore-right-terms', 'restore_right(): the terms of a taxonomy ask term_right() for the backed-up keys', PW,
  "\t\treturn self::term_right( $taxonomy, self::term_keys( $backup, $taxonomy ) );", "\t\treturn true;", 'unit', RIGHTS_UNIT)
TERMS_RIGHTS = 'testTheRightToSetTheTermsIsAskedForEveryTaxonomyTheStepTouchesByIdBySlugAndForOneOnlyThisSiteHas'
e('writer-precheck-live-taxonomies', 'precheck(): a taxonomy only the post of this site carries, which the step clears, is asked as well (P2)', PW,
  "\t\tforeach ( is_array( $current ) ? $current['terms'] : array() as $term ) {\n\t\t\t$taxonomies[] = (string) $term['taxonomy'];\n\t\t}", "\t\tforeach ( array() as $term ) {\n\t\t\t$taxonomies[] = (string) $term['taxonomy'];\n\t\t}", 'unit', TERMS_RIGHTS)
e('writer-precheck-term-right', 'precheck(): what term_right() refuses refuses the step', PW,
  "\t\t\t$may = self::term_right( $taxonomy, isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array() );\n\t\t\tif ( true !== $may ) {", "\t\t\t$may = self::term_right( $taxonomy, isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array() );\n\t\t\tif ( false ) {", 'unit', TERMS_RIGHTS)
e('writer-term-right-taxonomy', 'term_right(): the right of the taxonomy to assign its terms, for the set', PW,
  "\t\tif ( ! current_user_can( (string) $object->cap->assign_terms ) ) {", "\t\tif ( false ) {", 'unit', TERMS_RIGHTS)
e('writer-term-right-slug', 'term_right(): a slug is the term it names on this site, asked by its id (post_format; P2)', PW,
  "\t\t\t\t$found = get_term_by( 'slug', (string) $key, $taxonomy );\n\t\t\t\tif ( ! is_object( $found ) || ! isset( $found->term_id ) ) {", "\t\t\t\t$found = false;\n\t\t\t\tif ( ! is_object( $found ) || ! isset( $found->term_id ) ) {", 'unit', TERMS_RIGHTS)
e('writer-term-right-make', 'term_right(): a slug this site has no term of asks the right of the taxonomy to make one (B6)', PW,
  "\t\t\t\t\tif ( ! current_user_can( (string) $object->cap->edit_terms ) ) {", "\t\t\t\t\tif ( false ) {", 'unit', TERMS_RIGHTS)
e('writer-term-right-assign', 'term_right(): every term asks assign_term through map_meta_cap', PW,
  "\t\t\tif ( ! current_user_can( 'assign_term', $id ) ) {", "\t\t\tif ( false ) {", 'unit', TERMS_RIGHTS)
BYTES_IT = 'testABackupGivesBytesThatAreNoUtf8BackAsBytesNotAsTheMarkerOfTheCanon'
BYTES_UNIT = 'testABackupReadsBackBytesThatAreNoUtf8AsBytesNotAsTheMarker'
e('backup-decode', 'get(): the backup reads back through the inverse of the one spelling, bytes as bytes (P2)', BK,
  "\t\t$data = AB_MCP_Sync_Canon::decode( $text, 64 );", "\t\t$data = json_decode( $text, true, 64 );", 'it', BYTES_IT)
e('canon-decode-bytes', 'decode(): {"~b"} is its bytes again', CN,
  "\t\tif ( array( '~b' ) === $keys ) {", "\t\tif ( false ) {", 'canon', BYTES_UNIT)
e('canon-decode-roundtrip', 'decode(): a byte marker that does not read back (not its base64, UTF-8 after all) is no form of encode()', CN,
  "\t\t\tif ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $value['~b'] || 1 === preg_match( '//u', $bytes ) ) {", "\t\t\tif ( ! is_string( $bytes ) ) {", 'canon', BYTES_UNIT)
e('canon-decode-float-set', 'decode(): a float or set marker, which no such form carries, is refused', CN,
  "\t\tif ( array( '~f' ) === $keys || array( '~set' ) === $keys ) {", "\t\tif ( false ) {", 'canon', BYTES_UNIT)
e('canon-decode-key', 'decode(): a key marker, which no such form carries, is refused', CN,
  "\t\t\tif ( is_string( $key ) && 0 === strpos( $key, '~k:' ) ) {", "\t\t\tif ( false ) {", 'canon', BYTES_UNIT)
e('canon-decode-bare-float', 'decode(): a bare float is not the text of encode()', CN,
  "\t\t\tif ( is_float( $value ) ) {\n\t\t\t\t// encode() writes a float as {\"~f\"}; a bare float is not its text.", "\t\t\tif ( false ) {\n\t\t\t\t// encode() writes a float as {\"~f\"}; a bare float is not its text.", 'canon', BYTES_UNIT)

# ------------------------------- GitHub Codex round 3 on free#137 (one P1, two P2)
# The way back of the same run begins under the lock of the boundary, after
# the run is read again under it; the time of a call raised and the work
# derived from the limit, a write not run under one that does not carry it;
# the retention breaks a tie of the close by the younger run.
RACE = 'testTheWayBackOfARunDoesNotBeginBesideAStepBeingWrittenNorOnAPlanTheRunHasLeftBehind'
e('revert-begin-lock', 'begin(): the lock of the write boundary first; a step being written holds it, the way back does not begin beside it (P1)', RV,
  "\t\t\t$lock = AB_MCP_Change_Lock::take();", "\t\t\t$lock = true;", 'it', RACE)
e('revert-begin-begun', 'begin_same_run(): a step begun under the lock is refused, not taken as interrupted (P1)', RV,
  "\t\t\tif ( 'begun' === $step['state'] ) {\n\t\t\t\treturn new WP_Error( 'ab_mcp_sync_run_begun'", "\t\t\tif ( false ) {\n\t\t\t\treturn new WP_Error( 'ab_mcp_sync_run_begun'", 'it', RACE)
e('revert-begin-restate', 'begin_same_run(): the plan confirmed against the run as it is under the lock; a step that wrote in between refuses it (P1)', RV,
  "\t\tif ( ! hash_equals( (string) $plan['state'], (string) $again['state'] ) ) {", "\t\tif ( false ) {", 'it', RACE)
TIME_UNIT = 'testTheTimeOfACallIsRaisedWhereItTakesAndTheWorkIsDerivedFromTheLimitItHas'
TIME_IT = 'testAWriteStepIsNotRunUnderATimeLimitThatDoesNotCarryItWhileTheCheckGoesOnWithASmallerWork'
e('means-time-raise', 'raise(): a limit under WANTED_TIME is asked to go up where set_time_limit() takes (P2)', RS,
  "\t\tif ( $before > 0 && $before < self::WANTED_TIME && function_exists( 'set_time_limit' ) ) {", "\t\tif ( false ) {", 'unit', TIME_UNIT)
e('means-time-budget-half', 'budget(): half the limit as the work of a call', RS,
  "\t\treturn max( 1, min( self::WORK_MAX, intdiv( $limit, 2 ) ) );", "\t\treturn max( 1, min( self::WORK_MAX, $limit ) );", 'unit', TIME_UNIT)
e('means-time-budget-max', 'budget(): never more than WORK_MAX', RS,
  "\t\treturn max( 1, min( self::WORK_MAX, intdiv( $limit, 2 ) ) );", "\t\treturn max( 1, intdiv( $limit, 2 ) );", 'unit', TIME_UNIT)
e('means-time-writes', 'carries_write(): a limit under MIN_TIME carries no write step', RS,
  "\t\treturn (int) $limit <= 0 || (int) $limit >= self::MIN_TIME;", "\t\treturn true;", 'unit', TIME_UNIT)
e('engine-time-writes', 'next(): a write step and a step of the way back are not run under a limit that does not carry them, with the way (P2)', EN,
  "\t\tif ( in_array( $picked['op'], array( 'write', 'revert' ), true ) && ! $time['writes'] ) {", "\t\tif ( false ) {", 'it', TIME_IT)
e('engine-time-work', 'next(): the work of the call is derived from the limit it has, not the whole WORK_MAX', EN,
  "\t\tself::$work = (int) $time['budget'];", "\t\tself::$work = AB_MCP_Sync_Resources::WORK_MAX;", 'it', TIME_IT)
e('schema-keep-tie', 'backup_rows(): on a tie of the close the younger run comes first (P2)', SC,
  "\t\t\t\treturn 0 !== $by_time ? $by_time : $b <=> $a;", "\t\t\t\treturn 0 !== $by_time ? $by_time : $a <=> $b;", 'unit', 'testTheRetentionBreaksATieOfTheSameCloseByTheYoungerRun')


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
 * the package from one reading with its term names checked. GitHub Codex
 * round 1 on free#137: the state of a part from the read back, never from
 * what a call answered; the parts put back at one place (put_back()), the
 * row first, every part read again after; a target changed since the
 * report; the right to remove a key only this site has; a step back once,
 * also across runs of the way back; the key slashed as the values. Codex
 * round 6: ownership a mark of its own (own, own_after, own_target), set
 * where a call of the step's own changed the part (post other than pre),
 * never from the state of a part; decide() the one place that says whether
 * a part goes back (every clause, the boundary C of decision 18), the parts
 * decided afresh after the row went back (N1 to N4). The decision of
 * 07.11.2026 («A mit altem Wert»): any deviation within the step (another
 * hand, a refused or failed call, a read back other than written) ends the
 * step, nothing of the object is put back within the boundary or by the way
 * back, every part not as the backup is left with its value before (short,
 * never a credential, capped per answer), decide() leaves every part of a
 * deviated object and every part never read back; the turn within the
 * boundary, A2, the ownership of the default category on the way back and
 * the way back by the target of an interrupted step are gone. GitHub Codex
 * round 2 on free#137: the way back begins with all of its steps or not at
 * all (one transaction, also for a run made), the rights at the object asked
 * again right before a part goes back, the right to set the terms of every
 * taxonomy the step touches (by id, by slug, one only this site has), the
 * backup giving bytes that are no UTF-8 back as bytes; the mark of another
 * hand on a part the way back changed is gone (the halt decides). Codex
 * round 18: edit_post first for every part on the way back, the terms too;
 * the second read of the kind as it writes catches a hook of the rights
 * check that changes the part. GitHub Codex round 3: the way back of the
 * same run begins under the lock of the boundary and against the run as it
 * is under it; the time of a call raised where set_time_limit() takes and
 * the work derived from the limit, no write under one that does not carry
 * it; the retention breaks a tie of the close by the younger run.
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
$bk = 'includes/sync/class-sync-backup.php';
$cn = 'includes/sync/class-sync-canon.php';

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
