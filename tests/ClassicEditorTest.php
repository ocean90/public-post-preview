<?php
/**
 * Tests for the Classic Editor UI.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Classic_Editor;
use DS\PublicPostPreview\Clock;
use WP_Post;

/**
 * The publish box must only offer links where the REST API accepts them, and the page must never
 * carry a working preview URL.
 */
class ClassicEditorTest extends TestCase {

	/**
	 * Handle of the Classic Editor script.
	 */
	private const HANDLE = 'public-post-preview-classic-editor';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$editor_id );
	}

	public function tear_down() {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Renders the publish box section of a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Markup.
	 */
	private function render( WP_Post $post ) {
		ob_start();
		Classic_Editor\render( $post );

		return (string) ob_get_clean();
	}

	/**
	 * Enqueues the assets the way the classic edit screen of a post does.
	 *
	 * @param WP_Post $post            Post object.
	 * @param bool    $is_block_editor Whether the screen uses the block editor.
	 */
	private function enqueue_on_edit_screen( WP_Post $post, $is_block_editor = false ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set like post.php does.
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( $is_block_editor );

		Classic_Editor\enqueue_assets( 'post.php' );
	}

	/**
	 * Returns the inline config of the script.
	 *
	 * @return string Inline script.
	 */
	private function get_inline_config() {
		return implode( "\n", (array) wp_scripts()->get_data( self::HANDLE, 'before' ) );
	}

	/**
	 * Posts that can't have links.
	 *
	 * @return array<string, array<int, array<string, string>>> Post arguments.
	 */
	public function data_posts_without_links() {
		return array(
			'published'  => array( array( 'post_status' => 'publish' ) ),
			'private'    => array( array( 'post_status' => 'private' ) ),
			'auto-draft' => array( array( 'post_status' => 'auto-draft' ) ),
		);
	}

	/**
	 * @dataProvider data_posts_without_links
	 *
	 * @param array<string, string> $args Post arguments.
	 */
	public function test_no_box_and_no_script_for_posts_without_links( array $args ) {
		$post = self::factory()->post->create_and_get( $args );

		$this->assertSame( '', $this->render( $post ) );

		$this->enqueue_on_edit_screen( $post );
		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_no_box_for_post_types_without_public_previews() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		add_filter( 'ppp_post_types', '__return_empty_array' );

		$this->assertSame( '', $this->render( $post ) );
	}

	public function test_no_script_in_the_block_editor() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		$this->enqueue_on_edit_screen( $post, true );

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_box_and_script_for_a_draft() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		$this->assertStringContainsString( 'id="public-post-preview"', $this->render( $post ) );

		$this->enqueue_on_edit_screen( $post );
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	public function test_page_never_contains_a_working_preview_url() {
		$post  = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$link  = $this->create_link( $post->ID );
		$token = $this->get_token( $link['url'] );
		wp_set_current_user( self::$editor_id );

		$this->enqueue_on_edit_screen( $post );
		$page = $this->render( $post ) . $this->get_inline_config();

		$this->assertStringContainsString( $link['id'], $page, 'The link is missing from the page.' );
		$this->assertStringNotContainsString( explode( '.', $token )[1], $page, 'The page leaks the secret of a link.' );
	}

	public function test_expired_links_are_not_listed() {
		$post    = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$expired = $this->create_link( $post->ID, array( 'expires_at' => gmdate( 'c', self::NOW + HOUR_IN_SECONDS ) ) );
		$active  = $this->create_link( $post->ID, array( 'expires_at' => gmdate( 'c', self::NOW + DAY_IN_SECONDS ) ) );
		wp_set_current_user( self::$editor_id );

		Clock::freeze( self::NOW + 2 * HOUR_IN_SECONDS );
		$this->enqueue_on_edit_screen( $post );

		$config = $this->get_inline_config();
		$this->assertStringContainsString( $active['id'], $config );
		$this->assertStringNotContainsString( $expired['id'], $config );
	}
}
