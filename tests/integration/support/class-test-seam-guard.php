<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C): no shipped code
 * opens a seam through a filter, an option or anything but the one real
 * calls class. Read from the tokens of the files, nothing is loaded.
 * SeamGuardTest runs it over the shipped tree and over fixtures, one per
 * rule.
 *
 * A seam is a property whose doc comment carries @ab-mcp-seam. For each:
 * 1. declared private static, without a type, with no default or null;
 * 2. written only inside its own class (self::, static:: or its own name),
 *    and only as `= new <Name>()` or `??= new <Name>()` with a literal
 *    class name and no arguments, or `= null`; every new names the same
 *    class. So nothing chooses the object: no filter, no option, no
 *    constant, no variable;
 * 3. never written through a variable class (`$x::$calls = …`);
 * and in the whole tree:
 * 4. no ReflectionProperty, setStaticPropertyValue(), Closure::bind() or
 *    bindTo(): the ways around private, which only the tests may take.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Seam_Guard
 */
final class AB_MCP_Test_Seam_Guard {

	/** The tag of a seam property. */
	const TAG = '@ab-mcp-seam';

	/** Names that reach past private. */
	const AROUND = array( 'ReflectionProperty', 'setStaticPropertyValue', 'bindTo' );

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
					$bare = ltrim( $t[1], '\\' );
					if ( in_array( $bare, self::AROUND, true ) || ( 'bind' === $bare && self::before( $tokens, $i, 2 ) === 'Closure' ) ) {
						$out[] = $file . ':' . $t[2] . ": $bare reaches past private; only the tests may do that.";
					}
				}
				if ( ! is_array( $t ) || T_DOUBLE_COLON !== $t[0] ) {
					continue;
				}
				$next = self::next( $tokens, $i );
				if ( null === $next || ! is_array( $tokens[ $next ] ) || T_VARIABLE !== $tokens[ $next ][0] ) {
					continue;
				}
				$property = substr( $tokens[ $next ][1], 1 );
				$op       = self::next( $tokens, $next );
				if ( ! isset( $by[ $property ] ) || null === $op || ! ( '=' === $tokens[ $op ] || ( is_array( $tokens[ $op ] ) && T_COALESCE_EQUAL === $tokens[ $op ][0] ) ) ) {
					continue;
				}
				$left  = self::prev( $tokens, $i );
				$owner = null === $left ? '' : ( is_array( $tokens[ $left ] ) ? ltrim( $tokens[ $left ][1], '\\' ) : $tokens[ $left ] );
				$line  = $file . ':' . $t[2];
				if ( null !== $left && is_array( $tokens[ $left ] ) && T_VARIABLE === $tokens[ $left ][0] ) {
					$out[] = "$line: a seam property \$$property is written through a variable class ($owner::\$$property).";
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
					$out[] = "$line: the seam $name is written outside its class.";
					continue;
				}
				$rhs = self::rhs( $tokens, $op );
				if ( array( 'null', ';' ) === $rhs ) {
					continue;
				}
				if ( 5 === count( $rhs ) && 'new' === strtolower( $rhs[0] ) && preg_match( '/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $rhs[1] ) && array( '(', ')', ';' ) === array_slice( $rhs, 2 ) ) {
					$made[ $name ][ ltrim( $rhs[1], '\\' ) ] = $line;
					continue;
				}
				$out[] = "$line: the seam $name is set to something other than new <Class>() or null (" . implode( ' ', $rhs ) . '): nothing may choose its object.';
			}
		}
		foreach ( $made as $name => $classes ) {
			if ( count( $classes ) > 1 ) {
				$out[] = "The seam $name is set to more than one class: " . implode( ', ', array_keys( $classes ) ) . '.';
			}
		}
		return $out;
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
		$eq = self::next( $tokens, $j );
		if ( null === $eq || '=' !== $tokens[ $eq ] ) {
			return 'none';
		}
		$rhs = self::rhs( $tokens, $eq );
		return array( 'null', ';' ) === $rhs ? 'null' : 'other';
	}

	/**
	 * The tokens after an assignment up to and with the ;, as text, without
	 * whitespace and comments; null in any case is 'null'.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $op     Index of = or ??=.
	 * @return string[]
	 */
	private static function rhs( array $tokens, $op ) {
		$out   = array();
		$count = count( $tokens );
		for ( $k = $op + 1; $k < $count; $k++ ) {
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
