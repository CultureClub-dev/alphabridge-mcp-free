<?php
/**
 * The kinds of the takeover (contract A of the plan of 01.11.2026, Z8):
 * the registry of their engines, the rule of which kind is responsible for
 * which object type (decision 25), and what Z8 locks, with the reason and
 * the stage that brings it.
 *
 * ONE OWNER PER OBJECT TYPE (concept «Echtes Staging», section 2): menu
 * items and menu terms belong to menu only, attachments to attachment,
 * widget rows to widget_area, the menu positions to menu; the generic
 * engines leave those out and make no second diff of them. OWNERSHIP is
 * that rule, for the post types, taxonomies, options and theme mods of
 * WordPress 7.1.3 and a rule for any other: an entry with a stage is
 * locked in Z8 and shown in the report with its reason and stage, an
 * entry without one belongs to its kind. What is of group B is not here
 * but in AB_MCP_Sync_Exclusions, and the resolvers below answer it first.
 *
 * LOCKS are the operations Z8 does not do although the kind is taken over
 * (no deletion at all, decisions 21 and 22, and the others named there).
 *
 * The registry takes an engine only for the entries the table gives it
 * that are not locked, all of them and no other, once per kind.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-kind.php';
require_once __DIR__ . '/class-sync-exclusions.php';

/**
 * Class AB_MCP_Sync_Kinds
 */
final class AB_MCP_Sync_Kinds {

	/**
	 * The phases of a run a kind writes in (decision 16): 2 code (Z8b),
	 * 3 the configuration that registers types (Z8d), 5 contents. Phase 1 is
	 * the check before the first write, 4 the shells (empty in Z8), 6 the
	 * deletions (none in Z8), 7 the post-processing and 8 the check of a run.
	 */
	const PHASES = array(
		2 => 'code',
		3 => 'registration',
		5 => 'contents',
	);

