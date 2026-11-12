#!/usr/bin/env python3
"""Writes tests/mutation/lists/einheitenpaare.php (Z8b branch 2b): one entry
per clause of Z44 at its one place (AB_MCP_Sync_Pairs::set_unit_base()), of
what confirms a base (AB_MCP_Sync_Unit_Proof, the record of a passed check of
AB_MCP_Confirm_Token), of the ways past it that refuse a unit (rebase(),
restore()), of the confirmation of units part by part and of V1 (one plan per
call), of the proposal of units, of V4, of the open part of a pair in the
report, and of the files of a proposal stored once (Behebung 1: A1 to A4, B5,
B6, C8). Every search is checked to occur exactly once in its file and the
test of its expect to be a method of the class its run names.

    python3 tests/mutation/gen/einheitenpaare.py
"""
import os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
PA = 'includes/sync/class-sync-pairs.php'
PT = 'includes/sync/class-sync-pairs-tool.php'
PR = 'includes/sync/class-sync-proposal.php'
UP = 'includes/sync/class-sync-unit-proposal.php'
DE = 'includes/sync/class-sync-decision.php'
CT = 'includes/class-confirm-token.php'
VAR = {PA: '$pa', PT: '$pt', PR: '$pr', UP: '$up', DE: '$de', CT: '$ct'}
CLASSES = {'guard': ('unit', 'SyncUnitBaseGuardTest'), 'env': ('unit', 'SyncEnvTest'), 'it': ('it', 'SyncUnitPairsTest'), 'prop': ('it', 'SyncProposalTest'), 'man': ('it', 'SyncManifestTest')}
entries = []

def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('einheitenpaare/' + id_, clause, file, search, replace, run, expect))

Z = 'testSetUnitBaseHoldsEachClauseOfZ44'
A2 = 'testWhatConfirmsABaseIsMadeOnlyFromWhatHappened'
A3 = 'testAUnitGoesBackOnlyToTheStateItsJournalNames'
B5 = 'testAPartAStepDidNotWriteIsKeptApartFromOneItWroteAndChecked'
PUSH = 'testAUnitMadeOnThisSiteTakesABaseWithOriginPushOnlyWithItsFilesWrittenAndChecked'
F = 'testAUnitThatDiffersIsConfirmedPartByPartWithTheModeChosenForEachPartThatDiffers'
F55 = 'testTwoSitesAlikePairTheirUnitsThroughTheProposalAndAnUpdateOnStagingIsThenAChangeOnStaging'
WAITS = 'testAnActivePairOfAUnitIsProposedAgainWhereADecisionWaitsOrCanChange'
OPEN = 'testAPartOfAPairWithoutABaseIsOpenAndTheOtherPartsAreJudgedByTheirOwn'
FILES = 'testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit'
END = 'testAnEndOfTheProcessWhileTheProposalOfUnitsIsStoredLeavesItToTheNextCall'
UNCLEAR = 'testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart'
# ------------------------------------------------ what confirms a base (A2)
e('proof-passed', 'A2 of_confirmation(): only where the token passed its check against this state in this request', PA,
  " || ! AB_MCP_Confirm_Token::passed( 'wp_site_pairs', $args, hash( 'sha256', AB_MCP_Sync_Canon::encode( $state ) ) ) ) {", " ) {", 'it', A2)
e('proof-step-done', 'A2 of_step(): only from a step done', PA,
  " || 'done' !== $step['state'] || ! is_array( $step['result'] ) ) {", " || ! is_array( $step['result'] ) ) {", 'it', A2)
e('proof-step-unit', 'A2 of_step(): only from a result that names a unit with its parts and hashes', PA,
  "\t\tif ( ! is_array( $unit ) || ! is_string( $unit['kind'] ?? null ) || ! is_string( $unit['ref'] ?? null ) || ! is_array( $unit['written'] ?? null ) || ! is_array( $unit['live_after_write'] ?? null ) || ! is_array( $unit['checked'] ?? null ) ) {", "\t\tif ( false ) {", 'it', A2)
e('proof-covers-source', 'A2 covers(): the confirmation of one source is none for another', PA,
  " && ( $op['source'] ?? null ) === $source_id && ", " && ", 'it', A2)
e('proof-covers-op', 'A2 covers(): the operation as the person confirmed it', PA,
  " && in_array( AB_MCP_Sync_Canon::encode( $op ), $this->fields['ops'], true );", ";", 'it', Z)
e('holds-source', 'A2 step_holds(): the step of a run of this source', PA,
  " || $f['source'] !== $source_id || ", " || ", 'it', A2)
e('holds-kind', 'A2 step_holds(): a unit of this kind', PA,
  " || $f['kind'] !== $kind || ", " || ", 'it', A2)
e('holds-ref', 'A2 step_holds(): a unit of this name', PA,
  " || $f['ref'] !== $ref || ", " || ", 'it', A2)
e('holds-hash', 'A2 step_holds(): the part as read after the write, by its hash', PA,
  " || ( $f['checked'][ $part ] ?? null ) !== $hash ) {", " ) {", 'it', PUSH)
e('holds-written', 'B5 step_holds(): written only for a part the journal names written', PA,
  "return 'written' === $mode ? $written && ! $after :", "return 'written' === $mode ? ! $after :", 'it', B5)
e('holds-written-only', 'B5 step_holds(): written never for a part also left as after the write', PA,
  "return 'written' === $mode ? $written && ! $after :", "return 'written' === $mode ? $written :", 'it', B5)
e('holds-after', 'B5 step_holds(): live_after_write only for a part the journal names left', PA,
  "( 'live_after_write' === $mode && $after && ! $written )", "( 'live_after_write' === $mode && ! $written )", 'it', B5)
e('holds-after-only', 'B5 step_holds(): live_after_write never for a part also written', PA,
  "( 'live_after_write' === $mode && $after && ! $written )", "( 'live_after_write' === $mode && $after )", 'it', B5)
