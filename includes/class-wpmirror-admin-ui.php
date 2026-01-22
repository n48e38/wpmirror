<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Admin_UI {

    private $settings;
    private $jobs;

    public function __construct( WPMirror_Settings $settings, WPMirror_Background_Jobs $jobs ) {
        $this->settings = $settings;
        $this->jobs     = $jobs;

        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'admin_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
    }

    public function admin_init() : void {
        $this->settings->register();
        add_action( 'admin_post_wp_mirror_action', array( $this, 'handle_post_action' ) );
    }

    public function menu() : void {
        add_menu_page(
            __( 'WP Mirror', 'wp-mirror' ),
            __( 'WP Mirror', 'wp-mirror' ),
            'manage_options',
            'wp-mirror',
            array( $this, 'render' ),
            'dashicons-migrate',
            58
        );
    }

    public function enqueue( $hook ) : void {
        if ( $hook !== 'toplevel_page_wp-mirror' ) { return; }

        wp_enqueue_style( 'wp-mirror-admin', WP_MIRROR_PLUGIN_URL . 'admin/admin.css', array(), WP_MIRROR_VERSION );
        wp_enqueue_script( 'wp-mirror-admin', WP_MIRROR_PLUGIN_URL . 'admin/admin.js', array( 'jquery' ), WP_MIRROR_VERSION, true );
        wp_localize_script( 'wp-mirror-admin', 'WPMirror', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_mirror_admin' ),
        ) );
    }

    public function handle_post_action() : void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden', 'wp-mirror' ) ); }

        $action = isset( $_POST['wp_mirror_do'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mirror_do'] ) ) : '';
        check_admin_referer( 'wp_mirror_action', 'wp_mirror_nonce' );

        $settings = $this->settings->get();
        $export_dir = (string) $settings['export_dir'];

        if ( $action === 'start_export' ) {
            $this->jobs->start_export();
            wp_safe_redirect( admin_url( 'admin.php?page=wp-mirror' ) );
            exit;
        }

        if ( $action === 'start_deploy' ) {
            // If clean-removed is enabled, require explicit confirmation per deploy request.
            if ( (int) $settings['github_clean_removed'] === 1 ) {
                $confirm = isset( $_POST['confirm_clean_removed'] ) ? absint( $_POST['confirm_clean_removed'] ) : 0;
                if ( $confirm !== 1 ) {
                    wp_safe_redirect( add_query_arg( 'wp_mirror_notice', 'confirm_clean', admin_url( 'admin.php?page=wp-mirror' ) ) );
                    exit;
                }
            }
            
            $this->jobs->start_deploy();
            wp_safe_redirect( admin_url( 'admin.php?page=wp-mirror' ) );
            exit;
        }

        if ( $action === 'download_archive' ) {
            $file = isset( $_POST['archive_file'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_POST['archive_file'] ) ) ) : '';
            $allowed_dir = trailingslashit( wp_normalize_path( $export_dir ) ) . '_archives/';
            if ( ! $file || strpos( $file, $allowed_dir ) !== 0 || ! is_file( $file ) ) {
                wp_die( esc_html__( 'Invalid archive file.', 'wp-mirror' ) );
            }
            // Stream file to browser.
            nocache_headers();
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
            header( 'Content-Length: ' . (string) filesize( $file ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            readfile( $file );
            exit;
        }

if ( $action === 'restore_archive' ) {
            $file = isset( $_POST['archive_file'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_POST['archive_file'] ) ) ) : '';
            $confirm = isset( $_POST['confirm_restore'] ) ? absint( $_POST['confirm_restore'] ) : 0;

            if ( $confirm !== 1 ) {
                wp_safe_redirect( add_query_arg( 'wp_mirror_notice', 'confirm_restore', admin_url( 'admin.php?page=wp-mirror' ) ) );
                exit;
            }

            $allowed_dir = trailingslashit( wp_normalize_path( $export_dir ) ) . '_archives/';
            if ( ! $file || strpos( $file, $allowed_dir ) !== 0 || ! is_file( $file ) ) {
                wp_die( esc_html__( 'Invalid archive file.', 'wp-mirror' ) );
            }

            $state = $this->jobs->get_state();
            if ( in_array( (string) $state['status'], array( 'running', 'paused' ), true ) ) {
                wp_safe_redirect( add_query_arg( 'wp_mirror_notice', 'job_running', admin_url( 'admin.php?page=wp-mirror' ) ) );
                exit;
            }

            $this->jobs->start_restore( $file );
            wp_safe_redirect( admin_url( 'admin.php?page=wp-mirror' ) );
            exit;
        }

if ( $action === 'delete_archive' ) {
            $file = isset( $_POST['archive_file'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_POST['archive_file'] ) ) ) : '';
            $allowed_dir = trailingslashit( wp_normalize_path( $export_dir ) ) . '_archives/';
            if ( $file && strpos( $file, $allowed_dir ) === 0 ) {
                $zipper = new WPMirror_Zip();
                $zipper->delete_archive( $file );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=wp-mirror' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp-mirror' ) );
        exit;
    }

    public function render() : void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden', 'wp-mirror' ) ); }

        $s = $this->settings->get();
        $state = $this->jobs->get_state();

        $zipper = new WPMirror_Zip();
        $archives = $zipper->list_archives( (string) $s['export_dir'] );

        ?>
        <?php if ( isset( $_GET['wp_mirror_notice'] ) ) : ?>
  <?php $n = sanitize_text_field( wp_unslash( $_GET['wp_mirror_notice'] ) ); ?>
  <?php if ( $n === 'confirm_clean' ) : ?>
    <div class="notice notice-warning"><p><?php echo esc_html__( 'Remote clean-up is enabled. Please confirm the clean-removed option on the Deploy form to proceed.', 'wp-mirror' ); ?></p></div>
  <?php elseif ( $n === 'confirm_restore' ) : ?>
    <div class="notice notice-warning"><p><?php echo esc_html__( 'Restore will overwrite the current export directory contents. Please confirm the restore checkbox to proceed.', 'wp-mirror' ); ?></p></div>
  <?php elseif ( $n === 'job_running' ) : ?>
    <div class="notice notice-warning"><p><?php echo esc_html__( 'A WP Mirror job is currently running. Please wait for it to finish before starting a restore.', 'wp-mirror' ); ?></p></div>
  <?php endif; ?>
