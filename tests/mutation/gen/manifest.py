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
  "\t\tif ( 'done' !== $went ) {\n\t\t\treturn $acc;\n\t\t}\n\t\t// Nothing added", "\t\tif ( false ) {\n\t\t\treturn $acc;\n\t\t}\n\t\t// Nothing added", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-still-ended', 'Z1 whole(): no hash where still() ended the reading (changed, unreadable, the time)', FM,
  "\t\tif ( 'done' !== $went ) {\n\t\t\treturn $acc;\n\t\t}\n\t\tusort(", "\t\tif ( false ) {\n\t\t\treturn $acc;\n\t\t}\n\t\tusort(", 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-format', 'Z1 text(): the name of the format in the text of the manifest', FM,
  "\t\t\t\t'format' => self::FORMAT,", '', 'man', 'testTheHashIsTheSha256OfTheManifestOfEveryFileInTheFormatAbFiles1')
e('z1-sort', 'Z1 unit(): the files by strcmp() of the whole path', FM,
  "\t\tusort(\n\t\t\t$acc['entries'],", "\t\tarray_reverse(\n\t\t\t$acc['entries'],", 'man', 'testTheOrderIsStrcmpOfTheWholePathNotTheOrderOfTheWalk')
e('z1-no-mtime', 'Z1 text(): mtime never in the hash', FM,
  "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );", "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2], (int) $entry[3] );", 'man', 'testMtimeNeverCountsForEquality')
e('z1-form', 'Z1 text(): the form of the unit (file or folder) in the hash (GitHub-Codex on 7d0aeea)', FM,
  "\t\t\t\t'form'   => (string) $form,", '', 'man', 'testAFileAndAFolderHoldingTheSameFileAreNeverTheSameUnit')
e('z1-size', 'Z1 text(): the size of each file in the hash', FM,
  "\t\t\t$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );", "\t\t\t$files[] = array( (string) $entry[0], (string) $entry[2] );", 'man', 'testTheHashIsTheSha256OfTheManifestOfEveryFileInTheFormatAbFiles1')
e('z1-every-file', 'Z1 folder(): every file of every folder, a written log included (no list of names left out)', FM,
  "\t\t\t\t$went = self::folder( $path, $in, $side, $deadline, $first, $acc );", "\t\t\t\t$went = 'done';", 'man', 'testEveryFileCountsOneMoreLessOtherBytesOrAnotherNameIsAnotherHash')
e('z1-before-same', 'Z1 file(): the file opened is the one lstat() saw: device, inode, size, mtime, ctime (Behebung 2)', FM,
  "\t\tif ( false === $before || ! self::same( $stat, $before ) ) {", "\t\tif ( false === $before ) {", 'man', 'testEachFieldOfTheIdentityBeforeWhileAndAfterTheReadingCounts')
e('z1-read-size', 'Z1 file(): as many bytes as the file has (halb gelesen)', FM,
  "$read !== (int) $before['size'] || ! self::same( $before, $after )", "! self::same( $before, $after )", 'man', 'testAReadingCutShortGivesNoHash')
e('z1-after-same', 'Z1 file(): fstat() after the reading the same as before (a writer)', FM,
  "$read !== (int) $before['size'] || ! self::same( $before, $after ) ) {", "$read !== (int) $before['size'] ) {", 'man', 'testEachFieldOfTheIdentityBeforeWhileAndAfterTheReadingCounts')
e('z1-still', 'Z1 whole(): the unit as it was read, once every entry is read: nothing added, removed or replaced (GitHub-Codex on ed1e512)', FM,
  "\t\t\t$went = self::still( $acc, (float) $deadline, (bool) $first );", "\t\t\t$went = 'done';", 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-still-names', 'Z1 still(): each folder lists the same names again', FM,
  "\t\t\tif ( $now !== $names ) {", "\t\t\tif ( false ) {", 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-still-files', 'Z1 still(): each file at its path is the one hashed (also after a rename over it; GitHub-Codex P2)', FM,
  "\t\t\tif ( ! self::same( $stat, $calls->lstat( $file ) ) ) {", "\t\t\tif ( false ) {", 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-still-clock-names', 'Z1 still(): the time of the call before each folder is listed again (Behebung 5)', FM,
  "\t\tforeach ( $acc['dirs'] as $dir => $names ) {\n\t\t\tif ( self::due( $acc, $deadline, $first ) ) {", "\t\tforeach ( $acc['dirs'] as $dir => $names ) {\n\t\t\tif ( false ) {", 'man', 'testTheTimeOfTheCallIsAskedInTheFinalCheckOfTheUnitToo')
