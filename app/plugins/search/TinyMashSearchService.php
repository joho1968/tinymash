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
        return( mb_trim( $query ) );
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
        $results['rendered_items'] = $this->decorateRenderedEntriesForSearch(
            $this->content_renderer->renderEntrySummaries( $items, $this->theme ),
            $query
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
        $query = mb_trim( (string) ( $results['query'] ?? '' ) );
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

        $query = mb_trim( (string) ( $results['query'] ?? '' ) );
        return( $query !== '' ? 'No matches for "' . $query . '".' : 'No matches.' );
    }

    public function buildStaticSearchIndex( string $author_slug = '', array $included_authors = [] ) : array {
        $author_slug = $this->resolveAuthorSlug( [ 'author' => $author_slug ] );
        $included_authors = array_values(
            array_filter(
                array_map( fn( mixed $value ) : string => $this->resolveAuthorSlug( [ 'author' => (string) $value ] ), $included_authors )
            )
        );
        $entries = $this->content_repository->getEntriesForAudit(
            $this->config->getDefaultLanguage(),
            $author_slug !== '' ? $author_slug : null,
            $author_slug === '',
            true
        );
        $items = [];

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $entry_author_slug = $this->resolveAuthorSlug( [ 'author' => (string) ( $entry['author_slug'] ?? '' ) ] );
            if ( $author_slug === '' && (string) ( $entry['scope'] ?? 'root' ) === 'author' && ! in_array( $entry_author_slug, $included_authors, true ) ) {
                continue;
            }

            $rendered = $this->content_renderer->renderEntrySummary( $entry, $this->theme );
            $title = $this->plainText( (string) ( $rendered['title_html'] ?? $entry['title'] ?? '' ) );
            $summary = $this->plainText( (string) ( $rendered['summary_html'] ?? $entry['summary'] ?? '' ) );
            $content = $this->plainText( (string) ( $entry['content'] ?? '' ) );
            if ( $summary === '' ) {
                $summary = $this->truncateText( $content, 240 );
            }

            $tags = array_values( array_filter( (array) ( $entry['tags'] ?? [] ), 'is_string' ) );
            $labels = array_values( array_filter( (array) ( $entry['labels'] ?? [] ), 'is_string' ) );
            $author_label = $entry_author_slug !== '' ? $this->theme->getAuthorDisplayLabel( $entry_author_slug ) : '';
            $items[] = [
                'title' => $title,
                'summary' => $summary,
                'url' => $this->buildStaticResultUrl( (string) ( $entry['path'] ?? '' ), $author_slug ),
                'type' => (string) ( $entry['type'] ?? 'post' ),
                'scope' => (string) ( $entry['scope'] ?? 'root' ),
                'author_slug' => $entry_author_slug,
                'author_label' => $author_label,
                'published_at' => (string) ( $rendered['published_at_display'] ?? '' ),
                'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                'sticky' => ! empty( $entry['sticky'] ),
                'search_text' => $this->normalizeStaticSearchText( implode( "\n", [ $title, $summary, $content, implode( ' ', $tags ), implode( ' ', $labels ), $author_label ] ) ),
            ];
        }

        usort(
            $items,
            static function( array $left, array $right ) : int {
                if ( ! empty( $left['sticky'] ) !== ! empty( $right['sticky'] ) ) {
                    return( ! empty( $left['sticky'] ) ? -1 : 1 );
                }
                if ( (string) ( $left['published_at_utc'] ?? '' ) !== (string) ( $right['published_at_utc'] ?? '' ) ) {
                    return( strcmp( (string) ( $right['published_at_utc'] ?? '' ), (string) ( $left['published_at_utc'] ?? '' ) ) );
                }
                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        return(
            [
                'version' => 1,
                'scope' => $author_slug !== '' ? 'author' : 'site',
                'author_slug' => $author_slug,
                'min_query_length' => self::MIN_QUERY_LENGTH,
                'results_per_page' => self::RESULTS_PER_PAGE,
                'items' => $items,
            ]
        );
    }

    protected function getQueryLength( string $query ) : int {
        return( mb_strlen( $query, 'UTF-8' ) );
    }

    protected function plainText( string $value ) : string {
        $value = html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = preg_replace( '/[`*_>#~\[\](){|}]+/u', ' ', $value ) ?? $value;
        return( mb_trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) );
    }

    protected function truncateText( string $value, int $length ) : string {
        if ( $this->getQueryLength( $value ) <= $length ) {
            return( $value );
        }

        $truncated = mb_substr( $value, 0, max( 1, $length - 1 ), 'UTF-8' );
        return( mb_rtrim( $truncated ) . '...' );
    }

    protected function normalizeStaticSearchText( string $value ) : string {
        $value = $this->plainText( $value );
        return( mb_strtolower( $value, 'UTF-8' ) );
    }

    protected function buildStaticResultUrl( string $path, string $author_slug ) : string {
        $path_segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), static fn( string $part ) : bool => $part !== '' ) );
        $search_segments = $author_slug !== '' ? [ $author_slug, 'search' ] : [ 'search' ];
        while ( $path_segments !== [] && $search_segments !== [] && $path_segments[0] === $search_segments[0] ) {
            array_shift( $path_segments );
            array_shift( $search_segments );
        }

        return( str_repeat( '../', count( $search_segments ) ) . ( $path_segments !== [] ? implode( '/', array_map( 'rawurlencode', $path_segments ) ) . '/' : '' ) );
    }

    public function highlightHtmlForQuery( string $html, string $query ) : string {
        $terms = $this->normalizeHighlightTerms( $query );
        if ( $html === '' || $terms === [] ) {
            return( $html );
        }

        $patterns = [];
        foreach ( $terms as $term_data ) {
            $term = (string) ( $term_data['term'] ?? '' );
            if ( $term === '' ) {
                continue;
            }

            if ( ! empty( $term_data['hash_prefixed'] ) ) {
                $patterns[] = '(?<![\p{L}\p{N}])#' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])';
                continue;
            }

            if ( preg_match( '/^\d+$/', $term ) === 1 ) {
                $patterns[] = '(?<![\p{L}\p{N}.])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}.])';
                continue;
            }

            $patterns[] = preg_quote( $term, '/' );
        }

        if ( $patterns === [] ) {
            return( $html );
        }

        $pattern = '/(?:' . implode( '|', $patterns ) . ')/iu';
        $parts = preg_split( '/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return( $html );
        }

        $highlighted = '';
        $skip_stack = [];
        foreach ( $parts as $part ) {
            if ( $part === '' ) {
                continue;
            }

            if ( str_starts_with( $part, '<' ) && str_ends_with( $part, '>' ) ) {
                $this->updateHighlightSkipStack( $part, $skip_stack );
                $highlighted .= $part;
                continue;
            }

            $highlighted .= $skip_stack === [] ? $this->highlightTextSegment( $part, $pattern ) : $part;
        }

        return( $highlighted );
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

    protected function decorateRenderedEntriesForSearch( array $entries, string $query ) : array {
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
            $entry['title_html'] = $this->highlightHtmlForQuery( (string) ( $entry['title_html'] ?? '' ), $query );
            $entry['summary_html'] = $this->highlightHtmlForQuery( (string) ( $entry['summary_html'] ?? '' ), $query );
            $decorated_entries[] = $entry;
        }

        return( $decorated_entries );
    }

    protected function normalizeHighlightTerms( string $query ) : array {
        $query = $this->resolveQuery( [ 'q' => $query ] );
        $query = mb_strtolower( $query, 'UTF-8' );
        $terms = preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $terms ) ) {
            return( [] );
        }

        $normalized_terms = [];
        foreach ( $terms as $term ) {
            $term = mb_trim( $term );
            $term = preg_replace( '/^[^\p{L}\p{N}#]+|[^\p{L}\p{N}]+$/u', '', $term ) ?? $term;
            if ( $this->getQueryLength( $term ) < self::MIN_QUERY_LENGTH ) {
                continue;
            }

            $hash_prefixed = str_starts_with( $term, '#' );
            $term = ltrim( $term, '#' );
            $term = preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $term ) ?? $term;
            if ( $term === '' ) {
                continue;
            }

            $term = mb_strtolower( $term, 'UTF-8' );
            $normalized_terms[( ! empty( $hash_prefixed ) ? '#' : '' ) . $term] = [
                'term' => $term,
                'hash_prefixed' => $hash_prefixed,
            ];
        }

        uasort(
            $normalized_terms,
            static function( array $left, array $right ) : int {
                $left_term = (string) ( $left['term'] ?? '' );
                $right_term = (string) ( $right['term'] ?? '' );
                return( mb_strlen( $right_term, 'UTF-8' ) <=> mb_strlen( $left_term, 'UTF-8' ) );
            }
        );

        return( array_values( $normalized_terms ) );
    }

    protected function updateHighlightSkipStack( string $tag, array &$skip_stack ) : void {
        if ( preg_match( '/^<\s*\/\s*([a-z0-9:-]+)/i', $tag, $matches ) === 1 ) {
            $tag_name = strtolower( (string) ( $matches[1] ?? '' ) );
            for ( $index = count( $skip_stack ) - 1; $index >= 0; $index-- ) {
                if ( $skip_stack[$index] === $tag_name ) {
                    array_splice( $skip_stack, $index, 1 );
                    break;
                }
            }
            return;
        }

        if ( preg_match( '/^<\s*([a-z0-9:-]+)/i', $tag, $matches ) !== 1 ) {
            return;
        }

        if ( str_ends_with( trim( $tag ), '/>' ) ) {
            return;
        }

        $tag_name = strtolower( (string) ( $matches[1] ?? '' ) );
        if ( in_array( $tag_name, [ 'a', 'code', 'kbd', 'pre', 'samp', 'script', 'style' ], true ) ) {
            $skip_stack[] = $tag_name;
        }
    }

    protected function highlightTextSegment( string $text, string $pattern ) : string {
        $parts = preg_split( '/(&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);)/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return( $text );
        }

        $highlighted = '';
        foreach ( $parts as $part ) {
            if ( $part === '' ) {
                continue;
            }

            if ( preg_match( '/^&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);$/iu', $part ) === 1 ) {
                $highlighted .= $part;
                continue;
            }

            $highlighted .= preg_replace_callback(
                $pattern,
                static function( array $matches ) : string {
                    return( '<mark class="tm-search-highlight">' . htmlspecialchars( (string) ( $matches[0] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</mark>' );
                },
                $part
            ) ?? $part;
        }

        return( $highlighted );
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
