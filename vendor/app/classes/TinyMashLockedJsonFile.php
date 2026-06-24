<?php
namespace app\classes;

class TinyMashLockedJsonFile {

    public static function read( string $filename, array $default = [] ) : array {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( $default );
        }

        $handle = @ fopen( $filename, 'rb' );
        if ( $handle === false ) {
            return( $default );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( $default );
            }
            $json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( $default );
        }

        $decoded = json_decode( $json, true, 512 );
        return( is_array( $decoded ) ? $decoded : $default );
    }

    public static function write( string $filename, array $data ) : bool {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            return( false );
        }

        $encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || $encoded === '' ) {
            return( false );
        }

        $handle = @ fopen( $filename, 'c+' );
        if ( $handle === false ) {
            return( false );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                return( false );
            }
            rewind( $handle );
            ftruncate( $handle, 0 );
            $written = fwrite( $handle, $encoded . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        return( $written !== false );
    }

    public static function mutate( string $filename, callable $callback, array $default = [] ) : mixed {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create JSON store directory.' );
        }

        $handle = @ fopen( $filename, 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to open JSON store.' );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock JSON store.' );
            }

            rewind( $handle );
            $json = stream_get_contents( $handle );
            $current = $default;
            if ( is_string( $json ) && trim( $json ) !== '' ) {
                $decoded = json_decode( $json, true, 512 );
                if ( is_array( $decoded ) ) {
                    $current = $decoded;
                }
            }

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

            rewind( $handle );
            ftruncate( $handle, 0 );
            fwrite( $handle, $encoded . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        return( $result ?? null );
    }
}
