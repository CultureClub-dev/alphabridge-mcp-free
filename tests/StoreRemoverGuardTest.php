<?php
/**
 * The guards of the one places of the protected store (Z8b branch 3; plan
 * 3.1 Z7, Z9 and Z37, with its two guard tests), read from the tokens of
 * the shipped code, nothing loaded:
 *
 * - Z37, the remover: in class-protected-store.php, class-store-budget.php
 *   and class-sync-resources.php nothing removes but delete_path(): PHP's
 *   unlink() and rmdir() only in the two real calls of the seam
 *   (AB_MCP_Protected_Store_Calls::unlink(), ::rmdir()), those called only
 *   in delete_path(); no remove_tree(), remove_group(), wp_delete_file() or
 *   ->delete() in the three files; no other shipped file names the calls of
 *   the store, and only the budget and the measurement of the resources get
 *   the folder of the store (root()), each removing through delete_path();
 * - Z37, the writer: AB_MCP_Payload_File::write() is not called in the
 *   three files but once, in self_check(), for the control file beside the
 *   store (outside it); the bytes of a file of the store are written in
 *   write_file() only (put(), copy_into()), and a temp file is made in
 *   temp_file() only (create());
 * - Z7, trees: a tree is copied only while put_tree() marks it as built
 *   ($building), the folders of a tree are made in copy_tree() only, called
 *   by put_tree() only; the store renames only in write_file() (a temp file
 *   into place), ensure() (the building folder into place) and put_back()
 *   (an original back on uninstall, Z9);
 * - Z9: uninstall.php reaches the store only through
 *   AB_MCP_Protected_Store::uninstall(), which no shipped code calls
 *   otherwise, and names no folder of the store.
 *
 * What the code does at run time is tests/integration/StoreRemoverTest.php
 * and StoreTreesTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Protected_Store;
use PHPUnit\Framework\TestCase;

// The class whose table the cases read, loaded here: never from an earlier
// class of the suite (the class alone).
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-protected-store.php';

final class StoreRemoverGuardTest extends TestCase {

	/** The three files of Z37 (plan 3.1). */
	private const FILES = array(
		'includes/class-protected-store.php',
		'includes/class-store-budget.php',
		'includes/sync/class-sync-resources.php',
	);

	/**
	 * Every call in a file: function, method (->), static (Class::) and new,
	 * with the class and the named function it stands in (a closure counts
	 * as the function it is written in).
	 *
	 * @return array<int,array{kind:string,name:string,of:string,class:string|null,function:string|null,args:string,line:int}>
	 */
	private static function calls( string $file ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ) ),
				static fn( $t ): bool => ! ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) )
			)
		);
		$out     = array();
		$stack   = array();
		$pending = null;
		$n       = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_CLASS === $t[0] && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				$pending = array( 'class', $tokens[ $i + 1 ][1] );
				continue;
			}
			if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
				$next    = $tokens[ $i + 1 ] ?? null;
				$pending = is_array( $next ) && T_STRING === $next[0] ? array( 'function', $next[1] ) : array( 'closure', null );
				continue;
			}
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$stack[] = '{' === $t && null !== $pending ? $pending : array( 'block', null );
				$pending = null;
				continue;
			}
			if ( ';' === $t && null !== $pending && 'class' !== $pending[0] ) {
				$pending = null; // An abstract or interface function: no body.
			}
			if ( '}' === $t ) {
				array_pop( $stack );
				continue;
			}
			if ( ! is_array( $t ) || T_STRING !== $t[0] || '(' !== ( $tokens[ $i + 1 ] ?? null ) ) {
				continue;
			}
			$before = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $before ) && T_FUNCTION === $before[0] ) {
				continue;
			}
			$kind = 'function';
			$of   = '';
			if ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				$kind = 'method';
			} elseif ( is_array( $before ) && T_DOUBLE_COLON === $before[0] ) {
				$kind = 'static';
				$of   = is_array( $tokens[ $i - 2 ] ?? null ) ? $tokens[ $i - 2 ][1] : '';
			} elseif ( is_array( $before ) && T_NEW === $before[0] ) {
				$kind = 'new';
			}
			// The first argument, as written.
			$args  = '';
			$depth = 0;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$tok = $tokens[ $j ];
				if ( '(' === $tok ) {
					++$depth;
					if ( 1 === $depth ) {
						continue;
					}
				} elseif ( ')' === $tok ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $tok && 1 === $depth ) {
					break;
				}
				$args .= is_array( $tok ) ? $tok[1] : $tok;
			}
			$class    = null;
			$function = null;
			foreach ( $stack as $scope ) {
				if ( 'class' === $scope[0] ) {
					$class = $scope[1];
				} elseif ( 'function' === $scope[0] ) {
					$function = $scope[1];
				}
			}
			$out[] = array(
				'kind'     => $kind,
				'name'     => strtolower( $t[1] ),
				'of'       => $of,
				'class'    => $class,
				'function' => $function,
				'args'     => $args,
				'line'     => (int) $t[2],
			);
		}
		return $out;
	}

	/** Where a call stands: Class::function (or the file where outside a class). */
	private static function places( string $file, string $kind, string $name, string $of = '' ): array {
		$out = array();
		foreach ( self::calls( $file ) as $call ) {
			if ( $kind === $call['kind'] && strtolower( $name ) === $call['name'] && ( '' === $of || $of === $call['of'] ) ) {
				$out[] = ( $call['class'] ?? $file ) . '::' . ( $call['function'] ?? '' );
			}
		}
		sort( $out );
		return $out;
	}

	/** The shipped PHP files (includes/, the main file, uninstall.php). */
	private static function shipped(): array {
		$root  = dirname( __DIR__ );
		$files = array( 'alphabridge-mcp.php', 'uninstall.php' );
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.php' === substr( (string) $file, -4 ) ) {
				$files[] = substr( (string) $file, strlen( $root ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/** The files whose code (comments left out) holds a text. */
	private static function holding( string $text ): array {
		$found = array();
		foreach ( self::shipped() as $file ) {
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $t ) {
				if ( ! ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
					$code .= is_array( $t ) ? $t[1] : $t;
				}
			}
			if ( false !== stripos( $code, $text ) ) {
				$found[] = $file;
			}
		}
		return $found;
	}

	public function testTheWalkSeesTheCallsOfTheStore(): void {
		$calls = self::calls( self::FILES[0] );
		self::assertGreaterThan( 300, count( $calls ) );
		self::assertContains( 'AB_MCP_Protected_Store::delete_path', self::places( self::FILES[0], 'method', 'unlink' ) );
		self::assertContains( 'AB_MCP_Protected_Store::self_check', self::places( self::FILES[0], 'static', 'write', 'AB_MCP_Payload_File' ) );
	}

	public function testNothingRemovesInTheStoreButDeletePath(): void {
		$store = self::FILES[0];
		self::assertSame( array( 'AB_MCP_Protected_Store_Calls::unlink' ), self::places( $store, 'function', 'unlink' ), 'PHP\'s unlink() only in the real call of the seam.' );
		self::assertSame( array( 'AB_MCP_Protected_Store_Calls::rmdir' ), self::places( $store, 'function', 'rmdir' ), 'PHP\'s rmdir() only in the real call of the seam.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::delete_path' ), self::places( $store, 'method', 'unlink' ), 'The seam\'s unlink() only in delete_path().' );
		self::assertSame( array( 'AB_MCP_Protected_Store::delete_path' ), self::places( $store, 'method', 'rmdir' ), 'The seam\'s rmdir() only in delete_path().' );
		foreach ( self::FILES as $file ) {
			foreach ( array( 'remove_tree', 'remove_group', 'wp_delete_file' ) as $name ) {
				foreach ( array( 'function', 'static', 'method' ) as $kind ) {
					self::assertSame( array(), self::places( $file, $kind, $name ), "$file: no $name()." );
				}
			}
			self::assertSame( array(), self::places( $file, 'method', 'delete' ), "$file: no ->delete() (WP_Filesystem)." );
			if ( $store !== $file ) {
				foreach ( array( 'unlink', 'rmdir', 'rename', 'file_put_contents', 'fopen', 'copy' ) as $name ) {
					self::assertSame( array(), self::places( $file, 'function', $name ), "$file: no $name(); it writes and removes in the store through the store." );
				}
			}
		}
		self::assertSame( array( 'includes/class-protected-store.php' ), self::holding( 'AB_MCP_Protected_Store_Calls' ), 'No other shipped file names the calls of the store.' );
		self::assertSame( array( 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::holding( 'AB_MCP_Protected_Store::root(' ), 'Only the budget and the measurement get the folder of the store.' );
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::holding( '::delete_path(' ), 'delete_path() where a file of the store goes.' );
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::holding( '::temp_file(' ) );
	}

	public function testNothingOfTheStoreIsWrittenThroughThePayloadFileButTheControlFileBesideIt(): void {
		$all = array();
		foreach ( self::FILES as $file ) {
			foreach ( self::calls( $file ) as $call ) {
				if ( 'static' === $call['kind'] && 'write' === $call['name'] && 'AB_MCP_Payload_File' === $call['of'] ) {
					$all[] = $file . ' ' . $call['class'] . '::' . $call['function'] . '(' . trim( $call['args'] ) . ')';
				}
			}
		}
		self::assertSame( array( 'includes/class-protected-store.php AB_MCP_Protected_Store::self_check($control)' ), $all, 'Only the control file of the self-check, beside the store (outside it).' );
		$store = self::FILES[0];
		self::assertSame( array( 'AB_MCP_Protected_Store::write_file' ), self::places( $store, 'method', 'put' ), 'The bytes of a file of the store in write_file() only.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::write_file' ), self::places( $store, 'method', 'copy_into' ) );
		self::assertSame( array( 'AB_MCP_Protected_Store::temp_file' ), self::places( $store, 'method', 'create' ), 'A temp file in temp_file() only.' );
		self::assertSame( array( 'AB_MCP_Protected_Store_Calls::put' ), self::places( $store, 'function', 'file_put_contents' ), 'PHP\'s file_put_contents() in the real call of the seam only.' );
	}

	public function testATreeIsCopiedOnlyByPutTreeAndTheStoreRenamesOnlyWhereItMay(): void {
		$store = self::FILES[0];
		$code  = (string) file_get_contents( dirname( __DIR__ ) . '/' . $store );
		self::assertSame( 1, preg_match_all( '/self::\$building\[\s*\$place\s*\]\s*=\s*true;/', $code ), 'A tree is marked as built in one place.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::put_tree' ), self::places( $store, 'static', 'copy_tree', 'self' ), 'A tree is copied by put_tree() only.' );
		self::assertSame(
			array( 'AB_MCP_Protected_Store::ensure', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::write_file' ),
			self::places( $store, 'method', 'rename' ),
			'A temp file into place, the building folder into place, an original back on uninstall: no tree is renamed in the store.'
		);
		self::assertSame( array( 'AB_MCP_Protected_Store_Calls::rename' ), self::places( $store, 'function', 'rename' ) );
		self::assertSame( array( 'AB_MCP_Protected_Store::build_dir', 'AB_MCP_Protected_Store::complete', 'AB_MCP_Protected_Store::copy_tree', 'AB_MCP_Protected_Store::copy_tree', 'AB_MCP_Protected_Store::ensure', 'AB_MCP_Protected_Store::open', 'AB_MCP_Protected_Store::put_mark', 'AB_MCP_Protected_Store::self_check' ), self::places( $store, 'method', 'mkdir' ), 'The folders of a tree in copy_tree() only; a building folder in build_dir() only (Z8b branch 4, D4).' );
	}

	public function testTheRetentionOfABackupIsDecidedByKeptOnly(): void {
		$store = self::FILES[0];
		self::assertSame( array( 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep' ), self::places( $store, 'static', 'kept', 'self' ), 'The clean-up asks kept() before it lets a group of this store go by its time (Z8), and for a sidecar read whole but not this area\'s or installation\'s, with the area it names and the one it lies in (Behebung 1, A1).' );
		self::assertSame( array( 'AB_MCP_Protected_Store::remove', 'AB_MCP_Protected_Store::unswept' ), self::places( $store, 'static', 'delete_group', 'self' ), 'A group goes in remove() and the clean-up only.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep' ), self::places( $store, 'static', 'unswept', 'self' ), 'unswept() is the clean-up\'s one way to remove a group (Behebung 4, B1: it says what a mark that cannot be read kept).' );
		// remove() is the way of the owner of a package or a report: no
		// shipped code removes a group of backups or stage by it (a backup
		// goes by its retention only, Z8).
		$areas = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file ) as $call ) {
				if ( 'remove' === $call['name'] && ( ( 'static' === $call['kind'] && 'AB_MCP_Protected_Store' === $call['of'] ) || ( 'method' === $call['kind'] && 'includes/sync/class-sync-proposal.php' === $file ) ) ) {
					$areas[ trim( $call['args'] ) ] = true;
				}
			}
		}
		ksort( $areas );
		self::assertSame( array( '$area', "'in'", "'out'", "'reports'" ), array_keys( $areas ), 'The areas remove() is called with.' );
		$proposal = (string) file_get_contents( dirname( __DIR__ ) . '/includes/sync/class-sync-proposal.php' );
		self::assertSame( 1, substr_count( $proposal, 'return AB_MCP_Protected_Store::remove( $area, $gid );' ), 'The one call with an area named by a variable: the calls of the proposal, which pass reports.' );
		self::assertSame( 0, preg_match( "/->remove\\(\\s*'(?!reports')/", $proposal ) );
	}

	public function testTheUninstallReachesTheStoreOnlyThroughItsOneFunction(): void {
		self::assertSame( array( 'uninstall.php::' ), self::places( 'uninstall.php', 'static', 'uninstall', 'AB_MCP_Protected_Store' ), 'uninstall.php calls it.' );
		$others = array();
		foreach ( self::calls( 'uninstall.php' ) as $call ) {
			if ( 'static' === $call['kind'] && 'AB_MCP_Protected_Store' === $call['of'] && 'uninstall' !== $call['name'] ) {
				$others[] = $call['name'];
			}
		}
		self::assertSame( array(), $others, 'And nothing else of the store.' );
		self::assertSame( array( 'uninstall.php' ), self::holding( 'AB_MCP_Protected_Store::uninstall(' ), 'No shipped code but uninstall.php calls it (Pro calls it from its own uninstall).' );
		self::assertNotContains( 'uninstall.php', self::holding( 'ab-mcp-store' ), 'uninstall.php names no folder of the store.' );
	}

	/**
	 * Every call of AB_MCP_Protected_Store::open() in shipped code, comments
	 * left out (and the calls of the proposal through its seam, ->open()):
	 * file, area and the meta as written.
	 *
	 * @return array<int,array{file:string,area:string,meta:string}>
	 */
	private static function opens(): array {
		$out = array();
		foreach ( self::shipped() as $file ) {
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $t ) {
				if ( ! ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
					$code .= is_array( $t ) ? $t[1] : $t;
				}
			}
			if ( ! preg_match_all( '/(?:AB_MCP_Protected_Store::|calls\(\)->)open\(/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $hit ) {
				$at    = $hit[1] + strlen( $hit[0] );
				$depth = 1;
				$args  = '';
				for ( $i = $at; $i < strlen( $code ) && $depth > 0; $i++ ) {
					$c = $code[ $i ];
					$depth += '(' === $c ? 1 : ( ')' === $c ? -1 : 0 );
					if ( $depth > 0 ) {
						$args .= $c;
					}
				}
				$first = trim( (string) strtok( $args, ',' ) );
				$out[] = array(
					'file' => $file,
					'area' => $first,
					'meta' => trim( substr( $args, strlen( (string) strtok( $args, ',' ) ) + 1 ) ),
				);
			}
		}
		return $out;
	}

	public function testTheShippedWritersOfAnAreaOfRunsOnlyGiveTheirRun(): void {
		// Behebung 2 of Z8b branch 3, A1: the table SIDECAR says per area
		// whether it knows groups of no run (run_id 'count') or of a run only
		// ('run'); open() refuses a group the table does not hold, and every
		// shipped writer of an area of runs only gives the run.
		$runs_only = array_keys( array_filter( AB_MCP_Protected_Store::SIDECAR, static fn( array $row ): bool => 'run' === $row['run_id'] ) );
		self::assertSame( array( 'backups', 'stage' ), $runs_only );
		self::assertSame( array( 'out', 'in', 'reports' ), array_keys( array_filter( AB_MCP_Protected_Store::SIDECAR, static fn( array $row ): bool => 'count' === $row['run_id'] ) ) );
		foreach ( AB_MCP_Protected_Store::SIDECAR as $area => $row ) {
			self::assertSame( array( 'format', 'gid', 'area', 'created', 'user_id', 'run_id', 'declared', 'used', 'label', 'closed', 'binding' ), array_keys( $row ), "$area: every field open() writes is a field of its row." );
		}
		$seen = array();
		foreach ( self::opens() as $call ) {
			if ( 'includes/class-protected-store.php' === $call['file'] ) {
				continue;
			}
			$area = trim( $call['area'], "'" );
			$seen[ $call['file'] . ' ' . $call['area'] ] = true;
			if ( in_array( $area, $runs_only, true ) ) {
				self::assertStringContainsString( "'run_id'", $call['meta'], $call['file'] . ': a writer of ' . $area . ' gives the run.' );
			}
		}
		ksort( $seen );
		self::assertSame(
			array(
				"includes/sync/class-sync-backup.php 'backups'",
				"includes/sync/class-sync-code-stage.php 'stage'",
				"includes/sync/class-sync-export.php 'out'",
				"includes/sync/class-sync-import.php 'in'",
				'includes/sync/class-sync-proposal.php $area',
				"includes/sync/class-sync-proposal.php 'reports'",
				"includes/sync/class-sync-report.php 'reports'",
			),
			array_keys( $seen ),
			'The writers of sidecars: one area each, the proposal through its seam (reports); stage by the stage of a unit of code with its run (Z8b branch 4, D4).'
		);
	}
}
