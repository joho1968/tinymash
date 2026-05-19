<?php

require_once __DIR__ . '/TinyMashSearchService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'search' );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $content_renderer = $plugins->getService( 'content.renderer' );
    $theme = $plugins->getService( 'theme' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig
        || ! $security instanceof TinyMashSecurity
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $content_renderer instanceof TinyMashContentRenderer
        || ! $theme instanceof TinyMashTheme
        || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Search plugin are not available.' );
    }

    $search_service = new TinyMashSearchService( $config, $content_repository, $content_renderer, $theme, $user_repository );
    \Flight::set( 'public.search', $search_service->getPublicSearchSettings() );

    $apply_author_theme_context = static function( string $author_slug ) use ( $theme ) : void {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            $theme->clearThemeKeyOverride();
            return;
        }

        $theme->setThemeKeyOverride( $theme->resolveThemeKeyForAuthorContext( $author_slug ) );
    };

    $render_not_found = static function( string $message = 'Page not found', string $author_slug = '' ) use ( $theme ) : void {
        \Flight::response()->status( 404 );
        \Flight::view()->render(
            $theme->getTemplate( '404' ),
            array_merge(
                $theme->getBaseViewData( 'Page not found', null, $author_slug !== '' ? $author_slug : null ),
                [
                    'title' => 'Page not found',
                    'message' => $message,
                ]
            )
        );
    };

    $server_timing = [];
    $record_server_timing = static function( string $metric_name, float $started_at ) use ( &$server_timing ) : void {
        $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( $metric_name ) ) ?? '' );
        if ( $metric_name === '' ) {
            return;
        }

        $duration_ms = max( 0, ( microtime( true ) - $started_at ) * 1000 );
        if ( ! isset( $server_timing[$metric_name] ) ) {
            $server_timing[$metric_name] = 0.0;
        }

        $server_timing[$metric_name] += $duration_ms;
    };
    $record_renderer_metrics = static function() use ( $content_renderer, &$server_timing ) : void {
        if ( ! $content_renderer instanceof TinyMashContentRenderer || ! method_exists( $content_renderer, 'getProfilingMetrics' ) ) {
            return;
        }

        $profiling_metrics = $content_renderer->getProfilingMetrics();
        if ( ! is_array( $profiling_metrics ) ) {
            return;
        }

        foreach ( $profiling_metrics as $metric_name => $duration_ms ) {
            $metric_name = 'search_render_' . ( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $metric_name ) ?? '' );
            if ( ! is_numeric( $duration_ms ) || $metric_name === 'search_render_' ) {
                continue;
            }
            if ( ! isset( $server_timing[$metric_name] ) ) {
                $server_timing[$metric_name] = 0.0;
            }
            $server_timing[$metric_name] += max( 0, (float) $duration_ms );
        }
    };
    $record_repository_metrics = static function() use ( $content_repository, &$server_timing ) : void {
        if ( ! $content_repository instanceof TinyMashContentRepository || ! method_exists( $content_repository, 'getSearchProfilingMetrics' ) ) {
            return;
        }

        $profiling_metrics = $content_repository->getSearchProfilingMetrics();
        if ( ! is_array( $profiling_metrics ) ) {
            return;
        }

        foreach ( $profiling_metrics as $metric_name => $duration_ms ) {
            $metric_name = 'search_repo_' . ( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $metric_name ) ?? '' );
            if ( ! is_numeric( $duration_ms ) || $metric_name === 'search_repo_' ) {
                continue;
            }
            if ( ! isset( $server_timing[$metric_name] ) ) {
                $server_timing[$metric_name] = 0.0;
            }
            $server_timing[$metric_name] += max( 0, (float) $duration_ms );
        }
    };
    $emit_server_timing = static function() use ( &$server_timing ) : void {
        if ( headers_sent() || empty( $server_timing ) ) {
            return;
        }

        $parts = [];
        foreach ( $server_timing as $metric_name => $duration_ms ) {
            if ( ! is_numeric( $duration_ms ) ) {
                continue;
            }

            $parts[] = $metric_name . ';dur=' . number_format( max( 0, (float) $duration_ms ), 2, '.', '' );
        }

        if ( ! empty( $parts ) ) {
            header( 'Server-Timing: ' . implode( ', ', $parts ) );
        }
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/search',
        static function() use ( $plugins, $config, $security, $theme, $search_service, $apply_author_theme_context, $render_not_found, $record_server_timing, $record_renderer_metrics, $record_repository_metrics, $emit_server_timing ) : void {
            $request_started_at = microtime( true );
            $query_data = $plugins->getRequestQueryData();
            $query = $search_service->resolveQuery( $query_data );
            $author_slug = $search_service->resolveAuthorSlug( $query_data );
            $page = $search_service->resolvePage( $query_data );
            $allow_private_author_content = ! $config->isSitePublic() && $security->isLoggedIn();
            $apply_author_theme_context( $author_slug );

            if ( ! $config->isSitePublic() && ! $security->isLoggedIn() ) {
                $render_not_found( 'This site is not public. Log in to view content.', $author_slug );
                return;
            }

            if ( $query === '' ) {
                $plugins->redirect( $author_slug !== '' ? $theme->getAuthorURL( $author_slug ) : '/' );
                return;
            }

            $search_started_at = microtime( true );
            $results = $search_service->search( $query, $author_slug, $page, $allow_private_author_content );
            $record_server_timing( 'search', $search_started_at );
            $record_repository_metrics();
            $record_renderer_metrics();
            if ( $author_slug !== '' && empty( $results['author_scope_accessible'] ) && ! $allow_private_author_content ) {
                $render_not_found();
                return;
            }

            $document_title = $query !== '' ? 'Search: ' . $query : 'Search';
            $base_view_started_at = microtime( true );
            $view_data = array_merge(
                $theme->getBaseViewData( $document_title, null, $author_slug !== '' ? $author_slug : null ),
                [
                    'title' => $document_title,
                    'listing_context_summary' => $search_service->buildListingSummary( $results ),
                    'listing_empty_message' => $search_service->buildEmptyMessage( $results ),
                    'listing_pagination' => $results['pagination'],
                    'force_listing_results' => true,
                    'search_query' => $results['query'],
                    'search_total_matches' => $results['total_matches'],
                ]
            );
            $record_server_timing( 'base_view', $base_view_started_at );
            $record_server_timing( 'controller', $request_started_at );
            $emit_server_timing();

            if ( $author_slug !== '' ) {
                \Flight::view()->render(
                    $theme->getTemplate( 'author-home' ),
                    array_merge(
                        $view_data,
                        [
                            'author_slug' => $author_slug,
                            'author_display_name' => $theme->getAuthorDisplayLabel( $author_slug ),
                            'author_url' => $theme->getAuthorURL( $author_slug ),
                            'listing_heading' => 'Search results',
                            'posts' => $results['rendered_items'],
                        ]
                    )
                );
                return;
            }

            \Flight::view()->render(
                $theme->getTemplate( 'home' ),
                array_merge(
                    $view_data,
                    [
                        'listing_context_title' => 'Search results',
                        'entries' => $results['rendered_items'],
                    ]
                )
            );
        }
    );
};
