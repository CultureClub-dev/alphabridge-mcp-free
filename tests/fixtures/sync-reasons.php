<?php
/**
 * Why each entry of the takeover's tables stands where it stands
 * (AB_MCP_Sync_Kinds::OWNERSHIP and ::LOCKS, AB_MCP_Sync_Exclusions): one
 * reason per entry, with where WordPress 7.1.3 or this plugin shows it.
 *
 *   'why'    the reason, in one sentence;
 *   'core'   [file under ABSPATH, line, text on that line] — checked against
 *            the core in tests/integration/SyncCoreReasonsTest.php;
 *   'plugin' [file of this repo, line, text on that line] — checked in
 *            tests/SyncKindsTest.php;
 *   'plan'   the decision of the Z8 plan of 01.11.2026.
 *
 * Keys: the keys of OWNERSHIP and LOCKS; for the exclusions post_type:,
 * option:, option_prefix:, option_suffix:, post_meta:, post_meta_prefix:
 * and column:<table>.<column>. tests/SyncKindsTest.php holds that the keys
 * here are exactly the entries of the tables.
 *
 * @package AlphaBridge_MCP
 */

return array(

	/* ------------------------------------------------------- ownership */

	'ownership'  => array(
		'post_type:post'                 => array(
			'why'  => 'A post of the core (A1): its row, meta, terms, author and featured image go with the post kind.',
			'core' => array( array( 'wp-includes/post.php', 24, "'post'," ) ),
			'plan' => 'Entscheid 25 (post: A1)',
		),
		'post_type:page'                 => array(
			'why'  => 'A page of the core (A1), the same store as a post.',
			'core' => array( array( 'wp-includes/post.php', 59, "'page'," ) ),
			'plan' => 'Entscheid 25 (post: A1)',
		),
		'post_type:attachment'           => array(
			'why'  => 'An attachment is a post with files; only the attachment kind knows the file list.',
			'core' => array( array( 'wp-includes/post.php', 93, "'attachment'," ), array( 'wp-includes/post.php', 6926, 'function wp_delete_attachment_files(' ) ),
			'plan' => 'Entscheid 25 und 26 (attachment: A3)',
		),
		'post_type:nav_menu_item'        => array(
			'why'  => 'A menu item exists only within its menu: the menu kind writes it through wp_update_nav_menu_item(), never the post kind.',
			'core' => array( array( 'wp-includes/post.php', 150, "'nav_menu_item'," ), array( 'wp-includes/nav-menu.php', 418, 'function wp_update_nav_menu_item(' ) ),
			'plan' => 'Entscheid 25 (menu: Einträge als eigene Objektpaare)',
		),
		'post_type:custom_css'           => array(
			'why'  => 'Additional CSS is a post per stylesheet that wp_update_custom_css_post() writes: its identity is the stylesheet, not the post.',
			'core' => array( array( 'wp-includes/post.php', 189, "'custom_css'," ), array( 'wp-includes/theme.php', 2084, 'function wp_update_custom_css_post(' ), array( 'wp-includes/theme.php', 2138, "'post_type'             => 'custom_css'," ) ),
			'plan' => 'Entscheid 25 (custom_css: A9)',
		),
		'post_type:customize_changeset'  => array(
			'why'  => 'Publishing a changeset runs its settings through the Customizer (a hook on every status change), not as a post: locked until its schema (Z8d).',
			'core' => array( array( 'wp-includes/post.php', 221, "'customize_changeset'," ), array( 'wp-includes/default-filters.php', 570, "'_wp_customize_publish_changeset'" ) ),
			'plan' => 'Entscheid 30; Erhebung 1.5',
		),
		'post_type:wp_block'             => array(
			'why'  => 'A pattern (A10) is a post; its content goes with the post kind.',
			'core' => array( array( 'wp-includes/post.php', 303, "'wp_block'," ) ),
			'plan' => 'Entscheid 25 (post: A10)',
		),
		'post_type:wp_template'          => array(
			'why'  => 'A block template (A10) is a post identified by type, theme and slug.',
			'core' => array( array( 'wp-includes/post.php', 368, "'wp_template'," ) ),
			'plan' => 'Entscheid 25 (post: Vorlagen über Typ, Theme, Slug)',
		),
		'post_type:wp_template_part'     => array(
			'why'  => 'A template part (A10) is a post identified by type, theme and slug.',
			'core' => array( array( 'wp-includes/post.php', 433, "'wp_template_part'," ) ),
			'plan' => 'Entscheid 25 (post: Vorlagen über Typ, Theme, Slug)',
		),
		'post_type:wp_global_styles'     => array(
			'why'  => 'The global styles (A10) are a post per theme.',
			'core' => array( array( 'wp-includes/post.php', 497, "'wp_global_styles'," ) ),
			'plan' => 'Entscheid 25 (post: globale Stile über Typ, Theme, Slug)',
		),
		'post_type:wp_navigation'        => array(
			'why'  => 'A navigation of a block theme (A6) is a post with block markup; it goes as A1 does.',
			'core' => array( array( 'wp-includes/post.php', 539, "'wp_navigation'," ) ),
			'plan' => 'Umfang in Z8: A6 wie A1',
		),
		'post_type:wp_font_family'       => array(
			'why'  => 'A font family (A20) comes with files under uploads/fonts that are no attachments: with the files of Z8b.',
			'core' => array( array( 'wp-includes/post.php', 597, "'wp_font_family'," ), array( 'wp-includes/fonts.php', 179, "'/fonts'" ) ),
			'plan' => 'Erhebung 1.1 (A20, Z8b)',
		),
		'post_type:wp_font_face'         => array(
			'why'  => 'A font face (A20) names a file under uploads/fonts: with the files of Z8b.',
			'core' => array( array( 'wp-includes/post.php', 629, "'wp_font_face'," ), array( 'wp-includes/fonts.php', 179, "'/fonts'" ) ),
			'plan' => 'Erhebung 1.1 (A20, Z8b)',
		),
		'post_type:*'                    => array(
			'why'  => 'A post type a plugin or theme registers is not classified in Z8; unknown types stay locked until they are (Z8d).',
			'plan' => 'Umfang in Z8: A1 Kern-Typen; Erhebung 3 (A1, Klassifikation Z8d)',
		),
		'taxonomy:category'              => array(
			'why'  => 'Categories are terms of the core (A4).',
			'core' => array( array( 'wp-includes/taxonomy.php', 64, "'category'," ) ),
			'plan' => 'Entscheid 25 (term: A4)',
		),
		'taxonomy:post_tag'              => array(
			'why'  => 'Tags are terms of the core (A4).',
			'core' => array( array( 'wp-includes/taxonomy.php', 87, "'post_tag'," ) ),
			'plan' => 'Entscheid 25 (term: A4)',
		),
		'taxonomy:wp_pattern_category'   => array(
			'why'  => 'Pattern categories are ordinary terms a person makes on the pattern screen (A10 with A4).',
			'core' => array( array( 'wp-includes/taxonomy.php', 227, "'wp_pattern_category'," ) ),
			'plan' => 'Erhebung 1.1 (A4, Z8)',
		),
		'taxonomy:nav_menu'              => array(
			'why'  => 'A menu is a term of nav_menu; it belongs to the menu kind with its items and positions, not to the term kind.',
			'core' => array( array( 'wp-includes/taxonomy.php', 110, "'nav_menu'," ) ),
			'plan' => 'Entscheid 25 (menu: nav_menu-Term)',
		),
		'taxonomy:link_category'         => array(
			'why'  => 'The categories of the link manager belong to its own table links, for which Z8 has no engine.',
			'core' => array( array( 'wp-includes/taxonomy.php', 137, "'link_category'," ), array( 'wp-admin/includes/schema.php', 124, 'CREATE TABLE $wpdb->links (' ) ),
			'plan' => 'Erhebung 1.1 (gesperrt, sichtbar); Etappe Z8d mit den Tabellen ohne Engine (wie A16)',
		),
		'taxonomy:post_format'           => array(
			'why'  => 'The post formats are a fixed list of WordPress without a screen (show_ui false): the format is a field of the post.',
			'core' => array( array( 'wp-includes/taxonomy.php', 171, "'post_format'," ), array( 'wp-includes/taxonomy.php', 182, "'show_ui'           => false," ) ),
			'plan' => 'Erhebung 1.1 (Teil der Identität des Objekts)',
		),
		'taxonomy:wp_theme'              => array(
			'why'  => 'The theme term ties templates, template parts and global styles to their theme: part of their identity.',
			'core' => array( array( 'wp-includes/taxonomy.php', 189, "'wp_theme'," ), array( 'wp-includes/taxonomy.php', 190, "array( 'wp_template', 'wp_template_part', 'wp_global_styles' )," ) ),
			'plan' => 'Erhebung 1.1 (Teil der Identität des Objekts)',
		),
		'taxonomy:wp_template_part_area' => array(
			'why'  => 'The area of a template part is one of a fixed set: part of the template part.',
			'core' => array( array( 'wp-includes/taxonomy.php', 208, "'wp_template_part_area'," ), array( 'wp-includes/taxonomy.php', 209, "array( 'wp_template_part' )," ) ),
			'plan' => 'Erhebung 1.1 (Teil der Identität des Objekts)',
		),
		'taxonomy:*'                     => array(
			'why'  => 'A taxonomy of a plugin or theme belongs to a type Z8 has not classified; locked until Z8d.',
			'plan' => 'Erhebung 3 (Klassifikation Z8d)',
		),
		'option:sidebars_widgets'        => array(
			'why'  => 'The assignment of widgets to areas is one row of all areas: the widget_area kind writes its field of each area.',
			'core' => array( array( 'wp-includes/widgets.php', 1104, "update_option( 'sidebars_widgets', \$sidebars_widgets );" ) ),
			'plan' => 'Entscheid 24 und 25 (widget_area: A7)',
		),
		'option:widget_*'                => array(
			'why'  => 'The instances of a widget type live in the row widget_<id_base>, an instance per field: widget_area, through WP_Widget::update().',
			'core' => array( array( 'wp-includes/class-wp-widget.php', 172, "'widget_' . \$this->id_base;" ) ),
			'plan' => 'Entscheid 17, 24 und 25 (widget_area)',
		),
		'option:theme_mods_*'            => array(
			'why'  => 'The theme mods of a theme are one row; each key is its own object (theme_mod:…), and set_theme_mod() writes the whole row.',
			'core' => array( array( 'wp-includes/theme.php', 1028, 'get_option( "theme_mods_$theme_slug" )' ), array( 'wp-includes/theme.php', 1125, 'update_option( "theme_mods_$theme", $mods )' ) ),
			'plan' => 'Entscheid 24 (Feld je Objekt)',
		),
		'option:{prefix}user_roles'      => array(
			'why'  => 'The roles live in <prefix>user_roles; they follow with the rule for the registration as a class of the core (Z8d).',
			'core' => array( array( 'wp-includes/class-wp-roles.php', 342, "get_blog_prefix( \$this->site_id ) . 'user_roles';" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 495, "'user_roles'    => 'roles'," ) ),
			'plan' => 'Entscheid 29',
		),
		'option:users_can_register'      => array(
			'why'  => 'One half of the registration pair, which this plugin refuses on its own and which follows with the rule of Z8d.',
			'core' => array( array( 'wp-admin/includes/schema.php', 416, "'users_can_register'" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 350, "'users_can_register'           => 'registration'," ) ),
			'plan' => 'Entscheid 29; Offen bei Thomas 1',
		),
		'option:default_role'            => array(
			'why'  => 'The other half of the registration pair.',
			'core' => array( array( 'wp-admin/includes/schema.php', 466, "'default_role'" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 349, "'default_role'                 => 'registration'," ) ),
			'plan' => 'Entscheid 29; Offen bei Thomas 1',
		),
		'option:active_plugins'          => array(
			'why'  => 'Which plugins run is code (A11): with the phase of the code in Z8b.',
			'core' => array( array( 'wp-admin/includes/schema.php', 448, "'active_plugins'" ) ),
			'plan' => 'Was nicht Z8 ist: Z8b Aktivierungszustand',
		),
		'option:template'                => array(
			'why'  => 'The parent theme: a theme switch is a phase of the code (Z8b).',
			'core' => array( array( 'wp-admin/includes/schema.php', 457, "'template'" ) ),
			'plan' => 'Was nicht Z8 ist: Z8b Theme-Wechsel',
		),
		'option:stylesheet'              => array(
			'why'  => 'The active theme: a theme switch is a phase of the code (Z8b).',
			'core' => array( array( 'wp-admin/includes/schema.php', 458, "'stylesheet'" ) ),
			'plan' => 'Was nicht Z8 ist: Z8b Theme-Wechsel',
		),
		'option:nav_menu_options'        => array(
			'why'  => 'auto_add of every menu in one row: the core reads it whenever a top-level page is published, and Z8 only reads it for its lock (decision 21).',
			'core' => array( array( 'wp-includes/nav-menu.php', 1144, "get_option( 'nav_menu_options' )" ) ),
			'plan' => 'Entscheid 21 (Menü-Automatik); Feld des Menüs nicht in der Art-Tabelle, darum Z8d',
		),
		'option:*'                       => array(
			'why'  => 'Any other option goes to the option kind, which takes over core options with a schema only (the rest: option:unclassified).',
			'core' => array( array( 'wp-admin/includes/schema.php', 362, 'function populate_options(' ) ),
			'plan' => 'Entscheid 27',
		),
		'theme_mod:nav_menu_locations'   => array(
			'why'  => 'The menu positions are a field of the theme mods that the menu screen writes: they go with the menu kind.',
			'core' => array( array( 'wp-admin/nav-menus.php', 68, "set_theme_mod( 'nav_menu_locations'" ), array( 'wp-includes/nav-menu.php', 163, "get_theme_mod( 'nav_menu_locations' )" ) ),
			'plan' => 'Entscheid 17, 24 und 25 (Positionen als Feld der Menü-Art)',
		),
		'theme_mod:custom_css_post_id'   => array(
			'why'  => 'The pointer to the CSS post of the stylesheet; only the custom_css kind reads it, and nothing in Z8 sets it.',
			'core' => array( array( 'wp-includes/theme.php', 2154, "set_theme_mod( 'custom_css_post_id', \$r );" ) ),
			'plan' => 'Entscheid 25 (custom_css: nur vorhandenes ändern)',
		),
		'theme_mod:*'                    => array(
			'why'  => 'set_theme_mod() writes through one filter and update_option(), without the sanitizers and capabilities of the Customizer settings: locked until those (Z8d).',
			'core' => array( array( 'wp-includes/theme.php', 1105, 'function set_theme_mod(' ) ),
			'plan' => 'Entscheid 25 (theme_mods); Befund R3-5',
		),
		'files:attachment'               => array(
			'why'  => 'The files of an attachment are exactly those wp_delete_attachment_files() knows: the attachment kind.',
			'core' => array( array( 'wp-includes/post.php', 6926, 'function wp_delete_attachment_files(' ) ),
			'plan' => 'Entscheid 26',
		),
		'files:*'                        => array(
			'why'  => 'Other files (code, languages, fonts, files under uploads without an attachment) are counted, their contents not compared: Z8b.',
			'plan' => 'Entscheid 14 (gezählt, Inhalte nicht verglichen, Z8b)',
		),
	),

	/* ------------------------------------------------------------ locks */

	'locks'      => array(
		'*:delete'                     => array(
			'why'  => 'No object is deleted in Z8 and no status replaces a deletion: wp_delete_post() moves attachments and deletes comments of any post, menu items included.',
			'core' => array( array( 'wp-includes/post.php', 3923, "\$wpdb->update( \$wpdb->posts, \$parent_data, \$parent_where + array( 'post_type' => 'attachment' ) );" ) ),
			'plan' => 'Einleitung; Entscheid 21 und 22',
		),
		'menu:remove_item'             => array(
			'why'  => 'Removing a menu item runs wp_delete_post() and _wp_delete_post_menu_item(), which deletes items of other menus that point to it.',
			'core' => array( array( 'wp-includes/nav-menu.php', 1097, 'function _wp_delete_post_menu_item(' ) ),
			'plan' => 'Entscheid 21; Befund R6-1',
		),
		'widget_area:remove_instance'  => array(
			'why'  => 'A widget area with an instance removed on staging is locked as a whole: a part of it written would not be the staging state.',
			'plan' => 'Entscheid 21 und 25',
		),
		'custom_css:create'            => array(
			'why'  => 'wp_update_custom_css_post() sets custom_css_post_id when it creates the post, and the core reads a referenced post even in the trash.',
			'core' => array( array( 'wp-includes/theme.php', 2154, "set_theme_mod( 'custom_css_post_id', \$r );" ) ),
			'plan' => 'Entscheid 25; Befund R4-2',
		),
		'term:create_with_meta'        => array(
			'why'  => 'The meta right of a new term needs the term, and a term made in a run stays.',
			'plan' => 'Entscheid 28; Befund R5-4',
		),
		'post:create_with_object_meta' => array(
			'why'  => 'A meta key whose right only the object answers cannot be checked before the post exists.',
			'plan' => 'Entscheid 28; Befund R6-5',
		),
		'*:meta_without_rule'          => array(
			'why'  => 'A meta key map_meta_cap refuses and that differs between the sides has no field rule yet.',
			'plan' => 'Entscheid 28',
		),
		'post:builder'                 => array(
			'why'  => 'Documents of page builders other than the six block libraries follow with Z8c.',
			'plan' => 'Entscheid 31',
		),
		'post:builder_unknown'         => array(
			'why'  => 'A reader for a builder without a signature recognises its pages only with its own code, which reads stored values unchecked: every post waits while one is registered.',
			'plan' => 'Entscheid 31; Entscheid 11',
		),
		'attachment:replace_file'      => array(
			'why'  => 'No file is overwritten in Z8; another file at the path locks the attachment.',
			'plan' => 'Entscheid 26',
		),
		'attachment:shared_path'       => array(
			'why'  => 'A path another attachment of the live site uses is shared (the core keeps a thumb two attachments name).',
			'core' => array( array( 'wp-includes/post.php', 6933, "Don't delete the thumb if another attachment uses it." ) ),
			'plan' => 'Entscheid 26; Befund R3-4',
		),
		'option:unclassified'          => array(
			'why'  => 'An option of a plugin or theme, or of the core without a schema: «Option eines Plugins, nicht eingeordnet (Z8d)».',
			'plan' => 'Entscheid 27',
		),
		'*:mutual_references'          => array(
			'why'  => 'Objects that need each other cannot be made visible together; measured in Z11c, then an item of its own.',
			'plan' => 'Entscheid 14 und 16; Folgen für spätere Etappen',
		),
		'*:php_object'                 => array(
			'why'  => 'A value with an object, a reference or a record is read only with its class; the walk of the replace engine refuses it before unserialize().',
			'plugin' => array( array( 'includes/class-replace-engine.php', 532, 'public function carries_record( $text ) {' ) ),
			'plan' => 'Entscheid 11 (unvollständige Klasse sperrt)',
		),
		'*:unreadable_value'           => array(
			'why'  => 'A value that does not unpack is read by WordPress as false; one that nests deeper than MAX_DEPTH is not read to the end.',
			'plan' => 'Entscheid 11',
		),
		'*:possible_reference'         => array(
			'why'  => 'A whole number without a schema that equals the staging id of an object in the scope with another live id may be a reference no rule rewrites.',
			'plan' => 'Entscheid 12 (möglicher Verweis)',
		),
		'*:credential_field'           => array(
			'why'  => 'A field with the name of a credential inside a unit makes it mixed: locked until a field rule.',
			'plan' => 'Entscheid 9',
		),
		'attachment:missing_file'      => array(
			'why'  => 'A listed file that is missing cannot be packed or compared.',
			'plan' => 'Entscheid 26 (gelistete, aber fehlende Datei sperrt)',
		),
		'attachment:uploads_by_code'   => array(
			'why'  => 'The uploads folder is worked out from the stored rows as _wp_upload_dir() does it; where a filter or a network decides it, only code knows, and the files lock.',
			'core' => array( array( 'wp-includes/functions.php', 2429, "\$uploads = apply_filters( 'upload_dir', \$cache[ \$key ] );" ) ),
			'plan' => 'Entscheid 26; Entscheid 11',
		),
		'attachment:unsafe_path'       => array(
			'why'  => 'Only relative paths under the uploads, without links on the way, to regular files.',
			'plan' => 'Entscheid 26 (Pfade)',
		),
		'option:off_schema'            => array(
			'why'  => 'A core option whose value breaks its rule (sanitize_option() writes another) is not written as it is.',
			'core' => array( array( 'wp-includes/formatting.php', 4923, 'function sanitize_option( $option, $value ) {' ) ),
			'plan' => 'Entscheid 27 (Typ und Regel je Name)',
		),
	),

	/* ------------------------------------------------------- exclusions */

	'exclusions' => array(
		'post_type:revision'                     => array(
			'why'  => 'Revisions (B7) are made by the core on every update of a post.',
			'core' => array( array( 'wp-includes/post.php', 129, "'revision'," ), array( 'wp-includes/default-filters.php', 446, "add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );" ) ),
			'plan' => 'Entscheid 11 und 21',
		),
		'post_type:oembed_cache'                 => array(
			'why'  => 'The core keeps answers of oEmbed providers as posts of this type (B5).',
			'core' => array( array( 'wp-includes/post.php', 267, "'oembed_cache'," ), array( 'wp-includes/class-wp-embed.php', 328, "'post_type'   => 'oembed_cache'," ) ),
			'plugin' => array( array( 'includes/tools/class-tools-search-replace.php', 67, "'oembed_cache'" ) ),
			'plan' => 'Erhebung 1.1 (B5)',
		),
		'post_type:user_request'                 => array(
			'why'  => 'A request to export or erase personal data is a record of the live site (B).',
			'core' => array( array( 'wp-includes/post.php', 285, "'user_request'," ), array( 'wp-includes/user.php', 4848, "'post_type'     => 'user_request'," ) ),
			'plan' => 'Erhebung 1.1 (B)',
		),
		'option:siteurl'                         => array(
			'why'  => 'The address of WordPress is the identity of each site (B6).',
			'core' => array( array( 'wp-admin/includes/schema.php', 412, "'siteurl'" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 355, "'siteurl'                      => 'address'," ) ),
			'plan' => 'Konzept 3 (Optionen, nie übernehmen)',
		),
		'option:home'                            => array(
			'why'  => 'The address of the site is the identity of each site (B6).',
			'core' => array( array( 'wp-admin/includes/schema.php', 413, "'home'" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 356, "'home'                         => 'address'," ) ),
			'plan' => 'Konzept 3',
		),
		'option:blog_public'                     => array(
			'why'  => 'The search engine visibility; the preview protection of a staging site sets it to 0 (B6).',
			'core' => array( array( 'wp-admin/includes/schema.php', 474, "'blog_public'" ) ),
			'plugin' => array( array( 'includes/class-preview-protection.php', 452, "update_option( 'blog_public', 0 );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:admin_email'                     => array(
			'why'  => 'The address the site mails its administrator: of each site (B6, the mail settings of a copy).',
			'core' => array( array( 'wp-admin/includes/schema.php', 417, "'admin_email'" ) ),
			'plan' => 'Konzept 3',
		),
		'option:cron'                            => array(
			'why'  => 'The planned events of WP-Cron are instances of the running site (B5).',
			'core' => array( array( 'wp-includes/cron.php', 1304, "update_option( 'cron', \$cron, true );" ) ),
			'plugin' => array( array( 'includes/class-option-guard.php', 357, "'cron'                         => 'cron'," ) ),
			'plan' => 'Konzept 3 (Instanzen)',
		),
		'option:rewrite_rules'                   => array(
			'why'  => 'The rewrite rules are worked out by the core from the permalink settings (Phase 7 rebuilds them).',
			'core' => array( array( 'wp-includes/class-wp-rewrite.php', 1522, "update_option( 'rewrite_rules', \$this->rules );" ) ),
			'plan' => 'Konzept 3; Entscheid 33 (Rewrite bei A19)',
		),
		'option:recently_activated'              => array(
			'why'  => 'The plugins deactivated lately, for the plugin screen of this site.',
			'core' => array( array( 'wp-admin/plugins.php', 74, "update_option( 'recently_activated', \$recent, false );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:recently_edited'                 => array(
			'why'  => 'The files edited lately in the file editor of this site.',
			'core' => array( array( 'wp-admin/includes/misc.php', 355, "update_option( 'recently_edited', \$oldfiles );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:uninstall_plugins'               => array(
			'why'  => 'The uninstall callbacks the plugins of this site registered.',
			'core' => array( array( 'wp-includes/plugin.php', 950, "update_option( 'uninstall_plugins', \$uninstallable_plugins );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:db_version'                      => array(
			'why'  => 'The version of the database schema, written by the upgrade of the core.',
			'core' => array( array( 'wp-admin/includes/upgrade.php', 897, "update_option( 'db_version', \$wp_db_version );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:initial_db_version'              => array(
			'why'  => 'The database version the site was installed with.',
			'core' => array( array( 'wp-admin/includes/schema.php', 570, "\$defaults['initial_db_version']" ) ),
			'plan' => 'Konzept 3',
		),
		'option:fresh_site'                      => array(
			'why'  => 'Whether the site is fresh from the installation; the core sets it to 0 at the first change.',
			'core' => array( array( 'wp-admin/includes/upgrade.php', 87, "update_option( 'fresh_site', 1, false );" ), array( 'wp-includes/functions.php', 4811, "update_option( 'fresh_site', '0', false );" ) ),
			'plan' => 'Konzept 3',
		),
		'option:upload_path'                     => array(
			'why'  => 'Where the uploads of each site lie on its disk (B6): another value on the live site moves where WordPress looks for its media.',
			'core' => array( array( 'wp-admin/includes/schema.php', 471, "'upload_path'" ), array( 'wp-includes/functions.php', 2469, "\$upload_path = trim( get_option( 'upload_path' ) );" ) ),
			'plan' => 'Konzept 3; Entscheid 27',
		),
		'option:upload_url_path'                 => array(
			'why'  => 'The address of the uploads of each site (B6).',
			'core' => array( array( 'wp-admin/includes/schema.php', 484, "'upload_url_path'" ), array( 'wp-includes/functions.php', 2480, "\$url = get_option( 'upload_url_path' );" ) ),
			'plan' => 'Konzept 3; Entscheid 27',
		),
		'option:finished_splitting_shared_terms' => array(
			'why'  => 'Whether the core has split its shared terms on this site, written by the core itself.',
			'core' => array( array( 'wp-admin/includes/schema.php', 528, "'finished_splitting_shared_terms'" ), array( 'wp-includes/taxonomy.php', 4422, "update_option( 'finished_splitting_shared_terms', true );" ) ),
			'plan' => 'Entscheid 27',
		),
		'option:admin_email_lifespan'            => array(
			'why'  => 'When the core asks the administrator of this site again to confirm the address (B6).',
			'core' => array( array( 'wp-admin/includes/schema.php', 542, "'admin_email_lifespan'" ), array( 'wp-login.php', 623, "update_option( 'admin_email_lifespan', time() + \$remind_interval );" ) ),
			'plan' => 'Entscheid 27',
		),
		'option:auto_plugin_theme_update_emails' => array(
			'why'  => 'The mails about failed automatic updates this site sent, noted by the core.',
			'core' => array( array( 'wp-admin/includes/schema.php', 547, "'auto_plugin_theme_update_emails'" ), array( 'wp-admin/includes/class-plugin-upgrader.php', 445, "update_option( 'auto_plugin_theme_update_emails', \$past_failure_emails );" ) ),
			'plan' => 'Entscheid 27',
		),
		'option:wp_force_deactivated_plugins'    => array(
			'why'  => 'The plugins the core deactivated on this site during an update of the core.',
			'core' => array( array( 'wp-admin/includes/schema.php', 559, "'wp_force_deactivated_plugins'" ), array( 'wp-admin/includes/update-core.php', 2174, "update_option( 'wp_force_deactivated_plugins', \$deactivated_plugins, false );" ) ),
			'plan' => 'Entscheid 27',
		),
		'option_prefix:_transient_'              => array(
			'why'  => 'Transients are caches (B5).',
			'core' => array( array( 'wp-includes/option.php', 1545, "'_transient_' . \$transient;" ) ),
			'plan' => 'Konzept 3',
		),
		'option_prefix:_site_transient_'         => array(
			'why'  => 'Transients of the network or the single site, caches (B5).',
			'core' => array( array( 'wp-includes/option.php', 2675, "'_site_transient_' . \$transient;" ) ),
			'plan' => 'Konzept 3',
		),
		'option_prefix:ab_mcp_'                  => array(
			'why'  => 'The options of this plugin: connections, tokens, the undo index, the staging pair, these tables (B6).',
			'plugin' => array( array( 'includes/class-settings.php', 15, "const OPT_OPTIONS   = 'ab_mcp_options';" ), array( 'includes/sync/class-sync-schema.php', 42, "const OPTION = 'ab_mcp_staging_db_version';" ) ),
			'plan' => 'Konzept 3; Konzept 2 (B6)',
		),
		'option_suffix:.lock'                    => array(
			'why'  => 'WP_Upgrader::create_lock() keeps its locks as options <name>.lock (B5).',
			'core' => array( array( 'wp-admin/includes/class-wp-upgrader.php', 1062, "\$lock_option = \$lock_name . '.lock';" ) ),
			'plan' => 'Konzept 2 (B5)',
		),
		'post_meta:_edit_lock'                   => array(
			'why'  => 'Who has the post open in the editor of this site now.',
			'core' => array( array( 'wp-admin/includes/post.php', 1790, "update_post_meta( \$post->ID, '_edit_lock', \$lock );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_edit_lock'" ) ),
			'plan' => 'Konzept 3 (Postmeta, nie übernehmen)',
		),
		'post_meta:_edit_last'                   => array(
			'why'  => 'Who edited the post last on this site.',
			'core' => array( array( 'wp-admin/includes/post.php', 461, "update_post_meta( \$post_id, '_edit_last', get_current_user_id() );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_edit_last'" ) ),
			'plan' => 'Konzept 3',
		),
		'post_meta:_wp_old_slug'                 => array(
			'why'  => 'The former slugs the core keeps when a published post gets a new one (after post_updated).',
			'core' => array( array( 'wp-includes/post.php', 7592, "add_post_meta( \$post_id, '_wp_old_slug', \$post_before->post_name );" ), array( 'wp-includes/default-filters.php', 485, "add_action( 'post_updated', 'wp_check_for_changed_slugs', 12, 3 );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_wp_old_slug'" ) ),
			'plan' => 'Entscheid 11 und 21',
		),
		'post_meta:_wp_old_date'                 => array(
			'why'  => 'The former dates the core keeps when a published post gets a new one.',
			'core' => array( array( 'wp-includes/post.php', 7640, "add_post_meta( \$post_id, '_wp_old_date', \$previous_date );" ), array( 'wp-includes/default-filters.php', 489, "add_action( 'post_updated', 'wp_check_for_changed_dates', 12, 3 );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_wp_old_date'" ) ),
			'plan' => 'Entscheid 11 und 21',
		),
		'post_meta:_wp_desired_post_slug'        => array(
			'why'  => 'The slug a post had before the trash, kept by the core to give it back.',
			'core' => array( array( 'wp-includes/post.php', 8629, "add_post_meta( \$post->ID, '_wp_desired_post_slug', \$post->post_name );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_wp_desired_post_slug'" ) ),
			'plan' => 'Konzept 3',
		),
		'post_meta:_encloseme'                   => array(
			'why'  => 'An enclosure job of the core not yet run, set when a post is published.',
			'core' => array( array( 'wp-includes/post.php', 8247, "add_post_meta( \$post_id, '_encloseme', '1', true );" ), array( 'wp-includes/default-filters.php', 447, "add_action( 'publish_post', '_publish_post_hook', 5, 1 );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_encloseme'" ) ),
			'plan' => 'Konzept 3',
		),
		'post_meta:_pingme'                      => array(
			'why'  => 'A pingback job of the core not yet run, set when a post is published and pingbacks are on.',
			'core' => array( array( 'wp-includes/post.php', 8245, "add_post_meta( \$post_id, '_pingme', '1', true );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 48, "'_pingme'" ) ),
			'plan' => 'Konzept 3',
		),
		'post_meta:_trackbackme'                 => array(
			'why'  => 'A trackback job of the core not yet run, set when a post with addresses to ping is published.',
			'core' => array( array( 'wp-includes/post.php', 8251, "add_post_meta( \$post_id, '_trackbackme', '1' );" ), array( 'wp-includes/comment.php', 3338, "delete_post_meta( \$trackback, '_trackbackme' );" ) ),
			'plan' => 'Entscheid 11 (Folgedaten der Kern-Haken)',
		),
		'post_meta_prefix:_wp_trash_meta_'       => array(
			'why'  => 'What the trash keeps to restore a post: its status, the time, the status of its comments.',
			'core' => array( array( 'wp-includes/post.php', 4128, "add_post_meta( \$post_id, '_wp_trash_meta_status', \$previous_status );" ), array( 'wp-includes/post.php', 4129, "add_post_meta( \$post_id, '_wp_trash_meta_time', time() );" ), array( 'wp-includes/post.php', 4299, "add_post_meta( \$post_id, '_wp_trash_meta_comments_status', \$statuses );" ) ),
			'plugin' => array( array( 'includes/tools/class-duplicate-meta.php', 49, "'_wp_trash_meta_'" ) ),
			'plan' => 'Konzept 3',
		),
		'post_meta_prefix:_oembed_'              => array(
			'why'  => 'The oEmbed answers the core caches on the post that embeds them, with the time (B5).',
			'core' => array( array( 'wp-includes/class-wp-embed.php', 236, "\$cachekey      = '_oembed_' . \$key_suffix;" ), array( 'wp-includes/class-wp-embed.php', 237, "\$cachekey_time = '_oembed_time_' . \$key_suffix;" ), array( 'wp-includes/class-wp-embed.php', 312, 'update_post_meta( $post_id, $cachekey, $html );' ) ),
			'plan' => 'Konzept 2 (B5 Caches)',
		),
		'post_meta_prefix:_ab_mcp_'              => array(
			'why'  => 'The records of this plugin on a post, such as the SHA-256 it keeps of a file it uploaded (B6).',
			'plugin' => array( array( 'includes/tools/class-tools-media.php', 24, "const HASH_META = '_ab_mcp_sha256';" ) ),
			'plan' => 'Konzept 3 (ab_mcp_*)',
		),
		'column:posts.ID'                        => array(
			'why'  => 'Each site counts its own ids (two ID spaces): an id is never an identity across the sites.',
			'core' => array( array( 'wp-admin/includes/schema.php', 160, 'ID bigint(20) unsigned NOT NULL auto_increment,' ) ),
			'plan' => 'Entscheid 11; Erhebung 4',
		),
		'column:posts.guid'                      => array(
			'why'  => 'The core writes the permalink of the site at the insert into the guid and never again.',
			'core' => array( array( 'wp-includes/post.php', 5121, "array( 'guid' => get_permalink( \$post_id ) )" ) ),
			'plan' => 'Entscheid 11',
		),
		'column:posts.post_modified'             => array(
			'why'  => 'The core sets the time of the change on every update.',
			'core' => array( array( 'wp-includes/post.php', 4790, "\$post_modified     = current_time( 'mysql' );" ) ),
			'plan' => 'Entscheid 11',
		),
		'column:posts.post_modified_gmt'         => array(
			'why'  => 'The same in UTC.',
			'core' => array( array( 'wp-includes/post.php', 4791, "\$post_modified_gmt = current_time( 'mysql', true );" ) ),
			'plan' => 'Entscheid 11',
		),
		'column:posts.comment_count'             => array(
			'why'  => 'The counter of the comments (B1) of this site.',
			'core' => array( array( 'wp-includes/comment.php', 3143, "array( 'comment_count' => \$new )" ) ),
			'plan' => 'Entscheid 11 (Zähler)',
		),
		'column:posts.to_ping'                   => array(
			'why'  => 'The trackbacks still to send from this site.',
			'core' => array( array( 'wp-includes/comment.php', 3367, "array( 'to_ping' => '' )" ) ),
			'plan' => 'Entscheid 11',
		),
		'column:posts.pinged'                    => array(
			'why'  => 'The addresses this site has pinged.',
			'core' => array( array( 'wp-includes/post.php', 6069, "array( 'pinged' => \$new )" ) ),
			'plan' => 'Entscheid 11',
		),
		'column:term_taxonomy.count'             => array(
			'why'  => 'The core counts the objects of a term again on every status change of a post.',
			'core' => array( array( 'wp-includes/taxonomy.php', 4253, "compact( 'count' )" ), array( 'wp-includes/default-filters.php', 449, "'_update_term_count_on_transition_post_status'" ) ),
			'plan' => 'Entscheid 21 (Termzähler)',
		),
	),
);
