<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashUpdateJournal.php';

final class TinyMashUpdateRecoveryAccessService {

    protected const ACCESS_FORMAT = 'tinymash-update-recovery-access';
    protected const ACCESS_SCHEMA = 1;

    protected TinyMashUpdateJournal $journal;
    protected string $recovery_root;
    protected $clock;

    public function __construct( TinyMashUpdateJournal $journal, string $recovery_root, ?callable $clock = null ) {
        $recovery_root = rtrim( trim( $recovery_root ), DIRECTORY_SEPARATOR );
        if ( $recovery_root === '' || str_contains( $recovery_root, "\0" ) || is_link( $recovery_root ) ) {
            throw new \InvalidArgumentException( 'The update recovery path is unavailable.' );
        }
        $this->journal = $journal;
        $this->recovery_root = $recovery_root;
        $this->clock = $clock;
    }

    public function issue( int $lifetime_seconds = 3600 ) : array {
        if ( $lifetime_seconds < 300 || $lifetime_seconds > 7200 ) {
            throw new \InvalidArgumentException( 'The recovery access lifetime is invalid.' );
        }
        $journal = $this->journal->read();
        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1
            || ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'prepared', 'installing', 'files_replaced', 'failed' ], true ) ) {
            throw new \RuntimeException( 'No recoverable update transaction is available.' );
        }
        $worker_sha256 = (string) ( $journal['steps']['worker_runtime']['manifest_sha256'] ?? '' );
        if ( (string) ( $journal['steps']['worker_runtime']['status'] ?? '' ) !== 'complete'
            || preg_match( '/^[a-f0-9]{64}$/D', $worker_sha256 ) !== 1 ) {
            throw new \RuntimeException( 'The recovery worker is unavailable.' );
        }
        $transaction_directory = $this->transactionDirectory( $transaction_id );
        $worker_manifest = $transaction_directory . DIRECTORY_SEPARATOR . 'worker-runtime' . DIRECTORY_SEPARATOR . 'manifest.json';
        $actual_worker_sha256 = @ hash_file( 'sha256', $worker_manifest );
        if ( is_link( $worker_manifest )
            || ! is_file( $worker_manifest )
            || ! is_string( $actual_worker_sha256 )
            || ! hash_equals( $worker_sha256, $actual_worker_sha256 ) ) {
            throw new \RuntimeException( 'The recovery worker is unavailable.' );
        }

        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $now = $this->now();
        $payload = [
            'format' => self::ACCESS_FORMAT,
            'schema' => self::ACCESS_SCHEMA,
            'transaction_id' => $transaction_id,
            'token_sha256' => hash( 'sha256', $token ),
            'worker_manifest_sha256' => $worker_sha256,
            'issued_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
            'expires_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $now + $lifetime_seconds ),
        ];
        $this->writeAccessFile( $transaction_directory . DIRECTORY_SEPARATOR . 'recovery-access.json', $payload );
        return( [
            'token' => $token,
            'expires_at_utc' => $payload['expires_at_utc'],
        ] );
    }

    public function revoke() : void {
        $journal = $this->journal->read();
        $transaction_id = (string) ( $journal['transaction_id'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1 ) {
            return;
        }
        $filename = $this->transactionDirectory( $transaction_id ) . DIRECTORY_SEPARATOR . 'recovery-access.json';
        if ( is_link( $filename ) || ( is_file( $filename ) && ! @ unlink( $filename ) ) ) {
            throw new \RuntimeException( 'Recovery access could not be revoked.' );
        }
    }

    protected function transactionDirectory( string $transaction_id ) : string {
        $directory = $this->recovery_root . DIRECTORY_SEPARATOR . 'transaction-' . $transaction_id;
        if ( dirname( $directory ) !== $this->recovery_root
            || is_link( $this->recovery_root )
            || ! is_dir( $this->recovery_root )
            || is_link( $directory )
            || ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'The update recovery files are unavailable.' );
        }
        $real_root = @ realpath( $this->recovery_root );
        $real_directory = @ realpath( $directory );
        if ( ! is_string( $real_root )
            || ! is_string( $real_directory )
            || ! hash_equals( rtrim( $real_root, DIRECTORY_SEPARATOR ), $this->recovery_root )
            || ! hash_equals( rtrim( $real_directory, DIRECTORY_SEPARATOR ), $directory ) ) {
            throw new \RuntimeException( 'The update recovery files are unavailable.' );
        }
        return( $directory );
    }

    protected function writeAccessFile( string $filename, array $access ) : void {
        if ( is_link( $filename ) ) {
            throw new \RuntimeException( 'The recovery access file is unsafe.' );
        }
        $payload = json_encode( $access, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
        $temporary = $filename . '.tmp-' . bin2hex( random_bytes( 8 ) );
        $handle = @ fopen( $temporary, 'xb' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Recovery access could not be stored.' );
        }
        @ chmod( $temporary, 0600 );
        try {
            $written = fwrite( $handle, $payload );
            $complete = is_int( $written ) && $written === strlen( $payload ) && fflush( $handle );
        } finally {
            fclose( $handle );
        }
        if ( ! $complete || ! @ rename( $temporary, $filename ) ) {
            @ unlink( $temporary );
            throw new \RuntimeException( 'Recovery access could not be stored.' );
        }
        @ chmod( $filename, 0600 );
        if ( ( @ fileperms( $filename ) & 0777 ) !== 0600 ) {
            @ unlink( $filename );
            throw new \RuntimeException( 'Recovery access could not be stored privately.' );
        }
    }

    protected function now() : int {
        return( is_callable( $this->clock ) ? (int) ( $this->clock )() : time() );
    }
}
