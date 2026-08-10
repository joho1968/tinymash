<?php

require_once __DIR__ . '/TinyMashFeedsService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'feeds' );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $content_repository = $plugins->getService( 'content.repository' );
    $content_renderer = $plugins->getService( 'content.renderer' );
    $markdown_renderer = $plugins->getService( 'markdown.renderer' );
    $theme = $plugins->getService( 'theme' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig
        || ! $security instanceof TinyMashSecurity
        || ! $content_repository instanceof TinyMashContentRepository
        || ! $content_renderer instanceof TinyMashContentRenderer
        || ! $markdown_renderer instanceof TinyMashMarkdownRenderer
        || ! $theme instanceof TinyMashTheme
        || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the feed plugin are not available.' );
    }

    $service = new TinyMashFeedsService(
        $plugins,
        $plugin_key,
        $config,
        $security,
        $content_repository,
        $content_renderer,
        $markdown_renderer,
        $theme,
        $user_repository
    );
    \Flight::set( 'plugin.feeds.service', $service );

    $plugins->registerStaticExportContributor(
        $plugin_key,
        static fn( array $context ) : array => $service->buildStaticArtifacts( $context ),
        'both',
        'Public feeds'
    );

    $plugins->registerPublicHeadTagRenderer(
        $plugin_key,
        static fn( array $context ) : array => $service->getDiscoveryTags( $context )
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Frontpage feeds',
            'summary' => 'Controls whether the site-level frontpage feeds are exposed and how many items they return.',
            'fields' => [
                [
                    'key' => 'root_feeds_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable frontpage feeds',
                    'help' => 'When disabled, `/feed.rss`, `/feed.atom`, and `/feed.json` return 404.',
                    'default' => true,
                ],
                [
                    'key' => 'feed_item_limit',
                    'type' => 'select',
                    'label' => 'Feed item limit',
                    'help' => 'Applies to RSS 2.0, Atom 1.0, and JSON Feed.',
                    'default' => '20',
                    'options' => [
                        [ 'value' => '10', 'label' => '10 items' ],
                        [ 'value' => '20', 'label' => '20 items' ],
                        [ 'value' => '50', 'label' => '50 items' ],
                        [ 'value' => '100', 'label' => '100 items' ],
                    ],
                ],
            ],
        ]
    );

    $plugins->registerProfileSettingsSection(
        $plugin_key,
        'publishing',
        [
            'title' => 'Author feeds',
            'summary' => 'Controls whether your public author feeds are exposed.',
            'fields' => [
                [
                    'key' => 'author_feeds_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable my public feeds',
                    'help' => 'When disabled, your `/username/feed.rss`, `/feed.atom`, and `/feed.json` endpoints return 404.',
                    'default' => true,
                ],
            ],
        ]
    );

    $emit_feed = static function( string $format, ?string $author_slug = null ) use ( $service ) : void {
        $feed = $service->renderFeed( $format, $author_slug );
        if ( ! is_string( $feed ) ) {
            \Flight::app()->notFound();
            return;
        }

        header(
            match ( $format ) {
                'rss' => 'Content-Type: application/rss+xml; charset=utf-8',
                'atom' => 'Content-Type: application/atom+xml; charset=utf-8',
                default => 'Content-Type: application/feed+json; charset=utf-8',
            }
        );
        echo $feed;
    };

    $plugins->registerRoute( $plugin_key, 'get', '/feed.rss', static function() use ( $emit_feed ) : void {
        $emit_feed( 'rss' );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/feed.atom', static function() use ( $emit_feed ) : void {
        $emit_feed( 'atom' );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/feed.json', static function() use ( $emit_feed ) : void {
        $emit_feed( 'json' );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/@author_slug/feed.rss', static function( string $author_slug ) use ( $emit_feed ) : void {
        $emit_feed( 'rss', $author_slug );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/@author_slug/feed.atom', static function( string $author_slug ) use ( $emit_feed ) : void {
        $emit_feed( 'atom', $author_slug );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/@author_slug/feed.json', static function( string $author_slug ) use ( $emit_feed ) : void {
        $emit_feed( 'json', $author_slug );
    } );
};
