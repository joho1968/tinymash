<?php
namespace app\classes;

class TinyMashThemeRegistry {

    protected array $public_theme_roots = [];
    protected array $admin_theme_roots = [];
    protected string $public_root;
    protected ?array $public_themes = null;
    protected ?array $admin_themes = null;

    public function __construct( string|array $public_themes_root, string|array $admin_themes_root, string $public_root = '' ) {
        $this->public_theme_roots = $this->normalizeRootDirectories( $public_themes_root );
        $this->admin_theme_roots = $this->normalizeRootDirectories( $admin_themes_root );
        $this->public_root = rtrim( $public_root, DIRECTORY_SEPARATOR );
    }

    public function getPublicTheme( string $theme_key ) : array {
        return( $this->getThemeDefinition( 'public', $theme_key ) );
    }

    public function getAdminTheme( string $theme_key ) : array {
        return( $this->getThemeDefinition( 'admin', $theme_key ) );
    }

    public function listPublicThemes() : array {
        return( array_values( $this->getThemesByType( 'public' ) ) );
    }

    public function listAdminThemes() : array {
        return( array_values( $this->getThemesByType( 'admin' ) ) );
    }

    protected function getThemeDefinition( string $type, string $theme_key ) : array {
        $themes = $this->getThemesByType( $type );
        $theme_key = $this->normalizeThemeKey( $theme_key );

        if ( isset( $themes[$theme_key] ) ) {
            return( $themes[$theme_key] );
        }
        if ( isset( $themes['baseline'] ) ) {
            return( $themes['baseline'] );
        }

        return( $this->getFallbackThemeDefinition( $type ) );
    }

    protected function getThemesByType( string $type ) : array {
        $type = $type === 'admin' ? 'admin' : 'public';
        if ( $type === 'admin' ) {
            if ( $this->admin_themes === null ) {
                $this->admin_themes = $this->discoverThemes( 'admin', $this->admin_theme_roots );
            }
            return( $this->admin_themes );
        }

        if ( $this->public_themes === null ) {
            $this->public_themes = $this->discoverThemes( 'public', $this->public_theme_roots );
        }
        return( $this->public_themes );
    }

    protected function discoverThemes( string $type, array $root_directories ) : array {
        $themes = [];
        foreach ( $root_directories as $root_index => $root_directory ) {
            if ( ! is_dir( $root_directory ) || ! is_readable( $root_directory ) ) {
                continue;
            }

            $theme_directories = glob( $root_directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
            if ( ! is_array( $theme_directories ) ) {
                continue;
            }

            sort( $theme_directories, SORT_STRING );
            foreach ( $theme_directories as $theme_directory ) {
                $manifest_filename = $theme_directory . DIRECTORY_SEPARATOR . 'theme.json';
                $definition = $this->loadThemeManifest( $type, $manifest_filename, basename( $theme_directory ), $root_index === 0 );
                if ( $definition === null || isset( $themes[$definition['key']] ) ) {
                    continue;
                }

                $themes[$definition['key']] = $definition;
            }
        }

        if ( empty( $themes ) ) {
            $themes['baseline'] = $this->getFallbackThemeDefinition( $type );
        }

        return( $themes );
    }

    protected function loadThemeManifest( string $type, string $manifest_filename, string $fallback_key, bool $first_party = false ) : ?array {
        if ( ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename ) ) {
            return( null );
        }

        $manifest_json = file_get_contents( $manifest_filename );
        if ( ! is_string( $manifest_json ) || trim( $manifest_json ) === '' ) {
            return( null );
        }

        $manifest = json_decode( $manifest_json, true, 16 );
        if ( ! is_array( $manifest ) ) {
            return( null );
        }

        $key = $this->normalizeThemeKey( (string) ( $manifest['key'] ?? $fallback_key ) );
        if ( $key === '' ) {
            return( null );
        }

        $template_root = trim( (string) ( $manifest['template_root'] ?? '' ) );
        if ( $template_root === '' ) {
            $template_root = $type . '/' . $key;
        }

        return(
            [
                'type' => $type,
                'key' => $key,
                'name' => trim( (string) ( $manifest['name'] ?? ucwords( str_replace( [ '-', '_' ], ' ', $key ) ) ) ),
                'description' => trim( (string) ( $manifest['description'] ?? '' ) ),
                'version' => trim( (string) ( $manifest['version'] ?? '' ) ),
                'template_root' => $template_root,
                'views' => $this->normalizeViews( (array) ( $manifest['views'] ?? [] ) ),
                'slots' => $this->normalizeViews( (array) ( $manifest['slots'] ?? [] ) ),
                'css_urls' => $this->normalizeAssetUrls( (array) ( $manifest['css_urls'] ?? [] ) ),
                'js_urls' => $this->normalizeAssetUrls( (array) ( $manifest['js_urls'] ?? [] ) ),
                'supports' => is_array( $manifest['supports'] ?? null ) ? $manifest['supports'] : [],
                'settings_schema' => is_array( $manifest['settings_schema'] ?? null ) ? array_values( $manifest['settings_schema'] ) : [],
                'housekeeping_filename' => $this->resolveHousekeepingFilename( dirname( $manifest_filename ) ),
                'theme_directory' => dirname( $manifest_filename ),
                'manifest_filename' => $manifest_filename,
                'first_party' => $first_party,
                'manifest_warnings' => $this->validateThemeManifestWarnings( $type, $manifest, $key, dirname( $manifest_filename ), $first_party ),
            ]
        );
    }