e('tok-record', 'A2 check(): a check that passed is recorded', CT,
  "\t\tself::$passed[ self::passed_key( $tool, $args, $state ) ] = true;\n", '', 'it', A2)
e('tok-record-after-mac', 'A2 check(): recorded only after the MAC matched', CT,
  "\t\tif ( null === $mac || ! hash_equals( $mac, $m[2] ) ) {", "\t\tself::$passed[ self::passed_key( $tool, $args, $state ) ] = true;\n\t\tif ( null === $mac || ! hash_equals( $mac, $m[2] ) ) {", 'guard', 'testWhatConfirmsABaseCannotBeMadeOtherwiseThanFromWhatHappened')
e('tok-key-tool', 'A2 passed_key(): the tool', CT,
  "\t\t\t\t\t(string) $tool,\n\t\t\t\t\t(string) get_current_user_id(),", "\t\t\t\t\t(string) get_current_user_id(),", 'it', A2)
e('tok-key-user', 'A2 passed_key(): the account', CT,
  "\t\t\t\t\t(string) $tool,\n\t\t\t\t\t(string) get_current_user_id(),", "\t\t\t\t\t(string) $tool,", 'it', A2)
e('tok-key-scope', 'A2 passed_key(): the access level', CT,
  "\t\t\t\t\tclass_exists( 'AB_MCP_Auth' ) ? AB_MCP_Auth::current_scope() : 'full',\n\t\t\t\t\t(string) self::canonical(", "\t\t\t\t\t(string) self::canonical(", 'it', A2)
e('tok-key-args', 'A2 passed_key(): the arguments', CT,
  "\t\t\t\t\t(string) self::canonical( self::without_token( $args ) ),\n", '', 'it', A2)
e('tok-key-state', 'A2 passed_key(): the state', CT,
  "\t\t\t\t\thash( 'sha256', (string) $state ),\n\t\t\t\t)\n\t\t\t)\n\t\t);", "\t\t\t\t)\n\t\t\t)\n\t\t);", 'it', A2)
# ------------------------------------------------ Z44: set_unit_base(), the one place
e('z44-kind', 'Z44 set_unit_base(): a kind of units only', PA,
  "\t\tif ( ! self::is_unit( $kind ) ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-ref-valid', 'Z44 set_unit_base(): a name a pair holds (MAX_REF)', PA,
  "\t\tif ( ! self::valid_ref( $ref ) || 1 === preg_match(", "\t\tif ( 1 === preg_match(", 'it', Z)
e('z44-ref-held', 'Z44 set_unit_base(): no name held by its SHA-256', PA,
  "if ( ! self::valid_ref( $ref ) || 1 === preg_match( '/^sha256:[0-9a-f]{64}\\z/', (string) $ref ) ) {", "if ( ! self::valid_ref( $ref ) ) {", 'it', Z)
e('z44-proof', 'Z44 set_unit_base(): nothing without what confirms it', PA,
  "\t\tif ( ! $proof instanceof AB_MCP_Sync_Unit_Proof ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-parts-some', 'A1 set_unit_base(): one part or more', PA,
  "if ( ! is_array( $parts ) || array() === $parts || array() !== array_diff( array_keys( $parts ), $all ) ) {", "if ( ! is_array( $parts ) || array() !== array_diff( array_keys( $parts ), $all ) ) {", 'it', Z)
e('z44-parts-of-kind', 'A1 set_unit_base(): parts of the kind only', PA,
  "if ( ! is_array( $parts ) || array() === $parts || array() !== array_diff( array_keys( $parts ), $all ) ) {", "if ( ! is_array( $parts ) || array() === $parts ) {", 'it', Z)
e('z44-mode-origin', 'Z44 set_unit_base(): a mode of the origin of what confirms it', PA,
  "\t\t\tif ( ! is_string( $mode ) || ( self::UNIT_MODES[ $mode ] ?? null ) !== $origin ) {", "\t\t\tif ( ! is_string( $mode ) || ! isset( self::UNIT_MODES[ $mode ] ) ) {", 'it', Z)
e('z44-covers', 'Z44 set_unit_base(): the confirmation holds the operation as it is, for this source', PA,
  "\t\tif ( 'proposal' === $origin && ! $proof->covers( $source_id, $op ) ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-push-new', 'Z44 set_unit_base(): push only for a unit without a pair', PA,
  "( null !== $pair_id || 'written' !== ( $parts[ $all[0] ]['mode'] ?? null ) )", "( 'written' !== ( $parts[ $all[0] ]['mode'] ?? null ) )", 'it', Z)
e('z44-push-files', 'Z44 set_unit_base(): push only with the files written and checked', PA,
  "( null !== $pair_id || 'written' !== ( $parts[ $all[0] ]['mode'] ?? null ) )", "( null !== $pair_id )", 'it', B5)
