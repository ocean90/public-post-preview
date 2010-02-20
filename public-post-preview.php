<?php
/*
Plugin Name: Public Post Preview
Plugin URI: http://sivel.net/wordpress/
Description: Enables you to give a link to anonymous users for public preview of a post before it is published.
Author: Matt Martz</a> and <a href='http://wpvibe.com/'>Jonathan Dingman</a>
Version: 1.4
Author URI: http://sivel.net/
*/

class Public_Post_Preview {

	// Variable place holder for post ID for easy passing between functions
	var $id;

	// Plugin startup
	function Public_Post_Preview() {
		if ( ! is_admin() ) {
			add_action('init', array(&$this, 'show_preview'));
		} else {
			register_activation_hook(__FILE__, array(&$this, 'init'));
			add_action('admin_init', array(&$this, 'register_settings'));
			add_action('admin_menu', array(&$this, 'add_options_page')) ;
			add_action('admin_menu', array(&$this, 'meta_box'));
			add_action('save_post', array(&$this, 'save_post'));
			add_action('admin_footer-post.php', array(&$this, 'footer_scripts'));
			add_action('admin_footer-post-new.php', array(&$this, 'footer_scripts'));
			add_action('wp_ajax_pppgetlink', array(&$this, 'ajax_get_preview_link'));
		}
	}

	function footer_scripts() {
?>
<script type="text/javascript">
	jQuery("#getpreviewlink").click(function() {
		jQuery.get('<?php echo admin_url('admin-ajax.php'); ?>', 'action=pppgetlink&p=<?php echo $this->id; ?>&_wpnonce=<?php echo wp_create_nonce(); ?>', function(link) {
			if ( link != '' ) {
				jQuery('#previewlink').attr('href', link);
				jQuery('#previewlink').text(link);
			}
		});
	});
</script>
<?php
	}

	// Initialize plugin
	function init() {
		if ( ! get_option('public_post_preview') )
			add_option('public_post_preview', array());
		if ( ! get_option('ppp_opts') ) {
			$ppp_opts['nonce'] = 'true';
		}
	}

	// verify a nonce
	function verify_nonce($nonce, $action = -1) {
		$i = wp_nonce_tick();
		if ( substr(wp_hash($i . $action, 'nonce'), -12, 10) == $nonce )
			return 12;
		if ( substr(wp_hash(($i - 1) . $action, 'nonce'), -12, 10) == $nonce )
			return 24;
		return false;
	}

	// create a nonce
	function create_nonce($action = -1) {
		$i = wp_nonce_tick();
		return substr(wp_hash($i . $action, 'nonce'), -12, 10);
	}

	function ajax_get_preview_link() {
		if ( current_user_can('edit_posts') && wp_verify_nonce($_GET['_wpnonce']) ) {
			if ( ! isset( $_GET['p'] ) )
				die();
			$post = get_post( $_GET['p'] );
			$this->id = (int) $post->ID;
			$nonce = $this->create_nonce('public_post_preview_' . $this->id);

			$p = $post->post_type == 'page' ? 'page_id' : 'p';
			echo $this->get_preview_link($p, $nonce);
		}
		die();
	}

	function get_preview_link($p, $nonce) {
		return add_query_arg(array($p => $this->id, 'preview' => 'true', 'preview_id' => $this->id, 'public' => true, 'nonce' => $nonce), get_option('home') . '/');
	}

	// Content for meta box
	function preview_link_box($post) {
		$preview_posts = get_option('public_post_preview');
		//echo '<pre>'; var_dump($post); echo '</pre>';
		if ( ! in_array($post->post_status, array('publish')) ) {
			if ( $post->ID != 0 && $post->post_status != 'auto-draft' ) :
?>
			<p>
				<label for="public_preview_status" class="selectit">
					<input type="checkbox" name="public_preview_status" id="public_preview_status"<?php if ( !isset($preview_posts[$post->ID]) ) echo ' checked="checked"'; ?>/>
					Public Preview
				</label>
			</p>
<?php
			endif;
			if ( ! isset($preview_posts[$post->ID]) && $post->ID != 0 && $post->post_status != 'auto-draft' ) {
				$this->id = (int) $post->ID;
				$nonce = $this->create_nonce('public_post_preview_' . $this->id);

				$p = $post->post_type == 'page' ? 'page_id' : 'p';

				$url = $this->get_prevew_link($p, $nonce);

				echo "<p><a id='previewlink' href='$url'>$url</a></p>\n";
				echo "<a id='getpreviewlink' class='button' href='#'>Update Preview Link</a>";
			} else if ( $post->ID == 0 || $post->post_status == 'auto-draft' ) {
				echo "<p>Please save this post to get the preview url.</p>";
			}
		} else {
			echo '<p>This post is already public.  Public post preview not available.</p>';
		}
	}

