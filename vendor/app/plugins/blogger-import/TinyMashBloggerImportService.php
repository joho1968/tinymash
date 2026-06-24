<?php

use app\classes\TinyMashContentRepository;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;

class TinyMashBloggerImportService {

    protected const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';
    protected const BLOGGER_NAMESPACE = 'http://schemas.google.com/blogger/2018';
    protected const MAX_IMPORT_BATCH_SIZE = 100;

    protected string $workspace_directory;
    protected TinyMashContentRepository $content_repository;
    protected ?TinyMashMediaImportBridge $media_import_bridge;
    protected ?TinyMashImportLockService $import_lock_service;
    protected string $project_root;

    public function __construct( string $workspace_directory, TinyMashContentRepository $content_repository, ?TinyMashMediaImportBridge $media_import_bridge = null, string $project_root = '', ?TinyMashImportLockService $import_lock_service = null ) {
        $this->workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        $this->content_repository = $content_repository;
        $this->media_import_bridge = $media_import_bridge;
        $this->project_root = $project_root !== '' ? rtrim( $project_root, DIRECTORY_SEPARATOR ) : dirname( __DIR__, 2 );
        $this->import_lock_service = $import_lock_service;
    }

    public function isAvailable() : bool {
        return( class_exists( '\SimpleXMLElement' ) && function_exists( 'simplexml_load_string' ) );
    }

    public function createPreviewFromUpload( array $file, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, string $media_source_directory = '', bool $skip_unpublished = false ) : array {
        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
            throw new \RuntimeException( 'A Blogger export file is required.' );
        }

        $xml = @ file_get_contents( $file['tmp_name'] );
        if ( ! is_string( $xml ) || trim( $xml ) === '' ) {
            throw new \RuntimeException( 'The Blogger export file could not be read.' );
        }

