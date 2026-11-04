<?php
/**
 * The trust anchor of a production site (Z8, decision 4 of the plan of
 * 01.11.2026): which key of its staging site it accepts packages from, and
 * the print of the whole pair that plans and runs are bound to.
 *
 * PREPARED, THEN ACTIVE. The production site prepares an anchor in one of
 * two ways: it fetches the key from the route alphabridge/v1/staging-key of
 * its partner (prepare_from_partner(): the address built from the stored
 * address of the other site only, https only, through the fetcher, which
 * asks over AB_MCP_Outbound::get() and follows no redirect), or it reads the
 * pairing code a person entered (prepare_from_code()). Either way the key
 * must name the stored address of the other site exactly as
 * AB_MCP_Staging::normalize_url() stores it. A prepared anchor is a
 * proposal: nothing is accepted with it. It becomes ACTIVE only when a
 * person confirms it on the page AlphaBridge → Staging with the fingerprint
 * the staging site shows on its own page (confirm(), called from wp-admin
 * behind its nonce and manage_options, never from a tool or a route); a new
 * key is prepared and confirmed the same way, and the active one stays
 * until then. The route of the hub and any tool can at most prepare
 * (finding R2-1 of the plan).
 *
 * BOUND TO THE PAIR. An anchor holds for the other site it was made for: a
 * production site whose role or pair changes loses it (forget(), from
 * AB_MCP_Staging), and active() reads one only while this site is the
 * confirmed production site paired with that very address as its staging
 * site.
 *
 * THE PRINT (pair_print()): the SHA-256 over this site's own address, the
 * confirmed role with who, when and where, the pair likewise, and the
 * active anchor with who confirmed it and when. A new confirmation of the
 * role, another pair or a new anchor gives a new print; the plans and runs
 * of the takeover hold the print they began with (decision 15), so theirs
 * no longer matches, and a package signed with the key of an older anchor
 * is refused by its key id (AB_MCP_Sync_Manifest::verify()).
 *
 * Stored in one option without autoload (OPTION); a part that is not
 * exactly what this class writes reads as absent.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way. The page shows them as they are until the
 * texts of the takeover are translated with its interface (branches 12, 13).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-staging.php';
require_once __DIR__ . '/class-sync-key.php';
require_once __DIR__ . '/class-sync-fetcher.php';

/**
 * Class AB_MCP_Sync_Anchor
 */
final class AB_MCP_Sync_Anchor {

	/** The anchor: prepared and active, each with the key and where it came from. No autoload. */
	const OPTION = 'ab_mcp_sync_anchor';

	/** The format of the option. */
	const FORMAT = 'ab-sync-anchor/1';

	/** The format of the pair print. */
	const PRINT_FORMAT = 'ab-pair-print/1';

	/** How a key came to this site. */
	const VIA = array( 'route', 'code' );

	/** Seconds the fetch of the key waits. */
	const TIMEOUT = 10;

	/** At most this many bytes of the answer are read. */
	const MAX_ANSWER = 8192;

	/* ------------------------------------------------------------ prepare */

	/**
	 * Fetch the key from the stored address of the staging site and prepare
	 * it. Over https only, through the fetcher, no redirect.
	 *
	 * @param int         $user_id Who asked for it (0 for no person).
	 * @param object|null $fetcher Object whose get( $url, $args ) asks the
	 *                             other site; AB_MCP_Sync_Fetcher when null.
	 * @return array|WP_Error The prepared anchor.
	 */
	public static function prepare_from_partner( $user_id, $fetcher = null ) {
		$partner = self::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		if ( 0 !== strpos( $partner, 'https://' ) ) {
			return new WP_Error( 'ab_mcp_sync_anchor_not_https', 'The key of the staging site is fetched only over https, and the address of the staging site stored here is not an https:// address. Enter the pairing code the staging site shows on its page AlphaBridge → Staging instead, or store its https:// address on both sites.', array( 'partner_url' => $partner ) );
		}
		$url      = self::key_url( $partner );
		$fetcher  = is_object( $fetcher ) ? $fetcher : new AB_MCP_Sync_Fetcher();
		$response = $fetcher->get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_ANSWER,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'AlphaBridge-MCP/' . AB_MCP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ab_mcp_sync_anchor_unreached',
				sprintf( 'The key of the staging site could not be fetched from %1$s: %2$s Check that the staging site answers (wp_staging_status with check_partner=true), or enter its pairing code from its page AlphaBridge → Staging instead.', $url, $response->get_error_message() ),
				array(
					'url'   => $url,
					'cause' => $response->get_error_code(),
				)
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error(
				'ab_mcp_sync_anchor_no_key',
				sprintf( 'The staging site did not give its key at %1$s (HTTP %2$d; a redirect is not followed). On the staging site, confirm the role staging site and make its key on its page AlphaBridge → Staging, and update AlphaBridge MCP there to the version of this site; then fetch again, or enter its pairing code instead.', $url, $status ),
				array(
					'url'    => $url,
					'status' => $status,
				)
			);
		}
		$key = AB_MCP_Sync_Key::read_answer( (string) wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return self::hold( $key, $partner, 'route', $user_id );
	}

