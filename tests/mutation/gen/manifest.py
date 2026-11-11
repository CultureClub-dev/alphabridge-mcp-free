#!/usr/bin/env python3
"""Writes tests/mutation/lists/manifest.php (Z8b branch 2): one entry per
promise of plan 3.1 with branch 2 (Z1, Z2, Z35, Z38) at its place and per
clause of the new code. Every search is checked to occur exactly once in its
file and the test of its expect to be a method of the class its run names.

    python3 tests/mutation/gen/manifest.py
"""
import os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
FM = 'includes/sync/class-sync-file-manifest.php'
EN = 'includes/sync/class-sync-env.php'
DE = 'includes/sync/class-sync-decision.php'
PA = 'includes/sync/class-sync-pairs.php'
CC = 'includes/sync/class-sync-core-compare.php'
KI = 'includes/sync/class-sync-kinds.php'
VAR = {FM: '$fm', EN: '$en', DE: '$de', PA: '$pa', CC: '$cc', KI: '$ki'}
CLASSES = {'guard': ('unit', 'SyncManifestGuardTest'), 'man': ('unit', 'SyncFileManifestTest'), 'env': ('unit', 'SyncEnvTest'), 'core': ('unit', 'SyncCoreCompareTest'),
           'it': ('it', 'SyncManifestTest'), 'itenv': ('it', 'SyncEnvTest')}
entries = []

def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('manifest/' + id_, clause, file, search, replace, run, expect))

T = "\t"
# ------------------------------------------------ Z1: unit(), the one place
e('z1-only-whole', 'Z1 unit(): no hash where the reading ended before every file', FM,
  "\t\tif ( 'done' !== $went ) {\n\t\t\treturn $acc;\n\t\t}", "\t\tif ( false ) {\n\t\t\treturn $acc;\n\t\t}", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-format', 'Z1 text(): the name of the format in the text of the manifest', FM,
  "\t\t\t\t'format' => self::FORMAT,", '', 'man', 'testTheHashIsTheSha256OfTheManifestOfEveryFileInTheFormatAbFiles1')
e('z1-sort', 'Z1 unit(): the files by strcmp() of the whole path', FM,
  "\t\tusort(\n\t\t\t$acc['entries'],", "\t\tarray_reverse(\n\t\t\t$acc['entries'],", 'man', 'testTheOrderIsStrcmpOfTheWholePathNotTheOrderOfTheWalk')
e('z1-no-mtime', 'Z1 text(): mtime never in the hash', FM,
  "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );", "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2], (int) $entry[3] );", 'man', 'testMtimeNeverCountsForEquality')
e('z1-size', 'Z1 text(): the size of each file in the hash', FM,
  "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );", "\t\t\t$files[] = array( (string) $entry[0], (string) $entry[2] );", 'man', 'testTheHashIsTheSha256OfTheManifestOfEveryFileInTheFormatAbFiles1')
e('z1-every-file', 'Z1 folder(): every file of every folder, a written log included (no list of names left out)', FM,
  "\t\t\t\t$went = self::folder( $path, $in, $side, $deadline, $first, $acc );", "\t\t\t\t$went = 'done';", 'man', 'testEveryFileCountsOneMoreLessOtherBytesOrAnotherNameIsAnotherHash')
e('z1-inode', 'Z1 file(): the file hashed is the one lstat() saw (device and inode)', FM,
  "\t\tif ( false === $before || (int) $before['dev'] !== (int) $stat['dev'] || (int) $before['ino'] !== (int) $stat['ino'] ) {", "\t\tif ( false === $before ) {", 'man', 'testAWriterWhileTheFileIsReadGivesNoHash')
e('z1-read-size', 'Z1 file(): as many bytes as the file has (halb gelesen)', FM,
  "\t\tif ( $read !== (int) $before['size'] || (int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {", "\t\tif ( (int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {", 'man', 'testAReadingCutShortGivesNoHash')
e('z1-after-size', 'Z1 file(): the size after the reading the size before (a writer)', FM,
  "\t\tif ( $read !== (int) $before['size'] || (int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {", "\t\tif ( $read !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {", 'man', 'testAWriterWhileTheFileIsReadGivesNoHash')
e('z1-after-mtime', 'Z1 file(): the time after the reading the time before (a writer)', FM,
  "\t\tif ( $read !== (int) $before['size'] || (int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {", "\t\tif ( $read !== (int) $before['size'] || (int) $after['size'] !== (int) $before['size'] ) {", 'man', 'testAWriterWhileTheFileIsReadGivesNoHash')
