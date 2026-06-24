<?php
namespace app\controllers;

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashTheme;

require_once dirname( __FILE__ ) . '/BaseController.php';
require_once dirname( __FILE__, 2 ) . '/classes/TinyMashConfig.php';
require_once dirname( __FILE__, 2 ) . '/classes/TinyMashContentRepository.php';
require_once dirname( __FILE__, 2 ) . '/classes/TinyMashContentRenderer.php';
require_once dirname( __FILE__, 2 ) . '/classes/TinyMashPublicPageCache.php';
require_once dirname( __FILE__, 2 ) . '/classes/TinyMashTheme.php';

class ContentController extends BaseController {

    protected const PUBLIC_POSTS_PER_PAGE = 10;

    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashContentRenderer $content_renderer;
    protected TinyMashTheme $theme;
    protected array $server_timing = [];

    public function __construct( $app, $router, TinyMashConfig $config, TinyMashContentRepository $content_repository, TinyMashContentRenderer $content_renderer, TinyMashTheme $theme ) {
        parent::__construct( $app, $router );
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->content_renderer = $content_renderer;
        $this->theme = $theme;
    }

    public function home() : void {
        $request_started_at = microtime( true );
        if ( ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        if ( $this->redirectExplicitDocsMatterLandingPage( 'root', '' ) ) {
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $page = $this->getPublicListingPage();
        $lookup_started_at = microtime( true );
        $aggregated_page = $this->content_repository->getPublishedAggregatedPostsPage( $this->config->getDefaultLanguage(), $page, self::PUBLIC_POSTS_PER_PAGE );
        $this->recordServerTiming( 'lookup', $lookup_started_at );
        $entries = $this->filterPublicEntries( is_array( $aggregated_page['items'] ?? null ) ? $aggregated_page['items'] : [] );
        $pagination = $this->buildPublicPaginationViewData( is_array( $aggregated_page['pagination'] ?? null ) ? $aggregated_page['pagination'] : [], '/' );
        $render_entries_started_at = microtime( true );
        $rendered_entries = $this->content_renderer->renderEntrySummaries( $entries, $this->theme );
        $this->recordServerTiming( 'render_entries', $render_entries_started_at );
        $this->recordRendererProfilingMetrics();
        $landing_entry = $this->resolveThemeLandingEntry( 'root', '' );
        $render_landing_started_at = microtime( true );
        $rendered_landing_entry = is_array( $landing_entry ) ? $this->content_renderer->renderEntry( $landing_entry, $this->theme ) : null;
        $this->recordServerTiming( 'render_landing', $render_landing_started_at );
        $this->recordServerTiming( 'controller', $request_started_at );
        $this->renderPublicView(
            $this->theme->getTemplate( 'home' ),
            array_merge(
                $this->theme->getBaseViewData( 'Home', null, null ),
                $this->buildBlocksListingViewData( $rendered_entries ),
                $this->buildPanelMagazineListingViewData( $rendered_entries ),
                [
                    'entries' => $rendered_entries,
                    'listing_pagination' => $pagination,
                    'landing_entry' => $rendered_landing_entry,
                    'landing_entry_child_pages' => is_array( $landing_entry ) ? $this->theme->getChildPages( $landing_entry ) : [],
                    'docs_landing_entry' => $rendered_landing_entry,
                ]
            )
        );
    }

    public function pathRoot( string $segment ) : void {
        if ( $this->content_repository->hasPublicAuthor( $segment ) ) {
            if ( ! $this->canRenderAuthorSpace( $segment ) ) {
                $this->renderSitePrivateNotFound();
                return;
            }
            $this->applyAuthorThemeContext( $segment );
            if ( $this->redirectExplicitDocsMatterLandingPage( 'author', $segment ) ) {
                return;
            }
            if ( $this->renderCachedPublicPageIfAvailable() ) {
                return;
            }

            $author_home_lookup_started_at = microtime( true );
            $author_home = $this->content_repository->getAuthorHomePage( $segment, $this->config->getDefaultLanguage(), $this->getPublicListingPage(), self::PUBLIC_POSTS_PER_PAGE );
            $this->recordServerTiming( 'lookup', $author_home_lookup_started_at );
            if ( is_array( $author_home ) ) {
                $this->renderAuthorHome( $author_home );
                return;
            }
            $this->theme->clearThemeKeyOverride();
        }

        if ( ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $entry_lookup_started_at = microtime( true );
        $entry = $this->content_repository->getRootEntryByPath( $segment, $this->config->getDefaultLanguage() );
        $this->recordServerTiming( 'lookup', $entry_lookup_started_at );
        if ( ! is_array( $entry ) ) {
            $this->app->notFound();
            return;
        }

        $this->renderEntry( $entry );
    }

    public function pathTwoSegments( string $first, string $second ) : void {
        if ( $this->content_repository->hasPublicAuthor( $first ) ) {
            if ( ! $this->canRenderAuthorSpace( $first ) ) {
                $this->renderSitePrivateNotFound();
                return;
            }
            $this->applyAuthorThemeContext( $first );
            if ( $this->renderCachedPublicPageIfAvailable() ) {
                return;
            }
            $author_entry_lookup_started_at = microtime( true );
            $author_entry = $this->content_repository->getAuthorEntryByPath( $first, $second, $this->config->getDefaultLanguage() );
            $this->recordServerTiming( 'lookup', $author_entry_lookup_started_at );
            if ( is_array( $author_entry ) ) {
                $this->renderEntry( $author_entry );
                return;
            }

            $this->app->notFound();
            return;
        }

        if ( ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $root_entry_lookup_started_at = microtime( true );
        $root_entry = $this->content_repository->getRootEntryByPath( $first . '/' . $second, $this->config->getDefaultLanguage() );
        $this->recordServerTiming( 'lookup', $root_entry_lookup_started_at );
        if ( is_array( $root_entry ) ) {
            $this->renderEntry( $root_entry );
            return;
        }

        $this->app->notFound();
    }

    public function rootTag( string $tag ) : void {
        if ( ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        if ( ! $this->theme->areTagsEnabledForContext() ) {
            $this->app->notFound();
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $normalized_tag === '' ) {
            $this->app->notFound();
            return;
        }

        if ( $normalized_tag !== trim( strtolower( $tag ) ) ) {
            $this->app->redirect( '/tags/' . rawurlencode( $normalized_tag ) );
            return;
        }

        $lookup_started_at = microtime( true );
        $tag_page = $this->content_repository->getRootTagPage( $normalized_tag, $this->config->getDefaultLanguage(), $this->getPublicListingPage(), self::PUBLIC_POSTS_PER_PAGE );
        $this->recordServerTiming( 'lookup', $lookup_started_at );
        if ( ! is_array( $tag_page ) ) {
            $this->app->notFound();
            return;
        }

        $this->renderTagListing( $normalized_tag, $tag_page, 'root', '' );
    }

    public function authorTag( string $author_slug, string $tag ) : void {
        if ( ! $this->canRenderAuthorSpace( $author_slug ) ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        $this->applyAuthorThemeContext( $author_slug );
        if ( ! $this->theme->areTagsEnabledForContext( $author_slug ) ) {
            $this->app->notFound();
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $normalized_author_slug = strtolower( trim( $author_slug ) );
        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $normalized_author_slug === '' || $normalized_tag === '' ) {
            $this->app->notFound();
            return;
        }

        if ( $normalized_tag !== trim( strtolower( $tag ) ) ) {
            $this->app->redirect( '/' . rawurlencode( $normalized_author_slug ) . '/tags/' . rawurlencode( $normalized_tag ) );
            return;
        }

        $lookup_started_at = microtime( true );
        $tag_page = $this->content_repository->getAuthorTagPage( $normalized_author_slug, $normalized_tag, $this->config->getDefaultLanguage(), $this->getPublicListingPage(), self::PUBLIC_POSTS_PER_PAGE );
        $this->recordServerTiming( 'lookup', $lookup_started_at );
        if ( ! is_array( $tag_page ) ) {
            $this->app->notFound();
            return;
        }

        $this->renderTagListing( $normalized_tag, $tag_page, 'author', $normalized_author_slug );
    }

    public function legacyRootPost( string $slug ) : void {
        if ( ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }
        if ( $this->renderCachedPublicPageIfAvailable() ) {
            return;
        }

        $entry_lookup_started_at = microtime( true );
        $entry = $this->content_repository->getRootPostBySlug( $slug, $this->config->getDefaultLanguage() );
        $this->recordServerTiming( 'lookup', $entry_lookup_started_at );
        if ( ! is_array( $entry ) ) {
            $this->app->notFound();
            return;
        }

        $this->renderEntry( $entry );
    }

    protected function renderAuthorHome( array $author_home ) : void {
        $request_started_at = microtime( true );
        $author_slug = (string) ( $author_home['author_slug'] ?? '' );
        $author_display_name = $this->theme->getAuthorDisplayLabel( $author_slug );
        $pagination = $this->buildPublicPaginationViewData(
            is_array( $author_home['pagination'] ?? null ) ? $author_home['pagination'] : [],
            $this->theme->getAuthorURL( $author_slug )
        );
        $render_posts_started_at = microtime( true );
        $rendered_posts = $this->decorateRenderedEntriesWithTagLinks(
            $this->content_renderer->renderEntrySummaries( $author_home['posts'], $this->theme ),
            'author',
            $author_slug
        );
        $this->recordServerTiming( 'render_entries', $render_posts_started_at );
        $this->recordRendererProfilingMetrics();
        $render_landing_started_at = microtime( true );
        $rendered_docs_landing_entry = ( function () use ( $author_slug ) {
            $docs_landing_entry = $this->resolveDocsMatterLandingEntry( 'author', $author_slug );
            return( is_array( $docs_landing_entry ) ? $this->content_renderer->renderEntry( $docs_landing_entry, $this->theme ) : null );
        } )();
        $this->recordServerTiming( 'render_landing', $render_landing_started_at );
        $view_data = array_merge(
            $this->theme->getBaseViewData( $author_display_name !== '' ? $author_display_name : ucfirst( $author_slug ), null, $author_slug ),
            $this->buildBlocksListingViewData( $rendered_posts, $author_slug ),
            $this->buildPanelMagazineListingViewData( $rendered_posts, $author_slug ),
            [
                'author_slug' => $author_slug,
                'author_display_name' => $author_display_name,
                'author_url' => $this->theme->getAuthorURL( $author_slug ),
                'posts' => $rendered_posts,
                'listing_pagination' => $pagination,
                'author_home_can_edit' => $this->currentVisitorCanEditAuthorHome( $author_slug ),
                'author_home_edit_url' => $this->buildAuthorHomeEditUrl( $author_slug ),
                'docs_landing_entry' => $rendered_docs_landing_entry,
            ]
        );
        $view_data = $this->applyPrivateAuthorSpaceViewRestrictions( $view_data, $author_slug );
        $this->recordServerTiming( 'controller', $request_started_at );
        $this->renderPublicView( $this->theme->getTemplate( 'author-home' ), $view_data );
    }

    protected function renderEntry( array $entry ) : void {
        $request_started_at = microtime( true );
        $render_entry_started_at = microtime( true );
        $tag_scope = (string) ( $entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
        $tag_author_slug = $tag_scope === 'author' ? (string) ( $entry['author_slug'] ?? '' ) : '';
        $rendered_entry = $this->decorateRenderedEntryWithTagLinks(
            $this->content_renderer->renderEntry( $entry, $this->theme ),
            $tag_scope,
            $tag_author_slug
        );
        $this->recordServerTiming( 'render_entry', $render_entry_started_at );
        $navigation_started_at = microtime( true );
        $entry_navigation = $this->buildEntryNavigationViewData( $entry );
        $this->recordServerTiming( 'navigation', $navigation_started_at );
        $view_data = array_merge(
            $this->theme->getBaseViewData( $rendered_entry['title'], $entry, (string) ( $entry['scope'] ?? '' ) === 'author' ? (string) ( $entry['author_slug'] ?? '' ) : null ),
            [
                'entry' => $rendered_entry,
                'entry_breadcrumbs' => $this->theme->getEntryBreadcrumbs( $entry ),
                'entry_child_pages' => $this->theme->getChildPages( $entry ),
                'entry_navigation' => $entry_navigation,
                'docs_page_navigation' => (string) ( $entry['type'] ?? '' ) === 'page' && $this->theme->getThemeKey() === 'docsmatter'
                    ? $this->theme->getDocsPageNavigation( $entry, $this->config->getDefaultLanguage(), $this->theme->getThemeSettingsForAuthorContext( (string) ( $entry['scope'] ?? '' ) === 'author' ? (string) ( $entry['author_slug'] ?? '' ) : '' ) )
                    : [ 'home_url' => '', 'previous' => null, 'next' => null ],
                'entry_can_edit' => $this->currentVisitorCanEditEntry( $entry ),
                'entry_edit_url' => $this->buildEntryEditUrl( $entry ),
            ]
        );
        if ( (string) ( $entry['scope'] ?? '' ) === 'author' ) {
            $view_data = $this->applyPrivateAuthorSpaceViewRestrictions( $view_data, (string) ( $entry['author_slug'] ?? '' ) );
        }
        $this->recordServerTiming( 'controller', $request_started_at );
        $this->renderPublicView( $this->theme->getTemplateForEntry( $entry ), $view_data );
    }

    protected function renderTagListing( string $tag, array $tag_page, string $scope = 'root', string $author_slug = '' ) : void {
        $request_started_at = microtime( true );
        $base_path = $scope === 'author'
            ? '/' . rawurlencode( $author_slug ) . '/tags/' . rawurlencode( $tag )
            : '/tags/' . rawurlencode( $tag );
        $pagination = $this->buildPublicPaginationViewData(
            is_array( $tag_page['pagination'] ?? null ) ? $tag_page['pagination'] : [],
            $base_path
        );
        $render_entries_started_at = microtime( true );
        $rendered_entries = $this->decorateRenderedEntriesWithTagLinks(
            $this->content_renderer->renderEntrySummaries( is_array( $tag_page['items'] ?? null ) ? $tag_page['items'] : [], $this->theme ),
            $scope,
            $author_slug
        );
        $this->recordServerTiming( 'render_entries', $render_entries_started_at );
        $this->recordRendererProfilingMetrics();

        $tag_label = '#' . strtolower( $tag );
        $listing_summary = $scope === 'author'
            ? 'Published posts and pages tagged ' . $tag_label . ' in /' . $author_slug . '.'
            : 'Published root-visible content tagged ' . $tag_label . '.';
        $listing_empty_message = $scope === 'author'
            ? 'No published posts or pages with that tag were found in this author space.'
            : 'No published root-visible content with that tag was found.';
        $this->recordServerTiming( 'controller', $request_started_at );

        if ( $scope === 'author' ) {
            $author_display_name = $this->theme->getAuthorDisplayLabel( $author_slug );
            $this->renderPublicView(
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
                        'listing_context_summary' => $listing_summary,
                        'listing_empty_message' => $listing_empty_message,
                        'listing_pagination' => $pagination,
                        'force_listing_results' => true,
                    ]
                )
            );
            return;
        }

        $this->renderPublicView(
            $this->theme->getTemplate( 'home' ),
            array_merge(
                $this->theme->getBaseViewData( 'Tagged ' . $tag_label, null, null ),
                [
                    'entries' => $rendered_entries,
                    'listing_context_title' => 'Tagged ' . $tag_label,
                    'listing_context_summary' => $listing_summary,
                    'listing_empty_message' => $listing_empty_message,
                    'listing_pagination' => $pagination,
                    'landing_entry' => null,
                    'landing_entry_child_pages' => [],
                    'docs_landing_entry' => null,
                    'force_listing_results' => true,
                ]
            )
        );
    }

    public function resolveSecretLink( string $token ) : void {
        $secret_link_service = $this->getSecretLinkService();
        if ( ! is_object( $secret_link_service ) || ! method_exists( $secret_link_service, 'grantCurrentVisitorToken' ) ) {
            $this->renderSitePrivateNotFound();
            return;
        }

        $record = $secret_link_service->grantCurrentVisitorToken( $token );
        if ( ! is_array( $record ) ) {
            $this->renderSitePrivateNotFound();
            return;
        }

        $author_slug = strtolower( trim( (string) ( $record['author_slug'] ?? '' ) ) );
        if ( ! $this->isAuthorContentAccessible( $author_slug ) ) {
            $this->renderSitePrivateNotFound();
            return;
        }

        $this->app->redirect( '/' . rawurlencode( $author_slug ) );
    }

    protected function canRenderRootSpace() : bool {
        if ( $this->config->isSitePublic() ) {
            return( true );
        }

        return( $this->isAuthenticatedPublicVisitor() );
    }

    protected function canRenderAuthorSpace( string $author_slug ) : bool {
        if ( ! $this->isAuthorContentAccessible( $author_slug ) ) {
            return( false );
        }
        if ( $this->canRenderRootSpace() ) {
            return( true );
        }

        $secret_link_service = $this->getSecretLinkService();
        return(
            is_object( $secret_link_service )
            && method_exists( $secret_link_service, 'currentVisitorCanAccessAuthor' )
            && (bool) $secret_link_service->currentVisitorCanAccessAuthor( $author_slug )
        );
    }

    protected function isAuthorContentAccessible( string $author_slug ) : bool {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( true );
        }
        if ( str_starts_with( $author_slug, '_deleted_' ) ) {
            return( false );
        }

        if ( $this->app->has( 'user.repository' ) ) {
            $user_repository = $this->app->get( 'user.repository' );
            if ( is_object( $user_repository ) && method_exists( $user_repository, 'isAuthorContentPublic' ) ) {
                return( (bool) $user_repository->isAuthorContentPublic( $author_slug ) );
            }
        }

        return( true );
    }

    protected function filterPublicEntries( array $entries ) : array {
        $visible_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( ! empty( $entry['scope'] ) && $entry['scope'] === 'author' && ! $this->isAuthorContentAccessible( (string) ( $entry['author_slug'] ?? '' ) ) ) {
                continue;
            }
            $visible_entries[] = $entry;
        }

        return( $visible_entries );
    }

    protected function buildEntryNavigationViewData( array $entry ) : array {
        if ( ! $this->content_repository instanceof TinyMashContentRepository || ! $this->theme instanceof TinyMashTheme ) {
            return( [] );
        }

        if ( strtolower( trim( (string) ( $entry['type'] ?? 'post' ) ) ) !== 'post' ) {
            return( [] );
        }

        $navigation_entries = $this->content_repository->getEntryNavigationEntries( $entry, $this->config->getDefaultLanguage() );
        $back_url = (string) ( ( $entry['scope'] ?? '' ) === 'author'
            ? $this->theme->getAuthorURL( (string) ( $entry['author_slug'] ?? '' ) )
            : '/' );
        $back_label = 'Back to posts';

        $view_data = [
            'back_url' => $back_url,
            'back_label' => $back_label,
            'previous' => null,
            'next' => null,
        ];

        $previous_entry = is_array( $navigation_entries['previous'] ?? null ) ? $navigation_entries['previous'] : null;
        if ( is_array( $previous_entry ) ) {
            $view_data['previous'] = [
                'title' => (string) ( $previous_entry['title'] ?? 'Previous entry' ),
                'url' => $this->theme->getEntryURL( $previous_entry ),
            ];
        }

        $next_entry = is_array( $navigation_entries['next'] ?? null ) ? $navigation_entries['next'] : null;
        if ( is_array( $next_entry ) ) {
            $view_data['next'] = [
                'title' => (string) ( $next_entry['title'] ?? 'Next entry' ),
                'url' => $this->theme->getEntryURL( $next_entry ),
            ];
        }

        return( $view_data );
    }

    protected function resolveThemeLandingEntry( string $scope = 'root', string $author_slug = '' ) : ?array {
        $scope = strtolower( trim( $scope ) );
        $theme_settings = $this->theme->getThemeSettingsForAuthorContext( $scope === 'author' ? $author_slug : '' );
        if ( $scope === 'root' && $this->theme->getRootLandingMode( $theme_settings ) !== 'page' ) {
            return( null );
        }

        return( $this->theme->getLandingEntry( $scope, $author_slug, $this->config->getDefaultLanguage(), $theme_settings ) );
    }

    protected function applyAuthorThemeContext( string $author_slug ) : void {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            $this->theme->clearThemeKeyOverride();
            return;
        }

        $this->theme->setThemeKeyOverride( $this->theme->resolveThemeKeyForAuthorContext( $author_slug ) );
    }

    protected function resolveDocsMatterLandingEntry( string $scope = 'root', string $author_slug = '' ) : ?array {
        if ( $this->theme->getThemeKey() !== 'docsmatter' ) {
            return( null );
        }

        return( $this->resolveThemeLandingEntry( $scope, $author_slug ) );
    }

    protected function redirectExplicitDocsMatterLandingPage( string $scope = 'root', string $author_slug = '' ) : bool {
        if ( $this->theme->getThemeKey() !== 'docsmatter' ) {
            return( false );
        }

        $scope = strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root';
        $author_slug = $scope === 'author' ? strtolower( trim( $author_slug ) ) : '';
        $theme_settings = $this->theme->getThemeSettingsForAuthorContext( $scope === 'author' ? $author_slug : '' );
        if ( $this->theme->getLandingPagePath( $theme_settings ) === '' ) {
            return( false );
        }

        $landing_entry = $this->theme->getLandingEntry( $scope, $author_slug, $this->config->getDefaultLanguage(), $theme_settings );
        if ( ! is_array( $landing_entry ) ) {
            return( false );
        }

        $landing_url = $this->theme->getEntryURL( $landing_entry );
        if ( $landing_url === '' || $landing_url === '/' || ( $scope === 'author' && $landing_url === $this->theme->getAuthorURL( $author_slug ) ) ) {
            return( false );
        }

        $this->app->redirect( $landing_url );
        return( true );
    }

    protected function currentVisitorCanEditEntry( array $entry ) : bool {
        $visitor_context = $this->getAuthenticatedPublicVisitorContext();
        if ( ! is_array( $visitor_context ) ) {
            return( false );
        }
        if ( strtolower( trim( (string) ( $visitor_context['role'] ?? '' ) ) ) === 'superadmin' ) {
            return( true );
        }

        if ( (string) ( $entry['scope'] ?? '' ) !== 'author' || empty( $entry['author_slug'] ) ) {
            return( false );
        }

        return(
            strtolower( trim( (string) ( $visitor_context['username'] ?? '' ) ) )
            === strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) )
        );
    }

