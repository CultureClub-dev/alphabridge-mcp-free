<?php
/**
 * Technical confirmation for tools whose effect cannot be undone (9a).
 *
 * A tool that declares `confirm` in its definition never runs on the first
 * call. The first call answers with a preview of what would happen and a
 * `confirm_token`; only a second call with that token runs the tool. The
 * token is an HMAC over the tool name, the arguments, the state the preview
 * was made from, the user and the scope of the connection, with a short
 * expiry. It is stateless: nothing is stored, and any change in between
 * (other arguments, another plugin version, a plugin already deleted) makes
 * the token fail and asks for a fresh preview.
 *
 * What it protects against: a call the AI did not mean, or one it made up,
 * running at once. What it does not do: bring anything back, or replace a
 * person's approval. The answer says so.
 *
 * Definition keys (all optional except `state`):
 *  - state   callable( array $args ): string|WP_Error  Fingerprint of what the
 *            tool would change. WP_Error stops the call before any preview.
 *  - preview callable( array $args ): array|WP_Error    What would happen, in
 *            words and figures, shown with the token.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and checks confirmation tokens; gates tools that declare `confirm`.
 */
final class AB_MCP_Confirm_Token {

	/** Argument that carries the token. */
	const PARAM = 'confirm_token';

	/** Seconds a token stays valid. Long enough to read a preview, short enough not to linger. */
	const TTL = 600;

	/** Version prefix inside the MAC, so a later format cannot be confused with this one. */
	const VERSION = 'v1';

	/**
	 * Whether a tool definition asks for confirmation.
	 *
	 * @param array $def Tool definition.
	 * @return bool
	 */
	public static function required( array $def ) {
		// Any non-empty `confirm` counts, a broken one included: gate() then
		// refuses instead of letting the tool run without confirmation.
		return ! empty( $def['confirm'] );
	}

