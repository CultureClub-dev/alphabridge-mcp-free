<?php
/**
 * The lists of the rules of the guard of the read budget (R12b, part 2.2;
 * the rules are tests/ReadBudgetRules.php, the guard
 * tests/ReadBudgetGuardTest.php). Kept by hand, each entry with its reason.
 *
 * ATOMIC: the eight seams of one step of the disk each (section 3.1 of the
 * order; Protected_Store_Calls::read() is composed and none of them).
 * CHECKS: each check of G7 and what it answers at a halt (wp: true or a
 * WP_Error, the halt where it is not true; true: true at a halt; false:
 * false at a halt). FACHWERT: the functions that answer «not read» at a
 * halt with a value (null, false, '' or a marker) instead of the halt, whose
 * callers ask stop() next (R7): the answer as the PHP expression its branches
 * at a halt return (compared token by token, part 2.3a), and the reason
 * from its doc comment (E19). TRANSLATIONS: the functions that answer a
 * halt held in a variable with a translation (the function named, called
 * with the code of the halt first and its text), each with its place
 * (part 2.3a, Codex A1). COMPARE: what a comparison of a sort may
 * call (N2). SORTS: the functions of PHP that sort with a comparison.
 * FILTERS: the functions that call code not of the plugin by a hook.
 * FRAME_OUT: the calls of the frame of R12 that the reading does not follow
 * (R6), each with the reason it lies outside the scope (section 2 of the
 * order, «weiter offen»).
 *
 * OPEN: each violation of the rules at the head after part 1a (part 2.2),
 * by function in the order of its source: 'Rn what', the numbers of
 * belege/stoppfall-r12-f2-einordnung.txt (TAB) it answers among the 95 open
 * outside the chain (belege/r12b-offen-ausserhalb.txt), and the reason.
 * Each part of the build strikes what it mends; from the end of part 5 OPEN
 * is empty (section 3.2, «Übergang»).
 *
 * NAMED: the named boundaries (section 2 and 3.2 of the order), separate
 * and closed: the sorts outside the chain (one call each, their comparison
 * pure, R5), the filters, and the chain of the check of names with its
 * counts at the head after part 1a.
 *
 * @package AlphaBridge_MCP
 */
