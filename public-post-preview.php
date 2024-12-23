<?php
/**
 * Plugin Name: Public Post Preview
 * Version: 3.0.1
 * Description: Allow anonymous users to preview a post before it is published.
 * Author: Dominik Schilling
 * Author URI: https://dominikschilling.de/
 * Plugin URI: https://github.com/ocean90/public-post-preview
 * Text Domain: public-post-preview
 * Requires at least: 6.5
 * Tested up to: 6.7
 * Requires PHP: 8.0
 * License: GPLv2 or later
 *
 * Previously (2009-2011) maintained by Jonathan Dingman and Matt Martz.
 *
 *  Copyright (C) 2012-2024 Dominik Schilling
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

/**
 * The class which controls the plugin.
 *
 * Inits at 'plugins_loaded' hook.
 */
class DS_Public_Post_Preview {

	/**
	 * Registers actions and filters.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_settings' ) );

		add_action( 'transition_post_status', array( __CLASS__, 'unregister_public_preview_on_status_change' ), 20, 3 );
		add_action( 'post_updated', array( __CLASS__, 'unregister_public_preview_on_edit' ), 20, 2 );

		if ( ! is_admin() ) {
			add_action( 'pre_get_posts', array( __CLASS__, 'show_public_preview' ) );
			add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
			add_filter( 'user_switching_redirect_to', array( __CLASS__, 'user_switching_redirect_to' ), 10, 4 );
		} else {
			add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'post_submitbox_misc_actions' ) );
			add_action( 'save_post', array( __CLASS__, 'register_public_preview' ), 20, 2 );
			add_action( 'wp_ajax_public-post-preview', array( __CLASS__, 'ajax_register_public_preview' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
			add_filter( 'display_post_states', array( __CLASS__, 'display_preview_state' ), 20, 2 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings_ui' ) );

			foreach( self::get_post_types() as $post_type ) {
				add_filter( "views_edit-$post_type", array( __CLASS__, 'add_list_table_view' ) );
			}
			add_filter( 'pre_get_posts', array( __CLASS__, 'filter_post_list_for_public_preview' ) );
		}
	}

	/**
	 * Registers the settings used by the plugin.
	 *
	 * @since 3.0.0
	 */
	static function register_settings() {
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
	static function register_settings_ui() {
		if ( has_filter( 'ppp_nonce_life' ) ) {
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
				<input type="number" id="public-post-preview-expiration-time" name="public_post_preview_expiration_time" value="<?php echo esc_attr( $value ); ?>" class="small-text" step="1" min="1" /> <?php _e( 'hours', 'public-post-preview' ); ?>
				<p class="description"><?php _e( 'Default expiration time of a preview link in hours.', 'public-post-preview' ); ?></p>
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
	 * Registers the JavaScript file for post(-new).php.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook_suffix Unique page identifier.
	 */
	public static function enqueue_script( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( get_current_screen()->is_block_editor() ) {
			$script_assets_path = plugin_dir_path( __FILE__ ) . 'js/dist/gutenberg-integration.asset.php';
			$script_assets      = file_exists( $script_assets_path ) ?
				require $script_assets_path :
				array(
					'dependencies' => array(),
					'version'      => '',
				);
			wp_enqueue_script(
				'public-post-preview-gutenberg',
				plugins_url( 'js/dist/gutenberg-integration.js', __FILE__ ),
				$script_assets['dependencies'],
				$script_assets['version'],
				true
			);

			wp_set_script_translations( 'public-post-preview-gutenberg', 'public-post-preview' );

			$post            = get_post();
			$preview_enabled = self::is_public_preview_enabled( $post );
			wp_localize_script(
				'public-post-preview-gutenberg',
				'DSPublicPostPreviewData',
				array(
					'previewEnabled' => $preview_enabled,
					'previewUrl'     => $preview_enabled ? self::get_preview_link( $post ) : '',
					'nonce'          => wp_create_nonce( 'public-post-preview_' . $post->ID ),
				)
			);
		} else {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_script(
				'public-post-preview',
				plugins_url( "js/public-post-preview$suffix.js", __FILE__ ),
				array( 'jquery' ),
				'20221611',
				true
			);

			wp_localize_script(
				'public-post-preview',
				'DSPublicPostPreviewL10n',
				array(
					'enabled'  => __( 'Enabled!', 'public-post-preview' ),
					'disabled' => __( 'Disabled!', 'public-post-preview' ),
				)
			);
		}
	}

	/**
	 * Adds "Public Preview" to the list of display states used in the Posts list table.
	 *
	 * @since 2.4.0
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 * @return array Filtered array of post display states.
	 */
	public static function display_preview_state( $post_states, $post ) {
		if ( in_array( (int) $post->ID, self::get_preview_post_ids(), true ) ) {
			$post_states['ppp_enabled'] = sprintf(
				' %s&nbsp;<a href="%s" target="_blank" aria-label="%s"><span class="dashicons dashicons-format-links" aria-hidden="true"></span></a>',
				__( 'Public Preview', 'public-post-preview' ),
				esc_url( self::get_preview_link( $post ) ),
				esc_attr(
					sprintf(
						/* translators: %s: Post title */
						__( 'Open public preview of &#8220;%s&#8221;', 'public-post-preview' ), _draft_or_post_title( $post )
					)
				)
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
		$count = count( self::get_preview_post_ids() );
		if( ! $count ) {
			return $views;
		}

		$screen    = get_current_screen();
		$post_type = $screen->post_type;

		// Get the count of posts for this post type with public preview status.
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post__in'       => self::get_preview_post_ids(),
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( ! $query->post_count ) {
			return $views;
		}

		$views['public_preview'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( add_query_arg( array( 'post_type' => $post_type, 'public_preview' => 1 ), 'edit.php' ) ),
			isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ? ' class="current"  aria-current="page"' : '',
			__( 'Public Preview', 'public-post-preview' ),
			number_format_i18n( $query->post_count )
		);

		return $views;
	}

	/**
	 * Filters the post list to show only posts with public preview status.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Query $query The WP_Query instance.
	 */
	public static function filter_post_list_for_public_preview( $query ) {
		if ( ! $query->is_admin || ! $query->is_main_query()) {
			return;
		}

		if ( isset( $_GET['public_preview'] ) && '1' === $_GET['public_preview'] ) {
			$query->set( 'post__in', self::get_preview_post_ids() );
		}
	}

	/**
	 * Filters the redirect location after a user switches to another account or switches off with the User Switching plugin.
	 *
	 * This is used to direct the user to the public preview of a post when they switch off from the post editing screen.
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
		$post_id = isset( $_GET['redirect_to_post'] ) ? (int) $_GET['redirect_to_post'] : 0;

		if ( ! $post_id ) {
			return $redirect_to;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $redirect_to;
		}

		if ( ! $old_user || ! user_can( $old_user, 'edit_post', $post->ID ) ) {
			return $redirect_to;
		}

		if ( ! self::is_public_preview_enabled( $post ) ) {
			return $redirect_to;
		}

		return self::get_preview_link( $post );
	}

	/**
	 * Adds the checkbox to the submit meta box.
	 *
	 * @since 2.2.0
	 */
	public static function post_submitbox_misc_actions() {
		$post = get_post();

		// Ignore non-viewable post types.
		if ( ! in_array( $post->post_type, self::get_post_types(), true ) ) {
			return false;
		}

		// Do nothing for auto drafts.
		if ( 'auto-draft' === $post->post_status ) {
			return false;
		}

		// Post is already published.
		if ( in_array( $post->post_status, self::get_published_statuses(), true ) ) {
			return false;
		}

		?>
		<div class="misc-pub-section public-post-preview">
			<?php self::get_checkbox_html( $post ); ?>
		</div>
		<?php

	}

	/**
	 * Returns the viewable post types.
	 *
	 * @since 3.0.0
	 *
	 * @return string[] List with post types.
	 */
	private static function get_post_types() {
		$viewable_post_types = array();
		$post_types          = get_post_types( [], 'objects' );
		foreach ( $post_types as $post_type ) {
			if ( is_post_type_viewable( $post_type ) ) {
				$viewable_post_types[] = $post_type->name;
			}
		}

		return apply_filters( 'ppp_post_types', $viewable_post_types );
	}

	/**
	 * Returns post statuses which represent a published post.
	 *
	 * @since 2.4.0
	 *
	 * @return array List with post statuses.
	 */
	private static function get_published_statuses() {
		$published_statuses = array( 'publish', 'private' );

		return apply_filters( 'ppp_published_statuses', $published_statuses );
	}

	/**
	 * Prints the checkbox with the input field for the preview link.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post The post object.
	 */
	private static function get_checkbox_html( $post ) {
		if ( empty( $post ) ) {
			$post = get_post();
		}

		wp_nonce_field( 'public-post-preview_' . $post->ID, 'public_post_preview_wpnonce' );

		$enabled = self::is_public_preview_enabled( $post );
		?>
		<label><input type="checkbox"<?php checked( $enabled ); ?> name="public_post_preview" id="public-post-preview" value="1" />
		<?php _e( 'Enable public preview', 'public-post-preview' ); ?> <span id="public-post-preview-ajax"></span></label>

		<div id="public-post-preview-link" style="margin-top:6px"<?php echo $enabled ? '' : ' class="hidden"'; ?>>
			<label>
				<input type="text" name="public_post_preview_link" class="regular-text" value="<?php echo esc_attr( $enabled ? self::get_preview_link( $post ) : '' ); ?>" style="width:99%" readonly />
				<span class="description"><?php _e( 'Copy and share this preview URL.', 'public-post-preview' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Checks if a public preview is enabled for a post.
	 *
	 * @since 2.7.0
	 *
	 * @param WP_Post $post The post object.
	 * @return bool True if a public preview is enabled, false if not.
	 */
	private static function is_public_preview_enabled( $post ) {
		$preview_post_ids = self::get_preview_post_ids();
		return in_array( $post->ID, $preview_post_ids, true );
	}

	/**
	 * Returns the public preview link.
	 *
	 * The link is the home link with these parameters:
	 *  - preview, always true (query var for core)
	 *  - _ppp, a custom nonce, see DS_Public_Post_Preview::create_nonce()
	 *  - page_id or p or p and post_type to specify the post.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string The generated public preview link.
	 */
	public static function get_preview_link( $post ) {
		if ( 'page' === $post->post_type ) {
			$args = array(
				'page_id' => $post->ID,
			);
		} elseif ( 'post' === $post->post_type ) {
			$args = array(
				'p' => $post->ID,
			);
		} else {
			$args = array(
				'p'         => $post->ID,
				'post_type' => $post->post_type,
			);
		}

		$args['preview'] = true;
		$args['_ppp']    = self::create_nonce( 'public_post_preview_' . $post->ID );

		$link = add_query_arg( $args, home_url( '/' ) );

		return apply_filters( 'ppp_preview_link', $link, $post->ID, $post );
	}

	/**
	 * (Un)Registers a post for a public preview.
	 *
	 * Runs when a post is saved, ignores revisions and autosaves.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $post_id The post id.
	 * @param object $post    The post object.
	 * @return bool Returns true on a success, false on a failure.
	 */
	public static function register_public_preview( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( empty( $_POST['public_post_preview_wpnonce'] ) || ! wp_verify_nonce( $_POST['public_post_preview_wpnonce'], 'public-post-preview_' . $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$preview_post_ids = self::get_preview_post_ids();
		$preview_post_id  = (int) $post->ID;

		if ( empty( $_POST['public_post_preview'] ) && in_array( $preview_post_id, $preview_post_ids, true ) ) {
			$preview_post_ids = array_diff( $preview_post_ids, (array) $preview_post_id );
		} elseif (
			! empty( $_POST['public_post_preview'] ) &&
			! empty( $_POST['original_post_status'] ) &&
			! in_array( $_POST['original_post_status'], self::get_published_statuses(), true ) &&
			in_array( $post->post_status, self::get_published_statuses(), true )
		) {
			$preview_post_ids = array_diff( $preview_post_ids, (array) $preview_post_id );
		} elseif ( ! empty( $_POST['public_post_preview'] ) && ! in_array( $preview_post_id, $preview_post_ids, true ) ) {
			$preview_post_ids = array_merge( $preview_post_ids, (array) $preview_post_id );
		} else {
			return false; // Nothing has changed.
		}

		return self::set_preview_post_ids( $preview_post_ids );
	}

	/**
	 * Unregisters a post for public preview when a (scheduled) post gets published
	 * or trashed.
	 *
	 * @since 2.5.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return bool Returns true on a success, false on a failure.
	 */
	public static function unregister_public_preview_on_status_change( $new_status, $old_status, $post ) {
		$disallowed_status   = self::get_published_statuses();
		$disallowed_status[] = 'trash';

		if ( in_array( $new_status, $disallowed_status, true ) ) {
			return self::unregister_public_preview( $post->ID );
		}

		return false;
	}

	/**
	 * Unregisters a post for public preview when a post gets published or trashed.
	 *
	 * @since 2.5.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return bool Returns true on a success, false on a failure.
	 */
	public static function unregister_public_preview_on_edit( $post_id, $post ) {
		$disallowed_status   = self::get_published_statuses();
		$disallowed_status[] = 'trash';

		if ( in_array( $post->post_status, $disallowed_status, true ) ) {
			return self::unregister_public_preview( $post_id );
		}

		return false;
	}

	/**
	 * Unregisters a post for public preview.
	 *
	 * @since 2.5.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool Returns true on a success, false on a failure.
	 */
	private static function unregister_public_preview( $post_id ) {
		$post_id          = (int) $post_id;
		$preview_post_ids = self::get_preview_post_ids();

		if ( ! in_array( $post_id, $preview_post_ids, true ) ) {
			return false;
		}

		$preview_post_ids = array_diff( $preview_post_ids, (array) $post_id );

		return self::set_preview_post_ids( $preview_post_ids );
	}

	/**
	 * (Un)Registers a post for a public preview for an AJAX request.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_register_public_preview() {
		if ( ! isset( $_POST['post_ID'], $_POST['checked'] ) ) {
			wp_send_json_error( 'incomplete_data' );
		}

		$preview_post_id = (int) $_POST['post_ID'];
		$checked         = (string) $_POST['checked'];

		check_ajax_referer( 'public-post-preview_' . $preview_post_id );

		$post = get_post( $preview_post_id );

		if ( ! current_user_can( 'edit_post', $preview_post_id ) ) {
			wp_send_json_error( 'cannot_edit' );
		}

		if ( in_array( $post->post_status, self::get_published_statuses(), true ) ) {
			wp_send_json_error( 'invalid_post_status' );
		}

		$preview_post_ids = self::get_preview_post_ids();

		if ( 'false' === $checked && in_array( $preview_post_id, $preview_post_ids, true ) ) {
			$preview_post_ids = array_diff( $preview_post_ids, (array) $preview_post_id );
		} elseif ( 'true' === $checked && ! in_array( $preview_post_id, $preview_post_ids, true ) ) {
			$preview_post_ids = array_merge( $preview_post_ids, (array) $preview_post_id );
		} else {
			wp_send_json_error( 'unknown_status' );
		}

		$ret = self::set_preview_post_ids( $preview_post_ids );

		if ( ! $ret ) {
			wp_send_json_error( 'not_saved' );
		}

		$data = null;
		if ( 'true' === $checked ) {
			$data = array( 'preview_url' => self::get_preview_link( $post ) );
		}

		wp_send_json_success( $data );
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
	 * Checks if a public preview is available and allowed.
	 * Verifies the nonce and if the post id is registered for a public preview.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id The post id.
	 * @return bool True if a public preview is allowed, false on a failure.
	 */
	private static function is_public_preview_available( $post_id ) {
		if ( empty( $post_id ) ) {
			return false;
		}

		if ( ! self::verify_nonce( get_query_var( '_ppp' ), 'public_post_preview_' . $post_id ) ) {
			wp_die( __( 'This link has expired!', 'public-post-preview' ), 403 );
		}

		if ( ! in_array( $post_id, self::get_preview_post_ids(), true ) ) {
			wp_die( __( 'No public preview available!', 'public-post-preview' ), 404 );
		}

		return true;
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

		$preview_link = self::get_preview_link( $post );
		$preview_link = add_query_arg( 'page', $page_number, $preview_link );

		return preg_replace( '~href=(["|\'])(.+?)\1~', 'href=$1' . $preview_link . '$1', $link );
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

		if ( self::is_public_preview_available( $post_id ) ) {
			// Set post status to publish so that it's visible.
			$posts[0]->post_status = 'publish';

			// Disable comments and pings for this post.
			add_filter( 'comments_open', '__return_false' );
			add_filter( 'pings_open', '__return_false' );
			add_filter( 'wp_link_pages_link', array( __CLASS__, 'filter_wp_link_pages_link' ), 10, 2 );
		}

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
		if ( ! in_array( get_post_status( $post_id ), self::get_published_statuses(), true ) ) {
			return false;
		}

		wp_safe_redirect( get_permalink( $post_id ), 301 );
		exit;
	}

	/**
	 * Get the time-dependent variable for nonce creation.
	 *
	 * @see wp_nonce_tick()
	 *
	 * @since 2.1.0
	 *
	 * @return int The time-dependent variable.
	 */
	private static function nonce_tick() {
		$expiration = get_option( 'public_post_preview_expiration_time' ) ?: 48;
		$nonce_life = apply_filters( 'ppp_nonce_life', $expiration * HOUR_IN_SECONDS );

		return ceil( time() / ( $nonce_life / 2 ) );
	}

	/**
	 * Creates a random, one time use token. Without an UID.
	 *
	 * @see wp_create_nonce()
	 *
	 * @since 1.0.0
	 *
	 * @param  string|int $action Scalar value to add context to the nonce.
	 * @return string The one use form token.
	 */
	private static function create_nonce( $action = -1 ) {
		$i = self::nonce_tick();

		return substr( wp_hash( $i . $action, 'nonce' ), -12, 10 );
	}

	/**
	 * Verifies that correct nonce was used with time limit. Without an UID.
	 *
	 * @see wp_verify_nonce()
	 *
	 * @since 1.0.0
	 *
	 * @param string     $nonce  Nonce that was used in the form to verify.
	 * @param string|int $action Should give context to what is taking place and be the same when nonce was created.
	 * @return bool               Whether the nonce check passed or failed.
	 */
	private static function verify_nonce( $nonce, $action = -1 ) {
		$i = self::nonce_tick();

		// Nonce generated 0-12 hours ago.
		if ( substr( wp_hash( $i . $action, 'nonce' ), -12, 10 ) === $nonce ) {
			return 1;
		}

		// Nonce generated 12-24 hours ago.
		if ( substr( wp_hash( ( $i - 1 ) . $action, 'nonce' ), -12, 10 ) === $nonce ) {
			return 2;
		}

		// Invalid nonce.
		return false;
	}

	/**
	 * Returns the post IDs which are registered for a public preview.
	 *
	 * @since 2.0.0
	 *
	 * @return array The post IDs. (Empty array if no IDs are registered.)
	 */
	private static function get_preview_post_ids() {
		$post_ids = get_option( 'public_post_preview', array() );
		$post_ids = array_map( 'intval', $post_ids );

		return $post_ids;
	}

	/**
	 * Saves the post IDs which are registered for a public preview.
	 *
	 * @since 2.0.0
	 *
	 * @param array $post_ids List of post IDs that have a preview.
	 * @return bool Returns true on a success, false on a failure.
	 */
	private static function set_preview_post_ids( $post_ids = array() ) {
		$post_ids = array_map( 'absint', $post_ids );
		$post_ids = array_filter( $post_ids );
		$post_ids = array_unique( $post_ids );

		return update_option( 'public_post_preview', $post_ids );
	}

	/**
	 * Deletes the option 'public_post_preview' if the plugin will be uninstalled.
	 *
	 * @since 2.0.0
	 */
	public static function uninstall() {
		delete_option( 'public_post_preview' );
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
