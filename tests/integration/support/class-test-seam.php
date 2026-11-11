<?php
/**
 * The common helper of the test seams (Z8b branch 1, point 5; plan 8.3,
 * conventions.md «Heikle Zweige …», point 2). Test code only.
 *
 * The pattern each heikel/ branch of Z8b follows: its calls to the outside
 * (rename(), writing a file, an option, an HTTP request …) go through one
 * object of a calls class, held by the class of the branch in a property
 * whose name begins with naht_ and is used for nothing else in the tree:
 *
 *     private static $naht_sync_code_swap = null;
 *
 *     private static function calls() {
 *         return self::$naht_sync_code_swap ?? new AB_MCP_Sync_Code_Swap_Calls();
 *     }
 *
 * (and `self::$naht_sync_code_swap = null;` where it goes back to the real
 * calls; no filter, no option, no switch, no type, so that a stand-in fits).
 * Only the tests put another object there, through Reflection: this helper.
 * SeamGuardTest holds the rules for every seam of the shipped code
 * (AB_MCP_Test_Seam_Guard).
 *
 * A fault plan names a method of the calls object and, if wanted, which call
 * of it (nth, from 1; without nth every call), and one kind of fault, for the
 * kinds of fault the plan lists:
 *
 *   return  give back 'value' (default false) without the real call:
 *           «Rückgabe false», and «Lesefehler» with false, '' or bad bytes
 *   throw   throw 'class' (a Throwable, default RuntimeException) with
 *           'message': «Throwable»
 *   abort   abort the process at this call, 'when' before (default) or
 *           after the real call, as 'point' in 'mode' (shutdown,
 *           no_shutdown, timeout; AB_MCP_Test_Abort), reached in 'phase'
 *           (request, default, or shutdown): «exit/Fatal» and «Zeitlimit»,
 *           in a process of its own only
 *   before  run 'before' first (a callable of any form, also an array
 *           callable; or a writer op or a list of them, see run_op(), as a
 *           plan for a child process gives it), then the real call:
 *           «gleichzeitiger Schreiber»
 *   half    with 'arg' (index): the real call with that argument cut to
 *           'keep' bytes, then give back 'value' (default false): «halb
 *           geschrieben»; without 'arg': the real call, its string result cut
 *           to 'keep' bytes: «halb gelesen»
 *
 * Every call is recorded (method, number, fault), for the assertions.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Seam
 */
final class AB_MCP_Test_Seam {

	/** The kinds of fault. */
	const KINDS = array( 'return', 'throw', 'abort', 'before', 'half' );

	/** @var array<string,array{owner:string,property:string,previous:mixed,proxy:AB_MCP_Test_Seam_Proxy}> Installed seams. */
	private static $installed = array();

	/**
	 * The property of a seam, checked: it exists, is private and static and
	 * has no type.
	 *
	 * @param string $owner    Class.
	 * @param string $property Property name, without $.
	 * @return ReflectionProperty
	 */
	public static function property( $owner, $property ) {
		if ( ! class_exists( $owner ) ) {
			throw new LogicException( "No class $owner for a seam." );
		}
		$class = new ReflectionClass( $owner );
		if ( ! $class->hasProperty( $property ) ) {
			throw new LogicException( "$owner has no property \$$property for a seam." );
		}
		$reflected = $class->getProperty( $property );
		if ( ! $reflected->isStatic() || ! $reflected->isPrivate() ) {
			throw new LogicException( "The seam $owner::\$$property must be private static: only Reflection from the tests may reach it." );
		}
		if ( $reflected->hasType() ) {
			throw new LogicException( "The seam $owner::\$$property has a type; it must have none, so that the stand-in of the tests fits." );
		}
		if ( 0 !== strpos( $property, 'naht_' ) ) {
			throw new LogicException( "The name of the seam $owner::\$$property must begin with naht_, a name used for nothing else in the tree (AB_MCP_Test_Seam_Guard)." );
		}
		// Reflection reaches a private property without setAccessible()
		// since PHP 8.1; the tests run on 8.2 and later.
		return $reflected;
	}