	/**
	 * The schema property every confirming tool gets.
	 *
	 * @return array
	 */
	public static function schema_property() {
		return array(
			'type'        => 'string',
			'description' => __( 'Confirmation from the preview. Leave it out on the first call: the tool then changes nothing and answers with a preview and a confirm_token. Call again with the same arguments and that token to run it. The token is valid for 10 minutes and only while nothing has changed.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * The sentence added to the description of every confirming tool.
	 *
	 * @return string
	 */
	public static function description_note() {
		return __( 'Two steps: the first call changes nothing and returns a preview with a confirm_token; the second call with the same arguments and that token runs it. This guards against a call nobody meant; it does not make the change reversible.', 'alphabridge-mcp' );
	}

	/**
	 * Gate a call. Returns true when the tool may run (valid token), an array
	 * when the call is answered with a preview instead, or a WP_Error.
	 *
	 * @param string   $tool Tool name.
	 * @param array    $def  Tool definition.
	 * @param array    $args Arguments as sent.
	 * @param int|null $now  Current time (tests).
	 * @return true|array|WP_Error
	 */
	public static function gate( $tool, array $def, array $args, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! is_array( $def['confirm'] ) || ! isset( $def['confirm']['state'] ) || ! is_callable( $def['confirm']['state'] ) ) {
			return new WP_Error( 'ab_mcp_confirm_broken', __( 'Nothing ran: this tool asks for confirmation but registered no way to check it. The plugin that registered it is at fault; tell the site administrator.', 'alphabridge-mcp' ) );
		}
		if ( null === self::canonical( self::without_token( $args ) ) ) {
			return new WP_Error( 'ab_mcp_confirm_args', __( 'Nothing ran: the arguments hold a value that cannot be written as JSON (a number out of range, for example), so no confirmation can be bound to them. Leave out that value and call again.', 'alphabridge-mcp' ) );
		}
		$state = call_user_func( $def['confirm']['state'], self::without_token( $args ) );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$state = (string) $state;

		if ( isset( $args[ self::PARAM ] ) && '' !== (string) $args[ self::PARAM ] ) {
			return self::check( $tool, $args, $state, (string) $args[ self::PARAM ], $now );
		}

		$preview = null;
		if ( isset( $def['confirm']['preview'] ) && is_callable( $def['confirm']['preview'] ) ) {
			$preview = call_user_func( $def['confirm']['preview'], self::without_token( $args ) );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
		}

		return array(
			'confirm_required' => true,
			'done'             => false,
			'preview'          => $preview,
			self::PARAM        => self::issue( $tool, $args, $state, $now ),
			'expires_in'       => self::TTL,
			'next'             => __( 'Nothing has changed yet. Show this preview to the person; if they want it, call the same tool again with the same arguments and confirm_token. The token expires after 10 minutes or as soon as something it covers changes.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * Issue a token for these arguments and this state.
	 *
	 * @param string $tool  Tool name.
	 * @param array  $args  Arguments (a token in them is ignored).
	 * @param string $state State fingerprint.
	 * @param int    $now   Current time.
	 * @return string
	 */
	public static function issue( $tool, array $args, $state, $now ) {
		$expires = (int) $now + self::TTL;
		$mac     = self::mac( $tool, $args, $state, $expires );
		// gate() refuses arguments without a canonical form before issuing;
		// called directly with such arguments, no token is the answer.
		return null === $mac ? '' : $expires . '.' . $mac;
	}

	/**
	 * Check a token. True, or a WP_Error that names the way on.
	 *
	 * @param string $tool  Tool name.
	 * @param array  $args  Arguments (the token in them is ignored for the MAC).
	 * @param string $state State fingerprint now.
	 * @param string $token Presented token.
	 * @param int    $now   Current time.
	 * @return true|WP_Error
	 */
	public static function check( $tool, array $args, $state, $token, $now ) {
		if ( ! preg_match( '/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $m ) ) {
			return new WP_Error( 'ab_mcp_confirm_invalid', __( 'Nothing was changed: the confirm_token is not one this site issued. Leave out confirm_token in the next call for a fresh preview, show it to the person, then confirm with the new token.', 'alphabridge-mcp' ) );
		}
		$expires = (int) $m[1];
		if ( $expires < (int) $now || $expires > (int) $now + self::TTL ) {
			return new WP_Error( 'ab_mcp_confirm_expired', __( 'Nothing was changed: the confirm_token has expired (it is valid for 10 minutes). Leave out confirm_token in the next call for a fresh preview, show it to the person, then confirm with the new token.', 'alphabridge-mcp' ) );
		}
		$mac = self::mac( $tool, $args, $state, $expires );
		if ( null === $mac || ! hash_equals( $mac, $m[2] ) ) {
			return new WP_Error( 'ab_mcp_confirm_mismatch', __( 'Nothing was changed: the confirm_token does not match this call. The arguments differ from the preview, it was issued to another connection, or what it covers has changed since. Leave out confirm_token in the next call for a fresh preview, show it to the person, then confirm with the new token.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * Arguments without the token, as the tool receives them.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function without_token( array $args ) {
		unset( $args[ self::PARAM ] );
		return $args;
	}

	/**
	 * The MAC over tool, arguments, state, user, scope and expiry.
	 *
	 * @param string $tool    Tool name.
	 * @param array  $args    Arguments.
	 * @param string $state   State fingerprint.
	 * @param int    $expires Expiry timestamp.
	 * @return string|null 64 hex characters; null when the arguments have no canonical form.
	 */
	private static function mac( $tool, array $args, $state, $expires ) {
		$canonical = self::canonical( self::without_token( $args ) );
		if ( null === $canonical ) {
			return null;
		}
		$payload = implode(
			"\n",
			array(
				self::VERSION,
				(string) $tool,
				(string) get_current_user_id(),
				class_exists( 'AB_MCP_Auth' ) ? AB_MCP_Auth::current_scope() : 'full',
				(string) (int) $expires,
				hash( 'sha256', $canonical ),
				hash( 'sha256', (string) $state ),
			)
		);
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|ab_mcp_confirm' );
	}

	/**
	 * Arguments in a stable form: keys of objects sorted at every level, so
	 * the same arguments in another order give the same token. Lists keep
	 * their order (it can matter).
	 *
	 * A value JSON cannot hold (INF, NAN, invalid UTF-8 in a key) gives
	 * null, never an empty string: two different argument sets must never
	 * share one form.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function canonical( $value ) {
		$json = wp_json_encode( self::sorted( $value ) );
		return is_string( $json ) && '' !== $json ? $json : null;
	}

	/**
	 * Recursively sort associative arrays by key.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::sorted( $v );
		}
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
