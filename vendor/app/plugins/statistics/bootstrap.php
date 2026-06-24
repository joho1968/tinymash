<?php

require_once __DIR__ . '/TinyMashStatisticsRepository.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'statistics' );
    $config = $plugins->getService( 'config' );
    $project_root = dirname( __DIR__, 3 );
    $repository = new TinyMashStatisticsRepository(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'statistics'
    );

    if ( ! $config instanceof TinyMashConfig ) {
        throw new \RuntimeException( 'Required services for the statistics plugin are not available.' );
    }

    $plugins->registerHousekeepingTask(
        $plugin_key,
        static function() use ( $plugins, $repository, $plugin_key ) : string {
            $settings = $plugins->getPluginSystemSettings( $plugin_key );
            $retention_days = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 180;
            if ( $retention_days <= 0 ) {
                return( 'statistics retention is disabled' );
            }

            $result = $repository->pruneDailyData( $retention_days );
            return(
                'removed '
                . (int) ( $result['removed'] ?? 0 )
                . ' stale daily statistic bucket(s)'
            );
        },
        'retention',
        'Prune statistics retention'
    );

    $plugins->registerExportContributor(
        $plugin_key,
        static function( array $context ) use ( $repository ) : string {
            $scope = strtolower( trim( (string) ( $context['scope'] ?? '' ) ) );
            $plugins_directory = rtrim( (string) ( $context['plugins_directory'] ?? '' ), DIRECTORY_SEPARATOR );
            if ( $plugins_directory === '' ) {
                return( 'No plugin export directory was provided.' );
            }

            $statistics_root = $plugins_directory . DIRECTORY_SEPARATOR . 'statistics';
            if ( $scope === 'author' ) {
                $author_slug = trim( (string) ( $context['author_username'] ?? '' ) );
                if ( $author_slug === '' ) {
                    return( 'No author username was provided for the statistics export.' );
                }
                $result = $repository->exportAuthorData( $author_slug, $statistics_root );
                return( ! empty( $result['exported'] ) ? 'Exported statistics for "' . $author_slug . '".' : 'No statistics existed for "' . $author_slug . '".' );
            }

            $result = $repository->exportSiteData( $statistics_root );
            return( ! empty( $result['exported'] ) ? 'Exported site statistics.' : 'No site statistics existed.' );
        },
        'both',
        'Statistics data'
    );

    $plugins->registerImportContributor(
        $plugin_key,
        static function( array $context ) use ( $repository ) : string {
            $scope = strtolower( trim( (string) ( $context['scope'] ?? '' ) ) );
            $plugins_directory = rtrim( (string) ( $context['plugins_directory'] ?? '' ), DIRECTORY_SEPARATOR );
            if ( $plugins_directory === '' ) {
                return( 'No plugin import directory was provided.' );
            }

            $statistics_root = $plugins_directory . DIRECTORY_SEPARATOR . 'statistics';
            if ( $scope === 'author' ) {
                $author_slug = trim( (string) ( $context['author_username'] ?? '' ) );
                if ( $author_slug === '' ) {
                    return( 'No author username was provided for the statistics import.' );
                }
                $result = $repository->importAuthorData( $author_slug, $statistics_root, ! empty( $context['replace_existing'] ) );
                return( ! empty( $result['imported'] ) ? 'Imported statistics for "' . $author_slug . '".' : 'No statistics existed for "' . $author_slug . '" in the import bundle.' );
            }

            $result = $repository->importSiteData( $statistics_root, ! empty( $context['replace_existing'] ) );
            return( ! empty( $result['imported'] ) ? 'Imported site statistics.' : 'No site statistics existed in the import bundle.' );
        },
        'both',
        'Statistics data'
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $security = $plugins->getService( 'security' );
    $date_formatter = $plugins->getService( 'date.formatter' );
    $user_repository = $plugins->getService( 'user.repository' );
    if ( ! $security instanceof TinyMashSecurity || ! $date_formatter instanceof TinyMashDateFormatter || ! $user_repository instanceof \app\classes\TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the statistics plugin are not available.' );
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $statistics_url = $admin_url . '/statistics';
    $collect_url = '/_tm/statistics/collect';
    $settings_url = $admin_url . '/system/plugins/statistics';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'author',
        [
            'section' => 'statistics',
            'label' => 'Statistics',
            'url' => $statistics_url,
            'icon' => 'bi-graph-up-arrow',
            'order' => 75,
        ]
    );

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Statistics',
            'summary' => 'Collection and retention settings for the statistics plugin.',
            'fields' => [
                [
                    'key' => 'collection_enabled',
                    'type' => 'checkbox',
                    'label' => 'Collect public page views',
                    'help' => 'When enabled, public pages send a small first-party page-view beacon into the statistics store.',
                    'default' => 1,
                ],
                [
                    'key' => 'retention_days',
                    'type' => 'select',
                    'label' => 'Daily retention',
                    'help' => 'Keeps per-day history for this many days during housekeeping. All-time totals remain.',
                    'default' => '180',
                    'options' => [
                        [ 'value' => '30', 'label' => '30 days' ],
                        [ 'value' => '90', 'label' => '90 days' ],
                        [ 'value' => '180', 'label' => '180 days' ],
                        [ 'value' => '365', 'label' => '365 days' ],
                        [ 'value' => '0', 'label' => 'Keep forever' ],
                    ],
                ],
                [
                    'key' => 'exclude_self_views',
                    'type' => 'checkbox',
                    'label' => 'Exclude self and logged-in root views',
                    'help' => 'When enabled, your own author-space views and logged-in root/frontpage views are not counted.',
                    'default' => 1,
                ],
                [
                    'key' => 'exclude_admin_views',
                    'type' => 'checkbox',
                    'label' => 'Exclude admin views',
                    'help' => 'When enabled, logged-in admin views are never counted.',
                    'default' => 1,
                ],
            ],
        ]
    );

    $plugins->registerProfileSettingsSection(
        $plugin_key,
        'publishing',
        [
            'title' => 'Public statistics',
            'summary' => 'Controls whether views of your author space and author-owned content are counted.',
            'fields' => [
                [
                    'key' => 'author_statistics_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable my public statistics',
                    'help' => 'When disabled, views of your author home and author-owned content are not counted.',
                    'default' => true,
                ],
            ],
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-statistics',
        [
            'title' => 'Statistics',
            'summary' => 'Public page-view counts for sitewide and author-space reporting.',
            'group' => 'Plugins',
            'order' => 120,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it tracks',
                    'markdown' => 'Statistics records public page views. It does not track admin pages or try to act as a full analytics suite.',
                ],
                [
                    'title' => 'How it records',
                    'markdown' => 'The plugin injects a small first-party script into the shared public footer slot. That script posts a page-view beacon back to tinymash.',
                ],
                [
                    'title' => 'Charts',
                    'markdown' => 'The plugin uses numbers and small server-rendered SVG charts. That keeps it local-only, small, and dependency-free.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $resolve_author_label = static function( string $author_slug ) use ( $user_repository ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( '' );
        }

        return( $user_repository->getDisplayLabelByAuthorSlug( $author_slug ) );
    };

    $get_settings = static function() use ( $plugins, $plugin_key ) : array {
        $settings = $plugins->getPluginSystemSettings( $plugin_key );
        $collection_enabled = ! array_key_exists( 'collection_enabled', $settings ) || ! empty( $settings['collection_enabled'] );
        $retention_days = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 180;
        if ( ! in_array( $retention_days, [ 0, 30, 90, 180, 365 ], true ) ) {
            $retention_days = 180;
        }
        $exclude_self_views = ! array_key_exists( 'exclude_self_views', $settings ) || ! empty( $settings['exclude_self_views'] );
        $exclude_admin_views = ! array_key_exists( 'exclude_admin_views', $settings ) || ! empty( $settings['exclude_admin_views'] );

        return(
            [
                'collection_enabled' => $collection_enabled,
                'retention_days' => $retention_days,
                'exclude_self_views' => $exclude_self_views,
                'exclude_admin_views' => $exclude_admin_views,
            ]
        );
    };

    $author_statistics_enabled = static function( string $author_slug ) use ( $user_repository, $plugin_key ) : bool {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( true );
        }

        $settings = $user_repository->getPluginSettings( $author_slug, $plugin_key );
        if ( array_key_exists( 'author_statistics_enabled', $settings ) ) {
            return( ! empty( $settings['author_statistics_enabled'] ) );
        }

        return( true );
    };

    $get_request_visitor_context = static function() use ( $security ) : array {
        if ( method_exists( $security, 'getRequestVisitorContext' ) ) {
            $visitor_context = $security->getRequestVisitorContext();
            if ( is_array( $visitor_context ) ) {
                return( $visitor_context );
            }
        }

        return(
            [
                'logged_in' => method_exists( $security, 'isLoggedIn' ) ? (bool) $security->isLoggedIn() : false,
                'username' => method_exists( $security, 'getCurrentUsername' ) ? (string) $security->getCurrentUsername() : '',
                'role' => method_exists( $security, 'getCurrentRole' ) ? (string) $security->getCurrentRole() : 'public',
            ]
        );
    };

    $should_record_view = static function( string $author_slug ) use ( $get_request_visitor_context, $get_settings ) : bool {
        $settings = $get_settings();
        if ( ! $settings['collection_enabled'] ) {
            return( false );
        }

        $visitor_context = $get_request_visitor_context();
        if ( empty( $visitor_context['logged_in'] ) ) {
            return( true );
        }

        if ( $settings['exclude_admin_views'] && strtolower( trim( (string) ( $visitor_context['role'] ?? '' ) ) ) === 'superadmin' ) {
            return( false );
        }

        if ( $settings['exclude_self_views'] ) {
            $current_username = strtolower( trim( (string) ( $visitor_context['username'] ?? '' ) ) );
            if ( $author_slug === '' ) {
                return( false );
            }
            if ( $current_username !== '' && $author_slug === $current_username ) {
                return( false );
            }
        }

        return( true );
    };

    $render_chart = static function( array $series, string $bar_color = '#0d6efd' ) : string {
        if ( empty( $series ) ) {
            return( '<div class="small text-body-secondary">No recent data yet.</div>' );
        }

        $values = array_map( static fn( array $item ) : int => max( 0, (int) ( $item['value'] ?? 0 ) ), $series );
        $max_value = max( $values );
        if ( $max_value <= 0 ) {
            return( '<div class="small text-body-secondary">No recent data yet.</div>' );
        }

        $bar_width = 18;
        $gap = 6;
        $chart_height = 112;
        $chart_width = count( $series ) * ( $bar_width + $gap ) - $gap;
        $svg = '<svg viewBox="0 0 ' . $chart_width . ' ' . $chart_height . '" class="w-100" aria-hidden="true" preserveAspectRatio="none">';
        foreach ( $series as $index => $item ) {
            $value = max( 0, (int) ( $item['value'] ?? 0 ) );
            $height = $value > 0 ? max( 6, (int) round( ( $value / $max_value ) * ( $chart_height - 24 ) ) ) : 0;
            if ( $height <= 0 ) {
                continue;
            }
            $x = $index * ( $bar_width + $gap );
            $y = $chart_height - $height;
            $label = trim( (string) ( $item['label'] ?? '' ) );
            $title = ( $label !== '' ? $label . ': ' : '' ) . $value . ' page view' . ( $value === 1 ? '' : 's' );
            $escaped_title = htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
            $svg .= '<g title="' . $escaped_title . '"><title>' . $escaped_title . '</title>';
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bar_width . '" height="' . $height . '" rx="3" fill="' . $bar_color . '"></rect>';
            $svg .= '</g>';
        }
        $svg .= '</svg>';
        $svg .= '<div class="d-flex justify-content-between small text-body-secondary mt-2">';
        $svg .= '<span>' . htmlspecialchars( (string) ( $series[0]['label'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>';
        $svg .= '<span>' . htmlspecialchars( (string) ( $series[array_key_last( $series )]['label'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>';
        $svg .= '</div>';
        return( $svg );
    };

    $format_last_seen = static function( string $timestamp_utc ) use ( $date_formatter ) : string {
        return( $timestamp_utc !== '' ? $date_formatter->formatUtcDateTime( $timestamp_utc ) : 'Never' );
    };

    $kind_label = static function( string $page_kind, string $entry_type = '' ) : string {
        return(
            match ( $page_kind ) {
                'home' => 'Home',
                'author_home' => 'Author home',
                'post' => 'Post',
                'page' => $entry_type === 'page' ? 'Page' : 'Page',
                default => ucfirst( $page_kind !== '' ? $page_kind : ( $entry_type !== '' ? $entry_type : 'Page' ) ),
            }
        );
    };

    $render_page_table = static function( array $pages ) use ( $escape, $kind_label ) : string {
        if ( empty( $pages ) ) {
            return( '<div class="small text-body-secondary">No page views recorded yet.</div>' );
        }

        $html = '<div class="table-responsive">';
        $html .= '<table class="table table-sm align-middle mb-0">';
        $html .= '<thead><tr><th>Content</th><th>Kind</th><th class="text-end">Views</th></tr></thead><tbody>';
        foreach ( $pages as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }
            $title = trim( (string) ( $page['title'] ?? '' ) );
            $path = trim( (string) ( $page['path'] ?? '' ) );
            $label = $title !== '' ? $title : $path;
            $html .= '<tr>';
            $html .= '<td><div class="fw-semibold">' . $escape( $label ) . '</div><div class="small text-body-secondary font-monospace">' . $escape( $path ) . '</div></td>';
            $html .= '<td>' . $escape( $kind_label( (string) ( $page['page_kind'] ?? '' ), (string) ( $page['entry_type'] ?? '' ) ) ) . '</td>';
            $html .= '<td class="text-end">' . (int) ( $page['total_views'] ?? 0 ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return( $html );
    };

    $render_author_table = static function( array $authors ) use ( $escape, $resolve_author_label ) : string {
        if ( empty( $authors ) ) {
            return( '<div class="small text-body-secondary">No author-space views recorded yet.</div>' );
        }

        $html = '<div class="table-responsive">';
        $html .= '<table class="table table-sm align-middle mb-0">';
        $html .= '<thead><tr><th>Author</th><th class="text-end">Views</th><th class="text-end">30 days</th></tr></thead><tbody>';
        foreach ( $authors as $author ) {
            if ( ! is_array( $author ) ) {
                continue;
            }
            $author_slug = (string) ( $author['author_slug'] ?? '' );
            $author_label = $resolve_author_label( $author_slug );
            $html .= '<tr>';
            $html .= '<td><div class="fw-semibold">' . $escape( $author_label !== '' ? $author_label : $author_slug ) . '</div><div class="small text-body-secondary font-monospace">' . $escape( $author_slug ) . '</div></td>';
            $html .= '<td class="text-end">' . (int) ( $author['total_views'] ?? 0 ) . '</td>';
            $html .= '<td class="text-end">' . (int) ( $author['last_30_days'] ?? 0 ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return( $html );
    };

    $render_cards = static function( array $cards ) : string {
        $html = '<div class="row g-3">';
        foreach ( $cards as $card ) {
            if ( ! is_array( $card ) ) {
                continue;
            }
            $html .= '<div class="col-12 col-md-6 col-xl-3">';
            $html .= '<section class="h-100 rounded-3 p-3 bg-body-secondary">';
            $html .= '<div class="small text-uppercase text-body-secondary mb-2">' . htmlspecialchars( (string) ( $card['label'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</div>';
            $html .= '<div class="fs-4 fw-semibold">' . (int) ( $card['value'] ?? 0 ) . '</div>';
            $html .= '</section>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return( $html );
    };

    $render_statistics_page = static function( array $notice = [] ) use ( $plugins, $config, $security, $repository, $get_settings, $render_cards, $render_chart, $render_page_table, $render_author_table, $format_last_seen, $statistics_url, $settings_url, $escape, $resolve_author_label ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( (string) ( $config->configGetLoginURL() ?: '/login' ) );
            return;
        }

        if ( empty( $notice ) ) {
            $flash_notice = $security->pullFlash( 'statistics.notice', [] );
            if ( is_array( $flash_notice ) ) {
                $notice = $flash_notice;
            }
        }

        $settings = $get_settings();
        $current_username = trim( (string) $security->getCurrentUsername() );
        $current_author_label = $resolve_author_label( $current_username );
        $is_superadmin = $security->isSuperAdmin();
        $site_report = $repository->getSiteReport();
        $author_report = $repository->getAuthorReport( $current_username );
        $requested_tab = strtolower( trim( (string) ( $_GET['tab'] ?? 'global' ) ) );
        if ( ! in_array( $requested_tab, [ 'global', 'author' ], true ) ) {
            $requested_tab = 'global';
        }
        if ( ! $is_superadmin ) {
            $requested_tab = 'author';
        }
        $actions_html = $is_superadmin
            ? '<a class="btn btn-outline-secondary btn-sm" href="' . $escape( $settings_url ) . '">Configure</a>'
            : '';

        $body_html = '';
        $body_html .= '<section class="rounded-3 p-3 bg-body-secondary mb-3">';
        $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Collection</div>';
        $body_html .= '<div class="d-flex flex-wrap gap-3 small text-body-secondary">';
        $body_html .= '<span>Status: <span class="text-body">' . ( $settings['collection_enabled'] ? 'Enabled' : 'Disabled' ) . '</span></span>';
        $body_html .= '<span>Daily retention: <span class="text-body">' . ( $settings['retention_days'] > 0 ? (int) $settings['retention_days'] . ' days' : 'Keep forever' ) . '</span></span>';
        $body_html .= '</div>';
        $body_html .= '</section>';

        if ( $is_superadmin ) {
            $global_active = $requested_tab === 'global';
            $author_active = $requested_tab === 'author';
            $body_html .= '<nav class="mb-3" aria-label="Statistics views">';
            $body_html .= '<ul class="nav nav-tabs">';
            $body_html .= '<li class="nav-item"><a class="nav-link' . ( $global_active ? ' active' : '' ) . '" href="' . $escape( $statistics_url ) . '?tab=global"' . ( $global_active ? ' aria-current="page"' : '' ) . '>Global</a></li>';
            $body_html .= '<li class="nav-item"><a class="nav-link' . ( $author_active ? ' active' : '' ) . '" href="' . $escape( $statistics_url ) . '?tab=author"' . ( $author_active ? ' aria-current="page"' : '' ) . '>Author space (' . $escape( $current_author_label !== '' ? $current_author_label : $current_username ) . ')</a></li>';
            $body_html .= '</ul>';
            $body_html .= '</nav>';
        }

        if ( $is_superadmin && $requested_tab === 'global' ) {
            $body_html .= '<section class="d-grid gap-3 mb-4">';
            $body_html .= '<div>';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Sitewide</div>';
            $body_html .= '<h2 class="h5 mb-1">Public page views</h2>';
            $body_html .= '<div class="small text-body-secondary">Last seen: ' . $escape( $format_last_seen( (string) ( $site_report['last_viewed_at_utc'] ?? '' ) ) ) . '</div>';
            $body_html .= '</div>';
            $body_html .= $render_cards(
                [
                    [ 'label' => 'All time', 'value' => (int) ( $site_report['total_views'] ?? 0 ) ],
                    [ 'label' => 'Last 7 days', 'value' => (int) ( $site_report['last_7_days'] ?? 0 ) ],
                    [ 'label' => 'Last 30 days', 'value' => (int) ( $site_report['last_30_days'] ?? 0 ) ],
                    [ 'label' => 'Author spaces', 'value' => (int) ( $site_report['tracked_authors'] ?? 0 ) ],
                ]
            );
            $body_html .= '<div class="row g-3">';
            $body_html .= '<div class="col-12 col-xl-7"><section class="p-3 bg-body border rounded-3 shadow-sm"><div class="small text-uppercase text-body-secondary mb-2">Recent trend</div><h3 class="h6 mb-3">Last 14 days</h3>' . $render_chart( (array) ( $site_report['daily_series'] ?? [] ) ) . '</section></div>';
            $body_html .= '<div class="col-12 col-xl-5"><section class="p-3 bg-body border rounded-3 shadow-sm"><div class="small text-uppercase text-body-secondary mb-2">Top author spaces</div><h3 class="h6 mb-3">By all-time views</h3>' . $render_author_table( (array) ( $site_report['top_authors'] ?? [] ) ) . '</section></div>';
            $body_html .= '<div class="col-12"><section class="p-3 bg-body border rounded-3 shadow-sm"><div class="small text-uppercase text-body-secondary mb-2">Top content</div><h3 class="h6 mb-3">By all-time views</h3>' . $render_page_table( (array) ( $site_report['top_pages'] ?? [] ) ) . '</section></div>';
            $body_html .= '</div>';
            $body_html .= '</section>';
        }

        if ( ! $is_superadmin || $requested_tab === 'author' ) {
            $body_html .= '<section class="d-grid gap-3">';
            $body_html .= '<div>';
            $body_html .= '<div class="small text-uppercase text-body-secondary mb-2">Author space</div>';
            $body_html .= '<h2 class="h5 mb-1">Your public views</h2>';
            $body_html .= '<div class="small text-body-secondary">Current author: ' . $escape( $current_author_label !== '' ? $current_author_label : $current_username ) . ' · Last seen: ' . $escape( $format_last_seen( (string) ( $author_report['last_viewed_at_utc'] ?? '' ) ) ) . '</div>';
            $body_html .= '</div>';
            $body_html .= $render_cards(
                [
                    [ 'label' => 'All time', 'value' => (int) ( $author_report['total_views'] ?? 0 ) ],
                    [ 'label' => 'Last 7 days', 'value' => (int) ( $author_report['last_7_days'] ?? 0 ) ],
                    [ 'label' => 'Last 30 days', 'value' => (int) ( $author_report['last_30_days'] ?? 0 ) ],
                    [ 'label' => 'Tracked pages', 'value' => (int) ( $author_report['tracked_pages'] ?? 0 ) ],
                ]
            );
            $body_html .= '<div class="row g-3">';
            $body_html .= '<div class="col-12 col-xl-7"><section class="p-3 bg-body border rounded-3 shadow-sm"><div class="small text-uppercase text-body-secondary mb-2">Recent trend</div><h3 class="h6 mb-3">Last 14 days</h3>' . $render_chart( (array) ( $author_report['daily_series'] ?? [] ), '#198754' ) . '</section></div>';
            $body_html .= '<div class="col-12 col-xl-5"><section class="p-3 bg-body border rounded-3 shadow-sm"><div class="small text-uppercase text-body-secondary mb-2">Top content</div><h3 class="h6 mb-3">By all-time views</h3>' . $render_page_table( (array) ( $author_report['top_pages'] ?? [] ) ) . '</section></div>';
            $body_html .= '</div>';
            $body_html .= '</section>';
        }

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Statistics',
                'current_section' => 'statistics',
                'current_location' => 'statistics',
                'help_contexts' => [ 'plugin-statistics' ],
                'plugin_page_kicker' => 'Statistics',
                'plugin_page_title' => 'Public page views',
                'plugin_page_summary' => 'Sitewide reports for admins and author-scoped reports for the current account.',
                'plugin_page_notice' => $notice,
                'plugin_page_actions_html' => $actions_html,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        $statistics_url,
        static function() use ( $render_statistics_page, $plugins, $login_url, $security ) : void {
            if ( ! $security->isLoggedIn() ) {
                $plugins->redirect( $login_url );
                return;
            }

            $render_statistics_page();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'post',
        $collect_url,
        static function() use ( $plugins, $config, $repository, $get_settings, $security, $author_statistics_enabled, $should_record_view ) : void {
            $settings = $get_settings();
            if ( ! $settings['collection_enabled'] ) {
                $plugins->setResponseStatus( 204 );
                return;
            }

            if ( ! $config->isSitePublic() && ! $security->isLoggedIn() ) {
                $plugins->setResponseStatus( 403 );
                return;
            }

            $statistics_header = trim( (string) ( $_SERVER['HTTP_X_TINYMASH_STATISTICS'] ?? '' ) );
            if ( $statistics_header !== '1' ) {
                $plugins->setResponseStatus( 403 );
                return;
            }

            $origin = trim( (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
            $base_origin = '';
            $base_url = (string) $config->configGetBaseURL();
            if ( $base_url !== '' ) {
                $scheme = (string) parse_url( $base_url, PHP_URL_SCHEME );
                $host = (string) parse_url( $base_url, PHP_URL_HOST );
                $port = (string) parse_url( $base_url, PHP_URL_PORT );
                if ( $scheme !== '' && $host !== '' ) {
                    $base_origin = $scheme . '://' . $host . ( $port !== '' ? ':' . $port : '' );
                }
            }
            if ( $origin !== '' && $base_origin !== '' && strcasecmp( $origin, $base_origin ) !== 0 ) {
                $plugins->setResponseStatus( 403 );
                return;
            }

            $data = $plugins->getRequestData();
            try {
                $author_slug = trim( (string) ( $data['author_slug'] ?? '' ) );
                if ( ! $should_record_view( $author_slug ) ) {
                    $plugins->setResponseStatus( 204 );
                    return;
                }
                if ( $author_slug !== '' && ! $author_statistics_enabled( $author_slug ) ) {
                    $plugins->setResponseStatus( 204 );
                    return;
                }

                $repository->recordView(
                    [
                        'path' => (string) ( $data['path'] ?? '' ),
                        'title' => (string) ( $data['title'] ?? '' ),
                        'page_kind' => (string) ( $data['page_kind'] ?? '' ),
                        'author_slug' => $author_slug,
                        'entry_type' => (string) ( $data['entry_type'] ?? '' ),
                    ]
                );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Statistics collection failed (' . $e->getMessage() . ')' );
            }

            $plugins->setResponseStatus( 204 );
        }
    );

    $plugins->registerPublicSlotRenderer(
        $plugin_key,
        'footer',
        static function( array $context ) use ( $get_request_visitor_context, $get_settings, $collect_url, $config, $repository, $author_statistics_enabled, $should_record_view ) : string {
            $settings = $get_settings();
            if ( ! $settings['collection_enabled'] ) {
                return( '' );
            }

            $entry = is_array( $context['entry'] ?? null ) ? $context['entry'] : [];
            $author_slug = trim( (string) ( $context['author_slug'] ?? ( $entry['author_slug'] ?? '' ) ) );
            $page_kind = 'home';
            $entry_type = '';
            $title = trim( (string) ( $entry['title'] ?? '' ) );

            if ( ! empty( $entry ) ) {
                $entry_type = strtolower( trim( (string) ( $entry['type'] ?? '' ) ) );
                $page_kind = $entry_type === 'post' ? 'post' : 'page';
            } elseif ( $author_slug !== '' ) {
                $page_kind = 'author_home';
                $title = $author_slug;
            } else {
                $title = trim( (string) ( $context['site_name'] ?? 'Home' ) );
            }

            if ( ! $should_record_view( $author_slug ) ) {
                return( '' );
            }

            $visitor_context = $get_request_visitor_context();
            if ( ! $config->isSitePublic() && ! empty( $visitor_context['logged_in'] ) ) {
                $request_path = trim( (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) );
                $request_path = $request_path !== '' ? $request_path : '/';

                if ( $author_slug === '' || $author_statistics_enabled( $author_slug ) ) {
                    if ( empty( $_SESSION['tinymash.statistics.private_last_seen'] ) || ! is_array( $_SESSION['tinymash.statistics.private_last_seen'] ) ) {
                        $_SESSION['tinymash.statistics.private_last_seen'] = [];
                    }

                    $last_seen = (int) ( $_SESSION['tinymash.statistics.private_last_seen'][$request_path] ?? 0 );
                    $now = time();
                    if ( $last_seen <= 0 || ( $now - $last_seen ) >= 1800 ) {
                        try {
                            $repository->recordView(
                                [
                                    'path' => $request_path,
                                    'title' => $title !== '' ? $title : $request_path,
                                    'page_kind' => $page_kind,
                                    'author_slug' => $author_slug,
                                    'entry_type' => $entry_type,
                                ]
                            );
                            $_SESSION['tinymash.statistics.private_last_seen'][$request_path] = $now;
                        } catch ( \Throwable $e ) {
                            error_log( basename( __FILE__ ) . ': Private statistics collection failed (' . $e->getMessage() . ')' );
                        }
                    }
                }

                return( '' );
            }

            $payload = [
                'collectUrl' => $collect_url,
                'authorSlug' => $author_slug,
                'pageKind' => $page_kind,
                'entryType' => $entry_type,
                'title' => $title,
            ];

            return(
                '<script type="module">'
                . '(function(){'
                . 'const payload=' . json_encode( $payload, JSON_UNESCAPED_SLASHES ) . ';'
                . 'if(!payload.collectUrl||!window.fetch||!window.localStorage){return;}'
                . 'const dnt=(navigator.doNotTrack||window.doNotTrack||navigator.msDoNotTrack||"").toString().toLowerCase();'
                . 'if(dnt==="1"||dnt==="yes"){return;}'
                . 'const path=window.location.pathname||"/";'
                . 'const storageKey="tm:statistics:view:"+path;'
                . 'const now=Date.now();'
                . 'const lastSeen=parseInt(window.localStorage.getItem(storageKey)||"0",10);'
                . 'if(Number.isFinite(lastSeen)&&lastSeen>0&&(now-lastSeen)<1800000){return;}'
                . 'window.localStorage.setItem(storageKey,String(now));'
                . 'const body=new URLSearchParams();'
                . 'body.set("path",path);'
                . 'body.set("title",payload.title||document.title||path);'
                . 'body.set("page_kind",payload.pageKind||"page");'
                . 'body.set("author_slug",payload.authorSlug||"");'
                . 'body.set("entry_type",payload.entryType||"");'
                . 'fetch(payload.collectUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8","X-TinyMash-Statistics":"1"},body:body.toString(),credentials:"same-origin",keepalive:true,cache:"no-store"}).catch(function(){});'
                . '})();'
                . '</script>'
            );
        }
    );
};
