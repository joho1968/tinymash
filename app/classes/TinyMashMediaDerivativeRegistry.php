<?php
namespace app\classes;

class TinyMashMediaDerivativeRegistry {

    protected array $definitions = [];

    public function __construct() {
        $this->registerDerivativeDefinition(
            'stored_primary',
            [
                'label' => 'Stored primary image',
                'description' => 'The canonical stored image file currently served by the runtime. Large uploads may be constrained before storage to fit the configured limits.',
            ]
        );
        $this->registerDerivativeDefinition(
            'thumbnail',
            [
                'label' => 'Generated thumbnail',
                'description' => 'An optional smaller derivative used for faster media browsing in admin surfaces such as the featured-image picker.',
            ]
        );
        $this->registerDerivativeDefinition(
            'display',
            [
                'label' => 'Display image',
                'description' => 'An optional web-sized derivative used for lightboxes and normal public viewing without serving the stored original.',
            ]
        );
    }

    public function registerDerivativeDefinition( string $derivative_key, array $definition ) : void {
        $derivative_key = $this->normalizeDerivativeKey( $derivative_key );
        if ( $derivative_key === '' ) {
            return;
        }

        $this->definitions[$derivative_key] = [
            'key' => $derivative_key,
            'label' => trim( (string) ( $definition['label'] ?? $this->humanizeKey( $derivative_key ) ) ),
            'description' => trim( (string) ( $definition['description'] ?? '' ) ),
        ];
    }

    public function resolveDerivativeDefinition( string $derivative_key ) : array {
        $derivative_key = $this->normalizeDerivativeKey( $derivative_key );
        if ( $derivative_key === '' ) {
            return( [] );
        }

        return(
            $this->definitions[$derivative_key] ?? [
                'key' => $derivative_key,
                'label' => $this->humanizeKey( $derivative_key ),
                'description' => '',
            ]
        );
    }

    protected function normalizeDerivativeKey( string $derivative_key ) : string {
        $derivative_key = strtolower( trim( $derivative_key ) );
        return( preg_replace( '/[^a-z0-9._-]/', '', $derivative_key ) ?? '' );
    }

    protected function humanizeKey( string $key ) : string {
        return( ucwords( str_replace( [ '.', '-', '_' ], ' ', $key ) ) );
    }

}// TinyMashMediaDerivativeRegistry
