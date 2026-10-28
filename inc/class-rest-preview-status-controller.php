<?php
/**
 * REST_Preview_Status_Controller class.
 */

namespace DS\PublicPostPreview;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public route that preview pages poll to learn whether the post changed.
 */
final class REST_Preview_Status_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'public-post-preview/v1';
		$this->rest_base = 'posts/(?P<id>[\d]+)/preview-status';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id'   => array(
							'description' => __( 'ID of the previewed post.', 'public-post-preview' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'_ppp' => array(
							'description' => __( 'Token from the preview link.', 'public-post-preview' ),
							'type'        => 'string',
							'default'     => '',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Tells caches not to store any response of this route, errors included.
	 *
	 * `rest_send_nocache_headers` can't be used: it is read before the route runs.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response Response.
	 */
	public static function add_nocache_headers( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && preg_match( '#^/public-post-preview/v1/posts/\d+/preview-status$#', $request->get_route() ) ) {
			$response->set_headers( array_merge( $response->get_headers(), wp_get_nocache_headers() ) );
		}

		return $response;
	}

	/**
	 * Lets anyone see that a post went live, and everyone else needs a valid link.
	 *
	 * The token is checked here instead of `edit_post`, because visitors of a preview are
	 * usually not logged in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error True, or an error.
	 */
	public function get_item_permissions_check( $request ) {
		$post_id = (int) $request['id'];

		/** This filter is documented in public-post-preview.php */
		if ( ! apply_filters( 'ppp_poll_for_updates', true, $post_id ) ) {
			return Preview_Access\unavailable();
		}

		if ( Preview_Policy\get_live_post( $post_id ) ) {
			return true;
		}

		$link = Preview_Access\check( $post_id, (string) $request['_ppp'] );

		return is_wp_error( $link ) ? $link : true;
	}

	/**
	 * Returns whether the post is still a preview or went live.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function get_item( $request ) {
		$post_id   = (int) $request['id'];
		$published = Preview_Policy\get_live_post( $post_id );
		if ( $published ) {
			return rest_ensure_response(
				array(
					'status'    => 'published',
					'permalink' => get_permalink( $published ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'status'   => 'preview',
				'modified' => get_post( $post_id )->post_modified,
			)
		);
	}

	/**
	 * Returns the schema of the response.
	 *
	 * @return array<string, mixed> Schema.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'public-post-preview-status',
			'type'       => 'object',
			'properties' => array(
				'status'    => array(
					'description' => __( 'Whether the post is still a preview or has been published.', 'public-post-preview' ),
					'type'        => 'string',
					'enum'        => array( 'preview', 'published' ),
					'readonly'    => true,
				),
				'modified'  => array(
					'description' => __( 'Last modified time of the post in the site\'s timezone. Only present while the post is a preview.', 'public-post-preview' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'permalink' => array(
					'description' => __( 'URL of the post. Only present once the post has been published.', 'public-post-preview' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);
	}
}