e('z1-lstat-gone', 'Z1 folder(): an entry gone or unreadable since the listing: no hash', FM,
  "\t\t\tif ( false === $stat ) {\n\t\t\t\t// Gone since the folder was listed, or not readable.", "\t\t\tif ( false ) {\n\t\t\t\t// Gone since the folder was listed, or not readable.", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-fstat-after', 'Z1 file(): fstat() after the reading that fails: no hash', FM,
  "\t\tif ( false === $after ) {\n\t\t\treturn self::end( $acc, 'unreadable' );", "\t\tif ( false ) {\n\t\t\treturn self::end( $acc, 'unreadable' );", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-chunk-clock', 'Z1 file(): the time asked after each part of a file', FM,
  "\t\t\tif ( self::now() >= $deadline ) {\n\t\t\t\t$calls->close( $handle );\n\t\t\t\treturn self::late( $acc, $first );", "\t\t\tif ( false ) {\n\t\t\t\t$calls->close( $handle );\n\t\t\t\treturn self::late( $acc, $first );", 'man', 'testTheTimeOfTheCallIsAskedWithinALargeFileToo')
e('z1-too-large', 'Z1 late(): too large where it had a whole call', FM,
  "\t\tif ( $first ) {\n\t\t\treturn self::end( $acc, 'too_large' );", "\t\tif ( false ) {\n\t\t\treturn self::end( $acc, 'too_large' );", 'man', 'testATimeLimitWithinAUnitStopsItOrMakesItTooLarge')
e('z1-stopped', 'Z1 late(): begun late, stopped for the next call', FM,
  "\t\t$acc['stopped'] = true;", '', 'man', 'testATimeLimitWithinAUnitStopsItOrMakesItTooLarge')
e('z1-too-many', 'Z1 file(): no more files than a manifest holds', FM,
  "\t\tif ( $acc['files'] >= (int) $calls->limit() ) {", "\t\tif ( $acc['files'] > (int) $calls->limit() ) {", 'man', 'testMoreFilesThanAManifestHoldsGiveNoHash')
e('z1-paths-only-live', 'paths(): a path only on this site', FM,
  "\t\t\t\t$out['only_live'][] = (string) $path;", '', 'man', 'testThePathsOfTwoManifestsDifferOnlyByContentsNeverByMtime')
e('z1-paths-differs', 'paths(): a path on both sides with other contents, never by mtime', FM,
  "\t\t\t} elseif ( $l[ $path ] !== $one ) {", "\t\t\t} elseif ( false ) {", 'man', 'testThePathsOfTwoManifestsDifferOnlyByContentsNeverByMtime')
e('z1-guard', 'Z1 guard: the line of a unit takes no hash but the one of unit()', EN,
  "\t\t\t'hash'     => $read['hash'],", "\t\t\t'hash'     => null === $read['hash'] ? null : hash( 'sha256', $name ),", 'guard', 'testOnlyTheManifestWritesTheTextOfAManifestAndHashesAUnit')
# ------------------------------------------------ Z2: what no manifest holds
e('z2-vcs', 'Z2 folder(): a folder of version control anywhere locks the unit', FM,
  "\t\t\t\tif ( in_array( $name, self::VCS, true ) ) {", "\t\t\t\tif ( false ) {", 'man', 'testAFolderOfVersionControlAnywhereLocksTheUnitWithoutAHash')
e('z2-link-inside', 'Z2 folder(): a link in the unit locks it', FM,
  "\t\t\tif ( 0120000 === $type ) {\n\t\t\t\treturn self::locked( $acc, $side, 'link' );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( $acc, $side, 'link' );", 'man', 'testALinkInTheUnitOrTheUnitAsALinkLocksIt')
e('z2-link-unit', 'Z2 unit(): the unit itself a link is not followed', FM,
  "\t\t\tif ( 0040000 === $type ) {\n\t\t\t\t$acc['form'] = 'folder';", "\t\t\tif ( 0040000 === $type || 0120000 === $type ) {\n\t\t\t\t$acc['form'] = 'folder';", 'man', 'testALinkInTheUnitOrTheUnitAsALinkLocksIt')
e('z2-nonfile', 'Z2 folder(): what is no regular file locks the unit', FM,
  "\t\t\tif ( 0100000 !== $type ) {\n\t\t\t\treturn self::locked( $acc, $side, 'nonfile' );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( $acc, $side, 'nonfile' );", 'man', 'testSomethingThatIsNoRegularFileLocksIt')
