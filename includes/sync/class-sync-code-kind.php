<?php
/**
 * The kind code (Z8b branch 5; plan E10, 3.1 Z26 and Z38; contract A): the
 * engine the table of AB_MCP_Sync_Kinds gives the files of a plugin and of
 * a theme (part:plugin:files, part:theme:files). It is the kind whose step
 * writes and checks the files of a unit (the swap of the code of the site,
 * AB_MCP_Sync_Code_Swap), so it alone moves the base of that part on
 * (AB_MCP_Sync_Pairs::rebase() takes the part only from the engine
 * registered under this name, Z38), and it says what an account needs for
 * such a step (rights(), E10).
 *
 * THE RIGHTS (rights(), the rules AB_MCP_Sync_Write::core() asks right
 * before a step of the code writes, Z26), besides manage_options and the
 * level Full of wp_site_push, each asked with current_user_can() on this
 * site:
 * - a new unit (create): install_plugins, install_themes;
 * - another version (update): upload_plugins and update_plugins, of a theme
 *   upload_themes and update_themes (a package replaced is an upload in the
 *   core, wp-admin/update.php, no work in the file editor);
 * - the same version with other files (edit, A12): those two and
 *   edit_plugins or edit_themes besides, so DISALLOW_FILE_EDIT locks it;
 * and only where WordPress may change files at all (wp_is_file_mod_allowed(),
 * DISALLOW_FILE_MODS) and writes them directly (the file system method
 * direct: a swap renames and lays a folder exclusively, which no other
 * method does), else code:file_mods. What cannot be read (no method named)
 * is not direct.
 *
 * The other methods of contract A have no way for code in this version: a
 * unit is read by AB_MCP_Sync_Env and AB_MCP_Sync_File_Manifest, packed by
 * AB_MCP_Sync_Code_Package, staged by AB_MCP_Sync_Code_Stage and swapped by
 * AB_MCP_Sync_Code_Swap; they answer with that way.
 *
 * THE CALLS TO THE OUTSIDE (the rights of the account, whether files may be
 * changed, the file system method) go through AB_MCP_Sync_Code_Kind_Calls,
 * held by the seam of this class; only the tests put another object there.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-kind.php';
require_once __DIR__ . '/class-sync-kinds.php';

/**
 * The calls of the kind code to the outside: the real ones.
 */
final class AB_MCP_Sync_Code_Kind_Calls {

	/**
	 * Whether the account of this request has a capability on this site.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public function can( $cap ) {
		return true === current_user_can( $cap );
	}

	/**
	 * Whether WordPress may change files on this site (DISALLOW_FILE_MODS,
	 * the filter file_mod_allowed); null where it cannot be asked.
	 *
	 * @return bool|null
	 */
	public function mods_allowed() {
		if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
			return null;
		}
		$allowed = wp_is_file_mod_allowed( 'ab_mcp_code_swap' );
		return is_bool( $allowed ) ? $allowed : null;
	}

	/**
	 * The file system method WordPress would use here; null where it names
	 * none.
	 *
	 * @return string|null
	 */
	public function method() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$method = get_filesystem_method();
		return is_string( $method ) ? $method : null;
	}
}

/**
 * Class AB_MCP_Sync_Code_Kind
 */
final class AB_MCP_Sync_Code_Kind implements AB_MCP_Sync_Kind {

	/** The kinds of unit whose files this kind writes. */
	const UNITS = array( 'plugin', 'theme' );

	/**
	 * What a step of the code does to the files of a unit, each with the
	 * capabilities it takes of a plugin and of a theme (E10).
	 */
	const OPS = array(
		'create' => array(
			'plugin' => array( 'install_plugins' ),
			'theme'  => array( 'install_themes' ),
		),
		'update' => array(
			'plugin' => array( 'upload_plugins', 'update_plugins' ),
			'theme'  => array( 'upload_themes', 'update_themes' ),
		),
		'edit'   => array(
			'plugin' => array( 'upload_plugins', 'update_plugins', 'edit_plugins' ),
			'theme'  => array( 'upload_themes', 'update_themes', 'edit_themes' ),
		),
	);

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_code_kind = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Code_Kind_Calls
	 */
	private static function calls() {
		return self::$naht_sync_code_kind ?? new AB_MCP_Sync_Code_Kind_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_code_kind = null;
	}

