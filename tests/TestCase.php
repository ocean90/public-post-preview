<?php
/**
 * Base test case.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Link_Service;
use RuntimeException;
use WP_Error;
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
	 * Preview URLs of the links created by `create_link()`, by post ID.
	 *
	 * @var array<int, string>
	 */
	private $preview_urls = array();

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
	 * Creates a link the way the editor does.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $args    Label and expiry.
	 * @param int                  $user_id User who creates the link. Defaults to the editor.
	 * @return array<string, mixed>|WP_Error The link with its URL, or an error.
	 */
	protected function create_link( $post_id, array $args = array(), $user_id = 0 ) {
		wp_set_current_user( $user_id ?: self::$editor_id );
		$link = Link_Service\create_link( $post_id, $args );
		wp_set_current_user( 0 );

		if ( ! is_wp_error( $link ) ) {
			$this->preview_urls[ $post_id ] = $link['url'];
		}

		return $link;
	}

	/**
	 * Revokes all links of a post the way the editor does.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function revoke_all_links( $post_id ) {
		wp_set_current_user( self::$editor_id );
		Link_Service\revoke_all( $post_id );
		wp_set_current_user( 0 );
	}

	/**
	 * Creates a draft with a preview link.
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

		$this->create_link( $post->ID );

		return $post;
	}

	/**
	 * Returns the URL of the last link created for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string The preview URL.
	 */
	protected function get_preview_link( $post ) {
		return $this->preview_urls[ $post->ID ];
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
	 * @param int    $status Expected HTTP status: 404 for an unknown link, 403 for an expired one, 410 for an old one.
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
