<?php
/**
 * Staging: the tools around a staging site (free since 4.15.0).
 *
 * The first of them switches the preview protection of a staging site on
 * or off (AB_MCP_Preview_Protection); the second reports where the site
 * stands on the way to a staging site and what to do next (AB_MCP_Staging).
 * With the second come the guided prompt staging_workflow and, in the
 * server instructions, the question whether to work on staging first.
 * The group «Staging» grows with the later stages of the staging workflow:
 * since Z8 the packages of the takeover (wp_site_export, wp_site_export_chunk
 * on the staging site, wp_site_import_chunk on the production site;
 * AB_MCP_Sync_Export, AB_MCP_Sync_Import), the object pairs
 * (wp_site_pairs on the production site; AB_MCP_Sync_Pairs_Tool), the
 * report (wp_site_diff on the production site; AB_MCP_Sync_Report), the run
 * that writes objects of a report onto the production site (wp_site_push;
 * AB_MCP_Sync_Push, AB_MCP_Sync_Engine) and the way back for what a run
 * wrote (wp_site_revert; AB_MCP_Sync_Revert).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/class-preview-protection.php';
require_once dirname( __DIR__ ) . '/class-staging.php';

/**
 * Class AB_MCP_Tools_Staging
 */
class AB_MCP_Tools_Staging extends AB_MCP_Tools_Base {