	/**
	 * The engine registered under the name code: registered here where it is
	 * not yet, and always the one the registry holds (rebase() takes the part
	 * of a unit only from that very object).
	 *
	 * @return AB_MCP_Sync_Code_Kind|WP_Error
	 */
	public static function engine() {
		$held = AB_MCP_Sync_Kinds::get( 'code' );
		if ( null === $held ) {
			$done = AB_MCP_Sync_Kinds::register( new self() );
			if ( true !== $done ) {
				return $done;
			}
			$held = AB_MCP_Sync_Kinds::get( 'code' );
		}
		return $held instanceof self ? $held : new WP_Error( 'ab_mcp_sync_kind_duplicate', 'The kind code is registered with another engine than AB_MCP_Sync_Code_Kind, and one kind has one engine. Use AB_MCP_Sync_Code_Kind::engine() to register the kind code, and nothing else.' );
	}

	/** {@inheritdoc} */
	public function kind() {
		return 'code';
	}

	/** {@inheritdoc} */
	public function phase() {
		return 2;
	}

	/** {@inheritdoc} */
	public function responsibility() {
		return array( 'part:plugin:files', 'part:theme:files' );
	}

	/**
	 * Whether the account of this request may do this to the files of a
	 * unit, and whether this site lets files be changed in the way a swap
	 * changes them (E10; the class comment).
	 *
	 * @param string $op  create, update or edit (OPS).
	 * @param string $ref The unit: its kind and its name, as plugin:<name> or theme:<name>.
	 * @return true|WP_Error
	 */
	public function rights( $op, $ref ) {
		$unit = is_string( $ref ) ? explode( ':', $ref, 2 ) : array();
		if ( ! is_string( $op ) || ! isset( self::OPS[ $op ] ) || 2 !== count( $unit ) || ! in_array( $unit[0], self::UNITS, true ) || '' === $unit[1] ) {
			return new WP_Error( 'ab_mcp_sync_kind_invalid', 'rights() of the kind code takes an operation of AB_MCP_Sync_Code_Kind::OPS (create, update, edit) and a unit as plugin:<name> or theme:<name>. Use that form.' );
		}
		$calls = self::calls();
		if ( true !== $calls->mods_allowed() ) {
			return new WP_Error( 'code:file_mods', __( 'This site does not let WordPress change its files (DISALLOW_FILE_MODS in wp-config.php, or a filter on file_mod_allowed), so no plugin and no theme is taken over here. Ask the host or the site administrator to lift the setting, then make the plan again; or update the plugin or theme by hand over SFTP.', 'alphabridge-mcp' ) );
		}
		if ( 'direct' !== $calls->method() ) {
			return new WP_Error( 'code:file_mods', __( 'WordPress does not write files directly on this site (its file system method is not direct), and a plugin or theme is taken over by renaming folders, which only the direct method does. Ask the host to let PHP write the folders of plugins and themes as their owner, then make the plan again; until then, update it by hand in wp-admin.', 'alphabridge-mcp' ) );
		}
		foreach ( self::OPS[ $op ][ $unit[0] ] as $cap ) {
			if ( true !== $calls->can( $cap ) ) {
				return new WP_Error(
					'ab_mcp_sync_rights',
					'theme' === $unit[0]
						/* translators: 1: the folder of the theme, 2: the capability of WordPress, as the code names it (install_themes, upload_themes, update_themes, edit_themes). */
						? sprintf( __( 'Not taken over: this account may not change the files of the theme %1$s on this site in this way (%2$s; with DISALLOW_FILE_EDIT nobody has edit_themes, which the same version with other files takes). Connect with an account that may, or leave the entry out of the plan.', 'alphabridge-mcp' ), $unit[1], $cap )
						/* translators: 1: the folder or file of the plugin, 2: the capability of WordPress, as the code names it (install_plugins, upload_plugins, update_plugins, edit_plugins). */
						: sprintf( __( 'Not taken over: this account may not change the files of the plugin %1$s on this site in this way (%2$s; with DISALLOW_FILE_EDIT nobody has edit_plugins, which the same version with other files takes). Connect with an account that may, or leave the entry out of the plan.', 'alphabridge-mcp' ), $unit[1], $cap ),
					array( 'capability' => $cap )
				);
			}
		}
		return true;
	}

