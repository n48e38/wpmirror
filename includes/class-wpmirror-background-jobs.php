<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Background_Jobs {

    const STATE_OPTION = 'wp_mirror_job_state';
    const LAST_DEPLOY_MANIFEST_OPTION = 'wp_mirror_last_deploy_manifest';

    private $settings;
    private $assets;
    private $exporter;
    private $zipper;
    private $github;

    public function __construct( WPMirror_Settings $settings ) {
        $this->settings = $settings;
        $this->assets   = new WPMirror_Assets();
        $this->exporter = new WPMirror_Exporter( $settings, $this->assets );
        $this->zipper   = new WPMirror_Zip();
        $this->github   = new WPMirror_GitHub();
    }

    public function default_state() : array {
        return array(
            'job_id'           => '',
            'type'             => '',
            'status'           => 'idle',
            'stage'            => '',
            'progress'         => array( 'current' => 0, 'total' => 0 ),
            'message'          => '',
            'started_at'       => 0,
            'updated_at'       => 0,
            'log'              => array(),
            'errors'           => array(),
            'export'           => array(),
            'deploy'           => array(),
            'cancel_requested' => 0,
        );
    }

    public function get_state() : array {
        $st = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $st ) ) { $st = array(); }
        return wp_parse_args( $st, $this->default_state() );
    }

    private function update_state( array $state ) : void {
        $state['updated_at'] = time();
        update_option( self::STATE_OPTION, $state, false );
    }

    private function log( string $line ) : void {
        $state = $this->get_state();
        $ts = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $state['log'][] = '[' . $ts . '] ' . $line;
        if ( count( $state['log'] ) > 500 ) {
            $state['log'] = array_slice( $state['log'], -500 );
        }
        $this->update_state( $state );
    }

    private function fail( string $message, $details = null ) : void {
        $state = $this->get_state();
        $state['status'] = 'failed';
        $state['message'] = $message;
        if ( $details ) {
            $state['errors'][] = is_string( $details ) ? $details : wp_json_encode( $details );
        }
        $this->update_state( $state );
        $this->log( 'ERROR: ' . $message );
    }

    private function schedule_tick( int $in_seconds = 5 ) : void {
        wp_schedule_single_event( time() + max( 1, $in_seconds ), 'wp_mirror_run_jobs' );
    }

    private function with_lock( callable $fn ) : void {
        if ( get_transient( 'wp_mirror_lock' ) ) { return; }
        set_transient( 'wp_mirror_lock', 1, 60 );
        try { $fn(); } finally { delete_transient( 'wp_mirror_lock' ); }
    }

    public function start_export() : void {
        $s = $this->settings->get();
        $state = $this->default_state();
        $state['job_id'] = wp_generate_uuid4();
        $state['type'] = 'export';
        $state['status'] = 'running';
        $state['stage'] = 'discover';
        $state['started_at'] = time();
        $state['message'] = __( 'Export started.', 'wp-mirror' );
        $state['export'] = array(
            'export_dir'       => (string) $s['export_dir'],
            'public_base_url'  => (string) $s['public_base_url'],
            'asset_scope_mode' => (string) $s['asset_scope_mode'],
            'ignore_enabled'   => absint( $s['ignore_enabled'] ),
            'ignore_patterns'  => (string) $s['ignore_patterns'],
            'urls'             => array(),
            'urls_index'       => 0,
            'asset_queue'      => array(),
            'asset_seen'       => array(),
            'asset_index'      => 0,
            'zip_enabled'      => absint( $s['zip_enabled'] ),
            'zip_state'        => array(),
            'result'           => array(),
        );
        $this->update_state( $state );
        $this->log( 'Export job queued.' );
        $this->schedule_tick( 3 );
    }

    public function start_deploy() : void {
        $s = $this->settings->get();
        $token = $this->settings->get_github_token();

        if ( absint( $s['github_enabled'] ) !== 1 ) {
            $this->fail( __( 'GitHub deploy is disabled in settings.', 'wp-mirror' ) );
            return;
        }
        if ( $s['github_owner'] === '' || $s['github_repo'] === '' || $s['github_branch'] === '' ) {
            $this->fail( __( 'GitHub Owner/Repo/Branch must be set.', 'wp-mirror' ) );
            return;
        }
        if ( $token === '' ) {
            $this->fail( __( 'GitHub token is missing. Set it in settings or define WP_MIRROR_GITHUB_TOKEN.', 'wp-mirror' ) );
            return;
        }

        $export_dir = (string) $s['export_dir'];
        if ( ! is_dir( $export_dir ) ) {
            $this->fail( __( 'Export directory does not exist. Run export first.', 'wp-mirror' ) );
            return;
        }

        $state = $this->default_state();
        $state['job_id'] = wp_generate_uuid4();
        $state['type'] = 'deploy';
        $state['status'] = 'running';
        $state['stage'] = 'init';
        $state['started_at'] = time();
        $state['message'] = __( 'Deploy started.', 'wp-mirror' );
        $state['deploy'] = array(
            'owner'         => (string) $s['github_owner'],
            'repo'          => (string) $s['github_repo'],
            'branch'        => (string) $s['github_branch'],
            'path_prefix'   => trim( (string) $s['github_path_prefix'] ),
            'nojekyll'      => absint( $s['github_nojekyll'] ),
            'cname'         => trim( (string) $s['github_cname'] ),
            'clean_removed' => absint( $s['github_clean_removed'] ),
            'token'         => $token,
            'export_dir'    => $export_dir,
            'manifest_file' => trailingslashit( wp_normalize_path( $export_dir ) ) . '.wp-mirror-manifest.json',
            'queue'         => array(),
            'queue_index'   => 0,
            'failed'        => array(),
            'base_commit'   => '',
            'base_tree'     => '',
            'pause_until'   => 0,
            'pause_reason'  => '',
            'last_commit'   => '',
            'del_index'     => 0,
        );

        $this->update_state( $state );
        $this->log( 'Deploy job queued.' );
        $this->schedule_tick( 3 );
    }

    public function cron_tick() : void {
        $this->with_lock( function() {
            $state = $this->get_state();
            if ( $state['status'] !== 'running' && $state['status'] !== 'paused' ) { return; }

            if ( $state['status'] === 'paused' ) {
                $until = absint( $state['deploy']['pause_until'] ?? 0 );
                if ( $until > time() ) {
                    $this->schedule_tick( min( 60, max( 5, $until - time() ) ) );
                    return;
                }
                $state['status'] = 'running';
                $state['deploy']['pause_reason'] = '';
                $this->update_state( $state );
                $this->log( 'Resuming after pause.' );
            }

            if ( $state['type'] === 'export' ) { $this->run_export_tick( $state ); return; }
            if ( $state['type'] === 'deploy' ) { $this->run_deploy_tick( $state ); return; }
        } );
    }

    private function run_export_tick( array $state ) : void {
        $export_dir = (string) $state['export']['export_dir'];
        $public_base_url = (string) $state['export']['public_base_url'];

        $ok = $this->exporter->ensure_export_dir( $export_dir );
        if ( is_wp_error( $ok ) ) {
            $this->fail( $ok->get_error_message(), $ok->get_error_data() );
            return;
        }

        if ( $state['stage'] === 'discover' ) {
            $this->log( 'Discovering URLs...' );
            $urls = $this->exporter->discover_urls();
            $state['export']['urls'] = $urls;
            $state['export']['urls_index'] = 0;
            $state['stage'] = 'export_html';
            $state['progress'] = array( 'current' => 0, 'total' => count( $urls ) );
            $state['message'] = __( 'Exporting HTML...', 'wp-mirror' );
            $this->update_state( $state );
            $this->log( sprintf( 'Discovered %d URLs.', count( $urls ) ) );
            $this->schedule_tick( 3 );
            return;
        }

        if ( $state['stage'] === 'export_html' ) {
            $batch = absint( $this->settings->get_value( 'export_batch_urls', 5 ) );
            $urls  = (array) $state['export']['urls'];
            $i     = absint( $state['export']['urls_index'] );

            $ignore_enabled = absint( $state['export']['ignore_enabled'] ) === 1;
            $ignore_patterns = $ignore_enabled ? $this->assets->parse_ignore_patterns( (string) $state['export']['ignore_patterns'] ) : array();

            $processed = 0;
            while ( $i < count( $urls ) && $processed < $batch ) {
                $url = (string) $urls[ $i ];
                $i++; $processed++;

                $fetch = $this->exporter->fetch_html( $url );
                if ( is_wp_error( $fetch ) ) {
                    $this->log( 'Fetch failed: ' . $url . ' — ' . $fetch->get_error_message() );
                    $state['errors'][] = $url . ': ' . $fetch->get_error_message();
                    continue;
                }

                list( $html, $headers ) = $fetch;
                $html = $this->exporter->rewrite_internal_urls( (string) $html, $public_base_url );

                $dest = $this->exporter->map_url_to_export_path( $url, $export_dir );
                $write = $this->exporter->write_html( $dest, $html );
                if ( is_wp_error( $write ) ) {
                    $this->log( 'Write failed: ' . $dest . ' — ' . $write->get_error_message() );
                    $state['errors'][] = $dest . ': ' . $write->get_error_message();
                    continue;
                }

                $asset_urls = $this->assets->collect_from_html( $html, $public_base_url );
                foreach ( $asset_urls as $asset_url ) {
                    $mapped = $this->assets->map_url_to_file( (string) $asset_url, $public_base_url, $export_dir );
                    if ( ! $mapped ) { continue; }
                    $dest_abs = (string) $mapped[1];
                    $rel = ltrim( str_replace( trailingslashit( wp_normalize_path( $export_dir ) ), '', wp_normalize_path( $dest_abs ) ), '/' );

                    if ( $ignore_enabled && $this->assets->is_ignored( $rel, $ignore_patterns ) ) { continue; }

                    if ( ! isset( $state['export']['asset_seen'][ $rel ] ) ) {
                        $state['export']['asset_seen'][ $rel ] = 1;
                        $state['export']['asset_queue'][] = array(
                            'dest' => $dest_abs,
                            'src'  => (string) $mapped[0],
                            'rel'  => $rel,
                        );
                    }
                }

                $this->log( 'Exported: ' . $url );
            }

            $state['export']['urls_index'] = $i;
            $state['progress']['current'] = min( $i, $state['progress']['total'] );

            if ( $i >= count( $urls ) ) {
                if ( (string) $state['export']['asset_scope_mode'] === 'balanced' ) {
                    $this->log( 'Balanced mode: adding uploads and theme assets.' );
                    $this->enqueue_balanced_assets( $state, $ignore_patterns, $ignore_enabled );
                }

                $state['stage'] = 'copy_assets';
                $state['message'] = __( 'Copying assets...', 'wp-mirror' );
                $state['progress'] = array( 'current' => 0, 'total' => count( (array) $state['export']['asset_queue'] ) );
                $this->update_state( $state );
                $this->schedule_tick( 3 );
                return;
            }

            $this->update_state( $state );
            $this->schedule_tick( 3 );
            return;
        }

        if ( $state['stage'] === 'copy_assets' ) {
            $batch = absint( $this->settings->get_value( 'asset_batch_files', 25 ) );
            $q     = (array) $state['export']['asset_queue'];
            $i     = absint( $state['export']['asset_index'] );

            $ignore_enabled = absint( $state['export']['ignore_enabled'] ) === 1;
            $ignore_patterns = $ignore_enabled ? $this->assets->parse_ignore_patterns( (string) $state['export']['ignore_patterns'] ) : array();

            $processed = 0;
            while ( $i < count( $q ) && $processed < $batch ) {
                $item = $q[ $i ];
                $i++; $processed++;

                $src_abs = (string) ( $item['src'] ?? '' );
                $dest_abs = (string) ( $item['dest'] ?? '' );
                $rel = (string) ( $item['rel'] ?? '' );
                if ( $src_abs === '' || $dest_abs === '' ) { continue; }
                if ( $ignore_enabled && $this->assets->is_ignored( $rel, $ignore_patterns ) ) { continue; }

                if ( is_file( $dest_abs ) && filesize( $dest_abs ) === filesize( $src_abs ) ) { continue; }

                $copied = $this->assets->copy_one( $src_abs, $dest_abs );
                if ( is_wp_error( $copied ) ) {
                    $this->log( 'Asset copy failed: ' . $rel . ' — ' . $copied->get_error_message() );
                    $state['errors'][] = 'asset:' . $rel . ': ' . $copied->get_error_message();
                    continue;
                }

                $ext = strtolower( pathinfo( $dest_abs, PATHINFO_EXTENSION ) );
                if ( $ext === 'css' ) {
                    $css = (string) file_get_contents( $dest_abs );
                    foreach ( $this->assets->collect_from_css( $css ) as $dep ) {
                        $css_url_path = '/' . ltrim( $rel, '/' );
                        $resolved = $this->assets->resolve_css_dep( (string) $dep, $css_url_path );
                        if ( ! $resolved ) { continue; }
                        $dep_url = $resolved;
                        if ( strpos( $resolved, '/' ) === 0 ) {
                            $dep_url = rtrim( $public_base_url, '/' ) . $resolved;
                        }
                        $mapped = $this->assets->map_url_to_file( (string) $dep_url, $public_base_url, $export_dir );
                        if ( ! $mapped ) { continue; }
                        $dep_dest_abs = (string) $mapped[1];
                        $dep_rel = ltrim( str_replace( trailingslashit( wp_normalize_path( $export_dir ) ), '', wp_normalize_path( $dep_dest_abs ) ), '/' );
                        if ( $ignore_enabled && $this->assets->is_ignored( $dep_rel, $ignore_patterns ) ) { continue; }
                        if ( ! isset( $state['export']['asset_seen'][ $dep_rel ] ) ) {
                            $state['export']['asset_seen'][ $dep_rel ] = 1;
                            $state['export']['asset_queue'][] = array(
                                'dest' => $dep_dest_abs,
                                'src'  => (string) $mapped[0],
                                'rel'  => $dep_rel,
                            );
                            $q[] = end( $state['export']['asset_queue'] );
                            $state['progress']['total'] = count( (array) $state['export']['asset_queue'] );
                        }
                    }
                }

                $this->log( 'Copied asset: ' . $rel );
            }

            $state['export']['asset_index'] = $i;
            $state['progress']['current'] = min( $i, $state['progress']['total'] );

            if ( $i >= count( (array) $state['export']['asset_queue'] ) ) {
                $state['stage'] = 'finalize';
                $state['message'] = __( 'Finalizing export...', 'wp-mirror' );
                $this->update_state( $state );
                $this->schedule_tick( 3 );
                return;
            }

            $this->update_state( $state );
            $this->schedule_tick( 3 );
            return;
        }

        if ( $state['stage'] === 'finalize' ) {
            $manifest_path = trailingslashit( wp_normalize_path( $export_dir ) ) . '.wp-mirror-manifest.json';
            $manifest = $this->build_manifest( $export_dir );
            file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

            $stats = $this->folder_stats( $export_dir );
            $state['export']['result'] = array(
                'file_count' => $stats['files'],
                'total_bytes'=> $stats['bytes'],
                'finished_at'=> time(),
                'manifest_path' => $manifest_path,
            );

            $this->log( sprintf( 'Export complete. Files: %d, Size: %s', (int) $stats['files'], size_format( (int) $stats['bytes'] ) ) );

            if ( absint( $state['export']['zip_enabled'] ) === 1 ) {
                $state['stage'] = 'zip_prepare';
                $state['message'] = __( 'Preparing ZIP...', 'wp-mirror' );
                $this->update_state( $state );
                $this->schedule_tick( 3 );
                return;
            }

            $state['status'] = 'completed';
            $state['message'] = __( 'Export completed.', 'wp-mirror' );
            $this->update_state( $state );
            return;
        }

        if ( $state['stage'] === 'zip_prepare' ) {
            $archives_dir = $this->zipper->ensure_archives_dir( $export_dir );
            if ( is_wp_error( $archives_dir ) ) {
                $this->fail( $archives_dir->get_error_message(), $archives_dir->get_error_data() );
                return;
            }

            $files = $this->zipper->list_export_files( $export_dir );
            $zip_path = $this->zipper->build_zip_path( (string) $archives_dir );

            $state['export']['zip_state'] = array(
                'zip_path' => $zip_path,
                'files'    => $files,
                'index'    => 0,
            );
            $state['stage'] = 'zip_build';
            $state['progress'] = array( 'current' => 0, 'total' => count( $files ) );
            $state['message'] = __( 'Building ZIP...', 'wp-mirror' );
            $this->update_state( $state );
            $this->log( sprintf( 'ZIP started: %s (%d files).', basename( $zip_path ), count( $files ) ) );
            $this->schedule_tick( 3 );
            return;
        }

        if ( $state['stage'] === 'zip_build' ) {
            $zs = (array) $state['export']['zip_state'];
            $files = is_array( $zs['files'] ?? null ) ? $zs['files'] : array();
            $index = absint( $zs['index'] ?? 0 );
            $zip_path = (string) ( $zs['zip_path'] ?? '' );

            $batch = absint( $this->settings->get_value( 'zip_batch_files', 200 ) );
            list( $new_index, $added, $err ) = $this->zipper->add_batch( $zip_path, $export_dir, $files, $index, $batch );
            if ( $err instanceof WP_Error ) {
                $this->fail( $err->get_error_message(), $err->get_error_data() );
                return;
            }

            $zs['index'] = $new_index;
            $state['export']['zip_state'] = $zs;
            $state['progress']['current'] = min( $new_index, count( $files ) );

            if ( $new_index >= count( $files ) ) {
                $state['status'] = 'completed';
                $state['message'] = __( 'Export completed (ZIP created).', 'wp-mirror' );
                $state['export']['result']['zip_path'] = $zip_path;
                $this->update_state( $state );
                $this->log( 'ZIP complete: ' . basename( $zip_path ) );
                return;
            }

            $this->update_state( $state );
            $this->schedule_tick( 3 );
            return;
        }
    }

    private function enqueue_balanced_assets( array &$state, array $ignore_patterns, bool $ignore_enabled ) : void {
        $export_dir = (string) $state['export']['export_dir'];

        $uploads = wp_upload_dir();
        if ( isset( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) ) {
            $this->enqueue_dir_assets( wp_normalize_path( $uploads['basedir'] ), wp_normalize_path( WP_CONTENT_DIR ) . '/uploads', $export_dir, $state, $ignore_patterns, $ignore_enabled );
        }

        $theme_dirs = array_unique( array_filter( array(
            wp_normalize_path( get_stylesheet_directory() ),
            wp_normalize_path( get_template_directory() ),
        ) ) );

        foreach ( $theme_dirs as $td ) {
            $rel_in_wp = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $td ) );
            $rel_in_wp = ltrim( $rel_in_wp, '/' );
            $this->enqueue_dir_assets( $td, $rel_in_wp, $export_dir, $state, $ignore_patterns, $ignore_enabled );
        }
    }

    private function enqueue_dir_assets( string $src_dir_abs, string $rel_root_under_wp, string $export_dir, array &$state, array $ignore_patterns, bool $ignore_enabled ) : void {
        if ( ! is_dir( $src_dir_abs ) ) { return; }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src_dir_abs, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $abs = wp_normalize_path( $file->getPathname() );
            if ( preg_match( '/\.php$/i', $abs ) ) { continue; }

            $rel_in_src = ltrim( str_replace( trailingslashit( wp_normalize_path( $src_dir_abs ) ), '', $abs ), '/' );
            $rel = trim( $rel_root_under_wp, '/' ) . '/' . $rel_in_src;

            if ( $ignore_enabled && $this->assets->is_ignored( $rel, $ignore_patterns ) ) { continue; }

            if ( ! isset( $state['export']['asset_seen'][ $rel ] ) ) {
                $state['export']['asset_seen'][ $rel ] = 1;
                $dest_abs = trailingslashit( wp_normalize_path( $export_dir ) ) . $rel;
                $state['export']['asset_queue'][] = array(
                    'dest' => $dest_abs,
                    'src'  => $abs,
                    'rel'  => $rel,
                );
            }
        }
    }

    private function build_manifest( string $export_dir ) : array {
        $export_dir = wp_normalize_path( $export_dir );
        $manifest = array();

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $export_dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $abs = wp_normalize_path( $file->getPathname() );

            $rel = ltrim( str_replace( trailingslashit( $export_dir ), '', $abs ), '/' );
            if ( strpos( $rel, '_archives/' ) === 0 ) { continue; }
            if ( $rel === '.wp-mirror-manifest.json' ) { continue; }
            if ( preg_match( '/\.php$/i', $rel ) ) { continue; }

            $manifest[ $rel ] = array(
                'size'   => (int) $file->getSize(),
                'sha256' => hash_file( 'sha256', $abs ),
            );
        }

        ksort( $manifest );
        return $manifest;
    }

    private function folder_stats( string $dir ) : array {
        $dir = wp_normalize_path( $dir );
        $files = 0;
        $bytes = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $abs = wp_normalize_path( $file->getPathname() );
            if ( strpos( $abs, trailingslashit( $dir ) . '_archives/' ) === 0 ) { continue; }
            if ( preg_match( '/\.php$/i', $abs ) ) { continue; }
            $files++;
            $bytes += (int) $file->getSize();
        }
        return array( 'files' => $files, 'bytes' => $bytes );
    }

    private function run_deploy_tick( array $state ) : void {
        if ( absint( $state['cancel_requested'] ) === 1 ) {
            $state['status'] = 'cancelled';
            $state['message'] = __( 'Deploy cancelled.', 'wp-mirror' );
            $this->update_state( $state );
            $this->log( 'Deploy cancelled by user.' );
            return;
        }

        $d = (array) $state['deploy'];
        $owner  = (string) ( $d['owner'] ?? '' );
        $repo   = (string) ( $d['repo'] ?? '' );
        $branch = (string) ( $d['branch'] ?? '' );
        $token  = (string) ( $d['token'] ?? '' );
        $export_dir = (string) ( $d['export_dir'] ?? '' );

        if ( $state['stage'] === 'init' ) {
            $this->log( 'Initializing GitHub deploy...' );

            $manifest_file = (string) ( $d['manifest_file'] ?? '' );
            if ( ! is_file( $manifest_file ) ) {
                $manifest = $this->build_manifest( $export_dir );
                file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
            } else {
                $raw = file_get_contents( $manifest_file );
                $manifest = json_decode( (string) $raw, true );
                if ( ! is_array( $manifest ) ) {
                    $manifest = $this->build_manifest( $export_dir );
                    file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
                }
            }

            $last = get_option( self::LAST_DEPLOY_MANIFEST_OPTION, array() );
            if ( ! is_array( $last ) ) { $last = array(); }

            $queue = array();
            foreach ( $manifest as $rel => $meta ) {
                $old = $last[ $rel ] ?? null;
                if ( ! is_array( $old ) || ( $old['sha256'] ?? '' ) !== ( $meta['sha256'] ?? '' ) ) {
                    $queue[] = $rel;
                }
            }

            $path_prefix = trim( (string) ( $d['path_prefix'] ?? '' ) );
            $prefix_clean = $path_prefix !== '' ? trim( $path_prefix, '/' ) . '/' : '';

            if ( absint( $d['nojekyll'] ?? 0 ) === 1 ) {
                $queue[] = '__virtual__:' . $prefix_clean . '.nojekyll';
            }
            if ( ! empty( $d['cname'] ) ) {
                $queue[] = '__virtual__:' . $prefix_clean . 'CNAME';
            }

            $deletions = array();
            if ( absint( $d['clean_removed'] ?? 0 ) === 1 ) {
                foreach ( $last as $rel => $meta ) {
                    if ( ! isset( $manifest[ $rel ] ) ) { $deletions[] = $rel; }
                }
            }

            $d['queue'] = $queue;
            $d['queue_index'] = 0;
            $d['failed'] = array();
            $d['manifest'] = $manifest;
            $d['deletions'] = $deletions;
            $d['del_index'] = 0;

            $ref = $this->github->get_branch_ref( $owner, $repo, $branch, $token );
            if ( is_wp_error( $ref ) ) { $this->fail( $ref->get_error_message(), $ref->get_error_data() ); return; }
            list( $code, $headers, $body ) = $ref;
            if ( $this->is_rate_limited( $code, $headers ) ) { $this->pause_due_to_rate_limit( $headers, __( 'Rate limited while reading branch ref.', 'wp-mirror' ) ); return; }
            if ( $code !== 200 || ! is_array( $body ) || empty( $body['object']['sha'] ) ) {
                $this->fail( __( 'Failed to read branch ref. Ensure branch exists and token has repo permissions.', 'wp-mirror' ), $body );
                return;
            }
            $base_commit = (string) $body['object']['sha'];

            $commit = $this->github->get_commit( $owner, $repo, $base_commit, $token );
            if ( is_wp_error( $commit ) ) { $this->fail( $commit->get_error_message(), $commit->get_error_data() ); return; }
            list( $ccode, $cheaders, $cbody ) = $commit;
            if ( $this->is_rate_limited( $ccode, $cheaders ) ) { $this->pause_due_to_rate_limit( $cheaders, __( 'Rate limited while reading commit.', 'wp-mirror' ) ); return; }
            if ( $ccode !== 200 || ! is_array( $cbody ) || empty( $cbody['tree']['sha'] ) ) {
                $this->fail( __( 'Failed to read base commit tree.', 'wp-mirror' ), $cbody );
                return;
            }
            $base_tree = (string) $cbody['tree']['sha'];

            $d['base_commit'] = $base_commit;
            $d['base_tree'] = $base_tree;

            $state['deploy'] = $d;
            $state['stage'] = 'push_batches';
            $state['progress'] = array( 'current' => 0, 'total' => count( $queue ) + count( $deletions ) );
            $state['message'] = __( 'Deploying to GitHub...', 'wp-mirror' );
            $this->update_state( $state );

            $this->log( sprintf( 'Deploy queue prepared. Changed/new files: %d, deletions: %d', count( $queue ), count( $deletions ) ) );
            $this->schedule_tick( 3 );
            return;
        }

        if ( $state['stage'] === 'push_batches' ) {
            $batch = absint( $this->settings->get_value( 'github_batch_files', 15 ) );
            $queue = is_array( $d['queue'] ?? null ) ? $d['queue'] : array();
            $deletions = is_array( $d['deletions'] ?? null ) ? $d['deletions'] : array();
            $index = absint( $d['queue_index'] ?? 0 );

            $base_commit = (string) ( $d['base_commit'] ?? '' );
            $base_tree = (string) ( $d['base_tree'] ?? '' );
            $path_prefix = trim( (string) ( $d['path_prefix'] ?? '' ) );
            $prefix_clean = $path_prefix !== '' ? trim( $path_prefix, '/' ) . '/' : '';

            $tree_entries = array();
            $processed = 0;

            while ( $index < count( $queue ) && $processed < $batch ) {
                $rel = (string) $queue[ $index ];
                $index++; $processed++;

                if ( strpos( $rel, '__virtual__:' ) === 0 ) {
                    $virtual_path = substr( $rel, strlen( '__virtual__:' ) );
                    $content = '';
                    if ( substr( $virtual_path, -5 ) === 'CNAME' ) { $content = (string) ( $d['cname'] ?? '' ); }

                    $blob = $this->github->create_blob( $owner, $repo, $token, base64_encode( $content ) );
                    if ( is_wp_error( $blob ) ) { $d['failed'][] = $rel; continue; }
                    list( $bcode, $bheaders, $bbody ) = $blob;
                    if ( $this->is_rate_limited( $bcode, $bheaders ) ) {
                        $d['queue_index'] = $index - 1;
                        $state['deploy'] = $d;
                        $this->update_state( $state );
                        $this->pause_due_to_rate_limit( $bheaders, __( 'Rate limited while creating blob.', 'wp-mirror' ) );
                        return;
                    }
                    if ( $bcode !== 201 || ! is_array( $bbody ) || empty( $bbody['sha'] ) ) { $d['failed'][] = $rel; continue; }

                    $tree_entries[] = array( 'path' => $virtual_path, 'mode' => '100644', 'type' => 'blob', 'sha' => (string) $bbody['sha'] );
                    continue;
                }

                if ( preg_match( '/\.php$/i', $rel ) ) { continue; }

                $abs = trailingslashit( wp_normalize_path( $export_dir ) ) . ltrim( $rel, '/' );
                if ( ! is_file( $abs ) ) { $d['failed'][] = $rel; continue; }

                $data = file_get_contents( $abs );
                if ( false === $data ) { $d['failed'][] = $rel; continue; }

                $blob = $this->github->create_blob( $owner, $repo, $token, base64_encode( (string) $data ) );
                if ( is_wp_error( $blob ) ) { $d['failed'][] = $rel; continue; }
                list( $bcode, $bheaders, $bbody ) = $blob;
                if ( $this->is_rate_limited( $bcode, $bheaders ) ) {
                    $d['queue_index'] = $index - 1;
                    $state['deploy'] = $d;
                    $this->update_state( $state );
                    $this->pause_due_to_rate_limit( $bheaders, __( 'Rate limited while creating blob.', 'wp-mirror' ) );
                    return;
                }
                if ( $bcode !== 201 || ! is_array( $bbody ) || empty( $bbody['sha'] ) ) { $d['failed'][] = $rel; continue; }

                $tree_entries[] = array(
                    'path' => $prefix_clean . $rel,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha'  => (string) $bbody['sha'],
                );
            }

            if ( $index >= count( $queue ) && ! empty( $deletions ) && $processed < $batch ) {
                $del_index = absint( $d['del_index'] ?? 0 );
                while ( $del_index < count( $deletions ) && $processed < $batch ) {
                    $rel_del = (string) $deletions[ $del_index ];
                    $del_index++; $processed++;

                    $tree_entries[] = array(
                        'path' => $prefix_clean . $rel_del,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha'  => null,
                    );
                }
                $d['del_index'] = $del_index;
            }

            if ( empty( $tree_entries ) ) {
                $d['queue_index'] = $index;
                $state['deploy'] = $d;
                $this->update_state( $state );

                $done_files = ( $index >= count( $queue ) );
                $done_dels = empty( $deletions ) || ( absint( $d['del_index'] ?? 0 ) >= count( $deletions ) );
                if ( $done_files && $done_dels ) { $this->finalize_deploy( $state ); return; }

                $this->schedule_tick( 3 );
                return;
            }

            $tree = $this->github->create_tree( $owner, $repo, $token, $base_tree, $tree_entries );
            if ( is_wp_error( $tree ) ) { $this->fail( $tree->get_error_message(), $tree->get_error_data() ); return; }
            list( $tcode, $theaders, $tbody ) = $tree;
            if ( $this->is_rate_limited( $tcode, $theaders ) ) {
                $d['queue_index'] = max( 0, $index - $processed );
                $state['deploy'] = $d;
                $this->update_state( $state );
                $this->pause_due_to_rate_limit( $theaders, __( 'Rate limited while creating tree.', 'wp-mirror' ) );
                return;
            }
            if ( $tcode !== 201 || ! is_array( $tbody ) || empty( $tbody['sha'] ) ) { $this->fail( __( 'Failed to create tree.', 'wp-mirror' ), $tbody ); return; }

            $new_tree_sha = (string) $tbody['sha'];

            $msg = sprintf( 'WP Mirror deploy: %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
            $commit = $this->github->create_commit( $owner, $repo, $token, $msg, $new_tree_sha, $base_commit );
            if ( is_wp_error( $commit ) ) { $this->fail( $commit->get_error_message(), $commit->get_error_data() ); return; }
            list( $ccode, $cheaders, $cbody ) = $commit;
            if ( $this->is_rate_limited( $ccode, $cheaders ) ) {
                $d['queue_index'] = max( 0, $index - $processed );
                $state['deploy'] = $d;
                $this->update_state( $state );
                $this->pause_due_to_rate_limit( $cheaders, __( 'Rate limited while creating commit.', 'wp-mirror' ) );
                return;
            }
            if ( $ccode !== 201 || ! is_array( $cbody ) || empty( $cbody['sha'] ) || empty( $cbody['tree']['sha'] ) ) { $this->fail( __( 'Failed to create commit.', 'wp-mirror' ), $cbody ); return; }

            $new_commit_sha = (string) $cbody['sha'];
            $new_commit_tree_sha = (string) $cbody['tree']['sha'];

            $upd = $this->github->update_ref( $owner, $repo, $token, $branch, $new_commit_sha );
            if ( is_wp_error( $upd ) ) { $this->fail( $upd->get_error_message(), $upd->get_error_data() ); return; }
            list( $ucode, $uheaders, $ubody ) = $upd;
            if ( $this->is_rate_limited( $ucode, $uheaders ) ) {
                $d['queue_index'] = max( 0, $index - $processed );
                $state['deploy'] = $d;
                $this->update_state( $state );
                $this->pause_due_to_rate_limit( $uheaders, __( 'Rate limited while updating ref.', 'wp-mirror' ) );
                return;
            }
            if ( $ucode !== 200 ) { $this->fail( __( 'Failed to update branch ref.', 'wp-mirror' ), $ubody ); return; }

            $d['base_commit'] = $new_commit_sha;
            $d['base_tree'] = $new_commit_tree_sha;
            $d['queue_index'] = $index;
            $d['last_commit'] = $new_commit_sha;

            $done_items = $index + absint( $d['del_index'] ?? 0 );
            $state['progress']['current'] = min( $done_items, $state['progress']['total'] );

            $state['deploy'] = $d;
            $this->update_state( $state );
            $this->log( sprintf( 'Committed batch. Queue: %d/%d', $index, count( $queue ) ) );

            $done_files = ( $index >= count( $queue ) );
            $done_dels = empty( $deletions ) || ( absint( $d['del_index'] ?? 0 ) >= count( $deletions ) );
            if ( $done_files && $done_dels ) { $this->finalize_deploy( $state ); return; }

            $this->schedule_tick( 3 );
            return;
        }
    }

    private function finalize_deploy( array $state ) : void {
        $d = (array) $state['deploy'];
        if ( isset( $d['manifest'] ) && is_array( $d['manifest'] ) ) {
            update_option( self::LAST_DEPLOY_MANIFEST_OPTION, $d['manifest'], false );
        }

        $failed = is_array( $d['failed'] ?? null ) ? $d['failed'] : array();
        if ( ! empty( $failed ) ) {
            $state['status'] = 'failed';
            $state['message'] = __( 'Deploy finished with failures. Use “Retry failed items”.', 'wp-mirror' );
            $state['errors'] = array_merge( (array) $state['errors'], $failed );
            $this->update_state( $state );
            $this->log( sprintf( 'Deploy finished with %d failed items.', count( $failed ) ) );
            return;
        }

        $state['status'] = 'completed';
        $state['message'] = __( 'Deploy completed.', 'wp-mirror' );
        $this->update_state( $state );
        $this->log( 'Deploy completed successfully.' );
    }

    private function is_rate_limited( int $code, array $headers ) : bool {
        if ( $code !== 403 && $code !== 429 ) { return false; }
        $remaining = isset( $headers['x-ratelimit-remaining'] ) ? (int) $headers['x-ratelimit-remaining'] : null;
        if ( null !== $remaining && $remaining <= 0 ) { return true; }
        return isset( $headers['x-ratelimit-reset'] );
    }

    private function pause_due_to_rate_limit( array $headers, string $reason ) : void {
        $reset = isset( $headers['x-ratelimit-reset'] ) ? absint( $headers['x-ratelimit-reset'] ) : 0;
        $until = $reset > 0 ? $reset + 10 : ( time() + 60 );

        $st = $this->get_state();
        $st['status'] = 'paused';
        $st['message'] = __( 'Paused due to GitHub rate limit.', 'wp-mirror' );
        $st['deploy']['pause_until'] = $until;
        $st['deploy']['pause_reason'] = $reason;
        $this->update_state( $st );

        $this->log( sprintf( 'Paused (rate limit). Resume after %s.', gmdate( 'Y-m-d H:i:s', $until ) . ' UTC' ) );
        $this->schedule_tick( min( 300, max( 30, $until - time() ) ) );
    }

    public function ajax_status() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'wp-mirror' ) ), 403 );
        }
        check_ajax_referer( 'wp_mirror_admin', 'nonce' );

        $state = $this->get_state();
        $payload = array(
            'type'       => $state['type'],
            'status'     => $state['status'],
            'stage'      => $state['stage'],
            'message'    => $state['message'],
            'progress'   => $state['progress'],
            'updated_at' => $state['updated_at'],
            'log'        => array_slice( (array) $state['log'], -200 ),
            'errors'     => array_slice( (array) $state['errors'], -50 ),
            'export'     => array(
                'export_dir' => $state['export']['export_dir'] ?? '',
                'result'     => $state['export']['result'] ?? array(),
                'zip_path'   => $state['export']['result']['zip_path'] ?? '',
            ),
            'deploy'     => array(
                'pause_reason' => $state['deploy']['pause_reason'] ?? '',
                'pause_until'  => $state['deploy']['pause_until'] ?? 0,
                'last_commit'  => $state['deploy']['last_commit'] ?? '',
                'failed_count' => isset( $state['deploy']['failed'] ) && is_array( $state['deploy']['failed'] ) ? count( $state['deploy']['failed'] ) : 0,
            ),
        );

        wp_send_json_success( $payload );
    }

    public function ajax_cancel_deploy() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'wp-mirror' ) ), 403 );
        }
        check_ajax_referer( 'wp_mirror_admin', 'nonce' );

        $state = $this->get_state();
        if ( $state['type'] !== 'deploy' || ( $state['status'] !== 'running' && $state['status'] !== 'paused' ) ) {
            wp_send_json_success( array( 'message' => __( 'No deploy in progress.', 'wp-mirror' ) ) );
        }

        $state['cancel_requested'] = 1;
        $state['message'] = __( 'Cancel requested...', 'wp-mirror' );
        $this->update_state( $state );
        $this->log( 'Cancel requested.' );

        wp_send_json_success( array( 'message' => __( 'Cancel requested.', 'wp-mirror' ) ) );
    }

    public function ajax_retry_failed() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'wp-mirror' ) ), 403 );
        }
        check_ajax_referer( 'wp_mirror_admin', 'nonce' );

        $state = $this->get_state();
        if ( $state['type'] !== 'deploy' ) {
            wp_send_json_error( array( 'message' => __( 'No deploy state found.', 'wp-mirror' ) ), 400 );
        }

        $failed = isset( $state['deploy']['failed'] ) && is_array( $state['deploy']['failed'] ) ? $state['deploy']['failed'] : array();
        if ( empty( $failed ) ) {
            wp_send_json_success( array( 'message' => __( 'No failed items to retry.', 'wp-mirror' ) ) );
        }

        $state['status'] = 'running';
        $state['stage']  = 'push_batches';
        $state['cancel_requested'] = 0;

        $state['deploy']['queue'] = $failed;
        $state['deploy']['queue_index'] = 0;
        $state['deploy']['failed'] = array();
        $state['deploy']['deletions'] = array();
        $state['deploy']['del_index'] = 0;

        $state['message'] = __( 'Retrying failed items...', 'wp-mirror' );

        $this->update_state( $state );
        $this->log( sprintf( 'Retry queued: %d items.', count( $failed ) ) );
        $this->schedule_tick( 3 );

        wp_send_json_success( array( 'message' => __( 'Retry started.', 'wp-mirror' ) ) );
    }

    public function ajax_test_github() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'wp-mirror' ) ), 403 );
        }
        check_ajax_referer( 'wp_mirror_admin', 'nonce' );

        $token = $this->settings->get_github_token();
        if ( $token === '' ) {
            wp_send_json_error( array( 'message' => __( 'GitHub token is missing.', 'wp-mirror' ) ), 400 );
        }

        $resp = $this->github->test_connection( $token );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( array( 'message' => $resp->get_error_message() ), 500 );
        }
        list( $code, $headers, $body ) = $resp;
        if ( $this->is_rate_limited( $code, $headers ) ) {
            wp_send_json_error( array( 'message' => __( 'Rate limited by GitHub. Try again later.', 'wp-mirror' ) ), 429 );
        }
        if ( $code !== 200 ) {
            wp_send_json_error( array( 'message' => __( 'GitHub connection test failed.', 'wp-mirror' ), 'details' => $body ), 400 );
        }

        $login = is_array( $body ) && isset( $body['login'] ) ? (string) $body['login'] : '';
        wp_send_json_success( array( 'message' => __( 'GitHub connection OK.', 'wp-mirror' ), 'login' => $login ) );
    }
}
