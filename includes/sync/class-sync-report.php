<?php
/**
 * wp_site_diff on the production site (Z8, decisions 1, 14, 16, 21 and 22
 * of the plan of 01.11.2026; branch 6, part 2): the report over every kind of
 * the inventory, object by object, of what differs between this site and its
 * staging site and what a takeover would do with it. It reads, and writes
 * nothing but the report into the protected store of this site.
 *
 * THE CHECK BEFORE (decision 14), each refusal with its way
 * (AB_MCP_Guidance::compose()): no network; this site the confirmed
 * production site with a staging site paired; the key of that staging site
 * confirmed by a person (the active anchor); the packages of the inventory
 * of the staging site taken over completely here, each manifest checked
 * again (signature, this site and its staging site exactly, not expired,
 * the format ab-sync/1 and the version of the canonical form,
 * AB_MCP_Sync_Import::taken()), of one series; WordPress on the staging site
 * (the manifest names it) not newer than here, else the way is
 * wp_update_core.
 *
 * THE INVENTORY OF THIS SITE in parts, the same reading and the same index
 * of identities as the proposal of pairs (AB_MCP_Sync_Proposal::read_on():
 * AB_MCP_Sync_Inventory::BUDGET less a reserve, at most LIMIT objects a
 * call); the next call with the report_id goes on where it stopped. Once it
 * is read to its end, the packages are checked again, the pairs read, and
 * the decision function (AB_MCP_Sync_Decision) decides every entry.
 *
 * WHERE IT LIES. A group of the area reports of the protected store, kept a
 * day, one per connection (a new report removes the earlier ones of its
 * connection): its head (packages, print of the pair, connection, source,
 * canon, the versions of WordPress, until when), the parts of this site's
 * inventory, and, once done, the entries in parts with an index that holds
 * the counts and the report_hash: the SHA-256 over the head and the entries,
 * by which a package of objects and the plan of a run name the report they
 * are for (decision 5). A report belongs to the account and connection that
 * made it.
 *
 * THE ENVIRONMENT (branch 7; decision 14, «Lesende Bestandsaufnahme der
 * übrigen Arten»): with environment, the packages of the environment of the
 * staging site (purpose env) of one whole series in its order
 * (environment()), and once the inventory of this site is read, its own
 * environment in parts of the group (live_environment(), at least ENV_MIN
 * seconds a call), compared by AB_MCP_Sync_Env::compare(): an entry of kind
 * environment for each line of either side, after the objects and numbered
 * on, its locks with the others in blocked, its counts in environment.
 * The names the staging site holds (AB_MCP_Sync_Env::held_names(), by
 * their SHA-256) are kept with the head, read before this site reads its
 * own, so that each part of this site is stored with the rules of both
 * sites (AB_MCP_Sync_Env::held_there()). Without environment, one entry
 * says it is not compared, with the way.
 *
 * THE ANSWER: counts, what is locked with its reason and stage, the first
 * 200 entries and a cursor for the next ones. No hash, no value; a name
 * holding a credential by the SHA-256 of its ref only (B9 never in plain
 * text).
 *
 * Refusals and errors are gettext texts in the language of the site, each
 * naming the way; codes, states, kinds and parameters stay as the tools
 * name them. The words of the report (reasons of a lock, the why of a
 * decision, the words of the environment) stay English: they are compared
 * across the two sites and go into the hashes of the report and the plan.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-decision.php';
require_once __DIR__ . '/class-sync-env.php';

/**
 * Class AB_MCP_Sync_Report
 */
final class AB_MCP_Sync_Report {

	/** The format of the files of a report (2: the head keeps the names the staging site holds, held). */
	const FORMAT = 'ab-sync-report/2';

	/** The label of its group in the area reports. */
	const LABEL = 'report';

	/** Entries of one answer at most (the first 200, then a cursor). */
	const PAGE = 200;

	/** Entries of one stored part. */
	const PART = 1000;

	/** Packages of the environment of the staging site at most (one series). */
	const MAX_ENVIRONMENT = 50;

	/** The format of a part of the environment of this site in the group. */
	const ENV_FORMAT = 'ab-sync-report-env/1';

	/** Seconds a call keeps at least for a part of the environment of this site; with less left, the next call reads it. */
	const ENV_MIN = 10;

	/** The anchors of the page «Staging» the ways lead to. */
	const ANCHOR_PAIR  = 'ab-staging-partner';
	const ANCHOR_TRUST = 'ab-staging-trust';

	/** The clock of the expiry: a callable returning seconds, null for time(). Set by tests only. */
	public static $clock = null;

	/** Entries of one answer at most; PAGE when null. Set by tests only. */
	public static $page = null;

	/** Lines of one part of the environment of this site at most; AB_MCP_Sync_Export::OBJECTS when null. Set by tests only. */
	public static $env_lines = null;

	/* ---------------------------------------------------------- the tool */

