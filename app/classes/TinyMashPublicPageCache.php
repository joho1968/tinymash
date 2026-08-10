<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

class TinyMashPublicPageCache {

    protected string $cache_directory;
    protected int $ttl_seconds;

    public function __construct( string $cache_directory, int $ttl_seconds = 300 ) {
        $this->cache_directory = rtrim( $cache_directory, DIRECTORY_SEPARATOR );
        $this->ttl_seconds = max( 60, $ttl_seconds );
    }

    public function getGenerationToken() : string {
        if ( ! $this->ensureCacheDirectory() ) {
            return( '0' );
        }

        $token = $this->withGenerationLock(
            LOCK_SH,
            fn() : string => $this->readGenerationTokenUnlocked()
        );
        return( is_string( $token ) && $token !== '' ? $token : '0' );
    }

    public function read( string $request_uri, string $theme_key = 'baseline', string $expected_generation = '' ) : string|false {
        if ( ! $this->ensureCacheDirectory() ) {
            return( false );
        }

        $result = $this->withGenerationLock(
            LOCK_SH,
            function() use ( $request_uri, $theme_key, $expected_generation ) : string|false {
                $generation = $this->readGenerationTokenUnlocked();
                if ( $expected_generation !== '' && ! hash_equals( $generation, $expected_generation ) ) {
                    return( false );
                }

                $filename = $this->buildCacheFilename( $request_uri, $theme_key, $generation );
                if ( is_link( $filename ) || ! is_file( $filename ) ) {
                    return( false );
                }

                $modified_at = @ filemtime( $filename );
                if ( ! is_int( $modified_at ) || $modified_at < 1 || ( $modified_at + $this->ttl_seconds ) < time() ) {
                    @ unlink( $filename );
                    return( false );
                }

                $html = @ file_get_contents( $filename );
                return( is_string( $html ) && $html !== '' ? $html : false );
            }
        );
        return( is_string( $result ) && $result !== '' ? $result : false );
    }

    public function write( string $request_uri, string $theme_key, string $html, string $expected_generation = '' ) : bool {
        $html = (string) $html;
        if ( trim( $html ) === '' ) {
            return( false );
        }

        if ( ! $this->ensureCacheDirectory() ) {
            return( false );
        }

        $temporary_filename = $this->cache_directory . DIRECTORY_SEPARATOR . '.page.tmp-' . bin2hex( random_bytes( 8 ) );
        if ( ! TinyMashFilesystem::writeFileAtomically( $temporary_filename, $html ) ) {
            @ unlink( $temporary_filename );
            return( false );
        }

        try {
            return(
                $this->withGenerationLock(
                    LOCK_SH,
                    function() use ( $request_uri, $theme_key, $expected_generation, $temporary_filename ) : bool {
                        $generation = $this->readGenerationTokenUnlocked();
                        if ( $expected_generation !== '' && ! hash_equals( $generation, $expected_generation ) ) {
                            return( false );
                        }

                        return( @ rename( $temporary_filename, $this->buildCacheFilename( $request_uri, $theme_key, $generation ) ) );
                    }
                ) === true
            );
        } finally {
            if ( is_file( $temporary_filename ) || is_link( $temporary_filename ) ) {
                @ unlink( $temporary_filename );
            }
        }
    }

    public function clear() : int {
        return( self::clearDirectory( $this->cache_directory ) );
    }

    public function invalidateAll() : bool {
        return( self::invalidateDirectory( $this->cache_directory ) );
    }

    public function pruneExpired() : int {
        if ( ! is_dir( $this->cache_directory ) ) {
            return( 0 );
        }

        $removed = $this->withGenerationLock(
            LOCK_EX,
            function() : int {
                $removed = 0;
                foreach ( $this->getCacheFiles() as $filename ) {
                    $modified_at = @ filemtime( $filename );
                    if ( ! is_int( $modified_at ) || $modified_at < 1 || ( $modified_at + $this->ttl_seconds ) >= time() ) {
                        continue;
                    }
                    if ( @ unlink( $filename ) ) {
                        $removed++;
                    }
                }
                return( $removed );
            }
        );
        return( is_int( $removed ) ? $removed : 0 );
    }

