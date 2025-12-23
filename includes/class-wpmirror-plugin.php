<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Plugin {

    /** @var WPMirror_Plugin|null */
    private static $instance = null;

    /** @var WPMirror_Settings */
    public $settings;

    /** @var WPMirror_Background_Jobs */
    public $jobs;

    /** @var WPMirror_Admin_UI */
    public $admin;

    private function __construct() {}

    public static function instance() : self {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    private function bootstrap() : void {
        $this->settings = new WPMirror_Settings();
        $this->jobs     = new WPMirror_Background_Jobs( $this->settings );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        if ( is_admin() ) {
            $this->admin = new WPMirror_Admin_UI( $this->settings, $this->jobs );
        }

        // Cron worker.
        add_action( 'wp_mirror_run_jobs', array( $this->jobs, 'cron_tick' ) );

        // AJAX endpoints for UI polling and actions.
        add_action( 'wp_ajax_wp_mirror_status', array( $this->jobs, 'ajax_status' ) );
        add_action( 'wp_ajax_wp_mirror_cancel_deploy', array( $this->jobs, 'ajax_cancel_deploy' ) );
        add_action( 'wp_ajax_wp_mirror_retry_failed', array( $this->jobs, 'ajax_retry_failed' ) );
        add_action( 'wp_ajax_wp_mirror_test_github', array( $this->jobs, 'ajax_test_github' ) );

        register_activation_hook( WP_MIRROR_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WP_MIRROR_PLUGIN_FILE, array( $this, 'deactivate' ) );
    }

    public function load_textdomain() : void {
        load_plugin_textdomain(
            WP_MIRROR_TEXT_DOMAIN,
            false,
            dirname( plugin_basename( WP_MIRROR_PLUGIN_FILE ) ) . '/languages'
        );
    }

    public function activate() : void {
        // Jobs use single events; nothing to schedule here.
    }

    public function deactivate() : void {
        // Best effort: unschedule any pending single events.
        $timestamp = wp_next_scheduled( 'wp_mirror_run_jobs' );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wp_mirror_run_jobs' );
            $timestamp = wp_next_scheduled( 'wp_mirror_run_jobs' );
        }
    }
}
