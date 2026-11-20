<?php
/**
 * Stores preview links as post meta, one row per link, and owns their invariants.
 */

namespace DS\PublicPostPreview\Link_Store;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link;
use DS\PublicPostPreview\Token;
use WP_Error;

/**
 * Meta key of a link row.
 */
const META_KEY = '_ppp_link';

/**
 * Meta key of the latest expiry of all visible links, for `meta_query`.
 */
const EXPIRY_META_KEY = '_ppp_links_expire';

/**
 * Maximum length of a label in characters.
 */
const LABEL_MAX_LENGTH = 100;

/**
 * Default maximum number of active visible links per post.
 */
const MAX_LINKS = 20;

/**
 * Fields that `update()` may change.
 */
const UPDATABLE_FIELDS = array( 'label', 'expires_at', 'first_accessed_at', 'last_accessed_at' );

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
 * Returns the maximum lifetime of a link in seconds, counted from now.
 *
 * @return int Seconds.
 */
function get_max_lifetime(): int {
	/**
	 * Filters the maximum lifetime of a preview link.
	 *
	 * @since 4.0.0
	 *
	 * @param int $lifetime Maximum lifetime in seconds. Default one year.
	 */
	return (int) apply_filters( 'ppp_link_max_lifetime', YEAR_IN_SECONDS );
}

/**
 * Returns the maximum number of active links a post can have, internal links aside.
 *
 * @param int $post_id Post ID.
 * @return int Maximum number of links, or 0 for no limit.
 */
