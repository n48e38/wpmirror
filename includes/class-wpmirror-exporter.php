<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_Exporter {

    private $settings;
    private $assets;

    public function __construct( WPMirror_Settings $settings, WPMirror_Assets $assets ) {
        $this->settings = $settings;
        $this->assets   = $assets;
    }

    public function discover_urls() : array {
        $urls = array();

        $urls[] = home_url( '/' );

        foreach ( array( 'post', 'page' ) as $pt ) {
            $ids = get_posts( array(
                'post_type'              => $pt,
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ) );

            if ( is_array( $ids ) ) {
                foreach ( $ids as $id ) {
                    $permalink = get_permalink( (int) $id );
                    if ( $permalink ) {
                        $urls[] = $permalink;
                    }
                }
            }
        }

        $out  = array();
        $seen = array();
        foreach ( $urls as $u ) {
            $u = esc_url_raw( $u );
            if ( ! $u ) { continue; }
            if ( isset( $seen[ $u ] ) ) { continue; }
            $seen[ $u ] = true;
            $out[] = $u;
        }
        return $out;
    }

    public function fetch_html( string $url ) {
        $timeout = absint( $this->settings->get_value( 'http_timeout', 15 ) );

        $resp = wp_remote_get( $url, array(
            'timeout'     => $timeout,
            'redirection' => 5,
            'headers'     => array(
                'User-Agent' => 'WP-Mirror/' . WP_MIRROR_VERSION . ' (WordPress)',
            ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'wp_mirror_http_error', sprintf( __( 'Unexpected HTTP status %d for URL.', 'wp-mirror' ), (int) $code ) );
        }

        $body = (string) wp_remote_retrieve_body( $resp );
        $headers = (array) wp_remote_retrieve_headers( $resp );

        return array( $body, $headers );
    }

    public function map_url_to_export_path( string $url, string $export_dir ) : string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( $path === '' ) { $path = '/'; }
        if ( $path[0] !== '/' ) { $path = '/' . $path; }

        $path = rtrim( $path, '/' );
        if ( $path === '' ) {
            return trailingslashit( $export_dir ) . 'index.html';
        }

        $dir = trailingslashit( $export_dir ) . ltrim( $path, '/' );
        return trailingslashit( $dir ) . 'index.html';
    }

    public function rewrite_internal_urls( string $html, string $public_base_url ) : string {
        $public_base_url = trim( $public_base_url );
        if ( $public_base_url === '' ) {
            return (string) $html;
        }

        // Normalize and build public origin (scheme://host + optional base path, no trailing slash).
        $public_base_url = untrailingslashit( $public_base_url );
        $public_parts    = wp_parse_url( $public_base_url );
        if ( empty( $public_parts['scheme'] ) || empty( $public_parts['host'] ) ) {
            return (string) $html;
        }

        $public_scheme = (string) $public_parts['scheme'];
        $public_host   = (string) $public_parts['host'];
        $public_path   = isset( $public_parts['path'] ) ? rtrim( (string) $public_parts['path'], '/' ) : '';
        $public_origin = $public_scheme . '://' . $public_host . $public_path;

        // Compute old origins from home/site (supports subdirectory installs).
        $home_base  = untrailingslashit( home_url( '/' ) );
        $site_base  = untrailingslashit( site_url( '/' ) );
        $old_bases  = array_values( array_unique( array_filter( array( $home_base, $site_base ) ) ) );
        $old_origins = array();
        $old_hosts   = array();

        foreach ( $old_bases as $b ) {
            $p = wp_parse_url( $b );
            if ( empty( $p['host'] ) ) { continue; }

            $host = (string) $p['host'];
            $path = isset( $p['path'] ) ? rtrim( (string) $p['path'], '/' ) : '';

            $old_hosts[]   = $host;
            $old_origins[] = 'https://' . $host . $path;
            $old_origins[] = 'http://' . $host . $path;
            if ( ! empty( $p['scheme'] ) ) {
                $old_origins[] = (string) $p['scheme'] . '://' . $host . $path;
            }
        }

        $old_origins = array_values( array_unique( array_filter( $old_origins ) ) );
        $old_hosts   = array_values( array_unique( array_filter( $old_hosts ) ) );

        // Fast replace for common absolute URL forms (also handles JSON-escaped slashes and protocol-relative).
        foreach ( $old_origins as $old_origin ) {
            if ( $old_origin === '' || $old_origin === $public_origin ) { continue; }

            $html = str_replace( $old_origin . '/', $public_origin . '/', $html );
            $html = str_replace( $old_origin, $public_origin, $html );

            // JSON escaped forms (e.g. https:\/\/example.com\/path).
            $old_esc = str_replace( '/', '\/', $old_origin );
            $new_esc = str_replace( '/', '\/', $public_origin );
            $html    = str_replace( $old_esc . '\/', $new_esc . '\/', $html );
            $html    = str_replace( $old_esc, $new_esc, $html );

            $old_host = (string) wp_parse_url( $old_origin, PHP_URL_HOST );
            if ( $old_host ) {
                $html = str_replace( '//' . $old_host . '/', '//' . $public_host . '/', $html );
                $html = str_replace( '//' . $old_host, '//' . $public_host, $html );

                // Escaped protocol-relative forms.
                $html = str_replace( '\/\/' . $old_host . '\/', '\/\/' . $public_host . '\/', $html );
                $html = str_replace( '\/\/' . $old_host, '\/\/' . $public_host, $html );
            }
        }

        // Helper: remap a single URL string.
        $remap_url = function( string $u ) use ( $old_origins, $old_hosts, $public_origin, $public_host, $public_scheme ) : string {
            $u = trim( $u );
            if ( $u === '' ) { return $u; }

            $lower = strtolower( $u );
            if ( strpos( $lower, 'data:' ) === 0 || strpos( $lower, 'mailto:' ) === 0 || strpos( $lower, 'tel:' ) === 0 || strpos( $lower, 'javascript:' ) === 0 ) {
                return $u;
            }
            if ( strpos( $u, '#' ) === 0 ) {
                return $u;
            }

            // Protocol-relative: //host/path.
            if ( strpos( $u, '//' ) === 0 ) {
                $host = (string) wp_parse_url( $public_scheme . ':' . $u, PHP_URL_HOST );
                if ( $host && in_array( $host, $old_hosts, true ) ) {
                    $rest = substr( $u, strlen( '//' . $host ) );
                    return '//' . $public_host . $rest;
                }
                return $u;
            }

            // Replace by prefix match against known old origins (preserves path/query/fragment).
            foreach ( $old_origins as $old_origin ) {
                if ( $old_origin && strpos( $u, $old_origin ) === 0 ) {
                    return $public_origin . substr( $u, strlen( $old_origin ) );
                }
            }

            // Fallback: host-based rebuild.
            $host = (string) wp_parse_url( $u, PHP_URL_HOST );
            if ( $host && in_array( $host, $old_hosts, true ) ) {
                $path  = (string) wp_parse_url( $u, PHP_URL_PATH );
                $query = (string) wp_parse_url( $u, PHP_URL_QUERY );
                $frag  = (string) wp_parse_url( $u, PHP_URL_FRAGMENT );

                $new = $public_origin . $path;
                if ( $query ) { $new .= '?' . $query; }
                if ( $frag ) { $new .= '#' . $frag; }

                return $new;
            }

            return $u;
        };

        $rewrite_srcset = function( string $srcset ) use ( $remap_url ) : string {
            $parts = array_map( 'trim', explode( ',', $srcset ) );
            $out   = array();

            foreach ( $parts as $p ) {
                if ( $p === '' ) { continue; }
                $tokens = preg_split( '/\s+/', $p, 2 );
                $url    = isset( $tokens[0] ) ? (string) $tokens[0] : '';
                $desc   = isset( $tokens[1] ) ? (string) $tokens[1] : '';
                $url    = $remap_url( $url );
                $out[]  = trim( $url . ( $desc ? ' ' . $desc : '' ) );
            }

            return implode( ', ', $out );
        };

        $rewrite_css_urls = function( string $css ) use ( $remap_url ) : string {
            $css = preg_replace_callback(
                '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
                function( array $m ) use ( $remap_url ) {
                    $q   = (string) $m[1];
                    $url = (string) $m[2];
                    $new = $remap_url( $url );
                    return 'url(' . $q . $new . $q . ')';
                },
                $css
            );

            $css = preg_replace_callback(
                '/@import\s+(?:url\()??\s*([\'"]?)([^\'")\s;]+)\1\s*\)?/i',
                function( array $m ) use ( $remap_url ) {
                    $q   = (string) $m[1];
                    $url = (string) $m[2];
                    $new = $remap_url( $url );
                    return '@import ' . $q . $new . $q;
                },
                (string) $css
            );

            return (string) $css;
        };

        // DOM rewrite for attribute-based URLs, srcset lists, and inline CSS.
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        if ( $loaded ) {
            $xpath = new DOMXPath( $dom );
            $attrs = array( 'href','src','poster','content','style','data-src','data-lazy-src','data-original','data-bg','srcset','data-srcset' );

            foreach ( $attrs as $attr ) {
                $nodes = $xpath->query( '//*[@' . $attr . ']' );
                if ( ! ( $nodes instanceof DOMNodeList ) ) { continue; }

                foreach ( $nodes as $node ) {
                    $val = (string) $node->getAttribute( $attr );
                    if ( $val === '' ) { continue; }

                    if ( $attr === 'srcset' || $attr === 'data-srcset' ) {
                        $node->setAttribute( $attr, $rewrite_srcset( $val ) );
                        continue;
                    }

                    if ( $attr === 'style' ) {
                        $node->setAttribute( $attr, $rewrite_css_urls( $val ) );
                        continue;
                    }

                    $node->setAttribute( $attr, $remap_url( $val ) );
                }
            }

            // Rewrite CSS inside <style> blocks.
            $style_nodes = $dom->getElementsByTagName( 'style' );
            if ( $style_nodes instanceof DOMNodeList ) {
                foreach ( $style_nodes as $sn ) {
                    $sn->nodeValue = $rewrite_css_urls( (string) $sn->nodeValue );
                }
            }

            $html = $dom->saveHTML();
        }
        libxml_clear_errors();

        return (string) $html;
    }


    public function ensure_export_dir( string $export_dir ) {
        $export_dir = wp_normalize_path( $export_dir );
        if ( ! wp_mkdir_p( $export_dir ) ) {
            return new WP_Error( 'wp_mirror_export_dir', __( 'Failed to create export directory. Check permissions.', 'wp-mirror' ) );
        }
        if ( ! is_writable( $export_dir ) ) {
            return new WP_Error( 'wp_mirror_export_dir_not_writable', __( 'Export directory is not writable.', 'wp-mirror' ) );
        }
        return true;
    }

    public function write_html( string $dest_file, string $html ) {
        $dir = dirname( $dest_file );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'wp_mirror_mkdir_failed', __( 'Failed to create output directory.', 'wp-mirror' ) );
        }
        $bytes = file_put_contents( $dest_file, $html );
        if ( false === $bytes ) {
            return new WP_Error( 'wp_mirror_write_failed', __( 'Failed to write exported HTML file.', 'wp-mirror' ) );
        }
        return true;
    }
}
