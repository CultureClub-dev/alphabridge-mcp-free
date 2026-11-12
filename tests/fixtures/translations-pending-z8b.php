<?php
/**
 * The texts of Z8b branch 2 that wait for their translation: the order of
 * Z8b says «Texte englisch mit Textdomain; Übersetzungen erst mit
 * text/z8b». BundledTranslationsTest leaves these keys out of its check
 * of the nine languages and checks the list itself instead: every key is
 * a text the code translates, under includes/sync/, and untranslated in
 * all nine languages. A key text/z8b translates leaves the list in the
 * same change; the list ends empty and is removed with text/z8b.
 *
 * @package AlphaBridge_MCP
 */

return array(
	// includes/sync/class-sync-core-compare.php
	'The answer of api.wordpress.org holds an entry that is no path with its md5. Try again later: make the report again (wp_site_diff).',
	'The answer of api.wordpress.org holds no checksums. Try again later: make the report again (wp_site_diff).',
	'api.wordpress.org did not answer with the checksums. Try again later: make the report again (wp_site_diff).',
	'api.wordpress.org knows no checksums of this version and language. Update WordPress to a released version (wp_update_core), then make the report again (wp_site_diff).',
	'api.wordpress.org was not reached. Check that the host lets this site ask api.wordpress.org over https, then make the report again (wp_site_diff).',
	// includes/sync/class-sync-pairs.php
	'Only the step that wrote and checked the part %1$s of a pair of kind %2$s moves its bases on: the kind responsible for it by AB_MCP_Sync_Kinds::OWNERSHIP, registered as such. Nothing was written. Pass the kind registered for the part, and call again.',
	'The field base_extra of the pair is not stored JSON of a pair.',
	'The pair of kind %2$s has no part %1$s whose bases could be moved on: a unit takes new bases part by part (files, activation), an object of the takeover without a part. Nothing was written. Pass a part of the pair (AB_MCP_Sync_Pairs::PARTS), or none for an object, and call again.',
	// includes/sync/class-sync-said.php
	'A file of the font library comes with the fonts of the font library.',
	'A file of the font library is compared by its contents; it comes with the fonts of the font library.',
	'A file of the uploads outside the file lists of attachments is compared by its contents and taken over with the files of the site.',
	'A file of the uploads outside the file lists of attachments is taken over with the files of the site.',
	'A language file is compared by its contents and taken over with the files of the site.',
	'A language file is taken over with the files of the site.',
	'A must-use file or folder is compared by the contents of its files and taken over with the files of the site.',
	'A must-use file or folder is taken over with the files of the site.',
	'A part of a unit that no kind of the code takes over in this version.',
	'A path only on this site is not in the version of the staging site: a takeover puts the old folder whole into the backup, so the path leaves its place and comes back only through the way back.',
	'A plugin is code: its files are compared by their contents and whether it runs by its main files; it is taken over with the code of the site.',
	'A theme is code: its files are compared by their contents; it is taken over with the code of the site.',
	'AlphaBridge itself is shown, never taken over: bring both sites to the same version of AlphaBridge (wp-admin, Plugins → Update), then make a new report.',
	'AlphaBridge itself, in another version on each side: shown by its version only, never taken over.',
	'AlphaBridge itself, the same version on both sides: shown by its version only, never taken over.',
	'Changed on both sides alike since the base of its pair: nothing to take over.',
	'Changed on both sides since the base of its pair, differently.',
	'Changed on staging since the base of its pair, unchanged on this site.',
	'Changed on this site since the base of its pair, unchanged on staging: kept and shown.',
	'Differs between the sides, and it has no pair: which side changed is not known until a person confirms a common base for its pair.',
	'Differs: core files of a site are not those api.wordpress.org gives for its version and language. WordPress itself is never taken over: install it anew at the host or in wp-admin (Dashboard → Updates → Re-install).',
	'Each list of paths shows its first 100 here; the others follow, in their order, in the entries right after this one (result more_paths).',
	'Equal on both sides: the core files of each site are those api.wordpress.org gives for its version and language.',
	'Equal on both sides: the same files by their contents, and for a plugin the same main files running.',
	'Further paths of the unit of the entry before: the list they belong to (only_staging, only_live or differs), the number of the first of them in that list, and up to 100 paths in their order.',
	'It could not be read whole on %1$s (%2$s).',
	'It differs in its files and in which of its main files run.',
	'It differs in its files.',
	'It differs in which of its main files run, or in which theme runs.',
	'Its pair holds no base of the part %s.',
	'Its pair is %s: a person confirms a new alignment first (wp_site_pairs).',
	'Not compared: see why.',
	'Nothing of it is taken over in this version.',
	'Removed on staging since its pair was made, and changed on this site since its base: a conflict, kept on this site.',
	'Removed on staging since its pair was made.',
	'Removed on this site since its pair was made, and changed on staging since its base: a conflict, the removal holds here; nothing of staging is brought back in this version.',
	'Removed on this site since its pair was made: nothing to do.',
	'Shown only: the language is the option WPLANG, compared with the options; its files are compared under languages.',
	'The bases of its pair are of the version %1$s of the comparison data, this site reads %2$s.',
	'The core files could not be checked on the staging site (%s).',
	'The core files could not be checked on this site (%s).',
	'The files of the unit changed on this site since the base of its pair, and on the staging site as well: the files written on this site would leave their folder, so there is no choice «staging wins». Bring both sides to the same version (the same files), then make a new report. A plugin that keeps writing into its own folder (logs, caches, backups) changes again after every write: update it by hand in wp-admin (Plugins → Update) instead.',
	'The folders at the top of uploads other than the year folders (outside the file lists of attachments) are counted and summed, their contents not compared; they are classified with the files of the site.',
	'The theme lies in a further root of themes (register_theme_directory()), not in the folder of the themes: it is never taken over. Take it over by hand.',
	'The theme that runs could not be read on the staging site.',
	'The theme that runs could not be read on this site.',
	'The unit holds on the staging site what no manifest holds (a folder .git, .svn or .hg, a symbolic link, or something that is no regular file): it is never taken over. Remove it on the staging site (or take the unit over by hand), then make a new report.',
	'The unit holds on this site what no manifest holds (a folder .git, .svn or .hg, a symbolic link, or something that is no regular file): it is never taken over. Remove it on this site (or take the unit over by hand), then make a new report.',
	'Whether it is on %1$s is not known: the folder it would lie in could not be read whole there (%2$s).',
	'Which main files run could not be read on the staging site.',
	'Which main files run could not be read on this site.',
	'Which theme runs is a phase of the code: it is taken over with the code of the site.',
	'WordPress itself is never taken over: its files are compared with the checksums of api.wordpress.org, and it is installed anew at the host or in wp-admin.',
	'WordPress itself is never taken over: where staging is newer, this site is updated first (wp_update_core).',
	'a file of it changed while it was read',
	'a folder or file of it could not be read',
	'api.wordpress.org knows no checksums of its version and language',
	'api.wordpress.org was not reached',
	'it could not be hashed within one call',
	'it holds more files than one manifest holds',
	'its files do not fit into one package',
	'its version of WordPress could not be read',
	'the answer of api.wordpress.org held no checksums',
	'the staging site',
	'the unit the call before stopped at (kept by its SHA-256 only) is gone since',
	'this site',
);
