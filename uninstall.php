<?php
/**
 * Uninstall WP Mirror.
 *
 * @package wp-mirror
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wp_mirror_settings' );
delete_option( 'wp_mirror_job_state' );
delete_option( 'wp_mirror_last_deploy_manifest' );
