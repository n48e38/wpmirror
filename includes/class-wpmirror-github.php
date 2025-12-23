<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPMirror_GitHub {

    private $api = 'https://api.github.com';

    public function request( string $method, string $path, string $token, $body = null ) {
        $url = rtrim( $this->api, '/' ) . '/' . ltrim( $path, '/' );

        $headers = array(
            'Accept'              => 'application/vnd.github+json',
            'X-GitHub-Api-Version'=> '2022-11-28',
            'User-Agent'          => 'WP-Mirror/' . WP_MIRROR_VERSION,
        );

        if ( $token !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = array(
            'method'      => $method,
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => $headers,
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        $resp = wp_remote_request( $url, $args );

        if ( is_wp_error( $resp ) ) { return $resp; }

        $code    = (int) wp_remote_retrieve_response_code( $resp );
        $h       = (array) wp_remote_retrieve_headers( $resp );
        $raw     = wp_remote_retrieve_body( $resp );

        $decoded = null;
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( null === $decoded ) { $decoded = $raw; }
        } else {
            $decoded = array();
        }

        return array( $code, $h, $decoded );
    }

    public function test_connection( string $token ) {
        return $this->request( 'GET', '/user', $token, null );
    }

    public function get_branch_ref( string $owner, string $repo, string $branch, string $token ) {
        return $this->request( 'GET', sprintf( '/repos/%s/%s/git/ref/heads/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $branch ) ), $token, null );
    }

    public function get_commit( string $owner, string $repo, string $sha, string $token ) {
        return $this->request( 'GET', sprintf( '/repos/%s/%s/git/commits/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $sha ) ), $token, null );
    }

    public function create_blob( string $owner, string $repo, string $token, string $content_base64 ) {
        $body = array( 'content' => $content_base64, 'encoding' => 'base64' );
        return $this->request( 'POST', sprintf( '/repos/%s/%s/git/blobs', rawurlencode( $owner ), rawurlencode( $repo ) ), $token, $body );
    }

    public function create_tree( string $owner, string $repo, string $token, string $base_tree_sha, array $tree_entries ) {
        $body = array( 'base_tree' => $base_tree_sha, 'tree' => $tree_entries );
        return $this->request( 'POST', sprintf( '/repos/%s/%s/git/trees', rawurlencode( $owner ), rawurlencode( $repo ) ), $token, $body );
    }

    public function create_commit( string $owner, string $repo, string $token, string $message, string $tree_sha, string $parent_sha ) {
        $body = array( 'message' => $message, 'tree' => $tree_sha, 'parents' => array( $parent_sha ) );
        return $this->request( 'POST', sprintf( '/repos/%s/%s/git/commits', rawurlencode( $owner ), rawurlencode( $repo ) ), $token, $body );
    }

    public function update_ref( string $owner, string $repo, string $token, string $branch, string $commit_sha ) {
        $body = array( 'sha' => $commit_sha, 'force' => false );
        return $this->request( 'PATCH', sprintf( '/repos/%s/%s/git/refs/heads/%s', rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $branch ) ), $token, $body );
    }
}
