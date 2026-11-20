<?php
/**
 * The texts of the protected store that can reach an answer go through
 * gettext with the text domain (Z8b branch 3, Behebung 1, B4): every
 * message of a WP_Error in the files of the store is a gettext call, or
 * one of the texts the store had before branch 3, unchanged (OLD: the list
 * only shrinks; a text that is changed or new is translated, and waits in
 * tests/fixtures/translations-pending-z8b.php for text/z8b). Read from the
 * source, so a new message without gettext fails without a case of its
 * own. That each refusal names a way is AnswerWayTest's.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class StoreTextsTest extends TestCase {

	/** The files of the store. */
	const FILES = array( 'includes/class-protected-store.php', 'includes/class-store-budget.php' );

	/** The plain texts of the store from before branch 3 (40a282c), unchanged. */
	const OLD = array(
		'A booking names its run and the bytes the run needs, zero or more. Pass both.',
		'A file of the protected store is named with up to 64 of a-z, 0-9, ".", "_" and "-", starting with a letter or a digit, not ending in .tmp. Use such a name.',
		'A folder or file of the protected store is a link, or lies outside the store, so nothing there is read, written or removed. Ask the host to remove the link from the folder of the store, then try again.',
		'A group of the protected store is named by the 32 hex characters open() returned. Pass that id.',
		'A package is opened with the number of bytes it will hold, and it never grows past them. Pass the size as declared when you open it.',
		'A test write of 1 MB to the protected store failed, so the run does not start: its backups could not be written either. Ask the host to check the free space and the rights of the folder, then try again.',
		'One file of the protected store holds at most %d bytes. Split it into parts and use a name of its own for each.',
		'The booking could not be given back in the protected store. Ask the host to check that the folder is writable, then try again.',
		'The booking could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.',
		'The file could not be recorded in the protected store, so it was taken out again. Ask the host to check that the folder is writable and the disk has free space, then try again.',
		'The file could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.',
		'The file of the protected store could not be read. Try again; if it keeps failing, ask the host to check the rights of the folder.',
		'The file of the protected store failed its check against its SHA-256, so it is not used. Make it again; if it keeps failing, ask the host whether something else writes into the folder.',
		'The group could not be bound to its run in the protected store. Ask the host to check that the folder is writable, then try again.',
		'The group could not be closed in the protected store. Ask the host to check that the folder is writable, then try again.',
		'The group could not be made in the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.',
		'The label of a group holds up to 191 of the characters A-Z, a-z, 0-9, ".", "_", ":" and "-". Pass one of those, or none.',
		'The lock of the protected store could not be taken. Try again in a moment; if it keeps failing, ask the host whether PHP may lock files in that folder.',
		'The package passes the limit for %1$s (%2$d, at most %3$d), so it is not unpacked and nothing of it is used; a staging site of this version never makes such a package. 1. Update AlphaBridge MCP on the staging site to the version of this site. 2. Export the package again there (wp_site_export). 3. Import it again with the new package_id and manifest.',
		'The package was opened for %1$d bytes and holds %2$d; this file (%3$d bytes) would take it past them. Use a package opened with the size it needs.',
		'The protected store is no longer in the place this request chose: another request moved it, because that place could not be used any more. Nothing was read or written. Call it again.',
		'The protection of the store is not proved any more: a later check failed, or the last proof is older than a day. Nothing was read or written. Call it again over the web, through the MCP endpoint or in wp-admin: that request checks the store anew.',
		'The run needs %1$d bytes, other runs have booked %2$d, and %3$d must stay free, but the disk of the protected store has %4$d free. Nothing was written. Ask the host for more disk space, or wait until packages and reports are removed (a day after they were made), then try again.',
		'This account has %1$d open exports, the most one account keeps at a time (%2$d). An export counts until it is removed, a day after it was made, or later while a run that is not closed uses it. Wait until one is removed, or use one of them; an administrator can raise the number with the filter ab_mcp_store_max_exports.',
		'This group holds a file of that name already, and a file of the protected store is written once. Use another name for it.',
		'This package (%1$d bytes) would take the open packages of this site past %2$d bytes; they hold %3$d. Send it in smaller packages, or wait until one is removed (a day after it was made); an administrator can raise the limit with the filter ab_mcp_store_max_bytes.',
		'This site does not say how much space its disk has free (disk_free_space() gives no answer), so no run books its space and none starts. Ask the host to allow disk_free_space() for PHP, then try again.',
		'This site has %1$d open packages, the most it keeps at a time (%2$d). A package counts until it is removed, a day after it was made, or later while a run that is not closed uses it. Wait until one is removed; an administrator can raise the number with the filter ab_mcp_store_max_packages.',
	);

	/**
	 * The messages of the WP_Errors of a source: file:line => whether the
	 * message is a gettext call, and its texts (literals with a blank; a
	 * message from a variable has none).
	 *
	 * @param string $source PHP.
	 * @param string $where  Name of the file.
	 * @return array<string,array{gettext:bool,texts:string[]}>
	 */
	private static function messages( string $source, string $where ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$out    = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_NEW !== $tokens[ $i ][0] ) {
				continue;
			}
			$j = $i + 1;
			while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( ! is_array( $tokens[ $j ] ) || 'WP_Error' !== $tokens[ $j ][1] ) {
				continue;
			}
			$depth   = 0;
			$arg     = 0;
			$gettext = false;
			$texts   = array();
			for ( $k = $j + 1; $k < $count; $k++ ) {
				$token = $tokens[ $k ];
				if ( '(' === $token ) {
					++$depth;
					if ( 1 === $depth ) {
						continue;
					}
				} elseif ( ')' === $token ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $token && 1 === $depth ) {
					++$arg;
					continue;
				}
				if ( 1 !== $arg ) {
					continue;
				}
				if ( is_array( $token ) && T_STRING === $token[0] && in_array( $token[1], array( '__', '_n', '_x' ), true ) ) {
					$gettext = true;
				} elseif ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$text = stripcslashes( substr( $token[1], 1, -1 ) );
					if ( false !== strpos( $text, ' ' ) ) {
						$texts[] = $text;
					}
				}
			}
			$out[ $where . ':' . $tokens[ $j ][2] ] = array(
				'gettext' => $gettext,
				'texts'   => $texts,
			);
		}
		return $out;
	}

	/**
	 * The messages that are neither translated nor an unchanged old text.
	 *
	 * @param array<string,array{gettext:bool,texts:string[]}> $messages messages().
	 * @return string[]
	 */
	private static function untranslated( array $messages ): array {
		$out = array();
		foreach ( $messages as $where => $message ) {
			if ( $message['gettext'] ) {
				continue;
			}
			foreach ( $message['texts'] as $text ) {
				if ( ! in_array( $text, self::OLD, true ) ) {
					$out[] = $where . ': ' . $text;
				}
			}
		}
		return $out;
	}

	/** @return array<string,array{gettext:bool,texts:string[]}> */
	private static function store_messages(): array {
		$out = array();
		foreach ( self::FILES as $file ) {
			$out += self::messages( (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ), $file );
		}
		return $out;
	}

	public function testEveryMessageOfTheStoreIsTranslatedOrAnOldTextUnchanged(): void {
		$messages = self::store_messages();
		self::assertGreaterThan( 60, count( $messages ), 'The scan finds the messages.' );
		self::assertGreaterThan( 10, count( array_filter( array_column( $messages, 'gettext' ) ) ), 'And the translated ones among them.' );
		self::assertSame( array(), self::untranslated( $messages ) );
	}

	public function testEachOldTextIsStillThereAndTheCheckFindsAPlainNewOne(): void {
		$plain = array();
		foreach ( self::store_messages() as $message ) {
			if ( ! $message['gettext'] ) {
				$plain = array_merge( $plain, $message['texts'] );
			}
		}
		foreach ( self::OLD as $text ) {
			self::assertContains( $text, $plain, 'An old text that left the code leaves the list: ' . $text );
		}
		// The counter-check: a plain text, a plain text in sprintf(), an old
		// one changed; a gettext call and a message from a variable pass.
		$changed = self::OLD[0] . ' Now.';
		$source  = "<?php\nnew WP_Error( 'a', 'A text that is new here.' );\nnew WP_Error( 'b', sprintf( 'It holds %d bytes at most.', 5 ) );\n"
			. "new WP_Error( 'c', " . var_export( $changed, true ) . " );\n"
			. "new WP_Error( 'd', __( 'A text that is translated.', 'alphabridge-mcp' ) );\nnew WP_Error( 'e', \$why['message'] );\n";
		self::assertSame(
			array( 'x:2: A text that is new here.', 'x:3: It holds %d bytes at most.', 'x:4: ' . $changed ),
			self::untranslated( self::messages( $source, 'x' ) )
		);
	}
}
