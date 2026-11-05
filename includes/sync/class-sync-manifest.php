<?php
/**
 * The signed manifest of a package (Z8, decision 5 of the plan of
 * 01.11.2026; contract B).
 *
 * WHAT IT SAYS. Every package the staging site makes has one manifest, in
 * the format ab-sync/1, which the staging site signs with its key
 * (AB_MCP_Sync_Key) and the production site checks before it reads one
 * byte of the package (verify()). Its fields, all required, no other:
 *
 *  - format ab-sync/1; canon, the version of the canonical form (canon/1);
 *  - source_id and key_id of the key that signs;
 *  - source and target: the full addresses of the staging site and of the
 *    production site, as AB_MCP_Staging::normalize_url() stores them;
 *  - package_id (32 hex) and purpose: inventory, objects, media or env;
 *  - report_hash: the SHA-256 of the report a package of objects or media
 *    is for, null for inventory and env;
 *  - created and expires, expires exactly TTL (24 hours) after created;
 *  - kinds: the kinds in it with each subtype and whether it is complete
 *    (decision 9), ascending by kind and subtype, each pair once;
 *  - objects (how many) and unpacked (bytes once unpacked);
 *  - chunks: per chunk its bytes and SHA-256, in order; bytes (their sum)
 *    and sha256 (the SHA-256 of the whole package).
 *
 * Limits (decision 8, contract B): a chunk at most MAX_CHUNK (5 MiB), a
 * package at most MAX_BYTES (64 MiB) in chunks, at most MAX_UNPACKED
 * (256 MiB) once unpacked, at most MAX_OBJECTS objects; lists at most
 * MAX_LIST entries, as the plugin takes any list of arguments
 * (AB_MCP_Security::validate_args()).
 *
 * ONE SPELLING. The manifest is the text AB_MCP_Sync_Canon::encode() writes
 * for it (keys sorted, no white space); the signature covers that text
 * behind CONTEXT; and the production site takes only a text that decodes
 * and encodes back to the very same bytes, so a key twice, a float, an
 * escape or any other spelling of the same data is refused, and the data
 * read is the data signed.
 *
 * WHAT THE PRODUCTION SITE CHECKS (verify(), in this order, each with its
 * own refusal and way): the envelope; one spelling; every field; the format
 * and the version of the canonical form; an active anchor (one a person
 * confirmed, AB_MCP_Sync_Anchor); the source id and the key id of that
 * anchor; the signature with its key; the target exactly this site's own
 * address by normalize_url() (scheme, host, port and path: not same_site(),
 * which leaves the scheme out; an international domain name in either
 * spelling, AB_MCP_Sync_Key::site_form()); the source exactly the address
 * of the staging site it is paired with, compared the same way; not
 * expired, and not made later than SKEW from now; the purpose the caller
 * reads it for, and for objects and media the report it is for, so a
 * package is not used for another report; where the caller names it, the
 * package. Then each chunk against its own hash before it is read
 * (chunk()), and the whole against the hash of the package (whole()).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-key.php';
require_once __DIR__ . '/class-sync-anchor.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Manifest
 */
final class AB_MCP_Sync_Manifest {

	/** The format. */
	const FORMAT = 'ab-sync/1';

	/** What the signature covers before the text of the manifest. */
	const CONTEXT = "AlphaBridge ab-sync/1 manifest\n";

	/** What a package is for. */
	const PURPOSES = array( 'inventory', 'objects', 'media', 'env' );

	/** The purposes that are for one report (report_hash). */
	const FOR_REPORT = array( 'objects', 'media' );

	/** Seconds from created to expires: 24 hours. */
	const TTL = 86400;

	/**
	 * Seconds a manifest may be made later than this site's clock says:
	 * what two servers kept by NTP differ by, with room; the margin the
	 * plugin keeps before a planned date too (AB_MCP_Tools_Content, 300 s).
	 */
	const SKEW = 300;

	/** Bytes of a chunk at most: 5 MiB. */
	const MAX_CHUNK = 5242880;

	/** Bytes of a package at most: 64 MiB. */
	const MAX_BYTES = 67108864;