	/**
	 * wp_site_diff: begin a report from packages, go on with one, or give a
	 * page of one that is done.
	 *
	 * @param array $a packages (to begin) or report_id (to go on or page); cursor.
	 * @return array|WP_Error
	 */
	public static function call( array $a ) {
		$source = self::precheck();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( isset( $a['report_id'] ) ) {
			$found = self::find( $a['report_id'], $source );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( null !== $found['index'] ) {
				return self::page( $found, isset( $a['cursor'] ) ? $a['cursor'] : 0 );
			}
			return self::step( $found );
		}
		$packages = isset( $a['packages'] ) ? $a['packages'] : null;
		if ( ! is_array( $packages ) || ! array_is_list( $packages ) || array() === $packages || count( $packages ) > AB_MCP_Sync_Proposal::MAX_PACKAGES || count( array_unique( array_map( 'strval', $packages ) ) ) !== count( $packages ) ) {
			return self::refusal(
				'ab_mcp_sync_report_packages',
				/* translators: %d: the most packages a report takes. */
				sprintf( __( 'A report begins with packages: the package_ids of the inventory of the staging site this site took over completely, each once, at most %d.', 'alphabridge-mcp' ), AB_MCP_Sync_Proposal::MAX_PACKAGES ),
				__( '1. On the staging site, call wp_site_export with purpose=inventory (and its cursor until it gives none). 2. Here, take each package over with wp_site_import_chunk until it answers complete true. 3. Call wp_site_diff with packages, or with report_id to go on with a report.', 'alphabridge-mcp' ),
				self::ANCHOR_TRUST
			);
		}
		$environment = isset( $a['environment'] ) ? $a['environment'] : array();
		if ( ! is_array( $environment ) || ! array_is_list( $environment ) || count( $environment ) > self::MAX_ENVIRONMENT || count( array_unique( array_map( 'strval', $environment ) ) ) !== count( $environment ) || array() !== array_intersect( array_map( 'strval', $environment ), array_map( 'strval', $packages ) ) ) {
			return self::refusal(
				'ab_mcp_sync_report_environment',
				/* translators: %d: the most packages of the environment a report takes. */
				sprintf( __( 'environment names the package_ids of the environment of the staging site this site took over completely, each once, at most %d, in the order of their series, and none of the packages of the inventory.', 'alphabridge-mcp' ), self::MAX_ENVIRONMENT ),
				__( '1. On the staging site, call wp_site_export with purpose=env (and its cursor until it gives none). 2. Here, take each package over with wp_site_import_chunk with purpose=env until it answers complete true. 3. Call wp_site_diff with packages and environment.', 'alphabridge-mcp' ),
				self::ANCHOR_TRUST
			);
		}
		$staging = self::packages( $packages );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$env = self::environment( $environment );
		if ( is_wp_error( $env ) ) {
			return $env;
		}
		$version = self::wordpress_refusal( $staging['wordpress'], AB_MCP_Sync_Manifest::wordpress() );
		if ( null !== $version ) {
			return $version;
		}
		$gone = self::replace_own();
		if ( is_wp_error( $gone ) ) {
			return $gone;
		}
		$gid = AB_MCP_Protected_Store::open( 'reports', array( 'label' => self::LABEL ) );
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$head = array(
			'canon'       => AB_MCP_Sync_Canon::VERSION,
			'connection'  => AB_MCP_Sync_Proposal::connection(),
			'created'     => self::now(),
			'environment' => array_values( array_map( 'strval', $environment ) ),
			'expires'     => min( $staging['expires'], $env['expires'], self::now() + AB_MCP_Protected_Store::KEEP['reports'] ),
			'format'      => self::FORMAT,
			// The names the staging site holds, by their SHA-256: before this
			// site reads its own environment (Codex, round 2 of branch 7, A1).
			'held'        => AB_MCP_Sync_Env::held_names( $env['lines'] ),
			'packages'    => array_values( array_map( 'strval', $packages ) ),
			'print'       => (string) AB_MCP_Sync_Anchor::pair_print(),
			'source_id'   => $source,
			'wordpress'   => array(
				'live'    => AB_MCP_Sync_Manifest::wordpress(),
				'staging' => $staging['wordpress'],
			),
		);
		$put  = AB_MCP_Sync_Proposal::put( $gid, 'h', null, $head, self::FORMAT );
		if ( is_wp_error( $put ) ) {
			AB_MCP_Protected_Store::remove( 'reports', $gid );
			return $put;
		}
		return self::step(
			array(
				'gid'   => $gid,
				'head'  => $head,
				'parts' => array(),
				'index' => null,
			)
		);
	}

	/* ----------------------------------------------------- the check before */

