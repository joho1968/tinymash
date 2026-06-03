<?php
if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

// use flight\Engine;
// use flight\net\Router;
use app\controllers\AdminController;
use app\controllers\ContentController;
// use app\classes\TinyMashSecurity;

global $router;
global $app;
global $config;
global $security;

require_once ( dirname(__FILE__, 2 ) . '/include/defines.inc.php' );
require_once ( dirname( __DIR__ ) . '/controllers/AdminController.php' );
require_once ( dirname( __DIR__ ) . '/controllers/ContentController.php' );

$redirect_to_login = static function() use ( $app, $config ) : void {
    $login_url = (string) $config->configGetLoginURL();
    $request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? $config->configGetAdminURL() );
    $return_target = trim( $request_uri );
    if ( $return_target === '' || ! str_starts_with( $return_target, (string) $config->configGetAdminURL() ) ) {
        $return_target = (string) $config->configGetAdminURL();
    }

    $separator = str_contains( $login_url, '?' ) ? '&' : '?';
    $app->redirect( $login_url . $separator . 'return=' . rawurlencode( $return_target ) );
};

// Canonicalize malformed request paths like `//joho` or `~/joho` before routing.
$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$request_path = (string) ( parse_url( $request_uri, PHP_URL_PATH ) ?? '/' );
$request_query = (string) ( parse_url( $request_uri, PHP_URL_QUERY ) ?? '' );
$normalized_request_path = preg_replace( '#/+#', '/', $request_path ) ?? $request_path;
$strip_path_characters = "~><[]\\.=\\$!@%#^;:'*_(){}+";
if ( $normalized_request_path === '' ) {
    $normalized_request_path = '/';
}

$path_segments = array_values( array_filter( explode( '/', ltrim( $normalized_request_path, '/' ) ), static fn( string $segment ) : bool => $segment !== '' ) );
while ( ! empty( $path_segments ) ) {
    $first_segment = (string) $path_segments[0];
    $trimmed_first_segment = trim( $first_segment, $strip_path_characters );
    if ( $trimmed_first_segment !== '' ) {
        $path_segments[0] = ltrim( $first_segment, $strip_path_characters );
        break;
    }

    array_shift( $path_segments );
}
while ( ! empty( $path_segments ) ) {
    $last_index = count( $path_segments ) - 1;
    $last_segment = (string) $path_segments[$last_index];
    $trimmed_last_segment = trim( $last_segment, $strip_path_characters );
    if ( $trimmed_last_segment !== '' ) {
        $path_segments[$last_index] = rtrim( $last_segment, $strip_path_characters );
        break;
    }

    array_pop( $path_segments );
}
$path_segments = array_values( array_filter( $path_segments, static fn( string $segment ) : bool => $segment !== '' ) );
$normalized_request_path = '/' . implode( '/', $path_segments );
if ( $normalized_request_path === '' ) {
    $normalized_request_path = '/';
}
if ( $normalized_request_path !== $request_path ) {
    $normalized_request_uri = $normalized_request_path . ( $request_query !== '' ? '?' . $request_query : '' );
    $app->redirect( $normalized_request_uri );
    exit;
}

// Some error "handling"
$app->map('error', function ( \Throwable $e ) use ( $router, $app ) {
    error_log( '*** EXCEPTION: ' . $e->getMessage() . ' (' . $e->getCode() . ')' );
    error_log( $e->getTraceAsString() );
    $app->response()->status( 500 );
    $app->view()->render('0error.latte', ['title' => 'Unexpected error']);
});
$app->map('notFound', function () use ( $router, $app ) {
    $request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    $request_path = (string) ( parse_url( $request_uri, PHP_URL_PATH ) ?? '/' );
    if ( $app->has( 'redirects.service' ) ) {
        $redirects_service = $app->get( 'redirects.service' );
        if ( is_object( $redirects_service ) && method_exists( $redirects_service, 'resolveRequestPath' ) ) {
            $redirect = $redirects_service->resolveRequestPath( $request_path );
            if ( is_array( $redirect ) && ! empty( $redirect['target_url'] ) ) {
                $status_code = (int) ( $redirect['status_code'] ?? 302 );
                if ( ! in_array( $status_code, [ 301, 302, 307, 308 ], true ) ) {
                    $status_code = 302;
                }
                $target_url = (string) $redirect['target_url'];
                if ( $target_url !== '' && str_starts_with( $target_url, '/' ) ) {
                    $app->response()->status( $status_code );
                    header( 'Location: ' . $target_url, true, $status_code );
                    return;
                }
            }
        }
    }

    $app->response()->status( 404 );
    $theme = $app->has( 'theme' ) ? $app->get( 'theme' ) : null;
    $site_is_public = $app->has( 'site.is_public' ) ? (bool) $app->get( 'site.is_public' ) : true;
    $is_logged_in = false;
    if ( $app->has( 'security' ) ) {
        $security_service = $app->get( 'security' );
        if ( is_object( $security_service ) && method_exists( $security_service, 'isLoggedIn' ) ) {
            $is_logged_in = (bool) $security_service->isLoggedIn();
        }
    }
    if ( is_object( $theme ) && method_exists( $theme, 'getTemplate' ) && method_exists( $theme, 'getBaseViewData' ) ) {
        $view_data = array_merge(
            $theme->getBaseViewData( 'Page not found', null, null ),
            [ 'title' => 'Page not found' ]
        );
        if ( ! $site_is_public && ! $is_logged_in ) {
            $view_data = array_merge(
                $view_data,
                [
                    'message' => 'This site is not public. Log in to view content.',
                    'login_url' => $app->has( 'login.url' ) ? (string) $app->get( 'login.url' ) : '/admin/login',
                    'theme_show_public_chrome' => false,
                    'theme_sidebar_enabled' => false,
                    'theme_navigation_menu' => [],
                    'theme_page_structure' => [],
                ]
            );
        }
        $app->view()->render(
            (string) $theme->getTemplate( '404' ),
            $view_data
        );
        return;
    }
    $app->view()->render('404.latte', ['title' => 'Page not found']);
});

