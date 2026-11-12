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
NET = 'includes/sync/class-sync-net.php'
FILEVAR = {EN: '$en', RE: '$re', EX: '$ex', IM: '$im', P: '$p', NET: '$net'}

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
e('walk-after-mark', 'walk(): the last file counted is where the next part goes on', EN,
  "\t\t\t} else {\n\t\t\t\t++$acc['skipped'];\n\t\t\t}\n\t\t\t$acc['after'] = $path;", "\t\t\t} else {\n\t\t\t\t++$acc['skipped'];\n\t\t\t}", 'env', 'testAWalkOverOneLargeFolderMovesOnInEveryPart')

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
  "\t\t$text   = (string) preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', (string) $create );", "\t\t$text   = (string) $create;", 'env', SCHEMA)
SCHEMA_RET = "\t\t\t\treturn in_array( $word, self::SCHEMA_NAMES, true ) && 0 === strpos( $token, $prefix ) ? '`' . substr( $token, strlen( $prefix ) ) : $token;"
e('schema-prefix', 'schema(): without the prefix of the site', EN, SCHEMA_RET, "\t\t\t\treturn $token;", 'env', SCHEMA)
NAMES = 'testOnlyTheNamesOfTablesLoseThePrefixNeverAColumnOrAnIndex'
SCHEMA_WORDS = "\tconst SCHEMA_NAMES = array( 'TABLE', 'CONSTRAINT', 'REFERENCES' );"
e('gh1-schema-names-only', 'schema(): the prefix out of the names of tables and constraints only, never of a column or an index (GitHub-Codex, PR #136, P2)', EN,
  SCHEMA_RET, SCHEMA_RET.replace('in_array( $word, self::SCHEMA_NAMES, true ) && ', ''), 'env', NAMES)
e('gh1-schema-table', 'schema(): the name of the table without the prefix (GitHub-Codex, PR #136, P2)', EN,
  SCHEMA_WORDS, SCHEMA_WORDS.replace("'TABLE', ", ''), 'env', NAMES)
e('gh1-schema-constraint', 'schema(): the name of a constraint without the prefix (GitHub-Codex, PR #136, P2)', EN,
  SCHEMA_WORDS, SCHEMA_WORDS.replace("'CONSTRAINT', ", ''), 'env', NAMES)
e('gh1-schema-references', 'schema(): the table a foreign key references without the prefix (GitHub-Codex, PR #136, P2)', EN,
  SCHEMA_WORDS, SCHEMA_WORDS.replace(", 'REFERENCES'", ''), 'env', NAMES)
e('gh1-schema-after-name', 'schema(): only the name right after the word, not the columns after it (GitHub-Codex, PR #136, P2)', EN,
  "\t\t\t\t$word   = $before;\n\t\t\t\t$before = null;", "\t\t\t\t$word   = $before;", 'env', NAMES)
e('gh1-schema-quoted', 'schema(): a text in quotes is no name (GitHub-Codex, PR #136, P2)', EN,
  "\"/'(?:[^'\\\\\\\\]|\\\\\\\\.)*'|`", "\"/`", 'env', NAMES)
e('gh1-schema-backslash', 'schema(): a backslash in a text in quotes escapes what follows (GitHub-Codex, PR #136, P2)', EN,
  "\"/'(?:[^'\\\\\\\\]|\\\\\\\\.)*'|`", "\"/'(?:[^'\\\\\\\\])*'|`", 'env', NAMES)
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

PLUG = 'testThePluginsAreFoundAsGetPluginsFindsThem'
e('plugins-dot', 'plugin_files(): no name beginning with a dot', EN,
  "\t\t\tif ( '.' === $name[0] ) {\n\t\t\t\tcontinue;", "\t\t\tif ( false ) {\n\t\t\t\tcontinue;", 'env', PLUG)
PF = "\t\t\t\tif ( ( null !== $file && '.' === $file[0] ) || '.php' !== substr( $rel, -4 ) ) {"
e('plugins-dot-inner', 'plugin_files(): no file beginning with a dot one level down', EN, PF, PF.replace("( null !== $file && '.' === $file[0] ) || ", ''), 'env', PLUG)
e('plugins-php', 'plugin_files(): PHP files only', EN, PF, PF.replace(" || '.php' !== substr( $rel, -4 )", ''), 'env', PLUG)
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
  "\t\t\t\treturn 'page' === $ref && $line['seq'] >= 1 && AB_MCP_Sync_File_Manifest::FORMAT === $line['files'];", "\t\t\t\treturn AB_MCP_Sync_File_Manifest::FORMAT === $line['files'];", 'env', SHAPE)
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
NAME = 'testANameThatIsNoPlainTextIsShownByItsSha256'
NM = "\t\treturn 1 === preg_match( '//u', $name ) && 1 !== preg_match( '/[\\x00-\\x1F\\x7F]/', $name ) && strlen( $name ) <= self::MAX_TEXT ? $name : self::hashed( $name );"
e('name-utf8', 'name(): text in UTF-8 only', EN, NM, NM.replace("1 === preg_match( '//u', $name ) && ", ''), 'env', NAME)
e('name-control', 'name(): no control character', EN, NM, NM.replace(" && 1 !== preg_match( '/[\\x00-\\x1F\\x7F]/', $name )", ''), 'env', NAME)
e('name-length', 'name(): no longer than a line holds', EN, NM, NM.replace(' && strlen( $name ) <= self::MAX_TEXT', ''), 'env', NAME)
# Codex, round 1 of branch 7, C6: the form for a text a line does not hold is one it holds.
e('r1-name-fixed', 'name(): what is no plain text by its SHA-256, a text a line holds (C6)', EN, NM, NM.replace('self::hashed( $name )', "'bytes:' . bin2hex( substr( $name, 0, 512 ) )"), 'env', 'testAVersionLongerThanALineHoldsIsKeptByItsSha256AndNotShown')
e('r1-show-version', 'show(): a value by its SHA-256 not shown, a version too (C6)', EN,
  "\t\t\tif ( is_string( $value ) && 0 === strpos( $value, 'sha256:' ) ) {", "\t\t\tif ( false ) {", 'env', 'testAVersionLongerThanALineHoldsIsKeptByItsSha256AndNotShown')
