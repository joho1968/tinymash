<?php

use app\classes\TinyMashContentRepository;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;

class TinyMashBluditImportService {

    protected const MAX_IMPORT_BATCH_SIZE = 100;

    protected string $workspace_directory;
    protected TinyMashContentRepository $content_repository;
    protected ?TinyMashMediaImportBridge $media_import_bridge;
    protected ?TinyMashImportLockService $import_lock_service;
    protected string $project_root;

    public function __construct(
        string $workspace_directory,
        TinyMashContentRepository $content_repository,
        ?TinyMashMediaImportBridge $media_import_bridge = null,
        string $project_root = '',
        ?TinyMashImportLockService $import_lock_service = null
    ) {
        $this->workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        $this->content_repository = $content_repository;
        $this->media_import_bridge = $media_import_bridge;
        $this->project_root = $project_root !== '' ? rtrim( $project_root, DIRECTORY_SEPARATOR ) : dirname( __DIR__, 2 );
        $this->import_lock_service = $import_lock_service;
    }

    public function isAvailable() : bool {
        return( function_exists( 'json_decode' ) && class_exists( DOMDocument::class ) );
    }

    public function createPreviewFromDirectory(
        string $site_directory,
        string $target_scope,
        string $target_author_slug = '',
        string $created_by_username = '',
        bool $replace_existing_posts = false,
        bool $replace_existing_pages = false,
        bool $skip_unpublished = false,
        bool $require_project_local_directory = false
    ) : array {
        $preview = $this->parseSiteDirectory(
            $site_directory,
            $target_scope,
            $target_author_slug,
            $skip_unpublished,
            $require_project_local_directory
        );
        $preview['token'] = $this->generateToken();
        $preview['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        $preview['created_by_username'] = $this->normalizeSlug( $created_by_username !== '' ? $created_by_username : ( $target_scope === 'author' ? $target_author_slug : 'root' ) );
        $preview['replace_existing_posts'] = $replace_existing_posts;
        $preview['replace_existing_pages'] = $replace_existing_pages;
        $preview['skip_unpublished'] = $skip_unpublished;

        $workspace_directory = $this->getWorkspaceDirectory( (string) $preview['token'] );
        if ( ! is_dir( $workspace_directory ) && ! @mkdir( $workspace_directory, 0775, true ) && ! is_dir( $workspace_directory ) ) {
            throw new \RuntimeException( 'The Bludit import preview workspace could not be created.' );
        }

        $this->writeJsonFile( $workspace_directory . DIRECTORY_SEPARATOR . 'preview.json', $preview );
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

    public function deletePreview( string $token ) : void {
        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return;
        }

        $preview = $this->getPreview( $token );
        if ( is_array( $preview ) ) {
            $this->releaseImportLock( $this->getTargetLockKey( $preview ), $token );
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
            throw new \RuntimeException( 'The Bludit import preview token is invalid.' );
        }

        $target_lock_key = $this->getTargetLockKey( $preview );
        $this->acquireImportLock(
            $target_lock_key,
            'bludit-import',
            $token,
            (string) ( $preview['created_by_username'] ?? '' ),
            $force_lock
        );

        $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
        $state = [
            'token' => $token,
            'target_scope' => (string) ( $preview['target_scope'] ?? 'root' ),
            'target_author_slug' => (string) ( $preview['target_author_slug'] ?? '' ),
            'target_lock_key' => $target_lock_key,
            'created_by_username' => (string) ( $preview['created_by_username'] ?? '' ),
            'status' => 'running',
            'replace_existing_posts' => ! empty( $preview['replace_existing_posts'] ),
            'replace_existing_pages' => ! empty( $preview['replace_existing_pages'] ),
            'existing_posts_deleted' => false,
            'existing_pages_deleted' => false,
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
                'missing_media' => 0,
                'deleted_existing_posts' => 0,
                'deleted_existing_pages' => 0,
            ],
            'missing_media' => [],
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
            throw new \RuntimeException( 'The Bludit import preview token is invalid.' );
        }

        $preview = $this->getPreview( $token );
        if ( ! is_array( $preview ) ) {
            throw new \RuntimeException( 'The Bludit import preview is missing or expired.' );
        }

        $state = $this->getImportState( $token );
        if ( ! is_array( $state ) ) {
            $state = $this->startImport( $preview, $batch_size, $force_lock );
        } else {
            $this->acquireImportLock(
                (string) ( $state['target_lock_key'] ?? $this->getTargetLockKey( $preview ) ),
                'bludit-import',
                $token,
                (string) ( $state['created_by_username'] ?? $preview['created_by_username'] ?? '' ),
                $force_lock
            );
        }

        if ( (string) ( $state['status'] ?? '' ) === 'finished' ) {
            return( $this->buildImportBatchResult( $state, [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'missing_media' => 0, 'deleted_existing_posts' => 0, 'deleted_existing_pages' => 0 ] ) );
        }

        try {
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $total_entries = count( $entries );
            $limit = max( 1, min( self::MAX_IMPORT_BATCH_SIZE, $batch_size ) );
            $state['status'] = 'running';
            $state['batch_size'] = $limit;
            unset( $state['error_message'] );
            $seen_keys = is_array( $state['seen_keys'] ?? null ) ? $state['seen_keys'] : [];
            $counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : [];
            $counts = array_merge(
                [
                    'processed' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'skipped_duplicate' => 0,
                    'skipped_invalid' => 0,
                    'missing_media' => 0,
                    'deleted_existing_posts' => 0,
                    'deleted_existing_pages' => 0,
                ],
                $counts
            );
            $next_offset = max( 0, (int) ( $state['next_offset'] ?? 0 ) );
            $batch_counts = [
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'skipped_duplicate' => 0,
                'skipped_invalid' => 0,
                'missing_media' => 0,
                'deleted_existing_posts' => 0,
                'deleted_existing_pages' => 0,
            ];
            $missing_media = is_array( $state['missing_media'] ?? null ) ? $state['missing_media'] : [];

            if ( ! empty( $state['replace_existing_posts'] ) && empty( $state['existing_posts_deleted'] ) ) {
                $deleted_count = $this->deleteExistingTargetEntries( (string) ( $state['target_scope'] ?? 'root' ), (string) ( $state['target_author_slug'] ?? '' ), 'post' );
                $counts['deleted_existing_posts'] += $deleted_count;
                $batch_counts['deleted_existing_posts'] += $deleted_count;
                $state['existing_posts_deleted'] = true;
            }
            if ( ! empty( $state['replace_existing_pages'] ) && empty( $state['existing_pages_deleted'] ) ) {
                $deleted_count = $this->deleteExistingTargetEntries( (string) ( $state['target_scope'] ?? 'root' ), (string) ( $state['target_author_slug'] ?? '' ), 'page' );
                $counts['deleted_existing_pages'] += $deleted_count;
                $batch_counts['deleted_existing_pages'] += $deleted_count;
                $state['existing_pages_deleted'] = true;
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
                $scope_key = $type . ':' . $slug . ':' . (string) ( $entry['parent_slug'] ?? '' );
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

                $body_path = trim( (string) ( $entry['body_path'] ?? '' ) );
                if ( $body_path === '' || ! is_file( $body_path ) || ! is_readable( $body_path ) ) {
                    $counts['skipped']++;
                    $counts['skipped_invalid']++;
                    $batch_counts['skipped']++;
                    $batch_counts['skipped_invalid']++;
                    continue;
                }

                try {
                    $body_html = (string) file_get_contents( $body_path );
                    $attached_media_ids = [];
                    $entry_missing_media = [];
                    $localized_html = $this->localizeHtmlMedia( $body_html, $entry, $preview, $attached_media_ids, $entry_missing_media );
                    foreach ( $entry_missing_media as $missing_item ) {
                        if ( ! is_array( $missing_item ) ) {
                            continue;
                        }
                        $counts['missing_media']++;
                        $batch_counts['missing_media']++;
                        $missing_media = $this->appendMissingMediaRecord(
                            $missing_media,
                            [
                                'title' => (string) ( $entry['title'] ?? '' ),
                                'slug' => (string) ( $entry['slug'] ?? '' ),
                                'entry_type' => (string) ( $entry['entry_type'] ?? '' ),
                                'path' => (string) ( $missing_item['path'] ?? '' ),
                            ]
                        );
                    }

                    $summary = $this->summarizeHtml( $localized_html, (string) ( $entry['summary'] ?? '' ) );
                    $content = trim( $localized_html );
                    if ( $content === '' ) {
                        $content = $summary !== '' ? $summary : '';
                    }

                    $editor_entry = [
                        'entry_type' => (string) ( $entry['entry_type'] ?? 'page' ),
                        'scope' => (string) ( $preview['target_scope'] ?? 'root' ),
                        'author_slug' => (string) ( $preview['target_author_slug'] ?? '' ),
                        'slug' => (string) ( $entry['slug'] ?? '' ),
                        'title' => (string) ( $entry['title'] ?? '' ),
                        'summary' => $summary,
                        'content' => $content,
                        'aggregate_to_root' => false,
                        'sticky' => ! empty( $entry['sticky'] ),
                        'featured_image_media_id' => '',
                        'featured_image' => [],
                        'parent_slug' => (string) ( $entry['parent_slug'] ?? '' ),
                        'sort_order' => (int) ( $entry['sort_order'] ?? 0 ),
                        'show_in_navigation' => ! empty( $entry['show_in_navigation'] ),
                        'seo_title' => '',
                        'seo_description' => '',
                        'seo_social_title' => '',
                        'seo_social_description' => '',
                        'seo_social_image_media_id' => '',
                        'seo_social_image' => [],
                        'seo_canonical_url' => '',
                        'seo_robots' => ! empty( $entry['source_noindex'] ) ? 'noindex,nofollow' : '',
                        'seo_exclude_from_sitemap' => ! empty( $entry['source_noindex'] ),
                    ];

                    $saved_entry = $this->content_repository->importEditorEntry(
                        $editor_entry,
                        (string) ( $entry['status'] ?? 'published' ),
                        [
                            'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                            'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                            'write_revision' => false,
                        ]
                    );

                    $media_owner = (string) ( $preview['target_scope'] ?? 'root' ) === 'author'
                        ? (string) ( $preview['target_author_slug'] ?? '' )
                        : 'root';
                    if ( $this->media_import_bridge instanceof TinyMashMediaImportBridge && ! empty( $attached_media_ids ) ) {
                        $this->media_import_bridge->attachMediaToEntry( $media_owner, (string) ( $saved_entry['id'] ?? '' ), array_keys( $attached_media_ids ) );
                    }

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
            $state['missing_media'] = $missing_media;
            $state['total_entries'] = $total_entries;
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );

            if ( (int) $state['next_offset'] >= $total_entries ) {
                $state['status'] = 'finished';
                $state['finished_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $this->releaseImportLock( (string) ( $state['target_lock_key'] ?? '' ), $token );
            }

            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            return( $this->buildImportBatchResult( $state, $batch_counts ) );
        } catch ( \Throwable $exception ) {
            $state['status'] = 'failed';
            $state['error_message'] = trim( $exception->getMessage() ) !== '' ? trim( $exception->getMessage() ) : 'The Bludit import batch failed.';
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            $this->releaseImportLock( (string) ( $state['target_lock_key'] ?? '' ), $token );
            throw $exception;
        }
    }

    protected function parseSiteDirectory( string $site_directory, string $target_scope, string $target_author_slug = '', bool $skip_unpublished = false, bool $require_project_local_directory = false ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'The Bludit import runtime is not available. DOM and JSON support are required.' );
        }

        $resolved_site_directory = $this->normalizeSiteDirectory( $site_directory, $require_project_local_directory );
        $site = $this->readBluditDatabase( $resolved_site_directory . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'site.php' );
        $pages = $this->readBluditDatabase( $resolved_site_directory . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'pages.php' );

        $site_timezone = trim( (string) ( $site['timezone'] ?? 'UTC' ) );
        if ( $site_timezone === '' ) {
            $site_timezone = 'UTC';
        }
        $source_base_url = trim( (string) ( $site['url'] ?? '' ) );
        if ( $source_base_url === '' ) {
            $source_base_url = 'https://example.invalid';
        }

        $page_not_found = trim( (string) ( $site['pageNotFound'] ?? '404' ) );
        $entries = [];
        $counts = [
            'total' => 0,
            'posts' => 0,
            'pages' => 0,
            'drafts' => 0,
            'skipped_unpublished' => 0,
        ];

        foreach ( $pages as $path => $page ) {
            if ( ! is_string( $path ) || ! is_array( $page ) ) {
                continue;
            }

            $normalized_path = trim( $path, '/' );
            $source_type = strtolower( trim( (string) ( $page['type'] ?? '' ) ) );
            if ( $normalized_path === '' || str_starts_with( $normalized_path, 'autosave-' ) || $source_type === 'autosave' ) {
                continue;
            }
            if ( $normalized_path === $page_not_found ) {
                continue;
            }

            $entry_status = $this->mapSourceStatus( $source_type );
            if ( $entry_status !== 'published' ) {
                $counts['drafts']++;
                if ( $skip_unpublished ) {
                    $counts['skipped_unpublished']++;
                    continue;
                }
            }

            $entry_type = $this->mapEntryType( $normalized_path, $source_type );
            $slug_parts = explode( '/', $normalized_path );
            $slug = trim( (string) array_pop( $slug_parts ) );
            if ( $slug === '' ) {
                continue;
            }

            $parent_slug = $entry_type === 'page' && ! empty( $slug_parts )
                ? trim( (string) array_pop( $slug_parts ) )
                : '';

            $body_path = $resolved_site_directory . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized_path ) . DIRECTORY_SEPARATOR . 'index.txt';
            $entries[] = [
                'source_path' => $normalized_path,
                'body_path' => $body_path,
                'source_type' => $source_type,
                'entry_type' => $entry_type,
                'status' => $entry_status,
                'sticky' => $source_type === 'sticky',
                'slug' => $slug,
                'parent_slug' => $parent_slug,
                'title' => trim( (string) ( $page['title'] ?? $slug ) ),
                'summary' => trim( (string) ( $page['description'] ?? '' ) ),
                'sort_order' => (int) ( $page['position'] ?? 0 ),
                'show_in_navigation' => $entry_type === 'page',
                'published_at_utc' => $this->toUtc( (string) ( $page['date'] ?? '' ), $site_timezone ),
                'updated_at_utc' => $this->toUtc( (string) ( $page['dateModified'] ?? '' ), $site_timezone ),
                'uuid' => trim( (string) ( $page['uuid'] ?? '' ) ),
                'source_noindex' => ! empty( $page['noindex'] ) || ! empty( $page['nofollow'] ) || ! empty( $page['noarchive'] ),
                'source_md5' => trim( (string) ( $page['md5file'] ?? '' ) ),
            ];

            $counts['total']++;
            if ( $entry_type === 'page' ) {
                $counts['pages']++;
            } else {
                $counts['posts']++;
            }
        }

