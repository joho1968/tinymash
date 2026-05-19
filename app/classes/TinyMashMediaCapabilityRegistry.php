<?php
namespace app\classes;

class TinyMashMediaCapabilityRegistry {

    protected array $definitions = [];

    public function __construct() {
        $this->registerCapabilityDefinition(
            'editor.image_picker',
            [
                'label' => 'Editor image picker',
                'description' => 'Authors can choose image files directly from the composer.',
            ]
        );
        $this->registerCapabilityDefinition(
            'editor.paste_images',
            [
                'label' => 'Paste images',
                'description' => 'Clipboard images can be pasted directly into the composer.',
            ]
        );
        $this->registerCapabilityDefinition(
            'editor.drag_drop_images',
            [
                'label' => 'Drag and drop',
                'description' => 'Images can be dropped directly onto the composer.',
            ]
        );
        $this->registerCapabilityDefinition(
            'storage.canonical_file',
            [
                'label' => 'Canonical stored file',
                'description' => 'Each upload is stored as one canonical file on disk behind the media service.',
            ]
        );
        $this->registerCapabilityDefinition(
            'delivery.media_route',
            [
                'label' => 'Media route delivery',
                'description' => 'Stored media is delivered through the documented `/media/...` route shape.',
            ]
        );
        $this->registerCapabilityDefinition(
            'transform.resize_to_limits',
            [
                'label' => 'Resize to limits',
                'description' => 'Oversized uploads can be resized to the configured limits before storage when an image driver is available.',
            ]
        );
        $this->registerCapabilityDefinition(
            'transform.auto_orient',
            [
                'label' => 'Auto-orient uploads',
                'description' => 'Image orientation metadata can be normalized during ingest when supported by the active driver.',
            ]
        );
    }

    public function registerCapabilityDefinition( string $capability_key, array $definition ) : void {
        $capability_key = $this->normalizeCapabilityKey( $capability_key );
        if ( $capability_key === '' ) {
            return;
        }

        $this->definitions[$capability_key] = [
            'key' => $capability_key,
            'label' => trim( (string) ( $definition['label'] ?? $this->humanizeKey( $capability_key ) ) ),
            'description' => trim( (string) ( $definition['description'] ?? '' ) ),
        ];
    }

    public function resolveCapabilities( array $capabilities ) : array {
        $capability_flags = $this->normalizeCapabilityFlags( $capabilities );
        $resolved_capabilities = [];

        foreach ( $capability_flags as $capability_key => $enabled ) {
            $definition = $this->definitions[$capability_key] ?? [
                'key' => $capability_key,
                'label' => $this->humanizeKey( $capability_key ),
                'description' => '',
            ];
            $definition['enabled'] = (bool) $enabled;
            $resolved_capabilities[] = $definition;
        }

        usort(
            $resolved_capabilities,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $resolved_capabilities );
    }

    public function normalizeCapabilityFlags( array $capabilities ) : array {
        $capability_flags = [];

        foreach ( $capabilities as $capability_key => $capability_value ) {
            if ( is_int( $capability_key ) ) {
                if ( is_string( $capability_value ) ) {
                    $normalized_key = $this->normalizeCapabilityKey( $capability_value );
                    if ( $normalized_key !== '' ) {
                        $capability_flags[$normalized_key] = true;
                    }
                } elseif ( is_array( $capability_value ) && ! empty( $capability_value['key'] ) ) {
                    $normalized_key = $this->normalizeCapabilityKey( (string) $capability_value['key'] );
                    if ( $normalized_key !== '' ) {
                        $capability_flags[$normalized_key] = ! array_key_exists( 'enabled', $capability_value ) || ! empty( $capability_value['enabled'] );
                    }
                }
                continue;
            }

            if ( ! is_string( $capability_key ) ) {
                continue;
            }

            $normalized_key = $this->normalizeCapabilityKey( $capability_key );
            if ( $normalized_key === '' ) {
                continue;
            }

            $capability_flags[$normalized_key] = (bool) $capability_value;
        }

        ksort( $capability_flags, SORT_STRING );
        return( $capability_flags );
    }

    protected function normalizeCapabilityKey( string $capability_key ) : string {
        $capability_key = strtolower( trim( $capability_key ) );
        return( preg_replace( '/[^a-z0-9._-]/', '', $capability_key ) ?? '' );
    }

    protected function humanizeKey( string $key ) : string {
        return( ucwords( str_replace( [ '.', '-', '_' ], ' ', $key ) ) );
    }

}// TinyMashMediaCapabilityRegistry
