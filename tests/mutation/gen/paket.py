#!/usr/bin/env python3
"""Writes tests/mutation/lists/paket.php: one entry per clause of the trust
of the takeover (Z8 branch 5): the key of a staging source, the anchor, the
fetcher, the signed manifest and the forms of wp-admin. Every search is
checked to occur exactly once in its file before the list is written.

    python3 tests/mutation/gen/paket.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os
import re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
K = 'includes/sync/class-sync-key.php'
A = 'includes/sync/class-sync-anchor.php'
M = 'includes/sync/class-sync-manifest.php'
F = 'includes/sync/class-sync-fetcher.php'
S = 'includes/class-staging.php'
AD = 'includes/class-admin.php'
RC = 'includes/class-rest-controller.php'
O = 'includes/class-outbound.php'
EX = 'includes/sync/class-sync-export.php'
IM = 'includes/sync/class-sync-import.php'
INV_F = 'includes/sync/class-sync-inventory.php'
TS = 'includes/tools/class-tools-staging.php'
G = 'includes/class-staging-gate.php'
BUD = 'includes/class-store-budget.php'
ST = 'includes/class-protected-store.php'
GRANT_F = 'includes/sync/class-sync-grant.php'
PF = 'includes/class-payload-file.php'
UN = 'uninstall.php'
RT = 'includes/sync/class-sync-export-rights.php'
OS = 'includes/sync/class-sync-option-schema.php'
DK = 'includes/sync/class-sync-db-kinds.php'
NET = 'includes/sync/class-sync-net.php'
FILEVAR = {K: '$k', A: '$a', M: '$m', F: '$f', S: '$s', AD: '$ad', RC: '$rc', O: '$o', EX: '$ex', IM: '$im', INV_F: '$inv', TS: '$ts', G: '$g', BUD: '$bud', ST: '$st', GRANT_F: '$gr', PF: '$pf', UN: '$un', RT: '$rt', OS: '$os', DK: '$dk', NET: '$net'}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append((id_, clause, file, search, replace, run, expect))


T = '\t'

# ------------------------------------------------------------------ key
RB = "\t\tif ( null === $found || $found['key']['source_id'] !== $source || self::mac_secret() !== hex2bin( $mac ) ) {"
STORAGE_RB = 'testARenewalTheOptionsDoNotKeepConfirmsNothingAndLeavesNoKey with data set "%s"'
e('paket/key-renew-found', 'renew(): a key must read back', K, RB, RB.replace("null === $found || ", ''),
  'storage', STORAGE_RB % 'only the private key refused')
e('paket/key-renew-source-id', 'renew(): the source id it made reads back', K, RB, RB.replace(" || $found['key']['source_id'] !== $source", ''),
  'storage', STORAGE_RB % 'another source id kept')
e('paket/key-renew-forget', 'renew(): a key that did not read back is removed, the old one too', K,
  "\t\t\tself::forget();\n\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_key_failed',",
  "\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_key_failed',",
  'storage', STORAGE_RB % 'both writes refused')
STORE = 'testAFailedWriteOfThePrivateKeyIsReportedWithoutIt'
e('paket/key-store-quiet', 'store_secret(): no error is logged while the private key is written', K,
  "$quiet   = null !== $db ? $db->suppress_errors( true ) : false;", "$quiet   = null !== $db ? $db->suppress_errors( false ) : false;", 'storage', STORE)
e('paket/key-store-hidden', 'store_secret(): no error is shown while the private key is written', K,
  "$visible = null !== $db ? $db->hide_errors() : false;", "$visible = null !== $db ? $db->show_errors( true ) : false;", 'storage', STORE)
e('paket/key-store-quiet-back', 'store_secret(): errors are reported again afterwards', K,
  "\t\t\t\t$db->suppress_errors( $quiet );\n", "", 'storage', STORE)
e('paket/key-store-shown-back', 'store_secret(): errors are shown again afterwards', K,
  "\t\t\t\t$db->show_errors( $visible );\n", "", 'storage', STORE)
e('paket/key-store-last-query', 'store_secret(): last_query does not keep the query', K,
  "\t\t\t\t$db->last_query = null;\n", "", 'storage', STORE)
e('paket/key-store-func-call', 'store_secret(): func_call does not keep the query', K,
  "\t\t\t\t$db->func_call  = null;\n", "", 'storage', STORE)
e('paket/key-store-last-error', 'store_secret(): last_error does not keep an error that quotes the query', K,
  "\t\t\t\t$db->last_error = '';\n", "", 'storage', STORE)
e('paket/key-store-queries', 'store_secret(): SAVEQUERIES keeps no query of the private key', K,
  "\t\t\t\t\tarray_splice( $db->queries, $logged );", "", 'storage', 'testAWriteThatWorksLeavesNoTraceOfItsQuery')
e('paket/key-store-queries-before', 'store_secret(): the queries before stay', K,
  "array_splice( $db->queries, $logged )", "array_splice( $db->queries, 0 )", 'storage', STORE)
e('paket/key-store-ezsql', 'store_secret(): $EZSQL_ERROR keeps no error of the private key', K,
  "\t\t\t\tarray_splice( $GLOBALS['EZSQL_ERROR'], $errors );", "", 'storage', STORE)
e('paket/key-store-ezsql-before', 'store_secret(): the errors before stay', K,
  "array_splice( $GLOBALS['EZSQL_ERROR'], $errors )", "array_splice( $GLOBALS['EZSQL_ERROR'], 0 )", 'storage', STORE)
e('paket/key-store-switches', 'store_secret(): a database layer without the switches of wpdb is written to as it is', K,
  "$db      = is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) && method_exists( $wpdb, 'hide_errors' ) && method_exists( $wpdb, 'show_errors' ) ? $wpdb : null;",
  "$db      = is_object( $wpdb ) ? $wpdb : null;", 'storage', 'testADatabaseLayerWithoutTheSwitchesOfWpdbIsWrittenToAsItIs')
e('paket/key-secret-not-autoloaded', 'renew(): the private key in an option without autoload', K,
  "\t\t\tupdate_option( $option, $value, false );",
  "\t\t\tupdate_option( $option, $value, true );",
  'trust', 'testConfirmingTheRoleStagingMakesASourceIdAndAKeyInOptionsOfTheirOwn')
e('paket/key-source-not-autoloaded', 'renew(): the public part in an option without autoload', K,
  "\t\t\t\t'created'    => null === $now ? time() : (int) $now,\n\t\t\t),\n\t\t\tfalse",
  "\t\t\t\t'created'    => null === $now ? time() : (int) $now,\n\t\t\t),\n\t\t\ttrue",
  'trust', 'testConfirmingTheRoleStagingMakesASourceIdAndAKeyInOptionsOfTheirOwn')
e('paket/key-make-role', 'make(): only a confirmed staging site, also with a key left over', K,
  "\tpublic static function make() {\n\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {",
  "\tpublic static function make() {\n\t\tif ( false ) {",
  'trust', 'testAStagingSiteWithoutAKeyGetsOneOnlyFromAPersonAndNeverASecond')
e('paket/key-make-keeps', 'make(): a key that is there stays', K,
  "\t\tif ( null !== $key ) {\n\t\t\t// A key without the secret of its MACs gets one here as well.",
  "\t\tif ( false ) {\n\t\t\t// A key without the secret of its MACs gets one here as well.",
  'trust', 'testAStagingSiteWithoutAKeyGetsOneOnlyFromAPersonAndNeverASecond')
e('paket/key-forget-secret', 'forget(): the private key goes too', K,
  "\t\tdelete_option( self::OPTION_SECRET );\n\t\tdelete_option( self::OPTION_SOURCE );",
  "\t\tdelete_option( self::OPTION_SOURCE );",
  'trust', 'testEachConfirmationOfTheRoleStagingMakesANewKeyAndAnyOtherRoleNone')
e('paket/key-found-format', 'found(): the public part of this format only', K,
  "if ( ! is_array( $source ) || ! isset( $source['format'] ) || self::FORMAT !== $source['format'] ) {",
  "if ( ! is_array( $source ) || ! isset( $source['format'] ) ) {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "another format"')
e('paket/key-found-record', 'found(): the public part as key_record() checks it', K,
  "\t\t$key = self::key_record( $source );\n\t\tif ( null === $key ) {\n\t\t\treturn null;\n\t\t}",
  "\t\t$key = self::key_record( $source );",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a source id in capitals"')
e('paket/key-found-secret', 'found(): no key without its private key', K,
  "\t\t$secret = self::secret( $key['key_id'], $key['public_key'] );\n\t\tif ( null === $secret ) {\n\t\t\treturn null;\n\t\t}",
  "\t\t$secret = self::secret( $key['key_id'], $key['public_key'] );",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "no private key"')
e('paket/key-sign-role', 'sign(): only on a confirmed staging site', K,
  "\tpublic static function sign( $message, $key_id ) {\n\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {",
  "\tpublic static function sign( $message, $key_id ) {\n\t\tif ( false ) {",
  'trust', 'testSigningNamesTheKeyItSignsWith')
e('paket/key-sign-missing', 'sign(): nothing without a usable key', K,
  "\t\t$found = self::found();\n\t\tif ( null === $found ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_key_missing'",
  "\t\t$found = self::found();\n\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_key_missing'",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "no private key"')
e('paket/key-sign-key-id', 'sign(): only bytes that name this key', K,
  "\t\tif ( ! is_string( $key_id ) || ! hash_equals( $found['key']['key_id'], $key_id ) ) {",
  "\t\tif ( false ) {",
  'trust', 'testSigningNamesTheKeyItSignsWith')
e('paket/key-answer-role', 'answer(): only a confirmed staging site gives its key', K,
  "\tpublic static function answer() {\n\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {",
  "\tpublic static function answer() {\n\t\tif ( false ) {",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/key-answer-key', 'answer(): nothing without a usable key', K,
  "\t\tif ( null === $key || '' === $site ) {",
  "\t\tif ( '' === $site ) {",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/key-answer-site', 'answer(): nothing without an own address', K,
  "\t\tif ( null === $key || '' === $site ) {",
  "\t\tif ( null === $key ) {",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/key-code-trim', 'read_code(): white space around the code is left out', K,
  "\t\t$code = is_string( $code ) ? trim( $code ) : '';",
  "\t\t$code = is_string( $code ) ? $code : '';",
  'trust', 'testThePairingCodeReadsBackAsTheKeyOfTheStagingSite')
e('paket/key-pairing-code-needs-answer', 'pairing_code(): no code where there is no key to give', K,
  "\t\t$answer = self::answer();\n\t\tif ( null === $answer ) {\n\t\t\treturn null;\n\t\t}",
  "\t\t$answer = self::answer();",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/key-code-text', 'read_code(): a code is text', K,
  "\t\t$code = is_string( $code ) ? trim( $code ) : '';",
  "\t\t$code = trim( $code );",
  'trust', 'testAPairingCodeIsText')
e('paket/key-code-json', 'read_code(): the code holds a JSON object', K,
  "if ( ! is_array( $data ) || self::CODE_FIELDS !== array_keys( $data ) ||",
  "if ( self::CODE_FIELDS !== array_keys( $data ) ||",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "no JSON inside"')
e('paket/key-answer-offer', 'read_answer(): the key it offers reads', K,
  "if ( null === $offer || ! is_string( $data['fingerprint'] )",
  "if ( ! is_string( $data['fingerprint'] )",
  'trust', 'testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt with data set "another key id"')
e('paket/key-code-length', 'read_code(): at most MAX_CODE characters', K,
  "\t\tif ( strlen( $code ) > self::MAX_CODE ) {",
  "\t\tif ( false ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "too long, valid but for that"')
e('paket/key-code-round-trip', 'read_code(): base64 only in its one spelling', K,
  "if ( base64_encode( (string) $json ) !== $code ) {",
  "if ( false ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "bits that encode nothing"')
e('paket/key-code-fields', 'read_code(): exactly the fields, in their order', K,
  "|| self::CODE_FIELDS !== array_keys( $data ) ||",
  "||",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "a field more"')
e('paket/key-code-json-round-trip', 'read_code(): the JSON as pairing_code() writes it', K,
  "|| wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) !== $json ) {",
  ") {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "escaped slashes"')
e('paket/key-offer-format', 'offer(): the format of the key', K,
  "\t\tif ( self::FORMAT !== $data['format'] ) {",
  "\t\tif ( false ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "another format"')
e('paket/key-offer-site-empty', 'offer(): an address of the staging site', K,
  "\t\tif ( '' === $data['site'] || AB_MCP_Staging::normalize_url( $data['site'] ) !== $data['site'] ) {",
  "\t\tif ( AB_MCP_Staging::normalize_url( $data['site'] ) !== $data['site'] ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "no address"')
e('paket/key-offer-site-stored', 'offer(): the address as normalize_url() stores it', K,
  "\t\tif ( '' === $data['site'] || AB_MCP_Staging::normalize_url( $data['site'] ) !== $data['site'] ) {",
  "\t\tif ( '' === $data['site'] ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "an address not as stored"')
e('paket/key-answer-fields', 'read_answer(): exactly the fields of the route', K,
  "\t\tif ( $fields !== $keys ) {",
  "\t\tif ( false ) {",
  'trust', 'testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt with data set "a field more"')
e('paket/key-answer-fingerprint', 'read_answer(): the fingerprint is the one of the key', K,
  "|| ! is_string( $data['fingerprint'] ) || ! hash_equals( $offer['fingerprint'], $data['fingerprint'] ) ) {",
  ") {",
  'trust', 'testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt with data set "another fingerprint"')
e('paket/key-record-isset', 'key_record(): source id, key id and public key are there', K,
  "if ( ! is_array( $raw ) || ! isset( $raw['source_id'], $raw['key_id'], $raw['public_key'] ) ) {",
  "if ( ! is_array( $raw ) ) {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "no public key"')
e('paket/key-record-source-id-text', 'key_record(): the source id is text', K,
  "if ( ! is_string( $raw['source_id'] ) || 1 !== preg_match( self::ID, $raw['source_id'] ) ||",
  "if ( 1 !== preg_match( self::ID, $raw['source_id'] ) ||",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a source id as a list"')
e('paket/key-record-source-id', 'key_record(): the source id is 32 hex in lower case', K,
  "|| 1 !== preg_match( self::ID, $raw['source_id'] ) ||",
  "||",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a source id in capitals"')
e('paket/key-record-key-id-text', 'key_record(): the key id is text', K,
  "|| ! is_string( $raw['key_id'] ) ) {\n\t\t\treturn null;\n\t\t}\n\t\t$public = self::from_base64( $raw['public_key'], self::PUBLIC_BYTES );",
  ") {\n\t\t\treturn null;\n\t\t}\n\t\t$public = self::from_base64( $raw['public_key'], self::PUBLIC_BYTES );",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a key id as a list"')
e('paket/key-record-public', 'key_record(): a public key of 32 bytes in its one spelling', K,
  "if ( null === $public || ! hash_equals( self::key_id( $public ), $raw['key_id'] ) ) {",
  "if ( ! hash_equals( self::key_id( (string) $public ), $raw['key_id'] ) ) {",
  'trust', 'testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt with data set "a key that is no base64, with the key id of nothing"')
e('paket/key-record-key-id', 'key_record(): the key id is the one of the key', K,
  "if ( null === $public || ! hash_equals( self::key_id( $public ), $raw['key_id'] ) ) {",
  "if ( null === $public ) {",
  'trust', 'testAPairingCodeReadsOnlyInItsOneSpelling with data set "a key id not of the key"')
e('paket/key-base64-text', 'from_base64(): text only', K,
  "\t\tif ( ! is_string( $text ) ) {\n\t\t\treturn null;\n\t\t}",
  "",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a public key as a list"')
e('paket/key-base64-length', 'from_base64(): exactly the bytes asked for', K,
  "if ( strlen( (string) $raw ) !== (int) $bytes ||",
  "if (",
  'trust', 'testTheAnswerOfTheRouteReadsOnlyAsTheRouteWritesIt with data set "a key of 31 bytes"')
e('paket/key-base64-round-trip', 'from_base64(): one spelling of the bytes', K,
  "|| self::to_base64( $raw ) !== $text ) {",
  ") {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a public key with bits for nothing"')
e('paket/key-secret-isset', 'secret(): the option holds a key id and a private key', K,
  "if ( ! is_array( $raw ) || ! isset( $raw['key_id'], $raw['secret_key'] ) ||",
  "if ( ! is_array( $raw ) ||",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a private key without its key"')
e('paket/key-secret-key-id-text', 'secret(): its key id is text', K,
  "|| ! is_string( $raw['key_id'] ) || ! hash_equals( (string) $key_id, $raw['key_id'] ) ) {",
  "|| ! hash_equals( (string) $key_id, $raw['key_id'] ) ) {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a private key with a key id as a list"')
e('paket/key-secret-key-id', 'secret(): the private key of this key id', K,
  "|| ! is_string( $raw['key_id'] ) || ! hash_equals( (string) $key_id, $raw['key_id'] ) ) {",
  "|| ! is_string( $raw['key_id'] ) ) {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "the private key of another key id"')
e('paket/key-secret-seed', 'secret(): the private key is the one its seed makes', K,
  "\t\tif ( ! hash_equals( sodium_crypto_sign_secretkey( $pair ), $secret ) ) {",
  "\t\tif ( false ) {",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "a private key whose second half is not its public key"')
e('paket/key-secret-carries', 'secret(): the key pair of the seed has exactly this public key', K,
  "return hash_equals( (string) self::from_base64( $public_key, self::PUBLIC_BYTES ), sodium_crypto_sign_publickey( $pair ) ) ? $secret : null;",
  "return $secret;",
  'trust', 'testAKeyCountsOnlyWhereBothOptionsAgree with data set "the private key of another key"')
SF = "\t\tif ( '' === $stored || ! preg_match( '~^(https?)://([^/?#:@\\[\\]]+)(:[0-9]+)?(/[^?#]*)?\\z~i', trim( (string) $url ), $m ) || ! preg_match( '/[\\x80-\\xff]/', $m[2] ) ) {"
ADDR = 'testAnInternationalHostIsComparedInItsXnForm'
e('paket/site-form-stored', 'site_form(): no address normalize_url() refuses', K, SF, SF.replace("'' === $stored || ", ''), 'address', ADDR)
e('paket/site-form-text', 'site_form(): a host in brackets as stored', K, SF, SF.replace("|| ! preg_match( '~^(https?)://([^/?#:@\\[\\]]+)(:[0-9]+)?(/[^?#]*)?\\z~i', trim( (string) $url ), $m ) ", ''), 'address', ADDR)
e('paket/site-form-ascii', 'site_form(): an ASCII host as stored, not read as a name', K, SF, SF.replace(" || ! preg_match( '/[\\x80-\\xff]/', $m[2] )", ''), 'address', ADDR)
e('paket/site-form-no-intl', 'site_form(): without intl the ASCII letters in lower case, the rest byte for byte', K,
  "\t\t$host   = strtr( $host, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );", "",
  'address', 'testWithoutIntlAnInternationalHostIsComparedByteForByte')
e('paket/site-form-unreadable', 'site_form(): a name idn_to_ascii() does not take is no address', K,
  "\t\t\tif ( ! is_string( $host ) || '' === $host ) {", "\t\t\tif ( false ) {", 'address', ADDR)
e('paket/site-form-port', 'site_form(): the port stays', K,
  "'://host.invalid' . ( isset( $m[3] ) ? $m[3] : '' ) .", "'://host.invalid' . '' .", 'address', ADDR)
e('paket/site-form-path', 'site_form(): the path stays', K,
  ". ( isset( $m[4] ) ? $m[4] : '' ) )", ". '' )", 'address', ADDR)
HINT = 'testWithoutIntlAnInternationalHostIsComparedByteForByte'
e('paket/idn-hint-ascii', 'idn_hint(): nothing to say without an international name', K,
  "\t\tif ( ! preg_match( '/[\\x80-\\xff]/', (string) $a . (string) $b ) ) {", "\t\tif ( false ) {", 'address', HINT)
e('paket/idn-hint-read', 'idn_hint(): nothing to say where both names were read', K,
  "\t\tif ( $read && '' !== self::site_form( $a, $idn ) && '' !== self::site_form( $b, $idn ) ) {", "\t\tif ( false ) {", 'address', HINT)
e('paket/key-answer-site-form', 'answer(): the staging site names itself as site_form() writes it', K,
  "\t\t$site = self::site_form( home_url() );", "\t\t$site = AB_MCP_Staging::normalize_url( home_url() );",
  'address', 'testANameStoredWithBrokenBytesIsRefusedWithTheWay')

e('paket/key-route-none', 'rest_key(): 404 where there is no key to give', K,
  "\t\tif ( null === $answer ) {\n\t\t\treturn new WP_Error( 'ab_mcp_staging_key_none'",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_staging_key_none'",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/key-route-no-store', 'rest_key(): the answer is not stored on the way', K,
  "\t\t$response->header( 'Cache-Control', 'no-store' );",
  "",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')
e('paket/route-get-only', 'the route of the key reads only', RC,
  "\t\t\t\t\t'methods'             => 'GET',\n\t\t\t\t\t'callback'            => array( 'AB_MCP_Sync_Key', 'rest_key' ),",
  "\t\t\t\t\t'methods'             => 'GET, POST',\n\t\t\t\t\t'callback'            => array( 'AB_MCP_Sync_Key', 'rest_key' ),",
  'trust', 'testTheRouteGivesThePublicPartToAnyoneAndNothingElse')

# --------------------------------------------------------------- staging
RENEW = "\t\tif ( self::ROLE_STAGING === $role && ( $role_changed || $confirms( $stored['role'] ) ) ) {"
e('paket/staging-renews', 'save(): confirming the role staging site makes a key', S,
  "\t\t\t$key = AB_MCP_Sync_Key::renew();", "\t\t\t$key = array();",
  'trust', 'testConfirmingTheRoleStagingMakesASourceIdAndAKeyInOptionsOfTheirOwn')
e('paket/staging-renews-only-staging', 'save(): no key is made for another role', S, RENEW, RENEW.replace("self::ROLE_STAGING === $role && ", ''),
  'storage', 'testAProductionSiteConfirmsItsRoleWhateverTheKeyOptionsTake')
e('paket/staging-renews-only-when-written', 'save(): a save that keeps the role keeps the key', S, RENEW, "\t\tif ( self::ROLE_STAGING === $role ) {",
  'trust', 'testEachConfirmationOfTheRoleStagingMakesANewKeyAndAnyOtherRoleNone')
e('paket/staging-renews-on-form-confirmation', 'save(): the form confirming the hub\'s role is a new confirmation', S, RENEW, RENEW.replace(" || $confirms( $stored['role'] )", ''),
  'trust', 'testEachConfirmationOfTheRoleStagingMakesANewKeyAndAnyOtherRoleNone')
e('paket/staging-key-refusal', 'save(): without the new key nothing is saved', S,
  "\t\t\tif ( is_wp_error( $key ) ) {\n\t\t\t\treturn $key;\n\t\t\t}", "",
  'storage', STORAGE_RB % 'both writes refused')
e('paket/staging-forgets-key', 'save(): a site with another role keeps no key', S,
  "\t\tif ( self::ROLE_STAGING !== $role ) {\n\t\t\tAB_MCP_Sync_Key::forget();\n\t\t}", "",
  'trust', 'testEachConfirmationOfTheRoleStagingMakesANewKeyAndAnyOtherRoleNone')
e('paket/staging-check-partner-port', 'check_partner(): the other site on the port of its stored address', S,
  "$response = AB_MCP_Outbound::get_partner(", "$response = AB_MCP_Outbound::get(",
  'status', 'testTheConnectionIsTiedToTheCheckedAddresses with data set "its own port"')
e('paket/staging-pair-forgets-anchor', 'save(): another pair takes the anchor away', S,
  "\t\tif ( $pair_changed ) {\n\t\t\t$forgot = AB_MCP_Sync_Anchor::forget();",
  "\t\tif ( false ) {\n\t\t\t$forgot = AB_MCP_Sync_Anchor::forget();",
  'trust', 'testAnotherPairOrRoleTakesTheAnchorAway')
e('paket/staging-remove-pair-forgets-anchor', 'remove_pair(): the hub taking the pair back takes the anchor', S,
  "\t\t$forgot = AB_MCP_Sync_Anchor::forget();\n\t\tif ( is_wp_error( $forgot ) ) {\n\t\t\treturn $forgot;\n\t\t}\n\t\t$had",
  "\t\t$forgot = true;\n\t\tif ( is_wp_error( $forgot ) ) {\n\t\t\treturn $forgot;\n\t\t}\n\t\t$had",
  'trust', 'testAnotherPairOrRoleTakesTheAnchorAway')

# --------------------------------------------------------------- fetcher
e('paket/fetcher-https-only', 'get(): the staging site is asked over https only', F,
  "\t\tif ( 0 !== strpos( $url, 'https://' ) ) {",
  "\t\tif ( false ) {",
  'trust', 'testAnHttpAddressIsNeverAsked')
e('paket/fetcher-no-redirect', 'get(): no redirect, whatever the caller asks', F,
  "\t\t$args['redirection'] = 0;",
  "",
  'trust', 'testTheFetcherFollowsNoRedirectWhateverTheCallerAsksFor')

# ---------------------------------------------------------------- anchor
e('paket/anchor-fetch-partner', 'prepare_from_partner(): only the production site paired with a staging site', A,
  "\tpublic static function prepare_from_partner( $user_id, $fetcher = null ) {\n\t\t$partner = self::partner();\n\t\tif ( is_wp_error( $partner ) ) {\n\t\t\treturn $partner;\n\t\t}",
  "\tpublic static function prepare_from_partner( $user_id, $fetcher = null ) {\n\t\t$partner = self::partner();",
  'trust', 'testOnlyTheConfirmedProductionSitePairedWithAStagingSitePrepares')
e('paket/anchor-fetch-https-only', 'prepare_from_partner(): an http:// staging site is not asked', A,
  "\t\tif ( 0 !== strpos( $partner, 'https://' ) ) {",
  "\t\tif ( false ) {",
  'trust', 'testAnHttpAddressIsNeverAsked')
e('paket/anchor-fetch-unreached', 'prepare_from_partner(): no answer prepares nothing', A,
  "\t\tif ( is_wp_error( $response ) ) {\n\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_anchor_unreached',",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_anchor_unreached',",
  'trust', 'testAnUnreachedOrWrongAnswerPreparesNothing')
e('paket/anchor-fetch-status', 'prepare_from_partner(): only a 200 gives a key, a redirect is no key', A,
  "\t\tif ( 200 !== $status ) {",
  "\t\tif ( false ) {",
  'trust', 'testTheProductionSiteFetchesTheKeyOverTheBoundFetcherAndFollowsNoRedirect')
e('paket/anchor-fetch-answer', 'prepare_from_partner(): an answer that does not read prepares nothing', A,
  "\t\t$key = AB_MCP_Sync_Key::read_answer( (string) wp_remote_retrieve_body( $response ) );\n\t\tif ( is_wp_error( $key ) ) {\n\t\t\treturn $key;\n\t\t}",
  "\t\t$key = AB_MCP_Sync_Key::read_answer( (string) wp_remote_retrieve_body( $response ) );",
  'trust', 'testAnUnreachedOrWrongAnswerPreparesNothing')
e('paket/anchor-code-partner', 'prepare_from_code(): only the production site paired with a staging site', A,
  "\tpublic static function prepare_from_code( $code, $user_id ) {\n\t\t$partner = self::partner();\n\t\tif ( is_wp_error( $partner ) ) {\n\t\t\treturn $partner;\n\t\t}",
  "\tpublic static function prepare_from_code( $code, $user_id ) {\n\t\t$partner = self::partner();",
  'trust', 'testOnlyTheConfirmedProductionSitePairedWithAStagingSitePrepares')
e('paket/anchor-code-reads', 'prepare_from_code(): a code that does not read prepares nothing', A,
  "\t\t$key = AB_MCP_Sync_Key::read_code( $code );\n\t\tif ( is_wp_error( $key ) ) {\n\t\t\treturn $key;\n\t\t}",
  "\t\t$key = AB_MCP_Sync_Key::read_code( $code );",
  'trust', 'testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint')
HOLD = "\t\tif ( '' === $site || AB_MCP_Sync_Key::site_form( $partner ) !== $site ) {"
e('paket/anchor-site-exact', 'hold(): the key names the stored address of the staging site exactly', A, HOLD, "\t\tif ( '' === $site ) {",
  'trust', 'testAKeyForAnotherAddressThanTheStoredOneIsNotPrepared')
e('paket/anchor-site-unreadable', 'hold(): a name that cannot be read is no site, also where both spell it alike', A, HOLD, HOLD.replace("'' === $site || ", ''),
  'address', 'testANameThatCannotBeReadIsNoSiteEvenWhereBothSpellItAlike')
e('paket/anchor-fetch-xn', 'prepare_from_partner(): the key is fetched from the xn-- form of the stored address', A,
  "self::key_url( AB_MCP_Sync_Key::site_form( $partner ) )", "self::key_url( $partner )",
  'address', 'testTheKeyIsFetchedFromTheXnFormOfTheStoredAddress')
e('paket/anchor-prepare-keeps-active', 'hold(): the active anchor stays until a new one is confirmed', A,
  "\t\tself::write( $prepared, self::read()['active'] );",
  "\t\tself::write( $prepared, null );",
  'trust', 'testANewKeyStaysPreparedBesideTheActiveOneUntilConfirmed')
e('paket/anchor-confirm-role', 'confirm(): only on the confirmed production site paired with the staging site', A,
  "\tpublic static function confirm( $fingerprint, $user_id, $now = null ) {\n\t\t$partner = self::partner();\n\t\tif ( is_wp_error( $partner ) ) {\n\t\t\treturn $partner;\n\t\t}",
  "\tpublic static function confirm( $fingerprint, $user_id, $now = null ) {\n\t\t$partner = self::partner();",
  'trust', 'testOnlyTheConfirmedProductionSiteConfirms')
e('paket/anchor-confirm-nothing', 'confirm(): nothing to confirm without a prepared key', A,
  "\t\tif ( null === $prepared ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_anchor_nothing'",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_anchor_nothing'",
  'trust', 'testAPreparedKeyIsNotActiveUntilAPersonConfirmsTheFingerprintShown')
e('paket/anchor-confirm-stale', 'confirm(): only the key of the staging site stored now', A,
  "\t\tif ( $prepared['partner_url'] !== $partner ) {",
  "\t\tif ( false ) {",
  'trust', 'testConfirmingNeedsAPreparedKeyOfTheStoredStagingSite')
e('paket/anchor-confirm-fingerprint', 'confirm(): only with the fingerprint of the prepared key', A,
  "\t\tif ( ! is_string( $fingerprint ) || ! hash_equals( $prepared['fingerprint'], $fingerprint ) ) {",
  "\t\tif ( false ) {",
  'trust', 'testAPreparedKeyIsNotActiveUntilAPersonConfirmsTheFingerprintShown')
e('paket/anchor-confirm-fingerprint-text', 'confirm(): a fingerprint that is no text confirms nothing', A,
  "\t\tif ( ! is_string( $fingerprint ) || ! hash_equals( $prepared['fingerprint'], $fingerprint ) ) {",
  "\t\tif ( ! hash_equals( $prepared['fingerprint'], $fingerprint ) ) {",
  'trust', 'testAPreparedKeyIsNotActiveUntilAPersonConfirmsTheFingerprintShown')
e('paket/anchor-confirm-clears', 'confirm(): nothing is left prepared', A,
  "\t\tself::write( null, $active );",
  "\t\tself::write( $prepared, $active );",
  'trust', 'testAPreparedKeyIsNotActiveUntilAPersonConfirmsTheFingerprintShown')
e('paket/anchor-active-partner', 'active(): only for the stored staging site of a production site', A,
  "\t\treturn null !== $active && $active['partner_url'] === self::partner() && ! self::retired( $active['key_id'] ) ? $active : null;",
  "\t\treturn null !== $active && ! self::retired( $active['key_id'] ) ? $active : null;",
  'trust', 'testAnAnchorHoldsOnlyForTheStoredStagingSiteOfAProductionSite')
e('paket/anchor-prepared-partner', 'prepared(): only for the stored staging site', A,
  "\t\treturn null !== $prepared && $prepared['partner_url'] === self::partner() ? $prepared : null;",
  "\t\treturn $prepared;",
  'trust', 'testConfirmingNeedsAPreparedKeyOfTheStoredStagingSite')
e('paket/anchor-partner-role', 'partner(): only the confirmed production site', A,
  "\t\tif ( null === $record['role'] || AB_MCP_Staging::ROLE_PRODUCTION !== $record['role']['value'] ) {",
  "\t\tif ( null === $record['role'] ) {",
  'trust', 'testOnlyTheConfirmedProductionSitePairedWithAStagingSitePrepares')
e('paket/anchor-partner-pair', 'partner(): only with a staging site paired', A,
  "\t\tif ( null === $record['pair'] ) {",
  "\t\tif ( false ) {",
  'trust', 'testOnlyTheConfirmedProductionSitePairedWithAStagingSitePrepares')
e('paket/anchor-read-format', 'read(): the option of this format only', A,
  "\t\tif ( ! is_array( $raw ) || ! isset( $raw['format'] ) || self::FORMAT !== $raw['format'] ) {",
  "\t\tif ( ! is_array( $raw ) || ! isset( $raw['format'] ) ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "another format"')
e('paket/anchor-part-isset', 'part(): every field is there', A,
  "if ( ! is_array( $raw ) || ! isset( $raw['partner_url'], $raw['fingerprint'], $raw['via'], $raw['user_id'], $raw['at'] ) ) {",
  "if ( ! is_array( $raw ) ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "no way"')
e('paket/anchor-part-key', 'part(): the key as key_record() checks it', A,
  "\t\tif ( null === $key || $key['fingerprint'] !== $raw['fingerprint'] ||",
  "\t\tif ( $key['fingerprint'] !== $raw['fingerprint'] ||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "a key id not of the key"')
e('paket/anchor-part-fingerprint', 'part(): the fingerprint is the one of the key', A,
  "|| $key['fingerprint'] !== $raw['fingerprint'] ||",
  "||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "another fingerprint"')
e('paket/anchor-part-via', 'part(): fetched or entered, nothing else', A,
  "|| ! in_array( $raw['via'], self::VIA, true ) ) {",
  ") {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "an unknown way"')
e('paket/anchor-part-user-int', 'part(): who as a number', A,
  "if ( ! is_int( $raw['user_id'] ) || $raw['user_id'] < 0 ||",
  "if ( $raw['user_id'] < 0 ||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "who as text"')
e('paket/anchor-part-user-sign', 'part(): who not below zero', A,
  "if ( ! is_int( $raw['user_id'] ) || $raw['user_id'] < 0 ||",
  "if ( ! is_int( $raw['user_id'] ) ||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "who below zero"')
e('paket/anchor-part-at-int', 'part(): when as a number', A,
  "|| ! is_int( $raw['at'] ) || $raw['at'] <= 0 ) {",
  "|| $raw['at'] <= 0 ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "when as text"')
e('paket/anchor-part-at-positive', 'part(): when after 0', A,
  "|| ! is_int( $raw['at'] ) || $raw['at'] <= 0 ) {",
  "|| ! is_int( $raw['at'] ) || $raw['at'] < 0 ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "no time"')
e('paket/anchor-part-prepared-isset', 'part(): the active one says who prepared it and when', A,
  "if ( ! isset( $raw['prepared_by'], $raw['prepared_at'] ) || ",
  "if ( ",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "who prepared it, missing"')
e('paket/anchor-part-prepared-by-int', 'part(): who prepared it as a number', A,
  "|| ! is_int( $raw['prepared_by'] ) || $raw['prepared_by'] < 0 ||",
  "|| $raw['prepared_by'] < 0 ||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "who prepared it as text"')
e('paket/anchor-part-prepared-by-sign', 'part(): who prepared it not below zero', A,
  "|| ! is_int( $raw['prepared_by'] ) || $raw['prepared_by'] < 0 ||",
  "|| ! is_int( $raw['prepared_by'] ) ||",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "who prepared, below zero"')
e('paket/anchor-part-prepared-at-int', 'part(): when it was prepared as a number', A,
  "|| ! is_int( $raw['prepared_at'] ) || $raw['prepared_at'] <= 0 ) {",
  "|| $raw['prepared_at'] <= 0 ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "when it was prepared, text"')
e('paket/anchor-part-prepared-at-positive', 'part(): prepared after 0', A,
  "|| ! is_int( $raw['prepared_at'] ) || $raw['prepared_at'] <= 0 ) {",
  "|| ! is_int( $raw['prepared_at'] ) || $raw['prepared_at'] < 0 ) {",
  'trust', 'testTheAnchorReadsOnlyWhatItWrites with data set "prepared at 0"')
e('paket/print-needs-anchor', 'pair_print(): no print without an active anchor', A,
  "\t\t$active = self::active();\n\t\tif ( null === $active ) {\n\t\t\treturn null;\n\t\t}\n",
  "\t\t$active = self::active();\n",
  'trust', 'testThePairPrintChangesWithTheRoleThePairAndTheAnchor')
e('paket/print-site-form', 'pair_print(): this site\'s address from home_url() as it is, in the form site_form() gives', A,
  "\t\t$site = AB_MCP_Sync_Key::site_form( home_url() );", "\t\t$site = AB_MCP_Staging::normalize_url( home_url() );",
  'address', 'testThisSiteReadsItsOwnNameAsHomeUrlGivesIt')
e('paket/print-site-unreadable', 'pair_print(): no print for an own address site_form() cannot read', A,
  "\t\tif ( '' === $site ) {\n\t\t\treturn null;\n\t\t}\n", "",
  'address', 'testASiteWhoseOwnNameCannotBeReadHasNoPrint')
PRINT_ROLE = "array( $role['value'], $role['user_id'], $role['source'], $role['at'] )"
PRINT_PAIR = "array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['source'], $pair['at'] )"
PRINT_ANCHOR = "array( $active['source_id'], $active['key_id'], $active['public_key'], $active['via'], $active['user_id'], $active['at'] )"
for name, search, replace in (
    ('site', "\t\t\t\t'site'   => $site,\n", ''),
    ('role-at', PRINT_ROLE, "array( $role['value'], $role['user_id'], $role['source'] )"),
    ('role-who', PRINT_ROLE, "array( $role['value'], $role['source'], $role['at'] )"),
    ('role-where', PRINT_ROLE, "array( $role['value'], $role['user_id'], $role['at'] )"),
    ('pair-at', PRINT_PAIR, "array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['source'] )"),
    ('pair-who', PRINT_PAIR, "array( $pair['partner_url'], $pair['partner_role'], $pair['source'], $pair['at'] )"),
    ('pair-where', PRINT_PAIR, "array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['at'] )"),
    ('anchor-key', PRINT_ANCHOR, "array( $active['via'], $active['user_id'], $active['at'] )"),
    ('anchor-who', PRINT_ANCHOR, "array( $active['source_id'], $active['key_id'], $active['public_key'], $active['via'], $active['at'] )"),
    ('anchor-at', PRINT_ANCHOR, "array( $active['source_id'], $active['key_id'], $active['public_key'], $active['via'], $active['user_id'] )"),
):
    e('paket/print-' + name, 'pair_print(): ' + name.replace('-', ' ') + ' is in the print', A, search, replace,
      'trust', 'testThePairPrintChangesWithTheRoleThePairAndTheAnchor')

# -------------------------------------------------------------- manifest
e('paket/manifest-sign-role', 'sign(): only the confirmed staging site signs', M,
  "\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_manifest_role'",
  "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_manifest_role'",
  'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
e('paket/manifest-sign-pair', 'sign(): only for the production site of the pair', M,
  "\t\tif ( null === $pair ) {",
  "\t\tif ( false ) {",
  'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
e('paket/manifest-sign-key', 'sign(): only with a usable key', M,
  "\t\t$key = AB_MCP_Sync_Key::current();\n\t\tif ( null === $key ) {",
  "\t\t$key = AB_MCP_Sync_Key::current();\n\t\tif ( false ) {",
  'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
e('paket/manifest-sign-fields', 'sign(): the maker names the package fields and no other', M,
  "\t\tif ( self::PACKAGE_FIELDS !== $fields ) {",
  "\t\tif ( false ) {",
  'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
e('paket/manifest-sign-shape', 'sign(): nothing the production site would refuse for its shape', M,
  "\t\t$bad = self::shape( $manifest );\n\t\tif ( null !== $bad ) {\n\t\t\treturn $bad;\n\t\t}\n\t\t$text      = AB_MCP_Sync_Canon::encode( $manifest );",
  "\t\t$text      = AB_MCP_Sync_Canon::encode( $manifest );",
  'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
ENV = "\t\tif ( array( 'manifest', 'signature' ) !== $keys || ! is_string( $signed['manifest'] ) || '' === $signed['manifest'] || strlen( $signed['manifest'] ) > self::MAX_TEXT || ! is_string( $signed['signature'] ) ) {"
for name, replace, case in (
    ('keys', ENV.replace("array( 'manifest', 'signature' ) !== $keys || ", ''), 'a key more'),
    ('text', ENV.replace("|| ! is_string( $signed['manifest'] ) ", ''), 'manifest as array'),
    ('empty', ENV.replace("|| '' === $signed['manifest'] ", ''), 'empty manifest'),
    ('length', ENV.replace("|| strlen( $signed['manifest'] ) > self::MAX_TEXT ", ''), 'too long'),
    ('signature-text', ENV.replace(" || ! is_string( $signed['signature'] )", ''), 'signature no text'),
):
    e('paket/manifest-envelope-' + name, 'verify(): the envelope, ' + name.replace('-', ' '), M, ENV, replace,
      'manifest', 'testTheEnvelopeIsTheTextAndItsSignatureOnly with data set "' + case + '"')
SPELL = "\t\tif ( ! is_array( $manifest ) || AB_MCP_Sync_Canon::encode( $manifest ) !== $text ) {"
e('paket/manifest-spelling', 'verify(): only the one spelling is read', M, SPELL,
  "\t\tif ( ! is_array( $manifest ) ) {",
  'manifest', 'testOnlyTheOneSpellingIsReadEvenWithAValidSignature with data set "white space"')
e('paket/manifest-object', 'verify(): a manifest is an object', M, SPELL,
  "\t\tif ( AB_MCP_Sync_Canon::encode( $manifest ) !== $text ) {",
  'manifest', 'testAManifestThatIsNoObjectIsNotRead')
e('paket/manifest-verify-shape', 'verify(): every field as the format has it', M,
  "\t\t$bad = self::shape( $manifest );\n\t\tif ( null !== $bad ) {\n\t\t\treturn $bad;\n\t\t}\n\t\t$version",
  "\t\t$version",
  'manifest', 'testEveryFieldIsAsTheFormatHasItWhateverTheSignature with data set "a field more"')
e('paket/manifest-canon', 'verify(): the version of the canonical form of this site', M,
  "\t\tif ( null !== $version ) {",
  "\t\tif ( false ) {",
  'manifest', 'testEveryFieldIsAsTheFormatHasItWhateverTheSignature with data set "another canon"')
e('paket/manifest-anchor', 'verify(): nothing is read without a confirmed anchor', M,
  "\t\tif ( null === $anchor ) {",
  "\t\tif ( false ) {",
  'manifest', 'testAManifestIsReadOnlyWithAKeyAPersonConfirmed')
e('paket/manifest-unconfirmed', 'verify(): a prepared key is named as not confirmed', M,
  "\t\t\tif ( null !== AB_MCP_Sync_Anchor::prepared() ) {",
  "\t\t\tif ( false ) {",
  'manifest', 'testAManifestIsReadOnlyWithAKeyAPersonConfirmed')
KEYS = "\t\tif ( $manifest['source_id'] !== $anchor['source_id'] || $manifest['key_id'] !== $anchor['key_id'] ) {"
e('paket/manifest-source-id', 'verify(): the source id of the anchor', M, KEYS,
  "\t\tif ( $manifest['key_id'] !== $anchor['key_id'] ) {",
  'manifest', 'testAManifestSignedWithAnotherKeyIsRefused')
e('paket/manifest-key-id', 'verify(): the key id of the anchor', M, KEYS,
  "\t\tif ( $manifest['source_id'] !== $anchor['source_id'] ) {",
  'manifest', 'testAManifestSignedWithAnotherKeyIsRefused')
e('paket/manifest-signature', 'verify(): the signature with the confirmed key', M,
  "\t\tif ( true !== $valid ) {",
  "\t\tif ( false ) {",
  'manifest', 'testAManifestSignedWithAnotherKeyIsRefused')
TARGET = "\t\tif ( '' === $us || AB_MCP_Sync_Key::site_form( $manifest['target'] ) !== $us ) {"
SOURCE = "\t\tif ( '' === $from || AB_MCP_Sync_Key::site_form( $anchor['partner_url'] ) !== $from ) {"
UNREAD = 'testAnUnreadableNameIsNoTargetAndNoSourceEvenWhereBothSpellItAlike'
e('paket/manifest-target', 'verify(): the target is exactly this site\'s address', M, TARGET, "\t\tif ( '' === $us ) {",
  'manifest', 'testAManifestForAnotherAddressThanThisSiteIsRefused')
# verify() reads this site's own address from home_url() as it is: where
# normalize_url() keeps the bytes of the name (Linux, glibc), normalize_url()
# before site_form() changes nothing, so no entry here; on macOS
# testThisSiteReadsItsOwnNameAsHomeUrlGivesIt holds the order.
e('paket/manifest-target-unreadable', 'verify(): this site\'s own name that cannot be read is no target', M, TARGET, TARGET.replace("'' === $us || ", ''), 'address', UNREAD)
e('paket/manifest-target-idn', 'verify(): the target in either spelling of an international name', M,
  "AB_MCP_Sync_Key::site_form( $manifest['target'] ) !== $us", "$manifest['target'] !== $us", 'address', 'testThePackageIsForThisSiteInEitherSpelling')
e('paket/manifest-source', 'verify(): the source is exactly the stored staging site', M, SOURCE, "\t\tif ( '' === $from ) {",
  'manifest', 'testAManifestFromAnotherAddressThanTheStagingSiteIsRefused')
e('paket/manifest-source-unreadable', 'verify(): a staging site whose name cannot be read is no source', M, SOURCE, SOURCE.replace("'' === $from || ", ''), 'address', UNREAD)
e('paket/manifest-source-idn', 'verify(): the source in either spelling of an international name', M,
  "AB_MCP_Sync_Key::site_form( $anchor['partner_url'] ) !== $from", "$anchor['partner_url'] !== $from",
  'address', 'testAnInternationalNameAnchorsInEitherSpelling with data set "stored in Unicode, the key in xn--"')
SIGNADDR = "\t\tif ( '' === $source || '' === $target ) {"
e('paket/manifest-sign-own-address', 'sign(): nothing for an own address that cannot be read', M, SIGNADDR, "\t\tif ( '' === $target ) {", 'address', 'testAStagingSiteThatCannotReadAnAddressSignsNothing')
e('paket/manifest-sign-target-address', 'sign(): nothing for a production site whose address cannot be read', M, SIGNADDR, "\t\tif ( '' === $source ) {", 'address', 'testAStagingSiteThatCannotReadAnAddressSignsNothing')
e('paket/manifest-expired', 'verify(): expired at expires, not a second later', M,
  "\t\tif ( $now >= $manifest['expires'] ) {",
  "\t\tif ( $now > $manifest['expires'] ) {",
  'manifest', 'testAManifestIsReadFor24HoursFromWhenItWasMade')
e('paket/manifest-future', 'verify(): made at most SKEW later than now', M,
  "\t\tif ( $manifest['created'] > $now + self::SKEW ) {",
  "\t\tif ( $manifest['created'] >= $now + self::SKEW ) {",
  'manifest', 'testAManifestIsReadFor24HoursFromWhenItWasMade')
PURPOSE = "\t\tif ( ! isset( $expect['purpose'] ) || $expect['purpose'] !== $manifest['purpose'] ) {"
e('paket/manifest-purpose', 'verify(): read only for its purpose', M, PURPOSE,
  "\t\tif ( ! isset( $expect['purpose'] ) ) {",
  'manifest', 'testAPackageIsReadOnlyForItsPurposeItsReportAndItself')
e('paket/manifest-purpose-named', 'verify(): the caller names the purpose', M, PURPOSE,
  "\t\tif ( $expect['purpose'] !== $manifest['purpose'] ) {",
  'manifest', 'testAPackageIsReadOnlyForItsPurposeItsReportAndItself')
REPORT = "\t\tif ( in_array( $manifest['purpose'], self::FOR_REPORT, true ) && ( ! isset( $expect['report_hash'] ) || ! is_string( $expect['report_hash'] ) || ! hash_equals( $manifest['report_hash'], $expect['report_hash'] ) ) ) {"
for name, replace in (
    ('report', "\t\tif ( false ) {"),
    ('report-only-for-reports', REPORT.replace("in_array( $manifest['purpose'], self::FOR_REPORT, true ) && ", '')),
    ('report-named', REPORT.replace("! isset( $expect['report_hash'] ) || ", '')),
    ('report-text', REPORT.replace("|| ! is_string( $expect['report_hash'] ) ", '')),
):
    e('paket/manifest-' + name, 'verify(): a package of objects or media for its own report, ' + name.replace('-', ' '), M, REPORT, replace,
      'manifest', 'testAPackageIsReadOnlyForItsPurposeItsReportAndItself')
e('paket/manifest-package', 'verify(): the manifest of the package being read', M,
  "\t\tif ( isset( $expect['package_id'] ) && $expect['package_id'] !== $manifest['package_id'] ) {",
  "\t\tif ( false ) {",
  'manifest', 'testAPackageIsReadOnlyForItsPurposeItsReportAndItself')
CHUNKI = "\t\tif ( ! is_int( $index ) || ! isset( $manifest['chunks'][ $index ] ) ) {"
e('paket/chunk-index-int', 'chunk(): the index is a number', M, CHUNKI,
  "\t\tif ( ! isset( $manifest['chunks'][ $index ] ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')
e('paket/chunk-index-listed', 'chunk(): the index is one the manifest lists', M, CHUNKI,
  "\t\tif ( ! is_int( $index ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')
CHUNKH = "\t\tif ( ! is_string( $data ) || ! hash_equals( $manifest['chunks'][ $index ]['sha256'], hash( 'sha256', $data ) ) ) {"
e('paket/chunk-hash', 'chunk(): each chunk against its own hash (a swapped chunk is refused)', M, CHUNKH,
  "\t\tif ( ! is_string( $data ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')
e('paket/chunk-text', 'chunk(): bytes only', M, CHUNKH,
  "\t\tif ( ! hash_equals( $manifest['chunks'][ $index ]['sha256'], hash( 'sha256', $data ) ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')
WHOLE = "\t\tif ( ! is_string( $sha256 ) || ! hash_equals( $manifest['sha256'], $sha256 ) ) {"
e('paket/whole-hash', 'whole(): the package against its hash', M, WHOLE,
  "\t\tif ( ! is_string( $sha256 ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')
e('paket/whole-text', 'whole(): a hash as text', M, WHOLE,
  "\t\tif ( ! hash_equals( $manifest['sha256'], $sha256 ) ) {",
  'manifest', 'testEachChunkIsReadOnlyAgainstItsSizeAndHash')

# shape
BAD = 'testEveryFieldIsAsTheFormatHasItWhateverTheSignature with data set "%s"'
LIM = 'testTheLimitsThemselvesAreRead with data set "%s"'
e('paket/shape-fields', 'shape(): exactly the fields of the format', M,
  "\t\tif ( self::FIELDS !== $fields ) {", "\t\tif ( false ) {", 'manifest', BAD % 'a field more')
e('paket/shape-format', 'shape(): the format ab-sync/1', M,
  "\t\tif ( self::FORMAT !== $m['format'] ) {", "\t\tif ( false ) {", 'manifest', BAD % 'another format')
CANON = "\t\t\t'canon'      => is_string( $m['canon'] ) && 1 === preg_match( '/^canon\\/[1-9][0-9]{0,5}\\z/', $m['canon'] ),"
e('paket/shape-canon', 'shape(): canon/ and a number', M, CANON, "\t\t\t'canon'      => is_string( $m['canon'] ),", 'manifest', BAD % 'a canon that is none')
e('paket/shape-canon-text', 'shape(): the canon as text', M, CANON, "\t\t\t'canon'      => 1 === preg_match( '/^canon\\/[1-9][0-9]{0,5}\\z/', $m['canon'] ),", 'manifest', BAD % 'a canon as a list')
for field, case in (
    ('source_id', 'a source id in capitals'), ('key_id', 'a short key id'), ('source', 'a source not as stored'),
    ('target', 'a target not as stored'), ('package_id', 'a package id of 31'), ('purpose', 'an unknown purpose'),
    ('created', 'created as text'), ('objects', 'too many objects'), ('unpacked', 'too much unpacked'), ('sha256', 'a hash in capitals'),
):
    pad = ' ' * (11 - len(field))
    src = open(os.path.join(ROOT, M)).read()
    line = None
    for l in src.split('\n'):
        if l.startswith("\t\t\t'" + field + "'" + pad + "=> "):
            line = l
    if line is None:
        sys.exit('no check line for ' + field)
    e('paket/shape-' + field.replace('_', '-'), 'shape(): the field ' + field, M, line + '\n', "\t\t\t'" + field + "'" + pad + "=> true,\n", 'manifest', BAD % case)
e('paket/shape-created-positive', 'shape(): created after 0', M,
  "\t\t\t'created'    => is_int( $m['created'] ) && $m['created'] > 0,",
  "\t\t\t'created'    => is_int( $m['created'] ) && $m['created'] >= 0,", 'manifest', BAD % 'created at 0')
e('paket/shape-hex32-text', 'shape(): a 32 hex id as text', M,
  "\t\t\treturn is_string( $v ) && 1 === preg_match( AB_MCP_Sync_Key::ID, $v );",
  "\t\t\treturn 1 === preg_match( AB_MCP_Sync_Key::ID, $v );", 'manifest', BAD % 'a source id as a list')
e('paket/shape-hex64-text', 'shape(): a SHA-256 as text', M,
  "\t\t\treturn is_string( $v ) && 1 === preg_match( '/^[0-9a-f]{64}\\z/', $v );",
  "\t\t\treturn 1 === preg_match( '/^[0-9a-f]{64}\\z/', $v );", 'manifest', BAD % 'a hash as a list')
e('paket/shape-site-empty', 'shape(): an address is not empty', M,
  "\t\t\treturn '' !== $v && AB_MCP_Staging::normalize_url( $v ) === $v;",
  "\t\t\treturn AB_MCP_Staging::normalize_url( $v ) === $v;", 'manifest', BAD % 'no target')
COUNT = "\t\t\treturn is_int( $v ) && $v >= $min && $v <= $max;"
e('paket/shape-count-int', 'shape(): a count is a number', M, COUNT, "\t\t\treturn $v >= $min && $v <= $max;", 'manifest', BAD % 'objects as text')
e('paket/shape-count-min', 'shape(): a count not below its least', M, COUNT, "\t\t\treturn is_int( $v ) && $v <= $max;", 'manifest', BAD % 'fewer than no objects')
e('paket/shape-count-max', 'shape(): a count up to its most, that included', M, COUNT, "\t\t\treturn is_int( $v ) && $v >= $min && $v < $max;", 'manifest', LIM % '5000 objects')
e('paket/shape-objects-max', 'shape(): at most MAX_OBJECTS', M,
  "$count( $m['objects'], self::MAX_OBJECTS )", "$count( $m['objects'], self::MAX_OBJECTS + 1 )", 'manifest', BAD % 'too many objects')
e('paket/shape-unpacked-max', 'shape(): at most MAX_UNPACKED unpacked', M,
  "$count( $m['unpacked'], self::MAX_UNPACKED )", "$count( $m['unpacked'], self::MAX_UNPACKED + 1 )", 'manifest', BAD % 'too much unpacked')
REP = "\t\tif ( in_array( $m['purpose'], self::FOR_REPORT, true ) ? ! $hex64( $m['report_hash'] ) : null !== $m['report_hash'] ) {"
e('paket/shape-report-for-objects', 'shape(): objects and media name their report', M, REP,
  "\t\tif ( in_array( $m['purpose'], self::FOR_REPORT, true ) ? false : null !== $m['report_hash'] ) {", 'manifest', BAD % 'objects without their report')
e('paket/shape-report-for-none', 'shape(): inventory and env name no report', M, REP,
  "\t\tif ( in_array( $m['purpose'], self::FOR_REPORT, true ) ? ! $hex64( $m['report_hash'] ) : false ) {", 'manifest', BAD % 'an inventory with a report')
e('paket/shape-expires', 'shape(): expires exactly TTL after created', M,
  "\t\tif ( $m['expires'] !== $m['created'] + self::TTL ) {", "\t\tif ( false ) {", 'manifest', BAD % 'expires a second later')
KINDS = "\t\tif ( ! is_array( $m['kinds'] ) || ! array_is_list( $m['kinds'] ) || count( $m['kinds'] ) > self::MAX_LIST ) {"
e('paket/shape-kinds-array', 'shape(): kinds is a list', M, KINDS, KINDS.replace("! is_array( $m['kinds'] ) || ", ''), 'manifest', BAD % 'kinds as text')
e('paket/shape-kinds-list', 'shape(): kinds is a list, no map', M, KINDS, KINDS.replace("|| ! array_is_list( $m['kinds'] ) ", ''), 'manifest', BAD % 'kinds as a map')
e('paket/shape-kinds-count', 'shape(): at most MAX_LIST kinds, that included', M, KINDS, KINDS.replace("count( $m['kinds'] ) > self::MAX_LIST", "count( $m['kinds'] ) >= self::MAX_LIST"), 'manifest', LIM % '2000 kinds')
ENTRY = "\t\t\tif ( array( 'complete', 'kind', 'subtype' ) !== $keys || ! is_bool( $entry['complete'] )"
e('paket/shape-kind-keys', 'shape(): a kind has exactly kind, subtype and complete', M, ENTRY, "\t\t\tif ( ! is_bool( $entry['complete'] )", 'manifest', BAD % 'a kind without complete')
e('paket/shape-kind-complete', 'shape(): complete is true or false', M, ENTRY, "\t\t\tif ( array( 'complete', 'kind', 'subtype' ) !== $keys", 'manifest', BAD % 'complete as text')
KINDLINE = "\t\t\t\t|| ! is_string( $entry['kind'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}\\z/', $entry['kind'] )"
e('paket/shape-kind-text', 'shape(): the kind as text', M, KINDLINE, "\t\t\t\t|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}\\z/', $entry['kind'] )", 'manifest', BAD % 'a kind as a list')
e('paket/shape-kind-name', 'shape(): the kind is a name of a kind', M, KINDLINE, "\t\t\t\t|| ! is_string( $entry['kind'] )", 'manifest', BAD % 'a kind in capitals')
SUB = "\t\t\t\t|| ! is_string( $entry['subtype'] ) || 1 !== preg_match( '//u', $entry['subtype'] ) || preg_match( '/[\\x00-\\x1F\\x7F]/', $entry['subtype'] ) || mb_strlen( $entry['subtype'], 'UTF-8' ) > self::MAX_SUBTYPE ) {"
e('paket/shape-subtype-text', 'shape(): the subtype as text', M, SUB, SUB.replace("|| ! is_string( $entry['subtype'] ) || ", "|| "), 'manifest', BAD % 'a subtype not text')
e('paket/shape-subtype-utf8', 'shape(): the subtype in UTF-8', M, SUB, SUB.replace("|| 1 !== preg_match( '//u', $entry['subtype'] ) ", ''), 'manifest', 'testOnlyTheConfirmedStagingSiteSignsForItsPairWithItsKey')
e('paket/shape-subtype-control', 'shape(): no control character in the subtype', M, SUB, SUB.replace("|| preg_match( '/[\\x00-\\x1F\\x7F]/', $entry['subtype'] ) ", ''), 'manifest', BAD % 'a subtype with a line break')
e('paket/shape-subtype-length', 'shape(): at most MAX_SUBTYPE characters, that included', M, SUB, SUB.replace("> self::MAX_SUBTYPE", ">= self::MAX_SUBTYPE"), 'manifest', LIM % 'a subtype of 191')
ORDER = "\t\t\tif ( null !== $last && ( strcmp( $last['kind'], $entry['kind'] ) > 0 || ( $last['kind'] === $entry['kind'] && strcmp( $last['subtype'], $entry['subtype'] ) >= 0 ) ) ) {"
e('paket/shape-order-kind', 'shape(): kinds ascending by kind', M, ORDER, ORDER.replace("strcmp( $last['kind'], $entry['kind'] ) > 0 || ", ''), 'manifest', BAD % 'kinds out of order by kind')
e('paket/shape-order-subtype', 'shape(): one kind ascending by subtype', M, ORDER, ORDER.replace(" || ( $last['kind'] === $entry['kind'] && strcmp( $last['subtype'], $entry['subtype'] ) >= 0 )", ''), 'manifest', BAD % 'kinds out of order')
e('paket/shape-order-once', 'shape(): each kind and subtype once', M, ORDER, ORDER.replace("strcmp( $last['subtype'], $entry['subtype'] ) >= 0", "strcmp( $last['subtype'], $entry['subtype'] ) > 0"), 'manifest', BAD % 'a kind twice')
e('paket/shape-order-same-kind', 'shape(): the subtypes are compared within one kind only', M, ORDER, ORDER.replace("$last['kind'] === $entry['kind'] && ", ''), 'manifest', LIM % 'two kinds, lower subtype')
CHUNKS = "\t\tif ( ! is_array( $m['chunks'] ) || ! array_is_list( $m['chunks'] ) || array() === $m['chunks'] || count( $m['chunks'] ) > self::MAX_LIST ) {"
e('paket/shape-chunks-array', 'shape(): chunks is a list', M, CHUNKS, CHUNKS.replace("! is_array( $m['chunks'] ) || ", ''), 'manifest', BAD % 'chunks as text')
e('paket/shape-chunks-list', 'shape(): chunks is a list, no map', M, CHUNKS, CHUNKS.replace("|| ! array_is_list( $m['chunks'] ) ", ''), 'manifest', BAD % 'chunks as a map')
e('paket/shape-chunks-some', 'shape(): at least one chunk', M, CHUNKS, CHUNKS.replace("|| array() === $m['chunks'] ", ''), 'manifest', BAD % 'no chunk')
e('paket/shape-chunks-count', 'shape(): at most MAX_LIST chunks, that included', M, CHUNKS, CHUNKS.replace("count( $m['chunks'] ) > self::MAX_LIST", "count( $m['chunks'] ) >= self::MAX_LIST"), 'manifest', LIM % '2000 chunks')
CHUNK = "\t\t\tif ( array( 'bytes', 'sha256' ) !== $keys || ! $count( $chunk['bytes'], self::MAX_CHUNK, 1 ) || ! $hex64( $chunk['sha256'] ) ) {"
e('paket/shape-chunk-keys', 'shape(): a chunk has exactly bytes and sha256', M, CHUNK, CHUNK.replace("array( 'bytes', 'sha256' ) !== $keys || ", ''), 'manifest', BAD % 'a chunk with a field more')
e('paket/shape-chunk-least', 'shape(): a chunk has a byte at least', M, CHUNK, CHUNK.replace("self::MAX_CHUNK, 1 )", "self::MAX_CHUNK, 0 )"), 'manifest', BAD % 'an empty chunk')
e('paket/shape-chunk-most', 'shape(): a chunk has at most MAX_CHUNK bytes', M, CHUNK, CHUNK.replace("self::MAX_CHUNK, 1 )", "self::MAX_CHUNK + 1, 1 )"), 'manifest', BAD % 'a chunk over 5 MiB')
e('paket/shape-chunk-hash', 'shape(): a chunk names its SHA-256', M, CHUNK, CHUNK.replace(" || ! $hex64( $chunk['sha256'] )", ''), 'manifest', BAD % 'a chunk hash in capitals')
BYTES = "\t\tif ( ! $count( $m['bytes'], self::MAX_BYTES, 1 ) || $m['bytes'] !== $sum ) {"
e('paket/shape-bytes-most', 'shape(): a package has at most MAX_BYTES', M, BYTES, "\t\tif ( $m['bytes'] !== $sum ) {", 'manifest', BAD % 'a package over 64 MiB')
e('paket/shape-bytes-sum', 'shape(): bytes is the sum of the chunks', M, BYTES, "\t\tif ( ! $count( $m['bytes'], self::MAX_BYTES, 1 ) ) {", 'manifest', BAD % 'bytes not the sum')

# ----------------------------------------------------------------- admin
e('paket/admin-key-capability', 'handle_staging_key(): manage_options', AD,
  "\tpublic function handle_staging_key() {\n\t\t$this->guard();", "\tpublic function handle_staging_key() {", 'trust', 'testTheKeyIsMadeBehindTheNonceAndTheCapability')
e('paket/admin-key-nonce', 'handle_staging_key(): its nonce', AD,
  "\t\tcheck_admin_referer( 'ab_mcp_staging_key' );", "", 'trust', 'testTheKeyIsMadeBehindTheNonceAndTheCapability')
e('paket/admin-anchor-capability', 'handle_staging_anchor(): manage_options', AD,
  "\tpublic function handle_staging_anchor() {\n\t\t$this->guard();", "\tpublic function handle_staging_anchor() {", 'trust', 'testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint')
e('paket/admin-anchor-nonce', 'handle_staging_anchor(): its nonce', AD,
  "\t\tcheck_admin_referer( 'ab_mcp_staging_anchor' );", "", 'trust', 'testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint')
e('paket/admin-key-refusal', 'handle_staging_key(): a refusal lands as its notice', AD,
  "\t\tif ( is_wp_error( $made ) ) {\n\t\t\t$this->redirect( self::trust_notice( $made ), self::STAGING_TRUST );",
  "\t\tif ( false ) {\n\t\t\t$this->redirect( self::trust_notice( $made ), self::STAGING_TRUST );",
  'trust', 'testTheKeyIsMadeBehindTheNonceAndTheCapability')
e('paket/admin-anchor-refusal', 'handle_staging_anchor(): a refusal lands as its notice, not as done', AD,
  "$this->redirect( is_wp_error( $result ) ? self::trust_notice( $result ) : $done, self::STAGING_TRUST );",
  "$this->redirect( $done, self::STAGING_TRUST );",
  'trust', 'testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint')
e('paket/admin-record-key-refusal', 'handle_staging_record(): a key not stored lands as its notice, nothing saved', AD,
  "\t\tif ( is_wp_error( $saved ) && 'ab_mcp_staging_invalid' !== $saved->get_error_code() ) {", "\t\tif ( false ) {",
  'storage', 'testTheFormSaysTheRoleWasNotConfirmed')
e('paket/admin-anchor-posted-fingerprint', 'handle_staging_anchor(): the fingerprint the person confirmed, not the one stored', AD,
  "AB_MCP_Sync_Anchor::confirm( isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '', $user_id );",
  "AB_MCP_Sync_Anchor::confirm( (string) AB_MCP_Sync_Anchor::prepared()['fingerprint'], $user_id );",
  'trust', 'testTheKeyIsConfirmedOnlyBehindTheNonceTheCapabilityAndTheFingerprint')
e('paket/admin-fetch-https-only', 'staging_anchor_html(): the fetch is offered for an https:// staging site only', AD,
  "\t\tif ( 0 === strpos( $partner, 'https://' ) ) {", "\t\tif ( true ) {", 'trust', 'testTheProductionSiteShowsTheAnchorAndConfirmsOnlyThePreparedFingerprint')

# ------------------------------------------------- port of the partner
PORTS = "\t\t\tif ( ! $left || ! is_array( $ports ) || ! is_int( $port ) || $asked !== $url ) {"
e('paket/outbound-port-for-url', 'get_partner(): the port only for the very address asked', O, PORTS, PORTS.replace(" || $asked !== $url", ''),
  'address', 'testTheStagingSiteIsAskedOnThePortOfItsStoredAddressOnly')
e('paket/outbound-port-named', 'get_partner(): nothing added where the address names no port', O, PORTS, PORTS.replace(" || ! is_int( $port )", ''),
  'address', 'testWithoutAPortNothingIsAddedAndAnotherFiltersAnswerStays')
e('paket/outbound-port-list', 'get_partner(): another filter\'s answer that is no list stays', O, PORTS, PORTS.replace(" || ! is_array( $ports )", ''),
  'address', 'testWithoutAPortNothingIsAddedAndAnotherFiltersAnswerStays')
e('paket/outbound-port-once', 'get_partner(): the port once, not for a request started while the first runs', O, PORTS, PORTS.replace("! $left || ", ''),
  'address', 'testARequestStartedWhileTheFirstRunsIsNotAllowedThePort')
e('paket/outbound-port-used-up', 'get_partner(): the first check of the address uses the port up', O,
  "\t\t\t$left = false;\n", "", 'address', 'testARequestStartedWhileTheFirstRunsIsNotAllowedThePort')
e('paket/outbound-port-added', 'get_partner(): the port is allowed for the request', O,
  "\t\tadd_filter( 'http_allowed_safe_ports', $allow, 10, 3 );", "",
  'address', 'testTheStagingSiteIsAskedOnThePortOfItsStoredAddressOnly')
e('paket/outbound-port-removed', 'get_partner(): the port is taken off again', O,
  "\t\t\tremove_filter( 'http_allowed_safe_ports', $allow, 10 );", "",
  'address', 'testTheStagingSiteIsAskedOnThePortOfItsStoredAddressOnly')
FPREFIX = "\t\tif ( 0 !== strpos( $url, $base . '/' ) ) {"
e('paket/fetcher-partner-only', 'get(): nothing outside the stored address of the staging site', F, FPREFIX, "\t\tif ( false ) {",
  'address', 'testTheFetcherAsksNothingOutsideTheStoredAddress')
e('paket/fetcher-partner-path', 'get(): under the stored path, not beside it', F, FPREFIX, FPREFIX.replace("$base . '/'", '$base'),
  'address', 'testTheFetcherAsksNothingOutsideTheStoredAddress')

DOT = "\t\tif ( preg_match( '~/(?:\\.|%2e){1,2}(?:/|\\z)~i', $path ) ) {"
DOTS = 'testAPathWithASegmentDotOrDotDotIsRefused with data set "%s"'
e('paket/fetcher-dot-segment', 'get(): no segment .. in the path', F, DOT, "\t\tif ( false ) {", 'address', DOTS % '..')
e('paket/fetcher-dot-encoded', 'get(): no segment written as %2e', F, DOT, DOT.replace("(?:\\.|%2e)", "\\."), 'address', DOTS % '%2e%2e')
e('paket/fetcher-dot-case', 'get(): %2E as %2e', F, DOT, DOT.replace("~i'", "~'"), 'address', DOTS % '%2E')
e('paket/fetcher-dot-one', 'get(): no segment . either', F, DOT, DOT.replace("{1,2}", "{2}"), 'address', DOTS % '.')
e('paket/fetcher-dot-end', 'get(): a segment .. at the end of the path', F, DOT, DOT.replace("(?:/|\\z)", "/"), 'address', DOTS % '.. at the end')
e('paket/fetcher-dot-whole', 'get(): only a whole segment, not a name that begins with dots', F, DOT, DOT.replace("(?:/|\\z)", ""),
  'address', 'testDotsThatAreNoSegmentOfThePathAreAsked')
e('paket/fetcher-dot-query', 'get(): the path ends at ?, the query is no path', F,
  "\t\t$path = substr( $url, 0, strcspn( $url, '?' ) );\n", "\t\t$path = $url;\n", 'address', 'testDotsThatAreNoSegmentOfThePathAreAsked')

# ------------------------------------------------------------- packages (Teil 2)
PK = 'testEachLimitOfTheManifestIsHeldByName with data set "%s"'
for what, line in (
    ('objects', "\t\tif ( count( $objects ) > $max['objects'] ) {"),
    ('unpacked', "\t\tif ( $unpacked > $max['unpacked'] ) {"),
    ('chunks', "\t\tif ( count( $chunks ) > $max['chunks'] ) {"),
    ('bytes', "\t\tif ( $bytes > $max['bytes'] ) {"),
):
    e('paket/pack-limit-' + what, 'pack(): no package past the limit of its ' + what, EX, line, "\t\tif ( false ) {", 'package', PK % what)
LINE = "\t\t\tif ( '' === $line || strlen( $line ) + 1 > $size ) {"
NOCHUNK = 'testAnObjectThatNoChunkHoldsIsRefused with data set "%s"'
e('paket/pack-line-size', 'pack(): a line with its line break within a chunk', EX, LINE, LINE.replace('strlen( $line ) + 1 > $size', 'strlen( $line ) > $size'), 'package', NOCHUNK % 'one byte too large')
e('paket/pack-line-encodable', 'pack(): an object JSON does not write is no line', EX, LINE, LINE.replace("'' === $line || ", ''), 'package', NOCHUNK % 'not encodable')
FIT = "\t\t\tif ( strlen( $slice ) + strlen( $line ) + 1 > $size ) {"
e('paket/pack-slice-fit', 'pack(): a chunk holds as many lines as fit exactly', EX, FIT, FIT.replace(' > $size', ' >= $size'), 'package', 'testEachObjectIsOneLineAndNoLineIsSplitBetweenChunks')
e('paket/pack-default-objects', 'pack(): the limits of the manifest where none is passed', EX,
  "\t\t\t'objects'  => AB_MCP_Sync_Manifest::MAX_OBJECTS,", "\t\t\t'objects'  => AB_MCP_Sync_Manifest::MAX_OBJECTS + 1,", 'package', 'testTheLimitsAreTheManifestsWhereNoneIsPassed')
e('paket/pack-default-slice', 'pack(): SLICE where none is passed', EX,
  "$size = null === $slice ? self::SLICE : (int) $slice;", "$size = null === $slice ? 100 : (int) $slice;", 'package', 'testTheGzipOfTheLargestSliceStaysWithinAChunk')
e('paket/pack-last-chunk', 'pack(): a package has a chunk also without an object', EX,
  "\t\t$chunks[] = $slice;\n\t\tif ( $unpacked", "\t\tif ( $unpacked", 'package', 'testAPackageHasAChunkAlsoWithoutAnObject')
DONE = "\t\t\t\t'complete' => $at >= $start && $at < $end && ! isset( $incomplete[ $key ] ),"
REACH = 'testAKindIsCompleteOnlyWhereThePackageReadItFromItsStartToItsEnd with data set "%s"'
e('paket/kinds-from-start', 'kinds(): complete only for a kind begun at its start', EX, DONE, DONE.replace("$at >= $start && ", ''), 'package', REACH % 'began in the terms')
e('paket/kinds-to-end', 'kinds(): complete only for a kind read to its end', EX, DONE, DONE.replace(" && $at < $end", ''), 'package', REACH % 'ended in the terms')
e('paket/kinds-inner', 'kinds(): a cursor within a kind begins past it', EX,
  " + ( null === $from['inner'] ? 0 : 1 );", " + 0;", 'package', REACH % 'began in the terms')
e('paket/kinds-sorted', 'kinds(): ascending by kind and subtype', EX,
  "\t\t\t\treturn 0 !== $by ? $by : strcmp( $a['subtype'], $b['subtype'] );", "\t\t\t\treturn 0;", 'package', 'testKindsCarryTheirSubtypeAscendingAndOnce')
e('paket/kinds-taxonomy', 'kinds(): the taxonomy of a term is its subtype', EX,
  "( isset( $identity['taxonomy'] ) ? $identity['taxonomy'] : '' )", "''", 'package', 'testKindsCarryTheirSubtypeAscendingAndOnce')
e('paket/grants-no-key', 'grants(): no fetch address without the key', GRANT_F,
  "\t\t\tif ( null === $mac ) {\n\t\t\t\treturn new WP_Error( 'ab_mcp_sync_key_missing'", "\t\t\tif ( false ) {\n\t\t\t\treturn new WP_Error( 'ab_mcp_sync_key_missing'",
  'package', 'testAFetchAddressIsTheMacOfTheKeyOfThisStagingSite')
e('paket/grants-ttl', 'grants(): valid GRANT_TTL seconds', GRANT_F,
  "( null === $now ? time() : (int) $now ) + self::GRANT_TTL;", "( null === $now ? time() : (int) $now ) + 0;", 'package', 'testAFetchAddressIsTheMacOfTheKeyOfThisStagingSite')
GR = "\t\tif ( 'GET' !== $request->get_method() || self::QUERY !== self::sorted_keys( $query ) || array() !== $request->get_body_params() || '' !== (string) $request->get_body() ) {"
DEV = 'testEveryOtherRequestIsNone with data set "%s"'
e('paket/grant-get', 'grant(): GET only', GRANT_F, GR, GR.replace("'GET' !== $request->get_method() || ", ''), 'package', DEV % 'POST')
e('paket/grant-query', 'grant(): the four parameters and no other', GRANT_F, GR, GR.replace("self::QUERY !== self::sorted_keys( $query ) || ", ''), 'package', DEV % 'another parameter')
e('paket/grant-form', 'grant(): no form field', GRANT_F, GR, GR.replace("array() !== $request->get_body_params() || ", ''), 'package', DEV % 'a form field')
e('paket/grant-body', 'grant(): no body', GRANT_F, GR, GR.replace(" || '' !== (string) $request->get_body()", ''), 'package', DEV % 'a body')
e('paket/grant-text', 'grant(): each parameter text', GRANT_F,
  "\t\t\tif ( ! is_string( $value ) ) {\n\t\t\t\treturn null;", "\t\t\tif ( false ) {\n\t\t\t\treturn null;", 'package', DEV % 'a parameter as a list')
e('paket/grant-chunk-spelling', 'grant(): the chunk in its one spelling', GRANT_F,
  "'/^(0|[1-9][0-9]{0,3})\\z/'", "'/^[0-9]{1,4}\\z/'", 'package', DEV % 'the chunk as 02')
e('paket/grant-expires-spelling', 'grant(): until when in its one spelling', GRANT_F,
  "'/^[1-9][0-9]{0,11}\\z/'", "'/^[0-9]{1,12}\\z/'", 'package', DEV % 'until when as 01600')
TIMES = 'testAFetchAddressHoldsTenMinutesFromNowAtMost with data set "%s"'
WIN = "\t\tif ( $expires <= $now || $expires > $now + self::GRANT_TTL ) {"
e('paket/grant-not-after', 'grant(): not at its end or after', GRANT_F, WIN, WIN.replace('$expires <= $now', '$expires < $now'), 'package', TIMES % 'at its end')
e('paket/grant-ttl', 'grant(): valid at most GRANT_TTL from now', GRANT_F, WIN, WIN.replace(' || $expires > $now + self::GRANT_TTL', ''), 'package', TIMES % 'valid too long')
MACQ = "\t\tif ( null === $mac || ! hash_equals( $mac, $query['sig'] ) ) {"
e('paket/grant-mac-none', 'grant(): nothing on a site without the key', GRANT_F, MACQ, MACQ.replace('null === $mac || ', ''), 'package', 'testOnTheProductionSiteNoRequestIsRight')
e('paket/grant-mac', 'grant(): the MAC of this site', GRANT_F, MACQ, MACQ.replace(" || ! hash_equals( $mac, $query['sig'] )", ''), 'package', DEV % 'another signature')
e('paket/serve-request', 'serve(): only a REST request', GRANT_F,
  " || ! $request instanceof WP_REST_Request", "", 'package', 'testEveryOtherRequestAndPathGoesOnToWordPress')
e('paket/relay-https', 'relay_refusal(): over https', EX, "\t\tif ( is_ssl() ) {", "\t\tif ( false ) {", 'package', 'testTheRelayNeedsHttpsWhereTheSiteHasAPublicAddress')
e('paket/relay-local', 'relay_refusal(): a site without a public address', EX,
  "if ( 'local' === AB_MCP_Outbound::public_addresses(", "if ( 'none' === AB_MCP_Outbound::public_addresses(", 'package', 'testTheRelayNeedsHttpsWhereTheSiteHasAPublicAddress')
e('paket/export-chunk-relay', 'chunk(): no chunk over http where the site is public', EX,
  "\t\t$relay = self::relay_refusal();\n\t\tif ( null !== $relay ) {\n\t\t\treturn $relay;\n\t\t}\n", "", 'package', 'testTheStagingSiteGivesNoChunkOverHttpWhereItIsPublic')
REFUSE = 'testOnlyAConfirmedStagingSiteWithAPairAndAKeyMakesOrGivesOutAPackage'
for name, search in (
    ('network', "\t\tif ( is_multisite() ) {\n\t\t\treturn self::multisite();"),
    ('role', "\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_manifest_role', __( 'Only the confirmed staging site makes"),
    ('pair', "\t\tif ( null === AB_MCP_Staging::record()['pair'] ) {"),
    ('key', "\t\tif ( null === AB_MCP_Sync_Key::current() ) {"),
):
    e('paket/export-refuse-' + name, 'refusal(): no package ' + {'network': 'on a network', 'role': 'but on the confirmed staging site', 'pair': 'without a pair', 'key': 'without a key'}[name], EX,
      search, search.replace(search.split('\n')[0], "\t\tif ( false ) {"), 'package', REFUSE)
e('paket/export-purpose', 'make(): the inventory, the environment and objects only, in this version (media: the takeover of attachments)', EX,
  "\t\tif ( ! in_array( $purpose, self::PURPOSES, true ) ) {", "\t\tif ( false ) {", 'package', 'testThisVersionMakesPackagesOfTheInventoryTheEnvironmentAndObjectsOnly')
CUR = "\t\tif ( ! is_array( $cursor ) || array( 'inner', 'kind', 'package' ) !== self::sorted_keys( $cursor ) || ! is_string( $cursor['kind'] ) ) {"
CURS = 'testACursorIsOneAnAnswerGave with data set "%s"'
e('paket/export-cursor-array', 'follows(): a cursor is an array', EX, CUR, CUR.replace('! is_array( $cursor ) || ', ''), 'package', CURS % 'text')
e('paket/export-cursor-keys', 'follows(): a cursor has kind, inner and the package before, nothing else', EX, CUR, CUR.replace("array( 'inner', 'kind', 'package' ) !== self::sorted_keys( $cursor ) || ", ''), 'package', CURS % 'no package')
e('paket/export-cursor-kind', 'follows(): the kind of a cursor is text (no warning for a list)', EX, CUR, CUR.replace(" || ! is_string( $cursor['kind'] )", ''), 'package', CURS % 'a kind that is a list')
IMP = 'testTheProductionSiteTakesAPackageIdAndEitherAChunkOrFetchAddresses'
e('paket/import-network', 'call(): no package on a network', IM, "\t\tif ( is_multisite() ) {\n\t\t\treturn AB_MCP_Sync_Export::multisite();", "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Export::multisite();", 'package', IMP)
e('paket/import-package-id', 'call(): the package id as wp_site_export gave it', IM,
  "\t\tif ( ! is_string( $package_id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $package_id ) ) {", "\t\tif ( false ) {", 'package', IMP)
e('paket/import-both', 'call(): a chunk or fetch addresses, not both', IM, "\t\tif ( $has_data && $has_fetch ) {", "\t\tif ( false ) {", 'package', IMP)
e('paket/import-relay', 'call(): no relayed chunk over http where the site is public', IM,
  "$relay = $has_data ? AB_MCP_Sync_Export::relay_refusal() : null;", "$relay = null;", 'package', 'testTheProductionSiteTakesNoRelayedChunkOverHttpWhereItIsPublic')
FL = 'testFetchIsAListOfFetchAddressesAsTheStagingSiteGivesThem with data set "%s"'
e('paket/import-fetch-shape', 'call(): fetch as wp_site_export gives it, before anything is read', IM,
  "\t\tif ( $has_fetch && ! self::grants_shaped( $a['fetch'] ) ) {", "\t\tif ( false ) {", 'package', FL % 'an empty list')
GL = "\t\tif ( ! is_array( $grants ) || ! array_is_list( $grants ) || array() === $grants ) {"
e('paket/grants-shape-array', 'grants_shaped(): a list', IM, GL, GL.replace('! is_array( $grants ) || ', ''), 'package', FL % 'text')
e('paket/grants-shape-list', 'grants_shaped(): a list, not a map', IM, GL, GL.replace('! array_is_list( $grants ) || ', ''), 'package', FL % 'not a list')
e('paket/grants-shape-empty', 'grants_shaped(): not empty', IM, GL, GL.replace(' || array() === $grants', ''), 'package', FL % 'an empty list')
GI = "\t\t\tif ( ! is_array( $grant ) || array( 'chunk', 'expires', 'signature' ) !== self::sorted_keys( $grant ) || ! is_int( $grant['chunk'] ) || ! is_int( $grant['expires'] ) || ! is_string( $grant['signature'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $grant['signature'] ) ) {"
for name, part, case in (
    ('item', '! is_array( $grant ) || ', 'an item that is text'),
    ('keys', "array( 'chunk', 'expires', 'signature' ) !== self::sorted_keys( $grant ) || ", 'a key more'),
    ('chunk', "! is_int( $grant['chunk'] ) || ", 'the chunk as text'),
    ('expires', "! is_int( $grant['expires'] ) || ", 'until when as text'),
    ('signature-text', "! is_string( $grant['signature'] ) || ", 'the signature as a list'),
    ('signature-hex', " || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $grant['signature'] )", 'the signature with &'),
):
    e('paket/grants-shape-' + name, 'grants_shaped(): each fetch address as wp_site_export gives it (' + name + ')', IM, GI, GI.replace(part, ''), 'package', FL % case)
e('paket/import-url-form', 'url(): the stored address in the form the anchor compares', IM,
  "return AB_MCP_Sync_Key::site_form( $partner ) . '/?rest_route=/'", "return $partner . '/?rest_route=/'", 'package', 'testTheProductionSiteBuildsTheAddressFromTheStoredAddressOfItsStagingSite')
TOOLS = 'testTheToolsHandTheirArgumentsOn'
e('paket/tool-export-again', 'wp_site_export: with package_id the package made before', TS,
  "\t\tif ( array_key_exists( 'package_id', $a ) ) {\n\t\t\treturn AB_MCP_Sync_Export::again( $a['package_id'] );", "\t\tif ( false ) {\n\t\t\treturn AB_MCP_Sync_Export::again( $a['package_id'] );", 'package', TOOLS)
e('paket/tool-export-cursor', 'wp_site_export: the cursor passed on', TS,
  "isset( $a['cursor'] ) ? $a['cursor'] : null", "null", 'package', TOOLS)
MACK = "\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() || ! is_string( $context ) || 1 !== preg_match( '~^[a-z0-9/-]{1,64}\\z~', $context ) ) {"
MAC = 'testTheMacOfAStagingSiteIsItsOwnAndPerPurpose'
e('paket/mac-role', 'mac(): only a confirmed staging site', K, MACK, MACK.replace('AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() || ', ''), 'package', MAC)
e('paket/mac-context', 'mac(): a purpose of a-z, 0-9, - and /, up to 64', K, MACK, MACK.replace('{1,64}', '{0,65}'), 'package', MAC)
e('paket/mac-key', 'mac(): not without the secret of the MACs', K,
  "\t\tif ( null === $secret ) {\n\t\t\treturn null;\n\t\t}\n\t\treturn hash_hmac(", "\t\treturn hash_hmac(", 'package', MAC)
e('paket/mac-purpose', 'mac(): the purpose in what it signs', K,
  "'ab-sync-mac/1' . \"\\n\" . $context . \"\\n\"", "'ab-sync-mac/1' . \"\\n\" . 'x' . \"\\n\"", 'package', MAC)
INV = 'testASourceMayBoundTheObjectsOfAnInventory'
BOUND = "\t\tif ( isset( $source['objects'] ) && self::$made >= (int) $source['objects'] ) {"
e('paket/inventory-bound', 'out_of_time(): the bound of objects reached', INV_F, BOUND, BOUND.replace('>=', '>'), 'dbkinds', INV)
e('paket/inventory-bound-on', 'out_of_time(): a source that bounds its objects', INV_F, BOUND, "\t\tif ( false ) {", 'dbkinds', INV)
e('paket/inventory-count', 'entry(): every object counts', INV_F, "\t\t++self::$made;\n", "", 'dbkinds', INV)
e('paket/inventory-count-anew', 'source(): a new source counts anew', INV_F, "\t\tself::$made  = 0;\n", "", 'dbkinds', INV)
e('paket/inventory-at-least-one', 'source(): a bound of at least one object', INV_F, "max( 1, (int) $objects )", "(int) $objects", 'dbkinds', INV)
e('paket/gate-carry-over', 'CARRY_OVER: the packages are the carrying over', G,
  "'wp_site_export', 'wp_site_export_chunk', 'wp_site_import_chunk', 'wp_site_pairs' );", "'wp_site_export', 'wp_site_export_chunk', 'wp_site_pairs' );", 'gate', 'testTheExemptListHasItsThreeGroupsAndTheFilterOnlyAdds')

# ----------------------------------------------------------- integration
e('paket/it-renews', 'save(): the staging site confirmed by a person has a key (two sites)', S,
  "\t\t\t$key = AB_MCP_Sync_Key::renew();", "\t\t\t$key = array();", 'it', 'testTheStagingSiteGivesItsPublicKeyOverHttpAndKeepsThePrivateOne')
e('paket/it-store-quiet', 'store_secret(): MariaDB\'s refusal of the private key is not logged (two sites)', K,
  "$quiet   = null !== $db ? $db->suppress_errors( true ) : false;", "$quiet   = null !== $db ? $db->suppress_errors( false ) : false;", 'it', 'testAWriteOfThePrivateKeyMariaDBRefusesLeavesItInNoReport')
e('paket/it-store-ezsql', 'store_secret(): $EZSQL_ERROR, which outlives the query, keeps no error of the private key (two sites)', K,
  "\t\t\t\tarray_splice( $GLOBALS['EZSQL_ERROR'], $errors );", "", 'it', 'testAWriteOfThePrivateKeyMariaDBRefusesLeavesItInNoReport')
e('paket/it-renew-forget', 'renew(): refused writes leave no key (two sites)', K,
  "\t\t\tself::forget();\n\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_key_failed',",
  "\t\t\treturn new WP_Error(\n\t\t\t\t'ab_mcp_sync_key_failed',", 'it', 'testARenewalWhoseWritesAreRefusedConfirmsNothingAndLeavesNoKey')
e('paket/it-port', 'get_partner(): WordPress 7.1.3 asks the port of the stored address (two sites)', O,
  "\t\tadd_filter( 'http_allowed_safe_ports', $allow, 10, 3 );", "", 'it', 'testWordPressAsksThePortOfTheStoredStagingSiteForThatRequestOnly')
e('paket/it-https-only', 'prepare_from_partner(): the http:// integration site is never asked', A,
  "\t\tif ( 0 !== strpos( $partner, 'https://' ) ) {", "\t\tif ( false ) {", 'it', 'testTheProductionSiteReadsTheRouteAndTheCodeButAsksOnlyOverHttps')
e('paket/it-confirmed-anchor', 'verify(): nothing read with a prepared key (two sites)', M,
  "\t\t\tif ( null !== AB_MCP_Sync_Anchor::prepared() ) {", "\t\t\tif ( false ) {", 'it', 'testAManifestOfTheStagingSiteIsReadOnlyWithTheKeyAPersonConfirmed')
e('paket/it-target', 'verify(): another path, port or scheme of the production site (two sites)', M,
  TARGET, "\t\tif ( false ) {", 'it', 'testAManifestForAnotherAddressOfTheProductionSiteIsRefused')
e('paket/it-key-id', 'verify(): the key of an older confirmation (two sites)', M, KEYS, "\t\tif ( false ) {", 'it', 'testAnotherConfirmationOfTheStagingRoleNeedsTheNewKeyConfirmed')

PKG_IT = {
    'export': 'testTheStagingSiteMakesAPackageOfItsInventoryInItsProtectedStore',
    'cursor': 'testTheInventoryInASeriesOfPackagesHoldsEachObjectOnceAndAKindWholeWhereItIs',
    'route': 'testTheRouteAnswersEveryOtherRequestAsARouteWordPressDoesNotKnow',
    'fetch': 'testTheProductionSiteFetchesThePackageItselfAndTakesItUpAgain',
    'manifest': 'testTheProductionSiteChecksTheManifestOnEveryCall',
    'relay': 'testTheRelayCarriesAChunkFromTheStagingSiteToTheProductionSite',
    'whole': 'testAPackageIsCheckedWholeOnceEveryChunkIsThere',
    'own': 'testAPackageIsGivenOutToItsAccountAndRemovedAfterADay',
    'time': 'testACallFetchesAsFarAsItsTimeReaches',
    'serve': 'testAStagingSiteAnswersOnlyTheRequestOfItsRouteWithTheBytesOfTheChunk',
}
e('paket/it-route-hook', 'the route of the chunks is answered (two sites)', RC,
  "\t\tadd_filter( 'rest_pre_dispatch', array( $this, 'serve_package' ), 10, 3 );", "", 'itpkg', PKG_IT['route'])
SERVE = "\t\tif ( ! empty( $result ) || ! $request instanceof WP_REST_Request || '/' . AB_MCP_REST_NAMESPACE . self::ROUTE !== $request->get_route() ) {"
e('paket/it-serve-other-filter', 'serve(): what another filter answered stays', GRANT_F, SERVE, SERVE.replace('! empty( $result ) || ', ''), 'itpkg', PKG_IT['serve'])
e('paket/it-serve-path', 'serve(): only the path of the route', GRANT_F, SERVE, SERVE.replace(" || '/' . AB_MCP_REST_NAMESPACE . self::ROUTE !== $request->get_route()", ''), 'itpkg', PKG_IT['serve'])
SEND = "\t\tif ( $served || null === self::$serving || self::$serving[0] !== $result ) {"
e('paket/it-send-own', 'send(): only the answer serve() accepted', GRANT_F, SEND, SEND.replace(' || self::$serving[0] !== $result', ''), 'itpkg', PKG_IT['serve'])
e('paket/it-send-once', 'send(): once', GRANT_F, "\t\tself::$serving = null;\n\t\techo $bytes;", "\t\techo $bytes;", 'itpkg', PKG_IT['serve'])
e('paket/it-requested-expired', 'deliver(): nothing of a package whose manifest expired', EX,
  "$found['manifest']['expires'] <= $now || ", "", 'itpkg', PKG_IT['serve'])
e('paket/it-find-record', 'find(): a package without its record is none', EX,
  "\t\tif ( array() === $records ) {\n\t\t\treturn null;\n\t\t}\n\t\t$name = reset( $records );\n\t\t$text = AB_MCP_Protected_Store::get( 'out',",
  "\t\t$name = reset( $records );\n\t\t$text = AB_MCP_Protected_Store::get( 'out',", 'itpkg', PKG_IT['serve'])
OWN = "\t\tif ( null === $found || (int) $found['user_id'] !== (int) get_current_user_id() || $found['manifest']['expires'] <= ( null === $now ? time() : (int) $now ) ) {"
e('paket/it-own-account', 'own(): a package of this account', EX, OWN, OWN.replace("(int) $found['user_id'] !== (int) get_current_user_id() || ", ''), 'itpkg', PKG_IT['own'])
e('paket/it-own-expires', 'own(): not at the end of its manifest or after', EX, OWN, OWN.replace("$found['manifest']['expires'] <= (", "$found['manifest']['expires'] < ("), 'itpkg', PKG_IT['own'])
e('paket/it-chunk-index', 'chunk(): a chunk the manifest lists', EX,
  "\t\tif ( ! is_int( $chunk ) || ! isset( $found['manifest']['chunks'][ $chunk ] ) ) {", "\t\tif ( ! is_int( $chunk ) ) {", 'itpkg', PKG_IT['relay'])
e('paket/it-export-cursor', 'make(): the inventory from the cursor', EX,
  "$read = AB_MCP_Sync_Inventory::run( $source, $from );", "$read = AB_MCP_Sync_Inventory::run( $source, null );", 'itpkg', PKG_IT['cursor'])
e('paket/it-export-kinds-from', 'make(): complete by the cursor the package began with', EX,
  "$kinds  = self::kinds( $read['objects'], $from, $read['cursor'], $sorted['incomplete'] );", "$kinds  = self::kinds( $read['objects'], null, $read['cursor'], $sorted['incomplete'] );", 'itpkg', PKG_IT['cursor'])
e('paket/it-import-label', 'begin(): the group named by the package', IM,
  "\t\t\t\t'label'    => self::LABEL . $manifest['package_id'],", "\t\t\t\t'label'    => 'other',", 'itpkg', PKG_IT['fetch'])
e('paket/it-import-reverify', 'held(): the kept manifest checked again on every call', IM,
  "\t\t$checked = AB_MCP_Sync_Manifest::verify( $found['signed'], $expect );\n\t\tif ( is_wp_error( $checked ) ) {\n\t\t\treturn $checked;\n\t\t}\n\t\treturn $found;",
  "\t\treturn $found;", 'itpkg', PKG_IT['manifest'])
e('paket/it-import-other-manifest', 'held(): a later manifest is the kept one', IM,
  "\t\tif ( array_key_exists( 'manifest', $a ) && ( ! is_array( $a['manifest'] )", "\t\tif ( false && ( ! is_array( $a['manifest'] )", 'itpkg', PKG_IT['manifest'])
e('paket/it-import-unknown', 'held(): the first call brings the manifest', IM,
  "\t\t\tif ( null === $expect ) {", "\t\t\tif ( false ) {", 'itpkg', PKG_IT['manifest'])
e('paket/it-fetch-skip', 'fetched(): a chunk that is there is not asked again', IM,
  "\t\t\tif ( isset( $have[ AB_MCP_Sync_Export::chunk_name( $i ) ] ) ) {\n\t\t\t\tcontinue;\n\t\t\t}\n", "", 'itpkg', PKG_IT['fetch'])
e('paket/it-fetch-deadline', 'fetched(): as far as BUDGET reaches', IM,
  "\t\t\tif ( $left < self::MIN_FETCH ) {\n\t\t\t\tbreak;\n\t\t\t}\n", "", 'itpkg', PKG_IT['time'])
e('paket/it-fetch-range', 'fetched(): a chunk of the package', IM,
  "\t\t\tif ( ! isset( $held['manifest']['chunks'][ $grant['chunk'] ] ) ) {\n\t\t\t\treturn self::grants_refusal();", "\t\t\tif ( false ) {\n\t\t\t\treturn self::grants_refusal();", 'itpkg', PKG_IT['fetch'])
e('paket/it-fetch-status', 'arrived(): only an answer 200', IM, "\t\tif ( 200 !== $status ) {", "\t\tif ( false ) {", 'itpkg', PKG_IT['fetch'])
e('paket/it-fetch-limit', 'fetched(): no more than the chunk', IM,
  "'limit_response_size' => $bytes + 1,", "'limit_response_size' => $bytes + 2,", 'itpkg', PKG_IT['fetch'])
e('paket/it-keep-exists', 'keep(): a chunk that is there stays, no refusal', IM,
  " && 'ab_mcp_store_exists' !== $put->get_error_code()", "", 'itpkg', PKG_IT['relay'])
e('paket/it-state-whole', 'state(): the whole against its hash', IM,
  "\t\t\tif ( is_wp_error( $ok ) ) {\n\t\t\t\treturn $ok;\n\t\t\t}\n\t\t\t// Unpacked within",
  "\t\t\t// Unpacked within", 'itpkg', PKG_IT['whole'])
e('paket/it-state-mark', 'state(): a package checked whole is marked complete', IM,
  "\t\t\t$put = AB_MCP_Protected_Store::put( 'in', $held['gid'], self::COMPLETE, $held['manifest']['sha256'] );", "\t\t\t$put = true;", 'itpkg', PKG_IT['fetch'])
e('paket/it-state-failed', 'state(): failed only where a fetch failed', IM, "\t\tif ( array() !== $failed ) {", "\t\tif ( true ) {", 'itpkg', PKG_IT['fetch'])
RD = "\t\tif ( '' === $text || base64_encode( (string) $data ) !== $text ) {"
e('paket/it-relay-empty', 'relayed(): a chunk has a byte at least', IM, RD, RD.replace("'' === $text || ", ''), 'itpkg', PKG_IT['relay'])
e('paket/it-relay-spelling', 'relayed(): base64 in its one spelling', IM, RD, RD.replace(" || base64_encode( (string) $data ) !== $text", ''), 'itpkg', PKG_IT['relay'])

# ------------------------------------------------------- Codex round 4 (Teil 2)
PKG_IT.update({
    'gegen': 'testNoValueOfTheContentLeavesTheStagingSite',
    'binding_http': 'testAPackageIsGivenOutOnlyAsLongAsThePairAndTheKeyHold',
    'binding': 'testABindingThatChangedWithoutANewKeyIsNoBindingEither',
    'account': 'testAnImportBelongsToTheAccountThatBeganIt',
    'budget': 'testAFetchTakesNoLongerThanWhatIsLeftOfTheBudget',
    'whole_write': 'testAPackageThatCannotBeWrittenWholeLeavesNothingHalfMade',
    'import_write': 'testAnImportKeepsWhatArrivedAndEndsWhatAnInterruptionLeft',
})
LH = "\t\tif ( null === $status || 'b9' === $status || array() !== $credentials['mixed'] || self::credential_locked( $object['locks'] ) ) {"
HELD = 'testAnObjectACredentialHoldsCarriesItsIdentityAndTheReasonOnly with data set "%s"'
LINE = 'testALineCarriesWhatTheInventoryNeedsAndNoValueOfTheContent'
e('paket/line-held-name', 'line(): an object whose name is a credential: identity and reason only', EX, LH, LH.replace("'b9' === $status || ", ''), 'package', HELD % 'the name of a credential')
e('paket/line-held-field', 'line(): an object with a field of a credential: identity and reason only', EX, LH, LH.replace(" || array() !== $credentials['mixed']", ''), 'package', HELD % 'a field of a credential in it')
e('paket/line-possible', 'line(): a possible reference by its place, never its value', EX,
  "'possible'        => self::fields( $object['possible'], array( 'at' ) ),", "'possible'        => $object['possible'],", 'package', LINE)
e('paket/line-refs', 'line(): references by id and place only', EX,
  "'refs'            => self::fields( $object['refs'], array( 'at', 'id', 'login', 'space', 'strength' ) ),", "'refs'            => $object['refs'],", 'package', LINE)
e('paket/line-locks', 'line(): locks by key, stage, reason and place only', EX,
  "'locks'    => self::fields( $object['locks'], array( 'at', 'key', 'reason', 'stage' ) ),", "'locks'    => $object['locks'],", 'package', LINE)
e('paket/line-reason', 'line(): no free text of the object, the reasons are those of its locks', EX,
  "\t\t\t'locks'    => self::fields( $object['locks'], array( 'at', 'key', 'reason', 'stage' ) ),\n\t\t) + array_intersect_key( $object, array_flip( array( 'reason_code', 'target' ) ) );", "\t\t\t'locks'    => self::fields( $object['locks'], array( 'at', 'key', 'reason', 'stage' ) ),\n\t\t) + array_intersect_key( $object, array_flip( array( 'reason', 'reason_code', 'target' ) ) );", 'package', LINE)
e('paket/it-export-lines', 'make(): the package holds lines, not the objects of the inventory (two sites)', EX,
  "$lines = array_map( array( __CLASS__, 'line' ), $sorted['packed'] );", "$lines = $sorted['packed'];", 'itpkg', PKG_IT['gegen'])
MS = "\t\tif ( ! is_array( $raw ) || ! isset( $raw['key_id'], $raw['mac'] ) || ! is_string( $raw['mac'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $raw['mac'] ) ) {"
MACC = 'testTheMacOfAStagingSiteIsItsOwnAndPerPurpose'
e('paket/mac-secret-text', 'mac_secret(): the secret as text', K, MS, MS.replace(" || ! is_string( $raw['mac'] )", ''), 'package', MACC)
e('paket/mac-secret-hex', 'mac_secret(): the secret as 64 hex', K, MS, MS.replace(" || 1 !== preg_match( '/^[0-9a-f]{64}\\z/', $raw['mac'] )", ''), 'package', MACC)
e('paket/mac-secret-key', 'mac_secret(): the secret of the key stored now', K,
  " || $raw['key_id'] !== $source['key_id']", "", 'package', MACC)
e('paket/key-forget-mac', 'forget(): the secret of the MACs goes with the key', K, "\t\tdelete_option( self::OPTION_MAC );\n", "", 'package', MACC)
e('paket/key-renew-mac', 'renew(): the secret of the MACs reads back, for the key id stored now (so the key read is the one made)', K, RB, RB.replace(" || self::mac_secret() !== hex2bin( $mac )", ''),
  'storage', STORAGE_RB % 'the old key under the new source')
e('paket/key-store-mac', 'renew(): the secret of the MACs is written, as the private key is', K,
  "\t\tself::store_secret(\n\t\t\tself::OPTION_MAC,\n\t\t\tarray(\n\t\t\t\t'key_id' => $key_id,", "\t\tarray(\n\t\t\tself::OPTION_MAC,\n\t\t\tarray(\n\t\t\t\t'key_id' => $key_id,", 'package', MACC)
BIND = 'testAPackageIsBoundToTheKeyThePairAndTheirConfirmations'
for name, line in (
    ('key', "\t\t\t\t\t'key'    => array( $key['source_id'], $key['key_id'] ),\n"),
    ('source', "\t\t\t\t\t'source' => AB_MCP_Sync_Key::site_form( home_url() ),\n"),
    ('role', "\t\t\t\t\t'role'   => array( $role['value'], $role['user_id'], $role['source'], $role['at'] ),\n"),
    ('pair', "\t\t\t\t\t'pair'   => array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['source'], $pair['at'] ),\n"),
):
    e('paket/binding-' + name, 'binding(): the ' + name + ' is in it', EX, line, "", 'package', BIND)
BN = "\t\tif ( null === $key || null === $record['pair'] || null === $bind ) {"
e('paket/binding-no-key', 'binding(): none without a key', EX, BN, BN.replace('null === $key || ', ''), 'package', BIND)
e('paket/binding-no-pair', 'binding(): none without a pair', EX, BN, BN.replace(" || null === $record['pair']", ''), 'package', BIND)
e('paket/import-purpose-inventory', 'expect(): only the purposes this version takes (AB_MCP_Sync_Export::PURPOSES), not any text (since branch 10 they are every purpose a manifest names)', IM,
  "\t\tif ( ! in_array( $purpose, AB_MCP_Sync_Export::PURPOSES, true ) ) {", "\t\tif ( ! is_string( $purpose ) ) {", 'package', 'testTheProductionSiteTakesPackagesOfTheInventoryTheEnvironmentAndObjectsOnly')
e('paket/import-expect-first', 'call(): the purpose checked before anything is read', IM,
  "\t\tif ( array_key_exists( 'manifest', $a ) ) {\n\t\t\t$expect = self::expect(", "\t\tif ( false ) {\n\t\t\t$expect = self::expect(", 'package', 'testTheProductionSiteTakesPackagesOfTheInventoryTheEnvironmentAndObjectsOnly')
BU = 'StoreBudget'
SERIES_T = 'testThePackagesOfASeriesCountAsOneExportAndOnePackage'
CLOSED_T = 'testAClosedPackageCountsAsOpenNoMoreButItsBytesDo'
e('paket/budget-series', 'package_refusal(): the packages of a series count once', BUD,
  "$key          = 1 === preg_match( self::SERIES, $entry['label'] ) ? $entry['label'] : $i;", "$key          = $i;", 'budget', SERIES_T)
e('paket/budget-closed', 'package_refusal(): a closed package counts as open no more', BUD,
  "\t\t\tif ( $entry['closed'] ) {\n\t\t\t\tcontinue;\n\t\t\t}\n", "", 'budget', CLOSED_T)
e('paket/budget-closed-bytes', 'package_refusal(): the bytes of a closed package count', BUD,
  "\t\t\t$bytes += $entry['declared'];\n\t\t\tif ( $entry['closed'] ) {", "\t\t\tif ( $entry['closed'] ) {", 'budget', CLOSED_T)
e('paket/budget-join-export', 'package_refusal(): the next package of an open series is no new export', BUD,
  "$exports = $joins && isset( $exports[ $label ] ) ? 0 : count( $exports );", "$exports = count( $exports );", 'budget', SERIES_T)
e('paket/budget-join-open', 'package_refusal(): the next package of an open series is no new package', BUD,
  "$open    = $joins && isset( $open[ $label ] ) ? 0 : count( $open );", "$open    = count( $open );", 'budget', SERIES_T)
e('paket/it-store-close', 'close(): the group closed (two sites)', ST, "\t\t\t\t$entry['closed'] = true;", "\t\t\t\t$entry['closed'] = false;", 'itpkg', PKG_IT['import_write'])
e('paket/it-store-read-closed', 'read_entry(): closed as written (two sites; sidecar(), Behebung 2 of Z8b branch 3: the fields as the file holds them)', ST,
  "\t\treturn array( $area === $e['area'] ? 'own' : 'other', array_intersect_key( $e, self::SIDECAR[ $e['area'] ] ) );", "\t\treturn array( $area === $e['area'] ? 'own' : 'other', array( 'closed' => false ) + array_intersect_key( $e, self::SIDECAR[ $e['area'] ] ) );", 'itpkg', PKG_IT['import_write'])
e('paket/it-store-open-label', 'open(): the budget sees the label (two sites)', ST,
  "$entry['declared'], $entry['label'], $entry['binding'] );", "$entry['declared'], '', $entry['binding'] );", 'itpkg', PKG_IT['cursor'])
e('paket/it-import-close', 'state(): a package checked whole is closed (two sites)', IM,
  "\t\t\t$closed = AB_MCP_Protected_Store::close( 'in', $held['gid'] );", "\t\t\t$closed = true;", 'itpkg', PKG_IT['import_write'])
e('paket/it-series-close', 'state(): the production site takes more packages one after the other than it keeps open (two sites)', IM,
  "\t\t\t$closed = AB_MCP_Protected_Store::close( 'in', $held['gid'] );", "\t\t\t$closed = true;", 'itseries', 'testTheProductionSiteTakesASeriesOfMorePackagesThanItKeepsOpen')
e('paket/it-own-stale', 'own(): no package of another binding (two sites)', EX,
  "\t\tif ( ! self::bound( $found ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_stale'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_stale'", 'itpkg', PKG_IT['binding_http'])
e('paket/it-requested-bound', 'requested(): nothing of a package of another binding (two sites)', EX, "! self::bound( $found ) || ", "", 'itpkg', PKG_IT['binding'])
e('paket/it-bound-discard', 'bound(): a package of another binding is discarded (two sites)', EX,
  "\t\tAB_MCP_Protected_Store::remove( 'out', $found['manifest']['package_id'] );\n\t\treturn false;", "\t\treturn false;", 'itpkg', PKG_IT['binding'])
e('paket/it-discard-stale', 'store(): the packages of another binding go before a new one is made (two sites)', EX, "\t\tself::discard_stale();\n", "", 'itpkg', PKG_IT['binding'])
e('paket/it-follows-cursor', 'follows(): the cursor the package before recorded (two sites)', EX,
  "\t\tif ( AB_MCP_Sync_Canon::encode( $before['cursor'] ) !== AB_MCP_Sync_Canon::encode( $from ) ) {", "\t\tif ( false ) {", 'itpkg', PKG_IT['cursor'])
e('paket/it-follows-series', 'follows(): the series of the package before (two sites)', EX,
  "return array( $before['cursor'], $before['series'], $before['index'] );", "return array( $before['cursor'], bin2hex( random_bytes( 16 ) ), $before['index'] );", 'itpkg', PKG_IT['cursor'])
e('paket/it-answer-package', 'answer(): the cursor names this package (two sites)', EX,
  "\t\t\t\t'package' => $manifest['package_id'],", "\t\t\t\t'package' => str_repeat( 'f', 32 ),", 'itpkg', PKG_IT['cursor'])
e('paket/it-store-series-label', 'store(): the group named by its series (two sites)', EX,
  "\t\t\t\t'label'    => 'series:' . $series,", "\t\t\t\t'label'    => $purpose,", 'itpkg', PKG_IT['cursor'])
e('paket/it-store-chunk-cleanup', 'store(): a chunk that could not be written leaves nothing (two sites)', EX,
  "self::chunk_name( $i ), $data );\n\t\t\tif ( is_wp_error( $put ) ) {\n\t\t\t\tAB_MCP_Protected_Store::remove( 'out', $gid );\n\t\t\t\treturn $put;", "self::chunk_name( $i ), $data );\n\t\t\tif ( is_wp_error( $put ) ) {\n\t\t\t\treturn $put;", 'itpkg', PKG_IT['whole_write'])
e('paket/it-store-record-cleanup', 'store(): a record that could not be written leaves nothing (two sites)', EX,
  "\t\tif ( is_wp_error( $put ) ) {\n\t\t\tAB_MCP_Protected_Store::remove( 'out', $gid );\n\t\t\treturn $put;\n\t\t}\n\t\treturn self::answer(", "\t\tif ( is_wp_error( $put ) ) {\n\t\t\treturn $put;\n\t\t}\n\t\treturn self::answer(", 'itpkg', PKG_IT['whole_write'])
e('paket/it-begin-cleanup', 'begin(): a record that could not be written leaves nothing (two sites)', IM,
  "\t\t\tAB_MCP_Protected_Store::remove( 'in', $gid );\n\t\t\treturn $put;", "\t\t\treturn $put;", 'itpkg', PKG_IT['import_write'])
e('paket/it-import-account', 'find(): the package of this account only (two sites)', IM,
  " || (int) $group['user_id'] !== (int) get_current_user_id()", "", 'itpkg', PKG_IT['account'])
# Codex, round 1 of branch 7, A2: a package belongs to the connection that began it.
CONN = 'testAnImportBelongsToTheConnectionThatBeganIt'
e('paket/z7-import-connection-find', 'find(): the package of this connection only (two sites)', IM,
  "\t\t\tif ( null !== $found && self::connected( $found ) ) {", "\t\t\tif ( null !== $found ) {", 'itpkg', CONN)
e('paket/z7-import-connection-kept', 'begin(): the connection kept with the record (two sites)', IM,
  "\t\t\t\t'connection' => self::connection(),\n\t\t\t\t'expect'     => $expect,", "\t\t\t\t'connection' => hash( 'sha256', 'none' ),\n\t\t\t\t'expect'     => $expect,", 'itpkg', CONN)
e('paket/z7-import-connection-id', 'connection(): the token, not its kind only (two sites)', IM,
  "\t\treturn hash( 'sha256', 'ab-sync-import|' . $conn['kind'] . ':' . $conn['id'] );", "\t\treturn hash( 'sha256', 'ab-sync-import|' . $conn['kind'] );", 'itpkg', CONN)
e('paket/z7-import-connection-none', 'connected(): a record without a connection is of none (two sites)', IM,
  "\t\treturn null !== $found['connection'] && hash_equals( $found['connection'], self::connection() );", "\t\treturn null === $found['connection'] || hash_equals( $found['connection'], self::connection() );", 'itpkg', CONN)
e('paket/it-fetch-timeout', 'fetched(): the timeout within what is left of the budget (two sites)', IM,
  "'timeout'             => min( (float) self::TIMEOUT, $left ),", "'timeout'             => (float) self::TIMEOUT,", 'itpkg', PKG_IT['budget'])
e('paket/it-fetch-min', 'fetched(): no fetch with less than MIN_FETCH left (two sites)', IM,
  "\t\t\tif ( $left < self::MIN_FETCH ) {", "\t\t\tif ( $left < 0 ) {", 'itpkg', PKG_IT['budget'])
e('paket/it-state-files', 'state(): a state that cannot be read is no state (two sites)', IM,
  "\t\t$have = AB_MCP_Protected_Store::files( 'in', $held['gid'] );\n\t\tif ( is_wp_error( $have ) ) {\n\t\t\treturn $have;\n\t\t}\n\t\t$missing", "\t\t$have = AB_MCP_Protected_Store::files( 'in', $held['gid'] );\n\t\t$missing", 'itpkg', PKG_IT['import_write'])

# ------------------------------------------------------ Codex round 5
R5 = {
    'mac_first': 'testTheRouteChecksTheMacBeforeItLoadsTheExport',
    'it_script': 'testAnObjectAnyLockOfTheCredentialCheckHoldsCarriesItsIdentityAndTheReasonOnly',
    'locks_list': 'testTheLocksOfTheCredentialCheckAreTheOnesItGives',
    'bind_unit': 'testAPairThatGoesAndComesBackWithinASecondIsAnotherBinding',
    'bind_it': 'testAPairThatGoesAndComesBackGivesNoPackageOfBeforeOut',
    'bind_write': 'testEveryWriteOfTheRoleOrThePairRenewsTheValueAPackageBindsTo',
    'bind_read': 'testABindOnlyReadsAsItIsWritten',
    'mac': 'testAKeyWithoutTheSecretOfItsMacsGetsOneAndIsNotStuck',
    'idem': 'testConfirmingTheActiveKeyOnceMoreChangesNothing',
    'retired': 'testAKeyReplacedOrRevokedHereIsNeverConfirmedAgain',
    'retired_prepared': 'testAPreparedKeyRetiredSinceIsNotConfirmed',
    'retired_fifty': 'testTheRetiredKeyIdsAreTheLastFiftyAndOnlyAsWritten',
    'retire_failed': 'testANewKeyIsNotConfirmedWhereTheOldOneCannotBeKeptAsReplaced',
    'first_flow': 'testTheFirstImportWaitsForThePersonAndThenRunsAsItWas',
    'print_it': 'testAnImportEndsWhereThePairChangedSinceItBegan',
    'print_none': 'testAGroupWithoutThePrintOfItsBeginningIsEnded',
    'purpose_it': 'testAGroupHeldForAnotherPurposeIsEndedBeforeItIsReadOn',
    'abandoned': 'testAMakingStoppedBetweenItsChunksAndItsRecordIsNeverGivenOutAndGoesLater',
    'export_write': 'testAPackageWhoseWriteFailsIsNeitherLeftNorGivenOutAndIsMadeAgain',
    'import_write': 'testAnImportWhoseWriteFailsKeepsWhatArrivedAndGoesOn',
    'short': 'testAWriteOfFewerBytesThanGivenLeavesNothing',
    'silent': 'testAWriteThatFailsGivesNoWarningWithItsPath',
    'uninstall': 'testUninstallDeletesEveryOptionThePluginDeclares',
}
e('paket/r5-grant-before-export', 'serve(): the export is loaded only after a right MAC (A-4)', GRANT_F,
  "\t\t$now   = time();\n\t\t$grant = self::grant( $request, $now );",
  "\t\t$now   = time();\n\t\trequire_once __DIR__ . '/class-sync-export.php';\n\t\t$grant = self::grant( $request, $now );", 'itpkg', R5['mac_first'])
e('paket/r5-controller-no-export', 'serve_package(): the controller loads no export before the MAC (A-4)', RC,
  "\t\treturn AB_MCP_Sync_Grant::serve( $result, $server, $request );",
  "\t\trequire_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';\n\t\treturn AB_MCP_Sync_Grant::serve( $result, $server, $request );", 'itpkg', R5['mac_first'])

# A-1: every lock of the credential check holds the line to identity and reason.
e('paket/r5-line-credential-lock', 'line(): an object with any lock of the credential check: identity and reason only (A-1)', EX,
  LH, LH.replace(" || self::credential_locked( $object['locks'] )", ''), 'package', HELD % 'a lock *:script')
e('paket/r5-credential-locked-op', 'credential_locked(): by the operation of the key, kind:operation (A-1)', EX,
  "\t\t\tif ( in_array( $op, self::CREDENTIAL_LOCKS, true ) ) {", "\t\t\tif ( in_array( $key, self::CREDENTIAL_LOCKS, true ) ) {", 'package', HELD % 'a lock *:credential_field')
e('paket/r5-credential-locks-list', 'CREDENTIAL_LOCKS: also a name of a credential no reader read (A-1)', EX,
  "'php_object', 'unreadable_value', 'unread_credential' );", "'php_object', 'unreadable_value' );", 'package', R5['locks_list'])
e('paket/r5-it-credential-script', 'CREDENTIAL_LOCKS: a script, as in the example of Codex (two sites, A-1)', EX,
  "'password_protected', 'script', 'html_outside_kses',", "'password_protected', 'html_outside_kses',", 'itpkg', R5['it_script'])

# A-2: the random value of the role and pair.
e('paket/r5-binding-bind', 'binding(): the random value of the role and pair (A-2)', EX,
  "\t\t\t\t\t'bind'   => $bind,\n", "", 'package', R5['bind_unit'])
e('paket/r5-it-binding-bind', 'binding(): A → B → A gives no package of before out (two sites, A-2)', EX,
  "\t\t\t\t\t'bind'   => $bind,\n", "", 'itpkg', R5['bind_it'])
e('paket/r5-binding-no-bind', 'binding(): none without the random value (A-2)', EX, BN, BN.replace(" || null === $bind", ''), 'package', R5['bind_unit'])
e('paket/r5-save-rebind', 'save(): every write of the role or the pair a new random value (A-2)', S,
  "\t\t\tAB_MCP_Settings::set( self::KEY, self::rebind( $raw ) );\n\t\t}\n\t\t// A connection", "\t\t\tAB_MCP_Settings::set( self::KEY, $raw );\n\t\t}\n\t\t// A connection", 'status', R5['bind_write'])
e('paket/r5-remove-pair-rebind', 'remove_pair(): a new random value (A-2)', S,
  "\t\t\tunset( $raw['pair'] );\n\t\t\tAB_MCP_Settings::set( self::KEY, self::rebind( $raw ) );", "\t\t\tunset( $raw['pair'] );\n\t\t\tAB_MCP_Settings::set( self::KEY, $raw );", 'status', R5['bind_write'])
e('paket/r5-bind-shape', 'bind(): only as rebind() writes it (A-2)', S,
  "&& 1 === preg_match( '/^[0-9a-f]{32}\\z/', $raw['bind'] ) ? $raw['bind'] : null;", "? $raw['bind'] : null;", 'status', R5['bind_read'])
e('paket/r5-new-bind-readback', 'new_bind(): a value that does not read back is none (A-2)', S,
  "\t\treturn self::bind() === $raw['bind'] ? $raw['bind'] : null;", "\t\treturn $raw['bind'];", 'package', R5['bind_unit'])
REFB = "\t\tif ( null === AB_MCP_Staging::bind() && null === AB_MCP_Staging::new_bind() ) {"
e('paket/r5-refusal-new-bind', 'refusal(): a record without the random value gets one (A-2)', EX, REFB, REFB.replace(" && null === AB_MCP_Staging::new_bind()", ''), 'package', R5['bind_unit'])
e('paket/r5-refusal-unbound', 'refusal(): no package where it cannot be stored (A-2)', EX, REFB, "\t\tif ( false ) {", 'package', R5['bind_unit'])

# C: a key without the secret of its MACs.
e('paket/r5-refusal-mac-ready', 'refusal(): the secret of the MACs made where missing, else the way (C)', EX,
  "\t\tif ( ! AB_MCP_Sync_Key::mac_ready() ) {", "\t\tif ( false ) {", 'package', R5['mac'])
e('paket/r5-mac-ready-readback', 'mac_ready(): the secret made reads back (C)', K,
  "\t\treturn self::mac_secret() === hex2bin( $mac );\n\t}", "\t\treturn true;\n\t}", 'package', R5['mac'])
e('paket/r5-mac-ready-keeps', 'mac_ready(): a secret that is there stays (C)', K,
  "\t\tif ( null !== self::mac_secret() ) {\n\t\t\treturn true;\n\t\t}\n\t\ttry {", "\t\ttry {", 'package', R5['mac'])
e('paket/r5-key-make-mac', 'make(): a key without the secret of its MACs gets one (C)', K,
  "\t\t\tif ( ! self::mac_ready() ) {\n\t\t\t\treturn self::mac_refusal();", "\t\t\tif ( false ) {\n\t\t\t\treturn self::mac_refusal();", 'package', R5['mac'])

# Point 3 and its addendum: the anchor.
IDEM = "\t\tif ( null !== $current && is_string( $fingerprint ) && hash_equals( $current['fingerprint'], $fingerprint ) && ( null === $prepared || $prepared['key_id'] === $current['key_id'] ) ) {"
e('paket/r5-anchor-idempotent', 'confirm(): the active key once more changes nothing (point 3)', A, IDEM, "\t\tif ( false ) {", 'trust', R5['idem'])
e('paket/r5-it-anchor-idempotent', 'confirm(): a second click leaves the import running (two sites, point 3)', A, IDEM, "\t\tif ( false ) {", 'itpkg', R5['first_flow'])
e('paket/r5-anchor-idempotent-same-key', 'confirm(): not where another key is prepared (point 3)', A, IDEM, IDEM.replace(" && ( null === $prepared || $prepared['key_id'] === $current['key_id'] )", ''), 'trust', R5['idem'])
e('paket/r5-anchor-confirm-retired', 'confirm(): a retired key is not confirmed (point 3)', A,
  "\t\tif ( self::retired( $prepared['key_id'] ) ) {\n\t\t\treturn self::retired_refusal();\n\t\t}\n\t\t// The key it replaces", "\t\t// The key it replaces", 'trust', R5['retired_prepared'])
e('paket/r5-anchor-hold-retired', 'hold(): a retired key is not prepared (point 3)', A,
  "\t\tif ( self::retired( $key['key_id'] ) ) {\n\t\t\treturn self::retired_refusal();\n\t\t}\n\t\t$prepared = array(", "\t\t$prepared = array(", 'trust', R5['retired'])
RET = "\t\tif ( null !== $state['active'] && $state['active']['key_id'] !== $prepared['key_id'] && ! self::retire( $state['active']['key_id'] ) ) {"
e('paket/r5-anchor-retire-replaced', 'confirm(): the key it replaces is retired (point 3)', A, RET, "\t\tif ( false ) {", 'trust', R5['retired'])
e('paket/r5-anchor-retire-failed', 'confirm(): no new key where the old one cannot be kept as replaced (point 3)', A, RET, RET.replace("! self::retire(", "false && self::retire("), 'trust', R5['retire_failed'])
FORGET = "\t\tif ( null !== $active && ! self::retire( $active['key_id'] ) ) {"
e('paket/r5-anchor-forget-retire', 'forget(): the active key is revoked with it (point 3)', A, FORGET, "\t\tif ( false ) {", 'trust', R5['retired'])
e('paket/r5-anchor-retired-last', 'retired_list(): the last MAX_RETIRED (point 3)', A,
  "\t\treturn array_slice( $out, -self::MAX_RETIRED );", "\t\treturn $out;", 'trust', R5['retired_fifty'])
e('paket/r5-anchor-retired-shape', 'retired_list(): only SHA-256 in hex (point 3)', A,
  "if ( is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{64}\\z/', $hash ) ) {", "if ( is_string( $hash ) ) {", 'trust', R5['retired_fifty'])
e('paket/r5-anchor-retire-bounded', 'retire(): written bounded (point 3)', A,
  "\t\t$list   = array_slice( $list, -self::MAX_RETIRED );\n", "", 'trust', R5['retired_fifty'])
e('paket/r5-anchor-retire-readback', 'retire(): read back (point 3)', A,
  "\t\treturn self::retired_list() === $list;", "\t\treturn true;", 'trust', R5['retire_failed'])
e('paket/r5-anchor-retired-noautoload', 'retire(): without autoload (point 3)', A,
  "\t\tupdate_option( self::OPTION_RETIRED, $list, false );", "\t\tupdate_option( self::OPTION_RETIRED, $list );", 'trust', R5['retired'])
e('paket/r5-uninstall-retired', 'uninstall.php: the retired key ids go (point 3)', UN,
  "\t'ab_mcp_sync_anchor_retired',\n", "", 'uninstall', R5['uninstall'])

# Point 3: the print at the import; B-3: the purpose of a continuation.
e('paket/r5-import-print-kept', 'begin(): the print of the pair kept with the record (point 3)', IM,
  "\t\t\t\t'print'      => $print,\n", "\t\t\t\t'print'      => null,\n", 'itpkg', R5['print_it'])
PRN = "\t\tif ( ! self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) ) {"
e('paket/r5-import-print-check', 'held(): a continuation ends where the print changed (point 3)', IM, PRN, "\t\tif ( false ) {", 'itpkg', R5['print_it'])
e('paket/r5-import-print-none', 'bound(): a group without the print of its beginning is none of now (point 3)', IM,
  "\t\treturn null !== $found['print'] && hash_equals( $found['print'], (string) $print );", "\t\treturn null === $found['print'] || hash_equals( (string) $found['print'], (string) $print );", 'itpkg', R5['print_none'])
e('paket/r5-import-print-removed', 'held(): what arrived of it is removed (point 3)', IM,
  PRN + "\n\t\t\tAB_MCP_Protected_Store::remove( 'in', $found['gid'] );\n", PRN + "\n", 'itpkg', R5['print_it'])
PUR = "\t\tif ( ! is_array( $expect ) || ! isset( $expect['purpose'] ) || ! in_array( $expect['purpose'], AB_MCP_Sync_Export::PURPOSES, true ) ) {"
e('paket/r5-import-purpose-held', 'held(): every continuation a purpose this version takes (B-3)', IM, PUR, "\t\tif ( false ) {", 'itpkg', R5['purpose_it'])
e('paket/r5-import-purpose-removed', 'held(): a group for another purpose is ended (B-3)', IM,
  PUR + "\n\t\t\tAB_MCP_Protected_Store::remove( 'in', $found['gid'] );\n", PUR + "\n", 'itpkg', R5['purpose_it'])

# B-4: writes that fail for real, a making stopped before its record.
DIS = "\t\t\t} elseif ( null === $found && null !== $now && $group['created'] < $now - self::ABANDONED ) {"
e('paket/r5-discard-abandoned', 'discard_stale(): a making stopped before its record goes after ABANDONED (B-4)', EX, DIS, "\t\t\t} elseif ( false ) {", 'itpkg', R5['abandoned'])
e('paket/r5-discard-abandoned-age', 'discard_stale(): not before ABANDONED, it may still be written (B-4)', EX, DIS, "\t\t\t} elseif ( null === $found ) {", 'itpkg', R5['abandoned'])
e('paket/r5-payload-tmp', 'write(): a failed write, also one that falls short, leaves no temp file (B-4)', PF,
  "\t\tif ( false === $written ) {\n\t\t\t@unlink( $tmp );", "\t\tif ( false === $written ) {\n\t\t\t", 'payload', R5['short'])
e('paket/r5-payload-silent', 'write(): no warning with the path (B-4)', PF,
  "$written = @file_put_contents( $tmp, $blob );", "$written = file_put_contents( $tmp, $blob );", 'payload', R5['silent'])

# ------------------------------------------------------ Codex round 6
R6 = {
    'revoke': 'testARevocationThatCannotBeKeptLeavesThePairAndTheAnchor',
    'flow': 'testAPairEnteredAgainShowsAndOpensTheWayToANewKey',
    'it_flow': 'testAPairEnteredAgainGoesOnWithARenewedKeyAndNotWithTheOldOne',
    'readback': 'testAConfirmationThatDoesNotReadBackIsNone',
    'notices': 'testEveryNoticeOfTheFormsIsOneThePageShows',
    'hooks': 'testTheFormsAreHookedForAdminPost',
}
# F1: a revocation that cannot be kept changes nothing.
e('paket/r6-forget-fail-closed', 'forget(): where the key cannot be kept as revoked, the anchor stays (F1)', A,
  FORGET, FORGET.replace(" ) ) {", " ) && false ) {"), 'trust', R6['revoke'])
e('paket/r6-save-forget-first', 'save(): a pair whose anchor cannot be revoked is not changed (F1)', S,
  "\t\t\t$forgot = AB_MCP_Sync_Anchor::forget();\n\t\t\tif ( is_wp_error( $forgot ) ) {\n\t\t\t\treturn $forgot;\n\t\t\t}\n\t\t}\n\n\t\t// The key of a staging source",
  "\t\t\t$forgot = AB_MCP_Sync_Anchor::forget();\n\t\t}\n\n\t\t// The key of a staging source", 'trust', R6['revoke'])
e('paket/r6-remove-pair-forget-first', 'remove_pair(): nothing removed where the anchor cannot be revoked (F1)', S,
  "\t\t$forgot = AB_MCP_Sync_Anchor::forget();\n\t\tif ( is_wp_error( $forgot ) ) {\n\t\t\treturn $forgot;\n\t\t}\n\t\t$had",
  "\t\t$forgot = AB_MCP_Sync_Anchor::forget();\n\t\t$had", 'trust', R6['revoke'])
e('paket/r6-rest-delete-refusal', 'rest_delete(): the hub gets the refusal (F1)', S,
  "\t\tif ( is_wp_error( $removed ) ) {\n\t\t\treturn $removed;\n\t\t}\n", "", 'trust', R6['revoke'])
e('paket/r6-notice-revoke-failed', 'TRUST_NOTICES: the page says the pair stays, with the way (F1)', AD,
  "\t\t'staging_trust_sync_anchor_revoke_failed'  => array( 'error',", "\t\t'staging_trust_sync_anchor_revoke_failedx' => array( 'error',", 'trust', R6['revoke'])

# F2: the way to a new key, visible and working.
e('paket/r6-notice-retired', 'TRUST_NOTICES: a retired key, shown with its steps (F2)', AD,
  "\t\t'staging_trust_sync_anchor_retired'        => array( 'error',", "\t\t'staging_trust_sync_anchor_retiredx'       => array( 'error',", 'trust', R6['flow'])
e('paket/r6-notice-retire-failed', 'TRUST_NOTICES: a replacement that cannot be kept (F2)', AD,
  "\t\t'staging_trust_sync_anchor_retire_failed'  => array( 'error',", "\t\t'staging_trust_sync_anchor_retire_failedx' => array( 'error',", 'trust', R6['notices'])
e('paket/r6-notice-mac-failed', 'TRUST_NOTICES: the secret of the MACs that cannot be stored (F2)', AD,
  "\t\t'staging_trust_sync_mac_failed'            => array( 'error',", "\t\t'staging_trust_sync_mac_failedx'           => array( 'error',", 'trust', R6['notices'])
e('paket/r6-notice-renewed', 'TRUST_NOTICES: the renewed key, with the steps on the production site (F2)', AD,
  "\t\t'staging_key_renewed'                      => array( 'success',", "\t\t'staging_key_renewedx'                     => array( 'success',", 'trust', R6['flow'])
e('paket/r6-renew-hook', 'the form «Renew the key» is hooked (F2)', AD,
  "\t\tadd_action( 'admin_post_ab_mcp_staging_key_renew', array( $this, 'handle_staging_key_renew' ) );\n", "", 'trust', R6['hooks'])
e('paket/r6-renew-nonce', 'handle_staging_key_renew(): behind its own nonce (F2)', AD,
  "\t\tcheck_admin_referer( 'ab_mcp_staging_key_renew' );", "\t\tcheck_admin_referer( 'ab_mcp_staging_key' );", 'trust', R6['flow'])
e('paket/r6-renew-guard', 'handle_staging_key_renew(): manage_options (F2)', AD,
  "\t\t$this->guard();\n\t\t// The form of the page only", "\t\t// The form of the page only", 'trust', R6['flow'])
e('paket/r6-renew-form', 'the page of the staging site offers «Renew the key» (F2)', AD,
  "\t\t$html .= '<input type=\"hidden\" name=\"action\" value=\"ab_mcp_staging_key_renew\">';\n", "", 'trust', R6['flow'])
e('paket/r6-replace-role', 'replace(): only a confirmed staging site renews its key (F2)', K,
  "\tpublic static function replace() {\n\t\tif ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {", "\tpublic static function replace() {\n\t\tif ( false ) {", 'trust', R6['flow'])
e('paket/r6-it-replace-renews', 'replace(): a new key, the role as it was (two sites, F2)', K,
  "\t\t\treturn self::role_refusal();\n\t\t}\n\t\treturn self::renew();", "\t\t\treturn self::role_refusal();\n\t\t}\n\t\treturn self::current();", 'itpkg', R6['it_flow'])
e('paket/r6-retired-way', 'retired_refusal(): names «Renew the key» on the staging site (F2)', A,
  "On the staging site, click «Renew the key» under «Key of this staging site» on its page AlphaBridge → Staging;", "Renew the key on the staging site;", 'trust', R6['flow'])

# F4: a confirmation counts as it reads back; a retired key is no active one.
e('paket/r6-confirm-readback', 'confirm(): confirmed only as the anchor reads back (F4)', A,
  "\t\tif ( self::read()['active'] !== $active ) {", "\t\tif ( false ) {", 'trust', R6['readback'])
e('paket/r6-active-retired', 'active(): an anchor whose key is retired is none (F4)', A,
  "$active['partner_url'] === self::partner() && ! self::retired( $active['key_id'] ) ? $active : null;", "$active['partner_url'] === self::partner() ? $active : null;", 'trust', R6['readback'])
e('paket/r6-confirm-current-retired', 'confirm(): a retired anchor is not confirmed once more (F4)', A,
  "$state['active']['partner_url'] === $partner && ! self::retired( $state['active']['key_id'] ) ? $state['active'] : null;", "$state['active']['partner_url'] === $partner ? $state['active'] : null;", 'trust', R6['readback'])

# ------------------------------------------------------ Codex round 7
e('paket/r7-renew-post-only', 'handle_staging_key_renew(): a POST only, nothing on a GET with the nonce (R1)', AD,
  "\t\tif ( 'POST' !== $method ) {\n\t\t\twp_die( esc_html__( 'Nothing was changed: «Renew the key»", "\t\tif ( false ) {\n\t\t\twp_die( esc_html__( 'Nothing was changed: «Renew the key»", 'trust', R6['flow'])
e('paket/r8-renew-post-exact', 'handle_staging_key_renew(): the method exactly POST, not as normalised (R1)', AD,
  "? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared exactly with 'POST' below, never stored or shown.\n\t\tif ( 'POST' !== $method ) {\n\t\t\twp_die( esc_html__( 'Nothing was changed: «Renew the key»", "? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared exactly with 'POST' below, never stored or shown.\n\t\tif ( 'POST' !== $method ) {\n\t\t\twp_die( esc_html__( 'Nothing was changed: «Renew the key»", 'trust', R6['flow'])

# ------------------------------------------- part 3 (decisions 9 and 8)
T3 = {
    'cond': 'testOnlyAnAccountWithManageOptionsAndWriteAccessOnMakesOrGivesOutAPackage',
    'kinds': 'testAKindAndSubtypeWithAWithheldObjectIsNotComplete',
    'inflate': 'testAChunkIsInflatedNoFurtherThanItMay',
    'held_name': 'testTheListOfHeldObjectsIsNamedByItsHashInBase32',
    'mode_it': 'testWithWriteAccessOffAStagingSiteMakesAndGivesOutNothing',
    'package_it': 'testAPackageHoldsWhatTheAccountMayReadAndNamesTheRest',
    'cap_it': 'testAnAnswerNamesTheFirstTwoHundredWithheldObjectsAndCountsAll',
    'rights_it': 'testTheRightsOfAnObjectAreAskedOfWordPress',
    'unpack_it': 'testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain',
}
# Who makes a package.
e('paket/t3-refusal-capability', 'refusal(): an account with manage_options (decision 9)', EX,
  "\t\tif ( ! current_user_can( 'manage_options' ) ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_capability'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_capability'", 'package', T3['cond'])
e('paket/t3-refusal-mode', 'refusal(): write access on, the access level Full (decision 9)', EX,
  "\t\tif ( ! AB_MCP_Site_Mode::is_full() ) {\n\t\t\t/* translators: %s: how to switch write access on, a whole sentence. */\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_mode'", "\t\tif ( false ) {\n\t\t\t/* translators: %s: how to switch write access on, a whole sentence. */\n\t\t\treturn new WP_Error( 'ab_mcp_sync_export_mode'", 'package', T3['cond'])
e('paket/t3-deliver-mode', 'deliver(): nothing given out with write access off (two sites)', EX,
  "\t\t// With write access off, a staging site gives nothing out.\n\t\tif ( ! AB_MCP_Site_Mode::is_full() ) {", "\t\t// With write access off, a staging site gives nothing out.\n\t\tif ( false ) {", 'itpkg', T3['mode_it'])
# What goes into it.
e('paket/t3-sort-b9', 'sort(): a name of a credential is not packed, counted (two sites)', EX,
  "\t\t\tif ( 'b9' === $status ) {\n\t\t\t\t++$credentials['names'];", "\t\t\tif ( false ) {\n\t\t\t\t++$credentials['names'];", 'itpkg', T3['package_it'])
e('paket/t3-sort-withheld', 'sort(): what the account may not read is not packed (two sites)', EX,
  "\t\t\tif ( null !== $needs ) {", "\t\t\tif ( false ) {", 'itpkg', T3['package_it'])
e('paket/t3-sort-incomplete', 'sort(): its kind and subtype not complete (two sites)', EX,
  "\t\t\t\t$incomplete[ $object['kind'] . \"\\0\" . $subtype ] = true;\n", "", 'itpkg', T3['package_it'])
e('paket/t3-sort-named-cap', 'sort(): the first WITHHELD_LIST named, all counted (two sites)', EX,
  "\t\t\t\tif ( count( $withheld ) < self::WITHHELD_LIST ) {", "\t\t\t\tif ( true ) {", 'itpkg', T3['cap_it'])
e('paket/t3-sort-count', 'sort(): every withheld object counted (two sites)', EX,
  "\t\t\t\t++$count;\n", "", 'itpkg', T3['package_it'])
e('paket/t3-sort-units', 'sort(): units named as credentials in packed objects counted (two sites)', EX,
  "\t\t\t$credentials['units'] += count( $object['credentials']['b9'] );\n", "", 'itpkg', T3['package_it'])
e('paket/t3-sort-held', 'sort(): objects a credential holds counted (two sites)', EX,
  "\t\t\t\t++$credentials['held'];\n", "", 'itpkg', T3['package_it'])
e('paket/t3-kinds-incomplete', 'kinds(): not complete where an object was withheld', EX,
  DONE, DONE.replace(" && ! isset( $incomplete[ $key ] )", ''), 'package', T3['kinds'])
e('paket/t3-answer-way', 'answer(): the way only where something was withheld (two sites)', EX,
  "( $report['withheld']['count'] > 0 ? array( 'way' => self::withheld_way() ) : array() )", "( $report['withheld']['count'] >= 0 ? array( 'way' => self::withheld_way() ) : array() )", 'itpkg', T3['package_it'])
e('paket/t3-record-report', 'store(): the record keeps what was withheld, for again() (two sites)', EX,
  "\t\t\t\t'report'    => $report,\n", "\t\t\t\t'report'    => array( 'credentials' => array( 'held' => 0, 'names' => 0, 'units' => 0 ), 'withheld' => array( 'count' => 0, 'objects' => array() ) ),\n", 'itpkg', T3['package_it'])
# The rights of an object.
e('paket/t3-rights-post-read', 'post(): read_post (two sites)', RT,
  "\t\tif ( ! current_user_can( 'read_post', $post->ID ) ) {", "\t\tif ( false ) {", 'itpkg', T3['rights_it'])
e('paket/t3-rights-post-public', 'post(): public, read_post is enough (two sites)', RT,
  "\t\treturn is_post_publicly_viewable( $post ) ? null : 'edit_post';", "\t\treturn 'edit_post';", 'itpkg', T3['rights_it'])
e('paket/t3-rights-post-edit', 'post(): not public, edit_post (two sites)', RT,
  "\t\treturn is_post_publicly_viewable( $post ) ? null : 'edit_post';", "\t\treturn null;", 'itpkg', T3['rights_it'])
e('paket/t3-rights-post-id', 'post(): only an id, no leading digits (two sites)', RT,
  "\t\t$post = 1 === preg_match( '/^[1-9][0-9]*\\z/', $ref ) ? get_post( (int) $ref ) : null;", "\t\t$post = get_post( (int) $ref );", 'itpkg', T3['rights_it'])
e('paket/t3-rights-post-unregistered', 'post(): a type WordPress does not know, without doing it wrong (two sites)', RT,
  "\t\tif ( null === get_post_type_object( $post->post_type ) ) {\n\t\t\treturn current_user_can( 'edit_others_posts' ) ? null : 'edit_others_posts';\n\t\t}\n", "", 'itpkg', T3['rights_it'])
e('paket/t3-rights-term-public', 'term(): a public taxonomy as anyone reads it (two sites)', RT,
  "\t\tif ( ! is_taxonomy_viewable( $taxonomy ) && ! current_user_can( 'edit_term', $term->term_id ) ) {", "\t\tif ( ! current_user_can( 'edit_term', $term->term_id ) ) {", 'itpkg', T3['rights_it'])
e('paket/t3-rights-term-edit', 'term(): the others with edit_term (two sites)', RT,
  "\t\tif ( ! is_taxonomy_viewable( $taxonomy ) && ! current_user_can( 'edit_term', $term->term_id ) ) {", "\t\tif ( false ) {", 'itpkg', T3['rights_it'])
e('paket/t3-rights-term-unregistered', 'term(): a taxonomy WordPress does not know (two sites)', RT,
  "\t\tif ( ! taxonomy_exists( $taxonomy ) ) {\n\t\t\treturn current_user_can( 'manage_categories' ) ? null : 'manage_categories';\n\t\t}\n", "", 'itpkg', T3['rights_it'])
e('paket/t3-rights-term-no-taxonomy', 'term(): without its taxonomy, gone (two sites)', RT,
  "\t\tif ( '' === $taxonomy || 1 !== preg_match( '/^[1-9][0-9]*\\z/', $ref ) ) {", "\t\tif ( 1 !== preg_match( '/^[1-9][0-9]*\\z/', $ref ) ) {", 'itpkg', T3['rights_it'])
e('paket/t3-rights-term-id', 'term(): only an id, no leading digits (two sites)', RT,
  "\t\tif ( '' === $taxonomy || 1 !== preg_match( '/^[1-9][0-9]*\\z/', $ref ) ) {", "\t\tif ( '' === $taxonomy ) {", 'itpkg', T3['rights_it'])
e('paket/t3-rights-theme', 'withheld(): the theme with edit_theme_options (two sites)', RT,
  "\t\t\treturn current_user_can( 'edit_theme_options' ) ? null : 'edit_theme_options';", "\t\t\treturn null;", 'itpkg', T3['rights_it'])
e('paket/t3-rights-theme-css', 'THEME: the additional CSS too (two sites)', RT,
  "'theme_mod', 'custom_css' );", "'theme_mod' );", 'itpkg', T3['rights_it'])
e('paket/t3-rights-kind', 'withheld(): a kind without a rule is withheld (two sites)', RT,
  "\t\treturn 'option' === $kind ? null : 'kind';", "\t\treturn null;", 'itpkg', T3['rights_it'])
e('paket/t3-rights-option', 'withheld(): options with manage_options, asked first (two sites)', RT,
  "\t\treturn 'option' === $kind ? null : 'kind';", "\t\treturn 'kind';", 'itpkg', T3['rights_it'])
# Unpacked within the limits, each line checked again.
e('paket/t3-unpack-run', 'state(): a package checked whole is unpacked and checked before it is complete (two sites)', IM,
  "\t\t\t$lines = self::unpacked( $held );", "\t\t\t$lines = array();", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-remove', 'state(): a package that does not unpack is removed (two sites)', IM,
  "\t\t\tif ( is_wp_error( $lines ) ) {\n\t\t\t\tAB_MCP_Protected_Store::remove( 'in', $held['gid'] );", "\t\t\tif ( is_wp_error( $lines ) ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-keep-held', 'state(): the list of held objects kept (two sites)', IM,
  "\t\t\t$kept = self::keep_held( $held, $lines );", "\t\t\t$kept = true;", 'itpkg', T3['unpack_it'])
e('paket/t3-inflate-object', 'unpacked(): a chunk no further than the raw size of one object (two sites)', IM,
  "self::inflate( $data, min( $declared, $limits['object_bytes'] ) )", "self::inflate( $data, $declared )", 'itpkg', T3['unpack_it'])
e('paket/t3-inflate-declared', 'unpacked(): a chunk no further than the manifest declares (two sites)', IM,
  "self::inflate( $data, min( $declared, $limits['object_bytes'] ) )", "self::inflate( $data, $limits['object_bytes'] )", 'itpkg', T3['unpack_it'])
e('paket/t3-inflate-object-limit', 'unpacked(): past one object, the limit of decision 8 named (two sites)', IM,
  "\t\t\tif ( null === $text && $declared > $limits['object_bytes'] ) {", "\t\t\tif ( false ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-empty', 'unpacked(): an empty chunk holds no line (two sites)', IM,
  "\t\t\tif ( '' === $text ) {\n\t\t\t\tcontinue;\n\t\t\t}\n", "", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-objects', 'unpacked(): no more than 5000 objects (two sites)', IM,
  "\t\t\t\t$refused = AB_MCP_Store_Budget::unpack_refusal( array( 'objects' => $count ) );", "\t\t\t\t$refused = null;", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-depth', 'unpacked(): no deeper than 64 (two sites)', IM,
  "\t\t\t\tif ( JSON_ERROR_DEPTH === json_last_error() ) {", "\t\t\t\tif ( false ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-spelling', 'unpacked(): each line in its one spelling (two sites)', IM,
  "\t\t\t\tif ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== $line ) {", "\t\t\t\tif ( ! is_array( $object ) ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-shape', 'unpacked(): each line with the fields of a line (two sites)', IM,
  "\t\t\t\tif ( ! self::line_shaped( $object ) ) {", "\t\t\t\tif ( false ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-held', 'held_code(): a name of a credential by the rules of this site listed (two sites)', IM,
  "\t\tif ( self::named_credential( $named, $extra ) ) {", "\t\tif ( false ) {", 'itpkg', T3['unpack_it'])
UEND = "\t\tif ( $count !== $manifest['objects'] || $total !== $manifest['unpacked'] ) {"
e('paket/t3-unpack-count', 'unpacked(): as many lines as declared (two sites)', IM, UEND, UEND.replace("$count !== $manifest['objects'] || ", ''), 'itpkg', T3['unpack_it'])
e('paket/t3-unpack-total', 'unpacked(): as many bytes as declared (two sites)', IM, UEND, UEND.replace(" || $total !== $manifest['unpacked']", ''), 'itpkg', T3['unpack_it'])
e('paket/t3-shape-keys', 'typed(): no field a line has no place for (two sites)', IM,
  "\t\t\t\t// No type: no field of a line.\n\t\t\t\treturn false;", "\t\t\t\t// No type: no field of a line.\n\t\t\t\treturn true;", 'itpkg', T3['unpack_it'])
e('paket/t3-shape-required', 'line_shaped(): kind, ref, identity, status and locks (two sites)', IM,
  "\t\t\tif ( ! array_key_exists( $field, $object ) ) {", "\t\t\tif ( false ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-shape-b9', 'STATUSES: no b9, an object whose name is a credential is never packed (two sites)', IM,
  "\tconst STATUSES = array( 'z8', 'locked', 'guarded', 'unreachable', 'unreadable' );", "\tconst STATUSES = array( 'z8', 'locked', 'guarded', 'unreachable', 'unreadable', 'b9' );", 'itpkg', T3['unpack_it'])
e('paket/t3-shape-locks', 'records(): each record a record (two sites)', IM,
  "\t\t\tif ( ! is_array( $record ) || array() !== array_diff_key( array_flip( $needs ), $record ) ) {", "\t\t\tif ( ! is_array( $record ) ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-shape-held', 'line_shaped(): a line a credential holds carries its identity only (two sites)', IM,
  "\t\treturn ! AB_MCP_Sync_Export::credential_locked( $object['locks'] ) || array() === array_intersect_key( $object, array_flip( self::LINE_OPEN ) );", "\t\treturn true;", 'itpkg', T3['unpack_it'])
e('paket/t3-named-theme-mod', 'named_credential(): the key of a theme mod as well (two sites)', IM,
  "\t\tforeach ( array( 'name', 'theme_mod', 'target' ) as $field ) {", "\t\tforeach ( array( 'name', 'target' ) as $field ) {", 'itpkg', T3['unpack_it'])
e('paket/t3-held-list', 'held_list(): the list read back on a later call (two sites)', IM,
  "\t\treturn $list['objects'];\n\t}", "\t\treturn array();\n\t}", 'itpkg', T3['unpack_it'])
e('paket/t3-inflate-stop', 'inflate(): stops once past its cap', IM,
  "\t\t\t$out .= $more;\n\t\t\tif ( strlen( $out ) > $cap ) {\n\t\t\t\treturn null;\n\t\t\t}", "\t\t\t$out .= $more;", 'package', T3['inflate'])
e('paket/t3-inflate-cut', 'inflate(): a cut gzip is none', IM,
  "\t\t\tif ( false === $more || ZLIB_STREAM_END !== inflate_get_status( $context ) ) {", "\t\t\tif ( false === $more ) {", 'package', T3['inflate'])
e('paket/t3-held-name-pad', 'held_sha(): only the name held_name() writes', IM,
  "\t\treturn self::held_name( $hex ) === $name ? $hex : null;", "\t\treturn $hex;", 'package', T3['held_name'])

# ---------------------------------------- part 3, Codex round 1 (B1, A1, A2, B2, C1)
P3 = {
    'rights_it': 'testTheRightsOfAnObjectAreAskedOfWordPress',
    'acf_it': 'testAFieldGroupOfAcfIsInThePackageOfAnAdministrator',
    'unpack_it': 'testAnImportUnpacksEachLineWithinTheLimitsAndChecksItAgain',
    'both_it': 'testTheInventoryOfBothSitesOverEveryKindPassesTheCheckOfTheImport',
    'older_it': 'testARecordOfAnOlderVersionIsDiscardedWithTheWay',
    'held_it': 'testACompleteImportWithoutItsListOfHeldObjectsIsFetchedAnew',
    'inflate': 'testAChunkIsInflatedNoFurtherThanItMay',
}
# B1: who may edit an object may read it.
e('paket/p3r1-post-edit-reads', 'post(): edit_post reads it, as for the field groups of ACF (two sites)', RT,
  "\t\tif ( current_user_can( 'edit_post', $post->ID ) ) {\n\t\t\treturn null;\n\t\t}\n", "", 'itpkg', P3['acf_it'])
# A1: what WordPress falls back to.
e('paket/p3r1-unknown-type', 'post(): a type WordPress does not know, edit_others_posts (two sites)', RT,
  "\t\t\treturn current_user_can( 'edit_others_posts' ) ? null : 'edit_others_posts';", "\t\t\treturn null;", 'itpkg', P3['rights_it'])
e('paket/p3r1-unknown-taxonomy', 'term(): a taxonomy WordPress does not know, manage_categories (two sites)', RT,
  "\t\t\treturn current_user_can( 'manage_categories' ) ? null : 'manage_categories';", "\t\t\treturn null;", 'itpkg', P3['rights_it'])
# A2: every nested part exactly.
e('paket/p3r1-identity-fits', 'line_shaped(): the identity as its kind has it (two sites)', IM,
  "\t\tif ( ! self::identity_fits( $object['kind'], $object['ref'], $object['identity'] ) ) {", "\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-fields', 'identity_fits(): exactly the fields of the kind (two sites)', IM,
  "\t\tif ( array() !== array_diff( $given, $want ) || array() !== array_diff( $needs, $given ) ) {", "\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-typed', 'identity_fits(): each field typed (two sites)', IM,
  "\t\t\tif ( array_key_exists( $field, $identity ) && ! self::typed( $identity[ $field ], $type ) ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-id-ref', 'identity_fits(): a post, term or menu (item) by its id (two sites)', IM,
  "\t\tif ( in_array( $kind, self::ID_KINDS, true ) && ! $id_ref ) {", "\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-widget', 'identity_fits(): a widget area by its area (two sites)', IM,
  "\t\t\t\treturn $text['area'] === $ref;", "\t\t\t\treturn true;", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-option', 'identity_fits(): an option by its name (two sites)', IM,
  "\t\t\t\treturn $text['name'] === $ref;", "\t\t\t\treturn true;", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-theme-mod', 'identity_fits(): a theme mod by its stylesheet and key (two sites)', IM,
  "\t\t\t\treturn 0 === strpos( $text['name'], 'theme_mods_' ) &&", "\t\t\t\treturn true ||", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-css', 'identity_fits(): the additional CSS by its stylesheet and post (two sites)', IM,
  "\t\t\t\treturn $text['stylesheet'] . ( $identity['linked'] ? '' : '@' . $identity['post'] ) === $ref;", "\t\t\t\treturn true;", 'itpkg', P3['unpack_it'])
e('paket/p3r1-identity-name-only', 'identity_fits(): a theme mod row by its name only (two sites)', IM,
  "\t\tif ( 'theme_mod' === $kind && array( 'name' ) === array_keys( $identity ) ) {", "\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-locks', 'line_shaped(): each lock with its fields (two sites)', IM,
  "$fits = self::records( $value, self::LOCK_FIELDS, array_keys( self::LOCK_FIELDS ) ) &&", "$fits = is_array( $value ) &&", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-refs', 'line_shaped(): each reference with its fields (two sites)', IM,
  "\t\t\t\t$fits = self::records( $value, self::REF_FIELDS, self::REF_NEEDS );", "\t\t\t\t$fits = is_array( $value );", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-possible', 'line_shaped(): each possible reference by its place only (two sites)', IM,
  "\t\t\t\t$fits = self::records( $value, self::POSSIBLE_FIELDS, array( 'at' ) );", "\t\t\t\t$fits = is_array( $value );", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-list', 'records(): a list (two sites)', IM,
  "\t\tif ( ! is_array( $list ) || ! array_is_list( $list ) ) {", "\t\tif ( ! is_array( $list ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-needs', 'records(): the fields every record carries (two sites)', IM,
  " || array() !== array_diff_key( array_flip( $needs ), $record ) ) {", " ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-fields', 'records(): no other field (two sites)', IM,
  "\t\t\t\tif ( ! isset( $fields[ $field ] ) || ! self::typed( $value, $fields[ $field ] ) ) {", "\t\t\t\tif ( isset( $fields[ $field ] ) && ! self::typed( $value, $fields[ $field ] ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-records-typed', 'records(): each field typed (two sites)', IM,
  "\t\t\t\tif ( ! isset( $fields[ $field ] ) || ! self::typed( $value, $fields[ $field ] ) ) {", "\t\t\t\tif ( ! isset( $fields[ $field ] ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-line-typed', 'line_shaped(): each field of a line typed (two sites)', IM,
  "\t\t\t\t$fits = 'identity' === $type || self::typed( $value, $type );", "\t\t\t\t$fits = true;", 'itpkg', P3['unpack_it'])
for name, search, replace in (
    ('string', "\t\t\t\treturn is_string( $value );\n\t\t\tcase 'table':", "\t\t\t\treturn true;\n\t\t\tcase 'table':"),
    ('number', "\t\t\t\treturn is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,20}\\z/', $value ) );", "\t\t\t\treturn true;"),
    ('bool', "\t\t\t\treturn is_bool( $value );", "\t\t\t\treturn true;"),
    ('lists', "\t\t\t\t\tif ( ! is_int( $item ) ) {", "\t\t\t\t\tif ( false ) {"),
    ('hash', "\t\t\t\treturn null === $value || ( is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}\\z/', $value ) );", "\t\t\t\treturn true;"),
    ('size', "\t\t\t\treturn is_int( $value ) && $value >= 0;", "\t\t\t\treturn true;"),
    ('status', "\t\t\t\treturn in_array( $value, self::STATUSES, true );", "\t\t\t\treturn is_string( $value );"),
):
    e('paket/p3r1-typed-' + name, 'typed(): the type ' + name + ' (two sites)', IM, search, replace, 'itpkg', P3['unpack_it'])
# B2: one whole gzip, whole lines.
e('paket/p3r1-line-break', 'unpacked(): a chunk ends with a line break (two sites)', IM,
  "\t\t\tif ( \"\\n\" !== substr( $text, -1 ) ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r1-inflate-all-read', 'inflate(): all of the input read, one member and nothing after it', IM,
  "\t\treturn inflate_get_read_len( $context ) === strlen( $data ) ? $out : false;", "\t\treturn $out;", 'package', P3['inflate'])
e('paket/p3r1-it-inflate-all-read', 'inflate(): bytes or a second member after it refused (two sites)', IM,
  "\t\treturn inflate_get_read_len( $context ) === strlen( $data ) ? $out : false;", "\t\treturn $out;", 'itpkg', P3['unpack_it'])
e('paket/p3r1-inflate-piece', 'INFLATE_PIECE: past the cap by one small piece at most', IM,
  "\tconst INFLATE_PIECE = 512;", "\tconst INFLATE_PIECE = 4096;", 'package', P3['inflate'])
# C1: what an older version left.
e('paket/p3r1-report-bound', 'bound(): a record without its report is none (two sites)', EX,
  "\t\tif ( null !== $found['report'] && $found['binding']", "\t\tif ( $found['binding']", 'itpkg', P3['older_it'])
e('paket/p3r1-report-shaped', 'report_shaped(): the counts of the credentials as numbers (two sites)', EX,
  " || array( true, true, true ) !== array_values( array_map( 'is_int', $report['credentials'] ) ) ) {", " ) {", 'itpkg', P3['older_it'])
e('paket/p3r1-held-missing-removed', 'state(): a complete import without its list is removed (two sites)', IM,
  "\t\t\tif ( is_wp_error( $list ) ) {\n\t\t\t\tAB_MCP_Protected_Store::remove( 'in', $held['gid'] );", "\t\t\tif ( is_wp_error( $list ) ) {", 'itpkg', P3['held_it'])

# ---------------------------------------- part 3, Codex round 2 (A2 rest)
P3['forms_it'] = 'testEveryFormOfALineTheReadersMakeGoesThroughTheImport'
e('paket/p3r2-canon', 'typed(): canon the version of the canonical form (two sites)', IM,
  "\t\t\t\treturn AB_MCP_Sync_Canon::VERSION === $value;", "\t\t\t\treturn is_string( $value );", 'itpkg', P3['unpack_it'])
e('paket/p3r2-lock-known', 'line_shaped(): each lock one of the tables (two sites)', IM,
  " && array() === array_filter( $value, static fn( $lock ) => ! self::lock_known( $lock ) );", ";", 'itpkg', P3['unpack_it'])
LK = "\t\treturn ( isset( $entry['stage'] ) ? $entry['stage'] : null ) === $lock['stage'] && ( isset( $entry['reason'] ) ? $entry['reason'] : null ) === $lock['reason'];"
e('paket/p3r2-lock-stage', 'lock_known(): the stage of the table (two sites)', IM, LK, LK.replace("( isset( $entry['stage'] ) ? $entry['stage'] : null ) === $lock['stage'] && ", ''), 'itpkg', P3['unpack_it'])
e('paket/p3r2-lock-reason', 'lock_known(): the reason of the table (two sites)', IM, LK, LK.replace(" && ( isset( $entry['reason'] ) ? $entry['reason'] : null ) === $lock['reason']", ''), 'itpkg', P3['unpack_it'])
e('paket/p3r2-lock-ownership', 'lock_known(): a lock by an entry of OWNERSHIP (two sites)', IM,
  "\t\t} elseif ( isset( AB_MCP_Sync_Kinds::OWNERSHIP[ $lock['key'] ] ) ) {", "\t\t} elseif ( false ) {", 'itpkg', P3['both_it'])
e('paket/p3r2-lock-locks', 'lock_known(): a lock of LOCKS (two sites)', IM,
  "\t\tif ( isset( AB_MCP_Sync_Kinds::LOCKS[ $lock['key'] ] ) ) {", "\t\tif ( false ) {", 'itpkg', P3['forms_it'])
e('paket/p3r2-space', 'typed(): a space of the readers (two sites)', IM,
  "\t\t\t\treturn in_array( $value, self::SPACES, true );", "\t\t\t\treturn is_string( $value );", 'itpkg', P3['unpack_it'])
e('paket/p3r2-strength', 'typed(): a strength of the readers (two sites)', IM,
  "\t\t\t\treturn in_array( $value, self::STRENGTHS, true );", "\t\t\t\treturn is_string( $value );", 'itpkg', P3['unpack_it'])
e('paket/p3r2-integer', 'typed(): a position may be negative (two sites)', IM,
  "1 === preg_match( '/^-?[0-9]{1,19}\\z/', $value )", "1 === preg_match( '/^[0-9]{1,19}\\z/', $value )", 'itpkg', P3['forms_it'])
e('paket/p3r2-name-bytes', 'typed(): a name in the byte form of the canon (two sites)', IM,
  "\t\t\t\treturn null !== self::bytes( $value );", "\t\t\t\treturn is_string( $value );", 'itpkg', P3['forms_it'])
e('paket/p3r2-bytes-not-utf8', 'bytes(): only bytes that are no UTF-8 (two sites)', IM,
  " && 1 !== preg_match( '//u', $bytes ) ? $bytes : null;", " ? $bytes : null;", 'itpkg', P3['unpack_it'])
e('paket/p3r2-bytes-round-trip', 'bytes(): base64 as the canon writes it (two sites)', IM,
  " && base64_encode( $bytes ) === $value['~b'] &&", " &&", 'itpkg', P3['unpack_it'])
EI = "\t\tif ( array() === $object['identity'] && ( ! isset( $object['complete'], $object['unread'] ) || false !== $object['complete'] || array() === array_intersect( $object['unread'], self::ROW_UNREAD ) ) ) {"
e('paket/p3r2-empty-identity', 'line_shaped(): an empty identity only where the row was not read (two sites)', IM, EI, "\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r2-empty-identity-complete', 'line_shaped(): not complete (two sites)', IM, EI, EI.replace(" || false !== $object['complete']", ''), 'itpkg', P3['unpack_it'])
e('paket/p3r2-empty-identity-table', 'line_shaped(): the table in unread (two sites)', IM, EI, EI.replace(" || array() === array_intersect( $object['unread'], self::ROW_UNREAD )", ''), 'itpkg', P3['unpack_it'])
e('paket/p3r2-row-unread', 'ROW_UNREAD: every table a reader notes (two sites)', IM,
  "'menu_item', 'theme_mods', 'options' );", "'menu_item', 'theme_mods' );", 'itpkg', P3['forms_it'])
e('paket/p3r2-empty-identity-ref', 'unpacked(): a line without its identity checked by its ref (two sites)', IM,
  "array() === $object['identity'] ? array( 'name' => $object['ref'] ) : $object['identity']", "$object['identity']", 'itpkg', P3['unpack_it'])

# ---------------------------------------- part 3, Codex round 3 (two levels)
# The names of a line: any text, never a refusal; a credential in one holds the line.
e('paket/p3r3-typed-names', 'typed(): names, each a name (two sites)', IM,
  "\t\t\t\t\tif ( null === self::bytes( $item ) ) {", "\t\t\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-typed-names-list', 'typed(): names, a list (two sites)', IM,
  "\t\t\tcase 'names':\n\t\t\t\tif ( ! is_array( $value ) || ! array_is_list( $value ) ) {", "\t\t\tcase 'names':\n\t\t\t\tif ( ! is_array( $value ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-typed-login', 'typed(): a login, a name or none (two sites)', IM,
  "\t\t\t\treturn null === $value || null !== self::bytes( $value );", "\t\t\t\treturn true;", 'itpkg', P3['unpack_it'])
e('paket/p3r3-name-only-bytes', 'identity_fits(): a theme mod row by its name only, in bytes too (two sites)', IM,
  "\t\t\t$fields = array( 'name' => 'name' );", "\t\t\t$fields = array( 'name' => 'string' );", 'itpkg', P3['forms_it'])
e('paket/p3r3-unpack-code', 'unpacked(): each line asked why it is held (two sites)', IM,
  "\t\t\t\t$code = self::held_code( $object, $extra );", "\t\t\t\t$code = null;", 'itpkg', P3['unpack_it'])
HL = "\t\t\t\t\t$out[] = array( $object['kind'], hash( 'sha256', AB_MCP_Sync_Canon::encode( $object['ref'] ) ), $code );"
e('paket/p3r3-held-list-code', 'unpacked(): kind, ref and the code in the list (two sites)', IM, HL, HL.replace(", $code );", " );"), 'itpkg', P3['unpack_it'])
e('paket/p3r3-held-target', 'held_code(): the row WordPress reads instead named as well (two sites)', IM,
  " + array_intersect_key( $object, array( 'target' => true ) );", ";", 'itpkg', P3['unpack_it'])
e('paket/p3r3-named-target', 'named_credential(): the target as well (two sites)', IM,
  "\t\tforeach ( array( 'name', 'theme_mod', 'target' ) as $field ) {", "\t\tforeach ( array( 'name', 'theme_mod' ) as $field ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-held-field', 'held_code(): a field of a credential in a name holds the line (two sites)', IM,
  "\t\t\t$check = AB_MCP_Sync_Credentials::unit( '', $name, $extra );\n\t\t\tif ( 'mixed' === $check['state'] ) {", "\t\t\t$check = AB_MCP_Sync_Credentials::unit( '', $name, $extra );\n\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-held-unreadable', 'held_code(): a name that does not read to its end holds the line (two sites)', IM,
  "\t\t\tif ( 'unreadable' === $check['state'] ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-identity', 'names_of(): each text of the identity (two sites)', IM,
  "\t\t$out    = array_values( $object['identity'] );", "\t\t$out    = array();", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-line', 'names_of(): the names of the line (two sites)', IM,
  "\t\t\tarray( array( $object ), self::LINE_FIELDS ),\n", "", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-locks', 'names_of(): the places of the locks (two sites)', IM,
  "\t\t\tarray( $object['locks'], self::LOCK_FIELDS ),\n", "", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-refs', 'names_of(): the places and logins of the references (two sites)', IM,
  "\t\t\tarray( isset( $object['refs'] ) ? $object['refs'] : array(), self::REF_FIELDS ),\n", "", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-possible', 'names_of(): the places of the possible references (two sites)', IM,
  "\t\t\tarray( isset( $object['possible'] ) ? $object['possible'] : array(), self::POSSIBLE_FIELDS ),\n", "", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-list', 'names_of(): each name of a list of names (two sites)', IM,
  "'names' === $type ? $record[ $field ] : array( $record[ $field ] )", "array( $record[ $field ] )", 'itpkg', P3['unpack_it'])
e('paket/p3r3-names-login', 'names_of(): a login (two sites)', IM,
  "array( 'name', 'names', 'login' ), true )", "array( 'name', 'names' ), true )", 'itpkg', P3['unpack_it'])
e('paket/p3r3-held-format', 'HELD_FORMAT: a list of the version before is none (two sites)', IM,
  "\tconst HELD_FORMAT = 'ab-sync-held/3';", "\tconst HELD_FORMAT = 'ab-sync-held/1';", 'itpkg', P3['held_it'])
# The reason of a row the takeover never reads, as a code.
RC_ = "\t\tif ( null === $codes ? array_key_exists( 'reason_code', $object ) : ! in_array( isset( $object['reason_code'] ) ? $object['reason_code'] : null, $codes, true ) ) {"
e('paket/p3r3-reason-code-elsewhere', 'line_shaped(): no reason code where the row is read (two sites)', IM, RC_, RC_.replace("array_key_exists( 'reason_code', $object )", 'false'), 'itpkg', P3['unpack_it'])
e('paket/p3r3-reason-code-list', 'line_shaped(): a reason code of the list of its status (two sites)', IM, RC_, RC_.replace("! in_array( isset( $object['reason_code'] ) ? $object['reason_code'] : null, $codes, true )", "! isset( $object['reason_code'] )"), 'itpkg', P3['unpack_it'])
e('paket/p3r3-reason-code-needed', 'line_shaped(): the reason code there (two sites)', IM, RC_, RC_.replace("$object['reason_code'] : null, $codes", "$object['reason_code'] : $codes[0], $codes"), 'itpkg', P3['unpack_it'])
TG = "\t\tif ( ( 'unreachable' === $object['status'] ) !== array_key_exists( 'target', $object ) ) {"
e('paket/p3r3-target-needed', 'line_shaped(): unreachable with its target (two sites)', IM, TG, "\t\tif ( 'unreachable' !== $object['status'] && array_key_exists( 'target', $object ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-target-elsewhere', 'line_shaped(): no target elsewhere (two sites)', IM, TG, "\t\tif ( 'unreachable' === $object['status'] && ! array_key_exists( 'target', $object ) ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r3-codes-unreadable', 'REASON_CODES: lookup_failed of unreadable (two sites)', OS,
  "\t\t'unreadable'  => array( 'lookup_failed', 'not_settled' ),", "\t\t'unreadable'  => array( 'not_settled' ),", 'itpkg', P3['forms_it'])
e('paket/p3r3-class-guarded', 'classify(): guarded with its code (two sites)', OS,
  " + array( 'code' => 'option_guard' );", ";", 'itpkg', P3['forms_it'])
e('paket/p3r3-class-unreachable', 'classify(): unreachable with its code (two sites)', OS,
  "\t\t\t\t'code'   => 'read_as_other_row',\n", "", 'itpkg', P3['forms_it'])
e('paket/p3r3-class-target', 'classify(): unreachable with the row WordPress reads (two sites)', OS,
  "\t\t\t\t'target' => $resolved['name'],\n", "", 'itpkg', P3['forms_it'])
e('paket/p3r3-class-unreadable', 'classify(): unreadable with its code (two sites)', OS,
  " + array( 'code' => $resolved->get_error_data()['code'] );", ";", 'itpkg', P3['forms_it'])
e('paket/p3r3-settle-lookup', 'settle(): a row not looked up, lookup_failed (two sites)', OS,
  "the database error log names the cause.', array( 'code' => 'lookup_failed' ) );", "the database error log names the cause.', array( 'code' => 'not_settled' ) );", 'itpkg', P3['forms_it'])
e('paket/p3r3-bare-why', 'bare(): the code and target of a row the takeover never reads (two sites)', DK,
  "\t\t$why = isset( AB_MCP_Sync_Option_Schema::REASON_CODES[ $class['status'] ] ) ? array_intersect_key( $class, array_flip( array( 'code', 'target' ) ) ) : array();", "\t\t$why = array();", 'itpkg', P3['forms_it'])
e('paket/p3r3-bare-why-only', 'bare(): no reason code of another status (two sites)', DK,
  "\t\t$why = isset( AB_MCP_Sync_Option_Schema::REASON_CODES[ $class['status'] ] ) ? array_intersect_key( $class, array_flip( array( 'code', 'target' ) ) ) : array();", "\t\t$why = array_intersect_key( $class, array_flip( array( 'code', 'target' ) ) );", 'itpkg', P3['forms_it'])
e('paket/p3r3-line-why', 'line(): the code of the reason and the target, never the text', EX,
  "\t\t) + array_intersect_key( $object, array_flip( array( 'reason_code', 'target' ) ) );", "\t\t);", 'package', 'testALineCarriesWhatTheInventoryNeedsAndNoValueOfTheContent')

# ---------------------------------------- part 3, Codex round 4 (net, list without values)
e('paket/p3r4-held-hash', 'unpacked(): the SHA-256 of the ref in the list, never the ref (two sites)', IM, HL, HL.replace("hash( 'sha256', AB_MCP_Sync_Canon::encode( $object['ref'] ) )", "$object['ref']"), 'itpkg', P3['unpack_it'])
e('paket/p3r4-held-hash-canon', 'unpacked(): the ref as the canon spells it (two sites)', IM, HL, HL.replace("AB_MCP_Sync_Canon::encode( $object['ref'] )", "(string) AB_MCP_Sync_Import::bytes( $object['ref'] )"), 'itpkg', P3['unpack_it'])
e('paket/p3r4-net-credential', 'held_code(): the net finds a credential in a layer of a name (two sites)', IM,
  "\t\t\tif ( 'credential' === $net ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r4-net-unreadable', 'held_code(): a name the net does not read to its end (two sites)', IM,
  "\t\t\tif ( 'unreadable' === $net ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r4-over-name-count', 'over_name(): the credentials of every layer (two sites)', NET,
  "\t\t\tif ( array() !== self::credentials( $layer[1], $extra, $undecidable, '/code' === substr( $layer[0], -5 ) ) ) {", "\t\t\tif ( false ) {", 'itpkg', P3['unpack_it'])
e('paket/p3r4-over-name-code', 'over_name(): the layer of code read as code (two sites)', NET,
  "$undecidable, '/code' === substr( $layer[0], -5 ) ) ) {\n\t\t\t\treturn 'credential';", "$undecidable, false ) ) {\n\t\t\t\treturn 'credential';", 'itpkg', P3['unpack_it'])
e('paket/p3r4-over-name-undecidable', 'over_name(): braces that cannot be counted (two sites)', NET,
  "\t\t\tif ( $undecidable ) {\n\t\t\t\treturn 'unreadable';", "\t\t\tif ( false ) {\n\t\t\t\treturn 'unreadable';", 'itpkg', P3['unpack_it'])
ON = "\t\treturn $deep || array() !== $unread ? 'unreadable' : null;"
e('paket/p3r4-over-name-deep', 'over_name(): deeper than the net reads (two sites)', NET, ON, ON.replace("$deep || ", ''), 'itpkg', P3['unpack_it'])
e('paket/p3r4-over-name-unread', 'over_name(): a body that is no text (two sites)', NET, ON, ON.replace(" || array() !== $unread", ''), 'itpkg', P3['unpack_it'])

# ---------------------------------------- part 3: GitHub Codex P2 on PR 132 (a key of before)
P5_IT = 'testImportsOfAKeyOfBeforeHoldNoPlaceOnceTheKeyIsRenewed'
e('paket/p5-held-bound-before-verify', 'held(): a group of another pair is ended before its manifest is checked again (two sites)', IM,
  PRN, "\t\tif ( ! self::bound( $found, AB_MCP_Sync_Anchor::pair_print() ) && ! is_wp_error( AB_MCP_Sync_Manifest::verify( $found['signed'], $expect ) ) ) {", 'itpkg', P5_IT)
# Codex on d51833c and 5f626da, A: no group removed at a beginning; one of another pair counts as open no more.
e('paket/p7-begin-binding', 'begin(): the group opened with the print of its pair (two sites)', IM,
  "\t\t\t\t'binding'  => (string) $print,\n", "", 'itpkg', P5_IT)
e('paket/p7-open-binding', 'open(): the binding kept in the sidecar (two sites)', ST,
  "\t\t\t'binding'  => self::binding( isset( $meta['binding'] ) ? $meta['binding'] : '' ),\n", "\t\t\t'binding'  => '',\n", 'itpkg', P5_IT)
e('paket/p7-open-binding-count', 'open(): the binding of the package counted with (two sites)', ST,
  ", $entry['label'], $entry['binding'] );", ", $entry['label'] );", 'itpkg', P5_IT)
e('paket/p7-read-binding', 'read_entry(): the binding read back (two sites; sidecar(), Behebung 2 of Z8b branch 3: the fields as the file holds them)', ST,
  "\t\treturn array( $area === $e['area'] ? 'own' : 'other', array_intersect_key( $e, self::SIDECAR[ $e['area'] ] ) );", "\t\treturn array( $area === $e['area'] ? 'own' : 'other', array( 'binding' => '' ) + array_intersect_key( $e, self::SIDECAR[ $e['area'] ] ) );", 'itpkg', P5_IT)
e('paket/p7-race-own-end', 'held(): the next call of a package begun with a pair of before ends its own group, the other account keeps its own (two sites)', IM,
  PRN, "\t\tif ( false ) {", 'itpkg', 'testAPackageBegunWhileThePairChangesRemovesNoPackageOfTheNewPair')
P7_UNIT = 'testAnOpenPackageOfAnotherBindingCountsAsOpenNoMore'
SK = "\t\t\tif ( '' !== $binding && '' !== $entry['binding'] && $binding !== $entry['binding'] ) {"
e('paket/p7-count-skip', 'package_refusal(): an open package of another binding counts as open no more', BUD, SK, "\t\t\tif ( false ) {", 'budget', P7_UNIT)
e('paket/p7-count-same', 'package_refusal(): one of the same binding counts', BUD, SK, SK.replace(" && $binding !== $entry['binding']", ''), 'budget', P7_UNIT)
e('paket/p7-count-unbound', 'package_refusal(): one without a binding counts', BUD, SK, SK.replace(" && '' !== $entry['binding']", ''), 'budget', P7_UNIT)
e('paket/p7-count-opened-unbound', 'package_refusal(): one opened without a binding counts every package', BUD, SK, SK.replace("'' !== $binding && ", ''), 'budget', P7_UNIT)
e('paket/p7-count-bytes', 'package_refusal(): its bytes count until it is removed', BUD,
  SK + "\n\t\t\t\tcontinue;", SK + "\n\t\t\t\t$bytes -= $entry['declared'];\n\t\t\t\tcontinue;", 'budget', P7_UNIT)

# ----------------------------------------------------------------- check
# Each run: its suite and the test class it names; an entry runs the test
# its expect names, alone (the class of the integration suite takes more
# than a minute, and the CI runs every entry).
RUNS = {
    'trust': ('unit', 'SyncTrustTest'),
    'storage': ('unit', 'SyncTrustStorageTest'),
    'address': ('unit', 'SyncTrustAddressTest'),
    'manifest': ('unit', 'SyncManifestTest'),
    'status': ('unit', 'StagingStatusTest'),
    'it': ('it', 'SyncTrustTest'),
    'package': ('unit', 'SyncPackageTest'),
    'dbkinds': ('unit', 'SyncDbKindsTest'),
    'gate': ('unit', 'StagingGateTest'),
    'itpkg': ('it', 'SyncPackageTest'),
    'itseries': ('it', 'SyncPackageSeriesTest'),
    'budget': ('unit', 'StoreBudgetTest'),
    'payload': ('unit', 'PayloadFileTest'),
    'uninstall': ('unit', 'UninstallTest'),
}


def test_of(expect):
    return expect.split(' with data set "', 1)[0]


ids = set()
for (id_, clause, file, search, replace, run, expect) in entries:
    if id_ in ids:
        sys.exit('duplicate id ' + id_)
    ids.add(id_)
    text = open(os.path.join(ROOT, file)).read()
    n = text.count(search)
    if n != 1:
        sys.exit('%s: search occurs %d times in %s:\n%s' % (id_, n, file, search))
    if search == replace:
        sys.exit(id_ + ': replace equals search')
    if run not in RUNS:
        sys.exit(id_ + ': no run ' + run)
    suite, cls = RUNS[run]
    path = os.path.join(ROOT, 'tests', 'integration' if 'it' == suite else '', cls + '.php')
    if not re.search(r'\bfunction\s+' + re.escape(test_of(expect)) + r'\s*\(', open(path).read()):
        sys.exit('%s: %s is no test of %s' % (id_, test_of(expect), path))


def php(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('\t', '\\t').replace('\n', '\\n') + '"'


def php_single(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def regex(name):
    import re
    q = lambda t: re.sub(r'([\\/.^$|?*+()\[\]{}#])', r'\\\1', t)
    if ' with data set "' in name:
        test, case = name.split(' with data set "', 1)
        case = case.rstrip('"')
        # A failure names the data set so, a PHP warning or error under
        # «Triggered by» as test#name.
        return '/' + q(test) + '(?: with data set "|#)' + q(case) + '/'
    return '/' + q(name) + '/'


out = []
out.append('''<?php
/**
 * The clauses of the trust between a staging site and its production site
 * (Z8, branch 5 of the plan, part 1: decisions 4 and 5): the key of a
 * staging source and when it is made, kept and forgotten; the route of the
 * key and the pairing code, read back only in their one spelling; the
 * anchor a production site prepares (over https only, through the fetcher,
 * no redirect, for the stored address exactly) and a person confirms with
 * the fingerprint shown; the print of the pair; the signed manifest with
 * every field, its one spelling, its signature with the confirmed key, its
 * target and source exactly, its time, purpose, report and package; each
 * chunk and the whole against their hashes; the forms of wp-admin. Since
 * Codex round 1: the private key in no report of a failed write, the
 * renewal read back exactly, international domain names in either
 * spelling, the port of the stored address of the staging site. Since
 * Codex round 2: this site's own address from home_url() as it is, the
 * port once, no segment . or .. in the path of a fetch. Part 2 (decision
 * 6): the package of the inventory in chunks, the kinds it holds whole, the
 * signed fetch addresses and the route that answers only them, the direct
 * fetch and the relay, taken up again, checked whole; the bound of objects
 * of the inventory; the three tools. Codex round 4: the line of an object
 * from a list of what it may carry, the binding of a package, the import of
 * an account, the secret of the MACs, series and closed packages in the
 * budgets, the time of a fetch, a write that fails. Codex round 5: the MAC
 * checked before the export is loaded, every lock of the credential check,
 * the random value of a binding, the print and the purpose of a continuing
 * import, the retired keys of the anchor and the second confirmation, the
 * secret of the MACs made where it is missing, a making stopped before its
 * record, a write of the store that fails or falls short. Codex round 6: a
 * revocation that cannot be kept changes nothing, the notices and the way
 * «Renew the key», a confirmation that reads back. Codex round 7: «Renew
 * the key» on a POST only. Part 3 (decisions 9 and 8): who makes a package,
 * the rights of each object, complete per kind and subtype, the credentials
 * before the package, the unpacking within the limits and each line checked
 * again at the import. Codex round 1 of part 3: edit_post reads an object,
 * the fallbacks of WordPress for types and taxonomies it does not know, every
 * nested part of a line exactly, one whole gzip with whole lines, what an
 * older version left. Codex round 2 of part 3: every text of a line in its
 * closed form, an empty identity only for a row not read, names in bytes.
 * Codex round 3 of part 3: two levels, the structure refuses a package and
 * the names never; a credential in a name holds its line, with the code of
 * why; the reason of a row the takeover never reads as a code. Codex
 * round 4 of part 3: the net over the raw value over every name, the list
 * of held lines without a value (the SHA-256 of each ref).
 *
 * Written by tests/mutation/gen/paket.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncTrustTest, SyncTrustStorageTest,
 * SyncTrustAddressTest, SyncManifestTest, StagingStatusTest, SyncPackageTest,
 * SyncDbKindsTest, StagingGateTest, PayloadFileTest, UninstallTest) and, for what
 * two WordPress sites show, by the integration suite (the sites running,
 * AB_IT_ROOT set). Where a check could change no outcome (sodium or a
 * check before it refuses the same input), it was taken out of the code
 * rather than kept without a test.
 *
 * @package AlphaBridge_MCP
 */

/*
 * The run of an entry: the test its expect names, alone, in the unit suite
 * or in the integration suite (-c; the sites running). A whole class of
 * the integration suite takes more than a minute, and the CI runs every
 * entry in it (paket.py checks that the test is a method of the class).
 * The filter matches the class after its namespace and the test with or
 * without a data set, as PHPUnit names it (Class::test with data set …).
 */
$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$k  = 'includes/sync/class-sync-key.php';
$a  = 'includes/sync/class-sync-anchor.php';
$m  = 'includes/sync/class-sync-manifest.php';
$f  = 'includes/sync/class-sync-fetcher.php';
$s  = 'includes/class-staging.php';
$ad = 'includes/class-admin.php';
$rc = 'includes/class-rest-controller.php';
$o  = 'includes/class-outbound.php';
$ex = 'includes/sync/class-sync-export.php';
$im = 'includes/sync/class-sync-import.php';
$inv = 'includes/sync/class-sync-inventory.php';
$ts = 'includes/tools/class-tools-staging.php';
$g  = 'includes/class-staging-gate.php';
$bud = 'includes/class-store-budget.php';
$st = 'includes/class-protected-store.php';
$gr = 'includes/sync/class-sync-grant.php';
$pf = 'includes/class-payload-file.php';
$un = 'uninstall.php';
$rt = 'includes/sync/class-sync-export-rights.php';
$os = 'includes/sync/class-sync-option-schema.php';
$dk = 'includes/sync/class-sync-db-kinds.php';
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
open(os.path.join(ROOT, 'tests/mutation/lists/paket.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
