<?php
namespace app\classes;

class TinyMashThemeSupportRegistry {

    protected array $definitions = [];

    public function __construct() {
        $this->registerSupportDefinition(
            'menus',
            [
                'label' => 'Menus',
                'description' => 'The theme can render documented menu locations.',
            ]
        );
        $this->registerSupportDefinition(
            'primary_menu',
            [
                'label' => 'Primary menu',
                'description' => 'The theme can render the primary site menu.',
            ]
        );
        $this->registerSupportDefinition(
            'sidebar_menu',
            [
                'label' => 'Sidebar menu',
                'description' => 'The theme can render the primary site menu as a sidebar tree.',
            ]
        );
        $this->registerSupportDefinition(
            'breadcrumbs',
            [
                'label' => 'Breadcrumbs',
                'description' => 'The theme can render breadcrumb trails for supported content views.',
            ]
        );
        $this->registerSupportDefinition(
            'page_sidebar',
            [
                'label' => 'Page sidebar',
                'description' => 'The theme can render the published page structure as a sidebar surface.',
            ]
        );
        $this->registerSupportDefinition(
            'after_content_child_pages',
            [
                'label' => 'After-content child pages',
                'description' => 'The theme can render child-page navigation after page content.',
            ]
        );
        $this->registerSupportDefinition(
            'footer_menu',
            [
                'label' => 'Footer menu',
                'description' => 'The theme can render a dedicated footer menu location.',
            ]
        );
        $this->registerSupportDefinition(
            'public_background',
            [
                'label' => 'Public background',
                'description' => 'The theme can render the configured public background image.',
            ]
        );
        $this->registerSupportDefinition(
            'syntax_highlighting',
            [
                'label' => 'Syntax highlighting',
                'description' => 'The theme can integrate with the syntax-highlighting plugin for fenced code blocks.',
            ]
        );
    }

    public function registerSupportDefinition( string $support_key, array $definition ) : void {
        $support_key = $this->normalizeSupportKey( $support_key );
        if ( $support_key === '' ) {
            return;
        }

        $this->definitions[$support_key] = [
            'key' => $support_key,
            'label' => trim( (string) ( $definition['label'] ?? $this->humanizeKey( $support_key ) ) ),
            'description' => trim( (string) ( $definition['description'] ?? '' ) ),
        ];
    }

    public function resolveSupports( array $supports ) : array {
        $support_flags = $this->normalizeSupportFlags( $supports );
        $resolved_supports = [];

        foreach ( $support_flags as $support_key => $enabled ) {
            $definition = $this->definitions[$support_key] ?? [
                'key' => $support_key,
                'label' => $this->humanizeKey( $support_key ),
                'description' => '',
            ];
            $definition['enabled'] = (bool) $enabled;
            $resolved_supports[] = $definition;
        }

        usort(
            $resolved_supports,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $resolved_supports );
    }

    public function normalizeSupportFlags( array $supports ) : array {
        $support_flags = [];

        foreach ( $supports as $support_key => $support_value ) {
            if ( is_int( $support_key ) ) {
                if ( is_string( $support_value ) ) {
                    $normalized_key = $this->normalizeSupportKey( $support_value );
                    if ( $normalized_key !== '' ) {
                        $support_flags[$normalized_key] = true;
                    }
                } elseif ( is_array( $support_value ) && ! empty( $support_value['key'] ) ) {
                    $normalized_key = $this->normalizeSupportKey( (string) $support_value['key'] );
                    if ( $normalized_key !== '' ) {
                        $support_flags[$normalized_key] = ! array_key_exists( 'enabled', $support_value ) || ! empty( $support_value['enabled'] );
                    }
                }
                continue;
            }

            if ( ! is_string( $support_key ) ) {
                continue;
            }

            $normalized_key = $this->normalizeSupportKey( $support_key );
            if ( $normalized_key === '' ) {
                continue;
            }

            $support_flags[$normalized_key] = (bool) $support_value;
        }

        ksort( $support_flags, SORT_STRING );
        return( $support_flags );
    }

    protected function normalizeSupportKey( string $support_key ) : string {
        $support_key = strtolower( trim( $support_key ) );
        return( preg_replace( '/[^a-z0-9._-]/', '', $support_key ) ?? '' );
    }

    protected function humanizeKey( string $key ) : string {
        return( ucwords( str_replace( [ '.', '-', '_' ], ' ', $key ) ) );
    }

}// TinyMashThemeSupportRegistry
