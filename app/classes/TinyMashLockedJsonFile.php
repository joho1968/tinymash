<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

class TinyMashLockedJsonFile {

    public static function read( string $filename, array $default = [] ) : array {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( $default );
        }

        $json = @ file_get_contents( $filename );

        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( $default );
        }

        $decoded = json_decode( $json, true, 512 );
        return( is_array( $decoded ) ? $decoded : $default );
    }

    public static function readStrict( string $filename, array $default = [] ) : array {
        if ( ! is_file( $filename ) ) {
            return( $default );
        }
        if ( ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'Unable to read JSON store.' );
        }

        $json = @ file_get_contents( $filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            throw new \RuntimeException( 'JSON store is empty.' );
        }

        $decoded = json_decode( $json, true, 512 );
        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'JSON store is malformed.' );
        }

        return( $decoded );
    }

    public static function write( string $filename, array $data, bool $replace_malformed = false ) : bool {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            return( false );
        }

        $encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || $encoded === '' ) {
            return( false );
        }

        $lock_handle = @ fopen( self::getLockFilename( $filename ), 'c+' );
        if ( $lock_handle === false ) {
            return( false );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX ) ) {
                return( false );
            }
            if ( ! $replace_malformed && is_file( $filename ) ) {
                try {
                    self::readStrict( $filename );
                } catch ( \Throwable ) {
                    return( false );
                }
            }
            $payload = $encoded . PHP_EOL;
            return( TinyMashFilesystem::writeFileAtomically( $filename, $payload ) );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    public static function mutate( string $filename, callable $callback, array $default = [] ) : mixed {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create JSON store directory.' );
        }

        $lock_handle = @ fopen( self::getLockFilename( $filename ), 'c+' );
        if ( $lock_handle === false ) {
            throw new \RuntimeException( 'Unable to open JSON store.' );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock JSON store.' );
            }

            $current = self::readStrict( $filename, $default );

            $result = $callback( $current );
            $updated = $current;
            if ( is_array( $result ) && array_key_exists( 'data', $result ) ) {
                $updated = is_array( $result['data'] ) ? $result['data'] : $default;
                $result = $result['result'] ?? null;
            } elseif ( is_array( $result ) ) {
                $updated = $result;
            }

            $encoded = json_encode( $updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $encoded ) || $encoded === '' ) {
                throw new \RuntimeException( 'Unable to encode JSON store.' );
            }

            $payload = $encoded . PHP_EOL;
            if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $payload ) ) {
                throw new \RuntimeException( 'Unable to write JSON store.' );
            }
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }

        return( $result ?? null );
    }

    protected static function getLockFilename( string $filename ) : string {
        return( $filename . '.lock' );
    }
}
