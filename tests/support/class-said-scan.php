<?php
/**
 * The static scan of SyncSaidScanTest (branch 13 text/z8, Codex round 5):
 * every English text the code of the takeover writes under a key of
 * AB_MCP_Sync_Said::PROSE, so that the test can ask AB_MCP_Sync_Said to
 * know it.
 *
 * It reads the tokens of each file and looks at each sink: an entry of an
 * array ('reason' => …), an assignment ($x['note'] = …, .= for a piece
 * added to the text), an argument of a helper that puts its text into such
 * a key (HELPERS of the test) and a return of a function whose value goes
 * there (RETURNS). Of the expression of a sink it takes every text it can
 * give: literals, both sides of ?: and ??, concatenations (a value it cannot
 * read becomes %s, so the text is a form; what implode() joins is named as
 * unseen), the format of sprintf() and the literal values given to it, the
 * values of a constant table it names (self::WORDS[ … ]), a local variable
 * by its assignments in the function and what a closure in one returns. A
 * text in a gettext call or passed through AB_MCP_Sync_Said is said
 * already; a value read under a key of prose was written at a sink the scan
 * sees; a text without a space is a code, unless it is a one-word sentence
 * (Uncompared.). A piece added with .= must be a whole sentence after the
 * one before (fragments). What it cannot follow it reports as unseen, by
 * file and expression (Codex round 6, C1).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

final class SaidScan {

	const KEYS    = array( 'reason', 'why', 'words', 'note' );
	const GETTEXT = array( '__', '_x', '_n', '_nx', 'esc_html__', 'esc_attr__', 'esc_html_x', 'esc_attr_x', '_n_noop' );
	const PH      = "\x01";
	const SKIP    = "\x02";
	const UNSEEN  = "\x04";

	/** @var array file => function => arg index */
	public $helpers = array();
	/** @var array file => function => tuple index|null */
	public $returns = array();

	private $files  = array(); // name => tokens
	private $consts = array(); // class => name => [file, a, b]
	private $funcs  = array(); // file => list of [name, a, b]
	private $classes = array(); // file => class
	private $match  = array();
	/** @var array<int,array{0:string,1:int,2:string,3:bool}> file, line, text, whether a piece */
	public $found   = array();
	/** @var array<int,array{0:string,1:int,2:string}> file, line, expression */
	public $unseen  = array();
	/** @var array<int,array{0:string,1:int,2:string}> file, line, a piece added with .= that is no whole sentence */
	public $fragments = array();
	private $guard  = array();

	public function add( $name, $source ) {
		$raw = token_get_all( $source );
		$t   = array();
		$line = 1;
		foreach ( $raw as $tok ) {
			if ( is_array( $tok ) ) {
				$line = $tok[2];
				if ( in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ), true ) ) {
					continue;
				}
				$t[] = array( $tok[0], $tok[1], $tok[2] );
			} else {
				$t[] = array( $tok, $tok, $line );
			}
		}
		$this->files[ $name ] = $t;
		$this->index( $name );
	}

	private function index( $file ) {
		$t     = $this->files[ $file ];
		$stack = array();
		$m     = array();
		foreach ( $t as $i => $tok ) {
			if ( in_array( $tok[0], array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$stack[] = $i;
			} elseif ( in_array( $tok[0], array( ')', ']', '}' ), true ) ) {
				$o       = array_pop( $stack );
				$m[ $o ] = $i;
				$m[ $i ] = $o;
			}
		}
		$this->match[ $file ] = $m;
		$class                = null;
		$this->funcs[ $file ] = array();
		foreach ( $t as $i => $tok ) {
			if ( T_CLASS === $tok[0] && T_STRING === ( $t[ $i + 1 ][0] ?? null ) ) {
				$class                    = $t[ $i + 1 ][1];
				$this->classes[ $file ][ $i ] = $class;
			}
			if ( T_CONST === $tok[0] && null !== $class ) {
				$name = $t[ $i + 1 ][1];
				$a    = $i + 3;
				$b    = $this->until( $file, $a, array( ';' ) );
				$this->consts[ $class ][ $name ] = array( $file, $a, $b );
			}
			if ( T_FUNCTION === $tok[0] ) {
				$j = $i + 1;
				if ( '&' === $t[ $j ][0] ) {
					++$j;
				}
				$name = T_STRING === $t[ $j ][0] ? $t[ $j ][1] : null;
				$p    = '(' === $t[ $j ][0] ? $j : $j + 1;
				$k    = $m[ $p ] + 1;
				while ( isset( $t[ $k ] ) && '{' !== $t[ $k ][0] && ';' !== $t[ $k ][0] ) {
					$k = '(' === $t[ $k ][0] ? $m[ $k ] + 1 : $k + 1;
				}
				if ( '{' === $t[ $k ][0] ) {
					$this->funcs[ $file ][] = array( $name, $k, $m[ $k ], $i );
				}
			}
		}
	}

	/** The end of an expression: the first of $ends at depth 0. */
	private function until( $file, $a, array $ends ) {
		$t = $this->files[ $file ];
		$m = $this->match[ $file ];
		for ( $i = $a; isset( $t[ $i ] ); $i++ ) {
			$x = $t[ $i ][0];
			if ( in_array( $x, $ends, true ) ) {
				return $i;
			}
			if ( in_array( $x, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$i = $m[ $i ];
			} elseif ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				return $i;
			}
		}
		return $i;
	}

	private function func_at( $file, $i ) {
		$best = null;
		foreach ( $this->funcs[ $file ] as $f ) {
			if ( null !== $f[0] && $f[1] < $i && $i < $f[2] ) {
				$best = $f;
			}
		}
		return $best;
	}

	/**
	 * Whether a file has a function of this name.
	 *
	 * @param string $file File.
	 * @param string $name Name.
	 * @return bool
	 */
	public function has_function( $file, $name ) {
		foreach ( $this->funcs[ $file ] ?? array() as $f ) {
			if ( $name === $f[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The messages of the errors a function makes (new WP_Error( code,
	 * 'message' )), where it gives them as literals.
	 *
	 * @param string $file File.
	 * @param string $name Function.
	 * @return string[]
	 */
	public function errors( $file, $name ) {
		$t   = $this->files[ $file ];
		$out = array();
		foreach ( $this->funcs[ $file ] as $f ) {
			if ( $name !== $f[0] ) {
				continue;
			}
			for ( $i = $f[1]; $i < $f[2]; $i++ ) {
				if ( T_NEW === $t[ $i ][0] && 'WP_Error' === ltrim( $t[ $i + 1 ][1], '\\' ) ) {
					$args = $this->args( $file, $i + 2 );
					if ( isset( $args[1] ) && $args[1][0] + 1 === $args[1][1] && T_CONSTANT_ENCAPSED_STRING === $t[ $args[1][0] ][0] ) {
						$out[] = self::unquote( $t[ $args[1][0] ][1] );
					}
				}
			}
		}
		return $out;
	}

	public function run() {
		foreach ( $this->files as $file => $t ) {
			foreach ( $t as $i => $tok ) {
				if ( T_CONSTANT_ENCAPSED_STRING !== $tok[0] || ! in_array( self::unquote( $tok[1] ), self::KEYS, true ) ) {
					continue;
				}
				$next = $t[ $i + 1 ][0] ?? null;
				if ( T_DOUBLE_ARROW === $next ) {
					$this->sink( $file, $i + 2, $this->until( $file, $i + 2, array( ',', ';' ) ), false );
				} elseif ( ']' === $next && '[' === $t[ $i - 1 ][0] ) {
					$op = $t[ $i + 2 ][0] ?? null;
					if ( '=' === $op || T_CONCAT_EQUAL === $op || T_COALESCE_EQUAL === $op ) {
						$this->sink( $file, $i + 3, $this->until( $file, $i + 3, array( ';' ) ), T_CONCAT_EQUAL === $op );
					}
				}
			}
			foreach ( $this->helpers[ $file ] ?? array() as $fn => $arg ) {
				foreach ( $t as $i => $tok ) {
					if ( T_STRING === $tok[0] && $fn === $tok[1] && T_DOUBLE_COLON === $t[ $i - 1 ][0] && '(' === $t[ $i + 1 ][0] ) {
						$args = $this->args( $file, $i + 1 );
						if ( isset( $args[ $arg ] ) ) {
							$this->sink( $file, $args[ $arg ][0], $args[ $arg ][1], false );
						}
					}
				}
			}
			foreach ( $this->returns[ $file ] ?? array() as $fn => $at ) {
				foreach ( $this->funcs[ $file ] as $f ) {
					if ( $fn === $f[0] ) {
						$this->returns_of( $file, $f[1], $f[2], $at, true );
					}
				}
			}
		}
	}

	private function returns_of( $file, $a, $b, $at, $sink ) {
		$t   = $this->files[ $file ];
		$out = array();
		for ( $i = $a + 1; $i < $b; $i++ ) {
			if ( T_FUNCTION === $t[ $i ][0] || T_FN === $t[ $i ][0] ) {
				// A closure inside: its returns are its own.
				$k = $i;
				while ( '{' !== $t[ $k ][0] && ';' !== $t[ $k ][0] ) {
					$k = '(' === $t[ $k ][0] ? $this->match[ $file ][ $k ] + 1 : $k + 1;
				}
				if ( '{' === $t[ $k ][0] ) {
					$i = $this->match[ $file ][ $k ];
				}
				continue;
			}
			if ( T_RETURN !== $t[ $i ][0] ) {
				continue;
			}
			$e = $this->until( $file, $i + 1, array( ';' ) );
			$x = $i + 1;
			$y = $e;
			if ( null !== $at ) {
				if ( ! ( T_ARRAY === $t[ $x ][0] || '[' === $t[ $x ][0] ) ) {
					continue;
				}
				$open = T_ARRAY === $t[ $x ][0] ? $x + 1 : $x;
				$args = $this->args( $file, $open );
				if ( ! isset( $args[ $at ] ) ) {
					continue;
				}
				list( $x, $y ) = $args[ $at ];
			}
			if ( $sink ) {
				$this->sink( $file, $x, $y, false );
			} else {
				$out = array_merge( $out, $this->expand( $file, $x, $y ) );
			}
			$i = $e;
		}
		return $out;
	}

	/** The arguments of a call or array whose ( or [ is at $open: [a, b) each. */
	private function args( $file, $open ) {
		$t   = $this->files[ $file ];
		$end = $this->match[ $file ][ $open ];
		$out = array();
		$a   = $open + 1;
		while ( $a < $end ) {
			$b     = $this->until( $file, $a, array( ',' ) );
			$b     = min( $b, $end );
			$out[] = array( $a, $b );
			$a     = $b + 1;
		}
		return $out;
	}

	private function sink( $file, $a, $b, $piece ) {
		$line = $this->files[ $file ][ $a ][2] ?? 0;
		foreach ( $this->expand( $file, $a, $b ) as $v ) {
			$this->record( $file, $line, $v, $piece, $a, $b );
		}
	}

	private function record( $file, $line, $v, $piece, $a, $b ) {
		if ( false !== strpos( $v, self::SKIP ) ) {
			return;
		}
		if ( false !== strpos( $v, self::UNSEEN ) && '' === trim( str_replace( array( self::UNSEEN, self::PH ), '', $v ) ) ) {
			$this->unseen[] = array( $file, $line, $this->text( $file, $a, $b ) );
			return;
		}
		$v = str_replace( array( self::UNSEEN, self::PH ), '%s', $v );
		if ( ! self::prose( $v ) ) {
			return;
		}
		if ( $piece ) {
			// A piece added to a text is a whole sentence after the one before
			// (AB_MCP_Sync_Said says a text sentence by sentence).
			if ( 1 !== preg_match( '/\A \S.*[.!?]\z/s', $v ) ) {
				$this->fragments[] = array( $file, $line, $v );
			}
			$v = trim( $v );
		}
		$this->found[] = array( $file, $line, $v, $piece );
	}

	/**
	 * Whether a text is prose, not a code: words with a space between them,
	 * or one word that is a sentence (Uncompared.).
	 *
	 * @param string $v Text.
	 * @return bool
	 */
	public static function prose( $v ) {
		return 1 === preg_match( '/[A-Za-z]+ [A-Za-z%]/', $v ) || 1 === preg_match( '/\A\s*[A-Z][A-Za-z-]*[.!?:]\s*\z/', $v );
	}

	/** The literal values given to a form (sprintf), each a text said on its own. */
	private function values( $file, $args ) {
		foreach ( array_slice( $args, 1 ) as $arg ) {
			$line = $this->files[ $file ][ $arg[0] ][2] ?? 0;
			foreach ( $this->expand( $file, $arg[0], $arg[1] ) as $v ) {
				if ( '' === $v || 1 === preg_match( '/[\x01\x02\x04]/', $v ) || ! self::prose( $v ) ) {
					continue;
				}
				$this->found[] = array( $file, $line, $v, false );
			}
		}
	}

	public function text( $file, $a, $b ) {
		$s = '';
		for ( $i = $a; $i < $b; $i++ ) {
			$s .= $this->files[ $file ][ $i ][1] . ' ';
		}
		return trim( $s );
	}

	/** The texts an expression can give, PH for a value, SKIP where said. */
	public function expand( $file, $a, $b ) {
		$t = $this->files[ $file ];
		$m = $this->match[ $file ];
		while ( $a < $b && in_array( $t[ $a ][0], array( T_STRING_CAST, '@' ), true ) ) {
			++$a;
		}
		if ( $a >= $b ) {
			return array();
		}
		if ( '(' === $t[ $a ][0] && $m[ $a ] === $b - 1 ) {
			return $this->expand( $file, $a + 1, $b - 1 );
		}
		// Ternary and ??, at depth 0.
		for ( $i = $a; $i < $b; $i++ ) {
			$x = $t[ $i ][0];
			if ( in_array( $x, array( '(', '[', '{' ), true ) ) {
				$i = $m[ $i ];
				continue;
			}
			if ( '?' === $x ) {
				$depth = 0;
				for ( $k = $i + 1; $k < $b; $k++ ) {
					$y = $t[ $k ][0];
					if ( in_array( $y, array( '(', '[', '{' ), true ) ) {
						$k = $m[ $k ];
					} elseif ( '?' === $y ) {
						++$depth;
					} elseif ( ':' === $y ) {
						if ( 0 === $depth ) {
							break;
						}
						--$depth;
					}
				}
				$then = $k === $i + 1 ? $this->expand( $file, $a, $i ) : $this->expand( $file, $i + 1, $k );
				return array_merge( $then, $this->expand( $file, $k + 1, $b ) );
			}
		}
		for ( $i = $a; $i < $b; $i++ ) {
			if ( in_array( $t[ $i ][0], array( '(', '[', '{' ), true ) ) {
				$i = $m[ $i ];
				continue;
			}
			if ( T_COALESCE === $t[ $i ][0] ) {
				return array_merge( $this->expand( $file, $a, $i ), $this->expand( $file, $i + 1, $b ) );
			}
		}
		// Concatenation.
		$terms = array();
		$s     = $a;
		for ( $i = $a; $i <= $b; $i++ ) {
			if ( $i === $b || '.' === $t[ $i ][0] ) {
				$terms[] = array( $s, $i );
				$s       = $i + 1;
				continue;
			}
			if ( in_array( $t[ $i ][0], array( '(', '[', '{' ), true ) ) {
				$i = $m[ $i ];
			}
		}
		$out = array( '' );
		foreach ( $terms as $term ) {
			$vs = $this->term( $file, $term[0], $term[1], 1 === count( $terms ) );
			if ( array() === $vs ) {
				if ( 1 === count( $terms ) ) {
					return array();
				}
				$vs = array( self::PH );
			}
			$next = array();
			foreach ( $out as $o ) {
				foreach ( $vs as $v ) {
					$next[] = $o . $v;
				}
			}
			$out = array_slice( array_values( array_unique( $next ) ), 0, 256 );
		}
		return $out;
	}

	private function term( $file, $a, $b, $alone ) {
		$t = $this->files[ $file ];
		$m = $this->match[ $file ];
		while ( $a < $b && in_array( $t[ $a ][0], array( T_STRING_CAST, '@' ), true ) ) {
			++$a;
		}
		$x = $t[ $a ];
		if ( T_CONSTANT_ENCAPSED_STRING === $x[0] && $a + 1 === $b ) {
			return array( self::unquote( $x[1] ) );
		}
		if ( '(' === $x[0] && $m[ $a ] === $b - 1 ) {
			return $this->expand( $file, $a + 1, $b - 1 );
		}
		if ( T_LNUMBER === $x[0] || ( T_STRING === $x[0] && in_array( strtolower( $x[1] ), array( 'null', 'true', 'false' ), true ) && $a + 1 === $b ) ) {
			return array();
		}
		if ( T_STRING === $x[0] && '(' === ( $t[ $a + 1 ][0] ?? null ) ) {
			if ( in_array( $x[1], self::GETTEXT, true ) ) {
				return array( self::SKIP );
			}
			if ( 'sprintf' === $x[1] ) {
				$args = $this->args( $file, $a + 1 );
				$this->values( $file, $args );
				return $this->expand( $file, $args[0][0], $args[0][1] );
			}
			if ( 'implode' === $x[1] && ! $alone ) {
				// What it joins is a value of the text, not followed: named.
				$this->unseen[] = array( $file, $x[2], $this->text( $file, $a, $b ) );
				return array( self::PH );
			}
			return $this->has_gettext( $file, $a + 1 ) ? array( self::SKIP ) : array( self::UNSEEN );
		}
		if ( T_STRING === $x[0] && T_DOUBLE_COLON === ( $t[ $a + 1 ][0] ?? null ) ) {
			$class = in_array( $x[1], array( 'self', 'static' ), true ) ? $this->class_at( $file, $a ) : $x[1];
			$name  = $t[ $a + 2 ][1];
			if ( 'AB_MCP_Sync_Said' === $class ) {
				return array( self::SKIP );
			}
			if ( '(' === ( $t[ $a + 3 ][0] ?? null ) ) {
				foreach ( $this->returns as $f => $fns ) {
					if ( array_key_exists( $name, $fns ) && in_array( $class, $this->classes[ $f ] ?? array(), true ) ) {
						return array( self::SKIP );
					}
				}
				return $this->has_gettext( $file, $a + 3 ) ? array( self::SKIP ) : array( self::UNSEEN );
			}
			$chain = $this->chain( $file, $a + 3, $b );
			if ( ! isset( $this->consts[ $class ][ $name ] ) ) {
				return array( self::UNSEEN );
			}
			if ( array() !== $chain && in_array( end( $chain ), self::KEYS, true ) ) {
				return array( self::SKIP ); // A table value, scanned where it stands.
			}
			list( $cf, $ca, $cb ) = $this->consts[ $class ][ $name ];
			return array() === $chain ? $this->expand( $cf, $ca, $cb ) : $this->leaves( $cf, $ca, $cb );
		}
		if ( T_VARIABLE === $x[0] ) {
			if ( '(' === ( $t[ $a + 1 ][0] ?? null ) ) {
				return $this->closure( $file, $a, $x[1] );
			}
			$chain = $this->chain( $file, $a + 1, $b );
			if ( array() !== $chain && in_array( end( $chain ), self::KEYS, true ) ) {
				return array( self::SKIP ); // Read under a key of prose: written at a sink that is scanned.
			}
			if ( array() === $chain && $this->helper_param( $file, $a, $x[1] ) ) {
				return array( self::SKIP ); // The argument of a helper, scanned at each call.
			}
			return $this->traced( $file, $a, $x[1], array() !== $chain );
		}
		return array( self::UNSEEN );
	}

	/** The literal keys of an index chain from $i ([…][…]), false entries for others. */
	private function chain( $file, $i, $b ) {
		$t   = $this->files[ $file ];
		$out = array();
		while ( $i < $b && '[' === $t[ $i ][0] ) {
			$e     = $this->match[ $file ][ $i ];
			$out[] = ( $e === $i + 2 && T_CONSTANT_ENCAPSED_STRING === $t[ $i + 1 ][0] ) ? self::unquote( $t[ $i + 1 ][1] ) : false;
			$i     = $e + 1;
		}
		return $out;
	}

	private function class_at( $file, $i ) {
		$found = null;
		foreach ( $this->classes[ $file ] ?? array() as $at => $class ) {
			if ( $at < $i ) {
				$found = $class;
			}
		}
		return $found;
	}

	/** Every value of an array expression (not its keys), nested arrays through. */
	private function leaves( $file, $a, $b ) {
		$t = $this->files[ $file ];
		if ( T_ARRAY === $t[ $a ][0] && '(' === $t[ $a + 1 ][0] ) {
			$open = $a + 1;
		} elseif ( '[' === $t[ $a ][0] ) {
			$open = $a;
		} else {
			return $this->expand( $file, $a, $b );
		}
		$out = array();
		foreach ( $this->args( $file, $open ) as $el ) {
			$x = $el[0];
			for ( $i = $el[0]; $i < $el[1]; $i++ ) {
				if ( in_array( $t[ $i ][0], array( '(', '[', '{' ), true ) ) {
					$i = $this->match[ $file ][ $i ];
				} elseif ( T_DOUBLE_ARROW === $t[ $i ][0] ) {
					$x = $i + 1;
					break;
				}
			}
			$out = array_merge( $out, $this->leaves( $file, $x, $el[1] ) );
		}
		return $out;
	}

	/** Whether the call whose ( is at $open holds a gettext call: said in the language of the site. */
	private function has_gettext( $file, $open ) {
		$t = $this->files[ $file ];
		$e = $this->match[ $file ][ $open ] ?? $open;
		for ( $i = $open; $i < $e; $i++ ) {
			if ( ( T_STRING === $t[ $i ][0] && in_array( $t[ $i ][1], self::GETTEXT, true ) && '(' === $t[ $i + 1 ][0] ) || ( T_STRING === $t[ $i ][0] && 'AB_MCP_Sync_Said' === $t[ $i ][1] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether $var is the parameter of the enclosing function that a helper sinks. */
	private function helper_param( $file, $at, $var ) {
		$f = $this->func_at( $file, $at );
		if ( null === $f || ! isset( $this->helpers[ $file ][ $f[0] ] ) ) {
			return false;
		}
		$t    = $this->files[ $file ];
		$open = $f[3] + 1;
		while ( '(' !== $t[ $open ][0] ) {
			++$open;
		}
		$n = 0;
		foreach ( $this->args( $file, $open ) as $p ) {
			for ( $i = $p[0]; $i < $p[1]; $i++ ) {
				if ( T_VARIABLE === $t[ $i ][0] ) {
					if ( $var === $t[ $i ][1] ) {
						return $n === $this->helpers[ $file ][ $f[0] ];
					}
					break;
				}
			}
			++$n;
		}
		return false;
	}

	/** What a local variable is given in its function. */
	private function traced( $file, $at, $var, $indexed ) {
		$f = $this->func_at( $file, $at );
		if ( null === $f ) {
			return array( self::UNSEEN );
		}
		$key = $file . $f[1] . $var . (int) $indexed;
		if ( isset( $this->guard[ $key ] ) ) {
			return $this->guard[ $key ];
		}
		$this->guard[ $key ] = array( self::UNSEEN );
		$t    = $this->files[ $file ];
		$out  = array();
		$seen = false;
		for ( $i = $f[1]; $i < $f[2]; $i++ ) {
			if ( T_VARIABLE !== $t[ $i ][0] || $var !== $t[ $i ][1] ) {
				continue;
			}
			$op = $t[ $i + 1 ][0];
			if ( '=' === $op || T_CONCAT_EQUAL === $op || T_COALESCE_EQUAL === $op ) {
				$seen = true;
				$e    = $this->until( $file, $i + 2, array( ';', ',' ) );
				if ( T_CONCAT_EQUAL === $op ) {
					// A piece added to the text: said on its own.
					$this->sink_piece( $file, $i + 2, $e );
					continue;
				}
				$vs  = $indexed && in_array( $t[ $i + 2 ][0], array( T_ARRAY, '[' ), true ) ? $this->leaves( $file, $i + 2, $e ) : $this->expand( $file, $i + 2, $e );
				$out = array_merge( $out, $vs );
			} elseif ( ( ',' === $op || ')' === $op ) && $this->in_list( $file, $i ) ) {
				$seen  = true;
				$out[] = self::SKIP; // From list(): the function is scanned by its returns.
			}
		}
		$out                 = $seen ? $out : array( self::UNSEEN );
		$this->guard[ $key ] = $out;
		return $out;
	}

	private function sink_piece( $file, $a, $b ) {
		$this->sink( $file, $a, $b, true );
	}

	private function in_list( $file, $i ) {
		$t = $this->files[ $file ];
		for ( $k = $i; $k > 0; $k-- ) {
			if ( '(' === $t[ $k ][0] || '[' === $t[ $k ][0] ) {
				$e = $this->match[ $file ][ $k ];
				return ( T_LIST === ( $t[ $k - 1 ][0] ?? null ) || '[' === $t[ $k ][0] ) && '=' === ( $t[ $e + 1 ][0] ?? null );
			}
			if ( in_array( $t[ $k ][0], array( ';', '{', '}' ), true ) ) {
				return false;
			}
		}
		return false;
	}

	/** What a closure in a local variable returns. */
	private function closure( $file, $at, $var ) {
		$f = $this->func_at( $file, $at );
		$t = $this->files[ $file ];
		for ( $i = $f[1]; $i < $f[2]; $i++ ) {
			if ( T_VARIABLE === $t[ $i ][0] && $var === $t[ $i ][1] && '=' === $t[ $i + 1 ][0] ) {
				foreach ( $this->funcs[ $file ] as $c ) {
					if ( null === $c[0] && $c[3] > $i && $c[3] < $i + 4 ) {
						return $this->returns_of( $file, $c[1], $c[2], null, false );
					}
				}
			}
		}
		return array( self::UNSEEN );
	}

	public static function unquote( $lit ) {
		if ( "'" === $lit[0] ) {
			return strtr( substr( $lit, 1, -1 ), array( "\\'" => "'", '\\\\' => '\\' ) );
		}
		return stripcslashes( substr( $lit, 1, -1 ) );
	}
}
