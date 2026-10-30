<?php
/**
 * The approvals of the abilities of other plugins on Settings › AlphaBridge
 * MCP: which abilities the plugins of this site register, and which of them
 * an administrator approved for wp_run_ability.
 *
 * No ability is approved until an administrator switches it on here, and
 * switching on write access approves none (AB_MCP_Ability_Broker). The
 * switches sit in the fine-tuning's main group «Abilities of other plugins»,
 * below the two tools, one folding group per plugin, drawn with the rows of
 * the tools (AB_MCP_Admin::fine_row_html(), fine_sub_html()), and post and
 * save with the fine-tuning's one form and button (form="ab-caps", action
 * ab_mcp_fine_save).
 *
 * Only where this plugin's wp_run_ability is the registered one (here()).
 * Where AlphaBridge MCP Pro registers its own over it, Pro shows its own
 * switches in the same place (opt-out, its list ab_mcp_pro_abilities_off),
 * and these would only show a second, idle set. The fields have names of
 * their own: Pro saves its fields on the same action, also while its
 * licence is not active, and must not read these as its own.
 *
 * What the screen does not show stays as it is when it is saved: approvals
 * of abilities that are not registered right now (their plugin is off for a
 * moment), and namespace patterns written by code.
 *
 * Free since 4.12.0, after Pro's card (includes/abilities/abilities-card.php
 * of Pro), with approvals in place of switches that are on by default.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Ability_Switches
 */
class AB_MCP_Ability_Switches {

	/** The posted names whose switch is on. Not Pro's ab_abilities_on. */
	const FIELD_ON = 'ab_abilities_approved';

	/** The posted list of the abilities the screen showed. Not Pro's ab_abilities_shown. */
	const FIELD_SHOWN = 'ab_abilities_listed';

	/**
	 * Hook the switches into the fine-tuning.
	 */
	public static function hook() {
		add_filter( 'ab_mcp_fine_group_html', array( __CLASS__, 'fine_section' ), 10, 3 );
		add_action( 'ab_mcp_fine_save', array( __CLASS__, 'fine_save' ) );
	}