	/**
	 * The check before any report (decision 14): no network, the pair, the
	 * anchor a person confirmed; the source of its key.
	 *
	 * @return string|WP_Error
	 */
	public static function precheck() {
		if ( is_multisite() ) {
			return self::refusal(
				'ab_mcp_sync_report_multisite',
				__( 'The report is not available on a multisite installation: the takeover from a staging site works between single sites.', 'alphabridge-mcp' ),
				__( 'Use a single site as the production site and a single site as its staging site.', 'alphabridge-mcp' ),
				''
			);
		}
		$partner = AB_MCP_Sync_Anchor::partner();
		if ( is_wp_error( $partner ) ) {
			return self::refusal(
				'ab_mcp_sync_report_pair',
				__( 'This site is not the confirmed production site of a pair of sites, so there is no staging site to compare it with.', 'alphabridge-mcp' ),
				__( '1. On the page AlphaBridge → Staging of this site, confirm the role production site. 2. Enter the address of the staging site there. 3. Call wp_site_diff again.', 'alphabridge-mcp' ),
				self::ANCHOR_PAIR
			);
		}
		$anchor = AB_MCP_Sync_Anchor::active();
		if ( null === $anchor ) {
			return null !== AB_MCP_Sync_Anchor::prepared()
				? self::refusal(
					'ab_mcp_sync_report_unconfirmed',
					__( 'The key of the staging site is prepared on this site but not confirmed by a person, so nothing of it is read.', 'alphabridge-mcp' ),
					__( '1. On the page AlphaBridge → Staging of this site, compare the fingerprint of the key with the one the staging site shows on its page. 2. Confirm the key. 3. Call wp_site_diff again.', 'alphabridge-mcp' ),
					self::ANCHOR_TRUST
				)
				: self::refusal(
					'ab_mcp_sync_report_no_anchor',
					__( 'This site has no confirmed key of its staging site, so no package of it is read.', 'alphabridge-mcp' ),
					__( '1. On the page AlphaBridge → Staging of this site, fetch the key of the staging site, or enter its pairing code. 2. Compare its fingerprint with the one the staging site shows and confirm it. 3. Call wp_site_diff again.', 'alphabridge-mcp' ),
					self::ANCHOR_TRUST
				);
		}
		return (string) $anchor['source_id'];
	}

	/**
	 * The packages of the report, each manifest checked again (the format,
	 * the version of the canonical form, the anchor, both addresses, the
	 * expiry, the purpose: AB_MCP_Sync_Import::taken()), as one inventory of
	 * one series (AB_MCP_Sync_Proposal::compose()), with the version of
	 * WordPress on the staging site its manifests name (the highest, where
	 * an update ran between two packages of a series).
	 *
	 * @param string[] $packages Package ids.
	 * @return array|WP_Error compose() and wordpress.
	 */
	public static function packages( array $packages ) {
		$taken = array();
		foreach ( $packages as $id ) {
			$one = AB_MCP_Sync_Import::taken( $id );
			if ( is_wp_error( $one ) ) {
				return self::refusal( $one->get_error_code(), $one->get_error_message(), '', self::ANCHOR_TRUST );
			}
			$taken[] = $one;
		}
		$staging = AB_MCP_Sync_Proposal::compose( $taken );
		if ( is_wp_error( $staging ) ) {
			return self::refusal( 'ab_mcp_sync_report_series', $staging->get_error_message(), '', self::ANCHOR_TRUST );
		}
		$staging['wordpress'] = self::wordpress_of( $taken );
		return $staging;
	}

	/**
	 * The version of WordPress on the staging site its manifests name: the
	 * highest, where an update ran between two packages of a series (each
	 * manifest is checked to name a version, AB_MCP_Sync_Manifest::WORDPRESS).
	 *
	 * @param array[] $taken AB_MCP_Sync_Import::taken() of each package.
	 * @return string
	 */
	public static function wordpress_of( array $taken ) {
		$wordpress = '';
		foreach ( $taken as $one ) {
			$version = (string) ( $one['manifest']['wordpress'] ?? '' );
			if ( '' === $wordpress || version_compare( $version, $wordpress, '>' ) ) {
				$wordpress = $version;
			}
		}
		return $wordpress;
	}

	/**
	 * The packages of the environment of the staging site (branch 7), each
	 * manifest checked again (AB_MCP_Sync_Import::taken() with purpose env),
	 * as one whole series in order: the page of each the first line, one
	 * walk, the parts 1 to n without a gap, the last one last. The lines of
	 * a package are signed with it (each chunk by its SHA-256 in the signed
	 * manifest), so a series cannot be put together from two readings. A
	 * line whose name the import held by the rules of this site is shown by
	 * the SHA-256 of its name (AB_MCP_Sync_Env::held_here()).
	 *
	 * @param string[] $ids Package ids, in the order of their series; none: no environment.
	 * @return array{lines:array[],expires:int,packages:int}|WP_Error
	 */
	public static function environment( array $ids ) {
		$lines   = array();
		$expires = PHP_INT_MAX;
		$walk    = null;
		foreach ( array_values( $ids ) as $i => $id ) {
			$part = AB_MCP_Sync_Import::taken( $id, 'env' );
			if ( is_wp_error( $part ) ) {
				return self::refusal( $part->get_error_code(), $part->get_error_message(), '', self::ANCHOR_TRUST );
			}
			$page = $part['lines'][0] ?? null;
			if ( ! is_array( $page ) || 'page' !== ( $page['env'] ?? null ) || $i + 1 !== $page['seq'] || ( null !== $walk && $walk !== $page['walk'] ) || ( count( $ids ) === $i + 1 ) !== $page['last'] ) {
				return self::refusal(
					'ab_mcp_sync_report_environment_series',
					__( 'The packages of the environment are not one whole series in its order: each package of a reading of the environment names its place in it, and the last one says it is the last.', 'alphabridge-mcp' ),
					__( '1. Pass in environment every package one wp_site_export with purpose=env and its cursors made, in that order, from the first to the one without a cursor. 2. Or export the environment anew on the staging site and take it over here.', 'alphabridge-mcp' ),
					self::ANCHOR_TRUST
				);
			}
			$walk    = $page['walk'];
			$expires = min( $expires, (int) $part['manifest']['expires'] );
			// A name a credential holds by the rules of this site (the list of
			// the import): by its SHA-256 only (Codex, round 1 of branch 7, A1).
			foreach ( AB_MCP_Sync_Env::held_here( array_slice( $part['lines'], 1 ), $part['held'] ) as $line ) {
				$lines[] = $line;
			}
		}
		return array(
			'lines'    => $lines,
			'expires'  => $expires,
			'packages' => count( $ids ),
		);
	}

