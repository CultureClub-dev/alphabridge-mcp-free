<?php
/**
 * Policy enforcement: capability, Safe-Mode, rate limiting + shared guards.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Security
 */
class AB_MCP_Security {

	/**
	 * Decide whether the current request may run a tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return true|WP_Error
	 */
	public static function authorize( $name, array $def ) {
		$user_id = get_current_user_id();

		// 1. WordPress capability.
		if ( ! empty( $def['capability'] ) && ! current_user_can( $def['capability'] ) ) {
			return new WP_Error(
				'ab_mcp_forbidden',
				sprintf(
					/* translators: 1: tool, 2: capability */
					__( 'Your account lacks the capability "%2$s" required by "%1$s".', 'alphabridge-mcp' ),
					$name,
					$def['capability']
				)
			);
		}

		// 2. Global read-only mode: while it is on, only read tools may run.
		if ( AB_MCP_Settings::get( 'read_only', false ) && ! AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
			return new WP_Error(
				'ab_mcp_read_only',
				sprintf(
					/* translators: %s: tool */
					__( 'Read-only mode is active; the writing tool "%s" is blocked. Disable read-only mode under Settings → AlphaBridge MCP → Capabilities to allow writes.', 'alphabridge-mcp' ),
					$name
				)
			);
		}

		// 3. Tool must be enabled. Non-dangerous tools default on; dangerous
		// ("mighty") tools default off until the admin switches them on in the
		// AlphaBridge MCP settings. Disabled tools are also hidden from tools/list.
		if ( ! AB_MCP_Settings::is_tool_enabled( $name, $def ) ) {
			return new WP_Error(
				'ab_mcp_tool_disabled',
				sprintf(
					/* translators: %s: tool */
					__( 'The tool "%s" is disabled in the AlphaBridge MCP settings.', 'alphabridge-mcp' ),
					$name
				)
			);
		}

		// 4. Token scope. A scoped token narrows what it may do WITHIN what its
		// user already may do — it can only ever restrict, never grant. A "read"
		// token runs read tools only; a "content" token adds the editorial tools;
		// "full" (and any pre-4.1 token) is unrestricted. This is defence in depth
		// on top of the capability check above, useful for handing an AI client a
		// deliberately limited key.
		if ( ! AB_MCP_Tool_Registry::scope_allows( AB_MCP_Auth::current_scope(), $name, $def ) ) {
			return new WP_Error(
				'ab_mcp_scope',
				sprintf(
					/* translators: 1: tool, 2: scope */
					__( 'The token used is limited to the "%2$s" scope, which does not include the tool "%1$s". Use a wider-scoped token.', 'alphabridge-mcp' ),
					$name,
					AB_MCP_Auth::current_scope()
				)
			);
		}

		// 5. Rate limiting.
		$rl = self::check_rate_limit( $user_id, $def );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		return true;
	}

