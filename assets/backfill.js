/**
 * Tachyon R2: copy existing images to R2.
 *
 * Renders the progress panel, polls the job and drives batches while the tab is open.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const root = document.querySelector( '#tachyon-r2-backfill' );
	if ( ! root || ! window.tachyonR2Backfill ) {
		return;
	}

	const path = '/tachyon-r2/v1/backfill';
	const app = root.querySelector( '.tachyon-r2-app' );
	const summary = root.querySelector( '.tachyon-r2-summary' );
	const num = ( n ) => Number( n ).toLocaleString();
	let data = window.tachyonR2Backfill;
	let busy = false;
	let driving = false;

	app.innerHTML = `
		<style>#tachyon-r2-backfill [hidden] { display: none !important; }</style>
		<div class="tachyon-r2-progress" hidden>
			<progress max="100" value="0" style="width:100%;max-width:48rem;height:1.25rem"></progress>
			<p class="tachyon-r2-progress-text" aria-live="polite"></p>
		</div>
		<p class="tachyon-r2-delete" hidden>
			<label><input type="checkbox"> ${ __( 'Delete local copies after upload', 'tachyon-r2' ) }</label><br>
			<span class="description">${ __( 'Frees disk space. Tachyon serves the images from R2. Other files stay local. Restore with wp tachyon-r2 restore --all.', 'tachyon-r2' ) }</span>
		</p>
		<p class="tachyon-r2-actions">
			<button type="button" class="button button-primary" data-action="start"></button>
			<button type="button" class="button" data-action="pause">${ __( 'Pause', 'tachyon-r2' ) }</button>
			<button type="button" class="button button-primary" data-action="resume">${ __( 'Resume', 'tachyon-r2' ) }</button>
			<button type="button" class="button-link button-link-delete" data-action="cancel">${ __( 'Cancel', 'tachyon-r2' ) }</button>
		</p>
		<div class="notice notice-error inline tachyon-r2-error" hidden><p></p></div>
		<p class="description tachyon-r2-cron" hidden>${ __( 'WP-Cron is disabled. The copy continues while this page is open, or when a system cron runs wp-cron.php.', 'tachyon-r2' ) }</p>
		<details class="tachyon-r2-failures" hidden>
			<summary></summary>
			<ul></ul>
		</details>
		<p class="description">${ __( 'Large library? Run it from the command line instead:', 'tachyon-r2' ) } <code class="tachyon-r2-cli"></code></p>
	`;

	const $ = ( sel ) => app.querySelector( sel );
	const buttons = {};
	app.querySelectorAll( '[data-action]' ).forEach( ( b ) => {
		buttons[ b.dataset.action ] = b;
		b.addEventListener( 'click', () => act( b.dataset.action ) );
	} );
	const checkbox = $( '.tachyon-r2-delete input' );

	function render() {
		const { state, counts, failures } = data;
		const status = state.status;
		const active = status === 'running' || status === 'paused';

		summary.textContent = sprintf(
			/* translators: 1: images in R2, 2: total images, 3: images not in R2 */
			__( '%1$s of %2$s images are in R2. %3$s are not yet.', 'tachyon-r2' ),
			num( counts.in_r2 ),
			num( counts.images ),
			num( counts.pending )
		);

		$( '.tachyon-r2-progress' ).hidden = ! active && status !== 'done' && status !== 'cancelled';
		const pct = state.total > 0 ? Math.min( 100, Math.round( ( state.processed / state.total ) * 100 ) ) : 100;
		$( 'progress' ).value = pct;
		const labels = {
			running: __( 'Copying', 'tachyon-r2' ),
			paused: __( 'Paused', 'tachyon-r2' ),
			done: __( 'Done', 'tachyon-r2' ),
			cancelled: __( 'Cancelled', 'tachyon-r2' ),
		};
		$( '.tachyon-r2-progress-text' ).textContent = sprintf(
			/* translators: 1: status, 2: processed, 3: total, 4: files uploaded, 5: failed */
			__( '%1$s: %2$s of %3$s images, %4$s files uploaded, %5$s failed.', 'tachyon-r2' ),
			labels[ status ] || status,
			num( state.processed ),
			num( state.total ),
			num( state.uploaded ),
			num( state.failed )
		);

		const canStart = ! active && counts.pending > 0;
		buttons.start.hidden = ! canStart;
		buttons.start.textContent = counts.failed > 0 && counts.failed === counts.pending
			? __( 'Retry failed', 'tachyon-r2' )
			: sprintf(
				/* translators: %s: number of images */
				_n( 'Copy %s image to R2', 'Copy %s images to R2', counts.pending, 'tachyon-r2' ),
				num( counts.pending )
			);
		buttons.pause.hidden = status !== 'running';
		buttons.resume.hidden = status !== 'paused';
		buttons.cancel.hidden = ! active;
		Object.values( buttons ).forEach( ( b ) => ( b.disabled = busy ) );

		$( '.tachyon-r2-delete' ).hidden = ! data.can_delete_local || ! canStart;
		$( '.tachyon-r2-cron' ).hidden = ! ( data.cron_disabled && active );
		$( '.tachyon-r2-cli' ).textContent = data.cli;

		const details = $( '.tachyon-r2-failures' );
		details.hidden = counts.failed < 1;
		details.querySelector( 'summary' ).textContent = sprintf(
			/* translators: %s: number of images */
			_n( '%s image failed', '%s images failed', counts.failed, 'tachyon-r2' ),
			num( counts.failed )
		);
		details.querySelector( 'ul' ).replaceChildren(
			...failures.map( ( f ) => {
				const li = document.createElement( 'li' );
				const a = document.createElement( 'a' );
				a.href = f.url;
				a.textContent = f.title || '#' + f.id;
				li.append( a, ': ' + f.error );
				return li;
			} )
		);
	}

	function showError( err ) {
		const box = $( '.tachyon-r2-error' );
		box.hidden = ! err;
		box.querySelector( 'p' ).textContent = err ? err.message || String( err ) : '';
	}

	async function act( action ) {
		if ( action === 'cancel' && ! window.confirm( __( 'Stop copying? Images already copied stay in R2.', 'tachyon-r2' ) ) ) {
			return;
		}
		busy = true;
		render();
		try {
			data = await wp.apiFetch( {
				path,
				method: 'POST',
				data: { action, delete_local: checkbox.checked },
			} );
			showError( null );
		} catch ( err ) {
			showError( err );
		}
		busy = false;
		render();
		drive();
	}

	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

	// Process batches from this tab while the job runs; cron does the same in the background.
	async function drive() {
		if ( driving ) {
			return;
		}
		driving = true;
		while ( data.state.status === 'running' ) {
			const before = data.state.processed;
			try {
				data = await wp.apiFetch( { path, method: 'POST', data: { action: 'step' } } );
				showError( null );
			} catch ( err ) {
				showError( err );
				await sleep( 5000 );
			}
			render();
			if ( data.state.status === 'running' && data.state.processed === before ) {
				// Another request (cron) holds the lock; check back shortly.
				await sleep( 3000 );
				try {
					data = await wp.apiFetch( { path } );
				} catch ( err ) {
					showError( err );
				}
				render();
			}
		}
		driving = false;
	}

	render();
	drive();
} )();
