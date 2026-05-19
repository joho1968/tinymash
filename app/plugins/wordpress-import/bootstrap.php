<?php

require_once __DIR__ . '/TinyMashWordPressImportService.php';
require_once dirname( __DIR__, 2 ) . '/classes/TinyMashConfigIO.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
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
    $import_lock_service = $plugins->getService( 'import.lock_service' );
    $config_io = new TinyMashConfigIO();

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_repository instanceof TinyMashContentRepository || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the WordPress import plugin are not available.' );
    }

    $import_service = new TinyMashWordPressImportService(
        $project_root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'plugin-wordpress-import',
        $content_repository,
        $config,
        $config_io,
        $media_import_bridge instanceof TinyMashMediaImportBridge ? $media_import_bridge : null,
        $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service : null
    );

    $parse_cli_options = static function( array $arguments ) : array {
        $options = [
            'action' => strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ),
            'source' => (string) ( $arguments[1] ?? '' ),
            'author' => '',
            'aggregate_to_root' => false,
            'replace_existing_posts' => false,
            'replace_existing_pages' => false,
            'skip_unpublished' => false,
            'import_menus' => false,
            'batch_size' => 25,
            'memory_limit' => '512M',
            'keep_preview' => false,
            'force_lock' => false,
        ];

        foreach ( array_slice( $arguments, 2 ) as $argument ) {
            $argument = (string) $argument;
            if ( str_starts_with( $argument, '--author=' ) ) {
                $options['author'] = strtolower( trim( substr( $argument, 9 ) ) );
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

            throw new \RuntimeException( 'Unknown option "' . $argument . '".' );
        }

        return( $options );
    };

    $write_preview_summary = static function( array $preview ) : void {
        $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
        fwrite( STDOUT, 'preview_token: ' . (string) ( $preview['token'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'author: ' . (string) ( $preview['author_slug'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'entries: ' . (int) ( $counts['total'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'posts: ' . (int) ( $counts['posts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'pages: ' . (int) ( $counts['pages'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'attachments: ' . (int) ( $counts['attachments'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'menus: ' . (int) ( $counts['menus'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'menu_items: ' . (int) ( $counts['menu_items'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'unpublished: ' . (int) ( $counts['drafts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'skipped_unpublished: ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'adjusted_source_duplicates: ' . (int) ( $preview['adjusted_source_duplicates'] ?? 0 ) . PHP_EOL );
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
                'usage' => 'tinymash wordpress-import <preview|import> <wxr-file> --author=<username> [--aggregate-root] [--replace-existing-posts] [--replace-existing-pages] [--skip-unpublished] [--import-menus] [--batch-size=<n>] [--memory-limit=<value>] [--keep-preview] [--force-lock]',
                'summary' => 'Preview or import a WordPress WXR export into an author space from the CLI.',
                'order' => 220,
                'handler' => static function( array $arguments ) use ( $parse_cli_options, $write_preview_summary, $import_service, $user_repository ) : void {
                    $options = $parse_cli_options( $arguments );
                    $action = (string) ( $options['action'] ?? '' );
                    if ( ! in_array( $action, [ 'preview', 'import' ], true ) ) {
                        throw new \RuntimeException( 'usage: tinymash wordpress-import <preview|import> <wxr-file> --author=<username> [--aggregate-root] [--replace-existing-posts] [--replace-existing-pages] [--skip-unpublished] [--import-menus] [--batch-size=<n>] [--memory-limit=<value>] [--keep-preview] [--force-lock]' );
                    }

                    $source_filename = trim( (string) ( $options['source'] ?? '' ) );
                    if ( $source_filename === '' || ! is_file( $source_filename ) || ! is_readable( $source_filename ) ) {
                        throw new \RuntimeException( 'The WordPress export file could not be read.' );
                    }

                    $author_slug = strtolower( trim( (string) ( $options['author'] ?? '' ) ) );
                    if ( $author_slug === '' || ! is_array( $user_repository->getUserByUsername( $author_slug ) ) ) {
                        throw new \RuntimeException( 'A valid --author=<username> is required for WordPress import.' );
                    }

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
                        ! empty( $options['import_menus'] )
                    );

                    $write_preview_summary( $preview );
                    if ( $action === 'preview' ) {
                        return;
                    }

                    $token = (string) ( $preview['token'] ?? '' );
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
                    fwrite( STDOUT, 'unresolved_media: ' . (int) ( $counts['unresolved_media'] ?? 0 ) . PHP_EOL );
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
    $import_url = $plugin_url . '/run';
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
            'summary' => 'Import posts and pages from a WordPress WXR export into an author space.',
            'group' => 'Plugins',
            'order' => 121,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it imports',
                    'markdown' => 'WordPress import brings posts and pages from a WXR/XML export into an author space. It preserves timestamps, status, and page-parent relationships where the source export provides them.',
                ],
                [
                    'title' => 'Menus',
                    'markdown' => 'WordPress import can optionally map recognized WordPress navigation menus into tinymash `primary` and `footer` menu locations. This currently uses menu-name heuristics and only runs when menu import is enabled explicitly.',
                ],
                [
                    'title' => 'Media',
                    'markdown' => 'The importer localizes WordPress media from the exported attachment set, common WordPress upload URLs, and the source site when those files are still reachable.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
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
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES );
    };

    $is_json_request = static function() : bool {
        $requested_with = strtolower( trim( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) ) );
        if ( $requested_with === 'xmlhttprequest' ) {
            return( true );
        }

        $accept = strtolower( trim( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ) );
        return( str_contains( $accept, 'application/json' ) );
    };

    $resolve_target_author_slug = static function( array $data = [] ) use ( $security, $user_repository ) : string {
        $current_username = strtolower( trim( (string) $security->getCurrentUsername() ) );
        if ( $current_username === '' ) {
            return( '' );
        }

        if ( ! $security->isSuperAdmin() ) {
            return( $current_username );
        }

        $candidate = strtolower( trim( (string) ( $data['target_author'] ?? '' ) ) );
        if ( $candidate === '' ) {
            return( $current_username );
        }

        $user = $user_repository->getUserByUsername( $candidate );
        return( is_array( $user ) ? $candidate : $current_username );
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
            $options[] = [
                'username' => $username,
                'label' => $label,
            ];
        }

        return( $options );
    };

    $render_page = static function( string $mode = 'setup', array $notice = [] ) use ( $plugins, $security, $date_formatter, $import_service, $import_lock_service, $plugin_url, $preview_url, $report_url, $import_url, $login_url, $escape, $build_notice, $build_author_options, $max_execution_time, $import_batch_size ) : void {
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
        if ( is_array( $preview ) ) {
            $selected_target_author = strtolower( trim( (string) ( $preview['author_slug'] ?? $selected_target_author ) ) );
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
            $body_html .= '<section class="p-3 bg-body border rounded-3 shadow-sm">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import source</div>';
            $body_html .= '<h2 class="h5 mb-1">WordPress WXR export</h2>';
            $body_html .= '<p class="text-body-secondary mb-3">Imports WordPress posts and pages into an author space. Existing tinymash entries are never overwritten.</p>';
            $body_html .= '<form method="post" action="' . $escape( $preview_url ) . '" enctype="multipart/form-data" class="row g-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
            if ( $is_superadmin ) {
                $body_html .= '<div class="col-12 col-lg-6">';
                $body_html .= '<label class="form-label" for="wordpress-import-target-author">Target author space</label>';
                $body_html .= '<select class="form-select" id="wordpress-import-target-author" name="target_author">';
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
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-aggregate" name="aggregate_to_root" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-aggregate">Also show imported posts on the root front page</label>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-replace" name="replace_existing_posts" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-replace">Replace current author posts before import</label>';
            $body_html .= '<div class="form-text">Only posts in the target author space are removed. Pages are left alone.</div>';
            $body_html .= '</div></div>';
            $body_html .= '<div class="col-12"><div class="form-check">';
            $body_html .= '<input class="form-check-input" id="wordpress-import-replace-pages" name="replace_existing_pages" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="wordpress-import-replace-pages">Replace current author pages before import</label>';
            $body_html .= '<div class="form-text">Only pages in the target author space are removed. Posts are left alone.</div>';
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
            $body_html .= '<div class="col-12"><button class="btn btn-primary" type="submit"' . ( $runtime_available ? '' : ' disabled' ) . '>Preview import</button></div>';
            $body_html .= '</form>';
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
            $conflicting_lock_created_display = $has_conflicting_lock
                ? $date_formatter->formatUtcDateTime( (string) ( $active_lock['created_at_utc'] ?? '' ) )
                : '';

            $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Start over</a></div>';
            $body_html .= '<section class="p-3 bg-body border rounded-3 shadow-sm">';
            $body_html .= '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3"><div>';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Preview</div>';
            $body_html .= '<h2 class="h5 mb-1">Ready to import</h2>';
            $body_html .= '<div class="text-body-secondary small">This preview targets <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>.</div>';
            if ( $replace_existing_posts ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Current posts in this author space will be deleted before the first import batch. Pages are left alone.</div>';
                if ( $preview_post_count === 0 && $preview_page_count > 0 ) {
                    $body_html .= '<div class="small text-warning-emphasis mt-1">This export contains pages but no posts. Replace mode will not remove existing pages, so page duplicates in the target author space will still be skipped.</div>';
                }
            }
            if ( $replace_existing_pages ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Current pages in this author space will be deleted before the first import batch. Posts are left alone.</div>';
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
                $body_html .= '<div class="small ' . ( $conflicting_lock_is_stale ? 'text-warning-emphasis' : 'text-danger' ) . ' mt-1">Another import currently holds this author-space lock (created ' . $escape( $conflicting_lock_created_display !== '' ? $conflicting_lock_created_display : 'unknown time' ) . ').' . ( $conflicting_lock_is_stale ? ' You can override the stale lock.' : '' ) . '</div>';
            }
            $body_html .= '</div><div class="d-flex flex-wrap gap-2">';
            $body_html .= '<button class="btn btn-outline-primary" id="wordpress-import-start" type="button" data-import-url="' . $escape( $import_url ) . '" data-plugin-url="' . $escape( $plugin_url ) . '" data-preview-token="' . $escape( (string) ( $preview['token'] ?? '' ) ) . '" data-csrf-token="' . $escape( $csrf_token ) . '"' . ( $has_conflicting_lock && ! $conflicting_lock_is_stale ? ' disabled' : '' ) . '>' . ( $import_running ? 'Resume import' : 'Import now' ) . '</button>';
            $body_html .= '</div></div>';
            if ( $conflicting_lock_is_stale ) {
                $body_html .= '<div class="form-check mt-3">';
                $body_html .= '<input class="form-check-input" id="wordpress-import-force-lock" type="checkbox" value="1">';
                $body_html .= '<label class="form-check-label" for="wordpress-import-force-lock">Override stale import lock</label>';
                $body_html .= '</div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Entries</div><div class="fw-semibold">' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Posts</div><div class="fw-semibold">' . (int) ( $counts['posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Pages</div><div class="fw-semibold">' . (int) ( $counts['pages'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Attachments</div><div class="fw-semibold">' . (int) ( $counts['attachments'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Menus</div><div class="fw-semibold">' . (int) ( $counts['menus'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Menu items</div><div class="fw-semibold">' . (int) ( $counts['menu_items'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';

            $progress_processed = is_array( $import_state ) ? (int) ( $import_state['counts']['processed'] ?? 0 ) : 0;
            $progress_imported = is_array( $import_state ) ? (int) ( $import_state['counts']['imported'] ?? 0 ) : 0;
            $progress_skipped = is_array( $import_state ) ? (int) ( $import_state['counts']['skipped'] ?? 0 ) : 0;
            $progress_total = (int) ( $counts['total'] ?? 0 );
            $progress_percent = $progress_total > 0 ? (int) floor( ( min( $progress_processed, $progress_total ) / $progress_total ) * 100 ) : 0;
            $progress_hidden = $import_running || $import_finished ? '' : ' d-none';
            $spinner_hidden = $import_finished ? ' d-none' : '';
            $preview_table_hidden = $import_running || $import_finished ? ' d-none' : '';
            $progress_message = $import_finished ? 'Import finished.' : ( $import_running ? 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.' : 'Import runs in smaller batches so larger datasets do not depend on one long request.' );
            $body_html .= '<section class="mt-3 p-3 border rounded-3 bg-body-tertiary' . $progress_hidden . '" id="wordpress-import-progress">';
            $body_html .= '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-start"><div><div class="small text-uppercase text-body-secondary mb-1">Import progress</div><div class="small text-body-secondary d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-secondary' . $spinner_hidden . '" id="wordpress-import-progress-spinner" aria-hidden="true"></span><span id="wordpress-import-progress-message">' . $escape( $progress_message ) . '</span></div></div><div class="small text-body-secondary"><span id="wordpress-import-progress-counts">' . $progress_imported . ' imported, ' . $progress_skipped . ' skipped, ' . $progress_processed . ' / ' . $progress_total . ' processed</span></div></div>';
            $body_html .= '<div class="progress mt-3" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $progress_percent . '"><div class="progress-bar" id="wordpress-import-progress-bar" style="width: ' . $progress_percent . '%">' . $progress_percent . '%</div></div>';
            $body_html .= '</section>';

            $body_html .= '<div class="mt-3' . $preview_table_hidden . '" id="wordpress-import-preview-table-wrap"><div class="table-responsive border rounded-3"><div style="max-height: 32rem; overflow-y: auto;"><table class="table table-sm align-middle mb-0">';
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
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">Adjusted source conflicts</div><div class="table-responsive border rounded-3"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Original slug</th><th class="fw-normal">Adjusted slug</th></tr></thead><tbody>';
                foreach ( $adjusted_source_conflicts as $conflict ) {
                    if ( ! is_array( $conflict ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $conflict['title'] ?? '' ) ) . '</div></td><td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $conflict['entry_type'] ?? 'post' ) ) ) . '</td><td class="align-top"><code>' . $escape( (string) ( $conflict['original_slug'] ?? '' ) ) . '</code></td><td class="align-top"><code>' . $escape( (string) ( $conflict['adjusted_slug'] ?? '' ) ) . '</code></td></tr>';
                }
                $body_html .= '</tbody></table></div></div>';
            }

            if ( ! empty( $available_menus ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">WordPress menus</div><div class="table-responsive border rounded-3"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Menu</th><th class="fw-normal">Slug</th><th class="fw-normal">Items</th><th class="fw-normal">Recognized as</th></tr></thead><tbody>';
                foreach ( $available_menus as $menu ) {
                    if ( ! is_array( $menu ) ) {
                        continue;
                    }
                    $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $menu['name'] ?? '' ) ) . '</div></td><td class="align-top"><code>' . $escape( (string) ( $menu['slug'] ?? '' ) ) . '</code></td><td class="align-top text-body-secondary">' . (int) ( $menu['item_count'] ?? 0 ) . '</td><td class="align-top text-body-secondary">' . $escape( (string) ( $menu['suggested_location'] ?? '' ) !== '' ? ucfirst( (string) $menu['suggested_location'] ) : 'Not mapped' ) . '</td></tr>';
                }
                $body_html .= '</tbody></table></div></div>';
            }

            if ( ! empty( $unpublished_entries ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">' . ( $skip_unpublished ? 'Skipped unpublished source entries' : 'Unpublished entries' ) . '</div><div class="table-responsive border rounded-3"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Slug</th></tr></thead><tbody>';
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
            $body_html .= '<section class="p-3 bg-body border rounded-3 shadow-sm">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import report</div>';
            $body_html .= '<h2 class="h5 mb-1">WordPress import finished</h2>';
            $body_html .= '<div class="text-body-secondary small">Imported into <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>.</div>';
            if ( ! empty( $preview['replace_existing_posts'] ) && $preview_post_count === 0 && $preview_page_count > 0 && $report_imported === 0 && $report_skipped > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">This export contained pages but no posts. Replace mode removed existing posts only, so existing pages remained and duplicate page imports were skipped.</div>';
            } elseif ( ! empty( $preview['replace_existing_posts'] ) && $report_deleted > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Replace mode removes only posts in the target author space. Existing pages are left alone.</div>';
            }
            if ( ! empty( $preview['replace_existing_pages'] ) && $report_deleted_pages > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Replace-pages mode removes only pages in the target author space. Existing posts are left alone.</div>';
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
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Imported</div><div class="fw-semibold">' . (int) ( $counts['imported'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Skipped</div><div class="fw-semibold">' . (int) ( $counts['skipped'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Duplicates</div><div class="fw-semibold">' . (int) ( $counts['skipped_duplicate'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Removed first</div><div class="fw-semibold">' . (int) ( $counts['deleted_existing_posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            $body_html .= '<div class="row g-3 mt-1"><div class="col-12 col-lg-6"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Processed</div><div class="fw-semibold">' . (int) ( $counts['processed'] ?? 0 ) . ' / ' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-12 col-lg-6"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Finished</div><div class="fw-semibold">' . $escape( $date_formatter->formatUtcDateTime( (string) ( $import_state['finished_at_utc'] ?? '' ) ) ) . '</div></div></div></div>';
            if ( ! empty( $unresolved_media ) ) {
                $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">Unresolved media</div><div class="table-responsive border rounded-3"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Slug</th><th class="fw-normal">URL</th></tr></thead><tbody>';
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

                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.error || ('Import failed with status ' + response.status));
                }

                setProgress(payload);
                if (payload.done) {
                    window.location.href = payload.redirect_url || pluginUrl || window.location.href;
                    return;
                }
            }
        } catch (error) {
            startButton.disabled = false;
            startButton.textContent = 'Resume import';
            if (previewTableWrap) {
                previewTableWrap.classList.remove('d-none');
            }
            if (progressSection && progressMessage) {
                progressSection.classList.remove('d-none');
                progressMessage.textContent = error.message || 'The WordPress import failed.';
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
                        : 'Bring WordPress posts and pages into an author space.' ),
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
        'post',
        $preview_url,
        static function() use ( $plugins, $security, $import_service, $login_url, $plugin_url, $preview_url, $build_notice, $resolve_target_author_slug, $log_plugin_error ) : void {
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
                $target_author_slug = $resolve_target_author_slug( $data );
                $preview = $import_service->createPreviewFromUpload(
                    $_FILES['wordpress_export'] ?? [],
                    $target_author_slug,
                    ! empty( $data['aggregate_to_root'] ),
                    $current_username,
                    ! empty( $data['replace_existing_posts'] ),
                    ! empty( $data['replace_existing_pages'] ),
                    ! empty( $data['skip_unpublished'] ),
                    ! empty( $data['import_menus'] )
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
                $message = trim( $e->getMessage() ) !== '' ? trim( $e->getMessage() ) : 'The WordPress import failed.';
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
