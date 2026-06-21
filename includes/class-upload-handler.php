<?php
/**
 * Chunked SQL file uploads to the inbox directory.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives chunked uploads and assembles them in the inbox folder.
 */
class CSI_Upload_Handler {

	const TRANSIENT_PREFIX = 'csi_upload_';
	const TRANSIENT_TTL    = DAY_IN_SECONDS;

	/**
	 * Temporary upload directory.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		return trailingslashit( CSI_Job_Manager::import_base_dir() ) . 'tmp';
	}

	/**
	 * Recommended chunk size for browser uploads (2 MB).
	 *
	 * @return int
	 */
	public static function chunk_size() {
		return 2 * 1024 * 1024;
	}

	/**
	 * Handle one uploaded chunk.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error
	 */
	public static function handle_chunk( array $params ) {
		CSI_Job_Manager::ensure_directories();

		if ( empty( $_FILES['chunk'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
			return new WP_Error( 'csi_no_chunk', __( 'No file chunk was uploaded.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $_FILES['chunk']['error'] ) ) {
			return new WP_Error(
				'csi_chunk_error',
				self::upload_error_message( (int) $_FILES['chunk']['error'] ),
				array( 'status' => 400 )
			);
		}

		$filename     = isset( $params['filename'] ) ? sanitize_file_name( $params['filename'] ) : '';
		$file_size    = isset( $params['file_size'] ) ? (int) $params['file_size'] : 0;
		$chunk_index  = isset( $params['chunk_index'] ) ? (int) $params['chunk_index'] : 0;
		$total_chunks = isset( $params['total_chunks'] ) ? (int) $params['total_chunks'] : 0;
		$upload_id    = isset( $params['upload_id'] ) ? sanitize_key( $params['upload_id'] ) : '';

		if ( '' === $filename || ! self::is_valid_sql_filename( $filename ) ) {
			return new WP_Error( 'csi_invalid_filename', __( 'Only .sql files are allowed.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		if ( $file_size <= 0 || $total_chunks <= 0 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
			return new WP_Error( 'csi_invalid_upload', __( 'Invalid upload parameters.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		if ( 0 === $chunk_index ) {
			$upload_id = self::create_upload_session( $filename, $file_size, $total_chunks );
			if ( is_wp_error( $upload_id ) ) {
				return $upload_id;
			}
		} elseif ( '' === $upload_id ) {
			return new WP_Error( 'csi_missing_upload_id', __( 'Upload session ID is required.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		$session = self::get_session( $upload_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( $session['filename'] !== $filename || (int) $session['file_size'] !== $file_size || (int) $session['total_chunks'] !== $total_chunks ) {
			return new WP_Error( 'csi_upload_mismatch', __( 'Upload metadata does not match the active session.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		if ( (int) $session['chunks_received'] !== $chunk_index ) {
			return new WP_Error(
				'csi_chunk_out_of_order',
				sprintf(
					/* translators: %d: expected chunk index */
					__( 'Expected chunk %d.', 'chunked-sql-importer' ),
					(int) $session['chunks_received']
				),
				array( 'status' => 400 )
			);
		}

		$part_path = self::part_path( $upload_id );
		$mode      = 0 === $chunk_index ? 'wb' : 'ab';
		$handle    = fopen( $part_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return new WP_Error( 'csi_write_failed', __( 'Could not write upload chunk.', 'chunked-sql-importer' ), array( 'status' => 500 ) );
		}

		$source = fopen( $_FILES['chunk']['tmp_name'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $source ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'csi_read_failed', __( 'Could not read uploaded chunk.', 'chunked-sql-importer' ), array( 'status' => 500 ) );
		}

		stream_copy_to_stream( $source, $handle );
		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$session['chunks_received'] = $chunk_index + 1;
		$session['bytes_received']  = (int) filesize( $part_path );
		self::save_session( $upload_id, $session );

		$complete   = (int) $session['chunks_received'] >= $total_chunks;
		$response   = array(
			'upload_id'        => $upload_id,
			'chunk_index'      => $chunk_index,
			'chunks_received'  => (int) $session['chunks_received'],
			'total_chunks'     => $total_chunks,
			'bytes_received'   => (int) $session['bytes_received'],
			'file_size'        => $file_size,
			'complete'         => $complete,
			'progress_percent' => min( 100, round( ( (int) $session['bytes_received'] / $file_size ) * 100, 1 ) ),
		);

		if ( $complete ) {
			$finalized = self::finalize_upload( $upload_id, $session );
			if ( is_wp_error( $finalized ) ) {
				return $finalized;
			}

			$response['file'] = $finalized;
		}

		return $response;
	}

	/**
	 * Create a new upload session.
	 *
	 * @param string $filename     Sanitized file name.
	 * @param int    $file_size    Total file size.
	 * @param int    $total_chunks Total chunk count.
	 * @return string|WP_Error Upload ID.
	 */
	private static function create_upload_session( $filename, $file_size, $total_chunks ) {
		$upload_id = wp_generate_password( 32, false, false );
		$tmp_dir   = self::tmp_dir();

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new WP_Error( 'csi_tmp_unavailable', __( 'Temporary upload directory is not writable.', 'chunked-sql-importer' ), array( 'status' => 500 ) );
		}

		$session = array(
			'upload_id'        => $upload_id,
			'filename'         => $filename,
			'file_size'        => $file_size,
			'total_chunks'     => $total_chunks,
			'chunks_received'  => 0,
			'bytes_received'   => 0,
			'user_id'          => get_current_user_id(),
			'created_at'       => time(),
		);

		self::save_session( $upload_id, $session );

		return $upload_id;
	}

	/**
	 * Move assembled file into the inbox.
	 *
	 * @param string $upload_id Upload session ID.
	 * @param array  $session   Session data.
	 * @return array|WP_Error
	 */
	private static function finalize_upload( $upload_id, array $session ) {
		$part_path = self::part_path( $upload_id );
		if ( ! file_exists( $part_path ) ) {
			return new WP_Error( 'csi_part_missing', __( 'Uploaded file is missing.', 'chunked-sql-importer' ), array( 'status' => 500 ) );
		}

		$actual_size = (int) filesize( $part_path );
		if ( $actual_size !== (int) $session['file_size'] ) {
			self::cleanup_upload( $upload_id );
			return new WP_Error(
				'csi_size_mismatch',
				sprintf(
					/* translators: 1: expected bytes, 2: received bytes */
					__( 'Upload size mismatch. Expected %1$d bytes but received %2$d.', 'chunked-sql-importer' ),
					(int) $session['file_size'],
					$actual_size
				),
				array( 'status' => 400 )
			);
		}

		$dest_path = self::unique_inbox_path( $session['filename'] );
		if ( ! @rename( $part_path, $dest_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::cleanup_upload( $upload_id );
			return new WP_Error( 'csi_finalize_failed', __( 'Could not move uploaded file into inbox.', 'chunked-sql-importer' ), array( 'status' => 500 ) );
		}

		self::cleanup_upload( $upload_id, false );

		return array(
			'name'       => basename( $dest_path ),
			'path'       => $dest_path,
			'size'       => $actual_size,
			'size_human' => size_format( $actual_size ),
		);
	}

	/**
	 * Resolve a unique inbox destination path.
	 *
	 * @param string $filename Desired file name.
	 * @return string
	 */
	private static function unique_inbox_path( $filename ) {
		$inbox = CSI_Job_Manager::inbox_dir();
		$path  = trailingslashit( $inbox ) . $filename;

		if ( ! file_exists( $path ) ) {
			return $path;
		}

		$info      = pathinfo( $filename );
		$base      = $info['filename'];
		$extension = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$counter   = 1;

		do {
			$candidate = trailingslashit( $inbox ) . $base . '-' . $counter . $extension;
			++$counter;
		} while ( file_exists( $candidate ) );

		return $candidate;
	}

	/**
	 * Part file path for an upload session.
	 *
	 * @param string $upload_id Upload ID.
	 * @return string
	 */
	private static function part_path( $upload_id ) {
		return trailingslashit( self::tmp_dir() ) . $upload_id . '.part';
	}

	/**
	 * Load upload session data.
	 *
	 * @param string $upload_id Upload ID.
	 * @return array|WP_Error
	 */
	private static function get_session( $upload_id ) {
		$session = get_transient( self::TRANSIENT_PREFIX . $upload_id );
		if ( ! is_array( $session ) ) {
			return new WP_Error( 'csi_upload_expired', __( 'Upload session expired. Please upload again.', 'chunked-sql-importer' ), array( 'status' => 400 ) );
		}

		if ( (int) $session['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'csi_upload_forbidden', __( 'You cannot continue this upload session.', 'chunked-sql-importer' ), array( 'status' => 403 ) );
		}

		return $session;
	}

	/**
	 * Persist upload session data.
	 *
	 * @param string $upload_id Upload ID.
	 * @param array  $session   Session data.
	 */
	private static function save_session( $upload_id, array $session ) {
		set_transient( self::TRANSIENT_PREFIX . $upload_id, $session, self::TRANSIENT_TTL );
	}

	/**
	 * Remove upload session artifacts.
	 *
	 * @param string $upload_id   Upload ID.
	 * @param bool    $delete_part Whether to delete the part file.
	 */
	private static function cleanup_upload( $upload_id, $delete_part = true ) {
		delete_transient( self::TRANSIENT_PREFIX . $upload_id );

		if ( $delete_part ) {
			$part_path = self::part_path( $upload_id );
			if ( file_exists( $part_path ) ) {
				wp_delete_file( $part_path );
			}
		}
	}

	/**
	 * Validate SQL file extension.
	 *
	 * @param string $filename File name.
	 * @return bool
	 */
	private static function is_valid_sql_filename( $filename ) {
		return 'sql' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Map PHP upload error codes to messages.
	 *
	 * @param int $code PHP upload error code.
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'Uploaded chunk exceeds the server size limit.', 'chunked-sql-importer' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The chunk was only partially uploaded.', 'chunked-sql-importer' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No chunk file was uploaded.', 'chunked-sql-importer' );
			default:
				return __( 'Chunk upload failed.', 'chunked-sql-importer' );
		}
	}
}