STATE = 'testAStateIsOnlyOneReadWrites'
e('state-shaped', 'read(): a state only one read() writes', EN,
  "\t\t} elseif ( ! self::state_shaped( $state ) ) {", "\t\t} elseif ( false ) {", 'env', STATE)
e('state-keys', 'state_shaped(): exactly its fields', EN,
  "\t\tif ( array( 'after', 'attached', 'bytes', 'files', 'seq', 'skipped', 'step', 'walk' ) !== $keys ) {", "\t\tif ( false ) {", 'env', STATE)
e('state-counts', 'state_shaped(): its counts whole numbers from 0', EN,
  "\t\t\tif ( ! is_int( $state[ $count ] ) || $state[ $count ] < 0 ) {", "\t\t\tif ( false ) {", 'env', STATE)
ST = "\t\treturn is_int( $state['seq'] ) && $state['seq'] >= 1\n\t\t\t&& is_int( $state['step'] ) && $state['step'] >= 0 && $state['step'] < count( self::STEPS )\n\t\t\t&& is_string( $state['walk'] ) && 1 === preg_match( '/^[0-9a-f]{32}\\z/', $state['walk'] )\n\t\t\t&& ( null === $state['after'] || self::place_shaped( $state['after'] ) );"
e('state-seq', 'state_shaped(): a part from 1', EN, ST, ST.replace(" && $state['seq'] >= 1", ''), 'env', STATE)
e('state-step', 'state_shaped(): a step of STEPS', EN, ST, ST.replace(" && $state['step'] < count( self::STEPS )", ''), 'env', STATE)
e('state-step-low', 'state_shaped(): no step below 0', EN, ST, ST.replace(" && $state['step'] >= 0", ''), 'env', STATE)
e('state-walk', 'state_shaped(): a walk of 32 hex', EN, ST, ST.replace(" && 1 === preg_match( '/^[0-9a-f]{32}\\z/', $state['walk'] )", ''), 'env', STATE)
e('state-after', 'state_shaped(): after a place as place() writes it, or none', EN, ST, ST.replace(" || self::place_shaped( $state['after'] )", ' || true'), 'env', STATE)
# Codex, round 1 of branch 7, A1 and C5: the place of a part by its parts, a held one by its SHA-256.
PL = "\t\tif ( ! is_array( $place ) || array() === $place || ! array_is_list( $place ) ) {"
e('r1-place-list', 'place_shaped(): a list (A1, C5)', EN, PL, PL.replace(' || ! array_is_list( $place )', ''), 'env', STATE)
e('r1-place-empty', 'place_shaped(): not empty (A1, C5)', EN, PL, PL.replace(' || array() === $place', ''), 'env', STATE)
# Codex, round 2 of branch 7, A1: the place of a part holds no name, only the SHA-256 of each folder and of the whole path.
PS = "\t\t\tif ( ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $hash ) ) {"
e('r2-place-hex', 'place_shaped(): each a SHA-256 of 64 hex (A1)', EN, PS, PS.replace(" || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $hash )", ''), 'env', STATE)
e('r2-place-text', 'place_shaped(): each a text (A1)', EN, PS, PS.replace("! is_string( $hash ) || ", ''), 'env', STATE)
PLACE = 'testAPlaceHoldsNoNameOnlyTheSha256OfEachFolderAndOfTheWholePath'
e('r2-place-prefix', 'place(): the SHA-256 of the path up to each folder, not of the folder alone (A1)', EN,
  "\t\t\t$at    = null === $at ? $part : $at . '/' . $part;", "\t\t\t$at    = $part;", 'env', PLACE)
e('r2-place-each', 'place(): one SHA-256 per folder (A1)', EN,
  "\t\t\t$out[] = hash( 'sha256', $at );", "\t\t\t$out   = array( hash( 'sha256', $at ) );", 'env', PLACE)
PSPLIT = "\t\tforeach ( $split ? explode( '/', (string) $path ) : array( (string) $path ) as $part ) {"
e('r2-place-split', 'place(): a path through its folders (A1)', EN, PSPLIT, "\t\tforeach ( array( (string) $path ) as $part ) {", 'env', PLACE)
e('r2-place-table', 'place(): a table by its whole name, a «/» in it too (A1)', EN, PSPLIT, "\t\tforeach ( explode( '/', (string) $path ) as $part ) {", 'env', PLACE)
e('r2-found-path', 'found(): the SHA-256 of the path up to the name (A1)', EN,
  "\t\t\t\t$at = null === $path ? (string) $name : $path . '/' . $name;", "\t\t\t\t$at = (string) $name;", 'env', PLACE)
e('r2-found-gone', 'found(): a folder or entry gone: none (A1)', EN,
  "\t\t\tif ( null === $hit ) {\n\t\t\t\treturn null;", "\t\t\tif ( false ) {\n\t\t\t\treturn null;", 'env', PLACE)
e('r2-found-hit', 'found(): the name found is the path to go on in (A1)', EN,
  "\t\t\t$path = $hit;", '', 'env', PLACE)
