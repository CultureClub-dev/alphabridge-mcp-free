<?php
/**
 * Clauses held by the integration suite, which needs the two sites running
 * (tests/integration/README.md). A mutation here reaches site B through its
 * `php -S`, which reads the changed file on the next request.
 *
 * The first entry proves the way, on a clause the smoke test asks over
 * HTTP: the plugin's connection token only authenticates when its hash is
 * the stored one (AB_MCP_Auth::verify_token()). The later heikel/ branches
 * of Z8 add their clauses here.
 *
 * @package AlphaBridge_MCP
 */

$integration = array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist' );

return array(
	array(
		'id'      => 'auth/token-hash',
		'clause'  => 'verify_token(): a token authenticates only when its hash is the stored one',
		'file'    => 'includes/class-auth.php',
		'search'  => "if ( hash_equals( (string) \$entry['hash'], \$presented_hash ) ) {",
		'replace' => "if ( false && hash_equals( (string) \$entry['hash'], \$presented_hash ) ) {",
		'run'     => $integration,
		'expect'  => '/testTwoSitesRunThePluginOnTheirOwnRowsAndAnswerOverHttp/',
	),
);
