<?php
/**
 * Shows the preview links in the publish box of the Classic Editor.
 */

namespace DS\PublicPostPreview\Classic_Editor;

use DS\PublicPostPreview\Assets;
use DS\PublicPostPreview\Link_Service;
use WP_Post;

/**
 * Script and style handle.
 */
const HANDLE = 'public-post-preview-classic-editor';

/**
 * Registers the hooks.
 */
function register(): void {
	add_action( 'post_submitbox_misc_actions', __NAMESPACE__ . '\\render' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
}

/**
 * Renders the section in the publish box, which the script fills.
 *
 * The UI needs the REST API, so without JavaScript there is nothing to show.
 *
 * @param WP_Post $post Post object.
 */
function render( $post ) {
	if ( $post instanceof WP_Post && ! is_wp_error( Link_Service\get_manageable_post( $post->ID ) ) ) {
		echo '<div class="misc-pub-section misc-pub-public-post-preview hide-if-no-js" id="public-post-preview"></div>';
	}
}

/**
 * Enqueues the script on the classic edit screen of a post that can have links.
 *
 * @param string $hook_suffix Admin page.
 */
function enqueue_assets( $hook_suffix ) {
	// New posts are auto-drafts, which can't have links yet.
	if ( 'post.php' !== $hook_suffix ) {
		return;
	}

	$screen = get_current_screen();
	$post   = get_post();
	if ( ! $screen || $screen->is_block_editor() || ! $post ) {
		return;
	}

	// Passed with the page, so the box shows the links without waiting for a request.
	$links = Link_Service\list_links( $post->ID );
	if ( is_wp_error( $links ) ) {
		return;
	}

	Assets\enqueue_editor_ui(
		HANDLE,
		'classic-editor',
		array(
			'postId' => $post->ID,
			'links'  => $links,
		)
	);
}
