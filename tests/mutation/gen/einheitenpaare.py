#!/usr/bin/env python3
"""Writes tests/mutation/lists/einheitenpaare.php (Z8b branch 2b): one entry
per clause of Z44 at its one place (AB_MCP_Sync_Pairs::set_unit_base()), of
the ways past it that refuse a unit, of the confirmation of units and of V1
(one plan per call), of the proposal of units and of V4. Every search is
checked to occur exactly once in its file and the test of its expect to be a
method of the class its run names.

    python3 tests/mutation/gen/einheitenpaare.py
"""
import os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
PA = 'includes/sync/class-sync-pairs.php'
PT = 'includes/sync/class-sync-pairs-tool.php'
PR = 'includes/sync/class-sync-proposal.php'
UP = 'includes/sync/class-sync-unit-proposal.php'
VAR = {PA: '$pa', PT: '$pt', PR: '$pr', UP: '$up'}
CLASSES = {'guard': ('unit', 'SyncUnitBaseGuardTest'), 'it': ('it', 'SyncUnitPairsTest'), 'prop': ('it', 'SyncProposalTest')}
entries = []

def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('einheitenpaare/' + id_, clause, file, search, replace, run, expect))

Z = 'testSetUnitBaseHoldsEachClauseOfZ44'
# ------------------------------------------------ Z44: set_unit_base(), the one place
e('z44-kind', 'Z44 set_unit_base(): a kind of units only', PA,
  "\t\tif ( ! self::is_unit( $kind ) ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-ref-valid', 'Z44 set_unit_base(): a name a pair holds (MAX_REF)', PA,
  "\t\tif ( ! self::valid_ref( $ref ) || 1 === preg_match(", "\t\tif ( 1 === preg_match(", 'it', Z)
e('z44-ref-held', 'Z44 set_unit_base(): no name held by its SHA-256', PA,
  "if ( ! self::valid_ref( $ref ) || 1 === preg_match( '/^sha256:[0-9a-f]{64}\\z/', (string) $ref ) ) {", "if ( ! self::valid_ref( $ref ) ) {", 'it', Z)
e('z44-proof', 'Z44 set_unit_base(): nothing without what confirms it', PA,
  "\t\tif ( ! $proof instanceof AB_MCP_Sync_Unit_Proof ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-parts-count', 'Z44 set_unit_base(): every part of the kind, none besides', PA,
  "if ( ! is_array( $parts ) || count( $parts ) !== count( $want ) || array() !== array_diff( $want, array_keys( $parts ) ) ) {", "if ( ! is_array( $parts ) || array() !== array_diff( $want, array_keys( $parts ) ) ) {", 'it', Z)
e('z44-parts-each', 'Z44 set_unit_base(): each part of the kind named', PA,
  "if ( ! is_array( $parts ) || count( $parts ) !== count( $want ) || array() !== array_diff( $want, array_keys( $parts ) ) ) {", "if ( ! is_array( $parts ) || count( $parts ) !== count( $want ) ) {", 'it', Z)
e('z44-mode-origin', 'Z44 set_unit_base(): a mode of the origin of what confirms it', PA,
  "\t\t\tif ( ! is_string( $mode ) || ( self::UNIT_MODES[ $mode ] ?? null ) !== $origin ) {", "\t\t\tif ( ! is_string( $mode ) ) {", 'it', Z)
e('z44-covers', 'Z44 set_unit_base(): the confirmation holds the operation as it is (identity, parts, modes, values)', PA,
  "\t\tif ( 'proposal' === $origin && ! $proof->covers( $op ) ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-push-new', 'Z44 set_unit_base(): push only for a unit without a pair', PA,
  "( null !== $pair_id || ! in_array( $want[0], $proof->written(), true ) || array() !== array_diff( $proof->written(), $want ) )", "( ! in_array( $want[0], $proof->written(), true ) || array() !== array_diff( $proof->written(), $want ) )", 'it', Z)
e('z44-push-files', 'Z44 set_unit_base(): push only with the files written and checked', PA,
  "( null !== $pair_id || ! in_array( $want[0], $proof->written(), true ) || array() !== array_diff( $proof->written(), $want ) )", "( null !== $pair_id || array() !== array_diff( $proof->written(), $want ) )", 'it', 'testAUnitMadeOnThisSiteTakesABaseWithOriginPushOnlyWithItsFilesWrittenAndChecked')
