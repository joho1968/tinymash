<?php

require_once __DIR__ . '/TinyMashSeoService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'seo' );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $config = $plugins->getService( 'config' );
    $content_repository = $plugins->getService( 'content.repository' );
    $theme = $plugins->getService( 'theme' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $theme instanceof TinyMashTheme
        || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the SEO plugin are not available.' );
    }

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'SEO',
            'summary' => 'Generated search-engine output for robots.txt, sitemap.xml, and AI crawler advisory rules.',
            'fields' => [
                [
                    'key' => 'robots_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable generated robots.txt',
                    'help' => 'When disabled, `/robots.txt` returns 404.',
                    'default' => 1,
                ],
                [
                    'key' => 'sitemap_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable generated sitemap.xml',
                    'help' => 'When disabled, `/sitemap.xml` returns 404.',
                    'default' => 1,
                ],
                [
                    'key' => 'disallow_ai_crawlers',
                    'type' => 'checkbox',
                    'label' => 'Advisory: disallow common AI crawlers',
                    'help' => 'Adds explicit `Disallow: /` blocks for common AI crawler user agents in `robots.txt`. This is advisory, not enforcement.',
                    'default' => 0,
                ],
            ],
        ]
    );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-seo',
        [
            'title' => 'SEO',
            'summary' => 'Generated robots.txt and sitemap.xml output, plus AI crawler advisory rules.',
            'group' => 'Plugins',
            'order' => 140,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'The SEO plugin exposes generated `/robots.txt` and `/sitemap.xml` output, computes entry-level SEO metadata, and respects tinymash visibility rules together with the built-in search-index discouragement settings.',
                ],
                [
                    'title' => 'Social image order',
                    'markdown' => "For Open Graph and Twitter cards, tinymash uses this image order:\n\n1. the entry's **Social image** override\n2. the entry's **Featured image**\n3. the site-wide default OG image\n\nIf no social image is chosen, the normal featured-image/site fallback still applies.",
                ],
                [
                    'title' => 'Entry SEO fields',
                    'markdown' => 'When the plugin is active, the editor gets a dedicated `SEO` tab for SEO title, SEO description, social title, social description, social image, canonical URL, robots directive, and sitemap exclusion.',
                ],
                [
                    'title' => 'AI crawler advisory',
                    'markdown' => 'The plugin can add advisory `Disallow` blocks for common AI crawler user agents in `robots.txt`. This is a request, not technical enforcement.',
                ],
            ],
        ]
    );

    $seo_service = new TinyMashSeoService(
        $config,
        $content_repository,
        $theme,
        $user_repository,
        $plugins->getPluginSystemSettings( $plugin_key )
    );
    \Flight::set( 'public.seo', $seo_service->getPublicSeoSettings() );
    \Flight::set( 'public.seo.service', $seo_service );

    $render_not_found = static function() : void {
        \Flight::response()->status( 404 );
        \Flight::response()->header( 'Content-Type', 'text/plain; charset=UTF-8' );
        echo "Not found\n";
    };

    $set_cache_headers = static function() : void {
        \Flight::response()->header( 'Cache-Control', 'public, max-age=300, s-maxage=300' );
    };

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/robots.txt',
        static function() use ( $seo_service, $render_not_found, $set_cache_headers ) : void {
            if ( ! $seo_service->robotsEnabled() ) {
                $render_not_found();
                return;
            }

            \Flight::response()->header( 'Content-Type', 'text/plain; charset=UTF-8' );
            $set_cache_headers();
            echo $seo_service->buildRobotsTxt();
        }
    );

    $plugins->registerRoute(
        $plugin_key,
        'get',
        '/sitemap.xml',
        static function() use ( $seo_service, $render_not_found, $set_cache_headers ) : void {
            if ( ! $seo_service->sitemapEnabled() ) {
                $render_not_found();
                return;
            }

            \Flight::response()->header( 'Content-Type', 'application/xml; charset=UTF-8' );
            $set_cache_headers();
            echo $seo_service->buildSitemapXml();
        }
    );
};
