<?php
/**
 * The list of test plugins and themes (tests/integration/fixtures/code/
 * katalog.php, Z8b branch 1, point 2) against its folders, and the
 * properties the cases of plan section 9 rely on, read from the files
 * without loading them. The sites themselves are CodeFixturesTest
 * (integration).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_IT_Code;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/integration/support/class-it-code.php';

final class CodeKatalogTest extends TestCase {

	private static function header( string $file, string $field ): ?string {
		return preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi', (string) file_get_contents( $file ), $m ) ? $m[1] : null;
	}

	public function testEveryVersionHasItsFolderAndEveryFolderIsListed(): void {
		$katalog = AB_MCP_IT_Code::katalog();
		foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $list => $type ) {
			$listed = array();
			foreach ( $katalog[ $list ] as $slug => $unit ) {
				self::assertStringStartsWith( AB_MCP_IT_Code::PREFIX, $slug );
				foreach ( array_keys( $unit['fassungen'] ) as $fassung ) {
					self::assertDirectoryExists( AB_MCP_IT_Code::source( $type, $slug, (string) $fassung ) );
					$listed[] = $slug . '/' . $fassung;
				}
			}
			$found = array();
			foreach ( (array) glob( AB_MCP_IT_Code::fixtures() . '/' . $list . '/*/*', GLOB_ONLYDIR ) as $dir ) {
				$found[] = basename( dirname( (string) $dir ) ) . '/' . basename( (string) $dir );
			}
			sort( $listed );
			sort( $found );
			self::assertSame( $listed, $found, "Every folder of $list is a version of the list, and the other way round." );
		}
		foreach ( $katalog['mu-plugins'] as $file ) {
			self::assertFileExists( AB_MCP_IT_Code::fixtures() . '/mu-plugins/' . $file );
		}
		self::assertArrayHasKey( $katalog['theme'], $katalog['themes'] );
	}

	public function testEveryPluginVersionHasItsMainFilesWithAHeaderAndTheActiveOnesAreMainFiles(): void {
		foreach ( AB_MCP_IT_Code::katalog()['plugins'] as $slug => $unit ) {
			foreach ( $unit['start']['aktiv'] as $main ) {
				self::assertContains( $main, $unit['main'], "$slug: an active file is a main file." );
			}
			self::assertArrayHasKey( $unit['start']['fassung'], $unit['fassungen'] );
			foreach ( array_keys( $unit['fassungen'] ) as $fassung ) {
				$source = AB_MCP_IT_Code::source( 'plugin', $slug, (string) $fassung );
				foreach ( $unit['main'] as $main ) {
					$file = $source . '/' . basename( $main );
					self::assertFileExists( $file );
					self::assertNotNull( self::header( $file, 'Plugin Name' ), "$file has a plugin header." );
					self::assertNotNull( self::header( $file, 'Version' ) );
					self::assertStringContainsString( "\$GLOBALS['ab_it_fassung']", (string) file_get_contents( $file ), "$file sets the mark of its version (F39)." );
				}
				if ( ! empty( $unit['single'] ) ) {
					self::assertSame( array( $slug . '.php' ), AB_MCP_IT_Code::files( $source ), 'A plugin of one file is that file alone.' );
					self::assertSame( array( $slug . '.php' ), $unit['main'] );
				} else {
					foreach ( $unit['main'] as $main ) {
						self::assertSame( $slug, dirname( $main ), 'A folder plugin has its main files in its folder.' );
					}
				}
			}
		}
	}

	public function testThemesHaveTheirHeadersAndTheChildrenTheirParents(): void {
		foreach ( AB_MCP_IT_Code::katalog()['themes'] as $slug => $unit ) {
			foreach ( array_keys( $unit['fassungen'] ) as $fassung ) {
				$style = AB_MCP_IT_Code::source( 'theme', $slug, (string) $fassung ) . '/style.css';
				self::assertNotNull( self::header( $style, 'Theme Name' ) );
				self::assertSame( (string) $fassung, self::header( $style, 'Version' ) );
			}
		}
		self::assertSame( 'ab-it-eltern', self::header( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.0.0' ) . '/style.css', 'Template' ) );
		self::assertSame( 'ab-it-anderes-eltern', self::header( AB_MCP_IT_Code::source( 'theme', 'ab-it-anderes-kind', '1.0.0' ) . '/style.css', 'Template' ) );
		self::assertNotSame(
			file_get_contents( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.0.0' ) . '/functions.php' ),
			file_get_contents( AB_MCP_IT_Code::source( 'theme', 'ab-it-kind', '1.1.0' ) . '/functions.php' ),
			'F9: the new child has another functions.php.'
		);
	}

	public function testThePropertiesTheCasesRelyOn(): void {
		$src = static fn( string $slug, string $fassung ): string => AB_MCP_IT_Code::source( 'plugin', $slug, $fassung );
		// F39: same size and version, other bytes.
		$alt = $src( 'ab-it-zeitstempel', 'alt' ) . '/ab-it-zeitstempel.php';
		$neu = $src( 'ab-it-zeitstempel', 'neu' ) . '/ab-it-zeitstempel.php';
		self::assertSame( filesize( $alt ), filesize( $neu ) );
		self::assertNotSame( hash_file( 'sha256', $alt ), hash_file( 'sha256', $neu ) );
		self::assertSame( self::header( $alt, 'Version' ), self::header( $neu, 'Version' ) );
		// Migration without a new version number; F34.
		self::assertSame( '1.0.0', self::header( $src( 'ab-it-migration', '1.0.0' ) . '/ab-it-migration.php', 'Version' ) );
		self::assertSame( '1.0.0', self::header( $src( 'ab-it-migration', '1.0.0-migration' ) . '/ab-it-migration.php', 'Version' ) );
		self::assertSame( '2.0.0', self::header( $src( 'ab-it-migration', '2.0.0-bruch' ) . '/ab-it-migration.php', 'Version' ) );
		// F20: Requires PHP too high.
		self::assertSame( '99.0', self::header( $src( 'ab-it-aktiv', 'php' ) . '/ab-it-aktiv.php', 'Requires PHP' ) );
		// F8: Requires Plugins.
		self::assertSame( 'ab-it-basis', self::header( $src( 'ab-it-braucht', '1.0.0' ) . '/ab-it-braucht.php', 'Requires Plugins' ) );
		// F47: the data file only in the first version.
		self::assertFileExists( $src( 'ab-it-daten', '1.0.0' ) . '/data/export.csv' );
		self::assertFileDoesNotExist( $src( 'ab-it-daten', '1.1.0' ) . '/data/export.csv' );
		// F18: uninstall.php drops the table, the deactivation writes the option.
		self::assertStringContainsString( 'DROP TABLE IF EXISTS', (string) file_get_contents( $src( 'ab-it-routinen', '1.0.0' ) . '/uninstall.php' ) );
		self::assertStringContainsString( "update_option( 'ab_it_routinen_deaktiviert'", (string) file_get_contents( $src( 'ab-it-routinen', '1.0.0' ) . '/ab-it-routinen.php' ) );
		// F48: the caller is inactive at the start.
		self::assertSame( array(), AB_MCP_IT_Code::unit( 'plugin', 'ab-it-aufrufer' )['start']['aktiv'] );
	}

	public function testNoFixtureIsALinkAndEveryPhpFileParses(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( AB_MCP_IT_Code::fixtures(), \RecursiveDirectoryIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		$php = array();
		foreach ( $it as $file ) {
			self::assertFalse( is_link( $file->getPathname() ), $file->getPathname() . ' is a link.' );
			if ( 'php' === $file->getExtension() ) {
				$php[] = $file->getPathname();
			}
		}
		self::assertGreaterThan( 40, count( $php ) );
		foreach ( $php as $file ) {
			exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
			self::assertSame( 0, $code, implode( "\n", $out ) );
		}
	}

	public function testAUnitOutsideThePrefixIsRefused(): void {
		$this->expectException( \InvalidArgumentException::class );
		AB_MCP_IT_Code::source( 'plugin', 'hello', '1.0.0' );
	}
}
