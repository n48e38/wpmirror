<?php
/**
 * Plugin Name:       WP Mirror
 * Plugin URI:        https://github.com/example/wp-mirror
 * Description:       Export your WordPress site as a self-contained static site, optionally create ZIP archives, and deploy to GitHub (GitHub Pages compatible).
 * Version:           1.0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Mirror Contributors
 * Author URI:        https://github.com/example/wp-mirror
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       wp-mirror
 * Domain Path:       /languages
 *
 * WP Mirror is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_MIRROR_VERSION', '1.0.3.1' );
define( 'WP_MIRROR_SLUG', 'wp-mirror' );
define( 'WP_MIRROR_TEXT_DOMAIN', 'wp-mirror' );
define( 'WP_MIRROR_PLUGIN_FILE', __FILE__ );
define( 'WP_MIRROR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MIRROR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-plugin.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-settings.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-background-jobs.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-exporter.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-assets.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-zip.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-github.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-restore.php';
require_once WP_MIRROR_PLUGIN_DIR . 'includes/class-wpmirror-admin-ui.php';

WPMirror_Plugin::instance();