    public function getThemeDiagnostics() : array {
        $themes = array_merge( $this->listPublicThemes(), $this->listAdminThemes() );
        $summary = [
            'total' => 0,
            'public' => 0,
            'admin' => 0,
            'first_party' => 0,
            'third_party' => 0,
            'warnings' => 0,
            'first_party_warnings' => 0,
        ];
        $warnings = [];

        foreach ( $themes as $theme ) {
            if ( ! is_array( $theme ) ) {
                continue;
            }

            $type = (string) ( $theme['type'] ?? 'public' );
            $theme_warnings = is_array( $theme['manifest_warnings'] ?? null ) ? $theme['manifest_warnings'] : [];
            $summary['total']++;
            $summary[$type === 'admin' ? 'admin' : 'public']++;
            if ( ! empty( $theme['first_party'] ) ) {
                $summary['first_party']++;
                $summary['first_party_warnings'] += count( $theme_warnings );
            } else {
                $summary['third_party']++;
            }
            $summary['warnings'] += count( $theme_warnings );
            if ( $theme_warnings !== [] ) {
                $warnings[] = $theme;
            }
        }

        return(
            [
                'summary' => $summary,
                'themes' => $themes,
                'warnings' => $warnings,
            ]
        );
    }

    protected function validateThemeManifestWarnings( string $type, array $manifest, string $theme_key, string $theme_directory, bool $first_party ) : array {
        $warnings = [];

        foreach ( [ 'key', 'name', 'version', 'description', 'author_name', 'author_email', 'website', 'license' ] as $field ) {
            if ( trim( (string) ( $manifest[$field] ?? '' ) ) === '' ) {
                $warnings[] = 'Missing recommended manifest field: ' . $field . '.';
            }
        }

        if ( isset( $manifest['key'] ) && $this->normalizeThemeKey( (string) $manifest['key'] ) !== $theme_key ) {
            $warnings[] = 'Manifest key normalizes to a different theme key.';
        }

        $license = trim( (string) ( $manifest['license'] ?? '' ) );
        if ( $first_party && $license !== 'AGPL-3.0-or-later' ) {
            $warnings[] = 'First-party themes should declare AGPL-3.0-or-later.';
        }

        $template_root = trim( (string) ( $manifest['template_root'] ?? '' ) );
        if ( $template_root === '' ) {
            $warnings[] = 'Missing template_root; using the default ' . $type . '/' . $theme_key . '.';
        } elseif ( ! $this->isValidThemeTemplatePath( $template_root, false ) ) {
            $warnings[] = 'template_root should be a safe relative view path.';
        }

        if ( array_key_exists( 'views', $manifest ) && ! is_array( $manifest['views'] ) ) {
            $warnings[] = 'Views should be an object of view keys and template paths.';
        } elseif ( $type === 'public' ) {
            $views = is_array( $manifest['views'] ?? null ) ? $manifest['views'] : [];
            foreach ( [ 'home', 'author-home', 'entry-post', 'entry-page', '404' ] as $required_view ) {
                if ( trim( (string) ( $views[$required_view] ?? '' ) ) === '' ) {
                    $warnings[] = 'Missing required public view: ' . $required_view . '.';
                }
            }
            foreach ( $views as $view_key => $view_path ) {
                $view_key = is_string( $view_key ) || is_int( $view_key ) ? (string) $view_key : '';
                if ( ! is_string( $view_path ) || trim( $view_key ) === '' || trim( $view_path ) === '' ) {
                    $warnings[] = 'Views should contain non-empty string keys and template paths.';
                    break;
                }
                $view_path = trim( $view_path );
                if ( ! $this->isValidThemeTemplatePath( $view_path, true ) ) {
                    $warnings[] = 'View "' . $view_key . '" should point at a safe .latte template path.';
                    continue;
                }
            }
        }
        if ( array_key_exists( 'slots', $manifest ) && ! is_array( $manifest['slots'] ) ) {
            $warnings[] = 'Slots should be an object of slot keys and template paths.';
        } elseif ( is_array( $manifest['slots'] ?? null ) ) {
            foreach ( $manifest['slots'] as $slot_key => $slot_path ) {
                if ( ! is_string( $slot_key ) || ! is_string( $slot_path ) || trim( $slot_key ) === '' || trim( $slot_path ) === '' ) {
                    $warnings[] = 'Slots should contain non-empty string keys and template paths.';
                    break;
                }
                if ( ! str_ends_with( trim( $slot_path ), '.latte' ) ) {
                    $warnings[] = 'Slot "' . $slot_key . '" should point at a .latte template path.';
                }
            }
        }
        if ( array_key_exists( 'supports', $manifest ) && ! is_array( $manifest['supports'] ) ) {
            $warnings[] = 'Supports should be an object of feature flags.';
        } elseif ( is_array( $manifest['supports'] ?? null ) ) {
            foreach ( $manifest['supports'] as $support_key => $support_value ) {
                if ( ! is_string( $support_key ) || trim( $support_key ) === '' ) {
                    $warnings[] = 'Supports should use non-empty string feature keys.';
                    break;
                }
                if ( ! is_bool( $support_value ) ) {
                    $warnings[] = 'Support "' . $support_key . '" should be true or false.';
                }
            }
        }
        if ( array_key_exists( 'settings_schema', $manifest ) && ! is_array( $manifest['settings_schema'] ) ) {
            $warnings[] = 'Settings schema should be an array.';
        } elseif ( is_array( $manifest['settings_schema'] ?? null ) ) {
            $warnings = array_merge( $warnings, $this->validateThemeSettingsSchemaWarnings( $manifest['settings_schema'] ) );
        }

        foreach ( [ 'css_urls', 'js_urls' ] as $field ) {
            if ( array_key_exists( $field, $manifest ) && ! is_array( $manifest[$field] ) ) {
                $warnings[] = $field . ' should be an array of local URLs.';
                continue;
            }
            foreach ( (array) ( $manifest[$field] ?? [] ) as $asset_url ) {
                if ( ! is_string( $asset_url ) || trim( $asset_url ) === '' ) {
                    $warnings[] = $field . ' contains an empty or non-string URL.';
                    break;
                }
                $asset_url = trim( $asset_url );
                if ( preg_match( '#^https?://#i', $asset_url ) === 1 || str_starts_with( $asset_url, '//' ) ) {
                    $warnings[] = $field . ' must use local assets only: ' . $asset_url;
                    break;
                }
                if ( parse_url( $asset_url, PHP_URL_SCHEME ) !== null ) {
                    $warnings[] = $field . ' must not use URL schemes: ' . $asset_url;
                    break;
                }
                if ( ! str_starts_with( $asset_url, '/' ) ) {
                    $warnings[] = $field . ' should use root-relative local URLs: ' . $asset_url;
                    break;
                }
                if ( $this->public_root !== '' ) {
                    $asset_path = (string) ( parse_url( $asset_url, PHP_URL_PATH ) ?? '' );
                    if ( $asset_path !== '' && ! $this->isPublicAssetFile( $asset_path ) ) {
                        $warnings[] = $field . ' points at a missing public asset: ' . $asset_url;
                        break;
                    }
                }
            }
        }

        return( array_values( array_unique( $warnings ) ) );
    }

