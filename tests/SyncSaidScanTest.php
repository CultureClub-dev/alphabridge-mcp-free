<?php
/**
 * The completeness of AB_MCP_Sync_Said, shown by a static scan (branch 13
 * text/z8, Codex round 5): every English text the code of the takeover
 * writes under a key of AB_MCP_Sync_Said::PROSE (reason, why, words, note)
 * reaches the person through the answers of the tools wp_site_*, so the
 * list knows each one. Rounds 3 to 5 each found more such fields one by one;
 * the scan finds them all, and a new one fails here.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Said;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-said.php';
require_once __DIR__ . '/support/class-said-scan.php';

final class SyncSaidScanTest extends TestCase {

	/**
	 * Helpers that put the text of an argument into a key of prose: file =>
	 * function => the number of that argument (from 0).
	 */
	const HELPERS = array(
		'class-sync-decision.php'      => array(
			'result'       => 5,
			'table_result' => 3,
		),
		'class-sync-proposal.php'      => array(
			'alone'        => 2,
			'choice_entry' => 3,
		),
		'class-sync-option-schema.php' => array( 'answer' => 3 ),
		'class-sync-engine.php'        => array( 'noted' => 1 ),
	);

	/**
	 * Functions whose return goes into a key of prose: file => function =>
	 * the place of the text in the array it returns, null for the return
	 * itself.
	 */
	const RETURNS = array(
		'class-sync-decision.php'      => array(
			'target'     => null,
			'side_state' => 1,
		),
		'class-sync-proposal.php'      => array(
			'why_no_hash' => null,
			'why_unread'  => null,
			'twin_reason' => null,
			'doubt'       => null,
		),
		'class-sync-env.php'           => array(
			'judged'    => 2,
			'why_files' => null,
		),
		'class-sync-engine.php'        => array(
			'not_own_why'   => null,
			'unclear_after' => null,
		),
		'class-sync-option-schema.php' => array( 'reason_text' => null ),
	);

	/**
	 * Where the scan cannot follow a text, by file and expression, and why
	 * no English text the list does not know comes that way.
	 */
	const UNSEEN = array(
		'class-sync-attachment-writer.php: $made -> get_error_message ( )'  => 'A note of the journal: the message of a WP_Error of WordPress or of a gettext call, in the language of the site.',
		'class-sync-attachment-writer.php: $dir -> get_error_message ( )'   => 'As above.',
		'class-sync-engine.php: $put -> get_error_message ( )'              => 'As above.',
		'class-sync-engine.php: (string) $why'                              => 'deviated(): every call gives a gettext text or refusal_why(), which is one.',
		'class-sync-engine.php: (string) $note'                             => 'shared_back(): the closure $left is given gettext texts.',
		'class-sync-decision.php: $state [ 1 ]'                             => 'The why of side_state() (RETURNS), through foreach over both sides.',
		'class-sync-env.php: $complete ? null : $why'                       => 'files_line(): a code (by_code, …), said by why_files() (RETURNS) in judged().',
		'class-sync-option-schema.php: $resolved -> get_error_message ( )'  => 'The messages of AB_MCP_Sync_Option_Name::settle(), which testTheMessagesOfSettleAreKnown holds to the list.',
		'class-sync-pairs-tool.php: $now'                                   => 'now(): a gettext text, or a WP_Error that call() returns before.',
		'class-sync-proposal.php: null !== $doubt ? $doubt : \'The same slug on another path of parents (another parent, or a parent of another type, theme or language): another object, or one that moved. It is paired only by the choice of a person.\'' => 'The why of doubt() (RETURNS), through $item[\'doubt\']; the literal beside it is found and known.',
		'class-sync-push.php: (string) $reason'                             => 'verdict(): the closure $lock is given AB_MCP_Sync_Said::text() or gettext texts.',
		'class-sync-write.php: $note'                                       => 'From the arguments $changed and $whole, which the writers give as gettext texts, or a gettext text.',
	);

	private static function scan(): SaidScan {
		$scan          = new SaidScan();
		$scan->helpers = self::HELPERS;
		$scan->returns = self::RETURNS;
		foreach ( glob( __DIR__ . '/../includes/sync/*.php' ) as $file ) {
			if ( 'class-sync-said.php' !== basename( $file ) ) {
				$scan->add( basename( $file ), (string) file_get_contents( $file ) );
			}
		}
		$scan->run();
		return $scan;
	}

	public function testEveryTextWrittenToAKeyOfProseIsKnown(): void {
		$scan    = self::scan();
		$unknown = array();
		foreach ( $scan->found as $found ) {
			if ( ! AB_MCP_Sync_Said::knows( $found[2] ) ) {
				$unknown[] = $found[0] . ':' . $found[1] . ': ' . $found[2];
			}
		}
		self::assertGreaterThan( 250, count( $scan->found ), 'The scan reads the code at all.' );
		self::assertSame( array(), array_values( array_unique( $unknown ) ) );
	}

	public function testWhatTheScanCannotFollowIsNamed(): void {
		$unseen = array();
		foreach ( self::scan()->unseen as $one ) {
			$unseen[ $one[0] . ': ' . $one[2] ] = true;
		}
		$expected = self::UNSEEN;
		ksort( $unseen );
		ksort( $expected );
		self::assertSame( array_keys( $expected ), array_keys( $unseen ) );
	}

	public function testTheHelpersOfTheScanExist(): void {
		$scan = self::scan();
		foreach ( array( self::HELPERS, self::RETURNS ) as $list ) {
			foreach ( $list as $file => $functions ) {
				foreach ( array_keys( $functions ) as $name ) {
					self::assertTrue( $scan->has_function( $file, $name ), $file . ' ' . $name );
				}
			}
		}
	}

	public function testTheMessagesOfSettleAreKnown(): void {
		$messages = self::scan()->errors( 'class-sync-option-schema.php', 'settle' );
		self::assertCount( 3, $messages );
		foreach ( $messages as $message ) {
			self::assertTrue( AB_MCP_Sync_Said::knows( $message ), $message );
		}
	}

	/**
	 * Gegenbeweis: a text the list does not know, in each form the scan
	 * reads, is found.
	 */
	public function testTheScanFindsAnUnknownTextInEachForm(): void {
		$source = <<<'PHP'
<?php
final class Probe {
	const TABLE = array( 'a' => array( 'stage' => 'Z8x', 'reason' => 'Unknown one in a table.' ) );
	const WORDS = array( 'a' => 'Unknown two in words.', 'b' => 'Unknown three in words.' );
	public static function entry( $x, $s ) {
		$why = 'Unknown four in a variable.';
		if ( $x ) {
			$why = sprintf( 'Unknown five with %s.', $x );
		}
		$out = array(
			'why'   => $why,
			'words' => self::WORDS[ $x ],
			'note'  => $x ? 'Unknown six on one side.' : 'Unknown seven on the other.',
		);
		$out['reason'] = 'Unknown eight on ' . ( $s ? 'this site' : 'the staging site' ) . '.';
		$out['words'] .= ' Unknown nine as a piece.';
		$out['note']   = 'Unknown ten with ' . implode( ', ', $x ) . '.';
		self::helper( 1, 'Unknown eleven as an argument.' );
		list( $a, $b ) = self::tuple();
		$read          = static function () {
			return 'Unknown thirteen from a closure.';
		};
		$out['reason'] = $read() ?? __( 'Said already.', 'alphabridge-mcp' );
		$out['reason'] = self::returned();
		return $out;
	}
	private static function helper( $n, $text ) {
		return array( 'reason' => $text );
	}
	private static function tuple() {
		return array( 'x', 'Unknown twelve in a tuple.' );
	}
	private static function returned() {
		return 'Unknown fourteen as a return.';
	}
	private static function other( $x ) {
		return array( 'reason' => $x->message() );
	}
}
PHP;
		$scan          = new SaidScan();
		$scan->helpers = array( 'probe.php' => array( 'helper' => 1 ) );
		$scan->returns = array(
			'probe.php' => array(
				'tuple'    => 1,
				'returned' => null,
			),
		);
		$scan->add( 'probe.php', $source );
		$scan->run();
		$found = array_values( array_unique( array_column( $scan->found, 2 ) ) );
		foreach ( array(
			'Unknown one in a table.',
			'Unknown two in words.',
			'Unknown three in words.',
			'Unknown four in a variable.',
			'Unknown five with %s.',
			'Unknown six on one side.',
			'Unknown seven on the other.',
			'Unknown eight on this site.',
			'Unknown eight on the staging site.',
			'Unknown nine as a piece.',
			'Unknown ten with %s.',
			'Unknown eleven as an argument.',
			'Unknown twelve in a tuple.',
			'Unknown thirteen from a closure.',
			'Unknown fourteen as a return.',
		) as $text ) {
			self::assertContains( $text, $found, $text );
			self::assertFalse( AB_MCP_Sync_Said::knows( $text ), $text );
		}
		self::assertNotContains( 'Said already.', $found, 'A gettext text is said already.' );
		self::assertNotContains( 'Z8x', $found, 'Only keys of prose.' );
		self::assertSame( array( '$x -> message ( )' ), array_column( $scan->unseen, 2 ), 'What it cannot follow, it names.' );
	}

	/**
	 * The texts the scan finds put together, as the answer shows them: the
	 * words of an entry of the environment with what entry() adds, and why a
	 * folder was not counted in the sentence that names it.
	 */
	public function testTheTextsPutTogetherAreSaid(): void {
		$GLOBALS['ab_test_translations'] = array(
			'Counted, contents not compared: the same version, rows or count on both sides says nothing about the contents.' => 'Gezählt.',
			'Their contents are compared from %s.'                    => 'Ab %s.',
			'The %1$s could not be counted to its end on this site (%2$s).' => '%1$s hier nicht zu Ende gezählt (%2$s).',
			'a folder of it could not be read'                        => 'ein Ordner liess sich nicht lesen',
		);
		try {
			self::assertSame( 'Gezählt. Ab Z8b.', AB_MCP_Sync_Said::text( 'Counted, contents not compared: the same version, rows or count on both sides says nothing about the contents. Their contents are compared from Z8b.' ) );
			self::assertSame( 'uploads hier nicht zu Ende gezählt (ein Ordner liess sich nicht lesen).', AB_MCP_Sync_Said::text( 'The uploads could not be counted to its end on this site (a folder of it could not be read).' ) );
			self::assertSame( 'uploads hier nicht zu Ende gezählt (something else).', AB_MCP_Sync_Said::text( 'The uploads could not be counted to its end on this site (something else).' ), 'A value the list does not know as it is.' );
		} finally {
			unset( $GLOBALS['ab_test_translations'] );
		}
	}
}
