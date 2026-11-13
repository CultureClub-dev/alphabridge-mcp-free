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
CP = 'includes/sync/class-sync-code-plan.php'
CO = 'includes/sync/class-sync-code-ops.php'
CS = 'includes/sync/class-sync-code-swap.php'
EN = 'includes/sync/class-sync-engine.php'
ST = 'includes/sync/class-sync-code-stage.php'
VAR = {CK: '$ck', DE: '$de', PU: '$pu', CP: '$cp', CO: '$co', CS: '$cs', EN: '$en', ST: '$st'}
CLASSES = {'kind': ('unit', 'SyncCodeKindTest'), 'env': ('unit', 'SyncEnvTest'), 'run': ('unit', 'SyncRunTest'),
           'ops': ('unit', 'SyncCodeOpsTest'), 'coderun': ('it', 'SyncCodeRunTest')}
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
  TAKES, "\t\t$takes   = 'changed_on_staging' === $files && 'changed_on_staging' === $entry['result'];", 'env', 'testOnlyAUnitOfAKindTheSwapWritesIsTakenOverEvenWhereItsKindHasNoLock')
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

# ------------------------------------------- the plan of a run of the code (Z8b branch 5, part 1)
ACC = 'testTheSwapOfCodeThatRunsIsPlannedOnlyWithItsNumberInAcceptAndTheTokenBindsIt'
RUNSU = 'testWhetherCodeRunsNotKnownLocksTheUnitWithAcceptOrWithoutAndAnInactivePluginNeedsNoRelease'
PREV = 'testThePreviewNamesEveryPathThatGoesIntoTheBackup'
MIX = 'testARunOfTheCodeHoldsNoStepOfTheContents'
BIND = 'testAPackageThatIsGoneBindsNothingAndTheBindingIsChecked'
ENTRYT = 'testAnEntryOfAUnitWhoseFieldsDoNotReadInTheirFormPlansNothing'
VERS = 'testTheVersionsOfATakeoverAreReadInTheirFormAndAVersionNotKnownOnEitherSideLocks'
PAIRT = 'testThePairOfATakeoverIsReadInItsFormAndAPairNotNamedSoLocksWithItsOwnReason'
GONE = 'testThePathsThatGoIntoTheBackupReadInTheirFormAndMoreIsNeverMadeUp'
e('plan-accept-releases', 'unit(): code that runs here is taken over only with its number in accept (Z24, Z41)', CP,
  "\t\tif ( $runs && ! $released ) {", "\t\tif ( false ) {", 'coderun', ACC)
e('plan-accept-chosen', 'of(): a number in accept is a unit chosen in select (Z41)', CP,
  "\t\t\tif ( ! isset( $units[ $n ] ) ) {", "\t\t\tif ( false ) {", 'coderun', ACC)
e('plan-accept-no-unit', 'of(): accept without a unit of code chosen is refused, never a plan of the contents (Z41)', CP,
  "\t\t\treturn array() === $selection['accept'] ? null : self::refused(", "\t\t\treturn true ? null : self::refused(", 'coderun', MIX)
for (id_, cond) in (('all', "$selection['all'] || "), ('others', "$others > 0 || "), ('choices', "array() !== $selection['choices'] || ")):
    e('plan-code-mix-' + id_, 'of(): a run of the code holds no step of the contents (E19, Z30): ' + id_, CP,
      "\t\tif ( $selection['all'] || $others > 0 || array() !== $selection['choices'] || array() !== $selection['as_draft'] ) {",
      "\t\tif ( " + "$selection['all'] || $others > 0 || array() !== $selection['choices'] || array() !== $selection['as_draft'] ) {".replace(cond, ''), 'coderun', MIX)
e('plan-code-mix-as-draft', 'of(): a run of the code holds no step of the contents (E19, Z30): as_draft', CP,
  "\t\tif ( $selection['all'] || $others > 0 || array() !== $selection['choices'] || array() !== $selection['as_draft'] ) {",
  "\t\tif ( $selection['all'] || $others > 0 || array() !== $selection['choices'] ) {", 'coderun', MIX)
