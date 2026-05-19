<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

class TinyMashFediverseService {

    protected TinyMashConfig $config;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashUserRepository $user_repository;
    protected ?TinyMashMarkdownRenderer $markdown_renderer;
    protected ?TinyMashTheme $theme;
    protected ?TinyMashNotificationService $notification_service;
    protected string $store_filename;

    public function __construct(
        TinyMashConfig $config,
        TinyMashContentRepository $content_repository,
        TinyMashUserRepository $user_repository,
        ?TinyMashMarkdownRenderer $markdown_renderer,
        ?TinyMashTheme $theme,
        ?TinyMashNotificationService $notification_service,
        string $store_filename
    ) {
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->user_repository = $user_repository;
        $this->markdown_renderer = $markdown_renderer;
        $this->theme = $theme;
        $this->notification_service = $notification_service;
        $this->store_filename = $store_filename;
    }

    public function getStoreFilename() : string {
        return( $this->store_filename );
    }

    public function getRootSettings( array $system_settings ) : array {
        return( $this->normalizeAccountSettings( $system_settings, 'root_' ) );
    }

    public function getAuthorSettings( string $author_slug ) : array {
        $author_slug = $this->normalizeUsername( $author_slug );
        if ( $author_slug === '' ) {
            return( $this->getDefaultAccountSettings() );
        }

        return( $this->normalizeAccountSettings( $this->user_repository->getPluginSettings( $author_slug, 'fediverse' ) ) );
    }

    public function getEntrySettings( array $entry, array $root_settings = [] ) : array {
        $scope = strtolower( trim( (string) ( $entry['scope'] ?? 'root' ) ) );
        $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        $defaults = $scope === 'author'
            ? $this->getAuthorSettings( $author_slug )
            : $this->getRootSettings( $root_settings );

        $plugin_settings = is_array( $entry['plugin_settings']['fediverse'] ?? null ) ? $entry['plugin_settings']['fediverse'] : [];
        $enabled = array_key_exists( 'post_enabled', $plugin_settings )
            ? ! empty( $plugin_settings['post_enabled'] )
            : ! empty( $defaults['default_enabled'] );
        $include_link_back = array_key_exists( 'include_link_back', $plugin_settings )
            ? ! empty( $plugin_settings['include_link_back'] )
            : ! empty( $defaults['default_include_link_back'] );

        return(
            [
                'post_enabled' => $enabled,
                'include_link_back' => $include_link_back,
            ]
        );
    }

    public function queueEntrySync( ?array $previous_entry, array $saved_entry, array $root_settings = [], string $actor_username = '' ) : array {
        $entry_id = trim( (string) ( $saved_entry['id'] ?? '' ) );
        if ( $entry_id === '' ) {
            return( [ 'queued' => false, 'reason' => 'missing_entry_id' ] );
        }

        $entry_state = $this->getEntryState( $entry_id );
        $remote_status_id = trim( (string) ( $entry_state['remote_status_id'] ?? '' ) );
        $resolved = $this->resolveOutboundContext( $saved_entry, $root_settings );

        if ( empty( $resolved['eligible'] ) ) {
            if ( $remote_status_id === '' || ! $this->hasFediverseRelevantChanges( $previous_entry, $saved_entry, $root_settings ) ) {
                return(
                    [
                        'queued' => false,
                        'reason' => $remote_status_id === '' ? (string) ( $resolved['reason'] ?? 'not_eligible' ) : 'unchanged',
                    ]
                );
            }

            $queue_item = $this->upsertQueueItem(
                [
                    'entry_id' => $entry_id,
                    'action' => 'delete',
                    'scope' => (string) ( $saved_entry['scope'] ?? 'root' ),
                    'author_slug' => (string) ( $saved_entry['author_slug'] ?? '' ),
                    'actor_username' => $this->normalizeUsername( $actor_username ),
                    'attempts' => 0,
                    'max_attempts' => 5,
                    'next_attempt_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                    'last_error' => '',
                ]
            );

            return(
                [
                    'queued' => true,
                    'action' => 'delete',
                    'queue_item_id' => (string) ( $queue_item['id'] ?? '' ),
                ]
            );
        }

        $action = $remote_status_id === '' ? 'create' : 'update';
        if ( $action === 'update' && ! $this->hasFediverseRelevantChanges( $previous_entry, $saved_entry, $root_settings ) ) {
            return( [ 'queued' => false, 'reason' => 'unchanged' ] );
        }

        $queue_item = $this->upsertQueueItem(
            [
                'entry_id' => $entry_id,
                'action' => $action,
                'scope' => (string) ( $saved_entry['scope'] ?? 'root' ),
                'author_slug' => (string) ( $saved_entry['author_slug'] ?? '' ),
                'actor_username' => $this->normalizeUsername( $actor_username ),
                'attempts' => 0,
                'max_attempts' => 5,
                'next_attempt_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_error' => '',
            ]
        );

        return(
            [
                'queued' => true,
                'action' => $action,
                'queue_item_id' => (string) ( $queue_item['id'] ?? '' ),
            ]
        );
    }

    public function queueEntryRemoval( array $previous_entry, array $root_settings = [], string $actor_username = '' ) : array {
        $entry_id = trim( (string) ( $previous_entry['id'] ?? '' ) );
        if ( $entry_id === '' ) {
            return( [ 'queued' => false, 'reason' => 'missing_entry_id' ] );
        }

        $entry_state = $this->getEntryState( $entry_id );
        $remote_status_id = trim( (string) ( $entry_state['remote_status_id'] ?? '' ) );
        if ( $remote_status_id === '' ) {
            return( [ 'queued' => false, 'reason' => 'no_remote_post' ] );
        }

        $queue_item = $this->upsertQueueItem(
            [
                'entry_id' => $entry_id,
                'action' => 'delete',
                'scope' => (string) ( $previous_entry['scope'] ?? 'root' ),
                'author_slug' => (string) ( $previous_entry['author_slug'] ?? '' ),
                'actor_username' => $this->normalizeUsername( $actor_username ),
                'attempts' => 0,
                'max_attempts' => 5,
                'next_attempt_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'last_error' => '',
            ]
        );

        return(
            [
                'queued' => true,
                'action' => 'delete',
                'queue_item_id' => (string) ( $queue_item['id'] ?? '' ),
            ]
        );
    }

