<?php
/**
 * Plugin Name:       Chunked SQL Importer
 * Plugin URI:        https://github.com/example/chunked-sql-importer
 * Description:       Import large SQL dump files in resumable chunks with logging and progress tracking.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Loreto G. Gabawa Jr.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chunked-sql-importer
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSI_VERSION', '1.1.0' );
define( 'CSI_PLUGIN_FILE', __FILE__ );
define( 'CSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CSI_PLUGIN_DIR . 'includes/class-logger.php';
require_once CSI_PLUGIN_DIR . 'includes/class-queue-repository.php';
require_once CSI_PLUGIN_DIR . 'includes/class-activator.php';
require_once CSI_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'CSI_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CSI_Activator', 'deactivate' ) );

CSI_Plugin::instance();
