<?php
namespace app\classes;

class TinyMashMediaAttachmentMetadataStore {
    protected const INDEX_VERSION = '2026-03-30-1';

    protected string $media_root;
    protected string $metadata_index_filename;
    protected ?array $metadata_files_cache = null;
    protected ?array $metadata_items_cache = null;
    protected ?array $metadata_by_media_id_cache = null;
    protected ?array $metadata_by_url_cache = null;

    public function __construct( string $media_root ) {
        $this->media_root = rtrim( $media_root, DIRECTORY_SEPARATOR );
        $this->metadata_index_filename = dirname( $this->media_root ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'media-metadata-index.json';
    }

    public function clearPersistentCache() : int {
        $this->invalidateCaches();
        if ( ! is_file( $this->metadata_index_filename ) ) {
            return( 0 );
        }

        return( @ unlink( $this->metadata_index_filename ) ? 1 : 0 );
    }

    public function warmPersistentCache() : array {
        $items = $this->buildMetadataItemsFromFiles();
        $this->primeCachesFromMetadataItems( $items );
        $this->writePersistentMetadataIndex( $items );

        return(
            [
                'items' => count( $items ),
                'cache_file' => $this->metadata_index_filename,
            ]
        );
    }

    public function storeMetadata( array $metadata ) : array {
        $owner_username = $this->normalizeOwnerUsername( (string) ( $metadata['owner_username'] ?? '' ) );
        $year = $this->normalizeDateSegment( (string) ( $metadata['year'] ?? '' ), 4 );
        $month = $this->normalizeDateSegment( (string) ( $metadata['month'] ?? '' ), 2 );
        $filename = $this->normalizeFilename( (string) ( $metadata['filename'] ?? '' ) );

        if ( $owner_username === '' || $year === '' || $month === '' || $filename === '' ) {
            throw new \InvalidArgumentException( 'Invalid media metadata location.' );
        }

        $metadata_path = $this->buildMetadataPath( $owner_username, $year, $month, $filename );
        $metadata_directory = dirname( $metadata_path );
        if ( ! is_dir( $metadata_directory ) && ! @ mkdir( $metadata_directory, 0775, true ) && ! is_dir( $metadata_directory ) ) {
            throw new \RuntimeException( 'Unable to create media metadata directory.' );
        }

        $normalized_metadata = $this->normalizeMetadata( array_merge( $metadata, [
            'owner_username' => $owner_username,
            'year' => $year,
            'month' => $month,
            'filename' => $filename,
        ] ) );
        $encoded_metadata = json_encode( $normalized_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded_metadata ) || $encoded_metadata === '' ) {
            throw new \RuntimeException( 'Unable to encode media metadata.' );
        }

        $handle = @ fopen( $metadata_path, 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to open media metadata.' );
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock media metadata.' );
            }
            ftruncate( $handle, 0 );
            rewind( $handle );
            if ( fwrite( $handle, $encoded_metadata . PHP_EOL ) === false ) {
                throw new \RuntimeException( 'Unable to write media metadata.' );
            }
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        $this->invalidatePersistentCache();
        return( $normalized_metadata );
    }

