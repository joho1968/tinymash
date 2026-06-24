<?php
namespace app\classes;

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

use flight\Engine;
use flight\net\Router;

require_once dirname( __FILE__, 2 ) . '/include/defines.inc.php';

class TinyMashPlugins {

    protected const BOOT_STAGES = [ 'before', 'early', 'after', 'late' ];
    protected const PUBLIC_SLOTS = [ 'footer', 'sidebar' ];
    protected const ADMIN_NAV_AREAS = [ 'author', 'admin', 'account' ];
    protected const EXPOSED_SERVICE_KEYS = [
        'config',
        'security',
        'database',
        'content.repository',
        'content.target_picker',
        'draft.repository',
        'user.repository',
        'markdown.renderer',
        'date.formatter',
        'content.renderer',
        'shortcode.registry',
        'menu.service',
        'media.metadata_store',
        'media.import_map_store',
        'media.import_bridge',
        'import.lock_service',
        'media.service',
        'media.capability_registry',
        'media.derivative_registry',
        'theme.registry',
        'theme.support_registry',
        'plugin.capability_registry',
        'theme',
        'admin.theme',
        'help.catalog',
        'export.service',
        'mailer',
    ];

    protected Engine $app;
    protected Router $router;
    protected TinyMashConfig $config;
    protected TinyMashSecurity $security;
    protected ?TinyMashPluginCapabilityRegistry $capability_registry;
    protected array $registered_plugins = [];
    protected array $system_settings_sections = [];
    protected array $profile_settings_sections = [];
    protected array $booted_stages = [];
    protected array $public_slot_renderers = [];
    protected bool $has_dynamic_public_slot_renderer = false;
    protected array $public_entry_renderers = [];
    protected array $public_content_filters = [];
    protected array $public_assets = [];
    protected array $admin_navigation_items = [];
    protected array $admin_configuration_urls = [];
    protected array $cli_commands = [];
    protected array $housekeeping_tasks = [];
    protected array $export_contributors = [];
    protected array $import_contributors = [];
    protected array $media_usage_contributors = [];
    protected array $editor_tabs = [];
    protected array $plugin_directories = [];
    protected string $booting_plugin_key = '';

    public function __construct( Engine $app, Router $router, TinyMashConfig $config, TinyMashSecurity $security, ?TinyMashPluginCapabilityRegistry $capability_registry = null ) {
        $this->app = $app;
        $this->router = $router;
        $this->config = $config;
        $this->security = $security;
        $this->capability_registry = $capability_registry;
    }

    public function discoverDirectory( string $plugins_directory ) : void {
        $this->discoverDirectories( [ $plugins_directory ] );
    }

    public function discoverDirectories( array $plugins_directories ) : void {
        $normalized_directories = [];
        foreach ( $plugins_directories as $plugins_directory ) {
            if ( ! is_string( $plugins_directory ) ) {
                continue;
            }

            $plugins_directory = rtrim( trim( $plugins_directory ), DIRECTORY_SEPARATOR );
            if ( $plugins_directory === '' ) {
                continue;
            }

            $normalized_directories[] = $plugins_directory;
        }

        $this->plugin_directories = array_values( array_unique( $normalized_directories ) );
        $this->registered_plugins = [];
        $this->system_settings_sections = [];
        $this->profile_settings_sections = [];
        $this->booted_stages = [];
        $this->public_slot_renderers = [];
        $this->public_entry_renderers = [];
        $this->public_content_filters = [];
        $this->public_assets = [];
        $this->admin_navigation_items = [];
        $this->admin_configuration_urls = [];
        $this->cli_commands = [];
        $this->housekeeping_tasks = [];
        $this->export_contributors = [];
        $this->import_contributors = [];
        $this->media_usage_contributors = [];
        $this->editor_tabs = [];

        foreach ( $this->plugin_directories as $plugins_directory ) {
            if ( ! is_dir( $plugins_directory ) || ! is_readable( $plugins_directory ) ) {
                continue;
            }

            foreach ( $this->discoverManifestPlugins( $plugins_directory ) as $plugin_definition ) {
                $this->registerPluginDefinition( $plugin_definition );
            }
        }
    }

    public function bootStage( string $stage ) : void {
        $stage = $this->normalizeBootStage( $stage );
        if ( $stage === '' || in_array( $stage, $this->booted_stages, true ) ) {
            return;
        }

        foreach ( array_keys( $this->registered_plugins ) as $plugin_key ) {
            $plugin_definition = $this->registered_plugins[$plugin_key] ?? null;
            if ( ! is_array( $plugin_definition ) ) {
                continue;
            }
            if ( empty( $plugin_definition['active'] ) || ( $plugin_definition['stage'] ?? 'early' ) !== $stage ) {
                continue;
            }
            $this->bootPlugin( $plugin_key );
        }

        $this->booted_stages[] = $stage;
    }

