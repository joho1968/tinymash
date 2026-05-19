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
            ]
        );
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
            ]
        );
    }

}// TinyMashThemeRegistry
