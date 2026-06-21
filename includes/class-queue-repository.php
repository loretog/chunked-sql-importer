<?php
/**
 * Database access for import jobs and statement queue.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists jobs and queued SQL statements.
 */
class CSI_Queue_Repository {

	const JOBS_TABLE  = 'csi_jobs';
	const QUEUE_TABLE = 'csi_queue';

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;

		return $wpdb->prefix . self::JOBS_TABLE;
	}

	/**
	 * Queue table name.
	 *
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;

		return $wpdb->prefix . self::QUEUE_TABLE;
	}

	/**
	 * Create a new import job.
	 *
	 * @param string $file_path Absolute path to SQL file.
	 * @param array  $options   Job options.
	 * @return int|false Job ID or false on failure.
	 */
	public static function create_job( $file_path, array $options = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::jobs_table(),
			array(
				'status'      => 'pending',
				'file_path'   => $file_path,
				'file_name'   => basename( $file_path ),
				'file_size'   => (int) filesize( $file_path ),
				'file_hash'   => hash_file( 'sha256', $file_path ),
				'byte_offset' => 0,
				'options'     => wp_json_encode( $options ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get a job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return object|null
	 */
	public static function get_job( $job_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::jobs_table() . ' WHERE id = %d',
				$job_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$row->options = json_decode( (string) $row->options, true );
		if ( ! is_array( $row->options ) ) {
			$row->options = array();
		}

		return $row;
	}

	/**
	 * List jobs ordered by newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function list_jobs( $limit = 20 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::jobs_table() . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Update job fields.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Column => value.
	 */
	public static function update_job( $job_id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update(
			self::jobs_table(),
			$data,
			array( 'id' => (int) $job_id )
		);
	}

	/**
	 * Set job status.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status New status.
	 */
	public static function set_status( $job_id, $status ) {
		self::update_job(
			$job_id,
			array(
				'status' => sanitize_key( $status ),
			)
		);
	}

	/**
	 * Queue parsed statements.
	 *
	 * @param int   $job_id     Job ID.
	 * @param array $statements Parsed statement rows.
	 */
	public static function enqueue_statements( $job_id, array $statements ) {
		global $wpdb;

		if ( empty( $statements ) ) {
			return;
		}

		$table = self::queue_table();
		$now   = current_time( 'mysql', true );

		foreach ( $statements as $statement ) {
			$wpdb->insert(
				$table,
				array(
					'job_id'         => (int) $job_id,
					'seq'            => (int) $statement['seq'],
					'statement_type' => $statement['type'],
					'table_name'     => $statement['table'],
					'sql_hash'       => hash( 'sha256', $statement['sql'] ),
					'sql_text'       => $statement['sql'],
					'status'         => 'pending',
					'attempts'       => 0,
					'executed_at'    => null,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		self::update_job(
			$job_id,
			array(
				'total_statements' => (int) self::count_queue( $job_id ),
			)
		);
	}

	/**
	 * Count queued statements for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public static function count_queue( $job_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::queue_table() . ' WHERE job_id = %d',
				$job_id
			)
		);
	}

	/**
	 * Count pending statements.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public static function count_pending( $job_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::queue_table() . " WHERE job_id = %d AND status = 'pending'",
				$job_id
			)
		);
	}

	/**
	 * Fetch the next batch of pending statements.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Batch size.
	 * @return array<int, object>
	 */
	public static function get_pending_batch( $job_id, $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::queue_table() . "
				WHERE job_id = %d AND status = 'pending'
				ORDER BY seq ASC
				LIMIT %d",
				$job_id,
				$limit
			)
		);
	}

	/**
	 * Mark a queue row as successful.
	 *
	 * @param int $queue_id Queue row ID.
	 */
	public static function mark_success( $queue_id ) {
		global $wpdb;

		$wpdb->update(
			self::queue_table(),
			array(
				'status'      => 'success',
				'executed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $queue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a queue row as failed.
	 *
	 * @param int    $queue_id Queue row ID.
	 * @param string $error    Error message.
	 * @param int    $attempts Attempt count.
	 */
	public static function mark_failed( $queue_id, $error, $attempts ) {
		global $wpdb;

		$wpdb->update(
			self::queue_table(),
			array(
				'status'     => 'failed',
				'last_error' => $error,
				'attempts'   => (int) $attempts,
			),
			array( 'id' => (int) $queue_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a queue row as skipped.
	 *
	 * @param int    $queue_id Queue row ID.
	 * @param string $reason   Skip reason.
	 */
	public static function mark_skipped( $queue_id, $reason ) {
		global $wpdb;

		$wpdb->update(
			self::queue_table(),
			array(
				'status'     => 'skipped',
				'last_error' => $reason,
			),
			array( 'id' => (int) $queue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment job counters.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $field  executed_count|failed_count|skipped_count.
	 */
	public static function increment_counter( $job_id, $field ) {
		global $wpdb;

		$allowed = array( 'executed_count', 'failed_count', 'skipped_count' );
		if ( ! in_array( $field, $allowed, true ) ) {
			return;
		}

		$table = self::jobs_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET {$field} = {$field} + 1, updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$job_id
			)
		);
	}

	/**
	 * Get failed statements for a job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Max rows.
	 * @return array<int, object>
	 */
	public static function get_failed( $job_id, $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, seq, statement_type, table_name, last_error, sql_text
				FROM " . self::queue_table() . "
				WHERE job_id = %d AND status = 'failed'
				ORDER BY seq ASC
				LIMIT %d",
				$job_id,
				$limit
			)
		);
	}
}
