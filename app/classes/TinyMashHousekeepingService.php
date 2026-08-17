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
    protected ?TinyMashContentRepository $content_repository;
    protected ?TinyMashImportPreviewWorkspaceService $import_preview_workspace_service;
    protected string $web_fallback_lock_filename;

    public function __construct( TinyMashConfigIO $config_io, TinyMashDraftRepository $draft_repository, ?TinyMashMediaService $media_service = null, ?TinyMashPlugins $plugins = null, ?TinyMashNotificationService $notification_service = null, ?TinyMashPasswordResetService $password_reset_service = null, ?TinyMashTheme $theme = null, string $web_fallback_lock_filename = '', ?TinyMashMediaImportBridge $media_import_bridge = null, ?TinyMashContentRepository $content_repository = null, ?TinyMashImportPreviewWorkspaceService $import_preview_workspace_service = null ) {
        $this->config_io = $config_io;
        $this->draft_repository = $draft_repository;
        $this->media_service = $media_service;
        $this->media_import_bridge = $media_import_bridge;
        $this->plugins = $plugins;
        $this->notification_service = $notification_service;
        $this->password_reset_service = $password_reset_service;
        $this->theme = $theme;
        $this->content_repository = $content_repository;
        $this->import_preview_workspace_service = $import_preview_workspace_service;
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
        $trash_retention_days = $this->config_io->getContentTrashRetentionDays();
        $trashed_entries_purged = $this->content_repository instanceof TinyMashContentRepository
            ? $this->content_repository->purgeTrashedEntriesOlderThan( $trash_retention_days )
            : 0;
        $revision_prune_result = $this->content_repository instanceof TinyMashContentRepository
            ? $this->content_repository->pruneAllRevisionSnapshots()
            : [ 'checked_entries' => 0, 'removed_revisions' => 0, 'retention_limit' => 0 ];
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
        $import_preview_cleanup_result = $this->import_preview_workspace_service instanceof TinyMashImportPreviewWorkspaceService
            ? $this->import_preview_workspace_service->cleanupExpiredPreviews()
            : [
                'retention_seconds' => 0,
                'checked_workspaces' => 0,
                'removed_workspaces' => 0,
                'preserved_fresh' => 0,
                'preserved_retained' => 0,
                'skipped_unknown' => 0,
                'skipped_symlinks' => 0,
                'errors' => 0,
            ];
        $scheduled_publication_result = $this->runScheduledPublicationTask( $context, $started_at_utc );

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
                        'key' => 'scheduled_publication',
                        'label' => 'Scheduled publication',
                        'status' => (string) ( $scheduled_publication_result['status'] ?? 'skipped' ),
                        'message' => (string) ( $scheduled_publication_result['message'] ?? 'Scheduled publication was not run.' ),
                        'scheduled_count' => (int) ( $scheduled_publication_result['scheduled_count'] ?? 0 ),
                        'due_count' => (int) ( $scheduled_publication_result['due_count'] ?? 0 ),
                        'activated_count' => (int) ( $scheduled_publication_result['activated_count'] ?? 0 ),
                        'completed_event_count' => (int) ( $scheduled_publication_result['completed_event_count'] ?? 0 ),
                        'pending_event_count' => (int) ( $scheduled_publication_result['pending_event_count'] ?? 0 ),
                        'failed_count' => (int) ( $scheduled_publication_result['failed_count'] ?? 0 ),
                    ],
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
                    'key' => 'content_trash_cleanup',
                    'label' => 'Content Trash cleanup',
                    'status' => $trash_retention_days > 0 ? 'ran' : 'skipped',
                    'message' => $trash_retention_days > 0
                        ? ( 'Permanently deleted ' . $trashed_entries_purged . ' trashed content item(s).' )
                        : 'Disabled by site policy.',
                    'deleted_count' => $trashed_entries_purged,
                    'retention_days' => $trash_retention_days,
                ],
                [
                    'key' => 'content_revision_cleanup',
                    'label' => 'Content revision cleanup',
                    'status' => 'ran',
                    'message' => 'Removed ' . (int) ( $revision_prune_result['removed_revisions'] ?? 0 ) . ' stale content revision(s) after checking ' . (int) ( $revision_prune_result['checked_entries'] ?? 0 ) . ' content item(s).',
                    'checked_entries' => (int) ( $revision_prune_result['checked_entries'] ?? 0 ),
                    'removed_revisions' => (int) ( $revision_prune_result['removed_revisions'] ?? 0 ),
                    'retention_limit' => (int) ( $revision_prune_result['retention_limit'] ?? 0 ),
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
                [
                    'key' => 'import_preview_cleanup',
                    'label' => 'Import preview cleanup',
                    'status' => ! $this->import_preview_workspace_service instanceof TinyMashImportPreviewWorkspaceService
                        ? 'skipped'
                        : ( (int) ( $import_preview_cleanup_result['errors'] ?? 0 ) > 0 ? 'error' : 'ran' ),
                    'message' => $this->import_preview_workspace_service instanceof TinyMashImportPreviewWorkspaceService
                        ? 'Removed ' . (int) ( $import_preview_cleanup_result['removed_workspaces'] ?? 0 ) . ' expired import preview workspace(s)'
                            . ( (int) ( $import_preview_cleanup_result['errors'] ?? 0 ) > 0
                                ? '; ' . (int) $import_preview_cleanup_result['errors'] . ' workspace operation(s) failed.'
                                : '.' )
                        : 'Import preview retention service is unavailable.',
                    'retention_seconds' => (int) ( $import_preview_cleanup_result['retention_seconds'] ?? 0 ),
                    'checked_workspaces' => (int) ( $import_preview_cleanup_result['checked_workspaces'] ?? 0 ),
                    'removed_workspaces' => (int) ( $import_preview_cleanup_result['removed_workspaces'] ?? 0 ),
                    'preserved_fresh' => (int) ( $import_preview_cleanup_result['preserved_fresh'] ?? 0 ),
                    'preserved_retained' => (int) ( $import_preview_cleanup_result['preserved_retained'] ?? 0 ),
                    'skipped_unknown' => (int) ( $import_preview_cleanup_result['skipped_unknown'] ?? 0 ),
                    'skipped_symlinks' => (int) ( $import_preview_cleanup_result['skipped_symlinks'] ?? 0 ),
                    'errors' => (int) ( $import_preview_cleanup_result['errors'] ?? 0 ),
                ],
            ],
                'plugin_tasks' => $plugin_task_results,
                'theme_task' => $theme_task_result,
            ]
        );
    }

    protected function runScheduledPublicationTask( array $context, string $started_at_utc ) : array {
        if ( (string) ( $context['trigger'] ?? '' ) !== 'cli' ) {
            return(
                [
                    'status' => 'skipped',
                    'message' => 'CLI housekeeping is required.',
                    'scheduled_count' => 0,
                    'due_count' => 0,
                    'activated_count' => 0,
                    'completed_event_count' => 0,
                    'pending_event_count' => 0,
                    'failed_count' => 0,
                ]
            );
        }
        if ( ! $this->content_repository instanceof TinyMashContentRepository ) {
            return(
                [
                    'status' => 'error',
                    'message' => 'Content storage is unavailable.',
                    'scheduled_count' => 0,
                    'due_count' => 0,
                    'activated_count' => 0,
                    'completed_event_count' => 0,
                    'pending_event_count' => 0,
                    'failed_count' => 1,
                ]
            );
        }

        try {
            $activation = $this->content_repository->activateDueScheduledEntries( $started_at_utc );
        } catch ( \Throwable $exception ) {
            error_log( basename( __FILE__ ) . ': Scheduled publication activation failed (' . $exception->getMessage() . ')' );
            return(
                [
                    'status' => 'error',
                    'message' => 'Scheduled publication activation failed.',
                    'scheduled_count' => 0,
                    'due_count' => 0,
                    'activated_count' => 0,
                    'completed_event_count' => 0,
                    'pending_event_count' => 0,
                    'failed_count' => 1,
                ]
            );
        }

        $completed_events = 0;
        $pending_events = 0;
        $failed_events = 0;
        foreach ( $this->content_repository->getPendingScheduledPublicationEvents() as $pending_event ) {
            $event = is_array( $pending_event['event'] ?? null ) ? $pending_event['event'] : [];
            $entry = is_array( $pending_event['entry'] ?? null ) ? $pending_event['entry'] : [];
            $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
            $event_id = trim( (string) ( $event['id'] ?? '' ) );
            if ( $entry_id === '' || $event_id === '' ) {
                $failed_events++;
                continue;
            }
            if ( ! $this->plugins instanceof TinyMashPlugins ) {
                $pending_events++;
                continue;
            }

            $previous_entry = $entry;
            $previous_entry['status'] = 'scheduled';
            $handler_results = $this->plugins->runContentLifecycleHandlers(
                $previous_entry,
                $entry,
                [
                    'trigger' => 'scheduled_publication',
                    'actor_username' => 'scheduler',
                    'publication_event_id' => $event_id,
                    'started_at_utc' => $started_at_utc,
                ]
            );
            $handler_errors = [];
            foreach ( $handler_results as $handler_result ) {
                if ( (string) ( $handler_result['status'] ?? '' ) !== 'error' ) {
                    continue;
                }
                $handler_errors[] = trim( (string) ( $handler_result['plugin_key'] ?? 'plugin' ) ) . ': ' . trim( (string) ( $handler_result['message'] ?? 'handler failed' ) );
            }

            $error = implode( '; ', $handler_errors );
            $recorded = $this->content_repository->recordScheduledPublicationEventAttempt( $entry_id, $event_id, $error, $started_at_utc );
            if ( $recorded && $error === '' ) {
                $completed_events++;
            } else {
                $failed_events++;
            }
        }

        $storage_failures = (int) ( $activation['failed_count'] ?? 0 );
        $failed_count = $storage_failures + $failed_events;
        $activated_count = (int) ( $activation['activated_count'] ?? 0 );
        $message = 'Activated ' . $activated_count . ' scheduled content item(s)';
        if ( $completed_events > 0 ) {
            $message .= ' and completed ' . $completed_events . ' publication event(s)';
        }
        if ( $pending_events > 0 ) {
            $message .= '; ' . $pending_events . ' publication event(s) remain pending because plugins were not run';
        }
        $message .= $failed_count > 0 ? '; ' . $failed_count . ' operation(s) require attention.' : '.';

        return(
            [
                'status' => $failed_count > 0 ? 'error' : 'ran',
                'message' => $message,
                'scheduled_count' => (int) ( $activation['scheduled'] ?? 0 ),
                'due_count' => (int) ( $activation['due'] ?? 0 ),
                'activated_count' => $activated_count,
                'completed_event_count' => $completed_events,
                'pending_event_count' => $pending_events,
                'failed_count' => $failed_count,
            ]
        );
    }

    public function runScheduledPublications( array $context = [] ) : array {
        $started_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        return(
            $this->runScheduledPublicationTask(
                array_merge(
                    [
                        'run_type' => 'scheduled_publication',
                        'trigger' => 'cli',
                        'mode' => 'publish_due',
                        'started_at_utc' => $started_at_utc,
                    ],
                    $context
                ),
                $started_at_utc
            )
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