e('z1-still-clock-files', 'Z1 still(): the time of the call before each path is asked again (Behebung 5)', FM,
  "\t\tforeach ( $acc['stats'] as $file => $stat ) {\n\t\t\tif ( self::due( $acc, $deadline, $first ) ) {", "\t\tforeach ( $acc['stats'] as $file => $stat ) {\n\t\t\tif ( false ) {", 'man', 'testTheTimeOfTheCallIsAskedInTheFinalCheckOfTheUnitToo')
e('z1-release-due', 'Z1 whole(): no hash past the time of the call, asked at its release after the sorting and the hashing (Behebung 6)', FM,
  "\t\tif ( self::due( $acc, $deadline, $first ) ) {\n\t\t\treturn $acc;\n\t\t}\n\t\t$acc['complete'] = true;", "\t\tif ( false ) {\n\t\t\treturn $acc;\n\t\t}\n\t\t$acc['complete'] = true;", 'man', 'testNoHashOnceTheTimeOfTheCallIsOverAlsoAfterTheLastQueryOfTheFinalCheck')
e('z1-grows', 'Z1 file(): a file read past the size it had when opened is changed at once, not read on to its end (GitHub-Codex on e9db02d)', FM,
  "\t\t\tif ( $read > (int) $before['size'] ) {", "\t\t\tif ( false ) {", 'man', 'testAFileThatGrowsWhileItIsReadIsChangedAtOnce')
e('z1-due', 'Z1 due(): the one place the clock is asked while a unit is read (Behebung 5)', FM,
  "\t\tif ( self::now() < (float) $deadline ) {\n\t\t\treturn false;", "\t\tif ( true ) {\n\t\t\treturn false;", 'man', 'testTheTimeOfTheCallIsAskedInTheFinalCheckOfTheUnitToo')
e('z1-still-dirs-kept', 'Z1 folder(): the names of each folder kept for still()', FM,
  "\t\t$acc['dirs'][ $dir ] = $names;", '', 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-still-stats-kept', 'Z1 file(): the identity of each file hashed kept for still()', FM,
  "\t\t$acc['stats'][ $path ] = $before;", '', 'man', 'testAWriterWhileTheFileIsReadGivesNoHash')
e('z1-still-throw', 'Z1 whole(): a call that throws in still() is no answer (unreadable), never a hash', FM,
  "\t\t} catch ( Throwable $e ) {\n\t\t\treturn self::ended( $acc, 'unreadable' );\n\t\t}\n\t\tif ( 'done' !== $went ) {\n\t\t\treturn $acc;\n\t\t}\n\t\tusort(", "\t\t} catch ( Throwable $e ) {\n\t\t\t$went = 'done';\n\t\t}\n\t\tif ( 'done' !== $went ) {\n\t\t\treturn $acc;\n\t\t}\n\t\tusort(", 'man', 'testAFolderOrFileAddedRemovedOrReplacedWhileTheUnitIsReadGivesNoHash')
e('z1-clock-entry', 'Z1 folder(): the time of the call before each entry, also an empty folder (GitHub-Codex on ed1e512)', FM,
  "\t\t\t// The time of the call, before each entry (also an empty folder).\n\t\t\tif ( self::due( $acc, $deadline, $first ) ) {", "\t\t\t// The time of the call, before each entry (also an empty folder).\n\t\t\tif ( false ) {", 'man', 'testTheTimeOfTheCallIsAskedBeforeEachFolderAndFileAlsoEmptyOnes')
e('z1-clock-file', 'Z1 file(): the time of the call before a file is opened, also one of 0 bytes', FM,
  "\t\t$calls = self::calls();\n\t\tif ( self::due( $acc, $deadline, $first ) ) {", "\t\t$calls = self::calls();\n\t\tif ( false ) {", 'man', 'testTheTimeOfTheCallIsAskedBeforeEachFolderAndFileAlsoEmptyOnes')
e('z1-too-many-folder', 'Z1 folder(): each folder counts towards MAX_FILES (GitHub-Codex on ed1e512)', FM,
  "\t\t\t\tif ( ++$acc['walked'] > (int) self::calls()->limit() ) {", "\t\t\t\tif ( false ) {", 'man', 'testMoreFilesThanAManifestHoldsGiveNoHash')
