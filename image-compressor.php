<?php
/**
 * Plugin Name: ReloadWP Image Optimizer
 * Description: Compresses uploaded JPEG and PNG images; optionally converts them to WebP and strips EXIF metadata.
 * Version: 1.0.0
 * Author: Andrei Alba
 * Author URI: https://github.com/andreialba
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Text Domain: image-compressor
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMAGE_COMPRESSOR_FILE', __FILE__ );
define( 'IMAGE_COMPRESSOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMAGE_COMPRESSOR_URL', plugin_dir_url( __FILE__ ) );
define( 'IMAGE_COMPRESSOR_BASENAME', plugin_basename( __FILE__ ) );
define( 'IMAGE_COMPRESSOR_VERSION', '1.0.0' );

require_once IMAGE_COMPRESSOR_DIR . 'includes/helpers.php';
require_once IMAGE_COMPRESSOR_DIR . 'includes/admin.php';
require_once IMAGE_COMPRESSOR_DIR . 'includes/core.php';
require_once IMAGE_COMPRESSOR_DIR . 'includes/media.php';

register_activation_hook( __FILE__, 'image_compressor_activate' );
register_uninstall_hook( __FILE__, 'image_compressor_uninstall' );