    protected function currentVisitorCanEditAuthorHome( string $author_slug ) : bool {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( false );
        }

        $visitor_context = $this->getAuthenticatedPublicVisitorContext();
        if ( ! is_array( $visitor_context ) ) {
            return( false );
        }

        return( strtolower( trim( (string) ( $visitor_context['username'] ?? '' ) ) ) === $author_slug );
    }

    protected function buildAuthorHomeEditUrl( string $author_slug ) : string {
        if ( ! $this->currentVisitorCanEditAuthorHome( $author_slug ) ) {
            return( '' );
        }

        return( rtrim( (string) $this->config->configGetAdminURL(), '/' ) . '/profile/appearance' );
    }

    protected function buildEntryEditUrl( array $entry ) : string {
        $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
        if ( $entry_id === '' ) {
            return( '' );
        }

        return( rtrim( (string) $this->config->configGetAdminURL(), '/' ) . '/editor?entry=' . rawurlencode( $entry_id ) );
    }

    protected function renderSitePrivateNotFound() : void {
        $this->app->response()->status( 404 );
        $this->render(
            $this->theme->getTemplate( '404' ),
            array_merge(
                $this->theme->getBaseViewData( 'Page not found', null, null ),
                [
                    'title' => 'Page not found',
                    'message' => 'This site is not public. Log in to view content.',
                    'login_url' => $this->config->configGetLoginURL(),
                    'theme_show_public_chrome' => false,
                    'theme_sidebar_enabled' => false,
                    'theme_navigation_menu' => [],
                    'theme_page_structure' => [],
                ]
            )
        );
    }

    protected function buildBlocksListingViewData( array $entries, string $author_slug = '' ) : array {
        if ( $this->theme->getThemeKey() !== 'blocks' ) {
            return( [] );
        }

        return(
            $this->theme->getBlocksListingViewData(
                $entries,
                $this->theme->getThemeSettingsForAuthorContext( $author_slug )
            )
        );
    }

    protected function buildPanelMagazineListingViewData( array $entries, string $author_slug = '' ) : array {
        if ( $this->theme->getThemeKey() !== 'panel-magazine' ) {
            return( [] );
        }

        return(
            $this->theme->getPanelMagazineListingViewData(
                $entries,
                $this->theme->getThemeSettingsForAuthorContext( $author_slug )
            )
        );
    }

    public function media( string $owner_username, string $year, string $month, string $filename ) : void {
        $media_service = $this->app->has( 'media.service' ) ? $this->app->get( 'media.service' ) : null;
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'resolveMediaPath' ) ) {
            $this->app->notFound();
            return;
        }

        $media = $media_service->resolveMediaPath( $owner_username, $year, $month, $filename );
        if ( ! is_array( $media ) ) {
            $this->app->notFound();
            return;
        }

        if ( $owner_username === 'root' && ! $this->canRenderRootSpace() ) {
            $this->renderSitePrivateNotFound();
            return;
        }

        if ( $owner_username !== 'root' && ! $this->canRenderAuthorSpace( $owner_username ) ) {
            $this->renderSitePrivateNotFound();
            return;
        }

        header( 'Content-Type: ' . $media['mime'] );
        header( 'X-Content-Type-Options: nosniff' );
        if ( (string) ( $media['mime'] ?? '' ) === 'image/svg+xml' ) {
            header( "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" );
        }
        header( 'Content-Length: ' . (string) filesize( $media['path'] ) );
        header( 'Cache-Control: public, max-age=3600' );
        readfile( $media['path'] );
    }

    public function themeCustomCss( string $theme_key ) : void {
        $theme_key = strtolower( trim( $theme_key ) );
        $theme_key = preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?? '';
        if ( $theme_key === '' ) {
            $this->app->notFound();
            return;
        }

        $custom_css = $this->theme->getCustomCssSettings( $theme_key );
        $css = (string) ( $custom_css['css'] ?? '' );
        if ( empty( $custom_css['enabled'] ) || trim( $css ) === '' ) {
            $this->app->notFound();
            return;
        }

        $etag = '"' . sha1( $theme_key . "\n" . $css ) . '"';
        if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $etag ) {
            $this->app->response()->status( 304 );
            header( 'ETag: ' . $etag );
            header( 'Cache-Control: public, max-age=3600' );
            return;
        }

        header( 'Content-Type: text/css; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'ETag: ' . $etag );
        echo $css . ( str_ends_with( $css, "\n" ) ? '' : "\n" );
    }

    protected function getSecretLinkService() : ?object {
        if ( ! $this->app->has( 'secret_links' ) ) {
            return( null );
        }

        $secret_link_service = $this->app->get( 'secret_links' );
        return( is_object( $secret_link_service ) ? $secret_link_service : null );
    }

    protected function applyPrivateAuthorSpaceViewRestrictions( array $view_data, string $author_slug ) : array {
        $is_secret_link_view = $this->isPrivateAuthorSpaceSecretLinkView( $author_slug );
        $view_data['theme_secret_link_author_space_view'] = $is_secret_link_view;
        if ( ! $is_secret_link_view ) {
            return( $view_data );
        }

        $view_data['theme_navigation_pages'] = [];
        $view_data['theme_navigation_tree'] = [];
        $view_data['theme_navigation_menu'] = [];
        $view_data['theme_root_page_structure'] = [];
        return( $view_data );
    }

    protected function isPrivateAuthorSpaceSecretLinkView( string $author_slug ) : bool {
        if ( $this->config->isSitePublic() ) {
            return( false );
        }

        if ( $this->isAuthenticatedPublicVisitor() ) {
            return( false );
        }

        $secret_link_service = $this->getSecretLinkService();
        return(
            is_object( $secret_link_service )
            && method_exists( $secret_link_service, 'currentVisitorCanAccessAuthor' )
            && (bool) $secret_link_service->currentVisitorCanAccessAuthor( $author_slug )
        );
    }

    protected function getPublicListingPage() : int {
        $page = isset( $_GET['page'] ) ? (int) $_GET['page'] : 1;
        return( max( 1, $page ) );
    }

    protected function renderPublicView( string $view_name, array $data = [] ) : void {
        $this->applyPublicPageResponseHeaders();
        $public_page_cache = $this->getPublicPageCache();
        if ( ! $this->shouldUsePublicPageCache() || ! $public_page_cache instanceof TinyMashPublicPageCache ) {
            $this->resetShortcodeRequestState();
            $render_started_at = microtime( true );
            $this->render( $view_name, $data );
            $this->recordServerTiming( 'view', $render_started_at );
            $this->emitServerTimingHeader();
            return;
        }

        ob_start();
        try {
            $this->resetShortcodeRequestState();
            $render_started_at = microtime( true );
            $this->render( $view_name, $data );
            $html = (string) ob_get_clean();
            $this->recordServerTiming( 'view', $render_started_at );
        } catch ( \Throwable $e ) {
            ob_end_clean();
            throw $e;
        }

        if ( ! $this->hasDynamicShortcodeRenderForRequest() ) {
            $cache_write_started_at = microtime( true );
            $public_page_cache->write( $this->getCurrentRequestUriForPublicCache(), $this->theme->getThemeKey(), $html );
            $this->recordServerTiming( 'cache_write', $cache_write_started_at );
        }
        $this->emitServerTimingHeader();
        echo $html;
    }

    protected function renderCachedPublicPageIfAvailable() : bool {
        if ( ! $this->shouldUsePublicPageCache() ) {
            return( false );
        }

        $public_page_cache = $this->getPublicPageCache();
        if ( ! $public_page_cache instanceof TinyMashPublicPageCache ) {
            return( false );
        }

        $cache_read_started_at = microtime( true );
        $cached_html = $public_page_cache->read( $this->getCurrentRequestUriForPublicCache(), $this->theme->getThemeKey() );
        if ( ! is_string( $cached_html ) || $cached_html === '' ) {
            $this->recordServerTiming( 'cache_miss', $cache_read_started_at );
            return( false );
        }

        $this->applyPublicPageResponseHeaders();
        $this->recordServerTiming( 'cache_hit', $cache_read_started_at );
        $this->emitServerTimingHeader();
        echo $cached_html;
        return( true );
    }

    protected function recordServerTiming( string $metric_name, float $started_at ) : void {
        $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( $metric_name ) ) ?? '' );
        if ( $metric_name === '' ) {
            return;
        }

        $duration_ms = max( 0, ( microtime( true ) - $started_at ) * 1000 );
        if ( ! isset( $this->server_timing[$metric_name] ) ) {
            $this->server_timing[$metric_name] = 0.0;
        }

        $this->server_timing[$metric_name] += $duration_ms;
    }

    protected function emitServerTimingHeader() : void {
        if ( headers_sent() || empty( $this->server_timing ) ) {
            return;
        }

        $parts = [];
        foreach ( $this->server_timing as $metric_name => $duration_ms ) {
            if ( ! is_numeric( $duration_ms ) ) {
                continue;
            }

            $parts[] = $metric_name . ';dur=' . number_format( max( 0, (float) $duration_ms ), 2, '.', '' );
        }

        if ( ! empty( $parts ) ) {
            header( 'Server-Timing: ' . implode( ', ', $parts ) );
        }
    }

    protected function recordRendererProfilingMetrics() : void {
        if ( ! $this->content_renderer instanceof TinyMashContentRenderer || ! method_exists( $this->content_renderer, 'getProfilingMetrics' ) ) {
            return;
        }

        $profiling_metrics = $this->content_renderer->getProfilingMetrics();
        if ( ! is_array( $profiling_metrics ) ) {
            return;
        }

        foreach ( $profiling_metrics as $metric_name => $duration_ms ) {
            $metric_name = 'render_entries_' . ( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $metric_name ) ?? '' );
            if ( ! is_numeric( $duration_ms ) || $metric_name === 'render_entries_' ) {
                continue;
            }

            if ( ! isset( $this->server_timing[$metric_name] ) ) {
                $this->server_timing[$metric_name] = 0.0;
            }
            $this->server_timing[$metric_name] += max( 0, (float) $duration_ms );
        }
    }

    protected function shouldUsePublicPageCache() : bool {
        if ( ! $this->config->isSitePublic() ) {
            return( false );
        }
        if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'GET' ) {
            return( false );
        }
        if ( $this->app->has( 'plugins' ) ) {
            $plugins = $this->app->get( 'plugins' );
            if ( is_object( $plugins ) && method_exists( $plugins, 'hasDynamicPublicSlotRenderer' ) && $plugins->hasDynamicPublicSlotRenderer() ) {
                return( false );
            }
        }

        return( ! $this->isAuthenticatedPublicVisitor() );
    }

    protected function getCurrentRequestUriForPublicCache() : string {
        return( trim( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ?: '/' );
    }

    protected function getPublicPageCache() : ?TinyMashPublicPageCache {
        if ( ! $this->app->has( 'public.page_cache' ) ) {
            return( null );
        }

        $public_page_cache = $this->app->get( 'public.page_cache' );
        return( $public_page_cache instanceof TinyMashPublicPageCache ? $public_page_cache : null );
    }

    protected function resetShortcodeRequestState() : void {
        if ( ! $this->app->has( 'shortcode.registry' ) ) {
            return;
        }

        $shortcode_registry = $this->app->get( 'shortcode.registry' );
        if ( $shortcode_registry instanceof \app\classes\TinyMashShortcodeRegistry ) {
            $shortcode_registry->resetRequestState();
        }
    }

    protected function hasDynamicShortcodeRenderForRequest() : bool {
        if ( ! $this->app->has( 'shortcode.registry' ) ) {
            return( false );
        }

        $shortcode_registry = $this->app->get( 'shortcode.registry' );
        return( $shortcode_registry instanceof \app\classes\TinyMashShortcodeRegistry && $shortcode_registry->hasDynamicRenderForRequest() );
    }

    protected function applyPublicPageResponseHeaders() : void {
        $response = $this->app->response();
        $response->header( 'Vary', 'Cookie' );

        if ( $this->isAuthenticatedPublicVisitor() ) {
            $response->header( 'Cache-Control', 'private, no-store, max-age=0, must-revalidate' );
            $response->header( 'Pragma', 'no-cache' );
            $response->header( 'Expires', '0' );
            return;
        }

        $response->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=30' );
        $response->header( 'Pragma', 'public' );
        $response->header( 'Expires', gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );
    }

    protected function isAuthenticatedPublicVisitor() : bool {
        if ( ! $this->app->has( 'security' ) ) {
            return( false );
        }

        $security = $this->app->get( 'security' );
        if ( ! is_object( $security ) ) {
            return( false );
        }
        if ( method_exists( $security, 'isRequestAuthenticated' ) ) {
            return( (bool) $security->isRequestAuthenticated() );
        }
        if ( method_exists( $security, 'isLoggedIn' ) ) {
            return( (bool) $security->isLoggedIn() );
        }

        return( false );
    }

    protected function getAuthenticatedPublicVisitorContext() : ?array {
        if ( ! $this->app->has( 'security' ) ) {
            return( null );
        }

        $security = $this->app->get( 'security' );
        if ( ! is_object( $security ) ) {
            return( null );
        }

        $visitor_context = null;
        if ( method_exists( $security, 'getRequestVisitorContext' ) ) {
            $candidate_context = $security->getRequestVisitorContext();
            if ( is_array( $candidate_context ) ) {
                $visitor_context = $candidate_context;
            }
        } elseif ( method_exists( $security, 'getVisitorContext' ) ) {
            $candidate_context = $security->getVisitorContext();
            if ( is_array( $candidate_context ) ) {
                $visitor_context = $candidate_context;
            }
        }

        if ( ! is_array( $visitor_context ) || empty( $visitor_context['logged_in'] ) ) {
            return( null );
        }

        return( $visitor_context );
    }

    protected function buildPublicPaginationViewData( array $pagination, string $base_path ) : array {
        $base_path = trim( $base_path );
        if ( $base_path === '' ) {
            $base_path = '/';
        }

        $current_page = max( 1, (int) ( $pagination['current_page'] ?? 1 ) );
        $total_pages = max( 1, (int) ( $pagination['total_pages'] ?? 1 ) );
        $has_previous = ! empty( $pagination['has_previous'] );
        $has_next = ! empty( $pagination['has_next'] );
        $previous_page = max( 1, (int) ( $pagination['previous_page'] ?? 1 ) );
        $next_page = max( 1, (int) ( $pagination['next_page'] ?? $current_page ) );

        $build_url = static function( string $path, int $page ) : string {
            if ( $page <= 1 ) {
                return( $path );
            }

            return( $path . '?page=' . $page );
        };

        return(
            [
                'current_page' => $current_page,
                'total_pages' => $total_pages,
                'has_previous' => $has_previous,
                'has_next' => $has_next,
                'previous_url' => $has_previous ? $build_url( $base_path, $previous_page ) : '',
                'next_url' => $has_next ? $build_url( $base_path, $next_page ) : '',
            ]
        );
    }

    protected function decorateRenderedEntriesWithTagLinks( array $entries, string $scope = 'root', string $author_slug = '' ) : array {
        $decorated_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $decorated_entries[] = $this->decorateRenderedEntryWithTagLinks( $entry, $scope, $author_slug );
        }

        return( $decorated_entries );
    }

    protected function decorateRenderedEntryWithTagLinks( array $entry, string $scope = 'root', string $author_slug = '' ) : array {
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
                'label' => strtolower( $normalized_tag ),
                'url' => $this->buildTagUrl( $normalized_tag, $scope, $author_slug ),
            ];
        }

        $entry['tag_links'] = $tag_links;
        return( $entry );
    }

    protected function buildTagUrl( string $tag, string $scope = 'root', string $author_slug = '' ) : string {
        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $normalized_tag === '' ) {
            return( '' );
        }

        if ( $scope === 'author' ) {
            $normalized_author_slug = strtolower( trim( $author_slug ) );
            if ( $normalized_author_slug !== '' ) {
                return( '/' . rawurlencode( $normalized_author_slug ) . '/tags/' . rawurlencode( $normalized_tag ) );
            }
        }

        return( '/tags/' . rawurlencode( $normalized_tag ) );
    }

    protected function normalizeTagSlug( string $tag ) : string {
        $tag = ltrim( trim( strtolower( $tag ) ), '#' );
        $tag = preg_replace( '/\s+/u', '-', $tag ) ?? '';
        $tag = preg_replace( '/[^a-z0-9_-]+/', '-', $tag ) ?? '';
        $tag = preg_replace( '/-+/', '-', $tag ) ?? '';
        return( trim( $tag, '-_' ) );
    }

}// ContentController