# held()
HELD_T = 'testANameWithACredentialWrittenIntoItIsHeld'
# What the content check alone holds of a name of the site: a field of a form
# as the HTML API of the core reads it (the unit suite has no HTML API, so the
# integration suite; the net and keyed() hold the other fields).
FORM_IT = 'testANameWithAFieldOfAFormOfACredentialIsHeldByTheContentCheckAsTheHtmlApiOfTheCoreReadsIt'
e('r1-held-field', 'held(): a field of a credential in a name, as the content check reads it (A1)', EN,
  "\t\tif ( 'mixed' === $check['state'] ) {", "\t\tif ( false ) {", 'it', FORM_IT)
e('r1-held-unreadable', 'held(): a name the check does not read to its end (A1)', EN,
  "\t\tif ( 'unreadable' === $check['state'] ) {", "\t\tif ( false ) {", 'env', HELD_T)
e('r1-held-net', 'held(): the net over the raw name (A1)', EN,
  "\t\tif ( 'credential' === $net ) {", "\t\tif ( false ) {", 'env', HELD_T)
e('r1-held-net-unreadable', 'held(): a name the net does not read to its end (A1)', EN,
  "\t\treturn 'unreadable' === $net ? 'unreadable_name' : null;", "\t\treturn null;", 'env', HELD_T)
e('r1-held-extra', 'held(): the names of ab_mcp_redact_keys (A1)', EN,
  "\t\t$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();\n\t\t$check", "\t\t$extra = is_array( $extra ) ? $extra : array();\n\t\t$check", 'env', HELD_T)
COLON = 'testANameWithACredentialAfterAColonIsHeldAsOneAfterAnEqualsSign'
e('r2-held-bare', 'held(): a name of the site also after a colon without quotes (A1)', EN,
  "\t\t$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra, true );", "\t\t$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra );", 'env', COLON)
KEYED = 'testANameWithTheNameOfACredentialBeforeAnEqualsSignOrAColonIsHeldWhateverFollows'
KEYED_IF = "\t\t\tif ( $site && self::keyed( $layer[1], $extra ) ) {"
e('r3-net-keyed', 'over_name(): the name of a credential before = or :, for a name of the site (A1)', NET, KEYED_IF, "\t\t\tif ( false ) {", 'env', KEYED)
e('r3-net-keyed-only', 'over_name(): only where it is asked for (a name of the inventory keeps its rule) (A1)', NET, KEYED_IF, KEYED_IF.replace('$site && ', ''), 'env', KEYED)
SIGNS = "\t\tif ( ! preg_match_all( '/[=:;\"\\']/', $text, $m, PREG_OFFSET_CAPTURE ) ) {"
e('r3-keyed-colon', 'keyed(): before a colon (A1)', NET, SIGNS, SIGNS.replace('[=:', '[=', 1), 'env', KEYED)
e('r3-keyed-equals', 'keyed(): before an equals sign (A1)', NET, SIGNS, SIGNS.replace('[=:', '[:', 1), 'env', KEYED)
QUOTED = 'testANameWithTheNameOfACredentialBeforeASemicolonOrAQuoteIsHeldWhateverFollows'
e('r4-keyed-semicolon', 'keyed(): before a semicolon (A1, serialized)', NET, SIGNS, SIGNS.replace(':;', ':', 1), 'env', QUOTED)
e('r4-keyed-double-quote', 'keyed(): before a double quote (A1, a quoted key)', NET, SIGNS, SIGNS.replace(';\"', ';', 1), 'env', QUOTED)
e('r4-keyed-single-quote', 'keyed(): before a single quote (A1, a quoted key)', NET, SIGNS, SIGNS.replace('\\\']', ']', 1), 'env', QUOTED)
EACH = "\t\tforeach ( $m[0] as $hit ) {\n\t\t\t$before"
e('r3-keyed-each-first', 'keyed(): each = and :, not only the first (A1, Codex round 3)', NET, EACH, EACH.replace('$m[0] as', 'array_slice( $m[0], 0, 1 ) as'), 'env', KEYED)
e('r3-keyed-each-last', 'keyed(): each = and :, not only the last (A1)', NET, EACH, EACH.replace('$m[0] as', 'array_slice( $m[0], -1 ) as'), 'env', KEYED)
BEFORE = "\t\t\t$before = rtrim( (string) substr( $text, 0, (int) $hit[1] ), \" \\t\\n\\r\\x0B\\f\" );"
e('r3-keyed-space', 'keyed(): white space before the sign left out (A1)', NET, BEFORE, "\t\t\t$before = (string) substr( $text, 0, (int) $hit[1] );", 'env', KEYED)
FORMS = "\t\t\tforeach ( array( strtolower( $before ), AB_MCP_Redactor::normalize_key( $before ) ) as $form ) {"
e('r3-keyed-lower', 'keyed(): the text before it in lower case (A1)', NET, FORMS, FORMS.replace('strtolower( $before ), ', ''), 'env', KEYED)
e('r3-keyed-normal', 'keyed(): the text before it as normalize_key() reads it (A1)', NET, FORMS, FORMS.replace(', AB_MCP_Redactor::normalize_key( $before )', ''), 'env', KEYED)
KEYS = "\t\t$keys = array_merge( AB_MCP_Redactor::CREDENTIAL_EXACT, AB_MCP_Redactor::EXTRA_NAMES, $extra );"
e('r3-keyed-exact', 'keyed(): the names of CREDENTIAL_EXACT (A1)', NET, KEYS, KEYS.replace('AB_MCP_Redactor::CREDENTIAL_EXACT, ', ''), 'env', KEYED)
e('r3-keyed-extra-names', 'keyed(): the names of EXTRA_NAMES (A1)', NET, KEYS, KEYS.replace('AB_MCP_Redactor::EXTRA_NAMES, ', ''), 'env', KEYED)
e('r3-keyed-redact-keys', 'keyed(): the names of ab_mcp_redact_keys (A1)', NET, KEYS, KEYS.replace(', $extra', ''), 'env', KEYED)
ENDS = "\t\t\t\t\tif ( '' !== (string) $key && str_ends_with( $form, (string) $key ) ) {"
e('r3-keyed-empty', 'keyed(): an empty name is none (A1)', NET, ENDS, ENDS.replace("'' !== (string) $key && ", ''), 'env', KEYED)
e('r3-keyed-ends', 'keyed(): the text before it ends in the name, not only holds it (A1)', NET, ENDS, ENDS.replace('str_ends_with', 'str_contains'), 'env', KEYED)
WORD = "\t\t\tif ( preg_match( '/[A-Za-z0-9_\\-.\\x80-\\xFF]+\\z/', $before, $word ) && AB_MCP_Sync_Credentials::names_credential( $word[0], $extra ) ) {"
e('r3-keyed-word', 'keyed(): the last word before it names a credential as the check does (A1)', NET, WORD, "\t\t\tif ( false ) {", 'env', KEYED)
e('r3-keyed-word-last', 'keyed(): the last word, right before the sign (A1)', NET, WORD, WORD.replace('+\\z/', '+/'), 'env', KEYED)
THERE = 'testALineOfThisSiteWhoseNameTheStagingSiteHoldsIsHeldBeforeItIsStored'
HN = "\t\t\tif ( is_array( $line ) && isset( $line['env'], $line['ref'], $line['held'] ) && in_array( $line['env'], self::NAMED, true ) && 1 === preg_match( self::HASHED, (string) $line['ref'] ) ) {"
e('r2-held-names-held', 'held_names(): the lines held only (A1)', EN, HN, HN.replace(", $line['held'] )", ' )'), 'env', THERE)
e('r2-held-names-code', 'held_names(): each with why (A1)', EN,
  "\t\t\t\t$out[ (string) $line['ref'] ] = (string) $line['held'];", "\t\t\t\t$out[ (string) $line['ref'] ] = 'credential_field';", 'env', THERE)
