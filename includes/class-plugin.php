<?php
/**
 * Main plugin bootstrap.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CSI_PLUGIN_DIR . 'includes/class-logger.php';
require_once CSI_PLUGIN_DIR . 'includes/class-queue-repository.php';
require_once CSI_PLUGIN_DIR . 'includes/class-sql-parser.php';
require_once CSI_PLUGIN_DIR . 'includes/class-executor.php';
require_once CSI_PLUGIN_DIR . 'includes/class-job-manager.php';
require_once CSI_PLUGIN_DIR . 'includes/class-upload-handler.php';
require_once CSI_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once CSI_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Singleton plugin loader.
 */
class CSI_Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var CSI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return CSI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		load_plugin_textdomain( 'chunked-sql-importer', false, dirname( plugin_basename( CSI_PLUGIN_FILE ) ) . '/languages' );

		CSI_Rest_Controller::register();
		CSI_Admin_Page::register();
	}
}
