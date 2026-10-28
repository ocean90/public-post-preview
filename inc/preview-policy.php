<?php
/**
 * Decides which posts may have preview links and how long new links live.
 */

namespace DS\PublicPostPreview\Preview_Policy;

use DS\PublicPostPreview\Link_Store;
use WP_Post;

/**
 * Returns the post types that support public previews.
 *
 * @return string[] Post type names.
 */
function get_previewable_post_types(): array {
	$post_types = array();
	foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
		if ( is_post_type_viewable( $post_type ) ) {
			$post_types[] = $post_type->name;
		}
	}

	/**
	 * Filters the post types that support public previews.
	 *
	 * @since 2.0.0
	 *
	 * @param string[] $post_types Post type names.
	 */
	return (array) apply_filters( 'ppp_post_types', $post_types );
}

/**
 * Returns the post statuses of a published post.
 *
 * @return string[] Post statuses.
 */
function get_published_statuses(): array {
	/**
	 * Filters the post statuses of a published post, which have no public preview.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $statuses Post statuses.
	 */
	return (array) apply_filters( 'ppp_published_statuses', array( 'publish', 'private' ) );
}

/**
 * Whether a post may have preview links.
 *
 * @param WP_Post $post Post object.
 * @return bool True if the post supports public previews in its current state.
 */
function is_previewable( WP_Post $post ): bool {
	if ( ! in_array( $post->post_type, get_previewable_post_types(), true ) ) {
		return false;
	}

	$excluded = array_merge( get_published_statuses(), array( 'auto-draft', 'trash' ) );

	return ! in_array( $post->post_status, $excluded, true );
}

/**
 * Returns a post that went live for everyone.
 *
 * Private posts don't count, so their permalink isn't revealed to anonymous visitors.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|null The published post, or null.
 */
function get_live_post( int $post_id ): ?WP_Post {
	$post = $post_id > 0 ? get_post( $post_id ) : null;
	if ( $post && in_array( $post->post_status, get_published_statuses(), true ) && is_post_publicly_viewable( $post ) ) {
		return $post;
	}

	return null;
}

/**
 * Returns how long a new link lives unless a date is chosen.
 *
 * @return int Seconds, never more than the maximum lifetime.
 */
function get_default_lifetime(): int {
	$hours    = (int) get_option( 'public_post_preview_expiration_time' ) ?: 48;
	$lifetime = (int) apply_filters_deprecated( 'ppp_nonce_life', array( $hours * HOUR_IN_SECONDS ), '4.0.0', 'ppp_default_link_lifetime' );

	/**
	 * Filters how long a new preview link lives unless a date is chosen.
	 *
	 * @since 4.0.0
	 *
	 * @param int $lifetime Seconds. Default from Settings > Reading, 48 hours.
	 */
	$lifetime = (int) apply_filters( 'ppp_default_link_lifetime', $lifetime );

	return min( $lifetime, Link_Store\get_max_lifetime() );
}

/**
 * Builds the preview URL for a token.
 *
 * @param WP_Post $post  Post object.
 * @param string  $token Value of the `_ppp` parameter.
 * @return string Preview URL.
 */
function get_preview_url( WP_Post $post, string $token ): string {
	if ( 'page' === $post->post_type ) {
		$args = array( 'page_id' => $post->ID );
	} elseif ( 'post' === $post->post_type ) {
		$args = array( 'p' => $post->ID );
	} else {
		$args = array(
			'p'         => $post->ID,
			'post_type' => $post->post_type,
		);
	}

	$args['preview'] = '1';
	$args['_ppp']    = $token;

	/**
	 * Filters the public preview URL.
	 *
	 * @since 2.0.0
	 *
	 * @param string  $link    Preview URL.
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	return (string) apply_filters( 'ppp_preview_link', add_query_arg( $args, home_url( '/' ) ), $post->ID, $post );
}
