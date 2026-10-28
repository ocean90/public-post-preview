<?php
/**
 * Shows a draft to visitors with a valid preview link.
 */

namespace DS\PublicPostPreview\Frontend;

use DS\PublicPostPreview\Assets;
use DS\PublicPostPreview\Preview_Access;
use DS\PublicPostPreview\Preview_Policy;
use DS\PublicPostPreview\Token;
use WP_Post;
use WP_Query;

/**
 * Registers the hooks.
 */
function register(): void {
	add_filter( 'query_vars', __NAMESPACE__ . '\\add_query_var' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\show_public_preview' );
}

/**
 * Registers the `_ppp` query var.
 *
 * @param string[] $query_vars Query vars.
 * @return string[] Query vars.
 */
function add_query_var( $query_vars ) {
	$query_vars[] = '_ppp';

	return $query_vars;
}

/**
 * Prepares the main query of a preview request.
 *
 * Old nonce links are answered here, before the post is looked up, so the answer is the
 * same whether the post exists or not.
 *
 * @param WP_Query $query Query.
 */
function show_public_preview( $query ) {
	if ( ! $query->is_main_query() || ! $query->is_preview() || ! $query->is_singular() || ! $query->get( '_ppp' ) ) {
		return;
	}

	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
	}
	add_filter( 'wp_robots', 'wp_robots_no_robots' );

	$token = get_token();
	if ( Token::is_legacy( $token ) ) {
		$error = Preview_Access\check( 0, $token );
		wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => (int) $error->get_error_data()['status'] ) );
	}

	add_filter( 'posts_results', __NAMESPACE__ . '\\set_post_to_publish', 10, 2 );
}

/**
 * Shows the queried draft if the token unlocks it.
 *
 * A token that unlocks nothing leads to the regular 404 of the theme, the same as a post
 * that doesn't exist, so the response doesn't reveal whether a draft exists.
 *
 * @param WP_Post[] $posts Queried posts.
 * @return WP_Post[] Posts.
 */
function set_post_to_publish( $posts ) {
	remove_filter( 'posts_results', __NAMESPACE__ . '\\set_post_to_publish', 10 );

	if ( empty( $posts ) ) {
		return $posts;
	}

	$post_id = (int) $posts[0]->ID;

	$live = Preview_Policy\get_live_post( $post_id );
	if ( $live ) {
		wp_safe_redirect( get_permalink( $live ), 302 );
		exit;
	}

	$link = Preview_Access\check( $post_id, get_token() );
	if ( is_wp_error( $link ) ) {
		$status = (int) $link->get_error_data()['status'];
		if ( 404 === $status ) {
			return array();
		}

		wp_die( esc_html( $link->get_error_message() ), '', array( 'response' => (int) $status ) );
	}

	Preview_Access\record_visit( $post_id, $link );

	// Set post status to publish so that it's visible.
	$posts[0]->post_status = 'publish';

	// Allow Block Bindings sources to resolve for this post.
	add_filter(
		'map_meta_cap',
		static function( $caps, $cap, $user_id, $args ) use ( $post_id ) {
			if ( 'read_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post_id ) {
				return array( 'exist' );
			}
			return $caps;
		},
		10,
		4
	);

	// Disable comments and pings for this post.
	add_filter( 'comments_open', '__return_false' );
	add_filter( 'pings_open', '__return_false' );
	add_filter( 'wp_link_pages_link', __NAMESPACE__ . '\\filter_wp_link_pages_link', 10, 2 );

	/**
	 * Filters whether the public preview page should poll for updates to the post.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $poll    Whether to poll for updates. Default true.
	 * @param int  $post_id The post ID.
	 */
	if ( apply_filters( 'ppp_poll_for_updates', true, $post_id ) ) {
		add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_preview_updates_script' );
		add_action( 'wp_footer', __NAMESPACE__ . '\\print_preview_updates_container' );
	}

	/**
	 * Fires when a public preview is shown.
	 *
	 * @since 3.1.0
	 *
	 * @param int $post_id The post ID.
	 */
	do_action( 'ppp_show_public_preview', $post_id );

	return $posts;
}

/**
 * Keeps the preview token in the links to further pages of a paginated post.
 *
 * @param string $link        The page number HTML output.
 * @param int    $page_number Page number.
 * @return string The filtered HTML output.
 */