        usort(
            $entries,
            static function( array $left, array $right ) : int {
                $left_depth = substr_count( (string) ( $left['source_path'] ?? '' ), '/' );
                $right_depth = substr_count( (string) ( $right['source_path'] ?? '' ), '/' );
                if ( $left_depth !== $right_depth ) {
                    return( $left_depth <=> $right_depth );
                }

                $left_sort = (int) ( $left['sort_order'] ?? 0 );
                $right_sort = (int) ( $right['sort_order'] ?? 0 );
                if ( $left_sort !== $right_sort ) {
                    return( $left_sort <=> $right_sort );
                }

                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        $uploads_root = $resolved_site_directory . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'uploads';
        return(
            [
                'source_directory' => $resolved_site_directory,
                'source_title' => trim( (string) ( $site['title'] ?? '' ) ),
                'source_slogan' => trim( (string) ( $site['slogan'] ?? '' ) ),
                'source_url' => $source_base_url,
                'source_theme' => trim( (string) ( $site['theme'] ?? '' ) ),
                'source_homepage' => trim( (string) ( $site['homepage'] ?? '' ) ),
                'source_timezone' => $site_timezone,
                'target_scope' => $target_scope === 'author' ? 'author' : 'root',
                'target_author_slug' => $target_scope === 'author' ? $this->normalizeSlug( $target_author_slug ) : '',
                'counts' => $counts,
                'entries' => $entries,
                'uploads_directory' => is_dir( $uploads_root ) ? $uploads_root : '',
                'uploads_file_count' => is_dir( $uploads_root ) ? $this->countFilesRecursively( $uploads_root ) : 0,
                'skip_unpublished' => $skip_unpublished,
            ]
        );
    }

    protected function localizeHtmlMedia( string $html, array $entry, array $preview, array &$attached_media_ids, array &$missing_media ) : string {
        $html = trim( $html );
        if ( $html === '' ) {
            return( '' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="tm-bludit-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );
        if ( ! $loaded ) {
            return( $html );
        }

        $root = $document->getElementById( 'tm-bludit-root' );
        if ( ! $root instanceof DOMElement ) {
            return( $html );
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'a' ) ) as $link ) {
            if ( ! $link instanceof DOMElement ) {
                continue;
            }
            $href = $link->getAttribute( 'href' );
            if ( $href === '' ) {
                continue;
            }
            $link->setAttribute( 'href', $this->normalizeInternalLink( $href, (string) ( $preview['source_url'] ?? '' ), $preview ) );
        }

        foreach ( iterator_to_array( $root->getElementsByTagName( 'img' ) ) as $image ) {
            if ( ! $image instanceof DOMElement ) {
                continue;
            }

            $source_url = trim( html_entity_decode( $image->getAttribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( $source_url === '' ) {
                continue;
            }

            $absolute_source_url = $this->normalizeAbsoluteSourceUrl( $source_url, (string) ( $preview['source_url'] ?? '' ) );
            if ( $absolute_source_url === '' ) {
                continue;
            }

            $parts = parse_url( $absolute_source_url );
            $path = trim( (string) ( $parts['path'] ?? '' ) );
            if ( $path === '' || ! str_starts_with( $path, '/bl-content/uploads/' ) ) {
                continue;
            }

            $local_path = rtrim( (string) ( $preview['source_directory'] ?? '' ), DIRECTORY_SEPARATOR ) . $path;
            if ( ! is_file( $local_path ) || ! is_readable( $local_path ) ) {
                $missing_media[] = [ 'path' => $path ];
                continue;
            }

            if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
                continue;
            }

            $media_owner = (string) ( $preview['target_scope'] ?? 'root' ) === 'author'
                ? (string) ( $preview['target_author_slug'] ?? '' )
                : 'root';
            $alt_text = trim( html_entity_decode( $image->getAttribute( 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            $import = $this->media_import_bridge->importLocalFile(
                'bludit-import',
                $media_owner,
                $local_path,
                [
                    'source_key' => 'bludit:' . sha1( $absolute_source_url ),
                    'source_id' => (string) ( ( $entry['uuid'] ?? '' ) !== '' ? ( $entry['uuid'] . '|' . $path ) : $path ),
                    'source_url' => $absolute_source_url,
                    'original_filename' => basename( $local_path ),
                    'alt_text' => $alt_text,
                ]
            );

            $media = is_array( $import['media'] ?? null ) ? $import['media'] : [];
            $media_id = trim( (string) ( $media['media_id'] ?? '' ) );
            if ( $media_id !== '' ) {
                $attached_media_ids[$media_id] = true;
            }

            $media_url = trim( (string) ( $media['url'] ?? '' ) );
            if ( $media_url === '' ) {
                continue;
            }

            $image->setAttribute( 'src', $media_url );
            $image->removeAttribute( 'srcset' );
            $image->removeAttribute( 'sizes' );
            if ( ! empty( $media['width'] ) ) {
                $image->setAttribute( 'width', (string) $media['width'] );
            }
            if ( ! empty( $media['height'] ) ) {
                $image->setAttribute( 'height', (string) $media['height'] );
            }
        }

        $output = '';
        foreach ( $root->childNodes as $child ) {
            $output .= $document->saveHTML( $child );
        }

        return( $output );
    }

    protected function normalizeInternalLink( string $href, string $source_base_url, array $preview ) : string {
        $href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $href === '' || str_starts_with( $href, '#' ) || str_starts_with( strtolower( $href ), 'mailto:' ) || str_starts_with( strtolower( $href ), 'tel:' ) ) {
            return( $href );
        }

        $source_parts = parse_url( $source_base_url );
        $source_host = strtolower( trim( (string) ( $source_parts['host'] ?? '' ) ) );
        $source_scheme = strtolower( trim( (string) ( $source_parts['scheme'] ?? 'https' ) ) );

        if ( preg_match( '#^https?://#i', $href ) === 1 ) {
            $parts = parse_url( $href );
            $host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
            if ( $host === '' || $host !== $source_host ) {
                return( $href );
            }

            $path = trim( (string) ( $parts['path'] ?? '/' ) );
            $query = isset( $parts['query'] ) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
            $fragment = isset( $parts['fragment'] ) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
            return( $this->buildTargetPublicPath( $path, $preview ) . $query . $fragment );
        }

        if ( str_starts_with( $href, '//' ) ) {
            return( $this->normalizeInternalLink( $source_scheme . ':' . $href, $source_base_url, $preview ) );
        }

        if ( str_starts_with( $href, '/' ) ) {
            return( $this->buildTargetPublicPath( $href, $preview ) );
        }

        if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) === 1 ) {
            return( $href );
        }

        return( $this->buildTargetPublicPath( '/' . ltrim( $href, '/' ), $preview ) );
    }

    protected function buildTargetPublicPath( string $path, array $preview ) : string {
        $normalized_path = '/' . ltrim( trim( $path ), '/' );
        if ( $normalized_path === '//' || $normalized_path === '/' ) {
            return( (string) ( $preview['target_scope'] ?? 'root' ) === 'author'
                ? '/' . trim( (string) ( $preview['target_author_slug'] ?? '' ), '/' )
                : '/'
            );
        }

        if ( str_starts_with( $normalized_path, '/bl-content/' ) ) {
            return( $normalized_path );
        }

        if ( (string) ( $preview['target_scope'] ?? 'root' ) === 'author' ) {
            return( '/' . trim( (string) ( $preview['target_author_slug'] ?? '' ), '/' ) . $normalized_path );
        }

        return( $normalized_path );
    }

    protected function normalizeAbsoluteSourceUrl( string $source_url, string $source_base_url ) : string {
        if ( $source_url === '' ) {
            return( '' );
        }

        if ( str_starts_with( $source_url, '//' ) ) {
            return( 'https:' . $source_url );
        }
        if ( preg_match( '#^https?://#i', $source_url ) === 1 ) {
            return( $source_url );
        }

        return( rtrim( $source_base_url, '/' ) . '/' . ltrim( $source_url, '/' ) );
    }

    protected function summarizeHtml( string $html, string $fallback = '', int $word_limit = 40 ) : string {
        $fallback = trim( $fallback );
        if ( $fallback !== '' ) {
            return( $fallback );
        }

        $text = trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $text = preg_replace( '/\s+/u', ' ', $text );
        $text = is_string( $text ) ? trim( $text ) : '';
        if ( $text === '' ) {
            return( '' );
        }

        $words = preg_split( '/\s+/u', $text ) ?: [];
        if ( count( $words ) <= $word_limit ) {
            return( $text );
        }

        return( implode( ' ', array_slice( $words, 0, $word_limit ) ) . '…' );
    }

    protected function readBluditDatabase( string $filename ) : array {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'The Bludit database file could not be read: ' . $filename );
        }

        $raw = file_get_contents( $filename );
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            throw new \RuntimeException( 'The Bludit database file is empty: ' . $filename );
        }

