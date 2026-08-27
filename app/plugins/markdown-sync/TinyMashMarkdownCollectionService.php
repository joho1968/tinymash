<?php

require_once __DIR__ . '/TinyMashMarkdownCollectionLinkResolver.php';

class TinyMashMarkdownCollectionService {

    protected const FORMAT = 'tinymash-markdown-collection';
    protected const FORMAT_VERSION = 1;
    protected const MAX_PAGES = 500;
    protected const MAX_DEPTH = 12;
    protected const MAX_MARKDOWN_BYTES = 1048576;
    protected const MAX_BUNDLE_BYTES = 10485760;

    public function rewritePageLinks( array $page, array $bundle, string $target_path, string $public_base_path = '' ) : string {
        $collection_paths = [];
        foreach ( is_array( $bundle['pages'] ?? null ) ? $bundle['pages'] : [] as $collection_page ) {
            if ( is_array( $collection_page ) ) {
                $collection_paths[] = (string) ( $collection_page['relative_path'] ?? '' );
            }
        }
        $resolver = new TinyMashMarkdownCollectionLinkResolver();
        return( $resolver->rewrite(
            (string) ( $page['markdown'] ?? '' ),
            (string) ( $page['relative_path'] ?? '' ),
            $collection_paths,
            $target_path,
            $public_base_path
        ) );
    }

    public function buildBundleFromDirectory( string $source_directory ) : array {
        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        $resolved_root = realpath( $source_directory );
        if ( ! is_string( $resolved_root ) || ! is_dir( $resolved_root ) || ! is_readable( $resolved_root ) ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }
        if ( is_link( $source_directory ) || is_link( $resolved_root ) ) {
            throw new \InvalidArgumentException( 'source_symlink' );
        }

        $pages = [];
        $this->collectDirectoryPage( $resolved_root, $resolved_root, '', 0, $pages );
        if ( empty( $pages ) ) {
            throw new \InvalidArgumentException( 'source_empty' );
        }

        $root_page = null;
        foreach ( $pages as $page ) {
            if ( (string) ( $page['relative_path'] ?? '' ) === '' ) {
                $root_page = $page;
                break;
            }
        }
        if ( ! is_array( $root_page ) ) {
            throw new \InvalidArgumentException( 'source_root' );
        }

        $collection_id = $this->normalizeStableId( (string) ( $root_page['collection_id'] ?? '' ) );
        if ( $collection_id === '' ) {
            throw new \InvalidArgumentException( 'collection_id' );
        }
        if ( (int) ( $root_page['collection_format'] ?? 0 ) !== self::FORMAT_VERSION ) {
            throw new \InvalidArgumentException( 'collection_format' );
        }

        foreach ( $pages as &$page ) {
            unset( $page['collection_id'], $page['collection_format'] );
        }
        unset( $page );

        return(
            $this->normalizeBundle(
                [
                    'format' => self::FORMAT,
                    'format_version' => self::FORMAT_VERSION,
                    'collection_id' => $collection_id,
                    'pages' => $pages,
                ]
            )
        );
    }