function filter_wp_link_pages_link( $link, $page_number ) {
	$post = get_post();
	if ( ! $post ) {
		return $link;
	}

	$preview_link = add_query_arg( 'page', $page_number, Preview_Policy\get_preview_url( $post, get_token() ) );

	return preg_replace( '~href=(["|\'])(.+?)\1~', 'href=$1' . esc_url( $preview_link ) . '$1', $link );
}

/**
 * Enqueues the script which polls for updates to the previewed post.
 */
function enqueue_preview_updates_script() {
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$version = Assets\enqueue_script( 'public-post-preview-updates', 'preview-updates' );

	$config = array(
		'endpoint' => add_query_arg(
			'_ppp',
			rawurlencode( get_token() ),
			rest_url( sprintf( 'public-post-preview/v1/posts/%d/preview-status', $post->ID ) )
		),
		'modified' => $post->post_modified,
		'strings'  => array(
			'updated'   => __( 'This post has been updated.', 'public-post-preview' ),
			'published' => __( 'This post has now been published.', 'public-post-preview' ),
			'reload'    => __( 'Reload', 'public-post-preview' ),
			'viewPost'  => __( 'View post', 'public-post-preview' ),
			'dismiss'   => __( 'Dismiss', 'public-post-preview' ),
		),
	);

	wp_localize_script( 'public-post-preview-updates', 'DSPublicPostPreviewUpdates', $config );

	wp_register_style( 'public-post-preview-updates', false, array(), $version );
	wp_enqueue_style( 'public-post-preview-updates' );
	wp_add_inline_style( 'public-post-preview-updates', get_preview_updates_css() );
}

/**
 * Prints the live region the update notice is rendered into.
 *
 * The region is present from page load so assistive technology announces
 * content added to it later.
 */
function print_preview_updates_container() {
	echo '<div id="public-post-preview-updates" class="public-post-preview-updates" role="status" aria-live="polite"></div>' . "\n";
}

/**
 * Returns the CSS for the update notice.
 *
 * @internal
 *
 * @return string The CSS.
 */
function get_preview_updates_css() {
	return <<<'CSS'
#public-post-preview-updates {
	position: fixed;
	inset-block-end: 16px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	z-index: 999999;
	max-width: calc(100% - 32px);
	font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	letter-spacing: normal;
}
#public-post-preview-updates:empty {
	display: none;
}
#public-post-preview-updates .public-post-preview-updates__toast {
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: #1d2327;
	color: #fff;
	border-radius: 4px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
#public-post-preview-updates .public-post-preview-updates__text {
	margin: 0;
	font: inherit;
	color: inherit;
	letter-spacing: inherit;
}
#public-post-preview-updates .public-post-preview-updates__action,
#public-post-preview-updates .public-post-preview-updates__dismiss {
	appearance: none;
	box-sizing: border-box;
	margin: 0;
	font: inherit;
	line-height: 1;
	letter-spacing: normal;
	text-transform: none;
	text-decoration: none;
	text-shadow: none;
	color: #fff;
	background: transparent;
	border: 1px solid #fff;
	border-radius: 3px;
	box-shadow: none;
	transition: none;
	cursor: pointer;
	white-space: nowrap;
}
#public-post-preview-updates .public-post-preview-updates__action {
	padding: 6px 12px;
}
#public-post-preview-updates .public-post-preview-updates__dismiss {
	padding: 4px 8px;
	border-color: transparent;
	font-size: 20px;
}
#public-post-preview-updates .public-post-preview-updates__action:hover,
#public-post-preview-updates .public-post-preview-updates__action:focus,
#public-post-preview-updates .public-post-preview-updates__dismiss:hover,
#public-post-preview-updates .public-post-preview-updates__dismiss:focus {
	color: #1d2327;
	background: #fff;
}
#public-post-preview-updates .public-post-preview-updates__action:focus-visible,
#public-post-preview-updates .public-post-preview-updates__dismiss:focus-visible {
	outline: 2px solid #fff;
	outline-offset: 2px;
}
@media (prefers-reduced-motion: no-preference) {
	#public-post-preview-updates .public-post-preview-updates__toast {
		animation: public-post-preview-updates-in 0.2s ease-out;
	}
	@keyframes public-post-preview-updates-in {
		from {
			opacity: 0;
			transform: translateY(8px);
		}
	}
}
CSS;
}

/**
 * Returns the token of the current request.
 *
 * @internal
 *
 * @return string Token, or an empty string if the parameter is missing or not a string.
 */
function get_token(): string {
	$token = get_query_var( '_ppp' );

	return is_string( $token ) ? $token : '';
}
