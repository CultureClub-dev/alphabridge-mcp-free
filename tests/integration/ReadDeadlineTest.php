<?php
/**
 * The deadline of the readings of a swap with two sites (R12 of Z8b branch
 * 5, part 1: the way of the packages and of the stage), in the test process
 * so that the clock of the test reads every check (DeadlineClock as the
 * clock of the budget, of the swap and of the import, one clock):
 *
 * - AB_MCP_Sync_Code_Stage::signed() over a series of the environment B
 *   signs and A takes over through its import, built here: one package of
 *   two chunks (the only package) and two packages of two chunks each (the
 *   last package). In time the reading asks at exactly its gates (each gate
 *   with how often, and their order by its SHA-256, from a run in time and
 *   written down) and answers as without a deadline. The deadline over at
 *   any one gate (each gate by itself: the first, a middle and the last time
 *   the reading asks it; the search of the groups with its sidecars, the
 *   record, the files, the mark, each chunk read and inflated, each line, the
 *   list of held objects, then held_here(), names_held() and the putting
 *   together in signed()) stops the reading there: signed() answers with
 *   the stop in its full wording, by the same way up to that gate, without a
 *   reading of the clock and without a step of the store (a listing, a
 *   sidecar read) after it. The time running out while a sidecar is read
 *   stops the reading at the next gate; the clock unknown alike
 *   (ab_mcp_read_clock).
 * - The refusals of the readers in time stay what they are and are never
 *   the stop, and the stop is never one of them: a chunk that does not
 *   match its hash (ab_mcp_store_corrupt), a mark that does not read
 *   (ab_mcp_sync_import_incomplete), a chunk that is no gzip
 *   (ab_mcp_sync_import_unread), no list of held objects
 *   (ab_mcp_sync_import_held), a package no longer valid
 *   (ab_mcp_sync_manifest_expired); a value that is no deadline
 *   (ab_mcp_read_deadline, no reading of the clock); a store this request
 *   has not decided is not decided by a reading under a deadline
 *   (ab_mcp_store_undecided). Without a deadline each reading answers as
 *   with one in time.
 * - AB_MCP_Sync_Code_Stage::ready() over a stage built here (its group bound
 *   to a run, its tree put in, a state checking and a state ready): its
 *   files, the last state read and read in its form (the codec stops
 *   without encoding again), then foreign() with the binding and the
 *   sidecar of its group, each gate by itself as above; a stage not ready
 *   for the values asked (another hash, another run) is «not ready» (null)
 *   in time and the stop when late, never null. state() and foreign() are
 *   also read directly (they are private: through reflection), where the
 *   translations of state() (never unknown) and of foreign() (never a
 *   binding or an entry not of its form) and of entry() would speak.
 * - AB_MCP_Sync_Code_Swap::prepare() of a unit made new (its test requests
 *   answered through the seam of the swap): a store this request has not
 *   decided is decided before the deadline until − keep is made (the time
 *   running out while it is decided stops the swap at the first check of
 *   the deadline, before unchanged(), and nothing is read after it); in
 *   time the readings of the stage and of the signed manifest run under the
 *   one deadline (the gates of ready(), of tree() since part 2, and of
 *   signed()); over at the first,
 *   the last gate of ready() or of signed(), the swap answers with the stop
 *   in its full wording and the way of the swap, gives every lock back and
 *   reads nothing after it (no tree, no place, no device).
 * - The translations of what a reader below answered into a refusal of its
 *   own, each on its own level (at the entrance of signed() the report of
 *   the environment catches every stop of taken() first, so there they
 *   would not show): AB_MCP_Sync_Import::taken() of the series single,
 *   AB_MCP_Protected_Store::groups() of the area in and get() of the first
 *   chunk of that series, read directly, each gate by itself as above; and
 *   by name the gate where each translation would speak: over there, get()
 *   (never corrupt), group() (never unsafe), groups() (never unsafe or
 *   unlisted), the mark in taken() (never incomplete), lines() (never
 *   unread) and verify() (never spelling) answer the stop in its full
 *   wording.
 * - R12 part 2, the way of the tree: AB_MCP_Protected_Store::tree() of the
 *   tree of the stage and of a large tree (a file of more than one part, an
 *   empty file, an empty folder), and AB_MCP_Sync_File_Manifest::unit() of
 *   that tree read directly (its first call): in time as without a deadline
 *   (the same steps of the store and of the manifest, the same other
 *   readings of the clock, kept as steps in order), each gate by itself as
 *   above; the stop is the answer of tree(), never a tree changed or
 *   missing, and of unit() a reading stopped, never too large or
 *   unreadable. In time another hash is changed and a tree not there
 *   missing, as without a deadline; the earlier of the deadline and
 *   TREE_BUDGET ends the reading, in both orders (the deadline: the stop;
 *   TREE_BUDGET: too large, so changed).
 * - R12 part 2, the heads: AB_MCP_Sync_Env::head_read() of a main file (as
 *   without a deadline in time), and through reflection heads() and
 *   not_down() of the swap over live units built here (a plugin in a
 *   folder whose first PHP file names no plugin, a plugin of one file, a
 *   theme): in time the version its head names, each gate by itself as
 *   above; the stop of heads() is no files and of head_read() no head, and
 *   not_down() passes it on as the refusal with its code, never a version
 *   not known or a downgrade. In time no head that names a plugin, no PHP
 *   file at the top, a head read shorter than its file or not read are a
 *   version not known, an older version of the staging site a downgrade.
 *   prepare() of a unit there (update, its pair put here) reads its heads
 *   after signed() under the one deadline; over at the first or the last
 *   gate of heads(), of its heads, or at the check before the versions are
 *   compared, the swap stops as above.
 *
 * need() and stage() read without a deadline: CodeStageTest.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
use AB_MCP_Payload_File;
use AB_MCP_Protected_Store;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Code_Stage;
use AB_MCP_Sync_Code_Swap;
use AB_MCP_Sync_Deadline;
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Test_Seam;
use AlphaBridge\Tests\DeadlineClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-code-stage.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-code-swap.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-deadline.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-file-manifest.php';

final class ReadDeadlineTest extends TestCase {

	/** The deadline is over (ab_mcp_read_late), in full. */
	const LATE_TEXT = 'The time this call had for its reading ran out before the reading was done, so the reading stopped before its next step and what it read is not used. Call again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time).';

	/** The clock gave no time (ab_mcp_read_clock), in full. */
	const CLOCK_TEXT = 'The time of this call could not be measured during its reading, so the reading stopped before its next step and what it read is not used. Call again.';

	/** A deadline that is not one (ab_mcp_read_deadline), in full. */
	const DEADLINE_TEXT = 'The time limit this reading was given is not a time (a finite number of seconds), so nothing was read. This is a fault of the code that asked for the reading, not of the site: call again, and where it keeps failing, update AlphaBridge MCP or report it to its makers.';

	/** The store not decided in this request (ab_mcp_store_undecided), in full. */
	const UNDECIDED_TEXT = 'Where the protected store is was not decided yet in this request, and this reading has a time limit, so it does not decide it now (its check can take longer than the time left) and reads nothing of the store. Call again; where it keeps failing, report it to the makers of AlphaBridge MCP: the caller decides the store before such a reading.';

	/** A tree not confirmed against its hash (ab_mcp_store_tree_changed), in full. */
	const TREE_CHANGED_TEXT = 'The tree in the protected store could not be confirmed against the hash it was asked with: its manifest differs, or it could not be read whole (as when the time of the call runs out). So it is not given out. Try again; if it keeps failing, ask the host whether something else writes into the folder of the store or whether its disk is slow.';

	/** The unit of the series. */
	private const UNIT = 'ab-it-frist';

	/** The run the stage is bound to. */
	private const RUN = 7;

	/** What the swap answers after the stop of a reading (AB_MCP_Sync_Code_Swap::signed_stage(), read_deadline()), after its text. */
	private const SWAP_TAIL = ' So the plugin ab-it-frist is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.';

	/**
	 * The gates of each reading in time (DeadlineClock): each gate with how
	 * often the reading asks it, in the order it first asks it, from a run
	 * in time and written down; ORDER holds the whole order.
	 */
	const COUNTS = array(
		'single' => array(
			'AB_MCP_Sync_Report::environment#1'    => 1,
			'AB_MCP_Sync_Import::taken#1'          => 1,
			'AB_MCP_Protected_Store::groups#1'     => 1,
			'AB_MCP_Protected_Store::way#1'        => 16,
			'AB_MCP_Protected_Store::way#2'        => 23,
			'AB_MCP_Protected_Store::holds#1'      => 16,
			'AB_MCP_Protected_Store::holds#2'      => 16,
			'AB_MCP_Protected_Store::holds#3'      => 39,
			'AB_MCP_Protected_Store::entries#1'    => 1,
			'AB_MCP_Shape::items#1'                => 16,
			'AB_MCP_Protected_Store::entries#2'    => 16,
			'AB_MCP_Protected_Store::sidecar#1'    => 14,
			'AB_MCP_Protected_Store_Calls::read#1' => 14,
			'AB_MCP_Shape::decoded#1'              => 14,
			'AB_MCP_Shape::decoded#2'              => 14,
			'AB_MCP_Protected_Store::entries#3'    => 1,
			'AB_MCP_Protected_Store::groups#2'     => 7,
			'AB_MCP_Protected_Store::groups#3'     => 1,
			'AB_MCP_Sync_Import::find#1'           => 1,
			'AB_MCP_Protected_Store::files#1'      => 2,
			'AB_MCP_Protected_Store::group#1'      => 7,
			'AB_MCP_Protected_Store::files#2'      => 2,
			'AB_MCP_Protected_Store::files#3'      => 14,
			'AB_MCP_Protected_Store::files#4'      => 2,
			'AB_MCP_Protected_Store::get#1'        => 5,
			'AB_MCP_Protected_Store::get#2'        => 5,
			'AB_MCP_Payload_File::read#1'          => 5,
			'AB_MCP_Payload_File::read#2'          => 5,
			'AB_MCP_Payload_File::read#3'          => 5,
			'AB_MCP_Payload_File::read#4'          => 5,
			'AB_MCP_Payload_File::read#5'          => 5,
			'AB_MCP_Payload_File::read#6'          => 5,
			'AB_MCP_Sync_Import::record#1'         => 1,
			'AB_MCP_Sync_Import::record#2'         => 1,
			'AB_MCP_Sync_Import::taken#2'          => 1,
			'AB_MCP_Sync_Manifest::verify#1'       => 1,
			'AB_MCP_Sync_Canon::prepared#3'        => 22,
			'AB_MCP_Sync_Canon::prepared#2'        => 2,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Sync_Manifest::shape#2'        => 2,
			'AB_MCP_Sync_Manifest::verify#2'       => 1,
			'AB_MCP_Sync_Manifest::verify#3'       => 1,
			'AB_MCP_Sync_Import::inflate#1'        => 2,
			'AB_MCP_Sync_Import::inflate#2'        => 2,
			'AB_MCP_Sync_Import::inflate#3'        => 2,
			'AB_MCP_Sync_Import::lines#1'          => 5,
			'AB_MCP_Sync_Import::held_list#1'      => 5,
			'AB_MCP_Sync_Import::held_list#2'      => 1,
			'AB_MCP_Sync_Import::held_list#3'      => 1,
			'AB_MCP_Sync_Import::taken#3'          => 1,
			'AB_MCP_Sync_Env::held_here#1'         => 1,
			'AB_MCP_Sync_Env::held_here#2'         => 1,
			'AB_MCP_Sync_Env::held_here#3'         => 4,
			'AB_MCP_Sync_Env::names_held#1'        => 4,
			'AB_MCP_Sync_Env::names_held#2'        => 4,
			'AB_MCP_Sync_Env::names_held#4'        => 2,
			'AB_MCP_Sync_Env::names_held#3'        => 3,
			'AB_MCP_Sync_Env::names_held#5'        => 1,
			'AB_MCP_Sync_Report::environment#2'    => 1,
			'AB_MCP_Sync_Code_Stage::signed#1'     => 4,
			'AB_MCP_Sync_Code_Stage::signed#2'     => 3,
			'AB_MCP_Sync_Code_Stage::signed#3'     => 1,
		),
		'two'    => array(
			'AB_MCP_Sync_Report::environment#1'    => 2,
			'AB_MCP_Sync_Import::taken#1'          => 2,
			'AB_MCP_Protected_Store::groups#1'     => 2,
			'AB_MCP_Protected_Store::way#1'        => 32,
			'AB_MCP_Protected_Store::way#2'        => 46,
			'AB_MCP_Protected_Store::holds#1'      => 32,
			'AB_MCP_Protected_Store::holds#2'      => 32,
			'AB_MCP_Protected_Store::holds#3'      => 78,
			'AB_MCP_Protected_Store::entries#1'    => 2,
			'AB_MCP_Shape::items#1'                => 32,
			'AB_MCP_Protected_Store::entries#2'    => 32,
			'AB_MCP_Protected_Store::sidecar#1'    => 28,
			'AB_MCP_Protected_Store_Calls::read#1' => 28,
			'AB_MCP_Shape::decoded#1'              => 28,
			'AB_MCP_Shape::decoded#2'              => 28,
			'AB_MCP_Protected_Store::entries#3'    => 2,
			'AB_MCP_Protected_Store::groups#2'     => 14,
			'AB_MCP_Protected_Store::groups#3'     => 2,
			'AB_MCP_Sync_Import::find#1'           => 5,
			'AB_MCP_Protected_Store::files#1'      => 4,
			'AB_MCP_Protected_Store::group#1'      => 14,
			'AB_MCP_Protected_Store::files#2'      => 4,
			'AB_MCP_Protected_Store::files#3'      => 28,
			'AB_MCP_Protected_Store::files#4'      => 4,
			'AB_MCP_Protected_Store::get#1'        => 10,
			'AB_MCP_Protected_Store::get#2'        => 10,
			'AB_MCP_Payload_File::read#1'          => 10,
			'AB_MCP_Payload_File::read#2'          => 10,
			'AB_MCP_Payload_File::read#3'          => 10,
			'AB_MCP_Payload_File::read#4'          => 10,
			'AB_MCP_Payload_File::read#5'          => 10,
			'AB_MCP_Payload_File::read#6'          => 10,
			'AB_MCP_Sync_Import::record#1'         => 2,
			'AB_MCP_Sync_Import::record#2'         => 2,
			'AB_MCP_Sync_Import::taken#2'          => 2,
			'AB_MCP_Sync_Manifest::verify#1'       => 2,
			'AB_MCP_Sync_Canon::prepared#3'        => 44,
			'AB_MCP_Sync_Canon::prepared#2'        => 4,
			'AB_MCP_Sync_Canon::encode#1'          => 2,
			'AB_MCP_Sync_Manifest::shape#2'        => 4,
			'AB_MCP_Sync_Manifest::verify#2'       => 2,
			'AB_MCP_Sync_Manifest::verify#3'       => 2,
			'AB_MCP_Sync_Import::inflate#1'        => 4,
			'AB_MCP_Sync_Import::inflate#2'        => 4,
			'AB_MCP_Sync_Import::inflate#3'        => 4,
			'AB_MCP_Sync_Import::lines#1'          => 8,
			'AB_MCP_Sync_Import::held_list#1'      => 10,
			'AB_MCP_Sync_Import::held_list#2'      => 2,
			'AB_MCP_Sync_Import::held_list#3'      => 2,
			'AB_MCP_Sync_Import::taken#3'          => 2,
			'AB_MCP_Sync_Env::held_here#1'         => 2,
			'AB_MCP_Sync_Env::held_here#3'         => 6,
			'AB_MCP_Sync_Env::names_held#1'        => 6,
			'AB_MCP_Sync_Env::names_held#2'        => 6,
			'AB_MCP_Sync_Env::names_held#4'        => 2,
			'AB_MCP_Sync_Env::names_held#3'        => 3,
			'AB_MCP_Sync_Env::names_held#5'        => 2,
			'AB_MCP_Sync_Env::held_here#2'         => 1,
			'AB_MCP_Sync_Report::environment#2'    => 1,
			'AB_MCP_Sync_Code_Stage::signed#1'     => 6,
			'AB_MCP_Sync_Code_Stage::signed#2'     => 3,
			'AB_MCP_Sync_Code_Stage::signed#3'     => 1,
		),
		'taken'  => array(
			'AB_MCP_Sync_Import::taken#1'          => 1,
			'AB_MCP_Protected_Store::groups#1'     => 1,
			'AB_MCP_Protected_Store::way#1'        => 16,
			'AB_MCP_Protected_Store::way#2'        => 23,
			'AB_MCP_Protected_Store::holds#1'      => 16,
			'AB_MCP_Protected_Store::holds#2'      => 16,
			'AB_MCP_Protected_Store::holds#3'      => 39,
			'AB_MCP_Protected_Store::entries#1'    => 1,
			'AB_MCP_Shape::items#1'                => 16,
			'AB_MCP_Protected_Store::entries#2'    => 16,
			'AB_MCP_Protected_Store::sidecar#1'    => 14,
			'AB_MCP_Protected_Store_Calls::read#1' => 14,
			'AB_MCP_Shape::decoded#1'              => 14,
			'AB_MCP_Shape::decoded#2'              => 14,
			'AB_MCP_Protected_Store::entries#3'    => 1,
			'AB_MCP_Protected_Store::groups#2'     => 7,
			'AB_MCP_Protected_Store::groups#3'     => 1,
			'AB_MCP_Sync_Import::find#1'           => 1,
			'AB_MCP_Protected_Store::files#1'      => 2,
			'AB_MCP_Protected_Store::group#1'      => 7,
			'AB_MCP_Protected_Store::files#2'      => 2,
			'AB_MCP_Protected_Store::files#3'      => 14,
			'AB_MCP_Protected_Store::files#4'      => 2,
			'AB_MCP_Protected_Store::get#1'        => 5,
			'AB_MCP_Protected_Store::get#2'        => 5,
			'AB_MCP_Payload_File::read#1'          => 5,
			'AB_MCP_Payload_File::read#2'          => 5,
			'AB_MCP_Payload_File::read#3'          => 5,
			'AB_MCP_Payload_File::read#4'          => 5,
			'AB_MCP_Payload_File::read#5'          => 5,
			'AB_MCP_Payload_File::read#6'          => 5,
			'AB_MCP_Sync_Import::record#1'         => 1,
			'AB_MCP_Sync_Import::record#2'         => 1,
			'AB_MCP_Sync_Import::taken#2'          => 1,
			'AB_MCP_Sync_Manifest::verify#1'       => 1,
			'AB_MCP_Sync_Canon::prepared#3'        => 22,
			'AB_MCP_Sync_Canon::prepared#2'        => 2,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Sync_Manifest::shape#2'        => 2,
			'AB_MCP_Sync_Manifest::verify#2'       => 1,
			'AB_MCP_Sync_Manifest::verify#3'       => 1,
			'AB_MCP_Sync_Import::inflate#1'        => 2,
			'AB_MCP_Sync_Import::inflate#2'        => 2,
			'AB_MCP_Sync_Import::inflate#3'        => 2,
			'AB_MCP_Sync_Import::lines#1'          => 5,
			'AB_MCP_Sync_Import::held_list#1'      => 5,
			'AB_MCP_Sync_Import::held_list#2'      => 1,
			'AB_MCP_Sync_Import::held_list#3'      => 1,
			'AB_MCP_Sync_Import::taken#3'          => 1,
		),
		'groups' => array(
			'AB_MCP_Protected_Store::groups#1'     => 1,
			'AB_MCP_Protected_Store::way#1'        => 2,
			'AB_MCP_Protected_Store::way#2'        => 2,
			'AB_MCP_Protected_Store::holds#1'      => 2,
			'AB_MCP_Protected_Store::holds#2'      => 2,
			'AB_MCP_Protected_Store::holds#3'      => 4,
			'AB_MCP_Protected_Store::entries#1'    => 1,
			'AB_MCP_Shape::items#1'                => 16,
			'AB_MCP_Protected_Store::entries#2'    => 16,
			'AB_MCP_Protected_Store::sidecar#1'    => 7,
			'AB_MCP_Protected_Store_Calls::read#1' => 7,
			'AB_MCP_Shape::decoded#1'              => 7,
			'AB_MCP_Shape::decoded#2'              => 7,
			'AB_MCP_Protected_Store::entries#3'    => 1,
			'AB_MCP_Protected_Store::groups#2'     => 7,
			'AB_MCP_Protected_Store::groups#3'     => 1,
		),
		'get'    => array(
			'AB_MCP_Protected_Store::get#1'        => 1,
			'AB_MCP_Protected_Store::way#1'        => 2,
			'AB_MCP_Protected_Store::way#2'        => 3,
			'AB_MCP_Protected_Store::holds#1'      => 2,
			'AB_MCP_Protected_Store::holds#2'      => 2,
			'AB_MCP_Protected_Store::holds#3'      => 5,
			'AB_MCP_Protected_Store::group#1'      => 1,
			'AB_MCP_Protected_Store::sidecar#1'    => 1,
			'AB_MCP_Protected_Store_Calls::read#1' => 1,
			'AB_MCP_Shape::decoded#1'              => 1,
			'AB_MCP_Shape::decoded#2'              => 1,
			'AB_MCP_Protected_Store::get#2'        => 1,
			'AB_MCP_Payload_File::read#1'          => 1,
			'AB_MCP_Payload_File::read#2'          => 1,
			'AB_MCP_Payload_File::read#3'          => 1,
			'AB_MCP_Payload_File::read#4'          => 1,
			'AB_MCP_Payload_File::read#5'          => 1,
			'AB_MCP_Payload_File::read#6'          => 1,
		),
		'ready'   => array(
			'AB_MCP_Protected_Store::files#1'      => 1,
			'AB_MCP_Protected_Store::way#1'        => 5,
			'AB_MCP_Protected_Store::way#2'        => 7,
			'AB_MCP_Protected_Store::holds#1'      => 5,
			'AB_MCP_Protected_Store::holds#2'      => 5,
			'AB_MCP_Protected_Store::holds#3'      => 12,
			'AB_MCP_Protected_Store::group#1'      => 2,
			'AB_MCP_Protected_Store::sidecar#1'    => 3,
			'AB_MCP_Protected_Store_Calls::read#1' => 3,
			'AB_MCP_Shape::decoded#1'              => 4,
			'AB_MCP_Shape::decoded#2'              => 4,
			'AB_MCP_Protected_Store::files#2'      => 1,
			'AB_MCP_Protected_Store::files#3'      => 5,
			'AB_MCP_Protected_Store::files#4'      => 1,
			'AB_MCP_Sync_Code_Stage::state#1'      => 2,
			'AB_MCP_Protected_Store::get#1'        => 1,
			'AB_MCP_Protected_Store::get#2'        => 1,
			'AB_MCP_Payload_File::read#1'          => 1,
			'AB_MCP_Payload_File::read#2'          => 1,
			'AB_MCP_Payload_File::read#3'          => 1,
			'AB_MCP_Payload_File::read#4'          => 1,
			'AB_MCP_Payload_File::read#5'          => 1,
			'AB_MCP_Payload_File::read#6'          => 1,
			'AB_MCP_Sync_Canon::revived#1'         => 24,
			'AB_MCP_Sync_Canon::prepared#3'        => 22,
			'AB_MCP_Sync_Canon::prepared#2'        => 2,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Shape::fields#1'               => 28,
			'AB_MCP_Shape::items#1'                => 2,
			'AB_MCP_Sync_Code_Stage::state#2'      => 1,
			'AB_MCP_Protected_Store::entry#1'      => 1,
			'AB_MCP_Sync_Code_Stage::ready#1'      => 1,
		),
		'state'   => array(
			'AB_MCP_Protected_Store::files#1'      => 1,
			'AB_MCP_Protected_Store::way#1'        => 4,
			'AB_MCP_Protected_Store::way#2'        => 6,
			'AB_MCP_Protected_Store::holds#1'      => 4,
			'AB_MCP_Protected_Store::holds#2'      => 4,
			'AB_MCP_Protected_Store::holds#3'      => 10,
			'AB_MCP_Protected_Store::group#1'      => 2,
			'AB_MCP_Protected_Store::sidecar#1'    => 2,
			'AB_MCP_Protected_Store_Calls::read#1' => 2,
			'AB_MCP_Shape::decoded#1'              => 3,
			'AB_MCP_Shape::decoded#2'              => 3,
			'AB_MCP_Protected_Store::files#2'      => 1,
			'AB_MCP_Protected_Store::files#3'      => 5,
			'AB_MCP_Protected_Store::files#4'      => 1,
			'AB_MCP_Sync_Code_Stage::state#1'      => 2,
			'AB_MCP_Protected_Store::get#1'        => 1,
			'AB_MCP_Protected_Store::get#2'        => 1,
			'AB_MCP_Payload_File::read#1'          => 1,
			'AB_MCP_Payload_File::read#2'          => 1,
			'AB_MCP_Payload_File::read#3'          => 1,
			'AB_MCP_Payload_File::read#4'          => 1,
			'AB_MCP_Payload_File::read#5'          => 1,
			'AB_MCP_Payload_File::read#6'          => 1,
			'AB_MCP_Sync_Canon::revived#1'         => 24,
			'AB_MCP_Sync_Canon::prepared#3'        => 22,
			'AB_MCP_Sync_Canon::prepared#2'        => 2,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Shape::fields#1'               => 22,
			'AB_MCP_Shape::items#1'                => 2,
			'AB_MCP_Sync_Code_Stage::state#2'      => 1,
		),
		'foreign' => array(
			'AB_MCP_Shape::fields#1'               => 6,
			'AB_MCP_Protected_Store::entry#1'      => 1,
			'AB_MCP_Protected_Store::way#1'        => 1,
			'AB_MCP_Protected_Store::way#2'        => 1,
			'AB_MCP_Protected_Store::holds#1'      => 1,
			'AB_MCP_Protected_Store::holds#2'      => 1,
			'AB_MCP_Protected_Store::holds#3'      => 2,
			'AB_MCP_Protected_Store::sidecar#1'    => 1,
			'AB_MCP_Protected_Store_Calls::read#1' => 1,
			'AB_MCP_Shape::decoded#1'              => 1,
			'AB_MCP_Shape::decoded#2'              => 1,
		),
	);

	/** The SHA-256 of the gates of each reading in time, one gate a line, in order. */
	const ORDER = array(
		'single' => '550ba10e55e1a56cf8294a78c7eb3c046632a01eeface5148631ed76489006a6',
		'two'    => '10747b6584af413d6cdb341d8955a40f4242f566901e3c2681643d72f3beff7e',
		'taken'  => 'ce7acf5e3672b9cd18d6fb843211a8d4e0af7dad606ab6f19d9612a119566731',
		'groups' => 'e0f0109592d06cdd4cf8f6be496cd246435ccd52dec4dee9d88dda8cde640fcb',
		'get'    => 'f3fed0a80e9d7d5de73da6a2048d4e6ccdcc27b67913442a039c88f0dba84632',
		'ready'   => '043a471d047d5c1132d5532b2e928febb575c09dff0c53ddc3da1adc2dc2d2f9',
		'state'   => 'a3ce36a95785b7c21528f590b321eaa59edaec96a8967658cd29094523a463e1',
		'foreign' => '4723b10b82b3d32c90be1e0fdd38304e8b0a012017c8716891984e262e25fc35',
	);

	/** The name of the large tree (R12 part 2). */
	private const BIG = 'ab-it-gross';

	/**
	 * The readings of R12 part 2: their other readings of the clock (no
	 * deadline's) and the steps of the manifest and of the place of a tree
	 * are kept as steps of the clock (part_2()).
	 */
	private const PART_2 = array( 'tree', 'bigtree', 'unit', 'tree_other', 'tree_missing', 'headread', 'headlist', 'heads', 'headfile', 'headtheme', 'heads_none', 'heads_empty', 'heads_down', 'heads_short', 'heads_unread' );

	/**
	 * The readings of the heads (R12 part 2) through not_down() of the swap:
	 * the kind and the name of the unit, its place (in the folder of the
	 * live units, live_units()), the unit read there (a key of $heads) and
	 * the version of the staging site.
	 */
	private const NOT_DOWN = array(
		'heads'        => array( 'plugin', 'ab-it-frist', 'ab-it-frist', 'frist', '1.2.0' ),
		'headfile'     => array( 'plugin', 'ab-it-einzeln', 'ab-it-einzeln.php', 'einzeln', '1.2.0' ),
		'headtheme'    => array( 'theme', 'ab-it-thema', 'ab-it-thema', 'thema', '1.2.0' ),
		'heads_none'   => array( 'plugin', 'ab-it-ohne', 'ab-it-ohne', 'ohne', '1.2.0' ),
		'heads_empty'  => array( 'plugin', 'ab-it-leer', 'ab-it-leer', 'leer', '1.2.0' ),
		'heads_down'   => array( 'plugin', 'ab-it-frist', 'ab-it-frist', 'frist', '0.9.0' ),
		'heads_short'  => array( 'plugin', 'ab-it-frist', 'ab-it-frist', 'frist', '1.2.0' ),
		'heads_unread' => array( 'plugin', 'ab-it-frist', 'ab-it-frist', 'frist', '1.2.0' ),
	);

	/**
	 * The gates of each reading of R12 part 2 in time, as COUNTS: tree() of
	 * the tree of the stage (tree) and of the large tree (bigtree), unit() of
	 * the large tree read by the manifest directly, as its first call (unit);
	 * head_read() of a main file (headread), heads() of a live unit in a
	 * folder (headlist), not_down() of a live unit in a folder (heads: a
	 * file whose head names no plugin first, then its main file), of a
	 * plugin of one file (headfile) and of a theme (headtheme).
	 */
	const PART_2_COUNTS = array(
		'tree'    => array(
			'AB_MCP_Protected_Store::tree#1'       => 1,
			'AB_MCP_Protected_Store::way#1'        => 2,
			'AB_MCP_Protected_Store::way#2'        => 3,
			'AB_MCP_Protected_Store::holds#1'      => 2,
			'AB_MCP_Protected_Store::holds#2'      => 2,
			'AB_MCP_Protected_Store::holds#3'      => 5,
			'AB_MCP_Protected_Store::group#1'      => 1,
			'AB_MCP_Protected_Store::sidecar#1'    => 1,
			'AB_MCP_Protected_Store_Calls::read#1' => 1,
			'AB_MCP_Shape::decoded#1'              => 1,
			'AB_MCP_Shape::decoded#2'              => 1,
			'AB_MCP_Protected_Store::tree#2'       => 1,
			'AB_MCP_Sync_File_Manifest::whole#1'   => 1,
			'AB_MCP_Sync_File_Manifest::folder#1'  => 2,
			'AB_MCP_Sync_File_Manifest::folder#2'  => 4,
			'AB_MCP_Sync_File_Manifest::file#1'    => 3,
			'AB_MCP_Sync_File_Manifest::file#2'    => 3,
			'AB_MCP_Sync_File_Manifest::file#3'    => 6,
			'AB_MCP_Sync_File_Manifest::file#5'    => 3,
			'AB_MCP_Sync_File_Manifest::still#1'   => 2,
			'AB_MCP_Sync_File_Manifest::still#2'   => 3,
			'AB_MCP_Sync_File_Manifest::text#1'    => 3,
			'AB_MCP_Sync_Canon::prepared#3'        => 3,
			'AB_MCP_Sync_Canon::prepared#2'        => 12,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Sync_File_Manifest::whole#2'   => 1,
			'AB_MCP_Protected_Store::tree#3'       => 1,
		),
		'bigtree' => array(
			'AB_MCP_Protected_Store::tree#1'       => 1,
			'AB_MCP_Protected_Store::way#1'        => 2,
			'AB_MCP_Protected_Store::way#2'        => 3,
			'AB_MCP_Protected_Store::holds#1'      => 2,
			'AB_MCP_Protected_Store::holds#2'      => 2,
			'AB_MCP_Protected_Store::holds#3'      => 5,
			'AB_MCP_Protected_Store::group#1'      => 1,
			'AB_MCP_Protected_Store::sidecar#1'    => 1,
			'AB_MCP_Protected_Store_Calls::read#1' => 1,
			'AB_MCP_Shape::decoded#1'              => 1,
			'AB_MCP_Shape::decoded#2'              => 1,
			'AB_MCP_Protected_Store::tree#2'       => 1,
			'AB_MCP_Sync_File_Manifest::whole#1'   => 1,
			'AB_MCP_Sync_File_Manifest::folder#1'  => 3,
			'AB_MCP_Sync_File_Manifest::folder#2'  => 5,
			'AB_MCP_Sync_File_Manifest::file#1'    => 3,
			'AB_MCP_Sync_File_Manifest::file#2'    => 3,
			'AB_MCP_Sync_File_Manifest::file#3'    => 6,
			'AB_MCP_Sync_File_Manifest::file#5'    => 3,
			'AB_MCP_Sync_File_Manifest::file#4'    => 1,
			'AB_MCP_Sync_File_Manifest::still#1'   => 3,
			'AB_MCP_Sync_File_Manifest::still#2'   => 3,
			'AB_MCP_Sync_File_Manifest::text#1'    => 3,
			'AB_MCP_Sync_Canon::prepared#3'        => 3,
			'AB_MCP_Sync_Canon::prepared#2'        => 12,
			'AB_MCP_Sync_Canon::encode#1'          => 1,
			'AB_MCP_Sync_File_Manifest::whole#2'   => 1,
			'AB_MCP_Protected_Store::tree#3'       => 1,
		),
		'unit'    => array(
			'AB_MCP_Sync_File_Manifest::whole#1'  => 1,
			'AB_MCP_Sync_File_Manifest::folder#1' => 3,
			'AB_MCP_Sync_File_Manifest::folder#2' => 5,
			'AB_MCP_Sync_File_Manifest::file#1'   => 3,
			'AB_MCP_Sync_File_Manifest::file#2'   => 3,
			'AB_MCP_Sync_File_Manifest::file#3'   => 6,
			'AB_MCP_Sync_File_Manifest::file#5'   => 3,
			'AB_MCP_Sync_File_Manifest::file#4'   => 1,
			'AB_MCP_Sync_File_Manifest::still#1'  => 3,
			'AB_MCP_Sync_File_Manifest::still#2'  => 3,
			'AB_MCP_Sync_File_Manifest::text#1'   => 3,
			'AB_MCP_Sync_Canon::prepared#3'       => 3,
			'AB_MCP_Sync_Canon::prepared#2'       => 12,
			'AB_MCP_Sync_Canon::encode#1'         => 1,
			'AB_MCP_Sync_File_Manifest::whole#2'  => 1,
		),
		'headread'  => array(
			'AB_MCP_Sync_Env::head_read#1'   => 1,
			'AB_MCP_Sync_Env::head_read#2'   => 1,
			'AB_MCP_Sync_Env::head_read#3'   => 1,
			'AB_MCP_Sync_Env::head_read#4'   => 1,
			'AB_MCP_Sync_Env::head_fields#1' => 2,
			'AB_MCP_Sync_Env::head_read#5'   => 1,
		),
		'headlist'  => array(
			'AB_MCP_Sync_Code_Swap::heads#1' => 5,
			'AB_MCP_Sync_Code_Swap::heads#2' => 2,
		),
		'heads'     => array(
			'AB_MCP_Sync_Code_Swap::heads#1'    => 5,
			'AB_MCP_Sync_Code_Swap::heads#2'    => 2,
			'AB_MCP_Sync_Env::head_read#1'      => 2,
			'AB_MCP_Sync_Env::head_read#2'      => 2,
			'AB_MCP_Sync_Env::head_read#3'      => 2,
			'AB_MCP_Sync_Env::head_read#4'      => 2,
			'AB_MCP_Sync_Env::head_fields#1'    => 4,
			'AB_MCP_Sync_Env::head_read#5'      => 2,
			'AB_MCP_Sync_Code_Swap::not_down#1' => 1,
		),
		'headfile'  => array(
			'AB_MCP_Sync_Env::head_read#1'      => 1,
			'AB_MCP_Sync_Env::head_read#2'      => 1,
			'AB_MCP_Sync_Env::head_read#3'      => 1,
			'AB_MCP_Sync_Env::head_read#4'      => 1,
			'AB_MCP_Sync_Env::head_fields#1'    => 2,
			'AB_MCP_Sync_Env::head_read#5'      => 1,
			'AB_MCP_Sync_Code_Swap::not_down#1' => 1,
		),
		'headtheme' => array(
			'AB_MCP_Sync_Env::head_read#1'      => 1,
			'AB_MCP_Sync_Env::head_read#2'      => 1,
			'AB_MCP_Sync_Env::head_read#3'      => 1,
			'AB_MCP_Sync_Env::head_read#4'      => 1,
			'AB_MCP_Sync_Env::head_fields#1'    => 2,
			'AB_MCP_Sync_Env::head_read#5'      => 1,
			'AB_MCP_Sync_Code_Swap::not_down#1' => 1,
		),
	);

	/** The SHA-256 of the gates of each reading of R12 part 2 in time, as ORDER. */
	const PART_2_ORDER = array(
		'tree'    => '70a2fc8fde067daba764b5e8793fec36d85b8515d0deccae4c5ea978b6b4df3d',
		'bigtree' => '18f481c2665b8d93891270d2d76ec6a79251f15e85efde28a612bbf32a2b95f3',
		'unit'    => '6cea781d3d194e09327b54aa5da05ffad0345ea0de18a1064881fe93c0dfbaf5',
		'headread'  => 'd03b07c38ae38e358fceba1ee72df72cb4713955283246866f9292183d4fa65a',
		'headlist'  => '91b926dce8c3baa9b1b48ba0031d5c1bfd215ccfb0f64a89566daa9da78fff10',
		'heads'     => '281b39554833adc4bdc88e550d9962006b6c828d423ea7f1d3390944a48afab2',
		'headfile'  => 'be321f81bce6b1dc0bc551b636ca7c7cc9809703f777db2e48ea24ce9c9dd6b8',
		'headtheme' => 'be321f81bce6b1dc0bc551b636ca7c7cc9809703f777db2e48ea24ce9c9dd6b8',
	);

	/** @var array<string,string[]> The series built once: name => package ids. */
	private static $series = array();

	/** @var array The first chunk of the series single: gid (its group in the area in), sha256. */
	private static $chunk = array();

	/** @var array The stage built once: gid, hash, bytes, entries (path, size, SHA-256), state (ready, as ready() reads it). */
	private static $stage = array();

	/** @var array The large tree built once (R12 part 2): gid, hash, path. */
	private static $big = array();

	/** @var array The live units built once (R12 part 2, live_units()): dir, and each unit read by its key. */
	private static $heads = array();

	/** @var string|null DOCUMENT_ROOT before the class. */
	private static $docroot_before;

	public static function setUpBeforeClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		self::$docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		wp_set_current_user( self::admin() );
		if ( ! is_array( AB_MCP_Sync_Schema::install() ) ) {
			throw new RuntimeException( 'No schema.' );
		}
		self::pair();
		self::$stage  = self::stage();
		self::$series = self::build();
		self::$chunk  = self::chunk_of( self::$series['single'][0] );
		self::$big    = self::big();
		self::$heads  = self::live_units();
	}

	public static function tearDownAfterClass(): void {
		DeadlineClock::restore();
		AB_MCP_Sync_Run::$clock = null;
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		self::$series = array();
		self::$chunk  = array();
		self::$stage  = array();
		self::$big    = array();
		if ( isset( self::$heads['dir'] ) && is_dir( self::$heads['dir'] ) ) {
			self::remove_tree( self::$heads['dir'] );
		}
		self::$heads = array();
		if ( null === self::$docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = self::$docroot_before;
		}
	}

	protected function setUp(): void {
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		wp_set_current_user( self::admin() );
		DeadlineClock::restore();
		AB_MCP_Protected_Store::forget();
		self::assertIsString( AB_MCP_Protected_Store::root(), 'The store decided before the reading, as the swap does (read_deadline()).' );
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Code_Swap::release();
		\AB_MCP_Change_Lock::release_all();
		\AB_MCP_Update_Log::clear_running();
		delete_option( 'auto_updater.lock' );
		DeadlineClock::restore();
		AB_MCP_Sync_Run::$clock = null;
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Sync_Code_Swap::forget();
		AB_MCP_Protected_Store::forget();
	}

	/* ---------------------------------------------------------- helpers */

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	private static function root(): string {
		return (string) AB_MCP_IT_Sites::state()['root'];
	}

	private static function wipe(): void {
		$store = self::root() . '/ab-mcp-store';
		if ( is_dir( $store ) ) {
			self::remove_tree( $store );
		}
	}

	private static function remove_tree( string $path ): void {
		if ( 0 !== strpos( $path, self::root() . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		chmod( $path, 0700 );
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	private static function b( array $steps ): array {
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps );
	}

	/** B the staging site of A, A the production site of B with B's key confirmed. */
	private static function pair(): void {
		$b = self::b(
			array(
				array( 'op' => 'write_access' ),
				array(
					'op'          => 'staging_save',
					'role'        => 'staging',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op' => 'pairing_code',
					'as' => 'code',
				),
			)
		);
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		$saved = AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), 'form' );
		if ( ! is_array( $saved ) ) {
			throw new RuntimeException( 'A not saved as the production site of B.' );
		}
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		if ( ! is_array( $prepared ) || ! is_array( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) ) ) {
			throw new RuntimeException( 'The key of B not confirmed on A.' );
		}
	}

	/* ---------------------------------------------------------- the series */

	/** The line of the unit (a folder of three files, the tree of the stage) and its entries in two lines. */
	private static function unit_lines(): array {
		$files = array();
		foreach ( self::$stage['entries'] as $i => $entry ) {
			$files[] = array( $entry[0], $entry[1], $entry[2], 1700000000 + $i );
		}
		$unit  = array(
			'active'     => array( 'ab-it-frist.php' ),
			'bytes'      => self::$stage['bytes'],
			'cause'      => null,
			'complete'   => true,
			'env'        => 'plugin',
			'files'      => 3,
			'form'       => 'folder',
			'hash'       => self::$stage['hash'],
			'lock'       => null,
			'mains'      => array( 'ab-it-frist.php' ),
			'mtime'      => null,
			'own'        => false,
			'ref'        => self::UNIT,
			'sha256'     => null,
			'update_uri' => null,
			'version'    => '1.2.0',
		);
		$part  = static function ( int $seq, array $entries ): array {
			return array(
				'entries' => $entries,
				'env'     => 'entries',
				'ref'     => 'plugin:' . self::UNIT . '#' . $seq,
				'seq'     => $seq,
				'unit'    => 'plugin:' . self::UNIT,
			);
		};
		return array( $unit, $part( 1, array_slice( $files, 0, 2 ) ), $part( 2, array_slice( $files, 2 ) ) );
	}

	/** What signed() answers for the unit of the series. */
	private static function signed_answer(): array {
		list( $unit, $one, $two ) = self::unit_lines();
		$entries                  = array();
		foreach ( array_merge( $one['entries'], $two['entries'] ) as $entry ) {
			$entries[] = array( $entry[0], $entry[1], $entry[2] );
		}
		return array(
			'kind'       => 'plugin',
			'ref'        => self::UNIT,
			'form'       => 'folder',
			'hash'       => $unit['hash'],
			'files'      => 3,
			'bytes'      => self::$stage['bytes'],
			'version'    => '1.2.0',
			'update_uri' => null,
			'entries'    => $entries,
		);
	}

	/**
	 * The stage of the unit: a group of the area stage bound to RUN, the tree
	 * of three files put in (put_tree(), its hash the hash of the unit in the
	 * series), a state checking and then a state ready, written as complete()
	 * writes them.
	 *
	 * @return array gid, hash, bytes, entries, state.
	 */
	private static function stage(): array {
		$source = self::root() . '/run/r12-frist-src';
		if ( is_dir( $source ) ) {
			self::remove_tree( $source );
		}
		mkdir( $source . '/inc', 0755, true );
		$bytes = array(
			'ab-it-frist.php' => "<?php\n/**\n * Plugin Name: AB IT Frist\n * Version: 1.2.0\n */\n",
			'inc/teil.php'    => "<?php\n// A part of the plugin.\n",
			'readme.txt'      => "The deadline of a reading.\n",
		);
		$entries = array();
		foreach ( $bytes as $path => $text ) {
			file_put_contents( $source . '/' . $path, $text );
			$entries[] = array( $path, strlen( $text ), hash( 'sha256', $text ) );
		}
		$gid = AB_MCP_Protected_Store::open(
			'stage',
			array(
				'run_id' => self::RUN,
				'label'  => 'code',
			)
		);
		if ( ! is_string( $gid ) ) {
			throw new RuntimeException( 'No group of the stage.' );
		}
		$tree = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::UNIT, WP_PLUGIN_DIR . '/' . self::UNIT, $source );
		self::remove_tree( $source );
		if ( ! is_array( $tree ) ) {
			throw new RuntimeException( 'No tree of the stage: ' . $tree->get_error_code() );
		}
		$state = array(
			'format'  => AB_MCP_Sync_Code_Stage::STATE_FORMAT,
			'unit'    => array(
				'kind' => 'plugin',
				'ref'  => self::UNIT,
			),
			'signed'  => $tree['hash'],
			'tree'    => array(
				'hash' => $tree['hash'],
				'name' => self::UNIT,
			),
			'source'  => 'packages',
			'state'   => AB_MCP_Sync_Code_Stage::CHECKING,
			'seq'     => 1,
			'todo'    => array(
				array(
					'path'   => 'ab-it-frist.php',
					'size'   => $entries[0][1],
					'sha256' => $entries[0][2],
				),
				array(
					'path'   => 'inc/teil.php',
					'size'   => $entries[1][1],
					'sha256' => $entries[1][2],
				),
			),
			'done'    => 0,
			'lint'    => true,
			'notes'   => array(),
			'refusal' => null,
		);
		$write = new ReflectionMethod( AB_MCP_Sync_Code_Stage::class, 'write_state' );
		if ( true !== $write->invoke( null, $gid, $state ) ) {
			throw new RuntimeException( 'The state checking not written.' );
		}
		$state['state'] = AB_MCP_Sync_Code_Stage::READY;
		$state['seq']   = 2;
		$state['done']  = 2;
		if ( true !== $write->invoke( null, $gid, $state ) ) {
			throw new RuntimeException( 'The state ready not written.' );
		}
		return array(
			'gid'     => $gid,
			'hash'    => $tree['hash'],
			'bytes'   => array_sum( array_column( $entries, 1 ) ),
			'entries' => $entries,
			'state'   => AB_MCP_Sync_Code_Stage::state_of( AB_MCP_Sync_Canon::encode( $state ) ),
		);
	}

	/**
	 * The large tree (R12 part 2), in a group of the area stage of its own
	 * (another run): a main file, in a folder a file of one part and ten
	 * bytes more (AB_MCP_Sync_File_Manifest::CHUNK), an empty folder and an
	 * empty file, put in by put_tree().
	 *
	 * @return array gid, hash, path.
	 */
	private static function big(): array {
		$source = self::root() . '/run/r12-gross-src';
		if ( is_dir( $source ) ) {
			self::remove_tree( $source );
		}
		mkdir( $source . '/daten', 0755, true );
		mkdir( $source . '/leer', 0755 );
		file_put_contents( $source . '/ab-it-gross.php', "<?php\n/**\n * Plugin Name: AB IT Gross\n * Version: 1.0.0\n */\n" );
		file_put_contents( $source . '/daten/gross.bin', str_repeat( 'Frist', intdiv( \AB_MCP_Sync_File_Manifest::CHUNK, 5 ) ) . str_repeat( 'x', 14 ) );
		file_put_contents( $source . '/leer.txt', '' );
		$gid = AB_MCP_Protected_Store::open(
			'stage',
			array(
				'run_id' => self::RUN + 2,
				'label'  => 'code',
			)
		);
		if ( ! is_string( $gid ) ) {
			throw new RuntimeException( 'No group of the large tree.' );
		}
		$tree = AB_MCP_Protected_Store::put_tree( 'stage', $gid, self::BIG, WP_PLUGIN_DIR . '/' . self::BIG, $source );
		self::remove_tree( $source );
		if ( ! is_array( $tree ) ) {
			throw new RuntimeException( 'No large tree: ' . $tree->get_error_code() );
		}
		return array(
			'gid'  => $gid,
			'hash' => $tree['hash'],
			'path' => $tree['path'],
		);
	}

	/**
	 * The files of a live plugin ab-it-frist of version 1.0.0 in a folder:
	 * first by name a PHP file whose head names no plugin, then its main
	 * file, a hidden PHP file and one in a folder below (no heads), and a
	 * text (R12 part 2: heads(), not_down()).
	 *
	 * @param string $dir The folder of the unit.
	 */
	private static function put_live( string $dir ): void {
		mkdir( $dir . '/inc', 0755, true );
		file_put_contents( $dir . '/a-helfer.php', "<?php\n// A helper of the plugin, its head names none.\n" );
		file_put_contents( $dir . '/ab-it-frist.php', "<?php\n/**\n * Plugin Name: AB IT Frist\n * Version: 1.0.0\n */\n" );
		file_put_contents( $dir . '/.versteckt.php', "<?php\n/**\n * Plugin Name: Hidden\n * Version: 9.9.9\n */\n" );
		file_put_contents( $dir . '/inc/teil.php', "<?php\n/**\n * Plugin Name: Below\n * Version: 9.9.9\n */\n" );
		file_put_contents( $dir . '/readme.txt', "The live unit.\n" );
	}

	/**
	 * The live units of the readings of the heads (R12 part 2), outside the
	 * site (not_down() is read directly): ab-it-frist in a folder (put_live()),
	 * ab-it-ohne (its one PHP file names no plugin), ab-it-leer (no PHP file
	 * at its top), the plugin of one file ab-it-einzeln.php and the theme
	 * ab-it-thema; each read whole as unchanged() reads it, without a
	 * deadline.
	 *
	 * @return array dir, and each unit read by its key.
	 */
	private static function live_units(): array {
		$dir = self::root() . '/run/r12-kopf';
		if ( is_dir( $dir ) ) {
			self::remove_tree( $dir );
		}
		self::put_live( $dir . '/ab-it-frist' );
		mkdir( $dir . '/ab-it-ohne', 0755, true );
		file_put_contents( $dir . '/ab-it-ohne/ab-it-ohne.php', "<?php\n// A plugin without a head.\n" );
		mkdir( $dir . '/ab-it-leer/inc', 0755, true );
		file_put_contents( $dir . '/ab-it-leer/inc/teil.php', "<?php\n/**\n * Plugin Name: Below\n * Version: 9.9.9\n */\n" );
		file_put_contents( $dir . '/ab-it-leer/readme.txt', "No PHP file at its top.\n" );
		file_put_contents( $dir . '/ab-it-einzeln.php', "<?php\n/**\n * Plugin Name: AB IT Einzeln\n * Version: 1.0.0\n */\n" );
		mkdir( $dir . '/ab-it-thema', 0755, true );
		file_put_contents( $dir . '/ab-it-thema/style.css', "/*\nTheme Name: AB IT Thema\nVersion: 1.0.0\n*/\n" );
		$out = array( 'dir' => $dir );
		foreach ( array( 'frist' => 'ab-it-frist', 'ohne' => 'ab-it-ohne', 'leer' => 'ab-it-leer', 'einzeln' => 'ab-it-einzeln.php', 'thema' => 'ab-it-thema' ) as $key => $name ) {
			$out[ $key ] = \AB_MCP_Sync_File_Manifest::unit( $dir, $name, 'live', INF, true, 'plugin' );
			if ( true !== $out[ $key ]['complete'] ) {
				throw new RuntimeException( "The live unit $name not read whole." );
			}
		}
		return $out;
	}

	/** The binding a step of RUN expects of the stage. */
	private static function bound( int $run ): array {
		return array(
			'run_id' => $run,
			'kind'   => 'plugin',
			'ref'    => self::UNIT,
			'signed' => self::$stage['hash'],
		);
	}

	private static function page( int $seq, bool $last, string $walk ): array {
		return array(
			'env'   => 'page',
			'files' => \AB_MCP_Sync_File_Manifest::FORMAT,
			'last'  => $last,
			'ref'   => 'page',
			'seq'   => $seq,
			'walk'  => $walk,
		);
	}

	private static function core( string $ref, string $value ): array {
		return array(
			'env'   => 'core',
			'ref'   => $ref,
			'value' => $value,
		);
	}

	/** A table whose name a credential holds by the rules of this site: the import lists it as held (held_here() reads that list). */
	private static function held_table(): array {
		return array(
			'env'    => 'table',
			'ref'    => 'abfrist_token=EXAMPLE_SECRET',
			'rows'   => 1,
			'schema' => null,
		);
	}

	/**
	 * The series, each package signed by B and taken over by A through its
	 * import, a second after the one before (its group after it, so the
	 * readings take the same way in every run): single (one package of two
	 * chunks), two (two packages of two chunks each), then one package for
	 * each refusal of a reader: corrupt (its second chunk does not match its
	 * hash), mark (its mark does not match its hash), held (its list of held
	 * objects gone), unread (a chunk that is no gzip under its hash, put as
	 * CodeStageTest puts it); and missing, a package not taken over. Each
	 * package of the unit holds a table whose name a credential holds, so
	 * the list of held objects is read entry by entry.
	 *
	 * @return array<string,string[]>
	 */
	private static function build(): array {
		list( $unit, $one, $two ) = self::unit_lines();
		$single                   = static function ( string $walk ) use ( $unit, $one, $two ): array {
			return array(
				array(
					array( self::page( 1, true, $walk ), $unit ),
					array( $one, $two, self::held_table() ),
				),
			);
		};
		$plan                     = array(
			'single'  => $single( str_repeat( '1a', 16 ) ),
			'two'     => array(
				array(
					array( self::page( 1, false, str_repeat( '2b', 16 ) ), self::core( 'wordpress', '7.1.3' ) ),
					array( $unit, $one ),
				),
				array(
					array( self::page( 2, true, str_repeat( '2b', 16 ) ), $two ),
					array( self::core( 'locale', 'de_CH' ), self::held_table() ),
				),
			),
			'corrupt' => $single( str_repeat( '3c', 16 ) ),
			'mark'    => $single( str_repeat( '4d', 16 ) ),
			'held'    => $single( str_repeat( '5e', 16 ) ),
		);
		$made                     = array();
		$steps                    = array();
		foreach ( $plan as $name => $packages ) {
			foreach ( $packages as $chunks ) {
				$package = self::package( $chunks );
				$made[]  = array( $name, $package );
				$steps[] = array(
					'op'      => 'sign_manifest',
					'as'      => 's' . count( $steps ),
					'package' => $package['package'],
				);
			}
		}
		$bytes   = 'no gzip at all';
		$made[]  = array(
			'unread',
			array(
				'package' => array(
					'package_id'  => bin2hex( random_bytes( 16 ) ),
					'purpose'     => 'env',
					'report_hash' => null,
					'kinds'       => array(),
					'objects'     => 1,
					'unpacked'    => 10,
					'chunks'      => array(
						array(
							'bytes'  => strlen( $bytes ),
							'sha256' => hash( 'sha256', $bytes ),
						),
					),
					'bytes'       => strlen( $bytes ),
					'sha256'      => hash( 'sha256', $bytes ),
				),
				'chunks'  => array( $bytes ),
			),
		);
		$steps[] = array(
			'op'      => 'sign_manifest',
			'as'      => 's' . count( $steps ),
			'package' => end( $made )[1]['package'],
		);
		$signed  = self::b( $steps );
		$base    = time();
		$out     = array();
		foreach ( $made as $i => $one_made ) {
			list( $name, $package ) = $one_made;
			$id                     = $package['package']['package_id'];
			AB_MCP_Sync_Run::$clock = static function () use ( $base, $i ) {
				return $base + $i;
			};
			$begun                  = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $id,
					'manifest'   => $signed[ 's' . $i ],
					'purpose'    => 'env',
				)
			);
			self::taken_on( $begun, 'begun' );
			if ( 'unread' === $name ) {
				self::put_unread( $id, $bytes );
			} else {
				foreach ( $package['chunks'] as $c => $chunk ) {
					$state = AB_MCP_Sync_Import::call(
						array(
							'package_id' => $id,
							'chunk'      => $c,
							'data'       => base64_encode( $chunk ),
						)
					);
					self::taken_on( $state, 'chunk ' . $c );
				}
				if ( true !== $state['complete'] ) {
					throw new RuntimeException( "The package $name is not complete." );
				}
			}
			$out[ $name ][] = $id;
		}
		AB_MCP_Sync_Run::$clock = null;
		$dir                    = self::group_dir( $out['corrupt'][0] );
		self::replace( $dir . '/' . AB_MCP_Sync_Export::chunk_name( 1 ), 'a chunk of another hash' );
		self::replace( self::group_dir( $out['mark'][0] ) . '/' . AB_MCP_Sync_Import::COMPLETE, 'a mark of another package' );
		$dir = self::group_dir( $out['held'][0] );
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( null !== AB_MCP_Sync_Import::held_sha( (string) $file ) ) {
				unlink( $dir . '/' . $file );
			}
		}
		// A package this site does not hold.
		$out['missing'] = array( bin2hex( random_bytes( 16 ) ) );
		return $out;
	}

	/** A call of the import that went through, or the build stops. */
	private static function taken_on( $answer, string $what ): void {
		if ( ! is_array( $answer ) ) {
			throw new RuntimeException( "Import, $what: " . ( $answer instanceof WP_Error ? $answer->get_error_code() . ': ' . $answer->get_error_message() : gettype( $answer ) ) );
		}
	}

	/** A chunk that is no gzip under its hash, with its list and its mark, put into its group as the import puts them. */
	private static function put_unread( string $id, string $bytes ): void {
		$gid = null;
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			if ( AB_MCP_Sync_Import::LABEL . $id === $group['label'] ) {
				$gid = $group['gid'];
			}
		}
		$list = '{"format":"ab-sync-held/3","objects":[]}';
		foreach ( array(
			AB_MCP_Sync_Export::chunk_name( 0 )                           => $bytes,
			AB_MCP_Sync_Import::held_name( hash( 'sha256', $list ) ) => $list,
			AB_MCP_Sync_Import::COMPLETE                                 => hash( 'sha256', $bytes ),
		) as $name => $payload ) {
			if ( ! is_array( AB_MCP_Protected_Store::put( 'in', (string) $gid, $name, $payload ) ) ) {
				throw new RuntimeException( "Not put: $name." );
			}
		}
	}

	/** The folder of the group of a package in the area in, by its sidecar. */
	private static function group_dir( string $id ): string {
		foreach ( (array) glob( AB_MCP_Protected_Store::root() . '/in/*.json' ) as $side ) {
			$entry = json_decode( (string) file_get_contents( (string) $side ), true );
			if ( is_array( $entry ) && AB_MCP_Sync_Import::LABEL . $id === $entry['label'] ) {
				return substr( (string) $side, 0, -5 );
			}
		}
		throw new RuntimeException( "No group of the package $id." );
	}

	/** The group of a package in the area in and the SHA-256 of its first chunk (its manifest), read without a deadline. */
	private static function chunk_of( string $id ): array {
		$taken = AB_MCP_Sync_Import::taken( $id, 'env' );
		self::taken_on( $taken, 'taken' );
		foreach ( AB_MCP_Protected_Store::groups( 'in' ) as $group ) {
			if ( AB_MCP_Sync_Import::LABEL . $id === $group['label'] ) {
				return array(
					'gid'    => $group['gid'],
					'sha256' => $taken['manifest']['chunks'][0]['sha256'],
				);
			}
		}
		throw new RuntimeException( "No group of the package $id." );
	}

	/** A file of the store with other bytes as the store writes them (their SHA-256 another). */
	private static function replace( string $path, string $raw ): void {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( "No file $path." );
		}
		chmod( dirname( $path ), 0700 );
		unlink( $path );
		file_put_contents( $path, AB_MCP_Payload_File::compress( $raw ) );
	}

	/**
	 * A package of the environment of these chunks of lines: its fields for
	 * the manifest and its chunks (gzip).
	 *
	 * @param array[][] $chunks Lines of each chunk.
	 * @return array{package:array,chunks:string[]}
	 */
	private static function package( array $chunks ): array {
		$gz       = array();
		$listed   = array();
		$objects  = 0;
		$unpacked = 0;
		foreach ( $chunks as $lines ) {
			$text = '';
			foreach ( $lines as $line ) {
				$text .= AB_MCP_Sync_Canon::encode( $line ) . "\n";
			}
			$bytes     = (string) gzencode( $text, 6 );
			$gz[]      = $bytes;
			$listed[]  = array(
				'bytes'  => strlen( $bytes ),
				'sha256' => hash( 'sha256', $bytes ),
			);
			$objects  += count( $lines );
			$unpacked += strlen( $text );
		}
		return array(
			'package' => array(
				'package_id'  => bin2hex( random_bytes( 16 ) ),
				'purpose'     => 'env',
				'report_hash' => null,
				'kinds'       => array(),
				'objects'     => $objects,
				'unpacked'    => $unpacked,
				'chunks'      => $listed,
				'bytes'       => array_sum( array_column( $listed, 'bytes' ) ),
				'sha256'      => hash( 'sha256', implode( '', $gz ) ),
			),
			'chunks'  => $gz,
		);
	}

	/* ---------------------------------------------------------- readings */

	/**
	 * signed() of a series under a deadline (or none, or a value that is no
	 * deadline) with this clock, each listing and each sidecar the store reads
	 * through its seam kept as a step of the clock; $first faults of the seam
	 * go before those. The readings taken, groups and get read below signed()
	 * directly: taken() of the series single, groups() of the area in, get()
	 * of the first chunk of single; ready of the stage (ready_hash: another
	 * hash, ready_foreign: bound to another run), state and foreign of the
	 * stage directly.
	 *
	 * @param string        $series Key of the series or of the reading.
	 * @param DeadlineClock $clock  The clock.
	 * @param mixed         $deadline Deadline.
	 * @param array[]       $first  Faults of the seam of the store before the steps.
	 * @return mixed
	 */
	private function reading( string $series, DeadlineClock $clock, $deadline, array $first = array() ) {
		$clock->install();
		$part_2 = in_array( $series, self::PART_2, true );
		self::watch( $clock, $first, $part_2 );
		if ( $part_2 ) {
			self::part_2( $clock, $series );
		}
		try {
			switch ( $series ) {
				case 'taken':
					return AB_MCP_Sync_Import::taken( self::$series['single'][0], 'env', $deadline );
				case 'groups':
					return AB_MCP_Protected_Store::groups( 'in', $deadline );
				case 'get':
					return AB_MCP_Protected_Store::get( 'in', self::$chunk['gid'], AB_MCP_Sync_Export::chunk_name( 0 ), self::$chunk['sha256'], $deadline );
				case 'ready':
					return AB_MCP_Sync_Code_Stage::ready( self::$stage['gid'], self::UNIT, self::$stage['hash'], self::bound( self::RUN ), $deadline );
				case 'ready_hash':
					return AB_MCP_Sync_Code_Stage::ready( self::$stage['gid'], self::UNIT, str_repeat( '0', 64 ), self::bound( self::RUN ), $deadline );
				case 'ready_foreign':
					return AB_MCP_Sync_Code_Stage::ready( self::$stage['gid'], self::UNIT, self::$stage['hash'], self::bound( self::RUN + 1 ), $deadline );
				case 'state':
					return ( new ReflectionMethod( AB_MCP_Sync_Code_Stage::class, 'state' ) )->invoke( null, self::$stage['gid'], $deadline );
				case 'foreign':
					return ( new ReflectionMethod( AB_MCP_Sync_Code_Stage::class, 'foreign' ) )->invoke( null, self::$stage['gid'], self::$stage['state'], self::bound( self::RUN ), $deadline );
				case 'tree':
					return AB_MCP_Protected_Store::tree( 'stage', self::$stage['gid'], self::UNIT, self::$stage['hash'], $deadline );
				case 'tree_other':
					return AB_MCP_Protected_Store::tree( 'stage', self::$stage['gid'], self::UNIT, str_repeat( '0', 64 ), $deadline );
				case 'tree_missing':
					return AB_MCP_Protected_Store::tree( 'stage', self::$stage['gid'], 'fehlt', self::$stage['hash'], $deadline );
				case 'bigtree':
					return AB_MCP_Protected_Store::tree( 'stage', self::$big['gid'], self::BIG, self::$big['hash'], $deadline );
				case 'unit':
					return \AB_MCP_Sync_File_Manifest::unit( dirname( self::$big['path'] ), basename( self::$big['path'] ), 'live', DeadlineClock::NOW + AB_MCP_Protected_Store::TREE_BUDGET, true, 'tree', $deadline );
				case 'headread':
					return \AB_MCP_Sync_Env::head_read( self::$heads['dir'] . '/ab-it-frist/ab-it-frist.php', array( 'Plugin Name', 'Version' ), $deadline );
				case 'headlist':
					return ( new ReflectionMethod( AB_MCP_Sync_Code_Swap::class, 'heads' ) )->invoke( null, 'plugin', self::$heads['dir'] . '/ab-it-frist', self::$heads['frist'], $deadline );
			}
			if ( isset( self::NOT_DOWN[ $series ] ) ) {
				list( $kind, $ref, $place, $unit, $staging ) = self::NOT_DOWN[ $series ];
				return ( new ReflectionMethod( AB_MCP_Sync_Code_Swap::class, 'not_down' ) )->invoke( null, $kind, $ref, self::$heads['dir'] . '/' . $place, self::$heads[ $unit ], $staging, $deadline );
			}
			return AB_MCP_Sync_Code_Stage::signed( self::$series[ $series ], 'plugin', self::UNIT, $deadline );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
	}

	/**
	 * The seam of the store with each listing and each sidecar read kept as a
	 * step of the clock; for R12 part 2 ($lstat) each path asked too (the
	 * place of a tree).
	 */
	private static function watch( DeadlineClock $clock, array $first = array(), bool $lstat = false ): void {
		$step  = static function ( $method, $args ) use ( $clock ) {
			$clock->step( $method . ' ' . basename( (string) $args[0] ) );
		};
		$plan  = array();
		$steps = $lstat ? array( 'read', 'scandir', 'lstat' ) : array( 'read', 'scandir' );
		foreach ( $steps as $method ) {
			$plan[] = array(
				'method' => $method,
				'kind'   => 'before',
				'before' => $step,
			);
		}
		AB_MCP_Test_Seam::install( AB_MCP_Protected_Store::class, 'naht_protected_store', new \AB_MCP_Protected_Store_Calls(), array_merge( $first, $plan ) );
	}

	/**
	 * R12 part 2: each reading of the clock that is no deadline's (the start
	 * of TREE_BUDGET in read_tree(), the time of the call in due()) kept as a
	 * step (step: clock), so that its place shows among the gates; each call
	 * of the manifest to the file system (a listing, a path asked, a file
	 * opened, asked or read) kept as a step (manifest …). Closing a file is
	 * no step: it is allowed after a stop. Each head read (head_text() of
	 * AB_MCP_Sync_Env) kept as a step (head …); for heads_short the head
	 * read shorter than the file (half), for heads_unread not read (false).
	 */
	private static function part_2( DeadlineClock $clock, string $series ): void {
		$heads = array(
			'heads_short'  => array(
				'method' => 'head_text',
				'kind'   => 'half',
				'keep'   => 10,
			),
			'heads_unread' => array(
				'method' => 'head_text',
				'kind'   => 'return',
				'value'  => false,
			),
		);
		AB_MCP_Test_Seam::install(
			\AB_MCP_Sync_Env::class,
			'naht_sync_env',
			new \AB_MCP_Sync_Env_Calls(),
			array(
				$heads[ $series ] ?? array(
					'method' => 'head_text',
					'kind'   => 'before',
					'before' => static function ( $method, $args ) use ( $clock ) {
						$clock->step( 'head ' . basename( (string) $args[0] ) );
					},
				),
			)
		);
		\AB_MCP_Sync_Env::$clock = static function () use ( $clock ) {
			// R12b part 3.2: only a reading that is no deadline's (others); the
			// check of a helper of the steps is neither (T1, its step_reads).
			$others = $clock->others;
			$time   = $clock();
			if ( $clock->others > $others ) {
				$clock->step( 'clock' );
			}
			return $time;
		};
		$step = static function ( $method, $args ) use ( $clock ) {
			$clock->step( 'manifest ' . $method . ( is_string( $args[0] ) ? ' ' . basename( $args[0] ) : '' ) );
		};
		$plan = array();
		foreach ( array( 'names', 'lstat', 'open', 'fstat', 'read' ) as $method ) {
			$plan[] = array(
				'method' => $method,
				'kind'   => 'before',
				'before' => $step,
			);
		}
		AB_MCP_Test_Seam::install( \AB_MCP_Sync_File_Manifest::class, 'naht_sync_file_manifest', new \AB_MCP_Sync_File_Manifest_Calls(), $plan );
	}

	/** The steps among the events of a clock, in order. */
	private static function steps( DeadlineClock $clock ): array {
		return array_values(
			array_filter(
				$clock->events,
				static function ( $event ) {
					return 0 === strpos( $event, 'step: ' );
				}
			)
		);
	}

	/**
	 * What a reading answers without a deadline: the unit signed; the stage
	 * ready, its state; a reading below them as it reads (never a refusal;
	 * foreign(): null, bound).
	 */
	private function answer( string $series ) {
		if ( 'single' === $series || 'two' === $series ) {
			return self::signed_answer();
		}
		if ( 'ready' === $series || 'state' === $series ) {
			return self::$stage['state'];
		}
		// not_down() and heads() have a deadline always: the version the main
		// file of the unit names, read without a deadline (head_read()), and
		// its files by name.
		if ( isset( self::NOT_DOWN[ $series ] ) ) {
			$file = array(
				'heads'     => 'ab-it-frist/ab-it-frist.php',
				'headfile'  => 'ab-it-einzeln.php',
				'headtheme' => 'ab-it-thema/style.css',
			)[ $series ];
			return \AB_MCP_Sync_Env::head_read( self::$heads['dir'] . '/' . $file, array( 'Plugin Name', 'Version' ) )['Version'];
		}
		if ( 'headlist' === $series ) {
			return array( self::$heads['dir'] . '/ab-it-frist/a-helfer.php', self::$heads['dir'] . '/ab-it-frist/ab-it-frist.php' );
		}
		$without = $this->reading( $series, DeadlineClock::in_time(), null );
		self::assertNotInstanceOf( WP_Error::class, $without, $series . ' without a deadline: ' . ( $without instanceof WP_Error ? $without->get_error_code() : '' ) );
		return $without;
	}

	/** A reading of a series in time: its clock, checked against the gates written down. */
	private function in_time( string $series ): DeadlineClock {
		$clock  = DeadlineClock::in_time();
		$answer = $this->reading( $series, $clock, DeadlineClock::deadline() );
		self::assertSame( $this->answer( $series ), $answer, 'In time: the answer without a deadline.' );
		self::assertSame( self::counts_of( $series ), array_count_values( $clock->gates ), 'Each gate as often as written down.' );
		self::assertSame( self::order_of( $series ), hash( 'sha256', implode( "\n", $clock->gates ) ), 'The gates in the order written down.' );
		return $clock;
	}

	/** The gates of a reading as written down (COUNTS, or PART_2_COUNTS for R12 part 2). */
	private static function counts_of( string $series ): array {
		return self::COUNTS[ $series ] ?? self::PART_2_COUNTS[ $series ];
	}

	/** Their order as written down (ORDER, or PART_2_ORDER). */
	private static function order_of( string $series ): string {
		return self::ORDER[ $series ] ?? self::PART_2_ORDER[ $series ];
	}

	/** The place (1: the first reading) of the pick-th time a reading asks a gate. */
	private static function place( array $gates, string $gate, int $pick ): int {
		$at = array_keys( $gates, $gate, true );
		self::assertArrayHasKey( $pick, $at, $gate );
		return $at[ $pick ] + 1;
	}

	/** The places of the steps of a kind in the events of a clock, and how many gates come before each. */
	private static function steps_of( DeadlineClock $clock, string $prefix ): array {
		$out   = array();
		$gates = 0;
		foreach ( $clock->events as $event ) {
			if ( 0 !== strpos( $event, 'step: ' ) ) {
				++$gates;
			} elseif ( 0 === strpos( $event, 'step: ' . $prefix ) ) {
				$out[] = $gates;
			}
		}
		return $out;
	}

	/** A reading stopped: the stop its answer, kept as the stop of its deadline, in its full wording. */
	private static function assertStopped( $answer, $deadline, string $code, string $text ): void {
		self::assertRefused( $answer, $code, $text, 'The answer at the entrance: the stop.' );
		self::assertSame( AB_MCP_Sync_Deadline::stop( $deadline ), $answer, 'The stop of the deadline, as it is.' );
	}

	/** A WP_Error of its code with its full message. */
	private static function assertRefused( $error, string $code, string $text, string $why ): void {
		self::assertInstanceOf( WP_Error::class, $error, $why . ': ' . ( is_array( $error ) ? 'an answer' : gettype( $error ) ) );
		self::assertSame( array( $code, $text ), array( $error->get_error_code(), $error->get_error_message() ), $why );
	}

	/* -------------------------------------------- signed(): the main series */

	/** @return array<string,array{string}> */
	public static function series(): array {
		return array(
			'single' => array( 'single' ),
			'two'    => array( 'two' ),
			'taken'  => array( 'taken' ),
			'groups' => array( 'groups' ),
			'get'    => array( 'get' ),
			'ready'  => array( 'ready' ),
			'state'  => array( 'state' ),
			'foreign' => array( 'foreign' ),
		);
	}

	/**
	 * Each gate of each reading by itself: the first, a middle and the last
	 * time the reading asks it.
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function gates(): array {
		$out = array();
		foreach ( self::COUNTS as $series => $counts ) {
			foreach ( $counts as $gate => $m ) {
				foreach ( array_unique( array( 0, intdiv( $m - 1, 2 ), $m - 1 ) ) as $pick ) {
					$out[ sprintf( '%s: %s, %d of %d', $series, $gate, $pick + 1, $m ) ] = array( $series, $gate, $pick );
				}
			}
		}
		return $out;
	}

	#[DataProvider( 'series' )]
	public function testInTimeTheSignedManifestAsksAtExactlyItsGatesAndAnswersAsWithoutADeadline( string $series ): void {
		$clock   = DeadlineClock::in_time();
		$without = $this->reading( $series, $clock, null );
		self::assertSame( $this->answer( $series ), $without, 'Without a deadline: the unit signed; below signed() what the reader reads.' );
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'Without a deadline no clock read.' );
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		self::assertSame( $without, $this->reading( $series, $clock, $deadline ), 'In time as without a deadline.' );
		self::assertSame( self::COUNTS[ $series ], array_count_values( $clock->gates ), 'Each gate as often as written down: ' . var_export( array_count_values( $clock->gates ), true ) . ' ' . hash( 'sha256', implode( "\n", $clock->gates ) ) );
		self::assertSame( self::ORDER[ $series ], hash( 'sha256', implode( "\n", $clock->gates ) ), 'The gates in the order written down.' );
		self::assertSame( 0, $clock->others, 'No other reading of the clock.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'No stop.' );
		self::assertNotSame( array(), self::steps_of( $clock, 'read ' ), 'The sidecars are read through the seam.' );
	}

	#[DataProvider( 'gates' )]
	public function testOverAtOneGateTheSignedManifestStopsThereWithoutAStepOrAReadingOfTheClockAfterIt( string $series, string $gate, int $pick ): void {
		$this->stoppedAt( $series, $gate, $pick );
	}

	/**
	 * The gate where each translation of what a reader below answered into a
	 * refusal of its own would speak, over (Codex round 1 on the contract,
	 * finding 4: each translation reached by itself): the reading the
	 * translation belongs to, the gate and which time it is asked (in
	 * taken() the first file read is the record of the package in find(),
	 * the second its mark; in foreign() the binding is read in its form
	 * first, the entry of the group last).
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function translations(): array {
		return array(
			'get(): the file read, never corrupt'                    => array( 'get', 'AB_MCP_Payload_File::read#6', 0 ),
			'group(): the way to the folder of the group, never unsafe' => array( 'get', 'AB_MCP_Protected_Store::way#1', 1 ),
			'groups(): the way to the area, never unsafe'            => array( 'groups', 'AB_MCP_Protected_Store::way#1', 0 ),
			'groups(): the listing, never unlisted'                  => array( 'groups', 'AB_MCP_Protected_Store::entries#2', 0 ),
			'taken(): the mark read, never incomplete'               => array( 'taken', 'AB_MCP_Payload_File::read#6', 1 ),
			'lines(): the chunk inflated, never unread'              => array( 'taken', 'AB_MCP_Sync_Import::inflate#2', 0 ),
			'verify(): the manifest spelt, never spelling'           => array( 'taken', 'AB_MCP_Sync_Canon::encode#1', 0 ),
			'ready(): the state read, never not ready'               => array( 'ready', 'AB_MCP_Sync_Code_Stage::state#2', 0 ),
			'ready(): the binding read, its stop passed on'          => array( 'ready', 'AB_MCP_Protected_Store::entry#1', 0 ),
			'state(): the state read, never unknown'                 => array( 'state', 'AB_MCP_Payload_File::read#6', 0 ),
			'foreign(): the binding read, never not of its form'     => array( 'foreign', 'AB_MCP_Shape::fields#1', 0 ),
			'foreign(): the entry read in its form, never not of its form' => array( 'foreign', 'AB_MCP_Shape::fields#1', 5 ),
			'entry(): the sidecar read, never not read whole'        => array( 'foreign', 'AB_MCP_Shape::decoded#2', 0 ),
		);
	}

	#[DataProvider( 'translations' )]
	public function testOverWhereATranslationWouldSpeakTheReaderPassesTheStopOnNeverItsOwnRefusal( string $series, string $gate, int $pick ): void {
		$this->stoppedAt( $series, $gate, $pick );
	}

	/**
	 * A reading over at the pick-th time it asks a gate: the stop its answer
	 * in full, by the same way up to that gate, without a step of the store
	 * or a reading of the clock after it.
	 */
	private function stoppedAt( string $series, string $gate, int $pick ): void {
		$base     = $this->in_time( $series );
		$k        = self::place( $base->gates, $gate, $pick );
		$clock    = DeadlineClock::late_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "The same way up to reading $k (each gate before it in time), and no reading of the clock after it." );
		self::assertSame( $base->until_gate( $k ), $clock->events, 'The same steps up to the gate, none after it.' );
		self::assertSame( 0, $clock->others );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	#[DataProvider( 'series' )]
	public function testTheClockUnknownAtAMiddleGateStopsTheSignedManifestAsItsOwnStop( string $series ): void {
		$base     = $this->in_time( $series );
		$k        = intdiv( count( $base->gates ) + 1, 2 );
		$clock    = DeadlineClock::unknown_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "Reading $k: " . $base->gates[ $k - 1 ] );
		self::assertSame( $base->until_gate( $k ), $clock->events );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_clock', self::CLOCK_TEXT );
	}

	/** @return array<string,array{string,string}> */
	public static function sidecars(): array {
		$out = array();
		foreach ( array( 'single', 'two', 'ready' ) as $series ) {
			foreach ( array( 'first', 'last' ) as $which ) {
				$out[ $series . ': the ' . $which . ' sidecar' ] = array( $series, $which );
			}
		}
		return $out;
	}

	#[DataProvider( 'sidecars' )]
	public function testTheTimeRunningOutWhileASidecarIsReadStopsTheSignedManifestAtItsNextGate( string $series, string $which ): void {
		// Codex round 3 on the contract of locks, finding 7: the deadline
		// runs out during the readings of the sidecars of the search of the
		// groups; the reading stops before its next step.
		$base     = $this->in_time( $series );
		$reads    = self::steps_of( $base, 'read ' );
		$nth      = 'first' === $which ? 1 : count( $reads );
		$k        = $reads[ $nth - 1 ] + 1;
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading(
			$series,
			$clock,
			$deadline,
			array(
				array(
					'method' => 'read',
					'nth'    => $nth,
					'kind'   => 'before',
					'before' => static function ( $method, $args ) use ( $clock ) {
						$clock->step( $method . ' ' . basename( (string) $args[0] ) );
						$clock->jump();
					},
				),
			)
		);
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "Stopped at the gate after the read, reading $k: " . $base->gates[ $k - 1 ] );
		self::assertSame( $base->until_gate( $k ), $clock->events, 'The sidecar read, then the gate, nothing after it.' );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	/* ------------------------------------- signed(): the refusals of a reader */

	/**
	 * A series a reader refuses in time: the refusal (its code and its text,
	 * never the stop), the same without a deadline; the deadline over at its
	 * last gate before the refusal: the stop, never the refusal.
	 *
	 * @param string $series Key of the series.
	 * @param string $code   The code of the refusal.
	 * @param string $text   A sentence of its message.
	 * @param string $last   The last gate before the refusal.
	 */
	private function refusedInTimeAndStoppedWhenLate( string $series, string $code, string $text, string $last ): void {
		$base     = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $base, $deadline );
		self::assertInstanceOf( WP_Error::class, $answer, 'In time: refused.' );
		self::assertSame( $code, $answer->get_error_code(), $answer->get_error_message() );
		self::assertStringContainsString( $text, $answer->get_error_message() );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'A refusal of the reader, no stop.' );
		self::assertSame( $last, end( $base->gates ), 'The last gate before the refusal.' );
		$without = $this->reading( $series, DeadlineClock::in_time(), null );
		self::assertInstanceOf( WP_Error::class, $without );
		self::assertSame( array( $code, $answer->get_error_message() ), array( $without->get_error_code(), $without->get_error_message() ), 'Without a deadline the same refusal.' );

		$k        = count( $base->gates );
		$clock    = DeadlineClock::late_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( $base->gates, $clock->gates, 'The same way up to the last gate.' );
		self::assertSame( $base->until_gate( $k ), $clock->events );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	public function testAChunkThatDoesNotMatchItsHashIsCorruptInTimeAndTheStopWhenLateRightBeforeItsHash(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'corrupt', 'ab_mcp_store_corrupt', 'failed its check against its SHA-256', 'AB_MCP_Payload_File::read#5' );
	}

	public function testAMarkThatDoesNotReadIsIncompleteInTimeAndTheStopWhenLateRightBeforeItsHash(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'mark', 'ab_mcp_sync_import_incomplete', 'is not complete on this site yet', 'AB_MCP_Payload_File::read#5' );
	}

	public function testAChunkThatIsNoGzipIsUnreadInTimeAndTheStopWhenLateBeforeItsFirstPiece(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'unread', 'ab_mcp_sync_import_unread', 'does not unpack as it did when it was taken over', 'AB_MCP_Sync_Import::inflate#2' );
	}

	public function testNoListOfHeldObjectsIsHeldInTimeAndTheStopWhenLateBeforeItIsDecoded(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'held', 'ab_mcp_sync_import_held', 'does not read back', 'AB_MCP_Sync_Import::held_list#3' );
	}

	public function testAPackageThisSiteDoesNotHoldIsUnknownInTimeAndTheStopWhenLateAfterTheSearch(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'missing', 'ab_mcp_sync_import_unknown', 'This site holds no package', 'AB_MCP_Sync_Import::find#2' );
	}

	public function testAPackageNoLongerValidIsExpiredInTimeAndTheStopWhenLateBeforeItsSignatureIsChecked(): void {
		// The validity of a package keeps its own clock (AB_MCP_Sync_Run::now(),
		// not the clock of the budget): a month on.
		$now                    = time() + 30 * DAY_IN_SECONDS;
		AB_MCP_Sync_Run::$clock = static function () use ( $now ) {
			return $now;
		};
		$base     = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( 'single', $base, $deadline );
		self::assertInstanceOf( WP_Error::class, $answer );
		self::assertSame( 'ab_mcp_sync_manifest_expired', $answer->get_error_code(), $answer->get_error_message() );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ) );
		self::assertSame( 'AB_MCP_Sync_Manifest::verify#2', end( $base->gates ), 'The last gate before the anchor, the signature and the validity.' );
		$k        = count( $base->gates );
		$clock    = DeadlineClock::late_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( 'single', $clock, $deadline );
		self::assertSame( $base->gates, $clock->gates );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	public function testAValueThatIsNoDeadlineIsRefusedWithoutAReadingOfTheClockOrAStep(): void {
		$made = AB_MCP_Sync_Deadline::at( -1 );
		self::assertRefused( $made, 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'A time below 0 makes no deadline.' );
		foreach ( array( DeadlineClock::UNTIL, 'soon', $made ) as $value ) {
			$clock  = DeadlineClock::in_time();
			$answer = $this->reading( 'single', $clock, $value );
			self::assertRefused( $answer, 'ab_mcp_read_deadline', self::DEADLINE_TEXT, gettype( $value ) );
			self::assertSame( array( array(), 0, array() ), array( $clock->gates, $clock->others, $clock->events ), 'No clock read, nothing of the store read: ' . gettype( $value ) );
		}
	}

	public function testAStoreThisRequestHasNotDecidedIsNotDecidedByAReadingUnderADeadline(): void {
		// Codex round 2 on this part, finding 2: a reader under a deadline
		// never starts the decision of the store (its self-check), and says so.
		$proof = new ReflectionProperty( AB_MCP_Protected_Store::class, 'proof' );
		AB_MCP_Protected_Store::forget();
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( 'single', $clock, $deadline );
		self::assertStopped( $answer, $deadline, 'ab_mcp_store_undecided', self::UNDECIDED_TEXT );
		self::assertNull( $proof->getValue(), 'The store not decided by the reading.' );
		self::assertSame( array( 'AB_MCP_Sync_Report::environment#1', 'AB_MCP_Sync_Import::taken#1', 'AB_MCP_Protected_Store::groups#1' ), $clock->gates, 'Stopped at the gate of the first reader of the store, nothing of it read.' );
		self::assertSame( $clock->gates, $clock->events );
		// Without a deadline the reading decides it, as before.
		self::assertSame( self::signed_answer(), $this->reading( 'single', DeadlineClock::in_time(), null ) );
		self::assertNotNull( $proof->getValue() );
	}

	/* ---------------------------------------------- ready(): not ready, late */

	/**
	 * A stage ready() does not take for these values: «not ready» (null) in
	 * time and without a deadline; the deadline over at its last gate: the
	 * stop, never null.
	 *
	 * @param string $series Key of the reading.
	 * @param string $last   The last gate before the answer.
	 */
	private function notReadyInTimeAndStoppedWhenLate( string $series, string $last ): void {
		$base     = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		self::assertNull( $this->reading( $series, $base, $deadline ), 'In time: not ready.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ) );
		self::assertSame( $last, end( $base->gates ), 'The last gate before the answer.' );
		self::assertNull( $this->reading( $series, DeadlineClock::in_time(), null ), 'Without a deadline: not ready.' );
		$k        = count( $base->gates );
		$clock    = DeadlineClock::late_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( $base->gates, $clock->gates );
		self::assertSame( $base->until_gate( $k ), $clock->events );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	public function testAStageOfAnotherHashIsNotReadyInTimeAndTheStopWhenLateAfterItsState(): void {
		$this->notReadyInTimeAndStoppedWhenLate( 'ready_hash', 'AB_MCP_Sync_Code_Stage::state#2' );
	}

	public function testAStageBoundToAnotherRunIsNotReadyInTimeAndTheStopWhenLateBeforeTheAnswer(): void {
		$this->notReadyInTimeAndStoppedWhenLate( 'ready_foreign', 'AB_MCP_Sync_Code_Stage::ready#1' );
	}

	/* ------------------------------------ R12 part 2: the way of the tree */

	/** @return array<string,array{string}> */
	public static function tree_series(): array {
		return array(
			'tree'    => array( 'tree' ),
			'bigtree' => array( 'bigtree' ),
			'unit'    => array( 'unit' ),
		);
	}

	/**
	 * Each gate of each reading of the way of the tree by itself: the first,
	 * a middle and the last time the reading asks it (as gates()).
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function tree_way_gates(): array {
		return self::part_2_gates( array_keys( self::tree_series() ) );
	}

	/**
	 * @param string[] $readings Keys of PART_2_COUNTS.
	 * @return array<string,array{string,string,int}>
	 */
	private static function part_2_gates( array $readings ): array {
		$out = array();
		foreach ( $readings as $series ) {
			foreach ( self::PART_2_COUNTS[ $series ] as $gate => $m ) {
				foreach ( array_unique( array( 0, intdiv( $m - 1, 2 ), $m - 1 ) ) as $pick ) {
					$out[ sprintf( '%s: %s, %d of %d', $series, $gate, $pick + 1, $m ) ] = array( $series, $gate, $pick );
				}
			}
		}
		return $out;
	}

	#[DataProvider( 'tree_series' )]
	public function testInTimeTheTreeAsksAtExactlyItsGatesAndAnswersAsWithoutADeadline( string $series ): void {
		$this->inTimeAsWithout( $series );
	}

	/**
	 * A reading of R12 part 2 in time: the answer as without a deadline, its
	 * steps and its other readings of the clock too (as many, in the same
	 * order), and exactly its gates as written down.
	 */
	private function inTimeAsWithout( string $series ): void {
		$plain   = DeadlineClock::in_time();
		$without = $this->reading( $series, $plain, null );
		self::assertNotInstanceOf( WP_Error::class, $without, 'Without a deadline: ' . ( $without instanceof WP_Error ? $without->get_error_code() : '' ) );
		self::assertSame( array(), $plain->gates, 'Without a deadline no deadline asked.' );
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		self::assertSame( $without, $this->reading( $series, $clock, $deadline ), 'In time as without a deadline.' );
		self::assertSame( self::PART_2_COUNTS[ $series ], array_count_values( $clock->gates ), 'Each gate as often as written down: ' . var_export( array_count_values( $clock->gates ), true ) . ' ' . hash( 'sha256', implode( "\n", $clock->gates ) ) );
		self::assertSame( self::PART_2_ORDER[ $series ], hash( 'sha256', implode( "\n", $clock->gates ) ), 'The gates in the order written down.' );
		self::assertSame( $plain->events, self::steps( $clock ), 'The same steps and other readings of the clock, in the same order, as without a deadline.' );
		self::assertSame( array( $plain->others, $plain->others ), array( $clock->others, count( array_keys( $plain->events, 'step: clock', true ) ) ), 'As many other readings of the clock as without a deadline, each kept as a step.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'No stop.' );
		self::assertNotSame( array(), $plain->events, 'Its steps are seen.' );
	}

	#[DataProvider( 'tree_way_gates' )]
	public function testOverAtOneGateTheTreeStopsThereWithoutAStepOrAReadingOfTheClockAfterIt( string $series, string $gate, int $pick ): void {
		$this->haltedAt( $series, $gate, $pick );
	}

	/**
	 * The gate where each translation of the way of the tree would speak,
	 * over (Codex round 1 on the contract, finding 4): tree() passes the stop
	 * of its reading on (never a tree changed, missing or unsafe); unit()
	 * stops (never unreadable after its text, never too large in its first
	 * call); and the reads of the large file the picks of the gates leave
	 * out (after a part with more to come, the one that finds its end).
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function tree_translations(): array {
		return array(
			'tree(): the tree read from its first gate, never changed' => array( 'tree', 'AB_MCP_Sync_File_Manifest::whole#1', 0 ),
			'tree(): the text of its manifest, never changed'          => array( 'tree', 'AB_MCP_Sync_File_Manifest::text#1', 0 ),
			'tree(): the hash about to be given, never changed'        => array( 'tree', 'AB_MCP_Sync_File_Manifest::whole#2', 0 ),
			'tree(): the place of the tree, never missing or unsafe'   => array( 'tree', 'AB_MCP_Protected_Store::tree#2', 0 ),
			'whole(): the text begun, never unreadable'                => array( 'unit', 'AB_MCP_Sync_File_Manifest::text#1', 0 ),
			'whole(): the text encoded, never unreadable'              => array( 'unit', 'AB_MCP_Sync_Canon::encode#1', 0 ),
			'left(): the unit not asked, never too large'              => array( 'unit', 'AB_MCP_Sync_File_Manifest::whole#1', 0 ),
			'due(): after a part with more to come, never too large'   => array( 'unit', 'AB_MCP_Sync_File_Manifest::file#4', 0 ),
			'due(): before the hash is given, never too large'         => array( 'unit', 'AB_MCP_Sync_File_Manifest::whole#2', 0 ),
			'file(): the read after a part with more to come'          => array( 'bigtree', 'AB_MCP_Sync_File_Manifest::file#3', 3 ),
			'file(): the read that finds the end of the large file'    => array( 'bigtree', 'AB_MCP_Sync_File_Manifest::file#3', 4 ),
		);
	}

	#[DataProvider( 'tree_translations' )]
	public function testOverWhereATranslationOfTheTreeWouldSpeakItPassesTheStopOnNeverItsOwnRefusal( string $series, string $gate, int $pick ): void {
		$this->haltedAt( $series, $gate, $pick );
	}

	/**
	 * A reading of R12 part 2 over at the pick-th time it asks a gate: the
	 * stop its answer, by the same way up to that gate (its steps and its
	 * other readings of the clock in order), and neither a step nor a
	 * reading of the clock after it, nor one of another clock.
	 */
	private function haltedAt( string $series, string $gate, int $pick ): void {
		$base     = $this->in_time( $series );
		$k        = self::place( $base->gates, $gate, $pick );
		$clock    = DeadlineClock::late_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "The same way up to reading $k (each gate before it in time), and no reading of a deadline after it." );
		self::assertSame( $base->until_gate( $k ), $clock->events, 'The same steps and other readings of the clock up to the gate, none after it.' );
		self::assertSame( count( array_keys( $clock->events, 'step: clock', true ) ), $clock->others, 'No reading of the clock but those kept in order.' );
		self::assertHalted( $series, $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	/**
	 * The answer of a reading of R12 part 2 that stopped: of tree() the stop
	 * as it is; of unit() a reading stopped (not complete, no hash, no
	 * entries, no cause: never too large or unreadable), its deadline
	 * holding the stop.
	 */
	private static function assertHalted( string $series, $answer, $deadline, string $code, string $text ): void {
		if ( isset( self::NOT_DOWN[ $series ] ) ) {
			// not_down(): the refusal with the code of the stop and the way of
			// the swap, never code:version_unknown or code:downgrade.
			list( $kind, $ref ) = self::NOT_DOWN[ $series ];
			self::assertRefused( $answer, $code, $text . sprintf( ' So the %s %s is not swapped. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort) and plan again.', $kind, $ref ), 'not_down(): the stop, with the way of the swap.' );
			self::assertRefused( AB_MCP_Sync_Deadline::stop( $deadline ), $code, $text, 'The stop of its deadline.' );
			return;
		}
		if ( 'headread' === $series || 'headlist' === $series ) {
			// head_read(): no head (null); heads(): no files.
			self::assertSame( 'headread' === $series ? null : array(), $answer, $series . ': nothing read once it stopped.' );
			self::assertRefused( AB_MCP_Sync_Deadline::stop( $deadline ), $code, $text, 'The stop of its deadline.' );
			return;
		}
		if ( 'unit' === $series ) {
			self::assertIsArray( $answer, 'unit(): ' . gettype( $answer ) );
			self::assertSame( array( false, true, null, null, null, array() ), array( $answer['complete'], $answer['stopped'], $answer['cause'], $answer['hash'], $answer['lock'], $answer['entries'] ), 'unit(): stopped, never too large or unreadable.' );
			self::assertRefused( AB_MCP_Sync_Deadline::stop( $deadline ), $code, $text, 'The stop of its deadline.' );
			return;
		}
		self::assertStopped( $answer, $deadline, $code, $text );
	}

	#[DataProvider( 'tree_series' )]
	public function testTheClockUnknownAtAMiddleGateStopsTheTreeAsItsOwnStop( string $series ): void {
		$this->unknownAtAMiddleGate( $series );
	}

	/** A reading of R12 part 2 with the clock unknown from its middle gate on: its own stop there. */
	private function unknownAtAMiddleGate( string $series ): void {
		$base     = $this->in_time( $series );
		$k        = intdiv( count( $base->gates ) + 1, 2 );
		$clock    = DeadlineClock::unknown_from( $k );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $clock, $deadline );
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "Reading $k: " . $base->gates[ $k - 1 ] );
		self::assertSame( $base->until_gate( $k ), $clock->events );
		self::assertHalted( $series, $answer, $deadline, 'ab_mcp_read_clock', self::CLOCK_TEXT );
	}

	public function testAnotherHashIsAChangedTreeInTimeAndTheStopWhenLateAtTheLastGateOfItsReading(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'tree_other', 'ab_mcp_store_tree_changed', 'could not be confirmed against the hash it was asked with', 'AB_MCP_Sync_File_Manifest::whole#2' );
	}

	public function testATreeNotThereIsMissingInTimeAndTheStopWhenLateAtItsPlace(): void {
		$this->refusedInTimeAndStoppedWhenLate( 'tree_missing', 'ab_mcp_store_tree_missing', 'The tree is not in its group of the protected store', 'AB_MCP_Protected_Store::tree#2' );
	}

	/**
	 * A clock that is no DeadlineClock (that one answers the time of the
	 * call in time, always): each reading five seconds after the one before,
	 * from NOW; $log keeps each reading, a gate by its name (a DeadlineClock
	 * in time tells them, its time unused), any other as «other».
	 */
	private static function ticking( array &$log ): \Closure {
		$seen = DeadlineClock::in_time();
		$now  = DeadlineClock::NOW;
		return static function () use ( $seen, &$now, &$log ) {
			$gates = count( $seen->gates );
			$steps = count( $seen->step_reads );
			$seen();
			// R12b part 3.2: the check of a helper of the steps is a step (T1),
			// neither a gate nor the time of the call.
			$log[] = count( $seen->gates ) > $gates ? $seen->gates[ $gates ] : ( count( $seen->step_reads ) > $steps ? 'step' : 'other' );
			$now  += 5.0;
			return $now;
		};
	}

	/**
	 * The large tree read with the ticking clock: tree() (or unit(), its
	 * time of the call TREE_BUDGET from NOW) with a deadline until $until, or
	 * without one (null).
	 *
	 * @return array{0:mixed,1:mixed,2:string[]} The answer, the deadline, the log of the clock.
	 */
	private static function ticked( string $how, ?float $until ): array {
		$log      = array();
		$deadline = null === $until ? null : AB_MCP_Sync_Deadline::at( $until );
		\AB_MCP_Sync_Env::$clock = self::ticking( $log );
		try {
			$answer = 'tree' === $how
				? AB_MCP_Protected_Store::tree( 'stage', self::$big['gid'], self::BIG, self::$big['hash'], $deadline )
				: \AB_MCP_Sync_File_Manifest::unit( dirname( self::$big['path'] ), basename( self::$big['path'] ), 'live', DeadlineClock::NOW + AB_MCP_Protected_Store::TREE_BUDGET, true, 'tree', $deadline );
		} finally {
			\AB_MCP_Sync_Env::$clock = null;
		}
		return array( $answer, $deadline, $log );
	}

	public function testTheTreeIsReadUntilTheEarlierOfItsDeadlineAndTreeBudgetInBothOrders(): void {
		// Codex round 1 on the contract, finding 4: the least of the deadline
		// of the caller and TREE_BUDGET, checked in both orders.
		// TREE_BUDGET first (the deadline far): the tree too large for its
		// time, so changed, as without a deadline; the deadline not stopped.
		list( $answer, $deadline, $log ) = self::ticked( 'tree', 1.0e9 );
		self::assertRefused( $answer, 'ab_mcp_store_tree_changed', self::TREE_CHANGED_TEXT, 'TREE_BUDGET first: changed.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'The deadline not stopped.' );
		self::assertSame( 'other', end( $log ), 'Ended by the time of the call (due()), no reading after it.' );
		list( $without, , $plain ) = self::ticked( 'tree', null );
		self::assertRefused( $without, 'ab_mcp_store_tree_changed', self::TREE_CHANGED_TEXT, 'Without a deadline the same.' );
		self::assertSame( array( 'other' ), array_values( array_unique( $plain ) ), 'Without a deadline only the time of the call asked.' );
		$start = array_search( 'other', $log, true );
		self::assertIsInt( $start, 'The start of TREE_BUDGET read.' );
		self::assertGreaterThan( $start + 9, count( $log ), 'TREE_BUDGET ran out within the reading of the tree.' );
		// The deadline first: 15 s after the start of TREE_BUDGET (45 s).
		$begun = DeadlineClock::NOW + 5.0 * ( $start + 1 );
		list( $answer, $deadline, $log ) = self::ticked( 'tree', $begun + 15.0 );
		self::assertStopped( $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
		self::assertStringStartsWith( 'AB_MCP_Sync_File_Manifest::', end( $log ), 'Stopped at a gate of the reading of the tree, nothing read after it: ' . implode( ', ', array_slice( $log, $start ) ) );
		self::assertLessThan( $start + 9, count( $log ), 'Before TREE_BUDGET ran out.' );
		// The same in unit() (its first call): TREE_BUDGET first, too large;
		// the deadline first, stopped, never too large.
		list( $unit, $deadline ) = self::ticked( 'unit', 1.0e9 );
		self::assertSame( array( false, false, 'too_large', null ), array( $unit['complete'], $unit['stopped'], $unit['cause'], $unit['hash'] ), 'TREE_BUDGET first: too large.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ) );
		list( $unit, $deadline ) = self::ticked( 'unit', DeadlineClock::NOW + 15.0 );
		self::assertHalted( 'unit', $unit, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	public function testAValueThatIsNoDeadlineIsRefusedByTheTreeAndTheManifestWithoutAReadingOfTheClockOrAStep(): void {
		$made = AB_MCP_Sync_Deadline::at( -1 );
		foreach ( array( DeadlineClock::UNTIL, 'soon', $made ) as $value ) {
			foreach ( array( 'tree', 'unit' ) as $series ) {
				$clock  = DeadlineClock::in_time();
				$answer = $this->reading( $series, $clock, $value );
				if ( 'tree' === $series ) {
					self::assertRefused( $answer, 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'tree(): ' . gettype( $value ) );
				} else {
					self::assertHalted( $series, $answer, $value, 'ab_mcp_read_deadline', self::DEADLINE_TEXT );
				}
				self::assertSame( array( array(), 0, array() ), array( $clock->gates, $clock->others, $clock->events ), "No clock read, nothing read: $series, " . gettype( $value ) );
			}
		}
	}

	public function testAStoreThisRequestHasNotDecidedIsNotDecidedByTheTreeUnderADeadline(): void {
		$proof = new ReflectionProperty( AB_MCP_Protected_Store::class, 'proof' );
		AB_MCP_Protected_Store::forget();
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( 'tree', $clock, $deadline );
		self::assertStopped( $answer, $deadline, 'ab_mcp_store_undecided', self::UNDECIDED_TEXT );
		self::assertNull( $proof->getValue(), 'The store not decided by the reading.' );
		self::assertSame( array( 'AB_MCP_Protected_Store::tree#1' ), $clock->gates, 'Stopped at its first gate, nothing of the store read.' );
		self::assertSame( $clock->gates, $clock->events );
		self::assertIsArray( $this->reading( 'tree', DeadlineClock::in_time(), null ), 'Without a deadline the reading decides it, as before.' );
		self::assertNotNull( $proof->getValue() );
	}

	/* ----------------------------------------- R12 part 2: the heads read */

	/** @return array<string,array{string}> */
	public static function head_series(): array {
		return array(
			'headread'  => array( 'headread' ),
			'headlist'  => array( 'headlist' ),
			'heads'     => array( 'heads' ),
			'headfile'  => array( 'headfile' ),
			'headtheme' => array( 'headtheme' ),
		);
	}

	/**
	 * Each gate of each reading of the heads by itself (as gates()).
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function head_gates(): array {
		return self::part_2_gates( array_keys( self::head_series() ) );
	}

	#[DataProvider( 'head_series' )]
	public function testInTimeTheHeadsAskAtExactlyTheirGatesAndAnswerAsWithoutADeadline( string $series ): void {
		if ( 'headread' === $series ) {
			// head_read() without a deadline as before (no clock read).
			$this->inTimeAsWithout( $series );
			return;
		}
		// not_down() and heads() always have a deadline: in time the version
		// a head read without a deadline names (heads(): the files by name).
		$clock    = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		self::assertSame( $this->answer( $series ), $this->reading( $series, $clock, $deadline ), 'In time: the version of the live unit, as its head names it.' );
		self::assertSame( self::PART_2_COUNTS[ $series ], array_count_values( $clock->gates ), 'Each gate as often as written down: ' . var_export( array_count_values( $clock->gates ), true ) . ' ' . hash( 'sha256', implode( "\n", $clock->gates ) ) );
		self::assertSame( self::PART_2_ORDER[ $series ], hash( 'sha256', implode( "\n", $clock->gates ) ), 'The gates in the order written down.' );
		self::assertSame( 0, $clock->others, 'No other reading of the clock.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'No stop.' );
		// R12b part 3.2: before each head its file asked, its size read, each
		// through the helper of the steps (a step of its own, T1).
		$file  = array( 'step: is_file (AB_MCP_Sync_Env::head_read)', 'step: is_readable (AB_MCP_Sync_Env::head_read)', 'step: filesize (AB_MCP_Sync_Env::head_read)' );
		$heads = array(
			'headlist'  => array(),
			'heads'     => array_merge( $file, array( 'step: head a-helfer.php' ), $file, array( 'step: head ab-it-frist.php' ) ),
			'headfile'  => array_merge( $file, array( 'step: head ab-it-einzeln.php' ) ),
			'headtheme' => array_merge( $file, array( 'step: head style.css' ) ),
		)[ $series ];
		self::assertSame( $heads, self::steps( $clock ), 'Each head read once, in order (heads(): none).' );
	}

	#[DataProvider( 'head_gates' )]
	public function testOverAtOneGateTheHeadsStopThereWithoutAStepOrAReadingOfTheClockAfterIt( string $series, string $gate, int $pick ): void {
		$this->haltedAt( $series, $gate, $pick );
	}

	/**
	 * The gate where a translation of the heads would speak, over: not_down()
	 * passes the stop of heads() on (its files none) and of head_read() (no
	 * head), and stops before it compares the versions, never deciding on
	 * the empty list or a head not read (code:version_unknown) or on the
	 * versions (code:downgrade); head_read() gives no head once a header
	 * was not taken.
	 *
	 * @return array<string,array{string,string,int}>
	 */
	public static function head_translations(): array {
		return array(
			'not_down(): the entries of the unit, never decided on no files' => array( 'heads', 'AB_MCP_Sync_Code_Swap::heads#1', 0 ),
			'not_down(): the names of the heads, never decided on no files'  => array( 'heads', 'AB_MCP_Sync_Code_Swap::heads#2', 1 ),
			'not_down(): the first head, never version_unknown'              => array( 'heads', 'AB_MCP_Sync_Env::head_read#1', 0 ),
			'not_down(): the head about to be read, never version_unknown'   => array( 'heads', 'AB_MCP_Sync_Env::head_read#4', 1 ),
			'not_down(): a header of the main file, never version_unknown'   => array( 'heads', 'AB_MCP_Sync_Env::head_fields#1', 3 ),
			'not_down(): the head read whole, never version_unknown'         => array( 'heads', 'AB_MCP_Sync_Env::head_read#5', 1 ),
			'not_down(): before the versions are compared'                   => array( 'heads', 'AB_MCP_Sync_Code_Swap::not_down#1', 0 ),
			'head_read(): a header, never a head that names nothing'         => array( 'headread', 'AB_MCP_Sync_Env::head_fields#1', 1 ),
			'heads(): the names, no files'                                   => array( 'headlist', 'AB_MCP_Sync_Code_Swap::heads#2', 1 ),
		);
	}

	#[DataProvider( 'head_translations' )]
	public function testOverWhereATranslationOfTheHeadsWouldSpeakTheStopIsPassedOnNeverAVersionNotKnown( string $series, string $gate, int $pick ): void {
		$this->haltedAt( $series, $gate, $pick );
	}

	#[DataProvider( 'head_series' )]
	public function testTheClockUnknownAtAMiddleGateStopsTheHeadsAsItsOwnStop( string $series ): void {
		$this->unknownAtAMiddleGate( $series );
	}

	/**
	 * A unit not_down() refuses in time: the refusal (its code, never the
	 * stop), its last gate before it; the deadline over at that gate, and at
	 * the last time an earlier gate is asked ($before: the head about to be
	 * read): the stop, never the refusal, by the same way and nothing after
	 * it.
	 *
	 * @param string      $series Key of NOT_DOWN.
	 * @param string      $code   The code of the refusal.
	 * @param string      $text   A sentence of its message.
	 * @param string      $last   The last gate before the refusal.
	 * @param string|null $before An earlier gate, over at its last time too.
	 */
	private function refusedByNotDownInTimeAndStoppedWhenLate( string $series, string $code, string $text, string $last, ?string $before = null ): void {
		$base     = DeadlineClock::in_time();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $series, $base, $deadline );
		self::assertInstanceOf( WP_Error::class, $answer, 'In time: refused, ' . gettype( $answer ) );
		self::assertSame( $code, $answer->get_error_code(), $answer->get_error_message() );
		self::assertStringContainsString( $text, $answer->get_error_message() );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'A refusal of its own, no stop.' );
		self::assertSame( $last, end( $base->gates ), 'The last gate before the refusal.' );
		$at = array( count( $base->gates ) );
		if ( null !== $before ) {
			$at[] = self::place( $base->gates, $before, count( array_keys( $base->gates, $before, true ) ) - 1 );
		}
		foreach ( $at as $k ) {
			$clock    = DeadlineClock::late_from( $k );
			$deadline = DeadlineClock::deadline();
			$answer   = $this->reading( $series, $clock, $deadline );
			self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, "The same way up to reading $k: " . $base->gates[ $k - 1 ] );
			self::assertSame( $base->until_gate( $k ), $clock->events );
			self::assertSame( 0, $clock->others );
			self::assertHalted( $series, $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
		}
	}

	public function testNoHeadThatNamesAPluginIsAVersionNotKnownInTimeAndTheStopWhenLateBeforeTheVersionsAreCompared(): void {
		$this->refusedByNotDownInTimeAndStoppedWhenLate( 'heads_none', 'code:version_unknown', 'could not be read from its head read whole', 'AB_MCP_Sync_Code_Swap::not_down#1' );
	}

	public function testNoPhpFileAtTheTopIsAVersionNotKnownInTimeAndTheStopWhenLateBeforeTheVersionsAreCompared(): void {
		$this->refusedByNotDownInTimeAndStoppedWhenLate( 'heads_empty', 'code:version_unknown', 'could not be read from its head read whole', 'AB_MCP_Sync_Code_Swap::not_down#1' );
	}

	public function testAnOlderVersionOfTheStagingSiteIsADowngradeInTimeAndTheStopWhenLateBeforeTheVersionsAreCompared(): void {
		$this->refusedByNotDownInTimeAndStoppedWhenLate( 'heads_down', 'code:downgrade', 'never goes down a version', 'AB_MCP_Sync_Code_Swap::not_down#1' );
	}

	public function testAHeadReadShorterThanItsFileIsAVersionNotKnownInTimeAndTheStopWhenLateBeforeItIsReadOrTheVersionsCompared(): void {
		$this->refusedByNotDownInTimeAndStoppedWhenLate( 'heads_short', 'code:version_unknown', 'could not be read from its head read whole', 'AB_MCP_Sync_Code_Swap::not_down#1', 'AB_MCP_Sync_Env::head_read#4' );
	}

	public function testAHeadNotReadIsAVersionNotKnownInTimeAndTheStopWhenLateBeforeItIsReadOrTheVersionsCompared(): void {
		$this->refusedByNotDownInTimeAndStoppedWhenLate( 'heads_unread', 'code:version_unknown', 'could not be read from its head read whole', 'AB_MCP_Sync_Code_Swap::not_down#1', 'AB_MCP_Sync_Env::head_read#4' );
	}

	public function testTheHeadsStoppedAreNoFilesAndNotDownPassesTheStopOnNeverDecidingOnTheEmptyList(): void {
		// heads() over at its first gate: no files, its deadline holding the
		// stop.
		$clock    = DeadlineClock::late_from( 1 );
		$deadline = DeadlineClock::deadline();
		self::assertSame( array(), $this->reading( 'headlist', $clock, $deadline ), 'heads(): no files once it stopped.' );
		self::assertRefused( AB_MCP_Sync_Deadline::stop( $deadline ), 'ab_mcp_read_late', self::LATE_TEXT, 'The stop of its deadline.' );
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::heads#1' ), $clock->events );
		// In time a unit without a PHP file at its top has no files either,
		// and not_down() decides on them: the version is not known.
		$clock = DeadlineClock::in_time();
		$none  = $this->reading( 'heads_empty', $clock, DeadlineClock::deadline() );
		self::assertSame( 'code:version_unknown', $none->get_error_code() );
		self::assertNotContains( 'AB_MCP_Sync_Code_Swap::heads#2', $clock->gates, 'No name of a head: the list empty.' );
		// The empty list of a stop: not_down() passes the stop on, never a
		// version not known, and reads no head and asks no clock after it.
		$clock    = DeadlineClock::late_from( 1 );
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( 'heads', $clock, $deadline );
		self::assertHalted( 'heads', $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
		self::assertSame( array( array( 'AB_MCP_Sync_Code_Swap::heads#1' ), 0 ), array( $clock->events, $clock->others ), 'Nothing after the stop: no head read, no clock asked.' );
	}

	public function testAValueThatIsNoDeadlineGivesNoHeadWithoutAReadingOfTheClockOrAStep(): void {
		$made = AB_MCP_Sync_Deadline::at( -1 );
		foreach ( array( DeadlineClock::UNTIL, 'soon', $made ) as $value ) {
			$clock = DeadlineClock::in_time();
			self::assertHalted( 'headread', $this->reading( 'headread', $clock, $value ), $value, 'ab_mcp_read_deadline', self::DEADLINE_TEXT );
			self::assertSame( array( array(), 0, array() ), array( $clock->gates, $clock->others, $clock->events ), 'No clock read, no head read: ' . gettype( $value ) );
		}
	}

	/* ------------------------------------------------------- prepare() */

	/** What prepare() is asked with for the swap of the unit, made new from the stage: its run, unit, the series single and a time limit on the clock of the test. */
	private static function swap_args(): array {
		return array(
			'run_id' => self::RUN,
			'unit'   => array(
				'seq'          => 3,
				'op'           => 'swap',
				'stage_seq'    => 2,
				'n'            => 1,
				'kind'         => 'plugin',
				'ref'          => self::UNIT,
				'unit_op'      => 'create',
				'pair_id'      => null,
				'pair_hash'    => null,
				'staging_hash' => self::$stage['hash'],
				'version'      => array(
					'staging' => '1.2.0',
					'live'    => null,
				),
				'runs'         => false,
				'accept'       => false,
			),
			'env'    => self::$series['single'],
			'stage'  => array(
				'gid'    => self::$stage['gid'],
				'name'   => self::UNIT,
				'hash'   => self::$stage['hash'],
				'source' => 'packages',
				'files'  => 3,
				'bytes'  => self::$stage['bytes'],
			),
			'until'  => DeadlineClock::NOW + 120.0,
		);
	}

	/**
	 * prepare() of the swap with this clock: the test requests answered
	 * through the seam of the swap, its reads of the place, of AlphaBridge
	 * itself and of the devices kept as steps (swap …), the steps of the
	 * store as in reading(), and the decision of the store (decide(), seen
	 * through the filter query of its reading of the option) kept as a step;
	 * $jump: the time runs out there. Every lock of a swap that answered is
	 * given back.
	 *
	 * Each head read (R12 part 2: a unit there) is kept as a step too
	 * (head …).
	 *
	 * @param DeadlineClock $clock The clock.
	 * @param bool          $jump  Whether the time runs out while the store is decided.
	 * @param int|null      $others Set: the readings of the clock that are no deadline's, when the store was decided.
	 * @param array|null    $swap  What prepare() is asked with (with_live()); null: swap_args().
	 * @return mixed
	 */
	private function prepared( DeadlineClock $clock, bool $jump = false, ?int &$others = null, ?array $swap = null ) {
		$clock->install();
		self::watch( $clock );
		$step = static function ( $method, $args ) use ( $clock ) {
			$clock->step( 'swap ' . $method );
		};
		$faults = array(
			array(
				'method' => 'probe',
				'kind'   => 'return',
				'value'  => array(
					'targets' => array(
						array(
							'target' => 'home',
							'result' => 'ok',
						),
						array(
							'target' => 'mcp',
							'result' => 'ok',
						),
						array(
							'target' => 'admin_post',
							'result' => 'ok',
						),
					),
				),
			),
		);
		foreach ( array( 'scandir', 'lstat', 'own', 'stat' ) as $method ) {
			$faults[] = array(
				'method' => $method,
				'kind'   => 'before',
				'before' => $step,
			);
		}
		AB_MCP_Test_Seam::install( AB_MCP_Sync_Code_Swap::class, 'naht_sync_code_swap', new \AB_MCP_Sync_Code_Swap_Calls(), $faults );
		AB_MCP_Test_Seam::install(
			\AB_MCP_Sync_Env::class,
			'naht_sync_env',
			new \AB_MCP_Sync_Env_Calls(),
			array(
				array(
					'method' => 'head_text',
					'kind'   => 'before',
					'before' => static function ( $method, $args ) use ( $clock ) {
						$clock->step( 'head ' . basename( (string) $args[0] ) );
					},
				),
			)
		);
		$seen   = false;
		$decide = static function ( $query ) use ( $clock, $jump, &$seen, &$others ) {
			if ( ! $seen ) {
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					if ( isset( $frame['class'] ) && AB_MCP_Protected_Store::class === $frame['class'] && 'decide' === $frame['function'] ) {
						$seen   = true;
						$others = $clock->others;
						$clock->step( 'decide' );
						if ( $jump ) {
							$clock->jump();
						}
						break;
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $decide );
		try {
			$answer = AB_MCP_Sync_Code_Swap::prepare( null === $swap ? self::swap_args() : $swap );
			if ( is_array( $answer ) ) {
				self::assertSame( array(), AB_MCP_Sync_Code_Swap::release(), 'Every lock given back.' );
			}
			return $answer;
		} finally {
			remove_filter( 'query', $decide );
			AB_MCP_Test_Seam::restore_all();
		}
	}

	/** No lock of a swap is held: the lock for changes, WordPress's lock for updates and the note of the swap on it. */
	private static function assertNoLock(): void {
		$note = \AB_MCP_Update_Log::running();
		self::assertFalse( \AB_MCP_Change_Lock::held(), 'The lock for changes given back.' );
		self::assertFalse( \AB_MCP_Sync_Rows::option( 'auto_updater.lock' ), 'WordPress\'s lock for updates given back.' );
		self::assertFalse( is_array( $note ) && isset( $note['type'] ) && 'swap' === $note['type'], 'No note of a swap.' );
	}

	/**
	 * prepare() in time with the store not decided in this request: its way
	 * (the clock, the steps), checked.
	 */
	private function prepared_in_time(): DeadlineClock {
		$proof = new ReflectionProperty( AB_MCP_Protected_Store::class, 'proof' );
		AB_MCP_Protected_Store::forget();
		$clock  = DeadlineClock::in_time();
		$answer = $this->prepared( $clock );
		self::assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_code() . ': ' . $answer->get_error_message() : gettype( $answer ) );
		self::assertSame( array( self::$stage['hash'], self::$stage['hash'], null, array( 'staging' => '1.2.0', 'live' => null ) ), array( $answer['signed'], $answer['tree']['hash'], $answer['live'], $answer['version'] ), 'The stage, signed, of a unit made new.' );
		self::assertNotNull( $proof->getValue(), 'The store decided in this request.' );
		self::assertNoLock();
		return $clock;
	}

	/**
	 * The gates of the tree of the stage read in time (R12 part 2, its
	 * reading in the swap between ready() and signed()), its answer the one
	 * without a deadline, its gates as written down (PART_2_COUNTS).
	 *
	 * @return string[]
	 */
	private function tree_gates(): array {
		return $this->in_time( 'tree' )->gates;
	}

	public function testThePreparedSwapDecidesTheStoreBeforeItsDeadlineAndReadsTheStageAndTheSignedManifestUnderIt(): void {
		// Codex round 2 on this part, finding 2: the store is decided before
		// the first reading under the deadline (until − keep), uninterrupted;
		// the readings of ready(), of the tree (R12 part 2) and of signed()
		// then run under the one deadline, checked before the next step
		// (unchanged()).
		$ready  = $this->in_time( 'ready' )->gates;
		$tree   = $this->tree_gates();
		$signed = $this->in_time( 'single' )->gates;
		$clock  = $this->prepared_in_time();
		self::assertSame( array_merge( array( 'AB_MCP_Sync_Code_Swap::read_deadline#1' ), $ready, $tree, $signed ), $clock->gates, 'The first check of the deadline, then the gates of ready(), of tree() and of signed(), each as in their readings.' );
		$decide = array_search( 'step: decide', $clock->events, true );
		$first  = array_search( 'AB_MCP_Sync_Code_Swap::read_deadline#1', $clock->events, true );
		$place  = array_search( 'step: swap scandir', $clock->events, true );
		self::assertIsInt( $decide, 'The store decided in prepare().' );
		self::assertTrue( $decide < $first && $first < $place, 'The store decided, then the first check of the deadline, then the place of the unit (unchanged()): ' . implode( ', ', array_slice( $clock->events, 0, 8 ) ) );
	}

	public function testTheTimeRunningOutWhileTheStoreIsDecidedStopsTheSwapAtItsFirstCheckBeforeAnythingIsRead(): void {
		// The decision is not interrupted, its time is the reading's (until
		// stays): over after it, the swap stops at the first check of its
		// deadline, before unchanged(), and reads nothing more.
		$proof = new ReflectionProperty( AB_MCP_Protected_Store::class, 'proof' );
		AB_MCP_Protected_Store::forget();
		$clock  = DeadlineClock::in_time();
		$others = null;
		$answer = $this->prepared( $clock, true, $others );
		self::assertRefused( $answer, 'ab_mcp_read_late', self::LATE_TEXT . self::SWAP_TAIL, 'The stop, with the way of the swap.' );
		self::assertNotNull( $proof->getValue(), 'The store decided, whole.' );
		self::assertSame( array( 'AB_MCP_Sync_Code_Swap::read_deadline#1' ), $clock->gates, 'Stopped at the first check of the deadline.' );
		self::assertSame( 'AB_MCP_Sync_Code_Swap::read_deadline#1', end( $clock->events ), 'Nothing after it: no place of the unit read, nothing of the stage.' );
		self::assertContains( 'step: decide', $clock->events );
		self::assertSame( $others, $clock->others, 'No reading of the clock after the decision but the check (no File_Manifest::now()).' );
		self::assertNoLock();
	}

	/**
	 * The swap over at one gate of its readings: the first check, the first
	 * and the last gate of ready(), the first and the last of signed().
	 *
	 * @return array<string,array{string}>
	 */
	public static function swap_gates(): array {
		$out = array();
		foreach ( array( 'the first check', 'the first gate of ready()', 'the last gate of ready()', 'the first gate of signed()', 'the last gate of signed()' ) as $which ) {
			$out[ $which ] = array( $which );
		}
		return $out;
	}

	#[DataProvider( 'swap_gates' )]
	public function testOverAtOneGateOfItsReadingsTheSwapStopsThereGivesItsLocksBackAndReadsNothingAfterIt( string $which ): void {
		$ready = count( $this->in_time( 'ready' )->gates );
		$tree  = count( $this->tree_gates() );
		$base  = $this->prepared_in_time();
		$k     = array(
			'the first check'            => 1,
			'the first gate of ready()'  => 2,
			'the last gate of ready()'   => 1 + $ready,
			'the first gate of signed()' => 2 + $ready + $tree,
			'the last gate of signed()'  => count( $base->gates ),
		)[ $which ];
		AB_MCP_Protected_Store::forget();
		$clock  = DeadlineClock::late_from( $k );
		$answer = $this->prepared( $clock );
		self::assertRefused( $answer, 'ab_mcp_read_late', self::LATE_TEXT . self::SWAP_TAIL, "The stop at reading $k, with the way of the swap." );
		self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, 'The same way up to the gate: ' . $base->gates[ $k - 1 ] );
		self::assertSame( $base->until_gate( $k ), $clock->events, 'Nothing read after it (no tree, no place, no device).' );
		self::assertNoLock();
	}

	/* ------------------------------- prepare() of a unit there (R12 part 2) */

	/**
	 * The swap of a unit the plan updates (R12 part 2, the heads in the
	 * swap): the plugin of put_live() (version 1.0.0, not active) in the
	 * folder of the plugins of the site, and an active pair of it whose base
	 * is that unit read now; $fn gets what prepare() is asked with. The unit
	 * and the pair are taken away again.
	 *
	 * @param callable $fn function ( array $swap ).
	 */
	private function with_live( callable $fn ): void {
		global $wpdb;
		$target = WP_PLUGIN_DIR . '/' . self::UNIT;
		self::assertDirectoryDoesNotExist( $target, 'The place of the unit empty before.' );
		$pair = null;
		try {
			self::put_live( $target );
			$read = \AB_MCP_Sync_File_Manifest::unit( WP_PLUGIN_DIR, self::UNIT, 'live', INF, true, 'plugin' );
			self::assertIsString( $read['hash'], 'The unit there read whole.' );
			$pair                              = \AB_MCP_IT_Pair_Rows::put(
				array(
					'source_id'    => 'src-r12-2b',
					'kind'         => 'plugin',
					'live_ref'     => self::UNIT,
					'staging_ref'  => self::UNIT,
					'canon'        => AB_MCP_Sync_Canon::VERSION,
					'base_live'    => $read['hash'],
					'base_staging' => self::$stage['hash'],
					'base_origin'  => 'proposal',
				)
			);
			$swap                              = self::swap_args();
			$swap['unit']['unit_op']           = 'update';
			$swap['unit']['pair_id']           = (int) $pair['id'];
			$swap['unit']['pair_hash']         = \AB_MCP_Sync_Pairs::snapshot_hash( \AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) );
			$swap['unit']['version']['live']   = '1.0.0';
			$fn( $swap );
		} finally {
			if ( is_array( $pair ) ) {
				$wpdb->delete( AB_MCP_Sync_Schema::table( 'pairs' ), array( 'id' => (int) $pair['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test code.
				\AB_MCP_Sync_Pairs::forget();
			}
			if ( is_dir( $target ) ) {
				self::remove_tree( $target );
			}
		}
	}

	/** prepare() of the unit there in time, the store not decided in this request: its way (the clock, the steps), checked. */
	private function prepared_there_in_time( array $swap ): DeadlineClock {
		$proof  = new ReflectionProperty( AB_MCP_Protected_Store::class, 'proof' );
		$others = null;
		AB_MCP_Protected_Store::forget();
		$clock  = DeadlineClock::in_time();
		$answer = $this->prepared( $clock, false, $others, $swap );
		self::assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_code() . ': ' . $answer->get_error_message() : gettype( $answer ) );
		self::assertSame( array( self::$stage['hash'], self::$stage['hash'], 'folder', array( 'staging' => '1.2.0', 'live' => '1.0.0' ) ), array( $answer['signed'], $answer['tree']['hash'], $answer['live']['form'], $answer['version'] ), 'The stage, signed, of a unit there; its version read from its head.' );
		self::assertNotNull( $proof->getValue(), 'The store decided in this request.' );
		self::assertNoLock();
		return $clock;
	}

	public function testThePreparedSwapOfAUnitThereReadsItsHeadsUnderTheDeadlineAfterTheSignedManifest(): void {
		// R12 part 2: not_down() reads the heads of the unit there under the
		// one deadline, after ready(), tree() and signed(), each gate as in
		// its reading (heads); then AlphaBridge itself is asked (own()).
		$ready  = $this->in_time( 'ready' )->gates;
		$tree   = $this->tree_gates();
		$signed = $this->in_time( 'single' )->gates;
		$heads  = $this->in_time( 'heads' )->gates;
		$this->with_live(
			function ( array $swap ) use ( $ready, $tree, $signed, $heads ): void {
				$clock = $this->prepared_there_in_time( $swap );
				self::assertSame( array_merge( array( 'AB_MCP_Sync_Code_Swap::read_deadline#1' ), $ready, $tree, $signed, $heads ), $clock->gates, 'The first check, then the gates of ready(), tree(), signed() and of the heads, each as in their readings.' );
				$last = array_search( 'AB_MCP_Sync_Code_Swap::not_down#1', $clock->events, true );
				$own  = array_search( 'step: swap own', $clock->events, true );
				self::assertTrue( is_int( $last ) && is_int( $own ) && $last < $own, 'The heads read and the deadline asked, then AlphaBridge itself: ' . implode( ', ', array_slice( $clock->events, -6 ) ) );
				self::assertSame( array( 'step: head a-helfer.php', 'step: head ab-it-frist.php' ), array_values( preg_grep( '/^step: head /', $clock->events ) ), 'Its two heads read, in order.' );
			}
		);
	}

	/**
	 * The swap of a unit there over at one gate of its heads: the first and
	 * the last gate of heads(), the first gate of the first head, the last
	 * gate of the last head, the check before the versions are compared.
	 *
	 * @return array<string,array{string,int}>
	 */
	public static function swap_head_gates(): array {
		return array(
			'the first gate of heads()'        => array( 'AB_MCP_Sync_Code_Swap::heads#1', 0 ),
			'the last gate of heads()'         => array( 'AB_MCP_Sync_Code_Swap::heads#2', 1 ),
			'the first gate of the first head' => array( 'AB_MCP_Sync_Env::head_read#1', 0 ),
			'the last gate of the last head'   => array( 'AB_MCP_Sync_Env::head_read#5', 1 ),
			'the check before the versions'    => array( 'AB_MCP_Sync_Code_Swap::not_down#1', 0 ),
		);
	}

	#[DataProvider( 'swap_head_gates' )]
	public function testOverAtOneGateOfItsHeadsTheSwapOfAUnitThereStopsThereGivesItsLocksBackAndReadsNothingAfterIt( string $gate, int $pick ): void {
		$this->with_live(
			function ( array $swap ) use ( $gate, $pick ): void {
				$base = $this->prepared_there_in_time( $swap );
				$k    = self::place( $base->gates, $gate, $pick );
				AB_MCP_Protected_Store::forget();
				$clock  = DeadlineClock::late_from( $k );
				$others = null;
				$answer = $this->prepared( $clock, false, $others, $swap );
				self::assertRefused( $answer, 'ab_mcp_read_late', self::LATE_TEXT . self::SWAP_TAIL, "The stop at reading $k, with the way of the swap, never a version not known." );
				self::assertSame( array_slice( $base->gates, 0, $k ), $clock->gates, 'The same way up to the gate: ' . $base->gates[ $k - 1 ] );
				self::assertSame( $base->until_gate( $k ), $clock->events, 'Nothing read after it (no head, not AlphaBridge itself, no device).' );
				self::assertNoLock();
			}
		);
	}
}
