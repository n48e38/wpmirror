<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Zip {

    public function ensure_archives_dir( string $export_dir ) {
        $dir = trailingslashit( wp_normalize_path( $export_dir ) ) . '_archives';
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'wp_mirror_archives_dir', __( 'Failed to create archives directory.', 'wp-mirror' ) );
        }
        if ( ! is_writable( $dir ) ) {
            return new WP_Error( 'wp_mirror_archives_dir_not_writable', __( 'Archives directory is not writable.', 'wp-mirror' ) );
        }
        return $dir;
    }

    public function list_export_files( string $export_dir ) : array {
        $export_dir = wp_normalize_path( $export_dir );
        $out = array();

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $export_dir, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $abs = wp_normalize_path( $file->getPathname() );

            if ( strpos( $abs, trailingslashit( $export_dir ) . '_archives/' ) === 0 ) { continue; }
            if ( preg_match( '/\.php$/i', $abs ) ) { continue; }
            $out[] = $abs;
        }

        sort( $out );
        return $out;
    }

    public function build_zip_path( string $archives_dir ) : string {
        $ts = gmdate( 'Ymd-His' );
        return trailingslashit( $archives_dir ) . 'wp-mirror-export-' . $ts . '.zip';
    }

    public function add_batch( string $zip_path, string $export_dir, array $files, int $start_index, int $batch ) : array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return array( $start_index, 0, new WP_Error( 'wp_mirror_zip_missing', __( 'ZipArchive is not available on this server.', 'wp-mirror' ) ) );
        }

        $zip = new ZipArchive();
        $open = $zip->open( $zip_path, ZipArchive::CREATE );
        if ( true !== $open ) {
            return array( $start_index, 0, new WP_Error( 'wp_mirror_zip_open', __( 'Failed to open ZIP for writing.', 'wp-mirror' ) ) );
        }

        $added = 0;
        $i = $start_index;
        $max = min( count( $files ), $start_index + $batch );

        for ( ; $i < $max; $i++ ) {
            $abs = (string) $files[ $i ];
            $rel = ltrim( str_replace( trailingslashit( wp_normalize_path( $export_dir ) ), '', wp_normalize_path( $abs ) ), '/' );
            if ( preg_match( '/\.php$/i', $rel ) ) { continue; }
            $zip->addFile( $abs, $rel );
            $added++;
        }

        $zip->close();

        return array( $i, $added, null );
    }

    public function list_archives( string $export_dir ) : array {
        $out = array();
        $dir = trailingslashit( wp_normalize_path( $export_dir ) ) . '_archives';
        if ( ! is_dir( $dir ) ) { return $out; }
        $files = glob( $dir . '/*.zip' );
        if ( ! is_array( $files ) ) { return $out; }

        foreach ( $files as $f ) {
            $f = wp_normalize_path( $f );
            if ( ! is_file( $f ) ) { continue; }
            $out[] = array(
                'file'     => $f,
                'filename' => basename( $f ),
                'bytes'    => (int) filesize( $f ),
                'mtime'    => (int) filemtime( $f ),
            );
        }

        usort( $out, function( $a, $b ) { return $b['mtime'] <=> $a['mtime']; } );
        return $out;
    }

    public function delete_archive( string $abs_path ) : bool {
        $abs_path = wp_normalize_path( $abs_path );
        if ( is_file( $abs_path ) ) { return (bool) unlink( $abs_path ); }
        return false;
    }
}
