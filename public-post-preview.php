<?php
/**
 * Plugin Name: Public Post Preview
 * Version: 3.1.2
 * Description: Allow anonymous users to preview a post before it is published.
 * Author: Dominik Schilling
 * Author URI: https://dominikschilling.de/
 * Plugin URI: https://github.com/ocean90/public-post-preview
 * Text Domain: public-post-preview
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Previously (2009-2011) maintained by Jonathan Dingman and Matt Martz.
 *
 *  Copyright (C) 2012-2026 Dominik Schilling
 *
 *  This program is free software; you can redistribute it and/or
 *  modify it under the terms of the GNU General Public License
 *  as published by the Free Software Foundation; either version 2
 *  of the License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * Don't call this file directly.
 */
if ( ! class_exists( 'WP' ) ) {
	die();
}

require_once __DIR__ . '/inc/class-clock.php';
require_once __DIR__ . '/inc/class-token.php';
require_once __DIR__ . '/inc/class-link.php';
require_once __DIR__ . '/inc/link-store.php';
require_once __DIR__ . '/inc/preview-policy.php';
require_once __DIR__ . '/inc/preview-access.php';
require_once __DIR__ . '/inc/link-service.php';
require_once __DIR__ . '/inc/class-rest-links-controller.php';
require_once __DIR__ . '/inc/class-rest-preview-status-controller.php';

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Access;
use DS\PublicPostPreview\Preview_Policy;

/**
 * The class which controls the plugin.
 *
 * Inits at 'plugins_loaded' hook.
 */
class DS_Public_Post_Preview {

	/**
	 * Version of the stored data, raised when an upgrade routine is needed.
	 */
	private const DB_VERSION = 4;

	/**
	 * Lifetime of the link User Switching redirects to, in seconds.
	 */
	private const USER_SWITCHING_LINK_LIFETIME = 15 * MINUTE_IN_SECONDS;

	/**
	 * The link the current request was granted access with.
	 *
	 * @var string
	 */
	private static $request_token = '';

	/**
	 * Registers actions and filters.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_settings' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'init', 'DS\\PublicPostPreview\\Link_Store\\register_meta' );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'delete_links_on_status_change' ), 20, 3 );

		if ( ! is_admin() ) {
			add_action( 'pre_get_posts', array( __CLASS__, 'show_public_preview' ) );
			add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
			add_filter( 'user_switching_redirect_to', array( __CLASS__, 'user_switching_redirect_to' ), 10, 4 );
		} else {
			add_filter( 'display_post_states', array( __CLASS__, 'display_preview_state' ), 20, 2 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings_ui' ) );

			foreach ( Preview_Policy\get_previewable_post_types() as $post_type ) {
				add_filter( "views_edit-$post_type", array( __CLASS__, 'add_list_table_view' ) );
			}
			add_action( 'pre_get_posts', array( __CLASS__, 'filter_post_list_for_public_preview' ) );
		}
	}

	/**
	 * Registers the REST API routes.
	 *
	 * @since 4.0.0
	 */
	public static function register_rest_routes() {
		( new DS\PublicPostPreview\REST_Links_Controller() )->register_routes();
		( new DS\PublicPostPreview\REST_Preview_Status_Controller() )->register_routes();
	}

	/**
	 * Drops the data of previous versions.
	 *
	 * Links from before 4.0 are not migrated: they were derived from a nonce and can't be
	 * turned into hashed links.
	 *
	 * @since 4.0.0
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'public_post_preview_db_version' ) >= self::DB_VERSION ) {
			return;
		}

		delete_option( 'public_post_preview' );
		update_option( 'public_post_preview_db_version', self::DB_VERSION );
	}

	/**
	 * Registers the settings used by the plugin.
	 *
	 * @since 3.0.0
	 */
	public static function register_settings() {
		register_setting(
			'reading',
			'public_post_preview_expiration_time',
			array(
				'show_in_rest' => true,
				'type'         => 'integer',
				'description'  => __( 'Default expiration time in seconds.', 'public-post-preview' ),
				'default'      => 48,
			)
		);
	}

	/**
	 * Registers the settings UI.
	 *
	 * @since 3.0.0
	 */
	public static function register_settings_ui() {
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
	 * @since 2.4.0
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 * @return array Filtered array of post display states.
	 */
	public static function display_preview_state( $post_states, $post ) {
		$now   = Clock::now();
		$count = 0;
		foreach ( Link_Store\get_links( $post->ID ) as $link ) {
			if ( ! $link->is_expired( $now ) ) {
				++$count;
			}
		}

		if ( $count ) {
			$post_states['ppp_enabled'] = sprintf(
				/* translators: %s: Number of preview links. */
				_n( 'Public Preview (%s link)', 'Public Preview (%s links)', $count, 'public-post-preview' ),
				number_format_i18n( $count )
			);
		}

		return $post_states;
	}

	/**
	 * Adds a "Public Preview" view to the post list table.
	 *
	 * @since 3.0.0
	 *
	 * @param string[] $views An array of available list table views.
	 * @return string[] Filtered array of available list table views.
	 */
	public static function add_list_table_view( $views ) {
		$post_type = get_current_screen()->post_type;

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'meta_query'             => self::get_active_links_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed key, admin only.
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
			esc_url( add_query_arg( array( 'post_type' => $post_type, 'public_preview' => 1 ), 'edit.php' ) ),
			isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ? ' class="current"  aria-current="page"' : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table view toggle.
			__( 'Public Preview', 'public-post-preview' ),
			number_format_i18n( $query->found_posts )
		);

		return $views;
	}

