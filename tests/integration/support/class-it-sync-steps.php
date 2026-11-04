<?php
/**
 * Steps on a site in a process of its own (bin/sync-site.php), so that each
 * site computes its canonical form with its own WordPress: site B always,
 * site A where a test wants it unaffected by what this process loaded.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_IT_Sync_Steps
 */
final class AB_MCP_IT_Sync_Steps {

	/**
	 * Run steps on a site.
	 *
	 * @param string $key   Site.
	 * @param array  $steps Steps (see bin/sync-site.php).
	 * @return array Results by name.
	 */
	public static function run( $key, array $steps ) {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/bin/sync-site.php', AB_MCP_IT_Sites::state_file(), (string) $key );
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( "Could not start the steps on site $key." );
		}
		fwrite( $pipes[0], (string) json_encode( $steps ) );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		if ( 0 !== $code ) {
			throw new RuntimeException( "The steps on site $key failed ($code): $err$out" );
		}
		$decoded = json_decode( (string) $out, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( "The steps on site $key gave no JSON: $err$out" );
		}
		return $decoded;
	}

	/**
	 * The addresses of both sites, as the canonical form takes them.
	 *
	 * @return string[]
	 */
	public static function addresses() {
		return array( AB_MCP_IT_Sites::url( 'a' ), AB_MCP_IT_Sites::url( 'b' ) );
	}
}
