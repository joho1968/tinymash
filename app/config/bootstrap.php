<?php
error_reporting( E_ALL );

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

// Some "globals"
require_once( 'globals.inc.php' );
require_once dirname(__FILE__, 2 ) . '/include/defines.inc.php';
// Do the autoload thing
$ds = DIRECTORY_SEPARATOR;
require_once( __DIR__ . $ds . '..' . $ds . '..' . $ds . 'vendor' . $ds . 'autoload.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashConfig.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashSecurity.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPlugins.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentTargetPickerService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashDraftRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMarkdownRenderer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashDateFormatter.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentRenderer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMenuService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaAttachmentMetadataStore.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaImportMapStore.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaImportBridge.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaCapabilityRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaDerivativeRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashLockedJsonFile.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashImportLockService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPublicPageCache.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashHousekeepingService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashThemeRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashThemeSupportRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPluginCapabilityRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashTheme.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashAdminTheme.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashHelpCatalog.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUserRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashExportService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMailer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashNotificationService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPasswordResetService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashComponentsReportService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashSecretLinkService.php' );

use Latte\Engine;
// use app\classes\TinyMashSecurity;

// Our common $app object
$app = Flight::app();
$app->path( dirname( __DIR__ ) );
$app->path( dirname( __DIR__ ) . $ds . 'controllers' );
$app->path( dirname( __DIR__ ) . $ds . 'classes' );
$app->path( dirname( __DIR__, 2 ) . $ds . 'vendor' );

// Some defaults
if ( @ php_sapi_name() === 'cli' ) {
    $app->set( 'app.url', '' );
} else {
    $app->set( 'app.url',  ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] );
}
$app->set( 'admin.url', '/admin' );
$app->set( 'login.url', '/login' );

// Latte
$app->register( 'view', Engine::class, [], function( $latte ) {
    $latte->setTempDirectory( dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'latte-cache' . DIRECTORY_SEPARATOR );
    $latte->setLoader( new \Latte\Loaders\FileLoader( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'views' ) );
});

// Whip out the ol' router and we'll pass that to the routes file
$router = $app->router();

// Our common config object
$config = new \app\classes\TinyMashConfig( $app, $router );
$config->getConfig();

// Our common security object
$security = new app\classes\TinyMashSecurity(
    $app,
    $router,
    $config,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'login-attempts.json'
);

// Shared extension registries
$theme_support_registry = new app\classes\TinyMashThemeSupportRegistry();
$plugin_capability_registry = new app\classes\TinyMashPluginCapabilityRegistry();
$media_capability_registry = new app\classes\TinyMashMediaCapabilityRegistry();
$media_derivative_registry = new app\classes\TinyMashMediaDerivativeRegistry();

// Our common plugins object
$plugins = new app\classes\TinyMashPlugins( $app, $router, $config, $security, $plugin_capability_registry );

