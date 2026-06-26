<?php
namespace app\classes;

use flight\Engine;

class TinyMashTheme {
    protected Engine $app;
    protected TinyMashContentRepository $content_repository;
    protected ?TinyMashConfig $config;
    protected ?TinyMashThemeRegistry $theme_registry;
    protected ?TinyMashThemeSupportRegistry $support_registry;
    protected ?TinyMashUserRepository $user_repository;
    protected ?TinyMashMenuService $menu_service;
    protected string $theme_key_override = '';
    protected array $navigation_menu_cache = [];
    protected ?array $navigation_tree_cache = null;
    protected ?array $root_page_structure_cache = null;
    protected array $page_structure_cache = [];
    protected array $housekeeping_handler_cache = [];

    public function __construct( Engine $app, TinyMashContentRepository $content_repository, ?TinyMashConfig $config = null, ?TinyMashThemeRegistry $theme_registry = null, ?TinyMashThemeSupportRegistry $support_registry = null, ?TinyMashUserRepository $user_repository = null, ?TinyMashMenuService $menu_service = null ) {
        $this->app = $app;
        $this->content_repository = $content_repository;
        $this->config = $config;
        $this->theme_registry = $theme_registry;
        $this->support_registry = $support_registry;
        $this->user_repository = $user_repository;
        $this->menu_service = $menu_service;
    }

    public function getBaseViewData( string $title, ?array $context_entry = null, ?string $context_author_slug = null ) : array {
        $page_context = $this->resolvePageContext( $context_entry, $context_author_slug );
        $theme_settings = $this->getThemeSettingsForAuthorContext(
            $page_context['scope'] === 'author' ? (string) ( $page_context['author_slug'] ?? '' ) : ''
        );
        $site_name = (string) $this->app->get( 'site.name' );
        $site_slogan = (string) $this->app->get( 'site.slogan' );
        $site_name = $this->replaceEmojiShortcodes( $site_name );
        $site_slogan = $this->replaceEmojiShortcodes( $site_slogan );
        $document_title = $this->buildDocumentTitle( $title, $context_entry, $context_author_slug, $site_name );
        $meta_description = $this->buildMetaDescription( $context_entry, $context_author_slug, $site_name, $site_slogan );
        $meta_image = $this->buildMetaImage( $context_entry );
        $resolved_background = $this->resolvePublicBackground( $page_context );
        $resolved_banner = $this->resolvePublicBanner( $page_context );
        $syntax_highlighting = $this->getSyntaxHighlightingViewData( $theme_settings );
        $tags = $this->getTagViewData( $theme_settings );
        $base_view_data = [
            'title' => $title,
            'document_title' => $document_title,
            'meta_title' => $document_title,
            'meta_description' => $meta_description,
            'meta_url' => $this->buildMetaUrl( $context_entry, $context_author_slug ),
            'meta_type' => is_array( $context_entry ) ? 'article' : 'website',
            'theme_meta_robots' => $this->getMetaRobotsDirectives( $page_context ),
            'meta_image' => $meta_image,
        ];
        $seo_view_data = $this->getPublicSeoViewData( $base_view_data, $context_entry, $page_context );
        $seo_view_data['site_name'] = $site_name;

        return(
            array_merge(
                $base_view_data,
                [
                    'document_title' => (string) ( $seo_view_data['document_title'] ?? $base_view_data['document_title'] ),
                    'meta_title' => (string) ( $seo_view_data['meta_title'] ?? $base_view_data['meta_title'] ),
                    'meta_description' => (string) ( $seo_view_data['meta_description'] ?? $base_view_data['meta_description'] ),
                    'meta_url' => (string) ( $seo_view_data['canonical_url'] ?? $base_view_data['meta_url'] ),
                    'meta_type' => (string) ( $seo_view_data['meta_type'] ?? $base_view_data['meta_type'] ),
                    'theme_meta_robots' => (string) ( $seo_view_data['robots'] ?? $base_view_data['theme_meta_robots'] ),
                    'meta_image' => is_array( $seo_view_data['meta_image'] ?? null ) ? $seo_view_data['meta_image'] : $base_view_data['meta_image'],
                ],
                [
                'is_home' => $title === 'Home',
                'app_url' => $this->app->get( 'app.url' ),
                'home_url' => '/',
                'site_name' => $site_name,
                'site_slogan' => $site_slogan,
                'site_banner_image' => $this->config instanceof TinyMashConfig ? $this->config->getSiteBannerImage() : [],
                'site_favicon_png_image' => $this->config instanceof TinyMashConfig ? $this->config->getSiteFaviconPngImage() : [],
                'site_favicon_ico_image' => $this->config instanceof TinyMashConfig ? $this->config->getSiteFaviconIcoImage() : [],
                'site_og_image' => $this->config instanceof TinyMashConfig ? $this->config->getSiteOgImage() : [],
                'site_default_language' => (string) $this->app->get( 'site.default_language' ),
                'theme_key' => $this->getThemeKey(),
                'theme_name' => $this->getThemeName(),
                'theme_template_root' => $this->getTemplateRoot(),
                'theme_css_urls' => $this->getCssUrls(),
                'theme_js_urls' => $this->getJsUrls(),
                'theme_settings' => $theme_settings,
                'theme_supports' => $this->getSupports(),
                'theme_support_details' => $this->getSupportDetails(),
                'theme_menu_locations' => $this->getMenuLocations(),
                'theme_sidebar_enabled' => $this->isSidebarEnabled( $context_entry ),
                'theme_navigation_pages' => $this->getNavigationPages(),
                'theme_navigation_tree' => $this->getNavigationTree(),
                'theme_navigation_menu' => $this->getNavigationMenu( $page_context['current_path'] ),
                'theme_sidebar_menu' => $this->getSidebarMenu( $page_context['current_path'], $theme_settings ),
                'theme_primary_menu_position' => $this->getPrimaryMenuPosition( $theme_settings ),
                'theme_footer_menu' => $this->getFooterMenu( $theme_settings ),
                'theme_footer_menu_position' => $this->getFooterMenuPosition( $theme_settings ),
                'theme_content_container_class' => $this->getContentContainerClass( $theme_settings ),
                'theme_content_width_class' => $this->getContentWidthClass( $theme_settings ),
                'theme_root_page_structure' => $this->getRootPageStructure(),
                'theme_page_structure' => $this->getPageStructure( $page_context['scope'], $page_context['author_slug'], $page_context['current_path'] ),
                'theme_page_context' => $page_context,
                'author_display_name' => $page_context['author_display_name'],
                'theme_body_classes' => $this->getBodyClasses( $page_context, $context_entry ),
                'theme_background' => $resolved_background,
                'theme_background_inline_style' => $this->buildBackgroundInlineStyle( $resolved_background ),
                'theme_background_preload_url' => $this->getBackgroundPreloadUrl( $resolved_background ),
                'theme_banner' => $resolved_banner,
                'theme_banner_preload_url' => $this->getBannerPreloadUrl( $resolved_banner ),
                'theme_breadcrumbs_enabled' => $this->isBreadcrumbsEnabled(),
                'theme_child_pages_enabled' => $this->isChildPagesSectionEnabled(),
                'theme_child_pages_heading' => $this->getChildPagesSectionHeading(),
                'theme_screen_cookie_name' => 'tinymashScreen',
                'theme_screen_mode' => $this->resolveScreenMode( $page_context ),
                'theme_public_sidebar_fragments' => $this->getPublicSidebarFragments( $context_entry, $context_author_slug ),
                'theme_public_footer_fragments' => $this->getPublicFooterFragments( $context_entry, $context_author_slug ),
                'theme_public_head_tags' => $this->config instanceof TinyMashConfig ? $this->config->getPublicHeadTags() : [],
                'theme_search' => $this->getPublicSearchViewData( $page_context['scope'] === 'author' ? (string) ( $page_context['author_slug'] ?? '' ) : '', $theme_settings ),
                'theme_syntax_highlighting' => $syntax_highlighting,
                'theme_tags' => $tags,
                'theme_docs_home_url' => $this->getDocsHomeUrl( $page_context['scope'], $page_context['author_slug'], $theme_settings ),
                'theme_seo' => $seo_view_data,
                'theme_seo_entry' => is_array( $seo_view_data['entry'] ?? null ) ? $seo_view_data['entry'] : [],
            ]
            )
        );
    }

    public function getThemeKey() : string {
        if ( $this->theme_key_override !== '' ) {
            return( $this->theme_key_override );
        }

        return( $this->getSiteThemeKey() );
    }

    public function getSiteThemeKey() : string {
        if ( $this->config instanceof TinyMashConfig ) {
            return( $this->config->getPublicThemeKey() );
        }

        return( 'baseline' );
    }

    public function setThemeKeyOverride( string $theme_key ) : void {
        $theme_key = $this->normalizeThemeKey( $theme_key );
        if ( $theme_key === '' || $theme_key === $this->getSiteThemeKey() ) {
            $this->theme_key_override = '';
            return;
        }

        $available_theme_keys = array_map(
            static fn( array $theme_definition ) : string => (string) ( $theme_definition['key'] ?? '' ),
            $this->getAvailableThemes()
        );
        $this->theme_key_override = in_array( $theme_key, $available_theme_keys, true ) ? $theme_key : '';
    }

    public function clearThemeKeyOverride() : void {
        $this->theme_key_override = '';
    }

    public function resolveThemeKeyForAuthorContext( string $author_slug = '' ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( $this->getSiteThemeKey() );
        }

