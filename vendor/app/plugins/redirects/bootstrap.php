<?php

require_once __DIR__ . '/TinyMashRedirectsService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'redirects' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_target_picker = $plugins->getService( 'content.target_picker' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_target_picker instanceof TinyMashContentTargetPickerService ) {
        throw new \RuntimeException( 'Required services for the Redirects plugin are not available.' );
    }

    $redirects_service = new TinyMashRedirectsService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'redirects' . DIRECTORY_SEPARATOR . 'redirects.json',
        $content_target_picker
    );
    \Flight::set( 'redirects.service', $redirects_service );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $redirects_url = $admin_url . '/redirects';
    $save_url = $redirects_url . '/save';
    $delete_url = $redirects_url . '/delete';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'redirects',
            'label' => 'Redirects',
            'url' => $redirects_url,
            'icon' => 'bi-signpost-split',
            'order' => 70,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-redirects',
        [
            'title' => 'Redirects',
            'summary' => 'Preserve old public URLs by redirecting them to current tinymash content.',
            'group' => 'Plugins',
            'order' => 120,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Redirects a specific `/path` to a page or a post. One use for this plugin is for site migration and the preservation of permalinks. It does not support linking a path to an external target.',
                ],
                [
                    'title' => 'Matching',
                    'markdown' => 'Normal tinymash content routes win first; redirects are checked when a request would otherwise be not found. Query strings in incoming requests are ignored.',
                ],
                [
                    'title' => 'Targets',
                    'markdown' => 'Targets are internal only.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $require_admin = static function() use ( $plugins, $security, $login_url, $admin_url ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'redirects',
                    'current_location' => 'redirects',
                    'plugin_page_kicker' => 'Redirects',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Redirect management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage redirects.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . htmlspecialchars( $admin_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }

        return( true );
    };

    $build_status_options = static function( int $current_status ) use ( $escape ) : string {
        $options = [
            302 => '302 Temporary',
            301 => '301 Permanent',
            307 => '307 Temporary, preserve method',
            308 => '308 Permanent, preserve method',
        ];
        $html = '';
        foreach ( $options as $status_code => $label ) {
            $html .= '<option value="' . (int) $status_code . '"' . ( $current_status === (int) $status_code ? ' selected' : '' ) . '>' . $escape( $label ) . '</option>';
        }

        return( $html );
    };

    $build_target_options = static function( string $current_entry_id = '' ) use ( $redirects_service, $escape ) : string {
        $html = '<option value="">Choose published content</option>';
        foreach ( $redirects_service->getTargetOptions() as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }

            $entry_id = (string) ( $option['id'] ?? '' );
            if ( $entry_id === '' ) {
                continue;
            }

            $label = (string) ( $option['label'] ?? $option['url'] ?? $entry_id );
            $url = (string) ( $option['url'] ?? '' );
            $html .= '<option value="' . $escape( $entry_id ) . '"' . ( $entry_id === $current_entry_id ? ' selected' : '' ) . '>' . $escape( $label . ( $url !== '' ? ' - ' . $url : '' ) ) . '</option>';
        }

        return( $html );
    };

    $build_mapping_form = static function( array $record, string $title, string $button_label ) use ( $save_url, $security, $escape, $build_status_options, $build_target_options ) : string {
        $id = (string) ( $record['id'] ?? '' );
        $enabled = ! array_key_exists( 'enabled', $record ) || ! empty( $record['enabled'] );
        $target_type = (string) ( $record['target_type'] ?? 'content' );
        if ( ! in_array( $target_type, [ 'content', 'path' ], true ) ) {
            $target_type = 'content';
        }

        $html = '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-3">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        if ( $id !== '' ) {
            $html .= '<input type="hidden" name="id" value="' . $escape( $id ) . '">';
        }
        $html .= '<div class="small text-uppercase text-body-secondary">' . $escape( $title ) . '</div>';
        $html .= '<div class="row g-3 align-items-end">';
        $html .= '<div class="col-12 col-xl-3"><label class="form-label small mb-1">Source path</label><input class="form-control font-monospace" name="source_path" type="text" placeholder="/old-path" value="' . $escape( (string) ( $record['source_path'] ?? '' ) ) . '" required></div>';
        $html .= '<div class="col-12 col-xl-2"><label class="form-label small mb-1">Target type</label><select class="form-select" name="target_type"><option value="content"' . ( $target_type === 'content' ? ' selected' : '' ) . '>Content</option><option value="path"' . ( $target_type === 'path' ? ' selected' : '' ) . '>Internal path</option></select></div>';
        $html .= '<div class="col-12 col-xl-4"><label class="form-label small mb-1">Content target</label><select class="form-select" name="target_entry_id">' . $build_target_options( (string) ( $record['target_entry_id'] ?? '' ) ) . '</select></div>';
        $html .= '<div class="col-12 col-xl-3"><label class="form-label small mb-1">Internal path target</label><input class="form-control font-monospace" name="target_path" type="text" placeholder="/current-path" value="' . $escape( (string) ( $record['target_path'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-sm-5 col-xl-3"><label class="form-label small mb-1">Status</label><select class="form-select" name="status_code">' . $build_status_options( (int) ( $record['status_code'] ?? 302 ) ) . '</select></div>';
        $html .= '<div class="col-12 col-sm-3 col-xl-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-redirect-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '" name="enabled" type="checkbox" value="1"' . ( $enabled ? ' checked' : '' ) . '><label class="form-check-label" for="tm-redirect-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-12 col-sm-4 col-xl-2"><button class="btn btn-primary w-100" type="submit">' . $escape( $button_label ) . '</button></div>';
        $html .= '</div>';
        $html .= '<div class="form-text">Query strings in incoming requests are ignored. Targets are internal only.</div>';
        $html .= '</form>';

        return( $html );
    };

    $render_page = static function( array $notice = [] ) use ( $plugins, $security, $redirects_service, $redirects_url, $delete_url, $escape, $build_mapping_form ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'redirects.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $body_html = '<div class="d-grid gap-4">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= $build_mapping_form( [ 'enabled' => true, 'status_code' => 302, 'target_type' => 'content' ], 'New redirect', 'Add redirect' );
        $body_html .= '</section>';

        $records = $redirects_service->listRedirects();
        $body_html .= '<section class="d-grid gap-3">';
        $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">Existing redirects</div><div class="text-body-secondary small">Normal content routes win first. Redirects are checked only for not-found requests.</div></div>';

        if ( empty( $records ) ) {
            $body_html .= '<div class="p-3 rounded-3 bg-body-secondary text-body-secondary">No redirects have been created yet.</div>';
        }

        foreach ( $records as $record ) {
            $body_html .= '<article class="p-3 bg-body-secondary rounded-3">';
            $body_html .= '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">';
            $body_html .= '<div><div class="fw-semibold font-monospace">' . $escape( (string) ( $record['source_path'] ?? '' ) ) . '</div>';
            $body_html .= '<div class="small text-body-secondary">to ' . $escape( (string) ( $record['target_label'] ?? $record['target_url'] ?? '' ) ) . '</div></div>';
            $body_html .= '<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge text-bg-' . ( ! empty( $record['enabled'] ) ? 'success' : 'secondary' ) . '">' . ( ! empty( $record['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span><span class="badge text-bg-secondary">' . (int) ( $record['status_code'] ?? 302 ) . '</span></div>';
            $body_html .= '</div>';
            if ( empty( $record['has_target'] ) ) {
                $body_html .= '<div class="alert alert-warning py-2 small" role="alert">The selected content target is not currently published or no longer exists. This redirect will not run until the target is available again.</div>';
            }
            $body_html .= $build_mapping_form( $record, 'Edit redirect', 'Save redirect' );
            $body_html .= '<form method="post" action="' . $escape( $delete_url ) . '" class="mt-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
            $body_html .= '<input type="hidden" name="id" value="' . $escape( (string) ( $record['id'] ?? '' ) ) . '">';
            $body_html .= '<button class="btn btn-outline-danger btn-sm" type="submit">Delete redirect</button>';
            $body_html .= '</form>';
            $body_html .= '</article>';
        }
        $body_html .= '</section>';
        $body_html .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Redirects',
                'current_section' => 'redirects',
                'current_location' => 'redirects',
                'help_contexts' => [ 'plugin-redirects' ],
                'plugin_page_kicker' => 'Redirects',
                'plugin_page_title' => 'Path redirects',
                'plugin_page_summary' => 'Redirects a specific /path to a page or a post.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $redirects_url,
        static function() use ( $require_admin, $render_page ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $render_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $save_url,
        static function() use ( $plugins, $security, $redirects_service, $redirects_url, $require_admin ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'redirects.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirects_url );
                return;
            }

            try {
                $redirects_service->saveRedirect( $data );
                $security->setFlash( 'redirects.notice', [ 'type' => 'success', 'message' => 'Redirect saved.' ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'source_path' => 'Choose a non-reserved local source path such as /old-post.',
                    'source_conflict' => 'Another redirect already uses that source path.',
                    'target_type' => 'Choose a valid target type.',
                    'target_entry' => 'Choose a published content target.',
                    'target_path' => 'Choose an internal target path that is different from the source path.',
                ];
                $security->setFlash( 'redirects.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'The redirect could not be saved.' ] );
            } catch ( \Throwable $e ) {
                error_log( 'redirects plugin: save failed (' . $e->getMessage() . ')' );
                $security->setFlash( 'redirects.notice', [ 'type' => 'danger', 'message' => 'The redirect could not be saved.' ] );
            }

            $plugins->redirect( $redirects_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $delete_url,
        static function() use ( $plugins, $security, $redirects_service, $redirects_url, $require_admin ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'redirects.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $redirects_url );
                return;
            }

            $deleted = $redirects_service->deleteRedirect( (string) ( $data['id'] ?? '' ) );
            $security->setFlash(
                'redirects.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Redirect deleted.' ]
                    : [ 'type' => 'warning', 'message' => 'That redirect could not be found.' ]
            );
            $plugins->redirect( $redirects_url );
        }
    );
};