for field in ('dev', 'ino', 'size', 'mtime', 'ctime'):
    e('z1-identity-' + field, 'Z1 IDENTITY: ' + field + ' counts in every comparison of the file (Behebung 2)', FM,
      "\tconst IDENTITY = array( 'dev', 'ino', 'size', 'mtime', 'ctime' );", "\tconst IDENTITY = array( " + ', '.join("'" + f + "'" for f in ('dev', 'ino', 'size', 'mtime', 'ctime') if f != field) + " );", 'man', 'testEachFieldOfTheIdentityBeforeWhileAndAfterTheReadingCounts')
e('z1-lstat-gone', 'Z1 folder(): an entry gone or unreadable since the listing: no hash', FM,
  "\t\t\tif ( false === $stat ) {\n\t\t\t\t// Gone since the folder was listed, or not readable.", "\t\t\tif ( false ) {\n\t\t\t\t// Gone since the folder was listed, or not readable.", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-fstat-after', 'Z1 file(): fstat() after the reading that fails: no hash', FM,
  "\t\tif ( false === $after ) {\n\t\t\treturn self::end( $acc, 'unreadable' );", "\t\tif ( false ) {\n\t\t\treturn self::end( $acc, 'unreadable' );", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-read-false', 'Z1 file(): a read() that fails is no hash, also where the next read() gives bytes (Codex round 1, B7)', FM,
  "\t\t\tif ( false === $bytes ) {\n\t\t\t\t$calls->close( $handle );\n\t\t\t\treturn self::end( $acc, 'unreadable' );", "\t\t\tif ( false ) {\n\t\t\t\t$calls->close( $handle );\n\t\t\t\treturn self::end( $acc, 'unreadable' );", 'man', 'testAReadingThatFailsGivesNoHash')
e('z1-chunk-clock', 'Z1 file(): the time asked after each part of a file', FM,
  "\t\t\tif ( $read < (int) $before['size'] && self::due( $acc, $deadline, $first ) ) {", "\t\t\tif ( false ) {", 'man', 'testTheTimeOfTheCallIsAskedWithinALargeFileToo')
e('z1-chunk-not-last', 'Z1 file(): no clock after the last part of a file; the final check asks (Behebung 5)', FM,
  "\t\t\tif ( $read < (int) $before['size'] && self::due( $acc, $deadline, $first ) ) {", "\t\t\tif ( self::due( $acc, $deadline, $first ) ) {", 'man', 'testTheTimeOfTheCallIsAskedWithinALargeFileToo')
e('z1-too-large', 'Z1 late(): too large where it had a whole call', FM,
  "\t\tif ( $first ) {\n\t\t\treturn self::end( $acc, 'too_large' );", "\t\tif ( false ) {\n\t\t\treturn self::end( $acc, 'too_large' );", 'man', 'testATimeLimitWithinAUnitStopsItOrMakesItTooLarge')
e('z1-stopped', 'Z1 late(): begun late, stopped for the next call', FM,
  "\t\t$acc['stopped'] = true;", '', 'man', 'testATimeLimitWithinAUnitStopsItOrMakesItTooLarge')
e('z1-too-many', 'Z1 file(): no more files and folders than a manifest holds (a file counts)', FM,
  "\t\tif ( ++$acc['walked'] > (int) $calls->limit() ) {", "\t\tif ( ++$acc['walked'] > 1 + (int) $calls->limit() ) {", 'man', 'testMoreFilesThanAManifestHoldsGiveNoHash')
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
e('z2-nonfile', 'Z2 folder(): what is no regular file locks the unit (the lock, not only no hash; a FIFO is never opened)', FM,
  "\t\t\tif ( 0100000 !== $type ) {\n\t\t\t\treturn self::locked( $acc, $side, 'nonfile' );", "\t\t\tif ( 0100000 !== $type ) {\n\t\t\t\treturn self::end( $acc, 'unreadable' );", 'man', 'testSomethingThatIsNoRegularFileLocksIt')
e('z2-side', 'Z2 locked(): code:live_extra on the production site, code:link on staging', FM,
  "\t\t$acc['lock'] = self::LOCK_BY_SIDE[ 'live' === $side ? 'live' : 'staging' ];", "\t\t$acc['lock'] = self::LOCK_BY_SIDE['staging'];", 'man', 'testAFolderOfVersionControlAnywhereLocksTheUnitWithoutAHash')
VCS_SELF = "\t\t\t\t$went = in_array( basename( (string) $name ), self::VCS, true ) ? self::locked( $acc, $side, 'vcs' ) : self::folder( $path, '', $side, (float) $deadline, (bool) $first, $acc );"
e('z2-vcs-self', 'Z2 unit(): the folder of the unit itself of version control locks it (Codex round 1, A1)', FM,
  VCS_SELF, "\t\t\t\t$went = self::folder( $path, '', $side, (float) $deadline, (bool) $first, $acc );", 'man', 'testTheFolderOfTheUnitItselfOfVersionControlLocksIt')
