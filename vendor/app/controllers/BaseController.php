<?php
// app/controllers/BaseController.php
declare(strict_types=1);
namespace app\controllers;

use flight\Engine;

/*
 * Borrowed from https://dev.to/n0nag0n/building-a-simple-blog-with-flight-part-2-5acb
 */

abstract class BaseController {

    protected $app;
    protected $router;

    public function __construct( $app, $router ) {
        $this->app = $app;
        $this->router = $router;
    }

    public function isLoggedIn() : bool {
        return( false );
    }

    protected function render( string $view_name, array $data = [] ) : void {
        $this->app->view()->render( $view_name, $data );
    }

    protected function renderAdmin( string $view_name, array $data = [] ) : void {
        if ( ! array_key_exists( 'current_role_label', $data ) ) {
            $current_role = strtolower( trim( (string) ( $data['current_role'] ?? '' ) ) );
            $data['current_role_label'] = $current_role !== ''
                ? match ( $current_role ) {
                    'superadmin' => 'Admin',
                    'creator' => 'Author',
                    default => ucfirst( $current_role ),
                }
                : null;
        }

        if ( ! array_key_exists( 'current_user_theme_override', $data ) ) {
            $theme_override = '';
            if ( isset( $_COOKIE['tinymashScreen'] ) ) {
                $cookie_value = strtolower( trim( (string) $_COOKIE['tinymashScreen'] ) );
                if ( in_array( $cookie_value, [ 'light', 'dark' ], true ) ) {
                    $theme_override = $cookie_value;
                }
            } elseif ( isset( $_COOKIE['tmAdminThemeMode'] ) ) {
                $cookie_value = strtolower( trim( (string) $_COOKIE['tmAdminThemeMode'] ) );
                if ( in_array( $cookie_value, [ 'light', 'dark' ], true ) ) {
                    $theme_override = $cookie_value;
                }
            } elseif ( isset( $_COOKIE['tmAdminDarkMode'] ) ) {
                $cookie_value = trim( (string) $_COOKIE['tmAdminDarkMode'] );
                if ( $cookie_value === '0' ) {
                    $theme_override = 'light';
                } elseif ( $cookie_value === '1' ) {
                    $theme_override = 'dark';
                }
            }
            $data['current_user_theme_override'] = $theme_override;
        }

        if ( ! array_key_exists( 'current_user_theme_mode', $data ) ) {
            $data['current_user_theme_mode'] = 'auto';
        }
        if ( ! array_key_exists( 'visit_site_url', $data ) || ! array_key_exists( 'visit_root_url', $data ) ) {
            $is_superadmin = ! empty( $data['is_superadmin'] );
            $current_author_slug = strtolower( trim( (string) ( $data['current_author_slug'] ?? '' ) ) );
            if ( $current_author_slug === '' && ! $is_superadmin ) {
                $current_author_slug = strtolower( trim( (string) ( $data['current_username'] ?? '' ) ) );
            }

            $visit_root_url = '/';
            $visit_site_url = $visit_root_url;
            if ( ! $is_superadmin && $current_author_slug !== '' ) {
                $visit_site_url = '/' . rawurlencode( $current_author_slug );
            }

            if ( ! array_key_exists( 'visit_site_url', $data ) ) {
                $data['visit_site_url'] = $visit_site_url;
            }
            if ( ! array_key_exists( 'visit_root_url', $data ) ) {
                $data['visit_root_url'] = $visit_root_url;
            }
        }
        if ( ! array_key_exists( 'screen_mode_cookie_name', $data ) ) {
            $data['screen_mode_cookie_name'] = 'tinymashScreen';
        }
        if ( ! array_key_exists( 'site_favicon_png_image', $data ) || ! array_key_exists( 'site_favicon_ico_image', $data ) ) {
            $site_favicon_png_image = [];
            $site_favicon_ico_image = [];
            try {
                $config = $this->app->has( 'config' ) ? $this->app->get( 'config' ) : null;
                if ( is_object( $config ) && method_exists( $config, 'getSiteFaviconPngImage' ) && method_exists( $config, 'getSiteFaviconIcoImage' ) ) {
                    $site_favicon_png_image = (array) $config->getSiteFaviconPngImage();
                    $site_favicon_ico_image = (array) $config->getSiteFaviconIcoImage();
                }
            } catch ( \Throwable $e ) {
                $site_favicon_png_image = [];
                $site_favicon_ico_image = [];
            }
            if ( ! array_key_exists( 'site_favicon_png_image', $data ) ) {
                $data['site_favicon_png_image'] = $site_favicon_png_image;
            }
            if ( ! array_key_exists( 'site_favicon_ico_image', $data ) ) {
                $data['site_favicon_ico_image'] = $site_favicon_ico_image;
            }
        }
        if ( ! array_key_exists( 'admin_plugin_nav_items', $data ) ) {
            $data['admin_plugin_nav_items'] = [
                'author' => [],
                'admin' => [],
                'account' => [],
            ];
            try {
                $plugins = $this->app->has( 'plugins' ) ? $this->app->get( 'plugins' ) : null;
                if ( is_object( $plugins ) && method_exists( $plugins, 'getAdminNavigationItems' ) ) {
                    $data['admin_plugin_nav_items'] = [
                        'author' => (array) $plugins->getAdminNavigationItems( 'author' ),
                        'admin' => (array) $plugins->getAdminNavigationItems( 'admin' ),
                        'account' => (array) $plugins->getAdminNavigationItems( 'account' ),
                    ];
                }
            } catch ( \Throwable $e ) {
                $data['admin_plugin_nav_items'] = [
                    'author' => [],
                    'admin' => [],
                    'account' => [],
                ];
            }
        }
        if ( ! array_key_exists( 'admin_plugin_nav_author_import_items', $data ) || ! array_key_exists( 'admin_plugin_nav_author_general_items', $data ) ) {
            $author_items = is_array( $data['admin_plugin_nav_items']['author'] ?? null ) ? $data['admin_plugin_nav_items']['author'] : [];
            $author_import_items = [];
            $author_general_items = [];

            foreach ( $author_items as $author_item ) {
                if ( ! is_array( $author_item ) ) {
                    continue;
                }

                if ( strtolower( trim( (string) ( $author_item['group'] ?? '' ) ) ) === 'import' ) {
                    $author_import_items[] = $author_item;
                    continue;
                }

                $author_general_items[] = $author_item;
            }

            if ( ! array_key_exists( 'admin_plugin_nav_author_import_items', $data ) ) {
                $data['admin_plugin_nav_author_import_items'] = $author_import_items;
            }
            if ( ! array_key_exists( 'admin_plugin_nav_author_general_items', $data ) ) {
                $data['admin_plugin_nav_author_general_items'] = $author_general_items;
            }
        }
        if ( ! empty( $data['is_superadmin'] ) && isset( $data['admin_plugin_nav_items']['admin'] ) && is_array( $data['admin_plugin_nav_items']['admin'] ) ) {
            $author_signatures = [];
            foreach ( array_merge( (array) ( $data['admin_plugin_nav_author_import_items'] ?? [] ), (array) ( $data['admin_plugin_nav_author_general_items'] ?? [] ) ) as $author_nav_item ) {
                if ( ! is_array( $author_nav_item ) ) {
                    continue;
                }
                $author_signatures[] = strtolower( trim( (string) ( $author_nav_item['section'] ?? '' ) ) ) . '|' . trim( (string) ( $author_nav_item['url'] ?? '' ) );
            }
            $author_signatures = array_values( array_unique( array_filter( $author_signatures, static fn( $signature ) => $signature !== '|' ) ) );

            if ( ! empty( $author_signatures ) ) {
                $filtered_admin_items = [];
                foreach ( $data['admin_plugin_nav_items']['admin'] as $admin_nav_item ) {
                    if ( ! is_array( $admin_nav_item ) ) {
                        continue;
                    }
                    $signature = strtolower( trim( (string) ( $admin_nav_item['section'] ?? '' ) ) ) . '|' . trim( (string) ( $admin_nav_item['url'] ?? '' ) );
                    if ( in_array( $signature, $author_signatures, true ) ) {
                        continue;
                    }
                    $filtered_admin_items[] = $admin_nav_item;
                }
                $data['admin_plugin_nav_items']['admin'] = $filtered_admin_items;
            }
        }

        $admin_theme = null;
        try {
            $admin_theme = $this->app->get( 'admin.theme' );
        } catch ( \Throwable $e ) {
            $admin_theme = null;
        }

        if ( is_object( $admin_theme ) && method_exists( $admin_theme, 'getTemplate' ) && method_exists( $admin_theme, 'getBaseViewData' ) ) {
            $theme_view_data = $admin_theme->getBaseViewData();
            if ( is_array( $theme_view_data ) ) {
                $data = array_merge( $theme_view_data, $data );
            }
            $this->render( (string) $admin_theme->getTemplate( $view_name ), $data );
            return;
        }

        $this->render( $view_name, $data );
    }

    /**
     * Call method to create a shortcut to the $app property
     *
     * @param string $name The method name
     * @param array $arguments The method arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        return( $this->app->$name(...$arguments) );
    }

}// BaseController
