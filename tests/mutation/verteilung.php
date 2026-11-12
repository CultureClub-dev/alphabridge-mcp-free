<?php
/**
 * How the mutation entries are shared out among the jobs of the CI
 * (tests/mutation/run.php --shard=k/n), and where the durations come from.
 *
 * The cost of a shard is what its job runs: the mutation of each of its
 * entries, plus the baseline of each distinct command once (the runner runs
 * the baseline once per identical 'run' array and shard). So the durations
 * are kept per entry id (mutation) and per whole command array (baseline),
 * in tests/mutation/dauer.php, written by tests/mutation/dauer-import.php
 * from the log of a green CI run.
 *
 * The sharing (assign()): the entries of one command form a group; the
 * groups go, longest first, each to the shard with the least so far. A group
 * longer than the ideal shard is cut into parts where that makes the longest
 * shard shorter (each part runs the baseline once more). Every order is
 * fixed by the costs and then by the command and the ids, never by the order
 * in which lists and entries were loaded, so the same entries and durations
 * give the same shards on every machine. What the measurement does not know
 * is reckoned as the longest measured of its like (estimates()).
 *
 * A separate file so that its clauses can be mutated (the runner refuses
 * files under tests/ but this one and the helpers of the integration suite;
 * tests/mutation/lists/verteilung.php). All times in tenths of a second, as
 * integers: sums and ties are exact.
 *
 * @package AlphaBridge_MCP
 */

final class AB_Mutation_Plan {

	/**
	 * Tenths of a second for the mutation of an entry of a kind (unit,
	 * integration) of which the measure file has no value at all; otherwise
	 * estimates() takes the longest measured of its like. The longest of the
	 * kind in run 43235297215 (main bf56447), rounded up to whole seconds:
	 * unit 31.3 s, integration 32.0 s.
	 */
	const FALLBACK_MUTATION = array(
		'unit'        => 320,
		'integration' => 320,
	);

	/**
	 * Tenths of a second for the baseline of a new command of a kind of which
	 * the measure file has no baseline at all. The longest of the kind in run
	 * 43235297215, rounded up: unit 3.7 s, integration 23.6 s.
	 */
	const FALLBACK_BASELINE = array(
		'unit'        => 40,
		'integration' => 240,
	);

	/**
	 * How several measurements of one baseline (one per shard that ran the
	 * command) become one value.
	 */
	const RULE = 'median; of an even number the mean of the middle two, rounded up to 0.1 s';

