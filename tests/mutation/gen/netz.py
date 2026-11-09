#!/usr/bin/env python3
"""Writes tests/mutation/lists/netz.php: one entry per clause of Z8 branch
11b (heikel/z8-netz; Thomas, 08.11.2026 abends, decisions.md): the net over
the raw value (AB_MCP_Sync_Net::check(), branch 4) counts the credentials
of a meta value and of the value of an option in the form WordPress hands
them out, unpacked (texts(): every key and every leaf on its own, nested
serialized texts unpacked in their turn through
AB_MCP_Sync_Canon::unserialized(), within the depth and the layers of the
readers; a text that does not serialize back byte for byte read whole as
well; a text that does not unpack or holds an object locks), a structure (a
widget instance) the same way with its keys; the references stay counted
over the raw text (strings(): the keys of a structure counted as well); the
kinds (meta_form(), option(), stored_column()) and the export
(checked_content(), check_unit()) ask for that form alike.

Every search is checked to occur exactly once in its file, and the test of
its expect to be a method of the class its run names, before the list is
written; each entry runs that test only.

    python3 tests/mutation/gen/netz.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/netz.php). Development only:
/tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
NT = 'includes/sync/class-sync-net.php'
DK = 'includes/sync/class-sync-db-kinds.php'
EX = 'includes/sync/class-sync-export.php'
FILEVAR = {NT: '$nt', DK: '$dk', EX: '$ex'}

RUNS = {
    'net': ('unit', 'SyncNetTest'),
    'u': ('unit', 'SyncStructureWritersTest'),
    'it': ('it', 'SyncStructureTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('netz/' + id_, clause, file, search, replace, run, expect))


# The tests.
N_PROSE = 'testProseInASerializedValueIsProseWhereTheValueIsReadUnpacked'
N_CRED = 'testACredentialInASerializedValueLocksWhereverItStands'
N_UNREAD = 'testAStoredValueThatDoesNotUnpackOrHoldsAnObjectIsNeverClean'
N_STRUCT = 'testEachKeyAndLeafOfAStructureIsReadOnItsOwn'
N_NAMED = 'testTheNetLocksANameTheCheckDidNotNameAndNotesIt'
N_TEXTS = 'testTheNetReadsTheTextsOfAStructureAndNothingOfAnEmptyValue'
U_FORM = 'testTheFinishedContentReadsSerializedMetaAndAnOptionUnpackedAsTheirKinds'
IT_PROSE = 'testProseInASerializedValueOfMetaAndOfACoreOptionGoesThroughTheReportAndIntoThePackage'
IT_CRED = 'testACredentialInASerializedValueOfMetaAndOfAnOptionStillHoldsItInTheReportAndThePackage'
N_LEAFREAD = 'testALeafTheNetDoesNotReadToItsEndLocksAsAColumnDoes'
N_DEPTH = 'testBeyondTheDepthTheUnitIsNotReadToItsEnd'

# ------------------------------------------------------- check(): the form
APART = "\t\tif ( is_array( $raw ) || ( $stored && is_string( $raw ) && is_serialized( $raw ) ) ) {"
e('apart-stored', 'check(): a stored value that is serialized: its texts each on their own', NT,
  APART, "\t\tif ( is_array( $raw ) ) {", 'net', N_PROSE)
e('apart-structure', 'check(): a structure: its texts each on their own', NT,
  APART, "\t\tif ( $stored && is_string( $raw ) && is_serialized( $raw ) ) {", 'net', N_STRUCT)
e('apart-only-stored', 'check(): a text WordPress hands out as stored (a column) read as its text', NT,
  APART, "\t\tif ( true ) {", 'net', N_PROSE)
e('structure-layer', 'check(): a structure is unpacked already, its layers counted from one as AB_MCP_Sync_Canon::structure() counts them', NT,
  "\t\t\t\tis_array( $raw ) ? 1 : 0,", "\t\t\t\t0,", 'net', N_STRUCT)
e('place', 'check(): the place of a text in the lock (positions, never a key or a value)', NT,
  "\t\t\t\t\t$place  = $at . ':net:' . ( '' === $where ? '' : $where . ':' );",
  "\t\t\t\t\t$place  = $at . ':net:';", 'net', N_CRED)
e('texts-each', 'check(): the credentials counted in each text of the value', NT,
  "\t\t\t\t\tself::count_credentials( $acc, (string) $kind, (string) $at, $place, self::layers( $one, $deep, $unread ), $named, $extra, $unreadable );\n",
  "", 'net', N_CRED)
e('raw-whole', 'check(): the credentials of a text read as it is stored counted in its layers', NT,
  "\t\t\tself::count_credentials( $acc, (string) $kind, (string) $at, $at . ':net:', $layers, $named, $extra, $unreadable );\n",
  "", 'net', N_NAMED)
e('count-once', 'count_credentials(): one lock and one note per unit, over its texts as well', NT,
  "\t\t\tif ( $unreadable ) {\n\t\t\t\treturn;\n\t\t\t}",
  "\t\t\tif ( false ) {\n\t\t\t\treturn;\n\t\t\t}", 'net', N_CRED)
# Codex round 1 of branch 11b, A2: a text not read to its end locks as a column.
LEAF_NOT_READ = "\t\t\t\t\tself::not_read( $acc, (string) $kind, $place, $unread, $deep ? $at . ':net' . ( '' === $where ? '' : ':' . $where ) : null );\n"
e('leaf-not-read', 'check(): what the layers of a text of the value do not read to its end locks the unit, as for a column (A2)', NT,
  LEAF_NOT_READ, "", 'net', N_LEAFREAD)
e('leaf-not-read-it', 'check(): a leaf too deep or not readable is held in the report and the package (A2)', NT,
  LEAF_NOT_READ, "", 'it', IT_CRED)
e('leaf-deep', 'check(): a text of the value read deeper than the net reads locks (A2)', NT,
  "$deep ? $at . ':net' . ( '' === $where ? '' : ':' . $where ) : null );",
  "null );", 'net', N_LEAFREAD)
e('raw-not-read', 'check(): what the layers of the raw text do not read to its end locks the unit', NT,
  "\t\tself::not_read( $acc, (string) $kind, $at . ':net:', $unread, $deep ? $at . ':net' : null );\n", "", 'net', N_DEPTH)

# ------------------------------------------------------------- texts()
e('texts-depth', 'texts(): deeper than the readers read: not read to its end', NT,
  "\t\tif ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH ) {", "\t\tif ( false ) {", 'net', N_UNREAD)
e('texts-depth-count', 'texts(): the depth counted down the arrays', NT,
  "\t\t\tself::texts( $item, $entry, $layer, $depth + 1, $acc, $kind, $at, $each );",
  "\t\t\tself::texts( $item, $entry, $layer, $depth, $acc, $kind, $at, $each );", 'net', N_UNREAD)
e('texts-leaves', 'texts(): every leaf of an array', NT,
  "\t\t\tself::texts( $item, $entry, $layer, $depth + 1, $acc, $kind, $at, $each );\n", "", 'net', N_CRED)
e('texts-key', 'texts(): every key of an array that is a text', NT,
  "\t\t\t\t$each( $entry . '.key', $key );\n", "", 'net', N_CRED)
e('texts-serialized', 'texts(): a serialized text unpacked, not read as its text', NT,
  "\t\t\tif ( ! is_serialized( $value ) ) {\n\t\t\t\t$each( $where, $value );",
  "\t\t\tif ( true ) {\n\t\t\t\t$each( $where, $value );", 'net', N_PROSE)
e('texts-layers', 'texts(): more serialized texts inside one another than the readers unpack: not read to its end', NT,
  "\t\t\tif ( $layer >= AB_MCP_Sync_Canon::MAX_LAYERS ) {", "\t\t\tif ( false ) {", 'net', N_UNREAD)
e('texts-layer-count', 'texts(): each unpacked text one layer more', NT,
  "\t\t\tself::texts( $read['value'], '' === $where ? 'php' : $where . '.php', $layer + 1, $depth, $acc, $kind, $at, $each );",
  "\t\t\tself::texts( $read['value'], '' === $where ? 'php' : $where . '.php', $layer, $depth, $acc, $kind, $at, $each );", 'net', N_UNREAD)
e('texts-gate', 'texts(): unpacked only through AB_MCP_Sync_Canon::unserialized(), its check of the records first', NT,
  "\t\t\t$read = AB_MCP_Sync_Canon::unserialized( $value );",
  "\t\t\t$read = array( 'ok' => true, 'value' => @unserialize( trim( $value ) ), 'why' => '' );", 'net', N_UNREAD)
e('texts-unreadable', 'texts(): a text that does not unpack or holds an object locks', NT,
  "\t\t\t\tAB_MCP_Sync_Canon::lock( $acc, $kind, $read['why'], $here );\n", "", 'net', N_UNREAD)
e('texts-round-trip', 'texts(): a text that does not serialize back from its value byte for byte read whole as well (a key twice, bytes after its end)', NT,
  "\t\t\tif ( serialize( $read['value'] ) !== trim( $value ) ) {",
  "\t\t\tif ( false ) {", 'net', N_CRED)
e('texts-round-trip-trim', 'texts(): the round trip against the text as unserialize() reads it (trimmed)', NT,
  "\t\t\tif ( serialize( $read['value'] ) !== trim( $value ) ) {",
  "\t\t\tif ( serialize( $read['value'] ) !== $value ) {", 'net', N_PROSE)
e('texts-object', 'texts(): an object in a structure locks', NT,
  "\t\t\tAB_MCP_Sync_Canon::lock( $acc, $kind, 'php_object', $here );\n", "", 'net', N_STRUCT)
# ------------------------------------------- strings(): the references
e('strings-keys', 'strings(): the keys of a structure counted for the references', NT,
  "\t\t\tif ( is_string( $key ) && '' !== $key ) {\n\t\t\t\t$out[] = $key;\n\t\t\t}\n", "", 'net', N_STRUCT)
e('strings-leaves', 'strings(): the leaves of a structure counted for the references', NT,
  "\t\t\t} elseif ( is_string( $item ) && '' !== $item ) {",
  "\t\t\t} elseif ( false ) {", 'net', N_TEXTS)
e('strings-nested', 'strings(): nested arrays of a structure as well', NT,
  "\t\t\tif ( is_array( $item ) ) {\n\t\t\t\tself::strings( $item, $out );",
  "\t\t\tif ( false ) {\n\t\t\t\tself::strings( $item, $out );", 'net', N_TEXTS)
# ------------------------------------------------- the kinds and the export
e('kinds-meta', 'meta_form(): the net reads a meta value unpacked', DK,
  "\t\t\tAB_MCP_Sync_Net::check( $acc, $mark, $kind, $at, $row['value'], $ctx, true, true );",
  "\t\t\tAB_MCP_Sync_Net::check( $acc, $mark, $kind, $at, $row['value'], $ctx, true, false );", 'it', IT_PROSE)
e('kinds-option', 'option(): the net reads the value of an option unpacked', DK,
  "\t\tAB_MCP_Sync_Net::check( $acc, $mark, 'option', 'option_value', $raw, $source['ctx'], true, self::stored_column( 'value' ) );",
  "\t\tAB_MCP_Sync_Net::check( $acc, $mark, 'option', 'option_value', $raw, $source['ctx'], true, false );", 'it', IT_PROSE)
e('kinds-option-column', 'stored_column() of the option: its value is a stored value', DK,
  "\t\treturn 'value' === (string) $column;", "\t\treturn false;", 'u', U_FORM)
e('kinds-column', 'stored_column(): no column of the other kinds is one (WordPress hands them out as stored)', DK,
  "\tpublic static function stored_column( $column ) {\n\t\treturn false;",
  "\tpublic static function stored_column( $column ) {\n\t\treturn true;", 'u', U_FORM)
e('export-meta', 'checked_content(): a meta value read unpacked, as the kinds read it', EX,
  "\t\t\t\t\t\tif ( ! self::check_unit( $acc, $kind, 'meta:' . (string) $pair[0], $input, $name, false, true ) ) {",
  "\t\t\t\t\t\tif ( ! self::check_unit( $acc, $kind, 'meta:' . (string) $pair[0], $input, $name, false, false ) ) {", 'u', U_FORM)
e('export-row', 'checked_content(): a column the kind names as stored read unpacked', EX,
  ", $reader::stored_column( (string) $column ) );", ", false );", 'u', U_FORM)
e('export-net', 'check_unit(): the form handed to the net', EX,
  "\t\tAB_MCP_Sync_Net::check( $acc, $mark, $kind, $at, $raw, array(), false, $stored );",
  "\t\tAB_MCP_Sync_Net::check( $acc, $mark, $kind, $at, $raw, array(), false, false );", 'u', U_FORM)


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
 * The clauses of Z8 branch 11b (heikel/z8-netz; Thomas, 08.11.2026): the
 * net over the raw value counts the credentials of a meta value and of the
 * value of an option unpacked, as WordPress hands them out (every key and
 * every leaf on its own, nested serialized texts unpacked through
 * AB_MCP_Sync_Canon::unserialized() within the depth and the layers of the
 * readers, a text that does not serialize back read whole as well, one that
 * does not unpack or holds an object locked), a structure the same way with
 * its keys; the references stay counted over the raw text; the kinds and
 * the export ask for that form alike.
 *
 * Written by tests/mutation/gen/netz.py from the source; change the entries
 * there and run it again.
 *
 * Held by the unit suite (SyncNetTest, SyncStructureWritersTest) and, for
 * the kinds on WordPress, by the integration suite (SyncStructureTest; the
 * sites running, AB_IT_ROOT set). The clauses of the net it keeps from
 * branch 4 are in kanon.php.
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
open(os.path.join(ROOT, 'tests/mutation/lists/netz.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