<?php endif; ?>
<div class="wrap wp-mirror-wrap">
            <h1><?php echo esc_html__( 'WP Mirror', 'wp-mirror' ); ?></h1>

            <div class="wp-mirror-grid">
                <div class="wp-mirror-card">
                    <h2><?php echo esc_html__( 'Settings', 'wp-mirror' ); ?></h2>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields( 'wp_mirror_settings_group' );
                        $opt = WPMirror_Settings::OPTION_KEY;
                        ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="public_base_url"><?php echo esc_html__( 'Public Base URL', 'wp-mirror' ); ?></label></th>
                                <td>
                                    <input class="regular-text" type="url" id="public_base_url" name="<?php echo esc_attr( $opt ); ?>[public_base_url]" value="<?php echo esc_attr( $s['public_base_url'] ); ?>" placeholder="https://prod.example.com/" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="export_dir"><?php echo esc_html__( 'Export Directory (absolute path)', 'wp-mirror' ); ?></label></th>
                                <td>
                                    <input class="large-text" type="text" id="export_dir" name="<?php echo esc_attr( $opt ); ?>[export_dir]" value="<?php echo esc_attr( $s['export_dir'] ); ?>" placeholder="/var/www/html/wp-mirror-export" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Asset scope mode', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr( $opt ); ?>[asset_scope_mode]" value="referenced" <?php checked( $s['asset_scope_mode'], 'referenced' ); ?> />
                                        <?php echo esc_html__( 'Referenced-only (recommended)', 'wp-mirror' ); ?>
                                    </label><br/>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr( $opt ); ?>[asset_scope_mode]" value="balanced" <?php checked( $s['asset_scope_mode'], 'balanced' ); ?> />
                                        <?php echo esc_html__( 'Balanced', 'wp-mirror' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Ignore rules', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[ignore_enabled]" value="1" <?php checked( (int) $s['ignore_enabled'], 1 ); ?> />
                                        <?php echo esc_html__( 'Enable ignore patterns', 'wp-mirror' ); ?>
                                    </label>
                                    <textarea class="large-text code" rows="6" name="<?php echo esc_attr( $opt ); ?>[ignore_patterns]"><?php echo esc_textarea( $s['ignore_patterns'] ); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'ZIP archives', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[zip_enabled]" value="1" <?php checked( (int) $s['zip_enabled'], 1 ); ?> />
                                        <?php echo esc_html__( 'Enable ZIP archive creation after export', 'wp-mirror' ); ?>
                                    </label>
                                </td>
                            </tr>

                            <tr><th colspan="2"><h3><?php echo esc_html__( 'GitHub Deploy', 'wp-mirror' ); ?></h3></th></tr>

                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Enable GitHub deploy', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[github_enabled]" value="1" <?php checked( (int) $s['github_enabled'], 1 ); ?> />
                                        <?php echo esc_html__( 'Enable', 'wp-mirror' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="github_owner"><?php echo esc_html__( 'Owner', 'wp-mirror' ); ?></label></th>
                                <td><input class="regular-text" type="text" id="github_owner" name="<?php echo esc_attr( $opt ); ?>[github_owner]" value="<?php echo esc_attr( $s['github_owner'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="github_repo"><?php echo esc_html__( 'Repo', 'wp-mirror' ); ?></label></th>
                                <td><input class="regular-text" type="text" id="github_repo" name="<?php echo esc_attr( $opt ); ?>[github_repo]" value="<?php echo esc_attr( $s['github_repo'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="github_branch"><?php echo esc_html__( 'Branch', 'wp-mirror' ); ?></label></th>
                                <td><input class="regular-text" type="text" id="github_branch" name="<?php echo esc_attr( $opt ); ?>[github_branch]" value="<?php echo esc_attr( $s['github_branch'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="github_path_prefix"><?php echo esc_html__( 'Path prefix (optional)', 'wp-mirror' ); ?></label></th>
                                <td>
                                    <input class="regular-text" type="text" id="github_path_prefix" name="<?php echo esc_attr( $opt ); ?>[github_path_prefix]" value="<?php echo esc_attr( $s['github_path_prefix'] ); ?>" placeholder="docs" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="github_token"><?php echo esc_html__( 'Token (PAT)', 'wp-mirror' ); ?></label></th>
                                <td>
                                    <input class="regular-text" type="password" id="github_token" name="<?php echo esc_attr( $opt ); ?>[github_token]" value="<?php echo esc_attr( $s['github_token'] ); ?>" autocomplete="new-password" />
                                    <p class="description"><?php echo esc_html__( 'Tip: define WP_MIRROR_GITHUB_TOKEN in wp-config.php to avoid storing it in the database.', 'wp-mirror' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'GitHub Pages extras', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[github_nojekyll]" value="1" <?php checked( (int) $s['github_nojekyll'], 1 ); ?> />
                                        <?php echo esc_html__( 'Write .nojekyll', 'wp-mirror' ); ?>
                                    </label>
                                    <br/>
                                    <label for="github_cname"><?php echo esc_html__( 'CNAME (optional)', 'wp-mirror' ); ?></label><br/>
                                    <input class="regular-text" type="text" id="github_cname" name="<?php echo esc_attr( $opt ); ?>[github_cname]" value="<?php echo esc_attr( $s['github_cname'] ); ?>" placeholder="www.example.com" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Clean remote removed files', 'wp-mirror' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[github_clean_removed]" value="1" <?php checked( (int) $s['github_clean_removed'], 1 ); ?> />
                                        <?php echo esc_html__( 'Delete files on GitHub that no longer exist locally', 'wp-mirror' ); ?>
                                    </label>
                                </td>
                            </tr>

                            <tr><th colspan="2"><h3><?php echo esc_html__( 'Performance', 'wp-mirror' ); ?></h3></th></tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Batch sizes', 'wp-mirror' ); ?></th>
                                <td>
                                    <label><?php echo esc_html__( 'URLs per export tick', 'wp-mirror' ); ?>
                                        <input type="number" min="1" max="50" name="<?php echo esc_attr( $opt ); ?>[export_batch_urls]" value="<?php echo esc_attr( (int) $s['export_batch_urls'] ); ?>" />
                                    </label><br/>
                                    <label><?php echo esc_html__( 'Assets per copy tick', 'wp-mirror' ); ?>
                                        <input type="number" min="1" max="200" name="<?php echo esc_attr( $opt ); ?>[asset_batch_files]" value="<?php echo esc_attr( (int) $s['asset_batch_files'] ); ?>" />
                                    </label><br/>
                                    <label><?php echo esc_html__( 'Files per ZIP tick', 'wp-mirror' ); ?>
                                        <input type="number" min="50" max="2000" name="<?php echo esc_attr( $opt ); ?>[zip_batch_files]" value="<?php echo esc_attr( (int) $s['zip_batch_files'] ); ?>" />
                                    </label><br/>
                                    <label><?php echo esc_html__( 'Files per GitHub deploy tick', 'wp-mirror' ); ?>
                                        <input type="number" min="1" max="100" name="<?php echo esc_attr( $opt ); ?>[github_batch_files]" value="<?php echo esc_attr( (int) $s['github_batch_files'] ); ?>" />
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'HTTP timeout (seconds)', 'wp-mirror' ); ?></th>
                                <td><input type="number" min="5" max="60" name="<?php echo esc_attr( $opt ); ?>[http_timeout]" value="<?php echo esc_attr( (int) $s['http_timeout'] ); ?>" /></td>
                            </tr>
                        </table>

                        <?php submit_button( __( 'Save Settings', 'wp-mirror' ) ); ?>
                    </form>

                    <p>
                        <button type="button" class="button" id="wp-mirror-test-github"><?php echo esc_html__( 'Test GitHub connection', 'wp-mirror' ); ?></button>
                        <span class="wp-mirror-inline-status" id="wp-mirror-test-result"></span>
                    </p>
                </div>

                <div class="wp-mirror-card">
                    <h2><?php echo esc_html__( 'Actions', 'wp-mirror' ); ?></h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wp_mirror_action', 'wp_mirror_nonce' ); ?>
                        <input type="hidden" name="action" value="wp_mirror_action" />
                        <input type="hidden" name="wp_mirror_do" value="start_export" />
                        <?php submit_button( __( 'Generate Static Export', 'wp-mirror' ), 'primary', 'submit', false ); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mirror-inline-form">
                        <?php wp_nonce_field( 'wp_mirror_action', 'wp_mirror_nonce' ); ?>
                        <input type="hidden" name="action" value="wp_mirror_action" />
                        <input type="hidden" name="wp_mirror_do" value="start_deploy" />
                        <?php submit_button( __( 'Deploy to GitHub', 'wp-mirror' ), 'secondary', 'submit', false ); ?>
                    </form>

                    <div class="wp-mirror-progress">
                        <div class="wp-mirror-progress-bar" id="wp-mirror-progress-bar" style="width:0%"></div>
                    </div>
                    <p id="wp-mirror-progress-text" class="wp-mirror-muted"><?php echo esc_html__( 'Status will appear here after starting a job.', 'wp-mirror' ); ?></p>

                    <p>
                        <button type="button" class="button button-link-delete" id="wp-mirror-cancel-deploy"><?php echo esc_html__( 'Cancel GitHub deploy', 'wp-mirror' ); ?></button>
                        <button type="button" class="button" id="wp-mirror-retry-failed"><?php echo esc_html__( 'Retry failed items', 'wp-mirror' ); ?></button>
                    </p>

                    <h3><?php echo esc_html__( 'Logs', 'wp-mirror' ); ?></h3>
                    <div class="wp-mirror-log" id="wp-mirror-log"></div>
                    <div class="wp-mirror-errors" id="wp-mirror-errors"></div>
                </div>

                <div class="wp-mirror-card">
                    <h2><?php echo esc_html__( 'Export artifacts', 'wp-mirror' ); ?></h2>

                    <p><strong><?php echo esc_html__( 'Export directory:', 'wp-mirror' ); ?></strong><br/>
                        <code><?php echo esc_html( (string) $s['export_dir'] ); ?></code>
                    </p>

                    <?php if ( isset( $state['export']['result']['file_count'] ) ) : ?>
                        <p>
                            <strong><?php echo esc_html__( 'Last export:', 'wp-mirror' ); ?></strong>
                            <?php
                            $count = (int) ( $state['export']['result']['file_count'] ?? 0 );
                            $bytes = (int) ( $state['export']['result']['total_bytes'] ?? 0 );
                            echo esc_html( sprintf( __( '%d files, %s', 'wp-mirror' ), $count, size_format( $bytes ) ) );
                            ?>
                        </p>
                    <?php endif; ?>

                    <h3><?php echo esc_html__( 'ZIP archives', 'wp-mirror' ); ?></h3>
                    <?php if ( empty( $archives ) ) : ?>
                        <p class="wp-mirror-muted"><?php echo esc_html__( 'No archives yet.', 'wp-mirror' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__( 'File', 'wp-mirror' ); ?></th>
                                    <th><?php echo esc_html__( 'Size', 'wp-mirror' ); ?></th>
                                    <th><?php echo esc_html__( 'Created', 'wp-mirror' ); ?></th>
                                    <th><?php echo esc_html__( 'Actions', 'wp-mirror' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $archives as $a ) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html( $a['filename'] ); ?></code></td>
                                        <td><?php echo esc_html( size_format( (int) $a['bytes'] ) ); ?></td>
                                        <td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $a['mtime'] ) . ' UTC' ); ?></td>
                                        <td>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mirror-inline-form">
                                                <?php wp_nonce_field( 'wp_mirror_action', 'wp_mirror_nonce' ); ?>
                                                <input type="hidden" name="action" value="wp_mirror_action" />
                                                <input type="hidden" name="wp_mirror_do" value="download_archive" />
                                                <input type="hidden" name="archive_file" value="<?php echo esc_attr( $a['file'] ); ?>" />
                                                <?php submit_button( __( 'Download', 'wp-mirror' ), 'secondary', 'submit', false ); ?>
                                            </form>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mirror-inline-form">
                                                <?php wp_nonce_field( 'wp_mirror_action', 'wp_mirror_nonce' ); ?>
                                                <input type="hidden" name="action" value="wp_mirror_action" />
                                                <input type="hidden" name="wp_mirror_do" value="restore_archive" />
                                                <input type="hidden" name="archive_file" value="<?php echo esc_attr( $a['file'] ); ?>" />
                                                <label style="margin-right:6px;">
                                                    <input type="checkbox" name="confirm_restore" value="1" />
                                                    <?php echo esc_html__( 'Confirm restore', 'wp-mirror' ); ?>
                                                </label>
                                                <?php submit_button( __( 'Restore', 'wp-mirror' ), 'secondary', 'submit', false ); ?>
                                            </form>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mirror-inline-form">
                                                <?php wp_nonce_field( 'wp_mirror_action', 'wp_mirror_nonce' ); ?>
                                                <input type="hidden" name="action" value="wp_mirror_action" />
                                                <input type="hidden" name="wp_mirror_do" value="delete_archive" />
                                                <input type="hidden" name="archive_file" value="<?php echo esc_attr( $a['file'] ); ?>" />
                                                <?php submit_button( __( 'Delete', 'wp-mirror' ), 'delete', 'submit', false ); ?>
                                            </form>
                                            <span class="description"><?php echo esc_html__( 'Use Download to fetch the ZIP archive securely.', 'wp-mirror' ); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <p class="description"><?php echo esc_html__( 'WP Mirror writes exports/archives to the server filesystem. Use the actions above to Download, Restore, or Delete archives.', 'wp-mirror' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function dashboard_widget() : void {
        wp_add_dashboard_widget( 'wp_mirror_dashboard', __( 'WP Mirror', 'wp-mirror' ), array( $this, 'render_dashboard_widget' ) );
    }

    public function render_dashboard_widget() : void {
        if ( ! current_user_can( 'manage_options' ) ) { echo esc_html__( 'Forbidden', 'wp-mirror' ); return; }
        $state = $this->jobs->get_state();
        $status = (string) $state['status'];
        $msg = (string) $state['message'];
        $updated = (int) ( $state['updated_at'] ?? 0 );

        echo '<p><strong>' . esc_html__( 'Status:', 'wp-mirror' ) . '</strong> ' . esc_html( $status ) . '</p>';
        echo '<p>' . esc_html( $msg ) . '</p>';
        if ( $updated ) {
            echo '<p class="description">' . esc_html__( 'Last update:', 'wp-mirror' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', $updated ) . ' UTC' ) . '</p>';
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wp-mirror' ) ) . '">' . esc_html__( 'Open WP Mirror', 'wp-mirror' ) . '</a></p>';
    }
}