// login
$router->post( $config->configGetLoginURL(), function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->login();
});
$router->get( $config->configGetLoginURL(), function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->loginForm();
});
$router->get( $config->configGetAdminURL() . '/password-reset', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->passwordResetRequestForm();
});
$router->post( $config->configGetAdminURL() . '/password-reset', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->submitPasswordResetRequest();
});
$router->get( $config->configGetAdminURL() . '/password-reset/confirm', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->passwordResetConfirmForm();
});
$router->post( $config->configGetAdminURL() . '/password-reset/confirm', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->submitPasswordResetConfirm();
});
$router->get( $config->configGetAdminURL() . '/logout', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config );
    $admin->logout();
});
$router->get( $config->configGetAdminURL() . '/editor', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->editor();
});
$router->get( $config->configGetAdminURL() . '/help', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->help();
});
$router->get( $config->configGetAdminURL() . '/fragment/@fragment_key', function( string $fragment_key ) use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->adminFragment( $fragment_key );
});
$router->post( $config->configGetAdminURL() . '/editor/draft', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->saveEditorDraft();
});
$router->post( $config->configGetAdminURL() . '/editor/draft/delete', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->deleteEditorDraft();
});
$router->post( $config->configGetAdminURL() . '/editor/publish', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->publishEditorEntry();
});
$router->post( $config->configGetAdminURL() . '/editor/slug-check', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->checkEditorSlug();
});
$router->post( $config->configGetAdminURL() . '/editor/content-search', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->searchEditorContent();
});
$router->post( $config->configGetAdminURL() . '/editor/image-upload', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->uploadEditorImage();
});
$router->post( $config->configGetAdminURL() . '/editor/media-picker', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->loadEditorMediaPicker();
});
$router->post( $config->configGetAdminURL() . '/preview/markdown', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->previewMarkdown();
});
$router->get( $config->configGetAdminURL() . '/author', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->authorHome();
});
$router->get( $config->configGetAdminURL() . '/about', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->about();
});
$router->get( $config->configGetAdminURL() . '/entries', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $app->redirect( $config->configGetAdminURL() . '/content' );
});
$router->get( $config->configGetAdminURL() . '/content', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->entriesHome();
});
$router->post( $config->configGetAdminURL() . '/content/batch', function() use ( $app, $router, $security, $config ) {
    if ( ! $security->isLoggedIn() ) {
        $app->redirect( $config->configGetLoginURL() );
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->handleContentBatchAction();
});
$router->get( $config->configGetAdminURL() . '/profile', function() use ( $app, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $app->redirect( $config->configGetAdminURL() . '/profile/account' );
});
$router->get( $config->configGetAdminURL() . '/profile/email-confirm', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->confirmProfileEmailChange();
});
$router->get( $config->configGetAdminURL() . '/profile/@section', function( string $section ) use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->profile( $section );
});
$router->post( $config->configGetAdminURL() . '/profile/@section/save', function( string $section ) use ( $app, $router, $security, $config ) {
    if ( ! $security->isLoggedIn() ) {
        $app->redirect( $config->configGetLoginURL() );
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->saveProfile( $section );
});
$router->post( $config->configGetAdminURL() . '/profile/publishing/secret-link/generate', function() use ( $app, $router, $security, $config ) {
    if ( ! $security->isLoggedIn() ) {
        $app->redirect( $config->configGetLoginURL() );
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->generateProfileSecretLink();
});
$router->post( $config->configGetAdminURL() . '/profile/publishing/secret-link/revoke', function() use ( $app, $router, $security, $config ) {
    if ( ! $security->isLoggedIn() ) {
        $app->redirect( $config->configGetLoginURL() );
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->revokeProfileSecretLink();
});
$router->post( $config->configGetAdminURL() . '/profile/publishing/delete-all-content', function() use ( $app, $router, $security, $config ) {
    if ( ! $security->isLoggedIn() ) {
        $app->redirect( $config->configGetLoginURL() );
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->deleteAllProfileContent();
});
$router->get( $config->configGetAdminURL() . '/system', function() use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $app->redirect( $config->configGetAdminURL() . '/system/site' );
});
$router->get( $config->configGetAdminURL() . '/system/plugins/@plugin_key', function( string $plugin_key ) use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->systemPluginSettings( $plugin_key );
});
$router->get( $config->configGetAdminURL() . '/system/@section', function( string $section ) use ( $app, $router, $security, $config, $redirect_to_login ) {
    if ( ! $security->isLoggedIn() ) {
        $redirect_to_login();
        return;
    }
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->systemHome( $section );
});
$router->post( $config->configGetAdminURL() . '/system/settings', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->saveSystemSettings();
});
$router->post( $config->configGetAdminURL() . '/system/notifications/dismiss', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->dismissSystemNotification();
});
$router->post( $config->configGetAdminURL() . '/system/themes/select', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->selectSystemPublicTheme();
});
$router->post( $config->configGetAdminURL() . '/system/plugins/toggle', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->toggleSystemPluginState();
});
$router->post( $config->configGetAdminURL() . '/system/smtp/test', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->sendSystemTestEmail();
});
$router->post( $config->configGetAdminURL() . '/system/moderation/approve', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->approveSystemModerationEntry();
});
$router->post( $config->configGetAdminURL() . '/system/moderation/reject', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->rejectSystemModerationEntry();
});
$router->post( $config->configGetAdminURL() . '/system/users/save', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->saveSystemUser();
});
$router->post( $config->configGetAdminURL() . '/system/users/toggle', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->toggleSystemUserState();
});
$router->post( $config->configGetAdminURL() . '/system/users/delete', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->deleteSystemUser();
});
$router->post( $config->configGetAdminURL() . '/system/orphans/reassign', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->reassignSystemOrphanContent();
});
$router->post( $config->configGetAdminURL() . '/system/orphans/delete', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->deleteSystemOrphanContent();
});
$router->post( $config->configGetAdminURL() . '/system/media-library/review', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->updateSystemMediaLibraryReview();
});
$router->post( $config->configGetAdminURL() . '/system/media-library/delete', function() use ( $app, $router, $security, $config ) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    $admin->deleteSystemMediaLibraryItem();
});
// admin
$router->get( $config->configGetAdminURL(), function() use ( $app, $router, $security, $config, $redirect_to_login) {
    $admin = new AdminController( $app, $router, $security, $config, $app->get( 'markdown.renderer' ), $app->get( 'draft.repository' ), $app->get( 'content.repository' ), $app->get( 'theme' ) );
    if ( ! $security->isLoggedIn() ) {
        if ( defined( 'SECURITY_DEBUG' ) && SECURITY_DEBUG ) {
            error_log( basename( __FILE__ ) . ': [admin] Not authenticated, re-directing' );
        }
        $redirect_to_login();
    } else {
        $admin->index();
    }
});