    public function getMetadata( string $owner_username, string $year, string $month, string $filename ) : ?array {
        $owner_username = $this->normalizeOwnerUsername( $owner_username );
        $year = $this->normalizeDateSegment( $year, 4 );
        $month = $this->normalizeDateSegment( $month, 2 );
        $filename = $this->normalizeFilename( $filename );
        if ( $owner_username === '' || $year === '' || $month === '' || $filename === '' ) {
            return( null );
        }

        $metadata_path = $this->buildMetadataPath( $owner_username, $year, $month, $filename );
        if ( ! is_file( $metadata_path ) || ! is_readable( $metadata_path ) ) {
            return( null );
        }

        $handle = @ fopen( $metadata_path, 'rb' );
        if ( $handle === false ) {
            return( null );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( null );
            }
            $metadata_json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $metadata_json ) || trim( $metadata_json ) === '' ) {
            return( null );
        }

        $metadata = json_decode( $metadata_json, true, 16 );
        if ( ! is_array( $metadata ) || empty( $metadata ) ) {
            return( null );
        }

        return( $this->normalizeMetadata( $metadata ) );
    }

    public function getMetadataByMediaId( string $media_id, array $owner_usernames = [] ) : ?array {
        $media_id = trim( $media_id );
        if ( $media_id === '' ) {
            return( null );
        }

        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $candidates = $this->getMetadataCandidatesByMediaId( $media_id );
        if ( empty( $candidates ) ) {
            $this->refreshPersistentCacheFromFiles();
            $candidates = $this->getMetadataCandidatesByMediaId( $media_id );
        }

        foreach ( $candidates as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }
            return( $metadata );
        }

        return( null );
    }

    public function getMetadataByUrl( string $url, array $owner_usernames = [] ) : ?array {
        $url = trim( $url );
        if ( $url === '' ) {
            return( null );
        }

        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $candidates = $this->getMetadataCandidatesByUrl( $url );
        if ( empty( $candidates ) ) {
            $this->refreshPersistentCacheFromFiles();
            $candidates = $this->getMetadataCandidatesByUrl( $url );
        }

        foreach ( $candidates as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }
            return( $metadata );
        }

        return( null );
    }

    public function listMetadata( array $owner_usernames = [], int $limit = 50 ) : array {
        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $metadata_items = [];

        foreach ( $this->getAllMetadataItems() as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }
            $metadata_items[] = $metadata;
        }

        usort(
            $metadata_items,
            static function( array $left, array $right ) : int {
                $left_created = (string) ( $left['created_at_utc'] ?? '' );
                $right_created = (string) ( $right['created_at_utc'] ?? '' );
                if ( $left_created !== $right_created ) {
                    return( strcmp( $right_created, $left_created ) );
                }

                return( strcmp( (string) ( $left['media_id'] ?? '' ), (string) ( $right['media_id'] ?? '' ) ) );
            }
        );

        return( array_slice( $metadata_items, 0, max( 1, $limit ) ) );
    }

    public function listMetadataForContent( array $owner_usernames = [], string $attached_entry_id = '', string $attached_draft_id = '', string $attachment_session_id = '', int $limit = 50 ) : array {
        $attached_entry_id = trim( $attached_entry_id );
        $attached_draft_id = trim( $attached_draft_id );
        $attachment_session_id = $this->normalizeAttachmentSessionId( $attachment_session_id );
        if ( $attached_entry_id === '' && $attached_draft_id === '' && $attachment_session_id === '' ) {
            return( [] );
        }

        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $metadata_items = [];
        foreach ( $this->getAllMetadataItems() as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }
            if ( ! $this->metadataMatchesContentContext( $metadata, $attached_entry_id, $attached_draft_id, $attachment_session_id ) ) {
                continue;
            }
            $metadata_items[] = $metadata;
        }

        usort(
            $metadata_items,
            static function( array $left, array $right ) : int {
                $left_created = (string) ( $left['created_at_utc'] ?? '' );
                $right_created = (string) ( $right['created_at_utc'] ?? '' );
                if ( $left_created !== $right_created ) {
                    return( strcmp( $right_created, $left_created ) );
                }

                return( strcmp( (string) ( $left['media_id'] ?? '' ), (string) ( $right['media_id'] ?? '' ) ) );
            }
        );

        return( array_slice( $metadata_items, 0, max( 1, $limit ) ) );
    }

    public function assignMetadataToContent( string $media_id, array $owner_usernames = [], string $attached_entry_id = '', string $attached_draft_id = '', string $attachment_session_id = '' ) : ?array {
        $metadata = $this->getMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( null );
        }

        $metadata['attached_entry_id'] = trim( $attached_entry_id );
        $metadata['attached_draft_id'] = trim( $attached_draft_id );
        $metadata['attachment_session_id'] = $this->normalizeAttachmentSessionId( $attachment_session_id );
        return( $this->storeMetadata( $metadata ) );
    }

    public function deleteMetadata( array $metadata ) : bool {
        $owner_username = $this->normalizeOwnerUsername( (string) ( $metadata['owner_username'] ?? '' ) );
        $year = $this->normalizeDateSegment( (string) ( $metadata['year'] ?? '' ), 4 );
        $month = $this->normalizeDateSegment( (string) ( $metadata['month'] ?? '' ), 2 );
        $filename = $this->normalizeFilename( (string) ( $metadata['filename'] ?? '' ) );
        if ( $owner_username === '' || $year === '' || $month === '' || $filename === '' ) {
            throw new \InvalidArgumentException( 'Invalid media metadata location.' );
        }

        $metadata_path = $this->buildMetadataPath( $owner_username, $year, $month, $filename );
        if ( is_file( $metadata_path ) && ! @ unlink( $metadata_path ) ) {
            throw new \RuntimeException( 'Unable to delete media metadata.' );
        }

        $this->invalidatePersistentCache();
        return( true );
    }

    public function adoptSessionMetadataToDraft( array $owner_usernames, string $attachment_session_id, string $draft_id, string $entry_id = '' ) : int {
        $attachment_session_id = $this->normalizeAttachmentSessionId( $attachment_session_id );
        $draft_id = trim( $draft_id );
        $entry_id = trim( $entry_id );
        if ( $attachment_session_id === '' || $draft_id === '' ) {
            return( 0 );
        }

        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $updated = 0;
        foreach ( $this->getAllMetadataItems() as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }
            if ( (string) ( $metadata['attachment_session_id'] ?? '' ) !== $attachment_session_id ) {
                continue;
            }

            $metadata['attached_draft_id'] = $draft_id;
            $metadata['attached_entry_id'] = $entry_id;
            $metadata['attachment_session_id'] = '';
            $this->storeMetadata( $metadata );
            $updated++;
        }

        return( $updated );
    }

    public function adoptMetadataToEntry( array $owner_usernames, string $entry_id, string $source_entry_id = '', string $source_draft_id = '', string $attachment_session_id = '' ) : int {
        $entry_id = trim( $entry_id );
        $source_entry_id = trim( $source_entry_id );
        $source_draft_id = trim( $source_draft_id );
        $attachment_session_id = $this->normalizeAttachmentSessionId( $attachment_session_id );
        if ( $entry_id === '' ) {
            return( 0 );
        }

        $allowed_owner_lookup = $this->buildOwnerLookup( $owner_usernames );
        $updated = 0;
        foreach ( $this->getAllMetadataItems() as $metadata ) {
            if ( ! $this->metadataOwnerAllowed( $metadata, $allowed_owner_lookup ) ) {
                continue;
            }

            $matches_context =
                ( $attachment_session_id !== '' && (string) ( $metadata['attachment_session_id'] ?? '' ) === $attachment_session_id ) ||
                ( $source_draft_id !== '' && (string) ( $metadata['attached_draft_id'] ?? '' ) === $source_draft_id ) ||
                ( $source_entry_id !== '' && (string) ( $metadata['attached_entry_id'] ?? '' ) === $source_entry_id );
            if ( ! $matches_context ) {
                continue;
            }

            $metadata['attached_entry_id'] = $entry_id;
            $metadata['attached_draft_id'] = '';
            $metadata['attachment_session_id'] = '';
            $this->storeMetadata( $metadata );
            $updated++;
        }

        return( $updated );
    }

    protected function buildMetadataPath( string $owner_username, string $year, string $month, string $filename ) : string {
        return(
            $this->media_root
            . DIRECTORY_SEPARATOR . $owner_username
            . DIRECTORY_SEPARATOR . $year
            . DIRECTORY_SEPARATOR . $month
            . DIRECTORY_SEPARATOR . $filename
            . '.meta.json'
        );
    }

    protected function getMetadataFiles() : array {
        if ( is_array( $this->metadata_files_cache ) ) {
            return( $this->metadata_files_cache );
        }

        if ( ! is_dir( $this->media_root ) ) {
            $this->metadata_files_cache = [];
            return( [] );
        }

        $metadata_files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->media_root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            if ( ! $file_info->isFile() || ! str_ends_with( $file_info->getFilename(), '.meta.json' ) ) {
                continue;
            }
            $metadata_files[] = $file_info->getPathname();
        }

        sort( $metadata_files );
        $this->metadata_files_cache = $metadata_files;
        return( $metadata_files );
    }

    protected function getAllMetadataItems() : array {
        if ( is_array( $this->metadata_items_cache ) ) {
            return( $this->metadata_items_cache );
        }

        $items = $this->loadPersistentMetadataIndex();
        if ( ! is_array( $items ) ) {
            $items = $this->buildMetadataItemsFromFiles();
            $this->writePersistentMetadataIndex( $items );
        }

        $this->primeCachesFromMetadataItems( $items );
        return( $items );
    }

    protected function getMetadataCandidatesByMediaId( string $media_id ) : array {
        $this->getAllMetadataItems();
        return( is_array( $this->metadata_by_media_id_cache[$media_id] ?? null ) ? $this->metadata_by_media_id_cache[$media_id] : [] );
    }

    protected function getMetadataCandidatesByUrl( string $url ) : array {
        $this->getAllMetadataItems();
        return( is_array( $this->metadata_by_url_cache[$url] ?? null ) ? $this->metadata_by_url_cache[$url] : [] );
    }

    protected function invalidateCaches() : void {
        $this->metadata_files_cache = null;
        $this->metadata_items_cache = null;
        $this->metadata_by_media_id_cache = null;
        $this->metadata_by_url_cache = null;
    }

    protected function invalidatePersistentCache() : void {
        $this->invalidateCaches();
        if ( is_file( $this->metadata_index_filename ) ) {
            @ unlink( $this->metadata_index_filename );
        }
    }

    protected function refreshPersistentCacheFromFiles() : void {
        $this->invalidateCaches();
        $items = $this->buildMetadataItemsFromFiles();
        $this->primeCachesFromMetadataItems( $items );
        $this->writePersistentMetadataIndex( $items );
    }

    protected function buildMetadataItemsFromFiles() : array {
        $items = [];
        foreach ( $this->getMetadataFiles() as $metadata_path ) {
            $metadata = $this->readMetadataFile( $metadata_path );
            if ( ! is_array( $metadata ) ) {
                continue;
            }

            $items[] = $metadata;
        }

        return( $items );
    }

    protected function primeCachesFromMetadataItems( array $items ) : void {
        $normalized_items = [];
        $by_media_id = [];
        $by_url = [];

        foreach ( $items as $metadata ) {
            if ( ! is_array( $metadata ) ) {
                continue;
            }

            $normalized_metadata = $this->normalizeMetadata( $metadata );
            $normalized_items[] = $normalized_metadata;

            $media_id = trim( (string) ( $normalized_metadata['media_id'] ?? '' ) );
            if ( $media_id !== '' ) {
                if ( ! isset( $by_media_id[$media_id] ) || ! is_array( $by_media_id[$media_id] ) ) {
                    $by_media_id[$media_id] = [];
                }
                $by_media_id[$media_id][] = $normalized_metadata;
            }

            $url = trim( (string) ( $normalized_metadata['url'] ?? '' ) );
            if ( $url !== '' ) {
                if ( ! isset( $by_url[$url] ) || ! is_array( $by_url[$url] ) ) {
                    $by_url[$url] = [];
                }
                $by_url[$url][] = $normalized_metadata;
            }
        }

        $this->metadata_items_cache = $normalized_items;
        $this->metadata_by_media_id_cache = $by_media_id;
        $this->metadata_by_url_cache = $by_url;
    }

    protected function loadPersistentMetadataIndex() : ?array {
        if ( ! is_file( $this->metadata_index_filename ) || ! is_readable( $this->metadata_index_filename ) ) {
            return( null );
        }

        $handle = @ fopen( $this->metadata_index_filename, 'rb' );
        if ( $handle === false ) {
            return( null );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( null );
            }
            $index_json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $index_json ) || trim( $index_json ) === '' ) {
            return( null );
        }

        $index = json_decode( $index_json, true, 16 );
        if ( ! is_array( $index ) ) {
            return( null );
        }
        if ( (string) ( $index['version'] ?? '' ) !== self::INDEX_VERSION ) {
            return( null );
        }

        $items = is_array( $index['items'] ?? null ) ? $index['items'] : null;
        if ( ! is_array( $items ) ) {
            return( null );
        }

        return( $items );
    }

    protected function writePersistentMetadataIndex( array $items ) : void {
        $index_directory = dirname( $this->metadata_index_filename );
        if ( ! is_dir( $index_directory ) && ! @ mkdir( $index_directory, 0775, true ) && ! is_dir( $index_directory ) ) {
            return;
        }

        $payload = [
            'version' => self::INDEX_VERSION,
            'built_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'items' => array_values( $items ),
        ];
        $encoded_index = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded_index ) || $encoded_index === '' ) {
            return;
        }

        $handle = @ fopen( $this->metadata_index_filename, 'c+' );
        if ( $handle === false ) {
            return;
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                return;
            }
            ftruncate( $handle, 0 );
            rewind( $handle );
            fwrite( $handle, $encoded_index . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function readMetadataFile( string $metadata_path ) : ?array {
        if ( ! is_file( $metadata_path ) || ! is_readable( $metadata_path ) ) {
            return( null );
        }

        $metadata_json = file_get_contents( $metadata_path );
        if ( ! is_string( $metadata_json ) || trim( $metadata_json ) === '' ) {
            return( null );
        }

        $metadata = json_decode( $metadata_json, true, 16 );
        if ( ! is_array( $metadata ) || empty( $metadata ) ) {
            return( null );
        }

        return( $this->normalizeMetadata( $metadata ) );
    }

    protected function normalizeMetadata( array $metadata ) : array {
        $normalized_metadata = [
            'media_id' => trim( (string) ( $metadata['media_id'] ?? '' ) ),
            'owner_username' => $this->normalizeOwnerUsername( (string) ( $metadata['owner_username'] ?? '' ) ),
            'year' => $this->normalizeDateSegment( (string) ( $metadata['year'] ?? '' ), 4 ),
            'month' => $this->normalizeDateSegment( (string) ( $metadata['month'] ?? '' ), 2 ),
            'filename' => $this->normalizeFilename( (string) ( $metadata['filename'] ?? '' ) ),
            'path' => trim( (string) ( $metadata['path'] ?? '' ) ),
            'url' => trim( (string) ( $metadata['url'] ?? '' ) ),
            'original_filename' => trim( (string) ( $metadata['original_filename'] ?? '' ) ),
            'alt_text' => trim( (string) ( $metadata['alt_text'] ?? '' ) ),
            'markdown' => trim( (string) ( $metadata['markdown'] ?? '' ) ),
            'mime' => trim( (string) ( $metadata['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
            'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
            'driver' => trim( (string) ( $metadata['driver'] ?? '' ) ),
            'derivative_key' => trim( (string) ( $metadata['derivative_key'] ?? 'stored_primary' ) ),
            'resized_on_ingest' => ! empty( $metadata['resized_on_ingest'] ),
            'source' => trim( (string) ( $metadata['source'] ?? 'upload' ) ),
            'importer_key' => trim( (string) ( $metadata['importer_key'] ?? '' ) ),
            'source_key' => trim( (string) ( $metadata['source_key'] ?? '' ) ),
            'source_id' => trim( (string) ( $metadata['source_id'] ?? '' ) ),
            'source_url' => trim( (string) ( $metadata['source_url'] ?? '' ) ),
            'attached_entry_id' => trim( (string) ( $metadata['attached_entry_id'] ?? '' ) ),
            'attached_draft_id' => trim( (string) ( $metadata['attached_draft_id'] ?? '' ) ),
            'attachment_session_id' => $this->normalizeAttachmentSessionId( (string) ( $metadata['attachment_session_id'] ?? '' ) ),
            'thumbnail' => $this->normalizeDerivativeAsset( $metadata['thumbnail'] ?? [] ),
            'display' => $this->normalizeDerivativeAsset( $metadata['display'] ?? [] ),
            'metadata_rows' => $this->normalizeMetadataRows( $metadata['metadata_rows'] ?? [] ),
            'public_metadata_rows' => $this->normalizePublicMetadataRows( $metadata['public_metadata_rows'] ?? [] ),
            'metadata_retention_groups' => $this->normalizeMetadataGroupKeys( $metadata['metadata_retention_groups'] ?? [] ),
            'metadata_public_groups' => $this->normalizeMetadataGroupKeys( $metadata['metadata_public_groups'] ?? [] ),
            'embedded_metadata_stripped' => ! empty( $metadata['embedded_metadata_stripped'] ),
            'embedded_metadata_strip_note' => trim( (string) ( $metadata['embedded_metadata_strip_note'] ?? '' ) ),
        ];

        if ( $normalized_metadata['media_id'] === '' ) {
            $normalized_metadata['media_id'] = 'media_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) );
        }
        if ( $normalized_metadata['created_at_utc'] === '' ) {
            $normalized_metadata['created_at_utc'] = gmdate( 'Y-m-d H:i:s' );
        }
        if ( $normalized_metadata['mime'] === '' ) {
            $normalized_metadata['mime'] = 'application/octet-stream';
        }
        if ( $normalized_metadata['derivative_key'] === '' ) {
            $normalized_metadata['derivative_key'] = 'stored_primary';
        }
        if ( $normalized_metadata['source'] === '' ) {
            $normalized_metadata['source'] = 'upload';
        }

        return( $normalized_metadata );
    }

    protected function normalizeDerivativeAsset( mixed $derivative ) : array {
        if ( ! is_array( $derivative ) ) {
            return( [] );
        }

        $normalized_derivative = [
            'filename' => $this->normalizeFilename( (string) ( $derivative['filename'] ?? '' ) ),
            'path' => trim( (string) ( $derivative['path'] ?? '' ) ),
            'url' => trim( (string) ( $derivative['url'] ?? '' ) ),
            'mime' => trim( (string) ( $derivative['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $derivative['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $derivative['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $derivative['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $derivative['derivative_key'] ?? 'thumbnail' ) ),
        ];

        if ( $normalized_derivative['filename'] === '' || $normalized_derivative['url'] === '' ) {
            return( [] );
        }
        if ( $normalized_derivative['mime'] === '' ) {
            $normalized_derivative['mime'] = 'application/octet-stream';
        }
        if ( $normalized_derivative['derivative_key'] === '' ) {
            $normalized_derivative['derivative_key'] = 'thumbnail';
        }

        return( $normalized_derivative );
    }

    protected function normalizeMetadataRows( mixed $rows ) : array {
        if ( ! is_array( $rows ) ) {
            return( [] );
        }

        $normalized_rows = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $group_key = $this->normalizeMetadataGroupKey( (string) ( $row['group_key'] ?? '' ) );
            $group = trim( (string) ( $row['group'] ?? '' ) );
            $label = trim( (string) ( $row['label'] ?? '' ) );
            $value = trim( (string) ( $row['value'] ?? '' ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }

            $normalized_rows[] = [
                'group_key' => $group_key,
                'group' => mb_substr( $group !== '' ? $group : 'Image', 0, 80 ),
                'label' => mb_substr( $label, 0, 120 ),
                'value' => mb_substr( $value, 0, 500 ),
            ];

            if ( count( $normalized_rows ) >= 24 ) {
                break;
            }
        }

        return( $normalized_rows );
    }

    protected function normalizePublicMetadataRows( mixed $rows ) : array {
        $normalized_rows = [];
        foreach ( $this->normalizeMetadataRows( $rows ) as $row ) {
            $normalized_rows[] = [
                'group' => (string) ( $row['group'] ?? 'Image' ),
                'label' => (string) ( $row['label'] ?? '' ),
                'value' => (string) ( $row['value'] ?? '' ),
            ];
        }

        return( $normalized_rows );
    }

    protected function normalizeMetadataGroupKeys( mixed $groups ) : array {
        if ( ! is_array( $groups ) ) {
            return( [] );
        }

        $normalized_groups = [];
        foreach ( $groups as $group ) {
            if ( ! is_string( $group ) ) {
                continue;
            }

            $normalized_group = $this->normalizeMetadataGroupKey( $group );
            if ( $normalized_group !== '' && ! in_array( $normalized_group, $normalized_groups, true ) ) {
                $normalized_groups[] = $normalized_group;
            }
        }

        return( $normalized_groups );
    }

    protected function normalizeMetadataGroupKey( string $group_key ) : string {
        $group_key = strtolower( trim( $group_key ) );
        return( in_array( $group_key, [ 'camera_info', 'exposure_info', 'date_time_info', 'location_info', 'personal_rights_info' ], true ) ? $group_key : '' );
    }

    protected function normalizeOwnerUsername( string $owner_username ) : string {
        $owner_username = strtolower( trim( $owner_username ) );
        return( preg_replace( '/[^a-z0-9_]/', '', $owner_username ) ?? '' );
    }

    protected function normalizeOwnerUsernames( array $owner_usernames ) : array {
        return( array_keys( $this->buildOwnerLookup( $owner_usernames ) ) );
    }

    protected function buildOwnerLookup( array $owner_usernames ) : array {
        $owner_lookup = [];
        foreach ( $owner_usernames as $owner_username ) {
            if ( ! is_string( $owner_username ) ) {
                continue;
            }
            $normalized_owner = $this->normalizeOwnerUsername( $owner_username );
            if ( $normalized_owner !== '' ) {
                $owner_lookup[$normalized_owner] = true;
            }
        }

        return( $owner_lookup );
    }

    protected function metadataOwnerAllowed( array $metadata, array $allowed_owner_lookup ) : bool {
        if ( empty( $allowed_owner_lookup ) ) {
            return( true );
        }

        return( isset( $allowed_owner_lookup[(string) ( $metadata['owner_username'] ?? '' )] ) );
    }

    protected function normalizeDateSegment( string $segment, int $length ) : string {
        $segment = preg_replace( '/[^0-9]/', '', $segment ) ?? '';
        if ( strlen( $segment ) !== $length ) {
            return( '' );
        }

        return( $segment );
    }

    protected function metadataMatchesContentContext( array $metadata, string $attached_entry_id, string $attached_draft_id, string $attachment_session_id ) : bool {
        if ( $attached_entry_id !== '' && (string) ( $metadata['attached_entry_id'] ?? '' ) === $attached_entry_id ) {
            return( true );
        }
        if ( $attached_draft_id !== '' && (string) ( $metadata['attached_draft_id'] ?? '' ) === $attached_draft_id ) {
            return( true );
        }
        if ( $attachment_session_id !== '' && (string) ( $metadata['attachment_session_id'] ?? '' ) === $attachment_session_id ) {
            return( true );
        }

        return( false );
    }

    protected function normalizeAttachmentSessionId( string $attachment_session_id ) : string {
        return( preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $attachment_session_id ) ) ?? '' );
    }

    protected function normalizeFilename( string $filename ) : string {
        return( basename( trim( $filename ) ) );
    }

}// TinyMashMediaAttachmentMetadataStore
