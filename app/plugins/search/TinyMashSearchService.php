<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

class TinyMashSearchService {

    protected const MIN_QUERY_LENGTH = 2;
    protected const RESULTS_PER_PAGE = 10;

    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashContentRenderer $content_renderer;
    protected TinyMashTheme $theme;
    protected TinyMashUserRepository $user_repository;

    public function __construct(
        TinyMashConfig $config,
        TinyMashContentRepository $content_repository,
        TinyMashContentRenderer $content_renderer,
        TinyMashTheme $theme,
        TinyMashUserRepository $user_repository
    ) {
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->content_renderer = $content_renderer;
        $this->theme = $theme;
        $this->user_repository = $user_repository;
    }

    public function getPublicSearchSettings() : array {
        return(
            [
                'enabled' => true,
                'url' => '/search',
                'min_query_length' => self::MIN_QUERY_LENGTH,
            ]
        );
    }

    public function resolveQuery( array $query_data ) : string {
        $query = (string) ( $query_data['q'] ?? '' );
        return( function_exists( 'mb_trim' ) ? mb_trim( $query ) : trim( $query ) );
    }

    public function resolveAuthorSlug( array $query_data ) : string {
        $author_slug = strtolower( trim( (string) ( $query_data['author'] ?? '' ) ) );
        if ( $author_slug === '' || str_starts_with( $author_slug, '_deleted_' ) ) {
            return( '' );
        }

        return( $author_slug );
    }

    public function resolvePage( array $query_data ) : int {
        return( max( 1, (int) ( $query_data['page'] ?? 1 ) ) );
    }

    public function search( string $query, string $author_slug = '', int $page = 1, bool $allow_private_author_content = false ) : array {
        $query = $this->resolveQuery( [ 'q' => $query ] );
        $author_slug = $this->resolveAuthorSlug( [ 'author' => $author_slug ] );
        $page = max( 1, $page );

        $results = [
            'query' => $query,
            'author_slug' => $author_slug,
            'page' => $page,
            'min_query_length' => self::MIN_QUERY_LENGTH,
            'too_short' => false,
            'author_scope_accessible' => true,
            'items' => [],
            'rendered_items' => [],
            'pagination' => [],
            'total_matches' => 0,
            'results_shown' => 0,
        ];

        if ( $author_slug !== '' && ! $allow_private_author_content && ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
            $results['author_scope_accessible'] = false;
            return( $results );
        }

        if ( $query === '' || $this->getQueryLength( $query ) < self::MIN_QUERY_LENGTH ) {
            $results['too_short'] = true;
            return( $results );
        }

        $author_content_visibility = [];
        $entry_filter = function( array $entry ) use ( $allow_private_author_content, &$author_content_visibility ) : bool {
            if ( $allow_private_author_content ) {
                return( true );
            }

            if ( (string) ( $entry['scope'] ?? '' ) !== 'author' ) {
                return( true );
            }

            $entry_author_slug = strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) );
            if ( $entry_author_slug === '' || str_starts_with( $entry_author_slug, '_deleted_' ) ) {
                return( false );
            }

            if ( ! array_key_exists( $entry_author_slug, $author_content_visibility ) ) {
                $author_content_visibility[$entry_author_slug] = $this->user_repository->isAuthorContentPublic( $entry_author_slug );
            }

