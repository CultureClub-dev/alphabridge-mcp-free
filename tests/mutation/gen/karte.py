#!/usr/bin/env python3
"""Writes tests/mutation/lists/karte.php: one entry per clause of Z8 branch
12 (gratis/z8-karte; decision 35 of the plan of 01.11.2026, the moments of
the plan of the incentives of 03.11.2026): the section «Takeover» of the
page «Staging» (AB_MCP_Transfer, AB_MCP_Transfer_View, the handlers of
AB_MCP_Admin), transfer in wp_staging_status, the moments M1 to M3 with
their rules (AB_MCP_Transfer_Moments), the way back of a person
(AB_MCP_Sync_Revert::plan_for_person(), begin_for_person()), the last
report (AB_MCP_Sync_Report::latest()), step 7 of the prompt
staging_workflow, the sentence of the server instructions for a site of a
pair (AB_MCP_Staging::paired_way()) and wp_site_sync in PRO_TOOLS.

Every search is checked to occur exactly once in its file, and the test of
its expect to be a method of the class its run names, before the list is
written; each entry runs that test only.

    python3 tests/mutation/gen/karte.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/karte.php). Development only:
/tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
TR = 'includes/class-transfer.php'
MO = 'includes/class-transfer-moments.php'
VW = 'includes/class-transfer-view.php'
AD = 'includes/class-admin.php'
ST = 'includes/class-staging.php'
RC = 'includes/class-rest-controller.php'
GD = 'includes/class-guidance.php'
TS = 'includes/tools/class-tools-staging.php'
RV = 'includes/sync/class-sync-revert.php'
RP = 'includes/sync/class-sync-report.php'
RU = 'includes/sync/class-sync-run.php'
PT = 'includes/sync/class-sync-pairs-tool.php'
SH = 'includes/sync/class-sync-shown.php'
PR = 'includes/sync/class-sync-pairs.php'
FILEVAR = {SH: '$sh', PR: '$pr', TR: '$tr', MO: '$mo', VW: '$vw', AD: '$ad', ST: '$st', RC: '$rc', GD: '$gd', TS: '$ts', RV: '$rv', RP: '$rp', RU: '$ru', PT: '$pt'}

RUNS = {
    'u': ('unit', 'StagingTransferTest'),
    'flow': ('unit', 'StagingWorkflowTest'),
    'status': ('unit', 'StagingStatusTest'),
    'guide': ('unit', 'GuidanceTest'),
    'it': ('it', 'StagingTransferTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('karte/' + id_, clause, file, search, replace, run, expect))


# The tests.
U_NONE = 'testASiteWithoutAPairIsOnNoSide'
U_KEY = 'testTheStagingSiteReportsItsKeyWithTheFingerprint'
U_ANCHOR = 'testTheProductionSiteReportsTheAnchorPreparedThenActiveAndTheTables'
U_M1 = 'testM1FromTenObjectPairsARunCanTakeOver'
U_M2 = 'testM2ForAQualifiedCloseWithStepsCallsAndTheDurationOfTheRun'
U_M3 = 'testM3FromTheThirdPairOfARunTakenBack'
U_UNPUB = 'testNoEditionPartShowsWhileNoFeatureIsPublished'
U_RULES = 'testTheEditionPartShowsOnlyWhereEveryRuleHolds'
U_ONE = 'testOneNoticeAtATimeByPriorityEachOncePerSite'
U_FIRST = 'testTheFirstReportSeenStaysTheFirst'
U_KEEP = 'testTheBackupsOfARunAreKeptByTheRetentionContract'
U_REPORT = 'testTheReportCountsWhatARunCanTakeOverAndM1OnceItIsDone'
U_REST = 'testTheRestNamesTheStepsNotRunWithTheirObjects'
U_STORE = 'testTheProofOfTheStoreCarriesTheRuleForTheHostOnlyForTheUploads'
U_INT = 'testAnInterruptedRunComesFirstWithItsJournalItsRestAndCloseAsChecked'
U_FORMS = 'testEveryFormPostsToItsActionWithANonceBoundToItsObjectAndTheStateThePageShowed'
U_NOTICE = 'testANoticeCarriesBothPartsTheLinksAndTheSwitchOnce'
U_PAGE = 'testTheSectionStandsOnThePageStagingBelowTheCard'
U_HANDLERS = 'testEveryHandlerNeedsManageOptionsItsNonceAndAPost'
U_HOOKS = 'testTheHandlersAreHookedForAdminPostOnly'
U_SWITCH = 'testTheSwitchHidesAndShowsTheEditionNotesAndThePageShowsTheOutcomeOnce'
F_LIVE = 'testAPairedProductionSiteIsToldTheWayOfTheTakeoverAndWhatComesFirst'
F_STAGING = 'testAPairedStagingSiteIsToldThatTheProductionSiteTakesItOver'
F_NOWAY = 'testNoWayOfAPairWithoutItsToolForThisConnectionOrWithoutAPair'
F_PROMPT = 'testThePromptComesWithTheToolOnceWithItsArgumentsAndSteps'
S_KEYS = 'testTheReportCarriesTheStateAroundTheChecklist'
G_SYNC = 'testTheTakeoverInBundledStepsIsNamedAsNotInThisEdition'
IT_NORMAL = 'testAfterAReportThePageShowsTheRunItsStepsAndTakesAPairBackAsThePersonSawIt'
IT_M3 = 'testFromTheThirdPairOfARunTakenBackM3AndItsNoticeOnlyOncePublished'
IT_M1 = 'testAReportWithTenObjectPairsARunCanTakeOverIsM1'
IT_ABORT = 'testAPersonAbortsAnOpenRunOfAnotherConnectionAndClosesAnInterruptedRunAsChecked'
IT_PAIRS = 'testAPairIsDiscardedAsThePageShowedItAndNotOnceItChanged'
IT_PRINT = 'testCloseAsCheckedClosesOnlyTheInterruptionThePageShowed'
IT_RACE = 'testADiscardDeletesOnlyThePairAsThePageShowedIt'
IT_BUSY = 'testADiscardWaitsForAStepOfARunBeingWritten'
IT_NOKEY = 'testWithoutTheKeyTheRunsStayOnThePageAndInTheStatus'
IT_NOPAIR = 'testWithoutThePairTheRunsStayOnThePageAndInTheStatus'
IT_PINNED = 'testTheRunThatWroteStaysInViewBehindTenNewerRunsOfTheWayBack'
IT_JOURNAL_PRINT = 'testTheFormOfAnInterruptedRunCarriesThePrintOfTheJournalItShows'
IT_ROW_PRINT = 'testTheFormOfAPairCarriesTheStateOfTheRowItShows'
IT_GO_ON_KEY = 'testWithoutTheKeyAWayBackIsNotOfferedToGoOnAndNoWayNeedsTheKey'
IT_OLDER = 'testOlderRunsTurnThePageOfThePageStaging'
IT_CAP = 'testTheRunsToActOnAreAtMostAFixedNumberAndTheRestIsCounted'
IT_SETTLE = 'testCloseAsCheckedSettlesTheRunFromItsOneReading'
IT_ONCE = 'testTheCloseReadsTheStepsOnceAndComparesAndActsOnThatReading'
U_MORE = 'testBeyondTheFixedNumberOfRunsToActOnASentenceCountsTheRestAndNamesTheWay'
U_NOKEY = 'testWithoutTheKeyTheRunsStayWithTheirJournalAndCloseAsChecked'
U_PINNED = 'testTheRunsToActOnStandAboveThePageOfTheListWithTheWayToOlderRuns'
U_RESULT = 'testTheJournalShowsTheOutcomeOfAStepOfTheWayBackAsTheToolReportsIt'

# ----------------------------------------------------- PRO_TOOLS, prompt
e('pro-tools-site-sync', 'PRO_TOOLS: wp_site_sync is named as a tool of Pro (a call gets the edition, not «Unknown tool»)', GD,
  "\t\t'wp_site_sync',\n", "", 'guide', G_SYNC)
e('prompt-step-7', 'staging_workflow, step 7: report, approval, takeover, check, way back', TS,
  '"7. Carry it over: report, approval, takeover, check, way back. ', '"7. Carry it over one object at a time. ', 'flow', F_PROMPT)
e('prompt-way-back', 'staging_workflow, step 7: the way back pair by pair, also on the page «Staging»', TS,
  "The way back: wp_site_revert takes back what a run wrote, pair by pair, and a person can do the same on the page AlphaBridge → Staging of the production site. ", "", 'flow', F_PROMPT)
e('prompt-approval', 'staging_workflow, step 7: the approval of the person before the takeover', TS,
  "The approval: show the person what the report says will change, and take over only what they approve. ", "", 'flow', F_PROMPT)

# ------------------------------------------- the instructions of a pair
e('instructions-paired', 'the server instructions carry the way of a site of a pair', RC,
  "\t\t\t$instructions .= AB_MCP_Staging::paired_way( $this->registry );\n", "", 'flow', F_LIVE)
e('paired-needs-pair', 'paired_way(): only a site with a pair', ST,
  "\t\tif ( null === $record['role'] || null === $record['pair'] ) {", "\t\tif ( null === $record['role'] ) {", 'flow', F_NOWAY)
e('paired-tool-of-side', 'paired_way(): the tool it names first by the side (wp_site_push, wp_site_export)', ST,
  "\t\t$tool       = $production ? 'wp_site_push' : 'wp_site_export';", "\t\t$tool       = 'wp_site_push';", 'flow', F_NOWAY)
e('paired-tool-on', 'paired_way(): the tool switched on', ST,
  "! AB_MCP_Settings::is_tool_enabled( $tool, $def ) || ", "", 'flow', F_NOWAY)
e('paired-scope', 'paired_way(): the tool allowed for the access level of the token', ST,
  " || ! AB_MCP_Tool_Registry::scope_allows( AB_MCP_Auth::current_scope(), $tool, $def ) ) {", " ) {", 'flow', F_NOWAY)
e('paired-staging', 'paired_way(): the staging site is told that the production site takes over', ST,
  "\t\tif ( ! $production ) {\n\t\t\treturn ' This site is the staging site of a pair", "\t\tif ( false ) {\n\t\t\treturn ' This site is the staging site of a pair", 'flow', F_STAGING)
e('paired-first', 'paired_way(): while no key is confirmed, that comes first', ST,
  "\t\t$first = null === AB_MCP_Sync_Anchor::active() ? ", "\t\t$first = false ? ", 'flow', F_LIVE)

# ------------------------------------------------- transfer in the status
e('status-transfer', 'wp_staging_status reports transfer', ST,
  "\t\t$out['transfer'] = AB_MCP_Transfer::status();\n", "", 'status', S_KEYS)
e('status-staging-key', 'transfer: the staging site with its key and fingerprint', TR,
  "\t\t\t\t'key'  => null === $key ? null : array( 'fingerprint' => (string) $key['fingerprint'] ),", "\t\t\t\t'key'  => null,", 'u', U_KEY)
e('status-none', 'transfer: a site without a confirmed production role and pair is on no side', TR,
  "\t\tif ( ! is_string( $partner ) ) {", "\t\tif ( false ) {", 'u', U_NONE)
e('status-anchor-first', 'transfer: without an active anchor the way to confirm it', TR,
  "\t\tif ( null === $out['anchor']['active'] ) {", "\t\tif ( false ) {", 'u', U_ANCHOR)
e('status-prepared', 'transfer: a prepared key says it waits for its confirmation', TR,
  "\t\t\t$out['next'] = null === $out['anchor']['prepared']", "\t\t\t$out['next'] = true", 'u', U_ANCHOR)
e('status-tables', 'transfer: without the tables the way to them', TR,
  "\t\tif ( ! $out['tables'] ) {", "\t\tif ( false ) {", 'u', U_ANCHOR)
e('anchor-via', 'transfer: how the key was prepared', TR,
  "'via'         => 'route' === $prepared['via'] ? 'fetched' : 'pairing code',", "'via'         => 'fetched',", 'u', U_ANCHOR)
e('status-begun', 'transfer: a step begun whose request ended is named, not settled', TR,
  "\t\t\t\t$out['begun']   = $begun;\n", "", 'it', IT_ABORT)
e('status-lock', 'transfer: a step begun while the lock is held fresh is running', TR,
  "\t\t\tif ( AB_MCP_Sync_Run::lock_fresh() ) {", "\t\t\tif ( true ) {", 'it', IT_ABORT)
e('rest-planned', 'rest(): only the steps not run', TR,
  "\t\t\tif ( 'planned' !== $step['state'] ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'u', U_REST)
e('rest-object', 'rest(): each with its object from the plan', TR,
  "\t\t\t} elseif ( is_array( $item ) ) {", "\t\t\t} elseif ( false ) {", 'u', U_REST)
e('rest-takes-back', 'rest(): a step of the way back names the step it takes back', TR,
  "\t\t\t\t$entry['takes_back'] = (int) $step['parts']['forward_seq'];\n", "", 'u', U_REST)

# --------------------------------------------------------------- view()
e('view-settle', 'view(): the run that is not closed settled for the page (decision 18)', TR,
  "\t\t\t$settled      = AB_MCP_Sync_Run::settle( $open );", "\t\t\t$settled      = $open;", 'it', IT_ABORT)
e('view-interrupted', 'view(): an interrupted run with its journal', TR,
  "\t\t\tif ( 'interrupted' === $open['state'] ) {", "\t\t\tif ( false ) {", 'it', IT_ABORT)
e('view-report-seen', 'view(): a report done is the first report seen', TR,
  "\t\t\t\tAB_MCP_Transfer_Moments::report_seen( $report['created'] );\n", "", 'it', IT_M1)
e('view-runs-seen', 'view(): a site with a run has seen a report', TR,
  "\t\t\t\tAB_MCP_Transfer_Moments::report_seen( (int) end( $page['runs'] )['created'] );\n", "", 'it', IT_ABORT)
e('view-m1-due', 'view(): M1 is due with a large report', TR,
  "\t\t\t\t\t$due[] = 'm1';\n", "", 'it', IT_M1)
e('view-m3-due', 'view(): M3 is due with the steps of a run', TR,
  "\t\t\t\t\t$due[] = 'm3';\n", "", 'it', IT_M3)
e('report-takeable', 'report_view(): what a run takes over: take_over and create', TR,
  "\tconst TAKEABLE = array( 'take_over', 'create' );", "\tconst TAKEABLE = array( 'take_over' );", 'u', U_REPORT)
e('row-abort', 'run_row(): abort for an open run of the type push only', TR,
  "\t\t\t'abort'   => 'push' === $run['type'] && 'open' === $run['state'],", "\t\t\t'abort'   => 'push' === $run['type'],", 'it', IT_NORMAL)
e('backups-open', 'backups(): kept while the run is not closed', TR,
  "\t\tif ( ! in_array( (string) $run['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {\n\t\t\treturn array( 'state' => 'open'",
  "\t\tif ( false ) {\n\t\t\treturn array( 'state' => 'open'", 'u', U_KEEP)
e('backups-unknown', 'backups(): the table not read keeps everything', TR,
  "\t\tif ( ! is_array( $keep ) ) {", "\t\tif ( false ) {", 'u', U_KEEP)
e('backups-gone', 'backups(): not kept by the retention contract', TR,
  "\t\tif ( empty( $keep[ $id ] ) ) {", "\t\tif ( false ) {", 'u', U_KEEP)
e('backups-no-period', 'backups(): a close without a stored period', TR,
  "\t\tif ( 0 === $until ) {", "\t\tif ( false ) {", 'u', U_KEEP)
e('backups-until', 'backups(): the period of its close', TR,
  "\t\tif ( $until > $now ) {", "\t\tif ( $until >= $now + 86400 * 365 ) {", 'u', U_KEEP)
e('backups-last-three', 'backups(): past the period, a qualified close is one of the last three', TR,
  "\t\treturn (int) $run['forward_closed_at'] > 0", "\t\treturn false", 'u', U_KEEP)
e('pairs-back-done', 'pairs_back(): only a step of the way back that is done', TR,
  " || 'done' !== $step['state'] || ", " || ", 'it', IT_NORMAL)
e('plan-state', 'run_view(): each pair with the state of its plan for the form', TR,
  "'plan_state' => is_wp_error( $one ) ? null : (string) $one['state']", "'plan_state' => null", 'it', IT_NORMAL)
e('store-rule', 'store(): the rule for the host for the uploads', TR,
  "\t\tif ( 'uploads' === $proof['where'] ) {", "\t\tif ( false ) {", 'u', U_STORE)
e('store-rule-only', 'store(): no rule outside the web root', TR,
  "\t\tif ( 'uploads' === $proof['where'] ) {", "\t\tif ( true ) {", 'u', U_STORE)

# ------------------------------------------------ what a person does
e('abort-type', 'abort(): a run of the way back is not aborted', TR,
  "\t\tif ( 'push' !== $run['type'] ) {", "\t\tif ( false ) {", 'it', IT_NORMAL)
e('abort-engine', 'abort(): through the abort of the tool', TR,
  "\t\t$closed = AB_MCP_Sync_Engine::abort( $run );", "\t\t$closed = $run;", 'it', IT_ABORT)
e('checked-confirmed', 'close_checked(): only behind the nonce', TR,
  "\t\t$closed = AB_MCP_Sync_Run::close_checked( (int) $run_id, get_current_user_id(), $confirmed, (string) $print );",
  "\t\t$closed = AB_MCP_Sync_Run::close_checked( (int) $run_id, get_current_user_id(), true, (string) $print );", 'it', IT_ABORT)
e('checked-print', 'close_checked(): on the interruption the page showed (Codex round 1, A)', TR,
  "\t\t$closed = AB_MCP_Sync_Run::close_checked( (int) $run_id, get_current_user_id(), $confirmed, (string) $print );",
  "\t\t$closed = AB_MCP_Sync_Run::close_checked( (int) $run_id, get_current_user_id(), $confirmed );", 'it', IT_PRINT)
e('form-checked-print', 'the form «Close as checked» carries the print of the interruption', VW,
  "<input type=\"hidden\" name=\"state\" value=\"' . esc_attr( (string) $run['print'] ) . '\">", "", 'u', U_INT)
e('go-on-closed', 'go_on(): action=next until the run of the way back is closed', TR,
  "\t\t\tif ( in_array( (string) $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {\n\t\t\t\tbreak;",
  "\t\t\tif ( false ) {\n\t\t\t\tbreak;", 'it', IT_NORMAL)
e('view-without-key', 'view(): the runs without the key of the staging site', TR,
  "\t\t$view['anchored'] = $anchored;\n", "\t\t$view['anchored'] = $anchored;\n\t\tif ( ! $anchored ) {\n\t\t\treturn $view;\n\t\t}\n", 'it', IT_NOKEY)
e('status-run-without-key', 'status(): the run without the key', TR,
  "\t\t\t$open = $out['tables'] ? self::open_or_null() : null;", "\t\t\t$open = null;", 'it', IT_NOKEY)
e('status-run-without-pair', 'status(): the run without the pair', TR,
  "\t\t\t$open = self::open_or_null();\n", "\t\t\t$open = null;\n", 'it', IT_NOPAIR)
e('html-runs-without-key', 'html(): the runs without the key, and why the rest is not shown', VW,
  "\t\tif ( null === $active && ! $runs ) {", "\t\tif ( null === $active ) {", 'u', U_NOKEY)
e('html-why-not', 'html(): the sentence why the report and the pairs are not shown', VW,
  "\t\tif ( null === $active ) {\n\t\t\t// The runs stay without the key", "\t\tif ( false ) {\n\t\t\t// The runs stay without the key", 'u', U_NOKEY)
e('runs-after', 'view(): a page of the list after the run the page before ended with', TR,
  "\t\t$page  = AB_MCP_Sync_Run::page( $after, self::RUNS );", "\t\t$page  = AB_MCP_Sync_Run::page( 0, self::RUNS );", 'it', IT_PINNED)
e('pinned-way-back-open', 'way_back_open(): a write step no way back named', TR,
  " && ! isset( $named[ (int) $step['seq'] ] ) ) {", " ) {", 'it', IT_PINNED)
e('html-older-runs', 'runs_html(): the way to older runs', VW,
  "\t\tif ( null !== $next ) {\n\t\t\t$html .= '<p><a href=\"' . esc_url( self::url( array( 'runs_after'", "\t\tif ( false ) {\n\t\t\t$html .= '<p><a href=\"' . esc_url( self::url( array( 'runs_after'", 'u', U_PINNED)
e('html-pinned', 'runs_html(): the runs to act on above the page', VW,
  "\t\t\t$html .= self::runs_table( $pinned, 'ab-staging-transfer__pinned' );\n", "", 'u', U_PINNED)
e('journal-result-parts', 'journal_html(): the outcome of each part from step.result (Codex round 1, B)', VW,
  "\t\t\tforeach ( isset( $result['parts'] ) && is_array( $result['parts'] ) ? $result['parts'] : array() as $part ) {", "\t\t\tforeach ( array() as $part ) {", 'u', U_RESULT)
e('journal-finding', 'journal_html(): the finding of a step', VW,
  "\t\t\t$finding = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '';", "\t\t\t$finding = '';", 'u', U_RESULT)
e('journal-back-of', 'journal_html(): a step of the way back with the step and object it takes back', VW,
  "\t\t\tif ( null !== $back ) {\n\t\t\t\t// A step of the way back", "\t\t\tif ( false ) {\n\t\t\t\t// A step of the way back", 'u', U_RESULT)
e('outcome-once', 'take_outcome(): the outcome of a form shown once', TR,
  "\t\tdelete_transient( $key );\n", "", 'u', U_SWITCH)

# ------------------------------------------- the way back of a person
e('person-closed', 'plan_for_person(): a closed run of the type push only', RV,
  "\t\tif ( 'push' !== $run['type'] || ! in_array( $run['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {",
  "\t\tif ( 'push' !== $run['type'] ) {", 'it', IT_NORMAL)
e('person-confirmed', 'begin_for_person(): only behind the nonce', RV,
  "\t\tif ( true !== $confirmed ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_run_unconfirmed', 'A way back begins",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_run_unconfirmed', 'A way back begins", 'it', IT_NORMAL)
e('person-state', 'begin_for_person(): only on the plan the page showed', RV,
  "\t\tif ( ! is_string( $state ) || ! hash_equals( (string) $plan['state'], $state ) ) {", "\t\tif ( false ) {", 'it', IT_NORMAL)
e('person-steps', 'begin_for_person(): only the pair the form names', RV,
  "\t\tif ( isset( $a['steps'] ) ) {\n\t\t\t$args['steps'] = $a['steps'];\n\t\t}\n\t\treturn self::begin( $plan, $args );",
  "\t\treturn self::begin( $plan, $args );", 'it', IT_NORMAL)
e('latest-index', 'latest(): the index of a report done', RP,
  "\t\t$best['index'] = is_array( $index ) ? $index : null;", "\t\t$best['index'] = null;", 'it', IT_NORMAL)

# ---------------------------------------------------------- the moments
e('m1-threshold', 'M1 from 10 object pairs (O14)', MO,
  "\tconst THRESHOLD = 10;", "\tconst THRESHOLD = 11;", 'u', U_M1)
e('m2-qualified', 'M2 for a qualified close only', MO,
  "\t\tif ( (int) ( $run['forward_closed_at'] ?? 0 ) <= 0 || ", "\t\tif ( ", 'u', U_M2)
e('m2-duration', 'M2: the duration closed − created', MO,
  "self::duration( (int) $run['closed'] - (int) $run['created'] )", "self::duration( (int) $run['updated'] - (int) $run['created'] )", 'u', U_M2)
e('m3-third', 'M3 from the third pair', MO,
  "\tconst PAIRS_BACK = 3;", "\tconst PAIRS_BACK = 2;", 'u', U_M3)
e('duration-minutes', 'duration(): minutes under an hour', MO,
  "\t\tif ( $minutes < 60 ) {", "\t\tif ( false ) {", 'u', U_M2)
e('priority', 'notice(): M1 before M3', MO,
  "\tconst PRIORITY = array( 'm1', 'm3' );", "\tconst PRIORITY = array( 'm3', 'm1' );", 'u', U_ONE)
e('notice-may-show', 'notice(): only a moment whose edition part may show', MO,
  "in_array( $moment, $due, true ) && self::may_show( $moment )", "in_array( $moment, $due, true )", 'u', U_UNPUB)
e('notice-shown', 'notice(): noted as shown, once per site', MO,
  "\t\t\t\tself::save( $state );\n\t\t\t\treturn $moment;", "\t\t\t\treturn $moment;", 'u', U_ONE)
e('may-hidden', 'may_show(): not with the notes hidden', MO,
  "\t\treturn ! $state['hidden']\n", "\t\treturn true\n", 'u', U_RULES)
e('may-report', 'may_show(): not before the first report', MO,
  "\t\t\t&& $state['first_report'] > 0\n", "", 'u', U_RULES)
e('may-published', 'may_show(): not before its feature is published', MO,
  "\t\t\t&& in_array( self::FEATURES[ $moment ], self::published(), true )\n", "", 'u', U_RULES)
e('may-once', 'may_show(): not a second time', MO,
  "\t\t\t&& ! isset( $state['shown'][ $moment ] );", "\t\t\t;", 'u', U_RULES)
e('possible-edition', 'possible(): not in an edition that has the features', MO,
  "\t\treturn ! in_array( $edition, array( 'pro', 'agency' ), true ) && ", "\t\treturn ", 'u', U_RULES)
e('possible-published', 'possible(): only with a feature published', MO,
  " && array() !== array_intersect( self::FEATURES, self::published() );", ";", 'u', U_UNPUB)
e('report-seen-first', 'report_seen(): the first report stays the first', MO,
  "\t\tif ( $state['first_report'] > 0 ) {\n\t\t\treturn;", "\t\tif ( false ) {\n\t\t\treturn;", 'u', U_FIRST)

# ------------------------------------------------------------- the view
e('view-interrupted-first', 'html(): an interrupted run in the section', VW,
  "\t\t\t$html .= self::interrupted_html( $view['interrupted'] );\n", "", 'u', U_INT)
e('view-m1-once', 'report_html(): M1 not as a quiet line beside its notice', VW,
  "\t\tif ( null !== $report['m1'] && ! ( is_array( $notice ) && 'm1' === $notice['moment'] ) ) {", "\t\tif ( null !== $report['m1'] ) {", 'u', U_NOTICE)
e('view-switch-once', 'html(): the switch in the notice where one shows, else at the end', VW,
  "\t\t$html .= null === $view['notice'] ? self::notes_html( $view['notes'] ) : '';", "\t\t$html .= self::notes_html( $view['notes'] );", 'u', U_NOTICE)
e('view-switch-possible', 'notes_html(): offered only where a note can show', VW,
  "\t\tif ( ! $notes['possible'] ) {", "\t\tif ( false ) {", 'u', U_NOTICE)
e('line-interrupted', 'interrupted_line(): only for an interrupted run', VW,
  "\t\tif ( ! is_array( $open ) || 'interrupted' !== $open['state'] ) {", "\t\tif ( ! is_array( $open ) ) {", 'u', U_INT)
e('nonce-checked', 'the form «Close as checked» signed for its run', VW,
  "wp_nonce_field( 'ab_mcp_transfer_checked_' . $id, ", "wp_nonce_field( 'ab_mcp_transfer_checked', ", 'u', U_INT)
e('nonce-back', 'the form of the way back signed for its run and step', VW,
  "wp_nonce_field( 'ab_mcp_transfer_back_' . $id . '_' . (int) $pair['seq'], ", "wp_nonce_field( 'ab_mcp_transfer_back_' . $id, ", 'u', U_FORMS)
e('form-back-state', 'the form of the way back carries the state of its plan', VW,
  "<input type=\"hidden\" name=\"state\" value=\"' . esc_attr( $pair['plan_state'] ) . '\">", "", 'u', U_FORMS)
e('nonce-abort', 'the form «Abort» signed for its run', VW,
  "wp_nonce_field( 'ab_mcp_transfer_abort_' . $id, ", "wp_nonce_field( 'ab_mcp_transfer_abort', ", 'u', U_FORMS)
e('form-abort-only', 'the form «Abort» only where a run can be aborted', VW,
  "\t\t\tif ( $run['abort'] ) {", "\t\t\tif ( true ) {", 'u', U_FORMS)
e('nonce-go-on', 'the form «Go on» signed for its run', VW,
  "wp_nonce_field( 'ab_mcp_transfer_go_on_' . $id, ", "wp_nonce_field( 'ab_mcp_transfer_go_on', ", 'u', U_FORMS)
e('nonce-discard', 'the form «Discard» signed for its pair', VW,
  "wp_nonce_field( 'ab_mcp_transfer_discard_' . (int) $pair['id'], ", "wp_nonce_field( 'ab_mcp_transfer_discard', ", 'u', U_FORMS)
e('form-discard-state', 'the form «Discard» carries the state the page showed', VW,
  "<input type=\"hidden\" name=\"state\" value=\"' . esc_attr( $pair['discard'] ) . '\">", "", 'u', U_FORMS)

# ------------------------------------- Codex rounds 1 and 2, the helper
e('status-journal', 'run_status(): an interrupted run with its journal, parts and rest (decision 18)', TR,
  "\t\tif ( $interrupted ) {\n\t\t\t$shown          = AB_MCP_Sync_Run::shown( $run, $steps );", "\t\tif ( false ) {\n\t\t\t$shown          = AB_MCP_Sync_Run::shown( $run, $steps );", 'it', IT_ABORT)
e('helper-compare', 'AB_MCP_Sync_Shown::done(): nothing happens where the print of the form does not hold', SH,
  "\t\t\t\tif ( null !== $expected && ( ! is_string( $expected ) || ! hash_equals( (string) call_user_func( $print, $data ), $expected ) ) ) {",
  "\t\t\t\tif ( false ) {", 'it', IT_PRINT)
e('helper-compare-pairs', 'AB_MCP_Sync_Shown::done(): the same for «Discard»', SH,
  "\t\t\t\tif ( null !== $expected && ( ! is_string( $expected ) || ! hash_equals( (string) call_user_func( $print, $data ), $expected ) ) ) {",
  "\t\t\t\tif ( null === $expected ) {", 'it', IT_RACE)
e('helper-lock', 'AB_MCP_Sync_Shown::done(): under the lock of the write boundary', SH,
  "\t\treturn AB_MCP_Sync_Run::under_lock(\n\t\t\tstatic function () use ( $read, $print, $expected, $act, $refusal ) {",
  "\t\treturn call_user_func(\n\t\t\tstatic function () use ( $read, $print, $expected, $act, $refusal ) {", 'it', IT_BUSY)
e('close-one-reading', 'close_checked(): the print from the reading the close acts on, not a reading of its own', RU,
  "\t\t\t\treturn self::interruption_print( $read['run'], $read['steps'] );",
  "\t\t\t\treturn self::interruption_print( $read['run'], self::steps( (int) $read['run']['run_id'] ) );", 'it', IT_ONCE)
e('close-settle', 'interruption_read(): a step begun by a request that ended makes the run interrupted, from the one reading', RU,
  "\t\t\t\tif ( 'begun' === $step['state'] && ! isset( self::$own[ (int) $run_id ][ (int) $step['seq'] ] ) ) {\n\t\t\t\t\t$moved = self::set_state( $run_id, $run['state'], 'interrupted' );",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\t$moved = self::set_state( $run_id, $run['state'], 'interrupted' );", 'it', IT_SETTLE)
e('view-one-reading', 'run_view(): journal and print from one reading', TR,
  "\t\t$shown = is_wp_error( $read ) ? $read : AB_MCP_Sync_Run::shown( $run, $read['read'] );",
  "\t\t$shown = is_wp_error( $read ) ? $read : AB_MCP_Sync_Run::shown( $run );", 'it', IT_JOURNAL_PRINT)
e('pairs-row-print', 'view(): the print of the form of a pair from the row shown', TR,
  "\t\t\t\t$state                    = $shown_p['print'][ (int) $pair['id'] ];",
  "\t\t\t\t$state                    = AB_MCP_Sync_Pairs_Tool::state( array( 'action' => 'discard', 'pair_ids' => array( (int) $pair['id'] ) ) );", 'it', IT_ROW_PRINT)
e('discard-row-condition', 'discard_row(): one row deleted, else changed', PR,
  "\t\treturn 1 === (int) $done ? true : self::changed();\n\t}\n\n\t/**\n\t * The rows of a page",
  "\t\treturn true;\n\t}\n\n\t/**\n\t * The rows of a page", 'it', IT_ROW_PRINT)
e('row-go-on', 'run_row(): go on with an open run of the way back of this person, with the key', TR,
  "\t\t\t'go_on'   => 'revert' === $run['type'] && 'open' === $run['state'] && null === AB_MCP_Sync_Run::owner_refusal( $run ) && self::keyed(),",
  "\t\t\t'go_on'   => 'revert' === $run['type'] && 'open' === $run['state'] && null === AB_MCP_Sync_Run::owner_refusal( $run ),", 'it', IT_GO_ON_KEY)
e('row-go-on-any', 'run_row(): go on only with an open run of the way back', TR,
  "\t\t\t'go_on'   => 'revert' === $run['type'] && 'open' === $run['state'] && null === AB_MCP_Sync_Run::owner_refusal( $run ) && self::keyed(),",
  "\t\t\t'go_on'   => false,", 'it', IT_NORMAL)
e('next-without-key', 'run_status(): without the key no way that needs it', TR,
  "\t\tif ( ! self::keyed() ) {\n\t\t\t// The ways of the tools need the key", "\t\tif ( false ) {\n\t\t\t// The ways of the tools need the key", 'it', IT_GO_ON_KEY)
e('pinned', 'view(): the runs to act on whatever page it is', TR,
  "\t\t\t$view['pinned']       = $pinned['runs'];", "\t\t\t$view['pinned']       = array();", 'it', IT_PINNED)
e('pinned-not-twice', 'pinned(): not a run on the page already', TR,
  "static fn( $id ) => ! isset( $on[ (int) $id ] )", "static fn( $id ) => true", 'it', IT_PINNED)
e('pinned-cap', 'pinned(): at most PINNED', TR,
  "\t\t\tif ( count( $out['runs'] ) >= self::PINNED ) {", "\t\t\tif ( false ) {", 'it', IT_CAP)
e('pinned-more', 'pinned(): the rest counted', TR,
  "\t\t\t\t$out['more'] = count( $ids ) - $i;", "\t\t\t\t$out['more'] = 0;", 'it', IT_CAP)
e('html-more', 'runs_html(): the sentence with the number of further runs and the way', VW,
  "\t\t\tif ( $more > 0 ) {", "\t\t\tif ( false ) {", 'u', U_MORE)
e('page-runs-after', 'render_staging(): the page of the runs passed on', AD,
  "\t\t\t\t'runs_after'  => isset( $_GET['runs_after'] ) ? absint( wp_unslash( $_GET['runs_after'] ) ) : 0,", "\t\t\t\t'runs_after'  => 0,", 'it', IT_OLDER)

# ------------------------------------------------------------ handlers
e('handler-hook', 'the handler of «Abort» hooked', AD,
  "\t\tadd_action( 'admin_post_ab_mcp_transfer_abort', array( $this, 'handle_transfer_abort' ) );\n", "", 'u', U_HOOKS)
e('handler-guard', 'transfer_guard(): manage_options', AD,
  "\tprivate function transfer_guard( $what, $field ) {\n\t\t$this->guard();", "\tprivate function transfer_guard( $what, $field ) {", 'u', U_HANDLERS)
e('handler-post', 'transfer_post_only(): a POST only', AD,
  "\t\tif ( 'POST' !== $method ) {\n\t\t\twp_die( esc_html( 'Nothing was changed: the forms of the takeover",
  "\t\tif ( false ) {\n\t\t\twp_die( esc_html( 'Nothing was changed: the forms of the takeover", 'u', U_HANDLERS)
e('handler-nonce-object', 'transfer_guard(): the nonce bound to the object of the form', AD,
  "\t\tcheck_admin_referer( 'ab_mcp_transfer_' . $what . '_' . $id );", "\t\tcheck_admin_referer( 'ab_mcp_transfer_' . $what . '_' . ( $id ? 8 : 0 ) );", 'u', U_HANDLERS)
e('handler-back-nonce', 'handle_transfer_back(): the nonce bound to the run and the step', AD,
  "\t\tcheck_admin_referer( 'ab_mcp_transfer_back_' . $run_id . '_' . $seq );", "\t\tcheck_admin_referer( 'ab_mcp_transfer_back_' . $run_id . '_2' );", 'u', U_HANDLERS)
e('page-section', 'render_staging(): the section «Takeover» below the card', AD,
  "\t\techo AB_MCP_Transfer_View::html( $transfer ); // phpcs:ignore", "\t\techo ''; // phpcs:ignore", 'u', U_PAGE)


def test_of(expect):
    return expect.split(' with data set ')[0]


problems = []
ids = set()
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
 * The clauses of Z8 branch 12 (gratis/z8-karte; decision 35 of the plan of
 * 01.11.2026; the moments of the plan of the incentives of 03.11.2026): the
 * section «Takeover» of the page «Staging» with its forms and handlers,
 * transfer in wp_staging_status, the moments M1 to M3 and their rules, the
 * way back of a person, the last report, step 7 of the prompt
 * staging_workflow, the sentence of the server instructions for a site of
 * a pair, and wp_site_sync in PRO_TOOLS.
 *
 * Written by tests/mutation/gen/karte.py from the source; change the entries
 * there and run it again.
 *
 * Held by the unit suite (StagingTransferTest, StagingWorkflowTest,
 * StagingStatusTest, GuidanceTest) and, where the section reads or writes a
 * run, by the integration suite (StagingTransferTest; the sites running,
 * AB_IT_ROOT set).
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
open(os.path.join(ROOT, 'tests/mutation/lists/karte.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