	/**
	 * The purposes of the packages wp_site_export makes and
	 * wp_site_import_chunk takes in this version (AB_MCP_Sync_Export::
	 * PURPOSES), named here so that registering the tools loads no class of
	 * the takeover; a test holds them equal.
	 */
	const EXPORT_PURPOSES = array( 'inventory', 'env', 'objects', 'media' );

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {
		$r->register(
			'wp_set_preview_protection',
			array(
				'description' => 'Switch the preview protection of this site on or off, or set a new shared password. With the protection on, a visitor without a WordPress login sees a password page (HTTP 401) instead of every page, post, archive, feed, search, the sitemaps of SEO plugins and the AJAX answers of plugins for visitors (admin-ajax.php, admin-post.php and the ?wc-ajax= of WooCommerce; the heartbeat and the password generator of the core keep answering), and the public REST API (wp/v2, oembed/1.0 and every other namespace) answers such a visitor 401 as well; a visitor who enters the password is remembered in a browser cookie for 14 days. Switching on also sets the search engine visibility to discouraged (blog_public=0; the earlier value is remembered and written back when switching off), sends X-Robots-Tag: noindex on every answer, marks every answer as not cacheable and empties the page cache of the supported caching plugins. What stays reachable: this MCP endpoint and the sign-in of apps, wp-admin and wp-login.php, the REST root /wp-json/ (the hub reads it to find the endpoint), the OAuth documents below /.well-known/, and the REST API for signed-in users and application passwords, so Gutenberg, the hub and the connected assistants keep working. What it does not cover: files under /wp-content/uploads/, which the web server delivers without PHP, stay reachable by their address unless the host adds a rule; caches outside PHP (a host cache, Cloudflare APO, a CDN) are not emptied; what a plugin answers before WordPress has parsed the request is not held back (the payment callbacks of the wc-api of WooCommerce, a download over ?download_file= and other handlers on init or wp_loaded), nor is pingback.ping of xmlrpc.php, which needs no login; the shared password identifies nobody, so the protection suits a staging site shown to a client, not confidential data. The password is generated here and returned once, in password, for the person to pass on to whoever should see the preview; only its hash is stored, and a new password signs every visitor out. Deactivating the plugin lifts the protection and writes the search engine visibility back; activating it again restores both, with the same password. For the few requests WordPress serves without loading plugins (wp-activate.php) the plugin keeps a small must-use loader in wp-content/mu-plugins/ while the protection is on; where that folder cannot be written, not_covered says so and loader_source holds the file to create by hand. Returns enabled, changed, password (only when a new one was set), blog_public, blog_public_before, since, set_by, page_cache and not_covered. Needs manage_options and write access.',
				'capability'  => 'manage_options',
				// Switching on hides the site from visitors and switching off
				// shows it again; both are taken back by the same call, and
				// nothing of the site is overwritten.
				'destructive' => false,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'      => array(
							'type'        => 'boolean',
							'description' => 'true switches the protection on and sets a password when it was off; false switches it off and writes the earlier search engine visibility back. When omitted, the state stays as it is and the answer reports it.',
						),
						'new_password' => array(
							'type'        => 'boolean',
							'description' => 'true replaces the shared password while the protection is on; the new one is in the answer, once, and every visitor has to enter it again. Default false. Switching on sets a password anyway.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'set_preview_protection' ),
			)
		);

		$r->register(
			'wp_staging_status',
			array(
				'description' => 'Report where this site stands on the way to a staging site, and what to do next. Returns role: the role a person confirmed (production, staging, or unconfirmed, which counts as the production site), with who confirmed it, when and where (the page AlphaBridge → Staging or the hub), and a suggestion from what the site shows: the environment type WordPress reports, an address beginning with staging., stage., dev. or test. or ending in .test, .local or .localhost, the preview protection, the search engine visibility. A suggestion is never proof; only a person confirms the role, on the page AlphaBridge → Staging or in the hub. Returns pair (the other site of the pair, its role and who confirmed it where, or null), host (chosen by the person, detected from what the server shows with the evidence, or unknown; guide: the address of the host\'s own guide to its staging function where one is known, else null), preview_protection, blog_public, checklist (role confirmed, staging site there and answering, preview protection on the staging site, pair set: each with done true, false, or null where it is decided on the other site or not checked yet, its finding and, while open, numbered steps), next_step (the first open step with a direct link, to pass on to the person) and warnings. With check_partner=true it also asks the other site of the pair once whether AlphaBridge MCP answers there: one GET to its MCP endpoint without a token, only when every address of its host is public and tied to those addresses, not through a proxy, no redirect followed; the result is in partner_check. Reads only and changes nothing. Needs manage_options.',
				'capability'  => 'manage_options',
				'readonly'    => true,
				// With check_partner it asks another site, the one of the
				// pair; without, it reads this site only.
				'open_world'  => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'check_partner' => array(
							'type'        => 'boolean',
							'description' => 'true asks the other site of the pair whether AlphaBridge MCP answers there (one request to its stored address). Default false: nothing leaves this site.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'staging_status' ),
			)
		);

		$r->register(
			'wp_site_export',
			array(
				'description' => 'On a confirmed staging site paired with a production site: make a package for that production site, signed with the key of this staging site and kept a day in the protected store of this site. With purpose=inventory the package holds the inventory of this site: each object with its identity, hash, references, size and whether it was read completely, no content. With purpose=objects, report_hash (from wp_site_diff on the production site) and refs (kind and ref of each object as the report names the staging side, at most 2000) it holds those objects with their content for a run of wp_site_push on the production site: for a post its row, meta rows and terms as stored, with its canonical form and hash, so the production site writes exactly what the report compared; the manifest names the report, and the production site reads the package for that report only; in this version the engines of the kinds post, term and attachment export, every other kind refuses with its stage. With purpose=media, report_hash and refs (attachments of the report, kind attachment and ref) it holds the files of those attachments for the same run: every file of the file list of each (the main file, its sizes, the original of a scaled image, the edits), each in pieces checked against its size and SHA-256, whole attachments up to 48 MiB a package; media in the answer names the attachments packed, those left for another package (left), those larger than one package (too_large: upload them by hand on the production site) and those whose file list does not read whole (unread). With purpose=env it holds the environment of this site, for wp_site_diff: the version of WordPress and the language; each plugin and theme with its version and whether it runs; each must-use plugin and drop-in with its SHA-256; the language files, the fonts, the files of the must-use folder WordPress does not load and the files under uploads outside the file lists of attachments, each counted and summed, never read; each table with the prefix that neither WordPress nor the takeover covers, with its rows and the SHA-256 of its schema, none of its rows; the host: the versions of PHP and the database, the object cache, the WordPress block of the .htaccess and the must-use loader of AlphaBridge by their SHA-256, and a fixed list of constants that holds no secret (never a key, salt, password or the access to the database). Only what the calling account may read goes in (content with read_post or edit_post, and edit_post where it is not public; terms of a taxonomy that is not public with edit_term; menus, widgets, theme mods and the additional CSS with edit_theme_options; options with manage_options); the rest is counted and named in withheld, and its kinds are not complete. An object whose name is the name of a credential is left out and counted, one with a credential in it carries only its identity, status and locks with their reasons (credentials). One call reads as far as 60 seconds and 4000 objects (or lines of the environment) reach; when the answer carries a cursor, call again with it for the next package of the same purpose (on a large site the first packages of the inventory may hold no object, while this site works out the identities of its objects; a large uploads folder is counted over several packages of the environment). Returns package_id, objects, bytes, chunks, kinds (each kind and subtype, complete true where the package holds every object of it), withheld (count, the objects named with the capability they need, and the way), credentials (names left out, units left out, objects held), expires, manifest (pass it unchanged to wp_site_import_chunk on the production site) and fetch: per chunk a fetch address signed by this site and valid for 10 minutes, with which the production site fetches the chunk itself over https (pass fetch unchanged to wp_site_import_chunk). With package_id instead, it returns that package of this account again, with fresh fetch addresses for chunks still missing. Limit: whoever sees a fetch address within its 10 minutes can read that chunk. Changes nothing on the site but its protected store. Refused on a multisite network. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => false,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'purpose'    => array(
							'type'        => 'string',
							'enum'        => self::EXPORT_PURPOSES,
							'description' => 'What the package is for: inventory (the inventory of this staging site), env (its environment, for wp_site_diff), objects (the objects of a report with their content, for wp_site_push; with report_hash and refs) or media (the files of the attachments of a report, for wp_site_push; with report_hash and refs of kind attachment).',
						),
						'cursor'     => array(
							'type'        => 'object',
							'description' => 'The cursor of the answer before, unchanged, for the next package of the same purpose; without it, the first package.',
						),
						'report_hash' => array(
							'type'        => 'string',
							'description' => 'purpose=objects or media: the report_hash of the report of the production site (wp_site_diff) the objects are for.',
						),
						'refs'       => array(
							'type'        => 'array',
							'description' => 'purpose=objects or media: the objects to pack, each with kind and ref as the entries of the report name the staging side (staging.ref); for media the attachments; at most 2000.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'kind' => array( 'type' => 'string' ),
									'ref'  => array( 'type' => 'string' ),
								),
							),
						),
						'package_id' => array(
							'type'        => 'string',
							'description' => 'A package made before by this account (32 hex): returned again with fresh fetch addresses; purpose and cursor are then not needed.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'site_export' ),
			)
		);

