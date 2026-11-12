<?php
/**
 * The calls of the protected store with the requests of its self-check
 * through the test fetcher (Z8b branch 3): the production way,
 * AB_MCP_Outbound, refuses the local integration sites, and a check the
 * store makes by itself (put_tree() for a proof without php) takes no
 * fetcher. A test puts it into the seam of the store as its real calls
 * (AB_MCP_Test_Seam::install(), in a child process by its class name);
 * every other call is the real one. Test code only.
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-test-sync-fetcher.php';

/**
 * Class AB_MCP_IT_Store_Calls
 */
final class AB_MCP_IT_Store_Calls {

	/** @var AB_MCP_Protected_Store_Calls */
	private $real;

	/** @var AB_MCP_Test_Sync_Fetcher The fetcher of the requests (its calls for the assertions). */
	public $fetcher;

	/**
	 * @param AB_MCP_Test_Sync_Fetcher|null $fetcher The fetcher; one for the integration sites where none.
	 */
	public function __construct( $fetcher = null ) {
		$this->real    = new AB_MCP_Protected_Store_Calls();
		$this->fetcher = null === $fetcher ? AB_MCP_Test_Sync_Fetcher::for_sites() : $fetcher;
	}

	public function lstat( $path ) {
		return $this->real->lstat( $path );
	}

	public function stat( $path ) {
		return $this->real->stat( $path );
	}

	public function scandir( $dir ) {
		return $this->real->scandir( $dir );
	}

	public function mkdir( $path, $mode ) {
		return $this->real->mkdir( $path, $mode );
	}

	public function rmdir( $path ) {
		return $this->real->rmdir( $path );
	}

	public function unlink( $path ) {
		return $this->real->unlink( $path );
	}

	public function rename( $from, $to ) {
		return $this->real->rename( $from, $to );
	}

	public function chmod( $path, $mode ) {
		return $this->real->chmod( $path, $mode );
	}

	public function create( $path ) {
		return $this->real->create( $path );
	}

	public function put( $path, $bytes ) {
		return $this->real->put( $path, $bytes );
	}

	public function copy_into( $from, $to ) {
		return $this->real->copy_into( $from, $to );
	}

	public function read( $path, $max ) {
		return $this->real->read( $path, $max );
	}

	/**
	 * A request of the self-check: through the test fetcher where the store
	 * asks without one.
	 *
	 * @param string      $url     Address.
	 * @param array       $args    Arguments.
	 * @param object|null $fetcher Fetcher the store was given.
	 * @return array|WP_Error
	 */
	public function ask( $url, array $args, $fetcher ) {
		return $this->real->ask( $url, $args, null === $fetcher ? $this->fetcher : $fetcher );
	}
}