for vcs in ('.git', '.svn', '.hg'):
    e('z2-vcs-self' + vcs.replace('.', '-'), 'Z2 unit(): the folder of the unit itself named ' + vcs + ' locks it (A1)', FM,
      VCS_SELF, VCS_SELF.replace('in_array( basename( (string) $name ), self::VCS, true )', "in_array( basename( (string) $name ), array_diff( self::VCS, array( '" + vcs + "' ) ), true )"), 'man', 'testTheFolderOfTheUnitItselfOfVersionControlLocksIt')
e('z2-vcs-nested', 'Z2 candidates(): a folder of version control below a root of files is a unit, which unit() locks (A1)', EN,
  "\t\t\t\treturn in_array( $name, AB_MCP_Sync_File_Manifest::VCS, true ) ? 'unit' : 'into';", "\t\t\t\treturn 'into';", 'it', 'testTheRowsOfTheSeriesThatFailOrChangeWhileReadAreNeverComparedHalf')
e('z2-theme-root', 'Z2 unit(): a theme only from the folder of the themes, else code:theme_root and not read (Codex round 1, B1)', FM,
  "\t\t\tif ( 'theme' === $kind && ( null === self::theme_root() || rtrim( (string) $base, '/' ) !== rtrim( (string) self::theme_root(), '/' ) ) ) {", "\t\t\tif ( false ) {", 'man', 'testAThemeIsReadOnlyFromTheFolderOfTheThemes')
e('z2-theme-root-kind', 'Z2 theme_roots(): the themes of a further root go through unit() as themes (B1)', EN,
  "AB_MCP_Sync_File_Manifest::unit( $root, (string) $stylesheet, $side, $deadline, false, 'theme' );", "AB_MCP_Sync_File_Manifest::unit( $root, (string) $stylesheet, $side, $deadline, false, 'plugin' );", 'guard', 'testTheLocksOfWhatNoManifestHoldsAndOfAFurtherRootOfThemesAreSetInUnitOnly')
e('z2-lock-from-unit', 'Z2 unit_lines(): the line of a unit takes the lock unit() gave (B1)', EN,
  "\t\t\t'lock'     => $read['lock'],", "\t\t\t'lock'     => null,", 'guard', 'testTheLocksOfWhatNoManifestHoldsAndOfAFurtherRootOfThemesAreSetInUnitOnly')
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
  "\n\t\t\t\t$base = AB_MCP_Sync_Pairs::part_bases( $pair, $part );", "\n\t\t\t\t$base = AB_MCP_Sync_Pairs::part_bases( $pair, 'files' );", 'env', 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase')
e('unit-paths', 'unit_item(): the paths where the files differ', DE,
  "\t\tif ( in_array( 'files', $differs, true ) ) {", "\t\tif ( false ) {", 'env', 'testThePathsOfAUnitWhoseFilesDifferAreShownNeverByMtime')
