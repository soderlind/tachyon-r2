// Generates graph.json. Edit here, then run `node build-graph.mjs`.
import { writeFileSync } from 'node:fs';

const SHA = 'd2668761690b61acd53525905c2120d9cacd4430';
const node = (id, label, kind, lane, subtitle, summary, files = [], badges = []) =>
	({ id, label, kind, delta: 'unchanged', lane, files: files.map((path) => ({ path })), badges, subtitle, summary });
const edge = (from, to, kind, label, emphasis = 'normal') =>
	({ id: `${from}-${to}`, from, to, kind, delta: 'unchanged', label, emphasis, animated: emphasis === 'hero', files: [] });
let n = 0;
const msg = (from, to, label, kind = 'sync') =>
	({ id: `m${++n}`, from, to, label, kind, delta: 'unchanged', animated: true, files: [] });

const graph = {
	schemaVersion: '0.3.0',
	kind: 'graph',
	title: 'Tachyon R2 architecture',
	summary: 'Current state at 0.1.1: Tachyon R2 puts WordPress images in the R2 bucket that the Tachyon Edge Worker reads from, using sync, offload or stream uploads, and copies existing images with a background job.',
	lenses: ['architecture', 'data-flow'],
	provenance: {
		repo: { owner: 'soderlind', name: 'tachyon-r2', host: 'github.com' },
		base: { sha: SHA, ref: 'main' },
		head: { sha: SHA, ref: 'main' },
		generator: { name: 'illustrate-design', version: '1.0.0' },
	},
	lanes: [
		{ id: 'wordpress', label: 'WordPress', subtitle: 'core · tachyon-plugin', order: 0 },
		{ id: 'r2plugin', label: 'Tachyon R2', subtitle: 'includes/', order: 1 },
		{ id: 'cloudflare', label: 'Cloudflare', subtitle: 'R2 · Workers', order: 2 },
		{ id: 'visitors', label: 'Visitors', subtitle: 'front end, admin', order: 3 },
	],
	nodes: [
		node('core', 'Media upload', 'app', 'wordpress', 'wp-admin · REST · CLI', 'WordPress core upload: wp_handle_upload, sub-sizes via the image editor, wp_update_attachment_metadata.'),
		node('disk', 'Local uploads', 'datastore', 'wordpress', 'wp-content/uploads', 'Local files. Kept in sync mode, deleted after upload in offload mode, never written for images in stream mode. Non-images always stay here.'),
		node('cron', 'WP-Cron', 'app', 'wordpress', 'tachyon_r2_backfill', 'Runs a 20 s copy batch, then schedules the next one 5 s later while the job is running.'),
		node('tachyon', 'tachyon-plugin', 'app', 'wordpress', 'tachyon_url()', 'Rewrites image URLs to TACHYON_URL with resize arguments. Never uploads files.'),
		node('urls', 'Urls', 'module', 'r2plugin', 'Tachyon everywhere', 'When images may be remote: Tachyon on in admin and REST, and wp_get_attachment_url() returns a Tachyon URL for R2-only images.', ['includes/Urls.php']),
		node('sizes', 'Sizes', 'module', 'r2plugin', 'virtual sub-sizes', 'With skip thumbnails, stops core writing sub-size files and records the sizes in metadata so srcset and the editor still work.', ['includes/Sizes.php'], ['skip thumbnails']),
		node('router', 'Router', 'module', 'r2plugin', 'swaps upload_dir', 'While a supported image is uploaded in stream mode, swaps basedir and path to the tachyon-r2:// wrapper. baseurl is unchanged.', ['includes/Router.php'], ['stream']),
		node('uploader', 'Uploader', 'module', 'r2plugin', 'put · delete', 'On wp_update_attachment_metadata, uploads local image files; in offload mode deletes them at shutdown. Deletes R2 objects with the attachment.', ['includes/Uploader.php'], ['sync · offload']),
		node('wrapper', 'StreamWrapper', 'module', 'r2plugin', 'tachyon-r2:// paths', 'PHP stream wrapper: reads GET, writes PUT on close, stat via HEAD/LIST, scandir via LIST, unlink via DELETE.', ['includes/StreamWrapper.php'], ['stream']),
		node('backfill', 'Backfill', 'service', 'r2plugin', 'copy existing images', 'Copies images that lack _tachyon_r2 in time-boxed batches under an option lock. Start, pause, resume and cancel over REST; optionally deletes local copies.', ['includes/Backfill.php'], ['WP-Cron · REST']),
		node('client', 'Client · Signer', 'service', 'r2plugin', 'S3 API · SigV4', 'Path-style S3 client over the WordPress HTTP API, region auto, no SDK.', ['includes/Client.php', 'includes/Signer.php', 'includes/Keys.php']),
		node('cli', 'Settings · WP-CLI', 'ui', 'r2plugin', 'wp tachyon-r2', 'Settings for the site or the network (when network-activated), TACHYON_R2_* constants, and status/sync/restore commands.', ['includes/Settings.php', 'includes/CLI.php', 'includes/Config.php']),
		node('r2', 'R2 bucket', 'datastore', 'cloudflare', 'tachyon-uploads', 'Object keys mirror the Tachyon URL path, so the Worker needs no mapping.'),
		node('worker', 'Tachyon Edge Worker', 'external', 'cloudflare', 'resize on request', 'Reads the Original from R2 and serves the resized Variant.'),
		node('browser', 'Browser', 'ui', 'visitors', 'img src / srcset', 'Requests Tachyon URLs.'),
		node('panel', 'Copy to R2 panel', 'ui', 'visitors', 'settings · Media › Tachyon R2', 'Admin notice and the Existing images panel, on the settings page or, when network-activated, Media › Tachyon R2. While open, it steps the job over REST so it moves even without WP-Cron.', ['assets/backfill.js']),
	],
	edges: [
		edge('cli', 'uploader', 'call', 'sync / restore'),
		edge('core', 'sizes', 'call', 'which sub-sizes?'),
		edge('core', 'router', 'call', 'upload_dir'),
		edge('core', 'wrapper', 'data', 'write tachyon-r2://'),
		edge('core', 'disk', 'data', 'write files'),
		edge('core', 'uploader', 'call', 'metadata saved'),
		edge('panel', 'backfill', 'http', 'POST /tachyon-r2/v1/backfill'),
		edge('cron', 'backfill', 'call', 'step( 20 )'),
		edge('backfill', 'uploader', 'call', 'sync( id, force )'),
		edge('uploader', 'disk', 'data', 'read · delete (offload)'),
		edge('uploader', 'client', 'call', 'put_file · delete'),
		edge('wrapper', 'client', 'call', 'get · put · head · list'),
		edge('client', 'r2', 'http', 'PUT /bucket/key', 'hero'),
		edge('urls', 'tachyon', 'call', 'force Tachyon URLs'),
		edge('tachyon', 'browser', 'render', 'Tachyon URL in HTML'),
		edge('browser', 'worker', 'http', 'GET ?resize=…', 'hero'),
		edge('worker', 'r2', 'data', 'read Original', 'hero'),
	],
	flows: [
		{
			id: 'offload-upload',
			title: 'Upload in offload mode',
			summary: 'Core writes the image and sub-sizes locally; Tachyon R2 uploads them, then deletes the local copies. Sync mode is the same without the last step.',
			delta: 'unchanged',
			participants: ['core', 'sizes', 'disk', 'uploader', 'client', 'r2'].map((node) => ({ node })),
			messages: [
				msg('core', 'disk', 'move_uploaded_file photo.jpg'),
				msg('core', 'sizes', 'intermediate_image_sizes_advanced'),
				msg('sizes', 'core', 'all sizes ([] if skip thumbnails)', 'return'),
				msg('core', 'disk', 'write photo-300x225.jpg …'),
				msg('core', 'uploader', 'wp_update_attachment_metadata'),
				msg('uploader', 'client', 'put_file per local file'),
				msg('client', 'r2', 'PUT uploads/sites/N/…/photo.jpg (SigV4)'),
				msg('r2', 'client', '200', 'return'),
				msg('uploader', 'uploader', 'post meta _tachyon_r2 = 1', 'self'),
				msg('uploader', 'disk', 'shutdown: delete local copies (offload only)', 'async'),
			],
		},
		{
			id: 'stream-upload',
			title: 'Upload in stream mode',
			summary: 'The upload directory points at the tachyon-r2:// wrapper, so core writes the image and sub-sizes straight to R2.',
			delta: 'unchanged',
			participants: ['core', 'router', 'wrapper', 'client', 'r2', 'uploader'].map((node) => ({ node })),
			messages: [
				msg('core', 'router', 'wp_handle_upload_prefilter'),
				msg('router', 'router', 'supported image → active', 'self'),
				msg('core', 'router', 'upload_dir'),
				msg('router', 'core', 'basedir = tachyon-r2://uploads/sites/N', 'return'),
				msg('core', 'wrapper', 'move_uploaded_file → tachyon-r2://…/photo.jpg'),
				msg('wrapper', 'client', 'put on close'),
				msg('client', 'r2', 'PUT …/photo.jpg'),
				msg('core', 'wrapper', 'image editor: read original, save sub-sizes'),
				msg('wrapper', 'client', 'get original · put each size'),
				msg('client', 'r2', 'GET …/photo.jpg · PUT …/photo-300x225.jpg …'),
				msg('core', 'router', 'wp_generate_attachment_metadata → inactive'),
				msg('core', 'uploader', 'wp_update_attachment_metadata'),
				msg('uploader', 'uploader', 'written via wrapper → _tachyon_r2 = 1', 'self'),
			],
		},
		{
			id: 'copy-existing',
			title: 'Copy existing images to R2',
			summary: 'The admin starts the job from the panel. WP-Cron and the open panel both run batches; an option lock lets only one run at a time.',
			delta: 'unchanged',
			participants: ['panel', 'backfill', 'cron', 'uploader', 'client', 'r2'].map((node) => ({ node })),
			messages: [
				msg('panel', 'backfill', 'POST action=start (delete_local?)'),
				msg('backfill', 'backfill', 'status running, total = pending images', 'self'),
				msg('backfill', 'cron', 'wp_schedule_single_event', 'async'),
				msg('backfill', 'panel', 'state · counts', 'return'),
				msg('cron', 'backfill', 'tachyon_r2_backfill → step( 20 )'),
				msg('panel', 'backfill', 'POST action=step (while the panel is open)'),
				msg('backfill', 'backfill', 'take lock, next batch of pending IDs', 'self'),
				msg('backfill', 'uploader', 'Uploader::sync( id, force )'),
				msg('uploader', 'client', 'put_file per local file'),
				msg('client', 'r2', 'PUT …/photo.jpg (SigV4)'),
				msg('uploader', 'uploader', '_tachyon_r2 = 1', 'self'),
				msg('backfill', 'backfill', 'delete local copies (optional) · or _tachyon_r2_error', 'self'),
				msg('backfill', 'cron', 'reschedule in 5 s while running', 'async'),
			],
		},
	],
	views: [
		{ id: 'overview', title: 'Tachyon R2 architecture', lens: 'architecture', summary: 'Upload path into R2 and serve path through the Worker.', scope: { kind: 'all' }, defaultOpen: true, children: [] },
		{ id: 'offload-upload-view', title: 'Upload in offload mode', lens: 'data-flow', scope: { kind: 'selection', flows: ['offload-upload'] } },
		{ id: 'copy-existing-view', title: 'Copy existing images to R2', lens: 'data-flow', scope: { kind: 'selection', flows: ['copy-existing'] } },
		{ id: 'stream-upload-view', title: 'Upload in stream mode', lens: 'data-flow', scope: { kind: 'selection', flows: ['stream-upload'] } },
	],
	layout: { direction: 'right', laneOrder: ['wordpress', 'r2plugin', 'cloudflare', 'visitors'] },
};

writeFileSync(new URL('./graph.json', import.meta.url), JSON.stringify(graph, null, '\t') + '\n');