	/**
	 * The refusal where WordPress on the staging site is newer than here
	 * (decision 14; concept A17: no takeover onto an older WordPress), or
	 * null. Both are versions as the manifest takes them
	 * (AB_MCP_Sync_Manifest::WORDPRESS); one that is none refuses as well.
	 *
	 * @param string $staging The version on the staging site.
	 * @param string $live    The version here.
	 * @return WP_Error|null
	 */
	public static function wordpress_refusal( $staging, $live ) {
		$staging = (string) $staging;
		$live    = (string) $live;
		if ( 1 !== preg_match( AB_MCP_Sync_Manifest::WORDPRESS, $staging ) || 1 !== preg_match( AB_MCP_Sync_Manifest::WORDPRESS, $live ) ) {
			if ( '' === $staging && '' === $live ) {
				$reason = __( 'The version of WordPress can be read neither on the staging site nor on this site (none is named), so the two cannot be compared.', 'alphabridge-mcp' );
			} elseif ( '' === $staging ) {
				/* translators: %s: version of WordPress on this site as it is named. */
				$reason = sprintf( __( 'The version of WordPress on the staging site cannot be read (none is named; on this site: %s), so the two cannot be compared.', 'alphabridge-mcp' ), $live );
			} elseif ( '' === $live ) {
				/* translators: %s: version of WordPress on the staging site as it is named. */
				$reason = sprintf( __( 'The version of WordPress on this site cannot be read (none is named; on the staging site: %s), so the two cannot be compared.', 'alphabridge-mcp' ), $staging );
			} else {
				/* translators: 1: version of WordPress on the staging site as it is named, 2: the one on this site. */
				$reason = sprintf( __( 'The version of WordPress on the staging site (%1$s) or on this site (%2$s) cannot be read, so the two cannot be compared.', 'alphabridge-mcp' ), $staging, $live );
			}
			return self::refusal(
				'ab_mcp_sync_report_wordpress',
				$reason,
				__( '1. Update AlphaBridge MCP on both sites to the same version. 2. Export the inventory on the staging site again (wp_site_export) and take it over here. 3. Call wp_site_diff again.', 'alphabridge-mcp' ),
				self::ANCHOR_TRUST
			);
		}
		if ( version_compare( $staging, $live, '>' ) ) {
			return self::refusal(
				'ab_mcp_sync_report_wordpress',
				/* translators: 1: version of WordPress on the staging site, 2: version of WordPress on this site. */
				sprintf( __( 'WordPress on the staging site (%1$s) is newer than on this site (%2$s), and nothing made with a newer WordPress is taken over onto an older one.', 'alphabridge-mcp' ), $staging, $live ),
				__( '1. Update WordPress on this site first: wp_update_core (it has no way back through AlphaBridge, and asks for a confirmation of its own), or in wp-admin under Dashboard → Updates. 2. Call wp_site_diff again with the same packages.', 'alphabridge-mcp' ),
				''
			);
		}
		return null;
	}

	/* ------------------------------------------------------- the inventory */

	/**
	 * One call of a report that is not done: the next part of the inventory
	 * of this site; once it is read to its end, the report.
	 *
	 * @param array $found find() (or a group just begun).
	 * @return array|WP_Error
	 */
	private static function step( array $found ) {
		$start = self::clock();
		$seq   = count( $found['parts'] );
		if ( 0 === $seq || null !== $found['parts'][ $seq - 1 ]['cursor'] ) {
			$found = AB_MCP_Sync_Proposal::read_on( $found, self::FORMAT, self::concurrent() );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
		}
		if ( null === end( $found['parts'] )['cursor'] ) {
			$env = self::live_environment( $found, $start );
			if ( is_wp_error( $env ) ) {
				return $env;
			}
			if ( null !== $env['lines'] ) {
				return self::finish( $found, $env['lines'] );
			}
			return array(
				'report_id'   => $found['gid'],
				'done'        => false,
				'live'        => array(
					'objects' => array_sum( array_column( $found['parts'], 'objects' ) ),
				),
				'environment' => array(
					'parts' => $env['parts'],
				),
				'expires'     => (int) $found['head']['expires'],
				/* translators: %d: parts of the environment read so far. */
				'next'        => sprintf( _n( 'The objects of this site are read; its environment (plugins, themes, files, tables, the host) is read as far as one call reaches (%d part so far).', 'The objects of this site are read; its environment (plugins, themes, files, tables, the host) is read as far as one call reaches (%d parts so far).', (int) $env['parts'], 'alphabridge-mcp' ), $env['parts'] ) . ' Call wp_site_diff again with this report_id to go on.',
			);
		}
		$read_so_far = array_sum( array_column( $found['parts'], 'objects' ) );
		return array(
			'report_id' => $found['gid'],
			'done'      => false,
			'live'      => array(
				'objects' => $read_so_far,
			),
			'expires'   => (int) $found['head']['expires'],
			/* translators: %d: objects read so far. */
			'next'      => sprintf( __( 'The inventory of this site is read as far as one call reaches (first the identities of its objects, then the objects: %d so far).', 'alphabridge-mcp' ), $read_so_far ) . ' Call wp_site_diff again with this report_id to go on.',
		);
	}

