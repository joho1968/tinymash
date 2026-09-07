<?php
declare(strict_types=1);

if ( ! defined( 'TINYMASH_RUNNING' ) ) {
    define( 'TINYMASH_RUNNING', true );
}

if ( PHP_SAPI !== 'cli' ) {
    $request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?? '/' );
    if ( $request_path === '/_tinymash/health' || str_ends_with( $request_path, '/_tinymash/health' ) ) {
        $token = trim( (string) ( $_POST['token'] ?? '' ) );
        try {
            if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST'
                || preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 ) {
                throw new \InvalidArgumentException( 'access' );
            }
            require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TinyMashUpdateRecoveryEntryPoint.php';
            $health_context = \app\classes\TinyMashUpdateRecoveryEntryPoint::authorizeHealth( dirname( __DIR__ ), $token );
            define( 'TINYMASH_UPDATE_HEALTH_PROBE', true );
            define( 'TINYMASH_UPDATE_TRANSACTION_ID', (string) $health_context['transaction_id'] );
        } catch ( \Throwable ) {
            http_response_code( 404 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Cache-Control: no-store' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Referrer-Policy: no-referrer' );
            header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
            echo '{"error":"Update health access was not found."}';
            exit;
        }
    }
    if ( $request_path === '/_tinymash/update' || str_ends_with( $request_path, '/_tinymash/update' ) ) {
        $installation_root = dirname( __DIR__ );
        $entry_filename = $installation_root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TinyMashUpdateRecoveryEntryPoint.php';
        $runtime_directory = $installation_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime';
        $marker_filename = $runtime_directory . DIRECTORY_SEPARATOR . 'update-maintenance.json';
        $marker_size = @ filesize( $marker_filename );
        if ( ! is_link( $marker_filename ) && is_file( $marker_filename ) && is_int( $marker_size ) && $marker_size >= 2 && $marker_size <= 4096 ) {
            $marker = json_decode( (string) file_get_contents( $marker_filename ), true, 16 );
            $transaction_id = is_array( $marker ) ? (string) ( $marker['transaction_id'] ?? '' ) : '';
            if ( (string) ( $marker['format'] ?? '' ) === 'tinymash-update-maintenance'
                && (int) ( $marker['schema'] ?? 0 ) === 1
                && preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) === 1 ) {
                $entry_filename = '';
                $transaction_directory = $runtime_directory . DIRECTORY_SEPARATOR . 'update-transactions' . DIRECTORY_SEPARATOR . 'transaction-' . $transaction_id;
                $worker_directory = $transaction_directory . DIRECTORY_SEPARATOR . 'worker-runtime';
                $worker_manifest_filename = $worker_directory . DIRECTORY_SEPARATOR . 'manifest.json';
                $journal_filename = $runtime_directory . DIRECTORY_SEPARATOR . 'update-transaction.json';
                $candidate = $worker_directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TinyMashUpdateRecoveryEntryPoint.php';
                $journal_size = @ filesize( $journal_filename );
                $worker_manifest_size = @ filesize( $worker_manifest_filename );
                $journal = ! is_link( $journal_filename ) && is_int( $journal_size ) && $journal_size >= 2 && $journal_size <= 1048576
                    ? json_decode( (string) file_get_contents( $journal_filename ), true, 32 )
                    : null;
                $worker_manifest = ! is_link( $worker_manifest_filename ) && is_int( $worker_manifest_size ) && $worker_manifest_size >= 2 && $worker_manifest_size <= 65536
                    ? json_decode( (string) file_get_contents( $worker_manifest_filename ), true, 32 )
                    : null;
                $entry_metadata = is_array( $worker_manifest )
                    ? (array) ( $worker_manifest['files']['app/classes/TinyMashUpdateRecoveryEntryPoint.php'] ?? [] )
                    : [];
                $expected_manifest_sha256 = is_array( $journal ) ? (string) ( $journal['steps']['worker_runtime']['manifest_sha256'] ?? '' ) : '';
                $expected_entry_sha256 = (string) ( $entry_metadata['sha256'] ?? '' );
                $expected_entry_bytes = $entry_metadata['bytes'] ?? null;
                if ( ! is_link( $transaction_directory )
                    && ! is_link( $worker_directory )
                    && ! is_link( dirname( dirname( $candidate ) ) )
                    && ! is_link( dirname( $candidate ) )
                    && ! is_link( $candidate )
                    && is_file( $candidate )
                    && is_readable( $candidate )
                    && is_array( $journal )
                    && hash_equals( $transaction_id, (string) ( $journal['transaction_id'] ?? '' ) )
                    && preg_match( '/^[a-f0-9]{64}$/D', $expected_manifest_sha256 ) === 1
                    && hash_equals( $expected_manifest_sha256, (string) @ hash_file( 'sha256', $worker_manifest_filename ) )
                    && (string) ( $worker_manifest['format'] ?? '' ) === 'tinymash-update-worker-runtime'
                    && (int) ( $worker_manifest['schema'] ?? 0 ) === 1
                    && is_int( $expected_entry_bytes )
                    && $expected_entry_bytes > 0
                    && @ filesize( $candidate ) === $expected_entry_bytes
                    && preg_match( '/^[a-f0-9]{64}$/D', $expected_entry_sha256 ) === 1
                    && hash_equals( $expected_entry_sha256, (string) @ hash_file( 'sha256', $candidate ) ) ) {
                    $entry_filename = $candidate;
                }
            }
        }
        if ( $entry_filename === '' ) {
            http_response_code( 503 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Cache-Control: no-store' );
            header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
            echo '{"error":"Update recovery is unavailable."}';
            exit;
        }
        require $entry_filename;
        \app\classes\TinyMashUpdateRecoveryEntryPoint::handle( dirname( __DIR__ ) );
    }
}

require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';
