<?php
namespace app\classes;

final class TinyMashUpdateRecoveryEntryPoint {

    protected const WORKER_RUNTIME_FILES = [
        'app/classes/TinyMashAuthoritativeStateInventory.php',
        'app/classes/TinyMashDeployAuditService.php',
        'app/classes/TinyMashDeployPolicy.php',
        'app/classes/TinyMashUpdateCliRecoveryService.php',
        'app/classes/TinyMashUpdateHealthService.php',
        'app/classes/TinyMashUpdateInstallerService.php',
        'app/classes/TinyMashUpdateJournal.php',
        'app/classes/TinyMashUpdateRecoveryArtifactService.php',
        'app/classes/TinyMashUpdateRecoveryEntryPoint.php',
        'app/classes/TinyMashUpdateRecoveryWorker.php',
        'app/classes/TinyMashWriterGate.php',
    ];

    public static function handle( string $installation_root ) : never {
        $result = self::dispatch(
            $installation_root,
            (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
            is_array( $_POST ?? null ) ? $_POST : []
        );
        http_response_code( (int) $result['status'] );
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Cache-Control: no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Frame-Options: DENY' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        if ( (int) $result['status'] === 405 ) {
            header( 'Allow: POST' );
        }
        echo json_encode( $result['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        exit;
    }

    public static function dispatch( string $installation_root, string $method, array $input, ?int $now = null ) : array {
        if ( strtoupper( trim( $method ) ) !== 'POST' ) {
            return( self::response( 405, [ 'error' => 'Method not allowed.' ] ) );
        }
        $action = strtolower( trim( (string) ( $input['action'] ?? '' ) ) );
        $token = trim( (string) ( $input['token'] ?? '' ) );
        if ( ! in_array( $action, [ 'status', 'start', 'resume', 'rollback' ], true )
            || preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 ) {
            return( self::response( 404, [ 'error' => 'Recovery access was not found.' ] ) );
        }

        try {
            $context = self::authorize( $installation_root, $token, $now ?? time(), $action );
            $worker = self::loadWorker( $context );
            $status = $action === 'status' ? $worker->getStatus() : $worker->run( $action );
            return( self::response( 200, [ 'status' => $status ] ) );
        } catch ( \InvalidArgumentException ) {
            return( self::response( 404, [ 'error' => 'Recovery access was not found.' ] ) );
        } catch ( \Throwable ) {
            return( self::response( 409, [ 'error' => 'The recovery action could not be completed.' ] ) );
        }
    }

    public static function authorizeHealth( string $installation_root, string $token, ?int $now = null ) : array {
        $context = self::authorize( $installation_root, $token, $now ?? time(), 'status' );
        $journal = self::readJson( (string) $context['journal_filename'], 1048576 );
        $marker = self::readJson( (string) $context['runtime_directory'] . DIRECTORY_SEPARATOR . 'update-maintenance.json', 4096 );
        if ( ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'files_replaced', 'health_passed', 'complete' ], true )
            || (string) ( $marker['format'] ?? '' ) !== 'tinymash-update-maintenance'
            || (int) ( $marker['schema'] ?? 0 ) !== 1
            || ! hash_equals( (string) $journal['transaction_id'], (string) ( $marker['transaction_id'] ?? '' ) ) ) {
            throw new \InvalidArgumentException( 'access' );
        }
        $context['transaction_id'] = (string) $journal['transaction_id'];
        return( $context );
    }

