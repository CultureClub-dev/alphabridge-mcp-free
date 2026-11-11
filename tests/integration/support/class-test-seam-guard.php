<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C; Codex round 1,
 * A5): no shipped code reads or sets a seam through a filter, an option,
 * Reflection or anything but the one real calls class. Read from the tokens
 * of the files, nothing is loaded. SeamGuardTest runs it over the shipped
 * tree and over fixtures, one per rule and per way around.
 *
 * A list of forbidden wrappers does not close (apply_filters,
 * apply_filters_ref_array, get_option, get_site_option, get_transient, a
 * constant, a variable, a function of the plugin that asks any of them …),
 * so the rule is a positive one. A seam is a property whose doc comment
 * carries @ab-mcp-seam. For each:
 * 1. it is declared private static, without a type, with no default or null;
 * 2. it occurs in the shipped code in exactly two statements, both inside
 *    its own class and through self:: only:
 *      return self::$x ?? new <Class>();   (the accessor; one Class)
 *      self::$x = null;                     (back to the real calls)
 *    Any other occurrence (wrapped in a call, assigned from anything else,
 *    read into a variable, through static::, its class name or a variable
 *    class, outside its class) is a violation;
 * and in the whole tree:
 * 3. no dynamic static property (`::$$name`, `::${…}`), which could name a
 *    seam without its name;
 * 4. none of the ways around private, which only the tests may take:
 *    ReflectionProperty, ReflectionObject, getProperty(), getProperties(),
 *    getStaticPropertyValue(), setStaticPropertyValue(),
 *    getStaticProperties(), setValue(), setAccessible(), Closure::bind(),
 *    bindTo() and ->call(). The plugin uses none of them (ReflectionClass
 *    and ReflectionFunction for getFileName() stay allowed).
 *
 * What the guard cannot see: code that leaves the seam unused and calls the
 * outside on another way; that is no opening of the seam, and the review of
 * each branch looks for it (GRENZEN-PRUEFUMGEBUNG.md, G7).
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Seam_Guard
 */
final class AB_MCP_Test_Seam_Guard {

	/** The tag of a seam property. */
	const TAG = '@ab-mcp-seam';

	/** Names of classes and methods that reach past private. */
	const AROUND = array( 'ReflectionProperty', 'ReflectionObject', 'getProperty', 'getProperties', 'getStaticPropertyValue', 'setStaticPropertyValue', 'getStaticProperties', 'setValue', 'setAccessible', 'bindTo' );

	/** Tokens that end the statement before. */
	const STATEMENT_START = array( '{', '}', ';' );

