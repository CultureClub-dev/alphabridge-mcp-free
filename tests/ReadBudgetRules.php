<?php
/**
 * The rules of the guard of the read budget (R12b, part 2.2; the order
 * belege/auftrag-teil-r12b.txt, section 3.2, R1 to R7) over the model of
 * the reader (tests/ReadBudgetScan.php): outside the chain of the check of
 * names (in G1) every step runs through the helper of the steps or in an
 * atomic seam (R1), every pass of a loop begins with a check that leaves
 * the loop at a halt (R2), every edge of a recursion stands right after a
 * check and a halt is asked before the next work after its return (R3),
 * every entry into the chain and every call of a seam that reads the disk
 * stands right after a check that passes the halt on (R4), every dynamic
 * call has its target and every comparison of a sort only compares (R5),
 * every call of G1 lands where the basis says and the chain is entered only
 * through held() (R6), and after every call that can give a halt the next
 * statement passes it on (R7).
 *
 * The rules read the source as it stands, front to back, without control
 * flow (N4 of the order): a statement is what PHP calls one (an expression
 * up to its «;», a block, if, a loop, switch, try), a body the statements
 * in braces or the one statement of a control structure. A form they do not
 * know is a problem, never skipped.
 *
 * Words of the order: a check (Prüfung) is an event CHECK of the reader or
 * a helper of the steps; a halt query (Haltabfrage) an event STOP or CHECK;
 * work (Verarbeitung) a step, a helper of the steps or a call of a function
 * that holds a step (S).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

final class ReadBudgetRules {

	/** The rules, in the order of section 3.2. */
	public const RULES = array( 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7' );

	/** A helper of the steps answers its halt as a WP_Error. */
	private const STEP_FORM = 'wp';

	/** The forms of a halt test of a check: what its answer is at a halt. */
	private const FORMS = array( 'wp', 'true', 'false' );

	/** The events that stop the search for the next work after a return. */
	private const ENDS = array( 'return', 'throw', 'break', 'continue' );

	/** @var array<string,mixed> */
	private array $model;

	/** @var array<string,mixed> */
	private array $hand;

	/** @var array<string,mixed> */
	private array $lists;

	/** @var array<string,true> The functions of the chain of the basis (Class::name). */
	private array $chain_soll = array();

	/** @var list<string> The groups of G4 of the basis. */
	private array $g4_soll = array();

	/** @var array<string,bool> Class::name => it holds a step (itself or a function it calls). */
	private array $s = array();

	/** @var array<string,bool> Class::name => it can give a halt (a check, a halt or a stop in it or below). */
	private array $ch = array();

	/** @var array<string,true> The seams with a step of the disk. */
	private array $disk_seams = array();

	/** @var array<string,list<array<string,mixed>>> Class::name => its statements. */
	private array $trees = array();

	/** @var list<array<string,mixed>> */
	private array $out = array();

	/** @var list<string> */
	private array $problems = array();

	/** @var array<string,array<string,true>> list => entry => used. */
	private array $used = array();

	/** @var array<string,mixed> What of the named boundaries the code holds. */
	private array $named = array(
		'SORT'   => array(),
		'FILTER' => array(),
		'CHAIN'  => array(),
	);

	/**
	 * The violations of R1 to R7 in G1.
	 *
	 * @param array<string,mixed> $model What ReadBudgetScan::read() returned.
	 * @param array<string,mixed> $hand  The hand tables of the reader (HAND).
	 * @param array<string,mixed> $soll  The basis of tests/ReadBudgetSoll.php (CHAIN and G4 are read).
	 * @param array<string,mixed> $lists The lists of the rules (tests/ReadBudgetExceptions.php): ATOMIC, CHECKS, FACHWERT, COMPARE, SORTS, FILTERS, FRAME_OUT.
	 * @return array{violations:list<array<string,mixed>>,problems:list<string>,named:array<string,mixed>}
	 */
	public static function check( array $model, array $hand, array $soll, array $lists ): array {
		foreach ( array( 'ATOMIC', 'CHECKS', 'FACHWERT', 'COMPARE', 'SORTS', 'FILTERS', 'FRAME_OUT' ) as $list ) {
			if ( ! array_key_exists( $list, $lists ) ) {
				throw new \InvalidArgumentException( 'List of the rules missing: ' . $list );
			}
		}
		$rules = new self( $model, $hand, $soll, $lists );
		$rules->run();
		return array(
			'violations' => $rules->out,
			'problems'   => $rules->problems,
			'named'      => $rules->named,
		);
	}

	/**
	 * The violations by function in the order of the source, as the list OPEN
	 * of tests/ReadBudgetExceptions.php holds them: Class::name => 'Rn what'.
	 *
	 * @param list<array<string,mixed>> $violations What check() returned.
	 * @return array<string,list<string>>
	 */
	public static function by_function( array $violations ): array {
		$out = array();
		foreach ( $violations as $v ) {
			$out[ $v['fn'] ][] = $v['rule'] . ' ' . $v['what'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * What only the list OPEN or only the code holds, by function and place in
	 * its order ('Class::name #n Rn what').
	 *
	 * @param array<string,list<array{0:string,1:list<int>,2:string}>> $open       The list OPEN.
	 * @param list<array<string,mixed>>                                $violations What check() returned.
	 * @return list<string>
	 */
	public static function open_diff( array $open, array $violations ): array {
		$want = array();
		foreach ( $open as $fn => $entries ) {
			foreach ( $entries as $n => $entry ) {
				$want[] = $fn . ' #' . ( $n + 1 ) . ' ' . $entry[0];
			}
		}
		$have  = array();
		$where = array();
		$n     = array();
		foreach ( $violations as $v ) {
			if ( ! isset( $n[ $v['fn'] ] ) ) {
				$n[ $v['fn'] ] = 0;
			}
			++$n[ $v['fn'] ];
			$x           = $v['fn'] . ' #' . $n[ $v['fn'] ] . ' ' . $v['rule'] . ' ' . $v['what'];
			$have[]      = $x;
			$where[ $x ] = ' (' . $v['file'] . ':' . $v['line'] . ')';
		}
		$out = array();
		foreach ( array_diff( $want, $have ) as $x ) {
			$out[] = 'only in the list: ' . $x;
		}
		foreach ( array_diff( $have, $want ) as $x ) {
			$out[] = 'only in the code:  ' . $x . $where[ $x ];
		}
		return $out;
	}

	/**
	 * What only the list NAMED or only the code holds of the named boundaries.
	 *
	 * @param array<string,mixed> $named The list NAMED.
	 * @param array<string,mixed> $have  What check() returned as named.
	 * @return list<string>
	 */
	public static function named_diff( array $named, array $have ): array {
		$out = array();
		foreach ( array( 'SORT', 'FILTER' ) as $kind ) {
			$want = array();
			foreach ( $named[ $kind ] as $fn => $entries ) {
				foreach ( $entries as $entry ) {
					$want[] = $fn . ' ' . $entry[0];
				}
			}
			$is = array();
			foreach ( $have[ $kind ] as $fn => $entries ) {
				foreach ( $entries as $entry ) {
					$is[] = $fn . ' ' . $entry;
				}
			}
			foreach ( array_diff( $want, $is ) as $x ) {
				$out[] = $kind . ' only in the list: ' . $x;
			}
			foreach ( array_diff( $is, $want ) as $x ) {
				$out[] = $kind . ' only in the code:  ' . $x;
			}
		}
		foreach ( $have['CHAIN'] as $count => $n ) {
			if ( $named['CHAIN'][ $count ] !== $n ) {
				$out[] = sprintf( 'CHAIN %s: the list %d, the code %d', $count, $named['CHAIN'][ $count ], $n );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $model Model.
	 * @param array<string,mixed> $hand  Hand tables.
	 * @param array<string,mixed> $soll  Basis.
	 * @param array<string,mixed> $lists Lists of the rules.
	 */
	private function __construct( array $model, array $hand, array $soll, array $lists ) {
		$this->model = $model;
		$this->hand  = $hand;
		$this->lists = $lists;
		foreach ( array_keys( $soll['CHAIN'] ) as $id ) {
			$this->chain_soll[ self::key_of( $id ) ] = true;
		}
		$this->g4_soll = array_keys( $soll['G4'] );
	}

	/** Class::name of file::Class::name. */
	private static function key_of( string $id ): string {
		$parts = explode( '::', $id );
		return $parts[ count( $parts ) - 2 ] . '::' . $parts[ count( $parts ) - 1 ];
	}

	private function run(): void {
		$this->summaries();
		$g1 = array();
		foreach ( array_keys( $this->model['methods'] ) as $k ) {
			if ( ! isset( $this->model['chain'][ $k ] ) ) {
				$g1[] = $k;
			}
		}
		foreach ( $g1 as $k ) {
			$this->trees[ $k ] = $this->parse( $k );
			foreach ( $this->model['events'][ $k ] as $e ) {
				if ( 'CHECK' === $e['k'] ) {
					$this->form_of( $e ); // Each check has its form in CHECKS.
				}
			}
		}
		foreach ( $g1 as $k ) {
			$this->r1( $k );
			$this->r2( $k );
			$this->r4( $k );
			$this->r5( $k );
			$this->r6( $k );
			$this->r7( $k );
		}
		$this->r3();
		$this->chain_counts();
		$this->unused();
		usort(
			$this->out,
			static function ( array $a, array $b ): int {
				return array( $a['fn'], $a['pos'], $a['rule'] ) <=> array( $b['fn'], $b['pos'], $b['rule'] );
			}
		);
	}

	/* ---------- Summaries over the functions reached ---------- */

	private function summaries(): void {
		foreach ( array_keys( $this->model['reach'] ) as $k ) {
			$this->s[ $k ]  = false;
			$this->ch[ $k ] = false;
			foreach ( $this->model['events'][ $k ] as $e ) {
				if ( isset( $e['step'] ) && 'Platte' === $e['step'] && 1 === preg_match( '/_Calls::/', $k ) ) {
					$this->disk_seams[ $k ] = true;
				}
			}
		}
		do {
			$changed = false;
			foreach ( array_keys( $this->model['reach'] ) as $k ) {
				foreach ( $this->model['events'][ $k ] as $e ) {
					if ( ! $this->s[ $k ] && ( isset( $e['step'] ) || 'STEP' === $e['k'] || $this->calls_into( $e, $this->s ) ) ) {
						$this->s[ $k ] = true;
						$changed       = true;
					}
					if ( ! $this->ch[ $k ] && ( in_array( $e['k'], array( 'CHECK', 'STOP', 'HALT', 'STEP' ), true ) || $this->calls_into( $e, $this->ch, true ) ) ) {
						$this->ch[ $k ] = true;
						$changed        = true;
					}
				}
			}
		} while ( $changed );
	}

	/**
	 * Whether a call goes into a function the summary marks (with $deadline:
	 * and gives it its deadline).
	 *
	 * @param array<string,mixed> $e       Event.
	 * @param array<string,bool>  $summary Summary.
	 */
	private function calls_into( array $e, array $summary, bool $deadline = false ): bool {
		if ( 'CALL' !== $e['k'] ) {
			return false;
		}
		foreach ( $e['to'] as $to ) {
			if ( isset( $summary[ $to ] ) && true === $summary[ $to ] && ! ( $deadline && $this->without_deadline( $e, $to ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an event is work: a step, a helper of the steps, or a call of a
	 * function that holds a step (a boundary not followed counts as one).
	 *
	 * @param array<string,mixed> $e Event.
	 */
	private function work( array $e ): bool {
		if ( isset( $e['step'] ) || 'STEP' === $e['k'] ) {
			return true;
		}
		if ( 'CALL' !== $e['k'] ) {
			return false;
		}
		foreach ( $e['to'] as $to ) {
			if ( isset( $this->s[ $to ] ) && true === $this->s[ $to ] ) {
				return true;
			}
			if ( ! isset( $this->model['reach'][ $to ] ) && in_array( $to, $this->hand['BOUNDARY'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an event asks the halt (a halt query).
	 *
	 * @param array<string,mixed> $e Event.
	 */
	private static function asks( array $e ): bool {
		return in_array( $e['k'], array( 'CHECK', 'STOP' ), true );
	}

	/* ---------- R1: steps only in the helper and the atomic seams ---------- */

	private function r1( string $k ): void {
		$atomic = in_array( $k, $this->lists['ATOMIC'], true );
		$steps  = 0;
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( ! isset( $e['step'] ) || 'STEP' === $e['k'] ) {
				continue;
			}
			++$steps;
			if ( ! $atomic ) {
				$this->add( 'R1', $k, $e, $e['what'] );
			}
		}
		if ( $atomic ) {
			$this->used['ATOMIC'][ $k ] = true;
			if ( 1 !== $steps ) {
				$this->add_at( 'R1', $k, 0, $this->model['methods'][ $k ]['start'], sprintf( 'atomic seam with %d steps', $steps ) );
			}
		}
	}

	/* ---------- R2: every pass of a loop begins with a check ---------- */

	private function r2( string $k ): void {
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'LOOP' !== $e['k'] ) {
				continue;
			}
			$st = $this->statement_at( $k, $e['pos'] );
			if ( null === $st || 'loop' !== $st['k'] ) {
				$this->problems[] = sprintf( 'R2 %s:%d %s: the loop is no statement of its own', $this->model['methods'][ $k ]['file'], $e['line'], $k );
				continue;
			}
			if ( ! $this->begins_with_check( $k, $st['bodies'][0] ) ) {
				$this->add( 'R2', $k, $e, $e['head'] );
			}
		}
	}

	/**
	 * Whether a body begins with a check whose answer leaves it at a halt
	 * (return or break): if ( HALT ) …, $v = CHECK; if ( HALT( $v ) ) …, or
	 * $v = HALT ? X : …; if ( X === $v || … ) ….
	 *
	 * @param list<array<string,mixed>> $body Statements.
	 */
	private function begins_with_check( string $k, array $body ): bool {
		if ( array() === $body ) {
			return false;
		}
		if ( null !== $this->guard_if( $k, $body[0], true, true ) ) {
			return true;
		}
		if ( ! isset( $body[1] ) ) {
			return false;
		}
		if ( $this->guard_pair( $k, $body[0], $body[1], true, true ) ) {
			return true;
		}
		$tern = $this->ternary( $k, $body[0] );
		return null !== $tern && null !== $tern['var'] && $this->passes_value( $k, $body[1], $tern['var'], $tern['halt'], true );
	}

	/* ---------- R3: recursions ---------- */

	private function r3(): void {
		foreach ( $this->model['sccs'] as $comp ) {
			if ( isset( $this->model['chain'][ $comp[0] ] ) ) {
				continue;
			}
			$group = implode( ' + ', $comp );
			if ( ! in_array( $group, $this->g4_soll, true ) ) {
				$m = $this->model['methods'][ $comp[0] ];
				$this->add_at( 'R3', $comp[0], 0, $m['start'], 'circle not in G4: ' . $group );
			}
			foreach ( $comp as $v ) {
				foreach ( $this->model['events'][ $v ] as $e ) {
					if ( 'CALL' !== $e['k'] ) {
						continue;
					}
					foreach ( $e['to'] as $to ) {
						if ( ! in_array( $to, $comp, true ) || ! isset( $this->model['edges'][ $v ][ $to ] ) ) {
							continue;
						}
						if ( ! $this->guarded( $v, $e ) ) {
							$this->add( 'R3', $v, $e, 'before ' . $to );
						}
						if ( ! $this->halt_asked_after( $v, $e ) ) {
							$this->add( 'R3', $v, $e, 'after ' . $to );
						}
					}
				}
			}
		}
	}

	/**
	 * Whether a halt query comes before the next work after a call returns:
	 * the rest of its statement, then the statements after it in its block
	 * and in the blocks around, up to a return, throw, break or continue, the
	 * end of a closure or of the function.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function halt_asked_after( string $k, array $e ): bool {
		$path = $this->path( $this->trees[ $k ], $e['pos'] );
		$last = count( $path ) - 1;
		$st   = $path[ $last ]['block'][ $path[ $last ]['i'] ];
		$seen = $this->first_after( $k, $st['s'], $st['e'], $e['pe'] );
		if ( null !== $seen ) {
			return $seen;
		}
		if ( in_array( $st['k'], self::ENDS, true ) ) {
			return true;
		}
		for ( $level = $last; $level >= 0; $level-- ) {
			$block = $path[ $level ]['block'];
			$count = count( $block );
			for ( $j = $path[ $level ]['i'] + 1; $j < $count; $j++ ) {
				$seen = $this->first_after( $k, $block[ $j ]['s'], $block[ $j ]['e'], -1 );
				if ( null !== $seen ) {
					return $seen;
				}
				if ( in_array( $block[ $j ]['k'], self::ENDS, true ) ) {
					return true;
				}
			}
			if ( $level > 0 && 'inner' === $path[ $level - 1 ]['via'] ) {
				return true; // The end of a closure.
			}
		}
		return true;
	}

	/**
	 * In the tokens $a to $b after $after: true at a halt query before any
	 * work, false at work first, null where there is neither.
	 */
	private function first_after( string $k, int $a, int $b, int $after ): ?bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a || $x['pos'] > $b || $x['pos'] <= $after ) {
				continue;
			}
			if ( self::asks( $x ) ) {
				return true;
			}
			if ( $this->work( $x ) ) {
				return false;
			}
		}
		return null;
	}

	/* ---------- R4: entries and seams of the disk right after a check ---------- */

	private function r4( string $k ): void {
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'CALL' !== $e['k'] ) {
				continue;
			}
			foreach ( $e['to'] as $to ) {
				if ( ReadBudgetScan::HELD !== $to && ! isset( $this->disk_seams[ $to ] ) ) {
					continue;
				}
				if ( ! $this->guarded( $k, $e ) ) {
					$what = 'seam ' . $to;
					if ( ReadBudgetScan::HELD === $to ) {
						$what = 'entry ' . $to;
					}
					$this->add( 'R4', $k, $e, $what );
				}
			}
		}
	}

	/* ---------- R5: dynamic calls, callbacks, comparisons ---------- */

	private function r5( string $k ): void {
		$toks = $this->model['toks'][ $k ];
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'CB' !== $e['k'] || in_array( $e['what'], array( 'call_user_func', 'call_user_func_array' ), true ) ) {
				continue;
			}
			$closures = $this->closures_in( $k, $e['pos'], $e['pe'] );
			$native   = null;
			foreach ( $e['args'] as $a ) {
				if ( 1 === preg_match( "/^'\\\\?([a-z_][a-z0-9_]*)'$/i", $a, $mm ) ) {
					$native = strtolower( $mm[1] );
				}
			}
			$plugin = false;
			foreach ( $this->model['events'][ $k ] as $x ) {
				if ( 'CALL' === $x['k'] && $x['pos'] > $e['pos'] && $x['pos'] < $e['pe'] && 0 === strpos( $x['via'], 'Rückruf' ) ) {
					$plugin = true;
				}
			}
			if ( array() === $closures && null === $native && ! $plugin ) {
				$this->add( 'R5', $k, $e, 'callback without target: ' . $e['what'] );
				continue;
			}
			if ( ! in_array( $e['what'], $this->lists['SORTS'], true ) ) {
				continue;
			}
			$this->named['SORT'][ $k ][] = $e['what'];
			if ( $plugin || ( null !== $native && ! in_array( $native, $this->lists['COMPARE'], true ) ) ) {
				$this->add( 'R5', $k, $e, 'comparison not pure: ' . $e['what'] . ' calls ' . ( null === $native ? 'a function of the plugin' : $native ) );
			}
			foreach ( $closures as $c ) {
				foreach ( $this->model['events'][ $k ] as $x ) {
					if ( $x['pos'] <= $c['s'] || $x['pos'] > $c['e'] ) {
						continue;
					}
					if ( 'FN' === $x['k'] && ! isset( $x['step'] ) && in_array( $x['what'], $this->lists['COMPARE'], true ) ) {
						$this->used['COMPARE'][ $x['what'] ] = true;
						continue;
					}
					$this->add( 'R5', $k, $x, 'comparison not pure: ' . $e['what'] . ' calls ' . self::label( $x ) );
				}
			}
			if ( null !== $native && in_array( $native, $this->lists['COMPARE'], true ) ) {
				$this->used['COMPARE'][ $native ] = true;
			}
		}
		// A closure given to a function of PHP that calls no function it is given.
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'FN' !== $e['k'] || isset( $e['cbtext'] ) || isset( $e['viadyn'] ) ) {
				continue;
			}
			$cb = false;
			foreach ( $this->model['events'][ $k ] as $x ) {
				if ( 'CB' === $x['k'] && $x['pos'] === $e['pos'] ) {
					$cb = true;
				}
			}
			if ( ! $cb && array() !== $this->closures_in( $k, $e['pos'], $e['pe'] ) ) {
				$this->add( 'R5', $k, $e, 'closure given to ' . $e['what'] . ', which is no function of ITERCB' );
			}
		}
		// Calls of an expression: ( … )( , [ … ]( , 'name'( , ->{ … }( .
		$n = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( '(' !== $toks[ $i ]->text ) {
				continue;
			}
			$p = self::prev( $toks, $i );
			if ( null === $p ) {
				continue;
			}
			$dyn = in_array( $toks[ $p ]->text, array( ')', ']' ), true ) || T_CONSTANT_ENCAPSED_STRING === $toks[ $p ]->id;
			if ( '}' === $toks[ $p ]->text ) {
				$o = self::opening( $toks, $p );
				$q = self::prev( $toks, $o );
				if ( null !== $q && ( in_array( $toks[ $q ]->id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) || '$' === $toks[ $q ]->text ) ) {
					$dyn = true;
				}
			}
			if ( $dyn && ! $this->is_head( $toks, $p ) ) {
				$this->problems[] = sprintf( 'UNSUPPORTED %s:%d call of an expression (%s() in %s', $this->model['methods'][ $k ]['file'], $toks[ $i ]->line, $toks[ $p ]->text, $k );
			}
		}
	}

	/**
	 * Whether the token closes the head of a control structure, a cast or
	 * the parameters of a closure (no call of an expression follows it).
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private function is_head( array $toks, int $p ): bool {
		if ( ')' !== $toks[ $p ]->text ) {
			return false;
		}
		$o = self::opening( $toks, $p );
		$q = self::prev( $toks, $o );
		return null !== $q && in_array( $toks[ $q ]->id, array( T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CATCH ), true );
	}

	/**
	 * The closures (function and fn) whose start lies between two tokens.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function closures_in( string $k, int $a, int $b ): array {
		$out = array();
		$st  = $this->statement_at( $k, $a );
		if ( null === $st ) {
			return $out;
		}
		$walk = function ( array $stmts ) use ( &$walk, &$out, $a, $b ): void {
			foreach ( $stmts as $s ) {
				foreach ( $s['inner'] as $in ) {
					if ( $in['s'] > $a && $in['s'] < $b ) {
						$out[] = $in;
					}
					$walk( $in['stmts'] );
				}
				foreach ( $s['bodies'] as $body ) {
					$walk( $body );
				}
			}
		};
		$walk( array( $st ) );
		return $out;
	}

	/**
	 * A short name of an event.
	 *
	 * @param array<string,mixed> $e Event.
	 */
	private static function label( array $e ): string {
		if ( 'CALL' === $e['k'] ) {
			return implode( ', ', $e['to'] );
		}
		if ( isset( $e['what'] ) ) {
			return $e['what'];
		}
		return $e['k'];
	}

	/* ---------- R6: G1 is closed, the chain is entered only through held() ---------- */

	private function r6( string $k ): void {
		$frame = null;
		if ( isset( $this->hand['FRAME'][ $k ] ) ) {
			$frame = $this->hand['FRAME'][ $k ];
		}
		foreach ( $this->model['events'][ $k ] as $e ) {
			$targets = array();
			if ( 'CALL' === $e['k'] ) {
				$targets = $e['to'];
			} elseif ( in_array( $e['k'], array( 'CHECK', 'STOP', 'HALT', 'AT' ), true ) && isset( $this->model['methods'][ $e['what'] ] ) ) {
				$targets = array( $e['what'] );
			}
			foreach ( $targets as $to ) {
				if ( isset( $this->chain_soll[ $to ] ) && ReadBudgetScan::HELD !== $to ) {
					$this->add( 'R6', $k, $e, 'entry into the chain beside held(): ' . $to );
				}
				$edge = $k . '|' . $to;
				if ( isset( $this->model['reach'][ $to ] ) || isset( $this->model['bounds'][ $edge ] ) || isset( $this->model['cuts'][ $edge ] ) ) {
					continue; // In G1 or the chain, at a boundary, or cut.
				}
				if ( null !== $frame && ! in_array( $to, $frame, true ) ) {
					if ( isset( $this->lists['FRAME_OUT'][ $edge ] ) ) {
						$this->used['FRAME_OUT'][ $edge ] = true;
						continue;
					}
					$this->add( 'R6', $k, $e, 'call of the frame not in FRAME_OUT: ' . $to );
					continue;
				}
				$this->add( 'R6', $k, $e, 'call that lands nowhere: ' . $to );
			}
		}
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'FN' === $e['k'] && in_array( $e['what'], $this->lists['FILTERS'], true ) ) {
				$this->named['FILTER'][ $k ][] = $e['what'];
			}
		}
	}

	/* ---------- R7: the halt passed on after every call that can give one ---------- */

	private function r7( string $k ): void {
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( 'STEP' === $e['k'] ) {
				if ( ! $this->passes_halt( $k, $e, null ) ) {
					$this->add( 'R7', $k, $e, $this->hand['STEP_CLASS'] . '::' . $e['what'] );
				}
				continue;
			}
			if ( 'CALL' !== $e['k'] ) {
				continue;
			}
			foreach ( $e['to'] as $to ) {
				if ( isset( $this->model['chain'][ $to ] ) || isset( $this->hand['HELPERS'][ $to ] ) || ! isset( $this->ch[ $to ] ) || true !== $this->ch[ $to ] || $this->without_deadline( $e, $to ) ) {
					continue;
				}
				if ( ! $this->passes_halt( $k, $e, $to ) ) {
					$this->add( 'R7', $k, $e, $to );
				}
			}
		}
	}

	/**
	 * Whether a call gives the function no deadline (its parameter $deadline
	 * or $budget left out or null): then it gives no halt (without a
	 * deadline a check is the bare call, AB_MCP_Sync_Deadline::left()).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function without_deadline( array $e, string $to ): bool {
		foreach ( $this->model['methods'][ $to ]['params'] as $p => $param ) {
			if ( ! in_array( $param['name'], array( '$deadline', '$budget' ), true ) ) {
				continue;
			}
			return ! isset( $e['args'][ $p ] ) || 'null' === strtolower( $e['args'][ $p ] );
		}
		return false;
	}

	/**
	 * Whether the halt of a call is passed on: the call is what its statement
	 * returns (whole, or a whole branch of a ternary), or it is assigned so
	 * to a variable and the next statement tests it with is_wp_error() or
	 * true !== and returns; or the function called is in FACHWERT and the
	 * next statement asks the halt and returns (if ( … STOP … ) return …;,
	 * return … STOP …;, or $s = STOP; if ( null !== $s ) return …;). The next
	 * statement: the one after it, or after the if, block, try or switch it
	 * ends (not after a loop or a closure).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function passes_halt( string $k, array $e, ?string $to ): bool {
		$path = $this->path( $this->trees[ $k ], $e['pos'] );
		$last = count( $path ) - 1;
		$st   = $path[ $last ]['block'][ $path[ $last ]['i'] ];
		if ( 'return' === $st['k'] && $this->is_value( $k, $e, $st['x'], $st['e'] - 1 ) ) {
			return true;
		}
		$next = $this->next_statement( $path );
		$var  = $this->assigned( $k, $st, $e );
		if ( null !== $var && null !== $next && $this->tests_value( $k, $next['st'], $var, self::STEP_FORM, false ) ) {
			return true;
		}
		if ( null === $to || ! isset( $this->lists['FACHWERT'][ $to ] ) ) {
			return false;
		}
		$this->used['FACHWERT'][ $to ] = true;
		if ( null === $next ) {
			return false;
		}
		$n = $next['st'];
		if ( 'if' === $n['k'] && 1 === count( $n['bodies'] ) && self::leaves( $n['bodies'][0], false ) ) {
			return $this->asks_in( $k, $n['heads'][0][0], $n['heads'][0][1] );
		}
		if ( 'return' === $n['k'] ) {
			return $this->asks_in( $k, $n['s'], $n['e'] );
		}
		if ( 'expr' !== $n['k'] || ! isset( $next['block'][ $next['i'] + 1 ] ) ) {
			return false;
		}
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( 'STOP' === $x['k'] && $x['pos'] >= $n['s'] && $x['pos'] <= $n['e'] ) {
				$s = $this->assigned( $k, $n, $x );
				return null !== $s && $this->tests_stop( $k, $next['block'][ $next['i'] + 1 ], $s );
			}
		}
		return false;
	}

	/** Whether the tokens $a to $b hold a halt query. */
	private function asks_in( string $k, int $a, int $b ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] > $a && $x['pos'] < $b && self::asks( $x ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an if with one leaving branch tests a stop: null !== $s.
	 *
	 * @param array<string,mixed> $st Statement.
	 */
	private function tests_stop( string $k, array $st, string $var ): bool {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) || ! self::leaves( $st['bodies'][0], false ) ) {
			return false;
		}
		$cond = self::norm( $this->model['toks'][ $k ], $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 );
		return 'null!==' . $var === $cond || $var . '!==null' === $cond;
	}

	/**
	 * The statement that runs after the innermost one of a path (its block,
	 * its index there and itself), or null: after a loop body, a closure or
	 * the function there is none.
	 *
	 * @param list<array<string,mixed>> $path Path.
	 * @return ?array{block:list<array<string,mixed>>,i:int,st:array<string,mixed>}
	 */
	private function next_statement( array $path ): ?array {
		for ( $level = count( $path ) - 1; $level >= 0; $level-- ) {
			$block = $path[ $level ]['block'];
			$i     = $path[ $level ]['i'];
			if ( isset( $block[ $i + 1 ] ) ) {
				return array(
					'block' => $block,
					'i'     => $i + 1,
					'st'    => $block[ $i + 1 ],
				);
			}
			if ( 0 === $level || 'body' !== $path[ $level - 1 ]['via'] ) {
				return null;
			}
			$around = $path[ $level - 1 ]['block'][ $path[ $level - 1 ]['i'] ];
			if ( ! in_array( $around['k'], array( 'if', 'block', 'try', 'switch' ), true ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Whether the tokens $a to $b are the value of a call: the call and
	 * nothing else, or a top-level ternary one of whose branches is it.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function is_value( string $k, array $e, ?int $a, int $b ): bool {
		if ( null === $a ) {
			return false;
		}
		if ( $this->whole_call( $k, $e, $a, $b ) ) {
			return true;
		}
		$toks = $this->model['toks'][ $k ];
		$tern = self::branches( $toks, $a, $b );
		if ( null === $tern ) {
			return false;
		}
		foreach ( array( $tern[1], $tern[2] ) as $branch ) {
			if ( $this->whole_call( $k, $e, $branch[0], $branch[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A top-level ternary of the tokens $a to $b: its condition, its two
	 * branches (each as first and last token), or null.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return ?array{0:array{0:int,1:int},1:array{0:?int,1:int},2:array{0:?int,1:int}}
	 */
	private static function branches( array $toks, int $a, int $b ): ?array {
		$q    = null;
		$open = 0;
		$d    = 0;
		for ( $j = $a; $j <= $b; $j++ ) {
			$x = $toks[ $j ]->text;
			if ( in_array( $x, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $toks[ $j ]->id || T_DOLLAR_OPEN_CURLY_BRACES === $toks[ $j ]->id ) {
				++$d;
			} elseif ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				--$d;
			} elseif ( 0 === $d && '?' === $x ) {
				if ( null === $q ) {
					$q = $j;
				}
				++$open;
			} elseif ( 0 === $d && ':' === $x && null !== $q ) {
				--$open;
				if ( 0 === $open ) {
					return array(
						array( $a, self::last( $toks, $q - 1 ) ),
						array( self::next( $toks, $q ), self::last( $toks, $j - 1 ) ),
						array( self::next( $toks, $j ), self::last( $toks, $b ) ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * The last token at or before $b that is no space or comment.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function last( array $toks, int $b ): int {
		$p = self::prev( $toks, $b + 1 );
		if ( null === $p ) {
			return $b;
		}
		return $p;
	}

	/* ---------- The forms of a check ---------- */

	/**
	 * Whether an event stands right after a check whose halt is passed on,
	 * with no work between the check and it (work in its own arguments runs
	 * before it, a call around it after it): in the condition of an if with a
	 * leaving branch after a halt test; in a ternary after its halt test (the
	 * halt answered by the statement, or tested by the next one); or after a
	 * statement that checks and returns at a halt (if ( HALT ) return …; or
	 * $v = CHECK; if ( HALT( $v ) ) return …;) with statements without work
	 * between, also from the first statements of a then branch, a block or a
	 * try up to the statement around them. Not in the head of an elseif or of
	 * a loop other than foreach (asked at each pass).
	 *
	 * @param array<string,mixed> $e The event.
	 */
	private function guarded( string $k, array $e ): bool {
		$path  = $this->path( $this->trees[ $k ], $e['pos'] );
		$level = count( $path ) - 1;
		$block = $path[ $level ]['block'];
		$i     = $path[ $level ]['i'];
		$st    = $block[ $i ];
		if ( $this->work_in( $k, $e['pos'] + 1, $e['pe'] ) ) {
			return false;
		}
		foreach ( $st['heads'] as $n => $head ) {
			if ( null === $head || $e['pos'] < $head[0] || $e['pos'] > $head[1] ) {
				continue;
			}
			if ( ( 'if' === $st['k'] && 0 !== $n ) || ( 'loop' === $st['k'] && T_FOREACH !== $this->model['toks'][ $k ][ $st['s'] ]->id ) ) {
				return false;
			}
		}
		if ( 'if' === $st['k'] && $e['pos'] > $st['heads'][0][0] && $e['pos'] < $st['heads'][0][1] && 1 === count( $st['bodies'] ) && self::leaves( $st['bodies'][0], false ) ) {
			foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
				if ( $e['pos'] >= $d[0] && $e['pos'] <= $d[1] ) {
					break;
				}
				if ( null !== $this->halt_test( $k, $d[0], $d[1] ) ) {
					return ! $this->work_before( $k, $d[1] + 1, $e );
				}
			}
		}
		$tern = $this->ternary( $k, $st );
		if ( null !== $tern && $e['pos'] >= $tern['rest'][0] && $e['pos'] <= $tern['rest'][1] ) {
			if ( $this->work_before( $k, $tern['rest'][0], $e ) ) {
				return false;
			}
			if ( null === $tern['var'] ) {
				return true; // return HALT ? X : …: the halt answers.
			}
			return isset( $block[ $i + 1 ] ) && $this->passes_value( $k, $block[ $i + 1 ], $tern['var'], $tern['halt'], false );
		}
		if ( $this->work_before( $k, $st['s'], $e ) ) {
			return false;
		}
		while ( true ) {
			$before = $this->guard_before( $k, $block, $i );
			if ( 'yes' === $before ) {
				return true;
			}
			if ( 'no' === $before || 0 === $level ) {
				return false;
			}
			// No work before it in its block: up to the statement around a then branch, a block or a try.
			$up = $path[ $level - 1 ];
			$p  = $up['block'][ $up['i'] ];
			if ( 'body' !== $up['via'] || ! ( 'block' === $p['k'] || ( 'try' === $p['k'] && 0 === $up['n'] ) || ( 'if' === $p['k'] && 0 === $up['n'] ) ) ) {
				return false;
			}
			if ( 'if' === $p['k'] && $this->work_in( $k, $p['heads'][0][0], $p['heads'][0][1] ) ) {
				return false;
			}
			--$level;
			$block = $up['block'];
			$i     = $up['i'];
		}
	}

	/**
	 * Back from the statement before $i: 'yes' at a statement that checks and
	 * returns at a halt (no work after the check in it), 'no' at work first,
	 * 'top' at the start of the block.
	 *
	 * @param list<array<string,mixed>> $block Statements.
	 */
	private function guard_before( string $k, array $block, int $i ): string {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$check = $this->guard_if( $k, $block[ $j ], false, false );
			if ( null !== $check ) {
				if ( $this->work_in( $k, $check['pe'] + 1, $block[ $j ]['heads'][0][1] ) ) {
					return 'no';
				}
				return 'yes';
			}
			if ( $j >= 1 && $this->guard_pair( $k, $block[ $j - 1 ], $block[ $j ], false, false ) ) {
				return 'yes';
			}
			if ( $this->work_in( $k, $block[ $j ]['s'], $block[ $j ]['e'] ) ) {
				return 'no';
			}
		}
		return 'top';
	}

	/**
	 * Whether work runs from the token $a before an event: work that starts
	 * at $a or later and before it, and is no call around it.
	 *
	 * @param array<string,mixed> $e The event.
	 */
	private function work_before( string $k, int $a, array $e ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a || $x['pos'] >= $e['pos'] || ! $this->work( $x ) ) {
				continue;
			}
			if ( isset( $x['pe'] ) && $x['pe'] >= $e['pe'] ) {
				continue; // A call around it runs after it.
			}
			return true;
		}
		return false;
	}

	/**
	 * Whether the statement after a ternary passes its halt on: an if with a
	 * leaving branch whose condition asks first, before any work, X === $v
	 * (the value at a halt) or the halt itself.
	 *
	 * @param array<string,mixed> $st Statement.
	 */
	private function passes_value( string $k, array $st, string $var, string $halt, bool $break ): bool {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) || ! self::leaves( $st['bodies'][0], $break ) ) {
			return false;
		}
		$toks = $this->model['toks'][ $k ];
		foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
			$text = self::norm( $toks, $d[0], $d[1] );
			if ( $text === $halt . '===' . $var || $text === $var . '===' . $halt ) {
				return true;
			}
			foreach ( $this->model['events'][ $k ] as $x ) {
				if ( $x['pos'] >= $d[0] && $x['pos'] <= $d[1] && self::asks( $x ) ) {
					return true;
				}
			}
			if ( $this->work_in( $k, $d[0], $d[1] ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * An if with one branch whose condition holds a halt test of a check as
	 * one of its disjuncts (those before it pure) and whose branch leaves:
	 * the check, or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @return ?array<string,mixed>
	 */
	private function guard_if( string $k, array $st, bool $break, bool $step ): ?array {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) || ! self::leaves( $st['bodies'][0], $break ) ) {
			return null;
		}
		foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
			$check = $this->halt_test( $k, $d[0], $d[1] );
			if ( null !== $check && ( $step || 'STEP' !== $check['k'] ) ) {
				return $check;
			}
			if ( ! $this->pure( $k, $d[0], $d[1] ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * $v = CHECK( … ); and then if ( HALT( $v ) ) leaves.
	 *
	 * @param array<string,mixed> $a The assignment.
	 * @param array<string,mixed> $b The if.
	 */
	private function guard_pair( string $k, array $a, array $b, bool $break, bool $step ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a['s'] || $x['pos'] > $a['e'] ) {
				continue;
			}
			$is_check = 'CHECK' === $x['k'] || ( $step && 'STEP' === $x['k'] );
			if ( ! $is_check ) {
				continue;
			}
			$var = $this->assigned( $k, $a, $x );
			if ( null !== $var ) {
				return $this->tests_value( $k, $b, $var, $this->form_of( $x ), $break );
			}
		}
		return false;
	}

	/**
	 * Whether an if with one branch tests a value for its halt as its whole
	 * condition (or, for a value of a ternary, as its first disjunct X === $v)
	 * and leaves.
	 *
	 * @param array<string,mixed> $st   Statement.
	 * @param string              $halt A form of FORMS, or the text of the value at a halt (of a ternary).
	 */
	private function tests_value( string $k, array $st, string $var, string $halt, bool $break ): bool {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) || ! self::leaves( $st['bodies'][0], $break ) ) {
			return false;
		}
		$toks = $this->model['toks'][ $k ];
		if ( ! in_array( $halt, self::FORMS, true ) ) {
			$ds = $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 );
			$d  = self::norm( $toks, $ds[0][0], $ds[0][1] );
			return $d === $halt . '===' . $var || $d === $var . '===' . $halt;
		}
		$cond = self::norm( $toks, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 );
		return in_array( $cond, self::halt_forms( $var, $halt ), true );
	}

	/**
	 * The texts of a halt test of an expression in a form (or, with $go, of
	 * the test that there is no halt).
	 *
	 * @return list<string>
	 */
	private static function halt_forms( string $x, string $form, bool $go = false ): array {
		if ( 'wp' === $form && $go ) {
			return array( 'true===' . $x, $x . '===true' );
		}
		if ( 'wp' === $form ) {
			return array( 'true!==' . $x, $x . '!==true', 'is_wp_error(' . $x . ')' );
		}
		if ( ( 'true' === $form ) !== $go ) {
			return array( $x );
		}
		return array( '!' . $x );
	}

	/**
	 * The form of a check (what it answers at a halt).
	 *
	 * @param array<string,mixed> $x A check.
	 */
	private function form_of( array $x ): string {
		if ( 'STEP' === $x['k'] ) {
			return self::STEP_FORM;
		}
		if ( ! isset( $this->lists['CHECKS'][ $x['what'] ] ) ) {
			$this->problems[] = 'CHECKS missing: ' . $x['what'];
			return 'unknown';
		}
		$this->used['CHECKS'][ $x['what'] ] = true;
		return $this->lists['CHECKS'][ $x['what'] ];
	}

	/**
	 * The check whose halt test (with $go: test of no halt) the tokens $a to
	 * $b are (whole), or null.
	 *
	 * @return ?array<string,mixed>
	 */
	private function halt_test( string $k, int $a, int $b, bool $go = false ): ?array {
		$toks = $this->model['toks'][ $k ];
		$text = self::norm( $toks, $a, $b );
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( ( 'CHECK' !== $x['k'] && 'STEP' !== $x['k'] ) || $x['pos'] < $a || $x['pe'] > $b ) {
				continue;
			}
			if ( in_array( $text, self::halt_forms( self::norm( $toks, $this->start_of( $k, $x ), $x['pe'] ), $this->form_of( $x ), $go ), true ) ) {
				return $x;
			}
		}
		return null;
	}

	/**
	 * $v = HALT ? X : rest; or return HALT ? X : rest;: the variable (null for
	 * return), the text of X and the tokens of rest; or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @return ?array{var:?string,halt:string,rest:array{0:int,1:int},check:array<string,mixed>}
	 */
	private function ternary( string $k, array $st ): ?array {
		if ( 'expr' !== $st['k'] && 'return' !== $st['k'] ) {
			return null;
		}
		$toks = $this->model['toks'][ $k ];
		$a    = $st['x'];
		$var  = null;
		if ( 'expr' === $st['k'] ) {
			$eq = self::next( $toks, $st['s'] );
			if ( T_VARIABLE !== $toks[ $st['s'] ]->id || null === $eq || '=' !== $toks[ $eq ]->text ) {
				return null;
			}
			$var = $toks[ $st['s'] ]->text;
			$a   = self::next( $toks, $eq );
		}
		if ( null === $a ) {
			return null;
		}
		$b    = $st['e'] - 1;
		$tern = self::branches( $toks, $a, $b );
		if ( null === $tern || null === $tern[1][0] || null === $tern[2][0] ) {
			return null;
		}
		// HALT ? X : rest, the disjuncts before the halt test pure.
		foreach ( $this->disjuncts( $k, $tern[0][0], $tern[0][1] ) as $d ) {
			$check = $this->halt_test( $k, $d[0], $d[1] );
			if ( null !== $check ) {
				return array(
					'var'   => $var,
					'halt'  => self::norm( $toks, $tern[1][0], $tern[1][1] ),
					'rest'  => $tern[2],
					'check' => $check,
				);
			}
			if ( ! $this->pure( $k, $d[0], $d[1] ) ) {
				break;
			}
		}
		// GO ? rest : X (true === CHECK, ! STOPPED, LEFT).
		$cond  = self::trim( $toks, $tern[0][0], $tern[0][1] );
		$check = $this->halt_test( $k, $cond[0], $cond[1], true );
		if ( null === $check ) {
			return null;
		}
		return array(
			'var'   => $var,
			'halt'  => self::norm( $toks, $tern[2][0], $tern[2][1] ),
			'rest'  => $tern[1],
			'check' => $check,
		);
	}

	/**
	 * The variable a statement $v = CALL; assigns the whole call to, or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @param array<string,mixed> $e  The call.
	 */
	private function assigned( string $k, array $st, array $e ): ?string {
		if ( 'expr' !== $st['k'] ) {
			return null;
		}
		$toks = $this->model['toks'][ $k ];
		$eq   = self::next( $toks, $st['s'] );
		if ( T_VARIABLE !== $toks[ $st['s'] ]->id || null === $eq || '=' !== $toks[ $eq ]->text ) {
			return null;
		}
		if ( ! $this->is_value( $k, $e, self::next( $toks, $eq ), $st['e'] - 1 ) ) {
			return null;
		}
		return $toks[ $st['s'] ]->text;
	}

	/**
	 * Whether the tokens $a to $b are the call and nothing else (a receiver
	 * chain before it, an @ in front).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function whole_call( string $k, array $e, ?int $a, int $b ): bool {
		if ( null === $a || $e['pe'] !== self::prev( $this->model['toks'][ $k ], $b + 1 ) ) {
			return false;
		}
		return $this->start_of( $k, $e ) === $a || ( '@' === $this->model['toks'][ $k ][ $a ]->text && $this->start_of( $k, $e ) === self::next( $this->model['toks'][ $k ], $a ) );
	}

	/**
	 * The first token of a call with its receiver: $x->, K::f()->, self::.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function start_of( string $k, array $e ): int {
		$toks = $this->model['toks'][ $k ];
		$i    = $e['pos'];
		while ( true ) {
			$p = self::prev( $toks, $i );
			if ( null === $p ) {
				return $i;
			}
			$id = $toks[ $p ]->id;
			if ( in_array( $id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
				$o = self::prev( $toks, $p );
				if ( null === $o ) {
					return $i;
				}
				if ( ')' === $toks[ $o ]->text ) {
					$o = self::prev( $toks, self::opening( $toks, $o ) );
					if ( null === $o ) {
						return $i;
					}
				}
				$i = $o;
				continue;
			}
			return $i;
		}
	}

	/**
	 * The top-level disjuncts (split at ||) of the tokens $a to $b, each
	 * without its outer parentheses.
	 *
	 * @return list<array{0:int,1:int}>
	 */
	private function disjuncts( string $k, int $a, int $b ): array {
		$toks = $this->model['toks'][ $k ];
		$out  = array();
		$d    = 0;
		$from = $a;
		for ( $j = $a; $j <= $b; $j++ ) {
			$x = $toks[ $j ]->text;
			if ( in_array( $x, array( '(', '[', '{' ), true ) ) {
				++$d;
			} elseif ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				--$d;
			} elseif ( 0 === $d && T_BOOLEAN_OR === $toks[ $j ]->id ) {
				$out[] = self::trim( $toks, $from, $j - 1 );
				$from  = $j + 1;
			}
		}
		$out[] = self::trim( $toks, $from, $b );
		return $out;
	}

	/**
	 * The tokens $a to $b without the space around them and without the
	 * parentheses that hold all of them.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return array{0:int,1:int}
	 */
	private static function trim( array $toks, int $a, int $b ): array {
		$x = self::sig( $toks, $a, $b );
		$y = self::prev( $toks, $b + 1 );
		while ( null !== $x && null !== $y && $x < $y && '(' === $toks[ $x ]->text && self::closing( $toks, $x ) === $y ) {
			$x = self::next( $toks, $x );
			$y = self::prev( $toks, $y );
		}
		if ( null === $x || null === $y ) {
			return array( $a, $b );
		}
		return array( $x, $y );
	}

	/** Whether the tokens $a to $b hold no event but functions of PHP that are no step. */
	private function pure( string $k, int $a, int $b ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] >= $a && $x['pos'] <= $b && ( 'FN' !== $x['k'] || isset( $x['step'] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the tokens $a to $b hold work. */
	private function work_in( string $k, int $a, int $b ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] >= $a && $x['pos'] <= $b && $this->work( $x ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a body leaves: a return (or a break) among its statements.
	 *
	 * @param list<array<string,mixed>> $body Statements.
	 */
	private static function leaves( array $body, bool $break ): bool {
		foreach ( $body as $st ) {
			if ( 'return' === $st['k'] || ( $break && 'break' === $st['k'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- The named boundaries the code holds ---------- */

	private function chain_counts(): void {
		$steps = 0;
		$loops = 0;
		foreach ( array_keys( $this->model['chain'] ) as $k ) {
			foreach ( $this->model['events'][ $k ] as $e ) {
				if ( isset( $e['step'] ) ) {
					++$steps;
				}
				if ( 'LOOP' === $e['k'] ) {
					++$loops;
				}
			}
		}
		$groups = 0;
		foreach ( $this->model['sccs'] as $comp ) {
			if ( isset( $this->model['chain'][ $comp[0] ] ) ) {
				++$groups;
			}
		}
		$this->named['CHAIN'] = array(
			'functions'  => count( $this->model['chain'] ),
			'steps'      => $steps,
			'loops'      => $loops,
			'recursions' => $groups,
		);
		ksort( $this->named['SORT'] );
		ksort( $this->named['FILTER'] );
	}

	private function unused(): void {
		foreach ( $this->lists['ATOMIC'] as $k ) {
			if ( ! isset( $this->used['ATOMIC'][ $k ] ) ) {
				$this->problems[] = 'ATOMIC not in G1: ' . $k;
			}
		}
		foreach ( array( 'CHECKS', 'FACHWERT', 'FRAME_OUT' ) as $list ) {
			foreach ( array_keys( $this->lists[ $list ] ) as $entry ) {
				if ( ! isset( $this->used[ $list ][ $entry ] ) ) {
					$this->problems[] = sprintf( '%s not used: %s', $list, $entry );
				}
			}
		}
		foreach ( $this->lists['COMPARE'] as $entry ) {
			if ( ! isset( $this->used['COMPARE'][ $entry ] ) ) {
				$this->problems[] = 'COMPARE not used: ' . $entry;
			}
		}
	}

	/* ---------- Violations ---------- */

	/**
	 * @param array<string,mixed> $e Event.
	 */
	private function add( string $rule, string $k, array $e, string $what ): void {
		$this->add_at( $rule, $k, $e['pos'], $e['line'], $what );
	}

	private function add_at( string $rule, string $k, int $pos, int $line, string $what ): void {
		$this->out[] = array(
			'rule' => $rule,
			'fn'   => $k,
			'file' => $this->model['methods'][ $k ]['file'],
			'line' => $line,
			'pos'  => $pos,
			'what' => $what,
		);
	}

	/* ---------- Statements ---------- */

	/**
	 * The statements of a body.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function parse( string $k ): array {
		$toks = $this->model['toks'][ $k ];
		return $this->block( $toks, 1, count( $toks ) - 2, $k );
	}

	/**
	 * The statements of the tokens $a to $b. A statement: k (expr, return,
	 * break, continue, throw, if, loop, switch, try, block), s and e (its
	 * first and last token), x (the first token of its expression after
	 * return), heads (the parentheses of its conditions, null for else),
	 * bodies (its blocks) and inner (the closures in its expression, each
	 * with s, e and its statements).
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return list<array<string,mixed>>
	 */
	private function block( array $toks, int $a, int $b, string $k ): array {
		$out = array();
		$i   = self::sig( $toks, $a, $b );
		while ( null !== $i ) {
			if ( ';' === $toks[ $i ]->text ) {
				$i = self::sig( $toks, $i + 1, $b );
				continue;
			}
			$st    = $this->statement( $toks, $i, $b, $k );
			$out[] = $st;
			$i     = self::sig( $toks, $st['e'] + 1, $b );
		}
		return $out;
	}

	/**
	 * One statement from $i (at most to $b).
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return array<string,mixed>
	 */
	private function statement( array $toks, int $i, int $b, string $k ): array {
		$t  = $toks[ $i ];
		$st = array(
			'k'      => 'expr',
			's'      => $i,
			'e'      => $i,
			'x'      => $i,
			'heads'  => array(),
			'bodies' => array(),
			'inner'  => array(),
		);
		if ( '{' === $t->text ) {
			$e              = self::closing( $toks, $i );
			$st['k']        = 'block';
			$st['e']        = $e;
			$st['bodies'][] = $this->block( $toks, $i + 1, $e - 1, $k );
			return $st;
		}
		if ( T_IF === $t->id ) {
			return $this->if_statement( $toks, $i, $b, $k );
		}
		if ( in_array( $t->id, array( T_FOREACH, T_FOR, T_WHILE ), true ) ) {
			$hp             = self::next( $toks, $i );
			$he             = self::closing( $toks, $hp );
			$body           = $this->body( $toks, self::next( $toks, $he ), $b, $k );
			$st['k']        = 'loop';
			$st['heads'][]  = array( $hp, $he );
			$st['bodies'][] = $body[0];
			$st['e']        = $body[1];
			return $st;
		}
		if ( T_DO === $t->id ) {
			$body           = $this->body( $toks, self::next( $toks, $i ), $b, $k );
			$w              = self::next( $toks, $body[1] );
			$hp             = self::next( $toks, $w );
			$he             = self::closing( $toks, $hp );
			$st['k']        = 'loop';
			$st['heads'][]  = array( $hp, $he );
			$st['bodies'][] = $body[0];
			$st['e']        = self::next( $toks, $he );
			return $st;
		}
		if ( T_SWITCH === $t->id ) {
			$hp            = self::next( $toks, $i );
			$he            = self::closing( $toks, $hp );
			$bs            = self::next( $toks, $he );
			$be            = self::closing( $toks, $bs );
			$st['k']       = 'switch';
			$st['heads'][] = array( $hp, $he );
			$st['bodies']  = $this->cases( $toks, $bs + 1, $be - 1, $k );
			$st['e']       = $be;
			return $st;
		}
		if ( T_TRY === $t->id ) {
			$bs             = self::next( $toks, $i );
			$be             = self::closing( $toks, $bs );
			$st['k']        = 'try';
			$st['bodies'][] = $this->block( $toks, $bs + 1, $be - 1, $k );
			$n              = self::sig( $toks, $be + 1, $b );
			while ( null !== $n && in_array( $toks[ $n ]->id, array( T_CATCH, T_FINALLY ), true ) ) {
				$bs = self::next( $toks, $n );
				if ( T_CATCH === $toks[ $n ]->id ) {
					$bs = self::next( $toks, self::closing( $toks, $bs ) );
				}
				$be             = self::closing( $toks, $bs );
				$st['bodies'][] = $this->block( $toks, $bs + 1, $be - 1, $k );
				$n              = self::sig( $toks, $be + 1, $b );
			}
			$st['e'] = $be;
			return $st;
		}
		$kinds = array(
			T_RETURN   => 'return',
			T_BREAK    => 'break',
			T_CONTINUE => 'continue',
			T_THROW    => 'throw',
		);
		if ( isset( $kinds[ $t->id ] ) ) {
			$st['k'] = $kinds[ $t->id ];
			$x       = self::next( $toks, $i );
			if ( null !== $x ) {
				$st['x'] = $x;
			}
		}
		if ( in_array( $t->id, array( T_INLINE_HTML, T_CLOSE_TAG, T_ELSE, T_ELSEIF, T_CASE, T_DEFAULT, T_CATCH, T_FINALLY, T_ENDIF, T_ENDFOREACH, T_ENDFOR, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE ), true ) || ':' === $t->text ) {
			$this->problems[] = sprintf( 'UNSUPPORTED %s:%d statement beginning with %s in %s', $this->model['methods'][ $k ]['file'], $t->line, $t->text, $k );
		}
		// An expression up to its ; (the closures in it with their statements).
		$d = 0;
		for ( $j = $i; $j <= $b; $j++ ) {
			$x = $toks[ $j ];
			if ( T_FUNCTION === $x->id ) {
				$bs = $j;
				while ( $bs <= $b && '{' !== $toks[ $bs ]->text ) {
					++$bs;
				}
				$be            = self::closing( $toks, $bs );
				$st['inner'][] = array(
					'k'     => 'closure',
					's'     => $j,
					'e'     => $be,
					'stmts' => $this->block( $toks, $bs + 1, $be - 1, $k ),
				);
				$j             = $be;
				continue;
			}
			if ( T_FN === $x->id ) {
				$st['inner'][] = $this->arrow( $toks, $j, $b );
				continue;
			}
			if ( in_array( $x->text, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $x->id || T_DOLLAR_OPEN_CURLY_BRACES === $x->id ) {
				++$d;
			} elseif ( in_array( $x->text, array( ')', ']', '}' ), true ) ) {
				--$d;
				if ( $d < 0 ) {
					break;
				}
			} elseif ( ';' === $x->text && 0 === $d ) {
				$st['e'] = $j;
				return $st;
			}
		}
		$this->problems[] = sprintf( 'UNSUPPORTED %s:%d statement without its ; in %s', $this->model['methods'][ $k ]['file'], $t->line, $k );
		$st['e']          = $b;
		return $st;
	}

	/**
	 * fn ( … ) => expression: a closure of one statement, the return of its
	 * expression.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return array<string,mixed>
	 */
	private function arrow( array $toks, int $j, int $b ): array {
		$a = $j;
		while ( $a <= $b && T_DOUBLE_ARROW !== $toks[ $a ]->id ) {
			++$a;
		}
		$x = self::next( $toks, $a );
		$d = 0;
		$y = $x;
		for ( $q = $x; $q <= $b; $q++ ) {
			$t = $toks[ $q ]->text;
			if ( in_array( $t, array( '(', '[', '{' ), true ) ) {
				++$d;
			} elseif ( in_array( $t, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $d ) {
					break;
				}
				--$d;
			} elseif ( 0 === $d && ( ',' === $t || ';' === $t ) ) {
				break;
			}
			$y = $q;
		}
		$y = self::prev( $toks, $y + 1 );
		return array(
			'k'     => 'arrow',
			's'     => $j,
			'e'     => $y,
			'stmts' => array(
				array(
					'k'      => 'return',
					's'      => $x,
					'e'      => $y + 1,
					'x'      => $x,
					'heads'  => array(),
					'bodies' => array(),
					'inner'  => array(),
				),
			),
		);
	}

	/**
	 * if ( … ) body [elseif ( … ) body]… [else body].
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return array<string,mixed>
	 */
	private function if_statement( array $toks, int $i, int $b, string $k ): array {
		$st   = array(
			'k'      => 'if',
			's'      => $i,
			'e'      => $i,
			'x'      => $i,
			'heads'  => array(),
			'bodies' => array(),
			'inner'  => array(),
		);
		$hp   = self::next( $toks, $i );
		$he   = self::closing( $toks, $hp );
		$body = $this->body( $toks, self::next( $toks, $he ), $b, $k );

		$st['heads'][]  = array( $hp, $he );
		$st['bodies'][] = $body[0];
		$e              = $body[1];
		while ( true ) {
			$n = self::sig( $toks, $e + 1, $b );
			if ( null === $n ) {
				break;
			}
			if ( T_ELSEIF === $toks[ $n ]->id ) {
				$hp             = self::next( $toks, $n );
				$he             = self::closing( $toks, $hp );
				$body           = $this->body( $toks, self::next( $toks, $he ), $b, $k );
				$st['heads'][]  = array( $hp, $he );
				$st['bodies'][] = $body[0];
				$e              = $body[1];
				continue;
			}
			if ( T_ELSE === $toks[ $n ]->id ) {
				$body           = $this->body( $toks, self::next( $toks, $n ), $b, $k );
				$st['heads'][]  = null;
				$st['bodies'][] = $body[0];
				$e              = $body[1];
			}
			break;
		}
		$st['e'] = $e;
		return $st;
	}

	/**
	 * The body of a control structure at $i: a block, or one statement.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return array{0:list<array<string,mixed>>,1:int} The statements and the last token.
	 */
	private function body( array $toks, ?int $i, int $b, string $k ): array {
		if ( null === $i ) {
			return array( array(), $b );
		}
		if ( '{' === $toks[ $i ]->text ) {
			$e = self::closing( $toks, $i );
			return array( $this->block( $toks, $i + 1, $e - 1, $k ), $e );
		}
		if ( ':' === $toks[ $i ]->text ) {
			$this->problems[] = sprintf( 'UNSUPPORTED %s:%d control structure in the alternative syntax in %s', $this->model['methods'][ $k ]['file'], $toks[ $i ]->line, $k );
			return array( array(), $i );
		}
		$st = $this->statement( $toks, $i, $b, $k );
		return array( array( $st ), $st['e'] );
	}

	/**
	 * The bodies of the cases of a switch (each case its own body).
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return list<list<array<string,mixed>>>
	 */
	private function cases( array $toks, int $a, int $b, string $k ): array {
		$out = array();
		$cur = null;
		$i   = self::sig( $toks, $a, $b );
		while ( null !== $i ) {
			if ( in_array( $toks[ $i ]->id, array( T_CASE, T_DEFAULT ), true ) ) {
				if ( null !== $cur ) {
					$out[] = $cur;
				}
				$cur = array();
				$q   = 0;
				$j   = $i + 1;
				while ( $j <= $b && ! ( ( ':' === $toks[ $j ]->text || ';' === $toks[ $j ]->text ) && 0 === $q ) ) {
					if ( '?' === $toks[ $j ]->text ) {
						++$q;
					} elseif ( ':' === $toks[ $j ]->text ) {
						--$q;
					}
					++$j;
				}
				$i = self::sig( $toks, $j + 1, $b );
				continue;
			}
			if ( null === $cur ) {
				$this->problems[] = sprintf( 'UNSUPPORTED %s:%d statement before the first case in %s', $this->model['methods'][ $k ]['file'], $toks[ $i ]->line, $k );
				$cur = array();
			}
			$st    = $this->statement( $toks, $i, $b, $k );
			$cur[] = $st;
			$i     = self::sig( $toks, $st['e'] + 1, $b );
		}
		if ( null !== $cur ) {
			$out[] = $cur;
		}
		return $out;
	}

	/**
	 * The way to the innermost statement around a token: per level the block,
	 * the index in it, and how the next level lies in that statement (via
	 * body or inner, with its number n); the last level has via null.
	 *
	 * @param list<array<string,mixed>> $stmts Statements.
	 * @return list<array<string,mixed>>
	 */
	private function path( array $stmts, int $pos ): array {
		foreach ( $stmts as $i => $st ) {
			if ( $pos < $st['s'] || $pos > $st['e'] ) {
				continue;
			}
			foreach ( array( 'bodies' => 'body', 'inner' => 'inner' ) as $field => $via ) {
				foreach ( $st[ $field ] as $n => $in ) {
					$sub = $in;
					if ( 'inner' === $via ) {
						$sub = $in['stmts'];
					}
					$deeper = $this->path( $sub, $pos );
					if ( array() !== $deeper ) {
						return array_merge(
							array(
								array(
									'block' => $stmts,
									'i'     => $i,
									'via'   => $via,
									'n'     => $n,
								),
							),
							$deeper
						);
					}
				}
			}
			return array(
				array(
					'block' => $stmts,
					'i'     => $i,
					'via'   => null,
					'n'     => 0,
				),
			);
		}
		return array();
	}

	/**
	 * The innermost statement around a token of a function of G1, or null.
	 *
	 * @return ?array<string,mixed>
	 */
	private function statement_at( string $k, int $pos ): ?array {
		$path = $this->path( $this->trees[ $k ], $pos );
		if ( array() === $path ) {
			return null;
		}
		$last = $path[ count( $path ) - 1 ];
		return $last['block'][ $last['i'] ];
	}

	/* ---------- Tokens ---------- */

	/**
	 * The text of the tokens $a to $b without space and comments.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function norm( array $toks, ?int $a, ?int $b ): string {
		if ( null === $a || null === $b ) {
			return '';
		}
		$s = '';
		for ( $j = $a; $j <= $b; $j++ ) {
			if ( ! in_array( $toks[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$s .= $toks[ $j ]->text;
			}
		}
		return $s;
	}

	/**
	 * The first token at or after $i (at most $b) that is no space or comment.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function sig( array $toks, int $i, int $b ): ?int {
		for ( $j = $i; $j <= $b; $j++ ) {
			if ( ! in_array( $toks[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	/** @param list<\PhpToken> $toks Tokens. */
	private static function next( array $toks, ?int $i ): ?int {
		if ( null === $i ) {
			return null;
		}
		return self::sig( $toks, $i + 1, count( $toks ) - 1 );
	}

	/** @param list<\PhpToken> $toks Tokens. */
	private static function prev( array $toks, ?int $i ): ?int {
		if ( null === $i ) {
			return null;
		}
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! in_array( $toks[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	/**
	 * The token that closes the bracket at $i.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function closing( array $toks, ?int $i ): int {
		$n = count( $toks );
		if ( null === $i ) {
			return $n - 1;
		}
		$d = 0;
		for ( $j = $i; $j < $n; $j++ ) {
			$x = $toks[ $j ]->text;
			if ( in_array( $x, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $toks[ $j ]->id || T_DOLLAR_OPEN_CURLY_BRACES === $toks[ $j ]->id ) {
				++$d;
			} elseif ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				--$d;
				if ( 0 === $d ) {
					return $j;
				}
			}
		}
		return $n - 1;
	}

	/**
	 * The token that opens the bracket closed at $i.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function opening( array $toks, int $i ): int {
		$d = 0;
		for ( $j = $i; $j >= 0; $j-- ) {
			$x = $toks[ $j ]->text;
			if ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				++$d;
			} elseif ( in_array( $x, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $toks[ $j ]->id || T_DOLLAR_OPEN_CURLY_BRACES === $toks[ $j ]->id ) {
				--$d;
				if ( 0 === $d ) {
					return $j;
				}
			}
		}
		return 0;
	}
}
