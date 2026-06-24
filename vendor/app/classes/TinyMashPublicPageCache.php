<?php
namespace app\classes;

class TinyMashPublicPageCache {

    protected string $cache_directory;
    protected int $ttl_seconds;

    public function __construct( string $cache_directory, int $ttl_seconds = 300 ) {
        $this->cache_directory = rtrim( $cache_directory, DIRECTORY_SEPARATOR );
        $this->ttl_seconds = max( 60, $ttl_seconds );
    }

    public function read( string $request_uri, string $theme_key = 'baseline' ) : string|false {
        $filename = $this->buildCacheFilename( $request_uri, $theme_key );
        if ( ! is_file( $filename ) ) {
            return( false );
        }

        $modified_at = @ filemtime( $filename );
        if ( ! is_int( $modified_at ) || $modified_at < 1 || ( $modified_at + $this->ttl_seconds ) < time() ) {
            @ unlink( $filename );
            return( false );
        }

        $html = @ file_get_contents( $filename );
        if ( ! is_string( $html ) || $html === '' ) {
            return( false );
        }

        return( $html );
    }

    public function write( string $request_uri, string $theme_key, string $html ) : bool {
        $html = (string) $html;
        if ( trim( $html ) === '' ) {
            return( false );
        }

        if ( ! $this->ensureCacheDirectory() ) {
            return( false );
        }

        return( @ file_put_contents( $this->buildCacheFilename( $request_uri, $theme_key ), $html, LOCK_EX ) !== false );
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

        $removed = 0;
        $directory = @ opendir( $this->cache_directory );
        if ( $directory === false ) {
            return( 0 );
        }

        while ( ( $entry = readdir( $directory ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            $filename = $this->cache_directory . DIRECTORY_SEPARATOR . $entry;
            if ( ! is_file( $filename ) ) {
                continue;
            }

            $modified_at = @ filemtime( $filename );
            if ( ! is_int( $modified_at ) || $modified_at < 1 || ( $modified_at + $this->ttl_seconds ) >= time() ) {
                continue;
            }

            if ( @ unlink( $filename ) ) {
                $removed++;
            }
        }

        closedir( $directory );
        return( $removed );
    }

    public static function clearDirectory( string $cache_directory ) : int {
        $cache_directory = rtrim( $cache_directory, DIRECTORY_SEPARATOR );
        if ( $cache_directory === '' || ! is_dir( $cache_directory ) ) {
            return( 0 );
        }

        $removed = 0;
        $directory = @ opendir( $cache_directory );
        if ( $directory === false ) {
            return( 0 );
        }

        while ( ( $entry = readdir( $directory ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            $filename = $cache_directory . DIRECTORY_SEPARATOR . $entry;
            if ( is_file( $filename ) && @ unlink( $filename ) ) {
                $removed++;
            }
        }

        closedir( $directory );
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

        return( @ file_put_contents( self::getVersionFilename( $cache_directory ), (string) microtime( true ), LOCK_EX ) !== false );
    }

    protected function ensureCacheDirectory() : bool {
        if ( is_dir( $this->cache_directory ) ) {
            return( true );
        }

        return( @ mkdir( $this->cache_directory, 0775, true ) || is_dir( $this->cache_directory ) );
    }

    protected function buildCacheFilename( string $request_uri, string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        if ( $theme_key === '' ) {
            $theme_key = 'baseline';
        }

        $hash = hash( 'sha256', $this->getVersionToken() . '|' . $theme_key . '|' . $this->normalizeRequestUri( $request_uri ) );
        return( $this->cache_directory . DIRECTORY_SEPARATOR . $hash . '.html' );
    }

    protected function getVersionToken() : string {
        $version_filename = self::getVersionFilename( $this->cache_directory );
        if ( ! is_file( $version_filename ) ) {
            return( '0' );
        }

        $token = @ file_get_contents( $version_filename );
        $token = is_string( $token ) ? trim( $token ) : '';
        return( $token !== '' ? $token : '0' );
    }

    protected static function getVersionFilename( string $cache_directory ) : string {
        return( rtrim( $cache_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.version' );
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

}// TinyMashPublicPageCache