    public function getRegisteredPlugins() : array {
        $plugins = array_map(
            function( array $plugin ) : array {
                return( $this->decoratePluginDiagnostics( $plugin ) );
            },
            array_values( $this->registered_plugins )
        );
        usort(
            $plugins,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['name'] ?? $left['key'] ?? '' ), (string) ( $right['name'] ?? $right['key'] ?? '' ) ) );
            }
        );
        return( $plugins );
    }

    public function getPlugin( string $plugin_key ) : ?array {
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key] ) || ! is_array( $this->registered_plugins[$plugin_key] ) ) {
            return( null );
        }

        return( $this->decoratePluginDiagnostics( $this->registered_plugins[$plugin_key] ) );
    }

    public function getPluginDiagnostics() : array {
        $plugins = $this->getRegisteredPlugins();
        $summary = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'booted' => 0,
            'ready' => 0,
            'error' => 0,
            'warnings' => 0,
        ];
        $errors = [];
        $warnings = [];

        foreach ( $plugins as $plugin ) {
            $summary['total']++;
            if ( ! empty( $plugin['active'] ) ) {
                $summary['active']++;
            } else {
                $summary['inactive']++;
            }

            $status = (string) ( $plugin['boot_status'] ?? '' );
            if ( in_array( $status, [ 'booted', 'ready', 'error' ], true ) ) {
                $summary[$status]++;
            }
            if ( $status === 'error' ) {
                $errors[] = $plugin;
            }
            $plugin_warnings = is_array( $plugin['manifest_warnings'] ?? null ) ? $plugin['manifest_warnings'] : [];
            if ( ! empty( $plugin_warnings ) ) {
                $summary['warnings'] += count( $plugin_warnings );
                $warnings[] = $plugin;
            }
        }

        return(
            [
                'summary' => $summary,
                'plugins' => $plugins,
                'errors' => $errors,
                'warnings' => $warnings,
            ]
        );
    }

    public function registerCapabilityDefinition( string $capability_key, array $definition ) : void {
        if ( $this->capability_registry instanceof TinyMashPluginCapabilityRegistry ) {
            $this->capability_registry->registerCapabilityDefinition( $capability_key, $definition );
        }
    }

    public function getRegisteredPluginCapabilities() : array {
        $capability_index = [];

        foreach ( $this->getRegisteredPlugins() as $plugin ) {
            foreach ( (array) ( $plugin['capability_details'] ?? [] ) as $capability_definition ) {
                if ( ! is_array( $capability_definition ) || empty( $capability_definition['key'] ) ) {
                    continue;
                }
                $capability_key = (string) $capability_definition['key'];
                if ( ! isset( $capability_index[$capability_key] ) ) {
                    $capability_index[$capability_key] = $capability_definition;
                    $capability_index[$capability_key]['plugins'] = [];
                }
                $capability_index[$capability_key]['plugins'][] = [
                    'key' => (string) ( $plugin['key'] ?? '' ),
                    'name' => (string) ( $plugin['name'] ?? $plugin['key'] ?? '' ),
                    'active' => ! empty( $plugin['active'] ),
                    'boot_status' => (string) ( $plugin['boot_status'] ?? '' ),
                ];
            }
        }

        $capabilities = array_values( $capability_index );
        usort(
            $capabilities,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $capabilities );
    }

    public function registerPublicSlotRenderer( string $plugin_key, string $slot, callable $renderer, bool $dynamic = false ) : bool {
        $slot = $this->normalizePublicSlot( $slot );
        if ( $slot === '' || ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        if ( empty( $this->public_slot_renderers[$slot] ) || ! is_array( $this->public_slot_renderers[$slot] ) ) {
            $this->public_slot_renderers[$slot] = [];
        }
        $this->public_slot_renderers[$slot][] = [
            'plugin_key' => $this->canonicalizePluginKey( $plugin_key ),
            'renderer' => $renderer,
            'dynamic' => $dynamic,
        ];
        if ( $dynamic ) {
            $this->has_dynamic_public_slot_renderer = true;
        }

        return( true );
    }

    public function hasDynamicPublicSlotRenderer() : bool {
        return( $this->has_dynamic_public_slot_renderer );
    }

    public function registerPublicEntryRenderer( string $plugin_key, callable $renderer ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( false );
        }

        $this->public_entry_renderers[] = [
            'plugin_key' => $plugin_key,
            'renderer' => $renderer,
        ];

        return( true );
    }

    public function registerPublicContentFilter( string $plugin_key, callable $filter ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( false );
        }

        $this->public_content_filters[] = [
            'plugin_key' => $plugin_key,
            'filter' => $filter,
        ];

        return( true );
    }

    public function registerMediaUsageContributor( string $plugin_key, callable $contributor ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( false );
        }

        $this->media_usage_contributors[] = [
            'plugin_key' => $plugin_key,
            'contributor' => $contributor,
        ];
        return( true );
    }

    public function collectMediaUsageReferences() : array {
        $references = [];
        foreach ( $this->media_usage_contributors as $contributor ) {
            if ( ! is_array( $contributor ) || empty( $contributor['contributor'] ) || ! is_callable( $contributor['contributor'] ) ) {
                continue;
            }
            $plugin_key = (string) ( $contributor['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }
            try {
                $plugin_references = call_user_func( $contributor['contributor'] );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Media usage contributor failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                continue;
            }
            if ( ! is_array( $plugin_references ) ) {
                continue;
            }
            foreach ( $plugin_references as $reference ) {
                if ( ! is_array( $reference ) || trim( (string) ( $reference['media_id'] ?? '' ) ) === '' ) {
                    continue;
                }
                $reference['plugin_key'] = $plugin_key;
                $references[] = $reference;
            }
        }

        return( $references );
    }

    public function registerShortcode( string $plugin_key, string $name, callable $handler, array $definition = [] ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $shortcode_registry = $this->getService( 'shortcode.registry' );
        if ( ! $shortcode_registry instanceof TinyMashShortcodeRegistry ) {
            return( false );
        }

        return( $shortcode_registry->registerShortcode( $this->canonicalizePluginKey( $plugin_key ), $name, $handler, $definition ) );
    }

    public function getRegisteredShortcodes() : array {
        $shortcode_registry = $this->getService( 'shortcode.registry' );
        if ( ! $shortcode_registry instanceof TinyMashShortcodeRegistry ) {
            return( [] );
        }

        return( $shortcode_registry->getRegisteredShortcodes() );
    }

    public function registerPublicAsset( string $plugin_key, string $type, string $url ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $type = strtolower( trim( $type ) );
        $url = $this->normalizePublicAssetUrl( $url );
        if ( $plugin_key === '' || ! in_array( $type, [ 'css', 'js' ], true ) || $url === '' ) {
            return( false );
        }

        if ( empty( $this->public_assets[$type] ) || ! is_array( $this->public_assets[$type] ) ) {
            $this->public_assets[$type] = [];
        }
        $this->public_assets[$type][] = [
            'plugin_key' => $plugin_key,
            'url' => $url,
        ];

        return( true );
    }

    public function registerAdminNavigationItem( string $plugin_key, string $area, array $definition ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $area = $this->normalizeAdminNavigationArea( $area );
        if ( $plugin_key === '' || $area === '' ) {
            return( false );
        }

        $section = $this->normalizePluginKey( (string) ( $definition['section'] ?? '' ) );
        $label = trim( (string) ( $definition['label'] ?? '' ) );
        $url = trim( (string) ( $definition['url'] ?? '' ) );
        if ( $section === '' || $label === '' || $url === '' ) {
            return( false );
        }

        $group = $this->normalizePluginKey( (string) ( $definition['group'] ?? '' ) );
        $group_label = trim( (string) ( $definition['group_label'] ?? '' ) );

        if ( empty( $this->admin_navigation_items[$area] ) || ! is_array( $this->admin_navigation_items[$area] ) ) {
            $this->admin_navigation_items[$area] = [];
        }

        $this->admin_navigation_items[$area][] = [
            'plugin_key' => $plugin_key,
            'area' => $area,
            'section' => $section,
            'label' => $label,
            'url' => $url,
            'icon' => trim( (string) ( $definition['icon'] ?? 'bi-journal-text' ) ),
            'order' => (int) ( $definition['order'] ?? 100 ),
            'group' => $group,
            'group_label' => $group_label !== '' ? $group_label : ( $group !== '' ? ucfirst( str_replace( '-', ' ', $group ) ) : '' ),
        ];

        return( true );
    }

    public function registerAdminConfigurationUrl( string $plugin_key, string $url ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $url = $this->normalizePublicAssetUrl( $url );
        if ( $plugin_key === '' || $url === '' ) {
            return( false );
        }

        $this->admin_configuration_urls[$plugin_key] = $url;
        return( true );
    }

    public function getAdminConfigurationUrls() : array {
        $urls = [];
        foreach ( $this->admin_configuration_urls as $plugin_key => $url ) {
            if ( empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }
            $urls[$plugin_key] = $url;
        }
        return( $urls );
    }

    public function registerHelpDocument( string $plugin_key, string $context, array $document ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $context = strtolower( trim( $context ) );
        $context = preg_replace( '/[^a-z0-9-]/', '', $context ) ?? '';
        if ( $context === '' ) {
            return( false );
        }

        $help_catalog = $this->app->has( 'help.catalog' ) ? $this->app->get( 'help.catalog' ) : null;
        if ( ! $help_catalog instanceof TinyMashHelpCatalog ) {
            return( false );
        }

        if ( ! isset( $document['group'] ) || trim( (string) $document['group'] ) === '' ) {
            $document['group'] = 'Plugins';
        }

        return( $help_catalog->registerDocument( $context, $document ) );
    }

    public function getAdminNavigationItems( string $area = '' ) : array {
        $areas = $area !== '' ? [ $this->normalizeAdminNavigationArea( $area ) ] : self::ADMIN_NAV_AREAS;
        $navigation_items = [];

        foreach ( $areas as $current_area ) {
            if ( $current_area === '' || empty( $this->admin_navigation_items[$current_area] ) || ! is_array( $this->admin_navigation_items[$current_area] ) ) {
                continue;
            }

            foreach ( $this->admin_navigation_items[$current_area] as $navigation_item ) {
                if ( ! is_array( $navigation_item ) ) {
                    continue;
                }

                $plugin_key = (string) ( $navigation_item['plugin_key'] ?? '' );
                if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                    continue;
                }

                $navigation_items[] = $navigation_item;
            }
        }

        usort(
            $navigation_items,
            static function( array $left, array $right ) : int {
                $order_compare = (int) ( $left['order'] ?? 100 ) <=> (int) ( $right['order'] ?? 100 );
                if ( $order_compare !== 0 ) {
                    return( $order_compare );
                }

                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $navigation_items );
    }

    public function registerHousekeepingTask( string $plugin_key, callable $task, string $task_key = '', string $label = '' ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $task_key = $this->normalizePluginKey( $task_key !== '' ? $task_key : $plugin_key );
        if ( $plugin_key === '' || $task_key === '' ) {
            return( false );
        }

        $this->housekeeping_tasks[] = [
            'plugin_key' => $plugin_key,
            'task_key' => $task_key,
            'label' => trim( $label ) !== '' ? trim( $label ) : ( $this->registered_plugins[$plugin_key]['name'] ?? $plugin_key ),
            'task' => $task,
        ];

        return( true );
    }

    public function registerCliCommand( string $plugin_key, array $definition ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $command = $this->normalizePluginKey( (string) ( $definition['command'] ?? '' ) );
        $handler = $definition['handler'] ?? null;
        if ( $plugin_key === '' || $command === '' || ! is_callable( $handler ) ) {
            return( false );
        }

        $this->cli_commands[$command] = [
            'plugin_key' => $plugin_key,
            'command' => $command,
            'usage' => trim( (string) ( $definition['usage'] ?? '' ) ),
            'summary' => trim( (string) ( $definition['summary'] ?? '' ) ),
            'order' => (int) ( $definition['order'] ?? 100 ),
            'handler' => $handler,
        ];

        return( true );
    }

    public function getCliCommands() : array {
        if ( empty( $this->cli_commands ) ) {
            return( [] );
        }

        $commands = [];
        foreach ( $this->cli_commands as $cli_command ) {
            if ( ! is_array( $cli_command ) || empty( $cli_command['handler'] ) || ! is_callable( $cli_command['handler'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $cli_command['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            $commands[] = $cli_command;
        }

        usort(
            $commands,
            static function( array $left, array $right ) : int {
                $order_compare = (int) ( $left['order'] ?? 100 ) <=> (int) ( $right['order'] ?? 100 );
                if ( $order_compare !== 0 ) {
                    return( $order_compare );
                }

                return( strcmp( (string) ( $left['command'] ?? '' ), (string) ( $right['command'] ?? '' ) ) );
            }
        );

        return( $commands );
    }

    public function runCliCommand( string $command, array $arguments = [] ) : bool {
        $command = $this->normalizePluginKey( $command );
        if ( $command === '' ) {
            return( false );
        }

        $cli_command = $this->cli_commands[$command] ?? null;
        if ( ! is_array( $cli_command ) || empty( $cli_command['handler'] ) || ! is_callable( $cli_command['handler'] ) ) {
            return( false );
        }

        $plugin_key = (string) ( $cli_command['plugin_key'] ?? '' );
        if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
            return( false );
        }

        call_user_func( $cli_command['handler'], $arguments );
        return( true );
    }

    public function registerExportContributor( string $plugin_key, callable $contributor, string $scope = 'both', string $label = '' ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $scope = $this->normalizeExportScope( $scope );
        if ( $plugin_key === '' || $scope === '' ) {
            return( false );
        }

        $this->export_contributors[] = [
            'plugin_key' => $plugin_key,
            'scope' => $scope,
            'label' => trim( $label ) !== '' ? trim( $label ) : ( $this->registered_plugins[$plugin_key]['name'] ?? $plugin_key ),
            'contributor' => $contributor,
        ];

        return( true );
    }

    public function registerImportContributor( string $plugin_key, callable $contributor, string $scope = 'both', string $label = '' ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $scope = $this->normalizeExportScope( $scope );
        if ( $plugin_key === '' || $scope === '' ) {
            return( false );
        }

        $this->import_contributors[] = [
            'plugin_key' => $plugin_key,
            'scope' => $scope,
            'label' => trim( $label ) !== '' ? trim( $label ) : ( $this->registered_plugins[$plugin_key]['name'] ?? $plugin_key ),
            'contributor' => $contributor,
        ];

        return( true );
    }

    public function registerSystemSettingsSection( string $plugin_key, array $definition ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $section_definition = $this->normalizeSettingsSectionDefinition( $plugin_key, $definition );
        if ( $section_definition === null ) {
            return( false );
        }

        $this->system_settings_sections[$plugin_key] = $section_definition;
        return( true );
    }

    public function registerProfileSettingsSection( string $plugin_key, string $section, array $definition ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $section = strtolower( trim( $section ) );
        if ( ! in_array( $section, [ 'account', 'editor', 'publishing', 'appearance', 'fediverse' ], true ) ) {
            return( false );
        }

        $section_definition = $this->normalizeSettingsSectionDefinition( $plugin_key, $definition );
        if ( $section_definition === null ) {
            return( false );
        }

        if ( empty( $this->profile_settings_sections[$section] ) || ! is_array( $this->profile_settings_sections[$section] ) ) {
            $this->profile_settings_sections[$section] = [];
        }
        $this->profile_settings_sections[$section][$plugin_key] = $section_definition;
        return( true );
    }

    public function registerEditorTab( string $plugin_key, array $definition ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        $tab_key = $this->normalizePluginKey( (string) ( $definition['key'] ?? $plugin_key ) );
        $label = trim( (string) ( $definition['label'] ?? '' ) );
        $renderer = $definition['renderer'] ?? null;
        if ( $plugin_key === '' || $tab_key === '' || $label === '' || ! is_callable( $renderer ) ) {
            return( false );
        }

        $roles = [];
        foreach ( (array) ( $definition['roles'] ?? [ 'author', 'admin' ] ) as $role ) {
            if ( ! is_string( $role ) ) {
                continue;
            }

            $role = strtolower( trim( $role ) );
            if ( ! in_array( $role, [ 'author', 'admin' ], true ) || in_array( $role, $roles, true ) ) {
                continue;
            }

            $roles[] = $role;
        }
        if ( empty( $roles ) ) {
            $roles = [ 'author', 'admin' ];
        }

        $this->editor_tabs[$plugin_key] = [
            'plugin_key' => $plugin_key,
            'key' => $tab_key,
            'label' => $label,
            'order' => (int) ( $definition['order'] ?? 100 ),
            'roles' => $roles,
            'renderer' => $renderer,
        ];

        return( true );
    }

    public function getSystemSettingsSections() : array {
        return( array_values( $this->system_settings_sections ) );
    }

    public function getProfileSettingsSections( string $section ) : array {
        $section = strtolower( trim( $section ) );
        if ( empty( $this->profile_settings_sections[$section] ) || ! is_array( $this->profile_settings_sections[$section] ) ) {
            return( [] );
        }

        return( array_values( $this->profile_settings_sections[$section] ) );
    }

    public function getEditorTabs( array $context = [] ) : array {
        if ( empty( $this->editor_tabs ) ) {
            return( [] );
        }

        $requested_role = strtolower( trim( (string) ( $context['role'] ?? 'author' ) ) );
        if ( ! in_array( $requested_role, [ 'author', 'admin' ], true ) ) {
            $requested_role = 'author';
        }

        $tabs = [];
        foreach ( $this->editor_tabs as $editor_tab ) {
            if ( ! is_array( $editor_tab ) || empty( $editor_tab['renderer'] ) || ! is_callable( $editor_tab['renderer'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $editor_tab['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            $roles = is_array( $editor_tab['roles'] ?? null ) ? $editor_tab['roles'] : [ 'author', 'admin' ];
            if ( ! in_array( $requested_role, $roles, true ) ) {
                continue;
            }

            try {
                $html = call_user_func( $editor_tab['renderer'], $context );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Editor tab renderer failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                continue;
            }

            if ( ! is_string( $html ) || trim( $html ) === '' ) {
                continue;
            }

            $tabs[] = [
                'plugin_key' => $plugin_key,
                'key' => (string) ( $editor_tab['key'] ?? $plugin_key ),
                'label' => (string) ( $editor_tab['label'] ?? ucfirst( $plugin_key ) ),
                'order' => (int) ( $editor_tab['order'] ?? 100 ),
                'html' => $html,
            ];
        }

        usort(
            $tabs,
            static function( array $left, array $right ) : int {
                $order_compare = (int) ( $left['order'] ?? 100 ) <=> (int) ( $right['order'] ?? 100 );
                if ( $order_compare !== 0 ) {
                    return( $order_compare );
                }

                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $tabs );
    }

    public function renderPublicSlot( string $slot, array $context = [] ) : array {
        $slot = $this->normalizePublicSlot( $slot );
        if ( $slot === '' || empty( $this->public_slot_renderers[$slot] ) ) {
            return( [] );
        }

        $fragments = [];
        foreach ( $this->public_slot_renderers[$slot] as $slot_renderer ) {
            if ( ! is_array( $slot_renderer ) || empty( $slot_renderer['renderer'] ) || ! is_callable( $slot_renderer['renderer'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $slot_renderer['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) ) {
                continue;
            }

            try {
                $rendered_fragment = call_user_func( $slot_renderer['renderer'], $context );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Public slot renderer failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                continue;
            }

            $fragment = $this->normalizePublicSlotFragment( $plugin_key, $rendered_fragment );
            if ( empty( $fragment ) ) {
                continue;
            }

            $fragments[] = $fragment;
        }

        return( $fragments );
    }

    public function renderPublicEntryFragments( array $entry, array $context = [] ) : array {
        if ( empty( $this->public_entry_renderers ) ) {
            return( [] );
        }

        $fragments = [
            'before_content_html' => '',
            'after_content_html' => '',
        ];
        foreach ( $this->public_entry_renderers as $entry_renderer ) {
            if ( ! is_array( $entry_renderer ) || empty( $entry_renderer['renderer'] ) || ! is_callable( $entry_renderer['renderer'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $entry_renderer['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            try {
                $rendered_fragment = call_user_func( $entry_renderer['renderer'], $entry, $context );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Public entry renderer failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                continue;
            }

            $fragment = $this->normalizePublicEntryFragment( $rendered_fragment );
            if ( $fragment['before_content_html'] !== '' ) {
                $fragments['before_content_html'] .= $fragment['before_content_html'];
            }
            if ( $fragment['after_content_html'] !== '' ) {
                $fragments['after_content_html'] .= $fragment['after_content_html'];
            }
        }

        return( array_filter(
            $fragments,
            static function( string $html ) : bool {
                return( $html !== '' );
            }
        ) );
    }

    public function filterPublicContentHtml( string $html, array $entry, array $context = [] ) : string {
        if ( $html === '' || empty( $this->public_content_filters ) ) {
            return( $html );
        }

        foreach ( $this->public_content_filters as $content_filter ) {
            if ( ! is_array( $content_filter ) || empty( $content_filter['filter'] ) || ! is_callable( $content_filter['filter'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $content_filter['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            try {
                $filtered_html = call_user_func( $content_filter['filter'], $html, $entry, $context );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Public content filter failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                continue;
            }

            if ( is_string( $filtered_html ) ) {
                $html = $filtered_html;
            }
        }

        return( $html );
    }

    public function getPublicAssetUrls( string $type ) : array {
        $type = strtolower( trim( $type ) );
        if ( ! in_array( $type, [ 'css', 'js' ], true ) || empty( $this->public_assets[$type] ) || ! is_array( $this->public_assets[$type] ) ) {
            return( [] );
        }

        $urls = [];
        foreach ( $this->public_assets[$type] as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }

            $plugin_key = (string) ( $asset['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            $url = $this->normalizePublicAssetUrl( (string) ( $asset['url'] ?? '' ) );
            if ( $url !== '' && ! in_array( $url, $urls, true ) ) {
                $urls[] = $this->appendPublicAssetVersion( $url );
            }
        }

        return( $urls );
    }

    protected function normalizePublicSlotFragment( string $plugin_key, mixed $rendered_fragment ) : array {
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        if ( $plugin_key === '' ) {
            return( [] );
        }

        if ( is_string( $rendered_fragment ) ) {
            $html = trim( $rendered_fragment );
            if ( $html === '' ) {
                return( [] );
            }

            return(
                [
                    'plugin_key' => $plugin_key,
                    'html' => $html,
                ]
            );
        }

        if ( ! is_array( $rendered_fragment ) ) {
            return( [] );
        }

        $fragment = [ 'plugin_key' => $plugin_key ];
        $html = trim( (string) ( $rendered_fragment['html'] ?? '' ) );
        if ( $html !== '' ) {
            $fragment['html'] = $html;
        }

        $lines = [];
        foreach ( is_array( $rendered_fragment['lines'] ?? null ) ? $rendered_fragment['lines'] : [] as $line ) {
            $line = trim( (string) $line );
            if ( $line !== '' ) {
                $lines[] = $line;
            }
        }
        if ( ! empty( $lines ) ) {
            $fragment['lines'] = array_values( $lines );
        }

        return( count( $fragment ) > 1 ? $fragment : [] );
    }

    protected function normalizePublicEntryFragment( mixed $rendered_fragment ) : array {
        $fragment = [
            'before_content_html' => '',
            'after_content_html' => '',
        ];

        if ( is_string( $rendered_fragment ) ) {
            $html = trim( $rendered_fragment );
            if ( $html !== '' ) {
                $fragment['after_content_html'] = $html;
            }
            return( $fragment );
        }

        if ( ! is_array( $rendered_fragment ) ) {
            return( $fragment );
        }

        $before_content_html = trim( (string) ( $rendered_fragment['before_content_html'] ?? '' ) );
        if ( $before_content_html !== '' ) {
            $fragment['before_content_html'] = $before_content_html;
        }

        $after_content_html = trim( (string) ( $rendered_fragment['after_content_html'] ?? '' ) );
        if ( $after_content_html !== '' ) {
            $fragment['after_content_html'] = $after_content_html;
        }

        $html = trim( (string) ( $rendered_fragment['html'] ?? '' ) );
        if ( $html !== '' ) {
            $position = strtolower( trim( (string) ( $rendered_fragment['position'] ?? 'after_content' ) ) );
            if ( $position === 'before_content' ) {
                $fragment['before_content_html'] .= $html;
            } else {
                $fragment['after_content_html'] .= $html;
            }
        }

        return( $fragment );
    }

    protected function normalizePublicAssetUrl( string $url ) : string {
        $url = trim( $url );
        if ( $url === '' || ! str_starts_with( $url, '/' ) || str_starts_with( $url, '//' ) || str_contains( $url, "\0" ) || str_contains( $url, '..' ) ) {
            return( '' );
        }

        return( $url );
    }

    protected function appendPublicAssetVersion( string $url ) : string {
        $url_parts = parse_url( $url );
        if ( ! is_array( $url_parts ) ) {
            return( $url );
        }

        $path = trim( (string) ( $url_parts['path'] ?? '' ) );
        if ( $path === '' || ! str_starts_with( $path, '/' ) || str_contains( $path, '..' ) ) {
            return( $url );
        }

        $public_root = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'public';
        $asset_filename = $public_root . str_replace( '/', DIRECTORY_SEPARATOR, $path );
        if ( ! is_file( $asset_filename ) ) {
            return( $url );
        }

        $version = (string) @ filemtime( $asset_filename );
        if ( $version === '' || $version === '0' ) {
            return( $url );
        }

        $separator = str_contains( $url, '?' ) ? '&' : '?';
        return( $url . $separator . 'v=' . rawurlencode( $version ) );
    }

    public function runHousekeepingTasks( array $context = [] ) : array {
        if ( empty( $this->housekeeping_tasks ) ) {
            return( [] );
        }

        $results = [];
        foreach ( $this->housekeeping_tasks as $housekeeping_task ) {
            if ( ! is_array( $housekeeping_task ) || empty( $housekeeping_task['task'] ) || ! is_callable( $housekeeping_task['task'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $housekeeping_task['plugin_key'] ?? '' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }

            try {
                $task_result = call_user_func( $housekeeping_task['task'], $context );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'task_key' => (string) ( $housekeeping_task['task_key'] ?? $plugin_key ),
                    'label' => (string) ( $housekeeping_task['label'] ?? $plugin_key ),
                    'status' => 'ran',
                    'result' => $task_result,
                    'message' => is_string( $task_result ) ? trim( $task_result ) : '',
                ];
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Housekeeping task failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'task_key' => (string) ( $housekeeping_task['task_key'] ?? $plugin_key ),
                    'label' => (string) ( $housekeeping_task['label'] ?? $plugin_key ),
                    'status' => 'error',
                    'result' => null,
                    'message' => trim( $e->getMessage() ),
                ];
            }
        }

        return( $results );
    }

    public function runExportContributors( string $scope, array $context = [] ) : array {
        $scope = $this->normalizeExportScope( $scope );
        if ( $scope === '' || empty( $this->export_contributors ) ) {
            return( [] );
        }

        $results = [];
        foreach ( $this->export_contributors as $export_contributor ) {
            if ( ! is_array( $export_contributor ) || empty( $export_contributor['contributor'] ) || ! is_callable( $export_contributor['contributor'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $export_contributor['plugin_key'] ?? '' );
            $contributor_scope = (string) ( $export_contributor['scope'] ?? 'both' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }
            if ( $contributor_scope !== 'both' && $contributor_scope !== $scope ) {
                continue;
            }

            try {
                $result = call_user_func( $export_contributor['contributor'], $context );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'label' => (string) ( $export_contributor['label'] ?? $plugin_key ),
                    'scope' => $contributor_scope,
                    'status' => 'ran',
                    'result' => $result,
                    'message' => is_string( $result ) ? trim( $result ) : '',
                ];
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Export contributor failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'label' => (string) ( $export_contributor['label'] ?? $plugin_key ),
                    'scope' => $contributor_scope,
                    'status' => 'error',
                    'result' => null,
                    'message' => trim( $e->getMessage() ),
                ];
            }
        }

        return( $results );
    }

    public function runImportContributors( string $scope, array $context = [] ) : array {
        $scope = $this->normalizeExportScope( $scope );
        if ( $scope === '' || empty( $this->import_contributors ) ) {
            return( [] );
        }

        $results = [];
        foreach ( $this->import_contributors as $import_contributor ) {
            if ( ! is_array( $import_contributor ) || empty( $import_contributor['contributor'] ) || ! is_callable( $import_contributor['contributor'] ) ) {
                continue;
            }

            $plugin_key = (string) ( $import_contributor['plugin_key'] ?? '' );
            $contributor_scope = (string) ( $import_contributor['scope'] ?? 'both' );
            if ( $plugin_key === '' || empty( $this->registered_plugins[$plugin_key]['active'] ) || empty( $this->registered_plugins[$plugin_key]['booted'] ) ) {
                continue;
            }
            if ( $contributor_scope !== 'both' && $contributor_scope !== $scope ) {
                continue;
            }

            try {
                $result = call_user_func( $import_contributor['contributor'], $context );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'label' => (string) ( $import_contributor['label'] ?? $plugin_key ),
                    'scope' => $contributor_scope,
                    'status' => 'ran',
                    'result' => $result,
                    'message' => is_string( $result ) ? trim( $result ) : '',
                ];
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Import contributor failed for "' . $plugin_key . '" (' . $e->getMessage() . ')' );
                $results[] = [
                    'plugin_key' => $plugin_key,
                    'label' => (string) ( $import_contributor['label'] ?? $plugin_key ),
                    'scope' => $contributor_scope,
                    'status' => 'error',
                    'result' => null,
                    'message' => trim( $e->getMessage() ),
                ];
            }
        }

        return( $results );
    }

    public function registerRoute( string $plugin_key, string $method, string $route, callable $handler ) : bool {
        if ( ! $this->canRegisterForCurrentPlugin( $plugin_key ) ) {
            return( false );
        }

        $method = strtolower( trim( $method ) );
        $route = trim( $route );
        if ( $route === '' || ! in_array( $method, [ 'get', 'post', 'put', 'patch', 'delete', 'options' ], true ) || ! method_exists( $this->router, $method ) ) {
            return( false );
        }

        $this->router->$method( $route, $handler );
        return( true );
    }

    public function getVisitorContext() : array {
        if ( method_exists( $this->security, 'getPassiveVisitorContext' ) ) {
            return( $this->security->getPassiveVisitorContext() );
        }

        return( $this->security->getVisitorContext() );
    }

    public function isCliRuntime() : bool {
        return( PHP_SAPI === 'cli' );
    }

    public function getPluginSystemSettings( string $plugin_key ) : array {
        return( $this->config->getPluginSettings( $plugin_key ) );
    }

    public function getRequestData() : array {
        $data = $this->app->request()->data->getData();
        return( is_array( $data ) ? $data : [] );
    }

    public function getRequestQueryData() : array {
        $query = $this->app->request()->query->getData();
        return( is_array( $query ) ? $query : [] );
    }

    public function redirect( string $url ) : void {
        $this->app->redirect( $url );
    }

    public function setResponseStatus( int $status_code ) : void {
        $this->app->response()->status( $status_code );
    }

    public function renderAdminPage( array $data = [] ) : void {
        $admin_theme = $this->getService( 'admin.theme' );
        $view_data = $this->buildAdminViewData( $data );

        if ( is_object( $admin_theme ) && method_exists( $admin_theme, 'getTemplate' ) && method_exists( $admin_theme, 'getBaseViewData' ) ) {
            $theme_view_data = $admin_theme->getBaseViewData();
            if ( is_array( $theme_view_data ) ) {
                $view_data = array_merge( $theme_view_data, $view_data );
            }
            $this->app->view()->render( (string) $admin_theme->getTemplate( 'plugin-page' ), $view_data );
            return;
        }

        $this->app->view()->render( 'admin/baseline/plugin-page.latte', $view_data );
    }

    public function hasService( string $service_key ) : bool {
        $service_key = trim( $service_key );
        if ( ! in_array( $service_key, self::EXPOSED_SERVICE_KEYS, true ) ) {
            return( false );
        }
        if ( $service_key === 'config' || $service_key === 'security' ) {
            return( true );
        }

        return( $this->app->has( $service_key ) );
    }

    public function getService( string $service_key ) : mixed {
        if ( ! $this->hasService( $service_key ) ) {
            return( null );
        }
        if ( $service_key === 'config' ) {
            return( $this->config );
        }
        if ( $service_key === 'security' ) {
            return( $this->security );
        }

        return( $this->app->get( $service_key ) );
    }

    protected function registerPluginDefinition( array $plugin_definition ) : void {
        $plugin_key = $this->canonicalizePluginKey( (string) ( $plugin_definition['key'] ?? '' ) );
        if ( $plugin_key === '' ) {
            return;
        }
        if ( isset( $this->registered_plugins[$plugin_key] ) ) {
            return;
        }

        $plugin_definition['key'] = $plugin_key;
        $plugin_definition['capabilities'] = $this->capability_registry instanceof TinyMashPluginCapabilityRegistry
            ? $this->capability_registry->normalizeCapabilityKeys( (array) ( $plugin_definition['capabilities'] ?? [] ) )
            : $this->normalizePluginStringList( (array) ( $plugin_definition['capabilities'] ?? [] ) );
        $plugin_definition['capability_details'] = $this->capability_registry instanceof TinyMashPluginCapabilityRegistry
            ? $this->capability_registry->resolveCapabilities( (array) $plugin_definition['capabilities'] )
            : [];
        $plugin_definition['dependencies'] = $this->normalizePluginStringList( (array) ( $plugin_definition['dependencies'] ?? [] ) );
        $plugin_definition['stage'] = $this->normalizeBootStage( (string) ( $plugin_definition['stage'] ?? 'early' ) ) ?: 'early';
        $plugin_definition['default_active'] = ! empty( $plugin_definition['default_active'] );
        $plugin_definition['active'] = $this->config->isPluginActive( $plugin_key, (bool) $plugin_definition['default_active'] );
        $plugin_definition['source'] = (string) ( $plugin_definition['source'] ?? 'manifest' );
        $plugin_definition['boot_status'] = ! empty( $plugin_definition['active'] ) ? 'ready' : 'inactive';
        $plugin_definition['boot_error'] = '';
        $plugin_definition['booted'] = false;
        $plugin_definition['booted_stage'] = '';
        $this->registered_plugins[$plugin_key] = $plugin_definition;
    }

    protected function bootPlugin( string $plugin_key ) : void {
        if ( empty( $this->registered_plugins[$plugin_key] ) || ! is_array( $this->registered_plugins[$plugin_key] ) ) {
            return;
        }

        $plugin_definition = $this->registered_plugins[$plugin_key];
        if ( empty( $plugin_definition['active'] ) || ! empty( $plugin_definition['booted'] ) ) {
            return;
        }

        $bootstrap_path = (string) ( $plugin_definition['bootstrap_path'] ?? '' );
        if ( $bootstrap_path === '' ) {
            $this->markPluginBootError( $plugin_key, 'Missing bootstrap file.' );
            return;
        }
        if ( ! $this->canLoadPluginFile( $bootstrap_path ) ) {
            $this->markPluginBootError( $plugin_key, 'Bootstrap file is not readable.' );
            return;
        }

        try {
            $plugin_bootstrap = require( $bootstrap_path );
        } catch ( \Throwable $e ) {
            $this->markPluginBootError( $plugin_key, 'Bootstrap include failed: ' . $e->getMessage() );
            return;
        }

        if ( ! is_callable( $plugin_bootstrap ) ) {
            $this->markPluginBootError( $plugin_key, 'Bootstrap file must return a callable.' );
            return;
        }

        $this->booting_plugin_key = $plugin_key;
        try {
            call_user_func( $plugin_bootstrap, $this, $plugin_definition );
            $this->registered_plugins[$plugin_key]['booted'] = true;
            $this->registered_plugins[$plugin_key]['boot_status'] = 'booted';
            $this->registered_plugins[$plugin_key]['booted_stage'] = (string) ( $plugin_definition['stage'] ?? 'early' );
            $this->registered_plugins[$plugin_key]['boot_error'] = '';
        } catch ( \Throwable $e ) {
            $this->markPluginBootError( $plugin_key, $e->getMessage() );
        } finally {
            $this->booting_plugin_key = '';
        }
    }

    protected function markPluginBootError( string $plugin_key, string $message ) : void {
        if ( empty( $this->registered_plugins[$plugin_key] ) || ! is_array( $this->registered_plugins[$plugin_key] ) ) {
            return;
        }

        $this->registered_plugins[$plugin_key]['booted'] = false;
        $this->registered_plugins[$plugin_key]['boot_status'] = 'error';
        $this->registered_plugins[$plugin_key]['boot_error'] = trim( $message );
        error_log( basename( __FILE__ ) . ': Plugin "' . $plugin_key . '" failed to boot (' . trim( $message ) . ')' );
    }

    protected function decoratePluginDiagnostics( array $plugin ) : array {
        $status = strtolower( trim( (string) ( $plugin['boot_status'] ?? '' ) ) );
        if ( $status === '' ) {
            $status = ! empty( $plugin['active'] ) ? 'ready' : 'inactive';
        }

        $plugin['boot_status'] = $status;
        $plugin['boot_status_label'] = match ( $status ) {
            'booted' => 'Booted',
            'ready' => 'Ready',
            'error' => 'Error',
            'inactive' => 'Inactive',
            default => 'Unknown',
        };
        $plugin['boot_status_badge_class'] = match ( $status ) {
            'booted' => 'text-bg-success',
            'ready' => 'text-bg-info',
            'error' => 'text-bg-danger',
            'inactive' => 'text-bg-secondary',
            default => 'text-bg-warning',
        };
        $plugin['boot_status_cli'] = match ( $status ) {
            'booted' => 'booted',
            'ready' => 'ready',
            'error' => 'error',
            'inactive' => 'inactive',
            default => 'unknown',
        };

        return( $plugin );
    }

    protected function canRegisterForCurrentPlugin( string $plugin_key ) : bool {
        $plugin_key = $this->canonicalizePluginKey( $plugin_key );
        return( $plugin_key !== '' && $plugin_key === $this->booting_plugin_key );
    }

    protected function discoverManifestPlugins( string $plugins_directory ) : array {
        $plugin_definitions = [];
        $plugin_directories = glob( $plugins_directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $plugin_directories ) ) {
            return( [] );
        }

        sort( $plugin_directories, SORT_STRING );
        foreach ( $plugin_directories as $plugin_directory ) {
            $manifest_filename = $plugin_directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if ( ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename ) ) {
                continue;
            }

            $plugin_definition = $this->loadPluginManifest( $manifest_filename, basename( $plugin_directory ) );
            if ( $plugin_definition === null ) {
                continue;
            }

            $plugin_definitions[] = $plugin_definition;
        }

        return( $plugin_definitions );
    }

    protected function loadPluginManifest( string $manifest_filename, string $fallback_key ) : ?array {
        $manifest_json = file_get_contents( $manifest_filename );
        if ( ! is_string( $manifest_json ) || trim( $manifest_json ) === '' ) {
            return( null );
        }

        $manifest = json_decode( $manifest_json, true, 16 );
        if ( ! is_array( $manifest ) ) {
            error_log( basename( __FILE__ ) . ': Skipping plugin with invalid manifest "' . basename( dirname( $manifest_filename ) ) . '"' );
            return( null );
        }

        $plugin_key = $this->canonicalizePluginKey( (string) ( $manifest['key'] ?? $fallback_key ) );
        if ( $plugin_key === '' ) {
            return( null );
        }

        $plugin_directory = dirname( $manifest_filename );
        $bootstrap_relative = $this->normalizePluginRelativePath( (string) ( $manifest['bootstrap'] ?? 'bootstrap.php' ) );
        $bootstrap_path = $bootstrap_relative !== '' ? $plugin_directory . DIRECTORY_SEPARATOR . $bootstrap_relative : '';

        return(
            [
                'key' => $plugin_key,
                'name' => trim( (string) ( $manifest['name'] ?? ucwords( str_replace( [ '-', '_' ], ' ', $plugin_key ) ) ) ),
                'version' => trim( (string) ( $manifest['version'] ?? '' ) ),
                'description' => trim( (string) ( $manifest['description'] ?? '' ) ),
                'author_name' => trim( (string) ( $manifest['author_name'] ?? '' ) ),
                'author_email' => trim( (string) ( $manifest['author_email'] ?? '' ) ),
                'website' => trim( (string) ( $manifest['website'] ?? '' ) ),
                'license' => trim( (string) ( $manifest['license'] ?? '' ) ),
                'capabilities' => $this->normalizePluginStringList( (array) ( $manifest['capabilities'] ?? [] ) ),
                'dependencies' => $this->normalizePluginStringList( (array) ( $manifest['dependencies'] ?? [] ) ),
                'default_active' => array_key_exists( 'default_active', $manifest ) ? ! empty( $manifest['default_active'] ) : ! empty( $manifest['active'] ),
                'stage' => $this->normalizeBootStage( (string) ( $manifest['stage'] ?? 'early' ) ) ?: 'early',
                'source' => 'manifest',
                'plugin_directory' => $plugin_directory,
                'manifest_filename' => $manifest_filename,
                'first_party' => str_contains( str_replace( '\\', '/', $plugin_directory ), '/app/plugins/' ),
                'bootstrap_path' => $bootstrap_path,
                'manifest_warnings' => $this->validatePluginManifestWarnings( $manifest, $plugin_key, $bootstrap_path, str_contains( str_replace( '\\', '/', $plugin_directory ), '/app/plugins/' ) ),
            ]
        );
    }

    protected function validatePluginManifestWarnings( array $manifest, string $plugin_key, string $bootstrap_path, bool $first_party ) : array {
        $warnings = [];

        foreach ( [ 'name', 'version', 'description', 'license' ] as $field ) {
            if ( trim( (string) ( $manifest[$field] ?? '' ) ) === '' ) {
                $warnings[] = 'Missing recommended manifest field: ' . $field . '.';
            }
        }

        if ( ! is_file( $bootstrap_path ) || ! is_readable( $bootstrap_path ) ) {
            $warnings[] = 'Bootstrap file is missing or not readable.';
        }

        if ( array_key_exists( 'capabilities', $manifest ) && ! is_array( $manifest['capabilities'] ) ) {
            $warnings[] = 'Capabilities should be an array of strings.';
        }
        if ( array_key_exists( 'dependencies', $manifest ) && ! is_array( $manifest['dependencies'] ) ) {
            $warnings[] = 'Dependencies should be an array of plugin keys.';
        }

        foreach ( [ 'capabilities', 'dependencies' ] as $field ) {
            foreach ( (array) ( $manifest[$field] ?? [] ) as $value ) {
                if ( ! is_string( $value ) || trim( $value ) === '' ) {
                    $warnings[] = ucfirst( $field ) . ' contains an empty or non-string value.';
                    break;
                }
            }
        }

        $stage = strtolower( trim( (string) ( $manifest['stage'] ?? 'early' ) ) );
        if ( $stage !== '' && ! in_array( $stage, self::BOOT_STAGES, true ) ) {
            $warnings[] = 'Unknown boot stage "' . $stage . '"; using early.';
        }

        $license = trim( (string) ( $manifest['license'] ?? '' ) );
        if ( $first_party && $license !== 'AGPL-3.0-or-later' ) {
            $warnings[] = 'First-party plugins should declare AGPL-3.0-or-later.';
        }

        return( array_values( array_unique( $warnings ) ) );
    }

    protected function normalizePluginStringList( array $values ) : array {
        $normalized = [];
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }
            $value = trim( $value );
            if ( $value === '' ) {
                continue;
            }
            $normalized[] = $value;
        }

        return( array_values( array_unique( $normalized ) ) );
    }

    protected function normalizePluginRelativePath( string $relative_path ) : string {
        $relative_path = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, trim( $relative_path ) );
        if ( $relative_path === '' || str_starts_with( $relative_path, DIRECTORY_SEPARATOR ) || str_contains( $relative_path, '..' ) ) {
            return( '' );
        }

        return( $relative_path );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $plugin_key ) ?? '' );
    }

    protected function canonicalizePluginKey( string $plugin_key ) : string {
        $plugin_key = $this->normalizePluginKey( $plugin_key );
        return(
            match ( $plugin_key ) {
                'visitor-context' => 'footer',
                default => $plugin_key,
            }
        );
    }

    protected function normalizeBootStage( string $stage ) : string {
        $stage = strtolower( trim( $stage ) );
        return( in_array( $stage, self::BOOT_STAGES, true ) ? $stage : '' );
    }

    protected function normalizePublicSlot( string $slot ) : string {
        $slot = strtolower( trim( $slot ) );
        return( in_array( $slot, self::PUBLIC_SLOTS, true ) ? $slot : '' );
    }

    protected function normalizeAdminNavigationArea( string $area ) : string {
        $area = strtolower( trim( $area ) );
        return( in_array( $area, self::ADMIN_NAV_AREAS, true ) ? $area : '' );
    }

    protected function normalizeExportScope( string $scope ) : string {
        $scope = strtolower( trim( $scope ) );
        return( in_array( $scope, [ 'site', 'author', 'both' ], true ) ? $scope : '' );
    }

    protected function buildAdminViewData( array $data = [] ) : array {
        $current_role = strtolower( trim( (string) ( $data['current_role'] ?? $this->security->getCurrentRole() ) ) );
        $current_role_label = $current_role !== ''
            ? match ( $current_role ) {
                'superadmin' => 'Admin',
                'creator' => 'Author',
                default => ucfirst( $current_role ),
            }
            : null;
        $admin_plugin_nav_items = [
            'author' => $this->getAdminNavigationItems( 'author' ),
            'admin' => $this->getAdminNavigationItems( 'admin' ),
            'account' => $this->getAdminNavigationItems( 'account' ),
        ];
        $author_plugin_items = is_array( $admin_plugin_nav_items['author'] ?? null ) ? $admin_plugin_nav_items['author'] : [];
        $admin_plugin_nav_author_import_items = [];
        $admin_plugin_nav_author_general_items = [];
        foreach ( $author_plugin_items as $author_plugin_item ) {
            if ( ! is_array( $author_plugin_item ) ) {
                continue;
            }

            if ( strtolower( trim( (string) ( $author_plugin_item['group'] ?? '' ) ) ) === 'import' ) {
                $admin_plugin_nav_author_import_items[] = $author_plugin_item;
                continue;
            }

            $admin_plugin_nav_author_general_items[] = $author_plugin_item;
        }
        if ( $this->security->isSuperAdmin() ) {
            $author_signatures = [];
            foreach ( array_merge( $admin_plugin_nav_author_import_items, $admin_plugin_nav_author_general_items ) as $author_nav_item ) {
                if ( ! is_array( $author_nav_item ) ) {
                    continue;
                }
                $author_signatures[] = strtolower( trim( (string) ( $author_nav_item['section'] ?? '' ) ) ) . '|' . trim( (string) ( $author_nav_item['url'] ?? '' ) );
            }
            $author_signatures = array_values( array_unique( array_filter( $author_signatures, static fn( $signature ) => $signature !== '|' ) ) );

            if ( ! empty( $author_signatures ) ) {
                $filtered_admin_items = [];
                foreach ( $admin_plugin_nav_items['admin'] as $admin_nav_item ) {
                    if ( ! is_array( $admin_nav_item ) ) {
                        continue;
                    }
                    $signature = strtolower( trim( (string) ( $admin_nav_item['section'] ?? '' ) ) ) . '|' . trim( (string) ( $admin_nav_item['url'] ?? '' ) );
                    if ( in_array( $signature, $author_signatures, true ) ) {
                        continue;
                    }
                    $filtered_admin_items[] = $admin_nav_item;
                }
                $admin_plugin_nav_items['admin'] = $filtered_admin_items;
            }
        }

        $app_url = rtrim( (string) $this->app->get( 'app.url' ), '/' );
        $current_author_slug = strtolower( trim( (string) ( $data['current_author_slug'] ?? $this->security->getCurrentUsername() ) ) );
        $visit_root_url = $app_url . '/';
        $visit_site_url = $visit_root_url;
        if ( ! $this->security->isSuperAdmin() && $current_author_slug !== '' ) {
            $visit_site_url = $app_url . '/' . rawurlencode( $current_author_slug );
        }

        return(
            array_merge(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Plugin',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'current_role_label' => $current_role_label,
                    'current_username' => $this->security->getCurrentUsername(),
                    'is_superadmin' => $this->security->isSuperAdmin(),
                    'current_section' => 'author',
                    'current_location' => 'plugin',
                    'current_user_theme_override' => $this->resolveAdminThemeOverride(),
                    'current_user_theme_mode' => 'auto',
                    'screen_mode_cookie_name' => 'tinymashScreen',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'admin_profile_url' => $this->app->get( 'admin.url' ) . '/profile',
                    'entries_url' => $this->app->get( 'admin.url' ) . '/content',
                    'current_author_slug' => $current_author_slug,
                    'visit_site_url' => $visit_site_url,
                    'visit_root_url' => $visit_root_url,
                    'admin_plugin_nav_items' => $admin_plugin_nav_items,
                    'admin_plugin_nav_author_import_items' => $admin_plugin_nav_author_import_items,
                    'admin_plugin_nav_author_general_items' => $admin_plugin_nav_author_general_items,
                    'help_contexts' => [ 'admin-global' ],
                ],
                $data
            )
        );
    }

    protected function resolveAdminThemeOverride() : string {
        if ( isset( $_COOKIE['tinymashScreen'] ) ) {
            $cookie_value = strtolower( trim( (string) $_COOKIE['tinymashScreen'] ) );
            if ( in_array( $cookie_value, [ 'light', 'dark' ], true ) ) {
                return( $cookie_value );
            }
        } elseif ( isset( $_COOKIE['tmAdminThemeMode'] ) ) {
            $cookie_value = strtolower( trim( (string) $_COOKIE['tmAdminThemeMode'] ) );
            if ( in_array( $cookie_value, [ 'light', 'dark' ], true ) ) {
                return( $cookie_value );
            }
        } elseif ( isset( $_COOKIE['tmAdminDarkMode'] ) ) {
            $cookie_value = trim( (string) $_COOKIE['tmAdminDarkMode'] );
            if ( $cookie_value === '0' ) {
                return( 'light' );
            }
            if ( $cookie_value === '1' ) {
                return( 'dark' );
            }
        }

        return( '' );
    }

    protected function normalizeSettingsSectionDefinition( string $plugin_key, array $definition ) : ?array {
        $title = trim( (string) ( $definition['title'] ?? '' ) );
        $fields = is_array( $definition['fields'] ?? null ) ? $definition['fields'] : [];
        if ( $title === '' || empty( $fields ) ) {
            return( null );
        }

        $normalized_fields = [];
        foreach ( $fields as $field_definition ) {
            if ( ! is_array( $field_definition ) ) {
                continue;
            }
            $field_key = $this->normalizePluginKey( (string) ( $field_definition['key'] ?? '' ) );
            $field_type = strtolower( trim( (string) ( $field_definition['type'] ?? 'checkbox' ) ) );
            $field_label = trim( (string) ( $field_definition['label'] ?? '' ) );
            if ( $field_key === '' || $field_label === '' || ! in_array( $field_type, [ 'checkbox', 'select', 'text', 'password' ], true ) ) {
                continue;
            }

            $normalized_fields[] = [
                'key' => $field_key,
                'type' => $field_type,
                'label' => $field_label,
                'help' => trim( (string) ( $field_definition['help'] ?? '' ) ),
                'help_html' => trim( (string) ( $field_definition['help_html'] ?? '' ) ),
                'default' => $field_definition['default'] ?? '',
                'options' => is_array( $field_definition['options'] ?? null ) ? array_values( $field_definition['options'] ) : [],
            ];
        }

        if ( empty( $normalized_fields ) ) {
            return( null );
        }

        return(
            [
                'plugin_key' => $plugin_key,
                'title' => $title,
                'summary' => trim( (string) ( $definition['summary'] ?? '' ) ),
                'fields' => $normalized_fields,
            ]
        );
    }

    protected function canLoadPluginFile( string $plugin_file ) : bool {
        if ( ! is_file( $plugin_file ) || ! is_readable( $plugin_file ) ) {
            error_log( basename( __FILE__ ) . ': Plugin file is not readable "' . basename( $plugin_file ) . '"' );
            return( false );
        }

        return( true );
    }

}// TinyMashPlugins