	/**
	 * Fixed-window per-minute rate limit (transient based).
	 * A soft throttle, not a hard security boundary.
	 *
	 * @param int   $user_id User id.
	 * @param array $def     Tool definition.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( $user_id, array $def ) {
		$per_min = (int) AB_MCP_Settings::get( 'rate_limit_per_min', 120 );
		if ( $per_min > 0 ) {
			$count = self::bump_counter( 'ab_mcp_rl_' . $user_id . '_' . gmdate( 'YmdHi' ), MINUTE_IN_SECONDS );
			if ( $count > $per_min ) {
				return new WP_Error(
					'ab_mcp_rate_limited',
					__( 'Rate limit reached (per minute). Please slow down.', 'alphabridge-mcp' )
				);
			}
		}

		return true;
	}

	/**
	 * Atomically increment a counter. Uses the object cache (atomic increment on
	 * Redis/Memcached) when a persistent backend is present, else falls back to a
	 * transient (best-effort under concurrency).
	 *
	 * @param string $key Counter key.
	 * @param int    $ttl Lifetime in seconds.
	 * @return int New value (post-increment).
	 */
	private static function bump_counter( $key, $ttl ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'ab_mcp', $ttl );
			$n = wp_cache_incr( $key, 1, 'ab_mcp' );
			if ( false !== $n ) {
				return (int) $n;
			}
		}
		$n = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, $ttl );
		return $n;
	}

	/**
	 * Validate tool arguments against the tool's inputSchema. All problems are
	 * collected and reported in ONE error together with the expected schema, so
	 * a client can fix its call in a single round-trip. Unknown fields are
	 * tolerated (ignored) so clients aren't broken by extras.
	 *
	 * @param array $def  Tool definition.
	 * @param array $args Arguments.
	 * @return true|WP_Error
	 */
	public static function validate_args( array $def, array $args ) {
		$schema   = isset( $def['inputSchema'] ) && is_array( $def['inputSchema'] ) ? $def['inputSchema'] : array();
		$props    = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$problems = array();

		foreach ( $required as $rk ) {
			if ( ! array_key_exists( $rk, $args ) || null === $args[ $rk ] || ( is_string( $args[ $rk ] ) && '' === $args[ $rk ] ) ) {
				/* translators: %s: argument name */
				$problems[] = sprintf( __( 'missing required "%s"', 'alphabridge-mcp' ), $rk );
			}
		}

		foreach ( $args as $key => $value ) {
			if ( ! isset( $props[ $key ]['type'] ) ) {
				continue; // Unknown or type-less property: tolerate.
			}
			switch ( $props[ $key ]['type'] ) {
				case 'string':
					if ( ! is_scalar( $value ) ) {
						/* translators: %s: argument name */
						$problems[] = sprintf( __( '"%s" must be a string', 'alphabridge-mcp' ), $key );
						break;
					}
					if ( is_string( $value ) && strlen( $value ) > 8 * 1024 * 1024 ) {
						/* translators: %s: argument name */
						$problems[] = sprintf( __( '"%s" exceeds the 8 MB size limit', 'alphabridge-mcp' ), $key );
					}
					break;
				case 'integer':
				case 'number':
					if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
						/* translators: %s: argument name */
						$problems[] = sprintf( __( '"%s" must be a number', 'alphabridge-mcp' ), $key );
					}
					break;
				case 'array':
				case 'object':
					if ( ! is_array( $value ) ) {
						/* translators: %s: argument name */
						$problems[] = sprintf( __( '"%s" must be an array/object', 'alphabridge-mcp' ), $key );
						break;
					}
					if ( count( $value ) > 2000 ) {
						/* translators: %s: argument name */
						$problems[] = sprintf( __( '"%s" has too many items (max 2000)', 'alphabridge-mcp' ), $key );
					}
					break;
			}
		}

		if ( empty( $problems ) ) {
			return true;
		}

		return new WP_Error(
			'ab_mcp_invalid_args',
			sprintf(
				/* translators: 1: problem list, 2: schema summary */
				__( 'Invalid arguments: %1$s. Expected schema: %2$s', 'alphabridge-mcp' ),
				implode( '; ', $problems ),
				self::schema_summary( $props, $required )
			)
		);
	}

	/**
	 * Compact human-readable schema line: name* (type), ... ("*" = required).
	 *
	 * @param array $props    Schema properties.
	 * @param array $required Required keys.
	 * @return string
	 */
	private static function schema_summary( $props, $required ) {
		if ( empty( $props ) || ! is_array( $props ) ) {
			return __( 'no arguments', 'alphabridge-mcp' );
		}
		$parts = array();
		foreach ( $props as $k => $p ) {
			$type    = isset( $p['type'] ) ? (string) $p['type'] : 'any';
			$parts[] = $k . ( in_array( $k, $required, true ) ? '*' : '' ) . ' (' . $type . ')';
		}
		return implode( ', ', $parts );
	}
}
