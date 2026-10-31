<?php
/**
 * The staging record of a site and its status (free since 4.15.0).
 *
 * The guided way to a staging site needs to know what a site is: the
 * production site the public sees, or the staging site where the work is
 * done first. Only a person can say that, so the role is what a person
 * confirmed, on the settings page or in the hub; what the site shows (the
 * environment type WordPress reports, the address, the preview protection,
 * the search engine visibility) is offered as a suggestion and never taken
 * as proof. A site whose role nobody confirmed counts as the production
 * site (the plan of 31.10.2026, decision 7): the later pre-check of writes
 * holds such a site back rather than wave it through.
 *
 * The record, in AB_MCP_Settings::OPT_OPTIONS under KEY (the option
 * ab_mcp_options, which uninstall.php removes):
 *
 * - role: production or staging, with who confirmed it, when, and where
 *   (SOURCE_FORM on the settings page, SOURCE_HUB from the hub). No role
 *   is «unconfirmed».
 * - pair: the address of the other site and its role (always the other
 *   one: a pair is one production site and one staging site), with who
 *   confirmed it, when and where. A pair needs a confirmed role; a stored
 *   pair whose role equals this site's role, or a pair without a role,
 *   reads as no pair, so a broken record never counts as paired.
 * - host: the host a person chose, or «unknown»; without a choice the host
 *   is what detect_host() finds.
 *
 * The same record is written by the form on the settings page (a site
 * without the hub, a local copy) and by the hub over the REST route ROUTE
 * with the site token of the person who confirmed the pair there (the
 * plan, decision 6): both say the same, and wp_staging_status reports both
 * the same way, naming the source.
 *
 * The check whether the other site answers is a request from this site to
 * the stored address, made only when asked for (check_partner()): every A
 * and AAAA address of its host must be public, and the connection is tied
 * to exactly these addresses (AB_MCP_Outbound::get(), against DNS
 * rebinding); the request goes through wp_safe_remote_get(), which checks
 * it again, and follows no redirect. It asks the MCP endpoint of the other
 * site without a token, whose 401 names AlphaBridge MCP
 * (AB_MCP_Connection_Doctor::is_own_answer()); the preview protection
 * leaves that endpoint open, so a protected staging site answers too.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-preview-protection.php';
require_once __DIR__ . '/class-outbound.php';

/**
 * Class AB_MCP_Staging
 */
class AB_MCP_Staging {

	/**
	 * Key of the record in AB_MCP_Settings::OPT_OPTIONS.
	 */
	const KEY = 'staging';

	/**
	 * The roles. UNCONFIRMED is no stored value: it is what a site without
	 * a confirmed role reads as.
	 */
	const ROLE_UNCONFIRMED = 'unconfirmed';
	const ROLE_PRODUCTION  = 'production';
	const ROLE_STAGING     = 'staging';

	/**
	 * Where a role or a pair was confirmed.
	 */
	const SOURCE_FORM = 'form';
	const SOURCE_HUB  = 'hub';

	/**
	 * The host a person may choose when it is not detected, or to say it
	 * is none of HOSTS.
	 */
	const HOST_UNKNOWN = 'unknown';

	/**
	 * The hosts a person can choose, the ones the staging concept of
	 * 30.10.2026 names. Only some are detected (detect_host()); the others
	 * are chosen by the person.
	 */
	const HOSTS = array(
		'kinsta'     => 'Kinsta',
		'wpengine'   => 'WP Engine',
		'siteground' => 'SiteGround',
		'cloudways'  => 'Cloudways',
		'hostinger'  => 'Hostinger',
		'infomaniak' => 'Infomaniak',
	);

	/**
	 * The REST route of the hub, under AB_MCP_REST_NAMESPACE.
	 */
	const ROUTE = '/staging-pair';

	/**
	 * Anchor of the form on the settings page.
	 */
	const ANCHOR = 'ab-staging';

	/**
	 * Beginnings of a host name that suggest a staging site.
	 */
	const DOMAIN_PREFIXES = array( 'staging.', 'stage.', 'dev.', 'test.' );

	/**
	 * Endings of a host name reserved for testing and local networks
	 * (RFC 6761: .test and .localhost; RFC 6762: .local), which suggest a
	 * copy for development.
	 */
	const DOMAIN_SUFFIXES = array( '.test', '.local', '.localhost' );

	/**
	 * How long the check of the other site waits, in seconds.
	 */
	const CHECK_TIMEOUT = 10;

	/* ----------------------------------------------------------- record */

	/**
	 * The stored record in a known shape. A part that is not exactly what
	 * save() writes reads as absent.
	 *
	 * @return array{role:array|null,pair:array|null,host:array|null}
	 */
	public static function record() {
		$raw  = AB_MCP_Settings::get( self::KEY, array() );
		$raw  = is_array( $raw ) ? $raw : array();
		$role = self::read_role( isset( $raw['role'] ) ? $raw['role'] : null );
		return array(
			'role' => $role,
			'pair' => null !== $role ? self::read_pair( isset( $raw['pair'] ) ? $raw['pair'] : null, $role['value'] ) : null,
			'host' => self::read_host( isset( $raw['host'] ) ? $raw['host'] : null ),
		);
	}

	/**
	 * The confirmed role, or ROLE_UNCONFIRMED.
	 *
	 * @return string
	 */
	public static function role() {
		$role = self::record()['role'];
		return null !== $role ? $role['value'] : self::ROLE_UNCONFIRMED;
	}

	/**
	 * What the site counts as: the staging site only where that is
	 * confirmed; the production site otherwise, unconfirmed included.
	 *
	 * @return string ROLE_PRODUCTION or ROLE_STAGING.
	 */
	public static function treated_as() {
		return self::ROLE_STAGING === self::role() ? self::ROLE_STAGING : self::ROLE_PRODUCTION;
	}

