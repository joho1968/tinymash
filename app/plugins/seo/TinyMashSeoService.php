<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

class TinyMashSeoService {

    protected const AI_CRAWLER_USER_AGENTS = [
        'GPTBot',
        'ChatGPT-User',
        'Google-Extended',
        'ClaudeBot',
        'anthropic-ai',
        'PerplexityBot',
        'Bytespider',
        'CCBot',
        'Applebot-Extended',
    ];

    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashTheme $theme;
    protected TinyMashUserRepository $user_repository;
    protected array $settings;

    public function __construct(
        TinyMashConfig $config,
        TinyMashContentRepository $content_repository,
        TinyMashTheme $theme,
        TinyMashUserRepository $user_repository,
        array $settings = []
    ) {
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->theme = $theme;
        $this->user_repository = $user_repository;
        $this->settings = $this->normalizeSettings( $settings );
    }

    public function getPublicSeoSettings() : array {
        return(
            [
                'enabled' => $this->settings['robots_enabled'] || $this->settings['sitemap_enabled'],
                'robots_enabled' => $this->settings['robots_enabled'],
                'sitemap_enabled' => $this->settings['sitemap_enabled'],
                'disallow_ai_crawlers' => $this->settings['disallow_ai_crawlers'],
                'robots_url' => $this->settings['robots_enabled'] ? '/robots.txt' : '',
                'sitemap_url' => $this->settings['sitemap_enabled'] ? '/sitemap.xml' : '',
            ]
        );
    }

