( function () {
	'use strict';

	if ( typeof csiImport === 'undefined' ) {
		return;
	}

	const api = wp.apiFetch;
	api.use( api.createNonceMiddleware( csiImport.nonce ) );

	const root = csiImport.root.replace( /\/$/, '' );
	const chunkSize = 2 * 1024 * 1024;

	let activeJobId = null;
	let isRunning = false;
	let shouldRun = false;
	let isUploading = false;

	const els = {
		fileList: document.getElementById( 'csi-file-list' ),
		jobList: document.getElementById( 'csi-job-list' ),
		progressPanel: document.getElementById( 'csi-progress-panel' ),
		statusText: document.getElementById( 'csi-status-text' ),
		parseBar: document.getElementById( 'csi-parse-bar' ),
		execBar: document.getElementById( 'csi-exec-bar' ),
		parseMeta: document.getElementById( 'csi-parse-meta' ),
		execMeta: document.getElementById( 'csi-exec-meta' ),
		logOutput: document.getElementById( 'csi-log-output' ),
		startBtn: document.getElementById( 'csi-start' ),
		pauseBtn: document.getElementById( 'csi-pause' ),
		resumeBtn: document.getElementById( 'csi-resume' ),
		refreshBtn: document.getElementById( 'csi-refresh-files' ),
		uploadInput: document.getElementById( 'csi-upload-input' ),
		uploadBtn: document.getElementById( 'csi-upload-btn' ),
		uploadProgress: document.getElementById( 'csi-upload-progress' ),
		uploadBar: document.getElementById( 'csi-upload-bar' ),
		uploadMeta: document.getElementById( 'csi-upload-meta' ),
	};

	function request( path, options = {} ) {
		return api( {
			path: 'csi/v1' + path,
			...options,
		} );
	}

	function getOptions() {
		return {
			disable_fk_checks: document.getElementById( 'csi-disable-fk' ).checked,
			strip_definer: document.getElementById( 'csi-strip-definer' ).checked,
			stop_on_error: document.getElementById( 'csi-stop-on-error' ).checked,
			skip_drop: document.getElementById( 'csi-skip-drop' ).checked,
			skip_create: document.getElementById( 'csi-skip-create' ).checked,
		};
	}

	function updateJobUI( job ) {
		if ( ! job ) {
			return;
		}

		els.progressPanel.hidden = false;
		els.parseBar.style.width = job.parse_percent + '%';
		els.execBar.style.width = job.execute_percent + '%';
		els.parseMeta.textContent = job.byte_offset + ' / ' + job.file_size + ' bytes (' + job.parse_percent + '%)';
		els.execMeta.textContent =
			job.executed_count + ' executed, ' +
			job.failed_count + ' failed, ' +
			job.skipped_count + ' skipped, ' +
			job.pending_count + ' pending';

		const status = job.status;
		els.startBtn.hidden = true;
		els.pauseBtn.hidden = true;
		els.resumeBtn.hidden = true;

		if ( status === 'paused' ) {
			els.statusText.textContent = csiImport.i18n.paused;
			els.resumeBtn.hidden = false;
		} else if ( status === 'completed' ) {
			els.statusText.textContent = csiImport.i18n.completed;
		} else if ( status === 'failed' ) {
			els.statusText.textContent = csiImport.i18n.failed;
			els.resumeBtn.hidden = false;
		} else if ( [ 'pending', 'parsing' ].includes( status ) ) {
			els.statusText.textContent = csiImport.i18n.parsing;
			els.pauseBtn.hidden = false;
		} else if ( [ 'ready', 'running' ].includes( status ) ) {
			els.statusText.textContent = csiImport.i18n.executing;
			els.pauseBtn.hidden = false;
		} else {
			els.statusText.textContent = status;
		}
	}

	function renderFiles( files ) {
		if ( ! files.length ) {
			els.fileList.innerHTML = '<p class="description">' + csiImport.i18n.noFiles + '</p>';
			return;
		}

		const rows = files.map( function ( file ) {
			return '<tr>' +
				'<td>' + escapeHtml( file.name ) + '</td>' +
				'<td>' + escapeHtml( file.size_human ) + '</td>' +
				'<td><button type="button" class="button button-primary csi-import-file" data-file="' + escapeAttr( file.name ) + '">' + csiImport.i18n.import + '</button></td>' +
				'</tr>';
		} ).join( '' );

		els.fileList.innerHTML =
			'<table><thead><tr><th>File</th><th>Size</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>';

		els.fileList.querySelectorAll( '.csi-import-file' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				startImport( button.getAttribute( 'data-file' ) );
			} );
		} );
	}

	function renderJobs( jobs ) {
		if ( ! jobs.length ) {
			els.jobList.innerHTML = '<p class="description">No import jobs yet.</p>';
			return;
		}

		const rows = jobs.map( function ( job ) {
			return '<tr>' +
				'<td>#' + job.id + ' ' + escapeHtml( job.file_name ) + '</td>' +
				'<td>' + escapeHtml( job.status ) + '</td>' +
				'<td>' + job.execute_percent + '%</td>' +
				'<td><button type="button" class="button csi-open-job" data-job="' + job.id + '">View</button></td>' +
				'</tr>';
		} ).join( '' );

		els.jobList.innerHTML =
			'<table><thead><tr><th>Job</th><th>Status</th><th>Progress</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>';

		els.jobList.querySelectorAll( '.csi-open-job' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				openJob( parseInt( button.getAttribute( 'data-job' ), 10 ) );
			} );
		} );
	}

	function renderLog( data ) {
		const lines = ( data.entries || [] ).slice().reverse().map( function ( entry ) {
			const cls = entry.level === 'error' ? 'error' : ( entry.level === 'warn' ? 'warn' : '' );
			return '<div class="csi-log-line ' + cls + '">[' + escapeHtml( entry.level ) + '] ' + escapeHtml( entry.message ) + '</div>';
		} ).join( '' );

		els.logOutput.innerHTML = lines || 'No log entries yet.';
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function escapeAttr( value ) {
		return escapeHtml( value ).replace( /'/g, '&#39;' );
	}

	async function loadFiles() {
		const data = await request( '/files' );
		renderFiles( data.files || [] );
		return data;
	}

	function updateUploadUI( percent, message ) {
		els.uploadProgress.hidden = false;
		els.uploadBar.style.width = percent + '%';
		els.uploadMeta.textContent = message;
	}

	async function uploadChunk( formData ) {
		const response = await fetch( root + '/files/upload', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': csiImport.nonce,
			},
			body: formData,
		} );

		const data = await response.json();

		if ( ! response.ok ) {
			const message = data && data.message ? data.message : csiImport.i18n.uploadFailed;
			throw new Error( message );
		}

		return data;
	}

	async function uploadSqlFile( file ) {
		if ( ! file || ! /\.sql$/i.test( file.name ) ) {
			window.alert( csiImport.i18n.invalidFile );
			return;
		}

		if ( isUploading ) {
			return;
		}

		isUploading = true;
		els.uploadBtn.disabled = true;
		els.uploadInput.disabled = true;
		updateUploadUI( 0, csiImport.i18n.uploading + ' ' + file.name );

		const totalChunks = Math.max( 1, Math.ceil( file.size / chunkSize ) );
		let uploadId = '';

		try {
			for ( let index = 0; index < totalChunks; index++ ) {
				const start = index * chunkSize;
				const end = Math.min( start + chunkSize, file.size );
				const blob = file.slice( start, end );
				const formData = new FormData();

				formData.append( 'filename', file.name );
				formData.append( 'file_size', String( file.size ) );
				formData.append( 'chunk_index', String( index ) );
				formData.append( 'total_chunks', String( totalChunks ) );
				formData.append( 'chunk', blob, 'chunk.bin' );

				if ( uploadId ) {
					formData.append( 'upload_id', uploadId );
				}

				const result = await uploadChunk( formData );
				uploadId = result.upload_id;

				const percent = result.progress_percent || Math.round( ( ( index + 1 ) / totalChunks ) * 100 );
				updateUploadUI(
					percent,
					csiImport.i18n.uploading + ' ' + percent + '% (' + ( index + 1 ) + '/' + totalChunks + ' chunks)'
				);

				if ( result.complete && result.file ) {
					updateUploadUI( 100, csiImport.i18n.uploadComplete + ' ' + result.file.name );
					els.uploadInput.value = '';
					await loadFiles();
				}
			}
		} catch ( error ) {
			updateUploadUI( 0, csiImport.i18n.uploadFailed + ' ' + ( error.message || '' ) );
		} finally {
			isUploading = false;
			els.uploadBtn.disabled = ! els.uploadInput.files.length;
			els.uploadInput.disabled = false;
		}
	}

	async function loadJobs() {
		const jobs = await request( '/jobs' );
		renderJobs( jobs || [] );
	}

	async function loadLog( jobId ) {
		const data = await request( '/jobs/' + jobId + '/log' );
		renderLog( data );
	}

	async function openJob( jobId ) {
		activeJobId = jobId;
		const job = await request( '/jobs/' + jobId );
		updateJobUI( job );
		await loadLog( jobId );
	}

	async function startImport( fileName ) {
		if ( ! window.confirm( csiImport.i18n.confirmImport ) ) {
			return;
		}

		const job = await request( '/jobs', {
			method: 'POST',
			data: {
				file: fileName,
				...getOptions(),
			},
		} );

		activeJobId = job.id;
		shouldRun = true;
		updateJobUI( job );
		await loadJobs();
		await processLoop();
	}

	async function processLoop() {
		if ( ! activeJobId || ! shouldRun || isRunning ) {
			return;
		}

		isRunning = true;

		try {
			while ( shouldRun && activeJobId ) {
				let job = await request( '/jobs/' + activeJobId );
				updateJobUI( job );

				if ( job.status === 'paused' ) {
					break;
				}

				if ( job.status === 'completed' || job.status === 'failed' ) {
					await loadLog( activeJobId );
					await loadJobs();
					break;
				}

				if ( [ 'pending', 'parsing' ].includes( job.status ) ) {
					const parseResult = await request( '/jobs/' + activeJobId + '/parse', { method: 'POST' } );
					if ( parseResult.paused ) {
						break;
					}
					updateJobUI( parseResult.job );
					await loadLog( activeJobId );
					continue;
				}

				if ( [ 'ready', 'running' ].includes( job.status ) ) {
					const runResult = await request( '/jobs/' + activeJobId + '/run', { method: 'POST' } );
					if ( runResult.paused ) {
						break;
					}
					updateJobUI( runResult.job );
					await loadLog( activeJobId );

					if ( runResult.job.status === 'completed' || runResult.job.status === 'failed' ) {
						await loadJobs();
						break;
					}
					continue;
				}

				break;
			}
		} catch ( error ) {
			els.statusText.textContent = error.message || 'Import error';
		} finally {
			isRunning = false;
		}
	}

	els.refreshBtn.addEventListener( 'click', loadFiles );

	els.uploadInput.addEventListener( 'change', function () {
		els.uploadBtn.disabled = ! els.uploadInput.files.length || isUploading;
	} );

	els.uploadBtn.addEventListener( 'click', function () {
		if ( ! els.uploadInput.files.length ) {
			return;
		}
		uploadSqlFile( els.uploadInput.files[0] );
	} );

	els.startBtn.addEventListener( 'click', function () {
		shouldRun = true;
		processLoop();
	} );

	els.pauseBtn.addEventListener( 'click', async function () {
		if ( ! activeJobId ) {
			return;
		}
		shouldRun = false;
		const result = await request( '/jobs/' + activeJobId + '/pause', { method: 'POST' } );
		updateJobUI( result.job );
	} );

	els.resumeBtn.addEventListener( 'click', async function () {
		if ( ! activeJobId ) {
			return;
		}
		const result = await request( '/jobs/' + activeJobId + '/resume', { method: 'POST' } );
		updateJobUI( result.job );
		shouldRun = true;
		await processLoop();
	} );

	loadFiles();
	loadJobs();
}() );
