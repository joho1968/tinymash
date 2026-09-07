<?php
error_reporting( E_ALL );

if ( empty( TINYMASH_RUNNING ) ) {
    die( 'We do not seem to have all the bits configured' );
}

$ds = DIRECTORY_SEPARATOR;
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashWriterGate.php' );

$writer_gate = new app\classes\TinyMashWriterGate(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime'
);
$web_writer_lease = null;
if ( PHP_SAPI !== 'cli' && ! defined( 'TINYMASH_UPDATE_HEALTH_PROBE' ) ) {
    try {
        $web_writer_lease = $writer_gate->acquireShared();
    } catch ( \Throwable ) {
        http_response_code( 503 );
        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'Cache-Control: no-store' );
        header( 'Retry-After: 30' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Site update</title><style>body{margin:0;padding:2rem;font:1rem/1.5 system-ui,sans-serif}main{max-width:42rem;margin:10vh auto}h1{font-size:1.5rem;margin:0 0 .5rem}p{margin:0}</style></head><body><main><h1>Site update in progress</h1><p>Try again in a moment.</p></main></body></html>';
        exit;
    }
}

require_once( 'globals.inc.php' );
require_once dirname(__FILE__, 2 ) . '/include/defines.inc.php';
require_once( __DIR__ . $ds . '..' . $ds . '..' . $ds . 'vendor' . $ds . 'autoload.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashConfig.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashSecurity.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPlugins.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentTargetPickerService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashDraftRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMarkdownRenderer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMarkdownEditorComponent.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashDateFormatter.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashContentRenderer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashShortcodeRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMenuService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashFilesystem.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaAttachmentMetadataStore.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaImportMapStore.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaImportBridge.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaUsageReporter.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaReviewStore.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaCapabilityRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMediaDerivativeRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashLockedJsonFile.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashImportLockService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashImportPreviewWorkspaceService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPublicPageCache.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashRobotsService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashHousekeepingService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashThemeRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashThemeSupportRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPluginCapabilityRegistry.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashTheme.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashAdminTheme.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashHelpCatalog.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUserRepository.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashExportService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashStaticExportArtifactService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashStaticExportService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashMailer.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashNotificationService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashPasswordResetService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashComponentsReportService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateCheckService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateErrorPolicy.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateJournal.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateRecoveryArtifactService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateRecoveryAccessService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateRecoveryEntryPoint.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateRecoveryWorker.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateHealthService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdatePreparationService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateStageService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashUpdateInstallerService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashSecretLinkService.php' );
require_once( __DIR__ . $ds . '..' . $ds . 'classes' . $ds . 'TinyMashLinkIntegrityService.php' );

if ( PHP_SAPI !== 'cli' ) {
    tinymash_sync_runtime_temp_hint();
}

use Latte\Engine;

$app = Flight::app();
$app->path( dirname( __DIR__ ) );
$app->path( dirname( __DIR__ ) . $ds . 'controllers' );
$app->path( dirname( __DIR__ ) . $ds . 'classes' );
$app->path( dirname( __DIR__, 2 ) . $ds . 'vendor' );

// Runtime URL defaults.
if ( @ php_sapi_name() === 'cli' ) {
    $app->set( 'app.url', '' );
} else {
    $app->set( 'app.url',  ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] );
}
$app->set( 'admin.url', '/admin' );
$app->set( 'login.url', '/login' );

// Template engine.
$app->register( 'view', Engine::class, [], function( $latte ) {
    $latte->setTempDirectory( dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'latte-cache' . DIRECTORY_SEPARATOR );
    $latte->setLoader( new \Latte\Loaders\FileLoader( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'views' ) );
});

$router = $app->router();

$config = new \app\classes\TinyMashConfig( $app, $router );
$config->getConfig();
$robots_service = new app\classes\TinyMashRobotsService( $config );

$security = new app\classes\TinyMashSecurity(
    $app,
    $router,
    $config,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'login-attempts.json'
);

// Extension registries.
$theme_support_registry = new app\classes\TinyMashThemeSupportRegistry();
$plugin_capability_registry = new app\classes\TinyMashPluginCapabilityRegistry();
$media_capability_registry = new app\classes\TinyMashMediaCapabilityRegistry();
$media_derivative_registry = new app\classes\TinyMashMediaDerivativeRegistry();

$plugins = new app\classes\TinyMashPlugins( $app, $router, $config, $security, $plugin_capability_registry );