e('r2-held-names-order', 'held_names(): in one order (A1)', EN, "\t\tksort( $out, SORT_STRING );\n\t\treturn $out;", "\t\treturn $out;", 'env', THERE)
HT = "\t\t\tif ( ! is_array( $line ) || ! isset( $line['env'], $line['ref'] ) || isset( $line['held'] ) || ! in_array( $line['env'], self::NAMED, true ) ) {"
e('r2-held-there-here', 'held_there(): a line held here already stays as it is (A1)', EN, HT, HT.replace(" || isset( $line['held'] )", ''), 'env', THERE)
e('r2-held-there-named', 'held_there(): a name of the site only (A1)', EN, HT, HT.replace(" || ! in_array( $line['env'], self::NAMED, true )", ''), 'env', THERE)
e('r2-held-there-hashed', 'held_there(): a name by its SHA-256 already by that SHA-256 (A1)', EN,
  "\t\t\t$hashed = 1 === preg_match( self::HASHED, $ref ) ? $ref : self::hashed( $ref );", "\t\t\t$hashed = self::hashed( $ref );", 'env', THERE)
e('r2-held-there-reduced', 'held_there(): a name the staging site holds by its SHA-256 with why (A1)', EN,
  "\t\t\t\t$lines[ $i ] = self::reduced( $line, (string) $names[ $hashed ] );", '', 'env', THERE)
SHAPE_HELD = "\t\tif ( $held && ( ! in_array( $line['env'], self::NAMED, true ) || ! in_array( $line['held'], self::HELD, true ) || 1 !== preg_match( self::HASHED, $line['ref'] ) ) ) {"
LH = 'testALineOfAHeldNameCarriesItsSha256AndWhyOnly'
e('r1-shape-held-code', 'shaped(): held with a code of HELD (A1)', EN, SHAPE_HELD, SHAPE_HELD.replace(" || ! in_array( $line['held'], self::HELD, true )", ''), 'env', LH)
e('r1-shape-held-ref', 'shaped(): a held name by its SHA-256 only (A1)', EN, SHAPE_HELD, SHAPE_HELD.replace(" || 1 !== preg_match( self::HASHED, $line['ref'] )", ''), 'env', LH)
e('r1-shape-held-field', 'shaped(): held a field of the line (A1)', EN,
  "$held ? array( 'held' ) : array()", "array()", 'env', LH)
IH = 'testALineTheImportHeldIsShownByItsSha256'
e('r1-reduced-ref', 'reduced(): the ref by its SHA-256 (A1)', EN,
  "\t\tif ( 1 !== preg_match( self::HASHED, (string) $line['ref'] ) ) {", "\t\tif ( false ) {", 'env', IH)
e('r1-held-line-staging', 'held_line(): a name the staging site held says why (A1)', EN,
  "\t\tif ( isset( $line['held'] ) ) {\n\t\t\treturn (string) $line['held'];", "\t\tif ( false ) {\n\t\t\treturn (string) $line['held'];", 'env', IH)
e('r1-held-line-rules', 'held_line(): a name by the rules of this site (A1)', EN,
  "\t\treturn 1 === preg_match( self::HASHED, $line['ref'] ) ? null : self::held( $line['ref'], $extra );", "\t\treturn null;", 'env', IH)
