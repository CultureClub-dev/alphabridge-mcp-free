<?php
/**
 * A scan of PHP source by its own tokens (token_get_all(), no pattern over
 * the text; Z8b branch 3, Behebung 5b): the calls of time() in any spelling
 * (time(), \time(), TIME(), time ( )) that are no method, the readers of a
 * row of AB_MCP_Store_Inputs::TABLE and of the clock of the runs
 * (AB_MCP_Sync_Run::now()), and the named functions of a file, each with the
 * method it stands in.
 *
 * @package AlphaBridge_MCP
 */

namespace AlphaBridge\Tests;

final class SourceScan {

	/**
	 * What the code holds.
	 *
	 * @param string $code PHP source.
	 * @return array{time:list<array{0:string,1:int}>,read:list<array{0:string,1:string,2:int}>,now:list<array{0:string,1:int}>,functions:list<string>}
	 */
	public static function scan( string $code ): array {
		$t     = token_get_all( $code );
		$n     = count( $t );
		$out   = array(
			'time'      => array(),
			'read'      => array(),
			'now'       => array(),
			'functions' => array(),
		);
		$class = '';
		$fn    = '';
		$at    = -1;
		$wait  = null;
		$depth = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$x = $t[ $i ];
			if ( null !== $wait && ( '{' === $x || ';' === $x ) ) {
				// The body of the function named last, or none (abstract).
				if ( '{' === $x ) {
					$fn = $wait;
					$at = $depth;
				}
				$wait = null;
			}
			if ( '{' === $x || ( is_array( $x ) && in_array( $x[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				continue;
			}
			if ( '}' === $x ) {
				--$depth;
				if ( $depth === $at ) {
					$fn = '';
					$at = -1;
				}
				continue;
			}
			if ( ! is_array( $x ) ) {
				continue;
			}
			if ( in_array( $x[0], self::kinds( array( 'T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM' ) ), true ) ) {
				$j = self::next( $t, $i + 1 );
				if ( is_array( $t[ $j ] ) && T_STRING === $t[ $j ][0] ) {
					$class = $t[ $j ][1];
				}
				continue;
			}
			if ( T_FUNCTION === $x[0] && '' === $fn && null === $wait ) {
				$j = self::next( $t, $i + 1 );
				if ( '&' === $t[ $j ] ) {
					$j = self::next( $t, $j + 1 );
				}
				if ( is_array( $t[ $j ] ) && T_STRING === $t[ $j ][0] ) {
					$wait                = ( '' === $class ? '' : $class . '::' ) . $t[ $j ][1];
					$out['functions'][] = $t[ $j ][1];
				}
				continue;
			}
			$name = null;
			if ( T_STRING === $x[0] ) {
				$name = $x[1];
			} elseif ( in_array( $x[0], self::kinds( array( 'T_NAME_FULLY_QUALIFIED' ) ), true ) ) {
				$name = ltrim( $x[1], '\\' );
			}
			if ( null === $name ) {
				continue;
			}
			$open = self::next( $t, $i + 1 );
			if ( '(' !== $t[ $open ] ) {
				continue;
			}
			$p  = self::prev( $t, $i - 1 );
			$pt = $p >= 0 ? $t[ $p ] : null;
			if ( 'time' === strtolower( $name ) ) {
				if ( ! is_array( $pt ) || ! in_array( $pt[0], self::kinds( array( 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_NEW' ) ), true ) ) {
					$out['time'][] = array( $fn, $x[2] );
				}
				continue;
			}
			if ( ! is_array( $pt ) || T_DOUBLE_COLON !== $pt[0] ) {
				continue;
			}
			$c   = self::prev( $t, $p - 1 );
			$who = $c >= 0 && is_array( $t[ $c ] ) ? ltrim( $t[ $c ][1], '\\' ) : '';
			if ( 'read' === $name && 'AB_MCP_Store_Inputs' === $who ) {
				$a   = self::next( $t, $open + 1 );
				$row = is_array( $t[ $a ] ) && T_CONSTANT_ENCAPSED_STRING === $t[ $a ][0] ? substr( $t[ $a ][1], 1, -1 ) : '?';
				$b   = self::next( $t, $a + 1 );
				if ( '.' === $t[ $b ] ) {
					$row .= '*';
				}
				$out['read'][] = array( $fn, $row, $x[2] );
			} elseif ( 'now' === $name && ( 'AB_MCP_Sync_Run' === $who || ( in_array( $who, array( 'self', 'static' ), true ) && 'AB_MCP_Sync_Run' === $class ) ) ) {
				$out['now'][] = array( $fn, $x[2] );
			}
		}
		return $out;
	}

	/**
	 * Each method that reads a row of the table or the clock of the runs,
	 * with its rows (the clock as period.clock), over files.
	 *
	 * @param string[] $files Paths.
	 * @return array<string,string[]>
	 */
	public static function readers( array $files ): array {
		$out = array();
		foreach ( $files as $file ) {
			$scan = self::scan( (string) file_get_contents( $file ) );
			foreach ( $scan['read'] as $hit ) {
				$out[ $hit[0] ][] = $hit[1];
			}
			foreach ( $scan['now'] as $hit ) {
				$out[ $hit[0] ][] = 'period.clock';
			}
		}
		foreach ( $out as $fn => $rows ) {
			$rows = array_values( array_unique( $rows ) );
			sort( $rows );
			$out[ $fn ] = $rows;
		}
		ksort( $out );
		return $out;
	}

	/** The token ids of these names that this PHP has. */
	private static function kinds( array $names ): array {
		$out = array();
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$out[] = constant( $name );
			}
		}
		return $out;
	}

	/** The next token that is no white space or comment. */
	private static function next( array $t, int $i ): int {
		$n = count( $t );
		for ( ; $i < $n; $i++ ) {
			if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return $n - 1;
	}

	/** The token before that is no white space or comment. */
	private static function prev( array $t, int $i ): int {
		for ( ; $i >= 0; $i-- ) {
			if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return -1;
	}
}
