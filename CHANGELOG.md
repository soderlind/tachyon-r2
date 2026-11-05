# Changelog

## [0.1.0] - 2026-11-05

### Added

- Upload images to Cloudflare R2 over the S3 API (SigV4, no SDK).
- Upload methods: sync, offload and stream (`tachyon-r2://` stream wrapper).
- Skip thumbnails, with virtual sizes in attachment metadata.
- Tachyon URLs in the admin, REST and `wp_get_attachment_url()` when images may be remote.
- Delete R2 objects with the attachment.
- Settings page and `TACHYON_R2_*` constants.
- WP-CLI: `wp tachyon-r2 status`, `sync`, `restore`.