	/**
	 * The other role of a pair.
	 *
	 * @param string $role ROLE_PRODUCTION or ROLE_STAGING.
	 * @return string
	 */
	public static function other_role( $role ) {
		return self::ROLE_STAGING === $role ? self::ROLE_PRODUCTION : self::ROLE_STAGING;
	}

	/**
	 * Is a value a role that can be confirmed?
	 *
	 * @param mixed $role Value.
	 * @return bool
	 */
	public static function is_role( $role ) {
		return self::ROLE_PRODUCTION === $role || self::ROLE_STAGING === $role;
	}

	/**
	 * A stored role, or null.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|null
	 */
	private static function read_role( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['value'] ) || ! self::is_role( $raw['value'] ) ) {
			return null;
		}
		return array_merge( array( 'value' => $raw['value'] ), self::read_who( $raw ) );
	}

	/**
	 * A stored pair, or null: the address as save() normalises it and the
	 * other role.
	 *
	 * @param mixed  $raw  Stored value.
	 * @param string $role This site's confirmed role.
	 * @return array|null
	 */
	private static function read_pair( $raw, $role ) {
		if ( ! is_array( $raw ) || ! isset( $raw['partner_url'], $raw['partner_role'] ) ) {
			return null;
		}
		if ( '' === $raw['partner_url'] || self::normalize_url( $raw['partner_url'] ) !== $raw['partner_url'] ) {
			return null;
		}
		if ( ! self::is_role( $raw['partner_role'] ) || $raw['partner_role'] === $role ) {
			return null;
		}
		return array_merge(
			array(
				'partner_url'  => $raw['partner_url'],
				'partner_role' => $raw['partner_role'],
			),
			self::read_who( $raw )
		);
	}

	/**
	 * A stored choice of host, or null.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|null
	 */
	private static function read_host( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['value'] ) || ! is_string( $raw['value'] ) ) {
			return null;
		}
		if ( self::HOST_UNKNOWN !== $raw['value'] && ! isset( self::HOSTS[ $raw['value'] ] ) ) {
			return null;
		}
		return array_merge( array( 'value' => $raw['value'] ), self::read_who( $raw ) );
	}

	/**
	 * Who, when and where of a stored part.
	 *
	 * @param array $raw Stored part.
	 * @return array{user_id:int,user_login:string,source:string,at:int}
	 */
	private static function read_who( array $raw ) {
		$source = isset( $raw['source'] ) ? $raw['source'] : '';
		return array(
			'user_id'    => isset( $raw['user_id'] ) ? (int) $raw['user_id'] : 0,
			'user_login' => isset( $raw['user_login'] ) && is_string( $raw['user_login'] ) ? $raw['user_login'] : '',
			'source'     => self::SOURCE_FORM === $source || self::SOURCE_HUB === $source ? $source : '',
			'at'         => isset( $raw['at'] ) ? (int) $raw['at'] : 0,
		);
	}

	/**
	 * An address of a site as stored: scheme and host in lower case, the
	 * port unless it is the one of the scheme, the path without a slash at
	 * the end; '' for anything that is
	 * no http(s) address of a host, that holds a space, a control character
	 * or a character an address writes encoded (a quote, < >, a backslash,
	 * ^ ` { | }), or that carries a user name, a password, a query or a
	 * fragment (a password does not belong in this record, and the other
	 * parts name no site).
	 *
	 * @param mixed $url Address.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( strlen( $url ) > 2048 || ! preg_match( '#^https?://#i', $url ) || preg_match( '/[\x00-\x20\x7f"<>\\\\^`{|}]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$out    = $scheme . '://' . strtolower( $parts['host'] );
		// The port of the scheme is the address without it.
		if ( isset( $parts['port'] ) && (int) $parts['port'] !== ( 'https' === $scheme ? 443 : 80 ) ) {
			$out .= ':' . (int) $parts['port'];
		}
		return $out . rtrim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );
	}

	/**
	 * Do two addresses name the same site? As normalize_url() stores them
	 * (host without regard to case, the port of the scheme left out, no
	 * slash at the end), and with either scheme: http:// and https:// of one
	 * host and path are one site.
	 *
	 * @param string $a Address.
	 * @param string $b Address.
	 * @return bool
	 */
	public static function same_site( $a, $b ) {
		$strip = static function ( $url ) {
			return (string) preg_replace( '#^https?://#', '', self::normalize_url( $url ) );
		};
		$a = $strip( $a );
		return '' !== $a && $strip( $b ) === $a;
	}

	/**
	 * Confirm the role of this site and the pair, as the settings page and
	 * the hub do. Nothing is written when anything is wrong; a part that
	 * stays as it was keeps who confirmed it, when and where.
	 *
	 * A role of ROLE_UNCONFIRMED takes the confirmed role back (the
	 * settings page only); a pair needs a confirmed role, so it goes with
	 * it, and an address with it is refused. An empty address removes the
	 * pair (the settings page only; the hub always names the other site).
	 *
	 * @param string      $role         ROLE_UNCONFIRMED, ROLE_PRODUCTION or ROLE_STAGING.
	 * @param string      $partner_url  Address of the other site, or ''.
	 * @param string|null $partner_role Its role; null for the other one (the settings page).
	 * @param int         $user_id      Who confirms.
	 * @param string      $source       SOURCE_FORM or SOURCE_HUB.
	 * @return array{role_changed:bool,pair_changed:bool}|WP_Error
	 */
	public static function save( $role, $partner_url, $partner_role, $user_id, $source ) {
		$checked = self::validate( $role, $partner_url, $partner_role, $source );
		if ( array() !== $checked['problems'] ) {
			return self::invalid( $checked['problems'] );
		}
		$url          = $checked['url'];
		$partner_role = $checked['partner_role'];
		$unconfirm    = self::ROLE_UNCONFIRMED === $role;

		$raw    = AB_MCP_Settings::get( self::KEY, array() );
		$raw    = is_array( $raw ) ? $raw : array();
		$stored = self::record();
		$who    = self::who( (int) $user_id, (string) $source );

		$role_changed = $unconfirm
			? null !== $stored['role']
			: ( null === $stored['role'] || $stored['role']['value'] !== $role );
		if ( $unconfirm ) {
			unset( $raw['role'] );
		} elseif ( $role_changed ) {
			$raw['role'] = array_merge( array( 'value' => $role ), $who );
		}

		// A stored pair exists only with a stored role, and its role is the
		// other one; a new role therefore never keeps the old pair.
		$keep_pair    = '' !== $url && null !== $stored['pair']
			&& $stored['pair']['partner_url'] === $url && $stored['pair']['partner_role'] === $partner_role;
		$pair_changed = ! $keep_pair && ( '' !== $url || null !== $stored['pair'] );
		if ( '' === $url ) {
			unset( $raw['pair'] );
		} elseif ( ! $keep_pair ) {
			$raw['pair'] = array_merge(
				array(
					'partner_url'  => $url,
					'partner_role' => $partner_role,
				),
				$who
			);
		}

		if ( $role_changed || $pair_changed ) {
			AB_MCP_Settings::set( self::KEY, $raw );
		}
		return array(
			'role_changed' => $role_changed,
			'pair_changed' => $pair_changed,
		);
	}

	/**
	 * What save() checks, without writing: the problems (codes of
	 * problem()), the address as it would be stored and the role of the
	 * other site.
	 *
	 * @param string      $role         See save().
	 * @param string      $partner_url  See save().
	 * @param string|null $partner_role See save().
	 * @param string      $source       See save().
	 * @return array{problems:string[],url:string,partner_role:string|null}
	 */
	public static function validate( $role, $partner_url, $partner_role, $source ) {
		$problems = array();
		$hub      = self::SOURCE_HUB === $source;
		if ( ! $hub && self::SOURCE_FORM !== $source ) {
			$problems[] = 'source';
		}
		$unconfirm = ! $hub && self::ROLE_UNCONFIRMED === $role;
		if ( ! $unconfirm && ! self::is_role( $role ) ) {
			$problems[] = 'role';
		}
		$partner_url = is_string( $partner_url ) ? trim( $partner_url ) : '';
		$url         = '';
		if ( '' !== $partner_url || $hub ) {
			$url = self::normalize_url( $partner_url );
			if ( '' === $url ) {
				$problems[] = 'partner_url';
			} elseif ( self::same_site( $url, home_url() ) ) {
				$problems[] = 'self';
			} elseif ( false !== filter_var( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				// WordPress asks no IPv6 address (wp_http_validate_url()
				// reads a host as IPv4 or as a name), so the check could
				// never reach such a partner. An IPv4 address works and
				// stays allowed: a fresh staging server may have no name yet.
				$problems[] = 'ipv6';
			}
			if ( null === $partner_role && ! $hub && self::is_role( $role ) ) {
				$partner_role = self::other_role( $role );
			}
			if ( $unconfirm ) {
				$problems[] = 'no_role';
			} elseif ( ! self::is_role( $partner_role ) ) {
				$problems[] = 'partner_role';
			} elseif ( $partner_role === $role ) {
				$problems[] = 'same_roles';
			}
		}
		return array(
			'problems'     => $problems,
			'url'          => $url,
			'partner_role' => $partner_role,
		);
	}

	/**
	 * Is a value a choice of host: a key of HOSTS, HOST_UNKNOWN, or '' for
	 * «as detected»?
	 *
	 * @param mixed $choice Value.
	 * @return bool
	 */
	public static function is_host_choice( $choice ) {
		return '' === $choice || self::HOST_UNKNOWN === $choice || ( is_string( $choice ) && isset( self::HOSTS[ $choice ] ) );
	}

	/**
	 * Choose the host, or take the choice back ('': as detected).
	 *
	 * @param string $choice  A key of HOSTS, HOST_UNKNOWN or ''.
	 * @param int    $user_id Who chooses.
	 * @return bool|WP_Error Whether anything changed.
	 */
	public static function set_host( $choice, $user_id ) {
		if ( ! self::is_host_choice( $choice ) ) {
			return self::invalid( array( 'host' ) );
		}
		$raw    = AB_MCP_Settings::get( self::KEY, array() );
		$raw    = is_array( $raw ) ? $raw : array();
		$stored = self::record()['host'];
		if ( '' === $choice ) {
			if ( null === $stored ) {
				return false;
			}
			unset( $raw['host'] );
		} else {
			if ( null !== $stored && $stored['value'] === $choice ) {
				return false;
			}
			$who = self::who( (int) $user_id, self::SOURCE_FORM );
			unset( $who['source'] );
			$raw['host'] = array_merge( array( 'value' => $choice ), $who );
		}
		AB_MCP_Settings::set( self::KEY, $raw );
		return true;
	}

	/**
	 * Who, when and where for a part written now.
	 *
	 * @param int    $user_id Who.
	 * @param string $source  Where.
	 * @return array
	 */
	private static function who( $user_id, $source ) {
		$user = get_user_by( 'id', $user_id );
		return array(
			'user_id'    => $user_id,
			'user_login' => ( is_object( $user ) && isset( $user->user_login ) ) ? (string) $user->user_login : '',
			'source'     => $source,
			'at'         => time(),
		);
	}

	/**
	 * The refusal of save() and set_host(): every problem in one sentence,
	 * the fields in the data, HTTP 400 for the REST route.
	 *
	 * @param string[] $problems Codes, see problem().
	 * @return WP_Error
	 */
	private static function invalid( array $problems ) {
		$problems = array_values( array_unique( $problems ) );
		return new WP_Error(
			'ab_mcp_staging_invalid',
			/* translators: %s: what is wrong, one or more sentences. */
			sprintf( __( 'Nothing was saved: %s', 'alphabridge-mcp' ), implode( ' ', array_map( array( __CLASS__, 'problem' ), $problems ) ) ),
			array(
				'status'   => 400,
				'problems' => $problems,
			)
		);
	}

	/**
	 * What is wrong, for a code of invalid().
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function problem( $code ) {
		switch ( $code ) {
			case 'source':
				return __( 'The source must be the hub.', 'alphabridge-mcp' );
			case 'role':
				return __( 'The role of this site must be production site or staging site.', 'alphabridge-mcp' );
			case 'partner_role':
				return __( 'The role of the other site must be production site or staging site.', 'alphabridge-mcp' );
			case 'same_roles':
				return __( 'The two roles must differ: a pair is one production site and one staging site.', 'alphabridge-mcp' );
			case 'partner_url':
				return __( 'The address of the other site must be an http:// or https:// address, without user name, password, query or fragment.', 'alphabridge-mcp' );
			case 'self':
				return __( 'The address of the other site is the address of this site.', 'alphabridge-mcp' );
			case 'no_role':
				return __( 'A pair needs the role of this site first: choose production site or staging site.', 'alphabridge-mcp' );
			case 'ipv6':
				return __( 'The address of the other site is an IPv6 address, which WordPress does not ask: use the host name of the other site.', 'alphabridge-mcp' );
			default:
				return __( 'The host must be one of the list, or not known.', 'alphabridge-mcp' );
		}
	}

	/* ------------------------------------------------------- suggestion */

	/**
	 * What the site shows about its role: hints, never proof. Each hint
	 * names where it comes from. The search engine visibility counts only
	 * while the preview protection is off; with it on, the 0 is the
	 * protection's own.
	 *
	 * @return array{role:string,hints:array<int,array{source:string,finding:string}>}
	 */
	public static function suggestion() {
		$hints = array();

		$environment = wp_get_environment_type();
		if ( 'production' !== $environment ) {
			$hints[] = array(
				'source'  => 'environment_type',
				/* translators: %s: the environment type, such as staging or development. */
				'finding' => sprintf( __( 'WordPress reports the environment type “%s”.', 'alphabridge-mcp' ), $environment ),
			);
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( self::DOMAIN_PREFIXES as $prefix ) {
			if ( 0 === strpos( $host, $prefix ) ) {
				$hints[] = array(
					'source'  => 'domain',
					/* translators: %s: the beginning of the address, such as staging. */
					'finding' => sprintf( __( 'The address begins with “%s”.', 'alphabridge-mcp' ), $prefix ),
				);
			}
		}
		foreach ( self::DOMAIN_SUFFIXES as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				$hints[] = array(
					'source'  => 'domain',
					/* translators: %s: the end of the address, such as .test. */
					'finding' => sprintf( __( 'The address ends in “%s”, a name for testing or local development.', 'alphabridge-mcp' ), $suffix ),
				);
			}
		}

		$protected = AB_MCP_Preview_Protection::is_on();
		if ( $protected ) {
			$hints[] = array(
				'source'  => 'preview_protection',
				'finding' => __( 'The preview protection is on.', 'alphabridge-mcp' ),
			);
		} elseif ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$hints[] = array(
				'source'  => 'blog_public',
				'finding' => __( 'Search engines are discouraged (blog_public is 0).', 'alphabridge-mcp' ),
			);
		}

		return array(
			'role'  => array() !== $hints ? self::ROLE_STAGING : self::ROLE_PRODUCTION,
			'hints' => $hints,
		);
	}

	/* ------------------------------------------------------------- host */

	/**
	 * The host, from what the site shows. The markers are the ones WP Rocket
	 * uses to recognise these hosts (wp-media/wp-rocket,
	 * inc/ThirdParty/Hostings/HostResolver.php, read 31.10.2026), in its
	 * order; Hostinger and Infomaniak have none there and are only chosen.
	 * SiteGround's is its optimisation plugin, which also runs elsewhere:
	 * the evidence says so, and a person's choice overrides it.
	 *
	 * @param array|null $server  Server variables; $_SERVER when null.
	 * @param array|null $plugins Active plugins (basenames); the site's when null.
	 * @return array{host:string,evidence:string[]} host '' when nothing was found.
	 */
	public static function detect_host( $server = null, $plugins = null ) {
		$server  = is_array( $server ) ? $server : $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared, never output.
		$plugins = is_array( $plugins ) ? $plugins : self::active_plugins();
		if ( isset( $server['cw_allowed_ip'] ) ) {
			return self::found( 'cloudways', 'The server variable cw_allowed_ip is set.' );
		}
		if ( isset( $server['DOCUMENT_ROOT'] ) && false !== strpos( (string) $server['DOCUMENT_ROOT'], '.cloudwaysapps.com/' ) ) {
			return self::found( 'cloudways', 'The document root lies below .cloudwaysapps.com/.' );
		}
		if ( class_exists( 'WpeCommon', false ) && function_exists( 'wpe_param' ) ) {
			return self::found( 'wpengine', 'The class WpeCommon and the function wpe_param of WP Engine\'s must-use plugin are loaded.' );
		}
		if ( isset( $server['KINSTA_CACHE_ZONE'] ) ) {
			return self::found( 'kinsta', 'The server variable KINSTA_CACHE_ZONE is set.' );
		}
		if ( in_array( 'sg-cachepress/sg-cachepress.php', $plugins, true ) ) {
			return self::found( 'siteground', 'The plugin SiteGround Speed Optimizer (sg-cachepress) is active; it runs on other hosts too, so this is a hint.' );
		}
		return array(
			'host'     => '',
			'evidence' => array(),
		);
	}

	/**
	 * A detected host with its evidence.
	 *
	 * @param string $host     Key of HOSTS.
	 * @param string $evidence What showed it.
	 * @return array
	 */
	private static function found( $host, $evidence ) {
		return array(
			'host'     => $host,
			'evidence' => array( $evidence ),
		);
	}

	/**
	 * Active plugins of this site and of the network, as basenames.
	 *
	 * @return string[]
	 */
	private static function active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_filter( array_map( 'strval', $active ) ) );
	}

	/**
	 * The host as it counts: the person's choice, else the detected one,
	 * else unknown.
	 *
	 * @return array{value:string,name:string,source:string,detected:string|null,evidence:string[],chosen_by:array|null}
	 */
	public static function host() {
		$chosen   = self::record()['host'];
		$detected = self::detect_host();
		if ( null !== $chosen ) {
			$value  = $chosen['value'];
			$source = 'chosen';
		} elseif ( '' !== $detected['host'] ) {
			$value  = $detected['host'];
			$source = 'detected';
		} else {
			$value  = self::HOST_UNKNOWN;
			$source = 'none';
		}
		return array(
			'value'     => $value,
			'name'      => isset( self::HOSTS[ $value ] ) ? self::HOSTS[ $value ] : '',
			'source'    => $source,
			'detected'  => '' !== $detected['host'] ? $detected['host'] : null,
			'evidence'  => $detected['evidence'],
			'chosen_by' => null !== $chosen ? self::who_out( $chosen, false ) : null,
		);
	}

	/* ------------------------------------------------------ partner check */

	/**
	 * The address the check asks: the MCP endpoint of the other site as a
	 * query, which needs no pretty permalinks there.
	 *
	 * @param string $partner_url Stored address.
	 * @return string
	 */
	public static function check_url( $partner_url ) {
		return $partner_url . '/?rest_route=/' . AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE;
	}

	/**
	 * Ask the other site whether AlphaBridge MCP answers there. One GET to
	 * its MCP endpoint without a token, tied to the checked addresses of its
	 * host (AB_MCP_Outbound::get()); nothing is sent about this site (the
	 * user agent names the plugin only), and no redirect is followed.
	 *
	 * @param string $partner_url Stored address.
	 * @return array{status:string,url:string,http_status:int|null,finding:string,location?:string}
	 */
	public static function check_partner( $partner_url ) {
		$url      = self::check_url( $partner_url );
		$out      = array(
			'url'         => $url,
			'http_status' => null,
		);
		$response = AB_MCP_Outbound::get(
			$url,
			array(
				'timeout'             => self::CHECK_TIMEOUT,
				'limit_response_size' => 4096,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'AlphaBridge-MCP/' . AB_MCP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			switch ( $response->get_error_code() ) {
				case 'ab_mcp_outbound_local':
					$out['status'] = 'blocked';
					/* translators: %s: address of the other site. */
					$out['finding'] = sprintf( __( 'This site does not contact %s: the address is local or private. A local copy cannot be checked from here.', 'alphabridge-mcp' ), $partner_url );
					break;
				case 'ab_mcp_outbound_unresolved':
					$out['status'] = 'unresolved';
					/* translators: %s: address of the other site. */
					$out['finding'] = sprintf( __( 'The name of the other site %s does not resolve to an address.', 'alphabridge-mcp' ), $partner_url );
					break;
				case 'ab_mcp_outbound_proxy':
					$out['status'] = 'proxy';
					/* translators: %s: address of the other site. */
					$out['finding'] = sprintf( __( 'This site sends its requests through a proxy (WP_PROXY_HOST), which looks the name of %s up itself, so the request could not be tied to the checked address and was not sent.', 'alphabridge-mcp' ), $partner_url );
					break;
				case 'ab_mcp_outbound_unbound':
					$out['status'] = 'unbound';
					/* translators: %s: address of the other site. */
					$out['finding'] = sprintf( __( 'This site does not ask %s: WordPress sends its requests here without cURL, and only cURL can be tied to the checked address.', 'alphabridge-mcp' ), $partner_url );
					break;
				default:
					$out['status'] = 'unreachable';
					/* translators: 1: address of the other site, 2: the error WordPress reports. */
					$out['finding'] = sprintf( __( 'The other site %1$s could not be reached: %2$s', 'alphabridge-mcp' ), $partner_url, $response->get_error_message() );
			}
			return $out;
		}
		$answer = array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => AB_MCP_Connection_Doctor::header_list( wp_remote_retrieve_headers( $response ) ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => '',
		);
		$out['http_status'] = $answer['status'];
		if ( AB_MCP_Connection_Doctor::is_own_answer( $answer ) ) {
			$out['status'] = 'reachable';
			/* translators: %s: address of the other site. */
			$out['finding'] = sprintf( __( 'The other site %s answers, and AlphaBridge MCP runs there.', 'alphabridge-mcp' ), $partner_url );
		} elseif ( AB_MCP_Connection_Doctor::is_login_challenge( $answer ) ) {
			$out['status'] = 'login';
			/* translators: %s: address of the other site. */
			$out['finding'] = sprintf( __( 'The other site %s asks for an HTTP login, which keeps apps and the hub out.', 'alphabridge-mcp' ), $partner_url );
		} elseif ( $answer['status'] >= 300 && $answer['status'] < 400 && isset( $answer['headers']['location'] ) ) {
			$out['status']   = 'redirect';
			$out['location'] = $answer['headers']['location'];
			/* translators: 1: address of the other site, 2: the address it redirects to. */
			$out['finding'] = sprintf( __( 'The other site %1$s redirects to %2$s.', 'alphabridge-mcp' ), $partner_url, $out['location'] );
		} else {
			$out['status'] = 'not_alphabridge';
			/* translators: 1: address of the other site, 2: HTTP status code. */
			$out['finding'] = sprintf( __( 'The other site %1$s answers with HTTP %2$d, but not as AlphaBridge MCP.', 'alphabridge-mcp' ), $partner_url, $answer['status'] );
		}
		return $out;
	}

	/* ----------------------------------------------------------- report */

	/**
	 * Who, when and where of a part, as the tool and the route report it.
	 *
	 * @param array $part        Stored part.
	 * @param bool  $with_source Whether to name the source.
	 * @return array
	 */
	private static function who_out( array $part, $with_source = true ) {
		$out = array(
			'user_id'    => $part['user_id'],
			'user_login' => $part['user_login'],
			'at'         => $part['at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $part['at'] ) : '',
		);
		if ( $with_source ) {
			$out['source'] = $part['source'];
		}
		return $out;
	}

	/**
	 * The role and the pair as the tool and the REST route report them.
	 *
	 * @return array{role:array,pair:array|null}
	 */
	public static function summary() {
		$record = self::record();
		$role   = $record['role'];
		$pair   = $record['pair'];
		return array(
			'role' => array(
				'value'      => null !== $role ? $role['value'] : self::ROLE_UNCONFIRMED,
				'confirmed'  => null !== $role,
				'treated_as' => self::treated_as(),
				'set_by'     => null !== $role ? self::who_out( $role ) : null,
			),
			'pair' => null !== $pair
				? array(
					'partner_url'  => $pair['partner_url'],
					'partner_role' => $pair['partner_role'],
					'confirmed_by' => self::who_out( $pair ),
				)
				: null,
		);
	}

	/**
	 * Everything wp_staging_status reports. Reads only; the check of the
	 * other site is made only with $check.
	 *
	 * @param bool $check Whether to ask the other site.
	 * @return array
	 */
	public static function report( $check ) {
		$out                       = self::summary();
		$out['role']['suggestion'] = array_merge(
			self::suggestion(),
			array( 'note' => 'A suggestion from what the site shows, never proof: only a person confirms the role, on the settings page or in the hub. Until then the site counts as the production site.' )
		);

		$partner_check = null;
		if ( $check ) {
			$partner_check = null !== $out['pair']
				? self::check_partner( $out['pair']['partner_url'] )
				: array( 'status' => 'no_pair' );
		}
		$out['partner_check'] = $partner_check;

		$out['host'] = self::host();
		$protection  = AB_MCP_Preview_Protection::summary();

		$out['preview_protection'] = array(
			'enabled' => $protection['enabled'],
			'since'   => $protection['since'],
		);
		$out['blog_public']        = $protection['blog_public'];

		$items            = self::checklist( $out['role']['value'], $out['pair'], $partner_check, $protection['enabled'], $out['host'] );
		$out['checklist'] = $items;
		$out['next_step'] = self::next_step( $items, $out['role']['value'], $out['pair'] );

		$out['warnings'] = array();
		if ( self::ROLE_PRODUCTION === $out['role']['value'] && $protection['enabled'] ) {
			$out['warnings'][] = __( 'The preview protection is on, but this site is confirmed as the production site: its visitors see the password page.', 'alphabridge-mcp' );
		}
		return $out;
	}

	/**
	 * The checklist of the staging way, in order: role confirmed, a staging
	 * site there and answering, its preview protection on, the pair set.
	 * done is true, false, or null where it is not decided here (checked on
	 * the staging site, or not checked yet: then check says how). An open
	 * item has its steps, and some a fallback for when the steps fail.
	 *
	 * @param string     $role       The confirmed role or ROLE_UNCONFIRMED.
	 * @param array|null $pair       Pair as summary() reports it.
	 * @param array|null $check      Result of check_partner(), or null.
	 * @param bool       $protected  Whether the preview protection is on.
	 * @param array      $host       As host() reports it.
	 * @return array<int,array>
	 */
	public static function checklist( $role, $pair, $check, $protected, array $host ) {
		$staging = self::ROLE_STAGING === $role;
		$items   = array();

		// 1. The role.
		if ( self::is_role( $role ) ) {
			$items[] = self::item(
				'role_confirmed',
				__( 'Role confirmed', 'alphabridge-mcp' ),
				true,
				$staging ? __( 'This site is confirmed as the staging site.', 'alphabridge-mcp' ) : __( 'This site is confirmed as the production site.', 'alphabridge-mcp' )
			);
		} else {
			$items[] = self::item(
				'role_confirmed',
				__( 'Role confirmed', 'alphabridge-mcp' ),
				false,
				__( 'The role of this site is not confirmed yet, so it counts as the production site.', 'alphabridge-mcp' ),
				__( '1. Open the settings page of AlphaBridge MCP at Staging. 2. Choose whether this site is the production site or the staging site; the suggestion there is a hint, not proof. 3. Save.', 'alphabridge-mcp' )
			);
		}

		// 2. A staging site there and answering.
		$title = __( 'Staging site there and answering', 'alphabridge-mcp' );
		if ( $staging ) {
			$items[] = self::item( 'staging_connected', $title, true, __( 'This site is the staging site.', 'alphabridge-mcp' ) );
		} elseif ( null === $pair ) {
			$steps   = '' !== $host['name']
				/* translators: %s: name of the host, such as Kinsta. */
				? sprintf( __( '1. Create a staging copy of this site with the staging function in the control panel of %s. 2. Install and activate AlphaBridge MCP on the copy. 3. Enter the address of the copy on the settings page of this site at Staging, and the address of this site on the copy.', 'alphabridge-mcp' ), $host['name'] )
				: __( '1. Create a staging copy of this site: with the staging function of your host, if it has one, or with a staging plugin from the WordPress plugin directory. 2. Install and activate AlphaBridge MCP on the copy. 3. Enter the address of the copy on the settings page of this site at Staging, and the address of this site on the copy.', 'alphabridge-mcp' );
			$item             = self::item( 'staging_connected', $title, false, __( 'No staging site is known for this site yet.', 'alphabridge-mcp' ), $steps );
			$item['fallback'] = __( 'If neither the host nor a plugin can make a copy: for changes to posts and pages alone, work in drafts and previews, and publish once the person has approved them; media, menus and settings take effect at once, so they need a staging site.', 'alphabridge-mcp' );
			$items[]          = $item;
		} else {
			$items[] = self::staging_item( $title, $pair['partner_url'], $check );
		}

		// 3. The preview protection of the staging site.
		$title = __( 'Preview protection on the staging site', 'alphabridge-mcp' );
		if ( ! $staging ) {
			$items[] = self::item( 'protection_on_staging', $title, null, __( 'The preview protection is checked on the staging site itself.', 'alphabridge-mcp' ) );
		} elseif ( $protected ) {
			$items[] = self::item( 'protection_on_staging', $title, true, __( 'The preview protection is on.', 'alphabridge-mcp' ) );
		} else {
			$items[] = self::item(
				'protection_on_staging',
				$title,
				false,
				__( 'The preview protection is off: visitors and search engines can read the staging site.', 'alphabridge-mcp' ),
				__( '1. Switch on the preview protection on the settings page at Staging, or have the assistant call wp_set_preview_protection. 2. Pass the password it shows on to whoever should see the preview.', 'alphabridge-mcp' )
			);
		}

		// 4. The pair.
		$title = __( 'Pair set', 'alphabridge-mcp' );
		if ( null !== $pair ) {
			$items[] = self::item(
				'pair_set',
				$title,
				true,
				self::ROLE_STAGING === $pair['partner_role']
					/* translators: %s: address of the other site. */
					? sprintf( __( 'Paired with the staging site %s.', 'alphabridge-mcp' ), $pair['partner_url'] )
					/* translators: %s: address of the other site. */
					: sprintf( __( 'Paired with the production site %s.', 'alphabridge-mcp' ), $pair['partner_url'] )
			);
		} else {
			$items[] = self::item(
				'pair_set',
				$title,
				false,
				__( 'No pair is set yet.', 'alphabridge-mcp' ),
				__( '1. Confirm the role of this site, if it is not yet. 2. Enter the address of the other site on the settings page at Staging and save. 3. On the other site, enter the address of this one.', 'alphabridge-mcp' )
			);
		}

		return $items;
	}

	/**
	 * The item «staging site there and answering» of a production site
	 * with a pair: open while unchecked, then what the check found.
	 *
	 * @param string     $title       Title.
	 * @param string     $partner_url Address of the staging site.
	 * @param array|null $check       Result of check_partner(), or null.
	 * @return array
	 */
	private static function staging_item( $title, $partner_url, $check ) {
		if ( null === $check ) {
			/* translators: %s: address of the other site. */
			$item          = self::item( 'staging_connected', $title, null, sprintf( __( 'The staging site %s is entered; whether it answers has not been checked.', 'alphabridge-mcp' ), $partner_url ) );
			$item['check'] = 'Call wp_staging_status with check_partner=true to check that the staging site answers.';
			return $item;
		}
		switch ( $check['status'] ) {
			case 'reachable':
				return self::item( 'staging_connected', $title, true, $check['finding'] );
			case 'blocked':
			case 'unbound':
				return self::item( 'staging_connected', $title, null, $check['finding'] );
			case 'login':
				return self::item( 'staging_connected', $title, false, $check['finding'], __( '1. Switch off the host\'s password protection for the staging site. 2. Switch on the preview protection of AlphaBridge MCP there instead: it keeps visitors out and lets apps and the hub in.', 'alphabridge-mcp' ) );
			case 'redirect':
				return self::item( 'staging_connected', $title, false, $check['finding'], __( '1. If that is the staging site, enter that address on the settings page at Staging. 2. Check again.', 'alphabridge-mcp' ) );
			case 'proxy':
				return self::item( 'staging_connected', $title, false, $check['finding'], __( '1. To let this site ask the staging site directly, add its host name to WP_PROXY_BYPASS_HOSTS in wp-config.php and check again. 2. Or check by hand: open the address of the staging site in a browser and see that it answers.', 'alphabridge-mcp' ) );
			case 'not_alphabridge':
				return self::item( 'staging_connected', $title, false, $check['finding'], __( '1. Install and activate AlphaBridge MCP on the staging site. 2. Check again.', 'alphabridge-mcp' ) );
			default:
				return self::item( 'staging_connected', $title, false, $check['finding'], __( '1. Open the address in a browser and check that it is the staging site. 2. Correct it on the settings page at Staging if it is not.', 'alphabridge-mcp' ) );
		}
	}

	/**
	 * One item of the checklist.
	 *
	 * @param string    $id      Id.
	 * @param string    $title   Title.
	 * @param bool|null $done    Done, open, or not decided here.
	 * @param string    $finding What is the case.
	 * @param string    $steps   What to do; only for an open item.
	 * @return array
	 */
	private static function item( $id, $title, $done, $finding, $steps = '' ) {
		$item = array(
			'id'      => $id,
			'title'   => $title,
			'done'    => $done,
			'finding' => $finding,
		);
		if ( false === $done ) {
			$item['steps'] = $steps;
		}
		return $item;
	}

	/**
	 * The next step: the first open item with its steps, its fallback and
	 * the direct link (AB_MCP_Guidance::compose()); without one, the first
	 * check the assistant can make from here, or why the staging site could
	 * not be asked; without that, what to do now that the way is in place.
	 *
	 * @param array      $items Checklist.
	 * @param string     $role  The confirmed role or ROLE_UNCONFIRMED.
	 * @param array|null $pair  Pair as summary() reports it.
	 * @return string
	 */
	public static function next_step( array $items, $role, $pair ) {
		foreach ( $items as $item ) {
			if ( false === $item['done'] ) {
				$steps = $item['steps'] . ( isset( $item['fallback'] ) ? ' ' . $item['fallback'] : '' );
				return AB_MCP_Guidance::compose( $item['finding'], $steps, AB_MCP_Guidance::settings_url( self::ANCHOR ) );
			}
		}
		foreach ( $items as $item ) {
			if ( isset( $item['check'] ) ) {
				return $item['check'];
			}
			// A staging site that could not be asked from here (a local or
			// private address) is not shown to answer: its finding says why.
			if ( 'staging_connected' === $item['id'] && true !== $item['done'] ) {
				return $item['finding'];
			}
		}
		$other = null !== $pair ? $pair['partner_url'] : '';
		return self::ROLE_STAGING === $role
			/* translators: %s: address of the production site. */
			? sprintf( __( 'The staging way is in place: this site is the protected staging site of %s. Make the changes here and show them with the preview password; carry them over to the production site one at a time once they are approved.', 'alphabridge-mcp' ), $other )
			/* translators: %s: address of the staging site. */
			: sprintf( __( 'The staging way is in place: the staging site %s answers and is paired with this site. Make the changes there and carry them over to this site one at a time once they are approved.', 'alphabridge-mcp' ), $other );
	}

	/**
	 * Whether the assistant of this connection is asked to offer the way to
	 * a staging site: the sentence in the server instructions and the
	 * prompt staging_workflow, under the same condition. wp_staging_status
	 * has to be in the registry the server answers with, and the account
	 * of the connection has to be allowed to call it, by the same check the
	 * call makes (AB_MCP_Security::permits(): its capability manage_options,
	 * its switch, write access and the token's access level), or the
	 * sentence would ask for a call that is refused. And the connection has
	 * to be able to change something: write access on, and at least one
	 * writing tool that tools/list shows this connection (its switch on,
	 * and allowed for the access level of the token,
	 * AB_MCP_Tool_Registry::scope_allows()). For a connection that only
	 * reads, the question would be about a change it cannot make.
	 *
	 * @param AB_MCP_Tool_Registry|null $registry The tools of the server.
	 * @return bool
	 */
	public static function offered( $registry ) {
		if ( ! $registry instanceof AB_MCP_Tool_Registry ) {
			return false;
		}
		$def = $registry->get( 'wp_staging_status' );
		if ( ! is_array( $def ) || true !== AB_MCP_Security::permits( 'wp_staging_status', $def ) ) {
			return false;
		}
		if ( ! AB_MCP_Site_Mode::is_full() ) {
			return false;
		}
		$scope = AB_MCP_Auth::current_scope();
		foreach ( $registry->all() as $name => $tool ) {
			if ( ! AB_MCP_Tool_Registry::is_read_only( $name, $tool )
				&& AB_MCP_Settings::is_tool_enabled( $name, $tool )
				&& AB_MCP_Tool_Registry::scope_allows( $scope, $name, $tool ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------- REST route */

	/**
	 * GET: the role and the pair.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 * @return WP_REST_Response
	 */
	public static function rest_read( $request ) {
		unset( $request );
		return self::respond( self::summary() );
	}

	/**
	 * An answer of the route, not to be stored on the way: WordPress sends
	 * its no-cache headers only for a user signed in before the route's
	 * own token check.
	 *
	 * @param array $data Answer.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST: the hub confirms the role and the pair. The body is JSON with
	 * partner_url, role, partner_role and source «hub»; every field is
	 * required. Counts towards the rate limit of the account like a tool
	 * call; the permission callback (AB_MCP_REST_Controller) has checked the
	 * token, manage_options and the access level.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_write( $request ) {
		$rate = AB_MCP_Security::check_rate( get_current_user_id() );
		if ( is_wp_error( $rate ) ) {
			return new WP_Error( $rate->get_error_code(), $rate->get_error_message(), array( 'status' => 429 ) );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_body_params();
		$body = is_array( $body ) ? $body : array();
		// Values as sent: validate() compares each strictly, so a value of
		// another type is refused there like a wrong one.
		$get = static function ( $key ) use ( $body ) {
			return isset( $body[ $key ] ) ? $body[ $key ] : '';
		};
		// Always as the hub: the settings page's own ways (taking a role
		// back, removing a pair) are not offered here, whatever the body
		// names as its source.
		$problems = self::validate( $get( 'role' ), $get( 'partner_url' ), $get( 'partner_role' ), self::SOURCE_HUB )['problems'];
		if ( self::SOURCE_HUB !== $get( 'source' ) ) {
			array_unshift( $problems, 'source' );
		}
		if ( array() !== $problems ) {
			return self::invalid( $problems );
		}
		$saved = self::save( $get( 'role' ), $get( 'partner_url' ), $get( 'partner_role' ), get_current_user_id(), self::SOURCE_HUB );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::respond( array_merge( $saved, self::summary() ) );
	}
}
