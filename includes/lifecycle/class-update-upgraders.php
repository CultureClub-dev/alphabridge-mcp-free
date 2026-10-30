<?php
/**
 * The upgraders of AB_MCP_Update_Guard: WordPress's own, except that
 * while the guard holds the maintenance mode, bulk_upgrade() does not switch
 * it off at its end. Core switches it off before it cleans the update cache
 * and runs upgrader_process_complete (class-plugin-upgrader.php,
 * class-theme-upgrader.php, bulk_upgrade()); until the guard switched it on
 * again, visitors and this MCP endpoint would load the new code untested,
 * and a roll-back after an exception in one of those hooks would run without
 * it. The guard switches it off itself at the end.
 *
 * They also note whether they moved the previous version aside themselves
 * (move_to_temp_backup_dir(), WordPress 6.3). After an update that broke off,
 * the guard puts the copy in upgrade-temp-backup back only then: what lies
 * there under the folder's name is this update's copy, whatever the main
 * file says (an update can change only an included file), and a copy an
 * earlier request left there is not taken for it.
 *
 * Loaded by the guard after wp-admin/includes/class-wp-upgrader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * What both upgraders add to WordPress's.
 */
trait AB_MCP_Held_Upgrader {

	/** @var bool Whether the guard holds the maintenance mode. */
	public $hold = false;

	/** @var bool Whether this upgrader moved the previous version into upgrade-temp-backup. */
	public $backed_up = false;

	/**
	 * As WordPress's, but no switching off while the guard holds it.
	 *
	 * @param bool $enable On or off.
	 */
	public function maintenance_mode( $enable = false ) {
		if ( ! $enable && $this->hold ) {
			return;
		}
		parent::maintenance_mode( $enable );
	}

	/**
	 * As WordPress's, and it notes when the previous version is aside.
	 *
	 * @param array $args { slug, src, dir }.
	 * @return bool|WP_Error
	 */
	public function move_to_temp_backup_dir( $args ) {
		$res = parent::move_to_temp_backup_dir( $args );
		if ( true === $res ) {
			$this->backed_up = true;
		}
		return $res;
	}
}

/**
 * Class AB_MCP_Held_Plugin_Upgrader
 */
class AB_MCP_Held_Plugin_Upgrader extends Plugin_Upgrader {

	use AB_MCP_Held_Upgrader;
}

/**
 * Class AB_MCP_Held_Theme_Upgrader
 */
class AB_MCP_Held_Theme_Upgrader extends Theme_Upgrader {

	use AB_MCP_Held_Upgrader;
}

/**
 * WordPress's skin for updates without a screen, which keeps the update's
 * locks fresh at every step the upgrader reports (downloading, unpacking,
 * installing, removing the old version): a host can take minutes for one of
 * them, and the locks would be taken over after ten (Codex, 06.10.2026).
 * The download, the longest step on a slow connection, ends after 300 s
 * (download_url() in WP_Upgrader::download_package()).
 */
class AB_MCP_Beating_Skin extends Automatic_Upgrader_Skin {

	/**
	 * As WordPress's, after a beat of the update's locks.
	 *
	 * @param string|WP_Error $feedback Message or error.
	 * @param mixed           ...$args  Its arguments.
	 */
	public function feedback( $feedback, ...$args ) {
		AB_MCP_Update_Guard::beat();
		parent::feedback( $feedback, ...$args );
	}
}
