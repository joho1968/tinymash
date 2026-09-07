<?php
namespace app\classes;

final class TinyMashUpdateJournal {

    protected const FORMAT = 'tinymash-update-transaction';
    protected const SCHEMA = 5;
    protected const MAX_BYTES = 1048576;

    protected string $filename;

    public function __construct( string $filename ) {
        $filename = trim( $filename );
        $directory = dirname( $filename );
        if ( $filename === ''
            || str_contains( $filename, "\0" )
            || is_link( $filename )
            || is_link( $directory )
            || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
            throw new \InvalidArgumentException( 'The update journal path is unavailable.' );
        }
        $this->filename = $filename;
    }

    public function read() : array {
        return( $this->readUnlocked() );
    }

    public function mutate( callable $callback ) : mixed {
        return( $this->withLock(
            function( array $journal ) use ( $callback ) : mixed {
                $save = function( array $updated ) : void {
                    $this->validate( $updated );
                    $this->writeUnlocked( $updated );
                };
                return( $callback( $journal, $save ) );
            },
            LOCK_EX
        ) );
    }

    public static function create( string $installed_version, string $target_version, string $archive_sha256, int $timestamp ) : array {
        return( [
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'transaction_id' => bin2hex( random_bytes( 16 ) ),
            'installed_version' => $installed_version,
            'target_version' => $target_version,
            'archive_sha256' => $archive_sha256,
            'phase' => 'preparing',
            'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
            'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
            'steps' => [
                'code_rollback' => [ 'status' => 'waiting' ],
                'worker_runtime' => [ 'status' => 'waiting' ],
                'target_release' => [ 'status' => 'waiting' ],
                'site_backup' => [ 'status' => 'waiting' ],
                'backup_recheck' => [ 'status' => 'waiting' ],
                'managed_files' => [ 'status' => 'waiting' ],
                'final_audit' => [ 'status' => 'waiting' ],
                'opcache' => [ 'status' => 'waiting' ],
                'health_check' => [ 'status' => 'waiting' ],
            ],
            'error' => '',
        ] );
    }