        $json = preg_replace( '/^\s*<\?php.*?\?>\s*/s', '', $raw );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            throw new \RuntimeException( 'Unable to extract JSON from Bludit database: ' . $filename );
        }

        try {
            $decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            throw new \RuntimeException( 'The Bludit database JSON is invalid: ' . $filename );
        }

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'The Bludit database is not a JSON object: ' . $filename );
        }

        return( $decoded );
    }

    protected function normalizeSiteDirectory( string $site_directory, bool $require_project_local_directory = false ) : string {
        $site_directory = trim( $site_directory );
        if ( $site_directory === '' ) {
            throw new \RuntimeException( 'A Bludit site directory is required.' );
        }

        if ( ! str_starts_with( $site_directory, DIRECTORY_SEPARATOR ) ) {
            $site_directory = $this->project_root . DIRECTORY_SEPARATOR . ltrim( $site_directory, DIRECTORY_SEPARATOR );
        }

        $resolved = realpath( $site_directory );
        if ( $resolved === false || ! is_dir( $resolved ) || ! is_readable( $resolved ) ) {
            throw new \RuntimeException( 'The Bludit site directory could not be read.' );
        }

        if ( $require_project_local_directory ) {
            $project_root = $this->project_root . DIRECTORY_SEPARATOR;
            if ( ! str_starts_with( $resolved . DIRECTORY_SEPARATOR, $project_root ) ) {
                throw new \RuntimeException( 'The Bludit site directory must be inside the tinymash project.' );
            }
        }

        $site_database = $resolved . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'site.php';
        $pages_database = $resolved . DIRECTORY_SEPARATOR . 'bl-content' . DIRECTORY_SEPARATOR . 'databases' . DIRECTORY_SEPARATOR . 'pages.php';
        if ( ! is_file( $site_database ) || ! is_file( $pages_database ) ) {
            throw new \RuntimeException( 'The directory does not look like a Bludit site tree.' );
        }

        return( $resolved );
    }

    protected function mapEntryType( string $path, string $source_type ) : string {
        if ( $source_type === 'static' || str_contains( $path, '/' ) ) {
            return( 'page' );
        }

        return( 'post' );
    }

    protected function mapSourceStatus( string $source_type ) : string {
        return( match ( $source_type ) {
            'static', 'sticky', 'published' => 'published',
            default => 'unpublished',
        } );
    }

    protected function toUtc( string $value, string $timezone ) : string {
        $value = trim( $value );
        if ( $value === '' ) {
            return( '' );
        }

        try {
            $date = new DateTimeImmutable( $value, new DateTimeZone( $timezone ) );
            return( $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) );
        } catch ( \Throwable ) {
            return( '' );
        }
    }

    protected function countFilesRecursively( string $directory ) : int {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $fileinfo ) {
            if ( $fileinfo instanceof SplFileInfo && $fileinfo->isFile() ) {
                $count++;
            }
        }

        return( $count );
    }

    protected function deleteExistingTargetEntries( string $target_scope, string $target_author_slug, string $entry_type ) : int {
        if ( $target_scope === 'author' ) {
            if ( $entry_type === 'page' ) {
                $deleted = $this->content_repository->deletePagesByAuthorSlug( $target_author_slug );
                return( (int) ( $deleted['deleted_pages'] ?? 0 ) );
            }

            $deleted = $this->content_repository->deletePostsByAuthorSlug( $target_author_slug );
            return( (int) ( $deleted['deleted_posts'] ?? 0 ) );
        }

        if ( $entry_type === 'page' ) {
            $deleted = $this->content_repository->deleteRootPages();
            return( (int) ( $deleted['deleted_pages'] ?? 0 ) );
        }

        $deleted = $this->content_repository->deleteRootPosts();
        return( (int) ( $deleted['deleted_posts'] ?? 0 ) );
    }

    protected function appendMissingMediaRecord( array $records, array $record ) : array {
        $key = trim( (string) ( $record['slug'] ?? '' ) ) . '|' . trim( (string) ( $record['path'] ?? '' ) );
        if ( $key === '|' ) {
            return( $records );
        }

        foreach ( $records as $existing_record ) {
            if ( ! is_array( $existing_record ) ) {
                continue;
            }
            $existing_key = trim( (string) ( $existing_record['slug'] ?? '' ) ) . '|' . trim( (string) ( $existing_record['path'] ?? '' ) );
            if ( $existing_key === $key ) {
                return( $records );
            }
        }

        $records[] = $record;
        return( $records );
    }

    protected function buildImportBatchResult( array $state, array $batch_counts ) : array {
        $counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : [];
        $total = max( 0, (int) ( $state['total_entries'] ?? $counts['processed'] ?? 0 ) );
        $processed = max( 0, min( $total, (int) ( $counts['processed'] ?? 0 ) ) );
        $percent = $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 0;

        return(
            [
                'done' => (string) ( $state['status'] ?? '' ) === 'finished',
                'token' => (string) ( $state['token'] ?? '' ),
                'counts' => array_merge( $counts, [ 'total' => $total ] ),
                'batch_counts' => $batch_counts,
                'progress_percent' => $percent,
                'missing_media' => is_array( $state['missing_media'] ?? null ) ? $state['missing_media'] : [],
                'finished_at_utc' => (string) ( $state['finished_at_utc'] ?? '' ),
            ]
        );
    }

    protected function getTargetLockKey( array $preview ) : string {
        return( (string) ( $preview['target_scope'] ?? 'root' ) === 'author'
            ? $this->normalizeSlug( (string) ( $preview['target_author_slug'] ?? '' ) )
            : 'root'
        );
    }

    protected function acquireImportLock( string $target_lock_key, string $importer_key, string $token, string $created_by_username = '', bool $force = false ) : void {
        if ( ! $this->import_lock_service instanceof TinyMashImportLockService ) {
            return;
        }

        $existing = $this->import_lock_service->acquireAuthorLock( $target_lock_key, $importer_key, $token, $created_by_username, $force );
        if ( (string) ( $existing['token'] ?? '' ) !== $token ) {
            throw new \RuntimeException( 'Another import currently holds the target-space lock.' );
        }
    }

    protected function releaseImportLock( string $target_lock_key, string $token ) : void {
        if ( ! $this->import_lock_service instanceof TinyMashImportLockService ) {
            return;
        }

        $this->import_lock_service->releaseAuthorLock( $target_lock_key, $token );
    }

    protected function getWorkspaceDirectory( string $token ) : string {
        return( $this->workspace_directory . DIRECTORY_SEPARATOR . $token );
    }

    protected function getImportStateFilename( string $token ) : string {
        return( $this->getWorkspaceDirectory( $token ) . DIRECTORY_SEPARATOR . 'import-state.json' );
    }

    protected function generateToken() : string {
        try {
            $random = bin2hex( random_bytes( 6 ) );
        } catch ( \Throwable ) {
            $random = substr( sha1( uniqid( 'bludit', true ) ), 0, 12 );
        }

        return( 'bludit_' . gmdate( 'Ymd_His' ) . '_' . $random );
    }

    protected function normalizeToken( string $token ) : string {
        return( preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( $token ) ) ) ?? '' );
    }

    protected function normalizeSlug( string $value ) : string {
        return( preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( $value ) ) ) ?? '' );
    }

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'The import workspace could not be created.' );
        }

        $encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || $encoded === '' ) {
            throw new \RuntimeException( 'The import preview could not be encoded.' );
        }

        if ( @file_put_contents( $filename, $encoded . PHP_EOL, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'The import workspace could not be written.' );
        }
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
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

            $filename = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $filename ) ) {
                $this->deleteDirectoryRecursively( $filename );
                continue;
            }

            @unlink( $filename );
        }

        @rmdir( $directory );
    }
}