    public function resolvePageSeoData( array $base_meta, ?array $entry = null, array $page_context = [] ) : array {
        $meta_url = trim( (string) ( $base_meta['meta_url'] ?? '' ) );
        $document_title = trim( (string) ( $base_meta['document_title'] ?? '' ) );
        $meta_title = trim( (string) ( $base_meta['meta_title'] ?? '' ) );
        if ( $document_title === '' ) {
            $document_title = $meta_title;
        }
        $meta_description = trim( (string) ( $base_meta['meta_description'] ?? '' ) );
        $meta_image = is_array( $base_meta['meta_image'] ?? null ) ? $base_meta['meta_image'] : [];
        $social_title = '';
        $social_description = $meta_description;
        $social_image = $meta_image;
        $robots = trim( (string) ( $base_meta['theme_meta_robots'] ?? '' ) );
        $meta_type = trim( (string) ( $base_meta['meta_type'] ?? ( is_array( $entry ) ? 'article' : 'website' ) ) );
        $scope = strtolower( trim( (string) ( $page_context['scope'] ?? '' ) ) );
        $author_slug = strtolower( trim( (string) ( $page_context['author_slug'] ?? '' ) ) );

        if ( is_array( $entry ) ) {
            $seo_title = trim( (string) ( $entry['seo_title'] ?? '' ) );
            if ( $seo_title !== '' ) {
                $document_title = $seo_title;
                $meta_title = $seo_title;
            }

            $seo_description = $this->normalizePlainText( (string) ( $entry['seo_description'] ?? '' ) );
            if ( $seo_description !== '' ) {
                $meta_description = $this->limitText( $seo_description, 180 );
            }

            $seo_canonical_url = trim( (string) ( $entry['seo_canonical_url'] ?? '' ) );
            if ( $seo_canonical_url !== '' ) {
                $meta_url = $seo_canonical_url;
            }

            $robots = $this->mergeRobotsDirectives( $robots, (string) ( $entry['seo_robots'] ?? '' ) );
            $resolved_description = $this->buildEntryMetaDescription( $entry );
            if ( $meta_description === '' && $resolved_description !== '' ) {
                $meta_description = $resolved_description;
            }

            $seo_social_title = $this->normalizePlainText( (string) ( $entry['seo_social_title'] ?? '' ) );
            if ( $seo_social_title !== '' ) {
                $social_title = $this->limitText( $seo_social_title, 120 );
            }

            $seo_social_description = $this->normalizePlainText( (string) ( $entry['seo_social_description'] ?? '' ) );
            if ( $seo_social_description !== '' ) {
                $social_description = $this->limitText( $seo_social_description, 200 );
            }

            $seo_social_image = is_array( $entry['seo_social_image'] ?? null ) ? $entry['seo_social_image'] : [];
            if ( ! empty( $seo_social_image['url'] ) ) {
                $social_image = $seo_social_image;
            }
        }

        if ( $social_title === '' ) {
            $social_title = $meta_title;
        }
        if ( $social_description === '' ) {
            $social_description = $meta_description;
        }

        $include_in_sitemap = $this->settings['sitemap_enabled']
            && $this->config->isSitePublic()
            && stripos( $robots, 'noindex' ) === false
            && $this->isCanonicalUrlLocal( $meta_url );

        if ( $scope === 'author' && $author_slug !== '' && $this->shouldExcludeAuthorFromIndex( $author_slug ) ) {
            $include_in_sitemap = false;
        }
        if ( is_array( $entry ) && ! empty( $entry['seo_exclude_from_sitemap'] ) ) {
            $include_in_sitemap = false;
        }

        $entry_seo = [];
        if ( is_array( $entry ) ) {
            $entry_seo = [
                'id' => (string) ( $entry['id'] ?? '' ),
                'type' => (string) ( $entry['type'] ?? 'post' ),
                'canonical_url' => $meta_url,
                'robots' => $robots,
                'include_in_sitemap' => $include_in_sitemap,
                'og_type' => $meta_type,
                'social_title' => $social_title,
                'social_description' => $social_description,
                'social_image' => $social_image,
                'published_at_utc' => trim( (string) ( $entry['published_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $entry['updated_at_utc'] ?? '' ) ),
                'json_ld' => $this->buildEntryJsonLd( $entry, $meta_url, $meta_title, $meta_description, $meta_image ),
            ];
        }

        return(
            [
                'enabled' => true,
                'robots_enabled' => $this->settings['robots_enabled'],
                'sitemap_enabled' => $this->settings['sitemap_enabled'],
                'disallow_ai_crawlers' => $this->settings['disallow_ai_crawlers'],
                'robots_url' => $this->settings['robots_enabled'] ? '/robots.txt' : '',
                'sitemap_url' => $this->settings['sitemap_enabled'] ? '/sitemap.xml' : '',
                'canonical_url' => $meta_url,
                'robots' => $robots,
                'document_title' => $document_title,
                'meta_title' => $meta_title,
                'meta_description' => $meta_description,
                'meta_image' => $meta_image,
                'social_title' => $social_title,
                'social_description' => $social_description,
                'social_image' => $social_image,
                'meta_type' => $meta_type,
                'og_type' => $meta_type,
                'site_name' => (string) $this->config->getSiteName(),
                'include_in_sitemap' => $include_in_sitemap,
                'json_ld' => is_array( $entry_seo['json_ld'] ?? null ) ? $entry_seo['json_ld'] : $this->buildPageJsonLd( $meta_url, $meta_title, $meta_description, $meta_type ),
                'entry' => $entry_seo,
            ]
        );
    }

    public function robotsEnabled() : bool {
        return( $this->settings['robots_enabled'] );
    }

    public function sitemapEnabled() : bool {
        return( $this->settings['sitemap_enabled'] );
    }

    public function buildRobotsTxt() : string {
        $lines = [];

        if ( ! $this->config->isSitePublic() || $this->config->discouragesSearchIndexing() ) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
            return( implode( "\n", $lines ) . "\n" );
        }

        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';

        foreach ( $this->getDiscouragedAuthorSlugs() as $author_slug ) {
            $lines[] = 'Disallow: /' . $author_slug;
            $lines[] = 'Disallow: /' . $author_slug . '/';
        }

        if ( $this->settings['sitemap_enabled'] ) {
            $lines[] = 'Sitemap: ' . $this->buildAbsoluteUrl( '/sitemap.xml' );
        }

        if ( $this->settings['disallow_ai_crawlers'] ) {
            foreach ( self::AI_CRAWLER_USER_AGENTS as $user_agent ) {
                $lines[] = '';
                $lines[] = 'User-agent: ' . $user_agent;
                $lines[] = 'Disallow: /';
            }
        }

        return( implode( "\n", $lines ) . "\n" );
    }

    public function buildSitemapXml() : string {
        $urls = $this->collectSitemapUrls();

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ( $urls as $url ) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $this->escapeXml( (string) ( $url['loc'] ?? '' ) ) . '</loc>';
            if ( ! empty( $url['lastmod'] ) ) {
                $xml[] = '    <lastmod>' . $this->escapeXml( (string) $url['lastmod'] ) . '</lastmod>';
            }
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';
        return( implode( "\n", $xml ) . "\n" );
    }

    protected function normalizeSettings( array $settings ) : array {
        return(
            [
                'robots_enabled' => ! array_key_exists( 'robots_enabled', $settings ) || ! empty( $settings['robots_enabled'] ),
                'sitemap_enabled' => ! array_key_exists( 'sitemap_enabled', $settings ) || ! empty( $settings['sitemap_enabled'] ),
                'disallow_ai_crawlers' => ! empty( $settings['disallow_ai_crawlers'] ),
            ]
        );
    }

    protected function collectSitemapUrls() : array {
        if ( ! $this->config->isSitePublic() || $this->config->discouragesSearchIndexing() ) {
            return( [] );
        }

        $urls = [];
        $register_url = function( string $path_or_url, string $lastmod = '' ) use ( &$urls ) : void {
            $path_or_url = trim( $path_or_url );
            if ( $path_or_url === '' ) {
                return;
            }

            $absolute_url = $this->isAbsoluteHttpUrl( $path_or_url )
                ? $path_or_url
                : $this->buildAbsoluteUrl( '/' . ltrim( $path_or_url, '/' ) );
            if ( isset( $urls[$absolute_url] ) ) {
                if ( $lastmod !== '' && empty( $urls[$absolute_url]['lastmod'] ) ) {
                    $urls[$absolute_url]['lastmod'] = $lastmod;
                }
                return;
            }

            $urls[$absolute_url] = [
                'loc' => $absolute_url,
                'lastmod' => $lastmod,
            ];
        };

        $register_url( '/' );

        foreach ( $this->content_repository->getPublishedAggregatedPosts( $this->config->getDefaultLanguage() ) as $entry ) {
            if ( ! is_array( $entry ) || (string) ( $entry['scope'] ?? 'root' ) !== 'root' ) {
                continue;
            }
            $sitemap_entry = $this->buildEntrySitemapData( $entry );
            if ( ! $sitemap_entry['include'] ) {
                continue;
            }
            $register_url( $sitemap_entry['loc'], $sitemap_entry['lastmod'] );
        }

        foreach ( $this->flattenPageTree( $this->content_repository->getRootPublishedPageTree( $this->config->getDefaultLanguage() ) ) as $entry ) {
            $sitemap_entry = $this->buildEntrySitemapData( $entry );
            if ( ! $sitemap_entry['include'] ) {
                continue;
            }
            $register_url( $sitemap_entry['loc'], $sitemap_entry['lastmod'] );
        }

        foreach ( $this->content_repository->getPublicAuthorSlugs() as $author_slug ) {
            if ( $this->shouldExcludeAuthorFromIndex( $author_slug ) ) {
                continue;
            }

            $register_url( $this->theme->getAuthorURL( $author_slug ) );
            $author_home = $this->content_repository->getAuthorHome( $author_slug, $this->config->getDefaultLanguage() );
            if ( ! is_array( $author_home ) ) {
                continue;
            }

            foreach ( is_array( $author_home['posts'] ?? null ) ? $author_home['posts'] : [] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $sitemap_entry = $this->buildEntrySitemapData( $entry );
                if ( $sitemap_entry['include'] ) {
                    $register_url( $sitemap_entry['loc'], $sitemap_entry['lastmod'] );
                }
            }

            foreach ( is_array( $author_home['pages'] ?? null ) ? $author_home['pages'] : [] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $sitemap_entry = $this->buildEntrySitemapData( $entry );
                if ( $sitemap_entry['include'] ) {
                    $register_url( $sitemap_entry['loc'], $sitemap_entry['lastmod'] );
                }
            }
        }

        return( array_values( $urls ) );
    }

    protected function buildEntryMetaDescription( array $entry ) : string {
        $summary = $this->normalizePlainText( (string) ( $entry['summary'] ?? '' ) );
        if ( $summary !== '' ) {
            return( $this->limitText( $summary, 180 ) );
        }

        $content = $this->normalizePlainText( (string) ( $entry['content'] ?? '' ) );
        if ( $content === '' ) {
            return( '' );
        }

        return( $this->limitText( $content, 180 ) );
    }

    protected function shouldIncludeEntryInSitemap( array $entry ) : bool {
        if ( ! empty( $entry['seo_exclude_from_sitemap'] ) ) {
            return( false );
        }

        $canonical_url = trim( (string) ( $entry['seo_canonical_url'] ?? '' ) );
        if ( $canonical_url !== '' && ! $this->isCanonicalUrlLocal( $canonical_url ) ) {
            return( false );
        }

        $robots = $this->mergeRobotsDirectives( '', (string) ( $entry['seo_robots'] ?? '' ) );
        return( stripos( $robots, 'noindex' ) === false );
    }

    protected function buildEntrySitemapData( array $entry ) : array {
        $canonical_url = trim( (string) ( $entry['seo_canonical_url'] ?? '' ) );
        $loc = $canonical_url !== '' ? $canonical_url : $this->buildAbsoluteUrl( $this->theme->getEntryURL( $entry ) );

        return(
            [
                'include' => $this->shouldIncludeEntryInSitemap( $entry ),
                'loc' => $loc,
                'lastmod' => $this->resolveLastModified( $entry ),
            ]
        );
    }

    protected function flattenPageTree( array $nodes ) : array {
        $entries = [];

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $entries[] = $node;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                foreach ( $this->flattenPageTree( $node['children'] ) as $child_entry ) {
                    $entries[] = $child_entry;
                }
            }
        }

        return( $entries );
    }