// Our common content services
$content_repository = new app\classes\TinyMashContentRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content',
    $config->getContentRevisionRetentionLimit()
);
$content_target_picker = new app\classes\TinyMashContentTargetPickerService( $content_repository );
$draft_repository = new app\classes\TinyMashDraftRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts'
);
$user_repository = new app\classes\TinyMashUserRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'users'
);
$markdown_renderer = new app\classes\TinyMashMarkdownRenderer();
$date_formatter = new app\classes\TinyMashDateFormatter(
    $config->getLocaleDateFormat(),
    $config->getLocaleTimeFormat(),
    $config->getLocaleTimezone()
);
$media_metadata_store = new app\classes\TinyMashMediaAttachmentMetadataStore(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media'
);
$media_import_map_store = new app\classes\TinyMashMediaImportMapStore(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'import-map.json'
);
$media_service = new app\classes\TinyMashMediaService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media',
    $config,
    $media_capability_registry,
    $media_derivative_registry,
    $media_metadata_store
);
$media_import_bridge = new app\classes\TinyMashMediaImportBridge(
    $media_service,
    $media_import_map_store
);
$import_lock_service = new app\classes\TinyMashImportLockService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'import-locks.json'
);
$public_page_cache = new app\classes\TinyMashPublicPageCache(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'public-page-cache'
);
$content_renderer = new app\classes\TinyMashContentRenderer(
    $markdown_renderer,
    $date_formatter,
    $config,
    $user_repository,
    $media_service,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'rendered-content',
    $plugins
);
$menu_service = new app\classes\TinyMashMenuService( $config, $content_repository );
$theme_registry = new app\classes\TinyMashThemeRegistry(
    [
        dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'public',
        dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'public',
    ],
    [
        dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'admin',
        dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'admin',
    ],
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'public'
);
$theme = new app\classes\TinyMashTheme( $app, $content_repository, $config, $theme_registry, $theme_support_registry, $user_repository, $menu_service );
$admin_theme = new app\classes\TinyMashAdminTheme( 'baseline', $theme_registry );
$help_catalog = new app\classes\TinyMashHelpCatalog(
    dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR . 'help',
    'en'
);
$export_service = new app\classes\TinyMashExportService(
    $config,
    $user_repository,
    $content_repository,
    $draft_repository,
    $config->getConfigFilename(),
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'users',
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content',
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts',
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media'
);
$mailer = new app\classes\TinyMashMailer();
$notification_service = new app\classes\TinyMashNotificationService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'notifications.json'
);
$password_reset_service = new app\classes\TinyMashPasswordResetService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'password-resets.json',
    $user_repository
);
$components_report_service = new app\classes\TinyMashComponentsReportService(
    dirname( __FILE__, 3 ),
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'components.json'
);
$secret_link_service = new app\classes\TinyMashSecretLinkService(
    $config,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'secret-links.json'
);
$app->set( 'config', $config );
$app->set( 'content.repository', $content_repository );
$app->set( 'content.target_picker', $content_target_picker );
$app->set( 'draft.repository', $draft_repository );
$app->set( 'user.repository', $user_repository );
$app->set( 'markdown.renderer', $markdown_renderer );
$app->set( 'date.formatter', $date_formatter );
$app->set( 'content.renderer', $content_renderer );
$app->set( 'menu.service', $menu_service );
$app->set( 'media.metadata_store', $media_metadata_store );
$app->set( 'media.import_map_store', $media_import_map_store );
$app->set( 'media.service', $media_service );
$app->set( 'media.import_bridge', $media_import_bridge );
$app->set( 'import.lock_service', $import_lock_service );
$app->set( 'public.page_cache', $public_page_cache );
$app->set( 'media.capability_registry', $media_capability_registry );
$app->set( 'media.derivative_registry', $media_derivative_registry );
$app->set( 'theme.support_registry', $theme_support_registry );
$app->set( 'plugin.capability_registry', $plugin_capability_registry );
$app->set( 'plugins', $plugins );
$app->set( 'theme.registry', $theme_registry );
$app->set( 'theme', $theme );
$app->set( 'admin.theme', $admin_theme );
$app->set( 'help.catalog', $help_catalog );
$app->set( 'export.service', $export_service );
$app->set( 'mailer', $mailer );
$app->set( 'notification.service', $notification_service );
$app->set( 'password_reset.service', $password_reset_service );
$app->set( 'components.report_service', $components_report_service );
$app->set( 'secret_links', $secret_link_service );
$app->set( 'security', $security );

// Discover and boot staged plugins
$plugins_directories = [
    dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR . 'plugins',
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'plugins',
];
try {
    $plugins->discoverDirectories( $plugins_directories );
    $plugins->bootStage( 'before' );
    $plugins->bootStage( 'early' );
} catch( \Throwable $e ) {
    error_log( basename( __FILE__ ) . ': Unable to discover plugins ("' . $e->getMessage() . '")' );
}

// Load the routes file
require_once 'routes.inc.php';

try {
    $plugins->bootStage( 'after' );
} catch( \Throwable $e ) {
    error_log( basename( __FILE__ ) . ': Unable to complete plugin boot stages ("' . $e->getMessage() . '")' );
}

try {
    $plugins->bootStage( 'late' );
} catch( \Throwable $e ) {
    error_log( basename( __FILE__ ) . ': Unable to complete late plugin boot stage ("' . $e->getMessage() . '")' );
}

if ( PHP_SAPI !== 'cli' ) {
    register_shutdown_function(
        static function() use ( $draft_repository, $media_service, $media_import_bridge, $plugins, $notification_service, $password_reset_service, $theme ) : void {
            try {
                $config_io = new \app\classes\TinyMashConfigIO();
                if ( ! $config_io->getConfig() ) {
                    return;
                }

                $housekeeping_service = new \app\classes\TinyMashHousekeepingService(
                    $config_io,
                    $draft_repository,
                    $media_service,
                    $plugins,
                    $notification_service,
                    $password_reset_service,
                    $theme,
                    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'housekeeping-web.lock',
                    $media_import_bridge
                );
                $housekeeping_service->runWebFallbackIfDue();
            } catch( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Web housekeeping fallback failed ("' . $e->getMessage() . '")' );
            }
        }
    );
}

// The show must go on
$app->start();
