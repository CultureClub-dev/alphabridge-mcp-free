<?php
/**
 * What the account tools (wp_create_user, wp_update_user, wp_delete_user)
 * reach beyond the bootstrap, for FreeSinceFourNineUsersShopTest: accounts
 * with roles, a user cache in front of the database, the count of the
 * administrators, WordPress's writes, and $wpdb as far as the lock around
 * role changes and deletions uses it (named locks per connection, as the
 * database server keeps them). The same stage Pro's test/user-role-lock-test.php
 * and test/user-role-empty-test.php build, on top of tests/bootstrap.php.
 *
 * The database is $GLOBALS['ab_test_user_db'] (id => roles). The request's
 * user cache is $GLOBALS['ab_test_users'], which the bootstrap's get_user_by()
 * reads: a copy stays as it was read until clean_user_cache() reads the
 * account again from the database (or finds it gone). A test that changes
 * only the database plays another request or wp-admin.
 *
 * wp_delete_user() and get_editable_roles() live in
 * tests/wp-admin/includes/user.php, where WordPress keeps them and where
 * delete_user() loads them from.
 *
 * Loaded only by that test. Nothing here is used by the plugin elsewhere.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/**
 * WP_User as far as the account tools use it: the id, the login, the roles
 * on this site, has_cap() for the caller, and the three role writes, which
 * go to the database as WordPress writes them.
 */
final class AB_Test_Role_User {
	public $ID;
	public $user_login;
	public $roles;
	private $caps;

	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID         = $id;
		$this->user_login = 'user' . $id;
		$this->roles      = array_values( $roles );
		$this->caps       = $caps;
	}

	public function has_cap( $cap ): bool {
		return in_array( (string) $cap, $this->caps, true );
	}

	/** As WordPress: "" removes every role. */
	public function set_role( $role ): void {
		$GLOBALS['ab_test_set_roles'][]                 = $role;
		$GLOBALS['ab_test_user_db'][ $this->ID ] = '' === (string) $role ? array() : array( (string) $role );
		$this->roles                                    = $GLOBALS['ab_test_user_db'][ $this->ID ];
	}

	/**
	 * $GLOBALS['ab_test_remove_ignored']: a plugin's filter keeps the role.
	 * $GLOBALS['ab_test_remove_throws']: 'before' or 'after' the write a hook fails.
	 */
	public function remove_role( $role ): void {
		if ( ! empty( $GLOBALS['ab_test_remove_ignored'] ) ) {
			return;
		}
		if ( 'before' === ( $GLOBALS['ab_test_remove_throws'] ?? '' ) ) {
			throw new RuntimeException( 'a hook before the write failed' );
		}
		$GLOBALS['ab_test_user_db'][ $this->ID ] = array_values( array_diff( $GLOBALS['ab_test_user_db'][ $this->ID ] ?? array(), array( $role ) ) );
		$this->roles                             = $GLOBALS['ab_test_user_db'][ $this->ID ];
		if ( 'after' === ( $GLOBALS['ab_test_remove_throws'] ?? '' ) ) {
			throw new RuntimeException( 'remove_user_role hook failed' );
		}
	}

	/** $GLOBALS['ab_test_add_role_fails']: the role does not take. */
	public function add_role( $role ): void {
		if ( ! empty( $GLOBALS['ab_test_add_role_fails'] ) ) {
			return;
		}
		$GLOBALS['ab_test_user_db'][ $this->ID ][] = $role;
		$this->roles                               = $GLOBALS['ab_test_user_db'][ $this->ID ];
	}
}

/**
 * $wpdb as far as the lock uses it: named locks as the database server keeps
 * them, per connection. $GLOBALS['ab_test_locks'] maps a lock name to the
 * connection holding it, $GLOBALS['ab_test_conn'] is the connection of the
 * request running now. GET_LOCK(name, 0) answers 1 or 0 at once (NULL with
 * $GLOBALS['ab_test_lock_null']; 1 for everyone with
 * $GLOBALS['ab_test_lock_off'], to show the race without the lock);
 * RELEASE_LOCK frees only the caller's own.
 */
final class AB_Test_Lock_Wpdb {
	public $prefix = 'wp_';
	public $dbname = 'wordpress';

