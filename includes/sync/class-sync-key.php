<?php
/**
 * The key of a staging source (Z8, decision 4 of the plan of 01.11.2026).
 *
 * When a person confirms the role staging site (AB_MCP_Staging::save()
 * writes the role as staging, on the page AlphaBridge → Staging or from the
 * hub), the site gets a new source id and a new Ed25519 key pair
 * (sodium_crypto_sign_keypair(); WordPress brings sodium_compat where PHP
 * lacks the extension): renew(). A site whose role is anything else keeps
 * neither (forget()). A staging site whose role was confirmed before there
 * were keys has none until a person makes it on that page (make()), which
 * never replaces a key that is there.
 *
 * WHERE. Two options of their own, without autoload:
 *  - OPTION_SOURCE: the source id, the key id, the public key, when made;
 *  - OPTION_SECRET: the private key and the key id it belongs to, read by
 *    this class only to check and to sign, never put into an answer, a
 *    report, a package, the log or a page (tests hold that). The option
 *    guard keeps both from the option tools (an ab_mcp_ name is the
 *    plugin's own, AB_MCP_Option_Guard).
 * They are written one after the other, so a key counts only where both
 * agree: the private key's key id is the public key's, and the private key
 * carries exactly that public key (sodium_crypto_sign_publickey_from_secretkey()).
 * Anything else reads as no key (current() null), signs nothing and is
 * shown to nobody; the page offers to make a new one.
 *
 * IDS. The source id is 16 random bytes in hex. The key id is the first 32
 * hex characters of the SHA-256 of the public key, so it names that key and
 * no other. The fingerprint a person compares on both sites is the SHA-256
 * of format, source id, key id and public key, its first 32 hex characters
 * in capitals and groups of four (fingerprint()).
 *
 * WHAT ANOTHER SITE LEARNS. The route alphabridge/v1/staging-key answers the
 * public part to anyone (answer(), rest_key()), so that the production site
 * can fetch it over https before a person confirms it there; a site that is
 * not a confirmed staging site with a key answers 404 and says so. The
 * pairing code is the same public part as one string, for a person to carry
 * over by hand where the production site cannot ask (pairing_code()); the
 * production site reads either with read_answer() or read_code(), which
 * accept exactly what this class writes.
 *
 * Base64 is PHP's (base64_encode(), base64_decode() in its strict mode), and
 * a text counts only where encoding the bytes again gives it back
 * unchanged: one spelling per key and code. The round trip, not the
 * decoder, makes it strict: PHP's decoder takes white space, a missing
 * padding and bits that encode nothing, and so does sodium_compat where
 * WordPress brings it.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-staging.php';

/**
 * Class AB_MCP_Sync_Key
 */
final class AB_MCP_Sync_Key {

	/** The public part: source id, key id, public key, when made. No autoload. */
	const OPTION_SOURCE = 'ab_mcp_sync_source';

	/** The private key and its key id. No autoload; read only here. */
	const OPTION_SECRET = 'ab_mcp_sync_secret';

	/** The format of the public part, the route's answer and the pairing code. */
	const FORMAT = 'ab-sync-key/1';

	/** The REST route of the key, under AB_MCP_REST_NAMESPACE. */
	const ROUTE = '/staging-key';

	/** Bytes of an Ed25519 public key, private key and signature. */
	const PUBLIC_BYTES    = 32;
	const SECRET_BYTES    = 64;
	const SIGNATURE_BYTES = 64;

	/** A source id and a key id: 32 hex characters in lower case. */
	const ID = '/^[0-9a-f]{32}\z/';

	/** At most this many characters in a pairing code. */
	const MAX_CODE = 2048;

	/** The fields of the pairing code, in their order. */
	const CODE_FIELDS = array( 'format', 'site', 'source_id', 'key_id', 'public_key' );

	/* ------------------------------------------------------------- make */

