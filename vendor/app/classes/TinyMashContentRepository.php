<?php
namespace app\classes;

class TinyMashContentRepository {

    protected const CONTENT_INDEX_VERSION = 9;
    protected const SEARCH_INDEX_VERSION = 1;

    protected string $content_directory;
    protected string $content_lock_filename;
    protected string $content_index_filename;
    protected string $search_index_filename;
    protected string $public_page_cache_directory;
    protected int $revision_retention_limit;
    protected ?array $entries = null;
    protected ?array $published_entries = null;
    protected ?array $persistent_content_index = null;
    protected ?array $persistent_search_index = null;
    protected array $localized_entry_cache = [];
    protected array $child_pages_cache = [];
    protected array $page_tree_cache = [];
    protected array $search_profiling_metrics = [];

    public function __construct( string $content_directory, int $revision_retention_limit = 20 ) {
        $this->content_directory = rtrim( $content_directory, DIRECTORY_SEPARATOR );
        $this->content_lock_filename = $this->content_directory . DIRECTORY_SEPARATOR . '.content.lock';
        $this->content_index_filename = dirname( $this->content_directory ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'content-index.json';
        $this->search_index_filename = dirname( $this->content_directory ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'search-index.json';
        $this->public_page_cache_directory = dirname( $this->content_directory ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache';
        $this->revision_retention_limit = $this->normalizeRevisionRetentionLimit( $revision_retention_limit );
    }

    public function clearPersistentCache() : int {
        $this->persistent_content_index = null;
        $this->persistent_search_index = null;
        $removed = 0;
        if ( is_file( $this->content_index_filename ) && @ unlink( $this->content_index_filename ) ) {
            $removed++;
        }
        if ( is_file( $this->search_index_filename ) && @ unlink( $this->search_index_filename ) ) {
            $removed++;
        }

        return( $removed );
    }

    public function invalidateDerivedCaches() : void {
        $this->clearDerivedCaches();
    }

    public function warmPersistentCache() : array {
        $index = $this->buildPersistentContentIndex();
        $search_index = $this->buildPersistentSearchIndex( $index );
        $this->writePersistentContentIndex( $index );
        $this->writePersistentSearchIndex( $search_index );
        $this->persistent_content_index = $index;
        $this->persistent_search_index = $search_index;
        $this->entries = is_array( $index['entries'] ?? null ) ? $index['entries'] : [];
        $this->published_entries = is_array( $index['published_entries'] ?? null ) ? $index['published_entries'] : [];

        return(
            [
                'entries' => count( $this->entries ),
                'published_entries' => count( $this->published_entries ),
                'authors' => count( is_array( $index['author_home'] ?? null ) ? $index['author_home'] : [] ),
                'aggregated_posts' => count( is_array( $index['aggregated_post_ids'] ?? null ) ? $index['aggregated_post_ids'] : [] ),
                'cache_file' => $this->content_index_filename,
            ]
        );
    }

    public function getPublishedAggregatedPosts( ?string $language = null ) : array {
        $index = $this->getPersistentContentIndex();
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $posts = [];
        foreach ( is_array( $index['aggregated_post_ids'] ?? null ) ? $index['aggregated_post_ids'] : [] as $entry_id ) {
            if ( ! is_string( $entry_id ) || empty( $published_entry_lookup[$entry_id] ) || ! is_array( $published_entry_lookup[$entry_id] ) ) {
                continue;
            }
            $posts[] = $this->localizeEntry( $published_entry_lookup[$entry_id], $language );
        }

        return( $posts );
    }

    public function getSearchProfilingMetrics() : array {
        return( $this->search_profiling_metrics );
    }

    public function getPublishedAggregatedPostsPage( ?string $language = null, int $page = 1, int $per_page = 10 ) : array {
        $index = $this->getPersistentContentIndex();
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $aggregated_post_ids = array_values( array_filter( is_array( $index['aggregated_post_ids'] ?? null ) ? $index['aggregated_post_ids'] : [], 'is_string' ) );
        $pagination = $this->buildPaginationMeta( count( $aggregated_post_ids ), $page, $per_page );
        $paged_post_ids = array_slice( $aggregated_post_ids, $pagination['offset'], $pagination['per_page'] );
        $paged_posts = [];
        foreach ( $paged_post_ids as $entry_id ) {
            if ( ! empty( $published_entry_lookup[$entry_id] ) && is_array( $published_entry_lookup[$entry_id] ) ) {
                $paged_posts[] = $published_entry_lookup[$entry_id];
            }
        }

        return(
            [
                'items' => array_map( fn( array $entry ) : array => $this->localizeEntry( $entry, $language ), $paged_posts ),
                'pagination' => $pagination,
            ]
        );
    }

    public function getRootNavigationPages( ?string $language = null ) : array {
        return( $this->getChildPagesForContext( 'root', '', '', true, $language ) );
    }

    public function getRootNavigationPageTree( ?string $language = null ) : array {
        return( $this->buildPageTreeForContext( 'root', '', true, $language ) );
    }

    public function getRootPublishedPageTree( ?string $language = null ) : array {
        return( $this->buildPageTreeForContext( 'root', '', false, $language ) );
    }

    public function getAuthorPublishedPageTree( string $author_slug, ?string $language = null ) : array {
        $author_slug = $this->normalizePathPart( $author_slug );
        if ( $author_slug === '' ) {
            return( [] );
        }

        return( $this->buildPageTreeForContext( 'author', $author_slug, false, $language ) );
    }

    public function getAuthorHome( string $author_slug, ?string $language = null ) : ?array {
        $author_slug = $this->normalizePathPart( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $author_home = is_array( $index['author_home'][$author_slug] ?? null ) ? $index['author_home'][$author_slug] : null;
        if ( ! is_array( $author_home ) ) {
            return( null );
        }
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $posts = [];
        foreach ( is_array( $author_home['post_ids'] ?? null ) ? $author_home['post_ids'] : [] as $entry_id ) {
            if ( ! empty( $published_entry_lookup[$entry_id] ) && is_array( $published_entry_lookup[$entry_id] ) ) {
                $posts[] = $this->localizeEntry( $published_entry_lookup[$entry_id], $language );
            }
        }
        $pages = [];
        foreach ( is_array( $author_home['page_ids'] ?? null ) ? $author_home['page_ids'] : [] as $entry_id ) {
            if ( ! empty( $published_entry_lookup[$entry_id] ) && is_array( $published_entry_lookup[$entry_id] ) ) {
                $pages[] = $this->localizeEntry( $published_entry_lookup[$entry_id], $language );
            }
        }

        return(
            [
                'author_slug' => $author_slug,
                'posts' => $posts,
                'pages' => $pages,
            ]
        );
    }

    public function getAuthorHomePage( string $author_slug, ?string $language = null, int $page = 1, int $per_page = 10 ) : ?array {
        $author_slug = $this->normalizePathPart( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $author_home = is_array( $index['author_home'][$author_slug] ?? null ) ? $index['author_home'][$author_slug] : null;
        if ( ! is_array( $author_home ) ) {
            return( null );
        }
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $post_ids = array_values( array_filter( is_array( $author_home['post_ids'] ?? null ) ? $author_home['post_ids'] : [], 'is_string' ) );
        $page_ids = array_values( array_filter( is_array( $author_home['page_ids'] ?? null ) ? $author_home['page_ids'] : [], 'is_string' ) );
        $pagination = $this->buildPaginationMeta( count( $post_ids ), $page, $per_page );
        $paged_post_ids = array_slice( $post_ids, $pagination['offset'], $pagination['per_page'] );

        return(
            [
                'author_slug' => $author_slug,
                'posts' => array_values(
                    array_map(
                        fn( string $entry_id ) : array => $this->localizeEntry( $published_entry_lookup[$entry_id], $language ),
                        array_values( array_filter( $paged_post_ids, fn( string $entry_id ) : bool => ! empty( $published_entry_lookup[$entry_id] ) && is_array( $published_entry_lookup[$entry_id] ) ) )
                    )
                ),
                'pages' => array_values(
                    array_map(
                        fn( string $entry_id ) : array => $this->localizeEntry( $published_entry_lookup[$entry_id], $language ),
                        array_values( array_filter( $page_ids, fn( string $entry_id ) : bool => ! empty( $published_entry_lookup[$entry_id] ) && is_array( $published_entry_lookup[$entry_id] ) ) )
                    )
                ),
                'pagination' => $pagination,
            ]
        );
    }

    public function getRootTagPage( string $tag, ?string $language = null, int $page = 1, int $per_page = 10 ) : ?array {
        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $normalized_tag === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $matched_entries = [];

        foreach ( $published_entry_lookup as $entry ) {
            if ( ! is_array( $entry ) || ! $this->entryMatchesRootTagScope( $entry ) ) {
                continue;
            }
            if ( ! $this->entryHasTag( $entry, $normalized_tag ) ) {
                continue;
            }

            $matched_entries[] = $entry;
        }

        return( $this->buildTagPageResult( $matched_entries, $normalized_tag, $language, $page, $per_page ) );
    }

    public function getAuthorTagPage( string $author_slug, string $tag, ?string $language = null, int $page = 1, int $per_page = 10 ) : ?array {
        $author_slug = $this->normalizePathPart( $author_slug );
        $normalized_tag = $this->normalizeTagSlug( $tag );
        if ( $author_slug === '' || $normalized_tag === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $author_home = is_array( $index['author_home'][$author_slug] ?? null ) ? $index['author_home'][$author_slug] : null;
        if ( ! is_array( $author_home ) ) {
            return( null );
        }

        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $entry_ids = array_values(
            array_filter(
                array_merge(
                    is_array( $author_home['post_ids'] ?? null ) ? $author_home['post_ids'] : [],
                    is_array( $author_home['page_ids'] ?? null ) ? $author_home['page_ids'] : []
                ),
                'is_string'
            )
        );
        $matched_entries = [];

        foreach ( $entry_ids as $entry_id ) {
            $entry = is_array( $published_entry_lookup[$entry_id] ?? null ) ? $published_entry_lookup[$entry_id] : null;
            if ( ! is_array( $entry ) || ! $this->entryHasTag( $entry, $normalized_tag ) ) {
                continue;
            }

            $matched_entries[] = $entry;
        }

        return( $this->buildTagPageResult( $matched_entries, $normalized_tag, $language, $page, $per_page ) );
    }

    public function hasPublicAuthor( string $author_slug ) : bool {
        $author_slug = $this->normalizePathPart( $author_slug );
        if ( $author_slug === '' ) {
            return( false );
        }

        $index = $this->getPersistentContentIndex();
        return( is_array( $index['author_home'][$author_slug] ?? null ) );
    }

    public function getPublicAuthorSlugs() : array {
        $index = $this->getPersistentContentIndex();
        $author_home = is_array( $index['author_home'] ?? null ) ? $index['author_home'] : [];
        $author_slugs = array_values(
            array_filter(
                array_keys( $author_home ),
                fn( mixed $author_slug ) : bool => is_string( $author_slug ) && trim( $author_slug ) !== ''
            )
        );
        sort( $author_slugs, SORT_STRING );

        return( $author_slugs );
    }

    public function getPublishedAggregatedPaginationMeta( int $per_page = 10 ) : array {
        $index = $this->getPersistentContentIndex();
        $aggregated_post_ids = array_values( array_filter( is_array( $index['aggregated_post_ids'] ?? null ) ? $index['aggregated_post_ids'] : [], 'is_string' ) );
        return( $this->buildPaginationMeta( count( $aggregated_post_ids ), 1, $per_page ) );
    }

    public function getEntriesForAudit( ?string $language = null, ?string $author_slug = null, bool $allow_root = true, bool $published_only = true ) : array {
        $source_entries = $published_only ? $this->getPublishedEntries() : $this->getEntries();
        $entries = [];
        foreach ( $source_entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }
            $entries[] = $this->localizeEntry( $entry, $language );
        }

        usort(
            $entries,
            function( array $left, array $right ) : int {
                $left_path = (string) ( $left['path'] ?? '' );
                $right_path = (string) ( $right['path'] ?? '' );
                return( strcmp( $left_path, $right_path ) );
            }
        );

        return( $entries );
    }

    public function getAuthorHomePaginationMeta( string $author_slug, int $per_page = 10 ) : ?array {
        $author_slug = $this->normalizePathPart( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $author_home = is_array( $index['author_home'][$author_slug] ?? null ) ? $index['author_home'][$author_slug] : null;
        if ( ! is_array( $author_home ) ) {
            return( null );
        }

        $post_ids = array_values( array_filter( is_array( $author_home['post_ids'] ?? null ) ? $author_home['post_ids'] : [], 'is_string' ) );
        return( $this->buildPaginationMeta( count( $post_ids ), 1, $per_page ) );
    }

    public function getPublishedEntryWarmPaths( ?string $author_slug = null, int $limit = 0 ) : array {
        $normalized_author_slug = $author_slug !== null ? $this->normalizePathPart( $author_slug ) : null;
        $index = $this->getPersistentContentIndex();
        $published_entries = array_values( array_filter( is_array( $index['published_entries'] ?? null ) ? $index['published_entries'] : [], 'is_array' ) );
        $warm_paths = [];

        usort(
            $published_entries,
            function( array $left, array $right ) : int {
                if ( ( $left['published_at_utc'] ?? '' ) !== ( $right['published_at_utc'] ?? '' ) ) {
                    return( strcmp( (string) ( $right['published_at_utc'] ?? '' ), (string) ( $left['published_at_utc'] ?? '' ) ) );
                }

                return( strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) ) );
            }
        );

        foreach ( $published_entries as $entry ) {
            $entry_path = trim( (string) ( $entry['path'] ?? '' ) );
            if ( $entry_path === '' ) {
                continue;
            }

            $entry_scope = (string) ( $entry['scope'] ?? 'root' );
            $entry_author_slug = $this->normalizePathPart( (string) ( $entry['author_slug'] ?? '' ) );
            if ( $normalized_author_slug !== null ) {
                if ( $normalized_author_slug === '' ) {
                    continue;
                }
                if ( $entry_scope !== 'author' || $entry_author_slug !== $normalized_author_slug ) {
                    continue;
                }
            }

            $public_path = $entry_scope === 'author' && $entry_author_slug !== ''
                ? '/' . rawurlencode( $entry_author_slug ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $entry_path ) ) )
                : '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $entry_path ) ) );

            if ( in_array( $public_path, $warm_paths, true ) ) {
                continue;
            }

            $warm_paths[] = $public_path;
            if ( $limit > 0 && count( $warm_paths ) >= $limit ) {
                break;
            }
        }

        return( $warm_paths );
    }

    public function getEntryNavigationEntries( array $entry, ?string $language = null ) : array {
        $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
        if ( $entry_id === '' || (string) ( $entry['type'] ?? '' ) !== 'post' ) {
            return(
                [
                    'previous' => null,
                    'next' => null,
                ]
            );
        }

        $index = $this->getPersistentContentIndex();
        $published_entry_lookup = is_array( $index['published_entry_lookup'] ?? null ) ? $index['published_entry_lookup'] : [];
        $ordered_post_ids = [];
        if ( (string) ( $entry['scope'] ?? 'root' ) === 'author' ) {
            $author_slug = $this->normalizePathPart( (string) ( $entry['author_slug'] ?? '' ) );
            $author_home = is_array( $index['author_home'][$author_slug] ?? null ) ? $index['author_home'][$author_slug] : [];
            $ordered_post_ids = array_values( array_filter( is_array( $author_home['post_ids'] ?? null ) ? $author_home['post_ids'] : [], 'is_string' ) );
        } else {
            $ordered_post_ids = array_values( array_filter( is_array( $index['aggregated_post_ids'] ?? null ) ? $index['aggregated_post_ids'] : [], 'is_string' ) );
        }

        $entry_index = array_search( $entry_id, $ordered_post_ids, true );
        if ( $entry_index === false ) {
            return(
                [
                    'previous' => null,
                    'next' => null,
                ]
            );
        }

        $previous_entry = null;
        $next_entry = null;
        $previous_id = $ordered_post_ids[$entry_index - 1] ?? '';
        $next_id = $ordered_post_ids[$entry_index + 1] ?? '';
        if ( is_string( $previous_id ) && $previous_id !== '' && ! empty( $published_entry_lookup[$previous_id] ) && is_array( $published_entry_lookup[$previous_id] ) ) {
            $previous_entry = $this->localizeEntry( $published_entry_lookup[$previous_id], $language );
        }
        if ( is_string( $next_id ) && $next_id !== '' && ! empty( $published_entry_lookup[$next_id] ) && is_array( $published_entry_lookup[$next_id] ) ) {
            $next_entry = $this->localizeEntry( $published_entry_lookup[$next_id], $language );
        }

        return(
            [
                'previous' => $previous_entry,
                'next' => $next_entry,
            ]
        );
    }

    public function searchPublishedEntries( string $query, ?string $author_slug = null, ?string $language = null, int $page = 1, int $per_page = 10, ?callable $entry_filter = null ) : array {
        $this->search_profiling_metrics = [];
        $normalized_query = function_exists( 'mb_trim' ) ? mb_trim( $query ) : trim( $query );
        $search_terms = $this->normalizeSearchTerms( $normalized_query );
        if ( $search_terms === [] ) {
            $pagination = $this->buildPaginationMeta( 0, $page, $per_page );
            return(
                [
                    'items' => [],
                    'pagination' => $pagination,
                    'query' => $normalized_query,
                    'total_matches' => 0,
                ]
            );
        }

        $normalized_author_slug = $author_slug !== null ? $this->normalizePathPart( $author_slug ) : null;
        $search_index_started_at = microtime( true );
        $index = $this->getPersistentSearchIndex();
        $this->recordSearchProfilingMetric( 'index_load', $search_index_started_at );
        $published_entries = array_values( array_filter( is_array( $index['entries'] ?? null ) ? $index['entries'] : [], 'is_array' ) );
        $matched_entries = [];
        $scan_started_at = microtime( true );
        foreach ( $published_entries as $entry ) {
            if ( $normalized_author_slug !== null ) {
                if ( $normalized_author_slug === '' ) {
                    continue;
                }
                if ( (string) ( $entry['scope'] ?? '' ) !== 'author' || $this->normalizePathPart( (string) ( $entry['author_slug'] ?? '' ) ) !== $normalized_author_slug ) {
                    continue;
                }
            }
            if ( is_callable( $entry_filter ) && ! $entry_filter( $entry ) ) {
                continue;
            }
            $search_text = trim( (string) ( $entry['search_text'] ?? '' ) );
            if ( $search_text === '' ) {
                continue;
            }

            $matches_all_terms = true;
            foreach ( $search_terms as $term ) {
                if ( $this->searchEntryMatchesTerm( $entry, $search_text, $term ) ) {
                    continue;
                }
                $matches_all_terms = false;
                break;
            }

            if ( $matches_all_terms ) {
                $matched_entries[] = $entry;
            }
        }
        $this->recordSearchProfilingMetric( 'scan', $scan_started_at );

        $sort_started_at = microtime( true );
        usort(
            $matched_entries,
            function( array $left, array $right ) : int {
                if ( ! empty( $left['sticky'] ) !== ! empty( $right['sticky'] ) ) {
                    return( ! empty( $left['sticky'] ) ? -1 : 1 );
                }
                if ( ( $left['published_at_utc'] ?? '' ) !== ( $right['published_at_utc'] ?? '' ) ) {
                    return( strcmp( (string) ( $right['published_at_utc'] ?? '' ), (string) ( $left['published_at_utc'] ?? '' ) ) );
                }

                return( strcmp( $this->getEntrySortTitle( $left ), $this->getEntrySortTitle( $right ) ) );
            }
        );
        $this->recordSearchProfilingMetric( 'sort', $sort_started_at );

        $pagination = $this->buildPaginationMeta( count( $matched_entries ), $page, $per_page );
        $paged_entries = array_slice( $matched_entries, (int) $pagination['offset'], (int) $pagination['per_page'] );
        $localize_started_at = microtime( true );
        $localized_entries = array_map( fn( array $entry ) : array => $this->localizeEntry( $entry, $language ), $paged_entries );
        $this->recordSearchProfilingMetric( 'localize', $localize_started_at );

        return(
            [
                'items' => $localized_entries,
                'pagination' => $pagination,
                'query' => $normalized_query,
                'total_matches' => count( $matched_entries ),
            ]
        );
    }

    public function getRootEntryBySlug( string $slug, ?string $language = null ) : ?array {
        return( $this->getRootEntryByPath( $slug, $language ) );
    }

    public function getRootPostBySlug( string $slug, ?string $language = null ) : ?array {
        $entry = $this->getRootEntryByPath( $slug, $language );
        if ( ! is_array( $entry ) || $entry['type'] !== 'post' ) {
            return( null );
        }

        return( $entry );
    }

    public function getRootEntryByPath( string $path, ?string $language = null ) : ?array {
        $path = $this->normalizePath( $path );
        if ( $path === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $entry_id = (string) ( $index['root_path_map'][$path] ?? '' );
        $entry = ( $entry_id !== '' && ! empty( $index['published_entry_lookup'][$entry_id] ) && is_array( $index['published_entry_lookup'][$entry_id] ) )
            ? $index['published_entry_lookup'][$entry_id]
            : null;

        return( is_array( $entry ) ? $this->localizeEntry( $entry, $language ) : null );
    }

    public function getAuthorEntryByPath( string $author_slug, string $path, ?string $language = null ) : ?array {
        $author_slug = $this->normalizePathPart( $author_slug );
        $path = $this->normalizePath( $path );
        if ( $author_slug === '' || $path === '' ) {
            return( null );
        }

        $index = $this->getPersistentContentIndex();
        $entry_id = (string) ( $index['author_path_map'][$author_slug][$path] ?? '' );
        $entry = ( $entry_id !== '' && ! empty( $index['published_entry_lookup'][$entry_id] ) && is_array( $index['published_entry_lookup'][$entry_id] ) )
            ? $index['published_entry_lookup'][$entry_id]
            : null;

        return( is_array( $entry ) ? $this->localizeEntry( $entry, $language ) : null );
    }

    protected function shouldReplacePathMapEntry( string $existing_entry_id, array $candidate_entry, array $published_entry_lookup ) : bool {
        if ( $existing_entry_id === '' || empty( $published_entry_lookup[$existing_entry_id] ) || ! is_array( $published_entry_lookup[$existing_entry_id] ) ) {
            return( true );
        }

        $existing_entry = $published_entry_lookup[$existing_entry_id];
        $existing_type = strtolower( trim( (string) ( $existing_entry['type'] ?? 'post' ) ) );
        $candidate_type = strtolower( trim( (string) ( $candidate_entry['type'] ?? 'post' ) ) );
        if ( $existing_type === 'page' && $candidate_type !== 'page' ) {
            return( false );
        }
        if ( $existing_type !== 'page' && $candidate_type === 'page' ) {
            return( true );
        }

        return( false );
    }

    protected function isCanonicalPublishedPathEntry( array $entry, array $index ) : bool {
        $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
        $path = $this->normalizePath( (string) ( $entry['path'] ?? '' ) );
        if ( $entry_id === '' || $path === '' ) {
            return( false );
        }

        if ( (string) ( $entry['scope'] ?? 'root' ) === 'author' ) {
            $author_slug = $this->normalizePathPart( (string) ( $entry['author_slug'] ?? '' ) );
            if ( $author_slug === '' ) {
                return( false );
            }

            return( (string) ( $index['author_path_map'][$author_slug][$path] ?? '' ) === $entry_id );
        }

        return( (string) ( $index['root_path_map'][$path] ?? '' ) === $entry_id );
    }

    public function getEntryPath( array $entry ) : string {
        if ( ! empty( $entry['path'] ) && is_string( $entry['path'] ) ) {
            return( (string) $entry['path'] );
        }
        if ( $entry['type'] !== 'page' || $entry['parent_slug'] === '' ) {
            return( $entry['slug'] );
        }

        $segments = [ $entry['slug'] ];
        $current_parent_slug = $entry['parent_slug'];
        $guard = 0;
        while ( $current_parent_slug !== '' && $guard < 20 ) {
            $guard++;
            $parent_entry = $this->findPageByScopeAndSlug( $entry['scope'], $entry['author_slug'], $current_parent_slug );
            if ( $parent_entry === null ) {
                break;
            }
            array_unshift( $segments, $parent_entry['slug'] );
            $current_parent_slug = $parent_entry['parent_slug'];
        }

        return( implode( '/', $segments ) );
    }

    public function getEntryAncestors( array $entry, ?string $language = null ) : array {
        $ancestors = [];
        $current_parent_slug = $entry['parent_slug'];
        $guard = 0;
        while ( $current_parent_slug !== '' && $guard < 20 ) {
            $guard++;
            $parent_entry = $this->findPageByScopeAndSlug( $entry['scope'], $entry['author_slug'], $current_parent_slug );
            if ( $parent_entry === null ) {
                break;
            }
            array_unshift( $ancestors, $this->localizeEntry( $parent_entry, $language ) );
            $current_parent_slug = $parent_entry['parent_slug'];
        }

        return( $ancestors );
    }

    public function getChildPages( array $entry, ?string $language = null ) : array {
        return(
            $this->getChildPagesForContext(
                $entry['scope'],
                $entry['author_slug'],
                $entry['slug'],
                false,
                $language
            )
        );
    }

    public function getRootPageTree( ?string $language = null ) : array {
        return( $this->getRootNavigationPageTree( $language ) );
    }

    public function getContentStats( ?string $author_slug = null, bool $allow_root = true ) : array {
        $stats = [
            'entries' => 0,
            'posts' => 0,
            'pages' => 0,
            'authors' => 0,
            'pending_review' => 0,
        ];
        $authors = [];

        foreach ( $this->getEntries() as $entry ) {
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }
            $stats['entries']++;
            if ( $entry['type'] === 'post' ) {
                $stats['posts']++;
            } elseif ( $entry['type'] === 'page' ) {
                $stats['pages']++;
            }
            if ( $entry['status'] === 'pending_review' ) {
                $stats['pending_review']++;
            }
            if ( $entry['scope'] === 'author' && $entry['author_slug'] !== '' ) {
                $authors[$entry['author_slug']] = true;
            }
        }

        $stats['authors'] = count( $authors );
        return( $stats );
    }

    public function getPublishedEntryById( string $entry_id, ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : ?array {
        $entry = $this->getAccessibleEntryById( $entry_id, $language, $author_slug, $allow_root );
        if ( ! is_array( $entry ) || $entry['status'] !== 'published' ) {
            return( null );
        }

        return( $entry );
    }

    public function getAccessibleEntryById( string $entry_id, ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : ?array {
        $entry_id = trim( $entry_id );
        if ( $entry_id === '' ) {
            return( null );
        }

        foreach ( $this->getEntries() as $entry ) {
            if ( $entry['id'] !== $entry_id ) {
                continue;
            }
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }

            return( $this->localizeEntry( $entry, $language ) );
        }

        return( null );
    }

    public function getAccessibleTrashedEntryById( string $entry_id, ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : ?array {
        $entry_id = trim( $entry_id );
        if ( $entry_id === '' ) {
            return( null );
        }

        foreach ( $this->getTrashedEntries() as $entry ) {
            if ( $entry['id'] !== $entry_id ) {
                continue;
            }
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }

            return( $this->localizeEntry( $entry, $language ) );
        }

        return( null );
    }

    public function getEditorEntryOptions( ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : array {
        $options = [];
        $single_author_scope = ! $allow_root && is_string( $author_slug ) && $this->normalizeEditorPathPart( $author_slug ) !== '';
        foreach ( $this->getEntries() as $entry ) {
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }
            $localized_entry = $this->localizeEntry( $entry, $language );
            $label_parts = [ (string) $localized_entry['type'] ];
            if ( ! $single_author_scope ) {
                $label_parts[] = $localized_entry['scope'] === 'author' ? ( $localized_entry['author_slug'] . ' space' ) : 'root';
            }
            $label_parts[] = (string) $localized_entry['status'];
            $options[] = [
                'id' => $localized_entry['id'],
                'type' => $localized_entry['type'],
                'scope' => $localized_entry['scope'],
                'author_slug' => $localized_entry['author_slug'],
                'slug' => (string) ( $localized_entry['slug'] ?? '' ),
                'path' => $localized_entry['path'],
                'title' => $localized_entry['title'],
                'status' => $localized_entry['status'],
                'parent_slug' => (string) ( $localized_entry['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $localized_entry['sort_order'] ?? 0 ),
                'label' => $localized_entry['title'] . ' (' . implode( ', ', $label_parts ) . ')',
            ];
        }

        usort(
            $options,
            function( array $left, array $right ) : int {
                return( strcmp( $left['label'], $right['label'] ) );
            }
        );

        return( $options );
    }

    public function getEditorEntries( ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : array {
        $entries = [];
        foreach ( $this->getEntries() as $entry ) {
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }
            $localized_entry = $this->localizeEntry( $entry, $language );
            $entries[] = [
                'id' => $localized_entry['id'],
                'title' => $localized_entry['title'],
                'type' => $localized_entry['type'],
                'scope' => $localized_entry['scope'],
                'author_slug' => $localized_entry['author_slug'],
                'path' => $localized_entry['path'],
                'parent_slug' => (string) ( $localized_entry['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $localized_entry['sort_order'] ?? 0 ),
                'status' => $localized_entry['status'],
                'updated_at_utc' => $localized_entry['updated_at_utc'],
            ];
        }

        usort(
            $entries,
            function( array $left, array $right ) : int {
                if ( $left['updated_at_utc'] !== $right['updated_at_utc'] ) {
                    return( strcmp( $right['updated_at_utc'], $left['updated_at_utc'] ) );
                }

                return( strcmp( $left['title'], $right['title'] ) );
            }
        );

        return( $entries );
    }

    public function getTrashedEditorEntries( ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : array {
        $entries = [];
        foreach ( $this->getTrashedEntries() as $entry ) {
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }
            $localized_entry = $this->localizeEntry( $entry, $language );
            $entries[] = [
                'id' => $localized_entry['id'],
                'title' => $localized_entry['title'],
                'type' => $localized_entry['type'],
                'scope' => $localized_entry['scope'],
                'author_slug' => $localized_entry['author_slug'],
                'path' => $localized_entry['path'],
                'parent_slug' => (string) ( $localized_entry['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $localized_entry['sort_order'] ?? 0 ),
                'status' => $localized_entry['status'],
                'pre_trash_status' => (string) ( $localized_entry['pre_trash_status'] ?? '' ),
                'trashed_at_utc' => (string) ( $localized_entry['trashed_at_utc'] ?? '' ),
                'trashed_by' => (string) ( $localized_entry['trashed_by'] ?? '' ),
                'updated_at_utc' => $localized_entry['updated_at_utc'],
            ];
        }

        usort(
            $entries,
            function( array $left, array $right ) : int {
                if ( $left['trashed_at_utc'] !== $right['trashed_at_utc'] ) {
                    return( strcmp( $right['trashed_at_utc'], $left['trashed_at_utc'] ) );
                }

                return( strcmp( $left['title'], $right['title'] ) );
            }
        );

        return( $entries );
    }

    public function getRecentEditorEntries( int $limit = 8, ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : array {
        return( array_slice( $this->getEditorEntries( $language, $author_slug, $allow_root ), 0, max( 1, $limit ) ) );
    }

    public function getEditorTagSuggestions( ?string $author_slug = null, bool $allow_root = true ) : array {
        $tags = [];
        foreach ( $this->getEntries() as $entry ) {
            if ( ! $this->entryMatchesEditorAccess( $entry, $author_slug, $allow_root ) ) {
                continue;
            }

            foreach ( (array) ( $entry['tags'] ?? [] ) as $tag ) {
                if ( ! is_string( $tag ) ) {
                    continue;
                }

                $normalized_tag = $this->normalizeTagSlug( $tag );
                if ( $normalized_tag === '' ) {
                    continue;
                }

                $tags[$normalized_tag] = $normalized_tag;
            }
        }

        ksort( $tags, SORT_STRING );
        return( array_values( $tags ) );
    }

    public function getPendingReviewEntries( int $limit = 50, ?string $language = null, ?string $author_slug = null, bool $allow_root = true ) : array {
        $entries = array_values(
            array_filter(
                $this->getEditorEntries( $language, $author_slug, $allow_root ),
                static function( array $entry ) : bool {
                    return( ( $entry['status'] ?? '' ) === 'pending_review' );
                }
            )
        );

        return( array_slice( $entries, 0, max( 1, $limit ) ) );
    }

    public function getEntryRevisionHistory( string $entry_id, int $limit = 20, ?string $author_slug = null, bool $allow_root = true ) : array {
        $entry = $this->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
        if ( ! is_array( $entry ) ) {
            return( [] );
        }

        $revision_root = $this->getEntryDirectory( $entry ) . DIRECTORY_SEPARATOR . 'revisions';
        if ( ! is_dir( $revision_root ) ) {
            return( [] );
        }

        $revisions = [];
        foreach ( glob( $revision_root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'revision.json' ) ?: [] as $revision_file ) {
            if ( ! is_string( $revision_file ) ) {
                continue;
            }
            $raw_revision = $this->readRawRevisionFromStorage( $revision_file );
            if ( ! is_array( $raw_revision ) ) {
                continue;
            }

            $normalized_revision = $this->normalizeEntry( $raw_revision );
            if ( ! is_array( $normalized_revision ) ) {
                continue;
            }

            $localized_revision = $this->localizeEntry( $normalized_revision );
            $revisions[] = [
                'revision_id' => (string) ( $raw_revision['revision_id'] ?? '' ),
                'source_entry_id' => (string) ( $raw_revision['source_entry_id'] ?? $entry['id'] ),
                'title' => $localized_revision['title'],
                'summary' => $localized_revision['summary'],
                'type' => $localized_revision['type'],
                'scope' => $localized_revision['scope'],
                'author_slug' => $localized_revision['author_slug'],
                'path' => $localized_revision['path'],
                'status' => $localized_revision['status'],
                'updated_at_utc' => $localized_revision['updated_at_utc'],
                'saved_at_utc' => (string) ( $raw_revision['saved_at_utc'] ?? $localized_revision['updated_at_utc'] ),
            ];
        }

        usort(
            $revisions,
            static function( array $left, array $right ) : int {
                if ( $left['saved_at_utc'] !== $right['saved_at_utc'] ) {
                    return( strcmp( $right['saved_at_utc'], $left['saved_at_utc'] ) );
                }

                return( strcmp( $right['revision_id'], $left['revision_id'] ) );
            }
        );

        return( array_slice( $revisions, 0, max( 1, $limit ) ) );
    }

    public function getEntryRevisionSnapshot( string $entry_id, string $revision_id, ?string $author_slug = null, bool $allow_root = true ) : ?array {
        $entry = $this->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
        if ( ! is_array( $entry ) ) {
            return( null );
        }

        $revision_id = trim( $revision_id );
        if ( $revision_id === '' ) {
            return( null );
        }

        $revision_file = $this->getEntryDirectory( $entry ) . DIRECTORY_SEPARATOR . 'revisions' . DIRECTORY_SEPARATOR . $revision_id . DIRECTORY_SEPARATOR . 'revision.json';
        $raw_revision = $this->readRawRevisionFromStorage( $revision_file );
        if ( ! is_array( $raw_revision ) ) {
            return( null );
        }

        $normalized_revision = $this->normalizeEntry( $raw_revision );
        if ( ! is_array( $normalized_revision ) ) {
            return( null );
        }

        $localized_revision = $this->localizeEntry( $normalized_revision );
        $localized_revision['revision_id'] = (string) ( $raw_revision['revision_id'] ?? '' );
        $localized_revision['saved_at_utc'] = (string) ( $raw_revision['saved_at_utc'] ?? $localized_revision['updated_at_utc'] );

        return( $localized_revision );
    }

    public function normalizeEditorSlug( string $value ) : string {
        return( $this->normalizeEditorPathPart( $value ) );
    }

    public function hasEditorEntryConflict( array $editor_entry, string $loaded_entry_id = '' ) : bool {
        $normalized_editor_entry = $this->normalizeEditorEntryInput( $editor_entry );
        $loaded_entry_id = trim( $loaded_entry_id );

        foreach ( $this->getEntries() as $entry ) {
            if (
                $entry['type'] !== $normalized_editor_entry['type'] ||
                $entry['scope'] !== $normalized_editor_entry['scope'] ||
                $entry['author_slug'] !== $normalized_editor_entry['author_slug'] ||
                $entry['slug'] !== $normalized_editor_entry['slug']
            ) {
                continue;
            }

            if ( $loaded_entry_id === '' || $entry['id'] !== $loaded_entry_id ) {
                return( true );
            }
        }

        return( false );
    }

    public function moveAuthorEntriesToDeletedOwner( string $author_slug, string $deleted_username ) : array {
        return( $this->withLockedContentStore( function() use ( $author_slug, $deleted_username ) : array {
            $author_slug = $this->normalizeEditorPathPart( $author_slug );
            $deleted_username = $this->normalizeDeletedOwnerUsername( $deleted_username );
            if ( $author_slug === '' || $deleted_username === '' ) {
                throw new \InvalidArgumentException( 'author_slug' );
            }

            $raw_entries = $this->getRawEntries();
            $matches = 0;
            foreach ( $raw_entries as $raw_entry ) {
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if ( (string) ( $raw_entry['scope'] ?? 'root' ) !== 'author' ) {
                    continue;
                }
                if ( $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) !== $author_slug ) {
                    continue;
                }
                $matches++;
            }

            $deleted_author_slug = $this->buildDeletedAuthorSlug( $deleted_username );
            if ( $matches === 0 ) {
                return(
                    [
                        'deleted_author_slug' => $deleted_author_slug,
                        'moved_entries' => 0,
                    ]
                );
            }

            foreach ( $raw_entries as $index => $raw_entry ) {
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if ( (string) ( $raw_entry['scope'] ?? 'root' ) !== 'author' ) {
                    continue;
                }
                if ( $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) !== $author_slug ) {
                    continue;
                }

                $raw_entries[$index]['author_slug'] = $deleted_author_slug;
                $raw_entries[$index]['aggregate_to_root'] = false;
                $raw_entries[$index]['sticky'] = false;
                $raw_entries[$index]['status'] = $this->normalizeStatus( (string) ( $raw_entry['status'] ?? 'unpublished' ) );
                if ( empty( $raw_entries[$index]['id'] ) || ! is_string( $raw_entries[$index]['id'] ) ) {
                    $raw_entries[$index]['id'] = $this->generateEntryId();
                }
            }

            $this->writeRawEntries( $raw_entries );
            return(
                [
                    'deleted_author_slug' => $deleted_author_slug,
                    'moved_entries' => $matches,
                ]
            );
        } ) );
    }

    public function getOrphanedEntryGroups( ?string $language = null ) : array {
        $groups = [];
        foreach ( $this->getEntries() as $entry ) {
            if ( $entry['scope'] !== 'author' || ! $this->isDeletedAuthorSlug( $entry['author_slug'] ) ) {
                continue;
            }

            $localized_entry = $this->localizeEntry( $entry, $language );
            $group_key = $entry['author_slug'];
            if ( empty( $groups[$group_key] ) ) {
                $groups[$group_key] = [
                    'author_slug' => $entry['author_slug'],
                    'entry_count' => 0,
                    'published_count' => 0,
                    'pending_review_count' => 0,
                    'draft_count' => 0,
                    'entries' => [],
                ];
            }

            $groups[$group_key]['entry_count']++;
            if ( $entry['status'] === 'published' ) {
                $groups[$group_key]['published_count']++;
            } elseif ( $entry['status'] === 'pending_review' ) {
                $groups[$group_key]['pending_review_count']++;
            } else {
                $groups[$group_key]['draft_count']++;
            }
            $groups[$group_key]['entries'][] = $localized_entry;
        }

        foreach ( $groups as $group_key => $group ) {
            usort(
                $groups[$group_key]['entries'],
                function( array $left, array $right ) : int {
                    if ( $left['updated_at_utc'] !== $right['updated_at_utc'] ) {
                        return( strcmp( $right['updated_at_utc'], $left['updated_at_utc'] ) );
                    }

                    return( strcmp( $left['title'], $right['title'] ) );
                }
            );
        }

        uasort(
            $groups,
            function( array $left, array $right ) : int {
                return( strcmp( $left['author_slug'], $right['author_slug'] ) );
            }
        );

        return( array_values( $groups ) );
    }

    public function reassignOrphanedEntries( string $from_author_slug, string $to_author_slug ) : array {
        return( $this->withLockedContentStore( function() use ( $from_author_slug, $to_author_slug ) : array {
            $from_author_slug = $this->normalizeEditorPathPart( $from_author_slug );
            $to_author_slug = $this->normalizeEditorPathPart( $to_author_slug );
            if ( $from_author_slug === '' || $to_author_slug === '' || ! $this->isDeletedAuthorSlug( $from_author_slug ) ) {
                throw new \InvalidArgumentException( 'orphan_author_slug' );
            }
            if ( $from_author_slug === $to_author_slug ) {
                throw new \InvalidArgumentException( 'target_author_slug' );
            }

            $raw_entries = $this->getRawEntries();
            $matches = [];
            foreach ( $raw_entries as $index => $raw_entry ) {
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if ( (string) ( $raw_entry['scope'] ?? 'root' ) !== 'author' ) {
                    continue;
                }
                if ( $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) !== $from_author_slug ) {
                    continue;
                }
                $matches[$index] = $raw_entry;
            }

            if ( empty( $matches ) ) {
                return( [ 'reassigned_entries' => 0 ] );
            }

            foreach ( $matches as $index => $raw_entry ) {
                foreach ( $raw_entries as $candidate_index => $candidate_entry ) {
                    if ( ! is_array( $candidate_entry ) || array_key_exists( $candidate_index, $matches ) ) {
                        continue;
                    }
                    if (
                        (string) ( $candidate_entry['type'] ?? '' ) === (string) ( $raw_entry['type'] ?? '' ) &&
                        (string) ( $candidate_entry['scope'] ?? 'root' ) === 'author' &&
                        $this->normalizeEditorPathPart( (string) ( $candidate_entry['author_slug'] ?? '' ) ) === $to_author_slug &&
                        $this->normalizeEditorPathPart( (string) ( $candidate_entry['slug'] ?? '' ) ) === $this->normalizeEditorPathPart( (string) ( $raw_entry['slug'] ?? '' ) )
                    ) {
                        throw new \InvalidArgumentException( 'orphan_conflict' );
                    }
                }
            }

            foreach ( $matches as $index => $raw_entry ) {
                $raw_entries[$index]['author_slug'] = $to_author_slug;
            }

            $this->writeRawEntries( $raw_entries );
            return( [ 'reassigned_entries' => count( $matches ) ] );
        } ) );
    }

    public function deleteEntriesByAuthorSlug( string $author_slug ) : array {
        return( $this->withLockedContentStore( function() use ( $author_slug ) : array {
            $author_slug = $this->normalizeEditorPathPart( $author_slug );
            if ( $author_slug === '' ) {
                throw new \InvalidArgumentException( 'author_slug' );
            }

            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) === 'author' &&
                    $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) === $author_slug
                ) {
                    $entry_directory = dirname( $metadata_file );
                    if ( is_dir( $entry_directory ) ) {
                        $this->deleteDirectoryRecursively( $entry_directory );
                        $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                        $deleted_count++;
                    }
                }
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( [ 'deleted_entries' => $deleted_count ] );
        } ) );
    }

    public function deletePostsByAuthorSlug( string $author_slug ) : array {
        return( $this->withLockedContentStore( function() use ( $author_slug ) : array {
            $author_slug = $this->normalizeEditorPathPart( $author_slug );
            if ( $author_slug === '' ) {
                throw new \InvalidArgumentException( 'author_slug' );
            }

            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) === 'author' &&
                    $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) === $author_slug &&
                    (string) ( $raw_entry['type'] ?? '' ) === 'post'
                ) {
                    $entry_directory = dirname( $metadata_file );
                    if ( is_dir( $entry_directory ) ) {
                        $this->deleteDirectoryRecursively( $entry_directory );
                        $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                        $deleted_count++;
                    }
                }
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( [ 'deleted_posts' => $deleted_count ] );
        } ) );
    }

    public function deletePagesByAuthorSlug( string $author_slug ) : array {
        return( $this->withLockedContentStore( function() use ( $author_slug ) : array {
            $author_slug = $this->normalizeEditorPathPart( $author_slug );
            if ( $author_slug === '' ) {
                throw new \InvalidArgumentException( 'author_slug' );
            }

            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) === 'author' &&
                    $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) === $author_slug &&
                    (string) ( $raw_entry['type'] ?? '' ) === 'page'
                ) {
                    $entry_directory = dirname( $metadata_file );
                    if ( is_dir( $entry_directory ) ) {
                        $this->deleteDirectoryRecursively( $entry_directory );
                        $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                        $deleted_count++;
                    }
                }
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( [ 'deleted_pages' => $deleted_count ] );
        } ) );
    }

    public function deleteRootPosts() : array {
        return( $this->withLockedContentStore( function() : array {
            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) !== 'root' ||
                    (string) ( $raw_entry['type'] ?? '' ) !== 'post'
                ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                $deleted_count++;
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( [ 'deleted_posts' => $deleted_count ] );
        } ) );
    }

    public function deleteRootPages() : array {
        return( $this->withLockedContentStore( function() : array {
            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) !== 'root' ||
                    (string) ( $raw_entry['type'] ?? '' ) !== 'page'
                ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                $deleted_count++;
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( [ 'deleted_pages' => $deleted_count ] );
        } ) );
    }

    public function deleteAllAuthorContent( string $author_slug ) : array {
        return( $this->withLockedContentStore( function() use ( $author_slug ) : array {
            $author_slug = $this->normalizeEditorPathPart( $author_slug );
            if ( $author_slug === '' ) {
                throw new \InvalidArgumentException( 'author_slug' );
            }

            $deleted_posts = 0;
            $deleted_pages = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }

                if (
                    (string) ( $raw_entry['scope'] ?? 'root' ) !== 'author' ||
                    $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) !== $author_slug
                ) {
                    continue;
                }

                $entry_type = (string) ( $raw_entry['type'] ?? '' );
                if ( ! in_array( $entry_type, [ 'post', 'page' ], true ) ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                if ( $entry_type === 'page' ) {
                    $deleted_pages++;
                } else {
                    $deleted_posts++;
                }
            }

            if ( $deleted_posts > 0 || $deleted_pages > 0 ) {
                $this->clearDerivedCaches();
            }

            return(
                [
                    'deleted_posts' => $deleted_posts,
                    'deleted_pages' => $deleted_pages,
                    'deleted_entries' => $deleted_posts + $deleted_pages,
                ]
            );
        } ) );
    }

    public function saveEditorEntry( array $editor_entry, string $status = 'published', string $loaded_entry_id = '', array $options = [], array &$save_timings = [] ) : array {
        $lock_started_at = microtime( true );
        return( $this->withLockedContentStore( function() use ( $editor_entry, $status, $loaded_entry_id, $options, &$save_timings, $lock_started_at ) : array {
            $save_timings['lock_wait'] = ( microtime( true ) - $lock_started_at ) * 1000;
            $timing_started_at = microtime( true );
            $normalized_editor_entry = $this->normalizeEditorEntryInput( $editor_entry );
            $save_timings['normalize_input'] = ( microtime( true ) - $timing_started_at ) * 1000;

            $timing_started_at = microtime( true );
            $this->validateEditorEntryInput( $normalized_editor_entry );
            $save_timings['validate_input'] = ( microtime( true ) - $timing_started_at ) * 1000;

            return( $this->saveNormalizedEditorEntry( $normalized_editor_entry, $status, trim( $loaded_entry_id ), $options, $save_timings ) );
        } ) );
    }

    public function importEditorEntry( array $editor_entry, string $status = 'published', array $options = [] ) : array {
        return( $this->withLockedContentStore( function() use ( $editor_entry, $status, $options ) : array {
            $normalized_editor_entry = $this->normalizeEditorEntryInput( $editor_entry );
            $this->validateEditorEntryInput( $normalized_editor_entry );
            $published_at_override = $this->normalizeImportTimestamp( (string) ( $options['published_at_utc'] ?? '' ) );
            $updated_at_override = $this->normalizeImportTimestamp( (string) ( $options['updated_at_utc'] ?? '' ) );
            $write_revision = ! empty( $options['write_revision'] );

            foreach ( $this->getEntries() as $existing_entry ) {
                if (
                    (string) ( $existing_entry['type'] ?? '' ) === $normalized_editor_entry['type'] &&
                    (string) ( $existing_entry['scope'] ?? '' ) === $normalized_editor_entry['scope'] &&
                    (string) ( $existing_entry['author_slug'] ?? '' ) === $normalized_editor_entry['author_slug'] &&
                    (string) ( $existing_entry['slug'] ?? '' ) === $normalized_editor_entry['slug']
                ) {
                    throw new \InvalidArgumentException( 'duplicate_slug' );
                }
            }

            $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
            $normalized_status = $this->normalizeStatus( $status );
            $published_at_utc = $published_at_override;
            if ( $normalized_status === 'published' && $published_at_utc === '' ) {
                $published_at_utc = $now_utc;
            }

            $raw_entry = [
                'id' => $this->generateEntryId(),
                'type' => $normalized_editor_entry['type'],
                'scope' => $normalized_editor_entry['scope'],
                'slug' => $normalized_editor_entry['slug'],
                'status' => $normalized_status,
                'default_language' => 'en',
                'published_at_utc' => $published_at_utc,
                'updated_at_utc' => $updated_at_override !== '' ? $updated_at_override : $now_utc,
                'aggregate_to_root' => $normalized_editor_entry['type'] === 'post' ? $normalized_editor_entry['aggregate_to_root'] : false,
                'sticky' => $normalized_editor_entry['sticky'],
                'featured' => $normalized_editor_entry['featured'],
                'seo_title' => $normalized_editor_entry['seo_title'],
                'seo_description' => $normalized_editor_entry['seo_description'],
                'seo_social_title' => $normalized_editor_entry['seo_social_title'],
                'seo_social_description' => $normalized_editor_entry['seo_social_description'],
                'seo_social_image_media_id' => $normalized_editor_entry['seo_social_image_media_id'],
                'seo_social_image' => $normalized_editor_entry['seo_social_image'],
                'seo_canonical_url' => $normalized_editor_entry['seo_canonical_url'],
                'seo_robots' => $normalized_editor_entry['seo_robots'],
                'seo_exclude_from_sitemap' => $normalized_editor_entry['seo_exclude_from_sitemap'],
                'translations' => [
                    'en' => [
                        'title' => $normalized_editor_entry['title'],
                        'summary' => $normalized_editor_entry['summary'],
                        'content' => $normalized_editor_entry['content'],
                    ],
                ],
                'tags' => $normalized_editor_entry['tags'],
                'labels' => [],
                'plugin_settings' => $normalized_editor_entry['plugin_settings'],
                'featured_image_media_id' => $normalized_editor_entry['featured_image_media_id'],
                'featured_image' => $normalized_editor_entry['featured_image'],
                'fallback_cover_color' => $normalized_editor_entry['fallback_cover_color'],
            ];

            if ( $normalized_editor_entry['scope'] === 'author' ) {
                $raw_entry['author_slug'] = $normalized_editor_entry['author_slug'];
            }
            if ( $normalized_editor_entry['type'] === 'page' ) {
                $raw_entry['parent_slug'] = $normalized_editor_entry['parent_slug'];
                $raw_entry['sort_order'] = $normalized_editor_entry['sort_order'];
                $raw_entry['show_in_navigation'] = $normalized_editor_entry['show_in_navigation'];
            } else {
                $raw_entry['sort_order'] = 0;
                $raw_entry['show_in_navigation'] = false;
            }

            $this->writeRawEntryToStorage( $raw_entry, true );
            $saved_entry = $this->normalizeEntry( $raw_entry );
            if ( ! is_array( $saved_entry ) ) {
                throw new \RuntimeException( 'Imported entry could not be normalized after write.' );
            }

            $this->entries = is_array( $this->entries ) ? array_merge( $this->entries, [ $saved_entry ] ) : null;
            if ( $write_revision ) {
                $this->writeRevisionSnapshot( $saved_entry );
            }

            return( $saved_entry );
        } ) );
    }

    protected function saveNormalizedEditorEntry( array $normalized_editor_entry, string $status = 'published', string $loaded_entry_id = '', array $options = [], array &$save_timings = [] ) : array {
        $published_at_override = $this->normalizeImportTimestamp( (string) ( $options['published_at_utc'] ?? '' ) );
        $updated_at_override = $this->normalizeImportTimestamp( (string) ( $options['updated_at_utc'] ?? '' ) );
        $write_revision = array_key_exists( 'write_revision', $options ) ? ! empty( $options['write_revision'] ) : true;
        $loaded_entry_id = trim( $loaded_entry_id );
        $existing_raw_entry = [];
        $existing_entry_directory = '';
        $lookup_started_at = microtime( true );
        $loaded_entry_hint = is_array( $options['loaded_entry_hint'] ?? null ) ? $options['loaded_entry_hint'] : [];
        if ( $loaded_entry_id !== '' && ! empty( $loaded_entry_hint ) && (string) ( $loaded_entry_hint['id'] ?? '' ) === $loaded_entry_id ) {
            $existing_entry_directory = $this->getEntryDirectory(
                [
                    'id' => $loaded_entry_id,
                    'type' => (string) ( $loaded_entry_hint['type'] ?? $normalized_editor_entry['type'] ),
                    'scope' => (string) ( $loaded_entry_hint['scope'] ?? $normalized_editor_entry['scope'] ),
                    'author_slug' => (string) ( $loaded_entry_hint['author_slug'] ?? '' ),
                ]
            );
            $metadata_file = $existing_entry_directory . DIRECTORY_SEPARATOR . 'entry.json';
            $existing_raw_entry = $this->readRawEntryFromStorage( $metadata_file ) ?? [];
        }

        $skip_duplicate_scan = ! empty( $existing_raw_entry )
            && (string) ( $existing_raw_entry['type'] ?? '' ) === $normalized_editor_entry['type']
            && (string) ( $existing_raw_entry['scope'] ?? 'root' ) === $normalized_editor_entry['scope']
            && $this->normalizeEditorPathPart( (string) ( $existing_raw_entry['author_slug'] ?? '' ) ) === $normalized_editor_entry['author_slug']
            && $this->normalizeEditorPathPart( (string) ( $existing_raw_entry['slug'] ?? '' ) ) === $normalized_editor_entry['slug'];

        if ( ! $skip_duplicate_scan ) {
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry_metadata = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry_metadata ) ) {
                    continue;
                }
                if ( $this->rawEntryIsTrashed( $raw_entry_metadata ) ) {
                    continue;
                }

                $raw_entry_id = trim( (string) ( $raw_entry_metadata['id'] ?? '' ) );
                if ( $loaded_entry_id !== '' && $raw_entry_id === $loaded_entry_id && empty( $existing_raw_entry ) ) {
                    $existing_entry_directory = dirname( $metadata_file );
                    $existing_raw_entry = $this->readRawEntryFromStorage( $metadata_file ) ?? $raw_entry_metadata;
                }

                $raw_scope = ( ! empty( $raw_entry_metadata['scope'] ) && (string) $raw_entry_metadata['scope'] === 'author' ) ? 'author' : 'root';
                $raw_author_slug = $raw_scope === 'author' ? $this->normalizeEditorPathPart( (string) ( $raw_entry_metadata['author_slug'] ?? '' ) ) : '';
                $raw_slug = $this->normalizeEditorPathPart( (string) ( $raw_entry_metadata['slug'] ?? '' ) );
                $raw_type = (string) ( $raw_entry_metadata['type'] ?? '' );
                if (
                    $raw_type === $normalized_editor_entry['type'] &&
                    $raw_scope === $normalized_editor_entry['scope'] &&
                    $raw_author_slug === $normalized_editor_entry['author_slug'] &&
                    $raw_slug === $normalized_editor_entry['slug'] &&
                    $raw_entry_id !== '' &&
                    $raw_entry_id !== $loaded_entry_id
                ) {
                    throw new \InvalidArgumentException( 'duplicate_slug' );
                }
            }
        }
        $save_timings['lookup_existing'] = ( microtime( true ) - $lookup_started_at ) * 1000;

        $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $normalized_status = $this->normalizeStatus( $status );
        $entry_id = ! empty( $existing_raw_entry['id'] ) ? trim( (string) $existing_raw_entry['id'] ) : '';
        if ( $entry_id === '' ) {
            $entry_id = $this->generateEntryId();
        }

        $published_at_utc = $published_at_override !== '' ? $published_at_override : (string) ( $existing_raw_entry['published_at_utc'] ?? '' );
        if ( $normalized_status === 'published' && $published_at_utc === '' ) {
            $published_at_utc = $now_utc;
        }

        $updated_at_utc = $updated_at_override !== '' ? $updated_at_override : $now_utc;

        $raw_entry = [
            'id' => $entry_id,
            'type' => $normalized_editor_entry['type'],
            'scope' => $normalized_editor_entry['scope'],
            'slug' => $normalized_editor_entry['slug'],
            'status' => $normalized_status,
            'default_language' => 'en',
            'published_at_utc' => $published_at_utc,
            'updated_at_utc' => $updated_at_utc,
            'aggregate_to_root' => $normalized_editor_entry['type'] === 'post' ? $normalized_editor_entry['aggregate_to_root'] : false,
            'sticky' => $normalized_editor_entry['sticky'],
            'featured' => $normalized_editor_entry['featured'],
            'seo_title' => $normalized_editor_entry['seo_title'],
            'seo_description' => $normalized_editor_entry['seo_description'],
            'seo_social_title' => $normalized_editor_entry['seo_social_title'],
            'seo_social_description' => $normalized_editor_entry['seo_social_description'],
            'seo_social_image_media_id' => $normalized_editor_entry['seo_social_image_media_id'],
            'seo_social_image' => $normalized_editor_entry['seo_social_image'],
            'seo_canonical_url' => $normalized_editor_entry['seo_canonical_url'],
            'seo_robots' => $normalized_editor_entry['seo_robots'],
            'seo_exclude_from_sitemap' => $normalized_editor_entry['seo_exclude_from_sitemap'],
            'translations' => [
                'en' => [
                    'title' => $normalized_editor_entry['title'],
                    'summary' => $normalized_editor_entry['summary'],
                    'content' => $normalized_editor_entry['content'],
                ],
            ],
            'tags' => $normalized_editor_entry['tags'],
            'labels' => is_array( $existing_raw_entry['labels'] ?? null ) ? array_values( $existing_raw_entry['labels'] ) : [],
            'plugin_settings' => $normalized_editor_entry['plugin_settings'],
            'featured_image_media_id' => $normalized_editor_entry['featured_image_media_id'],
            'featured_image' => $normalized_editor_entry['featured_image'],
            'fallback_cover_color' => $normalized_editor_entry['fallback_cover_color'],
        ];

        if ( $normalized_editor_entry['scope'] === 'author' ) {
            $raw_entry['author_slug'] = $normalized_editor_entry['author_slug'];
        }
        if ( $normalized_editor_entry['type'] === 'page' ) {
            $raw_entry['parent_slug'] = $normalized_editor_entry['parent_slug'];
            $raw_entry['sort_order'] = $normalized_editor_entry['sort_order'];
            $raw_entry['show_in_navigation'] = $normalized_editor_entry['show_in_navigation'];
        } else {
            $raw_entry['sort_order'] = 0;
            $raw_entry['show_in_navigation'] = false;
        }

        if (
            ! empty( $existing_raw_entry ) &&
            $updated_at_override === '' &&
            ! $this->hasContentTimestampRawEntryChanges( $existing_raw_entry, $raw_entry )
        ) {
            $raw_entry['updated_at_utc'] = (string) ( $existing_raw_entry['updated_at_utc'] ?? $raw_entry['updated_at_utc'] );
        }

        if ( ! empty( $existing_raw_entry ) && ! $this->hasMeaningfulRawEntryChanges( $existing_raw_entry, $raw_entry ) ) {
            $saved_entry = $this->normalizeEntry( $existing_raw_entry );
            if ( ! is_array( $saved_entry ) ) {
                throw new \RuntimeException( 'Saved entry could not be normalized.' );
            }

            return( $saved_entry );
        }

        $timing_started_at = microtime( true );
        $this->writeRawEntryToStorage( $raw_entry, false, $existing_entry_directory );
        $save_timings['write_entry'] = ( microtime( true ) - $timing_started_at ) * 1000;

        $timing_started_at = microtime( true );
        $this->clearDerivedCaches();
        $save_timings['clear_caches'] = ( microtime( true ) - $timing_started_at ) * 1000;

        $timing_started_at = microtime( true );
        $saved_entry = $this->normalizeEntry( $raw_entry );
        $save_timings['normalize_saved'] = ( microtime( true ) - $timing_started_at ) * 1000;
        if ( ! is_array( $saved_entry ) ) {
            throw new \RuntimeException( 'Saved entry could not be normalized.' );
        }

        if ( $write_revision ) {
            $timing_started_at = microtime( true );
            $this->writeRevisionSnapshot( $saved_entry );
            $save_timings['write_revision'] = ( microtime( true ) - $timing_started_at ) * 1000;
        }

        return( $saved_entry );
    }

    protected function hasMeaningfulRawEntryChanges( array $existing_raw_entry, array $candidate_raw_entry ) : bool {
        return( $this->buildComparableRawEntryState( $existing_raw_entry ) !== $this->buildComparableRawEntryState( $candidate_raw_entry ) );
    }

    protected function hasContentTimestampRawEntryChanges( array $existing_raw_entry, array $candidate_raw_entry ) : bool {
        $existing_state = $this->buildComparableRawEntryState( $existing_raw_entry );
        $candidate_state = $this->buildComparableRawEntryState( $candidate_raw_entry );
        unset(
            $existing_state['status'],
            $existing_state['published_at_utc'],
            $candidate_state['status'],
            $candidate_state['published_at_utc']
        );

        return( $existing_state !== $candidate_state );
    }

    protected function buildComparableRawEntryState( array $raw_entry ) : array {
        $normalized_entry = $this->normalizeEntry( $raw_entry );
        if ( ! is_array( $normalized_entry ) ) {
            return( [] );
        }

        $default_language = (string) ( $normalized_entry['default_language'] ?? 'en' );
        $translations = is_array( $raw_entry['translations'] ?? null ) ? $raw_entry['translations'] : [];
        $translation = is_array( $translations[$default_language] ?? null ) ? $translations[$default_language] : [];

        return(
            [
                'type' => $normalized_entry['type'],
                'scope' => $normalized_entry['scope'],
                'author_slug' => $normalized_entry['author_slug'],
                'slug' => $normalized_entry['slug'],
                'status' => $normalized_entry['status'],
                'aggregate_to_root' => $normalized_entry['aggregate_to_root'],
                'sticky' => $normalized_entry['sticky'],
                'featured' => $normalized_entry['featured'],
                'seo_title' => $normalized_entry['seo_title'],
                'seo_description' => $normalized_entry['seo_description'],
                'seo_social_title' => $normalized_entry['seo_social_title'],
                'seo_social_description' => $normalized_entry['seo_social_description'],
                'seo_social_image_media_id' => $normalized_entry['seo_social_image_media_id'],
                'seo_social_image' => $normalized_entry['seo_social_image'],
                'seo_canonical_url' => $normalized_entry['seo_canonical_url'],
                'seo_robots' => $normalized_entry['seo_robots'],
                'seo_exclude_from_sitemap' => $normalized_entry['seo_exclude_from_sitemap'],
                'parent_slug' => $normalized_entry['parent_slug'],
                'sort_order' => $normalized_entry['sort_order'],
                'show_in_navigation' => $normalized_entry['show_in_navigation'],
                'featured_image_media_id' => $normalized_entry['featured_image_media_id'],
                'featured_image' => $normalized_entry['featured_image'],
                'fallback_cover_color' => $normalized_entry['fallback_cover_color'],
                'tags' => $normalized_entry['tags'],
                'labels' => $normalized_entry['labels'],
                'plugin_settings' => $this->normalizeComparablePluginSettings( $normalized_entry['plugin_settings'] ),
                'title' => trim( (string) ( $translation['title'] ?? '' ) ),
                'summary' => trim( (string) ( $translation['summary'] ?? '' ) ),
                'content' => (string) ( $translation['content'] ?? '' ),
            ]
        );
    }

    public function updateEntryStatus( string $entry_id, string $status, ?string $author_slug = null, bool $allow_root = true ) : array {
        return( $this->withLockedContentStore( function() use ( $entry_id, $status, $author_slug, $allow_root ) : array {
            $entry_id = trim( $entry_id );
            if ( $entry_id === '' ) {
                throw new \InvalidArgumentException( 'editor_entry_access' );
            }

            $accessible_entry = $this->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
            if ( ! is_array( $accessible_entry ) ) {
                throw new \InvalidArgumentException( 'editor_entry_access' );
            }

            $raw_entries = $this->getRawEntries();
            $existing_index = $this->findRawEntryIndexById( $raw_entries, $entry_id );
            if ( $existing_index === null || ! is_array( $raw_entries[$existing_index] ) ) {
                throw new \RuntimeException( 'The entry could not be updated.' );
            }

            $normalized_status = $this->normalizeStatus( $status );
            $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
            $raw_entries[$existing_index]['status'] = $normalized_status;
            if ( $normalized_status === 'published' && empty( $raw_entries[$existing_index]['published_at_utc'] ) ) {
                $raw_entries[$existing_index]['published_at_utc'] = $now_utc;
            }

            $this->writeRawEntries( $raw_entries );
            $saved_entry = $this->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
            if ( ! is_array( $saved_entry ) ) {
                throw new \RuntimeException( 'Updated entry could not be reloaded.' );
            }

            $this->writeRevisionSnapshot( $saved_entry );

            return( $saved_entry );
        } ) );
    }

    public function updateEntriesStatusByIds( array $entry_ids, string $status, ?string $author_slug = null, bool $allow_root = true ) : int {
        return( $this->withLockedContentStore( function() use ( $entry_ids, $status, $author_slug, $allow_root ) : int {
            $normalized_ids = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn( mixed $value ) : string => trim( (string) $value ),
                            $entry_ids
                        ),
                        static fn( string $value ) : bool => $value !== ''
                    )
                )
            );
            if ( empty( $normalized_ids ) ) {
                return( 0 );
            }

            $target_ids = array_fill_keys( $normalized_ids, true );
            $normalized_status = $this->normalizeStatus( $status );
            $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
            $changed_entries = [];

            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }

                $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
                if ( $entry_id === '' || ! isset( $target_ids[$entry_id] ) ) {
                    continue;
                }
                if ( ! $this->rawEntryMatchesEditorAccess( $raw_entry, $author_slug, $allow_root ) ) {
                    continue;
                }
                if ( (string) ( $raw_entry['status'] ?? 'unpublished' ) === $normalized_status ) {
                    unset( $target_ids[$entry_id] );
                    continue;
                }

                $raw_entry['status'] = $normalized_status;
                if ( $normalized_status === 'published' && empty( $raw_entry['published_at_utc'] ) ) {
                    $raw_entry['published_at_utc'] = $now_utc;
                }

                $this->writeRawEntryMetadataToStorage( $metadata_file, $raw_entry );
                $changed_entry = $this->readRawEntryFromStorage( $metadata_file );
                if ( is_array( $changed_entry ) ) {
                    $normalized_entry = $this->normalizeEntry( $changed_entry );
                    if ( is_array( $normalized_entry ) ) {
                        $changed_entries[] = $normalized_entry;
                    }
                }

                unset( $target_ids[$entry_id] );
                if ( empty( $target_ids ) ) {
                    break;
                }
            }

            if ( empty( $changed_entries ) ) {
                return( 0 );
            }

            $this->clearDerivedCaches();
            foreach ( $changed_entries as $changed_entry ) {
                $this->writeRevisionSnapshot( $changed_entry );
            }

            return( count( $changed_entries ) );
        } ) );
    }

    public function deleteEntryById( string $entry_id, ?string $author_slug = null, bool $allow_root = true ) : bool {
        return( $this->withLockedContentStore( function() use ( $entry_id, $author_slug, $allow_root ) : bool {
            $entry_id = trim( $entry_id );
            if ( $entry_id === '' ) {
                throw new \InvalidArgumentException( 'editor_entry_access' );
            }

            $accessible_entry = $this->getAccessibleEntryById( $entry_id, null, $author_slug, $allow_root );
            if ( ! is_array( $accessible_entry ) ) {
                throw new \InvalidArgumentException( 'editor_entry_access' );
            }

            $entry_directory = $this->getEntryDirectory( $accessible_entry );
            if ( ! is_dir( $entry_directory ) ) {
                throw new \RuntimeException( 'The entry could not be deleted.' );
            }

            $this->deleteDirectoryRecursively( $entry_directory );
            $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
            $this->clearDerivedCaches();
            return( true );
        } ) );
    }

    public function deleteEntriesByIds( array $entry_ids, ?string $author_slug = null, bool $allow_root = true ) : int {
        return( $this->withLockedContentStore( function() use ( $entry_ids, $author_slug, $allow_root ) : int {
            $normalized_ids = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn( mixed $value ) : string => trim( (string) $value ),
                            $entry_ids
                        ),
                        static fn( string $value ) : bool => $value !== ''
                    )
                )
            );
            if ( empty( $normalized_ids ) ) {
                return( 0 );
            }

            $target_ids = array_fill_keys( $normalized_ids, true );
            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }

                $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
                if ( $entry_id === '' || ! isset( $target_ids[$entry_id] ) ) {
                    continue;
                }

                if ( ! $this->rawEntryMatchesEditorAccess( $raw_entry, $author_slug, $allow_root ) ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                unset( $target_ids[$entry_id] );
                $deleted_count++;
                if ( empty( $target_ids ) ) {
                    break;
                }
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( $deleted_count );
        } ) );
    }

    public function moveEntriesToTrashByIds( array $entry_ids, string $trashed_by = '', ?string $author_slug = null, bool $allow_root = true ) : int {
        return( $this->withLockedContentStore( function() use ( $entry_ids, $trashed_by, $author_slug, $allow_root ) : int {
            $normalized_ids = $this->normalizeEntryIdList( $entry_ids );
            if ( empty( $normalized_ids ) ) {
                return( 0 );
            }

            $target_ids = array_fill_keys( $normalized_ids, true );
            $trashed_by = trim( $trashed_by );
            $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
            $changed_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }

                $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
                if ( $entry_id === '' || ! isset( $target_ids[$entry_id] ) ) {
                    continue;
                }
                if ( ! $this->rawEntryMatchesEditorAccess( $raw_entry, $author_slug, $allow_root ) ) {
                    continue;
                }
                if ( $this->rawEntryIsTrashed( $raw_entry ) ) {
                    unset( $target_ids[$entry_id] );
                    continue;
                }

                $pre_trash_status = $this->normalizePreTrashStatus( (string) ( $raw_entry['status'] ?? 'unpublished' ) );
                $raw_entry['pre_trash_status'] = $pre_trash_status;
                $raw_entry['status'] = 'trashed';
                $raw_entry['trashed_at_utc'] = $now_utc;
                $raw_entry['trashed_by'] = $trashed_by;
                $this->writeRawEntryToStorage( $raw_entry, false, dirname( $metadata_file ) );
                $changed_count++;
                unset( $target_ids[$entry_id] );
                if ( empty( $target_ids ) ) {
                    break;
                }
            }

            if ( $changed_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( $changed_count );
        } ) );
    }

    public function restoreTrashedEntriesByIds( array $entry_ids, ?string $author_slug = null, bool $allow_root = true ) : int {
        return( $this->withLockedContentStore( function() use ( $entry_ids, $author_slug, $allow_root ) : int {
            $normalized_ids = $this->normalizeEntryIdList( $entry_ids );
            if ( empty( $normalized_ids ) ) {
                return( 0 );
            }

            $raw_entries = $this->getRawEntries();
            $target_ids = array_fill_keys( $normalized_ids, true );
            $target_indexes = [];
            foreach ( $raw_entries as $index => $raw_entry ) {
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
                if ( $entry_id === '' || ! isset( $target_ids[$entry_id] ) ) {
                    continue;
                }
                if ( ! $this->rawEntryMatchesEditorAccess( $raw_entry, $author_slug, $allow_root ) ) {
                    continue;
                }
                if ( ! $this->rawEntryIsTrashed( $raw_entry ) ) {
                    continue;
                }
                $target_indexes[$index] = $entry_id;
            }

            if ( empty( $target_indexes ) ) {
                return( 0 );
            }

            foreach ( $target_indexes as $index => $entry_id ) {
                $raw_entry = $raw_entries[$index];
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }
                $this->assertTrashedEntryCanRestore( $raw_entry, $raw_entries, $entry_id, $target_ids );
            }

            $restored_count = 0;
            foreach ( array_keys( $target_indexes ) as $index ) {
                if ( ! is_array( $raw_entries[$index] ) ) {
                    continue;
                }
                $raw_entries[$index]['status'] = 'unpublished';
                unset( $raw_entries[$index]['trashed_at_utc'], $raw_entries[$index]['trashed_by'] );

                $normalized_entry = $this->normalizeEntry( $raw_entries[$index] );
                if ( ! is_array( $normalized_entry ) ) {
                    throw new \RuntimeException( 'Restored entry could not be normalized.' );
                }

                $this->writeRawEntryToStorage( $raw_entries[$index], false, $this->getEntryDirectory( $normalized_entry ) );
                $restored_count++;
            }

            if ( $restored_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( $restored_count );
        } ) );
    }

    public function permanentlyDeleteTrashedEntriesByIds( array $entry_ids, ?string $author_slug = null, bool $allow_root = true ) : int {
        return( $this->withLockedContentStore( function() use ( $entry_ids, $author_slug, $allow_root ) : int {
            $normalized_ids = $this->normalizeEntryIdList( $entry_ids );
            if ( empty( $normalized_ids ) ) {
                return( 0 );
            }

            $target_ids = array_fill_keys( $normalized_ids, true );
            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) ) {
                    continue;
                }

                $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
                if ( $entry_id === '' || ! isset( $target_ids[$entry_id] ) ) {
                    continue;
                }
                if ( ! $this->rawEntryMatchesEditorAccess( $raw_entry, $author_slug, $allow_root ) || ! $this->rawEntryIsTrashed( $raw_entry ) ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                unset( $target_ids[$entry_id] );
                $deleted_count++;
                if ( empty( $target_ids ) ) {
                    break;
                }
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( $deleted_count );
        } ) );
    }

    public function purgeTrashedEntriesOlderThan( int $retention_days ) : int {
        $retention_days = max( 0, min( 90, $retention_days ) );
        if ( $retention_days < 1 ) {
            return( 0 );
        }

        return( $this->withLockedContentStore( function() use ( $retention_days ) : int {
            $cutoff_timestamp = time() - ( $retention_days * 86400 );
            $deleted_count = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
                if ( ! is_array( $raw_entry ) || ! $this->rawEntryIsTrashed( $raw_entry ) ) {
                    continue;
                }

                $trashed_at = strtotime( (string) ( $raw_entry['trashed_at_utc'] ?? '' ) );
                if ( $trashed_at === false || $trashed_at > $cutoff_timestamp ) {
                    continue;
                }

                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }
                $this->deleteDirectoryRecursively( $entry_directory );
                $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
                $deleted_count++;
            }

            if ( $deleted_count > 0 ) {
                $this->clearDerivedCaches();
            }

            return( $deleted_count );
        } ) );
    }

    public function pruneAllRevisionSnapshots() : array {
        return( $this->withLockedContentStore( function() : array {
            $checked_entries = 0;
            $removed_revisions = 0;
            foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                $entry_directory = dirname( $metadata_file );
                if ( ! is_dir( $entry_directory ) ) {
                    continue;
                }

                $checked_entries++;
                $removed_revisions += $this->pruneRevisionSnapshots( $entry_directory );
            }

            return(
                [
                    'checked_entries' => $checked_entries,
                    'removed_revisions' => $removed_revisions,
                    'retention_limit' => $this->revision_retention_limit,
                ]
            );
        } ) );
    }

    protected function getPublishedEntries() : array {
        if ( is_array( $this->published_entries ) ) {
            return( $this->published_entries );
        }

        $index = $this->getPersistentContentIndex();
        $this->published_entries = is_array( $index['published_entries'] ?? null ) ? $index['published_entries'] : [];
        return( $this->published_entries );
    }

    protected function getTrashedEntries() : array {
        $index = $this->getPersistentContentIndex();
        return( is_array( $index['trashed_entries'] ?? null ) ? $index['trashed_entries'] : [] );
    }

    protected function getRawEntries() : array {
        $raw_entries = [];
        foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
            $raw_entry = $this->readRawEntryFromStorage( $metadata_file );
            if ( is_array( $raw_entry ) ) {
                $raw_entries[] = $raw_entry;
            }
        }

        return( $raw_entries );
    }

    protected function getEntries() : array {
        if ( is_array( $this->entries ) ) {
            return( $this->entries );
        }

        $index = $this->getPersistentContentIndex();
        $this->entries = is_array( $index['entries'] ?? null ) ? $index['entries'] : [];
        return( $this->entries );
    }

    protected function writeRawEntries( array $raw_entries ) : void {
        $this->ensureContentDirectory();

        $desired_entry_ids = [];
        foreach ( $raw_entries as $raw_entry ) {
            if ( ! is_array( $raw_entry ) ) {
                continue;
            }
            $written_entry_id = $this->writeRawEntryToStorage( $raw_entry );
            if ( $written_entry_id !== '' ) {
                $desired_entry_ids[] = $written_entry_id;
            }
        }

        $desired_entry_ids = array_values( array_unique( $desired_entry_ids ) );
        foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
            $raw_entry = $this->readRawEntryFromStorage( $metadata_file );
            if ( ! is_array( $raw_entry ) ) {
                continue;
            }
            $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
            if ( $entry_id === '' || in_array( $entry_id, $desired_entry_ids, true ) ) {
                continue;
            }

            $entry_directory = dirname( $metadata_file );
            $this->deleteDirectoryRecursively( $entry_directory );
            $this->cleanupEmptyParentDirectories( dirname( $entry_directory ) );
        }

        $this->clearDerivedCaches();
    }

    protected function normalizeEntry( array $raw_entry ) : ?array {
        $entry_type = ( ! empty( $raw_entry['type'] ) && in_array( $raw_entry['type'], [ 'post', 'page' ], true ) ) ? (string) $raw_entry['type'] : '';
        $scope = ( ! empty( $raw_entry['scope'] ) && $raw_entry['scope'] === 'author' ) ? 'author' : 'root';
        $slug = $this->normalizePathPart( (string) ( $raw_entry['slug'] ?? '' ) );
        $author_slug = $scope === 'author' ? $this->normalizePathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) : '';

        if ( $entry_type === '' || $slug === '' ) {
            return( null );
        }
        if ( $scope === 'author' && $author_slug === '' ) {
            return( null );
        }

        $translations = [];
        if ( ! empty( $raw_entry['translations'] ) && is_array( $raw_entry['translations'] ) ) {
            foreach ( $raw_entry['translations'] as $language => $translation ) {
                if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                    continue;
                }
                $language = strtolower( trim( $language ) );
                if ( $language === '' ) {
                    continue;
                }
                $translations[$language] = [
                    'title' => (string) ( $translation['title'] ?? '' ),
                    'summary' => (string) ( $translation['summary'] ?? '' ),
                    'content' => (string) ( $translation['content'] ?? '' ),
                ];
            }
        }

        $default_language = strtolower( trim( (string) ( $raw_entry['default_language'] ?? 'en' ) ) );
        if ( $default_language === '' ) {
            $default_language = 'en';
        }

        if ( empty( $translations[$default_language] ) ) {
            $translations[$default_language] = [
                'title' => (string) ( $raw_entry['title'] ?? '' ),
                'summary' => (string) ( $raw_entry['summary'] ?? '' ),
                'content' => (string) ( $raw_entry['content'] ?? '' ),
            ];
        }
        if ( $translations[$default_language]['title'] === '' || ! array_key_exists( 'content', $translations[$default_language] ) ) {
            return( null );
        }

        $entry_id = trim( (string) ( $raw_entry['id'] ?? '' ) );
        if ( $entry_id === '' ) {
            $entry_id = $this->buildLegacyEntryId( $scope, $author_slug, $entry_type, $slug );
        }

        return(
            [
                'id' => $entry_id,
                'type' => $entry_type,
                'scope' => $scope,
                'author_slug' => $author_slug,
                'slug' => $slug,
                'status' => $this->normalizeStatus( (string) ( $raw_entry['status'] ?? 'published' ) ),
                'default_language' => $default_language,
                'translations' => $translations,
                'published_at_utc' => (string) ( $raw_entry['published_at_utc'] ?? '' ),
                'updated_at_utc' => (string) ( $raw_entry['updated_at_utc'] ?? '' ),
                'trashed_at_utc' => (string) ( $raw_entry['trashed_at_utc'] ?? '' ),
                'trashed_by' => trim( (string) ( $raw_entry['trashed_by'] ?? '' ) ),
                'pre_trash_status' => $this->normalizePreTrashStatus( (string) ( $raw_entry['pre_trash_status'] ?? $raw_entry['status'] ?? 'unpublished' ) ),
                'aggregate_to_root' => ! empty( $raw_entry['aggregate_to_root'] ),
                'sticky' => ! empty( $raw_entry['sticky'] ),
                'featured' => ! empty( $raw_entry['featured'] ),
                'seo_title' => trim( (string) ( $raw_entry['seo_title'] ?? '' ) ),
                'seo_description' => trim( (string) ( $raw_entry['seo_description'] ?? '' ) ),
                'seo_social_title' => trim( (string) ( $raw_entry['seo_social_title'] ?? '' ) ),
                'seo_social_description' => trim( (string) ( $raw_entry['seo_social_description'] ?? '' ) ),
                'seo_social_image_media_id' => trim( (string) ( $raw_entry['seo_social_image_media_id'] ?? '' ) ),
                'seo_social_image' => $this->normalizeMediaImage( $raw_entry['seo_social_image'] ?? [] ),
                'seo_canonical_url' => trim( (string) ( $raw_entry['seo_canonical_url'] ?? '' ) ),
                'seo_robots' => $this->normalizeSeoRobots( (string) ( $raw_entry['seo_robots'] ?? '' ) ),
                'seo_exclude_from_sitemap' => ! empty( $raw_entry['seo_exclude_from_sitemap'] ),
                'parent_slug' => $this->normalizeParentSlug( (string) ( $raw_entry['parent_slug'] ?? '' ) ),
                'sort_order' => (int) ( $raw_entry['sort_order'] ?? 0 ),
                'show_in_navigation' => array_key_exists( 'show_in_navigation', $raw_entry ) ? ! empty( $raw_entry['show_in_navigation'] ) : ( $entry_type === 'page' ),
                'tags' => $this->normalizeStringList( $raw_entry['tags'] ?? [] ),
                'labels' => $this->normalizeStringList( $raw_entry['labels'] ?? [] ),
                'plugin_settings' => $this->normalizePluginSettings( $raw_entry['plugin_settings'] ?? [] ),
                'featured_image_media_id' => trim( (string) ( $raw_entry['featured_image_media_id'] ?? '' ) ),
                'featured_image' => $this->normalizeMediaImage( $raw_entry['featured_image'] ?? [] ),
                'fallback_cover_color' => $this->normalizeFallbackCoverColor( $raw_entry['fallback_cover_color'] ?? '' ),
            ]
        );
    }

    protected function normalizeEditorEntryInput( array $editor_entry ) : array {
        $entry_type = ( ! empty( $editor_entry['entry_type'] ) && in_array( $editor_entry['entry_type'], [ 'post', 'page' ], true ) ) ? (string) $editor_entry['entry_type'] : 'post';
        $scope = ( ! empty( $editor_entry['scope'] ) && $editor_entry['scope'] === 'author' ) ? 'author' : 'root';

        return(
            [
                'type' => $entry_type,
                'scope' => $scope,
                'author_slug' => $scope === 'author' ? $this->normalizeEditorPathPart( (string) ( $editor_entry['author_slug'] ?? '' ) ) : '',
                'slug' => $this->normalizeEditorPathPart( (string) ( $editor_entry['slug'] ?? '' ) ),
                'title' => trim( (string) ( $editor_entry['title'] ?? '' ) ),
                'summary' => trim( (string) ( $editor_entry['summary'] ?? '' ) ),
                'tags' => $this->normalizeTagList( $editor_entry['tags'] ?? [] ),
                'labels' => [],
                'plugin_settings' => $this->normalizePluginSettings( $editor_entry['plugin_settings'] ?? [] ),
                'seo_title' => trim( (string) ( $editor_entry['seo_title'] ?? '' ) ),
                'seo_description' => trim( (string) ( $editor_entry['seo_description'] ?? '' ) ),
                'seo_social_title' => trim( (string) ( $editor_entry['seo_social_title'] ?? '' ) ),
                'seo_social_description' => trim( (string) ( $editor_entry['seo_social_description'] ?? '' ) ),
                'seo_social_image_media_id' => trim( (string) ( $editor_entry['seo_social_image_media_id'] ?? '' ) ),
                'seo_social_image' => $this->normalizeMediaImage( $editor_entry['seo_social_image'] ?? [] ),
                'seo_canonical_url' => trim( (string) ( $editor_entry['seo_canonical_url'] ?? '' ) ),
                'seo_robots' => $this->normalizeSeoRobots( (string) ( $editor_entry['seo_robots'] ?? '' ) ),
                'seo_exclude_from_sitemap' => ! empty( $editor_entry['seo_exclude_from_sitemap'] ),
                'content' => (string) ( $editor_entry['content'] ?? '' ),
                'parent_slug' => $entry_type === 'page' ? $this->normalizeParentSlug( (string) ( $editor_entry['parent_slug'] ?? '' ) ) : '',
                'sort_order' => $entry_type === 'page' ? (int) ( $editor_entry['sort_order'] ?? 0 ) : 0,
                'aggregate_to_root' => $entry_type === 'post' ? ! empty( $editor_entry['aggregate_to_root'] ) : false,
                'sticky' => ! empty( $editor_entry['sticky'] ),
                'featured' => $entry_type === 'post' ? ! empty( $editor_entry['featured'] ) : false,
                'show_in_navigation' => $entry_type === 'page' ? ! empty( $editor_entry['show_in_navigation'] ) : false,
                'featured_image_media_id' => trim( (string) ( $editor_entry['featured_image_media_id'] ?? '' ) ),
                'featured_image' => $this->normalizeMediaImage( $editor_entry['featured_image'] ?? [] ),
                'fallback_cover_color' => $entry_type === 'post' ? $this->normalizeFallbackCoverColor( $editor_entry['fallback_cover_color'] ?? '' ) : '',
            ]
        );
    }

    protected function normalizeMediaImage( mixed $media_image ) : array {
        if ( ! is_array( $media_image ) ) {
            return( [] );
        }

        $normalized_media_image = [
            'media_id' => trim( (string) ( $media_image['media_id'] ?? '' ) ),
            'owner_username' => $this->normalizePathPart( (string) ( $media_image['owner_username'] ?? '' ) ),
            'filename' => basename( trim( (string) ( $media_image['filename'] ?? '' ) ) ),
            'url' => trim( (string) ( $media_image['url'] ?? '' ) ),
            'alt_text' => trim( (string) ( $media_image['alt_text'] ?? '' ) ),
            'mime' => trim( (string) ( $media_image['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $media_image['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $media_image['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $media_image['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $media_image['derivative_key'] ?? '' ) ),
        ];

        if ( $normalized_media_image['media_id'] === '' || $normalized_media_image['url'] === '' ) {
            return( [] );
        }

        return( $normalized_media_image );
    }

    protected function normalizeFallbackCoverColor( mixed $value ) : string {
        $value = strtolower( trim( (string) $value ) );
        if ( in_array( $value, [ '', 'slate', 'blue', 'green', 'amber', 'berry' ], true ) ) {
            return( $value );
        }

        return( preg_match( '/^#[0-9a-f]{6}$/', $value ) === 1 ? $value : '' );
    }

    protected function normalizePluginSettings( mixed $plugin_settings ) : array {
        if ( ! is_array( $plugin_settings ) ) {
            return( [] );
        }

        $normalized = [];
        foreach ( $plugin_settings as $plugin_key => $settings ) {
            if ( ! is_string( $plugin_key ) || ! is_array( $settings ) ) {
                continue;
            }

            $plugin_key = strtolower( trim( $plugin_key ) );
            $plugin_key = preg_replace( '/[^a-z0-9_-]+/', '', $plugin_key ) ?? '';
            if ( $plugin_key === '' ) {
                continue;
            }

            $normalized[$plugin_key] = [];
            foreach ( $settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }

                $setting_key = strtolower( trim( $setting_key ) );
                $setting_key = preg_replace( '/[^a-z0-9_-]+/', '', $setting_key ) ?? '';
                if ( $setting_key === '' ) {
                    continue;
                }

                if ( is_bool( $setting_value ) ) {
                    $normalized[$plugin_key][$setting_key] = $setting_value;
                } elseif ( is_scalar( $setting_value ) || $setting_value === null ) {
                    $normalized[$plugin_key][$setting_key] = trim( (string) $setting_value );
                }
            }

            if ( empty( $normalized[$plugin_key] ) ) {
                unset( $normalized[$plugin_key] );
            }
        }

        return( $normalized );
    }

    protected function validateEditorEntryInput( array $entry ) : void {
        if ( $entry['title'] === '' ) {
            throw new \InvalidArgumentException( 'Title is required.' );
        }
        if ( $entry['slug'] === '' ) {
            throw new \InvalidArgumentException( 'Slug is required.' );
        }
        if ( $entry['content'] === '' ) {
            throw new \InvalidArgumentException( 'Content is required.' );
        }
        if ( $entry['seo_canonical_url'] !== '' && ! $this->isValidSeoCanonicalUrl( $entry['seo_canonical_url'] ) ) {
            throw new \InvalidArgumentException( 'SEO canonical URL must be an absolute http(s) URL.' );
        }
        if ( $entry['scope'] === 'author' && $entry['author_slug'] === '' ) {
            throw new \InvalidArgumentException( 'Username/Author is required for author-scoped entries.' );
        }
        if ( $entry['type'] === 'page' && $entry['parent_slug'] === $entry['slug'] && $entry['parent_slug'] !== '' ) {
            throw new \InvalidArgumentException( 'Parent slug cannot match slug.' );
        }
    }

    protected function findRawEntryIndexById( array $raw_entries, string $entry_id ) : ?int {
        $entry_id = trim( $entry_id );
        if ( $entry_id === '' ) {
            return( null );
        }

        foreach ( $raw_entries as $index => $raw_entry ) {
            if ( is_array( $raw_entry ) && (string) ( $raw_entry['id'] ?? '' ) === $entry_id ) {
                return( $index );
            }
        }

        return( null );
    }

    protected function normalizeEntryIdList( array $entry_ids ) : array {
        return(
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn( mixed $value ) : string => trim( (string) $value ),
                            $entry_ids
                        ),
                        static fn( string $value ) : bool => $value !== ''
                    )
                )
            )
        );
    }

    protected function assertTrashedEntryCanRestore( array $raw_entry, array $raw_entries, string $entry_id, array $selected_entry_ids = [] ) : void {
        $scope = (string) ( $raw_entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
        $author_slug = $scope === 'author' ? $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) ) : '';
        $entry_type = (string) ( $raw_entry['type'] ?? '' );
        $slug = $this->normalizeEditorPathPart( (string) ( $raw_entry['slug'] ?? '' ) );

        foreach ( $raw_entries as $candidate_entry ) {
            if ( ! is_array( $candidate_entry ) || $this->rawEntryIsTrashed( $candidate_entry ) ) {
                continue;
            }
            if ( trim( (string) ( $candidate_entry['id'] ?? '' ) ) === $entry_id ) {
                continue;
            }

            $candidate_scope = (string) ( $candidate_entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
            $candidate_author_slug = $candidate_scope === 'author' ? $this->normalizeEditorPathPart( (string) ( $candidate_entry['author_slug'] ?? '' ) ) : '';
            if (
                (string) ( $candidate_entry['type'] ?? '' ) === $entry_type &&
                $candidate_scope === $scope &&
                $candidate_author_slug === $author_slug &&
                $this->normalizeEditorPathPart( (string) ( $candidate_entry['slug'] ?? '' ) ) === $slug
            ) {
                throw new \InvalidArgumentException( 'trash_restore_slug_conflict' );
            }
        }

        if ( $entry_type !== 'page' ) {
            return;
        }

        $parent_slug = $this->normalizeParentSlug( (string) ( $raw_entry['parent_slug'] ?? '' ) );
        if ( $parent_slug === '' ) {
            return;
        }

        foreach ( $raw_entries as $candidate_entry ) {
            if ( ! is_array( $candidate_entry ) ) {
                continue;
            }
            $candidate_entry_id = trim( (string) ( $candidate_entry['id'] ?? '' ) );
            if ( $this->rawEntryIsTrashed( $candidate_entry ) && empty( $selected_entry_ids[$candidate_entry_id] ) ) {
                continue;
            }

            $candidate_scope = (string) ( $candidate_entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
            $candidate_author_slug = $candidate_scope === 'author' ? $this->normalizeEditorPathPart( (string) ( $candidate_entry['author_slug'] ?? '' ) ) : '';
            if (
                (string) ( $candidate_entry['type'] ?? '' ) === 'page' &&
                $candidate_scope === $scope &&
                $candidate_author_slug === $author_slug &&
                $this->normalizeEditorPathPart( (string) ( $candidate_entry['slug'] ?? '' ) ) === $parent_slug
            ) {
                return;
            }
        }

        throw new \InvalidArgumentException( 'trash_restore_parent_unavailable' );
    }

    protected function entryMatchesEditorAccess( array $entry, ?string $author_slug = null, bool $allow_root = true ) : bool {
        $author_slug = $author_slug !== null ? $this->normalizeEditorPathPart( $author_slug ) : null;
        if ( $author_slug === null || $author_slug === '' ) {
            return( true );
        }
        if ( $entry['scope'] === 'root' ) {
            return( $allow_root );
        }

        return( $entry['author_slug'] === $author_slug );
    }

    protected function normalizeEditorPathPart( string $value ) : string {
        $value = $this->normalizePathToken( $value );
        $value = str_replace( '_', '-', $value );
        $value = preg_replace( '/\s+/', '-', $value ) ?? '';
        $value = preg_replace( '/[^a-z0-9-]+/', '', $value ) ?? '';
        $value = preg_replace( '/-+/', '-', $value ) ?? '';
        return( trim( $value, '-' ) );
    }

    protected function normalizeParentSlug( string $value ) : string {
        $segments = preg_split( '@/+@', trim( $value, " \t\n\r\0\x0B/" ) ) ?: [];
        $last_segment = ! empty( $segments ) ? (string) end( $segments ) : '';
        return( $this->normalizeEditorPathPart( $last_segment ) );
    }

    protected function normalizeStatus( string $status ) : string {
        $status = strtolower( trim( $status ) );
        if ( in_array( $status, [ 'draft', 'published', 'unpublished', 'pending_review', 'trashed' ], true ) ) {
            return( $status );
        }

        return( 'unpublished' );
    }

    protected function normalizePreTrashStatus( string $status ) : string {
        $status = $this->normalizeStatus( $status );
        return( $status === 'trashed' ? 'unpublished' : $status );
    }

    protected function normalizeSeoRobots( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( in_array( $value, [ '', 'index, follow', 'index, nofollow', 'noindex, follow', 'noindex, nofollow' ], true ) ? $value : '' );
    }

    protected function isValidSeoCanonicalUrl( string $value ) : bool {
        $value = trim( $value );
        if ( $value === '' ) {
            return( true );
        }

        if ( filter_var( $value, FILTER_VALIDATE_URL ) === false ) {
            return( false );
        }

        $scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
        return( in_array( $scheme, [ 'http', 'https' ], true ) );
    }

    protected function normalizeImportTimestamp( string $timestamp ) : string {
        $timestamp = trim( $timestamp );
        if ( $timestamp === '' ) {
            return( '' );
        }

        try {
            $normalized = new \DateTimeImmutable( $timestamp );
        } catch ( \Throwable ) {
            return( '' );
        }

        return( $normalized->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) );
    }

    protected function buildDeletedAuthorSlug( string $deleted_username ) : string {
        $base_slug = '_deleted_' . $deleted_username;
        $candidate = $base_slug;
        $suffix = 1;
        while ( $this->authorSlugExists( $candidate ) ) {
            $candidate = $base_slug . '_' . str_pad( (string) $suffix, 2, '0', STR_PAD_LEFT );
            $suffix++;
        }

        return( $candidate );
    }

    protected function authorSlugExists( string $author_slug ) : bool {
        foreach ( $this->getEntries() as $entry ) {
            if ( $entry['scope'] === 'author' && $entry['author_slug'] === $author_slug ) {
                return( true );
            }
        }

        return( false );
    }

    protected function isDeletedAuthorSlug( string $author_slug ) : bool {
        return( str_starts_with( $author_slug, '_deleted_' ) );
    }

    protected function normalizeDeletedOwnerUsername( string $username ) : string {
        $username = $this->normalizePathToken( $username );
        $username = preg_replace( '/[^a-z0-9_-]+/', '-', $username ) ?? '';
        $username = preg_replace( '/-+/', '-', $username ) ?? '';
        return( trim( $username, '-' ) );
    }

    protected function localizeEntry( array $entry, ?string $language = null ) : array {
        $language = is_string( $language ) ? strtolower( trim( $language ) ) : '';
        if ( $language === '' || empty( $entry['translations'][$language] ) ) {
            $language = $entry['default_language'];
        }

        $cache_key = trim( (string) ( $entry['id'] ?? '' ) ) . '|' . $language;
        if ( $cache_key !== '|' && isset( $this->localized_entry_cache[$cache_key] ) ) {
            return( $this->localized_entry_cache[$cache_key] );
        }

        $translation = $entry['translations'][$language] ?? $entry['translations'][$entry['default_language']];
        $entry['language'] = $language;
        $entry['title'] = (string) $translation['title'];
        $entry['summary'] = (string) $translation['summary'];
        $entry['content'] = (string) $translation['content'];
        $entry['path'] = $this->getEntryPath( $entry );

        if ( $cache_key !== '|' ) {
            $this->localized_entry_cache[$cache_key] = $entry;
        }
        return( $entry );
    }

    protected function getChildPagesForContext( string $scope, string $author_slug, string $parent_slug, bool $navigation_only, ?string $language = null ) : array {
        $cache_key = implode(
            '|',
            [
                $scope,
                $author_slug,
                $parent_slug,
                $navigation_only ? 'navigation' : 'all',
                is_string( $language ) ? strtolower( trim( $language ) ) : '',
            ]
        );
        if ( array_key_exists( $cache_key, $this->child_pages_cache ) ) {
            return( $this->child_pages_cache[$cache_key] );
        }

        $pages = [];
        foreach ( $this->getPublishedEntries() as $entry ) {
            if ( $entry['type'] !== 'page' || $entry['scope'] !== $scope ) {
                continue;
            }
            if ( $entry['author_slug'] !== $author_slug || $entry['parent_slug'] !== $parent_slug ) {
                continue;
            }
            if ( $navigation_only && ! $entry['show_in_navigation'] ) {
                continue;
            }
            $pages[] = $this->localizeEntry( $entry, $language );
        }

        usort(
            $pages,
            function( array $left, array $right ) : int {
                if ( $left['sort_order'] !== $right['sort_order'] ) {
                    return( $left['sort_order'] <=> $right['sort_order'] );
                }
                return( strcmp( $left['title'], $right['title'] ) );
            }
        );

        $this->child_pages_cache[$cache_key] = $pages;
        return( $pages );
    }

    protected function buildPageTreeForContext( string $scope, string $author_slug, bool $navigation_only, ?string $language = null ) : array {
        $cache_key = implode(
            '|',
            [
                $scope,
                $author_slug,
                $navigation_only ? 'navigation' : 'all',
                is_string( $language ) ? strtolower( trim( $language ) ) : '',
            ]
        );
        if ( array_key_exists( $cache_key, $this->page_tree_cache ) ) {
            return( $this->page_tree_cache[$cache_key] );
        }

        $this->page_tree_cache[$cache_key] = $this->buildPageTree(
            $this->getChildPagesForContext( $scope, $author_slug, '', $navigation_only, $language ),
            $navigation_only,
            $language
        );

        return( $this->page_tree_cache[$cache_key] );
    }

    protected function buildPageTree( array $pages, bool $navigation_only = false, ?string $language = null ) : array {
        $tree = [];
        foreach ( $pages as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }
            $page['children'] = $this->buildPageTree(
                $this->getChildPagesForContext(
                    (string) ( $page['scope'] ?? 'root' ),
                    (string) ( $page['author_slug'] ?? '' ),
                    (string) ( $page['slug'] ?? '' ),
                    $navigation_only,
                    $language
                ),
                $navigation_only,
                $language
            );
            $tree[] = $page;
        }

        return( $tree );
    }

    protected function findPageByScopeAndSlug( string $scope, string $author_slug, string $slug ) : ?array {
        $index = $this->getPersistentContentIndex();
        $page_lookup_key = $this->buildPageLookupKey( $scope, $author_slug, $slug );
        if ( $page_lookup_key === '' ) {
            return( null );
        }

        $entry_id = (string) ( $index['published_page_lookup'][$page_lookup_key] ?? '' );
        if ( $entry_id === '' || empty( $index['published_entry_lookup'][$entry_id] ) || ! is_array( $index['published_entry_lookup'][$entry_id] ) ) {
            return( null );
        }

        return( $index['published_entry_lookup'][$entry_id] );
    }

    protected function getPersistentContentIndex() : array {
        if ( is_array( $this->persistent_content_index ) ) {
            return( $this->persistent_content_index );
        }

        $index = $this->readPersistentContentIndex();
        if ( ! is_array( $index ) ) {
            $index = $this->buildPersistentContentIndex();
            $this->writePersistentContentIndex( $index );
        }

        $this->persistent_content_index = $index;
        return( $this->persistent_content_index );
    }

    protected function getPersistentSearchIndex() : array {
        if ( is_array( $this->persistent_search_index ) ) {
            return( $this->persistent_search_index );
        }

        $index = $this->readPersistentSearchIndex();
        if ( ! is_array( $index ) ) {
            $content_index = $this->getPersistentContentIndex();
            $index = $this->buildPersistentSearchIndex( $content_index );
            $this->writePersistentSearchIndex( $index );
        }

        $this->persistent_search_index = $index;
        return( $this->persistent_search_index );
    }

    protected function buildPersistentContentIndex() : array {
        $normalized_entries = [];
        foreach ( $this->getRawEntries() as $raw_entry ) {
            if ( ! is_array( $raw_entry ) ) {
                continue;
            }

            $normalized_entry = $this->normalizeEntry( $raw_entry );
            if ( $normalized_entry !== null ) {
                $normalized_entries[] = $normalized_entry;
            }
        }

        $trashed_entries = array_values(
            array_filter(
                $normalized_entries,
                static fn( array $entry ) : bool => ( $entry['status'] ?? '' ) === 'trashed'
            )
        );
        $normalized_entries = array_values(
            array_filter(
                $normalized_entries,
                static fn( array $entry ) : bool => ( $entry['status'] ?? '' ) !== 'trashed'
            )
        );
        $published_entries = array_values(
            array_filter(
                $normalized_entries,
                static fn( array $entry ) : bool => ( $entry['status'] ?? '' ) === 'published'
            )
        );

        $published_page_entries = [];
        foreach ( $published_entries as $entry ) {
            if ( ( $entry['type'] ?? '' ) !== 'page' ) {
                continue;
            }

            $page_lookup_key = $this->buildPageLookupKey( (string) ( $entry['scope'] ?? 'root' ), (string) ( $entry['author_slug'] ?? '' ), (string) ( $entry['slug'] ?? '' ) );
            if ( $page_lookup_key !== '' ) {
                $published_page_entries[$page_lookup_key] = $entry;
            }
        }

        foreach ( $normalized_entries as $index => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $normalized_entries[$index]['path'] = $this->buildEntryPathFromPageLookup( $entry, $published_page_entries );
        }
        foreach ( $published_entries as $index => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $published_entries[$index]['path'] = $this->buildEntryPathFromPageLookup( $entry, $published_page_entries );
        }
        foreach ( $trashed_entries as $index => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $trashed_entries[$index]['path'] = $this->buildEntryPathFromPageLookup( $entry, $published_page_entries );
        }

        $published_entry_lookup = [];
        $published_search_texts = [];
        $published_page_lookup = [];
        $root_path_map = [];
        $author_path_map = [];
        $author_home = [];
        $aggregated_posts = [];

        foreach ( $published_entries as $entry ) {
            $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
            if ( $entry_id === '' ) {
                continue;
            }

            $published_search_texts[$entry_id] = $this->buildPublishedEntrySearchText( $entry );
            $published_entry_lookup[$entry_id] = $entry;

            if ( ( $entry['type'] ?? '' ) === 'page' ) {
                $page_lookup_key = $this->buildPageLookupKey( (string) ( $entry['scope'] ?? 'root' ), (string) ( $entry['author_slug'] ?? '' ), (string) ( $entry['slug'] ?? '' ) );
                if ( $page_lookup_key !== '' ) {
                    $published_page_lookup[$page_lookup_key] = $entry_id;
                }
            }

            $path = trim( (string) ( $entry['path'] ?? '' ) );
            if ( $path !== '' ) {
                if ( ( $entry['scope'] ?? 'root' ) === 'author' ) {
                    $author_slug = (string) ( $entry['author_slug'] ?? '' );
                    if ( $author_slug !== '' ) {
                        $existing_entry_id = (string) ( $author_path_map[$author_slug][$path] ?? '' );
                        if ( $this->shouldReplacePathMapEntry( $existing_entry_id, $entry, $published_entry_lookup ) ) {
                            $author_path_map[$author_slug][$path] = $entry_id;
                        }
                    }
                } else {
                    $existing_entry_id = (string) ( $root_path_map[$path] ?? '' );
                    if ( $this->shouldReplacePathMapEntry( $existing_entry_id, $entry, $published_entry_lookup ) ) {
                        $root_path_map[$path] = $entry_id;
                    }
                }
            }

            if ( ( $entry['scope'] ?? 'root' ) === 'author' ) {
                $author_slug = (string) ( $entry['author_slug'] ?? '' );
                if ( $author_slug !== '' ) {
                    if ( ! isset( $author_home[$author_slug] ) || ! is_array( $author_home[$author_slug] ) ) {
                        $author_home[$author_slug] = [ 'post_ids' => [], 'page_ids' => [] ];
                    }

                    if ( ( $entry['type'] ?? '' ) === 'post' ) {
                        $author_home[$author_slug]['post_ids'][] = $entry_id;
                    } elseif ( ( $entry['type'] ?? '' ) === 'page' ) {
                        $author_home[$author_slug]['page_ids'][] = $entry_id;
                    }
                }
            }

            if ( ( $entry['type'] ?? '' ) === 'post' && ! empty( $entry['aggregate_to_root'] ) ) {
                $aggregated_posts[] = $entry_id;
            }
        }

        $sort_posts = function( string $left_id, string $right_id ) use ( $published_entry_lookup ) : int {
            $left = is_array( $published_entry_lookup[$left_id] ?? null ) ? $published_entry_lookup[$left_id] : [];
            $right = is_array( $published_entry_lookup[$right_id] ?? null ) ? $published_entry_lookup[$right_id] : [];
            if ( ! empty( $left['sticky'] ) !== ! empty( $right['sticky'] ) ) {
                return( ! empty( $left['sticky'] ) ? -1 : 1 );
            }
            if ( ( $left['published_at_utc'] ?? '' ) !== ( $right['published_at_utc'] ?? '' ) ) {
                return( strcmp( (string) ( $right['published_at_utc'] ?? '' ), (string) ( $left['published_at_utc'] ?? '' ) ) );
            }
            return( strcmp( $this->getEntrySortTitle( $left ), $this->getEntrySortTitle( $right ) ) );
        };
        usort( $aggregated_posts, $sort_posts );

        foreach ( $author_home as $author_slug => $author_data ) {
            $author_post_ids = array_values( array_filter( is_array( $author_data['post_ids'] ?? null ) ? $author_data['post_ids'] : [], 'is_string' ) );
            $author_page_ids = array_values( array_filter( is_array( $author_data['page_ids'] ?? null ) ? $author_data['page_ids'] : [], 'is_string' ) );

            usort( $author_post_ids, $sort_posts );
            usort(
                $author_page_ids,
                function( string $left_id, string $right_id ) use ( $published_entry_lookup ) : int {
                    $left = is_array( $published_entry_lookup[$left_id] ?? null ) ? $published_entry_lookup[$left_id] : [];
                    $right = is_array( $published_entry_lookup[$right_id] ?? null ) ? $published_entry_lookup[$right_id] : [];
                    $left_sort_order = (int) ( $left['sort_order'] ?? 0 );
                    $right_sort_order = (int) ( $right['sort_order'] ?? 0 );
                    if ( $left_sort_order !== $right_sort_order ) {
                        return( $left_sort_order <=> $right_sort_order );
                    }
                    return( strcmp( $this->getEntrySortTitle( $left ), $this->getEntrySortTitle( $right ) ) );
                }
            );

            $author_home[$author_slug] = [
                'post_ids' => $author_post_ids,
                'page_ids' => $author_page_ids,
            ];
        }

        return(
            [
                'version' => self::CONTENT_INDEX_VERSION,
                'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'entries' => $normalized_entries,
                'trashed_entries' => $trashed_entries,
                'published_entries' => $published_entries,
                'published_entry_lookup' => $published_entry_lookup,
                'published_search_texts' => $published_search_texts,
                'published_page_lookup' => $published_page_lookup,
                'root_path_map' => $root_path_map,
                'author_path_map' => $author_path_map,
                'author_home' => $author_home,
                'aggregated_post_ids' => $aggregated_posts,
            ]
        );
    }

    protected function buildPersistentSearchIndex( array $content_index ) : array {
        $published_entries = array_values( array_filter( is_array( $content_index['published_entries'] ?? null ) ? $content_index['published_entries'] : [], 'is_array' ) );
        $search_entries = [];

        foreach ( $published_entries as $entry ) {
            if ( ! $this->isCanonicalPublishedPathEntry( $entry, $content_index ) ) {
                continue;
            }

            $search_entries[] = $this->buildSearchIndexEntry( $entry );
        }

        return(
            [
                'version' => self::SEARCH_INDEX_VERSION,
                'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'entries' => $search_entries,
            ]
        );
    }

    protected function readPersistentContentIndex() : ?array {
        if ( ! is_file( $this->content_index_filename ) || ! is_readable( $this->content_index_filename ) ) {
            return( null );
        }

        $json = @ file_get_contents( $this->content_index_filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( null );
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) || (int) ( $decoded['version'] ?? 0 ) !== self::CONTENT_INDEX_VERSION || ! is_array( $decoded['entries'] ?? null ) || ! is_array( $decoded['published_entries'] ?? null ) ) {
            return( null );
        }

        return( $decoded );
    }

    protected function readPersistentSearchIndex() : ?array {
        if ( ! is_file( $this->search_index_filename ) || ! is_readable( $this->search_index_filename ) ) {
            return( null );
        }

        $json = @ file_get_contents( $this->search_index_filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( null );
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) || (int) ( $decoded['version'] ?? 0 ) !== self::SEARCH_INDEX_VERSION || ! is_array( $decoded['entries'] ?? null ) ) {
            return( null );
        }

        return( $decoded );
    }

    protected function writePersistentContentIndex( array $index ) : void {
        $target_directory = dirname( $this->content_index_filename );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            return;
        }

        $json = json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || $json === '' ) {
            return;
        }

        $temporary_filename = $this->content_index_filename . '.tmp';
        if ( @ file_put_contents( $temporary_filename, $json . PHP_EOL, LOCK_EX ) === false ) {
            return;
        }

        @ rename( $temporary_filename, $this->content_index_filename );
    }

    protected function writePersistentSearchIndex( array $index ) : void {
        $target_directory = dirname( $this->search_index_filename );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            return;
        }

        $json = json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || $json === '' ) {
            return;
        }

        $temporary_filename = $this->search_index_filename . '.tmp';
        if ( @ file_put_contents( $temporary_filename, $json . PHP_EOL, LOCK_EX ) === false ) {
            return;
        }

        @ rename( $temporary_filename, $this->search_index_filename );
    }

    protected function buildEntryPathFromPageLookup( array $entry, array $page_lookup ) : string {
        if ( ( $entry['type'] ?? '' ) !== 'page' || ( $entry['parent_slug'] ?? '' ) === '' ) {
            return( (string) ( $entry['slug'] ?? '' ) );
        }

        $segments = [ (string) ( $entry['slug'] ?? '' ) ];
        $current_parent_slug = (string) ( $entry['parent_slug'] ?? '' );
        $guard = 0;
        while ( $current_parent_slug !== '' && $guard < 20 ) {
            $guard++;
            $parent_key = $this->buildPageLookupKey( (string) ( $entry['scope'] ?? 'root' ), (string) ( $entry['author_slug'] ?? '' ), $current_parent_slug );
            $parent_entry = is_array( $page_lookup[$parent_key] ?? null ) ? $page_lookup[$parent_key] : null;
            if ( ! is_array( $parent_entry ) ) {
                break;
            }

            array_unshift( $segments, (string) ( $parent_entry['slug'] ?? '' ) );
            $current_parent_slug = (string) ( $parent_entry['parent_slug'] ?? '' );
        }

        return( implode( '/', array_values( array_filter( $segments, static fn( mixed $segment ) : bool => is_string( $segment ) && $segment !== '' ) ) ) );
    }

    protected function buildPageLookupKey( string $scope, string $author_slug, string $slug ) : string {
        $scope = $scope === 'author' ? 'author' : 'root';
        $author_slug = $scope === 'author' ? $this->normalizePathPart( $author_slug ) : '';
        $slug = $this->normalizePathPart( $slug );
        if ( $slug === '' || ( $scope === 'author' && $author_slug === '' ) ) {
            return( '' );
        }

        return( $scope . '|' . $author_slug . '|' . $slug );
    }

    protected function getEntrySortTitle( array $entry ) : string {
        $default_language = strtolower( trim( (string) ( $entry['default_language'] ?? 'en' ) ) );
        $translations = is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [];
        $translation = is_array( $translations[$default_language] ?? null ) ? $translations[$default_language] : reset( $translations );
        return( (string) ( is_array( $translation ) ? ( $translation['title'] ?? '' ) : '' ) );
    }

    protected function clearDerivedCaches() : void {
        $this->resetDerivedRuntimeState();
        $this->clearPersistentCache();
        TinyMashPublicPageCache::invalidateDirectory( $this->public_page_cache_directory );
    }

    protected function resetDerivedRuntimeState() : void {
        $this->entries = null;
        $this->published_entries = null;
        $this->persistent_content_index = null;
        $this->persistent_search_index = null;
        $this->localized_entry_cache = [];
        $this->child_pages_cache = [];
        $this->page_tree_cache = [];
        $this->search_profiling_metrics = [];
    }

    protected function recordSearchProfilingMetric( string $metric_name, float $started_at ) : void {
        $metric_name = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( $metric_name ) ) ?? '' );
        if ( $metric_name === '' ) {
            return;
        }

        if ( ! isset( $this->search_profiling_metrics[$metric_name] ) ) {
            $this->search_profiling_metrics[$metric_name] = 0.0;
        }

        $this->search_profiling_metrics[$metric_name] += max( 0, ( microtime( true ) - $started_at ) * 1000 );
    }

    protected function normalizeStringList( mixed $values ) : array {
        if ( ! is_array( $values ) ) {
            return( [] );
        }

        $normalized_values = [];
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }
            $value = trim( $value );
            if ( $value !== '' ) {
                $normalized_values[] = $value;
            }
        }

        return( array_values( array_unique( $normalized_values ) ) );
    }

    protected function normalizeTagList( mixed $values ) : array {
        if ( is_string( $values ) ) {
            $values = preg_split( '/[\s,]+/u', $values ) ?: [];
        }
        if ( ! is_array( $values ) ) {
            return( [] );
        }

        $normalized_values = [];
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }

            $value = ltrim( trim( $value ), '#' );
            $value = $this->normalizePathToken( $value );
            $value = preg_replace( '/\s+/u', '-', $value ) ?? '';
            $value = preg_replace( '/[^a-z0-9_-]+/', '-', $value ) ?? '';
            $value = preg_replace( '/-+/', '-', $value ) ?? '';
            $value = trim( $value, '-_' );
            if ( $value !== '' ) {
                $normalized_values[] = $value;
            }
        }

        return( array_values( array_unique( $normalized_values ) ) );
    }

    protected function normalizeTagSlug( string $value ) : string {
        $normalized_tags = $this->normalizeTagList( [ $value ] );
        return( (string) ( $normalized_tags[0] ?? '' ) );
    }

    protected function normalizePath( string $value ) : string {
        $segments = preg_split( '#/+?#', strtolower( trim( $value ) ) ) ?: [];
        $normalized_segments = [];
        foreach ( $segments as $segment ) {
            $segment = $this->normalizePathPart( $segment );
            if ( $segment !== '' ) {
                $normalized_segments[] = $segment;
            }
        }

        return( implode( '/', $normalized_segments ) );
    }

    protected function normalizePathPart( string $value ) : string {
        $value = $this->normalizePathToken( trim( $value, " \t\n\r\0\x0B/" ) );
        $value = str_replace( '_', '-', $value );
        $value = preg_replace( '/-+/', '-', $value ) ?? '';
        return( trim( $value, '-' ) );
    }

    protected function normalizePathToken( string $value ) : string {
        $value = function_exists( 'mb_strtolower' )
            ? mb_strtolower( trim( $value ), 'UTF-8' )
            : strtolower( trim( $value ) );
        if ( $value === '' ) {
            return( '' );
        }

        $value = strtr(
            $value,
            [
                'å' => 'a',
                'ä' => 'a',
                'ö' => 'o',
                'æ' => 'ae',
                'ø' => 'o',
                'ü' => 'u',
                'ù' => 'u',
                'ú' => 'u',
                'û' => 'u',
                'ï' => 'i',
                'ì' => 'i',
                'í' => 'i',
                'î' => 'i',
                'ë' => 'e',
                'è' => 'e',
                'é' => 'e',
                'ê' => 'e',
                'à' => 'a',
                'á' => 'a',
                'â' => 'a',
                'ã' => 'a',
                'ç' => 'c',
                'ñ' => 'n',
                'ß' => 'ss',
            ]
        );

        if ( function_exists( 'iconv' ) ) {
            $transliterated = @ iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
            if ( is_string( $transliterated ) && $transliterated !== '' ) {
                $value = $transliterated;
            }
        }

        return( strtolower( trim( $value ) ) );
    }

    protected function sortPosts( array &$posts ) : void {
        usort(
            $posts,
            function( array $left, array $right ) : int {
                if ( $left['sticky'] !== $right['sticky'] ) {
                    return( $left['sticky'] ? -1 : 1 );
                }
                if ( $left['published_at_utc'] !== $right['published_at_utc'] ) {
                    return( strcmp( $right['published_at_utc'], $left['published_at_utc'] ) );
                }
                return( strcmp( $left['title'], $right['title'] ) );
            }
        );
    }

    protected function buildTagPageResult( array $entries, string $tag, ?string $language, int $page, int $per_page ) : array {
        usort( $entries, fn( array $left, array $right ) : int => $this->compareEntriesForPublicListing( $left, $right ) );
        $pagination = $this->buildPaginationMeta( count( $entries ), $page, $per_page );
        $paged_entries = array_slice( $entries, (int) $pagination['offset'], (int) $pagination['per_page'] );

        return(
            [
                'tag' => $tag,
                'items' => array_map( fn( array $entry ) : array => $this->localizeEntry( $entry, $language ), $paged_entries ),
                'pagination' => $pagination,
                'total_matches' => count( $entries ),
            ]
        );
    }

    protected function compareEntriesForPublicListing( array $left, array $right ) : int {
        if ( ! empty( $left['sticky'] ) !== ! empty( $right['sticky'] ) ) {
            return( ! empty( $left['sticky'] ) ? -1 : 1 );
        }
        if ( ( $left['published_at_utc'] ?? '' ) !== ( $right['published_at_utc'] ?? '' ) ) {
            return( strcmp( (string) ( $right['published_at_utc'] ?? '' ), (string) ( $left['published_at_utc'] ?? '' ) ) );
        }

        return( strcmp( $this->getEntrySortTitle( $left ), $this->getEntrySortTitle( $right ) ) );
    }

    protected function entryMatchesRootTagScope( array $entry ) : bool {
        if ( (string) ( $entry['scope'] ?? 'root' ) === 'root' ) {
            return( true );
        }

        return( (string) ( $entry['type'] ?? '' ) === 'post' && ! empty( $entry['aggregate_to_root'] ) );
    }

    protected function entryHasTag( array $entry, string $tag ) : bool {
        if ( $tag === '' ) {
            return( false );
        }

        $entry_tags = array_values( array_filter( is_array( $entry['tags'] ?? null ) ? $entry['tags'] : [], 'is_string' ) );
        return( in_array( $tag, $entry_tags, true ) );
    }

    protected function buildPaginationMeta( int $total_items, int $page = 1, int $per_page = 10 ) : array {
        $per_page = max( 1, $per_page );
        $total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
        $current_page = min( max( 1, $page ), $total_pages );

        return(
            [
                'current_page' => $current_page,
                'per_page' => $per_page,
                'total_items' => max( 0, $total_items ),
                'total_pages' => $total_pages,
                'offset' => ( $current_page - 1 ) * $per_page,
                'has_previous' => $current_page > 1,
                'has_next' => $current_page < $total_pages,
                'previous_page' => $current_page > 1 ? $current_page - 1 : 1,
                'next_page' => $current_page < $total_pages ? $current_page + 1 : $total_pages,
            ]
        );
    }

    protected function normalizeSearchTerms( string $query ) : array {
        $query = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
        $terms = preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $terms ) ) {
            return( [] );
        }

        $normalized_terms = [];
        foreach ( $terms as $term ) {
            $term = function_exists( 'mb_trim' ) ? mb_trim( $term ) : trim( $term );
            $term = preg_replace( '/^[^\p{L}\p{N}#]+|[^\p{L}\p{N}]+$/u', '', $term ) ?? $term;
            if ( mb_strlen( $term ) < 2 ) {
                continue;
            }
            $hash_prefixed = str_starts_with( $term, '#' );
            $term = ltrim( $term, '#' );
            $term = preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $term ) ?? $term;
            if ( $term === '' ) {
                continue;
            }
            $term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
            $normalized_terms[] = [
                'term' => $term,
                'hash_prefixed' => $hash_prefixed,
            ];
        }

        $unique_terms = [];
        foreach ( $normalized_terms as $normalized_term ) {
            $key = ( ! empty( $normalized_term['hash_prefixed'] ) ? '#' : '' ) . (string) ( $normalized_term['term'] ?? '' );
            $unique_terms[$key] = $normalized_term;
        }

        return( array_values( $unique_terms ) );
    }

    protected function searchEntryMatchesTerm( array $entry, string $search_text, array $term_data ) : bool {
        $term = (string) ( $term_data['term'] ?? '' );
        if ( ! empty( $term_data['hash_prefixed'] ) ) {
            return( $this->searchEntryMatchesHashPrefixedTerm( $entry, $term ) );
        }

        return( $this->searchTextMatchesTerm( $search_text, $term ) );
    }

    protected function searchTextMatchesTerm( string $search_text, string $term ) : bool {
        if ( $term === '' ) {
            return( true );
        }
        if ( preg_match( '/^\d+$/', $term ) === 1 ) {
            return( preg_match( '/(?<![\p{L}\p{N}.])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}.])/u', $search_text ) === 1 );
        }

        return( strpos( $search_text, $term ) !== false );
    }

    protected function searchEntryMatchesHashPrefixedTerm( array $entry, string $term ) : bool {
        if ( $term === '' ) {
            return( true );
        }

        $parts = [];
        $default_language = strtolower( trim( (string) ( $entry['default_language'] ?? 'en' ) ) );
        $translations = is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [];
        $translation = is_array( $translations[$default_language] ?? null ) ? $translations[$default_language] : reset( $translations );
        if ( is_array( $translation ) ) {
            $parts[] = (string) ( $translation['title'] ?? '' );
            $parts[] = (string) ( $translation['summary'] ?? '' );
            $parts[] = (string) ( $translation['content'] ?? '' );
        }

        $raw_text = implode( ' ', $parts );
        $raw_text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw_text ) : strtolower( $raw_text );

        return( preg_match( '/(?<![\p{L}\p{N}])#' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u', $raw_text ) === 1 );
    }

    protected function normalizeComparablePluginSettings( array $plugin_settings ) : array {
        $normalized = $this->normalizePluginSettings( $plugin_settings );

        if ( $this->pluginSettingsMatchDefaults(
            is_array( $normalized['photos'] ?? null ) ? $normalized['photos'] : [],
            [
                'photo_post_enabled' => '0',
                'gallery_enabled' => '0',
                'gallery_download_enabled' => '0',
                'gallery_slideshow_enabled' => '0',
                'gallery_slideshow_delay_seconds' => '5',
                'gallery_position' => 'after_content',
                'gallery_media_ids' => '',
                'gallery_image_overrides' => '{}',
            ]
        ) ) {
            unset( $normalized['photos'] );
        }

        if ( $this->pluginSettingsMatchDefaults(
            is_array( $normalized['fediverse'] ?? null ) ? $normalized['fediverse'] : [],
            [
                'post_enabled' => '0',
                'include_link_back' => '1',
            ]
        ) ) {
            unset( $normalized['fediverse'] );
        }

        return( $normalized );
    }

    protected function pluginSettingsMatchDefaults( array $settings, array $defaults ) : bool {
        if ( empty( $settings ) ) {
            return( true );
        }

        foreach ( $settings as $key => $value ) {
            if ( ! array_key_exists( $key, $defaults ) ) {
                return( false );
            }
            if ( trim( (string) $value ) !== (string) $defaults[$key] ) {
                return( false );
            }
        }

        return( true );
    }

    protected function buildPublishedEntrySearchText( array $entry ) : string {
        $parts = [
            (string) ( $entry['path'] ?? '' ),
            (string) ( $entry['slug'] ?? '' ),
        ];

        $translations = is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [];
        foreach ( $translations as $translation ) {
            if ( ! is_array( $translation ) ) {
                continue;
            }

            $parts[] = (string) ( $translation['title'] ?? '' );
            $parts[] = (string) ( $translation['summary'] ?? '' );
            $parts[] = (string) ( $translation['content'] ?? '' );
        }

        if ( ! empty( $entry['tags'] ) && is_array( $entry['tags'] ) ) {
            $parts[] = implode( ' ', array_filter( $entry['tags'], 'is_string' ) );
        }
        if ( ! empty( $entry['labels'] ) && is_array( $entry['labels'] ) ) {
            $parts[] = implode( ' ', array_filter( $entry['labels'], 'is_string' ) );
        }

        $search_text = $this->stripMarkdownForSearch( implode( "\n", $parts ) );
        return( function_exists( 'mb_strtolower' ) ? mb_strtolower( $search_text ) : strtolower( $search_text ) );
    }

    protected function buildSearchIndexEntry( array $entry ) : array {
        $translations = is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [];
        $search_translations = [];
        foreach ( $translations as $language => $translation ) {
            if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                continue;
            }

            $search_translations[$language] = [
                'title' => (string) ( $translation['title'] ?? '' ),
                'summary' => $this->buildSearchSummaryText(
                    (string) ( $translation['summary'] ?? '' ),
                    (string) ( $translation['content'] ?? '' )
                ),
                'content' => '',
            ];
        }

        return(
            [
                'id' => (string) ( $entry['id'] ?? '' ),
                'type' => (string) ( $entry['type'] ?? 'post' ),
                'scope' => (string) ( $entry['scope'] ?? 'root' ),
                'author_slug' => (string) ( $entry['author_slug'] ?? '' ),
                'slug' => (string) ( $entry['slug'] ?? '' ),
                'status' => (string) ( $entry['status'] ?? 'published' ),
                'default_language' => (string) ( $entry['default_language'] ?? 'en' ),
                'translations' => $search_translations,
                'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
                'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
                'aggregate_to_root' => ! empty( $entry['aggregate_to_root'] ),
                'sticky' => ! empty( $entry['sticky'] ),
                'featured' => ! empty( $entry['featured'] ),
                'parent_slug' => (string) ( $entry['parent_slug'] ?? '' ),
                'sort_order' => (int) ( $entry['sort_order'] ?? 0 ),
                'show_in_navigation' => ! empty( $entry['show_in_navigation'] ),
                'tags' => is_array( $entry['tags'] ?? null ) ? $entry['tags'] : [],
                'labels' => is_array( $entry['labels'] ?? null ) ? $entry['labels'] : [],
                'featured_image_media_id' => (string) ( $entry['featured_image_media_id'] ?? '' ),
                'featured_image' => is_array( $entry['featured_image'] ?? null ) ? $entry['featured_image'] : [],
                'fallback_cover_color' => $this->normalizeFallbackCoverColor( $entry['fallback_cover_color'] ?? '' ),
                'path' => (string) ( $entry['path'] ?? '' ),
                'search_text' => $this->buildPublishedEntrySearchText( $entry ),
            ]
        );
    }

    protected function buildSearchSummaryText( string $summary, string $content ) : string {
        $summary = trim( $summary );
        if ( $summary !== '' ) {
            return( $summary );
        }

        $plain_content = $this->stripMarkdownForSearch( $content );
        if ( $plain_content === '' ) {
            return( '' );
        }

        $words = preg_split( '/\s+/u', $plain_content, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || empty( $words ) ) {
            return( '' );
        }
        if ( count( $words ) <= 35 ) {
            return( implode( ' ', $words ) );
        }

        return( rtrim( implode( ' ', array_slice( $words, 0, 35 ) ) ) . '...' );
    }

    protected function stripMarkdownForSearch( string $content ) : string {
        $content = preg_replace( '/```.*?```/su', ' ', $content ) ?? $content;
        $content = preg_replace( '/`[^`]*`/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[\!\[[^\]]*\]\([^)]+\)\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/!\[[^\]]*\]\([^)]+\)/u', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[[a-z][^\]]*\](?:\[\/[a-z][^\]]*\])?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $content ) ?? $content;
        $content = preg_replace( '/\(?https?:\/\/[^\s)]+\)?/iu', ' ', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}#{1,6}\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}>\s?/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}(?:[-+*]|\d+\.)\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/^\s{0,3}\[[ xX]\]\s+/mu', '', $content ) ?? $content;
        $content = preg_replace( '/[*_~#>]+/u', ' ', $content ) ?? $content;
        $content = str_replace( '|', ' ', $content );

        return( trim( preg_replace( '/\s+/u', ' ', strip_tags( $content ) ) ?? '' ) );
    }

    protected function getEntryMetadataFiles() : array {
        if ( ! is_dir( $this->content_directory ) ) {
            return( [] );
        }

        $metadata_files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->content_directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            if ( ! $file_info->isFile() || $file_info->getFilename() !== 'entry.json' ) {
                continue;
            }
            $metadata_files[] = $file_info->getPathname();
        }

        sort( $metadata_files );
        return( $metadata_files );
    }

    protected function readRawEntryMetadataFromStorage( string $metadata_file ) : ?array {
        if ( ! is_file( $metadata_file ) || ! is_readable( $metadata_file ) ) {
            return( null );
        }

        $json = file_get_contents( $metadata_file );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( null );
        }

        $raw_entry = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
        return( is_array( $raw_entry ) ? $raw_entry : null );
    }

    protected function writeRawEntryMetadataToStorage( string $metadata_file, array $raw_entry ) : void {
        $metadata = $raw_entry;
        if ( isset( $metadata['translations'] ) && is_array( $metadata['translations'] ) ) {
            foreach ( $metadata['translations'] as $language => $translation ) {
                if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                    unset( $metadata['translations'][$language] );
                    continue;
                }
                $metadata['translations'][$language] = [
                    'title' => (string) ( $translation['title'] ?? '' ),
                    'summary' => (string) ( $translation['summary'] ?? '' ),
                ];
            }
        } else {
            $metadata['translations'] = [];
        }

        $metadata_json = json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $metadata_json ) || $metadata_json === '' ) {
            throw new \RuntimeException( 'Unable to encode entry metadata.' );
        }

        if ( file_put_contents( $metadata_file, $metadata_json . PHP_EOL ) === false ) {
            throw new \RuntimeException( 'Unable to write entry metadata.' );
        }
    }

    protected function readRawEntryFromStorage( string $metadata_file ) : ?array {
        $raw_entry = $this->readRawEntryMetadataFromStorage( $metadata_file );
        if ( ! is_array( $raw_entry ) ) {
            return( null );
        }

        $entry_directory = dirname( $metadata_file );
        $default_language = strtolower( trim( (string) ( $raw_entry['default_language'] ?? 'en' ) ) );
        if ( $default_language === '' ) {
            $default_language = 'en';
        }

        $translations = is_array( $raw_entry['translations'] ?? null ) ? $raw_entry['translations'] : [];
        foreach ( $translations as $language => $translation ) {
            if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                unset( $translations[$language] );
                continue;
            }
            $language = strtolower( trim( $language ) );
            $body_filename = $entry_directory . DIRECTORY_SEPARATOR . 'body.' . $language . '.md';
            $translations[$language]['content'] = is_file( $body_filename ) && is_readable( $body_filename ) ? (string) file_get_contents( $body_filename ) : '';
        }

        if ( empty( $translations[$default_language] ) ) {
            $translations[$default_language] = [
                'title' => (string) ( $raw_entry['title'] ?? '' ),
                'summary' => (string) ( $raw_entry['summary'] ?? '' ),
                'content' => is_file( $entry_directory . DIRECTORY_SEPARATOR . 'body.' . $default_language . '.md' ) ? (string) file_get_contents( $entry_directory . DIRECTORY_SEPARATOR . 'body.' . $default_language . '.md' ) : '',
            ];
        }

        $raw_entry['translations'] = $translations;
        return( $raw_entry );
    }

    protected function rawEntryMatchesEditorAccess( array $raw_entry, ?string $author_slug = null, bool $allow_root = true ) : bool {
        $scope = (string) ( $raw_entry['scope'] ?? 'root' );
        if ( $scope !== 'author' ) {
            return( $allow_root );
        }

        $normalized_author_slug = $this->normalizeEditorPathPart( (string) ( $raw_entry['author_slug'] ?? '' ) );
        if ( $normalized_author_slug === '' ) {
            return( false );
        }

        if ( $author_slug === null || trim( $author_slug ) === '' ) {
            return( true );
        }

        return( $normalized_author_slug === $this->normalizeEditorPathPart( $author_slug ) );
    }

    protected function rawEntryIsTrashed( array $raw_entry ) : bool {
        return( $this->normalizeStatus( (string) ( $raw_entry['status'] ?? '' ) ) === 'trashed' );
    }

    protected function readRawRevisionFromStorage( string $metadata_file ) : ?array {
        if ( ! is_file( $metadata_file ) || ! is_readable( $metadata_file ) ) {
            return( null );
        }

        $json = file_get_contents( $metadata_file );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( null );
        }

        $raw_revision = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
        if ( ! is_array( $raw_revision ) ) {
            return( null );
        }

        $revision_directory = dirname( $metadata_file );
        $default_language = strtolower( trim( (string) ( $raw_revision['default_language'] ?? 'en' ) ) );
        if ( $default_language === '' ) {
            $default_language = 'en';
        }

        $translations = is_array( $raw_revision['translations'] ?? null ) ? $raw_revision['translations'] : [];
        foreach ( $translations as $language => $translation ) {
            if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                unset( $translations[$language] );
                continue;
            }
            $language = strtolower( trim( $language ) );
            $body_filename = $revision_directory . DIRECTORY_SEPARATOR . 'body.' . $language . '.md';
            $translations[$language]['content'] = is_file( $body_filename ) && is_readable( $body_filename ) ? (string) file_get_contents( $body_filename ) : '';
        }

        $raw_revision['translations'] = $translations;
        return( $raw_revision );
    }

    protected function writeRawEntryToStorage( array $raw_entry, bool $skip_existing_id_scan = false, string $known_existing_directory = '' ) : string {
        $normalized_entry = $this->normalizeEntry( $raw_entry );
        if ( ! is_array( $normalized_entry ) ) {
            return( '' );
        }

        $entry_directory = $this->getEntryDirectory( $normalized_entry );
        if ( ! $skip_existing_id_scan ) {
            $known_existing_directory = rtrim( $known_existing_directory, DIRECTORY_SEPARATOR );
            if ( $known_existing_directory !== '' ) {
                if ( $known_existing_directory !== $entry_directory && is_dir( $known_existing_directory ) ) {
                    $this->deleteDirectoryRecursively( $known_existing_directory );
                    $this->cleanupEmptyParentDirectories( dirname( $known_existing_directory ) );
                }
            } else {
                foreach ( $this->getEntryMetadataFiles() as $metadata_file ) {
                    $existing_raw_entry = $this->readRawEntryFromStorage( $metadata_file );
                    if (
                        ! is_array( $existing_raw_entry ) ||
                        (string) ( $existing_raw_entry['id'] ?? '' ) !== $normalized_entry['id'] ||
                        dirname( $metadata_file ) === $entry_directory
                    ) {
                        continue;
                    }

                    $old_directory = dirname( $metadata_file );
                    $this->deleteDirectoryRecursively( $old_directory );
                    $this->cleanupEmptyParentDirectories( dirname( $old_directory ) );
                }
            }
        }

        if ( ! is_dir( $entry_directory ) && ! mkdir( $entry_directory, 0775, true ) && ! is_dir( $entry_directory ) ) {
            throw new \RuntimeException( 'Unable to create entry directory "' . $entry_directory . '"' );
        }

        $metadata = [
            'id' => $normalized_entry['id'],
            'type' => $normalized_entry['type'],
            'scope' => $normalized_entry['scope'],
            'slug' => $normalized_entry['slug'],
            'status' => $normalized_entry['status'],
            'default_language' => $normalized_entry['default_language'],
            'published_at_utc' => $normalized_entry['published_at_utc'],
            'updated_at_utc' => $normalized_entry['updated_at_utc'],
            'trashed_at_utc' => $normalized_entry['trashed_at_utc'],
            'trashed_by' => $normalized_entry['trashed_by'],
            'pre_trash_status' => $normalized_entry['pre_trash_status'],
            'aggregate_to_root' => $normalized_entry['aggregate_to_root'],
            'sticky' => $normalized_entry['sticky'],
            'featured' => $normalized_entry['featured'],
            'seo_title' => $normalized_entry['seo_title'],
            'seo_description' => $normalized_entry['seo_description'],
            'seo_social_title' => $normalized_entry['seo_social_title'],
            'seo_social_description' => $normalized_entry['seo_social_description'],
            'seo_social_image_media_id' => $normalized_entry['seo_social_image_media_id'],
            'seo_social_image' => $normalized_entry['seo_social_image'],
            'seo_canonical_url' => $normalized_entry['seo_canonical_url'],
            'seo_robots' => $normalized_entry['seo_robots'],
            'seo_exclude_from_sitemap' => $normalized_entry['seo_exclude_from_sitemap'],
            'sort_order' => $normalized_entry['sort_order'],
            'show_in_navigation' => $normalized_entry['show_in_navigation'],
            'tags' => $normalized_entry['tags'],
            'labels' => $normalized_entry['labels'],
            'plugin_settings' => $normalized_entry['plugin_settings'],
            'featured_image_media_id' => $normalized_entry['featured_image_media_id'],
            'featured_image' => $normalized_entry['featured_image'],
            'fallback_cover_color' => $normalized_entry['fallback_cover_color'],
            'translations' => [],
        ];
        if ( $normalized_entry['scope'] === 'author' ) {
            $metadata['author_slug'] = $normalized_entry['author_slug'];
        }
        if ( $normalized_entry['type'] === 'page' ) {
            $metadata['parent_slug'] = $normalized_entry['parent_slug'];
        }

        foreach ( $normalized_entry['translations'] as $language => $translation ) {
            $metadata['translations'][$language] = [
                'title' => (string) ( $translation['title'] ?? '' ),
                'summary' => (string) ( $translation['summary'] ?? '' ),
            ];
            $body_filename = $entry_directory . DIRECTORY_SEPARATOR . 'body.' . $language . '.md';
            $body_content = (string) ( $translation['content'] ?? '' );
            if ( file_put_contents( $body_filename, $body_content ) === false ) {
                throw new \RuntimeException( 'Unable to write entry body "' . $body_filename . '"' );
            }
        }

        foreach ( glob( $entry_directory . DIRECTORY_SEPARATOR . 'body.*.md' ) ?: [] as $body_filename ) {
            if ( ! is_string( $body_filename ) || ! is_file( $body_filename ) ) {
                continue;
            }
            if ( preg_match( '/body\.([a-z0-9_-]+)\.md$/', basename( $body_filename ), $matches ) !== 1 ) {
                continue;
            }
            if ( array_key_exists( $matches[1], $metadata['translations'] ) ) {
                continue;
            }
            unlink( $body_filename );
        }

        $metadata_json = json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $metadata_json ) || $metadata_json === '' ) {
            throw new \RuntimeException( 'Unable to encode entry metadata.' );
        }

        if ( file_put_contents( $entry_directory . DIRECTORY_SEPARATOR . 'entry.json', $metadata_json . PHP_EOL ) === false ) {
            throw new \RuntimeException( 'Unable to write entry metadata.' );
        }

        return( $normalized_entry['id'] );
    }

    protected function writeRevisionSnapshot( array $entry ) : void {
        if ( $this->revision_retention_limit === 0 ) {
            return;
        }

        $entry_directory = $this->getEntryDirectory( $entry );
        if ( ! is_dir( $entry_directory ) ) {
            return;
        }

        $revision_id = 'revision_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) );
        $revision_directory = $entry_directory . DIRECTORY_SEPARATOR . 'revisions' . DIRECTORY_SEPARATOR . $revision_id;
        if ( ! is_dir( $revision_directory ) && ! mkdir( $revision_directory, 0775, true ) && ! is_dir( $revision_directory ) ) {
            throw new \RuntimeException( 'Unable to create revision directory "' . $revision_directory . '"' );
        }

        $metadata = [
            'revision_id' => $revision_id,
            'source_entry_id' => (string) ( $entry['id'] ?? '' ),
            'saved_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'id' => (string) ( $entry['id'] ?? '' ),
            'type' => (string) ( $entry['type'] ?? 'post' ),
            'scope' => (string) ( $entry['scope'] ?? 'root' ),
            'slug' => (string) ( $entry['slug'] ?? '' ),
            'status' => (string) ( $entry['status'] ?? 'unpublished' ),
            'default_language' => (string) ( $entry['default_language'] ?? 'en' ),
            'published_at_utc' => (string) ( $entry['published_at_utc'] ?? '' ),
            'updated_at_utc' => (string) ( $entry['updated_at_utc'] ?? '' ),
            'aggregate_to_root' => ! empty( $entry['aggregate_to_root'] ),
            'sticky' => ! empty( $entry['sticky'] ),
            'featured' => ! empty( $entry['featured'] ),
            'seo_title' => trim( (string) ( $entry['seo_title'] ?? '' ) ),
            'seo_description' => trim( (string) ( $entry['seo_description'] ?? '' ) ),
            'seo_social_title' => trim( (string) ( $entry['seo_social_title'] ?? '' ) ),
            'seo_social_description' => trim( (string) ( $entry['seo_social_description'] ?? '' ) ),
            'seo_social_image_media_id' => trim( (string) ( $entry['seo_social_image_media_id'] ?? '' ) ),
            'seo_social_image' => $this->normalizeMediaImage( $entry['seo_social_image'] ?? [] ),
            'seo_canonical_url' => trim( (string) ( $entry['seo_canonical_url'] ?? '' ) ),
            'seo_robots' => $this->normalizeSeoRobots( (string) ( $entry['seo_robots'] ?? '' ) ),
            'seo_exclude_from_sitemap' => ! empty( $entry['seo_exclude_from_sitemap'] ),
            'sort_order' => (int) ( $entry['sort_order'] ?? 0 ),
            'show_in_navigation' => ! empty( $entry['show_in_navigation'] ),
            'tags' => $this->normalizeStringList( $entry['tags'] ?? [] ),
            'labels' => $this->normalizeStringList( $entry['labels'] ?? [] ),
            'plugin_settings' => $this->normalizePluginSettings( $entry['plugin_settings'] ?? [] ),
            'featured_image_media_id' => trim( (string) ( $entry['featured_image_media_id'] ?? '' ) ),
            'featured_image' => $this->normalizeMediaImage( $entry['featured_image'] ?? [] ),
            'fallback_cover_color' => $this->normalizeFallbackCoverColor( $entry['fallback_cover_color'] ?? '' ),
            'translations' => [],
        ];
        if ( ( $entry['scope'] ?? 'root' ) === 'author' ) {
            $metadata['author_slug'] = (string) ( $entry['author_slug'] ?? '' );
        }
        if ( ( $entry['type'] ?? 'post' ) === 'page' ) {
            $metadata['parent_slug'] = (string) ( $entry['parent_slug'] ?? '' );
        }

        foreach ( is_array( $entry['translations'] ?? null ) ? $entry['translations'] : [] as $language => $translation ) {
            if ( ! is_string( $language ) || ! is_array( $translation ) ) {
                continue;
            }
            $language = strtolower( trim( $language ) );
            if ( $language === '' ) {
                continue;
            }
            $metadata['translations'][$language] = [
                'title' => (string) ( $translation['title'] ?? '' ),
                'summary' => (string) ( $translation['summary'] ?? '' ),
            ];

            $body_filename = $revision_directory . DIRECTORY_SEPARATOR . 'body.' . $language . '.md';
            if ( file_put_contents( $body_filename, (string) ( $translation['content'] ?? '' ) ) === false ) {
                throw new \RuntimeException( 'Unable to write revision body "' . $body_filename . '"' );
            }
        }

        $metadata_json = json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $metadata_json ) || $metadata_json === '' ) {
            throw new \RuntimeException( 'Unable to encode revision metadata.' );
        }

        if ( file_put_contents( $revision_directory . DIRECTORY_SEPARATOR . 'revision.json', $metadata_json . PHP_EOL ) === false ) {
            throw new \RuntimeException( 'Unable to write revision metadata.' );
        }

        $this->pruneRevisionSnapshots( $entry_directory );
    }

    protected function getEntryDirectory( array $normalized_entry ) : string {
        $base_directory = $normalized_entry['scope'] === 'author'
            ? $this->content_directory . DIRECTORY_SEPARATOR . $normalized_entry['author_slug']
            : $this->content_directory . DIRECTORY_SEPARATOR . 'root';

        return(
            $base_directory .
            DIRECTORY_SEPARATOR . $normalized_entry['type'] . 's' .
            DIRECTORY_SEPARATOR . $normalized_entry['id']
        );
    }

    protected function ensureContentDirectory() : void {
        if ( is_dir( $this->content_directory ) ) {
            return;
        }
        if ( ! mkdir( $this->content_directory, 0775, true ) && ! is_dir( $this->content_directory ) ) {
            throw new \RuntimeException( 'Unable to create content directory.' );
        }
    }

    protected function withLockedContentStore( callable $callback ) : mixed {
        $this->ensureContentDirectory();

        $lock_handle = @ fopen( $this->content_lock_filename, 'c+' );
        if ( $lock_handle === false ) {
            throw new \RuntimeException( 'Unable to open content lock file.' );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock content store.' );
            }

            $this->resetDerivedRuntimeState();
            return( $callback() );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $items = scandir( $directory );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $this->deleteDirectoryRecursively( $path );
                continue;
            }
            unlink( $path );
        }

        rmdir( $directory );
    }

    protected function pruneRevisionSnapshots( string $entry_directory ) : int {
        $revision_root = $entry_directory . DIRECTORY_SEPARATOR . 'revisions';
        $revision_directories = glob( $revision_root . DIRECTORY_SEPARATOR . 'revision_*', GLOB_ONLYDIR );
        if ( ! is_array( $revision_directories ) || count( $revision_directories ) <= $this->revision_retention_limit ) {
            return( 0 );
        }

        rsort( $revision_directories, SORT_STRING );
        $stale_revision_directories = array_slice( $revision_directories, $this->revision_retention_limit );
        foreach ( $stale_revision_directories as $revision_directory ) {
            if ( is_string( $revision_directory ) && is_dir( $revision_directory ) ) {
                $this->deleteDirectoryRecursively( $revision_directory );
            }
        }

        return( count( $stale_revision_directories ) );
    }

    protected function cleanupEmptyParentDirectories( string $directory ) : void {
        $content_root = $this->content_directory;
        while ( $directory !== '' && str_starts_with( $directory, $content_root ) && $directory !== $content_root ) {
            $items = scandir( $directory );
            if ( ! is_array( $items ) ) {
                return;
            }
            $visible_items = array_values(
                array_filter(
                    $items,
                    function( string $item ) : bool {
                        return( $item !== '.' && $item !== '..' );
                    }
                )
            );
            if ( ! empty( $visible_items ) ) {
                return;
            }
            rmdir( $directory );
            $directory = dirname( $directory );
        }
    }

    protected function generateEntryId() : string {
        do {
            $candidate = 'entry_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) );
        } while ( $this->entryIdExists( $candidate ) );

        return( $candidate );
    }

    protected function entryIdExists( string $entry_id ) : bool {
        foreach ( $this->getEntries() as $entry ) {
            if ( $entry['id'] === $entry_id ) {
                return( true );
            }
        }

        foreach ( $this->getRawEntries() as $raw_entry ) {
            if ( is_array( $raw_entry ) && (string) ( $raw_entry['id'] ?? '' ) === $entry_id ) {
                return( true );
            }
        }

        return( false );
    }

    protected function normalizeRevisionRetentionLimit( int $limit ) : int {
        if ( $limit < 0 ) {
            return( 20 );
        }
        if ( $limit > 100 ) {
            return( 100 );
        }

        return( $limit );
    }

    protected function buildLegacyEntryId( string $scope, string $author_slug, string $type, string $slug ) : string {
        if ( $scope === 'author' && $author_slug !== '' ) {
            return( $author_slug . '-' . $type . '-' . $slug );
        }

        return( 'root-' . $type . '-' . $slug );
    }

}
