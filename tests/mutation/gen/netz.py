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
    'itl': ('it', 'SyncLocksTest'),
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
N_REFLEAF = 'testAReferenceInALayerTakenOutOfALeafLocks'
N_STRINGS = 'testAValueThatDoesNotSerializeBackIsReadByTheStringsOfItsFormat'
N_SCALARS = 'testAScalarOfTheFormatOnlyInItsStrictFormElseTheTextIsReadWhole'
IT_HIDDEN = 'testTheNetLocksAHiddenReferenceInEveryUnit'

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
  "\t\t\t\t\tself::count_credentials( $acc, (string) $kind, (string) $at, $place, $layers, $named, $extra, $unreadable );\n",
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

# The cause of A2 for the references: a pair in a layer taken out of a text.
REF_GUARD = "\t\t\t\t\tif ( ! $locked ) {\n\t\t\t\t\t\tforeach ( $layers as $layer ) {"
e('leaf-references', 'check(): a pair in a layer taken out of a text of the value locks (the cause of A2)', NT,
  REF_GUARD, "\t\t\t\t\tif ( false ) {\n\t\t\t\t\t\tforeach ( $layers as $layer ) {", 'net', N_REFLEAF)
e('leaf-references-it', 'check(): a pair in a layer taken out of a leaf locks on WordPress (the cause of A2)', NT,
  REF_GUARD, "\t\t\t\t\tif ( false ) {\n\t\t\t\t\t\tforeach ( $layers as $layer ) {", 'itl', IT_HIDDEN)
e('leaf-references-locked', 'check(): nothing added to the references where the readers locked the unit', NT,
  REF_GUARD, "\t\t\t\t\tif ( true ) {\n\t\t\t\t\t\tforeach ( $layers as $layer ) {", 'net', N_REFLEAF)
e('leaf-references-extracted', 'check(): only a layer taken out of a text counts every pair (the text and its decodings over the raw text)', NT,
  "\t\t\t\t\t\t\tif ( $layer[2] && array() !== self::references( $layer[1], $ctx ) ) {",
  "\t\t\t\t\t\t\tif ( array() !== self::references( $layer[1], $ctx ) ) {", 'net', N_REFLEAF)
e('leaf-references-once', 'check(): one lock of the references per unit', NT,
  "\t\t\t\t\t\t\t\t$locked = true;\n\t\t\t\t\t\t\t\tbreak;", "\t\t\t\t\t\t\t\tbreak;", 'net', N_REFLEAF)

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
# Not listed: the trim() of the text before the round trip (a text with white
# space around it, read by the strings of its format, judges as its value);
# GRENZEN-STRUKTUR.md.
# The strings of the format of a text that does not serialize back
# (the table of A1 and B: the float of 17 digits judged as its prose).
e('texts-strings', 'texts(): every string of the format of a text that does not serialize back, each on its own', NT,
  "\t\t\t\t\t\tself::texts( $string, ( '' === $where ? '' : $where . '.' ) . 'string.' . $i, $layer + 1, $depth, $acc, $kind, $at, $each );\n", "", 'net', N_CRED)
e('texts-strings-not-whole', 'texts(): the strings of the format, not the whole text with its braces', NT,
  "\t\t\t\t$strings = self::serialized_strings( trim( $value ) );", "\t\t\t\t$strings = null;", 'net', N_STRINGS)
e('strings-string', 'serialized_strings(): each string by the length it states', NT,
  "\t\t\t\t$out[] = (string) substr( $text, $i, $len );\n", "", 'net', N_CRED)
e('strings-string-end', 'serialized_strings(): a string ends with its quote and ";"', NT,
  "\t\t\t\t$i    += $len + 2;", "\t\t\t\t$i    += $len;", 'net', N_STRINGS)
SCALAR_STRICT = "\t\t\t\tif ( ! preg_match( self::SCALARS[ $c ], $text, $m, 0, $i ) ) {\n\t\t\t\t\treturn null;\n\t\t\t\t}\n\t\t\t\t$i += strlen( $m[0] );"
e('strings-scalars', 'serialized_strings(): a number or a flag read in its strict form', NT,
  SCALAR_STRICT, "\t\t\t\treturn null;", 'net', N_SCALARS)
