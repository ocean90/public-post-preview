<?php
/**
 * Plugin Name: Public Post Preview
 * Version: 2.0
 * Description: Enables you to give a link to anonymous users for public preview of any post type before it is published.
 * Author: Dominik Schilling
 * Author URI: http://wphelper.de/
 * Plugin URI: http://wpgrafie.de/wp-plugins/public-post-preview/en/
 *
 * Text Domain: ds-public-post-preview
 * Domain Path: /lang
 *
 * License: GPLv2 or later
 *
 * Previously (2009-2011) maintained by Jonathan Dingman and Matt Martz.
 *
 *	Copyright (C) 2012 Dominik Schilling
 *
 *	This program is free software; you can redistribute it and/or
 *	modify it under the terms of the GNU General Public License
 *	as published by the Free Software Foundation; either version 2
 *	of the License, or (at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *
 *	You should have received a copy of the GNU General Public License
 *	along with this program; if not, write to the Free Software
 *	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
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
 * Used hooks:
 *  - pre_get_posts
 *  - add_meta_boxes
 *  - save_post
 *  - posts_results
 *  - admin_print_styles-post.php
 *  - admin_print_styles-post-new.php
 *
 *  Init at 'plugins_loaded' hook.
 *
 */
class DS_Public_Post_Preview {

	/**
	 * Hooks into 'pre_get_posts' to handle public preview, only nn-admin
	 * Hooks into 'add_meta_boxes' to register the meta box.
	 * Hooks into 'save_post' to handle the values of the meta box.
	 * Hooks into 'admin_print_styles-*' to print some inline css.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! is_admin() )
			add_filter( 'pre_get_posts', array( __CLASS__, 'show_public_preview' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );

		add_action( 'save_post', array( __CLASS__, 'register_public_preview' ), 20, 2 );

		add_action( 'admin_print_styles-post.php' , array( __CLASS__, 'print_inline_css' ) );
		add_action( 'admin_print_styles-post-new.php' , array( __CLASS__, 'print_inline_css' ) );
	}

	/**
	 * Registers for each post type a meta box.
	 * Only public post types are used.
	 *
	 * @since 2.0.0
	 */
	public static function register_meta_boxes() {
		$post_types = get_post_types(
			array(
				'public' => true
			)
		);

	    foreach ( $post_types as $post_type ) {
			add_meta_box(
					'public_post_preview',
					__( 'Public Post Preview', 'ds-public-post-preview' ),
					array( __CLASS__, 'public_post_preview_metabox_cb' ),
					$post_type,
					'normal',
					'high'
			);
		}
	}

	/**
	 * Callback method for the meta boxes.
	 * Checks current post status and shows the checkbox + link field if the post status
	 * is draft, pending or future.
	 *
	 * @since  2.0.0
	 *
	 * @param  object $post The current post object.
	 */
	public static function public_post_preview_metabox_cb( $post ) {
		if ( in_array( $post->post_status, array( 'draft', 'pending', 'future' ) ) ) :
			wp_nonce_field( 'public_post_preview', 'public_post_preview_wpnonce' );

		$preview_post_ids = self::get_preview_post_ids();
		?>
<p>
	<input type="checkbox"<?php checked( in_array( $post->ID, $preview_post_ids ) ); ?> name="public_post_preview" id="public-post-preview" value="1" />
	<label for="public-post-preview"><?php _e( 'Enable public preview', 'ds-public-post-preview' ); ?></label>

	<label id="public-post-preview-link">
		<input type="text" name="public_post_preview_link" class="regular-text disabled" value="<?php echo esc_attr( self::get_preview_link( $post->ID ) ); ?>" style="width:99%;" disabled="disabled" />
		<?php _e( '(Copy and share this link.)', 'ds-public-post-preview' ); ?>
	</label>
</p>
		<?php
		elseif ( in_array( $post->post_status, array( 'publish' ) ) ) :
		?>
<p><?php _e( 'This post is already public.', 'ds-public-post-preview' ); ?>
		<?php
		else :
		?>
<p><?php _e( 'The current post status is not supported. It needs to be draft, pending or future.', 'ds-public-post-preview' ); ?>
		<?php
		endif;
	}

	/**
	 * Returns the post ids which are registered for a public preview.
	 *
	 * @since  2.0.0
	 *
	 * @return array The post ids. (Empty array if no ids are registered.)
	 */
	public static function get_preview_post_ids() {
		return get_option( 'public_post_preview', array() );
	}

	/**
	 * Returns the public preview link.
	 *
	 * The link is the permalink with these parameters:
	 *  - preview, always true (query var for core)
	 *  - public, always true
	 *  - nonce, a custom nonce, see DS_Public_Post_Preview::create_nonce()
	 *
	 * @since  2.0.0
	 *
	 * @param  int    $post_id  The post id.
	 * @return string           The generated public preview link.
	 */
	private static function get_preview_link( $post_id ) {
		return add_query_arg(
			array(
				'preview' => true,
				'public'  => true,
				'nonce'   => self::create_nonce( 'public_post_preview_' . $post_id ),
			),
			get_permalink( $post_id )
		);
	}

	/**
	 * Prints some fancy inline CSS.
	 *
	 * It controls the visibility of the public preview link.
	 *
	 * @since 2.0.0
	 */
	public static function print_inline_css() {
		?>
<style>
	#public-post-preview-link {
		display: none;
	}

