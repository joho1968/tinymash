<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'feeds' );

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
        throw new \RuntimeException( 'Required services for the feed plugin are not available.' );
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

    $site_is_accessible = static function() use ( $config, $security ) : bool {
        return( $config->isSitePublic() || $security->isLoggedIn() );
    };

    $author_is_accessible = static function( string $author_slug ) use ( $user_repository ) : bool {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( false );
        }
        if ( str_starts_with( $author_slug, '_deleted_' ) ) {
            return( false );
        }

        return( $user_repository->isAuthorContentPublic( $author_slug ) );
    };

    $root_feeds_enabled = static function() use ( $plugins, $plugin_key ) : bool {
        $settings = $plugins->getPluginSystemSettings( $plugin_key );
        if ( array_key_exists( 'root_feeds_enabled', $settings ) ) {
            return( ! empty( $settings['root_feeds_enabled'] ) );
        }

        return( true );
    };

    $author_feeds_enabled = static function( string $author_slug ) use ( $plugin_key, $user_repository ) : bool {
        $settings = $user_repository->getPluginSettings( $author_slug, $plugin_key );
        if ( array_key_exists( 'author_feeds_enabled', $settings ) ) {
            return( ! empty( $settings['author_feeds_enabled'] ) );
        }

        return( true );
    };

    $resolve_feed_item_limit = static function() use ( $plugins, $plugin_key ) : int {
        $settings = $plugins->getPluginSystemSettings( $plugin_key );
        $limit = (int) ( $settings['feed_item_limit'] ?? 20 );
        if ( $limit <= 0 ) {
            $limit = 20;
        }

        return( min( 100, $limit ) );
    };

    $escape_xml = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' ) );
    };

    $strip_text = static function( string $value ) : string {
        $value = mb_trim( preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ?? '' );
        return( $value );
    };

    $format_rfc822 = static function( string $utc_datetime ) : string {
        if ( trim( $utc_datetime ) === '' ) {
            return( gmdate( DATE_RSS ) );
        }

        try {
            return( gmdate( DATE_RSS, strtotime( $utc_datetime ) ?: time() ) );
        } catch ( \Throwable $e ) {
            return( gmdate( DATE_RSS ) );
        }
    };

    $format_rfc3339 = static function( string $utc_datetime ) : string {
        if ( trim( $utc_datetime ) === '' ) {
            return( gmdate( DATE_ATOM ) );
        }

        try {
            return( gmdate( DATE_ATOM, strtotime( $utc_datetime ) ?: time() ) );
        } catch ( \Throwable $e ) {
            return( gmdate( DATE_ATOM ) );
        }
    };

    $build_absolute_url = static function( string $path ) use ( $config ) : string {
        $base_url = rtrim( (string) ( $config->configGetBaseURL() ?: '' ), '/' );
        $path = '/' . ltrim( $path, '/' );
        return( $base_url . $path );
    };

    $build_feed_context = static function( ?string $author_slug = null ) use (
        $config,
        $content_repository,
        $content_renderer,
        $theme,
        $site_is_accessible,
        $author_is_accessible,
        $author_feeds_enabled,
        $root_feeds_enabled,
        $resolve_feed_item_limit,
        $build_absolute_url,
        $strip_text
    ) : ?array {
        if ( ! $site_is_accessible() ) {
            return( null );
        }

        $entries = [];
        $feed_title = (string) $config->getSiteName();
        $feed_description = (string) $config->getSiteSlogan();
        $home_url = $build_absolute_url( '/' );
        $feed_base_path = '/feed';

        if ( $author_slug !== null ) {
            $author_slug = strtolower( trim( $author_slug ) );
            if ( $author_slug === '' || ! $author_is_accessible( $author_slug ) || ! $author_feeds_enabled( $author_slug ) ) {
                return( null );
            }

            $author_home = $content_repository->getAuthorHome( $author_slug, $config->getDefaultLanguage() );
            if ( ! is_array( $author_home ) ) {
                return( null );
            }

            $entries = is_array( $author_home['posts'] ?? null ) ? $author_home['posts'] : [];
            $feed_title = $author_slug . ' - ' . (string) $config->getSiteName();
            $feed_description = 'Published posts by ' . $author_slug . '.';
            $home_url = $build_absolute_url( '/' . rawurlencode( $author_slug ) );
            $feed_base_path = '/' . rawurlencode( $author_slug ) . '/feed';
        } else {
            if ( ! $root_feeds_enabled() ) {
                return( null );
            }

            foreach ( $content_repository->getPublishedAggregatedPosts( $config->getDefaultLanguage() ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                if ( (string) ( $entry['scope'] ?? '' ) === 'author' ) {
                    $entry_author_slug = (string) ( $entry['author_slug'] ?? '' );
                    if ( $entry_author_slug === '' || ! $author_is_accessible( $entry_author_slug ) ) {
                        continue;
                    }
                }
                $entries[] = $entry;
            }
        }

        $entries = array_slice( $entries, 0, $resolve_feed_item_limit() );
        $feed_items = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $rendered_entry = $content_renderer->renderEntry( $entry, $theme );
            $updated_at_utc = trim( (string) ( $entry['updated_at_utc'] ?? '' ) );
            $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
            $item_updated_at_utc = $updated_at_utc !== '' ? $updated_at_utc : $published_at_utc;

            $feed_items[] = [
                'id' => (string) ( $entry['id'] ?? '' ),
                'title' => trim( (string) ( $rendered_entry['title'] ?? '' ) ),
                'url' => $build_absolute_url( (string) ( $rendered_entry['url'] ?? '/' ) ),
                'summary_html' => (string) ( $rendered_entry['summary_html'] ?? '' ),
                'summary_text' => $strip_text( (string) ( $rendered_entry['summary_html'] ?? '' ) ),
                'content_html' => (string) ( $rendered_entry['content_html'] ?? '' ),
                'published_at_utc' => $published_at_utc,
                'updated_at_utc' => $item_updated_at_utc,
                'author_slug' => (string) ( $entry['author_slug'] ?? '' ),
            ];
        }

        $updated_at_utc = ! empty( $feed_items[0]['updated_at_utc'] ) ? (string) $feed_items[0]['updated_at_utc'] : gmdate( 'Y-m-d H:i:s' );

        return(
            [
                'title' => $feed_title,
                'description' => $feed_description,
                'home_url' => $home_url,
                'feed_base_path' => $feed_base_path,
                'updated_at_utc' => $updated_at_utc,
                'items' => $feed_items,
            ]
        );
    };

    $render_rss = static function( array $feed_context ) use ( $escape_xml, $format_rfc822, $build_absolute_url ) : string {
        $output = [];
        $output[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $output[] = '<rss version="2.0">';
        $output[] = '<channel>';
        $output[] = '<title>' . $escape_xml( (string) ( $feed_context['title'] ?? '' ) ) . '</title>';
        $output[] = '<link>' . $escape_xml( (string) ( $feed_context['home_url'] ?? '' ) ) . '</link>';
        $output[] = '<description>' . $escape_xml( (string) ( $feed_context['description'] ?? '' ) ) . '</description>';
        $output[] = '<lastBuildDate>' . $escape_xml( $format_rfc822( (string) ( $feed_context['updated_at_utc'] ?? '' ) ) ) . '</lastBuildDate>';
        $output[] = '<generator>tinymash</generator>';
        $output[] = '<atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="' . $escape_xml( $build_absolute_url( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.rss' ) ) . '" rel="self" type="application/rss+xml" />';

        foreach ( (array) ( $feed_context['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $output[] = '<item>';
            $output[] = '<title>' . $escape_xml( (string) ( $item['title'] ?? '' ) ) . '</title>';
            $output[] = '<link>' . $escape_xml( (string) ( $item['url'] ?? '' ) ) . '</link>';
            $output[] = '<guid isPermaLink="true">' . $escape_xml( (string) ( $item['url'] ?? '' ) ) . '</guid>';
            $output[] = '<pubDate>' . $escape_xml( $format_rfc822( (string) ( $item['published_at_utc'] ?? '' ) ) ) . '</pubDate>';
            $output[] = '<description><![CDATA[' . (string) ( $item['summary_html'] ?? '' ) . ']]></description>';
            $output[] = '</item>';
        }

        $output[] = '</channel>';
        $output[] = '</rss>';
        return( implode( "\n", $output ) . "\n" );
    };

    $render_atom = static function( array $feed_context ) use ( $escape_xml, $format_rfc3339, $build_absolute_url ) : string {
        $output = [];
        $output[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $output[] = '<feed xmlns="http://www.w3.org/2005/Atom">';
        $output[] = '<title>' . $escape_xml( (string) ( $feed_context['title'] ?? '' ) ) . '</title>';
        $output[] = '<id>' . $escape_xml( (string) ( $feed_context['home_url'] ?? '' ) ) . '</id>';
        $output[] = '<updated>' . $escape_xml( $format_rfc3339( (string) ( $feed_context['updated_at_utc'] ?? '' ) ) ) . '</updated>';
        $output[] = '<link rel="alternate" href="' . $escape_xml( (string) ( $feed_context['home_url'] ?? '' ) ) . '" />';
        $output[] = '<link rel="self" type="application/atom+xml" href="' . $escape_xml( $build_absolute_url( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.atom' ) ) . '" />';
        $output[] = '<subtitle>' . $escape_xml( (string) ( $feed_context['description'] ?? '' ) ) . '</subtitle>';

        foreach ( (array) ( $feed_context['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $output[] = '<entry>';
            $output[] = '<title>' . $escape_xml( (string) ( $item['title'] ?? '' ) ) . '</title>';
            $output[] = '<id>' . $escape_xml( (string) ( $item['url'] ?? '' ) ) . '</id>';
            $output[] = '<link href="' . $escape_xml( (string) ( $item['url'] ?? '' ) ) . '" />';
            $output[] = '<updated>' . $escape_xml( $format_rfc3339( (string) ( $item['updated_at_utc'] ?? '' ) ) ) . '</updated>';
            $output[] = '<published>' . $escape_xml( $format_rfc3339( (string) ( $item['published_at_utc'] ?? '' ) ) ) . '</published>';
            $output[] = '<summary>' . $escape_xml( (string) ( $item['summary_text'] ?? '' ) ) . '</summary>';
            $output[] = '<content type="html">' . $escape_xml( (string) ( $item['content_html'] ?? '' ) ) . '</content>';
            if ( ! empty( $item['author_slug'] ) ) {
                $output[] = '<author><name>' . $escape_xml( (string) $item['author_slug'] ) . '</name></author>';
            }
            $output[] = '</entry>';
        }

        $output[] = '</feed>';
        return( implode( "\n", $output ) . "\n" );
    };

    $render_json = static function( array $feed_context ) use ( $format_rfc3339, $build_absolute_url ) : string {
        $feed_items = [];
        foreach ( (array) ( $feed_context['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $feed_item = [
                'id' => (string) ( $item['url'] ?? $item['id'] ?? '' ),
                'url' => (string) ( $item['url'] ?? '' ),
                'title' => (string) ( $item['title'] ?? '' ),
                'summary' => (string) ( $item['summary_text'] ?? '' ),
                'content_html' => (string) ( $item['content_html'] ?? '' ),
                'date_published' => $format_rfc3339( (string) ( $item['published_at_utc'] ?? '' ) ),
                'date_modified' => $format_rfc3339( (string) ( $item['updated_at_utc'] ?? '' ) ),
            ];

            if ( ! empty( $item['author_slug'] ) ) {
                $feed_item['authors'] = [
                    [ 'name' => (string) $item['author_slug'] ],
                ];
            }

            $feed_items[] = $feed_item;
        }

        $document = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => (string) ( $feed_context['title'] ?? '' ),
            'home_page_url' => (string) ( $feed_context['home_url'] ?? '' ),
            'feed_url' => $build_absolute_url( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.json' ),
            'description' => (string) ( $feed_context['description'] ?? '' ),
            'items' => $feed_items,
        ];

        return( json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
    };

    $emit_feed = static function( string $format, ?string $author_slug = null ) use ( $build_feed_context, $render_rss, $render_atom, $render_json ) : void {
        $feed_context = $build_feed_context( $author_slug );
        if ( ! is_array( $feed_context ) ) {
            \Flight::app()->notFound();
            return;
        }

        if ( $format === 'rss' ) {
            header( 'Content-Type: application/rss+xml; charset=utf-8' );
            echo $render_rss( $feed_context );
            return;
        }

        if ( $format === 'atom' ) {
            header( 'Content-Type: application/atom+xml; charset=utf-8' );
            echo $render_atom( $feed_context );
            return;
        }

        header( 'Content-Type: application/feed+json; charset=utf-8' );
        echo $render_json( $feed_context );
    };

    $plugins->registerRoute( $plugin_key, 'get', '/feed.rss', static function() use ( $emit_feed ) : void {
        $emit_feed( 'rss', null );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/feed.atom', static function() use ( $emit_feed ) : void {
        $emit_feed( 'atom', null );
    } );
    $plugins->registerRoute( $plugin_key, 'get', '/feed.json', static function() use ( $emit_feed ) : void {
        $emit_feed( 'json', null );
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
