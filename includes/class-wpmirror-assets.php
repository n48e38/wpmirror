<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Assets {

    /** Allowed static asset extensions (never copy PHP). */
    private $allowed_ext = array(
        'css','js','map',
        'png','jpg','jpeg','gif','webp','svg','avif','ico',
        'woff','woff2','ttf','otf','eot',
        'mp4','webm','mp3','ogg','wav',
        'pdf','txt','json','xml',
    );

    public function collect_from_html( string $html, string $base_url ) : array {
        $urls = array();

        if ( preg_match_all( '#url\(([^)]+)\)#i', $html, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $u = trim( $raw, " \t\n\r\0\x0B\"\'" );
                if ( $u !== '' ) { $urls[] = $u; }
            }
        }
        if ( preg_match_all( '#@import\s+(?:url\()?\s*[\"\']([^\"\']+)[\"\']#i', $html, $m2 ) ) {
            foreach ( $m2[1] as $u ) { $urls[] = $u; }
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        if ( $loaded ) {
            $xpath = new DOMXPath( $dom );
            $attrs = array( 'href','src','poster','data-src','data-lazy-src','data-original','data-bg' );

            foreach ( $attrs as $attr ) {
                $nodes = $xpath->query( '//*[@' . $attr . ']' );
                if ( $nodes instanceof DOMNodeList ) {
                    foreach ( $nodes as $node ) {
                        $val = (string) $node->getAttribute( $attr );
                        if ( $val !== '' ) { $urls[] = $val; }
                    }
                }
            }

            $srcset_nodes = $xpath->query( '//*[@srcset or @data-srcset]' );
            if ( $srcset_nodes instanceof DOMNodeList ) {
                foreach ( $srcset_nodes as $n ) {
                    $ss = $n->hasAttribute( 'srcset' ) ? (string) $n->getAttribute( 'srcset' ) : (string) $n->getAttribute( 'data-srcset' );
                    foreach ( $this->parse_srcset( $ss ) as $u ) { $urls[] = $u; }
                }
            }

            $a_nodes = $xpath->query( '//a[@href]' );
            if ( $a_nodes instanceof DOMNodeList ) {
                foreach ( $a_nodes as $a ) {
                    $href = (string) $a->getAttribute( 'href' );
                    if ( $this->looks_like_asset( $href ) ) { $urls[] = $href; }
                }
            }
        }
        libxml_clear_errors();

        $out = array();
        $seen = array();
        foreach ( $urls as $u ) {
            $u = trim( (string) $u );
            if ( $u === '' ) { continue; }
            $u = $this->normalize_url( $u, $base_url );
            if ( $u === '' ) { continue; }
            if ( isset( $seen[ $u ] ) ) { continue; }
            $seen[ $u ] = true;
            $out[] = $u;
        }
        return $out;
    }

    private function parse_srcset( string $srcset ) : array {
        $out = array();
        $parts = explode( ',', $srcset );
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( $p === '' ) { continue; }
            $sub = preg_split( '/\s+/', $p );
            if ( ! empty( $sub[0] ) ) { $out[] = $sub[0]; }
        }
        return $out;
    }

    private function normalize_url( string $url, string $base_url ) : string {
        if ( strpos( $url, 'data:' ) === 0 || strpos( $url, 'mailto:' ) === 0 || strpos( $url, 'tel:' ) === 0 || strpos( $url, 'javascript:' ) === 0 ) {
            return '';
        }
        if ( strpos( $url, '#' ) === 0 ) { return ''; }
        if ( strpos( $url, '//' ) === 0 ) {
            $scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
            if ( ! $scheme ) { $scheme = 'https'; }
            return $scheme . ':' . $url;
        }
        return $url;
    }

    private function looks_like_asset( string $url ) : bool {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return $ext !== '' && in_array( $ext, $this->allowed_ext, true );
    }

    public function map_url_to_file( string $asset_url, string $public_base_url, string $export_dir ) {
        $asset_url = trim( $asset_url );
        if ( $asset_url === '' ) { return null; }

        $path = (string) wp_parse_url( $asset_url, PHP_URL_PATH );
        if ( $path === '' ) { return null; }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( $ext === 'php' ) { return null; }
        if ( $ext !== '' && ! in_array( $ext, $this->allowed_ext, true ) ) { return null; }

        if ( strpos( $path, '/wp-content/' ) !== 0 && strpos( $path, '/wp-includes/' ) !== 0 ) { return null; }

        $abs = wp_normalize_path( ABSPATH . ltrim( $path, '/' ) );

        $abs_root = wp_normalize_path( ABSPATH );
        if ( strpos( $abs, $abs_root ) !== 0 ) { return null; }
        if ( ! file_exists( $abs ) || is_dir( $abs ) ) { return null; }

        $dest_rel = ltrim( $path, '/' );
        $dest_abs = trailingslashit( wp_normalize_path( $export_dir ) ) . $dest_rel;

        return array( $abs, $dest_abs );
    }

    public function copy_one( string $src_abs, string $dest_abs ) {
        $dest_dir = dirname( $dest_abs );
        if ( ! wp_mkdir_p( $dest_dir ) ) {
            return new WP_Error( 'wp_mirror_mkdir_failed', __( 'Failed to create destination directory.', 'wp-mirror' ) );
        }

        if ( preg_match( '/\.php$/i', $src_abs ) ) {
            return new WP_Error( 'wp_mirror_php_blocked', __( 'Blocked copying a PHP file.', 'wp-mirror' ) );
        }

        if ( ! copy( $src_abs, $dest_abs ) ) {
            return new WP_Error( 'wp_mirror_copy_failed', __( 'Failed to copy file.', 'wp-mirror' ) );
        }
        return true;
    }

    public function collect_from_css( string $css ) : array {
        $urls = array();

        if ( preg_match_all( '#url\(([^)]+)\)#i', $css, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $u = trim( $raw, " \t\n\r\0\x0B\"\'" );
                if ( $u !== '' ) { $urls[] = $u; }
            }
        }
        if ( preg_match_all( '#@import\s+(?:url\()?\s*[\"\']([^\"\']+)[\"\']#i', $css, $m2 ) ) {
            foreach ( $m2[1] as $u ) { $urls[] = $u; }
        }

        $out = array();
        $seen = array();
        foreach ( $urls as $u ) {
            $u = trim( (string) $u );
            if ( $u === '' ) { continue; }
            if ( isset( $seen[ $u ] ) ) { continue; }
            $seen[ $u ] = true;
            $out[] = $u;
        }
        return $out;
    }

    public function resolve_css_dep( string $dep, string $css_url_path ) {
        $dep = trim( $dep );
        if ( $dep === '' ) { return null; }
        if ( strpos( $dep, 'data:' ) === 0 ) { return null; }

        if ( preg_match( '#^https?://#i', $dep ) || strpos( $dep, '//' ) === 0 ) { return $dep; }
        if ( strpos( $dep, '/' ) === 0 ) { return $dep; }

        $base_dir = rtrim( dirname( $css_url_path ), '/' );
        $full = $base_dir . '/' . $dep;

        $parts = array();
        foreach ( explode( '/', $full ) as $p ) {
            if ( $p === '' || $p === '.' ) { continue; }
            if ( $p === '..' ) { array_pop( $parts ); continue; }
            $parts[] = $p;
        }
        return '/' . implode( '/', $parts );
    }

    public function parse_ignore_patterns( string $patterns ) : array {
        $lines = preg_split( '/\r\n|\r|\n/', $patterns );
        $out = array();
        foreach ( $lines as $l ) {
            $l = trim( (string) $l );
            if ( $l === '' ) { continue; }
            $out[] = $l;
        }
        return $out;
    }

    public function is_ignored( string $rel_path, array $patterns ) : bool {
        $rel_path = ltrim( str_replace( '\\', '/', $rel_path ), '/' );
        foreach ( $patterns as $p ) {
            $p = trim( (string) $p );
            if ( $p === '' ) { continue; }
            $p_norm = trim( str_replace( '\\', '/', $p ), '/' );

            if ( function_exists( 'fnmatch' ) ) {
                if ( fnmatch( $p_norm, $rel_path, FNM_PATHNAME ) ) { return true; }
                if ( fnmatch( '*' . $p_norm . '*', $rel_path ) ) { return true; }
            } else {
                if ( strpos( $rel_path, $p_norm ) !== false ) { return true; }
            }
        }
        return false;
    }
}
