<?php
namespace app\classes;

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
        $author_slug = $this->normalizeSlug( $author_slug );
        $owner_username = $this->normalizeSlug( $owner_username );
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
        $owner_username = $this->normalizeSlug( $owner_username );
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
                    'excluded_authors' => $this->normalizeSlugList( $options['exclude_authors'] ?? [] ),
                    'retention_seconds' => 21600,
                ],
                function( string $bundle_directory ) use ( $options ) : array {
                    return( $this->exportSiteToDirectory( $bundle_directory, $options ) );
                }
            )
        );
    }

    public function exportAuthorToDirectory( string $author_slug, string $target_directory, array $options = [] ) : array {
        $author_slug = $this->normalizeSlug( $author_slug );
        if ( $author_slug === '' || ! $this->authorCanBeExported( $author_slug ) ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        $this->resetExportState();
        $this->ensureDirectory( $target_directory );
        $routes = $this->buildAuthorRoutePlan( $author_slug );
        $this->writeRoutes( $routes, $target_directory, $this->resolveBaseUrl( $options ) );
        return( $this->writeReport( $target_directory, 'author', [ $author_slug ], [], $routes, $options ) );
    }

    public function exportSiteToDirectory( string $target_directory, array $options = [] ) : array {
        $this->resetExportState();
        $this->ensureDirectory( $target_directory );
        $excluded_authors = $this->normalizeSlugList( $options['exclude_authors'] ?? [] );
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

        $this->writeRoutes( $routes, $target_directory, $this->resolveBaseUrl( $options ) );
        return( $this->writeReport( $target_directory, 'site', $included_authors, $excluded_authors, $routes, $options ) );
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
            if ( ! is_array( $route ) ) {
                continue;
            }

            $path = (string) ( $route['path'] ?? '/' );
            $html = $this->renderRoute( $route );
            $html = $this->rewriteHtmlForStaticExport( $html, $path, $target_directory, $base_url );
            $filename = $target_directory . DIRECTORY_SEPARATOR . $this->getStaticFilenameForPath( $path );
            $this->ensureDirectory( dirname( $filename ) );
            file_put_contents( $filename, $html );
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
        $rendered_landing_entry = is_array( $landing_entry ) ? $this->content_renderer->renderEntry( $landing_entry, $this->theme ) : null;
        $view_data = array_merge(
            $this->theme->getBaseViewData( 'Home', null, null ),
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
        $author_slug = $this->normalizeSlug( $author_slug );
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
            $this->theme->getBaseViewData( $author_display_name !== '' ? $author_display_name : ucfirst( $author_slug ), null, $author_slug ),
            $this->buildThemeListingViewData( $rendered_posts, $author_slug ),
            [
                'author_slug' => $author_slug,
                'author_display_name' => $author_display_name,
                'author_url' => $this->theme->getAuthorURL( $author_slug ),
                'posts' => $rendered_posts,
                'listing_pagination' => $this->buildPaginationViewData( is_array( $author_home['pagination'] ?? null ) ? $author_home['pagination'] : [], $this->theme->getAuthorURL( $author_slug ), $page ),
                'author_home_can_edit' => false,
                'author_home_edit_url' => '',
                'docs_landing_entry' => is_array( $docs_landing_entry ) ? $this->content_renderer->renderEntry( $docs_landing_entry, $this->theme ) : null,
            ]
        );

        return( $this->renderTemplate( $this->theme->getTemplate( 'author-home' ), $view_data, '/' . $author_slug . ( $page > 1 ? '?page=' . $page : '' ) ) );
    }

    protected function renderEntry( array $entry ) : string {
        if ( empty( $entry ) ) {
            throw new \InvalidArgumentException( 'entry' );
        }

        $author_slug = (string) ( $entry['scope'] ?? '' ) === 'author' ? $this->normalizeSlug( (string) ( $entry['author_slug'] ?? '' ) ) : '';
        $this->applyAuthorThemeContext( $author_slug );
        $tag_scope = $author_slug !== '' ? 'author' : 'root';
        $rendered_entry = $this->decorateRenderedEntryWithTagLinks(
            $this->content_renderer->renderEntry( $entry, $this->theme ),
            $tag_scope,
            $author_slug
        );
        $view_data = array_merge(
            $this->theme->getBaseViewData( $rendered_entry['title'], $entry, $author_slug !== '' ? $author_slug : null ),
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
            $author_slug = $this->normalizeSlug( $author_slug );
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
                        $this->theme->getBaseViewData( $author_display_name !== '' ? $author_display_name : ucfirst( $author_slug ), null, $author_slug ),
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
                    $this->theme->getBaseViewData( 'Tagged ' . $tag_label, null, null ),
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
        $author_slug = $this->normalizeSlug( $author_slug );
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

        $this->rewriteMetadataBaseUrls( $document, $base_url );

        foreach ( [ 'a' => 'href', 'link' => 'href', 'script' => 'src', 'img' => 'src', 'source' => 'src', 'video' => 'src', 'audio' => 'src' ] as $tag => $attribute ) {
            foreach ( iterator_to_array( $document->getElementsByTagName( $tag ) ) as $node ) {
                if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( $attribute ) ) {
                    continue;
                }

                $url = trim( $node->getAttribute( $attribute ) );
                $rewritten = $this->rewriteUrl( $url, $source_path, $target_directory, $base_url );
                if ( $rewritten !== $url ) {
                    $node->setAttribute( $attribute, $rewritten );
                }
            }
        }

        return( $document->saveHTML() ?: $html );
    }

    protected function rewriteMetadataBaseUrls( \DOMDocument $document, string $base_url ) : void {
        $configured_base_url = rtrim( trim( (string) $this->config->configGetBaseURL() ), '/' );
        $base_url = rtrim( trim( $base_url ), '/' );
        if ( $configured_base_url === '' || $base_url === '' || $configured_base_url === $base_url ) {
            return;
        }

        foreach ( iterator_to_array( $document->getElementsByTagName( 'link' ) ) as $node ) {
            if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'href' ) ) {
                continue;
            }
            $rel = strtolower( trim( $node->getAttribute( 'rel' ) ) );
            if ( ! in_array( $rel, [ 'canonical', 'alternate' ], true ) ) {
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
            if ( ! in_array( $property, [ 'og:url', 'og:image' ], true ) && ! in_array( $name, [ 'twitter:url', 'twitter:image' ], true ) ) {
                continue;
            }
            $node->setAttribute( 'content', $this->replaceConfiguredBaseUrl( $node->getAttribute( 'content' ), $configured_base_url, $base_url ) );
        }
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
        if ( $this->copyPublicAssetIfAvailable( $public_path, $target_directory ) ) {
            return( $this->relativePath( $source_dir, ltrim( $public_path, '/' ) ) . $query . $fragment );
        }

        return( $this->relativePath( $source_dir, $this->getStaticFilenameForPath( $public_path . $query ) ) . $fragment );
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
            copy( $source, $target );
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
        $author_slug = $this->normalizeSlug( $author_slug );
        return( $author_slug !== '' && $this->content_repository->hasPublicAuthor( $author_slug ) && $this->user_repository->isAuthorContentPublic( $author_slug ) );
    }

    protected function applyAuthorThemeContext( string $author_slug ) : void {
        $author_slug = $this->normalizeSlug( $author_slug );
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

    protected function writeReport( string $target_directory, string $scope, array $included_authors, array $excluded_authors, array $routes, array $options ) : array {
        $report = [
            'scope' => $scope,
            'generated_at_utc' => gmdate( DATE_ATOM ),
            'base_url' => $this->resolveBaseUrl( $options ),
            'route_count' => count( $routes ),
            'asset_count' => count( $this->copied_assets ),
            'included_authors' => array_values( $included_authors ),
            'excluded_authors' => array_values( $excluded_authors ),
            'warnings' => array_values( array_unique( $this->warnings ) ),
            'dynamic_policy' => 'Rendered dynamic plugin and shortcode output is snapshotted at export time.',
        ];
        file_put_contents( rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'export-report.json', json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL );
        return( $report );
    }

    protected function resetExportState() : void {
        $this->warnings = [];
        $this->copied_assets = [];
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

    protected function normalizeSlugList( mixed $values ) : array {
        if ( is_string( $values ) ) {
            $values = preg_split( '/[\s,]+/', $values ) ?: [];
        }
        if ( ! is_array( $values ) ) {
            return( [] );
        }

        $slugs = [];
        foreach ( $values as $value ) {
            $slug = $this->normalizeSlug( (string) $value );
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

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }
    }
}
