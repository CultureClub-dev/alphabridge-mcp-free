#!/usr/bin/env python3
"""Writes tests/mutation/lists/bericht.php: one entry per clause of the object
pairs of the takeover (Z8 branch 6, part 1: wp_site_pairs). Every search is
checked to occur exactly once in its file, and the test of its expect to be a
method of the class its run names, before the list is written; each entry
runs that test only.

    python3 tests/mutation/gen/bericht.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
P = 'includes/sync/class-sync-proposal.php'
T = 'includes/sync/class-sync-pairs-tool.php'
IM = 'includes/sync/class-sync-import.php'
OS = 'includes/sync/class-sync-option-schema.php'
TS = 'includes/tools/class-tools-staging.php'
G = 'includes/class-staging-gate.php'
FILEVAR = {P: '$p', T: '$t', IM: '$im', OS: '$os', TS: '$ts', G: '$g'}

RUNS = {
    'prop': ('unit', 'SyncProposalTest'),
    'tool': ('unit', 'SyncPairsToolTest'),
    'gate': ('unit', 'StagingGateTest'),
    'it': ('it', 'SyncProposalTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('bericht/' + id_, clause, file, search, replace, run, expect))


def off(search):
    """The condition of an if taken out: if ( false ) {."""
    return re.sub(r'^(\s*)(\}\s*else)?if \(.*\) \{', lambda m: m.group(1) + (m.group(2) or '') + 'if ( false ) {', search, count=1)


# --------------------------------------------------------------- propose
PK = "if ( ! is_array( $packages ) || ! array_is_list( $packages ) || array() === $packages || count( $packages ) > self::MAX_PACKAGES || count( array_unique( array_map( 'strval', $packages ) ) ) !== count( $packages ) ) {"
TOOL_IDS = 'testAProposalIdThatIsNoneAndDiscardWithoutPairsAreRefused'
e('propose-packages-list', 'propose(): packages is a list', P, PK, PK.replace('! array_is_list( $packages ) || ', ''), 'tool', TOOL_IDS)
e('propose-packages-empty', 'propose(): at least one package', P, PK, PK.replace('array() === $packages || ', ''), 'tool', TOOL_IDS)
e('propose-packages-max', 'propose(): at most MAX_PACKAGES', P, PK, PK.replace('count( $packages ) > self::MAX_PACKAGES || ', ''), 'tool', TOOL_IDS)
e('propose-packages-once', 'propose(): each package once', P, PK, PK.replace(" || count( array_unique( array_map( 'strval', $packages ) ) ) !== count( $packages )", ''), 'tool', TOOL_IDS)

# ------------------------------------------------------------------ find
e('find-id', 'find(): a proposal id is 32 hex', P,
  "\t\tif ( ! is_string( $proposal_id ) || 1 !== preg_match( '/^[0-9a-f]{32}\\z/', $proposal_id ) ) {",
  "\t\tif ( ! is_string( $proposal_id ) ) {", 'tool', TOOL_IDS)
e('find-label', 'find(): a group of the label of proposals only', P,
  "if ( $proposal_id === $entry['gid'] && self::LABEL === $entry['label'] ) {",
  "if ( $proposal_id === $entry['gid'] ) {", 'it', 'testAProposalOfAnotherCanonSourceOrFormatIsNone')
e('find-format', 'find(): a head of this format', P,
  "if ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) ) {",
  "if ( ! is_array( $head ) ) {", 'it', 'testAProposalOfAnotherCanonSourceOrFormatIsNone')
e('find-connection', 'find(): the connection that made it only (and so its account)', P,
  "\t\tif ( ! hash_equals( (string) $head['connection'], self::connection() ) ) {",
  "\t\tif ( false ) {", 'it', 'testAProposalOfAnotherCanonSourceOrFormatIsNone')
e('find-connection-mcp', 'find(): a proposal of this process is not one over MCP', P,
  "\t\tif ( ! hash_equals( (string) $head['connection'], self::connection() ) ) {",
  "\t\tif ( false ) {", 'it', 'testAProposalGoesOnOverCallsAndBelongsToItsAccountAndConnection')
e('find-account', 'find(): another account, another connection', P,
  "\t\tif ( ! hash_equals( (string) $head['connection'], self::connection() ) ) {",
  "\t\tif ( false ) {", 'it', 'testAProposalOfAnotherAccountIsNotItsOwn')
ST = "if ( AB_MCP_Sync_Canon::VERSION !== $head['canon'] || $source !== $head['source_id'] || ! hash_equals( (string) $head['print'], (string) AB_MCP_Sync_Anchor::pair_print() ) ) {"
e('find-canon', 'find(): the version of the canonical form of now', P, ST, ST.replace("AB_MCP_Sync_Canon::VERSION !== $head['canon'] || ", ''), 'it', 'testAProposalOfAnotherCanonSourceOrFormatIsNone')
e('find-source', 'find(): the source of the confirmed key', P, ST, ST.replace("$source !== $head['source_id'] || ", ''), 'it', 'testAProposalOfAnotherCanonSourceOrFormatIsNone')
e('find-print', 'find(): the print of the pair of sites of now', P, ST, ST.replace(" || ! hash_equals( (string) $head['print'], (string) AB_MCP_Sync_Anchor::pair_print() )", ''), 'it', 'testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired')
EX = "\t\tif ( self::now() >= (int) $head['expires'] ) {"
e('find-expires', 'find(): not after it expired', P, EX, off(EX), 'it', 'testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired')
e('find-expires-at', 'find(): at its expiry it holds no more', P, EX, EX.replace('>=', '>'), 'it', 'testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired')
e('replace-own', 'replace_own(): a new proposal removes the earlier ones of its connection only', P,
  "\t\t\tif ( is_array( $head ) && isset( $head['connection'] ) && hash_equals( (string) $head['connection'], $mine ) ) {", "\t\t\tif ( false ) {", 'it', 'testAProposalGoesOnOverCallsAndBelongsToItsAccountAndConnection')
e('replace-own-only', 'replace_own(): those of other connections stay', P,
  "\t\t\tif ( is_array( $head ) && isset( $head['connection'] ) && hash_equals( (string) $head['connection'], $mine ) ) {", "\t\t\tif ( is_array( $head ) ) {", 'it', 'testAProposalGoesOnOverCallsAndBelongsToItsAccountAndConnection')
e('find-expires-min', 'propose(): it holds as long as its earliest package', P,
  "\t\t\t'expires'    => min( $staging['expires'], self::now() + AB_MCP_Protected_Store::KEEP['reports'] ),",
  "\t\t\t'expires'    => self::now() + AB_MCP_Protected_Store::KEEP['reports'],", 'it', 'testAProposalHoldsAsLongAsItsEarliestPackage')
e('source-multisite', 'source_id(): no pairs on a network', P,
  "\t\tif ( is_multisite() ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_multisite'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_multisite'", 'tool', 'testEveryActionNeedsAConfirmedKeyAndNoNetwork')
e('source-anchor', 'source_id(): only with a confirmed key of a staging site', P,
  "\t\tif ( null === $anchor ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_pairs_no_anchor'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_pairs_no_anchor'", 'tool', 'testEveryActionNeedsAConfirmedKeyAndNoNetwork')

# --------------------------------------------------------------- compose
e('compose-twice', 'compose(): an object in two packages is refused', P,
  "\t\t\t\tif ( isset( $seen[ $kind . \"\\0\" . $sha ] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testAnObjectInTwoPackagesIsRefused')
e('compose-whole-elsewhere', 'compose(): objects of a kind whole in another package are refused', P,
  "\t\t\t\tif ( isset( $db[ $kind ], $whole[ $db[ $kind ] ] ) && $whole[ $db[ $kind ] ] !== $i ) {", "\t\t\t\tif ( false ) {", 'prop', 'testAKindWholeInOnePackageWithObjectsInAnotherIsRefused')
e('compose-whole-complete', 'compose(): only a kind a package read whole is whole', P,
  "\t\t\t\tif ( ! $entry['complete'] || ! isset( $db[ $entry['kind'] ] ) ) {", "\t\t\t\tif ( ! isset( $db[ $entry['kind'] ] ) ) {", 'prop', 'testAKindReadAcrossPackagesIsCompleteInNone')
e('compose-held', 'compose(): a line of the list of held objects is held', P,
  "\t\t\t\tif ( isset( $codes[ $kind . \"\\0\" . $sha ] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testTheListOfHeldObjectsMarksTheirLines')
e('compose-expires', 'compose(): the earliest package', P,
  "\t\t\t$expires = min( $expires, (int) $one['manifest']['expires'] );", "\t\t\t$expires = (int) $one['manifest']['expires'];", 'prop', 'testPackagesOfOneSeriesAreOneInventory')
e('complete-whole', 'complete(): not without a package that read its kind whole', P,
  "\t\tif ( ! isset( $db[ $kind ], $staging['whole'][ $db[ $kind ] ] ) ) {", "\t\tif ( false ) {", 'prop', 'testPackagesOfOneSeriesAreOneInventory')
e('complete-withheld', 'complete(): a subtype that package withheld objects of is not', P,
  "\t\treturn isset( $staging['complete'][ $key ] ) ? $staging['complete'][ $key ] : true;", "\t\treturn true;", 'prop', 'testASubtypeWithheldInThePackageThatReadItsKindIsNotComplete')
e('complete-none', 'complete(): a subtype it names nowhere had no object', P,
  "\t\treturn isset( $staging['complete'][ $key ] ) ? $staging['complete'][ $key ] : true;", "\t\treturn isset( $staging['complete'][ $key ] ) ? $staging['complete'][ $key ] : false;", 'prop', 'testPackagesOfOneSeriesAreOneInventory')

# ------------------------------------------------------------------ step
e('step-b9', 'step(): an object whose name is a credential is counted, never listed', P,
  "\t\t\tif ( 'b9' === $object['status'] ) {", "\t\t\tif ( false ) {", 'it', 'testANameHoldingACredentialIsShownByTheHashOfItsRefOnly')
e('step-held', 'step(): a line of this site a credential holds is held', P,
  "\t\t\tif ( null !== $code ) {\n\t\t\t\t$line['held'] = $code;", "\t\t\tif ( false ) {\n\t\t\t\t$line['held'] = $code;", 'it', 'testANameHoldingACredentialIsShownByTheHashOfItsRefOnly')
e('step-finish', 'step(): the inventory read to its end is worked out in the same call', P,
  "\t\tif ( null === $read['cursor'] ) {\n\t\t\treturn self::finish( $found );", "\t\tif ( false ) {\n\t\t\treturn self::finish( $found );", 'it', 'testAProposalGoesOnOverCallsAndBelongsToItsAccountAndConnection')
e('step-read', 'step(): an inventory read to its end is not read again', P,
  "\t\t\tif ( null === $cursor ) {\n\t\t\t\treturn self::finish( $found );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::finish( $found );", 'it', 'testAProposalWhoseWorkingOutFailedIsWorkedOutByTheNextCall')
e('parts-twice', 'parts(): two parts of one number void it', P,
  "\t\t\tif ( isset( $closes[ $parsed['seq'] ] ) ) {", "\t\t\tif ( false ) {", 'it', 'testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt')
e('parts-gap', 'parts(): a gap voids it', P,
  "\t\t\tif ( $seq !== $at ) {", "\t\t\tif ( false ) {", 'it', 'testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt')
e('parts-seq', 'parts(): a part names its own number', P,
  "|| $seq !== ( $close['seq'] ?? null ) ) {", ") {", 'it', 'testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt')
e('read-one-once', 'read_one(): one head only', P,
  "\t\tif ( count( $found ) > 1 ) {", "\t\tif ( false ) {", 'it', 'testAGroupWithTwoHeadsIsNone')

# ----------------------------------------------------------------- canon
e('canon-waiting', 'canon(): a pair whose object an inventory does not hold waits', P,
  "\t\t\tif ( null === $sl || null === $ll ) {", "\t\t\tif ( false ) {", 'it', 'testACanonChangeGivesEqualFormsNewBasesAndMakesTheOthersUnclear')
e('canon-run', 'finish(): the canon change runs', P,
  "\t\t$canon = self::canon( $pairs, $staging['lines'], $live['lines'] );",
  "\t\t$canon = array( 'rebased' => 0, 'unclear' => 0, 'waiting' => 0 );", 'it', 'testACanonChangeGivesEqualFormsNewBasesAndMakesTheOthersUnclear')
e('canon-reread', 'finish(): the pairs read again after a canon change', P,
  "\t\tif ( $canon['rebased'] + $canon['unclear'] > 0 ) {", "\t\tif ( false ) {", 'it', 'testACanonChangeGivesEqualFormsNewBasesAndMakesTheOthersUnclear')
CF = "\t\t\treturn is_array( $line ) && isset( $line['hash'] ) && is_string( $line['hash'] ) && ! empty( $line['complete'] ) && ! isset( $line['held'] ) ? $line['hash'] : null;"
CANON = 'testTheTargetOfACanonChangeIsTheStagingHashOnlyWhereNothingMapsIt'
e('canon-form-complete', 'canon_forms(): a form not read whole is none', P, CF, CF.replace(" && ! empty( $line['complete'] )", ''), 'prop', CANON)
e('canon-form-held', 'canon_forms(): a held line has no form', P, CF, CF.replace(" && ! isset( $line['held'] )", ''), 'prop', CANON)
e('canon-target-override', 'canon_forms(): an override changes the target', P,
  "\t\t\t'target'  => null === $pair['override'] && ! $mapped ? $s : null,", "\t\t\t'target'  => ! $mapped ? $s : null,", 'prop', CANON)
e('canon-target-mapped', 'canon_forms(): a post or term named by its id maps the form', P,
  "\t\t\t'target'  => null === $pair['override'] && ! $mapped ? $s : null,", "\t\t\t'target'  => null === $pair['override'] ? $s : null,", 'prop', CANON)
e('canon-target-term', 'canon_forms(): a term too', P,
  "in_array( $ref['space'], array( 'post', 'term' ), true )", "in_array( $ref['space'], array( 'post' ), true )", 'prop', CANON)
e('canon-target-possible', 'canon_forms(): a possible reference maps the form', P,
  "\t\t\t$mapped = $mapped || ! empty( $staging['possible'] );", "", 'prop', CANON)

# ----------------------------------------------------------------- match
e('match-taken', 'match(): objects of a pair are not proposed again', P,
  "\t\t\t\tif ( isset( $taken[ $side ][ $i ] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testObjectsOfAnActivePairAreNotProposedAgain')
e('match-realign', 'match(): only unclear and residual pairs are aligned anew', P,
  "\t\t\tif ( ! in_array( $pair['state'], array( 'unclear', 'residual' ), true ) ) {", "\t\t\tif ( false ) {", 'prop', 'testObjectsOfAnActivePairAreNotProposedAgain')
e('match-unread', 'match(): an object without identity is not compared', P,
  "\t\t\t\tif ( null === $item['key'] ) {", "\t\t\t\tif ( false ) {", 'prop', 'testARowNotReadIsNotComparedByItself')
VR = "\t\t\t\tif ( ! AB_MCP_Sync_Pairs::valid_ref( $item['ref'] ) && null === $item['held'] ) {"
e('match-ref', 'match(): a name no pair holds is not compared', P, VR, "\t\t\t\tif ( false ) {", 'prop', 'testANameNoPairHoldsIsNotCompared')
e('match-ref-held', 'match(): a held one stays held', P, VR, VR.replace(" && null === $item['held']", ''), 'prop', 'testANameNoPairHoldsIsNotCompared')
e('resolve-held', 'resolve(): a held object holds its group', P,
  "\t\t\tif ( null !== $item['held'] ) {\n\t\t\t\treturn array( self::held_entry( $s, $l ) );", "\t\t\tif ( false ) {\n\t\t\t\treturn array( self::held_entry( $s, $l ) );", 'prop', 'testAGroupWithAHeldObjectOnEitherSideIsHeldAsAWhole')
e('resolve-path', 'resolve(): one to one on a path is a pair', P,
  "\t\t\tif ( 1 === count( $ss ) && 1 === count( $ll ) ) {", "\t\t\tif ( false ) {", 'prop', 'testThePathOfParentsTellsPagesOfTheSameSlugApart')
HU = "\t\t\t\tif ( null !== $item['hash'] && 1 === ( $hl[ $item['hash'] ] ?? 0 ) && 1 === ( $hs[ $item['hash'] ] ?? 0 ) ) {"
e('resolve-hash', 'resolve(): an equal hash unique on both sides is a pair', P, HU, "\t\t\t\tif ( false ) {", 'prop', 'testAnEqualHashUniqueOnBothSidesIsAPair')
e('resolve-hash-live', 'resolve(): unique on this site', P, HU, HU.replace(" && 1 === ( $hl[ $item['hash'] ] ?? 0 )", ''), 'prop', 'testAnEqualHashTwiceOnASideIsNoPair')
e('resolve-hash-staging', 'resolve(): unique on the staging site', P, HU, HU.replace(" && 1 === ( $hs[ $item['hash'] ] ?? 0 )", ''), 'prop', 'testAnEqualHashTwiceOnASideIsNoPair')
e('resolve-left', 'resolve(): what is left one to one is a pair', P,
  "\t\tif ( 1 === count( $left['s'] ) && 1 === count( $left['l'] ) ) {", "\t\tif ( false ) {", 'prop', 'testWhatThePathLeavesOneToOneIsAPair')
e('resolve-ambiguous', 'resolve(): more left on both sides is ambiguous', P,
  "\t\t} elseif ( array() !== $left['s'] && array() !== $left['l'] ) {", "\t\t} elseif ( false ) {", 'prop', 'testMoreCandidatesOnASideAreAmbiguousWithAllOfThem')
e('live-alone', 'live_alone(): only live only where the staging side is complete', P,
  "\t\tif ( true === call_user_func( $complete, $item['kind'], $item['subtype'] ) ) {", "\t\tif ( true ) {", 'prop', 'testAnObjectOnlyOnThisSiteIsLiveOnlyOnlyWhereStagingIsComplete')
NH = "\t\tif ( null === $s['hash'] || null === $l['hash'] ) {"
e('pair-no-hash-staging', 'pair_entry(): no hash on the staging side, not compared', P, NH, NH.replace("null === $s['hash'] || ", ''), 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
e('pair-no-hash-live', 'pair_entry(): no hash on this site, not compared', P, NH, NH.replace(" || null === $l['hash']", ''), 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
EQ = "\t\t$equal = $s['complete'] && $l['complete'] && hash_equals( $s['hash'], $l['hash'] );"
e('equal-staging-whole', 'pair_entry(): equal only read whole on the staging site', P, EQ, EQ.replace("$s['complete'] && ", ''), 'prop', 'testAnObjectNotReadWholeIsNeverEqual')
e('equal-live-whole', 'pair_entry(): equal only read whole on this site', P, EQ, EQ.replace("$l['complete'] && ", ''), 'prop', 'testAnObjectNotReadWholeIsNeverEqual')
e('equal-hash', 'pair_entry(): equal only with the same hash', P, EQ, "\t\t$equal = $s['complete'] && $l['complete'];", 'prop', 'testTheSameIdentityWithOtherContentDiffersAndIsConfirmedUnclear')
RD = "\t\t$ready = null !== ( $s['hash'] ?? null ) && null !== ( $l['hash'] ?? null ) && null === $s['held'] && null === $l['held'];"
e('realign-staging-hash', 'realign_entry(): not without the staging object and its hash', P, RD, RD.replace("null !== ( $s['hash'] ?? null ) && ", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnew')
e('realign-live-hash', 'realign_entry(): not without the object of this site and its hash', P, RD, RD.replace("null !== ( $l['hash'] ?? null ) && ", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnew')
e('realign-staging-held', 'realign_entry(): not with a held staging object', P, RD, RD.replace(" && null === $s['held']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnew')
e('realign-live-held', 'realign_entry(): not with a held object of this site', P, RD, RD.replace(" && null === $l['held']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnew')
e('realign-note', 'realign_entry(): sides that differ now are named', P,
  "\t\tif ( ! ( $s['complete'] && $l['complete'] && hash_equals( $s['hash'], $l['hash'] ) ) ) {", "\t\tif ( false ) {", 'prop', 'testUnclearAndResidualPairsAreProposedForANewAlignment')
e('alone-why', 'alone(): an object alone without a hash says why', P,
  "( null === $item['hash'] && null !== $item['reason_code'] ? self::why_no_hash( $item ) : null )", "null", 'prop', 'testAnObjectAloneWithoutAHashSaysWhy')
e('why-code', 'why_no_hash(): the words of the reason code', P,
  "\t\tif ( null !== $text ) {\n\t\t\treturn $text;", "\t\tif ( false ) {\n\t\t\treturn $text;", 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
e('key-unread', 'identity_key(): none without an identity', P,
  "\t\tif ( array() === $identity ) {\n\t\t\treturn null;", "\t\tif ( false ) {\n\t\t\treturn null;", 'prop', 'testTheIdentityKeyOfEachKind')
e('key-menu-slug', 'identity_key(): a menu item by the slug of its menu', P,
  "isset( $menus[ (string) $menu ] ) ? AB_MCP_Sync_Canon::encode( $menus[ (string) $menu ] ) : '?'", "'?'", 'prop', 'testAMenuItemMatchesByTheSlugOfItsMenuNotItsId')
e('key-css-linked', 'identity_key(): the CSS of a stylesheet and a CSS post apart', P,
  "\t\t\t\t$parts = array( $identity['stylesheet'] ?? null, ! empty( $identity['linked'] ) );", "\t\t\t\t$parts = array( $identity['stylesheet'] ?? null );", 'prop', 'testTheIdentityKeyOfEachKind')
e('key-theme', 'identity_key(): the theme of a template', P,
  "$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null );", "$parts = array( $identity['post_type'] ?? null, $identity['post_name'] ?? null );", 'prop', 'testTheIdentityKeyOfEachKind')
e('parent-at', 'parent_of(): only the post_parent is a parent', P,
  "\t\t\tif ( 'post_parent' === ( $ref['at'] ?? null ) && 'post' === ( $ref['space'] ?? null ) ) {", "\t\t\tif ( 'post' === ( $ref['space'] ?? null ) ) {", 'prop', 'testOnlyThePostParentIsAParentNotAnotherReferenceToAPost')
e('shown-hash', 'shown(): no hash of a side', P,
  "\t\t\t\tunset( $entry[ $side ]['hash'] );", "", 'prop', 'testAnAnswerShowsNoHashOrSnapshot')
e('shown-candidates', 'shown(): no hash of a candidate', P,
  "\t\t\t\t\tunset( $entry['candidates'][ $side ][ $i ]['hash'] );", "", 'prop', 'testAnAnswerShowsNoHashOrSnapshot')
e('shown-snapshot', 'shown(): no snapshot of a pair', P,
  "\t\t\tunset( $entry['pair']['snapshot'] );", "", 'prop', 'testAnAnswerShowsNoHashOrSnapshot')
e('page-shown', 'page(): the entries as shown', P,
  "\t\t$entries = array_map( array( __CLASS__, 'shown' ), $page['entries'] );", "\t\t$entries = $page['entries'];", 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('parse-name', 'parse_name(): only the one spelling of name()', P,
  "\t\tif ( self::name( $prefix, $seq, $hex ) !== $name ) {", "\t\tif ( false ) {", 'prop', 'testTheNamesOfTheFilesOfAProposal')

# ------------------------------------------------------------------ tool
e('plan-action', 'plan(): confirm and discard only', T,
  "\t\tif ( ! in_array( $action, array( 'confirm', 'discard' ), true ) ) {\n\t\t\treturn self::no_action();", "\t\tif ( false ) {\n\t\t\treturn self::no_action();", 'tool', 'testWithoutAnActionNothingRuns')
e('plan-not-done', 'plan_confirm(): not a proposal still being worked out', T,
  "\t\tif ( null === $found['index'] ) {", "\t\tif ( false ) {", 'it', 'testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt')
SH = "\t\tif ( null === $select || ! is_array( $categories ) || ! array_is_list( $categories ) || array() !== array_diff( $categories, self::BY_CATEGORY ) || ! is_array( $choices ) || ! array_is_list( $choices ) ) {"
SEL = 'testAConfirmationTakesOnlyWhatTheProposalOffers'
e('sel-select', 'plan_confirm(): select is a list of entry numbers', T, SH, SH.replace('null === $select || ', ''), 'it', SEL)
e('sel-categories', 'plan_confirm(): categories of equal, differs and realign only', T, SH, SH.replace(' || array() !== array_diff( $categories, self::BY_CATEGORY )', ''), 'it', SEL)
e('sel-choices', 'plan_confirm(): choices is a list', T, SH, SH.replace(' || ! is_array( $choices ) || ! array_is_list( $choices )', ''), 'it', SEL)
e('sel-nothing', 'plan_confirm(): something is chosen', T,
  "\t\tif ( array() === $select && array() === $categories && array() === $choices ) {", "\t\tif ( false ) {", 'it', SEL)
e('sel-choice-shape', 'plan_confirm(): a choice names entry, staging_ref and live_ref', T,
  "\t\t\tif ( ! is_array( $choice ) || ! isset( $choice['entry'], $choice['staging_ref'], $choice['live_ref'] ) || null === self::numbers( array( $choice['entry'] ) ) ) {",
  "\t\t\tif ( ! is_array( $choice ) ) {", 'it', SEL)
e('sel-entry', 'plan_confirm(): an entry of the proposal', T,
  "\t\t\tif ( ! isset( $entries[ $n ] ) ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-ambiguous', 'plan_confirm(): an ambiguous entry needs a choice', T,
  "\t\t\tif ( 'ambiguous' === $entry['category'] ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-category', 'plan_confirm(): only equal, differs and realign are paired', T,
  "\t\t\tif ( ! in_array( $entry['category'], array( 'equal', 'differs', 'realign' ), true ) ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-realign-ready', 'plan_confirm(): a realign entry that cannot be aligned is refused', T,
  "\t\t\tif ( 'realign' === $entry['category'] && null === $entry['confirm'] ) {\n\t\t\t\treturn self::selection(", "\t\t\tif ( false ) {\n\t\t\t\treturn self::selection(", 'it', SEL)
e('sel-realign-skip', 'plan_confirm(): by category, such a realign entry is skipped', T,
  "\t\t\t\tif ( 'realign' === $entry['category'] && null === $entry['confirm'] ) {\n\t\t\t\t\t$skipped[]", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$skipped[]", 'it', SEL)
e('sel-room', 'plan_confirm(): categories only where select and choices leave room', T,
  "\t\t\tif ( $room < 1 ) {", "\t\t\tif ( false ) {", 'it', 'testAConfirmationMakesAtMost500PairsAndTheRestInAPartAfter')
e('sel-rest', 'plan_confirm(): what is left of the categories is named', T,
  "\t\t\tif ( $more['more'] && array() !== $more['entries'] ) {", "\t\t\tif ( false ) {", 'it', 'testAConfirmationMakesAtMost500PairsAndTheRestInAPartAfter')
e('sel-choice-ambiguous', 'plan_confirm(): a choice for an ambiguous entry only', T,
  "\t\t\tif ( 'ambiguous' !== $entry['category'] ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-choice-refs', 'plan_confirm(): the refs of a choice among its candidates', T,
  "\t\t\tif ( null === $s || null === $l ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-choice-hash', 'plan_confirm(): a candidate without a hash is not paired', T,
  "\t\t\tif ( ! is_string( $s['hash'] ) || ! is_string( $l['hash'] ) ) {", "\t\t\tif ( false ) {", 'it', SEL)
e('sel-max', 'plan_confirm(): at most MAX pairs a confirmation', T,
  "\t\tif ( count( $ops ) > self::MAX ) {", "\t\tif ( false ) {", 'it', 'testAConfirmationMakesAtMost500PairsAndTheRestInAPartAfter')
e('sel-twice', 'plan_confirm(): an object chosen twice is refused', T,
  "\t\t\t\tif ( isset( $used[ $key ] ) ) {", "\t\t\t\tif ( false ) {", 'it', SEL)
e('now-skip', 'plan_confirm(): an entry paired meanwhile is skipped', T,
  "\t\t\tif ( is_string( $now ) ) {", "\t\t\tif ( false ) {", 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('now-paired', 'now(): a ref of a pair is paired meanwhile', T,
  "\t\t\t\tif ( null !== $pair ) {\n\t\t\t\t\treturn sprintf( 'paired meanwhile", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn sprintf( 'paired meanwhile", 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('now-changed', 'now(): a pair changed since the proposal is skipped', T,
  "\t\treturn hash_equals( $op['expected'], AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) ) ? null : sprintf( 'pair %d changed since the proposal', $op['pair_id'] );", "\t\treturn null;", 'it', 'testAPairChangedSinceTheProposalIsSkippedNotOverwritten')
e('nothing-left', 'plan_confirm(): nothing left to confirm is refused', T,
  "\t\tif ( array() === $fresh ) {", "\t\tif ( false ) {", 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
DI = "\t\tif ( null === $ids || array() === $ids || count( $ids ) > self::MAX || count( array_unique( $ids ) ) !== count( $ids ) ) {"
e('discard-ids', 'plan_discard(): pair_ids is a list of numbers', T, DI, DI.replace('null === $ids || ', ''), 'tool', TOOL_IDS)
e('discard-empty', 'plan_discard(): at least one', T, DI, DI.replace('array() === $ids || ', ''), 'tool', TOOL_IDS)
e('discard-max', 'plan_discard(): at most MAX', T, DI, DI.replace('count( $ids ) > self::MAX || ', ''), 'tool', TOOL_IDS)
e('discard-once', 'plan_discard(): each once', T, DI, DI.replace(' || count( array_unique( $ids ) ) !== count( $ids )', ''), 'tool', TOOL_IDS)
e('discard-source', 'plan_discard(): a pair of this source only', T,
  "\t\t\tif ( null === $pair || $source !== $pair['source_id'] ) {", "\t\t\tif ( null === $pair ) {", 'it', 'testADiscardThatCannotEndAPairEndsNone')
e('confirm-rollback', 'undone(): what a failed confirmation wrote is taken back', T,
  "\t\tforeach ( array_reverse( $done ) as list( $what, $id, $before ) ) {", "\t\tforeach ( array() as list( $what, $id, $before ) ) {", 'it', 'testAConfirmationThatCannotWriteAPairKeepsNothing')
e('confirm-rollback-restore', 'undone(): a pair aligned anew is put back to its snapshot', T,
  "\t\t\t\t: AB_MCP_Sync_Pairs::restore( $id, $before, AB_MCP_Sync_Pairs::snapshot_hash( $now ) ) );", "\t\t\t\t: true );", 'it', 'testAConfirmationThatCannotWriteAPairKeepsNothing')
e('discard-rollback', 'discard(): pairs ended before a failure are made again', T,
  "\t\t\t\t\t$again  = AB_MCP_Sync_Pairs::create( $fields );", "\t\t\t\t\t$again  = true;", 'it', 'testADiscardThatCannotEndAPairEndsNone')
LF = "\t\t\tif ( ( null !== $kind && $kind !== $pair['kind'] ) || ( null !== $state && $state !== $pair['state'] ) ) {"
e('list-kind', 'listed(): only pairs of the kind asked', T, LF, LF.replace("( null !== $kind && $kind !== $pair['kind'] ) || ", ''), 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('list-state', 'listed(): only pairs of the state asked', T, LF, LF.replace(" || ( null !== $state && $state !== $pair['state'] )", ''), 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('list-state-valid', 'listed(): a state of a pair only', T,
  "\t\tif ( null !== $state && ! in_array( $state, AB_MCP_Sync_Pairs::STATES, true ) ) {", "\t\tif ( false ) {", 'tool', 'testListTakesOnlyAStateOfAPair')

# -------------------------------------------------------- registration
e('when-at-once', 'pairs_when(): propose and list run without the token', TS,
  "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'propose', 'list' ), true ) );", "\t\treturn true;", 'tool', 'testOnlyProposeAndListRunWithoutTheToken')
e('when-confirm', 'pairs_when(): confirm and discard need it', TS,
  "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'propose', 'list' ), true ) );", "\t\treturn ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'propose', 'list', 'confirm', 'discard' ), true ) );", 'tool', 'testOnlyProposeAndListRunWithoutTheToken')
e('def-when', 'register(): the confirmation asks when', TS,
  "\t\t\t\t\t'when'    => array( __CLASS__, 'pairs_when' ),\n", "", 'tool', 'testTheGateLetsProposeAndListRunAndAsksConfirmForItsState')
e('def-destructive', 'register(): destructive', TS,
  "\t\t\t\t'destructive' => true,\n\t\t\t\t'inputSchema' => array(\n\t\t\t\t\t'type'       => 'object',\n\t\t\t\t\t'properties' => array(\n\t\t\t\t\t\t'action'      => array(",
  "\t\t\t\t'destructive' => false,\n\t\t\t\t'inputSchema' => array(\n\t\t\t\t\t'type'       => 'object',\n\t\t\t\t\t'properties' => array(\n\t\t\t\t\t\t'action'      => array(", 'tool', 'testItIsRegisteredLikeTheOtherToolsOfTheTakeover')
e('gate-carry-over', 'CARRY_OVER: wp_site_pairs is not held by «Staging first»', G,
  "'wp_site_import_chunk', 'wp_site_pairs' );", "'wp_site_import_chunk' );", 'tool', 'testItIsRegisteredLikeTheOtherToolsOfTheTakeover')

# ---------------------------------------------------------------- import
e('taken-id', 'taken(): a package id is 32 hex', IM,
  "\t\tif ( ! is_string( $package_id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_package', 'A package is named",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_package', 'A package is named", 'tool', TOOL_IDS)
e('taken-unknown', 'taken(): a package this account took over only', IM,
  "\t\tif ( null === $found ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_unknown', sprintf(", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_unknown', sprintf(", 'it', 'testOnlyAPackageTakenOverCompletelyIsRead')
e('taken-bound', 'taken(): a package of the pair of sites as it is now', IM,
  "\t\tif ( ! self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_pair_changed', sprintf(", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_pair_changed', sprintf(", 'it', 'testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired')
e('taken-verify', 'taken(): its manifest checked again', IM,
  "\t\t$checked = AB_MCP_Sync_Manifest::verify( $found['signed'], $expect );\n\t\tif ( is_wp_error( $checked ) ) {\n\t\t\treturn $checked;\n\t\t}\n\t\t$manifest = $checked['manifest'];",
  "\t\t$checked = array( 'manifest' => $found['manifest'] );\n\t\t$manifest = $checked['manifest'];", 'it', 'testAPackageWhoseManifestExpiredIsNotRead')
e('taken-complete', 'taken(): a package marked complete only', IM,
  "\t\tif ( ! is_string( $mark ) ) {", "\t\tif ( false ) {", 'it', 'testOnlyAPackageTakenOverCompletelyIsRead')

# ---------------------------------------------------------- reason codes
e('reason-text', 'reason_text(): a code of the list only', OS,
  "\t\tif ( ! is_string( $code ) || ! isset( self::REASON_TEXTS[ $code ] ) ) {", "\t\tif ( ! is_string( $code ) ) {", 'prop', 'testEachReasonCodeHasTheWordsTheSiteThatReadTheRowWrites')
e('reason-target', 'reason_text(): the target in its words', OS,
  "\t\treturn 'read_as_other_row' === $code ? sprintf( self::REASON_TEXTS[ $code ], is_string( $target ) ? $target : 'of another name' ) : self::REASON_TEXTS[ $code ];",
  "\t\treturn self::REASON_TEXTS[ $code ];", 'prop', 'testEachReasonCodeHasTheWordsTheSiteThatReadTheRowWrites')


def test_of(expect):
    return expect.split(' with data set "', 1)[0]


ids = set()
for (id_, clause, file, search, replace, run, expect) in entries:
    if id_ in ids:
        sys.exit('duplicate id ' + id_)
    ids.add(id_)
    text = open(os.path.join(ROOT, file)).read()
    n = text.count(search)
    if n != 1:
        sys.exit('%s: search occurs %d times in %s:\n%s' % (id_, n, file, search))
    if search == replace:
        sys.exit(id_ + ': the replacement is the search')
    if run not in RUNS:
        sys.exit(id_ + ': no run ' + run)
    suite, cls = RUNS[run]
    path = os.path.join(ROOT, 'tests', 'integration' if 'it' == suite else '', cls + '.php')
    if not re.search(r'\bfunction\s+' + re.escape(test_of(expect)) + r'\s*\(', open(path).read()):
        sys.exit('%s: %s is no test of %s' % (id_, test_of(expect), path))


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
 * The clauses of the object pairs of the takeover (Z8, branch 6 of the plan,
 * part 1: decisions 10, 13 and 15): wp_site_pairs proposes pairs from the
 * inventory of the staging site and of this site (the packages of one
 * series, complete only where one package read a kind whole; the inventory of
 * this site in parts; a name holding a credential by the SHA-256 of its ref
 * only, an object whose name is a credential counted), matches by kind,
 * identity, path and hash, works out the canon change of active pairs, and
 * confirms, discards and lists with the token of the confirmation in the same
 * call, for the account and connection that made the proposal, all or
 * nothing.
 *
 * Written by tests/mutation/gen/bericht.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncProposalTest, SyncPairsToolTest) and, for what
 * two WordPress sites show, by the integration suite (SyncProposalTest; the
 * sites running, AB_IT_ROOT set). Not listed: the type guards after a read
 * against the SHA-256 of what this code wrote itself (no input reaches them),
 * and splitting into files and parts, which changes no outcome.
 *
 * @package AlphaBridge_MCP
 */

/*
 * The run of an entry: the test its expect names, alone, in the unit suite
 * or in the integration suite (-c; the sites running).
 */
$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$p  = 'includes/sync/class-sync-proposal.php';
$t  = 'includes/sync/class-sync-pairs-tool.php';
$im = 'includes/sync/class-sync-import.php';
$os = 'includes/sync/class-sync-option-schema.php';
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
open(os.path.join(ROOT, 'tests/mutation/lists/bericht.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
