<?php
/**
 * Plugin activation: database tables and upload directories.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation and deactivation hooks.
 */
class CSI_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::create_directories();
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// Jobs remain in the database so imports can be resumed after reactivation.
	}

	/**
	 * Create custom database tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table      = CSI_Queue_Repository::jobs_table();
		$queue_table     = CSI_Queue_Repository::queue_table();
		$log_table       = CSI_Logger::log_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_jobs = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'pending',
			file_path varchar(500) NOT NULL,
			file_name varchar(255) NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			file_hash varchar(64) NOT NULL DEFAULT '',
			byte_offset bigint(20) unsigned NOT NULL DEFAULT 0,
			total_statements int(10) unsigned NOT NULL DEFAULT 0,
			executed_count int(10) unsigned NOT NULL DEFAULT 0,
			failed_count int(10) unsigned NOT NULL DEFAULT 0,
			skipped_count int(10) unsigned NOT NULL DEFAULT 0,
			options longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql_queue = "CREATE TABLE {$queue_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			seq int(10) unsigned NOT NULL,
			statement_type varchar(20) NOT NULL DEFAULT 'other',
			table_name varchar(191) NULL,
			sql_hash char(64) NOT NULL,
			sql_text longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			executed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY job_status (job_id, status),
			KEY job_seq (job_id, seq)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			queue_id bigint(20) unsigned NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			sql_preview varchar(500) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id)
		) {$charset_collate};";

		dbDelta( $sql_jobs );
		dbDelta( $sql_queue );
		dbDelta( $sql_log );
	}

	/**
	 * Create protected upload directories.
	 */
	public static function create_directories() {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'sql-imports';

		$dirs = array(
			$base,
			$base . '/inbox',
			$base . '/jobs',
			$base . '/tmp',
		);

		foreach ( $dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				continue;
			}

			$htaccess = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}
}