# Codex round 2 of branch 11b, A: a scalar only in its strict form, else the text whole.
e('texts-strings-whole', 'texts(): where the format does not read to its end (a scalar not in its strict form), the text as a whole (Codex round 2, A)', NT,
  "\t\t\t\tif ( null === $strings ) {\n\t\t\t\t\t$each( $where, $value );", "\t\t\t\tif ( null === $strings ) {\n\t\t\t\t\t// not read", 'net', N_SCALARS)
e('strings-scalars-lax', 'serialized_strings(): no scalar read to the next ";" whatever it holds (Codex round 2, A)', NT,
  SCALAR_STRICT, "\t\t\t\t$i = strpos( $text, ';', $i ) + 1;", 'net', N_SCALARS)
e('scalar-i', 'SCALARS: an integer only of digits with its sign (Codex round 2, A)', NT,
  "\t\t'i' => '/\\Gi:[+-]?\\d+;/',", "\t\t'i' => '/\\Gi:[^;]*;/',", 'net', N_SCALARS)
e('scalar-d', 'SCALARS: a float only in the form PHP writes it (Codex round 2, A)', NT,
  "\t\t'd' => '/\\Gd:(?:[+-]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)(?:[eE][+-]?\\d+)?|[+-]?INF|NAN);/',", "\t\t'd' => '/\\Gd:[^;]*;/',", 'net', N_SCALARS)
e('scalar-b', 'SCALARS: a boolean only 0 or 1 (Codex round 2, A)', NT,
  "\t\t'b' => '/\\Gb:[01];/',", "\t\t'b' => '/\\Gb:[^;]*;/',", 'net', N_SCALARS)
e('strings-null', 'serialized_strings(): N; read', NT,
  "\t\t\t} elseif ( 'N' === $c && 'N;' === substr( $text, $i, 2 ) ) {\n\t\t\t\t$i += 2;",
  "\t\t\t} elseif ( 'N' === $c && 'N;' === substr( $text, $i, 2 ) ) {\n\t\t\t\treturn null;", 'net', N_STRINGS)
e('strings-close', 'serialized_strings(): the end of an array read', NT,
  "\t\t\t} elseif ( '}' === $c ) {\n\t\t\t\t++$i;", "\t\t\t} elseif ( '}' === $c ) {\n\t\t\t\treturn null;", 'net', N_STRINGS)
e('texts-object', 'texts(): an object in a structure locks', NT,
  "\t\t\tAB_MCP_Sync_Canon::lock( $acc, $kind, 'php_object', $here );\n", "", 'net', N_STRUCT)
# ------------------------------------- the forms of the steering (09.11.2026)
N_TOKENS = 'testTheTokensOfAServiceAndAJsonWebTokenCountByTheirFormInAnyContext'
N_PLACE = 'testAPlaceholderIsOneOnlyInItsExactFormAsTheWholeValue'
e('tokens', 'credentials(): a token of a service counts by its form, in any context', NT,
  "\t\t\tif ( 1 === preg_match( $pattern, $text ) ) {\n\t\t\t\t$found[] = $name;", "\t\t\tif ( 1 === preg_match( $pattern, $text ) ) {\n\t\t\t\t// not counted", 'net', N_TOKENS)
e('token-ghp-length', 'TOKENS: ghp_ with 36 letters and digits', NT,
  "ghp_[A-Za-z0-9]{36}/'", "ghp_[A-Za-z0-9]{35}/'", 'net', N_TOKENS)
e('token-ghp-before', 'TOKENS: ghp_ not glued to a letter or digit before it', NT,
  "'/(?<![A-Za-z0-9])ghp_", "'/ghp_", 'net', N_TOKENS)
e('token-pat-length', 'TOKENS: github_pat_ with 36 or more', NT,
  "github_pat_[A-Za-z0-9_]{36}/'", "github_pat_[A-Za-z0-9_]{35}/'", 'net', N_TOKENS)
e('token-stripe-length', 'TOKENS: sk_live_ and rk_live_ with 24', NT,
  "k_live_[A-Za-z0-9]{24}/'", "k_live_[A-Za-z0-9]{23}/'", 'net', N_TOKENS)
e('token-stripe-prefix', 'TOKENS: only the secret and the restricted key of Stripe (not pk_live_)', NT,
  "'/(?<![A-Za-z0-9])[rs]k_live_", "'/(?<![A-Za-z0-9])[a-z]k_live_", 'net', N_TOKENS)