	/**
	 * Prepare the key of a pairing code a person entered.
	 *
	 * @param mixed $code    The code.
	 * @param int   $user_id Who entered it.
	 * @return array|WP_Error The prepared anchor.
	 */
	public static function prepare_from_code( $code, $user_id ) {
		$partner = self::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		$key = AB_MCP_Sync_Key::read_code( $code );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return self::hold( $key, $partner, 'code', $user_id );
	}

	/**
	 * The address the key is fetched from: the route as a query on the stored
	 * address, which needs no pretty permalinks there (as
	 * AB_MCP_Staging::check_url()).
	 *
	 * @param string $partner_url Stored address of the staging site.
	 * @return string
	 */
	public static function key_url( $partner_url ) {
		return $partner_url . '/?rest_route=/' . AB_MCP_REST_NAMESPACE . AB_MCP_Sync_Key::ROUTE;
	}

	/* ------------------------------------------------------------ confirm */

	/**
	 * A person confirms the prepared key on the page AlphaBridge → Staging,
	 * with the fingerprint the page showed: it becomes the active anchor.
	 * Called from wp-admin only (AB_MCP_Admin, behind its nonce and
	 * manage_options).
	 *
	 * @param mixed $fingerprint The fingerprint the person confirmed.
	 * @param int   $user_id     Who.
	 * @param int   $now         Time; now when null.
	 * @return array|WP_Error The active anchor.
	 */
	public static function confirm( $fingerprint, $user_id, $now = null ) {
		$partner = self::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		$state    = self::read();
		$prepared = $state['prepared'];
		if ( null === $prepared ) {
			return new WP_Error( 'ab_mcp_sync_anchor_nothing', 'There is no prepared key of the staging site to confirm. Fetch it, or enter the pairing code of the staging site, on the page AlphaBridge → Staging first.' );
		}
		if ( $prepared['partner_url'] !== $partner ) {
			return new WP_Error( 'ab_mcp_sync_anchor_stale', 'The prepared key belongs to another address than the staging site now stored here, so it was not confirmed. Fetch the key again, or enter the pairing code again, on the page AlphaBridge → Staging.' );
		}
		if ( ! is_string( $fingerprint ) || ! hash_equals( $prepared['fingerprint'], $fingerprint ) ) {
			return new WP_Error( 'ab_mcp_sync_anchor_fingerprint', 'The key was not confirmed: the fingerprint is not the one of the prepared key (it may have changed since the page was loaded). Load the page AlphaBridge → Staging again, compare the fingerprint with the one the staging site shows, and confirm again.' );
		}
		$active = array_merge(
			$prepared,
			array(
				'prepared_by' => $prepared['user_id'],
				'prepared_at' => $prepared['at'],
				'user_id'     => (int) $user_id,
				'at'          => null === $now ? time() : (int) $now,
			)
		);
		self::write( null, $active );
		return $active;
	}

	/* --------------------------------------------------------------- read */

	/**
	 * The active anchor, while this site is the confirmed production site
	 * paired with its address as the staging site (partner() is that
	 * address, or an error that equals no address); else null.
	 *
	 * @return array|null
	 */
	public static function active() {
		$active = self::read()['active'];
		return null !== $active && $active['partner_url'] === self::partner() ? $active : null;
	}

	/**
	 * The prepared anchor, while it belongs to the stored staging site; else
	 * null.
	 *
	 * @return array|null
	 */
	public static function prepared() {
		$prepared = self::read()['prepared'];
		return null !== $prepared && $prepared['partner_url'] === self::partner() ? $prepared : null;
	}

	/**
	 * Remove the anchor, prepared and active: the pair or the role changed.
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * The address of the staging site this production site is paired with,
	 * or why there is none to anchor.
	 *
	 * @return string|WP_Error
	 */
	public static function partner() {
		$record = AB_MCP_Staging::record();
		if ( null === $record['role'] || AB_MCP_Staging::ROLE_PRODUCTION !== $record['role']['value'] ) {
			return new WP_Error( 'ab_mcp_sync_anchor_role', 'Only the confirmed production site anchors the key of a staging site: confirm the role production site on the page AlphaBridge → Staging of this site first.' );
		}
		// A stored pair of a production site is always one with a staging
		// site (AB_MCP_Staging::record() reads no other).
		if ( null === $record['pair'] ) {
			return new WP_Error( 'ab_mcp_sync_anchor_pair', 'This production site has no staging site paired: enter the address of the staging site on the page AlphaBridge → Staging of this site first.' );
		}
		return $record['pair']['partner_url'];
	}

	/* -------------------------------------------------------------- print */

