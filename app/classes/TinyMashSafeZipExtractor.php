<?php
namespace app\classes;

final class TinyMashSafeZipExtractor {

    protected int $maximum_entries;
    protected int $maximum_expanded_bytes;
    protected bool $require_unix_types;

    public function __construct( int $maximum_entries, int $maximum_expanded_bytes, bool $require_unix_types = false ) {
        if ( $maximum_entries < 1 || $maximum_expanded_bytes < 1 ) {
            throw new \InvalidArgumentException( 'ZIP extraction limits must be positive.' );
        }
        $this->maximum_entries = $maximum_entries;
        $this->maximum_expanded_bytes = $maximum_expanded_bytes;
        $this->require_unix_types = $require_unix_types;
    }

    public function extract( string $archive_filename, string $target_directory ) : array {
        $target_directory = $this->resolveEmptyTargetDirectory( $target_directory );
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_filename, \ZipArchive::RDONLY ) !== true ) {
            throw new \RuntimeException( 'The ZIP archive could not be opened.' );
        }

        try {
            $entries = $this->inspectEntries( $zip );
            $expanded_bytes = $this->extractEntries( $zip, $entries, $target_directory );
        } finally {
            $zip->close();
        }

        return( [
            'entries' => count( $entries ),
            'files' => count( array_filter( $entries, static fn( array $entry ) : bool => ! $entry['directory'] ) ),
            'directories' => count( array_filter( $entries, static fn( array $entry ) : bool => $entry['directory'] ) ),
            'expanded_bytes' => $expanded_bytes,
        ] );
    }

    protected function resolveEmptyTargetDirectory( string $target_directory ) : string {
        if ( is_link( $target_directory ) || ! is_dir( $target_directory ) || ! is_readable( $target_directory ) || ! is_writable( $target_directory ) ) {
            throw new \RuntimeException( 'The ZIP extraction directory is unavailable.' );
        }
        $real_target = realpath( $target_directory );
        if ( ! is_string( $real_target ) ) {
            throw new \RuntimeException( 'The ZIP extraction directory could not be resolved.' );
        }
        $iterator = new \FilesystemIterator( $real_target, \FilesystemIterator::SKIP_DOTS );
        if ( $iterator->valid() ) {
            throw new \RuntimeException( 'The ZIP extraction directory must be empty.' );
        }
        @ chmod( $real_target, 0700 );
        return( rtrim( $real_target, DIRECTORY_SEPARATOR ) );
    }

    protected function inspectEntries( \ZipArchive $zip ) : array {
        if ( $zip->numFiles < 1 || $zip->numFiles > $this->maximum_entries ) {
            throw new \RuntimeException( 'The ZIP archive contains an unsupported number of entries.' );
        }

        $entries = [];
        $paths = [];
        $folded_paths = [];
        $expanded_bytes = 0;
        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
            $entry_name = is_array( $stat ) ? (string) ( $stat['name'] ?? '' ) : '';
            $path = $this->normalizeEntryPath( $entry_name );
            $folded_path = mb_strtolower( $path, 'UTF-8' );
            if ( $path === '' || isset( $paths[$path] ) || isset( $folded_paths[$folded_path] ) ) {
                throw new \RuntimeException( 'The ZIP archive contains an invalid or duplicate path.' );
            }

            $directory = str_ends_with( $entry_name, '/' );
            $this->assertEntryType( $zip, $index, $directory );
            $this->assertNoPathConflict( $paths, $path, $directory );
            $this->assertNoPathConflict( $folded_paths, $folded_path, $directory );

            $size = max( 0, (int) ( $stat['size'] ?? 0 ) );
            if ( ! $directory && $size > $this->maximum_expanded_bytes - $expanded_bytes ) {
                throw new \RuntimeException( 'The expanded ZIP archive is too large.' );
            }
            if ( ! $directory ) {
                $expanded_bytes += $size;
            }

            $paths[$path] = $directory;
            $folded_paths[$folded_path] = $directory;
            $entries[] = [
                'name' => $entry_name,
                'path' => $path,
                'directory' => $directory,
                'size' => $size,
            ];
        }
        return( $entries );
    }

    protected function normalizeEntryPath( string $entry_name ) : string {
        if ( $entry_name === ''
            || strlen( $entry_name ) > 4096
            || ! mb_check_encoding( $entry_name, 'UTF-8' )
            || preg_match( '/[\x00-\x1F\x7F]/', $entry_name ) === 1
            || str_contains( $entry_name, '\\' )
            || str_starts_with( $entry_name, '/' )
            || preg_match( '/^[A-Za-z]:/', $entry_name ) === 1 ) {
            return( '' );
        }

        $parts = explode( '/', rtrim( $entry_name, '/' ) );
        foreach ( $parts as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return( '' );
            }
        }
        return( implode( '/', $parts ) );
    }

    protected function assertEntryType( \ZipArchive $zip, int $index, bool $directory ) : void {
        $operating_system = 0;
        $attributes = 0;
        $has_attributes = $zip->getExternalAttributesIndex( $index, $operating_system, $attributes, \ZipArchive::FL_UNCHANGED );
        $unix = defined( 'ZipArchive::OPSYS_UNIX' ) && $operating_system === \ZipArchive::OPSYS_UNIX;
        $mode = $unix ? ( $attributes >> 16 ) & 0170000 : 0;

        if ( $this->require_unix_types && ( ! $has_attributes || ! $unix || $mode === 0 ) ) {
            throw new \RuntimeException( 'The ZIP archive entry type is not explicit.' );
        }
        if ( $mode !== 0 && ( $directory ? $mode !== 0040000 : $mode !== 0100000 ) ) {
            throw new \RuntimeException( 'The ZIP archive contains a link or unsupported entry type.' );
        }
    }

    protected function assertNoPathConflict( array $paths, string $path, bool $directory ) : void {
        $prefix = '';
        $segments = explode( '/', $path );
        array_pop( $segments );
        foreach ( $segments as $segment ) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            if ( isset( $paths[$prefix] ) && $paths[$prefix] === false ) {
                throw new \RuntimeException( 'The ZIP archive contains conflicting paths.' );
            }
        }
        if ( ! $directory ) {
            $child_prefix = $path . '/';
            foreach ( $paths as $known_path => $known_directory ) {
                if ( str_starts_with( $known_path, $child_prefix ) ) {
                    throw new \RuntimeException( 'The ZIP archive contains conflicting paths.' );
                }
            }
        }
    }

    protected function extractEntries( \ZipArchive $zip, array $entries, string $target_directory ) : int {
        $expanded_bytes = 0;
        foreach ( $entries as $entry ) {
            $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $entry['path'] );
            if ( $entry['directory'] ) {
                $this->ensurePrivateDirectory( $target_path );
                continue;
            }

            $this->ensurePrivateDirectory( dirname( $target_path ) );
            $source_handle = $zip->getStream( $entry['name'] );
            $target_handle = @ fopen( $target_path, 'xb' );
            if ( ! is_resource( $source_handle ) || ! is_resource( $target_handle ) ) {
                if ( is_resource( $source_handle ) ) {
                    fclose( $source_handle );
                }
                if ( is_resource( $target_handle ) ) {
                    fclose( $target_handle );
                }
                throw new \RuntimeException( 'The ZIP archive could not be extracted safely.' );
            }

            try {
                $copied = $this->copyEntry( $source_handle, $target_handle, $this->maximum_expanded_bytes - $expanded_bytes );
                $flushed = fflush( $target_handle );
            } finally {
                fclose( $source_handle );
                fclose( $target_handle );
            }
            @ chmod( $target_path, 0600 );
            if ( $copied !== $entry['size'] || ! $flushed ) {
                throw new \RuntimeException( 'A ZIP archive entry could not be extracted completely.' );
            }
            $expanded_bytes += $copied;
        }
        return( $expanded_bytes );
    }

    protected function copyEntry( mixed $source_handle, mixed $target_handle, int $remaining_bytes ) : int {
        $copied = 0;
        while ( ! feof( $source_handle ) ) {
            $chunk = fread( $source_handle, 1048576 );
            if ( ! is_string( $chunk ) || ( $chunk === '' && ! feof( $source_handle ) ) ) {
                throw new \RuntimeException( 'A ZIP archive entry could not be read completely.' );
            }
            if ( $chunk === '' ) {
                break;
            }
            if ( strlen( $chunk ) > $remaining_bytes - $copied ) {
                throw new \RuntimeException( 'The expanded ZIP archive is too large.' );
            }

            $written = 0;
            while ( $written < strlen( $chunk ) ) {
                $result = fwrite( $target_handle, substr( $chunk, $written ) );
                if ( ! is_int( $result ) || $result < 1 ) {
                    throw new \RuntimeException( 'A ZIP archive entry could not be written completely.' );
                }
                $written += $result;
            }
            $copied += strlen( $chunk );
        }
        return( $copied );
    }

    protected function ensurePrivateDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            throw new \RuntimeException( 'ZIP extraction directories must not be links.' );
        }
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create a ZIP extraction directory.' );
        }
        @ chmod( $directory, 0700 );
    }
}
