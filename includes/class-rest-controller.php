<?php
/**
 * REST API for chunked SQL imports.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers REST routes for the importer.
 */
class CSI_Rest_Controller {

	const NAMESPACE = 'csi/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_files' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/files/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload_chunk' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_jobs' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_job' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'file' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_file_name',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/parse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'parse_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'pause_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resume_job' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_log' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List inbox SQL files.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_files() {
		return rest_ensure_response(
			array(
				'inbox'      => CSI_Job_Manager::inbox_dir(),
				'files'      => CSI_Job_Manager::list_inbox_files(),
				'chunk_size' => CSI_Upload_Handler::chunk_size(),
			)
		);
	}

	/**
	 * Receive one upload chunk and append it to the inbox assembly file.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_chunk( WP_REST_Request $request ) {
		$result = CSI_Upload_Handler::handle_chunk(
			array(
				'upload_id'    => $request->get_param( 'upload_id' ),
				'filename'     => $request->get_param( 'filename' ),
				'file_size'    => $request->get_param( 'file_size' ),
				'chunk_index'  => $request->get_param( 'chunk_index' ),
				'total_chunks' => $request->get_param( 'total_chunks' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * List import jobs.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_jobs() {
		$jobs = CSI_Queue_Repository::list_jobs( 50 );

		return rest_ensure_response(
			array_map(
				array( 'CSI_Job_Manager', 'format_job' ),
				$jobs
			)
		);
	}

	/**
	 * Create a new import job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ) {
		$options = array(
			'stop_on_error'     => (bool) $request->get_param( 'stop_on_error' ),
			'disable_fk_checks' => (bool) $request->get_param( 'disable_fk_checks' ),
			'strip_definer'     => (bool) $request->get_param( 'strip_definer' ),
			'skip_drop'         => (bool) $request->get_param( 'skip_drop' ),
			'skip_create'       => (bool) $request->get_param( 'skip_create' ),
		);

		$job_id = CSI_Job_Manager::create_job_from_file( $request->get_param( 'file' ), $options );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		$job = CSI_Queue_Repository::get_job( $job_id );

		return rest_ensure_response( CSI_Job_Manager::format_job( $job ) );
	}

	/**
	 * Get a single job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		$job = CSI_Queue_Repository::get_job( (int) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'csi_job_missing', __( 'Job not found.', 'chunked-sql-importer' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( CSI_Job_Manager::format_job( $job ) );
	}

	/**
	 * Parse the next SQL chunk for a job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function parse_job( WP_REST_Request $request ) {
		$result = CSI_Job_Manager::parse_batch( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Execute the next SQL batch for a job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run_job( WP_REST_Request $request ) {
		$result = CSI_Executor::run_batch( (int) $request['id'] );
		if ( ! empty( $result['error'] ) ) {
			return new WP_Error( 'csi_run_error', $result['error'], array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Pause a job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pause_job( WP_REST_Request $request ) {
		$result = CSI_Job_Manager::pause_job( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Resume a paused job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function resume_job( WP_REST_Request $request ) {
		$result = CSI_Job_Manager::resume_job( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get recent log lines for a job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_log( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = CSI_Queue_Repository::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'csi_job_missing', __( 'Job not found.', 'chunked-sql-importer' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'entries' => CSI_Logger::get_recent( $job_id, 100 ),
				'failed'  => CSI_Queue_Repository::get_failed( $job_id, 25 ),
			)
		);
	}
}
