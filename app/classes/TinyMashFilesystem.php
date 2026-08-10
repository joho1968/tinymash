<?php
namespace app\classes;

class TinyMashFilesystem {

    public static function writeFileAtomically( string $filename, string $contents ) : bool {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) ) {
            return( false );
        }

        try {
            $temporary_filename = $filename . '.tmp-' . bin2hex( random_bytes( 8 ) );
        } catch ( \Throwable ) {
            return( false );
        }

        $handle = @ fopen( $temporary_filename, 'xb' );
        if ( $handle === false ) {
            return( false );
        }

        $complete = false;
        try {
            $length = strlen( $contents );
            $offset = 0;
            while ( $offset < $length ) {
                $remaining_contents = $offset === 0 ? $contents : substr( $contents, $offset );
                $written = @ fwrite( $handle, $remaining_contents );
                if ( ! is_int( $written ) || $written < 1 ) {
                    break;
                }
                $offset += $written;
            }
            $complete = $offset === $length && @ fflush( $handle );
        } finally {
            fclose( $handle );
        }

        if ( ! $complete ) {
            @ unlink( $temporary_filename );
            return( false );
        }

        $existing_permissions = @ fileperms( $filename );
        if ( is_int( $existing_permissions ) ) {
            @ chmod( $temporary_filename, $existing_permissions & 0777 );
        }

        if ( ! @ rename( $temporary_filename, $filename ) ) {
            @ unlink( $temporary_filename );
            return( false );
        }

        return( true );
    }

    public static function resolveManagedReadableFile( string $root_directory, string $candidate_path, bool $reject_symlinks = true ) : string {
        $root_directory = rtrim( $root_directory, DIRECTORY_SEPARATOR );
        if ( $root_directory === '' || $candidate_path === '' || str_contains( $root_directory, "\0" ) || str_contains( $candidate_path, "\0" ) ) {
            return( '' );
        }

        $absolute_candidate = self::isAbsolutePath( $candidate_path )
            ? $candidate_path
            : $root_directory . DIRECTORY_SEPARATOR . $candidate_path;

        $real_root = realpath( $root_directory );
        $real_path = realpath( $absolute_candidate );
        if ( ! is_string( $real_root ) || $real_root === '' || ! is_string( $real_path ) || $real_path === '' ) {
            return( '' );
        }

        $real_root = rtrim( $real_root, DIRECTORY_SEPARATOR );
        $root_prefix = $real_root . DIRECTORY_SEPARATOR;
        if ( $real_path === $real_root || ! str_starts_with( $real_path, $root_prefix ) ) {
            return( '' );
        }

        if ( $reject_symlinks && self::pathContainsSymlinkComponentBelowRoot( $root_directory, $absolute_candidate ) ) {
            return( '' );
        }

        if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
            return( '' );
        }

        return( $real_path );
    }

    protected static function isAbsolutePath( string $path ) : bool {
        return(
            str_starts_with( $path, DIRECTORY_SEPARATOR )
            || preg_match( '/^[A-Za-z]:[\/\\\\]/', $path ) === 1
        );
    }

    protected static function pathContainsSymlinkComponentBelowRoot( string $root_directory, string $path ) : bool {
        $root_directory = self::normalizeLexicalPath( $root_directory );
        $path = self::normalizeLexicalPath( $path );
        $root_prefix = rtrim( $root_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( ! str_starts_with( $path, $root_prefix ) ) {
            return( false );
        }

        $relative_path = substr( $path, strlen( $root_prefix ) );
        $current = rtrim( $root_directory, DIRECTORY_SEPARATOR );
        foreach ( explode( DIRECTORY_SEPARATOR, $relative_path ) as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $part;
            if ( is_link( $current ) ) {
                return( true );
            }
        }

        return( false );
    }

    protected static function normalizeLexicalPath( string $path ) : string {
        $path = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $path );
        $path = preg_replace( '#' . preg_quote( DIRECTORY_SEPARATOR, '#' ) . '+#', DIRECTORY_SEPARATOR, $path ) ?? $path;

        $prefix = '';
        if ( preg_match( '/^[A-Za-z]:/', $path, $matches ) === 1 ) {
            $prefix = (string) $matches[0];
            $path = substr( $path, strlen( $prefix ) );
        } elseif ( str_starts_with( $path, DIRECTORY_SEPARATOR ) ) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim( $path, DIRECTORY_SEPARATOR );
        }

        $parts = [];
        foreach ( explode( DIRECTORY_SEPARATOR, $path ) as $part ) {
            if ( $part === '' || $part === '.' ) {
                continue;
            }
            if ( $part === '..' ) {
                array_pop( $parts );
                continue;
            }

            $parts[] = $part;
        }

        $normalized = implode( DIRECTORY_SEPARATOR, $parts );
        if ( $prefix === DIRECTORY_SEPARATOR ) {
            return( DIRECTORY_SEPARATOR . $normalized );
        }
        if ( $prefix !== '' ) {
            return( $prefix . ( $normalized !== '' ? DIRECTORY_SEPARATOR . $normalized : '' ) );
        }
        return( $normalized );
    }
}
