<?php
/**
 * The rule of includes/sync/, read from the code (Z8, decision 11): the
 * canonical form, the inventory and the checks read stored values only as
 * raw rows through $wpdb, and of WordPress they call only what has no
 * access to data, parsers and calculators. A gate in front of a reading
 * function is not tight: the function reads rows the gate did not check
 * (wp_upload_dir() the time zone through current_time()) and prefers the
 * options cache to the rows (alloptions), and maybe_unserialize() makes an
 * object of any class it finds there, running its __wakeup().
 *
 * So every call under includes/sync/ that is not one of PHP itself or of a
 * class declared there stands on ALLOWED, with the reason it reads no
 * stored value, or on EXCEPTIONS with its count and the reason: functions,
 * static methods (self:: and parent:: of a class that extends another of
 * this plugin as that class's), methods by name, constructors. Of PHP's
 * own functions only unserialize() is an exception. A text that names a
 * function of READING (a callback such as array_map( 'get_post', … )) fails
 * as well. ALLOWED is kept true in both directions: an entry no code calls
 * fails too.
 *
 * The integration suite measures the same with a class that counts its
 * __wakeup() (tests/integration/SyncSafeTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

// testTheWriteSideOfAKindIsAClassOfTheTakeover() reads the writers of the
// engine: loaded here, so the file runs on its own too (Z8b branch 2b,
// Behebung 1, C7).
require_once dirname( __DIR__ ) . '/includes/sync/class-sync-engine.php';

final class SyncSafeCallsTest extends TestCase {

	/**
	 * What the code under includes/sync/ may call, with the reason it reads
	 * no stored value: name => reason (functions), Class::method => reason,
	 * ->method => reason, new Class => reason.
	 */
	private const ALLOWED = array(
		// Functions of WordPress: parsers and calculators.
		'is_serialized'                        => 'Reads the text it is given (wp-includes/functions.php).',
		'is_wp_error'                          => 'instanceof WP_Error.',
		'wp_basename'                          => 'A string function (wp-includes/formatting.php).',
		'serialize_blocks'                     => 'Writes the blocks it is given.',
		'get_shortcode_regex'                  => 'With the tag names given, the pattern only; $shortcode_tags is not read.',
		'get_shortcode_atts_regex'             => 'The pattern only.',
		'shortcode_parse_atts'                 => 'Parses the text it is given (stripcslashes(), the pattern of get_shortcode_atts_regex()).',
		'has_filter'                           => 'Asks the hooks registered in this request, code, no stored value.',
		'get_post_status_object'               => 'The post statuses registered in this request, code, no stored value.',
		'is_multisite'                         => 'The constants MULTISITE and SUBDOMAIN_INSTALL.',
		'wp_using_ext_object_cache'            => 'The global this request set when it loaded object-cache.php (group U of the environment, branch 7).',
		// Static methods of this plugin outside includes/sync/.
		'AB_MCP_Builders::signatures'          => 'signatures.php and the filter ab_mcp_builder_signatures; no post and no option.',
		'AB_MCP_Builders::signature'           => 'One entry of signatures().',
		'AB_MCP_Builders::adapters'            => 'Makes the readers (shortcode profiles from files) and the filter ab_mcp_builder_adapters; asks no post.',
		'AB_MCP_Redactor::extra_keys'          => 'The filter ab_mcp_redact_keys, names only.',
		'AB_MCP_Redactor::redacts_key'         => 'Compares a name with the lists of names.',
		'AB_MCP_Redactor::normalize_key'       => 'A string function over a name (accessToken, client-secret and API_KEY alike); no stored value.',
		'AB_MCP_Replace_Engine::encode'        => 'A string in one of the forms of the replace engine.',
		'AB_MCP_Replace_Engine::needles'       => 'The forms of a string.',
		'AB_MCP_Undo::option_row'              => 'SELECT option_name, autoload through $wpdb, never the value.',
		'AB_MCP_Undo_Meta::rows'               => 'SELECT of the meta rows through $wpdb, the stored bytes.',
		'AB_MCP_Undo_Widgets::row'             => 'SELECT of one option row through $wpdb, the stored bytes.',
		'AB_MCP_Option_Guard::real_option_name' => 'trim() and the two legacy names of the core.',
		'AB_MCP_Option_Guard::too_long_for_column' => 'Counts characters.',
		'AB_MCP_Option_Guard::has_invisible_chars' => 'A pattern over the name.',
		'AB_MCP_Option_Guard::is_blocked'      => 'Compares the name with the lists of names.',
		'AB_MCP_Option_Guard::folds_onto_blocked' => 'Compares the folded name with the lists (remove_accents() with the locale en_US given).',
		// Methods: $wpdb (raw rows), the HTML API, the block parser, WP_Error, the replace engine.
		'->prepare'                            => '$wpdb: escapes the query.',
		'->query'                              => '$wpdb: the raw rows.',
		'->get_results'                        => '$wpdb: the raw rows.',
		'->get_row'                            => '$wpdb: the raw row.',
		'->get_var'                            => '$wpdb: the raw value.',
		'->get_col'                            => '$wpdb: the raw column.',
		'->insert'                             => '$wpdb: a row of the takeover\'s own tables.',
		'->suppress_errors'                    => '$wpdb: the error output.',
		'->esc_like'                           => '$wpdb: escapes a LIKE pattern.',
		'->get_charset_collate'                => '$wpdb: the collation of the connection.',
		'->db_server_info'                     => '$wpdb: the version the database server of the connection names (group U of the environment, branch 7).',
		'->next_tag'                           => 'WP_HTML_Tag_Processor: parses the text it is given.',
		'->get_attribute'                      => 'WP_HTML_Tag_Processor.',
		'->set_attribute'                      => 'WP_HTML_Tag_Processor.',
		'->class_list'                         => 'WP_HTML_Tag_Processor.',
		'->set_bookmark'                       => 'WP_HTML_Tag_Processor.',
		'->release_bookmark'                   => 'WP_HTML_Tag_Processor.',
		'->get_updated_html'                   => 'WP_HTML_Tag_Processor.',
		'->get_tag'                            => 'WP_HTML_Tag_Processor.',
		'->get_attribute_names_with_prefix'    => 'WP_HTML_Tag_Processor.',
		'->next_token'                         => 'WP_HTML_Tag_Processor: parses the text it is given.',
		'->get_token_type'                     => 'WP_HTML_Tag_Processor.',
		'->is_tag_closer'                      => 'WP_HTML_Tag_Processor.',
		'->get_modifiable_text'                => 'WP_HTML_Tag_Processor.',
		'->paused_at_incomplete_token'         => 'WP_HTML_Tag_Processor.',
		'WP_HTML_Decoder::decode_attribute'    => 'The HTML API: decodes the text it is given as an attribute value.',
		'WP_HTML_Decoder::decode_text_node'    => 'The HTML API: decodes the text it is given as text.',
		'WP_MatchesMapRegex::apply'            => 'Puts the matches it is given into the query of a rewrite rule.',
		'->parse'                              => 'WP_Block_Parser: parses the text it is given.',
		'->get_error_code'                     => 'WP_Error.',
		'->get_error_message'                  => 'WP_Error.',
		'->get_error_data'                     => 'WP_Error.',
		'->carries_record'                     => 'AB_MCP_Replace_Engine: walks the serialized text without unserialize().',
		// The trust of the takeover (Z8, decisions 4 and 5): encoders, the
		// answer of another site, the answer of a route.
		'AB_MCP_Staging::normalize_url'        => 'A string function over the address it is given (wp_parse_url()); no stored value.',
		'wp_json_encode'                       => 'Encodes the data it is given.',
		'wp_remote_retrieve_body'              => 'The body of the answer it is given.',
		'wp_remote_retrieve_response_code'     => 'The status of the answer it is given.',
		'AB_MCP_Outbound::get_partner'         => 'Asks the other site over the network, tied to its checked addresses, on the port of its stored address; no stored value of this site.',
		'->hide_errors'                        => '$wpdb: no error shown while the private key of a staging site is written (AB_MCP_Sync_Key::store_secret()).',
		'->show_errors'                        => '$wpdb: errors shown again as before, afterwards.',
		'->header'                             => 'WP_REST_Response: a header of the answer.',
		'new WP_REST_Response'                 => 'An answer object.',
		// The packages of the takeover (Z8, decision 6): the protected store,
		// which reads its files and its own rows through $wpdb; the request
		// of the route; the account and the scheme of this request.
		'AB_MCP_Protected_Store::open'         => 'Opens a group in the folder of the store (its id and proof as raw rows through $wpdb).',
		'AB_MCP_Protected_Store::put'          => 'Writes one file of a group.',
		'AB_MCP_Protected_Store::get'          => 'Reads one file of a group against its SHA-256.',
		'AB_MCP_Protected_Store::groups'       => 'The sidecars of an area of the store.',
		'AB_MCP_Protected_Store::files'        => 'The files of a group, by name.',
		'AB_MCP_Protected_Store::remove'       => 'Removes a group of the store.',
		'AB_MCP_Protected_Store::close'        => 'Marks a group of the store as closed (its sidecar).',
		'AB_MCP_Store_Budget::unpack_refusal'  => 'Compares the counts it is given with the limits of unpacking (UNPACK); no stored value.',
		'AB_MCP_Outbound::public_addresses'    => 'Looks the host of this site up in the DNS (or the filter ab_mcp_outbound_addresses); no stored value.',
		'->get_route'                          => 'WP_REST_Request: the path of the request.',
		'->get_method'                         => 'WP_REST_Request: its method.',
		'->get_query_params'                   => 'WP_REST_Request: its query.',
		'->get_body_params'                    => 'WP_REST_Request: its form fields.',
		'->get_body'                           => 'WP_REST_Request: its body.',
		'add_filter'                           => 'Hooks a function into this request.',
		'get_current_user_id'                  => 'The account of this request, as the authentication set it.',
		'is_ssl'                               => 'Whether this request came over https ($_SERVER).',
		'wp_parse_url'                         => 'Parses the address it is given.',
		// Constructors.
		'new WP_Block_Parser'                  => 'The parser of the core, without the filter block_parser_class.',
		'new WP_Error'                         => 'An error object.',
		'new WeakMap'                          => 'The refusals of AB_MCP_Sync_Write in this request, by their error object; no stored value.',
		'new AB_MCP_Replace_Engine'            => 'Compiles its patterns.',
		'new stdClass'                         => 'Stands for a stored object, made without its class.',
	);

	/**
	 * The calls outside ALLOWED, with the reason: file => call => array( count, reason ).
	 */
	private const EXCEPTIONS = array(
		'class-sync-attachment-writer.php' => array(
			'__' => array( 91, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 2, 'The same texts where a count decides the form: the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Builders::markup_meta_refusal' => array( 1, 'precheck(): a key of a page builder needs unfiltered_html, as for a post (the list of the signatures and the capability); no stored value.' ),
			'AB_MCP_Change_Lock::touch'            => array( 1, 'lay_files(): the heartbeat of the lock after each file laid.' ),
			'current_user_can'                     => array( 17, 'precheck(), write_part(), restore_right() and may_lay(): the rights of the account at the attachment (edit_post, upload_files for a new one and for the keys that name files, publish_posts for a private one, edit_others_posts), asked again right before a file is laid (Codex round 1 of branch 10, A5), and unfiltered_upload, which the core asks of a type it does not know (files_plan(), provide(), restore_files(), may_lay()) (decisions 26 and 28; branch 10).' ),
			'get_allowed_mime_types'               => array( 5, 'files_plan(), provide(), restore_files(), may_lay() and content_check(): the types this account may upload, as _wp_handle_upload() checks a file (decision 26: «erlaubt für das ausführende Konto»), binding for a file the step lays or one no list of this attachment names; in restore_files() and content_check() only added to the types WordPress knows, never a limit (Codex round 3 of branch 10, B); the list of the core and its filter.' ),
			'get_gmt_from_date'                    => array( 1, 'target_of(): the UTC date WordPress derives where an attachment hands none over, for the target of the row.' ),
			'get_post_type_object'                 => array( 2, 'precheck() and may_lay(): the capabilities of the type attachment.' ),
			'get_temp_dir'                         => array( 1, 'provide(): the temporary folder of PHP for the copy of a file before it is laid (wp-includes/functions.php); no stored value compared.' ),
			'is_object_in_taxonomy'                => array( 1, 'precheck(): whether a taxonomy is registered for attachments here.' ),
			'remove_filter'                        => array( 1, 'content_check(): the filter of upload_mimes this one call adds, removed right after it (Codex round 2 of branch 10, B); no stored value.' ),
			'sanitize_meta'                        => array( 1, 'precheck(): the sanitizer ahead of each written meta value (decision 17).' ),
			'sanitize_post'                        => array( 1, 'precheck(): the sanitizers ahead, as wp_insert_post() runs them (decision 17).' ),
			'taxonomy_exists'                      => array( 1, 'precheck(): whether a taxonomy is registered here.' ),
			'wp_check_filetype'                    => array( 5, 'files_plan(), may_lay() and restore_files(): the type of a file to lay by its name, for this account; of a file the list of this attachment here names already, and of one a value that goes back names, against every type WordPress knows; of the main file against the type of the attachment (decision 26); a calculation over the name and the list of types.' ),
			'wp_check_filetype_and_ext'            => array( 3, 'provide(), files_plan() and content_check(): the type of a file by its content, as _wp_handle_upload() checks an upload (decision 26: «Typ nach dem Inhalt»): of the temporary copy forward and of a file that lies here but no list of this attachment names, for this account; of a file the list of this attachment names, and of one a value that goes back names, against every type WordPress knows (Codex round 1 of branch 10, A4 and C; round 2, B and the decision of the lead).' ),
			'wp_get_attachment_image'              => array( 1, 'precheck(): whether WordPress shows the poster of an audio or a video as an image, as set_post_thumbnail() asks it (decision 28).' ),
			'wp_get_current_user'                  => array( 5, 'files_plan(), provide(), restore_files(), may_lay() and content_check(): the account the types it may upload are asked for.' ),
			'wp_get_mime_types'                    => array( 3, 'files_plan(), content_check() and restore_files(): every type WordPress knows, together with the types upload_mimes gives the account besides, for a file the list of this attachment here names that the step does not write, and for one a value that goes back names (whether its content is what its name says; Codex rounds 2 and 3 of branch 10, B); the list of the core and its filter mime_types.' ),
			'wp_insert_attachment'                 => array( 1, 'write_row(): the way forward of a new attachment with its file, as an upload makes it (decisions 17 and 26).' ),
			'wp_mkdir_p'                           => array( 1, 'folder(): a missing folder under the uploads for a file to lay, one level under its checked parent at a time, each checked again (decision 26: «fehlende Ordner darunter mit wp_mkdir_p()»; Codex round 1 of branch 10, A1).' ),
			'wp_slash'                             => array( 3, 'precheck() and write_row(): the slashing wp_insert_post() expects.' ),
			'wp_update_post'                       => array( 1, 'write_row(): the way forward of the row of an attachment here (decision 17).' ),
		),
		// The kinds of the structure of a site (Z8 branch 11): the core ways of
		// menus, widgets, the additional CSS and the options, with their rights.
		'class-sync-shared-row.php' => array(
			'wp_cache_delete' => array( 3, 'forget(): the caches of an option after a write or a put back raw (decision 33: alloptions, notoptions, the row).' ),
		),
		'class-sync-option-writer.php' => array(
			'__' => array( 23, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'->init'              => array( 1, 'post_write(): WP_Rewrite::init(), the permalink structure as the option holds it now, before the rules are built from it (A19).' ),
			'current_user_can'    => array( 3, 'precheck(), write_row() and restore_right(): manage_options, forward, right before the write and on the way back (decision 27).' ),
			'flush_rewrite_rules' => array( 1, 'post_write(): the rewrite rules built anew after an option of the permalinks was written or put back (decision 33, A19); soft, no file.' ),
			'sanitize_option'     => array( 1, 'sanitizer_refusal(): the sanitizer ahead, as update_option() runs it (decision 17); the value handed over, no stored value.' ),
			'update_option'       => array( 1, 'write_row(): the way forward of a core option (decisions 17 and 27).' ),
		),
		'class-sync-css-writer.php' => array(
			'__' => array( 29, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Page_Cache::purge_site' => array( 1, 'post_write(): the page cache of the whole site after the CSS changed (decision 33).' ),
			'apply_filters'             => array( 1, 'css_refusal(): update_custom_css_data, the filter wp_update_custom_css_post() saves the CSS with, ahead and without writing (decision 17).' ),
			'clean_post_cache'          => array( 1, 'post_write(): the caches of the CSS post (decision 33).' ),
			'current_user_can'          => array( 4, 'precheck(), write_row(), restore_right() and restore_part(): edit_css, as the Customizer asks it for the setting (decision 25), asked again right before the row goes back (Codex round 1 of branch 11, A5).' ),
			'get_stylesheet'            => array( 2, 'theme_refusal() and restore_right(): the active theme this request runs (the CSS is written only to it).' ),
			'sanitize_post'             => array( 1, 'css_refusal(): the sanitizers ahead, as wp_update_post() runs them (decision 17).' ),
			'sanitize_title'            => array( 1, 'target_of(): the slug wp_update_custom_css_post() gives the post, a calculation over the name.' ),
			'wp_get_theme'              => array( 1, 'theme_refusal(): the version of the active theme from its files (decision 25: «Theme live in gleicher Version»).' ),
			'wp_slash'                  => array( 2, 'css_refusal() and restore_part(): the slashing wp_update_post() expects.' ),
			'wp_update_custom_css_post' => array( 1, 'write_row(): the way forward of the additional CSS (decision 25).' ),
			'wp_update_post'            => array( 1, 'restore_part(): the row put back through the core (decision 23).' ),
		),
		'class-sync-db-kinds.php' => array(
			'__' => array( 1, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'wp_get_theme' => array( 1, 'AB_MCP_Sync_Kind_Custom_Css::post_form(): the version of the theme of the stylesheet from its files, packed with the CSS (decision 25), no stored value compared.' ),
		),
		'class-sync-menu-writer.php' => array(
			'__' => array( 86, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Page_Cache::purge_site' => array( 2, 'post_write() of a menu and of an item: the page cache of the whole site (a menu shows on every page; decision 33).' ),
			'clean_post_cache'              => array( 1, 'AB_MCP_Sync_Menu_Item_Writer::post_write(): the caches of the item (decision 33).' ),
			'current_user_can'              => array( 17, 'precheck(), write_row(), location_refusal(), restore_right() and restore_part() of a menu and an item: edit_theme_options, edit_post at an item, the right to make items, assign_term at the menu, forward, right before the write and on the way back, there again right before a position or the row of an item goes back (decision 28; as AB_MCP_Tools_Menus_Users::add_menu_item(); Codex round 1 of branch 11, A5).' ),
			'get_post_type_object'          => array( 2, 'AB_MCP_Sync_Menu_Item_Writer::precheck() and original_title(): the capabilities of nav_menu_item, the label of a post type archive (code, no stored value).' ),
			'get_registered_nav_menus'      => array( 3, 'precheck(), location_refusal() and restore_right(): the positions the active theme registers (code of the theme, no stored value).' ),
			'sanitize_key'                  => array( 3, 'AB_MCP_Sync_Menu_Item_Writer::sanitizer_refusal(): what wp_update_nav_menu_item() makes of the keys of an item, ahead (decision 17).' ),
			'sanitize_meta'                 => array( 1, 'AB_MCP_Sync_Menu_Item_Writer::precheck(): the sanitizer ahead of another meta value (decision 17).' ),
			'sanitize_post'                 => array( 2, 'AB_MCP_Sync_Menu_Item_Writer::sanitizer_refusal() and restore_part(): the sanitizers ahead, as wp_insert_post() and wp_update_post() run them (decision 17).' ),
			'sanitize_url'                  => array( 1, 'AB_MCP_Sync_Menu_Item_Writer::sanitizer_refusal(): what wp_update_nav_menu_item() makes of the address of a custom link, ahead.' ),
			'set_theme_mod'                 => array( 1, 'AB_MCP_Sync_Menu_Writer::write_part(): a position through the core way of the menu screen (decision 17: set_theme_mod( \'nav_menu_locations\', … )).' ),
			'wp_cache_delete'               => array( 2, 'AB_MCP_Sync_Menu_Item_Writer::post_write(): the meta and the menu of the item in the cache (decision 33).' ),
			'wp_slash'                      => array( 4, 'write_row(), sanitizer_refusal() and restore_part() of an item: the slashing wp_update_nav_menu_item() and wp_update_post() expect.' ),
			'wp_update_nav_menu_item'       => array( 2, 'AB_MCP_Sync_Menu_Item_Writer::write_row(): the way forward of an item, as the menu screen and the REST API write it (decisions 17 and 25), for an item here inside keeping_revisions() (no revision deleted), for a new one without.' ),
			'wp_update_post'                => array( 1, 'AB_MCP_Sync_Menu_Item_Writer::restore_part(): the row of an item put back through the core (decision 23).' ),
		),
		'class-sync-widget-area-writer.php' => array(
			'__' => array( 35, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Page_Cache::purge_site' => array( 1, 'post_write(): the page cache of the whole site (a widget area shows on every page; decision 33).' ),
			'AB_MCP_Widget_Writer::sanitize' => array( 3, 'sanitizer_refusal(), write_part() and restore_right(): the widget writer of this plugin (WP_Widget::update(), widget_update_callback), ahead, right before the write and for the backed-up settings (decision 17); nothing written.' ),
			'AB_MCP_Widget_Writer::widget'   => array( 1, 'target_of(): whether a widget is registered here (code, no stored value).' ),
			'current_user_can'               => array( 6, 'precheck(), write_row(), write_part(), restore_right() and right_now(): edit_theme_options, forward, right before each write (in write_part() again after the update() of the widget and its filters) and on the way back, there again right before a field goes back (Codex round 1 of branch 11, A5).' ),
			'update_option'                  => array( 1, 'write_part(): an instance into the row of its widget, as the widget tools write it (decision 17).' ),
			'wp_set_sidebars_widgets'        => array( 1, 'write_row(): the list of the area, with the row as stored (decision 17).' ),
		),
		'class-sync-backup.php' => array(
			'__' => array( 8, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Store_Budget::bookings' => array( 1, 'fits(): the booking of the run, which a backup has to fit beside the backups the run holds (GitHub Codex round 6 on free#137).' ),
		),
		'class-sync-canon.php'  => array(
			'__' => array( 2, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'unserialize' => array( 1, 'unserialized(): after carries_record() refused every object, enum, reference and record, with allowed_classes => false.' ),
		),
		// The trust of the takeover (Z8, decisions 4 and 5) reads no value it
		// compares: its own options, the record of the site and its address.
		'class-sync-anchor.php'   => array(
			'__' => array( 13, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Staging::record' => array( 2, 'partner() and pair_print(): the confirmed role and pair of this site, which the anchor belongs to.' ),
			'get_option'             => array( 2, 'read() and retired_list(): the anchor and the key ids it retired, options this class writes itself (OPTION, OPTION_RETIRED).' ),
			'update_option'          => array( 2, 'write() and retire(): those options, without autoload.' ),
			'delete_option'          => array( 1, 'forget(): removes it.' ),
			'home_url'               => array( 1, 'pair_print(): the own address of this site, which the print binds.' ),
		),
		'class-sync-key.php'      => array(
			'__' => array( 14, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Staging::role' => array( 5, 'make(), replace(), sign(), mac(), answer(): only a confirmed staging site has a key.' ),
			'get_option'           => array( 4, 'found(), secret() and mac_secret(): the key and the secret of its MACs, in the three options this class writes itself.' ),
			'update_option'        => array( 2, 'renew() and store_secret(): those three options, without autoload.' ),
			'delete_option'        => array( 3, 'forget(): removes them.' ),
			'home_url'             => array( 1, 'answer(): the own address the key names.' ),
		),
		// What the account that makes a package may read (decision 9): its
		// capabilities and the object as WordPress has it, never a value the
		// package compares.
		'class-sync-export-rights.php' => array(
			'current_user_can'          => array( 6, 'withheld(), post(), term(): the capabilities of the account that makes the package, per object.' ),
			'get_post'                  => array( 1, 'post(): the post whose rights are asked, for read_post, edit_post and whether it is publicly viewable.' ),
			'get_post_type_object'      => array( 1, 'post(): whether WordPress knows its type (no rule of its own otherwise).' ),
			'get_term'                  => array( 1, 'term(): the term whose rights are asked.' ),
			'is_post_publicly_viewable' => array( 1, 'post(): whether the post is public, else edit_post as well.' ),
			'is_taxonomy_viewable'      => array( 1, 'term(): whether its taxonomy is public, else edit_term.' ),
			'taxonomy_exists'           => array( 1, 'term(): whether WordPress knows its taxonomy.' ),
		),
		'class-sync-export.php'   => array(
			'__' => array( 37, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'$reader::checked_meta'  => array( 1, 'checked_content(): a meta row in the form its kind checked it, AB_MCP_Sync_Db_Kind::checked_meta() of the kind AB_MCP_Sync_Db_Kinds::for_pair() names (an instance of a widget unpacked from the text the content carries; no stored value read; Codex round 4 of branch 11, B).' ),
			'$reader::stored_column' => array( 1, 'checked_content(): whether a column of the row of the content is a value WordPress unpacks (the value of an option), AB_MCP_Sync_Db_Kind::stored_column() of the same kind, so the net reads it unpacked as the kind does (a constant answer by the name of the column; no stored value read; branch 11b).' ),
			'current_user_can'       => array( 1, 'refusal(): only an account with manage_options makes and reads packages (decision 9).' ),
			'AB_MCP_Site_Mode::is_full' => array( 2, 'refusal() and deliver(): only with write access on (the access level Full, decision 9).' ),
			'AB_MCP_Site_Mode::way_text' => array( 1, 'refusal(): the way to switch write access on.' ),
			'AB_MCP_Staging::role'   => array( 1, 'refusal(): only the confirmed staging site makes and gives out packages.' ),
			'AB_MCP_Staging::bind'   => array( 2, 'binding() and refusal(): the random value of the role and pair a package is bound to.' ),
			'AB_MCP_Staging::new_bind' => array( 1, 'refusal(): that value, made where the record has none.' ),
			'AB_MCP_Staging::record' => array( 5, 'refusal(), make(), make_objects(), make_media() and binding(): the pair, whose production site a package is for, and the confirmations a package is bound to.' ),
			'home_url'               => array( 5, 'make(), make_objects(), make_media(), binding() and relay_refusal(): the own address, for the inventory (make_media(): the attachments read as the package of objects reads them, Codex round 1 of branch 10, A7), the binding and whether it is public.' ),
		),
		// The import (Z8, branch 5; Codex, round 1 of branch 7, A2): the
		// connection a package is bound to, as a proposal and a report.
		'class-sync-import.php'   => array(
			'__' => array( 26, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Staging_Gate::connection' => array( 1, 'connection(): the connection of the call (the hash of its token, or its account), which a package is bound to as the reports and proposals made from it; no value the takeover compares.' ),
		),
		'class-sync-manifest.php' => array(
			'__' => array( 22, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Staging::record' => array( 1, 'sign(): the pair, whose production site a package is for.' ),
			'AB_MCP_Staging::role'   => array( 1, 'sign(): only the confirmed staging site signs.' ),
			'home_url'               => array( 2, 'sign() and verify(): the own address, as source and target exactly (decision 5).' ),
		),
		// The proposal of pairs (Z8, branch 6): the connection it is bound to
		// and the own address of the inventory, as the export reads its own.
		'class-sync-proposal.php' => array(
			'_n' => array( 1, 'step(): the progress of the environment of this site in a proposal of units (Z8b branch 2b): the same texts where a count decides the form, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes.' ),
			'__' => array( 13, 'the refusals and errors the tools wp_site_* pass on and the result of a page of the proposal in next, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 to 3).' ),
			'AB_MCP_Staging_Gate::connection' => array( 1, 'connection(): the connection of the call (the hash of its token, or its account), which a proposal and a confirmation are bound to (decision 15); no value the proposal compares.' ),
			'home_url'                        => array( 1, 'step(): the own address, for the inventory of this site with the addresses of both sites neutral, as the export reads its own.' ),
		),
		// The report (Z8, branch 6, part 2): the way of a refusal of the whole
		// call (decision 32), a text of the plugin and the address of its page.
		'class-sync-report.php' => array(
			'__' => array( 28, 'the reasons and steps of the refusals of wp_site_diff and the result of a page of the report in next, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 to 3).' ),
			'_n' => array( 1, 'the progress of the environment: the same texts where a count decides the form, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes (branch 13 text/z8, Codex round 2, B1).' ),
			'AB_MCP_Guidance::compose'      => array( 1, 'refusal(): the reason, the steps, the link and the handover of a refusal, in the language of the site (the locale and the bundled translation are read; branch 13 text/z8); no value the report compares.' ),
			'AB_MCP_Guidance::settings_url' => array( 1, 'refusal(): the address of the page «Staging» at an anchor (admin_url()); no value the report compares.' ),
		),
		'class-sync-schema.php' => array(
			'__' => array( 2, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'get_option'    => array( 2, 'maybe_upgrade() and status(): the version of the tables, a string this plugin writes itself and compares with VERSION; no value of a site the takeover compares.' ),
			'update_option' => array( 2, 'install(): writes that version (or that it failed).' ),
			'delete_option' => array( 1, 'uninstall: removes that version.' ),
			'dbDelta'       => array( 1, 'install(): makes the tables of the takeover.' ),
		),
		// The run (Z8, branch 8; decisions 17 to 20, 23 and 33): the engine
		// writes through the API of WordPress by design, and reads back from
		// the rows (AB_MCP_Sync_Rows) for every comparison; what it asks of
		// WordPress besides are the rights of the account, the lock, the store
		// and the probes of the check.
		'class-sync-run.php'    => array(
			'__' => array( 39, 'the refusals of the run (the lock, an interrupted run and its two ways, a state that changed), what stands for a value before cut from an answer, and the refusals a person reads on the page «Staging» after «Close as checked» or «Abort», in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Change_Lock::held'        => array( 4, 'under_lock(), settle(), update_step() and lock_fresh(): whether this request holds the lock of the write boundary (one lock per call, the decision of 07.11.2026 evening, Variante A).' ),
			'AB_MCP_Change_Lock::release'     => array( 2, 'under_lock() and settle(): the lock of the write boundary given back at the end of the call, and after the decision of a reading call (decision 18; Variante A).' ),
			'AB_MCP_Change_Lock::take'        => array( 2, 'under_lock(): the lock of the write boundary for the whole call that can change a run; settle(): for the decision of a reading call where a step is begun (decision 18; Variante A).' ),
			'AB_MCP_Staging_Gate::connection' => array( 1, 'connection(): the connection of the call, which a run is bound to (decision 15).' ),
			'get_option'                      => array( 1, 'lock_since(): the row of the lock of AB_MCP_Change_Lock, a time this plugin writes itself (decision 18); no value the takeover compares.' ),
			'AB_MCP_Protected_Store::backup_days' => array( 1, 'set_state(): the days a close keeps its backups (the filter of Pro), stored as keep_until at the close (decision 20; A7).' ),
		),
		'class-sync-guard.php'  => array(
			'__' => array( 2, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'add_action'          => array( 1, 'arm(): rest_api_init, where the REST filters of every registered type are hooked (A1).' ),
			'delete_option'       => array( 2, 'end() and record(): removes the record of the boundary, an option this class writes itself.' ),
			'get_metadata_by_mid' => array( 2, 'meta_by_mid() and term_meta_by_mid(): the post or term a meta id belongs to, for the lock of the boundary by object (branch 9 for terms); no value compared.' ),
			'get_option'          => array( 5, 'begin(), end() and record(): the record of the boundary, an option this class writes itself; guarded() and mark(): the mark of a site that has had a run, an option this class writes itself (N3).' ),
			'get_post_types'      => array( 1, 'arm_rest(): the post types registered, for the REST filters (A1); no stored value.' ),
			'->get_param'         => array( 1, 'rest_term(): the id of the term a REST request writes, for the lock of the boundary (branch 9); a value of the request, no stored value.' ),
			'maybe_serialize'     => array( 1, 'stored(): the bytes update_option() stores for the value the step\'s own call is to write, to let only that into the row while the call runs (only(); Codex round 5 of branch 11, C1); a calculation, no stored value.' ),
			'maybe_unserialize'   => array( 1, 'holding(): the record of the boundary read afresh from its row (AB_MCP_Sync_Rows::option()), an option this class writes itself (A1).' ),
			'remove_filter'       => array( 1, 'only(): the filter pre_update_option it hooks for one call of the step\'s own, where the lock was not armed in this request, removed right after it; no stored value.' ),
			'update_option'       => array( 2, 'begin(): writes that record; mark(): writes that mark, autoloaded (N3).' ),
			'update_post_meta'    => array( 1, 'begin(): the edit lock _edit_lock of a covered post, so an editor sees it taken (decision 19); excluded from every comparison.' ),
		),
		'class-sync-term-writer.php' => array(
			'__' => array( 50, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'clean_term_cache'          => array( 1, 'post_write(): the caches of the written term (decision 33).' ),
			'current_user_can'          => array( 3, 'precheck(): the rights of the account at the term (edit_term) or of the taxonomy for a new one (manage_terms, edit_terms, assign_terms); restore_right(): edit_term on the way back. The rights of the meta of a term go through AB_MCP_Sync_Post_Writer::ops_right() (Codex round 1, A2).' ),
			'get_taxonomy'              => array( 1, 'precheck(): the capabilities of the taxonomy; no stored value compared.' ),
			'is_taxonomy_hierarchical'  => array( 1, 'precheck(): whether the taxonomy has parents, as registered in this request.' ),
			'sanitize_meta'             => array( 1, 'precheck(): the sanitizer ahead of each written meta value, as update_metadata() runs it (decision 17).' ),
			'sanitize_term'             => array( 1, 'sanitizer_refusal(): the sanitizers ahead, as wp_insert_term() and wp_update_term() run them (decision 17).' ),
			'wp_cache_delete'           => array( 1, 'post_write(): the meta cache of the written term.' ),
			'wp_find_hierarchy_loop'    => array( 1, 'loop(): the loop of parents wp_update_term() would break (wp_check_term_hierarchy_for_loops()), read before anything is written; the parents through wp_get_term_taxonomy_parent_id().' ),
			'wp_get_term_taxonomy_parent_id' => array( 1, 'loop(): the parent here of a term the run does not move, beside the parents it gives the terms it writes (Codex round 1 on branch 9, B4); read for the check of a loop, never compared with the staging site.' ),
			'wp_insert_term'            => array( 1, 'write_row(): the way forward of a new term (decision 17).' ),
			'wp_slash'                  => array( 6, 'sanitizer_refusal(), write_row() and restore_row(): the slashing wp_insert_term() and wp_update_term() expect of the name and the description.' ),
			'wp_unique_term_slug'       => array( 1, 'precheck(): the slug WordPress would store for a new term, read before anything is written.' ),
			'wp_update_term'            => array( 2, 'write_row() and restore_row(): the way forward and back of the row of a term (decisions 17 and 23).' ),
		),
		'class-sync-said.php' => array(
			'__' => array( 275, 'text(): the reasons of the report, the locks of the environment among them, of the proposal of the pairs, the words and notes of the results and of the environment and the messages of AB_MCP_Outbound, said to a person in the language of the site where they are set into a refusal or a note or the copy of an answer (shown()); the stored English text is only the key, and the locale of the site and its bundled translation are read, no value the takeover compares or hashes (branch 13 text/z8, Codex rounds 2, 3 and 5; SyncSaidScanTest finds every such text).' ),
			'_x' => array( 8, 'kind(): the word of a kind of an object in a note of the journal, as the page «Staging» says it; the locale of the site and its bundled translation are read, no value the takeover compares or hashes (branch 13 text/z8, Codex round 2, B1).' ),
		),
		'class-sync-resources.php' => array(
			'__' => array( 6, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Protected_Store::root' => array( 1, 'measure(): the folder of the store, for the free space and the tempo of a write there (15.11).' ),
			'AB_MCP_Protected_Store::temp_file'   => array( 1, 'measure(): a temp file of the store in its area tmp/ for the tempo of a write (Z8b branch 3, Z37); a file, no stored value.' ),
			'AB_MCP_Protected_Store::write_file'  => array( 1, 'measure(): the write whose tempo is measured, through a temp file renamed into place (Z37); no stored value.' ),
			'AB_MCP_Protected_Store::delete_path' => array( 1, 'measure(): the temp file goes again through the one remover of the store (Z37); no stored value.' ),
		),
		'class-sync-post-writer.php' => array(
			'__' => array( 145, 'the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 4, 'The same texts where a count decides the form: the refusals of the writer and the notes of its parts and of its way back in the journal, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Builder_Caches::clear'             => array( 1, 'post_write(): the builder caches of the written post (decision 33).' ),
			'remove_filter'                            => array( 2, 'keeping_revisions(): the filters wp_revisions_to_keep and wp_{$post_type}_revisions_to_keep of the one call that saves a post go again (Codex rounds 1 and 2 of branch 11, A3 and A7; every writer of the engine).' ),
			'AB_MCP_Builders::markup_meta_refusal'     => array( 2, 'precheck() and restore_right(): a key of a page builder needs unfiltered_html, forward and on the way back, as the free meta writer and its undo ask it (the list of the signatures and the filter, and the capability; GitHub Codex round 1 on free#138, P2); no stored value.' ),
			'AB_MCP_Page_Cache::purge_posts'           => array( 1, 'post_write(): the page cache of the written post (decision 33).' ),
			'AB_MCP_Page_Cache::purge_site'            => array( 1, 'post_write(): the page cache of the whole site after a template, a template part, the global styles, a navigation or a reusable block, which any page may show (decision 33; branch 9b).' ),
			'AB_MCP_Tools_Content::saved_status'       => array( 2, 'precheck() and restore_row(): the status WordPress would store for the data handed over (decision 17); a calculation.' ),
			'AB_MCP_Tools_Content::status_date_refusal' => array( 2, 'precheck() and restore_row(): the refusals of the free writer for a status and date (decision 17); a calculation.' ),
			'add_metadata'                             => array( 1, 'write_meta(): the way forward of meta (decision 17).' ),
			'clean_post_cache'                         => array( 1, 'post_write(): the caches of the written post.' ),
			'current_user_can'                         => array( 16, 'precheck(): the rights of the account for each operation (decision 17, step 4); ops_right(): edit_, add_ and delete_post_meta or _term_meta per key that differs, exactly the operations write_meta() makes (meta_ops()), through map_meta_cap at the object (decision 28; branch 9; Codex round 1, A2); slug_refusal(): whether the account may publish a pending post, without which WordPress drops its slug; term_right(): the right of a taxonomy to assign its terms, assign_term for each term, the right to make a term for a slug this site has no term of; restore_right(): the same rights asked again right before a part goes back on the way back, edit_post first for every part (GitHub Codex round 2 on free#137; Codex round 18).' ),
			'delete_post_thumbnail'                    => array( 1, 'write_thumbnail(): the featured image removed through the core\'s own API (decision 28).' ),
			'delete_metadata'                          => array( 1, 'write_meta(): the way forward of meta.' ),
			'->get_registered'                         => array( 1, 'blocks_show_fallback(): the content of a pattern a template or a post names (core/pattern), as WordPress shows it, for a navigation block without a menu of its own (branch 9b, Codex round 1, B); never compared with the staging site.' ),
			'WP_Block_Patterns_Registry::get_instance' => array( 1, 'blocks_show_fallback(): the registry of the patterns of this request, for the content of a pattern (branch 9b).' ),
			'get_block_template'                       => array( 1, 'blocks_show_fallback(): the template part a template or a post names as WordPress shows it (a row here, else the file of the theme), for a navigation block without a menu of its own (branch 9b, Codex round 1, B); never compared with the staging site.' ),
			'get_block_templates'                      => array( 1, 'fallback_shown(): the templates of the active theme as WordPress shows them (the rows here over the files of the theme), for a navigation block without a menu of its own (branch 9b, Codex round 1, B); never compared with the staging site.' ),
			'get_block_file_template'                  => array( 1, 'parts_refusal(): whether the active theme has a template part a content names by theme and slug as a file, as render_block_core_template_part() asks it (branch 9b); a file of the theme, no stored value compared.' ),
			'get_gmt_from_date'                        => array( 1, 'prepare(): the UTC date WordPress derives where a published post hands none over, for the target of the row.' ),
			'get_option'                               => array( 1, 'with_row(): the default category wp_insert_post() gives a new post with the row (the ownership rule, Codex round 3); read to know what the row call was to write, not compared to the staging site.' ),
			'get_post_type_object'                     => array( 3, 'precheck(), slug_refusal() and restore_right(): the capabilities of the type.' ),
			'get_taxonomy'                             => array( 1, 'term_right(): the capabilities of the taxonomy (assign_terms, edit_terms), as the rights ask them; no stored value compared (GitHub Codex round 2 on free#137).' ),
			'get_stylesheet'                           => array( 6, 'inactive_theme() and inactive_theme_detail() (the same question for the refusal of the writer, in the language of the site), parts_refusal(), order_refs(), resolves_here() and fallback_shown(): the active theme as the core reads it for templates, template parts and global styles (get_block_template(), render_block_core_template_part(), the global styles of the theme), beside the option row; asked of the target and the order, never compared with the staging site (branch 9b).' ),
			'get_term_by'                              => array( 2, 'term_right(): the id of the term of this site a slug names (post_format, wp_theme, wp_template_part_area), for assign_term through map_meta_cap; named_refusal(): the name of the term of this site a slug names, which WordPress reads the theme and the area by (branch 9b); asked of the rights and the target, never compared with the staging site (decision 28; GitHub Codex round 2 on free#137).' ),
			'get_user_by'                              => array( 1, 'user_by_login(): the account of this site an author is written as (decision 25).' ),
			'is_post_type_viewable'                    => array( 1, 'shows_pages(): whether a post of a type has a page of its own, for a navigation block it shows (branch 9b); the types registered in this request.' ),
			'is_protected_meta'                        => array( 1, 'new_meta_allowed(): whether a key of a new post is protected, so its right is answered at the object only (decision 28; branch 9).' ),
			'maybe_serialize'                          => array( 1, 'db_form(): the bytes WordPress stores for what sanitize_meta() gives back, as update_metadata() serializes it (Codex round 1 on branch 9, B3); a calculation, no stored value.' ),
			'sanitize_meta'                            => array( 1, 'precheck(): the sanitizer ahead of each written meta value, as update_metadata() runs it (decision 17; branch 9).' ),
			'sanitize_post'                            => array( 1, 'precheck(): the sanitizers ahead, as wp_insert_post() runs them (decision 17).' ),
			'sanitize_title'                           => array( 2, 'slug_refusal(): the slug as wp_insert_post() makes it, in the old sanitizing for an update and the new one (branch 9); a string function with its filters.' ),
			'set_post_thumbnail'                       => array( 1, 'write_thumbnail(): the featured image through the core\'s own API (decision 28).' ),
			'taxonomy_exists'                          => array( 2, 'precheck() and restore_right(): whether a taxonomy is registered here.' ),
			'update_metadata'                          => array( 1, 'write_meta(): the way forward of meta.' ),
			'validate_file'                            => array( 1, 'parts_refusal(): whether a slug of a template part names a file at all, as render_block_core_template_part() asks it before it reads the file (branch 9b); a string function.' ),
			'wp_cache_delete'                          => array( 4, 'post_write() and restore_meta(): the caches of the written post and its meta.' ),
			'wp_clean_theme_json_cache'                => array( 1, 'post_write(): the caches of theme.json after the global styles were written (decision 33; branch 9b).' ),
			'wp_get_theme'                             => array( 1, 'template_offered(): the theme of this site, for the templates it offers (B7), asked by precheck() and resolves_here(); no stored value compared.' ),
			'->get_page_templates'                     => array( 1, 'template_offered(): whether the theme offers the template page_template names, as wp_insert_post() checks it (decisions 17 and 28; B7), for precheck() and for the order of the plan (resolves_here()).' ),
			'wp_get_attachment_image'                  => array( 1, 'precheck(): whether WordPress shows the attachment of the featured image as an image, as set_post_thumbnail() asks it before it keeps the id (decision 28; branch 9).' ),
			'wp_insert_post'                           => array( 1, 'write_row(): the way forward of a new post (decision 17).' ),
			'wp_set_object_terms'                      => array( 2, 'write_terms() and restore_terms(): the way forward and back of terms (decisions 17 and 23).' ),
			'wp_slash'                                 => array( 9, 'precheck(), write_row(), write_meta(), restore_row(): the slashing wp_insert_post() and the metadata API expect; in write_meta() the key as well as the value, since the metadata API unslashes both (GitHub Codex round 1 on free#137).' ),
			'wp_unique_post_slug'                      => array( 1, 'slug_refusal(): the slug WordPress would store, read before anything is written, with the filters of the core and the plugins (branch 9).' ),
			'wp_update_post'                           => array( 2, 'write_row() and restore_row(): the way forward and back of the row (decisions 17 and 23).' ),
		),
		'class-sync-engine.php' => array(
			'__' => array( 152, 'the journal of a run (the message and the findings of a step, the notes of its parts, why a part does not go back) and the refusals of wp_site_push and wp_site_revert, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 14, 'The same texts where a count decides the form: the journal of a run (the message and the findings of a step, the notes of its parts, why a part does not go back) and the refusals of wp_site_push and wp_site_revert, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Change_Lock::touch'       => array( 3, 'boundary(), write_parts() (the parts read back as written) and put_back() (each part put back, within the boundary and on the way back; B8): the heartbeat of the lock.' ),
			'AB_MCP_Php_Probe::run'           => array( 1, 'run_verify(): the PHP probe of the site (decision 33).' ),
			'AB_MCP_Protected_Store::available' => array( 1, 'run_check(): whether the store is proved (decision 7: no run without it).' ),
			'AB_MCP_Protected_Store::cleanup' => array( 3, 'abort(), run_verify() and close_revert(): the retention of the backups after a close (decision 20).' ),
			'AB_MCP_Protected_Store::refusal' => array( 1, 'run_check(): why the store is not proved.' ),
			'AB_MCP_Store_Budget::book'       => array( 1, 'run_check(): the booking of the run (decision 8).' ),
			'AB_MCP_Store_Budget::release'    => array( 4, 'abort(), run_verify(), close_revert() and abort_at(): the booking given back.' ),
			'clean_term_cache'                => array( 1, 'run_post(): the term caches after the run (decision 33).' ),
			'esc_html'                        => array( 2, 'sample_finding(): the title of the written object as the page shows it, the expected string of decision 33 (B4); the title comes from the journal, no stored value.' ),
			'get_permalink'                   => array( 1, 'run_verify(): the address of a published object for the sample of decision 33; no value compared.' ),
			'home_url'                        => array( 1, 'context(): the own address, as the export reads its own.' ),
			'is_post_type_viewable'           => array( 1, 'viewable(): whether the type of a written object has a page of its own for the sample of decision 33 (a template, a navigation and the other types of branch 9b have none); the types registered in this request.' ),
			'wp_remote_get'                   => array( 1, 'probe(): the sample of a published object answering 200 with its title (decision 33).' ),
			'wptexturize'                     => array( 1, 'sample_finding(): the title as a theme shows it texturized (B4); a calculation on the journal\'s title.' ),
		),
		'class-sync-push.php'   => array(
			'__' => array( 51, 'the refusals and the preview of wp_site_push, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 1, 'plan(): the same texts where a count decides the form, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes (branch 13 text/z8, Codex round 2, B1).' ),
			'_x' => array( 1, 'preview(): the word compared for the environment of the report, in the language of the site: the person sees the preview (AB_MCP_Confirm_Token); no value the takeover compares or hashes (branch 13 text/z8, Codex round 2, B1).' ),
			'AB_MCP_Protected_Store::bind' => array( 1, 'bind_package(): the packages of objects bound to the run, kept while it is not closed.' ),
			'wp_get_term_taxonomy_parent_id' => array( 1, 'term_depths(): the parent here of a term the run does not write, for the depth of the terms of the run in the tree it leaves, which orders their steps (Codex rounds 1 and 2 on branch 9, B4); read for the order, never compared with the staging site.' ),
		),
		'class-sync-pairs-tool.php' => array(
			'__' => array( 48, 'the refusals and the preview of wp_site_pairs and of «Discard» on the page «Staging», in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 2, 'The same texts where a count decides the form: the refusals of wp_site_pairs and of «Discard» on the page «Staging», in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-revert.php' => array(
			'__' => array( 36, 'the refusals and the preview of wp_site_revert and of «Take this pair back» on the page «Staging», in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'_n' => array( 3, 'The same texts where a count decides the form: the refusals of wp_site_revert and of «Take this pair back» on the page «Staging», in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
			'AB_MCP_Protected_Store::under_lock' => array( 1, 'begin(): a way back for a closed run is made under the lock of the store, against the clean-up (decision 20, rule 4; A6).' ),
		),
		'class-sync-env.php' => array(
			'AB_MCP_Own_Files::own_file_refusal' => array( 1, 'own(): whether a unit of plugins or must-use plugins is AlphaBridge itself (Free, Pro, the owner drop-in) by the identity of its path (constants of the plugin, realpath() and stat()), shown by its version and never hashed (Z8b branch 2, E12); no stored value.' ),
			'__' => array( 2, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		// The comparison of the core (Z8b branch 2, Z35, E18).
		'class-sync-core-compare.php' => array(
			'AB_MCP_Outbound::get' => array( 1, 'checksums(): the checksums of the core of this version and language from api.wordpress.org, only over https, no redirect followed, tied to the checked addresses (Z35, never get_core_checksums()); the answer is checked as data, no stored value of this site is read (GitHub-Codex and CI of free#147, Behebung 2).' ),
			'__'                   => array( 6, 'checksums(): its errors, each with the way, in the language of the site as they are made; check() keeps only their codes as the cause of a line, no value the takeover compares or hashes (Behebung 2).' ),
		),
		'class-sync-fetcher.php' => array(
			'__' => array( 3, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-grant.php' => array(
			'__' => array( 1, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-inventory.php' => array(
			'__' => array( 2, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-media-package.php' => array(
			'__' => array( 15, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-pairs.php' => array(
			'AB_MCP_Confirm_Token::passed' => array( 1, 'AB_MCP_Sync_Unit_Proof::of_confirmation(): whether the token of wp_site_pairs passed its check in this request against these arguments and this state (Z8b branch 2b, Behebung 1, A2); reads a record of this request, writes nothing.' ),
			'new LogicException'           => array( 2, 'AB_MCP_Sync_Unit_Proof::__wakeup() and __unserialize(): a proof of the base of a unit is never unserialized (Z8b branch 2b, Behebung 1, A2); refuses, writes nothing.' ),
			'__' => array( 57, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
		'class-sync-write.php' => array(
			'__' => array( 3, 'the refusals and errors the tools wp_site_* pass on, in the language of the site as they are made; the locale of the site and its bundled translation are read, no value the takeover compares or hashes; checked_before() alone reads a placeholder of a value not shown, and a credential it finds there hides that value in the answer, never a write (branch 13 text/z8, Codex rounds 1 and 2).' ),
		),
	);

	/**
	 * Functions that read stored values and unpack them (or call what
	 * does), refused as text as well, where they would be a callback.
	 */
	private const READING = array(
		'get_option', 'get_site_option', 'get_network_option', 'get_blog_option', 'wp_load_alloptions',
		'get_post_meta', 'get_metadata', 'get_metadata_raw', 'get_term_meta', 'get_user_meta', 'get_comment_meta', 'get_post_custom',
		'get_theme_mod', 'get_theme_mods', 'get_stylesheet', 'get_template', 'wp_get_theme',
		'wp_get_upload_dir', 'wp_upload_dir', '_wp_upload_dir', 'wp_get_attachment_metadata', 'get_attached_file',
		'current_time', 'wp_timezone', 'wp_timezone_string', 'wp_date', 'date_i18n', 'mysql2date', 'get_locale', 'determine_locale',
		'__', '_e', '_x', '_n', 'esc_html__', 'esc_attr__',
		'get_transient', 'get_site_transient', 'get_post', 'get_posts', 'get_term', 'get_terms', 'get_term_by',
		'wp_get_object_terms', 'get_the_terms', 'wp_get_nav_menu_items', 'get_userdata', 'get_user_by',
		'home_url', 'site_url', 'get_home_url', 'get_site_url', 'get_bloginfo', 'is_plugin_active', 'maybe_unserialize', 'unserialize',
		// Functions of the core that read an option (Codex, round 12:
		// esc_attr() reads blog_charset through the cache) or a stored post.
		'esc_attr', 'esc_html', 'esc_textarea', 'esc_js', 'esc_xml', 'esc_url', 'esc_url_raw', 'esc_attr_e', 'esc_html_e',
		'_wp_specialchars', 'wp_check_invalid_utf8', 'wp_kses', 'wp_kses_post', 'wp_kses_data', 'sanitize_title',
		'sanitize_title_for_query', 'remove_accents', 'url_to_postid', 'get_page_by_path', 'get_permalink',
		'resolve_option', 'option_snapshot', 'option_write_refused', 'detect_all', 'markers_found', 'signature_active', 'signature_data_version', 'primary',
	);

	/** @return array{0:array<string,array<string,int>>,1:array<string,string[]>} file => call => count; file => texts of READING. */
	private static function scan(): array {
		$files    = glob( dirname( __DIR__ ) . '/includes/sync/*.php' );
		$declared = array();
		$methods  = array();
		$parents  = array();
		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_CLASS === $token[0] ) {
					$name              = self::next_name( $tokens, $i );
					$declared[ $name ] = true;
					$at                = $i;
					while ( isset( $tokens[ $at ] ) && '{' !== $tokens[ $at ] ) {
						if ( is_array( $tokens[ $at ] ) && T_EXTENDS === $tokens[ $at ][0] ) {
							$parents[ basename( $file ) ][] = self::next_name( $tokens, $at );
						}
						++$at;
					}
				}
				if ( is_array( $token ) && T_FUNCTION === $token[0] && '(' !== self::after( $tokens, $i ) ) {
					// A method or function by its name; a closure (function () use
					// (…) {) declares none, and the first name inside it is a call.
					$methods[ self::next_name( $tokens, $i ) ] = true;
				}
			}
		}
		$calls = array();
		$texts = array();
		foreach ( $files as $file ) {
			$base   = basename( $file );
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && in_array( substr( $token[1], 1, -1 ), self::READING, true ) ) {
					$texts[ $base ][] = substr( $token[1], 1, -1 );
					continue;
				}
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) || '(' !== self::after( $tokens, $i ) ) {
					continue;
				}
				$name = ltrim( $token[1], '\\' );
				$prev = self::before( $tokens, $i );
				if ( is_array( $prev ) && T_FUNCTION === $prev[0] ) {
					continue;
				}
				if ( is_array( $prev ) && T_NEW === $prev[0] ) {
					$call = isset( $declared[ $name ] ) || 'self' === $name ? '' : 'new ' . $name;
				} elseif ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					$call = isset( $methods[ $name ] ) ? '' : '->' . $name;
				} elseif ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					$class = self::before( $tokens, self::index_before( $tokens, $i ) );
					$class = is_array( $class ) ? ltrim( $class[1], '\\' ) : '';
					if ( in_array( $class, array( 'self', 'static', 'parent' ), true ) ) {
						$call = isset( $methods[ $name ] ) ? '' : ( $parents[ $base ][0] ?? '?' ) . '::' . $name;
					} elseif ( '$w' === $class ) {
						// The write side of a kind (AB_MCP_Sync_Engine::writer(), branch 9):
						// one of WRITERS, classes declared here, and $w is assigned from
						// writer() only (testTheWriteSideOfAKindIsAClassOfTheTakeover).
						$call = '';
					} else {
						$call = isset( $declared[ $class ] ) ? '' : $class . '::' . $name;
					}
				} else {
					$call = function_exists( $name ) && ( new \ReflectionFunction( $name ) )->isInternal() && 'unserialize' !== $name ? '' : $name;
				}
				if ( '' !== $call ) {
					$calls[ $base ][ $call ] = ( $calls[ $base ][ $call ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $calls );
		return array( $calls, $texts );
	}

	/** @param array $tokens Tokens. */
	private static function next_name( array $tokens, int $i ): string {
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				return ltrim( $tokens[ $j ][1], '\\' );
			}
		}
		return '';
	}

	/** @param array $tokens Tokens. */
	private static function after( array $tokens, int $i ) {
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
				return $tokens[ $j ];
			}
		}
		return null;
	}

	/** @param array $tokens Tokens. */
	private static function index_before( array $tokens, int $i ): int {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
				return $j;
			}
		}
		return 0;
	}

	/** @param array $tokens Tokens. */
	private static function before( array $tokens, int $i ) {
		return $tokens[ self::index_before( $tokens, $i ) ] ?? null;
	}

	public function testTheWriteSideOfAKindIsAClassOfTheTakeover(): void {
		// The calls through $w (branch 9) count as calls of a class declared
		// under includes/sync/ (scan()): so every writer is one, and $w is
		// assigned nowhere but from AB_MCP_Sync_Engine::writer() or
		// writer_of(), which answer from WRITERS.
		foreach ( \AB_MCP_Sync_Engine::WRITERS as $kind => $class ) {
			self::assertTrue( class_exists( $class ), $kind );
			self::assertStringStartsWith( dirname( __DIR__ ) . '/includes/sync/', (string) ( new \ReflectionClass( $class ) )->getFileName(), $kind );
		}
		$assigned = 0;
		foreach ( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) as $file ) {
			$text = (string) file_get_contents( $file );
			if ( false === strpos( $text, '$w::' ) ) {
				// A file that calls nothing through $w may name a variable so
				// (the nodes of AB_MCP_Sync_Decision::cycles()).
				continue;
			}
			foreach ( preg_split( '/\R/', $text ) as $n => $line ) {
				if ( 1 === preg_match( '/\$w\s*=(?!=)/', $line ) ) {
					++$assigned;
					self::assertMatchesRegularExpression( '/\$w\s*=\s*(self::writer(_of)?|AB_MCP_Sync_Engine::writer)\(/', $line, basename( $file ) . ':' . ( $n + 1 ) );
				}
			}
		}
		self::assertGreaterThan( 0, $assigned );
	}

	public function testEveryCallIsAllowedWithItsReasonOrAnExceptionWithItsCount(): void {
		list( $calls ) = self::scan();
		$outside       = array();
		$used          = array();
		foreach ( $calls as $file => $names ) {
			foreach ( $names as $call => $count ) {
				if ( isset( self::ALLOWED[ $call ] ) ) {
					$used[ $call ] = true;
					continue;
				}
				$outside[ $file ][ $call ] = $count;
			}
		}
		$want = array();
		foreach ( self::EXCEPTIONS as $file => $functions ) {
			foreach ( $functions as $function => list( $count, $reason ) ) {
				self::assertNotSame( '', $reason );
				$want[ $file ][ $function ] = $count;
			}
		}
		foreach ( array( &$want, &$outside ) as &$list ) {
			ksort( $list );
			foreach ( $list as &$names ) {
				ksort( $names );
			}
			unset( $names );
		}
		unset( $list );
		self::assertSame( $want, $outside, 'A call under includes/sync/ reads no stored value (ALLOWED, with the reason) or stands on EXCEPTIONS with its count and reason.' );
		foreach ( self::ALLOWED as $call => $reason ) {
			self::assertNotSame( '', $reason, $call );
		}
		self::assertSame( array(), array_values( array_diff( array_keys( self::ALLOWED ), array_keys( $used ) ) ), 'Every entry of ALLOWED is called.' );
	}

	public function testNoTextNamesAFunctionThatReads(): void {
		list( , $texts ) = self::scan();
		self::assertSame( array(), $texts );
	}

	public function testTheScanSeesTheCodeAtAll(): void {
		list( $calls ) = self::scan();
		self::assertGreaterThan( 10, count( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) ) );
		self::assertSame( 1, $calls['class-sync-media.php']['has_filter'] ?? 0, 'A function call.' );
		self::assertArrayHasKey( 'AB_MCP_Undo_Meta::rows', $calls['class-sync-rows.php'], 'A static call.' );
		self::assertArrayHasKey( 'AB_MCP_Option_Guard::is_blocked', $calls['class-sync-option-schema.php'], 'A call through self:: of a parent.' );
		self::assertArrayHasKey( '->next_tag', $calls['class-sync-html.php'], 'A method.' );
		self::assertArrayHasKey( 'new WP_Block_Parser', $calls['class-sync-refs.php'], 'A constructor.' );
		self::assertArrayNotHasKey( 'strpos', $calls['class-sync-refs.php'], 'PHP\'s own functions are not counted.' );
	}
}
