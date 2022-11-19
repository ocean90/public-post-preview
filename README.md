# Public Post Preview

WordPress plugin to share a link to anonymous users to preview a draft of a post (or any other public post type) before it is published.

Have you ever been writing a post with the help of someone who does not have access to your blog and needed to give them the ability to preview it before publishing? This plugin takes care of that by generating an URL with an expiring nonce that can be given out for public preview.

<p align="center">
<img src="https://ps.w.org/public-post-preview/assets/screenshot-1.png?rev=1981242" alt="" width="400">
</p>

*This plugin was previously maintained by [Matt Martz](http://profiles.wordpress.org/sivel/) and was an idea of [Jonathan Dingman](http://profiles.wordpress.org/jdingman/). Thanks to Hans Dinkelberg for his [photo](http://www.flickr.com/photos/uitdragerij/7516234430/).*

## Installation

Note: There will be NO settings page.

For an automatic installation through WordPress:

1. Go to the 'Add New' plugins screen in your WordPress admin area
1. Search for 'Public Post Preview'
1. Click 'Install Now' and activate the plugin


For a manual installation via FTP:

1. Upload the `public-post-preview` directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' screen in your WordPress admin area


To upload the plugin through WordPress, instead of FTP:

1. Upload the downloaded zip file on the 'Add New' plugins screen (see the 'Upload' tab) in your WordPress admin area and activate.

## Usage
* To enable a public post preview check the box in the "Status & Visibility" section of the document settings. In the classic editor it's in the "Publish" meta box.
* The link will be displayed if the checkbox is checked, you can copy and share the link with your friends.
* To disable a preview uncheck the box again.

## Frequently Asked Questions

**I can't find the option for preview links. Where is it?**

The checkbox is only available for non-published posts and once a post was saved as a draft.


**After some time the preview link returns the message "The link has been expired!". Why?**

The plugin generates an URL with an expiring nonce. By default a link "lives" 48 hours. After 48 hours the link is expired and you need to copy and share a new link which is automatically generated on the same place under the editor.


**48 hours are not enough to me. Can I extend the nonce time?**

Yes, of course. You can use the filter `ppp_nonce_life`. Example for 5 days:

```
add_filter( 'ppp_nonce_life', 'my_nonce_life' );
function my_nonce_life() {
	return 5 * DAY_IN_SECONDS;
}
```

Or use the [Public Post Preview Configurator](https://wordpress.org/plugins/public-post-preview-configurator/).