	public function prepare( $query, ...$args ) {
		return (string) preg_replace_callback(
			'/%s/',
			static function () use ( &$args ) {
				return "'" . str_replace( "'", "''", (string) array_shift( $args ) ) . "'";
			},
			(string) $query
		);
	}

	public function get_var( $query ) {
		$locks = &$GLOBALS['ab_test_locks'];
		$conn  = $GLOBALS['ab_test_conn'];
		if ( preg_match( "/^SELECT GET_LOCK\\('([^']*)', 0\\)$/", (string) $query, $m ) ) {
			++$GLOBALS['ab_test_lock_seen'];
			// What another request does just before this one gets the lock.
			if ( is_callable( $GLOBALS['ab_test_on_lock'] ?? null ) ) {
				$before                     = $GLOBALS['ab_test_on_lock'];
				$GLOBALS['ab_test_on_lock'] = null;
				$before();
			}
			$GLOBALS['ab_test_lock_names'][] = $m[1];
			if ( ! empty( $GLOBALS['ab_test_lock_null'] ) ) {
				return null;
			}
			if ( ! empty( $GLOBALS['ab_test_lock_off'] ) ) {
				return '1';
			}
			if ( isset( $locks[ $m[1] ] ) && $locks[ $m[1] ] !== $conn ) {
				return '0';
			}
			$locks[ $m[1] ] = $conn;
			return '1';
		}
		if ( preg_match( "/^SELECT IS_USED_LOCK\\('([^']*)'\\) = CONNECTION_ID\\(\\)$/", (string) $query, $m ) ) {
			if ( ! empty( $GLOBALS['ab_test_lock_off'] ) ) {
				return '1';
			}
			if ( ! isset( $locks[ $m[1] ] ) ) {
				return null; // A free lock: IS_USED_LOCK() is NULL, and so is the comparison.
			}
			return $locks[ $m[1] ] === $conn ? '1' : '0';
		}
		if ( preg_match( "/^SELECT RELEASE_LOCK\\('([^']*)'\\)$/", (string) $query, $m ) ) {
			if ( isset( $locks[ $m[1] ] ) && $locks[ $m[1] ] === $conn ) {
				unset( $locks[ $m[1] ] );
				return '1';
			}
			return isset( $locks[ $m[1] ] ) ? '0' : null;
		}
		throw new RuntimeException( 'unexpected query: ' . $query );
	}
}

/**
 * A site with these accounts (id => roles) in the database and in the
 * request's cache, the caller (id 90, not in the database) with $caps, no
 * lock held and nothing written yet.
 *
 * @param array<int,string[]> $users Id => roles.
 * @param string[]            $caps  What the caller's has_cap() answers.
 */
function ab_test_user_site( array $users, array $caps = array() ): void {
	$GLOBALS['ab_test_user_db'] = $users;
	ab_test_user_cache_from_db();
	$GLOBALS['ab_test_users'][90]        = new AB_Test_Role_User( 90, array( 'administrator' ), $caps );
	$GLOBALS['ab_test_current_user']     = 90;
	$GLOBALS['ab_test_locks']            = array();
	$GLOBALS['ab_test_conn']             = 'first';
	$GLOBALS['ab_test_lock_seen']        = 0;
	$GLOBALS['ab_test_lock_names']       = array();
	$GLOBALS['ab_test_lock_null']        = false;
	$GLOBALS['ab_test_lock_off']         = false;
	$GLOBALS['ab_test_on_lock']          = null;
	$GLOBALS['ab_test_meanwhile']        = null;
	$GLOBALS['ab_test_second']           = null;
	$GLOBALS['ab_test_inner']            = null;
	$GLOBALS['ab_test_reconnect']        = false;
	$GLOBALS['ab_test_reconnect_on_read'] = false;
	$GLOBALS['ab_test_in_hook']          = null;
	$GLOBALS['ab_test_after_update']     = null;
	$GLOBALS['ab_test_reconnect_in_update'] = false;
	$GLOBALS['ab_test_update_throws']    = false;
	$GLOBALS['ab_test_user_updates']     = array();
	$GLOBALS['ab_test_user_inserts']     = array();
	$GLOBALS['ab_test_insert_answer']    = null;
	$GLOBALS['ab_test_set_roles']        = array();
	$GLOBALS['ab_test_delete_ok']        = true;
	$GLOBALS['ab_test_deleted_users']    = array();
	$GLOBALS['ab_test_reassigned']       = 'not called';
	$GLOBALS['ab_test_during_cleanup']   = null;
	$GLOBALS['ab_test_deleted_hook_throws'] = false;
	$GLOBALS['ab_test_gone']             = array();
	$GLOBALS['ab_test_remove_ignored']   = false;
	$GLOBALS['ab_test_remove_throws']    = '';
	$GLOBALS['ab_test_add_role_fails']   = false;
	$GLOBALS['ab_test_editable']         = null;
}

