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
FILEVAR = {PU: '$pu', RV: '$rv', EN: '$en', PW: '$pw', GU: '$gu', RS: '$rs', RU: '$ru', SC: '$sc', TS: '$ts', G: '$g'}

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
COVERS = "\t\treturn ! self::$owner && is_array( self::$armed ) && isset( self::$armed['posts'][ (string) (int) $id ] ) && (int) self::$armed['until'] > self::now();"
e('guard-owner', 'covers(): the owner of the boundary passes', GU, COVERS, COVERS.replace('! self::$owner && ', ''), 'unit', LOCK)
e('guard-until', 'covers(): a record past its time covers nothing', GU, COVERS, COVERS.replace(" && (int) self::$armed['until'] > self::now()", ''), 'unit', LOCK)
e('guard-record-expiry', 'record(): a record past its time is removed', GU,
  "\t\tif ( (int) $read['until'] <= self::now() ) {", "\t\tif ( false ) {", 'unit', LOCK)
e('guard-rest-423', 'rest_pre_insert(): the status 423 of a locked object', GU,
  "\t\t\treturn new WP_Error( self::CODE, self::message(), array( 'status' => 423 ) );", "\t\t\treturn new WP_Error( self::CODE, self::message(), array( 'status' => 400 ) );", 'unit', LOCK)
e('guard-empty-content', 'empty_content(): the classic way of a covered post meets the core text', GU,
  "\t\treturn $id > 0 && self::covers( $id ) ? true : $maybe_empty;", "\t\treturn $maybe_empty;", 'unit', LOCK)
e('guard-meta-mid', 'meta_by_mid(): a meta id is refused by its post', GU,
  "\t\treturn is_object( $meta ) && isset( $meta->post_id ) && self::covers( (int) $meta->post_id ) ? false : $check;", "\t\treturn $check;", 'unit', LOCK)
e('guard-edit-lock', 'begin(): the edit lock of wp-admin on a covered post (decision 19)', GU,
  "\t\t\tupdate_post_meta( (int) $id, '_edit_lock', self::now() . ':' . (int) get_current_user_id() );", '', 'unit', LOCK)
e('guard-option-still', 'pre_update_option(): a covered option keeps its value', GU,
  "\t\treturn ! self::$owner && is_array( self::$armed ) && (int) self::$armed['until'] > self::now() ? $old_value : $value;", "\t\treturn $value;", 'unit', LOCK)
e('guard-term', 'pre_insert_term(): a new term of a covered taxonomy is refused', GU,
  "\t\tif ( ! self::$owner && is_array( self::$armed ) && in_array( (string) $taxonomy, (array) self::$armed['taxonomies'], true ) && (int) self::$armed['until'] > self::now() ) {", "\t\tif ( false ) {", 'unit', LOCK)
e('guard-rest-hook', 'arm(): the REST API of a covered type is hooked (another user sees the message)', GU,
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
  "\t\t\t$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'post', $parent );\n\t\t\tif ( null === $mapped ) {", "\t\t\t$mapped = AB_MCP_Sync_Refs::mapped_id( $plan, 'post', $parent );\n\t\t\tif ( false ) {", 'unit', LOCKS)
e('writer-type', 'target_of(): only posts and pages in this version', PW,
  "\t\tif ( ! in_array( $type, self::TYPES, true ) ) {", "\t\tif ( false ) {", 'unit', LOCKS)
e('writer-status', 'target_of(): the trash and an auto-draft are no target', PW,
  "\t\tif ( ! in_array( $status, self::STATUSES, true ) ) {", "\t\tif ( false ) {", 'unit', LOCKS)
e('writer-taxonomy', 'target_of(): a taxonomy outside the rule is locked', PW,
  "\t\t\tif ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {", "\t\t\tif ( false ) {", 'unit', LOCKS)
e('writer-terms-set', 'terms_hash(): a set', PW,
  "\t\t$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );\n\t\tsort( $ids );", "\t\t$ids = array_values( array_map( 'intval', $ids ) );", 'unit', 'testTheHashesOfThePartsAreStableAndTermsAreASet')
e('writer-restore-meta-own', 'restore_meta(): back only where the bytes are what the step read back (decision 23)', PW,
  "\t\tif ( ! hash_equals( (string) $written, self::meta_hash( $now ) ) ) {", "\t\tif ( false ) {", 'it', 'testTheWayBackIsByteForByteAndLeavesWhatChangedSince')
e('writer-restore-row-own', 'restore_row(): back only where the row is what the step read back', PW,
  "\t\tif ( ! hash_equals( $written, $hash ) ) {", "\t\tif ( false ) {", 'it', 'testTheWayBackIsByteForByteAndLeavesWhatChangedSince')
e('writer-restore-future', 'restore_row(): a backed-up future whose date passed is «check needed» (decision 23)', PW,
  "\t\tif ( $saved !== $backup['post_status'] || '' !== AB_MCP_Tools_Content::status_date_refusal( $backup['post_type'], $current['row']['post_status'], $backup['post_status'], $gmt, true, (int) $now ) ) {", "\t\tif ( false ) {", 'it', 'testAScheduledStagingPostWhoseDatePassedIsRefusedAndTheWayBackAfterTheDateNeedsACheck')
e('writer-menu-automatic', 'precheck(): a page of the top level with the menu automatic (decision 21)', PW,
  "\t\tif ( 'page' === $type && 0 === (int) $prepared['postarr']['post_parent'] && in_array( $status, array( 'publish', 'future' ), true ) && self::menu_adds_pages() ) {", "\t\tif ( false ) {", 'it', 'testAPageOfTheTopLevelWithTheMenuAutomaticIsLockedAndTakenOverAsDraftWithTheOverride')

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

# ------------------------------------------------------- the retention
KEEP = 'testTheRetentionNamesTheRunsWhoseBackupsStay'
e('schema-keep-last', 'backup_runs(): the last KEEP_LAST qualified closes (decision 20, rule 2)', SC,
  "\t\t\tif ( $n <= self::KEEP_LAST || ( $days > 0 && $now - $at < $days * 86400 ) ) {", "\t\t\tif ( $n <= self::KEEP_LAST + 1 || ( $days > 0 && $now - $at < $days * 86400 ) ) {", 'unit', KEEP)
e('schema-days', 'backup_runs(): every qualified close within the days of the filter (rule 3)', SC,
  "\t\t\tif ( $n <= self::KEEP_LAST || ( $days > 0 && $now - $at < $days * 86400 ) ) {", "\t\t\tif ( $n <= self::KEEP_LAST ) {", 'unit', KEEP)
e('schema-reverts', 'backup_runs(): the runs a way back that is not closed needs (rule 4)', SC,
  "\t\t\t\t\t$keep[ (int) $needed ] = true;", '', 'unit', KEEP)
e('schema-unqualified', 'backup_runs(): an unqualified close for UNQUALIFIED_DAYS (rule 5)', SC,
  "\t\t\tif ( $now - (int) $row['closed'] < self::UNQUALIFIED_DAYS * 86400 ) {", "\t\t\tif ( true ) {", 'unit', KEEP)
e('schema-qualified-only', 'backup_runs(): only a qualified close counts among the last three', SC,
  "\t\t\tif ( (int) $row['forward_closed_at'] > 0 ) {", "\t\t\tif ( true ) {", 'unit', KEEP)

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
  "\t\t$qualified = $writes > 0 && $written === $writes;", "\t\t$qualified = false;", 'it', E2E)
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
 * on its way back running its steps of the way back only, the same run).
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
