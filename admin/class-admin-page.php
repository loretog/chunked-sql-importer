<?php
/**
 * Admin UI for chunked SQL imports.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Tools submenu and enqueues admin assets.
 */
class CSI_Admin_Page {

	/**
	 * Register admin hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add Tools submenu page.
	 */
	public static function add_menu() {
		add_management_page(
			__( 'SQL Import', 'chunked-sql-importer' ),
			__( 'SQL Import', 'chunked-sql-importer' ),
			'manage_options',
			'chunked-sql-importer',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'tools_page_chunked-sql-importer' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'csi-admin',
			CSI_PLUGIN_URL . 'admin/css/import-ui.css',
			array(),
			CSI_VERSION
		);

		wp_enqueue_script(
			'csi-admin',
			CSI_PLUGIN_URL . 'admin/js/import-ui.js',
			array( 'wp-api-fetch' ),
			CSI_VERSION,
			true
		);

		wp_localize_script(
			'csi-admin',
			'csiImport',
			array(
				'root'  => esc_url_raw( rest_url( 'csi/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'import'         => __( 'Start Import', 'chunked-sql-importer' ),
					'pause'          => __( 'Pause', 'chunked-sql-importer' ),
					'resume'         => __( 'Resume', 'chunked-sql-importer' ),
					'parsing'        => __( 'Parsing SQL file...', 'chunked-sql-importer' ),
					'executing'      => __( 'Executing statements...', 'chunked-sql-importer' ),
					'completed'      => __( 'Import completed.', 'chunked-sql-importer' ),
					'failed'         => __( 'Import failed.', 'chunked-sql-importer' ),
					'paused'         => __( 'Import paused.', 'chunked-sql-importer' ),
					'noFiles'        => __( 'No SQL files found in the inbox folder.', 'chunked-sql-importer' ),
					'confirmImport'  => __( 'This will run SQL against your database. Make sure you have a backup. Continue?', 'chunked-sql-importer' ),
					'upload'         => __( 'Upload SQL file', 'chunked-sql-importer' ),
					'uploading'      => __( 'Uploading...', 'chunked-sql-importer' ),
					'uploadComplete' => __( 'Upload complete.', 'chunked-sql-importer' ),
					'uploadFailed'   => __( 'Upload failed.', 'chunked-sql-importer' ),
					'chooseFile'     => __( 'Choose .sql file', 'chunked-sql-importer' ),
					'invalidFile'    => __( 'Please choose a .sql file.', 'chunked-sql-importer' ),
				),
			)
		);
	}

	/**
	 * Render admin page markup.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		CSI_Job_Manager::ensure_directories();
		$inbox = CSI_Job_Manager::inbox_dir();
		?>
		<div class="wrap csi-wrap">
			<h1><?php esc_html_e( 'Chunked SQL Importer', 'chunked-sql-importer' ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'chunked-sql-importer' ); ?></strong>
					<?php esc_html_e( 'This tool executes raw SQL against your database. Always back up before importing.', 'chunked-sql-importer' ); ?>
				</p>
			</div>

			<div class="csi-panel">
				<h2><?php esc_html_e( 'Step 1: Add your SQL file', 'chunked-sql-importer' ); ?></h2>

				<div class="csi-upload-block">
					<h3><?php esc_html_e( 'Upload from your computer', 'chunked-sql-importer' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Large files are uploaded in small chunks to avoid browser timeouts.', 'chunked-sql-importer' ); ?>
					</p>
					<div class="csi-upload-controls">
						<input type="file" id="csi-upload-input" accept=".sql,text/plain" />
						<button type="button" class="button button-primary" id="csi-upload-btn" disabled>
							<?php esc_html_e( 'Upload SQL file', 'chunked-sql-importer' ); ?>
						</button>
					</div>
					<div id="csi-upload-progress" class="csi-upload-progress" hidden>
						<div class="csi-progress-bar"><span id="csi-upload-bar" style="width:0%"></span></div>
						<p id="csi-upload-meta" class="description"></p>
					</div>
				</div>

				<div class="csi-upload-divider">
					<span><?php esc_html_e( 'or', 'chunked-sql-importer' ); ?></span>
				</div>

				<div class="csi-server-path-block">
					<h3><?php esc_html_e( 'Copy to server folder', 'chunked-sql-importer' ); ?></h3>
					<p>
						<?php esc_html_e( 'Place your .sql dump in this folder on the server:', 'chunked-sql-importer' ); ?>
					</p>
					<code class="csi-path"><?php echo esc_html( $inbox ); ?></code>
					<p>
						<button type="button" class="button" id="csi-refresh-files">
							<?php esc_html_e( 'Refresh file list', 'chunked-sql-importer' ); ?>
						</button>
					</p>
				</div>
			</div>

			<div class="csi-panel">
				<h2><?php esc_html_e( 'Step 2: Choose a file to import', 'chunked-sql-importer' ); ?></h2>
				<div id="csi-file-list" class="csi-file-list">
					<p class="description"><?php esc_html_e( 'Loading files...', 'chunked-sql-importer' ); ?></p>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'chunked-sql-importer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="csi-disable-fk" checked />
								<?php esc_html_e( 'Disable foreign key checks during import', 'chunked-sql-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" id="csi-strip-definer" checked />
								<?php esc_html_e( 'Strip DEFINER clauses', 'chunked-sql-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" id="csi-stop-on-error" />
								<?php esc_html_e( 'Stop on first SQL error', 'chunked-sql-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" id="csi-skip-drop" />
								<?php esc_html_e( 'Skip DROP statements', 'chunked-sql-importer' ); ?>
							</label><br />
							<label>
								<input type="checkbox" id="csi-skip-create" />
								<?php esc_html_e( 'Skip CREATE statements', 'chunked-sql-importer' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<div class="csi-panel" id="csi-progress-panel" hidden>
				<h2><?php esc_html_e( 'Import progress', 'chunked-sql-importer' ); ?></h2>
				<p id="csi-status-text" class="csi-status-text"></p>

				<div class="csi-progress-block">
					<label><?php esc_html_e( 'Parse progress', 'chunked-sql-importer' ); ?></label>
					<div class="csi-progress-bar"><span id="csi-parse-bar" style="width:0%"></span></div>
					<p id="csi-parse-meta" class="description"></p>
				</div>

				<div class="csi-progress-block">
					<label><?php esc_html_e( 'Execute progress', 'chunked-sql-importer' ); ?></label>
					<div class="csi-progress-bar"><span id="csi-exec-bar" style="width:0%"></span></div>
					<p id="csi-exec-meta" class="description"></p>
				</div>

				<p>
					<button type="button" class="button button-primary" id="csi-start" hidden>
						<?php esc_html_e( 'Start Import', 'chunked-sql-importer' ); ?>
					</button>
					<button type="button" class="button" id="csi-pause" hidden>
						<?php esc_html_e( 'Pause', 'chunked-sql-importer' ); ?>
					</button>
					<button type="button" class="button button-primary" id="csi-resume" hidden>
						<?php esc_html_e( 'Resume', 'chunked-sql-importer' ); ?>
					</button>
				</p>
			</div>

			<div class="csi-panel">
				<h2><?php esc_html_e( 'Recent jobs', 'chunked-sql-importer' ); ?></h2>
				<div id="csi-job-list"></div>
			</div>

			<div class="csi-panel">
				<h2><?php esc_html_e( 'Import log', 'chunked-sql-importer' ); ?></h2>
				<pre id="csi-log-output" class="csi-log-output"></pre>
			</div>
		</div>
		<?php
	}
}
