<?php
/**
 * REST_Links_Controller class.
 */

namespace DS\PublicPostPreview;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for the preview links of a post.
 */
final class REST_Links_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'public-post-preview/v1';
		$this->rest_base = 'posts/(?P<post_id>[\d]+)/links';
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$post_id = array(
			'post_id' => array(
				'description' => __( 'ID of the post.', 'public-post-preview' ),
				'type'        => 'integer',
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'args'   => $post_id,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<link_id>[0-9a-f]{8})',
			array(
				'args'   => array_merge(
					$post_id,
					array(
						'link_id' => array(
							'description' => __( 'ID of the preview link.', 'public-post-preview' ),
							'type'        => 'string',
						),
					)
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks that the current user may manage the links of the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error True, or an error.
	 */
	public function permissions_check( $request ) {
		$post = Link_Service\get_manageable_post( (int) $request['post_id'] );

		return is_wp_error( $post ) ? $post : true;
	}

	/**
	 * Lists the links of a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_items( $request ) {
		return rest_ensure_response( Link_Service\list_links( (int) $request['post_id'] ) );
	}

	/**
	 * Creates a link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_item( $request ) {
		$link = Link_Service\create_link( (int) $request['post_id'], $this->get_link_fields( $request ) );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$response = rest_ensure_response( $link );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Changes the label or expiry of a link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_item( $request ) {
		return rest_ensure_response( Link_Service\update_link( (int) $request['post_id'], (string) $request['link_id'], $this->get_link_fields( $request ) ) );
	}

	/**
	 * Revokes a link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_item( $request ) {
		$link = Link_Service\revoke_link( (int) $request['post_id'], (string) $request['link_id'] );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $link,
			)
		);
	}

	/**
	 * Revokes all links of a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_items( $request ) {
		$deleted = Link_Service\revoke_all( (int) $request['post_id'] );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/**
	 * Returns the schema of a link.
	 *
	 * @return array<string, mixed> Schema.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->schema;
		}

		$date = array(
			'type'     => array( 'string', 'null' ),
			'format'   => 'date-time',
			'readonly' => true,
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'public-post-preview-link',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'ID of the preview link.', 'public-post-preview' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'label'             => array(
					'description' => __( 'Optional label to tell links apart.', 'public-post-preview' ),
					'type'        => 'string',
				),
				'hint'              => array(
					'description' => __( 'Last four characters of the link, to tell links apart.', 'public-post-preview' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'created_at'        => array_merge( $date, array( 'description' => __( 'Creation time in UTC.', 'public-post-preview' ) ) ),
				'created_by'        => array(
					'description' => __( 'ID of the user who created the link.', 'public-post-preview' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'expires_at'        => array(
					'description' => __( 'Expiry time as ISO 8601 date, UTC unless an offset is given.', 'public-post-preview' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'first_accessed_at' => array_merge( $date, array( 'description' => __( 'First visit in UTC.', 'public-post-preview' ) ) ),
				'last_accessed_at'  => array_merge( $date, array( 'description' => __( 'Last visit in UTC, updated at most every 15 minutes.', 'public-post-preview' ) ) ),
				'url'               => array(
					'description' => __( 'Preview URL. Only included when the link is created.', 'public-post-preview' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->schema;
	}

	/**
	 * Reads the label and expiry from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed> Fields that were sent.
	 */
	private function get_link_fields( WP_REST_Request $request ): array {
		return array_filter(
			array(
				'label'      => $request['label'],
				'expires_at' => $request['expires_at'],
			),
			static function ( $value ) {
				return null !== $value;
			}
		);
	}
}