e('z44-side-line', 'Z44 set_unit_base(): a line of each side, no base made up for a missing side', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( false ) {", 'it', 'testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging')
e('z44-side-kind', 'Z44 set_unit_base(): the line of the unit of this kind', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( ! is_array( $line ) || $ref !== ( $line['ref'] ?? null ) ) {", 'it', Z)
e('z44-side-name', 'Z44 set_unit_base(): the same name on both sides (renamed is another unit)', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) ) {", 'it', Z)
e('z44-whole', 'Z44 set_unit_base(): every part named read whole on both sides', PA,
  "\t\t\tif ( null === $s || null === $l ) {\n\t\t\t\t/* translators: %s: the part (files, activation). */\n\t\t\t\treturn self::unit_refused( 'whole',", "\t\t\tif ( false ) {\n\t\t\t\t/* translators: %s: the part (files, activation). */\n\t\t\t\treturn self::unit_refused( 'whole',", 'it', Z)
e('z44-part-held', 'Z44 unit_part(): no part of a line whose name is held', PA,
  "\t\tif ( isset( $line['held'] ) || ! empty( $line['own'] ) || null !== ( $line['lock'] ?? null ) ) {", "\t\tif ( ! empty( $line['own'] ) || null !== ( $line['lock'] ?? null ) ) {", 'it', Z)
e('z44-part-own', 'Z44 unit_part(): no part of AlphaBridge itself', PA,
  "\t\tif ( isset( $line['held'] ) || ! empty( $line['own'] ) || null !== ( $line['lock'] ?? null ) ) {", "\t\tif ( isset( $line['held'] ) || null !== ( $line['lock'] ?? null ) ) {", 'it', Z)
e('z44-part-lock', 'Z44 unit_part(): no part of a locked line (Z2)', PA,
  "\t\tif ( isset( $line['held'] ) || ! empty( $line['own'] ) || null !== ( $line['lock'] ?? null ) ) {", "\t\tif ( isset( $line['held'] ) || ! empty( $line['own'] ) ) {", 'it', Z)
e('z44-part-complete', 'Z44 unit_part(): the files only from a manifest read whole', PA,
  "\t\t\tif ( true !== ( $line['complete'] ?? null ) || ! in_array( $line['form'] ?? null, array( 'folder', 'file' ), true ) ) {", "\t\t\tif ( ! in_array( $line['form'] ?? null, array( 'folder', 'file' ), true ) ) {", 'it', Z)
e('z44-part-form', 'Z44 unit_part(): the files only of a folder or a file', PA,
  "\t\t\tif ( true !== ( $line['complete'] ?? null ) || ! in_array( $line['form'] ?? null, array( 'folder', 'file' ), true ) ) {", "\t\t\tif ( true !== ( $line['complete'] ?? null ) ) {", 'it', Z)
e('z44-part-mains', 'Z44 unit_part(): which main files run only from the main files read', PA,
  "( ! is_array( $line['mains'] ?? null ) || ! is_array( $line['active'] ?? null ) )", "( ! is_array( $line['active'] ?? null ) )", 'it', Z)
e('z44-part-hash', 'Z44 unit_part(): a part only by its hash (the theme that runs from both rows)', PA,
  "\t\treturn self::is_hash( $hash ) ? $hash : null;", "\t\treturn self::is_hash( $hash ) ? $hash : hash( 'sha256', '' );", 'it', Z)
e('z44-fit-equal', 'Z44 set_unit_base(): equal only for a part the same on both sides', PA,
  "\t\t\t\t'equal'            => $same,", "\t\t\t\t'equal'            => true,", 'it', Z)
e('z44-fit-b', 'Z44 set_unit_base(): live_base only for a part that differs (Frage F, b)', PA,
  "\t\t\t\t'live_base'        => ! $same,", "\t\t\t\t'live_base'        => true,", 'it', Z)
e('z44-fit-c', 'Z44 set_unit_base(): keep_apart only for a part that differs (Frage F, c)', PA,
  "\t\t\t\t'keep_apart'       => ! $same,", "\t\t\t\t'keep_apart'       => true,", 'it', Z)
e('z44-fit-written', 'Z44 set_unit_base(): written only for a part this site has as staging has it (checked)', PA,
  "\t\t\t\t'written'          => $same,", "\t\t\t\t'written'          => true,", 'it', PUSH)
e('z44-fit-after', 'B5 set_unit_base(): live_after_write for the state of this site after the write, whatever it is', PA,
  "\t\t\t\t'live_after_write' => true,", "\t\t\t\t'live_after_write' => $same,", 'it', PUSH)
e('z44-step', 'Z44 set_unit_base(): push only as the journal of the step holds each part', PA,
  "\t\t\tif ( 'push' === $origin && ! $proof->step_holds( $source_id, $kind, $ref, $part, $mode, $l ) ) {", "\t\t\tif ( false ) {", 'it', B5)
e('z44-base-c', 'Z44 set_unit_base(): keep_apart keeps the state of each side as its own base', PA,
  "\t\t\t\t'staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'staging' => $l,", 'it', Z)
e('z44-base-b', 'Z44 set_unit_base(): live_base and push make the state of this site the base of both sides', PA,
  "\t\t\t\t'staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'staging' => $s,", 'it', Z)
e('z44-bound-live', 'Z44 set_unit_base(): the value of this side the confirmation names is the one of the lines', PA,
  "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] || ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", "if ( ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", 'it', Z)
e('z44-bound-staging', 'Z44 set_unit_base(): the value of staging the confirmation names is the one of the lines', PA,
  "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] || ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] ) {", 'it', Z)
e('z44-transaction', 'Z44 set_unit_base(): the base and its reading back in one transaction (V5)', PA,
  "\t\treturn self::transaction(\n\t\t\tstatic function () use ( $source_id,", "\t\treturn call_user_func(\n\t\t\tstatic function () use ( $source_id,", 'it', 'testAPairOfAUnitWhoseRowCannotBeReadBackAfterItsInsertIsTakenBack')
e('z44-nested', 'transaction(): within a transaction already, part of it (no second START TRANSACTION)', PA,
  "\t\tif ( self::$depth > 0 ) {\n\t\t\treturn call_user_func( $fn );", "\t\tif ( false ) {\n\t\t\treturn call_user_func( $fn );", 'it', 'testAFaultOfTheRowsOfThePairsWhileUnitsAreConfirmedKeepsNothing')
# ------------------------------------------------ write_unit_base(), unit_extra(): part by part (A1)
e('z44-new-state', 'Z44 write_unit_base(): a new pair active', PA,
  "\t\t\t\t\t'state'        => 'active',\n\t\t\t\t\t'override'     => null,", "\t\t\t\t\t'state'        => 'unclear',\n\t\t\t\t\t'override'     => null,", 'it', Z)
e('z44-new-origin', 'Z44 write_unit_base(): a new pair of the origin of what confirms it', PA,
  "\t\t\t\t\t'base_origin'  => $origin,", "\t\t\t\t\t'base_origin'  => 'clone',", 'it', Z)
e('z44-new-open', 'A1 write_unit_base(): the first part not named of a new pair without a base (OPEN)', PA,
  "\t\t\t\t\t'base_live'    => $bases[ $first ]['live'] ?? self::OPEN,", "\t\t\t\t\t'base_live'    => $bases[ $first ]['live'] ?? str_repeat( '0', 64 ),", 'it', Z)
e('z44-new-last-run', 'Z44 write_unit_base(): the run of a unit made with origin push', PA,
  "\t\t\t\t\t'last_run'     => 'push' === $origin ? ( $op['last_run'] ?? null ) : null,", "\t\t\t\t\t'last_run'     => null,", 'it', PUSH)
e('z44-identity', 'Z44 write_unit_base(): a new alignment of the pair of this very unit', PA,
  "\t\tif ( $source_id !== $raw['source_id'] || $kind !== $raw['kind'] || $ref !== $raw['live_ref'] || $ref !== $raw['staging_ref'] ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-pair-state', 'A1 write_unit_base(): an active or unclear pair or a rest is aligned anew, not one deleted', PA,
  "\t\tif ( ! in_array( $raw['state'], array( 'active', 'unclear', 'residual' ), true ) ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-keep-active', 'A1 write_unit_base(): an active pair keeps every part not named', PA,
  "\t\t$keep = 'active' === $raw['state'];", "\t\t$keep = false;", 'it', Z)
e('z44-keep-unclear', 'A1 write_unit_base(): an unclear pair or a rest keeps none of them as confirmed', PA,
  "\t\t$keep = 'active' === $raw['state'];", "\t\t$keep = true;", 'it', Z)
e('z44-realign-active', 'Z44 write_unit_base(): aligned anew, the pair is active', PA,
  "\t\t$set  = array(\n\t\t\t'state'       => 'active',\n", "\t\t$set  = array(\n", 'it', Z)
e('z44-realign-canon', 'Z44 write_unit_base(): aligned anew, in the version of the comparison data of now', PA,
  "\t\t\t'state'       => 'active',\n\t\t\t'canon'       => AB_MCP_Sync_Canon::VERSION,\n", "\t\t\t'state'       => 'active',\n", 'it', Z)
e('z44-realign-origin', 'Z44 write_unit_base(): aligned anew by a person (origin proposal)', PA,
  "\t\t\t'base_origin' => 'proposal',", "\t\t\t'base_origin' => 'push',", 'it', Z)
e('z44-first-named', 'A1 write_unit_base(): the first part named in the columns', PA,
  "\t\tif ( isset( $bases[ $first ] ) ) {\n\t\t\t$set['base_live']    = $bases[ $first ]['live'];", "\t\tif ( false ) {\n\t\t\t$set['base_live']    = $bases[ $first ]['live'];", 'it', Z)
e('z44-first-open', 'A1 write_unit_base(): the first part not named of an unclear pair or a rest open', PA,
  "\t\t} elseif ( ! $keep ) {\n\t\t\t$set['base_live']    = self::OPEN;", "\t\t} elseif ( false ) {\n\t\t\t$set['base_live']    = self::OPEN;", 'it', Z)
e('extra-parts-keep', 'A1 unit_extra(): the further parts not named as they were, of an active pair only', PA,
  "\t\t$parts = $keep && is_array( $extra['parts'] ?? null ) ? $extra['parts'] : array();", "\t\t$parts = is_array( $extra['parts'] ?? null ) ? $extra['parts'] : array();", 'it', Z)
e('extra-parts-kept', 'A1 unit_extra(): an active pair keeps the further parts not named', PA,
  "\t\t$parts = $keep && is_array( $extra['parts'] ?? null ) ? $extra['parts'] : array();", "\t\t$parts = array();", 'it', Z)
e('extra-modes-keep', 'A1 unit_extra(): the modes of the parts not named as they were, of an active pair only', PA,
  "\t\t$known = $keep && is_array( $extra['modes'] ?? null ) ? $extra['modes'] : array();", "\t\t$known = is_array( $extra['modes'] ?? null ) ? $extra['modes'] : array();", 'it', Z)
e('extra-modes-kept', 'A1 unit_extra(): an active pair keeps the modes of the parts not named', PA,
  "\t\t$known = $keep && is_array( $extra['modes'] ?? null ) ? $extra['modes'] : array();", "\t\t$known = array();", 'it', Z)
e('extra-further', 'Z44 unit_extra(): the further parts named with their bases', PA,
  "\t\t\tif ( isset( $bases[ $part ] ) ) {\n\t\t\t\t$parts[ $part ] = $bases[ $part ];", "\t\t\tif ( false ) {\n\t\t\t\t$parts[ $part ] = $bases[ $part ];", 'it', Z)
e('extra-modes', 'B5 unit_extra(): the mode of each part named kept with its base', PA,
  "\t\t\t$known[ $part ] = $mode;\n", '', 'it', Z)
e('open-no-base', 'A1 part_bases(): a part OPEN has no base', PA,
  " || self::OPEN === $base['live'] || self::OPEN === $base['staging'] ) {", " ) {", 'it', Z)
# ------------------------------------------------ Z44: no way past it
e('past-create', 'Z44 create(): a unit only through set_unit_base()', PA,
  "\t\tif ( self::is_unit( $pair['kind'] ) ) {\n\t\t\treturn self::unit_only( 'create' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'create' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
e('past-realign', 'Z44 realign(): a unit only through set_unit_base()', PA,
  "\t\tif ( self::is_unit( $raw['kind'] ) ) {\n\t\t\treturn self::unit_only( 'realign' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'realign' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
e('past-canon', 'Z44 change_canon(): the bases of a unit are no canonical form', PA,
  "\t\tif ( self::is_unit( $raw['kind'] ) ) {\n\t\t\treturn self::unit_only( 'change_canon' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'change_canon' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
e('past-rebase-base', 'A4 rebase(): a part with a base only, never a first base', PA,
  "\t\tif ( null === self::part_bases( self::decode( $raw ), (string) $part ) ) {", "\t\tif ( false ) {", 'man', 'testRebaseGivesNoFirstBaseToAPartOfAUnit')
e('past-rebase-mode', 'B5 rebase(): the part a step wrote and checked, so named', PA,
  "\t\t$extra['modes'][ $part ] = 'written';\n", '', 'man', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('past-restore', 'A3 restore(): a unit only to the state its journal names', PA,
  "\t\tif ( self::is_unit( $raw['kind'] ) && ! self::step_before( (int) $id, (string) $raw['source_id'], (string) $before, $step ) ) {", "\t\tif ( false ) {", 'it', A3)
e('past-restore-source', 'A3 step_before(): a step of a run of the source of the pair', PA,
  " && $source === (string) $run['source_id'] && ", " && ", 'it', A3)
e('past-restore-pair', 'A3 step_before(): a step of this pair', PA,
  " && $id === $read['pair_id'] && ", " && ", 'it', A3)
e('past-restore-before', 'A3 step_before(): the very state the journal names as the one before', PA,
  " && is_string( $read['pair_before'] ) && hash_equals( $read['pair_before'], $before );", " && is_string( $read['pair_before'] );", 'it', A3)
e('past-guard-call', 'Z44 guard: set_unit_base() called by the confirmation only', PT,
  "\t\t\t\t\t\t$pair = AB_MCP_Sync_Pairs::set_unit_base( $plan['source'], $op, $proof );", "\t\t\t\t\t\t$pair = AB_MCP_Sync_Pairs::set_unit_base( $plan['source'], $op, $proof );\n\t\t\t\t\t\tAB_MCP_Sync_Pairs::set_unit_base( $plan['source'], $op, $proof );", 'guard', 'testSetUnitBaseIsCalledByTheConfirmationOnly')
# ------------------------------------------------ the confirmation of units, V1
e('v1-checked', 'V1 call(): the plan the gate checked the token against, not one made anew', PT,
  "\t\t\t\t$plan = self::checked( $a );", "\t\t\t\t$plan = self::plan( $a );", 'prop', 'testTheConfirmationWritesThePlanItsTokenWasCheckedAgainst')
e('v1-unchecked', 'V1 call(): nothing written without a plan checked in the same call', PT,
  "\t\t\t\tif ( null === $plan ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_pairs_unchecked',", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_pairs_unchecked',", 'prop', 'testAConfirmationNotCheckedInTheSameCallWritesNothing')
e('v1-args', 'V1 checked(): the plan of these very arguments', PT,
  "return is_array( $checked ) && hash_equals( $checked['args'], self::args_print( $a ) ) ? $checked['plan'] : null;", "return is_array( $checked ) ? $checked['plan'] : null;", 'prop', 'testAConfirmationNotCheckedInTheSameCallWritesNothing')
e('v1-once', 'V1 checked(): taken once', PT,
  "\t\t$checked       = self::$checked;\n\t\tself::$checked = null;", "\t\t$checked       = self::$checked;", 'prop', 'testAConfirmationNotCheckedInTheSameCallWritesNothing')
e('confirm-proof', 'A2 confirm(): what confirms the units, from the check of the token of this call', PT,
  "\t\t$proof = AB_MCP_Sync_Unit_Proof::of_confirmation( $a, $plan['state'] );", "\t\t$proof = null;", 'it', F55)
e('confirm-args', 'A2 call(): the confirmation with the arguments the token was checked for', PT,
  "return 'confirm' === $action ? self::confirm( $plan, $a ) : self::discard( $plan );", "return 'confirm' === $action ? self::confirm( $plan, array() ) : self::discard( $plan );", 'it', F55)
e('units-select', 'A1 plan_confirm(): select on a unit with no part it confirms is refused, with the way to units', PT,
  "\t\t\tif ( self::is_unit( $entry ) && ! isset( $units[ $n ] ) && array() === self::selected( $entry ) ) {", "\t\t\tif ( false ) {", 'it', F)
e('units-category', 'A1 plan_confirm(): by its category, no unit whose parts differ', PT,
  "\t\t\t\tif ( self::is_unit( $entry ) && ! isset( $units[ $entry['n'] ] ) && ( array() !== self::differing( $entry ) || array() === self::selected( $entry ) ) ) {", "\t\t\t\tif ( false ) {", 'it', F)
e('units-category-differs', 'A1 plan_confirm(): by its category, not the parts that are the same of a unit whose parts differ', PT,
  "( array() !== self::differing( $entry ) || array() === self::selected( $entry ) )", "( array() === self::selected( $entry ) )", 'it', F)
e('units-entry', 'plan_confirm(): units only for a unit that can be paired', PT,
  "\t\t\tif ( ! self::is_unit( $entry ) || ! in_array( $entry['category'], array( 'equal', 'differs', 'realign' ), true ) || null === $entry['confirm'] ) {", "\t\t\tif ( ! self::is_unit( $entry ) ) {", 'it', 'testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging')
e('units-part-differs', 'A1 plan_confirm(): a mode only for a part that differs now', PT,
  "\t\t\t\tif ( 'differs' !== ( $entry['parts'][ $part ] ?? null ) ) {", "\t\t\t\tif ( false ) {", 'it', F)
e('units-modes', 'modes(): live_base or keep_apart only, each part of an entry once', PT,
  "|| ! in_array( $one['mode'], array( 'live_base', 'keep_apart' ), true ) ) {", ") {", 'it', F)
e('units-once', 'modes(): each part of an entry once', PT,
  "\t\t\tif ( isset( $out[ $n ][ $one['part'] ] ) ) {\n\t\t\t\treturn null;", "\t\t\tif ( false ) {\n\t\t\t\treturn null;", 'it', F)
e('units-picked-select', 'A1 plan_confirm(): an entry chosen by select confirms its parts that are the same', PT,
  "\t\t\t$chosen[ $n ] = $entry;\n\t\t\t$picked[ $n ] = true;", "\t\t\t$chosen[ $n ] = $entry;", 'it', F)
e('units-picked-category', 'A1 plan_confirm(): an entry chosen by its category confirms its parts that are the same', PT,
  "\t\t\t\t$picked[ $entry['n'] ] = true;\n", '', 'it', F55)
e('units-merge', 'A1 plan_confirm(): the parts named in units and the parts select confirms, in one operation', PT,
  "self::unit_op( $source, $entry, ( $units[ $n ] ?? array() ) + ( isset( $picked[ $n ] ) ? array_fill_keys( self::selected( $entry ), 'equal' ) : array() ) );", "self::unit_op( $source, $entry, $units[ $n ] ?? array() );", 'it', F55)
e('selected-equal', 'A1 selected(): select confirms a part that is the same only', PT,
  "\t\t\tif ( 'equal' !== ( $entry['parts'][ $part ] ?? null ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'it', F)
e('selected-active', 'A1 selected(): of an active pair only a part whose decision waits, the others as they are', PT,
  "\t\t\tif ( $active && ! in_array( $entry['states'][ $part ] ?? null, array( 'open', 'changed_alike' ), true ) ) {", "\t\t\tif ( false ) {", 'it', F)
e('selected-open', 'A1 selected(): of an active pair a part open', PT,
  "array( 'open', 'changed_alike' ), true ) ) {", "array( 'changed_alike' ), true ) ) {", 'it', F)
e('selected-alike', 'A1 selected(): of an active pair a part changed alike', PT,
  "array( 'open', 'changed_alike' ), true ) ) {", "array( 'open' ), true ) ) {", 'it', F)
e('selected-of-active', 'A1 selected(): the parts of an unclear pair or a rest all anew', PT,
  "\t\t$active = 'realign' === $entry['category'] && 'active' === ( $entry['pair']['state'] ?? null );", "\t\t$active = 'realign' === $entry['category'];", 'it', UNCLEAR)
e('units-op-skip', 'A1 unit_op(): only the parts chosen', PT,
  "\t\t\tif ( ! isset( $modes[ $part ] ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'it', F)
e('units-op-c', 'unit_op(): keep_apart names the state of staging as its base', PT,
  "\t\t\t\t'base_staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'base_staging' => $l,", 'it', F)
e('units-op-source', 'A2 unit_op(): the operation names its source', PT,
  "\t\t\t'source'   => (string) $source,\n", '', 'it', F55)
e('named-others', 'A1 named(): the preview names the parts not chosen', PT,
  "\t\t\tif ( array() !== $others ) {", "\t\t\tif ( false ) {", 'it', F)
e('named-from', 'A1 named(): unchanged for an active pair, open otherwise', PT,
  "$op[ 'active' === $op['from'] ? 'unchanged' : 'open' ] = $others;", "$op['open'] = $others;", 'it', F)
e('units-now', 'now(): a unit paired meanwhile is skipped', PT,
  "\t\tif ( 'create' === $op['op'] || ( 'unit' === $op['op'] && null === $op['pair_id'] ) ) {", "\t\tif ( 'create' === $op['op'] ) {", 'it', 'testTheTokenBindsTheUnitItsPartsTheirModesAndValues')
e('units-effect-b', 'unit_effect(): the answer says what live_base means (Frage F, b)', PT,
  "\t\tif ( in_array( 'live_base', $modes, true ) ) {", "\t\tif ( false ) {", 'it', F)
e('units-effect-c', 'unit_effect(): the answer says what keep_apart means (Frage F, c)', PT,
  "\t\tif ( in_array( 'keep_apart', $modes, true ) ) {", "\t\tif ( false ) {", 'it', F)
# ------------------------------------------------ the proposal of units
e('prop-objects', 'V4 finish(): the pairs of units past the match of the objects and the canon change', PR,
  "\t\t$pairs = self::objects( $all );\n\t\t$canon = self::canon(", "\t\t$pairs = $all;\n\t\t$canon = self::canon(", 'it', UNCLEAR)
e('prop-objects-filter', 'V4 objects(): no pair of a unit', PR,
  "return array_values( array_filter( $pairs, static fn( $pair ) => ! AB_MCP_Sync_Pairs::is_unit( $pair['kind'] ) ) );", "return array_values( $pairs );", 'it', UNCLEAR)
e('prop-env', 'propose(): environment one list of packages of its own (a list, each once, at most 50, none of the inventory)', PR,
  "\t\tif ( ! is_array( $environment ) || ! array_is_list( $environment ) || count( $environment ) > self::MAX_ENVIRONMENT || count( array_unique( array_map( 'strval', $environment ) ) ) !== count( $environment ) || array() !== array_intersect( array_map( 'strval', $environment ), array_map( 'strval', $packages ) ) ) {", "\t\tif ( ! is_array( $environment ) ) {", 'it', 'testTheEnvironmentOfAProposalIsOneSeriesOfItsOwn')
e('prop-env-read', 'step(): the environment of this site read to its end before the proposal of units', PR,
  "\t\t\tif ( null !== $env['lines'] ) {\n\t\t\t\treturn self::finish( $found, $env['lines'] );", "\t\t\tif ( true ) {\n\t\t\t\treturn self::finish( $found, (array) $env['lines'] );", 'it', FILES)
e('prop-put', 'stored(): a write that says nothing of what it stored stored nothing', PR,
  "\t\treturn is_wp_error( $put ) ? $put : self::unwritten();\n\t}", "\t\treturn is_wp_error( $put ) ? $put : true;\n\t}", 'it', FILES)
e('prop-stored-again', 'B6 stored(): a file of its name there already, read against its SHA-256, is this file: the call goes on', PR,
  "\t\tif ( is_wp_error( $put ) && 'ab_mcp_store_exists' === $put->get_error_code() && is_string( self::calls()->get( 'reports', $gid, $name, $sha ) ) ) {", "\t\tif ( false ) {", 'it', END)
e('prop-stored-read', 'B6 stored(): a file of its name that does not read back against its SHA-256 is refused', PR,
  " && 'ab_mcp_store_exists' === $put->get_error_code() && is_string( self::calls()->get( 'reports', $gid, $name, $sha ) ) ) {", " && 'ab_mcp_store_exists' === $put->get_error_code() ) {", 'it', FILES)
e('prop-index-stored', 'B6 read_on(): a slice of the identity index stored once, met again by the next call', PR,
  "\t\t\t\t$put   = self::stored( $found['gid'], self::name( 'i', $seq + 1, $sha ), $sha, $slice );", "\t\t\t\t$put   = self::calls()->put( 'reports', $found['gid'], self::name( 'i', $seq + 1, $sha ), $slice );\n\t\t\t\t$put   = is_array( $put ) ? true : ( is_wp_error( $put ) ? $put : new WP_Error( 'ab_mcp_sync_pairs_unwritten', 'unwritten' ) );", 'it', END)
e('prop-head-throw', 'B6 propose(): a group whose head a Throwable kept from being written is removed', PR,
  "\t\t\t// Behebung 1, B6).\n\t\t\tself::calls()->remove( 'reports', $gid );\n", "\t\t\t// Behebung 1, B6).\n", 'it', FILES)
e('up-held', 'unpaired(): a name held is not paired', UP,
  "\t\tif ( isset( $said['held'] ) ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'held',", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'held',", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-ref', 'why_not(): a name no pair holds is not paired', UP,
  "\t\tif ( ! AB_MCP_Sync_Pairs::valid_ref( $ref ) || 1 === preg_match( AB_MCP_Sync_Env::HASHED, $ref ) ) {", "\t\tif ( false ) {", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-own', 'why_not(): AlphaBridge itself is never paired', UP,
  "\t\tif ( ! empty( $s['own'] ) || ! empty( $l['own'] ) ) {", "\t\tif ( false ) {", 'it', F55)
e('up-lock', 'why_not(): a unit locked by what it holds (Z2) is not paired', UP,
  "\t\tif ( array() !== $locks ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'not_compared',\n\t\t\t\t'reason'   => self::LOCKED,", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'not_compared',\n\t\t\t\t'reason'   => self::LOCKED,", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-whole', 'unpaired(): equal or differs only where both sides are read whole', UP,
  "\t\tif ( ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l ) {", "\t\tif ( null === $s || null === $l ) {", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-one-side', 'unpaired(): a unit on one side only is only there, never paired', UP,
  "\t\t\t$entry['category'] = 'only_staging' === $said['result'] ? 'staging_only' : 'live_only';", "\t\t\t$entry['category'] = 'equal';", 'it', 'testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging')
e('up-modes', 'compared(): a unit whose parts differ waits for the modes of a person', UP,
  "\t\t\t'confirm' => array() === $differs ? 'active' : 'modes',", "\t\t\t'confirm' => 'active',", 'it', F)
e('up-parts', 'compared(): each part equal or differs', UP,
  "\t\t\t$parts[ $part ] = in_array( $part, $differs, true ) ? 'differs' : 'equal';", "\t\t\t$parts[ $part ] = 'equal';", 'it', F)
e('up-paths-note', 'C8 compared(): the way to every path beside the first of each list', UP,
  "\t\t\t$paths['note'] = self::ALL_PATHS;\n", '', 'it', F)
e('up-waits', 'A1 entries(): an active pair whose decision waits or can change is proposed again', UP,
  "\t\t\t\t\tif ( self::waits( $states, $pair ) ) {", "\t\t\t\t\tif ( false ) {", 'env', WAITS)
e('up-waits-pending', 'A1 waits(): a part whose decision waits', UP,
  "\t\t\tif ( in_array( $state, self::PENDING, true ) || ( 'changed_on_staging' === $state", "\t\t\tif ( ( 'changed_on_staging' === $state", 'env', WAITS)
e('up-waits-b', 'A1 waits(): a part changed on staging by the base live_base set', UP,
  " || ( 'changed_on_staging' === $state && is_array( $modes ) && 'live_base' === ( $modes[ $part ] ?? null ) ) ) {", " ) {", 'env', WAITS)
e('up-waits-b-mode', 'A1 waits(): changed on staging only by live_base, not by a change after another decision', UP,
  " && is_array( $modes ) && 'live_base' === ( $modes[ $part ] ?? null ) ) ) {", " ) ) {", 'env', WAITS)
for state in ('open', 'kept_apart', 'conflict', 'changed_live', 'changed_alike'):
    rest = [x for x in ('open', 'kept_apart', 'conflict', 'changed_live', 'changed_alike') if x != state]
    e('up-pending-' + state.replace('_', '-'), 'A1 PENDING: a part %s waits for a decision' % state, UP,
      "\tconst PENDING = array( 'open', 'kept_apart', 'conflict', 'changed_live', 'changed_alike' );", "\tconst PENDING = array( %s );" % ', '.join("'%s'" % x for x in rest), 'env', WAITS)
e('up-active-paired', 'entries(): any other unit with an active pair is not proposed again', UP,
  "\t\t\t\t\t} else {\n\t\t\t\t\t\t++$paired;\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t\tcontinue;", "\t\t\t\t\t} else {\n\t\t\t\t\t\t++$paired;\n\t\t\t\t\t}\n\t\t\t\t}", 'it', F55)
e('up-realign-ready', 'realign(): a pair is aligned anew only where both sides are read whole', UP,
  "\t\tif ( null !== $why || ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l || (string) $pair['live_ref'] !== $ref || (string) $pair['staging_ref'] !== $ref ) {", "\t\tif ( null !== $why ) {", 'it', UNCLEAR)
e('up-gone', 'entries(): a pair of a unit on neither side is named, not aligned', UP,
  "\t\t\tif ( in_array( $pair['kind'], self::KINDS, true ) && in_array( $pair['state'], array( 'unclear', 'residual' ), true ) && ! isset( $seen[ (int) $pair['id'] ] ) ) {", "\t\t\tif ( false ) {", 'it', UNCLEAR)
# ------------------------------------------------ the report: a part without a base (A1)
e('dec-open', 'A1 unit_item(): a part of an active pair without a base is open, the others judged by their own', DE,
  "\t\t\t\tif ( null === $base ) {\n\t\t\t\t\t// A part without a base yet (Z44): open, until a person\n\t\t\t\t\t// confirms it; the other parts are judged by their own.\n\t\t\t\t\t$states[ $part ] = 'open';\n\t\t\t\t\tcontinue;\n\t\t\t\t}", "\t\t\t\tif ( null === $base ) {\n\t\t\t\t\treturn self::unit_entry( $entry, array(), 'Its pair holds no base of the part ' . $part . '.', $held );\n\t\t\t\t}", 'env', OPEN)
e('dec-open-rank', 'A1 unit_item(): an open part outranks one kept apart', DE,
  "'changed_alike', 'open', 'kept_apart', 'equal' ) as $result ) {", "'changed_alike', 'kept_apart', 'open', 'equal' ) as $result ) {", 'env', OPEN)

# ------------------------------------------------ check and write
src = {f: open(os.path.join(ROOT, f)).read() for f in VAR}
errors = []
for (id_, clause, file, search, replace, run, expect) in entries:
    n = src[file].count(search)
    if 1 != n:
        errors.append('%s: search occurs %d times in %s' % (id_, n, file))
    kind, cls = CLASSES[run]
    path = os.path.join(ROOT, 'tests', 'integration' if 'it' == kind else '', cls + '.php').replace('/./', '/')
    if not re.search(r'function ' + re.escape(expect) + r'\(', open(path).read()):
        errors.append('%s: %s is no test of %s' % (id_, expect, path))
ids = [x[0] for x in entries]
if len(ids) != len(set(ids)):
    errors.append('ids twice')
if errors:
    print('\n'.join(errors))
    sys.exit(1)

def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'" if '\n' not in s and '\t' not in s else '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('\n', '\\n').replace('\t', '\\t') + '"'

out = ['<?php', '/**', ' * The clauses of the pairs of units (Z8b branch 2b: plan 3.1 Z44 at its one',
       ' * place, AB_MCP_Sync_Pairs::set_unit_base(), what confirms a base, the ways',
       ' * past it that refuse a unit, the confirmation of units part by part with V1',
       ' * of the pre-check, the proposal of units with V4, the open part in the report',
       ' * and the files of a proposal stored once; Behebung 1: A1 to A4, B5, B6, C8).',
       ' * Written by tests/mutation/gen/einheitenpaare.py from the',
       ' * source; change the entries there and run it again.', ' *',
       ' * Held by the integration suite (SyncUnitPairsTest, SyncProposalTest,',
       ' * SyncManifestTest; the sites running, AB_IT_ROOT set) and by the unit suite',
       ' * (SyncUnitBaseGuardTest, SyncEnvTest).', ' *',
       ' * Not listed, with why (bericht-zweig-2b.md), equivalent: in unit_part() the',
       ' * check of active_plugins read (part_hash() gives no hash without a list',
       ' * active), in set_unit_base() is_array() of a line alone (a value no array',
       ' * has neither env nor ref, the clauses after it refuse it) and is_string() of',
       ' * a mode (a mode no string is no key of UNIT_MODES), the guard of null lines',
       ' * in unpaired() (compare() gives equal or differs only with both lines), the',
       ' * environment of a report reused (a parameter for the refusal of a proposal);',
       " * in AB_MCP_Sync_Unit_Proof the origin in covers() and step_holds() (each is",
       ' * asked only for a proof of its origin), the ops of the state in',
       ' * of_confirmation() (state_of_ops() always names them), the run in of_step()',
       ' * and step_before() beside its step (a step of no run is no step either), the',
       ' * kind of mode in step_holds() (set_unit_base() takes only written and',
       ' * live_after_write for a push); in plan_confirm() selected() empty beside',
       ' * differing() for a category (no case reaches an entry of a unit whose parts',
       ' * are all the same and of which select confirms none); in waits() is_array()',
       ' * of the modes (base_extra holds them as set_unit_base() and rebase() wrote',
       ' * them).', ' *',
       ' * @package AlphaBridge_MCP', ' */', '',
       "$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );",
       "$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );",
       '']
for f, v in VAR.items():
    out.append("%s = '%s';" % (v, f))
out.append('')
out.append('return array(')
for (id_, clause, file, search, replace, run, expect) in entries:
    kind, cls = CLASSES[run]
    out.append('\tarray(')
    out.append("\t\t'id'      => %s," % php(id_))
    out.append("\t\t'clause'  => %s," % php(clause))
    out.append("\t\t'file'    => %s," % VAR[file])
    out.append("\t\t'search'  => %s," % php(search))
    out.append("\t\t'replace' => %s," % php(replace))
    out.append("\t\t'run'     => %s( '%s', '%s' )," % ('$unit' if 'unit' == kind else '$it', cls, expect))
    out.append("\t\t'expect'  => '/%s/'," % expect)
    out.append('\t),')
out.append(');')
open(os.path.join(ROOT, 'tests/mutation/lists/einheitenpaare.php'), 'w').write('\n'.join(out) + '\n')
print('%d entries' % len(entries))
