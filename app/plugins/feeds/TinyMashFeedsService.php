<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

require_once dirname( __DIR__, 2 ) . '/classes/TinyMashDateFormatter.php';

class TinyMashFeedsService {

    protected TinyMashPlugins $plugins;
    protected string $plugin_key;
    protected TinyMashConfig $config;
    protected TinyMashSecurity $security;
    protected ?TinyMashSecretLinkService $secret_link_service;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashContentRenderer $content_renderer;
    protected TinyMashMarkdownRenderer $markdown_renderer;
    protected TinyMashTheme $theme;
    protected TinyMashUserRepository $user_repository;

    public function __construct(
        TinyMashPlugins $plugins,
        string $plugin_key,
        TinyMashConfig $config,
        TinyMashSecurity $security,
        ?TinyMashSecretLinkService $secret_link_service,
        TinyMashContentRepository $content_repository,
        TinyMashContentRenderer $content_renderer,
        TinyMashMarkdownRenderer $markdown_renderer,
        TinyMashTheme $theme,
        TinyMashUserRepository $user_repository
    ) {
        $this->plugins = $plugins;
        $this->plugin_key = $plugin_key;
        $this->config = $config;
        $this->security = $security;
        $this->secret_link_service = $secret_link_service;
        $this->content_repository = $content_repository;
        $this->content_renderer = $content_renderer;
        $this->markdown_renderer = $markdown_renderer;
        $this->theme = $theme;
        $this->user_repository = $user_repository;
    }

    public function getDiscoveryTags( array $context ) : array {
        $page_context = is_array( $context['page_context'] ?? null ) ? $context['page_context'] : [];
        $scope = (string) ( $page_context['scope'] ?? 'root' );
        $access_author_slug = $scope === 'author'
            ? $this->normalizeAuthorSlug( (string) ( $page_context['author_slug'] ?? '' ) )
            : null;
        if ( empty( $context['static_export'] ) && ! $this->siteIsAccessible( $access_author_slug ) ) {
            return( [] );
        }
        $site_name = mb_trim( (string) ( $context['site_name'] ?? 'Site' ) );
        $feed_base_path = '/feed';
        $feed_title = $site_name !== '' ? $site_name : 'Site';

        if ( $scope === 'author' ) {
            $author_slug = $this->normalizeAuthorSlug( (string) ( $page_context['author_slug'] ?? '' ) );
            if ( $author_slug === '' || ! $this->authorIsAccessible( $author_slug ) || ! $this->authorFeedsEnabled( $author_slug ) ) {
                return( [] );
            }

            $author_label = mb_trim( (string) ( $page_context['author_display_name'] ?? '' ) );
            $feed_title = $author_label !== '' ? $author_label : $author_slug;
            if ( $site_name !== '' && $feed_title !== $site_name ) {
                $feed_title .= ' - ' . $site_name;
            }
            $feed_base_path = '/' . rawurlencode( $author_slug ) . '/feed';
        } elseif ( ! $this->rootFeedsEnabled() ) {
            return( [] );
        }

        $available_paths = array_fill_keys(
            array_values( array_filter( (array) ( $context['static_export_artifacts'] ?? [] ), 'is_string' ) ),
            true
        );
        $tags = [];
        foreach ( [ 'rss' => 'application/rss+xml', 'atom' => 'application/atom+xml' ] as $format => $mime ) {
            $path = $feed_base_path . '.' . $format;
            if ( ! empty( $context['static_export'] ) && ! isset( $available_paths[$path] ) ) {
                continue;
            }
            $tags[] = [
                'type' => 'link',
                'rel' => 'alternate',
                'href' => $path,
                'mime' => $mime,
                'title' => $feed_title . ' ' . ( $format === 'rss' ? 'RSS' : 'Atom' ),
            ];
        }

        return( $tags );
    }

    public function renderFeed( string $format, ?string $author_slug = null, array $options = [] ) : ?string {
        $feed_context = $this->buildFeedContext( $author_slug, $options );
        if ( ! is_array( $feed_context ) ) {
            return( null );
        }

        return(
            match ( strtolower( trim( $format ) ) ) {
                'rss' => $this->renderRss( $feed_context ),
                'atom' => $this->renderAtom( $feed_context ),
                'json' => $this->renderJson( $feed_context ),
                default => null,
            }
        );
    }