	/** {@inheritdoc} */
	public function inventory( $source, $cursor ) {
		return $this->no_way( 'inventory', 'AB_MCP_Sync_Env reads the units of a site with their files (wp_site_export with purpose=env).' );
	}

	/** {@inheritdoc} */
	public function canonical( $object ) {
		return $this->no_way( 'canonical', 'AB_MCP_Sync_File_Manifest gives the manifest of a unit and its hash.' );
	}

	/** {@inheritdoc} */
	public function export( array $refs ) {
		return $this->no_way( 'export', 'AB_MCP_Sync_Code_Package packs the files of a unit (wp_site_export with purpose=code).' );
	}

	/** {@inheritdoc} */
	public function core_effects( $op ) {
		return $this->no_way( 'core_effects', 'AB_MCP_Sync_Code_Swap names what a swap changes besides the files.' );
	}

	/** {@inheritdoc} */
	public function fields() {
		return array();
	}

	/** {@inheritdoc} */
	public function parts( array $payload ) {
		return $this->no_way( 'parts', 'A step of the code swaps the unit as a whole (AB_MCP_Sync_Code_Swap).' );
	}

	/** {@inheritdoc} */
	public function backup( array $part ) {
		return $this->no_way( 'backup', 'The swap moves the old unit whole into the protected store (AB_MCP_Sync_Code_Swap::rename_marked()).' );
	}

	/** {@inheritdoc} */
	public function write( array $part ) {
		return $this->no_way( 'write', 'The swap renames the stage to the place of the unit (AB_MCP_Sync_Code_Swap::rename_marked()).' );
	}

	/** {@inheritdoc} */
	public function read_back( array $part ) {
		return $this->no_way( 'read_back', 'The swap reads the unit back whole against the stage (AB_MCP_Sync_Code_Swap::close()).' );
	}

	/** {@inheritdoc} */
	public function restore_part( array $part ) {
		return $this->no_way( 'restore_part', 'The way back of a swap puts the old unit back whole (AB_MCP_Sync_Code_Swap::restore()).' );
	}

	/** {@inheritdoc} */
	public function post_write( array $parts ) {
		return $this->no_way( 'post_write', 'The swap clears OPcache for every file it replaced (AB_MCP_Sync_Code_Swap::invalidate()).' );
	}

	/** {@inheritdoc} */
	public function verify( array $pair ) {
		return $this->no_way( 'verify', 'The check of the run reads the unit against the stage of its step (AB_MCP_Sync_Code_Ops).' );
	}

	/**
	 * The answer of a method of contract A that has no way for code.
	 *
	 * @param string $method Method of contract A.
	 * @param string $way    What does it for code.
	 * @return WP_Error
	 */
	private function no_way( $method, $way ) {
		return new WP_Error(
			'ab_mcp_sync_kind_no_way',
			sprintf( 'The kind code has no %1$s(): a unit of code is no row of the database. Use what does it for code: %2$s', $method, $way ),
			array(
				'kind'   => 'code',
				'method' => $method,
			)
		);
	}
}
