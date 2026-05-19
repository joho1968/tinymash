<?php
namespace app\classes;

class TinyMashHousekeepingService {

    protected TinyMashConfigIO $config_io;
    protected TinyMashDraftRepository $draft_repository;
    protected ?TinyMashMediaService $media_service;
    protected ?TinyMashMediaImportBridge $media_import_bridge;
    protected ?TinyMashPlugins $plugins;
    protected ?TinyMashNotificationService $notification_service;
    protected ?TinyMashPasswordResetService $password_reset_service;
    protected ?TinyMashTheme $theme;
    protected string $web_fallback_lock_filename;

    public function __construct( TinyMashConfigIO $config_io, TinyMashDraftRepository $draft_repository, ?TinyMashMediaService $media_service = null, ?TinyMashPlugins $plugins = null, ?TinyMashNotificationService $notification_service = null, ?TinyMashPasswordResetService $password_reset_service = null, ?TinyMashTheme $theme = null, string $web_fallback_lock_filename = '', ?TinyMashMediaImportBridge $media_import_bridge = null ) {
        $this->config_io = $config_io;
        $this->draft_repository = $draft_repository;
        $this->media_service = $media_service;
        $this->media_import_bridge = $media_import_bridge;
        $this->plugins = $plugins;
        $this->notification_service = $notification_service;
        $this->password_reset_service = $password_reset_service;
        $this->theme = $theme;
        $this->web_fallback_lock_filename = trim( $web_fallback_lock_filename ) !== ''
            ? $web_fallback_lock_filename
            : dirname( $this->config_io->getConfigFilename(), 2 ) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'housekeeping-web.lock';
    }

    public function run( bool $run_plugins = true, array $context = [], bool $run_theme = true ) : array {
        $started_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $stale_draft_retention_days = $this->config_io->getHousekeepingDraftRetentionDays();
        $context = array_merge(
            [
                'run_type' => 'housekeeping',
                'trigger' => 'manual',
                'mode' => 'manual',
                'started_at_utc' => $started_at_utc,
            ],
            $context
        );
        $stale_drafts_removed = $this->draft_repository->deleteStaleDrafts( $stale_draft_retention_days );
        $thumbnail_backfill_result = $this->media_service instanceof TinyMashMediaService
            ? $this->media_service->backfillMissingThumbnails()
            : [ 'generated' => 0, 'checked' => 0 ];
        $thumbnail_prune_result = $this->media_service instanceof TinyMashMediaService
            ? $this->media_service->pruneOrphanedThumbnails()
            : [ 'removed_files' => 0, 'cleared_records' => 0 ];
        $notification_prune_result = $this->notification_service instanceof TinyMashNotificationService
            ? $this->notification_service->pruneNotifications( $this->config_io->getSystemSettings()['notification_retention_days'] ?? 30 )
            : [ 'removed' => 0, 'retention_days' => 0 ];
        $password_reset_prune_result = $this->password_reset_service instanceof TinyMashPasswordResetService
            ? $this->password_reset_service->pruneExpiredRequests()
            : [ 'removed_attempts' => 0, 'removed_requests' => 0 ];
        $media_import_cleanup_result = $this->media_import_bridge instanceof TinyMashMediaImportBridge
            ? $this->media_import_bridge->cleanupTemporaryImportDirectory()
            : [ 'checked_files' => 0, 'removed_files' => 0, 'removed_directory' => false, 'directory_exists' => false ];

        $plugin_task_results = [];
        if ( $run_plugins && $this->plugins instanceof TinyMashPlugins ) {
            $plugin_task_results = $this->plugins->runHousekeepingTasks(
                array_merge(
                    $context,
                    [
                        'started_at_utc' => $started_at_utc,
                        'stale_draft_retention_days' => $stale_draft_retention_days,
                        'stale_drafts_removed' => $stale_drafts_removed,
                    ]
                )
            );
        }

        $theme_task_result = [];
        if ( $run_theme && $this->theme instanceof TinyMashTheme ) {
            $theme_task_result = $this->theme->runHousekeepingTask(
                array_merge(
                    $context,
                    [
                        'started_at_utc' => $started_at_utc,
                        'stale_draft_retention_days' => $stale_draft_retention_days,
                        'stale_drafts_removed' => $stale_drafts_removed,
                    ]
                )
            );
        }

        $last_run_saved = $this->config_io->setHousekeepingLastRunMetadata(
            $started_at_utc,
            (string) ( $context['trigger'] ?? '' ),
            (string) ( $context['mode'] ?? '' )
        );

        return(
            [
                'started_at_utc' => $started_at_utc,
                'last_run_saved' => $last_run_saved,
                'stale_draft_retention_days' => $stale_draft_retention_days,
                'core_tasks' => [
                    [
                        'key' => 'stale_draft_cleanup',
                        'label' => 'Stale draft cleanup',
                        'status' => $stale_draft_retention_days > 0 ? 'ran' : 'skipped',
                        'message' => $stale_draft_retention_days > 0
                            ? ( 'Removed ' . $stale_drafts_removed . ' stale draft(s).' )
                            : 'Disabled by site policy.',
                    'deleted_count' => $stale_drafts_removed,
                ],
                [
                    'key' => 'thumbnail_backfill',
                    'label' => 'Thumbnail backfill',
                    'status' => 'ran',
                    'message' => 'Generated ' . (int) ( $thumbnail_backfill_result['generated'] ?? 0 ) . ' missing thumbnail(s) after checking ' . (int) ( $thumbnail_backfill_result['checked'] ?? 0 ) . ' candidate image(s).',
                    'generated' => (int) ( $thumbnail_backfill_result['generated'] ?? 0 ),
                    'checked' => (int) ( $thumbnail_backfill_result['checked'] ?? 0 ),
                ],
                [
                    'key' => 'thumbnail_cleanup',
                    'label' => 'Thumbnail cleanup',
                    'status' => 'ran',
                    'message' => 'Removed ' . (int) ( $thumbnail_prune_result['removed_files'] ?? 0 ) . ' orphaned thumbnail file(s) and cleared ' . (int) ( $thumbnail_prune_result['cleared_records'] ?? 0 ) . ' metadata record(s).',
                    'removed_files' => (int) ( $thumbnail_prune_result['removed_files'] ?? 0 ),
                    'cleared_records' => (int) ( $thumbnail_prune_result['cleared_records'] ?? 0 ),
                ],
                [
                    'key' => 'notification_cleanup',
                    'label' => 'Notification cleanup',
                    'status' => 'ran',
                    'message' => 'Removed ' . (int) ( $notification_prune_result['removed'] ?? 0 ) . ' expired notification(s).',
                    'removed_count' => (int) ( $notification_prune_result['removed'] ?? 0 ),
                ],
                [
                    'key' => 'password_reset_cleanup',
                    'label' => 'Password reset cleanup',
                    'status' => 'ran',
                    'message' => 'Removed ' . (int) ( $password_reset_prune_result['removed_requests'] ?? 0 ) . ' expired password-reset request(s) and ' . (int) ( $password_reset_prune_result['removed_attempts'] ?? 0 ) . ' throttle record(s).',
                    'removed_requests' => (int) ( $password_reset_prune_result['removed_requests'] ?? 0 ),
                    'removed_attempts' => (int) ( $password_reset_prune_result['removed_attempts'] ?? 0 ),
                ],
                [
                    'key' => 'media_import_temp_cleanup',
                    'label' => 'Media import temporary cleanup',
                    'status' => 'ran',
                    'message' => 'Removed ' . (int) ( $media_import_cleanup_result['removed_files'] ?? 0 ) . ' stale media-import temporary file(s)' . ( ! empty( $media_import_cleanup_result['removed_directory'] ) ? ' and removed the empty staging directory.' : '.' ),
                    'checked_files' => (int) ( $media_import_cleanup_result['checked_files'] ?? 0 ),
                    'removed_files' => (int) ( $media_import_cleanup_result['removed_files'] ?? 0 ),
                    'removed_directory' => ! empty( $media_import_cleanup_result['removed_directory'] ),
                    'directory_exists' => ! empty( $media_import_cleanup_result['directory_exists'] ),
                ],
            ],
                'plugin_tasks' => $plugin_task_results,
                'theme_task' => $theme_task_result,
            ]
        );
    }

