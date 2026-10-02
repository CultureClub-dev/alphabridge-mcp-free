<?php
/**
 * Tool registry – stores tool definitions.
 *
 * A tool definition is an associative array:
 *   - title       (string)  Human title.
 *   - description (string)  What the tool does (shown to the AI client).
 *   - inputSchema (array)   JSON Schema object describing arguments.
 *   - capability  (string)  Required WordPress capability, or null.
 *   - dangerous   (bool)    Powerful: marked «Mighty» on the settings page.
 *   - callback    (callable) function( array $args ): array|string|WP_Error.
 *   - group, group_label (string) The functional group; stamped from
 *                 set_current_group() unless the definition names one.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tool_Registry
 */
class AB_MCP_Tool_Registry {

	/**
	 * Name prefixes of tools that only ever add something new. Everything else
	 * that writes counts as destructive — see is_destructive().
	 */
	private const ADDITIVE_PREFIXES = array(
		'wp_create_',
		'wp_add_',
		'wp_upload_',
		'wp_duplicate_',
		'wp_reply_',
		'wp_network_create_',
		'wp_wc_create_',
		'wp_wc_add_',
	);

	/**
	 * Tools a "content" token may run whatever capability they are registered
	 * with: a decision per tool, on top of the capability rule in
	 * scope_allows(), which stays the general test.
	 *
	 * wp_update_builder_element (Pro) is the tool for the texts of pages built
	 * with a page builder. A site handed over to a client who keeps those
	 * texts up to date gets a content token, and this is the tool the client
	 * needs. Its fields are typed and checked (text, heading, filtered HTML,
	 * link, image); layout, styles and code of the page are not among them,
	 * so naming it here gives a content token no reach into those.
	 */
	private const CONTENT_TOOLS = array( 'wp_update_builder_element' );

	/**
	 * Registered tools keyed by name.
	 *
	 * @var array<string,array>
	 */
	private $tools = array();

	/**
	 * Current functional group stamped onto tools as they are registered.
	 *
	 * @var array{slug:string,label:string}
	 */
	private $current_group = array(
		'slug'  => 'other',
		'label' => 'Sonstiges',
	);

