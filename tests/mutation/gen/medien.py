#!/usr/bin/env python3
"""Writes tests/mutation/lists/medien.php: one entry per clause of Z8
branch 10 (heikel/z8-medien): the kind attachment with its files (decision
26): the canonical form of an attachment (AB_MCP_Sync_Kind_Attachment), the
package of media (AB_MCP_Sync_Media_Package, the export, the import and its
index), the write side (AB_MCP_Sync_Attachment_Writer: the file list, the
paths, the exclusive laying, the files the run owns, the paths another
attachment names, the type by the content, the rights forward and back),
the parent as a weak reference (the report, the plan and its parent step)
and the engine (the writer of the kind, the files before the row, the
check of the run).

Every search is checked to occur exactly once in its file, and the test of
its expect to be a method of the class its run names, before the list is
written; each entry runs that test only.

    python3 tests/mutation/gen/medien.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/medien.php). Development
only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
AW = 'includes/sync/class-sync-attachment-writer.php'
MP = 'includes/sync/class-sync-media-package.php'
ME = 'includes/sync/class-sync-media.php'
EN = 'includes/sync/class-sync-engine.php'
PU = 'includes/sync/class-sync-push.php'
DE = 'includes/sync/class-sync-decision.php'
IM = 'includes/sync/class-sync-import.php'
EX = 'includes/sync/class-sync-export.php'
DK = 'includes/sync/class-sync-db-kinds.php'
ER = 'includes/sync/class-sync-export-rights.php'
PW = 'includes/sync/class-sync-post-writer.php'
FILEVAR = {AW: '$aw', MP: '$mp', ME: '$me', EN: '$en', PU: '$pu', DE: '$de', IM: '$im', EX: '$ex', DK: '$dk', ER: '$er', PW: '$pw'}

RUNS = {
    'pkg': ('unit', 'SyncMediaPackageTest'),
    'report': ('unit', 'SyncReportTest'),
    'run': ('unit', 'SyncRunTest'),
    'kinds': ('unit', 'SyncDbKindsTest'),
    'writer': ('unit', 'SyncTermWriterTest'),
    'it': ('it', 'SyncMediaTest'),
    'itpkg': ('it', 'SyncPackageTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('medien/' + id_, clause, file, search, replace, run, expect))


# The tests.
NORMAL = 'testANewImageWithItsSizesAndANewPdfInANewPageGoOverAndTheWayBackLeavesThemWithTheirFiles'
CHANGED = 'testAChangedTitleAltTextDescriptionAndParentOfAnAttachmentGoOverAndBackWithoutAFile'
LOCKS = 'testWhatWouldReplaceShareOrLinkAFileOrIsOfATypeThisAccountMayNotUploadLocksBeforeAnythingIsWritten'
PACKAGE = 'testAPackageOfMediaWhoseFilesDoNotReadWholeIsNotUsed'
RACE = 'testAPathTakenAsTheFilesAreLaidKeepsTheFilesLaidBeforeAsTheRunsOwnAndMakesNoAttachment'
EDIT = 'testAnImageEditedOnStagingLaysItsNewFilesOnlyWithTheRightToUploadAndTheWayBackAsksTheTypeOfTheFileItNamesAgain'
U_LINE = 'testALineIsOnePieceOfOneFileInTheOneSpellingOfTheCanon'
U_BAD = 'testALineThatIsNotOneAsTheStagingSiteWritesItIsNone'
U_LINES = 'testTheLinesOfAFileAreItsPiecesInTheirOrderReadAgainstItsList'
U_BIG = 'testALargeFileIsSplitIntoPiecesOfThePieceSize'
U_STATE = 'testWhatLiesAtThePathOfAFileToLay'
U_OWN = 'testTheFilesTheRunLaidAreItsOwnAndAFileThereAlreadyIsNot'
U_ROW = 'testTheFileOfANewAttachmentGoesWithItsRowAndThatOfOneHereByItsOwnCall'
U_RIGHTS = 'testTheOwnKeysOfAnAttachmentAskTheRightToItAndToUploadForwardAndBack'
U_WEAK = 'testTheParentOfAnAttachmentIsAWeakReferenceThatWaitsButMakesNoCycle'
U_VERDICT = 'testWhichEntryOfAReportIsAStepLockedOrNothing'
U_FOLDER = 'testTheFolderOfAFileIsMadeLevelByLevelUnderTheUploadsAndTheUploadsThemselvesWhereTheyAreMissing'
U_LAY = 'testAFileIsLaidOnlyWhereItIsShownAtItsPathUnderTheUploadsAndAFolderSwappedAsItIsOpenedLeavesItEmptyAndUnclear'
FLAT = 'testTheFirstFilesOfASiteWithoutMonthFoldersGoIntoItsUploadsAndOnlyWithTheRightToUploadAsTheyAreLaid'
FILEHOOK = 'testAFileAHookChangesAsTheStepWritesOrPutsBackDeviatesTheAttachmentAndStopsItsWayBack'
U_KINDS = 'testEachKindRegistersWithWhatTheTableGivesIt'
U_WRITERS = 'testTheEngineWritesTermsWithTheirOwnWriter'
IT_LINES = 'testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain'
IT_RIGHTS = 'testTheRightsOfAnObjectAreAskedOfWordPress'

# ------------------------------------------------- the line of a package
e('line-spelling', 'line(): the one spelling of the canon', MP,
  "if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== (string) $line || self::FIELDS !== self::sorted_keys( $object ) ) {",
  "if ( ! is_array( $object ) || self::FIELDS !== self::sorted_keys( $object ) ) {", 'pkg', U_BAD + ' with data set "another spelling"')
e('line-fields', 'line(): the fields of FIELDS, no other', MP,
  "if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== (string) $line || self::FIELDS !== self::sorted_keys( $object ) ) {",
  "if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== (string) $line ) {", 'pkg', U_BAD + ' with data set "a field too many"')
e('line-kind', 'line(): the kind file', MP,
  "\t\tif ( 'file' !== $object['kind'] || ",
  "\t\tif ( ", 'pkg', U_BAD + ' with data set "another kind"')
e('line-ref', 'line(): a ref of an id, as text', MP,
  " || ! is_string( $object['ref'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,19}\\z/', $object['ref'] ) ||",
  " ||", 'pkg', U_BAD + ' with data set "a ref that is no id"')
e('line-path', 'line(): a plain path under the uploads', MP,
  " || null === $path || ! AB_MCP_Sync_Media::safe_path( $path ) ) {",
  " || null === $path ) {", 'pkg', U_BAD + ' with data set "a path up"')
e('line-sha', 'line(): a SHA-256 in hex', MP,
  "if ( ! is_string( $object['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $object['sha256'] ) || ",
  "if ( ! is_string( $object['sha256'] ) || ", 'pkg', U_BAD + ' with data set "a hash in capitals"')
e('line-pieces', 'line(): as many pieces as the size gives', MP,
  "\t\tif ( $object['pieces'] !== self::pieces( $object['size'] ) || ",
  "\t\tif ( ", 'pkg', U_BAD + ' with data set "a piece too many, its first whole"')
e('line-piece-range', 'line(): a piece within its pieces', MP,
  " || $object['piece'] >= $object['pieces'] ||", " ||", 'pkg', U_BAD + ' with data set "a piece past its end"')
e('line-base64', 'line(): data in base64, round trip', MP,
  "\t\tif ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $object['data'] ) {",
  "\t\tif ( ! is_string( $bytes ) ) {", 'pkg', U_BAD + ' with data set "its bytes in base64 spelt otherwise"')
e('line-piece-size', 'line(): a piece of exactly the size its number gives', MP,
  "\t\tif ( strlen( $bytes ) !== $want ) {\n\t\t\treturn null;", "\t\tif ( false ) {\n\t\t\treturn null;", 'pkg', U_BAD + ' with data set "data of another size"')
e('pieces-one', 'pieces(): one piece at least', MP,
  "\t\treturn max( 1, intdiv( max( 0, (int) $size ) + self::PIECE - 1, self::PIECE ) );",
  "\t\treturn intdiv( max( 0, (int) $size ) + self::PIECE - 1, self::PIECE );", 'pkg', U_LINE)
e('lines-of-resolved', 'lines_of(): the file resolved under the uploads, no link', MP,
  "\t\tif ( false === $real || $real !== $full || 'file' !== @filetype( $real ) ) {",
  "\t\tif ( false === $real || 'file' !== @filetype( $real ) ) {", 'pkg', U_LINES)
e('lines-of-hash', 'lines_of(): the bytes the list hashed, nothing after them', MP,
  "\t\t\tif ( '' !== self::read( $handle, 1 ) || ! hash_equals( $sha, hash_final( $hash ) ) ) {",
  "\t\t\tif ( false ) {", 'pkg', U_LINES)
e('lines-of-piece', 'lines_of(): a piece of PIECE bytes, the last the rest', MP,
  "\t\t\t\t$want  = min( self::PIECE, $size - $read );",
  "\t\t\t\t$want  = $size - $read;", 'pkg', U_BIG)

# ------------------------------------------ the import of a package of media
e('unpacked-twice', 'unpacked(): a file of an attachment once', MP,
  "\t\t\t\t\tif ( 0 !== $piece['piece'] || isset( $seen[ $key ] ) ) {",
  "\t\t\t\t\tif ( 0 !== $piece['piece'] ) {", 'it', PACKAGE)
e('unpacked-hash', 'unpacked(): every file against its SHA-256', MP,
  "\t\t\t\t\tif ( ! hash_equals( $current['sha256'], hash_final( $current['hash'] ) ) ) {",
  "\t\t\t\t\tif ( false ) {", 'it', PACKAGE)
e('unpacked-end', 'unpacked(): no package ends within a file', MP,
  "\t\tif ( null !== $current ) {\n\t\t\treturn self::refusal( 'The package ends within the pieces of a file.' );",
  "\t\tif ( false ) {\n\t\t\treturn self::refusal( 'The package ends within the pieces of a file.' );", 'it', PACKAGE)
e('unpacked-line', 'unpacked(): a line that is no piece of a file refuses', MP,
  "\t\t\t\tif ( null === $piece ) {\n\t\t\t\t\treturn self::refusal(",
  "\t\t\t\tif ( null === $piece ) {\n\t\t\t\t\tcontinue;\n\t\t\t\t\treturn self::refusal(", 'it', PACKAGE)
e('import-media-branch', 'Import::unpacked(): a package of media checked and indexed by its own class', IM,
  "\t\tif ( 'media' === $manifest['purpose'] ) {\n\t\t\treturn AB_MCP_Sync_Media_Package::unpacked( $held );",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Media_Package::unpacked( $held );", 'it', PACKAGE)
e('import-media-taken', 'Import::taken(): a package of media by its index', IM,
  "\t\tif ( 'media' === $purpose ) {\n\t\t\treturn AB_MCP_Sync_Media_Package::taken( $found['gid'], $manifest, $have );",
  "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Media_Package::taken( $found['gid'], $manifest, $have );", 'it', PACKAGE)
e('extract-hash', 'extract(): a file read whole against its SHA-256', MP,
  "\t\tif ( $next !== (int) $file['pieces'] || $bytes !== (int) $file['size'] || ! hash_equals( (string) $file['sha256'], hash_final( $hash ) ) ) {",
  "\t\tif ( false ) {", 'it', PACKAGE)
e('import-weak-only-attachment', 'line_shaped(): a weak reference only of the parent of an attachment', IM,
  "\t\t\tif ( 'weak' === ( $ref['strength'] ?? null ) && ! ( 'attachment' === $object['kind'] && 'post' === ( $ref['space'] ?? null ) && 'post_parent' === ( $ref['at'] ?? null ) ) ) {",
  "\t\t\tif ( false ) {", 'itpkg', IT_LINES)
e('import-identity-attachment', 'identity_fits(): an attachment is of the type attachment', IM,
  "\t\t\t\treturn 'attachment' === $text['post_type'];", "\t\t\t\treturn true;", 'itpkg', IT_LINES)

# ------------------------------------------------ the export of media
e('export-media', 'make(): the purpose media makes a package of media', EX,
  "\t\tif ( 'media' === $purpose ) {\n\t\t\treturn self::make_media( is_array( $for ) ? $for : array() );",
  "\t\tif ( false ) {\n\t\t\treturn self::make_media( is_array( $for ) ? $for : array() );", 'it', NORMAL)
e('export-rights-attachment', 'withheld(): an attachment by the rule of the content', ER,
  "\t\t\treturn 'attachment' === AB_MCP_Sync_Rows::post_type_of( (int) $ref ) ? self::post( $ref ) : 'gone';",
  "\t\t\treturn 'kind';", 'itpkg', IT_RIGHTS)

# ------------------------------------------ the canonical form of an attachment
e('kind-registered', 'CLASSES: the kind attachment is a kind of the database', DK,
  ", 'AB_MCP_Sync_Kind_Option', 'AB_MCP_Sync_Kind_Attachment' );", ", 'AB_MCP_Sync_Kind_Option' );", 'kinds', U_KINDS)
e('kind-weak-parent', 'attachment_of(): the parent of an attachment is a weak reference', DK,
  "\t\t\t'row'   => self::row_form( $row, $ctx, $acc, 'weak' ),", "\t\t\t'row'   => self::row_form( $row, $ctx, $acc ),", 'it', NORMAL)
e('kind-files', 'attachment_of(): the file list is part of the form', DK,
  "\t\t\t'files' => $files,\n", "", 'it', NORMAL)
e('kind-note', 'attachment_of(): what the list cannot read locks the attachment', DK,
  "\t\tAB_MCP_Sync_Media::note( $acc, $list );\n\t\t$files = array();", "\t\t$files = array();", 'it', LOCKS)

# ---------------------------------------------------------- the report
e('report-weak-no-edge', 'graph(): a weak reference makes no edge, no cycle with its target', DE,
  "\t\t\t\t\tif ( 'strong' === $strength ) {\n\t\t\t\t\t\t$edges[ $i ][ $j ] = true;",
  "\t\t\t\t\tif ( true ) {\n\t\t\t\t\t\t$edges[ $i ][ $j ] = true;", 'report', U_WEAK)
e('report-weak-waits', 'graph(): a weak reference waits as a strong one does', DE,
  "! in_array( $strength, array( 'strong', 'weak' ), true ) ) {", "'strong' !== $strength ) {", 'report', U_WEAK)
e('report-weak-passed-on', 'graph(): what needs a held back target by a weak reference waits', DE,
  "\t\tforeach ( array( $edges, $weak ) as $of ) {", "\t\tforeach ( array( $edges ) as $of ) {", 'report', U_WEAK)

# ------------------------------------------------------------- the plan
e('verdict-attachment', 'verdict(): an attachment is an item of its kind', PU,
  "\t\t} elseif ( 'attachment' === $entry['kind'] ) {\n\t\t\t// An attachment with its files (branch 10): no choice «as draft».\n\t\t\tif ( $as_draft ) {",
  "\t\t} elseif ( false ) {\n\t\t\t// An attachment with its files (branch 10): no choice «as draft».\n\t\t\tif ( $as_draft ) {", 'run', U_VERDICT)
e('verdict-no-draft', 'verdict(): an attachment has no status draft', PU,
  "\t\t\tif ( $as_draft ) {\n\t\t\t\treturn $lock( 'An attachment has no status draft: leave it out of as_draft.' );",
  "\t\t\tif ( false ) {\n\t\t\t\treturn $lock( 'An attachment has no status draft: leave it out of as_draft.' );", 'run', U_VERDICT)
e('plan-weak-parent', 'plan(): the weak parent of an attachment is read for its parent step', PU,
  "\t\t\t\t\t$item['weak_parent'] = (string) (int) $ref['id'];\n\t\t\t\t\tcontinue;",
  "\t\t\t\t\tcontinue;", 'it', NORMAL)
e('plan-parent-edge', 'plan(): an attachment here comes after the new parent the run creates', PU,
  "\t\t\t\t$items[ $n ]['refs'][] = array( 'post', $item['weak_parent'] );\n", "", 'it', CHANGED)
e('plan-parent-later', 'plan(): a new attachment under a post the run creates is made unattached first', PU,
  "\t\t\t\t$items[ $n ]['parent_later'] = true;\n", "", 'it', NORMAL)
e('plan-parent-step', 'plan(): the parent step after the attachment and its post', PU,
  "\t\tforeach ( $parents as $n => $m ) {", "\t\tforeach ( array() as $n => $m ) {", 'it', NORMAL)
e('plan-shared-paths', 'plan(): two attachments of the plan naming one path are locked', PU,
  "\t\t\tif ( count( $ns ) < 2 ) {", "\t\t\tif ( true ) {", 'it', LOCKS)

# ------------------------------------------------------------ the engine
e('engine-writer', 'WRITERS: the kind attachment has its writer', EN,
  "\t\t'attachment' => 'AB_MCP_Sync_Attachment_Writer',\n", "", 'writer', U_WRITERS)
e('engine-pending-attachment', 'pending(): a new attachment is pending in the space of posts', EN,
  "\t\t\t$space = 'attachment' === (string) $one['kind'] ? 'post' : (string) $one['kind'];",
  "\t\t\t$space = (string) $one['kind'];", 'it', NORMAL)
e('engine-media-lines', 'lines(): a package of media of the run by its index', EN,
  "\t\t\tif ( 'media' === AB_MCP_Sync_Import::purpose_of( $package_id ) ) {",
  "\t\t\tif ( false ) {", 'it', NORMAL)
e('engine-media-report', 'lines(): a package of media of another report is refused', EN,
  "\t\t\t\tif ( ! hash_equals( (string) $run['report_hash'], (string) $taken['manifest']['report_hash'] ) ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_objects_report', sprintf( 'The package of media",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_objects_report', sprintf( 'The package of media", 'it', LOCKS)
e('engine-attach-item', 'boundary(): the parent step as an update of the attachment its step made', EN,
  "\t\t\t$item = self::attach_item( $run, $item );\n", "", 'it', NORMAL)
e('engine-lay-files', 'boundary(): the files of an attachment before its row', EN,
  "\t\tif ( method_exists( $w, 'lay_files' ) ) {", "\t\tif ( false ) {", 'it', NORMAL)
e('engine-lay-files-stop', 'boundary(): files not laid whole, no row', EN,
  "\t\t\tif ( true !== $laid['ok'] ) {", "\t\t\tif ( false ) {", 'it', RACE)
e('engine-files-call', 'write_parts(): a call that changed a file of the attachment deviates the object (Codex round 1, A3)', EN,
  "\t\t\t\tif ( null !== $moved ) {\n\t\t\t\t\treturn self::deviated( $journal, $i, sprintf( 'the call of the part %1$s changed the file",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::deviated( $journal, $i, sprintf( 'the call of the part %1$s changed the file", 'it', FILEHOOK)
e('engine-files-back-start', 'put_back(): a file changed since the step read it last stops the way back before any part (Codex round 1, A3)', EN,
  "\t\t\tif ( null !== $moved ) {\n\t\t\t\t$stopped = sprintf( 'the file %s of the attachment changed since",
  "\t\t\tif ( false ) {\n\t\t\t\t$stopped = sprintf( 'the file %s of the attachment changed since", 'it', FILEHOOK)
e('engine-files-back-put', 'put_back(): a put back that changed a file stops the way back from there (Codex round 1, A3)', EN,
  "\t\t\t\t\tif ( null === $stopped && null !== $moved ) {", "\t\t\t\t\tif ( false ) {", 'it', FILEHOOK)
e('writer-files-state', 'files_state(): a file by its SHA-256 and size (Codex round 1, A3)', AW,
  "\t\t\t\t$state = is_string( $sha ) && false !== $size ? $sha . ':' . (int) $size : 'unreadable';", "\t\t\t\t$state = 'file';", 'it', FILEHOOK)
e('engine-with-files', 'with_files(): the files the step laid in its result', EN,
  "\t\t\t$files = $w::own_files( $journal );\n\t\t\tif ( array() !== $files ) {\n\t\t\t\t$result['files'] = $files;\n\t\t\t}",
  "\t\t\t$files = $w::own_files( $journal );", 'it', NORMAL)
e('engine-pair-staging-base', 'pair_for_new(): an attachment made unattached takes the staging base of that state', EN,
  "'base_staging' => isset( $prepared['base_staging'] ) && is_string( $prepared['base_staging'] ) ? $prepared['base_staging'] : (string) $item['staging_hash'],",
  "'base_staging' => (string) $item['staging_hash'],", 'it', NORMAL)
e('engine-verify-last', 'run_verify(): only the last step that wrote an object holds its target', EN,
  "\t\t\t\t$later   = ( $last[ ( $one['parts']['object']['kind'] ?? 'post' ) . ':' . (string) $live_id ] ?? (int) $one['seq'] ) !== (int) $one['seq'];",
  "\t\t\t\t$later   = false;", 'it', NORMAL)
e('engine-revert-rest-files', 'revert_boundary(): the files of a new attachment named on the way back', EN,
  "\t\t\t\t$result = self::with_files( $result, $journal, $made_by );", "", 'it', NORMAL)
e('engine-revert-files', 'revert_boundary(): the files of a step of an attachment here named on the way back', EN,
  "\t\t$result = self::with_files( $result, $journal, $w );\n\t\t$ended  = self::end_step( $run_id, $seq, array( 'state' => $all_back ? 'done' : 'unclear'",
  "\t\t$ended  = self::end_step( $run_id, $seq, array( 'state' => $all_back ? 'done' : 'unclear'", 'it', EDIT)
e('post-thumbnail-pending', 'Post_Writer::target_of(): a featured image the run creates first is pending', PW,
  " && 1 === preg_match( '/^[1-9][0-9]*\\z/', $value ) && isset( $pending['post'][ (int) $value ] ) ) {",
  " && false ) {", 'it', NORMAL)

# ----------------------------------------------------------- the writer
e('writer-parent-later', 'target_of(): a new attachment made unattached where its parent step follows', AW,
  "\t\t\tif ( $create && ! empty( $item['parent_later'] ) ) {", "\t\t\tif ( false ) {", 'it', NORMAL)
e('writer-parent-pending', 'target_of(): a parent the run creates is pending for the check', AW,
  "\t\t\t} elseif ( null === $mapped && isset( $pending['post'][ $parent ] ) ) {", "\t\t\t} elseif ( false ) {", 'it', CHANGED)
e('writer-parent-unmapped', 'target_of(): a parent without a pair the run does not create locks', AW,
  "\t\t\tif ( null === $mapped ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', sprintf( 'the post %d of the staging site the attachment hangs under",
  "\t\t\tif ( false ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'post:reference_unmapped', sprintf( 'the post %d of the staging site the attachment hangs under", 'it', LOCKS)
e('writer-interim-target', 'target_of(): the target of an attachment made unattached has no parent', AW,
  "\t\t\t$target['row']['post_parent'] = '0';\n", "", 'it', NORMAL)
e('writer-own-meta-new', 'target_of(): the own keys of a new attachment by their rule', AW,
  "\t\t\tif ( $create && ! in_array( $key, self::OWN_META, true ) && ", "\t\t\tif ( $create && ", 'it', NORMAL)
e('writer-file-with-row', 'target_of(): the file of a new attachment with its row', AW,
  "\t\t\t$postarr['file'] = $attached;\n", "", 'it', NORMAL)
e('writer-create-right', 'precheck(): a new attachment needs upload_files', AW,
  "\t\t} elseif ( ! current_user_can( (string) $pto->cap->create_posts ) ) {", "\t\t} elseif ( false ) {", 'it', EDIT)
e('writer-file-meta-right', 'precheck(): a change of the keys that name files needs upload_files', AW,
  "\t\t\tif ( in_array( $key, self::FILE_META, true ) && null !== $live_id && ! current_user_can( 'upload_files' ) ) {", "\t\t\tif ( false ) {", 'it', EDIT)
e('writer-own-meta-rule', 'precheck(): the own keys are no keys of map_meta_cap', AW,
  "\t\t\tif ( in_array( $key, self::OWN_META, true ) || '_thumbnail_id' === $key ) {\n\t\t\t\tcontinue;",
  "\t\t\tif ( '_thumbnail_id' === $key ) {\n\t\t\t\tcontinue;", 'it', CHANGED)
e('writer-files-plan', 'precheck(): the files of the target checked', AW,
  "\t\t$plan = self::files_plan( $prepared, $live_id );\n\t\treturn is_wp_error( $plan ) ? $plan : true;",
  "\t\treturn true;", 'it', LOCKS)
e('writer-replace', 'files_plan(): another file at a path locks, never written over', AW,
  "\t\t\tif ( 'other' === $state ) {", "\t\t\tif ( false ) {", 'it', LOCKS)
e('writer-same-bytes', 'files_plan(): a file at the path is the same only byte for byte', AW,
  "\t\t\t\t$state = is_string( $sha ) && hash_equals( (string) $file['sha256'], $sha ) && (int) @filesize( $root . '/' . $file['path'] ) === (int) $file['size'] ? 'present' : 'other';",
  "\t\t\t\t$state = 'present';", 'it', LOCKS)
e('writer-unsafe', 'files_plan(): a link on the way locks', AW,
  "\t\t\tif ( 'unsafe' === $state ) {", "\t\t\tif ( false ) {", 'it', LOCKS)
e('writer-type-name', 'files_plan(): a type this account may not upload by its name locks', AW,
  "\t\t\t\tif ( ( empty( $type['type'] ) || empty( $type['ext'] ) ) && ! $free ) {", "\t\t\t\tif ( false ) {", 'it', LOCKS)
e('writer-main-type', 'files_plan(): the main file of the type the attachment says', AW,
  "\t\t\tif ( ! $free && (string) $type['type'] !== (string) $prepared['postarr']['post_mime_type'] ) {", "\t\t\tif ( false ) {", 'it', LOCKS)
e('writer-present-main-type', 'files_plan(): a file this site has that the attachment would name, by its content', AW,
  "\t\t\t\tif ( $names && 'present' === $entry['state'] && $entry['path'] === $attached ) {", "\t\t\t\tif ( false ) {", 'it', LOCKS)
e('writer-media-missing', 'files_plan(): a file to lay the packages of media of the run carry', AW,
  "\t\t\t\tif ( ! is_array( $prepared['media'] ) || ! isset( $prepared['media'][ $key ] ) || ",
  "\t\t\t\tif ( ! is_array( $prepared['media'] ) || ", 'it', LOCKS)
e('writer-shared', 'files_plan(): a path another attachment of this site names locks', AW,
  "\t\tforeach ( $named as $path => $ids ) {", "\t\tforeach ( array() as $path => $ids ) {", 'it', LOCKS)
e('writer-shared-except', 'named_by(): the attachment written is no other', ME,
  " AND pm.post_id != %d AND pm.post_id > %d ORDER BY pm.post_id ASC LIMIT %d', array_merge( $args, array( (int) $except, ",
  " AND pm.post_id != %d AND pm.post_id > %d ORDER BY pm.post_id ASC LIMIT %d', array_merge( $args, array( 0, ", 'it', CHANGED)
e('writer-shared-exact', 'named_by(): a candidate names the path byte for byte', ME,
  "\t\t\t\t\tif ( isset( $want[ $entry['path'] ] ) ) {\n\t\t\t\t\t\t$out[ $entry['path'] ][] = (int) $id;",
  "\t\t\t\t\tif ( true ) {\n\t\t\t\t\t\t$out[ $entry['path'] ][] = (int) $id;", 'it', CHANGED)
e('writer-provide-name', 'provide(): a content of another type than its name refuses', AW,
  "\t\t\tif ( ! empty( $check['proper_filename'] ) ) {\n\t\t\t\t$done = AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type'",
  "\t\t\tif ( false ) {\n\t\t\t\t$done = AB_MCP_Sync_Post_Writer::locked( 'attachment:file_type'", 'it', RACE)
e('writer-provide-first', 'lay_files(): every file provided before the first is laid', AW,
  "\t\t\t\t$made = self::provide( $taken[ $package ], $file, $prepared );", "\t\t\t\t$made = $file['path'];", 'it', RACE)
e('writer-lay-exclusive', 'lay(): a file laid exclusively (fopen xb)', AW,
  "\t\t$out = @fopen( (string) $full, 'xb' );", "\t\t$out = @fopen( (string) $full, 'wb' );", 'it', RACE)
e('writer-lay-stop', 'lay_files(): a file not laid stops the files after it', AW,
  "\t\t\t\tif ( 'laid' !== $laid['state'] ) {\n\t\t\t\t\treturn self::not_laid( $journal, $laid['note'] );",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::not_laid( $journal, $laid['note'] );", 'it', RACE)
e('writer-lay-own', 'lay(): a file laid is the run\'s own', AW,
  "\t\t\t'state' => 'laid',\n\t\t\t'own'   => true,", "\t\t\t'state' => 'laid',\n\t\t\t'own'   => false,", 'it', NORMAL)
e('writer-folder', 'folder(): the missing folders made under the uploads, one level at a time', AW,
  "\t\t\t\tif ( ! @mkdir( $at, $perms ) && ! is_dir( $at ) ) {", "\t\t\t\tif ( true ) {", 'it', NORMAL)
e('writer-folder-root', 'folder(): the uploads folder itself made where it is missing (Codex round 1, B8)', AW,
  "\t\tforeach ( array_merge( array( '' ), $parts ) as $part ) {", "\t\tforeach ( $parts as $part ) {", 'pkg', U_FOLDER)
e('writer-folder-file', 'folder(): a file where a folder goes locks', AW,
  "\t\t\tif ( ! is_link( $at ) && file_exists( $at ) && ! is_dir( $at ) ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'attachment:replace_file'",
  "\t\t\tif ( false ) {\n\t\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'attachment:replace_file'", 'pkg', U_FOLDER)
e('writer-lay-dir-again', 'lay(): the folder of the file asked again right before the open (Codex round 1, A1)', AW,
  "\t\tif ( is_link( $dir ) || false === $real_dir || $real_dir !== $dir || ! is_dir( $real_dir ) || ( $dir !== $root && 0 !== strpos( $dir, $root . '/' ) ) ) {",
  "\t\tif ( false ) {", 'pkg', U_LAY)
e('writer-lay-placed', 'lay(): the file opened shown at its path under the uploads, else nothing written into it (Codex round 1, A1)', AW,
  "\t\tif ( ! $placed ) {", "\t\tif ( false ) {", 'pkg', U_LAY)
e('engine-lay-unclear', 'boundary(): a step with a file not shown at its path ends unclear, the file named (Codex round 1, A1, A2)', EN,
  "'state' => isset( $laid['state'] ) && 'unclear' === $laid['state'] ? 'unclear' : 'failed',", "'state' => 'failed',", 'it', RACE)
e('engine-files-unclear', 'with_files(): the files whose state is unclear named in the result (Codex round 1, A2)', EN,
  "\t\tif ( method_exists( $w, 'unclear_files' ) ) {", "\t\tif ( false ) {", 'it', RACE)
e('writer-may-lay', 'lay_files(): the rights asked again right before a file is laid (Codex round 1, A5)', AW,
  "\t\t\t\tif ( true !== $may ) {\n\t\t\t\t\treturn self::not_laid( $journal, $may );",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::not_laid( $journal, $may );", 'it', FLAT)
e('writer-may-lay-upload', 'may_lay(): upload_files (create_posts of the type) for a new attachment', AW,
  "\t\tif ( null === $live_id ? ! current_user_can( (string) $pto->cap->create_posts ) : ( ! current_user_can( 'edit_post', (int) $live_id ) || ! current_user_can( 'upload_files' ) ) ) {",
  "\t\tif ( null === $live_id ? false : ( ! current_user_can( 'edit_post', (int) $live_id ) || ! current_user_can( 'upload_files' ) ) ) {", 'it', FLAT)
e('writer-uploads-missing', 'files_plan(): a site without an upload yet', AW,
  "\t\t\t$root = $parent . '/' . basename( $dir );", "\t\t\treturn AB_MCP_Sync_Post_Writer::locked( 'attachment:unsafe_path', 'x' );", 'it', NORMAL)
e('writer-own-files', 'own_files(): own only what was laid and shown at its path, never creating (Codex round 1, A2)', AW,
  "\t\t\tif ( ! empty( $file['own'] ) && in_array( (string) ( $file['state'] ?? '' ), array( 'laid', 'partial' ), true ) ) {", "\t\t\tif ( ! empty( $file['own'] ) || 'creating' === ( $file['state'] ?? '' ) ) {", 'pkg', U_OWN)
e('writer-unclear-files', 'unclear_files(): a file being made when the request ended is unclear, named (Codex round 1, A2)', AW,
  "\t\t\tif ( 'creating' === $state || 'unclear' === $state ) {", "\t\t\tif ( 'unclear' === $state ) {", 'pkg', U_OWN)
e('writer-through-row', 'through_row(): the file of a new attachment goes with its row', AW,
  "\t\treturn 'meta:_wp_attached_file' === (string) $name && 'create' === $prepared['op'] && isset( $prepared['postarr']['file'] );",
  "\t\treturn false;", 'pkg', U_ROW)
e('writer-with-row', 'with_row(): the row call writes the file of a new attachment', AW,
  "\t\tif ( null === $live_id && isset( $prepared['postarr']['file'] ) && '' !== (string) $prepared['postarr']['file'] ) {",
  "\t\tif ( false ) {", 'pkg', U_ROW)
e('writer-write-part-rights', 'write_part(): an own key with the right to the attachment and to upload', AW,
  "\t\tif ( ! current_user_can( 'edit_post', (int) $live_id ) || ( in_array( $key, self::FILE_META, true ) && ! current_user_can( 'upload_files' ) ) ) {",
  "\t\tif ( false ) {", 'pkg', U_RIGHTS)
e('writer-restore-upload', 'restore_right(): a key that names files needs upload_files on the way back', AW,
  "\t\tif ( in_array( $key, self::FILE_META, true ) && ! current_user_can( 'upload_files' ) ) {\n\t\t\treturn sprintf( 'change the files of the attachment %1$d (upload_files, for the part %2$s)', $id, $part );",
  "\t\tif ( false ) {\n\t\t\treturn sprintf( 'change the files of the attachment %1$d (upload_files, for the part %2$s)', $id, $part );", 'pkg', U_RIGHTS)
e('writer-restore-edit', 'restore_right(): an own key needs edit_post on the way back', AW,
  "\t\tif ( ! current_user_can( 'edit_post', $id ) ) {\n\t\t\treturn sprintf( 'edit the attachment %1$d (edit_post, for the part %2$s)', $id, $part );",
  "\t\tif ( false ) {\n\t\t\treturn sprintf( 'edit the attachment %1$d (edit_post, for the part %2$s)', $id, $part );", 'pkg', U_RIGHTS)
e('writer-restore-type', 'restore_right(): the file a backed-up _wp_attached_file names of a type this account may upload', AW,
  "\t\t\t\tif ( ! empty( $check['proper_filename'] ) || ( ( empty( $check['type'] ) || empty( $check['ext'] ) ) && ! current_user_can( 'unfiltered_upload' ) ) ) {",
  "\t\t\t\tif ( false ) {", 'it', EDIT)

# ----------------------------------------------------- what lies at a path
e('state-link-on-way', 'target_state(): a link on the way is unsafe', ME,
  "\t\t\tif ( is_link( $at ) ) {\n\t\t\t\treturn 'unsafe';", "\t\t\tif ( false ) {\n\t\t\t\treturn 'unsafe';", 'pkg', U_STATE)
e('state-file-on-way', 'target_state(): a file on the way is unsafe', ME,
  "\t\t\tif ( ! is_dir( $at ) ) {\n\t\t\t\treturn 'unsafe';", "\t\t\tif ( false ) {\n\t\t\t\treturn 'unsafe';", 'pkg', U_STATE)
e('state-link-at', 'target_state(): a link at the path is another thing', ME,
  "\t\tif ( is_link( $at ) ) {\n\t\t\treturn 'other';", "\t\tif ( false ) {\n\t\t\treturn 'other';", 'pkg', U_STATE)
e('state-folder-at', 'target_state(): a folder at the path is another thing', ME,
  "\t\treturn 'file' === @filetype( $at ) ? 'file' : 'other';", "\t\treturn 'file';", 'pkg', U_STATE)


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
    return '/' + q(name) + '/'


out = []
out.append('''<?php
/**
 * The clauses of Z8 branch 10 (heikel/z8-medien): the kind attachment with
 * its files (decision 26): its canonical form with the file list, the
 * package of media (its lines, the export, the import and its index, a file
 * read out of its chunks), the write side (the file list of the target, the
 * paths, another file at a path, a link on the way, a path another
 * attachment names, the type by the name and by the content, every file
 * provided before the first is laid, the exclusive laying, the files the run
 * owns, the rights forward and back), the parent as a weak reference (the
 * report, the plan and its parent step) and the engine (the writer of the
 * kind, the files before the row, the check of the run, the files named on
 * the way back).
 *
 * Written by tests/mutation/gen/medien.py from the source; change the
 * entries there and run it again.
 *
 * Held by the integration suite (SyncMediaTest, SyncPackageTest; the sites
 * running, AB_IT_ROOT set) and the unit suite (SyncMediaPackageTest,
 * SyncReportTest, SyncRunTest, SyncDbKindsTest, SyncTermWriterTest). Not
 * listed: the texts of refusals, and what GRENZEN-MEDIEN.md names as not
 * listed.
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
open(os.path.join(ROOT, 'tests/mutation/lists/medien.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
