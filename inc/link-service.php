<?php
/**
 * Manages the links of a post on behalf of the current user.
 *
 * Shared by the REST API and the abilities, so both apply the same permissions and rules.
 */

namespace DS\PublicPostPreview\Link_Service;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;
use WP_Error;
use WP_Post;

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
 * Returns the visible links of a post, after removing expired ones.
 *
 * @param WP_Post $post Post object.
 * @return array<int, array<string, mixed>> Links.
 */
function list_links( WP_Post $post ): array {
	Link_Store\prune_expired( $post->ID );

	return array_map( __NAMESPACE__ . '\\prepare', Link_Store\get_links( $post->ID ) );
}

/**
 * Creates a link and returns it with its URL, the only time the URL is available.
 *
 * @param WP_Post              $post Post object.
 * @param array<string, mixed> $args {
 *     @type string $label      Optional label.
 *     @type int    $expires_at Optional expiry, Unix timestamp. Defaults to the default lifetime.
 * }
 * @return array<string, mixed>|WP_Error The link, or an error.
 */
function create_link( WP_Post $post, array $args ) {
	Link_Store\prune_expired( $post->ID );

	$created = Link_Store\create(
		$post->ID,
		array(
			'label'      => $args['label'] ?? '',
			'expires_at' => $args['expires_at'] ?? Clock::now() + Preview_Policy\get_default_lifetime(),
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
 * @param WP_Post              $post    Post object.
 * @param string               $link_id Link ID.
 * @param array<string, mixed> $changes Values for `label` and `expires_at` (Unix timestamp).
 * @return array<string, mixed>|WP_Error The changed link, or an error.
 */
function update_link( WP_Post $post, string $link_id, array $changes ) {
	$link = find_visible( $post, $link_id );
	if ( is_wp_error( $link ) ) {
		return $link;
	}

	$updated = Link_Store\update( $post->ID, $link->id, array_intersect_key( $changes, array_flip( array( 'label', 'expires_at' ) ) ) );
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
 * @param WP_Post $post    Post object.
 * @param string  $link_id Link ID.
 * @return array<string, mixed>|WP_Error The revoked link, or an error.
 */
function revoke_link( WP_Post $post, string $link_id ) {
	$link = find_visible( $post, $link_id );
	if ( is_wp_error( $link ) ) {
		return $link;
	}

	$deleted = Link_Store\delete( $post->ID, $link->id );
	if ( ! $deleted ) {
		return link_not_found();
	}

	fire_revoked( $post->ID, $deleted->id );

	return prepare( $deleted );
}

/**
 * Revokes all links of a post, internal links included.
 *
 * @param WP_Post $post Post object.
 * @return int Number of revoked visible links.
 */
function revoke_all( WP_Post $post ): int {
	$links = Link_Store\get_links( $post->ID );

	Link_Store\delete_all( $post->ID );

	foreach ( $links as $link ) {
		fire_revoked( $post->ID, $link->id );
	}

	return count( $links );
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
 * @param string $date Date, UTC unless it has an offset.
 * @return int|null Unix timestamp, or null if the date is invalid.
 */
function parse_date( string $date ): ?int {
	$timestamp = rest_parse_date( $date, true );

	return false === $timestamp ? null : (int) $timestamp;
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
 * Finds a link that is not internal.
 *
 * @internal
 *
 * @param WP_Post $post    Post object.
 * @param string  $link_id Link ID.
 * @return Link|WP_Error The link, or an error.
 */
function find_visible( WP_Post $post, string $link_id ) {
	$link = Link_Store\find( $post->ID, $link_id );

	return $link && ! $link->internal ? $link : link_not_found();
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