    public function buildStaticArtifacts( array $context ) : array {
        $scope = strtolower( trim( (string) ( $context['scope'] ?? '' ) ) );
        $base_url = rtrim( trim( (string) ( $context['base_url'] ?? '' ) ), '/' );
        $author_slugs = [];

        if ( $scope === 'author' ) {
            $author_slug = $this->normalizeAuthorSlug( (string) ( $context['author_slug'] ?? '' ) );
            if ( $author_slug !== '' ) {
                $author_slugs[] = $author_slug;
            }
        } elseif ( $scope === 'site' ) {
            foreach ( (array) ( $context['included_authors'] ?? [] ) as $author_slug ) {
                $author_slug = $this->normalizeAuthorSlug( (string) $author_slug );
                if ( $author_slug !== '' && ! in_array( $author_slug, $author_slugs, true ) ) {
                    $author_slugs[] = $author_slug;
                }
            }
        } else {
            return( [] );
        }

        $subjects = $scope === 'site' ? [ null ] : [];
        foreach ( $author_slugs as $author_slug ) {
            $subjects[] = $author_slug;
        }

        $artifacts = [];
        foreach ( $subjects as $author_slug ) {
            $feed_context = $this->buildFeedContext(
                $author_slug,
                [
                    'static_export' => true,
                    'base_url' => $base_url,
                    'included_authors' => $author_slugs,
                ]
            );
            if ( ! is_array( $feed_context ) ) {
                continue;
            }

            $feed_base_path = (string) ( $feed_context['feed_base_path'] ?? '/feed' );
            foreach ( [ 'rss', 'atom', 'json' ] as $format ) {
                $artifacts[] = [
                    'path' => $feed_base_path . '.' . $format,
                    'contents' => match ( $format ) {
                        'rss' => $this->renderRss( $feed_context ),
                        'atom' => $this->renderAtom( $feed_context ),
                        default => $this->renderJson( $feed_context ),
                    },
                    'content_type' => match ( $format ) {
                        'rss' => 'application/rss+xml; charset=utf-8',
                        'atom' => 'application/atom+xml; charset=utf-8',
                        default => 'application/feed+json; charset=utf-8',
                    },
                ];
            }
        }

        return( $artifacts );
    }