	/**
	 * The print of the pair: SHA-256 over this site's address, the confirmed
	 * role and pair (who, when, where) and the active anchor (key, who
	 * confirmed it, when); null while there is no active anchor.
	 *
	 * @return string|null
	 */
	public static function pair_print() {
		$active = self::active();
		if ( null === $active ) {
			return null;
		}
		$record = AB_MCP_Staging::record();
		$role   = $record['role'];
		$pair   = $record['pair'];
		$json   = wp_json_encode(
			array(
				'format' => self::PRINT_FORMAT,
				'site'   => AB_MCP_Staging::normalize_url( home_url() ),
				'role'   => array( $role['value'], $role['user_id'], $role['source'], $role['at'] ),
				'pair'   => array( $pair['partner_url'], $pair['partner_role'], $pair['user_id'], $pair['source'], $pair['at'] ),
				'anchor' => array( $active['source_id'], $active['key_id'], $active['public_key'], $active['via'], $active['user_id'], $active['at'] ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return is_string( $json ) ? hash( 'sha256', $json ) : null;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Store a key as the prepared anchor; the active one stays.
	 *
	 * @param array  $key     A key as AB_MCP_Sync_Key::read_answer() or read_code() give it.
	 * @param string $partner The stored address of the staging site.
	 * @param string $via     route or code.
	 * @param int    $user_id Who.
	 * @return array|WP_Error
	 */
	private static function hold( array $key, $partner, $via, $user_id ) {
		if ( $key['site'] !== $partner ) {
			return new WP_Error(
				'ab_mcp_sync_anchor_site',
				sprintf( 'The key names the staging site %1$s, but the staging site stored here is %2$s, and the two must be the same address exactly (scheme, host, port and path). Correct the address on the page AlphaBridge → Staging of the site that has it wrong, then fetch the key or enter the pairing code again.', $key['site'], $partner ),
				array(
					'site'        => $key['site'],
					'partner_url' => $partner,
				)
			);
		}
		$prepared = array(
			'partner_url' => $partner,
			'source_id'   => $key['source_id'],
			'key_id'      => $key['key_id'],
			'public_key'  => $key['public_key'],
			'fingerprint' => $key['fingerprint'],
			'via'         => $via,
			'user_id'     => (int) $user_id,
			'at'          => time(),
		);
		self::write( $prepared, self::read()['active'] );
		return $prepared;
	}

	/**
	 * The stored anchor, each part checked; a part that is not as write()
	 * stores it is null.
	 *
	 * @return array{prepared:array|null,active:array|null}
	 */
	private static function read() {
		$raw = get_option( self::OPTION, null );
		if ( ! is_array( $raw ) || ! isset( $raw['format'] ) || self::FORMAT !== $raw['format'] ) {
			return array(
				'prepared' => null,
				'active'   => null,
			);
		}
		return array(
			'prepared' => self::part( isset( $raw['prepared'] ) ? $raw['prepared'] : null, false ),
			'active'   => self::part( isset( $raw['active'] ) ? $raw['active'] : null, true ),
		);
	}

	/**
	 * A stored part, checked: the key as AB_MCP_Sync_Key::key_record()
	 * checks it, with its fingerprint; where it came from, who and when. Its
	 * address counts only where it is the stored address of the staging
	 * site (active(), prepared(), confirm()), which normalize_url() wrote.
	 *
	 * @param mixed $raw    Stored part.
	 * @param bool  $active Whether it is the active part (with who prepared it).
	 * @return array|null
	 */
	private static function part( $raw, $active ) {
		if ( ! is_array( $raw ) || ! isset( $raw['partner_url'], $raw['fingerprint'], $raw['via'], $raw['user_id'], $raw['at'] ) ) {
			return null;
		}
		$key = AB_MCP_Sync_Key::key_record( $raw );
		if ( null === $key || $key['fingerprint'] !== $raw['fingerprint'] || ! in_array( $raw['via'], self::VIA, true ) ) {
			return null;
		}
		if ( ! is_int( $raw['user_id'] ) || $raw['user_id'] < 0 || ! is_int( $raw['at'] ) || $raw['at'] <= 0 ) {
			return null;
		}
		$part = array(
			'partner_url' => $raw['partner_url'],
			'source_id'   => $key['source_id'],
			'key_id'      => $key['key_id'],
			'public_key'  => $key['public_key'],
			'fingerprint' => $key['fingerprint'],
			'via'         => $raw['via'],
			'user_id'     => $raw['user_id'],
			'at'          => $raw['at'],
		);
		if ( $active ) {
			if ( ! isset( $raw['prepared_by'], $raw['prepared_at'] ) || ! is_int( $raw['prepared_by'] ) || $raw['prepared_by'] < 0 || ! is_int( $raw['prepared_at'] ) || $raw['prepared_at'] <= 0 ) {
				return null;
			}
			$part['prepared_by'] = $raw['prepared_by'];
			$part['prepared_at'] = $raw['prepared_at'];
		}
		return $part;
	}

	/**
	 * Store the anchor.
	 *
	 * @param array|null $prepared Prepared part.
	 * @param array|null $active   Active part.
	 */
	private static function write( $prepared, $active ) {
		$value = array( 'format' => self::FORMAT );
		if ( null !== $prepared ) {
			$value['prepared'] = $prepared;
		}
		if ( null !== $active ) {
			$value['active'] = $active;
		}
		update_option( self::OPTION, $value, false );
	}
}
