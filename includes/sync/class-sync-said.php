<?php
/**
 * The English texts of the report and of the transport, said to a person
 * in the language of the site (branch 13 text/z8, Codex round 2, B1).
 *
 * A reason of a lock, a reason a report entry waits for, a reason of the
 * proposal of the pairs and a message of AB_MCP_Outbound stay English where
 * they are stored, compared across both sites (AB_MCP_Sync_Import), hashed
 * (the parts of the report, report_hash, the hashes of the proposal) or
 * compared with a constant (AB_MCP_Outbound::UNBOUND). Only where such a
 * text is set into a refusal, an error or a note for a person does text()
 * give it in the language of the site: by the English text itself, which
 * is the stable key, to a literal gettext call. A text this list does not
 * know is given as it is.
 *
 * The list holds every reason of the tables, those of the environment
 * (AB_MCP_Sync_Env::LOCKS) among them (SyncSaidTest holds it to them).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Said
 */
final class AB_MCP_Sync_Said {

	/**
	 * An English text of the report or the transport in the language of the
	 * site; a text the list does not know as it is.
	 *
	 * @param string $english The text as it is stored.
	 * @return string
	 */
	public static function text( $english ) {
		$english = (string) $english;
		$words   = self::words();
		if ( isset( $words[ $english ] ) ) {
			return $words[ $english ];
		}
		foreach ( self::forms() as $form => $said ) {
			$args = self::args( $form, $english );
			if ( null !== $args ) {
				return vsprintf( $said, $args );
			}
		}
		return $english;
	}

	/**
	 * A part of an answer of wp_site_diff or wp_site_pairs as the person reads
	 * it: every reason and why in it in the language of the site (text()),
	 * everything else as it is. Only the copy that goes into the answer:
	 * the stored report and proposal, their parts and their hashes are not
	 * touched, and no answer is read back.
	 *
	 * @param mixed $data A value of the answer.
	 * @return mixed
	 */
	public static function shown( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( ( 'reason' === $key || 'why' === $key ) && is_string( $value ) ) {
				$data[ $key ] = self::text( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::shown( $value );
			}
		}
		return $data;
	}

