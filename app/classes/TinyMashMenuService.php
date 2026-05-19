<?php
namespace app\classes;

class TinyMashMenuService {

    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected array $configured_locations_cache = [];
    protected array $resolved_locations_cache = [];

    public function __construct( TinyMashConfig $config, TinyMashContentRepository $content_repository ) {
        $this->config = $config;
        $this->content_repository = $content_repository;
    }

    public function getConfiguredLocations() : array {
        if ( ! empty( $this->configured_locations_cache ) ) {
            return( $this->configured_locations_cache );
        }

        $locations = $this->normalizeConfiguredLocations( $this->config->getMenusConfig() );
        $this->configured_locations_cache = [
            'primary' => $locations['primary'] ?? [],
            'footer' => $locations['footer'] ?? [],
        ];

        return( $this->configured_locations_cache );
    }

    public function getResolvedLocation( string $location, string $current_path = '' ) : array {
        $location = $this->normalizeLocationKey( $location );
        if ( $location === '' ) {
            return( [] );
        }

        $cache_key = $location . '|' . trim( $current_path, '/' );
        if ( array_key_exists( $cache_key, $this->resolved_locations_cache ) ) {
            return( $this->resolved_locations_cache[$cache_key] );
        }

        $items = [];
        foreach ( $this->getConfiguredLocations()[$location] ?? [] as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $resolved_item = $this->resolveConfiguredItem( $item, trim( $current_path, '/' ) );
            if ( is_array( $resolved_item ) ) {
                $items[] = $resolved_item;
            }
        }

        if ( $location === 'footer' ) {
            $items = $this->flattenResolvedItems( $items );
        }

        $this->resolved_locations_cache[$cache_key] = $items;
        return( $this->resolved_locations_cache[$cache_key] );
    }

    protected function normalizeConfiguredLocations( mixed $locations ) : array {
        $locations = is_array( $locations ) ? $locations : [];
        $normalized_locations = [];

        foreach ( [ 'primary', 'footer' ] as $location ) {
            $normalized_locations[$location] = [];
            foreach ( (array) ( $locations[$location] ?? [] ) as $item ) {
                $normalized_item = $this->normalizeConfiguredItem( $item );
                if ( is_array( $normalized_item ) ) {
                    $normalized_locations[$location][] = $normalized_item;
                }
            }
        }

        return( $normalized_locations );
    }

    protected function normalizeConfiguredItem( mixed $item ) : ?array {
        if ( ! is_array( $item ) ) {
            return( null );
        }

        $type = strtolower( trim( (string) ( $item['type'] ?? 'page' ) ) );
        if ( ! in_array( $type, [ 'page', 'url', 'separator' ], true ) ) {
            $type = 'page';
        }

        $label = trim( (string) ( $item['label'] ?? '' ) );
        $path = trim( preg_replace( '@/+@', '/', (string) ( $item['path'] ?? '' ) ) ?? '', '/' );
        $url = trim( (string) ( $item['url'] ?? '' ) );
        $new_tab = ! empty( $item['new_tab'] );
        $children = [];
        foreach ( (array) ( $item['children'] ?? [] ) as $child_item ) {
            $normalized_child = $this->normalizeConfiguredItem( $child_item );
            if ( is_array( $normalized_child ) ) {
                $children[] = $normalized_child;
            }
        }

        if ( $type === 'separator' ) {
            return(
                [
                    'type' => 'separator',
                    'path' => '',
                    'url' => '',
                    'label' => '',
                    'new_tab' => false,
                    'children' => [],
                ]
            );
        }

        if ( $type === 'page' ) {
            if ( $path === '' ) {
                return( null );
            }

            return(
                [
                    'type' => 'page',
                    'path' => $path,
                    'url' => '',
                    'label' => $label,
                    'new_tab' => false,
                    'children' => $children,
                ]
            );
        }

        if ( $url === '' ) {
            return( null );
        }

        return(
            [
                'type' => 'url',
                'path' => '',
                'url' => $url,
                'label' => $label,
                'new_tab' => $new_tab,
                'children' => $children,
            ]
        );
    }

