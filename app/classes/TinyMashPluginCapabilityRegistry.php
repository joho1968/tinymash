<?php
namespace app\classes;

class TinyMashPluginCapabilityRegistry {

    protected array $definitions = [];

    public function __construct() {
        $this->registerCapabilityDefinition(
            'routes.public',
            [
                'label' => 'Public routes',
                'description' => 'Adds public route handlers.',
            ]
        );
        $this->registerCapabilityDefinition(
            'routes.admin',
            [
                'label' => 'Admin routes',
                'description' => 'Adds admin route handlers.',
            ]
        );
        $this->registerCapabilityDefinition(
            'admin.navigation',
            [
                'label' => 'Admin navigation',
                'description' => 'Adds links or tools to admin navigation.',
            ]
        );
        $this->registerCapabilityDefinition(
            'settings.system',
            [
                'label' => 'System settings',
                'description' => 'Adds site settings under System.',
            ]
        );
        $this->registerCapabilityDefinition(
            'settings.profile',
            [
                'label' => 'Profile settings',
                'description' => 'Adds author or account settings under Profile.',
            ]
        );
        $this->registerCapabilityDefinition(
            'content.workflow',
            [
                'label' => 'Content workflow',
                'description' => 'Extends drafting, revision, approval, or publishing.',
            ]
        );
        $this->registerCapabilityDefinition(
            'content.shortcodes',
            [
                'label' => 'Content shortcodes',
                'description' => 'Adds Markdown content shortcodes.',
            ]
        );
        $this->registerCapabilityDefinition(
            'media.ingest',
            [
                'label' => 'Media ingest',
                'description' => 'Imports or creates managed media.',
            ]
        );
        $this->registerCapabilityDefinition(
            'media.transform',
            [
                'label' => 'Media transform',
                'description' => 'Adds media transformations or derivatives.',
            ]
        );
        $this->registerCapabilityDefinition(
            'import.content',
            [
                'label' => 'Content import',
                'description' => 'Imports content from another system or format.',
            ]
        );
        $this->registerCapabilityDefinition(
            'export.static',
            [
                'label' => 'Static export',
                'description' => 'Adds public files to static exports.',
            ]
        );
        $this->registerCapabilityDefinition(
            'metadata.public',
            [
                'label' => 'Public metadata',
                'description' => 'Adds validated metadata to public document heads.',
            ]
        );
        $this->registerCapabilityDefinition(
            'theme.slots',
            [
                'label' => 'Theme slots',
                'description' => 'Adds content to public or admin theme slots.',
            ]
        );
        $this->registerCapabilityDefinition(
            'assets.public',
            [
                'label' => 'Public assets',
                'description' => 'Adds local CSS, JavaScript, fonts, or images to public pages.',
            ]
        );
        $this->registerCapabilityDefinition(
            'storage.private',
            [
                'label' => 'Private storage',
                'description' => 'Stores plugin data outside the public web root.',
            ]
        );
        $this->registerCapabilityDefinition(
            'housekeeping',
            [
                'label' => 'Housekeeping',
                'description' => 'Adds scheduled or maintenance work.',
            ]
        );
        $this->registerCapabilityDefinition(
            'cli',
            [
                'label' => 'CLI',
                'description' => 'Adds command-line operations.',
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

}