/*
$router->group( '/admin', function() use ( $router, $app, $security ) {
    error_log( '*** /admin');
    if ( ! $security->isLoggedIn() ) {
        error_log( '*** /admin, not authenticated, re-directing');
        $app->redirect('/login');
    }
    $router->get('/', function () use( $app ) {
        error_log( '*** /admin (index)');
        $app->view()->render('admin.home.latte', [
            'app_url' => $app->get( 'app.url' ),
            'admin_url' => $app->get( 'admin.url'),
            'title' => 'Admin',
        ]);
    });
});
*/

$router->get('/', function () use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->home();
});

$router->get('/s/@token', function ( string $token ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->resolveSecretLink( $token );
});

$router->get('/post/@slug', function ( string $slug ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->legacyRootPost( $slug );
});

$router->get('/media/@owner_username/@year/@month/@filename', function ( string $owner_username, string $year, string $month, string $filename ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->media( $owner_username, $year, $month, $filename );
});

$router->get('/theme-custom-css/@theme_key', function ( string $theme_key ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->themeCustomCss( $theme_key );
});

$router->get('/tags/@tag', function( string $tag ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->rootTag( $tag );
});

$router->get('/@author_slug/tags/@tag', function( string $author_slug, string $tag ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->authorTag( $author_slug, $tag );
});

$router->get('/@first/@second', function( string $first, string $second ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->pathTwoSegments( $first, $second );
});

$router->get('/@segment', function( string $segment ) use( $app, $router, $config ) {
    $content = new ContentController(
        $app,
        $router,
        $config,
        $app->get( 'content.repository' ),
        $app->get( 'content.renderer' ),
        $app->get( 'theme' )
    );
    $content->pathRoot( $segment );
});
