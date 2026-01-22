<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Restore helpers for WP Mirror export archives.
 *
 * This restores *static export files* only (not the WordPress database).
 */
final class WPMirror_Restore {

    /**
     * Allowed static file extensions for restore/extract.
     *
     * @return array<string,bool>
     */
    public function allowed_extensions() : array {
        $exts = array(
            'html','htm','css','js','json','xml','txt','map',
            'png','jpg','jpeg','gif','svg','webp','ico',
            'woff','woff2','ttf','otf','eot',
            'pdf','webmanifest','rss','atom',
        );
        $out = array();
        foreach ( $exts as $e ) { $out[ $e ] = true; }
        return $out;
    }

    /**
     * Normalize and validate a ZIP entry name into a safe relative path.
     *
     * @param string $name ZIP entry name.
     * @return string|WP_Error Safe relative path or error.
     */
    public function sanitize_entry_path( string $name ) {
        $name = str_replace( '\\', '/', $name );
        $name = ltrim( $name, '/' );

        // Directory entries (end with slash) are handled by caller.
        if ( $name === '' ) {
            return new WP_Error( 'wp_mirror_restore_bad_entry', __( 'Invalid ZIP entry.', 'wp-mirror' ) );
        }

        // Prevent traversal.
        $parts = explode( '/', $name );
        foreach ( $parts as $p ) {
            if ( $p === '' || $p === '.' ) { continue; }
            if ( $p === '..' ) {
                return new WP_Error( 'wp_mirror_restore_zip_slip', __( 'Blocked unsafe ZIP path traversal.', 'wp-mirror' ) );
            }
        }

        // Block PHP and similar.
        if ( preg_match( '/\.(php|phtml|phar)$/i', $name ) ) {
            return new WP_Error( 'wp_mirror_restore_php_blocked', __( 'Blocked PHP file in archive.', 'wp-mirror' ) );
        }

        // Only allow expected static extensions.
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $allowed = $this->allowed_extensions();
        if ( $ext === '' || ! isset( $allowed[ $ext ] ) ) {
            return new WP_Error( 'wp_mirror_restore_ext_blocked', __( 'Blocked unsupported file type in archive.', 'wp-mirror' ) );
        }

        return $name;
    }

    /**
     * Copy one entry stream from ZipArchive to filesystem.
     *
     * @param ZipArchive $zip
     * @param string $entry_name
     * @param string $dest_abs
     * @return WP_Error|null
     */
    public function extract_entry_stream( ZipArchive $zip, string $entry_name, string $dest_abs ) {
        $stream = $zip->getStream( $entry_name );
        if ( ! is_resource( $stream ) ) {
            return new WP_Error( 'wp_mirror_restore_stream', __( 'Failed to read ZIP entry stream.', 'wp-mirror' ) );
        }

        $dir = wp_normalize_path( dirname( $dest_abs ) );
        if ( ! wp_mkdir_p( $dir ) ) {
            fclose( $stream );
            return new WP_Error( 'wp_mirror_restore_mkdir', __( 'Failed to create restore directory.', 'wp-mirror' ) );
        }

        $out = fopen( $dest_abs, 'wb' );
        if ( ! $out ) {
            fclose( $stream );
            return new WP_Error( 'wp_mirror_restore_write', __( 'Failed to write restored file.', 'wp-mirror' ) );
        }

        // Stream copy.
        stream_copy_to_stream( $stream, $out );
        fclose( $stream );
        fclose( $out );

        return null;
    }

    /**
     * Move a file or directory into target dir.
     *
     * @param string $src
     * @param string $dst
     * @return WP_Error|null
     */
    public function move_path( string $src, string $dst ) {
        $src = wp_normalize_path( $src );
        $dst = wp_normalize_path( $dst );

        // Fast path: rename.
        if ( @rename( $src, $dst ) ) {
            return null;
        }

        // Fallback: copy then delete (handles cross-device).
        if ( is_dir( $src ) ) {
            $err = $this->copy_dir( $src, $dst );
            if ( is_wp_error( $err ) ) { return $err; }
            $this->delete_dir( $src );
            return null;
        }

        if ( ! @copy( $src, $dst ) ) {
            return new WP_Error( 'wp_mirror_restore_move', __( 'Failed to move restored path.', 'wp-mirror' ) );
        }
        @unlink( $src );
        return null;
    }

    /**
     * Recursive directory copy.
     *
     * @param string $src
     * @param string $dst
     * @return WP_Error|null
     */
    private function copy_dir( string $src, string $dst ) {
        if ( ! wp_mkdir_p( $dst ) ) {
            return new WP_Error( 'wp_mirror_restore_copy_dir', __( 'Failed to create destination directory.', 'wp-mirror' ) );
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $it as $item ) {
            $rel = ltrim( str_replace( trailingslashit( wp_normalize_path( $src ) ), '', wp_normalize_path( $item->getPathname() ) ), '/' );
            $target = trailingslashit( wp_normalize_path( $dst ) ) . $rel;

            if ( $item->isDir() ) {
                if ( ! wp_mkdir_p( $target ) ) {
                    return new WP_Error( 'wp_mirror_restore_copy_dir', __( 'Failed to create destination directory.', 'wp-mirror' ) );
                }
            } else {
                if ( ! @copy( $item->getPathname(), $target ) ) {
                    return new WP_Error( 'wp_mirror_restore_copy_file', __( 'Failed to copy file during restore.', 'wp-mirror' ) );
                }
            }
        }

        return null;
    }

    /**
     * Recursive directory delete.
     *
     * @param string $dir
     * @return void
     */
    public function delete_dir( string $dir ) : void {
        $dir = wp_normalize_path( $dir );
        if ( ! is_dir( $dir ) ) { return; }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $it as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }

        @rmdir( $dir );
    }
}
