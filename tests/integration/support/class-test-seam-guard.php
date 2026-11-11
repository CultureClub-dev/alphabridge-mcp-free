<?php
/**
 * The guard of the test seams (Z8b branch 1, point 5; F-C; Codex round 1,
 * A5; Behebung 2): no shipped code reads or sets a seam on any way but its
 * two statements. Read from the bytes and tokens of the files, nothing is
 * loaded. SeamGuardTest runs it over the shipped tree and over fixtures,
 * one per rule and per way around.
 *
 * Lists of ways around do not close: after filters, options and Reflection
 * came a lower-case \reflectionproperty, a trait, a bound closure, a name in
 * a string. So the rule is one about the name. A seam is a property whose
 * name begins with PREFIX (`naht_` plus what its class is about, say
 * `naht_sync_code_swap`), a name used for nothing else in the tree. For each:
 * 1. it is declared private static, without a type, with no default or null;
 * 2. its name is one of a kind: declared once, and no seam name holds
 *    another;
 * 3. its name occurs nowhere in the bytes of the tree, in any case, in code,
 *    strings or comments, except in its declaration and in two statements
 *    inside its own class, through self:: only:
 *      return self::$naht_x ?? new <Class>();   (the accessor; one Class)
 *      self::$naht_x = null;                     (back to the real calls)
 *    So every way that needs the name — Reflection in any case, a trait, a
 *    closure bound to the class, $obj->{'…'}, self::${'…'}, a filter or an
 *    option around it — shows it where it may not be;
 * 4. the prefix itself occurs nowhere else either, so a name put together
 *    from it ('naht_' . $x, sprintf( 'naht_%s', … )) is seen too;
 * and in the whole tree:
 * 5. no Reflection that reaches properties without naming them: the
 *    methods getProperties(), getStaticProperties(), getStaticPropertyValue(),
 *    setStaticPropertyValue() and setValue(), in any case. What stays of the
 *    former list is only this: enumerating the properties of a class and
 *    writing them needs no name at all, so rules 3 and 4 cannot see it. The
 *    plugin uses none of them. ReflectionProperty, getProperty(),
 *    setAccessible(), ReflectionObject, Closure::bind(), bindTo(), ->call()
 *    and dynamic static properties went: each needs the name of the seam (or
 *    a property of the bound class written out), which rule 3 sees.
 *
 * What the guard cannot see (GRENZEN-PRUEFUMGEBUNG.md): a name put together
 * at run time without the prefix written out ('na' . 'ht_x', chr(),
 * strrev(), base64, G9), and code that leaves the seam unused (G7).
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Seam_Guard
 */
final class AB_MCP_Test_Seam_Guard {

	/** The prefix of the name of every seam. */
	const PREFIX = 'naht_';

	/** Methods that reach properties without naming them (lower case). */
	const ENUMERATE = array( 'getproperties', 'getstaticproperties', 'getstaticpropertyvalue', 'setstaticpropertyvalue', 'setvalue' );

	/** Tokens that end the statement before. */
	const STATEMENT_START = array( '{', '}', ';' );

	/** Keywords of a property declaration. */
	const MODIFIERS = array( T_PRIVATE, T_PUBLIC, T_PROTECTED, T_STATIC, T_VAR, T_READONLY );