    protected function validateThemeSettingsSchemaWarnings( array $settings_schema ) : array {
        $warnings = [];
        $setting_keys = [];

        foreach ( $settings_schema as $setting_definition ) {
            if ( ! is_array( $setting_definition ) ) {
                $warnings[] = 'Settings schema contains a non-object item.';
                continue;
            }

            $setting_key = trim( (string) ( $setting_definition['key'] ?? '' ) );
            if ( $setting_key === '' ) {
                $warnings[] = 'Settings schema contains an item without a key.';
                continue;
            }
            if ( ! preg_match( '/^[a-z0-9_][a-z0-9_-]*$/', $setting_key ) ) {
                $warnings[] = 'Setting "' . $setting_key . '" should use a safe lowercase key.';
            }
            if ( isset( $setting_keys[$setting_key] ) ) {
                $warnings[] = 'Settings schema contains a duplicate key: ' . $setting_key . '.';
            }
            $setting_keys[$setting_key] = true;

            if ( trim( (string) ( $setting_definition['label'] ?? '' ) ) === '' ) {
                $warnings[] = 'Setting "' . $setting_key . '" is missing a label.';
            }

            $type = strtolower( trim( (string) ( $setting_definition['type'] ?? '' ) ) );
            if ( ! in_array( $type, [ 'select', 'text' ], true ) ) {
                $warnings[] = 'Setting "' . $setting_key . '" uses an unsupported type.';
                continue;
            }

            if ( array_key_exists( 'author_override', $setting_definition ) && ! is_bool( $setting_definition['author_override'] ) ) {
                $warnings[] = 'Setting "' . $setting_key . '" author_override should be true or false.';
            }

            if ( $type === 'select' ) {
                if ( ! is_array( $setting_definition['options'] ?? null ) || empty( $setting_definition['options'] ) ) {
                    $warnings[] = 'Select setting "' . $setting_key . '" should declare options.';
                    continue;
                }

                $option_values = [];
                foreach ( $setting_definition['options'] as $option ) {
                    if ( ! is_array( $option ) || trim( (string) ( $option['value'] ?? '' ) ) === '' || trim( (string) ( $option['label'] ?? '' ) ) === '' ) {
                        $warnings[] = 'Select setting "' . $setting_key . '" contains an option without value and label.';
                        continue;
                    }
                    $option_values[] = (string) $option['value'];
                }

                if ( array_key_exists( 'default', $setting_definition ) && ! in_array( (string) $setting_definition['default'], $option_values, true ) ) {
                    $warnings[] = 'Select setting "' . $setting_key . '" default should match one of its options.';
                }
            }
        }

        return( $warnings );
    }

