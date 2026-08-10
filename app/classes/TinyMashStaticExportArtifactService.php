<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

class TinyMashStaticExportArtifactService {

    protected string $artifact_root;
    protected int $retention_seconds;

    public function __construct( string $artifact_root, int $retention_seconds = 21600 ) {
        $this->artifact_root = rtrim( $artifact_root, DIRECTORY_SEPARATOR );
        $this->retention_seconds = max( 60, $retention_seconds );
    }

    public function isAvailable() : bool {
        return( class_exists( '\ZipArchive' ) );
    }

    public function createArtifact( string $basename, array $metadata, callable $writer ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'ZipArchive is not available in this PHP runtime.' );
        }

        $basename = $this->normalizeBasename( $basename );
        if ( $basename === '' ) {
            throw new \InvalidArgumentException( 'basename' );
        }

        $this->ensureDirectory( $this->artifact_root );
        $artifact_id = $this->createArtifactId();
        $artifact_directory = $this->artifact_root . DIRECTORY_SEPARATOR . $artifact_id;
        $bundle_directory = $artifact_directory . DIRECTORY_SEPARATOR . $basename;
        $archive_path = $artifact_directory . DIRECTORY_SEPARATOR . $basename . '.zip';
        $manifest_path = $artifact_directory . DIRECTORY_SEPARATOR . 'manifest.json';

        $this->ensureDirectory( $bundle_directory );
        try {
            $report = $writer( $bundle_directory );
            $this->createZipFromDirectory( $bundle_directory, $archive_path, basename( $bundle_directory ) );
            $created_at = gmdate( DATE_ATOM );
            $manifest = array_merge(
                $metadata,
                [
                    'id' => $artifact_id,
                    'basename' => $basename,
                    'download_filename' => basename( $archive_path ),
                    'archive_path' => basename( $archive_path ),
                    'size_bytes' => is_file( $archive_path ) ? (int) filesize( $archive_path ) : 0,
                    'created_at_utc' => $created_at,
                    'expires_at_utc' => gmdate( DATE_ATOM, time() + $this->retention_seconds ),
                    'report' => is_array( $report ) ? $report : [],
                ]
            );
            $this->writeJsonFile( $manifest_path, $manifest );

            return( $this->normalizeArtifactRecord( $manifest, $artifact_directory ) );
        } catch ( \Throwable $e ) {
            $this->deleteDirectory( $artifact_directory );
            throw $e;
        }
    }

    public function listArtifacts( ?string $owner_username = null, bool $include_all = false ) : array {
        $this->cleanupExpiredArtifacts();
        if ( ! is_dir( $this->artifact_root ) ) {
            return( [] );
        }

        $records = [];
        $items = scandir( $this->artifact_root );
        if ( ! is_array( $items ) ) {
            return( [] );
        }

        $owner_username = $this->normalizeOwner( (string) ( $owner_username ?? '' ) );
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $artifact_directory = $this->artifact_root . DIRECTORY_SEPARATOR . $item;
            $manifest = $this->readManifest( $artifact_directory );
            if ( ! is_array( $manifest ) ) {
                continue;
            }
            if ( ! $include_all && $owner_username !== '' && $this->normalizeOwner( (string) ( $manifest['owner_username'] ?? '' ) ) !== $owner_username ) {
                continue;
            }

            $records[] = $this->normalizeArtifactRecord( $manifest, $artifact_directory );
        }

        usort(
            $records,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $right['created_at_utc'] ?? '' ), (string) ( $left['created_at_utc'] ?? '' ) ) );
            }
        );

        return( $records );
    }

    public function getArtifactForDownload( string $artifact_id, string $current_username, bool $is_superadmin = false ) : ?array {
        $record = $this->getArtifact( $artifact_id );
        if ( ! is_array( $record ) || ! empty( $record['expired'] ) ) {
            return( null );
        }
        if ( ! $is_superadmin && $this->normalizeOwner( (string) ( $record['owner_username'] ?? '' ) ) !== $this->normalizeOwner( $current_username ) ) {
            return( null );
        }

        $archive_path = (string) ( $record['resolved_archive_path'] ?? '' );
        if ( $archive_path === '' || ! is_file( $archive_path ) || ! $this->pathIsContained( $archive_path, $this->artifact_root ) ) {
            return( null );
        }

        return( $record );
    }

    public function deleteArtifact( string $artifact_id, string $current_username, bool $is_superadmin = false ) : bool {
        $record = $this->getArtifactForDownload( $artifact_id, $current_username, $is_superadmin );
        if ( ! is_array( $record ) ) {
            return( false );
        }

        $directory = (string) ( $record['artifact_directory'] ?? '' );
        if ( $directory === '' || ! $this->pathIsContained( $directory, $this->artifact_root ) ) {
            return( false );
        }

        $this->deleteDirectory( $directory );
        return( true );
    }

    public function cleanupExpiredArtifacts() : int {
        if ( ! is_dir( $this->artifact_root ) ) {
            return( 0 );
        }

        $items = scandir( $this->artifact_root );
        if ( ! is_array( $items ) ) {
            return( 0 );
        }

        $removed = 0;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $artifact_directory = $this->artifact_root . DIRECTORY_SEPARATOR . $item;
            if ( ! is_dir( $artifact_directory ) ) {
                continue;
            }

            $manifest = $this->readManifest( $artifact_directory );
            $expires_at = is_array( $manifest ) ? strtotime( (string) ( $manifest['expires_at_utc'] ?? '' ) ) : false;
            $modified_at = @ filemtime( $artifact_directory );
            $expired = is_int( $expires_at ) && $expires_at > 0
                ? $expires_at < time()
                : ( is_int( $modified_at ) && $modified_at < ( time() - $this->retention_seconds ) );
            if ( ! $expired ) {
                continue;
            }

            $this->deleteDirectory( $artifact_directory );
            $removed++;
        }

        return( $removed );
    }

    protected function getArtifact( string $artifact_id ) : ?array {
        $artifact_id = $this->normalizeArtifactId( $artifact_id );
        if ( $artifact_id === '' ) {
            return( null );
        }

        $artifact_directory = $this->artifact_root . DIRECTORY_SEPARATOR . $artifact_id;
        $manifest = $this->readManifest( $artifact_directory );
        if ( ! is_array( $manifest ) ) {
            return( null );
        }

        return( $this->normalizeArtifactRecord( $manifest, $artifact_directory ) );
    }

    protected function normalizeArtifactRecord( array $manifest, string $artifact_directory ) : array {
        $archive_name = basename( (string) ( $manifest['archive_path'] ?? $manifest['download_filename'] ?? '' ) );
        $archive_path = $archive_name !== '' ? $artifact_directory . DIRECTORY_SEPARATOR . $archive_name : '';
        $expires_at = strtotime( (string) ( $manifest['expires_at_utc'] ?? '' ) );
        $manifest['artifact_directory'] = $artifact_directory;
        $manifest['resolved_archive_path'] = $archive_path;
        $manifest['expired'] = is_int( $expires_at ) && $expires_at > 0 && $expires_at < time();
        $manifest['scope'] = (string) ( $manifest['scope'] ?? 'author' );
        $manifest['owner_username'] = $this->normalizeOwner( (string) ( $manifest['owner_username'] ?? '' ) );
        $manifest['download_filename'] = basename( (string) ( $manifest['download_filename'] ?? $archive_name ) );
        $manifest['size_bytes'] = is_file( $archive_path ) ? (int) filesize( $archive_path ) : (int) ( $manifest['size_bytes'] ?? 0 );
        return( $manifest );
    }

    protected function readManifest( string $artifact_directory ) : ?array {
        $artifact_directory = rtrim( $artifact_directory, DIRECTORY_SEPARATOR );
        if ( $artifact_directory === '' || ! $this->pathIsContained( $artifact_directory, $this->artifact_root ) ) {
            return( null );
        }

        $manifest_path = $artifact_directory . DIRECTORY_SEPARATOR . 'manifest.json';
        if ( ! is_file( $manifest_path ) || ! is_readable( $manifest_path ) ) {
            return( null );
        }

        $json = file_get_contents( $manifest_path );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( null );
        }

        $decoded = json_decode( $json, true );
        return( is_array( $decoded ) ? $decoded : null );
    }

    protected function createArtifactId() : string {
        return( gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 12 ) ) );
    }

    protected function normalizeArtifactId( string $artifact_id ) : string {
        $artifact_id = strtolower( trim( $artifact_id ) );
        return( preg_match( '/^[a-z0-9._-]{12,80}$/', $artifact_id ) === 1 ? $artifact_id : '' );
    }

    protected function normalizeOwner( string $owner_username ) : string {
        $owner_username = strtolower( trim( $owner_username ) );
        $owner_username = preg_replace( '/[^a-z0-9_]+/', '', $owner_username ) ?? '';
        return( substr( $owner_username, 0, 64 ) );
    }

    protected function normalizeBasename( string $basename ) : string {
        $basename = strtolower( trim( $basename ) );
        $basename = preg_replace( '/[^a-z0-9._-]+/', '-', $basename ) ?? '';
        return( trim( $basename, '-.' ) );
    }

    protected function createZipFromDirectory( string $source_directory, string $archive_path, string $root_name ) : void {
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'The static export archive could not be created.' );
        }

        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        $this->addDirectoryToZip( $zip, $source_directory, $source_directory, trim( str_replace( '\\', '/', $root_name ), '/' ) );
        $zip->close();
    }

    protected function addDirectoryToZip( \ZipArchive $zip, string $source_directory, string $root_directory, string $zip_root ) : void {
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
            if ( is_dir( $source_path ) ) {
                $zip->addEmptyDir( rtrim( $zip_path, '/' ) );
                $this->addDirectoryToZip( $zip, $source_path, $root_directory, $zip_root );
                continue;
            }

            $zip->addFile( $source_path, $zip_path );
        }
    }

    protected function writeJsonFile( string $filename, array $data ) : void {
        $json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            throw new \RuntimeException( 'Unable to encode static export metadata.' );
        }
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $json . PHP_EOL ) ) {
            throw new \RuntimeException( 'Unable to write static export metadata.' );
        }
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create static export directory.' );
        }
    }

    protected function pathIsContained( string $path, string $root ) : bool {
        $root_real = realpath( $root );
        $path_real = realpath( $path );
        if ( ! is_string( $root_real ) || ! is_string( $path_real ) ) {
            return( false );
        }

        $root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        return( $path_real === $root_real || str_starts_with( $path_real, $root_prefix ) );
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