    public function runQueue(array $root_settings = [], array $context = []) : array {
        $claimed_items = $this->claimDueQueueItems( 10 );
        if ( empty( $claimed_items ) ) {
            return(
                [
                    'claimed' => 0,
                    'sent' => 0,
                    'retried' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'message' => 'No Fediverse deliveries were due.',
                ]
            );
        }

        $result = [
            'claimed' => count( $claimed_items ),
            'sent' => 0,
            'retried' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
            'message' => '',
        ];

        foreach ( $claimed_items as $queue_item ) {
            $entry_id = trim( (string) ( $queue_item['entry_id'] ?? '' ) );
            $current_entry = $entry_id !== '' ? $this->content_repository->getAccessibleEntryById( $entry_id ) : null;
            $queue_action = trim( (string) ( $queue_item['action'] ?? '' ) );
            if ( $queue_action === 'delete' ) {
                $entry_state = $this->getEntryState( $entry_id );
                $remote_status_id = trim( (string) ( $entry_state['remote_status_id'] ?? '' ) );
                if ( $remote_status_id === '' ) {
                    $this->finalizeQueueItem(
                        $queue_item,
                        'sent',
                        'No remote Fediverse post remained to delete.',
                        [
                            'entry_state' => [
                                'remote_status_id' => '',
                                'remote_status_url' => '',
                                'last_delivery_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                                'last_error' => '',
                                'scope' => (string) ( $queue_item['scope'] ?? 'root' ),
                                'author_slug' => (string) ( $queue_item['author_slug'] ?? '' ),
                            ],
                        ]
                    );
                    $result['sent']++;
                    $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'sent', 'message' => 'Remote post already cleared.' ];
                    continue;
                }

                $account_settings = $this->resolveAccountSettingsForQueueItem( $queue_item, $root_settings );
                if ( ! $this->hasUsableAccountSettings( $account_settings ) ) {
                    $attempts = (int) ( $queue_item['attempts'] ?? 0 ) + 1;
                    $max_attempts = max( 1, (int) ( $queue_item['max_attempts'] ?? 5 ) );
                    $error_message = 'No Fediverse account is configured for this scope.';
                    if ( $attempts >= $max_attempts ) {
                        $this->finalizeQueueItem( $queue_item, 'failed', $error_message, [] );
                        if ( is_array( $current_entry ) ) {
                            $this->notifyDeliveryFailure( $current_entry, $error_message );
                        }
                        $result['failed']++;
                        $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'failed', 'message' => $error_message ];
                    } else {
                        $this->retryQueueItem( $queue_item, $attempts, $error_message );
                        $result['retried']++;
                        $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'retry', 'message' => $error_message ];
                    }
                    continue;
                }

