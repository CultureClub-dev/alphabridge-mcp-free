<?php
/**
 * The guards of the places of the promises of Z8b branch 2 (plan 3.1, Z1,
 * Z2, Z35, Z38): no way past the one place in the shipped code.
 *
 * - Z1, Z2: the hash of a unit comes only from AB_MCP_Sync_File_Manifest::
 *   unit(): no other shipped file writes the text of a manifest (text(),
 *   the format ab-files/1) or hashes a unit, and the reading of the
 *   environment puts into the line of a unit only the hash unit() gave;
 * - Z35: the checksums of the core only through AB_MCP_Sync_Core_Compare::
 *   checksums() (no other file asks api.wordpress.org for them, none calls
 *   get_core_checksums());
 * - Z38: the bases of a pair are written only in AB_MCP_Sync_Pairs (no other
 *   shipped file writes the table of the pairs), and rebase() is the one way
 *   that takes a part.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class SyncManifestGuardTest extends TestCase {

	/** @return array<string,string> Shipped PHP file (relative) => its code without comments. */
	private static function shipped(): array {
		$root = dirname( __DIR__ );
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$all  = iterator_to_array( $it );
		$all[] = new \SplFileInfo( $root . '/alphabridge-mcp.php' );
		$all[] = new \SplFileInfo( $root . '/uninstall.php' );
		foreach ( $all as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( (string) $file ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$code .= is_array( $token ) ? $token[1] : $token;
			}
			$out[ substr( (string) $file, strlen( $root ) + 1 ) ] = $code;
		}
		self::assertGreaterThan( 100, count( $out ) );
		return $out;
	}

	/** @return string[] The shipped files whose code holds a text. */
	private static function holding( string $text ): array {
		$found = array();
		foreach ( self::shipped() as $file => $code ) {
			if ( false !== stripos( $code, $text ) ) {
				$found[] = $file;
			}
		}
		sort( $found );
		return $found;
	}

	public function testOnlyTheManifestWritesTheTextOfAManifestAndHashesAUnit(): void {
		self::assertSame( array( 'includes/sync/class-sync-file-manifest.php' ), self::holding( 'ab-files/1' ), 'The format named in one file only.' );
		self::assertSame( array(), self::holding( 'AB_MCP_Sync_File_Manifest::text(' ), 'No other file makes the text of a manifest.' );
		self::assertSame( array( 'includes/sync/class-sync-env.php' ), self::holding( 'AB_MCP_Sync_File_Manifest::unit(' ), 'The units read in the reading of the environment only.' );
		$env = self::shipped()['includes/sync/class-sync-env.php'];
		self::assertSame( 1, substr_count( $env, "'hash'     => \$read['hash']," ), 'The line of a unit takes the hash unit() gave.' );
		self::assertSame( 0, preg_match( "/'hash'\\s*=>\\s*+(?!\\\$read\\['hash'\\]|null|'hash\\?')/", $env ), 'No other hash of a unit in the reading.' );
		self::assertSame( 0, preg_match( '/hash_init|hash_file\\(\\s*\'sha256\'\\s*,\\s*\\$(?!path\\b)/', $env ), 'No hash of the files of a unit beside unit().' );
	}

	public function testOnlyTheComparisonOfTheCoreAsksForItsChecksums(): void {
		self::assertSame( array(), self::holding( 'get_core_checksums(' ), 'Never through the core (http again).' );
		self::assertSame( array( 'includes/sync/class-sync-core-compare.php' ), self::holding( 'core/checksums' ) );
		self::assertSame( array( 'includes/sync/class-sync-env.php' ), self::holding( 'AB_MCP_Sync_Core_Compare::check(' ) );
	}

	public function testOnlyThePairsWriteTheTableOfThePairsAndOnlyRebaseTakesAPart(): void {
		$writers = array();
		foreach ( self::shipped() as $file => $code ) {
			if ( preg_match( "/(UPDATE|INSERT\\s+INTO|DELETE\\s+FROM|REPLACE\\s+INTO)[^;]*table\\(\\s*'pairs'\\s*\\)/is", $code ) || preg_match( "/->(update|insert|delete|replace)\\(\\s*AB_MCP_Sync_Schema::table\\(\\s*'pairs'/i", $code ) ) {
				$writers[] = $file;
			}
		}
		self::assertSame( array( 'includes/sync/class-sync-pairs.php' ), $writers );
		$pairs = self::shipped()['includes/sync/class-sync-pairs.php'];
		self::assertSame( 1, preg_match_all( '/function\\s+\\w+\\([^)]*\\$part\\b/', $pairs ) - preg_match_all( '/function\\s+(responsible|part_bases)\\(/', $pairs ), 'rebase() the one writer that takes a part.' );
		self::assertSame( 1, substr_count( $pairs, "\$extra['parts'][ \$part ] = array(" ), 'The base of a further part written in one place.' );
	}
}