e('plan-runs-unknown', 'unit(): whether code runs not known locks the unit, with accept or without (reading 3)', CP,
  "\t\tif ( null === $runs ) {", "\t\tif ( false ) {", 'coderun', RUNSU)
e('plan-paths-whole', 'unit(): the preview names every path that goes into the backup (reading 4, Z42)', CP,
  "\t\t\t\t\t'count' => count( $gone ),\n\t\t\t\t\t'paths' => $gone,", "\t\t\t\t\t'count' => count( $gone ),\n\t\t\t\t\t'paths' => array_slice( $gone, 0, 100 ),", 'coderun', PREV)
e('plan-paths-unknown', 'unit(): paths that go into the backup not known lock the unit (code:paths_unknown)', CP,
  "\t\tif ( null === $gone ) {\n\t\t\treturn $lock(", "\t\tif ( false ) {\n\t\t\treturn $lock(", 'coderun', 'testPathsThatGoIntoTheBackupNotKnownLockTheUnit')
e('plan-gone-more-form', 'gone(): more, where it is there, is a whole number of 1 or more', CP,
  "\t\t\tif ( ! is_int( $paths['more'] ) || $paths['more'] < 1 ) {", "\t\t\tif ( ! is_int( $paths['more'] ) || $paths['more'] < 0 ) {", 'ops', GONE)
e('plan-gone-whole', 'gone(): without more the list only_live of the entry is whole', CP,
  "\t\tif ( count( $gone ) !== $paths['only_live_count'] || array() !== array_filter(", "\t\tif ( array() !== array_filter(", 'ops', GONE)
e('plan-gone-paths', 'gone(): every path is a text that is not empty', CP,
  "\t\tif ( count( $gone ) !== $paths['only_live_count'] || array() !== array_filter(", "\t\tif ( count( $gone ) !== $paths['only_live_count'] || false && array() !== array_filter(", 'ops', GONE)
e('plan-version-same', 'version_op(): the same version on both sides is an edit, a version not known never (reading 2)', CP,
  "\t\t\tif ( 'same' === $order ) {", "\t\t\tif ( 'same' === $order || null === $order ) {", 'ops', VERS)
e('plan-version-newer', 'version_op(): a newer version on the staging site is an update', CP,
  "\t\t\tif ( 'newer' === $order ) {\n\t\t\t\treturn 'update';", "\t\t\tif ( 'newer' === $order ) {\n\t\t\t\treturn 'edit';", 'ops', VERS)
e('plan-version-downgrade', 'version_op(): an older version on the staging site locks (code:downgrade, E9)', CP,
  "\t\t\tif ( 'older' === $order ) {", "\t\t\tif ( false ) {", 'ops', VERS)
e('plan-entry-form', 'entry_of(): the fields of the entry that decide its step are read in their form', CP,
  "\t\t$read = AB_MCP_Shape::read( $entry, self::ENTRY );\n\t\tif ( null === $read ) {", "\t\t$read = AB_MCP_Shape::read( $entry, self::ENTRY );\n\t\tif ( false ) {", 'ops', ENTRYT)
e('plan-entry-swapped', 'ENTRY: only a plugin or a theme is a unit of the plan', CP,
  "\t\t\t\t'in'        => AB_MCP_Sync_Decision::SWAPPED,\n", '', 'ops', ENTRYT)
e('plan-pair-form', 'pair_of(): the pair an entry names is read in its form, its own reason where it is not', CP,
  "\t\t$read = AB_MCP_Shape::read( $entry, self::PAIR );\n\t\tif ( null === $read ) {", "\t\t$read = AB_MCP_Shape::read( $entry, self::PAIR );\n\t\tif ( false ) {", 'ops', PAIRT)
e('plan-pair-min', 'PAIR: a pair is a whole number of 1 or more', CP,
  "\t\t\t\t\t\t'min'    => 1,", "\t\t\t\t\t\t'min'    => 0,", 'ops', PAIRT)