function get_max_links( int $post_id ): int {
	/**
	 * Filters the maximum number of active preview links a post can have.
	 *
	 * Expired links and internal links, like the one User Switching redirects to, don't count.
	 *
	 * @since 4.0.0
	 *
	 * @param int $max_links Maximum number of links. Default 20. A value below 1 removes the limit.
	 * @param int $post_id   Post ID.
	 */
	return max( 0, (int) apply_filters( 'ppp_max_links_per_post', MAX_LINKS, $post_id ) );
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
 * @return array{link: Link, token: Token}|WP_Error The link and its token, or an error.
 */
function create( int $post_id, array $args ) {
	$expires_at = (int) ( $args['expires_at'] ?? 0 );
	$error      = validate_expiry( $expires_at );
	if ( $error ) {
		return $error;
	}

	$internal = ! empty( $args['internal'] );
	if ( ! $internal ) {
		$error = validate_count( $post_id );
		if ( $error ) {
			return $error;
		}
	}

	do {
		$token = Token::generate();
	} while ( find_row( $post_id, $token->id ) );

	$link = Link::from_array(
		array(
			'id'         => $token->id,
			'hash'       => $token->hash( $post_id ),
			'hint'       => substr( $token->secret, -4 ),
			'label'      => sanitize_label( $args['label'] ?? '' ),
			'created_at' => Clock::now(),
			'created_by' => $args['created_by'] ?? get_current_user_id(),
			'expires_at' => $expires_at,
			'internal'   => $internal,
		)
	);

	if ( ! add_post_meta( $post_id, META_KEY, wp_slash( $link->to_array() ) ) ) {
		return new WP_Error( 'ppp_link_not_saved', __( 'The preview link could not be saved.', 'public-post-preview' ), array( 'status' => 500 ) );
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
	foreach ( get_rows( $post_id ) as $row ) {
		if ( $include_internal || ! $row['link']->internal ) {
			$links[] = $row['link'];
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
	$row = find_row( $post_id, $link_id );

	return $row ? $row['link'] : null;
}

/**
 * Changes fields of a link.
 *
 * @param int                  $post_id Post ID.
 * @param string               $link_id Link ID.
 * @param array<string, mixed> $changes Values for `label`, `expires_at`, `first_accessed_at`, `last_accessed_at`.
 * @return Link|WP_Error|null The link with its new values, an error for invalid values, or null if the link is gone or changed in between.
 */
function update( int $post_id, string $link_id, array $changes ) {
	$changes = array_intersect_key( $changes, array_flip( UPDATABLE_FIELDS ) );
	if ( isset( $changes['expires_at'] ) ) {
		$changes['expires_at'] = (int) $changes['expires_at'];
		$error                 = validate_expiry( $changes['expires_at'] );
		if ( $error ) {
			return $error;
		}
	}
	if ( isset( $changes['label'] ) ) {
		$changes['label'] = sanitize_label( $changes['label'] );
	}

	$row = find_row( $post_id, $link_id );
	if ( ! $row ) {
		return null;
	}

	$link = Link::from_array( array_merge( $row['link']->to_array(), $changes ) );

	// MySQL reports no affected row for an identical value, which the check below would take for a lost link.
	if ( $link->to_array() === $row['link']->to_array() ) {
		return $link;
	}

	/*
	 * One conditional UPDATE instead of the meta API: `update_metadata()` adds a fresh row
	 * when the post has none left, and delete plus add leaves a gap. Either could bring back
	 * a link that was revoked at the same moment.
	 */
	global $wpdb;
	$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-swap of one row, the meta cache is cleared below.
		$wpdb->postmeta,
		array( 'meta_value' => maybe_serialize( $link->to_array() ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Written by row, not queried.
		array(
			'post_id'    => $post_id,
			'meta_key'   => META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Written by row, not queried.
			'meta_value' => maybe_serialize( $row['raw'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Matches the row as read.
		)
	);
	wp_cache_delete( $post_id, 'post_meta' );

	if ( ! $updated ) {
		return null;
	}

	if ( $link->expires_at !== $row['link']->expires_at ) {
		refresh_expiry( $post_id );
	}

	return $link;
}

/**
 * Deletes a link.
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return Link|null The deleted link, or null if the post has no such link.
 */
function delete( int $post_id, string $link_id ): ?Link {
	$row = find_row( $post_id, $link_id );
	if ( ! $row || ! delete_post_meta( $post_id, META_KEY, wp_slash( $row['raw'] ) ) ) {
		return null;
	}

	refresh_expiry( $post_id );

	return $row['link'];
}

/**
 * Deletes all links of a post, internal links included.
 *
 * @param int $post_id Post ID.
 * @return Link[] Deleted links.
 */
function delete_all( int $post_id ): array {
	$links = array_column( get_rows( $post_id ), 'link' );
	if ( ! $links ) {
		return array();
	}

	delete_post_meta( $post_id, META_KEY );
	delete_post_meta( $post_id, EXPIRY_META_KEY );

	return $links;
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
	foreach ( get_rows( $post_id ) as $row ) {
		if ( $row['link']->is_expired( $now ) && delete_post_meta( $post_id, META_KEY, wp_slash( $row['raw'] ) ) ) {
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
 * Checks an expiry time against now and the maximum lifetime.
 *
 * @internal
 *
 * @param int $expires_at Expiry time, Unix timestamp.
 * @return WP_Error|null An error, or null if the time is valid.
 */
function validate_expiry( int $expires_at ): ?WP_Error {
	$now = Clock::now();
	if ( $expires_at > $now && $expires_at <= $now + get_max_lifetime() ) {
		return null;
	}

	return new WP_Error(
		'ppp_invalid_expiry',
		__( 'The expiry date must be in the future and within the maximum lifetime of a preview link.', 'public-post-preview' ),
		array( 'status' => 400 )
	);
}

/**
 * Checks that a post can take one more visible link.
 *
 * @internal
 *
 * @param int $post_id Post ID.
 * @return WP_Error|null An error, or null if the post is below its limit.
 */
function validate_count( int $post_id ): ?WP_Error {
	$max = get_max_links( $post_id );
	if ( ! $max || count_active( $post_id ) < $max ) {
		return null;
	}

	return new WP_Error(
		'ppp_too_many_links',
		sprintf(
			/* translators: %s: Maximum number of links. */
			__( 'A post can have at most %s active preview links. Revoke one to create a new one.', 'public-post-preview' ),
			number_format_i18n( $max )
		),
		array( 'status' => 400 )
	);
}

/**
 * Turns a label into plain text of limited length.
 *
 * @internal
 *
 * @param mixed $label Raw label.
 * @return string Label.
 */
function sanitize_label( $label ): string {
	return mb_substr( sanitize_text_field( (string) $label ), 0, LABEL_MAX_LENGTH );
}

/**
 * Returns the stored rows of a post with their parsed links, unsorted.
 *
 * The raw value is kept so writes match the row exactly as stored. Values passed to the meta
 * API get `wp_slash()` because it unslashes them, which would change labels with backslashes.
 *
 * @internal
 *
 * @param int $post_id Post ID.
 * @return array<int, array{raw: mixed, link: Link}> Rows.
 */
function get_rows( int $post_id ): array {
	$rows = array();
	foreach ( get_post_meta( $post_id, META_KEY, false ) as $raw ) {
		$link = Link::from_array( $raw );
		if ( $link ) {
			$rows[] = array(
				'raw'  => $raw,
				'link' => $link,
			);
		}
	}

	return $rows;
}

/**
 * Counts the visible links that haven't expired.
 *
 * @internal
 *
 * @param int $post_id Post ID.
 * @return int Number of links.
 */
function count_active( int $post_id ): int {
	$now   = Clock::now();
	$count = 0;
	foreach ( get_rows( $post_id ) as $row ) {
		if ( ! $row['link']->internal && ! $row['link']->is_expired( $now ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Finds the stored row of a link.
 *
 * @internal
 *
 * @param int    $post_id Post ID.
 * @param string $link_id Link ID.
 * @return array{raw: mixed, link: Link}|null The row, or null if the post has no such link.
 */
function find_row( int $post_id, string $link_id ): ?array {
	foreach ( get_post_meta( $post_id, META_KEY, false ) as $raw ) {
		if ( is_array( $raw ) && ( $raw['id'] ?? null ) === $link_id ) {
			$link = Link::from_array( $raw );

			return $link ? array(
				'raw'  => $raw,
				'link' => $link,
			) : null;
		}
	}

	return null;
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
	foreach ( get_rows( $post_id ) as $row ) {
		if ( ! $row['link']->internal ) {
			$latest = max( $latest, $row['link']->expires_at );
		}
	}

	if ( get_latest_expiry( $post_id ) === $latest ) {
		return;
	}

	if ( $latest ) {
		update_post_meta( $post_id, EXPIRY_META_KEY, $latest );
	} else {
		delete_post_meta( $post_id, EXPIRY_META_KEY );
	}
}
