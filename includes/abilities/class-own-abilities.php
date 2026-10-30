<?php
/**
 * AlphaBridge's own tools as WordPress abilities, for the MCP adapter of
 * WordPress (wordpress/mcp-adapter): its default server lists the abilities
 * whose meta says mcp.public and runs them through the Abilities API.
 *
 * Off until an administrator switches it on under Fine-tuning (SETTING in
 * ab_mcp_options; fine_html(), fine_save()). The adapter is a second way in
 * that knows nothing of AlphaBridge's connections: no token, no access
 * level, no consent screen. Whoever signs in there with a WordPress account
 * (an application password, or a cookie with its nonce) reaches the tools
 * offered here with that account's rights, and the access level is «full»
 * for everyone (AB_MCP_Auth::current_scope() without a token).
 *
 * What runs is the same as through tools/call, and through the same code:
 * the permission callback asks AB_MCP_Security::permits() (capability,
 * write access, the tool's switch), and the execute callback runs the tool
 * through AB_MCP_REST_Controller::run_tool(), which tools/call uses as well
 * (the policy gate with the rate limit, the arguments, the confirmation
 * token of the tools without a way back, the log, marked VIA). Both
 * callbacks look the tool up again in the registry and ask offers() again,
 * so a tool that is not offered (any more) never runs this way.
 *
 * Which tools (offers()): those of this plugin, by the file their callback's
 * class (or function) is defined in, under includes/ of this plugin. AlphaBridge
 * MCP Pro registers its tools into the same registry after this plugin, and
 * some under the names of this plugin's tools (wp_update_post, wp_get_post
 * and others) with classes of its own; those depend on Pro's own checks and
 * licence and are not this plugin's to offer. A list of names could not see
 * such a replacement; the defining file does, also for a Pro class that
 * extends one of this plugin's (its file is Pro's). Never offered, whoever
 * registers them: the names of AB_MCP_Guidance::PRO_TOOLS and AGENCY_TOOLS,
 * Site Deploy (wp_deploy_*), undo (NOT_OFFERED: the undo points belong to
 * the calls of AlphaBridge connections, and the tools ask only for the
 * capability read), wp_list_abilities and wp_run_ability (the adapter lists
 * and runs abilities itself, and a runner of abilities inside an ability
 * would be a way around the approvals), and every tool without a
 * capability: such a tool leaves the check to the access level of an
 * AlphaBridge connection, which the adapter does not have, so any account,
 * a subscriber too, could call it.
 *
 * Each ability: name alphabridge/<tool name with - for _>, the English
 * short name as label (as tools/list sends it), the tool's description, the
 * category alphabridge, the tool's input schema (input_schema()), no output
 * schema (WordPress checks every result against it and would turn any
 * deviation into an error), meta.mcp.public and meta.mcp.type 'tool' for
 * the adapter, and the annotations of the registry. Neither show_in_rest nor
 * meta.public: show_in_rest would open the same tools through the REST
 * routes of the Abilities API as well, and from WordPress 7.1 so would
 * public, which seeds show_in_rest.
 *
 * AlphaBridge's own abilities never run through wp_run_ability
 * (AB_MCP_Ability_Broker::OWN_NAMESPACE): the tools themselves are the way
 * there.
 *
 * Free since 4.12.0.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Own_Abilities
 */
class AB_MCP_Own_Abilities {

	/** Refusals of one account logged per hour at most (denied_log_allowed()). */
	const DENIED_LOG_PER_HOUR = 20;


	/** Key in ab_mcp_options (AB_MCP_Settings): whether the tools are offered. */
	const SETTING = 'own_abilities';

	/** The posted switch; not a field of Pro (ab_abilities_on, ab_abilities_shown). */
	const FIELD = 'ab_own_abilities';

	/** The posted field that says the form showed the switch. */
	const FIELD_SHOWN = 'ab_own_abilities_shown';

	/** Id of the switch on the settings page, for a direct link. */
	const ANCHOR = 'ab-own-abilities';

	/** The category of the abilities and the namespace of their names. */
	const CATEGORY = 'alphabridge';

	/** What the log notes for a call that came this way (AB_MCP_Audit_Log). */
	const VIA = 'ability';

	/** Tools never offered as abilities. */
	const NOT_OFFERED = array( 'wp_undo', 'wp_undo_list', 'wp_undo_discard', 'wp_list_abilities', 'wp_run_ability' );

