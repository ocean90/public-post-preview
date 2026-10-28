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
	$link      = array(
		'type'       => 'object',
		'properties' => Link_Service\get_link_schema_properties(),
	);
	$post_id   = array(
		'description' => __( 'ID of the post.', 'public-post-preview' ),
		'type'        => 'integer',
		'minimum'     => 1,
	);
	$link_id   = array(
		'description' => __( 'ID of the preview link.', 'public-post-preview' ),
		'type'        => 'string',
		'pattern'     => '^[0-9a-f]{8}$',
	);
	$fields    = array_intersect_key( Link_Service\get_link_schema_properties(), array_flip( array( 'label', 'expires_at' ) ) );
	$with_post = static function ( array $properties, array $required = array() ) use ( $post_id ) {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge( array( 'post_id' => $post_id ), $properties ),
			'required'             => array_merge( array( 'post_id' ), $required ),
			'additionalProperties' => false,
		);
	};

	register_ability(
		'list-links',
		array(
			'label'         => __( 'List preview links', 'public-post-preview' ),
			'description'   => __( 'Lists the active preview links of an unpublished post, without their URLs.', 'public-post-preview' ),
			'input_schema'  => $with_post( array() ),
			'output_schema' => array(
				'type'  => 'array',
				'items' => $link,
			),
			'callback'      => static function ( array $input ) {
				return Link_Service\list_links( (int) $input['post_id'] );
			},
			'annotations'   => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		)
	);

	register_ability(
		'create-link',
		array(
			'label'         => __( 'Create a preview link', 'public-post-preview' ),
			'description'   => __( 'Creates a preview link for an unpublished post. The returned URL is shown only this once.', 'public-post-preview' ),
			'input_schema'  => $with_post( $fields ),
			'output_schema' => $link,
			'callback'      => static function ( array $input ) {
				return Link_Service\create_link( (int) $input['post_id'], $input );
			},
			'annotations'   => array(
				'readonly' => false,
			),
		)
	);

	register_ability(
		'update-link',
		array(
			'label'         => __( 'Change a preview link', 'public-post-preview' ),
			'description'   => __( 'Changes the label or extends the expiry of a preview link.', 'public-post-preview' ),
			'input_schema'  => $with_post( array_merge( array( 'link_id' => $link_id ), $fields ), array( 'link_id' ) ),
			'output_schema' => $link,
			'callback'      => static function ( array $input ) {
				return Link_Service\update_link( (int) $input['post_id'], (string) $input['link_id'], $input );
			},
			'annotations'   => array(
				'readonly'   => false,
				'idempotent' => true,
			),
		)
	);

	register_ability(
		'revoke-link',
		array(
			'label'         => __( 'Revoke a preview link', 'public-post-preview' ),
			'description'   => __( 'Revokes a preview link, so it no longer shows the post.', 'public-post-preview' ),
			'input_schema'  => $with_post( array( 'link_id' => $link_id ), array( 'link_id' ) ),
			'output_schema' => $link,
			'callback'      => static function ( array $input ) {
				return Link_Service\revoke_link( (int) $input['post_id'], (string) $input['link_id'] );
			},
			'annotations'   => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		)
	);

	register_ability(
		'revoke-all-links',
		array(
			'label'         => __( 'Revoke all preview links', 'public-post-preview' ),
			'description'   => __( 'Revokes all preview links of a post.', 'public-post-preview' ),
			'input_schema'  => $with_post( array() ),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array(
						'description' => __( 'Number of revoked links.', 'public-post-preview' ),
						'type'        => 'integer',
					),
				),
			),
			'callback'      => static function ( array $input ) {
				$deleted = Link_Service\revoke_all( (int) $input['post_id'] );

				return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => $deleted );
			},
			'annotations'   => array(
				'readonly'    => false,
				'destructive' => true,
			),
		)
	);
}

/**
 * Registers one ability of the plugin.
 *
 * @internal
 *
 * @param string               $name Ability name without namespace.
 * @param array<string, mixed> $args Label, description, schemas, callback and annotations.
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
			'execute_callback'    => $args['callback'],
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'annotations'  => $args['annotations'],
				'show_in_rest' => true,
			),
		)
	);
}
