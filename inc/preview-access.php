<?php
/**
 * Checks preview tokens for the preview page and the preview status endpoint.
 */

namespace DS\PublicPostPreview\Preview_Access;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;
use DS\PublicPostPreview\Token;
use WP_Error;

/**
 * Minimum time between two writes of the last access, in seconds.
 */
const ACCESS_WRITE_INTERVAL = 15 * MINUTE_IN_SECONDS;

/**
 * Returns the link a token unlocks for a post.
 *
 * Unknown, revoked and forged tokens get the same error, so a response never reveals
 * whether a link ID exists. Old nonces are recognized by their format alone, before the
 * post is looked at.
 *
 * @param int    $post_id Post ID.
 * @param string $value   Value of the `_ppp` parameter.
 * @return Link|WP_Error The link, or an error with the HTTP status to send.
 */
function check( int $post_id, string $value ) {
	if ( Token::is_legacy( $value ) ) {
		return new WP_Error(
			'ppp_legacy_link',
			__( 'This link format is no longer valid. Please ask the person who sent you the link for a new one.', 'public-post-preview' ),
			array( 'status' => 410 )
		);
	}

	$token = Token::parse( $value );
	$post  = $post_id > 0 ? get_post( $post_id ) : null;
	$link  = $token && $post && Preview_Policy\is_previewable( $post ) ? Link_Store\find( $post->ID, $token->id ) : null;

	// Hash even without a link, so the response time doesn't tell which link IDs exist.
	$valid = $token && $token->verify( $post_id, $link ? $link->hash : wp_fast_hash( 'ppp-no-link' ) );
	if ( ! $link || ! $valid ) {
		return unavailable();
	}

	if ( $link->is_expired( Clock::now() ) ) {
		return new WP_Error(
			'ppp_link_expired',
			sprintf(
				/* translators: %s: Expiry date. */
				__( 'This link expired on %s.', 'public-post-preview' ),
				wp_date( get_option( 'date_format' ), $link->expires_at )
			),
			array( 'status' => 403 )
		);
	}

	return $link;
}

/**
 * Returns the error for a preview that isn't available.
 *
 * @return WP_Error Error with status 404.
 */
function unavailable(): WP_Error {
	return new WP_Error(
		'ppp_preview_unavailable',
		__( 'No public preview available!', 'public-post-preview' ),
		array( 'status' => 404 )
	);
}

/**
 * Records a visit through a link.
 *
 * Visits by people who can edit the post and through internal links don't count, so testing
 * a link before sharing it doesn't look like the recipient opened it.
 *
 * @param int  $post_id Post ID.
 * @param Link $link    Link that was used.
 */
function record_visit( int $post_id, Link $link ): void {
	if ( $link->internal || current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	/**
	 * Fires on every visit of a public preview by someone who can't edit the post.
	 *
	 * @since 4.0.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $link_id Link ID.
	 */
	do_action( 'ppp_preview_accessed', $post_id, $link->id );

	$now = Clock::now();
	if ( null !== $link->last_accessed_at && $now - $link->last_accessed_at < ACCESS_WRITE_INTERVAL ) {
		return;
	}

	Link_Store\update(
		$post_id,
		$link->id,
		array(
			'first_accessed_at' => $link->first_accessed_at ?? $now,
			'last_accessed_at'  => $now,
		)
	);
}