	/**
	 * The seams of some files.
	 *
	 * @param string[] $files Paths.
	 * @return array[] { file, line, class, property, private, static, typed, default }
	 */
	public static function seams( array $files ) {
		$seams = array();
		foreach ( $files as $file ) {
			$tokens = self::tokens( $file );
			$class  = null;
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$class = self::class_at( $tokens, $i, $class );
				if ( ! is_array( $tokens[ $i ] ) || T_DOC_COMMENT !== $tokens[ $i ][0] || false === strpos( $tokens[ $i ][1], self::TAG ) ) {
					continue;
				}
				$seam = array(
					'file'     => $file,
					'line'     => $tokens[ $i ][2],
					'class'    => $class,
					'property' => null,
					'private'  => false,
					'static'   => false,
					'typed'    => false,
					'default'  => 'none',
				);
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$t = $tokens[ $j ];
					if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
						continue;
					}
					if ( is_array( $t ) && T_VARIABLE === $t[0] ) {
						$seam['property'] = substr( $t[1], 1 );
						$seam['default']  = self::default_after( $tokens, $j );
						break;
					}
					if ( is_array( $t ) && T_PRIVATE === $t[0] ) {
						$seam['private'] = true;
					} elseif ( is_array( $t ) && T_STATIC === $t[0] ) {
						$seam['static'] = true;
					} elseif ( is_array( $t ) && in_array( $t[0], array( T_PUBLIC, T_PROTECTED, T_VAR ), true ) ) {
						continue;
					} elseif ( ';' === $t || '{' === $t || ( is_array( $t ) && T_FUNCTION === $t[0] ) ) {
						break;
					} else {
						$seam['typed'] = true;
					}
				}
				$seams[] = $seam;
			}
		}
		return $seams;
	}

	/**
	 * Every rule broken in some files, as messages.
	 *
	 * @param string[] $files Paths (the whole tree that ships).
	 * @return string[]
	 */
	public static function violations( array $files ) {
		$out   = array();
		$seams = self::seams( $files );
		$by    = array();
		foreach ( $seams as $seam ) {
			$where = $seam['file'] . ':' . $seam['line'];
			if ( null === $seam['property'] || null === $seam['class'] ) {
				$out[] = "$where: the tag " . self::TAG . ' stands on no property of a class.';
				continue;
			}
			$name = $seam['class'] . '::$' . $seam['property'];
			if ( ! $seam['private'] || ! $seam['static'] ) {
				$out[] = "$where: the seam $name must be private static.";
			}
			if ( $seam['typed'] ) {
				$out[] = "$where: the seam $name must have no type.";
			}
			if ( ! in_array( $seam['default'], array( 'none', 'null' ), true ) ) {
				$out[] = "$where: the seam $name starts as something other than null.";
			}
			$by[ $seam['property'] ][] = $seam;
		}
		$made = array();
		foreach ( $files as $file ) {
			$tokens = self::tokens( $file );
			$class  = null;
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$class = self::class_at( $tokens, $i, $class );
				$t     = $tokens[ $i ];
				if ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$around = self::around( $tokens, $i );
					if ( null !== $around ) {
						$out[] = $file . ':' . $t[2] . ": $around reaches past private; only the tests may do that.";
					}
				}
				if ( ! is_array( $t ) || T_DOUBLE_COLON !== $t[0] ) {
					continue;
				}
				$next = self::next( $tokens, $i );
				if ( null === $next ) {
					continue;
				}
				if ( '$' === $tokens[ $next ] ) {
					$out[] = $file . ':' . $t[2] . ': a dynamic static property (::$$… or ::${…}) could name a seam without its name.';
					continue;
				}
				if ( ! is_array( $tokens[ $next ] ) || T_VARIABLE !== $tokens[ $next ][0] ) {
					continue;
				}
				$property = substr( $tokens[ $next ][1], 1 );
				if ( ! isset( $by[ $property ] ) ) {
					continue;
				}
				$left  = self::prev( $tokens, $i );
				$owner = null === $left ? '' : ( is_array( $tokens[ $left ] ) ? ltrim( $tokens[ $left ][1], '\\' ) : $tokens[ $left ] );
				$line  = $file . ':' . $t[2];
				if ( null !== $left && is_array( $tokens[ $left ] ) && T_VARIABLE === $tokens[ $left ][0] ) {
					$out[] = "$line: a seam property \$$property is reached through a variable class ($owner::\$$property).";
					continue;
				}
				$target = in_array( strtolower( $owner ), array( 'self', 'static' ), true ) ? $class : $owner;
				$seam   = null;
				foreach ( $by[ $property ] as $candidate ) {
					if ( strtolower( (string) $candidate['class'] ) === strtolower( (string) $target ) ) {
						$seam = $candidate;
					}
				}
				if ( null === $seam ) {
					continue;
				}
				$name = $seam['class'] . '::$' . $property;
				if ( $file !== $seam['file'] || strtolower( (string) $class ) !== strtolower( $seam['class'] ) ) {
					$out[] = "$line: the seam $name is reached outside its class.";
					continue;
				}
				$shape = 'self' === $owner ? self::shape( $tokens, $left, $next ) : null;
				if ( null === $shape ) {
					$out[] = "$line: the seam $name occurs other than as «return self::\$$property ?? new <Class>();» or «self::\$$property = null;»: " . self::statement( $tokens, $left ) . ' Nothing may read or set it on another way (a filter, an option, a variable).';
					continue;
				}
				if ( 'null' !== $shape ) {
					$made[ $name ][ $shape ] = $line;
				}
			}
		}
		foreach ( $made as $name => $classes ) {
			if ( count( $classes ) > 1 ) {
				$out[] = "The seam $name is made of more than one class: " . implode( ', ', array_keys( $classes ) ) . '.';
			}
		}
		return $out;
	}

	/**
	 * The shape of the statement of an occurrence self::$x: the class of
	 * «return self::$x ?? new <Class>();», 'null' for «self::$x = null;»,
	 * null for anything else.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $self   Index of self.
	 * @param int   $var    Index of $x.
	 * @return string|null
	 */
	private static function shape( array $tokens, $self, $var ) {
		$before = self::prev( $tokens, $self );
		$after  = self::rhs( $tokens, $var );
		if ( null !== $before && is_array( $tokens[ $before ] ) && T_RETURN === $tokens[ $before ][0] ) {
			// return always begins a statement of its own; the shape after it
			// is all there is to check (an anonymous class has the six tokens
			// as well: new class { }).
			if ( 6 === count( $after ) && '??' === $after[0] && 'new' === strtolower( $after[1] ) && preg_match( '/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $after[2] ) && array( '(', ')', ';' ) === array_slice( $after, 3 ) ) {
				return ltrim( $after[2], '\\' );
			}
			return null;
		}
		if ( null !== $before && in_array( $tokens[ $before ], self::STATEMENT_START, true ) && array( '=', 'null', ';' ) === $after ) {
			return 'null';
		}
		return null;
	}

	/**
	 * Whether a name token is a way around private; its name, or null.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index of the name.
	 * @return string|null
	 */
	private static function around( array $tokens, $i ) {
		$bare = ltrim( $tokens[ $i ][1], '\\' );
		if ( in_array( $bare, self::AROUND, true ) ) {
			return $bare;
		}
		$prev = self::prev( $tokens, $i );
		if ( 'bind' === $bare && null !== $prev && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] && 'Closure' === self::before( $tokens, $i, 2 ) ) {
			return 'Closure::bind';
		}
		$next = self::next( $tokens, $i );
		if ( 'call' === $bare && null !== $prev && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) && null !== $next && '(' === $tokens[ $next ] ) {
			return '->call';
		}
		return null;
	}

	/**
	 * The statement around a token, for a message.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index.
	 * @return string
	 */
	private static function statement( array $tokens, $i ) {
		$from = $i;
		while ( $from > 0 && ! in_array( $tokens[ $from - 1 ], self::STATEMENT_START, true ) ) {
			--$from;
		}
		$text = '';
		for ( $k = $from, $count = count( $tokens ); $k < $count; $k++ ) {
			$text .= is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			if ( ';' === $tokens[ $k ] || '{' === $tokens[ $k ] ) {
				break;
			}
		}
		return '«' . trim( (string) preg_replace( '/\s+/', ' ', $text ) ) . '»';
	}

	/**
	 * Tokens of a file.
	 *
	 * @param string $file Path.
	 * @return array
	 */
	private static function tokens( $file ) {
		$code = file_get_contents( $file );
		if ( false === $code ) {
			throw new RuntimeException( "Cannot read $file." );
		}
		return token_get_all( $code );
	}

	/**
	 * The class a token stands in (the last class keyword followed by a
	 * name; enough for one class per file and the fixtures).
	 *
	 * @param array       $tokens Tokens.
	 * @param int         $i      Index.
	 * @param string|null $class  Class so far.
	 * @return string|null
	 */
	private static function class_at( array $tokens, $i, $class ) {
		if ( is_array( $tokens[ $i ] ) && T_CLASS === $tokens[ $i ][0] ) {
			$next = self::next( $tokens, $i );
			if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
				return $tokens[ $next ][1];
			}
		}
		return $class;
	}

	/**
	 * What a property declaration starts with: 'none', 'null' or 'other'.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $j      Index of the variable.
	 * @return string
	 */
	private static function default_after( array $tokens, $j ) {
		$rhs = self::rhs( $tokens, $j );
		if ( array( ';' ) === $rhs ) {
			return 'none';
		}
		return array( '=', 'null', ';' ) === $rhs ? 'null' : 'other';
	}

	/**
	 * The tokens after a token up to and with the next ;, as text, without
	 * whitespace and comments; null in any case is 'null'.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   Index of the token before.
	 * @return string[]
	 */
	private static function rhs( array $tokens, $from ) {
		$out   = array();
		$count = count( $tokens );
		for ( $k = $from + 1; $k < $count; $k++ ) {
			$t = $tokens[ $k ];
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$text  = is_array( $t ) ? $t[1] : $t;
			$out[] = 'null' === strtolower( $text ) ? 'null' : $text;
			if ( ';' === $text ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Index of the next token that is no whitespace or comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      From.
	 * @return int|null
	 */
	private static function next( array $tokens, $i ) {
		$count = count( $tokens );
		for ( $k = $i + 1; $k < $count; $k++ ) {
			if ( ! is_array( $tokens[ $k ] ) || ! in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $k;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token that is no whitespace or comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      From.
	 * @return int|null
	 */
	private static function prev( array $tokens, $i ) {
		for ( $k = $i - 1; $k >= 0; $k-- ) {
			if ( ! is_array( $tokens[ $k ] ) || ! in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $k;
			}
		}
		return null;
	}

	/**
	 * The text of the token $back tokens before $i (no whitespace).
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      From.
	 * @param int   $back   How many back.
	 * @return string
	 */
	private static function before( array $tokens, $i, $back ) {
		$k = $i;
		for ( $n = 0; $n < $back && null !== $k; $n++ ) {
			$k = self::prev( $tokens, $k );
		}
		if ( null === $k ) {
			return '';
		}
		return ltrim( is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ], '\\' );
	}
}
