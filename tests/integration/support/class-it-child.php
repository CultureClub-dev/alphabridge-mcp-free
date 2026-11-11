<?php
/**
 * The plan a child process runs (Z8b branch 1, points 4 and 5): seams with
 * their fault plans, aborts at hooks, then the calls in order. Used by
 * bin/site-call.php (a site's WordPress loaded) and bin/plain-call.php
 * (plain PHP, for the tests of the helpers themselves); started and read by
 * AB_MCP_IT_Process.
 *
 * Plan (JSON on stdin):
 *   marker  path of the marker file of an abort (AB_MCP_IT_Process::abort()
 *           sets it; without it no abort can fire)
 *   require files to load first (plain-call; absolute paths)
 *   seams   [ { owner, property, real (class of the real calls), faults } ]
 *   hooks   [ { hook, point, mode, nth, priority, seconds } ]  (WordPress)
 *   calls   [ { call: a function or Class::method, args, as, expect } ]
 *
 * A string "$name" anywhere in the args is the result of the call named so
 * before. A WP_Error is given as { wp_error: { code, message } }, a
 * Throwable that leaves a call as { throwable: { class, message } }; with
 * "expect": "result" either ends the run with status 1. The output is one
 * line of JSON after the line RESULT_LINE: { completed: true, results,
 * seams }, written with JSON_THROW_ON_ERROR (no partial output: a result
 * that is not valid UTF-8 ends the run as a failure; give bytes in base64).
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-test-abort.php';
require_once __DIR__ . '/class-test-seam.php';

/**
 * Class AB_MCP_IT_Child
 */
final class AB_MCP_IT_Child {

	/** The line before the JSON of the result. */
	const RESULT_LINE = '@@ab-it-child-result@@';

	/**
	 * Read the plan from stdin.
	 *
	 * @return array
	 */
	public static function plan() {
		$plan = json_decode( (string) stream_get_contents( STDIN ), true );
		if ( ! is_array( $plan ) ) {
			fwrite( STDERR, "child: no plan on stdin.\n" );
			exit( 2 );
		}
		return $plan;
	}

	/**
	 * Everything before the calls: marker, files, seams, hooks.
	 *
	 * @param array $plan Plan.
	 */
	public static function prepare( array $plan ) {
		if ( isset( $plan['marker'] ) ) {
			AB_MCP_Test_Abort::arm( (string) $plan['marker'] );
		}
		foreach ( isset( $plan['require'] ) ? (array) $plan['require'] : array() as $file ) {
			require_once (string) $file;
		}
		foreach ( isset( $plan['seams'] ) ? (array) $plan['seams'] : array() as $seam ) {
			AB_MCP_Test_Seam::install( (string) $seam['owner'], (string) $seam['property'], isset( $seam['real'] ) ? (string) $seam['real'] : null, isset( $seam['faults'] ) ? (array) $seam['faults'] : array() );
		}
		foreach ( isset( $plan['hooks'] ) ? (array) $plan['hooks'] : array() as $hook ) {
			AB_MCP_Test_Abort::at_hook(
				(string) $hook['hook'],
				(string) $hook['point'],
				(string) $hook['mode'],
				isset( $hook['nth'] ) ? (int) $hook['nth'] : 1,
				isset( $hook['priority'] ) ? (int) $hook['priority'] : PHP_INT_MIN,
				isset( $hook['seconds'] ) ? (int) $hook['seconds'] : AB_MCP_Test_Abort::SECONDS
			);
		}
	}

	/**
	 * Run the calls and print the result.
	 *
	 * @param array $plan Plan.
	 */
	public static function run( array $plan ) {
		$results = array();
		foreach ( isset( $plan['calls'] ) ? (array) $plan['calls'] : array() as $i => $call ) {
			$name = isset( $call['as'] ) ? (string) $call['as'] : 'call' . $i;
			$args = self::fill( isset( $call['args'] ) ? array_values( (array) $call['args'] ) : array(), $results );
			try {
				$out = call_user_func_array( self::callable( (string) $call['call'] ), $args );
			} catch ( Throwable $e ) {
				$out = array(
					'throwable' => array(
						'class'   => get_class( $e ),
						'message' => $e->getMessage(),
					),
				);
			}
			if ( class_exists( 'WP_Error', false ) && $out instanceof WP_Error ) {
				$out = array(
					'wp_error' => array(
						'code'    => $out->get_error_code(),
						'message' => $out->get_error_message(),
					),
				);
			}
			if ( isset( $call['expect'] ) && 'result' === $call['expect'] && is_array( $out ) && ( isset( $out['wp_error'] ) || isset( $out['throwable'] ) ) ) {
				fwrite( STDERR, "child: call $name failed: " . json_encode( $out ) . "\n" );
				exit( 1 );
			}
			$results[ $name ] = $out;
		}
		$json = json_encode(
			array(
				'completed' => true,
				'results'   => $results,
				'seams'     => AB_MCP_Test_Seam::recorded(),
			),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		echo "\n" . self::RESULT_LINE . "\n" . $json . "\n";
	}

	/**
	 * A callable from its name.
	 *
	 * @param string $name Function or Class::method.
	 * @return callable
	 */
	private static function callable( $name ) {
		$callable = false !== strpos( $name, '::' ) ? explode( '::', $name, 2 ) : $name;
		if ( ! is_callable( $callable ) ) {
			throw new InvalidArgumentException( "child: $name is not callable here." );
		}
		return $callable;
	}

	/**
	 * Put the results of earlier calls into the arguments.
	 *
	 * @param mixed $value   Value.
	 * @param array $results Results by name.
	 * @return mixed
	 */
	private static function fill( $value, array $results ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::fill( $v, $results );
			}
			return $value;
		}
		if ( is_string( $value ) && '$' === substr( $value, 0, 1 ) && array_key_exists( substr( $value, 1 ), $results ) ) {
			return $results[ substr( $value, 1 ) ];
		}
		return $value;
	}
}