e('token-stripe-before', 'TOKENS: sk_live_ not glued to a letter or digit before it', NT,
  "'/(?<![A-Za-z0-9])[rs]k_live_", "'/[rs]k_live_", 'net', N_TOKENS)
e('token-aws-length', 'TOKENS: AKIA with 16 capitals and digits', NT,
  "AKIA[0-9A-Z]{16}(?![0-9A-Za-z])", "AKIA[0-9A-Z]{15}(?![0-9A-Za-z])", 'net', N_TOKENS)
e('token-aws-after', 'TOKENS: AKIA, no letter or digit after the 16', NT,
  "AKIA[0-9A-Z]{16}(?![0-9A-Za-z])", "AKIA[0-9A-Z]{16}", 'net', N_TOKENS)
e('token-aws-before', 'TOKENS: AKIA not glued to a letter or digit before it', NT,
  "'/(?<![A-Za-z0-9])AKIA", "'/AKIA", 'net', N_TOKENS)
e('token-slack-length', 'TOKENS: xoxb-, xoxp- with their numbers and 8 or more after them', NT,
  "[0-9]+-[0-9]+-[A-Za-z0-9-]{8}/'", "[0-9]+-[0-9]+-[A-Za-z0-9-]{7}/'", 'net', N_TOKENS)
e('token-slack-prefix', 'TOKENS: only xoxb- and xoxp-', NT,
  "'/(?<![A-Za-z0-9])xox[bp]-", "'/(?<![A-Za-z0-9])xox[a-z]-", 'net', N_TOKENS)
e('token-slack-before', 'TOKENS: xoxb- not glued to a letter or digit before it', NT,
  "'/(?<![A-Za-z0-9])xox[bp]-", "'/xox[bp]-", 'net', N_TOKENS)
e('token-mailchimp-length', 'TOKENS: Mailchimp with 32 hex', NT,
  "'/(?<![0-9A-Za-z])[0-9a-f]{32}-us", "'/(?<![0-9A-Za-z])[0-9a-f]{31}-us", 'net', N_TOKENS)
e('token-mailchimp-before', 'TOKENS: Mailchimp, no letter or digit before the 32 hex', NT,
  "'/(?<![0-9A-Za-z])[0-9a-f]{32}-us", "'/[0-9a-f]{32}-us", 'net', N_TOKENS)
e('token-mailchimp-centre', 'TOKENS: Mailchimp with the number of its data centre', NT,
  "-us[0-9]{1,2}(?![0-9A-Za-z])/'", "-us[0-9]{0,2}(?![0-9A-Za-z])/'", 'net', N_TOKENS)
e('token-mailchimp-after', 'TOKENS: Mailchimp, no letter or digit after its number', NT,
  "-us[0-9]{1,2}(?![0-9A-Za-z])/'", "-us[0-9]{1,2}/'", 'net', N_TOKENS)
e('jwt', 'credentials(): a JSON Web Token counts by its form, in any context', NT,
  "\t\t\t\tif ( self::jwt( $hit[1], $hit[2], $hit[3] ) ) {", "\t\t\t\tif ( false ) {", 'net', N_TOKENS)
e('jwt-before', 'JWT: not glued to a character of base64url before it', NT,
  "const JWT = '/(?<![A-Za-z0-9_\\-])(eyJ", "const JWT = '/(eyJ", 'net', N_TOKENS)
e('jwt-signature', 'JWT: three parts, the signature not empty', NT,
  "\\.([A-Za-z0-9_\\-]+)\\.([A-Za-z0-9_\\-]+)/';", "\\.([A-Za-z0-9_\\-]+)\\.([A-Za-z0-9_\\-]*)/';", 'net', N_TOKENS)
e('jwt-lengths', 'jwt(): no part of a length base64url never has', NT,
  "\t\t\tif ( 1 === strlen( (string) $part ) % 4 ) {\n\t\t\t\treturn false;", "\t\t\tif ( 1 === strlen( (string) $part ) % 4 ) {\n\t\t\t\t// any length", 'net', N_TOKENS)
