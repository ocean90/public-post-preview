<?php
/**
 * Offers the link operations through the Abilities API, for agents and automations.
 *
 * The abilities are a thin layer over Link_Service. Core hides the reason when a
 * permission callback fails, so the permission callback only requires a logged-in user and
 * the post-level checks run in Link_Service. That way a missing or foreign post gets the
 * same error as from the REST API.
 */

namespace DS\PublicPostPreview\Abilities;

use DS\PublicPostPreview\Link_Service;
use DS\PublicPostPreview\Token;

/**
 * Slug of the ability category.
 */
const CATEGORY = 'public-post-preview';

/**
 * Registers the hooks.
 */
function register(): void {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
}

/**
 * Registers the ability category.
 */
function register_category(): void {
	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => __( 'Public Post Preview', 'public-post-preview' ),
			'description' => __( 'Share unpublished posts with people who have no account, through preview links.', 'public-post-preview' ),
		)
	);
}

/**
 * Registers the abilities.
 */
function register_abilities(): void {
	$properties = Link_Service\get_link_schema_properties();
	$link       = array(
		'type'       => 'object',
		'properties' => $properties,
	);
	$fields     = array_intersect_key( $properties, array_flip( Link_Service\WRITABLE_FIELDS ) );
	$link_id    = array(
		'link_id' => array(
			'description' => __( 'ID of the preview link.', 'public-post-preview' ),
			'type'        => 'string',
			'pattern'     => '^' . Token::ID_PATTERN . '$',
		),
	);

	register_ability(
		'list-links',
		array(
			'label'            => __( 'List preview links', 'public-post-preview' ),
			'description'      => __( 'Lists the active preview links of an unpublished post, without their URLs.', 'public-post-preview' ),
			'input_schema'     => get_input_schema(),
			'output_schema'    => array(
				'type'  => 'array',
				'items' => $link,
			),
			'execute_callback' => static function ( array $input ) {
				return Link_Service\list_links( (int) $input['post_id'] );
			},
			'annotations'      => array( 'readonly' => true ),
		)
	);

	register_ability(
		'create-link',
		array(
			'label'            => __( 'Create a preview link', 'public-post-preview' ),
			'description'      => __( 'Creates a preview link for an unpublished post. The returned URL is shown only this once.', 'public-post-preview' ),
			'input_schema'     => get_input_schema( $fields ),
			'output_schema'    => $link,
			'execute_callback' => static function ( array $input ) {
				return Link_Service\create_link( (int) $input['post_id'], $input );
			},
			'annotations'      => array( 'destructive' => false ),
		)
	);

	register_ability(
		'update-link',
		array(
			'label'            => __( 'Change a preview link', 'public-post-preview' ),
			'description'      => __( 'Changes the label or the expiry of a preview link. The link keeps its URL.', 'public-post-preview' ),
			'input_schema'     => get_input_schema( array_merge( $link_id, $fields ), array( 'link_id' ) ),
			'output_schema'    => $link,
			'execute_callback' => static function ( array $input ) {
				return Link_Service\update_link( (int) $input['post_id'], (string) $input['link_id'], $input );
			},
			'annotations'      => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		)
	);

	register_ability(
		'revoke-link',
		array(
			'label'            => __( 'Revoke a preview link', 'public-post-preview' ),
			'description'      => __( 'Revokes a preview link, so it no longer shows the post.', 'public-post-preview' ),
			'input_schema'     => get_input_schema( $link_id, array( 'link_id' ) ),
			'output_schema'    => $link,
			'execute_callback' => static function ( array $input ) {
				return Link_Service\revoke_link( (int) $input['post_id'], (string) $input['link_id'] );
			},
			'annotations'      => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		)
	);

	register_ability(
		'revoke-all-links',
		array(
			'label'            => __( 'Revoke all preview links', 'public-post-preview' ),
			'description'      => __( 'Revokes all preview links of a post.', 'public-post-preview' ),
			'input_schema'     => get_input_schema(),
			'output_schema'    => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array(
						'description' => __( 'Number of revoked links.', 'public-post-preview' ),
						'type'        => 'integer',
					),
				),
			),
			'execute_callback' => static function ( array $input ) {
				$deleted = Link_Service\revoke_all( (int) $input['post_id'] );

				return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => $deleted );
			},
			'annotations'      => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		)
	);
}

/**
 * Returns an input schema with the post ID and further properties.
 *
 * @internal
 *
 * @param array<string, array<string, mixed>> $properties Further properties.
 * @param string[]                            $required   Further required properties.
 * @return array<string, mixed> Input schema.
 */
function get_input_schema( array $properties = array(), array $required = array() ): array {
	return array(
		'type'                 => 'object',
		'properties'           => array_merge(
			array(
				'post_id' => array(
					'description' => __( 'ID of the post.', 'public-post-preview' ),
					'type'        => 'integer',
					'minimum'     => 1,
				),
			),
			$properties
		),
		'required'             => array_merge( array( 'post_id' ), $required ),
		'additionalProperties' => false,
	);
}

/**
 * Registers one ability of the plugin.
 *
 * @internal
 *
 * @param string               $name Ability name without namespace.
 * @param array<string, mixed> $args Label, description, schemas, execute callback and annotations.
 */
function register_ability( string $name, array $args ): void {
	wp_register_ability(
		CATEGORY . '/' . $name,
		array(
			'label'               => $args['label'],
			'description'         => $args['description'],
			'category'            => CATEGORY,
			'input_schema'        => $args['input_schema'],
			'output_schema'       => $args['output_schema'],
			'execute_callback'    => $args['execute_callback'],
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'annotations'  => $args['annotations'],
				'show_in_rest' => true,
				// Lists the ability on the default server of the MCP Adapter.
				'mcp'          => array( 'public' => true ),
			),
		)
	);
}
