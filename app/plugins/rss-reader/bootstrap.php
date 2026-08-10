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

    $build_page = static function( array $notice = [], ?array $form_settings = null ) use ( $plugins, $security, $date_formatter, $service, $save_url, $refresh_url, $escape, $render_select ) : void {
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
        $sources[] = [ 'key' => '', 'label' => '', 'url' => '', 'enabled' => true, 'authors_may_embed' => false, 'item_limit' => (int) ( $settings['defaults']['item_limit'] ?? 5 ) ];
        $source_options = [ '' => 'Select source' ];
        foreach ( is_array( $settings['sources'] ?? null ) ? $settings['sources'] : [] as $source ) {
            $source_key = (string) ( $source['key'] ?? '' );
            if ( $source_key !== '' ) {
                $source_options[$source_key] = (string) ( $source['label'] ?? $source_key ) . ' (' . $source_key . ')';
            }
        }
        $layout_options = [ 'compact' => 'Compact', 'list' => 'List', 'headlines' => 'Headlines' ];

        $body_html = '<div class="tm-rss-reader-admin d-grid gap-4">';
        $body_html .= '<form method="post" action="' . $escape( $save_url ) . '" class="d-grid gap-4">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';

        $body_html .= '<section class="rounded-3 p-3 bg-body-secondary d-grid gap-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary">Defaults</div>';
        $body_html .= '<div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="tm-rss-default-limit">Items</label><input class="form-control" id="tm-rss-default-limit" name="default_item_limit" type="number" min="1" max="50" value="' . (int) ( $settings['defaults']['item_limit'] ?? 5 ) . '"></div>';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="tm-rss-stale-hours">Stale after hours</label><input class="form-control" id="tm-rss-stale-hours" name="stale_hours" type="number" min="1" max="720" value="' . (int) ( $settings['defaults']['stale_hours'] ?? 24 ) . '"></div>';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-rss-default-layout">Layout</label>' . $render_select( 'default_layout', (string) ( $settings['defaults']['layout'] ?? 'compact' ), $layout_options, 'tm-rss-default-layout' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-5"><div class="d-flex flex-wrap gap-3">';
        $body_html .= '<div class="form-check"><input class="form-check-input" id="tm-rss-show-excerpt" name="default_show_excerpt" type="checkbox" value="1"' . ( ! empty( $settings['defaults']['show_excerpt'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-show-excerpt">Show excerpts</label></div>';
        $body_html .= '<div class="form-check"><input class="form-check-input" id="tm-rss-show-date" name="default_show_date" type="checkbox" value="1"' . ( ! empty( $settings['defaults']['show_date'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-show-date">Show dates</label></div>';
        $body_html .= '</div></div>';
        $body_html .= '</div><div class="form-text">Public pages render cached items only. Refresh from this page, CLI, or scheduled housekeeping.</div>';
        $body_html .= '</section>';

        $body_html .= '<section class="d-grid gap-3">';
        $body_html .= '<div><div class="small text-uppercase text-body-secondary">Sources</div><div class="form-text mt-1">Add approved feeds here. The final blank row becomes a new source when label and URL are filled.</div></div>';
        $body_html .= '<div class="tm-rss-reader-source-list">';
        foreach ( $sources as $index => $source ) {
            $source_key = (string) ( $source['key'] ?? '' );
            $row = is_array( $row_map[$source_key] ?? null ) ? $row_map[$source_key] : [];
            $row_id = 'tm-rss-source-' . (int) $index;
            $body_html .= '<div class="tm-rss-reader-source-row py-3">';
            $body_html .= '<div class="row g-3 align-items-end">';
            $body_html .= '<div class="col-12 col-md-5 col-xl-3"><label class="form-label small mb-1" for="' . $row_id . '-label">Label</label><input class="form-control" id="' . $row_id . '-label" name="sources[' . (int) $index . '][label]" type="text" maxlength="120" value="' . $escape( (string) ( $source['label'] ?? '' ) ) . '" placeholder="Example"></div>';
            $body_html .= '<div class="col-12 col-md-4 col-xl-2"><label class="form-label small mb-1" for="' . $row_id . '-key">Key</label><input class="form-control font-monospace" id="' . $row_id . '-key" name="sources[' . (int) $index . '][key]" type="text" maxlength="80" value="' . $escape( $source_key ) . '" placeholder="example"></div>';
            $body_html .= '<div class="col-12 col-xl-5"><label class="form-label small mb-1" for="' . $row_id . '-url">Feed URL</label><input class="form-control font-monospace" id="' . $row_id . '-url" name="sources[' . (int) $index . '][url]" type="url" value="' . $escape( (string) ( $source['url'] ?? '' ) ) . '" placeholder="https://example.com/feed.xml"></div>';
            $body_html .= '<div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="' . $row_id . '-limit">Items</label><input class="form-control" id="' . $row_id . '-limit" name="sources[' . (int) $index . '][item_limit]" type="number" min="1" max="50" value="' . (int) ( $source['item_limit'] ?? 5 ) . '"></div>';
            $body_html .= '</div>';
            $body_html .= '<div class="d-flex flex-wrap align-items-center gap-3 mt-2">';
            $body_html .= '<div class="form-check"><input class="form-check-input" id="' . $row_id . '-enabled" name="sources[' . (int) $index . '][enabled]" type="checkbox" value="1"' . ( ! empty( $source['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="' . $row_id . '-enabled">Enabled</label></div>';
            $body_html .= '<div class="form-check"><input class="form-check-input" id="' . $row_id . '-authors" name="sources[' . (int) $index . '][authors_may_embed]" type="checkbox" value="1"' . ( ! empty( $source['authors_may_embed'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="' . $row_id . '-authors">Authors may embed</label></div>';
            if ( $source_key !== '' ) {
                $cache_status = (string) ( $row['status'] ?? 'unrefreshed' );
                $last_success = (string) ( $row['last_success_at_utc'] ?? '' );
                $last_success_display = $last_success !== '' ? $date_formatter->formatUtcDateTime( $last_success ) : '';
                $message = (string) ( $row['message'] ?? '' );
                $body_html .= '<div class="small text-body-secondary text-break"><span class="fw-semibold text-body">' . $escape( $cache_status ) . '</span><span class="mx-1">|</span>' . (int) ( $row['item_count'] ?? 0 ) . ' items';
                if ( $last_success_display !== '' ) {
                    $body_html .= '<span class="mx-1">|</span>' . $escape( $last_success_display );
                }
                if ( $message !== '' ) {
                    $body_html .= '<span class="mx-1">|</span>' . $escape( $message );
                }
                $body_html .= '</div><code class="small text-break">[rss source=&quot;' . $escape( $source_key ) . '&quot;]</code>';
            } else {
                $body_html .= '<div class="small text-body-secondary">Blank row for a new source.</div>';
            }
            $body_html .= '</div></div>';
        }
        $body_html .= '</div>';
        $body_html .= '</section>';

        $slot = is_array( $settings['slots']['sidebar'] ?? null ) ? $settings['slots']['sidebar'] : [];
        $body_html .= '<section class="rounded-3 p-3 bg-body-secondary d-grid gap-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary">Sidebar module</div>';
        $body_html .= '<div class="row g-3 align-items-end">';
        $body_html .= '<div class="col-12 col-lg-3"><div class="form-check mb-lg-2"><input class="form-check-input" id="tm-rss-sidebar-enabled" name="slots[sidebar][enabled]" type="checkbox" value="1"' . ( ! empty( $slot['enabled'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-enabled">Show in sidebar slot</label></div></div>';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-rss-sidebar-source">Source</label>' . $render_select( 'slots[sidebar][source]', (string) ( $slot['source'] ?? '' ), $source_options, 'tm-rss-sidebar-source' ) . '</div>';
        $body_html .= '<div class="col-12 col-lg-3"><label class="form-label small mb-1" for="tm-rss-sidebar-title">Title override</label><input class="form-control" id="tm-rss-sidebar-title" name="slots[sidebar][title]" type="text" maxlength="120" value="' . $escape( (string) ( $slot['title'] ?? '' ) ) . '"></div>';
        $body_html .= '<div class="col-6 col-lg-1"><label class="form-label small mb-1" for="tm-rss-sidebar-limit">Items</label><input class="form-control" id="tm-rss-sidebar-limit" name="slots[sidebar][item_limit]" type="number" min="1" max="50" value="' . (int) ( $slot['item_limit'] ?? 5 ) . '"></div>';
        $body_html .= '<div class="col-6 col-lg-2"><label class="form-label small mb-1" for="tm-rss-sidebar-layout">Layout</label>' . $render_select( 'slots[sidebar][layout]', (string) ( $slot['layout'] ?? 'compact' ), $layout_options, 'tm-rss-sidebar-layout' ) . '</div>';
        $body_html .= '<div class="col-12"><div class="d-flex flex-wrap gap-3"><div class="form-check"><input class="form-check-input" id="tm-rss-sidebar-excerpt" name="slots[sidebar][show_excerpt]" type="checkbox" value="1"' . ( ! empty( $slot['show_excerpt'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-excerpt">Show excerpts</label></div><div class="form-check"><input class="form-check-input" id="tm-rss-sidebar-date" name="slots[sidebar][show_date]" type="checkbox" value="1"' . ( ! empty( $slot['show_date'] ) ? ' checked' : '' ) . '><label class="form-check-label" for="tm-rss-sidebar-date">Show dates</label></div></div></div>';
        $body_html .= '</div></section>';

        $body_html .= '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Save RSS Reader</button><button class="btn btn-outline-secondary" type="submit" formaction="' . $escape( $refresh_url ) . '" formmethod="post">Refresh now</button></div>';
        $body_html .= '</form>';
        $body_html .= '<section class="d-grid gap-2"><div class="small text-uppercase text-body-secondary">Shortcodes</div><div class="d-flex flex-wrap gap-2"><code class="text-break">[rss source=&quot;source-key&quot;]</code><code class="text-break">[rss source=&quot;source-key&quot; limit=&quot;3&quot; layout=&quot;headlines&quot; excerpt=&quot;false&quot;]</code></div></section>';
        $body_html .= '</div>';

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
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache, $build_page ) : void {
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
                $result = $service->buildSettingsFromPostResult( $data );
                if ( ! empty( $result['errors'] ) ) {
                    $message = 'RSS Reader settings were not saved: ' . implode( ' ', array_slice( (array) $result['errors'], 0, 4 ) );
                    if ( count( (array) $result['errors'] ) > 4 ) {
                        $message .= ' More errors remain.';
                    }
                    $build_page( [ 'type' => 'danger', 'message' => $message ], (array) $result['settings'] );
                    return;
                }
                $service->saveSettings( (array) $result['settings'] );
                $invalidate_public_cache();
                $security->setFlash( 'rss-reader.notice', [ 'type' => 'success', 'message' => 'RSS Reader settings saved.' ] );
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
        static function() use ( $plugins, $security, $service, $plugin_url, $validate_post, $invalidate_public_cache ) : void {
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
                $result = $service->refreshSources();
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
            $plugins->redirect( $plugin_url );
        }
    );
};
