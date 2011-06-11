=== Public Post Preview ===
Contributors: sivel, jdingman
Donate Link: http://sivel.net/donate
Tags: public-post-preview, public, post, preview, posts
Requires at least: 2.7
Tested up to: 2.9
Stable tag: 1.3

Enables you to give a link to anonymous users for public preview of a post before it is published.

== Description ==

= This plugin is no longer maintained. If you would like to become the maintainer of this plugin please contact the author. =

Enables you to give a link to anonymous users for public preview of a post before it is published.

Have you ever been writing a post with the help of someone who does not have access to your blog and needed to give them the ability to preview it before publishing? This plugin takes care of that by generating a URL with an expiring nonce that can be given out for public preview.

Props to [Jonathan Dingman](http://www.ginside.com/) for the idea behind this plugin, testing and feedback.

== Installation ==

1. Upload the `public-post-preview` folder to the `/wp-content/plugins/` directory or install directly through the plugin installer.
1. Activate the plugin through the 'Plugins' menu in WordPress or by using the link provided by the plugin installer

NOTE: See "Other Notes" for Upgrade and Usage Instructions as well as other pertinent topics.

== Screenshots ==

1. Edit Posts Page

== Upgrade ==

1. Use the plugin updater in WordPress or...
1. Delete the previous `public-post-preview` folder from the `/wp-content/plugins/` directory
1. Upload the new `public-post-preview` folder to the `/wp-content/plugins/` directory

== Usage ==

1. By default all posts in draft or pending review status will have public preview links that can be found diretly below the edit post box.
1. To disable public post preview for a specific post uncheck the public preview post box and save the post.

== Change Log ==

= 1.3 (2009-06-30): =
* Hook in earlier in the post selection process to fix PHP notices
* Add uninstall functionality to remove options from the options table

= 1.2 (2009-03-30): =
* Fix preview URL for scheduled posts on sites with a permalink other than default activated.

= 1.1 (2009-03-11): =
* Don't limit public previews to posts in draft or pending status.  Just exclude posts in publish status.

= 1.0 (2009-02-20): =
* Initial Public Release