        return( $this->createPreviewFromXml( $xml, $author_slug, $aggregate_to_root, $created_by_username, $replace_existing_posts, $media_source_directory, $skip_unpublished ) );
    }

    public function createPreviewFromXml( string $xml, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, string $media_source_directory = '', bool $skip_unpublished = false ) : array {
        $preview = $this->parseXml( $xml, $author_slug, $aggregate_to_root, $media_source_directory, $skip_unpublished );
        $preview['token'] = $this->generateToken();
        $preview['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        $preview['created_by_username'] = $this->normalizeSlug( $created_by_username !== '' ? $created_by_username : $author_slug );
        $preview['replace_existing_posts'] = $replace_existing_posts;
        $preview['skip_unpublished'] = $skip_unpublished;

        $workspace_directory = $this->getWorkspaceDirectory( $preview['token'] );
        if ( ! is_dir( $workspace_directory ) && ! @ mkdir( $workspace_directory, 0775, true ) && ! is_dir( $workspace_directory ) ) {
            throw new \RuntimeException( 'The Blogger import preview workspace could not be created.' );
        }

        $media_index = is_array( $preview['media_index'] ?? null ) ? $preview['media_index'] : null;
        unset( $preview['media_index'] );
        $this->writeJsonFile( $workspace_directory . DIRECTORY_SEPARATOR . 'preview.json', $preview );
        if ( is_array( $media_index ) && ! empty( $preview['media_summary']['configured'] ) ) {
            $this->writeJsonFile( $workspace_directory . DIRECTORY_SEPARATOR . 'media-index.json', $media_index );
        }
        return( $preview );
    }

    public function getPreview( string $token ) : ?array {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return( null );
        }

        $preview_filename = $this->getWorkspaceDirectory( $token ) . DIRECTORY_SEPARATOR . 'preview.json';
        if ( ! is_file( $preview_filename ) || ! is_readable( $preview_filename ) ) {
            return( null );
        }

        $preview_json = file_get_contents( $preview_filename );
        if ( ! is_string( $preview_json ) || trim( $preview_json ) === '' ) {
            return( null );
        }

        try {
            $preview = json_decode( $preview_json, true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( null );
        }

        return( is_array( $preview ) ? $preview : null );
    }

    public function getMediaIndex( string $token ) : array {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return( [] );
        }

        $index_filename = $this->getWorkspaceDirectory( $token ) . DIRECTORY_SEPARATOR . 'media-index.json';
        if ( ! is_file( $index_filename ) || ! is_readable( $index_filename ) ) {
            return( [] );
        }

        $index_json = file_get_contents( $index_filename );
        if ( ! is_string( $index_json ) || trim( $index_json ) === '' ) {
            return( [] );
        }

        try {
            $index = json_decode( $index_json, true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( [] );
        }

        return( is_array( $index ) ? $index : [] );
    }

    public function deletePreview( string $token ) : void {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return;
        }

        $preview = $this->getPreview( $token );
        if ( is_array( $preview ) ) {
            $this->releaseImportLock( (string) ( $preview['author_slug'] ?? '' ), $token );
        }

        $this->deleteDirectoryRecursively( $this->getWorkspaceDirectory( $token ) );
    }

    public function getImportState( string $token ) : ?array {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return( null );
        }

        $state_filename = $this->getImportStateFilename( $token );
        if ( ! is_file( $state_filename ) || ! is_readable( $state_filename ) ) {
            return( null );
        }

        $state_json = file_get_contents( $state_filename );
        if ( ! is_string( $state_json ) || trim( $state_json ) === '' ) {
            return( null );
        }

        try {
            $state = json_decode( $state_json, true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( null );
        }

        return( is_array( $state ) ? $state : null );
    }

    public function startImport( array $preview, int $batch_size = 10, bool $force_lock = false ) : array {
        $token = $this->normalizeToken( (string) ( $preview['token'] ?? '' ) );
        if ( $token === '' ) {
            throw new \RuntimeException( 'The Blogger import preview token is invalid.' );
        }

        $this->acquireImportLock(
            (string) ( $preview['author_slug'] ?? '' ),
            'blogger-import',
            $token,
            (string) ( $preview['created_by_username'] ?? '' ),
            $force_lock
        );

        $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
        $state = [
            'token' => $token,
            'author_slug' => (string) ( $preview['author_slug'] ?? '' ),
            'created_by_username' => (string) ( $preview['created_by_username'] ?? '' ),
            'status' => 'running',
            'replace_existing_posts' => ! empty( $preview['replace_existing_posts'] ),
            'existing_posts_deleted' => false,
            'batch_size' => max( 1, min( self::MAX_IMPORT_BATCH_SIZE, $batch_size ) ),
            'total_entries' => count( $entries ),
            'next_offset' => 0,
            'seen_keys' => [],
            'counts' => [
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'skipped_duplicate' => 0,
                'skipped_invalid' => 0,
                'deleted_existing_posts' => 0,
            ],
            'started_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'finished_at_utc' => '',
        ];

        $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
        return( $state );
    }

    public function importPreviewBatch( string $token, int $batch_size = 10, bool $force_lock = false ) : array {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            throw new \RuntimeException( 'The Blogger import preview token is invalid.' );
        }

        $preview = $this->getPreview( $token );
        if ( ! is_array( $preview ) ) {
            throw new \RuntimeException( 'The Blogger import preview is missing or expired.' );
        }
        $media_index = $this->getMediaIndex( $token );

        $state = $this->getImportState( $token );
        if ( ! is_array( $state ) ) {
            $state = $this->startImport( $preview, $batch_size, $force_lock );
        } else {
            $this->acquireImportLock(
                (string) ( $state['author_slug'] ?? '' ),
                'blogger-import',
                $token,
                (string) ( $state['created_by_username'] ?? $preview['created_by_username'] ?? '' ),
                $force_lock
            );
        }

        if ( (string) ( $state['status'] ?? '' ) === 'finished' ) {
            return( $this->buildImportBatchResult( $state, [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'deleted_existing_posts' => 0 ] ) );
        }

        try {
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $total_entries = count( $entries );
            $limit = max( 1, min( self::MAX_IMPORT_BATCH_SIZE, $batch_size ) );
            $state['status'] = 'running';
            $state['batch_size'] = $limit;
            unset( $state['error_message'] );
            $seen_keys = is_array( $state['seen_keys'] ?? null ) ? $state['seen_keys'] : [];
            $counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'deleted_existing_posts' => 0 ];
            $next_offset = max( 0, (int) ( $state['next_offset'] ?? 0 ) );
            $batch_counts = [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'deleted_existing_posts' => 0 ];

            if ( ! empty( $state['replace_existing_posts'] ) && empty( $state['existing_posts_deleted'] ) ) {
                $deleted = $this->content_repository->deletePostsByAuthorSlug( (string) ( $state['author_slug'] ?? '' ) );
                $deleted_count = (int) ( $deleted['deleted_posts'] ?? 0 );
                $counts['deleted_existing_posts'] += $deleted_count;
                $batch_counts['deleted_existing_posts'] += $deleted_count;
                $state['existing_posts_deleted'] = true;
            }

            for ( $offset = $next_offset; $offset < $total_entries && $batch_counts['processed'] < $limit; $offset++ ) {
                $state['next_offset'] = $offset + 1;
                $counts['processed']++;
                $batch_counts['processed']++;

                $entry = $entries[$offset] ?? null;
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $type = (string) ( $entry['entry_type'] ?? '' );
                $slug = (string) ( $entry['slug'] ?? '' );
                $scope_key = $type . ':' . $slug;
                if ( $type === '' || $slug === '' ) {
                    $counts['skipped']++;
                    $counts['skipped_invalid']++;
                    $batch_counts['skipped']++;
                    $batch_counts['skipped_invalid']++;
                    continue;
                }

                if ( isset( $seen_keys[$scope_key] ) ) {
                    $counts['skipped']++;
                    $counts['skipped_duplicate']++;
                    $batch_counts['skipped']++;
                    $batch_counts['skipped_duplicate']++;
                    continue;
                }
                $seen_keys[$scope_key] = true;

                try {
                    $attached_media_ids = [];
                    $entry = $this->applyImportedMediaToEntry( $entry, (string) ( $state['author_slug'] ?? '' ), $media_index, $attached_media_ids );
                    $saved_entry = $this->content_repository->importEditorEntry(
                        $entry,
                        (string) ( $entry['status'] ?? 'published' ),
                        [
                            'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                            'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                            'write_revision' => false,
                        ]
                    );
                    $this->attachImportedMediaToSavedEntry( (string) ( $state['author_slug'] ?? '' ), $saved_entry, $attached_media_ids );
                    $counts['imported']++;
                    $batch_counts['imported']++;
                } catch ( \InvalidArgumentException ) {
                    $counts['skipped']++;
                    $counts['skipped_duplicate']++;
                    $batch_counts['skipped']++;
                    $batch_counts['skipped_duplicate']++;
                }
            }

            $state['seen_keys'] = $seen_keys;
            $state['counts'] = $counts;
            $state['total_entries'] = $total_entries;
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );

            if ( (int) $state['next_offset'] >= $total_entries ) {
                $state['status'] = 'finished';
                $state['finished_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $this->releaseImportLock( (string) ( $state['author_slug'] ?? '' ), $token );
            }

            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            return( $this->buildImportBatchResult( $state, $batch_counts ) );
        } catch ( \Throwable $exception ) {
            $state['status'] = 'failed';
            $state['error_message'] = trim( $exception->getMessage() ) !== '' ? trim( $exception->getMessage() ) : 'The Blogger import batch failed.';
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            $this->releaseImportLock( (string) ( $state['author_slug'] ?? '' ), $token );
            throw $exception;
        }
    }

    public function importPreview( array $preview ) : array {
        $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
        $imported = [];
        $skipped = [];
        $seen = [];

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $type = (string) ( $entry['entry_type'] ?? '' );
            $slug = (string) ( $entry['slug'] ?? '' );
            $scope_key = $type . ':' . $slug;
            if ( $type === '' || $slug === '' ) {
                continue;
            }

            if ( isset( $seen[$scope_key] ) ) {
                $skipped[] = [
                    'title' => (string) ( $entry['title'] ?? $slug ),
                    'slug' => $slug,
                    'type' => $type,
                    'reason' => 'Duplicate slug in the Blogger export.',
                ];
                continue;
            }
            $seen[$scope_key] = true;

            try {
                $attached_media_ids = [];
                $entry = $this->applyImportedMediaToEntry( $entry, (string) ( $preview['author_slug'] ?? '' ), $this->getMediaIndex( (string) ( $preview['token'] ?? '' ) ), $attached_media_ids );
                $saved_entry = $this->content_repository->importEditorEntry(
                    $entry,
                    (string) ( $entry['status'] ?? 'published' ),
                    [
                        'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                        'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                        'write_revision' => false,
                    ]
                );
                $this->attachImportedMediaToSavedEntry( (string) ( $preview['author_slug'] ?? '' ), $saved_entry, $attached_media_ids );
                $imported[] = [
                    'id' => (string) ( $saved_entry['id'] ?? '' ),
                    'title' => (string) ( $saved_entry['title'] ?? $slug ),
                    'slug' => (string) ( $saved_entry['slug'] ?? $slug ),
                    'type' => (string) ( $saved_entry['type'] ?? $type ),
                    'status' => (string) ( $saved_entry['status'] ?? 'published' ),
                ];
            } catch ( \InvalidArgumentException $e ) {
                $reason = $e->getMessage() === 'duplicate_slug'
                    ? 'An entry with the same slug and type already exists in this author space.'
                    : trim( $e->getMessage() );
                $skipped[] = [
                    'title' => (string) ( $entry['title'] ?? $slug ),
                    'slug' => $slug,
                    'type' => $type,
                    'reason' => $reason,
                ];
            }
        }

        return(
            [
                'imported' => $imported,
                'skipped' => $skipped,
                'counts' => [
                    'imported' => count( $imported ),
                    'skipped' => count( $skipped ),
                ],
            ]
        );
    }

    protected function parseXml( string $xml, string $author_slug, bool $aggregate_to_root = false, string $media_source_directory = '', bool $skip_unpublished = false ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'The Blogger import runtime is not available. SimpleXML is required.' );
        }

        $author_slug = $this->normalizeSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \RuntimeException( 'A valid target author is required for Blogger import.' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $xml_feed = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NOCDATA );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );

        if ( ! $xml_feed instanceof \SimpleXMLElement ) {
            $message = ! empty( $errors[0]->message ) ? trim( $errors[0]->message ) : 'The Blogger export XML could not be parsed.';
            throw new \RuntimeException( $message );
        }

        $entries = [];
        $unpublished_entries = [];
        $seen_entry_keys = [];
        $adjusted_source_duplicates = 0;
        $adjusted_source_conflicts = [];
        $counts = [
            'posts' => 0,
            'pages' => 0,
            'drafts' => 0,
            'skipped_unpublished' => 0,
            'total' => 0,
        ];

        $atom_children = $xml_feed->children( self::ATOM_NAMESPACE );
        foreach ( $atom_children->entry as $feed_entry ) {
            $parsed_entry = $this->parseFeedEntry( $feed_entry, $author_slug, $aggregate_to_root );
            if ( ! is_array( $parsed_entry ) ) {
                continue;
            }

            $adjusted = false;
            $adjusted_conflict = [];
            $parsed_entry = $this->ensureUniqueSourceEntrySlug( $parsed_entry, $seen_entry_keys, $adjusted, $adjusted_conflict );
            if ( $adjusted ) {
                $adjusted_source_duplicates++;
                if ( ! empty( $adjusted_conflict ) ) {
                    $adjusted_source_conflicts[] = $adjusted_conflict;
                }
            }

            if ( $parsed_entry['status'] !== 'published' ) {
                $counts['drafts']++;
                $unpublished_entries[] = $parsed_entry;
                if ( $skip_unpublished ) {
                    $counts['skipped_unpublished']++;
                    continue;
                }
            }
            $entries[] = $parsed_entry;
            $counts['total']++;
            if ( $parsed_entry['entry_type'] === 'page' ) {
                $counts['pages']++;
            } else {
                $counts['posts']++;
            }
        }

        usort(
            $entries,
            static function( array $left, array $right ) : int {
                $left_published = (string) ( $left['published_at_utc'] ?? '' );
                $right_published = (string) ( $right['published_at_utc'] ?? '' );
                if ( $left_published !== $right_published ) {
                    return( strcmp( $left_published, $right_published ) );
                }

                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        $media_source_directory = $this->normalizeMediaSourceDirectory( $media_source_directory );
        $media_index = [];
        $media_summary = [
            'configured' => false,
            'directory' => '',
            'indexed_files' => 0,
            'indexed_urls' => 0,
            'referenced_images' => 0,
            'matched_images' => 0,
        ];
        if ( $media_source_directory !== '' ) {
            $media_index = $this->buildMediaIndex( $media_source_directory );
            $media_summary = $this->buildMediaSummary( $entries, $media_source_directory, $media_index );
        }

        return(
            [
                'author_slug' => $author_slug,
                'created_by_username' => '',
                'aggregate_to_root' => $aggregate_to_root,
                'media_source_directory' => $media_source_directory,
                'media_summary' => $media_summary,
                'media_index' => $media_index,
                'adjusted_source_duplicates' => $adjusted_source_duplicates,
                'adjusted_source_conflicts' => $adjusted_source_conflicts,
                'entries' => $entries,
                'unpublished_entries' => $unpublished_entries,
                'counts' => $counts,
            ]
        );
    }

    protected function parseFeedEntry( \SimpleXMLElement $feed_entry, string $author_slug, bool $aggregate_to_root ) : ?array {
        $atom = $feed_entry->children( self::ATOM_NAMESPACE );
        $blogger = $feed_entry->children( self::BLOGGER_NAMESPACE );

        $kind = $this->resolveEntryKind( $feed_entry, $atom, $blogger );
        if ( ! in_array( $kind, [ 'post', 'page' ], true ) ) {
            return( null );
        }

        $title = $this->normalizeText( (string) $atom->title );
        $content = $this->normalizeContent( (string) $atom->content );
        $summary = $this->normalizeSummary( (string) $atom->summary, $content );
        $alternate_url = $this->resolveAlternateUrl( $feed_entry, $atom, $blogger );
        $slug = $this->resolveSlug( $alternate_url, $title, $kind, (string) $atom->id, trim( (string) $blogger->filename ) );
        if ( $slug === '' || $content === '' ) {
            return( null );
        }

        $draft_state = $this->resolveDraftState( $feed_entry, $blogger );
        $published_at_utc = $this->normalizeTimestamp( (string) $atom->published );
        $updated_at_utc = $this->normalizeTimestamp( (string) $atom->updated );
        $created_at_utc = $this->normalizeTimestamp( (string) $blogger->created );
        $title = $title !== '' ? $title : ucfirst( $kind ) . ' ' . $slug;

        return(
            [
                'entry_type' => $kind,
                'scope' => 'author',
                'author_slug' => $author_slug,
                'slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'content' => $content,
                'status' => $draft_state ? 'unpublished' : 'published',
                'parent_slug' => '',
                'sort_order' => 0,
                'aggregate_to_root' => $kind === 'post' ? $aggregate_to_root : false,
                'sticky' => false,
                'show_in_navigation' => $kind === 'page',
                'featured_image_media_id' => '',
                'featured_image' => [],
                'published_at_utc' => $published_at_utc !== '' ? $published_at_utc : $created_at_utc,
                'updated_at_utc' => $updated_at_utc,
                'source_url' => $alternate_url,
                'source_id' => trim( (string) $atom->id ),
            ]
        );
    }

    protected function resolveEntryKind( \SimpleXMLElement $feed_entry, \SimpleXMLElement $atom, \SimpleXMLElement $blogger ) : string {
        $blogger_type = strtoupper( trim( (string) $blogger->type ) );
        if ( $blogger_type === 'POST' ) {
            return( 'post' );
        }
        if ( $blogger_type === 'PAGE' ) {
            return( 'page' );
        }
        if ( $blogger_type === 'COMMENT' ) {
            return( 'comment' );
        }

        foreach ( $feed_entry->category as $category ) {
            $term = trim( (string) ( $category['term'] ?? '' ) );
            if ( $term === '' ) {
                continue;
            }
            if ( str_contains( $term, '#post' ) ) {
                return( 'post' );
            }
            if ( str_contains( $term, '#page' ) ) {
                return( 'page' );
            }
            if ( str_contains( $term, '#comment' ) ) {
                return( 'comment' );
            }
        }

        return( '' );
    }

    protected function resolveDraftState( \SimpleXMLElement $feed_entry, \SimpleXMLElement $blogger ) : bool {
        $blogger_status = strtoupper( trim( (string) $blogger->status ) );
        if ( $blogger_status !== '' ) {
            return( $blogger_status !== 'LIVE' );
        }

        $app_children = $feed_entry->children( 'http://www.w3.org/2007/app' );
        if ( ! isset( $app_children->control ) ) {
            return( false );
        }

        $control_children = $app_children->control->children( 'http://www.w3.org/2007/app' );
        return( strtolower( trim( (string) ( $control_children->draft ?? '' ) ) ) === 'yes' );
    }

    protected function resolveAlternateUrl( \SimpleXMLElement $feed_entry, \SimpleXMLElement $atom, \SimpleXMLElement $blogger ) : string {
        foreach ( $atom->link as $link ) {
            $rel = trim( (string) ( $link['rel'] ?? '' ) );
            $href = trim( (string) ( $link['href'] ?? '' ) );
            if ( $rel === 'alternate' && $href !== '' ) {
                return( $href );
            }
        }

        $filename = trim( (string) $blogger->filename );
        if ( $filename !== '' ) {
            return( $filename );
        }

        return( '' );
    }

    protected function resolveSlug( string $alternate_url, string $title, string $kind, string $source_id, string $filename = '' ) : string {
        $slug = '';

        if ( $title !== '' ) {
            $slug = $title;
        }

        if ( $slug === '' && $filename !== '' ) {
            $slug = basename( trim( $filename ) );
            $slug = preg_replace( '/\.html?$/i', '', $slug ) ?? $slug;
        }

        if ( $slug === '' && $alternate_url !== '' ) {
            $path = (string) parse_url( $alternate_url, PHP_URL_PATH );
            $segments = array_values( array_filter( explode( '/', $path ), static fn( string $segment ) : bool => trim( $segment ) !== '' ) );
            if ( ! empty( $segments ) ) {
                $slug = trim( (string) end( $segments ) );
                $slug = preg_replace( '/\.html?$/i', '', $slug ) ?? $slug;
            }
        }

        if ( $slug === '' && $source_id !== '' ) {
            $slug = $kind . '-' . substr( sha1( $source_id ), 0, 10 );
        }

        return( $this->normalizeSlug( $slug ) );
    }

    protected function ensureUniqueSourceEntrySlug( array $entry, array &$seen_entry_keys, bool &$adjusted, array &$adjusted_conflict = [] ) : array {
        $adjusted = false;
        $adjusted_conflict = [];
        $entry_type = (string) ( $entry['entry_type'] ?? '' );
        $slug = (string) ( $entry['slug'] ?? '' );
        if ( $entry_type === '' || $slug === '' ) {
            return( $entry );
        }

        $scope_key = $entry_type . ':' . $slug;
        if ( ! isset( $seen_entry_keys[$scope_key] ) ) {
            $seen_entry_keys[$scope_key] = true;
            return( $entry );
        }

        $title_slug = $this->normalizeSlug( (string) ( $entry['title'] ?? '' ) );
        $base_slug = $title_slug !== '' ? $title_slug : $slug;
        $candidate_slug = $base_slug;
        $suffix = 2;

        while ( isset( $seen_entry_keys[$entry_type . ':' . $candidate_slug] ) ) {
            $candidate_slug = $base_slug . '-' . $suffix;
            $suffix++;
        }

        $adjusted_conflict = [
            'title' => (string) ( $entry['title'] ?? '' ),
            'entry_type' => $entry_type,
            'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
            'source_url' => (string) ( $entry['source_url'] ?? '' ),
            'source_id' => (string) ( $entry['source_id'] ?? '' ),
            'original_slug' => $slug,
            'adjusted_slug' => $candidate_slug,
        ];
        $entry['slug'] = $candidate_slug;
        $seen_entry_keys[$entry_type . ':' . $candidate_slug] = true;
        $adjusted = true;
        return( $entry );
    }

    protected function normalizeSummary( string $summary, string $content ) : string {
        $summary = $this->stripMarkupForSummary( $summary );
        if ( $summary !== '' ) {
            return( $summary );
        }

        $content_text = $this->stripMarkupForSummary( $content );
        if ( $content_text === '' ) {
            return( '' );
        }

        $words = preg_split( '/\s+/u', $content_text ) ?: [];
        $words = array_values( array_filter( $words, static fn( string $word ) : bool => $word !== '' ) );
        if ( count( $words ) <= 40 ) {
            return( implode( ' ', $words ) );
        }

        return( implode( ' ', array_slice( $words, 0, 40 ) ) . '…' );
    }

    protected function stripMarkupForSummary( string $content ) : string {
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = preg_replace( '/!\[([^\]]*)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = strip_tags( $content );

        return( $this->normalizeText( $content ) );
    }

    protected function normalizeContent( string $content ) : string {
        $content = trim( $content );
        if ( $content === '' ) {
            return( '' );
        }

        $content = $this->convertBloggerImageHtmlToMarkdown( $content );
        $content = str_replace( "\r\n", "\n", $content );
        $content = str_replace( "\r", "\n", $content );
        $content = preg_replace_callback(
            '#(?:<br\s*/?>\s*)+#i',
            static function( array $matches ) : string {
                $break_count = preg_match_all( '#<br\s*/?>#i', (string) ( $matches[0] ?? '' ) );
                return( $break_count >= 2 ? "\n\n" : "\n" );
            },
            $content
        ) ?? $content;
        $content = preg_replace( '#</(p|div|section|article|blockquote|li|ul|ol|h[1-6])>#i', "\n\n", $content ) ?? $content;
        $content = preg_replace( '#<(p|div|section|article|blockquote|li|ul|ol|h[1-6])\b[^>]*>#i', '', $content ) ?? $content;
        $content = preg_replace( "/\n{3,}/", "\n\n", $content ) ?? $content;
        return( trim( $content ) );
    }

    protected function convertBloggerImageHtmlToMarkdown( string $content ) : string {
        if ( $content === '' || ! str_contains( strtolower( $content ), '<img' ) ) {
            return( $content );
        }

        $content = preg_replace_callback(
            '#<table\b[^>]*\bclass=["\'][^"\']*tr-caption-container[^"\']*["\'][^>]*>.*?</table>#is',
            function( array $matches ) : string {
                $markdown = $this->convertBloggerImageBlockToMarkdown( (string) ( $matches[0] ?? '' ) );
                return( $markdown !== '' ? "\n\n" . $markdown . "\n\n" : '' );
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '#<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>\s*<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>\s*</a>#is',
            function( array $matches ) : string {
                $href = html_entity_decode( (string) ( $matches[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $src = html_entity_decode( (string) ( $matches[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $alt = $this->extractImageAltFromHtml( (string) ( $matches[0] ?? '' ) );
                return( $this->buildMarkdownImage( $src, $alt, $href ) );
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '#<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#is',
            function( array $matches ) : string {
                $src = html_entity_decode( (string) ( $matches[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $alt = $this->extractImageAltFromHtml( (string) ( $matches[0] ?? '' ) );
                return( $this->buildMarkdownImage( $src, $alt ) );
            },
            $content
        ) ?? $content;

        return( $content );
    }

    protected function convertBloggerImageBlockToMarkdown( string $html ) : string {
        if ( $html === '' ) {
            return( '' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><body><div id="tm-blogger-image-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );
        if ( ! $loaded ) {
            return( '' );
        }

        $root = $document->getElementById( 'tm-blogger-image-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( '' );
        }

        $image = $root->getElementsByTagName( 'img' )->item( 0 );
        if ( ! $image instanceof \DOMElement ) {
            return( '' );
        }

        $src = html_entity_decode( (string) $image->getAttribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $alt = html_entity_decode( (string) $image->getAttribute( 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $href = '';
        $parent = $image->parentNode;
        if ( $parent instanceof \DOMElement && strtolower( $parent->tagName ) === 'a' ) {
            $href = html_entity_decode( (string) $parent->getAttribute( 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        $caption = '';
        foreach ( $root->getElementsByTagName( 'td' ) as $cell ) {
            if ( ! $cell instanceof \DOMElement ) {
                continue;
            }
            if ( str_contains( ' ' . strtolower( $cell->getAttribute( 'class' ) ) . ' ', ' tr-caption ' ) ) {
                $caption = $this->normalizeText( html_entity_decode( $cell->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                break;
            }
        }

        $markdown = $this->buildMarkdownImage( $src, $alt, $href );
        if ( $markdown === '' ) {
            return( '' );
        }

        if ( $caption !== '' ) {
            $markdown .= "\n\n" . $caption;
        }

        return( $markdown );
    }

    protected function extractImageAltFromHtml( string $html ) : string {
        if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $html, $matches ) === 1 ) {
            return( html_entity_decode( (string) ( $matches[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        return( '' );
    }

    protected function buildMarkdownImage( string $src, string $alt_text = '', string $href = '' ) : string {
        $src = function_exists( 'mb_trim' ) ? mb_trim( $src ) : trim( $src );
        $alt_text = $this->normalizeText( $alt_text );
        $href = function_exists( 'mb_trim' ) ? mb_trim( $href ) : trim( $href );
        if ( $src === '' ) {
            return( '' );
        }

        $escaped_alt = str_replace( [ '[', ']' ], [ '\[', '\]' ], $alt_text );
        $image_markdown = '![' . $escaped_alt . '](' . $src . ')';
        if ( $href !== '' && $href !== $src ) {
            return( '[' . $image_markdown . '](' . $href . ')' );
        }

        return( $image_markdown );
    }

    protected function normalizeText( string $text ) : string {
        $text = function_exists( 'mb_trim' ) ? mb_trim( $text ) : trim( $text );
        if ( $text === '' ) {
            return( '' );
        }

        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
        return( function_exists( 'mb_trim' ) ? mb_trim( $text ) : trim( $text ) );
    }

    protected function normalizeTimestamp( string $timestamp ) : string {
        $timestamp = trim( $timestamp );
        if ( $timestamp === '' ) {
            return( '' );
        }

        try {
            $normalized = new \DateTimeImmutable( $timestamp );
        } catch ( \Throwable ) {
            return( '' );
        }

        return( $normalized->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) );
    }

    protected function normalizeSlug( string $value ) : string {
        return( $this->content_repository->normalizeEditorSlug( $value ) );
    }

    protected function normalizeMediaSourceDirectory( string $directory ) : string {
        $directory = function_exists( 'mb_trim' ) ? mb_trim( $directory ) : trim( $directory );
        if ( $directory === '' ) {
            return( '' );
        }

        $candidate_directory = $directory;
        if ( preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', $candidate_directory ) !== 1 ) {
            $candidate_directory = $this->project_root . DIRECTORY_SEPARATOR . $candidate_directory;
        }

        $resolved_directory = realpath( $candidate_directory );
        if ( $resolved_directory === false || ! is_dir( $resolved_directory ) || ! is_readable( $resolved_directory ) ) {
            throw new \RuntimeException( 'The Blogger media directory could not be read.' );
        }

        $normalized_directory = rtrim( str_replace( '\\', '/', $resolved_directory ), '/' );
        $normalized_directory_with_slash = $normalized_directory . '/';
        $allowed_roots = [
            $this->project_root . DIRECTORY_SEPARATOR . 'public',
            $this->project_root . DIRECTORY_SEPARATOR . 'app',
            $this->project_root . DIRECTORY_SEPARATOR . 'vendor',
            $this->project_root . DIRECTORY_SEPARATOR . 'data',
            $this->project_root . DIRECTORY_SEPARATOR . '_cache',
            $this->project_root . DIRECTORY_SEPARATOR . 'logs',
            $this->project_root . DIRECTORY_SEPARATOR . 'tmp',
            $this->project_root . DIRECTORY_SEPARATOR . 'plugins',
            $this->project_root . DIRECTORY_SEPARATOR . 'themes',
            $this->project_root . DIRECTORY_SEPARATOR . 'users',
            $this->project_root . DIRECTORY_SEPARATOR . 'help',
        ];

        $is_allowed = false;
        foreach ( $allowed_roots as $allowed_root ) {
            $normalized_allowed_root = rtrim( str_replace( '\\', '/', $allowed_root ), '/' );
            if ( $normalized_allowed_root === '' ) {
                continue;
            }

            $normalized_allowed_root_with_slash = $normalized_allowed_root . '/';
            if ( $normalized_directory === $normalized_allowed_root || str_starts_with( $normalized_directory_with_slash, $normalized_allowed_root_with_slash ) ) {
                $is_allowed = true;
                break;
            }
        }

        if ( ! $is_allowed ) {
            throw new \RuntimeException( 'The Blogger media directory must be inside an allowed tinymash project directory.' );
        }

        return( $resolved_directory );
    }

    protected function buildMediaIndex( string $media_source_directory ) : array {
        $records = [];
        $image_files = [];
        $sidecar_files = [];
        $allowed_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $media_source_directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
                continue;
            }

            $path = $file_info->getPathname();
            $filename = $file_info->getFilename();
            $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
            if ( in_array( $extension, $allowed_extensions, true ) ) {
                $record = [
                    'path' => $path,
                    'filename' => $filename,
                    'basename' => strtolower( $filename ),
                    'basename_without_extension' => strtolower( pathinfo( $filename, PATHINFO_FILENAME ) ),
                    'source_key' => 'blogger-file:' . sha1( $path ),
                ];
                $records[$path] = $record;
                $image_files[] = $record;
                continue;
            }

            if ( $extension === 'json' ) {
                $sidecar_files[] = $path;
            }
        }

        usort(
            $image_files,
            static fn( array $left, array $right ) : int => strcmp( (string) ( $left['path'] ?? '' ), (string) ( $right['path'] ?? '' ) )
        );

        $by_basename = [];
        foreach ( $image_files as $record ) {
            $basename = (string) ( $record['basename'] ?? '' );
            if ( $basename !== '' && ! isset( $by_basename[$basename] ) ) {
                $by_basename[$basename] = $record;
            }
        }

        $url_map = [];
        foreach ( $sidecar_files as $sidecar_path ) {
            $sidecar_payload = $this->readSidecarPayload( $sidecar_path );
            $matched_record = $this->matchSidecarToImageRecord( $sidecar_path, $image_files, $sidecar_payload );
            if ( ! is_array( $matched_record ) ) {
                continue;
            }

            foreach ( $this->extractUrlsFromSidecar( $sidecar_payload ) as $url ) {
                $normalized_url = $this->normalizeMediaSourceUrl( $url );
                if ( $normalized_url === '' || isset( $url_map[$normalized_url] ) ) {
                    continue;
                }
                $url_map[$normalized_url] = $matched_record;
            }
        }

        return(
            [
                'directory' => $media_source_directory,
                'by_basename' => $by_basename,
                'by_url' => $url_map,
                'counts' => [
                    'indexed_files' => count( $image_files ),
                    'indexed_urls' => count( $url_map ),
                ],
            ]
        );
    }

    protected function buildMediaSummary( array $entries, string $media_source_directory, array $media_index ) : array {
        $referenced_images = 0;
        $matched_images = 0;

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            foreach ( $this->extractImageUrlsFromContent( (string) ( $entry['content'] ?? '' ) ) as $source_url ) {
                $referenced_images++;
                if ( is_array( $this->resolveMediaRecordForSourceUrl( $source_url, $media_index ) ) ) {
                    $matched_images++;
                }
            }
        }

        return(
            [
                'configured' => true,
                'directory' => $media_source_directory,
                'indexed_files' => (int) ( $media_index['counts']['indexed_files'] ?? 0 ),
                'indexed_urls' => (int) ( $media_index['counts']['indexed_urls'] ?? 0 ),
                'referenced_images' => $referenced_images,
                'matched_images' => $matched_images,
            ]
        );
    }

    protected function extractImageUrlsFromContent( string $content ) : array {
        if ( $content === '' ) {
            return( [] );
        }

        preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $html_matches );
        preg_match_all( '/!\[[^\]]*\]\((https?:\/\/[^\s)]+)\)/i', $content, $markdown_matches );
        $urls = array_merge(
            is_array( $html_matches[1] ?? null ) ? $html_matches[1] : [],
            is_array( $markdown_matches[1] ?? null ) ? $markdown_matches[1] : []
        );
        $normalized_urls = [];
        foreach ( $urls as $url ) {
            if ( ! is_string( $url ) ) {
                continue;
            }
            $normalized_url = $this->normalizeMediaSourceUrl( $url );
            if ( $normalized_url === '' || in_array( $normalized_url, $normalized_urls, true ) ) {
                continue;
            }
            $normalized_urls[] = $normalized_url;
        }

        return( $normalized_urls );
    }

    protected function matchSidecarToImageRecord( string $sidecar_path, array $image_files, array $sidecar_payload = [] ) : ?array {
        $sidecar_filename = basename( $sidecar_path );
        $candidates = [
            strtolower( preg_replace( '/\.json$/i', '', $sidecar_filename ) ?? '' ),
            strtolower( pathinfo( $sidecar_filename, PATHINFO_FILENAME ) ),
            strtolower( trim( (string) ( $sidecar_payload['filename'] ?? '' ) ) ),
            strtolower( pathinfo( (string) ( $sidecar_payload['filename'] ?? '' ), PATHINFO_FILENAME ) ),
        ];
        $candidates = array_values( array_filter( array_unique( $candidates ), static fn( string $candidate ) : bool => $candidate !== '' ) );

        foreach ( $image_files as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $filename = strtolower( (string) ( $record['filename'] ?? '' ) );
            $basename_without_extension = strtolower( (string) ( $record['basename_without_extension'] ?? '' ) );
            if ( in_array( $filename, $candidates, true ) || in_array( $basename_without_extension, $candidates, true ) ) {
                return( $record );
            }
        }

        return( null );
    }

    protected function extractUrlsFromSidecar( array $payload ) : array {
        if ( empty( $payload ) ) {
            return( [] );
        }

        $urls = [];
        $walker = function( mixed $value ) use ( &$walker, &$urls ) : void {
            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    $walker( $item );
                }
                return;
            }
            if ( ! is_string( $value ) ) {
                return;
            }
            $normalized_url = $this->normalizeMediaSourceUrl( $value );
            if ( $normalized_url === '' || in_array( $normalized_url, $urls, true ) ) {
                return;
            }
            $urls[] = $normalized_url;
        };
        $walker( $payload );

        return( $urls );
    }

    protected function readSidecarPayload( string $sidecar_path ) : array {
        $sidecar_json = file_get_contents( $sidecar_path );
        if ( ! is_string( $sidecar_json ) || trim( $sidecar_json ) === '' ) {
            return( [] );
        }

        try {
            $payload = json_decode( $sidecar_json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            return( [] );
        }

        return( is_array( $payload ) ? $payload : [] );
    }

    protected function normalizeMediaSourceUrl( string $source_url ) : string {
        $source_url = function_exists( 'mb_trim' ) ? mb_trim( html_entity_decode( $source_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : trim( html_entity_decode( $source_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $source_url === '' || preg_match( '#^https?://#i', $source_url ) !== 1 ) {
            return( '' );
        }

        return( $source_url );
    }

    protected function resolveMediaRecordForSourceUrl( string $source_url, array $media_index ) : ?array {
        $normalized_url = $this->normalizeMediaSourceUrl( $source_url );
        if ( $normalized_url === '' ) {
            return( null );
        }

        $by_url = is_array( $media_index['by_url'] ?? null ) ? $media_index['by_url'] : [];
        if ( isset( $by_url[$normalized_url] ) && is_array( $by_url[$normalized_url] ) ) {
            return( $by_url[$normalized_url] );
        }

        $path = (string) parse_url( $normalized_url, PHP_URL_PATH );
        $basename = strtolower( basename( $path ) );
        if ( $basename === '' ) {
            return( null );
        }

        $by_basename = is_array( $media_index['by_basename'] ?? null ) ? $media_index['by_basename'] : [];
        $record = $by_basename[$basename] ?? null;
        return( is_array( $record ) ? $record : null );
    }

    protected function getAllowedRemoteMediaHosts() : array {
        return(
            [
                'blogger.googleusercontent.com',
                '*.googleusercontent.com',
                '*.bp.blogspot.com',
                '*.blogspot.com',
            ]
        );
    }

    protected function applyImportedMediaToEntry( array $entry, string $author_slug, array $media_index, array &$attached_media_ids = [] ) : array {
        if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
            return( $entry );
        }
        if ( empty( $entry['content'] ) || ! is_string( $entry['content'] ) ) {
            return( $entry );
        }

        $content = (string) $entry['content'];
        $imported_media = [];
        $first_media = null;

        if ( str_contains( $content, '<img' ) ) {
            $content = $this->localizeHtmlImageContent( $content, $author_slug, $media_index, $attached_media_ids, $imported_media, $first_media );
        }

        if ( str_contains( $content, '![' ) ) {
            $content = $this->localizeMarkdownImageContent( $content, $author_slug, $media_index, $attached_media_ids, $imported_media, $first_media );
        }

        $entry['content'] = trim( $content );
        return( $entry );
    }

    protected function localizeHtmlImageContent( string $content, string $author_slug, array $media_index, array &$attached_media_ids, array &$imported_media, ?array &$first_media ) : string {
        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $html = '<!DOCTYPE html><html><body><div id="tm-import-root">' . $content . '</div></body></html>';
        $loaded = $document->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );
        if ( ! $loaded ) {
            return( $entry );
        }

        $root = $document->getElementById( 'tm-import-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( $content );
        }

        foreach ( iterator_to_array( $document->getElementsByTagName( 'img' ) ) as $image ) {
            if ( ! $image instanceof \DOMElement ) {
                continue;
            }

            $source_url = $this->normalizeMediaSourceUrl( (string) $image->getAttribute( 'src' ) );
            if ( $source_url === '' ) {
                continue;
            }

            $resolved_media = $this->resolveImportedMediaForSourceUrl(
                $source_url,
                $author_slug,
                $media_index,
                [
                    'alt_text' => (string) $image->getAttribute( 'alt' ),
                ],
                $imported_media
            );
            if ( ! is_array( $resolved_media ) ) {
                continue;
            }

            $resolved_media_id = trim( (string) ( $resolved_media['media_id'] ?? '' ) );
            if ( $resolved_media_id !== '' ) {
                $attached_media_ids[$resolved_media_id] = $resolved_media_id;
            }

            $image->setAttribute( 'src', (string) ( $resolved_media['url'] ?? $source_url ) );
            $alt_text = function_exists( 'mb_trim' ) ? mb_trim( (string) $image->getAttribute( 'alt' ) ) : trim( (string) $image->getAttribute( 'alt' ) );
            if ( $alt_text === '' && ! empty( $resolved_media['alt_text'] ) ) {
                $image->setAttribute( 'alt', (string) $resolved_media['alt_text'] );
            }

            if ( ! is_array( $first_media ) ) {
                $first_media = $resolved_media;
            }

            $parent = $image->parentNode;
            if ( $parent instanceof \DOMElement && strtolower( $parent->tagName ) === 'a' ) {
                $href_url = $this->normalizeMediaSourceUrl( (string) $parent->getAttribute( 'href' ) );
                if ( $href_url !== '' ) {
                    $resolved_href_media = $this->resolveImportedMediaForSourceUrl( $href_url, $author_slug, $media_index, [], $imported_media );
                    if ( is_array( $resolved_href_media ) && ! empty( $resolved_href_media['url'] ) ) {
                        $parent->setAttribute( 'href', (string) $resolved_href_media['url'] );
                    }
                }
            }
        }

        $rewritten_content = '';
        foreach ( $root->childNodes as $child_node ) {
            $rewritten_content .= $document->saveHTML( $child_node );
        }
        return( trim( $rewritten_content ) );
    }

    protected function localizeMarkdownImageContent( string $content, string $author_slug, array $media_index, array &$attached_media_ids, array &$imported_media, ?array &$first_media ) : string {
        $content = preg_replace_callback(
            '/\[\!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)\]\((https?:\/\/[^\s)]+)\)/i',
            function( array $matches ) use ( $author_slug, $media_index, &$attached_media_ids, &$imported_media, &$first_media ) : string {
                $alt_text = html_entity_decode( (string) ( $matches[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $image_url = $this->normalizeMediaSourceUrl( (string) ( $matches[2] ?? '' ) );
                $href_url = $this->normalizeMediaSourceUrl( (string) ( $matches[3] ?? '' ) );
                if ( $image_url === '' ) {
                    return( (string) ( $matches[0] ?? '' ) );
                }

                $resolved_media = $this->resolveImportedMediaForSourceUrl(
                    $image_url,
                    $author_slug,
                    $media_index,
                    [ 'alt_text' => $alt_text ],
                    $imported_media
                );
                if ( ! is_array( $resolved_media ) || empty( $resolved_media['url'] ) ) {
                    return( (string) ( $matches[0] ?? '' ) );
                }

                $resolved_media_id = trim( (string) ( $resolved_media['media_id'] ?? '' ) );
                if ( $resolved_media_id !== '' ) {
                    $attached_media_ids[$resolved_media_id] = $resolved_media_id;
                }
                if ( ! is_array( $first_media ) ) {
                    $first_media = $resolved_media;
                }

                if ( $href_url !== '' ) {
                    $resolved_href_media = $this->resolveImportedMediaForSourceUrl( $href_url, $author_slug, $media_index, [], $imported_media );
                    if ( is_array( $resolved_href_media ) && ! empty( $resolved_href_media['url'] ) ) {
                        $href_url = (string) $resolved_href_media['url'];
                    }
                }

                $image_markdown = '![' . str_replace( [ '[', ']' ], [ '\[', '\]' ], $this->normalizeText( $alt_text ) ) . '](' . (string) $resolved_media['url'] . ')';
                if ( $href_url !== '' && $href_url !== (string) $resolved_media['url'] ) {
                    return( '[' . $image_markdown . '](' . $href_url . ')' );
                }

                return( $image_markdown );
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/i',
            function( array $matches ) use ( $author_slug, $media_index, &$attached_media_ids, &$imported_media, &$first_media ) : string {
                $alt_text = html_entity_decode( (string) ( $matches[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $image_url = $this->normalizeMediaSourceUrl( (string) ( $matches[2] ?? '' ) );
                if ( $image_url === '' ) {
                    return( (string) ( $matches[0] ?? '' ) );
                }

                $resolved_media = $this->resolveImportedMediaForSourceUrl(
                    $image_url,
                    $author_slug,
                    $media_index,
                    [ 'alt_text' => $alt_text ],
                    $imported_media
                );
                if ( ! is_array( $resolved_media ) || empty( $resolved_media['url'] ) ) {
                    return( (string) ( $matches[0] ?? '' ) );
                }

                $resolved_media_id = trim( (string) ( $resolved_media['media_id'] ?? '' ) );
                if ( $resolved_media_id !== '' ) {
                    $attached_media_ids[$resolved_media_id] = $resolved_media_id;
                }
                if ( ! is_array( $first_media ) ) {
                    $first_media = $resolved_media;
                }

                return( '![' . str_replace( [ '[', ']' ], [ '\[', '\]' ], $this->normalizeText( $alt_text ) ) . '](' . (string) $resolved_media['url'] . ')' );
            },
            $content
        ) ?? $content;

        return( $content );
    }

    protected function attachImportedMediaToSavedEntry( string $author_slug, array $saved_entry, array $attached_media_ids ) : void {
        if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
            return;
        }

        $author_slug = $this->normalizeSlug( $author_slug );
        $entry_id = trim( (string) ( $saved_entry['id'] ?? '' ) );
        if ( $author_slug === '' || $entry_id === '' || empty( $attached_media_ids ) ) {
            return;
        }

        $this->media_import_bridge->attachMediaToEntry( $author_slug, $entry_id, array_values( $attached_media_ids ) );
    }

    protected function resolveImportedMediaForSourceUrl( string $source_url, string $author_slug, array $media_index, array $options, array &$imported_media ) : ?array {
        $record = $this->resolveMediaRecordForSourceUrl( $source_url, $media_index );
        $source_key = is_array( $record )
            ? trim( (string) ( $record['source_key'] ?? '' ) )
            : 'url:' . sha1( $source_url );
        if ( $source_key !== '' && isset( $imported_media[$source_key] ) && is_array( $imported_media[$source_key] ) ) {
            return( $imported_media[$source_key] );
        }

        try {
            if ( is_array( $record ) ) {
                $import_result = $this->media_import_bridge->importLocalFile(
                    'blogger-import',
                    $author_slug,
                    (string) ( $record['path'] ?? '' ),
                    [
                        'source_key' => $source_key,
                        'source_url' => $source_url,
                        'original_filename' => (string) ( $record['filename'] ?? basename( (string) ( $record['path'] ?? '' ) ) ),
                        'alt_text' => (string) ( $options['alt_text'] ?? '' ),
                    ]
                );
            } else {
                $import_result = $this->media_import_bridge->importRemoteUrl(
                    'blogger-import',
                    $author_slug,
                    $source_url,
                    [
                        'source_key' => $source_key,
                        'source_url' => $source_url,
                        'original_filename' => basename( (string) parse_url( $source_url, PHP_URL_PATH ) ),
                        'alt_text' => (string) ( $options['alt_text'] ?? '' ),
                        'allowed_hosts' => $this->getAllowedRemoteMediaHosts(),
                    ]
                );
            }
        } catch ( \Throwable ) {
            return( null );
        }
        $media = is_array( $import_result['media'] ?? null ) ? $import_result['media'] : null;
        if ( ! is_array( $media ) ) {
            return( null );
        }

        if ( $source_key !== '' ) {
            $imported_media[$source_key] = $media;
        }

        return( $media );
    }

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'The Blogger import preview directory could not be created.' );
        }

        $encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        if ( @ file_put_contents( $filename, $encoded . PHP_EOL, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'The Blogger import preview could not be written.' );
        }
    }

    protected function generateToken() : string {
        return( 'blogger_' . gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 ) );
    }

    protected function normalizeToken( string $token ) : string {
        $token = strtolower( trim( $token ) );
        return( preg_replace( '/[^a-z0-9_]+/', '', $token ) ?? '' );
    }

    protected function getWorkspaceDirectory( string $token ) : string {
        return( $this->workspace_directory . DIRECTORY_SEPARATOR . $this->normalizeToken( $token ) );
    }

    protected function getImportStateFilename( string $token ) : string {
        return( $this->getWorkspaceDirectory( $token ) . DIRECTORY_SEPARATOR . 'import-state.json' );
    }

    protected function buildImportBatchResult( array $state, array $batch_counts ) : array {
        $total_entries = max( 0, (int) ( $state['total_entries'] ?? 0 ) );
        $processed_entries = min( $total_entries, max( 0, (int) ( $state['next_offset'] ?? 0 ) ) );
        $done = (string) ( $state['status'] ?? '' ) === 'finished';

        return(
            [
                'done' => $done,
                'status' => $done ? 'finished' : 'running',
                'author_slug' => (string) ( $state['author_slug'] ?? '' ),
                'counts' => [
                    'processed' => max( 0, (int) ( $state['counts']['processed'] ?? $processed_entries ) ),
                    'imported' => max( 0, (int) ( $state['counts']['imported'] ?? 0 ) ),
                    'skipped' => max( 0, (int) ( $state['counts']['skipped'] ?? 0 ) ),
                    'skipped_duplicate' => max( 0, (int) ( $state['counts']['skipped_duplicate'] ?? 0 ) ),
                    'skipped_invalid' => max( 0, (int) ( $state['counts']['skipped_invalid'] ?? 0 ) ),
                    'deleted_existing_posts' => max( 0, (int) ( $state['counts']['deleted_existing_posts'] ?? 0 ) ),
                    'total' => $total_entries,
                ],
                'batch' => [
                    'processed' => max( 0, (int) ( $batch_counts['processed'] ?? 0 ) ),
                    'imported' => max( 0, (int) ( $batch_counts['imported'] ?? 0 ) ),
                    'skipped' => max( 0, (int) ( $batch_counts['skipped'] ?? 0 ) ),
                    'skipped_duplicate' => max( 0, (int) ( $batch_counts['skipped_duplicate'] ?? 0 ) ),
                    'skipped_invalid' => max( 0, (int) ( $batch_counts['skipped_invalid'] ?? 0 ) ),
                    'deleted_existing_posts' => max( 0, (int) ( $batch_counts['deleted_existing_posts'] ?? 0 ) ),
                ],
                'progress_percent' => $total_entries > 0 ? (int) floor( ( $processed_entries / $total_entries ) * 100 ) : 100,
                'updated_at_utc' => (string) ( $state['updated_at_utc'] ?? '' ),
                'finished_at_utc' => (string) ( $state['finished_at_utc'] ?? '' ),
            ]
        );
    }

    protected function acquireImportLock( string $author_slug, string $importer_key, string $token, string $created_by_username = '', bool $force_lock = false ) : void {
        if ( ! $this->import_lock_service instanceof TinyMashImportLockService ) {
            return;
        }

        $record = $this->import_lock_service->acquireAuthorLock( $author_slug, $importer_key, $token, $created_by_username, $force_lock );
        if ( ! is_array( $record ) ) {
            return;
        }

        if ( (string) ( $record['token'] ?? '' ) !== $token ) {
            $created_at_utc = (string) ( $record['created_at_utc'] ?? '' );
            $created_display = $created_at_utc !== '' ? $created_at_utc : 'unknown time';
            $stale_label = ! empty( $record['is_stale'] ) ? ' The existing lock is stale and can be overridden.' : '';
            throw new \RuntimeException( 'Another import is already running or paused for this author space (created ' . $created_display . ').' . $stale_label );
        }
    }

    protected function releaseImportLock( string $author_slug, string $token = '' ) : void {
        if ( ! $this->import_lock_service instanceof TinyMashImportLockService ) {
            return;
        }

        $this->import_lock_service->releaseAuthorLock( $author_slug, $token );
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        foreach ( scandir( $directory ) ?: [] as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if ( is_dir( $path ) ) {
                $this->deleteDirectoryRecursively( $path );
                continue;
            }

            @ unlink( $path );
        }

        @ rmdir( $directory );
    }
}
