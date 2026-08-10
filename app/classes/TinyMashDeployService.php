<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';
require_once __DIR__ . '/TinyMashDeployPolicy.php';

class TinyMashDeployService {

    protected const INTERNAL_ONLY_DEPLOY_PATHS = [
        '.agents', '.cache', '.codex', '.config', '.git', '.runway-config.json',
        'AGENTS.md', 'Site', '_auth', '_cache', '_deploy', 'audit_copilot', 'docs',
        'logs', 'node_modules', 'package-lock.json', 'package.json', 'tests',
        'tinymash.zip', 'tmp',
    ];

    protected string $project_root;
    protected TinyMashConfig $config;
    protected string $manifest_filename;
    protected array $exclude_paths = [];
    protected array $exclude_suffixes = [];

    public function __construct( string $project_root, TinyMashConfig $config, string $manifest_filename ) {
        $this->project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
        $this->config = $config;
        $this->manifest_filename = $manifest_filename;
    }

    public function deploy( string $target_directory ) : array {
        $manifest = $this->loadManifest();
        $target_directory = $this->prepareTargetDirectory( $target_directory );
        $this->assertTargetDoesNotOverlapSources( $target_directory, $manifest );
        $this->exclude_paths = array_values( array_filter( array_map( fn( $path ) => $this->normalizeRelativePath( (string) $path ), (array) ( $manifest['exclude_paths'] ?? [] ) ) ) );
        $this->exclude_suffixes = array_values( array_filter( array_map( static fn( $suffix ) => trim( (string) $suffix ), (array) ( $manifest['exclude_suffixes'] ?? [] ) ) ) );

        $copied_files = 0;
        $copied_directories = [];
        $site_identity_media_files = [];
        $warnings = [];

        foreach ( (array) ( $manifest['root_files'] ?? [] ) as $relative_path ) {
            $source_path = $this->resolveProjectPath( (string) $relative_path );
            $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $relative_path );
            $this->copyFileToPath( $source_path, $target_path );
            $copied_files++;
        }

