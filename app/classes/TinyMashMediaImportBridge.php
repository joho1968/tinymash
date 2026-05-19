<?php
namespace app\classes;

class TinyMashMediaImportBridge {

    protected const MISSING_IMPORTED_MEDIA_PLACEHOLDER_URL = '/assets/tinymash/identity/missing-imported-media.svg';

    protected TinyMashMediaService $media_service;
    protected ?TinyMashMediaImportMapStore $map_store;

    public function __construct( TinyMashMediaService $media_service, ?TinyMashMediaImportMapStore $map_store = null ) {
        $this->media_service = $media_service;
        $this->map_store = $map_store;
    }

    public function importLocalFile( string $importer_key, string $owner_username, string $source_path, array $options = [] ) : array {
        $importer_key = strtolower( trim( $importer_key ) );
        $owner_username = strtolower( trim( $owner_username ) );
        if ( $importer_key === '' || $owner_username === '' ) {
            throw new \InvalidArgumentException( 'media_import_target' );
        }
        if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
            throw new \InvalidArgumentException( 'media_import_source' );
        }

        $source_key = trim( (string) ( $options['source_key'] ?? '' ) );
        if ( $source_key === '' ) {
            $source_key = $this->buildSourceKey( $source_path, $options );
        }

        if ( $this->map_store instanceof TinyMashMediaImportMapStore ) {
            $existing_mapping = $this->map_store->getMapping( $importer_key, $owner_username, $source_key );
            if ( is_array( $existing_mapping ) ) {
                $existing_metadata = $this->media_service->getAttachmentMetadataByMediaId(
                    (string) ( $existing_mapping['media_id'] ?? '' ),
                    [ $owner_username ]
                );
                if ( is_array( $existing_metadata ) && ! empty( $existing_metadata['url'] ) ) {
                    return(
                        [
                            'reused' => true,
                            'source_key' => $source_key,
                            'mapping' => $existing_mapping,
                            'media' => $existing_metadata,
                        ]
                    );
                }
            }
        }

        $stored_media = $this->media_service->storeImportedFile(
            $source_path,
            $owner_username,
            [
                'importer_key' => $importer_key,
                'source_key' => $source_key,
                'source_id' => (string) ( $options['source_id'] ?? '' ),
                'source_url' => (string) ( $options['source_url'] ?? '' ),
                'original_filename' => (string) ( $options['original_filename'] ?? basename( $source_path ) ),
                'alt_text' => (string) ( $options['alt_text'] ?? '' ),
            ]
        );

        $mapping = null;
        if ( $this->map_store instanceof TinyMashMediaImportMapStore ) {
            $mapping = $this->map_store->storeMapping(
                [
                    'importer_key' => $importer_key,
                    'owner_username' => $owner_username,
                    'source_key' => $source_key,
                    'source_id' => (string) ( $options['source_id'] ?? '' ),
                    'source_url' => (string) ( $options['source_url'] ?? '' ),
                    'source_path' => $source_path,
                    'original_filename' => (string) ( $stored_media['original_filename'] ?? basename( $source_path ) ),
                    'media_id' => (string) ( $stored_media['media_id'] ?? '' ),
                    'media_url' => (string) ( $stored_media['url'] ?? '' ),
                    'mime' => (string) ( $stored_media['mime'] ?? '' ),
                    'imported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                ]
            );
        }

        return(
            [
                'reused' => false,
                'source_key' => $source_key,
                'mapping' => $mapping,
                'media' => $stored_media,
            ]
        );
    }

