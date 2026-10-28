<?php
/**
 * Tests for the anonymous preview status endpoint.
 */

namespace DS\PublicPostPreview\Tests;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The endpoint is public, so it must not reveal more than the preview page itself.
 */
class PreviewStatusEndpointTest extends TestCase {

	/**
	 * Requests the status of a post as an anonymous visitor holding a preview link.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $link    Preview link.
	 * @return WP_REST_Response The response.
	 */
	private function request_status( $post_id, $link ) {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/public-post-preview/v1/status/' . $post_id );
		$request->set_param( '_ppp', $this->get_token( $link ) );

		return rest_do_request( $request );
	}

	public function test_reports_the_modified_date_of_a_previewable_draft() {
		$post = $this->create_previewable_draft();

		$response = $this->request_status( $post->ID, $this->get_preview_link( $post ) );

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

		$this->assertSame( 403, $this->request_status( $post->ID, $this->with_token( $this->get_preview_link( $post ), 'invalid' ) )->get_status() );
	}

	public function test_token_of_another_post_is_rejected() {
		$post  = $this->create_previewable_draft();
		$other = $this->create_previewable_draft();

		$this->assertSame( 403, $this->request_status( $post->ID, $this->get_preview_link( $other ) )->get_status() );
	}

	public function test_disabled_preview_is_reported_as_unavailable() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		$this->disable_preview( $post->ID );

		$this->assertSame( 404, $this->request_status( $post->ID, $link )->get_status() );
	}

	public function test_published_post_reports_its_permalink() {
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		wp_publish_post( $post->ID );

		$response = $this->request_status( $post->ID, $link );

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
		$post = $this->create_previewable_draft();
		$link = $this->get_preview_link( $post );

		wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'private' ) );

		$this->assertSame( 404, $this->request_status( $post->ID, $link )->get_status() );
	}

	public function test_endpoint_is_closed_when_polling_is_disabled() {
		$post = $this->create_previewable_draft();

		add_filter( 'ppp_poll_for_updates', '__return_false' );

		$this->assertSame( 404, $this->request_status( $post->ID, $this->get_preview_link( $post ) )->get_status() );
	}
}
