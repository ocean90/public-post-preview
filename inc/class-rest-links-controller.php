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
			'/' . $this->rest_base . '/(?P<link_id>' . Token::ID_PATTERN . ')',
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

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'public-post-preview-link',
			'type'       => 'object',
			'properties' => Link_Service\get_link_schema_properties(),
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
		$fields = array();
		foreach ( Link_Service\WRITABLE_FIELDS as $field ) {
			if ( null !== $request[ $field ] ) {
				$fields[ $field ] = $request[ $field ];
			}
		}

		return $fields;
	}
}