            return( $author_content_visibility[$entry_author_slug] );
        };

        $search_results = $this->content_repository->searchPublishedEntries(
            $query,
            $author_slug !== '' ? $author_slug : null,
            $this->config->getDefaultLanguage(),
            $page,
            self::RESULTS_PER_PAGE,
            $entry_filter
        );

        $items = is_array( $search_results['items'] ?? null ) ? $search_results['items'] : [];
        $results['items'] = $items;
        $results['rendered_items'] = $this->decorateRenderedEntriesWithTagLinks(
            $this->content_renderer->renderEntrySummaries( $items, $this->theme )
        );
        $results['pagination'] = $this->buildPagination(
            is_array( $search_results['pagination'] ?? null ) ? $search_results['pagination'] : [],
            $query,
            $author_slug
        );
        $results['total_matches'] = max( 0, (int) ( $search_results['total_matches'] ?? count( $items ) ) );
        $results['results_shown'] = count( $items );

        return( $results );
    }

    public function buildListingSummary( array $results ) : string {
        $query = trim( (string) ( $results['query'] ?? '' ) );
        $author_slug = strtolower( trim( (string) ( $results['author_slug'] ?? '' ) ) );
        $scope_label = $author_slug !== '' ? ' in ' . $this->theme->getAuthorDisplayLabel( $author_slug ) : '';

        if ( empty( $results['author_scope_accessible'] ) && $author_slug !== '' ) {
            return( '' );
        }
        if ( ! empty( $results['too_short'] ) ) {
            return( '' );
        }

        $total_matches = max( 0, (int) ( $results['total_matches'] ?? 0 ) );
        $results_shown = max( 0, (int) ( $results['results_shown'] ?? 0 ) );

        if ( $total_matches < 1 ) {
            return( '' );
        }

        return( 'Showing ' . $results_shown . ' of ' . $total_matches . ' matches for "' . $query . '"' . $scope_label . '.' );
    }

    public function buildEmptyMessage( array $results ) : string {
        $author_slug = strtolower( trim( (string) ( $results['author_slug'] ?? '' ) ) );
        if ( empty( $results['author_scope_accessible'] ) && $author_slug !== '' ) {
            return( 'Search is not available for that author space.' );
        }
        if ( ! empty( $results['too_short'] ) ) {
            return( 'Enter at least ' . self::MIN_QUERY_LENGTH . ' characters to search.' );
        }

        $query = trim( (string) ( $results['query'] ?? '' ) );
        return( $query !== '' ? 'No matches for "' . $query . '".' : 'No matches.' );
    }

    protected function getQueryLength( string $query ) : int {
        if ( function_exists( 'mb_strlen' ) ) {
            return( (int) mb_strlen( $query, 'UTF-8' ) );
        }

        return( strlen( $query ) );
    }

    protected function buildPagination( array $pagination, string $query, string $author_slug = '' ) : array {
        $current_page = max( 1, (int) ( $pagination['current_page'] ?? 1 ) );
        $total_pages = max( 1, (int) ( $pagination['total_pages'] ?? 1 ) );
        $has_previous = ! empty( $pagination['has_previous'] );
        $has_next = ! empty( $pagination['has_next'] );
        $previous_page = max( 1, (int) ( $pagination['previous_page'] ?? 1 ) );
        $next_page = max( 1, (int) ( $pagination['next_page'] ?? $current_page ) );

        $build_url = static function( int $page_number ) use ( $query, $author_slug ) : string {
            $query_data = [ 'q' => $query ];
            if ( $author_slug !== '' ) {
                $query_data['author'] = $author_slug;
            }
            if ( $page_number > 1 ) {
                $query_data['page'] = $page_number;
            }

            return( '/search?' . http_build_query( $query_data ) );
        };

        return(
            [
                'current_page' => $current_page,
                'total_pages' => $total_pages,
                'has_previous' => $has_previous,
                'has_next' => $has_next,
                'previous_url' => $has_previous ? $build_url( $previous_page ) : '',
                'next_url' => $has_next ? $build_url( $next_page ) : '',
            ]
        );
    }

    protected function decorateRenderedEntriesWithTagLinks( array $entries ) : array {
        $decorated_entries = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $scope = (string) ( $entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
            $author_slug = $scope === 'author' ? strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) ) : '';
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
            $decorated_entries[] = $entry;
        }

        return( $decorated_entries );
    }

    protected function buildTagUrl( string $tag, string $scope = 'root', string $author_slug = '' ) : string {
        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $normalized_tag === '' ) {
            return( '#' );
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

}
