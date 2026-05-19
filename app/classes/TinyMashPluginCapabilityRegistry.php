<?php
namespace app\classes;

class TinyMashPluginCapabilityRegistry {

    protected array $definitions = [];

    public function __construct() {
        $this->registerCapabilityDefinition(
            'routes.public',
            [
                'label' => 'Public routes',
                'description' => 'The plugin adds public-facing route handlers.',
            ]
        );
        $this->registerCapabilityDefinition(
            'routes.admin',
            [
                'label' => 'Admin routes',
                'description' => 'The plugin adds admin-facing route handlers.',
            ]
        );
        $this->registerCapabilityDefinition(
            'admin.navigation',
            [
                'label' => 'Admin navigation',
                'description' => 'The plugin contributes entries to admin navigation or section tools.',
            ]
        );
        $this->registerCapabilityDefinition(
            'settings.system',
            [
                'label' => 'System settings',
                'description' => 'The plugin exposes site-level settings under System.',
            ]
        );
        $this->registerCapabilityDefinition(
            'settings.profile',
            [
                'label' => 'Profile settings',
                'description' => 'The plugin exposes author/account settings in Profile.',
            ]
        );
        $this->registerCapabilityDefinition(
            'content.workflow',
            [
                'label' => 'Content workflow',
                'description' => 'The plugin extends drafting, revision, approval, or publishing behavior.',
            ]
        );
        $this->registerCapabilityDefinition(
            'media.ingest',
            [
                'label' => 'Media ingest',
                'description' => 'The plugin imports or creates media objects for the application.',
            ]
        );
        $this->registerCapabilityDefinition(
            'media.transform',
            [
                'label' => 'Media transform',
                'description' => 'The plugin adds media transformation or derivative-generation behavior.',
            ]
        );
        $this->registerCapabilityDefinition(
            'import.content',
            [
                'label' => 'Content import',
                'description' => 'The plugin imports content from another system or format.',
            ]
        );
        $this->registerCapabilityDefinition(
            'theme.slots',
            [
                'label' => 'Theme slots',
                'description' => 'The plugin integrates with explicit public or admin theme slot surfaces.',
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

    public function resolveCapabilities( array $capability_keys ) : array {
        $normalized_keys = $this->normalizeCapabilityKeys( $capability_keys );
        $resolved_capabilities = [];

        foreach ( $normalized_keys as $capability_key ) {
            $resolved_capabilities[] = $this->definitions[$capability_key] ?? [
                'key' => $capability_key,
                'label' => $this->humanizeKey( $capability_key ),
                'description' => '',
            ];
        }

        usort(
            $resolved_capabilities,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $resolved_capabilities );
    }

    public function normalizeCapabilityKeys( array $capability_keys ) : array {
        $normalized_keys = [];
        foreach ( $capability_keys as $capability_key ) {
            if ( ! is_string( $capability_key ) ) {
                continue;
            }

            $normalized_key = $this->normalizeCapabilityKey( $capability_key );
            if ( $normalized_key === '' ) {
                continue;
            }
            $normalized_keys[] = $normalized_key;
        }

        $normalized_keys = array_values( array_unique( $normalized_keys ) );
        sort( $normalized_keys, SORT_STRING );
        return( $normalized_keys );
    }

    protected function normalizeCapabilityKey( string $capability_key ) : string {
        $capability_key = strtolower( trim( $capability_key ) );
        return( preg_replace( '/[^a-z0-9._-]/', '', $capability_key ) ?? '' );
    }

    protected function humanizeKey( string $key ) : string {
        return( ucwords( str_replace( [ '.', '-', '_' ], ' ', $key ) ) );
    }

}// TinyMashPluginCapabilityRegistry