    public function normalizeBundle( array $bundle ) : array {
        if ( array_diff( array_keys( $bundle ), [ 'format', 'format_version', 'collection_id', 'pages', 'digest' ] ) !== [] ) {
            throw new \InvalidArgumentException( 'bundle_field' );
        }
        if ( (string) ( $bundle['format'] ?? '' ) !== self::FORMAT || (int) ( $bundle['format_version'] ?? 0 ) !== self::FORMAT_VERSION ) {
            throw new \InvalidArgumentException( 'bundle_format' );
        }

        $collection_id = $this->normalizeStableId( (string) ( $bundle['collection_id'] ?? '' ) );
        if ( $collection_id === '' ) {
            throw new \InvalidArgumentException( 'collection_id' );
        }

        $raw_pages = is_array( $bundle['pages'] ?? null ) ? array_values( $bundle['pages'] ) : [];
        if ( empty( $raw_pages ) || count( $raw_pages ) > self::MAX_PAGES ) {
            throw new \InvalidArgumentException( 'page_count' );
        }

        $pages = [];
        $ids = [];
        $paths = [];
        $total_bytes = 0;
        foreach ( $raw_pages as $raw_page ) {
            if ( ! is_array( $raw_page ) ) {
                throw new \InvalidArgumentException( 'page_record' );
            }
            if ( array_diff( array_keys( $raw_page ), [ 'id', 'relative_path', 'parent_relative_path', 'parent_source_id', 'slug', 'title', 'summary', 'order', 'status', 'markdown', 'source_hash' ] ) !== [] ) {
                throw new \InvalidArgumentException( 'page_field' );
            }

            $source_id = $this->normalizeStableId( (string) ( $raw_page['id'] ?? '' ) );
            if ( $source_id === '' || isset( $ids[$source_id] ) ) {
                throw new \InvalidArgumentException( $source_id === '' ? 'page_id' : 'page_id_duplicate' );
            }

            $relative_path = $this->normalizeRelativePath( (string) ( $raw_page['relative_path'] ?? '' ) );
            if ( isset( $paths[$relative_path] ) ) {
                throw new \InvalidArgumentException( 'page_path_duplicate' );
            }
            $path_segments = $relative_path === '' ? [] : explode( '/', $relative_path );
            if ( count( $path_segments ) > self::MAX_DEPTH ) {
                throw new \InvalidArgumentException( 'page_depth' );
            }

            $title = mb_trim( (string) ( $raw_page['title'] ?? '' ) );
            if ( $title === '' || mb_strlen( $title ) > 200 ) {
                throw new \InvalidArgumentException( 'page_title' );
            }
            $summary = mb_trim( (string) ( $raw_page['summary'] ?? '' ) );
            if ( mb_strlen( $summary ) > 500 ) {
                throw new \InvalidArgumentException( 'page_summary' );
            }
            $markdown = str_replace( [ "\r\n", "\r" ], "\n", (string) ( $raw_page['markdown'] ?? '' ) );
            if ( ! mb_check_encoding( $markdown, 'UTF-8' ) || strlen( $markdown ) > self::MAX_MARKDOWN_BYTES ) {
                throw new \InvalidArgumentException( 'page_markdown' );
            }
            $total_bytes += strlen( $markdown );
            if ( $total_bytes > self::MAX_BUNDLE_BYTES ) {
                throw new \InvalidArgumentException( 'bundle_size' );
            }

            $status = strtolower( trim( (string) ( $raw_page['status'] ?? 'published' ) ) );
            if ( ! in_array( $status, [ 'published', 'unpublished' ], true ) ) {
                throw new \InvalidArgumentException( 'page_status' );
            }
            $sort_order = (int) ( $raw_page['order'] ?? 0 );
            if ( $sort_order < 0 || $sort_order > 100000 ) {
                throw new \InvalidArgumentException( 'page_order' );
            }

            $parent_relative_path = '';
            $slug = '';
            if ( ! empty( $path_segments ) ) {
                $slug = (string) end( $path_segments );
                array_pop( $path_segments );
                $parent_relative_path = implode( '/', $path_segments );
            }

            $page = [
                'id' => $source_id,
                'relative_path' => $relative_path,
                'parent_relative_path' => $parent_relative_path,
                'parent_source_id' => '',
                'slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'order' => $sort_order,
                'status' => $status,
                'markdown' => $markdown,
            ];
            $page['source_hash'] = $this->computePageSourceHash( $page );
            $pages[] = $page;
            $ids[$source_id] = true;
            $paths[$relative_path] = $source_id;
        }

        if ( ! isset( $paths[''] ) ) {
            throw new \InvalidArgumentException( 'page_root' );
        }

        foreach ( $pages as &$page ) {
            $relative_path = (string) $page['relative_path'];
            if ( $relative_path === '' ) {
                continue;
            }
            $parent_relative_path = (string) $page['parent_relative_path'];
            if ( ! isset( $paths[$parent_relative_path] ) ) {
                throw new \InvalidArgumentException( 'page_parent' );
            }
            $page['parent_source_id'] = $paths[$parent_relative_path];
        }
        unset( $page );

        usort(
            $pages,
            static function( array $left, array $right ) : int {
                $left_depth = (string) ( $left['relative_path'] ?? '' ) === '' ? 0 : substr_count( (string) $left['relative_path'], '/' ) + 1;
                $right_depth = (string) ( $right['relative_path'] ?? '' ) === '' ? 0 : substr_count( (string) $right['relative_path'], '/' ) + 1;
                if ( $left_depth !== $right_depth ) {
                    return( $left_depth <=> $right_depth );
                }
                $order_compare = (int) ( $left['order'] ?? 0 ) <=> (int) ( $right['order'] ?? 0 );
                if ( $order_compare !== 0 ) {
                    return( $order_compare );
                }
                return( strcmp( (string) ( $left['relative_path'] ?? '' ), (string) ( $right['relative_path'] ?? '' ) ) );
            }
        );

        $normalized = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'collection_id' => $collection_id,
            'pages' => $pages,
        ];
        $normalized['digest'] = $this->computeBundleDigest( $normalized );
        return( $normalized );
    }

    public function computeBundleDigest( array $bundle ) : string {
        $payload = [
            'format' => (string) ( $bundle['format'] ?? '' ),
            'format_version' => (int) ( $bundle['format_version'] ?? 0 ),
            'collection_id' => (string) ( $bundle['collection_id'] ?? '' ),
            'pages' => is_array( $bundle['pages'] ?? null ) ? array_values( $bundle['pages'] ) : [],
        ];
        foreach ( $payload['pages'] as &$page ) {
            if ( is_array( $page ) ) {
                unset( $page['source_hash'] );
            }
        }
        unset( $page );

        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        return( hash( 'sha256', $json ) );
    }

    public function computePageSourceHash( array $page ) : string {
        $payload = [
            'id' => (string) ( $page['id'] ?? '' ),
            'relative_path' => (string) ( $page['relative_path'] ?? '' ),
            'title' => (string) ( $page['title'] ?? '' ),
            'summary' => (string) ( $page['summary'] ?? '' ),
            'order' => (int) ( $page['order'] ?? 0 ),
            'status' => (string) ( $page['status'] ?? '' ),
            'markdown' => (string) ( $page['markdown'] ?? '' ),
        ];
        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        return( hash( 'sha256', $json ) );
    }

    protected function collectDirectoryPage( string $root, string $directory, string $relative_path, int $depth, array &$pages ) : void {
        if ( $depth > self::MAX_DEPTH || is_link( $directory ) ) {
            throw new \InvalidArgumentException( $depth > self::MAX_DEPTH ? 'page_depth' : 'source_symlink' );
        }
        if ( count( $pages ) >= self::MAX_PAGES ) {
            throw new \InvalidArgumentException( 'page_count' );
        }

        $index_filename = $directory . DIRECTORY_SEPARATOR . 'index.md';
        if ( ! is_file( $index_filename ) || ! is_readable( $index_filename ) || is_link( $index_filename ) ) {
            throw new \InvalidArgumentException( 'page_index' );
        }
        $contents = @ file_get_contents( $index_filename );
        if ( ! is_string( $contents ) || strlen( $contents ) > self::MAX_MARKDOWN_BYTES || ! mb_check_encoding( $contents, 'UTF-8' ) ) {
            throw new \InvalidArgumentException( 'page_markdown' );
        }

        $parsed = $this->parseFrontMatter( $contents );
        $pages[] = [
            'id' => (string) ( $parsed['metadata']['id'] ?? '' ),
            'relative_path' => $relative_path,
            'title' => (string) ( $parsed['metadata']['title'] ?? '' ),
            'summary' => (string) ( $parsed['metadata']['summary'] ?? '' ),
            'order' => (int) ( $parsed['metadata']['order'] ?? 0 ),
            'status' => (string) ( $parsed['metadata']['status'] ?? 'published' ),
            'markdown' => (string) $parsed['markdown'],
            'collection_id' => $relative_path === '' ? (string) ( $parsed['metadata']['collection'] ?? '' ) : '',
            'collection_format' => $relative_path === '' ? (int) ( $parsed['metadata']['format'] ?? 0 ) : 0,
        ];

        $children = [];
        foreach ( scandir( $directory ) ?: [] as $item ) {
            if ( $item === '.' || $item === '..' || $item === 'index.md' ) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_link( $path ) ) {
                throw new \InvalidArgumentException( 'source_symlink' );
            }
            if ( ! is_dir( $path ) ) {
                throw new \InvalidArgumentException( 'source_file' );
            }
            $segment = $this->normalizePathSegment( $item );
            if ( $segment === '' || $segment !== $item ) {
                throw new \InvalidArgumentException( 'page_path' );
            }
            $resolved = realpath( $path );
            if ( ! is_string( $resolved ) || ! str_starts_with( $resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) {
                throw new \InvalidArgumentException( 'source_path' );
            }
            $children[$segment] = $resolved;
        }
        ksort( $children, SORT_STRING );

        foreach ( $children as $segment => $child_directory ) {
            $child_relative_path = $relative_path === '' ? $segment : $relative_path . '/' . $segment;
            $this->collectDirectoryPage( $root, $child_directory, $child_relative_path, $depth + 1, $pages );
        }
    }

    protected function parseFrontMatter( string $contents ) : array {
        $contents = str_replace( [ "\r\n", "\r" ], "\n", $contents );
        if ( ! str_starts_with( $contents, "---\n" ) ) {
            throw new \InvalidArgumentException( 'front_matter' );
        }
        $end = strpos( $contents, "\n---\n", 4 );
        if ( $end === false ) {
            throw new \InvalidArgumentException( 'front_matter' );
        }

        $header = substr( $contents, 4, $end - 4 );
        $markdown = substr( $contents, $end + 5 );
        $metadata = [];
        $allowed_keys = [ 'id', 'title', 'summary', 'order', 'status', 'collection', 'format' ];
        foreach ( explode( "\n", $header ) as $line ) {
            if ( trim( $line ) === '' ) {
                continue;
            }
            $parts = explode( ':', $line, 2 );
            $key = strtolower( trim( (string) ( $parts[0] ?? '' ) ) );
            if ( count( $parts ) !== 2 || ! in_array( $key, $allowed_keys, true ) || array_key_exists( $key, $metadata ) ) {
                throw new \InvalidArgumentException( 'front_matter_field' );
            }
            $value = mb_trim( (string) $parts[1] );
            if ( strlen( $value ) >= 2 && ( ( $value[0] === '"' && $value[-1] === '"' ) || ( $value[0] === "'" && $value[-1] === "'" ) ) ) {
                $value = substr( $value, 1, -1 );
            }
            $metadata[$key] = $value;
        }

        return( [ 'metadata' => $metadata, 'markdown' => $markdown ] );
    }

    protected function normalizeStableId( string $value ) : string {
        $value = strtolower( trim( $value ) );
        if ( preg_match( '/^[a-z0-9][a-z0-9._-]{1,127}$/D', $value ) !== 1 ) {
            return( '' );
        }
        return( $value );
    }

    protected function normalizeRelativePath( string $path ) : string {
        $path = trim( str_replace( '\\', '/', $path ), '/' );
        if ( $path === '' ) {
            return( '' );
        }
        $segments = explode( '/', $path );
        $normalized = [];
        foreach ( $segments as $segment ) {
            $part = $this->normalizePathSegment( $segment );
            if ( $part === '' || $part !== $segment ) {
                throw new \InvalidArgumentException( 'page_path' );
            }
            $normalized[] = $part;
        }
        return( implode( '/', $normalized ) );
    }

    protected function normalizePathSegment( string $segment ) : string {
        $segment = strtolower( trim( $segment ) );
        return( preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $segment ) === 1 ? $segment : '' );
    }
}
