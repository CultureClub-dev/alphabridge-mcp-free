<?php
/**
 * wp_get_post and wp_get_post_meta read protected ("_") meta with
 * include_protected (since 4.14.0, before in AlphaBridge MCP Pro only).
 *
 * What must hold, as Pro's copies of the two tools answered up to 4.13:
 *
 * - include_protected=true takes manage_options; without it both tools
 *   refuse with the right and the way, also where include_meta leaves the
 *   meta out. With it the meta map gains the allow-listed protected keys
 *   (filter ab_mcp_pro_protected_meta_allowlist), marked by
 *   meta_includes_protected and protected_meta_note; other protected keys
 *   stay out, and the public keys keep the reader's verdict.
 * - One allow-listed key asked for by name is answered under the same
 *   right, include_protected or not; a key outside the allow-list is
 *   refused with the filter named, whoever asks.
 * - An allow-listed key needs the right to edit the post and, for a key a
 *   plugin registered, the plugin's rule for it on top, as WordPress maps
 *   edit_post_meta (may_expose_protected()).
 * - A credential-like key stays out, by name and in the listing, whatever
 *   the filter adds; and the answer is redacted like every reader's
 *   (AB_MCP_Redactor): a credential inside an allow-listed value reads as
 *   [REDACTED], but for a text under the name of a field people write,
 *   which is not decoded (the redactor's limit, as its readers describe it).
 * - The allow-list is one list, read by the readers and wp_search_replace.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Redactor;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Search_Replace;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/tools/class-tools-search-replace.php';
// get_post_type(), which may_expose_protected() asks, as the undo tests have it.
require_once __DIR__ . '/undo-stubs.php';

final class ProtectedMetaReadTest extends TestCase {

	private const LAYOUT = '[{"id":"a1","elType":"widget","settings":{"title":"Hello"}}]';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		// 5 may be edited, 6 only read, 7 not even read.
		ab_test_add_post( 5 );
		ab_test_add_post( 6 );
		ab_test_add_post( 7 );
		add_post_meta( 5, 'color', 'red' );
		add_post_meta( 5, '_elementor_data', self::LAYOUT );
		add_post_meta( 5, '_elementor_edit_mode', 'builder' );
		add_post_meta( 5, '_private_thing', 'internal' );
		add_post_meta( 5, '_edit_lock', '1700000000:1' );
		add_post_meta( 6, '_elementor_data', '[]' );
		self::can();
	}

	/**
	 * Every capability but the ones named, with the rights of posts 6 and 7.
	 *
	 * @param string ...$denied Capabilities the account lacks.
	 */
	private static function can( string ...$denied ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $denied ): bool {
			if ( in_array( $cap, $denied, true ) ) {
				return false;
			}
			$id = (int) ( $args[0] ?? 0 );
			if ( 'read_post' === $cap && 7 === $id ) {
				return false;
			}
			if ( in_array( $cap, array( 'edit_post', 'edit_post_meta' ), true ) && in_array( $id, array( 6, 7 ), true ) ) {
				return false;
			}
			return true;
		};
	}

	private static function meta( array $a ) {
		return AB_MCP_Tools_Content::get_post_meta_tool( $a );
	}

	private static function code( $res ): ?string {
		return is_wp_error( $res ) ? (string) $res->get_error_code() : null;
	}

	private static function message( $res ): string {
		self::assertInstanceOf( \WP_Error::class, $res );
		return (string) $res->get_error_message();
	}

	/* ------------------------------------------------- wp_get_post_meta */

	public function testAnAllowListedKeyIsAnsweredByNameForAnAccountWithManageOptions(): void {
		$res = self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) );
		self::assertSame( array( 'id' => 5, 'key' => '_elementor_data', 'value' => self::LAYOUT ), $res );
		self::assertSame( $res, self::meta( array( 'id' => 5, 'key' => '_elementor_data', 'include_protected' => true ) ), 'The same with include_protected=true.' );
		self::assertSame( array( 'id' => 5, 'key' => '_thumbnail_id', 'value' => '' ), self::meta( array( 'id' => 5, 'key' => '_thumbnail_id' ) ), 'An allow-listed key the post lacks reads empty, as a public one.' );
	}

	public function testTheListingWithIncludeProtectedAddsOnlyAllowListedKeys(): void {
		$res = self::meta( array( 'id' => 5, 'include_protected' => true ) );
		self::assertSame(
			array(
				'color'               => 'red',
				'_elementor_data'     => self::LAYOUT,
				'_elementor_edit_mode' => 'builder',
			),
			$res['meta'],
			'The public keys first, as the reader filtered them; then the allow-listed protected ones; never _private_thing or _edit_lock.'
		);
		self::assertTrue( $res['meta_includes_protected'] );
		self::assertSame( 'Only allow-listed protected keys are returned. Other plugins\' internal keys are never exposed.', $res['protected_meta_note'] );
	}

	public function testWithoutIncludeProtectedTheListingStaysPublic(): void {
		foreach ( array( array( 'id' => 5 ), array( 'id' => 5, 'include_protected' => false ) ) as $a ) {
			self::assertSame( array( 'id' => 5, 'meta' => array( 'color' => 'red' ) ), self::meta( $a ) );
		}
		// And so for an account without manage_options: nothing is refused.
		self::can( 'manage_options' );
		self::assertSame( array( 'id' => 5, 'meta' => array( 'color' => 'red' ) ), self::meta( array( 'id' => 5 ) ) );
	}

	public function testSeveralValuesOfAProtectedKeyAreListed(): void {
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array_merge( $keys, array( '_gallery' ) ) );
		add_post_meta( 5, '_gallery', 'a' );
		add_post_meta( 5, '_gallery', 'b' );
		self::assertSame( array( 'a', 'b' ), self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta']['_gallery'] );
	}

	public function testWithoutManageOptionsBothWaysAreRefusedWithTheRightAndTheWay(): void {
		self::can( 'manage_options' );
		$by_name = self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $by_name ) );
		self::assertStringStartsWith( 'Reading a protected meta key requires the manage_options capability, as include_protected does: connect with an administrator account.', self::message( $by_name ) );
		self::assertStringContainsString( 'If the account "user7" should be allowed to do this', self::message( $by_name ) );
		self::assertStringContainsString( 'Direct link: https://example.test/wp-admin/users.php', self::message( $by_name ) );

		$listing = self::meta( array( 'id' => 5, 'include_protected' => true ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $listing ) );
		self::assertStringStartsWith( 'include_protected requires the manage_options capability. Leave it out, or connect with an administrator account.', self::message( $listing ) );
		self::assertStringEndsWith( \AB_MCP_Guidance::handover(), self::message( $listing ) );
	}

	public function testWithAKeyIncludeProtectedDoesNotDecide(): void {
		// As in Pro: a public key is the public reader's, whatever
		// include_protected says, and the account needs no manage_options.
		self::can( 'manage_options' );
		self::assertSame( array( 'id' => 5, 'key' => 'color', 'value' => 'red' ), self::meta( array( 'id' => 5, 'key' => 'color', 'include_protected' => true ) ) );
	}

	public function testAKeyOutsideTheAllowListIsRefusedWithTheFilterNamed(): void {
		foreach ( array( array(), array( 'manage_options' ) ) as $denied ) {
			self::can( ...$denied );
			foreach ( array( '_private_thing', '_edit_lock', '_wp_old_slug' ) as $key ) {
				$res = self::meta( array( 'id' => 5, 'key' => $key ) );
				self::assertSame( 'ab_mcp_protected_meta', self::code( $res ), $key );
				self::assertStringContainsString( 'ab_mcp_pro_protected_meta_allowlist', self::message( $res ), $key );
				self::assertStringContainsString( 'include_protected=true lists the ones this post has', self::message( $res ), $key );
			}
		}
	}

	public function testTheFilterExtendsAndNarrowsTheList(): void {
		add_filter(
			'ab_mcp_pro_protected_meta_allowlist',
			static fn( $keys ) => array_merge( array_values( array_diff( $keys, array( '_elementor_edit_mode' ) ) ), array( '_private_thing' ) )
		);
		self::assertSame( 'internal', self::meta( array( 'id' => 5, 'key' => '_private_thing' ) )['value'], 'A key the filter adds is read by name.' );
		self::assertSame( 'ab_mcp_protected_meta', self::code( self::meta( array( 'id' => 5, 'key' => '_elementor_edit_mode' ) ) ), 'A key the filter takes off is refused.' );
		$meta = self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'];
		self::assertArrayHasKey( '_private_thing', $meta );
		self::assertArrayNotHasKey( '_elementor_edit_mode', $meta );
		self::assertArrayHasKey( '_elementor_data', $meta );
	}

	public function testACredentialLikeKeyStaysOutWhateverTheFilterAdds(): void {
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array_merge( $keys, array( '_api_key', '_stripe_secret' ) ) );
		add_post_meta( 5, '_api_key', 'PLACEHOLDER-1' );
		add_post_meta( 5, '_stripe_secret', 'PLACEHOLDER-2' );
		foreach ( array( '_api_key', '_stripe_secret', 'api_key' ) as $key ) {
			$res = self::meta( array( 'id' => 5, 'key' => $key ) );
			self::assertSame( 'ab_mcp_protected_meta', self::code( $res ), $key );
			self::assertStringStartsWith( 'This meta key looks like a credential', self::message( $res ), $key );
		}
		$res = self::meta( array( 'id' => 5, 'include_protected' => true ) );
		self::assertArrayNotHasKey( '_api_key', $res['meta'] );
		self::assertArrayNotHasKey( '_stripe_secret', $res['meta'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $res ) );
	}

	public function testAnAllowListedKeyNeedsTheRightToEditThePost(): void {
		// Post 6 may be read, not edited: the key is refused, as a public one
		// would be, and the listing leaves it out.
		$res = self::meta( array( 'id' => 6, 'key' => '_elementor_data' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringStartsWith( 'Your account cannot access this meta key: that takes the right to edit the post itself', self::message( $res ) );
		self::assertSame( array(), self::meta( array( 'id' => 6, 'include_protected' => true ) )['meta'] );
	}

	/**
	 * Capabilities as map_meta_cap() maps them: edit_post_meta takes what
	 * edit_post takes, and the auth_callback of the key on top (here the
	 * one a plugin registered for _elementor_data).
	 *
	 * @param bool $edit_post Whether the account may edit the post.
	 * @param bool $auth      What the plugin's auth_callback answers.
	 */
	private static function wp_meta_caps( bool $edit_post, bool $auth ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $edit_post, $auth ): bool {
			if ( 'edit_post' === $cap ) {
				return $edit_post;
			}
			if ( 'edit_post_meta' === $cap ) {
				return $edit_post && ( '_elementor_data' !== ( $args[1] ?? '' ) || $auth );
			}
			return true;
		};
	}

	public function testAKeyAPluginRegisteredFollowsItsRule(): void {
		// Registered for posts: may_expose_protected() asks edit_post_meta,
		// which asks the right to edit the post and then the plugin's
		// auth_callback for the key.
		$GLOBALS['ab_test_registered_meta']['post']['post']['_elementor_data'] = true;
		self::wp_meta_caps( true, false );
		self::assertSame( 'ab_mcp_forbidden', self::code( self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) ) ) );
		self::assertArrayNotHasKey( '_elementor_data', self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'] );
		self::assertArrayHasKey( '_elementor_edit_mode', self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'], 'An unregistered key still follows edit_post.' );

		// Registered for every post type: the same rule.
		$GLOBALS['ab_test_registered_meta'] = array( 'post' => array( '' => array( '_elementor_data' => true ) ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) ) ) );

		// The rule allows it, and the account may edit the post: read.
		self::wp_meta_caps( true, true );
		self::assertSame( self::LAYOUT, self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) )['value'] );

		// A rule that allows it does not stand in for the right to edit the post.
		self::wp_meta_caps( false, true );
		$res = self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringStartsWith( 'Your account cannot access this meta key: that takes the right to edit the post itself', self::message( $res ) );
		self::assertSame( array(), self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'], 'Nor in the listing, where the public keys take that right too.' );
	}

	public function testAKeyAPluginMarksAsProtectedIsAProtectedKey(): void {
		// is_protected_meta() without "_": allow-listed it is read like the
		// builder keys; otherwise it is refused with the allow-list named.
		add_filter( 'is_protected_meta', static fn( $protected, $key ) => $protected || 'internal_note' === $key, 10, 2 );
		add_post_meta( 5, 'internal_note', 'note' );
		self::assertStringContainsString( 'ab_mcp_pro_protected_meta_allowlist', self::message( self::meta( array( 'id' => 5, 'key' => 'internal_note' ) ) ) );
		self::assertArrayNotHasKey( 'internal_note', self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'] );
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array_merge( $keys, array( 'internal_note' ) ) );
		self::assertSame( 'note', self::meta( array( 'id' => 5, 'key' => 'internal_note' ) )['value'] );
		self::assertSame( 'note', self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta']['internal_note'] );
		self::can( 'manage_options' );
		self::assertSame( 'ab_mcp_forbidden', self::code( self::meta( array( 'id' => 5, 'key' => 'internal_note' ) ) ) );
	}

	public function testAFilterThatCallsAnUnderscoreKeyPublicDoesNotOpenIt(): void {
		// The underscore is checked literally as well, as the writers do:
		// is_protected_meta() is filterable.
		add_filter( 'is_protected_meta', static fn( $protected, $key ) => '_elementor_data' === $key ? false : $protected, 10, 2 );
		self::assertSame( self::LAYOUT, self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) )['value'], 'Still the protected path, by its rules.' );
		self::assertArrayNotHasKey( '_elementor_data', self::meta( array( 'id' => 5 ) )['meta'] );
		self::can( 'manage_options' );
		self::assertSame( 'ab_mcp_forbidden', self::code( self::meta( array( 'id' => 5, 'key' => '_elementor_data' ) ) ) );
	}

	public function testAPublicKeyKeepsTheReadersVerdictEvenWhenAllowListed(): void {
		// The listing adds protected keys only; a public key the reader left
		// out (here: the plugin's rule for it) stays out.
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array_merge( $keys, array( 'color' ) ) );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'edit_post_meta' === $cap && 'color' === ( $args[1] ?? '' ) );
		$meta = self::meta( array( 'id' => 5, 'include_protected' => true ) )['meta'];
		self::assertArrayNotHasKey( 'color', $meta );
		self::assertArrayHasKey( '_elementor_data', $meta );
		self::assertArrayNotHasKey( 'color', AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true ) )['meta'] );
	}

	public function testThePostIsCheckedFirst(): void {
		self::can( 'manage_options' );
		self::assertSame( 'ab_mcp_missing_arg', self::code( self::meta( array( 'key' => '_elementor_data' ) ) ) );
		self::assertSame( 'ab_mcp_not_found', self::code( self::meta( array( 'id' => 99, 'key' => '_elementor_data' ) ) ) );
		self::assertSame( 'ab_mcp_not_found', self::code( self::meta( array( 'id' => 99, 'include_protected' => true ) ) ) );
		$res = self::meta( array( 'id' => 7, 'key' => '_elementor_data' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringStartsWith( 'Your account cannot read this specific post', self::message( $res ) );
	}

	/* ------------------------------------------------------ wp_get_post */

	public function testGetPostAddsTheAllowListedKeysWithIncludeProtected(): void {
		$res = AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true ) );
		self::assertSame(
			array(
				'color'                => 'red',
				'_elementor_data'      => self::LAYOUT,
				'_elementor_edit_mode' => 'builder',
			),
			$res['meta']
		);
		self::assertTrue( $res['meta_includes_protected'] );
		self::assertSame( 'Only allow-listed protected keys are returned. Other plugins\' internal keys are never exposed.', $res['protected_meta_note'] );
		self::assertSame( array( 'meta_includes_protected', 'protected_meta_note' ), array_slice( array_keys( $res ), -2 ), 'After the terms, as Pro added them.' );

		$plain = AB_MCP_Tools_Content::get_post( array( 'id' => 5 ) );
		self::assertSame( array( 'color' => 'red' ), $plain['meta'] );
		self::assertArrayNotHasKey( 'meta_includes_protected', $plain );
		self::assertArrayNotHasKey( 'protected_meta_note', $plain );
	}

	public function testGetPostLeavesOutCredentialsAndKeysOfAPostItMayNotEdit(): void {
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array_merge( $keys, array( '_api_key' ) ) );
		add_post_meta( 5, '_api_key', 'PLACEHOLDER' );
		self::assertArrayNotHasKey( '_api_key', AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true ) )['meta'] );
		self::assertSame( array(), AB_MCP_Tools_Content::get_post( array( 'id' => 6, 'include_protected' => true ) )['meta'] );
	}

	public function testGetPostRefusesIncludeProtectedWithoutManageOptions(): void {
		self::can( 'manage_options' );
		foreach ( array( true, false ) as $include_meta ) {
			$res = AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true, 'include_meta' => $include_meta ) );
			self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
			self::assertStringStartsWith( 'include_protected requires the manage_options capability. Leave it out, or connect with an administrator account.', self::message( $res ) );
		}
		self::assertSame( array( 'color' => 'red' ), AB_MCP_Tools_Content::get_post( array( 'id' => 5 ) )['meta'], 'Without it, nothing changes.' );
		self::assertSame( 'ab_mcp_not_found', self::code( AB_MCP_Tools_Content::get_post( array( 'id' => 99, 'include_protected' => true ) ) ), 'The post is checked first.' );
	}

	public function testGetPostWithoutMetaAddsNothing(): void {
		$res = AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true, 'include_meta' => false ) );
		self::assertArrayNotHasKey( 'meta', $res );
		self::assertArrayNotHasKey( 'meta_includes_protected', $res );
		self::assertArrayNotHasKey( 'protected_meta_note', $res );
	}

	/* ------------------------------------------------------- redaction */

	public function testACredentialInsideAnAllowListedValueReadsAsRedacted(): void {
		add_post_meta( 5, '_elementor_page_settings', array( 'layout' => 'full', 'mailchimp' => array( 'api_key' => 'PLACEHOLDER-A' ) ) );
		delete_post_meta( 5, '_elementor_data' );
		add_post_meta( 5, '_elementor_data', '[{"id":"f1","settings":{"form_name":"Contact","password":"PLACEHOLDER-B"}}]' );

		$by_name = AB_MCP_Redactor::tool_result( 'wp_get_post_meta', array(), self::meta( array( 'id' => 5, 'key' => '_elementor_page_settings' ) ) );
		self::assertSame( array( 'layout' => 'full', 'mailchimp' => array( 'api_key' => AB_MCP_Redactor::MARK ) ), $by_name['value'] );
		self::assertSame( array( '/value/mailchimp/api_key' ), $by_name['redacted'] );

		$listing = AB_MCP_Redactor::tool_result( 'wp_get_post_meta', array(), self::meta( array( 'id' => 5, 'include_protected' => true ) ) );
		self::assertSame( AB_MCP_Redactor::MARK, $listing['meta']['_elementor_page_settings']['mailchimp']['api_key'] );
		self::assertSame( '[{"id":"f1","settings":{"form_name":"Contact","password":"[REDACTED]"}}]', $listing['meta']['_elementor_data'], 'Inside the JSON of the layout too, the rest as it was.' );
		self::assertSame( array( '/meta/_elementor_page_settings/mailchimp/api_key', '/meta/_elementor_data/0/settings/password' ), $listing['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $listing ) );

		$post = AB_MCP_Redactor::tool_result( 'wp_get_post', array(), AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true ) ) );
		self::assertSame( AB_MCP_Redactor::MARK, $post['meta']['_elementor_page_settings']['mailchimp']['api_key'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $post ) );
		self::assertSame( 'Text of post 5', $post['content'], 'What people wrote stays.' );
	}

	public function testATextUnderAFieldPeopleWriteIsNotDecodedAndItsNeighboursAreRedacted(): void {
		// The redactor's limit, as the descriptions state it: a text under
		// the name of a field people write (AB_MCP_Redactor::CONTENT_FIELDS)
		// is returned as written, JSON with a credential in it included; a
		// credential key beside it, or in an array below it, is redacted.
		delete_post_meta( 5, '_elementor_page_settings' );
		add_post_meta(
			5,
			'_elementor_page_settings',
			array(
				'raw'     => '{"api_key":"VISIBLE-1"}',
				'text'    => array( 'api_key' => 'PLACEHOLDER-T' ),
				'api_key' => 'PLACEHOLDER-K',
			)
		);
		foreach ( array(
			array( 'wp_get_post_meta', self::meta( array( 'id' => 5, 'key' => '_elementor_page_settings' ) ), array( 'value' ), '/value' ),
			array( 'wp_get_post_meta', self::meta( array( 'id' => 5, 'include_protected' => true ) ), array( 'meta', '_elementor_page_settings' ), '/meta/_elementor_page_settings' ),
			array( 'wp_get_post', AB_MCP_Tools_Content::get_post( array( 'id' => 5, 'include_protected' => true ) ), array( 'meta', '_elementor_page_settings' ), '/meta/_elementor_page_settings' ),
		) as list( $tool, $result, $at, $pointer ) ) {
			$out   = AB_MCP_Redactor::tool_result( $tool, array(), $result );
			$value = $out;
			foreach ( $at as $step ) {
				$value = $value[ $step ];
			}
			self::assertSame( '{"api_key":"VISIBLE-1"}', $value['raw'], $tool . ': not decoded under raw, so returned as written.' );
			self::assertSame( array( 'api_key' => AB_MCP_Redactor::MARK ), $value['text'], $tool . ': an array under such a name is judged by its keys.' );
			self::assertSame( AB_MCP_Redactor::MARK, $value['api_key'], $tool );
			self::assertSame( array( $pointer . '/text/api_key', $pointer . '/api_key' ), $out['redacted'], $tool . ': the raw text is not named as redacted.' );
			self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ), $tool );
		}
	}

	/* ------------------------------------------------ the definitions */

	public function testBothToolsOfferIncludeProtectedAndSayWhatItTakes(): void {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Content::register( $r );
		foreach ( array( 'wp_get_post', 'wp_get_post_meta' ) as $name ) {
			$def  = $r->get( $name );
			$prop = $def['inputSchema']['properties']['include_protected'] ?? null;
			self::assertIsArray( $prop, $name );
			self::assertSame( 'boolean', $prop['type'], $name );
			self::assertStringContainsString( 'manage_options', $prop['description'], $name );
			self::assertStringContainsString( 'manage_options', $def['description'], $name );
			self::assertStringContainsString( 'include_protected=true', $def['description'], $name );
			self::assertStringContainsString( \AB_MCP_Tools_Base::READ_REDACTION_NOTE, $def['description'], $name . ' keeps its sentence on redaction, with its limit.' );
			self::assertSame( 'edit_posts', $def['capability'], $name );
		}
		// The limit stands where the promise does: the term meta reader too.
		require_once dirname( __DIR__ ) . '/includes/tools/class-tools-meta-auth.php';
		\AB_MCP_Tools_Meta_Auth::register( $r );
		self::assertStringContainsString( \AB_MCP_Tools_Base::READ_REDACTION_NOTE, $r->get( 'wp_get_term_meta' )['description'] );
		self::assertStringContainsString( 'is not decoded, so JSON or serialized data inside it is returned as written', \AB_MCP_Tools_Base::READ_REDACTION_NOTE );
		$key = $r->get( 'wp_get_post_meta' )['inputSchema']['properties']['key']['description'];
		self::assertStringContainsString( 'manage_options', $key );
		self::assertStringContainsString( 'ab_mcp_pro_protected_meta_allowlist', $key );
	}

	public function testTheAllowListIsOneList(): void {
		// The readers and wp_search_replace read the same list, filter included.
		self::assertSame( AB_MCP_Tools_Content::protected_meta_allowlist(), AB_MCP_Tools_Search_Replace::protected_meta_allowlist() );
		self::assertSame(
			array( '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode', '_elementor_template_type', '_elementor_controls_usage', '_wp_page_template', '_thumbnail_id', '_wp_attached_file', '_wp_attachment_image_alt', '_wp_attachment_metadata' ),
			AB_MCP_Tools_Content::protected_meta_allowlist(),
			'Pro\'s list, unchanged.'
		);
		add_filter( 'ab_mcp_pro_protected_meta_allowlist', static fn( $keys ) => array( '_only' ) );
		self::assertSame( array( '_only' ), AB_MCP_Tools_Search_Replace::protected_meta_allowlist() );
		$defined = 0;
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) as $file ) {
			$defined += substr_count( (string) file_get_contents( $file ), 'function protected_meta_allowlist(' ) + substr_count( (string) file_get_contents( $file ), 'function may_expose_protected(' );
		}
		self::assertSame( 2, $defined, 'Each rule is written once.' );
	}
}