e('z2-side', 'Z2 locked(): code:live_extra on the production site, code:link on staging', FM,
  "\t\t$acc['lock'] = self::LOCK_BY_SIDE[ 'live' === $side ? 'live' : 'staging' ];", "\t\t$acc['lock'] = self::LOCK_BY_SIDE['staging'];", 'man', 'testAFolderOfVersionControlAnywhereLocksTheUnitWithoutAHash')
e('z2-decision-lock', 'Z2 unit_item(): a line locked by what it holds is locked, never compared', DE,
  "\t\tif ( array() !== $locks ) {\n\t\t\t$entry['action'] = 'locked';\n\t\t\treturn self::unit_entry( $entry, $locks, null, $held );", "\t\tif ( false ) {\n\t\t\t$entry['action'] = 'locked';\n\t\t\treturn self::unit_entry( $entry, $locks, null, $held );", 'env', 'testAUnitThatHoldsWhatNoManifestHoldsIsLockedNeverCompared')
e('z2-shape-hash', 'Z2 unit_shaped(): a line with a lock or not read whole carries no hash', EN,
  "\t\tif ( null !== $line['hash'] && ( ! $line['complete'] || null !== $line['lock'] || ! in_array( $line['form'], array( 'folder', 'file' ), true ) ) ) {", "\t\tif ( false ) {", 'env', 'testALineWithAFieldMissingTooManyOrOfAnotherTypeIsNoLine')
# --------------------------------------------- the decision of a unit (E1, E2)
e('unit-own', 'E12 unit_item(): AlphaBridge itself shown by its version, never taken over', DE,
  "\t\tif ( ! empty( $s['own'] ) || ! empty( $l['own'] ) ) {", "\t\tif ( false ) {", 'env', 'testAlphaBridgeItselfIsShownByItsVersionNeverTakenOver')
e('unit-whole', 'unit_item(): a unit not read whole on a side is not compared', DE,
  "\t\t\tif ( 'theme_choice' !== $kind && ( empty( $line['complete'] ) || null === ( $line['hash'] ?? null ) ) ) {", "\t\t\tif ( false ) {", 'env', 'testAUnitNotReadWholeIsNotComparedWithWhy')
e('unit-equal', 'unit_item(): without a pair equal only where every part is the same', DE,
  "\t\t\t$entry['result'] = array() === $differs ? 'equal' : 'differs';", "\t\t\t$entry['result'] = 'equal';", 'env', 'testAPluginWhoseFilesOrMainFilesRunningDifferDiffersWithThePart')
e('unit-activation', 'unit_item(): which main files run is a part of a plugin (E2)', DE,
  "\t\t$active = array_map( 'strval', $line['active'] );", "\t\t$active = array();", 'env', 'testAPluginWhoseFilesOrMainFilesRunningDifferDiffersWithThePart')