		$r->register(
			'wp_site_export_chunk',
			array(
				'description' => 'On the staging site: give one chunk of a package this account made with wp_site_export, as base64, for the relay: pass package_id, chunk and data unchanged to wp_site_import_chunk on the production site. A chunk is at most 5 MiB, its base64 about 7 MB, so the relay suits a client that hands data on without passing it through the model (a script, Claude Code); a chat client cannot carry it. The direct way (fetch addresses, wp_site_import_chunk with fetch) needs no relay. Refused unless the request comes over https, where this site has a public address. Returns package_id, chunk, bytes, sha256 and data. Changes nothing. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => false,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'package_id' => array(
							'type'        => 'string',
							'description' => 'The package, as wp_site_export named it (32 hex).',
						),
						'chunk'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'The chunk, from 0 to chunks - 1 of the answer of wp_site_export.',
						),
					),
					'required'   => array( 'package_id', 'chunk' ),
				),
				'callback'    => array( __CLASS__, 'site_export_chunk' ),
			)
		);

		$r->register(
			'wp_site_import_chunk',
			array(
				'description' => 'On the production site: take a package of the staging site it is paired with: of its inventory (purpose=inventory), of its environment (purpose=env, for wp_site_diff), of objects for a report (purpose=objects with report_hash, for wp_site_push: the package is read for that report only) or of the files of its attachments (purpose=media with report_hash, likewise). The call that begins a package passes package_id, manifest and purpose as wp_site_export gave them; this site checks the manifest (signed with the key of the staging site a person confirmed on the page AlphaBridge → Staging, for the address of this site exactly, from the stored address of the staging site exactly, made less than a day ago) and keeps it. Each call then either hands over one chunk (chunk and data from wp_site_export_chunk; only over https where this site has a public address) or passes fetch (the fetch addresses from wp_site_export): this site then fetches the chunks itself from the staging site over https, at an address it builds from the stored address of the staging site, following no redirect, for up to 60 seconds a call. Every chunk is checked against its SHA-256 in the manifest before it is kept; once all are there, the package is checked whole and unpacked within limits (8 MiB raw per object, a depth of 64, 5000 objects, 256 MiB a package; a chunk that inflates past what its manifest declares, or is not one whole gzip, is refused), each line checked again for the structure of a line of the inventory or of the environment (its fields, types and fixed lists; names are taken with any characters and length), or for a package of media each piece of a file in its order and size and each file against its SHA-256: a package whose structure does not hold is removed with the way. An object whose name is the name of a credential by the rules of this site, or a line with a credential written into one of its names (a slug, a place, a login; also encoded, as in base64), is counted as held with the reason, never with a name (held), as is a name of the environment (a file, a plugin, a theme, a table) with a credential written into it, and the package goes on. A package belongs to the account and the connection (the token) that began it; another one begins its own. Returns chunks, received, missing (call again for those, with fresh fetch addresses after 10 minutes), complete, held once complete, and failed with the reason for each chunk a fetch did not bring. Writes nothing but the protected store of this site, where the package is kept a day; what is in it is taken over only by the takeover. Refused on a multisite network. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => false,
				// With fetch it asks the staging site of the pair.
				'open_world'  => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'package_id'  => array(
							'type'        => 'string',
							'description' => 'The package, as wp_site_export named it (32 hex).',
						),
						'manifest'    => array(
							'type'        => 'object',
							'description' => 'The manifest from the answer of wp_site_export, unchanged (manifest and signature). Required while this site holds no package of that id yet.',
							'properties'  => array(
								'manifest'  => array( 'type' => 'string' ),
								'signature' => array( 'type' => 'string' ),
							),
						),
						'purpose'     => array(
							'type'        => 'string',
							'enum'        => self::EXPORT_PURPOSES,
							'description' => 'What the package is read for, as wp_site_export made it; with the manifest: inventory, env, objects or media.',
						),
						'report_hash' => array(
							'type'        => 'string',
							'description' => 'purpose=objects or media, with the manifest: the report_hash of the report (wp_site_diff) the package is for; the manifest must name the same.',
						),
						'chunk'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Relay: the chunk data holds.',
						),
						'data'        => array(
							'type'        => 'string',
							'description' => 'Relay: the chunk as base64, unchanged from wp_site_export_chunk.',
						),
						'fetch'       => array(
							'type'        => 'array',
							'description' => 'Direct: the fetch addresses from the answer of wp_site_export, unchanged.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'chunk'     => array( 'type' => 'integer' ),
									'expires'   => array( 'type' => 'integer' ),
									'signature' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'required'   => array( 'package_id' ),
				),
				'callback'    => array( __CLASS__, 'site_import_chunk' ),
			)
		);

		$r->register(
			'wp_site_pairs',
			array(
				'description' => 'On the production site: pair the objects of this site with those of its staging site, where the staging site is no copy made with the same ids. action=propose with packages (the package_ids of the inventory of the staging site this site took over completely with wp_site_import_chunk: one package, or the packages of one series) compares that inventory with the inventory of this site, read in parts of at most 4000 objects and 45 seconds a call (call again with action=propose and the proposal_id until done is true), and proposes by kind and identity (post type, theme, slug, language and the path of parents; taxonomy, slug and language; the menu, target and parent item of a menu item, not its position; the name of an option or area; the stylesheet of the additional CSS), comparing the content with each reference by id naming its target by identity (an identity another object of the same site has as well names none; a custom link by its address and label; a whole number of a stored value that hits an object, such as an image id of a builder or field, compared by that object as well), so the same objects with other ids on the two sites are equal: equal (the same identity and content), differs (the same identity, other content), uncertain (an identity that is there more than once on a site, also where a package holds only one of them; the same slug on another path of parents, a path of parents not known whole, a post without a slug, a menu item whose target or parent has no identity of its own): paired only by a choice of the person, never by itself, realign (a pair that is unclear or residual: both sides now as its new bases), staging_only and live_only (no counterpart; live_only only where the inventory of the staging site holds every object of that kind, else not_compared), not_compared (with the reason: a row not read, a name no pair holds, an option the takeover never reads, an object a credential holds) and held (a name holds a credential: shown by the SHA-256 of its ref only, never paired). Objects of a pair are not proposed again, and an active pair whose bases are of another version of the comparison data is worked out again where both objects are at hand (equal → new bases, else unclear). The answer carries counts, the first 200 entries and next_after (call again with proposal_id and after for the next ones). action=confirm with the proposal_id and select (entry numbers), categories (equal, differs, realign; at most 500 a call, after for the next part) or choices (for an uncertain entry: its staging_ref and live_ref) makes the pairs: equal as active, differs and a chosen pair of other content as unclear, realign as active with the states of both sides now; in one transaction of the database, all or nothing (the table must use InnoDB); its token also covers the objects of this site as they are when confirmed. action=discard with pair_ids ends pairs, for any account with manage_options; their objects stay. action=list with after, kind and state lists the pairs. A proposal belongs to the account and connection that made it, holds as long as its packages (a day at most), and a new proposal of the same connection replaces it. Writes the table of object pairs and the protected store of this site only; no content of either site changes. Refused on a multisite network and without a confirmed key of a staging site. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action'      => array(
							'type'        => 'string',
							'enum'        => array( 'propose', 'confirm', 'discard', 'list' ),
							'description' => 'propose: work out or page a proposal; confirm: make the pairs chosen; discard: end pairs; list: the pairs.',
						),
						'packages'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'propose: the package_ids of the inventory of the staging site, taken over completely here, of one series.',
						),
						'proposal_id' => array(
							'type'        => 'string',
							'description' => 'propose: go on with this proposal or page it; confirm: the proposal the entries are of.',
						),
						'after'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'propose: the entries after this number; confirm with categories: the entries after this number; list: the pairs after this pair id.',
						),
						'select'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'confirm: the numbers of the entries to pair (equal, differs or realign).',
						),
						'categories'  => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'equal', 'differs', 'realign' ),
							),
							'description' => 'confirm: every entry of these categories after the number in after, at most 500 a call.',
						),
						'choices'     => array(
							'type'        => 'array',
							'description' => 'confirm: for an uncertain entry, the pair the person chose: entry, staging_ref and live_ref among its candidates.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'entry'       => array( 'type' => 'integer' ),
									'staging_ref' => array( 'type' => 'string' ),
									'live_ref'    => array( 'type' => 'string' ),
								),
							),
						),
						'pair_ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'discard: the ids of the pairs to end, from action=list.',
						),
						'kind'        => array(
							'type'        => 'string',
							'description' => 'list: only pairs of this kind (post, term, attachment, menu, menu_item, widget_area, custom_css, option, theme_mod).',
						),
						'state'       => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'unclear', 'residual', 'deleted' ),
							'description' => 'list: only pairs in this state.',
						),
					),
					'required'   => array( 'action' ),
				),
				'confirm'     => array(
					'when'    => array( __CLASS__, 'pairs_when' ),
					'state'   => array( __CLASS__, 'pairs_state' ),
					'preview' => array( __CLASS__, 'pairs_preview' ),
					'note'    => 'action=confirm and action=discard run in two steps: without confirm_token such a call changes nothing and returns a preview with a confirm_token, and the same call with that token runs it; propose and list run at once. The token holds for this account and connection, while the proposal and the pairs it covers are unchanged. This guards against a call nobody meant; it does not make the change reversible.',
				),
				'callback'    => array( __CLASS__, 'site_pairs' ),
			)
		);

		$r->register(
			'wp_site_diff',
			array(
				'description' => 'On the production site: report what differs between this site and its staging site, object by object over every kind the takeover reads (posts of the core types, templates and navigations, terms, menus and their items, widget areas, the additional CSS, options and theme mods), and what a takeover would do with each. A report begins with packages: the package_ids of the inventory of the staging site that this site took over completely (wp_site_import_chunk), one package or the packages of one series. Checked first, each refusal with its way: this site is the confirmed production site with a staging site paired; the key of that staging site is confirmed by a person on the page AlphaBridge → Staging; each manifest is signed with that key, for this site and from its staging site exactly, not expired, of the same format and version of the comparison data; WordPress on the staging site is not newer than here (else update this site first, with wp_update_core). The inventory of this site is read in parts of at most 4000 objects and 60 seconds a call (call again with report_id until done is true). Each object pair is decided from the state of each side against its base (unchanged, changed, deleted, where the trash counts as deleted; a side the inventory does not hold whole is not compared, never deleted): equal, changed on staging (take_over), changed here (keep), changed on both differently (conflict: a person chooses) or alike, deleted on staging, deleted here. A pair that is unclear is treated as a conflict; a pair left as rest after a way back or an error (residual) is shown as rest, or as rest removed, and never taken over; a pair with a confirmed target (such as status draft) is compared against it and names it. Without a pair: only on staging (create), only here (keep), new on both sides (pair them first with wp_site_pairs). References an object needs are followed: one whose target is locked, unclear, without a pair or not in the inventory (an attachment, before media are compared) waits, and objects that need each other to be created are locked (mutual references). Not done, shown as locked with its reason and the stage that brings it: no object is deleted (an object deleted on staging shows as deleted on staging, taken over from Z8d), a menu with an item removed on staging and a widget area with an instance removed on staging are locked as a whole, and roles, Customizer changesets, options of plugins, theme mods other than the menu positions, documents of page builders other than six block libraries, post types and taxonomies of plugins, creating additional CSS and creating terms with meta are locked. With environment (the packages of the environment of the staging site, wp_site_export with purpose=env), this site reads its own environment as well, in parts within the same 60 seconds a call, and adds an entry of kind environment for each plugin, theme, must-use plugin, drop-in, table, root of files and value of the host on either side: equal or differs only where it was read by its SHA-256 (must-use plugins, drop-ins); a value of the host and the language are shown, same_value where they are the same on both sides (what lies behind a value is not compared); what is only counted or summed (plugins, themes and WordPress of the same version, tables of the same rows and schema, the language files, fonts, files of the must-use folder and the files under uploads outside the file lists of attachments of the same count and size) is counted, contents not compared, with the stage that compares them (Z8b for files and code, Z8d for tables), never equal; what could not be read whole is not compared, with why. Nothing of the environment is taken over: code, files and tables are locked with their reason and stage, the drop-ins and the host are changed at the host; no hash and no value of a file is shown, a name of the environment with a credential written into it only by its SHA-256, and no constant that holds a secret is read. Without environment, the environment is one entry, not compared, with the way. Returns report_id, report_hash, done, counts (by result and action), blocked (each lock with its reason, stage and how many entries), pairs, staging, live, environment (packages, counts), wordpress, the first 200 entries (result, rule and row of the table, action, the state of each side, locks, waits_for, choices) and cursor (call again with report_id and cursor for the next entries). A name holding a credential is shown by the SHA-256 of its ref only. Writes nothing but the report into the protected store of this site, where it is kept a day for this account and connection. Refused on a multisite network. Needs manage_options.',
				'capability'  => 'manage_options',
				'readonly'    => true,
				// It reads what the staging site packed, another site.
				'open_world'  => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'packages'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'To begin: the package_ids of the inventory of the staging site, taken over completely here, of one series.',
						),
						'environment' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'To begin, with packages: the package_ids of the environment of the staging site (wp_site_export with purpose=env), taken over completely here, of one series in its order. Without it, the environment is not compared.',
						),
						'report_id'   => array(
							'type'        => 'string',
							'description' => 'Go on with this report, or page it once it is done.',
						),
						'cursor'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'With report_id: the entries after this number (the cursor of the answer before).',
						),
					),
				),
				'callback'    => array( __CLASS__, 'site_diff' ),
			)
		);

		if ( false === has_filter( 'ab_mcp_prompts', array( __CLASS__, 'add_prompt' ) ) ) {
			add_filter( 'ab_mcp_prompts', array( __CLASS__, 'add_prompt' ), 10, 2 );
		}
		$r->register(
			'wp_site_push',
			array(
				'description' => 'On the production site: write objects of a report (wp_site_diff) from the staging site onto this site, one object per call, each under the lock of AlphaBridge with a backup and a journal, and keep the way back (wp_site_revert). action=plan with report_id, the entries to write (select: their numbers; or all=true for every entry a takeover writes), choices for conflicts (entry and choice: staging_wins writes the staging version, keep_live and leave_open write nothing), as_draft (entries taken over with the status draft: the one confirmed choice for a page of the top level that would be published or scheduled while a menu of this site adds pages by itself) and packages (the package_ids of the objects of the staging site for this report, made there with wp_site_export purpose=objects, and of the files of its attachments, purpose=media, each taken over here with wp_site_import_chunk): without confirm_token it answers with the preview (the steps, what is locked with its reason and stage, the means of the site) and a token; with the token it makes the run and answers with its run_id (a confirmed call that meets an open run of the same plan, account and connection gets that run). In this version a run writes posts and pages: their row with status and dates, the meta of _wp_page_template and, on a post that is there, plain keys the account may edit, the terms of category, post_tag and post_format; the core types of the block themes, terms, and attachments with their files: a file this site lacks is laid exclusively (never over another file, which locks the attachment, as does a path another attachment of this site names), checked by its content against the types the account may upload; no file is ever overwritten or deleted, also not one the run laid, and the files of a new attachment stay with it on the way back; an attachment that hangs under a post the same run creates gets its parent by a step of its own after that post; every other kind and type, a meta key or taxonomy outside that rule, a new post with plain meta or a taken slug, a field WordPress would sanitize, an author without an account here and a reference without a counterpart are locked with their stage and are no step. Nothing is deleted, and no status stands in for a deletion. action=next runs the next step whose dependencies are done: first the check of everything before the first write (the means, the booking of the protected store, the lock, the packages against the report, every target as it is now, the rights, the status WordPress would store), which closes the run as aborted on one deviation; then one object per call: the object is locked against the writes of other users and processes through the WordPress API (the REST API, the trash and deletions, meta, the classic way; a change on a way the lock does not refuse, such as wp_publish_post() by the cron, terms on the classic way or SQL, can be lost between the check and the write), its state is backed up and read back, each part (row, meta key, taxonomy) is written through wp_insert_post(), wp_update_post() with both dates and edit_date, the metadata API and wp_set_object_terms(), read back from the rows under the same lock; where anything of the object deviates (a hook of this site changed a part, a call was refused, a part reads back other than its target), nothing of it is put back by itself: every part not as the backup is left as it is with its value before in the journal, the step and the pair are unclear, a person checks the object in wp-admin; else the object as a whole is read back against its target (the staging version with the confirmed choice), and its pair takes new bases (or is made); then the caches; then the check of the run (every written object against its target, a published object answering 200, the PHP probe, the error log), which closes the run as done or with findings. action=step runs a named step (refused while a dependency is open); action=status shows a run with its steps, journal, rests and calls (without run_id: the open run and the last runs); action=abort closes an open run (what it wrote stays; the way back is wp_site_revert). A run whose step began and whose request ended before it finished is interrupted: no step runs and no new run begins until a person closes it as checked on the page AlphaBridge → Staging or wp_site_revert takes its own parts back. A run belongs to the account and connection that made it. Each step answers within 60 seconds; a long check goes on over calls. Refused on a multisite network and without a confirmed key of a staging site. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action'    => array(
							'type'        => 'string',
							'enum'        => array( 'plan', 'next', 'step', 'status', 'abort' ),
							'description' => 'plan: preview, then make the run (with confirm_token); next: run the next step; step: run a named step (seq); status: show a run; abort: close an open run.',
						),
						'report_id' => array(
							'type'        => 'string',
							'description' => 'plan: the report of wp_site_diff the objects are of.',
						),
						'select'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'plan: the numbers of the entries of the report to write (take_over or create).',
						),
						'all'       => array(
							'type'        => 'boolean',
							'description' => 'plan: true writes every entry a takeover writes in this version (take_over and create of posts and pages); what it cannot is named, not refused.',
						),
						'choices'   => array(
							'type'        => 'array',
							'description' => 'plan: for a conflict, the choice of the person: entry and choice (staging_wins, keep_live, leave_open).',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'entry'  => array( 'type' => 'integer' ),
									'choice' => array(
										'type' => 'string',
										'enum' => array( 'staging_wins', 'keep_live', 'leave_open' ),
									),
								),
							),
						),
						'as_draft'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'plan: the entries taken over with the status draft (the confirmed choice where a page of the top level would be published or scheduled while a menu adds pages by itself).',
						),
						'packages'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'plan: the package_ids of the objects of the staging site for this report and of the files of its attachments, taken over here completely (wp_site_import_chunk, purpose=objects and purpose=media).',
						),
						'run_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'next, step, status, abort: the run.',
						),
						'seq'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'step: the number of the step to run.',
						),
					),
					'required'   => array( 'action' ),
				),
				'confirm'     => array(
					'when'    => array( __CLASS__, 'push_when' ),
					'state'   => array( __CLASS__, 'push_state' ),
					'preview' => array( __CLASS__, 'push_preview' ),
					'note'    => 'action=plan runs in two steps: without confirm_token the call changes nothing and returns the preview of the run with a confirm_token, and the same call with that token makes the run; next, step, status and abort run at once. The token holds for this account and connection, while the report, the selection and the objects of this site it covers are unchanged. This guards against a call nobody meant; it does not make the change reversible: the way back is wp_site_revert.',
				),
				'callback'    => array( __CLASS__, 'site_push' ),
			)
		);

		$r->register(
			'wp_site_revert',
			array(
				'description' => 'On the production site: take back what a run of wp_site_push wrote, pair by pair, byte for byte conditional. action=plan with run_id (without it, the run of this site that is not closed) and steps (the write steps of the run to take back; without them, every step that wrote): without confirm_token it answers with the preview (each object with the parts the run wrote and what will happen: back, back where still its own, or a rest) and a token; with the token the way back begins: for a run that is open or interrupted, the same run moves to reverting and gets its steps of the way back; for a closed run, a run of the way back is made (at most one run per site is not closed). action=next takes one pair back under the lock of AlphaBridge: a part goes back only where its bytes are still what the run wrote (the row through wp_update_post() with the backed-up dates and edit_date, after the check of the status WordPress would store now; meta rows raw and conditional; terms through wp_set_object_terms()); nothing where it is as before the run; a part changed since, a part the step never read back and every part of an object that deviated within its step is left as it is and named with its value before, its pair unclear; a new object the run made is never removed and stays as a rest, its pair residual; a backed-up scheduled post whose date has passed is not put back (check needed). The last step closes the run as reverted, or with findings where rests or unclear parts remain. action=status shows the run. A run belongs to the account and connection that made it. Refused on a multisite network and without a confirmed key of a staging site. Needs manage_options and the access level Full.',
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action' => array(
							'type'        => 'string',
							'enum'        => array( 'plan', 'next', 'status' ),
							'description' => 'plan: preview, then begin the way back (with confirm_token); next: take one pair back; status: show the run.',
						),
						'run_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'The run whose pairs go back (plan: the open run when left out); next, status: the run of the way back.',
						),
						'steps'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'plan: the seqs of the write steps of the run to take back; without them, every step that wrote.',
						),
					),
					'required'   => array( 'action' ),
				),
				'confirm'     => array(
					'when'    => array( __CLASS__, 'revert_when' ),
					'state'   => array( __CLASS__, 'revert_state' ),
					'preview' => array( __CLASS__, 'revert_preview' ),
					'note'    => 'action=plan runs in two steps: without confirm_token the call changes nothing and returns the preview of the way back with a confirm_token, and the same call with that token begins it; next and status run at once. The token holds for this account and connection, while the run and the objects it covers are unchanged. This guards against a call nobody meant.',
				),
				'callback'    => array( __CLASS__, 'site_revert' ),
			)
		);
	}

	/**
	 * The guided prompt staging_workflow, under the condition of the
	 * sentence in the server instructions (AB_MCP_Staging::offered():
	 * wp_staging_status on in the registry the server answers with, and a
	 * connection that can write): the way from a change the person asks
	 * for to a staging site, the work there and the changes carried over
	 * one at a time. It names only what wp_staging_status reports about the
	 * host and advertises nothing.
	 *
	 * @param array                     $prompts  Prompts.
	 * @param AB_MCP_Tool_Registry|null $registry The tools of the server.
	 * @return array
	 */
	public static function add_prompt( $prompts, $registry = null ) {
		$prompts = is_array( $prompts ) ? $prompts : array();
		if ( ! AB_MCP_Staging::offered( $registry ) ) {
			return $prompts;
		}
		$prompts[] = array(
			'name'        => 'staging_workflow',
			'description' => 'Guided steps: make a change on a staging site first — say why and how, set up and protect the staging site, work there, then carry the change over to the production site one object at a time and check it.',
			'arguments'   => array(
				array(
					'name'        => 'change',
					'description' => 'What the person wants to change on the site. Optional.',
					'required'    => false,
				),
				array(
					'name'        => 'staging_site',
					'description' => 'Address of a staging site the person already has. Optional.',
					'required'    => false,
				),
			),
			'text'        => "You are helping the person change this WordPress site through AlphaBridge, on a staging site first. Change: {{change}}. Staging site, if there is one already: {{staging_site}}\n\n"
				. "1. Sum up. Say in a sentence or two what the person wants changed, and why staging comes first: a change on the production site is visible to visitors at once; on a staging site it is checked first and carried over once it is approved.\n"
				. "2. Look where the site stands. Call wp_staging_status and pass its findings on in plain words. Call it again after each step of setting up (steps 4 and 5), on the site you are working on: it checks the role, the pair, whether the staging site answers and its preview protection, and next_step says what comes next. It does not check the work or what was carried over: for that, read the objects on both sites with the reading tools.\n"
				. "3. Choose the way with the person: a staging copy made with the host's staging function or with a staging plugin, or an existing staging site connected to this one. " . AB_MCP_Staging::drafts_in_prompt() . " Follow the steps of the checklist. Name a host and its staging function only where wp_staging_status names them, and describe nothing of the host's or the plugin's screens beyond what it says.\n"
				. "4. Set it up. The person makes the copy, in the host's control panel or the plugin's own screen, and AlphaBridge MCP has to be active on it. On the page AlphaBridge → Staging of each site, the person confirms its role and enters the address of the other site; a copy can carry the settings of this site, so check the role on the copy. Then the person connects you to the staging site as well, so you can work there.\n"
				. "5. Protect the staging site. On the staging site, switch on the preview protection with wp_set_preview_protection (enabled=true), or on its page AlphaBridge → Staging, and give the person the password from the answer for whoever should see the preview. If the protection is on already, the answer says changed=false and carries no password: ask the person for the password they have, or, only with their yes, set a new one with new_password=true, which signs every visitor out; say so first.\n"
				. "6. Work on the staging site. Make the change there with the usual tools, show it to the person with the preview password, and get their approval.\n"
				. "7. Carry it over one object at a time. This needs a connection that can write on both sites; if one is missing or only reads, tell the person how to get it: connect you on the page AlphaBridge → AI Connection on that site (Connect your AI assistant) or add the site in the hub, with the access level Full (Content reaches posts, pages, media, terms and comments only) and write access switched on there. Read each changed page, post, menu, term or setting on the staging site and write it on the production site with the same tools, after showing the person what will change. Where a tool records an undo point, its answer carries an undo token, and wp_undo with it takes the change back; where the answer has none, tell the person that this change has no way back through AlphaBridge. A file: wp_upload_media_from_url with its address on the staging site (the preview protection does not cover files under /wp-content/uploads/).\n"
				. "8. Check. Read the changed objects on the production site again and tell the person what changed and where to look.\n\n"
				. 'If the person would rather not use staging, or chooses drafts, go ahead that way: the choice is theirs.',
		);
		return $prompts;
	}

	/**
	 * wp_staging_status.
	 *
	 * @param array $a Arguments.
	 * @return array
	 */
	public static function staging_status( array $a ) {
		return AB_MCP_Staging::report( self::b( $a, 'check_partner' ) );
	}

	/**
	 * wp_site_export.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_export( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-export.php';
		if ( array_key_exists( 'package_id', $a ) ) {
			return AB_MCP_Sync_Export::again( $a['package_id'] );
		}
		return AB_MCP_Sync_Export::make( isset( $a['purpose'] ) ? $a['purpose'] : null, isset( $a['cursor'] ) ? $a['cursor'] : null, null, array_intersect_key( $a, array( 'report_hash' => true, 'refs' => true ) ) );
	}

	/**
	 * wp_site_export_chunk.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_export_chunk( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-export.php';
		return AB_MCP_Sync_Export::chunk( isset( $a['package_id'] ) ? $a['package_id'] : null, isset( $a['chunk'] ) ? $a['chunk'] : null );
	}

	/**
	 * wp_site_import_chunk.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_import_chunk( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-import.php';
		return AB_MCP_Sync_Import::call( $a );
	}

	/**
	 * Whether a call of wp_site_pairs needs the token: a strict false for
	 * propose and list only, true for any other (confirm, discard, none),
	 * asked without loading the classes of the takeover.
	 *
	 * @param array $a Arguments.
	 * @return bool
	 */
	public static function pairs_when( array $a ) {
		return ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'propose', 'list' ), true ) );
	}

	/**
	 * The state a token of wp_site_pairs is bound to.
	 *
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function pairs_state( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-pairs-tool.php';
		return AB_MCP_Sync_Pairs_Tool::state( $a );
	}

	/**
	 * The preview of wp_site_pairs confirm and discard.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function pairs_preview( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-pairs-tool.php';
		return AB_MCP_Sync_Pairs_Tool::preview( $a );
	}

	/**
	 * wp_site_pairs.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_pairs( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-pairs-tool.php';
		return AB_MCP_Sync_Pairs_Tool::call( $a );
	}

	/**
	 * wp_site_diff.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_diff( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-report.php';
		return AB_MCP_Sync_Report::call( $a );
	}

	/**
	 * Whether a call of wp_site_push needs the token: a strict false for
	 * next, step, status and abort only, true for plan and any other.
	 *
	 * @param array $a Arguments.
	 * @return bool
	 */
	public static function push_when( array $a ) {
		return ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'step', 'status', 'abort' ), true ) );
	}

	/**
	 * The state a token of wp_site_push is bound to.
	 *
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function push_state( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-push.php';
		return AB_MCP_Sync_Push::state( $a );
	}

	/**
	 * The preview of wp_site_push plan.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function push_preview( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-push.php';
		return AB_MCP_Sync_Push::preview( $a );
	}

	/**
	 * wp_site_push.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_push( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-push.php';
		return AB_MCP_Sync_Push::call( $a );
	}

	/**
	 * Whether a call of wp_site_revert needs the token: a strict false for
	 * next and status only.
	 *
	 * @param array $a Arguments.
	 * @return bool
	 */
	public static function revert_when( array $a ) {
		return ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'status' ), true ) );
	}

	/**
	 * The state a token of wp_site_revert is bound to.
	 *
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function revert_state( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-revert.php';
		return AB_MCP_Sync_Revert::state( $a );
	}

	/**
	 * The preview of wp_site_revert plan.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function revert_preview( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-revert.php';
		return AB_MCP_Sync_Revert::preview( $a );
	}

	/**
	 * wp_site_revert.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function site_revert( array $a ) {
		require_once dirname( __DIR__ ) . '/sync/class-sync-revert.php';
		return AB_MCP_Sync_Revert::call( $a );
	}

	/**
	 * wp_set_preview_protection.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function set_preview_protection( array $a ) {
		$enabled = array_key_exists( 'enabled', $a ) ? self::b( $a, 'enabled' ) : null;
		$renew   = self::b( $a, 'new_password' );
		$user_id = get_current_user_id();
		$state   = AB_MCP_Preview_Protection::state();

		$changed    = false;
		$password   = '';
		$page_cache = null;

		if ( false === $enabled ) {
			if ( $state['on'] ) {
				$off        = AB_MCP_Preview_Protection::disable( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
				$changed    = $off['changed'];
				$page_cache = $off['page_cache'];
			}
		} elseif ( true === $enabled && ! $state['on'] ) {
			$on         = AB_MCP_Preview_Protection::enable( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
			$changed    = $on['changed'];
			$password   = $on['password'];
			$page_cache = $on['page_cache'];
		} elseif ( $renew ) {
			// On, or asked to stay on: a new password.
			$new = AB_MCP_Preview_Protection::renew_password( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
			if ( null === $new ) {
				return new WP_Error(
					'ab_mcp_preview_off',
					AB_MCP_Guidance::compose(
						__( 'The preview protection is off, so there is no password to renew.', 'alphabridge-mcp' ),
						__( '1. Switch it on with enabled=true, which sets a password and returns it once. 2. Pass the password on to whoever should see the preview.', 'alphabridge-mcp' ),
						AB_MCP_Guidance::settings_url( AB_MCP_Preview_Protection::ANCHOR )
					)
				);
			}
			$changed  = $new['changed'];
			$password = $new['password'];
		}

		$out            = AB_MCP_Preview_Protection::summary();
		$out['changed'] = $changed;
		if ( '' !== $password ) {
			$out['password']      = $password;
			$out['password_note'] = 'Shown this once; only its hash is stored. Visitors enter it on the password page and are remembered for 14 days; a new password signs them all out.';
		} elseif ( $out['enabled'] ) {
			$out['password_note'] = 'The password stays as it was and is not shown again; new_password=true sets a new one and returns it once.';
		}
		if ( null !== $page_cache ) {
			$out['page_cache'] = $page_cache;
		}
		$out['not_covered'] = AB_MCP_Preview_Protection::limits();
		if ( $out['enabled'] && is_array( $out['loader'] ) && ! $out['loader']['written'] ) {
			$out['not_covered'][] = AB_MCP_Preview_Protection::loader_missing_note();
			$out['loader_source'] = AB_MCP_Preview_Protection::loader_source();
		}
		return $out;
	}
}
