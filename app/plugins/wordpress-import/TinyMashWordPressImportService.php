<?php

use app\classes\TinyMashContentRepository;
use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;

class TinyMashWordPressImportService {

    protected const CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';
    protected const EXCERPT_NAMESPACE = 'http://wordpress.org/export/1.2/excerpt/';
    protected const WP_NAMESPACE = 'http://wordpress.org/export/1.2/';
    protected const MAX_IMPORT_BATCH_SIZE = 100;

    protected string $workspace_directory;
    protected TinyMashContentRepository $content_repository;
    protected ?TinyMashConfig $config;
    protected ?TinyMashConfigIO $config_io;
    protected ?TinyMashMediaImportBridge $media_import_bridge;
    protected ?TinyMashImportLockService $import_lock_service;

    public function __construct( string $workspace_directory, TinyMashContentRepository $content_repository, ?TinyMashConfig $config = null, ?TinyMashConfigIO $config_io = null, ?TinyMashMediaImportBridge $media_import_bridge = null, ?TinyMashImportLockService $import_lock_service = null ) {
        $this->workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        $this->content_repository = $content_repository;
        $this->config = $config;
        $this->config_io = $config_io;
        $this->media_import_bridge = $media_import_bridge;
        $this->import_lock_service = $import_lock_service;
    }

    public function isAvailable() : bool {
        return(
            class_exists( '\SimpleXMLElement' )
            && function_exists( 'simplexml_load_string' )
        );
    }

    public function createPreviewFromUpload( array $file, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, bool $replace_existing_pages = false, bool $skip_unpublished = false, bool $import_menus = false ) : array {
        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
            throw new \RuntimeException( 'A WordPress export file is required.' );
        }

        $xml = @ file_get_contents( $file['tmp_name'] );
        if ( ! is_string( $xml ) || trim( $xml ) === '' ) {
            throw new \RuntimeException( 'The WordPress export file could not be read.' );
        }

