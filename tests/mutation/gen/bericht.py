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
FILEVAR = {P: '$p', T: '$t', IM: '$im', OS: '$os', TS: '$ts', G: '$g', 'includes/sync/class-sync-pairs.php': '$pa', 'includes/sync/class-sync-identity.php': '$id', 'includes/sync/class-sync-inventory.php': '$inv', 'includes/sync/class-sync-export.php': '$ex', 'includes/sync/class-sync-db-kinds.php': '$dk'}

RUNS = {
    'prop': ('unit', 'SyncProposalTest'),
    'tool': ('unit', 'SyncPairsToolTest'),
    'gate': ('unit', 'StagingGateTest'),
    'it': ('it', 'SyncProposalTest'),
    'ident': ('unit', 'SyncIdentityTest'),
    'kinds': ('unit', 'SyncDbKindsTest'),
    'pkg': ('it', 'SyncPackageTest'),
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
e('compose-held', 'compose(): a line of the list of held objects is reduced', P,
  "\t\t\t\tif ( isset( $codes[ $kind . \"\\0\" . $sha ] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testTheListOfHeldObjectsReducesTheirLines')
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
e('step-held', 'step(): a line of this site a credential holds is reduced before it is stored', P,
  "\t\t\t$lines[] = null === $code ? $line : self::reduced( $line, $code );", "\t\t\t$lines[] = null === $code ? $line : array( 'held' => $code ) + $line;", 'it', 'testAHeldNameReachesNeitherTheStoreNorARealignAnswer')
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
CU = "\t\t\treturn is_array( $line ) && isset( $line['hash'] ) && is_string( $line['hash'] ) && ! empty( $line['complete'] ) && ! isset( $line['held'] );"
CANON = 'testTheTargetOfACanonChangeIsTheLiveHashOnlyWhereTheMappedFormsAreTheSame'
e('canon-form-complete', 'canon_forms(): a form not read whole is none', P, CU, CU.replace(" && ! empty( $line['complete'] )", ''), 'prop', CANON)
e('canon-form-held', 'canon_forms(): a held line has no form', P, CU, CU.replace(" && ! isset( $line['held'] )", ''), 'prop', CANON)
CS = "\t\t$same   = null !== $s && null !== $l && self::mapped_same( $staging, $live );"
e('canon-same-mapped', 'canon_forms(): the target only where the mapped forms are the same', P, CS, "\t\t$same   = null !== $s && null !== $l;", 'prop', CANON)
e('canon-target-override', 'canon_forms(): an override changes the target', P,
  "\t\t\t'target'  => null === $pair['override'] && $same ? $l : null,", "\t\t\t'target'  => $same ? $l : null,", 'prop', CANON)

# ----------------------------------------------------------------- match
e('match-taken', 'match(): objects of a pair are not proposed again', P,
  "\t\t\t\tif ( isset( $taken[ $side ][ $i ] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testObjectsOfAnActivePairAreNotProposedAgain')
e('match-realign', 'match(): only unclear and residual pairs are aligned anew', P,
  "\t\t\tif ( ! in_array( $pair['state'], array( 'unclear', 'residual' ), true ) ) {", "\t\t\tif ( false ) {", 'prop', 'testObjectsOfAnActivePairAreNotProposedAgain')
e('match-held', 'match(): a held object stands alone, by the hash of its ref', P,
  "\t\t\t\tif ( null !== $item['held'] ) {\n\t\t\t\t\t$entries[] = self::held_entry( $item );", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$entries[] = self::held_entry( $item );", 'prop', 'testAHeldObjectIsShownByTheHashOfItsRefOnly')
e('match-unread', 'match(): an object without identity is not compared', P,
  "\t\t\t\tif ( null === $item['key'] ) {", "\t\t\t\tif ( false ) {", 'prop', 'testARowNotReadIsNotComparedByItself')
e('match-ref', 'match(): a name no pair holds is not compared', P,
  "\t\t\t\tif ( ! AB_MCP_Sync_Pairs::valid_ref( $item['ref'] ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testANameNoPairHoldsIsNotCompared')
e('lookup-held', 'lookup(): a pair takes its held object by the hash of its ref', P,
  "\t\tforeach ( array( $encoded, 'sha:' . hash( 'sha256', $encoded ) ) as $key ) {", "\t\tforeach ( array( $encoded ) as $key ) {", 'prop', 'testAPairOfAHeldObjectTakesItAway')
e('reduced', 'reduced(): a held line keeps its kind, the hash of its ref and its code only', P,
  "\t\t\t'ref_sha256' => hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) ),\n\t\t\t'held'       => (string) $code,\n\t\t);",
  "\t\t\t'ref_sha256' => hash( 'sha256', AB_MCP_Sync_Canon::encode( $line['ref'] ) ),\n\t\t\t'held'       => (string) $code,\n\t\t) + $line;", 'prop', 'testAHeldLineIsReducedToItsKindTheHashOfItsRefAndItsCode')
e('doubt-slug', 'doubt(): a post without a slug is paired only by a choice', P,
  "\t\tif ( 'post' === $kind && '' === ( $identity['post_name'] ?? '' ) ) {", "\t\tif ( false ) {", 'prop', 'testPostsWithoutASlugAreUncertainNeverAPair')
DM = "\t\tif ( 'menu_item' === $kind && ( ! isset( $identity['target'] ) || ! isset( $identity['parent'] ) ) ) {"
e('doubt-target', 'doubt(): a menu item without the identity of its target', P, DM, DM.replace("! isset( $identity['target'] ) || ", ''), 'prop', 'testAMenuItemWithoutTheIdentityOfItsTargetOrParentIsUncertain')
e('doubt-parent', 'doubt(): a menu item without the identity of its parent', P, DM, DM.replace(" || ! isset( $identity['parent'] )", ''), 'prop', 'testAMenuItemWithoutTheIdentityOfItsTargetOrParentIsUncertain')
e('resolve-doubt', 'resolve(): a group with a doubt is left to a choice', P,
  "\t\tif ( null !== $doubt ) {\n\t\t\t$left = array(", "\t\tif ( false ) {\n\t\t\t$left = array(", 'prop', 'testPostsWithoutASlugAreUncertainNeverAPair')
e('resolve-path', 'resolve(): one to one on a path is a pair', P,
  "\t\t\t\tif ( 1 === count( $ss ) && 1 === count( $ll ) ) {", "\t\t\t\tif ( false ) {", 'prop', 'testThePathOfParentsTellsPagesOfTheSameSlugApart')
e('key-language-post', 'identity_key(): a post with its language (Codex round 3, B2)', P,
  "\t\t\t\t$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null, $identity['language'] ?? '' );",
  "\t\t\t\t$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null );", 'prop', 'testTranslationsSharingASlugAreTwoObjects')
e('twin-language', 'twin_key(): a parent with its language', P,
  "\t\treturn AB_MCP_Sync_Canon::encode( array( $post['type'] ?? '', $post['theme'] ?? '', (string) $post['name'], $post['language'] ?? '', $post['parent'] ) );",
  "\t\treturn AB_MCP_Sync_Canon::encode( array( $post['type'] ?? '', $post['theme'] ?? '', (string) $post['name'], $post['parent'] ) );", 'prop', 'testTranslationsSharingASlugAreTwoObjects')
e('resolve-uncertain', 'resolve(): the same identity left on other paths is uncertain, no pair by exclusion', P,
  "\t\tif ( array() !== $left['s'] && array() !== $left['l'] ) {", "\t\tif ( false ) {", 'prop', 'testTheSameSlugOnAnotherPathIsUncertainNeverAPair')
e('live-alone', 'live_alone(): only live only where the staging side is complete', P,
  "\t\tif ( true === call_user_func( $complete, $item['kind'], $item['subtype'] ) ) {", "\t\tif ( true ) {", 'prop', 'testAnObjectOnlyOnThisSiteIsLiveOnlyOnlyWhereStagingIsComplete')
NH = "\t\tif ( null === $s['hash'] || null === $l['hash'] ) {"
e('pair-no-hash-staging', 'pair_entry(): no hash on the staging side, not compared', P, NH, NH.replace("null === $s['hash'] || ", ''), 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
e('pair-no-hash-live', 'pair_entry(): no hash on this site, not compared', P, NH, NH.replace(" || null === $l['hash']", ''), 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
SA = "\t\treturn $s['complete'] && $l['complete'] && self::mapped_same( $s, $l );"
e('same-staging-whole', 'same(): only read whole on the staging site', P, SA, SA.replace("$s['complete'] && ", ''), 'prop', 'testAnObjectNotReadWholeIsNeverEqual')
e('same-live-whole', 'same(): only read whole on this site', P, SA, SA.replace("$l['complete'] && ", ''), 'prop', 'testAnObjectNotReadWholeIsNeverEqual')
e('same-mapped', 'same(): the same mapped form, not the same hash', P, SA, "\t\treturn $s['complete'] && $l['complete'] && hash_equals( (string) $s['hash'], (string) $l['hash'] );", 'prop', 'testOtherIdsOfTheSameTargetsAreEqualOnceMapped')
MS = "\t\t\tif ( isset( $s[ $key ], $l[ $key ] ) && is_string( $s[ $key ] ) && is_string( $l[ $key ] ) && hash_equals( $s[ $key ], $l[ $key ] ) ) {"
e('same-unresolved', 'mapped_same(): a side without a mapped form is no equal, also where both sides have none', P, MS, "\t\t\tif ( hash_equals( (string) ( $s[ $key ] ?? '' ), (string) ( $l[ $key ] ?? '' ) ) ) {", 'prop', 'testAReferenceWithoutATargetIsADifference')
MK = "\t\tforeach ( array( 'mapped', 'mapped_possible' ) as $key ) {"
e('same-possible', 'mapped_same(): the form with the possible references by identity suffices (Codex round 2, B-1)', P, MK, "\t\tforeach ( array( 'mapped' ) as $key ) {", 'prop', 'testEitherMappedFormMakesTheSame')
e('same-plain', 'mapped_same(): the form with the typed references alone suffices (a number that is no reference)', P, MK, "\t\tforeach ( array( 'mapped_possible' ) as $key ) {", 'prop', 'testEitherMappedFormMakesTheSame')
e('items-possible', 'items(): the second mapped form of a line', P,
  "\t\t\t\t'mapped_possible' => isset( $line['mapped_possible'] ) && is_string( $line['mapped_possible'] ) ? $line['mapped_possible'] : null,", "\t\t\t\t'mapped_possible' => null,", 'prop', 'testEitherMappedFormMakesTheSame')
e('path-doubt', 'doubt(): a path of parents not known whole is uncertain (Codex round 2, A-2)', P,
  "\t\tif ( 'post' === $kind && in_array( '?', $names, true ) ) {", "\t\tif ( false ) {", 'prop', 'testAPathNotKnownWholeIsUncertainNeverAPair')
PQ = "\t\t\tif ( isset( $seen[ $parent ] ) || ! isset( $posts[ $parent ] ) || '' === (string) $posts[ $parent ]['name'] || $posts[ $parent ]['twin'] || ( $twins[ self::twin_key( $posts[ $parent ] ) ] ?? 0 ) > 1 ) {"
e('path-no-slug', 'path(): a parent without a slug is "?"', P, PQ, PQ.replace(" || '' === (string) $posts[ $parent ]['name']", ''), 'prop', 'testAPathNotKnownWholeIsUncertainNeverAPair')
e('path-twin', 'path(): a parent whose type, slug and parent another post of its side has is "?"', P, PQ, PQ.replace(" || ( $twins[ self::twin_key( $posts[ $parent ] ) ] ?? 0 ) > 1", ''), 'prop', 'testAPathNotKnownWholeIsUncertainNeverAPair')
e('path-twin-flag', 'path(): a parent its site names a twin is "?" (Codex round 4)', P, PQ, PQ.replace(" || $posts[ $parent ]['twin']", ''), 'prop', 'testAPageUnderATwinIsUncertainAlsoWhereOnlyOneTwinIsAtHand')
e('items-parent-twin', 'items(): a parent carries the twin of its line (Codex round 4)', P,
  "\t\t\t\t\t'twin'     => ! empty( $line['twin'] ),", "\t\t\t\t\t'twin'     => false,", 'prop', 'testAPageUnderATwinIsUncertainAlsoWhereOnlyOneTwinIsAtHand')
e('items-twin', 'items(): an object carries the twin of its line (Codex round 4)', P,
  "\t\t\t\t'twin'            => ! empty( $line['twin'] ),", "\t\t\t\t'twin'            => false,", 'prop', 'testATwinOfItsSiteIsUncertainAlsoWhereOnlyOneIsAtHand')
WH = "\t\t\t\t$whole[] = '?' === $parent ? '?' : array( $parent['type'], $parent['theme'], (string) $parent['name'], $parent['language'] );"
WHOLE = 'testAParentOfAnotherLanguageTypeOrThemeIsAnotherPath'
e('path-whole-language', 'items(): a parent by its language (Codex round 4)', P, WH, WH.replace(", $parent['language']", ''), 'prop', WHOLE)
e('path-whole-type', 'items(): a parent by its type (Codex round 4)', P, WH, WH.replace("$parent['type'], ", ''), 'prop', WHOLE)
e('path-whole-theme', 'items(): a parent by its theme (Codex round 4)', P, WH, WH.replace(" $parent['theme'],", ''), 'prop', WHOLE)
e('path-whole', 'items(): the path by the whole parents, not their slugs', P,
  "\t\t\t\t'path'            => AB_MCP_Sync_Canon::encode( $whole ),", "\t\t\t\t'path'            => AB_MCP_Sync_Canon::encode( $names ),", 'prop', WHOLE)
TW = "\t\t\t\t$twin = count( $ss ) > 1 || count( $ll ) > 1;"
e('resolve-twice-staging', 'resolve(): an identity twice on a path of the staging side is uncertain (Codex round 4)', P, TW, TW.replace('count( $ss ) > 1 || ', ''), 'prop', 'testMoreOfTheSameIdentityOnAPathAreUncertainWithAllOfThem')
e('resolve-twice-live', 'resolve(): an identity twice on a path of this site is uncertain (Codex round 4)', P, TW, TW.replace(' || count( $ll ) > 1', ''), 'prop', 'testMoreOfTheSameIdentityOnAPathAreUncertainWithAllOfThem')
e('resolve-twin-flag', 'resolve(): a line its site names a twin is uncertain, also alone in its package (Codex round 4)', P,
  "\t\t\t\t\t$twin = $twin || $item['twin'];", "\t\t\t\t\t$twin = $twin;", 'prop', 'testATwinOfItsSiteIsUncertainAlsoWhereOnlyOneIsAtHand')
e('resolve-twin-rest', 'resolve(): what is left of twins after a choice is uncertain, never equal (Codex round 4)', P,
  "\t\t\t\t\t$twin = $twin || $item['twin'];", "\t\t\t\t\t$twin = $twin;", 'prop', 'testTheRestOfTwinsAfterAChosenPairIsUncertainNeverEqual')
e('resolve-twin-uncertain', 'resolve(): twins on a path with candidates on both sides are one uncertain entry', P,
  "\t\t\t\tif ( $twin && array() !== $ss && array() !== $ll ) {", "\t\t\t\tif ( false ) {", 'prop', 'testATwinOfItsSiteIsUncertainAlsoWhereOnlyOneIsAtHand')
FORM = 'testAnEqualMappedFormThereOnceOnEachSideOfAPathPairsTwins'
DRAFT = 'testAPublishedPageAndADraftOfItsSlugUnchangedAreTwoEqualPairs'
HAND = 'testTwinsAreNotPairedByTheirFormWhereNotAllOfThemAreAtHand'
e('resolve-form-pairs', 'resolve(): twins paired by their form are pairs (Codex round 5)', P,
  "\t\t\t\t\t\t$out[] = self::pair_entry( $pair[0], $pair[1] );", "\t\t\t\t\t\t/* removed */", 'prop', FORM)
RR = "\t\t\t\t\tif ( array() !== $ss && array() !== $ll ) {\n\t\t\t\t\t\t$out[] = self::choice_entry( 'uncertain', $ss, $ll, self::twin_reason( $ss[0]['kind'] ) );"
e('resolve-form-rest', 'resolve(): what is left of twins after their form is uncertain, never a pair by exclusion (Codex round 5)', P, RR, RR.replace('if ( array() !== $ss && array() !== $ll ) {', 'if ( false ) {'), 'prop', DRAFT)
e('resolve-form-rest-hand', 'resolve(): twins not all at hand are uncertain, never a pair by exclusion (Codex round 4)', P, RR, RR.replace('if ( array() !== $ss && array() !== $ll ) {', 'if ( false ) {'), 'prop', HAND)
BF = "\t\tif ( 'menu_item' === $ss[0]['kind'] || ! self::all_at_hand( $ss ) || ! self::all_at_hand( $ll ) ) {"
ITD = 'testAPublishedPageAndADraftOfItsSlugArePairedByTheirContent'
e('resolve-form-pairs-it', 'resolve(): a published page and a draft of its slug are pairs by their form (two sites, Codex round 5)', P,
  "\t\t\t\t\t\t$out[] = self::pair_entry( $pair[0], $pair[1] );", "\t\t\t\t\t\t/* removed */", 'it', ITD)
e('resolve-form-rest-it', 'resolve(): the changed one of them is uncertain, never differs (two sites, Codex round 5)', P, RR, RR.replace('if ( array() !== $ss && array() !== $ll ) {', 'if ( false ) {'), 'it', ITD)
e('form-menu', 'by_form(): never menu items, only their place tells them apart (Codex round 3, A2)', P, BF, BF.replace("'menu_item' === $ss[0]['kind'] || ", ''), 'prop', 'testMenuItemTwinsStayUncertainAlsoWhereAllAreAtHand')
e('form-staging-hand', 'by_form(): only with every twin of the staging site at hand (Codex round 5)', P, BF, BF.replace(' || ! self::all_at_hand( $ss )', ''), 'prop', HAND)
e('form-live-hand', 'by_form(): only with every twin of this site at hand (Codex round 5)', P, BF, BF.replace(' || ! self::all_at_hand( $ll )', ''), 'prop', HAND)
BL = "\t\tforeach ( array( 'mapped', 'mapped_possible' ) as $form ) {"
e('form-mapped', 'by_form(): by the form with the typed references (Codex round 3, B3)', P, BL, BL.replace("'mapped', ", ''), 'prop', FORM)
e('form-possible', 'by_form(): then by the form with the possible references (Codex round 3, B3)', P, BL, BL.replace(", 'mapped_possible'", ''), 'prop', FORM)
e('form-both', 'by_form(): the pairs of both forms', P,
  "\t\t\t$pairs                   = array_merge( $pairs, $found );", "\t\t\t$pairs                   = $found;", 'prop', FORM)
e('at-hand', 'all_at_hand(): as many on the path as the site says it has (Codex round 5)', P,
  "\t\t\tif ( count( $items ) !== $item['twins'] ) {", "\t\t\tif ( false ) {", 'prop', HAND)
IT = "\t\t\t\t'twins'           => empty( $line['twin'] ) ? 1 : $line['twin'],"
e('items-twins-none', 'items(): a line that is no twin is all its site has (Codex round 5)', P, IT, IT.replace('? 1 :', '? 0 :'), 'prop', DRAFT)
e('items-twins-count', 'items(): a twin as many as its site says (Codex round 5)', P, IT, IT.replace(": $line['twin'],", ': 2,'), 'prop', HAND)
e('unique-whole', 'unique_pairs(): only objects read whole (Codex round 5)', P,
  "\t\t\treturn is_string( $item[ $form ] ) && $item['complete'];", "\t\t\treturn is_string( $item[ $form ] );", 'prop', 'testTwinsNotReadWholeAreNotPairedByTheirForm')
UQ = "\t\t\tif ( $proof( $item ) && 1 === $hl[ $item[ $form ] ] && 1 === ( $hs[ $item[ $form ] ] ?? 0 ) ) {"
ONEFORM = 'testTwinsOfOneFormAreUncertain'
e('unique-live', 'unique_pairs(): a form there once on this site', P, UQ, UQ.replace('1 === $hl[ $item[ $form ] ]', '$hl[ $item[ $form ] ] >= 1'), 'prop', ONEFORM)
e('unique-staging', 'unique_pairs(): a form there once on the staging site', P, UQ, UQ.replace('1 === ( $hs[ $item[ $form ] ] ?? 0 )', 'isset( $hs[ $item[ $form ] ] )'), 'prop', ONEFORM)
e('unique-proof-live', 'unique_pairs(): an object of this site read whole', P, UQ, UQ.replace('$proof( $item ) && ', ''), 'prop', 'testTwinsNotReadWholeAreNotPairedByTheirForm')
e('unique-proof-staging', 'unique_pairs(): an object of the staging site read whole', P,
  "\t\t\tif ( $proof( $item ) && isset( $by[ $item[ $form ] ] ) ) {", "\t\t\tif ( is_string( $item[ $form ] ) && isset( $by[ $item[ $form ] ] ) ) {", 'prop', 'testTwinsNotReadWholeAreNotPairedByTheirForm')
e('unique-rest-staging', 'unique_pairs(): a staging object paired is not left', P,
  "\t\t\t\t$pairs[]                        = array( $item, $ll[ $by[ $item[ $form ] ] ] );\n\t\t\t\tcontinue;", "\t\t\t\t$pairs[]                        = array( $item, $ll[ $by[ $item[ $form ] ] ] );", 'prop', DRAFT)
e('unique-rest-live', 'unique_pairs(): an object of this site paired is not left', P,
  "\t\t\tif ( ! isset( $used[ $j ] ) ) {", "\t\t\tif ( true ) {", 'prop', DRAFT)
e('twin-reason-menu', 'twin_reason(): menu items told apart by their place only', P,
  "\t\tif ( 'menu_item' === $kind ) {\n\t\t\t// Only the place", "\t\tif ( false ) {\n\t\t\t// Only the place", 'prop', 'testTheSameTargetTwiceUnderOneParentIsUncertainEvenWhereTheFormsTellThemApart')
RD = "\t\t$ready = null !== $s && null !== $l && null === $s['held'] && null === $l['held'] && null !== $s['hash'] && null !== $l['hash'];"
e('realign-staging-held', 'realign_entry(): not with a held staging object', P, RD, RD.replace(" && null === $s['held']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash')
e('realign-live-held', 'realign_entry(): not with a held object of this site', P, RD, RD.replace(" && null === $l['held']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash')
e('realign-staging-hash', 'realign_entry(): not without the hash of the staging object', P, RD, RD.replace(" && null !== $s['hash']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash')
e('realign-live-hash', 'realign_entry(): not without the hash of the object of this site', P, RD, RD.replace(" && null !== $l['hash']", ''), 'prop', 'testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash')
e('realign-note', 'realign_entry(): sides that differ now are named', P,
  "\t\tif ( ! self::same( $s, $l ) ) {", "\t\tif ( false ) {", 'prop', 'testUnclearAndResidualPairsAreProposedForANewAlignment')
e('alone-why', 'alone(): an object alone without a hash says why', P,
  "( null === $item['hash'] && null !== $item['reason_code'] ? self::why_no_hash( $item ) : null )", "null", 'prop', 'testAnObjectAloneWithoutAHashSaysWhy')
e('why-code', 'why_no_hash(): the words of the reason code', P,
  "\t\tif ( null !== $text ) {\n\t\t\treturn $text;", "\t\tif ( false ) {\n\t\t\treturn $text;", 'prop', 'testASideWithoutAHashIsNotComparedWithTheWordsOfItsCode')
e('key-unread', 'identity_key(): none without an identity', P,
  "\t\tif ( array() === $identity ) {\n\t\t\treturn null;\n\t\t}\n\t\tswitch ( $kind ) {", "\t\tif ( false ) {\n\t\t\treturn null;\n\t\t}\n\t\tswitch ( $kind ) {", 'prop', 'testTheIdentityKeyOfEachKind')
e('key-menu-slug', 'identity_key(): a menu item by the slug of its menu', P,
  "isset( $menus[ (string) $menu ] ) ? AB_MCP_Sync_Canon::encode( $menus[ (string) $menu ] ) : '?'", "'?'", 'prop', 'testAMenuItemMatchesByTheSlugOfItsMenuNotItsId')
KM = "\t\t\t\t$parts = array( $slugs, $identity['type'] ?? null, $identity['object'] ?? null, $identity['target'] ?? null, $identity['parent'] ?? null );"
e('key-menu-position', 'identity_key(): a menu item not by its position', P, KM, KM.replace(" );", ", $identity['position'] ?? null );"), 'prop', 'testAMenuSortedAnewPairsEachItemWithItsTarget')
e('key-menu-target', 'identity_key(): a menu item by its target', P, KM, KM.replace(" $identity['target'] ?? null,", ''), 'prop', 'testAMenuSortedAnewPairsEachItemWithItsTarget')
e('key-menu-parent', 'identity_key(): a menu item by its parent item', P, KM, KM.replace(", $identity['parent'] ?? null", ''), 'prop', 'testTheSameTargetTwiceUnderOneParentIsUncertainEvenWhereTheFormsTellThemApart')
e('key-css-linked', 'identity_key(): the CSS of a stylesheet and a CSS post apart', P,
  "\t\t\t\t$parts = array( $identity['stylesheet'] ?? null, ! empty( $identity['linked'] ) );", "\t\t\t\t$parts = array( $identity['stylesheet'] ?? null );", 'prop', 'testTheIdentityKeyOfEachKind')
e('key-theme', 'identity_key(): the theme of a template', P,
  "$parts = array( $identity['post_type'] ?? null, $identity['theme'] ?? null, $identity['post_name'] ?? null, $identity['language'] ?? '' );", "$parts = array( $identity['post_type'] ?? null, $identity['post_name'] ?? null, $identity['language'] ?? '' );", 'prop', 'testTheIdentityKeyOfEachKind')
e('parent-at', 'parent_of(): only the post_parent is a parent', P,
  "\t\t\tif ( 'post_parent' === ( $ref['at'] ?? null ) && 'post' === ( $ref['space'] ?? null ) ) {", "\t\t\tif ( 'post' === ( $ref['space'] ?? null ) ) {", 'prop', 'testOnlyThePostParentIsAParentNotAnotherReferenceToAPost')
e('side-held', 'side(): a held object by the hash of its ref only', P,
  "\t\tif ( null !== $item['held'] ) {\n\t\t\treturn array(\n\t\t\t\t'held'       => $item['held'],", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'held'       => $item['held'],", 'prop', 'testAPairWithAHeldSideIsNotAlignedAnewAndNamesItByItsHash')
e('side-path', 'side(): the path of parents shown with a post', P,
  "\t\tif ( 'post' === $item['kind'] ) {\n\t\t\t$out['path'] = $item['names'];", "\t\tif ( false ) {\n\t\t\t$out['path'] = $item['names'];", 'prop', 'testTheSameSlugOnAnotherPathIsUncertainNeverAPair')
SH_S = "\t\t\t\tunset( $entry[ $side ]['hash'], $entry[ $side ]['mapped'], $entry[ $side ]['mapped_possible'] );"
SH_C = "\t\t\t\t\tunset( $entry['candidates'][ $side ][ $i ]['hash'], $entry['candidates'][ $side ][ $i ]['mapped'], $entry['candidates'][ $side ][ $i ]['mapped_possible'] );"
e('shown-hash', 'shown(): no hash or mapped form of a side', P, SH_S, "", 'prop', 'testAnAnswerShowsNoHashMappedFormOrSnapshot')
e('shown-possible', 'shown(): no second mapped form of a side', P, SH_S, SH_S.replace(", $entry[ $side ]['mapped_possible']", ''), 'prop', 'testAnAnswerShowsNoHashMappedFormOrSnapshot')
e('shown-candidates', 'shown(): no hash or mapped form of a candidate', P, SH_C, "", 'prop', 'testAnAnswerShowsNoHashMappedFormOrSnapshot')
e('shown-candidates-possible', 'shown(): no second mapped form of a candidate', P, SH_C, SH_C.replace(", $entry['candidates'][ $side ][ $i ]['mapped_possible']", ''), 'prop', 'testAnAnswerShowsNoHashMappedFormOrSnapshot')
e('shown-snapshot', 'shown(): no snapshot of a pair', P,
  "\t\t\tunset( $entry['pair']['snapshot'] );", "", 'prop', 'testAnAnswerShowsNoHashMappedFormOrSnapshot')
e('page-shown', 'page(): the entries as shown', P,
  "\t\t$entries = array_map( array( __CLASS__, 'shown' ), $page['entries'] );", "\t\t$entries = $page['entries'];", 'it', 'testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp')
e('parse-name', 'parse_name(): only the one spelling of name()', P,
  "\t\tif ( self::name( $prefix, $seq, $hex ) !== $name ) {", "\t\tif ( false ) {", 'prop', 'testTheNamesOfTheFilesOfAProposal')
e('live-now-gone', 'live_now(): an object that is no more is gone', P,
  "$out[ $object['kind'] . \"\\0\" . $object['ref'] ] = is_array( $read ) && isset( $read['hash'] ) ? (string) $read['hash'] : 'gone';", "$out[ $object['kind'] . \"\\0\" . $object['ref'] ] = is_array( $read ) && isset( $read['hash'] ) ? (string) $read['hash'] : 'x';", 'it', 'testTheTokenCoversTheObjectsOfThisSiteAsTheyAreWhenConfirmed')

# ------------------------------------------------------- mapped forms
ID = 'includes/sync/class-sync-identity.php'
INV = 'includes/sync/class-sync-inventory.php'
EXF = 'includes/sync/class-sync-export.php'
DK = 'includes/sync/class-sync-db-kinds.php'
MAPPED = 'testTheSameObjectsWithOtherIdsAreEqualOnceMapped'
e('entry-mapped', 'entry(): the mapped form of an object', INV,
  "\t\t$result['mapped'] = isset( $result['form'], $result['hash'] ) && is_array( $result['form'] ) && null !== self::$ctx ? AB_MCP_Sync_Identity::mapped_hash( $result['form'], self::$ctx ) : null;",
  "\t\t$result['mapped'] = isset( $result['hash'] ) ? $result['hash'] : null;", 'it', MAPPED)
B1 = 'testImageIdsOfPluginsOnOtherIdsAreEqualAndAnotherImageDiffers'
UNIQ = 'testAnIdentityAnotherObjectOfTheSiteHasNamesNone'
HEAD = 'testTwoHeadingsOfOneAddressAreTwoItemsAndAChildMovedUnderTheOtherIsNoEqual'
e('export-mapped', 'line(): the mapped form goes into the package', EXF,
  "\t\t\t'mapped'          => isset( $object['mapped'] ) ? $object['mapped'] : null,", "\t\t\t'mapped'          => null,", 'it', MAPPED)
e('export-possible', 'line(): the second mapped form goes into the package', EXF,
  "\t\t\t'mapped_possible' => isset( $object['mapped_possible'] ) ? $object['mapped_possible'] : null,", "\t\t\t'mapped_possible' => null,", 'it', B1)
e('entry-possible', 'entry(): the second mapped form of an object', INV,
  "\t\t$result['mapped_possible'] = null !== $result['mapped'] && isset( $result['possible'] ) && is_array( $result['possible'] ) ? AB_MCP_Sync_Identity::possible_hash( $result['form'], $result['possible'], self::$ctx ) : null;",
  "\t\t$result['mapped_possible'] = null;", 'it', B1)
PL = 'testTranslationsOfPolylangSharingSlugsAndAFileAreEqualByLanguage'
e('identity-line-language', 'read(): the line of a post with its language', DK,
  "\t\tif ( '' !== $language ) {\n\t\t\t$identity['language'] = null === $language ? '?' : (string) $language;\n\t\t}\n\t\treturn AB_MCP_Sync_Canon::finish( 'post',", "\t\tif ( false ) {\n\t\t\t$identity['language'] = null === $language ? '?' : (string) $language;\n\t\t}\n\t\treturn AB_MCP_Sync_Canon::finish( 'post',", 'it', PL)
e('identity-open', 'identity_fits(): a line without a language is whole', IM,
  "\t\t$needs = array_diff( $want, self::IDENTITY_OPEN[ $kind ] ?? array() );", "\t\t$needs = $want;", 'it', MAPPED)
PH = "\t\tif ( null !== $there && ( false !== $there ) !== $terms ) {"
e('possible-hit', 'hit(): a whole number names the object it hits by its identity', ID, PH, "\t\tif ( false ) {", 'it', B1)
e('possible-both', 'hit(): a number that hits a post and a term names neither', ID, PH, "\t\tif ( null !== $there && ( false !== $there || $terms ) ) {", 'it', 'testANumberThatHitsAPostAndATermNamesNeither')
VW = 'testAValueIsMappedOnlyAsItIsWritten'
e('possible-plain', 'numbers_of(): only a number in its plain spelling (Codex round 3, A1)', ID,
  "\t\t\tif ( 1 === preg_match( '/^[1-9][0-9]{0,17}\\z/', $part ) ) {", "\t\t\tif ( 1 === preg_match( '/^[0-9]{1,18}\\z/', $part ) ) {", 'it', VW)
e('possible-parts', 'numbers_of(): every part of the value kept', ID,
  "\t\t$parts = is_int( $value ) ? array( (string) $value ) : preg_split( '/([0-9]+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );", "\t\t$parts = array_map( 'strval', AB_MCP_Sync_Refs::numbers( $value ) );", 'it', VW)
e('possible-type', 'numbers_of(): a number and a text apart', ID,
  "\t\t\t'~possible' => is_int( $value ) ? 'number' : 'text',", "\t\t\t'~possible' => 'value',", 'it', VW)
e('possible-json', 'stored(): stored JSON by the paths of its tree', ID,
  "\t\t\tif ( 1 === count( $parts ) && is_array( $parts[0] ) && isset( $parts[0]['~json'] ) && array_key_exists( 'v', $parts[0] ) ) {", "\t\t\tif ( false ) {", 'it', B1)
e('possible-php', 'stored(): a serialized value key by key', ID,
  "\t\t\t\treturn array( '~php' => self::tree( $value['~php'], $at, $set, $ctx, $hit, $layers ) );", "\t\t\t\treturn $value;", 'it', B1)
e('possible-object', 'tree(): an object of JSON member by member', ID,
  "\t\t\treturn array( '~o' => self::tree( $value['~o'], $at, $set, $ctx, $hit, $layers ) );", "\t\t\treturn $value;", 'it', B1)
e('possible-inside', 'inside(): a text that holds JSON of no producer by its value', ID,
  "\t\t$out   = self::noted( $inner, $at, $set, $ctx, $found, $layers + 1, 0 );", "\t\t$out   = $inner;", 'it', B1)
e('possible-flat', 'flat(): a part that is text again by its parts', ID,
  "\t\t\t} elseif ( is_array( $part ) && array( '~s' ) === array_keys( $part ) && is_array( $part['~s'] ) ) {", "\t\t\t} elseif ( false ) {", 'it', B1)
e('identity-unresolved', 'map(): a reference without an identity gives no mapped form', ID,
  "\t\t\tif ( null === $as ) {\n\t\t\t\t$ok = false;", "\t\t\tif ( null === $as ) {\n\t\t\t\t$ok = true;", 'it', 'testATargetWithoutIdentityNamesNothingAlsoWhereBothSidesAreAlike')
e('identity-ref', 'map(): a reference names its target by identity', ID,
  "\t\t\tunset( $value['id'] );\n\t\t\treturn array( 'as' => $as ) + $value;", "\t\t\treturn array( 'as' => $as ) + $value;", 'it', MAPPED)

# ------------------------------------- the index of identities (Codex round 4)
ONE  = 'testAnIdentityAnotherObjectHasNamesNoneAndBothAreTwins'
LANG = 'testTheLanguageOfPolylangThenWpml'
ITEM = 'testAMenuItemByItsTargetAndParentAndTwinsNameNone'
PART = 'testAnIndexBuiltInPartsIsTheIndexBuiltAtOnce'
GONE = 'testATableThatCannotBeReadIsNamedAndEveryIdentityIsNone'
COUNT = 'testATwinSaysHowManyObjectsOfItsSiteHaveItsIdentity'
e('index-load', 'reset(): a stored index of the run is taken up', ID,
  "\t\tif ( is_string( $stored ) && null !== self::$print && ! self::load( $stored ) ) {", "\t\tif ( false ) {", 'ident', PART)
e('index-load-large', 'reset(): a stored index asks the database nothing', ID,
  "\t\tif ( is_string( $stored ) && null !== self::$print && ! self::load( $stored ) ) {", "\t\tif ( false ) {", 'ident', 'testTheIndexOfALargeSiteStaysCompact')
LH = "\t\tif ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) || self::$print !== ( $head['print'] ?? null ) || ! is_int( $head['at'] ?? null ) || $head['at'] < 0 || $head['at'] > count( self::STEPS ) || ! is_array( $head['sections'] ?? null ) ) {"
e('index-load-format', 'load(): an index of another format is none', ID, LH, LH.replace(" || self::FORMAT !== ( $head['format'] ?? null )", ''), 'ident', 'testAStoredIndexOfAnotherContextOrFormatIsNone')
e('index-load-context', 'load(): an index of another context (addresses, routes) is none', ID, LH, LH.replace(" || self::$print !== ( $head['print'] ?? null )", ''), 'ident', 'testAStoredIndexOfAnotherContextOrFormatIsNone')
e('index-failed', 'build(): a table that could not be read stays so in the call', ID,
  "\t\tif ( null !== self::$failed ) {\n\t\t\treturn self::$failed;", "\t\tif ( false ) {\n\t\t\treturn self::$failed;", 'ident', GONE)
e('index-context', 'build(): without a context, that of no site', ID,
  "\t\tif ( null === self::$ctx ) {\n\t\t\tself::$ctx   = AB_MCP_Sync_Canon::context( array() );", "\t\tif ( false ) {\n\t\t\tself::$ctx   = AB_MCP_Sync_Canon::context( array() );", 'ident', ITEM)
TT = "\t\t\tif ( $worked && null !== $out_of_time && call_user_func( $out_of_time ) ) {"
e('index-time', 'build(): as far as the time reaches, a page after the other', ID, TT, "\t\t\tif ( false ) {", 'ident', PART)
e('index-time-one', 'build(): at least one page a call', ID, TT, TT.replace('$worked && ', ''), 'ident', PART)
e('index-unreadable', 'build(): a table that could not be read is named', ID,
  "\t\t\tif ( true !== $step ) {", "\t\t\tif ( false ) {", 'ident', GONE)
SN = "\t\tif ( ! self::$changed || null !== self::$failed || null === self::$print ) {"
e('index-stored-same', 'stored(): nothing new to keep where it was taken up as it was', ID, SN, SN.replace('! self::$changed || ', ''), 'ident', 'testTheIndexOfALargeSiteStaysCompact')
e('index-stored-failed', 'stored(): nothing to keep where a table could not be read', ID, SN, SN.replace(' || null !== self::$failed', ''), 'ident', GONE)
e('index-stored-done', 'stored(): a finished index as such', ID,
  "\t\tif ( self::$at >= count( self::STEPS ) ) {\n\t\t\t$head['lines']", "\t\tif ( false ) {\n\t\t\t$head['lines']", 'ident', 'testTheIndexOfALargeSiteStaysCompact')
e('index-relations-unread', 'work(): assignments that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'term_relationships';", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'term_relationships';", 'ident', GONE)
e('index-themes', 'work(): a template of two themes has none', ID,
  "\t\t\t\t\t\tself::$themes[ $id ] = array_key_exists( $id, self::$themes ) ? null : $row['slug'];", "\t\t\t\t\t\tself::$themes[ $id ] = $row['slug'];", 'ident', ONE)
e('index-menus', 'work(): a menu item by the slugs of its menus', ID,
  "\t\t\t\t\t\tself::$menus[ $id ][] = $row['slug'];", "\t\t\t\t\t\tself::$menus[ $id ] = array();", 'ident', ITEM)
e('index-polylang-two', 'work(): an object of two languages of Polylang has none', ID,
  "\t\t\t\t\t\tself::$langs[ $step ][ $id ] = isset( self::$langs[ $step ][ $id ] ) ? self::MANY : self::code( $row['slug'] );", "\t\t\t\t\t\tself::$langs[ $step ][ $id ] = self::code( $row['slug'] );", 'ident', LANG)
e('index-wpml-table-unread', 'work(): the table of WPML that cannot be asked for', ID,
  "\t\t\t\t\tif ( null === $there ) {", "\t\t\t\t\tif ( false ) {", 'ident', GONE)
e('index-wpml-none', 'work(): no table of WPML, no row of it asked', ID,
  "\t\t\t\t\treturn $there ? true : self::paged( 0, 1 );", "\t\t\t\t\treturn true;", 'it', MAPPED)
e('index-wpml-unread', 'work(): rows of WPML that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'icl_translations';", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'icl_translations';", 'ident', GONE)
WS = "\t\t\t\t\t$space = 0 === strpos( $row['type'], 'post_' ) ? 'wpml_post' : ( 0 === strpos( $row['type'], 'tax_' ) ? 'wpml_tax' : null );"
e('index-wpml-tax', 'work(): a term of WPML by its term_taxonomy_id', ID, WS, WS.replace("( 0 === strpos( $row['type'], 'tax_' ) ? 'wpml_tax' : null )", 'null'), 'ident', LANG)
e('index-wpml-post', 'work(): only an element of a post type is a post', ID, WS, WS.replace("0 === strpos( $row['type'], 'post_' ) ?", 'true ?'), 'ident', LANG)
WC = "\t\t\t\t\t\tself::$langs[ $space ][ $row['id'] ] = ! isset( self::$langs[ $space ][ $row['id'] ] ) || $code === self::$langs[ $space ][ $row['id'] ] ? $code : self::MANY;"
e('index-wpml-same', 'work(): rows of one language of WPML name it', ID, WC, WC.replace(" || $code === self::$langs[ $space ][ $row['id'] ]", ''), 'ident', LANG)
e('index-wpml-two', 'work(): rows of two languages of WPML name none', ID, WC, "\t\t\t\t\t\tself::$langs[ $space ][ $row['id'] ] = $code;", 'ident', LANG)
e('index-files-unread', 'work(): the files of the attachments that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'postmeta';\n\t\t\t\t}\n\t\t\t\tforeach ( $rows as $row ) {\n\t\t\t\t\t$id = $row['post_id'];", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'postmeta';\n\t\t\t\t}\n\t\t\t\tforeach ( $rows as $row ) {\n\t\t\t\t\t$id = $row['post_id'];", 'ident', GONE)
e('index-files-two', 'work(): an attachment of two files has none', ID,
  "\t\t\t\t\tif ( array_key_exists( $id, self::$files ) ) {", "\t\t\t\t\tif ( false ) {", 'ident', ONE)
FF = "\t\t\t\t\t\tself::$files[ $id ] = '' === $row['value'] || null === $lang ? false : self::part( array( 'attachment', $row['value'], $lang ) );"
e('index-file-empty', 'work(): an attachment of an empty file has none', ID, FF, FF.replace("'' === $row['value'] || ", ''), 'ident', ONE)
e('index-file-languages', 'work(): an attachment of two languages has none', ID, FF, FF.replace(" || null === $lang", ''), 'ident', LANG)
e('index-file-language', 'work(): an attachment by its file and language', ID, FF, FF.replace("$row['value'], $lang ) )", "$row['value'], '' ) )"), 'ident', LANG)
e('index-posts-unread', 'work(): the posts that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'posts';", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'posts';", 'ident', GONE)
e('index-terms-unread', 'work(): the terms that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'terms';", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'terms';", 'ident', GONE)
TN = "\t\t\t\t\t$none   = '' === (string) $row['slug'] || null === $lang;"
e('index-term-slug', 'work(): a term without a slug has none', ID, TN, TN.replace("'' === (string) $row['slug'] || ", ''), 'ident', ONE)
e('index-term-languages', 'work(): a term of two languages has none', ID, TN, TN.replace(" || null === $lang", ''), 'ident', LANG)
e('index-term-language', 'work(): a term by taxonomy, slug and language', ID,
  "self::part( array( 'term', (string) $row['taxonomy'], (string) $row['slug'], $lang ) )", "self::part( array( 'term', (string) $row['taxonomy'], (string) $row['slug'], '' ) )", 'ident', LANG)
e('index-items-unread', 'work(): the menu items that cannot be read', ID,
  "\t\t\t\tif ( null === $rows ) {\n\t\t\t\t\treturn 'postmeta';\n\t\t\t\t}\n\t\t\t\tself::read_items( $rows );", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn 'postmeta';\n\t\t\t\t}\n\t\t\t\tself::read_items( $rows );", 'ident', GONE)
e('index-no-file', 'read_post(): an attachment without a file has none', ID,
  "\t\t\t$kind = false === $file ? self::NO_FILE : self::FILE;", "\t\t\t$kind = self::FILE;", 'ident', ITEM)
e('index-item', 'read_post(): a menu item by its line, not as a post', ID,
  "\t\t} elseif ( 'nav_menu_item' === $row['post_type'] ) {", "\t\t} elseif ( false ) {", 'ident', ITEM)
e('index-theme', 'read_post(): a template with its theme', ID,
  "\t\t\tif ( in_array( $row['post_type'], self::THEMED, true ) ) {", "\t\t\tif ( false ) {", 'ident', ONE)
PK = "\t\t\t$kind = '' === $row['post_name'] || null === $theme || null === $lang ? self::UNNAMED : self::NAMED;"
e('index-slug', 'read_post(): a post without a slug has none', ID, PK, PK.replace("'' === $row['post_name'] || ", ''), 'ident', ONE)
e('index-themes-two', 'read_post(): a template of two themes has none', ID, PK, PK.replace(' || null === $theme', ''), 'ident', ONE)
e('index-languages-two', 'read_post(): a post of two languages has none', ID, PK, PK.replace(' || null === $lang', ''), 'ident', LANG)
PP = "\t\t\t\t$part = self::part( array( 'post', $row['post_type'], $theme, $row['post_name'], $lang ) );"
e('index-post-language', 'read_post(): a post by its language (Codex round 3, B2)', ID, PP, PP.replace(", $lang ) );", ", '' ) );"), 'ident', LANG)
e('index-post-type', 'read_post(): a post by its type', ID, PP, PP.replace("$row['post_type'], ", ''), 'ident', ONE)
e('index-custom', 'read_items(): a custom link by its address and label', ID,
  "\t\t\tif ( 'custom' === $type ) {", "\t\t\tif ( false ) {", 'ident', ITEM)
CU = "\t\t\t\t$custom = bin2hex( self::part( array( 'custom', AB_MCP_Sync_Canon::text( (string) ( $m['_menu_item_url'] ?? '' ), self::$ctx, array(), $acc, 'meta:_menu_item_url', true ), (string) $title ) ) );"
e('index-custom-url', 'read_items(): a custom link by the neutral form of its address', ID, CU, CU.replace("AB_MCP_Sync_Canon::text( (string) ( $m['_menu_item_url'] ?? '' ), self::$ctx, array(), $acc, 'meta:_menu_item_url', true ), ", ''), 'ident', ITEM)
e('index-custom-label', 'read_items(): a custom link by its label as well (Codex round 2, A-1)', ID, CU, CU.replace(", (string) $title ) ) );", " ) ) );"), 'ident', ITEM)
e('index-attachment-twins', 'compute(): attachments of one file and language name none', ID,
  "\t\t$files  = self::twice( self::$read, self::READ, 19, self::FILE );", "\t\t$files  = array();", 'ident', ONE)
MK = "\t\t\t\tif ( self::NAMED !== ord( self::$read[ $offset + 8 ] ) || false === $above ) {"
e('index-unnamed', 'compute(): a post without what names it has none', ID, MK, MK.replace('self::NAMED !== ord( self::$read[ $offset + 8 ] ) || ', ''), 'ident', ONE)
e('index-under-none', 'compute(): under a parent without an identity, none', ID, MK, MK.replace(' || false === $above', ''), 'ident', ONE)
e('index-parent', 'compute(): a post with the identity of its parent', ID,
  "\t\t\t\treturn self::under( substr( self::$read, $offset + 19, 32 ), 0 === $up ? '' : $above );", "\t\t\t\treturn self::under( substr( self::$read, $offset + 19, 32 ), '' );", 'ident', ONE)
e('index-under-attachment', 'compute(): an attachment is a final parent', ID,
  "\t\t\t\treturn array_key_exists( $up, $final ) ? $final[ $up ] : self::attachment( $up, $files );", "\t\t\t\treturn array_key_exists( $up, $final ) ? $final[ $up ] : null;", 'ident', ONE)
e('index-term-twins', 'compute(): terms of one taxonomy, slug and language name none', ID,
  "\t\t$doubled = self::twice( self::$read_terms, self::TERM, 21, null );", "\t\t$doubled = array();", 'ident', ONE)
e('index-term-twin-flag', 'compute(): such a term is a twin', ID,
  "chr( ( $has ? self::HAS : 0 ) | ( $twin ? self::TWIN : 0 ) ) . ( $has ? substr( $rec, 21, 32 ) : self::counted( $n ) );", "chr( ( $has ? self::HAS : 0 ) ) . ( $has ? substr( $rec, 21, 32 ) : self::counted( $n ) );", 'ident', ONE)
e('index-term-count', 'compute(): such a term says how many have its identity (Codex round 5)', ID,
  "chr( ( $has ? self::HAS : 0 ) | ( $twin ? self::TWIN : 0 ) ) . ( $has ? substr( $rec, 21, 32 ) : self::counted( $n ) );", "chr( ( $has ? self::HAS : 0 ) | ( $twin ? self::TWIN : 0 ) ) . ( $has ? substr( $rec, 21, 32 ) : self::counted( 0 ) );", 'ident', COUNT)
e('index-item-under-none', 'compute(): a menu item under one without an identity has none', ID,
  "\t\t\t\tif ( false === $above ) {\n\t\t\t\t\treturn false;\n\t\t\t\t}\n\t\t\t\t$item = $items[ $id ];", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn false;\n\t\t\t\t}\n\t\t\t\t$item = $items[ $id ];", 'ident', ITEM)
e('index-item-attachment', 'compute(): a menu item showing an attachment by its identity', ID,
  "\t\t\t\t\t\t$target = array_key_exists( $item['o'], $final ) ? $final[ $item['o'] ] : self::attachment( $item['o'], $files );", "\t\t\t\t\t\t$target = array_key_exists( $item['o'], $final ) ? $final[ $item['o'] ] : null;", 'ident', ITEM)
e('index-item-term', 'compute(): a menu item showing a term by its identity', ID,
  "\t\t\t\t\t\t$target = $tfinal[ $item['o'] . \"\\0\" . $of ] ?? false;", "\t\t\t\t\t\t$target = false;", 'ident', ITEM)
e('index-item-other', 'compute(): another menu item by its type and object', ID,
  "\t\t\t\t\t\t$target = bin2hex( self::part( array( $type, $of ) ) );", "\t\t\t\t\t\t$target = bin2hex( self::part( array( $type ) ) );", 'ident', ITEM)
e('index-item-no-target', 'compute(): a menu item showing what has no identity has none', ID,
  "\t\t\t\tif ( null === $target ) {\n\t\t\t\t\treturn false;", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn false;", 'ident', ITEM)
e('index-item-top', 'compute(): a menu item at the top under «none»', ID,
  "\t\t\t\t$top          = 0 === $parent ? self::top() : bin2hex( $above );", "\t\t\t\t$top          = bin2hex( $above );", 'ident', ITEM)
e('index-attachment-twin-flag', 'compute(): an attachment of a file twice is a twin', ID,
  "\t\t\t\t\t$n        = $files[ $file ] ?? 0;", "\t\t\t\t\t$n        = 0;", 'ident', ONE)
e('index-item-twin-flag', 'compute(): a menu item of one line twice is a twin', ID,
  "\t\t\t\t\t$n        = $itwins[ $id ] ?? 0;", "\t\t\t\t\t$n        = 0;", 'ident', ITEM)
e('index-post-twin-flag', 'compute(): a post of one identity twice is a twin', ID,
  "\t\t\t\t\t$n        = $twins[ $id ] ?? 0;", "\t\t\t\t\t$n        = 0;", 'ident', ONE)
RC = "\t\t\t$out[] = pack( 'JCn', $id, ( false === $identity ? 0 : self::HAS ) | ( $n > 1 ? self::TWIN : 0 ), $head['lang'] ) . ( false === $identity ? self::counted( $n ) : $identity );"
e('index-record-twin', 'compute(): the record of a post, attachment or menu item twice says twin', ID, RC, RC.replace(' | ( $n > 1 ? self::TWIN : 0 )', ''), 'ident', ONE)
e('index-record-count', 'compute(): and how many objects have its identity (Codex round 5)', ID, RC, RC.replace('self::counted( $n )', 'self::counted( 0 )'), 'ident', COUNT)
e('index-level-count', 'levels(): a twin with how many of its level have its identity (Codex round 5)', ID,
  "\t\t\t\t\t$twins[ $id ] = $count[ $identity ];", "\t\t\t\t\t$twins[ $id ] = 2;", 'ident', COUNT)
e('index-twice-count', 'twice(): each record of a part counted (Codex round 5)', ID,
  "\t\t\t\t$whole[ $part ] = ( $whole[ $part ] ?? 0 ) + 1;", "\t\t\t\t$whole[ $part ] = isset( $whole[ $part ] ) ? 2 : 1;", 'ident', COUNT)
e('index-level-top', 'levels(): the objects under no parent first', ID,
  "\t\t\t\tif ( 0 === $up ) {\n\t\t\t\t\t$made[ $id ] = call_user_func( $make, $id, 0, '' );", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$made[ $id ] = call_user_func( $make, $id, 0, '' );", 'ident', ONE)
e('index-level-final', 'levels(): a parent once it is final', ID,
  "\t\t\t\tif ( null !== $of ) {", "\t\t\t\tif ( true ) {", 'ident', ONE)
e('index-level-unique', 'levels(): an identity another of the level has names none (Codex round 2, A-1)', ID,
  "\t\t\t\tif ( false !== $identity && $count[ $identity ] > 1 ) {", "\t\t\t\tif ( false ) {", 'ident', ONE)
e('index-attachment-not', 'attachment(): no post there, no parent', ID,
  "\t\tif ( null === $at ) {\n\t\t\treturn null;\n\t\t}\n\t\tif ( self::FILE !== ord(", "\t\tif ( false ) {\n\t\t\treturn null;\n\t\t}\n\t\tif ( self::FILE !== ord(", 'ident', 'testAPostUnderAParentThatIsNotThereHasNone')
e('index-attachment-kind', 'attachment(): only an attachment with a file', ID,
  "\t\tif ( self::FILE !== ord( self::$read[ $at + 8 ] ) ) {", "\t\tif ( false ) {", 'ident', ONE)
e('index-attachment-twin', 'attachment(): one of a file twice names none', ID,
  "\t\treturn isset( $twice[ $file ] ) ? false : $file;", "\t\treturn $file;", 'ident', ONE)
e('index-ready', 'ready(): a question builds the index where it is not done', ID,
  "\t\tif ( self::$at < count( self::STEPS ) ) {", "\t\tif ( false ) {", 'ident', ONE)
e('index-ready-context', 'ready(): with the context of the question where the run named none', ID,
  "\t\t\tif ( null === self::$ctx ) {\n\t\t\t\tself::$ctx   = $ctx;", "\t\t\tif ( false ) {\n\t\t\t\tself::$ctx   = $ctx;", 'ident', ITEM)
TR = "\t\t\tif ( ( null === $taxonomy || $tax === (string) $taxonomy ) && ( 0 === $ttid || $head['ttid'] === $ttid ) ) {"
e('index-term-taxonomy', 'term_record(): the term of the taxonomy asked', ID, TR, TR.replace(" || $tax === (string) $taxonomy", ' || true'), 'ident', ONE)
e('index-term-ttid', 'term_record(): the term of the term_taxonomy_id asked', ID, TR, TR.replace(" || $head['ttid'] === $ttid", ' || true'), 'ident', LANG)
e('index-term-one', 'term_record(): a term id in two taxonomies names none by the id alone', ID,
  "\t\treturn 1 === count( $found ) ? $found[0] : null;", "\t\treturn $found[0] ?? null;", 'ident', ONE)
TV = "\t\tif ( 'post' === $kind || 'menu_item' === $kind ) {"
TF = "\t\tif ( null === $record || 0 === ( $record['flags'] & self::TWIN ) ) {"
e('twins-flag', 'twins(): only a record that says twin (Codex round 5)', ID, TF, "\t\tif ( null === $record ) {", 'ident', ONE)
e('twins-count', 'twins(): the count its record holds (Codex round 5)', ID,
  "\t\treturn max( 2, unpack( 'J', $record['identity'] )[1] );", "\t\treturn 2;", 'ident', COUNT)
e('twin-post', 'twin(): a post by its record', ID, TV, TV.replace("'post' === $kind || ", ''), 'ident', ONE)
e('twin-item', 'twin(): a menu item by its record', ID, TV, TV.replace(" || 'menu_item' === $kind", ''), 'ident', ITEM)
TM = "\t\t} elseif ( 'term' === $kind || 'menu' === $kind ) {"
e('twin-term', 'twin(): a term by its taxonomy', ID, TM, TM.replace("'term' === $kind || ", ''), 'ident', ONE)
e('twin-menu', 'twin(): a menu by its taxonomy', ID, TM, TM.replace(" || 'menu' === $kind", ''), 'ident', 'testAnObjectOfTheInventoryAndItsLineSayWhereItIsATwin')
e('identity-menu-target', 'item(): a menu item with the identity of its target', DK,
  "\t\t\t'target'   => null === $whole ? null : $whole[0],", "\t\t\t'target'   => null,", 'it', 'testAMenuSortedAnewOnStagingPairsEachItemWithItsTarget')
e('identity-menu-parent', 'item(): a menu item with the identity of its parent', DK,
  "\t\t\t'parent'   => null === $whole ? null : $whole[1],", "\t\t\t'parent'   => null,", 'it', 'testAMenuSortedAnewOnStagingPairsEachItemWithItsTarget')

# --------------------------------- the index in the inventory and its runs
INX = 'testAnInventoryReadsNoObjectBeforeItsIndexIsDone'
e('inventory-unreadable', 'run(): a table of the index that cannot be read stops the inventory', INV,
  "\t\tif ( is_string( $ready ) ) {", "\t\tif ( false ) {", 'ident', INX)
e('inventory-not-ready', 'run(): no object before the index is done', INV,
  "\t\t$read = false === $ready ? array(", "\t\t$read = false ? array(", 'ident', INX)
IC = "\t\t\t'cursor'  => is_array( $cursor ) && isset( $cursor['kind'] ) ? $cursor : array("
e('inventory-not-ready-cursor', 'run(): the cursor given stays while the index is built', INV, IC, "\t\t\t'cursor'  => false ? $cursor : array(", 'ident', INX)
e('inventory-not-ready-start', 'run(): at the start, the first kind', INV, IC, "\t\t\t'cursor'  => true ? $cursor : array(", 'ident', INX)
e('inventory-index', 'run(): what to keep of the index for the next call', INV,
  "\t\treturn $read + array( 'index' => AB_MCP_Sync_Identity::stored() );", "\t\treturn $read + array( 'index' => null );", 'ident', INX)
e('inventory-index-proposal', 'run(): the finished index kept for the calls after it', INV,
  "\t\treturn $read + array( 'index' => AB_MCP_Sync_Identity::stored() );", "\t\treturn $read + array( 'index' => null );", 'it', 'testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce')
ET = "\t\t$twins = null === self::$ctx ? 0 : AB_MCP_Sync_Identity::twins( $result, self::$ctx );"
TWO = 'testAnObjectOfTheInventoryAndItsLineSayWhereItIsATwin'
e('entry-twin', 'entry(): an object its site names a twin says so (Codex round 4)', INV, "\t\tif ( $twins > 1 ) {", "\t\tif ( false ) {", 'ident', TWO)
e('entry-twin-count', 'entry(): with how many objects of its site have its identity (Codex round 5)', INV,
  "\t\t\t$result['twin'] = $twins;", "\t\t\t$result['twin'] = true;", 'ident', TWO)
e('entry-twin-context', 'entry(): only with the context of a source', INV, ET, "\t\t$twins = AB_MCP_Sync_Identity::twins( $result, self::$ctx );", 'kinds', 'testTheStatusOfAnObjectOfTheInventory')
LT = "\t\tif ( ! empty( $object['twin'] ) ) {\n\t\t\t$line['twin'] = (int) $object['twin'];"
e('line-twin', 'line(): the line of a twin says so (Codex round 4)', EXF, LT, LT.replace("! empty( $object['twin'] )", 'false'), 'ident', TWO)
e('line-twin-count', 'line(): with how many (Codex round 5)', EXF, LT, LT.replace("(int) $object['twin']", 'true'), 'ident', TWO)
e('line-twin-count-it', 'line(): the count of a twin reaches the other site (two sites, Codex round 5)', EXF, LT, LT.replace("(int) $object['twin']", 'true'), 'it', ITD)
e('import-twin', 'LINE_FIELDS: a line may say twin', IM,
  "\t\t'twin'            => 'twins',\n", "", 'it', 'testATwinIsUncertainInAPackageOfItsOwnAndWhatIsLeftAfterAChoiceToo')
TT2 = "\t\t\t\treturn is_int( $value ) && $value > 1;"
e('import-twins-int', 'typed(): twin is a number of objects, not its digits (Codex round 5)', IM, TT2, "\t\t\t\treturn $value > 1;", 'pkg', 'testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain')
e('import-twins-two', 'typed(): twin is two or more (Codex round 5)', IM, TT2, "\t\t\t\treturn is_int( $value );", 'pkg', 'testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain')
e('step-index-take', 'step(): the index the part before kept', P,
  "\t\t\t$index = $last['index'] ?? null;", "\t\t\t$index = null;", 'it', 'testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce')
e('step-index-keep', 'step(): the index as this call left it, kept with its part', P,
  "\t\tif ( null !== $read['index'] ) {", "\t\tif ( false ) {", 'it', 'testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce')
e('step-index-whole', 'step(): the index from all its slices', P,
  "\t\t\t\t$text .= $slice;", "\t\t\t\t$text = $slice;", 'it', 'testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce')
e('step-index-slice', 'step(): the index in slices a file of the store holds', P,
  "\t\t\t\t$slice = (string) substr( $read['index'], $at, AB_MCP_Sync_Identity::slice() );", "\t\t\t\t$slice = (string) $read['index'];", 'it', 'testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce')
EXI = 'testAnExportBuildsTheIndexInPartsAndItsSeriesTakesItUp'
e('export-index-take', 'make(): the index the package before kept', EXF,
  "\t\tif ( null !== $index ) {\n\t\t\t$text = '';", "\t\tif ( false ) {\n\t\t\t$text = '';", 'it', EXI)
e('export-index-gone', 'make(): a series whose index is gone does not go on', EXF,
  "\t\t\t\tif ( is_wp_error( $slice ) ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_export_index'", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_export_index'", 'it', EXI)
e('export-index-whole', 'make(): the index from all its slices', EXF,
  "\t\t\t\t$text .= $slice;", "\t\t\t\t$text = $slice;", 'it', EXI)
e('export-index-keep', 'store(): the index as this call left it, kept with the package', EXF,
  "\t\tif ( null !== $text ) {\n\t\t\t// In slices", "\t\tif ( false ) {\n\t\t\t// In slices", 'it', EXI)
e('export-index-slice', 'store(): the index in slices a file of the store holds', EXF,
  "\t\t\t\t$slice = (string) substr( $text, $at, AB_MCP_Sync_Identity::slice() );", "\t\t\t\t$slice = (string) $text;", 'it', EXI)
e('export-index-declared', 'store(): the package declares the bytes of its index', EXF,
  "\t\t\t\t'declared' => $bytes + self::RECORD_MAX + ( null === $text ? 0 : strlen( $text ) ),", "\t\t\t\t'declared' => $bytes + self::RECORD_MAX,", 'it', 'testAPackageDeclaresTheBytesOfAnIndexOfSomeMegabytes')
e('export-index-record', 'store(): the record names where the index is kept', EXF,
  "\t\t\t\t'index'     => $index,", "\t\t\t\t'index'     => null,", 'it', EXI)
e('export-index-follows', 'follows(): the index of the package before', EXF,
  "return array( $before['cursor'], $before['series'], $before['index'] );", "return array( $before['cursor'], $before['series'], null );", 'it', EXI)

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
e('sel-by-choice', 'plan_confirm(): an ambiguous or uncertain entry needs a choice', T,
  "\t\t\tif ( in_array( $entry['category'], AB_MCP_Sync_Proposal::BY_CHOICE, true ) ) {\n\t\t\t\treturn self::selection( sprintf( 'Entry %1$d is %2$s: it is paired only", "\t\t\tif ( false ) {\n\t\t\t\treturn self::selection( sprintf( 'Entry %1$d is %2$s: it is paired only", 'it', SEL)
e('sel-choice-by-choice', 'plan_confirm(): a choice for an ambiguous or uncertain entry only', T,
  "\t\t\tif ( ! in_array( $entry['category'], AB_MCP_Sync_Proposal::BY_CHOICE, true ) ) {", "\t\t\tif ( false ) {", 'it', SEL)
CE = "\t\t\t$equal = $s['complete'] && $l['complete'] && AB_MCP_Sync_Proposal::mapped_same( $s, $l );"
e('choice-equal', 'plan_confirm(): a chosen pair of the same mapped form is active', T, CE, "\t\t\t$equal = false;", 'it', SEL)
e('choice-unequal', 'plan_confirm(): a chosen pair of other content is unclear', T, CE, "\t\t\t$equal = true;", 'it', SEL)
e('live-bound', 'plan_confirm(): the state binds the objects of this site read fresh', T,
  "\t\t\t$op['live_now'] = $live[ $op['kind'] . \"\\0\" . $op['live_ref'] ];", "\t\t\t$op['live_now'] = 'x';", 'it', 'testTheTokenCoversTheObjectsOfThisSiteAsTheyAreWhenConfirmed')
e('live-gone', 'plan_confirm(): an object gone since the proposal is skipped', T,
  "$now            = 'gone' === $op['live_now'] ? 'the object of this site is gone since the proposal' : self::now( $source, $op );", "$now            = self::now( $source, $op );", 'it', 'testTheTokenCoversTheObjectsOfThisSiteAsTheyAreWhenConfirmed')
e('live-changed-shown', 'named(): the preview says an object changed since the proposal', T,
  "\t\t\t$op['changed_since_proposal'] = $op['live_now'] !== $op['base_live'];", "\t\t\t$op['changed_since_proposal'] = false;", 'it', 'testTheTokenCoversTheObjectsOfThisSiteAsTheyAreWhenConfirmed')
e('state-connection', 'plan(): the state binds the connection', T,
  "\t\t\t'connection' => AB_MCP_Sync_Proposal::connection(),\n\t\t\t'ops'", "\t\t\t'ops'", 'it', 'testEachPartOfTheStateOfADiscardBindsItsToken')
e('state-print', 'plan(): the state binds the print of the pair of sites', T,
  "\t\t\t'print'      => (string) AB_MCP_Sync_Anchor::pair_print(),\n\t\t);\n\t\treturn $plan;", "\t\t);\n\t\treturn $plan;", 'it', 'testEachPartOfTheStateOfADiscardBindsItsToken')
e('confirm-transaction', 'confirm(): one transaction, nothing kept where a pair fails', T,
  "\t\t$done = AB_MCP_Sync_Pairs::transaction(\n\t\t\tstatic function () use ( $plan, &$out ) {", "\t\t$done = call_user_func(\n\t\t\tstatic function () use ( $plan, &$out ) {", 'it', 'testAConfirmationThatCannotWriteAPairKeepsNothing')
e('confirm-transaction-read', 'confirm(): nothing kept where the read after an insert fails', T,
  "\t\t$done = AB_MCP_Sync_Pairs::transaction(\n\t\t\tstatic function () use ( $plan, &$out ) {", "\t\t$done = call_user_func(\n\t\t\tstatic function () use ( $plan, &$out ) {", 'it', 'testAConfirmationWhoseReadBackFailsAfterItsInsertKeepsNothing')
e('discard-transaction', 'discard(): one transaction, nothing ended where a pair fails', T,
  "\t\t$done = AB_MCP_Sync_Pairs::transaction(\n\t\t\tstatic function () use ( $plan ) {", "\t\t$done = call_user_func(\n\t\t\tstatic function () use ( $plan ) {", 'it', 'testADiscardThatCannotEndAPairEndsNone')
PA = 'includes/sync/class-sync-pairs.php'
e('tx-engine', 'transaction(): only on a table that keeps transactions', PA,
  "\t\tif ( ! is_string( $engine ) || 0 !== strcasecmp( $engine, 'InnoDB' ) ) {", "\t\tif ( false ) {", 'it', 'testATableWithoutTransactionsTakesNoPairs')
e('tx-rollback', 'transaction(): an error rolls back', PA,
  "\t\tif ( is_wp_error( $result ) ) {\n\t\t\t$wpdb->query( 'ROLLBACK' );", "\t\tif ( is_wp_error( $result ) ) {\n\t\t\t$wpdb->query( 'COMMIT' );", 'it', 'testAConfirmationThatCannotWriteAPairKeepsNothing')
e('tx-begin', 'transaction(): it begins one', PA,
  "\t\tif ( false === $wpdb->query( 'START TRANSACTION' ) ) {", "\t\tif ( false === $wpdb->query( 'SELECT 1' ) ) {", 'it', 'testAConfirmationThatCannotWriteAPairKeepsNothing')
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
  "\t\tif ( ! is_string( $package_id ) || ! preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_package', 'A package is named",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_package', 'A package is named", 'tool', TOOL_IDS)
e('taken-unknown', 'taken(): a package this account took over only', IM,
  "\t\tif ( null === $found ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_unknown', sprintf(", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_unknown', sprintf(", 'it', 'testOnlyAPackageTakenOverCompletelyIsRead')
e('taken-purpose', 'taken(): a package held for the inventory only', IM,
  "\t\tif ( ! is_array( $expect ) || ! isset( $expect['purpose'] ) || AB_MCP_Sync_Export::PURPOSES[0] !== $expect['purpose'] ) {", "\t\tif ( ! is_array( $expect ) ) {", 'it', 'testTakenReadsOnlyAPackageOfTheInventoryWithItsChunksAsSigned')
e('taken-bound', 'taken(): a package of the pair of sites as it is now', IM,
  "\t\tif ( true !== self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_pair_changed', sprintf(", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_import_pair_changed', sprintf(", 'it', 'testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired')
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
 * The clauses of the object pairs of the takeover (Z8, branch 6 of the plan,
 * part 1: decisions 10, 13 and 15): wp_site_pairs proposes pairs from the
 * inventory of the staging site and of this site (the packages of one
 * series, complete only where one package read a kind whole; the inventory of
 * this site in parts; a name holding a credential by the SHA-256 of its ref
 * only, an object whose name is a credential counted), matches by kind,
 * identity, path and hash, works out the canon change of active pairs, and
 * confirms, discards and lists with the token of the confirmation in the same
 * call, for the account and connection that made the proposal, all or
 * nothing. Codex round 1: equality of the mapped forms (every reference
 * named by the identity of its target, AB_MCP_Sync_Identity), no pair by
 * exclusion (another path of parents, no slug, a menu item without the
 * identity of its target or parent: uncertain), a menu item by its target
 * and parent, never its position, held lines reduced before they are stored
 * or shown, one transaction of the database per call, the token over the
 * objects of this site read fresh, the connection and the print. Codex round
 * 4: the compact index of identities, built in parts and once per run
 * (AB_MCP_Sync_Identity, the inventory, the proposal and the export series),
 * each line of an identity a site has twice uncertain (twin, also alone in
 * its package or after a choice), each parent of a path by its whole
 * identity. Codex round 5: twins paired by an equal mapped form there once on
 * each side, only where every twin of both sites is at hand (twin says how
 * many its site has); the rest, twins of one form and menu items uncertain.
 *
 * Written by tests/mutation/gen/bericht.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncProposalTest, SyncPairsToolTest, SyncPackageTest,
 * SyncIdentityTest, SyncDbKindsTest) and, for what
 * two WordPress sites show, by the integration suite (SyncProposalTest,
 * SyncPackageTest; the sites running, AB_IT_ROOT set). Not listed: the type
 * guards after a read against the SHA-256 of what this code wrote itself (no
 * input reaches them; the structure of a stored index of identities as well,
 * and the floor of two of twins()), splitting into files and parts, which
 * changes no outcome, what only keeps a form that is none out of the counts of
 * unique_pairs() (null is no key; the counts hold strings only), and what only
 * lets memory go (the index after a run, the objects before a package is
 * packed, the prefix count of twice(); measured instead, GRENZEN-BERICHT.md).
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
$pa = 'includes/sync/class-sync-pairs.php';
$id = 'includes/sync/class-sync-identity.php';
$inv = 'includes/sync/class-sync-inventory.php';
$ex = 'includes/sync/class-sync-export.php';
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
open(os.path.join(ROOT, 'tests/mutation/lists/bericht.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
