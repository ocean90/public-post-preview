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
		Clock::freeze( 1793000000 );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	}

	public function tear_down() {
		Clock::unfreeze();
		parent::tear_down();
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

	public function test_hint_is_the_end_of_the_secret() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$this->assertSame( substr( $created['token']->secret, -4 ), $created['link']->hint );
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
		$second = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400, 'label' => 'Lektorat' ) );

		$changed             = clone $first['link'];
		$changed->label      = 'Kunde Müller';
		$changed->expires_at = 1793600000;

		$this->assertTrue( Link_Store\update( $this->post_id, $first['link'], $changed ) );
		$this->assertSame( 'Kunde Müller', Link_Store\find( $this->post_id, $first['link']->id )->label );
		$this->assertSame( 'Lektorat', Link_Store\find( $this->post_id, $second['link']->id )->label );
		$this->assertSame( 1793600000, Link_Store\get_latest_expiry( $this->post_id ) );
	}

	public function test_update_fails_when_the_link_changed_in_between() {
		$created = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		$first        = clone $created['link'];
		$first->label = 'First';
		Link_Store\update( $this->post_id, $created['link'], $first );

		$second        = clone $created['link'];
		$second->label = 'Second';

		$this->assertFalse( Link_Store\update( $this->post_id, $created['link'], $second ) );
		$this->assertSame( 'First', Link_Store\find( $this->post_id, $created['link']->id )->label );
	}

	public function test_prune_removes_only_expired_links() {
		$expired = Link_Store\create( $this->post_id, array( 'expires_at' => 1793000100 ) );
		$valid   = Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );

		Clock::freeze( 1793000100 );
		$this->assertSame( 1, Link_Store\prune_expired( $this->post_id ) );

		$this->assertNull( Link_Store\find( $this->post_id, $expired['link']->id ) );
		$this->assertNotNull( Link_Store\find( $this->post_id, $valid['link']->id ) );
	}

	public function test_delete_all_removes_internal_links_too() {
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793086400 ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => 1793001000, 'internal' => true ) );

		$this->assertSame( 2, Link_Store\delete_all( $this->post_id ) );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
		$this->assertSame( 0, Link_Store\get_latest_expiry( $this->post_id ) );
	}
}