    public function importRemoteUrl( string $importer_key, string $owner_username, string $source_url, array $options = [] ) : array {
        $importer_key = strtolower( trim( $importer_key ) );
        $owner_username = strtolower( trim( $owner_username ) );
        $source_url = $this->normalizeRemoteSourceUrl( $source_url );
        if ( $importer_key === '' || $owner_username === '' ) {
            throw new \InvalidArgumentException( 'media_import_target' );
        }
        if ( $source_url === '' ) {
            throw new \InvalidArgumentException( 'media_import_source' );
        }

        $allowed_hosts = is_array( $options['allowed_hosts'] ?? null ) ? $options['allowed_hosts'] : [];
        if ( ! $this->isAllowedRemoteHost( $source_url, $allowed_hosts ) ) {
            throw new \InvalidArgumentException( 'media_import_remote_host' );
        }

        $source_key = trim( (string) ( $options['source_key'] ?? '' ) );
        if ( $source_key === '' ) {
            $source_key = 'url:' . sha1( $source_url );
        }

        if ( $this->map_store instanceof TinyMashMediaImportMapStore ) {
            $existing_mapping = $this->map_store->getMapping( $importer_key, $owner_username, $source_key );
            if ( is_array( $existing_mapping ) ) {
                $existing_metadata = $this->media_service->getAttachmentMetadataByMediaId(
                    (string) ( $existing_mapping['media_id'] ?? '' ),
                    [ $owner_username ]
                );
                if ( is_array( $existing_metadata ) && ! empty( $existing_metadata['url'] ) ) {
                    return(
                        [
                            'reused' => true,
                            'source_key' => $source_key,
                            'mapping' => $existing_mapping,
                            'media' => $existing_metadata,
                        ]
                    );
                }
            }
        }

        $download = $this->downloadRemoteUrlToTemporaryFile(
            $source_url,
            [
                'timeout_seconds' => max( 5, (int) ( $options['timeout_seconds'] ?? 20 ) ),
                'max_bytes' => max( 1, (int) ( $options['max_bytes'] ?? ( 25 * 1024 * 1024 ) ) ),
                'allowed_hosts' => $allowed_hosts,
            ]
        );

        try {
            $stored_media = $this->media_service->storeImportedFile(
                (string) $download['path'],
                $owner_username,
                [
                    'importer_key' => $importer_key,
                    'source_key' => $source_key,
                    'source_id' => (string) ( $options['source_id'] ?? '' ),
                    'source_url' => (string) ( $download['effective_url'] ?? $source_url ),
                    'original_filename' => (string) ( $options['original_filename'] ?? $this->buildRemoteFilename( $source_url ) ),
                    'alt_text' => (string) ( $options['alt_text'] ?? '' ),
                ]
            );
        } finally {
            if ( ! empty( $download['path'] ) && is_string( $download['path'] ) && is_file( $download['path'] ) ) {
                @ unlink( $download['path'] );
            }
            if ( ! empty( $download['path'] ) && is_string( $download['path'] ) ) {
                $this->removeDirectoryIfEmpty( dirname( $download['path'] ) );
            }
        }

        $mapping = null;
        if ( $this->map_store instanceof TinyMashMediaImportMapStore ) {
            $mapping = $this->map_store->storeMapping(
                [
                    'importer_key' => $importer_key,
                    'owner_username' => $owner_username,
                    'source_key' => $source_key,
                    'source_id' => (string) ( $options['source_id'] ?? '' ),
                    'source_url' => (string) ( $download['effective_url'] ?? $source_url ),
                    'source_path' => '',
                    'original_filename' => (string) ( $stored_media['original_filename'] ?? $this->buildRemoteFilename( $source_url ) ),
                    'media_id' => (string) ( $stored_media['media_id'] ?? '' ),
                    'media_url' => (string) ( $stored_media['url'] ?? '' ),
                    'mime' => (string) ( $stored_media['mime'] ?? '' ),
                    'imported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                ]
            );
        }

        return(
            [
                'reused' => false,
                'source_key' => $source_key,
                'mapping' => $mapping,
                'media' => $stored_media,
            ]
        );
    }

    public function attachMediaToEntry( string $owner_username, string $entry_id, array $media_ids ) : int {
        $owner_username = strtolower( trim( $owner_username ) );
        $entry_id = trim( $entry_id );
        if ( $owner_username === '' || $entry_id === '' || empty( $media_ids ) ) {
            return( 0 );
        }

        $attached = 0;
        $seen_media_ids = [];
        foreach ( $media_ids as $media_id ) {
            $media_id = trim( (string) $media_id );
            if ( $media_id === '' || isset( $seen_media_ids[$media_id] ) ) {
                continue;
            }
            $seen_media_ids[$media_id] = true;

            $assigned_metadata = $this->media_service->assignAttachmentToContent(
                $media_id,
                [ $owner_username ],
                $entry_id,
                '',
                ''
            );
            if ( is_array( $assigned_metadata ) ) {
                $attached++;
            }
        }

        return( $attached );
    }

    public function getMissingImportedMediaPlaceholderUrl() : string {
        return( self::MISSING_IMPORTED_MEDIA_PLACEHOLDER_URL );
    }

    public function buildMissingImportedMediaFallback( string $source_url, string $alt_text = '' ) : array {
        $source_url = trim( $source_url );
        $label = $this->buildMissingImportedMediaLabel( $source_url );
        $alt_text = trim( $alt_text );
        if ( $alt_text === '' ) {
            $alt_text = 'Missing imported image';
        } else {
            $alt_text = 'Missing imported image: ' . $alt_text;
        }

        return(
            [
                'placeholder_url' => $this->getMissingImportedMediaPlaceholderUrl(),
                'source_url' => $source_url,
                'alt_text' => $alt_text,
                'title' => 'Missing resource: ' . $label,
            ]
        );
    }

    public function cleanupTemporaryImportDirectory( int $stale_after_seconds = 3600 ) : array {
        $stale_after_seconds = max( 60, $stale_after_seconds );
        $temporary_directory = $this->getTemporaryImportDirectory( false );
        if ( ! is_dir( $temporary_directory ) ) {
            return(
                [
                    'checked_files' => 0,
                    'removed_files' => 0,
                    'removed_directory' => false,
                    'directory_exists' => false,
                ]
            );
        }

        $checked_files = 0;
        $removed_files = 0;
        $now = time();
        $temporary_files = glob( $temporary_directory . DIRECTORY_SEPARATOR . 'tm_media_*' );
        if ( is_array( $temporary_files ) ) {
            foreach ( $temporary_files as $temporary_file ) {
                if ( ! is_string( $temporary_file ) || ! is_file( $temporary_file ) ) {
                    continue;
                }

                $checked_files++;
                $modified_at = filemtime( $temporary_file );
                if ( ! is_int( $modified_at ) || ( $now - $modified_at ) < $stale_after_seconds ) {
                    continue;
                }

                if ( @ unlink( $temporary_file ) ) {
                    $removed_files++;
                }
            }
        }

        $removed_directory = $this->removeDirectoryIfEmpty( $temporary_directory );
        return(
            [
                'checked_files' => $checked_files,
                'removed_files' => $removed_files,
                'removed_directory' => $removed_directory,
                'directory_exists' => is_dir( $temporary_directory ),
            ]
        );
    }

    protected function buildSourceKey( string $source_path, array $options ) : string {
        $source_url = trim( (string) ( $options['source_url'] ?? '' ) );
        if ( $source_url !== '' ) {
            return( 'url:' . sha1( $source_url ) );
        }

        $source_id = trim( (string) ( $options['source_id'] ?? '' ) );
        if ( $source_id !== '' ) {
            return( 'id:' . sha1( $source_id ) );
        }

        $real_path = realpath( $source_path ) ?: $source_path;
        $bytes = is_file( $source_path ) ? (string) filesize( $source_path ) : '0';
        $modified_at = is_file( $source_path ) ? (string) filemtime( $source_path ) : '0';
        return( 'file:' . sha1( $real_path . '|' . $bytes . '|' . $modified_at ) );
    }

    protected function normalizeRemoteSourceUrl( string $source_url ) : string {
        $source_url = trim( $source_url );
        if ( $source_url === '' || preg_match( '#^https?://#i', $source_url ) !== 1 ) {
            return( '' );
        }

        $parts = parse_url( $source_url );
        if ( ! is_array( $parts ) ) {
            return( '' );
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {
            return( '' );
        }

        return( $source_url );
    }

    protected function isAllowedRemoteHost( string $source_url, array $allowed_hosts ) : bool {
        $host = strtolower( trim( (string) parse_url( $source_url, PHP_URL_HOST ) ) );
        if ( $host === '' || empty( $allowed_hosts ) ) {
            return( false );
        }

        foreach ( $allowed_hosts as $allowed_host ) {
            $allowed_host = strtolower( trim( (string) $allowed_host ) );
            if ( $allowed_host === '' ) {
                continue;
            }
            if ( $allowed_host === $host ) {
                return( true );
            }
            if ( str_starts_with( $allowed_host, '*.' ) ) {
                $suffix = substr( $allowed_host, 1 );
                if ( is_string( $suffix ) && $suffix !== '' && str_ends_with( $host, $suffix ) ) {
                    return( true );
                }
            }
        }

        return( false );
    }

    protected function downloadRemoteUrlToTemporaryFile( string $source_url, array $options = [] ) : array {
        if ( ! extension_loaded( 'curl' ) ) {
            throw new \RuntimeException( 'Remote media fetch requires the curl PHP extension.' );
        }

        $allowed_hosts = is_array( $options['allowed_hosts'] ?? null ) ? $options['allowed_hosts'] : [];
        if ( ! $this->isAllowedRemoteHost( $source_url, $allowed_hosts ) ) {
            throw new \InvalidArgumentException( 'media_import_remote_host' );
        }

        $timeout_seconds = max( 5, (int) ( $options['timeout_seconds'] ?? 20 ) );
        $max_bytes = max( 1, (int) ( $options['max_bytes'] ?? ( 25 * 1024 * 1024 ) ) );
        $temporary_directory = $this->getTemporaryImportDirectory();
        $temporary_path = tempnam( $temporary_directory, 'tm_media_' );
        if ( ! is_string( $temporary_path ) || $temporary_path === '' ) {
            throw new \RuntimeException( 'Unable to allocate a temporary file for remote media.' );
        }

        $handle = @ fopen( $temporary_path, 'wb' );
        if ( $handle === false ) {
            @ unlink( $temporary_path );
            throw new \RuntimeException( 'Unable to open a temporary file for remote media.' );
        }

        $curl = curl_init( $source_url );
        if ( $curl === false ) {
            fclose( $handle );
            @ unlink( $temporary_path );
            throw new \RuntimeException( 'Unable to initialize remote media transfer.' );
        }

        $written_bytes = 0;
        curl_setopt_array(
            $curl,
            [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => min( 10, $timeout_seconds ),
                CURLOPT_TIMEOUT => $timeout_seconds,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'tinymash media importer',
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function( mixed $resource, float $download_total, float $downloaded_now ) use ( &$written_bytes, $max_bytes ) : int {
                    $written_bytes = (int) $downloaded_now;
                    return( $written_bytes > $max_bytes ? 1 : 0 );
                },
            ]
        );

        try {
            $result = curl_exec( $curl );
            if ( $result === false ) {
                throw new \RuntimeException( 'Unable to download remote media.' );
            }

            $effective_url = (string) curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL );
            if ( $effective_url === '' || ! $this->isAllowedRemoteHost( $effective_url, $allowed_hosts ) ) {
                throw new \RuntimeException( 'Remote media redirect is not allowed.' );
            }
        } finally {
            curl_close( $curl );
            fflush( $handle );
            fclose( $handle );
        }

        $stored_bytes = is_file( $temporary_path ) ? (int) filesize( $temporary_path ) : 0;
        if ( $stored_bytes < 1 ) {
            @ unlink( $temporary_path );
            throw new \RuntimeException( 'Remote media download was empty.' );
        }
        if ( $stored_bytes > $max_bytes ) {
            @ unlink( $temporary_path );
            throw new \RuntimeException( 'Remote media download exceeded the allowed size.' );
        }

        return(
            [
                'path' => $temporary_path,
                'effective_url' => $effective_url,
                'bytes' => $stored_bytes,
            ]
        );
    }

    protected function getTemporaryImportDirectory( bool $create = true ) : string {
        if ( $create ) {
            return( tinymash_get_runtime_temp_subdirectory( 'tinymash-media-import' ) );
        }

        return( rtrim( tinymash_get_runtime_temp_directory(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'tinymash-media-import' );
    }

    protected function removeDirectoryIfEmpty( string $directory ) : bool {
        $directory = rtrim( trim( $directory ), DIRECTORY_SEPARATOR );
        if ( $directory === '' || ! is_dir( $directory ) ) {
            return( false );
        }
        if ( basename( $directory ) !== 'tinymash-media-import' ) {
            return( false );
        }

        $entries = scandir( $directory );
        if ( ! is_array( $entries ) ) {
            return( false );
        }

        foreach ( $entries as $entry ) {
            if ( $entry !== '.' && $entry !== '..' ) {
                return( false );
            }
        }

        return( @ rmdir( $directory ) );
    }

    protected function buildRemoteFilename( string $source_url ) : string {
        $path = (string) parse_url( $source_url, PHP_URL_PATH );
        $filename = basename( $path );
        return( $filename !== '' ? $filename : 'remote-image' );
    }

    protected function buildMissingImportedMediaLabel( string $source_url ) : string {
        $source_url = trim( $source_url );
        $path = (string) parse_url( $source_url, PHP_URL_PATH );
        $filename = trim( basename( $path ) );
        if ( $filename !== '' && $filename !== '.' && $filename !== '/' ) {
            return( $filename );
        }

        return( $source_url !== '' ? $source_url : 'unknown resource' );
    }

}