	/**
	 * Set the functional group for subsequently registered tools. The loader
	 * calls this before each tool class registers, so every tool is tagged with
	 * a human group (e.g. "Posts & Pages") without touching each definition.
	 *
	 * @param string $slug  Group slug.
	 * @param string $label Human label.
	 */
	public function set_current_group( $slug, $label ) {
		$this->current_group = array(
			'slug'  => (string) $slug,
			'label' => (string) $label,
		);
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name Tool name (snake_case, stable API).
	 * @param array  $def  Definition (see class docblock).
	 */
	public function register( $name, array $def ) {
		$def = wp_parse_args(
			$def,
			array(
				'title'       => $name,
				'description' => '',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'capability'  => null,
				'dangerous'   => false,
				'readonly'    => null,
				'callback'    => null,
				'group'       => '',
				'group_label' => '',
			)
		);

		if ( '' === $def['group'] ) {
			$def['group']       = $this->current_group['slug'];
			$def['group_label'] = $this->current_group['label'];
		}

		$this->tools[ $name ] = $def;
	}

	/**
	 * The MCP annotation object for one tool, exactly as it goes over the wire.
	 *
	 * It lives here rather than in the REST controller so the answer can be
	 * measured: the controller needs WordPress around it, this does not. The
	 * controller keeps the one line that calls this.
	 *
	 * @param string $name  Tool name.
	 * @param array  $def   Tool definition.
	 * @param string $title Human title, resolved by the caller.
	 * @return array
	 */
	public static function annotations( $name, array $def, $title ) {
		$read_only = self::is_read_only( $name, $def );
		return array(
			'title'           => (string) $title,
			'readOnlyHint'    => $read_only,
			'destructiveHint' => self::is_destructive( $name, $def ),
			'idempotentHint'  => $read_only,
			'openWorldHint'   => self::is_open_world( $name, $def ),
		);
	}

	/**
	 * Does this tool change or destroy something that is already there?
	 *
	 * This is the MCP `destructiveHint`, and it is NOT the same question as the
	 * `dangerous` flag next to it. `dangerous` marks tools worth a second
	 * thought («Mighty» on the settings page).
	 * `destructiveHint` tells the client whether a call overwrites existing
	 * state, and clients use it to decide whether to ask the user first.
	 *
	 * The two were the same thing here until 15.09.2026, and the result was a
	 * false statement: `wp_update_post` overwrites a post and announced
	 * `destructiveHint: false`, which the protocol defines as "performs only
	 * additive updates". Four tools said that. A client that trusts the hint —
	 * and it is there to be trusted — could overwrite a page without asking.
	 *
	 * So the default follows the protocol's own: anything that writes is
	 * destructive unless it demonstrably only adds. Creating, uploading,
	 * duplicating and replying add; everything else that writes is assumed to
	 * change. A tool that knows better says so with `destructive`.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_destructive( $name, array $def ) {
		if ( isset( $def['destructive'] ) && null !== $def['destructive'] ) {
			return (bool) $def['destructive'];
		}
		// Meaningful only for writing tools; the protocol says so, and a
		// reading tool that answered "destructive" would be nonsense.
		if ( self::is_read_only( $name, $def ) ) {
			return false;
		}
		foreach ( self::ADDITIVE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Central read-only classification. A definition may force the flag with
	 * 'readonly' => true|false; otherwise the (stable, snake_case) tool name
	 * decides. Drives the MCP readOnlyHint annotation, the site mode Read
	 * (AB_MCP_Site_Mode) AND the "read" token scope, so keep this list in sync
	 * when new tools are added — a new writing tool that is missed here would
	 * be wrongly allowed in Read and for read-scoped tokens. Unknown names
	 * default to "not read-only" (fail-closed).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_read_only( $name, array $def ) {
		if ( isset( $def['readonly'] ) && null !== $def['readonly'] ) {
			return (bool) $def['readonly'];
		}
		foreach ( array( 'wp_list_', 'wp_get_', 'wp_read_', 'wp_wc_list_', 'wp_wc_get_' ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		$reads = array(
			'wp_search',
			'wp_db_query',
			'wp_db_tables',
			'wp_db_schema',
			'wp_site_info',
			'wp_site_health',
			'wp_dashboard_counts',
			'wp_deploy_list',
			'wp_deploy_read',
			'wp_deploy_test',
			'wp_network_list_sites',
			'wp_seo_detect',
			'wp_seo_get',
		);
		return in_array( $name, $reads, true );
	}

	/**
	 * Capabilities that count as "content work" — the everyday editorial powers.
	 * Deliberately an allow-list: a tool whose capability is not in here (an
	 * administrative one such as manage_options or install_plugins, or none at
	 * all) is NOT content-level, so a content-scoped token cannot run it. New
	 * tools therefore start out excluded rather than silently included.
	 *
	 * @return string[]
	 */
	public static function content_capabilities() {
		return array(
			'read',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'edit_pages',
			'edit_others_pages',
			'publish_pages',
			'delete_pages',
			'upload_files',
			'manage_categories',
			'moderate_comments',
		);
	}

	/**
	 * The scopes a token can carry, narrowest first, each with one sentence
	 * on what a connection with it may do and what not. The consent page shows
	 * the sentence under the name from scope_labels().
	 *
	 * The content sentence names what a content token changes: the tools it
	 * runs need a capability from content_capabilities() or a name in
	 * CONTENT_TOOLS, and among those are the delete tools and the ones for
	 * terms and comments. Theme and plugin files, options and the database
	 * need capabilities outside that list, hence the second half. It makes no
	 * promise about code in content: for an account with unfiltered_html
	 * WordPress stores markup as it is, scripts included, whatever the scope.
	 *
	 * @return array Slug => sentence.
	 */
	public static function scopes() {
		return array(
			'read'    => __( 'Look at everything this account may see; change nothing.', 'alphabridge-mcp' ),
			'content' => __( 'Read, and change or delete posts, pages, media, terms and comments; no theme or plugin files and no site settings.', 'alphabridge-mcp' ),
			'full'    => __( 'Everything this account may do with the tools switched on here, settings and administration included.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * Short names of the scopes, slug => name.
	 *
	 * @return array<string,string>
	 */
	public static function scope_labels() {
		return array(
			'read'    => __( 'Read only', 'alphabridge-mcp' ),
			'content' => __( 'Content', 'alphabridge-mcp' ),
			'full'    => __( 'Full', 'alphabridge-mcp' ),
		);
	}

	/**
	 * May a token with this scope run this tool?
	 *
	 * The scope narrows what a token can do WITHIN what its user may do — it
	 * never widens it. An empty/unknown scope means "full", so tokens created
	 * before scopes existed keep working exactly as before.
	 *
	 * Both narrower scopes are fail-closed: "read" reuses the same read
	 * classification the site mode Read uses (an unrecognised tool counts
	 * as writing), and "content" additionally needs the tool's capability to be
	 * an explicitly content-level one, or the tool to be named in CONTENT_TOOLS.
	 *
	 * @param string $scope Token scope.
	 * @param string $name  Tool name.
	 * @param array  $def   Tool definition.
	 * @return bool
	 */
	public static function scope_allows( $scope, $name, array $def ) {
		$scope = (string) $scope;
		if ( '' === $scope || 'full' === $scope ) {
			return true;
		}
		if ( self::is_read_only( $name, $def ) ) {
			return true; // Reading is included in every scope.
		}
		if ( 'read' === $scope ) {
			return false;
		}
		if ( 'content' === $scope ) {
			if ( in_array( (string) $name, self::CONTENT_TOOLS, true ) ) {
				return true;
			}
			$cap = isset( $def['capability'] ) ? (string) $def['capability'] : '';
			return '' !== $cap && in_array( $cap, self::content_capabilities(), true );
		}
		return false; // Unknown scope: deny.
	}

	/**
	 * Does a tool interact with systems beyond this WordPress installation
	 * (remote downloads, FTP/SFTP)? Drives the MCP openWorldHint annotation.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_open_world( $name, array $def ) {
		unset( $def );
		if ( 0 === strpos( $name, 'wp_deploy_' ) ) {
			return true;
		}
		$networked = array(
			'wp_install_plugin',
			'wp_install_theme',
			'wp_update_plugin',
			'wp_update_theme',
			'wp_update_core',
			'wp_upload_media_from_url',
		);
		return in_array( $name, $networked, true );
	}

	/**
	 * Get one tool definition.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * All tools.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		return $this->tools;
	}

	/**
	 * Count registered tools.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->tools );
	}

	/**
	 * The group a tool belongs to.
	 *
	 * @param array $def Tool definition.
	 * @return string
	 */
	public static function group_of( array $def ) {
		return isset( $def['group'] ) && '' !== $def['group'] ? (string) $def['group'] : 'other';
	}

	/**
	 * Aggregate tools into their functional groups, in first-seen order.
	 * A group is flagged "mighty" when it holds at least one dangerous tool.
	 *
	 * @return array<string,array{label:string,mighty:bool,tools:string[],count:int}>
	 */
	public function groups() {
		$groups = array();
		foreach ( $this->tools as $name => $def ) {
			$slug = self::group_of( $def );
			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = array(
					'label'  => isset( $def['group_label'] ) && '' !== $def['group_label'] ? $def['group_label'] : $slug,
					'mighty' => false,
					'tools'  => array(),
					'count'  => 0,
				);
			}
			$groups[ $slug ]['tools'][] = $name;
			$groups[ $slug ]['count']++;
			if ( ! empty( $def['dangerous'] ) ) {
				$groups[ $slug ]['mighty'] = true;
			}
		}
		return $groups;
	}
}