	input[type="checkbox"]:checked ~ #public-post-preview-link {
		display: block !important;
	}
</style>
		<?php
	}

	/**
	 * Registers a post for a public preview.
	 *
	 * Don't runs on an autosave and ignores post revisions.
	 *
	 * @since  2.0.0
	 *
	 * @param  int    $post_id The post id.
	 * @param  object $post    The post object.
	 * @return bool            Returns false on a failure, true on a success.
	 */
	public static function register_public_preview( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return false;

		if( wp_is_post_revision( $post_id ) )
			return false;

		if ( empty( $_POST['public_post_preview_wpnonce'] ) || ! wp_verify_nonce( $_POST['public_post_preview_wpnonce'], 'public_post_preview' ) )
			return false;

		$preview_post_ids = self::get_preview_post_ids();
		$preview_post_id  = $post->ID;

		if ( empty( $_POST['public_post_preview'] ) && in_array( $preview_post_id, $preview_post_ids ) )
			$preview_post_ids = array_diff( $preview_post_ids, (array) $preview_post_id );
		elseif (
				! empty( $_POST['public_post_preview'] ) &&
				! empty( $_POST['original_post_status'] ) &&
				'publish' != $_POST['original_post_status'] &&
				'publish' == $post->post_status &&
				in_array( $preview_post_id, $preview_post_ids )
			)
			$preview_post_ids = array_diff( $preview_post_ids, (array) $preview_post_id );
		elseif ( ! empty( $_POST['public_post_preview'] ) && ! in_array( $preview_post_id, $preview_post_ids ) )
			$preview_post_ids = array_merge( $preview_post_ids, (array) $preview_post_id );
		else
			return false; // Nothing changed.

		return update_option( 'public_post_preview', $preview_post_ids );
	}

	/**
	 * Show the post if it's a public preview.
	 *
	 * Only if it's the main query, a preview, a singular page and
	 * DS_Public_Post_Preview::public_preview_available() return true.
	 *
	 * @since  2.0.0
	 *
	 * @param  object $query The WP_Query object.
	 * @return object        The WP_Query object, unchanged.
	 */
	public static function show_public_preview( $query ) {
		if ( $query->is_main_query() && $query->is_preview() && $query->is_singular() ) {
			$post_id = get_query_var( 'page_id' ) ? get_query_var( 'page_id' ) : get_query_var( 'p' );

			if ( self::public_preview_available( $post_id ) )
				add_filter( 'posts_results', array( __CLASS__, 'set_post_to_publish' ) );
		}

		return $query;
	}

	/**
	 * Checks if a public preview is available and allowed.
	 * Verifies the nonce and if the post id is registered for a public preview.
	 *
	 * @since  2.0.0
	 *
	 * @param  int   $post_id The post id.
	 * @return bool           True if a public preview is allowed, false on a failure.
	 */
	private static function public_preview_available( $post_id ) {
		if ( empty( $post_id ) || empty( $_GET['public'] ) || empty( $_GET['nonce'] ) )
			return false;

		if( ! self::verify_nonce( $_GET['nonce'], 'public_post_preview_' . $post_id ) )
			return false;

		if ( ! in_array( $post_id, get_option( 'public_post_preview', array() ) ) )
			return false;

		return true;
	}

	/**
	 * Sets the post status of the first post to publish, so we don't have to do anything
	 * *too* hacky to get it to load the preview.
	 *
	 * @since 2.0.0
	 *
	 * @param array $posts The post to preview.
	 */
	public static function set_post_to_publish( $posts ) {
		$posts[0]->post_status = 'publish';

		return $posts;
	}

	/**
	 * Creates a random, one time use token. Without an UID.
	 *
	 * @see    wp_create_nonce()
	 *
	 * @since  1.0.0
	 *
	 * @param  string|int $action Scalar value to add context to the nonce.
	 * @return string             The one use form token
	 */
	private static function create_nonce( $action = -1 ) {
		$i = wp_nonce_tick();

		return substr( wp_hash( $i . $action, 'nonce' ), -12, 10 );
	}

	/**
	 * Verifies that correct nonce was used with time limit. Without an UID.
	 *
	 * @see    wp_verify_nonce()
	 *
	 * @since  1.0.0
	 *
	 * @param  string     $nonce  Nonce that was used in the form to verify
	 * @param  string|int $action Should give context to what is taking place and be the same when nonce was created.
	 * @return bool               Whether the nonce check passed or failed.
	 */
	private static function verify_nonce( $nonce, $action = -1 ) {
		$i = wp_nonce_tick();

		// Nonce generated 0-12 hours ago
		if ( substr( wp_hash( $i . $action, 'nonce' ), -12, 10 ) == $nonce )
			return 1;

		// Nonce generated 12-24 hours ago
		if ( substr( wp_hash( ( $i - 1 ) . $action, 'nonce' ), -12, 10 ) == $nonce )
			return 2;

		// Invalid nonce
		return false;
	}

	/**
	 * Delets the option 'public_post_preview' if the plugin will be uninstalled.
	 *
	 * @since 2.0.0
	 */
	public static function uninstall() {
		delete_option( 'public_post_preview' );
	}
}

// Go go go.
add_action( 'plugins_loaded', array( 'DS_Public_Post_Preview', 'init' ) );

// Register the uninstall function.
register_uninstall_hook( __FILE__, array( 'DS_Public_Post_Preview', 'uninstall' ) );