HH = "\t\t\t$out[] = null !== $key && ! isset( $line['held'] ) && isset( $codes[ $key ] ) && in_array( $line['env'], self::NAMED, true ) ? self::reduced( $line, $codes[ $key ] ) : $line;"
e('r1-held-here-listed', 'held_here(): a line the list names only (A1)', EN, HH, HH.replace(' && isset( $codes[ $key ] )', ' && array() !== $codes'), 'env', IH)
e('r1-held-here-named', 'held_here(): a name of the site only (A1)', EN, HH, HH.replace(" && in_array( $line['env'], self::NAMED, true )", ''), 'env', IH)
CONST = 'testAConstantTheRulesOfThisSiteDoNotShowIsNotShownFromTheStagingSiteEither'
HC = "\t\t\tif ( null !== $key && 'host' === $line['env'] && 0 === strpos( (string) $line['ref'], 'constant:' ) && ! self::shown( substr( (string) $line['ref'], 9 ), $extra ) ) {"
e('gh1-held-here-constant', 'held_here(): a constant this site does not show, its value not shown from the staging site either (GitHub-Codex, PR #136, P2)', EN, HC, "\t\t\tif ( false ) {", 'env', CONST)
e('gh1-held-here-constant-host', 'held_here(): a constant of the host only (GitHub-Codex, PR #136, P2)', EN, HC, HC.replace("'host' === $line['env'] && ", ''), 'env', CONST)
e('gh1-held-here-constant-shown', 'held_here(): only a constant shown() does not let through (GitHub-Codex, PR #136, P2)', EN, HC, HC.replace('! self::shown(', 'self::shown('), 'env', CONST)
e('gh1-held-here-rules', 'held_here(): by the rules of this site where none are given (GitHub-Codex, PR #136, P2)', EN,
  "\t\t$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();\n\t\t$codes = array();", "\t\t$extra = is_array( $extra ) ? $extra : array();\n\t\t$codes = array();", 'env', CONST)
e('gh1-held-here-constant-it', 'held_here(): a constant only this site redacts, its value of the staging site in no answer and no file of the store (GitHub-Codex, PR #136, P2, two sites)', EN, HC, "\t\t\tif ( false ) {", 'it', 'testAConstantOnlyTheRulesOfThisSiteRedactIsNotShownFromTheStagingSiteInTheAnswerOrTheReport')
HA = 'testAHeldNameIsOneEntryByItsSha256OnBothSidesAndShownNowhere'
e('r1-alike-live', 'compare(): a name the staging site holds, this site by its SHA-256 as well (A1)', EN,
  "\t\t$l     = self::held_alike( $s, $l );", '', 'env', HA)
e('r1-alike-staging', 'compare(): a name this site holds, the staging site by its SHA-256 as well (A1)', EN,
  "\t\t$s     = self::held_alike( $l, $s );", '', 'env', HA)
e('r1-alike-match', 'held_alike(): the line of the same name only (A1)', EN,
  "\t\t\t\tif ( ! isset( $line['held'] ) && isset( $codes[ $hashed ] ) && ! isset( $b[ $env ][ $hashed ] ) ) {", "\t\t\t\tif ( ! isset( $line['held'] ) && ! isset( $b[ $env ][ $hashed ] ) ) {", 'env', HA)
e('r1-entry-held', 'entry(): a held name says why (A1)', EN,
  "\t\tif ( null !== $held ) {\n\t\t\t$entry['held'] = $held;", "\t\tif ( false ) {\n\t\t\t$entry['held'] = $held;", 'env', HA)
e('r1-entry-held-words', 'entry(): the words of a held name (A1)', EN,
  "\t\tif ( null !== $held ) {\n\t\t\t$words .= sprintf(", "\t\tif ( false ) {\n\t\t\t$words .= sprintf(", 'env', HA)

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
  "\t\t\t$result = null === $s ? 'only_live' : 'only_staging';", "\t\t\t$result = 'only_staging';", 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
e('cmp-locale', 'judged(): the version of WordPress and the language the same value, never equal (B4)', EN,
  "\t\t\tcase 'core':\n\t\t\t\t$fields = array( 'value' );\n\t\t\t\t$same   = 'same_value';", "\t\t\tcase 'core':\n\t\t\t\t$fields = array( 'value' );\n\t\t\t\t$same   = 'equal';", 'env', 'testWordPressAndTheLanguageAreShownOnlyAndTheCoreFilesComparedByTheirChecksums')
CODE = "\t\t\t\t$fields = array( 'version', 'active' );\n\t\t\t\t$same   = 'counted';"
e('cmp-host-defined', 'judged(): a constant defined on one side differs', EN,
  "\t\t\t\tif ( $s['defined'] !== $l['defined'] ) {", "\t\t\t\tif ( false ) {", 'env', 'testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost')
e('cmp-host-undefined', 'judged(): not defined on both sides is the same value', EN,
  "\t\t\t\tif ( ! $s['defined'] ) {\n\t\t\t\t\treturn array( 'same_value', array(), null );", "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn array( 'same_value', array(), null );", 'env', 'testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost')
e('r1-cmp-host-undefined-value', 'judged(): not defined on both sides is never equal (B4)', EN,
  "\t\t\t\t\treturn array( 'same_value', array(), null );", "\t\t\t\t\treturn array( 'equal', array(), null );", 'env', 'testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost')
e('r1-cmp-host-value', 'judged(): the same value of the host is never equal (B4)', EN,
  "\t\t\t\t$fields = array( 'value' );\n\t\t\t\t$same   = 'same_value';\n\t\t\t\tbreak;\n\t\t\tcase 'table':", "\t\t\t\t$fields = array( 'value' );\n\t\t\t\t$same   = 'equal';\n\t\t\t\tbreak;\n\t\t\tcase 'table':", 'env', 'testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost')
