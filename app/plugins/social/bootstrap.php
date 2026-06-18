<?php

require_once __DIR__ . '/TinyMashSocialService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashUserRepository;

return static function( TinyMashPlugins $plugins, array $plugin ) : void {
    $plugin_key = (string) ( $plugin['key'] ?? 'social' );
    $project_root = dirname( __DIR__, 3 );
    $config = $plugins->getService( 'config' );
    $security = $plugins->getService( 'security' );
    $user_repository = $plugins->getService( 'user.repository' );

    if ( ! $config instanceof TinyMashConfig || ! $security instanceof TinyMashSecurity || ! $user_repository instanceof TinyMashUserRepository ) {
        throw new \RuntimeException( 'Required services for the Social plugin are not available.' );
    }

    $service = new TinyMashSocialService(
        $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'social' . DIRECTORY_SEPARATOR . 'site.json'
    );
    \Flight::set( 'plugin.social.service', $service );

    $plugins->registerPublicAsset( $plugin_key, 'css', '/plugins/social/social.css' );

    $resolve_settings = static function( array $context = [], string $requested_scope = 'auto' ) use ( $service, $user_repository ) : array {
        $requested_scope = strtolower( trim( $requested_scope ) );
        $site_settings = $service->getSiteSettings();
        $author_slug = strtolower( trim( (string) ( $context['author_slug'] ?? '' ) ) );
        if ( $author_slug === '' && is_array( $context['entry'] ?? null ) ) {
            $author_slug = strtolower( trim( (string) ( $context['entry']['author_slug'] ?? '' ) ) );
        }

        if ( $requested_scope === 'profile' || ( $requested_scope === 'auto' && $author_slug !== '' ) ) {
            $profile_settings = $service->normalizeSettings( $user_repository->getPluginSettings( $author_slug, 'social' ) );
            if ( ! empty( $profile_settings['links'] ) || $requested_scope === 'profile' ) {
                return( $profile_settings );
            }
            if ( $requested_scope === 'auto' && empty( $site_settings['fallback_to_site_for_author'] ) ) {
                return( $profile_settings );
            }
        }

        return( $site_settings );
    };

    $plugins->registerPublicSlotRenderer(
        $plugin_key,
        'footer',
        static function( array $context = [] ) use ( $service, $resolve_settings ) : string {
            $settings = $resolve_settings( $context, 'auto' );
            if ( empty( $settings['show_in_footer'] ) ) {
                return( '' );
            }
            return( $service->renderLinksHtml( $settings ) );
        }
    );

    $plugins->registerPublicSlotRenderer(
        $plugin_key,
        'sidebar',
        static function( array $context = [] ) use ( $service, $resolve_settings ) : string {
            $settings = $resolve_settings( $context, 'auto' );
            if ( empty( $settings['show_in_sidebar'] ) ) {
                return( '' );
            }
            return( $service->renderLinksHtml( $settings ) );
        }
    );

    $plugins->registerShortcode(
        $plugin_key,
        'social',
        static function( array $shortcode ) use ( $service, $resolve_settings ) : array {
            $attributes = is_array( $shortcode['attributes'] ?? null ) ? $shortcode['attributes'] : [];
            $scope = strtolower( trim( (string) ( $attributes['scope'] ?? 'auto' ) ) );
            if ( ! in_array( $scope, [ 'auto', 'site', 'profile' ], true ) ) {
                $scope = 'auto';
            }
            $layout = strtolower( trim( (string) ( $attributes['layout'] ?? 'icons' ) ) );
            if ( ! in_array( $layout, [ 'icons', 'list', 'cards' ], true ) ) {
                $layout = 'icons';
            }
            $settings = $resolve_settings( is_array( $shortcode['context'] ?? null ) ? $shortcode['context'] : [], $scope );
            return( [ 'html' => $service->renderLinksHtml( $settings, 'Social links', true, $layout ) ] );
        },
        [
            'label' => 'Social links',
            'description' => 'Displays configured site or profile social links.',
            'example' => '[social layout="cards"]',
        ]
    );

    if ( $plugins->isCliRuntime() ) {
        return;
    }

    $admin_url = (string) ( $config->configGetAdminURL() ?: '/admin' );
    $login_url = (string) ( $config->configGetLoginURL() ?: '/login' );
    $site_url = $admin_url . '/social';
    $profile_url = $admin_url . '/social/profile';

    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'admin',
        [
            'section' => 'social',
            'label' => 'Social',
            'url' => $site_url,
            'icon' => 'bi-share',
            'order' => 82,
        ]
    );
    $plugins->registerAdminNavigationItem(
        $plugin_key,
        'account',
        [
            'section' => 'social-profile',
            'label' => 'Social links',
            'url' => $profile_url,
            'icon' => 'bi-share',
            'order' => 45,
        ]
    );
    $plugins->registerAdminConfigurationUrl( $plugin_key, $site_url );

    $plugins->registerHelpDocument(
        $plugin_key,
        'plugin-social',
        [
            'title' => 'Social',
            'summary' => 'Promote site and profile social/contact links.',
            'group' => 'Plugins',
            'order' => 145,
            'roles' => [ 'author', 'admin' ],
            'sections' => [
                [
                    'title' => 'What it does',
                    'markdown' => 'Social renders configured site/profile links with local icons. It does not post to social networks, fetch remote widgets, or call external platforms from visitor browsers.',
                ],
                [
                    'title' => 'Placement',
                    'markdown' => '`[social]` uses profile links when the rendered author/profile context has configured profile links. The site setting "Use site links when profile links are empty" controls whether automatic author/profile output falls back to site links. `[social scope="site"]` always renders the site links from `/admin/social`. `[social scope="profile"]` only renders links for the current author/profile context and returns nothing when that profile has no Social links. Shortcode layouts are `icons`, `list`, and `cards`, for example `[social layout="cards"]`.',
                ],
            ],
        ]
    );

    $escape = static function( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    };
    $clear_cache = static function() use ( $project_root ) : void {
        ( new TinyMashPublicPageCache( $project_root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache' ) )->clear();
    };

    $render_page = static function( string $mode, array $notice = [] ) use ( $plugins, $security, $service, $user_repository, $site_url, $profile_url, $escape ) : void {
        $is_profile = $mode === 'profile';
        $settings = $is_profile
            ? $service->normalizeSettings( $user_repository->getPluginSettings( (string) $security->getCurrentUsername(), 'social' ) )
            : $service->getSiteSettings();
        $form_action = $is_profile ? $profile_url . '/save' : $site_url . '/save';
        $body_html = '<form method="post" action="' . $escape( $form_action ) . '" class="d-grid gap-3">';
        $body_html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $security->getCsrfToken() ) . '">';
        $body_html .= '<section class="p-3 bg-body border rounded-3 shadow-sm">';
        $body_html .= '<p class="text-body-secondary small mb-3">Add one or more typed links. Empty address rows are ignored.</p>';
        $body_html .= $service->renderAdminFormFields( $settings, '', 'Save Social links', ! $is_profile );
        $body_html .= '</section>';
        $body_html .= '</form>';

        $plugins->renderAdminPage(
            [
                'title' => APP_NAME . APP_TITLE_SEP . 'Social',
                'current_section' => $is_profile ? 'social-profile' : 'social',
                'current_location' => $is_profile ? 'social/profile' : 'social',
                'plugin_page_kicker' => 'Social',
                'plugin_page_title' => $is_profile ? 'Profile links' : 'Site links',
                'plugin_page_summary' => 'Promoted contact and social links with local icons.',
                'plugin_page_notice' => $notice,
                'plugin_page_body_html' => $body_html,
            ]
        );
    };

    $plugins->registerRoute( $plugin_key, 'get', $site_url, static function() use ( $plugins, $security, $login_url, $render_page ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $plugins->renderAdminPage(
                [
                    'title' => APP_NAME . APP_TITLE_SEP . 'Forbidden',
                    'current_section' => 'social',
                    'plugin_page_kicker' => 'Social',
                    'plugin_page_title' => 'Forbidden',
                    'plugin_page_notice' => [ 'type' => 'danger', 'message' => 'You do not have permission to manage site Social links.' ],
                    'plugin_page_body_html' => '',
                ]
            );
            return;
        }
        $render_page( 'site' );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $site_url . '/save', static function() use ( $plugins, $security, $login_url, $site_url, $service, $clear_cache, $render_page ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        if ( ! $security->isSuperAdmin() ) {
            $plugins->setResponseStatus( 403 );
            $render_page( 'site', [ 'type' => 'danger', 'message' => 'You do not have permission to manage site Social links.' ] );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_page( 'site', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        $service->saveSiteSettings( $service->normalizePostedSettings( $data ) );
        $clear_cache();
        $plugins->redirect( $site_url );
    } );

    $plugins->registerRoute( $plugin_key, 'get', $profile_url, static function() use ( $plugins, $security, $login_url, $render_page ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        $render_page( 'profile' );
    } );

    $plugins->registerRoute( $plugin_key, 'post', $profile_url . '/save', static function() use ( $plugins, $security, $login_url, $profile_url, $service, $user_repository, $clear_cache, $render_page ) : void {
        if ( ! $security->isLoggedIn() ) {
            $plugins->redirect( $login_url );
            return;
        }
        $data = $plugins->getRequestData();
        if ( ! $security->validateCsrfToken( (string) ( $data['tinymash_csrf'] ?? '' ) ) ) {
            $render_page( 'profile', [ 'type' => 'danger', 'message' => 'Your session token is invalid. Please reload the page and try again.' ] );
            return;
        }
        $username = (string) $security->getCurrentUsername();
        $current_user = $user_repository->getUserByUsername( $username );
        if ( ! is_array( $current_user ) ) {
            $render_page( 'profile', [ 'type' => 'danger', 'message' => 'Your profile could not be loaded.' ] );
            return;
        }
        $input = $current_user;
        $input['plugin_settings'] = [
            'social' => $service->normalizePostedSettings( $data ),
        ];
        $user_repository->saveOwnProfile( $username, $input, false );
        $clear_cache();
        $plugins->redirect( $profile_url );
    } );
};
