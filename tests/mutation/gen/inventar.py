#!/usr/bin/env python3
"""Writes tests/mutation/lists/inventar.php: one entry per clause of the
environment of the report (Z8 branch 7: AB_MCP_Sync_Env, the packages of the
environment in wp_site_export and wp_site_import_chunk, and the environment
in wp_site_diff). Every search is checked to occur exactly once in its file,
and the test of its expect to be a method of the class its run names, before
the list is written; each entry runs that test only.

    python3 tests/mutation/gen/inventar.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
EN = 'includes/sync/class-sync-env.php'
RE = 'includes/sync/class-sync-report.php'
EX = 'includes/sync/class-sync-export.php'
IM = 'includes/sync/class-sync-import.php'
P = 'includes/sync/class-sync-proposal.php'
FILEVAR = {EN: '$en', RE: '$re', EX: '$ex', IM: '$im', P: '$p'}

RUNS = {
    'env': ('unit', 'SyncEnvTest'),
    'it': ('it', 'SyncEnvTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('inventar/' + id_, clause, file, search, replace, run, expect))


def off(search):
    """The condition of an if taken out: if ( false ) {."""
    return re.sub(r'^(\s*)(\}\s*else)?(if|elseif) \(.*\) \{', lambda m: m.group(1) + (m.group(2) or '') + m.group(3) + ' ( false ) {', search, count=1)


# ------------------------------------------------------------------ walk
ORDER = 'testTheOrderOfTheWalkIsDepthFirstByStrcmpOfEachPart'
e('order-part', 'order(): two paths by their parts, each by strcmp()', EN,
  "\t\t\tif ( 0 !== $by ) {\n\t\t\t\treturn $by < 0 ? -1 : 1;", "\t\t\tif ( false ) {\n\t\t\t\treturn $by < 0 ? -1 : 1;", 'env', ORDER)
e('order-folder-first', 'order(): a folder before what is in it', EN,
  "\t\treturn count( $x ) <=> count( $y );", "\t\treturn 0;", 'env', ORDER)
e('walk-link', 'walk(): a link is not followed, counted as skipped', EN,
  "\t\t\tif ( is_link( $full ) ) {", "\t\t\tif ( false ) {", 'env', 'testAWalkFollowsNoLinkAndCountsItAsSkipped')
e('walk-skip-top', 'walk(): what a root skips, at its top level only', EN,
  "\t\t\tif ( '' === $rel && $skip( $dir, $name ) ) {", "\t\t\tif ( $skip( $dir, $name ) ) {", 'env', 'testAWalkLeavesOutWhatTheRootSkipsAtItsTopLevelOnly')
e('walk-skip', 'walk(): what a root skips is not counted', EN,
  "\t\t\tif ( '' === $rel && $skip( $dir, $name ) ) {", "\t\t\tif ( false ) {", 'env', 'testAWalkLeavesOutWhatTheRootSkipsAtItsTopLevelOnly')
e('walk-attached', 'walk(): a path of a file list of an attachment counted apart', EN,
  "\t\t\t\tif ( null !== $known && isset( $known[ $path ] ) ) {", "\t\t\t\tif ( false ) {", 'env', 'testAWalkCountsTheAttachedPathsApart')
e('walk-bytes', 'walk(): the sizes summed', EN,
  "\t\t\t\t\t\t$acc['bytes'] += $size;", '', 'env', 'testAWalkCountsAndSumsTheFilesOfARoot')
e('walk-unreadable', 'walk(): a folder that cannot be read makes it unreadable', EN,
  "\t\t$names = self::names( $dir );\n\t\tif ( null === $names ) {", "\t\t$names = self::names( $dir );\n\t\tif ( false ) {", 'env', 'testAWalkOverAFolderThatCannotBeReadIsUnreadable')
e('walk-unreadable-up', 'walk(): unreadable deeper down is the answer of the root', EN,
  "\t\t\t\tif ( 'done' !== $in ) {\n\t\t\t\t\treturn $in;", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn $in;", 'env', 'testAWalkOverAFolderThatCannotBeReadIsUnreadable')
WCLOCK = "\t\t\t\tif ( self::now() >= $deadline && $acc['after'] !== $from ) {"
e('walk-clock', 'walk(): it stops when the clock says so', EN, WCLOCK, "\t\t\t\tif ( false ) {", 'env', 'testAWalkStoppedByTheClockGoesOnAfterTheLastPathWithTheSameCounts')
e('walk-moved', 'walk(): it stops only once it moved on', EN, WCLOCK, "\t\t\t\tif ( self::now() >= $deadline ) {", 'env', 'testAWalkOverFoldersWithoutAFileMovesOnInEveryPart')
e('walk-dir-after', 'walk(): a folder counted to its end is where the next part goes on', EN,
  "\t\t\t\t// A folder counted to its end: the next part goes on after it.\n\t\t\t\t$acc['after'] = $path;", '', 'env', 'testAWalkOverFoldersWithoutAFileMovesOnInEveryPart')
e('walk-after', 'walk(): it goes on after the last path it counted, not at it', EN,
  "\t\t\t\t} elseif ( self::order( $path, $after ) <= 0 ) {", "\t\t\t\t} elseif ( self::order( $path, $after ) < 0 ) {", 'env', 'testAWalkStoppedByTheClockGoesOnAfterTheLastPathWithTheSameCounts')
e('walk-after-into', 'walk(): into the folder the last path lies in', EN,
  "\t\t\t\tif ( 0 === strpos( $after, $path . '/' ) ) {", "\t\t\t\tif ( false ) {", 'env', 'testAWalkStoppedByTheClockGoesOnAfterTheLastPathWithTheSameCounts')
e('walk-after-mark', 'walk(): the last path counted is kept', EN,
  "\t\t\t} else {\n\t\t\t\t++$acc['skipped'];\n\t\t\t}\n\t\t\t$acc['after'] = $path;", "\t\t\t} else {\n\t\t\t\t++$acc['skipped'];\n\t\t\t}", 'env', 'testAWalkCountsAndSumsTheFilesOfARoot')

# ----------------------------------------------------------- the host
BLOCK = 'testTheWordPressBlockOfTheHtaccessIsTheLinesBetweenItsMarkers'
e('block-end', 'block(): an end before the beginning ends nothing', EN,
  "\t\t\tif ( $inside && false !== strpos( $line, '# END WordPress' ) ) {", "\t\t\tif ( false !== strpos( $line, '# END WordPress' ) ) {", 'env', BLOCK)
e('block-cr', 'block(): the lines without their ends', EN,
  "\t\t\t$line = rtrim( $line, \"\\r\" );", '', 'env', BLOCK)
e('block-begin', 'block(): the lines after its beginning only', EN,
  "\t\t\t} elseif ( false !== strpos( $line, '# BEGIN WordPress' ) ) {", "\t\t\t} elseif ( true ) {", 'env', BLOCK)
SCHEMA = 'testTheSchemaOfATableIsHashedWithoutItsPrefixAndAutoIncrement'
e('schema-auto', 'schema(): without AUTO_INCREMENT', EN,
  "\t\t$text = (string) preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', (string) $create );", "\t\t$text = (string) $create;", 'env', SCHEMA)
e('schema-prefix', 'schema(): without the prefix of the site', EN,
  "\t\t$text = str_replace( '`' . $prefix, '`', $text );", '', 'env', SCHEMA)
VALUE = 'testTheValueOfAConstantIsShownPlainOrByItsHashOnly'
e('value-plain', 'constant_value(): a text that is not plain by its SHA-256 only', EN,
  "\t\tif ( is_string( $value ) && 1 === preg_match( self::PLAIN, $value ) ) {", "\t\tif ( is_string( $value ) ) {", 'env', VALUE)
e('value-float', 'constant_value(): a float as its text', EN,
  "\t\tif ( is_float( $value ) ) {\n\t\t\treturn (string) $value;", "\t\tif ( is_float( $value ) ) {\n\t\t\treturn $value;", 'env', VALUE)
SECRET = 'testNoConstantThatHoldsASecretIsShown'
e('shown-never', 'shown(): never a constant of NEVER', EN,
  "\t\treturn ! in_array( strtoupper( (string) $name ), self::NEVER, true ) && ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );", "\t\treturn ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );", 'env', SECRET)
e('shown-case', 'shown(): NEVER in any case', EN,
  "\t\treturn ! in_array( strtoupper( (string) $name ), self::NEVER, true ) && ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );", "\t\treturn ! in_array( (string) $name, self::NEVER, true ) && ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );", 'env', SECRET)
e('shown-redactor', 'shown(): never a name the redactor redacts', EN,
  "\t\treturn ! in_array( strtoupper( (string) $name ), self::NEVER, true ) && ! AB_MCP_Redactor::redacts_key( (string) $name, $extra );", "\t\treturn ! in_array( strtoupper( (string) $name ), self::NEVER, true );", 'env', SECRET)
RULES = 'testTheConstantsShownLeaveOutWhatTheRulesOfThisSiteRedact'
e('constants-shown', 'constants(): only what shown() lets through', EN,
  "\t\t\tif ( ! self::shown( $name, $extra ) ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'env', RULES)
e('constants-rules', 'constants(): with the names of ab_mcp_redact_keys', EN,
  "\t\t$extra = AB_MCP_Redactor::extra_keys();\n\t\t$out   = array();\n\t\tforeach ( self::CONSTANTS as $name ) {", "\t\t$extra = array();\n\t\t$out   = array();\n\t\tforeach ( self::CONSTANTS as $name ) {", 'env', RULES)
e('constants-undefined', 'constants(): no value where it is not defined', EN,
  "\t\t\t\t'value'   => defined( $name ) ? self::constant_value( constant( $name ) ) : null,", "\t\t\t\t'value'   => defined( $name ) ? self::constant_value( constant( $name ) ) : '',", 'env', RULES)
LOADER = 'testTheLoaderAndTheDropInsAreThoseOfWordPressAndThisPlugin'
e('mu-loader', 'mu_files(): without the loader of AlphaBridge', EN,
  "\t\t\tif ( self::loaded_mu( $dir, $name ) && self::LOADER !== $name ) {", "\t\t\tif ( self::loaded_mu( $dir, $name ) ) {", 'env', LOADER)
e('mu-php', 'loaded_mu(): the PHP files of its top level only', EN,
  "\t\treturn '.php' === substr( $name, -4 ) && is_file( $dir . '/' . $name );", "\t\treturn is_file( $dir . '/' . $name );", 'env', LOADER)
e('mu-file', 'loaded_mu(): files only', EN,
  "\t\treturn '.php' === substr( $name, -4 ) && is_file( $dir . '/' . $name );", "\t\treturn '.php' === substr( $name, -4 );", 'env', LOADER)
e('hash-file', 'file_hash(): no hash of what is no file', EN,
  "\t\t$sha  = is_file( $path ) && is_readable( $path ) ? @hash_file( 'sha256', $path ) : false;", "\t\t$sha  = is_readable( $path ) ? @hash_file( 'sha256', $path ) : false;", 'env', LOADER)

PLUG = 'testThePluginsAreFoundAsGetPluginsFindsThem'
e('plugins-dot', 'plugin_files(): no name beginning with a dot', EN,
  "\t\t\tif ( '.' === $name[0] ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'env', PLUG)
PF = "\t\t\t\tif ( ( null !== $file && '.' === $file[0] ) || '.php' !== substr( $rel, -4 ) || ! is_file( $dir . '/' . $rel ) ) {"
e('plugins-dot-inner', 'plugin_files(): no file beginning with a dot one level down', EN, PF, PF.replace("( null !== $file && '.' === $file[0] ) || ", ''), 'env', PLUG)
e('plugins-php', 'plugin_files(): PHP files only', EN, PF, PF.replace(" || '.php' !== substr( $rel, -4 )", ''), 'env', PLUG)
e('plugins-file', 'plugin_files(): files only', EN, PF, PF.replace(" || ! is_file( $dir . '/' . $rel )", ''), 'env', PLUG)
e('plugins-name', 'plugin_files(): a file whose head names a plugin only', EN,
  "\t\t\t\tif ( '' !== $head['Plugin Name'] ) {", "\t\t\t\tif ( true ) {", 'env', PLUG)
e('plugins-version', 'plugin_files(): no version where its head names none', EN,
  "\t\t\t\t\t$out[ $rel ] = '' === $head['Version'] ? null : self::name( $head['Version'] );", "\t\t\t\t\t$out[ $rel ] = self::name( $head['Version'] );", 'env', PLUG)
THEME = 'testTheThemesAreFoundAsSearchThemeDirectoriesFindsThem'
e('themes-folder', 'theme_dirs(): folders only, none beginning with a dot', EN,
  "\t\t\t\tif ( '.' === $name[0] || ! is_dir( $root . '/' . $name ) ) {", "\t\t\t\tif ( ! is_dir( $root . '/' . $name ) ) {", 'env', THEME)
e('themes-deeper', 'theme_dirs(): one level down where a folder has no style.css', EN,
  "\t\t\t\tif ( array() === $found ) {", "\t\t\t\tif ( false ) {", 'env', THEME)
e('themes-first', 'theme_dirs(): a stylesheet in two roots from the first', EN,
  "\t\t\t\t\tif ( ! array_key_exists( $stylesheet, $out ) ) {", "\t\t\t\t\tif ( true ) {", 'env', THEME)
HEAD = 'testTheHeadOfAFileIsReadAsGetFileDataReadsIt'
e('head-cr', 'head(): a carriage return ends a line', EN,
  "\t\t$text = str_replace( \"\\r\", \"\\n\", is_string( $text ) ? $text : '' );", "\t\t$text = is_string( $text ) ? $text : '';", 'env', HEAD)
e('head-8k', 'head(): the first 8 KiB only', EN,
  "@file_get_contents( $path, false, null, 0, 8192 )", "@file_get_contents( $path )", 'env', HEAD)
e('head-end', 'head(): without the end of a comment or of PHP', EN,
  "trim( (string) preg_replace( '/\\s*(?:\\*\\/|\\?>).*/', '', $m[1] ) )", "trim( $m[1] )", 'env', HEAD)

# ------------------------------------------------------------ lines
SHAPE = 'testALineWithAFieldMissingTooManyOrOfAnotherTypeIsNoLine'
SH1 = "\t\tif ( ! isset( $line['env'], $line['ref'] ) || ! is_string( $line['env'] ) || ! isset( self::LINES[ $line['env'] ] ) || ! is_string( $line['ref'] ) || '' === $line['ref'] || self::name( $line['ref'] ) !== $line['ref'] ) {"
e('shape-kind', 'shaped(): a kind of LINES only', EN, SH1, SH1.replace(" || ! isset( self::LINES[ $line['env'] ] )", " || ! is_array( self::LINES[ $line['env'] ] ?? array() )"), 'env', SHAPE)
e('shape-ref-text', 'shaped(): a ref is a text', EN, SH1, SH1.replace(" || ! is_string( $line['ref'] )", ''), 'env', SHAPE)
e('shape-ref-empty', 'shaped(): a ref is not empty', EN, SH1, SH1.replace(" || '' === $line['ref']", ''), 'env', SHAPE)
e('shape-ref-name', 'shaped(): a ref as name() writes it', EN, SH1, SH1.replace(" || self::name( $line['ref'] ) !== $line['ref']", ''), 'env', SHAPE)
e('shape-keys', 'shaped(): exactly the fields of its kind', EN,
  "\t\tif ( $keys !== $want ) {\n\t\t\treturn false;", "\t\tif ( false ) {\n\t\t\treturn false;", 'env', SHAPE)
e('shape-typed', 'shaped(): each field of its type', EN,
  "\t\t\tif ( ! self::typed( $line[ $field ], $type ) ) {", "\t\t\tif ( false ) {", 'env', SHAPE)
e('shape-page', 'shaped(): a page is named page, from part 1', EN,
  "\t\t\t\treturn 'page' === $ref && $line['seq'] >= 1;", "\t\t\t\treturn true;", 'env', SHAPE)
e('shape-tables', 'shaped(): the list of tables is named tables', EN,
  "\t\t\t\treturn 'tables' === $ref;", "\t\t\t\treturn true;", 'env', SHAPE)
e('shape-core', 'shaped(): core of its refs only', EN,
  "\t\t\t\treturn in_array( $ref, self::CORE, true );", "\t\t\t\treturn true;", 'env', SHAPE)
e('shape-dropin', 'shaped(): a drop-in of DROPINS only', EN,
  "\t\t\t\treturn in_array( $ref, self::DROPINS, true );", "\t\t\t\treturn true;", 'env', SHAPE)
FILES_SHAPE = "\t\t\t\treturn in_array( $ref, self::ROOTS, true ) && ( null === $line['why'] ) === $line['complete'];"
e('shape-root', 'shaped(): a root of ROOTS only', EN, FILES_SHAPE, "\t\t\t\treturn ( null === $line['why'] ) === $line['complete'];", 'env', SHAPE)
e('shape-why', 'shaped(): a why exactly where a root was not counted to its end', EN, FILES_SHAPE, "\t\t\t\treturn in_array( $ref, self::ROOTS, true );", 'env', SHAPE)
HOST_SHAPE = "\t\t\t\treturn null === $constant ? in_array( $ref, self::HOST, true ) && true === $line['defined'] : in_array( $constant, self::CONSTANTS, true ) && ( $line['defined'] || null === $line['value'] );"
e('shape-host', 'shaped(): group U of its refs only', EN, HOST_SHAPE, HOST_SHAPE.replace("in_array( $ref, self::HOST, true ) && ", ''), 'env', SHAPE)
e('shape-host-defined', 'shaped(): a value of the host is defined', EN, HOST_SHAPE, HOST_SHAPE.replace(" && true === $line['defined']", ''), 'env', SHAPE)
e('shape-constant', 'shaped(): a constant of CONSTANTS only', EN, HOST_SHAPE, HOST_SHAPE.replace("in_array( $constant, self::CONSTANTS, true ) && ", ''), 'env', SHAPE)
e('shape-constant-value', 'shaped(): no value of a constant not defined', EN, HOST_SHAPE, HOST_SHAPE.replace(" && ( $line['defined'] || null === $line['value'] )", ''), 'env', SHAPE)
e('typed-null', 'typed(): null only where the type allows it', EN,
  "\t\tif ( null === $value ) {\n\t\t\treturn $null;", "\t\tif ( null === $value ) {\n\t\t\treturn true;", 'env', SHAPE)
e('typed-text', 'typed(): a text as name() writes it', EN,
  "\t\t\tcase 'text':\n\t\t\t\treturn is_string( $value ) && self::name( $value ) === $value;", "\t\t\tcase 'text':\n\t\t\t\treturn is_scalar( $value );", 'env', SHAPE)
e('typed-bool', 'typed(): a bool', EN,
  "\t\t\tcase 'bool':\n\t\t\t\treturn is_bool( $value );", "\t\t\tcase 'bool':\n\t\t\t\treturn is_scalar( $value );", 'env', SHAPE)
e('typed-count', 'typed(): a whole number from 0', EN,
  "\t\t\t\treturn is_int( $value ) && $value >= 0;", "\t\t\t\treturn is_numeric( $value );", 'env', SHAPE)
e('typed-count-negative', 'typed(): no negative count', EN,
  "\t\t\t\treturn is_int( $value ) && $value >= 0;", "\t\t\t\treturn is_int( $value );", 'env', SHAPE)
e('typed-hash', 'typed(): 64 hex', EN,
  "\t\t\t\treturn is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}\\z/', $value );", "\t\t\t\treturn is_string( $value );", 'env', SHAPE)
e('typed-hex32', 'typed(): 32 hex', EN,
  "\t\t\t\treturn is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{32}\\z/', $value );", "\t\t\t\treturn is_string( $value );", 'env', SHAPE)
e('typed-scalar', 'typed(): a value of the host is no float', EN,
  "\t\t\t\treturn is_bool( $value ) || is_int( $value ) || ( is_string( $value ) && self::name( $value ) === $value );", "\t\t\t\treturn is_scalar( $value );", 'env', SHAPE)
NAME = 'testANameThatIsNoPlainTextIsHeldByItsBytes'
NM = "\t\treturn 1 === preg_match( '//u', $name ) && 1 !== preg_match( '/[\\x00-\\x1F\\x7F]/', $name ) && strlen( $name ) <= self::MAX_TEXT ? $name : 'bytes:' . bin2hex( substr( $name, 0, 512 ) );"
e('name-utf8', 'name(): text in UTF-8 only', EN, NM, NM.replace("1 === preg_match( '//u', $name ) && ", ''), 'env', NAME)
e('name-control', 'name(): no control character', EN, NM, NM.replace(" && 1 !== preg_match( '/[\\x00-\\x1F\\x7F]/', $name )", ''), 'env', NAME)
e('name-length', 'name(): no longer than a line holds', EN, NM, NM.replace(' && strlen( $name ) <= self::MAX_TEXT', ''), 'env', NAME)
STATE = 'testAStateIsOnlyOneReadWrites'
e('state-shaped', 'read(): a state only one read() writes', EN,
  "\t\t} elseif ( ! self::state_shaped( $state ) ) {", "\t\t} elseif ( false ) {", 'env', STATE)
e('state-keys', 'state_shaped(): exactly its fields', EN,
  "\t\tif ( array( 'after', 'attached', 'bytes', 'files', 'seq', 'skipped', 'step', 'walk' ) !== $keys ) {", "\t\tif ( false ) {", 'env', STATE)
e('state-counts', 'state_shaped(): its counts whole numbers from 0', EN,
  "\t\t\tif ( ! is_int( $state[ $count ] ) || $state[ $count ] < 0 ) {", "\t\t\tif ( false ) {", 'env', STATE)
ST = "\t\treturn is_int( $state['seq'] ) && $state['seq'] >= 1\n\t\t\t&& is_int( $state['step'] ) && $state['step'] >= 0 && $state['step'] < count( self::STEPS )\n\t\t\t&& is_string( $state['walk'] ) && 1 === preg_match( '/^[0-9a-f]{32}\\z/', $state['walk'] )\n\t\t\t&& ( null === $state['after'] || ( is_string( $state['after'] ) && '' !== $state['after'] ) );"
e('state-seq', 'state_shaped(): a part from 1', EN, ST, ST.replace(" && $state['seq'] >= 1", ''), 'env', STATE)
e('state-step', 'state_shaped(): a step of STEPS', EN, ST, ST.replace(" && $state['step'] < count( self::STEPS )", ''), 'env', STATE)
e('state-step-low', 'state_shaped(): no step below 0', EN, ST, ST.replace(" && $state['step'] >= 0", ''), 'env', STATE)
e('state-walk', 'state_shaped(): a walk of 32 hex', EN, ST, ST.replace(" && 1 === preg_match( '/^[0-9a-f]{32}\\z/', $state['walk'] )", ''), 'env', STATE)
e('state-after', 'state_shaped(): after a text that is not empty, or none', EN, ST, ST.replace(" && '' !== $state['after']", ''), 'env', STATE)

# ---------------------------------------------------------- compare
e('cmp-list-entry', 'compare(): the list of tables not read whole is an entry of its own', EN,
  "\t\t\tif ( 'table' === $env && ( ! $whole['s'] || ! $whole['l'] ) ) {\n\t\t\t\t// The list itself", "\t\t\tif ( false ) {\n\t\t\t\t// The list itself", 'env', 'testTablesOfASideWhoseListWasNotReadWholeAreNotCompared')
e('cmp-list-tables', 'entry(): no table compared where a list was not read whole', EN,
  "\t\tif ( 'table' === $env && ( ! $whole['s'] || ! $whole['l'] ) ) {\n\t\t\t$result = 'not_compared';", "\t\tif ( false ) {\n\t\t\t$result = 'not_compared';", 'env', 'testTablesOfASideWhoseListWasNotReadWholeAreNotCompared')
e('cmp-list-complete', 'compare(): a list of tables is whole only where it says so', EN,
  "\t\t\t's' => isset( $s['tables']['tables'] ) && true === $s['tables']['tables']['complete'],", "\t\t\t's' => isset( $s['tables']['tables'] ),", 'env', 'testTablesOfASideWhoseListWasNotReadWholeAreNotCompared')
e('cmp-list-live', 'compare(): the list of this site as well', EN,
  "\t\t\t'l' => isset( $l['tables']['tables'] ) && true === $l['tables']['tables']['complete'],", "\t\t\t'l' => isset( $l['tables']['tables'] ),", 'env', 'testTablesOfASideWhoseListWasNotReadWholeAreNotCompared')
e('cmp-one-side', 'entry(): a line on one side only', EN,
  "\t\t\t$result = null === $s ? 'only_live' : 'only_staging';", "\t\t\t$result = 'only_staging';", 'env', 'testAPluginOnOneSideOnlyIsLocked')
e('cmp-wordpress', 'judged(): WordPress of the same version is counted', EN,
  "\t\t\t\t$same   = 'wordpress' === $ref ? 'counted' : 'equal';", "\t\t\t\t$same   = 'equal';", 'env', 'testWordPressOfTheSameVersionIsCountedAndTheLanguageShownOnly')
e('cmp-locale', 'judged(): the language equal by its value', EN,
  "\t\t\t\t$same   = 'wordpress' === $ref ? 'counted' : 'equal';", "\t\t\t\t$same   = 'counted';", 'env', 'testWordPressOfTheSameVersionIsCountedAndTheLanguageShownOnly')
CODE = "\t\t\t\t$fields = array( 'version', 'active' );\n\t\t\t\t$same   = 'counted';"
e('cmp-code', 'judged(): a plugin or theme of the same version is counted, never equal', EN, CODE, CODE.replace("'counted'", "'equal'"), 'env', 'testAPluginOfTheSameVersionIsCountedNeverEqual')
e('cmp-code-active', 'judged(): whether it runs is compared', EN, CODE, CODE.replace("array( 'version', 'active' )", "array( 'version' )"), 'env', 'testAPluginOfAnotherVersionOrStateDiffers')
e('cmp-host-defined', 'judged(): a constant defined on one side differs', EN,
  "\t\t\t\tif ( $s['defined'] !== $l['defined'] ) {", "\t\t\t\tif ( false ) {", 'env', 'testTheValuesOfTheHostAreEqualOrDifferAndChangedAtTheHost')
e('cmp-host-undefined', 'judged(): not defined on both sides is equal', EN,
  "\t\t\t\tif ( ! $s['defined'] ) {\n\t\t\t\t\treturn array( 'equal', array(), null );", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn array( 'equal', array(), null );", 'env', 'testTheValuesOfTheHostAreEqualOrDifferAndChangedAtTheHost')
TABLE = "\t\t\t\t$fields = array( 'rows', 'schema' );\n\t\t\t\t$same   = 'counted';"
e('cmp-table', 'judged(): a table of the same rows and schema is counted, never equal', EN, TABLE, TABLE.replace("'counted'", "'equal'"), 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
e('cmp-table-schema', 'judged(): the schema of a table is compared', EN, TABLE, TABLE.replace("array( 'rows', 'schema' )", "array( 'rows' )"), 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
FILES = "\t\t\t\t$fields = array( 'files', 'bytes' );\n\t\t\t\t$same   = 'counted';"
e('cmp-files', 'judged(): a root of the same count is counted, never equal', EN, FILES, FILES.replace("'counted'", "'equal'"), 'env', 'testARootOfTheSameCountIsCountedNeverEqual')
e('cmp-files-bytes', 'judged(): the size of a root is compared', EN, FILES, FILES.replace("array( 'files', 'bytes' )", "array( 'files' )"), 'env', 'testARootOfTheSameCountIsCountedNeverEqual')
e('cmp-files-complete', 'judged(): a root not counted to its end is not compared', EN,
  "\t\t\t\tif ( ! $s['complete'] || ! $l['complete'] ) {", "\t\t\t\tif ( false ) {", 'env', 'testARootNotCountedToItsEndIsNotComparedWithWhy')
e('cmp-files-side', 'judged(): which side was not counted to its end', EN,
  "\t\t\t\t\t$side = $s['complete'] ? $l : $s;", "\t\t\t\t\t$side = $l;", 'env', 'testARootNotCountedToItsEndIsNotComparedWithWhy')
e('cmp-unread', 'judged(): a value that could not be read is not compared', EN,
  "\t\t$why = $unread( $fields );\n\t\tif ( null !== $why ) {", "\t\t$why = $unread( $fields );\n\t\tif ( false ) {", 'env', 'testAValueThatCouldNotBeReadIsNotCompared')
e('cmp-hash', 'judged(): a must-use plugin by its hash', EN,
  "\t\t\t\t$fields = array( 'sha256' );", "\t\t\t\t$fields = array( 'bytes' );", 'env', 'testAMustUsePluginIsEqualOrDiffersByItsHash')
e('entry-locale', 'entry(): the language is shown only', EN,
  "\t\tif ( 'core' === $env && 'locale' === $ref ) {", "\t\tif ( false ) {", 'env', 'testWordPressOfTheSameVersionIsCountedAndTheLanguageShownOnly')
e('entry-host', 'entry(): the drop-ins and the host are changed at the host', EN,
  "\t\t} elseif ( 'equal' !== $result && in_array( $env, array( 'dropin', 'host' ), true ) ) {", "\t\t} elseif ( false ) {", 'env', 'testADropInIsEqualOrDiffersAndIsChangedAtTheHost')
e('entry-host-equal', 'entry(): what is equal at the host asks nothing', EN,
  "\t\t} elseif ( 'equal' !== $result && in_array( $env, array( 'dropin', 'host' ), true ) ) {", "\t\t} elseif ( in_array( $env, array( 'dropin', 'host' ), true ) ) {", 'env', 'testTheValuesOfTheHostAreEqualOrDifferAndChangedAtTheHost')
e('entry-lock', 'entry(): code, files and tables are locked with their reason and stage', EN,
  "\t\t} elseif ( 'equal' !== $result && isset( self::LOCKS[ $key ] ) ) {", "\t\t} elseif ( false ) {", 'env', 'testEachLockHasItsKeyArtStageAndReason')
e('entry-lock-equal', 'entry(): what is equal is not locked', EN,
  "\t\t} elseif ( 'equal' !== $result && isset( self::LOCKS[ $key ] ) ) {", "\t\t} elseif ( isset( self::LOCKS[ $key ] ) ) {", 'env', 'testAMustUsePluginIsEqualOrDiffersByItsHash')
e('entry-lock-root', 'entry(): each root by its own lock', EN,
  "\t\t$key   = 'files' === $env ? 'files:' . $ref : $env;", "\t\t$key   = 'files' === $env ? 'files:uploads' : $env;", 'env', 'testEachLockHasItsKeyArtStageAndReason')
e('entry-counted', 'entry(): counted says its contents are not compared, and from when', EN,
  "\t\t\tif ( 'counted' === $result ) {\n\t\t\t\t$entry['contents'] = 'not compared';", "\t\t\tif ( false ) {\n\t\t\t\t$entry['contents'] = 'not compared';", 'env', 'testAPluginOfTheSameVersionIsCountedNeverEqual')
e('show-hash', 'show(): no hash in an entry', EN,
  "\t\tunset( $line['env'], $line['ref'], $line['sha256'], $line['schema'] );", "\t\tunset( $line['env'], $line['ref'] );", 'env', 'testNothingCountedIsEverEqualAndNothingEqualIsLocked')
e('show-present', 'show(): a hash of the host as present', EN,
  "\t\t\tif ( 1 === preg_match( '/^[0-9a-f]{64}\\z/', $line['value'] ) ) {", "\t\t\tif ( false ) {", 'env', 'testAValueHeldByItsHashIsNotShown')
e('show-not-shown', 'show(): a value held by its SHA-256 not shown', EN,
  "\t\t\t} elseif ( 0 === strpos( $line['value'], 'sha256:' ) ) {", "\t\t\t} elseif ( false ) {", 'env', 'testAValueHeldByItsHashIsNotShown')
e('counted-blocked', 'counted(): each lock with how many entries', EN,
  "\t\t\t\t++$blocked[ $lock['key'] ]['entries'];", '', 'env', 'testEachLockHasItsKeyArtStageAndReason')
e('by-ref-page', 'by_ref(): the pages are no entries', EN,
  "\t\t\tif ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) || 'page' === $line['env'] ) {", "\t\t\tif ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) ) {", 'env', 'testTheEntriesComeInTheOrderOfTheirKindThenByRef')

# --------------------------------------------- reading, on a WordPress site
UP = 'testTheUploadsAreCountedOverPartsOutsideTheFileListsOfAttachments'
e('read-seq', 'read(): the next part has the next number', EN,
  "\t\t$state['seq'] = (int) $state['seq'] + 1;", '', 'it', UP)
e('read-last', 'read(): the page says whether it is the last', EN,
  "\t\t\t\t'last' => $done,", "\t\t\t\t'last' => true,", 'it', UP)
e('read-clock', 'read(): a part ends when the clock says so', EN,
  "\t\t\tif ( self::now() >= $deadline || count( $lines ) + 1 >= $max ) {", "\t\t\tif ( count( $lines ) + 1 >= $max ) {", 'it', UP)
e('read-lines', 'read(): a part ends at its lines', EN,
  "\t\t\tif ( self::now() >= $deadline || count( $lines ) + 1 >= $max ) {", "\t\t\tif ( self::now() >= $deadline ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('read-tables-on', 'read(): the tables go on where the part before ended', EN,
  "\t\t\t\tif ( ! $read['done'] ) {\n\t\t\t\t\t$state['after'] = $read['after'];", "\t\t\t\tif ( ! $read['done'] ) {\n\t\t\t\t\t$state['after'] = null;", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('tables-after', 'table_lines(): after the table the part before ended with', EN,
  "\t\t\tif ( null !== $after && strcmp( $short, $after ) <= 0 ) {", "\t\t\tif ( null !== $after && strcmp( $short, $after ) < 0 ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('tables-one', 'table_lines(): one table a part at least', EN,
  "\t\t\tif ( array() !== $lines && ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) ) {", "\t\t\tif ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('tables-room', 'table_lines(): a part ends at its room', EN,
  "\t\t\tif ( array() !== $lines && ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) ) {", "\t\t\tif ( array() !== $lines && ( self::now() >= $deadline ) ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('tables-core', 'tables(): no table of the core', EN,
  "\t\tforeach ( (array) $wpdb->tables( 'all', true ) as $table ) {\n\t\t\t$known[ (string) $table ] = true;", "\t\tforeach ( array() as $table ) {\n\t\t\t$known[ (string) $table ] = true;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-own', 'tables(): no table of the takeover', EN,
  "\t\tforeach ( AB_MCP_Sync_Schema::TABLES as $table ) {\n\t\t\t$known[ $wpdb->prefix . $table ] = true;", "\t\tforeach ( array() as $table ) {\n\t\t\t$known[ $wpdb->prefix . $table ] = true;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-prefix', 'tables(): the name without the prefix', EN,
  "\t\t\t$out[] = (string) substr( $table, strlen( $wpdb->prefix ) );", "\t\t\t$out[] = $table;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
MAIN = 'testTheEnvironmentOfBothSitesIsReadAndComparedNeverEqualWhereOnlyCounted'
e('rows', 'table_lines(): the rows of a table', EN,
  "\t\t\t$rows    = '' === (string) $wpdb->last_error && is_numeric( $rows ) ? (int) $rows : null;", "\t\t\t$rows    = 0;", 'it', MAIN)
e('active', 'plugins(): whether it runs, from the stored row', EN,
  "\t\t\t\t\t'active'  => null === $active ? null : in_array( (string) $file, $active, true ),", "\t\t\t\t\t'active'  => true,", 'it', MAIN)
e('uploads-skip', 'skip_of(): the uploads without fonts and the folders of AlphaBridge', EN,
  "\t\t\t\treturn 'fonts' === $name || in_array( $name, self::OWN_UPLOADS, true ) || 0 === strpos( $name, self::OWN_UPLOADS_PREFIX );", "\t\t\t\treturn 'fonts' === $name;", 'it', UP)
e('uploads-attached', 'root(): the uploads against the file lists of the attachments', EN,
  "\t\tif ( 'uploads' === $root ) {\n\t\t\t$known = self::attached( $deadline );", "\t\tif ( false ) {\n\t\t\t$known = self::attached( $deadline );", 'it', UP)
e('mu-root-skip', 'skip_of(): the must-use folder without its loaded files', EN,
  "\t\t\t\treturn self::loaded_mu( $dir, $name );", "\t\t\t\treturn false;", 'it', MAIN)
e('dropin-there', 'site_lines(): a drop-in that is there only', EN,
  "\t\t\tif ( '' !== $content && file_exists( $path ) ) {", "\t\t\tif ( '' !== $content ) {", 'it', MAIN)
e('loader', 'host(): the must-use loader by its hash, absent without it', EN,
  "\t\t$loader = '' === $mu || ! file_exists( $mu ) ? 'absent' : self::file_hash( $mu )['sha256'];", "\t\t$loader = 'absent';", 'it', MAIN)

# ------------------------------------------------- packages of the environment
e('export-env', 'make(): a package of the environment holds the environment', EX,
  "\t\tif ( 'env' === $purpose ) {\n\t\t\treturn self::make_env(", "\t\tif ( false ) {\n\t\t\treturn self::make_env(", 'it', MAIN)
e('export-follows-purpose', 'follows(): a cursor goes on with a package of its purpose', EX,
  "\t\tif ( $purpose !== $before['manifest']['purpose'] ) {\n\t\t\treturn self::cursor_refusal();", "\t\tif ( false ) {\n\t\t\treturn self::cursor_refusal();", 'it', 'testThePackagesOfTheEnvironmentAreOneWholeSeriesInItsOrder')
e('export-env-state', 'make_env(): the next package goes on from the state of the one before', EX,
  "\t\t\t$state  = $follows[0]['inner'];", "\t\t\t$state  = null;", 'it', 'testThePackagesOfTheEnvironmentAreOneWholeSeriesInItsOrder')
e('import-env', 'unpacked(): the lines of a package of the environment by their own structure', IM,
  "\t\t$env      = 'env' === $manifest['purpose'];", "\t\t$env      = false;", 'it', MAIN)
e('import-env-shape', 'unpacked(): a line of the environment of another shape refuses the package', IM,
  "\t\t\t\t\tif ( ! AB_MCP_Sync_Env::shaped( $object ) ) {", "\t\t\t\t\tif ( false ) {", 'it', 'testALineOfTheEnvironmentOfAnotherShapeRefusesThePackage')

# ------------------------------------------------------------- the report
SER = 'testThePackagesOfTheEnvironmentAreOneWholeSeriesInItsOrder'
ARG = "\t\tif ( ! is_array( $environment ) || ! array_is_list( $environment ) || count( $environment ) > self::MAX_ENVIRONMENT || count( array_unique( array_map( 'strval', $environment ) ) ) !== count( $environment ) || array() !== array_intersect( array_map( 'strval', $environment ), array_map( 'strval', $packages ) ) ) {"
e('arg-array', 'call(): environment is a list', RE, ARG, ARG.replace('! is_array( $environment ) || ', ''), 'it', SER)
e('arg-list', 'call(): environment is a list, not a map', RE, ARG, ARG.replace(' ! array_is_list( $environment ) ||', ''), 'it', SER)
e('arg-max', 'call(): at most MAX_ENVIRONMENT packages', RE, ARG, ARG.replace(' count( $environment ) > self::MAX_ENVIRONMENT ||', ''), 'it', SER)
e('arg-once', 'call(): each package once', RE, ARG, ARG.replace(" count( array_unique( array_map( 'strval', $environment ) ) ) !== count( $environment ) ||", ''), 'it', SER)
e('arg-apart', 'call(): none of the packages of the inventory', RE, ARG, ARG.replace(" || array() !== array_intersect( array_map( 'strval', $environment ), array_map( 'strval', $packages ) )", ''), 'it', SER)
SERIES = "\t\t\tif ( ! is_array( $page ) || 'page' !== ( $page['env'] ?? null ) || $i + 1 !== $page['seq'] || ( null !== $walk && $walk !== $page['walk'] ) || ( count( $ids ) === $i + 1 ) !== $page['last'] ) {"
e('series-page', 'environment(): each package begins with its page', RE, SERIES, SERIES.replace(" 'page' !== ( $page['env'] ?? null ) ||", ''), 'it', 'testALineOfTheEnvironmentOfAnotherShapeRefusesThePackage')
e('series-seq', 'environment(): the parts in their order, without a gap', RE, SERIES, SERIES.replace(' $i + 1 !== $page[\'seq\'] ||', ''), 'it', SER)
e('series-walk', 'environment(): the parts of one reading', RE, SERIES, SERIES.replace(" ( null !== $walk && $walk !== $page['walk'] ) ||", ''), 'it', SER)
e('series-last', 'environment(): the last part last', RE, SERIES, SERIES.replace(" || ( count( $ids ) === $i + 1 ) !== $page['last']", ''), 'it', SER)
e('series-taken', 'environment(): each a package of the environment taken over here', RE,
  "\t\t\t$part = AB_MCP_Sync_Import::taken( $id, 'env' );", "\t\t\t$part = AB_MCP_Sync_Import::taken( $id, 'inventory' );", 'it', MAIN)
e('env-min', 'live_environment(): with less than ENV_MIN seconds left the next call reads it', RE,
  "\t\t\tif ( $left < self::ENV_MIN ) {", "\t\t\tif ( false ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('env-on', 'live_environment(): a part goes on from the state of the one before', RE,
  "\t\t\t$read = AB_MCP_Sync_Env::read( false === $last ? null : $last['state'],", "\t\t\t$read = AB_MCP_Sync_Env::read( null,", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('env-parts', 'parse_name(): the parts of the environment are files of a report', P,
  "'/^([hx])([a-z2-7]{52})\\z|^([cdeiv])([0-9]{4})-([a-z2-7]{52})\\z/'", "'/^([hx])([a-z2-7]{52})\\z|^([cdei])([0-9]{4})-([a-z2-7]{52})\\z/'", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('without', 'finish(): without packages of the environment one entry, not compared', RE,
  "\t\t$environment = array() === $ids ? AB_MCP_Sync_Env::without() : AB_MCP_Sync_Env::compare( $env_side['lines'], $env );", "\t\t$environment = AB_MCP_Sync_Env::compare( $env_side['lines'], $env );", 'it', 'testWithoutEnvironmentTheReportSaysItIsNotComparedWithTheWay')
e('numbered', 'finish(): the environment numbered on after the objects', RE,
  "\t\t\t$report['entries'][] = array( 'n' => ++$n ) + $entry;", "\t\t\t$report['entries'][] = array( 'n' => $n ) + $entry;", 'it', MAIN)
e('blocked', 'finish(): the locks of the environment with the others', RE,
  "\t\tforeach ( $environment['blocked'] as $lock ) {\n\t\t\t$blocked[] = $lock;", "\t\tforeach ( array() as $lock ) {\n\t\t\t$blocked[] = $lock;", 'it', MAIN)


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
 * The clauses of the environment of the report (Z8, branch 7 of the plan:
 * decision 14, «Lesende Bestandsaufnahme der übrigen Arten»): the walk of a
 * root of files (one order, a link not followed, the attached paths apart,
 * what a root skips, a folder that cannot be read, the same counts over
 * parts), the block of the .htaccess, the schema of a table, the constants
 * (none that holds a secret, the rules of the site), the structure of a line
 * of a package, the comparison (counted never equal, equal only by a hash or
 * a value, not compared with why, each lock with its stage, the host changed
 * there, no hash shown), reading in parts on a WordPress site (the pages,
 * the tables, the uploads against the file lists of attachments), the
 * packages of the environment in wp_site_export and wp_site_import_chunk,
 * and the environment in wp_site_diff (the argument, one whole series, the
 * environment of this site in parts, the entries numbered on, the locks).
 *
 * Written by tests/mutation/gen/inventar.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncEnvTest) and, for what two WordPress sites
 * show, by the integration suite (SyncEnvTest; the sites running,
 * AB_IT_ROOT set). Not listed: the guards against a stored part of the
 * environment of the report that is not as this code wrote it (named by its
 * own SHA-256 in the store; no input reaches them), the expiry of a report
 * by the packages of the environment (made within seconds of the packages of
 * the inventory, so no test tells the two apart), and the readers whose
 * failure WordPress gives no way to cause on a test site (SHOW TABLES,
 * COUNT, SHOW CREATE TABLE failing; the rows of WPLANG, stylesheet,
 * template and active_plugins unreadable); GRENZEN-BERICHT.md, Zweig 7.
 *
 * @package AlphaBridge_MCP
 */

/*
 * The run of an entry: the test its expect names, alone, in the unit suite
 * or in the integration suite (-c; the sites running).
 */
$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$en = 'includes/sync/class-sync-env.php';
$re = 'includes/sync/class-sync-report.php';
$ex = 'includes/sync/class-sync-export.php';
$im = 'includes/sync/class-sync-import.php';
$p  = 'includes/sync/class-sync-proposal.php';

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
open(os.path.join(ROOT, 'tests/mutation/lists/inventar.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
