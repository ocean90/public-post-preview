<?php
/**
 * Manages the links of a post on behalf of the current user.
 *
 * Shared by the REST API and the abilities. Every operation checks access itself, so neither
 * caller can skip it, and dates go in and out as ISO 8601 strings.
 */

namespace DS\PublicPostPreview\Link_Service;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;
use WP_Error;
use WP_Post;

/**
 * Link fields that can be set when creating or changing a link.
 */
const WRITABLE_FIELDS = array( 'label', 'expires_at' );

/**
 * Returns a post the current user may manage links for.
 *
 * Missing posts and posts the user can't edit get the same error, so the response doesn't
 * reveal whether a draft exists.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error The post, or an error.
 */
function get_manageable_post( int $post_id ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'ppp_not_logged_in',
			__( 'Sorry, you must be logged in to manage preview links.', 'public-post-preview' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error(
			'ppp_post_not_found',
			__( 'Invalid post ID.', 'public-post-preview' ),
			array( 'status' => 404 )
		);
	}

	if ( ! Preview_Policy\is_previewable( $post ) ) {
		return new WP_Error(
			'ppp_post_not_previewable',
			__( 'Public previews are only available for unpublished posts of a viewable post type.', 'public-post-preview' ),
			array( 'status' => 400 )
		);
	}

	return $post;
}

/**
 * Returns the active visible links of a post.
 *
 * Expired links are left out but not deleted here, so listing stays free of side effects.
 *
 * @param int $post_id Post ID.
 * @return array<int, array<string, mixed>>|WP_Error Links, or an error.
 */
function list_links( int $post_id ) {
	$post = get_manageable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$now   = Clock::now();
	$links = array_filter(
		Link_Store\get_links( $post->ID ),
		static function ( Link $link ) use ( $now ) {
			return ! $link->is_expired( $now );
		}
	);

	return array_values( array_map( __NAMESPACE__ . '\\prepare', $links ) );
}

/**
 * Creates a link and returns it with its URL, the only time the URL is available.
 *
 * @param int                  $post_id Post ID.
 * @param array<string, mixed> $args {
 *     @type string $label      Optional label.
 *     @type string $expires_at Optional expiry as ISO 8601 date. Defaults to the default lifetime.
 * }
 * @return array<string, mixed>|WP_Error The link, or an error.
 */
function create_link( int $post_id, array $args ) {
	$post = get_manageable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$expires_at = Clock::now() + Preview_Policy\get_default_lifetime();
	if ( isset( $args['expires_at'] ) ) {
		$expires_at = parse_date( (string) $args['expires_at'] );
		if ( is_wp_error( $expires_at ) ) {
			return $expires_at;
		}
	}

	Link_Store\prune_expired( $post->ID );

	$created = Link_Store\create(
		$post->ID,
		array(
			'label'      => $args['label'] ?? '',
			'expires_at' => $expires_at,
		)
	);
	if ( is_wp_error( $created ) ) {
		return $created;
	}

	/**
	 * Fires after a preview link was created.
	 *
	 * @since 4.0.0
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $link_id    Link ID.
	 * @param int    $expires_at Expiry time, Unix timestamp.
	 */
	do_action( 'ppp_link_created', $post->ID, $created['link']->id, $created['link']->expires_at );

	$link        = prepare( $created['link'] );
	$link['url'] = Preview_Policy\get_preview_url( $post, $created['token']->to_string() );

	return $link;
}

/**
 * Changes the label or expiry of a link.
 *
 * @param int                  $post_id Post ID.
 * @param string               $link_id Link ID.
 * @param array<string, mixed> $changes Values for `label` and `expires_at` (ISO 8601 date).
 * @return array<string, mixed>|WP_Error The changed link, or an error.
 */
function update_link( int $post_id, string $link_id, array $changes ) {
	$link = find_visible( $post_id, $link_id );
	if ( is_wp_error( $link ) ) {
		return $link;
	}

	$changes = array_intersect_key( $changes, array_flip( WRITABLE_FIELDS ) );
	if ( isset( $changes['expires_at'] ) ) {
		$changes['expires_at'] = parse_date( (string) $changes['expires_at'] );
		if ( is_wp_error( $changes['expires_at'] ) ) {
			return $changes['expires_at'];
		}
	}

	if ( ! $changes ) {
		return prepare( $link );
	}

	$updated = Link_Store\update( $post_id, $link->id, $changes );
	if ( null === $updated ) {
		return link_not_found();
	}
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return prepare( $updated );
}