    public function runWebFallbackIfDue( bool $run_plugins = true, bool $run_theme = true ) : array {
        if ( PHP_SAPI === 'cli' ) {
            return( [ 'status' => 'skipped', 'reason' => 'cli' ] );
        }

        if ( $this->config_io->getHousekeepingWebFallbackMode() !== 'auto' ) {
            return( [ 'status' => 'skipped', 'reason' => 'mode_off' ] );
        }

        $request_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        if ( ! in_array( $request_method, [ 'GET', 'HEAD' ], true ) ) {
            return( [ 'status' => 'skipped', 'reason' => 'request_method' ] );
        }

        $last_run_utc = $this->config_io->getHousekeepingLastRunUtc();
        if ( ! $this->isWebFallbackDue( $last_run_utc ) ) {
            return( [ 'status' => 'skipped', 'reason' => 'not_due', 'last_run_utc' => $last_run_utc ] );
        }

        $lock_handle = $this->openWebFallbackLockHandle();
        if ( $lock_handle === null ) {
            return( [ 'status' => 'skipped', 'reason' => 'lock_unavailable' ] );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
                return( [ 'status' => 'skipped', 'reason' => 'already_running' ] );
            }

            clearstatcache( true, $this->config_io->getConfigFilename() );
            if ( ! $this->config_io->getConfig() ) {
                return( [ 'status' => 'skipped', 'reason' => 'config_unavailable' ] );
            }

            $last_run_utc = $this->config_io->getHousekeepingLastRunUtc();
            if ( ! $this->isWebFallbackDue( $last_run_utc ) ) {
                return( [ 'status' => 'skipped', 'reason' => 'not_due', 'last_run_utc' => $last_run_utc ] );
            }

            if ( function_exists( 'fastcgi_finish_request' ) ) {
                @ fastcgi_finish_request();
            }

            $result = $this->run(
                $run_plugins,
                [
                    'run_type' => 'housekeeping',
                    'trigger' => 'web_fallback',
                    'mode' => 'auto',
                    'request_method' => $request_method,
                    'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
                ],
                $run_theme
            );
            $result['status'] = 'ran';
            return( $result );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function isWebFallbackDue( string $last_run_utc ) : bool {
        if ( trim( $last_run_utc ) === '' ) {
            return( true );
        }

        $last_run_timestamp = strtotime( $last_run_utc );
        if ( $last_run_timestamp === false ) {
            return( true );
        }

        return( ( time() - $last_run_timestamp ) >= 900 );
    }

    protected function openWebFallbackLockHandle() {
        $lock_filename = trim( $this->web_fallback_lock_filename );
        if ( $lock_filename === '' ) {
            return( null );
        }

        $lock_directory = dirname( $lock_filename );
        if ( ! is_dir( $lock_directory ) && ! @ mkdir( $lock_directory, 0775, true ) && ! is_dir( $lock_directory ) ) {
            return( null );
        }

        $handle = @ fopen( $lock_filename, 'c+' );
        return( $handle === false ? null : $handle );
    }
}