// Content services.
$content_repository = new app\classes\TinyMashContentRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content',
    $config->getContentRevisionRetentionLimit()
);
$content_target_picker = new app\classes\TinyMashContentTargetPickerService( $content_repository );
$draft_repository = new app\classes\TinyMashDraftRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts'
);
$user_repository = new app\classes\TinyMashUserRepository(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'users',
    $config->getEditorDefaultMode()
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
$media_review_store = new app\classes\TinyMashMediaReviewStore(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'review.json'
);
$media_usage_reporter = new app\classes\TinyMashMediaUsageReporter(
    $content_repository,
    $draft_repository,
    $user_repository,
    $config,
    $media_service,
    $plugins
);
$import_lock_service = new app\classes\TinyMashImportLockService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'import-locks.json'
);
$import_preview_workspace_service = app\classes\TinyMashImportPreviewWorkspaceService::forProjectRoot(
    dirname( __FILE__, 3 )
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
$shortcode_registry = new app\classes\TinyMashShortcodeRegistry( $config->getUnknownShortcodeMode() );
$menu_service = new app\classes\TinyMashMenuService( $config, $content_repository );
$link_integrity_service = new app\classes\TinyMashLinkIntegrityService(
    $content_repository,
    $config,
    $markdown_renderer,
    $media_service,
    $router,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'public'
);
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
$admin_theme = new app\classes\TinyMashAdminTheme( 'baseline', $theme_registry, $config );
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
$static_export_artifact_service = new app\classes\TinyMashStaticExportArtifactService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'static-exports',
    21600
);
$static_export_service = new app\classes\TinyMashStaticExportService(
    $app,
    $config,
    $content_repository,
    $content_renderer,
    $theme,
    $user_repository,
    $media_service,
    $static_export_artifact_service,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'public'
);
$mailer = new app\classes\TinyMashMailer( $markdown_renderer );
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
$update_check_service = new app\classes\TinyMashUpdateCheckService(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'update-check.json'
);
$update_stage_service = new app\classes\TinyMashUpdateStageService(
    tinymash_get_runtime_temp_subdirectory( 'tinymash-updates' ),
    dirname( __FILE__, 3 )
);
$update_journal = new app\classes\TinyMashUpdateJournal(
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'update-transaction.json'
);
$update_preparation_service = new app\classes\TinyMashUpdatePreparationService(
    $update_stage_service,
    $update_journal,
    dirname( __FILE__, 3 ),
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'update-transactions',
    static function( string $target_directory ) use ( $export_service, $plugins ) : array {
        $manifest = $export_service->exportSite( $target_directory, $plugins, true );
        foreach ( (array) ( $manifest['plugin_exports'] ?? [] ) as $result ) {
            if ( is_array( $result ) && (string) ( $result['status'] ?? '' ) === 'error' ) {
                throw new \RuntimeException( 'A plugin could not add its data to the update backup.' );
            }
        }
        return( $manifest );
    },
    static fn( string $target_directory ) : array => $export_service->inspectSiteImport( $target_directory )
);
$update_installer_service = new app\classes\TinyMashUpdateInstallerService(
    $update_journal,
    $writer_gate,
    dirname( __FILE__, 3 ),
    static fn( array $journal ) : array => $update_preparation_service->verifyRecoveryArtifacts( $journal ),
    static fn( array $availability ) : array => $update_preparation_service->prepare( $availability )
);
$update_recovery_access_service = new app\classes\TinyMashUpdateRecoveryAccessService(
    $update_journal,
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'update-transactions'
);
$update_health_service = new app\classes\TinyMashUpdateHealthService(
    $update_journal,
    $writer_gate,
    dirname( __FILE__, 3 )
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
$app->set( 'shortcode.registry', $shortcode_registry );
$app->set( 'menu.service', $menu_service );
$app->set( 'link_integrity.service', $link_integrity_service );
$app->set( 'media.metadata_store', $media_metadata_store );
$app->set( 'media.import_map_store', $media_import_map_store );
$app->set( 'media.service', $media_service );
$app->set( 'media.import_bridge', $media_import_bridge );
$app->set( 'media.usage_reporter', $media_usage_reporter );
$app->set( 'media.review_store', $media_review_store );
$app->set( 'import.lock_service', $import_lock_service );
$app->set( 'import.preview_workspaces', $import_preview_workspace_service );
$app->set( 'public.page_cache', $public_page_cache );
$app->set( 'robots.service', $robots_service );
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
$app->set( 'static_export.artifacts', $static_export_artifact_service );
$app->set( 'static_export.service', $static_export_service );
$app->set( 'mailer', $mailer );
$app->set( 'notification.service', $notification_service );
$app->set( 'password_reset.service', $password_reset_service );
$app->set( 'components.report_service', $components_report_service );
$app->set( 'update.check_service', $update_check_service );
$app->set( 'update.stage_service', $update_stage_service );
$app->set( 'update.preparation_service', $update_preparation_service );
$app->set( 'update.installer_service', $update_installer_service );
$app->set( 'update.recovery_access_service', $update_recovery_access_service );
$app->set( 'update.health_service', $update_health_service );
$app->set( 'writer.gate', $writer_gate );
$app->set( 'secret_links', $secret_link_service );
$app->set( 'security', $security );

$plugins_directories = [
    dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR . 'plugins',
    dirname( __FILE__, 3 ) . DIRECTORY_SEPARATOR . 'plugins',
];
if ( ! defined( 'TINYMASH_UPDATE_HEALTH_PROBE' ) ) {
    try {
        $plugins->discoverDirectories( $plugins_directories );
        $plugins->bootStage( 'before' );
        $plugins->bootStage( 'early' );
    } catch( \Throwable $e ) {
        error_log( basename( __FILE__ ) . ': Unable to discover plugins ("' . $e->getMessage() . '")' );
    }
}

require_once 'routes.inc.php';

if ( ! defined( 'TINYMASH_UPDATE_HEALTH_PROBE' ) ) {
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
}

if ( PHP_SAPI !== 'cli' && ! defined( 'TINYMASH_UPDATE_HEALTH_PROBE' ) ) {
    register_shutdown_function(
        static function() use ( $content_repository, $draft_repository, $media_service, $media_import_bridge, $import_preview_workspace_service, $plugins, $notification_service, $password_reset_service, $theme ) : void {
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
                    $media_import_bridge,
                    $content_repository,
                    $import_preview_workspace_service
                );
                $housekeeping_service->runWebFallbackIfDue();
            } catch( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Web housekeeping fallback failed ("' . $e->getMessage() . '")' );
            }
        }
    );
}

$app->start();