    public static function clearDirectory( string $cache_directory ) : int {
        $cache_directory = rtrim( $cache_directory, DIRECTORY_SEPARATOR );
        if ( $cache_directory === '' || ! is_dir( $cache_directory ) ) {
            return( 0 );
        }

        $instance = new self( $cache_directory );
        $removed = $instance->withGenerationLock(
            LOCK_EX,
            function() use ( $instance ) : int {
                $next_generation = self::generateGenerationToken();
                if ( ! self::writeFileAtomically( self::getVersionFilename( $instance->cache_directory ), $next_generation . PHP_EOL ) ) {
                    return( 0 );
                }

                $removed = 0;
                foreach ( $instance->getCacheFiles() as $filename ) {
                    if ( @ unlink( $filename ) ) {
                        $removed++;
                    }
                }
                foreach ( glob( $instance->cache_directory . DIRECTORY_SEPARATOR . '.page.tmp-*' ) ?: [] as $temporary_filename ) {
                    if ( is_string( $temporary_filename ) && ( is_file( $temporary_filename ) || is_link( $temporary_filename ) ) ) {
                        @ unlink( $temporary_filename );
                    }
                }
                return( $removed );
            }
        );
        if ( ! is_int( $removed ) ) {
            return( 0 );
        }
        return( $removed );
    }

    public static function invalidateDirectory( string $cache_directory ) : bool {
        $cache_directory = rtrim( $cache_directory, DIRECTORY_SEPARATOR );
        if ( $cache_directory === '' ) {
            return( false );
        }

        if ( ! is_dir( $cache_directory ) && ! @ mkdir( $cache_directory, 0775, true ) && ! is_dir( $cache_directory ) ) {
            return( false );
        }

        $instance = new self( $cache_directory );
        return(
            $instance->withGenerationLock(
                LOCK_EX,
                static fn() : bool => self::writeFileAtomically( self::getVersionFilename( $cache_directory ), self::generateGenerationToken() . PHP_EOL )
            ) === true
        );
    }

    protected function ensureCacheDirectory() : bool {
        if ( is_dir( $this->cache_directory ) ) {
            return( true );
        }

        return( @ mkdir( $this->cache_directory, 0775, true ) || is_dir( $this->cache_directory ) );
    }

    protected function buildCacheFilename( string $request_uri, string $theme_key, string $generation ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        if ( $theme_key === '' ) {
            $theme_key = 'baseline';
        }

        $hash = hash( 'sha256', $generation . '|' . $theme_key . '|' . $this->normalizeRequestUri( $request_uri ) );
        return( $this->cache_directory . DIRECTORY_SEPARATOR . $hash . '.html' );
    }

    protected function readGenerationTokenUnlocked() : string {
        $version_filename = self::getVersionFilename( $this->cache_directory );
        if ( is_link( $version_filename ) || ! is_file( $version_filename ) ) {
            return( '0' );
        }

        $token = @ file_get_contents( $version_filename );
        $token = is_string( $token ) ? trim( $token ) : '';
        return( $token !== '' ? $token : '0' );
    }

    protected function withGenerationLock( int $operation, callable $callback ) : mixed {
        $lock_handle = @ fopen( self::getLockFilename( $this->cache_directory ), 'c+' );
        if ( $lock_handle === false ) {
            return( null );
        }

        try {
            if ( ! @ flock( $lock_handle, $operation ) ) {
                return( null );
            }
            return( $callback() );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function getCacheFiles() : array {
        $files = glob( $this->cache_directory . DIRECTORY_SEPARATOR . '*.html' );
        return( is_array( $files ) ? array_values( array_filter( $files, 'is_file' ) ) : [] );
    }

    protected static function writeFileAtomically( string $filename, string $contents ) : bool {
        return( TinyMashFilesystem::writeFileAtomically( $filename, $contents ) );
    }

    protected static function generateGenerationToken() : string {
        return( bin2hex( random_bytes( 16 ) ) );
    }

    protected static function getVersionFilename( string $cache_directory ) : string {
        return( rtrim( $cache_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.version' );
    }

    protected static function getLockFilename( string $cache_directory ) : string {
        return( rtrim( $cache_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.generation.lock' );
    }

    protected function normalizeRequestUri( string $request_uri ) : string {
        $request_uri = trim( $request_uri );
        if ( $request_uri === '' ) {
            return( '/' );
        }

        $path = (string) ( parse_url( $request_uri, PHP_URL_PATH ) ?? '/' );
        $query = (string) ( parse_url( $request_uri, PHP_URL_QUERY ) ?? '' );
        $path = $path !== '' ? $path : '/';
        if ( $query === '' ) {
            return( $path );
        }

        parse_str( $query, $query_parameters );
        $page = max( 0, (int) ( $query_parameters['page'] ?? 0 ) );
        if ( $page > 1 ) {
            return( $path . '?page=' . $page );
        }

        return( $path );
    }

}
