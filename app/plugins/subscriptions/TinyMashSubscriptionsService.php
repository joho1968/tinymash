<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

class TinyMashSubscriptionsService {

    protected const CONFIRMATION_TTL_SECONDS = 86400;
    protected const THROTTLE_WINDOW_SECONDS = 3600;
    protected const MAX_CLIENT_REQUESTS = 10;
    protected const MAX_ADDRESS_REQUESTS = 3;
    protected const MAX_SITE_REQUESTS = 100;
    protected const MAX_QUEUE_ATTEMPTS = 5;
    protected const HISTORY_LIMIT = 500;
    protected const DELIVERY_EVENT_LIMIT = 5000;
    protected const DELIVERY_EVENT_TTL_SECONDS = 34560000;
    protected const UNSUBSCRIBE_TOKEN_LIMIT = 8;

    protected string $storage_directory;
    protected ?TinyMashConfig $config;
    protected ?TinyMashContentRepository $content_repository;
    protected ?TinyMashUserRepository $user_repository;
    protected ?TinyMashTheme $theme;
    protected ?TinyMashMailer $mailer;
    protected ?TinyMashNotificationService $notification_service;
    protected ?TinyMashSecretLinkService $secret_link_service;

    public function __construct(
        string $storage_directory,
        ?TinyMashConfig $config = null,
        ?TinyMashContentRepository $content_repository = null,
        ?TinyMashUserRepository $user_repository = null,
        ?TinyMashTheme $theme = null,
        ?TinyMashMailer $mailer = null,
        ?TinyMashNotificationService $notification_service = null,
        ?TinyMashSecretLinkService $secret_link_service = null
    ) {
        $this->storage_directory = rtrim( $storage_directory, DIRECTORY_SEPARATOR );
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->user_repository = $user_repository;
        $this->theme = $theme;
        $this->mailer = $mailer;
        $this->notification_service = $notification_service;
        $this->secret_link_service = $secret_link_service;
    }