e('plan-bind', 'bind(): each package of the plan is bound to the run, one not bound refuses (V20)', CP,
  "\t\t\tif ( true !== $bound ) {", "\t\t\tif ( false ) {", 'coderun', BIND)

# ------------------------------------------- a step of the code in a run (Z8b branch 5, part 1)
INACT = 'testAnInactivePluginUpdatedOnStagingIsPlannedAloneStagedOverCallsAndReadyWithoutAChangeOfTheSite'
PKG = 'testAUnitFromAPackageOfCodePassesTheCheckOfTheRunAndIsStagedFromIt'
NOTREAD = 'testAStepOfTheCodeWhoseRunPlanOrJournalDoesNotReadInItsFormDoesNothing'
DL = 'testADownloadThatFailsFailsTheStageAndItsSwapIsSkipped'
ITEM = 'testTheUnitOfAStepComesFromThePlanOfItsRunByTheNumberOfTheStep'
WRIT = 'testAStepOfARunWhosePlanDoesNotReadBackIsJudgedAsNeitherCodeNorObject'
e('ops-item-seq', 'item(): the unit of the step by its number, never another (V18)', CO,
  "\t\t\t\tif ( $seq === $unit['seq'] ) {", "\t\t\t\tif ( true ) {", 'ops', ITEM)
e('ops-stage-first', 'stage(): a step without a journal stages, one with a journal checks', CO,
  "\t\tif ( null === $step['parts'] ) {", "\t\tif ( false ) {", 'coderun', INACT)
e('ops-stage-run-form', 'stage(): a run not of its form or a step with fields not read does nothing', CO,
  "\t\tif ( null === $run_id || ( isset( $step['unread'] ) && array() !== $step['unread'] ) ) {", "\t\tif ( false ) {", 'ops', 'testAStepOfARunNotOfItsFormOrWithFieldsNotReadStagesNothing')
e('ops-stage-failed', 'stage(): a stage that fails fails the step, its swap is skipped', CO,
  "\t\t\tif ( is_wp_error( $staged ) ) {\n\t\t\t\treturn self::not_staged(", "\t\t\tif ( false ) {\n\t\t\t\treturn self::not_staged(", 'coderun', DL)
e('ops-journal-form', 'stage(): a journal not of its form is not known, nothing is checked on it (V19)', CO,
  "\t\tif ( null === $journal || $journal['unit']['kind'] !== $unit['kind'] || $journal['unit']['ref'] !== $unit['ref'] ) {",
  "\t\tif ( null === $journal || $journal['unit']['kind'] !== $unit['kind'] ) {", 'coderun', NOTREAD)
e('ops-journal-known', 'stage(): a journal that does not read refuses, never a check on it', CO,
  "\t\tif ( null === $journal || $journal['unit']['kind'] !== $unit['kind'] || $journal['unit']['ref'] !== $unit['ref'] ) {",
  "\t\tif ( false ) {", 'coderun', NOTREAD)
e('ops-reading-5', 'stage(): a check that gave no state keeps the step planned and names the error and the way (reading 5)', CO,
  "\t\t\treturn self::not_checked( $seq, $state );", "\t\t\treturn $state;", 'coderun', NOTREAD)
e('ops-checking', 'stage(): a stage still checking says so and the step stays', CO,
  "\t\tif ( AB_MCP_Sync_Code_Stage::CHECKING === $checked['state'] ) {", "\t\tif ( false ) {", 'coderun', 'testAStageStillCheckingSaysSoAndTheStepStays')
e('ops-refused', 'stage(): a stage the check refused is not staged, its swap is skipped', CO,
  "\t\tif ( AB_MCP_Sync_Code_Stage::REFUSED === $checked['state'] ) {", "\t\tif ( false ) {", 'coderun', 'testAStageTheCheckRefusesIsNotStagedAndItsSwapIsSkipped')
e('engine-written-plan', 'written(): a plan that does not read back judges a step as neither code nor object', EN,
  "\t\tif ( ! is_array( $run['plan'] ) ) {", "\t\tif ( false ) {", 'run', WRIT)