    protected function buildFeedContext( ?string $author_slug, array $options ) : ?array {
        $static_export = ! empty( $options['static_export'] );
        $author_slug = $author_slug !== null ? $this->normalizeAuthorSlug( $author_slug ) : null;
        if ( ! $static_export && ! $this->siteIsAccessible( $author_slug ) ) {
            return( null );
        }

        $base_url = rtrim( trim( (string) ( $options['base_url'] ?? $this->config->configGetBaseURL() ?: '' ) ), '/' );
        $included_authors = null;
        if ( array_key_exists( 'included_authors', $options ) ) {
            $included_authors = [];
            foreach ( (array) $options['included_authors'] as $included_author ) {
                $included_author = $this->normalizeAuthorSlug( (string) $included_author );
                if ( $included_author !== '' ) {
                    $included_authors[$included_author] = true;
                }
            }
        }

        $entries = [];
        $site_name = $this->markdown_renderer->replaceEmojiShortcodes( (string) $this->config->getSiteName() );
        $feed_title = $site_name;
        $feed_description = $this->markdown_renderer->replaceEmojiShortcodes( (string) $this->config->getSiteSlogan() );
        $home_url = $this->buildAbsoluteUrl( '/', $base_url );
        $feed_base_path = '/feed';

        if ( $author_slug !== null ) {
            if ( $author_slug === '' || ! $this->authorIsAccessible( $author_slug ) || ! $this->authorFeedsEnabled( $author_slug ) ) {
                return( null );
            }

            $author_home = $this->content_repository->getAuthorHome( $author_slug, $this->config->getDefaultLanguage() );
            if ( ! is_array( $author_home ) ) {
                return( null );
            }

            $entries = is_array( $author_home['posts'] ?? null ) ? $author_home['posts'] : [];
            $feed_title = $author_slug . ' - ' . $site_name;
            $feed_description = 'Published posts by ' . $author_slug . '.';
            $home_url = $this->buildAbsoluteUrl( '/' . rawurlencode( $author_slug ), $base_url );
            $feed_base_path = '/' . rawurlencode( $author_slug ) . '/feed';
        } else {
            if ( ! $this->rootFeedsEnabled() ) {
                return( null );
            }

            foreach ( $this->content_repository->getPublishedAggregatedPosts( $this->config->getDefaultLanguage() ) as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                if ( (string) ( $entry['scope'] ?? '' ) === 'author' ) {
                    $entry_author_slug = $this->normalizeAuthorSlug( (string) ( $entry['author_slug'] ?? '' ) );
                    if ( $entry_author_slug === '' || ! $this->authorIsAccessible( $entry_author_slug ) ) {
                        continue;
                    }
                    if ( is_array( $included_authors ) && ! isset( $included_authors[$entry_author_slug] ) ) {
                        continue;
                    }
                }
                $entries[] = $entry;
            }
        }

        $feed_items = [];
        foreach ( array_slice( $entries, 0, $this->resolveFeedItemLimit() ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $rendered_entry = $this->content_renderer->renderEntry( $entry, $this->theme, 'feed' );
            $updated_at_utc = trim( (string) ( $entry['updated_at_utc'] ?? '' ) );
            $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
            $entry_url = $this->buildAbsoluteUrl( (string) ( $rendered_entry['url'] ?? '/' ), $base_url );
            $summary_html = $this->makeHtmlUrlsAbsolute( (string) ( $rendered_entry['summary_html'] ?? '' ), $base_url );
            $content_html = $this->makeHtmlUrlsAbsolute( (string) ( $rendered_entry['content_html'] ?? '' ), $base_url );

            $feed_items[] = [
                'id' => (string) ( $entry['id'] ?? '' ),
                'title' => $this->markdown_renderer->replaceEmojiShortcodes( mb_trim( (string) ( $rendered_entry['title'] ?? '' ) ) ),
                'url' => $entry_url,
                'summary_html' => $summary_html,
                'summary_text' => $this->stripText( $summary_html ),
                'content_html' => $content_html,
                'published_at_utc' => $published_at_utc,
                'updated_at_utc' => $updated_at_utc !== '' ? $updated_at_utc : $published_at_utc,
                'author_slug' => (string) ( $entry['author_slug'] ?? '' ),
            ];
        }

        $updated_at_utc = $this->resolveLatestItemUpdatedAt( $feed_items );

        return(
            [
                'title' => $feed_title,
                'description' => $feed_description,
                'home_url' => $home_url,
                'feed_base_path' => $feed_base_path,
                'base_url' => $base_url,
                'updated_at_utc' => $updated_at_utc,
                'items' => $feed_items,
            ]
        );
    }

    protected function renderRss( array $feed_context ) : string {
        $output = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0">',
            '<channel>',
            '<title>' . $this->escapeXml( (string) ( $feed_context['title'] ?? '' ) ) . '</title>',
            '<link>' . $this->escapeXml( (string) ( $feed_context['home_url'] ?? '' ) ) . '</link>',
            '<description>' . $this->escapeXml( (string) ( $feed_context['description'] ?? '' ) ) . '</description>',
            '<lastBuildDate>' . $this->escapeXml( $this->formatRfc822( (string) ( $feed_context['updated_at_utc'] ?? '' ) ) ) . '</lastBuildDate>',
            '<generator>tinymash</generator>',
            '<atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="' . $this->escapeXml( $this->buildAbsoluteUrl( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.rss', (string) ( $feed_context['base_url'] ?? '' ) ) ) . '" rel="self" type="application/rss+xml" />',
        ];

        foreach ( (array) ( $feed_context['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $summary_html = str_replace( ']]>', ']]]]><![CDATA[>', (string) ( $item['summary_html'] ?? '' ) );
            $output[] = '<item>';
            $output[] = '<title>' . $this->escapeXml( (string) ( $item['title'] ?? '' ) ) . '</title>';
            $output[] = '<link>' . $this->escapeXml( (string) ( $item['url'] ?? '' ) ) . '</link>';
            $output[] = '<guid isPermaLink="true">' . $this->escapeXml( (string) ( $item['url'] ?? '' ) ) . '</guid>';
            $output[] = '<pubDate>' . $this->escapeXml( $this->formatRfc822( (string) ( $item['published_at_utc'] ?? '' ) ) ) . '</pubDate>';
            $output[] = '<description><![CDATA[' . $summary_html . ']]></description>';
            $output[] = '</item>';
        }

        $output[] = '</channel>';
        $output[] = '</rss>';
        return( implode( "\n", $output ) . "\n" );
    }

