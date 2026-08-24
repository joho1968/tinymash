<?php

require_once __DIR__ . '/TinyMashBloggerImportService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashImportPreviewWorkspaceService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'blogger-import' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $user_repository = $plugins->getService( 'user.repository' );
    $media_import_bridge = $plugins->getService( 'media.import_bridge' );
    $import_lock_service = $plugins->getService( 'import.lock_service' );
    $import_preview_workspace_service = $plugins->getService( 'import.preview_workspaces' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_repository instanceof TinyMashContentRepository || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Blogger import plugin are not available.' );
    }

    $import_service = new TinyMashBloggerImportService(
        $project_root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'plugin-blogger-import',
        $content_repository,
        $media_import_bridge instanceof TinyMashMediaImportBridge ? $media_import_bridge : null,
        $project_root,
        $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service : null
    );

    $parse_cli_options = static function( array $arguments ) : array {
        $options = [
            'action' => strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ),
            'source' => (string) ( $arguments[1] ?? '' ),
            'author' => '',
            'aggregate_to_root' => false,
            'replace_existing_posts' => false,
            'skip_unpublished' => false,
            'batch_size' => 25,
            'memory_limit' => '512M',
            'keep_preview' => false,
            'media_dir' => '',
            'force_lock' => false,
            'first_image_as_featured' => false,
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
            if ( $argument === '--skip-unpublished' ) {
                $options['skip_unpublished'] = true;
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
            if ( str_starts_with( $argument, '--media-dir=' ) ) {
                $options['media_dir'] = trim( substr( $argument, 12 ) );
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
            if ( $argument === '--first-image-as-featured' ) {
                $options['first_image_as_featured'] = true;
                continue;
            }

            throw new \RuntimeException( 'Unknown option "' . $argument . '".' );
        }

        return( $options );
    };

    $write_preview_summary = static function( array $preview ) : void {
        $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
        $media_summary = is_array( $preview['media_summary'] ?? null ) ? $preview['media_summary'] : [];
        fwrite( STDOUT, 'preview_token: ' . (string) ( $preview['token'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'author: ' . (string) ( $preview['author_slug'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'entries: ' . (int) ( $counts['total'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'posts: ' . (int) ( $counts['posts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'pages: ' . (int) ( $counts['pages'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'unpublished: ' . (int) ( $counts['drafts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'skipped_unpublished: ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'adjusted_source_duplicates: ' . (int) ( $preview['adjusted_source_duplicates'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'media_references: ' . (int) ( $media_summary['referenced'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'media_matches: ' . (int) ( $media_summary['matched'] ?? 0 ) . PHP_EOL );
    };

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'blogger-import',
                'summary' => 'Preview or import a Blogger Atom/XML export into an author space.',
                'arguments' => [
                    [ 'name' => 'action', 'required' => true, 'description' => 'Preview the export or import it', 'choices' => [ 'preview', 'import' ] ],
                    [ 'name' => 'feed-file', 'required' => true, 'description' => 'Readable Blogger Atom/XML export file' ],
                ],
                'options' => [
                    [ 'name' => 'author', 'value' => true, 'required' => true, 'description' => 'Required local author account' ],
                    [ 'name' => 'aggregate-root', 'description' => 'Also include imported posts in the root blogroll' ],
                    [ 'name' => 'replace-existing-posts', 'description' => 'Replace posts with matching slugs' ],
                    [ 'name' => 'skip-unpublished', 'description' => 'Skip draft and scheduled source entries' ],
                    [ 'name' => 'first-image-as-featured', 'description' => 'Use the first imported post image as its featured image' ],
                    [ 'name' => 'media-dir', 'value' => true, 'description' => 'Search this directory for local source media' ],
                    [ 'name' => 'batch-size', 'value' => true, 'integer' => true, 'min' => 1, 'max' => 100, 'default' => 25, 'description' => 'Process this many entries per batch' ],
                    [ 'name' => 'memory-limit', 'value' => true, 'default' => '512M', 'pattern' => '/^(?:-1|\d+[KMG]?)$/i', 'pattern_message' => 'The --memory-limit option must be -1 or a number with an optional K, M, or G suffix.', 'description' => 'Set the PHP memory limit for the import' ],
                    [ 'name' => 'keep-preview', 'description' => 'Keep preview files after a completed import' ],
                    [ 'name' => 'force-lock', 'description' => 'Replace a stale import lock for this author' ],
                ],
                'examples' => [
                    'php8.4 bin/tinymash.php blogger-import preview takeout.xml --author=cajo',
                    'php8.4 bin/tinymash.php blogger-import import takeout.xml --author=cajo --first-image-as-featured',
                ],
                'notes' => 'The target account may be inactive, but it must exist. Run preview before import when checking a new source file.',
                'order' => 210,
                'handler' => static function( array $arguments ) use ( $parse_cli_options, $write_preview_summary, $import_service, $import_preview_workspace_service, $plugin_key, $user_repository ) : void {
                    $options = $parse_cli_options( $arguments );
                    $action = (string) ( $options['action'] ?? '' );
                    $source_filename = trim( (string) ( $options['source'] ?? '' ) );
                    if ( $source_filename === '' || ! is_file( $source_filename ) || ! is_readable( $source_filename ) ) {
                        throw new \RuntimeException( 'The Blogger export file could not be read.' );
                    }

                    $author_slug = strtolower( trim( (string) ( $options['author'] ?? '' ) ) );
                    if ( $author_slug === '' || ! is_array( $user_repository->getUserByUsername( $author_slug ) ) ) {
                        throw new \RuntimeException( 'A valid --author=<username> is required for Blogger import.' );
                    }

                    $memory_limit = trim( (string) ( $options['memory_limit'] ?? '512M' ) );
                    if ( $memory_limit !== '' ) {
                        @ini_set( 'memory_limit', $memory_limit );
                    }
                    @ini_set( 'max_execution_time', '0' );

                    $xml = @ file_get_contents( $source_filename );
                    if ( ! is_string( $xml ) || trim( $xml ) === '' ) {
                        throw new \RuntimeException( 'The Blogger export file could not be read.' );
                    }

                    $preview = $import_service->createPreviewFromXml(
                        $xml,
                        $author_slug,
                        ! empty( $options['aggregate_to_root'] ),
                        $author_slug,
                        ! empty( $options['replace_existing_posts'] ),
                        trim( (string) ( $options['media_dir'] ?? '' ) ),
                        ! empty( $options['skip_unpublished'] ),
                        ! empty( $options['first_image_as_featured'] )
                    );

                    $write_preview_summary( $preview );
                    $token = (string) ( $preview['token'] ?? '' );
                    if ( ! empty( $options['keep_preview'] ) ) {
                        if ( ! $import_preview_workspace_service instanceof TinyMashImportPreviewWorkspaceService || ! $import_preview_workspace_service->retainPreview( $plugin_key, $token ) ) {
                            throw new \RuntimeException( 'The Blogger import preview could not be marked for retention.' );
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
                    fwrite( STDOUT, 'imported: ' . (int) ( $counts['imported'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped: ' . (int) ( $counts['skipped'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped_duplicate: ' . (int) ( $counts['skipped_duplicate'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'skipped_invalid: ' . (int) ( $counts['skipped_invalid'] ?? 0 ) . PHP_EOL );

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
    $plugin_url = $admin_url . '/blogger-import';
    $preview_url = $plugin_url . '/preview';
    $report_url = $plugin_url . '/report';
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
            'section' => 'blogger-import',
            'label' => 'Blogger import',
            'url' => $plugin_url,
            'icon' => 'bi-box-arrow-in-down',
            'group' => 'import',
            'group_label' => 'Import',
            'order' => 75,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-blogger-import',
        [
            'title' => 'Blogger import',
            'summary' => 'Import posts and pages from a Blogger export into an author space.',
            'group' => 'Plugins',
            'order' => 120,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Import posts and pages from a Blogger export XML file into an author space.',
                ],
                [
                    'title' => 'Media',
                    'markdown' => 'Preserves recognized Blogger image alignment, localizes images from an optional matching media-export directory, and can use each post’s first image as its featured image.',
                ],
                [
                    'title' => 'Duplicates',
                    'markdown' => 'Imports never overwrite existing tinymash entries. If a Blogger item collides with an existing slug and type, it is skipped and reported.',
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

    $render_page = static function( string $mode = 'setup', array $notice = [] ) use ( $plugins, $security, $config, $date_formatter, $import_service, $import_lock_service, $plugin_key, $plugin_url, $preview_url, $report_url, $import_url, $unlock_url, $login_url, $escape, $build_notice, $build_author_options, $max_execution_time, $import_batch_size ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }

        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'blogger-import.notice', [] );
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
            $notice = $build_notice( 'danger', 'That Blogger import preview is not available for the current account.' );
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
            $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'The Blogger import preview is missing or expired. Please preview the export again.' ) );
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

        $body_html = '';
        if ( ! $runtime_available ) {
            $body_html .= '<div class="alert alert-danger" role="alert">SimpleXML is required for Blogger import, but it is not available in this PHP runtime.</div>';
        }

        $body_html .= '<div class="row g-3">';
        if ( $mode === 'setup' ) {
            $body_html .= '<div class="col-12">';
            $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import source</div>';
            $body_html .= '<h2 class="h5 mb-1">Blogger export feed</h2>';
            $body_html .= '<p class="text-body-secondary mb-3">Imports Blogger posts and pages into an author space. Existing tinymash entries are never overwritten.</p>';
            $body_html .= '<form method="post" action="' . $escape( $preview_url ) . '" enctype="multipart/form-data" class="row g-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
            if ( $is_superadmin ) {
                $body_html .= '<div class="col-12 col-lg-6">';
                $body_html .= '<label class="form-label" for="blogger-import-target-author">Target author space</label>';
                $body_html .= '<select class="form-select" id="blogger-import-target-author" name="target_author">';
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
                $body_html .= '<div class="col-12">';
                $body_html .= '<div class="small text-body-secondary">Target author space: <span class="font-monospace text-body fw-semibold">' . $escape( strtolower( $username ) ) . '</span></div>';
                $body_html .= '</div>';
            }
            $body_html .= '<div class="col-12">';
            $body_html .= '<label class="form-label" for="blogger-import-file">Blogger export file</label>';
            $body_html .= '<input class="form-control" id="blogger-import-file" name="blogger_export" type="file" accept=".atom,.xml,application/atom+xml,application/xml,text/xml"' . ( $runtime_available ? '' : ' disabled' ) . '>';
            $body_html .= '<div class="form-text">Current Google Takeout exports appear to provide blog entries as <code>feed.atom</code>.</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<label class="form-label" for="blogger-import-media-directory">Local media export directory</label>';
            $body_html .= '<input class="form-control font-monospace" id="blogger-import-media-directory" name="blogger_media_directory" type="text" value="' . $escape( (string) ( $query['media_dir'] ?? '' ) ) . '"' . ( $runtime_available ? '' : ' disabled' ) . '>';
            $body_html .= '<div class="form-text">Optional. Must point to a readable directory inside this tinymash project, for example <code>tmp/...</code>. Blogger image URLs will be matched against exported files there.</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="form-check">';
            $body_html .= '<input class="form-check-input" id="blogger-import-aggregate" name="aggregate_to_root" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="blogger-import-aggregate">Also show imported posts on the root front page</label>';
            $body_html .= '</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="form-check">';
            $body_html .= '<input class="form-check-input" id="blogger-import-replace" name="replace_existing_posts" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="blogger-import-replace">Replace current author posts before import</label>';
            $body_html .= '<div class="form-text">Only posts in the target author space are removed. Pages are left alone.</div>';
            $body_html .= '</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="form-check">';
            $body_html .= '<input class="form-check-input" id="blogger-import-skip-unpublished" name="skip_unpublished" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="blogger-import-skip-unpublished">Skip unpublished entries from the source export</label>';
            $body_html .= '<div class="form-text">Published entries still import normally. Unpublished source items are counted in the preview but left out of the import set.</div>';
            $body_html .= '</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="form-check">';
            $body_html .= '<input class="form-check-input" id="blogger-import-first-featured" name="first_image_as_featured" type="checkbox" value="1">';
            $body_html .= '<label class="form-check-label" for="blogger-import-first-featured">Use each post’s first image as its featured image</label>';
            $body_html .= '<div class="form-text">Featured images can appear in theme listings. The image remains in the post body.</div>';
            $body_html .= '</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="col-12">';
            $body_html .= '<button class="btn btn-primary" type="submit"' . ( $runtime_available ? '' : ' disabled' ) . '>Preview import</button>';
            $body_html .= '</div>';
            $body_html .= '</form>';
            if ( $timeout_warning ) {
                $body_html .= '<div class="alert alert-warning mt-3 mb-0" role="alert">This web runtime is limited to ' . $max_execution_time . ' seconds. Blogger import will use ' . $import_batch_size . ' entr' . ( $import_batch_size === 1 ? 'y' : 'ies' ) . ' per request.</div>';
            }
            $body_html .= '</section>';
            $body_html .= '</div>';
        }

        if ( is_array( $preview ) && $mode === 'preview' ) {
            $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $unpublished_entries = is_array( $preview['unpublished_entries'] ?? null )
                ? array_values( array_filter( $preview['unpublished_entries'], static fn( mixed $entry ) : bool => is_array( $entry ) ) )
                : array_values(
                    array_filter(
                        $entries,
                        static fn( array $entry ) : bool => (string) ( $entry['status'] ?? 'published' ) !== 'published'
                    )
                );
            $media_summary = is_array( $preview['media_summary'] ?? null ) ? $preview['media_summary'] : [];
            $replace_existing_posts = ! empty( $preview['replace_existing_posts'] );
            $skip_unpublished = ! empty( $preview['skip_unpublished'] );
            $first_image_as_featured = ! empty( $preview['first_image_as_featured'] );
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
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="d-flex flex-wrap gap-2 mb-3">';
            $body_html .= '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Start over</a>';
            $body_html .= '</div>';
            $body_html .= '<section class="">';
            $body_html .= '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3">';
            $body_html .= '<div>';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Preview</div>';
            $body_html .= '<h2 class="h5 mb-1">Ready to import</h2>';
            $body_html .= '<div class="text-body-secondary small">This preview targets <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>. Duplicate slug conflicts will be skipped during import.</div>';
            if ( $replace_existing_posts ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Current posts in this author space will be deleted before the first import batch. Pages are left alone.</div>';
            }
            if ( $skip_unpublished && (int) ( $counts['skipped_unpublished'] ?? 0 ) > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Skipping ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . ' unpublished source entr' . ( (int) ( $counts['skipped_unpublished'] ?? 0 ) === 1 ? 'y' : 'ies' ) . ' for this import.</div>';
            }
            if ( $first_image_as_featured ) {
                $body_html .= '<div class="small text-body-secondary mt-1">The first imported image in each post will also become its featured image.</div>';
            }
            if ( $adjusted_source_duplicates > 0 ) {
                $body_html .= '<div class="small text-warning-emphasis mt-1">Adjusted ' . $adjusted_source_duplicates . ' duplicate slug conflict' . ( $adjusted_source_duplicates === 1 ? '' : 's' ) . ' inside the Blogger source export so the entries can still be imported.</div>';
            }
            if ( $has_conflicting_lock ) {
                $body_html .= '<div class="small ' . ( $conflicting_lock_is_stale ? 'text-warning-emphasis' : 'text-danger' ) . ' mt-1">Another import holds this author-space lock (last active ' . $escape( $conflicting_lock_updated_display !== '' ? $conflicting_lock_updated_display : 'at an unknown time' ) . ').' . ( $conflicting_lock_is_stale ? ' You can override the stale lock.' : '' ) . '</div>';
            }
            $body_html .= '</div>';
            $body_html .= '<div class="d-flex flex-wrap gap-2">';
            $body_html .= '<button class="btn btn-outline-primary" id="blogger-import-start" type="button" data-import-url="' . $escape( $import_url ) . '" data-plugin-url="' . $escape( $plugin_url ) . '" data-login-url="' . $escape( $login_url ) . '" data-preview-token="' . $escape( (string) ( $preview['token'] ?? '' ) ) . '" data-csrf-token="' . $escape( $csrf_token ) . '"' . ( $has_conflicting_lock && ! $conflicting_lock_is_stale ? ' disabled' : '' ) . '>' . ( $import_running ? 'Resume import' : 'Import now' ) . '</button>';
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
            $body_html .= '</div>';
            $body_html .= '</div>';
            if ( $conflicting_lock_is_stale ) {
                $body_html .= '<div class="form-check mt-3">';
                $body_html .= '<input class="form-check-input" id="blogger-import-force-lock" type="checkbox" value="1">';
                $body_html .= '<label class="form-check-label" for="blogger-import-force-lock">Override stale import lock</label>';
                $body_html .= '</div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Entries</div><div class="fw-semibold">' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Posts</div><div class="fw-semibold">' . (int) ( $counts['posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Pages</div><div class="fw-semibold">' . (int) ( $counts['pages'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Unpublished</div><div class="fw-semibold">' . (int) ( $counts['drafts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            if ( ! empty( $media_summary['configured'] ) ) {
                $body_html .= '<div class="mt-3 py-3 border-top border-bottom">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-1">Local media set</div>';
                $body_html .= '<div class="small text-body-secondary">Directory: <span class="font-monospace">' . $escape( (string) ( $media_summary['directory'] ?? '' ) ) . '</span></div>';
                $body_html .= '<div class="small text-body-secondary mt-1">Indexed files: ' . (int) ( $media_summary['indexed_files'] ?? 0 ) . ' | Indexed sidecar URLs: ' . (int) ( $media_summary['indexed_urls'] ?? 0 ) . ' | Referenced images: ' . (int) ( $media_summary['referenced_images'] ?? 0 ) . ' | Matched images: ' . (int) ( $media_summary['matched_images'] ?? 0 ) . '</div>';
                $body_html .= '</div>';
            }
            $progress_processed = is_array( $import_state ) ? (int) ( $import_state['counts']['processed'] ?? 0 ) : 0;
            $progress_imported = is_array( $import_state ) ? (int) ( $import_state['counts']['imported'] ?? 0 ) : 0;
            $progress_skipped = is_array( $import_state ) ? (int) ( $import_state['counts']['skipped'] ?? 0 ) : 0;
            $progress_total = (int) ( $counts['total'] ?? 0 );
            $progress_percent = $progress_total > 0 ? (int) floor( ( min( $progress_processed, $progress_total ) / $progress_total ) * 100 ) : 0;
            $progress_hidden = $import_running || $import_finished ? '' : ' d-none';
            $spinner_hidden = $import_finished ? ' d-none' : '';
            $preview_table_hidden = $import_running || $import_finished ? ' d-none' : '';
            $progress_message = $import_finished
                ? 'Import finished.'
                : ( $import_running ? 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.' : 'Import runs in smaller batches so larger datasets do not depend on one long request.' );
            $body_html .= '<section class="mt-3 py-3 border-top border-bottom' . $progress_hidden . '" id="blogger-import-progress">';
            $body_html .= '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">';
            $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">Import progress</div><div class="small text-body-secondary d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-secondary' . $spinner_hidden . '" id="blogger-import-progress-spinner" aria-hidden="true"></span><span id="blogger-import-progress-message">' . $escape( $progress_message ) . '</span></div></div>';
            $body_html .= '<div class="small text-body-secondary"><span id="blogger-import-progress-counts">' . $progress_imported . ' imported, ' . $progress_skipped . ' skipped, ' . $progress_processed . ' / ' . $progress_total . ' processed</span></div>';
            $body_html .= '</div>';
            $body_html .= '<div class="progress mt-3" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $progress_percent . '">';
            $body_html .= '<div class="progress-bar" id="blogger-import-progress-bar" style="width: ' . $progress_percent . '%">' . $progress_percent . '%</div>';
            $body_html .= '</div>';
            $body_html .= '</section>';
            $body_html .= '<div class="mt-3' . $preview_table_hidden . '" id="blogger-import-preview-table-wrap">';
            $body_html .= '<div class="table-responsive"><div style="max-height: 32rem; overflow-y: auto;"><table class="table table-sm align-middle mb-0">';
            $body_html .= '<thead><tr><th class="fw-normal sticky-top bg-body">Title</th><th class="fw-normal sticky-top bg-body">Type</th><th class="fw-normal sticky-top bg-body">Status</th><th class="fw-normal sticky-top bg-body">Slug</th><th class="fw-normal sticky-top bg-body">Published</th><th class="fw-normal sticky-top bg-body">Source</th></tr></thead><tbody>';
            foreach ( array_slice( $entries, 0, 100 ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
                $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                $source_url = trim( (string) ( $entry['source_url'] ?? '' ) );
                $source_html = '<span class="text-body-secondary">—</span>';
                if ( $source_url !== '' ) {
                    $is_absolute_source_url = preg_match( '#^https?://#i', $source_url ) === 1;
                    $source_html = $is_absolute_source_url
                        ? '<a href="' . $escape( $source_url ) . '" target="_blank" rel="noopener noreferrer">Open</a>'
                        : '<code>' . $escape( $source_url ) . '</code>';
                }

                $body_html .= '<tr>';
                $body_html .= '<td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td>';
                $body_html .= '<td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'post' ) ) ) . '</td>';
                $body_html .= '<td class="align-top text-body-secondary">' . $escape( (string) ( $entry['status'] ?? 'published' ) === 'published' ? 'Published' : 'Unpublished' ) . '</td>';
                $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $entry['slug'] ?? '' ) ) . '</code></td>';
                $body_html .= '<td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td>';
                $body_html .= '<td class="align-top">' . $source_html . '</td>';
                $body_html .= '</tr>';
            }
            $body_html .= '</tbody></table></div></div>';
            if ( count( $entries ) > 100 ) {
                $body_html .= '<div class="small text-body-secondary mt-3">Showing the first 100 items in the preview table.</div>';
            }
            $body_html .= '</div>';
            if ( $adjusted_source_duplicates > 0 && ! empty( $adjusted_source_conflicts ) ) {
                $body_html .= '<div class="mt-3">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Adjusted source conflicts</div>';
                $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                $body_html .= '<thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Original slug</th><th class="fw-normal">Adjusted slug</th><th class="fw-normal">Published</th></tr></thead><tbody>';
                foreach ( $adjusted_source_conflicts as $conflict ) {
                    if ( ! is_array( $conflict ) ) {
                        continue;
                    }
                    $published_at_utc = trim( (string) ( $conflict['published_at_utc'] ?? '' ) );
                    $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                    $body_html .= '<tr>';
                    $body_html .= '<td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $conflict['title'] ?? '' ) ) . '</div></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $conflict['entry_type'] ?? 'post' ) ) ) . '</td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $conflict['original_slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $conflict['adjusted_slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td>';
                    $body_html .= '</tr>';
                }
                $body_html .= '</tbody></table></div>';
                $body_html .= '</div>';
            }
            if ( ! empty( $unpublished_entries ) ) {
                $body_html .= '<div class="mt-3">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">' . ( $skip_unpublished ? 'Skipped unpublished source entries' : 'Unpublished entries' ) . '</div>';
                $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                $body_html .= '<thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Slug</th><th class="fw-normal">Published</th></tr></thead><tbody>';
                foreach ( $unpublished_entries as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }
                    $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
                    $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                    $body_html .= '<tr>';
                    $body_html .= '<td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'post' ) ) ) . '</td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $entry['slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td>';
                    $body_html .= '</tr>';
                }
                $body_html .= '</tbody></table></div>';
                $body_html .= '</div>';
            }
            $body_html .= '</section>';
            $body_html .= '</div>';
        }

        if ( is_array( $preview ) && $mode === 'report' && is_array( $import_state ) ) {
            $counts = is_array( $import_state['counts'] ?? null ) ? $import_state['counts'] : [];
            $adjusted_source_conflicts = is_array( $preview['adjusted_source_conflicts'] ?? null ) ? $preview['adjusted_source_conflicts'] : [];
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $media_summary = is_array( $preview['media_summary'] ?? null ) ? $preview['media_summary'] : [];
            $unpublished_entries = array_values(
                array_filter(
                    $entries,
                    static fn( array $entry ) : bool => (string) ( $entry['status'] ?? 'published' ) !== 'published'
                )
            );
            $body_html .= '<div class="col-12">';
            $body_html .= '<div class="d-flex flex-wrap gap-2 mb-3">';
            $body_html .= '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Import another export</a>';
            $body_html .= '</div>';
            $body_html .= '<section class="">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Import report</div>';
            $body_html .= '<h2 class="h5 mb-1">Blogger import finished</h2>';
            $body_html .= '<div class="text-body-secondary small">Imported into <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['author_slug'] ?? strtolower( $username ) ) ) . '</span>.</div>';
            if ( ! empty( $preview['skip_unpublished'] ) && (int) ( $preview['counts']['skipped_unpublished'] ?? 0 ) > 0 ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Skipped ' . (int) ( $preview['counts']['skipped_unpublished'] ?? 0 ) . ' unpublished source entr' . ( (int) ( $preview['counts']['skipped_unpublished'] ?? 0 ) === 1 ? 'y' : 'ies' ) . ' before import.</div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Imported</div><div class="fw-semibold">' . (int) ( $counts['imported'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Skipped</div><div class="fw-semibold">' . (int) ( $counts['skipped'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Duplicates</div><div class="fw-semibold">' . (int) ( $counts['skipped_duplicate'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Invalid</div><div class="fw-semibold">' . (int) ( $counts['skipped_invalid'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            if ( ! empty( $media_summary['configured'] ) ) {
                $body_html .= '<div class="mt-3 py-3 border-top border-bottom">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-1">Local media set</div>';
                $body_html .= '<div class="small text-body-secondary">Directory: <span class="font-monospace">' . $escape( (string) ( $media_summary['directory'] ?? '' ) ) . '</span></div>';
                $body_html .= '<div class="small text-body-secondary mt-1">Indexed files: ' . (int) ( $media_summary['indexed_files'] ?? 0 ) . ' | Indexed sidecar URLs: ' . (int) ( $media_summary['indexed_urls'] ?? 0 ) . ' | Referenced images: ' . (int) ( $media_summary['referenced_images'] ?? 0 ) . ' | Matched images: ' . (int) ( $media_summary['matched_images'] ?? 0 ) . '</div>';
                $body_html .= '</div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Removed first</div><div class="fw-semibold">' . (int) ( $counts['deleted_existing_posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Processed</div><div class="fw-semibold">' . (int) ( $counts['processed'] ?? 0 ) . ' / ' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-12 col-lg-6"><div class="py-2 border-top h-100"><div class="small text-uppercase text-body-secondary mb-1">Finished</div><div class="fw-semibold">' . $escape( $date_formatter->formatUtcDateTime( (string) ( $import_state['finished_at_utc'] ?? '' ) ) ) . '</div></div></div>';
            $body_html .= '</div>';
            if ( ! empty( $preview['adjusted_source_duplicates'] ) ) {
                $body_html .= '<div class="small text-warning-emphasis mt-3">Adjusted ' . (int) $preview['adjusted_source_duplicates'] . ' source duplicate slug conflict' . ( (int) $preview['adjusted_source_duplicates'] === 1 ? '' : 's' ) . ' during preview.</div>';
            }
            if ( ! empty( $adjusted_source_conflicts ) ) {
                $body_html .= '<div class="mt-3">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Adjusted source conflicts</div>';
                $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                $body_html .= '<thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Original slug</th><th class="fw-normal">Adjusted slug</th><th class="fw-normal">Published</th></tr></thead><tbody>';
                foreach ( $adjusted_source_conflicts as $conflict ) {
                    if ( ! is_array( $conflict ) ) {
                        continue;
                    }
                    $published_at_utc = trim( (string) ( $conflict['published_at_utc'] ?? '' ) );
                    $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                    $body_html .= '<tr>';
                    $body_html .= '<td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $conflict['title'] ?? '' ) ) . '</div></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $conflict['entry_type'] ?? 'post' ) ) ) . '</td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $conflict['original_slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $conflict['adjusted_slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td>';
                    $body_html .= '</tr>';
                }
                $body_html .= '</tbody></table></div>';
                $body_html .= '</div>';
            }
            if ( ! empty( $unpublished_entries ) ) {
                $body_html .= '<div class="mt-3">';
                $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Unpublished entries</div>';
                $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
                $body_html .= '<thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Type</th><th class="fw-normal">Slug</th><th class="fw-normal">Published</th></tr></thead><tbody>';
                foreach ( $unpublished_entries as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }
                    $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
                    $published_display = $published_at_utc !== '' ? $date_formatter->formatUtcDateTime( $published_at_utc ) : 'Unpublished';
                    $body_html .= '<tr>';
                    $body_html .= '<td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'post' ) ) ) . '</td>';
                    $body_html .= '<td class="align-top"><code>' . $escape( (string) ( $entry['slug'] ?? '' ) ) . '</code></td>';
                    $body_html .= '<td class="align-top text-body-secondary">' . $escape( $published_display ) . '</td>';
                    $body_html .= '</tr>';
                }
                $body_html .= '</tbody></table></div>';
                $body_html .= '</div>';
            }
            $body_html .= '</section>';
            $body_html .= '</div>';
        }

        $body_html .= '</div>';

        if ( $mode === 'preview' ) {
        $body_html .= <<<HTML
<script>
(() => {
    const startButton = document.getElementById('blogger-import-start');
    if (!startButton) {
        return;
    }

    const progressSection = document.getElementById('blogger-import-progress');
    const progressMessage = document.getElementById('blogger-import-progress-message');
    const progressCounts = document.getElementById('blogger-import-progress-counts');
    const progressBar = document.getElementById('blogger-import-progress-bar');
    const progressSpinner = document.getElementById('blogger-import-progress-spinner');
    const previewTableWrap = document.getElementById('blogger-import-preview-table-wrap');
    const forceLockCheckbox = document.getElementById('blogger-import-force-lock');
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
                    : (error.message || 'The Blogger import failed.');
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
                'title' => APP_NAME . APP_TITLE_SEP . 'Blogger import',
                'current_section' => 'blogger-import',
                'current_location' => 'blogger-import',
                'help_contexts' => [ 'plugin-blogger-import' ],
                'plugin_page_kicker' => 'Import',
                'plugin_page_title' => $mode === 'report' ? 'Blogger import report' : ( $mode === 'preview' ? 'Blogger import preview' : 'Blogger import' ),
                'plugin_page_summary' => $mode === 'report'
                    ? 'Review the result of a completed Blogger import.'
                    : ( $mode === 'preview'
                        ? 'Review the parsed Blogger content before starting the import.'
                        : 'Bring Blogger posts and pages into your current author space.' ),
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $plugin_url,
        static function() use ( $render_page ) : void {
            $render_page( 'setup' );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $preview_url,
        static function() use ( $render_page ) : void {
            $render_page( 'preview' );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $report_url,
        static function() use ( $render_page ) : void {
            $render_page( 'report' );
        }
    );

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
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            if ( ! $import_service->isAvailable() ) {
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'SimpleXML is required for Blogger import in this PHP runtime.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            try {
                $current_username = trim( (string) $security->getCurrentUsername() );
                $target_author_slug = $resolve_target_author_slug( $data );
                $preview = $import_service->createPreviewFromUpload(
                    $_FILES['blogger_export'] ?? [],
                    $target_author_slug,
                    ! empty( $data['aggregate_to_root'] ),
                    $current_username,
                    ! empty( $data['replace_existing_posts'] ),
                    (string) ( $data['blogger_media_directory'] ?? '' ),
                    ! empty( $data['skip_unpublished'] ),
                    ! empty( $data['first_image_as_featured'] )
                );
            } catch ( \Throwable $e ) {
                $log_plugin_error( 'preview failed', $e );
                $message = 'The Blogger export could not be previewed.';
                if ( str_contains( strtolower( $e->getMessage() ), 'media directory' ) ) {
                    $message = 'The Blogger export could not be previewed. Check that the optional local media directory exists, is readable, and points to a directory inside tinymash.';
                }
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', $message ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $redirect_url = $preview_url . '?preview=' . rawurlencode( (string) ( $preview['token'] ?? '' ) );
            if ( ! empty( $preview['media_source_directory'] ) ) {
                $redirect_url .= '&media_dir=' . rawurlencode( (string) ( $preview['media_source_directory'] ?? '' ) );
            }
            $plugins->redirect( $redirect_url );
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
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview_token'] ?? '' ) ) ) ) ?? '';
            $lock_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['lock_token'] ?? '' ) ) ) ) ?? '';
            $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
            $current_username = strtolower( trim( (string) $security->getCurrentUsername() ) );
            if ( ! is_array( $preview ) || (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== $current_username ) {
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'That Blogger import preview is not available for the current account.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $author_slug = (string) ( $preview['author_slug'] ?? '' );
            $lock = $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service->getAuthorLock( $author_slug ) : null;
            $lock_owner = strtolower( trim( (string) ( $lock['created_by_username'] ?? '' ) ) );
            if ( ! is_array( $lock ) || $lock_token === '' || ! hash_equals( (string) ( $lock['token'] ?? '' ), $lock_token ) || ( ! $security->isSuperAdmin() && $lock_owner !== $current_username ) ) {
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'The earlier import lock could not be released.' ) );
                $plugins->redirect( $preview_url . '?preview=' . rawurlencode( $preview_token ) );
                return;
            }

            $released = $import_lock_service->releaseAuthorLock( $author_slug, $lock_token );
            $security->setFlash(
                'blogger-import.notice',
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
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview_token'] ?? '' ) ) ) ) ?? '';
            $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
            if ( ! is_array( $preview ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'The Blogger import preview is missing or expired. Please preview the export again.' ], 404 );
                    return;
                }
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'The Blogger import preview is missing or expired. Please preview the export again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }
            if ( (string) ( $preview['created_by_username'] ?? $preview['author_slug'] ?? '' ) !== strtolower( trim( (string) $security->getCurrentUsername() ) ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'That Blogger import preview does not belong to the current account.' ], 403 );
                    return;
                }
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', 'That Blogger import preview does not belong to the current account.' ) );
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
                    $duplicate_count = (int) ( $result['counts']['skipped_duplicate'] ?? 0 );
                    $invalid_count = (int) ( $result['counts']['skipped_invalid'] ?? 0 );
                    $deleted_existing_posts = (int) ( $result['counts']['deleted_existing_posts'] ?? 0 );
                    $message = 'Imported ' . $imported_count . ' Blogger entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    $notice_type = 'success';
                    if ( $deleted_existing_posts > 0 ) {
                        $message = 'Removed ' . $deleted_existing_posts . ' existing post' . ( $deleted_existing_posts === 1 ? '' : 's' ) . ' and imported ' . $imported_count . ' Blogger entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    }
                    if ( $skipped_count > 0 ) {
                        $message .= ' Skipped ' . $skipped_count . ' entr' . ( $skipped_count === 1 ? 'y' : 'ies' ) . '.';
                        if ( $duplicate_count > 0 ) {
                            $message .= ' Duplicate conflicts in the Blogger export or target space: ' . $duplicate_count . '.';
                        }
                        if ( $invalid_count > 0 ) {
                            $message .= ' Invalid entries: ' . $invalid_count . '.';
                        }
                        $notice_type = 'warning';
                    }
                    $security->setFlash( 'blogger-import.notice', $build_notice( $notice_type, $message ) );
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
                $message = trim( $e->getMessage() ) !== '' ? trim( $e->getMessage() ) : 'The Blogger import failed.';
                if ( str_contains( strtolower( $e->getMessage() ), 'media directory' ) ) {
                    $message = 'The Blogger import failed. Check that the optional local media directory still exists and is readable.';
                }
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => $message ], 500 );
                    return;
                }
                $security->setFlash( 'blogger-import.notice', $build_notice( 'danger', $message ) );
            }

            $plugins->redirect( ! empty( $result['done'] ) ? ( $report_url . '?preview=' . rawurlencode( $preview_token ) ) : ( $preview_url . '?preview=' . rawurlencode( $preview_token ) ) );
        }
    );
};
