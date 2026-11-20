<?php
/*
 * Fixed copy for the test suite only (Z8b branch 5, R12b part 1a, F3-4):
 * AB_MCP_Sync_Net::keyed() as it stood at commit 8daf33f
 * (8daf33fb958edf39b835c016fdde54eb78d40018, includes/sync/class-sync-net.php
 * lines 790 to 833), doc comment and body unchanged below. It is the
 * reference of tests/SyncNetKeyedSameTest.php: the new keyed() answers the
 * same for every input. Nothing of the plugin calls it; tests/ is not
 * shipped (.distignore).
 *
 * @package AlphaBridge_MCP
 */

/**
 * The reference: keyed() of 8daf33f.
 */
final class AB_Test_Keyed_8daf33f {

	/**
	 * Whether a name of the environment (a file, a folder, a table) holds the
	 * name of a credential right before an =, a :, a ; or a quote (" or '),
	 * white space between them allowed, whatever follows (a value, «;»,
	 * nothing): a name of a file or a table is no prose, so a key in it is
	 * one (Codex, rounds 2 and 3 of branch 7, A1); the ; and the quotes end a
	 * key of a serialized array or a quoted key (s:8:"password";…,
	 * {"password" : …}), which the content check may read as clean. Each sign
	 * on its own, so that no match before takes a part of the name of the
	 * next (note:password:…). There, the text
	 * before it ends in a name of the credential check (CREDENTIAL_EXACT,
	 * EXTRA_NAMES, ab_mcp_redact_keys), in lower case or as normalize_key()
	 * reads it (xpassword:, my-auth:, my vault=), or its last word names one
	 * as the check does (names_credential(): CREDENTIAL_NEEDLES anywhere in
	 * it, so one at its end too, and EXTRA_LAST; oauth_server:, api_key.v1:).
	 * «reset-password» or «note:EXAMPLE.php» holds none;
	 * «reset-password:guide.php», «reset-password;v2.php» or «O'Reilly
	 * password's.pdf» does (fail closed, rare: GRENZEN-BERICHT, branch 7, C).
	 *
	 * @param string   $text  A layer of the name.
	 * @param string[] $extra AB_MCP_Redactor::extra_keys().
	 * @return bool
	 */
	public static function keyed( $text, array $extra ) {
		$text = (string) $text;
		if ( ! preg_match_all( '/[=:;"\']/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		$keys = array_merge( AB_MCP_Redactor::CREDENTIAL_EXACT, AB_MCP_Redactor::EXTRA_NAMES, $extra );
		foreach ( $m[0] as $hit ) {
			$before = rtrim( (string) substr( $text, 0, (int) $hit[1] ), " \t\n\r\x0B\f" );
			foreach ( array( strtolower( $before ), AB_MCP_Redactor::normalize_key( $before ) ) as $form ) {
				foreach ( $keys as $key ) {
					if ( '' !== (string) $key && str_ends_with( $form, (string) $key ) ) {
						return true;
					}
				}
			}
			if ( preg_match( '/[A-Za-z0-9_\-.\x80-\xFF]+\z/', $before, $word ) && AB_MCP_Sync_Credentials::names_credential( $word[0], $extra ) ) {
				return true;
			}
		}
		return false;
	}
}