/** The request's cache, read whole from the database (a request of its own). */
function ab_test_user_cache_from_db(): void {
	$caller                   = $GLOBALS['ab_test_users'][90] ?? null;
	$GLOBALS['ab_test_users'] = array();
	foreach ( $GLOBALS['ab_test_user_db'] as $id => $roles ) {
		$GLOBALS['ab_test_users'][ $id ] = new AB_Test_Role_User( (int) $id, $roles );
	}
	if ( null !== $caller ) {
		$GLOBALS['ab_test_users'][90] = $caller;
	}
}

/** How many accounts hold the administrator role in the database. */
function ab_test_admins(): int {
	return count( array_filter( $GLOBALS['ab_test_user_db'], static fn( $r ) => in_array( 'administrator', (array) $r, true ) ) );
}

/** wpdb connected anew: the server freed the old connection's locks, the new one holds none. */
function ab_test_renew_connection(): void {
	foreach ( $GLOBALS['ab_test_locks'] as $name => $conn ) {
		if ( $conn === $GLOBALS['ab_test_conn'] ) {
			unset( $GLOBALS['ab_test_locks'][ $name ] );
		}
	}
	$GLOBALS['ab_test_conn'] = 'renewed';
}

/**
 * Another request, run whole on a connection of its own and with its own PHP
 * state: it holds no lock and reads every account from the database. Its
 * answer goes to $GLOBALS[ $into ].
 */
function ab_test_other_request( callable $call, string $into = 'ab_test_second' ): void {
	$flag  = new ReflectionProperty( 'AB_MCP_Tools_Menus_Users', 'role_lock_held' );
	$held  = $flag->getValue();
	$cache = $GLOBALS['ab_test_users'];
	$conn  = $GLOBALS['ab_test_conn'];
	$flag->setValue( null, false );
	$GLOBALS['ab_test_conn'] = 'second';
	ab_test_user_cache_from_db();
	$GLOBALS[ $into ]         = $call();
	$GLOBALS['ab_test_users'] = $cache;
	$GLOBALS['ab_test_conn']  = $conn;
	$flag->setValue( null, $held );
}