    protected function renderAtom( array $feed_context ) : string {
        $output = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<feed xmlns="http://www.w3.org/2005/Atom">',
            '<title>' . $this->escapeXml( (string) ( $feed_context['title'] ?? '' ) ) . '</title>',
            '<id>' . $this->escapeXml( (string) ( $feed_context['home_url'] ?? '' ) ) . '</id>',
            '<updated>' . $this->escapeXml( $this->formatRfc3339( (string) ( $feed_context['updated_at_utc'] ?? '' ) ) ) . '</updated>',
            '<link rel="alternate" href="' . $this->escapeXml( (string) ( $feed_context['home_url'] ?? '' ) ) . '" />',
            '<link rel="self" type="application/atom+xml" href="' . $this->escapeXml( $this->buildAbsoluteUrl( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.atom', (string) ( $feed_context['base_url'] ?? '' ) ) ) . '" />',
            '<subtitle>' . $this->escapeXml( (string) ( $feed_context['description'] ?? '' ) ) . '</subtitle>',
        ];

        foreach ( (array) ( $feed_context['items'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $output[] = '<entry>';
            $output[] = '<title>' . $this->escapeXml( (string) ( $item['title'] ?? '' ) ) . '</title>';
            $output[] = '<id>' . $this->escapeXml( (string) ( $item['url'] ?? '' ) ) . '</id>';
            $output[] = '<link href="' . $this->escapeXml( (string) ( $item['url'] ?? '' ) ) . '" />';
            $output[] = '<updated>' . $this->escapeXml( $this->formatRfc3339( (string) ( $item['updated_at_utc'] ?? '' ) ) ) . '</updated>';
            $output[] = '<published>' . $this->escapeXml( $this->formatRfc3339( (string) ( $item['published_at_utc'] ?? '' ) ) ) . '</published>';
            $output[] = '<summary>' . $this->escapeXml( (string) ( $item['summary_text'] ?? '' ) ) . '</summary>';
            $output[] = '<content type="html">' . $this->escapeXml( (string) ( $item['content_html'] ?? '' ) ) . '</content>';
            if ( ! empty( $item['author_slug'] ) ) {
                $output[] = '<author><name>' . $this->escapeXml( (string) $item['author_slug'] ) . '</name></author>';
            }
            $output[] = '</entry>';
        }

        $output[] = '</feed>';
        return( implode( "\n", $output ) . "\n" );
    }

    protected function renderJson( array $feed_context ) : string {
        $items = [];
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
                'date_published' => $this->formatRfc3339( (string) ( $item['published_at_utc'] ?? '' ) ),
                'date_modified' => $this->formatRfc3339( (string) ( $item['updated_at_utc'] ?? '' ) ),
            ];
            if ( ! empty( $item['author_slug'] ) ) {
                $feed_item['authors'] = [ [ 'name' => (string) $item['author_slug'] ] ];
            }
            $items[] = $feed_item;
        }

        return(
            json_encode(
                [
                    'version' => 'https://jsonfeed.org/version/1.1',
                    'title' => (string) ( $feed_context['title'] ?? '' ),
                    'home_page_url' => (string) ( $feed_context['home_url'] ?? '' ),
                    'feed_url' => $this->buildAbsoluteUrl( (string) ( $feed_context['feed_base_path'] ?? '/feed' ) . '.json', (string) ( $feed_context['base_url'] ?? '' ) ),
                    'description' => (string) ( $feed_context['description'] ?? '' ),
                    'items' => $items,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n"
        );
    }

    protected function makeHtmlUrlsAbsolute( string $html, string $base_url ) : string {
        if ( trim( $html ) === '' || $base_url === '' ) {
            return( $html );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML( '<div id="tinymash-feed-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return( $html );
        }

        foreach ( [ 'a' => [ 'href' ], 'img' => [ 'src' ], 'source' => [ 'src' ], 'video' => [ 'src', 'poster' ], 'audio' => [ 'src' ] ] as $tag => $attributes ) {
            foreach ( iterator_to_array( $document->getElementsByTagName( $tag ) ) as $node ) {
                if ( ! $node instanceof DOMElement ) {
                    continue;
                }
                foreach ( $attributes as $attribute ) {
                    $url = trim( $node->getAttribute( $attribute ) );
                    if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
                        $node->setAttribute( $attribute, $this->buildAbsoluteUrl( $url, $base_url ) );
                    }
                }
            }
        }

        $root = $document->getElementById( 'tinymash-feed-root' );
        if ( ! $root instanceof DOMElement ) {
            return( $html );
        }
        $output = '';
        foreach ( iterator_to_array( $root->childNodes ) as $child ) {
            $output .= $document->saveHTML( $child );
        }
        return( $output );
    }

    protected function siteIsAccessible( ?string $author_slug = null ) : bool {
        if ( $this->config->isSitePublic() || $this->security->isLoggedIn() ) {
            return( true );
        }

        return(
            $this->secret_link_service instanceof TinyMashSecretLinkService
            && (
                $author_slug === null
                    ? $this->secret_link_service->currentVisitorCanAccessSite()
                    : $this->secret_link_service->currentVisitorCanAccessAuthor( $author_slug )
            )
        );
    }

    protected function authorIsAccessible( string $author_slug ) : bool {
        return( $author_slug !== '' && ! str_starts_with( $author_slug, '_deleted_' ) && $this->user_repository->isAuthorContentPublic( $author_slug ) );
    }

    protected function rootFeedsEnabled() : bool {
        $settings = $this->plugins->getPluginSystemSettings( $this->plugin_key );
        return( ! array_key_exists( 'root_feeds_enabled', $settings ) || ! empty( $settings['root_feeds_enabled'] ) );
    }

    protected function authorFeedsEnabled( string $author_slug ) : bool {
        $settings = $this->user_repository->getPluginSettings( $author_slug, $this->plugin_key );
        return( ! array_key_exists( 'author_feeds_enabled', $settings ) || ! empty( $settings['author_feeds_enabled'] ) );
    }

    protected function resolveFeedItemLimit() : int {
        $settings = $this->plugins->getPluginSystemSettings( $this->plugin_key );
        $limit = max( 1, (int) ( $settings['feed_item_limit'] ?? 20 ) );
        return( min( 100, $limit ) );
    }

    protected function buildAbsoluteUrl( string $path, string $base_url ) : string {
        return( rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' ) );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        return( strtolower( trim( $author_slug ) ) );
    }

    protected function escapeXml( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' ) );
    }

    protected function stripText( string $value ) : string {
        return( mb_trim( preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ?? '' ) );
    }

    protected function resolveLatestItemUpdatedAt( array $feed_items ) : string {
        $latest = null;
        foreach ( $feed_items as $feed_item ) {
            if ( ! is_array( $feed_item ) ) {
                continue;
            }
            $candidate = TinyMashDateFormatter::parseUtcDateTime( (string) ( $feed_item['updated_at_utc'] ?? '' ) );
            if ( $candidate instanceof \DateTimeImmutable && ( ! $latest instanceof \DateTimeImmutable || $candidate > $latest ) ) {
                $latest = $candidate;
            }
        }

        return( $latest instanceof \DateTimeImmutable ? $latest->format( 'Y-m-d\TH:i:s\Z' ) : gmdate( 'Y-m-d\TH:i:s\Z' ) );
    }

    protected function formatRfc822( string $utc_datetime ) : string {
        $date_time = TinyMashDateFormatter::parseUtcDateTime( $utc_datetime );
        return( $date_time instanceof \DateTimeImmutable ? $date_time->format( DATE_RSS ) : '' );
    }

    protected function formatRfc3339( string $utc_datetime ) : string {
        $date_time = TinyMashDateFormatter::parseUtcDateTime( $utc_datetime );
        return( $date_time instanceof \DateTimeImmutable ? $date_time->format( DATE_ATOM ) : '' );
    }
}