return array(
	'ATOMIC'    => array(
		'AB_MCP_Protected_Store_Calls::lstat',
		'AB_MCP_Protected_Store_Calls::scandir',
		'AB_MCP_Sync_File_Manifest_Calls::names',
		'AB_MCP_Sync_File_Manifest_Calls::lstat',
		'AB_MCP_Sync_File_Manifest_Calls::open',
		'AB_MCP_Sync_File_Manifest_Calls::fstat',
		'AB_MCP_Sync_File_Manifest_Calls::read',
		'AB_MCP_Sync_Env_Calls::head_text',
	),
	'CHECKS'    => array(
		'AB_MCP_Sync_Deadline::left'        => 'wp',
		'AB_MCP_Payload_File::left'         => 'wp',
		'AB_MCP_Protected_Store::read_gate' => 'wp',
		'AB_MCP_Shape::stopped'             => 'true',
		'AB_MCP_Sync_Canon::stopped'        => 'true',
		'AB_MCP_Sync_File_Manifest::due'    => 'true',
		'AB_MCP_Sync_File_Manifest::left'   => 'false',
	),
	'FACHWERT'  => array(
		'AB_MCP_Protected_Store::entries'    => array( 'array()', 'class-protected-store.php:4173: once the deadline is over, whole is null (set before the return) and the caller passes the stop on.' ),
		'AB_MCP_Protected_Store::holds'      => array( 'false', 'Part 2.3a (E19 applied, E21): class-protected-store.php:749-751: false under a deadline that is over, the caller passes the stop on.' ),
		'AB_MCP_Protected_Store::read_tree'  => array( 'null', 'class-protected-store.php:2526: where the deadline is over first, the reading is stopped and the caller passes its stop on.' ),
		'AB_MCP_Protected_Store::sidecar'    => array( "array( 'unknown', null )", 'class-protected-store.php:4601: read only while the deadline passes the stop on; else unknown.' ),
		'AB_MCP_Protected_Store::way'        => array( 'null', 'Part 2.3a (E19 applied, E21): class-protected-store.php:4413-4414: once the deadline is over, null, and the caller passes the stop on.' ),
		'AB_MCP_Shape::decoded'              => array( 'null', 'Part 2.3a (E19 applied, E21): class-shape.php:367 (deadline: «See read()»), read() class-shape.php:73-75: the reading ends as null; read() returns it whole (class-shape.php:109).' ),
		'AB_MCP_Shape::fields'               => array( 'null', 'Part 2.3a (E19 applied, E21): class-shape.php:329 (deadline: «See read()»), read() class-shape.php:73-75: the reading ends as null; read() returns it whole.' ),
		'AB_MCP_Shape::items'                => array( 'null', 'Part 2.3a (E19 applied, E21): class-shape.php:281 (deadline: «See read()»), read() class-shape.php:73-75: the reading ends as null; read() returns it whole.' ),
		'AB_MCP_Shape::pairs'                => array( 'null', 'Part 2.3a (E19 applied, E21): class-shape.php:305 (deadline: «See read()»), read() class-shape.php:73-75: the reading ends as null; read() returns it whole.' ),
		'AB_MCP_Shape::read'                 => array( 'null', 'class-shape.php:83: with a deadline the reading ends as null and its caller passes the stop on.' ),
		'AB_MCP_Store_Inputs::read'          => array( 'null', 'Part 2.3a (E19 applied, E21): class-store-inputs.php:1137 (deadline: «AB_MCP_Shape::read()»): the value of AB_MCP_Shape::read() returned whole; sidecar() asks the stop next (class-protected-store.php:4603).' ),
		'AB_MCP_Sync_Canon::decode'          => array( 'null', 'class-sync-canon.php:771: each element revived only while the deadline is not over, else null.' ),
		'AB_MCP_Sync_Canon::encode'          => array( "''", 'class-sync-canon.php:722: once the deadline is over, \'\' and the caller passes the stop on.' ),
		'AB_MCP_Sync_Canon::prepared'        => array( 'null', 'class-sync-canon.php:928: each element only while the deadline is not over (null at a halt, encode() asks the stop).' ),
		'AB_MCP_Sync_Canon::revived'         => array( 'null', 'Part 2.3a (E19 applied, E21): class-sync-canon.php:783: $ok cleared where the deadline is over (class-sync-canon.php:812: $ok = false, null).' ),
		'AB_MCP_Sync_Code_Stage::state_of'   => array( 'null', 'class-sync-code-stage.php:1503: null, also under a deadline that is over, whose stop the caller passes on.' ),
		'AB_MCP_Sync_Code_Swap::heads'       => array( 'array()', 'Part 2.3a (E19 applied, E21): class-sync-code-swap.php:1012-1014: once the deadline is over, none, and not_down() passes the stop on.' ),
		'AB_MCP_Sync_Env::head_fields'       => array( 'null', 'class-sync-env.php:1017: null only under a deadline that is over.' ),
		'AB_MCP_Sync_Env::head_read'         => array( 'null', 'Part 2.3a (E19 applied, E21): class-sync-env.php:972-973: once the deadline is over, null, and the caller passes the stop on.' ),
		'AB_MCP_Sync_File_Manifest::text'    => array( "''", 'class-sync-file-manifest.php:379: \'\' where the deadline is over; the caller passes the stop on.' ),
	),
	'TRANSLATIONS' => array(
		'AB_MCP_Sync_Code_Swap::signed_stage' => array( 'AB_MCP_Sync_Code_Swap::refusal', 'Part 2.3a (Codex A1): class-sync-code-swap.php:943-946: the halt of signed() (or tree(), ready()) translated by refusal() with its code and its text first (the order, section 4: translations pass the halt on).' ),
	),
	'COMPARE'   => array( 'strcmp' ),
	'SORTS'     => array( 'usort', 'uasort', 'uksort' ),
	'FILTERS'   => array( 'apply_filters', 'apply_filters_ref_array', 'do_action' ),
	'FRAME_OUT' => array(
		'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Stage::target'             => 'The frame before the deadline is formed in checked() (R12 Z. 36–38: no limit on all work of prepare()).',
		'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::take'                => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::release'             => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::prepare|AB_MCP_Sync_Code_Swap::with_kept'           => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Ops::released'         => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap::calls'           => 'The frame before the deadline is formed in checked(): the seam of the probe (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap_Calls::probe_enabled' => 'The frame before the deadline is formed in checked(): the probe (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap::invalidate'      => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap::php_file'        => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap::probe_before'    => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::under_locks|AB_MCP_Sync_Code_Swap::time_left'       => 'The frame before the deadline is formed in checked() (R12 Z. 36–38).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::unchanged'           => 'The live reading with its own deadline min( until - keep, now + UNIT_BUDGET ) (the order, section 2, «weiter offen»; class-sync-code-swap.php:853–874).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::calls'               => 'The seam of the self-check and of the core lock (the order, section 2, «weiter offen»).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap_Calls::own'           => 'The self-check (the order, section 2, «weiter offen»; follow-up V6).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::lock_held'           => 'The readings of the lock and the note (the order, section 2, «weiter offen»).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap_Calls::core_held'     => 'The readings of the lock and the note (the order, section 2, «weiter offen»).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::lock_unknown'        => 'The readings of the lock and the note: the answer where the lock is unknown (the order, section 2, «weiter offen»).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::device'              => 'The rest of the work of checked() after not_down() (the order, section 2, «weiter offen»; class-sync-code-swap.php:685–687).',
		'AB_MCP_Sync_Code_Swap::checked|AB_MCP_Sync_Code_Swap::time_left'           => 'The final check of the swap (class-sync-code-swap.php:699; R12, unchanged by the order).',
		'AB_MCP_Sync_Code_Swap::read_deadline|AB_MCP_Protected_Store::proof'        => 'BOUNDARY: proof() decides the store before the deadline is formed (ROH 1a; the order, section 3.2, G1).',
	),
	'OPEN'      => array(
		'AB_MCP_Guidance::settings_url' => array(
			array( 'R1 admin_url', array( 350 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Payload_File::name' => array(
			array( 'R1 sha1', array( 181, 190, 193, 199 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Payload_File::read' => array(
			array( 'R1 is_file', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 filesize', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 file_get_contents', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 gzdecode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 hash', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Protected_Store::candidates' => array(
			array( 'R1 wp_upload_dir', array( 180, 185, 188, 192, 198 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
			array( 'R1 hash', array( 202 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Protected_Store::entries' => array(
			array( 'R7 AB_MCP_Protected_Store::way', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 363 H ja).' ),
			array( 'R7 AB_MCP_Store_Inputs::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 204 F ja; TAB 364 H ja).' ),
			array( 'R4 seam AB_MCP_Protected_Store_Calls::scandir', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): the branch at the halt of the ternary (null === $names) answers $out, an array() by an earlier statement, not the answer array() of FACHWERT (the rules read no data flow); the part that changes it returns array(). None of the 95 (TAB 204 F ja; TAB 364 H ja).' ),
			array( 'R1 usort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95 (TAB 158 CB Grenze).' ),
		),
		'AB_MCP_Protected_Store::entry' => array(
			array( 'R7 AB_MCP_Protected_Store::way', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 189 F ja; TAB 357 H ja).' ),
		),
		'AB_MCP_Protected_Store::files' => array(
			array( 'R7 AB_MCP_Protected_Store::group', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 193 F nein; TAB 194 F ja; TAB 359 H ja).' ),
			array( 'R1 scandir', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95 (TAB 13 S ja).' ),
			array( 'R1 filesize', array( 195 ), 'Direct step (Platte): through the helper of the steps (part 3).' ),
			array( 'R1 ksort', array( 196 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Protected_Store::get' => array(
			array( 'R7 AB_MCP_Protected_Store::group', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 181 F nein; TAB 182 F ja; TAB 353 H ja).' ),
			array( 'R1 lstat', array( 183 ), 'Direct step (Platte): through the helper of the steps (part 3).' ),
			array( 'R7 AB_MCP_Payload_File::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 184 F ja; TAB 354 H ja).' ),
		),
		'AB_MCP_Protected_Store::group' => array(
			array( 'R7 AB_MCP_Protected_Store::way', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 367 H ja).' ),
			array( 'R7 AB_MCP_Protected_Store::read_entry', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 208 F ja; TAB 368 H ja).' ),
			array( 'R7 AB_MCP_Protected_Store::way', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 209 F ja; TAB 369 H ja).' ),
			array( 'R1 lstat', array( 210 ), 'Direct step (Platte): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Protected_Store::groups' => array(
			array( 'R7 AB_MCP_Protected_Store::way', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 186 F ja; TAB 355 H ja).' ),
		),
		'AB_MCP_Protected_Store::holds' => array(
			array( 'R1 stat', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 realpath', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 stat', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
		),
		'AB_MCP_Protected_Store::plain' => array(
			array( 'R1 lstat', array( 183 ), 'Direct step (Platte): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Protected_Store::read_entry' => array(
			array( 'R7 AB_MCP_Protected_Store::sidecar', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 370 H ja).' ),
		),
		'AB_MCP_Protected_Store::state' => array(
			array( 'R2 foreach ( array_keys( $out[\'seen\'] ) as $where )', array( 14 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Protected_Store::stored' => array(
			array( 'R1 wpdb->get_var', array( 197 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
			array( 'R1 maybe_unserialize', array( 203 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Protected_Store::tree' => array(
			array( 'R7 AB_MCP_Protected_Store::group', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 199 F nein; TAB 200 F ja; TAB 360 H ja).' ),
		),
		'AB_MCP_Protected_Store::way' => array(
			array( 'R2 foreach ( $parts as $part )', array( 16 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R7 AB_MCP_Protected_Store::holds', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 207 F ja; TAB 366 H ja).' ),
		),
		'AB_MCP_Protected_Store::whole' => array(
			array( 'R2 foreach ( self::SIDECAR[ $e[\'area\'] ] as $field => $type )', array( 17 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Protected_Store_Calls::read' => array(
			array( 'R4 seam AB_MCP_Protected_Store_Calls::lstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). None of the 95.' ),
			array( 'R1 file_get_contents', array( 179 ), 'Direct step (Platte): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Redactor::extra_keys' => array(
			array( 'R2 foreach ( is_array( $keys ) ? $keys : array() as $key )', array( 19 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Redactor::is_credential_key' => array(
			array( 'R2 foreach ( self::CREDENTIAL_NEEDLES as $needle )', array( 18 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Redactor::redacts_key' => array(
			array( 'R2 foreach ( self::CREDENTIAL_NEEDLES as $needle )', array( 20 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Settings::get' => array(
			array( 'R1 get_option', array( 224, 225, 226, 230 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Shape::decoded' => array(
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 json_encode', array( 221 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Shape::fields' => array(
			array( 'R7 AB_MCP_Shape::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 380 H ja).' ),
		),
		'AB_MCP_Shape::is_list' => array(
			array( 'R2 foreach ( array_keys( $value ) as $key )', array( 25 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Shape::items' => array(
			array( 'R7 AB_MCP_Shape::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 377 H ja).' ),
		),
		'AB_MCP_Shape::pairs' => array(
			array( 'R3 after AB_MCP_Shape::read', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R7 AB_MCP_Shape::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 378 H ja).' ),
			array( 'R3 before AB_MCP_Shape::read', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
		),
		'AB_MCP_Shape::read' => array(
			array( 'R2 foreach ( self::PROPERTIES[ $type ] as $property )', array( 24 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R3 before AB_MCP_Shape::items', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R3 before AB_MCP_Shape::pairs', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R3 before AB_MCP_Shape::fields', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R3 before AB_MCP_Shape::decoded', array( 3 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
		),
		'AB_MCP_Sync_Anchor::pair_print' => array(
			array( 'R1 home_url', array( 228, 229 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
			array( 'R1 wp_json_encode', array( 231 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 hash', array( 232 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Anchor::read' => array(
			array( 'R1 get_option', array( 313 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
		),
		'AB_MCP_Sync_Anchor::retired' => array(
			array( 'R1 hash', array( 227 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Anchor::retired_list' => array(
			array( 'R1 get_option', array( 227 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
			array( 'R2 foreach ( is_array( $raw ) ? $raw : array() as $hash )', array( 29 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_Canon::decode' => array(
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R7 AB_MCP_Sync_Canon::revived', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 236 F nein; TAB 386 H ja).' ),
		),
		'AB_MCP_Sync_Canon::encode' => array(
			array( 'R1 json_encode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Canon::prepared' => array(
			array( 'R1 base64_encode', array( 223, 274, 311 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 bin2hex', array( 239 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 pack', array( 239 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R3 after AB_MCP_Sync_Canon::prepared', array( 388 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R7 AB_MCP_Sync_Canon::prepared', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 388 H nein).' ),
			array( 'R1 json_encode', array( 240 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 usort', array( 241 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
			array( 'R7 AB_MCP_Sync_Canon::prepared', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 389 H ja).' ),
			array( 'R1 bin2hex', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R3 before AB_MCP_Sync_Canon::prepared', array( 242 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R3 after AB_MCP_Sync_Canon::prepared', array( 390 ), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4).' ),
			array( 'R7 AB_MCP_Sync_Canon::prepared', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 242 F nein; TAB 390 H nein).' ),
			array( 'R1 ksort', array( 243 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Canon::revived' => array(
			array( 'R1 base64_decode', array( 236 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 base64_encode', array( 237 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R7 AB_MCP_Sync_Canon::revived', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 387 H ja).' ),
		),
		'AB_MCP_Sync_Code_Stage::ready' => array(
			array( 'R7 AB_MCP_Sync_Code_Stage::state', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 395 H ja).' ),
			array( 'R7 AB_MCP_Sync_Code_Stage::foreign', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 245 F ja; TAB 396 H ja).' ),
		),
		'AB_MCP_Sync_Code_Stage::signed' => array(
			array( 'R1 ksort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R2 foreach ( $parts as $seq => $list )', array( 37 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_Code_Stage::state' => array(
			array( 'R7 AB_MCP_Protected_Store::get', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 249 F ja; TAB 401 H ja).' ),
			array( 'R7 AB_MCP_Sync_Code_Stage::state_of', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 250 F ja; TAB 402 H ja).' ),
		),
		'AB_MCP_Sync_Code_Stage::state_of' => array(
			array( 'R7 AB_MCP_Store_Inputs::read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 403 H ja).' ),
		),
		'AB_MCP_Sync_Code_Swap::heads' => array(
			array( 'R1 sort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Code_Swap::not_down' => array(
			array( 'R2 foreach ( self::heads( $kind, $target, $live, $deadline ) as $file )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). None of the 95 (TAB 40 S ja; TAB 409 H ja).' ),
			array( 'R7 AB_MCP_Sync_Code_Swap::heads', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 40 S ja; TAB 409 H ja).' ),
			array( 'R7 AB_MCP_Sync_Env::head_read', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 254 F ja; TAB 410 H ja).' ),
		),
		'AB_MCP_Sync_Code_Swap::prepare' => array(
			array( 'R7 AB_MCP_Sync_Code_Swap::under_locks', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95.' ),
		),
		'AB_MCP_Sync_Code_Swap::signed_stage' => array(
			array( 'R7 AB_MCP_Sync_Code_Stage::ready', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 406 H ja).' ),
			array( 'R7 AB_MCP_Protected_Store::tree', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 252 F ja; TAB 407 H ja).' ),
		),
		'AB_MCP_Sync_Env::hashed' => array(
			array( 'R1 hash', array( 275, 277, 279 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Env::head_read' => array(
			array( 'R1 is_file', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 is_readable', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
			array( 'R1 filesize', array(), 'Direct step (Platte): through the helper of the steps (part 3). None of the 95.' ),
		),
		'AB_MCP_Sync_Env::held_here' => array(
			array( 'R1 hash', array( 274 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R4 entry AB_MCP_Sync_Env::held', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). None of the 95 (TAB 276 F ja; TAB 413 H ja).' ),
		),
		'AB_MCP_Sync_Env::names_held' => array(
			array( 'R4 entry AB_MCP_Sync_Env::held', array( 279 ), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries).' ),
			array( 'R2 foreach ( array( \'stylesheet\', \'template\' ) as $field )', array( 78 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R4 entry AB_MCP_Sync_Env::held', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). None of the 95 (TAB 278 F entfällt).' ),
			array( 'R2 foreach ( array( \'mains\', \'active\' ) as $field )', array( 79 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_Env::reduced' => array(
			array( 'R1 ksort', array( 273 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_File_Manifest::file' => array(
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::open', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::file is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::fstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::file is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R1 hash_init', array( 289 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R2 while ( true )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::file is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95 (TAB 83 S ja).' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::read', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::file is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R1 hash_update', array( 290 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::fstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::file is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R1 hash_final', array( 291 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_File_Manifest::folder' => array(
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::names', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::folder is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R1 usort', array( 286 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
			array( 'R2 foreach ( $names as $name )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::folder is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95 (TAB 82 S ja).' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::lstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::folder is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R3 before AB_MCP_Sync_File_Manifest::folder', array(), 'Recursion: a check before the edge, a halt query before the next work after its return (part 5; A4 part 4). None of the 95 (TAB 287 F ja; TAB 427 H ja).' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::folder', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 287 F ja; TAB 427 H ja).' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::file', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 288 F entfällt; TAB 429 H ja).' ),
		),
		'AB_MCP_Sync_File_Manifest::same' => array(
			array( 'R2 foreach ( self::IDENTITY as $field )', array( 86 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_File_Manifest::still' => array(
			array( 'R2 foreach ( $acc[\'dirs\'] as $dir => $names )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::still is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95 (TAB 84 S ja).' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::names', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::still is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
			array( 'R1 usort', array( 292 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
			array( 'R2 foreach ( $acc[\'stats\'] as $file => $stat )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::still is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95 (TAB 85 S ja).' ),
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::lstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers the marker \'ended\', and AB_MCP_Sync_File_Manifest::still is not in FACHWERT (its doc comment does not name the answer at a halt, E19; the marker form is open 2 of part 2.2). None of the 95.' ),
		),
		'AB_MCP_Sync_File_Manifest::unit' => array(
			array( 'R7 AB_MCP_Sync_File_Manifest::whole', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 414 H ja).' ),
		),
		'AB_MCP_Sync_File_Manifest::whole' => array(
			array( 'R4 seam AB_MCP_Sync_File_Manifest_Calls::lstat', array(), 'An entry into the chain or a call of a seam of the disk not right after a check with its halt passed on (part 3: seams; part 5: entries). New in part 2.3a (Codex A1): its branch at a halt answers $acc, and AB_MCP_Sync_File_Manifest::whole is not in FACHWERT (its doc comment does not name the answer at a halt, E19). None of the 95.' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::folder', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 280 F ja; TAB 415 H entfällt; TAB 416 H ja).' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::file', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 281 F entfällt; TAB 417 H ja).' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::still', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 282 F ja; TAB 419 H ja).' ),
			array( 'R1 usort', array( 283 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
			array( 'R7 AB_MCP_Sync_File_Manifest::text', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. New in part 2.3a (Codex A1): after text() (FACHWERT) the branch at the halt calls late() first (AT, a helper of the clock, no cleanup) and answers $acc; AB_MCP_Sync_File_Manifest::whole is not in FACHWERT (E19). None of the 95 (TAB 284 F ja; TAB 420 H ja).' ),
			array( 'R1 hash', array( 285 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Import::connection' => array(
			array( 'R1 hash', array( 300 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Import::held_list' => array(
			array( 'R7 AB_MCP_Protected_Store::get', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 302 F nein; TAB 303 F ja; TAB 452 H ja).' ),
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Import::held_name' => array(
			array( 'R2 foreach ( str_split( (string) hex2bin( $sha ) ) as $byte )', array( 93 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R1 hex2bin', array( 302 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R2 foreach ( str_split( $bits, 5 ) as $five )', array( 94 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_Import::held_sha' => array(
			array( 'R2 foreach ( str_split( substr( $name, strlen( self::HELD ) ) ) as $letter )', array( 95 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R2 foreach ( str_split( substr( $bits, 0, 256 ), 4 ) as $four )', array( 96 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
		),
		'AB_MCP_Sync_Import::inflate' => array(
			array( 'R1 inflate_init', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 inflate_add', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 inflate_add', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Import::lines' => array(
			array( 'R2 foreach ( $manifest[\'chunks\'] as $i => $chunk )', array(), 'The body of the loop does not begin with a check that leaves at a halt (part 5). None of the 95 (TAB 89 S ja).' ),
			array( 'R7 AB_MCP_Sync_Import::inflate', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 299 F ja; TAB 447 H ja).' ),
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Import::record' => array(
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 json_decode', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Import::taken' => array(
			array( 'R7 AB_MCP_Protected_Store::get', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 295 F ja; TAB 296 F ja; TAB 443 H ja).' ),
			array( 'R1 hash', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95 (TAB 295 F ja; TAB 296 F ja; TAB 443 H ja).' ),
		),
		'AB_MCP_Sync_Key::fingerprint' => array(
			array( 'R1 hash', array( 305 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Key::from_base64' => array(
			array( 'R1 base64_decode', array( 233, 234, 309, 315 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Key::key_id' => array(
			array( 'R1 hash', array( 304 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Key::site_form' => array(
			array( 'R1 idn_to_ascii', array( 229, 306, 317, 318, 319, 321, 322, 459 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Key::to_base64' => array(
			array( 'R1 base64_encode', array( 307 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
		),
		'AB_MCP_Sync_Manifest::shape' => array(
			array( 'R1 sort', array( 312 ), 'Direct step (Sortierung): through the helper of the steps (part 4).' ),
			array( 'R2 foreach ( $checks as $field => $ok )', array( 101 ), 'The body of the loop does not begin with a check that leaves at a halt (part 5).' ),
			array( 'R1 sort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95.' ),
			array( 'R1 sort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Manifest::verify' => array(
			array( 'R1 sort', array(), 'Direct step (Sortierung): through the helper of the steps (part 4). None of the 95 (TAB 308 F entfällt).' ),
			array( 'R1 json_decode', array( 310 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R7 AB_MCP_Sync_Manifest::shape', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 312 F nein; TAB 454 H ja).' ),
			array( 'R1 sodium_crypto_sign_verify_detached', array( 315, 458 ), 'Direct step (Codec): through the helper of the steps (part 4).' ),
			array( 'R1 home_url', array( 316 ), 'Direct step (Datenbank): through the helper of the steps (part 3).' ),
			array( 'R1 hash', array(), 'Direct step (Codec): through the helper of the steps (part 4). None of the 95.' ),
		),
		'AB_MCP_Sync_Report::environment' => array(
			array( 'R7 AB_MCP_Sync_Import::taken', array(), 'The halt of the call not passed on by the next statement (is_wp_error() or true !== and return, or FACHWERT and stop()); with the part that changes the function. None of the 95 (TAB 455 H ja).' ),
		),
	),
	'NAMED'     => array(
		'SORT'   => array(
			'AB_MCP_Protected_Store::entries'   => array( array( 'usort', 158, 'The sort of the sidecars by their time (class-protected-store.php:4209): one call; its comparison only compares (R5).' ) ),
			'AB_MCP_Sync_Canon::prepared'       => array( array( 'usort', 160, 'The sort of the items of a set by their text (class-sync-canon.php:953): one call; its comparison only compares with strcmp() (R5).' ) ),
			'AB_MCP_Sync_File_Manifest::folder' => array( array( 'usort', 166, 'The sort of the names of a folder with strcmp() (class-sync-file-manifest.php:461): one call (R5).' ) ),
			'AB_MCP_Sync_File_Manifest::still'  => array( array( 'usort', 168, 'The sort of the names of a folder with strcmp() (class-sync-file-manifest.php:630): one call (R5).' ) ),
			'AB_MCP_Sync_File_Manifest::whole'  => array( array( 'usort', 164, 'The sort of the entries by their path (class-sync-file-manifest.php:334): one call; its comparison only compares with strcmp() (R5).' ) ),
		),
		'FILTER' => array(
			'AB_MCP_Redactor::extra_keys' => array( array( 'apply_filters', 0, 'The filter ab_mcp_redact_keys calls code not of the plugin (class-redactor.php:330; a boundary of the core, the order N2); its keys are checked in the loop that follows, one pass at a time (R2, Nr. 19).' ) ),
		),
		'CHAIN'  => array(
			'functions'  => 93,
			'steps'      => 32,
			'loops'      => 97,
			'recursions' => 6,
			'why'        => 'The check of one name (the decision of 14.11.2026 for option 4; the order, section 1 (b)): a begun check of a name runs to its end without a check; the halt is checked before each call of AB_MCP_Sync_Env::held(). Counts at the head after part 1a; at 8daf33f (earlier state) 80 functions, 31 steps, 141 open sites (TAB, chain = ja). The sort Nr. 159 (class-replace-engine.php:227) lies in it.',
		),
	),
);
