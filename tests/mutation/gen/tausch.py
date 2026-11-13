#!/usr/bin/env python3
"""Writes tests/mutation/lists/tausch.php: one entry per clause of the swap of
the code of the site (Z8b branch 5: the kind code and its rights, the plan and
the steps of a run of the code, the swap with its promises of plan 3.1). Every
search is checked to occur exactly once in its file, and the test of its
expect to be a method of the class its run names, before the list is written;
each entry runs that test only.

    python3 tests/mutation/gen/tausch.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
CK = 'includes/sync/class-sync-code-kind.php'
DE = 'includes/sync/class-sync-decision.php'
PU = 'includes/sync/class-sync-push.php'
VAR = {CK: '$ck', DE: '$de', PU: '$pu'}
CLASSES = {'kind': ('unit', 'SyncCodeKindTest'), 'env': ('unit', 'SyncEnvTest'), 'run': ('unit', 'SyncRunTest')}
entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('tausch/' + id_, clause, file, search, replace, run, expect))


# ------------------------------------------- the kind code and its rights (E10, Z26, Z38)
ENGINE = 'testTheEngineIsTheOneRegisteredUnderTheNameCodeAndMovesTheFilesOfAPluginAndOfATheme'
OTHER = 'testAnotherEngineUnderTheNameCodeIsNotTakenForTheKind'
CAPS = 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses'
SITE = 'testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked'
FORM = 'testAnOperationOrAUnitNotOfTheFormIsRefusedBeforeAnythingIsAsked'
e('kind-owns-both', 'responsibility(): the files of a plugin and of a theme, as the table gives them to the kind code (Z38)', CK,
  "\t\treturn array( 'part:plugin:files', 'part:theme:files' );", "\t\treturn array( 'part:plugin:files' );", 'kind', ENGINE)
e('kind-engine-held', 'engine(): only the engine of this class is the kind code, never another object registered under its name (Z38)', CK,
  "\t\treturn $held instanceof self ? $held : new WP_Error(", "\t\treturn true ? $held : new WP_Error(", 'kind', OTHER)
e('z26-form', 'rights(): an operation or a unit not of the form is refused before anything is asked (Z26)', CK,
  "\t\tif ( ! is_string( $op ) || ! isset( self::OPS[ $op ] ) || 2 !== count( $unit ) || ! in_array( $unit[0], self::UNITS, true ) || '' === $unit[1] ) {",
  "\t\tif ( ! is_string( $op ) || ! isset( self::OPS[ $op ] ) || 2 !== count( $unit ) || ! in_array( $unit[0], self::UNITS, true ) ) {", 'kind', FORM)
e('z26-file-mods', 'rights(): a site that lets no files be changed takes over no code (E10, F20)', CK,
  "\t\tif ( true !== $calls->mods_allowed() ) {", "\t\tif ( false ) {", 'kind', SITE)
e('z26-file-mods-unknown', 'rights(): whether files may be changed not known is not allowed (E10)', CK,
  "\t\tif ( true !== $calls->mods_allowed() ) {", "\t\tif ( false === $calls->mods_allowed() ) {", 'kind', SITE)
e('z26-direct', 'rights(): only the file system method direct, a method not named is not direct (E10)', CK,
  "\t\tif ( 'direct' !== $calls->method() ) {", "\t\tif ( false ) {", 'kind', SITE)
e('z26-each-cap', 'rights(): each capability of the operation is asked, and one missing refuses (E10)', CK,
  "\t\t\tif ( true !== $calls->can( $cap ) ) {", "\t\t\tif ( false ) {", 'kind', CAPS)
for (op, unit, caps, less) in (
        ('create', 'plugin', "array( 'install_plugins' )", 'array()'),
        ('create', 'theme', "array( 'install_themes' )", 'array()'),
        ('update', 'plugin', "array( 'upload_plugins', 'update_plugins' )", "array( 'upload_plugins' )"),
        ('update', 'theme', "array( 'upload_themes', 'update_themes' )", "array( 'update_themes' )"),
        ('edit', 'plugin', "array( 'upload_plugins', 'update_plugins', 'edit_plugins' )", "array( 'upload_plugins', 'update_plugins' )"),
        ('edit', 'theme', "array( 'upload_themes', 'update_themes', 'edit_themes' )", "array( 'upload_themes', 'update_themes' )")):
    pad = ' ' if 'theme' == unit else ''
    e('z26-caps-%s-%s' % (op, unit), 'OPS: the capabilities of %s of a %s (E10)' % (op, unit), CK,
      "\t\t\t'%s' %s=> %s,\n" % (unit, pad, caps) if False else "\t\t\t'%s'%s => %s," % (unit, ' ' if 'theme' == unit else '', caps),
      "\t\t\t'%s'%s => %s," % (unit, ' ' if 'theme' == unit else '', less), 'kind', CAPS)

# ------------------------------------------- the report: a unit the swap takes over (E5, F5)
PARTS = 'testThePartsOfAUnitWithAPairAreJudgedEachAgainstItsOwnBase'
DIFFERS = 'testAPluginWhoseFilesOrMainFilesRunningDifferDiffersWithThePart'
ONE = 'testAUnitOnOneSideOnly'
TAKES = "\t\t$takes   = $swapped && 'changed_on_staging' === $files && 'changed_on_staging' === $entry['result'];"
e('unit-takes', 'unit_way(): the files of a plugin or theme changed on staging and unchanged here are taken over', DE,
  TAKES, "\t\t$takes   = false;", 'env', PARTS)
e('unit-takes-swapped-only', 'unit_way(): only of a kind the swap writes; a unit of another kind waits with the lock of its kind', DE,
  TAKES, "\t\t$takes   = 'changed_on_staging' === $files && 'changed_on_staging' === $entry['result'];", 'env', PARTS)
e('unit-takes-no-conflict', 'unit_way(): nothing of a unit is taken over while a part of it is in conflict', DE,
  TAKES, "\t\t$takes   = $swapped && 'changed_on_staging' === $files;", 'env', PARTS)
e('unit-takes-files', 'unit_way(): the files are what the swap takes; which main files run alone waits with the lock of its kind', DE,
  TAKES, "\t\t$takes   = $swapped && 'changed_on_staging' === $entry['result'];", 'env', PARTS)
e('unit-takes-action', 'unit_way(): what the swap takes over says take_over', DE,
  "\t\t} elseif ( $takes ) {\n\t\t\t$entry['action'] = 'take_over';", "\t\t} elseif ( false ) {\n\t\t\t$entry['action'] = 'take_over';", 'env', PARTS)
e('unit-takes-no-lock', 'unit_way(): what the swap takes over carries no lock of its kind', DE,
  "( ! $takes && in_array( $entry['result'], array( 'changed_on_staging', 'conflict' ), true ) )", "( in_array( $entry['result'], array( 'changed_on_staging', 'conflict' ), true ) )", 'env', PARTS)
e('unit-differs-no-stage', 'unit_way(): a plugin or theme that differs without a pair waits for its pair, with no lock of a stage', DE,
  "\t\t$waits = $swapped ? (", "\t\t$waits = false ? (", 'env', DIFFERS)
e('unit-new-create', 'unit_new(): a plugin or theme only on staging is made here by the swap (F5)', DE,
  "\t\treturn in_array( $kind, self::SWAPPED, true ) ? 'create' : 'locked';", "\t\treturn 'locked';", 'env', ONE)
e('unit-new-swapped-only', 'unit_new(): a unit of another kind only on staging stays locked', DE,
  "\t\treturn in_array( $kind, self::SWAPPED, true ) ? 'create' : 'locked';", "\t\treturn 'create';", 'env', ONE)

# ------------------------------------------- the plan: a unit of code is no step of the contents (E19, Z30)
VERDICT = 'testWhichEntryOfAReportIsAStepLockedOrNothing'
e('z30-unit-no-content-step', 'verdict(): a unit of code the swap takes over or makes is never an item of a run of the contents (Z30)', PU,
  "\t\tif ( 'environment' === $entry['kind'] && in_array( $action, array( 'take_over', 'create' ), true ) ) {", "\t\tif ( false ) {", 'run', VERDICT)

# ------------------------------------------------ check and write
src = {f: open(os.path.join(ROOT, f)).read() for f in VAR}
errors = []
for (id_, clause, file, search, replace, run, expect) in entries:
    n = src[file].count(search)
    if 1 != n:
        errors.append('%s: search occurs %d times in %s' % (id_, n, file))
    if search == replace:
        errors.append('%s: search is replace' % id_)
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


out = ['<?php', '/**', ' * The clauses of the swap of the code of the site (Z8b branch 5: the kind',
       ' * code with the rights of E10 at Z26 and its place in the registry for Z38;',
       ' * the entry of a unit the swap takes over or makes, in the report).',
       ' * Written by tests/mutation/gen/tausch.py from the source; change the entries',
       ' * there and run it again.', ' *',
       ' * Held by the unit suite (SyncCodeKindTest, SyncEnvTest).', ' *',
       ' * Not listed, with why (bericht-zweig-5.md), equivalent: in rights() the',
       ' * count of the two halves of a unit beside the kind of unit (a ref without a',
       ' * colon has one half, whose kind is then the whole ref and no kind of UNITS).',
       ' *', ' * @package AlphaBridge_MCP', ' */', '',
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
open(os.path.join(ROOT, 'tests/mutation/lists/tausch.php'), 'w').write('\n'.join(out) + '\n')
print('%d entries' % len(entries))
