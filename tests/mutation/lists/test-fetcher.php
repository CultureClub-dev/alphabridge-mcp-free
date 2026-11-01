<?php
/**
 * The rule of Entscheid 34 (Z8): the product code takes no switch for the
 * test fetcher. The mutation builds exactly such a switch into
 * AB_MCP_Outbound::get(); ShippedCodeKnowsNoTestFetcherTest must see it.
 * (TestFetcherIsolationTest cannot: without the bootstrap the class does
 * not exist, so the switch stays closed at runtime — which is why the scan
 * of the shipped files exists.)
 *
 * @package AlphaBridge_MCP
 */

return array(
	array(
		'id'      => 'test-fetcher/switch-in-product-code',
		'clause'  => 'no shipped file reaches for the test fetcher',
		'file'    => 'includes/class-outbound.php',
		'search'  => "\tpublic static function get( \$url, array \$args, \$addresses = null ) {\n",
		'replace' => "\tpublic static function get( \$url, array \$args, \$addresses = null ) {\n\t\tif ( class_exists( 'AB_MCP_Test_Sync_Fetcher', false ) ) {\n\t\t\treturn AB_MCP_Test_Sync_Fetcher::for_sites()->get( \$url, \$args );\n\t\t}\n",
		'run'     => array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'ShippedCodeKnowsNoTestFetcherTest' ),
		'expect'  => '/testNoShippedFileNamesTheTestFetcherOrTheIntegrationSuite/',
	),
);
