=== Public Post Preview ===
Contributors: ocean90
Tags: public, preview, posts, anonymous, drafts
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 5.6
Stable tag: 2.10.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allow anonymous users to preview a draft of a post before it is published.

== Description ==

Share a link to anonymous users to preview a draft of a post (or any other public post type) before it is published.

Have you ever been writing a post with the help of someone who does not have access to your site and needed to give them the ability to preview it before publishing? This plugin takes care of that by generating an URL with an expiring nonce that can be given out for public preview.

*Previously this plugin was maintained by [Matt Martz](http://profiles.wordpress.org/sivel/) and was an idea of [Jonathan Dingman](http://profiles.wordpress.org/jdingman/). Thanks to Hans Dinkelberg for his [photo](http://www.flickr.com/photos/uitdragerij/7516234430/).*

== Installation ==

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

== Screenshots ==

1. Edit Posts Page

== Usage ==
* To enable a public post preview check the box below the edit post box.
* The link will be displayed if the checkbox is checked, just copy and share the link with your friends.
* To disable a preview just uncheck the box.

== Frequently Asked Questions ==

**I can't find the option for preview links. Where is it?**

The checkbox is only available for non-published posts and once a post was saved as a draft.


**After some time the preview link returns the message "The link has been expired!". Why?**

The plugin generates an URL with an expiring nonce. By default a link "lives" 48 hours. After 48 hours the link is expired and you need to copy and share a new link which is automatically generated on the same place under the editor.


**48 hours are not enough to me. Can I extend the nonce time?**

Yes, of course. You can use the filter `ppp_nonce_life`. Example for 5 days:

`add_filter( 'ppp_nonce_life', 'my_nonce_life' );
function my_nonce_life() {
	return 5 * DAY_IN_SECONDS;
}`

Or use the [Public Post Preview Configurator](https://wordpress.org/plugins/public-post-preview-configurator/).

== Changelog ==

=  2.10.0 (2022-11-19): =
* Compatibility with WordPress 6.1.
* Integrate with [User Switching](https://wordpress.org/plugins/user-switching/): Direct the user to the public preview of a post when they switch off from the post editing screen. Props [@johnbillion](https://github.com/johnbillion).

= 2.9.3 (2021-03-12): =
* Compatibility with WordPress 5.7.
* Create a fresh preview URL when enabling public preview.
* Add check for possibly undefined PHP "superglobals". Props [@waviaei](https://github.com/waviaei).

For more see [CHANGELOG.md](https://github.com/ocean90/public-post-preview/blob/master/CHANGELOG.md).
