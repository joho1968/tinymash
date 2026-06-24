<?php
namespace app\controllers;

trait AdminThemeConcern {

    public function selectSystemPublicTheme() : void {
        if ( ! $this->security->isLoggedIn() ) {
            $this->app->redirect( $this->config->configGetLoginURL() );
            return;
        }

        if ( ! $this->security->isSuperAdmin() ) {
            $this->app->response()->status( 403 );
            $this->renderAdmin(
                'forbidden',
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'app_url' => $this->app->get( 'app.url' ),
                    'admin_url' => $this->app->get( 'admin.url' ),
                    'current_role' => $this->security->getCurrentRole(),
                    'is_superadmin' => false,
                    'current_section' => 'system',
                    'admin_author_url' => $this->app->get( 'admin.url' ) . '/author',
                    'admin_system_url' => $this->app->get( 'admin.url' ) . '/system',
                    'help_contexts' => [ 'admin-system' ],
                ]
            );
            return;
        }

        $data = $this->app->request()->data->getData();
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( ! $this->isValidCsrfSubmission( $data ) ) {
            $this->setSystemNoticeFlash( 'danger', 'Your session token is invalid. Please reload the page and try again.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'themes' ) );
            return;
        }

        $theme_key = strtolower( trim( (string) ( $data['theme_key'] ?? '' ) ) );
        $available_themes = $this->theme !== null && method_exists( $this->theme, 'getAvailableThemes' ) ? $this->theme->getAvailableThemes() : [];
        $selected_theme = null;
        foreach ( $available_themes as $available_theme ) {
            if ( is_array( $available_theme ) && strtolower( trim( (string) ( $available_theme['key'] ?? '' ) ) ) === $theme_key ) {
                $selected_theme = $available_theme;
                break;
            }
        }

        if ( ! is_array( $selected_theme ) ) {
            $this->setSystemNoticeFlash( 'danger', 'That public theme is not available.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'themes' ) );
            return;
        }

        $config_io = new \app\classes\TinyMashConfigIO();
        if ( ! $config_io->getConfig() || ! $config_io->updateSystemSettings( [ 'theme_key' => $theme_key ], 'themes_select' ) ) {
            $this->setSystemNoticeFlash( 'danger', 'The public theme could not be changed right now.' );
            $this->app->redirect( $this->getSystemSectionUrl( 'themes' ) );
            return;
        }

        $this->clearPublicPageCache();
        $this->setSystemNoticeFlash( 'success', 'Public theme changed to ' . (string) ( $selected_theme['name'] ?? ucfirst( $theme_key ) ) . '.' );
        $this->app->redirect( $this->getSystemSectionUrl( 'themes' ) );
    }

    protected function getSystemAdminThemeInfo() : array {
        if ( ! $this->app->has( 'admin.theme' ) ) {
            return( [] );
        }

        $admin_theme = $this->app->get( 'admin.theme' );
        if ( ! is_object( $admin_theme ) ) {
            return( [] );
        }

        return(
            [
                'key' => method_exists( $admin_theme, 'getThemeKey' ) ? (string) $admin_theme->getThemeKey() : 'baseline',
                'name' => method_exists( $admin_theme, 'getThemeName' ) ? (string) $admin_theme->getThemeName() : 'Baseline Admin',
                'description' => method_exists( $admin_theme, 'getThemeDescription' ) ? (string) $admin_theme->getThemeDescription() : '',
                'version' => method_exists( $admin_theme, 'getThemeVersion' ) ? (string) $admin_theme->getThemeVersion() : '',
            ]
        );
    }

    protected function validateThemeSettings( array $data ) : array {
        $theme_key = $this->theme !== null ? $this->theme->getThemeKey() : 'baseline';
        $theme_settings = [];
        $theme_schema = $this->theme !== null ? $this->theme->getThemeSettingsSchema() : [];
        $current_theme_settings = $this->theme !== null ? $this->theme->getThemeSettings() : [];

        foreach ( $theme_schema as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || empty( $setting_definition['key'] ) || empty( $setting_definition['type'] ) ) {
                continue;
            }

            $setting_key = (string) $setting_definition['key'];
            $setting_type = (string) $setting_definition['type'];
            if ( ! empty( $setting_definition['disabled'] ) ) {
                $theme_settings[$setting_key] = $current_theme_settings[$setting_key] ?? ( $setting_definition['default'] ?? null );
                continue;
            }

            if ( $setting_type === 'select' ) {
                $allowed_values = [];
                foreach ( (array) ( $setting_definition['options'] ?? [] ) as $option_definition ) {
                    if ( is_array( $option_definition ) && isset( $option_definition['value'] ) ) {
                        $allowed_values[] = (string) $option_definition['value'];
                    }
                }

                $value = (string) ( $data[$setting_key] ?? ( $setting_definition['default'] ?? '' ) );
                if ( ! in_array( $value, $allowed_values, true ) ) {
                    throw new \InvalidArgumentException( $setting_key );
                }
                $theme_settings[$setting_key] = $value;
                continue;
            }

            if ( $setting_type === 'checkbox' ) {
                $theme_settings[$setting_key] = ! empty( $data[$setting_key] );
                continue;
            }

            if ( $setting_type === 'text' ) {
                if ( (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages' ) {
                    $theme_settings[$setting_key] = $this->validateLandingPagePathForScope(
                        (string) ( $data[$setting_key] ?? '' ),
                        'root',
                        '',
                        $setting_key
                    );
                    continue;
                }

                $theme_settings[$setting_key] = trim( (string) ( $data[$setting_key] ?? '' ) );
            }
        }

        $custom_css = (string) ( $data['custom_css'] ?? '' );
        $custom_css = str_replace( [ "\r\n", "\r" ], "\n", $custom_css );
        if ( strlen( $custom_css ) > 200000 ) {
            throw new \InvalidArgumentException( 'custom_css_length' );
        }
        if ( $this->customCssContainsBlockedRuntimeAsset( $custom_css ) ) {
            throw new \InvalidArgumentException( 'custom_css_remote' );
        }
        $theme_settings['custom_css_enabled'] = ! empty( $data['custom_css_enabled'] );
        $theme_settings['custom_css'] = $custom_css;

        return(
            [
                'theme_key' => $theme_key,
                'theme_settings' => $theme_settings,
            ]
        );
    }

    protected function customCssContainsBlockedRuntimeAsset( string $custom_css ) : bool {
        if ( trim( $custom_css ) === '' ) {
            return( false );
        }

        return(
            preg_match( '/@import\b/i', $custom_css ) === 1
            || preg_match( '/url\(\s*[\'"]?\s*(?:https?:)?\/\//i', $custom_css ) === 1
            || preg_match( '/url\(\s*[\'"]?\s*https?:/i', $custom_css ) === 1
        );
    }

    protected function getSystemThemeSettingLookups() : array {
        if ( $this->theme === null ) {
            return( [] );
        }

        $lookups = [];
        foreach ( $this->theme->getThemeSettingsSchema() as $setting_definition ) {
            if ( ! is_array( $setting_definition ) || empty( $setting_definition['key'] ) ) {
                continue;
            }

            if ( (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages' ) {
                $lookups[(string) $setting_definition['key']] = $this->buildPublishedPageLookupOptions( 'root' );
            }
        }

        return( $lookups );
    }

    protected function themeSupportsAuthorLandingPageOverride( string $theme_key = '' ) : bool {
        if ( $this->theme === null ) {
            return( false );
        }

        foreach ( $this->theme->getThemeSettingsSchemaForKey( $theme_key ) as $setting_definition ) {
            if ( ! is_array( $setting_definition ) ) {
                continue;
            }

            if ( (string) ( $setting_definition['key'] ?? '' ) === 'landing_page_path'
                && ! empty( $setting_definition['author_override'] )
                && (string) ( $setting_definition['lookup'] ?? '' ) === 'published_pages' ) {
                return( true );
            }
        }

        return( false );
    }

    protected function buildPublishedPageLookupOptions( string $scope = 'root', string $author_slug = '' ) : array {
        if ( $this->content_repository === null ) {
            return( [] );
        }

        $scope = strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root';
        $author_slug = strtolower( trim( $author_slug ) );
        $page_tree = $scope === 'author'
            ? $this->content_repository->getAuthorPublishedPageTree( $author_slug )
            : $this->content_repository->getRootPublishedPageTree();

        $options = [];
        $append_nodes = function( array $nodes ) use ( &$append_nodes, &$options ) : void {
            foreach ( $nodes as $node ) {
                if ( ! is_array( $node ) ) {
                    continue;
                }

                $path = trim( (string) ( $node['path'] ?? '' ) );
                $title = trim( (string) ( $node['title'] ?? $path ) );
                if ( $path !== '' ) {
                    $options[] = [
                        'value' => $path,
                        'label' => $title !== '' ? $title : $path,
                    ];
                }

                if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                    $append_nodes( $node['children'] );
                }
            }
        };
        $append_nodes( is_array( $page_tree ) ? $page_tree : [] );

        return( $options );
    }

    protected function normalizePublicPagePathInput( string $path ) : string {
        $path = trim( $path );
        if ( $path === '' ) {
            return( '' );
        }

        $segments = preg_split( '@/+@', trim( $path, " \t\n\r\0\x0B/" ) ) ?: [];
        $segments = array_values(
            array_filter(
                array_map( static fn( mixed $segment ) : string => trim( (string) $segment ), $segments ),
                static fn( string $segment ) : bool => $segment !== ''
            )
        );

        return( implode( '/', $segments ) );
    }

    protected function validateLandingPagePathForScope( string $path, string $scope = 'root', string $author_slug = '', string $error_key = 'landing_page_path' ) : string {
        $normalized_path = $this->normalizePublicPagePathInput( $path );
        if ( $normalized_path === '' ) {
            return( '' );
        }

        if ( $this->content_repository === null ) {
            throw new \InvalidArgumentException( $error_key );
        }

        $entry = strtolower( trim( $scope ) ) === 'author'
            ? $this->content_repository->getAuthorEntryByPath( strtolower( trim( $author_slug ) ), $normalized_path, $this->config->getDefaultLanguage() )
            : $this->content_repository->getRootEntryByPath( $normalized_path, $this->config->getDefaultLanguage() );

        if ( ! is_array( $entry ) || (string) ( $entry['type'] ?? '' ) !== 'page' || (string) ( $entry['status'] ?? '' ) !== 'published' ) {
            throw new \InvalidArgumentException( $error_key );
        }

        return( $normalized_path );
    }

}
