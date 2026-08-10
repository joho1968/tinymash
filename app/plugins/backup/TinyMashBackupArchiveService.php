<?php

use app\classes\TinyMashFilesystem;

require_once dirname( __DIR__, 2 ) . '/classes/TinyMashFilesystem.php';

class TinyMashBackupArchiveService {

    protected const RESTORE_MAX_ENTRIES = 20000;
    protected const RESTORE_MAX_EXPANDED_BYTES = 8589934592;

    protected string $workspace_root;
    protected int $workspace_retention_seconds = 21600;

    public function __construct( string $workspace_root ) {
        $workspace_root = rtrim( trim( $workspace_root ), DIRECTORY_SEPARATOR );
        if ( $workspace_root === '' || is_link( $workspace_root ) ) {
            throw new \InvalidArgumentException( 'workspace_root' );
        }
        $this->workspace_root = $workspace_root;
    }

    public function isAvailable() : bool {
        return( class_exists( 'ZipArchive' ) );
    }

    public function createArchive( string $archive_basename, callable $exporter ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'ZipArchive is not available in this PHP runtime.' );
        }

        $archive_basename = $this->normalizeArchiveBasename( $archive_basename );
        if ( $archive_basename === '' ) {
            throw new \InvalidArgumentException( 'archive_basename' );
        }

        $workspace_directory = $this->createWorkspaceDirectory();
        $bundle_directory = $workspace_directory . DIRECTORY_SEPARATOR . $archive_basename;
        $this->ensureDirectory( $bundle_directory );

        try {
            $manifest = $exporter( $bundle_directory );
            $archive_path = $workspace_directory . DIRECTORY_SEPARATOR . $archive_basename . '.zip';
            $this->createZipFromDirectory( $bundle_directory, $archive_path, basename( $bundle_directory ) );

            return(
                [
                    'workspace_directory' => $workspace_directory,
                    'bundle_directory' => $bundle_directory,
                    'archive_path' => $archive_path,
                    'download_filename' => basename( $archive_path ),
                    'manifest' => is_array( $manifest ) ? $manifest : [],
                ]
            );
        } catch ( \Throwable $e ) {
            $this->deleteDirectory( $workspace_directory );
            throw $e;
        }
    }

    public function prepareRestoreUpload( array $upload, string $username, callable $inspector ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'ZIP restore is not available in this PHP runtime.' );
        }

        $username = strtolower( trim( $username ) );
        if ( $username === '' || preg_match( '/^[a-z0-9_]+$/', $username ) !== 1 ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $upload_error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            throw new \RuntimeException( $this->getUploadErrorMessage( $upload_error ) );
        }

        $temporary_filename = trim( (string) ( $upload['tmp_name'] ?? '' ) );
        $original_filename = mb_trim( basename( (string) ( $upload['name'] ?? '' ) ) );
        if ( $temporary_filename === '' || ! is_file( $temporary_filename ) || ! is_readable( $temporary_filename ) ) {
            throw new \RuntimeException( 'The uploaded backup archive could not be read.' );
        }
        if ( ! str_ends_with( strtolower( $original_filename ), '.zip' ) ) {
            throw new \RuntimeException( 'Choose a tinymash ZIP backup.' );
        }
        if ( PHP_SAPI !== 'cli' && ! is_uploaded_file( $temporary_filename ) ) {
            throw new \RuntimeException( 'The backup archive was not received as an HTTP upload.' );
        }

        $workspace_directory = $this->createWorkspaceDirectory( true );
        $archive_path = $workspace_directory . DIRECTORY_SEPARATOR . 'restore.zip';
        try {
            $moved = PHP_SAPI !== 'cli'
                ? @ move_uploaded_file( $temporary_filename, $archive_path )
                : @ copy( $temporary_filename, $archive_path );
            if ( ! $moved || ! is_file( $archive_path ) ) {
                throw new \RuntimeException( 'The uploaded backup archive could not be stored for inspection.' );
            }
            @ chmod( $archive_path, 0600 );

            $extraction_directory = $workspace_directory . DIRECTORY_SEPARATOR . 'extracted';
            $this->ensurePrivateDirectory( $extraction_directory );
            $bundle_directory = $this->extractRestoreArchive( $archive_path, $extraction_directory );
            @ unlink( $archive_path );

            $inspection = $inspector( $bundle_directory );
            if ( ! is_array( $inspection ) || (string) ( $inspection['scope'] ?? '' ) !== 'site' ) {
                throw new \RuntimeException( 'The uploaded archive is not a site backup.' );
            }

            $token = bin2hex( random_bytes( 24 ) );
            $review = [
                'token_hash' => hash( 'sha256', $token ),
                'owner_username' => $username,
                'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'original_filename' => $original_filename,
                'bundle_relative_path' => str_replace( DIRECTORY_SEPARATOR, '/', substr( $bundle_directory, strlen( $workspace_directory ) + 1 ) ),
                'inspection' => $inspection,
            ];
            $this->writePrivateJson( $workspace_directory . DIRECTORY_SEPARATOR . 'restore-review.json', $review );

            return( array_merge( $review, [ 'token' => $token ] ) );
        } catch ( \Throwable $e ) {
            $this->deleteDirectory( $workspace_directory );
            throw $e;
        }
    }

    public function getRestoreReview( string $token, string $username ) : ?array {
        $token = strtolower( trim( $token ) );
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-f0-9]{48}$/', $token ) !== 1 || $username === '' || ! is_dir( $this->workspace_root ) || is_link( $this->workspace_root ) ) {
            return( null );
        }

        foreach ( scandir( $this->workspace_root ) ?: [] as $item ) {
            if ( $item === '.' || $item === '..' || ! preg_match( '/^[0-9]{8}_[0-9]{6}_[a-f0-9]{32}$/', $item ) ) {
                continue;
            }

            $workspace_directory = $this->workspace_root . DIRECTORY_SEPARATOR . $item;
            $review_filename = $workspace_directory . DIRECTORY_SEPARATOR . 'restore-review.json';
            if ( is_link( $workspace_directory ) || is_link( $review_filename ) || ! is_file( $review_filename ) || ! is_readable( $review_filename ) ) {
                continue;
            }
            $modified_at = @ filemtime( $workspace_directory );
            if ( $modified_at === false || $modified_at <= time() - $this->workspace_retention_seconds ) {
                continue;
            }

            try {
                $review = json_decode( (string) file_get_contents( $review_filename ), true, 64, JSON_THROW_ON_ERROR );
            } catch ( \JsonException ) {
                continue;
            }
            if (
                ! is_array( $review )
                || ! hash_equals( (string) ( $review['token_hash'] ?? '' ), hash( 'sha256', $token ) )
                || ! hash_equals( (string) ( $review['owner_username'] ?? '' ), $username )
            ) {
                continue;
            }

            $relative_path = str_replace( '/', DIRECTORY_SEPARATOR, trim( (string) ( $review['bundle_relative_path'] ?? '' ), '/' ) );
            $bundle_directory = $workspace_directory . DIRECTORY_SEPARATOR . $relative_path;
            $real_workspace = realpath( $workspace_directory );
            $real_bundle = realpath( $bundle_directory );
            if (
                ! is_string( $real_workspace )
                || ! is_string( $real_bundle )
                || ! is_dir( $real_bundle )
                || is_link( $bundle_directory )
                || ! str_starts_with( $real_bundle, rtrim( $real_workspace, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR )
            ) {
                return( null );
            }

            return(
                array_merge(
                    $review,
                    [
                        'token' => $token,
                        'workspace_directory' => $real_workspace,
                        'bundle_directory' => $real_bundle,
                    ]
                )
            );
        }

        return( null );
    }

    public function cleanupRestoreReview( string $token, string $username ) : void {
        $review = $this->getRestoreReview( $token, $username );
        if ( is_array( $review ) ) {
            $this->cleanupWorkspace( (string) $review['workspace_directory'] );
        }
    }

    public function cleanupWorkspace( string $workspace_directory ) : void {
        $workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        $real_root = realpath( $this->workspace_root );
        $real_workspace = realpath( $workspace_directory );
        if ( ! is_string( $real_root ) || ! is_string( $real_workspace ) ) {
            return;
        }

        $root_prefix = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( $real_workspace === $real_root || ! str_starts_with( $real_workspace, $root_prefix ) ) {
            return;
        }

        $this->deleteDirectory( $real_workspace );
    }

    public function cleanupExpiredWorkspaces( ?int $retention_seconds = null ) : int {
        $retention_seconds = (int) ( $retention_seconds ?? $this->workspace_retention_seconds );
        if ( $retention_seconds <= 0 || is_link( $this->workspace_root ) || ! is_dir( $this->workspace_root ) ) {
            return( 0 );
        }

        $items = scandir( $this->workspace_root );
        if ( ! is_array( $items ) ) {
            return( 0 );
        }

        $removed = 0;
        $cutoff = time() - $retention_seconds;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $this->workspace_root . DIRECTORY_SEPARATOR . $item;
            if ( is_link( $path ) || ! is_dir( $path ) ) {
                continue;
            }

            $modified_at = @ filemtime( $path );
            if ( $modified_at === false || $modified_at > $cutoff ) {
                continue;
            }

            $this->deleteDirectory( $path );
            $removed++;
        }

        return( $removed );
    }

    public function getManagedRelativePath( string $path ) : string {
        $path = trim( $path );
        if ( $path === '' ) {
            return( '' );
        }

        $real_root = realpath( $this->workspace_root );
        $real_path = realpath( $path );
        if ( ! is_string( $real_root ) || $real_root === '' || ! is_string( $real_path ) || $real_path === '' ) {
            return( '' );
        }

        $root_prefix = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        if ( ! str_starts_with( $real_path, $root_prefix ) ) {
            return( '' );
        }

        return( str_replace( DIRECTORY_SEPARATOR, '/', substr( $real_path, strlen( $root_prefix ) ) ) );
    }

    protected function createWorkspaceDirectory( bool $private = false ) : string {
        $this->ensureDirectory( $this->workspace_root );
        $workspace_directory = $this->workspace_root
            . DIRECTORY_SEPARATOR
            . gmdate( 'Ymd_His' )
            . '_'
            . bin2hex( random_bytes( 16 ) );

        if ( $private ) {
            $this->ensurePrivateDirectory( $workspace_directory );
        } else {
            $this->ensureDirectory( $workspace_directory );
        }
        return( $workspace_directory );
    }

    protected function extractRestoreArchive( string $archive_path, string $target_directory ) : string {
        $zip = new ZipArchive();
        if ( $zip->open( $archive_path, ZipArchive::RDONLY ) !== true ) {
            throw new \RuntimeException( 'The uploaded ZIP archive could not be opened.' );
        }

        $seen_paths = [];
        $expanded_size = 0;
        try {
            if ( $zip->numFiles < 1 || $zip->numFiles > self::RESTORE_MAX_ENTRIES ) {
                throw new \RuntimeException( 'The backup archive contains an unsupported number of entries.' );
            }

            for ( $index = 0; $index < $zip->numFiles; $index++ ) {
                $stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
                $entry_name = is_array( $stat ) ? (string) ( $stat['name'] ?? '' ) : '';
                $normalized_path = $this->normalizeZipEntryPath( $entry_name );
                if ( $normalized_path === '' || isset( $seen_paths[$normalized_path] ) ) {
                    throw new \RuntimeException( 'The backup archive contains an invalid or duplicate path.' );
                }
                $seen_paths[$normalized_path] = true;

                $is_directory = str_ends_with( $entry_name, '/' );
                if ( $this->zipEntryIsUnsafeType( $zip, $index, $is_directory ) ) {
                    throw new \RuntimeException( 'The backup archive contains a symbolic link or unsupported entry type.' );
                }

                $entry_size = max( 0, (int) ( $stat['size'] ?? 0 ) );
                if ( $entry_size > self::RESTORE_MAX_EXPANDED_BYTES - $expanded_size ) {
                    throw new \RuntimeException( 'The expanded backup archive is too large to inspect safely.' );
                }

                $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized_path );
                if ( $is_directory ) {
                    $this->ensurePrivateDirectory( $target_path );
                    continue;
                }

                $this->ensurePrivateDirectory( dirname( $target_path ) );
                $source_handle = $zip->getStream( $entry_name );
                $target_handle = @ fopen( $target_path, 'xb' );
                if ( ! is_resource( $source_handle ) || ! is_resource( $target_handle ) ) {
                    if ( is_resource( $source_handle ) ) {
                        fclose( $source_handle );
                    }
                    if ( is_resource( $target_handle ) ) {
                        fclose( $target_handle );
                    }
                    throw new \RuntimeException( 'The backup archive could not be extracted safely.' );
                }

                $copied = 0;
                $flushed = false;
                try {
                    $copied = $this->copyRestoreEntry( $source_handle, $target_handle, self::RESTORE_MAX_EXPANDED_BYTES - $expanded_size );
                    $flushed = fflush( $target_handle );
                } finally {
                    fclose( $source_handle );
                    fclose( $target_handle );
                }
                @ chmod( $target_path, 0600 );
                if ( $copied !== $entry_size || ! $flushed ) {
                    throw new \RuntimeException( 'The backup archive entry could not be extracted completely.' );
                }
                $expanded_size += $copied;
            }
        } finally {
            $zip->close();
        }

        if ( is_file( $target_directory . DIRECTORY_SEPARATOR . 'manifest.json' ) ) {
            return( $target_directory );
        }

        $top_level_directories = [];
        foreach ( new \FilesystemIterator( $target_directory, \FilesystemIterator::SKIP_DOTS ) as $item ) {
            if ( $item->isDir() && ! $item->isLink() ) {
                $top_level_directories[] = $item->getPathname();
                continue;
            }
            throw new \RuntimeException( 'The backup archive must contain one tinymash bundle.' );
        }
        if ( count( $top_level_directories ) !== 1 || ! is_file( $top_level_directories[0] . DIRECTORY_SEPARATOR . 'manifest.json' ) ) {
            throw new \RuntimeException( 'The backup archive must contain one tinymash bundle.' );
        }

        return( $top_level_directories[0] );
    }

    protected function copyRestoreEntry( mixed $source_handle, mixed $target_handle, int $remaining_bytes ) : int {
        $copied = 0;
        while ( ! feof( $source_handle ) ) {
            $chunk = fread( $source_handle, 1048576 );
            if ( ! is_string( $chunk ) || ( $chunk === '' && ! feof( $source_handle ) ) ) {
                throw new \RuntimeException( 'The backup archive entry could not be read completely.' );
            }
            if ( $chunk === '' ) {
                break;
            }

            $chunk_length = strlen( $chunk );
            if ( $chunk_length > $remaining_bytes - $copied ) {
                throw new \RuntimeException( 'The expanded backup archive is too large to inspect safely.' );
            }

            $written = 0;
            while ( $written < $chunk_length ) {
                $result = fwrite( $target_handle, substr( $chunk, $written ) );
                if ( ! is_int( $result ) || $result < 1 ) {
                    throw new \RuntimeException( 'The backup archive entry could not be written completely.' );
                }
                $written += $result;
            }
            $copied += $chunk_length;
        }

        return( $copied );
    }

    protected function normalizeZipEntryPath( string $entry_name ) : string {
        if (
            $entry_name === ''
            || str_contains( $entry_name, "\0" )
            || str_contains( $entry_name, '\\' )
            || str_starts_with( $entry_name, '/' )
            || preg_match( '/^[A-Za-z]:/', $entry_name ) === 1
        ) {
            return( '' );
        }

        $parts = [];
        foreach ( explode( '/', trim( $entry_name, '/' ) ) as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return( '' );
            }
            $parts[] = $part;
        }

        return( implode( '/', $parts ) );
    }

    protected function zipEntryIsUnsafeType( ZipArchive $zip, int $index, bool $is_directory ) : bool {
        $operating_system = 0;
        $attributes = 0;
        if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes, ZipArchive::FL_UNCHANGED ) ) {
            return( false );
        }

        if ( defined( 'ZipArchive::OPSYS_UNIX' ) && $operating_system !== ZipArchive::OPSYS_UNIX ) {
            return( false );
        }

        $mode = ( $attributes >> 16 ) & 0170000;
        if ( $mode === 0 ) {
            return( false );
        }

        return( $is_directory ? $mode !== 0040000 : $mode !== 0100000 );
    }

    protected function getUploadErrorMessage( int $upload_error ) : string {
        return(
            match ( $upload_error ) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The backup archive exceeds the server upload limit.',
                UPLOAD_ERR_PARTIAL => 'The backup archive upload did not complete.',
                UPLOAD_ERR_NO_FILE => 'Choose a tinymash ZIP backup to inspect.',
                default => 'The backup archive could not be uploaded.',
            }
        );
    }

    protected function ensurePrivateDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            throw new \RuntimeException( 'Restore directories must not be symbolic links.' );
        }
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create restore workspace directory.' );
        }
        @ chmod( $directory, 0700 );
    }

    protected function writePrivateJson( string $filename, array $payload ) : void {
        $json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || $json === '' ) {
            throw new \RuntimeException( 'Unable to encode restore review data.' );
        }
        $contents = $json . PHP_EOL;
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $contents ) ) {
            throw new \RuntimeException( 'Unable to write restore review data.' );
        }
        @ chmod( $filename, 0600 );
    }

    protected function createZipFromDirectory( string $source_directory, string $archive_path, string $root_name ) : void {
        if ( is_link( $source_directory ) || ! is_dir( $source_directory ) ) {
            throw new \RuntimeException( 'Backup source directory is invalid.' );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'The backup archive could not be created.' );
        }

        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        $root_name = trim( str_replace( '\\', '/', $root_name ), '/' );
        $this->addDirectoryToZip( $zip, $source_directory, $source_directory, $root_name );
        if ( ! $zip->close() ) {
            throw new \RuntimeException( 'The backup archive could not be finalized.' );
        }
    }

    protected function addDirectoryToZip( ZipArchive $zip, string $source_directory, string $root_directory, string $zip_root ) : void {
        $items = scandir( $source_directory );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $source_path = $source_directory . DIRECTORY_SEPARATOR . $item;
            $relative_path = ltrim( substr( $source_path, strlen( $root_directory ) ), DIRECTORY_SEPARATOR );
            $zip_path = $zip_root . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative_path );

            if ( is_link( $source_path ) ) {
                throw new \RuntimeException( 'Backup data must not contain symbolic links.' );
            }
            if ( is_dir( $source_path ) ) {
                if ( ! $zip->addEmptyDir( rtrim( $zip_path, '/' ) ) ) {
                    throw new \RuntimeException( 'The backup archive could not add a directory.' );
                }
                $this->addDirectoryToZip( $zip, $source_path, $root_directory, $zip_root );
                continue;
            }

            if ( ! is_file( $source_path ) || ! is_readable( $source_path ) || ! $zip->addFile( $source_path, $zip_path ) ) {
                throw new \RuntimeException( 'The backup archive could not add a file.' );
            }
        }
    }

    protected function normalizeArchiveBasename( string $archive_basename ) : string {
        $archive_basename = strtolower( trim( $archive_basename ) );
        $archive_basename = preg_replace( '/[^a-z0-9._-]+/', '-', $archive_basename ) ?? '';
        return( trim( $archive_basename, '-.' ) );
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            throw new \RuntimeException( 'Backup directories must not be symbolic links.' );
        }
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create backup workspace directory.' );
        }
    }

    protected function deleteDirectory( string $directory ) : void {
        if ( is_link( $directory ) ) {
            @ unlink( $directory );
            return;
        }
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $items = scandir( $directory );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_link( $path ) ) {
                @ unlink( $path );
                continue;
            }
            if ( is_dir( $path ) ) {
                $this->deleteDirectory( $path );
                continue;
            }
            @ unlink( $path );
        }

        @ rmdir( $directory );
    }
}
