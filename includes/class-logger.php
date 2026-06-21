<?php
/**
 * Import audit logger.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads import log entries.
 */
class CSI_Logger {

	/**
	 * Log table name without prefix.
	 */
	const TABLE = 'csi_log';

	/**
	 * Get fully qualified log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a log entry.
	 *
	 * @param int         $job_id      Job ID.
	 * @param string      $level       Log level: info, warn, error.
	 * @param string      $message     Log message.
	 * @param int|null    $queue_id    Related queue row ID.
	 * @param string|null $sql_preview SQL preview text.
	 */
	public static function log( $job_id, $level, $message, $queue_id = null, $sql_preview = null ) {
		global $wpdb;

		$wpdb->insert(
			self::log_table(),
			array(
				'job_id'      => (int) $job_id,
				'queue_id'    => $queue_id ? (int) $queue_id : null,
				'level'       => sanitize_key( $level ),
				'message'     => $message,
				'sql_preview' => $sql_preview ? self::preview( $sql_preview ) : null,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch recent log lines for a job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Max rows.
	 * @return array<int, object>
	 */
	public static function get_recent( $job_id, $limit = 100 ) {
		global $wpdb;

		$table = self::log_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, queue_id, level, message, sql_preview, created_at
				FROM {$table}
				WHERE job_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$job_id,
				$limit
			)
		);
	}

	/**
	 * Truncate SQL for display.
	 *
	 * @param string $sql SQL text.
	 * @return string
	 */
	public static function preview( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );

		if ( strlen( $sql ) > 500 ) {
			return substr( $sql, 0, 497 ) . '...';
		}

		return $sql;
	}
}
