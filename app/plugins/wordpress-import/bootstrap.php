<?php

require_once __DIR__ . '/TinyMashWordPressImportService.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashConfigIO.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashEmbedService;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashImportPreviewWorkspaceService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashShortcodeRegistry;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'wordpress-import' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $user_repository = $plugins->getService( 'user.repository' );
    $media_import_bridge = $plugins->getService( 'media.import_bridge' );
    $embed_service = $plugins->getService( 'embed.service' );
    $shortcode_registry = $plugins->getService( 'shortcode.registry' );
    $import_lock_service = $plugins->getService( 'import.lock_service' );
    $import_preview_workspace_service = $plugins->getService( 'import.preview_workspaces' );
    $config_io = new TinyMashConfigIO();

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_repository instanceof TinyMashContentRepository || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the WordPress import plugin are not available.' );
    }

    $comments_service = new TinyMashCommentsService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'comments'
    );

    $import_service = new TinyMashWordPressImportService(
        $project_root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'plugin-wordpress-import',
        $content_repository,
        $config,
        $config_io,
        $media_import_bridge instanceof TinyMashMediaImportBridge ? $media_import_bridge : null,
        $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service : null,
        $embed_service instanceof TinyMashEmbedService ? $embed_service : null,
        $comments_service,
        $shortcode_registry instanceof TinyMashShortcodeRegistry ? $shortcode_registry : null
    );

    $parse_cli_options = static function( array $arguments ) : array {
        $options = [
            'action' => strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ),
            'source' => (string) ( $arguments[1] ?? '' ),
            'author' => '',
            'root' => false,
            'aggregate_to_root' => false,
            'replace_existing_posts' => false,
            'replace_existing_pages' => false,
            'skip_unpublished' => false,
            'import_menus' => false,
            'batch_size' => 25,
            'memory_limit' => '512M',
            'keep_preview' => false,
            'force_lock' => false,
            'import_comments' => false,
            'preserve_approved_comments' => false,
            'retain_commenter_emails' => false,
        ];

        foreach ( array_slice( $arguments, 2 ) as $argument ) {
            $argument = (string) $argument;
            if ( str_starts_with( $argument, '--author=' ) ) {
                $options['author'] = strtolower( trim( substr( $argument, 9 ) ) );
                continue;
            }
            if ( $argument === '--root' ) {
                $options['root'] = true;
                continue;
            }
            if ( $argument === '--aggregate-root' ) {
                $options['aggregate_to_root'] = true;
                continue;
            }
            if ( $argument === '--replace-existing-posts' || $argument === '--replace' ) {
                $options['replace_existing_posts'] = true;
                continue;
            }
            if ( $argument === '--replace-existing-pages' ) {
                $options['replace_existing_pages'] = true;
                continue;
            }
            if ( $argument === '--skip-unpublished' ) {
                $options['skip_unpublished'] = true;
                continue;
            }
            if ( $argument === '--import-menus' ) {
                $options['import_menus'] = true;
                continue;
            }
            if ( str_starts_with( $argument, '--batch-size=' ) ) {
                $options['batch_size'] = max( 1, min( 100, (int) substr( $argument, 13 ) ) );
                continue;
            }
            if ( str_starts_with( $argument, '--memory-limit=' ) ) {
                $options['memory_limit'] = trim( substr( $argument, 15 ) );
                continue;
            }
            if ( $argument === '--keep-preview' ) {
                $options['keep_preview'] = true;
                continue;
            }
            if ( $argument === '--force-lock' ) {
                $options['force_lock'] = true;
                continue;
            }
            if ( $argument === '--import-comments' ) {
                $options['import_comments'] = true;
                continue;
            }
            if ( $argument === '--preserve-approved-comments' ) {
                $options['preserve_approved_comments'] = true;
                continue;
            }
            if ( $argument === '--retain-commenter-emails' ) {
                $options['retain_commenter_emails'] = true;
                continue;
            }

            throw new \RuntimeException( 'Unknown option "' . $argument . '".' );
        }

        return( $options );
    };

    $write_preview_summary = static function( array $preview ) : void {
        $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
        fwrite( STDOUT, 'preview_token: ' . (string) ( $preview['token'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'author: ' . (string) ( $preview['author_slug'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'target_scope: ' . (string) ( $preview['target_scope'] ?? 'author' ) . PHP_EOL );
        fwrite( STDOUT, 'entries: ' . (int) ( $counts['total'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'posts: ' . (int) ( $counts['posts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'pages: ' . (int) ( $counts['pages'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'attachments: ' . (int) ( $counts['attachments'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'menus: ' . (int) ( $counts['menus'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'menu_items: ' . (int) ( $counts['menu_items'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'unpublished: ' . (int) ( $counts['drafts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'skipped_unpublished: ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'adjusted_source_duplicates: ' . (int) ( $preview['adjusted_source_duplicates'] ?? 0 ) . PHP_EOL );
        $embeds = is_array( $preview['embeds'] ?? null ) ? $preview['embeds'] : [];
        fwrite( STDOUT, 'embeds_recognized: ' . (int) ( $embeds['recognized'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'embeds_converted: ' . (int) ( $embeds['converted'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'embeds_unsupported: ' . (int) ( $embeds['unsupported'] ?? 0 ) . PHP_EOL );
        $media_shortcodes = is_array( $preview['media_shortcodes'] ?? null ) ? $preview['media_shortcodes'] : [];
        fwrite( STDOUT, 'galleries_planned: ' . (int) ( $media_shortcodes['galleries_planned'] ?? 0 ) . '/' . (int) ( $media_shortcodes['galleries_found'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'captions_converted: ' . (int) ( $media_shortcodes['captions_converted'] ?? 0 ) . '/' . (int) ( $media_shortcodes['captions_found'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'media_shortcodes_unsupported: ' . (int) ( $media_shortcodes['unsupported'] ?? 0 ) . PHP_EOL );
        $text_shortcodes = is_array( $preview['text_shortcodes'] ?? null ) ? $preview['text_shortcodes'] : [];
        fwrite( STDOUT, 'code_examples_converted: ' . (int) ( $text_shortcodes['code_converted'] ?? 0 ) . '/' . (int) ( $text_shortcodes['code_found'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'highlights_converted: ' . (int) ( $text_shortcodes['highlight_converted'] ?? 0 ) . '/' . (int) ( $text_shortcodes['highlight_found'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'wikipedia_words_preserved: ' . (int) ( $text_shortcodes['wikipedia_converted'] ?? 0 ) . '/' . (int) ( $text_shortcodes['wikipedia_found'] ?? 0 ) . PHP_EOL );
        $unsupported_shortcodes = is_array( $preview['unsupported_shortcodes'] ?? null ) ? $preview['unsupported_shortcodes'] : [];
        fwrite( STDOUT, 'shortcodes_needing_review: ' . (int) ( $unsupported_shortcodes['occurrences'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'entries_needing_shortcode_review: ' . (int) ( $unsupported_shortcodes['affected_entries'] ?? 0 ) . PHP_EOL );
        foreach ( (array) ( $unsupported_shortcodes['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $names = [];
            foreach ( (array) ( $item['names'] ?? [] ) as $name => $count ) {
                $names[] = $name . ' (' . (int) $count . ')';
            }
            fwrite( STDOUT, 'shortcode_review: ' . (string) ( $item['entry_type'] ?? '' ) . '/' . (string) ( $item['slug'] ?? '' ) . ' ' . implode( ', ', $names ) . PHP_EOL );
        }
        $comments = is_array( $preview['comments']['counts'] ?? null ) ? $preview['comments']['counts'] : [];
        fwrite( STDOUT, 'comments_found: ' . (int) ( $comments['total'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'comments_importable: ' . (int) ( $comments['importable'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'comments_orphaned: ' . ( (int) ( $comments['orphaned'] ?? 0 ) + (int) ( $comments['orphaned_entry'] ?? 0 ) ) . PHP_EOL );
        fwrite( STDOUT, 'comments_unsupported: ' . (int) ( $comments['unsupported'] ?? 0 ) . PHP_EOL );
        $recognized_locations = is_array( $preview['menus']['recognized_locations'] ?? null ) ? $preview['menus']['recognized_locations'] : [];
        foreach ( $recognized_locations as $location => $menu_slug ) {
            if ( ! is_string( $location ) || ! is_string( $menu_slug ) || $location === '' || $menu_slug === '' ) {
                continue;
            }
            fwrite( STDOUT, 'recognized_menu_' . $location . ': ' . $menu_slug . PHP_EOL );
        }
    };

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'wordpress-import',
                'summary' => 'Preview or import a WordPress WXR export into root or an author space.',
                'arguments' => [
                    [ 'name' => 'action', 'required' => true, 'description' => 'Preview the export or import it', 'choices' => [ 'preview', 'import' ] ],
                    [ 'name' => 'wxr-file', 'required' => true, 'description' => 'Readable WordPress WXR export file' ],
                ],
                'options' => [
                    [ 'name' => 'author', 'value' => true, 'description' => 'Local author account; choose either this or --root' ],
                    [ 'name' => 'root', 'description' => 'Import into the root site instead of an author space' ],
                    [ 'name' => 'aggregate-root', 'description' => 'Also include imported posts in the root blogroll' ],
                    [ 'name' => 'replace-existing-posts', 'description' => 'Delete target posts and their comments before import' ],
                    [ 'name' => 'replace-existing-pages', 'description' => 'Delete target pages and their comments before import' ],
                    [ 'name' => 'skip-unpublished', 'description' => 'Skip source entries that are not published' ],
                    [ 'name' => 'import-menus', 'description' => 'Import recognized WordPress menus' ],
                    [ 'name' => 'import-comments', 'description' => 'Import comments mapped to imported entries' ],
                    [ 'name' => 'preserve-approved-comments', 'description' => 'Keep approved source comments approved' ],
                    [ 'name' => 'retain-commenter-emails', 'description' => 'Keep valid source commenter e-mail addresses' ],
                    [ 'name' => 'batch-size', 'value' => true, 'integer' => true, 'min' => 1, 'max' => 100, 'default' => 25, 'description' => 'Process this many entries per batch' ],
                    [ 'name' => 'memory-limit', 'value' => true, 'default' => '512M', 'pattern' => '/^(?:-1|\d+[KMG]?)$/i', 'pattern_message' => 'The --memory-limit option must be -1 or a number with an optional K, M, or G suffix.', 'description' => 'Set the PHP memory limit for the import' ],
                    [ 'name' => 'keep-preview', 'description' => 'Keep preview files after a completed import' ],
                    [ 'name' => 'force-lock', 'description' => 'Replace a stale import lock for this author' ],
                ],
                'examples' => [
                    'php8.4 bin/tinymash.php wordpress-import preview export.xml --author=cajo',
                    'php8.4 bin/tinymash.php wordpress-import import export.xml --author=cajo --import-menus',
                    'php8.4 bin/tinymash.php wordpress-import preview export.xml --root',
                ],
                'notes' => 'Choose exactly one target. Author accounts may be inactive, but must exist. Preview a new source file before import.',
                'order' => 220,
                'handler' => static function( array $arguments ) use ( $parse_cli_options, $write_preview_summary, $import_service, $import_preview_workspace_service, $plugin_key, $user_repository ) : void {
                    $options = $parse_cli_options( $arguments );
                    $action = (string) ( $options['action'] ?? '' );
                    $source_filename = trim( (string) ( $options['source'] ?? '' ) );
                    if ( $source_filename === '' || ! is_file( $source_filename ) || ! is_readable( $source_filename ) ) {
                        throw new \RuntimeException( 'The WordPress export file could not be read.' );
                    }

                    $author_slug = strtolower( trim( (string) ( $options['author'] ?? '' ) ) );
                    $target_root = ! empty( $options['root'] );
                    if ( $target_root === ( $author_slug !== '' ) ) {
                        throw new \RuntimeException( 'Choose exactly one target: --root or --author=<username>.' );
                    }
                    if ( ! $target_root && ! is_array( $user_repository->getUserByUsername( $author_slug ) ) ) {
                        throw new \RuntimeException( 'A valid --author=<username> is required for WordPress import.' );
                    }
                    $author_slug = $target_root ? 'root' : $author_slug;

                    $memory_limit = trim( (string) ( $options['memory_limit'] ?? '512M' ) );
                    if ( $memory_limit !== '' ) {
                        @ini_set( 'memory_limit', $memory_limit );
                    }
                    @ini_set( 'max_execution_time', '0' );

                    $xml = @ file_get_contents( $source_filename );
                    if ( ! is_string( $xml ) || trim( $xml ) === '' ) {
                        throw new \RuntimeException( 'The WordPress export file could not be read.' );
                    }

                    $preview = $import_service->createPreviewFromXml(
                        $xml,
                        $author_slug,
                        ! empty( $options['aggregate_to_root'] ),
                        $author_slug,
                        ! empty( $options['replace_existing_posts'] ),
                        ! empty( $options['replace_existing_pages'] ),
                        ! empty( $options['skip_unpublished'] ),
                        ! empty( $options['import_menus'] ),
                        ! empty( $options['import_comments'] ),
                        ! empty( $options['preserve_approved_comments'] ),
                        ! empty( $options['retain_commenter_emails'] ),
                        $target_root ? 'root' : 'author'
                    );

                    $write_preview_summary( $preview );
                    $token = (string) ( $preview['token'] ?? '' );
                    if ( ! empty( $options['keep_preview'] ) ) {
                        if ( ! $import_preview_workspace_service instanceof TinyMashImportPreviewWorkspaceService || ! $import_preview_workspace_service->retainPreview( $plugin_key, $token ) ) {
                            throw new \RuntimeException( 'The WordPress import preview could not be marked for retention.' );
                        }
                    }
                    if ( $action === 'preview' ) {
                        return;
                    }

                    $batch_size = max( 1, min( 100, (int) ( $options['batch_size'] ?? 25 ) ) );
                    do {
                        $batch_result = $import_service->importPreviewBatch( $token, $batch_size, ! empty( $options['force_lock'] ) );
                        $counts = is_array( $batch_result['counts'] ?? null ) ? $batch_result['counts'] : [];
                        fwrite(
                            STDOUT,
                            'progress: '
                            . (int) ( $counts['processed'] ?? 0 )
                            . '/'
                            . (int) ( $counts['total'] ?? 0 )
                            . ' imported='
                            . (int) ( $counts['imported'] ?? 0 )
                            . ' skipped='
                            . (int) ( $counts['skipped'] ?? 0 )
                            . PHP_EOL
                        );
                    } while ( empty( $batch_result['done'] ) );

                    fwrite( STDOUT, 'deleted_existing_posts: ' . (int) ( $counts['deleted_existing_posts'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'deleted_existing_pages: ' . (int) ( $counts['deleted_existing_pages'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'imported: ' . (int) ( $counts['imported'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped: ' . (int) ( $counts['skipped'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped_duplicate: ' . (int) ( $counts['skipped_duplicate'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped_invalid: ' . (int) ( $counts['skipped_invalid'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'comments_imported: ' . (int) ( $counts['comments_imported'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'comments_pending: ' . (int) ( $counts['comments_pending'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'unresolved_media: ' . (int) ( $counts['unresolved_media'] ?? 0 ) . PHP_EOL );
                    if ( (int) ( $preview['unsupported_shortcodes']['occurrences'] ?? 0 ) > 0 ) {
                        fwrite( STDOUT, 'review WordPress plugin shortcodes listed above in imported entries; skipped entries were not changed' . PHP_EOL );
                    }
                    $unresolved_media = is_array( $batch_result['unresolved_media'] ?? null ) ? $batch_result['unresolved_media'] : [];
                    if ( ! empty( $unresolved_media ) ) {
                        fwrite( STDOUT, 'unresolved_media_sample:' . PHP_EOL );
                        foreach ( array_slice( $unresolved_media, 0, 10 ) as $item ) {
                            if ( ! is_array( $item ) ) {
                                continue;
                            }
                            fwrite( STDOUT, '- ' . (string) ( $item['slug'] ?? '-' ) . ' -> ' . (string) ( $item['url'] ?? '' ) . PHP_EOL );
                        }
                    }

                    if ( empty( $options['keep_preview'] ) ) {
                        $import_service->deletePreview( $token );
                    }
                },
            ]
        );
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/wordpress-import';
    $preview_url = $plugin_url . '/preview';
    $report_url = $plugin_url . '/report';
    $review_download_url = $plugin_url . '/review-download';
    $import_url = $plugin_url . '/run';
    $unlock_url = $plugin_url . '/unlock';
    $max_execution_time = (int) ini_get( 'max_execution_time' );
    $import_batch_size = match ( true ) {
        $max_execution_time > 0 && $max_execution_time <= 30 => 1,
        $max_execution_time > 0 && $max_execution_time <= 60 => 2,
        $max_execution_time > 0 && $max_execution_time < 120 => 5,
        default => 10,
    };

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'wordpress-import',
            'label' => 'WordPress import',
            'url' => $plugin_url,
            'icon' => 'bi-box-arrow-in-down',
            'group' => 'import',
            'group_label' => 'Import',
            'order' => 76,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-wordpress-import',
        [
            'title' => 'WordPress import',
            'summary' => 'Import posts, pages, and selected comments from a WordPress WXR export.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/wordpress-import',
            'documentation_label' => 'WordPress import',
            'group' => 'Plugins',
            'order' => 121,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Import posts, pages, and selected comments from a WordPress WXR/XML export. Authors target their own space; superadmins may choose root or an author space. Timestamps, status, and available page-parent relationships are preserved.',
                ],
                [
                    'title' => 'Menus',
                    'markdown' => 'Optionally map recognized WordPress menus to the tinymash `primary` and `footer` locations. Matching uses menu names and runs only when menu import is enabled.',
                ],
                [
                    'title' => 'Media',
                    'markdown' => 'Localizes media from exported attachments, common WordPress upload URLs, and reachable source-site files.',
                ],
                [
                    'title' => 'Comments',
                    'markdown' => 'Comment import is optional. Approval and private e-mail retention require separate choices; subscriptions are never inferred.',
                ],
                [
                    'title' => 'Interrupted imports',
                    'markdown' => 'Return to the same preview and choose **Resume import**. If an earlier import blocks a new preview, open that import or release its lock from the preview page.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $render_embed_report = static function( array $preview ) use ( $escape ) : string {
        $embeds = is_array( $preview['embeds'] ?? null ) ? $preview['embeds'] : [];
        $items = is_array( $embeds['items'] ?? null ) ? $embeds['items'] : [];
        if ( (int) ( $embeds['recognized'] ?? 0 ) < 1 && (int) ( $embeds['unsupported'] ?? 0 ) < 1 ) {
            return( '' );
        }

        $html = '<div class="mt-3 py-3 border-top"><div class="small text-uppercase text-body-secondary mb-2">External embeds</div>';
        $html .= '<div class="d-flex flex-wrap gap-3 small text-body-secondary">';
        $html .= '<span>Recognized: <strong class="text-body">' . (int) ( $embeds['recognized'] ?? 0 ) . '</strong></span>';
        $html .= '<span>Converted: <strong class="text-body">' . (int) ( $embeds['converted'] ?? 0 ) . '</strong></span>';
        $html .= '<span>Unsupported: <strong class="text-body">' . (int) ( $embeds['unsupported'] ?? 0 ) . '</strong></span>';
        $html .= '</div>';
        if ( ! empty( $items ) ) {
            $html .= '<div class="table-responsive mt-2" tabindex="0" aria-label="External embed results"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Entry</th><th class="fw-normal">Result</th><th class="fw-normal">Source</th></tr></thead><tbody>';
            foreach ( array_slice( $items, 0, 25 ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $status = match( (string) ( $item['status'] ?? '' ) ) {
                    'converted' => 'Converted to [embed]',
                    'linked' => 'Replaced with link',
                    default => 'Removed',
                };
                $source_url = (string) ( $item['source_url'] ?? '' );
                $html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? '' ) ) . '</div><code>' . $escape( (string) ( $item['slug'] ?? '' ) ) . '</code></td>';
                $html .= '<td class="align-top text-body-secondary">' . $escape( $status ) . '</td>';
                $html .= '<td class="align-top"><code>' . $escape( $source_url !== '' ? $source_url : 'No usable source URL' ) . '</code></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        if ( ! empty( $embeds['items_truncated'] ) || count( $items ) > 25 ) {
            $html .= '<div class="small text-body-secondary mt-2">Showing the first 25 external embeds.</div>';
        }
        return( $html . '</div>' );
    };

    $render_media_shortcode_report = static function( array $preview ) use ( $escape ) : string {
        $report = is_array( $preview['media_shortcodes'] ?? null ) ? $preview['media_shortcodes'] : [];
        $found = (int) ( $report['galleries_found'] ?? 0 ) + (int) ( $report['captions_found'] ?? 0 );
        if ( $found === 0 ) {
            return( '' );
        }
        $html = '<div class="mt-3 py-3 border-top"><div class="small text-uppercase text-body-secondary mb-2">Media shortcodes</div>';
        $html .= '<div class="small text-body-secondary">Galleries planned: <strong class="text-body">' . (int) ( $report['galleries_planned'] ?? 0 ) . '/' . (int) ( $report['galleries_found'] ?? 0 ) . '</strong> · Captions converted: <strong class="text-body">' . (int) ( $report['captions_converted'] ?? 0 ) . '/' . (int) ( $report['captions_found'] ?? 0 ) . '</strong> · Needs review: <strong class="text-body">' . (int) ( $report['unsupported'] ?? 0 ) . '</strong></div>';
        $html .= '<div class="small text-body-secondary mt-1">Gallery conversion needs reachable image files. Missing files remain in the import report.</div>';
        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];
        if ( $items !== [] ) {
            $html .= '<details class="mt-2"><summary class="small">Review media shortcode details</summary><div class="table-responsive mt-2" tabindex="0" aria-label="Media shortcode results"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Entry</th><th class="fw-normal">Source</th><th class="fw-normal">Result</th></tr></thead><tbody>';
            foreach ( array_slice( $items, 0, 25 ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $type = (string) ( $item['type'] ?? '' );
                $status = (string) ( $item['status'] ?? '' );
                $result = $status === 'review' ? 'Needs review' : ( $type === 'gallery' ? 'Native gallery planned' : 'Image and caption preserved' );
                $images = (int) ( $item['images'] ?? 0 );
                $html .= '<tr><td><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? '' ) ) . '</div><code>' . $escape( (string) ( $item['slug'] ?? '' ) ) . '</code></td><td>' . $escape( $type ) . ' · ' . $images . ( $images === 1 ? ' image' : ' images' ) . '</td><td>' . $escape( $result ) . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        if ( $found > count( $items ) ) {
            $html .= '<div class="small text-body-secondary mt-2">Showing the first 25 results.</div>';
        }
        return( $html . '</div>' );
    };

    $render_text_shortcode_report = static function( array $preview ) : string {
        $report = is_array( $preview['text_shortcodes'] ?? null ) ? $preview['text_shortcodes'] : [];
        if ( (int) ( $report['code_found'] ?? 0 ) + (int) ( $report['highlight_found'] ?? 0 ) + (int) ( $report['wikipedia_found'] ?? 0 ) === 0 ) {
            return( '' );
        }
        return( '<div class="mt-3 py-3 border-top"><div class="small text-uppercase text-body-secondary mb-2">Text shortcodes</div><div class="small text-body-secondary">Code examples converted: <strong class="text-body">' . (int) ( $report['code_converted'] ?? 0 ) . '/' . (int) ( $report['code_found'] ?? 0 ) . '</strong> · Highlights converted: <strong class="text-body">' . (int) ( $report['highlight_converted'] ?? 0 ) . '/' . (int) ( $report['highlight_found'] ?? 0 ) . '</strong> · Wikipedia words preserved: <strong class="text-body">' . (int) ( $report['wikipedia_converted'] ?? 0 ) . '/' . (int) ( $report['wikipedia_found'] ?? 0 ) . '</strong></div></div>' );
    };

    $render_unsupported_shortcode_report = static function( array $preview, bool $completed = false ) use ( $escape, $admin_url, $review_download_url ) : string {
        $report = is_array( $preview['unsupported_shortcodes'] ?? null ) ? $preview['unsupported_shortcodes'] : [];
        $occurrences = (int) ( $report['occurrences'] ?? 0 );
        if ( $occurrences === 0 ) {
            return( '' );
        }
        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];
        $html = '<section class="mt-3 py-3 border-top" aria-label="WordPress plugin content needing review">';
        $html .= '<div class="small text-uppercase text-warning-emphasis mb-1">Content needing review</div>';
        $html .= '<div class="small text-body-secondary"><strong class="text-body">' . $occurrences . '</strong> WordPress plugin tag' . ( $occurrences === 1 ? '' : 's' ) . ' in <strong class="text-body">' . (int) ( $report['affected_entries'] ?? 0 ) . '</strong> entr' . ( (int) ( $report['affected_entries'] ?? 0 ) === 1 ? 'y' : 'ies' ) . '. Their output cannot be recreated from this export. ' . ( $completed ? 'Edit or unpublish affected entries.' : 'Plan to edit or unpublish affected entries after import.' ) . '</div>';
        if ( $completed ) {
            $html .= '<div class="small text-body-secondary mt-1">Findings are from the export; skipped entries were not changed.</div>';
        }
        $html .= '<div class="mt-2"><a class="small" href="' . $escape( $review_download_url . '?preview=' . rawurlencode( (string) ( $preview['token'] ?? '' ) ) ) . '">Download full review list</a></div>';
        if ( $items !== [] ) {
            $html .= '<details class="mt-2"' . ( count( $items ) <= 10 ? ' open' : '' ) . '><summary class="small">Show affected entries</summary><div class="table-responsive mt-2" tabindex="0" aria-label="WordPress plugin tags needing review"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Entry</th><th class="fw-normal">Plugin tags</th></tr></thead><tbody>';
            foreach ( array_slice( $items, 0, 50 ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $names = [];
                foreach ( (array) ( $item['names'] ?? [] ) as $name => $count ) {
                    $names[] = '<code>' . $escape( (string) $name ) . '</code> (' . (int) $count . ')';
                }
                $html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? '' ) ) . '</div><code>' . $escape( (string) ( $item['slug'] ?? '' ) ) . '</code> <span class="text-body-secondary small">' . $escape( (string) ( $item['entry_type'] ?? '' ) ) . '</span>';
                if ( $completed ) {
                    $content_query = http_build_query( [ 'entries_owner' => (string) ( $preview['author_slug'] ?? '' ), 'entries_title' => (string) ( $item['title'] ?? '' ), 'entries_type' => (string) ( $item['entry_type'] ?? '' ), 'entries_tree' => '0' ] );
                    $html .= '<div><a class="small" href="' . $escape( $admin_url . '/content?' . $content_query ) . '">Find in Content</a></div>';
                }
                $html .= '</td><td class="align-top">' . implode( ', ', $names ) . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        if ( ! empty( $report['items_truncated'] ) ) {
            $html .= '<div class="small text-body-secondary mt-2">Showing the first 50 affected entries here. The download includes all findings.</div>';
        }
        return( $html . '</section>' );
    };

    $render_comment_report = static function( array $preview, array $import_state = [] ) use ( $escape ) : string {
        $counts = is_array( $preview['comments']['counts'] ?? null ) ? $preview['comments']['counts'] : [];
        $issues = is_array( $preview['comments']['issues'] ?? null ) ? $preview['comments']['issues'] : [];
        $found = (int) ( $counts['total'] ?? 0 );
        $orphaned = (int) ( $counts['orphaned'] ?? 0 ) + (int) ( $counts['orphaned_entry'] ?? 0 );
        $html = '<div class="mt-3 py-3 border-top"><div class="small text-uppercase text-body-secondary mb-2">Comments</div>';
        $html .= '<div class="d-flex flex-wrap gap-3 small text-body-secondary">';
        $html .= '<span>Found: <strong class="text-body">' . $found . '</strong></span>';
        $html .= '<span>Importable: <strong class="text-body">' . (int) ( $counts['importable'] ?? 0 ) . '</strong></span>';
        $html .= '<span>Orphaned: <strong class="text-body">' . $orphaned . '</strong></span>';
        $html .= '<span>Unsupported: <strong class="text-body">' . ( (int) ( $counts['unsupported'] ?? 0 ) + (int) ( $counts['malformed'] ?? 0 ) ) . '</strong></span>';
        $html .= '</div>';
        if ( ! empty( $preview['import_comments'] ) ) {
            $html .= '<div class="small text-body-secondary mt-2">Comments will import. Approved source comments will ' . ( ! empty( $preview['preserve_approved_comments'] ) ? 'stay approved' : 'enter moderation' ) . '. Commenter e-mail addresses will ' . ( ! empty( $preview['retain_commenter_emails'] ) ? 'be retained' : 'be discarded' ) . '.</div>';
        } elseif ( $found > 0 ) {
            $html .= '<div class="small text-body-secondary mt-2">Comments were not selected for import.</div>';
        }
        if ( ! empty( $import_state['counts']['comments_imported'] ) ) {
            $html .= '<div class="small mt-2">Imported: <strong>' . (int) $import_state['counts']['comments_imported'] . '</strong>; pending: <strong>' . (int) ( $import_state['counts']['comments_pending'] ?? 0 ) . '</strong>; approved: <strong>' . (int) ( $import_state['counts']['comments_approved'] ?? 0 ) . '</strong>.</div>';
        }
        if ( $issues !== [] ) {
            $html .= '<div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Source comment</th><th class="fw-normal">Result</th></tr></thead><tbody>';
            foreach ( array_slice( $issues, 0, 25 ) as $issue ) {
                if ( is_array( $issue ) ) {
                    $html .= '<tr><td><code>' . $escape( (string) ( $issue['source_id'] ?? '' ) ) . '</code></td><td class="text-body-secondary">' . $escape( (string) ( $issue['reason'] ?? '' ) ) . '</td></tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
        return( $html . '</div>' );
    };

    $build_notice = static function( string $type, string $message ) : array {
        return(
            [
                'type' => $type,
                'message' => $message,
            ]
        );
    };

    $log_plugin_error = static function( string $context, \Throwable $e ) use ( $plugin_key ) : void {
        error_log( $plugin_key . ': ' . $context . ': ' . $e->getMessage() );
    };

    $emit_json = static function( TinyMashPlugins $plugins, array $payload, int $status_code = 200 ) : void {
        $plugins->setResponseStatus( $status_code );
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=UTF-8' );
        }
        echo json_encode( $plugins->resolveOutputData( $payload ), JSON_UNESCAPED_SLASHES );
    };

    $is_json_request = static function() : bool {
        $requested_with = strtolower( trim( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) ) );
        if ( $requested_with === 'xmlhttprequest' ) {
            return( true );
        }

        $accept = strtolower( trim( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ) );
        return( str_contains( $accept, 'application/json' ) );
    };

    $resolve_target = static function( array $data = [] ) use ( $security, $user_repository ) : array {
        $current_username = strtolower( trim( (string) $security->getCurrentUsername() ) );
        if ( $current_username === '' ) {
            return( [ 'scope' => 'author', 'author_slug' => '' ] );
        }

        if ( ! $security->isSuperAdmin() ) {
            return( [ 'scope' => 'author', 'author_slug' => $current_username ] );
        }

        $candidate = strtolower( trim( (string) ( $data['target_author'] ?? '' ) ) );
        if ( $candidate === '__root__' ) {
            return( [ 'scope' => 'root', 'author_slug' => 'root' ] );
        }
        if ( $candidate === '' ) {
            return( [ 'scope' => 'author', 'author_slug' => $current_username ] );
        }

        $user = $user_repository->getUserByUsername( $candidate );
        return( [ 'scope' => 'author', 'author_slug' => is_array( $user ) ? $candidate : $current_username ] );
    };

    $build_author_options = static function() use ( $user_repository ) : array {
        $options = [];
        foreach ( $user_repository->getAllUsers() as $user ) {
            if ( ! is_array( $user ) ) {
                continue;
            }

            $username = strtolower( trim( (string) ( $user['username'] ?? '' ) ) );
            if ( $username === '' ) {
                continue;
            }

            $display_name = trim( (string) ( $user['display_name'] ?? '' ) );
            $label = $display_name !== '' ? ( $display_name . ' (' . $username . ')' ) : $username;
            if ( empty( $user['active'] ) ) {
                $label .= ' — sign-in disabled';
            }
            $options[] = [
                'username' => $username,
                'label' => $label,
            ];
        }

        return( $options );
    };

    $render_page = static function( string $mode = 'setup', array $notice = [] ) use ( $plugins, $security, $date_formatter, $import_service, $import_lock_service, $plugin_key, $plugin_url, $preview_url, $report_url, $import_url, $unlock_url, $login_url, $escape, $render_embed_report, $render_media_shortcode_report, $render_text_shortcode_report, $render_unsupported_shortcode_report, $render_comment_report, $build_notice, $build_author_options, $max_execution_time, $import_batch_size ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }

        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'wordpress-import.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $username = trim( (string) $security->getCurrentUsername() );
        $is_superadmin = $security->isSuperAdmin();
        $csrf_token = $security->getCsrfToken();
        $runtime_available = $import_service->isAvailable();
        $query = $plugins->getRequestQueryData();
        $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $query['preview'] ?? '' ) ) ) ) ?? '';
        $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
        $author_options = $build_author_options();
        $selected_target_author = strtolower( trim( (string) ( $query['target_author'] ?? $username ) ) );
        $import_state = $preview_token !== '' ? $import_service->getImportState( $preview_token ) : null;
        $timeout_warning = $max_execution_time > 0 && $max_execution_time < 120;
        $active_lock = null;

        if ( is_array( $preview ) && (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== strtolower( $username ) ) {
            $preview = null;
            $import_state = null;
            $notice = $build_notice( 'danger', 'That WordPress import preview is not available for the current account.' );
        }
        if ( is_array( $preview ) && (string) ( $preview['target_scope'] ?? 'author' ) === 'root' && ! $is_superadmin ) {
            $preview = null;
            $import_state = null;
            $notice = $build_notice( 'danger', 'Root imports require a superadmin account.' );
        }
        if ( is_array( $preview ) ) {
            $selected_target_author = (string) ( $preview['target_scope'] ?? 'author' ) === 'root'
                ? '__root__'
                : strtolower( trim( (string) ( $preview['author_slug'] ?? $selected_target_author ) ) );
            if ( $import_lock_service instanceof TinyMashImportLockService ) {
                $lock = $import_lock_service->getAuthorLock( (string) ( $preview['author_slug'] ?? '' ) );
                if ( is_array( $lock ) && (string) ( $lock['token'] ?? '' ) !== (string) ( $preview['token'] ?? '' ) ) {
                    $active_lock = $lock;
                }
            }
        }
        if ( in_array( $mode, [ 'preview', 'report' ], true ) && ! is_array( $preview ) ) {
            $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'The WordPress import preview is missing or expired. Please preview the export again.' ) );
            $plugins->redirect( $plugin_url );
            return;
        }
        if ( $mode === 'preview' && is_array( $import_state ) && (string) ( $import_state['status'] ?? '' ) === 'finished' ) {
            $plugins->redirect( $report_url . '?preview=' . rawurlencode( $preview_token ) );
            return;
        }
        if ( $mode === 'report' && ( ! is_array( $import_state ) || (string) ( $import_state['status'] ?? '' ) !== 'finished' ) ) {
            $plugins->redirect( $preview_url . '?preview=' . rawurlencode( $preview_token ) );
            return;
        }

        $body_html = '<div class="row g-3">';
        if ( ! $runtime_available ) {
            $body_html .= '<div class="col-12"><div class="alert alert-danger mb-0" role="alert">SimpleXML is required for WordPress import in this PHP runtime.</div></div>';
        }

        if ( $mode === 'setup' ) {
            $body_html .= '<div class="col-12">';
            $body_html .= '<section class="">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import source</div>';
            $body_html .= '<h2 class="h5 mb-1">WordPress WXR export</h2>';
            $body_html .= '<p class="text-body-secondary mb-3">Import WordPress posts, pages, and selected comments. Existing entries are never overwritten.</p>';
            $body_html .= '<form method="post" action="' . $escape( $preview_url ) . '" enctype="multipart/form-data" class="row g-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
            if ( $is_superadmin ) {
                $body_html .= '<div class="col-12 col-lg-6">';
                $body_html .= '<label class="form-label" for="wordpress-import-target-author">Import into</label>';
                $body_html .= '<select class="form-select" id="wordpress-import-target-author" name="target_author">';
                $body_html .= '<option value="__root__"' . ( $selected_target_author === '__root__' ? ' selected' : '' ) . '>Root site (/<slug>)</option>';
                foreach ( $author_options as $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $option_username = (string) ( $option['username'] ?? '' );
                    $selected = $option_username === $selected_target_author ? ' selected' : '';
                    $body_html .= '<option value="' . $escape( $option_username ) . '"' . $selected . '>' . $escape( (string) ( $option['label'] ?? $option_username ) ) . '</option>';
                }
                $body_html .= '</select>';
                $body_html .= '</div>';
            } else {
                $body_html .= '<div class="col-12"><div class="small text-body-secondary">Target author space: <span class="font-monospace text-body fw-semibold">' . $escape( strtolower( $username ) ) . '</span></div></div>';
            }
            $body_html .= '<div class="col-12">';
            $body_html .= '<label class="form-label" for="wordpress-import-file">WordPress export file</label>';
            $body_html .= '<input class="form-control" id="wordpress-import-file" name="wordpress_export" type="file" accept=".xml,application/xml,text/xml"' . ( $runtime_available ? '' : ' disabled' ) . '>';
            $body_html .= '<div class="form-text">Use a standard WordPress WXR export file.</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12" id="wordpress-import-aggregate-row"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-aggregate" name="aggregate_to_root" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-aggregate">Also show author-space posts on the root front page</label>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-replace" name="replace_existing_posts" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-replace">Replace current target posts before import</label>';
            $body_html .= '<div class="form-text">Removes these posts and their comments. Pages, drafts, and media stay.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-replace-pages" name="replace_existing_pages" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-replace-pages">Replace current target pages before import</label>';
            $body_html .= '<div class="form-text">Removes these pages and their comments. Posts, drafts, and media stay.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-skip-unpublished" name="skip_unpublished" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-skip-unpublished">Skip unpublished entries from the source export</label>';
            $body_html .= '<div class="form-text">Published entries still import normally. Unpublished source items are counted in the preview but left out of the import set.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-menus" name="import_menus" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-menus">Import recognized WordPress menus</label>';
            $body_html .= '<div class="form-text">When recognized, WordPress navigation menus can replace the tinymash `primary` and `footer` menu locations. This is site-level config.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12 pt-2 border-top"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-comments" name="import_comments" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-comments">Import comments mapped to imported entries</label>';
            $body_html .= '<div class="form-text">Commenter URLs, IP addresses, user-agent data, and reply-notification consent are not imported.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12 col-lg-6"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-comment-approval" name="preserve_approved_comments" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-comment-approval">Keep approved source comments approved</label>';
            $body_html .= '<div class="form-text">Otherwise approved comments enter moderation.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12 col-lg-6"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-comment-emails" name="retain_commenter_emails" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-comment-emails">Retain valid commenter e-mail addresses</label>';
            $body_html .= '<div class="form-text">Addresses stay private and never create subscriptions.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><button class="btn btn-primary" type="submit"' . ( $runtime_available ? '' : ' disabled' ) . '>Preview import</button></div>';
            $body_html .= '</form>';
            if ( $is_superadmin ) {
                $body_html .= '<script>(() => { const target = document.getElementById("wordpress-import-target-author"); const row = document.getElementById("wordpress-import-aggregate-row"); const checkbox = document.getElementById("wordpress-import-aggregate"); if (!target || !row || !checkbox) return; const sync = () => { const isRoot = target.value === "__root__"; row.hidden = isRoot; checkbox.disabled = isRoot; if (isRoot) checkbox.checked = false; }; target.addEventListener("change", sync); sync(); })();</script>';
            }
            if ( $timeout_warning ) {
                $body_html .= '<div class="alert alert-warning mt-3 mb-0" role="alert">This web runtime is limited to ' . $max_execution_time . ' seconds. WordPress import will use ' . $import_batch_size . ' entr' . ( $import_batch_size === 1 ? 'y' : 'ies' ) . ' per request.</div>';
            }
            $body_html .= '</section></div>';

        }

        if ( is_array( $preview ) && $mode === 'preview' ) {
            $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $unpublished_entries = is_array( $preview['unpublished_entries'] ?? null )
                ? array_values( array_filter( $preview['unpublished_entries'], static fn( mixed $entry ) : bool => is_array( $entry ) ) )
                : array_values( array_filter( $entries, static fn( array $entry ) : bool => (string) ( $entry['status'] ?? 'published' ) !== 'published' ) );
            $replace_existing_posts = ! empty( $preview['replace_existing_posts'] );
            $replace_existing_pages = ! empty( $preview['replace_existing_pages'] );
            $skip_unpublished = ! empty( $preview['skip_unpublished'] );
            $import_menus = ! empty( $preview['import_menus'] );
            $preview_post_count = (int) ( $counts['posts'] ?? 0 );
            $preview_page_count = (int) ( $counts['pages'] ?? 0 );
            $available_menus = is_array( $preview['menus']['available'] ?? null ) ? $preview['menus']['available'] : [];
            $recognized_menu_locations = is_array( $preview['menus']['recognized_locations'] ?? null ) ? $preview['menus']['recognized_locations'] : [];
            $import_running = is_array( $import_state ) && (string) ( $import_state['status'] ?? '' ) === 'running';
            $import_finished = is_array( $import_state ) && (string) ( $import_state['status'] ?? '' ) === 'finished';
            $adjusted_source_duplicates = max( 0, (int) ( $preview['adjusted_source_duplicates'] ?? 0 ) );
            $adjusted_source_conflicts = is_array( $preview['adjusted_source_conflicts'] ?? null ) ? $preview['adjusted_source_conflicts'] : [];
            $has_conflicting_lock = is_array( $active_lock );
            $conflicting_lock_is_stale = $has_conflicting_lock && ! empty( $active_lock['is_stale'] );
            $conflicting_lock_updated_display = $has_conflicting_lock
                ? $date_formatter->formatUtcDateTime( (string) ( $active_lock['updated_at_utc'] ?? $active_lock['created_at_utc'] ?? '' ) )
                : '';
            $conflicting_lock_owner = strtolower( trim( (string) ( $active_lock['created_by_username'] ?? '' ) ) );
            $can_release_conflicting_lock = $has_conflicting_lock && ( $is_superadmin || ( $conflicting_lock_owner !== '' && $conflicting_lock_owner === strtolower( $username ) ) );
            $conflicting_preview = $has_conflicting_lock && (string) ( $active_lock['importer_key'] ?? '' ) === $plugin_key
                ? $import_service->getPreview( (string) ( $active_lock['token'] ?? '' ) )
                : null;
            $can_open_conflicting_preview = is_array( $conflicting_preview )
                && (string) ( $conflicting_preview['created_by_username'] ?? $conflicting_preview['author_slug'] ?? '' ) === strtolower( $username );

            $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Start over</a></div>';
            $body_html .= '<section class="">';
            $body_html .= '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3"><div>';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Preview</div>';
            $body_html .= '<h2 class="h5 mb-1">Ready to import</h2>';
            $body_html .= '<div class="text-body-secondary small">This preview targets <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' ? 'Root site (/<slug>)' : (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>.</div>';
            if ( $replace_existing_posts ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Current posts in this target space and their comments will be deleted before import. Pages, drafts, and media stay.</div>';
                if ( $preview_post_count === 0 && $preview_page_count > 0 ) {
                    $body_html .= '<div class="small text-warning-emphasis mt-1">This export contains pages but no posts. Existing target pages stay; duplicate pages will be skipped.</div>';
                }
            }
            if ( $replace_existing_pages ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Current pages in this target space and their comments will be deleted before import. Posts, drafts, and media stay.</div>';
            }
            if ( $skip_unpublished && (int) ( $counts['skipped_unpublished'] ?? 0 ) > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Skipping ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . ' unpublished source entr' . ( (int) ( $counts['skipped_unpublished'] ?? 0 ) === 1 ? 'y' : 'ies' ) . ' for this import.</div>';
            }
            if ( $import_menus ) {
                if ( ! empty( $recognized_menu_locations ) ) {
                    $menu_bits = [];
                    foreach ( $recognized_menu_locations as $location => $menu_slug ) {
                        if ( ! is_string( $location ) || ! is_string( $menu_slug ) || $location === '' || $menu_slug === '' ) {
                            continue;
                        }
                        $menu_bits[] = ucfirst( $location ) . ': ' . $menu_slug;
                    }
                    if ( ! empty( $menu_bits ) ) {
                        $body_html .= '<div class="small text-warning-emphasis mt-1">Recognized WordPress menus will replace the matching tinymash menu locations on import: ' . $escape( implode( ' · ', $menu_bits ) ) . '.</div>';
                    }
                } else {
                    $body_html .= '<div class="small text-body-secondary mt-1">Menu import is enabled, but this export does not contain recognized WordPress `primary` or `footer` menus.</div>';
                }
            }
            if ( $adjusted_source_duplicates > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Adjusted ' . $adjusted_source_duplicates . ' duplicate slug conflict' . ( $adjusted_source_duplicates === 1 ? '' : 's' ) . ' inside the WordPress source export so the entries can still be imported.</div>';
            }
            if ( $has_conflicting_lock ) {
                $body_html .= '<div class="small ' . ( $conflicting_lock_is_stale ? 'text-warning-emphasis' : 'text-danger' ) . ' mt-1">Another import holds this author-space lock (last active ' . $escape( $conflicting_lock_updated_display !== '' ? $conflicting_lock_updated_display : 'at an unknown time' ) . ').' . ( $conflicting_lock_is_stale ? ' Override is available for stale locks.' : '' ) . '</div>';
            }
            $body_html .= '</div><div class="d-flex flex-wrap gap-2">';
            $body_html .= '<button class="btn btn-outline-primary" id="wordpress-import-start" type="button" data-import-url="' . $escape( $import_url ) . '" data-plugin-url="' . $escape( $plugin_url ) . '" data-login-url="' . $escape( $login_url ) . '" data-preview-token="' . $escape( (string) ( $preview['token'] ?? '' ) ) . '" data-csrf-token="' . $escape( $csrf_token ) . '"' . ( $has_conflicting_lock && ! $conflicting_lock_is_stale ? ' disabled' : '' ) . '>' . ( $import_running ? 'Resume import' : 'Import now' ) . '</button>';
            if ( $can_open_conflicting_preview ) {
                $body_html .= '<a class="btn btn-outline-secondary" href="' . $escape( $preview_url . '?preview=' . rawurlencode( (string) ( $active_lock['token'] ?? '' ) ) ) . '">Open earlier import</a>';
            }
            if ( $can_release_conflicting_lock ) {
                $body_html .= '<form method="post" action="' . $escape( $unlock_url ) . '" onsubmit="return confirm(\'Release the earlier import lock? An import still running in another tab will stop at its next batch.\');">';
                $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
                $body_html .= '<input type="hidden" name="preview_token" value="' . $escape( (string) ( $preview['token'] ?? '' ) ) . '">';
                $body_html .= '<input type="hidden" name="lock_token" value="' . $escape( (string) ( $active_lock['token'] ?? '' ) ) . '">';
                $body_html .= '<button class="btn btn-outline-danger" type="submit">Release earlier lock</button>';
                $body_html .= '</form>';
            }
            $body_html .= '</div></div>';
            if ( $conflicting_lock_is_stale ) {
                $body_html .= '<div class="form-check mt-3">';
                $body_html .= '<input class="form-check-input" id="wordpress-import-force-lock" type="checkbox" value="1">';
                $body_html .= '<label class="form-check-label" for="wordpress-import-force-lock">Override stale import lock</label>';
                $body_html .= '</div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Entries</div><div class="fw-semibold">' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Posts</div><div class="fw-semibold">' . (int) ( $counts['posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Pages</div><div class="fw-semibold">' . (int) ( $counts['pages'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Attachments</div><div class="fw-semibold">' . (int) ( $counts['attachments'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Menus</div><div class="fw-semibold">' . (int) ( $counts['menus'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Menu items</div><div class="fw-semibold">' . (int) ( $counts['menu_items'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            $body_html .= $render_embed_report( $preview );
            $body_html .= $render_media_shortcode_report( $preview );
            $body_html .= $render_text_shortcode_report( $preview );
            $body_html .= $render_unsupported_shortcode_report( $preview );
            $body_html .= $render_comment_report( $preview, is_array( $import_state ) ? $import_state : [] );

            $progress_processed = is_array( $import_state ) ? (int) ( $import_state['counts']['processed'] ?? 0 ) : 0;
            $progress_imported = is_array( $import_state ) ? (int) ( $import_state['counts']['imported'] ?? 0 ) : 0;
            $progress_skipped = is_array( $import_state ) ? (int) ( $import_state['counts']['skipped'] ?? 0 ) : 0;
            $progress_total = (int) ( $counts['total'] ?? 0 );
            $progress_percent = $progress_total > 0 ? (int) floor( ( min( $progress_processed, $progress_total ) / $progress_total ) * 100 ) : 0;
            $progress_hidden = $import_running || $import_finished ? '' : ' d-none';
            $spinner_hidden = $import_finished ? ' d-none' : '';
            $preview_table_hidden = $import_running || $import_finished ? ' d-none' : '';
            $progress_message = $import_finished ? 'Import finished.' : ( $import_running ? 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.' : 'Import runs in smaller batches so larger datasets do not depend on one long request.' );
            $body_html .= '<section class="mt-3 py-3 border-top border-bottom' . $progress_hidden . '" id="wordpress-import-progress">';
            $body_html .= '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-start"><div><div class="small text-uppercase text-body-secondary mb-1">Import progress</div><div class="small text-body-secondary d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-secondary' . $spinner_hidden . '" id="wordpress-import-progress-spinner" aria-hidden="true"></span><span id="wordpress-import-progress-message">' . $escape( $progress_message ) . '</span></div></div><div class="small text-body-secondary"><span id="wordpress-import-progress-counts">' . $progress_imported . ' imported, ' . $progress_skipped . ' skipped, ' . $progress_processed . ' / ' . $progress_total . ' processed</span></div></div>';
            $body_html .= '<div class="progress mt-3" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $progress_percent . '"><div class="progress-bar" id="wordpress-import-progress-bar" style="width: ' . $progress_percent . '%">' . $progress_percent . '%</div></div>';
            $body_html .= '</section>';

            $body_html .= '<div class="mt-3' . $preview_table_hidden . '" id="wordpress-import-preview-table-wrap"><div class="table-responsive"><div tabindex="0" aria-label="WordPress import entries" style="max-height: 32rem; overflow-y: auto;"><table class="table table-sm align-middle mb-0">';
            $body_html .= '<thead><tr><th class="fw-normal sticky-top bg-body">Title</th><th class="fw-normal sticky-top bg-body">Type</th><th class="fw-normal sticky-top bg-body">Status</th><th class="fw-normal sticky-top bg-body">Slug</th><th class="fw-normal sticky-top bg-body">Published</th></tr></thead><tbody>';
            foreach ( array_slice( $entries, 0, 100 ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
                $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td><td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'post' ) ) ) . '</td><td class="align-top text-body-secondary">' . $escape( (string) ( $entry['status'] ?? 'published' ) === 'published' ? 'Published' : 'Unpublished' ) . '</td><td class="align-top"><code>' . $escape( (string) ( $entry['slug'] ?? '' ) ) . '</code></td><td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td></tr>';
            }
            $body_html .= '</tbody></table></div></div>';
            if ( count( $entries ) > 100 ) {
                $body_html .= '<div class="small text-body-secondary mt-3">Showing the first 100 items in the preview table.</div>';
            }
            $body_html .= '</div>';

            if ( ! empty( $adjusted_source_conflicts ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">Adjusted source conflicts</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Original slug</th><th class="fw-normal">Adjusted slug</th></tr></thead><tbody>';
                foreach ( $adjusted_source_conflicts as $conflict ) {
                    if ( ! is_array( $conflict ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $conflict['title'] ?? '' ) ) . '</div></td><td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $conflict['entry_type'] ?? 'post' ) ) ) . '</td><td class="align-top"><code>' . $escape( (string) ( $conflict['original_slug'] ?? '' ) ) . '</code></td><td class="align-top"><code>' . $escape( (string) ( $conflict['adjusted_slug'] ?? '' ) ) . '</code></td></tr>';
                }
                $body_html .= '</tbody></table></div></div>';
            }

            if ( ! empty( $available_menus ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">WordPress menus</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Menu</th><th class="fw-normal">Slug</th><th class="fw-normal">Items</th><th class="fw-normal">Recognized as</th></tr></thead><tbody>';
                foreach ( $available_menus as $menu ) {
                    if ( ! is_array( $menu ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $menu['name'] ?? '' ) ) . '</div></td><td class="align-top"><code>' . $escape( (string) ( $menu['slug'] ?? '' ) ) . '</code></td><td class="align-top text-body-secondary">' . (int) ( $menu['item_count'] ?? 0 ) . '</td><td class="align-top text-body-secondary">' . $escape( (string) ( $menu['suggested_location'] ?? '' ) !== '' ? ucfirst( (string) $menu['suggested_location'] ) : 'Not mapped' ) . '</td></tr>';
                }
                $body_html .= '</tbody></table></div></div>';
            }

            if ( ! empty( $unpublished_entries ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">' . ( $skip_unpublished ? 'Skipped unpublished source entries' : 'Unpublished entries' ) . '</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Slug</th></tr></thead><tbody>';
                foreach ( $unpublished_entries as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td><td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'post' ) ) ) . '</td><td class="align-top"><code>' . $escape( (string) ( $entry['slug'] ?? '' ) ) . '</code></td></tr>';
                }
                $body_html .= '</tbody></table></div></div>';
            }
            $body_html .= '</section></div>';
        }

        if ( is_array( $preview ) && $mode === 'report' && is_array( $import_state ) ) {
            $counts = is_array( $import_state['counts'] ?? null ) ? $import_state['counts'] : [];
            $preview_counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
            $preview_post_count = (int) ( $preview_counts['posts'] ?? 0 );
            $preview_page_count = (int) ( $preview_counts['pages'] ?? 0 );
            $preview_skipped_unpublished = (int) ( $preview_counts['skipped_unpublished'] ?? 0 );
            $recognized_menu_locations = is_array( $preview['menus']['recognized_locations'] ?? null ) ? $preview['menus']['recognized_locations'] : [];
            $report_menu_locations = [];
            foreach ( [ 'primary', 'footer' ] as $menu_location ) {
                $menu_slug = trim( (string) ( $recognized_menu_locations[$menu_location] ?? '' ) );
                if ( $menu_slug === '' ) {
                    continue;
                }

                $report_menu_locations[] = ucfirst( $menu_location ) . ': ' . $menu_slug;
            }
            $report_imported = (int) ( $counts['imported'] ?? 0 );
            $report_skipped = (int) ( $counts['skipped'] ?? 0 );
            $report_deleted = (int) ( $counts['deleted_existing_posts'] ?? 0 );
            $report_deleted_pages = (int) ( $counts['deleted_existing_pages'] ?? 0 );
            $report_unresolved_media = (int) ( $counts['unresolved_media'] ?? 0 );
            $unresolved_media = is_array( $import_state['unresolved_media'] ?? null ) ? array_values( array_filter( $import_state['unresolved_media'], static fn( mixed $item ) : bool => is_array( $item ) ) ) : [];
            $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Import another export</a></div>';
            $body_html .= '<section class="">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import report</div>';
            $body_html .= '<h2 class="h5 mb-1">WordPress import finished</h2>';
            $body_html .= '<div class="text-body-secondary small">Imported into <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' ? 'Root site (/<slug>)' : (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>.</div>';
            $body_html .= '<div class="text-body-secondary small mt-1">Review this report now. Import workspaces expire after seven days of inactivity.</div>';
            if ( ! empty( $preview['replace_existing_posts'] ) && $preview_post_count === 0 && $preview_page_count > 0 && $report_imported === 0 && $report_skipped > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">This export contained pages but no posts. Replace mode removed existing posts only, so existing pages remained and duplicate page imports were skipped.</div>';
            } elseif ( ! empty( $preview['replace_existing_posts'] ) && $report_deleted > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Replace mode removed posts only in the selected target space. Existing pages were left alone.</div>';
            }
            if ( ! empty( $preview['replace_existing_pages'] ) && $report_deleted_pages > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Replace-pages mode removed pages only in the selected target space. Existing posts were left alone.</div>';
            }
            if ( ! empty( $preview['skip_unpublished'] ) && $preview_skipped_unpublished > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Skipped ' . $preview_skipped_unpublished . ' unpublished source entr' . ( $preview_skipped_unpublished === 1 ? 'y' : 'ies' ) . ' before import.</div>';
            }
            if ( ! empty( $preview['import_menus'] ) ) {
                if ( ! empty( $report_menu_locations ) ) {
                    $body_html .= '<div class="small text-body-secondary mt-1">Updated tinymash menu locations from WordPress: ' . $escape( implode( ' · ', $report_menu_locations ) ) . '.</div>';
                } else {
                    $body_html .= '<div class="small text-body-secondary mt-1">Menu import was enabled, but this export did not contain recognized WordPress `primary` or `footer` menus.</div>';
                }
            }
            if ( $report_unresolved_media > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Some WordPress upload URLs could not be localized. This usually means the export had no matching attachment item, or the source file was no longer reachable during import.</div>';
            }
            $body_html .= $render_embed_report( $preview );
            $body_html .= $render_media_shortcode_report( $preview );
            $body_html .= $render_text_shortcode_report( $preview );
            $body_html .= $render_unsupported_shortcode_report( $preview, true );
            $body_html .= $render_comment_report( $preview, $import_state );
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Imported</div><div class="fw-semibold">' . (int) ( $counts['imported'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Skipped</div><div class="fw-semibold">' . (int) ( $counts['skipped'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Duplicates</div><div class="fw-semibold">' . (int) ( $counts['skipped_duplicate'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Removed first</div><div class="fw-semibold">' . (int) ( $counts['deleted_existing_posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            $body_html .= '<div class="row g-3 mt-1"><div class="col-12 col-lg-6"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Processed</div><div class="fw-semibold">' . (int) ( $counts['processed'] ?? 0 ) . ' / ' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-12 col-lg-6"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Finished</div><div class="fw-semibold">' . $escape( $date_formatter->formatUtcDateTime( (string) ( $import_state['finished_at_utc'] ?? '' ) ) ) . '</div></div></div></div>';
            if ( ! empty( $unresolved_media ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">Unresolved media</div><div class="table-responsive" tabindex="0" aria-label="Unresolved media URLs"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Slug</th><th class="fw-normal">URL</th></tr></thead><tbody>';
                foreach ( array_slice( $unresolved_media, 0, 25 ) as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? '' ) ) . '</div><div class="small text-body-secondary">' . $escape( ucfirst( (string) ( $item['entry_type'] ?? 'post' ) ) ) . '</div></td><td class="align-top"><code>' . $escape( (string) ( $item['slug'] ?? '' ) ) . '</code></td><td class="align-top"><code>' . $escape( (string) ( $item['url'] ?? '' ) ) . '</code></td></tr>';
                }
                $body_html .= '</tbody></table></div>';
                if ( count( $unresolved_media ) > 25 ) {
                    $body_html .= '<div class="small text-body-secondary mt-2">Showing the first 25 unresolved media URLs.</div>';
                }
                $body_html .= '</div>';
            }
            $body_html .= '</section></div>';
        }

        $body_html .= '</div>';

        if ( $mode === 'preview' ) {
            $body_html .= <<<HTML
<script>
(() => {
    const startButton = document.getElementById('wordpress-import-start');
    if (!startButton) {
        return;
    }

    const progressSection = document.getElementById('wordpress-import-progress');
    const progressMessage = document.getElementById('wordpress-import-progress-message');
    const progressCounts = document.getElementById('wordpress-import-progress-counts');
    const progressBar = document.getElementById('wordpress-import-progress-bar');
    const progressSpinner = document.getElementById('wordpress-import-progress-spinner');
    const previewTableWrap = document.getElementById('wordpress-import-preview-table-wrap');
    const forceLockCheckbox = document.getElementById('wordpress-import-force-lock');
    const importUrl = startButton.dataset.importUrl || '';
    const pluginUrl = startButton.dataset.pluginUrl || '';
    const loginUrl = startButton.dataset.loginUrl || '';
    const previewToken = startButton.dataset.previewToken || '';
    const csrfToken = startButton.dataset.csrfToken || '';
    let importInFlight = false;

    function setProgress(payload) {
        if (!progressSection || !progressMessage || !progressCounts || !progressBar || !payload) {
            return;
        }

        progressSection.classList.remove('d-none');
        if (progressSpinner) {
            progressSpinner.classList.toggle('d-none', !!payload.done);
        }
        const imported = Number(payload.counts?.imported || 0);
        const skipped = Number(payload.counts?.skipped || 0);
        const processed = Number(payload.counts?.processed || 0);
        const total = Number(payload.counts?.total || 0);
        const percent = Number(payload.progress_percent || 0);
        progressCounts.textContent = imported + ' imported, ' + skipped + ' skipped, ' + processed + ' / ' + total + ' processed';
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
        progressBar.setAttribute('aria-valuenow', String(percent));
        progressMessage.textContent = payload.done
            ? 'Import finished.'
            : 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.';
    }

    async function runImport() {
        if (importInFlight || !importUrl || !previewToken || !csrfToken) {
            return;
        }

        importInFlight = true;
        startButton.disabled = true;
        startButton.textContent = 'Importing...';
        if (progressSection) {
            progressSection.classList.remove('d-none');
        }
        if (progressSpinner) {
            progressSpinner.classList.remove('d-none');
        }
        if (progressMessage) {
            progressMessage.textContent = 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.';
        }
        if (previewTableWrap) {
            previewTableWrap.classList.add('d-none');
        }

        try {
            while (true) {
                const response = await fetch(importUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        tinymash_csrf: csrfToken,
                        preview_token: previewToken,
                        force_lock: forceLockCheckbox && forceLockCheckbox.checked ? '1' : '0'
                    }).toString()
                });

                if (!window.TinyMashImportAdmin) {
                    throw new Error('The import helper is unavailable. Reload this preview to resume.');
                }
                const payload = await window.TinyMashImportAdmin.readJsonResponse(response);

                setProgress(payload);
                if (payload.done) {
                    window.location.href = payload.redirect_url || pluginUrl || window.location.href;
                    return;
                }
            }
        } catch (error) {
            if (error.requiresLogin && window.TinyMashImportAdmin) {
                window.location.href = window.TinyMashImportAdmin.buildLoginUrl(loginUrl, window.location.pathname + window.location.search);
                return;
            }
            startButton.disabled = false;
            startButton.textContent = 'Resume import';
            if (previewTableWrap) {
                previewTableWrap.classList.remove('d-none');
            }
            if (progressSection && progressMessage) {
                progressSection.classList.remove('d-none');
                progressMessage.textContent = error instanceof TypeError
                    ? 'The request was interrupted. Reload this preview to resume.'
                    : (error.message || 'The WordPress import failed.');
            }
            if (progressSpinner) {
                progressSpinner.classList.add('d-none');
            }
        } finally {
            importInFlight = false;
        }
    }

    startButton.addEventListener('click', runImport);
})();
</script>
HTML;
        }

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'WordPress import',
                'current_section' => 'wordpress-import',
                'current_location' => 'wordpress-import',
                'help_contexts' => [ 'plugin-wordpress-import' ],
                'plugin_page_kicker' => 'Import',
                'plugin_page_title' => $mode === 'report' ? 'WordPress import report' : ( $mode === 'preview' ? 'WordPress import preview' : 'WordPress import' ),
                'plugin_page_summary' => $mode === 'report'
                    ? 'Review the result of a completed WordPress import.'
                    : ( $mode === 'preview'
                        ? 'Review the parsed WordPress content before starting the import.'
                        : 'Import WordPress posts, pages, and selected comments.' ),
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute( $plugin_key, 'get', $plugin_url, static function() use ( $render_page ) : void { $render_page( 'setup' ); } );
    $plugins->registerRoute( $plugin_key, 'get', $preview_url, static function() use ( $render_page ) : void { $render_page( 'preview' ); } );
    $plugins->registerRoute( $plugin_key, 'get', $report_url, static function() use ( $render_page ) : void { $render_page( 'report' ); } );
    $plugins->registerRoute(
        $plugin_key,
        'get',
        $review_download_url,
        static function() use ( $plugins, $security, $import_service ) : void {
            if ( ! $security->isLoggedIn() ) {
                http_response_code( 404 );
                return;
            }

            $query = $plugins->getRequestQueryData();
            $preview = $import_service->getPreview( (string) ( $query['preview'] ?? '' ) );
            $username = strtolower( mb_trim( (string) $security->getCurrentUsername() ) );
            if ( ! is_array( $preview ) || (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== $username || ( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' && ! $security->isSuperAdmin() ) ) {
                http_response_code( 404 );
                return;
            }

            $report = is_array( $preview['unsupported_shortcodes'] ?? null ) ? $preview['unsupported_shortcodes'] : [];
            header( 'Content-Type: text/plain; charset=UTF-8' );
            header( 'Content-Disposition: attachment; filename="wordpress-import-review.txt"' );
            header( 'Cache-Control: private, no-store' );
            header( 'X-Content-Type-Options: nosniff' );
            echo 'WordPress content review' . PHP_EOL;
            echo 'Target: ' . (string) ( $preview['target_scope'] ?? 'author' ) . ( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' ? '' : ':' . (string) ( $preview['author_slug'] ?? '' ) ) . PHP_EOL;
            echo 'Plugin tags: ' . (int) ( $report['occurrences'] ?? 0 ) . PHP_EOL;
            echo 'Affected entries: ' . (int) ( $report['affected_entries'] ?? 0 ) . PHP_EOL;
            echo 'Edit or unpublish affected entries after import. Skipped entries were not changed.' . PHP_EOL;
            foreach ( (array) ( $report['items'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $title = mb_trim( preg_replace( '/\s+/u', ' ', (string) ( $item['title'] ?? '' ) ) ?? '' );
                $path = (string) ( $preview['target_scope'] ?? 'author' ) === 'root' ? '' : (string) ( $preview['author_slug'] ?? '' ) . '/';
                echo PHP_EOL . (string) ( $item['entry_type'] ?? '' ) . ' /' . $path . (string) ( $item['slug'] ?? '' ) . ' — ' . $title . PHP_EOL;
                foreach ( (array) ( $item['names'] ?? [] ) as $name => $count ) {
                    echo '  [' . (string) $name . '] × ' . (int) $count . PHP_EOL;
                }
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $preview_url,
        static function() use ( $plugins, $security, $import_service, $login_url, $plugin_url, $preview_url, $build_notice, $resolve_target, $log_plugin_error ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            if ( ! $import_service->isAvailable() ) {
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'SimpleXML is required for WordPress import in this PHP runtime.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            try {
                $current_username = trim( (string) $security->getCurrentUsername() );
                $target = $resolve_target( $data );
                $preview = $import_service->createPreviewFromUpload(
                    $_FILES['wordpress_export'] ?? [],
                    (string) ( $target['author_slug'] ?? '' ),
                    ! empty( $data['aggregate_to_root'] ),
                    $current_username,
                    ! empty( $data['replace_existing_posts'] ),
                    ! empty( $data['replace_existing_pages'] ),
                    ! empty( $data['skip_unpublished'] ),
                    ! empty( $data['import_menus'] ),
                    ! empty( $data['import_comments'] ),
                    ! empty( $data['preserve_approved_comments'] ),
                    ! empty( $data['retain_commenter_emails'] ),
                    (string) ( $target['scope'] ?? 'author' )
                );
            } catch ( \Throwable $e ) {
                $log_plugin_error( 'preview failed', $e );
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'The WordPress export could not be previewed.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $plugins->redirect( $preview_url . '?preview=' . rawurlencode( (string) ( $preview['token'] ?? '' ) ) );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $unlock_url,
        static function() use ( $plugins, $security, $import_service, $import_lock_service, $login_url, $plugin_url, $preview_url, $build_notice ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview_token'] ?? '' ) ) ) ) ?? '';
            $lock_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['lock_token'] ?? '' ) ) ) ) ?? '';
            $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
            $current_username = strtolower( trim( (string) $security->getCurrentUsername() ) );
            if ( ! is_array( $preview ) || (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== $current_username || ( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' && ! $security->isSuperAdmin() ) ) {
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'That WordPress import preview is not available for the current account.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $author_slug = (string) ( $preview['author_slug'] ?? '' );
            $lock = $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service->getAuthorLock( $author_slug ) : null;
            $lock_owner = strtolower( trim( (string) ( $lock['created_by_username'] ?? '' ) ) );
            if ( ! is_array( $lock ) || $lock_token === '' || ! hash_equals( (string) ( $lock['token'] ?? '' ), $lock_token ) || ( ! $security->isSuperAdmin() && $lock_owner !== $current_username ) ) {
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'The earlier import lock could not be released.' ) );
                $plugins->redirect( $preview_url . '?preview=' . rawurlencode( $preview_token ) );
                return;
            }

            $released = $import_lock_service->releaseAuthorLock( $author_slug, $lock_token );
            $security->setFlash(
                'wordpress-import.notice',
                $build_notice( $released ? 'success' : 'danger', $released ? 'Released the earlier import lock.' : 'The earlier import lock could not be released.' )
            );
            $plugins->redirect( $preview_url . '?preview=' . rawurlencode( $preview_token ) );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $import_url,
        static function() use ( $plugins, $security, $import_service, $login_url, $plugin_url, $preview_url, $report_url, $build_notice, $emit_json, $is_json_request, $import_batch_size, $log_plugin_error ) : void {
            $expects_json = $is_json_request();

            if ( ! $security->isLoggedIn() ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'Login is required.' ], 401 );
                    return;
                }
                $plugins->redirect( $login_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'Your session token is invalid. Please reload the page and try again.' ], 403 );
                    return;
                }
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview_token'] ?? '' ) ) ) ) ?? '';
            $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
            if ( ! is_array( $preview ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'The WordPress import preview is missing or expired. Please preview the export again.' ], 404 );
                    return;
                }
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'The WordPress import preview is missing or expired. Please preview the export again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }
            if ( (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== strtolower( trim( (string) $security->getCurrentUsername() ) ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'That WordPress import preview does not belong to the current account.' ], 403 );
                    return;
                }
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'That WordPress import preview does not belong to the current account.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }
            if ( (string) ( $preview['target_scope'] ?? 'author' ) === 'root' && ! $security->isSuperAdmin() ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'Root imports require a superadmin account.' ], 403 );
                    return;
                }
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', 'Root imports require a superadmin account.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $security->touchAuthenticatedSession();
            $security->closeSession();

            $result = [ 'done' => false ];
            try {
                $result = $import_service->importPreviewBatch( $preview_token, $import_batch_size, ! empty( $data['force_lock'] ) );
                if ( ! empty( $result['done'] ) ) {
                    $imported_count = (int) ( $result['counts']['imported'] ?? 0 );
                    $skipped_count = (int) ( $result['counts']['skipped'] ?? 0 );
                    $deleted_existing_posts = (int) ( $result['counts']['deleted_existing_posts'] ?? 0 );
                    $deleted_existing_pages = (int) ( $result['counts']['deleted_existing_pages'] ?? 0 );
                    $message = 'Imported ' . $imported_count . ' WordPress entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    $notice_type = 'success';
                    if ( $deleted_existing_posts > 0 ) {
                        $message = 'Removed ' . $deleted_existing_posts . ' existing post' . ( $deleted_existing_posts === 1 ? '' : 's' ) . ' and imported ' . $imported_count . ' WordPress entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    }
                    if ( $deleted_existing_pages > 0 ) {
                        $message = 'Removed ' . $deleted_existing_pages . ' existing page' . ( $deleted_existing_pages === 1 ? '' : 's' ) . ' and imported ' . $imported_count . ' WordPress entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    }
                    if ( $deleted_existing_posts > 0 && $deleted_existing_pages > 0 ) {
                        $message = 'Removed ' . $deleted_existing_posts . ' existing post' . ( $deleted_existing_posts === 1 ? '' : 's' ) . ' and ' . $deleted_existing_pages . ' existing page' . ( $deleted_existing_pages === 1 ? '' : 's' ) . ', then imported ' . $imported_count . ' WordPress entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    }
                    if ( $skipped_count > 0 ) {
                        $message .= ' Skipped ' . $skipped_count . ' entr' . ( $skipped_count === 1 ? 'y' : 'ies' ) . '.';
                        $notice_type = 'warning';
                    }
                    $security->setFlash( 'wordpress-import.notice', $build_notice( $notice_type, $message ) );
                }
                if ( $expects_json ) {
                    $result['redirect_url'] = ! empty( $result['done'] )
                        ? ( $report_url . '?preview=' . rawurlencode( $preview_token ) )
                        : ( $preview_url . '?preview=' . rawurlencode( $preview_token ) );
                    $emit_json( $plugins, $result );
                    return;
                }
            } catch ( \Throwable $e ) {
                $log_plugin_error( 'import failed', $e );
                $message = mb_trim( $e->getMessage() ) !== '' ? mb_trim( $e->getMessage() ) : 'The WordPress import failed.';
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => $message ], 500 );
                    return;
                }
                $security->setFlash( 'wordpress-import.notice', $build_notice( 'danger', $message ) );
            }

            $plugins->redirect( ! empty( $result['done'] ) ? ( $report_url . '?preview=' . rawurlencode( $preview_token ) ) : ( $preview_url . '?preview=' . rawurlencode( $preview_token ) ) );
        }
    );
};
