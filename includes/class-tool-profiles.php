<?php
/**
 * Profiles for the tool switches: Simple, Advanced, Expert.
 *
 * A profile is a preset for the per-tool switches, nothing more. It is
 * computed from the registry, never stored as a rule: applying one writes the
 * switches the way the settings form does (AB_MCP_Settings::set_tool_state()),
 * and AB_MCP_Settings::is_tool_enabled() keeps deciding exactly as before. So
 * a site that never applies a profile behaves as it always did, and the gate
 * that decides whether a tool runs has no second source of truth.
 *
 * What each profile switches on:
 *
 * - simple:   every tool that is not Mighty — the state the plugin ships in.
 * - advanced: Simple plus the Mighty tools of groups at level 'advanced'.
 * - expert:   every tool.
 *
 * The level of a group comes from AB_MCP_Tool_Registry::groups().
 *
 * The page offers only profiles that make a difference (see offered()), and
 * which one it shows is read back from the switches: the offered one whose
 * switches match, else «custom». A tool added later (an update, an add-on)
 * starts at its own default, as it always did, so a site on Expert reads
 * «custom» until the admin picks Expert again. That is deliberate: a new
 * Mighty tool never comes on without someone choosing it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tool_Profiles
 */
class AB_MCP_Tool_Profiles {

	/**
	 * The profiles, narrowest first, slug => name.
	 *
	 * @return array<string,string>
	 */
	public static function profiles() {
		return array(
			'simple'   => __( 'Simple', 'alphabridge-mcp' ),
			'advanced' => __( 'Advanced', 'alphabridge-mcp' ),
			'expert'   => __( 'Expert', 'alphabridge-mcp' ),
		);
	}

	/**
	 * Is a tool on in a profile?
	 *
	 * A tool that is not Mighty is on in every profile: it is on when the
	 * plugin ships, and a profile never takes away what a site ships with. A
	 * Mighty tool is on from the level of its group on.
	 *
	 * @param string $profile Profile slug.
	 * @param array  $def     Tool definition.
	 * @param string $level   Level of the tool's group.
	 * @return bool
	 */
	public static function tool_on( $profile, array $def, $level ) {
		if ( empty( $def['dangerous'] ) ) {
			return true;
		}
		return AB_MCP_Tool_Registry::level_rank( $level ) <= AB_MCP_Tool_Registry::level_rank( $profile );
	}

	/**
	 * The profiles in which a tool is on.
	 *
	 * @param array  $def   Tool definition.
	 * @param string $level Level of the tool's group.
	 * @return string[]
	 */
	public static function profiles_of( array $def, $level ) {
		$on = array();
		foreach ( array_keys( self::profiles() ) as $profile ) {
			if ( self::tool_on( $profile, $def, $level ) ) {
				$on[] = $profile;
			}
		}
		return $on;
	}

	/**
	 * The switches of every profile: profile => ( tool name => on ).
	 *
	 * @param array $all    Every registered tool, name => definition (the registry's all()).
	 * @param array $groups The registry's groups().
	 * @return array<string,array<string,bool>>
	 */
	public static function states( array $all, array $groups ) {
		$states = array();
		foreach ( array_keys( self::profiles() ) as $profile ) {
			$states[ $profile ] = array();
		}
		foreach ( $all as $name => $def ) {
			$slug  = AB_MCP_Tool_Registry::group_of( $def );
			$level = isset( $groups[ $slug ]['level'] ) ? (string) $groups[ $slug ]['level'] : 'expert';
			foreach ( array_keys( $states ) as $profile ) {
				$states[ $profile ][ $name ] = self::tool_on( $profile, $def, $level );
			}
		}
		return $states;
	}

	/**
	 * The profiles the settings page offers, narrowest first, slug => name.
	 *
	 * Simple always, Expert where it switches on more than Simple, Advanced
	 * only where it differs from both. On a site where no Mighty group is
	 * marked Advanced, Free on its own for one, Advanced would switch exactly
	 * what Simple does: a button that changes nothing. So no two offered
	 * profiles have the same switches, and the switches name at most one.
	 *
	 * @param array<string,array<string,bool>> $states The switches of every profile (states()).
	 * @return array<string,string>
	 */
	public static function offered( array $states ) {
		$offered = array();
		foreach ( self::profiles() as $profile => $name ) {
			if ( 'simple' === $profile
				|| ( 'expert' === $profile && $states['expert'] !== $states['simple'] )
				|| ( 'advanced' === $profile && $states['advanced'] !== $states['simple'] && $states['advanced'] !== $states['expert'] ) ) {
				$offered[ $profile ] = $name;
			}
		}
		return $offered;
	}

	/**
	 * The switches as they are now: tool name => on, by the same rule the gate
	 * uses.
	 *
	 * @param array $all Every registered tool, name => definition.
	 * @return array<string,bool>
	 */
	public static function effective( array $all ) {
		$on = array();
		foreach ( $all as $name => $def ) {
			$on[ $name ] = AB_MCP_Settings::is_tool_enabled( $name, $def );
		}
		return $on;
	}

	/**
	 * Which offered profile the switches follow, or 'custom'.
	 *
	 * Reads only; it writes nothing, so opening the settings page leaves an
	 * existing site exactly as it was.
	 *
	 * @param array $all    Every registered tool, name => definition.
	 * @param array $groups The registry's groups().
	 * @return string
	 */
	public static function current( array $all, array $groups ) {
		$now    = self::effective( $all );
		$states = self::states( $all, $groups );
		foreach ( array_keys( self::offered( $states ) ) as $profile ) {
			if ( $states[ $profile ] === $now ) {
				return $profile;
			}
		}
		return 'custom';
	}

	/**
	 * Set the tool switches to a profile.
	 *
	 * Writes the per-tool switches of every registered tool, as saving the
	 * capabilities form does. Nothing else: not read-only mode, not a
	 * connection, not a token. A known profile the page does not offer is
	 * applied too; its switches are those of an offered one.
	 *
	 * @param string               $profile  Profile slug.
	 * @param AB_MCP_Tool_Registry $registry Registry.
	 * @return bool False for an unknown profile, which changes nothing.
	 */
	public static function apply( $profile, AB_MCP_Tool_Registry $registry ) {
		$profile = (string) $profile;
		if ( ! array_key_exists( $profile, self::profiles() ) ) {
			return false;
		}
		$states = self::states( $registry->all(), $registry->groups() );
		AB_MCP_Settings::set_tool_state( $states[ $profile ] );
		return true;
	}
}
