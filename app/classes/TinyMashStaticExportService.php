<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

class TinyMashStaticExportService {

    protected const POSTS_PER_PAGE = 10;

    protected object $app;
    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashContentRenderer $content_renderer;
    protected TinyMashTheme $theme;
    protected TinyMashUserRepository $user_repository;
    protected TinyMashMediaService $media_service;
    protected TinyMashStaticExportArtifactService $artifact_service;
    protected string $public_root;
    protected array $warnings = [];
    protected array $copied_assets = [];
    protected array $exported_route_files = [];
    protected array $static_export_artifact_paths = [];
    protected array $unresolved_internal_urls = [];

    public function __construct(
        object $app,
        TinyMashConfig $config,
        TinyMashContentRepository $content_repository,
        TinyMashContentRenderer $content_renderer,
        TinyMashTheme $theme,
        TinyMashUserRepository $user_repository,
        TinyMashMediaService $media_service,
        TinyMashStaticExportArtifactService $artifact_service,
        string $public_root
    ) {
        $this->app = $app;
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->content_renderer = $content_renderer;
        $this->theme = $theme;
        $this->user_repository = $user_repository;
        $this->media_service = $media_service;
        $this->artifact_service = $artifact_service;
        $this->public_root = rtrim( $public_root, DIRECTORY_SEPARATOR );
    }