	/**
	 * Filters the post list to show only posts with active preview links.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Query $query The WP_Query instance.
	 */
	public static function filter_post_list_for_public_preview( $query ) {
		if ( ! $query->is_admin || ! $query->is_main_query() ) {
			return;
		}

		if ( isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list table view toggle.
			$query->set( 'meta_query', self::get_active_links_meta_query() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed key, admin only.
		}
	}

	/**
	 * Returns the meta query for posts with at least one active visible link.
	 *
	 * @return array<int, array<string, mixed>> Meta query.
	 */
	private static function get_active_links_meta_query() {
		return array(
			array(
				'key'     => Link_Store\EXPIRY_META_KEY,
				'value'   => Clock::now(),
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
	}

	/**
	 * Filters the redirect location after a user switches to another account or switches off with the User Switching plugin.
	 *
	 * Switching off from the post editing screen leads to the public preview of the post,
	 * through an internal link that lives 15 minutes and never shows up in the UI.
	 *
	 * @since 2.10.0
	 *
	 * @param string       $redirect_to   The target redirect location, or an empty string if none is specified.
	 * @param string|null  $redirect_type The redirect type, see the `user_switching::REDIRECT_*` constants.
	 * @param WP_User|null $new_user      The user being switched to, or null if there is none.
	 * @param WP_User|null $old_user      The user being switched from, or null if there is none.
	 * @return string The target redirect location.
	 */
	public static function user_switching_redirect_to( $redirect_to, $redirect_type, $new_user, $old_user ) {
		$post_id = isset( $_GET['redirect_to_post'] ) ? (int) $_GET['redirect_to_post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- User Switching verifies its own nonce; edit_post is checked below.
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! $old_user || ! user_can( $old_user, 'edit_post', $post->ID ) || ! Preview_Policy\is_previewable( $post ) ) {
			return $redirect_to;
		}

		$created = Link_Store\create(
			$post->ID,
			array(
				'expires_at' => Clock::now() + self::USER_SWITCHING_LINK_LIFETIME,
				'created_by' => $old_user->ID,
				'internal'   => true,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $redirect_to;
		}

		return Preview_Policy\get_preview_url( $post, $created['token']->to_string() );
	}

	/**
	 * Deletes all links of a post that gets published or trashed.
	 *
	 * @since 4.0.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function delete_links_on_status_change( $new_status, $old_status, $post ) {
		$statuses   = Preview_Policy\get_published_statuses();
		$statuses[] = 'trash';

		if ( in_array( $new_status, $statuses, true ) ) {
			Link_Store\delete_all( $post->ID );
		}
	}

	/**
	 * Registers the new query var `_ppp`.
	 *
	 * @since 2.1.0
	 *
	 * @param  array $qv Existing list of query variables.
	 * @return array List of query variables.
	 */
	public static function add_query_var( $qv ) {
		$qv[] = '_ppp';

		return $qv;
	}

	/**
	 * Registers the filter to handle a public preview.
	 *
	 * Filter will be set if it's the main query, a preview, a singular page
	 * and the query var `_ppp` exists.
	 *
	 * @since 2.0.0
	 *
	 * @param object $query The WP_Query object.
	 */
	public static function show_public_preview( $query ) {
		if (
			$query->is_main_query() &&
			$query->is_preview() &&
			$query->is_singular() &&
			$query->get( '_ppp' )
		) {
			if ( ! headers_sent() ) {
				nocache_headers();
				header( 'X-Robots-Tag: noindex' );
			}
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			add_filter( 'posts_results', array( __CLASS__, 'set_post_to_publish' ), 10, 2 );
		}
	}

	/**
	 * Enqueues the script which polls for updates to the previewed post.
	 *
	 * @since 4.0.0
	 */
	public static function enqueue_preview_updates_script() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$script_assets = file_exists( __DIR__ . '/js/dist/preview-updates.asset.php' ) ?
			require __DIR__ . '/js/dist/preview-updates.asset.php' :
			array(
				'dependencies' => array(),
				'version'      => '',
			);
		wp_enqueue_script(
			'public-post-preview-updates',
			plugins_url( 'js/dist/preview-updates.js', __FILE__ ),
			$script_assets['dependencies'],
			$script_assets['version'],
			true
		);

		$config = array(
			'endpoint' => add_query_arg(
				'_ppp',
				rawurlencode( self::$request_token ),
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

		wp_register_style( 'public-post-preview-updates', false, array(), $script_assets['version'] );
		wp_enqueue_style( 'public-post-preview-updates' );
		wp_add_inline_style( 'public-post-preview-updates', self::get_preview_updates_css() );
	}

	/**
	 * Prints the live region the update notice is rendered into.
	 *
	 * The region is present from page load so assistive technology announces
	 * content added to it later.
	 *
	 * @since 4.0.0
	 */
	public static function print_preview_updates_container() {
		echo '<div id="public-post-preview-updates" class="public-post-preview-updates" role="status" aria-live="polite"></div>' . "\n";
	}

	/**
	 * Returns the CSS for the update notice.
	 *
	 * @since 4.0.0
	 *
	 * @return string The CSS.
	 */
	private static function get_preview_updates_css() {
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
	 * Filters the HTML output of individual page number links to use the
	 * preview link.
	 *
	 * @since 2.5.0
	 *
	 * @param string $link        The page number HTML output.
	 * @param int    $page_number Page number for paginated posts' page links.
	 * @return string The filtered HTML output.
	 */
	public static function filter_wp_link_pages_link( $link, $page_number ) {
		$post = get_post();
		if ( ! $post ) {
			return $link;
		}

		$preview_link = Preview_Policy\get_preview_url( $post, self::$request_token );
		$preview_link = add_query_arg( 'page', $page_number, $preview_link );

		return preg_replace( '~href=(["|\'])(.+?)\1~', 'href=$1' . esc_url( $preview_link ) . '$1', $link );
	}

	/**
	 * Sets the post status of the first post to publish, so we don't have to do anything
	 * *too* hacky to get it to load the preview.
	 *
	 * @since 2.0.0
	 *
	 * @param  array $posts The post to preview.
	 * @return array The post that is being previewed.
	 */
	public static function set_post_to_publish( $posts ) {
		// Remove the filter again, otherwise it will be applied to other queries too.
		remove_filter( 'posts_results', array( __CLASS__, 'set_post_to_publish' ), 10 );

		if ( empty( $posts ) ) {
			return $posts;
		}

		$post_id = (int) $posts[0]->ID;

		// If the post has gone live, redirect to it's proper permalink.
		self::maybe_redirect_to_published_post( $post_id );

		$token = (string) get_query_var( '_ppp' );
		$link  = Preview_Access\check( $post_id, $token );
		if ( is_wp_error( $link ) ) {
			wp_die( esc_html( $link->get_error_message() ), (int) $link->get_error_data()['status'] );
		}

		self::$request_token = $token;
		Preview_Access\record_visit( $post_id, $link );

		// Set post status to publish so that it's visible.
		$posts[0]->post_status = 'publish';

		// Allow Block Bindings sources to resolve for this post.
		add_filter(
			'map_meta_cap',
			static function( $caps, $cap, $user_id, $args ) use ( $post_id ) {
				if (
					'read_post' === $cap &&
					isset( $args[0] ) &&
					(int) $args[0] === $post_id
				) {
					return [ 'exist' ];
				}
				return $caps;
			},
			10,
			4
		);

		// Disable comments and pings for this post.
		add_filter( 'comments_open', '__return_false' );
		add_filter( 'pings_open', '__return_false' );
		add_filter( 'wp_link_pages_link', array( __CLASS__, 'filter_wp_link_pages_link' ), 10, 2 );

		/**
		 * Filters whether the public preview page should poll for updates to the post.
		 *
		 * @since 4.0.0
		 *
		 * @param bool $poll    Whether to poll for updates. Default true.
		 * @param int  $post_id The post ID.
		 */
		if ( apply_filters( 'ppp_poll_for_updates', true, $post_id ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_preview_updates_script' ) );
			add_action( 'wp_footer', array( __CLASS__, 'print_preview_updates_container' ) );
		}

		do_action( 'ppp_show_public_preview', $post_id );

		return $posts;
	}

	/**
	 * Redirects to post's proper permalink, if it has gone live.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id The post id.
	 * @return false False of post status is not a published status.
	 */
	private static function maybe_redirect_to_published_post( $post_id ) {
		if ( ! in_array( get_post_status( $post_id ), Preview_Policy\get_published_statuses(), true ) ) {
			return false;
		}

		wp_safe_redirect( get_permalink( $post_id ), 301 );
		exit;
	}

	/**
	 * Deletes the plugin's data when the plugin is uninstalled.
	 *
	 * @since 2.0.0
	 */
	public static function uninstall() {
		delete_option( 'public_post_preview' );
		delete_option( 'public_post_preview_db_version' );
		delete_post_meta_by_key( DS\PublicPostPreview\Link_Store\META_KEY );
		delete_post_meta_by_key( DS\PublicPostPreview\Link_Store\EXPIRY_META_KEY );
	}

	/**
	 * Performs actions on plugin activation.
	 *
	 * @since 2.9.4
	 */
	public static function activate() {
		register_uninstall_hook( __FILE__, array( 'DS_Public_Post_Preview', 'uninstall' ) );
	}
}

add_action( 'plugins_loaded', array( 'DS_Public_Post_Preview', 'init' ) );

register_activation_hook( __FILE__, array( 'DS_Public_Post_Preview', 'activate' ) );