# ------------------------------------------- what runs here, three-valued (Z8b branch 5, part 1)
PLUG = 'testWhichPluginsRunIsReadFromTheRowOfActivePluginsAndARowNotReadIsNotKnown'
THEME = 'testWhichThemeRunsIsReadFromBothItsRowsAndASwitchInBetweenIsNotKnown'
e('swap-plugins-row', 'active_plugins(): a row not read is not known, never no plugin', CS,
  "\t\tif ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) || ! is_string( $row['value'] ) ) {", "\t\tif ( ! is_array( $row ) ) {", 'ops', PLUG)
e('swap-plugins-form', 'active_plugins(): the row is read in the form of site.active_plugins', CS,
  "AB_MCP_Store_Inputs::read( 'site.active_plugins', $read['value'] )", "( is_array( $read['value'] ) ? $read['value'] : null )", 'ops', PLUG)
e('swap-mains-unknown', 'active_mains(): plugins not known are no main files known', CS,
  "\t\tif ( null === $active || ! is_string( $ref ) || '' === $ref ) {", "\t\tif ( ! is_string( $ref ) || '' === $ref ) {", 'ops', PLUG)
e('swap-runs-plugin', 'runs(): whether a plugin runs is not known where its main files are not', CS,
  "\t\t\treturn null === $mains ? null : array() !== $mains;", "\t\t\treturn array() !== $mains;", 'ops', PLUG)
for (id_, cond) in (('stylesheet', "null === $read['stylesheet'] || "), ('template', "null === $read['template'] || ")):
    e('swap-theme-' + id_, 'theme(): the theme not known where its row ' + id_ + ' is not read', CS,
      "\t\tif ( null === $read['stylesheet'] || null === $read['template'] || $read['again'] !== $read['stylesheet'] ) {",
      "\t\tif ( " + "null === $read['stylesheet'] || null === $read['template'] || $read['again'] !== $read['stylesheet'] ) {".replace(cond, ''), 'ops', THEME)
e('swap-theme-switch', 'theme(): a switch while the rows are read is not known', CS,
  "\t\tif ( null === $read['stylesheet'] || null === $read['template'] || $read['again'] !== $read['stylesheet'] ) {",
  "\t\tif ( null === $read['stylesheet'] || null === $read['template'] ) {", 'ops', THEME)
e('swap-runs-theme', 'runs(): whether a theme runs is not known where the theme is not', CS,
  "\t\treturn null === $theme ? null : ( $ref === $theme['stylesheet'] || $ref === $theme['template'] );",
  "\t\treturn null !== $theme && ( $ref === $theme['stylesheet'] || $ref === $theme['template'] );", 'ops', THEME)
e('swap-runs-parent', 'runs(): the parent of the theme that runs runs as well', CS,
  "\t\treturn null === $theme ? null : ( $ref === $theme['stylesheet'] || $ref === $theme['template'] );",
  "\t\treturn null === $theme ? null : ( $ref === $theme['stylesheet'] );", 'ops', THEME)

# ------------------------------------------- K1a: Codex round 1 on the code, A3 and A2
A3T = 'testAStageThatIsNotTheOneOfItsGroupOrNotSignedFailsItsStepAndItsSwapIsSkipped'
A2T = 'testAReadyStageNotBoundToThisRunThisUnitAndItsStagingHashNeverMakesTheStepDone'
BYL = 'testTheGroupsOfThePackagesAreReadInTheirFormBeforeAPackageIsBound'
e('k1a-permanent-ends', 'stage(): a refusal of the stage no later call changes ends the step failed (A3)', CO,
  "\t\t\tif ( in_array( $state->get_error_code(), AB_MCP_Sync_Code_Stage::PERMANENT, true ) ) {", "\t\t\tif ( false ) {", 'coderun', A3T)
e('k1a-permanent-other', 'PERMANENT: the stage that is not the one of its group (A3)', ST,
  "\tconst PERMANENT = array( 'code:stage_other', 'code:unsigned' );", "\tconst PERMANENT = array( 'code:unsigned' );", 'coderun', A3T)
