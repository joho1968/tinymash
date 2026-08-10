<?php
namespace app\classes;

class TinyMashLinkIntegrityService {

    protected TinyMashContentRepository $content_repository;
    protected TinyMashConfig $config;
    protected TinyMashMarkdownRenderer $markdown_renderer;
    protected TinyMashMediaService $media_service;
    protected object $router;
    protected string $public_root;

    public function __construct(
        TinyMashContentRepository $content_repository,
        TinyMashConfig $config,
        TinyMashMarkdownRenderer $markdown_renderer,
        TinyMashMediaService $media_service,
        object $router,
        string $public_root
    ) {
        $this->content_repository = $content_repository;
        $this->config = $config;
        $this->markdown_renderer = $markdown_renderer;
        $this->media_service = $media_service;
        $this->router = $router;
        $this->public_root = rtrim( $public_root, DIRECTORY_SEPARATOR );
    }

    public function scan( ?callable $redirect_resolver = null ) : array {
        $entries = $this->content_repository->getEntriesForAudit( $this->config->getDefaultLanguage(), null, true, false );
        $published_paths = [ '/' => true ];
        $unpublished_paths = [];
        foreach ( $this->content_repository->getPublicAuthorSlugs() as $author_slug ) {
            $published_paths[$this->normalizeUrlPath( '/' . rawurlencode( $author_slug ) )] = true;
        }
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $path = $this->buildEntryPublicPath( $entry );
            if ( $path === '' ) {
                continue;
            }
            if ( (string) ( $entry['status'] ?? '' ) === 'published' ) {
                $published_paths[$path] = true;
            } else {
                $unpublished_paths[$path] = true;
            }
        }

        $findings = [];
        $summary = [
            'entries' => count( $entries ),
            'menu_items' => 0,
            'internal_targets' => 0,
            'valid_targets' => 0,
            'external_targets' => 0,
            'ignored_fragments' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $source_path = $this->buildEntryPublicPath( $entry );
            if ( $source_path === '' ) {
                continue;
            }

            foreach ( [ 'summary', 'content' ] as $field ) {
                $markdown = (string) ( $entry[$field] ?? '' );
                if ( trim( $markdown ) === '' ) {
                    continue;
                }

                try {
                    $targets = $this->extractRenderedTargets( $markdown );
                } catch ( \Throwable $e ) {
                    $findings[] = $this->buildFinding(
                        'error',
                        'render_error',
                        $entry,
                        $field,
                        '',
                        '',
                        'This field could not be rendered for link inspection.'
                    );
                    $summary['errors']++;
                    continue;
                }

                $seen = [];
                foreach ( $targets as $target ) {
                    $destination = (string) ( $target['destination'] ?? '' );
                    $signature = (string) ( $target['kind'] ?? 'link' ) . '|' . $destination;
                    if ( isset( $seen[$signature] ) ) {
                        continue;
                    }
                    $seen[$signature] = true;
                    $this->inspectTarget(
                        $destination,
                        (string) ( $target['kind'] ?? 'link' ),
                        $source_path,
                        $entry,
                        $field,
                        $published_paths,
                        $unpublished_paths,
                        $redirect_resolver,
                        $findings,
                        $summary
                    );
                }
            }
        }

        foreach ( $this->flattenMenuItems( $this->config->getMenusConfig() ) as $menu_item ) {
            $summary['menu_items']++;
            $destination = (string) ( $menu_item['destination'] ?? '' );
            if ( $destination === '' ) {
                continue;
            }
            $this->inspectTarget(
                $destination,
                'link',
                '/',
                [
                    'id' => '',
                    'title' => (string) ( $menu_item['label'] ?? 'Menu item' ),
                    'source_type' => 'menu',
                    'menu_location' => (string) ( $menu_item['location'] ?? '' ),
                ],
                'menu',
                $published_paths,
                $unpublished_paths,
                $redirect_resolver,
                $findings,
                $summary
            );
        }