	// Register meta box
	function meta_box() {
		add_meta_box('publicpostpreview', 'Public Post Preview', array(&$this, 'preview_link_box'), 'post', 'normal', 'high');
		add_meta_box('publicpostpreview', 'Public Post Preview', array(&$this, 'preview_link_box'), 'page', 'normal', 'high');
	}

	// Update options on post save
	function save_post($post) {
		$preview_posts = get_option('public_post_preview');
		$post_id = $_POST['post_ID'];
		if ( $post != $post_id )
			return;
		if ( (isset($_POST['public_preview_status']) && $_POST['public_preview_status'] == 'on') && ! in_array($_POST['post_status'], array('publish')) && isset($preview_posts[$post_id]) ) {
				unset($preview_posts[$post_id]);
		} elseif ( ! isset($_POST['public_preview_status']) && $_POST['original_post_status'] != 'publish' && in_array($_POST['post_status'], array('publish')) && ! isset($preview_posts[$post_id]) ) {
				$preview_posts[$post_id] = false;
		}
		update_option('public_post_preview', $preview_posts);
	}

	// Show the post preview
	function show_preview() {
		if ( ! is_admin() && isset($_GET['preview_id']) && isset($_GET['preview']) && isset($_GET['public']) && isset($_GET['nonce']) ) {
			$this->id = (int) $_GET['preview_id'];
			$preview_posts = get_option('public_post_preview');	

			if ( false == $this->verify_nonce($_GET['nonce'], 'public_post_preview_' . $this->id) || isset($preview_posts[$this->id]) )
				wp_die('You do not have permission to publicly preview this post.');

			add_filter('posts_results', array(&$this, 'fake_publish'));
		}
	}

	// Fake the post being published so we don't have to do anything *too* hacky to get it to load the preview
	function fake_publish($posts) {
		$posts[0]->post_status = 'publish';
		return $posts;
	}

	function register_settings() {
		register_setting('ppp_opts', 'ppp_opts');
	}

	function add_options_page () {
		if ( current_user_can ( 'manage_options' ) && function_exists ( 'add_options_page' ) ) {
			$this->hookname = add_options_page (
				__( 'Public Post Preview' , 'public-post-preview' ) ,
				__( 'Public Post Preview' , 'public-post-preview' ) ,
				'manage_options' ,
				'public-post-preview' ,
				array ( &$this , 'options_page' )
			);
		}
	}

	// Options page
	function options_page () {
		$options = get_option('ppp_opts');
		?>
		<div class="wrap">
			<h2><?php _e( 'Public Post Preview' , 'public-post-preview' ); ?></h2>
			<form action="options.php" method="post">
				<?php settings_fields('ppp_opts'); ?>
				<h3><?php _e( 'General Configuration' , 'public-post-preview' ); ?></h3>
				<p><?php _e( 'Disable funky time length?' , 'public-post-preview' ); ?></p>
				<select name="ppp_opts[nonce]">
					<option value="true"<?php selected ( 'true' , $options['nonce'] ); ?>><?php _e( 'true' , 'public-post-preview' ); ?></option>
					<option value="false"<?php selected ( 'false' , $options['nonce'] ); ?>><?php _e( 'false' , 'public-post-preview' ); ?></option>
				</select>
				<p>The current time length is a little funky.  It is based on the nonce functions that WordPress uses, but isn't really good for exact time calculation.  The current implementation allows the link to remain active for up to 24 hours.  This doesn't mean it will last 24 hours.  It could be 3 minutes.  So feel free to disable this.  Disabling reduces security a bit.</p>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php _e( 'Save Changes' , 'public-post-preview' ) ?>" />
				</p>
			</form>
		</div>
	<?php
	}
}

$Public_Post_Preview = new Public_Post_Preview();

?>