	/**
	 * The environment of this site for a report with packages of the
	 * environment of the staging site, in parts of the group (one per call,
	 * within what is left of the budget of the call, at least ENV_MIN
	 * seconds): its lines once read to its end; none for a report without
	 * them. A part is stored with the rules of both sites: a name the
	 * staging site holds (held in the head) by its SHA-256 only.
	 *
	 * @param array $found find().
	 * @param float $start When the call began.
	 * @return array{lines:array[]|null,parts:int}|WP_Error lines null: not read to its end yet.
	 */
	private static function live_environment( array $found, $start ) {
		if ( array() === ( $found['head']['environment'] ?? array() ) ) {
			return array(
				'lines' => array(),
				'parts' => 0,
			);
		}
		$parts = self::env_parts( $found['gid'] );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$last = end( $parts );
		if ( false === $last || null !== $last['state'] ) {
			$left = AB_MCP_Sync_Inventory::BUDGET - AB_MCP_Sync_Proposal::RESERVE - ( self::clock() - $start );
			if ( $left < self::ENV_MIN ) {
				return array(
					'lines' => null,
					'parts' => count( $parts ),
				);
			}
			$read = AB_MCP_Sync_Env::read( false === $last ? null : $last['state'], $left, null === self::$env_lines ? AB_MCP_Sync_Export::OBJECTS : (int) self::$env_lines );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$part = array(
				'format' => self::ENV_FORMAT,
				'lines'  => AB_MCP_Sync_Env::held_there( $read['lines'], (array) ( $found['head']['held'] ?? array() ) ),
				'seq'    => count( $parts ) + 1,
				'state'  => $read['state'],
			);
			$text = AB_MCP_Sync_Canon::encode( $part );
			$put  = AB_MCP_Protected_Store::put( 'reports', $found['gid'], AB_MCP_Sync_Proposal::name( 'v', $part['seq'], hash( 'sha256', $text ) ), $text );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$parts[] = $part;
			if ( null !== $read['state'] ) {
				return array(
					'lines' => null,
					'parts' => count( $parts ),
				);
			}
		}
		$lines = array();
		foreach ( $parts as $part ) {
			foreach ( $part['lines'] as $line ) {
				$lines[] = $line;
			}
		}
		return array(
			'lines' => $lines,
			'parts' => count( $parts ),
		);
	}

	/**
	 * The parts of the environment of this site in a group, in order: one
	 * per number, without a gap, each read against its SHA-256 and of the
	 * walk of the first.
	 *
	 * @param string $gid Group.
	 * @return array[]|WP_Error
	 */
	private static function env_parts( $gid ) {
		$files = AB_MCP_Protected_Store::files( 'reports', $gid );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$found = array();
		foreach ( array_keys( $files ) as $name ) {
			$parsed = AB_MCP_Sync_Proposal::parse_name( (string) $name );
			if ( null === $parsed || 'v' !== $parsed['prefix'] ) {
				continue;
			}
			if ( isset( $found[ $parsed['seq'] ] ) ) {
				return self::concurrent();
			}
			$found[ $parsed['seq'] ] = $parsed;
		}
		ksort( $found );
		$out = array();
		foreach ( $found as $seq => $parsed ) {
			$text = AB_MCP_Protected_Store::get( 'reports', $gid, $parsed['name'], $parsed['sha'] );
			$part = is_string( $text ) ? json_decode( $text, true, 64 ) : null;
			if ( count( $out ) + 1 !== $seq || ! is_array( $part ) || self::ENV_FORMAT !== ( $part['format'] ?? null ) || $seq !== ( $part['seq'] ?? null ) || ! is_array( $part['lines'] ?? null ) || ( isset( $out[0] ) && $out[0]['lines'][0]['walk'] !== ( $part['lines'][0]['walk'] ?? null ) ) ) {
				return self::concurrent();
			}
			$out[] = $part;
		}
		return $out;
	}