    protected function shouldExcludeAuthorFromIndex( string $author_slug ) : bool {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' || ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
            return( true );
        }

        return( $this->user_repository->discouragesSearchIndexing( $author_slug ) );
    }

    protected function getDiscouragedAuthorSlugs() : array {
        $discouraged = [];
        foreach ( $this->content_repository->getPublicAuthorSlugs() as $author_slug ) {
            if ( $this->shouldExcludeAuthorFromIndex( $author_slug ) ) {
                $discouraged[] = $author_slug;
            }
        }

        sort( $discouraged, SORT_STRING );
        return( array_values( array_unique( $discouraged ) ) );
    }

    protected function resolveLastModified( array $entry ) : string {
        $value = trim( (string) ( $entry['updated_at_utc'] ?? $entry['published_at_utc'] ?? '' ) );
        if ( $value === '' ) {
            return( '' );
        }

        try {
            $timestamp = strtotime( $value );
            if ( $timestamp === false ) {
                return( '' );
            }
            return( gmdate( 'c', $timestamp ) );
        } catch ( \Throwable $e ) {
            return( '' );
        }
    }

    protected function mergeRobotsDirectives( string $base, string $override ) : string {
        $base = strtolower( trim( $base ) );
        $override = strtolower( trim( $override ) );
        if ( $base === '' && $override === '' ) {
            return( '' );
        }

        $base_noindex = str_contains( $base, 'noindex' );
        $base_nofollow = str_contains( $base, 'nofollow' );
        $override_noindex = str_contains( $override, 'noindex' );
        $override_nofollow = str_contains( $override, 'nofollow' );

        return( ( $base_noindex || $override_noindex ? 'noindex' : 'index' ) . ', ' . ( $base_nofollow || $override_nofollow ? 'nofollow' : 'follow' ) );
    }

    protected function isAbsoluteHttpUrl( string $value ) : bool {
        if ( $value === '' ) {
            return( false );
        }

        $scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
        return( in_array( $scheme, [ 'http', 'https' ], true ) );
    }

    protected function isCanonicalUrlLocal( string $canonical_url ) : bool {
        if ( ! $this->isAbsoluteHttpUrl( $canonical_url ) ) {
            return( false );
        }

        $base_url = rtrim( (string) ( $this->config->configGetBaseURL() ?: '' ), '/' );
        if ( ! $this->isAbsoluteHttpUrl( $base_url ) ) {
            return( false );
        }

        $canonical_host = strtolower( (string) parse_url( $canonical_url, PHP_URL_HOST ) );
        $base_host = strtolower( (string) parse_url( $base_url, PHP_URL_HOST ) );
        if ( $canonical_host === '' || $base_host === '' || $canonical_host !== $base_host ) {
            return( false );
        }

        $canonical_scheme = strtolower( (string) parse_url( $canonical_url, PHP_URL_SCHEME ) );
        $base_scheme = strtolower( (string) parse_url( $base_url, PHP_URL_SCHEME ) );
        return( $canonical_scheme !== '' && $canonical_scheme === $base_scheme );
    }

    protected function buildEntryJsonLd( array $entry, string $canonical_url, string $meta_title, string $meta_description, array $meta_image ) : array {
        $base_url = $this->getBaseUrl();
        $website_id = $base_url !== '' ? $base_url . '#website' : '#website';
        $organization_id = $base_url !== '' ? $base_url . '#organization' : '#organization';
        $entry_id = $canonical_url !== '' ? $canonical_url . '#primary' : '#primary';

        $json_ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                $this->buildWebsiteJsonLd(),
                $this->buildOrganizationJsonLd(),
            ],
        ];

        $entry_node = [
            '@id' => $entry_id,
            '@type' => (string) ( ( $entry['type'] ?? 'post' ) === 'post' ? 'BlogPosting' : 'WebPage' ),
            'headline' => $meta_title,
            'description' => $meta_description,
            'url' => $canonical_url,
            'isPartOf' => [ '@id' => $website_id ],
        ];

        $published_at_utc = trim( (string) ( $entry['published_at_utc'] ?? '' ) );
        if ( $published_at_utc !== '' ) {
            $entry_node['datePublished'] = $published_at_utc;
        }

        $updated_at_utc = trim( (string) ( $entry['updated_at_utc'] ?? '' ) );
        if ( $updated_at_utc !== '' ) {
            $entry_node['dateModified'] = $updated_at_utc;
        }

        if ( ! empty( $meta_image['url'] ) ) {
            $entry_node['image'] = [ (string) $meta_image['url'] ];
        }

        if ( (string) ( $entry['scope'] ?? '' ) === 'author' ) {
            $author_slug = strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) );
            if ( $author_slug !== '' ) {
                $entry_node['author'] = [
                    '@type' => 'Person',
                    'name' => $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug ),
                ];
            }
        } else {
            $entry_node['author'] = [
                '@type' => 'Organization',
                'name' => (string) $this->config->getSiteName(),
            ];
        }

        if ( ( $entry['type'] ?? 'post' ) === 'post' ) {
            $entry_node['publisher'] = [ '@id' => $organization_id ];
        }

        if ( $canonical_url !== '' ) {
            $entry_node['mainEntityOfPage'] = [
                '@type' => 'WebPage',
                '@id' => $canonical_url,
            ];
        }

        $breadcrumbs = $this->theme->getEntryBreadcrumbs( $entry );
        $breadcrumb_node = $this->buildBreadcrumbJsonLd( $breadcrumbs, $canonical_url );
        if ( is_array( $breadcrumb_node ) ) {
            $json_ld['@graph'][] = $breadcrumb_node;
            $entry_node['breadcrumb'] = [ '@id' => (string) $breadcrumb_node['@id'] ];
        }

        $json_ld['@graph'][] = $entry_node;
        return( $json_ld );
    }

    protected function buildPageJsonLd( string $canonical_url, string $meta_title, string $meta_description, string $meta_type ) : array {
        $base_url = $this->getBaseUrl();
        $website_id = $base_url !== '' ? $base_url . '#website' : '#website';
        $page_id = $canonical_url !== '' ? $canonical_url . '#page' : '#page';

        return(
            [
                '@context' => 'https://schema.org',
                '@graph' => [
                    $this->buildWebsiteJsonLd(),
                    [
                        '@id' => $page_id,
                        '@type' => $meta_type === 'website' ? 'WebPage' : 'WebPage',
                        'name' => $meta_title,
                        'description' => $meta_description,
                        'url' => $canonical_url,
                        'isPartOf' => [ '@id' => $website_id ],
                    ],
                ],
            ]
        );
    }

    protected function buildWebsiteJsonLd() : array {
        $base_url = $this->getBaseUrl();
        $website = [
            '@id' => ( $base_url !== '' ? $base_url : '' ) . '#website',
            '@type' => 'WebSite',
            'name' => (string) $this->config->getSiteName(),
            'url' => $base_url,
        ];

        $site_slogan = trim( (string) $this->config->getSiteSlogan() );
        if ( $site_slogan !== '' ) {
            $website['description'] = $site_slogan;
        }

        $search_data = \Flight::has( 'public.search' ) ? \Flight::get( 'public.search' ) : [];
        if ( is_array( $search_data ) && ! empty( $search_data['enabled'] ) && ! empty( $search_data['url'] ) ) {
            $target_url = $this->buildAbsoluteUrl( (string) $search_data['url'] );
            $separator = str_contains( $target_url, '?' ) ? '&' : '?';
            $website['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => $target_url . $separator . 'q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ];
        }

        return( $website );
    }

    protected function buildOrganizationJsonLd() : array {
        $base_url = $this->getBaseUrl();
        return(
            [
                '@id' => ( $base_url !== '' ? $base_url : '' ) . '#organization',
                '@type' => 'Organization',
                'name' => (string) $this->config->getSiteName(),
                'url' => $base_url,
            ]
        );
    }

    protected function buildBreadcrumbJsonLd( array $breadcrumbs, string $canonical_url ) : ?array {
        $items = [];
        foreach ( $breadcrumbs as $index => $breadcrumb ) {
            if ( ! is_array( $breadcrumb ) ) {
                continue;
            }

            $title = trim( (string) ( $breadcrumb['title'] ?? '' ) );
            if ( $title === '' ) {
                continue;
            }

            $item_url = trim( (string) ( $breadcrumb['url'] ?? '' ) );
            if ( $item_url !== '' && ! $this->isAbsoluteHttpUrl( $item_url ) ) {
                $item_url = $this->buildAbsoluteUrl( $item_url );
            }

            $list_item = [
                '@type' => 'ListItem',
                'position' => count( $items ) + 1,
                'name' => $title,
            ];
            if ( $item_url !== '' ) {
                $list_item['item'] = $item_url;
            }

            $items[] = $list_item;
        }

        if ( $items === [] ) {
            return( null );
        }

        return(
            [
                '@id' => ( $canonical_url !== '' ? $canonical_url : '#page' ) . '#breadcrumb',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $items,
            ]
        );
    }

    protected function buildAbsoluteUrl( string $path ) : string {
        $base_url = $this->getBaseUrl();
        return( $base_url . '/' . ltrim( $path, '/' ) );
    }

    protected function getBaseUrl() : string {
        return( rtrim( (string) ( $this->config->configGetBaseURL() ?: '' ), '/' ) );
    }

    protected function escapeXml( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' ) );
    }

    protected function normalizePlainText( string $value ) : string {
        $value = preg_replace( '/```.*?```/su', ' ', $value ) ?? $value;
        $value = preg_replace( '/`[^`]*`/u', ' ', $value ) ?? $value;
        $value = preg_replace( '/\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)/u', ' ', $value ) ?? $value;
        $value = preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', ' ', $value ) ?? $value;
        $value = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $value ) ?? $value;
        $value = preg_replace( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', ' ', $value ) ?? $value;
        $value = strip_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
        return( function_exists( 'mb_trim' ) ? mb_trim( $value ) : trim( $value ) );
    }

    protected function limitText( string $value, int $limit ) : string {
        if ( $value === '' ) {
            return( '' );
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $value, 'UTF-8' ) <= $limit ) {
                return( $value );
            }
            return( rtrim( mb_substr( $value, 0, $limit - 3, 'UTF-8' ) ) . '...' );
        }

        if ( strlen( $value ) <= $limit ) {
            return( $value );
        }

        return( rtrim( substr( $value, 0, $limit - 3 ) ) . '...' );
    }

}
