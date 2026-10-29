<?php
/**
 * Tests for the anonymous preview status endpoint.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Clock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The endpoint is public, so it must not reveal more than the preview page itself.
 */
class PreviewStatusEndpointTest extends TestCase {

	/**
	 * Requests the status of a post as an anonymous visitor holding a token.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Value of the `_ppp` parameter.
	 * @return WP_REST_Response The response.
	 */
	private function request_status( $post_id, $token ) {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', "/public-post-preview/v1/posts/{$post_id}/preview-status" );
		$request->set_param( '_ppp', $token );

		return rest_do_request( $request );
	}

	public function test_reports_the_modified_date_of_a_previewable_draft() {
		$post = $this->create_previewable_draft();

		$response = $this->request_status( $post->ID, $this->get_token( $this->get_preview_link( $post ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'status'   => 'preview',
				'modified' => get_post( $post->ID )->post_modified,
			),
			$response->get_data()
		);
	}

	public function test_wrong_token_is_rejected() {
		$post = $this->create_previewable_draft();

		$this->assertSame( 404, $this->request_status( $post->ID, 'invalid' )->get_status() );
	}

	public function test_token_of_another_post_is_rejected() {
		$post  = $this->create_previewable_draft();
		$other = $this->create_previewable_draft();

		$this->assertSame( 404, $this->request_status( $post->ID, $this->get_token( $this->get_preview_link( $other ) ) )->get_status() );
	}

	public function test_revoked_link_is_reported_as_unavailable() {
		$post  = $this->create_previewable_draft();
		$token = $this->get_token( $this->get_preview_link( $post ) );

		$this->revoke_all_links( $post->ID );

		$this->assertSame( 404, $this->request_status( $post->ID, $token )->get_status() );
	}

	public function test_expired_link_is_reported_as_expired() {
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$link = $this->create_link( $post, array( 'expires_at' => gmdate( 'c', self::NOW + 60 ) ) );

		Clock::freeze( self::NOW + 60 );

		$this->assertSame( 403, $this->request_status( $post, $this->get_token( $link['url'] ) )->get_status() );
	}

	public function test_old_nonce_is_reported_as_gone() {
		$post = $this->create_previewable_draft();

		$this->assertSame( 410, $this->request_status( $post->ID, '3f9a1c07be' )->get_status() );
	}

	public function test_published_post_reports_its_permalink() {
		$post  = $this->create_previewable_draft();
		$token = $this->get_token( $this->get_preview_link( $post ) );

		wp_publish_post( $post->ID );

		$response = $this->request_status( $post->ID, $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'status'    => 'published',
				'permalink' => get_permalink( $post->ID ),
			),
			$response->get_data()
		);
	}

	public function test_private_post_does_not_reveal_its_permalink() {
		$post  = $this->create_previewable_draft();
		$token = $this->get_token( $this->get_preview_link( $post ) );

		wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'private' ) );

		$this->assertSame( 404, $this->request_status( $post->ID, $token )->get_status() );
	}

	public function test_endpoint_is_closed_when_polling_is_disabled() {
		$post = $this->create_previewable_draft();

		add_filter( 'ppp_poll_for_updates', '__return_false' );

		$this->assertSame( 404, $this->request_status( $post->ID, $this->get_token( $this->get_preview_link( $post ) ) )->get_status() );
	}

	public function test_response_tells_caches_not_to_store_it() {
		$post    = $this->create_previewable_draft();
		$request = new WP_REST_Request( 'GET', "/public-post-preview/v1/posts/{$post->ID}/preview-status" );
		$request->set_param( '_ppp', 'invalid' );

		$response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );

		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
	}

	public function test_token_that_is_not_a_string_is_rejected() {
		$post    = $this->create_previewable_draft();
		$request = new WP_REST_Request( 'GET', "/public-post-preview/v1/posts/{$post->ID}/preview-status" );
		$request->set_param( '_ppp', array( 'x' ) );

		// The Allow header runs the permission callback again, with the parameters as they were sent.
		$response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_id_zero_is_rejected() {
		$this->assertSame( 400, $this->request_status( 0, 'invalid' )->get_status() );
	}
}
