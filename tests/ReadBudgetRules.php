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
 * Part 2.3a (Codex, round 1 over the guard, A1 to A4): every branch that
 * leaves at a halt answers it as prescribed (answers(); how, is since part
 * 2.3c the fixed list of whole forms below); after a function of FACHWERT only its
 * own forms count, the halt asked with the right polarity
 * (passes_fachwert(), halt_query()); the check of a pair is assigned whole
 * (guard_pair()); and an argument unpacked at or before the deadline is
 * UNSUPPORTED, a named one placed by its name (argument()).
 *
 * Part 2.3b (Codex, round 1 over the guard, A5, A6 and B1, and two of the
 * same kind as A2 and A4): the closed lists are compared counted (counted(),
 * named_diff(), FRAME_OUT with its numbers; the sources of the lists since
 * part 2.3c by list_counts(), the translations as entries of ANSWERS); a
 * halt after the return of a recursion is asked with the right
 * polarity (halt_asked_after(), asks_after()); a call whose arguments the
 * model does not hold (a callback, call_user_func_array(), new $x) does not
 * show its deadline left out, call_user_func() gives the arguments after its
 * first (argument()); and a helper of a sort has its callback (R5, the
 * reader).
 *
 * Part 2.3c (Codex, round 2 over the guard, no. 1 to 4; the decision of
 * 14.11. 21:01, option a): the answer to a halt is read against a fixed
 * list of whole forms without a free part (answers()): return $v; alone,
 * $v the variable that holds the halt; a whole sequence of statements of
 * the list ANSWERS of the function, token by token, with the number of its
 * places and its reason (the value of FACHWERT with its side value, a halt
 * query of the deadline, a translation as its whole call); or break; alone
 * in the pair of R2. There is no cleanup by rule before the return, no
 * answer by the name of a variable alone, no translation by a text that
 * occurs. A call of a function of FACHWERT returned whole is no form of R7
 * (E26 of part 2.3a is dropped). The sources of the lists are counted, not
 * compared: each array as written holds as many elements as PHP keeps of
 * it, its keys only string and integer literals (list_counts()). And, found
 * on the way and of the same kind as A2 of round 1: after a ternary the
 * halt itself is asked only with the right polarity (passes_value()).
 *
 * The boundary N7 of the order (section 3.2), word for word: «Dass eine
 * aufgezählte Form den Halt unverändert weitergibt, zeigt nicht der
 * Wächter, sondern der Nachweis je Form in Teil 7.» The guard reads the
 * spelling of the answer, not its value: that the halt arrives unchanged
 * is shown by the tests of part 7 that trigger it. For them check() gives,
 * for each branch at a halt it accepts, the function, the line and the
 * identifier of its form (halts; forms()): SAME, BREAK, or LISTED with the
 * tokens of the whole sequence, a side value of the answer in it.
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

	/** The halt query of the deadline: the halt it holds, or null. */
	private const STOP = 'AB_MCP_Sync_Deadline::stop';

	/** The identifier of the form return $v;, $v the variable that holds the halt. */
	public const FORM_SAME = 'SAME';

	/** The identifier of the form break; in the pair of R2 (the statement after the loop answers). */
	public const FORM_BREAK = 'BREAK';

	/** The start of the identifier of a form of the list ANSWERS; its tokens follow, each after a space. */
	public const FORM_LISTED = 'LISTED';

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

	/** @var array<string,true> The calls whose arguments were told UNSUPPORTED (once each). */
	private array $told = array();

	/** @var array<string,array{fn:string,file:string,line:int,pos:int,form:string}> The branches at a halt accepted, Class::name@token => where and in which form. */
	private array $halts = array();

	/** @var array<string,list<string>> The tokens of a text of a list (once each). */
	private array $spelled = array();

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
	 * @param array<string,mixed> $lists The lists of the rules (tests/ReadBudgetExceptions.php): ATOMIC, CHECKS, FACHWERT, ANSWERS, COMPARE, SORTS, FILTERS, FRAME_OUT.
	 * @return array{violations:list<array<string,mixed>>,problems:list<string>,named:array<string,mixed>,halts:list<array{fn:string,file:string,line:int,pos:int,form:string}>}
	 */
	public static function check( array $model, array $hand, array $soll, array $lists ): array {
		foreach ( array( 'ATOMIC', 'CHECKS', 'FACHWERT', 'ANSWERS', 'COMPARE', 'SORTS', 'FILTERS', 'FRAME_OUT' ) as $list ) {
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
			'halts'      => array_values( $rules->halts ),
		);
	}

	/**
	 * The forms of the branches at a halt that check() accepted (part 2.3c,
	 * the boundary N7): the identifier of each form with the number of its
	 * places, so that part 7 gives each form a test that triggers the halt
	 * and a mutation.
	 *
	 * @param list<array{fn:string,file:string,line:int,pos:int,form:string}> $halts What check() returned as halts.
	 * @return array<string,int>
	 */
	public static function forms( array $halts ): array {
		$out = array();
		foreach ( $halts as $halt ) {
			if ( ! isset( $out[ $halt['form'] ] ) ) {
				$out[ $halt['form'] ] = 0;
			}
			++$out[ $halt['form'] ];
		}
		ksort( $out );
		return $out;
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
		$out                         = array();
		list( $only_want, $only_have ) = self::counted( $want, $have );
		foreach ( $only_want as $x ) {
			$out[] = 'only in the list: ' . $x;
		}
		foreach ( $only_have as $x ) {
			$out[] = 'only in the code:  ' . $x . $where[ $x ];
		}
		return $out;
	}

	/**
	 * What only the list NAMED or only the code holds of the named boundaries:
	 * the sorts and filters by function and place in its order ('Class::name
	 * #n usort'), counted (Codex, round 1 over the guard, A5: an entry twice
	 * in the list or a second call in the code is a difference), and the counts
	 * of the chain with exactly the keys functions, steps, loops, recursions
	 * and why.
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
				foreach ( $entries as $n => $entry ) {
					$want[] = $fn . ' #' . ( $n + 1 ) . ' ' . $entry[0];
				}
			}
			$is = array();
			foreach ( $have[ $kind ] as $fn => $entries ) {
				foreach ( $entries as $n => $entry ) {
					$is[] = $fn . ' #' . ( $n + 1 ) . ' ' . $entry;
				}
			}
			list( $only_want, $only_have ) = self::counted( $want, $is );
			foreach ( $only_want as $x ) {
				$out[] = $kind . ' only in the list: ' . $x;
			}
			foreach ( $only_have as $x ) {
				$out[] = $kind . ' only in the code:  ' . $x;
			}
		}
		$keys = array_keys( $named['CHAIN'] );
		sort( $keys );
		if ( array( 'functions', 'loops', 'recursions', 'steps', 'why' ) !== $keys ) {
			$out[] = 'CHAIN: the keys of the list are ' . implode( ', ', $keys ) . ', not functions, loops, recursions, steps, why';
		}
		foreach ( $have['CHAIN'] as $count => $n ) {
			if ( ! array_key_exists( $count, $named['CHAIN'] ) || $named['CHAIN'][ $count ] !== $n ) {
				$listed = 'none';
				if ( array_key_exists( $count, $named['CHAIN'] ) ) {
					$listed = var_export( $named['CHAIN'][ $count ], true );
				}
				$out[] = sprintf( 'CHAIN %s: the list %s, the code %d', $count, $listed, $n );
			}
		}
		return $out;
	}

	/**
	 * What only $want or only $have holds, each as often as it is there more
	 * often (a multiset: Codex, round 1 over the guard, A5).
	 *
	 * @param list<string> $want Entries.
	 * @param list<string> $have Entries.
	 * @return array{0:list<string>,1:list<string>}
	 */
	public static function counted( array $want, array $have ): array {
		$count = array();
		$text  = array();
		foreach ( array( 1 => $want, -1 => $have ) as $sign => $list ) {
			foreach ( $list as $x ) {
				if ( ! isset( $count[ $x ] ) ) {
					$count[ $x ] = 0;
					$text[ $x ]  = $x;
				}
				$count[ $x ] += $sign;
			}
		}
		$out = array( array(), array() );
		foreach ( $count as $x => $c ) {
			for ( $j = 0; $j < abs( $c ); $j++ ) {
				$side           = $c > 0 ? 0 : 1;
				$out[ $side ][] = $text[ $x ];
			}
		}
		return $out;
	}

	/**
	 * What the source of a list of the guard (tests/ReadBudgetExceptions.php,
	 * tests/ReadBudgetSoll.php) says more than PHP keeps of it (part 2.3c;
	 * Codex, round 2 over the guard, no. 4): counted, not compared. The
	 * source is the return of one array and nothing else. Each array as
	 * written holds as many elements as count() of the array PHP made of it
	 * at the same place: of two elements with keys alike PHP keeps only the
	 * last, in whatever spelling ('x' and "x", 1 and '1'), so the list would
	 * say more than it holds. Where the numbers are alike no element is lost
	 * and the n-th element written is the n-th kept, and so on into it. A key
	 * is one string literal or one integer literal; a value is an array as
	 * written or tokens without an array and without an unpacking. Anything
	 * else is told, never skipped.
	 *
	 * @param string $php   The source of the list.
	 * @param mixed  $value What it returns (require).
	 * @return list<string>
	 */
	public static function list_counts( string $php, $value ): array {
		$toks = \PhpToken::tokenize( $php );
		$i    = self::sig( $toks, 0, count( $toks ) - 1 );
		if ( null !== $i && T_OPEN_TAG === $toks[ $i ]->id ) {
			$i = self::next( $toks, $i );
		}
		if ( null === $i || T_RETURN !== $toks[ $i ]->id ) {
			return array( 'the source does not begin with the return of its array' );
		}
		$a   = self::next( $toks, $i );
		$end = self::array_end( $toks, $a );
		if ( null === $a || null === $end ) {
			return array( sprintf( 'line %d: what the source returns is no array as written', $toks[ $i ]->line ) );
		}
		$out  = array();
		$semi = self::next( $toks, $end );
		if ( null === $semi || ';' !== $toks[ $semi ]->text || null !== self::next( $toks, $semi ) ) {
			$out[] = sprintf( 'line %d: the source holds more than the return of its array', $toks[ $end ]->line );
		}
		self::count_array( $toks, $a, $end, $value, $out );
		return $out;
	}

	/**
	 * The token that closes the array as written that begins at $a (array( …
	 * ) or [ … ]), or null where none begins there.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 */
	private static function array_end( array $toks, ?int $a ): ?int {
		if ( null === $a ) {
			return null;
		}
		if ( '[' === $toks[ $a ]->text ) {
			return self::closing( $toks, $a );
		}
		$open = self::next( $toks, $a );
		if ( T_ARRAY !== $toks[ $a ]->id || null === $open || '(' !== $toks[ $open ]->text ) {
			return null;
		}
		return self::closing( $toks, $open );
	}

	/**
	 * Counts the array as written from $a to $end against $value, what PHP
	 * holds at its place, and the arrays in it (list_counts()).
	 *
	 * @param list<\PhpToken> $toks  Tokens.
	 * @param mixed           $value What PHP holds at the place of the array.
	 * @param list<string>    $out   What is told.
	 */
	private static function count_array( array $toks, int $a, int $end, $value, array &$out ): void {
		$open = $a;
		if ( T_ARRAY === $toks[ $a ]->id ) {
			$open = self::next( $toks, $a );
		}
		// Its elements: the tokens between its brackets, split at the commas outside any bracket in them.
		$elements = array();
		$current  = array();
		$depth    = 0;
		for ( $j = $open + 1; $j < $end; $j++ ) {
			$t = $toks[ $j ];
			if ( in_array( $t->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( 0 === $depth && ',' === $t->text ) {
				$elements[] = $current;
				$current    = array();
				continue;
			}
			$depth    += self::bracket( $t );
			$current[] = $j;
		}
		if ( array() !== $current ) {
			$elements[] = $current;
		}
		if ( ! is_array( $value ) ) {
			$out[] = sprintf( 'line %d: an array as written where PHP holds none', $toks[ $a ]->line );
			return;
		}
		$kept = array_values( $value );
		$same = count( $elements ) === count( $kept );
		if ( ! $same ) {
			$out[] = sprintf( 'line %d: the array holds %d elements as written and %d as PHP keeps it (a key twice, in whatever spelling)', $toks[ $a ]->line, count( $elements ), count( $kept ) );
		}
		foreach ( $elements as $at => $element ) {
			$val   = $element;
			$depth = 0;
			foreach ( $element as $n => $j ) {
				if ( 0 === $depth && T_DOUBLE_ARROW === $toks[ $j ]->id ) {
					if ( 1 !== $n || ! in_array( $toks[ $element[0] ]->id, array( T_CONSTANT_ENCAPSED_STRING, T_LNUMBER ), true ) ) {
						$out[] = sprintf( 'line %d: a key that is no string literal and no integer literal: %s', $toks[ $j ]->line, self::norm( $toks, $element[0], $j - 1 ) );
					}
					$val = array_slice( $element, $n + 1 );
					break;
				}
				$depth += self::bracket( $toks[ $j ] );
			}
			if ( array() === $val ) {
				$out[] = sprintf( 'line %d: an element without a value', $toks[ $a ]->line );
				continue;
			}
			$first = $val[0];
			$last  = $val[ count( $val ) - 1 ];
			if ( self::array_end( $toks, $first ) === $last ) {
				if ( $same ) {
					self::count_array( $toks, $first, $last, $kept[ $at ], $out );
				}
				continue;
			}
			foreach ( $val as $j ) {
				if ( T_ARRAY === $toks[ $j ]->id || '[' === $toks[ $j ]->text || T_ELLIPSIS === $toks[ $j ]->id ) {
					$out[] = sprintf( 'line %d: an array or an unpacking in a value that is no array as written is not counted', $toks[ $j ]->line );
					break;
				}
			}
		}
	}

	/** What a token adds to the depth of brackets: 1 where it opens one, -1 where it closes one, else 0. */
	private static function bracket( \PhpToken $t ): int {
		if ( in_array( $t->text, array( '(', '[', '{' ), true ) || T_CURLY_OPEN === $t->id || T_DOLLAR_OPEN_CURLY_BRACES === $t->id ) {
			return 1;
		}
		if ( in_array( $t->text, array( ')', ']', '}' ), true ) ) {
			return -1;
		}
		return 0;
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
		uasort(
			$this->halts,
			static function ( array $a, array $b ): int {
				return array( $a['fn'], $a['pos'] ) <=> array( $b['fn'], $b['pos'] );
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
					if ( ! $this->s[ $k ] && ( isset( $e['step'] ) || 'STEP' === $e['k'] || $this->calls_into( $k, $e, $this->s ) ) ) {
						$this->s[ $k ] = true;
						$changed       = true;
					}
					if ( ! $this->ch[ $k ] && ( in_array( $e['k'], array( 'CHECK', 'STOP', 'HALT', 'STEP' ), true ) || $this->calls_into( $k, $e, $this->ch, true ) ) ) {
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
	private function calls_into( string $k, array $e, array $summary, bool $deadline = false ): bool {
		if ( 'CALL' !== $e['k'] ) {
			return false;
		}
		foreach ( $e['to'] as $to ) {
			if ( isset( $summary[ $to ] ) && true === $summary[ $to ] && ! ( $deadline && $this->without_deadline( $k, $e, $to ) ) ) {
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
			$begin = $this->begins_with_check( $k, $st['bodies'][0] );
			if ( null === $begin || ( 'break' === $begin['leave'] && ! $this->returns_after( $k, $e['pos'], $begin ) ) ) {
				$this->add( 'R2', $k, $e, $e['head'] );
			}
		}
	}

	/**
	 * Whether a body begins with a check whose answer leaves it at a halt as
	 * prescribed (answers()): if ( HALT ) return …, $v = CHECK; if ( HALT( $v
	 * ) ) return|break …, or $v = HALT ? X : …; if ( X === $v || … ) return …;
	 * how it leaves, and with a break the variable and form of the halt (a
	 * break only in the pair, where the halt is kept).
	 *
	 * @param list<array<string,mixed>> $body Statements.
	 * @return ?array{leave:string,var:?string,form:?string}
	 */
	private function begins_with_check( string $k, array $body ): ?array {
		$return = array(
			'leave' => 'return',
			'var'   => null,
			'form'  => null,
		);
		if ( array() === $body ) {
			return null;
		}
		if ( null !== $this->guard_if( $k, $body[0], true ) ) {
			return $return;
		}
		if ( ! isset( $body[1] ) ) {
			return null;
		}
		$pair = $this->guard_pair( $k, $body[0], $body[1], true, true );
		if ( null !== $pair ) {
			return $pair;
		}
		$tern = $this->ternary( $k, $body[0] );
		if ( null !== $tern && null !== $tern['var'] && $this->passes_value( $k, $body[1], $tern['var'], $tern['halt'] ) ) {
			return $return;
		}
		return null;
	}

	/**
	 * After a loop left by a break at a halt: whether the statement after the
	 * loop tests the same variable for its halt and returns as prescribed.
	 *
	 * @param array{leave:string,var:?string,form:?string} $begin What begins_with_check() found.
	 */
	private function returns_after( string $k, int $pos, array $begin ): bool {
		$next = $this->next_statement( $this->path( $this->trees[ $k ], $pos ) );
		return null !== $next && null !== $begin['var'] && null !== $begin['form'] && 'return' === $this->tests_value( $k, $next['st'], $begin['var'], $begin['form'], true, false );
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
	 * Whether a halt is asked as prescribed before the next work after a call
	 * returns (part 2.3b: the polarity of A2 of Codex, round 1 over the
	 * guard, applied to R3). In the rest of its statement only work counts,
	 * also a call around it, which runs after it (a halt query there is no
	 * halt asked after the return). Then the statements after it in its
	 * block and in the blocks around, up to a return, throw, break or
	 * continue, the end of a closure or of the function: a statement that asks
	 * the halt with the right polarity and answers it (asks_after()) before
	 * any work. A halt query in another form (the wrong polarity, a branch
	 * that does not answer) is no halt asked.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function halt_asked_after( string $k, array $e ): bool {
		$path = $this->path( $this->trees[ $k ], $e['pos'] );
		$last = count( $path ) - 1;
		$st   = $path[ $last ]['block'][ $path[ $last ]['i'] ];
		if ( $this->work_after( $k, $st['s'], $st['e'], $e ) ) {
			return false;
		}
		if ( in_array( $st['k'], self::ENDS, true ) ) {
			return true;
		}
		for ( $level = $last; $level >= 0; $level-- ) {
			$block = $path[ $level ]['block'];
			$count = count( $block );
			for ( $j = $path[ $level ]['i'] + 1; $j < $count; $j++ ) {
				if ( $this->asks_after( $k, $block, $j ) ) {
					return true;
				}
				if ( $this->work_in( $k, $block[ $j ]['s'], $block[ $j ]['e'] ) ) {
					return false;
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
	 * Whether work runs in the tokens $a to $b after a call returns: work that
	 * starts after it, or a call around it.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function work_after( string $k, int $a, int $b, array $e ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a || $x['pos'] > $b || $x['pos'] === $e['pos'] || ! $this->work( $x ) ) {
				continue;
			}
			if ( $x['pos'] > $e['pe'] || ( $x['pos'] < $e['pos'] && isset( $x['pe'] ) && $x['pe'] >= $e['pe'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the statement $j of a block asks the halt with the right
	 * polarity and answers it as prescribed: if ( … ) whose condition asks the
	 * halt (asks_halt()) with a branch that answers (answers()); $v = CHECK(
	 * … ); and then if ( HALT( $v ) ) answering (guard_pair()); $s =
	 * AB_MCP_Sync_Deadline::stop( … ); and then if ( null !== $s ) answering
	 * (tests_stop()); or $v = HALT ? X : …; and then an if that tests X and
	 * answers (passes_value()). A return in a ternary, return HALT ? X : …;,
	 * is no whole form of an answer (part 2.3c).
	 *
	 * @param list<array<string,mixed>> $block Statements.
	 */
	private function asks_after( string $k, array $block, int $j ): bool {
		$st = $block[ $j ];
		if ( 'if' === $st['k'] && 1 === count( $st['bodies'] ) && $this->asks_halt( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) && null !== $this->answers( $k, $st['bodies'][0], null, true, false ) ) {
			return true;
		}
		if ( isset( $block[ $j + 1 ] ) ) {
			if ( null !== $this->guard_pair( $k, $st, $block[ $j + 1 ], false, true ) ) {
				return true;
			}
			foreach ( $this->model['events'][ $k ] as $x ) {
				if ( 'STOP' !== $x['k'] || self::STOP !== $x['what'] || $x['pos'] < $st['s'] || $x['pos'] > $st['e'] ) {
					continue;
				}
				$s = $this->assigned( $k, $st, $x, true );
				if ( null !== $s && $this->tests_stop( $k, $block[ $j + 1 ], $s ) ) {
					return true;
				}
			}
		}
		$tern = $this->ternary( $k, $st );
		if ( null === $tern ) {
			return false;
		}
		if ( null === $tern['var'] ) {
			return false; // return HALT ? X : …: no whole form of an answer (part 2.3c).
		}
		return isset( $block[ $j + 1 ] ) && $this->passes_value( $k, $block[ $j + 1 ], $tern['var'], $tern['halt'] );
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
			// Only the callback, at its place among the arguments (also of a helper
			// of the steps, one place further: part 2.3b, Codex B1).
			$closures = array();
			$native   = null;
			$plugin   = false;
			if ( null !== $e['cb'] ) {
				$closures = $this->closures_in( $k, $e['cbs'] - 1, $e['cbe'] + 1 );
				if ( 1 === preg_match( "/^'\\\\?([a-z_][a-z0-9_]*)'$/i", $e['cb'], $mm ) ) {
					$native = strtolower( $mm[1] );
				}
				foreach ( $this->model['events'][ $k ] as $x ) {
					if ( 'CALL' === $x['k'] && $x['pos'] >= $e['cbs'] && $x['pos'] <= $e['cbe'] && 0 === strpos( $x['via'], 'Rückruf' ) ) {
						$plugin = true;
					}
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
		// A closure given to a function of PHP that calls no function it is given,
		// or to a helper of such a step (part 2.3b, Codex B1).
		foreach ( $this->model['events'][ $k ] as $e ) {
			if ( ( 'FN' !== $e['k'] && 'STEP' !== $e['k'] ) || isset( $e['cbtext'] ) || isset( $e['viadyn'] ) ) {
				continue;
			}
			$name = $e['what'];
			if ( 'STEP' === $e['k'] ) {
				$name = $this->hand['STEP_CLASS'] . '::' . $e['what'];
			}
			$cb = false;
			foreach ( $this->model['events'][ $k ] as $x ) {
				if ( 'CB' === $x['k'] && $x['pos'] === $e['pos'] ) {
					$cb = true;
				}
			}
			if ( ! $cb && array() !== $this->closures_in( $k, $e['pos'], $e['pe'] ) ) {
				$this->add( 'R5', $k, $e, 'closure given to ' . $name . ', which is no function of ITERCB' );
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
						$this->used['FRAME_OUT'][ $edge ][ $e['pos'] ] = true; // Its call sites, counted (A5).
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
				if ( isset( $this->model['chain'][ $to ] ) || isset( $this->hand['HELPERS'][ $to ] ) || ! isset( $this->ch[ $to ] ) || true !== $this->ch[ $to ] || $this->without_deadline( $k, $e, $to ) ) {
					continue;
				}
				if ( ! $this->passes_halt( $k, $e, $to ) ) {
					$this->add( 'R7', $k, $e, $to );
				}
			}
		}
	}

	/**
	 * Whether a call gives the function no deadline (E17): its parameter
	 * $deadline or $budget is shown left out (no positional argument there
	 * and no named one) or given as null. Then it gives no halt (without a
	 * deadline a check is the bare call, AB_MCP_Sync_Deadline::left()). An
	 * argument unpacked (...) at or before that parameter is UNSUPPORTED and
	 * counts as a deadline given (Codex, round 1 over the guard, A4).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function without_deadline( string $k, array $e, string $to ): bool {
		foreach ( $this->model['methods'][ $to ]['params'] as $p => $param ) {
			if ( ! in_array( $param['name'], array( '$deadline', '$budget' ), true ) ) {
				continue;
			}
			$arg = $this->argument( $k, $e, $to, $p, substr( $param['name'], 1 ) );
			return null !== $arg && ( '' === $arg || 'null' === strtolower( $arg ) );
		}
		return false;
	}

	/**
	 * The argument a call gives the parameter at $p named $name: its text
	 * (positional, or named name: …), '' where it is left out, or null where
	 * an argument is unpacked at or before it or the model does not hold the
	 * arguments of the call (a callback, call_user_func_array(), new $x; told
	 * UNSUPPORTED once). call_user_func() gives its target the arguments
	 * after its first (part 2.3b).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function argument( string $k, array $e, string $to, int $p, string $name ): ?string {
		$named = array();
		$via   = '';
		if ( isset( $e['via'] ) ) {
			$via = $e['via'];
		}
		// Part 2.3b: a call whose arguments the model does not hold: a callback
		// (its arguments are given by the function of PHP that calls it), the
		// arguments of call_user_func_array() in an array, new $x (read without
		// its arguments). Its deadline is not shown left out: UNSUPPORTED.
		if ( 0 === strpos( $via, 'Rückruf' ) || 'dynamisch (call_user_func_array)' === $via || 0 === strpos( $via, 'dynamisch (new ' ) ) {
			$this->tell( $k, $e, $to, sprintf( 'UNSUPPORTED %s:%d the arguments of a call %s are not read, so the parameter $%s of %s is not shown left out, in %s', $this->model['methods'][ $k ]['file'], $e['line'], $via, $name, $to, $k ) );
			return null;
		}
		$skip = 0;
		if ( 'dynamisch (call_user_func)' === $via ) {
			$skip = 1; // Its first argument is the function it calls.
		}
		foreach ( $e['args'] as $n => $arg ) {
			$i = $n - $skip;
			if ( $i < 0 ) {
				continue;
			}
			if ( 0 === strpos( $arg, '...' ) ) {
				if ( $i > $p ) {
					break; // PHP takes no positional argument after an unpacked one: $p is given before it.
				}
				$this->tell( $k, $e, $to, sprintf( 'UNSUPPORTED %s:%d arguments unpacked at or before the parameter $%s of %s in %s', $this->model['methods'][ $k ]['file'], $e['line'], $name, $to, $k ) );
				return null;
			}
			if ( 1 === preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)/', $arg, $m ) ) {
				$named[ $m[1] ] = trim( substr( $arg, strlen( $m[0] ) ) );
				continue;
			}
			if ( array() === $named && $i === $p ) {
				return $arg;
			}
		}
		if ( isset( $named[ $name ] ) ) {
			return $named[ $name ];
		}
		return '';
	}

	/**
	 * A problem of the arguments of a call, told once each.
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function tell( string $k, array $e, string $to, string $problem ): void {
		$key = $k . '|' . $e['pos'] . '|' . $to;
		if ( ! isset( $this->told[ $key ] ) ) {
			$this->told[ $key ] = true;
			$this->problems[]   = $problem;
		}
	}

	/**
	 * Whether the halt of a call is passed on. A function in FACHWERT first
	 * (Codex, round 1 over the guard, A2): see passes_fachwert(); a test of
	 * its value with is_wp_error() or a return of it does not do. Else the
	 * call is what its statement returns (whole, or a whole branch of a
	 * ternary), or it is assigned so to a variable and the next statement
	 * tests it with is_wp_error() or true !== and answers as prescribed
	 * (answers()). The next statement: the one after it, or after the if,
	 * block, try or switch it ends (not after a loop or a closure).
	 *
	 * @param array<string,mixed> $e The call.
	 */
	private function passes_halt( string $k, array $e, ?string $to ): bool {
		$path = $this->path( $this->trees[ $k ], $e['pos'] );
		$last = count( $path ) - 1;
		$st   = $path[ $last ]['block'][ $path[ $last ]['i'] ];
		$next = $this->next_statement( $path );
		if ( null !== $to && isset( $this->lists['FACHWERT'][ $to ] ) ) {
			$this->used['FACHWERT'][ $to ] = true;
			return $this->passes_fachwert( $k, $e, $st, $next );
		}
		if ( 'return' === $st['k'] && $this->is_value( $k, $e, $st['x'], $st['e'] - 1 ) ) {
			return true;
		}
		$var = $this->assigned( $k, $st, $e );
		return null !== $var && null !== $next && null !== $this->tests_value( $k, $next['st'], $var, self::STEP_FORM, false, false );
	}

	/**
	 * Whether the halt after a call of a function in FACHWERT is passed on:
	 * the next statement is an if whose condition asks the halt with the
	 * right polarity (asks_halt()) and whose branch answers as prescribed, or
	 * $s = AB_MCP_Sync_Deadline::stop( … ); and then if ( null !== $s )
	 * answering. A call in a return, throw, break or continue has no next
	 * statement that runs: the value of FACHWERT returned whole is no form
	 * (part 2.3c; Codex, round 2 over the guard, no. 3: E26 of part 2.3a,
	 * «returned whole by a function in FACHWERT with the same answer», is
	 * dropped, R7 by its wording).
	 *
	 * @param array<string,mixed>  $e    The call.
	 * @param array<string,mixed>  $st   Its statement.
	 * @param ?array<string,mixed> $next The next statement (next_statement()).
	 */
	private function passes_fachwert( string $k, array $e, array $st, ?array $next ): bool {
		if ( in_array( $st['k'], self::ENDS, true ) ) {
			return false;
		}
		// Part 2.3b (of the same kind as Codex A1 and A3): no work on the value
		// in its own statement before the halt is asked, such as a call around it.
		if ( null === $next || $this->work_after( $k, $st['s'], $st['e'], $e ) ) {
			return false;
		}
		$n = $next['st'];
		if ( 'if' === $n['k'] && 1 === count( $n['bodies'] ) ) {
			return $this->asks_halt( $k, $n['heads'][0][0] + 1, $n['heads'][0][1] - 1 ) && null !== $this->answers( $k, $n['bodies'][0], null, true, false );
		}
		if ( 'expr' !== $n['k'] || ! isset( $next['block'][ $next['i'] + 1 ] ) ) {
			return false;
		}
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( 'STOP' === $x['k'] && self::STOP === $x['what'] && $x['pos'] >= $n['s'] && $x['pos'] <= $n['e'] ) {
				$s = $this->assigned( $k, $n, $x, true );
				return null !== $s && $this->tests_stop( $k, $next['block'][ $next['i'] + 1 ], $s );
			}
		}
		return false;
	}

	/**
	 * Whether the condition (tokens $a to $b) asks the halt with the right
	 * polarity: one of its top-level disjuncts is a halt query (halt_query()),
	 * those before it pure.
	 */
	private function asks_halt( string $k, int $a, int $b ): bool {
		foreach ( $this->disjuncts( $k, $a, $b ) as $d ) {
			if ( $this->halt_query( $k, $d[0], $d[1] ) ) {
				return true;
			}
			if ( ! $this->pure( $k, $d[0], $d[1] ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Whether the tokens $a to $b are, whole, true exactly at a halt: the halt
	 * test of a check in its form (halt_test()); null !== STOP or STOP !==
	 * null with STOP a whole AB_MCP_Sync_Deadline::stop( D ); or that after
	 * null !== D && (without a deadline no halt, the pattern SH:389 of the
	 * order).
	 */
	private function halt_query( string $k, int $a, int $b ): bool {
		if ( null !== $this->halt_test( $k, $a, $b ) ) {
			return true;
		}
		$toks = $this->model['toks'][ $k ];
		$text = self::norm( $toks, $a, $b );
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( 'STOP' !== $x['k'] || self::STOP !== $x['what'] || $x['pos'] < $a || $x['pe'] > $b ) {
				continue;
			}
			$q     = self::norm( $toks, $this->start_of( $k, $x ), $x['pe'] );
			$forms = array( 'null!==' . $q, $q . '!==null' );
			if ( isset( $x['args'][0] ) ) {
				$d = preg_replace( '/\s+/', '', $x['args'][0] );
				foreach ( array( 'null!==' . $q, $q . '!==null' ) as $f ) {
					$forms[] = 'null!==' . $d . '&&' . $f;
					$forms[] = $d . '!==null&&' . $f;
				}
			}
			if ( in_array( $text, $forms, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an if with one branch tests a stop, null !== $s, and answers as
	 * prescribed.
	 *
	 * @param array<string,mixed> $st Statement.
	 */
	private function tests_stop( string $k, array $st, string $var ): bool {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) ) {
			return false;
		}
		$cond = self::norm( $this->model['toks'][ $k ], $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 );
		if ( 'null!==' . $var !== $cond && $var . '!==null' !== $cond ) {
			return false;
		}
		return null !== $this->answers( $k, $st['bodies'][0], $var, true, false );
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
	 * before it, a call around it after it): in the condition of an if after
	 * a halt test, its branch answering as prescribed (answers()); in a
	 * ternary assigned after its halt test, $v = HALT ? X : …;, its value at
	 * a halt tested by the next statement (a return in a ternary is no whole
	 * form of an answer, part 2.3c); or after a statement
	 * that checks and answers at a halt (if ( HALT ) return …; or $v = CHECK;
	 * if ( HALT( $v ) ) return …;, the check assigned whole) with statements
	 * without work between, also from the first statements of a then branch,
	 * a block or a try up to the statement around them. Not in the head of an
	 * elseif or of a loop other than foreach (asked at each pass).
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
		if ( 'if' === $st['k'] && $e['pos'] > $st['heads'][0][0] && $e['pos'] < $st['heads'][0][1] && 1 === count( $st['bodies'] ) ) {
			foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
				if ( $e['pos'] >= $d[0] && $e['pos'] <= $d[1] ) {
					break;
				}
				if ( null !== $this->halt_test( $k, $d[0], $d[1] ) ) {
					// The branch is asked for its form only at a halt test (part 2.3c: each branch accepted is kept as one at a halt).
					if ( null === $this->answers( $k, $st['bodies'][0], null, true, false ) ) {
						break;
					}
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
				return false; // return HALT ? X : …: no whole form of an answer (part 2.3c).
			}
			return isset( $block[ $i + 1 ] ) && $this->passes_value( $k, $block[ $i + 1 ], $tern['var'], $tern['halt'] );
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
			$check = $this->guard_if( $k, $block[ $j ], false );
			if ( null !== $check ) {
				if ( $this->work_in( $k, $check['pe'] + 1, $block[ $j ]['heads'][0][1] ) ) {
					return 'no';
				}
				return 'yes';
			}
			if ( $j >= 1 && null !== $this->guard_pair( $k, $block[ $j - 1 ], $block[ $j ], false, false ) ) {
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
	 * Whether the statement after a ternary passes its halt on: an if whose
	 * condition asks first, after pure disjuncts only, X === $v (the value
	 * at a halt) or the halt itself in a halt query with the right polarity
	 * (halt_query(); part 2.3c, of the same kind as Codex A2), and whose
	 * branch answers as prescribed (answers(); no break: the halt is not kept
	 * for after the loop).
	 *
	 * @param array<string,mixed> $st Statement.
	 */
	private function passes_value( string $k, array $st, string $var, string $halt ): bool {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) ) {
			return false;
		}
		$toks = $this->model['toks'][ $k ];
		foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
			$text = self::norm( $toks, $d[0], $d[1] );
			if ( $text === $halt . '===' . $var || $text === $var . '===' . $halt ) {
				return null !== $this->answers( $k, $st['bodies'][0], null, true, false );
			}
			// Part 2.3c (of the same kind as Codex A2): the halt itself only as a halt query with the right
			// polarity (halt_query()), the disjuncts before it pure, as in asks_halt(); a halt query in
			// another form is none.
			if ( $this->halt_query( $k, $d[0], $d[1] ) ) {
				return null !== $this->answers( $k, $st['bodies'][0], null, true, false );
			}
			if ( ! $this->pure( $k, $d[0], $d[1] ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * An if with one branch whose condition holds a halt test of a check as
	 * one of its disjuncts (those before it pure) and whose branch answers as
	 * prescribed (answers(); no break: the halt is not kept for after the
	 * loop): the check, or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @return ?array<string,mixed>
	 */
	private function guard_if( string $k, array $st, bool $step ): ?array {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) ) {
			return null;
		}
		foreach ( $this->disjuncts( $k, $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 ) as $d ) {
			$check = $this->halt_test( $k, $d[0], $d[1] );
			if ( null !== $check && ( $step || 'STEP' !== $check['k'] ) ) {
				if ( null === $this->answers( $k, $st['bodies'][0], null, true, false ) ) {
					return null;
				}
				return $check;
			}
			if ( ! $this->pure( $k, $d[0], $d[1] ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * $v = CHECK( … ); and then if ( HALT( $v ) ) answering (answers()). The
	 * check is assigned whole, so it runs on every way on (Codex, round 1
	 * over the guard, A3: a check in one branch of a ternary does not do);
	 * how the if leaves, the variable and the form of the check, or null.
	 *
	 * @param array<string,mixed> $a The assignment.
	 * @param array<string,mixed> $b The if.
	 * @return ?array{leave:string,var:string,form:string}
	 */
	private function guard_pair( string $k, array $a, array $b, bool $break, bool $step ): ?array {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a['s'] || $x['pos'] > $a['e'] ) {
				continue;
			}
			$is_check = 'CHECK' === $x['k'] || ( $step && 'STEP' === $x['k'] );
			if ( ! $is_check ) {
				continue;
			}
			$var = $this->assigned( $k, $a, $x, true );
			if ( null === $var ) {
				continue;
			}
			$form  = $this->form_of( $x );
			$leave = $this->tests_value( $k, $b, $var, $form, true, $break );
			if ( null === $leave ) {
				return null;
			}
			return array(
				'leave' => $leave,
				'var'   => $var,
				'form'  => $form,
			);
		}
		return null;
	}

	/**
	 * Whether an if with one branch tests a value for its halt in a form of
	 * FORMS as its whole condition and answers as prescribed (answers(): the
	 * value itself is the halt only in the form wp; only with $stop an entry
	 * of ANSWERS may hold a halt query of the deadline): how it leaves, or
	 * null.
	 *
	 * @param array<string,mixed> $st   Statement.
	 * @param string              $halt A form of FORMS.
	 */
	private function tests_value( string $k, array $st, string $var, string $halt, bool $stop, bool $break ): ?string {
		if ( 'if' !== $st['k'] || 1 !== count( $st['bodies'] ) || ! in_array( $halt, self::FORMS, true ) ) {
			return null;
		}
		$cond = self::norm( $this->model['toks'][ $k ], $st['heads'][0][0] + 1, $st['heads'][0][1] - 1 );
		if ( ! in_array( $cond, self::halt_forms( $var, $halt ), true ) ) {
			return null;
		}
		$held = null;
		if ( 'wp' === $halt ) {
			$held = $var;
		}
		return $this->answers( $k, $st['bodies'][0], $held, $stop, $break );
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
	 * return), the text of X, its tokens (hx) and the tokens of rest; or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @return ?array{var:?string,halt:string,hx:array{0:int,1:int},rest:array{0:int,1:int},check:array<string,mixed>}
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
					'hx'    => $tern[1],
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
			'hx'    => $tern[2],
			'rest'  => $tern[1],
			'check' => $check,
		);
	}

	/**
	 * The variable a statement $v = CALL; assigns the call to (as its value,
	 * is_value(); with $whole only the whole call, no branch of a ternary),
	 * or null.
	 *
	 * @param array<string,mixed> $st Statement.
	 * @param array<string,mixed> $e  The call.
	 */
	private function assigned( string $k, array $st, array $e, bool $whole = false ): ?string {
		if ( 'expr' !== $st['k'] ) {
			return null;
		}
		$toks = $this->model['toks'][ $k ];
		$eq   = self::next( $toks, $st['s'] );
		if ( T_VARIABLE !== $toks[ $st['s'] ]->id || null === $eq || '=' !== $toks[ $eq ]->text ) {
			return null;
		}
		$a = self::next( $toks, $eq );
		if ( $whole && ! $this->whole_call( $k, $e, $a, $st['e'] - 1 ) ) {
			return null;
		}
		if ( ! $this->is_value( $k, $e, $a, $st['e'] - 1 ) ) {
			return null;
		}
		return $toks[ $st['s'] ]->text;
	}

	/**
	 * Whether a branch that leaves at a halt answers it in one of the whole
	 * forms (part 2.3c, the boundary N7 of the order; Codex, round 2 over the
	 * guard, no. 1 and 2). Its statements are, token by token and with
	 * nothing else in the branch:
	 * - return $v; alone, $v the variable that holds the halt ($var): SAME;
	 * - a sequence of statements of the list ANSWERS of the function, LISTED:
	 *   expressions and then a return of a value, such as the value of
	 *   FACHWERT with its side value ($whole = null; return array();), a halt
	 *   query of the deadline or a translation as its whole call. An entry that names the
	 *   variable of the halt does only where the halt is held in it; a halt
	 *   query of the deadline in it only with $stop (not after a call whose
	 *   WP_Error may be another error, E27); and no entry holds work, a
	 *   check, a step of the helper, the clock or a call that can give a halt;
	 * - with $break, break; alone: BREAK (the loop is left; r2() asks the
	 *   statement after the loop, E25).
	 * No form has a free part: there is no cleanup by rule before the return
	 * (E24 of part 2.3a is dropped) and no answer by the name of a variable
	 * or by a text that occurs in it (E23). Each branch accepted is kept with
	 * the identifier of its form (halts), each entry of ANSWERS with its
	 * places. That the form passes the halt on unchanged is not shown here
	 * (N7: the tests of part 7).
	 *
	 * @param list<array<string,mixed>> $body Statements.
	 * @return ?string 'return' or 'break', or null.
	 */
	private function answers( string $k, array $body, ?string $var, bool $stop, bool $break ): ?string {
		$n = count( $body );
		if ( 0 === $n ) {
			return null;
		}
		$a    = $body[0]['s'];
		$b    = $body[ $n - 1 ]['e'];
		$have = self::texts( $this->model['toks'][ $k ], $a, $b );
		if ( array( 'break', ';' ) === $have ) {
			if ( ! $break ) {
				return null;
			}
			$this->halt( $k, $a, self::FORM_BREAK );
			return 'break';
		}
		if ( null !== $var && array( 'return', $var, ';' ) === $have ) {
			$this->halt( $k, $a, self::FORM_SAME );
			return 'return';
		}
		if ( ! isset( $this->lists['ANSWERS'][ $k ] ) || ! is_array( $this->lists['ANSWERS'][ $k ] ) ) {
			return null;
		}
		foreach ( $this->lists['ANSWERS'][ $k ] as $i => $entry ) {
			if ( ! self::is_answer( $entry ) || $this->spelling( $entry[0] ) !== $have ) {
				continue;
			}
			if ( null !== $entry[1] && $entry[1] !== $var ) {
				continue; // The halt is not held in the variable the entry names.
			}
			for ( $j = 0; $j < $n; $j++ ) {
				$kind = 'expr';
				if ( $j === $n - 1 ) {
					$kind = 'return';
				}
				if ( $kind !== $body[ $j ]['k'] ) {
					return null;
				}
			}
			if ( $body[ $n - 1 ]['x'] >= $body[ $n - 1 ]['e'] ) {
				return null; // A return without a value answers nothing.
			}
			if ( ! $this->quiet( $k, $a, $b, $stop ) ) {
				return null;
			}
			$this->used['ANSWERS'][ $k ][ $i ][ $a ] = true; // Its places, counted.
			if ( isset( $this->lists['FACHWERT'][ $k ] ) ) {
				$this->used['FACHWERT'][ $k ] = true;
			}
			$this->halt( $k, $a, self::FORM_LISTED . ' ' . implode( ' ', $have ) );
			return 'return';
		}
		return null;
	}

	/**
	 * Whether an entry of ANSWERS has its four parts: the statements, the
	 * variable of the halt it names (or null), the number of its places and
	 * the reason.
	 *
	 * @param mixed $entry Entry.
	 */
	private static function is_answer( $entry ): bool {
		return is_array( $entry ) && array( 0, 1, 2, 3 ) === array_keys( $entry ) && is_string( $entry[0] ) && ( null === $entry[1] || is_string( $entry[1] ) ) && is_int( $entry[2] ) && is_string( $entry[3] );
	}

	/**
	 * Whether the tokens $a to $b of a listed answer hold nothing that runs
	 * on after the halt: no work, no check, no step of the helper, no clock
	 * (AT) and no call that can give a halt with its deadline; and a halt
	 * query of the deadline only with $stop. This forbids only: what is
	 * allowed there is the entry of the list, token by token.
	 */
	private function quiet( string $k, int $a, int $b, bool $stop ): bool {
		foreach ( $this->model['events'][ $k ] as $x ) {
			if ( $x['pos'] < $a || $x['pos'] > $b ) {
				continue;
			}
			if ( $this->work( $x ) || in_array( $x['k'], array( 'CHECK', 'STEP', 'AT' ), true ) || $this->calls_into( $k, $x, $this->ch, true ) ) {
				return false;
			}
			if ( 'STOP' === $x['k'] && ! $stop ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Keeps a branch at a halt that is accepted, with the identifier of its
	 * form (part 2.3c, N7). A branch read in two forms is a problem.
	 */
	private function halt( string $k, int $pos, string $form ): void {
		$key  = $k . '@' . $pos;
		$file = $this->model['methods'][ $k ]['file'];
		$line = $this->model['toks'][ $k ][ $pos ]->line;
		if ( isset( $this->halts[ $key ] ) ) {
			if ( $form !== $this->halts[ $key ]['form'] ) {
				$this->problems[] = sprintf( 'ANSWERS %s:%d %s: the branch at a halt is read in two forms, %s and %s', $file, $line, $k, $this->halts[ $key ]['form'], $form );
			}
			return;
		}
		$this->halts[ $key ] = array(
			'fn'   => $k,
			'file' => $file,
			'line' => $line,
			'pos'  => $pos,
			'form' => $form,
		);
	}

	/**
	 * The tokens $a to $b, each as its text, without space and comments.
	 *
	 * @param list<\PhpToken> $toks Tokens.
	 * @return list<string>
	 */
	private static function texts( array $toks, int $a, int $b ): array {
		$out = array();
		for ( $j = $a; $j <= $b; $j++ ) {
			if ( ! in_array( $toks[ $j ]->id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out[] = $toks[ $j ]->text;
			}
		}
		return $out;
	}

	/**
	 * The tokens of a text of a list (statements or an expression of PHP),
	 * each as its text, without space and comments.
	 *
	 * @return list<string>
	 */
	private function spelling( string $php ): array {
		if ( ! isset( $this->spelled[ $php ] ) ) {
			$toks                  = \PhpToken::tokenize( '<?php ' . $php );
			$this->spelled[ $php ] = self::texts( $toks, 1, count( $toks ) - 1 );
		}
		return $this->spelled[ $php ];
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

	/**
	 * Each entry of a list used, as often as the list says (Codex, round 1
	 * over the guard, A5): an entry of ATOMIC, COMPARE, SORTS and FILTERS once
	 * in its list; FRAME_OUT with the number of its call sites, ANSWERS with
	 * the number of its places (answers_counted()). What the source of a list
	 * says more than PHP keeps of it is told by list_counts().
	 */
	private function unused(): void {
		foreach ( array( 'ATOMIC', 'COMPARE', 'SORTS', 'FILTERS' ) as $list ) {
			foreach ( array_count_values( $this->lists[ $list ] ) as $entry => $times ) {
				if ( $times > 1 ) {
					$this->problems[] = sprintf( '%s %d times: %s', $list, $times, $entry );
				}
			}
		}
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
		$counts = array(
			'FRAME_OUT' => array( 0, 'call sites' ),
		);
		foreach ( $counts as $list => list( $at, $what ) ) {
			foreach ( $this->lists[ $list ] as $entry => $listed ) {
				if ( ! isset( $this->used[ $list ][ $entry ] ) ) {
					continue;
				}
				$have = count( $this->used[ $list ][ $entry ] );
				if ( ! is_array( $listed ) || ! array_key_exists( $at, $listed ) ) {
					$this->problems[] = sprintf( '%s %s: no number of its %s in the list', $list, $entry, $what );
					continue;
				}
				if ( $listed[ $at ] !== $have ) {
					$this->problems[] = sprintf( '%s %s: %d %s, the list %s', $list, $entry, $have, $what, var_export( $listed[ $at ], true ) );
				}
			}
		}
		foreach ( $this->lists['COMPARE'] as $entry ) {
			if ( ! isset( $this->used['COMPARE'][ $entry ] ) ) {
				$this->problems[] = 'COMPARE not used: ' . $entry;
			}
		}
		$this->answers_counted();
	}

	/**
	 * The list ANSWERS closed and counted (part 2.3c): each entry with its
	 * four parts (the statements, the variable of the halt it names or null,
	 * the number of its places, the reason), none twice in its function,
	 * each the answer of as many branches at a halt as it says. In a function
	 * of FACHWERT each ends with the return of its value there: its callers
	 * ask the halt and read this value (R7).
	 */
	private function answers_counted(): void {
		foreach ( $this->lists['ANSWERS'] as $k => $entries ) {
			if ( ! is_array( $entries ) || array() === $entries ) {
				$this->problems[] = sprintf( 'ANSWERS %s: no entry', $k );
				continue;
			}
			$seen = array();
			$nr   = 0;
			foreach ( $entries as $i => $entry ) {
				++$nr;
				$name = sprintf( 'ANSWERS %s #%d', $k, $nr );
				if ( ! self::is_answer( $entry ) ) {
					$this->problems[] = $name . ': not the statements, the variable of the halt or null, the number of its places and the reason';
					continue;
				}
				$spelt = $this->spelling( $entry[0] );
				$text  = implode( ' ', $spelt );
				$key   = $text . ' | ' . var_export( $entry[1], true );
				if ( isset( $seen[ $key ] ) ) {
					$this->problems[] = sprintf( '%s: twice in its function: %s', $name, $text );
					continue;
				}
				$seen[ $key ] = true;
				$have         = 0;
				if ( isset( $this->used['ANSWERS'][ $k ][ $i ] ) ) {
					$have = count( $this->used['ANSWERS'][ $k ][ $i ] );
				}
				if ( 0 === $have ) {
					$this->problems[] = sprintf( 'ANSWERS not used: %s #%d %s', $k, $nr, $text );
				} elseif ( $entry[2] !== $have ) {
					$this->problems[] = sprintf( '%s: %d places, the list %d: %s', $name, $have, $entry[2], $text );
				}
				if ( isset( $this->lists['FACHWERT'][ $k ] ) ) {
					$value  = array_merge( array( 'return' ), $this->spelling( $this->lists['FACHWERT'][ $k ][0] ), array( ';' ) );
					$before = count( $spelt ) - count( $value );
					if ( $before < 0 || array_slice( $spelt, $before ) !== $value || ( $before > 0 && ';' !== $spelt[ $before - 1 ] ) ) {
						$this->problems[] = sprintf( '%s: in a function of FACHWERT the answer ends with the return of its value there (%s): %s', $name, $this->lists['FACHWERT'][ $k ][0], $text );
					}
				}
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
