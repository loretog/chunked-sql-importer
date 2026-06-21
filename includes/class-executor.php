<?php
/**
 * Executes queued SQL statements in batches.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs pending queue rows against the WordPress database.
 */
class CSI_Executor {

	const DEFAULT_BATCH_SIZE = 50;
	const DEFAULT_TIME_LIMIT = 20;

	/**
	 * Execute the next batch for a job.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $args   Optional overrides.
	 * @return array Result payload.
	 */
	public static function run_batch( $job_id, array $args = array() ) {
		$job = CSI_Queue_Repository::get_job( $job_id );
		if ( ! $job ) {
			return array( 'error' => __( 'Job not found.', 'chunked-sql-importer' ) );
		}

		if ( 'paused' === $job->status ) {
			return array(
				'paused' => true,
				'job'    => CSI_Job_Manager::format_job( $job ),
			);
		}

		if ( ! in_array( $job->status, array( 'ready', 'running' ), true ) ) {
			return array(
				'error' => __( 'Job is not ready to execute.', 'chunked-sql-importer' ),
				'job'   => CSI_Job_Manager::format_job( $job ),
			);
		}

		CSI_Queue_Repository::set_status( $job_id, 'running' );

		$batch_size = isset( $args['batch_size'] ) ? (int) $args['batch_size'] : self::DEFAULT_BATCH_SIZE;
		$time_limit = isset( $args['time_limit'] ) ? (int) $args['time_limit'] : self::DEFAULT_TIME_LIMIT;
		$started_at = microtime( true );

		$batch   = CSI_Queue_Repository::get_pending_batch( $job_id, $batch_size );
		$ran     = 0;
		$success = 0;
		$failed  = 0;
		$skipped = 0;

		$stop_on_error = ! empty( $job->options['stop_on_error'] );

		if ( ! empty( $job->options['disable_fk_checks'] ) ) {
			self::query( 'SET FOREIGN_KEY_CHECKS = 0' );
		}

		foreach ( $batch as $row ) {
			if ( ( microtime( true ) - $started_at ) >= $time_limit ) {
				break;
			}

			++$ran;
			$sql = self::prepare_sql( $row->sql_text, $job->options );

			if ( '' === trim( $sql ) ) {
				CSI_Queue_Repository::mark_skipped( $row->id, 'Empty statement' );
				CSI_Queue_Repository::increment_counter( $job_id, 'skipped_count' );
				++$skipped;
				continue;
			}

			$result = self::query( $sql );

			if ( false === $result ) {
				global $wpdb;
				$error    = $wpdb->last_error ? $wpdb->last_error : __( 'Unknown database error.', 'chunked-sql-importer' );
				$attempts = (int) $row->attempts + 1;

				CSI_Queue_Repository::mark_failed( $row->id, $error, $attempts );
				CSI_Queue_Repository::increment_counter( $job_id, 'failed_count' );
				CSI_Logger::log( $job_id, 'error', $error, (int) $row->id, $sql );
				++$failed;

				if ( $stop_on_error ) {
					CSI_Queue_Repository::set_status( $job_id, 'failed' );
					break;
				}

				continue;
			}

			CSI_Queue_Repository::mark_success( $row->id );
			CSI_Queue_Repository::increment_counter( $job_id, 'executed_count' );
			++$success;
		}

		if ( $success > 0 ) {
			CSI_Logger::log(
				$job_id,
				'info',
				sprintf(
					/* translators: 1: success count, 2: failed count */
					__( 'Batch complete: %1$d succeeded, %2$d failed.', 'chunked-sql-importer' ),
					$success,
					$failed
				)
			);
		}

		if ( ! empty( $job->options['disable_fk_checks'] ) ) {
			self::query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}

		$pending = CSI_Queue_Repository::count_pending( $job_id );
		$job     = CSI_Queue_Repository::get_job( $job_id );

		if ( $pending === 0 && 'failed' !== $job->status ) {
			CSI_Queue_Repository::set_status( $job_id, 'completed' );
			CSI_Logger::log( $job_id, 'info', __( 'Import completed.', 'chunked-sql-importer' ) );
			$job = CSI_Queue_Repository::get_job( $job_id );
		} elseif ( 'running' === $job->status && $pending > 0 ) {
			CSI_Queue_Repository::set_status( $job_id, 'ready' );
			$job = CSI_Queue_Repository::get_job( $job_id );
		}

		return array(
			'job'     => CSI_Job_Manager::format_job( $job ),
			'ran'     => $ran,
			'success' => $success,
			'failed'  => $failed,
			'skipped' => $skipped,
			'pending' => $pending,
		);
	}

	/**
	 * Run a raw SQL query.
	 *
	 * @param string $sql SQL statement.
	 * @return int|false|true
	 */
	private static function query( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importer intentionally runs dump SQL.
		return $wpdb->query( $sql );
	}

	/**
	 * Normalize SQL before execution.
	 *
	 * @param string $sql     SQL statement.
	 * @param array  $options Job options.
	 * @return string
	 */
	private static function prepare_sql( $sql, array $options ) {
		if ( ! empty( $options['strip_definer'] ) ) {
			$sql = preg_replace( '/\sDEFINER\s*=\s*`[^`]+`@`[^`]+`/i', '', $sql );
		}

		return trim( $sql );
	}
}
