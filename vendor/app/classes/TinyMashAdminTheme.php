<?php
namespace app\classes;

class TinyMashAdminTheme {

    protected string $theme_key;
    protected ?TinyMashThemeRegistry $theme_registry;
    protected ?TinyMashConfig $config;

    public function __construct( string $theme_key = 'baseline', ?TinyMashThemeRegistry $theme_registry = null, ?TinyMashConfig $config = null ) {
        $this->theme_key = $this->normalizeThemeKey( $theme_key );
        $this->theme_registry = $theme_registry;
        $this->config = $config;
    }

    public function getThemeKey() : string {
        return( $this->theme_key );
    }

    public function getThemeName() : string {
        return( (string) ( $this->getThemeDefinition()['name'] ?? 'Baseline Admin' ) );
    }

    public function getTemplateRoot() : string {
        return( (string) ( $this->getThemeDefinition()['template_root'] ?? ( 'admin/' . $this->theme_key ) ) );
    }

    public function getTemplate( string $view_name ) : string {
        $view_name = $this->normalizeViewName( $view_name );
        if ( $view_name === '' ) {
            throw new \InvalidArgumentException( 'admin_view' );
        }

        return( $this->getTemplateRoot() . '/' . $view_name . '.latte' );
    }

    public function getSlotTemplate( string $slot_name ) : string {
        $slot_name = $this->normalizeViewName( $slot_name );
        if ( $slot_name === '' ) {
            throw new \InvalidArgumentException( 'admin_slot' );
        }

        return( $this->getTemplateRoot() . '/slots/' . $slot_name . '.latte' );
    }

    public function getBaseViewData() : array {
        $theme_definition = $this->getThemeDefinition();
        $slots = is_array( $theme_definition['slots'] ?? null ) ? $theme_definition['slots'] : [];

        return(
            [
                'admin_theme_key' => $this->getThemeKey(),
                'admin_theme_name' => $this->getThemeName(),
                'admin_theme_template_root' => $this->getTemplateRoot(),
                'admin_theme_css_urls' => (array) ( $theme_definition['css_urls'] ?? [] ),
                'admin_theme_js_urls' => (array) ( $theme_definition['js_urls'] ?? [] ),
                'admin_theme_slot_head_extra' => (string) ( $slots['head_extra'] ?? 'slots/head-extra.latte' ),
                'admin_theme_slot_topbar_actions' => (string) ( $slots['topbar_actions'] ?? '../slots/topbar-actions.latte' ),
                'admin_theme_slot_section_tools' => (string) ( $slots['section_tools'] ?? '../slots/section-tools.latte' ),
                'site_favicon_png_image' => $this->getSiteFaviconPngImage(),
                'site_favicon_ico_image' => $this->getSiteFaviconIcoImage(),
            ]
        );
    }

    public function getThemeDescription() : string {
        return( trim( (string) ( $this->getThemeDefinition()['description'] ?? '' ) ) );
    }

    public function getThemeVersion() : string {
        return( trim( (string) ( $this->getThemeDefinition()['version'] ?? '' ) ) );
    }

    protected function normalizeThemeKey( string $theme_key ) : string {
        $theme_key = strtolower( trim( $theme_key ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $theme_key ) ?: 'baseline' );
    }

    protected function normalizeViewName( string $view_name ) : string {
        $view_name = strtolower( trim( $view_name ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $view_name ) ?: '' );
    }

    protected function getThemeDefinition() : array {
        if ( $this->theme_registry instanceof TinyMashThemeRegistry ) {
            return( $this->theme_registry->getAdminTheme( $this->theme_key ) );
        }

        return(
            [
                'key' => 'baseline',
                'name' => 'Baseline Admin',
                'template_root' => 'admin/baseline',
                'css_urls' => [
                    '/ext/bs/css/bootstrap.min.css',
                    '/ext/bsi/bootstrap-icons.min.css',
                    '/css/tinymash.css',
                ],
                'js_urls' => [
                    '/ext/bs/js/bootstrap.bundle.min.js',
                    '/ext/sortablejs/sortable.min.js',
                ],
                'slots' => [
                    'head_extra' => 'slots/head-extra.latte',
                    'topbar_actions' => '../slots/topbar-actions.latte',
                    'section_tools' => '../slots/section-tools.latte',
                ],
            ]
        );
    }

    protected function getSiteFaviconPngImage() : array {
        if ( $this->config instanceof TinyMashConfig ) {
            return( (array) $this->config->getSiteFaviconPngImage() );
        }

        return( [] );
    }

    protected function getSiteFaviconIcoImage() : array {
        if ( $this->config instanceof TinyMashConfig ) {
            return( (array) $this->config->getSiteFaviconIcoImage() );
        }

        return( [] );
    }

}// TinyMashAdminTheme
