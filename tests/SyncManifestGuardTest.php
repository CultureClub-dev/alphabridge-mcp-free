<?php
/**
 * The guards of the places of the promises of Z8b branch 2 (plan 3.1, Z1,
 * Z2, Z35, Z38): no way past the one place in the shipped code.
 *
 * - Z1, Z2: the hash of a unit comes only from AB_MCP_Sync_File_Manifest::
 *   unit(): no other shipped file writes the text of a manifest (text(),
 *   the format ab-files/1) or hashes a unit, and the reading of the
 *   environment puts into the line of a unit only the hash unit() gave;
 *   the locks of what no manifest holds (code:live_extra, code:link) and of
 *   a theme outside the folder of the themes (code:theme_root) are set in
 *   unit() only, which is told the kind of each unit (Codex round 1, B1);
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
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/sync/class-sync-code-stage.php', 'includes/sync/class-sync-env.php', 'includes/sync/class-sync-export.php' ), self::holding( 'AB_MCP_Sync_File_Manifest::unit(' ), 'The units read in the reading of the environment, the trees of the protected store (Z8b branch 3, Z7: what goes in comes back byte for byte, by the manifest unit() gives), and of Z8b branch 4 the unit a package of code is made of (code_unit()) and the live unit, the unit built and the tree to check of a stage (live(), read_built()): each by the manifest unit() gives, compared with the signed one.' );
		$store = self::shipped()['includes/class-protected-store.php'];
		self::assertSame( 1, substr_count( $store, 'AB_MCP_Sync_File_Manifest::unit(' ), 'The store reads a tree in one place (read_tree()).' );
		self::assertSame( 1, preg_match( "/function read_tree\\([^{]*\\{[^}]*AB_MCP_Sync_File_Manifest::unit\\([^;]*'tree'\\s*\\);/s", $store ), 'read_tree() tells unit() the kind tree: never a theme of a further root.' );
		self::assertSame( 0, preg_match( '/hash_init|hash_file\\(/', $store ), 'The store hashes no tree beside unit().' );
		$env = self::shipped()['includes/sync/class-sync-env.php'];
		self::assertSame( 1, substr_count( $env, "'hash'     => \$read['hash']," ), 'The line of a unit takes the hash unit() gave.' );
		self::assertSame( 0, preg_match( "/'hash'\\s*=>\\s*+(?!\\\$read\\['hash'\\]|null|'hash\\?')/", $env ), 'No other hash of a unit in the reading.' );
		self::assertSame( 0, preg_match( '/hash_init|hash_file\\(\\s*\'sha256\'\\s*,\\s*\\$(?!path\\b)/', $env ), 'No hash of the files of a unit beside unit().' );
	}

	public function testTheLocksOfWhatNoManifestHoldsAndOfAFurtherRootOfThemesAreSetInUnitOnly(): void {
		$manifest = array( 'includes/sync/class-sync-file-manifest.php' );
		self::assertSame( $manifest, self::holding( 'LOCK_BY_SIDE' ), 'The locks of a side named and set in the manifest only.' );
		self::assertSame( $manifest, self::holding( 'THEME_ROOT_LOCK' ) );
		foreach ( array( 'code:live_extra', 'code:link', 'code:theme_root' ) as $lock ) {
			self::assertSame( array( 'includes/sync/class-sync-env.php', 'includes/sync/class-sync-file-manifest.php' ), self::holding( "'$lock'" ), $lock . ': its value in the manifest, its reason and stage in CODE_LOCKS.' );
			self::assertSame( 1, substr_count( self::shipped()['includes/sync/class-sync-env.php'], "'$lock'" ), $lock . ': in the environment the key of CODE_LOCKS only, never set on a line.' );
		}
		$env = self::shipped()['includes/sync/class-sync-env.php'];
		self::assertSame( 2, substr_count( $env, 'AB_MCP_Sync_File_Manifest::unit(' ), 'The units and the themes of a further root, both through unit().' );
		self::assertSame( 2, preg_match_all( "/AB_MCP_Sync_File_Manifest::unit\\([^;]*,\\s*(\\\$kind|'theme')\\s*\\)/", $env ), 'Each call tells unit() the kind.' );
		self::assertSame( 1, preg_match_all( "/'lock'\\s*=>\\s*\\\$read\\['lock'\\]/", $env ), 'The line of a unit takes the lock unit() gave.' );
		$manifest_code = self::shipped()['includes/sync/class-sync-file-manifest.php'];
		self::assertSame( 1, preg_match( "/if \\( 'theme' === \\\$kind && \\( null === self::theme_root\\(\\)/", $manifest_code ), 'unit() takes a theme from the folder of the themes only.' );
	}

	public function testTheClockIsAskedInOnePlaceWhileAUnitIsRead(): void {
		// Behebung 5: the time of the call while a unit is walked, its files
		// read and the unit checked once more, all through due().
		$code = self::shipped()['includes/sync/class-sync-file-manifest.php'];
		self::assertSame( 1, substr_count( $code, 'self::now()' ), 'The clock asked in due() only.' );
		self::assertSame( 6, substr_count( $code, 'self::due( $acc, $deadline, $first )' ), 'Before each entry, before a file, after each part of it, before each folder and each path of still(), and at the release of the hash.' );
		// Behebung 6: the promise «no hash past the time of the call» where the
		// hash is given: one due() between the hash and its release, nothing else.
		self::assertSame( 1, preg_match( '/\$hash = hash\( \'sha256\', \$text \);(.*?)\$acc\[\'hash\'\]\s*= \$hash;/s', $code, $m ), 'The hash computed apart, then given.' );
		self::assertSame( 1, substr_count( $m[1], 'self::due( $acc, $deadline, $first )' ), 'Exactly one due() between the hash and its release.' );
		self::assertSame( 1, substr_count( $m[1], 'complete' ), 'complete set after it, with the hash.' );
		self::assertSame( 1, substr_count( $code, "\$acc['hash']     = \$hash;" ), 'The hash given in one place.' );
		self::assertSame( 1, preg_match( '/function still\\( array &\\$acc, \\$deadline, \\$first \\)/', $code ), 'still() is told the time of the call.' );
	}

	public function testOnlyTheComparisonOfTheCoreAsksForItsChecksums(): void {
		self::assertSame( array(), self::holding( 'get_core_checksums(' ), 'Never through the core (http again).' );
		self::assertSame( array( 'includes/sync/class-sync-core-compare.php' ), self::holding( 'core/checksums' ) );
		self::assertSame( array( 'includes/sync/class-sync-env.php' ), self::holding( 'AB_MCP_Sync_Core_Compare::check(' ) );
	}

	public function testOnlyThePairsWriteTheTableOfThePairsAndOnlyRebaseTakesAPart(): void {
		$writers = array();
		foreach ( self::shipped() as $file => $code ) {
			if ( preg_match( "/(UPDATE|INSERT\\s+INTO|DELETE\\s+FROM|REPLACE\\s+INTO)[^;]*table\\(\\s*'pairs'\\s*\\)/is", $code ) || preg_match( "/->(update|insert|insert_row|delete|replace)\\(\\s*AB_MCP_Sync_Schema::table\\(\\s*'pairs'/i", $code ) ) {
				$writers[] = $file;
			}
		}
		self::assertSame( array( 'includes/sync/class-sync-pairs.php' ), $writers );
		$pairs = self::shipped()['includes/sync/class-sync-pairs.php'];
		// responsible(), part_bases(), unit_part() and the step_holds() of a
		// proof (Z8b branch 2b) read a part; they write nothing.
		self::assertSame( 1, preg_match_all( '/function\\s+\\w+\\([^)]*\\$part\\b/', $pairs ) - preg_match_all( '/function\\s+(responsible|part_bases|unit_part|step_holds)\\(/', $pairs ), 'rebase() the one writer that takes a part.' );
		self::assertSame( 1, substr_count( $pairs, "self::calls()->insert_row( AB_MCP_Sync_Schema::table( 'pairs' )" ), 'One insert of a pair, in insert_pair().' );
		self::assertSame( 1, substr_count( $pairs, "\$extra['parts'][ \$part ] = array(" ), 'The base of a further part written in one place.' );
	}
}