	/**
	 * Work out the report of a group whose inventory is read to its end:
	 * the packages checked again, the pairs, the decision of every entry,
	 * the entries stored with the report_hash.
	 *
	 * @param array        $found find().
	 * @param array[]      $env   The lines of the environment of this site (none without packages of the environment).
	 * @return array|WP_Error The first page.
	 */
	private static function finish( array $found, array $env = array() ) {
		$head    = $found['head'];
		$staging = self::packages( $head['packages'] );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$ids      = $head['environment'] ?? array();
		$env_side = self::environment( $ids );
		if ( is_wp_error( $env_side ) ) {
			return $env_side;
		}
		$environment = array() === $ids ? AB_MCP_Sync_Env::without() : AB_MCP_Sync_Env::compare( $env_side['lines'], $env );
		$live = AB_MCP_Sync_Proposal::live( $found, self::concurrent() );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$pairs = self::pairs( $head['source_id'] );
		if ( is_wp_error( $pairs ) ) {
			return $pairs;
		}
		$report = AB_MCP_Sync_Decision::report(
			$staging['lines'],
			$live['lines'],
			$pairs,
			static function ( $kind, $subtype ) use ( $staging ) {
				return self::complete( $staging, $kind, $subtype );
			},
			$live['b9_refs']
		);
		unset( $live['lines'] );
		// The environment after the objects, numbered on (one cursor).
		$n = count( $report['entries'] );
		foreach ( $environment['entries'] as $entry ) {
			$report['entries'][] = array( 'n' => ++$n ) + $entry;
		}
		$parts = array();
		foreach ( array_chunk( $report['entries'], self::PART ) as $i => $chunk ) {
			$put = AB_MCP_Sync_Proposal::put( $found['gid'], 'e', $i + 1, $chunk, self::FORMAT );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			$parts[] = $put + array(
				'first' => (int) $chunk[0]['n'],
				'count' => count( $chunk ),
			);
		}
		$incomplete = array();
		foreach ( $staging['complete'] as $key => $whole ) {
			if ( ! $whole ) {
				list( $kind, $subtype ) = explode( "\0", $key, 2 );
				$incomplete[]           = array(
					'kind'    => $kind,
					'subtype' => $subtype,
				) + self::type_lock( $kind, $subtype );
			}
		}
		$blocked = $report['blocked'];
		foreach ( $environment['blocked'] as $lock ) {
			$blocked[] = $lock;
		}
		usort(
			$blocked,
			static function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);
		$index                = array(
			'blocked'     => $blocked,
			'counts'      => $report['counts'],
			'environment' => array(
				'packages' => count( $ids ),
				'counts'   => $environment['counts'],
			),
			'format'      => self::FORMAT,
			'live'        => array(
				'objects'     => array_sum( array_column( $found['parts'], 'objects' ) ),
				'credentials' => (int) $live['b9'],
			),
			'pairs'       => $report['pairs'],
			'parts'       => $parts,
			'staging'     => array(
				'packages'   => count( $head['packages'] ),
				'objects'    => $staging['objects'],
				'held'       => $staging['held'],
				'incomplete' => $incomplete,
			),
			'total'       => count( $report['entries'] ),
		);
		$index['report_hash'] = self::report_hash( $head, $index );
		$put                  = AB_MCP_Sync_Proposal::put( $found['gid'], 'x', null, $index, self::FORMAT );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$found['index'] = $index;
		return self::page( $found, 0 );
	}