	/**
	 * Put a stand-in into a seam: a proxy over the real calls object that
	 * carries out the fault plan.
	 *
	 * @param string             $owner    Class.
	 * @param string             $property Property name, without $.
	 * @param object|string|null $real     The real calls object, or its class
	 *                                     (made with new), or null for the
	 *                                     object the seam holds now.
	 * @param array[]            $faults   Fault plan (see the head).
	 * @return AB_MCP_Test_Seam_Proxy
	 */
	public static function install( $owner, $property, $real = null, array $faults = array() ) {
		$reflected = self::property( $owner, $property );
		$previous  = $reflected->getValue();
		if ( is_string( $real ) ) {
			$real = new $real();
		} elseif ( null === $real ) {
			$real = $previous;
		}
		if ( ! is_object( $real ) || $real instanceof AB_MCP_Test_Seam_Proxy ) {
			throw new LogicException( "The seam $owner::\$$property needs the real calls object (or its class)." );
		}
		$proxy = new AB_MCP_Test_Seam_Proxy( $real, $faults );
		$key   = $owner . '::$' . $property;
		if ( ! isset( self::$installed[ $key ] ) ) {
			self::$installed[ $key ] = array(
				'owner'    => $owner,
				'property' => $property,
				'previous' => $previous,
			);
		}
		self::$installed[ $key ]['proxy'] = $proxy;
		$reflected->setValue( null, $proxy );
		return $proxy;
	}

	/**
	 * Put back what every seam held before install().
	 */
	public static function restore_all() {
		foreach ( self::$installed as $key => $seam ) {
			self::property( $seam['owner'], $seam['property'] )->setValue( null, $seam['previous'] );
			unset( self::$installed[ $key ] );
		}
	}

	/**
	 * The calls each installed seam recorded.
	 *
	 * @return array<string,array[]>
	 */
	public static function recorded() {
		$out = array();
		foreach ( self::$installed as $key => $seam ) {
			$out[ $key ] = $seam['proxy']->calls;
		}
		return $out;
	}

	/**
	 * Check a fault plan.
	 *
	 * @param array[] $faults Fault plan.
	 */
	public static function check_faults( array $faults ) {
		foreach ( $faults as $i => $fault ) {
			if ( ! is_array( $fault ) || empty( $fault['method'] ) || ! is_string( $fault['method'] ) ) {
				throw new InvalidArgumentException( "Fault $i names no method." );
			}
			if ( ! isset( $fault['kind'] ) || ! in_array( $fault['kind'], self::KINDS, true ) ) {
				throw new InvalidArgumentException( "Fault $i has no kind of " . implode( ', ', self::KINDS ) . '.' );
			}
			if ( isset( $fault['nth'] ) && ( ! is_int( $fault['nth'] ) || $fault['nth'] < 1 ) ) {
				throw new InvalidArgumentException( "Fault $i: nth counts from 1." );
			}
			if ( 'abort' === $fault['kind'] ) {
				if ( empty( $fault['point'] ) || ! is_string( $fault['point'] ) ) {
					throw new InvalidArgumentException( "Fault $i aborts without naming its point." );
				}
				AB_MCP_Test_Abort::check_mode( isset( $fault['mode'] ) ? $fault['mode'] : '' );
				if ( isset( $fault['when'] ) && ! in_array( $fault['when'], array( 'before', 'after' ), true ) ) {
					throw new InvalidArgumentException( "Fault $i: when is before or after." );
				}
				AB_MCP_Test_Abort::check_phase( $fault['mode'], isset( $fault['phase'] ) ? $fault['phase'] : 'request' );
			}
			if ( 'before' === $fault['kind'] && ! isset( $fault['before'] ) ) {
				throw new InvalidArgumentException( "Fault $i has no writer to run before the call." );
			}
			if ( 'half' === $fault['kind'] && ( ! isset( $fault['keep'] ) || ! is_int( $fault['keep'] ) || $fault['keep'] < 0 ) ) {
				throw new InvalidArgumentException( "Fault $i: half needs keep (bytes)." );
			}
		}
	}