                $delete_result = $this->deleteRemoteStatus( $account_settings, $remote_status_id );
                if ( ! empty( $delete_result['ok'] ) ) {
                    $this->finalizeQueueItem(
                        $queue_item,
                        'sent',
                        'Deleted remote Fediverse post.',
                        [
                            'entry_state' => [
                                'remote_status_id' => '',
                                'remote_status_url' => '',
                                'last_delivery_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                                'last_error' => '',
                                'scope' => (string) ( $queue_item['scope'] ?? 'root' ),
                                'author_slug' => (string) ( $queue_item['author_slug'] ?? '' ),
                            ],
                            'history_context' => [
                                'remote_status_id' => $remote_status_id,
                                'trigger' => (string) ( $context['trigger'] ?? '' ),
                            ],
                        ]
                    );
                    $result['sent']++;
                    $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'sent', 'message' => 'Deleted remote post.' ];
                    continue;
                }

                $attempts = (int) ( $queue_item['attempts'] ?? 0 ) + 1;
                $max_attempts = max( 1, (int) ( $queue_item['max_attempts'] ?? 5 ) );
                $error_message = trim( (string) ( $delete_result['error'] ?? 'Fediverse delete failed.' ) );
                if ( $attempts >= $max_attempts ) {
                    $this->finalizeQueueItem( $queue_item, 'failed', $error_message, [] );
                    if ( is_array( $current_entry ) ) {
                        $this->notifyDeliveryFailure( $current_entry, $error_message );
                    }
                    $result['failed']++;
                    $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'failed', 'message' => $error_message ];
                } else {
                    $this->retryQueueItem( $queue_item, $attempts, $error_message );
                    $result['retried']++;
                    $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'retry', 'message' => $error_message ];
                }
                continue;
            }

            if ( ! is_array( $current_entry ) ) {
                $this->finalizeQueueItem( $queue_item, 'skipped', 'The content item no longer exists.', [] );
                $result['skipped']++;
                $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'skipped', 'message' => 'Missing content.' ];
                continue;
            }

            $resolved = $this->resolveOutboundContext( $current_entry, $root_settings );
            if ( empty( $resolved['eligible'] ) ) {
                $this->finalizeQueueItem( $queue_item, 'skipped', (string) ( $resolved['message'] ?? 'The content item is no longer eligible for Fediverse delivery.' ), [] );
                $result['skipped']++;
                $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'skipped', 'message' => (string) ( $resolved['message'] ?? 'Not eligible.' ) ];
                continue;
            }

            $entry_state = $this->getEntryState( $entry_id );
            $remote_status_id = trim( (string) ( $entry_state['remote_status_id'] ?? '' ) );
            $action = trim( (string) ( $queue_item['action'] ?? '' ) ) === 'update' && $remote_status_id !== '' ? 'update' : 'create';
            $send_result = $this->sendEntryToMastodon( $current_entry, $resolved['entry_settings'], $resolved['account_settings'], $action, $remote_status_id );

            if ( ! empty( $send_result['ok'] ) ) {
                $response_data = is_array( $send_result['data'] ?? null ) ? $send_result['data'] : [];
                $remote_status_id = trim( (string) ( $response_data['id'] ?? $remote_status_id ) );
                $this->finalizeQueueItem(
                    $queue_item,
                    'sent',
                    $action === 'create' ? 'Posted to Mastodon.' : 'Updated Mastodon post.',
                    [
                        'entry_state' => [
                            'remote_status_id' => $remote_status_id,
                            'remote_status_url' => trim( (string) ( $response_data['url'] ?? '' ) ),
                            'last_delivery_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                            'last_error' => '',
                            'scope' => (string) ( $current_entry['scope'] ?? 'root' ),
                            'author_slug' => (string) ( $current_entry['author_slug'] ?? '' ),
                        ],
                        'history_context' => [
                            'remote_status_id' => $remote_status_id,
                            'remote_status_url' => trim( (string) ( $response_data['url'] ?? '' ) ),
                            'trigger' => (string) ( $context['trigger'] ?? '' ),
                        ],
                    ]
                );
                $result['sent']++;
                $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'sent', 'message' => $action === 'create' ? 'Posted.' : 'Updated.' ];
                continue;
            }

            $attempts = (int) ( $queue_item['attempts'] ?? 0 ) + 1;
            $max_attempts = max( 1, (int) ( $queue_item['max_attempts'] ?? 5 ) );
            $error_message = trim( (string) ( $send_result['error'] ?? 'Mastodon delivery failed.' ) );

            if ( $attempts >= $max_attempts ) {
                $this->finalizeQueueItem( $queue_item, 'failed', $error_message, [] );
                $this->notifyDeliveryFailure( $current_entry, $error_message );
                $result['failed']++;
                $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'failed', 'message' => $error_message ];
                continue;
            }

            $this->retryQueueItem( $queue_item, $attempts, $error_message );
            $result['retried']++;
            $result['items'][] = [ 'entry_id' => $entry_id, 'status' => 'retry', 'message' => $error_message ];
        }

        $result['message'] = 'Fediverse deliveries: '
            . $result['sent'] . ' sent, '
            . $result['retried'] . ' scheduled for retry, '
            . $result['failed'] . ' failed, '
            . $result['skipped'] . ' skipped.';

        return( $result );
    }

    public function getDeliveryLog( string $author_slug = '', int $limit = 50 ) : array {
        $store = $this->readStore();
        $author_slug = $this->normalizeUsername( $author_slug );
        $items = [];

        foreach ( (array) ( $store['queue'] ?? [] ) as $queue_item ) {
            $queue_item = $this->normalizeQueueItem( is_array( $queue_item ) ? $queue_item : [] );
            if ( empty( $queue_item['id'] ) ) {
                continue;
            }
            if ( $author_slug !== '' && (string) ( $queue_item['author_slug'] ?? '' ) !== $author_slug ) {
                continue;
            }

            $queue_item['log_type'] = 'queue';
            $queue_item['recorded_at_utc'] = (string) ( $queue_item['updated_at_utc'] ?? $queue_item['created_at_utc'] ?? '' );
            $items[] = $queue_item;
        }

        foreach ( (array) ( $store['history'] ?? [] ) as $history_item ) {
            $history_item = $this->normalizeHistoryItem( is_array( $history_item ) ? $history_item : [] );
            if ( empty( $history_item['id'] ) ) {
                continue;
            }
            if ( $author_slug !== '' && (string) ( $history_item['author_slug'] ?? '' ) !== $author_slug ) {
                continue;
            }

            $history_item['log_type'] = 'history';
            $history_item['recorded_at_utc'] = (string) ( $history_item['created_at_utc'] ?? '' );
            $items[] = $history_item;
        }

        usort(
            $items,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $right['recorded_at_utc'] ?? '' ), (string) ( $left['recorded_at_utc'] ?? '' ) ) );
            }
        );

        return( $limit > 0 ? array_slice( $items, 0, $limit ) : $items );
    }

    public function buildEntryEditorSettings( array $draft, array $root_settings = [] ) : array {
        return( $this->getEntrySettings( $draft, $root_settings ) );
    }

    public function describeEligibility(array $draft, array $root_settings = []) : array {
        return( $this->resolveOutboundContext( $draft, $root_settings ) );
    }

    public function buildDeliveryReadiness( array $draft, array $root_settings = [] ) : array {
        $resolved = $this->resolveOutboundContext( $draft, $root_settings );
        $preview = $this->buildStatusPreview(
            $draft,
            is_array( $resolved['entry_settings'] ?? null ) ? $resolved['entry_settings'] : [],
            is_array( $resolved['account_settings'] ?? null ) ? $resolved['account_settings'] : []
        );
        $warnings = $this->buildReadinessWarnings( $draft, $resolved, $preview );
        $state = ! empty( $resolved['eligible'] )
            ? ( empty( $warnings ) ? 'ready' : 'warning' )
            : 'blocked';

        return(
            [
                'state' => $state,
                'eligible' => ! empty( $resolved['eligible'] ),
                'reason' => (string) ( $resolved['reason'] ?? '' ),
                'message' => (string) ( $resolved['message'] ?? '' ),
                'entry_settings' => is_array( $resolved['entry_settings'] ?? null ) ? $resolved['entry_settings'] : [],
                'account_settings' => [
                    'instance_url' => (string) ( $resolved['account_settings']['instance_url'] ?? '' ),
                    'status_character_limit' => (int) ( $resolved['account_settings']['status_character_limit'] ?? 500 ),
                ],
                'preview' => $preview,
                'warnings' => $warnings,
            ]
        );
    }

    protected function resolveOutboundContext( array $entry, array $root_settings = [] ) : array {
        $entry_type = strtolower( trim( (string) ( $entry['type'] ?? $entry['entry_type'] ?? 'post' ) ) );
        $status = strtolower( trim( (string) ( $entry['status'] ?? 'unpublished' ) ) );
        $scope = strtolower( trim( (string) ( $entry['scope'] ?? 'root' ) ) );
        $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        $account_settings = $scope === 'author' ? $this->getAuthorSettings( $author_slug ) : $this->getRootSettings( $root_settings );
        $entry_settings = $this->getEntrySettings( $entry, $root_settings );

        if ( $entry_type !== 'post' ) {
            return( [ 'eligible' => false, 'reason' => 'entry_type', 'message' => 'Only posts are sent to the Fediverse.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }
        if ( $status !== 'published' ) {
            return( [ 'eligible' => false, 'reason' => 'status', 'message' => 'Only published posts are sent to the Fediverse.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }
        if ( empty( $entry_settings['post_enabled'] ) ) {
            return( [ 'eligible' => false, 'reason' => 'disabled', 'message' => 'Fediverse posting is disabled for this entry.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }
        if ( ! $this->hasUsableAccountSettings( $account_settings ) ) {
            return( [ 'eligible' => false, 'reason' => 'credentials', 'message' => 'No Fediverse account is configured for this scope.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }
        if ( $scope === 'author' && ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
            return( [ 'eligible' => false, 'reason' => 'visibility', 'message' => 'Only public author-space posts are sent to Mastodon.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }
        if ( $scope === 'root' && ! $this->config->isSitePublic() ) {
            return( [ 'eligible' => false, 'reason' => 'visibility', 'message' => 'Only public root-space posts are sent to Mastodon.', 'entry_settings' => $entry_settings, 'account_settings' => $account_settings ] );
        }

        return(
            [
                'eligible' => true,
                'reason' => '',
                'message' => 'Eligible for Fediverse delivery.',
                'entry_settings' => $entry_settings,
                'account_settings' => $account_settings,
            ]
        );
    }

    protected function hasFediverseRelevantChanges( ?array $previous_entry, array $saved_entry, array $root_settings = [] ) : bool {
        if ( ! is_array( $previous_entry ) ) {
            return( true );
        }

        $previous_settings = $this->getEntrySettings( $previous_entry, $root_settings );
        $saved_settings = $this->getEntrySettings( $saved_entry, $root_settings );

        return(
            [
                'summary' => trim( (string) ( $previous_entry['summary'] ?? '' ) ),
                'content' => (string) ( $previous_entry['content'] ?? '' ),
                'tags' => is_array( $previous_entry['tags'] ?? null ) ? array_values( $previous_entry['tags'] ) : [],
                'slug' => (string) ( $previous_entry['slug'] ?? '' ),
                'published_at_utc' => (string) ( $previous_entry['published_at_utc'] ?? '' ),
                'status' => (string) ( $previous_entry['status'] ?? '' ),
                'settings' => $previous_settings,
            ]
            !==
            [
                'summary' => trim( (string) ( $saved_entry['summary'] ?? '' ) ),
                'content' => (string) ( $saved_entry['content'] ?? '' ),
                'tags' => is_array( $saved_entry['tags'] ?? null ) ? array_values( $saved_entry['tags'] ) : [],
                'slug' => (string) ( $saved_entry['slug'] ?? '' ),
                'published_at_utc' => (string) ( $saved_entry['published_at_utc'] ?? '' ),
                'status' => (string) ( $saved_entry['status'] ?? '' ),
                'settings' => $saved_settings,
            ]
        );
    }

    protected function sendEntryToMastodon( array $entry, array $entry_settings, array $account_settings, string $action, string $remote_status_id = '' ) : array {
        $status_text = $this->buildStatusText( $entry, $entry_settings, $account_settings );
        if ( $status_text === '' ) {
            return( [ 'ok' => false, 'error' => 'The post could not be converted into a Mastodon status.' ] );
        }

        if ( $action === 'update' && $remote_status_id !== '' ) {
            return( $this->requestMastodon( $account_settings['instance_url'], $account_settings['access_token'], 'PUT', '/api/v1/statuses/' . rawurlencode( $remote_status_id ), [ 'status' => $status_text ] ) );
        }

        return(
            $this->requestMastodon(
                $account_settings['instance_url'],
                $account_settings['access_token'],
                'POST',
                '/api/v1/statuses',
                [
                    'status' => $status_text,
                    'visibility' => 'public',
                ],
                [
                    'Idempotency-Key: ' . $this->buildIdempotencyKey( (string) ( $entry['id'] ?? '' ), $status_text ),
                ]
            )
        );
    }

    protected function deleteRemoteStatus( array $account_settings, string $remote_status_id ) : array {
        $response = $this->requestMastodon(
            (string) ( $account_settings['instance_url'] ?? '' ),
            (string) ( $account_settings['access_token'] ?? '' ),
            'DELETE',
            '/api/v1/statuses/' . rawurlencode( trim( $remote_status_id ) )
        );

        if ( ! empty( $response['ok'] ) || (int) ( $response['status_code'] ?? 0 ) === 404 ) {
            return(
                [
                    'ok' => true,
                    'status_code' => (int) ( $response['status_code'] ?? 0 ),
                    'data' => is_array( $response['data'] ?? null ) ? $response['data'] : [],
                ]
            );
        }

        return( $response );
    }

    protected function buildStatusText( array $entry, array $entry_settings, array $account_settings = [] ) : string {
        $preview = $this->buildStatusPreview( $entry, $entry_settings, $account_settings );
        return( (string) ( $preview['status_text'] ?? '' ) );
    }

    protected function buildStatusPreview( array $entry, array $entry_settings, array $account_settings = [] ) : array {
        $character_limit = $this->normalizeStatusCharacterLimit( $account_settings['status_character_limit'] ?? null );
        $title = $this->normalizeStatusFragmentText( (string) ( $entry['title'] ?? '' ) );
        $summary = $this->normalizeStatusFragmentText( (string) ( $entry['summary'] ?? '' ) );
        $excerpt = $summary !== '' ? $summary : $this->buildExcerptFromMarkdown( (string) ( $entry['content'] ?? '' ) );
        $hashtags = $this->buildHashtags( is_array( $entry['tags'] ?? null ) ? $entry['tags'] : [] );
        $url = ! empty( $entry_settings['include_link_back'] ) ? $this->buildAbsoluteEntryUrl( $entry ) : '';

        $parts = $this->buildStatusParts( $excerpt, $hashtags, $url );
        $status = implode( "\n\n", $parts );
        if ( mb_strlen( $status ) <= $character_limit ) {
            return(
                [
                    'status_text' => $status,
                    'raw_status_text' => $status,
                    'character_limit' => $character_limit,
                    'character_count' => mb_strlen( $status ),
                    'raw_character_count' => mb_strlen( $status ),
                    'will_truncate' => false,
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'hashtags' => $hashtags,
                    'link_back_url' => $url,
                    'include_link_back' => $url !== '',
                ]
            );
        }

        if ( $excerpt !== '' ) {
            $remaining = $character_limit - mb_strlen( $hashtags ) - ( $url !== '' ? mb_strlen( $url ) : 0 ) - 6;
            if ( $remaining < 40 ) {
                $remaining = 40;
            }
            $excerpt = mb_substr( $excerpt, 0, max( 0, $remaining - 1 ) );
            $excerpt = rtrim( $excerpt, " \t\n\r\0\x0B.,;:-" ) . '…';
        }

        $parts = $this->buildStatusParts( $excerpt, $hashtags, $url );
        $final_status = implode( "\n\n", $parts );

        return(
            [
                'status_text' => $final_status,
                'raw_status_text' => $status,
                'character_limit' => $character_limit,
                'character_count' => mb_strlen( $final_status ),
                'raw_character_count' => mb_strlen( $status ),
                'will_truncate' => $final_status !== $status,
                'title' => $title,
                'excerpt' => $excerpt,
                'hashtags' => $hashtags,
                'link_back_url' => $url,
                'include_link_back' => $url !== '',
            ]
        );
    }

    protected function buildStatusParts( string $excerpt, string $hashtags, string $url ) : array {
        $parts = [];

        if ( $excerpt !== '' ) {
            $parts[] = $excerpt;
        }
        if ( $hashtags !== '' ) {
            $parts[] = $hashtags;
        }
        if ( $url !== '' ) {
            $parts[] = $url;
        }

        return( $parts );
    }

    protected function buildReadinessWarnings( array $entry, array $resolved, array $preview ) : array {
        $warnings = [];
        $content = (string) ( $entry['content'] ?? '' );
        $summary = trim( (string) ( $entry['summary'] ?? '' ) );
        $status_text = trim( (string) ( $preview['status_text'] ?? '' ) );

        if ( ! empty( $preview['will_truncate'] ) ) {
            $warnings[] = 'This Fediverse post is longer than the configured character limit and will be shortened before delivery.';
        }

        if ( $summary === '' && $status_text !== '' ) {
            $warnings[] = 'No summary is set. The Fediverse post will use an automatic excerpt from the body text.';
        }

        if ( preg_match( '/\[[^\]]+\]\([^)]+\)/u', $content ) === 1 || preg_match( '/<a\b[^>]*>/i', $content ) === 1 ) {
            $warnings[] = 'Links are flattened into plain text in the outbound Fediverse post. Important URLs should still be visible, but the formatting will not match the original post.';
        }

        if ( preg_match( '/(^|\n)```/u', $content ) === 1 || preg_match( '/`[^`\n]+`/u', $content ) === 1 ) {
            $warnings[] = 'Code blocks and inline code do not survive as rich formatting in the outbound Fediverse text.';
        }

        if ( preg_match( '/^\|.+\|/m', $content ) === 1 ) {
            $warnings[] = 'Tables do not render cleanly on Fediverse platforms and will be flattened in the outbound text.';
        }

        if ( preg_match( '/^\s*\[![A-Z]+/m', $content ) === 1 ) {
            $warnings[] = 'Callouts are flattened to plain text in the outbound Fediverse post.';
        }

        if ( preg_match( '/<\s*[a-z][^>]*>/i', $content ) === 1 ) {
            $warnings[] = 'Raw HTML is not preserved in the outbound Fediverse text.';
        }

        if ( preg_match( '/^\s*[-*+]\s+\[[ xX]\]/m', $content ) === 1 ) {
            $warnings[] = 'Task lists lose their interactive meaning and are sent as plain text.';
        }

        if ( empty( $resolved['eligible'] ) && (string) ( $resolved['reason'] ?? '' ) === 'credentials' ) {
            $warnings[] = 'Configure a Mastodon-compatible account first, or this post cannot be queued for delivery.';
        }

        return( array_values( array_unique( $warnings ) ) );
    }

    protected function buildHashtags( array $tags, int $limit = 4 ) : string {
        $hashtags = [];
        foreach ( $tags as $tag ) {
            if ( ! is_scalar( $tag ) && $tag !== null ) {
                continue;
            }

            $normalized_tag = strtolower( trim( (string) $tag ) );
            $normalized_tag = preg_replace( '/[^a-z0-9]+/i', '', $normalized_tag ) ?? '';
            if ( $normalized_tag === '' ) {
                continue;
            }

            $hashtag = '#' . $normalized_tag;
            if ( in_array( $hashtag, $hashtags, true ) ) {
                continue;
            }

            $hashtags[] = $hashtag;
            if ( count( $hashtags ) >= $limit ) {
                break;
            }
        }

        return( implode( ' ', $hashtags ) );
    }

    protected function buildExcerptFromMarkdown( string $markdown ) : string {
        $markdown = trim( $markdown );
        if ( $markdown === '' ) {
            return( '' );
        }

        if ( $this->markdown_renderer instanceof TinyMashMarkdownRenderer ) {
            $html = $this->markdown_renderer->render( $markdown, [ 'classic_smileys_enabled' => true ] );
            $text = $this->normalizeStatusFragmentText( $html, true );
            return( mb_substr( $text, 0, 260 ) );
        }

        $text = $this->normalizeStatusFragmentText( $markdown );
        return( mb_substr( $text, 0, 260 ) );
    }

    protected function normalizeStatusFragmentText( string $text, bool $is_rendered_html = false ) : string {
        $text = trim( $text );
        if ( $text === '' ) {
            return( '' );
        }

        if ( $is_rendered_html ) {
            $text = $this->flattenHtmlLinks( $text );
            $text = trim( preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) ?? '' );
            return( $text );
        }

        $text = $this->flattenMarkdownLinks( $text );
        $text = $this->flattenHtmlLinks( $text );
        $text = trim( preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) ?? '' );
        return( $text );
    }

    protected function flattenMarkdownLinks( string $text ) : string {
        return(
            preg_replace_callback(
                '/\[(.*?)\]\((https?:\/\/[^)\s]+)\)/iu',
                static function( array $matches ) : string {
                    $label = trim( (string) ( $matches[1] ?? '' ) );
                    $url = trim( (string) ( $matches[2] ?? '' ) );
                    if ( $url === '' ) {
                        return( $label );
                    }
                    if ( $label === '' || $label === $url ) {
                        return( $url );
                    }

                    return( $label . ' (' . $url . ')' );
                },
                $text
            ) ?? $text
        );
    }

    protected function flattenHtmlLinks( string $text ) : string {
        return(
            preg_replace_callback(
                '/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/isu',
                static function( array $matches ) : string {
                    $url = trim( html_entity_decode( (string) ( $matches[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    $label = trim( html_entity_decode( strip_tags( (string) ( $matches[3] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    if ( $url === '' ) {
                        return( $label );
                    }
                    if ( $label === '' || $label === $url ) {
                        return( $url );
                    }

                    return( $label . ' (' . $url . ')' );
                },
                $text
            ) ?? $text
        );
    }

    protected function buildAbsoluteEntryUrl( array $entry ) : string {
        $entry = $this->normalizePreviewEntryForUrl( $entry );
        $relative_url = '';
        if ( $this->theme instanceof TinyMashTheme ) {
            $relative_url = (string) $this->theme->getEntryURL( $entry );
        }

        $relative_url = trim( $relative_url );
        if ( $relative_url === '' ) {
            $relative_url = '/';
        }
        if ( preg_match( '/^https?:\/\//i', $relative_url ) === 1 ) {
            return( $relative_url );
        }

        $base_url = rtrim( (string) ( $this->config->configGetBaseURL() ?: '' ), '/' );
        if ( $base_url === '' && class_exists( '\Flight' ) ) {
            try {
                $app = \Flight::app();
                $base_url = rtrim( (string) ( is_object( $app ) ? $app->get( 'app.url' ) : '' ), '/' );
            } catch ( \Throwable $e ) {
                $base_url = '';
            }
        }
        return( $base_url !== '' ? ( $base_url . '/' . ltrim( $relative_url, '/' ) ) : $relative_url );
    }

    protected function normalizePreviewEntryForUrl( array $entry ) : array {
        $normalized_entry = $entry;
        if ( ! array_key_exists( 'type', $normalized_entry ) || ! is_string( $normalized_entry['type'] ?? null ) || trim( (string) $normalized_entry['type'] ) === '' ) {
            $normalized_entry['type'] = strtolower( trim( (string) ( $normalized_entry['entry_type'] ?? 'post' ) ) ) === 'page' ? 'page' : 'post';
        }
        if ( ! array_key_exists( 'scope', $normalized_entry ) || ! is_string( $normalized_entry['scope'] ?? null ) || trim( (string) $normalized_entry['scope'] ) === '' ) {
            $normalized_entry['scope'] = 'root';
        }
        if ( ! array_key_exists( 'author_slug', $normalized_entry ) || ! is_string( $normalized_entry['author_slug'] ?? null ) ) {
            $normalized_entry['author_slug'] = '';
        }
        if ( ! array_key_exists( 'parent_slug', $normalized_entry ) || ! is_string( $normalized_entry['parent_slug'] ?? null ) ) {
            $normalized_entry['parent_slug'] = '';
        }
        if ( ! array_key_exists( 'slug', $normalized_entry ) || ! is_string( $normalized_entry['slug'] ?? null ) ) {
            $normalized_entry['slug'] = '';
        }

        return( $normalized_entry );
    }

    protected function notifyDeliveryFailure( array $entry, string $error_message ) : void {
        if ( ! $this->notification_service instanceof TinyMashNotificationService ) {
            return;
        }

        try {
            $this->notification_service->createNotification(
                'fediverse_delivery_failed',
                'Fediverse delivery failed',
                'Unable to deliver "' . trim( (string) ( $entry['title'] ?? 'Untitled post' ) ) . '" to Mastodon: ' . $error_message,
                [
                    'severity' => 'warning',
                    'target_username' => (string) ( $entry['author_slug'] ?? '' ),
                    'dedupe_key' => 'fediverse-delivery:' . (string) ( $entry['id'] ?? '' ),
                    'dedupe_window_seconds' => 3600,
                    'context' => [
                        'entry_id' => (string) ( $entry['id'] ?? '' ),
                        'path' => (string) ( $entry['path'] ?? '' ),
                    ],
                ]
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to create Fediverse failure notification (' . $e->getMessage() . ')' );
        }
    }

    protected function upsertQueueItem( array $queue_item ) : array {
        $queue_item = $this->normalizeQueueItem( $queue_item );
        $result = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $queue_item ) : array {
                $store = $this->normalizeStore( $store );
                $matched_existing = [];
                foreach ( $store['queue'] as $index => $existing_queue_item ) {
                    $existing_queue_item = $this->normalizeQueueItem( is_array( $existing_queue_item ) ? $existing_queue_item : [] );
                    if ( $existing_queue_item['entry_id'] !== $queue_item['entry_id'] ) {
                        continue;
                    }

                    if ( (string) ( $existing_queue_item['status'] ?? '' ) === 'processing' ) {
                        continue;
                    }

                    $matched_existing = $existing_queue_item;
                    unset( $store['queue'][$index] );
                }

                $merged_queue_item = array_merge( $matched_existing, $queue_item );
                if ( trim( (string) ( $merged_queue_item['id'] ?? '' ) ) === '' ) {
                    $merged_queue_item['id'] = $this->generateQueueItemId();
                }
                $merged_queue_item['status'] = 'queued';
                $merged_queue_item['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                if ( trim( (string) ( $merged_queue_item['created_at_utc'] ?? '' ) ) === '' ) {
                    $merged_queue_item['created_at_utc'] = $merged_queue_item['updated_at_utc'];
                }

                array_unshift( $store['queue'], $this->normalizeQueueItem( $merged_queue_item ) );
                $store['queue'] = array_values( $store['queue'] );

                return( [ 'data' => $store, 'result' => $merged_queue_item ] );
            },
            $this->getDefaultStore()
        );

        return( is_array( $result ) ? $this->normalizeQueueItem( $result ) : [] );
    }

    protected function claimDueQueueItems( int $limit ) : array {
        $limit = max( 1, $limit );
        $claimed = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $limit ) : array {
                $store = $this->normalizeStore( $store );
                $claimed_items = [];
                $now = time();
                foreach ( $store['queue'] as $index => $queue_item ) {
                    $queue_item = $this->normalizeQueueItem( is_array( $queue_item ) ? $queue_item : [] );
                    if ( $queue_item['status'] !== 'queued' ) {
                        $store['queue'][$index] = $queue_item;
                        continue;
                    }

                    $next_attempt_timestamp = strtotime( (string) ( $queue_item['next_attempt_utc'] ?? '' ) );
                    if ( $next_attempt_timestamp !== false && $next_attempt_timestamp > $now ) {
                        $store['queue'][$index] = $queue_item;
                        continue;
                    }

                    $queue_item['status'] = 'processing';
                    $queue_item['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    $store['queue'][$index] = $queue_item;
                    $claimed_items[] = $queue_item;
                    if ( count( $claimed_items ) >= $limit ) {
                        break;
                    }
                }

                return( [ 'data' => $store, 'result' => $claimed_items ] );
            },
            $this->getDefaultStore()
        );

        return( is_array( $claimed ) ? array_values( $claimed ) : [] );
    }

    protected function retryQueueItem( array $queue_item, int $attempts, string $error_message ) : void {
        $queue_item_id = trim( (string) ( $queue_item['id'] ?? '' ) );
        if ( $queue_item_id === '' ) {
            return;
        }

        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $queue_item_id, $attempts, $error_message ) : array {
                $store = $this->normalizeStore( $store );
                foreach ( $store['queue'] as $index => $existing_queue_item ) {
                    $existing_queue_item = $this->normalizeQueueItem( is_array( $existing_queue_item ) ? $existing_queue_item : [] );
                    if ( $existing_queue_item['id'] !== $queue_item_id ) {
                        $store['queue'][$index] = $existing_queue_item;
                        continue;
                    }

                    $existing_queue_item['attempts'] = $attempts;
                    $existing_queue_item['status'] = 'queued';
                    $existing_queue_item['last_error'] = $error_message;
                    $existing_queue_item['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    $existing_queue_item['next_attempt_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + $this->resolveRetryDelaySeconds( $attempts ) );
                    $store['queue'][$index] = $existing_queue_item;
                    break;
                }

                return( [ 'data' => $store, 'result' => true ] );
            },
            $this->getDefaultStore()
        );
    }

    protected function finalizeQueueItem( array $queue_item, string $result_status, string $message, array $options = [] ) : void {
        $queue_item = $this->normalizeQueueItem( $queue_item );
        if ( $queue_item['id'] === '' ) {
            return;
        }

        $history_item = [
            'id' => $this->generateHistoryId(),
            'entry_id' => $queue_item['entry_id'],
            'action' => $queue_item['action'],
            'scope' => $queue_item['scope'],
            'author_slug' => $queue_item['author_slug'],
            'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'status' => $result_status,
            'message' => $message,
            'attempts' => (int) ( $queue_item['attempts'] ?? 0 ),
            'context' => is_array( $options['history_context'] ?? null ) ? $options['history_context'] : [],
        ];
        $entry_state = is_array( $options['entry_state'] ?? null ) ? $options['entry_state'] : null;

        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $queue_item, $history_item, $entry_state ) : array {
                $store = $this->normalizeStore( $store );
                $store['queue'] = array_values(
                    array_filter(
                        $store['queue'],
                        static function( mixed $existing_queue_item ) use ( $queue_item ) : bool {
                            return( ! is_array( $existing_queue_item ) || (string) ( $existing_queue_item['id'] ?? '' ) !== $queue_item['id'] );
                        }
                    )
                );

                array_unshift( $store['history'], $this->normalizeHistoryItem( $history_item ) );
                $store['history'] = array_slice( array_values( $store['history'] ), 0, 200 );

                if ( is_array( $entry_state ) && $queue_item['entry_id'] !== '' ) {
                    $store['entries'][$queue_item['entry_id']] = $this->normalizeEntryState( $entry_state );
                }

                return( [ 'data' => $store, 'result' => true ] );
            },
            $this->getDefaultStore()
        );
    }

    protected function getEntryState( string $entry_id ) : array {
        $store = $this->readStore();
        return( $this->normalizeEntryState( is_array( $store['entries'][$entry_id] ?? null ) ? $store['entries'][$entry_id] : [] ) );
    }

    protected function requestMastodon( string $instance_url, string $access_token, string $method, string $path, array $form_fields = [], array $extra_headers = [] ) : array {
        $instance_url = rtrim( $instance_url, '/' );
        $url = $instance_url . $path;
        $method = strtoupper( trim( $method ) );
        $headers = array_merge(
            [
                'Accept: application/json',
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: tinymash/' . APP_VERSION,
            ],
            $extra_headers
        );
        $body = http_build_query( $form_fields );

        if ( function_exists( 'curl_init' ) ) {
            $handle = curl_init( $url );
            if ( $handle === false ) {
                return( [ 'ok' => false, 'error' => 'Unable to initialize the HTTP client.' ] );
            }

            curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $handle, CURLOPT_HEADER, true );
            curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
            curl_setopt( $handle, CURLOPT_TIMEOUT, 20 );
            curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10 );
            curl_setopt( $handle, CURLOPT_HTTPHEADER, $headers );
            curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
            if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
            }

            $response = curl_exec( $handle );
            if ( $response === false ) {
                $error_message = curl_error( $handle );
                curl_close( $handle );
                return( [ 'ok' => false, 'error' => $error_message !== '' ? $error_message : 'The Mastodon request failed.' ] );
            }

            $status_code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
            $header_size = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
            curl_close( $handle );
            $response_body = substr( (string) $response, $header_size );

            return( $this->normalizeApiResponse( $status_code, $response_body ) );
        }

        $context = stream_context_create(
            [
                'http' => [
                    'method' => $method,
                    'header' => implode( "\r\n", $headers ),
                    'content' => in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ? $body : '',
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]
        );
        $response_body = @ file_get_contents( $url, false, $context );
        $status_code = 0;
        foreach ( (array) ( $http_response_header ?? [] ) as $header_line ) {
            if ( preg_match( '/\s(\d{3})\s/', (string) $header_line, $matches ) === 1 ) {
                $status_code = (int) $matches[1];
                break;
            }
        }

        return( $this->normalizeApiResponse( $status_code, is_string( $response_body ) ? $response_body : '' ) );
    }

    protected function normalizeApiResponse( int $status_code, string $response_body ) : array {
        $decoded = json_decode( $response_body, true, 32 );
        $decoded = is_array( $decoded ) ? $decoded : [];
        if ( $status_code >= 200 && $status_code < 300 ) {
            return( [ 'ok' => true, 'status_code' => $status_code, 'data' => $decoded ] );
        }

        $error_message = trim( (string) ( $decoded['error'] ?? '' ) );
        if ( $error_message === '' ) {
            $error_message = 'Mastodon returned HTTP ' . $status_code . '.';
        }

        return( [ 'ok' => false, 'status_code' => $status_code, 'error' => $error_message, 'data' => $decoded ] );
    }

    protected function buildIdempotencyKey( string $entry_id, string $status_text ) : string {
        return( substr( hash( 'sha256', $entry_id . "\n" . $status_text ), 0, 64 ) );
    }

    protected function hasUsableAccountSettings( array $settings ) : bool {
        return( trim( (string) ( $settings['instance_url'] ?? '' ) ) !== '' && trim( (string) ( $settings['access_token'] ?? '' ) ) !== '' );
    }

    protected function resolveAccountSettingsForQueueItem( array $queue_item, array $root_settings = [] ) : array {
        $scope = (string) ( $queue_item['scope'] ?? 'root' ) === 'author' ? 'author' : 'root';
        $author_slug = $this->normalizeUsername( (string) ( $queue_item['author_slug'] ?? '' ) );
        return( $scope === 'author' ? $this->getAuthorSettings( $author_slug ) : $this->getRootSettings( $root_settings ) );
    }

    protected function normalizeAccountSettings( array $settings, string $prefix = '' ) : array {
        $defaults = $this->getDefaultAccountSettings();
        $instance_url = trim( (string) ( $settings[$prefix . 'instance_url'] ?? '' ) );
        if ( $instance_url !== '' && preg_match( '#^https?://#i', $instance_url ) !== 1 ) {
            $instance_url = 'https://' . ltrim( $instance_url, '/' );
        }

        return(
            [
                'instance_url' => rtrim( $instance_url, '/' ),
                'access_token' => trim( (string) ( $settings[$prefix . 'access_token'] ?? '' ) ),
                'default_enabled' => array_key_exists( $prefix . 'default_enabled', $settings ) ? ! empty( $settings[$prefix . 'default_enabled'] ) : $defaults['default_enabled'],
                'default_include_link_back' => array_key_exists( $prefix . 'default_include_link_back', $settings ) ? ! empty( $settings[$prefix . 'default_include_link_back'] ) : $defaults['default_include_link_back'],
                'status_character_limit' => $this->normalizeStatusCharacterLimit( $settings[$prefix . 'status_character_limit'] ?? $defaults['status_character_limit'] ),
            ]
        );
    }

    protected function getDefaultAccountSettings() : array {
        return(
            [
                'instance_url' => '',
                'access_token' => '',
                'default_enabled' => false,
                'default_include_link_back' => true,
                'status_character_limit' => 500,
            ]
        );
    }

    protected function normalizeStatusCharacterLimit( mixed $value ) : int {
        $limit = (int) $value;
        if ( $limit < 100 ) {
            return( 500 );
        }

        return( min( 5000, $limit ) );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->getDefaultStore() ) ) );
    }

    protected function getDefaultStore() : array {
        return(
            [
                'format' => 'tinymash-fediverse',
                'queue' => [],
                'history' => [],
                'entries' => [],
            ]
        );
    }

    protected function normalizeStore( array $store ) : array {
        $queue = [];
        foreach ( (array) ( $store['queue'] ?? [] ) as $queue_item ) {
            $normalized_queue_item = $this->normalizeQueueItem( is_array( $queue_item ) ? $queue_item : [] );
            if ( $normalized_queue_item['id'] !== '' ) {
                $queue[] = $normalized_queue_item;
            }
        }

        $history = [];
        foreach ( (array) ( $store['history'] ?? [] ) as $history_item ) {
            $normalized_history_item = $this->normalizeHistoryItem( is_array( $history_item ) ? $history_item : [] );
            if ( $normalized_history_item['id'] !== '' ) {
                $history[] = $normalized_history_item;
            }
        }

        $entries = [];
        foreach ( (array) ( $store['entries'] ?? [] ) as $entry_id => $entry_state ) {
            if ( ! is_string( $entry_id ) ) {
                continue;
            }

            $entry_id = trim( $entry_id );
            if ( $entry_id === '' ) {
                continue;
            }

            $entries[$entry_id] = $this->normalizeEntryState( is_array( $entry_state ) ? $entry_state : [] );
        }

        return(
            [
                'format' => 'tinymash-fediverse',
                'queue' => $queue,
                'history' => $history,
                'entries' => $entries,
            ]
        );
    }

    protected function normalizeQueueItem( array $queue_item ) : array {
        return(
            [
                'id' => trim( (string) ( $queue_item['id'] ?? '' ) ),
                'entry_id' => trim( (string) ( $queue_item['entry_id'] ?? '' ) ),
                'action' => in_array( (string) ( $queue_item['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) ? (string) $queue_item['action'] : 'create',
                'scope' => (string) ( $queue_item['scope'] ?? 'root' ) === 'author' ? 'author' : 'root',
                'author_slug' => $this->normalizeUsername( (string) ( $queue_item['author_slug'] ?? '' ) ),
                'actor_username' => $this->normalizeUsername( (string) ( $queue_item['actor_username'] ?? '' ) ),
                'status' => in_array( (string) ( $queue_item['status'] ?? '' ), [ 'queued', 'processing' ], true ) ? (string) $queue_item['status'] : 'queued',
                'attempts' => max( 0, (int) ( $queue_item['attempts'] ?? 0 ) ),
                'max_attempts' => max( 1, (int) ( $queue_item['max_attempts'] ?? 5 ) ),
                'next_attempt_utc' => trim( (string) ( $queue_item['next_attempt_utc'] ?? '' ) ),
                'last_error' => trim( (string) ( $queue_item['last_error'] ?? '' ) ),
                'created_at_utc' => trim( (string) ( $queue_item['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $queue_item['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeHistoryItem( array $history_item ) : array {
        return(
            [
                'id' => trim( (string) ( $history_item['id'] ?? '' ) ),
                'entry_id' => trim( (string) ( $history_item['entry_id'] ?? '' ) ),
                'action' => in_array( (string) ( $history_item['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) ? (string) $history_item['action'] : 'create',
                'scope' => (string) ( $history_item['scope'] ?? 'root' ) === 'author' ? 'author' : 'root',
                'author_slug' => $this->normalizeUsername( (string) ( $history_item['author_slug'] ?? '' ) ),
                'created_at_utc' => trim( (string) ( $history_item['created_at_utc'] ?? '' ) ),
                'status' => in_array( (string) ( $history_item['status'] ?? '' ), [ 'sent', 'failed', 'skipped' ], true ) ? (string) $history_item['status'] : 'sent',
                'message' => trim( (string) ( $history_item['message'] ?? '' ) ),
                'attempts' => max( 0, (int) ( $history_item['attempts'] ?? 0 ) ),
                'context' => is_array( $history_item['context'] ?? null ) ? $history_item['context'] : [],
            ]
        );
    }

    protected function normalizeEntryState( array $entry_state ) : array {
        return(
            [
                'remote_status_id' => trim( (string) ( $entry_state['remote_status_id'] ?? '' ) ),
                'remote_status_url' => trim( (string) ( $entry_state['remote_status_url'] ?? '' ) ),
                'last_delivery_at_utc' => trim( (string) ( $entry_state['last_delivery_at_utc'] ?? '' ) ),
                'last_error' => trim( (string) ( $entry_state['last_error'] ?? '' ) ),
                'scope' => (string) ( $entry_state['scope'] ?? 'root' ) === 'author' ? 'author' : 'root',
                'author_slug' => $this->normalizeUsername( (string) ( $entry_state['author_slug'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        return( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) === 1 ? $username : '' );
    }

    protected function resolveRetryDelaySeconds( int $attempts ) : int {
        $attempts = max( 1, $attempts );
        return( min( 86400, 300 * ( 2 ** max( 0, $attempts - 1 ) ) ) );
    }

    protected function generateQueueItemId() : string {
        return( 'fediverse_queue_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }

    protected function generateHistoryId() : string {
        return( 'fediverse_history_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }
}