	/**
	 * Who is responsible for which object type. kind: the kind (null where
	 * no kind is meant); art: the type of change of the concept's catalogue;
	 * stage and reason: locked in Z8 until that stage; fields: an option of
	 * shared fields, each field resolved on its own.
	 */
	const OWNERSHIP = array(
		// Post types WordPress registers (wp-includes/post.php, create_initial_post_types()).
		'post_type:post'                => array( 'kind' => 'post', 'art' => 'A1' ),
		'post_type:page'                => array( 'kind' => 'post', 'art' => 'A1' ),
		'post_type:attachment'          => array( 'kind' => 'attachment', 'art' => 'A3' ),
		'post_type:nav_menu_item'       => array( 'kind' => 'menu', 'art' => 'A5' ),
		'post_type:custom_css'          => array( 'kind' => 'custom_css', 'art' => 'A9' ),
		'post_type:customize_changeset' => array(
			'kind'   => null,
			'art'    => 'A23',
			'stage'  => 'Z8d',
			'reason' => 'A Customizer changeset runs its settings when it is published, as stored users or with the capability exist; it needs a schema of its own.',
		),
		'post_type:wp_block'            => array( 'kind' => 'post', 'art' => 'A10' ),
		'post_type:wp_template'         => array( 'kind' => 'post', 'art' => 'A10' ),
		'post_type:wp_template_part'    => array( 'kind' => 'post', 'art' => 'A10' ),
		'post_type:wp_global_styles'    => array( 'kind' => 'post', 'art' => 'A10' ),
		'post_type:wp_navigation'       => array( 'kind' => 'post', 'art' => 'A6' ),
		'post_type:wp_font_family'      => array(
			'kind'   => null,
			'art'    => 'A20',
			'stage'  => 'Z8b',
			'reason' => 'A font of the font library comes with its files under uploads/fonts, which are not attachments.',
		),
		'post_type:wp_font_face'        => array(
			'kind'   => null,
			'art'    => 'A20',
			'stage'  => 'Z8b',
			'reason' => 'A font of the font library comes with its files under uploads/fonts, which are not attachments.',
		),
		'post_type:*'                   => array(
			'kind'   => null,
			'art'    => 'A1',
			'stage'  => 'Z8d',
			'reason' => 'A post type of a plugin or theme, not classified yet.',
		),

		// Taxonomies WordPress registers (wp-includes/taxonomy.php, create_initial_taxonomies()).
		'taxonomy:category'             => array( 'kind' => 'term', 'art' => 'A4' ),
		'taxonomy:post_tag'             => array( 'kind' => 'term', 'art' => 'A4' ),
		'taxonomy:wp_pattern_category'  => array( 'kind' => 'term', 'art' => 'A10' ),
		'taxonomy:nav_menu'             => array( 'kind' => 'menu', 'art' => 'A5' ),
		'taxonomy:link_category'        => array(
			'kind'   => null,
			'art'    => 'A4',
			'stage'  => 'Z8d',
			'reason' => 'The categories of the link manager, whose table links has no engine.',
		),
		// Part of the identity of the post that carries them, not term objects.
		'taxonomy:post_format'          => array( 'kind' => 'post', 'art' => 'A1' ),
		'taxonomy:wp_theme'             => array( 'kind' => 'post', 'art' => 'A10' ),
		'taxonomy:wp_template_part_area' => array( 'kind' => 'post', 'art' => 'A10' ),
		'taxonomy:*'                    => array(
			'kind'   => null,
			'art'    => 'A4',
			'stage'  => 'Z8d',
			'reason' => 'A taxonomy of a plugin or theme, not classified yet.',
		),

		// Options with an owner of their own (decision 24: the object is a field of the row).
		'option:sidebars_widgets'       => array( 'kind' => 'widget_area', 'art' => 'A7' ),
		'option:widget_*'               => array( 'kind' => 'widget_area', 'art' => 'A7' ),
		'option:theme_mods_*'           => array(
			'kind'   => null,
			'art'    => 'A9',
			'fields' => 'theme_mod',
		),
		'option:{prefix}user_roles'     => array(
			'kind'   => 'roles',
			'art'    => 'A18',
			'stage'  => 'Z8d',
			'reason' => 'Roles follow with the rule for the registration as a class of the core (decision 29).',
		),
		'option:users_can_register'     => array(
			'kind'   => 'registration',
			'art'    => 'A8',
			'stage'  => 'Z8d',
			'reason' => 'The registration pair follows with the rule for the registration as a class of the core (decision 29).',
		),
		'option:default_role'           => array(
			'kind'   => 'registration',
			'art'    => 'A8',
			'stage'  => 'Z8d',
			'reason' => 'The registration pair follows with the rule for the registration as a class of the core (decision 29).',
		),
		'option:active_plugins'         => array(
			'kind'   => null,
			'art'    => 'A11',
			'stage'  => 'Z8b',
			'reason' => 'Which plugins run is the code of the site.',
		),
		'option:template'               => array(
			'kind'   => null,
			'art'    => 'A9',
			'stage'  => 'Z8b',
			'reason' => 'A change of the theme is a phase of the code.',
		),
		'option:stylesheet'             => array(
			'kind'   => null,
			'art'    => 'A9',
			'stage'  => 'Z8b',
			'reason' => 'A change of the theme is a phase of the code.',
		),
		'option:nav_menu_options'       => array(
			'kind'   => 'menu',
			'art'    => 'A5',
			'stage'  => 'Z8d',
			'reason' => 'Whether a menu adds new top-level pages by itself is a field of the menu in a row of all menus; Z8 reads it (decision 21) and does not take it over.',
		),
		// Any other option: the option kind takes core options with a
		// schema only (decision 27), the others are locked (LOCKS option:unclassified).
		'option:*'                      => array( 'kind' => 'option', 'art' => 'A8' ),

		// The keys of theme_mods_<stylesheet> (decisions 24 and 25).
		'theme_mod:nav_menu_locations'  => array( 'kind' => 'menu', 'art' => 'A5' ),
		'theme_mod:custom_css_post_id'  => array( 'kind' => 'custom_css', 'art' => 'A9' ),
		'theme_mod:*'                   => array(
			'kind'   => 'theme_mods',
			'art'    => 'A9',
			'stage'  => 'Z8d',
			'reason' => 'set_theme_mod() skips the sanitizers and capabilities of the Customizer settings; theme mods follow through those.',
		),

		// Files (decision 26).
		'files:attachment'              => array( 'kind' => 'attachment', 'art' => 'A3' ),
		'files:*'                       => array(
			'kind'   => null,
			'art'    => 'A22',
			'stage'  => 'Z8b',
			'reason' => 'Files outside the file lists of attachments: counted, their contents not compared.',
		),
	);