e('jwt-alg', 'jwt(): the header an object of JSON with alg', NT,
  "return is_array( $head ) && array_key_exists( 'alg', $head );", "return is_array( $head );", 'net', N_TOKENS)
e('jwt-object', 'jwt(): the header an object, not a string of JSON', NT,
  "return is_array( $head ) && array_key_exists( 'alg', $head );", "return null !== $head;", 'net', N_TOKENS)
e('jwt-base64url', 'jwt(): the header read as base64url (- and _)', NT,
  "base64_decode( strtr( (string) $header, '-_', '+/' ), true );", "base64_decode( (string) $header, true );", 'net', N_TOKENS)
e('placeholder-start', 'PLACEHOLDER: from the start of the value (never a part of a word, Codex round 3, A1)', NT,
  "const PLACEHOLDER = '/^(?:", "const PLACEHOLDER = '/(?:", 'net', N_PLACE)
e('placeholder-end', 'PLACEHOLDER: to the end of the value (never a part of a word, Codex round 3, A1)', NT,
  "|\\[REDACTED\\])\\z/u';", "|\\[REDACTED\\])/u';", 'net', N_PLACE)
e('placeholder-your', 'PLACEHOLDER: YOUR_ and capitals only', NT,
  "|YOUR_[A-Z0-9_]*[A-Z0-9]|", "|YOUR_[A-Za-z0-9_]*|", 'net', N_PLACE)
e('placeholder-x', 'PLACEHOLDER: x three times or more', NT,
  "|[xX]{3,}(?:[ \\-][xX]{3,})*|", "|[xX]{2,}(?:[ \\-][xX]{2,})*|", 'net', N_PLACE)
e('placeholder-x-groups', 'PLACEHOLDER: x in groups (xxxx xxxx)', NT,
  "|[xX]{3,}(?:[ \\-][xX]{3,})*|", "|[xX]{3,}|", 'net', N_PLACE)
e('placeholder-stars', 'PLACEHOLDER: * three times or more', NT,
  "|\\*{3,}|", "|\\*{2,}|", 'net', N_PLACE)
e('placeholder-dots', 'PLACEHOLDER: . three times or more', NT,
  "|\\.{3,}|", "|\\.{2,}|", 'net', N_PLACE)
e('placeholder-brackets', 'PLACEHOLDER: <…> with something in it', NT,
  "'/^(?:<[^<>]+>|", "'/^(?:<[^<>]*>|", 'net', N_PLACE)
e('placeholder-var', 'PLACEHOLDER: $VAR begins with a letter or _', NT,
  "|\\$\\{?[A-Za-z_][A-Za-z0-9_]*\\}?|", "|\\$\\{?[A-Za-z0-9_]*\\}?|", 'net', N_PLACE)
e('placeholder-call', 'placeholder(): exactly PLACEHOLDER', NT,
  "return 1 === preg_match( self::PLACEHOLDER, (string) $value );", "return 0 !== preg_match( self::PLACEHOLDER, (string) $value );", 'net', N_PLACE)

N_AUTH = 'testTheHeaderAuthorizationCountsAtTheStartOfALineOrAField'
AUTH = "const AUTHORIZATION = '/(?:^|(?<=[\\r\\n;\"\\'`:]))[ \\t]*(?:proxy-)?authorization[ \\t]*:[ \\t]*[\"\\'`]?[ \\t]*(bearer|basic)[ \\t]+([^\\s\"\\'`;,]*)/i';"
e('auth', 'credentials(): the header Authorization with Bearer or Basic counts (Codex round 1, A1)', NT,
  "\t\t\t\tif ( 'basic' === strtolower( $hit[1] ) ? self::basic( $hit[2] ) : self::bearer( $hit[2] ) ) {", "\t\t\t\tif ( false ) {", 'net', N_AUTH)
e('auth-scheme', 'credentials(): Basic by its pair, Bearer by its token', NT,
  "\t\t\t\tif ( 'basic' === strtolower( $hit[1] ) ? self::basic( $hit[2] ) : self::bearer( $hit[2] ) ) {", "\t\t\t\tif ( self::bearer( $hit[2] ) ) {", 'net', N_AUTH)
e('auth-start', 'AUTHORIZATION: at the start of the text', NT,
  AUTH, AUTH.replace("(?:^|(?<=", "(?:(?<="), 'net', N_AUTH)