        foreach ( (array) ( $manifest['directories'] ?? [] ) as $relative_path ) {
            $source_path = $this->resolveProjectPath( (string) $relative_path );
            $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $relative_path );
            $copy_result = $this->copyDirectoryRecursively( $source_path, $target_path );
            $copied_files += (int) ( $copy_result['files'] ?? 0 );
            if ( ! empty( $copy_result['created'] ) ) {
                $copied_directories[] = (string) $relative_path;
            }
        }

        foreach ( (array) ( $manifest['optional_directories'] ?? [] ) as $relative_path ) {
            $normalized_relative_path = $this->normalizeRelativePath( (string) $relative_path );
            if ( $normalized_relative_path === '' ) {
                continue;
            }

            $source_path = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized_relative_path );
            if ( ! is_dir( $source_path ) || ! is_readable( $source_path ) ) {
                continue;
            }

            $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized_relative_path );
            $copy_result = $this->copyDirectoryRecursively( $source_path, $target_path );
            $copied_files += (int) ( $copy_result['files'] ?? 0 );
            if ( ! empty( $copy_result['created'] ) ) {
                $copied_directories[] = $normalized_relative_path;
            }
        }

        $active_plugins = $this->getActivePluginKeys();
        if ( ! empty( $manifest['active_plugins_only'] ) ) {
            $plugin_root = trim( (string) ( $manifest['plugin_directory'] ?? 'plugins' ), '/\\' );
            $plugin_data_root = trim( (string) ( $manifest['plugin_data_directory'] ?? 'data/plugins' ), '/\\' );
            $copy_active_plugin_data = ! empty( $manifest['copy_active_plugin_data'] );
            foreach ( $active_plugins as $plugin_key ) {
                $relative_path = $plugin_root . '/' . $plugin_key;
                $source_path = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
                if ( ! is_dir( $source_path ) ) {
                    continue;
                }
                $target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
                $copy_result = $this->copyDirectoryRecursively( $source_path, $target_path );
                $copied_files += (int) ( $copy_result['files'] ?? 0 );
                if ( ! empty( $copy_result['created'] ) ) {
                    $copied_directories[] = $relative_path;
                }

                if ( $copy_active_plugin_data ) {
                    $plugin_data_relative_path = $plugin_data_root . '/' . $plugin_key;
                    $plugin_data_source_path = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugin_data_relative_path );
                    if ( ! is_dir( $plugin_data_source_path ) ) {
                        continue;
                    }

                    $plugin_data_target_path = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugin_data_relative_path );
                    $plugin_data_copy_result = $this->copyDirectoryRecursively( $plugin_data_source_path, $plugin_data_target_path );
                    $copied_files += (int) ( $plugin_data_copy_result['files'] ?? 0 );
                    if ( ! empty( $plugin_data_copy_result['created'] ) ) {
                        $copied_directories[] = $plugin_data_relative_path;
                    }
                }
            }
        }

        foreach ( (array) ( $manifest['empty_directories'] ?? [] ) as $relative_path ) {
            $this->ensureDirectory(
                $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $relative_path )
            );
        }

        $sanitized_config = $this->buildSanitizedDeployConfig();
        $sanitized_config_relative_path = 'app/config/tinymash.json.example';
        $sanitized_config_target = $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $sanitized_config_relative_path );
        $this->writeJsonFile( $sanitized_config_target, $sanitized_config );

        if ( ! is_file( $this->project_root . DIRECTORY_SEPARATOR . 'LICENSE' ) ) {
            $warnings[] = 'No top-level LICENSE file exists in the source tree, so none was included in the deploy build.';
        }

        $file_inventory = $this->buildFileInventory( $target_directory );

        $deploy_manifest = [
            'format' => 'tinymash-deploy',
            'format_version' => 2,
            'built_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'site' => [
                'name' => (string) ( $sanitized_config['site']['name'] ?? 'tinymash' ),
                'base_url' => (string) ( $sanitized_config['site']['base_url'] ?? '' ),
                'public_theme' => (string) ( $sanitized_config['themes']['public']['current'] ?? 'baseline' ),
            ],
            'includes' => [
                'root_files' => array_values( (array) ( $manifest['root_files'] ?? [] ) ),
                'directories' => array_values( (array) ( $manifest['directories'] ?? [] ) ),
                'optional_directories' => array_values( (array) ( $manifest['optional_directories'] ?? [] ) ),
                'empty_directories' => array_values( (array) ( $manifest['empty_directories'] ?? [] ) ),
                'exclude_paths' => $this->exclude_paths,
                'exclude_suffixes' => $this->exclude_suffixes,
                'active_plugins_only' => ! empty( $manifest['active_plugins_only'] ),
                'plugin_data_directory' => trim( (string) ( $manifest['plugin_data_directory'] ?? 'data/plugins' ), '/\\' ),
                'copy_active_plugin_data' => ! empty( $manifest['copy_active_plugin_data'] ),
                'active_plugins' => $active_plugins,
                'site_identity_media_files' => $site_identity_media_files,
                'example_config' => true,
                'example_config_path' => $sanitized_config_relative_path,
            ],
            'counts' => [
                'copied_files' => $copied_files,
                'copied_directories' => count( array_unique( $copied_directories ) ),
                'inventoried_files' => count( $file_inventory ),
            ],
            'inventory' => [
                'algorithm' => 'sha256',
                'files' => $file_inventory,
            ],
            'upgrade' => [
                'strategy' => 'staged-replacement',
                'preserve_paths' => TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS,
                'discard_paths' => TinyMashDeployPolicy::UPGRADE_DISCARD_PATHS,
                'review_paths' => TinyMashDeployPolicy::UPGRADE_REVIEW_PATHS,
            ],
            'warnings' => $warnings,
        ];

        $this->writeJsonFile( $target_directory . DIRECTORY_SEPARATOR . 'deploy-manifest.json', $deploy_manifest );
        return( $deploy_manifest );
    }

    protected function buildSanitizedDeployConfig() : array {
        $source_config = $this->loadSourceConfigArray();
        $source_plugin_states = is_array( $source_config['plugins']['states'] ?? null ) ? $source_config['plugins']['states'] : [];
        $source_public_theme_settings = is_array( $source_config['themes']['public']['settings'] ?? null ) ? $source_config['themes']['public']['settings'] : [];

        $sanitized_plugin_states = [];
        foreach ( $source_plugin_states as $plugin_key => $enabled ) {
            if ( ! is_string( $plugin_key ) ) {
                continue;
            }

            $normalized_plugin_key = strtolower( trim( $plugin_key ) );
            if ( $normalized_plugin_key === '' ) {
                continue;
            }

            $sanitized_plugin_states[$normalized_plugin_key] = (bool) $enabled;
        }
        ksort( $sanitized_plugin_states, SORT_STRING );

        $sanitized_public_theme_settings = [];
        foreach ( $source_public_theme_settings as $theme_key => $theme_settings ) {
            if ( ! is_string( $theme_key ) ) {
                continue;
            }

            $normalized_theme_key = strtolower( trim( $theme_key ) );
            if ( $normalized_theme_key === '' ) {
                continue;
            }

            $sanitized_public_theme_settings[$normalized_theme_key] = [];
        }

        foreach ( [ 'baseline', 'blocks', 'docsmatter', 'panel-magazine', 'terminal', 'timeline' ] as $theme_key ) {
            if ( ! array_key_exists( $theme_key, $sanitized_public_theme_settings ) ) {
                $sanitized_public_theme_settings[$theme_key] = [];
            }
        }
        ksort( $sanitized_public_theme_settings, SORT_STRING );

        return(
            [
                'site' => [
                    'name' => 'tinymash',
                    'slogan' => '',
                    'contact' => '',
                    'base_url' => '',
                    'login_url' => '/admin/login',
                    'admin_url' => '/admin',
                    'security_min_password' => 8,
                    'default_language' => 'en',
                    'allow_registrations' => false,
                    'is_public' => true,
                    'maintenance' => false,
                    'admin_email' => '',
                    'week_starts_on' => 'monday',
                    'allow_username_change' => false,
                    'allow_email_change' => true,
                    'login_message' => '',
                    'images' => [
                        'banner' => [],
                        'favicon_png' => [],
                        'favicon_ico' => [],
                        'og' => [],
                        'background' => [],
                    ],
                    'background_render_mode' => 'scaled',
                    'system_notifications_email' => '',
                    'allow_secret_links' => true,
                    'secret_link_default_expiry_days' => 60,
                    'filesystem_umask' => '0007',
                    'rendered_content_cache_enabled' => true,
                    'discourage_search_indexing' => false,
                    'allow_admin_password_resets' => false,
                    'allow_author_password_resets' => true,
                    'password_reset_throttle_window_minutes' => 60,
                    'password_reset_max_ip_requests' => 5,
                    'password_reset_max_identifier_requests' => 3,
                ],
                'locale' => [
                    'time' => 'H:i (T)',
                    'date' => 'd-M-Y',
                    'timezone' => 'UTC',
                ],
                'content' => [
                    'require_moderation' => false,
                    'revision_retention_limit' => 20,
                ],
                'editor' => [
                    'autosave_enabled' => true,
                    'autosave_interval_seconds' => 120,
                    'classic_smileys_enabled' => true,
                ],
                'media' => [
                    'content_images_mode' => 'authenticated',
                    'image_driver' => 'auto',
                    'image_max_width' => 1920,
                    'image_max_height' => 1920,
                    'generate_thumbnails' => true,
                    'thumbnail_max_width' => 320,
                    'thumbnail_max_height' => 320,
                    'image_max_upload_mb' => 10,
                    'allowed_image_mimes' => [
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                        'image/webp',
                        'image/avif',
                    ],
                ],
                'smtp' => [
                    'enabled' => false,
                    'host' => '',
                    'port' => 587,
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls',
                    'from_email' => '',
                    'from_name' => '',
                    'reply_to_email' => '',
                ],
                'themes' => [
                    'public' => [
                        'current' => 'baseline',
                        'settings' => $sanitized_public_theme_settings,
                    ],
                ],
                'plugins' => [
                    'states' => $sanitized_plugin_states,
                    'settings' => [],
                ],
                'housekeeping' => [
                    'stale_draft_retention_days' => 7,
                    'last_run_utc' => '',
                    'last_trigger' => '',
                    'last_mode' => '',
                    'web_fallback_mode' => 'auto',
                ],
                'notifications' => [
                    'retention_days' => 30,
                    'email_events' => [
                        'moderation_required' => false,
                        'profile_email_changed' => false,
                        'user_lockout' => true,
                        'component_updates' => true,
                    ],
                ],
                'menus' => [
                    'locations' => [
                        'primary' => [],
                        'footer' => [],
                    ],
                ],
            ]
        );
    }

    protected function loadSourceConfigArray() : array {
        $config_filename = $this->project_root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
        if ( ! is_file( $config_filename ) || ! is_readable( $config_filename ) ) {
            return( [] );
        }

        $config_json = @ file_get_contents( $config_filename );
        if ( ! is_string( $config_json ) || trim( $config_json ) === '' ) {
            return( [] );
        }

        try {
            $decoded = json_decode( $config_json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( \JsonException ) {
            return( [] );
        }

        return( is_array( $decoded ) ? $decoded : [] );
    }

    protected function loadManifest() : array {
        if ( ! is_file( $this->manifest_filename ) || ! is_readable( $this->manifest_filename ) ) {
            throw new \RuntimeException( 'Deploy manifest is not readable.' );
        }

        $manifest = require $this->manifest_filename;
        if ( ! is_array( $manifest ) ) {
            throw new \RuntimeException( 'Deploy manifest is invalid.' );
        }

        $this->assertManifestExcludesInternalPaths( $manifest );

        return( $manifest );
    }

    protected function assertManifestExcludesInternalPaths( array $manifest ) : void {
        $manifest_paths = [];
        foreach ( [ 'root_files', 'directories', 'optional_directories', 'empty_directories' ] as $manifest_key ) {
            $manifest_paths = array_merge( $manifest_paths, (array) ( $manifest[$manifest_key] ?? [] ) );
        }
        $manifest_paths[] = (string) ( $manifest['plugin_directory'] ?? '' );
        $manifest_paths[] = (string) ( $manifest['plugin_data_directory'] ?? '' );

        foreach ( $manifest_paths as $manifest_path ) {
            $normalized_path = $this->normalizeRelativePath( (string) $manifest_path );
            if ( $normalized_path === '' ) {
                continue;
            }

            $path_segments = [];
            foreach ( explode( '/', $normalized_path ) as $path_segment ) {
                if ( $path_segment === '' || $path_segment === '.' ) {
                    continue;
                }
                if ( $path_segment === '..' ) {
                    throw new \RuntimeException( 'Deploy manifest path traversal is not allowed: ' . $normalized_path );
                }
                $path_segments[] = $path_segment;
            }
            $normalized_path = implode( '/', $path_segments );

            foreach ( self::INTERNAL_ONLY_DEPLOY_PATHS as $internal_path ) {
                if ( $normalized_path === $internal_path || str_starts_with( $normalized_path, $internal_path . '/' ) ) {
                    throw new \RuntimeException( 'Deploy manifest includes internal-only path: ' . $normalized_path );
                }
            }
        }
    }

    protected function getActivePluginKeys() : array {
        $states = $this->config->getPluginStates();
        $plugin_defaults = $this->discoverPluginDefaultStates();
        $active_plugins = [];

        foreach ( $plugin_defaults as $plugin_key => $default_active ) {
            $normalized_key = strtolower( trim( (string) $plugin_key ) );
            if ( $normalized_key === 'visitor-context' ) {
                $normalized_key = 'footer';
            }
            if ( $normalized_key === '' ) {
                continue;
            }

            $is_active = array_key_exists( $normalized_key, $states )
                ? (bool) $states[$normalized_key]
                : (bool) $default_active;
            if ( ! $is_active ) {
                continue;
            }

            $active_plugins[] = $normalized_key;
        }

        foreach ( $states as $plugin_key => $is_active ) {
            $normalized_key = strtolower( trim( (string) $plugin_key ) );
            if ( $normalized_key === 'visitor-context' ) {
                $normalized_key = 'footer';
            }
            if ( $normalized_key === '' || ! $is_active || in_array( $normalized_key, $active_plugins, true ) ) {
                continue;
            }

            $active_plugins[] = $normalized_key;
        }

        sort( $active_plugins, SORT_STRING );
        return( array_values( array_unique( $active_plugins ) ) );
    }

    protected function discoverPluginDefaultStates() : array {
        $plugin_defaults = [];
        foreach ( [ 'app/plugins', 'plugins' ] as $plugins_directory ) {
            $absolute_plugins_directory = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $plugins_directory );
            if ( ! is_dir( $absolute_plugins_directory ) || ! is_readable( $absolute_plugins_directory ) ) {
                continue;
            }

            $plugin_directories = glob( $absolute_plugins_directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
            if ( ! is_array( $plugin_directories ) ) {
                continue;
            }

            foreach ( $plugin_directories as $plugin_directory ) {
                if ( ! is_string( $plugin_directory ) ) {
                    continue;
                }

                $manifest_filename = $plugin_directory . DIRECTORY_SEPARATOR . 'plugin.json';
                if ( ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename ) ) {
                    continue;
                }

                $manifest_json = @ file_get_contents( $manifest_filename );
                if ( ! is_string( $manifest_json ) || trim( $manifest_json ) === '' ) {
                    continue;
                }

                try {
                    $manifest = json_decode( $manifest_json, true, 512, JSON_THROW_ON_ERROR );
                } catch ( \JsonException ) {
                    continue;
                }
                if ( ! is_array( $manifest ) ) {
                    continue;
                }

                $plugin_key = $this->normalizePluginKey( (string) ( $manifest['key'] ?? basename( $plugin_directory ) ) );
                if ( $plugin_key === '' || array_key_exists( $plugin_key, $plugin_defaults ) ) {
                    continue;
                }

                $plugin_defaults[$plugin_key] = array_key_exists( 'default_active', $manifest )
                    ? ! empty( $manifest['default_active'] )
                    : ! empty( $manifest['active'] );
            }
        }

        ksort( $plugin_defaults, SORT_STRING );
        return( $plugin_defaults );
    }

    protected function prepareTargetDirectory( string $target_directory ) : string {
        $target_directory = trim( $target_directory );
        if ( $target_directory === '' ) {
            throw new \InvalidArgumentException( 'target_directory' );
        }

        if ( is_link( $target_directory ) ) {
            throw new \RuntimeException( 'Target directory must not be a symbolic link.' );
        }

        if ( file_exists( $target_directory ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Target exists and is not a directory.' );
        }

        if ( is_dir( $target_directory ) ) {
            $iterator = new \FilesystemIterator( $target_directory, \FilesystemIterator::SKIP_DOTS );
            if ( $iterator->valid() ) {
                throw new \RuntimeException( 'Target directory must be empty.' );
            }
        } elseif ( ! @mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create target directory.' );
        }

        return( rtrim( $target_directory, DIRECTORY_SEPARATOR ) );
    }

    protected function assertTargetDoesNotOverlapSources( string $target_directory, array $manifest ) : void {
        $real_target = realpath( $target_directory );
        $real_project_root = realpath( $this->project_root );
        if ( ! is_string( $real_target ) || ! is_string( $real_project_root ) ) {
            throw new \RuntimeException( 'Unable to resolve deploy source or target directory.' );
        }

        $real_target = rtrim( $real_target, DIRECTORY_SEPARATOR );
        $real_project_root = rtrim( $real_project_root, DIRECTORY_SEPARATOR );
        if ( $real_target === $real_project_root ) {
            throw new \RuntimeException( 'Deploy target must not be the project root.' );
        }

        $source_directories = array_merge(
            (array) ( $manifest['directories'] ?? [] ),
            (array) ( $manifest['optional_directories'] ?? [] ),
            [
                (string) ( $manifest['plugin_directory'] ?? '' ),
                (string) ( $manifest['plugin_data_directory'] ?? '' ),
            ]
        );
        foreach ( $source_directories as $relative_path ) {
            $relative_path = $this->normalizeRelativePath( (string) $relative_path );
            if ( $relative_path === '' ) {
                continue;
            }

            $source_directory = $real_project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
            $real_source_directory = realpath( $source_directory );
            if ( ! is_string( $real_source_directory ) || ! is_dir( $real_source_directory ) ) {
                continue;
            }

            $real_source_directory = rtrim( $real_source_directory, DIRECTORY_SEPARATOR );
            if ( $real_target === $real_source_directory || str_starts_with( $real_target, $real_source_directory . DIRECTORY_SEPARATOR ) ) {
                throw new \RuntimeException( 'Deploy target must not be inside a copied source directory.' );
            }
        }
    }

    protected function resolveProjectPath( string $relative_path ) : string {
        $normalized_relative_path = trim( str_replace( '\\', '/', $relative_path ), '/' );
        if ( $normalized_relative_path === '' ) {
            throw new \RuntimeException( 'Deploy path is invalid.' );
        }

        $full_path = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $normalized_relative_path );
        if ( ! file_exists( $full_path ) ) {
            throw new \RuntimeException( 'Deploy path does not exist: ' . $normalized_relative_path );
        }

        return( $full_path );
    }

    protected function copyFileToPath( string $source_file, string $target_file ) : void {
        if ( is_link( $source_file ) || ! is_file( $source_file ) || ! is_readable( $source_file ) ) {
            throw new \RuntimeException( 'Source file is not readable.' );
        }

        $this->ensureDirectory( dirname( $target_file ) );
        if ( ! @copy( $source_file, $target_file ) ) {
            throw new \RuntimeException( 'Unable to copy deploy file.' );
        }
    }

    protected function copyDirectoryRecursively( string $source_directory, string $target_directory ) : array {
        if ( is_link( $source_directory ) || ! is_dir( $source_directory ) || ! is_readable( $source_directory ) ) {
            throw new \RuntimeException( 'Source directory is not readable.' );
        }

        $this->ensureDirectory( $target_directory );
        $copied_files = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source_directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isLink() ) {
                throw new \RuntimeException( 'Deploy sources must not contain symbolic links.' );
            }

            $relative_path = $iterator->getSubPathName();
            $target_path = $target_directory . DIRECTORY_SEPARATOR . $relative_path;
            $project_relative_path = $this->buildProjectRelativePath( $item->getPathname() );
            if ( $this->shouldExcludePath( $project_relative_path ) ) {
                continue;
            }

            if ( $item->isDir() ) {
                $this->ensureDirectory( $target_path );
                continue;
            }

            $this->ensureDirectory( dirname( $target_path ) );
            if ( ! @copy( $item->getPathname(), $target_path ) ) {
                throw new \RuntimeException( 'Unable to copy deploy file.' );
            }
            $copied_files++;
        }

        return(
            [
                'created' => true,
                'files' => $copied_files,
            ]
        );
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( is_dir( $directory ) ) {
            return;
        }

        if ( ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create deploy directory.' );
        }
    }

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $this->ensureDirectory( dirname( $filename ) );
        $json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        if ( ! is_string( $json ) ) {
            throw new \RuntimeException( 'Unable to encode deploy manifest.' );
        }

        $contents = $json . PHP_EOL;
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $contents ) ) {
            throw new \RuntimeException( 'Unable to write deploy manifest.' );
        }
    }

    protected function buildFileInventory( string $target_directory ) : array {
        $inventory = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $target_directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iterator as $item ) {
            if ( $item->isLink() ) {
                throw new \RuntimeException( 'Deploy target must not contain symbolic links.' );
            }
            if ( ! $item->isFile() || ! $item->isReadable() ) {
                continue;
            }

            $relative_path = $this->buildTargetRelativePath( $target_directory, $item->getPathname() );
            if ( TinyMashDeployPolicy::isAuditIgnoredPath( $relative_path ) ) {
                continue;
            }
            $bytes = $item->getSize();
            $sha256 = @ hash_file( 'sha256', $item->getPathname() );
            if ( ! is_string( $sha256 ) ) {
                throw new \RuntimeException( 'Unable to hash deploy file: ' . $relative_path );
            }
            $inventory[$relative_path] = [
                'bytes' => $bytes,
                'sha256' => $sha256,
            ];
        }
        ksort( $inventory, SORT_STRING );

        return( $inventory );
    }

    protected function buildTargetRelativePath( string $target_directory, string $path ) : string {
        $target_root = rtrim( str_replace( '\\', '/', $target_directory ), '/' ) . '/';
        $normalized_path = str_replace( '\\', '/', $path );
        if ( ! str_starts_with( $normalized_path, $target_root ) ) {
            throw new \RuntimeException( 'Deploy target path escaped the target directory.' );
        }

        return( $this->normalizeRelativePath( substr( $normalized_path, strlen( $target_root ) ) ) );
    }

    protected function normalizeRelativePath( string $path ) : string {
        return( trim( str_replace( '\\', '/', $path ), '/' ) );
    }

    protected function normalizePluginKey( string $plugin_key ) : string {
        $plugin_key = strtolower( trim( $plugin_key ) );
        $plugin_key = preg_replace( '/[^a-z0-9_-]+/', '-', $plugin_key ) ?? '';
        $plugin_key = trim( $plugin_key, '-_' );
        return( $plugin_key === 'visitor-context' ? 'footer' : $plugin_key );
    }

    protected function buildProjectRelativePath( string $path ) : string {
        $project_root = rtrim( str_replace( '\\', '/', $this->project_root ), '/' ) . '/';
        $normalized_path = str_replace( '\\', '/', $path );
        if ( str_starts_with( $normalized_path, $project_root ) ) {
            return( ltrim( substr( $normalized_path, strlen( $project_root ) ), '/' ) );
        }

        return( ltrim( $normalized_path, '/' ) );
    }

    protected function shouldExcludePath( string $relative_path ) : bool {
        $relative_path = $this->normalizeRelativePath( $relative_path );
        if ( $relative_path === '' ) {
            return( false );
        }

        if ( in_array( $relative_path, $this->exclude_paths, true ) ) {
            return( true );
        }

        foreach ( $this->exclude_suffixes as $suffix ) {
            if ( $suffix !== '' && str_ends_with( $relative_path, $suffix ) ) {
                return( true );
            }
        }

        return( false );
    }

}
