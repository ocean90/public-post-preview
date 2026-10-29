<?php
/**
 * Tests for the link store.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Token;

/**
 * The store must never keep a secret and must keep its query index in step with the links.
 */
class LinkStoreTest extends TestCase {

	/**
	 * Draft used by the tests.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	}

	public function test_created_link_verifies_with_its_token_only() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$link    = Link_Store\find( $this->post_id, $created['token']->id );

		$this->assertNotNull( $link );
		$this->assertTrue( $created['token']->verify( $this->post_id, $link->hash ) );
		$this->assertFalse( Token::generate()->verify( $this->post_id, $link->hash ) );
	}

	public function test_stored_meta_never_contains_the_secret() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$this->assertStringNotContainsString(
			$created['token']->secret,
			maybe_serialize( get_post_meta( $this->post_id ) )
		);
	}

	public function test_link_of_another_post_is_not_found() {
		$other   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$created = Link_Store\create( $other, array( 'expires_at' => 1793086400 ) );

		$this->assertNull( Link_Store\find( $this->post_id, $created['link']->id ) );
	}

	public function test_internal_links_are_hidden_unless_asked_for() {
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793001000, 'internal' => true ) );

		$this->assertCount( 1, Link_Store\get_links( $this->post_id ) );
		$this->assertCount( 2, Link_Store\get_links( $this->post_id, true ) );
	}

	public function test_expire_index_follows_the_latest_visible_link() {
		$this->assertSame( 0, Link_Store\get_latest_expiry( $this->post_id ) );

		$short = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$long  = Link_Store\create( $this->post_id, array( 'expires_at' => 1793600000 ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => 1799999999, 'internal' => true ) );

		$this->assertSame( 1793600000, Link_Store\get_latest_expiry( $this->post_id ) );

		Link_Store\delete( $this->post_id, $long['link']->id );
		$this->assertSame( 1793086400, Link_Store\get_latest_expiry( $this->post_id ) );

		Link_Store\delete( $this->post_id, $short['link']->id );
		$this->assertSame( 0, Link_Store\get_latest_expiry( $this->post_id ) );
	}

	public function test_deleting_one_link_keeps_the_others() {
		$first  = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$second = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$deleted = Link_Store\delete( $this->post_id, $first['link']->id );

		$this->assertSame( $first['link']->id, $deleted->id );
		$this->assertNull( Link_Store\find( $this->post_id, $first['link']->id ) );
		$this->assertNotNull( Link_Store\find( $this->post_id, $second['link']->id ) );
	}

	public function test_deleting_an_unknown_link_returns_null() {
		$this->assertNull( Link_Store\delete( $this->post_id, 'deadbeef' ) );
	}

	public function test_update_changes_only_the_given_link() {
		$first  = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$second = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400, 'label' => 'Copy editing' ) );

		$updated = Link_Store\update(
			$this->post_id,
			$first['link']->id,
			array(
				'label'      => 'Client review',
				'expires_at' => 1793600000,
			)
		);

		$this->assertSame( 'Client review', $updated->label );
		$this->assertSame( 'Client review', Link_Store\find( $this->post_id, $first['link']->id )->label );
		$this->assertSame( 'Copy editing', Link_Store\find( $this->post_id, $second['link']->id )->label );
		$this->assertSame( 1793600000, Link_Store\get_latest_expiry( $this->post_id ) );
	}

	public function test_update_does_not_bring_back_a_deleted_link() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		Link_Store\delete( $this->post_id, $created['link']->id );

		$this->assertNull( Link_Store\update( $this->post_id, $created['link']->id, array( 'last_accessed_at' => 1793000050 ) ) );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
	}

	/**
	 * @dataProvider data_invalid_expiries
	 *
	 * @param int $expires_at Expiry time.
	 */
	public function test_invalid_expiry_is_rejected( $expires_at ) {
		$this->assertWPError( Link_Store\create( $this->post_id, array( 'expires_at' => $expires_at ) ) );

		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$this->assertWPError( Link_Store\update( $this->post_id, $created['link']->id, array( 'expires_at' => $expires_at ) ) );
		$this->assertSame( 1793086400, Link_Store\find( $this->post_id, $created['link']->id )->expires_at );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public function data_invalid_expiries() {
		return array(
			'now'                 => array( 1793000000 ),
			'past'                => array( 1792999999 ),
			'beyond one year'     => array( 1793000000 + YEAR_IN_SECONDS + 1 ),
			'missing (zero)'      => array( 0 ),
		);
	}

	public function test_maximum_lifetime_counts_from_now() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793000000 + YEAR_IN_SECONDS ) );

		Clock::freeze( 1793000000 + DAY_IN_SECONDS );
		$updated = Link_Store\update( $this->post_id, $created['link']->id, array( 'expires_at' => 1793000000 + DAY_IN_SECONDS + YEAR_IN_SECONDS ) );

		$this->assertSame( 1793000000 + DAY_IN_SECONDS + YEAR_IN_SECONDS, $updated->expires_at );
	}

	public function test_label_is_stored_as_plain_text_of_limited_length() {
		$created = Link_Store\create(
			$this->post_id,
			array(
				'expires_at' => 1793086400,
				'label'      => '<b>Client</b> ' . str_repeat( 'ä', 120 ),
			)
		);

		$this->assertSame( 'Client ' . str_repeat( 'ä', 93 ), $created['link']->label );
	}

	public function test_link_with_backslashes_in_its_label_can_be_changed_and_deleted() {
		$created = Link_Store\create(
			$this->post_id,
			array(
				'expires_at' => 1793086400,
				'label'      => 'C:\\drafts\\review',
			)
		);

		$this->assertSame( 'C:\\drafts\\review', Link_Store\find( $this->post_id, $created['link']->id )->label );
		$this->assertNotNull( Link_Store\update( $this->post_id, $created['link']->id, array( 'last_accessed_at' => 1793000050 ) ) );
		$this->assertNotNull( Link_Store\delete( $this->post_id, $created['link']->id ) );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
	}

	public function test_prune_removes_only_expired_links() {
		$expired = Link_Store\create( $this->post_id, array( 'expires_at' => 1793000100 ) );
		$valid   = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		Clock::freeze( 1793000100 );
		$this->assertSame( 1, Link_Store\prune_expired( $this->post_id ) );

		$this->assertNull( Link_Store\find( $this->post_id, $expired['link']->id ) );
		$this->assertNotNull( Link_Store\find( $this->post_id, $valid['link']->id ) );
	}

	public function test_number_of_active_links_per_post_is_limited() {
		add_filter( 'ppp_max_links_per_post', static fn() => 2 );

		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		$second = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$this->assertWPError( Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) ) );
		$this->assertCount( 2, Link_Store\get_links( $this->post_id ) );

		Link_Store\delete( $this->post_id, $second['link']->id );
		$this->assertIsArray( Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) ) );
	}

	public function test_expired_and_internal_links_do_not_count_towards_the_limit() {
		add_filter( 'ppp_max_links_per_post', static fn() => 1 );

		Link_Store\create( $this->post_id, array( 'expires_at' => 1793000100 ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400, 'internal' => true ) );

		Clock::freeze( 1793000100 );
		$this->assertIsArray( Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) ) );
	}

	public function test_internal_link_is_created_when_the_limit_is_reached() {
		add_filter( 'ppp_max_links_per_post', static fn() => 1 );

		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$this->assertIsArray( Link_Store\create( $this->post_id, array( 'expires_at' => 1793001000, 'internal' => true ) ) );
	}

	public function test_limit_can_be_removed() {
		add_filter( 'ppp_max_links_per_post', static fn() => 0 );

		for ( $i = 0; $i <= Link_Store\MAX_LINKS; $i++ ) {
			$this->assertIsArray( Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) ) );
		}
	}

	public function test_delete_all_removes_internal_links_too() {
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793001000, 'internal' => true ) );

		$this->assertCount( 2, Link_Store\delete_all( $this->post_id ) );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
		$this->assertSame( 0, Link_Store\get_latest_expiry( $this->post_id ) );
	}
}
