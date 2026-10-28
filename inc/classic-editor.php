<?php
/**
 * Shows the preview links in the publish box of the Classic Editor.
 */

namespace DS\PublicPostPreview\Classic_Editor;

use DS\PublicPostPreview\Admin;
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
 * Returns the active links of a post, if the current user may manage them.
 *
 * @internal
 *
 * @param WP_Post $post Post object.
 * @return array<int, array<string, mixed>>|null Links, or null if the post can't have links.
 */
function get_links( WP_Post $post ): ?array {
	$links = Link_Service\list_links( $post->ID );

	return is_wp_error( $links ) ? null : $links;
}

/**
 * Renders the section in the publish box, which the script turns into the full UI.
 *
 * @param WP_Post $post Post object.
 */
function render( $post ) {
	$links = $post instanceof WP_Post ? get_links( $post ) : null;
	if ( null === $links ) {
		return;
	}

	$count = count( $links );
	?>
	<div class="misc-pub-section misc-pub-public-post-preview" id="public-post-preview">
		<?php esc_html_e( 'Public preview:', 'public-post-preview' ); ?>
		<strong>
			<?php
			echo esc_html(
				$count
					/* translators: %s: Number of preview links. */
					? sprintf( _n( '%s link', '%s links', $count, 'public-post-preview' ), number_format_i18n( $count ) )
					: __( 'Off', 'public-post-preview' )
			);
			?>
		</strong>
	</div>
	<?php
}

/**
 * Enqueues the script on the classic edit screen of a post that can have links.
 *
 * @param string $hook_suffix Admin page.
 */
function enqueue_assets( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}

	$screen = get_current_screen();
	$post   = get_post();
	if ( ! $screen || $screen->is_block_editor() || ! $post ) {
		return;
	}

	$links = get_links( $post );
	if ( null === $links ) {
		return;
	}

	$version = Assets\enqueue_script( HANDLE, 'classic-editor' );
	Assets\add_settings(
		HANDLE,
		array_merge(
			Admin\get_editor_settings(),
			array(
				'postId' => $post->ID,
				'links'  => $links,
			)
		)
	);
	Assets\enqueue_style( HANDLE, 'classic-editor', array(), $version );
}