    public function createAuthorArtifact( string $owner_username, string $author_slug, array $options = [] ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $owner_username = $this->normalizeAuthorSlug( $owner_username );
        if ( $author_slug === '' || $owner_username === '' ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        $basename = $this->buildExportBasename( 'author', $author_slug );
        return(
            $this->artifact_service->createArtifact(
                $basename,
                [
                    'scope' => 'author',
                    'owner_username' => $owner_username,
                    'author_slug' => $author_slug,
                    'base_url' => $this->resolveBaseUrl( $options ),
                    'retention_seconds' => 21600,
                ],
                function( string $bundle_directory ) use ( $author_slug, $options ) : array {
                    return( $this->exportAuthorToDirectory( $author_slug, $bundle_directory, $options ) );
                }
            )
        );
    }

    public function createSiteArtifact( string $owner_username, array $options = [] ) : array {
        $owner_username = $this->normalizeAuthorSlug( $owner_username );
        if ( $owner_username === '' ) {
            throw new \InvalidArgumentException( 'owner_username' );
        }

        $basename = $this->buildExportBasename( 'site', 'site' );
        return(
            $this->artifact_service->createArtifact(
                $basename,
                [
                    'scope' => 'site',
                    'owner_username' => $owner_username,
                    'base_url' => $this->resolveBaseUrl( $options ),
                    'excluded_authors' => $this->normalizeAuthorSlugList( $options['exclude_authors'] ?? [] ),
                    'retention_seconds' => 21600,
                ],
                function( string $bundle_directory ) use ( $options ) : array {
                    return( $this->exportSiteToDirectory( $bundle_directory, $options ) );
                }
            )
        );
    }

    public function exportAuthorToDirectory( string $author_slug, string $target_directory, array $options = [] ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' || ! $this->authorCanBeExported( $author_slug ) ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        $this->resetExportState();
        $target_created = $this->prepareTargetDirectory( $target_directory );
        try {
            $routes = $this->buildAuthorRoutePlan( $author_slug );
            $base_url = $this->resolveBaseUrl( $options );
            $artifacts = $this->collectStaticExportArtifacts(
                'author',
                [
                    'scope' => 'author',
                    'author_slug' => $author_slug,
                    'included_authors' => [ $author_slug ],
                    'excluded_authors' => [],
                    'base_url' => $base_url,
                ],
                $routes
            );
            $this->writeRoutes( $routes, $target_directory, $base_url );
            $this->writeStaticExportArtifacts( $artifacts, $target_directory, $base_url );
            return( $this->writeReport( $target_directory, 'author', [ $author_slug ], [], $routes, $artifacts, $options ) );
        } catch ( \Throwable $exception ) {
            $this->cleanFailedExport( $target_directory, $target_created );
            throw $exception;
        }
    }

    public function exportSiteToDirectory( string $target_directory, array $options = [] ) : array {
        $this->resetExportState();
        $target_created = $this->prepareTargetDirectory( $target_directory );
        try {
            $excluded_authors = $this->normalizeAuthorSlugList( $options['exclude_authors'] ?? [] );
            $included_authors = array_values(
                array_filter(
                    $this->content_repository->getPublicAuthorSlugs(),
                    fn( string $author_slug ) : bool => $this->authorCanBeExported( $author_slug ) && ! in_array( $author_slug, $excluded_authors, true )
                )
            );

            $routes = $this->buildRootRoutePlan();
            foreach ( $included_authors as $author_slug ) {
                $routes = array_merge( $routes, $this->buildAuthorRoutePlan( $author_slug ) );
            }

            $base_url = $this->resolveBaseUrl( $options );
            $artifacts = $this->collectStaticExportArtifacts(
                'site',
                [
                    'scope' => 'site',
                    'author_slug' => '',
                    'included_authors' => $included_authors,
                    'excluded_authors' => $excluded_authors,
                    'base_url' => $base_url,
                ],
                $routes
            );
            $this->writeRoutes( $routes, $target_directory, $base_url );
            $this->writeStaticExportArtifacts( $artifacts, $target_directory, $base_url );
            return( $this->writeReport( $target_directory, 'site', $included_authors, $excluded_authors, $routes, $artifacts, $options ) );
        } catch ( \Throwable $exception ) {
            $this->cleanFailedExport( $target_directory, $target_created );
            throw $exception;
        }
    }

    public function getExportableAuthors() : array {
        $authors = [];
        foreach ( $this->content_repository->getPublicAuthorSlugs() as $author_slug ) {
            if ( ! $this->authorCanBeExported( $author_slug ) ) {
                continue;
            }

            $authors[] = [
                'slug' => $author_slug,
                'label' => $this->theme->getAuthorDisplayLabel( $author_slug ),
            ];
        }

        return( $authors );
    }

    protected function writeRoutes( array $routes, string $target_directory, string $base_url ) : void {
        foreach ( $routes as $route ) {
            if ( is_array( $route ) ) {
                $this->exported_route_files[$this->getStaticFilenameForPath( (string) ( $route['path'] ?? '/' ) )] = true;
            }
        }

        foreach ( $routes as $route ) {
            if ( ! is_array( $route ) ) {
                continue;
            }

            $path = (string) ( $route['path'] ?? '/' );
            $html = $this->renderRoute( $route );
            $html = $this->rewriteHtmlForStaticExport( $html, $path, $target_directory, $base_url );
            $filename = $target_directory . DIRECTORY_SEPARATOR . $this->getStaticFilenameForPath( $path );
            $this->ensureDirectory( dirname( $filename ) );
            if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $html ) ) {
                throw new \RuntimeException( 'Unable to write static route: ' . $path );
            }
        }
    }

    protected function renderRoute( array $route ) : string {
        $type = (string) ( $route['type'] ?? '' );
        if ( $type === 'root-home' ) {
            return( $this->renderRootHome( (int) ( $route['page'] ?? 1 ) ) );
        }
        if ( $type === 'author-home' ) {
            return( $this->renderAuthorHome( (string) ( $route['author_slug'] ?? '' ), (int) ( $route['page'] ?? 1 ) ) );
        }
        if ( $type === 'entry' ) {
            return( $this->renderEntry( is_array( $route['entry'] ?? null ) ? $route['entry'] : [] ) );
        }
        if ( $type === 'root-tag' ) {
            return( $this->renderTagListing( (string) ( $route['tag'] ?? '' ), 'root', '', (int) ( $route['page'] ?? 1 ) ) );
        }
        if ( $type === 'author-tag' ) {
            return( $this->renderTagListing( (string) ( $route['tag'] ?? '' ), 'author', (string) ( $route['author_slug'] ?? '' ), (int) ( $route['page'] ?? 1 ) ) );
        }

        throw new \InvalidArgumentException( 'route_type' );
    }

    protected function renderRootHome( int $page = 1 ) : string {
        $this->theme->clearThemeKeyOverride();
        $page = max( 1, $page );
        $aggregated_page = $this->content_repository->getPublishedAggregatedPostsPage( $this->config->getDefaultLanguage(), $page, self::POSTS_PER_PAGE );
        $entries = $this->filterPublicEntries( is_array( $aggregated_page['items'] ?? null ) ? $aggregated_page['items'] : [] );
        $rendered_entries = $this->content_renderer->renderEntrySummaries( $entries, $this->theme );
        $landing_entry = $this->resolveThemeLandingEntry( 'root', '' );
        $rendered_landing_entry = is_array( $landing_entry ) ? $this->content_renderer->renderEntry( $landing_entry, $this->theme, 'static_export' ) : null;
        $view_data = array_merge(
            $this->theme->getBaseViewData( 'Home', null, null, $this->getStaticExportRenderContext() ),
            $this->buildThemeListingViewData( $rendered_entries ),
            [
                'entries' => $rendered_entries,
                'listing_pagination' => $this->buildPaginationViewData( is_array( $aggregated_page['pagination'] ?? null ) ? $aggregated_page['pagination'] : [], '/', $page ),
                'landing_entry' => $rendered_landing_entry,
                'landing_entry_child_pages' => is_array( $landing_entry ) ? $this->theme->getChildPages( $landing_entry ) : [],
                'docs_landing_entry' => $rendered_landing_entry,
            ]
        );

        return( $this->renderTemplate( $this->theme->getTemplate( 'home' ), $view_data, '/' . ( $page > 1 ? '?page=' . $page : '' ) ) );
    }

    protected function renderAuthorHome( string $author_slug, int $page = 1 ) : string {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $this->applyAuthorThemeContext( $author_slug );
        $author_home = $this->content_repository->getAuthorHomePage( $author_slug, $this->config->getDefaultLanguage(), max( 1, $page ), self::POSTS_PER_PAGE );
        if ( ! is_array( $author_home ) ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        $rendered_posts = $this->decorateRenderedEntriesWithTagLinks(
            $this->content_renderer->renderEntrySummaries( is_array( $author_home['posts'] ?? null ) ? $author_home['posts'] : [], $this->theme ),
            'author',
            $author_slug
        );
        $author_display_name = $this->theme->getAuthorDisplayLabel( $author_slug );
        $docs_landing_entry = $this->theme->getThemeKey() === 'docsmatter'
            ? $this->resolveThemeLandingEntry( 'author', $author_slug )
            : null;
        $view_data = array_merge(
            $this->theme->getBaseViewData( $author_display_name !== '' ? $author_display_name : ucfirst( $author_slug ), null, $author_slug, $this->getStaticExportRenderContext() ),
            $this->buildThemeListingViewData( $rendered_posts, $author_slug ),
            [
                'author_slug' => $author_slug,
                'author_display_name' => $author_display_name,
                'author_url' => $this->theme->getAuthorURL( $author_slug ),
                'posts' => $rendered_posts,
                'listing_pagination' => $this->buildPaginationViewData( is_array( $author_home['pagination'] ?? null ) ? $author_home['pagination'] : [], $this->theme->getAuthorURL( $author_slug ), $page ),
                'author_home_can_edit' => false,
                'author_home_edit_url' => '',
                'docs_landing_entry' => is_array( $docs_landing_entry ) ? $this->content_renderer->renderEntry( $docs_landing_entry, $this->theme, 'static_export' ) : null,
            ]
        );

        return( $this->renderTemplate( $this->theme->getTemplate( 'author-home' ), $view_data, '/' . $author_slug . ( $page > 1 ? '?page=' . $page : '' ) ) );
    }

    protected function renderEntry( array $entry ) : string {
        if ( empty( $entry ) ) {
            throw new \InvalidArgumentException( 'entry' );
        }

        $author_slug = (string) ( $entry['scope'] ?? '' ) === 'author' ? $this->normalizeAuthorSlug( (string) ( $entry['author_slug'] ?? '' ) ) : '';
        $this->applyAuthorThemeContext( $author_slug );
        $tag_scope = $author_slug !== '' ? 'author' : 'root';
        $rendered_entry = $this->decorateRenderedEntryWithTagLinks(
            $this->content_renderer->renderEntry( $entry, $this->theme, 'static_export' ),
            $tag_scope,
            $author_slug
        );
        $view_data = array_merge(
            $this->theme->getBaseViewData( $rendered_entry['title'], $entry, $author_slug !== '' ? $author_slug : null, $this->getStaticExportRenderContext() ),
            [
                'entry' => $rendered_entry,
                'entry_breadcrumbs' => $this->theme->getEntryBreadcrumbs( $entry ),
                'entry_child_pages' => $this->theme->getChildPages( $entry ),
                'entry_navigation' => [],
                'docs_page_navigation' => (string) ( $entry['type'] ?? '' ) === 'page' && $this->theme->getThemeKey() === 'docsmatter'
                    ? $this->theme->getDocsPageNavigation( $entry, $this->config->getDefaultLanguage(), $this->theme->getThemeSettingsForAuthorContext( $author_slug ) )
                    : [ 'home_url' => '', 'previous' => null, 'next' => null ],
                'entry_can_edit' => false,
                'entry_edit_url' => '',
            ]
        );

        return( $this->renderTemplate( $this->theme->getTemplateForEntry( $entry ), $view_data, $this->theme->getEntryURL( $entry ) ) );
    }

    protected function renderTagListing( string $tag, string $scope, string $author_slug = '', int $page = 1 ) : string {
        $tag = $this->normalizeTagSlug( $tag );
        $page = max( 1, $page );
        if ( $tag === '' ) {
            throw new \InvalidArgumentException( 'tag' );
        }

        if ( $scope === 'author' ) {
            $author_slug = $this->normalizeAuthorSlug( $author_slug );
            $this->applyAuthorThemeContext( $author_slug );
            $tag_page = $this->content_repository->getAuthorTagPage( $author_slug, $tag, $this->config->getDefaultLanguage(), $page, self::POSTS_PER_PAGE );
            $base_path = '/' . rawurlencode( $author_slug ) . '/tags/' . rawurlencode( $tag );
            $rendered_entries = $this->decorateRenderedEntriesWithTagLinks(
                $this->content_renderer->renderEntrySummaries( is_array( $tag_page['items'] ?? null ) ? $tag_page['items'] : [], $this->theme ),
                'author',
                $author_slug
            );
            $author_display_name = $this->theme->getAuthorDisplayLabel( $author_slug );
            $tag_label = '#' . $tag;
            return(
                $this->renderTemplate(
                    $this->theme->getTemplate( 'author-home' ),
                    array_merge(
                        $this->theme->getBaseViewData( $author_display_name !== '' ? $author_display_name : ucfirst( $author_slug ), null, $author_slug, $this->getStaticExportRenderContext() ),
                        [
                            'author_slug' => $author_slug,
                            'author_display_name' => $author_display_name,
                            'author_url' => $this->theme->getAuthorURL( $author_slug ),
                            'posts' => $rendered_entries,
                            'listing_heading' => 'tagged ' . $tag_label,
                            'listing_context_title' => 'Tagged ' . $tag_label,
                            'listing_context_summary' => 'Published posts and pages tagged ' . $tag_label . ' in /' . $author_slug . '.',
                            'listing_empty_message' => 'No published posts or pages with that tag were found in this author space.',
                            'listing_pagination' => $this->buildPaginationViewData( is_array( $tag_page['pagination'] ?? null ) ? $tag_page['pagination'] : [], $base_path, $page ),
                            'force_listing_results' => true,
                        ]
                    ),
                    $base_path . ( $page > 1 ? '?page=' . $page : '' )
                )
            );
        }

        $this->theme->clearThemeKeyOverride();
        $tag_page = $this->content_repository->getRootTagPage( $tag, $this->config->getDefaultLanguage(), $page, self::POSTS_PER_PAGE );
        $base_path = '/tags/' . rawurlencode( $tag );
        $rendered_entries = $this->decorateRenderedEntriesWithTagLinks(
            $this->content_renderer->renderEntrySummaries( $this->filterPublicEntries( is_array( $tag_page['items'] ?? null ) ? $tag_page['items'] : [] ), $this->theme ),
            'root',
            ''
        );
        $tag_label = '#' . $tag;
        return(
            $this->renderTemplate(
                $this->theme->getTemplate( 'home' ),
                array_merge(
                    $this->theme->getBaseViewData( 'Tagged ' . $tag_label, null, null, $this->getStaticExportRenderContext() ),
                    [
                        'entries' => $rendered_entries,
                        'listing_context_title' => 'Tagged ' . $tag_label,
                        'listing_context_summary' => 'Published root-visible content tagged ' . $tag_label . '.',
                        'listing_empty_message' => 'No published root-visible content with that tag was found.',
                        'listing_pagination' => $this->buildPaginationViewData( is_array( $tag_page['pagination'] ?? null ) ? $tag_page['pagination'] : [], $base_path, $page ),
                        'landing_entry' => null,
                        'landing_entry_child_pages' => [],
                        'docs_landing_entry' => null,
                        'force_listing_results' => true,
                    ]
                ),
                $base_path . ( $page > 1 ? '?page=' . $page : '' )
            )
        );
    }

    protected function buildRootRoutePlan() : array {
        $routes = [];
        $pagination = $this->content_repository->getPublishedAggregatedPaginationMeta( self::POSTS_PER_PAGE );
        for ( $page = 1; $page <= max( 1, (int) ( $pagination['total_pages'] ?? 1 ) ); $page++ ) {
            $routes[] = [ 'type' => 'root-home', 'path' => $this->buildPagedPath( '/', $page ), 'page' => $page ];
        }

        $entries = array_values(
            array_filter(
                $this->content_repository->getEntriesForAudit( $this->config->getDefaultLanguage(), null, true, true ),
                static fn( array $entry ) : bool => (string) ( $entry['scope'] ?? 'root' ) === 'root'
            )
        );
        foreach ( $entries as $entry ) {
            $routes[] = [ 'type' => 'entry', 'path' => $this->theme->getEntryURL( $entry ), 'entry' => $entry ];
        }

        foreach ( $this->collectTagsFromEntries( $entries ) as $tag ) {
            $tag_page = $this->content_repository->getRootTagPage( $tag, $this->config->getDefaultLanguage(), 1, self::POSTS_PER_PAGE );
            $pagination = is_array( $tag_page['pagination'] ?? null ) ? $tag_page['pagination'] : [];
            for ( $page = 1; $page <= max( 1, (int) ( $pagination['total_pages'] ?? 1 ) ); $page++ ) {
                $routes[] = [ 'type' => 'root-tag', 'path' => $this->buildPagedPath( '/tags/' . rawurlencode( $tag ), $page ), 'tag' => $tag, 'page' => $page ];
            }
        }

        return( $this->dedupeRoutes( $routes ) );
    }

    protected function buildAuthorRoutePlan( string $author_slug ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        $routes = [];
        $pagination = $this->content_repository->getAuthorHomePaginationMeta( $author_slug, self::POSTS_PER_PAGE ) ?? [];
        for ( $page = 1; $page <= max( 1, (int) ( $pagination['total_pages'] ?? 1 ) ); $page++ ) {
            $routes[] = [ 'type' => 'author-home', 'path' => $this->buildPagedPath( '/' . rawurlencode( $author_slug ), $page ), 'author_slug' => $author_slug, 'page' => $page ];
        }

        $entries = $this->content_repository->getEntriesForAudit( $this->config->getDefaultLanguage(), $author_slug, false, true );
        foreach ( $entries as $entry ) {
            $routes[] = [ 'type' => 'entry', 'path' => $this->theme->getEntryURL( $entry ), 'entry' => $entry ];
        }

        foreach ( $this->collectTagsFromEntries( $entries ) as $tag ) {
            $tag_page = $this->content_repository->getAuthorTagPage( $author_slug, $tag, $this->config->getDefaultLanguage(), 1, self::POSTS_PER_PAGE );
            $pagination = is_array( $tag_page['pagination'] ?? null ) ? $tag_page['pagination'] : [];
            for ( $page = 1; $page <= max( 1, (int) ( $pagination['total_pages'] ?? 1 ) ); $page++ ) {
                $routes[] = [ 'type' => 'author-tag', 'path' => $this->buildPagedPath( '/' . rawurlencode( $author_slug ) . '/tags/' . rawurlencode( $tag ), $page ), 'author_slug' => $author_slug, 'tag' => $tag, 'page' => $page ];
            }
        }

        return( $this->dedupeRoutes( $routes ) );
    }

    protected function renderTemplate( string $template, array $view_data, string $request_uri ) : string {
        $this->resetShortcodeRequestState();
        $previous_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = $request_uri;
        ob_start();
        try {
            $this->app->view()->render( $template, $view_data );
            $html = (string) ob_get_clean();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            throw $e;
        } finally {
            if ( $previous_request_uri === null ) {
                unset( $_SERVER['REQUEST_URI'] );
            } else {
                $_SERVER['REQUEST_URI'] = $previous_request_uri;
            }
        }

        if ( $this->hasDynamicShortcodeRenderForRequest() ) {
            $this->warnings[] = 'Dynamic shortcode output was snapshotted for ' . $request_uri . '.';
        }

        return( $html );
    }

    protected function rewriteHtmlForStaticExport( string $html, string $source_path, string $target_directory, string $base_url ) : string {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            $this->warnings[] = 'Unable to parse HTML for ' . $source_path . '; URL rewriting was skipped.';
            return( $html );
        }

        $this->rewriteMetadataBaseUrls( $document, $base_url, $source_path );

        $url_attributes = [
            'a' => [ 'href' ],
            'audio' => [ 'src' ],
            'button' => [ 'formaction' ],
            'embed' => [ 'src' ],
            'form' => [ 'action' ],
            'iframe' => [ 'src' ],
            'img' => [ 'src' ],
            'input' => [ 'src', 'formaction' ],
            'link' => [ 'href' ],
            'object' => [ 'data' ],
            'script' => [ 'src' ],
            'source' => [ 'src' ],
            'track' => [ 'src' ],
            'video' => [ 'src', 'poster' ],
        ];
        foreach ( $url_attributes as $tag => $attributes ) {
            foreach ( iterator_to_array( $document->getElementsByTagName( $tag ) ) as $node ) {
                if ( ! $node instanceof \DOMElement ) {
                    continue;
                }
                foreach ( $attributes as $attribute ) {
                    if ( ! $node->hasAttribute( $attribute ) ) {
                        continue;
                    }

                    $url = trim( $node->getAttribute( $attribute ) );
                    $rewritten = $this->rewriteUrl( $url, $source_path, $target_directory, $base_url );
                    if ( $rewritten !== $url ) {
                        $node->setAttribute( $attribute, $rewritten );
                    }
                }
            }
        }

        foreach ( [ 'img', 'source' ] as $tag ) {
            foreach ( iterator_to_array( $document->getElementsByTagName( $tag ) ) as $node ) {
                if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'srcset' ) ) {
                    continue;
                }
                $srcset = $node->getAttribute( 'srcset' );
                $node->setAttribute( 'srcset', $this->rewriteSrcset( $srcset, $source_path, $target_directory, $base_url ) );
            }
        }

        foreach ( iterator_to_array( $document->getElementsByTagName( '*' ) ) as $node ) {
            if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'style' ) ) {
                continue;
            }
            $node->setAttribute( 'style', $this->rewriteCssUrls( $node->getAttribute( 'style' ), $source_path, $target_directory, $base_url ) );
        }
        foreach ( iterator_to_array( $document->getElementsByTagName( 'style' ) ) as $node ) {
            $node->nodeValue = $this->rewriteCssUrls( $node->textContent, $source_path, $target_directory, $base_url );
        }

        return( $document->saveHTML() ?: $html );
    }

    protected function rewriteSrcset( string $srcset, string $source_path, string $target_directory, string $base_url ) : string {
        if ( trim( $srcset ) === '' || str_contains( strtolower( $srcset ), 'data:' ) ) {
            return( $srcset );
        }

        $candidates = preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: [];
        foreach ( $candidates as &$candidate ) {
            if ( preg_match( '/^(\S+)(\s+.+)?$/', $candidate, $matches ) !== 1 ) {
                continue;
            }
            $candidate = $this->rewriteUrl( $matches[1], $source_path, $target_directory, $base_url ) . ( $matches[2] ?? '' );
        }
        unset( $candidate );

        return( implode( ', ', $candidates ) );
    }

    protected function rewriteCssUrls( string $css, string $source_path, string $target_directory, string $base_url ) : string {
        return(
            preg_replace_callback(
                '/url\(\s*([\'\"]?)([^\'\")]+)\1\s*\)/i',
                function( array $matches ) use ( $source_path, $target_directory, $base_url ) : string {
                    $url = trim( (string) ( $matches[2] ?? '' ) );
                    if ( $url === '' || str_starts_with( $url, 'data:' ) ) {
                        return( $matches[0] );
                    }
                    $quote = (string) ( $matches[1] ?? '' );
                    return( 'url(' . $quote . $this->rewriteUrl( $url, $source_path, $target_directory, $base_url ) . $quote . ')' );
                },
                $css
            ) ?? $css
        );
    }

    protected function rewriteMetadataBaseUrls( \DOMDocument $document, string $base_url, string $source_path ) : void {
        $configured_base_url = rtrim( trim( (string) $this->config->configGetBaseURL() ), '/' );
        $base_url = rtrim( trim( $base_url ), '/' );
        if ( $base_url === '' ) {
            return;
        }
        $route_url = $this->buildStaticRouteUrl( $base_url, $source_path );

        foreach ( iterator_to_array( $document->getElementsByTagName( 'link' ) ) as $node ) {
            if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'href' ) ) {
                continue;
            }
            $rel = strtolower( trim( $node->getAttribute( 'rel' ) ) );
            if ( $rel === 'canonical' ) {
                $node->setAttribute( 'href', $route_url );
                continue;
            }
            if ( $rel !== 'alternate' || $configured_base_url === '' || $configured_base_url === $base_url ) {
                continue;
            }
            $node->setAttribute( 'href', $this->replaceConfiguredBaseUrl( $node->getAttribute( 'href' ), $configured_base_url, $base_url ) );
        }

        foreach ( iterator_to_array( $document->getElementsByTagName( 'meta' ) ) as $node ) {
            if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'content' ) ) {
                continue;
            }
            $property = strtolower( trim( $node->getAttribute( 'property' ) ) );
            $name = strtolower( trim( $node->getAttribute( 'name' ) ) );
            if ( $property === 'og:url' || $name === 'twitter:url' ) {
                $node->setAttribute( 'content', $route_url );
                continue;
            }
            if (
                ( $property !== 'og:image' && $name !== 'twitter:image' )
                || $configured_base_url === ''
                || $configured_base_url === $base_url
            ) {
                continue;
            }
            $node->setAttribute( 'content', $this->replaceConfiguredBaseUrl( $node->getAttribute( 'content' ), $configured_base_url, $base_url ) );
        }
    }

    protected function buildStaticRouteUrl( string $base_url, string $source_path ) : string {
        $filename = str_replace( '\\', '/', $this->getStaticFilenameForPath( $source_path ) );
        if ( $filename === 'index.html' ) {
            return( rtrim( $base_url, '/' ) . '/' );
        }
        if ( str_ends_with( $filename, '/index.html' ) ) {
            $filename = substr( $filename, 0, -strlen( 'index.html' ) );
        }

        return( rtrim( $base_url, '/' ) . '/' . ltrim( $filename, '/' ) );
    }

    protected function replaceConfiguredBaseUrl( string $value, string $configured_base_url, string $base_url ) : string {
        $value = trim( $value );
        if ( $value === $configured_base_url ) {
            return( $base_url );
        }
        if ( str_starts_with( $value, $configured_base_url . '/' ) ) {
            return( $base_url . substr( $value, strlen( $configured_base_url ) ) );
        }

        return( $value );
    }

    protected function rewriteUrl( string $url, string $source_path, string $target_directory, string $base_url ) : string {
        if ( $url === '' || str_starts_with( $url, '#' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) === 1 || str_starts_with( $url, '//' ) ) {
            return( $url );
        }

        $fragment = '';
        $fragment_pos = strpos( $url, '#' );
        if ( $fragment_pos !== false ) {
            $fragment = substr( $url, $fragment_pos );
            $url = substr( $url, 0, $fragment_pos );
        }

        $query = '';
        $query_pos = strpos( $url, '?' );
        if ( $query_pos !== false ) {
            $query = substr( $url, $query_pos );
            $url = substr( $url, 0, $query_pos );
        }

        if ( ! str_starts_with( $url, '/' ) ) {
            return( $url . $query . $fragment );
        }

        $source_dir = dirname( $this->getStaticFilenameForPath( $source_path ) );
        $public_path = parse_url( $url, PHP_URL_PATH );
        $public_path = is_string( $public_path ) ? $public_path : $url;
        $route_file = $this->getStaticFilenameForPath( $public_path . $query );
        if ( isset( $this->exported_route_files[$route_file] ) ) {
            return( $this->relativePath( $source_dir, $route_file ) . $fragment );
        }
        $generated_theme_css = $this->copyGeneratedThemeCssIfAvailable( $public_path, $target_directory, $base_url );
        if ( $generated_theme_css !== '' ) {
            return( $this->relativePath( $source_dir, $generated_theme_css ) . $query . $fragment );
        }
        if ( $this->copyPublicAssetIfAvailable( $public_path, $target_directory ) ) {
            return( $this->relativePath( $source_dir, ltrim( $public_path, '/' ) ) . $query . $fragment );
        }

        $unresolved_url = $public_path . $query;
        $this->unresolved_internal_urls[$unresolved_url] = true;
        $live_base_url = rtrim( trim( (string) $this->config->configGetBaseURL() ), '/' );
        return( $live_base_url !== '' ? $live_base_url . $unresolved_url . $fragment : $unresolved_url . $fragment );
    }

    protected function copyGeneratedThemeCssIfAvailable( string $public_path, string $target_directory, string $base_url ) : string {
        if ( preg_match( '#^/theme-custom-css/([a-z0-9_-]+)$#', $public_path, $matches ) !== 1 ) {
            return( '' );
        }

        $theme_key = (string) ( $matches[1] ?? '' );
        if ( $theme_key === '' || $theme_key !== $this->theme->getThemeKey() ) {
            return( '' );
        }

        $settings = $this->theme->getCustomCssSettings( $theme_key );
        $css = (string) ( $settings['css'] ?? '' );
        if ( empty( $settings['enabled'] ) || trim( $css ) === '' ) {
            return( '' );
        }

        $relative = 'theme-custom-css/' . $theme_key . '.css';
        $target = rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
        $this->ensureDirectory( dirname( $target ) );
        if ( ! is_file( $target ) ) {
            $css = $this->rewriteCssUrls( $css, '/' . $relative, $target_directory, $base_url );
            if ( ! TinyMashFilesystem::writeFileAtomically( $target, $css ) ) {
                throw new \RuntimeException( 'Unable to write generated theme stylesheet: ' . $relative );
            }
            $this->copied_assets[$relative] = true;
        }

        return( $relative );
    }

    protected function copyPublicAssetIfAvailable( string $public_path, string $target_directory ) : bool {
        $public_path = '/' . ltrim( $public_path, '/' );
        if ( $public_path === '/' || str_ends_with( $public_path, '/' ) ) {
            return( false );
        }
        if ( preg_match( '#\.(html?|php)$#i', $public_path ) === 1 ) {
            return( false );
        }

        $source = $this->resolveLocalPublicAssetSource( $public_path );
        if ( $source === '' ) {
            return( false );
        }

        $relative = ltrim( $public_path, '/' );
        $target = rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
        $this->ensureDirectory( dirname( $target ) );
        if ( ! is_file( $target ) ) {
            if ( preg_match( '/\.css$/i', $relative ) === 1 ) {
                $css = file_get_contents( $source );
                if ( ! is_string( $css ) ) {
                    throw new \RuntimeException( 'Unable to read stylesheet: ' . $relative );
                }
                $css = $this->rewriteCssUrls( $css, '/' . $relative, $target_directory, $this->resolveBaseUrl( [] ) );
                if ( ! TinyMashFilesystem::writeFileAtomically( $target, $css ) ) {
                    throw new \RuntimeException( 'Unable to write stylesheet: ' . $relative );
                }
            } else {
                if ( ! @ copy( $source, $target ) ) {
                    throw new \RuntimeException( 'Unable to copy public asset: ' . $relative );
                }
            }
            $this->copied_assets[$relative] = true;
            if ( preg_match( '/\.css$/i', $relative ) === 1 ) {
                $this->copyCssReferencedAssets( $source, $relative, $target_directory );
            }
        }

        return( true );
    }

    protected function resolveLocalPublicAssetSource( string $public_path ) : string {
        $source = $this->public_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $public_path, '/' ) );
        if ( is_file( $source ) && $this->pathIsContained( $source, $this->public_root ) ) {
            return( $source );
        }

        $media_parts = explode( '/', trim( $public_path, '/' ) );
        if ( count( $media_parts ) === 5 && $media_parts[0] === 'media' ) {
            $media = $this->media_service->resolveMediaPath( $media_parts[1], $media_parts[2], $media_parts[3], $media_parts[4] );
            $media_path = is_array( $media ) ? (string) ( $media['path'] ?? '' ) : '';
            if ( $media_path !== '' && is_file( $media_path ) && $this->pathIsContained( $media_path, $this->media_service->getMediaRoot() ) ) {
                return( $media_path );
            }
        }

        return( '' );
    }

    protected function copyCssReferencedAssets( string $source_css, string $css_relative_path, string $target_directory ) : void {
        $css = file_get_contents( $source_css );
        if ( ! is_string( $css ) || $css === '' ) {
            return;
        }

        if ( preg_match_all( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches ) !== false ) {
            foreach ( $matches[2] ?? [] as $asset_url ) {
                $asset_url = trim( (string) $asset_url );
                if ( $asset_url === '' || str_starts_with( $asset_url, 'data:' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $asset_url ) === 1 || str_starts_with( $asset_url, '//' ) ) {
                    continue;
                }

                $asset_path = (string) ( parse_url( $asset_url, PHP_URL_PATH ) ?? '' );
                if ( $asset_path === '' ) {
                    continue;
                }

                $public_relative = str_starts_with( $asset_path, '/' )
                    ? ltrim( $asset_path, '/' )
                    : trim( dirname( $css_relative_path ) . '/' . $asset_path, '/' );
                $public_relative = $this->normalizeRelativeAssetPath( $public_relative );
                if ( $public_relative === '' ) {
                    continue;
                }

                $source = $this->resolveLocalPublicAssetSource( '/' . $public_relative );
                if ( $source === '' ) {
                    continue;
                }

                $target = rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $public_relative );
                $this->ensureDirectory( dirname( $target ) );
                if ( ! is_file( $target ) ) {
                    copy( $source, $target );
                    $this->copied_assets[$public_relative] = true;
                }
            }
        }
    }

    protected function normalizeRelativeAssetPath( string $relative_path ) : string {
        $parts = [];
        foreach ( explode( '/', str_replace( '\\', '/', $relative_path ) ) as $part ) {
            if ( $part === '' || $part === '.' ) {
                continue;
            }
            if ( $part === '..' ) {
                array_pop( $parts );
                continue;
            }
            $parts[] = $part;
        }

        return( implode( '/', $parts ) );
    }

    protected function getStaticFilenameForPath( string $path ) : string {
        $path = trim( $path );
        $query = (string) ( parse_url( $path, PHP_URL_QUERY ) ?? '' );
        $clean_path = (string) ( parse_url( $path, PHP_URL_PATH ) ?? '/' );
        $clean_path = '/' . trim( $clean_path, '/' );
        if ( $clean_path === '/' ) {
            return( $query === '' ? 'index.html' : 'page/' . $this->queryPageNumber( $query ) . '/index.html' );
        }
        $relative = trim( $clean_path, '/' );
        if ( $query !== '' ) {
            return( $relative . '/page/' . $this->queryPageNumber( $query ) . '/index.html' );
        }
        if ( preg_match( '#\.[a-z0-9]{1,8}$#i', $relative ) === 1 ) {
            return( $relative );
        }

        return( $relative . '/index.html' );
    }

    protected function buildPagedPath( string $base_path, int $page ) : string {
        $base_path = '/' . trim( $base_path, '/' );
        if ( $base_path === '/' ) {
            return( $page <= 1 ? '/' : '/?page=' . $page );
        }

        return( $page <= 1 ? $base_path : $base_path . '?page=' . $page );
    }

    protected function queryPageNumber( string $query ) : string {
        parse_str( $query, $params );
        return( (string) max( 2, (int) ( $params['page'] ?? 2 ) ) );
    }

    protected function buildPaginationViewData( array $pagination, string $base_path, int $page ) : array {
        $current_page = max( 1, (int) ( $pagination['current_page'] ?? $page ) );
        $total_pages = max( 1, (int) ( $pagination['total_pages'] ?? 1 ) );
        return(
            [
                'current_page' => $current_page,
                'total_pages' => $total_pages,
                'has_previous' => $current_page > 1,
                'has_next' => $current_page < $total_pages,
                'previous_url' => $current_page > 1 ? $this->buildPagedPath( $base_path, $current_page - 1 ) : '',
                'next_url' => $current_page < $total_pages ? $this->buildPagedPath( $base_path, $current_page + 1 ) : '',
            ]
        );
    }

    protected function buildThemeListingViewData( array $entries, string $author_slug = '' ) : array {
        if ( $this->theme->getThemeKey() === 'blocks' ) {
            return( $this->theme->getBlocksListingViewData( $entries, $this->theme->getThemeSettingsForAuthorContext( $author_slug ) ) );
        }
        if ( $this->theme->getThemeKey() === 'panel-magazine' ) {
            return( $this->theme->getPanelMagazineListingViewData( $entries, $this->theme->getThemeSettingsForAuthorContext( $author_slug ) ) );
        }

        return( [] );
    }

    protected function resolveThemeLandingEntry( string $scope, string $author_slug ) : ?array {
        $theme_settings = $this->theme->getThemeSettingsForAuthorContext( $scope === 'author' ? $author_slug : '' );
        if ( $scope === 'root' && $this->theme->getRootLandingMode( $theme_settings ) !== 'page' ) {
            return( null );
        }

        return( $this->theme->getLandingEntry( $scope, $author_slug, $this->config->getDefaultLanguage(), $theme_settings ) );
    }

    protected function decorateRenderedEntriesWithTagLinks( array $entries, string $scope, string $author_slug ) : array {
        return( array_map( fn( array $entry ) : array => $this->decorateRenderedEntryWithTagLinks( $entry, $scope, $author_slug ), $entries ) );
    }

    protected function decorateRenderedEntryWithTagLinks( array $entry, string $scope, string $author_slug ) : array {
        $tag_links = [];
        foreach ( is_array( $entry['tags'] ?? null ) ? $entry['tags'] : [] as $tag ) {
            if ( ! is_string( $tag ) ) {
                continue;
            }
            $normalized_tag = $this->normalizeTagSlug( $tag );
            if ( $normalized_tag === '' ) {
                continue;
            }
            $tag_links[] = [
                'label' => $normalized_tag,
                'url' => $scope === 'author' && $author_slug !== ''
                    ? '/' . rawurlencode( $author_slug ) . '/tags/' . rawurlencode( $normalized_tag )
                    : '/tags/' . rawurlencode( $normalized_tag ),
            ];
        }

        $entry['tag_links'] = $tag_links;
        return( $entry );
    }

    protected function collectTagsFromEntries( array $entries ) : array {
        $tags = [];
        foreach ( $entries as $entry ) {
            foreach ( is_array( $entry['tags'] ?? null ) ? $entry['tags'] : [] as $tag ) {
                if ( ! is_string( $tag ) ) {
                    continue;
                }
                $normalized_tag = $this->normalizeTagSlug( $tag );
                if ( $normalized_tag !== '' ) {
                    $tags[$normalized_tag] = true;
                }
            }
        }

        $tags = array_keys( $tags );
        sort( $tags, SORT_STRING );
        return( $tags );
    }

    protected function filterPublicEntries( array $entries ) : array {
        return(
            array_values(
                array_filter(
                    $entries,
                    fn( array $entry ) : bool => (string) ( $entry['scope'] ?? 'root' ) !== 'author'
                        || $this->authorCanBeExported( (string) ( $entry['author_slug'] ?? '' ) )
                )
            )
        );
    }

    protected function authorCanBeExported( string $author_slug ) : bool {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        return( $author_slug !== '' && $this->content_repository->hasPublicAuthor( $author_slug ) && $this->user_repository->isAuthorContentPublic( $author_slug ) );
    }

    protected function applyAuthorThemeContext( string $author_slug ) : void {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            $this->theme->clearThemeKeyOverride();
            return;
        }

        $this->theme->setThemeKeyOverride( $this->theme->resolveThemeKeyForAuthorContext( $author_slug ) );
    }

    protected function resetShortcodeRequestState() : void {
        if ( ! $this->app->has( 'shortcode.registry' ) ) {
            return;
        }

        $shortcode_registry = $this->app->get( 'shortcode.registry' );
        if ( $shortcode_registry instanceof TinyMashShortcodeRegistry ) {
            $shortcode_registry->resetRequestState();
        }
    }

    protected function hasDynamicShortcodeRenderForRequest() : bool {
        if ( ! $this->app->has( 'shortcode.registry' ) ) {
            return( false );
        }

        $shortcode_registry = $this->app->get( 'shortcode.registry' );
        return( $shortcode_registry instanceof TinyMashShortcodeRegistry && $shortcode_registry->hasDynamicRenderForRequest() );
    }

    protected function collectStaticExportArtifacts( string $scope, array $context, array $routes ) : array {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( [] );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! is_object( $plugins ) || ! method_exists( $plugins, 'runStaticExportContributors' ) ) {
            return( [] );
        }

        $occupied_files = [ 'export-report.json' => true ];
        foreach ( $routes as $route ) {
            if ( is_array( $route ) ) {
                $occupied_files[$this->getStaticFilenameForPath( (string) ( $route['path'] ?? '/' ) )] = true;
            }
        }

        $artifacts = [];
        foreach ( $plugins->runStaticExportContributors( $scope, $context ) as $contribution ) {
            if ( ! is_array( $contribution ) ) {
                continue;
            }

            $plugin_key = trim( (string) ( $contribution['plugin_key'] ?? '' ) );
            if ( (string) ( $contribution['status'] ?? '' ) !== 'ran' ) {
                $message = mb_trim( (string) ( $contribution['message'] ?? '' ) );
                $this->warnings[] = 'Static export contribution failed for ' . ( $plugin_key !== '' ? $plugin_key : 'a plugin' ) . ( $message !== '' ? ': ' . $message : '.' );
                continue;
            }

            foreach ( (array) ( $contribution['result'] ?? [] ) as $artifact ) {
                $normalized = $this->normalizeStaticExportArtifact( is_array( $artifact ) ? $artifact : [], $plugin_key );
                if ( $normalized === null ) {
                    continue;
                }

                $filename = (string) $normalized['filename'];
                if ( isset( $occupied_files[$filename] ) ) {
                    $this->warnings[] = 'Static export artifact collision at /' . $filename . ' from ' . ( $plugin_key !== '' ? $plugin_key : 'a plugin' ) . '.';
                    continue;
                }

                $occupied_files[$filename] = true;
                $this->exported_route_files[$filename] = true;
                $this->static_export_artifact_paths[(string) $normalized['path']] = true;
                $artifacts[] = $normalized;
            }
        }

        return( $artifacts );
    }

    protected function normalizeStaticExportArtifact( array $artifact, string $plugin_key ) : ?array {
        $path = trim( (string) ( $artifact['path'] ?? '' ) );
        $contents = $artifact['contents'] ?? null;
        if ( $path === '' || ! is_string( $contents ) || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) ) {
            $this->warnings[] = 'Ignored an invalid static export artifact from ' . ( $plugin_key !== '' ? $plugin_key : 'a plugin' ) . '.';
            return( null );
        }
        $parsed_path = parse_url( $path, PHP_URL_PATH );
        $path_segments = explode( '/', ltrim( $path, '/' ) );
        if (
            ! is_string( $parsed_path )
            || $parsed_path !== $path
            || preg_match( '/[\x00-\x1F\x7F]/', $path ) === 1
            || str_contains( $path, '\\' )
            || str_contains( $path, '%' )
            || in_array( '', $path_segments, true )
            || in_array( '.', $path_segments, true )
            || in_array( '..', $path_segments, true )
        ) {
            $this->warnings[] = 'Ignored an unsafe static export artifact path from ' . ( $plugin_key !== '' ? $plugin_key : 'a plugin' ) . '.';
            return( null );
        }

        $filename = $this->getStaticFilenameForPath( $path );
        if ( pathinfo( $path, PATHINFO_EXTENSION ) === '' ) {
            $this->warnings[] = 'Ignored a static export artifact without a file extension from ' . ( $plugin_key !== '' ? $plugin_key : 'a plugin' ) . '.';
            return( null );
        }

        return(
            [
                'plugin_key' => $plugin_key,
                'path' => '/' . ltrim( $path, '/' ),
                'filename' => $filename,
                'contents' => $contents,
                'content_type' => trim( (string) ( $artifact['content_type'] ?? 'application/octet-stream' ) ),
            ]
        );
    }

    protected function writeStaticExportArtifacts( array $artifacts, string $target_directory, string $base_url = '' ) : void {
        $target_root = realpath( $target_directory );
        if ( ! is_string( $target_root ) ) {
            throw new \RuntimeException( 'static_export_target' );
        }
        $target_prefix = rtrim( $target_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

        foreach ( $artifacts as $artifact ) {
            if ( ! is_array( $artifact ) ) {
                continue;
            }
            $filename = rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) ( $artifact['filename'] ?? '' ) );
            $this->ensureDirectory( dirname( $filename ) );
            $resolved_directory = realpath( dirname( $filename ) );
            if ( ! is_string( $resolved_directory ) || ( $resolved_directory !== $target_root && ! str_starts_with( $resolved_directory . DIRECTORY_SEPARATOR, $target_prefix ) ) ) {
                throw new \RuntimeException( 'static_export_artifact_path' );
            }
            if ( is_link( $filename ) || file_exists( $filename ) ) {
                throw new \RuntimeException( 'static_export_artifact_collision' );
            }
            $contents = (string) ( $artifact['contents'] ?? '' );
            $content_type = strtolower( trim( (string) ( $artifact['content_type'] ?? '' ) ) );
            if ( str_starts_with( $content_type, 'text/html' ) || preg_match( '/\.html?$/i', $filename ) === 1 ) {
                $contents = $this->rewriteHtmlForStaticExport( $contents, (string) ( $artifact['path'] ?? '' ), $target_directory, $base_url );
            }
            if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $contents ) ) {
                throw new \RuntimeException( 'static_export_artifact_write' );
            }
        }
    }

    protected function getStaticExportRenderContext() : array {
        return(
            [
                'static_export' => true,
                'static_export_artifacts' => array_keys( $this->static_export_artifact_paths ),
            ]
        );
    }

    protected function writeReport( string $target_directory, string $scope, array $included_authors, array $excluded_authors, array $routes, array $artifacts, array $options ) : array {
        $unresolved_internal_urls = array_keys( $this->unresolved_internal_urls );
        sort( $unresolved_internal_urls, SORT_STRING );
        if ( $unresolved_internal_urls !== [] ) {
            $this->warnings[] = count( $unresolved_internal_urls ) . ' internal URL(s) were outside the export and now point to the live site.';
        }
        $plugin_export_modes = $this->getPluginStaticExportDeclarations();
        $plugin_export_mode_counts = array_fill_keys( TinyMashPlugins::STATIC_EXPORT_MODES, 0 );
        foreach ( $plugin_export_modes as $declaration ) {
            $mode = (string) ( $declaration['mode'] ?? '' );
            if ( isset( $plugin_export_mode_counts[$mode] ) ) {
                $plugin_export_mode_counts[$mode]++;
            }
        }
        $report = [
            'scope' => $scope,
            'generated_at_utc' => gmdate( DATE_ATOM ),
            'base_url' => $this->resolveBaseUrl( $options ),
            'route_count' => count( $routes ),
            'asset_count' => count( $this->copied_assets ),
            'artifact_count' => count( $artifacts ),
            'artifacts' => array_values(
                array_map(
                    static fn( array $artifact ) : array => [
                        'plugin_key' => (string) ( $artifact['plugin_key'] ?? '' ),
                        'path' => (string) ( $artifact['path'] ?? '' ),
                        'content_type' => (string) ( $artifact['content_type'] ?? '' ),
                    ],
                    $artifacts
                )
            ),
            'plugin_export_mode_counts' => $plugin_export_mode_counts,
            'plugin_export_modes' => $plugin_export_modes,
            'included_authors' => array_values( $included_authors ),
            'excluded_authors' => array_values( $excluded_authors ),
            'unresolved_internal_urls' => $unresolved_internal_urls,
            'warnings' => array_values( array_unique( $this->warnings ) ),
            'dynamic_policy' => 'Plugin behavior follows its declared export mode; rendered dynamic output is snapshotted at export time.',
        ];
        $report_json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $report_filename = rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'export-report.json';
        if ( ! is_string( $report_json ) || ! TinyMashFilesystem::writeFileAtomically( $report_filename, $report_json . PHP_EOL ) ) {
            throw new \RuntimeException( 'Unable to write static export report.' );
        }
        return( $report );
    }

    protected function getPluginStaticExportDeclarations() : array {
        if ( ! method_exists( $this->app, 'has' ) || ! method_exists( $this->app, 'get' ) || ! $this->app->has( 'plugins' ) ) {
            return( [] );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! $plugins instanceof TinyMashPlugins ) {
            return( [] );
        }

        return( $plugins->getStaticExportDeclarations() );
    }

    protected function resetExportState() : void {
        $this->warnings = [];
        $this->copied_assets = [];
        $this->exported_route_files = [];
        $this->static_export_artifact_paths = [];
        $this->unresolved_internal_urls = [];
        $this->warnings[] = 'Static export does not include PHP behavior, admin routes, sessions, forms, provider refreshes, drafts, users, config, or private runtime state.';
    }

    protected function dedupeRoutes( array $routes ) : array {
        $seen = [];
        $deduped = [];
        foreach ( $routes as $route ) {
            $path = (string) ( $route['path'] ?? '' );
            if ( $path === '' || isset( $seen[$path] ) ) {
                continue;
            }
            $seen[$path] = true;
            $deduped[] = $route;
        }

        return( $deduped );
    }

    protected function resolveBaseUrl( array $options ) : string {
        $base_url = trim( (string) ( $options['base_url'] ?? '' ) );
        if ( $base_url === '' ) {
            $base_url = trim( (string) $this->config->configGetBaseURL() );
        }

        $parts = parse_url( $base_url );
        if (
            $base_url === ''
            || ! is_array( $parts )
            || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
            || trim( (string) ( $parts['host'] ?? '' ) ) === ''
            || isset( $parts['user'] )
            || isset( $parts['pass'] )
            || isset( $parts['query'] )
            || isset( $parts['fragment'] )
        ) {
            throw new \InvalidArgumentException( 'base_url' );
        }

        return( rtrim( $base_url, '/' ) );
    }

    protected function buildExportBasename( string $scope, string $slug ) : string {
        return( 'tinymash-static-' . $scope . '-' . $this->normalizeSlug( $slug ) . '-' . gmdate( 'Ymd-His' ) );
    }

    protected function normalizeSlug( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_ -]+/', '', $value ) ?? '';
        $value = str_replace( ' ', '-', $value );
        return( trim( $value, '-_' ) );
    }

    protected function normalizeAuthorSlug( string $value ) : string {
        return( $this->content_repository->normalizeAuthorSlug( $value ) );
    }

    protected function normalizeAuthorSlugList( mixed $values ) : array {
        if ( is_string( $values ) ) {
            $values = preg_split( '/[\s,]+/', $values ) ?: [];
        }
        if ( ! is_array( $values ) ) {
            return( [] );
        }

        $slugs = [];
        foreach ( $values as $value ) {
            $slug = $this->normalizeAuthorSlug( (string) $value );
            if ( $slug !== '' ) {
                $slugs[$slug] = true;
            }
        }

        return( array_keys( $slugs ) );
    }

    protected function normalizeTagSlug( string $tag ) : string {
        $tag = ltrim( trim( strtolower( $tag ) ), '#' );
        $tag = preg_replace( '/\s+/u', '-', $tag ) ?? '';
        $tag = preg_replace( '/[^a-z0-9_-]+/', '-', $tag ) ?? '';
        $tag = preg_replace( '/-+/', '-', $tag ) ?? '';
        return( trim( $tag, '-_' ) );
    }

    protected function relativePath( string $from_directory, string $to_path ) : string {
        $from_directory = trim( str_replace( '\\', '/', $from_directory ), '/' );
        if ( $from_directory === '.' ) {
            $from_directory = '';
        }
        $from_parts = array_values( array_filter( explode( '/', $from_directory ), static fn( string $part ) : bool => $part !== '' && $part !== '.' ) );
        $to_parts = array_values( array_filter( explode( '/', trim( str_replace( '\\', '/', $to_path ), '/' ) ), static fn( string $part ) : bool => $part !== '' ) );
        while ( $from_parts !== [] && $to_parts !== [] && $from_parts[0] === $to_parts[0] ) {
            array_shift( $from_parts );
            array_shift( $to_parts );
        }

        $relative = array_merge( array_fill( 0, count( $from_parts ), '..' ), $to_parts );
        return( $relative === [] ? './' : implode( '/', $relative ) );
    }

    protected function pathIsContained( string $path, string $root ) : bool {
        $root_real = realpath( $root );
        $path_real = realpath( $path );
        if ( ! is_string( $root_real ) || ! is_string( $path_real ) ) {
            return( false );
        }

        $root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        return( $path_real === $root_real || str_starts_with( $path_real, $root_prefix ) );
    }

    protected function prepareTargetDirectory( string $target_directory ) : bool {
        $target_directory = rtrim( trim( $target_directory ), DIRECTORY_SEPARATOR );
        if ( $target_directory === '' || is_link( $target_directory ) ) {
            throw new \InvalidArgumentException( 'target_directory' );
        }
        if ( file_exists( $target_directory ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'The static export destination is not a directory.' );
        }
        if ( is_dir( $target_directory ) ) {
            $items = scandir( $target_directory );
            if ( ! is_array( $items ) || array_values( array_diff( $items, [ '.', '..' ] ) ) !== [] ) {
                throw new \RuntimeException( 'The static export destination must be empty.' );
            }
            return( false );
        }

        $this->ensureDirectory( $target_directory );
        return( true );
    }

    protected function cleanFailedExport( string $target_directory, bool $remove_target ) : void {
        if ( ! is_dir( $target_directory ) || is_link( $target_directory ) ) {
            return;
        }

        $items = scandir( $target_directory );
        foreach ( is_array( $items ) ? $items : [] as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $target_directory . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->cleanFailedExport( $path, true );
                continue;
            }
            @ unlink( $path );
        }
        if ( $remove_target ) {
            @ rmdir( $target_directory );
        }
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            if ( ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
                throw new \RuntimeException( 'Unable to create static export directory.' );
            }
        }
    }
}