        usort(
            $findings,
            static function( array $left, array $right ) : int {
                $severity_order = [ 'error' => 0, 'warning' => 1 ];
                $left_order = $severity_order[(string) ( $left['severity'] ?? '' )] ?? 2;
                $right_order = $severity_order[(string) ( $right['severity'] ?? '' )] ?? 2;
                if ( $left_order !== $right_order ) {
                    return( $left_order <=> $right_order );
                }
                return( strcmp( (string) ( $left['source_label'] ?? '' ), (string) ( $right['source_label'] ?? '' ) ) );
            }
        );

        return(
            [
                'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'summary' => $summary,
                'findings' => $findings,
                'scope_note' => 'Checks rendered content and configured menus against local content, media, public files, registered GET routes, and redirects. External URLs and fragment IDs are not requested.',
            ]
        );
    }

    protected function inspectTarget(
        string $destination,
        string $kind,
        string $source_path,
        array $source,
        string $field,
        array $published_paths,
        array $unpublished_paths,
        ?callable $redirect_resolver,
        array &$findings,
        array &$summary
    ) : void {
        $resolution = $this->resolveDestination( $destination, $source_path );
        $status = (string) ( $resolution['status'] ?? 'invalid' );
        if ( $status === 'external' ) {
            $summary['external_targets']++;
            return;
        }
        if ( $status === 'fragment' ) {
            $summary['ignored_fragments']++;
            return;
        }

        $summary['internal_targets']++;
        $path = (string) ( $resolution['path'] ?? '' );
        if ( $status !== 'internal' || $path === '' ) {
            $findings[] = $this->buildFinding( 'error', 'invalid_url', $source, $field, $destination, '', 'The target URL is invalid.' );
            $summary['errors']++;
            return;
        }

        if ( isset( $published_paths[$path] ) ) {
            $summary['valid_targets']++;
            return;
        }
        if ( isset( $unpublished_paths[$path] ) ) {
            $findings[] = $this->buildFinding( 'warning', 'unpublished_content', $source, $field, $destination, $path, 'The target exists but is not published.' );
            $summary['warnings']++;
            return;
        }

        if ( str_starts_with( $path, '/media/' ) ) {
            if ( $this->managedMediaExists( $path ) ) {
                $summary['valid_targets']++;
                return;
            }
            $findings[] = $this->buildFinding( 'error', 'missing_media', $source, $field, $destination, $path, 'The managed media file is missing.' );
            $summary['errors']++;
            return;
        }

        if ( $this->publicFileExists( $path ) || $this->registeredGetRouteExists( $path ) ) {
            $summary['valid_targets']++;
            return;
        }

        if ( is_callable( $redirect_resolver ) ) {
            $redirect = $redirect_resolver( $path );
            if ( is_array( $redirect ) && ! empty( $redirect['target_url'] ) ) {
                $finding = $this->buildFinding( 'warning', 'redirected', $source, $field, $destination, $path, 'The target resolves through a configured redirect.' );
                $finding['redirect_target'] = (string) $redirect['target_url'];
                $findings[] = $finding;
                $summary['warnings']++;
                return;
            }
        }

        $message = $kind === 'image' ? 'The internal image target could not be resolved.' : 'The internal link target could not be resolved.';
        $findings[] = $this->buildFinding( 'error', 'missing_internal', $source, $field, $destination, $path, $message );
        $summary['errors']++;
    }

    protected function extractRenderedTargets( string $markdown ) : array {
        if ( ! class_exists( '\DOMDocument' ) ) {
            throw new \RuntimeException( 'DOMDocument is not available.' );
        }

        $html = $this->markdown_renderer->render( $markdown );
        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $previous_state = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="tm-link-integrity-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_state );
        if ( ! $loaded ) {
            throw new \RuntimeException( 'Rendered content could not be parsed.' );
        }

        $targets = [];
        foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
            if ( $link instanceof \DOMElement && $link->hasAttribute( 'href' ) ) {
                $targets[] = [ 'kind' => 'link', 'destination' => mb_trim( $link->getAttribute( 'href' ) ) ];
            }
        }
        foreach ( $dom->getElementsByTagName( 'img' ) as $image ) {
            if ( $image instanceof \DOMElement && $image->hasAttribute( 'src' ) ) {
                $targets[] = [ 'kind' => 'image', 'destination' => mb_trim( $image->getAttribute( 'src' ) ) ];
            }
        }

        return( $targets );
    }

    protected function resolveDestination( string $destination, string $source_path ) : array {
        $destination = mb_trim( $destination );
        if ( $destination === '' || str_starts_with( $destination, '#' ) || str_starts_with( $destination, '?' ) ) {
            return( [ 'status' => 'fragment', 'path' => $source_path ] );
        }
        if ( str_contains( $destination, "\0" ) || str_contains( $destination, '\\' ) || str_starts_with( $destination, '//' ) ) {
            return( [ 'status' => 'invalid', 'path' => '' ] );
        }

        $parts = parse_url( $destination );
        if ( $parts === false ) {
            return( [ 'status' => 'invalid', 'path' => '' ] );
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( $scheme !== '' && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return( [ 'status' => 'external', 'path' => '' ] );
        }
        if ( $scheme !== '' && ! $this->absoluteUrlBelongsToSite( $parts ) ) {
            return( [ 'status' => 'external', 'path' => '' ] );
        }

        $path = (string) ( $parts['path'] ?? '' );
        if ( $path === '' ) {
            return( [ 'status' => 'fragment', 'path' => $source_path ] );
        }
        if ( ! str_starts_with( $path, '/' ) ) {
            $base_directory = $source_path === '/' ? '/' : dirname( $source_path );
            $path = rtrim( str_replace( '\\', '/', $base_directory ), '/' ) . '/' . $path;
        }

        $path = $this->normalizeUrlPath( $path );
        return( $path !== '' ? [ 'status' => 'internal', 'path' => $path ] : [ 'status' => 'invalid', 'path' => '' ] );
    }

    protected function absoluteUrlBelongsToSite( array $parts ) : bool {
        $base_url = (string) $this->config->configGetBaseURL();
        $base_parts = parse_url( $base_url );
        if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) || empty( $parts['host'] ) ) {
            return( false );
        }

        $base_port = (int) ( $base_parts['port'] ?? ( strtolower( (string) ( $base_parts['scheme'] ?? '' ) ) === 'http' ? 80 : 443 ) );
        $target_port = (int) ( $parts['port'] ?? ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'http' ? 80 : 443 ) );
        return( strtolower( (string) $base_parts['host'] ) === strtolower( (string) $parts['host'] ) && $base_port === $target_port );
    }

    protected function normalizeUrlPath( string $path ) : string {
        if ( $path === '' || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) ) {
            return( '' );
        }

        $segments = [];
        foreach ( explode( '/', $path ) as $segment ) {
            $decoded_segment = rawurldecode( $segment );
            if ( $decoded_segment === '' || $decoded_segment === '.' ) {
                continue;
            }
            if ( $decoded_segment === '..' ) {
                array_pop( $segments );
                continue;
            }
            if ( str_contains( $decoded_segment, '/' ) || str_contains( $decoded_segment, '\\' ) || str_contains( $decoded_segment, "\0" ) ) {
                return( '' );
            }
            $segments[] = rawurlencode( $decoded_segment );
        }

        return( '/' . implode( '/', $segments ) );
    }

    protected function buildEntryPublicPath( array $entry ) : string {
        $path = trim( (string) ( $entry['path'] ?? '' ), '/' );
        if ( $path === '' ) {
            return( '' );
        }
        if ( (string) ( $entry['scope'] ?? 'root' ) === 'author' ) {
            $author_slug = trim( (string) ( $entry['author_slug'] ?? '' ), '/' );
            if ( $author_slug === '' ) {
                return( '' );
            }
            $path = $author_slug . '/' . $path;
        }

        return( $this->normalizeUrlPath( '/' . $path ) );
    }

    protected function managedMediaExists( string $path ) : bool {
        $segments = array_map( 'rawurldecode', explode( '/', trim( $path, '/' ) ) );
        if ( count( $segments ) !== 5 || $segments[0] !== 'media' ) {
            return( false );
        }

        return( is_array( $this->media_service->resolveMediaPath( $segments[1], $segments[2], $segments[3], $segments[4] ) ) );
    }

    protected function publicFileExists( string $path ) : bool {
        $relative_path = rawurldecode( ltrim( $path, '/' ) );
        if ( $relative_path === '' || str_contains( $relative_path, "\0" ) || str_contains( $relative_path, '\\' ) ) {
            return( false );
        }

        $real_root = realpath( $this->public_root );
        $real_file = realpath( $this->public_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
        return(
            is_string( $real_root )
            && is_string( $real_file )
            && str_starts_with( $real_file, rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR )
            && is_file( $real_file )
            && is_readable( $real_file )
        );
    }

    protected function registeredGetRouteExists( string $path ) : bool {
        if ( ! method_exists( $this->router, 'getRoutes' ) ) {
            return( false );
        }

        foreach ( $this->router->getRoutes() as $route ) {
            if ( ! is_object( $route ) || ! method_exists( $route, 'matchMethod' ) || ! method_exists( $route, 'matchUrl' ) ) {
                continue;
            }
            $pattern = (string) ( $route->pattern ?? '' );
            if ( in_array( $pattern, [ '/@segment', '/@first/@second' ], true ) ) {
                continue;
            }
            if ( $route->matchMethod( 'GET' ) && $route->matchUrl( $path ) ) {
                return( true );
            }
        }

        return( false );
    }

    protected function flattenMenuItems( array $menus ) : array {
        $items = [];
        $walk = function( array $menu_items, string $location ) use ( &$walk, &$items ) : void {
            foreach ( $menu_items as $menu_item ) {
                if ( ! is_array( $menu_item ) ) {
                    continue;
                }
                $type = strtolower( trim( (string) ( $menu_item['type'] ?? 'page' ) ) );
                if ( $type === 'page' ) {
                    $path = trim( (string) ( $menu_item['path'] ?? '' ), '/' );
                    if ( $path !== '' ) {
                        $items[] = [
                            'location' => $location,
                            'label' => mb_trim( (string) ( $menu_item['label'] ?? $path ) ),
                            'destination' => '/' . $path,
                        ];
                    }
                } elseif ( $type === 'url' ) {
                    $items[] = [
                        'location' => $location,
                        'label' => mb_trim( (string) ( $menu_item['label'] ?? 'Menu link' ) ),
                        'destination' => mb_trim( (string) ( $menu_item['url'] ?? '' ) ),
                    ];
                }
                $walk( is_array( $menu_item['children'] ?? null ) ? $menu_item['children'] : [], $location );
            }
        };

        foreach ( [ 'primary', 'footer' ] as $location ) {
            $walk( is_array( $menus[$location] ?? null ) ? $menus[$location] : [], $location );
        }
        return( $items );
    }

    protected function buildFinding( string $severity, string $type, array $source, string $field, string $destination, string $resolved_path, string $message ) : array {
        $is_menu = (string) ( $source['source_type'] ?? '' ) === 'menu';
        $source_label = $is_menu
            ? ucfirst( (string) ( $source['menu_location'] ?? 'menu' ) ) . ' menu: ' . (string) ( $source['title'] ?? 'Menu item' )
            : (string) ( $source['title'] ?? 'Untitled content' );
        $edit_url = $is_menu
            ? (string) $this->config->configGetAdminURL() . '/system/menus'
            : (string) $this->config->configGetAdminURL() . '/editor?entry=' . rawurlencode( (string) ( $source['id'] ?? '' ) ) . '&source=published';

        return(
            [
                'severity' => $severity,
                'type' => $type,
                'source_type' => $is_menu ? 'menu' : 'content',
                'source_label' => $source_label,
                'field' => $field,
                'destination' => $destination,
                'resolved_path' => $resolved_path,
                'message' => $message,
                'edit_url' => $edit_url,
            ]
        );
    }
}
