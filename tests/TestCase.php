<?php
/**
 * Base test case.
 */

namespace DS\PublicPostPreview\Tests;

use DS_Public_Post_Preview;
use RuntimeException;
use WP_Post;
use WP_UnitTestCase;
use WPDieException;

/**
 * Thrown instead of redirecting, so the plugin's exit after a redirect doesn't end the test run.
 */
class RedirectException extends RuntimeException {}

/**
 * Shared helpers that go through the plugin's own code paths instead of its storage.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * ID of an editor who can edit all posts.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Creates the shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Turns redirects into exceptions.
	 */
	public function set_up() {
		parent::set_up();

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RedirectException( $location );
			}
		);
	}

	/**
	 * Enables the public preview through the handler of the classic editor form.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User who submits the form. Defaults to the editor.
	 */
	protected function enable_preview( $post_id, $user_id = 0 ) {
		$this->submit_preview_form( $post_id, true, $user_id );
	}

	/**
	 * Disables the public preview through the handler of the classic editor form.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function disable_preview( $post_id ) {
		$this->submit_preview_form( $post_id, false, 0 );
	}

	/**
	 * Submits the preview checkbox of the classic editor form.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $enable  Whether the checkbox is checked.
	 * @param int  $user_id User who submits the form. Defaults to the editor.
	 */
	private function submit_preview_form( $post_id, $enable, $user_id ) {
		wp_set_current_user( $user_id ?: self::$editor_id );

		$_POST['public_post_preview_wpnonce'] = wp_create_nonce( 'public-post-preview_' . $post_id );
		if ( $enable ) {
			$_POST['public_post_preview'] = '1';
		}

		DS_Public_Post_Preview::register_public_preview( $post_id, get_post( $post_id ) );

		$_POST = array();
	}

	/**
	 * Creates a draft with an enabled public preview.
	 *
	 * @return WP_Post The draft.
	 */
	protected function create_previewable_draft() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);

		$this->enable_preview( $post->ID );

		return $post;
	}

	/**
	 * Returns the public preview link the plugin hands out for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string The preview link.
	 */
	protected function get_preview_link( $post ) {
		return DS_Public_Post_Preview::get_preview_link( $post );
	}

	/**
	 * Returns the token of a preview link.
	 *
	 * @param string $link Preview link.
	 * @return string The token.
	 */
	protected function get_token( $link ) {
		$args = array();
		wp_parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $args );

		return $args['_ppp'];
	}

	/**
	 * Returns a preview link with its token replaced.
	 *
	 * @param string $link  Preview link.
	 * @param string $token Replacement token.
	 * @return string The modified link.
	 */
	protected function with_token( $link, $token ) {
		return add_query_arg( '_ppp', $token, $link );
	}

	/**
	 * Loads a preview link as an anonymous visitor.
	 *
	 * @param string $link Preview link.
	 */
	protected function visit_preview( $link ) {
		wp_set_current_user( 0 );
		$this->go_to( $link );
	}

	/**
	 * Asserts that an anonymous visitor gets no access through a preview link.
	 *
	 * @param string $link   Preview link.
	 * @param int    $status Expected HTTP status: 403 for an invalid link, 404 for an unavailable preview.
	 */
	protected function assert_preview_denied( $link, $status ) {
		try {
			$this->visit_preview( $link );
		} catch ( WPDieException $e ) {
			$this->assertSame( $status, $e->getCode(), 'Access was denied with an unexpected status.' );
			return;
		}

		$this->fail( 'The preview was shown although access should have been denied.' );
	}
}