	/**
	 * An op of a writer in a plan (JSON, for a child process): put_file
	 * (path, bytes base64), unlink (path), mkdir (path), rename (from, to),
	 * call (callable: a function or Class::method, args).
	 *
	 * @param array $op Op.
	 * @return mixed
	 */
	public static function run_op( array $op ) {
		switch ( isset( $op['op'] ) ? $op['op'] : '' ) {
			case 'put_file':
				return file_put_contents( (string) $op['path'], base64_decode( (string) $op['bytes'] ) );
			case 'unlink':
				return @unlink( (string) $op['path'] );
			case 'mkdir':
				return @mkdir( (string) $op['path'], 0755, true );
			case 'rename':
				return @rename( (string) $op['from'], (string) $op['to'] );
			case 'call':
				return call_user_func_array( (string) $op['callable'], isset( $op['args'] ) ? array_values( (array) $op['args'] ) : array() );
		}
		throw new InvalidArgumentException( 'No writer op ' . json_encode( $op ) . '.' );
	}
}

/**
 * Class AB_MCP_Test_Seam_Proxy: stands in for the calls object of a seam.
 */
final class AB_MCP_Test_Seam_Proxy {

	/** @var object The real calls object. */
	private $real;

	/** @var array[] Fault plan. */
	private $faults;

	/** @var array<string,int> Calls so far, by method. */
	private $counts = array();

	/** @var array[] Every call: method, n, fault (kind or null). */
	public $calls = array();

	/**
	 * @param object  $real   The real calls object.
	 * @param array[] $faults Fault plan.
	 */
	public function __construct( $real, array $faults ) {
		AB_MCP_Test_Seam::check_faults( $faults );
		$this->real   = $real;
		$this->faults = $faults;
	}

	/**
	 * A call of the product code.
	 *
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	public function __call( $method, array $args ) {
		if ( ! method_exists( $this->real, $method ) ) {
			throw new BadMethodCallException( get_class( $this->real ) . " has no method $method." );
		}
		$n                       = isset( $this->counts[ $method ] ) ? $this->counts[ $method ] + 1 : 1;
		$this->counts[ $method ] = $n;
		$fault                   = null;
		foreach ( $this->faults as $candidate ) {
			if ( $candidate['method'] === $method && ( ! isset( $candidate['nth'] ) || $candidate['nth'] === $n ) ) {
				$fault = $candidate;
				break;
			}
		}
		$this->calls[] = array(
			'method' => $method,
			'n'      => $n,
			'fault'  => null === $fault ? null : $fault['kind'],
		);
		if ( null === $fault ) {
			return $this->real->$method( ...$args );
		}
		switch ( $fault['kind'] ) {
			case 'return':
				return array_key_exists( 'value', $fault ) ? $fault['value'] : false;
			case 'throw':
				$class = isset( $fault['class'] ) ? $fault['class'] : 'RuntimeException';
				if ( ! is_a( $class, 'Throwable', true ) ) {
					throw new LogicException( "$class is no Throwable." );
				}
				throw new $class( isset( $fault['message'] ) ? (string) $fault['message'] : "Fault injected into $method (call $n)." );
			case 'abort':
				$seconds = isset( $fault['seconds'] ) ? (int) $fault['seconds'] : AB_MCP_Test_Abort::SECONDS;
				if ( isset( $fault['when'] ) && 'after' === $fault['when'] ) {
					$this->real->$method( ...$args );
				}
				AB_MCP_Test_Abort::fire( $fault['point'], $fault['mode'], $seconds );
				return null; // Not reached.
			case 'before':
				// A callable in any form (a closure, a function name, an array
				// callable [$object, 'method'] or [Class::class, 'method']);
				// a writer op or a list of them is an array, never callable.
				if ( is_callable( $fault['before'] ) ) {
					call_user_func( $fault['before'], $method, $args );
				} else {
					foreach ( isset( $fault['before']['op'] ) ? array( $fault['before'] ) : (array) $fault['before'] as $op ) {
						AB_MCP_Test_Seam::run_op( (array) $op );
					}
				}
				return $this->real->$method( ...$args );
			case 'half':
				if ( isset( $fault['arg'] ) ) {
					$args[ (int) $fault['arg'] ] = substr( (string) $args[ (int) $fault['arg'] ], 0, $fault['keep'] );
					$this->real->$method( ...$args );
					return array_key_exists( 'value', $fault ) ? $fault['value'] : false;
				}
				$result = $this->real->$method( ...$args );
				return is_string( $result ) ? substr( $result, 0, $fault['keep'] ) : $result;
		}
		throw new LogicException( "No fault kind {$fault['kind']}." );
	}
}