    protected function resolveConfiguredItem( array $item, string $current_path = '' ) : ?array {
        $type = (string) ( $item['type'] ?? 'page' );
        if ( $type === 'separator' ) {
            return(
                [
                    'title' => '',
                    'url' => '',
                    'children' => [],
                    'is_current' => false,
                    'is_ancestor' => false,
                    'is_separator' => true,
                ]
            );
        }

        $resolved_children = [];
        foreach ( (array) ( $item['children'] ?? [] ) as $child_item ) {
            if ( ! is_array( $child_item ) ) {
                continue;
            }

            $resolved_child = $this->resolveConfiguredItem( $child_item, $current_path );
            if ( is_array( $resolved_child ) ) {
                $resolved_children[] = $resolved_child;
            }
        }

        if ( $type === 'page' ) {
            $path = trim( (string) ( $item['path'] ?? '' ), '/' );
            if ( $path === '' ) {
                return( null );
            }

            $entry = $this->content_repository->getRootEntryByPath( $path, $this->config->getDefaultLanguage() );
            if ( ! is_array( $entry ) || (string) ( $entry['type'] ?? '' ) !== 'page' || (string) ( $entry['status'] ?? '' ) !== 'published' ) {
                return( null );
            }

            return(
                [
                    'title' => trim( (string) ( $item['label'] ?? '' ) ) !== '' ? trim( (string) $item['label'] ) : (string) ( $entry['title'] ?? $path ),
                    'url' => '/' . str_replace( '%2F', '/', rawurlencode( $path ) ),
                    'children' => $resolved_children,
                    'is_current' => $current_path !== '' && $path === $current_path,
                    'is_ancestor' => ( $current_path !== '' && str_starts_with( $current_path, $path . '/' ) ) || $this->childrenContainActiveItem( $resolved_children ),
                ]
            );
        }

        $url = trim( (string) ( $item['url'] ?? '' ) );
        if ( $url === '' ) {
            return( null );
        }

        $parsed_path = trim( (string) ( parse_url( $url, PHP_URL_PATH ) ?? '' ), '/' );
        $is_relative = str_starts_with( $url, '/' );
        $target = ! empty( $item['new_tab'] ) ? '_blank' : '';
        $rel = $target !== '' ? 'noopener noreferrer' : '';

        return(
            [
                'title' => trim( (string) ( $item['label'] ?? '' ) ) !== '' ? trim( (string) $item['label'] ) : $url,
                'url' => $url,
                'children' => $resolved_children,
                'is_current' => $is_relative && $parsed_path !== '' && $parsed_path === $current_path,
                'is_ancestor' => ( $is_relative && $parsed_path !== '' && $current_path !== '' && $parsed_path !== $current_path && str_starts_with( $current_path, $parsed_path . '/' ) ) || $this->childrenContainActiveItem( $resolved_children ),
                'target' => $target,
                'rel' => $rel,
            ]
        );
    }

    protected function childrenContainActiveItem( array $items ) : bool {
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            if ( ! empty( $item['is_current'] ) || ! empty( $item['is_ancestor'] ) || $this->childrenContainActiveItem( (array) ( $item['children'] ?? [] ) ) ) {
                return( true );
            }
        }

        return( false );
    }

    protected function flattenResolvedItems( array $items ) : array {
        $flattened_items = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
            $item['children'] = [];
            $flattened_items[] = $item;

            foreach ( $this->flattenResolvedItems( $children ) as $child_item ) {
                $flattened_items[] = $child_item;
            }
        }

        return( $flattened_items );
    }

    protected function normalizeLocationKey( string $location ) : string {
        $location = strtolower( trim( $location ) );
        return( in_array( $location, [ 'primary', 'footer' ], true ) ? $location : '' );
    }

}// TinyMashMenuService
