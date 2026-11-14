<?php
/**
 * The reader of the guard of the read budget (R12b, part 2; the guard is
 * tests/ReadBudgetGuardTest.php, its lists tests/ReadBudgetSoll.php).
 *
 * It reads the functions of includes/ by their own tokens (PhpToken, the
 * tokens of token_get_all(), no pattern over the text) and resolves the
 * calls between them as the reading of R12 resolved them at 8daf33f
 * (belege/stoppfall-r12-f2-kandidaten.php, variant W-a of the order of
 * R12b, section 3.2): self::, static::, parent::, a class, $this->, new,
 * instances assigned in the function, the table of returns RET and the hand
 * tables INST, INST_BYNAME and DYN. From AB_MCP_Sync_Code_Swap::prepare()
 * it follows the calls along the frame of R12 (FRAME) up to the boundaries
 * (BOUNDARY, includes/lifecycle/) and the cut edges (CUT), and it tells
 * the chain of the check of names (the functions reached only through
 * AB_MCP_Sync_Env::held()) from the rest. basis() counts in that rest what
 * the guard counts (G1 to G7 of the order).
 *
 * No control flow: each body is read once, front to back. A call it cannot
 * resolve, a dynamic call without an entry in DYN, a function of PHP or
 * WordPress without a kind in KIND and an entry of a hand table no code
 * uses are problems, never skipped (problems in the result).
 *
 * The words of the kinds (Platte, Codec, Sortierung, Datenbank, sonst) and
 * of the roles (Rahmen, Fristhelfer, Weg) are the terms of the order.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

final class ReadBudgetScan {

	/** Where the reading starts: the swap, which forms the deadline in checked(). */
	public const START = 'AB_MCP_Sync_Code_Swap::prepare';

	/** The one entry into the chain of the check of names. */
	public const HELD = 'AB_MCP_Sync_Env::held';

	/** The class of the deadline and what its methods are at a call. */
	private const DEADLINE = 'AB_MCP_Sync_Deadline';
	private const DEADLINE_EVENTS = array(
		'left' => 'CHECK',
		'stop' => 'STOP',
		'halt' => 'HALT',
		'at'   => 'AT',
	);

	/** Functions of PHP that call a function they are given. */
	private const ITERCB = array( 'array_map', 'array_filter', 'array_walk', 'array_walk_recursive', 'array_reduce', 'call_user_func', 'call_user_func_array', 'usort', 'uasort', 'uksort', 'preg_replace_callback', 'preg_replace_callback_array', 'iterator_apply' );

	/**
	 * The place of the callback among the arguments of each function of
	 * ITERCB (the manual of PHP; part 2.3b, Codex B1). A helper of the steps
	 * takes the deadline first and then the same arguments (the order, section
	 * 3.1): there its callback is one place further.
	 */
	private const CB_ARG = array(
		'array_map'                   => 0,
		'array_filter'                => 1,
		'array_walk'                  => 1,
		'array_walk_recursive'        => 1,
		'array_reduce'                => 1,
		'call_user_func'              => 0,
		'call_user_func_array'        => 0,
		'usort'                       => 1,
		'uasort'                      => 1,
		'uksort'                      => 1,
		'preg_replace_callback'       => 1,
		'preg_replace_callback_array' => 0,
		'iterator_apply'              => 1,
	);

	/** The kinds of a step (the order, section 1, «Was „Schritt“ heisst»). */
	public const STEP_KINDS = array( 'Platte', 'Codec', 'Sortierung', 'Datenbank' );

	/** The events that ask the deadline or its halt. */
	private const ASKS = array( 'CHECK', 'STOP', 'HALT', 'AT' );

	/** @var array<string,string> Relative path => source. */
	private array $sources;

	/** @var array<string,mixed> The hand tables. */
	private array $hand;

	/** @var array<string,array{file:string,parent:?string,traits:list<string>}> */
	private array $classes = array();

	/** @var array<string,array<string,mixed>> Class::name => the function. */
	private array $methods = array();

	/** @var array<string,list<string>> Class::name => the files of a second declaration. */
	private array $twice = array();

	/** @var array<string,string> Class::name => the class it returns (RET and K::calls()). */
	private array $ret = array();

	/** @var array<string,string> The entries of RET from the hand table. */
	private array $ret_hand = array();

	/** @var array<string,list<\PhpToken>> Class::name => the tokens of its body, of the functions reached. */
	private array $toks = array();

	/** @var array<string,list<array<string,mixed>>> Class::name => its events, of the functions reached. */
	private array $events = array();

	/** @var array<string,true> */
	private array $reach = array();

	/** @var array<string,array<string,list<int>>> from => to => lines. */
	private array $edges = array();

	/** @var array<string,list<int>> 'from|to' => lines, at a boundary. */
	private array $bounds = array();

	/** @var array<string,list<int>> 'from|to' => lines, at a cut edge. */
	private array $cuts = array();

	/** @var list<string> */
	private array $problems = array();

	/** @var array<string,array<string,int>> table => entry => uses. */
	private array $used = array();

	/** @var string The file whose tokens are kept. */
	private string $cached = '';

	/** @var list<\PhpToken> */
	private array $cache = array();

	/**
	 * The sources under includes/ of a plugin folder.
	 *
	 * @param string $root The plugin folder.
	 * @return array<string,string> 'includes/…' => source.
	 */
	public static function sources( string $root ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.php' !== substr( $file->getFilename(), -4 ) ) {
				continue;
			}
			$code = file_get_contents( $file->getPathname() );
			if ( ! is_string( $code ) ) {
				throw new \RuntimeException( 'Not read: ' . $file->getPathname() );
			}
			$out[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = $code;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Reads the sources with the hand tables.
	 *
	 * @param array<string,string> $sources Relative path => source.
	 * @param array<string,mixed>  $hand    The hand tables (tests/ReadBudgetSoll.php, key HAND).
	 * @return array<string,mixed> The model: methods, events, toks, reach, chain, edges, bounds, cuts, sccs, problems.
	 */
	public static function read( array $sources, array $hand ): array {
		$reader = new self( $sources, $hand );
		$reader->index();
		$reader->walk();
		$reader->check();
		return $reader->model();
	}

	/**
	 * @param array<string,string> $sources Relative path => source.
	 * @param array<string,mixed>  $hand    The hand tables.
	 */
	private function __construct( array $sources, array $hand ) {
		$this->sources = $sources;
		foreach ( array( 'FRAME', 'NO_CAND', 'ROOTS', 'BOUNDARY', 'HELPERS', 'HELPER_EVENT', 'RET', 'INST_BYNAME', 'INST', 'DYN', 'CUT', 'KIND', 'STEP_CLASS' ) as $table ) {
			if ( ! array_key_exists( $table, $hand ) ) {
				throw new \InvalidArgumentException( 'Hand table missing: ' . $table );
			}
		}
		$this->hand = $hand;
	}

	/* ---------- 1. Classes and functions ---------- */

	private function index(): void {
		foreach ( $this->sources as $file => $code ) {
			$toks     = \PhpToken::tokenize( $code );
			$n        = count( $toks );
			$cls      = null;
			$clsdepth = -1;
			$depth    = 0;
			for ( $i = 0; $i < $n; $i++ ) {
				$t = $toks[ $i ];
				if ( self::opens( $t ) ) {
					++$depth;
				} elseif ( '}' === $t->text ) {
					--$depth;
					if ( $depth === $clsdepth ) {
						$cls      = null;
						$clsdepth = -1;
					}
				}
				if ( in_array( $t->id, array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) ) {
					$pv = self::prev( $toks, $i );
					if ( null !== $pv && in_array( $toks[ $pv ]->id, array( T_DOUBLE_COLON, T_NEW ), true ) ) {
						continue; // X::class, new class.
					}
					$j = self::next( $toks, $i );
					if ( null !== $j && T_STRING === $toks[ $j ]->id ) {
						$cls      = $toks[ $j ]->text;
						$clsdepth = $depth;
						$parent   = null;
						for ( $k = $j; $k < $n && '{' !== $toks[ $k ]->text; $k++ ) {
							if ( T_EXTENDS === $toks[ $k ]->id ) {
								$parent = ltrim( self::text( $toks, self::next( $toks, $k ) ), '\\' );
							}
						}
						$this->classes[ $cls ] = array(
							'file'   => $file,
							'parent' => $parent,
							'traits' => array(),
						);
					}
					continue;
				}
				if ( T_USE === $t->id && null !== $cls && $depth === $clsdepth + 1 ) {
					for ( $k = $i + 1; $k < $n && ';' !== $toks[ $k ]->text && '{' !== $toks[ $k ]->text; $k++ ) {
						if ( in_array( $toks[ $k ]->id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
							$this->classes[ $cls ]['traits'][] = ltrim( $toks[ $k ]->text, '\\' );
						}
					}
					continue;
				}
				if ( T_FUNCTION !== $t->id ) {
					continue;
				}
				$j = self::next( $toks, $i );
				if ( null === $j || '(' === $toks[ $j ]->text || ( '&' === $toks[ $j ]->text && '(' === self::text( $toks, self::next( $toks, $j ) ) ) ) {
					continue; // A closure.
				}
				if ( '&' === $toks[ $j ]->text ) {
					$j = self::next( $toks, $j );
				}
				$name = self::text( $toks, $j );
				$p    = self::next( $toks, $j );
				if ( null === $p || '(' !== $toks[ $p ]->text ) {
					$this->problems[] = sprintf( 'UNSUPPORTED %s:%d function without parameters', $file, $t->line );
					continue;
				}
				$pe     = self::paren( $toks, $p );
				$params = array();
				foreach ( self::args( $toks, $p, $pe ) as $seg ) {
					if ( 1 === preg_match( '/(&?)\s*(\.\.\.)?\s*(\$\w+)\s*(=\s*(.*))?$/', $seg, $mm ) ) {
						$default = null;
						if ( isset( $mm[5] ) ) {
							$default = trim( $mm[5] );
						}
						$params[] = array(
							'name'    => $mm[3],
							'ref'     => '&' === $mm[1],
							'default' => $default,
							'decl'    => $seg,
						);
					}
				}
				$b = $pe;
				while ( $b < $n && '{' !== $toks[ $b ]->text && ';' !== $toks[ $b ]->text ) {
					++$b;
				}
				if ( $b >= $n || ';' === $toks[ $b ]->text ) {
					continue; // Abstract or of an interface.
				}
				$e = self::brace( $toks, $b );
				$c = '';
				if ( null !== $cls ) {
					$c = $cls;
				}
				$key = $c . '::' . $name;
				if ( isset( $this->methods[ $key ] ) ) {
					$this->twice[ $key ][] = $file;
				}
				$this->methods[ $key ] = array(
					'file'   => $file,
					'class'  => $c,
					'name'   => $name,
					'start'  => $t->line,
					'end'    => $toks[ $e ]->line,
					'params' => $params,
					'b'      => $b,
					'e'      => $e,
				);
				if ( null === $cls ) {
					$i = $e; // The body of a function outside a class is skipped (its braces pair).
				}
			}
		}
		// K::calls() returns the seam K_Calls where there is one (RET of the hand table comes first).
		foreach ( $this->hand['RET'] as $mk => $class ) {
			$this->ret[ $mk ]      = $class;
			$this->ret_hand[ $mk ] = $class;
		}
		foreach ( $this->methods as $mk => $mm ) {
			if ( 'calls' === $mm['name'] && isset( $this->classes[ $mm['class'] . '_Calls' ] ) && ! isset( $this->ret[ $mk ] ) ) {
				$this->ret[ $mk ] = $mm['class'] . '_Calls';
			}
		}
	}

	/**
	 * A method over its class, traits and parents.
	 *
	 * @return ?string Class::name, 'EXT:Class::name' for a class not of the plugin, or null.
	 */
	private function resolve( ?string $c, string $m ): ?string {
		$seen = array();
		while ( null !== $c && ! isset( $seen[ $c ] ) ) {
			$seen[ $c ] = true;
			if ( isset( $this->methods[ $c . '::' . $m ] ) ) {
				return $c . '::' . $m;
			}
			if ( ! isset( $this->classes[ $c ] ) ) {
				return 'EXT:' . $c . '::' . $m;
			}
			foreach ( $this->classes[ $c ]['traits'] as $tr ) {
				if ( isset( $this->methods[ $tr . '::' . $m ] ) ) {
					return $tr . '::' . $m;
				}
			}
			$c = $this->classes[ $c ]['parent'];
		}
		return null;
	}

	private function parent_of( string $c ): ?string {
		if ( ! isset( $this->classes[ $c ] ) ) {
			return null;
		}
		return $this->classes[ $c ]['parent'];
	}

	/** The class a name stands for in a function of $own (self, static, parent). */
	private function named( string $name, string $own ): ?string {
		$ln = strtolower( $name );
		if ( 'self' === $ln || 'static' === $ln ) {
			return $own;
		}
		if ( 'parent' === $ln ) {
			return $this->parent_of( $own );
		}
		return $name;
	}

	/** What a call of Class::name is for the deadline: CHECK, STOP, HALT, AT or null. */
	private function asks( ?string $c, string $meth ): ?string {
		if ( self::DEADLINE === $c ) {
			if ( isset( self::DEADLINE_EVENTS[ $meth ] ) ) {
				return self::DEADLINE_EVENTS[ $meth ];
			}
			return null;
		}
		$key = $c . '::' . $meth;
		if ( isset( $this->hand['HELPER_EVENT'][ $key ] ) ) {
			$this->use( 'HELPER_EVENT', $key );
			return $this->hand['HELPER_EVENT'][ $key ];
		}
		return null;
	}

	private function use( string $table, string $entry ): void {
		if ( ! isset( $this->used[ $table ][ $entry ] ) ) {
			$this->used[ $table ][ $entry ] = 0;
		}
		++$this->used[ $table ][ $entry ];
	}

	/** @return list<\PhpToken> The tokens of a file (the last one read is kept). */
	private function file_tokens( string $file ): array {
		if ( $this->cached !== $file ) {
			$this->cache  = \PhpToken::tokenize( $this->sources[ $file ] );
			$this->cached = $file;
		}
		return $this->cache;
	}

	/* ---------- 2. Events of a function ---------- */

	/**
	 * The events of one function, front to back: LOOP, CALL (into the plugin),
	 * FN (a function of PHP or WordPress), XM and XN (a method or constructor
	 * of a class not of the plugin), DYN (a dynamic call), CB (a function
	 * that calls one it is given), STEP (a helper of the steps), CHECK, STOP,
	 * HALT and AT (a question to the deadline), UNRES (not resolved).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function events_of( string $key ): array {
		$m    = $this->methods[ $key ];
		$all  = $this->file_tokens( $m['file'] );
		$toks = array_slice( $all, $m['b'], $m['e'] - $m['b'] + 1 );
		$this->toks[ $key ] = $toks;
		$n      = count( $toks );
		$ev     = array();
		$own    = $m['class'];
		$depth  = 0;
		$doends = array();
		// Local types: $v = new X(…) and $v = K::f() with its return in RET.
		$vtype = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( T_VARIABLE !== $toks[ $i ]->id ) {
				continue;
			}
			$a = self::next( $toks, $i );
			if ( '=' !== self::text( $toks, $a ) ) {
				continue;
			}
			$b = self::next( $toks, $a );
			if ( null === $b ) {
				continue;
			}
			if ( T_NEW === $toks[ $b ]->id ) {
				$cn = $this->named( ltrim( self::text( $toks, self::next( $toks, $b ) ), '\\' ), $own );
				if ( null !== $cn ) {
					$vtype[ $toks[ $i ]->text ] = $cn;
				}
				continue;
			}
			$dc = self::next( $toks, $b );
			if ( in_array( $toks[ $b ]->id, array( T_STRING, T_STATIC, T_NAME_FULLY_QUALIFIED ), true ) && null !== $dc && T_DOUBLE_COLON === $toks[ $dc ]->id ) {
				$cn = $this->named( ltrim( $toks[ $b ]->text, '\\' ), $own );
				$rk = $this->resolve( $cn, self::text( $toks, self::next( $toks, $dc ) ) );
				if ( null !== $rk && isset( $this->ret[ $rk ] ) ) {
					$vtype[ $toks[ $i ]->text ] = $this->ret[ $rk ];
					$this->ret_use( $rk );
				}
			}
		}
		// The closures (function (…) use (…) { } and fn (…) => expression): each its own region.
		$regions = array();
		for ( $i = 1; $i < $n; $i++ ) {
			if ( T_FUNCTION === $toks[ $i ]->id ) {
				$j = self::next( $toks, $i );
				if ( null === $j || ( '(' !== $toks[ $j ]->text && '&' !== $toks[ $j ]->text ) ) {
					continue;
				}
				$b = $j;
				while ( $b < $n && '{' !== $toks[ $b ]->text ) {
					++$b;
				}
				$regions[] = array( $i, self::brace( $toks, $b ), $toks[ $i ]->line );
			} elseif ( T_FN === $toks[ $i ]->id ) {
				$a = $i;
				while ( $a < $n && T_DOUBLE_ARROW !== $toks[ $a ]->id ) {
					++$a;
				}
				$d = 0;
				for ( $b = $a + 1; $b < $n; $b++ ) {
					$x = $toks[ $b ]->text;
					if ( in_array( $x, array( '(', '[', '{' ), true ) ) {
						++$d;
					}
					if ( in_array( $x, array( ')', ']', '}' ), true ) ) {
						if ( 0 === $d ) {
							break;
						}
						--$d;
					}
					if ( 0 === $d && ( ',' === $x || ';' === $x ) ) {
						break;
					}
				}
				$regions[] = array( $i, $b - 1, $toks[ $i ]->line );
			}
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $toks[ $i ];
			if ( self::opens( $t ) ) {
				++$depth;
			}
			if ( '}' === $t->text ) {
				--$depth;
			}
			// Loops.
			if ( in_array( $t->id, array( T_FOREACH, T_FOR, T_WHILE, T_DO ), true ) ) {
				if ( T_WHILE === $t->id ) {
					$pp = self::prev( $toks, $i );
					if ( null !== $pp && '}' === $toks[ $pp ]->text && isset( $doends[ $pp ] ) ) {
						continue; // The while of a do … while.
					}
				}
				if ( T_DO === $t->id ) {
					$bs = self::next( $toks, $i );
					if ( '{' !== self::text( $toks, $bs ) ) {
						$this->problems[] = sprintf( 'UNSUPPORTED %s:%d do without braces in %s', $m['file'], $t->line, $key );
						continue;
					}
					$be            = self::brace( $toks, $bs );
					$doends[ $be ] = true;
					$ws            = self::next( $toks, $be );
					$hp            = self::next( $toks, $ws );
					$he            = self::paren( $toks, $hp );
					$head          = 'do … while ' . self::flat( $toks, $hp, $he );
				} else {
					$hp   = self::next( $toks, $i );
					$he   = self::paren( $toks, $hp );
					$head = $t->text . ' ' . self::flat( $toks, $hp, $he );
					$bs   = self::next( $toks, $he );
					if ( '{' === self::text( $toks, $bs ) ) {
						$be = self::brace( $toks, $bs );
					} elseif ( ':' === self::text( $toks, $bs ) ) {
						$this->problems[] = sprintf( 'UNSUPPORTED %s:%d loop in the alternative syntax in %s', $m['file'], $t->line, $key );
						continue;
					} else {
						$be = $bs;
						while ( $be < $n && ';' !== $toks[ $be ]->text ) {
							++$be;
						}
					}
				}
				$ev[] = array(
					'k'    => 'LOOP',
					'what' => $t->text,
					'line' => $t->line,
					'head' => $head,
					'hp'   => $hp,
					'he'   => $he,
					'bs'   => $bs,
					'be'   => $be,
					'pos'  => $i,
					'd'    => $depth,
				);
				continue;
			}
			// A callback as text 'Class::name', or array( X, 'name' ) and [ X, 'name' ].
			if ( T_CONSTANT_ENCAPSED_STRING === $t->id ) {
				$this->string_callback( $toks, $i, $own, $depth, $ev );
				continue;
			}
			// new X(.
			if ( T_NEW === $t->id ) {
				$cn = self::next( $toks, $i );
				if ( null === $cn ) {
					continue;
				}
				if ( T_CLASS === $toks[ $cn ]->id ) {
					$ev[] = array( 'k' => 'XN', 'what' => 'new class', 'line' => $t->line, 'pos' => $i, 'd' => $depth );
					continue;
				}
				$name = ltrim( $toks[ $cn ]->text, '\\' );
				if ( T_VARIABLE === $toks[ $cn ]->id ) {
					$ev[] = array( 'k' => 'DYN', 'what' => 'new ' . $name, 'line' => $t->line, 'args' => array(), 'pos' => $i, 'pe' => $cn, 'd' => $depth );
					$i    = $cn; // Its ( is that of new, no call of $name (part 2.2).
					continue;
				}
				$c    = $this->named( $name, $own );
				$po   = self::next( $toks, $cn );
				$pe   = $cn;
				$args = array();
				if ( '(' === self::text( $toks, $po ) ) {
					$pe   = self::paren( $toks, $po );
					$args = self::args( $toks, $po, $pe );
				}
				if ( null !== $c && isset( $this->classes[ $c ] ) ) {
					$rk = $this->resolve( $c, '__construct' );
					if ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
						$ev[] = array( 'k' => 'CALL', 'to' => array( $rk ), 'line' => $t->line, 'via' => 'new', 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
					} elseif ( null !== $rk ) {
						$ev[] = array( 'k' => 'XM', 'what' => substr( $rk, 4 ), 'line' => $t->line, 'pos' => $i, 'd' => $depth );
					} else {
						$ev[] = array( 'k' => 'XN', 'what' => 'new ' . $c . ' (ohne Konstruktor)', 'line' => $t->line, 'pos' => $i, 'd' => $depth );
					}
				} else {
					$ev[] = array( 'k' => 'XM', 'what' => $c . '::__construct', 'line' => $t->line, 'pos' => $i, 'd' => $depth );
				}
				$i = $cn;
				continue;
			}
			// $v::m( (dynamic, static), $f( (a dynamic function), ->$m( and ::$m( (a dynamic name).
			if ( T_VARIABLE === $t->id ) {
				$nx = self::next( $toks, $i );
				$pv = self::prev( $toks, $i );
				if ( null !== $nx && T_DOUBLE_COLON === $toks[ $nx ]->id ) {
					$mn = self::next( $toks, $nx );
					$po = self::next( $toks, $mn );
					if ( T_STRING === self::id( $toks, $mn ) && '(' === self::text( $toks, $po ) ) {
						$pe   = self::paren( $toks, $po );
						$ev[] = array( 'k' => 'DYN', 'what' => $t->text . '::' . $toks[ $mn ]->text, 'line' => $t->line, 'args' => self::args( $toks, $po, $pe ), 'pos' => $i, 'pe' => $pe, 'd' => $depth );
						$i    = $mn;
					}
					continue;
				}
				if ( '(' === self::text( $toks, $nx ) ) {
					$pe = self::paren( $toks, $nx );
					if ( null === $pv || ! in_array( $toks[ $pv ]->id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
						$ev[] = array( 'k' => 'DYN', 'what' => $t->text . '(', 'line' => $t->line, 'args' => self::args( $toks, $nx, $pe ), 'pos' => $i, 'pe' => $pe, 'd' => $depth );
					} else {
						$ev[] = array( 'k' => 'DYN', 'what' => $toks[ $pv ]->text . $t->text . '(', 'line' => $t->line, 'args' => self::args( $toks, $nx, $pe ), 'pos' => $i, 'pe' => $pe, 'd' => $depth );
					}
				}
				continue;
			}
			if ( in_array( $t->id, array( T_NAME_QUALIFIED, T_NAME_RELATIVE ), true ) && in_array( self::id( $toks, self::next( $toks, $i ) ), array( T_DOUBLE_COLON, ord( '(' ) ), true ) ) {
				$this->problems[] = sprintf( 'UNSUPPORTED %s:%d name with a namespace %s in %s', $m['file'], $t->line, $t->text, $key );
				continue;
			}
			if ( T_STRING !== $t->id && T_STATIC !== $t->id && T_NAME_FULLY_QUALIFIED !== $t->id ) {
				continue;
			}
			$name = ltrim( $t->text, '\\' );
			$nx   = self::next( $toks, $i );
			$pv   = self::prev( $toks, $i );
			// Class::name(.
			if ( null !== $nx && T_DOUBLE_COLON === $toks[ $nx ]->id ) {
				$mn = self::next( $toks, $nx );
				$po = self::next( $toks, $mn );
				if ( T_STRING === self::id( $toks, $mn ) && '(' === self::text( $toks, $po ) ) {
					$this->static_call( $toks, $i, $mn, $po, $name, $own, $depth, $ev );
					$i = $mn;
				}
				continue;
			}
			if ( '(' !== self::text( $toks, $nx ) ) {
				continue;
			}
			// ->name( and ?->name(.
			if ( null !== $pv && in_array( $toks[ $pv ]->id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				$this->instance_call( $toks, $i, $nx, $pv, $name, $key, $vtype, $depth, $ev );
				continue;
			}
			if ( null !== $pv && in_array( $toks[ $pv ]->id, array( T_FUNCTION, T_NEW, T_DOUBLE_COLON, T_FN, T_CONST ), true ) ) {
				continue;
			}
			// A function.
			$ln   = strtolower( $name );
			$pe   = self::paren( $toks, $nx );
			$args = self::args( $toks, $nx, $pe );
			if ( isset( $this->methods[ '::' . $name ] ) ) {
				$ev[] = array( 'k' => 'CALL', 'to' => array( '::' . $name ), 'line' => $t->line, 'via' => 'Funktion', 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
				continue;
			}
			$ev[] = array( 'k' => 'FN', 'what' => $ln, 'line' => $t->line, 'pos' => $i, 'pe' => $pe, 'args' => $args, 'd' => $depth );
			if ( in_array( $ln, self::ITERCB, true ) ) {
				self::iteration( $toks, $i, $nx, $pe, $ln, 0, $depth, $ev );
			}
		}
		foreach ( $ev as $q => $e ) {
			list( $ev[ $q ]['cl'], $ev[ $q ]['cll'] ) = self::closure_of( $regions, $e['pos'] );
		}
		return $this->dynamic( $key, $ev );
	}

	/**
	 * A call of a function of ITERCB (or of its helper, with $shift 1: the
	 * deadline first) at $i, its parenthesis $po to $pe: the event CB with the
	 * arguments of the function (args, without the deadline of a helper), its
	 * callback (cb, the text of the argument at its place in CB_ARG, or null
	 * where it is not given, with its first and last token cbs and cbe), and
	 * an event FN (cbtext) where the callback names a function of PHP.
	 *
	 * @param list<\PhpToken>            $toks Tokens of the body.
	 * @param list<array<string,mixed>> $ev   Events so far.
	 */
	private static function iteration( array $toks, int $i, int $po, int $pe, string $ln, int $shift, int $depth, array &$ev ): void {
		$t     = $toks[ $i ];
		$args  = array_slice( self::args( $toks, $po, $pe ), $shift );
		$spans = array_slice( self::spans( $toks, $po, $pe ), $shift );
		$at    = self::CB_ARG[ $ln ];
		$cb    = array(
			'k'    => 'CB',
			'what' => $ln,
			'line' => $t->line,
			'args' => $args,
			'cb'   => null,
			'cbs'  => $pe,
			'cbe'  => $pe,
			'text' => self::flat( $toks, $po, $pe ),
			'pos'  => $i,
			'pe'   => $pe,
			'd'    => $depth,
		);
		if ( 1 === $shift ) {
			$cb['helper'] = true;
		}
		if ( isset( $args[ $at ], $spans[ $at ] ) ) {
			$cb['cb']  = $args[ $at ];
			$cb['cbs'] = $spans[ $at ][0];
			$cb['cbe'] = $spans[ $at ][1];
		}
		$ev[] = $cb;
		if ( null !== $cb['cb'] && 1 === preg_match( "/^'\\\\?([a-z_][a-z0-9_]*)'$/i", $cb['cb'], $mm ) && 1 !== preg_match( '/^AB_MCP_/', $mm[1] ) ) {
			$ev[] = array( 'k' => 'FN', 'what' => strtolower( $mm[1] ), 'line' => $t->line, 'pos' => $i, 'pe' => $pe, 'args' => array(), 'd' => $depth, 'cbtext' => true );
		}
	}

	/**
	 * @param list<\PhpToken>            $toks  Tokens of the body.
	 * @param list<array<string,mixed>> $ev    Events so far.
	 */
	private function string_callback( array $toks, int $i, string $own, int $depth, array &$ev ): void {
		$t = $toks[ $i ];
		$s = trim( $t->text, "'\"" );
		if ( 1 === preg_match( '/^(AB_MCP_\w+)::(\w+)$/', $s, $mm ) ) {
			$rk = $this->resolve( $mm[1], $mm[2] );
			if ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
				$ev[] = array( 'k' => 'CALL', 'to' => array( $rk ), 'line' => $t->line, 'via' => 'Rückruf (Text)', 'args' => array(), 'pos' => $i, 'pe' => $i, 'd' => $depth );
			}
		}
		$pv = self::prev( $toks, $i );
		if ( ',' !== self::text( $toks, $pv ) ) {
			return;
		}
		$pc = self::prev( $toks, $pv );
		$cl = null;
		$st = $pc;
		if ( null === $pc ) {
			return;
		}
		if ( T_CONSTANT_ENCAPSED_STRING === $toks[ $pc ]->id && 1 === preg_match( '/^AB_MCP_\w+$/', trim( $toks[ $pc ]->text, "'\"" ) ) ) {
			$cl = trim( $toks[ $pc ]->text, "'\"" );
		} elseif ( T_CLASS_C === $toks[ $pc ]->id || '$this' === $toks[ $pc ]->text ) {
			$cl = $own;
		} elseif ( T_CLASS === $toks[ $pc ]->id ) {
			$q = self::prev( $toks, $pc );
			if ( null !== $q && T_DOUBLE_COLON === $toks[ $q ]->id ) {
				$q2 = self::prev( $toks, $q );
				$cl = $this->named( ltrim( self::text( $toks, $q2 ), '\\' ), $own );
				$st = $q2;
			}
		}
		if ( null === $cl ) {
			return;
		}
		$op    = self::prev( $toks, $st );
		$cp    = self::next( $toks, $i );
		$isarr = '[' === self::text( $toks, $op ) || ( '(' === self::text( $toks, $op ) && T_ARRAY === self::id( $toks, self::prev( $toks, $op ) ) );
		if ( $isarr && in_array( self::text( $toks, $cp ), array( ')', ']' ), true ) ) {
			$rk = $this->resolve( $cl, trim( $t->text, "'\"" ) );
			if ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
				$ev[] = array( 'k' => 'CALL', 'to' => array( $rk ), 'line' => $t->line, 'via' => 'Rückruf (array)', 'args' => array(), 'pos' => $i, 'pe' => $i, 'd' => $depth );
			}
		}
	}

	/**
	 * The step a helper of the steps stands for: the name of its method, and
	 * for wpdb_<method> the method of $wpdb (part 3.1).
	 *
	 * @param string $meth The name of the method of the helper.
	 */
	public static function step_of_helper( string $meth ): string {
		$step = strtolower( $meth );
		if ( 0 === strpos( $step, 'wpdb_' ) ) {
			return 'wpdb->' . substr( $step, 5 );
		}
		return $step;
	}

	/**
	 * Class::name( at $i (the name of the method at $mn, its parenthesis at $po).
	 *
	 * @param list<\PhpToken>            $toks Tokens of the body.
	 * @param list<array<string,mixed>> $ev   Events so far.
	 */
	private function static_call( array $toks, int $i, int $mn, int $po, string $name, string $own, int $depth, array &$ev ): void {
		$t    = $toks[ $i ];
		$ln   = strtolower( $name );
		$c    = $this->named( $name, $own );
		$meth = $toks[ $mn ]->text;
		$pe   = self::paren( $toks, $po );
		$args = self::args( $toks, $po, $pe );
		if ( $c === $this->hand['STEP_CLASS'] ) {
			// A helper of the steps (section 3.1 of the order): the step it runs,
			// and where that step calls a function it is given, its callback one
			// place further, after the deadline (part 2.3b, Codex B1). The helper
			// of a method of $wpdb is wpdb_<method>, the step wpdb-><method> (part
			// 3.1: a name of a method holds no ->); helper_method is the method,
			// the check of G7.
			$ev[] = array( 'k' => 'STEP', 'what' => self::step_of_helper( $meth ), 'helper_method' => strtolower( $meth ), 'line' => $t->line, 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
			if ( in_array( strtolower( $meth ), self::ITERCB, true ) ) {
				self::iteration( $toks, $i, $po, $pe, strtolower( $meth ), 1, $depth, $ev );
			}
			return;
		}
		$rk = $this->resolve( $c, $meth );
		if ( null === $rk || 0 === strpos( $rk, 'EXT:' ) ) {
			$ck = $this->asks( $c, $meth );
		} else {
			list( $rc, $rm ) = explode( '::', $rk );
			$ck              = $this->asks( $rc, $rm );
		}
		if ( null !== $ck ) {
			$what = $c . '::' . $meth;
			if ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
				$what = $rk;
			}
			$ev[] = array( 'k' => $ck, 'line' => $t->line, 'what' => $what, 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
		} elseif ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
			$via = '::';
			if ( 'static' === $ln ) {
				$via = 'static::';
			}
			$ev[] = array( 'k' => 'CALL', 'to' => array( $rk ), 'line' => $t->line, 'via' => $via, 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
		} elseif ( null !== $rk ) {
			$ev[] = array( 'k' => 'XM', 'what' => substr( $rk, 4 ), 'line' => $t->line, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
		} else {
			$ev[] = array( 'k' => 'UNRES', 'what' => $c . '::' . $meth, 'line' => $t->line, 'pos' => $i, 'd' => $depth );
		}
	}

	/**
	 * ->name( at $i (its parenthesis at $nx, the arrow at $pv).
	 *
	 * @param list<\PhpToken>            $toks  Tokens of the body.
	 * @param array<string,string>      $vtype Local types.
	 * @param list<array<string,mixed>> $ev    Events so far.
	 */
	private function instance_call( array $toks, int $i, int $nx, int $pv, string $name, string $key, array $vtype, int $depth, array &$ev ): void {
		$m    = $this->methods[ $key ];
		$t    = $toks[ $i ];
		$own  = $m['class'];
		$pe   = self::paren( $toks, $nx );
		$args = self::args( $toks, $nx, $pe );
		$obj  = self::prev( $toks, $pv );
		$recv = null;
		$how  = '';
		if ( '$this' === self::text( $toks, $obj ) ) {
			$recv = $own;
			$how  = '$this->';
		} elseif ( T_VARIABLE === self::id( $toks, $obj ) && isset( $vtype[ $toks[ $obj ]->text ] ) ) {
			$recv = $vtype[ $toks[ $obj ]->text ];
			$how  = $toks[ $obj ]->text . '-> (lokal)';
		} elseif ( ')' === self::text( $toks, $obj ) ) {
			// K::f()->name(.
			$o2 = self::prev( $toks, $obj );
			if ( '(' === self::text( $toks, $o2 ) ) {
				$o3 = self::prev( $toks, $o2 );
				$o4 = self::prev( $toks, $o3 );
				$o5 = self::prev( $toks, $o4 );
				if ( T_DOUBLE_COLON === self::id( $toks, $o4 ) ) {
					$cn = $this->named( ltrim( self::text( $toks, $o5 ), '\\' ), $own );
					$rk = $this->resolve( $cn, self::text( $toks, $o3 ) );
					if ( null !== $rk && isset( $this->ret[ $rk ] ) ) {
						$recv = $this->ret[ $rk ];
						$how  = preg_replace( '/^AB_MCP_/', '', $rk ) . '()->';
						$this->ret_use( $rk );
					}
				}
			}
		}
		if ( null === $recv && '$wpdb' === self::text( $toks, $obj ) ) {
			$recv = 'wpdb';
			$how  = 'global $wpdb';
		}
		if ( null === $recv && isset( $this->hand['INST_BYNAME'][ $name ] ) ) {
			$recv = $this->hand['INST_BYNAME'][ $name ];
			$how  = 'Name (INST_BYNAME)';
			$this->use( 'INST_BYNAME', $name );
		}
		$ik = $key . '|->' . $name;
		if ( null === $recv && isset( $this->hand['INST'][ $ik ] ) ) {
			$recv = $this->hand['INST'][ $ik ];
			$how  = 'Hand (INST)';
			$this->use( 'INST', $ik );
		}
		if ( null === $recv ) {
			$ev[] = array( 'k' => 'UNRES', 'what' => '->' . $name, 'recv' => self::flat( $toks, max( 0, $pv - 6 ), $pv ), 'line' => $t->line, 'pos' => $i, 'd' => $depth );
			return;
		}
		$rk = $this->resolve( $recv, $name );
		if ( null !== $rk && 0 !== strpos( $rk, 'EXT:' ) ) {
			$ev[] = array( 'k' => 'CALL', 'to' => array( $rk ), 'line' => $t->line, 'via' => $how, 'args' => $args, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
			return;
		}
		$what = $recv . '->' . $name;
		if ( null !== $rk ) {
			$what = str_replace( '::', '->', substr( $rk, 4 ) );
		}
		$ev[] = array( 'k' => 'XM', 'what' => $what, 'line' => $t->line, 'pos' => $i, 'pe' => $pe, 'd' => $depth );
	}

	private function ret_use( string $rk ): void {
		if ( isset( $this->ret_hand[ $rk ] ) ) {
			$this->use( 'RET', $rk );
		}
	}

	/**
	 * The dynamic calls by the hand table DYN (key Class::name|form): to plugin
	 * functions (CALL), to a function of PHP (fn), or none (a closure of the
	 * same function, the clock).
	 *
	 * @param list<array<string,mixed>> $ev Events.
	 * @return list<array<string,mixed>>
	 */
	private function dynamic( string $key, array $ev ): array {
		$add = array();
		foreach ( $ev as $idx => $e ) {
			$is_call = 'FN' === $e['k'] && in_array( $e['what'], array( 'call_user_func', 'call_user_func_array' ), true ) && ! isset( $e['cbtext'] );
			if ( 'DYN' !== $e['k'] && ! $is_call ) {
				continue;
			}
			$dk                 = $key . '|' . $e['what'];
			$ev[ $idx ]['form'] = $e['what'];
			if ( ! isset( $this->hand['DYN'][ $dk ] ) ) {
				$ev[ $idx ]['nodyn'] = true;
				continue;
			}
			$this->use( 'DYN', $dk );
			$entry             = $this->hand['DYN'][ $dk ];
			$ev[ $idx ]['dyn'] = $entry;
			if ( isset( $entry['fn'] ) ) {
				$add[] = array( 'k' => 'FN', 'what' => $entry['fn'], 'line' => $e['line'], 'pos' => $e['pos'], 'pe' => $e['pe'], 'args' => array(), 'd' => $e['d'], 'cl' => $e['cl'], 'cll' => $e['cll'], 'viadyn' => true );
			}
			$targets = array();
			foreach ( $entry['to'] as $to ) {
				if ( ! isset( $this->methods[ $to ] ) ) {
					$this->problems[] = sprintf( 'DYN %s: target %s is no function of the plugin', $dk, $to );
					continue;
				}
				$targets[] = $to;
			}
			if ( array() === $targets ) {
				continue;
			}
			$ev[ $idx ]['k_orig'] = $e['k'];
			$ev[ $idx ]['k']      = 'CALL';
			$ev[ $idx ]['to']     = $targets;
			$ev[ $idx ]['via']    = 'dynamisch (' . $e['what'] . ')';
			if ( 'FN' === $e['k'] ) {
				// The call of call_user_func itself stays in the table of functions.
				$add[] = array( 'k' => 'FN', 'what' => $e['what'], 'line' => $e['line'], 'pos' => $e['pos'], 'pe' => $e['pe'], 'args' => array(), 'd' => $e['d'], 'cbtext' => true, 'cl' => $e['cl'], 'cll' => $e['cll'] );
			}
		}
		foreach ( $add as $a ) {
			$ev[] = $a;
		}
		usort(
			$ev,
			static function ( array $a, array $b ): int {
				return $a['pos'] <=> $b['pos'];
			}
		);
		return $ev;
	}

	/* ---------- 3. What is reached ---------- */

	private function walk(): void {
		$queue = array( self::START );
		while ( array() !== $queue ) {
			$k = array_shift( $queue );
			if ( isset( $this->reach[ $k ] ) || ! isset( $this->methods[ $k ] ) ) {
				continue;
			}
			$this->reach[ $k ]  = true;
			$this->edges[ $k ]  = array();
			$this->events[ $k ] = $this->events_of( $k );
			$frame              = null;
			if ( isset( $this->hand['FRAME'][ $k ] ) ) {
				$frame = $this->hand['FRAME'][ $k ];
			}
			foreach ( $this->events[ $k ] as $e ) {
				foreach ( $this->targets( $e ) as $to ) {
					if ( null !== $frame && ! in_array( $to, $frame, true ) ) {
						continue; // The frame: only the edges named.
					}
					if ( null !== $frame ) {
						$this->use( 'FRAME', $k . '|' . $to );
					}
					if ( in_array( $to, $this->hand['BOUNDARY'], true ) || 0 === strpos( $this->methods[ $to ]['file'], 'includes/lifecycle/' ) ) {
						$this->bounds[ $k . '|' . $to ][] = $e['line'];
						continue;
					}
					if ( isset( $this->hand['CUT'][ $k . '|' . $to ] ) ) {
						$this->cuts[ $k . '|' . $to ][] = $e['line'];
						continue;
					}
					$this->edges[ $k ][ $to ][] = $e['line'];
					if ( ! isset( $this->reach[ $to ] ) ) {
						$queue[] = $to;
					}
				}
			}
		}
	}

	/**
	 * The functions of the plugin an event goes into.
	 *
	 * @param array<string,mixed> $e Event.
	 * @return list<string>
	 */
	private function targets( array $e ): array {
		if ( 'CALL' === $e['k'] ) {
			return $e['to'];
		}
		if ( in_array( $e['k'], self::ASKS, true ) && isset( $this->methods[ $e['what'] ] ) ) {
			return array( $e['what'] );
		}
		return array();
	}

	/** What is not resolved, and what of the hand tables is not used. */
	private function check(): void {
		foreach ( array_keys( $this->reach ) as $k ) {
			$file = $this->methods[ $k ]['file'];
			if ( isset( $this->twice[ $k ] ) ) {
				$this->problems[] = sprintf( 'TWICE %s declared in %s and %s', $k, $file, implode( ', ', $this->twice[ $k ] ) );
			}
			foreach ( $this->events[ $k ] as $idx => $e ) {
				if ( 'UNRES' === $e['k'] ) {
					$recv = '';
					if ( isset( $e['recv'] ) ) {
						$recv = ' after ' . $e['recv'];
					}
					$this->problems[] = sprintf( 'UNRES %s:%d %s %s%s', $file, $e['line'], $k, $e['what'], $recv );
				}
				if ( isset( $e['nodyn'] ) ) {
					$this->problems[] = sprintf( 'DYN %s:%d %s %s without an entry %s|%s', $file, $e['line'], $k, $e['what'], $k, $e['what'] );
				}
				if ( in_array( $e['k'], array( 'FN', 'XM', 'XN', 'STEP' ), true ) ) {
					if ( ! isset( $this->hand['KIND'][ $e['what'] ] ) ) {
						$this->problems[] = sprintf( 'KIND missing: %s (%s:%d %s)', $e['what'], $file, $e['line'], $k );
						continue;
					}
					$this->use( 'KIND', $e['what'] );
					$kind = $this->hand['KIND'][ $e['what'] ];
					if ( isset( $e['cbtext'] ) && in_array( $e['what'], array( 'call_user_func', 'call_user_func_array' ), true ) ) {
						continue;
					}
					if ( in_array( $kind[0], self::STEP_KINDS, true ) && true === $kind[1] ) {
						$this->events[ $k ][ $idx ]['step'] = $kind[0];
					} elseif ( 'STEP' === $e['k'] ) {
						$this->problems[] = sprintf( 'STEP %s:%d %s: a helper of %s, which is no step', $file, $e['line'], $k, $e['what'] );
					}
				}
			}
		}
		foreach ( $this->hand['ROOTS'] as $r ) {
			if ( ! isset( $this->reach[ $r ] ) ) {
				$this->problems[] = 'ROOT not reached: ' . $r;
			}
		}
		foreach ( $this->hand['FRAME'] as $from => $tos ) {
			foreach ( $tos as $to ) {
				$this->unused( 'FRAME', $from . '|' . $to );
			}
		}
		foreach ( array_keys( $this->hand['NO_CAND'] ) as $k ) {
			if ( ! isset( $this->reach[ $k ] ) ) {
				$this->problems[] = 'NO_CAND not reached: ' . $k;
			}
		}
		foreach ( array_keys( $this->hand['HELPERS'] ) as $k ) {
			if ( ! isset( $this->reach[ $k ] ) ) {
				$this->problems[] = 'HELPERS not reached: ' . $k;
			}
		}
		foreach ( $this->hand['BOUNDARY'] as $b ) {
			$hit = false;
			foreach ( array_keys( $this->bounds ) as $edge ) {
				if ( substr( $edge, -strlen( '|' . $b ) ) === '|' . $b ) {
					$hit = true;
				}
			}
			if ( ! $hit ) {
				$this->problems[] = 'BOUNDARY not met: ' . $b;
			}
		}
		foreach ( array_keys( $this->hand['CUT'] ) as $edge ) {
			if ( ! isset( $this->cuts[ $edge ] ) ) {
				$this->problems[] = 'CUT not met: ' . $edge;
			}
		}
		foreach ( array( 'HELPER_EVENT', 'RET', 'INST_BYNAME', 'INST', 'DYN', 'KIND' ) as $table ) {
			foreach ( array_keys( $this->hand[ $table ] ) as $entry ) {
				$this->unused( $table, $entry );
			}
		}
	}

	private function unused( string $table, int|string $entry ): void {
		if ( ! isset( $this->used[ $table ][ $entry ] ) ) {
			$this->problems[] = sprintf( '%s not used: %s', $table, $entry );
		}
	}

	/** @return array<string,mixed> */
	private function model(): array {
		// The chain: what is reached only through held().
		$seen  = array();
		$queue = array( self::START );
		while ( array() !== $queue ) {
			$k = array_shift( $queue );
			if ( isset( $seen[ $k ] ) || ! isset( $this->reach[ $k ] ) || self::HELD === $k ) {
				continue;
			}
			$seen[ $k ] = true;
			foreach ( array_keys( $this->edges[ $k ] ) as $to ) {
				if ( ! isset( $seen[ $to ] ) ) {
					$queue[] = $to;
				}
			}
		}
		$chain = array();
		foreach ( array_keys( $this->reach ) as $k ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$chain[ $k ] = true;
			}
		}
		$methods = array();
		foreach ( array_keys( $this->reach ) as $k ) {
			$methods[ $k ] = $this->methods[ $k ];
		}
		return array(
			'methods'  => $methods,
			'events'   => $this->events,
			'toks'     => $this->toks,
			'reach'    => $this->reach,
			'chain'    => $chain,
			'edges'    => $this->edges,
			'bounds'   => $this->bounds,
			'cuts'     => $this->cuts,
			'sccs'     => $this->sccs(),
			'problems' => $this->problems,
		);
	}

	/**
	 * The recursions: the strongly connected parts of the edges (Tarjan) with
	 * more than one function or an edge to itself.
	 *
	 * @return list<list<string>>
	 */
	private function sccs(): array {
		$index = 0;
		$stack = array();
		$on    = array();
		$idx   = array();
		$low   = array();
		$out   = array();
		$edges = $this->edges;
		$reach = $this->reach;
		$sc    = static function ( string $v ) use ( &$sc, &$index, &$stack, &$on, &$idx, &$low, &$out, $edges, $reach ): void {
			$idx[ $v ] = $index;
			$low[ $v ] = $index;
			++$index;
			$stack[]  = $v;
			$on[ $v ] = true;
			foreach ( array_keys( $edges[ $v ] ) as $w ) {
				if ( ! isset( $reach[ $w ] ) ) {
					continue;
				}
				if ( ! isset( $idx[ $w ] ) ) {
					$sc( $w );
					$low[ $v ] = min( $low[ $v ], $low[ $w ] );
				} elseif ( isset( $on[ $w ] ) && true === $on[ $w ] ) {
					$low[ $v ] = min( $low[ $v ], $idx[ $w ] );
				}
			}
			if ( $low[ $v ] === $idx[ $v ] ) {
				$comp = array();
				do {
					$w        = array_pop( $stack );
					$on[ $w ] = false;
					$comp[]   = $w;
				} while ( $w !== $v );
				$out[] = $comp;
			}
		};
		foreach ( array_keys( $reach ) as $k ) {
			if ( ! isset( $idx[ $k ] ) ) {
				$sc( $k );
			}
		}
		$rec = array();
		foreach ( $out as $comp ) {
			if ( count( $comp ) > 1 || isset( $edges[ $comp[0] ][ $comp[0] ] ) ) {
				sort( $comp );
				$rec[] = $comp;
			}
		}
		return $rec;
	}

	/* ---------- 4. What the guard counts ---------- */

	/**
	 * The basis of the guard as the reader finds it, in the form of the lists
	 * of tests/ReadBudgetSoll.php: G1 the functions outside the chain (file::
	 * Class::name => role), CHAIN the functions of the chain, BOUNDS and CUTS
	 * the boundaries met ('from|to' => sites), G2 the steps, G3 the loops,
	 * G4 the recursions, G5 the entries into the chain, G6 the dynamic calls,
	 * G7 the checks, each of a function of G1 in the order of its source.
	 *
	 * @param array<string,mixed> $model What read() returned.
	 * @param array<string,mixed> $hand  The hand tables.
	 * @return array<string,mixed>
	 */
	public static function basis( array $model, array $hand ): array {
		$out = array(
			'G1'     => array(),
			'CHAIN'  => array(),
			'BOUNDS' => array(),
			'CUTS'   => array(),
			'G2'     => array(),
			'G3'     => array(),
			'G4'     => array(),
			'G5'     => array(),
			'G6'     => array(),
			'G7'     => array(),
		);
		$chain = $model['chain'];
		foreach ( $model['methods'] as $k => $m ) {
			$id = $m['file'] . '::' . $k;
			if ( isset( $chain[ $k ] ) ) {
				$out['CHAIN'][ $id ] = 'Kette';
				continue;
			}
			$role = 'Weg';
			if ( isset( $hand['FRAME'][ $k ] ) ) {
				$role = 'Rahmen';
			} elseif ( isset( $hand['HELPERS'][ $k ] ) ) {
				$role = 'Fristhelfer';
			}
			$out['G1'][ $id ] = $role;
		}
		foreach ( array( 'bounds' => 'BOUNDS', 'cuts' => 'CUTS' ) as $from => $to ) {
			foreach ( $model[ $from ] as $edge => $lines ) {
				$out[ $to ][ str_replace( '|', ' -> ', $edge ) ] = count( $lines );
			}
		}
		foreach ( $model['methods'] as $k => $m ) {
			if ( isset( $chain[ $k ] ) ) {
				continue;
			}
			foreach ( $model['events'][ $k ] as $e ) {
				if ( isset( $e['step'] ) ) {
					$out['G2'][ $k ][] = $e['what'];
				}
				if ( 'LOOP' === $e['k'] && ! isset( $hand['NO_CAND'][ $k ] ) ) {
					$out['G3'][ $k ][] = $e['head'];
				}
				if ( 'CALL' === $e['k'] ) {
					foreach ( $e['to'] as $to ) {
						if ( isset( $chain[ $to ] ) ) {
							$out['G5'][ $k ][] = self::entry( $e, $to );
						}
					}
				}
				$dyn = self::dynamic_label( $e, $model['edges'][ $k ] );
				if ( null !== $dyn ) {
					$out['G6'][ $k ][] = $dyn;
				}
				if ( in_array( $e['k'], array( 'CHECK', 'STOP' ), true ) && ! isset( $out['G7'][ $e['what'] ] ) ) {
					$out['G7'][ $e['what'] ] = $e['k'];
				}
				if ( 'STEP' === $e['k'] ) {
					$out['G7'][ $hand['STEP_CLASS'] . '::' . $e['helper_method'] ] = 'CHECK';
				}
			}
		}
		foreach ( $model['sccs'] as $comp ) {
			if ( isset( $chain[ $comp[0] ] ) ) {
				continue;
			}
			$edges = array();
			foreach ( $comp as $v ) {
				foreach ( $model['events'][ $v ] as $e ) {
					if ( 'CALL' !== $e['k'] ) {
						continue;
					}
					foreach ( $e['to'] as $to ) {
						if ( in_array( $to, $comp, true ) && isset( $model['edges'][ $v ][ $to ] ) ) {
							$edges[] = $v . ' -> ' . $to;
						}
					}
				}
			}
			$out['G4'][ implode( ' + ', $comp ) ] = $edges;
		}
		foreach ( array( 'G1', 'CHAIN', 'BOUNDS', 'CUTS', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7' ) as $g ) {
			ksort( $out[ $g ] );
		}
		return $out;
	}

	/**
	 * An entry into the chain: its target and how it is called.
	 *
	 * @param array<string,mixed> $e Event.
	 */
	private static function entry( array $e, string $to ): string {
		$how = $e['via'];
		if ( 0 !== $e['cl'] ) {
			$how .= ' in einer Closure';
		}
		return $to . ' (' . $how . ')';
	}

	/**
	 * A dynamic call as G6 lists it: its form, the kind of its target (Plugin,
	 * nativ, Closure, Uhr, Naht, Instanz) and the target; null for every other
	 * event. A call of an instance counts where the reading follows its edge
	 * (as ROH 1c: the frame follows only the edges FRAME names).
	 *
	 * @param array<string,mixed>            $e     Event.
	 * @param array<string,list<int>>        $edges The edges followed from its function.
	 */
	private static function dynamic_label( array $e, array $edges ): ?string {
		if ( isset( $e['form'] ) && isset( $e['dyn'] ) ) {
			$to = array();
			if ( 'CALL' === $e['k'] ) {
				$to = $e['to'];
			}
			if ( isset( $e['dyn']['fn'] ) ) {
				$to[] = $e['dyn']['fn'];
			}
			$targets = 'ohne Ziel';
			if ( array() !== $to ) {
				$targets = implode( ', ', $to );
			}
			return $e['form'] . ' -> ' . $e['dyn']['kind'] . ': ' . $targets;
		}
		if ( 'CALL' === $e['k'] && isset( $e['via'] ) && '$this->' !== $e['via'] && false !== strpos( $e['via'], '->' ) ) {
			$followed = false;
			foreach ( $e['to'] as $to ) {
				if ( isset( $edges[ $to ] ) ) {
					$followed = true;
				}
			}
			if ( ! $followed ) {
				return null;
			}
			$kind = 'Naht';
			foreach ( $e['to'] as $to ) {
				if ( 1 !== preg_match( '/_Calls::/', $to ) ) {
					$kind = 'Instanz';
				}
			}
			return $e['via'] . ' -> ' . $kind . ': ' . implode( ', ', $e['to'] );
		}
		if ( 'CB' === $e['k'] && ! in_array( $e['what'], array( 'call_user_func', 'call_user_func_array' ), true ) ) {
			if ( null === $e['cb'] ) {
				return $e['what'] . ' -> ohne Rückruf';
			}
			return $e['what'] . ' -> ' . self::callback( array( $e['cb'] ) ); // A helper alike (part 2.3b, Codex B1).
		}
		return null;
	}

	/**
	 * What a function that calls one it is given gets: a closure, a function
	 * of PHP by its name, or a callback of another form.
	 *
	 * @param list<string> $args Its arguments.
	 */
	private static function callback( array $args ): string {
		foreach ( $args as $a ) {
			if ( 1 === preg_match( '/^(static\s+)?(function|fn)\b/', $a ) ) {
				return 'Closure';
			}
			if ( 1 === preg_match( "/^'\\\\?([a-z_][a-z0-9_]*)'$/i", $a, $mm ) ) {
				return 'nativ: ' . strtolower( $mm[1] );
			}
		}
		return 'anders: ' . implode( ', ', $args );
	}

	/* ---------- Tokens ---------- */

	private static function opens( \PhpToken $t ): bool {
		return '{' === $t->text || T_CURLY_OPEN === $t->id || T_DOLLAR_OPEN_CURLY_BRACES === $t->id;
	}

	/** @param list<\PhpToken> $t Tokens. */
	private static function next( array $t, ?int $i ): ?int {
		if ( null === $i ) {
			return null;
		}
		$n = count( $t );
		for ( $j = $i + 1; $j < $n; $j++ ) {
			if ( ! in_array( $t[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	/** @param list<\PhpToken> $t Tokens. */
	private static function prev( array $t, ?int $i ): ?int {
		if ( null === $i ) {
			return null;
		}
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! in_array( $t[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	/**
	 * The text of a token, '' where there is none.
	 *
	 * @param list<\PhpToken> $t Tokens.
	 */
	private static function text( array $t, ?int $i ): string {
		if ( null === $i || ! isset( $t[ $i ] ) ) {
			return '';
		}
		return $t[ $i ]->text;
	}

	/**
	 * The id of a token, 0 where there is none.
	 *
	 * @param list<\PhpToken> $t Tokens.
	 */
	private static function id( array $t, ?int $i ): int {
		if ( null === $i || ! isset( $t[ $i ] ) ) {
			return 0;
		}
		return $t[ $i ]->id;
	}

	/** @param list<\PhpToken> $t Tokens. */
	private static function brace( array $t, ?int $b ): int {
		$n = count( $t );
		if ( null === $b ) {
			return $n - 1;
		}
		$d = 0;
		for ( $k = $b; $k < $n; $k++ ) {
			if ( self::opens( $t[ $k ] ) ) {
				++$d;
			}
			if ( '}' === $t[ $k ]->text ) {
				--$d;
				if ( 0 === $d ) {
					return $k;
				}
			}
		}
		return $n - 1;
	}

	/** @param list<\PhpToken> $t Tokens. */
	private static function paren( array $t, ?int $b ): int {
		$n = count( $t );
		if ( null === $b ) {
			return $n - 1;
		}
		$open  = $t[ $b ]->text;
		$close = ']';
		if ( '(' === $open ) {
			$close = ')';
		}
		$d = 0;
		for ( $k = $b; $k < $n; $k++ ) {
			if ( $t[ $k ]->text === $open ) {
				++$d;
			}
			if ( $t[ $k ]->text === $close ) {
				--$d;
				if ( 0 === $d ) {
					return $k;
				}
			}
		}
		return $n - 1;
	}

	/** @param list<\PhpToken> $t Tokens. */
	private static function flat( array $t, ?int $a, ?int $b ): string {
		if ( null === $a || null === $b ) {
			return '';
		}
		$s = '';
		for ( $k = $a; $k <= $b; $k++ ) {
			if ( in_array( $t[ $k ]->id, array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$s .= ' ';
				continue;
			}
			$s .= $t[ $k ]->text;
		}
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * The arguments of a call (its parenthesis at $po to $pe), at the top level.
	 *
	 * @param list<\PhpToken> $t Tokens.
	 * @return list<string>
	 */
	private static function args( array $t, int $po, int $pe ): array {
		$args = array();
		$cur  = '';
		$d    = 0;
		for ( $k = $po + 1; $k < $pe; $k++ ) {
			$x = $t[ $k ]->text;
			if ( in_array( $x, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $t[ $k ]->id || T_DOLLAR_OPEN_CURLY_BRACES === $t[ $k ]->id ) {
				++$d;
			}
			if ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				--$d;
			}
			if ( 0 === $d && ',' === $x ) {
				$args[] = trim( preg_replace( '/\s+/', ' ', $cur ) );
				$cur    = '';
				continue;
			}
			if ( in_array( $t[ $k ]->id, array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$cur .= ' ';
				continue;
			}
			$cur .= $x;
		}
		if ( '' !== trim( $cur ) ) {
			$args[] = trim( preg_replace( '/\s+/', ' ', $cur ) );
		}
		return $args;
	}

	/**
	 * The first and last token of each argument of a call at the top level
	 * (its parenthesis at $po to $pe), as args() splits them.
	 *
	 * @param list<\PhpToken> $t Tokens.
	 * @return list<array{0:int,1:int}>
	 */
	private static function spans( array $t, int $po, int $pe ): array {
		$out  = array();
		$from = null;
		$last = null;
		$d    = 0;
		for ( $k = $po + 1; $k < $pe; $k++ ) {
			$x = $t[ $k ]->text;
			if ( in_array( $x, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $t[ $k ]->id || T_DOLLAR_OPEN_CURLY_BRACES === $t[ $k ]->id ) {
				++$d;
			}
			if ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				--$d;
			}
			if ( 0 === $d && ',' === $x ) {
				if ( null !== $from ) {
					$out[] = array( $from, $last );
				}
				$from = null;
				continue;
			}
			if ( in_array( $t[ $k ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( null === $from ) {
				$from = $k;
			}
			$last = $k;
		}
		if ( null !== $from ) {
			$out[] = array( $from, $last );
		}
		return $out;
	}

	/**
	 * The smallest closure around a position: its first token and its line, or 0.
	 *
	 * @param list<array{0:int,1:int,2:int}> $regions The closures.
	 * @return array{0:int,1:int}
	 */
	private static function closure_of( array $regions, int $pos ): array {
		$best = 0;
		$bl   = 0;
		$size = PHP_INT_MAX;
		foreach ( $regions as $r ) {
			if ( $pos > $r[0] && $pos <= $r[1] && $r[1] - $r[0] < $size ) {
				$best = $r[0];
				$bl   = $r[2];
				$size = $r[1] - $r[0];
			}
		}
		return array( $best, $bl );
	}
}
