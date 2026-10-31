<?php
/*
 * Fixed excerpt for the test suite only: WP_Http::normalize_cookies() of
 * wp-includes/class-wp-http.php, WordPress 7.0.2, taken from the local core
 * copy ~/Entwicklung/wordpress-core-7.0.2 on 31.10.2026, the method unchanged
 * below this note. AB_MCP_Outbound::get() fills the cookie jar of a request
 * with it, as WP_Http::request() does from the 'cookies' argument
 * (OutboundBindingTest).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors.
 */

if ( class_exists( 'WP_Http', false ) ) {
	return;
}

class WP_Http {


	/**
	 * Normalizes cookies for using in Requests.
	 *
	 * @since 4.6.0
	 *
	 * @param array $cookies Array of cookies to send with the request.
	 * @return WpOrg\Requests\Cookie\Jar Cookie holder object.
	 */
	public static function normalize_cookies( $cookies ) {
		$cookie_jar = new WpOrg\Requests\Cookie\Jar();

		foreach ( $cookies as $name => $value ) {
			if ( $value instanceof WP_Http_Cookie ) {
				$attributes                 = array_filter(
					$value->get_attributes(),
					static function ( $attr ) {
						return null !== $attr;
					}
				);
				$cookie_jar[ $value->name ] = new WpOrg\Requests\Cookie( (string) $value->name, $value->value, $attributes, array( 'host-only' => $value->host_only ) );
			} elseif ( is_scalar( $value ) ) {
				$cookie_jar[ $name ] = new WpOrg\Requests\Cookie( (string) $name, (string) $value );
			}
		}

		return $cookie_jar;
	}
}
