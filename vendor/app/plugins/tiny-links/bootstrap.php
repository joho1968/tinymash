<?php

require_once __DIR__ . '/TinyMashTinyLinksService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'tiny-links' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_target_picker = $plugins->getService( 'content.target_picker' );
    $date_formatter = $plugins->getService( 'date.formatter' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $content_target_picker instanceof TinyMashContentTargetPickerService || ! $date_formatter instanceof TinyMashDateFormatter ) {
        throw new \RuntimeException( 'Required services for the Tiny links plugin are not available.' );
    }

    $tiny_links_service = new TinyMashTinyLinksService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'tiny-links' . DIRECTORY_SEPARATOR . 'links.json',
        $content_target_picker
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $tiny_links_url = $admin_url . '/tiny-links';
    $save_url = $tiny_links_url . '/save';
    $delete_url = $tiny_links_url . '/delete';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'tiny-links',
            'label' => 'Tiny links',
            'url' => $tiny_links_url,
            'icon' => 'bi-link-45deg',
            'order' => 75,
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-tiny-links',
        [
            'title' => 'Tiny links',
            'summary' => 'Create short links for published content.',
            'group' => 'Plugins',
            'order' => 125,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Tiny links creates short URLs under `/t/<token>` for published tinymash content.',
                ],
                [
                    'title' => 'Targets',
                    'markdown' => 'Targets are internal only. Use `Content` for a published page or post so the tiny link follows that content if its URL changes. Use `Internal path` only when you need to point at another local public path.',
                ],
                [
                    'title' => 'Usage',
                    'markdown' => 'Each tiny link stores a hit count and the most recent time it was used.',
                ],
                [
                    'title' => 'Expiration',
                    'markdown' => 'A tiny link can be disabled manually or given an expiration date. Expired links no longer redirect.',
                ],
            ],
        ]
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/t/@token',
        static function( string $token ) use ( $tiny_links_service ) : void {
            $record = $tiny_links_service->resolveToken( $token );
            $target_url = (string) ( $record['target_url'] ?? '' );
            if ( $target_url === '' || ! str_starts_with( $target_url, '/' ) ) {
                \Flight::notFound();
                return;
            }

            \Flight::response()->status( 302 );
            header( 'Location: ' . $target_url, true, 302 );
        }
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
                    'current_section' => 'tiny-links',
                    'current_location' => 'tiny-links',
                    'plugin_page_kicker' => 'Tiny links',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'Tiny link management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage tiny links.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . htmlspecialchars( $admin_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }

        return( true );
    };

    $build_target_options = static function( string $current_entry_id = '' ) use ( $tiny_links_service, $escape ) : string {
        $html = '<option value="">Choose published content</option>';
        foreach ( $tiny_links_service->getTargetOptions() as $option ) {
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

    $build_link_form = static function( array $record, string $title, string $button_label ) use ( $save_url, $security, $escape, $build_target_options ) : string {
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
        $html .= '<div class="col-12 col-xl-2"><label class="form-label small mb-1">Token</label><div class="input-group"><span class="input-group-text font-monospace">/t/</span><input class="form-control font-monospace" name="token" type="text" placeholder="auto" value="' . $escape( (string) ( $record['token'] ?? '' ) ) . '"></div></div>';
        $html .= '<div class="col-12 col-xl-2"><label class="form-label small mb-1">Target type</label><select class="form-select" name="target_type"><option value="content"' . ( $target_type === 'content' ? ' selected' : '' ) . '>Content</option><option value="path"' . ( $target_type === 'path' ? ' selected' : '' ) . '>Internal path</option></select></div>';
        $html .= '<div class="col-12 col-xl-4"><label class="form-label small mb-1">Content target</label><select class="form-select" name="target_entry_id">' . $build_target_options( (string) ( $record['target_entry_id'] ?? '' ) ) . '</select></div>';
        $html .= '<div class="col-12 col-xl-4"><label class="form-label small mb-1">Internal path target</label><input class="form-control font-monospace" name="target_path" type="text" placeholder="/current-path" value="' . $escape( (string) ( $record['target_path'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-sm-4 col-xl-2"><label class="form-label small mb-1">Expires</label><input class="form-control" name="expires_on" type="date" value="' . $escape( (string) ( $record['expires_on'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-sm-5 col-xl-4"><label class="form-label small mb-1">Note</label><input class="form-control" name="note" type="text" maxlength="160" value="' . $escape( (string) ( $record['note'] ?? '' ) ) . '"></div>';
        $html .= '<div class="col-12 col-sm-3 col-xl-2"><div class="form-check mb-2"><input class="form-check-input" id="tm-tiny-link-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '" name="enabled" type="checkbox" value="1"' . ( $enabled ? ' checked' : '' ) . '><label class="form-check-label" for="tm-tiny-link-enabled-' . $escape( $id !== '' ? $id : 'new' ) . '">Enabled</label></div></div>';
        $html .= '<div class="col-12 col-xl-2"><button class="btn btn-primary w-100" type="submit">' . $escape( $button_label ) . '</button></div>';
        $html .= '</div>';
        $html .= '<div class="form-text">Leave token blank to generate one. Content targets follow the selected item if its URL changes; internal path targets are fixed local paths. External targets are not supported.</div>';
        $html .= '</form>';

        return( $html );
    };

    $render_page = static function( array $notice = [] ) use ( $plugins, $security, $tiny_links_service, $tiny_links_url, $delete_url, $escape, $build_link_form, $date_formatter ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'tiny-links.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $body_html = '<div class="d-grid gap-4">';
        $body_html .= '<section class="p-3 bg-body-secondary rounded-3">';
        $body_html .= $build_link_form( [ 'enabled' => true, 'target_type' => 'content' ], 'New tiny link', 'Create link' );
        $body_html .= '</section>';

        $records = $tiny_links_service->listLinks();
        $body_html .= '<section class="d-grid gap-3">';
        $body_html .= '<div><div class="small text-uppercase text-body-secondary mb-1">Existing tiny links</div><div class="text-body-secondary small">Tiny links redirect from a reserved `/t/` prefix to published content or fixed internal paths.</div></div>';
        if ( empty( $records ) ) {
            $body_html .= '<div class="p-3 rounded-3 bg-body-secondary text-body-secondary">No tiny links have been created yet.</div>';
        }

        foreach ( $records as $record ) {
            $hit_count = max( 0, (int) ( $record['hit_count'] ?? 0 ) );
            $last_hit_display = trim( (string) ( $record['last_hit_at_utc'] ?? '' ) ) !== ''
                ? $date_formatter->formatUtcDateTime( (string) $record['last_hit_at_utc'] )
                : '';
            $body_html .= '<article class="p-3 bg-body-secondary rounded-3">';
            $body_html .= '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">';
            $body_html .= '<div><div class="fw-semibold font-monospace">' . $escape( (string) ( $record['short_path'] ?? '' ) ) . '</div>';
            $body_html .= '<div class="small text-body-secondary">to ' . $escape( (string) ( $record['target_label'] ?? $record['target_url'] ?? '' ) ) . '</div></div>';
            $body_html .= '<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge text-bg-' . ( ! empty( $record['enabled'] ) ? 'success' : 'secondary' ) . '">' . ( ! empty( $record['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</span>' . ( ! empty( $record['expired'] ) ? '<span class="badge text-bg-warning">Expired</span>' : '' ) . '</div>';
            $body_html .= '</div>';
            $body_html .= '<div class="small text-body-secondary mb-3">' . $hit_count . ' ' . ( $hit_count === 1 ? 'use' : 'uses' ) . ( $last_hit_display !== '' ? ' · last used ' . $escape( $last_hit_display ) : '' ) . '</div>';
            if ( empty( $record['has_target'] ) ) {
                $body_html .= '<div class="alert alert-warning py-2 small" role="alert">The selected content target is not currently published or no longer exists. This tiny link will not run until the target is available again.</div>';
            }
            $body_html .= $build_link_form( $record, 'Edit tiny link', 'Save link' );
            $body_html .= '<form method="post" action="' . $escape( $delete_url ) . '" class="mt-3">';
            $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
            $body_html .= '<input type="hidden" name="id" value="' . $escape( (string) ( $record['id'] ?? '' ) ) . '">';
            $body_html .= '<button class="btn btn-outline-danger btn-sm" type="submit">Delete tiny link</button>';
            $body_html .= '</form>';
            $body_html .= '</article>';
        }
        $body_html .= '</section>';
        $body_html .= '</div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Tiny links',
                'current_section' => 'tiny-links',
                'current_location' => 'tiny-links',
                'help_contexts' => [ 'plugin-tiny-links' ],
                'plugin_page_kicker' => 'Tiny links',
                'plugin_page_title' => 'Short internal links',
                'plugin_page_summary' => 'Create short links for published content.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $tiny_links_url,
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
        static function() use ( $plugins, $security, $tiny_links_service, $tiny_links_url, $require_admin ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'tiny-links.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $tiny_links_url );
                return;
            }

            try {
                $tiny_links_service->saveLink( $data );
                $security->setFlash( 'tiny-links.notice', [ 'type' => 'success', 'message' => 'Tiny link saved.' ] );
            } catch ( \InvalidArgumentException $e ) {
                $messages = [
                    'token' => 'Use a token with at least three letters, numbers, dashes, or underscores, or leave it blank to generate one.',
                    'token_conflict' => 'Another tiny link already uses that token.',
                    'target_type' => 'Choose a valid target type.',
                    'target_entry' => 'Choose a published content target.',
                    'target_path' => 'Choose a public internal target path.',
                    'expires_on' => 'Choose a valid expiration date or leave it blank.',
                ];
                $security->setFlash( 'tiny-links.notice', [ 'type' => 'danger', 'message' => $messages[$e->getMessage()] ?? 'The tiny link could not be saved.' ] );
            } catch ( \Throwable $e ) {
                error_log( 'tiny-links plugin: save failed (' . $e->getMessage() . ')' );
                $security->setFlash( 'tiny-links.notice', [ 'type' => 'danger', 'message' => 'The tiny link could not be saved.' ] );
            }

            $plugins->redirect( $tiny_links_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $delete_url,
        static function() use ( $plugins, $security, $tiny_links_service, $tiny_links_url, $require_admin ) : void {
            if ( ! $require_admin() ) {
                return;
            }

            $data = $plugins->getRequestData();
            if ( ! $security->validateCsrfToken( isset( $data['tinymash_csrf'] ) ? (string) $data['tinymash_csrf'] : '' ) ) {
                $security->setFlash( 'tiny-links.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $tiny_links_url );
                return;
            }

            $deleted = $tiny_links_service->deleteLink( (string) ( $data['id'] ?? '' ) );
            $security->setFlash(
                'tiny-links.notice',
                $deleted
                    ? [ 'type' => 'success', 'message' => 'Tiny link deleted.' ]
                    : [ 'type' => 'warning', 'message' => 'That tiny link could not be found.' ]
            );
            $plugins->redirect( $tiny_links_url );
        }
    );
};
