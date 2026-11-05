=== Tachyon R2 ===
Contributors: PerS
Tags: cloudflare, r2, tachyon, images, offload
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Store WordPress images in Cloudflare R2 for the Tachyon Edge Worker. Sync, offload or stream uploads.

== Description ==

tachyon-plugin only rewrites image URLs. Tachyon R2 uploads each image to the R2 bucket the Tachyon Edge Worker reads from, at the key the Worker expects.

* Three upload methods: sync (keep local files), offload (delete after upload), stream (never touch disk).
* Optional: skip thumbnail files and let Tachyon resize on request.
* Only images the Worker serves go to R2. Other files stay local.
* Deleting an attachment deletes its objects.
* WP-CLI: `wp tachyon-r2 status|sync|restore`.

See https://github.com/soderlind/tachyon-r2 for configuration.

== Changelog ==

= 0.1.0 =
* Initial release.