        return( $this->createPreviewFromXml( $xml, $author_slug, $aggregate_to_root, $created_by_username, $replace_existing_posts, $replace_existing_pages, $skip_unpublished, $import_menus ) );
    }

    public function createPreviewFromXml( string $xml, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, bool $replace_existing_pages = false, bool $skip_unpublished = false, bool $import_menus = false ) : array {
        $preview = $this->parseXml( $xml, $author_slug, $aggregate_to_root, $skip_unpublished, $import_menus );
        $preview['token'] = $this->generateToken();
        $preview['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        $preview['created_by_username'] = $this->normalizeSlug( $created_by_username !== '' ? $created_by_username : $author_slug );
        $preview['replace_existing_posts'] = $replace_existing_posts;
        $preview['replace_existing_pages'] = $replace_existing_pages;
        $preview['skip_unpublished'] = $skip_unpublished;
        $preview['import_menus'] = $import_menus;

        $workspace_directory = $this->getWorkspaceDirectory( $preview['token'] );
        if ( ! is_dir( $workspace_directory ) && ! @ mkdir( $workspace_directory, 0775, true ) && ! is_dir( $workspace_directory ) ) {
            throw new \RuntimeException( 'The WordPress import preview workspace could not be created.' );
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
            throw new \RuntimeException( 'The WordPress import preview token is invalid.' );
        }

        $this->acquireImportLock(
            (string) ( $preview['author_slug'] ?? '' ),
            'wordpress-import',
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
            'replace_existing_pages' => ! empty( $preview['replace_existing_pages'] ),
            'import_menus' => ! empty( $preview['import_menus'] ),
            'menus_imported' => false,
            'menu_import_locations' => is_array( $preview['menus']['recognized_locations'] ?? null ) ? $preview['menus']['recognized_locations'] : [],
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
                'unresolved_media' => 0,
                'deleted_existing_posts' => 0,
                'deleted_existing_pages' => 0,
            ],
            'unresolved_media' => [],
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
            throw new \RuntimeException( 'The WordPress import preview token is invalid.' );
        }

        $preview = $this->getPreview( $token );
        if ( ! is_array( $preview ) ) {
            throw new \RuntimeException( 'The WordPress import preview is missing or expired.' );
        }

        $state = $this->getImportState( $token );
        if ( ! is_array( $state ) ) {
            $state = $this->startImport( $preview, $batch_size, $force_lock );
        } else {
            $this->acquireImportLock(
                (string) ( $state['author_slug'] ?? '' ),
                'wordpress-import',
                $token,
                (string) ( $state['created_by_username'] ?? $preview['created_by_username'] ?? '' ),
                $force_lock
            );
        }

        if ( (string) ( $state['status'] ?? '' ) === 'finished' ) {
            return( $this->buildImportBatchResult( $state, [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'unresolved_media' => 0, 'deleted_existing_posts' => 0, 'deleted_existing_pages' => 0 ] ) );
        }

        try {
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $attachments = is_array( $preview['attachments'] ?? null ) ? $preview['attachments'] : [];
            $allowed_hosts = is_array( $preview['allowed_remote_hosts'] ?? null ) ? $preview['allowed_remote_hosts'] : [];
            $total_entries = count( $entries );
            $limit = max( 1, min( self::MAX_IMPORT_BATCH_SIZE, $batch_size ) );
            $state['status'] = 'running';
            $state['batch_size'] = $limit;
            unset( $state['error_message'] );
            $seen_keys = is_array( $state['seen_keys'] ?? null ) ? $state['seen_keys'] : [];
            $counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'unresolved_media' => 0, 'deleted_existing_posts' => 0, 'deleted_existing_pages' => 0 ];
            $next_offset = max( 0, (int) ( $state['next_offset'] ?? 0 ) );
            $batch_counts = [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'unresolved_media' => 0, 'deleted_existing_posts' => 0, 'deleted_existing_pages' => 0 ];
            $unresolved_media = is_array( $state['unresolved_media'] ?? null ) ? $state['unresolved_media'] : [];

            if ( ! empty( $state['replace_existing_posts'] ) && empty( $state['existing_posts_deleted'] ) ) {
                $deleted = $this->content_repository->deletePostsByAuthorSlug( (string) ( $state['author_slug'] ?? '' ) );
                $deleted_count = (int) ( $deleted['deleted_posts'] ?? 0 );
                $counts['deleted_existing_posts'] += $deleted_count;
                $batch_counts['deleted_existing_posts'] += $deleted_count;
                $state['existing_posts_deleted'] = true;
            }
            if ( ! empty( $state['replace_existing_pages'] ) && empty( $state['existing_pages_deleted'] ) ) {
                $deleted = $this->content_repository->deletePagesByAuthorSlug( (string) ( $state['author_slug'] ?? '' ) );
                $deleted_count = (int) ( $deleted['deleted_pages'] ?? 0 );
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
                    $entry_unresolved_media = [];
                    $entry = $this->applyImportedMediaToEntry( $entry, (string) ( $state['author_slug'] ?? '' ), $attachments, $allowed_hosts, $attached_media_ids, $entry_unresolved_media );
                    foreach ( $entry_unresolved_media as $unresolved_item ) {
                        if ( ! is_array( $unresolved_item ) ) {
                            continue;
                        }

                        $counts['unresolved_media']++;
                        $batch_counts['unresolved_media']++;
                        $unresolved_media = $this->appendUnresolvedMediaRecord(
                            $unresolved_media,
                            [
                                'title' => (string) ( $entry['title'] ?? '' ),
                                'slug' => (string) ( $entry['slug'] ?? '' ),
                                'entry_type' => (string) ( $entry['entry_type'] ?? '' ),
                                'url' => (string) ( $unresolved_item['url'] ?? '' ),
                            ]
                        );
                    }
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
            $state['unresolved_media'] = $unresolved_media;
            $state['total_entries'] = $total_entries;
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );

            if (
                $batch_counts['processed'] > 0
                || $batch_counts['deleted_existing_posts'] > 0
                || $batch_counts['deleted_existing_pages'] > 0
            ) {
                $this->content_repository->invalidateDerivedCaches();
            }

            if ( (int) $state['next_offset'] >= $total_entries ) {
                if ( ! empty( $state['import_menus'] ) && empty( $state['menus_imported'] ) ) {
                    $this->applyImportedMenus( $preview, (string) ( $state['author_slug'] ?? '' ) );
                    $state['menus_imported'] = true;
                }
                $state['status'] = 'finished';
                $state['finished_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $this->releaseImportLock( (string) ( $state['author_slug'] ?? '' ), $token );
            }

            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            return( $this->buildImportBatchResult( $state, $batch_counts ) );
        } catch ( \Throwable $exception ) {
            $state['status'] = 'failed';
            $state['error_message'] = trim( $exception->getMessage() ) !== '' ? trim( $exception->getMessage() ) : 'The WordPress import batch failed.';
            $state['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
            $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            $this->releaseImportLock( (string) ( $state['author_slug'] ?? '' ), $token );
            throw $exception;
        }
    }

    protected function parseXml( string $xml, string $author_slug, bool $aggregate_to_root = false, bool $skip_unpublished = false, bool $import_menus = false ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'The WordPress import runtime is not available. SimpleXML is required.' );
        }

        $author_slug = $this->normalizeSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \RuntimeException( 'A valid target author is required for WordPress import.' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $xml_feed = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NOCDATA );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );

        if ( ! $xml_feed instanceof \SimpleXMLElement ) {
            $message = ! empty( $errors[0]->message ) ? trim( $errors[0]->message ) : 'The WordPress export XML could not be parsed.';
            throw new \RuntimeException( $message );
        }

        if ( strtolower( $xml_feed->getName() ) !== 'rss' || ! isset( $xml_feed->channel ) ) {
            throw new \RuntimeException( 'The uploaded file does not look like a WordPress WXR export.' );
        }

        $channel = $xml_feed->channel;
        $namespaces = $xml_feed->getNamespaces( true );
        $wp_namespace = (string) ( $namespaces['wp'] ?? self::WP_NAMESPACE );
        $content_namespace = (string) ( $namespaces['content'] ?? self::CONTENT_NAMESPACE );
        $excerpt_namespace = (string) ( $namespaces['excerpt'] ?? self::EXCERPT_NAMESPACE );

        $attachments = [];
        $allowed_remote_hosts = [];
        $channel_link = trim( (string) $channel->link );
        $channel_host = strtolower( trim( (string) parse_url( $channel_link, PHP_URL_HOST ) ) );
        if ( $channel_host !== '' ) {
            $allowed_remote_hosts[] = $channel_host;
        }

        foreach ( $channel->item as $item ) {
            $wp = $item->children( $wp_namespace );
            $post_type = strtolower( trim( (string) $wp->post_type ) );
            if ( $post_type !== 'attachment' ) {
                continue;
            }

            $attachment_id = trim( (string) $wp->post_id );
            $attachment_url = trim( (string) $wp->attachment_url );
            if ( $attachment_id === '' || $attachment_url === '' ) {
                continue;
            }

            $attachment_host = strtolower( trim( (string) parse_url( $attachment_url, PHP_URL_HOST ) ) );
            if ( $attachment_host !== '' && ! in_array( $attachment_host, $allowed_remote_hosts, true ) ) {
                $allowed_remote_hosts[] = $attachment_host;
            }

            $attachments[$attachment_id] = [
                'attachment_id' => $attachment_id,
                'url' => $attachment_url,
                'title' => $this->normalizeText( (string) $item->title ),
                'source_id' => $attachment_id,
                'original_filename' => basename( (string) parse_url( $attachment_url, PHP_URL_PATH ) ),
                'variants' => $this->resolveAttachmentVariants( $wp, $attachment_url, $attachment_id, (string) $item->title ),
            ];
        }

        $entries = [];
        $unpublished_entries = [];
        $entries_by_source_id = [];
        $seen_entry_keys = [];
        $adjusted_source_duplicates = 0;
        $adjusted_source_conflicts = [];
        $counts = [
            'posts' => 0,
            'pages' => 0,
            'drafts' => 0,
            'skipped_unpublished' => 0,
            'attachments' => count( $attachments ),
            'total' => 0,
        ];

        foreach ( $channel->item as $item ) {
            $parsed_entry = $this->parseWordPressItem( $item, $author_slug, $aggregate_to_root, $wp_namespace, $content_namespace, $excerpt_namespace );
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
            $source_id = (string) ( $parsed_entry['source_id'] ?? '' );
            if ( $source_id !== '' ) {
                $entries_by_source_id[$source_id] = [
                    'entry_type' => (string) ( $parsed_entry['entry_type'] ?? '' ),
                    'slug' => (string) ( $parsed_entry['slug'] ?? '' ),
                    'title' => (string) ( $parsed_entry['title'] ?? '' ),
                ];
            }

            $counts['total']++;
            if ( $parsed_entry['entry_type'] === 'page' ) {
                $counts['pages']++;
            } else {
                $counts['posts']++;
            }
        }

        foreach ( $entries as $index => $entry ) {
            if ( ! is_array( $entry ) || (string) ( $entry['entry_type'] ?? '' ) !== 'page' ) {
                continue;
            }

            $parent_source_id = trim( (string) ( $entry['parent_source_id'] ?? '' ) );
            if ( $parent_source_id === '' ) {
                unset( $entries[$index]['parent_source_id'] );
                continue;
            }

            $parent = $entries_by_source_id[$parent_source_id] ?? null;
            if ( is_array( $parent ) && (string) ( $parent['entry_type'] ?? '' ) === 'page' ) {
                $entries[$index]['parent_slug'] = (string) ( $parent['slug'] ?? '' );
            }
            unset( $entries[$index]['parent_source_id'] );
        }

        $menus = $this->parseWordPressMenus( $channel, $wp_namespace, $entries_by_source_id, $author_slug );
        $counts['menus'] = count( (array) ( $menus['available'] ?? [] ) );
        $counts['menu_items'] = (int) ( $menus['menu_items_total'] ?? 0 );

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

        return(
            [
                'author_slug' => $author_slug,
                'created_by_username' => '',
                'aggregate_to_root' => $aggregate_to_root,
                'allowed_remote_hosts' => array_values( array_unique( array_filter( $allowed_remote_hosts, static fn( string $host ) : bool => $host !== '' ) ) ),
                'attachments' => $attachments,
                'attachment_count' => count( $attachments ),
                'adjusted_source_duplicates' => $adjusted_source_duplicates,
                'adjusted_source_conflicts' => $adjusted_source_conflicts,
                'entries' => $entries,
                'unpublished_entries' => $unpublished_entries,
                'menus' => $menus,
                'import_menus' => $import_menus,
                'counts' => $counts,
            ]
        );
    }

    protected function parseWordPressItem( \SimpleXMLElement $item, string $author_slug, bool $aggregate_to_root, string $wp_namespace, string $content_namespace, string $excerpt_namespace ) : ?array {
        $wp = $item->children( $wp_namespace );
        $content = $item->children( $content_namespace );
        $excerpt = $item->children( $excerpt_namespace );

        $post_type = strtolower( trim( (string) $wp->post_type ) );
        if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
            return( null );
        }

        $source_id = trim( (string) $wp->post_id );
        $title = $this->normalizeText( (string) $item->title );
        $content_html = trim( (string) $content->encoded );
        $excerpt_html = trim( (string) $excerpt->encoded );
        $source_url = trim( (string) $item->link );
        $slug = $this->resolveSlug( (string) $wp->post_name, $title, $post_type, $source_id, $source_url );
        if ( $slug === '' ) {
            return( null );
        }

        $status = $this->mapWordPressStatus( (string) $wp->status );
        $published_at_utc = $this->normalizeTimestamp( (string) $wp->post_date_gmt );
        if ( $published_at_utc === '' ) {
            $published_at_utc = $this->normalizeTimestamp( (string) $item->pubDate );
        }
        $updated_at_utc = $this->normalizeTimestamp( (string) $wp->post_modified_gmt );
        $thumbnail_attachment_id = $this->resolveThumbnailAttachmentId( $item, $wp_namespace );

        return(
            [
                'entry_type' => $post_type,
                'scope' => 'author',
                'author_slug' => $author_slug,
                'slug' => $slug,
                'title' => $title !== '' ? $title : ucfirst( $post_type ) . ' ' . $slug,
                'summary' => '',
                'content' => '',
                'content_html' => $content_html,
                'excerpt_html' => $excerpt_html,
                'status' => $status,
                'parent_slug' => '',
                'parent_source_id' => $post_type === 'page' ? trim( (string) $wp->post_parent ) : '',
                'sort_order' => $post_type === 'page' ? max( 0, (int) $wp->menu_order ) : 0,
                'aggregate_to_root' => $post_type === 'post' ? $aggregate_to_root : false,
                'sticky' => false,
                'show_in_navigation' => $post_type === 'page',
                'featured_image_media_id' => '',
                'featured_image' => [],
                'published_at_utc' => $published_at_utc,
                'updated_at_utc' => $updated_at_utc,
                'source_url' => $source_url,
                'source_id' => $source_id,
                'thumbnail_attachment_id' => $thumbnail_attachment_id,
            ]
        );
    }

    protected function mapWordPressStatus( string $status ) : string {
        return( strtolower( trim( $status ) ) === 'publish' ? 'published' : 'unpublished' );
    }

    protected function parseWordPressMenus( \SimpleXMLElement $channel, string $wp_namespace, array $entries_by_source_id, string $author_slug ) : array {
        $menu_terms = [];
        $wp_channel = $channel->children( $wp_namespace );
        foreach ( $wp_channel->term as $term ) {
            $taxonomy = strtolower( trim( (string) $term->term_taxonomy ) );
            if ( $taxonomy !== 'nav_menu' ) {
                continue;
            }

            $term_slug = $this->normalizeSlug( (string) $term->term_slug );
            if ( $term_slug === '' ) {
                continue;
            }

            $menu_terms[$term_slug] = [
                'slug' => $term_slug,
                'name' => $this->normalizeText( (string) $term->term_name ),
            ];
        }

        $menus_by_slug = [];
        $menu_items_total = 0;
        foreach ( $channel->item as $item ) {
            $wp = $item->children( $wp_namespace );
            $post_type = strtolower( trim( (string) $wp->post_type ) );
            if ( $post_type !== 'nav_menu_item' || $this->mapWordPressStatus( (string) $wp->status ) !== 'published' ) {
                continue;
            }

            $menu_slug = '';
            $menu_name = '';
            foreach ( $item->category as $category ) {
                $attributes = $category->attributes();
                $domain = strtolower( trim( (string) ( $attributes['domain'] ?? '' ) ) );
                if ( $domain !== 'nav_menu' ) {
                    continue;
                }

                $menu_slug = $this->normalizeSlug( (string) ( $attributes['nicename'] ?? '' ) );
                $menu_name = $this->normalizeText( (string) $category );
                break;
            }
            if ( $menu_slug === '' ) {
                continue;
            }

            $menu_items_total++;
            $menu_definition = $menu_terms[$menu_slug] ?? [ 'slug' => $menu_slug, 'name' => $menu_name !== '' ? $menu_name : ucwords( str_replace( '-', ' ', $menu_slug ) ) ];
            if ( ! isset( $menus_by_slug[$menu_slug] ) ) {
                $menus_by_slug[$menu_slug] = $menu_definition + [ 'items' => [] ];
            }

            $parsed_item = $this->parseWordPressMenuItem( $item, $wp, $entries_by_source_id, $author_slug );
            if ( is_array( $parsed_item ) ) {
                $menus_by_slug[$menu_slug]['items'][] = $parsed_item;
            }
        }

        $available = [];
        $locations = [
            'primary' => [],
            'footer' => [],
        ];
        $recognized_locations = [];
        foreach ( $menus_by_slug as $menu_slug => $menu_definition ) {
            $items = $this->buildWordPressMenuTree( is_array( $menu_definition['items'] ?? null ) ? $menu_definition['items'] : [] );
            $suggested_location = $this->guessWordPressMenuLocation( $menu_slug, (string) ( $menu_definition['name'] ?? '' ), $recognized_locations );
            $available[] = [
                'slug' => $menu_slug,
                'name' => (string) ( $menu_definition['name'] ?? $menu_slug ),
                'item_count' => $this->countWordPressMenuItems( $items ),
                'suggested_location' => $suggested_location,
            ];
            if ( $suggested_location !== '' && empty( $recognized_locations[$suggested_location] ) ) {
                $recognized_locations[$suggested_location] = $menu_slug;
                $locations[$suggested_location] = $items;
            }
        }

        return(
            [
                'available' => $available,
                'recognized_locations' => $recognized_locations,
                'locations' => $locations,
                'menu_items_total' => $menu_items_total,
            ]
        );
    }

    protected function parseWordPressMenuItem( \SimpleXMLElement $item, \SimpleXMLElement $wp, array $entries_by_source_id, string $author_slug ) : ?array {
        $source_id = trim( (string) $wp->post_id );
        if ( $source_id === '' ) {
            return( null );
        }

        $meta = $this->extractWordPressPostMeta( $wp );
        $item_type = strtolower( trim( (string) ( $meta['_menu_item_type'] ?? '' ) ) );
        $menu_parent_source_id = trim( (string) ( $meta['_menu_item_menu_item_parent'] ?? '' ) );
        $target = trim( (string) ( $meta['_menu_item_target'] ?? '' ) );
        $title = $this->normalizeText( (string) $item->title );
        $resolved = null;

        if ( $item_type === 'post_type' ) {
            $object_id = trim( (string) ( $meta['_menu_item_object_id'] ?? '' ) );
            $linked_entry = is_array( $entries_by_source_id[$object_id] ?? null ) ? $entries_by_source_id[$object_id] : null;
            if ( is_array( $linked_entry ) ) {
                $entry_type = strtolower( trim( (string) ( $linked_entry['entry_type'] ?? '' ) ) );
                $entry_slug = trim( (string) ( $linked_entry['slug'] ?? '' ) );
                if ( $entry_slug !== '' && in_array( $entry_type, [ 'post', 'page' ], true ) ) {
                    $resolved = [
                        'type' => 'url',
                        'url' => $this->buildImportedEntryUrl( $author_slug, $entry_slug ),
                        'label' => $title !== '' ? $title : (string) ( $linked_entry['title'] ?? $entry_slug ),
                        'new_tab' => strtolower( $target ) === '_blank',
                    ];
                }
            }
        }

        if ( ! is_array( $resolved ) ) {
            $source_url = trim( (string) ( $meta['_menu_item_url'] ?? '' ) );
            if ( $source_url === '' ) {
                $source_url = trim( (string) $item->link );
            }
            if ( $source_url === '' ) {
                return( null );
            }

            $resolved = [
                'type' => 'url',
                'url' => $source_url,
                'label' => $title !== '' ? $title : $source_url,
                'new_tab' => strtolower( $target ) === '_blank',
            ];
        }

        $resolved['children'] = [];
        $resolved['_source_id'] = $source_id;
        $resolved['_parent_source_id'] = $menu_parent_source_id;
        return( $resolved );
    }

    protected function buildWordPressMenuTree( array $items ) : array {
        $items_by_id = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $source_id = trim( (string) ( $item['_source_id'] ?? '' ) );
            if ( $source_id === '' ) {
                continue;
            }

            $item['children'] = is_array( $item['children'] ?? null ) ? $item['children'] : [];
            $items_by_id[$source_id] = $item;
        }

        $tree = [];
        foreach ( array_keys( $items_by_id ) as $source_id ) {
            $parent_source_id = trim( (string) ( $items_by_id[$source_id]['_parent_source_id'] ?? '' ) );
            if ( $parent_source_id !== '' && isset( $items_by_id[$parent_source_id] ) && $parent_source_id !== $source_id ) {
                $items_by_id[$parent_source_id]['children'][] = &$items_by_id[$source_id];
                continue;
            }

            $tree[] = &$items_by_id[$source_id];
        }

        return( array_values( array_map( fn( array $item ) : array => $this->stripWordPressMenuSourceFields( $item ), $tree ) ) );
    }

    protected function stripWordPressMenuSourceFields( array $item ) : array {
        $children = [];
        foreach ( (array) ( $item['children'] ?? [] ) as $child_item ) {
            if ( is_array( $child_item ) ) {
                $children[] = $this->stripWordPressMenuSourceFields( $child_item );
            }
        }

        unset( $item['_source_id'], $item['_parent_source_id'] );
        $item['children'] = $children;
        return( $item );
    }

    protected function guessWordPressMenuLocation( string $menu_slug, string $menu_name, array $recognized_locations = [] ) : string {
        $haystack = strtolower( trim( $menu_slug . ' ' . $menu_name ) );
        if ( $haystack === '' ) {
            return( '' );
        }

        if ( preg_match( '/\b(footer|bottom|social)\b/', $haystack ) ) {
            return( empty( $recognized_locations['footer'] ) ? 'footer' : '' );
        }

        if ( preg_match( '/\b(primary|main|header|top)\b/', $haystack ) ) {
            return( empty( $recognized_locations['primary'] ) ? 'primary' : '' );
        }

        if ( $menu_slug === 'menu' || $haystack === 'menu' ) {
            return( empty( $recognized_locations['primary'] ) ? 'primary' : '' );
        }

        return( '' );
    }

    protected function countWordPressMenuItems( array $items ) : int {
        $count = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $count++;
            $count += $this->countWordPressMenuItems( (array) ( $item['children'] ?? [] ) );
        }

        return( $count );
    }

    protected function extractWordPressPostMeta( \SimpleXMLElement $wp ) : array {
        $meta = [];
        foreach ( $wp->postmeta as $postmeta ) {
            $meta_key = trim( (string) $postmeta->meta_key );
            if ( $meta_key === '' ) {
                continue;
            }

            $meta[$meta_key] = trim( (string) $postmeta->meta_value );
        }

        return( $meta );
    }

    protected function buildImportedEntryUrl( string $author_slug, string $entry_slug ) : string {
        $author_slug = $this->normalizeSlug( $author_slug );
        $entry_slug = trim( preg_replace( '@/+@', '/', $entry_slug ) ?? '', '/' );
        return( '/' . rawurlencode( $author_slug ) . '/' . str_replace( '%2F', '/', rawurlencode( $entry_slug ) ) );
    }

    protected function applyImportedMenus( array $preview, string $author_slug ) : void {
        $recognized_locations = is_array( $preview['menus']['recognized_locations'] ?? null ) ? $preview['menus']['recognized_locations'] : [];
        $import_locations = is_array( $preview['menus']['locations'] ?? null ) ? $preview['menus']['locations'] : [];
        if ( empty( $recognized_locations ) || ! $this->config instanceof TinyMashConfig || ! $this->config_io instanceof TinyMashConfigIO ) {
            return;
        }

        $current_locations = $this->config->getMenusConfig();
        $merged_locations = [
            'primary' => is_array( $current_locations['primary'] ?? null ) ? $current_locations['primary'] : [],
            'footer' => is_array( $current_locations['footer'] ?? null ) ? $current_locations['footer'] : [],
        ];

        foreach ( [ 'primary', 'footer' ] as $location_key ) {
            if ( empty( $recognized_locations[$location_key] ) ) {
                continue;
            }

            $merged_locations[$location_key] = is_array( $import_locations[$location_key] ?? null ) ? array_values( $import_locations[$location_key] ) : [];
        }

        if ( ! $this->config_io->getConfig() ) {
            throw new \RuntimeException( 'The tinymash configuration could not be loaded for WordPress menu import.' );
        }

        if ( ! $this->config_io->updateSystemSettings( [ 'menu_locations' => $merged_locations ], 'menus' ) ) {
            throw new \RuntimeException( 'The imported WordPress menus could not be saved to the tinymash menu configuration.' );
        }
    }

    protected function resolveThumbnailAttachmentId( \SimpleXMLElement $item, string $wp_namespace ) : string {
        $wp = $item->children( $wp_namespace );
        foreach ( $wp->postmeta as $postmeta ) {
            $meta_key = trim( (string) $postmeta->meta_key );
            if ( $meta_key !== '_thumbnail_id' ) {
                continue;
            }

            return( trim( (string) $postmeta->meta_value ) );
        }

        return( '' );
    }

    protected function resolveSlug( string $post_name, string $title, string $kind, string $source_id, string $source_url = '' ) : string {
        $slug = $this->normalizeSlug( $post_name );
        if ( $slug !== '' ) {
            return( $slug );
        }

        if ( $title !== '' ) {
            $slug = $this->normalizeSlug( $title );
        }

        if ( $slug === '' && $source_url !== '' ) {
            $path = (string) parse_url( $source_url, PHP_URL_PATH );
            $segments = array_values( array_filter( explode( '/', $path ), static fn( string $segment ) : bool => trim( $segment ) !== '' ) );
            if ( ! empty( $segments ) ) {
                $slug = $this->normalizeSlug( trim( (string) end( $segments ) ) );
            }
        }

        if ( $slug === '' && $source_id !== '' ) {
            $slug = $kind . '-' . substr( sha1( $source_id ), 0, 10 );
        }

        return( $slug );
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

    protected function applyImportedMediaToEntry( array $entry, string $author_slug, array $attachments, array $allowed_hosts, array &$attached_media_ids = [], array &$unresolved_media = [] ) : array {
        $content_html = (string) ( $entry['content_html'] ?? '' );
        $excerpt_html = (string) ( $entry['excerpt_html'] ?? '' );
        $first_media = null;

        if ( $this->media_import_bridge instanceof TinyMashMediaImportBridge && $content_html !== '' ) {
            $content_html = $this->localizeWordPressHtml( $content_html, $author_slug, $attachments, $allowed_hosts, $attached_media_ids, $first_media, $unresolved_media );
        }

        $content_markdown = $this->convertHtmlToMarkdown( $content_html );
        $summary_markdown = $this->convertHtmlToMarkdown( $excerpt_html );
        $entry['content'] = $content_markdown;
        $entry['summary'] = $this->normalizeSummary( $summary_markdown, $content_markdown );

        $thumbnail_attachment_id = trim( (string) ( $entry['thumbnail_attachment_id'] ?? '' ) );
        if ( $thumbnail_attachment_id !== '' ) {
            $featured_media = $this->importAttachmentById( $thumbnail_attachment_id, $author_slug, $attachments, $allowed_hosts );
            if ( is_array( $featured_media ) ) {
                $media_id = trim( (string) ( $featured_media['media_id'] ?? '' ) );
                if ( $media_id !== '' ) {
                    $attached_media_ids[$media_id] = $media_id;
                }
                $entry['featured_image_media_id'] = $media_id;
                $entry['featured_image'] = [
                    'media_id' => (string) ( $featured_media['media_id'] ?? '' ),
                    'owner_username' => (string) ( $featured_media['owner_username'] ?? '' ),
                    'filename' => (string) ( $featured_media['filename'] ?? '' ),
                    'url' => (string) ( $featured_media['url'] ?? '' ),
                    'alt_text' => (string) ( $featured_media['alt_text'] ?? '' ),
                    'mime' => (string) ( $featured_media['mime'] ?? '' ),
                    'width' => (int) ( $featured_media['width'] ?? 0 ),
                    'height' => (int) ( $featured_media['height'] ?? 0 ),
                    'bytes' => (int) ( $featured_media['bytes'] ?? 0 ),
                    'derivative_key' => (string) ( $featured_media['metadata']['derivative_key'] ?? 'stored_primary' ),
                ];
            }
        }

        unset( $entry['content_html'], $entry['excerpt_html'], $entry['thumbnail_attachment_id'] );
        return( $entry );
    }

    protected function localizeWordPressHtml( string $html, string $author_slug, array $attachments, array $allowed_hosts, array &$attached_media_ids, ?array &$first_media = null, array &$unresolved_media = [] ) : string {
        $document = $this->loadHtmlFragmentDocument( $html, 'tm-wp-root' );
        if ( ! $document instanceof \DOMDocument ) {
            return( $html );
        }

        $root = $document->getElementById( 'tm-wp-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( $html );
        }

        foreach ( iterator_to_array( $document->getElementsByTagName( 'img' ) ) as $image ) {
            if ( ! $image instanceof \DOMElement ) {
                continue;
            }

            $source_url = trim( (string) $image->getAttribute( 'src' ) );
            if ( $source_url === '' ) {
                continue;
            }

            $normalized_source_url = $this->resolveWordPressSourceUrl( $source_url, $allowed_hosts );
            $resolved_media = $this->importAttachmentByUrl( $source_url, $author_slug, $attachments, $allowed_hosts, (string) $image->getAttribute( 'alt' ) );
            if ( ! is_array( $resolved_media ) ) {
                if ( $this->isAllowedDirectWordPressUploadUrl( $normalized_source_url, $allowed_hosts ) ) {
                    $unresolved_media = $this->appendUnresolvedMediaRecord(
                        $unresolved_media,
                        [
                            'url' => $normalized_source_url,
                        ]
                    );
                    if ( $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
                        $this->applyMissingImportedMediaFallback( $document, $image, $normalized_source_url );
                    } else {
                        $image->setAttribute( 'src', $normalized_source_url );
                        $parent = $image->parentNode;
                        if (
                            $parent instanceof \DOMElement
                            && strtolower( $parent->tagName ) === 'a'
                            && $this->sourceUrlsMatch( (string) $parent->getAttribute( 'href' ), $source_url )
                        ) {
                            $parent->setAttribute( 'href', $normalized_source_url );
                        }
                    }
                }
                continue;
            }

            $media_id = trim( (string) ( $resolved_media['media_id'] ?? '' ) );
            if ( $media_id !== '' ) {
                $attached_media_ids[$media_id] = $media_id;
            }
            if ( ! is_array( $first_media ) ) {
                $first_media = $resolved_media;
            }

            $image->setAttribute( 'src', (string) ( $resolved_media['url'] ?? $source_url ) );
            if ( trim( (string) $image->getAttribute( 'alt' ) ) === '' && ! empty( $resolved_media['alt_text'] ) ) {
                $image->setAttribute( 'alt', (string) $resolved_media['alt_text'] );
            }

            $parent = $image->parentNode;
            if (
                $parent instanceof \DOMElement
                && strtolower( $parent->tagName ) === 'a'
                && $this->sourceUrlsMatch( (string) $parent->getAttribute( 'href' ), $source_url )
            ) {
                $parent->setAttribute( 'href', (string) ( $resolved_media['url'] ?? $source_url ) );
            }
        }

        $rewritten_html = '';
        foreach ( $root->childNodes as $child_node ) {
            $rewritten_html .= $document->saveHTML( $child_node );
        }

        return( trim( $rewritten_html ) );
    }

    protected function applyMissingImportedMediaFallback( \DOMDocument $document, \DOMElement $image, string $source_url ) : void {
        if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
            return;
        }

        $fallback = $this->media_import_bridge->buildMissingImportedMediaFallback(
            $source_url,
            (string) $image->getAttribute( 'alt' )
        );
        $placeholder_url = trim( (string) ( $fallback['placeholder_url'] ?? '' ) );
        $fallback_source_url = trim( (string) ( $fallback['source_url'] ?? $source_url ) );
        $fallback_alt_text = trim( (string) ( $fallback['alt_text'] ?? '' ) );
        $fallback_title = trim( (string) ( $fallback['title'] ?? '' ) );
        if ( $placeholder_url === '' || $fallback_source_url === '' ) {
            return;
        }

        $image->setAttribute( 'src', $placeholder_url );
        $image->setAttribute( 'alt', $fallback_alt_text !== '' ? $fallback_alt_text : 'Missing imported image' );
        if ( $fallback_title !== '' ) {
            $image->setAttribute( 'title', $fallback_title );
        }

        $parent = $image->parentNode;
        if ( $parent instanceof \DOMElement && strtolower( $parent->tagName ) === 'a' ) {
            $parent->setAttribute( 'href', $fallback_source_url );
            if ( $fallback_title !== '' ) {
                $parent->setAttribute( 'title', $fallback_title );
            }
            return;
        }

        $link = $document->createElement( 'a' );
        $link->setAttribute( 'href', $fallback_source_url );
        if ( $fallback_title !== '' ) {
            $link->setAttribute( 'title', $fallback_title );
        }
        if ( $parent instanceof \DOMNode ) {
            $parent->replaceChild( $link, $image );
            $link->appendChild( $image );
        }
    }

    protected function importAttachmentById( string $attachment_id, string $author_slug, array $attachments, array $allowed_hosts ) : ?array {
        $attachment_id = trim( $attachment_id );
        $attachment = $attachments[$attachment_id] ?? null;
        if ( ! is_array( $attachment ) ) {
            return( null );
        }

        return( $this->importAttachmentRecord( $attachment, $author_slug, $allowed_hosts, '' ) );
    }

    protected function importAttachmentByUrl( string $source_url, string $author_slug, array $attachments, array $allowed_hosts, string $alt_text = '' ) : ?array {
        $normalized_source_url = $this->resolveWordPressSourceUrl(
            trim( html_entity_decode( $source_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
            $allowed_hosts
        );
        if ( $normalized_source_url === '' ) {
            return( null );
        }

        $attachment = $this->findAttachmentRecordByUrl( $normalized_source_url, $attachments );
        if ( is_array( $attachment ) ) {
            return( $this->importAttachmentRecord( $attachment, $author_slug, $allowed_hosts, $alt_text, $normalized_source_url ) );
        }

        if ( $this->isAllowedDirectWordPressUploadUrl( $normalized_source_url, $allowed_hosts ) ) {
            return( $this->importDirectWordPressUploadUrl( $normalized_source_url, $author_slug, $allowed_hosts, $alt_text ) );
        }

        return( null );
    }

    protected function resolveWordPressSourceUrl( string $source_url, array $allowed_hosts ) : string {
        $source_url = trim( $source_url );
        if ( $source_url === '' ) {
            return( '' );
        }

        $host = strtolower( trim( (string) parse_url( $source_url, PHP_URL_HOST ) ) );
        $path = trim( (string) parse_url( $source_url, PHP_URL_PATH ) );
        if ( $host !== '' ) {
            return( $source_url );
        }
        if ( $path === '' || ! preg_match( '#^/?(?:wp-content/uploads|uploads)/#i', $path ) ) {
            return( $source_url );
        }

        $allowed_host = strtolower( trim( (string) ( $allowed_hosts[0] ?? '' ) ) );
        if ( $allowed_host === '' ) {
            return( $source_url );
        }

        return( 'https://' . $allowed_host . '/' . ltrim( $path, '/' ) );
    }

    protected function isAllowedDirectWordPressUploadUrl( string $source_url, array $allowed_hosts ) : bool {
        $host = strtolower( trim( (string) parse_url( $source_url, PHP_URL_HOST ) ) );
        if ( $host === '' || ! in_array( $host, $allowed_hosts, true ) ) {
            return( false );
        }

        $path = strtolower( trim( (string) parse_url( $source_url, PHP_URL_PATH ) ) );
        if ( $path === '' ) {
            return( false );
        }

        return( str_contains( $path, '/wp-content/uploads/' ) || str_starts_with( $path, '/uploads/' ) );
    }

    protected function importDirectWordPressUploadUrl( string $source_url, string $author_slug, array $allowed_hosts, string $alt_text = '' ) : ?array {
        if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
            return( null );
        }

        try {
            $import_result = $this->media_import_bridge->importRemoteUrl(
                'wordpress-import',
                $author_slug,
                $source_url,
                [
                    'source_key' => 'direct-upload:' . sha1( $source_url ),
                    'source_id' => $source_url,
                    'source_url' => $source_url,
                    'original_filename' => basename( (string) parse_url( $source_url, PHP_URL_PATH ) ),
                    'alt_text' => $alt_text,
                    'allowed_hosts' => $allowed_hosts,
                ]
            );
        } catch ( \Throwable ) {
            return( null );
        }

        $media = is_array( $import_result['media'] ?? null ) ? $import_result['media'] : null;
        return( is_array( $media ) ? $media : null );
    }

    protected function findAttachmentRecordByUrl( string $source_url, array $attachments ) : ?array {
        $normalized_source_url = $this->normalizeMatchableSourceUrl( $source_url );
        if ( $normalized_source_url === '' ) {
            return( null );
        }

        $fallback_match = null;
        foreach ( $attachments as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $candidates = [ $attachment ];
            $variants = is_array( $attachment['variants'] ?? null ) ? $attachment['variants'] : [];
            foreach ( $variants as $variant ) {
                if ( is_array( $variant ) ) {
                    $candidates[] = $variant;
                }
            }

            foreach ( $candidates as $candidate ) {
                $candidate_url = trim( (string) ( $candidate['url'] ?? '' ) );
                if ( $candidate_url === '' ) {
                    continue;
                }

                if ( $this->normalizeMatchableSourceUrl( $candidate_url ) === $normalized_source_url ) {
                    return( $candidate );
                }

                if ( $fallback_match === null && $this->sourceUrlsMatch( $candidate_url, $source_url ) ) {
                    $fallback_match = $candidate;
                }
            }
        }

        return( $fallback_match );
    }

    protected function importAttachmentRecord( array $attachment, string $author_slug, array $allowed_hosts, string $alt_text = '', string $requested_source_url = '' ) : ?array {
        if ( ! $this->media_import_bridge instanceof TinyMashMediaImportBridge ) {
            return( null );
        }

        $source_url = trim( $requested_source_url !== '' ? $requested_source_url : (string) ( $attachment['url'] ?? '' ) );
        if ( $source_url === '' ) {
            return( null );
        }

        try {
            $import_result = $this->media_import_bridge->importRemoteUrl(
                'wordpress-import',
                $author_slug,
                $source_url,
                [
                    'source_key' => 'attachment:' . sha1( (string) ( $attachment['attachment_id'] ?? '' ) . '|' . $source_url ),
                    'source_id' => (string) ( $attachment['attachment_id'] ?? '' ),
                    'source_url' => $source_url,
                    'original_filename' => basename( (string) parse_url( $source_url, PHP_URL_PATH ) ),
                    'alt_text' => $alt_text !== '' ? $alt_text : (string) ( $attachment['title'] ?? '' ),
                    'allowed_hosts' => $allowed_hosts,
                ]
            );
        } catch ( \Throwable ) {
            return( null );
        }

        $media = is_array( $import_result['media'] ?? null ) ? $import_result['media'] : null;
        return( is_array( $media ) ? $media : null );
    }

    protected function appendUnresolvedMediaRecord( array $records, array $record ) : array {
        $url = trim( (string) ( $record['url'] ?? '' ) );
        if ( $url === '' ) {
            return( $records );
        }

        $slug = trim( (string) ( $record['slug'] ?? '' ) );
        foreach ( $records as $existing_record ) {
            if ( ! is_array( $existing_record ) ) {
                continue;
            }

            if ( (string) ( $existing_record['url'] ?? '' ) === $url && (string) ( $existing_record['slug'] ?? '' ) === $slug ) {
                return( $records );
            }
        }

        $records[] = [
            'title' => trim( (string) ( $record['title'] ?? '' ) ),
            'slug' => $slug,
            'entry_type' => trim( (string) ( $record['entry_type'] ?? '' ) ),
            'url' => $url,
        ];

        if ( count( $records ) > 50 ) {
            $records = array_slice( $records, 0, 50 );
        }

        return( $records );
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

    protected function convertHtmlToMarkdown( string $html ) : string {
        $html = trim( $html );
        if ( $html === '' ) {
            return( '' );
        }

        $document = $this->loadHtmlFragmentDocument( $html, 'tm-wp-markdown-root' );
        if ( ! $document instanceof \DOMDocument ) {
            $markdown = strip_tags( $html );
        } else {
            $root = $document->getElementById( 'tm-wp-markdown-root' );
            if ( ! $root instanceof \DOMElement ) {
                $markdown = strip_tags( $html );
            } else {
                $markdown = $this->renderChildNodesToMarkdown( $root );
            }
        }

        if ( ! is_string( $markdown ) || $markdown === '' ) {
            return( '' );
        }

        $markdown = str_replace( "\r\n", "\n", $markdown );
        $markdown = str_replace( "\r", "\n", $markdown );
        $markdown = preg_replace( "/^[ \t]*``[ \t]*\n(?=^[ \t]*```[^\n]*$)/m", '', $markdown ) ?? $markdown;
        $markdown = preg_replace( '/^[ \t]+$/m', '', $markdown ) ?? $markdown;
        $markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown ) ?? $markdown;
        return( function_exists( 'mb_trim' ) ? mb_trim( $markdown ) : trim( $markdown ) );
    }

    protected function renderChildNodesToMarkdown( \DOMNode $node, int $list_depth = 0 ) : string {
        $markdown = '';
        $children = iterator_to_array( $node->childNodes );
        $child_count = count( $children );
        for ( $index = 0; $index < $child_count; $index++ ) {
            $child_node = $children[$index];
            if ( $child_node instanceof \DOMElement && strtolower( $child_node->tagName ) === 'code' ) {
                $code_block = $this->renderConsecutiveCodeBlockNodes( $children, $index, $node );
                if ( is_array( $code_block ) ) {
                    $markdown .= (string) ( $code_block['markdown'] ?? '' );
                    $index = (int) ( $code_block['last_index'] ?? $index );
                    continue;
                }
            }

            $markdown .= $this->renderNodeToMarkdown( $child_node, $list_depth );
        }

        return( $markdown );
    }

    protected function renderConsecutiveCodeBlockNodes( array $children, int $start_index, \DOMNode $parent_node ) : ?array {
        if ( ! $this->canRenderCodeRunAsBlock( $parent_node ) ) {
            return( null );
        }

        $lines = [];
        $last_index = $start_index - 1;
        $saw_code = false;
        for ( $index = $start_index, $max = count( $children ); $index < $max; $index++ ) {
            $child_node = $children[$index];
            if ( $child_node instanceof \DOMText ) {
                if ( trim( html_entity_decode( $child_node->wholeText, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === '' ) {
                    $last_index = $index;
                    continue;
                }
                break;
            }

            if ( ! $child_node instanceof \DOMElement || strtolower( $child_node->tagName ) !== 'code' ) {
                break;
            }

            $line = html_entity_decode( $child_node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $line = str_replace( "\xc2\xa0", ' ', $line );
            $line = str_replace( "\u{00A0}", ' ', $line );
            $lines[] = rtrim( str_replace( "\r", '', $line ), "\n" );
            $last_index = $index;
            $saw_code = true;
        }

        if ( ! $saw_code || count( $lines ) < 2 ) {
            return( null );
        }

        return(
            [
                'markdown' => "```\n" . implode( "\n", $lines ) . "\n```\n\n",
                'last_index' => $last_index,
            ]
        );
    }

    protected function canRenderCodeRunAsBlock( \DOMNode $parent_node ) : bool {
        if ( ! $parent_node instanceof \DOMElement ) {
            return( true );
        }

        return( ! in_array( strtolower( $parent_node->tagName ), [ 'p', 'li', 'a', 'span', 'strong', 'b', 'em', 'i', 'code', 'pre' ], true ) );
    }

    protected function renderNodeToMarkdown( \DOMNode $node, int $list_depth = 0 ) : string {
        if ( $node instanceof \DOMText ) {
            $text = html_entity_decode( $node->wholeText, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $text = str_replace( "\xc2\xa0", ' ', $text );
            $text = str_replace( "\u{00A0}", ' ', $text );
            return( preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text );
        }

        if ( ! $node instanceof \DOMElement ) {
            return( '' );
        }

        $tag_name = strtolower( $node->tagName );
        $content = $this->renderChildNodesToMarkdown( $node, $list_depth );

        return match ( $tag_name ) {
            'p' => $this->renderParagraphToMarkdown( $content ),
            'br' => "\n",
            'strong', 'b' => '**' . $this->trimMarkdownText( $content ) . '**',
            'em', 'i' => '*' . $this->trimMarkdownText( $content ) . '*',
            'code' => '`' . trim( $node->textContent ) . '`',
            'pre' => "```\n" . rtrim( $node->textContent ) . "\n```\n\n",
            'blockquote' => $this->renderBlockquoteToMarkdown( $content ),
            'a' => $this->renderLinkToMarkdown( $node, $content ),
            'img' => $this->renderImageToMarkdown( $node, $this->isStandaloneImageNode( $node ) ),
            'h1' => '# ' . $this->trimMarkdownText( $content ) . "\n\n",
            'h2' => '## ' . $this->trimMarkdownText( $content ) . "\n\n",
            'h3' => '### ' . $this->trimMarkdownText( $content ) . "\n\n",
            'h4' => '#### ' . $this->trimMarkdownText( $content ) . "\n\n",
            'h5' => '##### ' . $this->trimMarkdownText( $content ) . "\n\n",
            'h6' => '###### ' . $this->trimMarkdownText( $content ) . "\n\n",
            'ul' => $this->renderListToMarkdown( $node, false, $list_depth ),
            'ol' => $this->renderListToMarkdown( $node, true, $list_depth ),
            'li' => $this->trimMarkdownText( $content ),
            'hr' => "---\n\n",
            default => $content,
        };
    }

    protected function renderLinkToMarkdown( \DOMElement $node, string $content ) : string {
        $href = trim( (string) $node->getAttribute( 'href' ) );
        $label = $this->trimMarkdownText( $content );
        $title = trim( html_entity_decode( (string) $node->getAttribute( 'title' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $href === '' ) {
            return( $label );
        }

        if ( $node->childNodes->length === 1 ) {
            $first_child = $node->firstChild;
            if (
                $first_child instanceof \DOMElement
                && strtolower( $first_child->tagName ) === 'img'
                && $this->sourceUrlsMatch( $href, (string) $first_child->getAttribute( 'src' ) )
            ) {
                return( $this->renderImageToMarkdown( $first_child, $this->isStandaloneImageNode( $node ) ) );
            }
        }

        if ( $label === '' ) {
            $label = $href;
        }

        return( '[' . $label . '](' . $href . $this->renderMarkdownTitleSuffix( $title ) . ')' );
    }

    protected function renderImageToMarkdown( \DOMElement $node, bool $standalone = false ) : string {
        $src = trim( (string) $node->getAttribute( 'src' ) );
        if ( $src === '' ) {
            return( '' );
        }

        $alt = $this->trimMarkdownText( html_entity_decode( (string) $node->getAttribute( 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $title = trim( html_entity_decode( (string) $node->getAttribute( 'title' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $markdown = '![' . str_replace( [ '[', ']' ], [ '\[', '\]' ], $alt ) . '](' . $src . $this->renderMarkdownTitleSuffix( $title ) . ')';
        return( $standalone ? ( $markdown . "\n\n" ) : $markdown );
    }

    protected function renderMarkdownTitleSuffix( string $title ) : string {
        $title = trim( $title );
        if ( $title === '' ) {
            return( '' );
        }

        return( ' "' . str_replace( '"', '\"', $title ) . '"' );
    }

    protected function renderBlockquoteToMarkdown( string $content ) : string {
        $content = $this->trimMarkdownText( $content );
        if ( $content === '' ) {
            return( '' );
        }

        $lines = preg_split( "/\n/u", $content ) ?: [];
        $lines = array_map( static fn( string $line ) : string => '> ' . $line, $lines );
        return( implode( "\n", $lines ) . "\n\n" );
    }

    protected function renderListToMarkdown( \DOMElement $node, bool $ordered, int $list_depth = 0 ) : string {
        $output = '';
        $index = 1;
        foreach ( $node->childNodes as $child_node ) {
            if ( ! $child_node instanceof \DOMElement || strtolower( $child_node->tagName ) !== 'li' ) {
                continue;
            }

            $prefix = $ordered ? (string) $index . '. ' : '- ';
            $indent = str_repeat( '  ', $list_depth );
            $item_markdown = $this->renderListItemToMarkdown( $child_node, $list_depth + 1 );
            if ( $item_markdown === '' ) {
                $index++;
                continue;
            }

            $lines = preg_split( "/\n/u", $item_markdown ) ?: [];
            $first_line = array_shift( $lines );
            $output .= $indent . $prefix . $first_line . "\n";
            foreach ( $lines as $line ) {
                if ( $line === '' ) {
                    continue;
                }
                $output .= $indent . '  ' . $line . "\n";
            }
            $index++;
        }

        return( $output !== '' ? rtrim( $output ) . "\n\n" : '' );
    }

    protected function renderListItemToMarkdown( \DOMElement $node, int $list_depth ) : string {
        $parts = [];
        foreach ( $node->childNodes as $child_node ) {
            if ( $child_node instanceof \DOMElement && in_array( strtolower( $child_node->tagName ), [ 'ul', 'ol' ], true ) ) {
                $parts[] = rtrim( $this->renderListToMarkdown( $child_node, strtolower( $child_node->tagName ) === 'ol', $list_depth ) );
                continue;
            }

            $parts[] = $this->renderNodeToMarkdown( $child_node, $list_depth );
        }

        $markdown = implode( '', $parts );
        $markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown ) ?? $markdown;
        return( $this->trimMarkdownText( $markdown ) );
    }

    protected function trimMarkdownText( string $value ) : string {
        $value = str_replace( "\r\n", "\n", $value );
        $value = str_replace( "\r", "\n", $value );
        $value = str_replace( "\xc2\xa0", ' ', $value );
        $value = str_replace( "\u{00A0}", ' ', $value );
        $value = preg_replace( "/[ \t]+\n/u", "\n", $value ) ?? $value;
        $value = preg_replace( "/\n{3,}/", "\n\n", $value ) ?? $value;
        return( function_exists( 'mb_trim' ) ? mb_trim( $value ) : trim( $value ) );
    }

    protected function isStandaloneImageNode( \DOMNode $node ) : bool {
        $parent = $node->parentNode;
        if ( ! $parent instanceof \DOMElement ) {
            return( true );
        }

        $parent_tag = strtolower( $parent->tagName );
        return( ! in_array( $parent_tag, [ 'p', 'span', 'strong', 'b', 'em', 'i', 'code' ], true ) );
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
        $content = preg_replace( '/```.*?```/su', ' ', $content ) ?? $content;
        $content = preg_replace( '/`[^`]*`/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\(?https?:\/\/[^\s)]+\)?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}#{1,6}\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/[*_~#>]+/u', ' ', $content ) ?? $content;
        $content = strip_tags( $content );
        return( $this->normalizeText( $content ) );
    }

    protected function resolveAttachmentVariants( \SimpleXMLElement $wp, string $attachment_url, string $attachment_id, string $attachment_title = '' ) : array {
        $attachment_url = trim( $attachment_url );
        if ( $attachment_url === '' ) {
            return( [] );
        }

        $metadata = $this->extractWordPressAttachmentMetadata( $wp );
        $sizes = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : [];
        if ( empty( $sizes ) ) {
            return( [] );
        }

        $base_directory = rtrim( dirname( $attachment_url ), '/' );
        $variants = [];
        foreach ( $sizes as $size_name => $size_data ) {
            if ( ! is_array( $size_data ) ) {
                continue;
            }

            $variant_file = trim( (string) ( $size_data['file'] ?? '' ) );
            if ( $variant_file === '' ) {
                continue;
            }

            $variants[] = [
                'attachment_id' => $attachment_id,
                'url' => $base_directory . '/' . ltrim( $variant_file, '/' ),
                'title' => $this->normalizeText( $attachment_title ),
                'source_id' => $attachment_id,
                'original_filename' => $variant_file,
                'size_name' => is_string( $size_name ) ? trim( $size_name ) : '',
                'width' => max( 0, (int) ( $size_data['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $size_data['height'] ?? 0 ) ),
            ];
        }

        return( $variants );
    }

    protected function extractWordPressAttachmentMetadata( \SimpleXMLElement $wp ) : array {
        foreach ( $wp->postmeta as $postmeta ) {
            if ( trim( (string) $postmeta->meta_key ) !== '_wp_attachment_metadata' ) {
                continue;
            }

            $serialized = (string) $postmeta->meta_value;
            if ( $serialized === '' ) {
                break;
            }

            try {
                $metadata = @ unserialize( $serialized, [ 'allowed_classes' => false ] );
            } catch ( \Throwable ) {
                $metadata = false;
            }

            return( is_array( $metadata ) ? $metadata : [] );
        }

        return( [] );
    }

    protected function normalizeText( string $text ) : string {
        $text = str_replace( "\xc2\xa0", ' ', $text );
        $text = str_replace( "\u{00A0}", ' ', $text );
        $text = function_exists( 'mb_trim' ) ? mb_trim( $text ) : trim( $text );
        if ( $text === '' ) {
            return( '' );
        }

        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
        return( function_exists( 'mb_trim' ) ? mb_trim( $text ) : trim( $text ) );
    }

    protected function renderParagraphToMarkdown( string $content ) : string {
        $content = $this->trimMarkdownText( $content );
        if ( $content === '' ) {
            return( '' );
        }

        $content = preg_replace(
            '/^((?:!\[[^\]]*\]\([^)]+\)|\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)))(?=\S)/u',
            "$1\n\n",
            $content
        ) ?? $content;

        return( $content . "\n\n" );
    }

    protected function loadHtmlFragmentDocument( string $html, string $root_id ) : ?\DOMDocument {
        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="' . $root_id . '">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );

        return( $loaded ? $document : null );
    }

    protected function sourceUrlsMatch( string $left_url, string $right_url ) : bool {
        $left_matchable = $this->normalizeMatchableSourceUrl( $left_url );
        $right_matchable = $this->normalizeMatchableSourceUrl( $right_url );
        if ( $left_matchable === '' || $right_matchable === '' ) {
            return( false );
        }

        if ( $left_matchable === $right_matchable ) {
            return( true );
        }

        return( $this->stripWordPressSizeSuffix( $left_matchable ) === $this->stripWordPressSizeSuffix( $right_matchable ) );
    }

    protected function normalizeMatchableSourceUrl( string $url ) : string {
        $url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $url === '' ) {
            return( '' );
        }

        $host = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ) ) );
        $path = trim( (string) parse_url( $url, PHP_URL_PATH ) );
        if ( $host === '' || $path === '' ) {
            return( $url );
        }

        return( $host . $path );
    }

    protected function stripWordPressSizeSuffix( string $value ) : string {
        return( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $value ) ?? $value );
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

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'The WordPress import preview directory could not be created.' );
        }

        $encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        if ( @ file_put_contents( $filename, $encoded . PHP_EOL, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'The WordPress import preview could not be written.' );
        }
    }

    protected function generateToken() : string {
        return( 'wordpress_' . gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 ) );
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
                    'unresolved_media' => max( 0, (int) ( $state['counts']['unresolved_media'] ?? 0 ) ),
                    'deleted_existing_posts' => max( 0, (int) ( $state['counts']['deleted_existing_posts'] ?? 0 ) ),
                    'deleted_existing_pages' => max( 0, (int) ( $state['counts']['deleted_existing_pages'] ?? 0 ) ),
                    'total' => $total_entries,
                ],
                'batch' => [
                    'processed' => max( 0, (int) ( $batch_counts['processed'] ?? 0 ) ),
                    'imported' => max( 0, (int) ( $batch_counts['imported'] ?? 0 ) ),
                    'skipped' => max( 0, (int) ( $batch_counts['skipped'] ?? 0 ) ),
                    'skipped_duplicate' => max( 0, (int) ( $batch_counts['skipped_duplicate'] ?? 0 ) ),
                    'skipped_invalid' => max( 0, (int) ( $batch_counts['skipped_invalid'] ?? 0 ) ),
                    'unresolved_media' => max( 0, (int) ( $batch_counts['unresolved_media'] ?? 0 ) ),
                    'deleted_existing_posts' => max( 0, (int) ( $batch_counts['deleted_existing_posts'] ?? 0 ) ),
                    'deleted_existing_pages' => max( 0, (int) ( $batch_counts['deleted_existing_pages'] ?? 0 ) ),
                ],
                'progress_percent' => $total_entries > 0 ? (int) floor( ( $processed_entries / $total_entries ) * 100 ) : 100,
                'updated_at_utc' => (string) ( $state['updated_at_utc'] ?? '' ),
                'finished_at_utc' => (string) ( $state['finished_at_utc'] ?? '' ),
                'unresolved_media' => array_values( array_filter( is_array( $state['unresolved_media'] ?? null ) ? $state['unresolved_media'] : [], 'is_array' ) ),
            ]
        );
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
