<?php

/**
 * ELK 301 Migrator
 *
 * @package           ELK_301_Migrator
 * @author            Gabriele Coquillard
 * @copyright         2026 Gabriele Coquillard @ ELK-Lab
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       ELK 301 Migrator
 * Plugin URI:        https://www.elk-lab.com
 * Description:       Scans the site for all public URLs and generates a 301 redirection table ready to be compiled and used.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Gabriele Coquillard @ ELK-Lab
 * Author URI:        https://www.elk-lab.com
 * Text Domain:       elk-301-migrator
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'ELK_301_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ELK_301_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'ELK_301_MIGRATOR_VERSION', '1.0.0' );

require_once ELK_301_MIGRATOR_PATH . 'includes/scanner.php';
require_once ELK_301_MIGRATOR_PATH . 'includes/exporter.php';
require_once ELK_301_MIGRATOR_PATH . 'includes/admin.php';
