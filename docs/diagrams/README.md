# Diagrams

[PR Lens](https://www.npmjs.com/package/@coldtea/pr-lens-renderer) diagrams of Tachyon R2 0.1.0, rendered from [graph.json](graph.json). [build-graph.mjs](build-graph.mjs) generates `graph.json`; edit that, not the JSON. The SVGs in [out/](out/) are canonical; the PNGs are static copies.

| View | Shows |
|---|---|
| `overview` | Components, the upload path into R2 and the serve path through the Worker |
| `offload-upload-view` | An image upload in offload mode (sync mode is the same without the last step) |
| `stream-upload-view` | An image upload in stream mode, written straight to R2 through `tachyon-r2://` |

## Regenerate

```sh
cd docs/diagrams
npm ci
node build-graph.mjs
rm -rf out && npm run render
for f in out/*.svg; do rsvg-convert -o "${f%.svg}.png" "$f"; done
```

`render.mjs` validates the graph and refuses to overwrite existing SVGs, so remove `out/` first. Update `SHA` in `build-graph.mjs` to the commit the diagrams describe.

## Source map

| Element | Evidence |
|---|---|
| Media upload → Sizes, `[]` when skipping thumbnails; virtual sizes | `includes/Sizes.php` (`skip`, `virtual_sizes`, `add`) |
| Media upload → Router `upload_dir`, prefilter, inactive after metadata | `includes/Router.php` (`prefilter`, `upload_dir`, `done`) |
| Writes to `tachyon-r2://`, put on close, get/put/head/list | `includes/StreamWrapper.php` (`stream_open`, `stream_flush`, `url_stat`, `dir_opendir`) |
| Metadata saved → Uploader, `put_file`, `_tachyon_r2`, shutdown delete (offload) | `includes/Uploader.php` (`on_metadata`, `sync`, `shutdown`) |
| Stream: "written via wrapper" counts as in R2 | `Uploader::sync` (`Keys::is_wrapper`, `StreamWrapper::wrote`) |
| Client · Signer, `PUT /bucket/key`, SigV4, region `auto` | `includes/Client.php`, `includes/Signer.php`; `tests/SignerTest.php` |
| R2 keys `uploads/sites/N/…` | `includes/Keys.php` (`root`, `for_path`, `wrapper_base`); `tests/KeysTest.php` |
| Urls → tachyon-plugin "force Tachyon URLs" | `includes/Urls.php` (`tachyon_disable_in_admin`, `tachyon_override_image_downsize`, `wp_get_attachment_url`) |
| Settings · WP-CLI → Uploader `sync / restore` | `includes/CLI.php`, `includes/Settings.php`, `includes/Config.php` |
| Mode badges (`sync · offload`, `stream`, `skip thumbnails`) | `Config::mode`, `Config::skip_thumbnails`; README "Upload methods" |
| tachyon-plugin → Browser → Worker → R2 | tachyon-plugin `tachyon_url()`; [Tachyon Edge](https://github.com/soderlind/tachyon-edge) |
| Both flows' order | Live run on 2026-11-05 with `wp media import` in each mode (see the 0.1.0 commit) |
