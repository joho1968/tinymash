<?php
namespace app\classes;

final class TinyMashWriterLease {

    protected mixed $handle;
    protected bool $exclusive;
    protected bool $released = false;

    public function __construct( mixed $handle, bool $exclusive ) {
        if ( ! is_resource( $handle ) ) {
            throw new \InvalidArgumentException( 'Writer lease handle is invalid.' );
        }
        $this->handle = $handle;
        $this->exclusive = $exclusive;
    }

    public function isExclusive() : bool {
        return( $this->exclusive && ! $this->released );
    }

    public function release() : void {
        if ( $this->released ) {
            return;
        }
        @ flock( $this->handle, LOCK_UN );
        fclose( $this->handle );
        $this->released = true;
    }

    public function __destruct() {
        $this->release();
    }
}

final class TinyMashWriterGate {

    protected const MARKER_FORMAT = 'tinymash-update-maintenance';
    protected const MARKER_SCHEMA = 1;

    protected string $lock_filename;
    protected string $marker_filename;

    public function __construct( string $runtime_directory ) {
        $runtime_directory = rtrim( trim( $runtime_directory ), DIRECTORY_SEPARATOR );
        if ( $runtime_directory === '' || str_contains( $runtime_directory, "\0" ) || is_link( $runtime_directory ) ) {
            throw new \InvalidArgumentException( 'The writer gate path is unavailable.' );
        }
        $this->lock_filename = $runtime_directory . DIRECTORY_SEPARATOR . 'writer-gate.lock';
        $this->marker_filename = $runtime_directory . DIRECTORY_SEPARATOR . 'update-maintenance.json';
    }

    public function acquireShared( bool $wait = false ) : TinyMashWriterLease {
        if ( $this->readMaintenanceMarker() !== [] ) {
            throw new \RuntimeException( 'A tinymash update is in progress.' );
        }
        $handle = $this->openLock();
        $operation = LOCK_SH | ( $wait ? 0 : LOCK_NB );
        if ( ! @ flock( $handle, $operation ) ) {
            fclose( $handle );
            throw new \RuntimeException( 'A tinymash update is in progress.' );
        }
        try {
            if ( $this->readMaintenanceMarker() !== [] ) {
                throw new \RuntimeException( 'A tinymash update is in progress.' );
            }
            return( new TinyMashWriterLease( $handle, false ) );
        } catch ( \Throwable $exception ) {
            @ flock( $handle, LOCK_UN );
            fclose( $handle );
            throw $exception;
        }
    }

    public function acquireExclusive( string $transaction_id, bool $wait = true, bool $start_maintenance = true ) : TinyMashWriterLease {
        $transaction_id = strtolower( trim( $transaction_id ) );
        if ( preg_match( '/^[a-f0-9]{32}$/D', $transaction_id ) !== 1 ) {
            throw new \InvalidArgumentException( 'The update transaction ID is invalid.' );
        }
        $marker = $this->readMaintenanceMarker();
        if ( $marker !== [] && ! hash_equals( $transaction_id, (string) ( $marker['transaction_id'] ?? '' ) ) ) {
            throw new \RuntimeException( 'Another update transaction owns maintenance mode.' );
        }
        if ( $marker === [] ) {
            if ( ! $start_maintenance ) {
                throw new \RuntimeException( 'Update maintenance is no longer active.' );
            }
            $this->createMaintenanceMarker( $transaction_id );
        }

        $handle = $this->openLock();
        $operation = LOCK_EX | ( $wait ? 0 : LOCK_NB );
        if ( ! @ flock( $handle, $operation ) ) {
            fclose( $handle );
            throw new \RuntimeException( 'Site writers have not stopped yet.' );
        }
        $marker = $this->readMaintenanceMarker();
        if ( $marker === [] || ! hash_equals( $transaction_id, (string) ( $marker['transaction_id'] ?? '' ) ) ) {
            @ flock( $handle, LOCK_UN );
            fclose( $handle );
            throw new \RuntimeException( 'Update maintenance state changed while the writer gate was closing.' );
        }
        return( new TinyMashWriterLease( $handle, true ) );
    }

    public function acquireSetup() : TinyMashWriterLease {
        if ( $this->readMaintenanceMarker() !== [] ) {
            throw new \RuntimeException( 'A tinymash update is in progress.' );
        }
        $handle = $this->openLock();
        try {
            if ( ! @ flock( $handle, LOCK_EX | LOCK_NB ) ) {
                throw new \RuntimeException( 'The site is busy. Run setup again in a moment.' );
            }
            if ( $this->readMaintenanceMarker() !== [] ) {
                throw new \RuntimeException( 'A tinymash update is in progress.' );
            }
            return( new TinyMashWriterLease( $handle, true ) );
        } catch ( \Throwable $exception ) {
            @ flock( $handle, LOCK_UN );
            fclose( $handle );
            throw $exception;
        }
    }