    protected function isValidThemeTemplatePath( string $path, bool $require_latte ) : bool {
        $path = str_replace( '\\', '/', trim( $path ) );
        $path = trim( $path, '/' );
        if ( $path === '' || str_contains( $path, "\0" ) || str_contains( $path, '..' ) ) {
            return( false );
        }
        if ( $require_latte && ! str_ends_with( $path, '.latte' ) ) {
            return( false );
        }
        foreach ( explode( '/', $path ) as $part ) {
            if ( $part === '' || $part === '.' || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $part ) ) {
                return( false );
            }
        }

        return( true );
    }

    protected function isPublicAssetFile( string $asset_path ) : bool {
        $asset_path = str_replace( '\\', '/', trim( $asset_path ) );
        if ( $asset_path === '' || ! str_starts_with( $asset_path, '/' ) || str_contains( $asset_path, "\0" ) || str_contains( $asset_path, '..' ) ) {
            return( false );
        }

        $public_root = realpath( $this->public_root );
        if ( ! is_string( $public_root ) || $public_root === '' ) {
            return( false );
        }

        $filename = realpath( $public_root . str_replace( '/', DIRECTORY_SEPARATOR, $asset_path ) );
        if ( ! is_string( $filename ) || ! is_file( $filename ) ) {
            return( false );
        }

        return( str_starts_with( $filename, rtrim( $public_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) );
    }

    protected function normalizeRootDirectories( string|array $root_directories ) : array {
        $directories = is_array( $root_directories ) ? $root_directories : [ $root_directories ];
        $normalized = [];
        foreach ( $directories as $directory ) {
            if ( ! is_string( $directory ) ) {
                continue;
            }

            $directory = rtrim( trim( $directory ), DIRECTORY_SEPARATOR );
            if ( $directory === '' ) {
                continue;
            }

            $normalized[] = $directory;
        }

        return( array_values( array_unique( $normalized ) ) );
    }

    protected function normalizeViews( array $views ) : array {
        $normalized = [];
        foreach ( $views as $view_key => $view_path ) {
            if ( ! is_string( $view_key ) || ! is_string( $view_path ) ) {
                continue;
            }
            $view_key = strtolower( trim( $view_key ) );
            $view_path = trim( $view_path );
            if ( $view_key === '' || $view_path === '' ) {
                continue;
            }
            $normalized[$view_key] = $view_path;
        }

        return( $normalized );
    }

    protected function normalizeAssetUrls( array $asset_urls ) : array {
        $normalized = [];
        foreach ( $asset_urls as $asset_url ) {
            if ( ! is_string( $asset_url ) ) {
                continue;
            }
            $asset_url = trim( $asset_url );
            if ( $asset_url === '' ) {
                continue;
            }
            $normalized[] = $this->appendAssetVersion( $asset_url );
        }

        return( $normalized );
    }

    protected function appendAssetVersion( string $asset_url ) : string {
        if ( $this->public_root === '' || ! str_starts_with( $asset_url, '/' ) ) {
            return( $asset_url );
        }

        $url_parts = parse_url( $asset_url );
        if ( ! is_array( $url_parts ) ) {
            return( $asset_url );
        }

        $path = trim( (string) ( $url_parts['path'] ?? '' ) );
        if ( $path === '' ) {
            return( $asset_url );
        }

        $asset_filename = $this->public_root . str_replace( '/', DIRECTORY_SEPARATOR, $path );
        if ( ! is_file( $asset_filename ) ) {
            return( $asset_url );
        }

        $version = (string) @ filemtime( $asset_filename );
        if ( $version === '' || $version === '0' ) {
            return( $asset_url );
        }

        $separator = str_contains( $asset_url, '?' ) ? '&' : '?';
        return( $asset_url . $separator . 'v=' . rawurlencode( $version ) );
    }

    protected function normalizeThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?? '' );
    }

    protected function resolveHousekeepingFilename( string $theme_directory ) : string {
        $housekeeping_filename = rtrim( $theme_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'housekeeping.php';
        if ( is_file( $housekeeping_filename ) && is_readable( $housekeeping_filename ) ) {
            return( $housekeeping_filename );
        }

        return( '' );
    }

    protected function getFallbackThemeDefinition( string $type ) : array {
        if ( $type === 'admin' ) {
            return(
                [
                    'type' => 'admin',
                    'key' => 'baseline',
                    'name' => 'Baseline Admin',
                    'description' => '',
                    'version' => '',
                    'template_root' => 'admin/baseline',
                    'views' => [],
                    'slots' => [
                        'head_extra' => 'slots/head-extra.latte',
                        'topbar_actions' => '../slots/topbar-actions.latte',
                        'section_tools' => '../slots/section-tools.latte',
                    ],
                    'css_urls' => [
                        '/ext/bs/css/bootstrap.min.css',
                        '/ext/bsi/bootstrap-icons.min.css',
                        '/css/tinymash.css',
                    ],
                    'js_urls' => [
                        '/ext/bs/js/bootstrap.bundle.min.js',
                    ],
                    'supports' => [],
                    'settings_schema' => [],
                    'manifest_warnings' => [],
                ]
            );
        }

        return(
            [
                'type' => 'public',
                'key' => 'baseline',
                'name' => 'Baseline',
                'description' => '',
                'version' => '',
                'template_root' => 'public/baseline',
                'views' => [
                    'home' => 'public/baseline/home.latte',
                    'author-home' => 'public/baseline/author.home.latte',
                    'entry-post' => 'public/baseline/entry.latte',
                    'entry-page' => 'public/baseline/entry.latte',
                    '404' => 'public/baseline/404.latte',
                ],
                'slots' => [],
                'css_urls' => [
                    '/ext/bs/css/bootstrap.min.css',
                    '/ext/bsi/bootstrap-icons.min.css',
                    '/css/tinymash.css',
                ],
                'js_urls' => [
                    '/ext/bs/js/bootstrap.bundle.min.js',
                ],
                'supports' => [
                    'menus' => true,
                    'breadcrumbs' => true,
                    'page_sidebar' => true,
                    'after_content_child_pages' => true,
                    'footer_menu' => false,
                    'public_background' => true,
                ],
                'settings_schema' => [],
                'manifest_warnings' => [],
            ]
        );
    }

}// TinyMashThemeRegistry
