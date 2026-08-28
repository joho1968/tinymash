<?php

require_once __DIR__ . '/TinyMashSeoService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashRobotsService;
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
    $robots_service = $plugins->getService( 'robots.service' );

    if ( ! $config instanceof TinyMashConfig
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $robots_service instanceof TinyMashRobotsService
        || ! $theme instanceof TinyMashTheme
        || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the SEO plugin are not available.' );
    }

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'SEO',
            'summary' => 'Configure sitemap.xml and optional rules added to robots.txt.',
            'fields' => [
                [
                    'key' => 'robots_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable SEO rules in robots.txt',
                    'help' => 'Core exclusions remain active when disabled.',
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
            'summary' => 'Add sitemap, crawler rules, and entry metadata.',
            'documentation_url' => 'https://tinymash.joho.se/docs/plugins/seo',
            'documentation_label' => 'SEO',
            'group' => 'Plugins',
            'order' => 140,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Generate `/sitemap.xml`, add optional rules to core `/robots.txt`, and expose entry SEO metadata while respecting tinymash visibility and indexing settings.',
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
                    'markdown' => 'Add advisory `Disallow` blocks for common AI crawler user agents in `robots.txt`. These rules are requests, not technical enforcement.',
                ],
            ],
        ]
    );

    $seo_service = new TinyMashSeoService(
        $config,
        $content_repository,
        $robots_service,
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