	/**
	 * Whether this plugin's wp_run_ability is the one registered here, so
	 * that its approvals decide. Not where an add-on registered its own over
	 * it (AlphaBridge MCP Pro, with its own switches).
	 *
	 * @param object|null $registry The tool registry; the plugin's by default.
	 * @return bool
	 */
	public static function here( $registry = null ) {
		if ( null === $registry && class_exists( 'AB_MCP_Plugin', false ) ) {
			$registry = AB_MCP_Plugin::instance()->registry;
		}
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get' ) ) {
			return false;
		}
		$def = $registry->get( AB_MCP_Ability_Broker::TOOL );
		return is_array( $def ) && isset( $def['callback'] ) && is_array( $def['callback'] ) && 'AB_MCP_Tools_Abilities' === ( $def['callback'][0] ?? '' );
	}

	/**
	 * What the screen shows, read once.
	 *
	 * @return array { available, wp_version, read_mode, on, extra_on, groups: provider => list of { name, label, description, readonly, destructive, internal, on } }
	 */
	public static function state() {
		$state = array(
			'available'  => AB_MCP_Ability_Broker::available(),
			'wp_version' => AB_MCP_Ability_Broker::wp_version(),
			'read_mode'  => ! AB_MCP_Ability_Broker::mode_allows_run(),
			'on'         => AB_MCP_Ability_Broker::on_list(),
			'extra_on'   => array(),
			'groups'     => array(),
		);
		if ( ! $state['available'] ) {
			return $state;
		}
		$shown = array();
		foreach ( AB_MCP_Ability_Broker::all() as $name => $ability ) {
			$provider    = AB_MCP_Ability_Broker::provider( $name );
			$annotations = AB_MCP_Ability_Broker::annotations( $ability );
			$shown[]     = $name;
			$state['groups'][ $provider ][] = array(
				'name'        => $name,
				'label'       => (string) $ability->get_label(),
				'description' => (string) $ability->get_description(),
				// The badge: the reading that it writes wins where the plugin
				// contradicts itself (AB_MCP_Ability_Broker::is_readonly()).
				'readonly'    => AB_MCP_Ability_Broker::is_readonly( $ability ),
				'destructive' => true === $annotations['destructive'],
				'internal'    => ! AB_MCP_Ability_Broker::is_public( $ability ),
				'on'          => AB_MCP_Ability_Broker::listed( $name, $state['on'] ),
			);
		}
		foreach ( $state['on'] as $item ) {
			if ( ! in_array( $item, $shown, true ) ) {
				$state['extra_on'][] = $item;
			}
		}
		return $state;
	}

	/**
	 * The switches inside the main group «Abilities of other plugins», below
	 * the two tools: what abilities are and that none runs unapproved, then
	 * one folding group per plugin with a switch, the name in code, the
	 * label, the description behind an «i», «deletes» where the plugin says
	 * so, and the badge Reads or Writes. Every switch posts with the
	 * fine-tuning's form (form="ab-caps"); the list of the abilities shown
	 * goes with it, and only those change on save.
	 *
	 * @param array $s state().
	 * @return string HTML, escaped.
	 */
	public static function fine_html( array $s ) {
		$h  = '<div class="ab-abilities ab-abilities--fine" id="ab-abilities">';
		$h .= '<p class="ab-note ab-abilities__lead">' . esc_html( AB_MCP_Admin::addon_text( 'abilities_approve_lead' ) ) . '</p>';
		if ( empty( $s['available'] ) ) {
			$h .= '<p class="ab-note ab-abilities__missing">' . esc_html( sprintf( AB_MCP_Admin::addon_text( 'abilities_need_wp' ), (string) $s['wp_version'] ) ) . '</p></div>';
			return $h;
		}
		if ( ! empty( $s['read_mode'] ) ) {
			$h .= '<p class="ab-note ab-abilities__read">' . esc_html( AB_MCP_Admin::addon_text( 'abilities_write_off' ) ) . '</p>';
		}
		if ( array() === $s['groups'] ) {
			$h .= '<p class="ab-note ab-abilities__none">' . esc_html( AB_MCP_Admin::addon_text( 'abilities_none' ) ) . '</p>';
		}
		$shown = array();
		foreach ( $s['groups'] as $provider => $rows ) {
			$items  = '';
			$reads  = 0;
			$writes = 0;
			foreach ( $rows as $row ) {
				$shown[] = $row['name'];
				if ( ! empty( $row['readonly'] ) ) {
					++$reads;
				} else {
					++$writes;
				}
				$items .= AB_MCP_Admin::fine_row_html(
					array(
						'name'     => self::FIELD_ON . '[]',
						'value'    => $row['name'],
						'checked'  => ! empty( $row['on'] ),
						'label'    => '' !== $row['label'] ? $row['label'] : $row['name'],
						'describe' => $row['description'],
						'reads'    => ! empty( $row['readonly'] ),
						'note'     => ! empty( $row['destructive'] ) ? 'deletes' : '',
						'class'    => 'ab-item',
						'id'       => AB_MCP_Ability_Broker::anchor( $row['name'] ),
					)
				);
			}
			$h .= AB_MCP_Admin::fine_sub_html(
				array(
					'id'     => 'ability-' . (string) $provider,
					'title'  => (string) $provider,
					'reads'  => $reads,
					'writes' => $writes,
					'rows'   => $items,
				)
			);
		}
		// The abilities this screen shows; the save changes only these.
		$h .= '<input type="hidden" name="' . self::FIELD_SHOWN . '" value="' . esc_attr( implode( ',', $shown ) ) . '" form="ab-caps">';
		if ( array() !== $s['extra_on'] ) {
			$h .= '<p class="ab-note ab-abilities__extra">' . esc_html( AB_MCP_Admin::addon_text( 'abilities_kept_on' ) ) . ' <code>' . implode( '</code>, <code>', array_map( 'esc_html', $s['extra_on'] ) ) . '</code></p>';
		}
		$h .= '</div>';
		return $h;
	}

	/**
	 * The switches as the fine-tuning asks for them (filter
	 * ab_mcp_fine_group_html): in the main group that holds the tool group
	 * «abilities» (wherever a filter put it), for administrators, where this
	 * plugin's wp_run_ability is the registered one.
	 *
	 * @param string   $html   What add-ons put there.
	 * @param string   $main   Slug of the main group.
	 * @param string[] $groups Slugs of the tool groups in it.
	 * @return string
	 */
	public static function fine_section( $html, $main = '', $groups = array() ) {
		if ( ! in_array( 'abilities', (array) $groups, true ) || ! current_user_can( 'manage_options' ) || ! self::here() ) {
			return $html;
		}
		return (string) $html . self::fine_html( self::state() );
	}

	/**
	 * The abilities of a posted list that are registered now; the form's own
	 * word on what it showed counts only for those.
	 *
	 * @param mixed $posted Names as posted.
	 * @return string[]
	 */
	public static function registered( $posted ) {
		$out = array();
		foreach ( AB_MCP_Ability_Broker::sanitize_list( is_array( $posted ) ? $posted : array() ) as $name ) {
			if ( null !== AB_MCP_Ability_Broker::get( $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * The new list of approved abilities: what the screen did not show stays
	 * as it was; of what it showed, every one whose switch was on.
	 *
	 * A namespace pattern ("vendor/*") that covers a shown ability whose
	 * switch is now off goes: else switching that one off would change
	 * nothing (Codex, 30.10.2026). The shown abilities of that namespace
	 * whose switches stay on keep their approval by name; one the provider
	 * adds later then needs its own.
	 *
	 * @param string[] $stored Approved before.
	 * @param string[] $shown  Abilities the screen showed (registered).
	 * @param mixed    $on     Names whose switch was on, as posted.
	 * @return string[]
	 */
	public static function merge( array $stored, array $shown, $on ) {
		$on   = AB_MCP_Ability_Broker::sanitize_list( is_array( $on ) ? $on : array() );
		$keep = array();
		foreach ( $stored as $item ) {
			if ( in_array( $item, $shown, true ) ) {
				continue;
			}
			if ( '/*' === substr( $item, -2 ) ) {
				foreach ( $shown as $name ) {
					if ( AB_MCP_Ability_Broker::listed( $name, array( $item ) ) && ! in_array( $name, $on, true ) ) {
						continue 2;
					}
				}
			}
			$keep[] = $item;
		}
		foreach ( $shown as $name ) {
			if ( in_array( $name, $on, true ) ) {
				$keep[] = $name;
			}
		}
		return AB_MCP_Ability_Broker::sanitize_list( $keep );
	}

	/**
	 * Save the switches with the fine-tuning (action ab_mcp_fine_save; the
	 * capability and the nonce are checked there). Only when the form showed
	 * them: a form without the list of shown abilities changes nothing.
	 *
	 * @param array $posted The posted fields, unslashed.
	 */
	public static function fine_save( $posted ) {
		if ( ! is_array( $posted ) || ! isset( $posted[ self::FIELD_SHOWN ] ) || ! is_string( $posted[ self::FIELD_SHOWN ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$shown = self::registered( explode( ',', sanitize_text_field( $posted[ self::FIELD_SHOWN ] ) ) );
		$on    = isset( $posted[ self::FIELD_ON ] ) && is_array( $posted[ self::FIELD_ON ] ) ? array_map( 'sanitize_text_field', $posted[ self::FIELD_ON ] ) : array();
		AB_MCP_Ability_Broker::set_on( self::merge( AB_MCP_Ability_Broker::on_list(), $shown, $on ) );
	}
}
