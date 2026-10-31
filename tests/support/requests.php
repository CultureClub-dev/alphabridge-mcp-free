<?php
/*
 * Fixed copies for the test suite only: the classes of Requests 2.0.17, the
 * HTTP library WordPress bundles and follows redirects with, that
 * AB_MCP_Outbound::get() uses to carry cookies over the redirects it
 * follows itself, as WordPress's transport carries them (OutboundBindingTest).
 * Taken from wp-includes/Requests/src of WordPress 7.0.2 (the local core copy
 * ~/Entwicklung/wordpress-core-7.0.2 on 31.10.2026), each file unchanged
 * under tests/support/requests/. Requests is free software under the ISC
 * license (wp-includes/Requests/LICENSE); copyright its contributors.
 */

foreach ( array(
	'Exception.php',
	'Exception/InvalidArgument.php',
	'Utility/InputValidator.php',
	'Utility/CaseInsensitiveDictionary.php',
	'Utility/FilteredIterator.php',
	'Response/Headers.php',
	'Port.php',
	'Ipv6.php',
	'Iri.php',
	'Cookie.php',
	'Cookie/Jar.php',
) as $ab_requests_file ) {
	require_once __DIR__ . '/requests/' . $ab_requests_file;
}
unset( $ab_requests_file );