    public function finishMaintenance( TinyMashWriterLease $lease, string $transaction_id ) : void {
        if ( ! $lease->isExclusive() ) {
            throw new \RuntimeException( 'An exclusive writer lease is required.' );
        }
        $marker = $this->readMaintenanceMarker();
        if ( $marker === [] || ! hash_equals( strtolower( trim( $transaction_id ) ), (string) ( $marker['transaction_id'] ?? '' ) ) ) {
            throw new \RuntimeException( 'Update maintenance state does not match this transaction.' );
        }
        if ( is_link( $this->marker_filename ) || ! @ unlink( $this->marker_filename ) ) {
            throw new \RuntimeException( 'Update maintenance mode could not be cleared.' );
        }
        $lease->release();
    }

    public function getMaintenanceStatus() : array {
        return( $this->readMaintenanceMarker() );
    }

    protected function openLock() : mixed {
        $directory = dirname( $this->lock_filename );
        clearstatcache();
        if ( is_link( $directory ) || is_link( dirname( $directory ) )
            || ! is_dir( $directory ) || ! is_readable( $directory ) || ! is_writable( $directory ) ) {
            throw new \RuntimeException( 'The writer gate directory is unavailable.' );
        }
        $stat = @ lstat( $this->lock_filename );
        if ( is_array( $stat ) && ( ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['nlink'] !== 1 ) ) {
            throw new \RuntimeException( 'The writer gate lock is unsafe.' );
        }
        $handle = @ fopen( $this->lock_filename, 'c+b' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'The writer gate lock is unavailable.' );
        }
        clearstatcache();
        $opened = fstat( $handle );
        $current = @ lstat( $this->lock_filename );
        if ( ! is_array( $opened ) || ! is_array( $current ) || ( $current['mode'] & 0170000 ) !== 0100000
            || $current['nlink'] !== 1 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] ) {
            fclose( $handle );
            throw new \RuntimeException( 'The writer gate lock changed while it was being opened.' );
        }
        @ chmod( $this->lock_filename, 0600 );
        return( $handle );
    }

    protected function createMaintenanceMarker( string $transaction_id ) : void {
        $payload = json_encode( [
            'format' => self::MARKER_FORMAT,
            'schema' => self::MARKER_SCHEMA,
            'transaction_id' => $transaction_id,
            'started_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
        if ( is_link( $this->marker_filename ) ) {
            throw new \RuntimeException( 'Update maintenance state is unsafe.' );
        }
        $handle = @ fopen( $this->marker_filename, 'xb' );
        if ( $handle === false ) {
            $marker = $this->readMaintenanceMarker();
            if ( $marker !== [] && hash_equals( $transaction_id, (string) ( $marker['transaction_id'] ?? '' ) ) ) {
                return;
            }
            throw new \RuntimeException( 'Update maintenance mode could not be enabled.' );
        }
        @ chmod( $this->marker_filename, 0600 );
        try {
            $offset = 0;
            while ( $offset < strlen( $payload ) ) {
                $written = fwrite( $handle, substr( $payload, $offset ) );
                if ( ! is_int( $written ) || $written < 1 ) {
                    break;
                }
                $offset += $written;
            }
            $complete = $offset === strlen( $payload ) && fflush( $handle );
        } finally {
            fclose( $handle );
        }
        if ( ! $complete ) {
            @ unlink( $this->marker_filename );
            throw new \RuntimeException( 'Update maintenance mode could not be enabled.' );
        }
    }

    protected function readMaintenanceMarker() : array {
        clearstatcache();
        if ( is_link( $this->marker_filename ) ) {
            throw new \RuntimeException( 'Update maintenance state is unsafe.' );
        }
        if ( ! file_exists( $this->marker_filename ) ) {
            return( [] );
        }
        $stat = @ lstat( $this->marker_filename );
        if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['nlink'] !== 1
            || ! is_readable( $this->marker_filename ) ) {
            throw new \RuntimeException( 'Update maintenance state is unsafe.' );
        }
        $size = @ filesize( $this->marker_filename );
        if ( ! is_int( $size ) || $size < 2 || $size > 4096 ) {
            throw new \RuntimeException( 'Update maintenance state is invalid.' );
        }
        try {
            $marker = json_decode( (string) file_get_contents( $this->marker_filename ), true, 16, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'Update maintenance state is invalid.' );
        }
        if ( ! is_array( $marker )
            || (string) ( $marker['format'] ?? '' ) !== self::MARKER_FORMAT
            || (int) ( $marker['schema'] ?? 0 ) !== self::MARKER_SCHEMA
            || preg_match( '/^[a-f0-9]{32}$/D', (string) ( $marker['transaction_id'] ?? '' ) ) !== 1
            || preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) ( $marker['started_at_utc'] ?? '' ) ) !== 1 ) {
            throw new \RuntimeException( 'Update maintenance state is invalid.' );
        }
        return( $marker );
    }
}
