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

[tachyon-plugin](https://github.com/humanmade/tachyon-plugin) only rewrites image URLs. It never uploads files. Tachyon R2 fills that gap: it puts each uploaded image in the R2 bucket the [Tachyon Edge](https://github.com/soderlind/tachyon-edge) Worker reads from, at the key the Worker expects.

* Three upload methods: sync (keep local files), offload (delete after upload), stream (never write to disk).
* Optional: skip thumbnail files and let Tachyon resize on request.
* Only images the Worker serves (gif, jpg, jpeg, png, webp, avif) go to R2. PDFs, video and other files stay local.
* Deleting an attachment deletes its objects in R2.
* Talks to R2 over the S3 API with an R2 API token (SigV4, no SDK).
* WP-CLI: `wp tachyon-r2 status|sync|restore`.

= Upload methods =

* **sync** (default): copies each image and its sizes to R2 and keeps local files. Doesn't need Tachyon. Safe to turn off at any time.
* **offload**: files are written locally, uploaded, then removed at the end of the request. Needs Tachyon.
* **stream**: uploads are written straight to R2 through a `tachyon-r2://` stream wrapper. Needs Tachyon.

If Tachyon isn't detected, offload and stream fall back to sync, so images never become unreachable.

**Skip thumbnails** stops WordPress from creating sub-size files. The sizes are still recorded in the attachment metadata, so `srcset`, `the_content` and the block editor work; Tachyon creates them on request.

When images may be missing locally (offload, stream or skip thumbnails), Tachyon R2 also turns on Tachyon in the admin, makes the block editor get Tachyon URLs, and returns Tachyon URLs from `wp_get_attachment_url()` for images that only exist in R2.

= Object keys =

Keys mirror the Tachyon URL path, so the Worker finds the object without any mapping:

* Single site: `uploads/2026/01/photo.jpg`
* Multisite blog 3: `uploads/sites/3/2026/01/photo.jpg`

The prefix (`uploads`) is the path of `TACHYON_URL`. If your Worker sets `TACHYON_KEY_PREFIX`, set the same prefix here.

= WP-CLI =

* `wp tachyon-r2 status` – settings and connection check.
* `wp tachyon-r2 sync --all [--dry-run]` – upload existing images. In offload or stream mode, removes local copies unless you pass `--keep-local`.
* `wp tachyon-r2 sync 12 13 [--keep-local]` – specific attachments.
* `wp tachyon-r2 restore --all` – download images back to disk, before you switch to sync mode or deactivate the plugin.

On multisite, run each command per site with `--url=`.

Source, diagrams and issues: https://github.com/soderlind/tachyon-r2

== Installation ==

Requirements:

* An R2 bucket and an R2 API token with *Object Read & Write* on that bucket.
* For offload, stream and skip thumbnails: tachyon-plugin active and `TACHYON_URL` defined.

1. Download `tachyon-r2.zip` from the [latest release](https://github.com/soderlind/tachyon-r2/releases) and upload it in **Plugins → Add New → Upload Plugin**.
2. Activate it. On multisite, the settings are network-wide (**Network Admin → Settings → Tachyon R2**).
3. Configure it in **Settings → Tachyon R2**, or with constants in `wp-config.php`. A constant overrides the setting and locks the field. Use constants for the secret, so it isn't stored in the database:

`
define( 'TACHYON_URL', 'https://tachyon-edge.example.workers.dev/uploads' );
define( 'TACHYON_R2_ACCOUNT_ID', '...' );
define( 'TACHYON_R2_ACCESS_KEY_ID', '...' );
define( 'TACHYON_R2_SECRET_ACCESS_KEY', '...' );
define( 'TACHYON_R2_BUCKET', 'tachyon-uploads' );
define( 'TACHYON_R2_MODE', 'offload' );        // sync | offload | stream
define( 'TACHYON_R2_SKIP_THUMBNAILS', true );  // optional
// define( 'TACHYON_R2_KEY_PREFIX', 'uploads' ); // default: path of TACHYON_URL
// define( 'TACHYON_R2_ENDPOINT', 'https://<account>.eu.r2.cloudflarestorage.com' ); // jurisdictions
`

4. Click **Check connection** on the settings page, or run `wp tachyon-r2 status`.

== Frequently Asked Questions ==

= Does uninstalling delete my images in R2? =

No. Uninstalling removes the settings only; objects in R2 are left alone. Deleting an attachment does delete its objects.

= Can I use `wp media regenerate`? =

It needs the original locally. Run `wp tachyon-r2 restore` first for offloaded or streamed images.

= What happens to URLs in post content if I switch back to sync? =

When images may be remote, the block editor inserts Tachyon URLs into post content. They keep working, but they don't revert if you go back to sync.

= Does image editing (crop, rotate) work? =

Yes. The original is downloaded to a temp file, edited, and the result is uploaded.

= Will other plugins find my files? =

Plugins that read files from disk (`get_attached_file()`), for example EXIF or optimisation plugins, won't find offloaded or streamed images.

= Is there a file size limit? =

Uploads use a single `PUT`, which R2 limits to 5 GB.

== Changelog ==

= 0.1.0 =
* Upload images to Cloudflare R2 over the S3 API (SigV4, no SDK).
* Upload methods: sync, offload and stream (`tachyon-r2://` stream wrapper).
* Skip thumbnails, with virtual sizes in attachment metadata.
* Tachyon URLs in the admin, REST and `wp_get_attachment_url()` when images may be remote.
* Delete R2 objects with the attachment.
* Settings page and `TACHYON_R2_*` constants.
* WP-CLI: `wp tachyon-r2 status`, `sync`, `restore`.
