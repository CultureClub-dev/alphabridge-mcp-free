<?php
/**
 * The stand-in for downloads.wordpress.org and api.wordpress.org (Z8b
 * branch 1, point 3): what it answers from the test plugins and themes, that
 * WordPress takes its packages (unzip_file(), download_url(), plugins_api()
 * through hook()), and that it never reaches anything else. The test fetcher
 * of the partner sites (AB_MCP_Test_Sync_Fetcher) stays as it is.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Code;
use AB_MCP_Test_Wporg;
use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

final class WporgStandInTest extends TestCase {

	/** @var string[] Paths to remove. */
	private array $remove = array();

	protected function tearDown(): void {
		foreach ( array_reverse( $this->remove ) as $path ) {
			if ( is_dir( $path ) ) {
				foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
					$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
				}
				rmdir( $path );
			} elseif ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	private function temp(): string {
		$path           = get_temp_dir() . 'ab-it-wporg-' . bin2hex( random_bytes( 6 ) );
		$this->remove[] = $path;
		return $path;
	}

	public function testAVersionComesAsWordPressOrgPacksIt(): void {
		$wporg    = new AB_MCP_Test_Wporg();
		$response = $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-inaktiv.1.1.0.zip' );
		self::assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		self::assertSame( 'application/zip', wp_remote_retrieve_header( $response, 'content-type' ) );
		$zip = $this->temp() . '.zip';
		file_put_contents( $zip, wp_remote_retrieve_body( $response ) );
		$this->remove[] = $zip;
		$archive        = new ZipArchive();
		self::assertTrue( $archive->open( $zip ) );
		$names = array();
		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$names[] = $archive->getNameIndex( $i );
		}
		$archive->close();
		sort( $names );
		self::assertSame( array( 'ab-it-inaktiv/', 'ab-it-inaktiv/ab-it-inaktiv.php', 'ab-it-inaktiv/readme.txt' ), $names, 'One folder named after the slug.' );
		// WordPress unpacks it, and it is the version asked for, byte for byte.
		$dir = $this->temp();
		self::assertTrue( WP_Filesystem() );
		self::assertTrue( unzip_file( $zip, $dir ) );
		$source = AB_MCP_IT_Code::source( 'plugin', 'ab-it-inaktiv', '1.1.0' );
		foreach ( AB_MCP_IT_Code::files( $source ) as $file ) {
			self::assertFileEquals( $source . '/' . $file, $dir . '/ab-it-inaktiv/' . $file );
		}
		self::assertSame( '1.1.0', get_file_data( $dir . '/ab-it-inaktiv/ab-it-inaktiv.php', array( 'v' => 'Version' ) )['v'] );
	}

	public function testWithoutAVersionTheNewestAndAThemeAlike(): void {
		$wporg = new AB_MCP_Test_Wporg();
		self::assertSame( file_get_contents( AB_MCP_IT_Code::zip( 'plugin', 'ab-it-aktiv', 'php' ) ), wp_remote_retrieve_body( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-aktiv.zip' ) ), 'The newest of 1.0.0, 2.0.0, 2.0.1 and 2.0.2.' );
		self::assertSame( file_get_contents( AB_MCP_IT_Code::zip( 'theme', 'ab-it-kind', '1.1.0' ) ), wp_remote_retrieve_body( $wporg->get( 'https://downloads.wordpress.org/theme/ab-it-kind.1.1.0.zip' ) ) );
	}

	public function testTheInformationOfThePluginAndTheThemeApi(): void {
		$wporg = new AB_MCP_Test_Wporg();
		$info  = json_decode( wp_remote_retrieve_body( $wporg->get( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=ab-it-aktiv' ) ), true );
		self::assertSame( '2.0.2', $info['version'] );
		self::assertSame( 'https://downloads.wordpress.org/plugin/ab-it-aktiv.2.0.2.zip', $info['download_link'] );
		self::assertSame( array( '1.0.0', '2.0.0', '2.0.1', '2.0.2' ), array_keys( $info['versions'] ) );
		$theme = json_decode( wp_remote_retrieve_body( $wporg->get( 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&slug=ab-it-eltern' ) ), true );
		self::assertSame( '1.1.0', $theme['version'] );
		self::assertSame( 1, count( array_filter( AB_MCP_Test_Wporg::everything()['plugin']['ab-it-migration'], static fn( $f ) => '1.0.0' === $f ) ), 'The version number 1.0.0 two versions share goes to the first.' );
	}

	public function testUnknownOnItsHostsIs404AndElsewhereNothingIsAsked(): void {
		$wporg = new AB_MCP_Test_Wporg();
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-aktiv.9.9.9.zip' ) ) );
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/akismet.5.0.zip' ) ) );
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=akismet' ) ) );
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-einzeln.1.0.0.zip' ) ), 'A plugin of one file is no package of wordpress.org.' );
		foreach ( array( 'http://downloads.wordpress.org/plugin/ab-it-aktiv.1.0.0.zip', 'https://example.com/plugin/ab-it-aktiv.1.0.0.zip', 'https://downloads.wordpress.org:8443/plugin/ab-it-aktiv.1.0.0.zip', 'https://user@downloads.wordpress.org/plugin/ab-it-aktiv.1.0.0.zip', 'https://wordpress.org/plugins/' ) as $url ) {
			$answer = $wporg->get( $url );
			self::assertWPError( $answer, $url );
		}
		self::assertCount( 9, $wporg->calls );
	}

	private static function assertWPError( $answer, string $url ): void {
		self::assertInstanceOf( \WP_Error::class, $answer, $url );
		self::assertSame( 'ab_mcp_test_wporg_foreign', $answer->get_error_code() );
	}

	public function testAnOfferOfItsOwn(): void {
		$wporg = new AB_MCP_Test_Wporg( array( 'plugin' => array( 'ab-it-allein' => array( '1.1.0' => '1.1.0' ) ) ) );
		self::assertSame( 200, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.1.0.zip' ) ) );
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.0.0.zip' ) ) );
		self::assertSame( 404, wp_remote_retrieve_response_code( $wporg->get( 'https://downloads.wordpress.org/plugin/ab-it-aktiv.1.0.0.zip' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		new AB_MCP_Test_Wporg( array( 'plugin' => array( 'ab-it-allein' => array( '3.0.0' => '3.0.0' ) ) ) );
	}

	public function testWordPressItselfGetsItsPackagesThroughTheHook(): void {
		$wporg = ( new AB_MCP_Test_Wporg() )->hook();
		try {
			$file = download_url( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.1.0.zip' );
			self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );
			$this->remove[] = $file;
			self::assertFileEquals( AB_MCP_IT_Code::zip( 'plugin', 'ab-it-allein', '1.1.0' ), $file, 'Streamed to the file download_url() named.' );
			$info = plugins_api( 'plugin_information', array( 'slug' => 'ab-it-allein' ) );
			self::assertIsObject( $info, is_wp_error( $info ) ? $info->get_error_message() : '' );
			self::assertSame( '1.1.0', $info->version );
			self::assertSame( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.1.0.zip', $info->download_link );
			$other = wp_remote_get( 'https://example.com/' );
			self::assertWPErrorNotOurs( $other );
		} finally {
			$wporg->unhook();
		}
		self::assertSame( array( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.1.0.zip' ), array_slice( array_column( $wporg->calls, 'url' ), 0, 1 ) );
		$after = download_url( 'https://downloads.wordpress.org/plugin/ab-it-allein.1.1.0.zip' );
		self::assertInstanceOf( \WP_Error::class, $after, 'Unhooked: WordPress asks the network, which the test sites block.' );
	}

	private static function assertWPErrorNotOurs( $answer ): void {
		self::assertInstanceOf( \WP_Error::class, $answer, 'Another host passes the hook; the test site blocks it (WP_HTTP_BLOCK_EXTERNAL).' );
		self::assertNotSame( 'ab_mcp_test_wporg_foreign', $answer->get_error_code() );
	}
}
