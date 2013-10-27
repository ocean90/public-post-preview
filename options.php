<?php
function public_post_preview_show_settings_page() {
?>

<div class="wrap">
	<?php screen_icon(); ?>
	<h2>Public Post Preview</h2>
	<form method="post" action="options.php">
<?php 
	settings_fields('public_post_preview_group');
	do_settings_sections('public_post_preview_group');
?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row">Expiration hours</th>
					<td>
						<input id="public_post_preview_expiration_hours" class="regular-text" type="text" value="<?php echo get_option('public_post_preview_expiration_hours') ?>" name="public_post_preview_expiration_hours" />
						<p class="description">Expiration of the preview link in hours, default = 48</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>
</div>

<?php
}
?>