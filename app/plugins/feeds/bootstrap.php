<?php

require_once __DIR__ . '/TinyMashFeedsService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'feeds' );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $secret_link_service = $plugins->getService( 'secret_links' );
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
        $secret_link_service instanceof TinyMashSecretLinkService ? $secret_link_service : null,
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

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-feeds',
        [
            'title' => 'Feeds',
            'summary' => 'Provide RSS, Atom, and JSON Feed for the site and author spaces.',
            'group' => 'Plugins',
            'order' => 123,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'Overview',
                    'markdown' => 'Provide RSS 2.0, Atom 1.0, and JSON Feed for the site and enabled author spaces.',
                ],
                [
                    'title' => 'Addresses',
                    'markdown' => 'Site feeds use `/feed.rss`, `/feed.atom`, and `/feed.json`. Author feeds use `/<author>/feed.rss`, `/<author>/feed.atom`, and `/<author>/feed.json`.',
                ],
                [
                    'title' => 'Visibility',
                    'markdown' => 'Feed output follows site and author visibility. Non-public feeds require the same login or secret-link access as other content in that scope and are never shared-cacheable.',
                ],
            ],
        ]
    );

    $plugins->registerSystemSettingsSection(
        $plugin_key,
        [
            'title' => 'Site feeds',
            'summary' => 'Enable site feeds and set their item count.',
            'fields' => [
                [
                    'key' => 'root_feeds_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable site feeds',
                    'help' => 'Return 404 from `/feed.rss`, `/feed.atom`, and `/feed.json` when disabled.',
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
            'summary' => 'Enable feeds for your author space.',
            'fields' => [
                [
                    'key' => 'author_feeds_enabled',
                    'type' => 'checkbox',
                    'label' => 'Enable author feeds',
                    'help' => 'Return 404 from your author feed addresses when disabled.',
                    'default' => true,
                ],
            ],
        ]
    );

    $emit_feed = static function( string $format, ?string $author_slug = null ) use ( $service, $config ) : void {
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
        if ( ! $config->isSitePublic() ) {
            header( 'Vary: Cookie' );
            header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
            header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        }
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
