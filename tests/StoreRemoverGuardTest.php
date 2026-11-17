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
 * - Z15, Z16 (Z8b branch 5), the swap: in class-sync-code-swap.php PHP's
 *   rename() only in AB_MCP_Sync_Code_Swap_Calls::rename(), called only in
 *   AB_MCP_Sync_Code_Swap::move() (onto a place lstat() reads as free);
 * - Z9: uninstall.php reaches the store only through
 *   AB_MCP_Protected_Store::uninstall(), which no shipped code calls
 *   otherwise, and names no folder of the store.
 *
 * The walk of the calls (walk(), nikic/php-parser, each file once per
 * process) sees every call whose name is written as a name in the code: a
 * function, a name after ->, ?->, :: or new (new also without
 * parentheses), resolved as PHP resolves it. A name in any spelling or
 * case is the name itself (rename(), \rename(), namespace\rename(),
 * RENAME()), a qualified one (Ns\rename()) another; it follows an import
 * (use function … as, use Class as …) and a namespace (an unqualified
 * function there counts as the global one PHP falls back to); self and
 * static are the class they stand in, parent its extends (in a trait the
 * three stay as written: the class that takes the trait decides); a
 * function that returns by reference (function &name) is that function,
 * and a closure counts as the function it is written in. Its bound is
 * the name as a value at run time, which it does not see: a variable
 * ($f( … ), $obj->$m( … )), a string ('rename'( … )), a callback handed
 * on (call_user_func( 'rename', … ), array_map( 'unlink', … )). For
 * unlink, rmdir, rename, mkdir and wp_delete_file no shipped file holds
 * the name as a string (testNoShippedFileNamesARemoverAsAValue); a name put
 * together ('un' . 'link') stays unseen. Its own test reads the spellings
 * in tests/fixtures/t7-walker/. Every guard here reads the walk, none the
 * text of the code (the skeptic over F2 and G, B1; the skeptic over the
 * reader, B1: a text missed ::delete_path ( with a space): besides the
 * calls, the names a file writes (a class named anywhere in its code), its
 * strings (with the parts of a string that holds variables, and HTML
 * outside PHP) and the writes of a static property (the mark of a tree
 * being built).
 *
 * What the code does at run time is tests/integration/StoreRemoverTest.php
 * and StoreTreesTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Protected_Store;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Assert;
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

	/** The calls of a file (walk()). */
	private static function calls( string $file ): array {
		return self::walk( $file )['calls'];
	}

	/**
	 * Every call of a file whose name is written as a name (the head of this
	 * file): kind (function, method, static, new), name in lower case, of
	 * (the class of a static call; as written where it is a value), the class
	 * and the named function it stands in, its first and second argument as
	 * written and its line, in the order of the file; the lines of every
	 * string of the file (a part of one with variables, HTML outside PHP) by
	 * its text in lower case without a leading \ (V1); every name the file
	 * writes, in lower case, as PHP resolves it; and every write of a static
	 * property (Class::$name, or an element of it: its first key) with the
	 * value as written. Each file is parsed once per process.
	 *
	 * @return array{calls:list<array{kind:string,name:string,of:string,class:?string,function:?string,args:string,second:string,line:int}>,strings:array<string,list<int>>,names:array<string,true>,sets:list<array{of:string,name:string,key:string,value:string,class:?string,function:?string}>}
	 */
	private static function walk( string $file ): array {
		static $walked = array();
		if ( isset( $walked[ $file ] ) ) {
			return unserialize( $walked[ $file ], array( 'allowed_classes' => false ) );
		}
		$code = file_get_contents( dirname( __DIR__ ) . '/' . $file );
		if ( ! is_string( $code ) || '' === $code ) {
			self::fail( $file . ': not read, so the walk would see nothing of it.' );
		}
		try {
			$ast = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( $code );
		} catch ( Error $e ) {
			self::fail( $file . ':' . $e->getStartLine() . ': ' . $e->getRawMessage() );
		}
		$walker = new class( $code, $file ) extends NodeVisitorAbstract {
			/** @var array<int,array<string,mixed>> By the place of the name in the file. */
			public array $calls = array();
			/** @var array<string,list<int>> */
			public array $strings = array();
			/** @var array<string,true> */
			public array $names = array();
			/** @var list<array<string,mixed>> */
			public array $sets = array();
			/** @var list<array{0:Node,1:string}> The classes, traits and named functions the walk is in, with their names. */
			private array $in = array();

			public function __construct( private string $code, private string $file ) {}

			public function enterNode( Node $node ) {
				if ( $node instanceof Node\Stmt\ClassLike ) {
					$this->in[] = array( $node, $node->namespacedName?->toString() ?? 'class@anonymous' );
				} elseif ( $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod ) {
					$this->in[] = array( $node, $node->name->toString() );
				} elseif ( $node instanceof Node\Scalar\String_ || $node instanceof Node\InterpolatedStringPart || $node instanceof Node\Stmt\InlineHTML ) {
					$this->strings[ strtolower( ltrim( $node->value, '\\' ) ) ][] = $node->getStartLine();
				} elseif ( $node instanceof Node\Name ) {
					$this->names[ strtolower( $node->toString() ) ] = true;
				} elseif ( $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp ) {
					$this->set( $node->var, $node->expr );
				} elseif ( $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall ) {
					$this->add( $node instanceof Node\Expr\FuncCall ? 'function' : 'method', $node->name, '', $node->args );
				} elseif ( $node instanceof Node\Expr\StaticCall ) {
					$this->add( 'static', $node->name, $this->class_of( $node->class ), $node->args );
				} elseif ( $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name ) {
					$this->add( 'new', $node->class, '', $node->args );
				}
				return null;
			}

			public function leaveNode( Node $node ) {
				if ( array() !== $this->in && end( $this->in )[0] === $node ) {
					array_pop( $this->in );
				}
				return null;
			}

			/** A call whose name is a name; a name that is a value at run time ($f(), $obj->$m(), Foo::$m()) is none. */
			private function add( string $kind, Node $name, string $of, array $args ): void {
				if ( $name instanceof Node\Expr ) {
					return;
				}
				[ $class, $function ] = $this->place();
				$this->calls[ $name->getStartFilePos() ] = array(
					'kind'     => $kind,
					'name'     => strtolower( 'new' === $kind ? $this->class_of( $name ) : $name->toString() ),
					'of'       => $of,
					'class'    => $class,
					'function' => $function,
					'args'     => isset( $args[0] ) ? $this->text( $args[0] ) : '',
					'second'   => isset( $args[1] ) ? $this->text( $args[1] ) : '',
					'line'     => $name->getStartLine(),
				);
			}

			/** A write of a static property, or of an element of it; any other target is none. */
			private function set( Node\Expr $var, Node\Expr $value ): void {
				$key = '';
				while ( $var instanceof Node\Expr\ArrayDimFetch ) {
					$key = null === $var->dim ? '' : $this->text( $var->dim );
					$var = $var->var;
				}
				if ( $var instanceof Node\Expr\StaticPropertyFetch && $var->name instanceof Node\VarLikeIdentifier ) {
					[ $class, $function ] = $this->place();
					$this->sets[]         = array(
						'of'       => $this->class_of( $var->class ),
						'name'     => strtolower( $var->name->toString() ),
						'key'      => $key,
						'value'    => $this->text( $value ),
						'class'    => $class,
						'function' => $function,
					);
				}
			}

			/** The class and the named function the walk is in. */
			private function place(): array {
				$out = array( null, null );
				foreach ( $this->in as [ $in, $named ] ) {
					$out[ $in instanceof Node\Stmt\ClassLike ? 0 : 1 ] = $named;
				}
				return $out;
			}

			/** The class of a static call or of new: self and static the class they stand in, parent its extends. */
			private function class_of( Node $class ): string {
				if ( ! $class instanceof Node\Name ) {
					return $this->text( $class );
				}
				if ( ! $class->isSpecialClassName() ) {
					return $class->toString();
				}
				$own = null;
				foreach ( $this->in as $in ) {
					$own = $in[0] instanceof Node\Stmt\ClassLike ? $in : $own;
				}
				if ( null !== $own && $own[0] instanceof Node\Stmt\Trait_ ) {
					return strtolower( $class->toString() ); // The class that takes the trait decides.
				}
				$parent = 'parent' === strtolower( $class->toString() );
				if ( null === $own || ( $parent && ! ( $own[0] instanceof Node\Stmt\Class_ && null !== $own[0]->extends ) ) ) {
					Assert::fail( $this->file . ':' . $class->getStartLine() . ': ' . $class->toString() . ' without a class' . ( $parent ? ' that extends one' : '' ) . ': the walk would not know the class.' );
				}
				return $parent ? $own[0]->extends->toString() : $own[1];
			}

			private function text( Node $node ): string {
				return substr( $this->code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1 );
			}
		};
		( new NodeTraverser( new NameResolver(), $walker ) )->traverse( (array) $ast );
		ksort( $walker->calls );
		// Kept as one string a file: the arrays of all shipped files would
		// not fit beside PHPUnit under a memory_limit of 128 MB.
		$out             = array(
			'calls'   => array_values( $walker->calls ),
			'strings' => $walker->strings,
			'names'   => $walker->names,
			'sets'    => $walker->sets,
		);
		$walked[ $file ] = serialize( $out );
		return $out;
	}

	/** Where a call stands: Class::function (or the file where outside a class). */
	private static function places( string $file, string $kind, string $name, string $of = '' ): array {
		$out = array();
		foreach ( self::calls( $file ) as $call ) {
			if ( $kind === $call['kind'] && strtolower( $name ) === $call['name'] && ( '' === $of || 0 === strcasecmp( $of, $call['of'] ) ) ) {
				$out[] = ( $call['class'] ?? $file ) . '::' . ( $call['function'] ?? '' );
			}
		}
		sort( $out );
		return $out;
	}

	/** The shipped files that call a name (places() on every file of shipped()). */
	private static function callers( string $kind, string $name, string $of ): array {
		return array_values( array_filter( self::shipped(), static fn( string $file ): bool => array() !== self::places( $file, $kind, $name, $of ) ) );
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

	/** The shipped files (or those given) whose walk names a name, or holds a string with it, in any case. */
	private static function naming( string $text, ?array $files = null ): array {
		$text = strtolower( $text );
		return array_values(
			array_filter(
				$files ?? self::shipped(),
				static function ( string $file ) use ( $text ): bool {
					$walk = self::walk( $file );
					return isset( $walk['names'][ $text ] ) || array() !== array_filter( array_keys( $walk['strings'] ), static fn( $s ): bool => str_contains( (string) $s, $text ) );
				}
			)
		);
	}

	public function testTheWalkSeesTheCallsOfTheStore(): void {
		$calls = self::calls( self::FILES[0] );
		self::assertGreaterThan( 300, count( $calls ) );
		self::assertContains( 'AB_MCP_Protected_Store::delete_path', self::places( self::FILES[0], 'method', 'unlink' ) );
		self::assertContains( 'AB_MCP_Protected_Store::self_check', self::places( self::FILES[0], 'static', 'write', 'AB_MCP_Payload_File' ) );
	}

	/**
	 * Prüft: the walk reads a name in every spelling as PHP resolves it:
	 * rename(), \rename(), namespace\rename(), \Class:: and namespace\Class::
	 * (also in a closure with its use ( … ), in a class or outside one) as
	 * the name itself, a qualified one (Ns\rename(), Ns\Class::) as another;
	 * it follows an import of a function or a class under a name of the
	 * file's own, reads a class in any case and self, static and parent as
	 * the class they stand for, and a function that returns by reference
	 * (also a closure that does) as that function (Codex D9; the skeptic
	 * over D3b-2b, B2 and C1; part G, V2 and V3).
	 * Sonst: a walk that read an alias, another case or self as no call of
	 * the class, or a function &name as a closure, would leave a guard of
	 * the one places green while PHP's rename(), unlink() or rmdir() is
	 * called past them.
	 */
	public function testTheWalkReadsEverySpellingOfANameAsPhpResolvesIt(): void {
		$dir  = 'tests/fixtures/t7-walker/';
		$seen = array();
		foreach ( self::calls( $dir . 'spellings.php' ) as $call ) {
			$seen[] = $call['kind'] . ' ' . ( '' === $call['of'] ? '' : $call['of'] . '::' ) . $call['name'] . ' in ' . $call['class'] . '::' . $call['function'];
		}
		$in = ' in AB_MCP_Walker_Spellings::all';
		self::assertSame(
			array( 'function rename' . $in, 'function rename' . $in, 'function rename' . $in, 'function ns\\rename' . $in, 'static AB_MCP_Payload_File::write' . $in, 'static AB_MCP_Payload_File::write' . $in, 'static Ns\\AB_MCP_Payload_File::write' . $in, 'method rename' . $in, 'function rename' . $in, 'function rename in ::' ),
			$seen
		);
		self::assertSame( array( $dir . 'import-function.php::' ), self::places( $dir . 'import-function.php', 'function', 'rename' ), 'The walk follows the import of a function.' );
		self::assertSame( array( $dir . 'import-class.php::' ), self::places( $dir . 'import-class.php', 'static', 'write', 'AB_MCP_Payload_File' ), 'And of a class.' );
		$classes = $dir . 'class-spellings.php';
		$all     = array( 'AB_MCP_Walker_Classes::all', 'AB_MCP_Walker_Classes::all' );
		self::assertSame( $all, self::places( $classes, 'static', 'write', 'AB_MCP_PAYLOAD_FILE' ), 'ab_mcp_payload_file:: and Parent:: (its extends).' );
		self::assertSame( $all, self::places( $classes, 'static', 'write', 'ab_mcp_walker_classes' ), 'SELF:: and static::.' );
		self::assertSame( $all, self::places( $classes, 'function', 'unlink' ), 'In function &all() and in its closure function &().' );
	}

	/**
	 * Prüft: no shipped file holds the name of PHP's unlink(), rmdir(),
	 * rename(), mkdir() or of wp_delete_file() as a string, in any case,
	 * with or without a leading \ (V1): so none of them is called with its
	 * name as a value (call_user_func( 'unlink', … ), 'rename'( … ),
	 * array_map( 'rmdir', … )), which the walk does not see.
	 * Sonst: such a call would remove, move or make a folder in the store
	 * or of a swap past every guard of the one places, and they would stay
	 * green.
	 */
	public function testNoShippedFileNamesARemoverAsAValue(): void {
		$found = array();
		$read  = 0;
		foreach ( self::shipped() as $file ) {
			$strings = self::walk( $file )['strings'];
			$read   += count( $strings, COUNT_RECURSIVE ) - count( $strings );
			foreach ( array( 'unlink', 'rmdir', 'rename', 'mkdir', 'wp_delete_file' ) as $name ) {
				foreach ( $strings[ $name ] ?? array() as $line ) {
					$found[] = $file . ':' . $line . ' ' . $name;
				}
			}
		}
		self::assertGreaterThan( 50000, $read, 'The strings of the shipped files are read.' );
		self::assertSame( array(), $found );
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
		self::assertSame( array( 'includes/class-protected-store.php' ), self::naming( 'AB_MCP_Protected_Store_Calls' ), 'No other shipped file names the calls of the store.' );
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::callers( 'static', 'root', 'AB_MCP_Protected_Store' ), 'Only the budget and the measurement get the folder of the store (and the store itself).' );
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::callers( 'static', 'delete_path', 'AB_MCP_Protected_Store' ), 'delete_path() where a file of the store goes.' );
		self::assertSame( array( 'includes/class-protected-store.php', 'includes/class-store-budget.php', 'includes/sync/class-sync-resources.php' ), self::callers( 'static', 'temp_file', 'AB_MCP_Protected_Store' ) );
	}

	public function testNothingOfTheStoreIsWrittenThroughThePayloadFileButTheControlFileBesideIt(): void {
		$all = array();
		foreach ( self::FILES as $file ) {
			foreach ( self::calls( $file ) as $call ) {
				if ( 'static' === $call['kind'] && 'write' === $call['name'] && 0 === strcasecmp( 'AB_MCP_Payload_File', $call['of'] ) ) {
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
		$marks = array();
		foreach ( self::walk( $store )['sets'] as $set ) {
			if ( 'building' === $set['name'] && 0 === strcasecmp( 'AB_MCP_Protected_Store', $set['of'] ) ) {
				$marks[] = $set['class'] . '::' . $set['function'] . ' [' . $set['key'] . '] = ' . $set['value'];
			}
		}
		self::assertSame( array( 'AB_MCP_Protected_Store::put_tree [$place] = true' ), $marks, 'A tree is marked as built in one place.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::put_tree' ), self::places( $store, 'static', 'copy_tree', 'AB_MCP_Protected_Store' ), 'A tree is copied by put_tree() only.' );
		self::assertSame(
			array( 'AB_MCP_Protected_Store::ensure', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::put_back', 'AB_MCP_Protected_Store::write_file' ),
			self::places( $store, 'method', 'rename' ),
			'A temp file into place, the building folder into place, an original back on uninstall: no tree is renamed in the store.'
		);
		self::assertSame( array( 'AB_MCP_Protected_Store_Calls::rename' ), self::places( $store, 'function', 'rename' ) );
		self::assertSame( array( 'AB_MCP_Protected_Store::build_dir', 'AB_MCP_Protected_Store::complete', 'AB_MCP_Protected_Store::copy_tree', 'AB_MCP_Protected_Store::copy_tree', 'AB_MCP_Protected_Store::ensure', 'AB_MCP_Protected_Store::open', 'AB_MCP_Protected_Store::put_mark', 'AB_MCP_Protected_Store::self_check' ), self::places( $store, 'method', 'mkdir' ), 'The folders of a tree in copy_tree() only; a building folder in build_dir() only (Z8b branch 4, D4).' );
	}

	/**
	 * Prüft: the swap renames only through its calls, and those only from
	 * move(), which renames onto a place it read as free (Z15, Z16; part
	 * D3b-2 of the rest).
	 * Sonst: a rename of the swap past move() could put a version over a
	 * folder another process put there, or move the old one out of its
	 * place without its backup, journal and mark, so that the way back and
	 * the instruction by hand no longer find it.
	 */
	public function testTheSwapRenamesOnlyThroughItsCallsAndThoseOnlyFromMove(): void {
		$swap = 'includes/sync/class-sync-code-swap.php';
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap_Calls::rename' ), self::places( $swap, 'function', 'rename' ), 'PHP\'s rename() in the one call of the swap only.' );
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::move' ), self::places( $swap, 'method', 'rename' ), 'That call only from move().' );
	}

	public function testTheRetentionOfABackupIsDecidedByKeptOnly(): void {
		$store = self::FILES[0];
		self::assertSame( array( 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep' ), self::places( $store, 'static', 'kept', 'AB_MCP_Protected_Store' ), 'The clean-up asks kept() before it lets a group of this store go by its time (Z8), and for a sidecar read whole but not this area\'s or installation\'s, with the area it names and the one it lies in (Behebung 1, A1).' );
		self::assertSame( array( 'AB_MCP_Protected_Store::remove', 'AB_MCP_Protected_Store::unswept' ), self::places( $store, 'static', 'delete_group', 'AB_MCP_Protected_Store' ), 'A group goes in remove() and the clean-up only.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::sweep', 'AB_MCP_Protected_Store::sweep' ), self::places( $store, 'static', 'unswept', 'AB_MCP_Protected_Store' ), 'unswept() is the clean-up\'s one way to remove a group (Behebung 4, B1: it says what a mark that cannot be read kept).' );
		// remove() is the way of the owner of a package or a report: no
		// shipped code removes a group of backups or stage by it (a backup
		// goes by its retention only, Z8).
		$areas = array();
		$seam  = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file ) as $call ) {
				if ( 'remove' === $call['name'] && 'static' === $call['kind'] && 0 === strcasecmp( 'AB_MCP_Protected_Store', $call['of'] ) ) {
					$areas[ trim( $call['args'] ) ][] = $call['class'] . '::' . $call['function'];
				} elseif ( 'remove' === $call['name'] && 'method' === $call['kind'] && 'includes/sync/class-sync-proposal.php' === $file ) {
					$seam[ trim( $call['args'] ) ] = true;
				}
			}
		}
		ksort( $areas );
		self::assertSame( array( '$area', "'in'", "'out'", "'reports'" ), array_keys( $areas ), 'The areas remove() is called with.' );
		self::assertSame( array( 'AB_MCP_Sync_Proposal_Calls::remove' ), $areas['$area'], 'The one call with an area named by a variable: the calls of the proposal,' );
		self::assertSame( array( "'reports'" ), array_keys( $seam ), 'which pass reports.' );
	}

	public function testTheUninstallReachesTheStoreOnlyThroughItsOneFunction(): void {
		self::assertSame( array( 'uninstall.php::' ), self::places( 'uninstall.php', 'static', 'uninstall', 'AB_MCP_Protected_Store' ), 'uninstall.php calls it.' );
		$others = array();
		foreach ( self::calls( 'uninstall.php' ) as $call ) {
			if ( 'static' === $call['kind'] && 0 === strcasecmp( 'AB_MCP_Protected_Store', $call['of'] ) && 'uninstall' !== $call['name'] ) {
				$others[] = $call['name'];
			}
		}
		self::assertSame( array(), $others, 'And nothing else of the store.' );
		self::assertSame( array( 'uninstall.php' ), self::callers( 'static', 'uninstall', 'AB_MCP_Protected_Store' ), 'No shipped code but uninstall.php calls it (Pro calls it from its own uninstall).' );
		self::assertSame( array(), self::naming( 'ab-mcp-store', array( 'uninstall.php' ) ), 'uninstall.php names no folder of the store.' );
	}

	/**
	 * Every call of AB_MCP_Protected_Store::open() in shipped code as the walk
	 * reads it (walk()), and the calls of the proposal through its seam
	 * (->open() in its file, as the retention reads ->remove()): file, area
	 * and the meta as written.
	 *
	 * @return array<int,array{file:string,area:string,meta:string}>
	 */
	private static function opens(): array {
		$out = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file ) as $call ) {
				if ( 'open' === $call['name'] && ( ( 'static' === $call['kind'] && 0 === strcasecmp( 'AB_MCP_Protected_Store', $call['of'] ) ) || ( 'method' === $call['kind'] && 'includes/sync/class-sync-proposal.php' === $file ) ) ) {
					$out[] = array(
						'file' => $file,
						'area' => trim( $call['args'] ),
						'meta' => trim( $call['second'] ),
					);
				}
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
			$area = trim( $call['area'], '\'"' );
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
				"includes/sync/class-sync-code-swap.php 'backups'",
				"includes/sync/class-sync-export.php 'out'",
				"includes/sync/class-sync-import.php 'in'",
				'includes/sync/class-sync-proposal.php $area',
				"includes/sync/class-sync-proposal.php 'reports'",
				"includes/sync/class-sync-report.php 'reports'",
			),
			array_keys( $seen ),
			'The writers of sidecars: one area each, the proposal through its seam (reports); stage by the stage of a unit of code with its run (Z8b branch 4, D4); backups by the way back of a swap for the place of the new version, a group of its own with the run of the way back (Z8b branch 5, part E, question 1 B).'
		);
	}
}