TABLE = "\t\t\t\t$fields = array( 'rows', 'schema' );\n\t\t\t\t$same   = 'counted';"
e('cmp-table', 'judged(): a table of the same rows and schema is counted, never equal', EN, TABLE, TABLE.replace("'counted'", "'equal'"), 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
e('cmp-table-schema', 'judged(): the schema of a table is compared', EN, TABLE, TABLE.replace("array( 'rows', 'schema' )", "array( 'rows' )"), 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
FILES = "\t\t\t\t$fields = array( 'files', 'bytes' );\n\t\t\t\t$same   = 'counted';"
e('cmp-files', 'judged(): a root of the same count is counted, never equal', EN, FILES, FILES.replace("'counted'", "'equal'"), 'env', 'testARootOfTheSameCountIsCountedNeverEqual')
e('cmp-files-bytes', 'judged(): the size of a root is compared', EN, FILES, FILES.replace("array( 'files', 'bytes' )", "array( 'files' )"), 'env', 'testARootOfTheSameCountIsCountedNeverEqual')
e('cmp-files-complete', 'judged(): a root not counted to its end is not compared', EN,
  "\t\t\t\tif ( ! $s['complete'] || ! $l['complete'] ) {\n\t\t\t\t\t$side = $s['complete'] ? $l : $s;", "\t\t\t\tif ( false ) {\n\t\t\t\t\t$side = $s['complete'] ? $l : $s;", 'env', 'testARootNotCountedToItsEndIsNotComparedWithWhy')
e('cmp-files-side', 'judged(): which side was not counted to its end', EN,
  "\t\t\t\t\t$side = $s['complete'] ? $l : $s;", "\t\t\t\t\t$side = $l;", 'env', 'testARootNotCountedToItsEndIsNotComparedWithWhy')
e('cmp-unread', 'judged(): a value that could not be read is not compared', EN,
  "\t\t$why = $unread( $fields );\n\t\tif ( null !== $why ) {", "\t\t$why = $unread( $fields );\n\t\tif ( false ) {", 'env', 'testAUnitNotReadWholeIsNotComparedWithWhy')
e('cmp-hash', 'judged(): a drop-in by its hash', EN,
  "\t\t\tcase 'dropin':\n\t\t\t\t$fields = array( 'sha256' );", "\t\t\tcase 'dropin':\n\t\t\t\t$fields = array( 'bytes' );", 'env', 'testADropInIsEqualOrDiffersAndIsChangedAtTheHost')
e('entry-locale', 'entry(): the language is shown only', EN,
  "\t\t} elseif ( 'core' === $env && 'locale' === $ref ) {", "\t\t} elseif ( false ) {", 'env', 'testWordPressAndTheLanguageAreShownOnlyAndTheCoreFilesComparedByTheirChecksums')
e('entry-host', 'entry(): the drop-ins and the host are changed at the host', EN,
  "\t\t} elseif ( in_array( $env, array( 'dropin', 'host' ), true ) ) {", "\t\t} elseif ( false ) {", 'env', 'testADropInIsEqualOrDiffersAndIsChangedAtTheHost')
e('r1-entry-host-always', 'entry(): group U is changed at the host, whatever the result (B4)', EN,
  "\t\t} elseif ( in_array( $env, array( 'dropin', 'host' ), true ) ) {", "\t\t} elseif ( 'equal' !== $result && 'same_value' !== $result && in_array( $env, array( 'dropin', 'host' ), true ) ) {", 'env', 'testTheValuesOfTheHostAreShownNeverEqualAndChangedAtTheHost')
e('entry-lock', 'entry(): code, files and tables are locked with their reason and stage', EN,
  "\t\t} elseif ( isset( self::LOCKS[ $key ] ) ) {", "\t\t} elseif ( false ) {", 'env', 'testEachLockHasItsKeyArtStageAndReason')
e('entry-counted', 'entry(): counted says its contents are not compared, and from when', EN,
  "\t\t\tif ( 'counted' === $result ) {\n\t\t\t\t$entry['contents'] = 'not compared';", "\t\t\tif ( false ) {\n\t\t\t\t$entry['contents'] = 'not compared';", 'env', 'testATableWithTheSameRowsAndSchemaIsCountedNeverEqual')
SH = "\t\tunset( $line['env'], $line['ref'], $line['sha256'], $line['schema'], $line['held'] );"
e('show-hash', 'show(): no hash in an entry', EN, SH, "\t\tunset( $line['env'], $line['ref'], $line['held'] );", 'env', 'testNothingCountedIsEverEqualAndNothingEqualIsLocked')
e('r1-show-held', 'show(): no held in a side (A1)', EN, SH, "\t\tunset( $line['env'], $line['ref'], $line['sha256'], $line['schema'] );", 'env', HA)
e('show-present', 'show(): a hash of the host as present', EN,
  "\t\tif ( 'host' === $env && is_string( $line['value'] ) && 1 === preg_match( '/^[0-9a-f]{64}\\z/', $line['value'] ) ) {", "\t\tif ( false ) {", 'env', 'testAValueHeldByItsHashIsNotShown')
e('show-not-shown', 'show(): a value held by its SHA-256 not shown', EN,
  "\t\t\tif ( is_string( $value ) && 0 === strpos( $value, 'sha256:' ) ) {", "\t\t\tif ( false ) {", 'env', 'testAValueHeldByItsHashIsNotShown')
e('counted-blocked', 'counted(): each lock with how many entries', EN,
  "\t\t\t\t++$blocked[ $lock['key'] ]['entries'];", '', 'env', 'testEachLockHasItsKeyArtStageAndReason')

# --------------------------------------------- reading, on a WordPress site
UP = 'testTheUploadsAreCountedOverPartsOutsideTheFileListsOfAttachments'
e('read-seq', 'read(): the next part has the next number', EN,
  "\t\t$state['seq'] = (int) $state['seq'] + 1;", '', 'it', UP)
e('read-last', 'read(): the page says whether it is the last', EN,
  "\t\t\t\t'last'  => $done,", "\t\t\t\t'last'  => true,", 'it', UP)
e('read-clock', 'read(): a part ends when the clock says so', EN,
  "\t\t\tif ( self::now() >= $deadline || count( $lines ) + 1 >= $max ) {", "\t\t\tif ( count( $lines ) + 1 >= $max ) {", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('read-lines', 'read(): a part ends at its lines', EN,
  "\t\t\tif ( self::now() >= $deadline || count( $lines ) + 1 >= $max ) {", "\t\t\tif ( self::now() >= $deadline ) {", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('read-tables-on', 'read(): the tables go on where the part before ended', EN,
  "\t\t\t\t$read  = self::table_lines( $state['after'], $deadline, $max - 1 - count( $lines ) );\n\t\t\t\t$lines = array_merge( $lines, $read['lines'] );\n\t\t\t\tif ( ! $read['done'] ) {\n\t\t\t\t\t$state['after'] = $read['after'];", "\t\t\t\t$read  = self::table_lines( $state['after'], $deadline, $max - 1 - count( $lines ) );\n\t\t\t\t$lines = array_merge( $lines, $read['lines'] );\n\t\t\t\tif ( ! $read['done'] ) {\n\t\t\t\t\t$state['after'] = null;", 'it', 'testTheEnvironmentOfThisSiteIsReadInPartsWithTheSameEntries')
e('tables-after', 'table_lines(): after the table the part before ended with', EN,
  "\t\t\tif ( null !== $after && strcmp( $short, $after ) <= 0 ) {", "\t\t\tif ( null !== $after && strcmp( $short, $after ) < 0 ) {", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-one', 'table_lines(): one table a part at least', EN,
  "\t\t\tif ( array() !== $lines && ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) ) {", "\t\t\tif ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) {", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-room', 'table_lines(): a part ends at its room', EN,
  "\t\t\tif ( array() !== $lines && ( count( $lines ) >= (int) $room - 1 || self::now() >= $deadline ) ) {", "\t\t\tif ( array() !== $lines && ( self::now() >= $deadline ) ) {", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-core', 'tables(): no table of the core', EN,
  "\t\tforeach ( (array) $wpdb->tables( 'all', true ) as $table ) {\n\t\t\t$known[ (string) $table ] = true;", "\t\tforeach ( array() as $table ) {\n\t\t\t$known[ (string) $table ] = true;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-own', 'tables(): no table of the takeover', EN,
  "\t\tforeach ( AB_MCP_Sync_Schema::TABLES as $table ) {\n\t\t\t$known[ $wpdb->prefix . $table ] = true;", "\t\tforeach ( array() as $table ) {\n\t\t\t$known[ $wpdb->prefix . $table ] = true;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
e('tables-prefix', 'tables(): the name without the prefix', EN,
  "\t\t\t$out[] = (string) substr( $table, strlen( $wpdb->prefix ) );", "\t\t\t$out[] = $table;", 'it', 'testTheDropInsAndTheTablesAreThoseOfWordPress')
MAIN = 'testTheEnvironmentOfBothSitesIsReadAndComparedNeverEqualWhereOnlyCounted'
e('rows', 'table_lines(): the rows of a table', EN,
  "\t\t\t$rows    = '' === (string) $wpdb->last_error && is_numeric( $rows ) ? (int) $rows : null;", "\t\t\t$rows    = 0;", 'it', MAIN)
e('uploads-skip', 'skip_of(): the uploads without fonts and the folders of AlphaBridge', EN,
  "\t\t\t\treturn 'fonts' === $name || in_array( $name, self::OWN_UPLOADS, true ) || 0 === strpos( $name, self::OWN_UPLOADS_PREFIX )\n", "\t\t\t\treturn 'fonts' === $name\n", 'it', UP)
e('uploads-attached', 'root(): the uploads against the file lists of the attachments', EN,
  "\t\tif ( 'uploads' === $root ) {\n\t\t\t$known = self::attached( $deadline );", "\t\tif ( false ) {\n\t\t\t$known = self::attached( $deadline );", 'it', UP)
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
  "\t\t$environment = array() === $ids ? AB_MCP_Sync_Env::without() : AB_MCP_Sync_Env::compare( $env_side['lines'], $env, $pairs );", "\t\t$environment = AB_MCP_Sync_Env::compare( $env_side['lines'], $env, $pairs );", 'it', 'testWithoutEnvironmentTheReportSaysItIsNotComparedWithTheWay')
e('numbered', 'finish(): the environment numbered on after the objects', RE,
  "\t\t\t$report['entries'][] = array( 'n' => ++$n ) + $entry;", "\t\t\t$report['entries'][] = array( 'n' => $n ) + $entry;", 'it', MAIN)
# Codex, round 1 of branch 7: on two sites.
SECRET = 'testANameWithACredentialIsInNoAnswerPackageCursorOrFileOfTheStore'
RULES = 'testANameOnlyTheRulesOfThisSiteHoldIsHeldByTheImport'
GONE = 'testAPlaceKeptByItsSha256ThatIsGoneLeavesItsRootNotCountedToItsEnd'
e('r1-named-held', 'named(): a held name by its SHA-256 and why (A1, two sites)', EN,
  "\t\tif ( null !== $held ) {\n\t\t\treturn self::reduced( self::line( $env, self::hashed( $name ), $fields ), $held );", "\t\tif ( false ) {\n\t\t\treturn self::reduced( self::line( $env, self::hashed( $name ), $fields ), $held );", 'it', SECRET)
e('r1-tables-place', 'table_lines(): the cursor at a table by its place (A1, two sites)', EN,
  "\t\t\t\t\t'after' => self::place( $last, false ),", "\t\t\t\t\t'after' => array( $last ),", 'it', SECRET)
e('r1-root-place', 'root(): the state at a path by its place (A1, C5, two sites)', EN,
  "\t\t\t$state['after'] = self::place( $state['after'] );", '', 'it', SECRET)
e('r1-root-moved', 'root(): a place held by its SHA-256 that is gone: not counted to its end (A1)', EN,
  "\t\tif ( null !== $state['after'] && null === $after ) {", "\t\tif ( false ) {", 'it', GONE)
e('r1-tables-gone', 'table_lines(): a table held by its SHA-256 that is gone: the list not whole (A1)', EN,
  "\t\tif ( null === $names || ( null !== $place && null === $after ) ) {", "\t\tif ( null === $names ) {", 'it', GONE)
e('r1-export-held', 'make_env(): the lines held counted (A1, two sites)', EX,
  "\t\t\t\t'held'  => count( array_filter( $read['lines'], static fn( $line ) => isset( $line['held'] ) ) ),", "\t\t\t\t'held'  => 0,", 'it', SECRET)
e('r1-import-held', 'unpacked(): a name of the environment held by the rules of this site listed (A1, two sites)', IM,
  "\t\t\t\t\tif ( null !== $code ) {\n\t\t\t\t\t\t$out[] = array( $object['env'],", "\t\t\t\t\tif ( false ) {\n\t\t\t\t\t\t$out[] = array( $object['env'],", 'it', RULES)
e('r1-report-held', 'environment(): the lines the import held by the SHA-256 of their name (A1, two sites)', RE,
  "\t\t\tforeach ( AB_MCP_Sync_Env::held_here( array_slice( $part['lines'], 1 ), $part['held'] ) as $line ) {", "\t\t\tforeach ( array_slice( $part['lines'], 1 ) as $line ) {", 'it', RULES)
IT_THERE = 'testANameOnlyTheRulesOfTheStagingSiteHoldIsHeldInThePartsOfThisSiteAsWell'
e('r2-report-head-held', 'call(): the head keeps the names the staging site holds, before this site reads its own (A1, two sites)', RE,
  "\t\t\t'held'        => AB_MCP_Sync_Env::held_names( $env['lines'] ),", "\t\t\t'held'        => array(),", 'it', IT_THERE)
e('r2-report-part-held', 'live_environment(): a part of this site stored with the rules of both sites (A1, two sites)', RE,
  "\t\t\t\t'lines'  => AB_MCP_Sync_Env::held_there( $read['lines'], (array) ( $found['head']['held'] ?? array() ) ),", "\t\t\t\t'lines'  => $read['lines'],", 'it', IT_THERE)
IT_COLON = 'testANameWithACredentialAfterAColonOrBelowAFolderIsInNoAnswerCursorPackageOrFileOfTheStore'
e('r2-held-bare-it', 'held(): a name after a colon held in package, cursor, report and store (A1, two sites)', EN,
  "\t\t$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra, true );", "\t\t$net = AB_MCP_Sync_Net::over_name( (string) $name, $extra );", 'it', IT_COLON)
IT_KEYED = 'testANameWithTheNameOfACredentialBeforeAnEqualsSignOrAColonIsInNoAnswerCursorPackageOrFileOfTheStore'
e('r3-net-keyed-it', 'over_name(): a name before = or : held in package, cursor, report and store (A1, two sites, Codex round 3)', NET,
  "\t\t\tif ( $site && self::keyed( $layer[1], $extra ) ) {", "\t\t\tif ( false ) {", 'it', IT_KEYED)
e('r2-root-place-it', 'root(): a state below a folder by SHA-256 only, over parts on both sites (A1, two sites)', EN,
  "\t\t\t$at    = null === $at ? $part : $at . '/' . $part;", "\t\t\t$at    = $part;", 'it', IT_COLON)
e('r1-import-purpose', 'held(): a later call names the purpose it was begun for (A3)', IM,
  "\t\tif ( array_key_exists( 'purpose', $a ) && $expect['purpose'] !== $a['purpose'] ) {", "\t\tif ( false ) {", 'it', 'testALaterCallOfAnImportNamesNoOtherPurpose')
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
 * of a package, the comparison (counted never equal, equal only by a hash,
 * a value of the host the same value, not compared with why, each lock with its stage, the host changed
 * there, no hash shown; a name a credential holds by its SHA-256 only, also
 * the name of one before = : ; or a quote, the place of a part by SHA-256 only, a
 * name the staging site holds held here before it is stored), reading in parts on a
 * WordPress site (the pages,
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
 * AB_IT_ROOT set). Not listed: the lock of a root by its own name in entry()
 * (since Z8b branch 2 uploads is the only root counted, so the mutation is
 * the same code), is_file() before hash_file() in file_hash()
 * (without it a FIFO in the place of a drop-in would block the call, so a
 * test of it ends in the time limit of the runner, not red), the guards
 * against a stored part of the
 * environment of the report that is not as this code wrote it (named by its
 * own SHA-256 in the store; no input reaches them), the expiry of a report
 * by the packages of the environment (made within seconds of the packages of
 * the inventory, so no test tells the two apart), and the readers whose
 * failure WordPress gives no way to cause on a test site (SHOW TABLES,
 * COUNT, SHOW CREATE TABLE failing; the rows of WPLANG, stylesheet,
 * template and active_plugins unreadable), and seven guards whose loss no
 * test can see (the kind and the SHA-256 in held_line(), isset( held ) in
 * held_here(), the place in held_alike(), the kind of a held line in
 * shaped(), the kind and the SHA-256 in held_names()); GRENZEN-BERICHT.md,
 * Zweig 7.
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
$net = 'includes/sync/class-sync-net.php';

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
