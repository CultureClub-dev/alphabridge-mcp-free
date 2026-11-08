#!/usr/bin/env python3
"""Writes tests/mutation/lists/inhalte.php: one entry per clause of Z8
branch 9 (heikel/z8-inhalte): the kind term (AB_MCP_Sync_Term_Writer, its
export, the lock of a boundary for terms), the field rule of the kind post
(decision 28), its slug, its sanitizers ahead, its featured image, the
locks of the canonical form (decisions 30, 31), and the engine that picks
the writer by the kind. Every search is checked to occur exactly once in
its file, and the test of its expect to be a method of the class its run
names, before the list is written; each entry runs that test only.

    python3 tests/mutation/gen/inhalte.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/inhalte.php). Development
only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
TW = 'includes/sync/class-sync-term-writer.php'
PW = 'includes/sync/class-sync-post-writer.php'
EN = 'includes/sync/class-sync-engine.php'
PU = 'includes/sync/class-sync-push.php'
GU = 'includes/sync/class-sync-guard.php'
DK = 'includes/sync/class-sync-db-kinds.php'
FILEVAR = {TW: '$tw', PW: '$pw', EN: '$en', PU: '$pu', GU: '$gu', DK: '$dk'}

RUNS = {
    'term': ('unit', 'SyncTermWriterTest'),
    'unit': ('unit', 'SyncRunTest'),
    'kinds': ('unit', 'SyncDbKindsTest'),
    'it': ('it', 'SyncContentTest'),
    'package': ('unit', 'SyncPackageTest'),
    'kindsx': ('unit', 'SyncKindsTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('inhalte/' + id_, clause, file, search, replace, run, expect))


# ------------------------------------------------------- the kind term
T_LOCKS = 'testWhatTheEngineOfTheTermDoesNotWriteIsRefusedWithItsLock'
T_TARGET = 'testTheTargetOfATermIsItsRowWithTheParentByItsPair'
T_NEW = 'testANewTermAsksTheRightsOfItsTaxonomyItsSlugAndItsSanitizersBeforeAnythingIsWritten'
T_HERE = 'testATermHereAsksItsRightItsGroupItsParentAndTheRightOfEveryKeyThatDiffers'
T_PARTS = 'testThePartsOfATermAreItsRowAndTheKeysThatDiffer'
T_RIGHTS = 'testTheRightsOfTheMetaOfATermAreAskedPerOperationThroughMapMetaCap'
T_WRITE = 'testTheRowOfATermIsWrittenThroughTheCoreAndAnIdWordPressGaveBackIsNotTheStepsOwn'
T_BACK = 'testTheRowOfATermGoesBackOnlyWhereItIsWhatTheStepWroteAndItsSlugAndParentAreFree'
T_GUARD = 'testTheLockOfABoundaryCoversATermItsMetaAndItsRestSaves'
T_BEFORE = 'testTheValueBeforeOfAPartOfATermIsShortAndNeverACredential'

e('term-taxonomy', 'target_of(): a term of a taxonomy the kind term does not own is refused', TW,
  "\t\tif ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || 'term' !== $answer['kind'] || 'z8' !== $answer['status'] ) {", "\t\tif ( false ) {", 'term', T_LOCKS)
e('term-line-lock', 'target_of(): what the canonical form of the staging term locks is refused with that lock', TW,
  "\t\t$held = AB_MCP_Sync_Post_Writer::line_lock( $line );\n\t\tif ( null !== $held ) {", "\t\t$held = AB_MCP_Sync_Post_Writer::line_lock( $line );\n\t\tif ( false ) {", 'term', T_LOCKS)
e('term-content', 'target_of(): a package without the content of the term writes nothing', TW,
  "|| ! is_array( $line['form'] ) || array() !== $line['content']['terms'] ) {", "|| ! is_array( $line['form'] ) || false ) {", 'term', T_LOCKS)
e('term-row-taxonomy', 'target_of(): the row and the identity name the same taxonomy', TW,
  "\t\tif ( $row['taxonomy'] !== $taxonomy ) {", "\t\tif ( false ) {", 'term', T_LOCKS)
e('term-group', 'target_of(): a staging term in a group of aliases is locked (term:term_group)', TW,
  "\t\tif ( '0' !== $row['term_group'] ) {", "\t\tif ( false ) {", 'term', T_LOCKS)
e('term-parent-pair', 'target_of(): the parent by its pair', TW,
  "\t\t\t$parent = (int) $mapped;", "\t\t\t$parent = (int) $parent;", 'term', T_TARGET)
e('term-parent-pending', 'target_of(): a parent the run creates first is pending (Eltern zuerst)', TW,
  "\t\t\tif ( null === $mapped && isset( $pending['term'][ $parent ] ) ) {", "\t\t\tif ( false ) {", 'term', T_LOCKS)
e('term-parent-unmapped', 'target_of(): a parent without a pair is refused', TW,
  "\t\t\tif ( null === $mapped ) {\n\t\t\t\treturn self::locked( 'term:reference_unmapped'", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'term:reference_unmapped'", 'term', T_LOCKS)
e('term-texts-rewritten', 'target_of(): the name and the description with the addresses of this site', TW,
  "\t\t\t$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );", "\t\t\t$texts[ $column ] = $row[ $column ];", 'term', T_TARGET)
e('term-create-meta', 'target_of(): a new term with meta is locked (decision 28)', TW,
  "\t\t\tif ( 'create' === $item['op'] ) {\n\t\t\t\treturn self::locked( 'term:create_with_meta'", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'term:create_with_meta'", 'term', T_LOCKS)
e('term-meta-rule', 'target_of(): a value the metadata API cannot store as it is locks with the lock of the term', TW,
  "$plan, $changed, 'term:meta_rule' );", "$plan, $changed );", 'term', T_LOCKS)
e('term-taxonomy-here', 'precheck(): a taxonomy not registered here is refused', TW,
  "\t\tif ( ! is_object( $object ) || ! isset( $object->cap->manage_terms, $object->cap->edit_terms, $object->cap->assign_terms ) ) {", "\t\tif ( false ) {", 'term', T_NEW)
e('term-here-gone', 'precheck(): a term of another taxonomy under this id is gone for the step', TW,
  "\t\t\tif ( ! is_array( $current ) || $current['row']['taxonomy'] !== $taxonomy ) {", "\t\t\tif ( ! is_array( $current ) ) {", 'term', T_HERE)
e('term-edit-term', 'precheck(): the right edit_term at the term here', TW,
  "\t\t\tif ( ! current_user_can( 'edit_term', (int) $live_id ) ) {\n\t\t\t\treturn self::rights(", "\t\t\tif ( false ) {\n\t\t\t\treturn self::rights(", 'term', T_HERE)
e('term-group-here', 'precheck(): a term of this site in a group of aliases is locked', TW,
  "\t\t\tif ( '0' !== (string) $current['row']['term_group'] ) {", "\t\t\tif ( false ) {", 'term', T_HERE)
e('term-create-rights', 'precheck(): a new term asks manage_terms, edit_terms and assign_terms of the taxonomy (decision 28)', TW,
  "\t\t\t\tif ( ! current_user_can( (string) $object->cap->$cap ) ) {", "\t\t\t\tif ( false ) {", 'term', T_NEW)
e('term-parent-hierarchical', 'precheck(): a parent in a taxonomy without parents is locked', TW,
  "\t\t\tif ( ! is_taxonomy_hierarchical( $taxonomy ) ) {", "\t\t\tif ( false ) {", 'term', T_HERE)
e('term-parent-here', 'precheck(): the parent has to be a term of the taxonomy here', TW,
  "\t\t\tif ( ! is_array( $found ) ) {\n\t\t\t\treturn self::locked( 'term:reference_unmapped'", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'term:reference_unmapped'", 'term', T_HERE)
e('term-parent-loop', 'precheck(): a parent that closes a loop is locked (wp_check_term_hierarchy_for_loops() would change other terms)', TW,
  "\t\t\tif ( null !== $loop ) {\n\t\t\t\treturn self::locked( 'term:parent', sprintf( 'the parent %1$d would close a loop", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'term:parent', sprintf( 'the parent %1$d would close a loop", 'term', T_HERE)
e('term-slug-empty', 'precheck(): an empty slug is refused (WordPress would make one)', TW,
  "\t\tif ( '' === $slug ) {\n\t\t\treturn self::locked( 'term:sanitizer'", "\t\tif ( false ) {\n\t\t\treturn self::locked( 'term:sanitizer'", 'term', T_NEW)
e('term-slug-holder', 'precheck(): a slug another term of the taxonomy holds is locked', TW,
  "\t\tif ( 0 !== $holder && $holder !== (int) $live_id ) {", "\t\tif ( false ) {", 'term', T_HERE)
e('term-slug-unique', 'precheck(): a new term whose slug WordPress would make unique is locked', TW,
  "\t\t\tif ( (string) $unique !== $slug ) {", "\t\t\tif ( false ) {", 'term', T_NEW)
e('term-sanitizer', 'precheck(): a field sanitize_term() would change is locked (decision 17)', TW,
  "\t\tif ( null !== $refusal ) {\n\t\t\treturn self::locked( 'term:sanitizer', $refusal );", "\t\tif ( false ) {\n\t\t\treturn self::locked( 'term:sanitizer', $refusal );", 'term', T_NEW)
e('term-sanitizer-compare', 'sanitizer_refusal(): the fields compared with what sanitize_term() makes of them', TW,
  "\t\t\tif ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {\n\t\t\t\treturn sprintf( 'field %s', $field );", "\t\t\tif ( false ) {\n\t\t\t\treturn sprintf( 'field %s', $field );", 'term', T_NEW)
e('term-name-empty', 'sanitizer_refusal(): an empty name is refused (WordPress refuses it)', TW,
  "\t\tif ( '' === trim( (string) $args['name'] ) ) {", "\t\tif ( false ) {", 'term', T_NEW)
e('term-meta-right', 'precheck(): a key that differs and that map_meta_cap refuses locks the term (decision 28)', TW,
  "\t\t\t\tif ( true !== $may ) {\n\t\t\t\t\treturn self::locked( '*:meta_without_rule'", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::locked( '*:meta_without_rule'", 'term', T_HERE)
e('term-sanitize-meta', 'precheck(): each written value through sanitize_meta() (decision 17)', TW,
  "\t\t\t\t\tif ( AB_MCP_Sync_Post_Writer::db_form( sanitize_meta( $key, $value, 'term', $taxonomy ) ) !== AB_MCP_Sync_Post_Writer::stored_form( $value ) ) {", "\t\t\t\t\tif ( false ) {", 'term', T_HERE)
e('term-meta-spelling', 'precheck(): two spellings of a key the database compares alike lock the term', TW,
  "\t\t\t\t\tif ( $one['key'] !== (string) $key ) {", "\t\t\t\t\tif ( false ) {", 'term', T_HERE)
e('term-differing-equal', 'differing(): a key equal on both sides is no part (decision 28)', TW,
  "\t\t\tif ( self::targets( $prepared, $key ) !== AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) ) {", "\t\t\tif ( true ) {", 'term', T_PARTS)
e('term-differing-b9', 'differing(): a key with the name of a credential is never written (B9)', TW,
  "\t\t\tif ( AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'term', T_PARTS)
e('term-way-row', 'WAY_ROW: the row of a term is on a way the lock does not refuse (decision 19)', TW,
  "\tconst WAY_ROW  = 'not_refusable';", "\tconst WAY_ROW  = 'refusable';", 'term', T_PARTS)
e('term-meta-right-edit', 'restore_ops(): the way back of a key edits its rows in place where both sides hold rows (edit_term_meta)', PW,
  "\t\tif ( array() !== $backup && array() !== $now ) {\n\t\t\t$ops[] = 'edit';", "\t\tif ( false ) {\n\t\t\t$ops[] = 'edit';", 'term', T_RIGHTS)
e('term-meta-right-add', 'restore_ops(): the way back adds the rows the step removed (add_term_meta)', PW,
  "\t\tif ( count( $backup ) > count( $now ) ) {", "\t\tif ( false ) {", 'term', T_RIGHTS)
e('term-meta-right-delete', 'restore_ops(): the way back removes the rows the step added (delete_term_meta)', PW,
  "\t\tif ( count( $backup ) < count( $now ) ) {", "\t\tif ( false ) {", 'term', T_RIGHTS)
e('term-restore-edit-term', 'restore_right(): edit_term first, for every part', TW,
  "\t\tif ( ! current_user_can( 'edit_term', $id ) ) {", "\t\tif ( false ) {", 'term', T_RIGHTS)
e('term-write-duplicate', 'write_row(): an id not above the highest before the insert is a term WordPress gave back, not the step\'s own', TW,
  "\t\tif ( $id <= $highest ) {", "\t\tif ( false ) {", 'term', T_WRITE)
e('term-write-split', 'write_row(): an update written under another id is refused', TW,
  "\t\t\tif ( ! is_array( $done ) || (int) $done['term_id'] !== (int) $live_id ) {", "\t\t\tif ( ! is_array( $done ) ) {", 'term', T_WRITE)
e('term-write-slashed', 'write_row(): the name slashed for wp_insert_term(), which unslashes it', TW,
  "\t\t$done = wp_insert_term( wp_slash( (string) $args['name'] ), $taxonomy, $fields );", "\t\t$done = wp_insert_term( (string) $args['name'], $taxonomy, $fields );", 'term', T_WRITE)
e('term-write-part-right', 'write_part(): the right of a meta key asked at the term right before the call', TW,
  "\t\tif ( true !== $may ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the term", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the term", 'term', T_WRITE)
e('term-back-written', 'restore_row(): a row changed since the step wrote it is left', TW,
  "\t\tif ( ! hash_equals( (string) $written, $hash ) ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'left',\n\t\t\t\t'note'  => 'The row of the term was changed", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'left',\n\t\t\t\t'note'  => 'The row of the term was changed", 'term', T_BACK)
e('term-back-slug', 'restore_row(): a backed-up slug another term holds now is left', TW,
  "\t\tif ( null === $holder || ( 0 !== $holder && $holder !== (int) $live_id ) ) {", "\t\tif ( false ) {", 'term', T_BACK)
e('term-back-parent', 'restore_row(): a backed-up parent that is gone or closes a loop is left', TW,
  "\t\tif ( $parent > 0 && ( ! is_array( AB_MCP_Sync_Rows::term( $parent, $taxonomy ) ) || null !== self::loop( (int) $live_id, $parent, $taxonomy ) ) ) {", "\t\tif ( false ) {", 'term', T_BACK)
e('term-back-sanitizer', 'restore_row(): a backed-up field the sanitizing would change is left', TW,
  "\t\t$refusal = self::sanitizer_refusal( $args, $taxonomy, (int) $live_id );\n\t\tif ( null !== $refusal ) {\n\t\t\treturn array(", "\t\t$refusal = self::sanitizer_refusal( $args, $taxonomy, (int) $live_id );\n\t\tif ( false ) {\n\t\t\treturn array(", 'term', T_BACK)
e('term-back-read', 'restore_row(): a row that does not read as the backup after the call is unclear, never restored', TW,
  "\t\tif ( ! is_array( $after ) || ! hash_equals( $before, self::row_hash( $after['row'] ) ) ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'unclear',\n\t\t\t\t'note'  => 'The row of the term was written back", "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'state' => 'unclear',\n\t\t\t\t'note'  => 'The row of the term was written back", 'term', T_BACK)
e('term-guard-taxonomy', 'guard_of(): the boundary of a term refuses new terms of its taxonomy (pre_insert_term)', TW,
  "\t\t\t'taxonomies' => array( (string) $taxonomy ),", "\t\t\t'taxonomies' => array(),", 'term', T_PARTS)
e('term-guard-term', 'guard_of(): the boundary covers the term here', TW,
  "\t\t\t'terms'      => null === $live_id ? array() : array( (int) $live_id => (string) $taxonomy ),", "\t\t\t'terms'      => array(),", 'term', T_PARTS)
e('term-before-b9', 'before_value(): a meta value of a key with the name of a credential is never shown', TW,
  "\t\t\t$text = AB_MCP_Sync_Post_Writer::meta_before( substr( $part, 5 ), AB_MCP_Sync_Post_Writer::meta_values( $backup, substr( $part, 5 ) ) );", "\t\t\t$text = implode( ', ', AB_MCP_Sync_Post_Writer::meta_values( $backup, substr( $part, 5 ) ) );", 'term', T_BEFORE)

# --------------------------------------------- the lock of a boundary, terms
e('guard-term-record', 'begin(): the terms the step writes are in the record', GU,
  "\t\t\t$record['terms'][ (string) (int) $id ] = (string) $taxonomy;", '', 'term', T_GUARD)
e('guard-term-meta', 'term_meta_by_object(): the meta of a covered term is not written', GU,
  "\t\treturn self::covers_term( $object_id ) ? false : $check;\n\t}\n\n\t/**\n\t * delete_term_metadata", "\t\treturn $check;\n\t}\n\n\t/**\n\t * delete_term_metadata", 'term', T_GUARD)
e('guard-term-delete-all', 'term_meta_delete(): a delete across every term is refused while one is covered', GU,
  "\t\t\treturn null !== $record && ! empty( $record['terms'] ) ? false : $check;", "\t\t\treturn $check;", 'term', T_GUARD)
e('guard-term-by-mid', 'term_meta_by_mid(): by the term a meta id belongs to', GU,
  "\t\treturn is_object( $meta ) && isset( $meta->term_id ) && isset( $record['terms'][ (string) (int) $meta->term_id ] ) ? false : $check;", "\t\treturn $check;", 'term', T_GUARD)
e('guard-term-rest', 'rest_term(): a REST save of a covered term is refused with 423', GU,
  "\t\tif ( null !== $record && isset( $record['terms'][ (string) $id ] ) ) {\n\t\t\treturn new WP_Error( self::CODE, self::message( $record ), array( 'status' => 423 ) );\n\t\t}\n\t\treturn $response;", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( self::CODE, self::message( $record ), array( 'status' => 423 ) );\n\t\t}\n\t\treturn $response;", 'term', T_GUARD)
e('guard-term-rest-controller', 'rest_term(): only a write through a controller of terms', GU,
  "\t\tif ( $id < 1 || ! $controller instanceof WP_REST_Terms_Controller ) {", "\t\tif ( $id < 1 ) {", 'term', T_GUARD)
e('guard-term-rest-read', 'rest_term(): a read is never refused', GU,
  "|| in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {", " ) {", 'term', T_GUARD)
e('guard-term-arm', 'arm(): the term meta filters are hooked', GU,
  "\t\tadd_filter( 'update_term_metadata', array( __CLASS__, 'term_meta_by_object' ), 1, 2 );\n", '', 'it', 'testAnotherWriterOfATermIsRefusedWhileItsStepWrites')
e('guard-term-arm-rest', 'arm(): the REST refusal of a covered term is hooked', GU,
  "\t\tadd_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_term' ), 1, 3 );\n", '', 'it', 'testAnotherWriterOfATermIsRefusedWhileItsStepWrites')

# --------------------------------------------- the export of a term
e('export-term-only', 'Kind_Term::export(): the menus (a kind of terms) write from branch 11 on', DK,
  "\t\tif ( 'term' !== $this->kind() ) {\n\t\t\treturn $this->not_here( 'export' );", "\t\tif ( false ) {\n\t\t\treturn $this->not_here( 'export' );", 'kinds', 'testWhatWritesIsNotInThisBranch')
e('export-term-b9', 'Kind_Term::export(): a meta row whose key is the name of a credential is never packed (B9)', DK,
  "\t\t\t\t\tif ( AB_MCP_Sync_Credentials::names_credential( (string) $m['key'], $extra ) ) {\n\t\t\t\t\t\tcontinue;", "\t\t\t\t\tif ( false ) {\n\t\t\t\t\t\tcontinue;", 'it', 'testTheMetaOfATermGoesByItsRightAndBackAndANewTermWithMetaIsLocked')

# --------------------------------------------- the package of objects: status and B9
EX = 'includes/sync/class-sync-export.php'
IV = 'includes/sync/class-sync-inventory.php'
EXCL = 'includes/sync/class-sync-exclusions.php'
FILEVAR.update({EX: '$ex', IV: '$iv', EXCL: '$excl'})
P_STATUS = 'testAnObjectWithoutTheStatusOfTheInventoryIsNeverPackedAndOneNamedAsACredentialIsCounted'
e('export-status-given', 'make_objects(): every result of export() gets the status of the rule of the inventory', EX,
  "\t\t\t\t$object['status']                 = AB_MCP_Sync_Inventory::status_of( $object );\n", '', 'it', 'testAnObjectWithTheNameOfACredentialNeverLandsInAPackageOfObjects')
e('export-sort-no-status', 'sort(): an object without a status is withheld, never packed (fail closed)', EX,
  "\t\t\t$needs = null === $status ? 'status' : AB_MCP_Sync_Export_Rights::withheld( $object );", "\t\t\t$needs = AB_MCP_Sync_Export_Rights::withheld( $object );", 'package', P_STATUS)
e('export-line-no-status', 'line(): an object without a status is a held line, without hash and content', EX,
  "\t\tif ( null === $status || 'b9' === $status || array() !== $credentials['mixed']", "\t\tif ( 'b9' === $status || array() !== $credentials['mixed']", 'package', P_STATUS)
e('inventory-status-again', 'status_of(): a status given once (z8) holds again', IV,
  "\t\tif ( null === $status || 'core' === $status || 'z8' === $status ) {", "\t\tif ( null === $status || 'core' === $status ) {", 'package', P_STATUS)
e('inventory-status-kept', 'status_of(): b9, guarded, unreachable and unreadable as the kind named them', IV,
  "\t\treturn in_array( $status, array( 'b9', 'guarded', 'unreachable', 'unreadable' ), true ) ? $status : 'locked';", "\t\treturn 'locked';", 'package', P_STATUS)
e('exclusion-category-children', 'OPTIONS: the cache of the hierarchy of the categories is group B', EXCL,
  ", 'wp_force_deactivated_plugins', 'category_children' );", ", 'wp_force_deactivated_plugins' );", 'kindsx', 'testEachExcludedNameIsNeverTakenOver')

# --------------------------------------------- the engine, by the kind
E_IT1 = 'testAChangedCategoryAndANewTagGoOverAndTheWayBackPutsTheChangedOneBackAndLeavesTheNewOneAsARest'
e('engine-pending-terms', 'pending(): a new term is pending for the check of what refers to it (branch 9)', EN,
  " && isset( $pending[ (string) $one['kind'] ] ) ) {", " && 'post' === $one['kind'] ) {", 'unit', 'testTheEngineKnowsWhatItCreatesInBothSpacesAndNoStepWritesAKindWithoutAWriter')
e('engine-check-no-writer', 'check_item(): no step writes a kind without a writer', EN,
  "\t\tif ( null === $w ) {\n\t\t\treturn self::finding( $item, sprintf( 'No step of this version writes the kind %s.', $item['kind'] ) );", "\t\tif ( false ) {\n\t\t\treturn self::finding( $item, sprintf( 'No step of this version writes the kind %s.', $item['kind'] ) );", 'unit', 'testTheEngineKnowsWhatItCreatesInBothSpacesAndNoStepWritesAKindWithoutAWriter')
e('engine-guard-terms', 'boundary(): the lock of the boundary covers the term the step writes', EN,
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], array(), $covers['terms'] );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message(), $journal );",
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], array() );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message(), $journal );", 'it', 'testAnotherWriterOfATermIsRefusedWhileItsStepWrites')
e('engine-revert-guard-terms', 'revert_boundary(): the lock of the way back covers the term', EN,
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], array(), $covers['terms'] );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message() );",
  "\t\t$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], array() );\n\t\tif ( is_wp_error( $guard ) ) {\n\t\t\treturn self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message() );", 'it', 'testAnotherWriterOfATermIsRefusedWhileItsStepWrites')
e('engine-post-by-kind', 'run_post(): the caches of a written term by the writer of its kind', EN,
  "\t\t\t\t$ids[] = array( null === self::writer( $kind ) ? 'post' : $kind, (int) $one['result']['live_ref'] );", "\t\t\t\t$ids[] = array( 'post', (int) $one['result']['live_ref'] );", 'it', E_IT1)
e('engine-verify-by-kind', 'run_verify(): a written term against its target by the writer of its kind', EN,
  "\t\t\t\t$w       = self::writer( isset( $one['parts']['object']['kind'] ) ? (string) $one['parts']['object']['kind'] : 'post' );", "\t\t\t\t$w       = self::writer( 'post' );", 'it', E_IT1)
e('engine-pair-by-kind', 'pair_for_new(): the base of a new term by the writer of its kind', EN,
  "\t\t$w    = self::writer( $item['kind'] );\n\t\t$hash = null === $w ? null : $w::live_hash( $live_id, self::context( $run )['source'] );", "\t\t$w    = self::writer( 'post' );\n\t\t$hash = null === $w ? null : $w::live_hash( $live_id, self::context( $run )['source'] );", 'it', E_IT1)

# --------------------------------------------- the plan
e('plan-term-taxonomy', 'verdict(): a term of a taxonomy the term engine does not write is locked with Z8d', PU,
  "\t\t\tif ( ! in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Term_Writer::TAXONOMIES, true ) ) {", "\t\t\tif ( false ) {", 'unit', 'testWhichEntryOfAReportIsAStepLockedOrNothing')
e('plan-item-kind', 'verdict(): the item carries the kind of the entry', PU,
  "\t\t\t\t'kind'        => (string) $entry['kind'],", "\t\t\t\t'kind'        => 'post',", 'unit', 'testWhichEntryOfAReportIsAStepLockedOrNothing')
e('plan-order-space', 'order(): a new term in the space of terms, a new post in the space of posts', PU,
  "\t\t\t\t$at[ ( 'term' === $item['kind'] ? 'term' : 'post' ) . \"\\0\" . $item['staging_ref'] ] = $n;", "\t\t\t\t$at[ 'post' . \"\\0\" . $item['staging_ref'] ] = $n;", 'unit', 'testTheOrderOfTheGraphPutsANewTermBeforeThePostThatIsFiledUnderIt')

# --------------------------------------------- the kind post, branch 9
P_RULE = 'testTheRuleOfThisVersionForMetaKeys'
P_LOCKS = 'testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock'
P_TYPES = 'testATypeTheTableLocksAndWhatTheCanonicalFormLocksAreRefusedWithTheirOwnLock'
P_FIELD = 'testTheFieldRuleAsksEveryOperationOfAKeyThatDiffersAndAPageBuilderKeyNeedsUnfilteredHtml'
P_DELETE = 'testRemovingAMetaKeyOnlyThisSiteHasNeedsTheRightToDeleteIt'
P_IMAGE = 'testTheFeaturedImageGoesThroughItsOwnApiToAnAttachmentWordPressShowsAsAnImage'
P_SLUG = 'testTheSlugIsWhatWordPressWouldStoreOrTheStepIsLocked'
e('post-type-lock', 'target_of(): a type the table locks (a changeset, decision 30) is refused with its own lock', PW,
  "\t\tif ( 'locked' === $answer['status'] ) {", "\t\tif ( false ) {", 'unit', P_TYPES)
e('post-line-lock', 'target_of(): what the canonical form of the staging object locks (a page builder, decision 31) is refused with that lock', PW,
  "\t\t$held = self::line_lock( $line );\n\t\tif ( null !== $held ) {", "\t\t$held = self::line_lock( $line );\n\t\tif ( false ) {", 'unit', P_TYPES)
e('post-line-lock-unread', 'line_lock(): a lock this version does not read refuses as well (fail closed)', PW,
  "\t\treturn array() === $locks ? null : self::locked( 'post:lock_unread', 'the object of the staging site carries a lock this version does not read' );", "\t\treturn null;", 'unit', P_TYPES)
e('post-live-lock', 'live_rule(): what the canonical form of the post here locks is refused', PW,
  "\t\t$held = self::read_lock( self::live_read( (int) $live_id, $source ), 'the post of this site' );\n\t\tif ( null !== $held ) {", "\t\t$held = self::read_lock( self::live_read( (int) $live_id, $source ), 'the post of this site' );\n\t\tif ( false ) {", 'unit', P_TYPES)
e('post-target-skip', 'target_of(): what the comparison leaves out, and a credential, is no part of the target', PW,
  "\t\t\tif ( '' === $key || AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {", "\t\t\tif ( '' === $key ) {", 'unit', P_LOCKS)
e('post-thumbnail-pair', 'target_of(): the featured image by the pair of its attachment', PW,
  "\t\t\t\tif ( null === $mapped || isset( $meta[ $key ] ) ) {", "\t\t\t\tif ( false ) {", 'unit', P_LOCKS)
e('post-thumbnail-mapped', 'target_of(): the featured image written as the id of the attachment here', PW,
  "\t\t\t\t$meta[ $key ][] = (string) (int) $mapped;", "\t\t\t\t$meta[ $key ][] = $value;", 'unit', P_LOCKS)
e('post-roundtrip', 'meta_value(): a serialized value serialize() would not give back byte for byte is locked', PW,
  "\t\t\tif ( serialize( $read['value'] ) !== $text ) {", "\t\t\tif ( false ) {", 'unit', P_LOCKS)
e('post-new-protected', 'new_meta_allowed(): a protected key of a new post is answered at the object only', PW,
  "\t\tif ( is_protected_meta( $key, 'post' ) ) {\n\t\t\treturn false;", "\t\tif ( false ) {\n\t\t\treturn false;", 'unit', P_RULE)
e('post-new-auth', 'new_meta_allowed(): a key with an authorization filter of its own is answered at the object only', PW,
  "\t\treturn self::auth_follows_post( \"auth_post_meta_{$key}_for_{$type}\" ) && self::auth_follows_post( \"auth_post_meta_{$key}\" );", "\t\treturn true;", 'unit', P_RULE)
e('post-new-auth-subtype', 'new_meta_allowed(): also by the subtype (register_post_meta() with an auth_callback)', PW,
  "\t\treturn self::auth_follows_post( \"auth_post_meta_{$key}_for_{$type}\" ) && self::auth_follows_post( \"auth_post_meta_{$key}\" );", "\t\treturn self::auth_follows_post( \"auth_post_meta_{$key}\" );", 'unit', P_RULE)
e('post-differing-equal', 'differing(): a key equal on both sides is no part (decision 28)', PW,
  "\t\t\tif ( self::targets( $prepared, $key ) !== self::meta_values( $current, $key ) ) {", "\t\t\tif ( true ) {", 'unit', P_RULE)
e('post-differing-excluded', 'differing(): an excluded key and a credential are never written', PW,
  "\t\t\tif ( AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'unit', P_RULE)
e('post-meta-refused', 'precheck(): a key that differs and that map_meta_cap refuses locks the post (*:meta_without_rule)', PW,
  "\t\t\t\tif ( true !== $may ) {\n\t\t\t\t\treturn self::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the post", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn self::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the post", 'unit', P_DELETE)
e('post-meta-edit', 'meta_ops(): edit_post_meta where one row stays one row (update_metadata())', PW,
  "\t\t\treturn array( 'edit' );", "\t\t\treturn array();", 'unit', P_DELETE)
e('post-meta-add', 'meta_ops(): add_post_meta where values are to be stored', PW,
  "\t\tif ( array() !== $to ) {\n\t\t\t$ops[] = 'add';", "\t\tif ( false ) {\n\t\t\t$ops[] = 'add';", 'unit', P_FIELD)
e('post-builder-key', 'precheck(): a key of a page builder needs unfiltered_html', PW,
  "\t\t\tif ( null !== AB_MCP_Builders::markup_meta_refusal( $key ) ) {\n\t\t\t\treturn self::rights(", "\t\t\tif ( false ) {\n\t\t\t\treturn self::rights(", 'unit', P_FIELD)
e('post-sanitize-meta', 'precheck(): each written value through sanitize_meta() (decision 17)', PW,
  "\t\t\t\tif ( self::db_form( sanitize_meta( (string) $key, $value, 'post', $type ) ) !== self::stored_form( $value ) ) {", "\t\t\t\tif ( false ) {", 'unit', P_FIELD)
e('post-sanitizer-fields', 'precheck(): the other fields of the row through sanitize_post() as well', PW,
  "\t\t$fields    = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered', 'comment_status', 'ping_status', 'post_mime_type' );", "\t\t$fields    = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered' );", 'unit', P_FIELD)
e('post-spelled-template', 'precheck(): the template the row writes is asked for other spellings, equal or not', PW,
  "\t\t\t\t$spelled[] = '_wp_page_template';", '', 'unit', P_FIELD)
e('post-thumbnail-image', 'precheck(): the featured image names an attachment WordPress shows as an image', PW,
  "\t\t\tif ( 'attachment' !== AB_MCP_Sync_Rows::post_type_of( $image ) || '' === (string) wp_get_attachment_image( $image, 'thumbnail' ) ) {", "\t\t\tif ( false ) {", 'unit', P_IMAGE)
e('post-thumbnail-write', 'write_part(): the featured image through set_post_thumbnail()', PW,
  "\t\t\tif ( '_thumbnail_id' === $key ) {\n\t\t\t\treturn self::write_thumbnail( (int) $live_id, $values );", "\t\t\tif ( false ) {\n\t\t\t\treturn self::write_thumbnail( (int) $live_id, $values );", 'unit', P_IMAGE)
e('post-write-part-right', 'write_part(): the right of a key asked at the post right before the call; refused at a new post, the object stays as a rest', PW,
  "\t\t\t\tif ( true !== $may ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the post was not written", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the post was not written", 'it', 'testANewPostWithAPlainKeyGoesAndOneWhoseRightTheNewPostRefusesStaysAsARest')
e('post-slug-pending', 'slug_refusal(): a pending post of an account that may not publish it loses its slug', PW,
  "\t\t\tif ( ! $may ) {\n\t\t\t\treturn self::locked( 'post:slug_collision', sprintf( 'WordPress drops the slug", "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'post:slug_collision', sprintf( 'WordPress drops the slug", 'unit', P_SLUG)
e('post-slug-empty', 'slug_refusal(): a published post without a slug gets one from its title', PW,
  "\t\t\treturn in_array( $status, $drafts, true ) ? null : self::locked( 'post:slug_collision', sprintf( 'the %s has no slug", "\t\t\treturn null ? null : self::locked( 'post:slug_collision', sprintf( 'the %s has no slug", 'unit', P_SLUG)
e('post-slug-sanitize', 'slug_refusal(): the slug as sanitize_title() makes it', PW,
  "\t\tif ( $made !== $slug ) {", "\t\tif ( false ) {", 'unit', P_SLUG)
e('post-slug-draft', 'slug_refusal(): WordPress keeps the slug of a draft as it is', PW,
  "\t\tif ( in_array( $status, $drafts, true ) ) {\n\t\t\t// WordPress keeps the slug of a draft as it is (wp_unique_post_slug()).", "\t\tif ( false ) {\n\t\t\t// WordPress keeps the slug of a draft as it is (wp_unique_post_slug()).", 'unit', P_SLUG)
e('post-slug-trashed', 'slug_refusal(): a post in the trash with the slug would be renamed (__trashed), a row of another post', PW,
  "\t\tif ( null === $trashed || 0 !== $trashed ) {", "\t\tif ( false ) {", 'it', 'testANewPageWhoseSlugAPostInTheTrashHoldsIsLockedBeforeAnythingIsWritten')
e('post-slug-unique', 'slug_refusal(): the slug WordPress would make unique (wp_unique_post_slug())', PW,
  "\t\tif ( (string) $unique !== $slug ) {", "\t\tif ( false ) {", 'unit', P_SLUG)
e('post-slug-check', 'precheck(): the slug rule is asked', PW,
  "\t\t\t$refusal = self::slug_refusal( $prepared, $live_id, is_array( $live ) ? (string) $live['post_name'] : null );\n\t\t\tif ( null !== $refusal ) {", "\t\t\t$refusal = self::slug_refusal( $prepared, $live_id, is_array( $live ) ? (string) $live['post_name'] : null );\n\t\t\tif ( false ) {", 'unit', P_SLUG)
e('post-journal-differing', 'journal_parts(): the meta parts of a post here are the keys that differ', PW,
  "\t\t$keys  = is_array( $current ) ? self::differing( $prepared, $current ) : array_keys( $prepared['meta'] );", "\t\t$keys  = array_keys( $prepared['meta'] );", 'it', 'testAPluginKeyEqualOnBothSidesStaysUntouchedAndOneThatDiffersWaitsForARuleOfTheSite')


# --------------------------------------------- Codex round 1 on branch 9
C_A1 = 'testAHookThatChangesAKeyEqualOnBothSidesWithTheRowDeviatesTheObjectAndTheWayBackLeavesIt'
e('engine-row-besides', 'write_parts(): what the row call changed besides the parts of the journal (a key equal on both sides) deviates the object before the next call (Codex round 1, A1)', EN,
  "\t\t\t\t\tif ( null !== $changed_besides ) {", "\t\t\t\t\tif ( false ) {", 'it', C_A1)
e('engine-row-besides-all', 'changed_besides(): every part read before and after the row call, planned or not (part_names())', EN,
  "\t\tforeach ( self::part_names( is_array( $before ) ? $before : array(), $after ) as $name ) {", "\t\tforeach ( array_keys( $journaled ) as $name ) {", 'it', C_A1)
C_A2P = 'testTheRightsOfAKeyOfSeveralRowsAreTheOperationsTheWriteMakes'
C_A2T = 'testTheRightsOfAKeyOfSeveralRowsOfATermAreTheOperationsTheWriteMakes'
e('post-meta-ops-one-row', 'meta_ops(): edit alone only where one row stays one row; a key of several rows is removed and added anew (Codex round 1, A2)', PW,
  "\t\tif ( 1 === count( $to ) && 1 === count( $from ) ) {\n\t\t\treturn array( 'edit' );", "\t\tif ( array() !== $to && array() !== $from ) {\n\t\t\treturn array( 'edit' );", 'unit', C_A2P)
e('post-ops-right', 'ops_right(): the right of every operation asked through map_meta_cap', PW,
  "\t\t\tif ( ! current_user_can( $cap, (int) $live_id, $key ) ) {", "\t\t\tif ( false ) {", 'unit', C_A2P)
e('post-write-meta-ops', 'write_meta(): the operations of meta_ops(), no other (update_metadata() only where one row stays one)', PW,
  "\t\tif ( array( 'edit' ) === $ops ) {", "\t\tif ( array() !== $values && array() !== $current ) {", 'unit', C_A2P)
e('term-meta-right-ops', 'meta_right(): the rights of a term key are the operations write_meta() makes (Codex round 1, A2)', TW,
  "\t\treturn AB_MCP_Sync_Post_Writer::ops_right( 'term', $live_id, $key, AB_MCP_Sync_Post_Writer::meta_ops( $to, $from ) );", "\t\treturn true;", 'term', C_A2T)
e('term-restore-ops', 'restore_right(): the way back of a term key asks the operations of restore_meta(), row by row', TW,
  "AB_MCP_Sync_Post_Writer::restore_ops( AB_MCP_Sync_Post_Writer::meta_values( $backup, $key )", "AB_MCP_Sync_Post_Writer::meta_ops( AB_MCP_Sync_Post_Writer::meta_values( $backup, $key )", 'term', C_A2T)
C_B3P = 'testASanitizerIsComparedByTheBytesWordPressWouldStore'
C_B3T = 'testASanitizerOfTheMetaOfATermIsComparedByTheBytesWordPressWouldStore'
e('post-sanitize-bytes', 'precheck(): the sanitized value compared by the bytes WordPress would store, not as a PHP value (Codex round 1, B3)', PW,
  "\t\t\t\tif ( self::db_form( sanitize_meta( (string) $key, $value, 'post', $type ) ) !== self::stored_form( $value ) ) {", "\t\t\t\tif ( sanitize_meta( (string) $key, $value, 'post', $type ) !== $value ) {", 'unit', C_B3P)
e('term-sanitize-bytes', 'precheck(): the sanitized value of a term key compared by the bytes WordPress would store (Codex round 1, B3)', TW,
  "\t\t\t\t\tif ( AB_MCP_Sync_Post_Writer::db_form( sanitize_meta( $key, $value, 'term', $taxonomy ) ) !== AB_MCP_Sync_Post_Writer::stored_form( $value ) ) {", "\t\t\t\t\tif ( sanitize_meta( $key, $value, 'term', $taxonomy ) !== $value ) {", 'term', C_B3T)
e('db-form-bool', 'db_form(): a bool as $wpdb writes it (1 or empty)', PW,
  "\t\t\treturn $value ? '1' : '';", "\t\t\treturn null;", 'unit', C_B3P)
e('db-form-serialize', 'db_form(): maybe_serialize() first, as update_metadata() runs it', PW,
  "\t\t$value = maybe_serialize( $value );\n\t\tif ( is_bool( $value ) ) {", "\t\tif ( is_bool( $value ) ) {", 'unit', C_B3P)
RV = 'includes/sync/class-sync-revert.php'
FILEVAR.update({RV: '$rv'})
C_B4 = 'testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell'
T_B4 = 'testTheLoopOfParentsIsAskedOfTheStateTheRunLeaves'
e('engine-planned-parents', 'run_check(): the check asks a loop of parents of the state the run leaves (Codex round 1, B4)', EN,
  "\t\t$ctx['parents'] = self::planned_parents( $items, $lines, $ctx );\n", '', 'it', C_B4)
e('term-prepared-parents', 'target_of(): the parents the run gives the terms it writes go with the target into the check', TW,
  "\t\t\t'parents'     => isset( $ctx['parents'] ) && is_array( $ctx['parents'] ) ? $ctx['parents'] : array(),", "\t\t\t'parents'     => array(),", 'term', T_B4)
e('term-loop-planned', 'loop(): a term the run writes stands with the parent the run gives it', TW,
  "\t\t\treturn isset( $planned[ (int) $id ] ) ? (int) $planned[ (int) $id ] : wp_get_term_taxonomy_parent_id( (int) $id, $tax );", "\t\t\treturn wp_get_term_taxonomy_parent_id( (int) $id, $tax );", 'term', T_B4)
C_B4R2 = 'testAChainOfCategoriesTurnedOverGoesOverAndBack'
U_DEPTH = 'testTheTermsOfARunAreWrittenFromTheRootOfTheTreeItLeavesDown'
e('push-order-depth', 'order(): the terms by their depth in the tree the run leaves, ancestors first (Codex rounds 1 and 2, B4)', PU,
  "\t\t\t\t\t\treturn array( 0, isset( $items[ $n ]['depth'] ) ? (int) $items[ $n ]['depth'] : 0, (int) $n );", "\t\t\t\t\t\treturn array( 0, 0, (int) $n );", 'unit', U_DEPTH)
e('push-depth-set', 'plan(): the depth of each term goes with its item into the order', PU,
  "\t\t\t$items[ $n ]['depth'] = $depth;\n", '', 'it', C_B4R2)
e('push-depth-given', 'term_depths(): a term of the run stands under the parent the run gives it when the run writes that parent too', PU,
  "\t\t\t\t$given[ $node ] = $node_of[ (string) $item['parent_ref'] ];", "\t\t\t\t$given[ $node ] = false;", 'unit', U_DEPTH)
e('push-depth-mapped', 'term_depths(): else under its parent by the pair', PU,
  "\t\t\t\t$given[ $node ] = null === $here ? false : 'l:' . (int) $here;", "\t\t\t\t$given[ $node ] = false;", 'unit', U_DEPTH)
e('push-depth-live-parent', 'term_depths(): a term the run does not write stands under its parent here', PU,
  "\t\t\t\t\t$parent = (int) wp_get_term_taxonomy_parent_id( (int) substr( $at, 2 ), $taxonomy );", "\t\t\t\t\t$parent = 0;", 'unit', U_DEPTH)
e('push-parent-ref', 'plan(): the parent of a term from its references, for the order', PU,
  "\t\t\t\t\t\t$item['parent_ref'] = (string) (int) $ref['id'];\n", '', 'it', C_B4)
e('revert-last-first', 'plan(): the way back goes from the last step to the first (Codex round 1, B4)', RV,
  "\t\t$items = array_reverse( $items );\n", '', 'it', C_B4)
e('post-slug-trashed-draft', 'slug_refusal(): a post in the trash with the slug is renamed for a draft as well, so a draft asks it too (Codex round 1, C)', PW,
  "\t\t$trashed = self::trashed_holder( $slug, $id );\n\t\tif ( null === $trashed || 0 !== $trashed ) {", "\t\t$trashed = in_array( $status, $drafts, true ) ? 0 : self::trashed_holder( $slug, $id );\n\t\tif ( null === $trashed || 0 !== $trashed ) {", 'unit', P_SLUG)

# --------------------------------------------- the locks of the kind post after the decision on branch 9b
# Moved over with branch 9b (heikel/z8-vorlagen): post:engine_type is gone,
# the five core types are written (their clauses: tests/mutation/gen/vorlagen.py);
# these two hold what took the place of the lock in target_of().
e('post-type-branch-9b', 'target_of(): the five core types of branch 9b have a target since branch 9b (post:engine_type gone)', PW,
  "\t\tif ( ! in_array( $type, self::TYPES, true ) ) {\n\t\t\t// A type of another kind", "\t\tif ( ! in_array( $type, array( 'post', 'page' ), true ) ) {\n\t\t\t// A type of another kind", 'unit', P_LOCKS)
e('post-type-other-kind', 'target_of(): a type of another kind is never written by the engine of the kind post (post:other_kind)', PW,
  "\t\t\treturn self::locked( 'post:other_kind', sprintf( 'post type %s', $type ) );", "\t\t\treturn self::locked( 'post:lock_unread', sprintf( 'post type %s', $type ) );", 'unit', P_LOCKS)
e('post-status-trash', 'target_of(): the trash is a deletion, with the deletions (post:in_trash, Z8d)', PW,
  "$key = 'trash' === $status ? 'post:in_trash' :", "$key = 'trash' === $status ? 'post:status_rule' :", 'unit', P_LOCKS)
e('post-status-auto-draft', 'target_of(): an auto-draft is never taken over (post:auto_draft)', PW,
  "( 'auto-draft' === $status ? 'post:auto_draft' : 'post:status_rule' )", "( 'auto-draft' === $status ? 'post:status_rule' : 'post:status_rule' )", 'unit', P_LOCKS)
e('post-status-rule', 'target_of(): any other status waits for its field rule (post:status_rule, Z8d)', PW,
  "( 'auto-draft' === $status ? 'post:auto_draft' : 'post:status_rule' )", "( 'auto-draft' === $status ? 'post:auto_draft' : 'post:auto_draft' )", 'unit', P_LOCKS)
e('post-content-missing', 'target_of(): a package without the content of the object writes nothing (post:content_missing)', PW,
  "\t\t\treturn self::locked( 'post:content_missing', 'the package carries no content of the object (a row of it could not be read on the staging site)' );", "\t\t\treturn self::locked( 'post:lock_unread', 'the package carries no content of the object (a row of it could not be read on the staging site)' );", 'unit', P_LOCKS)
e('post-type-unregistered', 'precheck(): a post type this site does not register is locked before anything is written (post:type_unregistered)', PW,
  "\t\tif ( ! is_object( $pto ) || ! isset( $pto->cap ) ) {\n\t\t\treturn self::locked( 'post:type_unregistered',", "\t\tif ( false ) {\n\t\t\treturn self::locked( 'post:type_unregistered',", 'unit', P_LOCKS)
e('post-read-lock-unread', 'read_lock(): a lock of the post of this site this version does not read locks as well (post:lock_unread)', PW,
  "\t\treturn self::locked( 'post:lock_unread', sprintf( '%s carries a lock this version does not read', (string) $what ) );", "\t\treturn null;", 'unit', P_TYPES)
e('push-token-order', 'plan(): the state the token is bound to covers the order of the steps it confirms (Codex round 3, B)', PU,
  "\t\t\t\t\t'order'       => $order,\n", '', 'it', 'testATokenWhoseOrderOfStepsNoLongerHoldsIsStale')
e('post-restore-markup', 'restore_right(): a key of a page builder goes back only with unfiltered_html, as forward (GitHub Codex round 1 on free#138, P2)', PW,
  "\t\t\tif ( null !== AB_MCP_Builders::markup_meta_refusal( $key ) ) {\n\t\t\t\treturn sprintf( 'write the page-builder meta key %s of the post (unfiltered_html)', $key );", "\t\t\tif ( false ) {\n\t\t\t\treturn sprintf( 'write the page-builder meta key %s of the post (unfiltered_html)', $key );", 'unit', 'testTheWayBackAsksTheRightsAtTheObjectAsTheForwardWriteDoesBeforeAPartGoesBack')

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
 * The clauses of Z8 branch 9 (heikel/z8-inhalte): the kind term
 * (AB_MCP_Sync_Term_Writer: the taxonomies it owns, the locks of its
 * target, the parent by its pair or pending, the rights of a new term and
 * of a term here, its slug, its sanitizers, the rights per meta key, the
 * keys that differ, the row through the core, an id WordPress gave back,
 * the way back of its row, the lock of its boundary), its export, the
 * field rule of the kind post (decision 28: the keys that differ, the
 * operations per key through map_meta_cap, a new post's keys, the page
 * builder key, sanitize_meta(), the featured image through its own API),
 * its slug (what wp_insert_post() would store), the locks of the canonical
 * form on both sides (decisions 30, 31), and the engine and the plan by the
 * kind.
 *
 * Written by tests/mutation/gen/inhalte.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncTermWriterTest, SyncRunTest, SyncDbKindsTest)
 * and, for what two WordPress sites show, by the integration suite
 * (SyncContentTest; the sites running, AB_IT_ROOT set). Not listed: the
 * texts of refusals (AnswerWayTest), the reads that fail (no test site
 * refuses a read), and what GRENZEN-INHALTE.md names as not listed.
 *
 * @package AlphaBridge_MCP
 */

$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$tw = 'includes/sync/class-sync-term-writer.php';
$pw = 'includes/sync/class-sync-post-writer.php';
$en = 'includes/sync/class-sync-engine.php';
$pu = 'includes/sync/class-sync-push.php';
$gu = 'includes/sync/class-sync-guard.php';
$dk = 'includes/sync/class-sync-db-kinds.php';
$ex = 'includes/sync/class-sync-export.php';
$iv = 'includes/sync/class-sync-inventory.php';
$excl = 'includes/sync/class-sync-exclusions.php';
$rv = 'includes/sync/class-sync-revert.php';

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
open(os.path.join(ROOT, 'tests/mutation/lists/inhalte.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
