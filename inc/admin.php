<?php
/**
 * Shows preview links in the post list and the setting for their default lifetime.
 */

namespace DS\PublicPostPreview\Admin;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;
use WP_Post;
use WP_Query;
use WP_Screen;

/**
 * Registers the hooks.
 */
function register(): void {
	add_filter( 'display_post_states', __NAMESPACE__ . '\\display_preview_state', 20, 2 );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings_ui' );
	add_action( 'current_screen', __NAMESPACE__ . '\\register_list_table_view' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\filter_post_list_for_public_preview' );
}

/**
 * Registers the settings UI.
 */
function register_settings_ui() {
	// The legacy filter still counts, so the field stays hidden for sites that set the lifetime in code.
	if ( has_filter( 'ppp_nonce_life' ) || has_filter( 'ppp_default_link_lifetime' ) ) {
		return;
	}

	add_settings_section(
		'public_post_preview',
		__( 'Public Post Preview', 'public-post-preview' ),
		'__return_false',
		'reading'
	);

	add_settings_field(
		'public_post_preview_expiration_time',
		__( 'Expiration Time', 'public-post-preview' ),
		static function() {
			$value = get_option( 'public_post_preview_expiration_time' );
			?>
			<input type="number" id="public-post-preview-expiration-time" name="public_post_preview_expiration_time" value="<?php echo esc_attr( $value ); ?>" class="small-text" step="1" min="1" /> <?php esc_html_e( 'hours', 'public-post-preview' ); ?>
			<p class="description"><?php esc_html_e( 'Default expiration time of a preview link in hours.', 'public-post-preview' ); ?></p>
			<?php
		},
		'reading',
		'public_post_preview',
		array(
			'label_for' => 'public-post-preview-expiration-time',
		)
	);
}

/**
 * Adds "Public Preview" with the number of active links to the post states in list tables.
 *
 * @param string[] $post_states Post states.
 * @param WP_Post  $post        Post object.
 * @return string[] Post states.
 */
function display_preview_state( $post_states, $post ) {
	$now = Clock::now();
	if ( Link_Store\get_latest_expiry( $post->ID ) <= $now ) {
		return $post_states;
	}

	$count = 0;
	foreach ( Link_Store\get_links( $post->ID ) as $link ) {
		if ( ! $link->is_expired( $now ) ) {
			++$count;
		}
	}

	$post_states['ppp_enabled'] = sprintf(
		/* translators: %s: Number of preview links. */
		_n( 'Public Preview (%s link)', 'Public Preview (%s links)', $count, 'public-post-preview' ),
		number_format_i18n( $count )
	);

	return $post_states;
}

/**
 * Adds the "Public Preview" view to the list table of the current post type.
 *
 * Registered on `current_screen` instead of `plugins_loaded`, so post types registered
 * on `init` are covered too.
 *
 * @param WP_Screen $screen Current screen.
 */
function register_list_table_view( $screen ) {
	if ( 'edit' === $screen->base && in_array( $screen->post_type, Preview_Policy\get_previewable_post_types(), true ) ) {
		add_filter( "views_{$screen->id}", __NAMESPACE__ . '\\add_list_table_view' );
	}
}

/**
 * Adds a "Public Preview" view to the post list table.
 *
 * @param string[] $views List table views.
 * @return string[] List table views.
 */
function add_list_table_view( $views ) {
	$post_type = get_current_screen()->post_type;

	$query = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'any',
			'meta_query'             => get_active_links_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin only, few posts have links.
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( ! $query->found_posts ) {
		return $views;
	}

	$views['public_preview'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
		esc_url(
			add_query_arg(
				array(
					'post_type'      => $post_type,
					'public_preview' => 1,
				),
				'edit.php'
			)
		),
		isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ? ' class="current"  aria-current="page"' : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table view toggle.
		__( 'Public Preview', 'public-post-preview' ),
		number_format_i18n( $query->found_posts )
	);

	return $views;
}

/**
 * Filters the post list to show only posts with active preview links.
 *
 * @param WP_Query $query Query.
 */
function filter_post_list_for_public_preview( $query ) {
	if ( ! $query->is_admin || ! $query->is_main_query() ) {
		return;
	}

	if ( isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table view toggle.
		$query->set( 'meta_query', get_active_links_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin only, few posts have links.
	}
}

/**
 * Returns the meta query for posts with at least one active visible link.
 *
 * @internal
 *
 * @return array<int, array<string, mixed>> Meta query.
 */
function get_active_links_meta_query(): array {
	return array(
		array(
			'key'     => Link_Store\EXPIRY_META_KEY,
			'value'   => Clock::now(),
			'compare' => '>',
			'type'    => 'NUMERIC',
		),
	);
}