/**
 * Revokes a link.
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return array<string, mixed>|WP_Error The revoked link, or an error.
 */
function revoke_link( int $post_id, string $link_id ) {
	$link = find_visible( $post_id, $link_id );
	if ( is_wp_error( $link ) ) {
		return $link;
	}

	$deleted = Link_Store\delete( $post_id, $link->id );
	if ( ! $deleted ) {
		return link_not_found();
	}

	fire_revoked( $post_id, $deleted->id );

	return prepare( $deleted );
}

/**
 * Revokes all links of a post.
 *
 * @param int $post_id Post ID.
 * @return int|WP_Error Number of revoked visible links, or an error.
 */
function revoke_all( int $post_id ) {
	$post = get_manageable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	return remove_all_links( $post->ID );
}

/**
 * Deletes all links of a post without checking access, for publishing and trashing.
 *
 * Internal links are deleted too, without an action, as they never appear anywhere.
 *
 * @param int $post_id Post ID.
 * @return int Number of deleted visible links.
 */
function remove_all_links( int $post_id ): int {
	$count = 0;
	foreach ( Link_Store\delete_all( $post_id ) as $link ) {
		if ( ! $link->internal ) {
			fire_revoked( $post_id, $link->id );
			++$count;
		}
	}

	return $count;
}

/**
 * Returns the JSON schema properties of a link, as returned by `prepare()`.
 *
 * @return array<string, array<string, mixed>> Schema properties.
 */
function get_link_schema_properties(): array {
	$date = array(
		'type'     => array( 'string', 'null' ),
		'format'   => 'date-time',
		'readonly' => true,
	);

	return array(
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
	);
}

/**
 * Returns the public representation of a link, without its hash.
 *
 * @param Link $link Link.
 * @return array<string, mixed> Link data.
 */
function prepare( Link $link ): array {
	return array(
		'id'                => $link->id,
		'label'             => $link->label,
		'hint'              => $link->hint,
		'created_at'        => format_date( $link->created_at ),
		'created_by'        => $link->created_by,
		'expires_at'        => format_date( $link->expires_at ),
		'first_accessed_at' => format_date( $link->first_accessed_at ),
		'last_accessed_at'  => format_date( $link->last_accessed_at ),
	);
}

/**
 * Parses an ISO 8601 date.
 *
 * @internal
 *
 * @param string $date Date, UTC unless it has an offset.
 * @return int|WP_Error Unix timestamp, or an error for an invalid date.
 */
function parse_date( string $date ) {
	// Without forcing UTC, so an offset in the date is applied. Dates without one parse as UTC.
	$timestamp = rest_parse_date( $date );
	if ( false === $timestamp ) {
		return new WP_Error(
			'ppp_invalid_expiry',
			__( 'The expiry date must be an ISO 8601 date.', 'public-post-preview' ),
			array( 'status' => 400 )
		);
	}

	return (int) $timestamp;
}

/**
 * Formats a timestamp as ISO 8601 in UTC.
 *
 * @internal
 *
 * @param int|null $timestamp Unix timestamp.
 * @return string|null Date, or null without a timestamp.
 */
function format_date( ?int $timestamp ): ?string {
	return null === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
}

/**
 * Finds a link that is not internal, on a post the current user may manage.
 *
 * @internal
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return Link|WP_Error The link, or an error.
 */
function find_visible( int $post_id, string $link_id ) {
	$post = get_manageable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$link = Link_Store\find( $post->ID, $link_id );

	// An expired link that hasn't been pruned yet must not come back through an update.
	if ( ! $link || $link->internal || $link->is_expired( Clock::now() ) ) {
		return link_not_found();
	}

	return $link;
}

/**
 * Returns the error for an unknown link.
 *
 * @internal
 *
 * @return WP_Error Error.
 */
function link_not_found(): WP_Error {
	return new WP_Error(
		'ppp_link_not_found',
		__( 'Invalid preview link ID.', 'public-post-preview' ),
		array( 'status' => 404 )
	);
}

/**
 * Fires the action for a revoked link.
 *
 * @internal
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 */
function fire_revoked( int $post_id, string $link_id ): void {
	/**
	 * Fires after a preview link was revoked.
	 *
	 * @since 4.0.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $link_id Link ID.
	 */
	do_action( 'ppp_link_revoked', $post_id, $link_id );
}
