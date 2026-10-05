<?php
/**
 * Admin settings screen (submenu under Settings).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Admin
 */
class AB_MCP_Admin {

	/**
	 * The main group of each tool group of the core and of the Pro add-on on
	 * the fine-tuning. Only the display is grouped: the switches, the group a
	 * tool carries in the registry, the access levels and everything a client
	 * sees stay as they are. A tool group missing here goes to «More tools»
	 * unless an add-on names its main group (filter ab_mcp_tool_main_group).
	 */
	const MAIN_OF = array(
		'content'        => 'content',
		'builders'       => 'content',
		'media'          => 'content',
		'taxonomy'       => 'content',
		'widgets'        => 'content',
		'seo'            => 'content',
		'search'         => 'content',
		'options'        => 'site',
		'users'          => 'site',
		'meta-auth'      => 'site',
		'multisite'      => 'site',
		'lifecycle'      => 'code',
		'plugins-themes' => 'code',
		'files'          => 'code',
		'php-snippets'   => 'code',
		'deploy'         => 'code',
		'transfer'       => 'code',
		'blueprint'      => 'code',
		'migration'      => 'code',
		'database'       => 'system',
		'system'         => 'system',
		'ops'            => 'system',
		'woocommerce'    => 'shop',
		'undo'           => 'undo',
		'abilities'      => 'abilities',
	);

	/**
	 * The main group that takes every tool group no other main group claims.
	 */
	const MAIN_MORE = 'more';

	/**
	 * The tool registry, handed in by the plugin; looked up when not.
	 *
	 * @var AB_MCP_Tool_Registry|null
	 */
	private $registry;

	/**
	 * Hooks.
	 *
	 * @param AB_MCP_Tool_Registry|null $registry The plugin's registry. The plugin
	 *                                            builds this screen while it is
	 *                                            itself still being built, so it
	 *                                            hands its registry in rather than
	 *                                            have it looked up then.
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry instanceof AB_MCP_Tool_Registry ? $registry : null;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'plugin_action_links_' . AB_MCP_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ab_mcp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ab_mcp_site_mode', array( $this, 'handle_site_mode' ) );
		add_action( 'admin_post_ab_mcp_mode_notice', array( $this, 'handle_mode_notice_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'mode_notice' ) );
		add_action( 'admin_post_ab_mcp_token', array( $this, 'handle_token' ) );
		add_action( 'admin_post_ab_mcp_connector_auth', array( $this, 'handle_connector_auth' ) );
		add_action( 'admin_post_ab_mcp_oauth_settings', array( $this, 'handle_oauth_settings' ) );
		add_action( 'admin_post_ab_mcp_protocol_settings', array( $this, 'handle_protocol_settings' ) );
		add_action( 'admin_post_ab_mcp_audit_clear', array( $this, 'handle_audit_clear' ) );
		add_action( 'admin_post_ab_mcp_review_dismiss', array( $this, 'handle_review_dismiss' ) );
		// Creating or rotating a token reveals a secret. That is done over
		// authenticated admin-ajax so the plaintext is returned once, directly to
		// the admin's own browser, and is never written to the database — not even
		// to a short-lived transient (which, without a persistent object cache,
		// lands in wp_options).
		add_action( 'wp_ajax_ab_mcp_create_token', array( $this, 'ajax_create_token' ) );
		add_action( 'wp_ajax_ab_mcp_rotate_token', array( $this, 'ajax_rotate_token' ) );
		add_action( 'wp_ajax_ab_mcp_enable_url_auth', array( $this, 'ajax_enable_url_auth' ) );
	}

	/**
	 * Register the menu as a sub-item of Settings.
	 */
	public function menu() {
		add_options_page(
			'AlphaBridge MCP',
			'AlphaBridge MCP',
			'manage_options',
			'alphabridge-mcp',
			array( $this, 'render' )
		);
	}

	/**
	 * The «Settings» link in this plugin's row of the plugin list, first in
	 * the row as WordPress plugins usually put it, so the page is one click
	 * away right after activation. Only for those who may open the page; the
	 * others would land on WordPress's «not allowed».
	 *
	 * Bundled in Pro, this file is not a plugin of its own and the filter
	 * never fires; Pro puts the link into its own row.
	 *
	 * @param array $links The row's links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$settings = '<a href="' . esc_url( AB_MCP_Guidance::settings_url() ) . '">' . esc_html__( 'Settings', 'alphabridge-mcp' ) . '</a>';
		return array( 'settings' => $settings ) + $links;
	}

	/**
	 * The tool registry.
	 *
	 * @return AB_MCP_Tool_Registry
	 */
	private function registry() {
		if ( null === $this->registry ) {
			$this->registry = AB_MCP_Plugin::instance()->registry;
		}
		return $this->registry;
	}

