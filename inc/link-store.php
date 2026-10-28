<?php
/**
 * Stores preview links as post meta, one row per link.
 */

namespace DS\PublicPostPreview\Link_Store;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link;
use DS\PublicPostPreview\Token;

/**
 * Meta key of a link row.
 */
const META_KEY = '_ppp_link';

/**
 * Meta key of the latest expiry of all visible links, for `meta_query`.
 */
const EXPIRY_META_KEY = '_ppp_links_expire';

/**
 * Registers the meta keys.
 */
function register_meta(): void {
	register_post_meta(
		'',
		META_KEY,
		array(
			'type'          => 'array',
			'single'        => false,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false',
		)
	);
	register_post_meta(
		'',
		EXPIRY_META_KEY,
		array(
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => '__return_false',
		)
	);
}

/**
 * Creates a link.
 *
 * @param int                  $post_id Post ID.
 * @param array<string, mixed> $args {
 *     @type int    $expires_at Expiry time, Unix timestamp. Required.
 *     @type string $label      Optional label.
 *     @type int    $created_by User ID. Defaults to the current user.
 *     @type bool   $internal   Whether the link is hidden from the UI.
 * }
 * @return array{link: Link, token: Token}|null The link and its token, or null on failure.
 */
function create( int $post_id, array $args ): ?array {
	$token = Token::generate();

	$link                    = new Link();
	$link->id                = $token->id;
	$link->hash              = $token->hash( $post_id );
	$link->hint              = substr( $token->secret, -4 );
	$link->label             = (string) ( $args['label'] ?? '' );
	$link->created_at        = Clock::now();
	$link->created_by        = (int) ( $args['created_by'] ?? get_current_user_id() );
	$link->expires_at        = (int) $args['expires_at'];
	$link->first_accessed_at = null;
	$link->last_accessed_at  = null;
	$link->internal          = ! empty( $args['internal'] );

	if ( ! add_post_meta( $post_id, META_KEY, $link->to_array() ) ) {
		return null;
	}

	refresh_expiry( $post_id );

	return array(
		'link'  => $link,
		'token' => $token,
	);
}

/**
 * Returns the links of a post, newest first.
 *
 * @param int  $post_id          Post ID.
 * @param bool $include_internal Whether to include internal links.
 * @return Link[] Links.
 */
function get_links( int $post_id, bool $include_internal = false ): array {
	$links = array();
	foreach ( get_post_meta( $post_id, META_KEY, false ) as $row ) {
		$link = Link::from_array( $row );
		if ( $link && ( $include_internal || ! $link->internal ) ) {
			$links[] = $link;
		}
	}

	usort(
		$links,
		static function ( Link $a, Link $b ) {
			return $b->created_at <=> $a->created_at ?: strcmp( $a->id, $b->id );
		}
	);

	return $links;
}

/**
 * Finds a link of a post by its ID, internal links included.
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return Link|null The link, or null if the post has no such link.
 */
function find( int $post_id, string $link_id ): ?Link {
	foreach ( get_links( $post_id, true ) as $link ) {
		if ( hash_equals( $link->id, $link_id ) ) {
			return $link;
		}
	}

	return null;
}

/**
 * Replaces a link, but only if it is still stored as given.
 *
 * Deleting by the old value and adding the new one, instead of `update_metadata()`, makes the
 * write conditional: `update_metadata()` adds a fresh row when none exists, which would bring
 * back a link that was revoked in the meantime.
 *
 * @param int  $post_id Post ID.
 * @param Link $old     The link as read.
 * @param Link $new     The changed link.
 * @return bool True if the link was replaced, false if it changed or was deleted in between.
 */
function update( int $post_id, Link $old, Link $new ): bool {
	if ( ! delete_post_meta( $post_id, META_KEY, $old->to_array() ) ) {
		return false;
	}

	$added = (bool) add_post_meta( $post_id, META_KEY, $new->to_array() );
	refresh_expiry( $post_id );

	return $added;
}

/**
 * Deletes a link.
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return Link|null The deleted link, or null if the post has no such link.
 */
function delete( int $post_id, string $link_id ): ?Link {
	$link = find( $post_id, $link_id );
	if ( ! $link || ! delete_post_meta( $post_id, META_KEY, $link->to_array() ) ) {
		return null;
	}

	refresh_expiry( $post_id );

	return $link;
}

/**
 * Deletes all links of a post, internal links included.
 *
 * @param int $post_id Post ID.
 * @return int Number of deleted links.
 */
function delete_all( int $post_id ): int {
	$count = count( get_links( $post_id, true ) );

	delete_post_meta( $post_id, META_KEY );
	delete_post_meta( $post_id, EXPIRY_META_KEY );

	return $count;
}

/**
 * Deletes expired links.
 *
 * @param int $post_id Post ID.
 * @return int Number of deleted links.
 */
function prune_expired( int $post_id ): int {
	$now   = Clock::now();
	$count = 0;
	foreach ( get_links( $post_id, true ) as $link ) {
		if ( $link->is_expired( $now ) && delete_post_meta( $post_id, META_KEY, $link->to_array() ) ) {
			++$count;
		}
	}

	if ( $count ) {
		refresh_expiry( $post_id );
	}

	return $count;
}

/**
 * Returns the latest expiry of all visible links.
 *
 * @param int $post_id Post ID.
 * @return int Unix timestamp, 0 without links.
 */
function get_latest_expiry( int $post_id ): int {
	return (int) get_post_meta( $post_id, EXPIRY_META_KEY, true );
}

/**
 * Recalculates the latest expiry of all visible links.
 *
 * @internal
 *
 * @param int $post_id Post ID.
 */
function refresh_expiry( int $post_id ): void {
	$latest = 0;
	foreach ( get_links( $post_id ) as $link ) {
		$latest = max( $latest, $link->expires_at );
	}

	if ( $latest ) {
		update_post_meta( $post_id, EXPIRY_META_KEY, $latest );
	} else {
		delete_post_meta( $post_id, EXPIRY_META_KEY );
	}
}
