<?php

require_once __DIR__ . '/TinyMashBackupArchiveService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashExportService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'backup' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $export_service = $plugins->getService( 'export.service' );
    $archive_service = new TinyMashBackupArchiveService(
        $project_root . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'plugin-backup'
    );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $export_service instanceof TinyMashExportService ) {
        throw new \RuntimeException( 'Required services for the backup plugin are not available.' );
    }

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Backup',
            'summary' => 'Controls how generated ZIP archives are delivered to the browser.',
            'fields' => [
                [
                    'key' => 'download_delivery_mode',
                    'type' => 'select',
                    'label' => 'ZIP delivery mode',
                    'help' => 'Use direct PHP streaming by default, or hand completed ZIP files off to Nginx or Apache when the server is configured for it.',
                    'default' => 'php_stream',
                    'options' => [
                        [ 'value' => 'php_stream', 'label' => 'PHP stream (default)' ],
                        [ 'value' => 'nginx_x_accel', 'label' => 'Nginx X-Accel-Redirect' ],
                        [ 'value' => 'apache_x_sendfile', 'label' => 'Apache X-Sendfile' ],
                    ],
                ],
                [
                    'key' => 'nginx_internal_prefix',
                    'type' => 'text',
                    'label' => 'Nginx internal download prefix',
                    'help' => 'Only used with Nginx X-Accel-Redirect. Example: /_tm-protected-downloads',
                    'default' => '/_tm-protected-downloads',
                ],
            ],
        ]
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $backup_url = $admin_url . '/backup';
    $author_download_url = $backup_url . '/download-author';
    $site_download_url = $backup_url . '/download-site';
    $restore_inspect_url = $backup_url . '/restore/inspect';
    $restore_apply_url = $backup_url . '/restore/apply';
    $restore_cancel_url = $backup_url . '/restore/cancel';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'account',
        [
            'section' => 'backup',
            'label' => 'Backup',
            'url' => $backup_url,
            'icon' => 'bi-download',
            'order' => 80,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-backup',
        [
            'title' => 'Backup',
            'summary' => 'Download your own data or a full site backup.',
            'group' => 'Plugins',
            'order' => 110,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Backup packages the core export bridge into downloadable ZIP archives.',
                ],
                [
                    'title' => 'Author downloads',
                    'markdown' => 'Every logged-in user can download an author bundle containing their profile, content, drafts, media, and data from active plugins that register export contributors.',
                ],
                [
                    'title' => 'Site backups',
                    'markdown' => 'Admins can download a full site bundle containing config, users, content, drafts, media, and data from active plugins that register export contributors.',
                ],
                [
                    'title' => 'Site restore',
                    'markdown' => 'Admins can inspect a tinymash site-backup ZIP before replacing the current site data. Restore validates the complete bundle again, uses core rollback on failure, and signs the current admin out when it completes.',
                ],
            ],
        ]
    );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function() use ( $archive_service ) : string {
            $removed = $archive_service->cleanupExpiredWorkspaces();
            return(
                'removed '
                . $removed
                . ' stale backup workspace(s)'
            );
        },
        'cleanup',
        'Prune stale backup workspaces'
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $format_bytes = static function( int $bytes ) : string {
        $bytes = max( 0, $bytes );
        if ( $bytes < 1024 ) {
            return( $bytes . ' B' );
        }
        if ( $bytes < 1048576 ) {
            return( number_format( $bytes / 1024, 1 ) . ' KB' );
        }
        if ( $bytes < 1073741824 ) {
            return( number_format( $bytes / 1048576, 1 ) . ' MB' );
        }
        return( number_format( $bytes / 1073741824, 2 ) . ' GB' );
    };

    $normalize_name_part = static function( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9._-]+/', '-', $value ) ?? '';
        $value = trim( $value, '-.' );
        return( $value !== '' ? $value : 'tinymash' );
    };

    $build_archive_basename = static function( string $scope, string $site_name, string $username = '' ) use ( $normalize_name_part ) : string {
        $site_name = $normalize_name_part( $site_name );
        $timestamp = gmdate( 'Ymd_His' );
        if ( $scope === 'author' ) {
            return( 'tinymash-author-' . $normalize_name_part( $username ) . '-' . $timestamp );
        }

        return( 'tinymash-site-' . $site_name . '-' . $timestamp );
    };

    $build_nginx_internal_uri = static function( string $prefix, string $relative_path ) : string {
        $prefix = '/' . trim( $prefix, '/' );
        if ( $prefix === '/' ) {
            $prefix = '/_tm-protected-downloads';
        }

        $segments = array_values(
            array_filter(
                explode( '/', str_replace( '\\', '/', $relative_path ) ),
                static fn( mixed $segment ) : bool => is_string( $segment ) && trim( $segment ) !== ''
            )
        );
        if ( empty( $segments ) ) {
            return( '' );
        }

        $encoded_segments = array_map(
            static fn( string $segment ) : string => rawurlencode( $segment ),
            $segments
        );

        return( $prefix . '/' . implode( '/', $encoded_segments ) );
    };

    $send_archive_download = static function( array $archive ) use ( $archive_service, $plugins, $plugin_key, $build_nginx_internal_uri ) : void {
        $archive_path = (string) ( $archive['archive_path'] ?? '' );
        $download_filename = trim( (string) ( $archive['download_filename'] ?? basename( $archive_path ) ) );
        $workspace_directory = (string) ( $archive['workspace_directory'] ?? '' );
        $plugin_settings = $plugins->getPluginSystemSettings( $plugin_key );
        $delivery_mode = strtolower( trim( (string) ( $plugin_settings['download_delivery_mode'] ?? 'php_stream' ) ) );
        if ( ! in_array( $delivery_mode, [ 'php_stream', 'nginx_x_accel', 'apache_x_sendfile' ], true ) ) {
            $delivery_mode = 'php_stream';
        }

        if ( $archive_path === '' || ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
            $archive_service->cleanupWorkspace( $workspace_directory );
            throw new \RuntimeException( 'The backup archive could not be read for download.' );
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        if ( ! headers_sent() ) {
            header( 'Content-Type: application/zip' );
            header( 'Content-Length: ' . (string) filesize( $archive_path ) );
            header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download_filename ) . '"' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }

        if ( $delivery_mode === 'nginx_x_accel' ) {
            $relative_path = $archive_service->getManagedRelativePath( $archive_path );
            $internal_prefix = trim( (string) ( $plugin_settings['nginx_internal_prefix'] ?? '/_tm-protected-downloads' ) );
            $internal_uri = $build_nginx_internal_uri( $internal_prefix, $relative_path );
            if ( $relative_path !== '' && $internal_uri !== '' ) {
                header( 'X-Accel-Redirect: ' . $internal_uri );
                exit;
            }
        }

        if ( $delivery_mode === 'apache_x_sendfile' ) {
            header( 'X-Sendfile: ' . $archive_path );
            exit;
        }

        readfile( $archive_path );
        $archive_service->cleanupWorkspace( $workspace_directory );
        exit;
    };

    $render_backup_page = static function( array $notice = [] ) use ( $plugins, $security, $config, $archive_service, $backup_url, $author_download_url, $site_download_url, $restore_inspect_url, $restore_apply_url, $restore_cancel_url, $escape, $format_bytes ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
            return;
        }

        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'backup.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $archive_service->cleanupExpiredWorkspaces();

        $csrf_token = $security->getCsrfToken();
        $archive_available = $archive_service->isAvailable();
        $username = trim( (string) $security->getCurrentUsername() );
        $is_superadmin = $security->isSuperAdmin();
        $restore_review = null;
        if ( $is_superadmin ) {
            $query = $plugins->getRequestQueryData();
            $restore_token = trim( (string) ( $query['restore'] ?? '' ) );
            if ( $restore_token !== '' ) {
                $restore_review = $archive_service->getRestoreReview( $restore_token, $username );
                if ( ! is_array( $restore_review ) && empty( $notice ) ) {
                    $notice = [
                        'type' => 'warning',
                        'message' => 'That restore review is no longer available. Inspect the backup again.',
                    ];
                }
            }
        }

        $body_html = '';
        if ( ! $archive_available ) {
            $body_html .= '<div class="alert alert-danger" role="alert">ZIP downloads are not available in this PHP runtime. The <code>ZipArchive</code> extension is required for this plugin.</div>';
        }

        $body_html .= '<section aria-labelledby="tm-backup-export-heading">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-1">Export</div>';
        $body_html .= '<h2 class="h5 mb-3" id="tm-backup-export-heading">Download data</h2>';
        $body_html .= '<div class="row g-4">';
        $body_html .= '<div class="col-12' . ( $is_superadmin ? ' col-xl-6' : '' ) . '">';
        $body_html .= '<div class="h-100 d-flex flex-column">';
        $body_html .= '<h3 class="h6 mb-1">Download my data</h3>';
        $body_html .= '<p class="text-body-secondary mb-3">Packages your profile, content, drafts, media, and data from active plugins that expose author export contributors.</p>';
        $body_html .= '<div class="small text-body-secondary mb-3">Current account: <span class="text-body fw-semibold">' . $escape( $username ) . '</span></div>';
        $body_html .= '<form class="mt-auto" method="post" action="' . $escape( $author_download_url ) . '">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
        $body_html .= '<button class="btn btn-primary" type="submit"' . ( $archive_available ? '' : ' disabled' ) . '>Download my data</button>';
        $body_html .= '</form>';
        $body_html .= '</div>';
        $body_html .= '</div>';

        if ( $is_superadmin ) {
            $body_html .= '<div class="col-12 d-xl-none"><hr class="my-0"></div>';
            $body_html .= '<div class="col-12 col-xl-6 ps-xl-4">';
            $body_html .= '<div class="h-100 d-flex flex-column">';
            $body_html .= '<h3 class="h6 mb-1">Download site backup</h3>';
            $body_html .= '<p class="text-body-secondary mb-3">Packages site config, users, content, drafts, media, and data from active plugins that expose site export contributors.</p>';
            $body_html .= '<form class="mt-auto" method="post" action="' . $escape( $site_download_url ) . '">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
            $body_html .= '<button class="btn btn-outline-primary" type="submit"' . ( $archive_available ? '' : ' disabled' ) . '>Download site backup</button>';
            $body_html .= '</form>';
            $body_html .= '</div>';
            $body_html .= '</div>';
        }
        $body_html .= '</div>';
        $body_html .= '</section>';

        if ( $is_superadmin ) {
            $body_html .= '<section class="mt-4 pt-4 border-top" aria-labelledby="tm-backup-restore-heading">';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-1">Restore</div>';
            $body_html .= '<h2 class="h5 mb-1" id="tm-backup-restore-heading">Restore site backup</h2>';
            $body_html .= '<p class="text-body-secondary mb-3">Inspect a tinymash site-backup ZIP before replacing the current site data.</p>';

            if ( ! is_array( $restore_review ) ) {
                $body_html .= '<form method="post" action="' . $escape( $restore_inspect_url ) . '" enctype="multipart/form-data" class="row g-3 align-items-end">';
                $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
                $body_html .= '<div class="col-12 col-xl-8"><label class="form-label small mb-1" for="tm-backup-restore-file">Backup ZIP</label><input class="form-control" id="tm-backup-restore-file" name="backup_archive" type="file" accept=".zip,application/zip" required' . ( $archive_available ? '' : ' disabled' ) . '></div>';
                $body_html .= '<div class="col-12 col-xl-4"><button class="btn btn-outline-primary" type="submit"' . ( $archive_available ? '' : ' disabled' ) . '>Inspect backup</button></div>';
                $body_html .= '</form>';
            } else {
                $inspection = is_array( $restore_review['inspection'] ?? null ) ? $restore_review['inspection'] : [];
                $counts = is_array( $inspection['counts'] ?? null ) ? $inspection['counts'] : [];
                $components = is_array( $inspection['components'] ?? null ) ? $inspection['components'] : [];
                $conflicts = is_array( $inspection['target_conflicts'] ?? null ) ? $inspection['target_conflicts'] : [];
                $token = (string) ( $restore_review['token'] ?? '' );

                $body_html .= '<div class="row g-4">';
                $body_html .= '<div class="col-12 col-xl-6">';
                $body_html .= '<h3 class="h6 mb-3">Inspection result</h3>';
                $body_html .= '<dl class="row small mb-0">';
                $body_html .= '<dt class="col-sm-5 text-body-secondary fw-normal">Archive</dt><dd class="col-sm-7 text-break">' . $escape( (string) ( $restore_review['original_filename'] ?? '' ) ) . '</dd>';
                $body_html .= '<dt class="col-sm-5 text-body-secondary fw-normal">Site</dt><dd class="col-sm-7">' . $escape( (string) ( $inspection['site_name'] ?? 'Unnamed site' ) ) . '</dd>';
                $body_html .= '<dt class="col-sm-5 text-body-secondary fw-normal">Exported UTC</dt><dd class="col-sm-7 font-monospace">' . $escape( (string) ( $inspection['exported_at_utc'] ?? '' ) ) . '</dd>';
                $body_html .= '<dt class="col-sm-5 text-body-secondary fw-normal">Format</dt><dd class="col-sm-7">Version ' . (int) ( $inspection['format_version'] ?? 0 ) . '</dd>';
                $body_html .= '<dt class="col-sm-5 text-body-secondary fw-normal">Files</dt><dd class="col-sm-7">' . (int) ( $inspection['file_count'] ?? 0 ) . ' (' . $escape( $format_bytes( (int) ( $inspection['expanded_size_bytes'] ?? 0 ) ) ) . ')</dd>';
                $body_html .= '</dl>';
                $body_html .= '</div>';
                $body_html .= '<div class="col-12 col-xl-6">';
                $body_html .= '<h3 class="h6 mb-3">Bundle contents</h3>';
                $body_html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th scope="col">Component</th><th scope="col" class="text-end">Count/status</th></tr></thead><tbody>';
                $body_html .= '<tr><td>Users</td><td class="text-end">' . (int) ( $counts['users'] ?? 0 ) . '</td></tr>';
                $body_html .= '<tr><td>Entries</td><td class="text-end">' . (int) ( $counts['entries'] ?? 0 ) . '</td></tr>';
                $body_html .= '<tr><td>Media</td><td class="text-end">' . ( ! empty( $components['media'] ) ? 'Included' : 'Empty' ) . '</td></tr>';
                $body_html .= '<tr><td>Drafts</td><td class="text-end">' . ( ! empty( $components['drafts'] ) ? 'Included' : 'Empty' ) . '</td></tr>';
                $body_html .= '<tr><td>Plugin data</td><td class="text-end">' . ( ! empty( $components['plugin_data'] ) ? 'Included' : 'None' ) . '</td></tr>';
                $body_html .= '</tbody></table></div>';
                $body_html .= '</div>';
                $body_html .= '</div>';

                $body_html .= '<div class="alert alert-warning mt-4 mb-3" role="alert"><div class="fw-semibold mb-1">This replaces the current site data.</div><div class="small">Config, users, content, drafts, and media will be replaced. Core files roll back if import fails; plugin-owned import effects are not part of that transaction.' . ( ! empty( $conflicts ) ? ' Existing data was found in: ' . $escape( implode( ', ', $conflicts ) ) . '.' : '' ) . '</div></div>';
                $body_html .= '<form method="post" action="' . $escape( $restore_apply_url ) . '" class="row g-3 align-items-end">';
                $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="restore_token" value="' . $escape( $token ) . '">';
                $body_html .= '<div class="col-12"><div class="form-check"><input class="form-check-input" id="tm-backup-restore-replace" name="replace_current" type="checkbox" value="1" required><label class="form-check-label" for="tm-backup-restore-replace">Replace the current site data with this backup</label></div></div>';
                $body_html .= '<div class="col-12 col-xl-7"><label class="form-label small mb-1" for="tm-backup-restore-confirmation">Type RESTORE to confirm</label><input class="form-control font-monospace" id="tm-backup-restore-confirmation" name="confirmation" type="text" autocomplete="off" required></div>';
                $body_html .= '<div class="col-12 col-xl-5"><button class="btn btn-danger" type="submit">Restore site backup</button></div>';
                $body_html .= '</form>';
                $body_html .= '<form method="post" action="' . $escape( $restore_cancel_url ) . '" class="mt-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="restore_token" value="' . $escape( $token ) . '"><button class="btn btn-link link-secondary p-0" type="submit">Cancel restore</button></form>';
            }
            $body_html .= '</section>';
        }

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Backup',
                'current_section' => 'backup',
                'current_location' => 'backup',
                'help_contexts' => [ 'plugin-backup' ],
                'plugin_page_kicker' => 'Backup',
                'plugin_page_title' => 'Backup and restore',
                'plugin_page_summary' => 'Download portable backups and inspect a site restore before applying it.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $backup_url,
        static function() use ( $plugins, $login_url, $security, $render_backup_page ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $render_backup_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $restore_inspect_url,
        static function() use ( $plugins, $security, $archive_service, $export_service, $backup_url, $login_url ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }
            if ( ! $security->isSuperAdmin() ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Only admins can inspect site restores.' ] );
                $plugins->redirect( $backup_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $backup_url );
                return;
            }

            try {
                $archive_service->cleanupExpiredWorkspaces();
                $review = $archive_service->prepareRestoreUpload(
                    is_array( $_FILES['backup_archive'] ?? null ) ? $_FILES['backup_archive'] : [],
                    (string) $security->getCurrentUsername(),
                    static fn( string $bundle_directory ) : array => $export_service->inspectSiteImport( $bundle_directory )
                );
                $plugins->redirect( $backup_url . '?restore=' . rawurlencode( (string) $review['token'] ) );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to inspect site backup (' . $e->getMessage() . ')' );
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => $e->getMessage() ] );
                $plugins->redirect( $backup_url );
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $restore_cancel_url,
        static function() use ( $plugins, $security, $archive_service, $backup_url, $login_url ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }
            if ( ! $security->isSuperAdmin() ) {
                $plugins->redirect( $backup_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $backup_url );
                return;
            }
            $archive_service->cleanupRestoreReview( (string) ( $data['restore_token'] ?? '' ), (string) $security->getCurrentUsername() );
            $plugins->redirect( $backup_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $restore_apply_url,
        static function() use ( $plugins, $security, $archive_service, $export_service, $backup_url, $login_url ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }
            if ( ! $security->isSuperAdmin() ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Only admins can restore a site backup.' ] );
                $plugins->redirect( $backup_url );
                return;
            }

            $data = $plugins->getRequestData();
            $token = trim( (string) ( $data['restore_token'] ?? '' ) );
            $redirect_url = $backup_url . ( $token !== '' ? '?restore=' . rawurlencode( $token ) : '' );
            if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }
            if ( empty( $data['replace_current'] ) || mb_strtoupper( mb_trim( (string) ( $data['confirmation'] ?? '' ) ) ) !== 'RESTORE' ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'Confirm replacement and type RESTORE before applying the backup.' ] );
                $plugins->redirect( $redirect_url );
                return;
            }

            $username = (string) $security->getCurrentUsername();
            $review = $archive_service->getRestoreReview( $token, $username );
            if ( ! is_array( $review ) ) {
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'That restore review is no longer available. Inspect the backup again.' ] );
                $plugins->redirect( $backup_url );
                return;
            }

            try {
                $inspection = $export_service->inspectSiteImport( (string) $review['bundle_directory'] );
                $result = $export_service->importSite( (string) $review['bundle_directory'], true, $plugins, true );
                $plugin_failures = count(
                    array_filter(
                        is_array( $result['plugin_imports'] ?? null ) ? $result['plugin_imports'] : [],
                        static fn( mixed $plugin_result ) : bool => is_array( $plugin_result ) && (string) ( $plugin_result['status'] ?? '' ) === 'error'
                    )
                );
                $restored_login_url = (string) ( $inspection['login_url'] ?? $login_url );
                $archive_service->cleanupWorkspace( (string) $review['workspace_directory'] );
                $security->logout();
                $security->setFlash( 'backup.restore_login_notice', [ 'plugin_warnings' => $plugin_failures ] );
                $plugins->redirect( $restored_login_url );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to restore site backup (' . $e->getMessage() . ')' );
                $security->setFlash( 'backup.notice', [ 'type' => 'danger', 'message' => 'The site restore failed. Core site data remains unchanged or was rolled back. ' . $e->getMessage() ] );
                $plugins->redirect( $redirect_url );
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $author_download_url,
        static function() use ( $plugins, $config, $security, $export_service, $archive_service, $build_archive_basename, $send_archive_download, $backup_url ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Your session token is invalid. Please reload the page and try again.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            if ( ! $archive_service->isAvailable() ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'ZIP downloads are not available in this PHP runtime.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            $username = trim( (string) $security->getCurrentUsername() );
            if ( $username === '' ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'You must be logged in to download your data.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            try {
                $archive_service->cleanupExpiredWorkspaces();
                $archive = $archive_service->createArchive(
                    $build_archive_basename( 'author', $config->getSiteName(), $username ),
                    static function( string $bundle_directory ) use ( $export_service, $username, $plugins ) : array {
                        return( $export_service->exportAuthor( $username, $bundle_directory, $plugins, true ) );
                    }
                );
                $send_archive_download( $archive );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to create author backup (' . $e->getMessage() . ')' );
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Your data could not be packaged right now.',
                    ]
                );
                $plugins->redirect( $backup_url );
            }
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $site_download_url,
        static function() use ( $plugins, $config, $security, $export_service, $archive_service, $build_archive_basename, $send_archive_download, $backup_url ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
                return;
            }

            if ( ! $security->isSuperAdmin() ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Only admins can download full site backups.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'Your session token is invalid. Please reload the page and try again.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            if ( ! $archive_service->isAvailable() ) {
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'ZIP downloads are not available in this PHP runtime.',
                    ]
                );
                $plugins->redirect( $backup_url );
                return;
            }

            try {
                $archive_service->cleanupExpiredWorkspaces();
                $archive = $archive_service->createArchive(
                    $build_archive_basename( 'site', $config->getSiteName() ),
                    static function( string $bundle_directory ) use ( $export_service, $plugins ) : array {
                        return( $export_service->exportSite( $bundle_directory, $plugins, true ) );
                    }
                );
                $send_archive_download( $archive );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to create site backup (' . $e->getMessage() . ')' );
                $security->setFlash(
                    'backup.notice',
                    [
                        'type' => 'danger',
                        'message' => 'The site backup could not be packaged right now.',
                    ]
                );
                $plugins->redirect( $backup_url );
            }
        }
    );
};