	/** Name prefix of tools never offered: Site Deploy of the Agency plan. */
	const NOT_OFFERED_PREFIX = 'wp_deploy_';

	/**
	 * The type of a property whose schema names none: every type JSON has.
	 * The same as no type in JSON Schema, but WordPress's own check of an
	 * ability's input (rest_validate_value_from_schema()) wants one and
	 * raises a notice without.
	 */
	const ANY_TYPE = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );

	/**
	 * Hook the registration into the Abilities API and the switch into the
	 * fine-tuning. On load of the plugin, before init: the Abilities API
	 * fires its actions the first time something asks for an ability,
	 * which can be early in init.
	 */
	public static function hook() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'ab_mcp_fine_save', array( __CLASS__, 'fine_save' ) );
	}

	/**
	 * Whether an administrator switched the offer on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return true === (bool) AB_MCP_Settings::get( self::SETTING, false );
	}

	/**
	 * Whether WordPress has the Abilities API (6.9 and newer).
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * The ability's name for a tool: alphabridge/wp-list-posts.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	public static function ability_name( $tool ) {
		return self::CATEGORY . '/' . str_replace( '_', '-', (string) $tool );
	}

	/**
	 * The plugin's tool registry, or null before it exists.
	 *
	 * @return AB_MCP_Tool_Registry|null
	 */
	private static function registry() {
		if ( ! class_exists( 'AB_MCP_Plugin', false ) ) {
			return null;
		}
		$registry = AB_MCP_Plugin::instance()->registry;
		return is_object( $registry ) && method_exists( $registry, 'all' ) && method_exists( $registry, 'get' ) ? $registry : null;
	}

	/**
	 * Whether a tool may be offered as an ability (see the class comment):
	 * not one of NOT_OFFERED, Site Deploy, Pro or Agency by name; a
	 * capability; a callback defined in this plugin; a valid ability name.
	 *
	 * @param string $name Tool name.
	 * @param mixed  $def  Its definition in the registry.
	 * @return bool
	 */
	public static function offers( $name, $def ) {
		$name = (string) $name;
		if ( ! is_array( $def ) ) {
			return false;
		}
		if ( in_array( $name, self::NOT_OFFERED, true ) || 0 === strpos( $name, self::NOT_OFFERED_PREFIX ) ) {
			return false;
		}
		if ( in_array( $name, AB_MCP_Guidance::PRO_TOOLS, true ) || in_array( $name, AB_MCP_Guidance::AGENCY_TOOLS, true ) ) {
			return false;
		}
		if ( ! isset( $def['capability'] ) || ! is_string( $def['capability'] ) || '' === $def['capability'] ) {
			return false;
		}
		if ( ! self::is_own_callback( isset( $def['callback'] ) ? $def['callback'] : null ) ) {
			return false;
		}
		return 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', self::ability_name( $name ) );
	}

	/**
	 * Whether a callback is this plugin's: the file that defines its class
	 * (for a method; the class named, not the one a method is inherited
	 * from) or its function (for a function or closure) lies under
	 * includes/ of this plugin.
	 *
	 * @param mixed $callback Callback.
	 * @return bool
	 */
	public static function is_own_callback( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && isset( $callback[0] ) ) {
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				if ( '' === $class || ! class_exists( $class, false ) ) {
					return false;
				}
				$file = ( new ReflectionClass( $class ) )->getFileName();
			} elseif ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$file = ( new ReflectionFunction( $callback ) )->getFileName();
			} else {
				return false;
			}
		} catch ( \ReflectionException $e ) {
			return false;
		}
		$dir  = realpath( AB_MCP_DIR . 'includes' );
		$real = is_string( $file ) ? realpath( $file ) : false;
		return false !== $dir && false !== $real && 0 === strpos( $real, rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * The tools offered right now: offers(), and switched on, as tools/list
	 * lists them (a switched-off tool is not shown to any assistant).
	 *
	 * @param AB_MCP_Tool_Registry|null $registry Registry; the plugin's by default.
	 * @return array<string,array> Tool name => definition.
	 */
	public static function offered( $registry = null ) {
		$registry = null === $registry ? self::registry() : $registry;
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'all' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $registry->all() as $name => $def ) {
			if ( self::offers( $name, $def ) && AB_MCP_Settings::is_tool_enabled( $name, $def ) ) {
				$out[ (string) $name ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Register the category (action wp_abilities_api_categories_init).
	 */
	public static function register_category() {
		if ( ! self::enabled() || ! self::available() ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'AlphaBridge MCP',
				'description' => 'The tools of AlphaBridge MCP, run with the checks of an AlphaBridge connection: capability, write access, the tool switches, the confirmation of the tools without a way back, and the log.',
			)
		);
	}

	/**
	 * Register one ability per offered tool (action wp_abilities_api_init).
	 */
	public static function register_abilities() {
		if ( ! self::enabled() || ! self::available() ) {
			return;
		}
		foreach ( self::offered() as $name => $def ) {
			wp_register_ability( self::ability_name( $name ), self::args( $name, $def ) );
		}
	}

	/**
	 * The arguments of one ability.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Its definition.
	 * @return array
	 */
	public static function args( $name, array $def ) {
		$name  = (string) $name;
		$label = AB_MCP_Tool_Labels::title( $name, $def );
		$hints = AB_MCP_Tool_Registry::annotations( $name, $def, $label );
		return array(
			'label'               => $label,
			'description'         => (string) ( isset( $def['description'] ) ? $def['description'] : '' ),
			'category'            => self::CATEGORY,
			'input_schema'        => self::input_schema( isset( $def['inputSchema'] ) ? $def['inputSchema'] : null ),
			// No output_schema: WordPress checks every result against it
			// (WP_Ability::validate_output()) and answers a deviation with an
			// error instead of the result; the tools' answers vary with
			// their input and with what the site holds.
			'permission_callback' => static function ( $input = null ) use ( $name ) {
				return self::permission( $name, $input );
			},
			'execute_callback'    => static function ( $input = null ) use ( $name ) {
				return self::execute( $name, $input );
			},
			'meta'                => array(
				// The adapter maps readonly, destructive and idempotent to
				// its hints and takes openWorldHint as it is.
				'annotations' => array(
					'readonly'      => (bool) $hints['readOnlyHint'],
					'destructive'   => (bool) $hints['destructiveHint'],
					'idempotent'    => (bool) $hints['idempotentHint'],
					'openWorldHint' => (bool) $hints['openWorldHint'],
				),
				// For the MCP adapter only. No public and no show_in_rest:
				// either would open the REST routes of the Abilities API.
				'mcp'         => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		);
	}

	/**
	 * The tool's input schema as an ability takes it: an object schema; no
	 * empty properties (as a PHP object, WordPress's check of the input
	 * fails on the first argument a client passes, and as an empty PHP
	 * array it would be sent as a JSON list; a schema without properties
	 * means the same); a property without a type gets ANY_TYPE.
	 *
	 * @param mixed $schema The tool's inputSchema.
	 * @return array
	 */
	public static function input_schema( $schema ) {
		$schema = is_array( $schema ) ? json_decode( (string) wp_json_encode( $schema ), true ) : null;
		if ( ! is_array( $schema ) ) {
			$schema = array();
		}
		$schema['type'] = 'object';
		return self::schema_part( $schema );
	}

	/**
	 * One level of a schema, as input_schema() describes.
	 *
	 * @param array $s Schema.
	 * @return array
	 */
	private static function schema_part( array $s ) {
		if ( array_key_exists( 'properties', $s ) ) {
			$props = is_array( $s['properties'] ) ? $s['properties'] : array();
			foreach ( $props as $key => $prop ) {
				$props[ $key ] = self::schema_part( self::typed( is_array( $prop ) ? $prop : array() ) );
			}
			if ( array() === $props ) {
				unset( $s['properties'] );
			} else {
				$s['properties'] = $props;
			}
		}
		if ( isset( $s['items'] ) && is_array( $s['items'] ) ) {
			$s['items'] = self::schema_part( self::typed( $s['items'] ) );
		}
		return $s;
	}

	/**
	 * A property schema with a type: ANY_TYPE where it names none.
	 *
	 * @param array $p Property schema.
	 * @return array
	 */
	private static function typed( array $p ) {
		if ( ! isset( $p['type'] ) && ! isset( $p['anyOf'] ) && ! isset( $p['oneOf'] ) ) {
			$p['type'] = self::ANY_TYPE;
		}
		return $p;
	}

	/**
	 * The tool's definition for a call through its ability, or the refusal
	 * with its way: the offer is switched off, or the tool is not offered
	 * (any more): not registered, without a capability, or replaced by one
	 * that is not this plugin's (offers()).
	 *
	 * @param string $name Tool name.
	 * @return array|WP_Error
	 */
	private static function definition( $name ) {
		if ( ! self::enabled() ) {
			return new WP_Error(
				'ab_mcp_own_abilities_off',
				AB_MCP_Guidance::compose(
					/* translators: %s: tool name */
					sprintf( __( 'AlphaBridge\'s tools are not offered as WordPress abilities on this site, so "%s" did not run.', 'alphabridge-mcp' ), $name ),
					__( 'To allow it, an administrator switches on "Offer AlphaBridge\'s tools as WordPress abilities" under Settings → AlphaBridge MCP → Fine-tuning and saves the changes.', 'alphabridge-mcp' ),
					AB_MCP_Guidance::settings_url( self::ANCHOR )
				)
			);
		}
		$registry = self::registry();
		$def      = null === $registry ? null : $registry->get( $name );
		if ( ! self::offers( $name, $def ) ) {
			return new WP_Error(
				'ab_mcp_own_ability_not_offered',
				AB_MCP_Guidance::compose(
					/* translators: %s: tool name */
					sprintf( __( 'The tool "%s" is not offered as a WordPress ability on this site: it runs only through an AlphaBridge connection, with the checks of its access level.', 'alphabridge-mcp' ), $name ),
					__( 'Use it through an AlphaBridge connection instead: an administrator connects the app under Settings → AlphaBridge MCP, and tools/list names the tool there.', 'alphabridge-mcp' ),
					AB_MCP_Guidance::settings_url( 'ab-connections' )
				)
			);
		}
		return $def;
	}

	/**
	 * The permission callback of a tool's ability: definition(), then the
	 * checks of the policy gate without the rate limit
	 * (AB_MCP_Security::permits()). The Abilities API and the adapter ask
	 * it before the run, once or twice; the run itself goes through
	 * AB_MCP_Security::authorize() once and is counted there. A refusal goes
	 * into the log, as tools/call logs one: the call ends there; at most
	 * DENIED_LOG_PER_HOUR of them per account and hour (denied_log_allowed()).
	 *
	 * @param string $name  Tool name.
	 * @param mixed  $input The input; the log notes its keys.
	 * @return true|WP_Error
	 */
	public static function permission( $name, $input = null ) {
		$def = self::definition( $name );
		if ( ! is_wp_error( $def ) ) {
			$def = AB_MCP_Security::permits( $name, $def );
		}
		if ( is_wp_error( $def ) ) {
			if ( self::denied_log_allowed() ) {
				AB_MCP_Audit_Log::record( $name, self::args_of( $input ), 'denied', $def->get_error_message(), self::VIA );
			}
			return $def;
		}
		return true;
	}

	/**
	 * Whether one more refusal of this account is logged. The adapter asks the
	 * permission callback before the run, which never reaches the rate limit;
	 * without a bound an account that may do nothing could fill the log with
	 * refusals and push the earlier entries out (Codex, 30.10.2026). Counted
	 * per account in a fixed hour from its first logged refusal, at most
	 * DENIED_LOG_PER_HOUR; a refusal beyond it still refuses, only the log
	 * line is left out. Not atomic: two requests at the same moment may log
	 * one or two more, which keeps the log from being flooded all the same.
	 *
	 * @param int|null $now Current time (tests).
	 * @return bool
	 */
	public static function denied_log_allowed( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$key   = 'ab_mcp_own_denied_' . (int) get_current_user_id();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['since'], $state['n'] ) || $now - (int) $state['since'] >= HOUR_IN_SECONDS ) {
			$state = array(
				'since' => $now,
				'n'     => 0,
			);
		}
		if ( (int) $state['n'] >= self::DENIED_LOG_PER_HOUR ) {
			return false;
		}
		$state['n'] = (int) $state['n'] + 1;
		// The hour stays the one that began with the first entry.
		set_transient( $key, $state, max( 1, (int) $state['since'] + HOUR_IN_SECONDS - $now ) );
		return true;
	}

	/**
	 * The execute callback of a tool's ability: definition() again, then the
	 * tool through AB_MCP_REST_Controller::run_tool(), as tools/call runs it.
	 *
	 * @param string $name  Tool name.
	 * @param mixed  $input The input.
	 * @return mixed The tool's result (an array), or a WP_Error.
	 */
	public static function execute( $name, $input = null ) {
		$args = self::args_of( $input );
		$def  = self::definition( $name );
		if ( is_wp_error( $def ) ) {
			AB_MCP_Audit_Log::record( $name, $args, 'denied', $def->get_error_message(), self::VIA );
			return $def;
		}
		return AB_MCP_REST_Controller::run_tool( $name, $def, $args, self::VIA );
	}

	/**
	 * An ability's input as a tool's arguments: an object as an array,
	 * anything but an array as none.
	 *
	 * @param mixed $input Input.
	 * @return array
	 */
	private static function args_of( $input ) {
		if ( is_object( $input ) ) {
			$input = json_decode( (string) wp_json_encode( $input ), true );
		}
		return is_array( $input ) ? $input : array();
	}

	/* ------------------------------------------------------- the switch */

	/**
	 * The switch in the fine-tuning, below the tool groups: it posts with
	 * the fine-tuning's one form (form="ab-caps") and is saved on
	 * ab_mcp_fine_save. Not moved by the switches for all tools or a group
	 * (neither ab-tool nor ab-item): it opens a way in, it is no tool.
	 *
	 * @return string HTML, escaped.
	 */
	public static function fine_html() {
		$h  = '<div class="ab-own-abilities" id="' . esc_attr( self::ANCHOR ) . '">';
		$h .= '<input type="hidden" name="' . esc_attr( self::FIELD_SHOWN ) . '" value="1" form="ab-caps">';
		$h .= '<label class="ab-own-abilities__row"><span class="ab-switch"><input type="checkbox" role="switch" class="ab-own-abilities__switch" name="' . esc_attr( self::FIELD ) . '" value="1" form="ab-caps"' . checked( self::enabled(), true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span></span>';
		$h .= '<span class="ab-own-abilities__label">' . esc_html__( 'Offer AlphaBridge\'s tools as WordPress abilities, for the MCP Adapter', 'alphabridge-mcp' ) . '</span></label>';
		$h .= '<p class="ab-note">' . esc_html__( 'Accounts that sign in to the MCP Adapter, for example with an application password, can then use AlphaBridge\'s tools with their WordPress rights, without an AlphaBridge connection. Tools that write run only with write access on, switched-off tools stay off, and undo and the tools for the abilities of other plugins are not offered. Off until you switch it on here; switching on write access does not switch it on.', 'alphabridge-mcp' ) . '</p>';
		if ( ! self::available() ) {
			$version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
			/* translators: %s: WordPress version */
			$h .= '<p class="ab-note">' . esc_html( sprintf( __( 'This site runs WordPress %s. The Abilities API needs WordPress 6.9 or newer; until WordPress is updated under Dashboard → Updates, nothing is offered.', 'alphabridge-mcp' ), '' !== $version ? $version : '?' ) ) . '</p>';
		}
		$h .= '</div>';
		return $h;
	}

	/**
	 * Save the switch with the fine-tuning (action ab_mcp_fine_save; the
	 * capability and the nonce are checked there). Only when the form showed
	 * it; a change goes into the log with who made it.
	 *
	 * @param array $posted The posted fields, unslashed.
	 */
	public static function fine_save( $posted ) {
		if ( ! is_array( $posted ) || empty( $posted[ self::FIELD_SHOWN ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$on = ! empty( $posted[ self::FIELD ] );
		if ( $on === self::enabled() ) {
			return;
		}
		AB_MCP_Settings::set( self::SETTING, $on );
		$user  = wp_get_current_user();
		$login = is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '';
		AB_MCP_Audit_Log::record(
			'own_abilities',
			array(),
			'ok',
			sprintf(
				$on ? 'AlphaBridge\'s tools offered as WordPress abilities (MCP adapter): switched on by %1$s (user %2$d).' : 'AlphaBridge\'s tools offered as WordPress abilities (MCP adapter): switched off by %1$s (user %2$d).',
				'' !== $login ? $login : '?',
				get_current_user_id()
			)
		);
	}
}