        $theme_key = $this->user_repository->getPublicThemeKey( $author_slug );
        if ( $theme_key === '' || $theme_key === 'inherit' ) {
            return( $this->getSiteThemeKey() );
        }

        $available_theme_keys = array_map(
            static fn( array $theme_definition ) : string => (string) ( $theme_definition['key'] ?? '' ),
            $this->getAvailableThemes()
        );

        return( in_array( $theme_key, $available_theme_keys, true ) ? $theme_key : $this->getSiteThemeKey() );
    }

    public function getThemeName() : string {
        return( (string) ( $this->getThemeDefinition()['name'] ?? ucfirst( $this->getThemeKey() ) ) );
    }

    public function getTemplateRoot() : string {
        return( (string) ( $this->getThemeDefinition()['template_root'] ?? 'public/baseline' ) );
    }

    public function getTemplate( string $view_name ) : string {
        $view_name = strtolower( trim( $view_name ) );
        $theme_definition = $this->getThemeDefinition();
        if ( ! empty( $theme_definition['views'][$view_name] ) && is_string( $theme_definition['views'][$view_name] ) ) {
            return( (string) $theme_definition['views'][$view_name] );
        }

        return(
            match ( $view_name ) {
                'author-home' => $this->getTemplateRoot() . '/author.home.latte',
                'entry-post', 'entry-page' => $this->getTemplateRoot() . '/entry.latte',
                '404' => $this->getTemplateRoot() . '/404.latte',
                default => $this->getTemplateRoot() . '/home.latte',
            }
        );
    }

    public function getTemplateForEntry( array $entry ) : string {
        return( $this->getTemplate( (string) ( ( $entry['type'] ?? 'post' ) === 'page' ? 'entry-page' : 'entry-post' ) ) );
    }

    public function getCssUrls() : array {
        $css_urls = (array) ( $this->getThemeDefinition()['css_urls'] ?? [] );
        if ( ! empty( $this->getSyntaxHighlightingViewData()['enabled'] ) ) {
            $css_urls[] = '/plugins/syntax-highlighting/syntax-highlighting.css';
        }
        $css_urls = array_merge( $css_urls, $this->getPluginPublicAssetUrls( 'css' ) );
        $custom_css_url = $this->getCustomCssUrl();
        if ( $custom_css_url !== '' ) {
            $css_urls[] = $custom_css_url;
        }

        return( array_values( array_unique( $css_urls ) ) );
    }

    public function getCustomCssSettings( string $theme_key = '' ) : array {
        $theme_key = $theme_key !== '' ? $this->normalizeThemeKey( $theme_key ) : $this->getThemeKey();
        $settings = $this->config instanceof TinyMashConfig ? $this->config->getPublicThemeSettings( $theme_key ) : [];
        $css = (string) ( $settings['custom_css'] ?? '' );
        $css = str_replace( [ "\r\n", "\r" ], "\n", $css );

        return(
            [
                'enabled' => ! empty( $settings['custom_css_enabled'] ),
                'css' => $css,
                'hash' => sha1( $theme_key . "\n" . $css ),
            ]
        );
    }

    public function getCustomCssUrl( string $theme_key = '' ) : string {
        $theme_key = $theme_key !== '' ? $this->normalizeThemeKey( $theme_key ) : $this->getThemeKey();
        $custom_css = $this->getCustomCssSettings( $theme_key );
        if ( empty( $custom_css['enabled'] ) || trim( (string) ( $custom_css['css'] ?? '' ) ) === '' ) {
            return( '' );
        }

        return( '/theme-custom-css/' . rawurlencode( $theme_key ) . '?v=' . substr( (string) ( $custom_css['hash'] ?? '' ), 0, 12 ) );
    }

    public function getJsUrls() : array {
        $js_urls = (array) ( $this->getThemeDefinition()['js_urls'] ?? [] );
        if ( ! empty( $this->getSyntaxHighlightingViewData()['enabled'] ) ) {
            $js_urls[] = '/ext/highlightjs/highlight.min.js';
            $js_urls[] = '/plugins/syntax-highlighting/syntax-highlighting.js';
        }
        $js_urls = array_merge( $js_urls, $this->getPluginPublicAssetUrls( 'js' ) );

        return( array_values( array_unique( $js_urls ) ) );
    }

    public function getSupports() : array {
        $supports = is_array( $this->getThemeDefinition()['supports'] ?? null ) ? $this->getThemeDefinition()['supports'] : [];
        if ( $this->support_registry instanceof TinyMashThemeSupportRegistry ) {
            return( $this->support_registry->normalizeSupportFlags( $supports ) );
        }

        return( $supports );
    }

    public function getSupportDetails() : array {
        $supports = is_array( $this->getThemeDefinition()['supports'] ?? null ) ? $this->getThemeDefinition()['supports'] : [];
        if ( $this->support_registry instanceof TinyMashThemeSupportRegistry ) {
            return( $this->support_registry->resolveSupports( $supports ) );
        }

        return( [] );
    }

    public function getMenuLocations() : array {
        $primary_items = $this->getResolvedPrimaryMenu();
        $footer_items = $this->getFooterMenu();
        return(
            [
                'primary' => [
                    'label' => 'Primary',
                    'items' => $primary_items,
                ],
                'footer' => [
                    'label' => 'Footer',
                    'items' => $footer_items,
                ],
            ]
        );
    }

    public function areTagsEnabledForContext( string $author_slug = '' ) : bool {
        $normalized_author_slug = strtolower( trim( $author_slug ) );
        $theme_settings = $this->getThemeSettingsForAuthorContext( $normalized_author_slug );
        $tag_view_data = $this->getTagViewData( $theme_settings );
        return( ! empty( $tag_view_data['enabled'] ) );
    }

    public function getThemeSettingsSchema() : array {
        return( $this->getThemeSettingsSchemaForKey( $this->getThemeKey() ) );
    }

    public function getThemeSettingsSchemaForKey( string $theme_key = '' ) : array {
        $theme_definition = $theme_key !== '' ? $this->getThemeDefinitionForKey( $theme_key ) : $this->getThemeDefinition();
        $settings_schema = is_array( $theme_definition['settings_schema'] ?? null ) ? array_values( $theme_definition['settings_schema'] ) : [];
        $filtered_schema = [];

        foreach ( $settings_schema as $setting_definition ) {
            if ( ! is_array( $setting_definition ) ) {
                continue;
            }

            $required_plugin = strtolower( trim( (string) ( $setting_definition['requires_plugin'] ?? '' ) ) );
            if ( $required_plugin !== '' && ! $this->isPluginActive( $required_plugin ) ) {
                continue;
            }

            if ( (string) ( $setting_definition['key'] ?? '' ) === 'show_tags' && $this->config instanceof TinyMashConfig && ! $this->config->areTagsEnabled() ) {
                $setting_definition['disabled'] = true;
                $setting_definition['help'] = trim( (string) ( $setting_definition['help'] ?? '' ) . ' Tags are currently disabled sitewide.' );
            }

            $filtered_schema[] = $setting_definition;
        }

        return( $filtered_schema );
    }

    public function getThemeDescription() : string {
        return( trim( (string) ( $this->getThemeDefinition()['description'] ?? '' ) ) );
    }

    public function getThemeVersion() : string {
        return( trim( (string) ( $this->getThemeDefinition()['version'] ?? '' ) ) );
    }

    public function runHousekeepingTask( array $context = [] ) : array {
        $theme_definition = $this->getThemeDefinition();
        $theme_key = (string) ( $theme_definition['key'] ?? $this->getThemeKey() );
        $housekeeping_filename = trim( (string) ( $theme_definition['housekeeping_filename'] ?? '' ) );
        if ( $housekeeping_filename === '' ) {
            return( [] );
        }

        $handler = $this->resolveThemeHousekeepingHandler( $housekeeping_filename );
        if ( ! is_callable( $handler ) ) {
            return(
                [
                    'theme_key' => $theme_key,
                    'status' => 'error',
                    'message' => 'Theme housekeeping hook is not callable.',
                    'result' => null,
                ]
            );
        }

        try {
            $result = call_user_func( $handler, $context, $theme_definition, $this );
            return(
                [
                    'theme_key' => $theme_key,
                    'status' => 'ran',
                    'message' => is_string( $result ) ? trim( $result ) : '',
                    'result' => $result,
                ]
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Theme housekeeping hook failed for "' . $theme_key . '" (' . $e->getMessage() . ')' );
            return(
                [
                    'theme_key' => $theme_key,
                    'status' => 'error',
                    'message' => trim( $e->getMessage() ),
                    'result' => null,
                ]
            );
        }
    }

    public function getAvailableThemes() : array {
        $themes = [];
        if ( $this->theme_registry instanceof TinyMashThemeRegistry ) {
            $themes = $this->theme_registry->listPublicThemes();
        } else {
            $themes = [ $this->getThemeDefinition() ];
        }

        foreach ( $themes as $index => $theme_definition ) {
            if ( ! is_array( $theme_definition ) ) {
                continue;
            }
            $themes[$index]['support_details'] = $this->support_registry instanceof TinyMashThemeSupportRegistry
                ? $this->support_registry->resolveSupports( is_array( $theme_definition['supports'] ?? null ) ? $theme_definition['supports'] : [] )
                : [];
        }

        return( $themes );
    }

    public function getThemeSettings() : array {
        return( $this->getThemeSettingsForKey( $this->getThemeKey() ) );
    }

    public function getThemeSettingsForKey( string $theme_key = '' ) : array {
        $theme_key = $theme_key !== '' ? $this->normalizeThemeKey( $theme_key ) : $this->getThemeKey();
        $settings = [];
        foreach ( $this->getThemeSettingsSchemaForKey( $theme_key ) as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || empty( $setting_definition['key'] ) ) {
                continue;
            }
            $settings[(string) $setting_definition['key']] = $setting_definition['default'] ?? null;
        }

        if ( $this->config instanceof TinyMashConfig ) {
            foreach ( $this->config->getPublicThemeSettings( $theme_key ) as $setting_key => $setting_value ) {
                if ( is_string( $setting_key ) && array_key_exists( $setting_key, $settings ) ) {
                    $settings[$setting_key] = $setting_value;
                }
            }
        }

        return( $settings );
    }

    protected function normalizeThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?? '' );
    }

    public function getThemeSettingsForAuthorContext( string $author_slug = '' ) : array {
        $settings = $this->getThemeSettings();
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( $settings );
        }

        foreach ( $this->user_repository->getPublicThemeSettings( $author_slug, $this->getThemeKey() ) as $setting_key => $setting_value ) {
            if ( is_string( $setting_key ) && array_key_exists( $setting_key, $settings ) ) {
                $settings[$setting_key] = $setting_value;
            }
        }

        return( $settings );
    }

    public function getNavigationPages() : array {
        $navigation_pages = [];
        foreach ( $this->getResolvedPrimaryMenu() as $page ) {
            $navigation_pages[] = $page;
        }

        return( $navigation_pages );
    }

    public function getNavigationMenu( string $current_path = '' ) : array {
        $theme_settings = $this->getThemeSettings();
        if ( $this->getPrimaryMenuPosition( $theme_settings ) !== 'header' ) {
            return( [] );
        }

        return( $this->getResolvedPrimaryMenu( $current_path, $theme_settings ) );
    }

    public function getSidebarMenu( string $current_path = '', ?array $theme_settings = null ) : array {
        $resolved_theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        if ( $this->getPrimaryMenuPosition( $resolved_theme_settings ) !== 'sidebar' ) {
            return( [] );
        }

        if ( $this->menu_service instanceof TinyMashMenuService ) {
            return( $this->menu_service->getResolvedLocation( 'primary', $current_path ) );
        }

        return( [] );
    }

    public function getPrimaryMenuPosition( ?array $theme_settings = null ) : string {
        $supports = $this->getSupports();
        if ( empty( $supports['primary_menu'] ) ) {
            return( 'off' );
        }

        $resolved_theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $position = strtolower( trim( (string) ( $resolved_theme_settings['primary_menu_position'] ?? 'header' ) ) );
        return( in_array( $position, [ 'header', 'sidebar', 'off' ], true ) ? $position : 'header' );
    }

    public function getFooterMenuPosition( ?array $theme_settings = null ) : string {
        $supports = $this->getSupports();
        if ( empty( $supports['footer_menu'] ) ) {
            return( 'off' );
        }

        $resolved_theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $position = strtolower( trim( (string) ( $resolved_theme_settings['footer_menu_position'] ?? 'footer' ) ) );
        return( in_array( $position, [ 'footer', 'off' ], true ) ? $position : 'footer' );
    }

    protected function getResolvedPrimaryMenu( string $current_path = '', ?array $theme_settings = null ) : array {
        $resolved_theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        if ( $this->menu_service instanceof TinyMashMenuService ) {
            $configured_menu = $this->menu_service->getResolvedLocation( 'primary', $current_path );
            if ( ! empty( $configured_menu ) ) {
                return( $configured_menu );
            }
        }

        if ( (string) ( $resolved_theme_settings['page_navigation_position'] ?? '' ) === 'identity_panel' ) {
            return( [] );
        }

        if ( $this->isDocsMatterTheme() ) {
            return( [] );
        }

        if ( $this->getPrimaryMenuPosition( $resolved_theme_settings ) !== 'header' ) {
            return( [] );
        }

        $depth_setting = (string) ( $resolved_theme_settings['top_navigation_depth'] ?? '1' );
        if ( $depth_setting === 'disabled' ) {
            return( [] );
        }

        $max_depth = in_array( $depth_setting, [ '1', '2', '3' ], true ) ? (int) $depth_setting : 1;
        $cache_key = trim( $current_path, '/' ) . '|' . $max_depth;
        if ( array_key_exists( $cache_key, $this->navigation_menu_cache ) ) {
            return( $this->navigation_menu_cache[$cache_key] );
        }

        $this->navigation_menu_cache[$cache_key] = $this->limitTreeDepth(
            $this->buildTreeNodes( $this->content_repository->getRootNavigationPageTree(), trim( $current_path, '/' ) ),
            $max_depth
        );

        return( $this->navigation_menu_cache[$cache_key] );
    }

    public function getContentWidthSetting( ?array $theme_settings = null ) : string {
        $resolved_theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $setting = strtolower( trim( (string) ( $resolved_theme_settings['content_width'] ?? 'theme_default' ) ) );
        $setting = match ( $setting ) {
            'md' => 'compact',
            'lg' => 'standard',
            'xl' => 'wide',
            'xxl' => 'full',
            default => $setting,
        };
        return( in_array( $setting, [ 'theme_default', 'small', 'compact', 'reading', 'standard', 'wide', 'full' ], true ) ? $setting : 'theme_default' );
    }

    public function getContentContainerClass( ?array $theme_settings = null ) : string {
        return( 'container-fluid' );
    }

    public function getContentWidthClass( ?array $theme_settings = null ) : string {
        return(
            match ( $this->getContentWidthSetting( $theme_settings ) ) {
                'small' => 'tm-content-width-small',
                'compact' => 'tm-content-width-compact',
                'reading' => 'tm-content-width-reading',
                'standard' => 'tm-content-width-standard',
                'wide' => 'tm-content-width-wide',
                'full' => 'tm-content-width-full',
                default => 'tm-content-width-default',
            }
        );
    }

    public function getNavigationTree() : array {
        if ( is_array( $this->navigation_tree_cache ) ) {
            return( $this->navigation_tree_cache );
        }

        $this->navigation_tree_cache = $this->buildTreeNodes( $this->content_repository->getRootNavigationPageTree(), '' );
        return( $this->navigation_tree_cache );
    }

    public function getFooterMenu( ?array $theme_settings = null ) : array {
        if ( $this->getFooterMenuPosition( $theme_settings ) !== 'footer' ) {
            return( [] );
        }

        if ( $this->menu_service instanceof TinyMashMenuService ) {
            return( $this->menu_service->getResolvedLocation( 'footer', '' ) );
        }

        return( [] );
    }

    protected function resolveScreenMode( array $page_context = [] ) : string {
        $cookie_value = strtolower( trim( (string) ( $_COOKIE['tinymashScreen'] ?? '' ) ) );
        if ( in_array( $cookie_value, [ 'auto', 'light', 'dark' ], true ) ) {
            return( $cookie_value );
        }

        $author_slug = strtolower( trim( (string) ( $page_context['author_slug'] ?? '' ) ) );
        if ( $author_slug !== '' && $this->user_repository instanceof TinyMashUserRepository ) {
            $profile_screen_mode = $this->user_repository->getPublicScreenMode( $author_slug );
            if ( in_array( $profile_screen_mode, [ 'auto', 'light', 'dark' ], true ) ) {
                return( $profile_screen_mode );
            }
        }

        return( 'auto' );
    }

    protected function getPublicFooterFragments( ?array $context_entry = null, ?string $context_author_slug = null ) : array {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( [] );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! is_object( $plugins ) || ! method_exists( $plugins, 'renderPublicSlot' ) ) {
            return( [] );
        }

        $resolved_author_slug = '';
        if ( is_string( $context_author_slug ) && trim( $context_author_slug ) !== '' ) {
            $resolved_author_slug = strtolower( trim( $context_author_slug ) );
        } elseif ( is_array( $context_entry ) && (string) ( $context_entry['scope'] ?? '' ) === 'author' ) {
            $resolved_author_slug = strtolower( trim( (string) ( $context_entry['author_slug'] ?? '' ) ) );
        }

        $context = [
            'theme_key' => $this->getThemeKey(),
            'site_name' => (string) $this->app->get( 'site.name' ),
            'author_slug' => $resolved_author_slug,
            'entry' => $context_entry,
        ];

        $fragments = $plugins->renderPublicSlot( 'footer', $context );
        return( is_array( $fragments ) ? $fragments : [] );
    }

    protected function getPublicSidebarFragments( ?array $context_entry = null, ?string $context_author_slug = null ) : array {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( [] );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! is_object( $plugins ) || ! method_exists( $plugins, 'renderPublicSlot' ) ) {
            return( [] );
        }

        $resolved_author_slug = '';
        if ( is_string( $context_author_slug ) && trim( $context_author_slug ) !== '' ) {
            $resolved_author_slug = strtolower( trim( $context_author_slug ) );
        } elseif ( is_array( $context_entry ) && (string) ( $context_entry['scope'] ?? '' ) === 'author' ) {
            $resolved_author_slug = strtolower( trim( (string) ( $context_entry['author_slug'] ?? '' ) ) );
        }

        $context = [
            'theme_key' => $this->getThemeKey(),
            'site_name' => (string) $this->app->get( 'site.name' ),
            'author_slug' => $resolved_author_slug,
            'entry' => $context_entry,
        ];

        $fragments = $plugins->renderPublicSlot( 'sidebar', $context );
        return( is_array( $fragments ) ? $fragments : [] );
    }

    protected function getPublicSearchViewData( string $author_slug = '', array $theme_settings = [] ) : array {
        $search_settings = $this->app->has( 'public.search' ) ? $this->app->get( 'public.search' ) : [];
        if ( ! is_array( $search_settings ) || empty( $search_settings['enabled'] ) || empty( $search_settings['url'] ) ) {
            return(
                [
                    'enabled' => false,
                    'url' => '',
                    'query' => '',
                    'min_query_length' => 2,
                    'author_slug' => '',
                ]
            );
        }

        $theme_search_setting = strtolower( trim( (string) ( $theme_settings['show_search'] ?? 'enabled' ) ) );
        $theme_search_enabled = ! in_array( $theme_search_setting, [ 'disabled', 'off', 'false', '0', 'no' ], true );
        $query = function_exists( 'mb_trim' )
            ? mb_trim( (string) ( $_GET['q'] ?? '' ) )
            : trim( (string) ( $_GET['q'] ?? '' ) );

        return(
            [
                'enabled' => $theme_search_enabled,
                'url' => trim( (string) ( $search_settings['url'] ?? '' ) ),
                'query' => $query,
                'min_query_length' => max( 2, (int) ( $search_settings['min_query_length'] ?? 2 ) ),
                'author_slug' => strtolower( trim( $author_slug ) ),
            ]
        );
    }

    protected function getMetaRobotsDirectives( array $page_context ) : string {
        if ( $this->config instanceof TinyMashConfig && $this->config->discouragesSearchIndexing() ) {
            return( 'noindex, nofollow' );
        }

        $scope = strtolower( trim( (string) ( $page_context['scope'] ?? '' ) ) );
        $author_slug = strtolower( trim( (string) ( $page_context['author_slug'] ?? '' ) ) );
        if ( $scope === 'author' && $author_slug !== '' && $this->user_repository instanceof TinyMashUserRepository && $this->user_repository->discouragesSearchIndexing( $author_slug ) ) {
            return( 'noindex, nofollow' );
        }

        return( '' );
    }

    protected function getPublicSeoViewData( array $base_view_data = [], ?array $context_entry = null, array $page_context = [] ) : array {
        $seo_service = $this->app->has( 'public.seo.service' ) ? $this->app->get( 'public.seo.service' ) : null;
        if ( is_object( $seo_service ) && method_exists( $seo_service, 'resolvePageSeoData' ) ) {
            $seo_data = $seo_service->resolvePageSeoData( $base_view_data, $context_entry, $page_context );
            if ( is_array( $seo_data ) ) {
                return( $seo_data );
            }
        }

        $seo_settings = $this->app->has( 'public.seo' ) ? $this->app->get( 'public.seo' ) : [];
        if ( ! is_array( $seo_settings ) ) {
            return(
                [
                    'enabled' => false,
                    'robots_enabled' => false,
                    'sitemap_enabled' => false,
                    'disallow_ai_crawlers' => false,
                    'robots_url' => '',
                    'sitemap_url' => '',
                    'canonical_url' => '',
                    'robots' => (string) ( $base_view_data['theme_meta_robots'] ?? '' ),
                    'document_title' => (string) ( $base_view_data['document_title'] ?? ( $base_view_data['meta_title'] ?? '' ) ),
                    'meta_title' => (string) ( $base_view_data['meta_title'] ?? '' ),
                    'meta_description' => (string) ( $base_view_data['meta_description'] ?? '' ),
                    'meta_image' => is_array( $base_view_data['meta_image'] ?? null ) ? $base_view_data['meta_image'] : [],
                    'social_title' => (string) ( $base_view_data['meta_title'] ?? '' ),
                    'social_description' => (string) ( $base_view_data['meta_description'] ?? '' ),
                    'social_image' => is_array( $base_view_data['meta_image'] ?? null ) ? $base_view_data['meta_image'] : [],
                    'meta_type' => (string) ( $base_view_data['meta_type'] ?? '' ),
                    'og_type' => (string) ( $base_view_data['meta_type'] ?? '' ),
                    'site_name' => (string) $this->app->get( 'site.name' ),
                    'include_in_sitemap' => false,
                    'entry' => [],
                ]
            );
        }

        return(
            [
                'enabled' => ! empty( $seo_settings['enabled'] ),
                'robots_enabled' => ! empty( $seo_settings['robots_enabled'] ),
                'sitemap_enabled' => ! empty( $seo_settings['sitemap_enabled'] ),
                'disallow_ai_crawlers' => ! empty( $seo_settings['disallow_ai_crawlers'] ),
                'robots_url' => trim( (string) ( $seo_settings['robots_url'] ?? '' ) ),
                'sitemap_url' => trim( (string) ( $seo_settings['sitemap_url'] ?? '' ) ),
                'canonical_url' => (string) ( $base_view_data['meta_url'] ?? '' ),
                'robots' => (string) ( $base_view_data['theme_meta_robots'] ?? '' ),
                'document_title' => (string) ( $base_view_data['document_title'] ?? ( $base_view_data['meta_title'] ?? '' ) ),
                'meta_title' => (string) ( $base_view_data['meta_title'] ?? '' ),
                'meta_description' => (string) ( $base_view_data['meta_description'] ?? '' ),
                'meta_image' => is_array( $base_view_data['meta_image'] ?? null ) ? $base_view_data['meta_image'] : [],
                'social_title' => (string) ( $base_view_data['meta_title'] ?? '' ),
                'social_description' => (string) ( $base_view_data['meta_description'] ?? '' ),
                'social_image' => is_array( $base_view_data['meta_image'] ?? null ) ? $base_view_data['meta_image'] : [],
                'meta_type' => (string) ( $base_view_data['meta_type'] ?? '' ),
                'og_type' => (string) ( $base_view_data['meta_type'] ?? '' ),
                'site_name' => (string) $this->app->get( 'site.name' ),
                'include_in_sitemap' => false,
                'entry' => [],
            ]
        );
    }

    protected function replaceEmojiShortcodes( string $text ) : string {
        $markdown_renderer = $this->app->has( 'markdown.renderer' ) ? $this->app->get( 'markdown.renderer' ) : null;
        if ( ! $markdown_renderer instanceof TinyMashMarkdownRenderer ) {
            return( $text );
        }

        return( $markdown_renderer->replaceEmojiShortcodes( $text ) );
    }

    public function getRootPageStructure() : array {
        if ( is_array( $this->root_page_structure_cache ) ) {
            return( $this->root_page_structure_cache );
        }

        $this->root_page_structure_cache = $this->buildTreeNodes( $this->content_repository->getRootPublishedPageTree(), '' );
        return( $this->root_page_structure_cache );
    }

    public function getPageStructure( string $scope = 'root', string $author_slug = '', string $current_path = '' ) : array {
        $scope = strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root';
        $author_slug = strtolower( trim( $author_slug ) );
        $current_path = trim( $current_path, '/' );
        $cache_key = $scope . '|' . $author_slug . '|' . $current_path;
        if ( array_key_exists( $cache_key, $this->page_structure_cache ) ) {
            return( $this->page_structure_cache[$cache_key] );
        }

        $pages = $scope === 'author'
            ? $this->content_repository->getAuthorPublishedPageTree( $author_slug )
            : $this->content_repository->getRootPublishedPageTree();

        $this->page_structure_cache[$cache_key] = $this->buildTreeNodes( $pages, $current_path );
        return( $this->page_structure_cache[$cache_key] );
    }

    public function getEntryBreadcrumbs( array $entry ) : array {
        $breadcrumbs = [];
        foreach ( $this->content_repository->getEntryAncestors( $entry ) as $ancestor ) {
            $breadcrumbs[] = [
                'title' => $ancestor['title'],
                'url' => $this->getEntryURL( $ancestor ),
            ];
        }

        return( $breadcrumbs );
    }

    public function getChildPages( array $entry ) : array {
        $child_pages = [];
        foreach ( $this->content_repository->getChildPages( $entry ) as $child_page ) {
            $child_pages[] = [
                'title' => $child_page['title'],
                'url' => $this->getEntryURL( $child_page ),
            ];
        }

        return( $child_pages );
    }

    public function getLandingPagePath( ?array $theme_settings = null ) : string {
        $settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $path = trim( (string) ( $settings['landing_page_path'] ?? '' ) );
        if ( $path === '' ) {
            return( '' );
        }

        $segments = preg_split( '@/+@', trim( $path, " \t\n\r\0\x0B/" ) ) ?: [];
        $segments = array_values(
            array_filter(
                array_map( fn( mixed $segment ) : string => trim( (string) $segment ), $segments ),
                fn( string $segment ) : bool => $segment !== ''
            )
        );

        return( implode( '/', $segments ) );
    }

    public function getRootLandingMode( ?array $theme_settings = null ) : string {
        if ( $this->isDocsMatterTheme() ) {
            return( 'page' );
        }

        $settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $mode = strtolower( trim( (string) ( $settings['root_landing_mode'] ?? 'blogroll' ) ) );
        return( in_array( $mode, [ 'blogroll', 'page' ], true ) ? $mode : 'blogroll' );
    }

    public function getLandingEntry( string $scope = 'root', string $author_slug = '', ?string $language = null, ?array $theme_settings = null ) : ?array {
        $landing_page_path = $this->getLandingPagePath( $theme_settings );
        if ( $landing_page_path === '' && $this->isDocsMatterTheme() ) {
            $page_tree = strtolower( trim( $scope ) ) === 'author'
                ? $this->content_repository->getAuthorPublishedPageTree( strtolower( trim( $author_slug ) ), $language )
                : $this->content_repository->getRootPublishedPageTree( $language );
            $home_node = $this->resolveDocsHomeNode( $this->buildTreeNodes( $page_tree, '' ) );
            $landing_page_path = trim( (string) ( $home_node['path'] ?? '' ) );
        }
        if ( $landing_page_path === '' ) {
            return( null );
        }

        if ( strtolower( trim( $scope ) ) === 'author' ) {
            $author_slug = strtolower( trim( $author_slug ) );
            if ( $author_slug === '' ) {
                return( null );
            }

            $entry = $this->content_repository->getAuthorEntryByPath( $author_slug, $landing_page_path, $language );
        } else {
            $entry = $this->content_repository->getRootEntryByPath( $landing_page_path, $language );
        }

        if ( ! is_array( $entry ) || (string) ( $entry['type'] ?? '' ) !== 'page' ) {
            return( null );
        }

        return( $entry );
    }

    public function getDocsLandingPagePath( ?array $theme_settings = null ) : string {
        return( $this->getLandingPagePath( $theme_settings ) );
    }

    public function getDocsLandingEntry( string $scope = 'root', string $author_slug = '', ?string $language = null, ?array $theme_settings = null ) : ?array {
        return( $this->getLandingEntry( $scope, $author_slug, $language, $theme_settings ) );
    }

    public function getDocsPageNavigation( array $entry, ?string $language = null, ?array $theme_settings = null ) : array {
        if ( (string) ( $entry['type'] ?? '' ) !== 'page' ) {
            return(
                [
                    'home_url' => '',
                    'previous' => null,
                    'next' => null,
                ]
            );
        }

        $scope = (string) ( $entry['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
        $author_slug = $scope === 'author' ? strtolower( trim( (string) ( $entry['author_slug'] ?? '' ) ) ) : '';
        $page_tree = $scope === 'author'
            ? $this->content_repository->getAuthorPublishedPageTree( $author_slug, $language )
            : $this->content_repository->getRootPublishedPageTree( $language );
        $page_nodes = $this->flattenPageTreeNodes( $this->buildTreeNodes( $page_tree, '' ) );
        $current_path = $this->content_repository->getEntryPath( $entry );
        $current_index = null;
        foreach ( $page_nodes as $index => $page_node ) {
            if ( (string) ( $page_node['path'] ?? '' ) === $current_path ) {
                $current_index = $index;
                break;
            }
        }

        $home_url = $this->getDocsHomeUrl( $scope, $author_slug, $theme_settings, $language );
        if ( $current_index === null ) {
            return(
                [
                    'home_url' => $home_url,
                    'previous' => null,
                    'next' => null,
                ]
            );
        }

        $previous_page = $page_nodes[$current_index - 1] ?? null;
        $next_page = $page_nodes[$current_index + 1] ?? null;

        return(
            [
                'home_url' => $home_url,
                'previous' => is_array( $previous_page ) ? [ 'title' => (string) ( $previous_page['title'] ?? 'Previous page' ), 'url' => (string) ( $previous_page['url'] ?? '' ) ] : null,
                'next' => is_array( $next_page ) ? [ 'title' => (string) ( $next_page['title'] ?? 'Next page' ), 'url' => (string) ( $next_page['url'] ?? '' ) ] : null,
            ]
        );
    }

    public function getDocsHomeUrl( string $scope = 'root', string $author_slug = '', ?array $theme_settings = null, ?string $language = null ) : string {
        if ( ! $this->isDocsMatterTheme() ) {
            return( '/' );
        }

        $scope = strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root';
        $author_slug = $scope === 'author' ? strtolower( trim( $author_slug ) ) : '';
        if ( $this->getLandingPagePath( $theme_settings ) !== '' ) {
            $landing_entry = $this->getLandingEntry( $scope, $author_slug, $language, $theme_settings );
            if ( is_array( $landing_entry ) ) {
                return( $this->getEntryURL( $landing_entry ) );
            }
        }

        $page_tree = $scope === 'author'
            ? $this->content_repository->getAuthorPublishedPageTree( $author_slug, $language )
            : $this->content_repository->getRootPublishedPageTree( $language );
        $home_node = $this->resolveDocsHomeNode( $this->buildTreeNodes( $page_tree, '' ) );
        if ( is_array( $home_node ) && ! empty( $home_node['url'] ) ) {
            return( (string) $home_node['url'] );
        }

        $landing_entry = $this->getLandingEntry( $scope, $author_slug, $language, $theme_settings );
        if ( is_array( $landing_entry ) ) {
            return( $this->getEntryURL( $landing_entry ) );
        }

        return( $scope === 'author' && $author_slug !== '' ? $this->getAuthorURL( $author_slug ) : '/' );
    }

    public function getEntryURL( array $entry ) : string {
        $path = $this->content_repository->getEntryPath( $entry );
        if ( ! empty( $entry['scope'] ) && $entry['scope'] === 'author' && ! empty( $entry['author_slug'] ) ) {
            return( '/' . rawurlencode( (string) $entry['author_slug'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $path ) ) );
        }

        return( '/' . str_replace( '%2F', '/', rawurlencode( $path ) ) );
    }

    public function getAuthorURL( string $author_slug ) : string {
        return( '/' . rawurlencode( strtolower( trim( $author_slug ) ) ) );
    }

    public function getAuthorDisplayLabel( string $author_slug ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        if ( $author_slug === '' ) {
            return( '' );
        }

        if ( $this->user_repository instanceof TinyMashUserRepository ) {
            return( $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug ) );
        }

        return( $author_slug );
    }

    public function getBodyClasses( array $page_context = [], ?array $context_entry = null ) : string {
        $theme_key = $this->getThemeKey();
        $classes = [
            'tm-public',
            'tm-theme-' . $theme_key,
        ];

        if ( in_array( $theme_key, [ 'baseline', 'blocks', 'timeline', 'panel-magazine' ], true ) ) {
            $theme_settings = $this->getThemeSettings();
            $default_title_style = $theme_key === 'panel-magazine' ? 'serif' : 'sans-serif';
            $classes[] = (string) ( ( $theme_settings['publication_title_style'] ?? $default_title_style ) === 'serif' ? 'tm-titles-serif' : 'tm-titles-sans' );
        }

        if ( $theme_key === 'panel-magazine' ) {
            $theme_settings = $theme_settings ?? $this->getThemeSettings();
            $classes[] = (string) ( ( $theme_settings['panel_position'] ?? 'left' ) === 'right' ? 'tm-panel-right' : 'tm-panel-left' );
            $classes[] = (string) ( ( $theme_settings['compact_header'] ?? 'sticky' ) === 'static' ? 'tm-panel-compact-static' : 'tm-panel-compact-sticky' );
        }

        if ( ! empty( $page_context['scope'] ) && $page_context['scope'] === 'author' ) {
            $classes[] = 'tm-author-space';
        } else {
            $classes[] = 'tm-root-space';
        }

        if ( ! empty( $page_context['current_path'] ) ) {
            $classes[] = 'tm-single';
        } elseif ( ! empty( $page_context['scope'] ) && $page_context['scope'] === 'author' ) {
            $classes[] = 'tm-author-home';
        } else {
            $classes[] = 'tm-home';
        }

        if ( is_array( $context_entry ) ) {
            $classes[] = 'tm-entry';
            $classes[] = (string) ( ( $context_entry['type'] ?? 'post' ) === 'page' ? 'tm-entry-page' : 'tm-entry-post' );
        }

        $background = $this->resolvePublicBackground( $page_context );
        if ( ! empty( $background['enabled'] ) ) {
            $classes[] = 'tm-has-public-background';
            $classes[] = 'tm-public-background-' . (string) ( $background['scope'] ?? 'site' );
            $classes[] = 'tm-public-background-mode-' . (string) ( $background['render_mode'] ?? 'scaled' );
        }

        $banner = $this->resolvePublicBanner( $page_context );
        if ( ! empty( $banner['enabled'] ) ) {
            $classes[] = 'tm-has-public-banner';
            $classes[] = 'tm-public-banner-' . (string) ( $banner['scope'] ?? 'site' );
        }

        return( implode( ' ', $classes ) );
    }

    protected function resolvePublicBanner( array $page_context = [] ) : array {
        $site_banner = [
            'enabled' => false,
            'scope' => 'site',
            'image' => [],
        ];

        if ( $this->config instanceof TinyMashConfig ) {
            $site_image = $this->config->getSiteBannerImage();
            if ( ! empty( $site_image['url'] ) && ! empty( $site_image['media_id'] ) ) {
                $site_banner['enabled'] = true;
                $site_banner['image'] = $site_image;
            }
        }

        if ( (string) ( $page_context['scope'] ?? 'root' ) !== 'author' || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( $site_banner );
        }

        $author_slug = strtolower( trim( (string) ( $page_context['author_slug'] ?? '' ) ) );
        if ( $author_slug === '' ) {
            return( $site_banner );
        }

        $user = $this->user_repository->getUserByAuthorSlug( $author_slug );
        if ( ! is_array( $user ) ) {
            return( $site_banner );
        }

        $banner_source = strtolower( trim( (string) ( $user['public_banner_source'] ?? 'inherit' ) ) );
        if ( $banner_source === 'none' ) {
            return(
                [
                    'enabled' => false,
                    'scope' => 'author',
                    'image' => [],
                ]
            );
        }

        if ( $banner_source === 'custom' ) {
            $media_id = trim( (string) ( $user['public_banner_media_id'] ?? '' ) );
            $image = $this->resolvePublicBackgroundMediaImage( $media_id, [ $author_slug ] );
            return(
                [
                    'enabled' => ! empty( $image['url'] ),
                    'scope' => 'author',
                    'image' => ! empty( $image['url'] ) ? $image : [],
                ]
            );
        }

        return( $site_banner );
    }

    protected function resolvePublicBackground( array $page_context = [] ) : array {
        if ( ! $this->themeSupportsPublicBackground() ) {
            return( $this->getEmptyPublicBackground() );
        }

        $site_background = [
            'enabled' => false,
            'scope' => 'site',
            'render_mode' => $this->config instanceof TinyMashConfig ? $this->config->getSiteBackgroundRenderMode() : 'scaled',
            'fixed' => true,
            'image' => [],
        ];

        if ( $this->config instanceof TinyMashConfig ) {
            $site_image = $this->config->getSiteBackgroundImage();
            if ( ! empty( $site_image['url'] ) ) {
                $site_background['enabled'] = true;
                $site_background['image'] = $site_image;
            }
        }

        if ( (string) ( $page_context['scope'] ?? 'root' ) !== 'author' || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( $site_background );
        }

        $author_slug = strtolower( trim( (string) ( $page_context['author_slug'] ?? '' ) ) );
        if ( $author_slug === '' ) {
            return( $site_background );
        }

        $user = $this->user_repository->getUserByAuthorSlug( $author_slug );
        if ( ! is_array( $user ) ) {
            return( $site_background );
        }

        $background_source = strtolower( trim( (string) ( $user['public_background_source'] ?? 'inherit' ) ) );
        if ( $background_source === 'none' ) {
            return(
                [
                    'enabled' => false,
                    'scope' => 'author',
                    'render_mode' => $this->normalizeBackgroundRenderMode( (string) ( $user['public_background_render_mode'] ?? 'scaled' ) ),
                    'fixed' => true,
                    'image' => [],
                ]
            );
        }

        if ( $background_source === 'custom' ) {
            $media_id = trim( (string) ( $user['public_background_media_id'] ?? '' ) );
            $image = $this->resolvePublicBackgroundMediaImage( $media_id, [ $author_slug ] );
            if ( ! empty( $image['url'] ) ) {
                return(
                    [
                        'enabled' => true,
                        'scope' => 'author',
                        'render_mode' => $this->normalizeBackgroundRenderMode( (string) ( $user['public_background_render_mode'] ?? 'scaled' ) ),
                        'fixed' => true,
                        'image' => $image,
                    ]
                );
            }

            return(
                [
                    'enabled' => false,
                    'scope' => 'author',
                    'render_mode' => $this->normalizeBackgroundRenderMode( (string) ( $user['public_background_render_mode'] ?? 'scaled' ) ),
                    'fixed' => true,
                    'image' => [],
                ]
            );
        }

        return( $site_background );
    }

    protected function themeSupportsPublicBackground() : bool {
        $supports = $this->getSupports();
        return( ! empty( $supports['public_background'] ) );
    }

    protected function getEmptyPublicBackground() : array {
        return(
            [
                'enabled' => false,
                'scope' => 'theme',
                'render_mode' => 'scaled',
                'fixed' => true,
                'image' => [],
            ]
        );
    }

    protected function resolvePublicBackgroundMediaImage( string $media_id, array $owner_usernames = [] ) : array {
        $media_id = trim( $media_id );
        if ( $media_id === '' || ! $this->app->has( 'media.service' ) ) {
            return( [] );
        }

        $media_service = $this->app->get( 'media.service' );
        if ( ! is_object( $media_service ) || ! method_exists( $media_service, 'getAttachmentMetadataByMediaId' ) ) {
            return( [] );
        }

        $metadata = $media_service->getAttachmentMetadataByMediaId( $media_id, $owner_usernames );
        if ( ! is_array( $metadata ) ) {
            return( [] );
        }

        return(
            [
                'media_id' => trim( (string) ( $metadata['media_id'] ?? '' ) ),
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'filename' => basename( trim( (string) ( $metadata['filename'] ?? '' ) ) ),
                'url' => trim( (string) ( $metadata['url'] ?? '' ) ),
                'alt_text' => trim( (string) ( $metadata['alt_text'] ?? '' ) ),
                'mime' => trim( (string) ( $metadata['mime'] ?? '' ) ),
                'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
                'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
                'derivative_key' => trim( (string) ( $metadata['derivative_key'] ?? '' ) ),
            ]
        );
    }

    protected function buildBackgroundInlineStyle( array $background ) : string {
        if ( empty( $background['enabled'] ) || ! is_array( $background['image'] ?? null ) ) {
            return( '' );
        }

        $image_url = trim( (string) ( $background['image']['url'] ?? '' ) );
        if ( $image_url === '' ) {
            return( '' );
        }

        $render_mode = $this->normalizeBackgroundRenderMode( (string) ( $background['render_mode'] ?? 'scaled' ) );
        $position = 'left top';
        $repeat = 'no-repeat';
        $size = 'auto';

        if ( $render_mode === 'centered' ) {
            $position = 'center center';
        } elseif ( $render_mode === 'scaled' ) {
            $position = 'center center';
            $size = 'cover';
        } elseif ( $render_mode === 'tiled' ) {
            $repeat = 'repeat';
        }

        return(
            "background-image: url('"
            . str_replace( [ "'", "\n", "\r" ], [ "\\'", '', '' ], $image_url )
            . "'); background-position: "
            . $position
            . '; background-repeat: '
            . $repeat
            . '; background-size: '
            . $size
            . '; background-attachment: fixed;'
        );
    }

    protected function getBackgroundPreloadUrl( array $background ) : string {
        if ( empty( $background['enabled'] ) || ! is_array( $background['image'] ?? null ) ) {
            return( '' );
        }

        return( trim( (string) ( $background['image']['url'] ?? '' ) ) );
    }

    protected function getBannerPreloadUrl( array $banner ) : string {
        if ( empty( $banner['enabled'] ) || ! is_array( $banner['image'] ?? null ) ) {
            return( '' );
        }

        return( trim( (string) ( $banner['image']['url'] ?? '' ) ) );
    }

    protected function normalizeBackgroundRenderMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( in_array( $mode, [ 'as_is', 'centered', 'scaled', 'tiled' ], true ) ? $mode : 'scaled' );
    }

    public function isSidebarEnabled( ?array $context_entry = null ) : bool {
        if ( (string) ( $this->getThemeSettings()['secondary_sidebar'] ?? 'enabled' ) === 'disabled' ) {
            return( false );
        }

        if ( $this->getPrimaryMenuPosition( $this->getThemeSettings() ) === 'sidebar' ) {
            return( true );
        }

        if ( (string) ( $this->getThemeSettings()['page_sidebar_style'] ?? 'flat' ) === 'disabled' ) {
            return( false );
        }

        if ( is_array( $context_entry ) && (string) ( $context_entry['type'] ?? '' ) === 'post' ) {
            return( false );
        }

        return( true );
    }

    public function getPostSummaryFallbackMode() : string {
        $mode = strtolower( trim( (string) ( $this->getThemeSettings()['post_summary_fallback'] ?? 'words' ) ) );
        return( in_array( $mode, [ 'words', 'full', 'title' ], true ) ? $mode : 'words' );
    }

    public function getPostSummaryFallbackWordCount() : int {
        $word_count = (int) trim( (string) ( $this->getThemeSettings()['post_summary_fallback_words'] ?? '40' ) );
        if ( $word_count < 10 ) {
            return( 40 );
        }
        if ( $word_count > 200 ) {
            return( 200 );
        }

        return( $word_count );
    }

    public function isBreadcrumbsEnabled() : bool {
        if ( $this->isDocsMatterTheme() ) {
            return( false );
        }

        return( (string) ( $this->getThemeSettings()['page_breadcrumbs'] ?? 'enabled' ) !== 'disabled' );
    }

    public function isChildPagesSectionEnabled() : bool {
        return( (string) ( $this->getThemeSettings()['page_children_after_content'] ?? 'more' ) !== 'disabled' );
    }

    public function getChildPagesSectionHeading() : string {
        return(
            match ( (string) ( $this->getThemeSettings()['page_children_after_content'] ?? 'more' ) ) {
                'subpages' => 'Sub pages',
                default => 'More',
            }
        );
    }

    protected function buildTreeNodes( array $pages, string $current_path = '' ) : array {
        $tree = [];
        foreach ( $pages as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }
            $path = (string) ( $page['path'] ?? $this->content_repository->getEntryPath( $page ) );
            $children = $this->buildTreeNodes( $page['children'] ?? [], $current_path );
            $tree[] = [
                'id' => (string) ( $page['id'] ?? '' ),
                'title' => $page['title'],
                'slug' => (string) ( $page['slug'] ?? '' ),
                'path' => $path,
                'url' => $this->getEntryURL( $page ),
                'show_in_navigation' => ! empty( $page['show_in_navigation'] ),
                'is_current' => $current_path !== '' && $path === $current_path,
                'is_ancestor' => $current_path !== '' && $path !== '' && $path !== $current_path && str_starts_with( $current_path, $path . '/' ),
                'children' => $children,
            ];
        }

        return( $tree );
    }

    protected function limitTreeDepth( array $pages, int $max_depth, int $current_depth = 1 ) : array {
        $limited_pages = [];
        foreach ( $pages as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            $limited_page = $page;
            if ( $current_depth >= $max_depth ) {
                $limited_page['children'] = [];
            } else {
                $limited_page['children'] = $this->limitTreeDepth( (array) ( $page['children'] ?? [] ), $max_depth, $current_depth + 1 );
            }

            $limited_pages[] = $limited_page;
        }

        return( $limited_pages );
    }

    protected function flattenPageTreeNodes( array $pages ) : array {
        $flat = [];
        foreach ( $pages as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }

            $flat[] = $page;
            if ( ! empty( $page['children'] ) ) {
                foreach ( $this->flattenPageTreeNodes( (array) $page['children'] ) as $child_page ) {
                    $flat[] = $child_page;
                }
            }
        }

        return( $flat );
    }

    protected function resolvePageContext( ?array $context_entry = null, ?string $context_author_slug = null ) : array {
        $scope = 'root';
        $author_slug = '';
        $current_path = '';

        if ( is_array( $context_entry ) ) {
            if ( (string) ( $context_entry['scope'] ?? 'root' ) === 'author' ) {
                $scope = 'author';
                $author_slug = strtolower( trim( (string) ( $context_entry['author_slug'] ?? '' ) ) );
            }
            if ( (string) ( $context_entry['type'] ?? '' ) === 'page' ) {
                $current_path = $this->content_repository->getEntryPath( $context_entry );
            }
        } elseif ( is_string( $context_author_slug ) && trim( $context_author_slug ) !== '' ) {
            $scope = 'author';
            $author_slug = strtolower( trim( $context_author_slug ) );
        }

        return(
            [
                'scope' => $scope,
                'author_slug' => $author_slug,
                'author_display_name' => $this->getAuthorDisplayLabel( $author_slug ),
                'current_path' => trim( $current_path, '/' ),
            ]
        );
    }

    protected function resolveDocsHomeNode( array $page_nodes ) : ?array {
        foreach ( $page_nodes as $page_node ) {
            if ( ! is_array( $page_node ) ) {
                continue;
            }

            $children = is_array( $page_node['children'] ?? null ) ? $page_node['children'] : [];
            if ( ! empty( $children ) ) {
                $child_node = $this->resolveDocsHomeNode( $children );
                if ( is_array( $child_node ) ) {
                    return( $child_node );
                }
            }

            if ( ! empty( $page_node['url'] ) ) {
                return( $page_node );
            }
        }

        return( null );
    }

    protected function isDocsMatterTheme() : bool {
        return( $this->getThemeKey() === 'docsmatter' );
    }

    protected function isPluginActive( string $plugin_key ) : bool {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( false );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! is_object( $plugins ) || ! method_exists( $plugins, 'getPlugin' ) ) {
            return( false );
        }

        $plugin = $plugins->getPlugin( $plugin_key );
        return( is_array( $plugin ) && ! empty( $plugin['active'] ) );
    }

    protected function getPluginPublicAssetUrls( string $type ) : array {
        if ( ! $this->app->has( 'plugins' ) ) {
            return( [] );
        }

        $plugins = $this->app->get( 'plugins' );
        if ( ! is_object( $plugins ) || ! method_exists( $plugins, 'getPublicAssetUrls' ) ) {
            return( [] );
        }

        $urls = $plugins->getPublicAssetUrls( $type );
        return( is_array( $urls ) ? $urls : [] );
    }

    protected function getSyntaxHighlightingViewData( array $theme_settings = [] ) : array {
        $plugin_active = $this->isPluginActive( 'syntax-highlighting' );
        $setting = strtolower( trim( (string) ( $theme_settings['enable_syntax_highlighting'] ?? ( $this->getThemeSettings()['enable_syntax_highlighting'] ?? 'enabled' ) ) ) );
        $theme_enabled = ! in_array( $setting, [ 'disabled', 'off', 'false', '0', 'no' ], true );
        $enabled = $plugin_active && $theme_enabled;

        return(
            [
                'plugin_active' => $plugin_active,
                'enabled' => $enabled,
                'language_labels' => $enabled,
                'language_label_class' => 'tm-syntax-language-label',
            ]
        );
    }

    protected function getTagViewData( array $theme_settings = [] ) : array {
        $system_enabled = $this->config instanceof TinyMashConfig ? $this->config->areTagsEnabled() : true;
        $setting = strtolower( trim( (string) ( $theme_settings['show_tags'] ?? ( $this->getThemeSettings()['show_tags'] ?? 'enabled' ) ) ) );
        $theme_enabled = ! in_array( $setting, [ 'disabled', 'off', 'false', '0', 'no' ], true );

        return(
            [
                'system_enabled' => $system_enabled,
                'enabled' => $system_enabled && $theme_enabled,
            ]
        );
    }

    public function getBlocksListingViewData( array $entries, ?array $theme_settings = null ) : array {
        $theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $featured_limit = $this->getBlocksFeaturedRowLimit( $theme_settings );
        $general_limit = $this->getBlocksGeneralGridLimit( $theme_settings );
        $featured_entries = [];
        $ordinary_entries = [];

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $qualifies_for_featured_row = ! empty( $entry['sticky'] ) || ! empty( $entry['featured'] );
            if ( $qualifies_for_featured_row && $featured_limit > 0 && count( $featured_entries ) < $featured_limit ) {
                $featured_entries[] = $this->decorateBlocksEntry( $entry, $theme_settings );
                continue;
            }

            $ordinary_entries[] = $this->decorateBlocksEntry( $entry, $theme_settings );
        }

        $featured_columns = $this->getBlocksDisplayColumnCount( $featured_limit, count( $featured_entries ), true );

        return(
            [
                'blocks_featured_entries' => $featured_entries,
                'blocks_regular_entries' => $ordinary_entries,
                'blocks_featured_columns' => $featured_columns,
                'blocks_general_columns' => max( 1, $general_limit ),
            ]
        );
    }

    public function getPanelMagazineListingViewData( array $entries, ?array $theme_settings = null ) : array {
        $theme_settings = is_array( $theme_settings ) ? $theme_settings : $this->getThemeSettings();
        $layout = strtolower( trim( (string) ( $theme_settings['post_list_layout'] ?? 'magazine' ) ) );
        $layout = $layout === 'stream' ? 'stream' : 'magazine';
        $candidate_entries = array_values( array_filter( $entries, 'is_array' ) );
        $lead_index = 0;

        foreach ( $candidate_entries as $index => $entry ) {
            if ( ! empty( $entry['sticky'] ) ) {
                $lead_index = $index;
                break;
            }
        }

        $lead_entry = $candidate_entries[$lead_index] ?? null;
        if ( is_array( $lead_entry ) ) {
            unset( $candidate_entries[$lead_index] );
        }
        $candidate_entries = array_values( $candidate_entries );

        return(
            [
                'panel_magazine_list_layout' => $layout,
                'panel_magazine_lead_entry' => $lead_entry,
                'panel_magazine_secondary_entries' => array_slice( $candidate_entries, 0, 2 ),
                'panel_magazine_stream_entries' => array_slice( $candidate_entries, 2 ),
            ]
        );
    }

    protected function decorateBlocksEntry( array $entry, array $theme_settings ) : array {
        $entry['blocks_cover_color'] = $this->resolveBlocksCoverColor( $entry, $theme_settings );
        $entry['blocks_cover_style'] = preg_match( '/^#[0-9a-f]{6}$/', (string) $entry['blocks_cover_color'] ) === 1
            ? 'background-color: ' . $this->convertHexColorToRgba( (string) $entry['blocks_cover_color'], 0.90 ) . '; --tm-blocks-cover-frame: ' . (string) $entry['blocks_cover_color'] . ';'
            : '';
        return( $entry );
    }

    protected function resolveBlocksCoverColor( array $entry, array $theme_settings ) : string {
        $entry_color = strtolower( trim( (string) ( $entry['fallback_cover_color'] ?? '' ) ) );
        if (
            in_array( $entry_color, [ 'slate', 'blue', 'green', 'amber', 'berry' ], true ) ||
            preg_match( '/^#[0-9a-f]{6}$/', $entry_color ) === 1
        ) {
            return( $entry_color );
        }

        $theme_color = strtolower( trim( (string) ( $theme_settings['fallback_cover_palette'] ?? 'slate' ) ) );
        return( in_array( $theme_color, [ 'slate', 'blue', 'green', 'amber', 'berry' ], true ) ? $theme_color : 'slate' );
    }

    protected function convertHexColorToRgba( string $hex_color, float $alpha ) : string {
        $hex_color = strtolower( trim( $hex_color ) );
        if ( preg_match( '/^#([0-9a-f]{6})$/', $hex_color, $matches ) !== 1 ) {
            return( 'rgba(108, 117, 125, 0.90)' );
        }

        $hex = $matches[1];
        $red = hexdec( substr( $hex, 0, 2 ) );
        $green = hexdec( substr( $hex, 2, 2 ) );
        $blue = hexdec( substr( $hex, 4, 2 ) );
        $alpha = min( 1, max( 0, $alpha ) );

        return( sprintf( 'rgba(%d, %d, %d, %.2F)', $red, $green, $blue, $alpha ) );
    }

    protected function getBlocksGeneralGridLimit( array $theme_settings = [] ) : int {
        $raw_value = trim( (string) ( $theme_settings['general_grid_blocks'] ?? ( $this->getThemeSettings()['general_grid_blocks'] ?? '0' ) ) );
        $grid_limit = (int) $raw_value;
        if ( $grid_limit < 0 || $grid_limit > 10 ) {
            return( 3 );
        }

        return( $grid_limit === 0 ? 3 : $grid_limit );
    }

    protected function getBlocksFeaturedRowLimit( array $theme_settings = [] ) : int {
        $raw_value = trim( (string) ( $theme_settings['featured_blocks'] ?? ( $this->getThemeSettings()['featured_blocks'] ?? '2' ) ) );
        $featured_limit = (int) $raw_value;
        if ( $featured_limit < 0 || $featured_limit > 10 ) {
            return( 2 );
        }

        return( $featured_limit );
    }

    protected function getBlocksDisplayColumnCount( int $configured_limit, int $entry_count, bool $allow_zero = false ) : int {
        if ( $allow_zero && $configured_limit === 0 ) {
            return( 0 );
        }

        if ( $entry_count <= 0 ) {
            return( max( 1, $configured_limit ) );
        }

        if ( $configured_limit <= 0 ) {
            return( $allow_zero ? $entry_count : min( 3, $entry_count ) );
        }

        return( max( 1, min( $configured_limit, $entry_count ) ) );
    }

    protected function buildDocumentTitle( string $title, ?array $context_entry, ?string $context_author_slug, string $site_name ) : string {
        $title = $this->normalizeTitlePart( $title );
        $site_name = $this->normalizeTitlePart( $site_name );
        $context_author_slug = strtolower( trim( (string) $context_author_slug ) );
        $author_slug = $context_author_slug;

        if ( is_array( $context_entry ) ) {
            if ( (string) ( $context_entry['scope'] ?? 'root' ) === 'author' ) {
                $author_slug = strtolower( trim( (string) ( $context_entry['author_slug'] ?? '' ) ) );
            }

            $entry_title = $this->normalizeTitlePart( (string) ( $context_entry['title'] ?? '' ) );
            if ( $entry_title !== '' ) {
                $title = $entry_title;
            }
        }

        $author_label = $author_slug !== '' ? $this->normalizeTitlePart( $this->getAuthorDisplayLabel( $author_slug ) ) : '';
        $normalized_title = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
        if ( $title === '' || $normalized_title === 'home' ) {
            return( $this->joinDocumentTitleParts( $author_label !== '' ? [ $author_label, $site_name ] : [ $site_name ] ) );
        }

        return( $this->joinDocumentTitleParts( $author_label !== '' ? [ $title, $author_label, $site_name ] : [ $title, $site_name ] ) );
    }

    protected function normalizeTitlePart( string $title_part ) : string {
        return( function_exists( 'mb_trim' ) ? mb_trim( $title_part ) : trim( $title_part ) );
    }

    protected function joinDocumentTitleParts( array $parts ) : string {
        $clean_parts = [];
        $previous_part = '';
        foreach ( $parts as $part ) {
            $part = $this->normalizeTitlePart( (string) $part );
            if ( $part === '' ) {
                continue;
            }
            $previous_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $previous_part ) : strtolower( $previous_part );
            $part_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part ) : strtolower( $part );
            if ( $previous_part !== '' && $previous_key === $part_key ) {
                continue;
            }
            $clean_parts[] = $part;
            $previous_part = $part;
        }

        return( implode( ' - ', $clean_parts ) );
    }

    protected function buildMetaDescription( ?array $context_entry, ?string $context_author_slug, string $site_name, string $site_slogan ) : string {
        $context_author_slug = strtolower( trim( (string) $context_author_slug ) );
        if ( is_array( $context_entry ) ) {
            $summary = $this->sanitizeMetaText( (string) ( $context_entry['summary'] ?? '' ) );
            if ( $summary !== '' ) {
                return( $summary );
            }

            if ( $site_slogan !== '' ) {
                return( $site_slogan );
            }

            return( trim( (string) ( $context_entry['title'] ?? '' ) ) );
        }
        if ( $context_author_slug !== '' ) {
            $author_label = $this->getAuthorDisplayLabel( $context_author_slug );
            if ( $site_slogan !== '' ) {
                return( $author_label . ' on ' . $site_name . '. ' . $site_slogan );
            }

            return( 'Published content by ' . $author_label . ' on ' . $site_name . '.' );
        }

        return( $this->sanitizeMetaText( $site_slogan !== '' ? $site_slogan : $site_name ) );
    }

    protected function buildMetaUrl( ?array $context_entry, ?string $context_author_slug ) : string {
        $base_url = rtrim( (string) $this->app->get( 'app.url' ), '/' );
        if ( $base_url === '' ) {
            return( '' );
        }
        if ( is_array( $context_entry ) ) {
            return( $base_url . $this->getEntryURL( $context_entry ) );
        }

        $context_author_slug = strtolower( trim( (string) $context_author_slug ) );
        if ( $context_author_slug !== '' ) {
            return( $base_url . $this->getAuthorURL( $context_author_slug ) );
        }

        return( $base_url . '/' );
    }

    protected function buildMetaImage( ?array $context_entry ) : array {
        if ( is_array( $context_entry ) ) {
            $featured_image = $this->normalizeSiteImageValue( $context_entry['featured_image'] ?? [] );
            if ( ! empty( $featured_image ) ) {
                return( $this->absolutizeSiteImageValue( $featured_image ) );
            }
        }

        if ( $this->config instanceof TinyMashConfig ) {
            return( $this->absolutizeSiteImageValue( $this->config->getSiteOgImage() ) );
        }

        return( [] );
    }

    protected function absolutizeSiteImageValue( array $site_image ) : array {
        if ( empty( $site_image['url'] ) || ! is_string( $site_image['url'] ) ) {
            return( $site_image );
        }

        $url = trim( $site_image['url'] );
        if ( $url === '' || preg_match( '#^https?://#i', $url ) === 1 ) {
            return( $site_image );
        }

        $base_url = rtrim( (string) $this->app->get( 'app.url' ), '/' );
        if ( $base_url === '' || ! str_starts_with( $url, '/' ) ) {
            return( $site_image );
        }

        $site_image['url'] = $base_url . $url;
        return( $site_image );
    }

    protected function sanitizeMetaText( string $value ) : string {
        $value = preg_replace( '/```.*?```/su', ' ', $value ) ?? $value;
        $value = preg_replace( '/`[^`]*`/u', ' ', $value ) ?? $value;
        $value = preg_replace( '/!\[([^\]]*)\]\([^)]+\)/u', '$1', $value ) ?? $value;
        $value = preg_replace( '/\[([^\]]+)\]\([^)]+\)/u', '$1', $value ) ?? $value;
        $value = preg_replace( '/[*_~#>]+/u', ' ', $value ) ?? $value;
        $value = strip_tags( $value );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
        if ( mb_strlen( $value ) > 180 ) {
            $value = rtrim( mb_substr( $value, 0, 177 ) ) . '...';
        }

        return( $value );
    }

    protected function normalizeSiteImageValue( mixed $site_image ) : array {
        if ( ! is_array( $site_image ) ) {
            return( [] );
        }

        $normalized_site_image = [
            'media_id' => trim( (string) ( $site_image['media_id'] ?? '' ) ),
            'owner_username' => strtolower( trim( (string) ( $site_image['owner_username'] ?? '' ) ) ),
            'filename' => basename( trim( (string) ( $site_image['filename'] ?? '' ) ) ),
            'url' => trim( (string) ( $site_image['url'] ?? '' ) ),
            'alt_text' => trim( (string) ( $site_image['alt_text'] ?? '' ) ),
            'mime' => trim( (string) ( $site_image['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $site_image['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $site_image['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $site_image['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $site_image['derivative_key'] ?? '' ) ),
        ];

        if ( $normalized_site_image['url'] === '' ) {
            return( [] );
        }

        return( $normalized_site_image );
    }

    protected function getThemeDefinition() : array {
        return( $this->getThemeDefinitionForKey( $this->getThemeKey() ) );
    }

    protected function getThemeDefinitionForKey( string $theme_key ) : array {
        $theme_key = $this->normalizeThemeKey( $theme_key );
        if ( $this->theme_registry instanceof TinyMashThemeRegistry ) {
            return( $this->theme_registry->getPublicTheme( $theme_key !== '' ? $theme_key : 'baseline' ) );
        }

        return(
            [
                'key' => 'baseline',
                'name' => 'Baseline',
                'template_root' => 'public/baseline',
                'views' => [
                    'home' => 'public/baseline/home.latte',
                    'author-home' => 'public/baseline/author.home.latte',
                    'entry-post' => 'public/baseline/entry.latte',
                    'entry-page' => 'public/baseline/entry.latte',
                    '404' => 'public/baseline/404.latte',
                ],
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
                    'primary_menu' => true,
                    'sidebar_menu' => true,
                    'breadcrumbs' => true,
                    'page_sidebar' => true,
                    'after_content_child_pages' => true,
                    'footer_menu' => true,
                    'public_background' => true,
                ],
                'settings_schema' => [],
            ]
        );
    }

    protected function resolveThemeHousekeepingHandler( string $housekeeping_filename ) : ?callable {
        if ( array_key_exists( $housekeeping_filename, $this->housekeeping_handler_cache ) ) {
            return( $this->housekeeping_handler_cache[$housekeeping_filename] );
        }

        $handler = include $housekeeping_filename;
        $this->housekeeping_handler_cache[$housekeeping_filename] = is_callable( $handler ) ? $handler : null;
        return( $this->housekeeping_handler_cache[$housekeeping_filename] );
    }

}// TinyMashTheme