    public function initialize() : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'state' ),
            function( array $state ) : array {
                $state = $this->normalizeState( $state );
                if ( $state['activated_at_utc'] === '' ) {
                    $state['activated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                }
                return( $state );
            },
            $this->getDefaultState()
        );
    }

    public function normalizeSettings( array $settings ) : array {
        $recipient_limit = (int) ( $settings['recipient_limit'] ?? 500 );
        if ( $recipient_limit < 1 || $recipient_limit > 2000 ) {
            $recipient_limit = 500;
        }

        return(
            [
                'delivery_enabled' => ! empty( $settings['delivery_enabled'] ),
                'signup_enabled' => array_key_exists( 'signup_enabled', $settings ) ? ! empty( $settings['signup_enabled'] ) : ! empty( $settings['delivery_enabled'] ),
                'site_scope_enabled' => ! array_key_exists( 'site_scope_enabled', $settings ) || ! empty( $settings['site_scope_enabled'] ),
                'footer_form_enabled' => ! empty( $settings['footer_form_enabled'] ),
                'owner_notifications_enabled' => ! array_key_exists( 'owner_notifications_enabled', $settings ) || ! empty( $settings['owner_notifications_enabled'] ),
                'paused' => ! empty( $settings['paused'] ),
                'recipient_limit' => $recipient_limit,
            ]
        );
    }

    public function normalizeScope( string $scope ) : string {
        $scope = mb_strtolower( mb_trim( $scope ) );
        if ( $scope === 'site' ) {
            return( 'site' );
        }
        if ( preg_match( '/^author:([a-z0-9_]{1,64})$/', $scope ) === 1 ) {
            return( $scope );
        }

        return( '' );
    }

    public function publicScopeIsAvailable( string $scope, array $settings ) : bool {
        $scope = $this->normalizeScope( $scope );
        return( $scope !== '' && $this->scopeAcceptsPublicSignup( $scope, $settings ) );
    }

    public function getScopeLabel( string $scope ) : string {
        $scope = $this->normalizeScope( $scope );
        return( $scope !== '' ? $this->scopeLabel( $scope ) : 'posts' );
    }

    public function signupFormScopeForContext( array $context ) : string {
        $author_slug = $this->normalizeUsername( (string) ( $context['author_slug'] ?? '' ) );
        $entry = is_array( $context['entry'] ?? null ) ? $context['entry'] : [];
        if ( $author_slug === '' && (string) ( $entry['scope'] ?? '' ) === 'author' ) {
            $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        }

        return( $author_slug !== '' ? 'author:' . $author_slug : 'site' );
    }

    public function getAuthorSettings( string $author_slug ) : array {
        $author_slug = $this->normalizeUsername( $author_slug );
        if ( $author_slug === '' || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( [ 'signup_enabled' => false, 'publishing_enabled' => false ] );
        }

        $settings = $this->user_repository->getPluginSettings( $author_slug, 'subscriptions' );
        return(
            [
                'signup_enabled' => array_key_exists( 'signup_enabled', $settings ) ? ! empty( $settings['signup_enabled'] ) : ! empty( $settings['publishing_enabled'] ),
                'publishing_enabled' => ! empty( $settings['publishing_enabled'] ),
            ]
        );
    }

    public function updateAuthorSettings( string $author_slug, array $settings, string $actor_username, bool $actor_is_admin ) : bool {
        $author_slug = $this->normalizeUsername( $author_slug );
        $actor_username = $this->normalizeUsername( $actor_username );
        $scope = $author_slug !== '' ? 'author:' . $author_slug : '';
        if ( $scope === '' || ! $this->actorCanManageScope( $actor_username, $actor_is_admin, $scope ) || ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( false );
        }

        return(
            $this->user_repository->updatePluginSettings(
                $author_slug,
                'subscriptions',
                [
                    'signup_enabled' => ! empty( $settings['signup_enabled'] ),
                    'publishing_enabled' => ! empty( $settings['publishing_enabled'] ),
                ]
            )
        );
    }

    public function renderSignupForm( string $scope, string $action_url, array $settings ) : string {
        $scope = $this->normalizeScope( $scope );
        if ( $scope === '' || ! $this->scopeAcceptsPublicSignup( $scope, $settings ) ) {
            return( '' );
        }

        return(
            '<section class="tm-subscriptions" data-tm-heading-scope="component">'
            . '<p class="tm-subscriptions-summary">Get an e-mail when there is a new post.</p>'
            . '<form class="tm-subscriptions-form" method="post" action="' . $this->escape( $action_url ) . '">'
            . '<div class="tm-subscriptions-fields">'
            . '<input class="form-control form-control-sm" name="email" type="email" autocomplete="email" placeholder="E-mail address" aria-label="E-mail address" required>'
            . '<button class="btn btn-primary btn-sm" type="submit">Subscribe</button>'
            . '</div>'
            . '<input type="hidden" name="scope" value="' . $this->escape( $scope ) . '">'
            . '<div class="tm-subscriptions-trap" aria-hidden="true"><label>Website <input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>'
            . '</form></section>'
        );
    }

    public function requestPublicSignup( string $email, string $scope, string $ip_address, array $settings ) : array {
        $result = [
            'accepted' => true,
            'throttled' => false,
            'message' => 'If the address can be subscribed, a confirmation link will be sent.',
        ];
        $scope = $this->normalizeScope( $scope );
        $email = $this->normalizeEmail( $email );
        $this->rotateClientKeyIfDue();

        $throttle = $this->getThrottleState( $email, $ip_address );
        if ( ! empty( $throttle['blocked'] ) ) {
            $result['throttled'] = true;
            return( $result );
        }
        $this->recordSignupAttempt( $email, $ip_address );
        if ( $scope === '' || $email === '' || ! $this->scopeAcceptsPublicSignup( $scope, $settings ) ) {
            return( $result );
        }

        $subscriber = $this->upsertSubscriberScope(
            $email,
            $scope,
            [
                'status' => 'pending',
                'consent_source' => 'public_signup',
                'consent_recorded_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'consent_recorded_by' => '',
                'confirmed_at_utc' => '',
                'unsubscribed_at_utc' => '',
                'confirmation_token_hash' => '',
                'confirmation_expires_at_utc' => '',
                'unsubscribe_token_hashes' => [],
            ],
            false
        );
        if ( (string) ( $subscriber['scope_status'] ?? '' ) === 'confirmed' ) {
            return( $result );
        }

        $this->enqueueItem(
            [
                'type' => 'confirmation',
                'subscriber_id' => (string) ( $subscriber['id'] ?? '' ),
                'scope' => $scope,
            ],
            'confirmation:' . (string) ( $subscriber['id'] ?? '' ) . ':' . $scope
        );

        return( $result );
    }

    public function addManualSubscriber( string $email, string $scope, string $actor_username, bool $actor_is_admin, array $settings ) : array {
        $scope = $this->normalizeScope( $scope );
        $email = $this->normalizeEmail( $email );
        $actor_username = $this->normalizeUsername( $actor_username );
        if ( $scope === '' ) {
            throw new \InvalidArgumentException( 'scope' );
        }
        if ( $email === '' ) {
            throw new \InvalidArgumentException( 'email' );
        }
        if ( ! $this->actorCanManageScope( $actor_username, $actor_is_admin, $scope ) ) {
            throw new \RuntimeException( 'forbidden_scope' );
        }
        return( $this->upsertSubscriberScope(
            $email,
            $scope,
            [
                'status' => 'confirmed',
                'consent_source' => $actor_is_admin ? 'manual_admin' : 'manual_author',
                'consent_recorded_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'consent_recorded_by' => $actor_username,
                'confirmed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'unsubscribed_at_utc' => '',
                'confirmation_token_hash' => '',
                'confirmation_expires_at_utc' => '',
                'unsubscribe_token_hashes' => [],
            ],
            true,
            $this->normalizeSettings( $settings )['recipient_limit']
        ) );
    }

    public function removeSubscriberScope( string $subscriber_id, string $scope, string $actor_username, bool $actor_is_admin ) : bool {
        $subscriber_id = trim( $subscriber_id );
        $scope = $this->normalizeScope( $scope );
        $actor_username = $this->normalizeUsername( $actor_username );
        if ( $subscriber_id === '' || $scope === '' || ! $this->actorCanManageScope( $actor_username, $actor_is_admin, $scope ) ) {
            return( false );
        }

        return( $this->setScopeUnsubscribed( $subscriber_id, $scope, $actor_username !== '' ? 'manual:' . $actor_username : 'manual' ) );
    }

    public function getSubscribersForScope( string $scope ) : array {
        $scope = $this->normalizeScope( $scope );
        if ( $scope === '' ) {
            return( [] );
        }

        $store = $this->readStore( 'subscribers', $this->getDefaultSubscribersStore() );
        $subscribers = [];
        foreach ( $store['subscribers'] as $subscriber ) {
            if ( ! is_array( $subscriber ) || ! is_array( $subscriber['scopes'][$scope] ?? null ) ) {
                continue;
            }
            $scope_record = $this->normalizeScopeRecord( $subscriber['scopes'][$scope] );
            $subscribers[] = [
                'id' => trim( (string) ( $subscriber['id'] ?? '' ) ),
                'email' => $this->normalizeEmail( (string) ( $subscriber['email'] ?? '' ) ),
                'scope' => $scope,
                'status' => $scope_record['status'],
                'consent_source' => $scope_record['consent_source'],
                'consent_recorded_at_utc' => $scope_record['consent_recorded_at_utc'],
                'consent_recorded_by' => $scope_record['consent_recorded_by'],
                'confirmed_at_utc' => $scope_record['confirmed_at_utc'],
                'unsubscribed_at_utc' => $scope_record['unsubscribed_at_utc'],
            ];
        }
        usort(
            $subscribers,
            static function( array $left, array $right ) : int {
                $status_compare = strcmp( (string) $left['status'], (string) $right['status'] );
                return( $status_compare !== 0 ? $status_compare : strcasecmp( (string) $left['email'], (string) $right['email'] ) );
            }
        );

        return( $subscribers );
    }

    public function getSummary() : array {
        $subscribers = $this->readStore( 'subscribers', $this->getDefaultSubscribersStore() );
        $queue = $this->readStore( 'queue', $this->getDefaultQueueStore() );
        $summary = [ 'confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0, 'queued' => 0, 'retrying' => 0, 'failed' => 0 ];
        foreach ( $subscribers['subscribers'] as $subscriber ) {
            if ( ! is_array( $subscriber ) ) {
                continue;
            }
            foreach ( (array) ( $subscriber['scopes'] ?? [] ) as $scope_record ) {
                $status = (string) ( $this->normalizeScopeRecord( is_array( $scope_record ) ? $scope_record : [] )['status'] ?? '' );
                if ( isset( $summary[$status] ) ) {
                    $summary[$status]++;
                }
            }
        }
        foreach ( $queue['items'] as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $status = (string) ( $item['status'] ?? 'pending' );
            if ( $status === 'retrying' ) {
                $summary['retrying']++;
            } elseif ( $status === 'failed' ) {
                $summary['failed']++;
            } else {
                $summary['queued']++;
            }
        }
        return( $summary );
    }

    public function getConfirmation( string $subscriber_id, string $scope, string $token ) : ?array {
        $scope_record = $this->getTokenScopeRecord( $subscriber_id, $scope );
        if ( ! is_array( $scope_record ) || (string) ( $scope_record['status'] ?? '' ) !== 'pending' ) {
            return( null );
        }
        $token_hash = hash( 'sha256', trim( $token ) );
        $stored_hash = trim( (string) ( $scope_record['confirmation_token_hash'] ?? '' ) );
        $expires_at = strtotime( (string) ( $scope_record['confirmation_expires_at_utc'] ?? '' ) );
        if ( $stored_hash === '' || ! hash_equals( $stored_hash, $token_hash ) || ! is_int( $expires_at ) || $expires_at < time() ) {
            return( null );
        }

        return( [ 'subscriber_id' => trim( $subscriber_id ), 'scope' => $this->normalizeScope( $scope ) ] );
    }

    public function confirmSubscription( string $subscriber_id, string $scope, string $token, array $settings ) : bool {
        if ( ! $this->config instanceof TinyMashConfig || ! $this->config->isSitePublic() ) {
            return( false );
        }
        $subscriber_id = trim( $subscriber_id );
        $scope = $this->normalizeScope( $scope );
        $token_hash = hash( 'sha256', trim( $token ) );
        if ( preg_match( '/^subscriber_[a-f0-9]{32}$/', $subscriber_id ) !== 1 || ! $this->scopeAcceptsPublicSignup( $scope, $settings ) ) {
            return( false );
        }
        $recipient_limit = $this->normalizeSettings( $settings )['recipient_limit'];

        $result = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) use ( $subscriber_id, $scope, $token_hash, $recipient_limit ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                if ( ! is_array( $store['subscribers'][$subscriber_id]['scopes'][$scope] ?? null ) ) {
                    return( [ 'data' => $store, 'result' => [ 'confirmed' => false, 'email' => '' ] ] );
                }
                $record = $this->normalizeScopeRecord( $store['subscribers'][$subscriber_id]['scopes'][$scope] );
                $stored_hash = $record['confirmation_token_hash'];
                $expires_at = strtotime( $record['confirmation_expires_at_utc'] );
                if ( $record['status'] !== 'pending' || $stored_hash === '' || ! hash_equals( $stored_hash, $token_hash ) || ! is_int( $expires_at ) || $expires_at < time() ) {
                    return( [ 'data' => $store, 'result' => [ 'confirmed' => false, 'email' => '' ] ] );
                }
                $email = $this->normalizeEmail( (string) ( $store['subscribers'][$subscriber_id]['email'] ?? '' ) );
                if ( $this->countConfirmedSubscribersInStore( $store ) >= $recipient_limit && ! $this->storeHasConfirmedEmail( $store, $email ) ) {
                    return( [ 'data' => $store, 'result' => [ 'confirmed' => false, 'email' => '' ] ] );
                }
                $record['status'] = 'confirmed';
                $record['consent_source'] = 'public_double_opt_in';
                $record['confirmed_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $record['confirmation_token_hash'] = '';
                $record['confirmation_expires_at_utc'] = '';
                $store['subscribers'][$subscriber_id]['scopes'][$scope] = $record;
                $store['subscribers'][$subscriber_id]['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                return( [ 'data' => $store, 'result' => [ 'confirmed' => true, 'email' => $email ] ] );
            },
            $this->getDefaultSubscribersStore()
        );
        $confirmed = is_array( $result ) && ! empty( $result['confirmed'] );
        $email = is_array( $result ) ? (string) ( $result['email'] ?? '' ) : '';

        if ( $confirmed && $this->notification_service instanceof TinyMashNotificationService && ! empty( $this->normalizeSettings( $settings )['owner_notifications_enabled'] ) ) {
            $this->notification_service->createNotification(
                'subscriptions',
                'Subscription confirmed',
                $email . ' confirmed ' . $this->scopeLabel( $scope ) . '.',
                [ 'severity' => 'info', 'dedupe_key' => 'subscription-confirmed:' . $subscriber_id . ':' . $scope, 'dedupe_window_seconds' => 60 ]
            );
        }

        return( (bool) $confirmed );
    }

    public function getUnsubscribeRequest( string $subscriber_id, string $scope, string $token ) : ?array {
        $scope_record = $this->getTokenScopeRecord( $subscriber_id, $scope );
        if ( ! is_array( $scope_record ) || (string) ( $scope_record['status'] ?? '' ) !== 'confirmed' ) {
            return( null );
        }
        $token_hash = hash( 'sha256', trim( $token ) );
        foreach ( (array) ( $scope_record['unsubscribe_token_hashes'] ?? [] ) as $stored_hash ) {
            if ( is_string( $stored_hash ) && $stored_hash !== '' && hash_equals( $stored_hash, $token_hash ) ) {
                return( [ 'subscriber_id' => trim( $subscriber_id ), 'scope' => $this->normalizeScope( $scope ) ] );
            }
        }

        return( null );
    }

    public function unsubscribe( string $subscriber_id, string $scope, string $token ) : bool {
        $subscriber_id = trim( $subscriber_id );
        $scope = $this->normalizeScope( $scope );
        if ( preg_match( '/^subscriber_[a-f0-9]{32}$/', $subscriber_id ) !== 1 || $scope === '' || trim( $token ) === '' ) {
            return( false );
        }
        return( $this->setScopeUnsubscribed( $subscriber_id, $scope, 'subscriber', hash( 'sha256', trim( $token ) ) ) );
    }

    public function queuePublication( ?array $previous_entry, array $saved_entry, array $settings, array $context = [] ) : array {
        $entry_id = trim( (string) ( $saved_entry['id'] ?? '' ) );
        $status = strtolower( trim( (string) ( $saved_entry['status'] ?? '' ) ) );
        $previous_status = strtolower( trim( (string) ( $previous_entry['status'] ?? '' ) ) );
        $entry_type = strtolower( trim( (string) ( $saved_entry['entry_type'] ?? $saved_entry['type'] ?? 'post' ) ) );
        $trigger = strtolower( trim( (string) ( $context['trigger'] ?? '' ) ) );
        if ( $entry_id === '' || $status !== 'published' || $previous_status === 'published' || $entry_type !== 'post' ) {
            return( [ 'queued' => 0, 'reason' => 'not_first_publication' ] );
        }
        if ( in_array( $trigger, [ 'markdown_sync', 'blogger_import', 'wordpress_import', 'bludit_import', 'restore' ], true ) ) {
            return( [ 'queued' => 0, 'reason' => 'bulk_operation' ] );
        }

        $state = $this->readStore( 'state', $this->getDefaultState() );
        $published_at = trim( (string) ( $saved_entry['published_at_utc'] ?? '' ) );
        if ( $this->timestampBefore( $published_at, (string) ( $state['activated_at_utc'] ?? '' ) ) ) {
            return( [ 'queued' => 0, 'reason' => 'before_activation' ] );
        }
        if ( isset( $state['delivery_events'][$entry_id] ) ) {
            return( [ 'queued' => 0, 'reason' => 'already_recorded' ] );
        }

        $normalized_settings = $this->normalizeSettings( $settings );
        if ( empty( $normalized_settings['delivery_enabled'] ) ) {
            $this->recordDeliveryEvent( $entry_id, $published_at, 0, 'disabled' );
            return( [ 'queued' => 0, 'reason' => 'disabled' ] );
        }

        $content_scope = strtolower( trim( (string) ( $saved_entry['scope'] ?? 'root' ) ) );
        $author_slug = $content_scope === 'author' ? $this->normalizeUsername( (string) ( $saved_entry['author_slug'] ?? '' ) ) : '';
        $author_scope = $author_slug !== '' ? 'author:' . $author_slug : '';
        $author_enabled = $author_scope !== '' && $this->authorDeliveryEnabled( $author_slug );
        if ( $content_scope === 'author' && ! $author_enabled ) {
            $this->recordDeliveryEvent( $entry_id, $published_at, 0, 'author_disabled' );
            return( [ 'queued' => 0, 'reason' => 'author_disabled' ] );
        }
        if ( ! $this->entryHasNotificationAccess( $saved_entry ) ) {
            $this->recordDeliveryEvent( $entry_id, $published_at, 0, 'private_without_secret_link' );
            return( [ 'queued' => 0, 'reason' => 'private_without_secret_link' ] );
        }
        $recipients = $this->getPublicationRecipients( ! empty( $normalized_settings['site_scope_enabled'] ), $author_enabled ? $author_scope : '' );
        $queued = 0;
        foreach ( $recipients as $recipient ) {
            $subscriber_id = (string) ( $recipient['subscriber_id'] ?? '' );
            $scope = (string) ( $recipient['scope'] ?? '' );
            if ( $subscriber_id === '' || $scope === '' ) {
                continue;
            }
            if ( $this->enqueueItem(
                [ 'type' => 'post', 'subscriber_id' => $subscriber_id, 'scope' => $scope, 'entry_id' => $entry_id ],
                'post:' . $entry_id . ':' . $subscriber_id
            ) ) {
                $queued++;
            }
        }
        $this->recordDeliveryEvent( $entry_id, $published_at, $queued, 'queued' );
        return( [ 'queued' => $queued, 'reason' => $queued > 0 ? 'queued' : 'no_recipients' ] );
    }

    public function runQueue( array $plugin_settings, array $context = [] ) : array {
        $settings = $this->normalizeSettings( $plugin_settings );
        if ( ! empty( $settings['paused'] ) ) {
            return( [ 'claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0, 'suppressed' => 0, 'paused' => true, 'message' => 'Subscription delivery is paused.' ] );
        }
        if ( ! $this->mailer instanceof TinyMashMailer || ! $this->config instanceof TinyMashConfig ) {
            throw new \RuntimeException( 'Subscription mail services are unavailable.' );
        }

        $items = $this->claimDueItems( 25 );
        $result = [ 'claimed' => count( $items ), 'delivered' => 0, 'retried' => 0, 'failed' => 0, 'suppressed' => 0, 'paused' => false, 'message' => '' ];
        foreach ( $items as $item ) {
            try {
                $delivery = $this->deliverQueueItem( $item, $settings );
                $status = (string) ( $delivery['status'] ?? 'suppressed' );
                $this->finishQueueItem( $item, $status, (string) ( $delivery['message'] ?? '' ) );
                if ( isset( $result[$status] ) ) {
                    $result[$status]++;
                }
            } catch ( \Throwable $e ) {
                $attempts = (int) ( $item['attempts'] ?? 0 ) + 1;
                $status = $attempts >= self::MAX_QUEUE_ATTEMPTS ? 'failed' : 'retried';
                $this->finishQueueItem( $item, $status, $this->normalizeDeliveryError( $e->getMessage() ) );
                $result[$status]++;
                if ( $status === 'failed' && $this->notification_service instanceof TinyMashNotificationService ) {
                    $this->notification_service->createNotification(
                        'subscriptions-delivery',
                        'Subscription delivery failed',
                        'A queued subscription message reached its retry limit.',
                        [ 'severity' => 'warning', 'dedupe_key' => 'subscriptions-delivery-failed', 'dedupe_window_seconds' => 21600 ]
                    );
                }
            }
        }
        $result['message'] = $result['claimed'] > 0
            ? 'Processed ' . $result['claimed'] . ' subscription queue item(s).'
            : 'No subscription deliveries were due.';
        return( $result );
    }

    public function prune() : array {
        $removed_throttle = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'throttle' ),
            function( array $store ) : array {
                $store = $this->normalizeThrottleStore( $store );
                $before = count( $store['attempts'] );
                $store['attempts'] = $this->pruneAttempts( $store['attempts'] );
                return( [ 'data' => $store, 'result' => $before - count( $store['attempts'] ) ] );
            },
            $this->getDefaultThrottleStore()
        );
        $removed_queue = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) : array {
                $store = $this->normalizeQueueStore( $store );
                $before = count( $store['items'] );
                $cutoff = time() - 86400;
                $store['items'] = array_values(
                    array_filter(
                        $store['items'],
                        static function( mixed $item ) use ( $cutoff ) : bool {
                            if ( ! is_array( $item ) || (string) ( $item['status'] ?? '' ) !== 'failed' ) {
                                return( true );
                            }
                            $updated = strtotime( (string) ( $item['updated_at_utc'] ?? '' ) );
                            return( ! is_int( $updated ) || $updated >= $cutoff );
                        }
                    )
                );
                return( [ 'data' => $store, 'result' => $before - count( $store['items'] ) ] );
            },
            $this->getDefaultQueueStore()
        );
        $removed_pending = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                $removed = 0;
                foreach ( $store['subscribers'] as $subscriber_id => $subscriber ) {
                    if ( ! is_array( $subscriber ) ) {
                        unset( $store['subscribers'][$subscriber_id] );
                        $removed++;
                        continue;
                    }
                    $scopes = is_array( $subscriber['scopes'] ?? null ) ? $subscriber['scopes'] : [];
                    foreach ( $scopes as $scope => $record ) {
                        $record = $this->normalizeScopeRecord( is_array( $record ) ? $record : [] );
                        $expires_at = strtotime( $record['confirmation_expires_at_utc'] );
                        if ( $record['status'] === 'pending' && is_int( $expires_at ) && $expires_at < time() ) {
                            unset( $scopes[$scope] );
                            $removed++;
                        }
                    }
                    if ( empty( $scopes ) ) {
                        unset( $store['subscribers'][$subscriber_id] );
                        continue;
                    }
                    $subscriber['scopes'] = $scopes;
                    $store['subscribers'][$subscriber_id] = $subscriber;
                }
                return( [ 'data' => $store, 'result' => $removed ] );
            },
            $this->getDefaultSubscribersStore()
        );
        $removed_events = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'state' ),
            function( array $state ) : array {
                $state = $this->normalizeState( $state );
                $before = count( $state['delivery_events'] );
                $cutoff = time() - self::DELIVERY_EVENT_TTL_SECONDS;
                $events = array_filter(
                    $state['delivery_events'],
                    static function( mixed $event ) use ( $cutoff ) : bool {
                        if ( ! is_array( $event ) ) {
                            return( false );
                        }
                        $recorded_at = strtotime( (string) ( $event['recorded_at_utc'] ?? '' ) );
                        return( ! is_int( $recorded_at ) || $recorded_at >= $cutoff );
                    }
                );
                uasort(
                    $events,
                    static fn( array $left, array $right ) : int => strcmp( (string) ( $right['recorded_at_utc'] ?? '' ), (string) ( $left['recorded_at_utc'] ?? '' ) )
                );
                $state['delivery_events'] = array_slice( $events, 0, self::DELIVERY_EVENT_LIMIT, true );
                return( [ 'data' => $state, 'result' => $before - count( $state['delivery_events'] ) ] );
            },
            $this->getDefaultState()
        );
        return( [ 'removed_throttle' => (int) $removed_throttle, 'removed_queue' => (int) $removed_queue, 'removed_pending' => (int) $removed_pending, 'removed_events' => (int) $removed_events ] );
    }

    protected function deliverQueueItem( array $item, array $settings ) : array {
        $type = (string) ( $item['type'] ?? '' );
        $subscriber_id = trim( (string) ( $item['subscriber_id'] ?? '' ) );
        $scope = $this->normalizeScope( (string) ( $item['scope'] ?? '' ) );
        $subscriber = $this->getSubscriber( $subscriber_id );
        if ( ! is_array( $subscriber ) || $scope === '' ) {
            return( [ 'status' => 'suppressed', 'message' => 'Subscriber is unavailable.' ] );
        }
        $email = $this->normalizeEmail( (string) ( $subscriber['email'] ?? '' ) );
        $scope_record = $this->normalizeScopeRecord( is_array( $subscriber['scopes'][$scope] ?? null ) ? $subscriber['scopes'][$scope] : [] );
        if ( $type === 'confirmation' ) {
            if ( $scope_record['status'] !== 'pending' || ! $this->scopeAcceptsPublicSignup( $scope, $settings ) ) {
                return( [ 'status' => 'suppressed', 'message' => 'Confirmation is no longer required.' ] );
            }
            $token = $this->setConfirmationToken( $subscriber_id, $scope );
            $url = $this->buildActionUrl( 'confirm', $subscriber_id, $scope, $token );
            $site_name = $this->config->getSiteName();
            $subject = '[' . $site_name . '] Confirm your subscription';
            $message = $this->mailer->buildPublicMessage(
                'You are receiving this e-mail because someone subscribed this address to ' . $this->scopeLabel( $scope ) . ' on ' . $site_name . '.',
                "Confirm your subscription:\n\n" . $url . "\n\nIf you did not request this, you can ignore this message.",
                '<p><a href="' . $this->escape( $url ) . '">Confirm subscription</a></p><p>If you did not request this, you can ignore this message.</p>',
                $site_name,
                (string) $this->config->configGetBaseURL()
            );
            $this->mailer->sendMessage( $this->config->getSystemSettings(), $email, $subject, $message['text'], $message['html'], [], $site_name );
            return( [ 'status' => 'delivered', 'message' => 'Confirmation sent.' ] );
        }
        if ( $type !== 'post' || $scope_record['status'] !== 'confirmed' ) {
            return( [ 'status' => 'suppressed', 'message' => 'Subscription is not active.' ] );
        }
        if ( empty( $settings['delivery_enabled'] ) ) {
            return( [ 'status' => 'suppressed', 'message' => 'New-post notifications are disabled.' ] );
        }
        if ( $scope === 'site' && empty( $settings['site_scope_enabled'] ) ) {
            return( [ 'status' => 'suppressed', 'message' => 'Site-wide delivery is disabled.' ] );
        }

        $entry_id = trim( (string) ( $item['entry_id'] ?? '' ) );
        $entry = $this->content_repository instanceof TinyMashContentRepository ? $this->content_repository->getAccessibleEntryById( $entry_id ) : null;
        if ( ! $this->entryCanBeDelivered( $entry, $scope ) ) {
            return( [ 'status' => 'suppressed', 'message' => 'Post is no longer eligible.' ] );
        }
        $unsubscribe_token = $this->addUnsubscribeToken( $subscriber_id, $scope );
        $unsubscribe_url = $this->buildActionUrl( 'unsubscribe', $subscriber_id, $scope, $unsubscribe_token );
        $entry_url = $this->buildEntryUrl( $entry );
        $site_name = $this->config->getSiteName();
        $title = mb_trim( (string) ( $entry['title'] ?? 'New post' ) );
        $summary = $this->buildEntrySummary( $entry );
        $author = $this->buildAuthorLabel( $entry );
        $date = $this->buildPublishedDate( $entry );
        $text = $title . "\n\n";
        if ( $summary !== '' ) {
            $text .= $summary . "\n\n";
        }
        if ( $author !== '' ) {
            $text .= 'Author: ' . $author . "\n";
        }
        if ( $date !== '' ) {
            $text .= 'Published: ' . $date . "\n";
        }
        $text .= 'Read: ' . $entry_url;
        $html = '<h1>' . $this->escape( $title ) . '</h1>';
        if ( $summary !== '' ) {
            $html .= '<p>' . nl2br( $this->escape( $summary ) ) . '</p>';
        }
        if ( $author !== '' ) {
            $html .= '<p><strong>Author:</strong> ' . $this->escape( $author ) . '</p>';
        }
        if ( $date !== '' ) {
            $html .= '<p><strong>Published:</strong> ' . $this->escape( $date ) . '</p>';
        }
        $html .= '<p><a href="' . $this->escape( $entry_url ) . '">Read the post</a></p>';
        $reason = $scope === 'site'
            ? 'You are receiving this e-mail because you subscribed to new posts from ' . $site_name . '.'
            : 'You are receiving this e-mail because you subscribed to ' . $this->scopeLabel( $scope ) . ' on ' . $site_name . '.';
        $message = $this->mailer->buildPublicMessage(
            $reason,
            $text,
            $html,
            $site_name,
            (string) $this->config->configGetBaseURL(),
            [ [ 'label' => 'Unsubscribe', 'url' => $unsubscribe_url ] ]
        );
        $headers = [ 'List-Unsubscribe' => '<' . $unsubscribe_url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click' ];
        $this->mailer->sendMessage( $this->config->getSystemSettings(), $email, '[' . $site_name . '] ' . $title, $message['text'], $message['html'], $headers, $site_name );
        return( [ 'status' => 'delivered', 'message' => 'Post notification sent.' ] );
    }

    protected function entryCanBeDelivered( mixed $entry, string $scope ) : bool {
        if ( ! is_array( $entry ) || ! $this->config instanceof TinyMashConfig || ! $this->entryHasNotificationAccess( $entry ) ) {
            return( false );
        }
        $status = strtolower( trim( (string) ( $entry['status'] ?? '' ) ) );
        $type = strtolower( trim( (string) ( $entry['entry_type'] ?? $entry['type'] ?? '' ) ) );
        if ( $status !== 'published' || $type !== 'post' ) {
            return( false );
        }
        if ( ! str_starts_with( $scope, 'author:' ) ) {
            $entry_scope = strtolower( trim( (string) ( $entry['scope'] ?? 'root' ) ) );
            if ( $entry_scope !== 'author' ) {
                return( true );
            }
            $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
            return(
                $author_slug !== ''
                && $this->authorDeliveryEnabled( $author_slug )
                && ( ! $this->user_repository instanceof TinyMashUserRepository || $this->user_repository->isAuthorContentPublic( $author_slug ) )
            );
        }
        $author_slug = substr( $scope, 7 );
        return(
            $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) ) === $author_slug
            && $this->authorDeliveryEnabled( $author_slug )
            && ( ! $this->user_repository instanceof TinyMashUserRepository || $this->user_repository->isAuthorContentPublic( $author_slug ) )
        );
    }

    protected function entryHasNotificationAccess( array $entry ) : bool {
        if ( ! $this->config instanceof TinyMashConfig ) {
            return( false );
        }
        if ( $this->config->isSitePublic() ) {
            return( true );
        }
        if ( ! $this->secret_link_service instanceof TinyMashSecretLinkService || ! $this->secret_link_service->isEnabled() ) {
            return( false );
        }

        $content_scope = strtolower( trim( (string) ( $entry['scope'] ?? 'root' ) ) );
        if ( $content_scope !== 'author' ) {
            return( is_array( $this->secret_link_service->getSiteSecretLink() ) );
        }

        $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        return(
            is_array( $this->secret_link_service->getSiteSecretLink() )
            || ( $author_slug !== '' && is_array( $this->secret_link_service->getAuthorSecretLink( $author_slug ) ) )
        );
    }

    protected function getPublicationRecipients( bool $site_enabled, string $author_scope ) : array {
        $store = $this->readStore( 'subscribers', $this->getDefaultSubscribersStore() );
        $recipients = [];
        foreach ( $store['subscribers'] as $subscriber_id => $subscriber ) {
            if ( ! is_array( $subscriber ) ) {
                continue;
            }
            $site_record = $this->normalizeScopeRecord( is_array( $subscriber['scopes']['site'] ?? null ) ? $subscriber['scopes']['site'] : [] );
            if ( $site_enabled && $site_record['status'] === 'confirmed' ) {
                $recipients[] = [ 'subscriber_id' => (string) $subscriber_id, 'scope' => 'site' ];
                continue;
            }
            if ( $author_scope !== '' ) {
                $author_record = $this->normalizeScopeRecord( is_array( $subscriber['scopes'][$author_scope] ?? null ) ? $subscriber['scopes'][$author_scope] : [] );
                if ( $author_record['status'] === 'confirmed' ) {
                    $recipients[] = [ 'subscriber_id' => (string) $subscriber_id, 'scope' => $author_scope ];
                }
            }
        }
        return( $recipients );
    }

    protected function scopeAcceptsPublicSignup( string $scope, array $settings ) : bool {
        $settings = $this->normalizeSettings( $settings );
        if ( empty( $settings['signup_enabled'] ) || ! empty( $settings['paused'] ) || ! $this->config instanceof TinyMashConfig || ! $this->config->isSitePublic() ) {
            return( false );
        }
        if ( $scope === 'site' ) {
            return( ! empty( $settings['site_scope_enabled'] ) );
        }
        $author_slug = substr( $scope, 7 );
        return(
            $this->authorSignupEnabled( $author_slug )
            && ( ! $this->user_repository instanceof TinyMashUserRepository || $this->user_repository->isAuthorContentPublic( $author_slug ) )
        );
    }

    protected function authorSignupEnabled( string $author_slug ) : bool {
        if ( ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( true );
        }
        $settings = $this->user_repository->getPluginSettings( $author_slug, 'subscriptions' );
        return( array_key_exists( 'signup_enabled', $settings ) ? ! empty( $settings['signup_enabled'] ) : ! empty( $settings['publishing_enabled'] ) );
    }

    protected function authorDeliveryEnabled( string $author_slug ) : bool {
        if ( ! $this->user_repository instanceof TinyMashUserRepository ) {
            return( true );
        }
        $settings = $this->user_repository->getPluginSettings( $author_slug, 'subscriptions' );
        return( ! empty( $settings['publishing_enabled'] ) );
    }

    protected function actorCanManageScope( string $actor_username, bool $actor_is_admin, string $scope ) : bool {
        if ( $actor_is_admin ) {
            if ( $scope === 'site' ) {
                return( true );
            }
            $author_slug = substr( $scope, 7 );
            return( ! $this->user_repository instanceof TinyMashUserRepository || is_array( $this->user_repository->getUserByAuthorSlug( $author_slug ) ) );
        }
        return( $actor_username !== '' && $scope === 'author:' . $actor_username );
    }

    protected function upsertSubscriberScope( string $email, string $scope, array $scope_record, bool $replace_confirmed, int $recipient_limit = 0 ) : array {
        $state = $this->readStore( 'state', $this->getDefaultState() );
        $subscriber_id = 'subscriber_' . substr( hash_hmac( 'sha256', $email, (string) $state['address_hmac_key'] ), 0, 32 );
        $result = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) use ( $subscriber_id, $email, $scope, $scope_record, $replace_confirmed, $recipient_limit ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                if ( $recipient_limit > 0 && $this->countConfirmedSubscribersInStore( $store ) >= $recipient_limit && ! $this->storeHasConfirmedEmail( $store, $email ) ) {
                    throw new \RuntimeException( 'recipient_limit' );
                }
                $now = gmdate( 'Y-m-d\TH:i:s\Z' );
                $subscriber = is_array( $store['subscribers'][$subscriber_id] ?? null ) ? $store['subscribers'][$subscriber_id] : [];
                $subscriber['id'] = $subscriber_id;
                $subscriber['email'] = $email;
                $subscriber['created_at_utc'] = trim( (string) ( $subscriber['created_at_utc'] ?? '' ) ) ?: $now;
                $subscriber['updated_at_utc'] = $now;
                $subscriber['scopes'] = is_array( $subscriber['scopes'] ?? null ) ? $subscriber['scopes'] : [];
                $existing = $this->normalizeScopeRecord( is_array( $subscriber['scopes'][$scope] ?? null ) ? $subscriber['scopes'][$scope] : [] );
                if ( $existing['status'] !== 'confirmed' || $replace_confirmed ) {
                    $subscriber['scopes'][$scope] = $this->normalizeScopeRecord( $scope_record );
                }
                $store['subscribers'][$subscriber_id] = $subscriber;
                return( [ 'data' => $store, 'result' => [ 'id' => $subscriber_id, 'scope_status' => (string) $subscriber['scopes'][$scope]['status'] ] ] );
            },
            $this->getDefaultSubscribersStore()
        );
        return( is_array( $result ) ? $result : [] );
    }

    protected function setScopeUnsubscribed( string $subscriber_id, string $scope, string $source, string $required_token_hash = '' ) : bool {
        $result = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) use ( $subscriber_id, $scope, $source, $required_token_hash ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                if ( ! is_array( $store['subscribers'][$subscriber_id]['scopes'][$scope] ?? null ) ) {
                    return( [ 'data' => $store, 'result' => false ] );
                }
                $record = $this->normalizeScopeRecord( $store['subscribers'][$subscriber_id]['scopes'][$scope] );
                if ( $required_token_hash !== '' ) {
                    $token_matches = false;
                    foreach ( $record['unsubscribe_token_hashes'] as $stored_hash ) {
                        if ( hash_equals( $stored_hash, $required_token_hash ) ) {
                            $token_matches = true;
                            break;
                        }
                    }
                    if ( $record['status'] !== 'confirmed' || ! $token_matches ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                }
                $record['status'] = 'unsubscribed';
                $record['unsubscribed_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $record['unsubscribed_by'] = mb_substr( mb_trim( $source ), 0, 96 );
                $record['confirmation_token_hash'] = '';
                $record['confirmation_expires_at_utc'] = '';
                $record['unsubscribe_token_hashes'] = [];
                $store['subscribers'][$subscriber_id]['scopes'][$scope] = $record;
                $store['subscribers'][$subscriber_id]['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                return( [ 'data' => $store, 'result' => true ] );
            },
            $this->getDefaultSubscribersStore()
        );
        return( (bool) $result );
    }

    protected function setConfirmationToken( string $subscriber_id, string $scope ) : string {
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) use ( $subscriber_id, $scope, $token ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                if ( ! is_array( $store['subscribers'][$subscriber_id]['scopes'][$scope] ?? null ) ) {
                    throw new \RuntimeException( 'Subscriber scope is unavailable.' );
                }
                $record = $this->normalizeScopeRecord( $store['subscribers'][$subscriber_id]['scopes'][$scope] );
                $record['confirmation_token_hash'] = hash( 'sha256', $token );
                $record['confirmation_expires_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::CONFIRMATION_TTL_SECONDS );
                $record['confirmation_sent_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $store['subscribers'][$subscriber_id]['scopes'][$scope] = $record;
                return( $store );
            },
            $this->getDefaultSubscribersStore()
        );
        return( $token );
    }

    protected function addUnsubscribeToken( string $subscriber_id, string $scope ) : string {
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'subscribers' ),
            function( array $store ) use ( $subscriber_id, $scope, $token ) : array {
                $store = $this->normalizeSubscribersStore( $store );
                if ( ! is_array( $store['subscribers'][$subscriber_id]['scopes'][$scope] ?? null ) ) {
                    throw new \RuntimeException( 'Subscriber scope is unavailable.' );
                }
                $record = $this->normalizeScopeRecord( $store['subscribers'][$subscriber_id]['scopes'][$scope] );
                array_unshift( $record['unsubscribe_token_hashes'], hash( 'sha256', $token ) );
                $record['unsubscribe_token_hashes'] = array_slice( array_values( array_unique( $record['unsubscribe_token_hashes'] ) ), 0, self::UNSUBSCRIBE_TOKEN_LIMIT );
                $store['subscribers'][$subscriber_id]['scopes'][$scope] = $record;
                return( $store );
            },
            $this->getDefaultSubscribersStore()
        );
        return( $token );
    }

    protected function getTokenScopeRecord( string $subscriber_id, string $scope ) : ?array {
        $subscriber_id = trim( $subscriber_id );
        $scope = $this->normalizeScope( $scope );
        if ( preg_match( '/^subscriber_[a-f0-9]{32}$/', $subscriber_id ) !== 1 || $scope === '' ) {
            return( null );
        }
        $subscriber = $this->getSubscriber( $subscriber_id );
        return( is_array( $subscriber['scopes'][$scope] ?? null ) ? $this->normalizeScopeRecord( $subscriber['scopes'][$scope] ) : null );
    }

    protected function getSubscriber( string $subscriber_id ) : ?array {
        $store = $this->readStore( 'subscribers', $this->getDefaultSubscribersStore() );
        return( is_array( $store['subscribers'][$subscriber_id] ?? null ) ? $store['subscribers'][$subscriber_id] : null );
    }

    protected function enqueueItem( array $item, string $dedupe_key ) : bool {
        $result = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) use ( $item, $dedupe_key ) : array {
                $store = $this->normalizeQueueStore( $store );
                foreach ( $store['items'] as $existing ) {
                    if ( is_array( $existing ) && (string) ( $existing['dedupe_key'] ?? '' ) === $dedupe_key && (string) ( $existing['status'] ?? '' ) !== 'failed' ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                }
                $now = gmdate( 'Y-m-d\TH:i:s\Z' );
                $item['id'] = 'mail_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 5 ) );
                $item['dedupe_key'] = $dedupe_key;
                $item['status'] = 'pending';
                $item['attempts'] = 0;
                $item['next_attempt_utc'] = $now;
                $item['created_at_utc'] = $now;
                $item['updated_at_utc'] = $now;
                $item['last_error'] = '';
                $store['items'][] = $item;
                return( [ 'data' => $store, 'result' => true ] );
            },
            $this->getDefaultQueueStore()
        );
        return( (bool) $result );
    }

    protected function claimDueItems( int $limit ) : array {
        $result = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) use ( $limit ) : array {
                $store = $this->normalizeQueueStore( $store );
                $now = time();
                $claimed = [];
                foreach ( $store['items'] as $index => $item ) {
                    if ( ! is_array( $item ) || count( $claimed ) >= $limit ) {
                        continue;
                    }
                    $status = (string) ( $item['status'] ?? 'pending' );
                    $updated = strtotime( (string) ( $item['updated_at_utc'] ?? '' ) );
                    if ( $status === 'processing' && is_int( $updated ) && $updated < ( $now - 900 ) ) {
                        $status = 'retrying';
                    }
                    $next_attempt = strtotime( (string) ( $item['next_attempt_utc'] ?? '' ) );
                    if ( ! in_array( $status, [ 'pending', 'retrying' ], true ) || ( is_int( $next_attempt ) && $next_attempt > $now ) ) {
                        continue;
                    }
                    $item['status'] = 'processing';
                    $item['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    $store['items'][$index] = $item;
                    $claimed[] = $item;
                }
                return( [ 'data' => $store, 'result' => $claimed ] );
            },
            $this->getDefaultQueueStore()
        );
        return( is_array( $result ) ? $result : [] );
    }

    protected function finishQueueItem( array $claimed_item, string $status, string $message ) : void {
        $item_id = (string) ( $claimed_item['id'] ?? '' );
        $final_item = null;
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) use ( $item_id, $status, $message, &$final_item ) : array {
                $store = $this->normalizeQueueStore( $store );
                foreach ( $store['items'] as $index => $item ) {
                    if ( ! is_array( $item ) || (string) ( $item['id'] ?? '' ) !== $item_id ) {
                        continue;
                    }
                    $item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
                    $item['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    $item['last_error'] = $status === 'retried' || $status === 'failed' ? $message : '';
                    if ( $status === 'retried' ) {
                        $item['status'] = 'retrying';
                        $item['next_attempt_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + $this->retryDelaySeconds( $item['attempts'] ) );
                        $store['items'][$index] = $item;
                        $final_item = $item;
                        return( $store );
                    }
                    if ( $status === 'failed' ) {
                        $item['status'] = 'failed';
                        $store['items'][$index] = $item;
                    } else {
                        unset( $store['items'][$index] );
                        $store['items'] = array_values( $store['items'] );
                    }
                    $final_item = $item;
                    return( $store );
                }
                return( $store );
            },
            $this->getDefaultQueueStore()
        );
        if ( is_array( $final_item ) ) {
            $this->appendHistory( $final_item, $status, $message );
        }
    }

    protected function appendHistory( array $item, string $status, string $message ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'history' ),
            function( array $store ) use ( $item, $status, $message ) : array {
                $store = $this->normalizeHistoryStore( $store );
                array_unshift(
                    $store['items'],
                    [
                        'id' => (string) ( $item['id'] ?? '' ),
                        'type' => (string) ( $item['type'] ?? '' ),
                        'entry_id' => (string) ( $item['entry_id'] ?? '' ),
                        'subscriber_id' => (string) ( $item['subscriber_id'] ?? '' ),
                        'scope' => (string) ( $item['scope'] ?? '' ),
                        'status' => $status,
                        'message' => mb_substr( mb_trim( $message ), 0, 240 ),
                        'attempts' => (int) ( $item['attempts'] ?? 0 ),
                        'completed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                    ]
                );
                $store['items'] = array_slice( $store['items'], 0, self::HISTORY_LIMIT );
                return( $store );
            },
            $this->getDefaultHistoryStore()
        );
    }

    protected function recordDeliveryEvent( string $entry_id, string $published_at, int $recipient_count, string $status ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'state' ),
            function( array $state ) use ( $entry_id, $published_at, $recipient_count, $status ) : array {
                $state = $this->normalizeState( $state );
                $state['delivery_events'][$entry_id] = [
                    'published_at_utc' => $published_at,
                    'recorded_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                    'recipient_count' => max( 0, $recipient_count ),
                    'status' => $status,
                ];
                return( $state );
            },
            $this->getDefaultState()
        );
    }

    protected function getThrottleState( string $email, string $ip_address ) : array {
        $store = $this->readStore( 'throttle', $this->getDefaultThrottleStore() );
        $state = $this->readStore( 'state', $this->getDefaultState() );
        $address_key = $email !== '' ? hash_hmac( 'sha256', $email, (string) $state['address_hmac_key'] ) : '';
        $client_key = trim( $ip_address ) !== '' ? hash_hmac( 'sha256', trim( $ip_address ), (string) $state['client_hmac_key'] ) : '';
        $address_count = 0;
        $client_count = 0;
        $attempts = $this->pruneAttempts( $store['attempts'] );
        foreach ( $attempts as $attempt ) {
            if ( ! is_array( $attempt ) ) {
                continue;
            }
            if ( $address_key !== '' && hash_equals( $address_key, (string) ( $attempt['address_key'] ?? '' ) ) ) {
                $address_count++;
            }
            if ( $client_key !== '' && hash_equals( $client_key, (string) ( $attempt['client_key'] ?? '' ) ) ) {
                $client_count++;
            }
        }
        return( [ 'blocked' => count( $attempts ) >= self::MAX_SITE_REQUESTS || $address_count >= self::MAX_ADDRESS_REQUESTS || $client_count >= self::MAX_CLIENT_REQUESTS ] );
    }

    protected function recordSignupAttempt( string $email, string $ip_address ) : void {
        $state = $this->readStore( 'state', $this->getDefaultState() );
        $address_key = $email !== '' ? hash_hmac( 'sha256', $email, (string) $state['address_hmac_key'] ) : '';
        $client_key = trim( $ip_address ) !== '' ? hash_hmac( 'sha256', trim( $ip_address ), (string) $state['client_hmac_key'] ) : '';
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'throttle' ),
            function( array $store ) use ( $address_key, $client_key ) : array {
                $store = $this->normalizeThrottleStore( $store );
                $store['attempts'] = $this->pruneAttempts( $store['attempts'] );
                $store['attempts'][] = [ 'address_key' => $address_key, 'client_key' => $client_key, 'requested_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ) ];
                return( $store );
            },
            $this->getDefaultThrottleStore()
        );
    }

    protected function rotateClientKeyIfDue() : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'state' ),
            function( array $state ) : array {
                $state = $this->normalizeState( $state );
                $rotated_at = strtotime( (string) ( $state['client_key_rotated_at_utc'] ?? '' ) );
                if ( ! is_int( $rotated_at ) || $rotated_at < ( time() - 2592000 ) ) {
                    $state['client_hmac_key'] = bin2hex( random_bytes( 32 ) );
                    $state['client_key_rotated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                }
                return( $state );
            },
            $this->getDefaultState()
        );
    }

    protected function pruneAttempts( array $attempts ) : array {
        $cutoff = time() - self::THROTTLE_WINDOW_SECONDS;
        return(
            array_values(
                array_filter(
                    $attempts,
                    static function( mixed $attempt ) use ( $cutoff ) : bool {
                        if ( ! is_array( $attempt ) ) {
                            return( false );
                        }
                        $timestamp = strtotime( (string) ( $attempt['requested_at_utc'] ?? '' ) );
                        return( is_int( $timestamp ) && $timestamp >= $cutoff );
                    }
                )
            )
        );
    }

    protected function countConfirmedSubscribersInStore( array $store ) : int {
        $count = 0;
        foreach ( $store['subscribers'] as $subscriber ) {
            if ( ! is_array( $subscriber ) ) {
                continue;
            }
            foreach ( (array) ( $subscriber['scopes'] ?? [] ) as $record ) {
                if ( is_array( $record ) && (string) ( $record['status'] ?? '' ) === 'confirmed' ) {
                    $count++;
                    break;
                }
            }
        }
        return( $count );
    }

    protected function storeHasConfirmedEmail( array $store, string $email ) : bool {
        foreach ( $store['subscribers'] as $subscriber ) {
            if ( ! is_array( $subscriber ) || $this->normalizeEmail( (string) ( $subscriber['email'] ?? '' ) ) !== $email ) {
                continue;
            }
            foreach ( (array) ( $subscriber['scopes'] ?? [] ) as $record ) {
                if ( is_array( $record ) && (string) ( $record['status'] ?? '' ) === 'confirmed' ) {
                    return( true );
                }
            }
        }
        return( false );
    }

    protected function buildActionUrl( string $action, string $subscriber_id, string $scope, string $token ) : string {
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        return( $base_url . '/subscriptions/' . $action . '?subscriber=' . rawurlencode( $subscriber_id ) . '&scope=' . rawurlencode( $scope ) . '&token=' . rawurlencode( $token ) );
    }

    protected function buildEntryUrl( array $entry ) : string {
        if ( $this->theme instanceof TinyMashTheme ) {
            $path = $this->theme->getEntryURL( $entry );
        } elseif ( $this->content_repository instanceof TinyMashContentRepository ) {
            $entry_path = $this->content_repository->getEntryPath( $entry );
            $path = '/' . str_replace( '%2F', '/', rawurlencode( $entry_path ) );
            if ( (string) ( $entry['scope'] ?? '' ) === 'author' && $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) ) !== '' ) {
                $path = '/' . rawurlencode( $this->normalizeUsername( (string) $entry['author_slug'] ) ) . $path;
            }
        } else {
            $path = '/';
        }
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        return( $base_url . ( str_starts_with( $path, '/' ) ? $path : '/' . $path ) );
    }

    protected function buildEntrySummary( array $entry ) : string {
        $summary = mb_trim( (string) ( $entry['summary'] ?? '' ) );
        if ( $summary === '' ) {
            $summary = mb_trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $entry['content'] ?? '' ) ) ) ?? '' );
        }
        if ( mb_strlen( $summary ) > 400 ) {
            $summary = rtrim( mb_substr( $summary, 0, 397 ) ) . '...';
        }
        return( $summary );
    }

    protected function buildAuthorLabel( array $entry ) : string {
        $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        if ( $author_slug === '' ) {
            return( '' );
        }
        return( $this->user_repository instanceof TinyMashUserRepository ? $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug ) : $author_slug );
    }

    protected function buildPublishedDate( array $entry ) : string {
        $timestamp = strtotime( (string) ( $entry['published_at_utc'] ?? '' ) );
        return( is_int( $timestamp ) ? gmdate( 'Y-m-d', $timestamp ) : '' );
    }

    protected function scopeLabel( string $scope ) : string {
        if ( $scope === 'site' ) {
            return( 'all site posts' );
        }
        $author_slug = substr( $scope, 7 );
        $author_label = $this->user_repository instanceof TinyMashUserRepository ? $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug ) : $author_slug;
        return( 'posts by ' . $author_label );
    }

    protected function retryDelaySeconds( int $attempts ) : int {
        $delays = [ 300, 1800, 7200, 43200 ];
        return( $delays[min( max( 0, $attempts - 1 ), count( $delays ) - 1 )] );
    }

    protected function normalizeDeliveryError( string $message ) : string {
        $message = strtolower( trim( $message ) );
        if ( in_array( $message, [ 'smtp_disabled', 'smtp_host', 'smtp_from_email', 'smtp_send_failed' ], true ) ) {
            return( $message );
        }
        return( 'delivery_failed' );
    }

    protected function timestampBefore( string $candidate, string $boundary ) : bool {
        $candidate_time = strtotime( $candidate );
        $boundary_time = strtotime( $boundary );
        return( is_int( $candidate_time ) && is_int( $boundary_time ) && $candidate_time < $boundary_time );
    }

    protected function normalizeEmail( string $email ) : string {
        $email = mb_strtolower( mb_trim( $email ) );
        return( filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '' );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        return( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) === 1 ? $username : '' );
    }

    protected function normalizeScopeRecord( array $record ) : array {
        $status = strtolower( trim( (string) ( $record['status'] ?? 'pending' ) ) );
        if ( ! in_array( $status, [ 'pending', 'confirmed', 'unsubscribed', 'suppressed' ], true ) ) {
            $status = 'pending';
        }
        return(
            [
                'status' => $status,
                'consent_source' => mb_substr( mb_trim( (string) ( $record['consent_source'] ?? '' ) ), 0, 64 ),
                'consent_recorded_at_utc' => trim( (string) ( $record['consent_recorded_at_utc'] ?? '' ) ),
                'consent_recorded_by' => $this->normalizeUsername( (string) ( $record['consent_recorded_by'] ?? '' ) ),
                'confirmed_at_utc' => trim( (string) ( $record['confirmed_at_utc'] ?? '' ) ),
                'unsubscribed_at_utc' => trim( (string) ( $record['unsubscribed_at_utc'] ?? '' ) ),
                'unsubscribed_by' => mb_substr( mb_trim( (string) ( $record['unsubscribed_by'] ?? '' ) ), 0, 96 ),
                'confirmation_token_hash' => trim( (string) ( $record['confirmation_token_hash'] ?? '' ) ),
                'confirmation_expires_at_utc' => trim( (string) ( $record['confirmation_expires_at_utc'] ?? '' ) ),
                'confirmation_sent_at_utc' => trim( (string) ( $record['confirmation_sent_at_utc'] ?? '' ) ),
                'unsubscribe_token_hashes' => array_values( array_filter( (array) ( $record['unsubscribe_token_hashes'] ?? [] ), static fn( mixed $hash ) : bool => is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) === 1 ) ),
            ]
        );
    }

    protected function getFilename( string $store ) : string {
        return( $this->storage_directory . DIRECTORY_SEPARATOR . $store . '.json' );
    }

    protected function readStore( string $store, array $default ) : array {
        return( TinyMashLockedJsonFile::readStrict( $this->getFilename( $store ), $default ) );
    }

    protected function getDefaultState() : array {
        $now = gmdate( 'Y-m-d\TH:i:s\Z' );
        return( [ 'format' => 'tinymash-subscriptions-state', 'format_version' => 1, 'activated_at_utc' => '', 'address_hmac_key' => bin2hex( random_bytes( 32 ) ), 'client_hmac_key' => bin2hex( random_bytes( 32 ) ), 'client_key_rotated_at_utc' => $now, 'delivery_events' => [] ] );
    }

    protected function normalizeState( array $state ) : array {
        $default = $this->getDefaultState();
        $state = array_merge( $default, $state );
        $state['format'] = $default['format'];
        $state['format_version'] = 1;
        $state['delivery_events'] = is_array( $state['delivery_events'] ?? null ) ? $state['delivery_events'] : [];
        if ( preg_match( '/^[a-f0-9]{64}$/', (string) $state['address_hmac_key'] ) !== 1 ) {
            $state['address_hmac_key'] = $default['address_hmac_key'];
        }
        if ( preg_match( '/^[a-f0-9]{64}$/', (string) $state['client_hmac_key'] ) !== 1 ) {
            $state['client_hmac_key'] = $default['client_hmac_key'];
        }
        unset( $state['site_was_public'], $state['visibility_hold'], $state['visibility_acknowledged_at_utc'] );
        return( $state );
    }

    protected function getDefaultSubscribersStore() : array {
        return( [ 'format' => 'tinymash-subscriptions-subscribers', 'format_version' => 1, 'subscribers' => [] ] );
    }

    protected function normalizeSubscribersStore( array $store ) : array {
        $store['format'] = 'tinymash-subscriptions-subscribers';
        $store['format_version'] = 1;
        $store['subscribers'] = is_array( $store['subscribers'] ?? null ) ? $store['subscribers'] : [];
        return( $store );
    }

    protected function getDefaultQueueStore() : array {
        return( [ 'format' => 'tinymash-subscriptions-queue', 'format_version' => 1, 'items' => [] ] );
    }

    protected function normalizeQueueStore( array $store ) : array {
        $store['format'] = 'tinymash-subscriptions-queue';
        $store['format_version'] = 1;
        $store['items'] = is_array( $store['items'] ?? null ) ? array_values( $store['items'] ) : [];
        return( $store );
    }

    protected function getDefaultHistoryStore() : array {
        return( [ 'format' => 'tinymash-subscriptions-history', 'format_version' => 1, 'items' => [] ] );
    }

    protected function normalizeHistoryStore( array $store ) : array {
        $store['format'] = 'tinymash-subscriptions-history';
        $store['format_version'] = 1;
        $store['items'] = is_array( $store['items'] ?? null ) ? array_values( $store['items'] ) : [];
        return( $store );
    }

    protected function getDefaultThrottleStore() : array {
        return( [ 'format' => 'tinymash-subscriptions-throttle', 'format_version' => 1, 'attempts' => [] ] );
    }

    protected function normalizeThrottleStore( array $store ) : array {
        $store['format'] = 'tinymash-subscriptions-throttle';
        $store['format_version'] = 1;
        $store['attempts'] = is_array( $store['attempts'] ?? null ) ? array_values( $store['attempts'] ) : [];
        return( $store );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