    protected static function authorize( string $installation_root, string $token, int $now, string $action ) : array {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( ! is_string( $real_app ) || ! hash_equals( rtrim( dirname( $real_app ), DIRECTORY_SEPARATOR ), $installation_root ) ) {
            throw new \RuntimeException( 'installation_root' );
        }
        $runtime = $installation_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime';
        try {
            $journal = self::readJson( $runtime . DIRECTORY_SEPARATOR . 'update-transaction.json', 1048576 );
        } catch ( \Throwable ) {
            throw new \InvalidArgumentException( 'access' );
        }
        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        $worker_sha256 = (string) ( $journal['steps']['worker_runtime']['manifest_sha256'] ?? '' );
        if ( (string) ( $journal['format'] ?? '' ) !== 'tinymash-update-transaction'
            || (int) ( $journal['schema'] ?? 0 ) !== 5
            || preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1
            || ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'prepared', 'installing', 'files_replaced', 'health_passed', 'complete', 'rolling_back', 'rolled_back', 'failed' ], true )
            || (string) ( $journal['steps']['worker_runtime']['status'] ?? '' ) !== 'complete'
            || preg_match( '/^[a-f0-9]{64}$/D', $worker_sha256 ) !== 1 ) {
            throw new \InvalidArgumentException( 'access' );
        }
        $recovery_root = $runtime . DIRECTORY_SEPARATOR . 'update-transactions';
        $transaction_directory = $recovery_root . DIRECTORY_SEPARATOR . 'transaction-' . $transaction_id;
        $access_filename = $transaction_directory . DIRECTORY_SEPARATOR . 'recovery-access.json';
        try {
            self::assertRealDirectory( $recovery_root );
            self::assertRealDirectory( $transaction_directory );
            if ( dirname( $transaction_directory ) !== $recovery_root ) {
                throw new \RuntimeException( 'transaction' );
            }
            $access = self::readJson( $access_filename, 8192 );
            if ( ( @ fileperms( $access_filename ) & 0777 ) !== 0600 ) {
                throw new \RuntimeException( 'access_mode' );
            }
        } catch ( \Throwable ) {
            throw new \InvalidArgumentException( 'access' );
        }
        $issued_at_utc = (string) ( $access['issued_at_utc'] ?? '' );
        $expires_at_utc = (string) ( $access['expires_at_utc'] ?? '' );
        $issued_at = strtotime( $issued_at_utc );
        $expires_at = strtotime( $expires_at_utc );
        if ( (string) ( $access['format'] ?? '' ) !== 'tinymash-update-recovery-access'
            || (int) ( $access['schema'] ?? 0 ) !== 1
            || ! hash_equals( $transaction_id, (string) ( $access['transaction_id'] ?? '' ) )
            || ! hash_equals( $worker_sha256, (string) ( $access['worker_manifest_sha256'] ?? '' ) )
            || preg_match( '/^[a-f0-9]{64}$/D', (string) ( $access['token_sha256'] ?? '' ) ) !== 1
            || ! hash_equals( (string) $access['token_sha256'], hash( 'sha256', $token ) )
            || preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $issued_at_utc ) !== 1
            || preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $expires_at_utc ) !== 1
            || ! is_int( $issued_at )
            || ! is_int( $expires_at )
            || $expires_at <= $issued_at
            || $expires_at > $issued_at + 7200
            || $expires_at <= $now ) {
            throw new \InvalidArgumentException( 'access' );
        }

        $phase = (string) $journal['phase'];
        if ( ( $action === 'start' && $phase !== 'prepared' )
            || ( $action === 'resume' && ! in_array( $phase, [ 'installing', 'files_replaced', 'failed' ], true ) )
            || ( $action === 'rollback' && ! in_array( $phase, [ 'installing', 'files_replaced', 'rolling_back', 'rolled_back', 'failed' ], true ) ) ) {
            throw new \InvalidArgumentException( 'access' );
        }
        if ( in_array( $action, [ 'resume', 'rollback' ], true ) ) {
            try {
                $marker = self::readJson( $runtime . DIRECTORY_SEPARATOR . 'update-maintenance.json', 4096 );
            } catch ( \Throwable ) {
                throw new \InvalidArgumentException( 'access' );
            }
            if ( (string) ( $marker['format'] ?? '' ) !== 'tinymash-update-maintenance'
                || (int) ( $marker['schema'] ?? 0 ) !== 1
                || ! hash_equals( $transaction_id, (string) ( $marker['transaction_id'] ?? '' ) ) ) {
                throw new \InvalidArgumentException( 'access' );
            }
        }

        $worker_root = $transaction_directory . DIRECTORY_SEPARATOR . 'worker-runtime';
        $manifest_filename = $worker_root . DIRECTORY_SEPARATOR . 'manifest.json';
        $actual_manifest_sha256 = @ hash_file( 'sha256', $manifest_filename );
        if ( ! is_string( $actual_manifest_sha256 ) || ! hash_equals( $worker_sha256, $actual_manifest_sha256 ) ) {
            throw new \RuntimeException( 'worker_manifest' );
        }
        $manifest = self::readJson( $manifest_filename, 65536 );
        $files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [];
        if ( (string) ( $manifest['format'] ?? '' ) !== 'tinymash-update-worker-runtime'
            || (int) ( $manifest['schema'] ?? 0 ) !== 1
            || array_keys( $files ) !== self::WORKER_RUNTIME_FILES ) {
            throw new \RuntimeException( 'worker_manifest' );
        }
        foreach ( $files as $relative_path => $metadata ) {
            $metadata = is_array( $metadata ) ? $metadata : [];
            $filename = $worker_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
            $expected_bytes = $metadata['bytes'] ?? null;
            $expected_sha256 = (string) ( $metadata['sha256'] ?? '' );
            if ( ! is_int( $expected_bytes )
                || $expected_bytes < 1
                || preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) !== 1
                || is_link( $filename )
                || ! is_file( $filename )
                || ! is_readable( $filename )
                || @ filesize( $filename ) !== $expected_bytes
                || ! hash_equals( $expected_sha256, (string) @ hash_file( 'sha256', $filename ) ) ) {
                throw new \RuntimeException( 'worker_file' );
            }
        }
        return( [
            'installation_root' => $installation_root,
            'recovery_root' => $recovery_root,
            'runtime_directory' => $runtime,
            'journal_filename' => $runtime . DIRECTORY_SEPARATOR . 'update-transaction.json',
            'worker_filename' => $worker_root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'TinyMashUpdateRecoveryWorker.php',
        ] );
    }

    protected static function loadWorker( array $context ) : TinyMashUpdateRecoveryWorker {
        if ( class_exists( TinyMashUpdateRecoveryWorker::class, false ) ) {
            throw new \RuntimeException( 'worker_context' );
        }
        require (string) $context['worker_filename'];
        return( new TinyMashUpdateRecoveryWorker(
            (string) $context['installation_root'],
            (string) $context['recovery_root'],
            (string) $context['journal_filename'],
            (string) $context['runtime_directory']
        ) );
    }

    protected static function readJson( string $filename, int $maximum_bytes ) : array {
        $size = @ filesize( $filename );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename )
            || ! is_int( $size ) || $size < 2 || $size > $maximum_bytes ) {
            throw new \RuntimeException( 'json_file' );
        }
        try {
            $value = json_decode( (string) file_get_contents( $filename ), true, 32, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'json_file' );
        }
        if ( ! is_array( $value ) ) {
            throw new \RuntimeException( 'json_file' );
        }
        return( $value );
    }

    protected static function assertRealDirectory( string $directory ) : void {
        $real = @ realpath( $directory );
        if ( is_link( $directory ) || ! is_dir( $directory ) || ! is_string( $real )
            || ! hash_equals( rtrim( $real, DIRECTORY_SEPARATOR ), $directory ) ) {
            throw new \RuntimeException( 'directory' );
        }
    }

    protected static function response( int $status, array $body ) : array {
        return( [ 'status' => $status, 'body' => $body ] );
    }
}