if ( ! function_exists( 'clean_user_cache' ) ) {
	/**
	 * The account is read again from the database, or found gone. That read
	 * can lose the server and connect anew ($GLOBALS['ab_test_reconnect_on_read']).
	 */
	function clean_user_cache( $user ) {
		$id = (int) ( is_object( $user ) ? $user->ID : $user );
		if ( ! empty( $GLOBALS['ab_test_reconnect_on_read'] ) ) {
			$GLOBALS['ab_test_reconnect_on_read'] = false;
			ab_test_renew_connection();
		}
		if ( isset( $GLOBALS['ab_test_user_db'][ $id ] ) ) {
			$GLOBALS['ab_test_users'][ $id ] = new AB_Test_Role_User( $id, $GLOBALS['ab_test_user_db'][ $id ] );
		} else {
			unset( $GLOBALS['ab_test_users'][ $id ] );
		}
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * The ids with the role, from the database. Right after the count the
	 * connection can be renewed ($GLOBALS['ab_test_reconnect']) and another
	 * request run whole ($GLOBALS['ab_test_meanwhile']): the interleaving of
	 * two real requests at the worst moment.
	 */
	function get_users( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['ab_test_user_db'] as $id => $roles ) {
			if ( in_array( $args['role'] ?? '', (array) $roles, true ) ) {
				$out[] = (int) $id;
			}
		}
		if ( ! empty( $GLOBALS['ab_test_reconnect'] ) ) {
			$GLOBALS['ab_test_reconnect'] = false;
			ab_test_renew_connection();
		}
		if ( is_callable( $GLOBALS['ab_test_meanwhile'] ?? null ) ) {
			$call                         = $GLOBALS['ab_test_meanwhile'];
			$GLOBALS['ab_test_meanwhile'] = null;
			ab_test_other_request( $call );
		}
		return array_slice( $out, 0, (int) ( $args['number'] ?? count( $out ) ) );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/** The roles of $GLOBALS['ab_test_roles'] (slug => capabilities), null for any other. */
	function get_role( $role ) {
		$roles = $GLOBALS['ab_test_roles'] ?? array();
		return isset( $roles[ $role ] ) ? (object) array( 'capabilities' => $roles[ $role ] ) : null;
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	/**
	 * Notes what it is handed. On the way: a hook that calls the tool again
	 * on the same connection ($GLOBALS['ab_test_in_hook']), a hook that fails
	 * ($GLOBALS['ab_test_update_throws']), the connection renewed
	 * ($GLOBALS['ab_test_reconnect_in_update']). A role handed over is set
	 * last, as wp_insert_user() does; then the hooks on profile_update
	 * ($GLOBALS['ab_test_after_update']).
	 */
	function wp_update_user( $data ) {
		$GLOBALS['ab_test_user_updates'][] = $data;
		clean_user_cache( $data['ID'] );
		if ( is_callable( $GLOBALS['ab_test_in_hook'] ?? null ) ) {
			$hook                       = $GLOBALS['ab_test_in_hook'];
			$GLOBALS['ab_test_in_hook'] = null;
			$GLOBALS['ab_test_inner']   = $hook();
		}
		if ( ! empty( $GLOBALS['ab_test_update_throws'] ) ) {
			throw new RuntimeException( 'a plugin hook failed' );
		}
		if ( ! empty( $GLOBALS['ab_test_reconnect_in_update'] ) ) {
			$GLOBALS['ab_test_reconnect_in_update'] = false;
			ab_test_renew_connection();
		}
		if ( isset( $data['role'] ) ) {
			$GLOBALS['ab_test_user_db'][ $data['ID'] ] = '' === $data['role'] ? array() : array( $data['role'] );
		}
		clean_user_cache( $data['ID'] );
		if ( is_callable( $GLOBALS['ab_test_after_update'] ?? null ) ) {
			$hook                           = $GLOBALS['ab_test_after_update'];
			$GLOBALS['ab_test_after_update'] = null;
			$hook();
		}
		return $data['ID'];
	}
}

if ( ! function_exists( 'wp_insert_user' ) ) {
	/** Notes what it is handed; answers $GLOBALS['ab_test_insert_answer'] (a WP_Error) or the new id 50. */
	function wp_insert_user( $data ) {
		if ( null !== ( $GLOBALS['ab_test_insert_answer'] ?? null ) ) {
			return $GLOBALS['ab_test_insert_answer'];
		}
		$GLOBALS['ab_test_user_inserts'][] = $data;
		return 50;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
	}
}

if ( ! function_exists( 'sanitize_user' ) ) {
	/** As WordPress without strict: tags out, then only letters, digits, space and _ . - @. */
	function sanitize_user( $username, $strict = false ) {
		return trim( (string) preg_replace( '|[^a-z0-9 _.\-@]|i', '', strip_tags( (string) $username ) ) );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return substr( str_repeat( 'Gen3rated!', 10 ), 0, (int) $length );
	}
}

if ( ! function_exists( 'is_user_member_of_blog' ) ) {
	/** On multisite wp_delete_user() takes the account off this site only; $GLOBALS['ab_test_gone'] lists who is off. */
	function is_user_member_of_blog( $user_id = 0, $blog_id = 0 ) {
		return isset( $GLOBALS['ab_test_user_db'][ (int) $user_id ] ) && empty( $GLOBALS['ab_test_gone'][ (int) $user_id ] );
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/network/' . ltrim( (string) $path, '/' );
	}
}
