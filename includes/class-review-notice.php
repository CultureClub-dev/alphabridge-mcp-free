<?php
/**
 * The one-time request for a review on WordPress.org.
 *
 * It appears on the plugin's own settings page only, and only once a site has
 * really used the plugin: at least MIN_DAYS after the first counted tool call
 * and after at least MIN_CALLS successful calls. «Don't ask again» is final and
 * survives updates, because the state lives in its own option, not among the
 * settings that an update rewrites.
 *
 * The counter stops once MIN_CALLS successful calls are stored, so counting is
 * a small, bounded cost rather than a write per call, and once dismissed the
 * counter is not written at all. The dismissal lives in an option of its own
 * that the counter never writes: a counting request that is still in flight
 * while the admin clicks «don't ask again» cannot write a stale «not
 * dismissed» over it.
 * Nothing is sent anywhere: the notice is a link to the review form on
 * WordPress.org and a link that records the dismissal on this site. It asks
 * everyone the same way — there is no «are you happy?» fork in front of it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Review_Notice
 */
class AB_MCP_Review_Notice {

	const OPTION           = 'ab_mcp_review';
	const OPTION_DISMISSED = 'ab_mcp_review_dismissed';
	const MIN_DAYS   = 14;
	const MIN_CALLS  = 50;
	const REVIEW_URL = 'https://wordpress.org/support/plugin/alphabridge-mcp/reviews/#new-post';

	/**
	 * The stored state with every field present, whatever is in the options.
	 *
	 * The counter and the dismissal are read from two options on purpose; see
	 * the file comment.
	 *
	 * @return array{first_call:int,calls:int,dismissed:bool}
	 */
	public static function state() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'first_call' => isset( $raw['first_call'] ) ? (int) $raw['first_call'] : 0,
			'calls'      => isset( $raw['calls'] ) ? (int) $raw['calls'] : 0,
			'dismissed'  => (bool) get_option( self::OPTION_DISMISSED, false ),
		);
	}

	/**
	 * Whether the notice is due: a pure rule over the state and the clock.
	 *
	 * @param array $state As returned by state().
	 * @param int   $now   Unix timestamp.
	 * @return bool
	 */
	public static function is_due( array $state, $now ) {
		if ( ! empty( $state['dismissed'] ) ) {
			return false;
		}
		$first = isset( $state['first_call'] ) ? (int) $state['first_call'] : 0;
		if ( $first <= 0 ) {
			// No first call yet: the clock has not started, whatever «now» is.
			return false;
		}
		if ( (int) $now - $first < self::MIN_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		$calls = isset( $state['calls'] ) ? (int) $state['calls'] : 0;
		return $calls >= self::MIN_CALLS;
	}

	/**
	 * Count one successful tool call.
	 *
	 * Cheap by design: after MIN_CALLS, and once dismissed, nothing is written.
	 * Only the counter option is ever written here, never the dismissal.
	 *
	 * @param int|null $now Unix timestamp; null means the current time.
	 */
	public static function count_call( $now = null ) {
		$state = self::state();
		if ( $state['dismissed'] || $state['calls'] >= self::MIN_CALLS ) {
			return;
		}
		$now = null === $now ? time() : (int) $now;
		update_option(
			self::OPTION,
			array(
				'first_call' => $state['first_call'] > 0 ? $state['first_call'] : $now,
				'calls'      => $state['calls'] + 1,
			)
		);
	}

	/**
	 * Record «don't ask again». Final: it survives updates, the question never
	 * comes back, and no counting request can overwrite it.
	 */
	public static function dismiss() {
		update_option( self::OPTION_DISMISSED, 1 );
	}

	/**
	 * The notice for the settings page, or '' while it is not due.
	 *
	 * @param int|null $now Unix timestamp; null means the current time.
	 * @return string HTML built from escaped parts.
	 */
	public static function render( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::is_due( self::state(), $now ) ) {
			return '';
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=ab_mcp_review_dismiss' ), 'ab_mcp_review_dismiss' );
		return '<div class="notice notice-info"><p>'
			. esc_html__( 'This site has been working with AlphaBridge MCP for a while now. A review on WordPress.org — whatever your verdict — helps other people decide whether it fits them.', 'alphabridge-mcp' )
			. '</p><p><a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Write a review', 'alphabridge-mcp' ) . '</a> '
			. '<a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Don’t ask again', 'alphabridge-mcp' ) . '</a></p></div>';
	}
}
