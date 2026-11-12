<?php
/**
 * The calls of the protected store with the requests of its self-check
 * through the test fetcher (Z8b branch 3): the production way,
 * AB_MCP_Outbound, refuses the local integration sites, and a check the
 * store makes by itself (put_tree() for a proof without php) takes no
 * fetcher. A test puts it into the seam of the store as its real calls
 * (AB_MCP_Test_Seam::install(), in a child process by its class name);
 * every other call is the real one. Behebung 1 of Z8b branch 3: a read of
 * one path can be made to fail ($fail: method => paths, each answered with
 * false), for the read errors the fault plan of the seam cannot aim at one
 * path (lstat(), scandir() and read() are called for many paths; chmod()
 * for the files and the folders of a tree). Test code only.
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

	/** @var array<string,string[]> Method => paths whose call gives false (a read error of that path). */
	public $fail = array();

	/** @var array<int,array{0:string,1:string}> The calls answered with false: method and path. */
	public $failed = array();

	/** @var array<string,mixed> Path => the mtime lstat() answers for it (Behebung 4: a time of another form). */
	public $mtime = array();

	/** @var array<string,array<string,int>> Method => path => how many calls fail before the next ones answer (Behebung 5, B4). */
	public $fail_once = array();

	/** @var array<string,mixed> Path => the size lstat() answers for it (Behebung 4: a size of another form). */
	public $size = array();

	/** @var array<string,mixed> Folder => what scandir() answers for it (Behebung 4: a listing of another form). */
	public $names = array();

	/** @var array{0:bool,1:mixed} Whether free() answers the value given, and the value (Behebung 4). */
	public $free = array( false, null );

	/**
	 * Whether a call of a method for a path is to fail.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @return bool
	 */
	private function fails( $method, $path ) {
		if ( isset( $this->fail_once[ $method ][ (string) $path ] ) && $this->fail_once[ $method ][ (string) $path ] > 0 ) {
			--$this->fail_once[ $method ][ (string) $path ];
			$this->failed[] = array( $method, (string) $path );
			return true;
		}
		if ( isset( $this->fail[ $method ] ) && in_array( (string) $path, $this->fail[ $method ], true ) ) {
			$this->failed[] = array( $method, (string) $path );
			return true;
		}
		return false;
	}

	/**
	 * @param AB_MCP_Test_Sync_Fetcher|null $fetcher The fetcher; one for the integration sites where none.
	 */
	public function __construct( $fetcher = null ) {
		$this->real    = new AB_MCP_Protected_Store_Calls();
		$this->fetcher = null === $fetcher ? AB_MCP_Test_Sync_Fetcher::for_sites() : $fetcher;
	}

	public function lstat( $path ) {
		if ( $this->fails( 'lstat', $path ) ) {
			return false;
		}
		$stat = $this->real->lstat( $path );
		if ( is_array( $stat ) && array_key_exists( (string) $path, $this->mtime ) ) {
			$stat['mtime'] = $this->mtime[ (string) $path ];
			$stat[9]       = $this->mtime[ (string) $path ];
		}
		if ( is_array( $stat ) && array_key_exists( (string) $path, $this->size ) ) {
			$stat['size'] = $this->size[ (string) $path ];
			$stat[7]      = $this->size[ (string) $path ];
		}
		return $stat;
	}

	public function free( $dir ) {
		return $this->free[0] ? $this->free[1] : $this->real->free( $dir );
	}

	public function stat( $path ) {
		return $this->fails( 'stat', $path ) ? false : $this->real->stat( $path );
	}

	public function scandir( $dir ) {
		if ( array_key_exists( (string) $dir, $this->names ) ) {
			return $this->names[ (string) $dir ];
		}
		return $this->fails( 'scandir', $dir ) ? false : $this->real->scandir( $dir );
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
		return $this->fails( 'chmod', $path ) ? false : $this->real->chmod( $path, $mode );
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
		return $this->fails( 'read', $path ) ? false : $this->real->read( $path, $max );
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
