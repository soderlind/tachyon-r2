# Changelog

## [0.1.2] - 2026-11-07

### Added

- Read-only Abilities API abilities `tachyon-r2/get-status` and `tachyon-r2/get-backfill-status`. They are exposed in the Abilities REST API and, when the WordPress MCP Adapter is installed, as MCP tools. Both require the capability that manages the settings.

### Changed

- Requires WordPress 7.0.
- `wp tachyon-r2 status` shares its status logic with the ability.

### Documentation

- Lane-colored diagrams and a copy-existing view.
- FAQ on `TACHYON_URL`, the key prefix and local URLs.

## [0.1.1] - 2026-11-05

### Added

- Copy existing images to R2 from the admin. A notice says how many images aren't in R2 yet. The **Existing images** panel copies them in resumable WP-Cron batches, newest first, with progress, Pause/Resume/Cancel, a list of failed images and an opt-in to delete local copies (offload or stream mode only). With network activation, the panel is under each site's **Media → Tachyon R2**.
- REST endpoint `tachyon-r2/v1/backfill` and the `tachyon_r2_backfill_batch_size` filter.

### Changed

- Multisite: when the plugin is activated on a single site, its settings page is that site's **Settings → Tachyon R2** and the settings apply to that site only. Network activation keeps the network-wide page. Only network administrators can change the settings.
- Sites activated before this change keep using the network settings until their own are saved.
- README credits the diagram tooling.

## [0.1.0] - 2026-11-05

### Added

- Upload images to Cloudflare R2 over the S3 API (SigV4, no SDK).
- Upload methods: sync, offload and stream (`tachyon-r2://` stream wrapper).
- Skip thumbnails, with virtual sizes in attachment metadata.
- Tachyon URLs in the admin, REST and `wp_get_attachment_url()` when images may be remote.
- Delete R2 objects with the attachment.
- Settings page and `TACHYON_R2_*` constants.
- WP-CLI: `wp tachyon-r2 status`, `sync`, `restore`.
