<?php
/**
 * WordPress' application passwords as far as the guard in front of them asks:
 * the class exists (WordPress 5.6 and later) and
 * wp_is_application_passwords_available() answers what the test put in
 * $GLOBALS['ab_test_way']['app_pw_available']. Required by AnswerWayTest only
 * after it has read the refusal for a WordPress without them.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Application_Passwords', false ) ) {
	/** Present, as from WordPress 5.6 on; the guard asks for nothing more. */
	class WP_Application_Passwords {}
}

if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
	function wp_is_application_passwords_available() {
		return (bool) ( $GLOBALS['ab_test_way']['app_pw_available'] ?? true );
	}
}