e('z44-push-parts', 'Z44 set_unit_base(): push names parts of the unit only', PA,
  "( null !== $pair_id || ! in_array( $want[0], $proof->written(), true ) || array() !== array_diff( $proof->written(), $want ) )", "( null !== $pair_id || ! in_array( $want[0], $proof->written(), true ) )", 'it', 'testAUnitMadeOnThisSiteTakesABaseWithOriginPushOnlyWithItsFilesWrittenAndChecked')
e('z44-side-line', 'Z44 set_unit_base(): a line of each side, no base made up for a missing side', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( false ) {", 'it', 'testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging')
e('z44-side-kind', 'Z44 set_unit_base(): the line of the unit of this kind', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( ! is_array( $line ) || $ref !== ( $line['ref'] ?? null ) ) {", 'it', Z)
e('z44-side-name', 'Z44 set_unit_base(): the same name on both sides (renamed is another unit)', PA,
  "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) || $ref !== ( $line['ref'] ?? null ) ) {", "\t\t\tif ( ! is_array( $line ) || $kind !== ( $line['env'] ?? null ) ) {", 'it', Z)
e('z44-whole', 'Z44 set_unit_base(): every part read whole on both sides', PA,
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
  "\t\t\t\t'equal'      => $same,", "\t\t\t\t'equal'      => true,", 'it', Z)
e('z44-fit-b', 'Z44 set_unit_base(): live_base only for a part that differs (Frage F, b)', PA,
  "\t\t\t\t'live_base'  => ! $same,", "\t\t\t\t'live_base'  => true,", 'it', Z)
e('z44-fit-c', 'Z44 set_unit_base(): keep_apart only for a part that differs (Frage F, c)', PA,
  "\t\t\t\t'keep_apart' => ! $same,", "\t\t\t\t'keep_apart' => true,", 'it', Z)
e('z44-fit-push', 'Z44 set_unit_base(): push for a part written only as staging has it (checked)', PA,
  "\t\t\t\t'push'       => $same || ! $wrote,", "\t\t\t\t'push'       => true,", 'it', 'testAUnitMadeOnThisSiteTakesABaseWithOriginPushOnlyWithItsFilesWrittenAndChecked')
e('z44-base-c', 'Z44 set_unit_base(): keep_apart keeps the state of each side as its own base', PA,
  "\t\t\t\t'staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'staging' => $l,", 'it', Z)
e('z44-base-b', 'Z44 set_unit_base(): live_base and push make the state of this site the base of both sides', PA,
  "\t\t\t\t'staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'staging' => $s,", 'it', Z)
e('z44-bound-live', 'Z44 set_unit_base(): the value of this side the confirmation names is the one of the lines', PA,
  "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] || ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", "if ( ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", 'it', Z)
e('z44-bound-staging', 'Z44 set_unit_base(): the value of staging the confirmation names is the one of the lines', PA,
  "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] || ( $parts[ $part ]['base_staging'] ?? null ) !== $bases[ $part ]['staging'] ) {", "if ( ( $parts[ $part ]['base_live'] ?? null ) !== $bases[ $part ]['live'] ) {", 'it', Z)
e('z44-further', 'Z44 set_unit_base(): the further parts with their bases', PA,
  "\t\t\t$further[ $part ] = $bases[ $part ];", '', 'it', Z)
e('z44-transaction', 'Z44 set_unit_base(): the base and its reading back in one transaction (V5)', PA,
  "\t\treturn self::transaction(\n\t\t\tstatic function () use ( $source_id,", "\t\treturn call_user_func(\n\t\t\tstatic function () use ( $source_id,", 'it', 'testAPairOfAUnitWhoseRowCannotBeReadBackAfterItsInsertIsTakenBack')
e('z44-nested', 'transaction(): within a transaction already, part of it (no second START TRANSACTION)', PA,
  "\t\tif ( self::$depth > 0 ) {\n\t\t\treturn call_user_func( $fn );", "\t\tif ( false ) {\n\t\t\treturn call_user_func( $fn );", 'it', 'testAFaultOfTheRowsOfThePairsWhileUnitsAreConfirmedKeepsNothing')
e('z44-new-active', 'Z44 write_unit_base(): a new pair active, of the origin of what confirms it', PA,
  "\t\t\t\t\t'base_origin'  => $origin,", "\t\t\t\t\t'base_origin'  => 'clone',", 'it', Z)
e('z44-identity', 'Z44 write_unit_base(): a new alignment of the pair of this very unit', PA,
  "\t\tif ( $source_id !== $raw['source_id'] || $kind !== $raw['kind'] || $ref !== $raw['live_ref'] || $ref !== $raw['staging_ref'] ) {", "\t\tif ( false ) {", 'it', Z)
e('z44-pair-state', 'Z44 write_unit_base(): only an unclear pair or a rest is aligned anew', PA,
  "\t\tif ( ! in_array( $raw['state'], array( 'unclear', 'residual' ), true ) ) {\n\t\t\t/* translators: 1: pair number, 2: its state, as the tools name it (active, …). */", "\t\tif ( false ) {\n\t\t\t/* translators: 1: pair number, 2: its state, as the tools name it (active, …). */", 'it', Z)
e('z44-realign-active', 'Z44 write_unit_base(): aligned anew, the pair is active', PA,
  "\t\t\tarray(\n\t\t\t\t'state'        => 'active',\n\t\t\t\t'canon'        => AB_MCP_Sync_Canon::VERSION,", "\t\t\tarray(\n\t\t\t\t'canon'        => AB_MCP_Sync_Canon::VERSION,", 'it', Z)
e('z44-realign-parts', 'Z44 write_unit_base(): every further part anew, the other keys of base_extra kept', PA,
  "\t\tif ( array() !== $further ) {\n\t\t\t$extra['parts'] = $further;", "\t\tif ( false ) {\n\t\t\t$extra['parts'] = $further;", 'it', Z)
# ------------------------------------------------ Z44: no way past it
e('past-create', 'Z44 create(): a unit only through set_unit_base()', PA,
  "\t\tif ( self::is_unit( $pair['kind'] ) ) {\n\t\t\treturn self::unit_only( 'create' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'create' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
e('past-realign', 'Z44 realign(): a unit only through set_unit_base()', PA,
  "\t\tif ( self::is_unit( $raw['kind'] ) ) {\n\t\t\treturn self::unit_only( 'realign' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'realign' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
e('past-canon', 'Z44 change_canon(): the bases of a unit are no canonical form', PA,
  "\t\tif ( self::is_unit( $raw['kind'] ) ) {\n\t\t\treturn self::unit_only( 'change_canon' );", "\t\tif ( false ) {\n\t\t\treturn self::unit_only( 'change_canon' );", 'it', 'testNoWayPastSetUnitBaseMakesOrAlignsTheBaseOfAUnit')
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
e('units-select', 'plan_confirm(): a unit whose parts differ is not paired by select without its modes (Frage F, a)', PT,
  "\t\t\tif ( self::is_unit( $entry ) && ! isset( $units[ $n ] ) && array() !== self::differing( $entry ) ) {", "\t\t\tif ( false ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-category', 'plan_confirm(): nor by its category', PT,
  "\t\t\t\tif ( self::is_unit( $entry ) && ! isset( $units[ $entry['n'] ] ) && array() !== self::differing( $entry ) ) {", "\t\t\t\tif ( false ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-entry', 'plan_confirm(): units only for a unit whose parts differ', PT,
  "\t\t\tif ( ! self::is_unit( $entry ) || ! in_array( $entry['category'], array( 'differs', 'realign' ), true ) || null === $entry['confirm'] ) {", "\t\t\tif ( ! self::is_unit( $entry ) ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-each-part', 'plan_confirm(): a mode for each part that differs and for no other', PT,
  "\t\t\tif ( array_keys( $modes ) !== $names ) {", "\t\t\tif ( false ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-modes', 'modes(): live_base or keep_apart only, each part of an entry once', PT,
  "|| ! in_array( $one['mode'], array( 'live_base', 'keep_apart' ), true ) ) {", ") {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-once', 'modes(): each part of an entry once', PT,
  "\t\t\tif ( isset( $out[ $n ][ $one['part'] ] ) ) {\n\t\t\t\treturn null;", "\t\t\tif ( false ) {\n\t\t\t\treturn null;", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-op-c', 'unit_op(): keep_apart names the state of staging as its base', PT,
  "\t\t\t\t'base_staging' => 'keep_apart' === $mode ? $s : $l,", "\t\t\t\t'base_staging' => $l,", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-now', 'now(): a unit paired meanwhile is skipped', PT,
  "\t\tif ( 'create' === $op['op'] || ( 'unit' === $op['op'] && null === $op['pair_id'] ) ) {", "\t\tif ( 'create' === $op['op'] ) {", 'it', 'testTheTokenBindsTheUnitItsPartsTheirModesAndValues')
e('units-effect-b', 'unit_effect(): the answer says what live_base means (Frage F, b)', PT,
  "\t\tif ( in_array( 'live_base', $modes, true ) ) {", "\t\tif ( false ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('units-effect-c', 'unit_effect(): the answer says what keep_apart means (Frage F, c)', PT,
  "\t\tif ( in_array( 'keep_apart', $modes, true ) ) {", "\t\tif ( false ) {", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
# ------------------------------------------------ the proposal of units
e('prop-objects', 'V4 finish(): the pairs of units past the match of the objects and the canon change', PR,
  "\t\t$pairs = self::objects( $all );\n\t\t$canon = self::canon(", "\t\t$pairs = $all;\n\t\t$canon = self::canon(", 'it', 'testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart')
e('prop-objects-filter', 'V4 objects(): no pair of a unit', PR,
  "return array_values( array_filter( $pairs, static fn( $pair ) => ! AB_MCP_Sync_Pairs::is_unit( $pair['kind'] ) ) );", "return array_values( $pairs );", 'it', 'testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart')
e('prop-env', 'propose(): environment one list of packages of its own (a list, each once, at most 50, none of the inventory)', PR,
  "\t\tif ( ! is_array( $environment ) || ! array_is_list( $environment ) || count( $environment ) > self::MAX_ENVIRONMENT || count( array_unique( array_map( 'strval', $environment ) ) ) !== count( $environment ) || array() !== array_intersect( array_map( 'strval', $environment ), array_map( 'strval', $packages ) ) ) {", "\t\tif ( ! is_array( $environment ) ) {", 'it', 'testTheEnvironmentOfAProposalIsOneSeriesOfItsOwn')
e('prop-env-read', 'step(): the environment of this site read to its end before the proposal of units', PR,
  "\t\t\tif ( null !== $env['lines'] ) {\n\t\t\t\treturn self::finish( $found, $env['lines'] );", "\t\t\tif ( true ) {\n\t\t\t\treturn self::finish( $found, (array) $env['lines'] );", 'it', 'testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit')
e('prop-put', 'put(): a write that says nothing of what it stored stored nothing', PR,
  "\t\t$put  = self::calls()->put( 'reports', $gid, $name, $text );\n\t\tif ( ! is_array( $put ) ) {", "\t\t$put  = self::calls()->put( 'reports', $gid, $name, $text );\n\t\tif ( is_wp_error( $put ) ) {", 'it', 'testAFaultOfTheFilesOfAProposalOrOfTheReadingOfTheEnvironmentPairsNoUnit')
e('up-held', 'unpaired(): a name held is not paired', UP,
  "\t\tif ( isset( $said['held'] ) ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'held',", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'held',", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-ref', 'why_not(): a name no pair holds is not paired', UP,
  "\t\tif ( ! AB_MCP_Sync_Pairs::valid_ref( $ref ) || 1 === preg_match( AB_MCP_Sync_Env::HASHED, $ref ) ) {", "\t\tif ( false ) {", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-own', 'why_not(): AlphaBridge itself is never paired', UP,
  "\t\tif ( ! empty( $s['own'] ) || ! empty( $l['own'] ) ) {", "\t\tif ( false ) {", 'it', 'testTwoSitesAlikePairTheirUnitsThroughTheProposalAndAnUpdateOnStagingIsThenAChangeOnStaging')
e('up-lock', 'why_not(): a unit locked by what it holds (Z2) is not paired', UP,
  "\t\tif ( array() !== $locks ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'not_compared',\n\t\t\t\t'reason'   => self::LOCKED,", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'category' => 'not_compared',\n\t\t\t\t'reason'   => self::LOCKED,", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-whole', 'unpaired(): equal or differs only where both sides are read whole', UP,
  "\t\tif ( ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l ) {", "\t\tif ( null === $s || null === $l ) {", 'it', 'testAUnitNotReadWholeLockedHeldOrNamedTooLongIsNotPairedButNamedWithWhy')
e('up-one-side', 'unpaired(): a unit on one side only is only there, never paired', UP,
  "\t\t\t$entry['category'] = 'only_staging' === $said['result'] ? 'staging_only' : 'live_only';", "\t\t\t$entry['category'] = 'equal';", 'it', 'testAUnitOnOneSideOnlyGetsNoPairAndOnlyLiveStaysApartFromARemovalOnStaging')
e('up-modes', 'compared(): a unit whose parts differ waits for the modes of a person', UP,
  "\t\t\t'confirm' => array() === $differs ? 'active' : 'modes',", "\t\t\t'confirm' => 'active',", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('up-parts', 'compared(): each part equal or differs', UP,
  "\t\t\t$parts[ $part ] = in_array( $part, $differs, true ) ? 'differs' : 'equal';", "\t\t\t$parts[ $part ] = 'equal';", 'it', 'testAUnitThatDiffersAlreadyIsPairedOnlyWithTheModeChosenForEachPartThatDiffers')
e('up-active-paired', 'entries(): a unit with an active pair is not proposed again', UP,
  "\t\t\t\t} elseif ( 'active' === $pair['state'] ) {\n\t\t\t\t\t++$paired;\n\t\t\t\t}\n\t\t\t\tcontinue;", "\t\t\t\t} elseif ( 'active' === $pair['state'] ) {\n\t\t\t\t\t++$paired;\n\t\t\t\t}", 'it', 'testTwoSitesAlikePairTheirUnitsThroughTheProposalAndAnUpdateOnStagingIsThenAChangeOnStaging')
e('up-realign-ready', 'realign(): a pair is aligned anew only where both sides are read whole', UP,
  "\t\tif ( null !== $why || ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l || (string) $pair['live_ref'] !== $ref || (string) $pair['staging_ref'] !== $ref ) {", "\t\tif ( null !== $why ) {", 'it', 'testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart')
e('up-gone', 'entries(): a pair of a unit on neither side is named, not aligned', UP,
  "\t\t\tif ( in_array( $pair['kind'], self::KINDS, true ) && in_array( $pair['state'], array( 'unclear', 'residual' ), true ) && ! isset( $seen[ (int) $pair['id'] ] ) ) {", "\t\t\tif ( false ) {", 'it', 'testAnUnclearPairOfAUnitIsAlignedAnewThroughTheProposalPartByPart')

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
       ' * place, AB_MCP_Sync_Pairs::set_unit_base(), the ways past it that refuse a',
       ' * unit, the confirmation of units with V1 of the pre-check, the proposal of',
       ' * units with V4). Written by tests/mutation/gen/einheitenpaare.py from the',
       ' * source; change the entries there and run it again.', ' *',
       ' * Held by the integration suite (SyncUnitPairsTest, SyncProposalTest; the',
       ' * sites running, AB_IT_ROOT set) and by the unit suite (SyncUnitBaseGuardTest).', ' *',
       ' * Not listed, with why (bericht-zweig-2b.md), equivalent: in unit_part() the',
       ' * check of active_plugins read (part_hash() gives no hash without a list',
       ' * active), in unit_op() the mode equal of a part that is the same (units',
       ' * names modes only for the parts that differ, units-each-part), in',
       ' * set_unit_base() is_array() of a line alone (a value no array has neither',
       ' * env nor ref, the clauses after it refuse it); and unset() of the parts in a new',
       ' * alignment (they are set anew after it; for a kind of one part the stale key',
       ' * changes nothing a report reads), the guard of null lines in unpaired()',
       ' * (compare() gives equal or differs only with both lines), and the',
       ' * environment of a report reused (a parameter for the refusal of a proposal).', ' *',
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
