<?php
/**
 * Import job orchestration.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates file discovery, parsing, and job lifecycle.
 */
class CSI_Job_Manager {

	const PARSE_BATCH_SIZE = 100;
	const PARSE_TIME_LIMIT = 20;

	/**
	 * Base directory for SQL imports.
	 *
	 * @return string
	 */
	public static function import_base_dir() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . 'sql-imports';
	}

	/**
	 * Inbox directory path.
	 *
	 * @return string
	 */
	public static function inbox_dir() {
		return trailingslashit( self::import_base_dir() ) . 'inbox';
	}

	/**
	 * Ensure import directories exist.
	 */
	public static function ensure_directories() {
		CSI_Activator::create_directories();
	}

	/**
	 * List SQL files available in the inbox.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_inbox_files() {
		self::ensure_directories();

		$dir   = self::inbox_dir();
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return $files;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry || '.htaccess' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}

			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 'sql' !== $extension ) {
				continue;
			}

			$files[] = array(
				'name' => $entry,
				'path' => $path,
				'size' => (int) filesize( $path ),
				'size_human' => size_format( filesize( $path ) ),
				'modified' => filemtime( $path ),
			);
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return $b['modified'] <=> $a['modified'];
			}
		);

		return $files;
	}

	/**
	 * Validate and resolve an inbox file path.
	 *
	 * @param string $filename File name only.
	 * @return string|WP_Error
	 */
	public static function resolve_inbox_file( $filename ) {
		$filename = basename( sanitize_file_name( $filename ) );
		if ( '' === $filename ) {
			return new WP_Error( 'csi_invalid_file', __( 'Invalid file name.', 'chunked-sql-importer' ) );
		}

		$path = self::inbox_dir() . '/' . $filename;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'csi_missing_file', __( 'SQL file not found in inbox.', 'chunked-sql-importer' ) );
		}

		$real_inbox = realpath( self::inbox_dir() );
		$real_file  = realpath( $path );

		if ( false === $real_inbox || false === $real_file || 0 !== strpos( $real_file, $real_inbox ) ) {
			return new WP_Error( 'csi_invalid_path', __( 'File path is not allowed.', 'chunked-sql-importer' ) );
		}

		return $real_file;
	}

	/**
	 * Create a job from an inbox file.
	 *
	 * @param string $filename File name.
	 * @param array  $options  Job options.
	 * @return int|WP_Error
	 */
	public static function create_job_from_file( $filename, array $options = array() ) {
		$path = self::resolve_inbox_file( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$defaults = array(
			'stop_on_error'      => false,
			'disable_fk_checks'  => true,
			'strip_definer'      => true,
			'skip_drop'          => false,
			'skip_create'        => false,
		);

		$options = wp_parse_args( $options, $defaults );
		$job_id  = CSI_Queue_Repository::create_job( $path, $options );

		if ( ! $job_id ) {
			return new WP_Error( 'csi_create_failed', __( 'Could not create import job.', 'chunked-sql-importer' ) );
		}

		CSI_Logger::log( $job_id, 'info', sprintf(
			/* translators: %s: file name */
			__( 'Import job created for %s.', 'chunked-sql-importer' ),
			basename( $path )
		) );

		return $job_id;
	}

	/**
	 * Parse the next chunk for a job.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $args   Optional overrides.
	 * @return array|WP_Error
	 */
	public static function parse_batch( $job_id, array $args = array() ) {
		$job = CSI_Queue_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'csi_job_missing', __( 'Job not found.', 'chunked-sql-importer' ) );
		}

		if ( 'paused' === $job->status ) {
			return array(
				'paused' => true,
				'job'    => self::format_job( $job ),
			);
		}

		if ( ! in_array( $job->status, array( 'pending', 'parsing' ), true ) ) {
			return array(
				'job'    => self::format_job( $job ),
				'done'   => true,
				'parsed' => 0,
			);
		}

		if ( ! file_exists( $job->file_path ) ) {
			CSI_Queue_Repository::set_status( $job_id, 'failed' );
			return new WP_Error( 'csi_file_missing', __( 'SQL file no longer exists.', 'chunked-sql-importer' ) );
		}

		CSI_Queue_Repository::set_status( $job_id, 'parsing' );

		$max_items  = isset( $args['batch_size'] ) ? (int) $args['batch_size'] : self::PARSE_BATCH_SIZE;
		$time_limit = isset( $args['time_limit'] ) ? (int) $args['time_limit'] : self::PARSE_TIME_LIMIT;
		$start_seq  = (int) $job->total_statements + 1;

		try {
			$result = CSI_Sql_Parser::parse_chunk(
				$job->file_path,
				(int) $job->byte_offset,
				$start_seq,
				$max_items,
				$time_limit,
				$job->options
			);
		} catch ( RuntimeException $e ) {
			CSI_Queue_Repository::set_status( $job_id, 'failed' );
			return new WP_Error( 'csi_parse_error', $e->getMessage() );
		}

		if ( ! empty( $result['statements'] ) ) {
			CSI_Queue_Repository::enqueue_statements( $job_id, $result['statements'] );
		}

		CSI_Queue_Repository::update_job(
			$job_id,
			array(
				'byte_offset' => (int) $result['byte_offset'],
			)
		);

		$job = CSI_Queue_Repository::get_job( $job_id );

		if ( ! empty( $result['eof'] ) ) {
			CSI_Queue_Repository::set_status( $job_id, 'ready' );
			CSI_Logger::log( $job_id, 'info', __( 'SQL file parsed. Ready to execute.', 'chunked-sql-importer' ) );
			$job = CSI_Queue_Repository::get_job( $job_id );
		}

		return array(
			'job'    => self::format_job( $job ),
			'parsed' => count( $result['statements'] ),
			'eof'    => ! empty( $result['eof'] ),
		);
	}

	/**
	 * Pause a running job.
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public static function pause_job( $job_id ) {
		$job = CSI_Queue_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'csi_job_missing', __( 'Job not found.', 'chunked-sql-importer' ) );
		}

		if ( in_array( $job->status, array( 'completed', 'failed' ), true ) ) {
			return new WP_Error( 'csi_cannot_pause', __( 'This job cannot be paused.', 'chunked-sql-importer' ) );
		}

		CSI_Queue_Repository::set_status( $job_id, 'paused' );
		CSI_Logger::log( $job_id, 'info', __( 'Import paused.', 'chunked-sql-importer' ) );

		return array(
			'job' => self::format_job( CSI_Queue_Repository::get_job( $job_id ) ),
		);
	}

	/**
	 * Resume a paused job.
	 *
	 * @param int $job_id Job ID.
	 * @return array|WP_Error
	 */
	public static function resume_job( $job_id ) {
		$job = CSI_Queue_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'csi_job_missing', __( 'Job not found.', 'chunked-sql-importer' ) );
		}

		if ( 'paused' !== $job->status ) {
			return new WP_Error( 'csi_not_paused', __( 'Job is not paused.', 'chunked-sql-importer' ) );
		}

		$pending    = CSI_Queue_Repository::count_pending( $job_id );
		$new_status = 'ready';

		if ( (int) $job->byte_offset < (int) $job->file_size ) {
			$new_status = 'parsing';
		} elseif ( $pending > 0 ) {
			$new_status = 'ready';
		}

		CSI_Queue_Repository::set_status( $job_id, $new_status );
		CSI_Logger::log( $job_id, 'info', __( 'Import resumed.', 'chunked-sql-importer' ) );

		return array(
			'job' => self::format_job( CSI_Queue_Repository::get_job( $job_id ) ),
		);
	}

	/**
	 * Format a job row for API responses.
	 *
	 * @param object $job Job row.
	 * @return array<string, mixed>
	 */
	public static function format_job( $job ) {
		$options = $job->options;
		if ( is_string( $options ) ) {
			$options = json_decode( $options, true );
		}
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$total     = max( 1, (int) $job->total_statements );
		$processed = (int) $job->executed_count + (int) $job->failed_count + (int) $job->skipped_count;
		$pending   = CSI_Queue_Repository::count_pending( (int) $job->id );

		$parse_percent = 0;
		if ( (int) $job->file_size > 0 ) {
			$parse_percent = min( 100, round( ( (int) $job->byte_offset / (int) $job->file_size ) * 100, 1 ) );
		}

		$execute_percent = 0;
		if ( (int) $job->total_statements > 0 ) {
			$execute_percent = min( 100, round( ( $processed / $total ) * 100, 1 ) );
		}

		return array(
			'id'               => (int) $job->id,
			'status'           => $job->status,
			'file_name'        => $job->file_name,
			'file_size'        => (int) $job->file_size,
			'file_size_human'  => size_format( (int) $job->file_size ),
			'byte_offset'      => (int) $job->byte_offset,
			'total_statements' => (int) $job->total_statements,
			'executed_count'   => (int) $job->executed_count,
			'failed_count'     => (int) $job->failed_count,
			'skipped_count'    => (int) $job->skipped_count,
			'pending_count'    => $pending,
			'parse_percent'    => $parse_percent,
			'execute_percent'  => $execute_percent,
			'created_at'       => $job->created_at,
			'updated_at'       => $job->updated_at,
			'options'          => $options,
		);
	}
}