	/**
	 * Operations Z8 does not do on a kind it takes over ('*' for every
	 * kind), with the reason and the stage that brings them.
	 */
	const LOCKS = array(
		'*:delete'                     => array(
			'stage'  => 'Z8d',
			'reason' => 'No object is deleted in Z8, and no status stands in for a deletion: an object deleted on staging is shown as such.',
		),
		'*:restore_deleted'            => array(
			'stage'  => 'Z8d',
			'reason' => 'Bringing back an object deleted on one site, from the other site or from a backup, follows with the deletions and their way back.',
		),
		'menu:remove_item'             => array(
			'stage'  => 'Z8d',
			'reason' => 'Removing a menu item is a deletion with the consequences of wp_delete_post(); a menu with an item removed on staging is locked as a whole.',
		),
		'widget_area:remove_instance'  => array(
			'stage'  => 'Z8d',
			'reason' => 'A widget area with an instance removed on staging is locked as a whole: writing the rest would not bring it to the staging state.',
		),
		'custom_css:create'            => array(
			'stage'  => 'Z8d',
			'reason' => 'Creating Additional CSS sets custom_css_post_id as well, and no way back through the trash undoes it.',
		),
		'term:create_with_meta'        => array(
			'stage'  => 'Z8d',
			'reason' => 'The right to the meta of a new term can only be asked once the term exists, and a term made in a run is not taken back.',
		),
		'post:create_with_object_meta' => array(
			'stage'  => 'Z8d',
			'reason' => 'The right to a meta key of a new post that only the object answers can only be asked once the post exists.',
		),
		'*:meta_without_rule'          => array(
			'stage'  => 'Z8d',
			'reason' => 'A meta key map_meta_cap refuses and that differs between the sides waits for its field rule.',
		),
		'post:builder'                 => array(
			'stage'  => 'Z8c',
			'reason' => 'A document of a page builder other than the six released block libraries.',
		),
		'post:builder_unknown'         => array(
			'stage'  => 'Z8c',
			'reason' => 'A plugin registers a reader for a page builder that has no signature here; which posts it built only that reader\'s code tells, so no post is taken over while it is registered.',
		),
		'attachment:replace_file'      => array(
			'stage'  => 'Z8b',
			'reason' => 'A different file at the path on the live site would be replaced.',
		),
		'attachment:shared_path'       => array(
			'stage'  => 'Z8b',
			'reason' => 'Another attachment of the live site uses a file at this path.',
		),
		'option:unclassified'          => array(
			'stage'  => 'Z8d',
			'reason' => 'An option of a plugin or theme, or one without a schema, not classified yet.',
		),
		'*:mutual_references'          => array(
			'stage'  => 'Z11c',
			'reason' => 'Objects that need each other cannot be made visible together in WordPress; how often that happens is measured first.',
		),
		// What the canonical form finds in an object (branch 4).
		'*:php_object'                 => array(
			'stage'  => 'Z8d',
			'reason' => 'A stored value holds a PHP object, a reference or a record that is read only with the code of its class; it is neither compared nor taken over without that class.',
		),
		'*:unreadable_value'           => array(
			'stage'  => 'Z8d',
			'reason' => 'A stored value looks serialized but does not unpack, or nests deeper than it is read: nothing says what it holds.',
		),
		'*:possible_reference'         => array(
			'stage'  => 'Z8d',
			'reason' => 'A whole number without a known schema equals the staging id of an object in the scope whose live id differs: it may point to that object, and no rule says whether to rewrite it.',
		),
		'*:reference_spelling'         => array(
			'stage'  => 'Z8d',
			'reason' => 'A reference is stored in a spelling the rewriter does not write back as WordPress reads it (backslash escapes, character references, other quotes, the attribute twice, an image id outside the class): it is not rewritten.',
		),
		'*:credential_field'           => array(
			'stage'  => 'Z8d',
			'reason' => 'A field inside the value has the name of a credential: the value is mixed, and no field rule says which part may go.',
		),
		'post:password_protected'      => array(
			'stage'  => 'Z8d',
			'reason' => 'The post is protected by a password (post_password): the password is a credential, so the post is taken over by hand only.',
		),
		'*:html_outside_kses'          => array(
			'stage'  => 'Z8d',
			'reason' => 'The value holds HTML outside the list of tags and attributes WordPress lets through in posts (kses), such as srcdoc, SVG, embed or base: what it names is not read, so it is taken over by hand only.',
		),
		'*:script'                     => array(
			'stage'  => 'Z8d',
			'reason' => 'The value holds a script of JavaScript, which no parser of WordPress reads: what it names (ids, addresses, credentials) is not known, so it is taken over by hand only.',
		),
		'*:unread_reference'           => array(
			'stage'  => 'Z8d',
			'reason' => 'A layer of the stored value (its text decoded, a data: address, base64, a string of CSS, JSON a plugin reads) holds an address of the sites that names an object by its id where no reader of WordPress read it: it would be taken over unchanged and name another object on the live site, so the object is taken over by hand only.',
		),
		'*:unread_credential'          => array(
			'stage'  => 'Z8d',
			'reason' => 'A layer of the stored value (its text decoded, a data: address, base64, an image, a script of an image, a dropped value of JSON) holds the name of a credential with a value beside it that the credential check did not name: nothing of it is compared or packed, the object is taken over by hand only.',
		),
		'attachment:missing_file'      => array(
			'stage'  => 'Z8b',
			'reason' => 'A file of the attachment\'s file list is not there.',
		),
		'attachment:uploads_by_code'   => array(
			'stage'  => 'Z8b',
			'reason' => 'Code decides where the uploads lie (a filter on upload_dir or on the option upload_path, as offload plugins add, or a network of sites): the files are neither compared nor taken over.',
		),
		'attachment:unsafe_path'       => array(
			'stage'  => 'Z8b',
			'reason' => 'A path of the attachment\'s file list is not a plain relative path under the uploads, or a link or no regular file lies on the way.',
		),
		'option:off_schema'            => array(
			'stage'  => 'Z8d',
			'reason' => 'The stored value of a core option does not keep the rule of its schema.',
		),
		// What the engines of the kinds post and term do not carry to their
		// end (branches 8 and 9; AB_MCP_Sync_Post_Writer, AB_MCP_Sync_Term_Writer),
		// and the engines that follow for the other kinds.
		'post:engine_type'             => array(
			'stage'  => 'Z8',
			'reason' => 'The other core types of the kind post (templates, template parts, global styles, navigations, reusable blocks) follow with branch 9b of Z8, right after branch 9; until then the engine of the kind post writes posts and pages.',
		),
		'post:other_kind'              => array(
			'stage'  => 'Z8',
			'reason' => 'A post type of another kind (an attachment, a menu item, the additional CSS) is written by the engine of its own kind, never by the engine of the kind post: plan it again from a fresh report.',
		),
		'post:in_trash'                => array(
			'stage'  => 'Z8d',
			'reason' => 'The staging object lies in the trash, a deletion on the staging site; no status stands in for a deletion, and deletions follow with their way back (as *:delete).',
		),
		'post:auto_draft'              => array(
			'stage'  => 'Z8',
			'reason' => 'An auto-draft (WordPress made it for the editor, nobody saved it, WordPress removes it after seven days) has no content: it is never taken over, by no stage.',
		),
		'post:status_rule'             => array(
			'stage'  => 'Z8d',
			'reason' => 'A status the engine of the kind post does not write (a status of a plugin, or one WordPress gives no post or page) waits for its field rule; the engine writes publish, future, draft, pending and private.',
		),
		'post:content_missing'         => array(
			'stage'  => 'Z8',
			'reason' => 'The package carries no whole content of the object (a row of it could not be read on the staging site), so nothing of it is written: make the inventory and the package again on the staging site.',
		),
		'post:lock_unread'             => array(
			'stage'  => 'Z8',
			'reason' => 'The canonical form of the object carries a lock this version does not read (the other site runs another version of AlphaBridge), so nothing of it is written: bring both sites to the same version.',
		),
		'post:type_unregistered'       => array(
			'stage'  => 'Z8',
			'reason' => 'The post type of the object is not registered on this site (the plugin or theme that registers it is not active here): activate it here, or take the object over by hand.',
		),
		'post:meta_rule'               => array(
			'stage'  => 'Z8d',
			'reason' => 'A meta value the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.',
		),
		'post:taxonomy_rule'           => array(
			'stage'  => 'Z8d',
			'reason' => 'A term of a taxonomy of a plugin or theme on the post waits for the classification of that taxonomy; the engine of the kind post sets category, post_tag and post_format.',
		),
		'post:meta_spelling'           => array(
			'stage'  => 'Z8d',
			'reason' => 'Two meta keys of the post that the database compares alike (Foo and foo under the collation of postmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.',
		),
		'post:term_order'              => array(
			'stage'  => 'Z8d',
			'reason' => 'A relationship with a term_order other than 0 (a plugin that sorts the terms), on the staging post or on the post of this site, waits for its field rule: wp_set_object_terms() makes every relationship anew with the order 0, so a step could not write the order of the staging site, and the way back could not give the order of this site back.',
		),
		'post:slug_collision'          => array(
			'stage'  => 'Z8',
			'reason' => 'WordPress would store another slug than the staging object has (another post of its type and parent holds it, a post in the trash holds it and would be renamed, a pending post of an account that may not publish it loses it, a published one without a slug gets one from its title): change the slug on the staging site, or pair the two objects first (wp_site_pairs).',
		),
		'post:sanitizer'               => array(
			'stage'  => 'Z8',
			'reason' => 'A field or meta value of the object changes in the sanitizing WordPress runs on a save (kses without unfiltered_html, sanitize_title(), sanitize_meta()), so the written object would not be the target: take it over with an account that may store unfiltered HTML, or by hand.',
		),
		'post:thumbnail'               => array(
			'stage'  => 'Z8',
			'reason' => 'The featured image names an attachment of this site that WordPress shows as no image, and set_post_thumbnail() would remove the featured image instead of setting it: check the attachment in the media library of this site.',
		),
		'post:menu_automatic'          => array(
			'stage'  => 'Z8',
			'reason' => 'A page of the top level is published or scheduled only while no menu of this site adds pages by itself (the automatic would add it, and the cron would publish a scheduled one); the confirmed choice is to take it over as draft, or the automatic is switched off in the menu.',
		),
		'post:author_unknown'          => array(
			'stage'  => 'Z8',
			'reason' => 'The author of the staging object has no account of that login on this site; make the account, or take the object over by hand.',
		),
		'post:status_date'             => array(
			'stage'  => 'Z8',
			'reason' => 'WordPress would store another status than the staging site has (a scheduled date that passed, or one too close): set the date anew on the staging site.',
		),
		'post:reference_unmapped'      => array(
			'stage'  => 'Z8',
			'reason' => 'A reference of the object (its parent, a term, an id in its content) has no counterpart on this site: pair or create the target first.',
		),
		'post:page_template'           => array(
			'stage'  => 'Z8',
			'reason' => 'The template of the staging object (_wp_page_template) is not one the theme of this site offers for its type, so WordPress would store default instead: add the template to the theme of this site, or take the object over by hand.',
		),
		'term:taxonomy_rule'           => array(
			'stage'  => 'Z8d',
			'reason' => 'The engine of the kind term writes the terms of category, post_tag and wp_pattern_category, registered on this site, from a package with their content; a term of another taxonomy waits for its classification.',
		),
		'term:term_group'              => array(
			'stage'  => 'Z8d',
			'reason' => 'A term in a group of aliases (term_group other than 0) waits for its field rule: the group is an id of the site\'s own, which no pair maps.',
		),
		'term:parent'                  => array(
			'stage'  => 'Z8d',
			'reason' => 'The parent would close a loop of parents on this site (wp_update_term() would break it by changing the parent of other terms), or the taxonomy has no parents here: set the parents by hand in wp-admin.',
		),
		'term:slug_collision'          => array(
			'stage'  => 'Z8',
			'reason' => 'Another term of the taxonomy holds the slug on this site, or WordPress would store another one: change the slug on the staging site, or pair the two terms first (wp_site_pairs).',
		),
		'term:sanitizer'               => array(
			'stage'  => 'Z8',
			'reason' => 'A field or meta value of the term changes in the sanitizing WordPress runs on a save (the filters of term names and descriptions, kses without unfiltered_html, sanitize_meta()), or the slug is empty: take it over with an account that may store unfiltered HTML, or by hand.',
		),
		'term:reference_unmapped'      => array(
			'stage'  => 'Z8',
			'reason' => 'A reference of the term (its parent, an address in its name, description or meta) has no counterpart on this site: pair or create the target first.',
		),
		'term:meta_rule'               => array(
			'stage'  => 'Z8d',
			'reason' => 'A meta value of the term the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.',
		),
		'term:meta_spelling'           => array(
			'stage'  => 'Z8d',
			'reason' => 'Two meta keys of the term that the database compares alike (Foo and foo under the collation of termmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.',
		),
		'attachment:engine'            => array(
			'stage'  => 'Z10',
			'reason' => 'The engine that writes attachments and their files follows with branch 10.',
		),
		'menu:engine'                  => array(
			'stage'  => 'Z11',
			'reason' => 'The engine that writes menus and their items follows with branch 11.',
		),
		'widget_area:engine'           => array(
			'stage'  => 'Z11',
			'reason' => 'The engine that writes widget areas follows with branch 11.',
		),
		'custom_css:engine'            => array(
			'stage'  => 'Z11',
			'reason' => 'The engine that writes the additional CSS follows with branch 11.',
		),
		'option:engine'                => array(
			'stage'  => 'Z11',
			'reason' => 'The engine that writes core options follows with branch 11.',
		),
	);

