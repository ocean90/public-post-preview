<?php
/**
 * Tests for anonymous access to a public preview.
 */

namespace DS\PublicPostPreview\Tests;

/**
 * Each test describes a way an anonymous visitor could get access they should not have.
 */
class PreviewAccessTest extends TestCase {

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

		$this->assert_preview_denied( $this->with_token( $this->get_preview_link( $post ), 'invalid' ), 403 );
	}

	public function test_token_of_another_post_is_rejected() {
		$post  = $this->create_previewable_draft();
		$other = $this->create_previewable_draft();

		$link = $this->with_token( $this->get_preview_link( $post ), $this->get_token( $this->get_preview_link( $other ) ) );

		$this->assert_preview_denied( $link, 403 );
	}

	public function test_disabled_preview_is_rejected_even_with_a_valid_link() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		$this->disable_preview( $post->ID );

		$this->assert_preview_denied( $link, 404 );
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

	public function test_user_who_cannot_edit_the_post_cannot_enable_its_preview() {
		$post        = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		$this->enable_preview( $post->ID, $contributor );

		$this->assert_preview_denied( $this->get_preview_link( $post ), 404 );
	}
}