	/** Bytes of a package once unpacked at most: 256 MiB. */
	const MAX_UNPACKED = 268435456;

	/** Objects of a package at most. */
	const MAX_OBJECTS = 5000;

	/** Entries of a list (chunks, kinds) at most, as AB_MCP_Security takes a list. */
	const MAX_LIST = 2000;

	/** Characters of a subtype at most (a ref of a pair, AB_MCP_Sync_Pairs::MAX_REF). */
	const MAX_SUBTYPE = 191;

	/** Bytes of the text of a manifest at most. */
	const MAX_TEXT = 1048576;

	/** The fields of a manifest, sorted. */
	const FIELDS = array( 'bytes', 'canon', 'chunks', 'created', 'expires', 'format', 'key_id', 'kinds', 'objects', 'package_id', 'purpose', 'report_hash', 'sha256', 'source', 'source_id', 'target', 'unpacked' );

	/** The fields the maker of a package names; sign() adds the others. */
	const PACKAGE_FIELDS = array( 'bytes', 'chunks', 'kinds', 'objects', 'package_id', 'purpose', 'report_hash', 'sha256', 'unpacked' );

	/* --------------------------------------------------------------- sign */

	/**
	 * Sign the manifest of a package on the confirmed staging site, for the
	 * production site it is paired with.
	 *
	 * @param array    $package The PACKAGE_FIELDS.
	 * @param int|null $now     Time; now when null.
	 * @return array{manifest:string,signature:string}|WP_Error
	 */
	public static function sign( array $package, $now = null ) {
		if ( AB_MCP_Staging::ROLE_STAGING !== AB_MCP_Staging::role() ) {
			return new WP_Error( 'ab_mcp_sync_manifest_role', 'Only the confirmed staging site signs a package: confirm the role staging site on the page AlphaBridge → Staging of this site first.' );
		}
		$pair = AB_MCP_Staging::record()['pair'];
		// A stored pair of a staging site is always one with a production
		// site (AB_MCP_Staging::record() reads no other).
		if ( null === $pair ) {
			return new WP_Error( 'ab_mcp_sync_manifest_pair', 'A package is signed for the production site this staging site is paired with, and it has none: enter the address of the production site on the page AlphaBridge → Staging of this site first.' );
		}
		$key = AB_MCP_Sync_Key::current();
		if ( null === $key ) {
			return new WP_Error( 'ab_mcp_sync_key_missing', 'This staging site has no usable key: make it on the page AlphaBridge → Staging under «Key of this staging site», confirm it on the production site, then call again.' );
		}
		// Both addresses as the production site compares them
		// (AB_MCP_Sync_Key::site_form()): an international domain name in its
		// xn-- form where PHP has intl.
		$source = AB_MCP_Sync_Key::site_form( home_url() );
		$target = AB_MCP_Sync_Key::site_form( $pair['partner_url'] );
		if ( '' === $source || '' === $target ) {
			return new WP_Error( 'ab_mcp_sync_manifest_address', sprintf( 'No package is signed: the address of this site or of the production site (%s) cannot be read here, an international domain name this server cannot convert. Store the address in its xn-- form (Punycode) on the page AlphaBridge → Staging, and the address of this site under Settings → General.', $pair['partner_url'] ) );
		}
		$fields = array_keys( $package );
		sort( $fields );
		if ( self::PACKAGE_FIELDS !== $fields ) {
			$odd = array_values( array_merge( array_diff( self::PACKAGE_FIELDS, $fields ), array_diff( $fields, self::PACKAGE_FIELDS ) ) );
			return self::shape_refusal( (string) $odd[0] );
		}
		$now      = null === $now ? time() : (int) $now;
		$manifest = array_merge(
			$package,
			array(
				'format'    => self::FORMAT,
				'canon'     => AB_MCP_Sync_Canon::VERSION,
				'source_id' => $key['source_id'],
				'key_id'    => $key['key_id'],
				'source'    => $source,
				'target'    => $target,
				'created'   => $now,
				'expires'   => $now + self::TTL,
			)
		);
		$bad = self::shape( $manifest );
		if ( null !== $bad ) {
			return $bad;
		}
		$text      = AB_MCP_Sync_Canon::encode( $manifest );
		$signature = AB_MCP_Sync_Key::sign( self::CONTEXT . $text, $key['key_id'] );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}
		return array(
			'manifest'  => $text,
			'signature' => AB_MCP_Sync_Key::to_base64( $signature ),
		);
	}

	/* ------------------------------------------------------------- verify */

	/**
	 * Check a signed manifest on the production site, before anything of
	 * its package is read.
	 *
	 * @param mixed    $signed array( 'manifest' => text, 'signature' => base64 ).
	 * @param array    $expect purpose (required); report_hash (required for
	 *                         objects and media); package_id (optional).
	 * @param int|null $now    Time; now when null.
	 * @return array{manifest:array,hash:string}|WP_Error The manifest and the
	 *                                                     SHA-256 of its text.
	 */
	public static function verify( $signed, array $expect, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		// The envelope.
		$keys = is_array( $signed ) ? array_keys( $signed ) : array();
		sort( $keys );
		if ( array( 'manifest', 'signature' ) !== $keys || ! is_string( $signed['manifest'] ) || '' === $signed['manifest'] || strlen( $signed['manifest'] ) > self::MAX_TEXT || ! is_string( $signed['signature'] ) ) {
			return new WP_Error( 'ab_mcp_sync_manifest_envelope', 'The package carries no manifest this site can read: a manifest is its text and its signature, nothing else. Export the package again on the staging site (wp_site_export) and use what it gives.' );
		}
		$text      = $signed['manifest'];
		$signature = AB_MCP_Sync_Key::from_base64( $signed['signature'], AB_MCP_Sync_Key::SIGNATURE_BYTES );

		// One spelling: the text AB_MCP_Sync_Canon::encode() writes for its data.
		$manifest = json_decode( $text, true, 8 );
		if ( ! is_array( $manifest ) || AB_MCP_Sync_Canon::encode( $manifest ) !== $text ) {
			return new WP_Error( 'ab_mcp_sync_manifest_spelling', 'The manifest is not written the one way the staging site writes it, so it is not read: it was changed on the way. Export the package again on the staging site (wp_site_export) and use what it gives, unchanged.' );
		}

		// Every field, the format and the version of the canonical form.
		$bad = self::shape( $manifest );
		if ( null !== $bad ) {
			return $bad;
		}
		$version = AB_MCP_Sync_Canon::version_refusal( $manifest['canon'] );
		if ( null !== $version ) {
			return $version;
		}

		// The anchor a person confirmed, its key, the signature.
		$anchor = AB_MCP_Sync_Anchor::active();
		if ( null === $anchor ) {
			if ( null !== AB_MCP_Sync_Anchor::prepared() ) {
				return new WP_Error( 'ab_mcp_sync_manifest_unconfirmed', 'The key of the staging site is prepared on this site but not confirmed, so no package is read. Ask the person to confirm it on the page AlphaBridge → Staging of this site, comparing its fingerprint with the one the staging site shows, then call again.' );
			}
			return new WP_Error( 'ab_mcp_sync_manifest_no_anchor', 'This site has no confirmed key of a staging site, so no package is read. Ask the person to fetch the key of the staging site, or enter its pairing code, and confirm it on the page AlphaBridge → Staging of this site; then call again.' );
		}
		if ( $manifest['source_id'] !== $anchor['source_id'] || $manifest['key_id'] !== $anchor['key_id'] ) {
			return new WP_Error(
				'ab_mcp_sync_manifest_key',
				sprintf( 'The package is signed with the key %1$s of the source %2$s, but the key confirmed here is %3$s of the source %4$s (the staging site made a new key when its role was confirmed again, or the package comes from another site). Ask the person to fetch the new key and confirm it on the page AlphaBridge → Staging of this site, then export the package again.', $manifest['key_id'], $manifest['source_id'], $anchor['key_id'], $anchor['source_id'] ),
				array(
					'key_id'    => $manifest['key_id'],
					'confirmed' => $anchor['key_id'],
				)
			);
		}
		try {
			// A signature that is no base64 of 64 bytes is '' here, which
			// sodium refuses with an exception (the anchor's key was checked
			// when it was read).
			$valid = sodium_crypto_sign_verify_detached( (string) $signature, self::CONTEXT . $text, (string) AB_MCP_Sync_Key::from_base64( $anchor['public_key'], AB_MCP_Sync_Key::PUBLIC_BYTES ) );
		} catch ( Throwable $e ) {
			$valid = false;
		}
		if ( true !== $valid ) {
			return new WP_Error( 'ab_mcp_sync_manifest_signature', 'The signature of the manifest does not match the confirmed key of the staging site: the manifest was changed on the way or signed by another key. Export the package again on the staging site (wp_site_export) and use what it gives, unchanged.' );
		}

		// For this site, from its staging site, exactly.
		// Compared as AB_MCP_Sync_Key::site_form() gives them (an international
		// domain name in either spelling); an address it cannot read is none.
		// This site's own address goes to site_form() as home_url() gives it:
		// normalize_url() before breaks a name like straße on macOS
		// (parse_url(), AB_MCP_Sync_Key::site_form()).
		$own = home_url();
		$us  = AB_MCP_Sync_Key::site_form( $own );
		if ( '' === $us || AB_MCP_Sync_Key::site_form( $manifest['target'] ) !== $us ) {
			return new WP_Error(
				'ab_mcp_sync_manifest_target',
				sprintf( 'The package is for %1$s, and this site is %2$s: scheme, host, port and path must be the same. Check the address of the production site on the page AlphaBridge → Staging of the staging site, then export the package again.', $manifest['target'], $own ) . AB_MCP_Sync_Key::idn_hint( $manifest['target'], $own ),
				array(
					'target' => $manifest['target'],
					'site'   => $own,
				)
			);
		}
		$from = AB_MCP_Sync_Key::site_form( $manifest['source'] );
		if ( '' === $from || AB_MCP_Sync_Key::site_form( $anchor['partner_url'] ) !== $from ) {
			return new WP_Error(
				'ab_mcp_sync_manifest_source',
				sprintf( 'The package comes from %1$s, and the staging site paired with this site is %2$s: scheme, host, port and path must be the same. Check the addresses on the page AlphaBridge → Staging of both sites, then export the package again.', $manifest['source'], $anchor['partner_url'] ) . AB_MCP_Sync_Key::idn_hint( $manifest['source'], $anchor['partner_url'] ),
				array(
					'source'      => $manifest['source'],
					'partner_url' => $anchor['partner_url'],
				)
			);
		}

		// In time.
		if ( $now >= $manifest['expires'] ) {
			return new WP_Error( 'ab_mcp_sync_manifest_expired', 'The package expired: a package is read for 24 hours after the staging site made it. Export it again on the staging site (wp_site_export).', array( 'expires' => $manifest['expires'] ) );
		}
		if ( $manifest['created'] > $now + self::SKEW ) {
			return new WP_Error( 'ab_mcp_sync_manifest_future', 'The package says it was made later than the clock of this site says it is now, by more than five minutes, so the clock of one of the two sites is wrong. Ask the host of the site whose time is wrong to correct its clock, then export the package again.', array( 'created' => $manifest['created'] ) );
		}

		// For what the caller reads it.
		if ( ! isset( $expect['purpose'] ) || $expect['purpose'] !== $manifest['purpose'] ) {
			return new WP_Error(
				'ab_mcp_sync_manifest_purpose',
				sprintf( 'The package is a package of %1$s and is read only as one. Use the package of the right kind, or export one on the staging site (wp_site_export).', $manifest['purpose'] ),
				array( 'purpose' => $manifest['purpose'] )
			);
		}
		if ( in_array( $manifest['purpose'], self::FOR_REPORT, true ) && ( ! isset( $expect['report_hash'] ) || ! is_string( $expect['report_hash'] ) || ! hash_equals( $manifest['report_hash'], $expect['report_hash'] ) ) ) {
			return new WP_Error( 'ab_mcp_sync_manifest_report', 'The package was made for another report than the one being taken over, and a package is used for its own report only. Export the package again on the staging site for the current report (wp_site_export), then call again.', array( 'report_hash' => $manifest['report_hash'] ) );
		}
		if ( isset( $expect['package_id'] ) && $expect['package_id'] !== $manifest['package_id'] ) {
			return new WP_Error( 'ab_mcp_sync_manifest_package', 'The manifest is the one of another package than the one being read. Use the manifest that came with this package, or export the package again on the staging site (wp_site_export).', array( 'package_id' => $manifest['package_id'] ) );
		}

		return array(
			'manifest' => $manifest,
			'hash'     => hash( 'sha256', $text ),
		);
	}

	/**
	 * A chunk against the manifest, before anything of it is read: its index
	 * in the list and its SHA-256.
	 *
	 * @param array $manifest The manifest verify() gave.
	 * @param mixed $index    Index of the chunk, from 0.
	 * @param mixed $data     Its bytes.
	 * @return true|WP_Error
	 */
	public static function chunk( array $manifest, $index, $data ) {
		if ( ! is_int( $index ) || ! isset( $manifest['chunks'][ $index ] ) ) {
			return new WP_Error( 'ab_mcp_sync_chunk_index', sprintf( 'The package has no chunk %s: its manifest lists the chunks 0 to %d. Ask for a chunk of that list.', is_scalar( $index ) ? (string) $index : '?', count( $manifest['chunks'] ) - 1 ) );
		}
		if ( ! is_string( $data ) || ! hash_equals( $manifest['chunks'][ $index ]['sha256'], hash( 'sha256', $data ) ) ) {
			return new WP_Error( 'ab_mcp_sync_chunk_hash', sprintf( 'Chunk %d is not the chunk the manifest names (its SHA-256 differs), so it is not read. Fetch the chunk again from the staging site; if it differs again, export the package again there (wp_site_export).', $index ), array( 'chunk' => $index ) );
		}
		return true;
	}

	/**
	 * The whole package against its hash, once every chunk is there.
	 *
	 * @param array  $manifest The manifest verify() gave.
	 * @param string $sha256   SHA-256 of the chunks in order.
	 * @return true|WP_Error
	 */
	public static function whole( array $manifest, $sha256 ) {
		if ( ! is_string( $sha256 ) || ! hash_equals( $manifest['sha256'], $sha256 ) ) {
			return new WP_Error( 'ab_mcp_sync_package_hash', 'The package put together from its chunks is not the package the manifest names (its SHA-256 differs), so it is not read. Fetch the package again from the staging site; if it differs again, export it again there (wp_site_export).' );
		}
		return true;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Every field of a manifest as this format has it, or the refusal that
	 * names the first one that is not.
	 *
	 * @param array $m Manifest.
	 * @return WP_Error|null
	 */
	private static function shape( array $m ) {
		$fields = array_keys( $m );
		sort( $fields );
		if ( self::FIELDS !== $fields ) {
			$odd = array_values( array_merge( array_diff( self::FIELDS, $fields ), array_diff( $fields, self::FIELDS ) ) );
			return self::shape_refusal( (string) $odd[0] );
		}
		if ( self::FORMAT !== $m['format'] ) {
			return new WP_Error(
				'ab_mcp_sync_manifest_format',
				sprintf( 'The package is of the format %1$s, and this site reads %2$s. Update AlphaBridge MCP on both sites to the same version, then export the package again on the staging site (wp_site_export).', is_string( $m['format'] ) ? $m['format'] : 'unknown', self::FORMAT ),
				array( 'format' => is_string( $m['format'] ) ? $m['format'] : null )
			);
		}
		$hex32 = static function ( $v ) {
			return is_string( $v ) && 1 === preg_match( AB_MCP_Sync_Key::ID, $v );
		};
		$hex64 = static function ( $v ) {
			return is_string( $v ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $v );
		};
		// normalize_url() gives '' for anything that is no text.
		$site  = static function ( $v ) {
			return '' !== $v && AB_MCP_Staging::normalize_url( $v ) === $v;
		};
		$count = static function ( $v, $max, $min = 0 ) {
			return is_int( $v ) && $v >= $min && $v <= $max;
		};
		$checks = array(
			'canon'      => is_string( $m['canon'] ) && 1 === preg_match( '/^canon\/[1-9][0-9]{0,5}\z/', $m['canon'] ),
			'source_id'  => $hex32( $m['source_id'] ),
			'key_id'     => $hex32( $m['key_id'] ),
			'source'     => $site( $m['source'] ),
			'target'     => $site( $m['target'] ),
			'package_id' => $hex32( $m['package_id'] ),
			'purpose'    => in_array( $m['purpose'], self::PURPOSES, true ),
			'created'    => is_int( $m['created'] ) && $m['created'] > 0,
			'objects'    => $count( $m['objects'], self::MAX_OBJECTS ),
			'unpacked'   => $count( $m['unpacked'], self::MAX_UNPACKED ),
			'sha256'     => $hex64( $m['sha256'] ),
		);
		foreach ( $checks as $field => $ok ) {
			if ( ! $ok ) {
				return self::shape_refusal( $field );
			}
		}
		if ( in_array( $m['purpose'], self::FOR_REPORT, true ) ? ! $hex64( $m['report_hash'] ) : null !== $m['report_hash'] ) {
			return self::shape_refusal( 'report_hash' );
		}
		if ( $m['expires'] !== $m['created'] + self::TTL ) {
			return self::shape_refusal( 'expires' );
		}
		if ( ! is_array( $m['kinds'] ) || ! array_is_list( $m['kinds'] ) || count( $m['kinds'] ) > self::MAX_LIST ) {
			return self::shape_refusal( 'kinds' );
		}
		$last = null;
		foreach ( $m['kinds'] as $entry ) {
			$keys = is_array( $entry ) ? array_keys( $entry ) : array();
			sort( $keys );
			if ( array( 'complete', 'kind', 'subtype' ) !== $keys || ! is_bool( $entry['complete'] )
				|| ! is_string( $entry['kind'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $entry['kind'] )
				|| ! is_string( $entry['subtype'] ) || 1 !== preg_match( '//u', $entry['subtype'] ) || preg_match( '/[\x00-\x1F\x7F]/', $entry['subtype'] ) || mb_strlen( $entry['subtype'], 'UTF-8' ) > self::MAX_SUBTYPE ) {
				return self::shape_refusal( 'kinds' );
			}
			// Ascending by kind, then subtype, each pair once: one spelling of the set.
			if ( null !== $last && ( strcmp( $last['kind'], $entry['kind'] ) > 0 || ( $last['kind'] === $entry['kind'] && strcmp( $last['subtype'], $entry['subtype'] ) >= 0 ) ) ) {
				return self::shape_refusal( 'kinds' );
			}
			$last = $entry;
		}
		if ( ! is_array( $m['chunks'] ) || ! array_is_list( $m['chunks'] ) || array() === $m['chunks'] || count( $m['chunks'] ) > self::MAX_LIST ) {
			return self::shape_refusal( 'chunks' );
		}
		$sum = 0;
		foreach ( $m['chunks'] as $chunk ) {
			$keys = is_array( $chunk ) ? array_keys( $chunk ) : array();
			sort( $keys );
			if ( array( 'bytes', 'sha256' ) !== $keys || ! $count( $chunk['bytes'], self::MAX_CHUNK, 1 ) || ! $hex64( $chunk['sha256'] ) ) {
				return self::shape_refusal( 'chunks' );
			}
			$sum += $chunk['bytes'];
		}
		if ( ! $count( $m['bytes'], self::MAX_BYTES, 1 ) || $m['bytes'] !== $sum ) {
			return self::shape_refusal( 'bytes' );
		}
		return null;
	}

	/**
	 * @param string $field The first field that is not as the format has it.
	 * @return WP_Error
	 */
	private static function shape_refusal( $field ) {
		return new WP_Error(
			'ab_mcp_sync_manifest_shape',
			sprintf( 'The field %s of the manifest is missing, not allowed, or not as the format ab-sync/1 has it, so the package is not read. Update AlphaBridge MCP on both sites to the same version, then export the package again on the staging site (wp_site_export).', $field ),
			array( 'field' => $field )
		);
	}
}
