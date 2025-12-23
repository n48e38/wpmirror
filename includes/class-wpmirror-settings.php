<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Settings {

    const OPTION_KEY = 'wp_mirror_settings';

    public function defaults() : array {
        return array(
            'public_base_url'         => home_url( '/' ),
            'export_dir'              => trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . 'wp-mirror-export',
            'asset_scope_mode'        => 'referenced', // referenced|balanced
            'ignore_enabled'          => 1,
            'ignore_patterns'         => implode( "\n", array(
                'cache',
                'caches',
                'backup',
                'backups',
                'log',
                'logs',
                'node_modules',
                '.git',
                'vendor',
                'wflogs',
                'wp-content/cache',
                'wp-content/backups',
            ) ),
            'zip_enabled'             => 1,
            'github_enabled'          => 0,
            'github_owner'            => '',
            'github_repo'             => '',
            'github_branch'           => 'gh-pages',
            'github_path_prefix'      => '',
            'github_token'            => '',
            'github_cname'            => '',
            'github_nojekyll'         => 1,
            'github_clean_removed'    => 0,
            'export_batch_urls'       => 5,
            'asset_batch_files'       => 25,
            'zip_batch_files'         => 200,
            'github_batch_files'      => 15,
            'http_timeout'            => 15,
        );
    }

    public function register() : void {
        register_setting(
            'wp_mirror_settings_group',
            self::OPTION_KEY,
            array( $this, 'sanitize' )
        );
    }

    public function get() : array {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, $this->defaults() );
    }

    public function get_value( string $key, $default = null ) {
        $all = $this->get();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    public function sanitize( $input ) : array {
        $defaults = $this->defaults();
        $out      = array();

        $out['public_base_url'] = isset( $input['public_base_url'] ) ? esc_url_raw( trim( (string) $input['public_base_url'] ) ) : $defaults['public_base_url'];

        $export_dir = isset( $input['export_dir'] ) ? wp_normalize_path( trim( (string) $input['export_dir'] ) ) : $defaults['export_dir'];
        $out['export_dir'] = rtrim( $export_dir, "/\\" );

        $mode = isset( $input['asset_scope_mode'] ) ? sanitize_text_field( (string) $input['asset_scope_mode'] ) : $defaults['asset_scope_mode'];
        $out['asset_scope_mode'] = in_array( $mode, array( 'referenced', 'balanced' ), true ) ? $mode : $defaults['asset_scope_mode'];

        $out['ignore_enabled']  = isset( $input['ignore_enabled'] ) ? absint( $input['ignore_enabled'] ) : 0;
        $out['ignore_patterns'] = isset( $input['ignore_patterns'] ) ? sanitize_textarea_field( (string) $input['ignore_patterns'] ) : $defaults['ignore_patterns'];

        $out['zip_enabled'] = isset( $input['zip_enabled'] ) ? absint( $input['zip_enabled'] ) : 0;

        $out['github_enabled']       = isset( $input['github_enabled'] ) ? absint( $input['github_enabled'] ) : 0;
        $out['github_owner']         = isset( $input['github_owner'] ) ? sanitize_text_field( (string) $input['github_owner'] ) : '';
        $out['github_repo']          = isset( $input['github_repo'] ) ? sanitize_text_field( (string) $input['github_repo'] ) : '';
        $out['github_branch']        = isset( $input['github_branch'] ) ? sanitize_text_field( (string) $input['github_branch'] ) : 'gh-pages';
        $out['github_path_prefix']   = isset( $input['github_path_prefix'] ) ? sanitize_text_field( (string) $input['github_path_prefix'] ) : '';
        $out['github_token']         = isset( $input['github_token'] ) ? sanitize_text_field( (string) $input['github_token'] ) : '';
        $out['github_cname']         = isset( $input['github_cname'] ) ? sanitize_text_field( (string) $input['github_cname'] ) : '';
        $out['github_nojekyll']      = isset( $input['github_nojekyll'] ) ? absint( $input['github_nojekyll'] ) : 0;
        $out['github_clean_removed'] = isset( $input['github_clean_removed'] ) ? absint( $input['github_clean_removed'] ) : 0;

        $out['export_batch_urls']  = isset( $input['export_batch_urls'] ) ? max( 1, min( 50, absint( $input['export_batch_urls'] ) ) ) : $defaults['export_batch_urls'];
        $out['asset_batch_files']  = isset( $input['asset_batch_files'] ) ? max( 1, min( 200, absint( $input['asset_batch_files'] ) ) ) : $defaults['asset_batch_files'];
        $out['zip_batch_files']    = isset( $input['zip_batch_files'] ) ? max( 50, min( 2000, absint( $input['zip_batch_files'] ) ) ) : $defaults['zip_batch_files'];
        $out['github_batch_files'] = isset( $input['github_batch_files'] ) ? max( 1, min( 100, absint( $input['github_batch_files'] ) ) ) : $defaults['github_batch_files'];

        $out['http_timeout'] = isset( $input['http_timeout'] ) ? max( 5, min( 60, absint( $input['http_timeout'] ) ) ) : $defaults['http_timeout'];

        return wp_parse_args( $out, $defaults );
    }

    public function get_github_token() : string {
        if ( defined( 'WP_MIRROR_GITHUB_TOKEN' ) && is_string( WP_MIRROR_GITHUB_TOKEN ) && WP_MIRROR_GITHUB_TOKEN !== '' ) {
            return (string) WP_MIRROR_GITHUB_TOKEN;
        }
        return (string) $this->get_value( 'github_token', '' );
    }
}