	/**
	 * The key of a command: its whole array, so that the unit and the
	 * integration run of the same filter are two commands.
	 *
	 * @param string[] $run Command.
	 * @return string
	 */
	public static function key( array $run ) {
		return (string) json_encode( array_values( $run ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The kind of a command: the integration suite (its configuration named)
	 * or the unit suite (any other command).
	 *
	 * @param string[] $run Command.
	 * @return string 'integration' or 'unit'.
	 */
	public static function kind( array $run ) {
		return in_array( 'tests/integration/phpunit.xml.dist', $run, true ) ? 'integration' : 'unit';
	}

	/**
	 * What each entry and each command is reckoned to cost. A measured one its
	 * value; one added after the measurement the longest measured of its like,
	 * so that nothing new is reckoned short (it costs a little evenness):
	 * - the mutation of a new entry of a measured command: the longest
	 *   measured mutation of that command;
	 * - the mutation of an entry of a new command: the longest measured
	 *   mutation of its kind;
	 * - the baseline of a new command: the longest measured baseline of its
	 *   kind;
	 * - a kind of which the file has no value: FALLBACK_MUTATION,
	 *   FALLBACK_BASELINE.
	 * A measured mutation counts for the command and kind the lists give its
	 * entry now.
	 *
	 * @param array[] $entries  Entries.
	 * @param array   $measures Durations.
	 * @return array{mutation:array<int,int>,baseline:array<string,int>} Index => tenths, command key => tenths.
	 */
	public static function estimates( array $entries, array $measures ) {
		$entries    = array_values( $entries );
		$by_command = array();
		$by_kind    = array();
		$base_kind  = array();
		foreach ( $entries as $entry ) {
			if ( isset( $measures['mutation'][ $entry['id'] ] ) ) {
				$key                = self::key( $entry['run'] );
				$kind               = self::kind( $entry['run'] );
				$tenths             = $measures['mutation'][ $entry['id'] ];
				$by_command[ $key ] = max( $by_command[ $key ] ?? 0, $tenths );
				$by_kind[ $kind ]   = max( $by_kind[ $kind ] ?? 0, $tenths );
			}
		}
		foreach ( $measures['grundlinie'] as $key => $tenths ) {
			$kind               = self::kind( (array) json_decode( (string) $key, true ) );
			$base_kind[ $kind ] = max( $base_kind[ $kind ] ?? 0, $tenths );
		}
		$out = array(
			'mutation' => array(),
			'baseline' => array(),
		);
		foreach ( $entries as $i => $entry ) {
			$key                     = self::key( $entry['run'] );
			$kind                    = self::kind( $entry['run'] );
			$out['mutation'][ $i ]   = $measures['mutation'][ $entry['id'] ] ?? $by_command[ $key ] ?? $by_kind[ $kind ] ?? self::FALLBACK_MUTATION[ $kind ];
			$out['baseline'][ $key ] = $measures['grundlinie'][ $key ] ?? $base_kind[ $kind ] ?? self::FALLBACK_BASELINE[ $kind ];
		}
		return $out;
	}

	/* ------------------------------------------------------------ sharing */

	/**
	 * The shard of every entry.
	 *
	 * @param array[] $entries  Entries (id, run), in any order.
	 * @param array   $measures Durations (load_measures()).
	 * @param int     $n        Number of shards.
	 * @return array<int,int> Index of the entry => shard 1..n.
	 */
	public static function assign( array $entries, array $measures, $n ) {
		$entries  = array_values( $entries );
		$estimate = self::estimates( $entries, $measures );
		$groups   = array();
		$total    = 0;
		$top      = 0;
		foreach ( $entries as $i => $entry ) {
			$key = self::key( $entry['run'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'baseline' => $estimate['baseline'][ $key ],
					'members'  => array(),
					'sum'      => 0,
				);
				$total         += $groups[ $key ]['baseline'];
			}
			$cost                        = $estimate['mutation'][ $i ];
			$groups[ $key ]['members'][] = array( $i, $entry['id'], $cost );
			$groups[ $key ]['sum']      += $cost;
			$total                      += $cost;
			$top                         = max( $top, $groups[ $key ]['baseline'] + $cost );
		}

		// The ideal shard: no shard can be shorter than an n-th of the whole,
		// nor than its longest entry with that entry's baseline.
		$ideal = max( intdiv( $total + $n - 1, $n ), $top );

		$whole = array();
		$cut   = array();
		foreach ( $groups as $key => $group ) {
			$whole[] = array( $group['baseline'] + $group['sum'], (string) $key, $group['members'] );
			if ( $group['baseline'] + $group['sum'] <= $ideal ) {
				$cut[] = end( $whole );
				continue;
			}
			// Too long for one shard: parts that each fit, every part with
			// the baseline once more. The ideal holds the baseline with the
			// longest entry of the group, so the room beside the baseline is
			// at least that entry (and above 0, the group having one longer
			// than 0): no more parts than entries.
			$members = $group['members'];
			usort( $members, array( __CLASS__, 'longer_first' ) );
			$count = (int) ceil( $group['sum'] / ( $ideal - $group['baseline'] ) );
			$parts = array_fill( 0, $count, array() );
			$loads = array_fill( 0, $count, 0 );
			foreach ( $members as $member ) {
				$at             = self::least( $loads );
				$parts[ $at ][] = $member;
				$loads[ $at ]  += $member[2];
			}
			foreach ( $parts as $part => $those ) {
				$cut[] = array( $group['baseline'] + $loads[ $part ], (string) $key, $those );
			}
		}

		// A part costs its baseline once more: the commands stay whole unless
		// cutting them makes the longest shard shorter.
		$shards = self::place( $whole, $n );
		if ( count( $cut ) > count( $whole ) ) {
			$cut_shards = self::place( $cut, $n );
			if ( max( array_column( self::costs( $entries, $cut_shards, $measures, $n ), 'cost' ) ) < max( array_column( self::costs( $entries, $shards, $measures, $n ), 'cost' ) ) ) {
				$shards = $cut_shards;
			}
		}
		return $shards;
	}

	/**
	 * Units (cost, command, members), longest first, each to the shard with
	 * the least so far.
	 *
	 * @param array[] $units Units.
	 * @param int     $n     Number of shards.
	 * @return array<int,int> Index of the entry => shard 1..n.
	 */
	private static function place( array $units, $n ) {
		// Longest first; of equal ones by the command. The parts of one
		// command keep the order they were made in (usort is stable).
		usort(
			$units,
			static function ( $a, $b ) {
				return $a[0] !== $b[0] ? $b[0] <=> $a[0] : strcmp( $a[1], $b[1] );
			}
		);
		$loads  = array_fill( 0, $n, 0 );
		$shards = array();
		foreach ( $units as $unit ) {
			$at            = self::least( $loads );
			$loads[ $at ] += $unit[0];
			foreach ( $unit[2] as $member ) {
				$shards[ $member[0] ] = $at + 1;
			}
		}
		ksort( $shards );
		return $shards;
	}

	/**
	 * The entries of shard k, in the order they were loaded.
	 *
	 * @param array[] $entries  Entries.
	 * @param array   $measures Durations.
	 * @param int     $k        Shard, 1..n.
	 * @param int     $n        Number of shards.
	 * @return array[]
	 */
	public static function shard( array $entries, array $measures, $k, $n ) {
		$entries = array_values( $entries );
		$shards  = self::assign( $entries, $measures, $n );
		$mine    = array();
		foreach ( $entries as $i => $entry ) {
			if ( $k === $shards[ $i ] ) {
				$mine[] = $entry;
			}
		}
		return $mine;
	}

	/**
	 * The sharing of the runner before: every n-th entry from the k-th, in
	 * the order loaded. For the comparison only.
	 *
	 * @param array[] $entries Entries, in the order loaded.
	 * @param int     $n       Number of shards.
	 * @return array<int,int> Index => shard 1..n.
	 */
	public static function round_robin( array $entries, $n ) {
		$shards = array();
		foreach ( array_keys( array_values( $entries ) ) as $i ) {
			$shards[ $i ] = $i % $n + 1;
		}
		return $shards;
	}

	/**
	 * What each shard costs: its mutations and the baseline of each of its
	 * commands once.
	 *
	 * @param array[]        $entries  Entries.
	 * @param array<int,int> $shards   Index => shard (assign(), round_robin()).
	 * @param array          $measures Durations.
	 * @param int            $n        Number of shards.
	 * @return array<int,array{cost:int,baselines:int,entries:int}> Shard 1..n.
	 */
	public static function costs( array $entries, array $shards, array $measures, $n ) {
		$entries  = array_values( $entries );
		$estimate = self::estimates( $entries, $measures );
		$out      = array();
		$seen     = array();
		for ( $k = 1; $k <= $n; $k++ ) {
			$out[ $k ]  = array( 'cost' => 0, 'baselines' => 0, 'entries' => 0 );
			$seen[ $k ] = array();
		}
		foreach ( $entries as $i => $entry ) {
			$k   = $shards[ $i ];
			$key = self::key( $entry['run'] );
			if ( ! isset( $seen[ $k ][ $key ] ) ) {
				$seen[ $k ][ $key ]      = true;
				$out[ $k ]['cost']      += $estimate['baseline'][ $key ];
				$out[ $k ]['baselines'] += 1;
			}
			$out[ $k ]['cost']    += $estimate['mutation'][ $i ];
			$out[ $k ]['entries'] += 1;
		}
		return $out;
	}

	/**
	 * The slot with the least load; of equal ones the first.
	 *
	 * @param int[] $loads Loads.
	 * @return int
	 */
	private static function least( array $loads ) {
		$at = 0;
		foreach ( $loads as $i => $load ) {
			if ( $load < $loads[ $at ] ) {
				$at = $i;
			}
		}
		return $at;
	}

	/**
	 * Longer first; of equal ones the lower id.
	 *
	 * @param array $a Member (index, id, cost).
	 * @param array $b Member.
	 * @return int
	 */
	private static function longer_first( array $a, array $b ) {
		return $a[2] !== $b[2] ? $b[2] <=> $a[2] : strcmp( $a[1], $b[1] );
	}

	/* ------------------------------------------------------- measure file */

	/**
	 * The measure file, checked. Missing or broken is an error: the runner
	 * stops rather than share out without durations.
	 *
	 * @param string $file File.
	 * @return array{herkunft:array,mutation:array<string,int>,grundlinie:array<string,int>}
	 * @throws RuntimeException What is wrong.
	 */
	public static function load_measures( $file ) {
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( "the measure file $file is missing" );
		}
		try {
			$data = ( static function ( $path ) {
				return include $path;
			} )( $file );
		} catch ( Throwable $e ) {
			throw new RuntimeException( "the measure file $file does not load (" . $e->getMessage() . ')' );
		}
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( "the measure file $file returns no array" );
		}
		foreach ( array( 'herkunft', 'mutation', 'grundlinie' ) as $part ) {
			if ( ! isset( $data[ $part ] ) || ! is_array( $data[ $part ] ) ) {
				throw new RuntimeException( "the measure file $file has no '$part'" );
			}
		}
		$from = $data['herkunft'];
		if ( ! isset( $from['quelle'] ) || ! is_string( $from['quelle'] ) || ! preg_match( '/^[0-9a-f]{40}$/D', $from['quelle'] ) ) {
			throw new RuntimeException( "the measure file $file names no source commit (herkunft.quelle)" );
		}
		foreach ( array( 'lauf', 'versuch' ) as $field ) {
			if ( ! isset( $from[ $field ] ) || ! is_int( $from[ $field ] ) || $from[ $field ] < 1 ) {
				throw new RuntimeException( "the measure file $file has no herkunft.$field" );
			}
		}
		$out = array(
			'herkunft'   => $from,
			'mutation'   => array(),
			'grundlinie' => array(),
		);
		foreach ( array( 'mutation', 'grundlinie' ) as $part ) {
			foreach ( $data[ $part ] as $key => $seconds ) {
				if ( ! is_string( $key ) || '' === $key ) {
					throw new RuntimeException( "the measure file $file has a key in '$part' that is no name" );
				}
				if ( 'grundlinie' === $part && ! is_array( json_decode( $key, true ) ) ) {
					throw new RuntimeException( "the measure file $file has a baseline whose key is no command array: $key" );
				}
				if ( ! ( is_float( $seconds ) || is_int( $seconds ) ) || ! is_finite( (float) $seconds ) || $seconds < 0 ) {
					throw new RuntimeException( "the measure file $file has no valid duration for $key" );
				}
				$out[ $part ][ $key ] = (int) round( $seconds * 10 );
			}
		}
		return $out;
	}

	/**
	 * The source of a measure file.
	 *
	 * @param array $measures Durations (mutation, grundlinie: tenths) and herkunft.
	 * @return string PHP.
	 */
	public static function export( array $measures ) {
		$out   = array();
		$out[] = '<?php';
		$out[] = '/**';
		$out[] = ' * Measured durations of the mutation entries, for --shard of';
		$out[] = ' * tests/mutation/run.php (tests/mutation/verteilung.php): seconds of the';
		$out[] = ' * mutation run per entry id, of the baseline per whole command. Written by';
		$out[] = ' * tests/mutation/dauer-import.php from the log of a green CI run (herkunft);';
		$out[] = ' * do not change by hand, import anew.';
		$out[] = ' *';
		$out[] = ' * @package AlphaBridge_MCP';
		$out[] = ' */';
		$out[] = '';
		$out[] = 'return array(';
		$out[] = "\t'herkunft'   => array(";
		foreach ( $measures['herkunft'] as $field => $value ) {
			$out[] = "\t\t" . var_export( (string) $field, true ) . ' => ' . var_export( $value, true ) . ',';
		}
		$out[] = "\t),";
		foreach ( array( 'mutation', 'grundlinie' ) as $part ) {
			$out[] = "\t" . str_pad( var_export( $part, true ), 12 ) . ' => array(';
			$values = $measures[ $part ];
			ksort( $values, SORT_STRING );
			foreach ( $values as $key => $tenths ) {
				$out[] = "\t\t" . var_export( (string) $key, true ) . ' => ' . sprintf( '%d.%d', intdiv( $tenths, 10 ), $tenths % 10 ) . ',';
			}
			$out[] = "\t),";
		}
		$out[] = ');';
		return implode( "\n", $out ) . "\n";
	}

	/* ------------------------------------------------------------- import */

	/**
	 * The entries of lists, for the import: id and command, nothing checked
	 * but that ids are unique (the runner checks the rest when it runs them).
	 *
	 * @param string[] $lists Files, in the order the runner loads them.
	 * @return array[]
	 * @throws RuntimeException A list is no list, an id twice.
	 */
	public static function list_entries( array $lists ) {
		$entries = array();
		$ids     = array();
		foreach ( $lists as $list ) {
			$found = ( static function ( $path ) {
				return require $path;
			} )( $list );
			if ( ! is_array( $found ) ) {
				throw new RuntimeException( "$list returns no list" );
			}
			foreach ( $found as $entry ) {
				if ( ! isset( $entry['id'], $entry['run'] ) || ! is_string( $entry['id'] ) || ! is_array( $entry['run'] ) ) {
					throw new RuntimeException( "$list: an entry has no id or no command" );
				}
				if ( isset( $ids[ $entry['id'] ] ) ) {
					throw new RuntimeException( "$list: the id {$entry['id']} is taken" );
				}
				$ids[ $entry['id'] ] = true;
				$entries[]           = array(
					'id'  => $entry['id'],
					'run' => $entry['run'],
				);
			}
		}
		return $entries;
	}

	/**
	 * The durations in the log of a CI run (gh run view <id> --log), checked
	 * against the entries of the lists at the source commit.
	 *
	 * The contract: every mutation job of the run is there (shards 1..n, each
	 * once) and reached its end (the runner's last line, and as many killed
	 * lines as it counts); each of them checked the source out; every
	 * expected id has exactly one mutation time, every expected command at
	 * least one baseline time; nothing in the log is not expected; a time
	 * that is no number is an error of its own (0.0 is one: the rounding).
	 * A missing measurement is an error here, never a default.
	 *
	 * @param string  $log     Log text.
	 * @param array[] $entries Entries of the lists at the source commit.
	 * @param string  $source  Source commit (40 hex digits).
	 * @return array{shards:int,mutation:array<string,int>,grundlinie:array<string,int>,merge_bases:string[]}
	 * @throws RuntimeException The log breaks the contract.
	 */
	public static function import( $log, array $entries, $source ) {
		if ( ! preg_match( '/^[0-9a-f]{40}$/D', $source ) ) {
			throw new RuntimeException( "the source $source is no full commit id" );
		}

		$expected = array();
		$commands = array();
		foreach ( $entries as $entry ) {
			$expected[ $entry['id'] ] = true;
			$key                      = self::key( $entry['run'] );
			$printed                  = implode( ' ', $entry['run'] );
			if ( isset( $commands[ $printed ] ) && $commands[ $printed ] !== $key ) {
				throw new RuntimeException( "two commands are printed alike ($printed); their baselines cannot be told apart" );
			}
			$commands[ $printed ] = $key;
		}

		// The lines of each job, without the time stamp of the runner.
		$jobs = array();
		foreach ( preg_split( '/\r?\n/', (string) $log ) as $line ) {
			$cols = explode( "\t", $line, 3 );
			if ( 3 !== count( $cols ) ) {
				continue;
			}
			$jobs[ $cols[0] ][] = (string) preg_replace( '/^\x{FEFF}?\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z ?/u', '', $cols[2] );
		}

		$shards      = array();
		$n           = null;
		$mutation    = array();
		$baselines   = array();
		$merge_bases = array();
		foreach ( $jobs as $job => $lines ) {
			$start = null;
			foreach ( $lines as $at => $text ) {
				if ( preg_match( '#^\#\#\[group\]Run php tests/mutation/run\.php --shard=([1-9][0-9]*)/([1-9][0-9]*)$#D', $text, $m ) ) {
					$start = $at;
					break;
				}
			}
			if ( null === $start ) {
				continue;
			}
			$k = (int) $m[1];
			if ( null === $n ) {
				$n = (int) $m[2];
			} elseif ( (int) $m[2] !== $n ) {
				throw new RuntimeException( "the jobs share into {$n} and into {$m[2]} shards" );
			}
			if ( isset( $shards[ $k ] ) ) {
				throw new RuntimeException( "shard $k/$n is in the log twice ($shards[$k], $job)" );
			}
			$shards[ $k ] = $job;

			// The source: the checkout of the plugin, the commit itself or
			// the merge of it a pull request runs. A push checks a branch out
			// and names its commit by git log, the whole id on the next line.
			$checked = false;
			$before  = '';
			foreach ( array_slice( $lines, 0, $start ) as $text ) {
				if ( preg_match( '/^HEAD is now at [0-9a-f]{7,40} Merge ' . $source . ' into ([0-9a-f]{40})$/D', $text, $hm ) ) {
					$merge_bases[ $hm[1] ] = true;
					$checked               = true;
				} elseif ( preg_match( '/^HEAD is now at ([0-9a-f]{7,40}) /', $text, $hm ) && 0 === strpos( $source, $hm[1] ) ) {
					$checked = true;
				} elseif ( $source === $text && preg_match( '#^\[command\]\S*git log -1 --format=%H$#D', $before ) ) {
					$checked = true;
				}
				$before = $text;
			}
			if ( ! $checked ) {
				throw new RuntimeException( "shard $k/$n did not check out the source $source" );
			}

			$killed = 0;
			$end    = null;
			foreach ( array_slice( $lines, $start + 1 ) as $text ) {
				if ( 0 === strpos( $text, '##[group]' ) ) {
					break;
				}
				if ( preg_match( '/^baseline\b/', $text ) ) {
					if ( ! preg_match( '/^baseline  green +(\S+) s  (.+)$/D', $text, $bm ) ) {
						throw new RuntimeException( "shard $k/$n: a baseline line out of form: $text" );
					}
					$tenths = self::tenths( $bm[1], "shard $k/$n, baseline {$bm[2]}" );
					if ( ! isset( $commands[ $bm[2] ] ) ) {
						throw new RuntimeException( "shard $k/$n ran a baseline the lists do not have: {$bm[2]}" );
					}
					$baselines[ $commands[ $bm[2] ] ][] = $tenths;
				} elseif ( preg_match( '/^(killed|SURVIVED|OTHER|TIMEOUT|INVALID)\b/', $text, $vm ) ) {
					if ( 'killed' !== $vm[1] ) {
						throw new RuntimeException( "shard $k/$n is not green: $text" );
					}
					if ( ! preg_match( '/^killed +(\S+) s  (\S+) — /', $text, $km ) ) {
						throw new RuntimeException( "shard $k/$n: a mutation line out of form: $text" );
					}
					$tenths = self::tenths( $km[1], "shard $k/$n, entry {$km[2]}" );
					if ( ! isset( $expected[ $km[2] ] ) ) {
						throw new RuntimeException( "shard $k/$n measured {$km[2]}, which the lists at the source do not have" );
					}
					if ( isset( $mutation[ $km[2] ] ) ) {
						throw new RuntimeException( "{$km[2]} is measured twice" );
					}
					$mutation[ $km[2] ] = $tenths;
					++$killed;
				} elseif ( preg_match( '/^all ([0-9]+) mutations killed; every file is back byte for byte$/D', $text, $em ) ) {
					$end = (int) $em[1];
				} elseif ( preg_match( '#^shard ([0-9]+)/([0-9]+) has no entry; the other shards run them all$#D', $text ) ) {
					$end = 0;
				}
			}
			if ( $end !== $killed ) {
				throw new RuntimeException( "shard $k/$n did not reach its end (" . ( null === $end ? 'no last line' : "$end counted, $killed killed lines" ) . ')' );
			}
		}

		if ( null === $n ) {
			throw new RuntimeException( 'the log has no mutation job' );
		}
		for ( $k = 1; $k <= $n; $k++ ) {
			if ( ! isset( $shards[ $k ] ) ) {
				throw new RuntimeException( "shard $k/$n is missing from the log" );
			}
		}
		$missing = array_keys( array_diff_key( $expected, $mutation ) );
		if ( array() !== $missing ) {
			throw new RuntimeException( count( $missing ) . ' entries of the source have no measurement: ' . implode( ', ', array_slice( $missing, 0, 5 ) ) . ( count( $missing ) > 5 ? ', …' : '' ) );
		}
		$grundlinie = array();
		foreach ( array_unique( $commands ) as $printed => $key ) {
			if ( ! isset( $baselines[ $key ] ) ) {
				throw new RuntimeException( "the baseline of $printed has no measurement" );
			}
			$grundlinie[ $key ] = self::median( $baselines[ $key ] );
		}
		ksort( $mutation, SORT_STRING );
		ksort( $grundlinie, SORT_STRING );
		$bases = array_keys( $merge_bases );
		sort( $bases, SORT_STRING );
		return array(
			'shards'      => $n,
			'mutation'    => $mutation,
			'grundlinie'  => $grundlinie,
			'merge_bases' => $bases,
		);
	}

	/**
	 * Several measurements of one baseline as one (RULE).
	 *
	 * @param int[] $values Tenths, at least one.
	 * @return int
	 */
	public static function median( array $values ) {
		sort( $values, SORT_NUMERIC );
		$count = count( $values );
		$mid   = intdiv( $count, 2 );
		if ( 1 === $count % 2 ) {
			return $values[ $mid ];
		}
		return intdiv( $values[ $mid - 1 ] + $values[ $mid ] + 1, 2 );
	}

	/**
	 * A time as the runner prints it (%5.1f), in tenths.
	 *
	 * @param string $text  Number.
	 * @param string $where For the message.
	 * @return int
	 * @throws RuntimeException No such number.
	 */
	private static function tenths( $text, $where ) {
		if ( ! preg_match( '/^([0-9]+)\.([0-9])$/D', $text, $m ) ) {
			throw new RuntimeException( "$where: the time '$text' is no valid duration" );
		}
		return (int) $m[1] * 10 + (int) $m[2];
	}
}