	/**
	 * Capability guard.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'alphabridge-mcp' ) );
		}
	}

	/**
	 * Save the fine-tuning: the tool switches, and whatever an add-on put
	 * into the same form (filter ab_mcp_fine_group_html), which the add-on
	 * saves on the action ab_mcp_fine_save. One button saves both.
	 */
	public function handle_save() {
		$this->guard();
		check_admin_referer( 'ab_mcp_save' );

		$registry = $this->registry();
		$enabled  = isset( $_POST['enabled_tools'] ) && is_array( $_POST['enabled_tools'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_tools'] ) )
			: array();

		$state = array();
		foreach ( $registry->all() as $name => $def ) {
			$state[ $name ] = in_array( $name, $enabled, true );
		}
		AB_MCP_Settings::set_tool_state( $state );

		/**
		 * Fires when the fine-tuning is saved, after the core stored the tool
		 * switches. The capability and the form's nonce (ab_mcp_save) are
		 * checked; an add-on stores the fields it added to the form
		 * (ab_mcp_fine_group_html), sanitizing them itself.
		 *
		 * @since 4.5.0
		 *
		 * @param array $posted The posted fields, unslashed.
		 */
		do_action( 'ab_mcp_fine_save', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each add-on sanitizes the fields it reads; the nonce is checked above.

		// A connected client may cache the tool list it loaded, and this endpoint
		// offers no server-initiated stream, so it cannot push a
		// notifications/tools/list_changed. Say what to do if the change is not visible.
		$this->redirect( 'tools_saved', 'ab-fine' );
	}

	/**
	 * Switch write access on or off (AB_MCP_Site_Mode).
	 *
	 * On only with the box under the notice ticked, and only for the notice
	 * in force: a form loaded under an earlier version of the notice, or
	 * showing another wording of it (a language pack changed since, or
	 * another language), is refused, so the record never names a text the
	 * administrator did not have on the screen. Off needs no box; it only
	 * takes away. Switching on switches every tool on
	 * (AB_MCP_Site_Mode::switch_to_full()); the connections and their access
	 * levels stay as they are. A form to switch on that arrives while write
	 * access is already on (an old tab, a second click) changes nothing.
	 */
	public function handle_site_mode() {
		$this->guard();
		check_admin_referer( 'ab_mcp_site_mode' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( AB_MCP_Site_Mode::READ === $mode ) {
			AB_MCP_Site_Mode::switch_to_read( get_current_user_id(), AB_MCP_Site_Mode::SOURCE_FORM );
			$this->redirect( 'mode_read' );
		}
		if ( AB_MCP_Site_Mode::FULL !== $mode ) {
			$this->redirect( 'mode_unknown' );
		}
		if ( AB_MCP_Site_Mode::is_full() ) {
			$this->redirect( 'mode_already_full' );
		}
		$version = isset( $_POST['notice_version'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_version'] ) ) : '';
		$hash    = isset( $_POST['notice_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_hash'] ) ) : '';
		if ( AB_MCP_Site_Mode::NOTICE_VERSION !== $version || ! hash_equals( AB_MCP_Site_Mode::notice_hash(), $hash ) ) {
			$this->redirect( 'mode_stale' );
		}
		$confirmed = isset( $_POST['confirm_full'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_full'] ) ) : '';
		if ( '1' !== $confirmed ) {
			$this->redirect( 'mode_unconfirmed' );
		}
		AB_MCP_Site_Mode::switch_to_full( get_current_user_id(), AB_MCP_Site_Mode::SOURCE_FORM );
		$this->redirect( 'mode_full' );
	}

	/**
	 * «Dismiss» on the notice about the update that brought write access.
	 */
	public function handle_mode_notice_dismiss() {
		$this->guard();
		check_admin_referer( 'ab_mcp_mode_notice' );
		AB_MCP_Settings::set( AB_MCP_Settings::KEY_MODE_NOTICE, false );
		$this->redirect( 'mode_notice_dismissed' );
	}

	/**
	 * The notice after the update that brought write access (from a version
	 * before 4.4.0), on the admin screens of administrators until one of them
	 * dismisses it or switches (AB_MCP_Settings::KEY_MODE_NOTICE). The
	 * plugin's own page shows it right under the switch instead (render()),
	 * so the switch stays the first thing there.
	 */
	public function mode_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides where the notice is shown; no state is changed.
		if ( isset( $_GET['page'] ) && 'alphabridge-mcp' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		echo $this->mode_notice_html( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside mode_notice_html().
	}

	/**
	 * The notice after the update: the site reads only now, and where write
	 * access is switched on. A form, so that dismissing works without
	 * JavaScript.
	 *
	 * @param bool $elsewhere Shown on another screen than the plugin's own,
	 *                        with a link to it.
	 * @return string HTML, escaped.
	 */
	private function mode_notice_html( $elsewhere ) {
		$html  = '<div class="notice notice-warning ab-mode-notice"><p><strong>' . esc_html__( 'AlphaBridge MCP now only reads on this site.', 'alphabridge-mcp' ) . '</strong> ';
		$html .= esc_html__( 'Since this update every site starts with write access off: AI assistants can read content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes is refused, and so are the reading tools noted “only with write access” under Fine-tuning, which read code, files, the database, logs or credentials. To let them write again, an administrator switches on write access at the top of Settings → AlphaBridge MCP, at the site owner\'s own risk.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Switching on write access switches every tool on; afterwards you can switch single tools off under Fine-tuning.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p class="ab-mode-notice__actions">';
		if ( $elsewhere ) {
			$html .= '<a class="button button-primary" href="' . esc_url( admin_url( 'options-general.php?page=alphabridge-mcp' ) ) . '">' . esc_html__( 'Open Settings → AlphaBridge MCP', 'alphabridge-mcp' ) . '</a> ';
		}
		$html .= wp_nonce_field( 'ab_mcp_mode_notice', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_mode_notice"><button type="submit" class="button">' . esc_html__( 'Dismiss', 'alphabridge-mcp' ) . '</button></p></form></div>';
		return $html;
	}

	/**
	 * A small icon, drawn here so nothing loads from outside. Always beside
	 * a word that says the same, so it is hidden from screen readers.
	 *
	 * @param string $name power, eye, pen, bolt or chevron.
	 * @param int    $size Width and height in pixels.
	 * @return string SVG.
	 */
	private static function icon( $name, $size = 16 ) {
		$paths = array(
			'power'   => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
			'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'pen'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
			'bolt'    => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
			'chevron' => '<path d="M9 6l6 6-6 6"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="ab-ico ab-ico--' . $name . '" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * The main switch, the first thing on the page: «Write access for AI
	 * assistants», for every connection alike. Off (the default) is calm;
	 * on turns the card deep violet and the switch yellow, and says since
	 * when and by whom it was confirmed.
	 *
	 * The switch is a button with role switch. Off, it submits the form that
	 * switches on, and that form asks for the box under the notice: with
	 * JavaScript the notice, the box, «Switch on» and «Cancel» open as a small
	 * window at the switch (admin.js); without it they stand in the card as a
	 * plain form, and the browser asks for the box (required) before it
	 * sends. handle_site_mode() refuses the switch without the box all the
	 * same. On, the switch submits the form that switches off: one click, no
	 * question.
	 *
	 * Two boxes say what each state means. The one for «off» says what
	 * assistants read; an add-on whose tools read more there adds one
	 * sentence of its own (filter ab_mcp_read_mode_reads_also). The one for
	 * «on» names what the tools of this site write: the core's alone, or with
	 * a licensed Pro add-on (ab_mcp_site_edition) the code, files and database
	 * too, so it never promises what the site cannot do.
	 *
	 * @return string HTML, escaped.
	 */
	private function mode_card_html() {
		$full = AB_MCP_Site_Mode::is_full();

		$html  = '<section class="ab-mode ab-mode--' . ( $full ? 'full' : 'read' ) . '" id="ab-mode" aria-labelledby="ab-mode-title">';
		$html .= '<p class="ab-mode__eyebrow">' . esc_html__( 'AlphaBridge MCP on this site', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="ab-mode__head"><div class="ab-mode__text">';
		$html .= '<h2 class="ab-mode__title" id="ab-mode-title">' . esc_html__( 'Write access for AI assistants', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-mode__status" id="ab-mode-status">' . esc_html(
			$full
				? __( 'On: full power. Connected AI assistants read and write on this site.', 'alphabridge-mcp' )
				: __( 'Off: AI assistants only read. For anything they should change, you flip the switch.', 'alphabridge-mcp' )
		) . '</p>';
		$html .= '<p class="ab-mode__scope">' . esc_html__( 'Applies to every connection: Claude, ChatGPT, Cursor and all others.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';
		$html .= '<button type="submit" form="ab-mode-form" class="ab-power" role="switch" aria-checked="' . ( $full ? 'true' : 'false' ) . '" aria-labelledby="ab-mode-title" aria-describedby="ab-mode-status"' . ( $full ? '' : ' aria-haspopup="dialog" aria-controls="ab-mode-confirm" aria-expanded="false"' ) . '>';
		$html .= '<span class="ab-power__word ab-power__word--on" aria-hidden="true">' . esc_html( _x( 'On', 'main switch', 'alphabridge-mcp' ) ) . '</span>';
		$html .= '<span class="ab-power__word ab-power__word--off" aria-hidden="true">' . esc_html( _x( 'Off', 'main switch', 'alphabridge-mcp' ) ) . '</span>';
		$html .= '<span class="ab-power__knob" aria-hidden="true">' . self::icon( 'power', 34 ) . '</span>';
		$html .= '</button></div>';

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-mode__form" id="ab-mode-form">';
		$html .= wp_nonce_field( 'ab_mcp_site_mode', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_site_mode">';
		if ( $full ) {
			$html .= '<input type="hidden" name="mode" value="' . esc_attr( AB_MCP_Site_Mode::READ ) . '">';
		} else {
			$html .= '<input type="hidden" name="mode" value="' . esc_attr( AB_MCP_Site_Mode::FULL ) . '">';
			$html .= '<input type="hidden" name="notice_version" value="' . esc_attr( AB_MCP_Site_Mode::NOTICE_VERSION ) . '">';
			$html .= '<input type="hidden" name="notice_hash" value="' . esc_attr( AB_MCP_Site_Mode::notice_hash() ) . '">';
			$html .= '<div class="ab-mode__dialog" id="ab-mode-confirm" aria-labelledby="ab-mode-confirm-title" aria-describedby="ab-mode-warning">';
			$html .= '<h3 class="ab-mode__dialog-title" id="ab-mode-confirm-title" tabindex="-1">' . esc_html__( 'Switch on write access?', 'alphabridge-mcp' ) . '</h3>';
			$html .= '<p class="ab-mode__warning" id="ab-mode-warning">' . esc_html( AB_MCP_Site_Mode::notice_text() ) . '</p>';
			$html .= '<label class="ab-mode__confirm"><input type="checkbox" name="confirm_full" value="1" required><span>' . esc_html( AB_MCP_Site_Mode::checkbox_text() ) . '</span></label>';
			$html .= '<p class="ab-mode__actions"><button type="button" class="ab-btn ab-btn--ghost ab-mode__cancel" hidden>' . esc_html__( 'Cancel', 'alphabridge-mcp' ) . '</button><button type="submit" class="ab-btn ab-mode__go">' . esc_html__( 'Switch on', 'alphabridge-mcp' ) . '</button></p>';
			$html .= '</div>';
		}
		$html .= '</form>';

		$reads = __( 'AI assistants read content, media and settings and advise you.', 'alphabridge-mcp' );
		/**
		 * What the tools of an add-on read with write access off beyond the
		 * box «Off: read only», as one sentence, or '' for nothing. Escaped
		 * here. AB_MCP_Admin::addon_text() has the Pro add-on's sentences in
		 * the core's languages.
		 *
		 * @param string               $also     Sentence, '' by default.
		 * @param AB_MCP_Tool_Registry $registry The tools of this site.
		 */
		$also = trim( (string) apply_filters( 'ab_mcp_read_mode_reads_also', '', $this->registry() ) );
		if ( '' !== $also ) {
			$reads .= ' ' . $also;
		}
		$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );
		$writes  = in_array( $edition, array( 'pro', 'agency' ), true )
			? __( 'AI assistants read and write: posts, pages, plugins, themes, code and the database.', 'alphabridge-mcp' )
			: __( 'AI assistants read and write: posts, pages, media, terms and comments.', 'alphabridge-mcp' );

		$html .= '<div class="ab-mode__boxes">';
		$html .= '<div class="ab-mode__box ab-mode__box--off' . ( $full ? '' : ' is-current' ) . '">' . self::icon( 'eye', 22 ) . '<p><strong>' . esc_html__( 'Off: read only', 'alphabridge-mcp' ) . '</strong><span class="ab-mode__reads">' . esc_html( $reads ) . '</span></p></div>';
		$html .= '<div class="ab-mode__box ab-mode__box--on' . ( $full ? ' is-current' : '' ) . '">' . self::icon( 'bolt', 22 ) . '<p><strong>' . esc_html__( 'On: full power', 'alphabridge-mcp' ) . '</strong><span class="ab-mode__writes">' . esc_html( $writes ) . '</span></p></div>';
		$html .= '</div>';
		if ( $full ) {
			$html .= '<p class="ab-mode__since">' . esc_html( AB_MCP_Site_Mode::full_since_text() ) . '</p>';
		}
		$html .= '</section>';

		return $html;
	}

	/**
	 * Rename or delete a token. Creating and rotating (which reveal a secret) are
	 * handled over authenticated ajax instead — see ajax_create_token() /
	 * ajax_rotate_token() — so no plaintext token is ever persisted.
	 */
	public function handle_token() {
		$this->guard();
		check_admin_referer( 'ab_mcp_token' );

		if ( isset( $_POST['rename_token'] ) ) {
			AB_MCP_Settings::rename_token(
				sanitize_text_field( wp_unslash( $_POST['rename_token'] ) ),
				sanitize_text_field( wp_unslash( $_POST['rename_label'] ?? '' ) )
			);
			$this->redirect( 'saved' );
		}

		if ( isset( $_POST['delete_token'] ) ) {
			AB_MCP_Settings::delete_token( sanitize_text_field( wp_unslash( $_POST['delete_token'] ) ) );
			$this->redirect( 'token_deleted' );
		}

		$this->redirect( 'saved' );
	}

	/**
	 * Toggle connector-URL (token-in-path) authentication on or off.
	 */
	public function handle_connector_auth() {
		$this->guard();
		check_admin_referer( 'ab_mcp_connector_auth' );
		AB_MCP_Settings::set( 'connector_url_auth_enabled', isset( $_POST['connector_url_auth_enabled'] ) );
		$this->redirect( 'saved' );
	}

	/**
	 * Toggle AlphaBridge Connect (Claude's native Connect button / OAuth) on or
	 * off. Off = the well-known metadata, consent page and OAuth endpoints all
	 * disappear; existing connections keep working (they are ordinary tokens).
	 * The second switch in the same form decides whether apps may identify
	 * themselves with a client metadata document (AB_MCP_OAuth::cimd_enabled()).
	 */
	public function handle_oauth_settings() {
		$this->guard();
		check_admin_referer( 'ab_mcp_oauth_settings' );
		AB_MCP_Settings::set( 'oauth_enabled', isset( $_POST['oauth_enabled'] ) );
		AB_MCP_Settings::set( 'oauth_cimd', isset( $_POST['oauth_cimd'] ) );
		$this->redirect( 'saved' );
	}

	/**
	 * Switch MCP revision 2026-07-28 on or off. Off = the endpoint answers the
	 * older revisions only; clients that speak both fall back to the older one.
	 */
	public function handle_protocol_settings() {
		$this->guard();
		check_admin_referer( 'ab_mcp_protocol_settings' );
		AB_MCP_Settings::set( 'modern_protocol', isset( $_POST['modern_protocol'] ) );
		$this->redirect( 'saved' );
	}

	/**
	 * AJAX: create a connection (token) and return the plaintext ONCE.
	 *
	 * The secret is sent only in this JSON response, straight to the authenticated
	 * admin's browser, and is never stored in readable form.
	 */
	public function ajax_create_token() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : get_current_user_id();
		// The token acts as that user; only someone who may edit the user may
		// hand out its authority (see AB_MCP_Auth::may_issue_token_for()).
		if ( ! AB_MCP_Auth::may_issue_token_for( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot create a connection for that user.', 'alphabridge-mcp' ) ), 403 );
		}
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( '' === $label ) {
			$label = __( 'Connection', 'alphabridge-mcp' );
		}
		$scope   = AB_MCP_Settings::sanitize_scope(
			isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'full'
		);
		$days    = isset( $_POST['expires_days'] ) ? absint( wp_unslash( $_POST['expires_days'] ) ) : 0;
		$days    = min( $days, 3650 ); // Cap at ~10 years so a fat-fingered value can't overflow.
		$expires = $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0;

		$plain = AB_MCP_Settings::add_token( $user_id, $label, $scope, $expires );
		$this->send_token_json( $plain );
	}

	/**
	 * AJAX: rotate a connection's secret and return the new plaintext ONCE.
	 */
	public function ajax_rotate_token() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		$hash  = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		// Rotating hands out a fresh secret for the entry's user: the same rule
		// as creating one for that user.
		$entry = AB_MCP_Settings::get_token_by_hash( $hash );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Connection not found.', 'alphabridge-mcp' ) ), 404 );
		}
		if ( ! AB_MCP_Auth::may_issue_token_for( isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0 ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot rotate a connection that belongs to that user.', 'alphabridge-mcp' ) ), 403 );
		}
		$plain = AB_MCP_Settings::rotate_token( $hash );
		if ( '' === (string) $plain ) {
			wp_send_json_error( array( 'message' => __( 'Connection not found.', 'alphabridge-mcp' ) ), 404 );
		}
		$this->send_token_json( $plain );
	}

	/**
	 * AJAX: switch connector-URL (token-in-path) authentication on from the reveal
	 * box. Same option as the Advanced form below — this is the one-click path so
	 * that a connector URL is only ever shown when it will actually authenticate.
	 */
	public function ajax_enable_url_auth() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		AB_MCP_Settings::set( 'connector_url_auth_enabled', true );
		wp_send_json_success( array( 'pathAuth' => true ) );
	}

	/**
	 * Capability guard for the token ajax endpoints. The nonce is verified inline
	 * in each handler, right after this call, so it is checked before any request
	 * data is read.
	 */
	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'alphabridge-mcp' ) ), 403 );
		}
	}

	/**
	 * Emit the one-time token JSON payload: the plaintext, the (opt-in) connector
	 * URL, and the freshly rendered connections-table row so the UI can update in
	 * place without a reload (which would lose the never-again-shown secret).
	 *
	 * @param string $plain Plaintext token just created or rotated.
	 */
	private function send_token_json( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the connection.', 'alphabridge-mcp' ) ), 500 );
		}

		// add_token()/rotate_token() both leave the affected entry last.
		$tokens = AB_MCP_Settings::get_tokens();
		$entry  = ! empty( $tokens ) ? end( $tokens ) : array();

		$endpoint  = untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
		$path_auth = (bool) AB_MCP_Settings::get( 'connector_url_auth_enabled', false );

		wp_send_json_success(
			array(
				'token'     => $plain,
				'endpoint'  => $endpoint,
				'connector' => $path_auth ? $endpoint . '/' . $plain : '',
				'pathAuth'  => $path_auth,
				'hash'      => isset( $entry['hash'] ) ? (string) $entry['hash'] : '',
				'row'       => is_array( $entry ) && ! empty( $entry ) ? $this->connection_row_html( $entry ) : '',
			)
		);
	}

	/**
	 * Clear audit log.
	 */
	public function handle_audit_clear() {
		$this->guard();
		check_admin_referer( 'ab_mcp_audit_clear' );
		AB_MCP_Audit_Log::clear();
		$this->redirect( 'audit_cleared' );
	}

	/**
	 * «Don't ask again» on the review notice. Final — see AB_MCP_Review_Notice.
	 */
	public function handle_review_dismiss() {
		$this->guard();
		check_admin_referer( 'ab_mcp_review_dismiss' );
		AB_MCP_Review_Notice::dismiss();
		$this->redirect( 'review_dismissed' );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $notice Notice key.
	 * @param string $anchor Id on the page to land on, '' for the top.
	 */
	private function redirect( $notice, $anchor = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'alphabridge-mcp',
					'ab_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			) . ( '' !== $anchor ? '#' . $anchor : '' )
		);
		exit;
	}

	/**
	 * Render the page.
	 *
	 * Top to bottom: the main switch for write access; the band with the
	 * logo, this site's state and the choice of assistant; how to connect the
	 * chosen one; the fine-tuning of the tool switches (open, its main groups
	 * folded); the connections;
	 * boxes from add-ons; the log. The side column carries the Pro box (free
	 * edition only), boxes from add-ons and the guides.
	 */
	public function render() {
		$this->guard();

		$tokens   = AB_MCP_Settings::get_tokens();
		$registry = $this->registry();
		$groups   = $registry->groups();
		$all      = $registry->all();

		echo '<div class="wrap ab-mcp">';
		// The main switch comes first, above the marker below: WordPress moves
		// admin notices right after the marker, so none lands above the switch.
		echo $this->mode_card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside mode_card_html().
		if ( AB_MCP_Settings::get( AB_MCP_Settings::KEY_MODE_NOTICE, false ) ) {
			echo $this->mode_notice_html( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside mode_notice_html().
		}
		// Without the marker admin notices land after the first heading.
		echo '<hr class="wp-header-end">';
		$this->notice();
		// The one-time review request: only here, only once the site has really
		// used the plugin, and never again after «don't ask again».
		echo AB_MCP_Review_Notice::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo $this->hero_html( $tokens, $all ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside hero_html().

		echo '<div class="ab-cols">';
		$this->render_main_column( $tokens, $groups, $all );
		echo $this->sidebar_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside sidebar_html(); add-on boxes escape their own output.
		echo '</div>'; // columns.
		echo '</div>'; // wrap.
	}

	/**
	 * The main column, top to bottom: connect the chosen assistant, the
	 * fine-tuning of the tool switches, the connections, boxes from add-ons,
	 * the log.
	 *
	 * @param array $tokens Stored connections.
	 * @param array $groups Tool groups from the registry.
	 * @param array $all    Every registered tool (name => definition).
	 */
	private function render_main_column( $tokens, $groups, $all ) {
		echo '<div class="ab-main">';
		echo $this->connect_card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside connect_card_html().
		echo $this->capabilities_card_html( $groups, $all ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside capabilities_card_html().
		$this->render_connections_card( $tokens );

		/**
		 * Add-ons render extra boxes in the main column here (e.g. the Pro
		 * add-on's Site-Deploy connection).
		 */
		do_action( 'ab_mcp_admin_main_boxes' );

		$this->render_audit();
		echo '</div>';
	}

	/**
	 * The assistants the connect card knows, in the order they are offered.
	 * Claude and ChatGPT connect with this site's login and approval (OAuth);
	 * Cursor and everything else with a token created here.
	 *
	 * @return array<string, array{name: string, hint: string, mark: string}>
	 */
	private function clients() {
		return array(
			'claude'  => array(
				'name' => 'Claude',
				'hint' => __( 'claude.ai, Desktop, iPhone', 'alphabridge-mcp' ),
				'mark' => 'C',
			),
			'chatgpt' => array(
				'name' => 'ChatGPT',
				'hint' => __( 'Plus, Pro, Business', 'alphabridge-mcp' ),
				'mark' => 'G',
			),
			'cursor'  => array(
				'name' => 'Cursor',
				'hint' => __( 'Code editor', 'alphabridge-mcp' ),
				'mark' => 'Cu',
			),
			'other'   => array(
				'name' => __( 'Other', 'alphabridge-mcp' ),
				'hint' => __( 'Claude Code, scripts', 'alphabridge-mcp' ),
				'mark' => '…',
			),
		);
	}

	/**
	 * The band at the top: logo and name, this site's state in three figures,
	 * and the choice of assistant that the connect card below follows.
	 *
	 * @param array $tokens Stored connections.
	 * @param array $all    Every registered tool (name => definition).
	 * @return string HTML, escaped.
	 */
	private function hero_html( $tokens, $all ) {
		$read = ! AB_MCP_Site_Mode::is_full();
		$on   = 0;
		foreach ( $all as $name => $def ) {
			// With write access off a switched-on tool that writes, or a
			// Mighty one, is refused all the same; counting it as on there
			// would read as «everything works».
			if ( AB_MCP_Settings::is_tool_enabled( $name, $def ) && ( ! $read || AB_MCP_Site_Mode::runs_in_read( (string) $name, (array) $def ) ) ) {
				++$on;
			}
		}
		$count = count( (array) $tokens );
		$site  = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $site ) {
			$site = home_url();
		}

		$html  = '<div class="ab-hero">';
		$html .= '<div class="ab-hero__top">';
		$html .= '<img class="ab-logo" src="' . esc_url( AB_MCP_URL . 'assets/logo-128.png' ) . '" width="44" height="44" alt="">';
		$html .= '<h1 class="ab-hero__brand">AlphaBridge MCP</h1>';
		$html .= '<div class="ab-chips">';
		/* translators: %s: number of connections. */
		$html .= '<span class="ab-chip">' . sprintf( esc_html( _n( '%s connection', '%s connections', $count, 'alphabridge-mcp' ) ), '<b>' . (int) $count . '</b>' ) . '</span>';
		$html .= '<span class="ab-chip">' . sprintf(
			$read
				/* translators: 1: tools that may run with write access off (switched on, only reading and not among those that need write access), 2: all tools. */
				? esc_html__( '%1$s of %2$s tools can run with write access off', 'alphabridge-mcp' )
				/* translators: 1: tools switched on, 2: all tools. */
				: esc_html__( '%1$s of %2$s tools on', 'alphabridge-mcp' ),
			'<b>' . (int) $on . '</b>',
			'<b>' . count( $all ) . '</b>'
		) . '</span>';
		$mode  = AB_MCP_Site_Mode::get();
		$html .= '<a class="ab-chip ab-chip--mode" href="#ab-mode"><span class="ab-dot' . ( AB_MCP_Site_Mode::FULL === $mode ? ' ab-dot--warn' : '' ) . '" aria-hidden="true"></span>' . esc_html( AB_MCP_Site_Mode::label( $mode ) ) . '</a>';
		$html .= '</div></div>';
		$html .= '<h2 class="ab-hero__title">' . esc_html__( 'Connect your AI assistant', 'alphabridge-mcp' ) . '</h2>';
		/* translators: %s: the site's name. */
		$html .= '<p class="ab-hero__lead">' . esc_html( sprintf( __( 'Which one do you want to connect to “%s”?', 'alphabridge-mcp' ), $site ) ) . '</p>';
		$html .= '<div class="ab-clients" role="group" aria-label="' . esc_attr__( 'Assistant', 'alphabridge-mcp' ) . '">';
		foreach ( $this->clients() as $key => $c ) {
			$html .= '<button type="button" class="ab-client" data-client="' . esc_attr( $key ) . '" aria-controls="ab-panel-' . esc_attr( $key ) . '" aria-pressed="' . ( 'claude' === $key ? 'true' : 'false' ) . '">';
			$html .= '<span class="ab-mark ab-mark--' . esc_attr( $key ) . '" aria-hidden="true">' . esc_html( $c['mark'] ) . '</span>';
			$html .= '<span>' . esc_html( $c['name'] ) . '<small>' . esc_html( $c['hint'] ) . '</small></span></button>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The setup video in the admin's language: German for German, English
	 * otherwise. Linked, never embedded — nothing loads from YouTube until the
	 * viewer clicks.
	 *
	 * @return string
	 */
	private static function video_url() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		return 0 === strpos( (string) $locale, 'de' ) ? 'https://www.youtube.com/watch?v=M262gCOc7gM' : 'https://www.youtube.com/watch?v=DlMJ9tfrqJg';
	}

	/**
	 * A read-only field with a copy button.
	 *
	 * @param string $value  What is shown and copied.
	 * @param string $label  The field's accessible name.
	 * @param string $button Button text; «Copy» when empty.
	 * @return string HTML, escaped.
	 */
	private function copy_field( $value, $label, $button = '' ) {
		$button = '' !== $button ? $button : __( 'Copy', 'alphabridge-mcp' );
		return '<div class="ab-field"><input type="text" readonly value="' . esc_attr( $value ) . '" class="ab-select" aria-label="' . esc_attr( $label ) . '"><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy="' . esc_attr( $value ) . '">' . esc_html( $button ) . '</button></div>';
	}

	/**
	 * A link to another site, opening in a new tab, with a small icon.
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @param string $icon  One character.
	 * @return string HTML, escaped.
	 */
	private function ext_link( $url, $label, $icon ) {
		return '<a class="ab-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><span class="ab-link__ico" aria-hidden="true">' . esc_html( $icon ) . '</span>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a>';
	}

	/**
	 * A button-styled link to another site, opening in a new tab.
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @return string HTML, escaped.
	 */
	private function ext_button( $url, $label ) {
		return '<a class="ab-btn ab-btn--sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . ' <span aria-hidden="true">↗</span><span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a>';
	}

	/**
	 * One numbered step.
	 *
	 * @param int    $n     Number.
	 * @param string $title Title (plain text).
	 * @param string $body  HTML, already escaped.
	 * @return string HTML.
	 */
	private function step( $n, $title, $body ) {
		return '<div class="ab-step"><h3 class="ab-step__title"><span class="ab-step__n" aria-hidden="true">' . (int) $n . '</span>' . esc_html( $title ) . '</h3>' . $body . '</div>';
	}

	/**
	 * What Claude or ChatGPT needs while this site's OAuth flow is switched off.
	 * Both connect with this site's login and approval; the directory entry and
	 * ChatGPT's app cannot work without it, so they are not offered then.
	 *
	 * @param string $name Claude or ChatGPT.
	 * @return string HTML, escaped.
	 */
	private function oauth_off_html( $name ) {
		/* translators: %s: Claude or ChatGPT. */
		$text = sprintf( __( '%s connects with this site’s login and your approval (OAuth), and that is switched off here. Switch it on under Your connections → “Connect from Claude (OAuth, advanced)”.', 'alphabridge-mcp' ), $name );
		$html = '<div class="ab-callout"><p>' . esc_html( $text ) . '</p>';
		if ( 'Claude' === $name ) {
			// The way the page offered before OAuth existed: a token in the
			// connector URL (connector-URL authentication, off by default).
			$html .= '<p>' . esc_html__( 'Until then, Claude.ai can connect with a token in its connector URL: pick “Other”, create a connection and switch on the connector URL it offers.', 'alphabridge-mcp' ) . '</p>';
		}
		return $html . '<p><a class="ab-more" href="#ab-oauth">' . esc_html__( 'Go to the setting', 'alphabridge-mcp' ) . '</a></p></div>';
	}

	/**
	 * The connect card: one panel per assistant (the band above picks which one
	 * shows), the token form for the assistants that need a token, and the
	 * one-time reveal of a new token.
	 *
	 * The reveal sits outside the panels: rotating a connection further down
	 * reveals a new token too, whichever assistant is picked.
	 *
	 * @return string HTML, escaped.
	 */
	private function connect_card_html() {
		$endpoint = untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
		$oauth    = self::oauth_on();
		$guide    = 'https://www.alphabridge-mcp.com/connector.html';
		$docs     = 'https://www.alphabridge-mcp.com/docs';

		$html  = '<div class="ab-card ab-connect" id="ab-connect">';
		$html .= '<p class="ab-eyebrow">' . esc_html__( 'Connect', 'alphabridge-mcp' ) . '</p>';

		// Claude: the directory entry first, the direct way folded away.
		$html .= '<div class="ab-panel" id="ab-panel-claude" data-panel="claude">';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect Claude', 'alphabridge-mcp' ) . '</h2>';
		if ( $oauth ) {
			$html .= '<div class="ab-steps">';
			$html .= $this->step( 1, __( 'Open AlphaBridge in Claude’s directory', 'alphabridge-mcp' ), '<p>' . esc_html__( 'The listing “AlphaBridge MCP for WordPress” opens in Claude.', 'alphabridge-mcp' ) . '</p>' . $this->ext_button( 'https://claude.ai/directory/alphabridge', __( 'Open Claude’s directory', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 2, __( 'Click Connect, enter this address', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Claude asks for your site’s address.', 'alphabridge-mcp' ) . '</p>' . $this->copy_field( home_url(), __( 'This site’s address', 'alphabridge-mcp' ), __( 'Copy address', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 3, __( 'Sign in here and approve', 'alphabridge-mcp' ), '<p>' . esc_html__( 'This site asks you to sign in and to approve. The connection then shows up under Your connections.', 'alphabridge-mcp' ) . '</p>' );
			$html .= '</div>';
			$html .= '<div class="ab-links">' . $this->ext_link( self::video_url(), __( 'Watch the video', 'alphabridge-mcp' ), '▶' ) . $this->ext_link( $guide, __( 'Step-by-step guide', 'alphabridge-mcp' ), '?' ) . '</div>';
			$html .= '<p class="ab-note">' . esc_html__( 'This way runs through the AlphaBridge Connect hub — one hub connection reaches up to 10 sites.', 'alphabridge-mcp' ) . '</p>';
			$html .= '<details class="ab-alt"><summary>' . esc_html__( 'Without the hub: add a custom connector', 'alphabridge-mcp' ) . '</summary>';
			$html .= '<p>' . esc_html__( 'In Claude: Customize → Connectors → Add → Add custom connector. Paste this endpoint and connect; this site asks for your approval — no token to copy.', 'alphabridge-mcp' ) . '</p>';
			$html .= $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) ) . '</details>';
		} else {
			$html .= $this->oauth_off_html( 'Claude' );
		}
		$html .= '</div>';

		// ChatGPT: an app of its own, pointed at this site's endpoint.
		$html .= '<div class="ab-panel" id="ab-panel-chatgpt" data-panel="chatgpt" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect ChatGPT', 'alphabridge-mcp' ) . '</h2>';
		if ( $oauth ) {
			$html .= '<p class="ab-req">' . esc_html__( 'Needs ChatGPT Plus, Pro, Business, Enterprise or Edu.', 'alphabridge-mcp' ) . '</p>';
			$html .= '<div class="ab-steps">';
			$html .= $this->step( 1, __( 'Open ChatGPT’s plugins', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Sign in to ChatGPT and open the plugins page.', 'alphabridge-mcp' ) . '</p>' . $this->ext_button( 'https://chatgpt.com/plugins', __( 'Open ChatGPT', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 2, __( 'Create an MCP app with this endpoint', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Give it a name, paste this endpoint as the server URL, choose OAuth and leave the advanced fields empty.', 'alphabridge-mcp' ) . '</p>' . $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 3, __( 'Approve on this site', 'alphabridge-mcp' ), '<p>' . esc_html__( 'ChatGPT opens this site’s consent page in the same tab. Choose the access level and allow.', 'alphabridge-mcp' ) . '</p>' );
			$html .= '</div>';
			$html .= '<div class="ab-links">' . $this->ext_link( $guide, __( 'Step-by-step guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		} else {
			$html .= $this->oauth_off_html( 'ChatGPT' );
		}
		$html .= '</div>';

		// Cursor: a token and the ready-made configuration.
		$html .= '<div class="ab-panel" id="ab-panel-cursor" data-panel="cursor" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect Cursor', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<div class="ab-steps">';
		$html .= $this->step( 1, __( 'Create a connection', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Cursor connects with a token. Create one below.', 'alphabridge-mcp' ) . '</p>' );
		$html .= $this->step( 2, __( 'Copy the configuration', 'alphabridge-mcp' ), '<p>' . esc_html__( 'It appears right after you create the connection, and only then.', 'alphabridge-mcp' ) . '</p>' );
		$html .= $this->step( 3, __( 'Paste it into Cursor', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Open Cursor’s MCP settings (for all projects: ~/.cursor/mcp.json), paste, save and reload the servers.', 'alphabridge-mcp' ) . '</p>' );
		$html .= '</div>';
		$html .= '<div class="ab-links">' . $this->ext_link( $docs, __( 'Setup guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		$html .= '</div>';

		// Everything else: the endpoint and a token.
		$html .= '<div class="ab-panel" id="ab-panel-other" data-panel="other" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Claude Code, scripts and other clients', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Create a token and send it with every request to this endpoint as “Authorization: Bearer …” — or paste the ready-made configuration.', 'alphabridge-mcp' ) . '</p>';
		$html .= $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) );
		$html .= '<div class="ab-links">' . $this->ext_link( $docs, __( 'Setup guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		$html .= '</div>';

		$html .= $this->token_form_html();
		$html .= $this->reveal_html();
		$html .= '</div>';

		return $html;
	}

	/**
	 * The token form, shown for Cursor and «Other» — the assistants that need a
	 * token. Claude and ChatGPT never do.
	 *
	 * @return string HTML, escaped.
	 */
	private function token_form_html() {
		$users = wp_dropdown_users(
			array(
				'name'     => 'user_id',
				'selected' => get_current_user_id(),
				'show'     => 'user_login',
				'echo'     => 0,
			)
		);

		$html  = '<div class="ab-token" data-for="cursor other" hidden>';
		$html .= '<div class="ab-create-form">';
		$html .= '<button type="button" class="ab-btn ab-create"><span aria-hidden="true">＋</span> ' . esc_html__( 'Create connection', 'alphabridge-mcp' ) . '</button>';
		$html .= '<details class="ab-advanced"><summary>' . esc_html__( 'Label, user, access, expiry (optional)', 'alphabridge-mcp' ) . '</summary>';
		$html .= '<div class="ab-advanced__fields">';
		$html .= '<label>' . esc_html__( 'Label', 'alphabridge-mcp' ) . ' <input type="text" name="label" placeholder="' . esc_attr__( 'e.g. Cursor on my laptop', 'alphabridge-mcp' ) . '" class="regular-text"></label>';
		$html .= '<label>' . esc_html__( 'User', 'alphabridge-mcp' ) . ' ' . $users . '</label>';
		$html .= '<label>' . esc_html__( 'Access', 'alphabridge-mcp' ) . ' <select name="scope"><option value="full">' . esc_html__( 'Full', 'alphabridge-mcp' ) . '</option><option value="content">' . esc_html__( 'Content only', 'alphabridge-mcp' ) . '</option><option value="read">' . esc_html__( 'Read only', 'alphabridge-mcp' ) . '</option></select></label>';
		$html .= '<label>' . esc_html__( 'Expires in', 'alphabridge-mcp' ) . ' <input type="number" name="expires_days" min="0" max="3650" step="1" value="0"> ' . esc_html__( 'days (0 = never)', 'alphabridge-mcp' ) . '</label>';
		$html .= '</div>';
		$html .= '<p class="ab-note">' . esc_html__( 'Default: full access for you, no expiry. Choose “Content only” or “Read only” to hand a client a deliberately limited key.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</details></div>';
		$html .= '<p class="ab-note">' . esc_html__( 'The token is shown in full only once, right after you create it. Rotate or delete it under Your connections.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * One-time reveal of a new token — appears in place the moment a connection
	 * is created or rotated. Populated by JS from the ajax response and never
	 * shown again (only the hash is stored server-side).
	 *
	 * @return string HTML, escaped.
	 */
	private function reveal_html() {
		$html  = '<div class="ab-reveal" hidden>';
		$html .= '<p class="ab-reveal__ok">' . esc_html__( '✓ Connection created.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="notice notice-warning inline ab-reveal__warn"><p><strong>' . esc_html__( 'Note this down now — it is shown in full only once.', 'alphabridge-mcp' ) . '</strong> ' . esc_html__( 'The token is never stored in readable form. Save it somewhere safe (e.g. a password manager). If you lose it, rotate the connection to get a new one.', 'alphabridge-mcp' ) . '</p></div>';

		// 1) Connector URL with the token embedded — shown ONLY when connector-URL
		// authentication is on, so a copied URL always works; otherwise a one-click
		// enable button takes its place (same option and warning as further down).
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Connector URL', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(for Claude.ai — token included)', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<div class="ab-reveal-url-row ab-field" hidden><input type="text" readonly value="" class="ab-select ab-reveal-url" aria-label="' . esc_attr__( 'Connector URL', 'alphabridge-mcp' ) . '"><button type="button" class="ab-btn ab-btn--sm ab-copy" data-copy-target=".ab-reveal-url">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';
		$html .= '<div class="ab-reveal-url-enable ab-callout" hidden>';
		$html .= '<p>' . esc_html__( 'Claude.ai connects via a URL that embeds the token in its path. This is currently switched off, so no connector URL is offered yet.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-enable-url-auth">' . esc_html__( 'Enable and show the connector URL', 'alphabridge-mcp' ) . '</button>';
		$html .= '</div>';

		// 2) Bearer token — for clients that take a URL and a token separately.
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Bearer token', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(URL + token entered separately)', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<div class="ab-field"><input type="text" readonly value="" class="ab-select ab-reveal-token" aria-label="' . esc_attr__( 'Bearer token', 'alphabridge-mcp' ) . '"><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy-target=".ab-reveal-token">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';

		// 3) Ready-made config for Cursor / Claude Code (header authentication).
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Config for Cursor / Claude Code', 'alphabridge-mcp' ) . '</strong></p>';
		$html .= '<div class="ab-field ab-field--top"><textarea readonly rows="9" class="ab-reveal-json ab-select" aria-label="' . esc_attr__( 'Config for Cursor / Claude Code', 'alphabridge-mcp' ) . '"></textarea><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy-target=".ab-reveal-json">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';
		$html .= '<p class="ab-note">' . esc_html__( 'Claude.ai: open Connectors, add a custom connector and paste the Connector URL. Cursor / Claude Code: paste the config into your MCP settings file.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The main groups of the fine-tuning, in the order shown, slug => label.
	 *
	 * Seven at most with the Pro add-on, so the card shows a handful of rows
	 * that each open to their tool groups. «More tools» comes last and takes
	 * every tool group that no main group claims, so a tool of an unknown
	 * add-on always has a row; like every main group it shows only when it
	 * has tools.
	 *
	 * @return array<string,string>
	 */
	public static function main_groups() {
		$mains = array(
			'content'   => _x( 'Content', 'main group of tools', 'alphabridge-mcp' ),
			'site'      => _x( 'Site & users', 'main group of tools', 'alphabridge-mcp' ),
			'code'      => _x( 'Plugins, themes & code', 'main group of tools', 'alphabridge-mcp' ),
			'system'    => _x( 'Database & operations', 'main group of tools', 'alphabridge-mcp' ),
			'shop'      => _x( 'Shop (WooCommerce)', 'main group of tools', 'alphabridge-mcp' ),
			'undo'      => _x( 'Undo', 'main group of tools', 'alphabridge-mcp' ),
			'abilities' => _x( 'Abilities of other plugins', 'main group of tools', 'alphabridge-mcp' ),
		);
		/**
		 * The main groups of the fine-tuning, slug => label, in the order
		 * shown. An add-on may add one and claim its tool groups for it with
		 * ab_mcp_tool_main_group. «More tools» is added after this filter and
		 * stays last.
		 *
		 * @param array<string,string> $mains Slug => label.
		 */
		$filtered = apply_filters( 'ab_mcp_tool_main_groups', $mains );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : $mains as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && self::MAIN_MORE !== $slug && is_scalar( $label ) && '' !== trim( (string) $label ) ) {
				$out[ $slug ] = (string) $label;
			}
		}
		$out[ self::MAIN_MORE ] = _x( 'More tools', 'main group of tools', 'alphabridge-mcp' );
		return $out;
	}

	/**
	 * The main group a tool group shows in.
	 *
	 * @param string                    $group Slug of the tool group, as registered.
	 * @param string                    $label Its label.
	 * @param array<string,string>|null $mains main_groups(), when the caller has it.
	 * @return string Slug of a main group.
	 */
	public static function main_group_of( $group, $label = '', $mains = null ) {
		$mains = is_array( $mains ) ? $mains : self::main_groups();
		$group = (string) $group;
		$main  = isset( self::MAIN_OF[ $group ] ) ? self::MAIN_OF[ $group ] : self::MAIN_MORE;
		/**
		 * The main group a tool group shows in on the fine-tuning. An add-on
		 * names it for its own tool groups; a slug that is no main group
		 * counts as «More tools». Only the display follows it.
		 *
		 * @param string $main  Slug of the main group.
		 * @param string $group Slug of the tool group, as registered.
		 * @param string $label Label of the tool group.
		 */
		$main = apply_filters( 'ab_mcp_tool_main_group', $main, $group, (string) $label );
		return is_string( $main ) && isset( $mains[ $main ] ) ? $main : self::MAIN_MORE;
	}

	/**
	 * The line under the name of a main group: what is in it. A main group
	 * of one kind of thing says it in words; one of several tool groups names
	 * them, so the line never promises a tool this site does not have.
	 *
	 * @param string   $main   Slug of the main group.
	 * @param string[] $labels Names of its tool groups, in the order shown.
	 * @return string Unescaped text.
	 */
	public static function main_description( $main, array $labels ) {
		$fixed = array(
			'shop'      => __( 'Products, orders, coupons and customers', 'alphabridge-mcp' ),
			'undo'      => __( 'Take back changes of AI assistants', 'alphabridge-mcp' ),
			'abilities' => __( 'Actions that other plugins offer to AI assistants', 'alphabridge-mcp' ),
		);
		if ( isset( $fixed[ $main ] ) ) {
			return $fixed[ $main ];
		}
		/* translators: separator between the names of tool groups in a list. */
		return implode( _x( ', ', 'list separator', 'alphabridge-mcp' ), array_map( 'strval', $labels ) );
	}

	/**
	 * The name of a tool group under its main group, in the admin's
	 * language. The core names the groups of the Pro add-on as well: Pro
	 * ships no translations, and the names show on the core's page. Any other
	 * group keeps the label it was registered with.
	 *
	 * @param string $slug  Slug of the tool group.
	 * @param string $label Label it was registered with.
	 * @return string
	 */
	public static function group_label( $slug, $label = '' ) {
		$labels = array(
			'content'        => _x( 'Posts and pages', 'tool group', 'alphabridge-mcp' ),
			'builders'       => _x( 'Page builders', 'tool group', 'alphabridge-mcp' ),
			'media'          => _x( 'Media', 'tool group', 'alphabridge-mcp' ),
			'taxonomy'       => _x( 'Terms and comments', 'tool group', 'alphabridge-mcp' ),
			'widgets'        => _x( 'Widgets', 'tool group', 'alphabridge-mcp' ),
			'seo'            => _x( 'SEO', 'tool group', 'alphabridge-mcp' ),
			'search'         => _x( 'Search and bulk actions', 'tool group', 'alphabridge-mcp' ),
			'options'        => _x( 'Settings and options', 'tool group', 'alphabridge-mcp' ),
			'users'          => _x( 'Users and menus', 'tool group', 'alphabridge-mcp' ),
			'meta-auth'      => _x( 'Meta and application passwords', 'tool group', 'alphabridge-mcp' ),
			'multisite'      => _x( 'Multisite network', 'tool group', 'alphabridge-mcp' ),
			'lifecycle'      => _x( 'Install and update', 'tool group', 'alphabridge-mcp' ),
			'plugins-themes' => _x( 'Plugins and themes', 'tool group', 'alphabridge-mcp' ),
			'files'          => _x( 'Plugin files and uploads', 'tool group', 'alphabridge-mcp' ),
			'php-snippets'   => _x( 'PHP snippets (Code Snippets, WPCode)', 'tool group', 'alphabridge-mcp' ),
			'deploy'         => _x( 'Site Deploy (FTP/SFTP)', 'tool group', 'alphabridge-mcp' ),
			'transfer'       => _x( 'Large uploads in parts', 'tool group', 'alphabridge-mcp' ),
			'blueprint'      => _x( 'Blueprint (site structure)', 'tool group', 'alphabridge-mcp' ),
			'migration'      => _x( 'Export', 'tool group', 'alphabridge-mcp' ),
			'database'       => _x( 'Database', 'tool group', 'alphabridge-mcp' ),
			'system'         => _x( 'System and maintenance', 'tool group', 'alphabridge-mcp' ),
			'ops'            => _x( 'Cron, transients and site health', 'tool group', 'alphabridge-mcp' ),
			'woocommerce'    => 'WooCommerce',
			'undo'           => _x( 'Undo', 'tool group', 'alphabridge-mcp' ),
			'abilities'      => _x( 'Abilities of other plugins', 'tool group', 'alphabridge-mcp' ),
			'other'          => _x( 'Other', 'tool group', 'alphabridge-mcp' ),
		);
		$slug = (string) $slug;
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		return '' !== (string) $label ? (string) $label : $slug;
	}

	/**
	 * The tool groups of the registry sorted into main groups: only main
	 * groups with at least one tool, in the order of main_groups(), each with
	 * its tool groups (known ones in the order of MAIN_OF, so Pro's page
	 * builders follow posts and pages, others after them in registry order)
	 * and how many of its tools read and how many write
	 * (AB_MCP_Tool_Registry::is_read_only()); the same counts per tool group.
	 *
	 * @param array $groups AB_MCP_Tool_Registry::groups().
	 * @param array $all    Every registered tool (name => definition).
	 * @return array<string,array{label:string,groups:array<string,array{label:string,tools:string[],reads:int,writes:int}>,reads:int,writes:int,count:int}>
	 */
	public static function fine_groups( array $groups, array $all ) {
		$mains = self::main_groups();
		$out   = array();
		foreach ( $mains as $slug => $label ) {
			$out[ $slug ] = array(
				'label'  => $label,
				'groups' => array(),
				'reads'  => 0,
				'writes' => 0,
				'count'  => 0,
			);
		}
		foreach ( $groups as $gslug => $g ) {
			$tools = isset( $g['tools'] ) ? array_values( (array) $g['tools'] ) : array();
			if ( array() === $tools ) {
				continue;
			}
			$glabel = isset( $g['label'] ) ? (string) $g['label'] : '';
			$main   = self::main_group_of( (string) $gslug, $glabel, $mains );
			$sub    = array(
				'label'  => self::group_label( (string) $gslug, $glabel ),
				'tools'  => $tools,
				'reads'  => 0,
				'writes' => 0,
			);
			foreach ( $tools as $tname ) {
				$def  = isset( $all[ $tname ] ) ? (array) $all[ $tname ] : array();
				$kind = AB_MCP_Tool_Registry::is_read_only( (string) $tname, $def ) ? 'reads' : 'writes';
				++$sub[ $kind ];
				++$out[ $main ][ $kind ];
				++$out[ $main ]['count'];
			}
			$out[ $main ]['groups'][ (string) $gslug ] = $sub;
		}
		$rank = array_flip( array_keys( self::MAIN_OF ) );
		foreach ( $out as $slug => $m ) {
			$order = array();
			$i     = 0;
			foreach ( array_keys( $m['groups'] ) as $gslug ) {
				$order[ $gslug ] = array( isset( $rank[ $gslug ] ) ? $rank[ $gslug ] : count( $rank ), $i++ );
			}
			uksort(
				$out[ $slug ]['groups'],
				static function ( $a, $b ) use ( $order ) {
					return $order[ $a ] <=> $order[ $b ];
				}
			);
		}
		return array_filter(
			$out,
			static function ( $m ) {
				return $m['count'] > 0;
			}
		);
	}

	/**
	 * The badge that says whether a tool reads or writes: the only two
	 * badges of the fine-tuning, each a symbol and a word («Reads» with an
	 * eye, «Writes» with a pen), so neither depends on colour. Public for
	 * add-ons that list more on the fine-tuning (the Pro add-on's abilities of
	 * other plugins), so the words come translated from the core.
	 *
	 * @param bool $reads Whether the tool only reads.
	 * @return string HTML, escaped.
	 */
	public static function kind_badge( $reads ) {
		if ( $reads ) {
			return '<span class="ab-pill ab-pill--reads">' . self::icon( 'eye', 13 ) . esc_html( _x( 'Reads', 'tool badge', 'alphabridge-mcp' ) ) . '</span>';
		}
		return '<span class="ab-pill ab-pill--writes">' . self::icon( 'pen', 13 ) . esc_html( _x( 'Writes', 'tool badge', 'alphabridge-mcp' ) ) . '</span>';
	}

	/**
	 * A note in plain text next to a tool's badge, no badge of its own.
	 *
	 * @param string $kind 'full_only': a reader that runs only with write
	 *                     access on (code, files, the database, logs,
	 *                     credentials); 'deletes' (or 'destructive', its
	 *                     name before 4.5.0): it deletes or overwrites what is
	 *                     there (for add-ons, e.g. abilities of other plugins).
	 * @return string HTML, escaped; '' for an unknown kind.
	 */
	public static function tool_note( $kind ) {
		$texts = array(
			'full_only' => _x( 'only with write access', 'tool note', 'alphabridge-mcp' ),
			'deletes'   => _x( 'deletes', 'tool note', 'alphabridge-mcp' ),
		);
		$kind = 'destructive' === $kind ? 'deletes' : (string) $kind;
		if ( ! isset( $texts[ $kind ] ) ) {
			return '';
		}
		return '<span class="ab-tool-row__note ab-tool-row__note--' . esc_attr( $kind ) . '">' . esc_html( $texts[ $kind ] ) . '</span>';
	}

	/**
	 * How many tools read and how many write, as the two badges with their
	 * numbers; a kind with none is left out. Public for add-ons.
	 *
	 * @param int $reads  Tools that only read.
	 * @param int $writes Tools that write.
	 * @return string HTML, escaped.
	 */
	public static function count_badges( $reads, $writes ) {
		$out = array();
		if ( $reads > 0 ) {
			/* translators: %d: number of tools that only read. */
			$out[] = '<span class="ab-pill ab-pill--reads">' . self::icon( 'eye', 13 ) . esc_html( sprintf( _x( '%d read', 'tool badge with a number', 'alphabridge-mcp' ), (int) $reads ) ) . '</span>';
		}
		if ( $writes > 0 ) {
			/* translators: %d: number of tools that write. */
			$out[] = '<span class="ab-pill ab-pill--writes">' . self::icon( 'pen', 13 ) . esc_html( sprintf( _x( '%d write', 'tool badge with a number', 'alphabridge-mcp' ), (int) $writes ) ) . '</span>';
		}
		return implode( ' ', $out );
	}

	/**
	 * Texts an add-on shows on this page, in the core's languages: the Pro
	 * add-on ships no translations.
	 *
	 * reads_also_users, reads_also_shop, reads_also_users_shop: what Pro's
	 * tools read with write access off (filter ab_mcp_read_mode_reads_also);
	 * abilities_lead: what the abilities of other plugins are and when they
	 * run; abilities_write_off: that none runs with write access off;
	 * abilities_need_wp: WordPress is older than 6.9 (one %s, the version);
	 * abilities_none: no plugin offers any; abilities_kept_off: the start of
	 * a list of switched-off abilities whose plugin is not active right now.
	 *
	 * @param string $key One of the keys above.
	 * @return string Unescaped text; '' for an unknown key.
	 */
	public static function addon_text( $key ) {
		switch ( (string) $key ) {
			case 'reads_also_users':
				return __( 'With Pro they also read users.', 'alphabridge-mcp' );
			case 'reads_also_shop':
				return __( 'With Pro they also read the shop\'s products, orders, customers and coupons.', 'alphabridge-mcp' );
			case 'reads_also_users_shop':
				return __( 'With Pro they also read users and the shop\'s products, orders, customers and coupons.', 'alphabridge-mcp' );
			case 'abilities_lead':
				return __( 'Plugins offer their actions to AI assistants as abilities (WordPress Abilities API). With write access on, every ability runs, reading or writing, unless you switch it off here; each one also checks the rights of the connected account.', 'alphabridge-mcp' );
			case 'abilities_write_off':
				return __( 'Write access is off, so no ability runs; switch it on at the top of this page.', 'alphabridge-mcp' );
			case 'abilities_need_wp':
				/* translators: %s: WordPress version */
				return __( 'This site runs WordPress %s. Abilities of other plugins need WordPress 6.9 or newer (Abilities API); update WordPress under Dashboard → Updates to use them.', 'alphabridge-mcp' );
			case 'abilities_none':
				return __( 'No plugin on this site offers abilities yet.', 'alphabridge-mcp' );
			case 'abilities_kept_off':
				return __( 'Also switched off, not offered right now (kept):', 'alphabridge-mcp' );
		}
		return '';
	}

	/**
	 * One row of the fine-tuning: its switch, the name in code, a short
	 * label, a note in plain text and the badge Reads or Writes. The switch
	 * is a checkbox with role switch that posts with the fine-tuning's form
	 * (form="ab-caps"), wherever the row stands. Public: the Pro add-on draws
	 * its abilities of other plugins with it, so they look and post like the
	 * tools.
	 *
	 * @param array $a {
	 *     @type string $name     Name of the field, e.g. 'enabled_tools[]'.
	 *     @type string $value    Its value, e.g. the tool's name.
	 *     @type bool   $checked  Whether it is on.
	 *     @type string $code     Shown in code; the value when empty.
	 *     @type string $label    Short label, plain text.
	 *     @type string $describe Longer text behind an «i», plain text; '' for none.
	 *     @type bool   $reads    Whether it only reads (the badge).
	 *     @type string $note     A kind for tool_note(), '' for none.
	 *     @type string $class    Class of the switch: 'ab-tool' for a tool
	 *                            of the registry (counted), 'ab-item' for an
	 *                            add-on's item (the default; moved by the
	 *                            switches for its group and for all).
	 *     @type string $id       Id of the row, '' for none.
	 *     @type string $search   More words the search finds the row by, such
	 *                            as the names of its groups; '' for none.
	 * }
	 * @return string HTML, escaped.
	 */
	public static function fine_row_html( array $a ) {
		$a        = wp_parse_args(
			$a,
			array(
				'name'     => '',
				'value'    => '',
				'checked'  => true,
				'code'     => '',
				'label'    => '',
				'describe' => '',
				'reads'    => false,
				'note'     => '',
				'class'    => 'ab-item',
				'id'       => '',
				'search'   => '',
			)
		);
		$value    = (string) $a['value'];
		$code     = '' !== (string) $a['code'] ? (string) $a['code'] : $value;
		$label    = (string) $a['label'];
		$describe = (string) $a['describe'];
		$kind     = $a['reads'] ? _x( 'Reads', 'tool badge', 'alphabridge-mcp' ) : _x( 'Writes', 'tool badge', 'alphabridge-mcp' );
		$note     = self::tool_note( (string) $a['note'] );
		$search   = $code . ' ' . $label . ' ' . $describe . ' ' . $kind . ( '' !== $note ? ' ' . wp_strip_all_tags( $note ) : '' ) . ( '' !== (string) $a['search'] ? ' ' . (string) $a['search'] : '' );
		$class    = 'ab-tool' === $a['class'] ? 'ab-tool' : 'ab-item';
		$tip      = 'ab-tip-' . preg_replace( '/[^A-Za-z0-9_-]+/', '-', $code );

		$html  = '<li class="ab-tool-row"' . ( '' !== $a['id'] ? ' id="' . esc_attr( (string) $a['id'] ) . '"' : '' ) . ' data-search="' . esc_attr( strtolower( $search ) ) . '">';
		$html .= '<label class="ab-switch ab-switch--sm"><input type="checkbox" role="switch" class="' . $class . '" name="' . esc_attr( (string) $a['name'] ) . '" value="' . esc_attr( $value ) . '" form="ab-caps"' . checked( (bool) $a['checked'], true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $code ) . '</span></label>';
		$html .= '<code>' . esc_html( $code ) . '</code>';
		$html .= '<span class="ab-tool-row__what"><span class="ab-tool-row__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $describe && $describe !== $label ) {
			/* translators: %s: name of a tool or ability. */
			$html .= '<button type="button" class="ab-info" aria-label="' . esc_attr( sprintf( __( 'Full description of %s', 'alphabridge-mcp' ), $code ) ) . '" aria-describedby="' . esc_attr( $tip ) . '">i<span class="ab-tip" role="tooltip" id="' . esc_attr( $tip ) . '">' . esc_html( $describe ) . '</span></button>';
		}
		$html .= '</span>';
		$html .= $note . self::kind_badge( (bool) $a['reads'] ) . '</li>';
		return $html;
	}

	/**
	 * A tool group of the fine-tuning (the second level): its switch, name
	 * and badges on a row that opens its list, folded until opened. Public:
	 * the Pro add-on draws the abilities of each plugin with it.
	 *
	 * @param array $a {
	 *     @type string $id     Unique slug; the list gets the id ab-sub-<id>.
	 *     @type string $title  Name, plain text.
	 *     @type int    $reads  How many of its items read.
	 *     @type int    $writes How many write.
	 *     @type string $rows   The rows (fine_row_html()), HTML.
	 *     @type string $after  More inside the list's area, below the rows (a
	 *                          note), HTML; '' for none.
	 *     @type bool   $open   Open from the start.
	 *     @type string $group  Value of data-group, e.g. the tool group's slug.
	 * }
	 * @return string HTML; the rows and $after as given, the rest escaped.
	 */
	public static function fine_sub_html( array $a ) {
		$a    = wp_parse_args(
			$a,
			array(
				'id'     => '',
				'title'  => '',
				'reads'  => 0,
				'writes' => 0,
				'rows'   => '',
				'after'  => '',
				'open'   => false,
				'group'  => '',
			)
		);
		$id   = 'ab-sub-' . sanitize_key( (string) $a['id'] );
		$open = (bool) $a['open'];

		$html  = '<div class="ab-sub' . ( $open ? ' is-open' : '' ) . '"' . ( '' !== $a['group'] ? ' data-group="' . esc_attr( (string) $a['group'] ) . '"' : '' ) . '>';
		$html .= '<div class="ab-sub__head">';
		/* translators: %s: name of a group of tools or abilities. */
		$html .= '<label class="ab-switch ab-switch--sm"><input type="checkbox" role="switch" class="ab-sub-toggle" checked><span class="ab-switch__track" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( sprintf( __( 'All in %s', 'alphabridge-mcp' ), (string) $a['title'] ) ) . '</span></label>';
		$html .= '<button type="button" class="ab-sub__toggle" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $id ) . '">';
		$html .= '<span class="ab-sub__name">' . esc_html( (string) $a['title'] ) . '</span>';
		$html .= '<span class="ab-sub__badges">' . self::count_badges( (int) $a['reads'], (int) $a['writes'] ) . '</span>';
		$html .= '<span class="ab-chev">' . self::icon( 'chevron', 16 ) . '</span>';
		$html .= '</button></div>';
		$html .= '<div class="ab-sub__body" id="' . esc_attr( $id ) . '"' . ( $open ? '' : ' hidden' ) . '><ul class="ab-tool-list">' . $a['rows'] . '</ul>' . $a['after'] . '</div>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * One tool: its row (fine_row_html()) with its short name in the admin's
	 * language as the label (AB_MCP_Tool_Labels), the description, which is
	 * written for the assistant, behind the «i», and «only with write access»
	 * for a reader that waits for write access. A tool without a short name
	 * shows the first sentence of its description. The search finds a tool by
	 * its name in code, its short name, its description and the names of its
	 * groups.
	 *
	 * @param string $tname   Tool name.
	 * @param array  $def     Tool definition.
	 * @param bool   $enabled Whether it is switched on.
	 * @param string $groups  The names of its tool group and main group, for the search.
	 * @return string HTML, escaped.
	 */
	private function tool_row_html( $tname, array $def, $enabled, $groups = '' ) {
		$reads = AB_MCP_Tool_Registry::is_read_only( (string) $tname, $def );
		$desc  = trim( wp_strip_all_tags( (string) ( $def['description'] ?? '' ) ) );
		$label = class_exists( 'AB_MCP_Tool_Labels' ) ? AB_MCP_Tool_Labels::get( (string) $tname ) : '';
		if ( '' === $label ) {
			$parts = preg_split( '/(?<=[.!?])\s+/', $desc, 2 );
			$label = is_array( $parts ) ? (string) $parts[0] : $desc;
		}
		return self::fine_row_html(
			array(
				'name'     => 'enabled_tools[]',
				'value'    => (string) $tname,
				'checked'  => (bool) $enabled,
				'label'    => $label,
				'describe' => $desc !== $label ? $desc : '',
				'reads'    => $reads,
				'note'     => $reads && ! AB_MCP_Site_Mode::runs_in_read( (string) $tname, $def ) ? 'full_only' : '',
				'class'    => 'ab-tool',
				'id'       => 'ab-tool-' . (string) $tname,
				'search'   => (string) $groups,
			)
		);
	}

	/**
	 * The fine-tuning, open from the start: what it is for and what switching
	 * on write access does to it, a bar with the switch for all tools, a
	 * search, the badges of all tools and how many are on, then one row per
	 * main group (fine_groups()). A main group's row has its switch, name,
	 * what is in it, its badges and how many are on; its button opens the
	 * tool groups, and each of those opens its tools. A search opens what it
	 * finds.
	 *
	 * Every tool is on until an administrator switches it off here, and
	 * switching write access on switches every tool on again; whether a
	 * switched-on tool may run is the main switch's question. One form
	 * (#ab-caps) and one button save the tool switches (enabled_tools[] and
	 * the list of all tools) and whatever an add-on adds to a main group
	 * (filter ab_mcp_fine_group_html, saved on ab_mcp_fine_save). The form
	 * element holds only its hidden fields; every switch and the button join
	 * it with the form attribute, so a group can hold anything. The switches
	 * for all, for a main group and for a tool group carry no name: they only
	 * set the switches below them.
	 *
	 * @param array $groups Tool groups from the registry.
	 * @param array $all    Every registered tool (name => definition).
	 * @return string HTML, escaped.
	 */
	private function capabilities_card_html( $groups, $all ) {
		$on_total = 0;
		$reads    = 0;
		$writes   = 0;
		$rows     = '';
		foreach ( self::fine_groups( (array) $groups, (array) $all ) as $main => $m ) {
			$on     = 0;
			$body   = '';
			$many   = count( $m['groups'] ) > 1;
			$labels = array();
			foreach ( $m['groups'] as $gslug => $g ) {
				$labels[] = $g['label'];
				$items    = '';
				foreach ( $g['tools'] as $tname ) {
					$def     = isset( $all[ $tname ] ) ? (array) $all[ $tname ] : array();
					$enabled = AB_MCP_Settings::is_tool_enabled( $tname, $def );
					$on     += $enabled ? 1 : 0;
					$items  .= $this->tool_row_html( (string) $tname, $def, $enabled, $g['label'] . ' ' . $m['label'] );
				}
				if ( $many ) {
					$body .= self::fine_sub_html(
						array(
							'id'     => (string) $gslug,
							'title'  => $g['label'],
							'reads'  => (int) $g['reads'],
							'writes' => (int) $g['writes'],
							'rows'   => $items,
							'group'  => (string) $gslug,
						)
					);
				} else {
					$body .= '<ul class="ab-tool-list ab-tool-list--direct" data-group="' . esc_attr( (string) $gslug ) . '">' . $items . '</ul>';
				}
			}
			/**
			 * More in a main group of the fine-tuning, below its tools: an
			 * add-on's settings that belong to these tools (the Pro add-on
			 * puts the abilities of other plugins here, each with its switch).
			 * Escaped HTML, inside no form: fields post with the fine-tuning
			 * through form="ab-caps" (fine_row_html() sets it) and are saved on
			 * the action ab_mcp_fine_save; a switch with the class ab-item is
			 * moved by the switches for its group and for all. A form of its
			 * own stays possible (an add-on before 4.5.0 brings one).
			 *
			 * @param string   $html   '' by default.
			 * @param string   $main   Slug of the main group.
			 * @param string[] $groups Slugs of the tool groups in it.
			 */
			$extra = apply_filters( 'ab_mcp_fine_group_html', '', (string) $main, array_map( 'strval', array_keys( $m['groups'] ) ) );
			if ( is_string( $extra ) && '' !== $extra ) {
				$body .= '<div class="ab-group__addon">' . $extra . '</div>';
			}
			$on_total += $on;
			$reads    += (int) $m['reads'];
			$writes   += (int) $m['writes'];
			$n         = (int) $m['count'];
			$id        = 'ab-group-' . sanitize_key( (string) $main );

			$rows .= '<div class="ab-group" data-main="' . esc_attr( (string) $main ) . '">';
			$rows .= '<div class="ab-group__head">';
			/* translators: %s: name of a main group of tools. */
			$rows .= '<label class="ab-switch ab-group-switch"><input type="checkbox" role="switch" class="ab-group-toggle"' . checked( $n > 0 && $on >= $n, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( sprintf( __( 'All tools in %s', 'alphabridge-mcp' ), $m['label'] ) ) . '</span></label>';
			$rows .= '<button type="button" class="ab-group__toggle" id="' . esc_attr( $id ) . '-button" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">';
			$rows .= '<span class="ab-group__text"><span class="ab-group__name">' . esc_html( $m['label'] ) . '</span><span class="ab-group__desc">' . esc_html( self::main_description( (string) $main, $labels ) ) . '</span></span>';
			$rows .= '<span class="ab-group__badges">' . self::count_badges( (int) $m['reads'], (int) $m['writes'] ) . '</span>';
			$rows .= '<span class="ab-group__right">' . $this->group_status( $on, $n ) . '<span class="ab-chev">' . self::icon( 'chevron', 18 ) . '</span></span>';
			$rows .= '</button></div>';
			$rows .= '<div class="ab-group__body" id="' . esc_attr( $id ) . '" hidden>' . $body . '</div>';
			$rows .= '</div>';
		}
		$total = count( $all );

		$html  = '<section class="ab-card ab-fine" id="ab-fine" aria-labelledby="ab-fine-title">';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ab-caps" class="ab-caps">';
		$html .= wp_nonce_field( 'ab_mcp_save', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_save">';
		$html .= '<input type="hidden" name="all_tools" value="' . esc_attr( implode( ',', array_keys( $all ) ) ) . '">';
		$html .= '</form>';
		$html .= '<p class="ab-eyebrow">' . esc_html__( 'Fine-tuning', 'alphabridge-mcp' ) . '</p>';
		$html .= '<h2 class="ab-card__title" id="ab-fine-title">' . esc_html__( 'Switch single tools on and off', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Every tool is on until you switch it off here. No AI assistant sees a switched-off tool. Writing tools run only with write access on, and so do the reading ones noted “only with write access”: they read code, files, the database or logs.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<p class="ab-lead ab-fine__reset">' . esc_html__( 'Switching on write access switches everything here on; what you switch off afterwards stays off until write access is switched on the next time.', 'alphabridge-mcp' ) . '</p>';
		if ( AB_MCP_Site_Mode::is_full() && AB_MCP_Settings::get( AB_MCP_Settings::KEY_LEGACY_SWITCHES, false ) ) {
			$html .= '<p class="ab-lead ab-fine__legacy">' . esc_html__( 'On this site, tools that read code, files, the database, logs or credentials and were switched off before the update that brought write access stay off until you switch them on here or switch on write access again.', 'alphabridge-mcp' ) . '</p>';
		}
		$html .= '<div class="ab-capbar">';
		$html .= '<label class="ab-capbar__item"><span class="ab-switch"><input type="checkbox" role="switch" class="ab-all-toggle"' . checked( $total > 0 && $on_total === $total, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span></span>' . esc_html__( 'All tools', 'alphabridge-mcp' ) . '</label>';
		$html .= '<input type="search" class="ab-search" placeholder="' . esc_attr__( 'Find a tool…', 'alphabridge-mcp' ) . '" aria-label="' . esc_attr__( 'Find a tool', 'alphabridge-mcp' ) . '">';
		$html .= '<span class="ab-capbar__badges">' . self::count_badges( $reads, $writes ) . '</span>';
		/* translators: 1: tools switched on, 2: all tools. */
		$html .= '<span class="ab-capbar__count">' . sprintf( esc_html__( '%1$s of %2$s on', 'alphabridge-mcp' ), '<b class="ab-on-count">' . (int) $on_total . '</b>', '<b>' . (int) $total . '</b>' ) . '<span class="ab-capbar__more" hidden></span></span>';
		$html .= '</div>';
		$html .= '<div class="ab-groups">' . $rows . '</div>';
		$html .= '<p class="ab-note ab-caps__none" hidden>' . esc_html__( 'No tool matches.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="ab-savebar"><button type="submit" form="ab-caps" class="ab-btn">' . esc_html__( 'Save changes', 'alphabridge-mcp' ) . '</button>';
		$html .= '<span class="ab-savebar__text" data-dirty="' . esc_attr__( 'Unsaved changes — save to apply them.', 'alphabridge-mcp' ) . '">' . esc_html__( 'New connections see the change at once. An assistant that is already connected may load the tool list again only when you connect it again.', 'alphabridge-mcp' ) . '</span></div>';
		/* translators: %d: requests per minute. */
		$html .= '<p class="ab-note">' . esc_html( sprintf( __( 'Abuse protection active (fixed): %d requests/min.', 'alphabridge-mcp' ), (int) AB_MCP_Settings::get( 'rate_limit_per_min', 120 ) ) ) . '</p>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * How many tools of a main group are on, in plain text: «x of y on».
	 * admin.js keeps it current while switches change (same words), and
	 * fills the line under it with the items of an add-on in the group that
	 * are off («1 ability off»): the switch of the group moves them too.
	 *
	 * @param int $on    Tools switched on.
	 * @param int $total Tools in the group.
	 * @return string HTML, escaped.
	 */
	private function group_status( $on, $total ) {
		$state = $on <= 0 ? 'off' : ( $on >= $total ? 'on' : 'partial' );
		/* translators: 1: tools switched on, 2: all tools. */
		return '<span class="ab-group__state ab-status" data-state="' . $state . '"><span class="ab-status__count">' . esc_html( sprintf( __( '%1$s of %2$s on', 'alphabridge-mcp' ), (int) $on, (int) $total ) ) . '</span><span class="ab-status__more" hidden></span></span>';
	}

	/**
	 * The connections card: the table, then the two advanced settings that
	 * decide how connections may authenticate.
	 *
	 * @param array $tokens Stored connections.
	 */
	private function render_connections_card( $tokens ) {
		$path_auth = (bool) AB_MCP_Settings::get( 'connector_url_auth_enabled', false );
		$oauth_on  = (bool) AB_MCP_Settings::get( 'oauth_enabled', true );

		echo '<div class="ab-card ab-connections" id="ab-connections">';
		echo '<p class="ab-eyebrow">' . esc_html__( 'Connections', 'alphabridge-mcp' ) . '</p>';
		echo '<h2 class="ab-card__title">' . esc_html__( 'Your connections', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="ab-lead">' . esc_html__( 'Every assistant and client connected to this site.', 'alphabridge-mcp' ) . '</p>';
		$this->render_connection_table( $tokens );

		// Advanced (collapsed): connector-URL (token-in-path) authentication, opt-in.
		echo '<details class="ab-alt" id="ab-url-auth"><summary>' . esc_html__( 'Connector-URL authentication (advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ab_mcp_connector_auth' );
		echo '<input type="hidden" name="action" value="ab_mcp_connector_auth">';
		echo '<label class="ab-check"><input type="checkbox" name="connector_url_auth_enabled" value="1"' . checked( $path_auth, true, false ) . '><span>' . esc_html__( 'Also offer a connector URL that embeds the token in its path.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'Off by default. Header authentication (Authorization / X-Api-Key) always works. A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></span></label>';
		echo '<p><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';

		// Advanced (collapsed): connecting from Claude or ChatGPT (OAuth). On by
		// default; switching it off removes the discovery metadata, the consent
		// page and the OAuth endpoints entirely.
		echo '<details class="ab-alt" id="ab-oauth"><summary>' . esc_html__( 'Connect from Claude (OAuth, advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ab_mcp_oauth_settings' );
		echo '<input type="hidden" name="action" value="ab_mcp_oauth_settings">';
		echo '<label class="ab-check"><input type="checkbox" name="oauth_enabled" value="1"' . checked( $oauth_on, true, false ) . '><span>' . esc_html__( 'Let Claude connect with its native Connect button.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'On by default. Claude discovers this site, you approve on a login-protected consent screen, and the approved connection appears in the list above (revocable any time). Nothing connects without that approval. Switching this off removes the OAuth endpoints; existing connections keep working.', 'alphabridge-mcp' ) . '</span></span></label>';
		$cimd_on = (bool) AB_MCP_Settings::get( 'oauth_cimd', true );
		echo '<label class="ab-check"><input type="checkbox" name="oauth_cimd" value="1"' . checked( $cimd_on, true, false ) . '><span>' . esc_html__( 'Accept apps with a metadata document.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'On by default. Such an app names the address of a small file about itself instead of registering with the site; when you open its consent screen, the site loads that file from the app\'s server. Switch this off if this site cannot reach other servers, or should not: apps that can register with the site then do that, as before.', 'alphabridge-mcp' ) . '</span></span></label>';
		if ( has_filter( 'ab_mcp_oauth_cimd' ) ) {
			// A filter overrides the switch and the block below. Say so, and
			// what is in force, so saving that changes nothing is explained.
			echo '<p class="ab-note">' . esc_html( AB_MCP_OAuth::cimd_enabled() ? __( 'Code on this site (the ab_mcp_oauth_cimd filter) decides this setting; right now apps with a metadata document are accepted.', 'alphabridge-mcp' ) : __( 'Code on this site (the ab_mcp_oauth_cimd filter) decides this setting; right now apps with a metadata document are not accepted.', 'alphabridge-mcp' ) ) . '</p>';
		} elseif ( $cimd_on && AB_MCP_OAuth::outbound_blocked() ) {
			echo '<p class="ab-note">' . esc_html__( 'This site blocks requests to other servers (WP_HTTP_BLOCK_EXTERNAL in wp-config.php), so it cannot load these files and does not offer this way to connect; apps that can register with the site do that instead. To use it anyway, list the app hosts in WP_ACCESSIBLE_HOSTS and switch it on with the ab_mcp_oauth_cimd filter.', 'alphabridge-mcp' ) . '</p>';
		}
		echo '<p><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';

		// Advanced (collapsed): MCP revision 2026-07-28. On by default; off
		// restores the answers from before that revision.
		$modern_on = (bool) AB_MCP_Settings::get( 'modern_protocol', true );
		echo '<details class="ab-alt" id="ab-protocol"><summary>' . esc_html__( 'MCP protocol 2026-07-28 (advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ab_mcp_protocol_settings' );
		echo '<input type="hidden" name="action" value="ab_mcp_protocol_settings">';
		echo '<label class="ab-check"><input type="checkbox" name="modern_protocol" value="1"' . checked( $modern_on, true, false ) . '><span>' . esc_html__( 'Answer clients that speak MCP 2026-07-28.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'On by default. Clients of the newer revision are served in it; clients of the older revisions keep the one they ask for either way. Switch this off only if a client, or a proxy between client and site, has trouble with the newer revision: the site then answers in the older revisions only, and clients that speak both revisions fall back to the older one.', 'alphabridge-mcp' ) . '</span></span></label>';
		if ( has_filter( 'ab_mcp_modern_protocol' ) ) {
			// A filter overrides the switch. Say so, and what is in force, so
			// the admin is not left wondering why saving changes nothing.
			$effective = AB_MCP_REST_Controller::modern_enabled();
			echo '<p class="ab-note">' . esc_html( $effective ? __( 'Code on this site (the ab_mcp_modern_protocol filter) decides this setting; right now the revision is answered.', 'alphabridge-mcp' ) : __( 'Code on this site (the ab_mcp_modern_protocol filter) decides this setting; right now the revision is not answered.', 'alphabridge-mcp' ) ) . '</p>';
		}
		echo '<p><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';
		echo '</div>';
	}

	/**
	 * The connections table (label, access, token, expiry, last used, actions).
	 * Just the table — connections are created in the connect card above.
	 *
	 * @param array $tokens Tokens.
	 */
	private function render_connection_table( $tokens ) {
		echo '<div class="ab-table-wrap"><table class="ab-table"><thead><tr><th scope="col">' . esc_html__( 'Label', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Access', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Token', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Expires', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Last used', 'alphabridge-mcp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'alphabridge-mcp' ) . '</span></th></tr></thead><tbody class="ab-conn-rows">';
		echo '<tr class="ab-empty-row"' . ( empty( $tokens ) ? '' : ' hidden' ) . '><td colspan="6"><em>' . esc_html__( 'No connections yet — pick your assistant at the top and follow the steps.', 'alphabridge-mcp' ) . '</em></td></tr>';
		$older = AB_MCP_Settings::older_of_same_app( $tokens );
		foreach ( $tokens as $t ) {
			$is_older = isset( $t['hash'] ) && isset( $older[ (string) $t['hash'] ] );
			echo $this->connection_row_html( $t, $is_older ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside connection_row_html().
		}
		echo '</tbody></table></div>';
		echo '<p class="ab-note">' . esc_html__( 'Rotate re-issues a connection’s token (the old one stops working at once). Delete removes it.', 'alphabridge-mcp' ) . '</p>';
	}

	/**
	 * One connections-table row for a stored token entry. Shared by the initial
	 * render and by the ajax create/rotate response so a new or rotated row can be
	 * inserted in place without a reload. Everything is escaped here.
	 *
	 * The five cells before the actions carry no attributes; the tests read them
	 * as plain cells.
	 *
	 * @param array $t        Token entry (hash, prefix, label, scope, expires, last_used).
	 * @param bool  $is_older The same app has a newer connection for the same
	 *                        user (AB_MCP_Settings::older_of_same_app()).
	 * @return string A single <tr>…</tr>.
	 */
	private function connection_row_html( $t, $is_older = false ) {
		$scope_labels = array(
			'read'    => __( 'Read', 'alphabridge-mcp' ),
			'content' => __( 'Content', 'alphabridge-mcp' ),
			'full'    => __( 'Full', 'alphabridge-mcp' ),
		);
		// last_used and expires are stored as time(), UTC timestamps, and shown
		// as the site's date and time in digits, like the log below. They used
		// to read «%s ago» and «in %s» around human_time_diff(): WordPress
		// translates the time span, the words around it need this plugin's own
		// language pack, and without one a German site read «2 Wochen ago».
		$used      = ! empty( $t['last_used'] ) ? self::site_datetime( (int) $t['last_used'] ) : '—';
		$scope_key = isset( $t['scope'] ) && isset( $scope_labels[ $t['scope'] ] ) ? (string) $t['scope'] : 'full';
		$exp       = isset( $t['expires'] ) ? (int) $t['expires'] : 0;
		if ( $exp <= 0 ) {
			$exp_txt = '<span class="ab-muted">' . esc_html__( 'never', 'alphabridge-mcp' ) . '</span>';
		} elseif ( time() > $exp ) {
			$exp_txt = '<span class="ab-bad">' . esc_html__( 'expired', 'alphabridge-mcp' ) . '</span>';
		} else {
			$exp_txt = esc_html( self::site_datetime( $exp ) );
		}
		$hash = isset( $t['hash'] ) ? (string) $t['hash'] : '';

		$html = '<tr data-hash="' . esc_attr( $hash ) . '"><td><strong>' . esc_html( ! empty( $t['label'] ) ? $t['label'] : '—' ) . '</strong>';
		if ( $is_older ) {
			// A hint, not a verdict: the older connection may still be in use.
			$html .= '<br><span class="description ab-older">' . esc_html__( 'The same app connected again later. Compare “Last used” before you delete this older connection.', 'alphabridge-mcp' ) . '</span>';
		}
		$html .= '</td>';
		$html .= '<td><span class="ab-pill ab-pill--' . esc_attr( $scope_key ) . '">' . esc_html( $scope_labels[ $scope_key ] ) . '</span></td>';
		$html .= '<td><code>' . esc_html( isset( $t['prefix'] ) ? $t['prefix'] : '' ) . '…</code></td>';
		$html .= '<td>' . $exp_txt . '</td>';
		$html .= '<td>' . esc_html( $used ) . '</td>';
		$html .= '<td class="ab-actions">';
		// Rotate reveals a new secret, so it runs over ajax like create.
		$html .= '<button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-rotate" data-hash="' . esc_attr( $hash ) . '" data-confirm="' . esc_attr__( 'Issue a new secret for this connection? The current token stops working immediately.', 'alphabridge-mcp' ) . '">' . esc_html__( 'Rotate', 'alphabridge-mcp' ) . '</button> ';
		// Delete reveals nothing, so it stays a plain nonce-protected form post.
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-confirm-form" data-confirm="' . esc_attr__( 'Delete connection?', 'alphabridge-mcp' ) . '">';
		$html .= wp_nonce_field( 'ab_mcp_token', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_token"><input type="hidden" name="delete_token" value="' . esc_attr( $hash ) . '"><button class="ab-btn ab-btn--ghost ab-btn--sm ab-btn--danger">' . esc_html__( 'Delete', 'alphabridge-mcp' ) . '</button></form></td></tr>';

		return $html;
	}

	/**
	 * Audit log card.
	 */
	private function render_audit() {
		echo '<div class="ab-card ab-log" id="ab-log">';
		echo '<p class="ab-eyebrow">' . esc_html__( 'Log', 'alphabridge-mcp' ) . '</p>';
		echo '<h2 class="ab-card__title">' . esc_html__( 'Recent tool calls', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="ab-lead">' . esc_html__( 'Tool calls are always logged.', 'alphabridge-mcp' ) . '</p>';
		$log = AB_MCP_Audit_Log::recent( 15 );
		echo '<div class="ab-table-wrap"><table class="ab-table"><thead><tr><th scope="col">' . esc_html__( 'Time', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Tool', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Status', 'alphabridge-mcp' ) . '</th></tr></thead><tbody>';
		if ( empty( $log ) ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No entries yet.', 'alphabridge-mcp' ) . '</em></td></tr>';
		}
		foreach ( $log as $row ) {
			$dot = 'ok' === $row['status'] ? 'ab-dot' : ( 'denied' === $row['status'] ? 'ab-dot ab-dot--warn' : 'ab-dot ab-dot--bad' );
			// ts is time(), a UTC timestamp. wp_date() shows it in the site's
			// timezone, like every other date in WordPress. date_i18n() reads its
			// argument as a timestamp already shifted to local time and printed
			// the UTC clock instead.
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['ts'] ) ) . '</td><td><code>' . esc_html( $row['tool'] ) . '</code></td><td><span class="' . esc_attr( $dot ) . '" aria-hidden="true"></span> ' . esc_html( $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-log__clear">';
		wp_nonce_field( 'ab_mcp_audit_clear' );
		echo '<input type="hidden" name="action" value="ab_mcp_audit_clear"><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Clear log', 'alphabridge-mcp' ) . '</button></form></div>';
	}

	/**
	 * The side column: the Pro box (free edition only), the invitation to the
	 * affiliate programme, boxes from add-ons, the guides.
	 *
	 * @return string HTML; add-on boxes escape their own output.
	 */
	private function sidebar_html() {
		ob_start();
		/**
		 * Add-ons render boxes in the side column here, below the Pro box and
		 * above the guides — the Pro add-on's affiliate box, for one.
		 *
		 * @since 4.3.10
		 */
		do_action( 'ab_mcp_admin_side_boxes' );
		$addons = (string) ob_get_clean();

		return '<div class="ab-side">' . $this->pro_box_html() . $this->affiliate_invite_html() . $addons . $this->guides_html() . '</div>';
	}

	/**
	 * One line that invites to the affiliate programme, or ''.
	 *
	 * Like the Pro box it stands only on the plugin's OWN settings page
	 * (WordPress.org guideline 11). It links to the programme's page on the
	 * product website, which carries the current terms and how to apply; the
	 * address has no query and so no tracking. The commission is paid on Pro and
	 * Agency sales, never on this free plugin, and the line says so.
	 *
	 * The 60% is the programme's commission as set at Freemius on 29.09.2026
	 * (renewals included, lifetime commission on). This plugin cannot ask
	 * Freemius; a change of the terms needs a new release of this line.
	 *
	 * Where the Pro add-on reads the site's own affiliate state from Freemius,
	 * its box in the side column (figures, invitation or pending application)
	 * takes this line's place. From Pro 4.5.14 the add-on answers the filter
	 * `ab_mcp_affiliate_invite`; an older Pro that already hangs its affiliate
	 * box on the side column has no answer, so the line then stays away rather
	 * than stand next to that box.
	 *
	 * @return string HTML, escaped.
	 */
	private function affiliate_invite_html() {
		/**
		 * Whether the side column shows the invitation to the affiliate programme.
		 *
		 * @since 4.3.11
		 *
		 * @param bool $show False wherever the Pro add-on's affiliate box (ab_mcp_pro_aff_side_box)
		 *                   hangs on the side column, true everywhere else. From Pro 4.5.14 the
		 *                   add-on gives its own answer.
		 */
		$show = (bool) apply_filters( 'ab_mcp_affiliate_invite', false === has_action( 'ab_mcp_admin_side_boxes', 'ab_mcp_pro_aff_side_box' ) );
		if ( ! $show ) {
			return '';
		}

		/* translators: the commission of the AlphaBridge affiliate programme; keep the number. */
		$pct = __( '60%', 'alphabridge-mcp' );

		$html  = '<div class="ab-invite">';
		$html .= '<span class="ab-invite__pct" aria-hidden="true">' . esc_html( $pct ) . '</span>';
		$html .= '<p><strong>' . esc_html__( 'Earn with AlphaBridge.', 'alphabridge-mcp' ) . '</strong> ';
		/* translators: %s: the commission, e.g. 60% */
		$html .= esc_html( sprintf( __( '%s of every Pro and Agency sale you refer, renewals included.', 'alphabridge-mcp' ), $pct ) );
		$html .= ' <a class="ab-more" href="' . esc_url( 'https://www.alphabridge-mcp.com/affiliates.html' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Become an affiliate', 'alphabridge-mcp' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The guides card: the video, connecting step by step, all tools.
	 *
	 * @return string HTML, escaped.
	 */
	private function guides_html() {
		$links = array(
			array( self::video_url(), __( 'Watch the video', 'alphabridge-mcp' ) ),
			array( 'https://www.alphabridge-mcp.com/connector.html', __( 'Connect step by step', 'alphabridge-mcp' ) ),
			array( 'https://www.alphabridge-mcp.com/docs', __( 'All tools', 'alphabridge-mcp' ) ),
		);

		$html  = '<div class="ab-card ab-guides"><p class="ab-eyebrow">' . esc_html__( 'Help', 'alphabridge-mcp' ) . '</p>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Guides', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Setup, every tool and examples.', 'alphabridge-mcp' ) . '</p><ul class="ab-guides__list">';
		foreach ( $links as $l ) {
			$html .= '<li><a class="ab-more" href="' . esc_url( $l[0] ) . '" target="_blank" rel="noopener">' . esc_html( $l[1] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a></li>';
		}
		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * The side-column box about AlphaBridge MCP Pro, or '' where Pro already runs.
	 *
	 * Allowed on the plugin's OWN settings page (WordPress.org guideline 11): it
	 * advertises a separately distributed product and says that nothing in THIS
	 * plugin is limited — no feature here is presented as locked (guideline 9).
	 * The look comes from assets/admin.css, which ships with the plugin; nothing
	 * loads from outside.
	 *
	 * The button starts the 7-day trial, which needs no card; the two prices
	 * below it buy right away. Each link opens the Freemius checkout of the Pro
	 * plan for exactly what it names — the addresses the website's buttons fall
	 * back to. The query says only what opens (trial, monthly or yearly) and
	 * carries no tracking. The website cannot preselect the monthly price, so a
	 * link there would land a «$9/month» click on the yearly one.
	 *
	 * The list names what the Pro edition adds. Site Deploy belongs to the Agency
	 * edition and is left out, so the Pro prices buy everything listed.
	 *
	 * A site whose add-on reports a Pro or Agency licence through
	 * `ab_mcp_site_edition` is not offered what it already has. Every other value,
	 * an expired licence included, reads as the free edition, as it does in the
	 * site's self-description.
	 *
	 * @return string
	 */
	private function pro_box_html() {
		$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );
		if ( in_array( $edition, array( 'pro', 'agency' ), true ) ) {
			return '';
		}

		$checkout = 'https://checkout.freemius.com/plugin/35076/plan/57642/';
		$new_tab  = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span>';
		$link     = static function ( $query, $label, $class = '' ) use ( $checkout, $new_tab ) {
			return '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $checkout . '?' . $query ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . $new_tab . '</a>';
		};
		$items    = array(
			__( 'Over 120 tools on this site', 'alphabridge-mcp' ),
			__( 'Theme and plugin files, database, users, menus', 'alphabridge-mcp' ),
			__( 'WooCommerce, SEO editing, installs and updates', 'alphabridge-mcp' ),
			__( 'Undo points that expire after 7 days, adjustable from 1 to 30', 'alphabridge-mcp' ),
			__( 'Search and replace with preview', 'alphabridge-mcp' ),
		);

		$html  = '<div class="postbox ab-pro">';
		$html .= '<p class="ab-pro__eyebrow">AlphaBridge MCP Pro <span class="ab-pro__pill">' . esc_html__( '7 days free', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<h2 class="ab-pro__title">' . esc_html__( 'Let Claude run the whole site', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<ul class="ab-pro__list">';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( $item ) . '</li>';
		}
		$html .= '</ul>';
		$html .= $link( 'trial=free&billing_cycle=monthly', __( 'Try Pro free for 7 days', 'alphabridge-mcp' ), 'ab-pro__cta' );
		$html .= '<p class="ab-pro__alt">' . sprintf(
			/* translators: 1: link with the monthly price, 2: link with the yearly price. */
			esc_html__( 'No card needed · then %1$s or %2$s · cancel anytime', 'alphabridge-mcp' ),
			/* translators: monthly price of AlphaBridge MCP Pro in US dollars; keep the amount. */
			$link( 'billing_cycle=monthly', __( '$9/month', 'alphabridge-mcp' ) ),
			/* translators: yearly price of AlphaBridge MCP Pro in US dollars; keep the amount. */
			$link( 'billing_cycle=annual', __( '$49/year', 'alphabridge-mcp' ) )
		) . '</p>';
		$html .= '<p class="ab-pro__note">' . esc_html__( 'A separate plugin. Everything in this free plugin is complete on its own and stays fully functional without it.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Enqueue the settings screen's script (copy buttons, the choice of
	 * assistant, the capability switches and search, the token create/rotate
	 * flow) and its stylesheet — only on our own admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_alphabridge-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ab-mcp-admin', AB_MCP_URL . 'assets/admin.css', array(), AB_MCP_VERSION );
		wp_enqueue_script(
			'ab-mcp-admin',
			AB_MCP_URL . 'assets/admin.js',
			array(),
			AB_MCP_VERSION,
			true
		);
		wp_localize_script(
			'ab-mcp-admin',
			'abMcpAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ab_mcp_ajax' ),
				'copied'     => __( 'Copied', 'alphabridge-mcp' ),
				'working'    => __( 'Working…', 'alphabridge-mcp' ),
				'createHint' => __( 'A connection is active. Copy the token above now — it is not shown again.', 'alphabridge-mcp' ),
				'failed'     => __( 'Something went wrong. Please reload and try again.', 'alphabridge-mcp' ),
				/* translators: 1: tools switched on, 2: all tools. */
				'partial'    => __( '%1$s of %2$s on', 'alphabridge-mcp' ),
				/* translators: %s: number of abilities of other plugins switched off under Fine-tuning. */
				'itemOff'    => _n( '%s ability off', '%s abilities off', 1, 'alphabridge-mcp' ),
				/* translators: %s: number of abilities of other plugins switched off under Fine-tuning. */
				'itemsOff'   => _n( '%s ability off', '%s abilities off', 2, 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * Whether this site's OAuth flow (connecting from Claude) is switched on.
	 *
	 * @return bool
	 */
	private static function oauth_on() {
		return class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled();
	}

	/**
	 * A stored UTC timestamp as the site's date and time, in the form of the
	 * admin's language: «2026-09-27 12:52» in English, «27.09.2026, 12:52»
	 * in German. The same format as the line under the switch for write
	 * access (AB_MCP_Site_Mode::full_since_text()), so every date of the
	 * settings page reads alike; the log keeps its own column with seconds.
	 * Minutes, not seconds: AB_MCP_Settings::touch_token() writes last_used at
	 * most every five minutes.
	 *
	 * @param int $ts Unix timestamp (time()).
	 * @return string Unescaped text.
	 */
	private static function site_datetime( $ts ) {
		/* translators: date and time format for PHP date(), as on the settings page; see https://www.php.net/manual/datetime.format.php */
		$text = wp_date( _x( 'Y-m-d H:i', 'date and time format', 'alphabridge-mcp' ), (int) $ts );
		return is_string( $text ) ? $text : '—';
	}

	/**
	 * Render admin notices from the ab_notice query arg.
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice key; no state is changed.
		if ( empty( $_GET['ab_notice'] ) ) {
			return;
		}
		$map = array(
			'saved'         => array( 'success', __( 'Saved.', 'alphabridge-mcp' ) ),
			'tools_saved'   => array( 'success', __( 'Saved. Clients may cache the tool list they loaded. If the change is not visible in a connected client, refresh its tool list or reconnect it (Claude: in Connectors, refresh this connector’s tool list or disconnect and connect again; Claude Code: /mcp, then reconnect).', 'alphabridge-mcp' ) ),
			'token_created' => array( 'success', __( 'Connection created.', 'alphabridge-mcp' ) ),
			'token_deleted' => array( 'success', __( 'Connection deleted.', 'alphabridge-mcp' ) ),
			'audit_cleared' => array( 'success', __( 'Log cleared.', 'alphabridge-mcp' ) ),
			'review_dismissed' => array( 'success', __( 'Noted — the plugin will not ask for a review again.', 'alphabridge-mcp' ) ),
			'mode_full'        => array( 'success', __( 'Write access is on. AI assistants can now create, change and delete through every tool, and every tool is switched on; you can switch single tools off under Fine-tuning. Your confirmation is recorded with your account, the time and the version of the notice. You can switch write access off at the top of this page at any time.', 'alphabridge-mcp' ) ),
			'mode_already_full' => array( 'success', __( 'Write access was already on; nothing was changed. What you switched off under Fine-tuning stays off.', 'alphabridge-mcp' ) ),
			'mode_read'        => array( 'success', __( 'Write access is off. AI assistants only read now: content, media, terms, comments, settings and the structure of the site; every tool that creates, changes or deletes, and every reading tool noted “only with write access”, is refused.', 'alphabridge-mcp' ) ),
			'mode_unconfirmed' => array( 'error', __( 'Write access was not switched on: tick the box under the notice to confirm it, then switch again.', 'alphabridge-mcp' ) ),
			'mode_stale'       => array( 'error', __( 'Write access was not switched on: the notice has changed since this page was loaded. Read it again, tick the box and switch again.', 'alphabridge-mcp' ) ),
			'mode_unknown'     => array( 'error', __( 'Unknown setting; nothing was changed. Use the switch at the top of this page.', 'alphabridge-mcp' ) ),
			'mode_notice_dismissed' => array( 'success', __( 'Noted. Whether AI assistants may write on this site is shown at the top of this page.', 'alphabridge-mcp' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice key; no state is changed.
		$key = sanitize_key( wp_unslash( $_GET['ab_notice'] ) );
		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}
}
