<?php
/**
 * Where on disk the well-known relay looks for the other WordPress.
 *
 * A site relays the document of another site on its domain only when that
 * site is installed in the same web directory: <document root><path>/wp-load.php
 * exists and lies inside the document root. The document root is ABSPATH
 * (where this site's core lies) less the path of site_url(). That holds for
 * a site at the root, for the core in its own folder (home /, site_url /wp),
 * for a site in a folder (/blog) and for another site under that folder.
 *
 * Each case runs in a PHP process of its own (support/well-known-disk-probe.php)
 * with its own ABSPATH and site_url, nothing given in place of the document
 * root. The web directories are made under the temp directory and removed
 * again (support/well-known-docroot.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/well-known-docroot.php';

final class WellKnownDocumentRootTest extends TestCase {

	private const H = 'https://example.test';

	/** @var string[] Directories to remove after the test. */
	private array $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			ab_test_wk_remove( $dir );
		}
		$this->dirs = array();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string[],5:?string}>
	 *         ABSPATH under the web directory, home, site_url, request path, sites on disk, issuer relayed (null: refused)
	 */
	public static function layouts(): array {
		$as = '/.well-known/oauth-authorization-server';
		return array(
			'site at the root'                             => array( '', self::H, self::H, $as . '/staging/123', array( '/staging/123' ), self::H . '/staging/123' ),
			'core in /wp, home at the root'                => array( '/wp', self::H, self::H . '/wp', $as . '/staging/123', array( '/staging/123', '/wp' ), self::H . '/staging/123' ),
			'core in /wp, no WordPress under the path'     => array( '/wp', self::H, self::H . '/wp', $as . '/staging/9', array( '/staging/123', '/wp' ), null ),
			'main site in /blog, the other at the root'    => array( '/blog', self::H . '/blog', self::H . '/blog', '/blog' . $as . '/staging/123', array( '/staging/123', '/blog' ), self::H . '/staging/123' ),
			'the other site under the home of the main'    => array( '/blog', self::H . '/blog', self::H . '/blog', '/blog' . $as . '/blog/staging', array( '/blog/staging' ), self::H . '/blog/staging' ),
			'home in /blog, core in /blog/wp'              => array( '/blog/wp', self::H . '/blog', self::H . '/blog/wp', '/blog' . $as . '/staging/123', array( '/staging/123', '/blog/wp' ), self::H . '/staging/123' ),
			'ABSPATH does not end in the path of site_url' => array( '/elsewhere', self::H, self::H . '/wp', $as . '/staging/123', array( '/staging/123', '/elsewhere' ), null ),
			'a directory without wp-load.php'              => array( '', self::H, self::H, $as . '/staging', array( '/staging/123' ), null ),
		);
	}

	/**
	 * @param string[] $sites
	 */
	#[DataProvider( 'layouts' )]
	public function testTheOtherSiteIsLookedForUnderTheDocumentRoot( string $core, string $home, string $site, string $path, array $sites, ?string $issuer ): void {
		$root = $this->dir( $sites );

		$result = $this->probe( $root . $core, $home, $site, $path );

		self::assertSame( null === $issuer ? 'refused' : 'document', $result['kind'] );
		self::assertSame( $issuer, $result['issuer'] );
		self::assertSame( null === $issuer ? 0 : 1, $result['fetches'], 'No WordPress there: not even a fetch.' );
	}

	public function testASymlinkOutOfTheDocumentRootIsNotRelayed(): void {
		$root    = $this->dir( array() );
		$outside = $this->dir( array( '/site' ) );
		symlink( $outside . '/site', $root . '/elsewhere' );

		$result = $this->probe( $root, self::H, self::H, '/.well-known/oauth-authorization-server/elsewhere' );

		self::assertSame( array( 'refused', 0 ), array( $result['kind'], $result['fetches'] ) );
	}

	public function testASymlinkInsideTheDocumentRootIsRelayed(): void {
		$root = $this->dir( array( '/staging/123' ) );
		symlink( $root . '/staging/123', $root . '/alias' );

		$result = $this->probe( $root, self::H, self::H, '/.well-known/oauth-authorization-server/alias' );

		self::assertSame( array( 'document', self::H . '/alias', 1 ), array( $result['kind'], $result['issuer'], $result['fetches'] ) );
	}

	public function testAPathOutOfTheDocumentRootIsNeverLookedFor(): void {
		$root    = $this->dir( array() );
		$sibling = $this->dir( array( '/x' ) );
		$name    = basename( $sibling );

		foreach ( array( '/../' . $name . '/x', '/%2e%2e/' . $name . '/x', '/..%2f' . $name . '/x' ) as $path ) {
			$result = $this->probe( $root, self::H, self::H, '/.well-known/oauth-authorization-server' . $path );
			self::assertSame( array( 'refused', 0 ), array( $result['kind'], $result['fetches'] ), $path );
		}
	}

	/**
	 * open_basedir hides the document root, or not: no relay and no warning
	 * in the first case, the relay in the second.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function openBasedir(): array {
		return array(
			'document root outside open_basedir' => array( false ),
			'document root inside open_basedir'  => array( true ),
		);
	}

	#[DataProvider( 'openBasedir' )]
	public function testOpenBasedirHidingTheSiteMeansNoRelayAndNoWarning( bool $visible ): void {
		$root    = $this->dir( array( '/staging/123' ) );
		$allowed = dirname( __DIR__ ) . ( $visible ? PATH_SEPARATOR . realpath( $root ) : '' );

		$result = $this->probe( $root, self::H, self::H, '/.well-known/oauth-authorization-server/staging/123', $allowed );

		self::assertSame( $visible ? 'document' : 'refused', $result['kind'] );
		self::assertSame( $visible ? 1 : 0, $result['fetches'] );
	}

	/**
	 * A web directory with a WordPress (its wp-load.php) under each of $sites.
	 *
	 * @param string[] $sites
	 */
	private function dir( array $sites ): string {
		$dir          = ab_test_wk_dir( $sites );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * One request in a process of its own with this ABSPATH, home and
	 * site_url; nothing may come on stderr.
	 *
	 * @return array{kind:string,issuer:?string,fetches:int}
	 */
	private function probe( string $abspath, string $home, string $site, string $path, string $open_basedir = '' ): array {
		if ( ! is_dir( $abspath ) ) {
			mkdir( $abspath, 0700, true );
		}
		$command = array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1' );
		if ( '' !== $open_basedir ) {
			array_push( $command, '-d', 'open_basedir=' . $open_basedir );
		}
		$command[] = __DIR__ . '/support/well-known-disk-probe.php';
		$command[] = (string) json_encode(
			array(
				'abspath' => $abspath,
				'home'    => $home,
				'site'    => $site,
				'path'    => $path,
			)
		);
		$pipes = array();
		$proc  = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out   = (string) stream_get_contents( $pipes[1] );
		$err   = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );

		self::assertSame( '', $err, 'No warning: it would end up in front of the JSON.' );
		$result = json_decode( $out, true );
		self::assertIsArray( $result, $out );
		return $result;
	}
}