	/**
	 * The report_hash: the SHA-256 over the head of the report (packages,
	 * print, source, canon, versions) and its index without the hash itself
	 * (counts, what is locked, the SHA-256 of each part of the entries), in
	 * the one spelling of AB_MCP_Sync_Canon::encode().
	 *
	 * @param array $head  Head.
	 * @param array $index Index, without report_hash.
	 * @return string
	 */
	public static function report_hash( array $head, array $index ) {
		unset( $index['report_hash'], $head['connection'], $head['created'], $head['expires'] );
		return hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'format' => self::FORMAT,
					'head'   => $head,
					'index'  => $index,
				)
			)
		);
	}

	/**
	 * The lock of a post type or taxonomy the table locks (a changeset, a
	 * post type of a plugin), for a subtype the staging site did not give
	 * whole: so a type whose objects no account may read there (WordPress
	 * lets nobody edit a changeset outside the Customizer, and the export
	 * holds back what the account may not edit, decision 9) still shows as
	 * locked with its reason and stage (decision 1).
	 *
	 * @param string $kind    Pair kind.
	 * @param string $subtype Subtype.
	 * @return array lock, or nothing.
	 */
	private static function type_lock( $kind, $subtype ) {
		if ( 'post' === $kind ) {
			$answer = AB_MCP_Sync_Kinds::post_type( $subtype );
		} elseif ( 'term' === $kind ) {
			$answer = AB_MCP_Sync_Kinds::taxonomy( $subtype );
		} else {
			return array();
		}
		return 'locked' === $answer['status'] ? array(
			'lock' => array(
				'key'    => $answer['key'],
				'stage'  => $answer['stage'],
				'reason' => $answer['reason'],
			),
		) : array();
	}

	/**
	 * Whether the staging side holds every object of a kind and subtype; of
	 * every subtype of the kind where the subtype is not known (an object
	 * gone on both sides).
	 *
	 * @param array       $staging AB_MCP_Sync_Proposal::compose().
	 * @param string      $kind    Pair kind.
	 * @param string|null $subtype Subtype, null where not known.
	 * @return bool
	 */
	public static function complete( array $staging, $kind, $subtype ) {
		if ( null !== $subtype ) {
			return AB_MCP_Sync_Proposal::complete( $staging, $kind, $subtype );
		}
		if ( ! AB_MCP_Sync_Proposal::complete( $staging, $kind, '' ) ) {
			return false;
		}
		foreach ( $staging['complete'] as $key => $whole ) {
			if ( 0 === strpos( $key, $kind . "\0" ) && ! $whole ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------ paging */

	/**
	 * A page of a report that is done: the counts, what is locked, the
	 * entries after the cursor (at most PAGE), the cursor of the next page.
	 *
	 * @param array $found  find(), done.
	 * @param mixed $cursor The number of the last entry of the page before.
	 * @return array|WP_Error
	 */
	private static function page( array $found, $cursor ) {
		$after   = is_numeric( $cursor ) ? max( 0, (int) $cursor ) : 0;
		$index   = $found['index'];
		$entries = array();
		$more    = false;
		foreach ( $index['parts'] as $part ) {
			if ( (int) $part['first'] + (int) $part['count'] - 1 <= $after ) {
				continue;
			}
			$list = AB_MCP_Sync_Proposal::read_part( $found['gid'], $part, self::concurrent() );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $entry ) {
				if ( $entry['n'] <= $after ) {
					continue;
				}
				if ( count( $entries ) >= ( null === self::$page ? self::PAGE : (int) self::$page ) ) {
					$more = true;
					break 2;
				}
				$entries[] = $entry;
			}
		}
		$last = array() === $entries ? null : (int) end( $entries )['n'];
		return array(
			'report_id'   => $found['gid'],
			'report_hash' => $index['report_hash'],
			'done'        => true,
			'expires'     => (int) $found['head']['expires'],
			'wordpress'   => $found['head']['wordpress'],
			'counts'      => $index['counts'],
			'blocked'     => $index['blocked'],
			'pairs'       => $index['pairs'],
			'staging'     => $index['staging'],
			'live'        => $index['live'],
			'environment' => $index['environment'] ?? null,
			'total'       => $index['total'],
			'entries'     => $entries,
			'cursor'      => $more ? $last : null,
			'next'        => 'Show the report to the person: nothing was written on either site. Each entry says what differs (result, sides) and what a takeover would do (action): take_over and create are written by a run, choose waits for the choice of a person, waits and locked are held back (waits_for and locks name why, each lock with the stage that brings it), pair asks for wp_site_pairs first, keep and none leave the object as it is. The entries of kind environment come after the objects: plugins, themes, must-use plugins, drop-ins, tables, files and the host; equal or differs only for what was read by its SHA-256 (must-use plugins, drop-ins), same_value or differs for the values of the host and the language (shown only, what lies behind a value not compared), counted for what was only counted or summed (contents not compared; stage says from when they are), host for what is changed at the host (the drop-ins and the host, whatever the result); a name held (held) is shown by its SHA-256 only; nothing of the environment is taken over.' . ( $more ? ' More entries: call wp_site_diff again with this report_id and cursor=' . $last . '.' : '' ),
		);
	}

	/* ------------------------------------------------------------ store */

	/**
	 * A report of this account and connection, for this source and pair, not
	 * expired: its head, the parts of the inventory and, once done, its
	 * index.
	 *
	 * @param mixed  $report_id Report (32 hex).
	 * @param string $source    The source of the confirmed key.
	 * @return array|WP_Error gid, head, parts, index (null while not done).
	 */
	public static function find( $report_id, $source ) {
		if ( ! is_string( $report_id ) || 1 !== preg_match( '/^[0-9a-f]{32}\z/', $report_id ) ) {
			return self::unknown();
		}
		$groups = AB_MCP_Protected_Store::groups( 'reports' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$group = null;
		foreach ( $groups as $entry ) {
			if ( $report_id === $entry['gid'] && self::LABEL === $entry['label'] ) {
				$group = $entry;
			}
		}
		if ( null === $group ) {
			return self::unknown();
		}
		$files = AB_MCP_Protected_Store::files( 'reports', $report_id );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$head = AB_MCP_Sync_Proposal::read_one( $report_id, $files, 'h', self::concurrent() );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		if ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) ) {
			return self::unknown();
		}
		if ( ! hash_equals( (string) $head['connection'], AB_MCP_Sync_Proposal::connection() ) ) {
			return new WP_Error( 'ab_mcp_sync_report_foreign', __( 'This report belongs to another account or connection, and only the one that made it goes on with it or pages it. Make a report over this connection: call wp_site_diff with the packages of the inventory of the staging site.', 'alphabridge-mcp' ) );
		}
		if ( AB_MCP_Sync_Canon::VERSION !== $head['canon'] || $source !== $head['source_id'] || ! hash_equals( (string) $head['print'], (string) AB_MCP_Sync_Anchor::pair_print() ) ) {
			return new WP_Error( 'ab_mcp_sync_report_stale', __( 'The role of this site, its pair, the confirmed key of the staging site or the version of the comparison data changed since this report was made, so it holds no more. Make a new report: call wp_site_diff with the packages of a fresh inventory of the staging site.', 'alphabridge-mcp' ) );
		}
		if ( self::now() >= (int) $head['expires'] ) {
			return new WP_Error( 'ab_mcp_sync_report_expired', __( 'This report expired: it holds as long as the packages it was made from (a day at most). Export the inventory on the staging site again (wp_site_export), take it over here (wp_site_import_chunk), then call wp_site_diff with the packages.', 'alphabridge-mcp' ) );
		}
		$parts = AB_MCP_Sync_Proposal::parts( $report_id, $files, self::FORMAT, self::concurrent() );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$index = AB_MCP_Sync_Proposal::read_one( $report_id, $files, 'x', self::concurrent() );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		return array(
			'gid'   => $report_id,
			'head'  => $head,
			'parts' => $parts,
			'index' => $index,
		);
	}

	/**
	 * The youngest report of this site for this source and pair that has not
	 * expired, of whichever account and connection made it, for the page
	 * «Staging» (Z8, decision 35, branch 12: the last report): its id, when
	 * it was made, until when it holds, and its index once it is done (null
	 * while it is not). Reads only: no report is paged or gone on with here,
	 * which stays with the connection that made it (find()). Null where
	 * there is none; a group that does not read as a report, or holds for
	 * another source, pair or version of the comparison data, is passed by.
	 *
	 * @param string $source The source of the confirmed key.
	 * @return array{report_id:string,created:int,expires:int,index:array|null}|null|WP_Error
	 */
	public static function latest( $source ) {
		$groups = AB_MCP_Protected_Store::groups( 'reports' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$print = (string) AB_MCP_Sync_Anchor::pair_print();
		$best  = null;
		foreach ( $groups as $group ) {
			if ( self::LABEL !== $group['label'] ) {
				continue;
			}
			$files = AB_MCP_Protected_Store::files( 'reports', $group['gid'] );
			$head  = is_wp_error( $files ) ? null : AB_MCP_Sync_Proposal::read_one( $group['gid'], $files, 'h', self::concurrent() );
			if ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) ) {
				continue;
			}
			if ( AB_MCP_Sync_Canon::VERSION !== ( $head['canon'] ?? null ) || $source !== ( $head['source_id'] ?? null ) || ! hash_equals( (string) ( $head['print'] ?? '' ), $print ) || self::now() >= (int) ( $head['expires'] ?? 0 ) ) {
				continue;
			}
			if ( null === $best || (int) $head['created'] > $best['created'] ) {
				$best = array(
					'report_id' => (string) $group['gid'],
					'created'   => (int) $head['created'],
					'expires'   => (int) $head['expires'],
					'files'     => $files,
				);
			}
		}
		if ( null === $best ) {
			return null;
		}
		$index = AB_MCP_Sync_Proposal::read_one( $best['report_id'], $best['files'], 'x', self::concurrent() );
		unset( $best['files'] );
		$best['index'] = is_array( $index ) ? $index : null;
		return $best;
	}

	/**
	 * A connection works on one report: a new one removes the earlier ones
	 * of this connection from the store (those of other connections stay).
	 *
	 * @return true|WP_Error
	 */
	private static function replace_own() {
		$groups = AB_MCP_Protected_Store::groups( 'reports' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$mine = AB_MCP_Sync_Proposal::connection();
		foreach ( $groups as $group ) {
			if ( self::LABEL !== $group['label'] ) {
				continue;
			}
			$files = AB_MCP_Protected_Store::files( 'reports', $group['gid'] );
			$head  = is_wp_error( $files ) ? null : AB_MCP_Sync_Proposal::read_one( $group['gid'], $files, 'h', self::concurrent() );
			if ( is_array( $head ) && isset( $head['connection'] ) && hash_equals( (string) $head['connection'], $mine ) ) {
				AB_MCP_Protected_Store::remove( 'reports', $group['gid'] );
			}
		}
		return true;
	}

	/**
	 * The pairs of a source, all.
	 *
	 * @param string $source Source.
	 * @return array[]|WP_Error
	 */
	private static function pairs( $source ) {
		$out   = array();
		$after = 0;
		do {
			$page = AB_MCP_Sync_Pairs::page_source( $source, $after );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['pairs'] as $pair ) {
				$out[] = $pair;
			}
			$after = $page['next'];
		} while ( null !== $after );
		return $out;
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * A refusal of the whole call with its way (decision 32): the reason,
	 * the steps, the link to the page «Staging».
	 *
	 * @param string $code   Code.
	 * @param string $reason Why.
	 * @param string $steps  What the person does.
	 * @param string $anchor Anchor on the page «Staging» ('' for the page).
	 * @return WP_Error
	 */
	private static function refusal( $code, $reason, $steps, $anchor ) {
		return new WP_Error( $code, AB_MCP_Guidance::compose( $reason, $steps, AB_MCP_Guidance::settings_url( $anchor, 'staging' ) ) );
	}

	/**
	 * @return float Seconds, for the budget of a call (the clock of AB_MCP_Sync_Env).
	 */
	private static function clock() {
		return null !== AB_MCP_Sync_Env::$clock ? (float) call_user_func( AB_MCP_Sync_Env::$clock ) : microtime( true );
	}

	/**
	 * @return int Seconds.
	 */
	private static function now() {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * @return WP_Error No report of that id.
	 */
	private static function unknown() {
		return new WP_Error( 'ab_mcp_sync_report_unknown', __( 'This site holds no report of that id (a report is kept a day at most, and a new one of the same connection replaces it). Make a report: call wp_site_diff with the packages of the inventory of the staging site.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error The files of a report are not as one call wrote them.
	 */
	public static function concurrent() {
		return new WP_Error( 'ab_mcp_sync_report_parts', __( 'The files of this report are not as one call after the other wrote them (two calls went on with it at once, or a file was changed in the protected store). Make a new report: call wp_site_diff with the packages of the inventory of the staging site, and go on with one call at a time.', 'alphabridge-mcp' ) );
	}
}