    protected function withLock( callable $callback, int $operation ) : mixed {
        $directory = dirname( $this->filename );
        if ( is_link( $directory ) || ! is_dir( $directory ) || ! is_readable( $directory ) || ! is_writable( $directory ) ) {
            throw new \RuntimeException( 'The update journal directory is unavailable.' );
        }
        $lock_filename = $this->filename . '.lock';
        if ( is_link( $lock_filename ) ) {
            throw new \RuntimeException( 'The update journal lock is unsafe.' );
        }
        $handle = @ fopen( $lock_filename, 'c+b' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'The update journal lock is unavailable.' );
        }
        @ chmod( $lock_filename, 0600 );
        try {
            if ( ! @ flock( $handle, $operation ) ) {
                throw new \RuntimeException( 'The update journal could not be locked.' );
            }
            return( $callback( $this->readUnlocked() ) );
        } finally {
            @ flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    protected function readUnlocked() : array {
        if ( ! file_exists( $this->filename ) ) {
            return( [] );
        }
        if ( is_link( $this->filename ) || ! is_file( $this->filename ) || ! is_readable( $this->filename ) ) {
            throw new \RuntimeException( 'The update journal is unavailable.' );
        }
        $size = @ filesize( $this->filename );
        if ( ! is_int( $size ) || $size < 2 || $size > self::MAX_BYTES ) {
            throw new \RuntimeException( 'The update journal size is invalid.' );
        }
        try {
            $journal = json_decode( (string) file_get_contents( $this->filename ), true, 32, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        if ( ! is_array( $journal ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        $this->validate( $journal );
        return( $journal );
    }

    protected function writeUnlocked( array $journal ) : void {
        $payload = json_encode( $journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
        $temporary = $this->filename . '.tmp-' . bin2hex( random_bytes( 8 ) );
        $handle = @ fopen( $temporary, 'xb' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'The update journal could not be stored.' );
        }
        @ chmod( $temporary, 0600 );
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
        if ( ! $complete || ! @ rename( $temporary, $this->filename ) ) {
            @ unlink( $temporary );
            throw new \RuntimeException( 'The update journal could not be stored.' );
        }
        @ chmod( $this->filename, 0600 );
    }

    protected function validate( array $journal ) : void {
        $steps = is_array( $journal['steps'] ?? null ) ? $journal['steps'] : [];
        if ( (string) ( $journal['format'] ?? '' ) !== self::FORMAT
            || (int) ( $journal['schema'] ?? 0 ) !== self::SCHEMA
            || preg_match( '/^[a-f0-9]{32}$/D', (string) ( $journal['transaction_id'] ?? '' ) ) !== 1
            || ! $this->isVersion( (string) ( $journal['installed_version'] ?? '' ) )
            || ! $this->isVersion( (string) ( $journal['target_version'] ?? '' ) )
            || preg_match( '/^[a-f0-9]{64}$/D', (string) ( $journal['archive_sha256'] ?? '' ) ) !== 1
            || ! in_array( (string) ( $journal['phase'] ?? '' ), [ 'preparing', 'prepared', 'installing', 'files_replaced', 'health_passed', 'complete', 'rolling_back', 'rolled_back', 'failed' ], true )
            || ! $this->isTimestamp( (string) ( $journal['created_at_utc'] ?? '' ) )
            || ! $this->isTimestamp( (string) ( $journal['updated_at_utc'] ?? '' ) )
            || ! isset( $steps['code_rollback'], $steps['worker_runtime'], $steps['target_release'], $steps['site_backup'], $steps['backup_recheck'], $steps['managed_files'], $steps['final_audit'], $steps['opcache'], $steps['health_check'] )
            || ! is_string( $journal['error'] ?? null )
            || mb_strlen( (string) $journal['error'] ) > 256 ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        foreach ( [ 'code_rollback', 'worker_runtime', 'target_release', 'site_backup', 'backup_recheck', 'managed_files', 'final_audit', 'opcache', 'health_check' ] as $step ) {
            if ( ! is_array( $steps[$step] )
                || ! in_array( (string) ( $steps[$step]['status'] ?? '' ), [ 'waiting', 'running', 'complete' ], true ) ) {
                throw new \RuntimeException( 'The update journal is invalid.' );
            }
        }
        $worker_runtime = (array) $steps['worker_runtime'];
        if ( (string) ( $worker_runtime['status'] ?? '' ) === 'complete'
            && ( (int) ( $worker_runtime['files'] ?? 0 ) < 1
                || (int) ( $worker_runtime['bytes'] ?? 0 ) < 1
                || preg_match( '/^[a-f0-9]{64}$/D', (string) ( $worker_runtime['manifest_sha256'] ?? '' ) ) !== 1 ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        $managed_files = (array) $steps['managed_files'];
        if ( (string) ( $managed_files['status'] ?? '' ) !== 'waiting' ) {
            $total = $managed_files['total'] ?? null;
            $next_index = $managed_files['next_index'] ?? null;
            $current = $managed_files['current'] ?? null;
            if ( preg_match( '/^[a-f0-9]{64}$/D', (string) ( $managed_files['plan_sha256'] ?? '' ) ) !== 1
                || ! is_int( $total )
                || $total < 1
                || $total > 100001
                || ! is_int( $next_index )
                || $next_index < 0
                || $next_index > $total
                || ! is_array( $current )
                || ! in_array( (string) ( $managed_files['direction'] ?? '' ), [ 'forward', 'rollback' ], true ) ) {
                throw new \RuntimeException( 'The update journal is invalid.' );
            }
            if ( $current !== []
                && ( ( $current['index'] ?? null ) !== $next_index
                    || ! is_string( $current['path'] ?? null )
                    || ! in_array( (string) ( $current['action'] ?? '' ), [ 'replace', 'delete', 'directory', 'manifest' ], true ) ) ) {
                throw new \RuntimeException( 'The update journal is invalid.' );
            }
            if ( (string) $managed_files['status'] === 'complete'
                && ( $next_index !== $total || $current !== [] ) ) {
                throw new \RuntimeException( 'The update journal is invalid.' );
            }
        }
        if ( (string) ( $steps['backup_recheck']['status'] ?? '' ) === 'complete'
            && preg_match( '/^[a-f0-9]{64}$/D', (string) ( $steps['backup_recheck']['state_inventory_sha256'] ?? '' ) ) !== 1 ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        if ( (string) ( $steps['final_audit']['status'] ?? '' ) === 'complete'
            && ! in_array( (string) ( $steps['final_audit']['release'] ?? '' ), [ 'target', 'installed' ], true ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        if ( (string) ( $steps['opcache']['status'] ?? '' ) === 'complete'
            && ! in_array( (string) ( $steps['opcache']['release'] ?? '' ), [ 'target', 'installed' ], true ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        $phase = (string) $journal['phase'];
        if ( $phase === 'files_replaced'
            && ( (string) ( $managed_files['status'] ?? '' ) !== 'complete'
                || (string) ( $managed_files['direction'] ?? '' ) !== 'forward'
                || (string) ( $steps['final_audit']['release'] ?? '' ) !== 'target'
                || (string) ( $steps['opcache']['release'] ?? '' ) !== 'target' ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        if ( $phase === 'rolled_back'
            && ( (string) ( $managed_files['status'] ?? '' ) !== 'complete'
                || (string) ( $managed_files['direction'] ?? '' ) !== 'rollback'
                || (string) ( $steps['final_audit']['release'] ?? '' ) !== 'installed' ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
        if ( in_array( $phase, [ 'health_passed', 'complete' ], true )
            && ( (string) ( $steps['health_check']['status'] ?? '' ) !== 'complete'
                || ! hash_equals( (string) $journal['target_version'], (string) ( $steps['health_check']['version'] ?? '' ) ) ) ) {
            throw new \RuntimeException( 'The update journal is invalid.' );
        }
    }

    protected function isVersion( string $version ) : bool {
        return( preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version ) === 1 );
    }

    protected function isTimestamp( string $timestamp ) : bool {
        return( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $timestamp ) === 1 );
    }
}