e('unit-live-changed', 'E1 unit_item(): the files changed on both sides: code:live_changed, no choice', DE,
  "\t\tif ( isset( $states['files'] ) && 'conflict' === $states['files'] ) {", "\t\tif ( false ) {", 'env', 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase')
e('unit-part-base', 'E2 unit_item(): each part against its own base', DE,
  "\t\t\t\t$base = AB_MCP_Sync_Pairs::part_bases( $pair, $part );", "\t\t\t\t$base = AB_MCP_Sync_Pairs::part_bases( $pair, 'files' );", 'env', 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase')
e('unit-paths', 'unit_item(): the paths where the files differ', DE,
  "\t\tif ( in_array( 'files', $differs, true ) ) {", "\t\tif ( false ) {", 'env', 'testThePathsOfAUnitWhoseFilesDifferAreShownNeverByMtime')
e('unit-pairs', 'compare(): a unit with its pair', EN,
  "\t\t\t\t$by_pair[ $pair['kind'] ][ (string) $pair['staging_ref'] ] = $pair;", '', 'env', 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase')
e('unit-entries-whole', 'entries_of(): the paths only where every file of a side is at hand', EN,
  "\t\t\t$out[ $unit ] = null !== $files && is_array( $line ) && ! isset( $line['held'] ) && count( $files ) === (int) $line['files'] ? $files : null;", "\t\t\t$out[ $unit ] = null !== $files && is_array( $line ) && ! isset( $line['held'] ) ? $files : null;", 'env', 'testThePathsOfAUnitWhoseFilesDifferAreShownNeverByMtime')
e('unit-first-time', 'read(): the first unit of a call has time of its own (every call moves on)', EN,
  "\t\t\t$read = self::read_unit( $kind, $dir, $name, $flag, $side, $alone ? max( (float) $deadline, self::now() + self::UNIT_BUDGET ) : $deadline, $alone );", "\t\t\t$read = self::read_unit( $kind, $dir, $name, $flag, $side, $deadline, $alone );", 'itenv', 'testTheUploadsAreCountedOverPartsOutsideTheFileListsOfAttachments')
# ------------------------------------------------ Z35: the core
e('z35-https', 'Z35 checksums(): only https', CC,
  "\tconst API = 'https://api.wordpress.org/core/checksums/1.0/';", "\tconst API = 'http://api.wordpress.org/core/checksums/1.0/';", 'core', 'testTheChecksumsAreAskedOverHttpsThroughTheOutboundWithoutARedirect')
e('z35-no-redirect', 'Z35 checksums(): no redirect followed', CC,
  "\t\t\t\t'redirection' => 0,", "\t\t\t\t'redirection' => 3,", 'core', 'testTheChecksumsAreAskedOverHttpsThroughTheOutboundWithoutARedirect')
e('z35-outbound', 'Z35 the real call: AB_MCP_Outbound', CC,
  "\t\treturn AB_MCP_Outbound::get( (string) $url, $args );", "\t\treturn wp_remote_get( (string) $url, $args );", 'core', 'testTheChecksumsAreAskedOverHttpsThroughTheOutboundWithoutARedirect')
e('z35-no-kind', 'Z35 OWNERSHIP: no kind for the core', KI,
  "\t\t\t'kind'   => null,\n\t\t\t'art'    => 'A17',", "\t\t\t'kind'   => 'core_writer',\n\t\t\t'art'    => 'A17',", 'core', 'testNoKindCanClaimTheCore')
e('core-status', 'checksums(): another status is no answer', CC,
  "\t\tif ( 200 !== $status || ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY ) {", "\t\tif ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY ) {", 'core', 'testAnAnswerThatIsNotTheChecksumsIsRefused')
e('core-max', 'checksums(): an answer too large is none', CC,
  "\t\tif ( 200 !== $status || ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY ) {", "\t\tif ( 200 !== $status || ! is_string( $body ) || '' === $body ) {", 'core', 'testAnAnswerThatIsNotTheChecksumsIsRefused')
e('core-unknown', 'checksums(): a version the service does not know', CC,
  "\t\tif ( false === $data['checksums'] ) {", "\t\tif ( false ) {", 'core', 'testTheStoredAnswerHasTheFormThisClassReads')
e('core-relative', 'checksums(): a path of the answer is a plain relative path', CC,
  "\t\t\tif ( ! is_string( $path ) || ! self::relative( $path ) || ! is_string( $md5 ) || 1 !== preg_match( '/^[0-9a-f]{32}\\z/', $md5 ) ) {", "\t\t\tif ( ! is_string( $path ) || ! is_string( $md5 ) || 1 !== preg_match( '/^[0-9a-f]{32}\\z/', $md5 ) ) {", 'core', 'testAnAnswerThatIsNotTheChecksumsIsRefused')
e('core-md5', 'checksums(): an md5 of 32 hex', CC,
  "\t\t\tif ( ! is_string( $path ) || ! self::relative( $path ) || ! is_string( $md5 ) || 1 !== preg_match( '/^[0-9a-f]{32}\\z/', $md5 ) ) {", "\t\t\tif ( ! is_string( $path ) || ! self::relative( $path ) || ! is_string( $md5 ) ) {", 'core', 'testAnAnswerThatIsNotTheChecksumsIsRefused')
e('core-not-wp-content', 'check(): the files of wp-content are no core', CC,
  "\t\t\tif ( 0 === strpos( $path, self::NOT_CORE ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'core', 'testTheCoreFilesAsShippedAreEqualWithoutWpContent')
e('core-missing', 'check(): a core file not there is missing', CC,
  "\t\t\tif ( ! $calls->exists( $file ) ) {", "\t\t\tif ( false ) {", 'core', 'testAChangedOrMissingCoreFileDiffersByItsCount')
e('core-differs', 'check(): a core file of other contents differs', CC,
  "\t\t\tif ( ! is_string( $have ) || ! hash_equals( $md5, $have ) ) {", "\t\t\tif ( ! is_string( $have ) ) {", 'core', 'testAChangedOrMissingCoreFileDiffersByItsCount')
e('core-clock', 'check(): the time of the call over, no line', CC,
  "\t\t\tif ( 0 === ( ++$tick % 64 ) && self::now() >= (float) $deadline ) {", "\t\t\tif ( false ) {", 'core', 'testTheTimeOfTheCallOverLeavesNoLine')
e('core-locale', 'check(): the language of the package of WordPress', CC,
  "\t\t$locale = isset( $GLOBALS['wp_local_package'] ) && is_string( $GLOBALS['wp_local_package'] ) && '' !== $GLOBALS['wp_local_package'] ? $GLOBALS['wp_local_package'] : 'en_US';", "\t\t$locale = 'en_US';", 'core', 'testTheLanguageOfThePackageOfWordPressIsAsked')
# ------------------------------------------------ Z38: rebase() by its part
e('z38-owner', 'Z38 rebase(): only the kind responsible for the part', PA,
  "\t\tif ( ! self::responsible( $kind, (string) $raw['kind'], (string) $part ) ) {", "\t\tif ( false ) {", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-kind-of-part', 'Z38 responsible(): the kind the table names for the part', PA,
  "\t\treturn 'z8' === $answer['status'] && $answer['kind'] === $kind->kind()", "\t\treturn 'z8' === $answer['status']", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-registered', 'Z38 responsible(): the very engine registered under its name', PA,
  "\t\t\t&& AB_MCP_Sync_Kinds::get( $kind->kind() ) === $kind;", "\t\t\t&& null !== AB_MCP_Sync_Kinds::get( $kind->kind() );", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-part-of-kind', 'Z38 rebase(): a part the pair of the unit has', PA,
  "\t\tif ( null === $parts || ! in_array( $part, $parts, true ) ) {\n\t\t\treturn new WP_Error(", "\t\tif ( null === $parts ) {\n\t\t\treturn new WP_Error(", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-object', 'Z38 rebase(): an object of Z8 takes no part', PA,
  "\t\tif ( null === $parts && null === $part ) {", "\t\tif ( null === $parts ) {", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-further', 'Z38 rebase(): a further part in base_extra, the columns as they are', PA,
  "\t\tif ( $parts[0] !== $part ) {", "\t\tif ( false ) {", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-keep-columns', 'Z38 rebase(): the bases of the first part stay for a further one', PA,
  "\t\t\tunset( $set['base_live'], $set['base_staging'] );", '', 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-bases-part', 'Z38 part_bases(): a further part from base_extra', PA,
  "\t\t\t: ( $pair['base_extra']['parts'][ $part ] ?? null );", "\t\t\t: array( 'live' => $pair['base_live'] ?? null, 'staging' => $pair['base_staging'] ?? null );", 'it', 'testRebaseMovesOnOnlyThePartOfTheKindResponsibleForIt')
e('z38-db-write', 'Z38 swap(): a write that fails writes nothing and says so', PA,
  "\t\t$done = self::calls()->query( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );\n\t\tif ( false === $done ) {\n\t\t\treturn self::db_error();", "\t\t$done = self::calls()->query( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );\n\t\tif ( false ) {\n\t\t\treturn self::db_error();", 'it', 'testAFaultOfTheDatabaseLeavesTheBasesAsTheyWere')
e('z38-db-read', 'Z38 raw(): a read that fails is an error', PA,
  "\t\tif ( false === $row ) {\n\t\t\treturn self::db_error();", "\t\tif ( false ) {\n\t\t\treturn self::db_error();", 'it', 'testAFaultOfTheDatabaseLeavesTheBasesAsTheyWere')

# ---------------------------------------------------------------- write
errors = []
src = {}
for (id_, clause, file, search, replace, run, expect) in entries:
    if file not in src:
        src[file] = open(os.path.join(ROOT, file)).read()
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

out = ['<?php', '/**', ' * The clauses of the manifests of code and files (Z8b branch 2: plan 3.1',
       ' * Z1, Z2, Z35, Z38 at their places, the decision of a unit, the core',
       ' * compared, rebase() by its part). Written by tests/mutation/gen/manifest.py',
       ' * from the source; change the entries there and run it again.',
       ' *', ' * Held by the unit suite (SyncFileManifestTest, SyncEnvTest,',
       ' * SyncCoreCompareTest) and, for the rows of the pairs and the reading on a',
       ' * WordPress site, by the integration suite (SyncManifestTest, SyncEnvTest;',
       ' * the sites running, AB_IT_ROOT set).', ' *',
       ' * Not listed, with why (bericht-zweig-2.md): the guard of an empty text of a',
       ' * manifest in unit() (no input makes AB_MCP_Sync_Canon::encode() fail on a',
       " * list of paths, sizes and hashes), the guards of names() and open() and of a",
       ' * read() that fails in file() (a Throwable of the next call, caught by unit(),',
       ' * gives the same answer: unreadable, no hash).', ' *',
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
open(os.path.join(ROOT, 'tests/mutation/lists/manifest.php'), 'w').write('\n'.join(out) + '\n')
print('%d entries' % len(entries))
