<?php

require_once __DIR__ . '/TinyMashRssReaderService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'rss-reader' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    if ( ! $config instanceof TinyMashConfig ) {
        throw new \RuntimeException( 'Required services for the RSS Reader plugin are not available.' );
    }

    $service = new TinyMashRssReaderService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'rss-reader'
    );
    \Flight::set( 'plugin.rss_reader.service', $service );

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function( array $context = [] ) use ( $service ) : string {
            if ( (string) ( $context['trigger'] ?? '' ) === 'web_fallback' ) {
                return( 'Skipped RSS refresh during web fallback; use CLI or admin refresh.' );
            }
            return( (string) $service->refreshSources()['message'] );
        },
        'refresh',
        'Refresh RSS Reader sources'
    );

    if ( $plugins->isCliRuntime() ) {
        $plugins->registerCliCommand(
            $plugin_key,
            [
                'command' => 'rss-reader',
                'usage' => 'tinymash rss-reader refresh [source-key]',
                'summary' => 'Refresh configured RSS Reader sources.',
                'order' => 247,
                'handler' => static function( array $arguments ) use ( $service ) : void {
                    if ( strtolower( trim( (string) ( $arguments[0] ?? '' ) ) ) !== 'refresh' ) {
                        throw new \RuntimeException( 'usage: tinymash rss-reader refresh [source-key]' );
                    }
                    $source_key = trim( (string) ( $arguments[1] ?? '' ) );
                    $result = $service->refreshSources( $source_key !== '' ? [ $source_key ] : null );
                    fwrite( STDOUT, 'refreshed: ' . (int) ( $result['refreshed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'failed: ' . (int) ( $result['failed'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'disabled: ' . (int) ( $result['disabled'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'cached_sources: ' . (int) ( $result['source_count'] ?? 0 ) . PHP_EOL );
                    fwrite( STDOUT, 'message: ' . (string) ( $result['message'] ?? '' ) . PHP_EOL );
                },
            ]
        );
        return;
    }

    $date_formatter = $plugins->getService( 'date.formatter' );
    if ( ! $security instanceof TinyMashSecurity || ! $date_formatter instanceof TinyMashDateFormatter ) {
        throw new \RuntimeException( 'Required services for the RSS Reader plugin are not available.' );
    }

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/rss-reader/rss-reader.css' );
    $plugins->registerShortcode(
        $plugin_key,
        'rss',
        static function( array $shortcode ) use ( $service ) : array {
            $context = is_array( $shortcode['context'] ?? null ) ? $shortcode['context'] : [];
            return(
                [
                    'html' => $service->renderShortcodeHtml( is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [], $context ),
                    'dynamic' => true,
                ]
            );
        },
        [
            'label' => 'RSS Reader',
            'description' => 'Displays cached recent items from one configured RSS or Atom source.',
            'example' => '[rss source="example" limit="5"]',
            'dynamic' => true,
        ]
    );

    $settings = $service->getSettings();
    if ( ! empty( $settings['slots']['sidebar']['enabled'] ) ) {
        $plugins->registerPublicSlotRenderer(
            $plugin_key,
            'sidebar',
            static function() use ( $service ) : string {
                return( $service->renderSlotHtml( 'sidebar' ) );
            },
            true
        );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $plugin_url = $admin_url . '/rss-reader';
    $save_url = $plugin_url . '/save';
    $refresh_url = $plugin_url . '/refresh';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'rss-reader',
            'label' => 'RSS Reader',
            'url' => $plugin_url,
            'icon' => 'bi-rss',
            'order' => 80,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $plugin_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-rss-reader',
        [
            'title' => 'RSS Reader',
            'summary' => 'Display cached RSS or Atom items from approved sources.',
            'group' => 'Plugins',
            'order' => 145,
            'roles' => [ 'admin' ],
            'sections' => [
                [
                    'title' => 'Sources',
                    'markdown' => 'RSS Reader sources are managed by superadmins because each source URL is fetched by the server. Public pages render cached items only and never fetch remote feeds during visitor requests.',
                ],
                [
                    'title' => 'Shortcode',
                    'markdown' => 'Use `[rss source="source-key" limit="5"]` to display one approved source. Useful attributes are `source`, `limit`, `layout="compact|list|headlines"`, `excerpt="true|false"`, `date="true|false"`, and `title="Custom title"`.',
                ],
                [
                    'title' => 'Author embeds',
                    'markdown' => 'A source must have **Authors may embed** enabled before author-space content can render it. Root/site content and the configured sidebar module can use enabled sources directly.',
                ],
                [
                    'title' => 'Scope',
                    'markdown' => 'RSS Reader displays cached feed items and links to their sources. It does not import items as tinymash content or fetch media attachments. Multiple sources require separate shortcodes.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };
    $render_select = static function( string $name, string $current, array $options, string $id = '' ) use ( $escape ) : string {
        $html = '<select class="form-select" name="' . $escape( $name ) . '"' . ( $id !== '' ? ' id="' . $escape( $id ) . '"' : '' ) . '>';
        foreach ( $options as $value => $label ) {
            $value = (string) $value;
            $html .= '<option value="' . $escape( $value ) . '"' . ( $value === $current ? ' selected' : '' ) . '>' . $escape( (string) $label ) . '</option>';
        }
        $html .= '</select>';
        return( $html );
    };
    $invalidate_public_cache = static function() : void {
        $public_page_cache = \Flight::app()->has( 'public.page_cache' ) ? \Flight::app()->get( 'public.page_cache' ) : null;
        if ( $public_page_cache instanceof TinyMashPublicPageCache ) {
            $public_page_cache->invalidateAll();
        }
    };
    $require_admin = static function() use ( $plugins, $security, $login_url, $admin_url, $escape ) : bool {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return( false );
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'rss-reader',
                    'current_location' => 'rss-reader',
                    'plugin_page_kicker' => 'RSS Reader',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_summary' => 'RSS source management is limited to admins.',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage RSS sources.' ],
                    'plugin_page_body_html' => '<p><a class="btn btn-outline-secondary" href="' . $escape( $admin_url ) . '">Return to dashboard</a></p>',
                ]
            );
            return( false );
        }
        return( true );
    };

    $build_admin_url = static function( array $query = [] ) use ( $plugin_url ) : string {
        return( $plugin_url . ( ! empty( $query ) ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' ) );
    };

    $build_page = static function( array $notice = [], ?array $form_settings = null, array $selection = [] ) use ( $plugins, $security, $date_formatter, $service, $save_url, $refresh_url, $escape, $render_select, $build_admin_url ) : void {
        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'rss-reader.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }
        $settings = $form_settings ?? $service->getSettings();
        $settings = array_replace_recursive( $service->getDefaultSettings(), $settings );
        $rows = $service->getAdminRows();
        $row_map = [];
        foreach ( $rows as $row ) {
            $row_source_key = (string) ( $row['source']['key'] ?? '' );
            if ( $row_source_key !== '' ) {
                $row_map[$row_source_key] = $row;
            }
        }
        $sources = is_array( $settings['sources'] ?? null ) ? array_values( $settings['sources'] ) : [];
        $source_map = [];
        $source_options = [ '' => 'Select source' ];
        foreach ( $sources as $source ) {
            $source_key = (string) ( $source['key'] ?? '' );
            if ( $source_key !== '' ) {
                $source_map[$source_key] = $source;
                $source_options[$source_key] = (string) ( $source['label'] ?? $source_key ) . ' (' . $source_key . ')';
            }
        }
        $layout_options = [ 'compact' => 'Compact', 'list' => 'List', 'headlines' => 'Headlines' ];
        $query = $plugins->getRequestQueryData();
        $requested_view = strtolower( trim( (string) ( $selection['view'] ?? $query['view'] ?? '' ) ) );
        $requested_source = trim( (string) ( $selection['source'] ?? $query['source'] ?? '' ) );
        if ( ! in_array( $requested_view, [ 'defaults', 'sidebar' ], true ) ) {
            $requested_view = '';
        }
        if ( $requested_view === '' && $requested_source === '' ) {
            $requested_source = ! empty( $sources ) ? (string) ( $sources[0]['key'] ?? '' ) : 'new';
        }
        if ( $requested_view === '' && $requested_source !== 'new' && ! isset( $source_map[$requested_source] ) ) {
            $requested_source = ! empty( $sources ) ? (string) ( $sources[0]['key'] ?? '' ) : 'new';
        }

        $current_source = $requested_view === '' && isset( $source_map[$requested_source] ) ? $source_map[$requested_source] : [];
        $current_row = is_array( $row_map[$requested_source] ?? null ) ? $row_map[$requested_source] : [];
        $current_label = $requested_view === 'defaults' ? 'Display defaults' : ( $requested_view === 'sidebar' ? 'Public sidebar' : ( $requested_source === 'new' ? 'New feed source' : (string) ( $current_source['label'] ?? $requested_source ) ) );
        $csrf_token = $security->getCsrfToken();
        $status_label = static function( string $status ) : string {
            return( match ( $status ) {
                'ok' => 'Ready',
                'error' => 'Refresh failed',
                'disabled' => 'Disabled',
                default => 'Not refreshed',
            } );
        };

        $build_navigation = static function( string $marker ) use ( $sources, $row_map, $requested_view, $requested_source, $current_label, $build_admin_url, $refresh_url, $csrf_token, $status_label, $escape ) : string {
            $html = '<div ' . $marker . '><div class="d-flex align-items-center justify-content-between gap-2 mb-2"><div class="small text-uppercase text-body-secondary">Feed sources</div><a class="btn btn-primary btn-sm" href="' . $escape( $build_admin_url( [ 'source' => 'new' ] ) ) . '"><span class="bi bi-plus-lg me-1" aria-hidden="true"></span>New</a></div>';
            if ( empty( $sources ) ) {
                $html .= '<div class="small text-body-secondary mb-3">No sources yet.</div>';
            } else {
                $html .= '<nav class="d-grid gap-2 mb-3" aria-label="Feed sources">';
                foreach ( $sources as $source ) {
                    $key = (string) ( $source['key'] ?? '' );
                    $row = is_array( $row_map[$key] ?? null ) ? $row_map[$key] : [];
                    $active = $requested_view === '' && $requested_source === $key;
                    $status = ! empty( $source['enabled'] ) ? $status_label( (string) ( $row['status'] ?? 'unrefreshed' ) ) : 'Disabled';
                    $html .= '<a class="text-decoration-none ' . ( $active ? 'border-start border-primary border-3 ps-2 fw-semibold' : 'ps-3' ) . '" href="' . $escape( $build_admin_url( [ 'source' => $key ] ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '><span class="d-flex justify-content-between align-items-start gap-2"><span class="text-break">' . $escape( (string) ( $source['label'] ?? $key ) ) . '</span><span class="small text-body-secondary">' . (int) ( $row['item_count'] ?? 0 ) . '</span></span><span class="d-block small text-body-secondary">' . $escape( $status ) . '</span></a>';
                }
                $html .= '</nav>';
            }
            $html .= '<nav class="d-grid gap-2 border-top pt-3" aria-label="RSS Reader settings">';
            foreach ( [ 'defaults' => 'Display defaults', 'sidebar' => 'Public sidebar' ] as $view => $label ) {
                $active = $requested_view === $view;
                $html .= '<a class="text-decoration-none ' . ( $active ? 'border-start border-primary border-3 ps-2 fw-semibold' : 'ps-3' ) . '" href="' . $escape( $build_admin_url( [ 'view' => $view ] ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . $escape( $label ) . '</a>';
            }
            $html .= '</nav><form class="mt-3" method="post" action="' . $escape( $refresh_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit"><span class="bi bi-arrow-clockwise me-1" aria-hidden="true"></span>Refresh all</button></form>';
            return( $html . '<span class="visually-hidden">Current: ' . $escape( $current_label ) . '</span></div>' );
        };

        $desktop_navigation = $build_navigation( 'data-tm-rss-reader-navigation-desktop' );
        $mobile_navigation = $build_navigation( 'data-tm-rss-reader-navigation-mobile' );
        $body_html = '<div class="tm-rss-reader-admin row g-4 align-items-start" data-tm-rss-reader-workspace>';
        $body_html .= '<div class="col-12 d-lg-none"><details class="border-bottom pb-3" data-tm-rss-reader-mobile-navigation><summary class="py-2 fw-semibold">Browse RSS Reader <span class="small fw-normal text-body-secondary">' . $escape( $current_label ) . '</span></summary><div class="pt-3">' . $mobile_navigation . '</div></details></div>';
        $body_html .= '<aside class="col-lg-3 d-none d-lg-block" aria-label="RSS Reader navigation"><div class="pe-lg-3 border-end position-sticky" style="top:5rem;">' . $desktop_navigation . '</div></aside>';
        $body_html .= '<div class="col-12 col-lg-9"><div class="d-grid gap-3 rounded-3 p-3 bg-body-secondary" data-tm-rss-reader-current-workspace data-tm-admin-surface-group>';

        if ( $requested_view === 'defaults' ) {
            $body_html .= '<header><div class="small text-uppercase text-body-secondary mb-1">RSS Reader</div><h2 class="h5 mb-1">Display defaults</h2><div class="small text-body-secondary">Used when a shortcode does not override its display options.</div></header>';
            $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="section" value="defaults"><div class="row g-3 align-items-end">';
            $body_html .= '<div class="col-6 col-xl-3"><label class="form-label small mb-1" for="tm-rss-default-limit">Items</label><input class="form-control" id="tm-rss-default-limit" name="default_item_limit" type="number" min="1" max="50" value="' . (int) ( $settings['defaults']['item_limit'] ?? 5 ) . '"></div>';
            $body_html .= '<div class="col-6 col-xl-3"><label class="form-label small mb-1" for="tm-rss-stale-hours">Stale after hours</label><input class="form-control" id="tm-rss-stale-hours" name="stale_hours" type="number" min="1" max="720" value="' . (int) ( $settings['defaults']['stale_hours'] ?? 24 ) . '"></div>';
            $body_html .= '<div class="col-12 col-xl-4"><label class="form-label small mb-1" for="tm-rss-default-layout">Layout</label>' . $render_select( 'default_layout', (string) ( $settings['defaults']['layout'] ?? 'compact' ), $layout_options, 'tm-rss-default-layout' ) . '</div>';
            $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-3"><div class="form-check"><input class="form-check-input" id="tm-rss-show-excerpt" name="default_show_excerpt" type="checkbox" value="1"' . ( ! empty( $settings['defaults']['show_excerpt'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-show-excerpt">Show excerpts</label></div><div class="form-check"><input class="form-check-input" id="tm-rss-show-date" name="default_show_date" type="checkbox" value="1"' . ( ! empty( $settings['defaults']['show_date'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-show-date">Show dates</label></div></div></div></div>';
            $body_html .= '<div><button class="btn btn-primary" type="submit">Save defaults</button></div></form>';
        } elseif ( $requested_view === 'sidebar' ) {
            $slot = is_array( $settings['slots']['sidebar'] ?? null ) ? $settings['slots']['sidebar'] : [];
            $body_html .= '<header><div class="small text-uppercase text-body-secondary mb-1">RSS Reader</div><h2 class="h5 mb-1">Public sidebar</h2><div class="small text-body-secondary">Show one feed source in themes that provide the sidebar slot.</div></header>';
            $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="section" value="sidebar"><div class="form-check"><input class="form-check-input" id="tm-rss-sidebar-enabled" name="slots[sidebar][enabled]" type="checkbox" value="1"' . ( ! empty( $slot['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-enabled">Show RSS Reader in the public sidebar</label></div><div class="row g-3 align-items-end" data-tm-rss-sidebar-grid>';
            $body_html .= '<div class="col-12 col-xl-7"><label class="form-label small mb-1" for="tm-rss-sidebar-source">Source</label>' . $render_select( 'slots[sidebar][source]', (string) ( $slot['source'] ?? '' ), $source_options, 'tm-rss-sidebar-source' ) . '</div>';
            $body_html .= '<div class="col-12 col-xl-5"><label class="form-label small mb-1" for="tm-rss-sidebar-title">Title override</label><input class="form-control" id="tm-rss-sidebar-title" name="slots[sidebar][title]" type="text" maxlength="120" value="' . $escape( (string) ( $slot['title'] ?? '' ) ) . '"></div>';
            $body_html .= '<div class="col-6 col-xl-3"><label class="form-label small mb-1" for="tm-rss-sidebar-limit">Items</label><input class="form-control" id="tm-rss-sidebar-limit" name="slots[sidebar][item_limit]" type="number" min="1" max="50" value="' . (int) ( $slot['item_limit'] ?? 5 ) . '"></div>';
            $body_html .= '<div class="col-6 col-xl-4"><label class="form-label small mb-1" for="tm-rss-sidebar-layout">Layout</label>' . $render_select( 'slots[sidebar][layout]', (string) ( $slot['layout'] ?? 'compact' ), $layout_options, 'tm-rss-sidebar-layout' ) . '</div>';
            $body_html .= '<fieldset class="col-12 col-xl-5" data-tm-rss-sidebar-details><legend class="form-label small mb-1">Item details</legend><div class="d-flex flex-wrap gap-3 py-2"><div class="form-check"><input class="form-check-input" id="tm-rss-sidebar-excerpt" name="slots[sidebar][show_excerpt]" type="checkbox" value="1"' . ( ! empty( $slot['show_excerpt'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-excerpt">Show excerpts</label></div><div class="form-check"><input class="form-check-input" id="tm-rss-sidebar-date" name="slots[sidebar][show_date]" type="checkbox" value="1"' . ( ! empty( $slot['show_date'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-date">Show dates</label></div></div></fieldset></div>';
            $body_html .= '<div><button class="btn btn-primary" type="submit">Save public sidebar</button></div></form>';
        } else {
            $is_new = $requested_source === 'new';
            if ( $is_new ) {
                $current_source = [ 'key' => '', 'label' => '', 'url' => '', 'enabled' => true, 'authors_may_embed' => false, 'item_limit' => (int) ( $settings['defaults']['item_limit'] ?? 5 ) ];
            }
            $source_key = (string) ( $current_source['key'] ?? '' );
            $body_html .= '<header class="d-flex flex-wrap align-items-start justify-content-between gap-2"><div><div class="small text-uppercase text-body-secondary mb-1">' . ( $is_new ? 'New source' : 'Feed source' ) . '</div><h2 class="h5 mb-1">' . $escape( $is_new ? 'Add feed source' : (string) ( $current_source['label'] ?? $source_key ) ) . '</h2>' . ( $source_key !== '' ? '<div class="small text-body-secondary font-monospace">' . $escape( $source_key ) . '</div>' : '' ) . '</div>';
            if ( ! $is_new ) {
                $body_html .= '<form method="post" action="' . $escape( $refresh_url ) . '"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="source_key" value="' . $escape( $source_key ) . '"><button class="btn btn-outline-secondary btn-sm" type="submit"><span class="bi bi-arrow-clockwise me-1" aria-hidden="true"></span>Refresh source</button></form>';
            }
            $body_html .= '</header>';
            $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="section" value="source"><input type="hidden" name="original_source_key" value="' . $escape( $source_key ) . '"><div class="row g-3 align-items-end">';
            $body_html .= '<div class="col-12 col-xl-6"><label class="form-label small mb-1" for="tm-rss-source-label">Label</label><input class="form-control" id="tm-rss-source-label" name="source[label]" type="text" maxlength="120" value="' . $escape( (string) ( $current_source['label'] ?? '' ) ) . '" required></div>';
            $body_html .= '<div class="col-12 col-xl-6"><label class="form-label small mb-1" for="tm-rss-source-key">Key</label><input class="form-control font-monospace" id="tm-rss-source-key" name="source[key]" type="text" maxlength="80" value="' . $escape( $source_key ) . '" placeholder="Generated from the label"></div>';
            $body_html .= '<div class="col-12 col-xl-9"><label class="form-label small mb-1" for="tm-rss-source-url">Feed URL</label><input class="form-control font-monospace" id="tm-rss-source-url" name="source[url]" type="url" value="' . $escape( (string) ( $current_source['url'] ?? '' ) ) . '" placeholder="https://example.com/feed.xml" required></div>';
            $body_html .= '<div class="col-6 col-xl-3"><label class="form-label small mb-1" for="tm-rss-source-limit">Items fetched</label><input class="form-control" id="tm-rss-source-limit" name="source[item_limit]" type="number" min="1" max="50" value="' . (int) ( $current_source['item_limit'] ?? 5 ) . '"></div>';
            $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-3"><div class="form-check"><input class="form-check-input" id="tm-rss-source-enabled" name="source[enabled]" type="checkbox" value="1"' . ( ! empty( $current_source['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-source-enabled">Enabled</label></div><div class="form-check"><input class="form-check-input" id="tm-rss-source-authors" name="source[authors_may_embed]" type="checkbox" value="1"' . ( ! empty( $current_source['authors_may_embed'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-source-authors">Authors may embed</label></div></div></div></div>';
            $body_html .= '<div><button class="btn btn-primary" type="submit">' . ( $is_new ? 'Add source' : 'Save source' ) . '</button></div></form>';

            if ( ! $is_new ) {
                $cache_status = ! empty( $current_source['enabled'] ) ? $status_label( (string) ( $current_row['status'] ?? 'unrefreshed' ) ) : 'Disabled';
                $last_success = (string) ( $current_row['last_success_at_utc'] ?? '' );
                $checked_at = (string) ( $current_row['checked_at_utc'] ?? '' );
                $body_html .= '<section class="border-top pt-3"><div class="small text-uppercase text-body-secondary mb-2">Cached source</div><dl class="row small mb-0"><dt class="col-sm-4 text-body-secondary fw-normal">Status</dt><dd class="col-sm-8">' . $escape( $cache_status ) . '</dd><dt class="col-sm-4 text-body-secondary fw-normal">Items</dt><dd class="col-sm-8">' . (int) ( $current_row['item_count'] ?? 0 ) . '</dd><dt class="col-sm-4 text-body-secondary fw-normal">Last successful refresh</dt><dd class="col-sm-8">' . $escape( $last_success !== '' ? $date_formatter->formatUtcDateTime( $last_success ) : 'Never' ) . '</dd><dt class="col-sm-4 text-body-secondary fw-normal">Last checked</dt><dd class="col-sm-8">' . $escape( $checked_at !== '' ? $date_formatter->formatUtcDateTime( $checked_at ) : 'Never' ) . '</dd>';
                if ( (string) ( $current_row['message'] ?? '' ) !== '' ) {
                    $body_html .= '<dt class="col-sm-4 text-body-secondary fw-normal">Message</dt><dd class="col-sm-8 text-break">' . $escape( (string) $current_row['message'] ) . '</dd>';
                }
                $body_html .= '</dl></section>';
                $body_html .= '<details class="border-top py-2"><summary class="py-1 fw-semibold">Shortcode examples</summary><div class="pt-3 d-grid gap-2"><code class="text-break">[rss source=&quot;' . $escape( $source_key ) . '&quot;]</code><code class="text-break">[rss source=&quot;' . $escape( $source_key ) . '&quot; limit=&quot;3&quot; layout=&quot;headlines&quot; excerpt=&quot;false&quot;]</code></div></details>';
                $body_html .= '<details class="border-top py-2"><summary class="py-1 fw-semibold text-danger">Delete source</summary><form method="post" action="' . $escape( $save_url ) . '" class="pt-3 d-grid gap-3"><input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '"><input type="hidden" name="section" value="source"><input type="hidden" name="action" value="delete"><input type="hidden" name="original_source_key" value="' . $escape( $source_key ) . '"><div class="form-check"><input class="form-check-input" id="tm-rss-delete-confirm" name="delete_confirmation" type="checkbox" value="' . $escape( $source_key ) . '" required><label class="form-check-label" for="tm-rss-delete-confirm">Delete ' . $escape( (string) ( $current_source['label'] ?? $source_key ) ) . '</label></div><div><button class="btn btn-outline-danger" type="submit">Delete source</button></div></form></details>';
            }
        }

        $body_html .= '</div></div></div>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'RSS Reader',
                'current_section' => 'rss-reader',
                'current_location' => 'rss-reader',
                'plugin_page_kicker' => 'RSS Reader',
                'plugin_page_title' => 'Feed sources',
                'plugin_page_summary' => 'Display cached items from selected RSS or Atom sources.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
                'help_contexts' => [ 'plugin-rss-reader' ],
                'admin_theme_css_urls' => [
                    '/ext/bs/css/bootstrap.min.css',
                    '/ext/bsi/bootstrap-icons.min.css',
                    '/css/tinymash.css',
                    '/plugins/rss-reader/rss-reader.css',
                ],
            ]
        );
    };

    $validate_post = static function( array $data ) use ( $security ) : bool {
        return( $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $plugin_url,
        static function() use ( $require_admin, $build_page ) : void {
            if ( ! $require_admin() ) {
                return;
            }
            $build_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $save_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache, $build_page, $build_admin_url ) : void {
            if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
                $plugins->setResponseStatus( 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $validate_post( $data ) ) {
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $result = $service->buildScopedSettingsFromPostResult( $data );
                $selection = is_array( $result['selection'] ?? null ) ? $result['selection'] : [];
                if ( ! empty( $result['errors'] ) ) {
                    $message = 'RSS Reader settings were not saved: ' . implode( ' ', array_slice( (array) $result['errors'], 0, 4 ) );
                    if ( count( (array) $result['errors'] ) > 4 ) {
                        $message .= ' More errors remain.';
                    }
                    $build_page( [ 'type' => 'danger', 'message' => $message ], (array) $result['settings'], $selection );
                    return;
                }
                $service->saveSettings( (array) $result['settings'] );
                $invalidate_public_cache();
                $action = strtolower( trim( (string) ( $data['action'] ?? 'save' ) ) );
                $section = strtolower( trim( (string) ( $data['section'] ?? '' ) ) );
                $message = $action === 'delete' ? 'Feed source deleted.' : ( $section === 'source' ? 'Feed source saved.' : ( $section === 'defaults' ? 'Display defaults saved.' : 'Public sidebar saved.' ) );
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'success', 'message' => $message ] );
                $plugins->redirect( $build_admin_url( $selection ) );
                return;
            } catch ( \Throwable $e ) {
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'danger', 'message' => 'RSS Reader settings could not be saved.' ] );
            }
            $plugins->redirect( $plugin_url );
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $refresh_url,
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache, $build_admin_url ) : void {
            if ( ! $security->isLoggedIn() || ! $security->isSuperAdmin() ) {
                $plugins->setResponseStatus( 403 );
                return;
            }
            $data = $plugins->getRequestData();
            if ( ! $validate_post( $data ) ) {
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
                $plugins->redirect( $plugin_url );
                return;
            }
            try {
                $source_key = trim( (string) ( $data['source_key'] ?? '' ) );
                $source_map = $service->getSourceMap();
                if ( $source_key !== '' && ! isset( $source_map[$source_key] ) ) {
                    throw new \InvalidArgumentException( 'source' );
                }
                $result = $service->refreshSources( $source_key !== '' ? [ $source_key ] : null );
                $invalidate_public_cache();
                $security->setFlash(
                    'rss-reader.notice',
                    [
                        'type' => (int) ( $result['failed'] ?? 0 ) > 0 ? 'warning' : 'success',
                        'message' => (string) ( $result['message'] ?? 'RSS Reader sources were refreshed.' ),
                    ]
                );
            } catch ( \Throwable $e ) {
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'danger', 'message' => 'RSS Reader refresh could not be completed.' ] );
            }
            $plugins->redirect( $source_key !== '' ? $build_admin_url( [ 'source' => $source_key ] ) : $plugin_url );
        }
    );
};