	/**
	 * A new source id and key pair, replacing what is there. Only on a
	 * confirmed staging site: AB_MCP_Staging::save() calls it right after it
	 * wrote that role.
	 *
	 * @param int|null $now Time; now when null.
	 * @return array|WP_Error The public part (current()).
	 */
	public static function renew( $now = null ) {
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return self::role_refusal();
		}
		try {
			$pair   = sodium_crypto_sign_keypair();
			$secret = sodium_crypto_sign_secretkey( $pair );
			$public = sodium_crypto_sign_publickey( $pair );
			$source = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_key_failed', 'No key was made: PHP could not make an Ed25519 key pair or random bytes on this server. Ask the host to enable the sodium extension of PHP, then make the key on the page AlphaBridge → Staging.' );
		}
		$key_id = self::key_id( $public );
		update_option(
			self::OPTION_SECRET,
			array(
				'key_id'     => $key_id,
				'secret_key' => self::to_base64( $secret ),
			),
			false
		);
		update_option(
			self::OPTION_SOURCE,
			array(
				'format'     => self::FORMAT,
				'source_id'  => $source,
				'key_id'     => $key_id,
				'public_key' => self::to_base64( $public ),
				'created'    => null === $now ? time() : (int) $now,
			),
			false
		);
		$made = self::current();
		return null !== $made ? $made : new WP_Error( 'ab_mcp_sync_key_failed', 'The key was made but does not read back: the options of this site did not keep it. Check the database (wp_get_error_log), then make the key again on the page AlphaBridge → Staging.' );
	}

	/**
	 * The key of a confirmed staging site that has none, made by a person on
	 * the page AlphaBridge → Staging. A key that is there stays: replacing it
	 * would take the confirmation of the production site away.
	 *
	 * @return array{made:bool,key:array}|WP_Error
	 */
	public static function make() {
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return self::role_refusal();
		}
		$key = self::current();
		if ( null !== $key ) {
			return array(
				'made' => false,
				'key'  => $key,
			);
		}
		$key = self::renew();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return array(
			'made' => true,
			'key'  => $key,
		);
	}

	/**
	 * Remove the key: the site is no staging site any more.
	 */
	public static function forget() {
		delete_option( self::OPTION_SECRET );
		delete_option( self::OPTION_SOURCE );
	}

	/* ------------------------------------------------------------- read */

	/**
	 * The key of this site where both options agree, or null.
	 *
	 * @return array{source_id:string,key_id:string,public_key:string,fingerprint:string,created:int}|null
	 */
	public static function current() {
		$found = self::found();
		return null !== $found ? $found['key'] : null;
	}

	/**
	 * Sign bytes with the private key of this site, if it is the key named.
	 * The caller puts the source id and the key id of current() into the
	 * bytes first; a key that changed in between signs nothing.
	 *
	 * @param string $message Bytes.
	 * @param string $key_id  The key id the bytes name.
	 * @return string|WP_Error The detached signature (64 bytes).
	 */
	public static function sign( $message, $key_id ) {
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return self::role_refusal();
		}
		$found = self::found();
		if ( null === $found ) {
			return new WP_Error( 'ab_mcp_sync_key_missing', 'This staging site has no usable key: make it on the page AlphaBridge → Staging under «Key of this staging site», confirm it on the production site, then call again.' );
		}
		if ( ! is_string( $key_id ) || ! hash_equals( $found['key']['key_id'], $key_id ) ) {
			return new WP_Error( 'ab_mcp_sync_key_changed', 'The key of this staging site changed while the package was signed; nothing was signed. Call again: the new key is used, and the production site needs it confirmed.' );
		}
		try {
			return sodium_crypto_sign_detached( (string) $message, $found['secret'] );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ab_mcp_sync_key_failed', 'Nothing was signed: PHP could not sign with the key of this site. Ask the host to enable the sodium extension of PHP, then call again.' );
		}
	}

	/**
	 * What the route answers and the page shows: the public part of the key
	 * of a confirmed staging site and its own address, or null.
	 *
	 * @return array{format:string,site:string,source_id:string,key_id:string,public_key:string,fingerprint:string}|null
	 */
	public static function answer() {
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return null;
		}
		$key  = self::current();
		$site = AB_MCP_Staging::normalize_url( home_url() );
		if ( null === $key || '' === $site ) {
			return null;
		}
		return array(
			'format'      => self::FORMAT,
			'site'        => $site,
			'source_id'   => $key['source_id'],
			'key_id'      => $key['key_id'],
			'public_key'  => $key['public_key'],
			'fingerprint' => $key['fingerprint'],
		);
	}

	/**
	 * The pairing code: answer() without the fingerprint (it follows from the
	 * rest) as JSON, in base64. Null where answer() is.
	 *
	 * @return string|null
	 */
	public static function pairing_code() {
		$answer = self::answer();
		if ( null === $answer ) {
			return null;
		}
		$json = wp_json_encode( array_intersect_key( $answer, array_flip( self::CODE_FIELDS ) ), JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? base64_encode( $json ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a code to copy, of public data.
	}

	/* ------------------------------------------------- read another site */

	/**
	 * A pairing code a person entered: exactly what pairing_code() writes.
	 *
	 * @param mixed $code As entered; white space around it is left out.
	 * @return array{site:string,source_id:string,key_id:string,public_key:string,fingerprint:string}|WP_Error
	 */
	public static function read_code( $code ) {
		$code = is_string( $code ) ? trim( $code ) : '';
		if ( strlen( $code ) > self::MAX_CODE ) {
			return self::code_refusal();
		}
		$json = base64_decode( $code, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the pairing code, read back in its one spelling.
		// base64_decode() gives false for a character out of base64, which
		// encodes as '' and so fails the round trip too.
		if ( base64_encode( (string) $json ) !== $code ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the round trip.
			return self::code_refusal();
		}
		$data = json_decode( $json, true, 4 );
		if ( ! is_array( $data ) || self::CODE_FIELDS !== array_keys( $data ) || wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) !== $json ) {
			return self::code_refusal();
		}
		$offer = self::offer( $data );
		return null !== $offer ? $offer : self::code_refusal();
	}

	/**
	 * The body of the route's answer, as the production site fetched it:
	 * the fields of answer(), each as answer() writes it, the fingerprint
	 * the one of the key.
	 *
	 * @param string $body Body.
	 * @return array{site:string,source_id:string,key_id:string,public_key:string,fingerprint:string}|WP_Error
	 */
	public static function read_answer( $body ) {
		$data   = json_decode( (string) $body, true, 4 );
		$fields = array_merge( self::CODE_FIELDS, array( 'fingerprint' ) );
		sort( $fields );
		$keys = is_array( $data ) ? array_keys( $data ) : array();
		sort( $keys );
		if ( $fields !== $keys ) {
			return self::answer_refusal();
		}
		$offer = self::offer( $data );
		if ( null === $offer || ! is_string( $data['fingerprint'] ) || ! hash_equals( $offer['fingerprint'], $data['fingerprint'] ) ) {
			return self::answer_refusal();
		}
		return $offer;
	}

	/**
	 * The public part of a key as a record holds it, checked: a source id
	 * and a key id of 32 hex, a public key of 32 bytes in its one base64
	 * spelling, the key id the one of that key. With the fingerprint.
	 *
	 * @param mixed $raw Record.
	 * @return array{source_id:string,key_id:string,public_key:string,fingerprint:string}|null
	 */
	public static function key_record( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['source_id'], $raw['key_id'], $raw['public_key'] ) ) {
			return null;
		}
		if ( ! is_string( $raw['source_id'] ) || 1 !== preg_match( self::ID, $raw['source_id'] ) || ! is_string( $raw['key_id'] ) ) {
			return null;
		}
		$public = self::from_base64( $raw['public_key'], self::PUBLIC_BYTES );
		if ( null === $public || ! hash_equals( self::key_id( $public ), $raw['key_id'] ) ) {
			return null;
		}
		return array(
			'source_id'   => $raw['source_id'],
			'key_id'      => $raw['key_id'],
			'public_key'  => $raw['public_key'],
			'fingerprint' => self::fingerprint( $raw['source_id'], $raw['key_id'], $raw['public_key'] ),
		);
	}

	/* ------------------------------------------------------------- ids */

	/**
	 * @param string $public Public key, raw.
	 * @return string The key id: the first 32 hex of its SHA-256.
	 */
	public static function key_id( $public ) {
		return substr( hash( 'sha256', (string) $public ), 0, 32 );
	}

	/**
	 * The fingerprint a person compares: SHA-256 of format, source id, key
	 * id and public key, one per line; the first 32 hex in capitals, in
	 * groups of four.
	 *
	 * @param string $source_id  Source id.
	 * @param string $key_id     Key id.
	 * @param string $public_key Public key, base64.
	 * @return string
	 */
	public static function fingerprint( $source_id, $key_id, $public_key ) {
		$hex = strtoupper( substr( hash( 'sha256', self::FORMAT . "\n" . $source_id . "\n" . $key_id . "\n" . $public_key ), 0, 32 ) );
		return implode( ' ', str_split( $hex, 4 ) );
	}

	/**
	 * @param string $raw Bytes.
	 * @return string Base64 with padding.
	 */
	public static function to_base64( $raw ) {
		return base64_encode( (string) $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- keys and signatures as text.
	}

	/**
	 * Base64 back to bytes, only in its one spelling and length.
	 *
	 * @param mixed $text  Base64.
	 * @param int   $bytes How many bytes it must hold.
	 * @return string|null
	 */
	public static function from_base64( $text, $bytes ) {
		if ( ! is_string( $text ) ) {
			return null;
		}
		$raw = base64_decode( $text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- read back in its one spelling.
		if ( strlen( (string) $raw ) !== (int) $bytes || self::to_base64( $raw ) !== $text ) {
			return null;
		}
		return $raw;
	}

	/* ------------------------------------------------------- REST route */

	/**
	 * GET alphabridge/v1/staging-key: the public part of the key, to anyone;
	 * 404 on a site that is not a confirmed staging site with a key. Nothing
	 * is written, nothing counted; not to be stored on the way.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_key( $request ) {
		unset( $request );
		$answer = self::answer();
		if ( null === $answer ) {
			return new WP_Error( 'ab_mcp_staging_key_none', 'This site has no key of a staging site to give: it is not confirmed as the staging site, or its key is not made yet. On this site, confirm the role staging site and make its key on the page AlphaBridge → Staging; or use the address of the staging site.', array( 'status' => 404 ) );
		}
		$response = new WP_REST_Response( $answer );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/* ---------------------------------------------------------- private */

	/**
	 * The key of this site with its private key, read once, where both
	 * options agree; else null.
	 *
	 * @return array{key:array,secret:string}|null
	 */
	private static function found() {
		$source = get_option( self::OPTION_SOURCE, null );
		if ( ! is_array( $source ) || ! isset( $source['format'] ) || self::FORMAT !== $source['format'] ) {
			return null;
		}
		$key = self::key_record( $source );
		if ( null === $key ) {
			return null;
		}
		$secret = self::secret( $key['key_id'], $key['public_key'] );
		if ( null === $secret ) {
			return null;
		}
		$key['created'] = isset( $source['created'] ) && is_int( $source['created'] ) ? $source['created'] : 0;
		return array(
			'key'    => $key,
			'secret' => $secret,
		);
	}

	/**
	 * The private key of the key named, where the option holds it for that
	 * key id and it carries exactly that public key.
	 *
	 * @param string $key_id     Key id.
	 * @param string $public_key Public key, base64.
	 * @return string|null Raw private key.
	 */
	private static function secret( $key_id, $public_key ) {
		$raw = get_option( self::OPTION_SECRET, null );
		if ( ! is_array( $raw ) || ! isset( $raw['key_id'], $raw['secret_key'] ) || ! is_string( $raw['key_id'] ) || ! hash_equals( (string) $key_id, $raw['key_id'] ) ) {
			return null;
		}
		// A private key that is no base64 of 64 bytes is '' here, which sodium
		// refuses with an exception.
		$secret = (string) self::from_base64( $raw['secret_key'], self::SECRET_BYTES );
		try {
			$carried = sodium_crypto_sign_publickey_from_secretkey( $secret );
		} catch ( Throwable $e ) {
			return null;
		}
		return hash_equals( (string) self::from_base64( $public_key, self::PUBLIC_BYTES ), $carried ) ? $secret : null;
	}

	/**
	 * What a pairing code or an answer of the route offers: the format, the
	 * address of the staging site as normalize_url() stores it, the key.
	 *
	 * @param array $data Decoded fields.
	 * @return array{site:string,source_id:string,key_id:string,public_key:string,fingerprint:string}|null
	 */
	private static function offer( array $data ) {
		// Both callers took exactly the fields; normalize_url() gives '' for
		// anything that is no text.
		if ( self::FORMAT !== $data['format'] ) {
			return null;
		}
		if ( '' === $data['site'] || AB_MCP_Staging::normalize_url( $data['site'] ) !== $data['site'] ) {
			return null;
		}
		$key = self::key_record( $data );
		if ( null === $key ) {
			return null;
		}
		return array_merge( array( 'site' => $data['site'] ), $key );
	}

	/**
	 * @return WP_Error This site is no confirmed staging site.
	 */
	private static function role_refusal() {
		return new WP_Error( 'ab_mcp_sync_key_role', 'Only a confirmed staging site has a key: confirm the role staging site on the page AlphaBridge → Staging of this site first.' );
	}

	/**
	 * @return WP_Error The pairing code does not read.
	 */
	private static function code_refusal() {
		return new WP_Error( 'ab_mcp_sync_key_code', 'The pairing code does not read as the code of a staging site. Copy it again in full from the page AlphaBridge → Staging of the staging site (under «Key of this staging site») and enter it again.' );
	}

	/**
	 * @return WP_Error The answer of the route does not read.
	 */
	private static function answer_refusal() {
		return new WP_Error( 'ab_mcp_sync_key_answer', 'The staging site answered, but not with a key this site can read. Update AlphaBridge MCP on the staging site to the version of this site, or enter its pairing code from its page AlphaBridge → Staging instead.' );
	}
}