e('k1a-permanent-unsigned', 'PERMANENT: the unit not signed in the environment (A3)', ST,
  "\tconst PERMANENT = array( 'code:stage_other', 'code:unsigned' );", "\tconst PERMANENT = array( 'code:stage_other' );", 'coderun', A3T)
e('k1a-stage-other-code', 'complete(): the stage not of its group is a refusal of its own (A3)', ST,
  "\t\t\treturn self::refusal( 'code:stage_other', __( 'The stage named is not the one its group holds.", "\t\t\treturn self::refusal( 'code:stage_unknown', __( 'The stage named is not the one its group holds.", 'coderun', A3T)
e('k1a-foreign-refuses', 'stage(): a ready stage not bound to the step never makes it done (A2)', CO,
  "\t\tif ( null !== $foreign ) {", "\t\tif ( false ) {", 'coderun', A2T)
e('k1a-foreign-kind', 'foreign(): the stage of another kind of unit is not bound to the step (A2)', CO,
  "if ( null === $read || $unit['kind'] !== $read['unit']['kind'] || $unit['ref'] !== $read['unit']['ref'] || ! hash_equals( $unit['staging_hash'], $read['signed'] ) ) {",
  "if ( null === $read || $unit['ref'] !== $read['unit']['ref'] || ! hash_equals( $unit['staging_hash'], $read['signed'] ) ) {", 'coderun', A2T)
e('k1a-foreign-ref', 'foreign(): the stage of another unit is not bound to the step (A2)', CO,
  "if ( null === $read || $unit['kind'] !== $read['unit']['kind'] || $unit['ref'] !== $read['unit']['ref'] || ! hash_equals( $unit['staging_hash'], $read['signed'] ) ) {",
  "if ( null === $read || $unit['kind'] !== $read['unit']['kind'] || ! hash_equals( $unit['staging_hash'], $read['signed'] ) ) {", 'coderun', A2T)
e('k1a-foreign-hash', 'foreign(): the stage of another version of the staging site is not bound to the step (A2)', CO,
  "if ( null === $read || $unit['kind'] !== $read['unit']['kind'] || $unit['ref'] !== $read['unit']['ref'] || ! hash_equals( $unit['staging_hash'], $read['signed'] ) ) {",
  "if ( null === $read || $unit['kind'] !== $read['unit']['kind'] || $unit['ref'] !== $read['unit']['ref'] ) {", 'coderun', A2T)
e('k1a-foreign-run', 'foreign(): the group of the stage bound to another run is not bound to the step (A2)', CO,
  "\t\t\t\treturn $run_id === $one['run_id'] ? null : $why;", "\t\t\t\treturn null;", 'coderun', A2T)
e('k1a-answer-binding', 'answer(): complete() names the unit and its signed hash, the binding read before done (A2)', ST,
  "\t\t\t'unit'    => $state['unit'],\n", '', 'coderun', A2T)
e('k1a-bind-group-form', 'by_label(): a group of the packages not of its form refuses, never cast', CP,
  "\t\t\tif ( null === $read ) {\n\t\t\t\treturn self::refused( 'ab_mcp_sync_code_bind', __( 'A group", "\t\t\tif ( false ) {\n\t\t\t\treturn self::refused( 'ab_mcp_sync_code_bind', __( 'A group", 'ops', BYL)
e('k1a-bind-label-known', 'bind(): a package without its group is not bound and refuses (V20)', CP,
  "\t\t\tif ( array_key_exists( $label, $by_label ) ) {", "\t\t\tif ( true ) {", 'coderun', BIND)

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
       ' * the plan of a run of the code, a step of the code in a run and what runs',
       ' * here, three-valued (part 1).',
       ' * Held by the unit suite (SyncCodeKindTest, SyncEnvTest, SyncRunTest,',
       ' * SyncCodeOpsTest) and the integration suite (SyncCodeRunTest).', ' *',
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