e('auth-anywhere', 'AUTHORIZATION: only at the start of a line or a field (not in a sentence, not after a tag)', NT,
  AUTH, AUTH.replace("(?:^|(?<=[\\r\\n;\"\\'`:]))", ""), 'net', N_AUTH)
e('auth-line', 'AUTHORIZATION: after a line break', NT,
  AUTH, AUTH.replace("(?<=[\\r\\n;", "(?<=[;"), 'net', N_AUTH)
e('auth-semicolon', 'AUTHORIZATION: after a «;» of a line of headers (Codex round 1, A1)', NT,
  AUTH, AUTH.replace("(?<=[\\r\\n;", "(?<=[\\r\\n"), 'net', N_AUTH)
e('auth-quote', 'AUTHORIZATION: after a quote', NT,
  AUTH, AUTH.replace(";\"\\'`:]))", ";:]))"), 'net', N_AUTH)
e('auth-colon', 'AUTHORIZATION: after the «:» of an array of headers', NT,
  AUTH, AUTH.replace(";\"\\'`:]))", ";\"\\'`]))"), 'net', N_AUTH)
e('auth-proxy', 'AUTHORIZATION: Proxy-Authorization as well', NT,
  AUTH, AUTH.replace("(?:proxy-)?authorization", "authorization"), 'net', N_AUTH)
e('auth-quoted-scheme', 'AUTHORIZATION: the scheme in quotes after «:»', NT,
  AUTH, AUTH.replace("[ \\t]*:[ \\t]*[\"\\'`]?[ \\t]*(bearer", "[ \\t]*:[ \\t]*(bearer"), 'net', N_AUTH)
e('auth-field-end', 'AUTHORIZATION: the value to a «;» or «,», a field after it one of its own (Codex round 3, A3)', NT,
  AUTH, AUTH.replace("([^\\s\"\\'`;,]*)", "([^\\s\"\\'`]*)"), 'net', N_AUTH)
e('auth-case', 'AUTHORIZATION: in any case, as HTTP reads a header', NT,
  AUTH, AUTH.replace(")/i';", ")/';"), 'net', N_AUTH)
e('bearer-start', 'bearer(): the characters of a token at the start of the value', NT,
  "const BEARER_TOKEN = '#^[A-Za-z0-9\\-._~+/]+=*#';", "const BEARER_TOKEN = '#[A-Za-z0-9\\-._~+/]+=*#';", 'net', N_AUTH)
e('bearer-dot', 'bearer(): a «.» at its end ends a sentence', NT,
  "\t\t$token = rtrim( $m[0], '.' );", "\t\t$token = $m[0];", 'net', N_AUTH)
e('bearer-empty', 'bearer(): not empty', NT,
  "\t\treturn '' !== $token && ! self::placeholder( $token );", "\t\treturn ! self::placeholder( $token );", 'net', N_AUTH)
e('bearer-placeholder', 'bearer(): no placeholder', NT,
  "\t\treturn '' !== $token && ! self::placeholder( $token );", "\t\treturn '' !== $token;", 'net', N_AUTH)
e('basic-control', 'basic(): a text without a sign of control', NT,
  "|| ! self::is_text( $pair ) || 1 === preg_match( '/[\\x00-\\x1F\\x7F]/', $pair ) ) {", "|| ! self::is_text( $pair ) ) {", 'net', N_AUTH)
e('basic-text', 'basic(): a text in UTF-8', NT,
  "|| ! self::is_text( $pair ) || 1 === preg_match( '/[\\x00-\\x1F\\x7F]/', $pair ) ) {", "|| 1 === preg_match( '/[\\x00-\\x1F\\x7F]/', $pair ) ) {", 'net', N_AUTH)
e('basic-colon', 'basic(): a pair with «:»', NT,
  "\t\tif ( false === $at || 1 === strlen( $pair ) ) {", "\t\tif ( 1 === strlen( $pair ) ) {", 'net', N_AUTH)
e('basic-both-empty', 'basic(): user and password not both empty', NT,
  "\t\tif ( false === $at || 1 === strlen( $pair ) ) {", "\t\tif ( false === $at ) {", 'net', N_AUTH)
