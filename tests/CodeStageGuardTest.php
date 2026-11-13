<?php
/**
 * The guards of Z8b branch 4 over the source by its tokens (plan 3.1, the
 * one place of each promise; Auftrag «Durchsetzungsorte»):
 *
 * - Z5: no way past from_org() to a package of wordpress.org for a stage:
 *   in includes/sync/ only the stage names downloads.wordpress.org, and
 *   there only from_org() takes its address (ORG_URL) and calls the
 *   downloader and the unpacker of a ZIP; unzip_file() is called in one
 *   place of the shipped code besides the core's upgrader: the seam of the
 *   stage (no unpacker for a ZIP of a foreign source on this site). The
 *   install tools reach the same downloader with version (D2), named here;
 *   their way without version is a named limit (G-4-1), not checked here.
 * - Z6: no state «ready» but from complete(): READY is written in
 *   complete() only; a state is written only by take_in() (checking) and
 *   next(); next() and refuse() only from complete(); no other shipped
 *   file writes a state of a stage (its format).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class CodeStageGuardTest extends TestCase {

	private const STAGE = 'includes/sync/class-sync-code-stage.php';

	private static function root(): string {
		return dirname( __DIR__ ) . '/';
	}

	/**
	 * Every place a name occurs as code (no comment, no string), with the
	 * function it lies in: [ function, line ].
	 *
	 * @return array<int,array{0:string,1:int,2:string}> function, line, the token before.
	 */
	private static function places( string $file, string $name ): array {
		$tokens   = token_get_all( (string) file_get_contents( self::root() . $file ) );
		$out      = array();
		$function = '';
		$depth    = 0;
		$open     = null;
		$prev     = '';
		foreach ( $tokens as $i => $t ) {
			if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
				for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
						$function = $tokens[ $j ][1];
						$open     = $depth;
						break;
					}
					if ( '(' === $tokens[ $j ] ) {
						break;
					}
				}
			}
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $t ) {
				--$depth;
				if ( null !== $open && $depth === $open ) {
					$function = '';
					$open     = null;
				}
			}
			if ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_CONSTANT_ENCAPSED_STRING ), true ) && ( $name === $t[1] || "'" . $name . "'" === $t[1] ) ) {
				$out[] = array( $function, $t[2], $prev );
			}
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$prev = is_array( $t ) ? $t[1] : $t;
			}
		}
		return $out;
	}

	/** The functions a name occurs in as code, sorted, each once; its declaration (const, function) left out. */
	private static function in( string $file, string $name ): array {
		$used = array_filter( self::places( $file, $name ), static fn( array $place ): bool => ! in_array( strtolower( $place[2] ), array( 'const', 'function' ), true ) );
		$out  = array_values( array_unique( array_column( $used, 0 ) ) );
		sort( $out );
		return $out;
	}

	/** @return string[] The shipped PHP files under includes/, relative. */
	private static function shipped(): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = substr( $file->getPathname(), strlen( self::root() ) );
			}
		}
		sort( $out );
		return $out;
	}

	public function testOnlyFromOrgFetchesAPackageOfWordpressOrgForAStage(): void {
		$naming = array();
		foreach ( self::shipped() as $file ) {
			$code = (string) file_get_contents( self::root() . $file );
			if ( 0 === strpos( $file, 'includes/sync/' ) && false !== stripos( $code, 'downloads.wordpress.org' ) ) {
				$naming[] = $file;
			}
		}
		self::assertSame( array( self::STAGE ), $naming, 'In the takeover only the stage names downloads.wordpress.org.' );
		self::assertSame( array( '' ), array_values( array_unique( array_column( self::places( self::STAGE, 'https://downloads.wordpress.org/' ), 0 ) ) ), 'Its address in ORG_URL only (a constant of the class).' );
		self::assertSame( array( 'from_org' ), self::in( self::STAGE, 'ORG_URL' ), 'ORG_URL is taken in from_org() only.' );
		self::assertSame( array( 'from_org' ), self::in( self::STAGE, 'fetch_zip' ), 'The downloader is called in from_org() only.' );
		self::assertSame( array( 'from_org' ), self::in( self::STAGE, 'unzip' ), 'The seam of the unpacker, called in from_org() only.' );
		// The unpacker of the core: in the seam of the stage only, in the shipped code.
		$unzip = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::places( $file, 'unzip_file' ) as $place ) {
				$unzip[] = $file . ' ' . $place[0];
			}
		}
		self::assertSame( array( self::STAGE . ' unzip' ), $unzip, 'No unpacker of a ZIP elsewhere (plan E3: no unpacker for a foreign ZIP on the live site).' );
		// The other callers of the downloader: the install tools with version (D2).
		$callers = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::places( $file, 'fetch_zip' ) as $place ) {
				if ( 'function' !== $place[2] ) {
					$callers[] = $file . ' ' . $place[0];
				}
			}
		}
		sort( $callers );
		self::assertSame( array( 'includes/sync/class-sync-code-stage.php from_org', 'includes/tools/class-tools-install-update.php install_version' ), $callers );
	}

	public function testOnlyCompleteMakesAStageReady(): void {
		self::assertSame( array( 'complete', 'ready' ), self::in( self::STAGE, 'READY' ), 'READY is written in complete() and read in ready() only.' );
		$writes = array();
		foreach ( self::places( self::STAGE, 'READY' ) as $place ) {
			if ( 'complete' === $place[0] ) {
				$writes[] = $place[2];
			}
		}
		self::assertSame( array( '::' ), $writes );
		self::assertSame( array( 'next', 'take_in' ), self::in( self::STAGE, 'write_state' ), 'A state is written by take_in() and next() only.' );
		self::assertSame( array( 'complete', 'refuse' ), self::in( self::STAGE, 'next' ), 'next() from complete() and refuse() only.' );
		self::assertSame( array( 'complete' ), self::in( self::STAGE, 'refuse' ), 'refuse() from complete() only.' );
		self::assertSame( array( 'complete', 'take_in' ), self::in( self::STAGE, 'CHECKING' ), 'take_in() writes its first state checking; complete() goes on from it.' );
		$format = array();
		foreach ( self::shipped() as $file ) {
			if ( false !== strpos( (string) file_get_contents( self::root() . $file ), 'ab-code-stage/1' ) ) {
				$format[] = $file;
			}
		}
		self::assertSame( array( 'includes/class-store-inputs.php', self::STAGE ), $format, 'No other file knows the format of a state: the stage and the form of its row.' );
	}
}