	/**
	 * The seams of some files: every property declared with a name that
	 * begins with PREFIX.
	 *
	 * @param string[] $files Paths.
	 * @return array[] { file, line, offset, class, property, private, static, typed, default }
	 */
	public static function seams( array $files ) {
		$seams = array();
		foreach ( $files as $file ) {
			$tokens  = self::tokens( $file );
			$offsets = self::offsets( $tokens );
			$class   = null;
			$count   = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$class = self::class_at( $tokens, $i, $class );
				$t     = $tokens[ $i ];
				if ( ! is_array( $t ) || T_VARIABLE !== $t[0] || 0 !== stripos( substr( $t[1], 1 ), self::PREFIX ) ) {
					continue;
				}
				$declaration = self::declaration( $tokens, $i );
				if ( null === $declaration ) {
					continue;
				}
				$seams[] = array(
					'file'     => $file,
					'line'     => $t[2],
					'offset'   => $offsets[ $i ] + 1,
					'class'    => $class,
					'property' => substr( $t[1], 1 ),
					'private'  => in_array( T_PRIVATE, $declaration['modifiers'], true ),
					'static'   => in_array( T_STATIC, $declaration['modifiers'], true ),
					'typed'    => $declaration['typed'],
					'default'  => self::default_after( $tokens, $i ),
				);
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
		$out     = array();
		$seams   = self::seams( $files );
		$allowed = array();
		$names   = array();
		foreach ( $seams as $seam ) {
			$where = $seam['file'] . ':' . $seam['line'];
			$name  = $seam['class'] . '::$' . $seam['property'];
			if ( ! $seam['private'] || ! $seam['static'] ) {
				$out[] = "$where: the seam $name must be private static.";
			}
			if ( $seam['typed'] ) {
				$out[] = "$where: the seam $name must have no type.";
			}
			if ( ! in_array( $seam['default'], array( 'none', 'null' ), true ) ) {
				$out[] = "$where: the seam $name starts as something other than null.";
			}
			$lower = strtolower( $seam['property'] );
			if ( isset( $names[ $lower ] ) ) {
				$out[] = "$where: the name {$seam['property']} of a seam is declared more than once; each seam needs a name of its own.";
			}
			$names[ $lower ]                                     = $seam;
			$allowed[ $seam['file'] ][ $seam['offset'] ]         = true;
		}
		foreach ( array_keys( $names ) as $a ) {
			foreach ( array_keys( $names ) as $b ) {
				if ( $a !== $b && false !== strpos( $a, $b ) ) {
					$out[] = "The name of the seam $a holds the name of the seam $b; each name must be one of a kind.";
				}
			}
		}
		$made = array();
		foreach ( $seams as $seam ) {
			foreach ( self::statements( $seam ) as $offset => $shape ) {
				$allowed[ $seam['file'] ][ $offset ] = true;
				if ( 'null' !== $shape ) {
					$made[ $seam['class'] . '::$' . $seam['property'] ][ $shape ] = true;
				}
			}
		}
		foreach ( $made as $name => $classes ) {
			if ( count( $classes ) > 1 ) {
				$out[] = "The seam $name is made of more than one class: " . implode( ', ', array_keys( $classes ) ) . '.';
			}
		}
		foreach ( $files as $file ) {
			$code  = (string) file_get_contents( $file );
			$lower = strtolower( $code );
			// Rules 3 and 4: every occurrence of the prefix, in any case, must
			// begin an allowed occurrence of a seam name.
			for ( $at = strpos( $lower, self::PREFIX ); false !== $at; $at = strpos( $lower, self::PREFIX, $at + 1 ) ) {
				if ( isset( $allowed[ $file ][ $at ] ) ) {
					continue;
				}
				$seam  = self::seam_at( $lower, $at, $names );
				$line  = substr_count( $code, "\n", 0, $at ) + 1;
				$text  = trim( (string) strtok( substr( $code, (int) strrpos( substr( $code, 0, $at ), "\n" ) ), "\n" ) );
				$out[] = null === $seam
					? "$file:$line: the prefix " . self::PREFIX . " of the seams occurs outside a seam: «{$text}». It belongs to seam names alone."
					: "$file:$line: the name {$seam['property']} of the seam {$seam['class']}::\${$seam['property']} occurs outside its declaration and its two statements «return self::\${$seam['property']} ?? new <Class>();» and «self::\${$seam['property']} = null;»: «{$text}». Nothing may read or set it on another way.";
			}
			// Rule 5.
			$tokens = self::tokens( $file );
			foreach ( $tokens as $t ) {
				if ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) && in_array( strtolower( ltrim( $t[1], '\\' ) ), self::ENUMERATE, true ) ) {
					$out[] = $file . ':' . $t[2] . ': ' . $t[1] . ' reaches properties without naming them; only the tests may do that.';
				}
			}
		}
		return $out;
	}

	/**
	 * The allowed statements of a seam: the offsets of its name in them and
	 * their shape (the class of the accessor, or 'null').
	 *
	 * @param array $seam Seam.
	 * @return array<int,string>
	 */
	private static function statements( array $seam ) {
		$out     = array();
		$tokens  = self::tokens( $seam['file'] );
		$offsets = self::offsets( $tokens );
		$class   = null;
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$class = self::class_at( $tokens, $i, $class );
			$t     = $tokens[ $i ];
			if ( ! is_array( $t ) || T_VARIABLE !== $t[0] || '$' . $seam['property'] !== $t[1] || $class !== $seam['class'] ) {
				continue;
			}
			$colon = self::prev( $tokens, $i );
			$self  = null === $colon ? null : self::prev( $tokens, $colon );
			if ( null === $self || ! is_array( $tokens[ $colon ] ) || T_DOUBLE_COLON !== $tokens[ $colon ][0] || ! is_array( $tokens[ $self ] ) || 'self' !== $tokens[ $self ][1] ) {
				continue;
			}
			$shape = self::shape( $tokens, $self, $i );
			if ( null !== $shape ) {
				$out[ $offsets[ $i ] + 1 ] = $shape;
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
	 * The declaration a variable token stands in, or null when it is none:
	 * its modifiers and whether it has a type.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index of the variable.
	 * @return array{modifiers:int[],typed:bool}|null
	 */
	private static function declaration( array $tokens, $i ) {
		$modifiers = array();
		$typed     = false;
		for ( $k = self::prev( $tokens, $i ); null !== $k; $k = self::prev( $tokens, $k ) ) {
			$t = $tokens[ $k ];
			if ( is_array( $t ) && in_array( $t[0], self::MODIFIERS, true ) ) {
				$modifiers[] = $t[0];
				continue;
			}
			if ( array() === $modifiers && ( ( is_array( $t ) && in_array( $t[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE ), true ) ) || in_array( $t, array( '?', '|', '&', '(', ')' ), true ) ) ) {
				$typed = true;
				continue;
			}
			break;
		}
		return array() === $modifiers ? null : array(
			'modifiers' => $modifiers,
			'typed'     => $typed,
		);
	}

	/**
	 * The seam whose name begins at an offset, or null.
	 *
	 * @param string $lower Lower-case code.
	 * @param int    $at    Offset.
	 * @param array  $names Seams by lower-case name.
	 * @return array|null
	 */
	private static function seam_at( $lower, $at, array $names ) {
		$found = null;
		foreach ( $names as $name => $seam ) {
			if ( substr( $lower, $at, strlen( $name ) ) === $name && ( null === $found || strlen( $name ) > strlen( $found['property'] ) ) ) {
				$found = $seam;
			}
		}
		return $found;
	}

	/**
	 * The byte offset of each token.
	 *
	 * @param array $tokens Tokens.
	 * @return int[]
	 */
	private static function offsets( array $tokens ) {
		$out = array();
		$at  = 0;
		foreach ( $tokens as $i => $t ) {
			$out[ $i ] = $at;
			$at       += strlen( is_array( $t ) ? $t[1] : $t );
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
	 * The class a token stands in: the last class, trait, interface or enum
	 * keyword followed by a name (a trait or an anonymous class is never a
	 * class of a seam).
	 *
	 * @param array       $tokens Tokens.
	 * @param int         $i      Index.
	 * @param string|null $class  Class so far.
	 * @return string|null
	 */
	private static function class_at( array $tokens, $i, $class ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM ), true ) ) {
			$prev = self::prev( $tokens, $i );
			if ( T_CLASS === $tokens[ $i ][0] && null !== $prev && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_DOUBLE_COLON, T_NEW ), true ) ) {
				return T_NEW === $tokens[ $prev ][0] ? '{anonymous}' : $class;
			}
			$next = self::next( $tokens, $i );
			$name = null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ? $tokens[ $next ][1] : '{anonymous}';
			return T_CLASS === $tokens[ $i ][0] ? $name : '{' . $tokens[ $i ][1] . ' ' . $name . '}';
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
}