e('basic-user', 'basic(): with an empty password, the user (a key as the user) no placeholder', NT,
  "\t\treturn ! self::placeholder( '' === $password ? (string) substr( $pair, 0, $at ) : $password );", "\t\treturn ! self::placeholder( $password );", 'net', N_AUTH)
e('basic-placeholder', 'basic(): the password no placeholder', NT,
  "\t\treturn ! self::placeholder( '' === $password ? (string) substr( $pair, 0, $at ) : $password );", "\t\treturn true;", 'net', N_AUTH)

N_PEM = 'testAPrivateKeyInPemCountsByItsFrameAndABodyOfBase64'
PEM = "const PEM = '/-----BEGIN ((?:[A-Z0-9]+ )*)PRIVATE KEY((?: BLOCK)?)-----(.*?)-----END \\\\1PRIVATE KEY\\\\2-----/s';"
e('pem', 'credentials(): a private key in PEM counts by its frame and its body', NT,
  "\t\t\t\tif ( self::pem( $body ) ) {", "\t\t\t\tif ( false ) {", 'net', N_PEM)
e('pem-label', 'PEM: the END line of the same label', NT,
  PEM, PEM.replace("-----END \\\\1PRIVATE", "-----END (?:[A-Z0-9]+ )*PRIVATE"), 'net', N_PEM)
e('pem-block', 'PEM: BLOCK in both lines or in neither', NT,
  PEM, PEM.replace("PRIVATE KEY\\\\2-----/s", "PRIVATE KEY(?: BLOCK)?-----/s"), 'net', N_PEM)
e('pem-lazy', 'PEM: each key to its own END line', NT,
  PEM, PEM.replace("-----(.*?)-----END", "-----(.*)-----END"), 'net', N_PEM)
e('pem-lines', 'PEM: the body over its lines', NT,
  PEM, PEM.replace("-----/s';", "-----/';"), 'net', N_PEM)
e('pem-split', 'pem(): its lines at every line break and a <br> of HTML', NT,
  "preg_split( '/\\r\\n|\\r|\\n|<br\\s*\\/?>/i', (string) $body )", "preg_split( '/\\r\\n|\\n/i', (string) $body )", 'net', N_PEM)
e('pem-split-br', 'pem(): a <br> of HTML a line break', NT,
  "preg_split( '/\\r\\n|\\r|\\n|<br\\s*\\/?>/i', (string) $body )", "preg_split( '/\\r\\n|\\r|\\n/i', (string) $body )", 'net', N_PEM)
e('pem-trim', 'pem(): each line without the white space around it', NT,
  "\t\t\t$line = trim( (string) $line );", "\t\t\t$line = (string) $line;", 'net', N_PEM)
e('pem-headers', 'pem(): the headers of an encrypted key are no body', NT,
  "if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9-]*:\\s|^=[A-Za-z0-9+\\/]{4}\\z/', $line ) ) {", "if ( 1 !== preg_match( '/^=[A-Za-z0-9+\\/]{4}\\z/', $line ) ) {", 'net', N_PEM)
e('pem-checksum', 'pem(): the checksum of PGP is no body', NT,
  "if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9-]*:\\s|^=[A-Za-z0-9+\\/]{4}\\z/', $line ) ) {", "if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9-]*:\\s/', $line ) ) {", 'net', N_PEM)
e('pem-base64', 'pem(): the body base64 only, not empty', NT,
  "return 1 === preg_match( '/^[A-Za-z0-9+\\/]+={0,2}\\z/', $base64 ) && 0 === strlen( $base64 ) % 4;", "return 1 === preg_match( '/^[A-Za-z0-9+\\/]*={0,2}\\z/', $base64 ) && 0 === strlen( $base64 ) % 4;", 'net', N_PEM)
e('pem-alphabet', 'pem(): a sentence is no body', NT,
  "return 1 === preg_match( '/^[A-Za-z0-9+\\/]+={0,2}\\z/', $base64 ) && 0 === strlen( $base64 ) % 4;", "return 0 === strlen( $base64 ) % 4;", 'net', N_PEM)
e('pem-length', 'pem(): a length of a multiple of four', NT,
  "return 1 === preg_match( '/^[A-Za-z0-9+\\/]+={0,2}\\z/', $base64 ) && 0 === strlen( $base64 ) % 4;", "return 1 === preg_match( '/^[A-Za-z0-9+\\/]+={0,2}\\z/', $base64 );", 'net', N_PEM)

