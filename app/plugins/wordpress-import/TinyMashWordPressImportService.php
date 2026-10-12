<?php

use app\classes\TinyMashContentRepository;
use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashEmbedService;
use app\classes\TinyMashFilesystem;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashShortcodeRegistry;

require_once dirname( __DIR__, 2 ) . '/classes/TinyMashFilesystem.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashEmbedService.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashShortcodeRegistry.php';
require_once dirname( __DIR__ ) . '/comments/TinyMashCommentsService.php';

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
    protected TinyMashEmbedService $embed_service;
    protected ?TinyMashCommentsService $comments_service;
    protected TinyMashShortcodeRegistry $shortcode_registry;

    public function __construct( string $workspace_directory, TinyMashContentRepository $content_repository, ?TinyMashConfig $config = null, ?TinyMashConfigIO $config_io = null, ?TinyMashMediaImportBridge $media_import_bridge = null, ?TinyMashImportLockService $import_lock_service = null, ?TinyMashEmbedService $embed_service = null, ?TinyMashCommentsService $comments_service = null, ?TinyMashShortcodeRegistry $shortcode_registry = null ) {
        $this->workspace_directory = rtrim( $workspace_directory, DIRECTORY_SEPARATOR );
        $this->content_repository = $content_repository;
        $this->config = $config;
        $this->config_io = $config_io;
        $this->media_import_bridge = $media_import_bridge;
        $this->import_lock_service = $import_lock_service;
        $this->embed_service = $embed_service ?? new TinyMashEmbedService();
        $this->comments_service = $comments_service;
        $this->shortcode_registry = $shortcode_registry ?? new TinyMashShortcodeRegistry();
    }

    public function isAvailable() : bool {
        return(
            class_exists( '\SimpleXMLElement' )
            && function_exists( 'simplexml_load_string' )
        );
    }

    public function createPreviewFromUpload( array $file, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, bool $replace_existing_pages = false, bool $skip_unpublished = false, bool $import_menus = false, bool $import_comments = false, bool $preserve_approved_comments = false, bool $retain_commenter_emails = false, string $target_scope = 'author' ) : array {
        if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
            throw new \RuntimeException( 'A WordPress export file is required.' );
        }

        $xml = @ file_get_contents( $file['tmp_name'] );
        if ( ! is_string( $xml ) || trim( $xml ) === '' ) {
            throw new \RuntimeException( 'The WordPress export file could not be read.' );
        }

        return( $this->createPreviewFromXml( $xml, $author_slug, $aggregate_to_root, $created_by_username, $replace_existing_posts, $replace_existing_pages, $skip_unpublished, $import_menus, $import_comments, $preserve_approved_comments, $retain_commenter_emails, $target_scope ) );
    }

    public function createPreviewFromXml( string $xml, string $author_slug, bool $aggregate_to_root = false, string $created_by_username = '', bool $replace_existing_posts = false, bool $replace_existing_pages = false, bool $skip_unpublished = false, bool $import_menus = false, bool $import_comments = false, bool $preserve_approved_comments = false, bool $retain_commenter_emails = false, string $target_scope = 'author' ) : array {
        if ( ! in_array( $target_scope, [ 'author', 'root' ], true ) ) {
            throw new \InvalidArgumentException( 'target_scope' );
        }
        $author_slug = $target_scope === 'root' ? 'root' : $author_slug;
        $preview = $this->parseXml( $xml, $author_slug, $target_scope === 'root' || $aggregate_to_root, $skip_unpublished, $import_menus, $import_comments && $retain_commenter_emails, $target_scope );
        $preview['token'] = $this->generateToken();
        $preview['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        $preview['created_by_username'] = $this->normalizeAuthorSlug( $created_by_username !== '' ? $created_by_username : $author_slug );
        $preview['replace_existing_posts'] = $replace_existing_posts;
        $preview['replace_existing_pages'] = $replace_existing_pages;
        $preview['skip_unpublished'] = $skip_unpublished;
        $preview['import_menus'] = $import_menus;
        $preview['import_comments'] = $import_comments;
        $preview['preserve_approved_comments'] = $import_comments && $preserve_approved_comments;
        $preview['retain_commenter_emails'] = $import_comments && $retain_commenter_emails;

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

        $target_scope = (string) ( $preview['target_scope'] ?? 'author' );
        if ( ! in_array( $target_scope, [ 'author', 'root' ], true ) || ( $target_scope === 'root' && (string) ( $preview['author_slug'] ?? '' ) !== 'root' ) ) {
            throw new \RuntimeException( 'The WordPress import target is invalid.' );
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
            'target_scope' => $target_scope,
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
                'comments_imported' => 0,
                'comments_approved' => 0,
                'comments_pending' => 0,
                'comments_spam' => 0,
                'comments_trash' => 0,
                'comments_skipped_duplicate' => 0,
                'comments_skipped_limit' => 0,
                'comments_skipped_relationship' => 0,
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
            $counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : [];
            $next_offset = max( 0, (int) ( $state['next_offset'] ?? 0 ) );
            $batch_counts = [ 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'skipped_duplicate' => 0, 'skipped_invalid' => 0, 'unresolved_media' => 0, 'deleted_existing_posts' => 0, 'deleted_existing_pages' => 0 ];
            $unresolved_media = is_array( $state['unresolved_media'] ?? null ) ? $state['unresolved_media'] : [];
            $post_comment_entry_ids = [];
            $page_comment_entry_ids = [];

            if ( ! empty( $state['replace_existing_posts'] ) && empty( $state['existing_posts_deleted'] ) ) {
                $post_comment_entry_ids = $this->prepareReplacementCommentEntries( $state, 'post' );
            }
            if ( ! empty( $state['replace_existing_pages'] ) && empty( $state['existing_pages_deleted'] ) ) {
                $page_comment_entry_ids = $this->prepareReplacementCommentEntries( $state, 'page' );
            }

            if ( ! empty( $state['replace_existing_posts'] ) && empty( $state['existing_posts_deleted'] ) ) {
                $deleted = (string) ( $state['target_scope'] ?? 'author' ) === 'root'
                    ? $this->content_repository->deleteRootPosts()
                    : $this->content_repository->deletePostsByAuthorSlug( (string) ( $state['author_slug'] ?? '' ) );
                foreach ( $post_comment_entry_ids as $entry_id ) {
                    $this->comments_service?->clearEntryCommentsForReplacement( $entry_id, $this->getCommentScope( $state ) );
                }
                $deleted_count = (int) ( $deleted['deleted_posts'] ?? 0 );
                $counts['deleted_existing_posts'] += $deleted_count;
                $batch_counts['deleted_existing_posts'] += $deleted_count;
                $state['existing_posts_deleted'] = true;
                unset( $state['replacement_post_comment_entry_ids'] );
                $state['counts'] = $counts;
                $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
            }
            if ( ! empty( $state['replace_existing_pages'] ) && empty( $state['existing_pages_deleted'] ) ) {
                $deleted = (string) ( $state['target_scope'] ?? 'author' ) === 'root'
                    ? $this->content_repository->deleteRootPages()
                    : $this->content_repository->deletePagesByAuthorSlug( (string) ( $state['author_slug'] ?? '' ) );
                foreach ( $page_comment_entry_ids as $entry_id ) {
                    $this->comments_service?->clearEntryCommentsForReplacement( $entry_id, $this->getCommentScope( $state ) );
                }
                $deleted_count = (int) ( $deleted['deleted_pages'] ?? 0 );
                $counts['deleted_existing_pages'] += $deleted_count;
                $batch_counts['deleted_existing_pages'] += $deleted_count;
                $state['existing_pages_deleted'] = true;
                unset( $state['replacement_page_comment_entry_ids'] );
                $state['counts'] = $counts;
                $this->writeJsonFile( $this->getImportStateFilename( $token ), $state );
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
                    $source_id = (string) ( $entry['source_id'] ?? '' );
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
                    if ( ! empty( $preview['import_comments'] ) && $this->comments_service instanceof TinyMashCommentsService ) {
                        $comment_records = is_array( $preview['comments_by_entry_source_id'][$source_id] ?? null )
                            ? $preview['comments_by_entry_source_id'][$source_id]
                            : [];
                        if ( $comment_records !== [] ) {
                            $comment_result = $this->comments_service->importComments(
                                (string) ( $saved_entry['id'] ?? '' ),
                                $this->getCommentScope( $state ),
                                'wordpress',
                                $comment_records,
                                ! empty( $preview['preserve_approved_comments'] ),
                                ! empty( $preview['retain_commenter_emails'] )
                            );
                            $this->mergeImportedCommentResultCounts( $counts, (array) ( $comment_result['counts'] ?? [] ) );
                        }
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

    protected function parseXml( string $xml, string $author_slug, bool $aggregate_to_root = false, bool $skip_unpublished = false, bool $import_menus = false, bool $retain_commenter_emails = false, string $target_scope = 'author' ) : array {
        if ( ! $this->isAvailable() ) {
            throw new \RuntimeException( 'The WordPress import runtime is not available. SimpleXML is required.' );
        }

        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \RuntimeException( 'A valid target author is required for WordPress import.' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $xml_feed = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );

        if ( ! $xml_feed instanceof \SimpleXMLElement ) {
            $message = ! empty( $errors[0]->message ) ? mb_trim( $errors[0]->message ) : 'The WordPress export XML could not be parsed.';
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
        $attachments_by_parent = [];
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
                'parent_source_id' => trim( (string) $wp->post_parent ),
                'menu_order' => (int) $wp->menu_order,
                'mime' => strtolower( trim( (string) $wp->post_mime_type ) ),
            ];
            $parent_source_id = $attachments[$attachment_id]['parent_source_id'];
            if ( $parent_source_id !== '' ) {
                $attachments_by_parent[$parent_source_id][] = $attachment_id;
            }
        }

        $entries = [];
        $unpublished_entries = [];
        $entries_by_source_id = [];
        $seen_entry_keys = [];
        $adjusted_source_duplicates = 0;
        $adjusted_source_conflicts = [];
        $embed_report = $this->embed_service->createImportReport();
        $media_shortcode_report = [ 'galleries_found' => 0, 'galleries_planned' => 0, 'captions_found' => 0, 'captions_converted' => 0, 'unsupported' => 0, 'items' => [] ];
        $text_shortcode_report = [ 'code_found' => 0, 'code_converted' => 0, 'highlight_found' => 0, 'highlight_converted' => 0, 'wikipedia_found' => 0, 'wikipedia_converted' => 0, 'items' => [] ];
        $unsupported_shortcode_report = [ 'occurrences' => 0, 'affected_entries' => 0, 'items' => [], 'items_truncated' => false ];
        $comments_by_entry_source_id = [];
        $comment_counts = $this->getDefaultCommentImportCounts();
        $comment_issues = [];
        $counts = [
            'posts' => 0,
            'pages' => 0,
            'drafts' => 0,
            'skipped_unpublished' => 0,
            'attachments' => count( $attachments ),
            'total' => 0,
        ];

        foreach ( $channel->item as $item ) {
            $parsed_comments = $this->parseWordPressComments( $item, $wp_namespace );
            $comment_counts['total'] += (int) ( $parsed_comments['counts']['total'] ?? 0 );
            $comment_counts['unsupported'] += (int) ( $parsed_comments['counts']['unsupported'] ?? 0 );
            $parsed_entry = $this->parseWordPressItem( $item, $author_slug, $aggregate_to_root, $wp_namespace, $content_namespace, $excerpt_namespace, $target_scope );
            if ( ! is_array( $parsed_entry ) ) {
                $comment_counts['orphaned_entry'] += count( (array) ( $parsed_comments['records'] ?? [] ) );
                continue;
            }

            $adjusted = false;
            $adjusted_conflict = [];
            $parsed_entry = $this->ensureUniqueSourceEntrySlug( $parsed_entry, $seen_entry_keys, $adjusted, $adjusted_conflict );
            $this->prepareWordPressMediaShortcodes( $parsed_entry, $attachments, $attachments_by_parent, $media_shortcode_report );
            $this->prepareWordPressTextShortcodes( $parsed_entry, $text_shortcode_report );
            if ( $adjusted ) {
                $adjusted_source_duplicates++;
                if ( ! empty( $adjusted_conflict ) ) {
                    $adjusted_source_conflicts[] = $adjusted_conflict;
                }
            }

            $embed_conversion = is_array( $parsed_entry['_embed_conversion'] ?? null ) ? $parsed_entry['_embed_conversion'] : [];
            unset( $parsed_entry['_embed_conversion'] );
            $embed_report = $this->embed_service->mergeImportReport( $embed_report, $embed_conversion, $parsed_entry );

            if ( $parsed_entry['status'] !== 'published' ) {
                $counts['drafts']++;
                $unpublished_entries[] = $parsed_entry;
                if ( $skip_unpublished ) {
                    $counts['skipped_unpublished']++;
                    $comment_counts['orphaned_entry'] += count( (array) ( $parsed_comments['records'] ?? [] ) );
                    continue;
                }
            }

            $source_id = (string) ( $parsed_entry['source_id'] ?? '' );
            $raw_comments = is_array( $parsed_comments['records'] ?? null ) ? $parsed_comments['records'] : [];
            if ( $raw_comments !== [] && $source_id !== '' && $this->comments_service instanceof TinyMashCommentsService ) {
                $comment_preview = $this->comments_service->previewImportedComments( 'wordpress', $raw_comments, $retain_commenter_emails );
                $prepared_comments = is_array( $comment_preview['records'] ?? null ) ? $comment_preview['records'] : [];
                if ( $prepared_comments !== [] ) {
                    $comments_by_entry_source_id[$source_id] = $prepared_comments;
                }
                $this->mergeCommentImportCounts( $comment_counts, (array) ( $comment_preview['counts'] ?? [] ) );
                foreach ( (array) ( $comment_preview['issues'] ?? [] ) as $issue ) {
                    if ( is_array( $issue ) && count( $comment_issues ) < 25 ) {
                        $issue['entry_title'] = (string) ( $parsed_entry['title'] ?? '' );
                        $comment_issues[] = $issue;
                    }
                }
            } elseif ( $raw_comments !== [] ) {
                $comment_counts['malformed'] += count( $raw_comments );
            }

            $this->appendUnsupportedShortcodeReport( $parsed_entry, $unsupported_shortcode_report );
            $entries[] = $parsed_entry;
            if ( $source_id !== '' ) {
                $entries_by_source_id[$source_id] = [
                    'entry_type' => (string) ( $parsed_entry['entry_type'] ?? '' ),
                    'scope' => (string) ( $parsed_entry['scope'] ?? 'author' ),
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
                'target_scope' => $target_scope,
                'created_by_username' => '',
                'aggregate_to_root' => $aggregate_to_root,
                'allowed_remote_hosts' => array_values( array_unique( array_filter( $allowed_remote_hosts, static fn( string $host ) : bool => $host !== '' ) ) ),
                'attachments' => $attachments,
                'attachment_count' => count( $attachments ),
                'adjusted_source_duplicates' => $adjusted_source_duplicates,
                'adjusted_source_conflicts' => $adjusted_source_conflicts,
                'embeds' => $embed_report,
                'media_shortcodes' => $media_shortcode_report,
                'text_shortcodes' => $text_shortcode_report,
                'unsupported_shortcodes' => $unsupported_shortcode_report,
                'comments' => [
                    'counts' => $comment_counts,
                    'issues' => $comment_issues,
                    'issues_truncated' => count( $comment_issues ) < $comment_counts['malformed'] + $comment_counts['orphaned'] + $comment_counts['duplicate_source'],
                ],
                'comments_by_entry_source_id' => $comments_by_entry_source_id,
                'entries' => $entries,
                'unpublished_entries' => $unpublished_entries,
                'menus' => $menus,
                'import_menus' => $import_menus,
                'counts' => $counts,
            ]
        );
    }

    protected function parseWordPressItem( \SimpleXMLElement $item, string $author_slug, bool $aggregate_to_root, string $wp_namespace, string $content_namespace, string $excerpt_namespace, string $target_scope = 'author' ) : ?array {
        $wp = $item->children( $wp_namespace );
        $content = $item->children( $content_namespace );
        $excerpt = $item->children( $excerpt_namespace );

        $post_type = strtolower( trim( (string) $wp->post_type ) );
        if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
            return( null );
        }

        $source_id = trim( (string) $wp->post_id );
        $title = $this->normalizeText( (string) $item->title );
        $content_embed_conversion = $this->embed_service->convertImportedHtml( mb_trim( (string) $content->encoded ) );
        $excerpt_embed_conversion = $this->embed_service->convertImportedHtml( mb_trim( (string) $excerpt->encoded ) );
        $embed_conversion = $this->embed_service->mergeImportReport(
            $this->embed_service->mergeImportReport( $this->embed_service->createImportReport(), $content_embed_conversion ),
            $excerpt_embed_conversion
        );
        $content_html = (string) ( $content_embed_conversion['content'] ?? '' );
        $excerpt_html = (string) ( $excerpt_embed_conversion['content'] ?? '' );
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
                'scope' => $target_scope,
                'author_slug' => $target_scope === 'root' ? '' : $author_slug,
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
                '_embed_conversion' => $embed_conversion,
            ]
        );
    }

    protected function prepareWordPressMediaShortcodes( array &$entry, array $attachments, array $attachments_by_parent, array &$report ) : void {
        $html = (string) ( $entry['content_html'] ?? '' );
        $title = (string) ( $entry['title'] ?? '' );
        $slug = (string) ( $entry['slug'] ?? '' );
        $caption_count = preg_match_all( '/\[caption\b[^\]]*\]/i', $html );
        $report['captions_found'] += $caption_count === false ? 0 : $caption_count;
        $converted_before = (int) $report['captions_converted'];
        $html = preg_replace_callback(
            '/\[caption\b([^\]]*)\](.*?)\[\/caption\]/is',
            function( array $match ) use ( &$report, $title, $slug ) : string {
                $inner = (string) $match[2];
                if ( preg_match_all( '/<img\b/i', $inner ) !== 1 || stripos( $inner, '[caption' ) !== false ) {
                    return( $match[0] );
                }

                $attributes = $this->parseWordPressShortcodeAttributes( (string) $match[1] );
                $visible_text = mb_trim( html_entity_decode( strip_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                $caption = $visible_text === '' ? mb_trim( (string) ( $attributes['caption'] ?? '' ) ) : '';
                $caption_html = $caption !== '' ? '<p>' . htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p>' : '';
                $report['captions_converted']++;
                $this->appendMediaShortcodeReportItem( $report, 'caption', 'convert', $title, $slug, 1 );
                return( "\n\n" . mb_trim( $inner ) . $caption_html . "\n\n" );
            },
            $html
        ) ?? $html;
        $report['unsupported'] += max( 0, (int) $caption_count - ( (int) $report['captions_converted'] - $converted_before ) );

        $gallery_count = preg_match_all( '/\[gallery\b([^\]]*)\]/i', $html, $gallery_matches, PREG_OFFSET_CAPTURE );
        $report['galleries_found'] += $gallery_count === false ? 0 : $gallery_count;
        if ( $gallery_count === 1 ) {
            $shortcode = (string) $gallery_matches[0][0][0];
            $offset = (int) $gallery_matches[0][0][1];
            $attributes_raw = (string) $gallery_matches[1][0][0];
            $attributes = $this->parseWordPressShortcodeAttributes( $attributes_raw );
            $unparsed = preg_replace( "/([a-z_][a-z0-9_-]*)\\s*=\\s*(?:\"[^\"]*\"|'[^']*')/i", '', $attributes_raw ) ?? $attributes_raw;
            $prefix = mb_trim( substr( $html, 0, $offset ) );
            $suffix = mb_trim( substr( $html, $offset + strlen( $shortcode ) ) );
            $position = $prefix === '' ? 'before_content' : ( $suffix === '' ? 'after_content' : '' );
            $supported_attributes = array_diff( array_keys( $attributes ), [ 'ids', 'columns', 'link' ] ) === [];
            $supported_link = ! isset( $attributes['link'] ) || in_array( strtolower( $attributes['link'] ), [ 'file', 'post', 'none' ], true );
            $ids = [];
            if ( isset( $attributes['ids'] ) ) {
                $raw_ids = preg_replace( '/\s+/', '', $attributes['ids'] ) ?? '';
                if ( preg_match( '/^[0-9]+(?:,[0-9]+)*$/', $raw_ids ) === 1 ) {
                    $ids = explode( ',', $raw_ids );
                }
            } else {
                $ids = $attachments_by_parent[(string) ( $entry['source_id'] ?? '' )] ?? [];
                usort( $ids, static function( string $left, string $right ) use ( $attachments ) : int {
                    $order = (int) ( $attachments[$left]['menu_order'] ?? 0 ) <=> (int) ( $attachments[$right]['menu_order'] ?? 0 );
                    return( $order !== 0 ? $order : (int) $left <=> (int) $right );
                } );
            }

            $ids = array_values( array_unique( $ids ) );
            $valid_images = $ids !== [] && count( $ids ) <= 100;
            foreach ( $ids as $id ) {
                $attachment = $attachments[$id] ?? null;
                $mime = is_array( $attachment ) ? (string) ( $attachment['mime'] ?? '' ) : '';
                $extension = is_array( $attachment ) ? strtolower( pathinfo( (string) parse_url( (string) ( $attachment['url'] ?? '' ), PHP_URL_PATH ), PATHINFO_EXTENSION ) ) : '';
                if ( ! is_array( $attachment ) || ( ! str_starts_with( $mime, 'image/' ) && ! in_array( $extension, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ], true ) ) ) {
                    $valid_images = false;
                    break;
                }
            }
            if ( $position !== '' && $supported_attributes && $supported_link && mb_trim( $unparsed ) === '' && $valid_images ) {
                $entry['_gallery_import'] = [ 'shortcode' => $shortcode, 'position' => $position, 'attachment_ids' => $ids ];
                $report['galleries_planned']++;
                $this->appendMediaShortcodeReportItem( $report, 'gallery', 'plan', $title, $slug, count( $ids ) );
            } else {
                $report['unsupported']++;
                $this->appendMediaShortcodeReportItem( $report, 'gallery', 'review', $title, $slug, count( $ids ) );
            }
        } elseif ( $gallery_count > 1 ) {
            $report['unsupported'] += $gallery_count;
            $this->appendMediaShortcodeReportItem( $report, 'gallery', 'review', $title, $slug, 0 );
        }

        $entry['content_html'] = $html;
    }

    protected function parseWordPressShortcodeAttributes( string $raw ) : array {
        $attributes = [];
        preg_match_all( "/([a-z_][a-z0-9_-]*)\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)')/i", $raw, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $attributes[strtolower( (string) $match[1] )] = html_entity_decode( (string) ( $match[2] !== '' ? $match[2] : $match[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        return( $attributes );
    }

    protected function appendMediaShortcodeReportItem( array &$report, string $type, string $status, string $title, string $slug, int $images ) : void {
        if ( count( $report['items'] ) >= 25 ) {
            return;
        }
        $report['items'][] = [ 'type' => $type, 'status' => $status, 'title' => $title, 'slug' => $slug, 'images' => $images ];
    }

    protected function prepareWordPressTextShortcodes( array &$entry, array &$report ) : void {
        $html = (string) ( $entry['content_html'] ?? '' );
        if ( $html === '' ) {
            return;
        }

        preg_match_all( '/\[(code|php|highlight|xs_wikipedia)\b[^\]]*\]/i', $html, $openings, PREG_SET_ORDER );
        foreach ( $openings as $opening ) {
            $kind = strtolower( (string) ( $opening[1] ?? '' ) );
            $key = match ( $kind ) {
                'highlight' => 'highlight_found',
                'xs_wikipedia' => 'wikipedia_found',
                default => 'code_found',
            };
            $report[$key]++;
        }

        $title = (string) ( $entry['title'] ?? '' );
        $slug = (string) ( $entry['slug'] ?? '' );
        $entry['content_html'] = preg_replace_callback(
            '/\[(code|php|highlight|xs_wikipedia)\](.*?)\[\/\1\]|\[xs_wikipedia\s+word\s*=\s*(["\'])(.*?)\3\]/is',
            function( array $match ) use ( &$report, $title, $slug ) : string {
                $kind = ! empty( $match[3] ) ? 'xs_wikipedia' : strtolower( (string) $match[1] );
                $inner = ! empty( $match[3] ) ? (string) $match[4] : (string) $match[2];
                if ( in_array( $kind, [ 'highlight', 'xs_wikipedia' ], true ) && preg_match( '/<[^>]*>|\[(?:code|php|highlight|xs_wikipedia)\b/i', $inner ) === 1 ) {
                    return( $match[0] );
                }

                if ( $kind === 'xs_wikipedia' ) {
                    $word = mb_trim( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    if ( $word === '' ) {
                        return( $match[0] );
                    }
                    $report['wikipedia_converted']++;
                    $replacement = htmlspecialchars( $word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                } elseif ( $kind === 'highlight' ) {
                    $report['highlight_converted']++;
                    $replacement = '<strong>' . htmlspecialchars( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</strong>';
                } else {
                    $report['code_converted']++;
                    $language = $kind === 'php' ? ' data-tm-wp-language="php"' : '';
                    $replacement = "\n\n<pre" . $language . '>' . htmlspecialchars( html_entity_decode( trim( $inner, "\r\n" ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . "</pre>\n\n";
                }
                if ( count( $report['items'] ) < 25 ) {
                    $report['items'][] = [ 'type' => $kind, 'title' => $title, 'slug' => $slug ];
                }
                return( $replacement );
            },
            $html
        ) ?? $html;
    }

    protected function appendUnsupportedShortcodeReport( array $entry, array &$report ) : void {
        $html = (string) ( $entry['content_html'] ?? '' );
        if ( ! str_contains( $html, '[' ) ) {
            return;
        }

        $gallery = is_array( $entry['_gallery_import'] ?? null ) ? $entry['_gallery_import'] : [];
        if ( $gallery !== [] ) {
            $html = str_replace( (string) ( $gallery['shortcode'] ?? '' ), '', $html );
        }
        $names = [];
        foreach ( $this->shortcode_registry->scanShortcodeCandidates( $this->convertHtmlToMarkdown( $html ) ) as $candidate ) {
            $name = (string) ( $candidate['name'] ?? '' );
            if ( $name === '' || $name === 'embed' || ! empty( $candidate['supported'] ) ) {
                continue;
            }
            $report['occurrences']++;
            $names[$name] = (int) ( $names[$name] ?? 0 ) + 1;
        }
        if ( $names === [] ) {
            return;
        }

        $report['affected_entries']++;
        if ( count( $report['items'] ) >= 50 ) {
            $report['items_truncated'] = true;
        }
        ksort( $names, SORT_STRING );
        $report['items'][] = [
            'title' => (string) ( $entry['title'] ?? '' ),
            'slug' => (string) ( $entry['slug'] ?? '' ),
            'entry_type' => (string) ( $entry['entry_type'] ?? '' ),
            'status' => (string) ( $entry['status'] ?? '' ),
            'names' => $names,
        ];
    }

    protected function mapWordPressStatus( string $status ) : string {
        return( strtolower( trim( $status ) ) === 'publish' ? 'published' : 'unpublished' );
    }

    protected function parseWordPressComments( \SimpleXMLElement $item, string $wp_namespace ) : array {
        $wp = $item->children( $wp_namespace );
        $records = [];
        $counts = [ 'total' => 0, 'unsupported' => 0 ];
        foreach ( $wp->comment as $comment ) {
            $counts['total']++;
            $comment_type = strtolower( trim( (string) $comment->comment_type ) );
            if ( $comment_type !== '' && $comment_type !== 'comment' ) {
                $counts['unsupported']++;
                continue;
            }

            $status = match( strtolower( trim( (string) $comment->comment_approved ) ) ) {
                '1', 'approve', 'approved' => 'approved',
                '0', 'hold', 'pending', 'unapproved' => 'pending',
                'spam' => 'spam',
                'trash', 'post-trashed' => 'trash',
                default => '',
            };
            if ( $status === '' ) {
                $counts['unsupported']++;
                continue;
            }

            $parent_source_id = trim( (string) $comment->comment_parent );
            if ( $parent_source_id === '0' ) {
                $parent_source_id = '';
            }
            $records[] = [
                'source_id' => trim( (string) $comment->comment_id ),
                'parent_source_id' => $parent_source_id,
                'author_name' => (string) $comment->comment_author,
                'email' => (string) $comment->comment_author_email,
                'body' => (string) $comment->comment_content,
                'body_format' => 'html',
                'status' => $status,
                'created_at_utc' => $this->normalizeTimestamp( (string) $comment->comment_date_gmt ),
            ];
        }

        return( [ 'records' => $records, 'counts' => $counts ] );
    }

    protected function getDefaultCommentImportCounts() : array {
        return(
            [
                'total' => 0,
                'importable' => 0,
                'approved' => 0,
                'pending' => 0,
                'spam' => 0,
                'trash' => 0,
                'malformed' => 0,
                'orphaned' => 0,
                'orphaned_entry' => 0,
                'duplicate_source' => 0,
                'unsupported' => 0,
                'truncated' => 0,
                'emails_retained' => 0,
                'emails_discarded' => 0,
            ]
        );
    }

    protected function prepareReplacementCommentEntries( array &$state, string $entry_type ) : array {
        $state_key = 'replacement_' . $entry_type . '_comment_entry_ids';
        if ( ! array_key_exists( $state_key, $state ) ) {
            $entry_ids = [];
            if ( $this->comments_service instanceof TinyMashCommentsService ) {
                $author_slug = (string) ( $state['author_slug'] ?? '' );
                $target_scope = (string) ( $state['target_scope'] ?? 'author' );
                foreach ( $this->content_repository->getEntriesForAudit( null, $target_scope === 'root' ? null : $author_slug, $target_scope === 'root', false ) as $entry ) {
                    if (
                        (string) ( $entry['scope'] ?? '' ) === $target_scope
                        && ( $target_scope === 'root' || (string) ( $entry['author_slug'] ?? '' ) === $author_slug )
                        && (string) ( $entry['type'] ?? '' ) === $entry_type
                    ) {
                        $entry_ids[] = (string) ( $entry['id'] ?? '' );
                    }
                }
            }
            $state[$state_key] = $entry_ids;
            $this->writeJsonFile( $this->getImportStateFilename( (string) $state['token'] ), $state );
        }

        $entry_ids = $state[$state_key];
        if ( ! is_array( $entry_ids ) ) {
            throw new \RuntimeException( 'WordPress replacement comment state is invalid.' );
        }
        if ( $this->comments_service instanceof TinyMashCommentsService ) {
            $scope = $this->getCommentScope( $state );
            foreach ( $entry_ids as $entry_id ) {
                if ( ! is_string( $entry_id ) ) {
                    throw new \RuntimeException( 'WordPress replacement comment state is invalid.' );
                }
                $this->comments_service->assertEntryCommentsCanBeReplaced( $entry_id, $scope );
            }
        }
        return( $entry_ids );
    }

    protected function getCommentScope( array $state ) : string {
        return( (string) ( $state['target_scope'] ?? 'author' ) === 'root' ? 'root' : 'author:' . (string) ( $state['author_slug'] ?? '' ) );
    }

    protected function mergeCommentImportCounts( array &$counts, array $addition ) : void {
        foreach ( array_keys( $this->getDefaultCommentImportCounts() ) as $key ) {
            if ( $key === 'total' || $key === 'unsupported' || $key === 'orphaned_entry' ) {
                continue;
            }
            $counts[$key] = (int) ( $counts[$key] ?? 0 ) + (int) ( $addition[$key] ?? 0 );
        }
    }

    protected function mergeImportedCommentResultCounts( array &$counts, array $addition ) : void {
        $mapping = [
            'comments_imported' => 'imported',
            'comments_approved' => 'imported_approved',
            'comments_pending' => 'imported_pending',
            'comments_spam' => 'imported_spam',
            'comments_trash' => 'imported_trash',
            'comments_skipped_duplicate' => 'skipped_duplicate',
            'comments_skipped_limit' => 'skipped_limit',
            'comments_skipped_relationship' => 'skipped_relationship',
        ];
        foreach ( $mapping as $target => $source ) {
            $counts[$target] = (int) ( $counts[$target] ?? 0 ) + (int) ( $addition[$source] ?? 0 );
        }
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
                        'url' => $this->buildImportedEntryUrl( (string) ( $linked_entry['scope'] ?? 'author' ), $author_slug, $entry_slug ),
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

    protected function buildImportedEntryUrl( string $target_scope, string $author_slug, string $entry_slug ) : string {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $entry_slug = trim( preg_replace( '@/+@', '/', $entry_slug ) ?? '', '/' );
        $path = str_replace( '%2F', '/', rawurlencode( $entry_slug ) );
        return( $target_scope === 'root' ? '/' . $path : '/' . rawurlencode( $author_slug ) . '/' . $path );
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

        $gallery_import = is_array( $entry['_gallery_import'] ?? null ) ? $entry['_gallery_import'] : [];
        if ( $this->media_import_bridge instanceof TinyMashMediaImportBridge && $gallery_import !== [] ) {
            $gallery_media_ids = [];
            $gallery_inline_html = [];
            $gallery_has_missing_media = false;
            foreach ( (array) ( $gallery_import['attachment_ids'] ?? [] ) as $attachment_id ) {
                $media = $this->importAttachmentById( (string) $attachment_id, $author_slug, $attachments, $allowed_hosts );
                $media_id = is_array( $media ) ? trim( (string) ( $media['media_id'] ?? '' ) ) : '';
                $attachment = is_array( $attachments[(string) $attachment_id] ?? null ) ? $attachments[(string) $attachment_id] : [];
                $label = mb_trim( (string) ( $attachment['title'] ?? '' ) );
                if ( $media_id !== '' && ! empty( $media['url'] ) ) {
                    $gallery_media_ids[$media_id] = $media_id;
                    $attached_media_ids[$media_id] = $media_id;
                    $gallery_inline_html[] = '<p><img src="' . htmlspecialchars( (string) ( $media['url'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" alt="' . htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"></p>';
                    continue;
                }
                $gallery_has_missing_media = true;
                $source_url = (string) ( $attachment['url'] ?? '' );
                if ( $source_url !== '' ) {
                    $unresolved_media = $this->appendUnresolvedMediaRecord( $unresolved_media, [ 'url' => $source_url ] );
                }
                $source_url = $this->resolveWordPressSourceUrl( $source_url, $allowed_hosts );
                if ( $this->isAllowedDirectWordPressUploadUrl( $source_url, $allowed_hosts ) ) {
                    $fallback = $this->media_import_bridge->buildMissingImportedMediaFallback( $source_url, $label );
                    $gallery_inline_html[] = '<p><a href="' . htmlspecialchars( $source_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"><img src="' . htmlspecialchars( (string) $fallback['placeholder_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" alt="' . htmlspecialchars( (string) $fallback['alt_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" title="' . htmlspecialchars( (string) $fallback['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"></a></p>';
                } else {
                    $gallery_inline_html[] = '<p>Missing gallery image.</p>';
                }
            }
            if ( $gallery_has_missing_media ) {
                $content_html = str_replace( (string) ( $gallery_import['shortcode'] ?? '' ), implode( "\n", $gallery_inline_html ), $content_html );
            } elseif ( $gallery_media_ids !== [] ) {
                $content_html = str_replace( (string) ( $gallery_import['shortcode'] ?? '' ), '', $content_html );
                $entry['plugin_settings']['photos'] = [
                    'gallery_enabled' => '1',
                    'gallery_media_ids' => implode( "\n", $gallery_media_ids ),
                    'gallery_position' => (string) ( $gallery_import['position'] ?? 'after_content' ),
                ];
            }
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

        unset( $entry['content_html'], $entry['excerpt_html'], $entry['thumbnail_attachment_id'], $entry['_gallery_import'] );
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
            if ( mb_trim( (string) $image->getAttribute( 'alt' ) ) === '' && ! empty( $resolved_media['alt_text'] ) ) {
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

        return( mb_trim( $rewritten_html ) );
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
        $fallback_alt_text = mb_trim( (string) ( $fallback['alt_text'] ?? '' ) );
        $fallback_title = mb_trim( (string) ( $fallback['title'] ?? '' ) );
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
            'title' => mb_trim( (string) ( $record['title'] ?? '' ) ),
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

        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $entry_id = trim( (string) ( $saved_entry['id'] ?? '' ) );
        if ( $author_slug === '' || $entry_id === '' || empty( $attached_media_ids ) ) {
            return;
        }

        $this->media_import_bridge->attachMediaToEntry( $author_slug, $entry_id, array_values( $attached_media_ids ) );
    }

    protected function convertHtmlToMarkdown( string $html ) : string {
        $html = mb_trim( $html );
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
        return( mb_trim( $markdown ) );
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
                if ( mb_trim( html_entity_decode( $child_node->wholeText, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === '' ) {
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
            'code' => $this->renderCodeToMarkdown( $node ),
            'pre' => $this->renderPreToMarkdown( $node ),
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

    protected function renderPreToMarkdown( \DOMElement $node ) : string {
        $content = rtrim( $node->textContent, "\r\n" );
        $language = $node->getAttribute( 'data-tm-wp-language' ) === 'php' ? 'php' : '';
        return( $this->renderFencedCodeToMarkdown( $content, $language ) );
    }

    protected function renderCodeToMarkdown( \DOMElement $node ) : string {
        $content = '';
        $markdown = '';
        foreach ( $node->childNodes as $child_node ) {
            if ( $child_node instanceof \DOMElement && strtolower( $child_node->tagName ) === 'img' ) {
                if ( mb_trim( $content ) !== '' ) {
                    $markdown .= $this->renderCodeTextToMarkdown( $content ) . "\n\n";
                    $content = '';
                }
                $markdown .= $this->renderImageToMarkdown( $child_node, true );
                continue;
            }
            $content .= $this->extractCodeText( $child_node );
        }

        return( $markdown . ( mb_trim( $content ) !== '' ? $this->renderCodeTextToMarkdown( $content ) : '' ) );
    }

    protected function renderCodeTextToMarkdown( string $content ) : string {
        $content = mb_trim( $content );
        if ( str_contains( $content, "\n" ) ) {
            return( "\n\n" . $this->renderFencedCodeToMarkdown( $content ) );
        }

        return( '`' . $content . '`' );
    }

    protected function extractCodeText( \DOMNode $node ) : string {
        if ( $node instanceof \DOMText ) {
            return( $node->wholeText );
        }
        if ( $node instanceof \DOMElement && strtolower( $node->tagName ) === 'br' ) {
            return( "\n" );
        }

        $content = '';
        foreach ( $node->childNodes as $child_node ) {
            $content .= $this->extractCodeText( $child_node );
        }
        return( $content );
    }

    protected function renderFencedCodeToMarkdown( string $content, string $language = '' ) : string {
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );
        $fence_length = 3;
        if ( preg_match_all( '/`+/', $content, $runs ) !== false ) {
            foreach ( $runs[0] as $run ) {
                $fence_length = max( $fence_length, strlen( $run ) + 1 );
            }
        }
        $fence = str_repeat( '`', $fence_length );
        return( $fence . $language . "\n" . $content . "\n" . $fence . "\n\n" );
    }

    protected function renderLinkToMarkdown( \DOMElement $node, string $content ) : string {
        $href = trim( (string) $node->getAttribute( 'href' ) );
        $label = $this->trimMarkdownText( $content );
        $title = mb_trim( html_entity_decode( (string) $node->getAttribute( 'title' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
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
            if (
                $first_child instanceof \DOMElement
                && strtolower( $first_child->tagName ) === 'img'
                && $this->wordPressImageAlignment( $first_child ) !== ''
            ) {
                return( $this->renderImageToMarkdown( $first_child, $this->isStandaloneImageNode( $node ), $href ) );
            }
        }

        if ( $label === '' ) {
            $label = $href;
        }

        return( '[' . $label . '](' . $href . $this->renderMarkdownTitleSuffix( $title ) . ')' );
    }

    protected function renderImageToMarkdown( \DOMElement $node, bool $standalone = false, string $href = '' ) : string {
        $src = trim( (string) $node->getAttribute( 'src' ) );
        if ( $src === '' ) {
            return( '' );
        }

        $alt = $this->trimMarkdownText( html_entity_decode( (string) $node->getAttribute( 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $title = mb_trim( html_entity_decode( (string) $node->getAttribute( 'title' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $alignment = $this->wordPressImageAlignment( $node );
        if ( $alignment !== '' ) {
            $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
            $image = '<img src="' . $escape( $src ) . '" alt="' . $escape( $alt ) . '"';
            if ( $title !== '' ) {
                $image .= ' title="' . $escape( $title ) . '"';
            }
            foreach ( [ 'width', 'height' ] as $dimension ) {
                $value = (string) $node->getAttribute( $dimension );
                if ( preg_match( '/^[1-9][0-9]{0,4}$/', $value ) === 1 ) {
                    $image .= ' ' . $dimension . '="' . $value . '"';
                }
            }
            $image .= '>';
            if ( $href !== '' ) {
                $image = '<a href="' . $escape( $href ) . '">' . $image . '</a>';
            }
            $modifier = $alignment === 'center' ? 'tm-content-image-block' : 'tm-content-image-flow-' . $alignment;
            $markup = '<span class="tm-content-image tm-content-image-' . $alignment . ' ' . $modifier . '">' . $image . '</span>';
            return( $standalone ? ( $markup . "\n\n" ) : $markup );
        }

        $markdown = '![' . str_replace( [ '[', ']' ], [ '\[', '\]' ], $alt ) . '](' . $src . $this->renderMarkdownTitleSuffix( $title ) . ')';
        return( $standalone ? ( $markdown . "\n\n" ) : $markdown );
    }

    protected function wordPressImageAlignment( \DOMElement $node ) : string {
        $class_names = strtolower( (string) $node->getAttribute( 'class' ) );
        if ( preg_match( '/(?:^|\s)align(left|right|center)(?:\s|$)/', $class_names, $match ) !== 1 ) {
            return( '' );
        }
        return( (string) $match[1] );
    }

    protected function renderMarkdownTitleSuffix( string $title ) : string {
        $title = mb_trim( $title );
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
        return( mb_trim( $value ) );
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
        $content = preg_replace( '/`([^`]*)`/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\[Open embedded content\]\([^)]+\)/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\(?https?:\/\/[^\s)]+\)?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}#{1,6}\s+/mu', '', $content ) ?? $content;
        $content = strip_tags( $content );
        $content = preg_replace( '/[*_~#>]+/u', ' ', $content ) ?? $content;
        $content = $this->normalizeText( $content );
        return( preg_replace( '/\s+([,;:!?])/u', '$1', $content ) ?? $content );
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
        $text = mb_trim( $text );
        if ( $text === '' ) {
            return( '' );
        }

        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
        return( mb_trim( $text ) );
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
        $errors = \DateTimeImmutable::getLastErrors();
        if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
            return( '' );
        }

        return( $normalized->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) );
    }

    protected function normalizeSlug( string $value ) : string {
        return( $this->content_repository->normalizeEditorSlug( $value ) );
    }

    protected function normalizeAuthorSlug( string $value ) : string {
        return( $this->content_repository->normalizeAuthorSlug( $value ) );
    }

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'The WordPress import preview directory could not be created.' );
        }

        $encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $encoded . PHP_EOL ) ) {
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
                    'comments_imported' => max( 0, (int) ( $state['counts']['comments_imported'] ?? 0 ) ),
                    'comments_approved' => max( 0, (int) ( $state['counts']['comments_approved'] ?? 0 ) ),
                    'comments_pending' => max( 0, (int) ( $state['counts']['comments_pending'] ?? 0 ) ),
                    'comments_spam' => max( 0, (int) ( $state['counts']['comments_spam'] ?? 0 ) ),
                    'comments_trash' => max( 0, (int) ( $state['counts']['comments_trash'] ?? 0 ) ),
                    'comments_skipped_duplicate' => max( 0, (int) ( $state['counts']['comments_skipped_duplicate'] ?? 0 ) ),
                    'comments_skipped_limit' => max( 0, (int) ( $state['counts']['comments_skipped_limit'] ?? 0 ) ),
                    'comments_skipped_relationship' => max( 0, (int) ( $state['counts']['comments_skipped_relationship'] ?? 0 ) ),
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