	/** @var array<string,AB_MCP_Sync_Kind> The registered kinds, by name, in order of registration. */
	private static $kinds = array();

	/* --------------------------------------------------------- registry */

	/**
	 * Register the engine of a kind.
	 *
	 * @param AB_MCP_Sync_Kind $kind Engine.
	 * @return true|WP_Error
	 */
	public static function register( AB_MCP_Sync_Kind $kind ) {
		$name = $kind->kind();
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $name ) ) {
			return new WP_Error( 'ab_mcp_sync_kind_invalid', 'A kind needs a name of a-z, 0-9 and _ that starts with a letter, at most 32 characters. Use such a name.' );
		}
		if ( isset( self::$kinds[ $name ] ) ) {
			return new WP_Error( 'ab_mcp_sync_kind_duplicate', sprintf( 'The kind %s is registered already, and one kind has one engine. Use AB_MCP_Sync_Kinds::get() to reach it.', $name ), array( 'kind' => $name ) );
		}
		if ( ! is_int( $kind->phase() ) || ! isset( self::PHASES[ $kind->phase() ] ) ) {
			return new WP_Error( 'ab_mcp_sync_kind_phase', sprintf( 'The kind %s names no phase a kind writes in. Use 2 (code), 3 (registration) or 5 (contents).', $name ), array( 'kind' => $name ) );
		}
		$claimed = (array) $kind->responsibility();
		$owned   = array();
		foreach ( self::OWNERSHIP as $key => $entry ) {
			if ( $name !== $entry['kind'] ) {
				continue;
			}
			if ( isset( $entry['stage'] ) ) {
				if ( in_array( $key, $claimed, true ) ) {
					return new WP_Error(
						'ab_mcp_sync_kind_locked',
						sprintf( '%1$s is locked until %2$s: %3$s Use only the entries of the table without a stage.', $key, $entry['stage'], $entry['reason'] ),
						array(
							'kind'  => $name,
							'key'   => $key,
							'stage' => $entry['stage'],
						)
					);
				}
				continue;
			}
			$owned[] = $key;
		}
		sort( $claimed );
		sort( $owned );
		if ( array() === $owned || $claimed !== $owned ) {
			return new WP_Error(
				'ab_mcp_sync_kind_responsibility',
				sprintf( 'The kind %1$s must be responsible for exactly what AB_MCP_Sync_Kinds::OWNERSHIP gives it: %2$s. Use that list in responsibility().', $name, array() === $owned ? 'nothing it may take over in this version' : implode( ', ', $owned ) ),
				array(
					'kind'    => $name,
					'claimed' => $claimed,
					'owned'   => $owned,
				)
			);
		}
		self::$kinds[ $name ] = $kind;
		return true;
	}

	/**
	 * @param string $name Kind.
	 * @return AB_MCP_Sync_Kind|null
	 */
	public static function get( $name ) {
		return isset( self::$kinds[ $name ] ) ? self::$kinds[ $name ] : null;
	}

	/**
	 * The registered kinds by phase, within a phase in order of registration.
	 *
	 * @return AB_MCP_Sync_Kind[]
	 */
	public static function all() {
		$order = array_flip( array_keys( self::$kinds ) );
		$kinds = array_values( self::$kinds );
		usort(
			$kinds,
			static function ( $a, $b ) use ( $order ) {
				return array( $a->phase(), $order[ $a->kind() ] ) <=> array( $b->phase(), $order[ $b->kind() ] );
			}
		);
		return $kinds;
	}

	/**
	 * Forget every registered kind (a test, a fresh start of the registry).
	 */
	public static function reset() {
		self::$kinds = array();
	}

	/* ---------------------------------------------------- responsibility */

	/**
	 * @param string $post_type Post type.
	 * @return array The answer of answer().
	 */
	public static function post_type( $post_type ) {
		if ( AB_MCP_Sync_Exclusions::post_type( $post_type ) ) {
			return self::excluded( 'post_type:' . $post_type );
		}
		return self::answer( 'post_type:' . $post_type, 'post_type:*' );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return array The answer of answer().
	 */
	public static function taxonomy( $taxonomy ) {
		return self::answer( 'taxonomy:' . $taxonomy, 'taxonomy:*' );
	}

	/**
	 * @param string      $name   Option name, exactly as stored.
	 * @param string|null $prefix The table prefix of the site (for the
	 *                            roles); null: the one of $wpdb.
	 * @return array The answer of answer().
	 */
	public static function option( $name, $prefix = null ) {
		$name = (string) $name;
		if ( AB_MCP_Sync_Exclusions::option( $name ) ) {
			return self::excluded( 'option:' . $name );
		}
		if ( null === $prefix ) {
			$prefix = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : '';
		}
		if ( '' !== $prefix && $prefix . 'user_roles' === $name ) {
			return self::answer( 'option:{prefix}user_roles', 'option:*' );
		}
		if ( isset( self::OWNERSHIP[ 'option:' . $name ] ) ) {
			return self::answer( 'option:' . $name, 'option:*' );
		}
		foreach ( array( 'widget_', 'theme_mods_' ) as $start ) {
			if ( 0 === strpos( $name, $start ) && strlen( $name ) > strlen( $start ) ) {
				return self::answer( 'option:' . $start . '*', 'option:*' );
			}
		}
		return self::answer( 'option:*', 'option:*' );
	}

	/**
	 * @param string $key Key in theme_mods_<stylesheet>.
	 * @return array The answer of answer().
	 */
	public static function theme_mod( $key ) {
		return self::answer( 'theme_mod:' . $key, 'theme_mod:*' );
	}

	/**
	 * @param string $area 'attachment' for the files of an attachment's
	 *                     file list (decision 26), anything else for others.
	 * @return array The answer of answer().
	 */
	public static function files( $area ) {
		return self::answer( 'files:' . $area, 'files:*' );
	}

	/**
	 * Whether Z8 locks an operation on a kind.
	 *
	 * @param string $kind Kind.
	 * @param string $op   Operation, such as delete.
	 * @return array|null 'key', 'stage', 'reason'; null when it is not locked.
	 */
	public static function lock( $kind, $op ) {
		foreach ( array( $kind . ':' . $op, '*:' . $op ) as $key ) {
			if ( isset( self::LOCKS[ $key ] ) ) {
				return array( 'key' => $key ) + self::LOCKS[ $key ];
			}
		}
		return null;
	}

	/**
	 * The entry of the table for a key, or for the rule of the others.
	 *
	 * @param string $key   Key.
	 * @param string $other Key of the rule for any other.
	 * @return array 'key', 'status' (z8, locked or fields), 'kind', 'art',
	 *               'stage' and 'reason' (null where none).
	 */
	private static function answer( $key, $other ) {
		$found = isset( self::OWNERSHIP[ $key ] ) ? $key : $other;
		$entry = self::OWNERSHIP[ $found ];
		$state = 'z8';
		if ( isset( $entry['stage'] ) ) {
			$state = 'locked';
		} elseif ( isset( $entry['fields'] ) ) {
			$state = 'fields';
		}
		return array(
			'key'    => $found,
			'status' => $state,
			'kind'   => $entry['kind'],
			'art'    => $entry['art'],
			'stage'  => isset( $entry['stage'] ) ? $entry['stage'] : null,
			'reason' => isset( $entry['reason'] ) ? $entry['reason'] : null,
		);
	}

	/**
	 * @param string $key What was asked.
	 * @return array The answer for an object of group B.
	 */
	private static function excluded( $key ) {
		return array(
			'key'    => $key,
			'status' => 'excluded',
			'kind'   => null,
			'art'    => 'B',
			'stage'  => null,
			'reason' => 'Data of the running site (group B): never compared, never taken over.',
		);
	}
}