N_COOKIE = 'testTheValueOfALoginCookieCountsByTheFormWordPressGivesIt'
COOKIE = "const COOKIE = '/(?:\\\\||%7[cC])\\\\d{10}(?:\\\\||%7[cC])[A-Za-z0-9]{43}(?:\\\\||%7[cC])[0-9a-f]{64}/';"
e('cookie', 'credentials(): the value of a login cookie counts by its form, in any context', NT,
  "\t\tif ( 1 === preg_match( self::COOKIE, $text ) ) {\n\t\t\t$found[] = 'auth_token';", "\t\tif ( 1 === preg_match( self::COOKIE, $text ) ) {\n\t\t\t// not counted", 'net', N_COOKIE)
e('cookie-encoded', 'COOKIE: a «|» URL-encoded as well (%7C)', NT,
  COOKIE, COOKIE.replace("'/(?:\\\\||%7[cC])\\\\d{10}", "'/\\\\|\\\\d{10}"), 'net', N_COOKIE)
e('cookie-raw', 'COOKIE: a «|» raw as well', NT,
  COOKIE, COOKIE.replace("'/(?:\\\\||%7[cC])\\\\d{10}", "'/%7[cC]\\\\d{10}"), 'net', N_COOKIE)
e('cookie-lower', 'COOKIE: %7c in lower case as well', NT,
  COOKIE, COOKIE.replace("[A-Za-z0-9]{43}(?:\\\\||%7[cC])", "[A-Za-z0-9]{43}(?:\\\\||%7C)"), 'net', N_COOKIE)
e('cookie-expiration', 'COOKIE: the expiration of ten digits', NT,
  COOKIE, COOKIE.replace("\\\\d{10}", "\\\\d{9,10}"), 'net', N_COOKIE)
e('cookie-separator', 'COOKIE: the expiration and the token apart by a «|»', NT,
  COOKIE, COOKIE.replace("\\\\d{10}(?:\\\\||%7[cC])", "\\\\d{10}(?:\\\\||%7[cC]|-)"), 'net', N_COOKIE)
e('cookie-token', 'COOKIE: the token of 43 letters and digits', NT,
  COOKIE, COOKIE.replace("[A-Za-z0-9]{43}", "[A-Za-z0-9]{42,43}"), 'net', N_COOKIE)
e('cookie-hmac', 'COOKIE: the HMAC of 64 hex', NT,
  COOKIE, COOKIE.replace("[0-9a-f]{64}", "[0-9a-f]{63}"), 'net', N_COOKIE)
e('cookie-hex', 'COOKIE: the HMAC in the hex WordPress writes (lower case)', NT,
  COOKIE, COOKIE.replace("[0-9a-f]{64}", "[0-9a-fA-F]{64}"), 'net', N_COOKIE)
e('cookie-sep-hmac', 'COOKIE: the token and the HMAC apart by a «|»', NT,
  COOKIE, COOKIE.replace("[A-Za-z0-9]{43}(?:\\\\||%7[cC])", "[A-Za-z0-9]{43}(?:\\\\||%7[cCdD])"), 'net', N_COOKIE)

N_SERKEY = 'testAKeyOfTheSerializedFormatWithAValueCountsInATextThatIsNotUnpacked'
SERKEY = "\t\t\t'/s:\\d+:\"(' . self::NAME . ')\";(?:s:[1-9]\\d*:\"|[id]:)/',"
e('serialized-key', 'credentials(): a key of the serialized format with a value counts', NT,
  SERKEY, "\t\t\t'/(?!)/',", 'net', N_SERKEY)
e('serialized-key-format', 'credentials(): the key a string of the format (s:<n>:"…";)', NT,
  SERKEY, SERKEY.replace("'/s:\\d+:\"(", "'/\"("), 'net', N_SERKEY)
e('serialized-key-empty', 'credentials(): its value not an empty string', NT,
  SERKEY, SERKEY.replace("s:[1-9]\\d*:", "s:\\d+:"), 'net', N_SERKEY)
e('serialized-key-number', 'credentials(): its value a number as well', NT,
  SERKEY, SERKEY.replace("|[id]:)", ")"), 'net', N_SERKEY)

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
