<?php

require_once __DIR__ . '/TinyMashBluditImportService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'bludit-import' );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $user_repository = $plugins->getService( 'user.repository' );
    $media_import_bridge = $plugins->getService( 'media.import_bridge' );
    $import_lock_service = $plugins->getService( 'import.lock_service' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_repository instanceof TinyMashContentRepository || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Bludit import plugin are not available.' );
    }

    $project_root = dirname( __DIR__, 3 );
    $import_service = new TinyMashBluditImportService(
        $project_root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'plugin-bludit-import',
        $content_repository,
        $media_import_bridge instanceof TinyMashMediaImportBridge ? $media_import_bridge : null,
        $project_root,
        $import_lock_service instanceof TinyMashImportLockService ? $import_lock_service : null
    );

    $parse_cli_options = static function( array $arguments ) : array {
        $options = [
            'action' => strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ),
            'source' => (string) ( $arguments[1] ?? '' ),
            'target_scope' => 'root',
            'author' => '',
            'replace_existing_posts' => false,
            'replace_existing_pages' => false,
            'skip_unpublished' => false,
            'batch_size' => 25,
            'keep_preview' => false,
            'force_lock' => false,
        ];

        foreach ( array_slice( $arguments, 2 ) as $argument ) {
            $argument = (string) $argument;
            if ( $argument === '--root' ) {
                $options['target_scope'] = 'root';
                $options['author'] = '';
                continue;
            }
            if ( str_starts_with( $argument, '--author=' ) ) {
                $options['target_scope'] = 'author';
                $options['author'] = strtolower( trim( substr( $argument, 9 ) ) );
                continue;
            }
            if ( $argument === '--replace-existing-posts' || $argument === '--replace-posts' ) {
                $options['replace_existing_posts'] = true;
                continue;
            }
            if ( $argument === '--replace-existing-pages' || $argument === '--replace-pages' ) {
                $options['replace_existing_pages'] = true;
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
        fwrite( STDOUT, 'source_title: ' . (string) ( $preview['source_title'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'source_directory: ' . (string) ( $preview['source_directory'] ?? '-' ) . PHP_EOL );
        fwrite( STDOUT, 'target_scope: ' . (string) ( $preview['target_scope'] ?? 'root' ) . PHP_EOL );
        fwrite( STDOUT, 'target_author: ' . (string) ( $preview['target_author_slug'] ?? '' ) . PHP_EOL );
        fwrite( STDOUT, 'entries: ' . (int) ( $counts['total'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'posts: ' . (int) ( $counts['posts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'pages: ' . (int) ( $counts['pages'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'unpublished: ' . (int) ( $counts['drafts'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'skipped_unpublished: ' . (int) ( $counts['skipped_unpublished'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'uploads_files: ' . (int) ( $preview['uploads_file_count'] ?? 0 ) . PHP_EOL );
        fwrite( STDOUT, 'homepage: ' . (string) ( $preview['source_homepage'] ?? '' ) . PHP_EOL );
    };

    $resolve_cli_target = static function( array $options ) use ( $user_repository ) : array {
        $target_scope = (string) ( $options['target_scope'] ?? 'root' );
        $target_author = strtolower( trim( (string) ( $options['author'] ?? '' ) ) );

        if ( $target_scope === 'author' ) {
            if ( $target_author === '' || ! is_array( $user_repository->getUserByUsername( $target_author ) ) ) {
                throw new \RuntimeException( 'A valid --author=<username> is required for author-space Bludit import.' );
            }

            return( [ 'target_scope' => 'author', 'target_author_slug' => $target_author ] );
        }

        return( [ 'target_scope' => 'root', 'target_author_slug' => '' ] );
    };

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'bludit-import',
                'usage' => 'tinymash bludit-import <preview|import> <site-directory> [--root|--author=<username>] [--replace-existing-posts] [--replace-existing-pages] [--skip-unpublished] [--batch-size=<n>] [--keep-preview] [--force-lock]',
                'summary' => 'Preview or import a local Bludit site tree into root or an author space from the CLI.',
                'order' => 230,
                'handler' => static function( array $arguments ) use ( $parse_cli_options, $write_preview_summary, $resolve_cli_target, $import_service ) : void {
                    $options = $parse_cli_options( $arguments );
                    $action = (string) ( $options['action'] ?? '' );
                    if ( ! in_array( $action, [ 'preview', 'import' ], true ) ) {
                        throw new \RuntimeException( 'usage: tinymash bludit-import <preview|import> <site-directory> [--root|--author=<username>] [--replace-existing-posts] [--replace-existing-pages] [--skip-unpublished] [--batch-size=<n>] [--keep-preview] [--force-lock]' );
                    }

                    $source_directory = trim( (string) ( $options['source'] ?? '' ) );
                    if ( $source_directory === '' ) {
                        throw new \RuntimeException( 'The Bludit site directory is required.' );
                    }

                    $target = $resolve_cli_target( $options );
                    $preview = $import_service->createPreviewFromDirectory(
                        $source_directory,
                        (string) ( $target['target_scope'] ?? 'root' ),
                        (string) ( $target['target_author_slug'] ?? '' ),
                        (string) ( $target['target_scope'] === 'author' ? $target['target_author_slug'] : 'root' ),
                        ! empty( $options['replace_existing_posts'] ),
                        ! empty( $options['replace_existing_pages'] ),
                        ! empty( $options['skip_unpublished'] ),
                        false
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
                    fwrite( STDOUT, 'missing_media: ' . (int) ( $counts['missing_media'] ?? 0 ) . PHP_EOL );

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
    $plugin_url = $admin_url . '/bludit-import';
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
            'section' => 'bludit-import',
            'label' => 'Bludit import',
            'url' => $plugin_url,
            'icon' => 'bi-box-arrow-in-down',
            'group' => 'import',
            'group_label' => 'Import',
            'order' => 77,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-bludit-import',
        [
            'title' => 'Bludit import',
            'summary' => 'Import content from a local Bludit site tree into root or an author space.',
            'group' => 'Plugins',
            'order' => 122,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it imports',
                    'markdown' => 'Bludit import reads a local Bludit site tree, previews posts and pages, and imports them into root or an author space while preserving hierarchy where tinymash supports it.',
                ],
                [
                    'title' => 'Media',
                    'markdown' => 'The importer localizes images from the Bludit `bl-content/uploads/` tree through the shared tinymash media-import bridge.',
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

    $emit_json = static function( TinyMashPlugins $plugins, array $payload, int $status = 200 ) : void {
        $plugins->setResponseStatus( $status );
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=UTF-8' );
        }
        echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    };

    $is_json_request = static function() : bool {
        $accept = strtolower( trim( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) ) );
        $requested_with = strtolower( trim( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) ) );
        return( str_contains( $accept, 'application/json' ) || $requested_with === 'xmlhttprequest' );
    };

    $build_author_options = static function() use ( $user_repository ) : array {
        $options = [];
        foreach ( $user_repository->getAllUsers() as $user ) {
            if ( ! is_array( $user ) || empty( $user['username'] ) || empty( $user['active'] ) ) {
                continue;
            }

            $username = strtolower( trim( (string) ( $user['username'] ?? '' ) ) );
            if ( $username === '' ) {
                continue;
            }

            $options[] = [
                'value' => $username,
                'label' => $username,
            ];
        }

        usort(
            $options,
            static fn( array $left, array $right ) : int => strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) )
        );

        return( $options );
    };

    $resolve_target = static function( array $data ) use ( $security, $user_repository ) : array {
        if ( ! $security->isSuperAdmin() ) {
            $current_username = strtolower( trim( (string) $security->getCurrentUsername() ) );
            if ( $current_username === '' || ! is_array( $user_repository->getUserByUsername( $current_username ) ) ) {
                throw new \RuntimeException( 'A valid target author account is required.' );
            }

            return(
                [
                    'target_scope' => 'author',
                    'target_author_slug' => $current_username,
                ]
            );
        }

        $target_scope = trim( (string) ( $data['target_scope'] ?? 'root' ) ) === 'author' ? 'author' : 'root';
        if ( $target_scope === 'author' ) {
            $target_author_slug = strtolower( trim( (string) ( $data['author_slug'] ?? '' ) ) );
            if ( $target_author_slug === '' || ! is_array( $user_repository->getUserByUsername( $target_author_slug ) ) ) {
                throw new \RuntimeException( 'A valid target author account is required.' );
            }

            return(
                [
                    'target_scope' => 'author',
                    'target_author_slug' => $target_author_slug,
                ]
            );
        }

        return(
            [
                'target_scope' => 'root',
                'target_author_slug' => '',
            ]
        );
    };

    $log_plugin_error = static function( string $context, \Throwable $exception ) : void {
        error_log( '[bludit-import] ' . $context . ': ' . $exception->getMessage() );
    };

    $render_page = static function( string $mode = 'setup', array $notice = [] ) use (
        $plugins,
        $security,
        $date_formatter,
        $import_service,
        $import_lock_service,
        $plugin_url,
        $preview_url,
        $report_url,
        $import_url,
        $login_url,
        $escape,
        $build_notice,
        $build_author_options,
        $max_execution_time,
        $import_batch_size
    ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }

        if ( empty( $notice ) ) {
            $notice = $security->pullFlash( 'bludit-import.notice' );
            if ( ! is_array( $notice ) ) {
                $notice = [];
            }
        }

        $data = array_merge( $plugins->getRequestQueryData(), $plugins->getRequestData() );
        $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview'] ?? '' ) ) ) ) ?? '';
        $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
        $import_state = $preview_token !== '' ? $import_service->getImportState( $preview_token ) : null;
        $csrf_token = $security->getCsrfToken();
        $username = strtolower( trim( (string) $security->getCurrentUsername() ) );
        $is_superadmin = $security->isSuperAdmin();
        $author_options = $build_author_options();
        $selected_scope = is_array( $preview ) ? (string) ( $preview['target_scope'] ?? 'root' ) : trim( (string) ( $data['target_scope'] ?? 'root' ) );
        $selected_author = is_array( $preview ) ? (string) ( $preview['target_author_slug'] ?? '' ) : trim( (string) ( $data['author_slug'] ?? $username ) );
        $site_directory = is_array( $preview ) ? (string) ( $preview['source_directory'] ?? '' ) : trim( (string) ( $data['bludit_site_directory'] ?? '' ) );
        $timeout_warning = $max_execution_time > 0 && $max_execution_time < 120;
        $has_conflicting_lock = false;
        $conflicting_lock_is_stale = false;
        if ( is_array( $preview ) && $import_lock_service instanceof TinyMashImportLockService ) {
            $target_lock_key = (string) ( ( $preview['target_scope'] ?? 'root' ) === 'author' ? ( $preview['target_author_slug'] ?? '' ) : 'root' );
            $lock = $import_lock_service->getAuthorLock( $target_lock_key );
            if ( is_array( $lock ) && (string) ( $lock['token'] ?? '' ) !== (string) ( $preview['token'] ?? '' ) ) {
                $has_conflicting_lock = true;
                $conflicting_lock_is_stale = ! empty( $lock['is_stale'] );
            }
        }

        $body_html = '<div class="row g-3">';
        if ( $mode === 'setup' ) {
            $body_html .= '<div class="col-12">';
            $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Preview input</div>';
            $body_html .= '<form method="post" action="' . $escape( $preview_url ) . '" class="row g-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
            $body_html .= '<div class="col-12"><label class="form-label" for="bludit-import-directory">Bludit site directory</label><input class="form-control font-monospace" id="bludit-import-directory" name="bludit_site_directory" value="' . $escape( $site_directory ) . '" placeholder="/path/to/bludit-site" required><div class="form-text">Point this at the root of the Bludit site directory, for example the directory that contains <code>bl-content/</code> and <code>bl-kernel/</code>.</div></div>';
            if ( $is_superadmin ) {
                $body_html .= '<div class="col-12 col-lg-4"><label class="form-label" for="bludit-import-target-scope">Import into</label><select class="form-select" id="bludit-import-target-scope" name="target_scope">';
                $body_html .= '<option value="root"' . ( $selected_scope !== 'author' ? ' selected' : '' ) . '>root</option>';
                $body_html .= '<option value="author"' . ( $selected_scope === 'author' ? ' selected' : '' ) . '>author space</option>';
                $body_html .= '</select></div>';
                $body_html .= '<div class="col-12 col-lg-8"><label class="form-label" for="bludit-import-author">Target author</label><select class="form-select" id="bludit-import-author" name="author_slug">';
                foreach ( $author_options as $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $value = (string) ( $option['value'] ?? '' );
                    $label = (string) ( $option['label'] ?? '' );
                    $body_html .= '<option value="' . $escape( $value ) . '"' . ( $selected_author === $value ? ' selected' : '' ) . '>' . $escape( $label ) . '</option>';
                }
                $body_html .= '</select></div>';
            } else {
                $body_html .= '<input type="hidden" name="target_scope" value="author"><input type="hidden" name="author_slug" value="' . $escape( $username ) . '">';
                $body_html .= '<div class="col-12"><div class="small text-uppercase text-body-secondary mb-2">Target</div><div class="fw-semibold font-monospace">' . $escape( $username ) . '</div></div>';
            }
            $body_html .= '<div class="col-12 col-lg-4"><div class="form-check mt-4"><input class="form-check-input" id="bludit-import-replace-posts" name="replace_existing_posts" type="checkbox" value="1"><label class="form-check-label" for="bludit-import-replace-posts">Replace existing posts first</label></div></div>';
            $body_html .= '<div class="col-12 col-lg-4"><div class="form-check mt-4"><input class="form-check-input" id="bludit-import-replace-pages" name="replace_existing_pages" type="checkbox" value="1"><label class="form-check-label" for="bludit-import-replace-pages">Replace existing pages first</label></div></div>';
            $body_html .= '<div class="col-12 col-lg-4"><div class="form-check mt-4"><input class="form-check-input" id="bludit-import-skip-unpublished" name="skip_unpublished" type="checkbox" value="1"><label class="form-check-label" for="bludit-import-skip-unpublished">Skip unpublished source entries</label></div></div>';
            $body_html .= '<div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Preview import</button></div>';
            $body_html .= '</form>';
            $body_html .= '</section></div>';
        }

        if ( is_array( $preview ) && in_array( $mode, [ 'preview', 'report' ], true ) ) {
            $counts = is_array( $preview['counts'] ?? null ) ? $preview['counts'] : [];
            $entries = is_array( $preview['entries'] ?? null ) ? $preview['entries'] : [];
            $import_running = is_array( $import_state ) && (string) ( $import_state['status'] ?? '' ) === 'running';
            $import_finished = is_array( $import_state ) && (string) ( $import_state['status'] ?? '' ) === 'finished';
            $body_html .= '<div class="col-12"><section class="p-3 bg-body border rounded-3 shadow-sm">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Preview</div>';
            $body_html .= '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">';
            $body_html .= '<div><h2 class="h5 mb-1">' . $escape( (string) ( $preview['source_title'] ?? 'Bludit site' ) ) . '</h2><div class="small text-body-secondary">Import into <span class="font-monospace text-body fw-semibold">' . $escape( (string) ( $preview['target_scope'] ?? 'root' ) === 'author' ? (string) ( $preview['target_author_slug'] ?? '' ) : 'root' ) . '</span>.</div></div>';
            if ( $mode === 'preview' ) {
                $body_html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary" id="bludit-import-start" type="button" data-import-url="' . $escape( $import_url ) . '" data-plugin-url="' . $escape( $plugin_url ) . '" data-preview-token="' . $escape( (string) ( $preview['token'] ?? '' ) ) . '" data-csrf-token="' . $escape( $csrf_token ) . '"' . ( $has_conflicting_lock && ! $conflicting_lock_is_stale ? ' disabled' : '' ) . '>' . ( $import_running ? 'Resume import' : 'Import now' ) . '</button></div>';
            } else {
                $body_html .= '<div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary btn-sm" href="' . $escape( $plugin_url ) . '">Import another site</a></div>';
            }
            $body_html .= '</div>';
            if ( $has_conflicting_lock ) {
                $body_html .= '<div class="small ' . ( $conflicting_lock_is_stale ? 'text-warning-emphasis' : 'text-danger' ) . ' mt-2">Another import currently holds this target-space lock.' . ( $conflicting_lock_is_stale ? ' You can override the stale lock.' : '' ) . '</div>';
            }
            if ( $conflicting_lock_is_stale && $mode === 'preview' ) {
                $body_html .= '<div class="form-check mt-3"><input class="form-check-input" id="bludit-import-force-lock" type="checkbox" value="1"><label class="form-check-label" for="bludit-import-force-lock">Override stale import lock</label></div>';
            }
            $body_html .= '<div class="row g-3 mt-1">';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Entries</div><div class="fw-semibold">' . (int) ( $counts['total'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Posts</div><div class="fw-semibold">' . (int) ( $counts['posts'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Pages</div><div class="fw-semibold">' . (int) ( $counts['pages'] ?? 0 ) . '</div></div></div>';
            $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Uploads</div><div class="fw-semibold">' . (int) ( $preview['uploads_file_count'] ?? 0 ) . '</div></div></div>';
            $body_html .= '</div>';
            $body_html .= '<div class="small text-body-secondary mt-3">Directory: <span class="font-monospace">' . $escape( (string) ( $preview['source_directory'] ?? '' ) ) . '</span></div>';
            if ( ! empty( $preview['source_homepage'] ) ) {
                $body_html .= '<div class="small text-body-secondary mt-1">Homepage: <code>' . $escape( (string) ( $preview['source_homepage'] ?? '' ) ) . '</code></div>';
            }

            if ( $mode === 'preview' ) {
                $progress_processed = is_array( $import_state ) ? (int) ( $import_state['counts']['processed'] ?? 0 ) : 0;
                $progress_imported = is_array( $import_state ) ? (int) ( $import_state['counts']['imported'] ?? 0 ) : 0;
                $progress_skipped = is_array( $import_state ) ? (int) ( $import_state['counts']['skipped'] ?? 0 ) : 0;
                $progress_total = (int) ( $counts['total'] ?? 0 );
                $progress_percent = $progress_total > 0 ? (int) floor( ( min( $progress_processed, $progress_total ) / $progress_total ) * 100 ) : 0;
                $progress_hidden = $import_running || $import_finished ? '' : ' d-none';
                $spinner_hidden = $import_finished ? ' d-none' : '';
                $preview_table_hidden = $import_running || $import_finished ? ' d-none' : '';
                $progress_message = $import_finished ? 'Import finished.' : ( $import_running ? 'Import in progress. This runs in smaller batches so larger datasets do not depend on one long request.' : 'Import runs in smaller batches so larger datasets do not depend on one long request.' );
                $body_html .= '<section class="mt-3 p-3 border rounded-3 bg-body-tertiary' . $progress_hidden . '" id="bludit-import-progress">';
                $body_html .= '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-start"><div><div class="small text-uppercase text-body-secondary mb-1">Import progress</div><div class="small text-body-secondary d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-secondary' . $spinner_hidden . '" id="bludit-import-progress-spinner" aria-hidden="true"></span><span id="bludit-import-progress-message">' . $escape( $progress_message ) . '</span></div></div><div class="small text-body-secondary"><span id="bludit-import-progress-counts">' . $progress_imported . ' imported, ' . $progress_skipped . ' skipped, ' . $progress_processed . ' / ' . $progress_total . ' processed</span></div></div>';
                $body_html .= '<div class="progress mt-3" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $progress_percent . '"><div class="progress-bar" id="bludit-import-progress-bar" style="width: ' . $progress_percent . '%">' . $progress_percent . '%</div></div>';
                $body_html .= '</section>';
                $body_html .= '<div class="mt-3' . $preview_table_hidden . '" id="bludit-import-preview-table-wrap">';
            } else {
                $body_html .= '<div class="mt-3">';
            }

            $body_html .= '<div class="table-responsive border rounded-3"><div style="max-height: 32rem; overflow-y: auto;"><table class="table table-sm align-middle mb-0">';
            $body_html .= '<thead><tr><th class="fw-normal sticky-top bg-body">Title</th><th class="fw-normal sticky-top bg-body">Type</th><th class="fw-normal sticky-top bg-body">Status</th><th class="fw-normal sticky-top bg-body">Path</th><th class="fw-normal sticky-top bg-body">Updated</th></tr></thead><tbody>';
            foreach ( array_slice( $entries, 0, 100 ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $updated_at_utc = trim( (string) ( $entry['updated_at_utc'] ?? '' ) );
                $updated_display = $updated_at_utc !== '' ? $date_formatter->formatUtcDateTime( $updated_at_utc ) : 'Unknown';
                $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $entry['title'] ?? '' ) ) . '</div></td><td class="align-top text-body-secondary">' . $escape( ucfirst( (string) ( $entry['entry_type'] ?? 'page' ) ) ) . '</td><td class="align-top text-body-secondary">' . $escape( (string) ( $entry['status'] ?? 'published' ) === 'published' ? 'Published' : 'Unpublished' ) . '</td><td class="align-top"><code>' . $escape( (string) ( $entry['source_path'] ?? '' ) ) . '</code></td><td class="align-top text-body-secondary small">' . $escape( $updated_display ) . '</td></tr>';
            }
            $body_html .= '</tbody></table></div></div>';
            if ( count( $entries ) > 100 ) {
                $body_html .= '<div class="small text-body-secondary mt-3">Showing the first 100 items in the preview table.</div>';
            }
            $body_html .= '</div>';

            if ( $mode === 'report' && is_array( $import_state ) ) {
                $report_counts = is_array( $import_state['counts'] ?? null ) ? $import_state['counts'] : [];
                $missing_media = is_array( $import_state['missing_media'] ?? null ) ? array_values( array_filter( $import_state['missing_media'], static fn( mixed $item ) : bool => is_array( $item ) ) ) : [];
                $body_html .= '<div class="row g-3 mt-1">';
                $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Imported</div><div class="fw-semibold">' . (int) ( $report_counts['imported'] ?? 0 ) . '</div></div></div>';
                $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Skipped</div><div class="fw-semibold">' . (int) ( $report_counts['skipped'] ?? 0 ) . '</div></div></div>';
                $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Removed posts</div><div class="fw-semibold">' . (int) ( $report_counts['deleted_existing_posts'] ?? 0 ) . '</div></div></div>';
                $body_html .= '<div class="col-6 col-lg-3"><div class="p-3 border rounded-3 bg-body-tertiary h-100"><div class="small text-uppercase text-body-secondary mb-1">Removed pages</div><div class="fw-semibold">' . (int) ( $report_counts['deleted_existing_pages'] ?? 0 ) . '</div></div></div>';
                $body_html .= '</div>';
                if ( ! empty( $missing_media ) ) {
                    $body_html .= '<div class="mt-3"><div class="small text-uppercase text-body-secondary mb-2">Missing media</div><div class="table-responsive border rounded-3"><table class="table table-sm align-middle mb-0"><thead><tr><th class="fw-normal">Title</th><th class="fw-normal">Slug</th><th class="fw-normal">Path</th></tr></thead><tbody>';
                    foreach ( array_slice( $missing_media, 0, 25 ) as $item ) {
                        if ( ! is_array( $item ) ) {
                            continue;
                        }
                        $body_html .= '<tr><td class="align-top"><div class="fw-semibold">' . $escape( (string) ( $item['title'] ?? '' ) ) . '</div><div class="small text-body-secondary">' . $escape( ucfirst( (string) ( $item['entry_type'] ?? 'page' ) ) ) . '</div></td><td class="align-top"><code>' . $escape( (string) ( $item['slug'] ?? '' ) ) . '</code></td><td class="align-top"><code>' . $escape( (string) ( $item['path'] ?? '' ) ) . '</code></td></tr>';
                    }
                    $body_html .= '</tbody></table></div></div>';
                }
            }

            $body_html .= '</section></div>';
        }

        $body_html .= '</div>';

        if ( $mode === 'preview' ) {
            $body_html .= <<<HTML
<script>
(() => {
    const startButton = document.getElementById('bludit-import-start');
    if (!startButton) {
        return;
    }

    const progressSection = document.getElementById('bludit-import-progress');
    const progressMessage = document.getElementById('bludit-import-progress-message');
    const progressCounts = document.getElementById('bludit-import-progress-counts');
    const progressBar = document.getElementById('bludit-import-progress-bar');
    const progressSpinner = document.getElementById('bludit-import-progress-spinner');
    const previewTableWrap = document.getElementById('bludit-import-preview-table-wrap');
    const forceLockCheckbox = document.getElementById('bludit-import-force-lock');
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
                progressMessage.textContent = error.message || 'The Bludit import failed.';
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
                'title' => APP_NAME . APP_TITLE_SEP . 'Bludit import',
                'current_section' => 'bludit-import',
                'current_location' => 'bludit-import',
                'help_contexts' => [ 'plugin-bludit-import' ],
                'plugin_page_kicker' => 'Import',
                'plugin_page_title' => $mode === 'report' ? 'Bludit import report' : ( $mode === 'preview' ? 'Bludit import preview' : 'Bludit import' ),
                'plugin_page_summary' => $mode === 'report'
                    ? 'Review the result of a completed Bludit import.'
                    : ( $mode === 'preview'
                        ? 'Review the parsed Bludit content before starting the import.'
                        : 'Bring a local Bludit site tree into root or an author space.' ),
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
        static function() use ( $plugins, $security, $import_service, $login_url, $plugin_url, $preview_url, $build_notice, $resolve_target, $log_plugin_error ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            if ( ! $import_service->isAvailable() ) {
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', 'DOM and JSON support are required for Bludit import in this PHP runtime.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            try {
                $target = $resolve_target( $data );
                $preview = $import_service->createPreviewFromDirectory(
                    (string) ( $data['bludit_site_directory'] ?? '' ),
                    (string) ( $target['target_scope'] ?? 'root' ),
                    (string) ( $target['target_author_slug'] ?? '' ),
                    strtolower( trim( (string) $security->getCurrentUsername() ) ),
                    ! empty( $data['replace_existing_posts'] ),
                    ! empty( $data['replace_existing_pages'] ),
                    ! empty( $data['skip_unpublished'] ),
                    true
                );
            } catch ( \Throwable $e ) {
                $log_plugin_error( 'preview failed', $e );
                $message = trim( $e->getMessage() ) !== '' ? trim( $e->getMessage() ) : 'The Bludit site directory could not be previewed.';
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', $message ) );
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
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', 'Your session token is invalid. Please reload the page and try again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }

            $preview_token = preg_replace( '/[^a-z0-9_]+/', '', strtolower( trim( (string) ( $data['preview_token'] ?? '' ) ) ) ) ?? '';
            $preview = $preview_token !== '' ? $import_service->getPreview( $preview_token ) : null;
            if ( ! is_array( $preview ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'The Bludit import preview is missing or expired. Please preview the site again.' ], 404 );
                    return;
                }
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', 'The Bludit import preview is missing or expired. Please preview the site again.' ) );
                $plugins->redirect( $plugin_url );
                return;
            }
            if ( (string) ( $preview['created_by_username'] ?? $preview['target_author_slug'] ?? '' ) !== strtolower( trim( (string) $security->getCurrentUsername() ) ) ) {
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => 'That Bludit import preview does not belong to the current account.' ], 403 );
                    return;
                }
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', 'That Bludit import preview does not belong to the current account.' ) );
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
                    $message = 'Imported ' . $imported_count . ' Bludit entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    $notice_type = 'success';
                    if ( $deleted_existing_posts > 0 || $deleted_existing_pages > 0 ) {
                        $message = 'Removed ' . $deleted_existing_posts . ' existing post' . ( $deleted_existing_posts === 1 ? '' : 's' ) . ' and ' . $deleted_existing_pages . ' existing page' . ( $deleted_existing_pages === 1 ? '' : 's' ) . ', then imported ' . $imported_count . ' Bludit entr' . ( $imported_count === 1 ? 'y' : 'ies' ) . '.';
                    }
                    if ( $skipped_count > 0 ) {
                        $message .= ' Skipped ' . $skipped_count . ' entr' . ( $skipped_count === 1 ? 'y' : 'ies' ) . '.';
                        $notice_type = 'warning';
                    }
                    $security->setFlash( 'bludit-import.notice', $build_notice( $notice_type, $message ) );
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
                $message = trim( $e->getMessage() ) !== '' ? trim( $e->getMessage() ) : 'The Bludit import failed.';
                if ( $expects_json ) {
                    $emit_json( $plugins, [ 'error' => $message ], 500 );
                    return;
                }
                $security->setFlash( 'bludit-import.notice', $build_notice( 'danger', $message ) );
            }

            $plugins->redirect( ! empty( $result['done'] ) ? ( $report_url . '?preview=' . rawurlencode( $preview_token ) ) : ( $preview_url . '?preview=' . rawurlencode( $preview_token ) ) );
        }
    );
};