e('unit-pairs', 'compare(): a unit with its pair', EN,
  "\t\t\t\t$by_pair[ $pair['kind'] ][ (string) $pair['staging_ref'] ] = $pair;", '', 'env', 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase')
e('unit-entries-whole', 'entries_of(): the paths only where every file of a side is at hand', EN,
  "\t\t\t$out[ $unit ] = null !== $files && is_array( $line ) && ! isset( $line['held'] ) && count( $files ) === (int) $line['files'] ? $files : null;", "\t\t\t$out[ $unit ] = null !== $files && is_array( $line ) && ! isset( $line['held'] ) ? $files : null;", 'env', 'testThePathsOfAUnitWhoseFilesDifferAreShownNeverByMtime')
e('unit-first-time', 'read(): the first unit of a call has time of its own (every call moves on)', EN,
  "\t\t\t$read = self::read_unit( $kind, $dir, $name, $flag, $side, $alone ? max( (float) $deadline, self::now() + self::UNIT_BUDGET ) : $deadline, $alone );", "\t\t\t$read = self::read_unit( $kind, $dir, $name, $flag, $side, $deadline, $alone );", 'itenv', 'testTheUploadsAreCountedOverPartsOutsideTheFileListsOfAttachments')
e('unit-plugin-no-version', 'candidates(): a plugin of one file without a version is a unit (Codex round 1, B2)', EN,
  "( 'plugin' === $kind && array_key_exists( $name, $ctx['mains'] ) )", "( 'plugin' === $kind && isset( $ctx['mains'][ $name ] ) )", 'it', 'testAReportBetweenTwoUsualSitesShowsCodeAsEqualOrDifferent')
e('unit-one-side-lock', 'unit_item(): on one side only, a lock of that side first (Codex round 1, B3)', DE,
  "\t\t\t} elseif ( isset( $there['lock'] ) && is_string( $there['lock'] ) ) {", "\t\t\t} elseif ( false ) {", 'env', 'testAUnitOnOneSideIsOnlyThereOnlyWhereThatSideReadItWholeWithoutALock')
e('unit-one-side-whole', 'unit_item(): on one side only, read whole on that side first (B3)', DE,
  "\t\t\t} elseif ( 'theme_choice' !== $kind && ( empty( $there['complete'] ) || null === ( $there['hash'] ?? null ) ) ) {", "\t\t\t} elseif ( false ) {", 'env', 'testAUnitOnOneSideIsOnlyThereOnlyWhereThatSideReadItWholeWithoutALock')
e('unread-missing', 'unit_item(): not on a side whose folder was not listed whole: not compared (Codex round 1, B4)', DE,
  "\t\t\t} elseif ( null !== $missing ) {", "\t\t\t} elseif ( false ) {", 'env', 'testAUnitWhoseFolderTheOtherSideDidNotListWholeIsNotCompared')
e('unread-at', 'compare(): the folder a unit would lie in, not listed whole on the other side (B4)', EN,
  "\t\t\t\treturn $why;", "\t\t\t\treturn null;", 'env', 'testAUnitWhoseFolderTheOtherSideDidNotListWholeIsNotCompared')
e('unread-prefix', 'unread_at(): only below the folder, by the beginning of the path (B4)', EN,
  "0 === strpos( (string) $ref, (string) $prefix )", "false !== strpos( (string) $ref, (string) $prefix )", 'env', 'testAUnitWhoseFolderTheOtherSideDidNotListWholeIsNotCompared')
e('unread-nested', 'unread(): a folder below a root of files that could not be read (B4)', EN,
  "\t\t\t\t\t$out[ $env ][ (string) $ref . '/' ] = 'unreadable';", '', 'env', 'testAUnitWhoseFolderTheOtherSideDidNotListWholeIsNotCompared')
e('unread-root-list', 'candidates(): a folder of plugins, themes or must-use plugins that cannot be listed gives its line (B4, V1)', EN,
  "\t\t\tif ( null === $names ) {\n\t\t\t\tyield array( '', 'root' );", "\t\t\tif ( false ) {\n\t\t\t\tyield array( '', 'root' );", 'it', 'testAFolderOfPluginsTheStagingSiteCannotListLeavesThePluginsHereNotCompared')
e('unread-root-nested', 'nested(): the root of a kind of files that cannot be listed gives its line (B4)', EN,
  "\t\t\tif ( '' === $rel ) {\n\t\t\t\t// The root itself: what is not there is not known.", "\t\t\tif ( false ) {\n\t\t\t\t// The root itself: what is not there is not known.", 'it', 'testAFolderOfPluginsTheStagingSiteCannotListLeavesThePluginsHereNotCompared')
e('paths-every', 'unit_paths(): every path of each list (Codex round 1, B5)', DE,
  "\t\t\t$out[ $key ]            = $list;", "\t\t\t$out[ $key ]            = array_slice( $list, 0, self::MAX_PATHS );", 'env', 'testEveryPathOfAUnitIsInTheReportInPagesOfAHundred')
e('paths-paged', 'compare(): the entry of a unit with its further paths after it (B5)', EN,
  "\t\t\t\t\tforeach ( AB_MCP_Sync_Decision::paged( $item ) as $one ) {", "\t\t\t\t\tforeach ( array( $item ) as $one ) {", 'env', 'testEveryPathOfAUnitIsInTheReportInPagesOfAHundred')
e('paths-first-hundred', 'paged(): a hundred paths a list in the entry (B5)', DE,
  "\t\t\t$entry['paths'][ $list ] = array_slice( $entry['paths'][ $list ], 0, self::MAX_PATHS );", '', 'env', 'testEveryPathOfAUnitIsInTheReportInPagesOfAHundred')
e('paths-rest', 'paged(): the paths past a hundred, each once (B5)', DE,
  "\t\t\tforeach ( array_chunk( array_slice( $entry['paths'][ $list ], self::MAX_PATHS ), self::MAX_PATHS ) as $i => $chunk ) {", "\t\t\tforeach ( array_chunk( $entry['paths'][ $list ], self::MAX_PATHS ) as $i => $chunk ) {", 'env', 'testEveryPathOfAUnitIsInTheReportInPagesOfAHundred')
e('paths-from', 'paged(): the number of the first path of an entry of further paths (B5)', DE,
  "\t\t\t\t\t\t'from'  => self::MAX_PATHS * ( $i + 1 ) + 1,", "\t\t\t\t\t\t'from'  => 1,", 'env', 'testEveryPathOfAUnitIsInTheReportInPagesOfAHundred')
e('series-theme-again', 'theme_choice(): the theme switched while it is read is not read (Codex round 1, B6)', EN,
  "\t\t$same = $read['again'] === $read['stylesheet'];", "\t\t$same = true;", 'it', 'testTheRowsOfTheSeriesThatFailOrChangeWhileReadAreNeverComparedHalf')
e('held-mains', 'names_held(): the main files of a plugin and those that run by the rules of both sites (GitHub-Codex P1)', EN,
  "\t\t\t} elseif ( is_array( $line ) && 'plugin' === ( $line['env'] ?? null ) ) {\n\t\t\t\t// The main files of a plugin and those that run (GitHub-Codex, P1).", "\t\t\t} elseif ( false ) {\n\t\t\t\t// The main files of a plugin and those that run (GitHub-Codex, P1).", 'env', 'testTheMainFilesOfAPluginAreHeldByTheRulesOfBothSites')
e('held-names-mains', 'held_names(): a main file the staging site holds (GitHub-Codex P1)', EN,
  "\t\t\t} elseif ( is_array( $line ) && 'plugin' === ( $line['env'] ?? null ) ) {\n\t\t\t\t$inside = array_merge(", "\t\t\t} elseif ( false ) {\n\t\t\t\t$inside = array_merge(", 'env', 'testTheMainFilesOfAPluginAreHeldByTheRulesOfBothSites')
e('theme-roots-page', 'unit_step(): the themes of further roots end a part where it is full and go on in the next (GitHub-Codex P2)', EN,
  "\t\t\t\tif ( count( $lines ) >= $room ) {\n\t\t\t\t\treturn array(\n\t\t\t\t\t\t'lines' => $lines,\n\t\t\t\t\t\t'done'  => false,\n\t\t\t\t\t\t'after' => self::place( (string) $last, false ),", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn array(\n\t\t\t\t\t\t'lines' => $lines,\n\t\t\t\t\t\t'done'  => false,\n\t\t\t\t\t\t'after' => self::place( (string) $last, false ),", 'it', 'testTheThemesOfAFurtherRootAreReadPageByPageNoneLeftOut')
e('theme-roots-after', 'unit_step(): the themes of further roots after the one the part before ended with', EN,
  "\t\t\t\tif ( null !== $after && strcmp( (string) $name, $after ) <= 0 ) {", "\t\t\t\tif ( false ) {", 'it', 'testTheThemesOfAFurtherRootAreReadPageByPageNoneLeftOut')
e('upload-top-attached', 'candidates(): a file at the top of the uploads in a file list of an attachment is no unit (GitHub-Codex P2)', EN,
  "\t\t\t\treturn isset( $ctx['attached'][ $name ] ) ? 'skip' : 'unit';", "\t\t\t\treturn 'unit';", 'it', 'testAFileOfAnAttachmentAtTheTopOfTheUploadsIsNoUnit')
e('removed-changed', 'unit_item(): removed on one side, the other side against its base (TABLE rows 7 and 9; GitHub-Codex P2)', DE,
  "\t\t\t\t\t$moved            = ! hash_equals( (string) $base[ null === $s ? 'live' : 'staging' ], $have );", "\t\t\t\t\t$moved            = false;", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('removed-side', 'unit_item(): removed on one side, the base of the side that has it', DE,
  "\t\t\t\t\t$moved            = ! hash_equals( (string) $base[ null === $s ? 'live' : 'staging' ], $have );", "\t\t\t\t\t$moved            = ! hash_equals( (string) $base[ null === $s ? 'staging' : 'live' ], $have );", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('removed-action', 'unit_item(): a removal against a change is kept, not locked or nothing to do', DE,
  "\t\t\t\t$entry['action'] = null === $s ? ( $changed ? 'keep' : 'locked' ) : ( $changed ? 'keep' : 'none' );", "\t\t\t\t$entry['action'] = null === $s ? 'locked' : 'none';", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('removed-canon', 'unit_item(): removed on one side, bases of another version: not compared', DE,
  "\t\t\t\tif ( $pair['canon'] !== AB_MCP_Sync_Canon::VERSION ) {\n\t\t\t\t\treturn self::unit_entry( $entry, array(), sprintf( 'The bases of its pair", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::unit_entry( $entry, array(), sprintf( 'The bases of its pair", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('removed-no-base', 'unit_item(): removed on one side, a part without its base: not compared', DE,
  "\t\t\t\t\tif ( null === $base ) {\n\t\t\t\t\t\treturn self::unit_entry(", "\t\t\t\t\tif ( false ) {\n\t\t\t\t\t\treturn self::unit_entry(", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('removed-not-read', 'unit_item(): removed on one side, a part of the other not read: not compared', DE,
  "\t\t\t\t\tif ( null === $have ) {", "\t\t\t\t\tif ( false ) {", 'env', 'testAUnitRemovedOnOneSideAndChangedOnTheOtherIsAConflictWithTheRemoval')
e('own-version-known', 'E12 unit_item(): AlphaBridge itself with a version not read is never the same (GitHub-Codex on ed1e512)', DE,
  "\t\t\t\tif ( ! empty( $line['own'] ) && ! is_string( $line['version'] ?? null ) ) {", "\t\t\t\tif ( false ) {", 'env', 'testAlphaBridgeItselfIsShownByItsVersionNeverTakenOver')
e('own-version-read', 'E12 read_unit(): the version of AlphaBridge itself read from its head', EN,
  "\t\t\t\t'version'  => self::own_version( $dir . '/' . $name ),", "\t\t\t\t'version'  => null,", 'it', 'testAlphaBridgeItselfInTheMustUseFolderIsReadWithItsVersion')
e('own-version-mu', 'E12 unit_lines(): the version of AlphaBridge itself in the must-use folder on its line', EN,
  "\t\t\t$fields['version'] = $read['version'] ?? null;", '', 'it', 'testAlphaBridgeItselfInTheMustUseFolderIsReadWithItsVersion')
e('walk-time', 'nested(): the time of the call asked while a root of files is walked, also where it gives no unit (GitHub-Codex on e9db02d)', EN,
  "\t\t\tif ( $walk['visited'] > 0 && self::now() >= $walk['until'] ) {", "\t\t\tif ( false ) {", 'it', 'testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut')
e('plugin-active-ref', 'unit_lines(): a main file runs by its full reference in active_plugins (GitHub-Codex on f25da74)', EN,
  "\t\t\t\t$refs[ $main ]  = $rel;", "\t\t\t\t$refs[ $main ]  = $main;", 'env', 'testAFolderPluginWhoseMainFileHasItsNameRunsByItsFullReference')
e('theme-root-exact', 'theme_roots(): an external theme is left out only where the standard root has its very stylesheet (GitHub-Codex on f25da74)', EN,
  "\t\t\t\tif ( array_key_exists( (string) $stylesheet, $here ) || isset( $seen[ (string) $stylesheet ] ) ) {", "\t\t\t\tif ( file_exists( $standard['dir'] . '/' . explode( '/', (string) $stylesheet )[0] ) || isset( $seen[ (string) $stylesheet ] ) ) {", 'it', 'testAThemeOfAFurtherRootBesideOneOfTheSameCollectionHereKeepsItsLock')
e('theme-root-same', 'theme_roots(): the theme of the standard root is that one, the external one with its stylesheet left out', EN,
  "\t\t\t\tif ( array_key_exists( (string) $stylesheet, $here ) || isset( $seen[ (string) $stylesheet ] ) ) {", "\t\t\t\tif ( isset( $seen[ (string) $stylesheet ] ) ) {", 'it', 'testAThemeOfAFurtherRootBesideOneOfTheSameCollectionHereKeepsItsLock')
e('empty-manifest', 'entries_of(): a folder read whole without a file has an empty manifest (GitHub-Codex on f25da74)', EN,
  "\t\t\t\t\t$out[ $kind . ':' . $ref ] = array();", '', 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-form', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (form)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-complete', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (complete)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-hash', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (hash)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-lock', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (lock)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-held', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (held)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && 0 === ( $line['files'] ?? null ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('empty-manifest-files', 'entries_of(): an empty manifest only for a folder read whole, with a hash, not locked nor held, without a file (files)', EN,
  "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) && 0 === ( $line['files'] ?? null ) ) {", "\t\t\t\tif ( 'folder' === ( $line['form'] ?? null ) && true === ( $line['complete'] ?? null ) && is_string( $line['hash'] ?? null ) && null === ( $line['lock'] ?? null ) && ! isset( $line['held'] ) ) {", 'env', 'testAFolderReadWholeWithoutAFileShowsTheFilesOfTheOtherSideAsOnlyThere')
e('walk-visited-guard', 'nested(): one entry visited before the time of the call is asked, so that every call moves on (Codex on 7d0aeea, C)', EN,
  "\t\t\tif ( $walk['visited'] > 0 && self::now() >= $walk['until'] ) {", "\t\t\tif ( self::now() >= $walk['until'] ) {", 'it', 'testAWalkPastItsTimeVisitsOneEntryFirstAndGoesOnAfterIt')
e('walk-last-skip', 'nested(): the place goes on over a skipped entry', EN,
  "\t\t\tif ( 'skip' === $what ) {\n\t\t\t\t$walk['last'] = $path;", "\t\t\tif ( 'skip' === $what ) {", 'it', 'testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut')
e('walk-last-into', 'nested(): the place goes on over a folder walked', EN,
  "\t\t\t\tyield from self::nested( $base, $path, $into, $pick, $walk );\n\t\t\t\t$walk['last'] = $path;", "\t\t\t\tyield from self::nested( $base, $path, $into, $pick, $walk );", 'it', 'testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut')
e('walk-step-time', 'unit_step(): a walk out of time ends the step, to go on (never done)', EN,
  "\t\t\tif ( 'time' === $flag ) {\n\t\t\t\treturn self::units_stop( $lines, $at );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::units_stop( $lines, $at );", 'it', 'testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut')
e('walk-step-at', 'unit_step(): the next call goes on after the last entry walked, not only after the last unit', EN,
  "\t\t\t$at = null !== $walk['last'] ? $walk['last'] : $last;", "\t\t\t$at = $last;", 'it', 'testATreeOfEmptyFoldersIsWalkedOverPartsWithItsPlaceAndNoUnitLeftOut')
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
e('core-too-slow', 'check(): with the whole time of a call and not through: not compared (too_slow), never equal; the series goes on (GitHub-Codex on ec0d088, G-M7)', CC,
  "\t\t\t\tif ( $first ) {\n\t\t\t\t\t$out['cause'] = 'too_slow';", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$out['cause'] = 'too_slow';", 'core', 'testTheTimeOfTheCallOverLeavesNoLine')
e('core-first-passed', 'read(): the check of the core told it has the whole time of the call (G-M7)', EN,
  "AB_MCP_Sync_Core_Compare::check( max( $deadline, self::now() + self::UNIT_BUDGET ), $first );", "AB_MCP_Sync_Core_Compare::check( max( $deadline, self::now() + self::UNIT_BUDGET ) );", 'it', 'testACoreTooSlowForAWholeCallIsNotComparedAndTheRestOfTheEnvironmentIsRead')
e('core-too-slow-way', 'why_core(): too_slow says why and names the way (G-M7)', EN,
  "\t\t\t'too_slow'        => 'they could not all be checked within the time of one call; check them at the host (wp core verify-checksums), or make the report again (wp_site_diff)',", '', 'core', 'testTheTimeOfTheCallOverLeavesNoLine')
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
  "\t\t$done = self::calls()->update_row( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );\n\t\tif ( false === $done ) {\n\t\t\treturn self::db_error();", "\t\t$done = self::calls()->update_row( 'UPDATE ' . AB_MCP_Sync_Schema::table( 'pairs' ) . ' SET ' . implode( ', ', $parts ) . ' WHERE ' . self::where_row( $raw ) );\n\t\tif ( false ) {\n\t\t\treturn self::db_error();", 'it', 'testAFaultOfTheDatabaseLeavesTheBasesAsTheyWere')
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
       " * list of paths, sizes and hashes), the guards of names() and open() (the",
       ' * next call with false throws a TypeError, caught by unit(): the same answer,',
       ' * unreadable, no hash), and in unit() a theme_root() of null beside another',
       ' * folder (rtrim() of the empty text is no folder: locked either way). A',
       ' * read() that fails in file() is listed (z1-read-false): where the next',
       ' * read() gives bytes, the file would be hashed whole without the clause',
       ' * (Codex round 1, B7, corrected). The guard of names() in still() is not',
       ' * listed either (its TypeError is caught as unreadable, the same answer).',
       ' * same() in file() takes no type, so that a',
       ' * call that failed is not caught as a TypeError in place of its clause',
       ' * (Behebung 3, z1-fstat-after).', ' *',
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
