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

	/** The unit of the series. */
	private const UNIT = 'ab-it-frist';

	/** The run the stage is bound to. */
	private const RUN = 7;

	/**
	 * The gates of each reading in time (DeadlineClock): each gate with how
	 * often the reading asks it, in the order it first asks it, from a run
	 * in time and written down; ORDER holds the whole order.
	 */
	const COUNTS = array(
		'single' => array(
			'AB_MCP_Sync_Report::environment#1' => 1,
			'AB_MCP_Sync_Import::taken#1'       => 1,
			'AB_MCP_Protected_Store::groups#1'  => 1,
			'AB_MCP_Protected_Store::way#1'     => 16,
			'AB_MCP_Protected_Store::way#2'     => 23,
			'AB_MCP_Protected_Store::holds#1'   => 16,
			'AB_MCP_Protected_Store::holds#2'   => 16,
			'AB_MCP_Protected_Store::holds#3'   => 39,
			'AB_MCP_Protected_Store::entries#1' => 1,
			'AB_MCP_Shape::items#1'             => 16,
			'AB_MCP_Protected_Store::entries#2' => 16,
			'AB_MCP_Protected_Store::sidecar#1' => 14,
			'AB_MCP_Shape::decoded#1'           => 14,
			'AB_MCP_Shape::decoded#2'           => 14,
			'AB_MCP_Protected_Store::entries#3' => 1,
			'AB_MCP_Protected_Store::groups#2'  => 7,
			'AB_MCP_Protected_Store::groups#3'  => 1,
			'AB_MCP_Sync_Import::find#1'        => 1,
			'AB_MCP_Protected_Store::files#1'   => 2,
			'AB_MCP_Protected_Store::group#1'   => 7,
			'AB_MCP_Protected_Store::files#2'   => 2,
			'AB_MCP_Protected_Store::files#3'   => 14,
			'AB_MCP_Protected_Store::files#4'   => 2,
			'AB_MCP_Protected_Store::get#1'     => 5,
			'AB_MCP_Protected_Store::get#2'     => 5,
			'AB_MCP_Payload_File::read#1'       => 5,
			'AB_MCP_Payload_File::read#2'       => 5,
			'AB_MCP_Payload_File::read#3'       => 5,
			'AB_MCP_Payload_File::read#4'       => 5,
			'AB_MCP_Payload_File::read#5'       => 5,
			'AB_MCP_Payload_File::read#6'       => 5,
			'AB_MCP_Sync_Import::record#1'      => 1,
			'AB_MCP_Sync_Import::record#2'      => 1,
			'AB_MCP_Sync_Import::taken#2'       => 1,
			'AB_MCP_Sync_Manifest::verify#1'    => 1,
			'AB_MCP_Sync_Canon::prepared#3'     => 22,
			'AB_MCP_Sync_Canon::prepared#2'     => 2,
			'AB_MCP_Sync_Canon::encode#1'       => 1,
			'AB_MCP_Sync_Manifest::shape#2'     => 2,
			'AB_MCP_Sync_Manifest::verify#2'    => 1,
			'AB_MCP_Sync_Manifest::verify#3'    => 1,
			'AB_MCP_Sync_Import::inflate#1'     => 2,
			'AB_MCP_Sync_Import::inflate#2'     => 2,
			'AB_MCP_Sync_Import::inflate#3'     => 2,
			'AB_MCP_Sync_Import::lines#1'       => 5,
			'AB_MCP_Sync_Import::held_list#1'   => 5,
			'AB_MCP_Sync_Import::held_list#2'   => 1,
			'AB_MCP_Sync_Import::held_list#3'   => 1,
			'AB_MCP_Sync_Import::taken#3'       => 1,
			'AB_MCP_Sync_Env::held_here#1'      => 1,
			'AB_MCP_Sync_Env::held_here#2'      => 1,
			'AB_MCP_Sync_Env::held_here#3'      => 4,
			'AB_MCP_Sync_Env::names_held#1'     => 4,
			'AB_MCP_Sync_Env::names_held#2'     => 4,
			'AB_MCP_Sync_Env::names_held#4'     => 2,
			'AB_MCP_Sync_Env::names_held#3'     => 3,
			'AB_MCP_Sync_Env::names_held#5'     => 1,
			'AB_MCP_Sync_Report::environment#2' => 1,
			'AB_MCP_Sync_Code_Stage::signed#1'  => 4,
			'AB_MCP_Sync_Code_Stage::signed#2'  => 3,
			'AB_MCP_Sync_Code_Stage::signed#3'  => 1,
		),
		'two'    => array(
			'AB_MCP_Sync_Report::environment#1' => 2,
			'AB_MCP_Sync_Import::taken#1'       => 2,
			'AB_MCP_Protected_Store::groups#1'  => 2,
			'AB_MCP_Protected_Store::way#1'     => 32,
			'AB_MCP_Protected_Store::way#2'     => 46,
			'AB_MCP_Protected_Store::holds#1'   => 32,
			'AB_MCP_Protected_Store::holds#2'   => 32,
			'AB_MCP_Protected_Store::holds#3'   => 78,
			'AB_MCP_Protected_Store::entries#1' => 2,
			'AB_MCP_Shape::items#1'             => 32,
			'AB_MCP_Protected_Store::entries#2' => 32,
			'AB_MCP_Protected_Store::sidecar#1' => 28,
			'AB_MCP_Shape::decoded#1'           => 28,
			'AB_MCP_Shape::decoded#2'           => 28,
			'AB_MCP_Protected_Store::entries#3' => 2,
			'AB_MCP_Protected_Store::groups#2'  => 14,
			'AB_MCP_Protected_Store::groups#3'  => 2,
			'AB_MCP_Sync_Import::find#1'        => 5,
			'AB_MCP_Protected_Store::files#1'   => 4,
			'AB_MCP_Protected_Store::group#1'   => 14,
			'AB_MCP_Protected_Store::files#2'   => 4,
			'AB_MCP_Protected_Store::files#3'   => 28,
			'AB_MCP_Protected_Store::files#4'   => 4,
			'AB_MCP_Protected_Store::get#1'     => 10,
			'AB_MCP_Protected_Store::get#2'     => 10,
			'AB_MCP_Payload_File::read#1'       => 10,
			'AB_MCP_Payload_File::read#2'       => 10,
			'AB_MCP_Payload_File::read#3'       => 10,
			'AB_MCP_Payload_File::read#4'       => 10,
			'AB_MCP_Payload_File::read#5'       => 10,
			'AB_MCP_Payload_File::read#6'       => 10,
			'AB_MCP_Sync_Import::record#1'      => 2,
			'AB_MCP_Sync_Import::record#2'      => 2,
			'AB_MCP_Sync_Import::taken#2'       => 2,
			'AB_MCP_Sync_Manifest::verify#1'    => 2,
			'AB_MCP_Sync_Canon::prepared#3'     => 44,
			'AB_MCP_Sync_Canon::prepared#2'     => 4,
			'AB_MCP_Sync_Canon::encode#1'       => 2,
			'AB_MCP_Sync_Manifest::shape#2'     => 4,
			'AB_MCP_Sync_Manifest::verify#2'    => 2,
			'AB_MCP_Sync_Manifest::verify#3'    => 2,
			'AB_MCP_Sync_Import::inflate#1'     => 4,
			'AB_MCP_Sync_Import::inflate#2'     => 4,
			'AB_MCP_Sync_Import::inflate#3'     => 4,
			'AB_MCP_Sync_Import::lines#1'       => 8,
			'AB_MCP_Sync_Import::held_list#1'   => 10,
			'AB_MCP_Sync_Import::held_list#2'   => 2,
			'AB_MCP_Sync_Import::held_list#3'   => 2,
			'AB_MCP_Sync_Import::taken#3'       => 2,
			'AB_MCP_Sync_Env::held_here#1'      => 2,
			'AB_MCP_Sync_Env::held_here#3'      => 6,
			'AB_MCP_Sync_Env::names_held#1'     => 6,
			'AB_MCP_Sync_Env::names_held#2'     => 6,
			'AB_MCP_Sync_Env::names_held#4'     => 2,
			'AB_MCP_Sync_Env::names_held#3'     => 3,
			'AB_MCP_Sync_Env::names_held#5'     => 2,
			'AB_MCP_Sync_Env::held_here#2'      => 1,
			'AB_MCP_Sync_Report::environment#2' => 1,
			'AB_MCP_Sync_Code_Stage::signed#1'  => 6,
			'AB_MCP_Sync_Code_Stage::signed#2'  => 3,
			'AB_MCP_Sync_Code_Stage::signed#3'  => 1,
		),
		'taken'  => array(
			'AB_MCP_Sync_Import::taken#1'       => 1,
			'AB_MCP_Protected_Store::groups#1'  => 1,
			'AB_MCP_Protected_Store::way#1'     => 16,
			'AB_MCP_Protected_Store::way#2'     => 23,
			'AB_MCP_Protected_Store::holds#1'   => 16,
			'AB_MCP_Protected_Store::holds#2'   => 16,
			'AB_MCP_Protected_Store::holds#3'   => 39,
			'AB_MCP_Protected_Store::entries#1' => 1,
			'AB_MCP_Shape::items#1'             => 16,
			'AB_MCP_Protected_Store::entries#2' => 16,
			'AB_MCP_Protected_Store::sidecar#1' => 14,
			'AB_MCP_Shape::decoded#1'           => 14,
			'AB_MCP_Shape::decoded#2'           => 14,
			'AB_MCP_Protected_Store::entries#3' => 1,
			'AB_MCP_Protected_Store::groups#2'  => 7,
			'AB_MCP_Protected_Store::groups#3'  => 1,
			'AB_MCP_Sync_Import::find#1'        => 1,
			'AB_MCP_Protected_Store::files#1'   => 2,
			'AB_MCP_Protected_Store::group#1'   => 7,
			'AB_MCP_Protected_Store::files#2'   => 2,
			'AB_MCP_Protected_Store::files#3'   => 14,
			'AB_MCP_Protected_Store::files#4'   => 2,
			'AB_MCP_Protected_Store::get#1'     => 5,
			'AB_MCP_Protected_Store::get#2'     => 5,
			'AB_MCP_Payload_File::read#1'       => 5,
			'AB_MCP_Payload_File::read#2'       => 5,
			'AB_MCP_Payload_File::read#3'       => 5,
			'AB_MCP_Payload_File::read#4'       => 5,
			'AB_MCP_Payload_File::read#5'       => 5,
			'AB_MCP_Payload_File::read#6'       => 5,
			'AB_MCP_Sync_Import::record#1'      => 1,
			'AB_MCP_Sync_Import::record#2'      => 1,
			'AB_MCP_Sync_Import::taken#2'       => 1,
			'AB_MCP_Sync_Manifest::verify#1'    => 1,
			'AB_MCP_Sync_Canon::prepared#3'     => 22,
			'AB_MCP_Sync_Canon::prepared#2'     => 2,
			'AB_MCP_Sync_Canon::encode#1'       => 1,
			'AB_MCP_Sync_Manifest::shape#2'     => 2,
			'AB_MCP_Sync_Manifest::verify#2'    => 1,
			'AB_MCP_Sync_Manifest::verify#3'    => 1,
			'AB_MCP_Sync_Import::inflate#1'     => 2,
			'AB_MCP_Sync_Import::inflate#2'     => 2,
			'AB_MCP_Sync_Import::inflate#3'     => 2,
			'AB_MCP_Sync_Import::lines#1'       => 5,
			'AB_MCP_Sync_Import::held_list#1'   => 5,
			'AB_MCP_Sync_Import::held_list#2'   => 1,
			'AB_MCP_Sync_Import::held_list#3'   => 1,
			'AB_MCP_Sync_Import::taken#3'       => 1,
		),
		'groups' => array(
			'AB_MCP_Protected_Store::groups#1'  => 1,
			'AB_MCP_Protected_Store::way#1'     => 2,
			'AB_MCP_Protected_Store::way#2'     => 2,
			'AB_MCP_Protected_Store::holds#1'   => 2,
			'AB_MCP_Protected_Store::holds#2'   => 2,
			'AB_MCP_Protected_Store::holds#3'   => 4,
			'AB_MCP_Protected_Store::entries#1' => 1,
			'AB_MCP_Shape::items#1'             => 16,
			'AB_MCP_Protected_Store::entries#2' => 16,
			'AB_MCP_Protected_Store::sidecar#1' => 7,
			'AB_MCP_Shape::decoded#1'           => 7,
			'AB_MCP_Shape::decoded#2'           => 7,
			'AB_MCP_Protected_Store::entries#3' => 1,
			'AB_MCP_Protected_Store::groups#2'  => 7,
			'AB_MCP_Protected_Store::groups#3'  => 1,
		),
		'get'    => array(
			'AB_MCP_Protected_Store::get#1'     => 1,
			'AB_MCP_Protected_Store::way#1'     => 2,
			'AB_MCP_Protected_Store::way#2'     => 3,
			'AB_MCP_Protected_Store::holds#1'   => 2,
			'AB_MCP_Protected_Store::holds#2'   => 2,
			'AB_MCP_Protected_Store::holds#3'   => 5,
			'AB_MCP_Protected_Store::group#1'   => 1,
			'AB_MCP_Protected_Store::sidecar#1' => 1,
			'AB_MCP_Shape::decoded#1'           => 1,
			'AB_MCP_Shape::decoded#2'           => 1,
			'AB_MCP_Protected_Store::get#2'     => 1,
			'AB_MCP_Payload_File::read#1'       => 1,
			'AB_MCP_Payload_File::read#2'       => 1,
			'AB_MCP_Payload_File::read#3'       => 1,
			'AB_MCP_Payload_File::read#4'       => 1,
			'AB_MCP_Payload_File::read#5'       => 1,
			'AB_MCP_Payload_File::read#6'       => 1,
		),
		'ready'   => array(
			'AB_MCP_Protected_Store::files#1'   => 1,
			'AB_MCP_Protected_Store::way#1'     => 5,
			'AB_MCP_Protected_Store::way#2'     => 7,
			'AB_MCP_Protected_Store::holds#1'   => 5,
			'AB_MCP_Protected_Store::holds#2'   => 5,
			'AB_MCP_Protected_Store::holds#3'   => 12,
			'AB_MCP_Protected_Store::group#1'   => 2,
			'AB_MCP_Protected_Store::sidecar#1' => 3,
			'AB_MCP_Shape::decoded#1'           => 4,
			'AB_MCP_Shape::decoded#2'           => 4,
			'AB_MCP_Protected_Store::files#2'   => 1,
			'AB_MCP_Protected_Store::files#3'   => 5,
			'AB_MCP_Protected_Store::files#4'   => 1,
			'AB_MCP_Sync_Code_Stage::state#1'   => 2,
			'AB_MCP_Protected_Store::get#1'     => 1,
			'AB_MCP_Protected_Store::get#2'     => 1,
			'AB_MCP_Payload_File::read#1'       => 1,
			'AB_MCP_Payload_File::read#2'       => 1,
			'AB_MCP_Payload_File::read#3'       => 1,
			'AB_MCP_Payload_File::read#4'       => 1,
			'AB_MCP_Payload_File::read#5'       => 1,
			'AB_MCP_Payload_File::read#6'       => 1,
			'AB_MCP_Sync_Canon::revived#1'      => 24,
			'AB_MCP_Sync_Canon::prepared#3'     => 22,
			'AB_MCP_Sync_Canon::prepared#2'     => 2,
			'AB_MCP_Sync_Canon::encode#1'       => 1,
			'AB_MCP_Shape::fields#1'            => 28,
			'AB_MCP_Shape::items#1'             => 2,
			'AB_MCP_Sync_Code_Stage::state#2'   => 1,
			'AB_MCP_Protected_Store::entry#1'   => 1,
			'AB_MCP_Sync_Code_Stage::ready#1'   => 1,
		),
		'state'   => array(
			'AB_MCP_Protected_Store::files#1'   => 1,
			'AB_MCP_Protected_Store::way#1'     => 4,
			'AB_MCP_Protected_Store::way#2'     => 6,
			'AB_MCP_Protected_Store::holds#1'   => 4,
			'AB_MCP_Protected_Store::holds#2'   => 4,
			'AB_MCP_Protected_Store::holds#3'   => 10,
			'AB_MCP_Protected_Store::group#1'   => 2,
			'AB_MCP_Protected_Store::sidecar#1' => 2,
			'AB_MCP_Shape::decoded#1'           => 3,
			'AB_MCP_Shape::decoded#2'           => 3,
			'AB_MCP_Protected_Store::files#2'   => 1,
			'AB_MCP_Protected_Store::files#3'   => 5,
			'AB_MCP_Protected_Store::files#4'   => 1,
			'AB_MCP_Sync_Code_Stage::state#1'   => 2,
			'AB_MCP_Protected_Store::get#1'     => 1,
			'AB_MCP_Protected_Store::get#2'     => 1,
			'AB_MCP_Payload_File::read#1'       => 1,
			'AB_MCP_Payload_File::read#2'       => 1,
			'AB_MCP_Payload_File::read#3'       => 1,
			'AB_MCP_Payload_File::read#4'       => 1,
			'AB_MCP_Payload_File::read#5'       => 1,
			'AB_MCP_Payload_File::read#6'       => 1,
			'AB_MCP_Sync_Canon::revived#1'      => 24,
			'AB_MCP_Sync_Canon::prepared#3'     => 22,
			'AB_MCP_Sync_Canon::prepared#2'     => 2,
			'AB_MCP_Sync_Canon::encode#1'       => 1,
			'AB_MCP_Shape::fields#1'            => 22,
			'AB_MCP_Shape::items#1'             => 2,
			'AB_MCP_Sync_Code_Stage::state#2'   => 1,
		),
		'foreign' => array(
			'AB_MCP_Shape::fields#1'            => 6,
			'AB_MCP_Protected_Store::entry#1'   => 1,
			'AB_MCP_Protected_Store::way#1'     => 1,
			'AB_MCP_Protected_Store::way#2'     => 1,
			'AB_MCP_Protected_Store::holds#1'   => 1,
			'AB_MCP_Protected_Store::holds#2'   => 1,
			'AB_MCP_Protected_Store::holds#3'   => 2,
			'AB_MCP_Protected_Store::sidecar#1' => 1,
			'AB_MCP_Shape::decoded#1'           => 1,
			'AB_MCP_Shape::decoded#2'           => 1,
		),
	);

	/** The SHA-256 of the gates of each reading in time, one gate a line, in order. */
	const ORDER = array(
		'single' => '042c0201e514698c0bcb4a09c17b881d3c12f3fe3fecc180b0bfc2d881b777c0',
		'two'    => '17b445bb6e5ff9b88c33034de1282f61e9ce6e21e5e601dcef81dbd4e7581611',
		'taken'  => 'e299121732d7736ca1923a325e83d60fc55581a4d0d2fa85463b89c4cb3a8f94',
		'groups' => 'ad55959bc822020eac9f0e37332fde3525826b6d44f88ca435bd85940f22ccbf',
		'get'    => '957bcd4b227ab8ca2eef7b8344013fe996cc44c4c7f434de06d808ad426f0146',
		'ready'   => 'fff429340643b83d722ff1f4a6d1dc2acbbde4453479c11a5e76e2c5a3d005a0',
		'state'   => 'd12ef962074b22c209bf7f6a145b0d95a1014cbd021fb80b3277b30126bfecfc',
		'foreign' => '3b605856d06ee27e50c0034566387ebd5e3c4f8e9a569efecc55f0946899c713',
	);

	/** @var array<string,string[]> The series built once: name => package ids. */
	private static $series = array();

	/** @var array The first chunk of the series single: gid (its group in the area in), sha256. */
	private static $chunk = array();

	/** @var array The stage built once: gid, hash, bytes, entries (path, size, SHA-256), state (ready, as ready() reads it). */
	private static $stage = array();

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
		DeadlineClock::restore();
		AB_MCP_Sync_Run::$clock = null;
		AB_MCP_Test_Seam::restore_all();
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
		self::watch( $clock, $first );
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
			}
			return AB_MCP_Sync_Code_Stage::signed( self::$series[ $series ], 'plugin', self::UNIT, $deadline );
		} finally {
			AB_MCP_Test_Seam::restore_all();
		}
	}

	/** The seam of the store with each listing and each sidecar read kept as a step of the clock. */
	private static function watch( DeadlineClock $clock, array $first = array() ): void {
		$step = static function ( $method, $args ) use ( $clock ) {
			$clock->step( $method . ' ' . basename( (string) $args[0] ) );
		};
		AB_MCP_Test_Seam::install(
			AB_MCP_Protected_Store::class,
			'naht_protected_store',
			new \AB_MCP_Protected_Store_Calls(),
			array_merge(
				$first,
				array(
					array(
						'method' => 'read',
						'kind'   => 'before',
						'before' => $step,
					),
					array(
						'method' => 'scandir',
						'kind'   => 'before',
						'before' => $step,
					),
				)
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
		$without = $this->reading( $series, DeadlineClock::in_time(), null );
		self::assertNotInstanceOf( WP_Error::class, $without, $series . ' without a deadline: ' . ( $without instanceof WP_Error ? $without->get_error_code() : '' ) );
		return $without;
	}

	/** A reading of a series in time: its clock, checked against the gates written down. */
	private function in_time( string $series ): DeadlineClock {
		$clock  = DeadlineClock::in_time();
		$answer = $this->reading( $series, $clock, DeadlineClock::deadline() );
		self::assertSame( $this->answer( $series ), $answer, 'In time: the answer without a deadline.' );
		self::assertSame( self::COUNTS[ $series ], array_count_values( $clock->gates ), 'Each gate as often as written down.' );
		self::assertSame( self::ORDER[ $series ], hash( 'sha256', implode( "\n", $clock->gates ) ), 'The gates in the order written down.' );
		return $clock;
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
}
