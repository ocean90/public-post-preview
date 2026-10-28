<?php
/**
 * Tests for anonymous access to a public preview.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;

/**
 * Each test describes a way an anonymous visitor could get access they should not have.
 */
class PreviewAccessTest extends TestCase {

	private const NOW = 1793000000;

	public function set_up() {
		parent::set_up();
		Clock::freeze( self::NOW );
	}

	public function tear_down() {
		Clock::unfreeze();
		parent::tear_down();
	}

	public function test_valid_link_shows_the_draft() {
		$post = $this->create_previewable_draft();

		$this->visit_preview( $this->get_preview_link( $post ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( have_posts() );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_preview_page_does_not_accept_comments() {
		$post = $this->create_previewable_draft();

		$this->visit_preview( $this->get_preview_link( $post ) );

		$this->assertFalse( comments_open( $post->ID ) );
	}

	public function test_link_of_a_published_post_redirects_to_its_permalink() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		wp_publish_post( $post->ID );

		$this->expectException( RedirectException::class );
		$this->expectExceptionMessage( get_permalink( $post->ID ) );
		$this->visit_preview( $link );
	}

	public function test_wrong_token_is_rejected() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		$this->assert_preview_denied( $this->with_token( $link, explode( '.', $this->get_token( $link ) )[0] . '.' . str_repeat( 'a', 32 ) ), 404 );
	}

	/**
	 * @dataProvider data_malformed_tokens
	 *
	 * @param string $token Malformed token.
	 */
	public function test_malformed_token_is_rejected( $token ) {
		$post = $this->create_previewable_draft();

		$this->assert_preview_denied( $this->with_token( $this->get_preview_link( $post ), $token ), 404 );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function data_malformed_tokens() {
		return array(
			'garbage'         => array( 'invalid' ),
			'unknown link id' => array( 'deadbeef.' . str_repeat( 'a', 32 ) ),
			'array syntax'    => array( 'a.b.c' ),
		);
	}

	public function test_old_nonce_link_explains_that_it_is_no_longer_valid() {
		$post = $this->create_previewable_draft();

		$this->assert_preview_denied( $this->with_token( $this->get_preview_link( $post ), '3f9a1c07be' ), 410 );
	}

	public function test_token_of_another_post_is_rejected() {
		$post  = $this->create_previewable_draft();
		$other = $this->create_previewable_draft();

		$link = $this->with_token( $this->get_preview_link( $post ), $this->get_token( $this->get_preview_link( $other ) ) );

		$this->assert_preview_denied( $link, 404 );
	}

	public function test_link_copied_with_the_post_meta_does_not_unlock_the_copy() {
		$post = $this->create_previewable_draft();
		$copy = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		foreach ( get_post_meta( $post->ID, Link_Store\META_KEY, false ) as $row ) {
			add_post_meta( $copy, Link_Store\META_KEY, wp_slash( $row ) );
		}

		$link = add_query_arg( 'p', $copy, $this->get_preview_link( $post ) );

		$this->assert_preview_denied( $link, 404 );
	}

	public function test_expired_link_is_rejected_from_the_moment_it_expires() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$this->create_link( $post->ID, array( 'expires_at' => gmdate( 'c', self::NOW + HOUR_IN_SECONDS ) ) );

		Clock::freeze( self::NOW + HOUR_IN_SECONDS );

		$this->assert_preview_denied( $this->get_preview_link( $post ), 403 );
	}

	public function test_extended_link_stays_valid_after_its_old_expiry() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$link = $this->create_link( $post->ID, array( 'expires_at' => gmdate( 'c', self::NOW + HOUR_IN_SECONDS ) ) );
		Link_Store\update( $post->ID, $link['id'], array( 'expires_at' => self::NOW + DAY_IN_SECONDS ) );

		Clock::freeze( self::NOW + 2 * HOUR_IN_SECONDS );
		$this->visit_preview( $this->get_preview_link( $post ) );

		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_revoked_link_is_rejected() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		$this->revoke_all_links( $post->ID );

		$this->assert_preview_denied( $link, 404 );
	}

	public function test_revoked_link_stays_rejected_while_another_link_works() {
		$post    = $this->create_previewable_draft();
		$revoked = $this->get_preview_link( $post );
		$this->create_link( $post->ID );
		$valid = $this->get_preview_link( $post );

		Link_Store\delete( $post->ID, explode( '.', $this->get_token( $revoked ) )[0] );

		$this->assert_preview_denied( $revoked, 404 );
		$this->visit_preview( $valid );
		$this->assertSame( $post->ID, get_queried_object_id() );
	}

	public function test_preview_is_not_restored_after_publishing_and_reverting_to_draft() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		wp_publish_post( $post->ID );
		wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );

		$this->assert_preview_denied( $link, 404 );
	}

	public function test_preview_is_not_restored_after_trashing_and_restoring() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		wp_trash_post( $post->ID );
		wp_untrash_post( $post->ID );

		$this->assert_preview_denied( $link, 404 );
	}

	public function test_user_who_cannot_edit_the_post_cannot_create_a_link() {
		$post        = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$this->assertWPError( $this->create_link( $post, array(), $contributor ) );
		$this->assertSame( array(), Link_Store\get_links( $post, true ) );
	}

	public function test_visit_records_the_first_and_last_access() {
		$post = $this->create_previewable_draft();
		$id   = explode( '.', $this->get_token( $this->get_preview_link( $post ) ) )[0];

		$this->visit_preview( $this->get_preview_link( $post ) );
		Clock::freeze( self::NOW + 20 * MINUTE_IN_SECONDS );
		$this->visit_preview( $this->get_preview_link( $post ) );

		$link = Link_Store\find( $post->ID, $id );
		$this->assertSame( self::NOW, $link->first_accessed_at );
		$this->assertSame( self::NOW + 20 * MINUTE_IN_SECONDS, $link->last_accessed_at );
	}

	public function test_last_access_is_written_at_most_every_15_minutes() {
		$post = $this->create_previewable_draft();
		$id   = explode( '.', $this->get_token( $this->get_preview_link( $post ) ) )[0];

		$this->visit_preview( $this->get_preview_link( $post ) );
		Clock::freeze( self::NOW + 14 * MINUTE_IN_SECONDS );
		$this->visit_preview( $this->get_preview_link( $post ) );

		$this->assertSame( self::NOW, Link_Store\find( $post->ID, $id )->last_accessed_at );
	}

	public function test_visit_by_an_editor_of_the_post_is_not_recorded() {
		$post = $this->create_previewable_draft();
		$id   = explode( '.', $this->get_token( $this->get_preview_link( $post ) ) )[0];

		wp_set_current_user( self::$editor_id );
		$this->go_to( $this->get_preview_link( $post ) );

		$this->assertNull( Link_Store\find( $post->ID, $id )->first_accessed_at );
	}

	public function test_every_visit_fires_the_access_action() {
		$post  = $this->create_previewable_draft();
		$calls = 0;
		add_action(
			'ppp_preview_accessed',
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$this->visit_preview( $this->get_preview_link( $post ) );
		$this->visit_preview( $this->get_preview_link( $post ) );

		$this->assertSame( 2, $calls );
	}
}