	/**
	 * Whether the list knows a text (whole, or by one of its forms).
	 *
	 * @param string $english The text.
	 * @return bool
	 */
	public static function knows( $english ) {
		$english = (string) $english;
		if ( isset( self::words()[ $english ] ) ) {
			return true;
		}
		foreach ( array_keys( self::forms() ) as $form ) {
			if ( $form === $english || null !== self::args( $form, $english ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The word of a kind of an object the takeover writes, as the page
	 * «Staging» shows it (AB_MCP_Transfer_View::kind()); an unknown kind as
	 * it is.
	 *
	 * @param string $kind The kind.
	 * @return string
	 */
	public static function kind( $kind ) {
		$words = array(
			'post'        => _x( 'post', 'kind of an object', 'alphabridge-mcp' ),
			'term'        => _x( 'term', 'kind of an object', 'alphabridge-mcp' ),
			'attachment'  => _x( 'attachment', 'kind of an object', 'alphabridge-mcp' ),
			'menu'        => _x( 'menu', 'kind of an object', 'alphabridge-mcp' ),
			'menu_item'   => _x( 'menu item', 'kind of an object', 'alphabridge-mcp' ),
			'widget_area' => _x( 'widget area', 'kind of an object', 'alphabridge-mcp' ),
			'custom_css'  => _x( 'additional CSS', 'kind of an object', 'alphabridge-mcp' ),
			'option'      => _x( 'option', 'kind of an object', 'alphabridge-mcp' ),
		);
		return $words[ (string) $kind ] ?? (string) $kind;
	}

	/**
	 * The values of a form in a text, by the number of each placeholder; null
	 * where the text is not of the form.
	 *
	 * @param string $form The English form (%s, %d, %1$s, …).
	 * @param string $text The text.
	 * @return array|null
	 */
	private static function args( $form, $text ) {
		$pieces  = preg_split( '/(%(?:[0-9]+\$)?[sd])/', $form, -1, PREG_SPLIT_DELIM_CAPTURE );
		$pattern = '';
		$order   = array();
		$next    = 1;
		foreach ( $pieces as $i => $piece ) {
			if ( 0 === $i % 2 ) {
				$pattern .= preg_quote( $piece, '/' );
				continue;
			}
			$number = preg_match( '/^%([0-9]+)\$/', $piece, $m ) ? (int) $m[1] : $next++;
			$digit  = 'd' === substr( $piece, -1 );
			if ( isset( $order[ $number ] ) ) {
				$pattern .= '(?P=p' . $number . ')';
				continue;
			}
			$order[ $number ] = $digit;
			$pattern         .= '(?P<p' . $number . '>' . ( $digit ? '-?[0-9]+' : '.+?' ) . ')';
		}
		if ( 1 !== preg_match( '/\A' . $pattern . '\z/s', $text, $found ) ) {
			return null;
		}
		ksort( $order );
		$args = array();
		foreach ( $order as $number => $digit ) {
			$args[] = $digit ? (int) $found[ 'p' . $number ] : $found[ 'p' . $number ];
		}
		return $args;
	}

	/**
	 * The texts without a value in them: English => in the language of the site.
	 *
	 * @return array<string,string>
	 */
	private static function words() {
		return array(
			// The reasons of the tables of AB_MCP_Sync_Kinds (OWNERSHIP, LOCKS) and of what is never taken over.
			'A Customizer changeset runs its settings when it is published, as stored users or with the capability exist; it needs a schema of its own.' => __( 'A Customizer changeset runs its settings when it is published, as stored users or with the capability exist; it needs a schema of its own.', 'alphabridge-mcp' ),
			'A font of the font library comes with its files under uploads/fonts, which are not attachments.' => __( 'A font of the font library comes with its files under uploads/fonts, which are not attachments.', 'alphabridge-mcp' ),
			'A post type of a plugin or theme, not classified yet.' => __( 'A post type of a plugin or theme, not classified yet.', 'alphabridge-mcp' ),
			'The categories of the link manager, whose table links has no engine.' => __( 'The categories of the link manager, whose table links has no engine.', 'alphabridge-mcp' ),
			'A taxonomy of a plugin or theme, not classified yet.' => __( 'A taxonomy of a plugin or theme, not classified yet.', 'alphabridge-mcp' ),
			'Roles follow with the rule for the registration as a class of the core (decision 29).' => __( 'Roles follow with the rule for the registration as a class of the core (decision 29).', 'alphabridge-mcp' ),
			'The registration pair follows with the rule for the registration as a class of the core (decision 29).' => __( 'The registration pair follows with the rule for the registration as a class of the core (decision 29).', 'alphabridge-mcp' ),
			'Which plugins run is the code of the site.' => __( 'Which plugins run is the code of the site.', 'alphabridge-mcp' ),
			'A change of the theme is a phase of the code.' => __( 'A change of the theme is a phase of the code.', 'alphabridge-mcp' ),
			'Whether a menu adds new top-level pages by itself is a field of the menu in a row of all menus; Z8 reads it (decision 21) and does not take it over.' => __( 'Whether a menu adds new top-level pages by itself is a field of the menu in a row of all menus; Z8 reads it (decision 21) and does not take it over.', 'alphabridge-mcp' ),
			'set_theme_mod() skips the sanitizers and capabilities of the Customizer settings; theme mods follow through those.' => __( 'set_theme_mod() skips the sanitizers and capabilities of the Customizer settings; theme mods follow through those.', 'alphabridge-mcp' ),
			'Files outside the file lists of attachments: counted, their contents not compared.' => __( 'Files outside the file lists of attachments: counted, their contents not compared.', 'alphabridge-mcp' ),
			'No object is deleted in Z8, and no status stands in for a deletion: an object deleted on staging is shown as such.' => __( 'No object is deleted in Z8, and no status stands in for a deletion: an object deleted on staging is shown as such.', 'alphabridge-mcp' ),
			'Bringing back an object deleted on one site, from the other site or from a backup, follows with the deletions and their way back.' => __( 'Bringing back an object deleted on one site, from the other site or from a backup, follows with the deletions and their way back.', 'alphabridge-mcp' ),
			'Removing a menu item is a deletion with the consequences of wp_delete_post(); a menu with an item removed on staging is locked as a whole.' => __( 'Removing a menu item is a deletion with the consequences of wp_delete_post(); a menu with an item removed on staging is locked as a whole.', 'alphabridge-mcp' ),
			'A widget area with an instance removed on staging is locked as a whole: writing the rest would not bring it to the staging state.' => __( 'A widget area with an instance removed on staging is locked as a whole: writing the rest would not bring it to the staging state.', 'alphabridge-mcp' ),
			'Creating Additional CSS sets custom_css_post_id as well, and no way back through the trash undoes it.' => __( 'Creating Additional CSS sets custom_css_post_id as well, and no way back through the trash undoes it.', 'alphabridge-mcp' ),
			'The right to the meta of a new term can only be asked once the term exists, and a term made in a run is not taken back.' => __( 'The right to the meta of a new term can only be asked once the term exists, and a term made in a run is not taken back.', 'alphabridge-mcp' ),
			'The right to a meta key of a new post that only the object answers can only be asked once the post exists.' => __( 'The right to a meta key of a new post that only the object answers can only be asked once the post exists.', 'alphabridge-mcp' ),
			'A meta key map_meta_cap refuses and that differs between the sides waits for its field rule.' => __( 'A meta key map_meta_cap refuses and that differs between the sides waits for its field rule.', 'alphabridge-mcp' ),
			'A document of a page builder other than the six released block libraries.' => __( 'A document of a page builder other than the six released block libraries.', 'alphabridge-mcp' ),
			'A plugin registers a reader for a page builder that has no signature here; which posts it built only that reader\'s code tells, so no post is taken over while it is registered.' => __( 'A plugin registers a reader for a page builder that has no signature here; which posts it built only that reader\'s code tells, so no post is taken over while it is registered.', 'alphabridge-mcp' ),
			'A different file at the path on the live site would be replaced.' => __( 'A different file at the path on the live site would be replaced.', 'alphabridge-mcp' ),
			'Another attachment of the live site uses a file at this path.' => __( 'Another attachment of the live site uses a file at this path.', 'alphabridge-mcp' ),
			'A file of the attachment is of a type this account may not upload, or its content is not what its name says (WordPress checks a file by its content as it uploads it, wp_check_filetype_and_ext()): upload it by hand in the media library of this site, or take it over with an account that may upload files of that type.' => __( 'A file of the attachment is of a type this account may not upload, or its content is not what its name says (WordPress checks a file by its content as it uploads it, wp_check_filetype_and_ext()): upload it by hand in the media library of this site, or take it over with an account that may upload files of that type.', 'alphabridge-mcp' ),
			'The files of the attachment this site does not have yet are larger than one package of media carries (48 MiB): a person resolves it, by uploading the file by hand in the media library of this site.' => __( 'The files of the attachment this site does not have yet are larger than one package of media carries (48 MiB): a person resolves it, by uploading the file by hand in the media library of this site.', 'alphabridge-mcp' ),
			'An option of a plugin or theme, or one without a schema, not classified yet.' => __( 'An option of a plugin or theme, or one without a schema, not classified yet.', 'alphabridge-mcp' ),
			'Objects that need each other cannot be made visible together in WordPress; how often that happens is measured first.' => __( 'Objects that need each other cannot be made visible together in WordPress; how often that happens is measured first.', 'alphabridge-mcp' ),
			'A stored value holds a PHP object, a reference or a record that is read only with the code of its class; it is neither compared nor taken over without that class.' => __( 'A stored value holds a PHP object, a reference or a record that is read only with the code of its class; it is neither compared nor taken over without that class.', 'alphabridge-mcp' ),
			'A stored value looks serialized but does not unpack, or nests deeper than it is read: nothing says what it holds.' => __( 'A stored value looks serialized but does not unpack, or nests deeper than it is read: nothing says what it holds.', 'alphabridge-mcp' ),
			'A whole number without a known schema equals the staging id of an object in the scope whose live id differs: it may point to that object, and no rule says whether to rewrite it.' => __( 'A whole number without a known schema equals the staging id of an object in the scope whose live id differs: it may point to that object, and no rule says whether to rewrite it.', 'alphabridge-mcp' ),
			'A reference is stored in a spelling the rewriter does not write back as WordPress reads it (backslash escapes, character references, other quotes, the attribute twice, an image id outside the class): it is not rewritten.' => __( 'A reference is stored in a spelling the rewriter does not write back as WordPress reads it (backslash escapes, character references, other quotes, the attribute twice, an image id outside the class): it is not rewritten.', 'alphabridge-mcp' ),
			'A field inside the value has the name of a credential: the value is mixed, and no field rule says which part may go.' => __( 'A field inside the value has the name of a credential: the value is mixed, and no field rule says which part may go.', 'alphabridge-mcp' ),
			'The post is protected by a password (post_password): the password is a credential, so the post is taken over by hand only.' => __( 'The post is protected by a password (post_password): the password is a credential, so the post is taken over by hand only.', 'alphabridge-mcp' ),
			'The value holds HTML outside the list of tags and attributes WordPress lets through in posts (kses), such as srcdoc, SVG, embed or base: what it names is not read, so it is taken over by hand only.' => __( 'The value holds HTML outside the list of tags and attributes WordPress lets through in posts (kses), such as srcdoc, SVG, embed or base: what it names is not read, so it is taken over by hand only.', 'alphabridge-mcp' ),
			'The value holds a script of JavaScript, which no parser of WordPress reads: what it names (ids, addresses, credentials) is not known, so it is taken over by hand only.' => __( 'The value holds a script of JavaScript, which no parser of WordPress reads: what it names (ids, addresses, credentials) is not known, so it is taken over by hand only.', 'alphabridge-mcp' ),
			'A layer of the stored value (its text decoded, a data: address, base64, a string of CSS, JSON a plugin reads) holds an address of the sites that names an object by its id where no reader of WordPress read it: it would be taken over unchanged and name another object on the live site, so the object is taken over by hand only.' => __( 'A layer of the stored value (its text decoded, a data: address, base64, a string of CSS, JSON a plugin reads) holds an address of the sites that names an object by its id where no reader of WordPress read it: it would be taken over unchanged and name another object on the live site, so the object is taken over by hand only.', 'alphabridge-mcp' ),
			'A layer of the stored value (its text decoded, a data: address, base64, an image, a script of an image, a dropped value of JSON) holds the name of a credential with a value beside it that the credential check did not name: nothing of it is compared or packed, the object is taken over by hand only.' => __( 'A layer of the stored value (its text decoded, a data: address, base64, an image, a script of an image, a dropped value of JSON) holds the name of a credential with a value beside it that the credential check did not name: nothing of it is compared or packed, the object is taken over by hand only.', 'alphabridge-mcp' ),
			'A file of the attachment\'s file list is not there.' => __( 'A file of the attachment\'s file list is not there.', 'alphabridge-mcp' ),
			'Code decides where the uploads lie (a filter on upload_dir or on the option upload_path, as offload plugins add, or a network of sites): the files are neither compared nor taken over.' => __( 'Code decides where the uploads lie (a filter on upload_dir or on the option upload_path, as offload plugins add, or a network of sites): the files are neither compared nor taken over.', 'alphabridge-mcp' ),
			'A path of the attachment\'s file list is not a plain relative path under the uploads, or a link or no regular file lies on the way.' => __( 'A path of the attachment\'s file list is not a plain relative path under the uploads, or a link or no regular file lies on the way.', 'alphabridge-mcp' ),
			'The stored value of a core option does not keep the rule of its schema.' => __( 'The stored value of a core option does not keep the rule of its schema.', 'alphabridge-mcp' ),
			'A post type of another kind (an attachment, a menu item, the additional CSS) is written by the engine of its own kind, never by the engine of the kind post: plan it again from a fresh report.' => __( 'A post type of another kind (an attachment, a menu item, the additional CSS) is written by the engine of its own kind, never by the engine of the kind post: plan it again from a fresh report.', 'alphabridge-mcp' ),
			'The staging object lies in the trash, a deletion on the staging site; no status stands in for a deletion, and deletions follow with their way back (as *:delete).' => __( 'The staging object lies in the trash, a deletion on the staging site; no status stands in for a deletion, and deletions follow with their way back (as *:delete).', 'alphabridge-mcp' ),
			'An auto-draft (WordPress made it for the editor, nobody saved it, WordPress removes it after seven days) has no content: it is never taken over, by no stage.' => __( 'An auto-draft (WordPress made it for the editor, nobody saved it, WordPress removes it after seven days) has no content: it is never taken over, by no stage.', 'alphabridge-mcp' ),
			'A status the engine of the kind post does not write (a status of a plugin, or one WordPress gives no post or page) waits for its field rule; the engine writes publish, future, draft, pending and private.' => __( 'A status the engine of the kind post does not write (a status of a plugin, or one WordPress gives no post or page) waits for its field rule; the engine writes publish, future, draft, pending and private.', 'alphabridge-mcp' ),
			'The package carries no whole content of the object (a row of it could not be read on the staging site), so nothing of it is written: make the inventory and the package again on the staging site.' => __( 'The package carries no whole content of the object (a row of it could not be read on the staging site), so nothing of it is written: make the inventory and the package again on the staging site.', 'alphabridge-mcp' ),
			'The canonical form of the object carries a lock this version does not read (the other site runs another version of AlphaBridge), so nothing of it is written: bring both sites to the same version.' => __( 'The canonical form of the object carries a lock this version does not read (the other site runs another version of AlphaBridge), so nothing of it is written: bring both sites to the same version.', 'alphabridge-mcp' ),
			'The post type of the object is not registered on this site (the plugin or theme that registers it is not active here): activate it here, or take the object over by hand.' => __( 'The post type of the object is not registered on this site (the plugin or theme that registers it is not active here): activate it here, or take the object over by hand.', 'alphabridge-mcp' ),
			'A meta value the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.' => __( 'A meta value the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.', 'alphabridge-mcp' ),
			'A term of a taxonomy of a plugin or theme on the post waits for the classification of that taxonomy; the engine of the kind post sets category, post_tag, post_format, wp_pattern_category, wp_theme and wp_template_part_area. A term of wp_theme or wp_template_part_area whose name is not its slug waits for its field rule as well: WordPress reads the theme and the area by the name, the engine writes them by the slug.' => __( 'A term of a taxonomy of a plugin or theme on the post waits for the classification of that taxonomy; the engine of the kind post sets category, post_tag, post_format, wp_pattern_category, wp_theme and wp_template_part_area. A term of wp_theme or wp_template_part_area whose name is not its slug waits for its field rule as well: WordPress reads the theme and the area by the name, the engine writes them by the slug.', 'alphabridge-mcp' ),
			'Two meta keys of the post that the database compares alike (Foo and foo under the collation of postmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.' => __( 'Two meta keys of the post that the database compares alike (Foo and foo under the collation of postmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.', 'alphabridge-mcp' ),
			'A relationship with a term_order other than 0 (a plugin that sorts the terms), on the staging post or on the post of this site, waits for its field rule: wp_set_object_terms() makes every relationship anew with the order 0, so a step could not write the order of the staging site, and the way back could not give the order of this site back.' => __( 'A relationship with a term_order other than 0 (a plugin that sorts the terms), on the staging post or on the post of this site, waits for its field rule: wp_set_object_terms() makes every relationship anew with the order 0, so a step could not write the order of the staging site, and the way back could not give the order of this site back.', 'alphabridge-mcp' ),
			'WordPress would store another slug than the staging object has (another post of its type and parent holds it, a post in the trash holds it and would be renamed, a pending post of an account that may not publish it loses it, a published one without a slug gets one from its title): change the slug on the staging site, or pair the two objects first (wp_site_pairs).' => __( 'WordPress would store another slug than the staging object has (another post of its type and parent holds it, a post in the trash holds it and would be renamed, a pending post of an account that may not publish it loses it, a published one without a slug gets one from its title): change the slug on the staging site, or pair the two objects first (wp_site_pairs).', 'alphabridge-mcp' ),
			'A field or meta value of the object changes in the sanitizing WordPress runs on a save (kses without unfiltered_html, sanitize_title(), sanitize_meta()), so the written object would not be the target: take it over with an account that may store unfiltered HTML, or by hand.' => __( 'A field or meta value of the object changes in the sanitizing WordPress runs on a save (kses without unfiltered_html, sanitize_title(), sanitize_meta()), so the written object would not be the target: take it over with an account that may store unfiltered HTML, or by hand.', 'alphabridge-mcp' ),
			'The featured image names an attachment of this site that WordPress shows as no image, and set_post_thumbnail() would remove the featured image instead of setting it: check the attachment in the media library of this site.' => __( 'The featured image names an attachment of this site that WordPress shows as no image, and set_post_thumbnail() would remove the featured image instead of setting it: check the attachment in the media library of this site.', 'alphabridge-mcp' ),
			'A page of the top level is published or scheduled only while no menu of this site adds pages by itself (the automatic would add it, and the cron would publish a scheduled one); the confirmed choice is to take it over as draft, or the automatic is switched off in the menu.' => __( 'A page of the top level is published or scheduled only while no menu of this site adds pages by itself (the automatic would add it, and the cron would publish a scheduled one); the confirmed choice is to take it over as draft, or the automatic is switched off in the menu.', 'alphabridge-mcp' ),
			'The author of the staging object has no account of that login on this site; make the account, or take the object over by hand.' => __( 'The author of the staging object has no account of that login on this site; make the account, or take the object over by hand.', 'alphabridge-mcp' ),
			'WordPress would store another status than the staging site has (a scheduled date that passed, or one too close): set the date anew on the staging site.' => __( 'WordPress would store another status than the staging site has (a scheduled date that passed, or one too close): set the date anew on the staging site.', 'alphabridge-mcp' ),
			'A reference of the object (its parent, a term, an id in its content, a template part it names by theme and slug) has no counterpart on this site: pair or create the target first.' => __( 'A reference of the object (its parent, a term, an id in its content, a template part it names by theme and slug) has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'A template, a template part or the global styles belong to a theme (the term of wp_theme) that is not the active theme of this site by that very name, or to none: WordPress finds them by the name of the active theme only, and a theme is never rewritten into another. Activate the same theme on both sites (a change of the theme is a phase of the code, Z8b), or take the object over by hand in the site editor.' => __( 'A template, a template part or the global styles belong to a theme (the term of wp_theme) that is not the active theme of this site by that very name, or to none: WordPress finds them by the name of the active theme only, and a theme is never rewritten into another. Activate the same theme on both sites (a change of the theme is a phase of the code, Z8b), or take the object over by hand in the site editor.', 'alphabridge-mcp' ),
			'The navigation would change which navigation WordPress shows in a navigation block without a menu of its own (the most recently published one: a new one published after it, a date moved past it, a status that adds or takes away the latest), and a template or a post of this site shows such a block after the run. Give that block its menu (in the site editor, on the staging site as well), or make or publish the navigation by hand in the site editor of this site, where the change shows, or leave it out of the plan.' => __( 'The navigation would change which navigation WordPress shows in a navigation block without a menu of its own (the most recently published one: a new one published after it, a date moved past it, a status that adds or takes away the latest), and a template or a post of this site shows such a block after the run. Give that block its menu (in the site editor, on the staging site as well), or make or publish the navigation by hand in the site editor of this site, where the change shows, or leave it out of the plan.', 'alphabridge-mcp' ),
			'The custom CSS of the global styles holds a closing tag of STYLE (</style followed by a space, a slash or >), ends in a part of one, or is no text, which the site editor refuses to save, or the global styles are no JSON the check of their CSS reads (WordPress shows nothing of them then): change the styles on the staging site, where the site editor saves them anew as JSON.'
				// Said without the sign >: a text with </style … > reads as an HTML tag
				// the translation would have to repeat (BundledTranslationsTest).
				=> __( 'The custom CSS of the global styles holds a closing tag of STYLE (</style followed by a space, a slash or a closing angle bracket), ends in a part of one, or is no text, which the site editor refuses to save, or the global styles are no JSON the check of their CSS reads (WordPress shows nothing of them then): change the styles on the staging site, where the site editor saves them anew as JSON.', 'alphabridge-mcp' ),
			'The template of the staging object (_wp_page_template) is not one the theme of this site offers for its type, so WordPress would store default instead: add the template to the theme of this site, or take the object over by hand.' => __( 'The template of the staging object (_wp_page_template) is not one the theme of this site offers for its type, so WordPress would store default instead: add the template to the theme of this site, or take the object over by hand.', 'alphabridge-mcp' ),
			'The engine of the kind term writes the terms of category, post_tag and wp_pattern_category, registered on this site, from a package with their content; a term of another taxonomy waits for its classification.' => __( 'The engine of the kind term writes the terms of category, post_tag and wp_pattern_category, registered on this site, from a package with their content; a term of another taxonomy waits for its classification.', 'alphabridge-mcp' ),
			'A term in a group of aliases (term_group other than 0) waits for its field rule: the group is an id of the site\'s own, which no pair maps.' => __( 'A term in a group of aliases (term_group other than 0) waits for its field rule: the group is an id of the site\'s own, which no pair maps.', 'alphabridge-mcp' ),
			'The parent would close a loop of parents on this site (wp_update_term() would break it by changing the parent of other terms), or the taxonomy has no parents here: set the parents by hand in wp-admin.' => __( 'The parent would close a loop of parents on this site (wp_update_term() would break it by changing the parent of other terms), or the taxonomy has no parents here: set the parents by hand in wp-admin.', 'alphabridge-mcp' ),
			'Another term of the taxonomy holds the slug on this site, or WordPress would store another one: change the slug on the staging site, or pair the two terms first (wp_site_pairs).' => __( 'Another term of the taxonomy holds the slug on this site, or WordPress would store another one: change the slug on the staging site, or pair the two terms first (wp_site_pairs).', 'alphabridge-mcp' ),
			'A field or meta value of the term changes in the sanitizing WordPress runs on a save (the filters of term names and descriptions, kses without unfiltered_html, sanitize_meta()), or the slug is empty: take it over with an account that may store unfiltered HTML, or by hand.' => __( 'A field or meta value of the term changes in the sanitizing WordPress runs on a save (the filters of term names and descriptions, kses without unfiltered_html, sanitize_meta()), or the slug is empty: take it over with an account that may store unfiltered HTML, or by hand.', 'alphabridge-mcp' ),
			'A reference of the term (its parent, an address in its name, description or meta) has no counterpart on this site: pair or create the target first.' => __( 'A reference of the term (its parent, an address in its name, description or meta) has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'A meta value of the term the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.' => __( 'A meta value of the term the metadata API cannot store byte for byte (a serialized value that is no array, or one serialize() would not give back as it is) waits for its field rule.', 'alphabridge-mcp' ),
			'Two meta keys of the term that the database compares alike (Foo and foo under the collation of termmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.' => __( 'Two meta keys of the term that the database compares alike (Foo and foo under the collation of termmeta) wait for their field rule: update_metadata() and delete_metadata() write by the key and would change both.', 'alphabridge-mcp' ),
			'Two or more menus of the plan take each other\'s position (an exchange): the step of each would change another of them, whose step would then find it changed since the plan and leave a position empty, whatever their order. Plan one of them in a run of its own first and the others from a new report after it, or set the positions by hand under Appearance → Menus → Manage Locations.' => __( 'Two or more menus of the plan take each other\'s position (an exchange): the step of each would change another of them, whose step would then find it changed since the plan and leave a position empty, whatever their order. Plan one of them in a run of its own first and the others from a new report after it, or set the positions by hand under Appearance → Menus → Manage Locations.', 'alphabridge-mcp' ),
			'The menu is at a position the active theme of this site does not register: a position is written only where the theme registers it (as the menu screen offers them). Activate a theme that registers it, or set the position by hand under Appearance → Menus → Manage Locations.' => __( 'The menu is at a position the active theme of this site does not register: a position is written only where the theme registers it (as the menu screen offers them). Activate a theme that registers it, or set the position by hand under Appearance → Menus → Manage Locations.', 'alphabridge-mcp' ),
			'WordPress would store another value for a field of the menu item than the staging site has (wp_update_nav_menu_item() with sanitize_key(), sanitize_html_class(), sanitize_url(), a title it stores as none where it is the title of the object, kses without unfiltered_html): change the item on the staging site, take it over with an account that may store unfiltered HTML, or by hand under Appearance → Menus.' => __( 'WordPress would store another value for a field of the menu item than the staging site has (wp_update_nav_menu_item() with sanitize_key(), sanitize_html_class(), sanitize_url(), a title it stores as none where it is the title of the object, kses without unfiltered_html): change the item on the staging site, take it over with an account that may store unfiltered HTML, or by hand under Appearance → Menus.', 'alphabridge-mcp' ),
			'The menu item is of a shape the core does not write through the menu screen (another status than publish or draft, no position, in no menu or in several, a type the core does not know, a meta field of the core twice or missing): it waits for its field rule.' => __( 'The menu item is of a shape the core does not write through the menu screen (another status than publish or draft, no position, in no menu or in several, a type the core does not know, a meta field of the core twice or missing): it waits for its field rule.', 'alphabridge-mcp' ),
			'A reference of the menu or of its item (its menu, its parent item, the post or term it links, an address with an id) has no counterpart on this site: pair or create the target first.' => __( 'A reference of the menu or of its item (its menu, its parent item, the post or term it links, an address with an id) has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'The widget area is not on this site (no key of sidebars_widgets): it is not made by a run. Open Appearance → Widgets on this site once (the core adds the areas of the theme), then make a new report.' => __( 'The widget area is not on this site (no key of sidebars_widgets): it is not made by a run. Open Appearance → Widgets on this site once (the core adds the areas of the theme), then make a new report.', 'alphabridge-mcp' ),
			'A widget of the area is of a type that is not registered on this site (its update() cannot check its settings), is no widget with a number, or its row (widget_<base>) was not written by the widgets of WordPress: activate the plugin or theme that provides the widget, or take the area over by hand under Appearance → Widgets.' => __( 'A widget of the area is of a type that is not registered on this site (its update() cannot check its settings), is no widget with a number, or its row (widget_<base>) was not written by the widgets of WordPress: activate the plugin or theme that provides the widget, or take the area over by hand under Appearance → Widgets.', 'alphabridge-mcp' ),
			'The update() of a widget or the filter widget_update_callback would store other settings than the staging site has (kses without unfiltered_html, a field the widget sanitizes otherwise here): take it over with an account that may store unfiltered HTML, or by hand under Appearance → Widgets.' => __( 'The update() of a widget or the filter widget_update_callback would store other settings than the staging site has (kses without unfiltered_html, a field the widget sanitizes otherwise here): take it over with an account that may store unfiltered HTML, or by hand under Appearance → Widgets.', 'alphabridge-mcp' ),
			'An instance of the area was there at the base of the pair, and its counterpart on this site is not known by the pair (the lists of both sides did not name the same widgets in the same order when the area was paired) or is placed in another area here: align the pair anew (wp_site_pairs), or take the area over by hand under Appearance → Widgets.' => __( 'An instance of the area was there at the base of the pair, and its counterpart on this site is not known by the pair (the lists of both sides did not name the same widgets in the same order when the area was paired) or is placed in another area here: align the pair anew (wp_site_pairs), or take the area over by hand under Appearance → Widgets.', 'alphabridge-mcp' ),
			'A reference in the settings of a widget (a post, a term, a menu, an address with an id) has no counterpart on this site: pair or create the target first.' => __( 'A reference in the settings of a widget (a post, a term, a menu, an address with an id) has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'The Additional CSS belongs to a theme that is not the active theme of this site, or is active here in another version than on the staging site: the CSS is written only to the same theme in the same version (a change of the theme is a phase of the code). Bring both sites to the same theme and version, or change the CSS by hand in the Customizer.' => __( 'The Additional CSS belongs to a theme that is not the active theme of this site, or is active here in another version than on the staging site: the CSS is written only to the same theme in the same version (a change of the theme is a phase of the code). Bring both sites to the same theme and version, or change the CSS by hand in the Customizer.', 'alphabridge-mcp' ),
			'The Additional CSS holds a closing tag of STYLE or ends in a part of one (the Customizer refuses to save it, WP_Customize_Custom_CSS_Setting::validate()), or a filter or the sanitizing of a save would change it here: change the CSS on the staging site, or by hand in the Customizer.' => __( 'The Additional CSS holds a closing tag of STYLE or ends in a part of one (the Customizer refuses to save it, WP_Customize_Custom_CSS_Setting::validate()), or a filter or the sanitizing of a save would change it here: change the CSS on the staging site, or by hand in the Customizer.', 'alphabridge-mcp' ),
			'The Additional CSS of the staging site has a part the engine does not write as it is (the source of a preprocessor in post_content_filtered, or another status than publish, which the core gives Additional CSS): it waits for its field rule.' => __( 'The Additional CSS of the staging site has a part the engine does not write as it is (the source of a preprocessor in post_content_filtered, or another status than publish, which the core gives Additional CSS): it waits for its field rule.', 'alphabridge-mcp' ),
			'An address with an id in the Additional CSS (url() of an attachment) has no counterpart on this site: pair or create the target first.' => __( 'An address with an id in the Additional CSS (url() of an attachment) has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'The core option is not on this site: an option is changed, never made by a run (WordPress makes its core options as it is installed and updated).' => __( 'The core option is not on this site: an option is changed, never made by a run (WordPress makes its core options as it is installed and updated).', 'alphabridge-mcp' ),
			'sanitize_option() of this site would store another value for the option than the staging site has: change the setting on the staging site, or by hand in wp-admin (Settings).' => __( 'sanitize_option() of this site would store another value for the option than the staging site has: change the setting on the staging site, or by hand in wp-admin (Settings).', 'alphabridge-mcp' ),
			'The option names an object (a page, a category, the sticky posts, an address with an id) that has no counterpart on this site: pair or create the target first.' => __( 'The option names an object (a page, a category, the sticky posts, an address with an id) that has no counterpart on this site: pair or create the target first.', 'alphabridge-mcp' ),
			'Data of the running site (group B): never compared, never taken over.' => __( 'Data of the running site (group B): never compared, never taken over.', 'alphabridge-mcp' ),
			// The reasons of the locks of the environment (AB_MCP_Sync_Env::LOCKS).
			'A plugin is code: its files and whether it runs are compared and taken over with the code of the site.' => __( 'A plugin is code: its files and whether it runs are compared and taken over with the code of the site.', 'alphabridge-mcp' ),
			'A theme is code: its files and which theme runs are compared and taken over with the code of the site.' => __( 'A theme is code: its files and which theme runs are compared and taken over with the code of the site.', 'alphabridge-mcp' ),
			'WordPress itself is never taken over: where staging is newer, this site is updated first (wp_update_core); its files are compared with the code of the site.' => __( 'WordPress itself is never taken over: where staging is newer, this site is updated first (wp_update_core); its files are compared with the code of the site.', 'alphabridge-mcp' ),
			'A must-use plugin is a file: compared by its SHA-256, taken over with the files of the site.' => __( 'A must-use plugin is a file: compared by its SHA-256, taken over with the files of the site.', 'alphabridge-mcp' ),
			'A table of a plugin without an adapter: shown by its rows and schema, its rows are not read; taken over only through an adapter, or as a whole on a confirmation per table.' => __( 'A table of a plugin without an adapter: shown by its rows and schema, its rows are not read; taken over only through an adapter, or as a whole on a confirmation per table.', 'alphabridge-mcp' ),
			'The language files are counted and summed, their contents not compared; they are compared and taken over with the files of the site.' => __( 'The language files are counted and summed, their contents not compared; they are compared and taken over with the files of the site.', 'alphabridge-mcp' ),
			'The files of the font library are counted and summed, their contents not compared; they come with the fonts of the font library.' => __( 'The files of the font library are counted and summed, their contents not compared; they come with the fonts of the font library.', 'alphabridge-mcp' ),
			'The files of the must-use folder WordPress does not load itself (in its subfolders) are counted and summed, their contents not compared.' => __( 'The files of the must-use folder WordPress does not load itself (in its subfolders) are counted and summed, their contents not compared.', 'alphabridge-mcp' ),
			'Files under uploads outside the file lists of attachments are counted and summed, their contents not compared.' => __( 'Files under uploads outside the file lists of attachments are counted and summed, their contents not compared.', 'alphabridge-mcp' ),
			// The reasons of AB_MCP_Sync_Option_Schema (REASON_TEXTS and the messages of settle()).
			'The option guard of AlphaBridge MCP refuses this option name, so it is not taken over. An administrator changes it in wp-admin.' => __( 'The option guard of AlphaBridge MCP refuses this option name, so it is not taken over. An administrator changes it in wp-admin.', 'alphabridge-mcp' ),
			'The option could not be looked up, so it is not classified. Try again; if it keeps failing, the database error log names the cause.' => __( 'The option could not be looked up, so it is not classified. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ),
			'The option name could not be settled, so it is not classified. Try again; if it keeps failing, the database error log names the cause.' => __( 'The option name could not be settled, so it is not classified. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ),
			// The reasons of the proposal of the pairs (AB_MCP_Sync_Proposal).
			'Its name cannot be held by a pair (longer than 191 characters, a control character, or not UTF-8), so it is not paired. Take it over by hand.' => __( 'Its name cannot be held by a pair (longer than 191 characters, a control character, or not UTF-8), so it is not paired. Take it over by hand.', 'alphabridge-mcp' ),
			'Without a slug, an object is named by nothing but its id, so it is paired only by the choice of a person.' => __( 'Without a slug, an object is named by nothing but its id, so it is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'The path of its parents is not known whole on its site (a parent not read, without a slug, or with the slug of another), so it is paired only by the choice of a person.' => __( 'The path of its parents is not known whole on its site (a parent not read, without a slug, or with the slug of another), so it is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'What the menu item shows, or the item it hangs under, has no identity of its own on its site (a target that is gone or has no slug, or another with the same: two links of one address and label), so it is paired only by the choice of a person.' => __( 'What the menu item shows, or the item it hangs under, has no identity of its own on its site (a target that is gone or has no slug, or another with the same: two links of one address and label), so it is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'The same slug on another path of parents (another parent, or a parent of another type, theme or language): another object, or one that moved. It is paired only by the choice of a person.' => __( 'The same slug on another path of parents (another parent, or a parent of another type, theme or language): another object, or one that moved. It is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'Another item of its menu has the same identity on its site (two links of one address and label, or one target, under one parent), so only its place tells them apart: it is paired only by the choice of a person.' => __( 'Another item of its menu has the same identity on its site (two links of one address and label, or one target, under one parent), so only its place tells them apart: it is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'Another object of its site has the same identity (a post of the same type, slug, language and parent, such as a published page and a draft of its slug; a term of the same taxonomy, slug and language), so neither is named by it. Such objects are paired by an equal content only where it is there once on each side and every one of them of both sites is at hand; this one is paired only by the choice of a person, also where a package holds only one of them or the other one was paired.' => __( 'Another object of its site has the same identity (a post of the same type, slug, language and parent, such as a published page and a draft of its slug; a term of the same taxonomy, slug and language), so neither is named by it. Such objects are paired by an equal content only where it is there once on each side and every one of them of both sites is at hand; this one is paired only by the choice of a person, also where a package holds only one of them or the other one was paired.', 'alphabridge-mcp' ),
			'The inventory of the staging site does not show that it holds every object of this kind: it was read across packages (a package does not say which other packages belong to its series, so this site cannot tell a whole series from one with a package left out), or objects of it were withheld from the account that made it. So an object of this site without a counterpart is not called only live. Where objects were withheld, export again with an account that may read every object (wp_site_export); a kind read across packages stays so in this version.' => __( 'The inventory of the staging site does not show that it holds every object of this kind: it was read across packages (a package does not say which other packages belong to its series, so this site cannot tell a whole series from one with a package left out), or objects of it were withheld from the account that made it. So an object of this site without a counterpart is not called only live. Where objects were withheld, export again with an account that may read every object (wp_site_export); a kind read across packages stays so in this version.', 'alphabridge-mcp' ),
			'What names it on its site (the parent of a post; the target, parent item or menu of a menu item) is the object of a pair on one side and another object on the other (the object of the pair renamed, and another object of its site took its slug): another object, or one that moved. It is paired only by the choice of a person.' => __( 'What names it on its site (the parent of a post; the target, parent item or menu of a menu item) is the object of a pair on one side and another object on the other (the object of the pair renamed, and another object of its site took its slug): another object, or one that moved. It is paired only by the choice of a person.', 'alphabridge-mcp' ),
			'A name of this object holds a credential by the rules of this site (held: credential_name, credential_field or unreadable_name), so it is shown by the SHA-256 of its ref only and is not paired. Take it over by hand.' => __( 'A name of this object holds a credential by the rules of this site (held: credential_name, credential_field or unreadable_name), so it is shown by the SHA-256 of its ref only and is not paired. Take it over by hand.', 'alphabridge-mcp' ),
			'Rest removed: the object is no longer on this site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.' => __( 'Rest removed: the object is no longer on this site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.', 'alphabridge-mcp' ),
			'The object is no longer on this site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.' => __( 'The object is no longer on this site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.', 'alphabridge-mcp' ),
			'The object is not in the inventory of the staging site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.' => __( 'The object is not in the inventory of the staging site. A new alignment needs both objects; to end the pair, call wp_site_pairs with action=discard and its pair id.', 'alphabridge-mcp' ),
			'A side of this pair is not compared (no hash, or a credential holds it), so it is not aligned anew. Take it over by hand, or call wp_site_pairs with action=discard and its pair id.' => __( 'A side of this pair is not compared (no hash, or a credential holds it), so it is not aligned anew. Take it over by hand, or call wp_site_pairs with action=discard and its pair id.', 'alphabridge-mcp' ),
			'A credential holds this object (a field of a credential, a post protected by a password, a script, HTML outside kses, a value that does not unpack), so it is never compared; its locks name why. Take it over by hand.' => __( 'A credential holds this object (a field of a credential, a post protected by a password, a script, HTML outside kses, a value that does not unpack), so it is never compared; its locks name why. Take it over by hand.', 'alphabridge-mcp' ),
			'The staging site could not read this row, so it has no identity to match. Make the inventory again on the staging site (wp_site_export), take it over here, then propose again.' => __( 'The staging site could not read this row, so it has no identity to match. Make the inventory again on the staging site (wp_site_export), take it over here, then propose again.', 'alphabridge-mcp' ),
			'This site could not read this row, so it has no identity to match. Try again; if it keeps failing, the database error log names the cause.' => __( 'This site could not read this row, so it has no identity to match. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ),
			// The reason of a lock the table does not name (AB_MCP_Sync_Post_Writer::locked()).
			'Not carried to its end in this version.' => __( 'Not carried to its end in this version.', 'alphabridge-mcp' ),
			// The messages of AB_MCP_Outbound a fetch of the key hands on (AB_MCP_Sync_Anchor).
			'Not sent: without cURL the request cannot be tied to the checked addresses.' => __( 'Not sent: without cURL the request cannot be tied to the checked addresses.', 'alphabridge-mcp' ),
			'The address was not asked: the request would go through the proxy, which looks the name up itself.' => __( 'The address was not asked: the request would go through the proxy, which looks the name up itself.', 'alphabridge-mcp' ),
			'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.' => __( 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.', 'alphabridge-mcp' ),
			'The address was not asked: its host name has no address.' => __( 'The address was not asked: its host name has no address.', 'alphabridge-mcp' ),
			'The address was not asked: it is local or not public.' => __( 'The address was not asked: it is local or not public.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * The texts with a value in them: English form => the form in the
	 * language of the site.
	 *
	 * @return array<string,string>
	 */
	private static function forms() {
		return array(
			// The reasons of AB_MCP_Sync_Option_Schema (REASON_TEXTS and the messages of settle()).
			/* translators: %s: the name of the option row WordPress reads instead. */
			'WordPress reads the row %s under this name, so this row is never read and is not taken over. An administrator changes it in wp-admin.' => __( 'WordPress reads the row %s under this name, so this row is never read and is not taken over. An administrator changes it in wp-admin.', 'alphabridge-mcp' ),
			/* translators: %s: the name of the option row WordPress reads instead. */
			'WordPress reads the row %s under this name, so this row is never read and is not taken over.' => __( 'WordPress reads the row %s under this name, so this row is never read and is not taken over.', 'alphabridge-mcp' ),
			// The reasons of AB_MCP_Sync_Decision.
			/* translators: 1: kind of the object, as the tools name it (post, term, …), 2: its id on the staging site. */
			'It needs the %1$s %2$s of the staging site, which is created in the same run but is held back itself (locked, or waiting for another).' => __( 'It needs the %1$s %2$s of the staging site, which is created in the same run but is held back itself (locked, or waiting for another).', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the id of the pair. */
			'Its target, the %1$s of the staging site, has an unclear pair (pair %2$d): a person confirms which side wins first.' => __( 'Its target, the %1$s of the staging site, has an unclear pair (pair %2$d): a person confirms which side wins first.', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the id of the pair. */
			'Its target, the %1$s of the staging site, was taken over as deleted (pair %2$d).' => __( 'Its target, the %1$s of the staging site, was taken over as deleted (pair %2$d).', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the id of the pair. */
			'Its target, the %1$s of its pair %2$d, is not in the inventory of the staging site (deleted there, or not read).' => __( 'Its target, the %1$s of its pair %2$d, is not in the inventory of the staging site (deleted there, or not read).', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the id of the pair. */
			'Its target, the %1$s of the staging site, is gone on this site (pair %2$d).' => __( 'Its target, the %1$s of the staging site, is gone on this site (pair %2$d).', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the id of the pair. */
			'Its target, the %1$s of the staging site, is in the trash there (pair %2$d).' => __( 'Its target, the %1$s of the staging site, is in the trash there (pair %2$d).', 'alphabridge-mcp' ),
			/* translators: %s: kind and id of the target, as the tools name them (post 12, …). */
			'Its target, the %s of the staging site, is not in its inventory: an object of a type the takeover does not read, or one withheld from the account that made the inventory. Until it is, this object is not taken over.' => __( 'Its target, the %s of the staging site, is not in its inventory: an object of a type the takeover does not read, or one withheld from the account that made the inventory. Until it is, this object is not taken over.', 'alphabridge-mcp' ),
			/* translators: %s: kind and id of the target, as the tools name them (post 12, …). */
			'Its target, the %s of the staging site, has no pair yet but an object of its identity on this site: pair them first (wp_site_pairs).' => __( 'Its target, the %s of the staging site, has no pair yet but an object of its identity on this site: pair them first (wp_site_pairs).', 'alphabridge-mcp' ),
			/* translators: 1: kind and id of the target, as the tools name them (post 12, …), 2: the result of the target in the report (new_staging, …). */
			'Its target, the %1$s of the staging site, has no pair and is not created here (%2$s).' => __( 'Its target, the %1$s of the staging site, has no pair and is not created here (%2$s).', 'alphabridge-mcp' ),
		);
	}
}
