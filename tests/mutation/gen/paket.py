#!/usr/bin/env python3
"""Writes tests/mutation/lists/paket.php: one entry per clause of the trust
of the takeover (Z8 branch 5): the key of a staging source, the anchor, the
fetcher, the signed manifest and the forms of wp-admin. Every search is
checked to occur exactly once in its file before the list is written.

    python3 tests/mutation/gen/paket.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php). Development only: /tests ships with no release.
"""
import os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
K = 'includes/sync/class-sync-key.php'
A = 'includes/sync/class-sync-anchor.php'
M = 'includes/sync/class-sync-manifest.php'
F = 'includes/sync/class-sync-fetcher.php'
S = 'includes/class-staging.php'
AD = 'includes/class-admin.php'
RC = 'includes/class-rest-controller.php'
O = 'includes/class-outbound.php'
FILEVAR = {K: '$k', A: '$a', M: '$m', F: '$f', S: '$s', AD: '$ad', RC: '$rc', O: '$o'}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    # The runner takes no empty replacement: a removed clause leaves a comment.
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append((id_, clause, file, search, replace, run, expect))


T = '\t'

# ------------------------------------------------------------------ key
RB = "\t\tif ( null === $found || $found['key']['source_id'] !== $source || $found['key']['key_id'] !== $key_id ) {"
STORAGE_RB = 'testARenewalTheOptionsDoNotKeepConfirmsNothingAndLeavesNoKey with data set "%s"'
e('paket/key-renew-found', 'renew(): a key must read back', K, RB, RB.replace("null === $found || ", ''),
  'storage', STORAGE_RB % 'only the private key refused')
e('paket/key-renew-source-id', 'renew(): the source id it made reads back', K, RB, RB.replace(" || $found['key']['source_id'] !== $source", ''),
  'storage', STORAGE_RB % 'another source id kept')
e('paket/key-renew-key-id', 'renew(): the key it made reads back, not the old one', K, RB, RB.replace(" || $found['key']['key_id'] !== $key_id", ''),
  'storage', STORAGE_RB % 'the old key under the new source')
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
  "\t\t\tupdate_option( self::OPTION_SECRET, $value, false );",
  "\t\t\tupdate_option( self::OPTION_SECRET, $value, true );",
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
  "\t\tif ( null !== $key ) {\n\t\t\treturn array(\n\t\t\t\t'made' => false,",
  "\t\tif ( false ) {\n\t\t\treturn array(\n\t\t\t\t'made' => false,",
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
  'address', 'testANameNormalizeUrlChangedWhenItWasStoredIsRefusedWithTheWay')

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
  "\t\tif ( $pair_changed ) {\n\t\t\tAB_MCP_Sync_Anchor::forget();\n\t\t}",
  "",
  'trust', 'testAnotherPairOrRoleTakesTheAnchorAway')
e('paket/staging-remove-pair-forgets-anchor', 'remove_pair(): the hub taking the pair back takes the anchor', S,
  "\t\tAB_MCP_Sync_Anchor::forget();\n\t\treturn $had;",
  "\t\treturn $had;",
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
  "\t\treturn null !== $active && $active['partner_url'] === self::partner() ? $active : null;",
  "\t\treturn $active;",
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
e('paket/manifest-target-own', 'verify(): this site\'s own address from home_url() as it is, not after normalize_url()', M,
  "\t\t$own = home_url();", "\t\t$own = AB_MCP_Staging::normalize_url( home_url() );", 'address', 'testThisSiteReadsItsOwnNameAsHomeUrlGivesIt')
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

# ----------------------------------------------------------------- check
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
 * port once, no segment . or .. in the path of a fetch.
 *
 * Written by tests/mutation/gen/paket.py from the source; change the
 * entries there and run it again.
 *
 * Held by the unit suite (SyncTrustTest, SyncTrustStorageTest,
 * SyncTrustAddressTest, SyncManifestTest, StagingStatusTest) and, for what
 * two WordPress sites show, by the integration suite (the sites running,
 * AB_IT_ROOT set). Where a check could change no outcome (sodium or a
 * check before it refuses the same input), it was taken out of the code
 * rather than kept without a test.
 *
 * @package AlphaBridge_MCP
 */

$trust    = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncTrustTest' );
$storage  = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncTrustStorageTest' );
$address  = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncTrustAddressTest' );
$manifest = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'SyncManifestTest' );
$status   = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'StagingStatusTest' );
$it       = array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', 'SyncTrustTest' );

$k  = 'includes/sync/class-sync-key.php';
$a  = 'includes/sync/class-sync-anchor.php';
$m  = 'includes/sync/class-sync-manifest.php';
$f  = 'includes/sync/class-sync-fetcher.php';
$s  = 'includes/class-staging.php';
$ad = 'includes/class-admin.php';
$rc = 'includes/class-rest-controller.php';
$o  = 'includes/class-outbound.php';

return array(''')
for (id_, clause, file, search, replace, run, expect) in entries:
    out.append('\tarray(')
    out.append("\t\t'id'      => %s," % php_single(id_))
    out.append("\t\t'clause'  => %s," % php_single(clause))
    out.append("\t\t'file'    => %s," % FILEVAR[file])
    out.append("\t\t'search'  => %s," % php(search))
    out.append("\t\t'replace' => %s," % php(replace))
    out.append("\t\t'run'     => $%s," % run)
    out.append("\t\t'expect'  => %s," % php_single(regex(expect)))
    out.append('\t),')
out.append(');')
open(os.path.join(ROOT, 'tests/mutation/lists/paket.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')
