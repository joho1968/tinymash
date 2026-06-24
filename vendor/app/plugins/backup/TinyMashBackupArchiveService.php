<?php

class TinyMashBackupArchiveService {

    protected string $workspace_root;
    protected int $workspace_retention_seconds = 21600;

    public function __construct( string $workspace_root ) {
        $this->workspace_root = rtrim( $workspace_root, DIRECTORY_SEPARATOR );
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

    public function cleanupWorkspace( string $workspace_directory ) : void {
        $workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        if ( $workspace_directory === '' || ! str_starts_with( $workspace_directory, $this->workspace_root ) ) {
            return;
        }

        $this->deleteDirectory( $workspace_directory );
    }

    public function cleanupExpiredWorkspaces( ?int $retention_seconds = null ) : int {
        $retention_seconds = (int) ( $retention_seconds ?? $this->workspace_retention_seconds );
        if ( $retention_seconds <= 0 || ! is_dir( $this->workspace_root ) ) {
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
            if ( ! is_dir( $path ) ) {
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

    protected function createWorkspaceDirectory() : string {
        $this->ensureDirectory( $this->workspace_root );
        $workspace_directory = $this->workspace_root
            . DIRECTORY_SEPARATOR
            . gmdate( 'Ymd_His' )
            . '_'
            . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );

        $this->ensureDirectory( $workspace_directory );
        return( $workspace_directory );
    }

    protected function createZipFromDirectory( string $source_directory, string $archive_path, string $root_name ) : void {
        $zip = new ZipArchive();
        if ( $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'The backup archive could not be created.' );
        }

        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        $root_name = trim( str_replace( '\\', '/', $root_name ), '/' );
        $this->addDirectoryToZip( $zip, $source_directory, $source_directory, $root_name );
        $zip->close();
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
            $relative_path = ltrim( str_replace( $root_directory, '', $source_path ), DIRECTORY_SEPARATOR );
            $zip_path = $zip_root . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative_path );

            if ( is_dir( $source_path ) ) {
                $zip->addEmptyDir( rtrim( $zip_path, '/' ) );
                $this->addDirectoryToZip( $zip, $source_path, $root_directory, $zip_root );
                continue;
            }

            $zip->addFile( $source_path, $zip_path );
        }
    }

    protected function normalizeArchiveBasename( string $archive_basename ) : string {
        $archive_basename = strtolower( trim( $archive_basename ) );
        $archive_basename = preg_replace( '/[^a-z0-9._-]+/', '-', $archive_basename ) ?? '';
        return( trim( $archive_basename, '-.' ) );
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }
    }

    protected function deleteDirectory( string $directory ) : void {
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
            if ( is_dir( $path ) ) {
                $this->deleteDirectory( $path );
                continue;
            }
            @ unlink( $path );
        }

        @ rmdir( $directory );
    }
}
