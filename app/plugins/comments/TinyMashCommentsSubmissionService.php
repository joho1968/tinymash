<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashSecretLinkService;
use app\classes\TinyMashTheme;
use app\classes\TinyMashUserRepository;

class TinyMashCommentsSubmissionService {

    protected const SCHEMA_VERSION = 1;
    protected const FORM_MINIMUM_SECONDS = 3;
    protected const FORM_MAXIMUM_SECONDS = 7200;
    protected const QUEUE_LIMIT = 2000;
    protected const QUEUE_BATCH_LIMIT = 20;
    protected const QUEUE_MAX_ATTEMPTS = 5;
    protected const THROTTLE_HISTORY_LIMIT = 5000;

    protected string $storage_directory;
    protected TinyMashCommentsService $comments;
    protected ?TinyMashConfig $config;
    protected ?TinyMashContentRepository $content_repository;
    protected ?TinyMashUserRepository $user_repository;
    protected ?TinyMashTheme $theme;
    protected ?TinyMashMailer $mailer;
    protected ?TinyMashNotificationService $notification_service;
    protected ?TinyMashSecretLinkService $secret_link_service;
    protected ?\Closure $clock;

    public function __construct(
        string $storage_directory,
        TinyMashCommentsService $comments,
        ?TinyMashConfig $config = null,
        ?TinyMashContentRepository $content_repository = null,
        ?TinyMashUserRepository $user_repository = null,
        ?TinyMashTheme $theme = null,
        ?TinyMashMailer $mailer = null,
        ?TinyMashNotificationService $notification_service = null,
        ?TinyMashSecretLinkService $secret_link_service = null,
        ?\Closure $clock = null
    ) {
        $this->storage_directory = rtrim( $storage_directory, DIRECTORY_SEPARATOR );
        if ( $this->storage_directory === '' ) {
            throw new \InvalidArgumentException( 'storage_directory' );
        }
        $this->comments = $comments;
        $this->config = $config;
        $this->content_repository = $content_repository;
        $this->user_repository = $user_repository;
        $this->theme = $theme;
        $this->mailer = $mailer;
        $this->notification_service = $notification_service;
        $this->secret_link_service = $secret_link_service;
        $this->clock = $clock;
    }

    public function initialize() : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'submission-state' ),
            function( array $state ) : array {
                return( $state === [] ? $this->defaultState() : $this->requireState( $state ) );
            },
            []
        );
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) : array {
                return( $store === [] ? $this->defaultQueueStore() : $this->requireQueueStore( $store ) );
            },
            []
        );
        TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'throttle' ),
            function( array $store ) : array {
                return( $store === [] ? $this->defaultThrottleStore() : $this->requireThrottleStore( $store ) );
            },
            []
        );
    }

    public function getThrottleLimits() : array {
        return(
            [
                'address_hour' => 3,
                'address_day' => 6,
                'client_fifteen_minutes' => 5,
                'client_day' => 20,
                'entry_hour' => 20,
                'global_hour' => 100,
            ]
        );
    }

    public function renderForm(
        array $entry,
        string $visitor_type,
        string $csrf_token,
        array $site_settings,
        array $author_settings = [],
        string $action_url = '/comments/submit',
        string $parent_id = '',
        string $entry_url = '',
        string $privacy_url = ''
    ) : string {
        $policy = $this->comments->resolveEntryPolicy( $entry, $site_settings, $author_settings );
        if (
            (string) ( $entry['status'] ?? '' ) !== 'published'
            || empty( $policy['open'] )
            || ! $this->comments->visitorCanComment( (string) $policy['access_policy'], $visitor_type )
            || trim( $csrf_token ) === ''
        ) {
            return( '' );
        }

        $entry_id = (string) ( $entry['id'] ?? '' );
        $parent_id = trim( $parent_id );
        $parent = null;
        if ( $parent_id !== '' ) {
            try {
                $candidate = $this->comments->getComment( $entry_id, $parent_id );
                if ( is_array( $candidate ) && (string) ( $candidate['status'] ?? '' ) === 'approved' && (string) ( $candidate['scope'] ?? '' ) === (string) ( $policy['scope'] ?? '' ) ) {
                    $parent = $candidate;
                }
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                $parent = null;
            }
        }
        if ( ! is_array( $parent ) ) {
            $parent_id = '';
        }
        $form_token = $this->issueFormToken( $entry_id );
        if ( $form_token === '' ) {
            return( '' );
        }

        $escape = static fn( string $value ) : string => htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $html = '<section class="tm-comments tm-comments-compose" id="comment-form" data-tm-heading-scope="component">';
        $heading = is_array( $parent ) ? 'Reply to ' . (string) ( $parent['author_name'] ?? '' ) : 'Leave a comment';
        $html .= '<div class="tm-comments-compose-header"><h2 class="tm-comments-title">' . $escape( $heading ) . '</h2>';
        if ( is_array( $parent ) && trim( $entry_url ) !== '' ) {
            $html .= '<a class="tm-comments-cancel-reply" href="' . $escape( $entry_url ) . '#comment-form">Cancel reply</a>';
        }
        $html .= '</div>';
        $html .= '<form class="tm-comments-form" method="post" action="' . $escape( $action_url ) . '">';
        $html .= '<input type="hidden" name="tinymash_csrf" value="' . $escape( $csrf_token ) . '">';
        $html .= '<input type="hidden" name="entry_id" value="' . $escape( $entry_id ) . '">';
        $html .= '<input type="hidden" name="form_token" value="' . $escape( $form_token ) . '">';
        $html .= '<input type="hidden" name="parent_id" value="' . $escape( $parent_id ) . '">';
        if ( $visitor_type !== 'signed_in' ) {
            $html .= '<div class="tm-comments-identity">';
            $html .= '<label><span>Name</span><input class="form-control form-control-sm" name="author_name" type="text" autocomplete="name" maxlength="80" aria-describedby="tm-comments-name-help" required><span class="form-text" id="tm-comments-name-help">Emoji and smileys are not accepted in names.</span></label>';
            $html .= '<label><span>E-mail address</span><input class="form-control form-control-sm" name="email" type="email" autocomplete="email" maxlength="254" required></label>';
            $html .= '</div>';
        }
        $html .= '<label><span>Comment</span><textarea class="form-control form-control-sm" name="body" rows="5" maxlength="4000" required></textarea></label>';
        $html .= '<label class="tm-comments-notify"><input name="reply_notifications_enabled" type="checkbox" value="1"> <span>Notify me of replies by e-mail</span></label>';
        $html .= '<div class="tm-comments-form-actions"><button class="btn btn-primary btn-sm" type="submit">Send comment</button>';
        if ( trim( $privacy_url ) !== '' ) {
            $html .= '<a class="tm-comments-privacy" href="' . $escape( $privacy_url ) . '">Comment privacy</a>';
        }
        $html .= '</div>';
        $html .= '<div class="tm-comments-trap" aria-hidden="true"><label>Website <input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>';
        $html .= '</form></section>';
        return( $html );
    }

    public function requestSubmission(
        array $input,
        array $entry,
        string $visitor_type,
        string $ip_address,
        string $actor_username,
        string $actor_name,
        bool $actor_is_admin,
        array $site_settings,
        array $author_settings = []
    ) : array {
        $result = [ 'accepted' => true, 'created' => false, 'published' => false, 'throttled' => false, 'validation_error' => '' ];
        $entry_id = trim( (string) ( $entry['id'] ?? '' ) );
        if (
            mb_trim( (string) ( $input['website'] ?? '' ) ) !== ''
            || ! $this->formTokenIsValid( $entry_id, (string) ( $input['form_token'] ?? '' ) )
        ) {
            return( $result );
        }

        $email = mb_strtolower( mb_trim( (string) ( $input['email'] ?? '' ) ) );
        $reply_notifications_enabled = ! empty( $input['reply_notifications_enabled'] );
        $address_identity = $visitor_type === 'signed_in' ? 'account:' . mb_strtolower( mb_trim( $actor_username ) ) : $email;
        $throttled = $this->recordAttempt( $address_identity, $ip_address, $entry_id );
        if ( $throttled ) {
            $result['throttled'] = true;
            return( $result );
        }

        $policy = $this->comments->resolveEntryPolicy( $entry, $site_settings, $author_settings );
        if (
            (string) ( $entry['status'] ?? '' ) !== 'published'
            || empty( $policy['open'] )
            || ! $this->comments->visitorCanComment( (string) $policy['access_policy'], $visitor_type )
        ) {
            return( $result );
        }

        $comment = [];
        $queue_type = '';
        try {
            if ( $visitor_type === 'signed_in' ) {
                $comment = $this->comments->createAccountComment(
                    $entry_id,
                    (string) $policy['scope'],
                    (string) ( $input['parent_id'] ?? '' ),
                    $actor_username,
                    $actor_name,
                    (string) ( $input['body'] ?? '' ),
                    $actor_is_admin,
                    $reply_notifications_enabled
                );
                if ( (string) ( $comment['status'] ?? '' ) === 'pending' ) {
                    $queue_type = 'moderation';
                }
            } else {
                $created = $this->comments->createGuestComment(
                    $entry_id,
                    (string) $policy['scope'],
                    (string) ( $input['parent_id'] ?? '' ),
                    (string) ( $input['author_name'] ?? '' ),
                    $email,
                    (string) ( $input['body'] ?? '' ),
                    $visitor_type,
                    false,
                    $reply_notifications_enabled
                );
                $comment = is_array( $created['comment'] ?? null ) ? $created['comment'] : [];
                $queue_type = 'verification';
            }
        } catch ( \InvalidArgumentException $e ) {
            $result['accepted'] = false;
            $result['validation_error'] = $this->submissionValidationMessage( $e->getMessage() );
            return( $result );
        } catch ( \RuntimeException ) {
            return( $result );
        }

        $comment_id = (string) ( $comment['id'] ?? '' );
        if ( $queue_type !== '' ) {
            try {
                if ( ! $this->enqueue( $queue_type, $entry_id, $comment_id ) ) {
                    throw new \RuntimeException( 'comment_queue' );
                }
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                try {
                    $this->comments->discardUnnotifiedComment( $entry_id, $comment_id );
                } catch ( \InvalidArgumentException | \RuntimeException ) {
                    return( $result );
                }
                return( $result );
            }
        }

        $result['created'] = true;
        $result['published'] = (string) ( $comment['status'] ?? '' ) === 'approved';
        if ( $result['published'] && (string) ( $comment['parent_id'] ?? '' ) !== '' ) {
            try {
                $this->queueApprovedReply( $entry_id, $comment_id );
            } catch ( \InvalidArgumentException | \RuntimeException ) {
                $this->notifyQueueFailure( $comment_id );
            }
        }
        return( $result );
    }

    public function queueApprovedReply( string $entry_id, string $comment_id ) : bool {
        $comment = $this->comments->getComment( $entry_id, $comment_id );
        if (
            ! is_array( $comment )
            || (string) ( $comment['status'] ?? '' ) !== 'approved'
            || (string) ( $comment['parent_id'] ?? '' ) === ''
        ) {
            return( false );
        }
        $parent = $this->comments->getComment( $entry_id, (string) $comment['parent_id'] );
        if (
            ! is_array( $parent )
            || (string) ( $parent['status'] ?? '' ) !== 'approved'
            || empty( $parent['reply_notifications_enabled'] )
        ) {
            return( false );
        }
        return( $this->enqueue( 'reply_notification', $entry_id, $comment_id ) );
    }

    public function getVerification( string $entry_id, string $comment_id, string $token ) : ?array {
        try {
            return( $this->comments->getGuestVerification( $entry_id, $comment_id, $token ) );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            return( null );
        }
    }

    public function getVerificationForReissue( string $entry_id, string $comment_id, string $token ) : ?array {
        try {
            return( $this->comments->getGuestVerificationForReissue( $entry_id, $comment_id, $token ) );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            return( null );
        }
    }

    public function requestVerificationReissue( string $entry_id, string $comment_id, string $token, string $ip_address ) : array {
        $result = [ 'accepted' => true, 'queued' => false, 'throttled' => false ];
        try {
            $comment = $this->comments->getGuestVerificationForReissue( $entry_id, $comment_id, $token );
            if ( ! is_array( $comment ) ) {
                return( $result );
            }
            if ( $this->recordAttempt( 'verification:' . (string) $comment['email'], $ip_address, $entry_id ) ) {
                $result['throttled'] = true;
                return( $result );
            }
            $result['queued'] = $this->enqueue( 'verification', $entry_id, $comment_id );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            return( $result );
        }
        return( $result );
    }

    public function confirmVerification( string $entry_id, string $comment_id, string $token ) : bool {
        try {
            if ( ! is_array( $this->comments->getGuestVerification( $entry_id, $comment_id, $token ) ) ) {
                return( false );
            }
            if ( ! $this->enqueue( 'moderation', $entry_id, $comment_id ) ) {
                return( false );
            }
            return( $this->comments->verifyGuestComment( $entry_id, $comment_id, $token ) );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            return( false );
        }
    }

    public function runQueue( array $context = [] ) : array {
        $limit = min( self::QUEUE_BATCH_LIMIT, max( 1, (int) ( $context['limit'] ?? self::QUEUE_BATCH_LIMIT ) ) );
        $claimed = $this->claimDueItems( $limit );
        $result = [ 'claimed' => count( $claimed ), 'delivered' => 0, 'retried' => 0, 'failed' => 0, 'suppressed' => 0 ];
        foreach ( $claimed as $item ) {
            try {
                $status = $this->deliverQueueItem( $item );
            } catch ( \Throwable ) {
                $status = 'retry';
            }
            $final_status = $this->finishQueueItem( (string) ( $item['id'] ?? '' ), $status );
            if ( isset( $result[$final_status] ) ) {
                $result[$final_status]++;
            }
        }
        return( $result );
    }

    public function getQueueItems() : array {
        return( $this->readQueue()['items'] );
    }

    public function prune( array $settings = [] ) : array {
        $comment_result = $this->comments->prune( $settings );
        $now = $this->nowTimestamp();
        $removed_attempts = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'throttle' ),
            function( array $store ) use ( $now ) : array {
                $store = $this->requireThrottleStore( $store );
                $before = count( $store['attempts'] );
                $store['attempts'] = array_values(
                    array_filter(
                        $store['attempts'],
                        static fn( array $attempt ) : bool => (int) $attempt['timestamp'] >= $now - 86400
                    )
                );
                return( [ 'data' => $store, 'result' => $before - count( $store['attempts'] ) ] );
            },
            $this->defaultThrottleStore()
        );
        $removed_queue = TinyMashLockedJsonFile::mutate(
            $this->getFilename( 'queue' ),
            function( array $store ) use ( $now ) : array {
                $store = $this->requireQueueStore( $store );
                $before = count( $store['items'] );
                $store['items'] = array_values(
                    array_filter(
                        $store['items'],
                        static function( array $item ) use ( $now ) : bool {
                            $updated = strtotime( (string) $item['updated_at_utc'] );
                            if ( ! is_int( $updated ) ) {
                                return( true );
                            }
                            if ( (string) $item['status'] === 'delivered' ) {
                                return( $updated >= $now - 604800 );
                            }
                            if ( (string) $item['status'] === 'failed' ) {
                                return( $updated >= $now - 2592000 );
                            }
                            return( true );
                        }
                    )
                );
                return( [ 'data' => $store, 'result' => $before - count( $store['items'] ) ] );
            },
            $this->defaultQueueStore()
        );
        return( [ 'comments' => $comment_result, 'attempts' => (int) $removed_attempts, 'queue' => (int) $removed_queue ] );
    }

    protected function issueFormToken( string $entry_id ) : string {
        if ( preg_match( '/^entry_[a-z0-9_]{1,96}$/', $entry_id ) !== 1 ) {
            return( '' );
        }
        $state = $this->readState();
        $timestamp = $this->nowTimestamp();
        $nonce = bin2hex( random_bytes( 8 ) );
        $payload = $entry_id . '|' . $timestamp . '|' . $nonce;
        return( $timestamp . '.' . $nonce . '.' . hash_hmac( 'sha256', $payload, (string) $state['form_hmac_key'] ) );
    }

    protected function formTokenIsValid( string $entry_id, string $token ) : bool {
        if ( preg_match( '/^([0-9]{10})\.([a-f0-9]{16})\.([a-f0-9]{64})$/', trim( $token ), $matches ) !== 1 ) {
            return( false );
        }
        $timestamp = (int) $matches[1];
        $age = $this->nowTimestamp() - $timestamp;
        if ( $age < self::FORM_MINIMUM_SECONDS || $age > self::FORM_MAXIMUM_SECONDS ) {
            return( false );
        }
        $state = $this->readState();
        $payload = $entry_id . '|' . $timestamp . '|' . $matches[2];
        return( hash_equals( hash_hmac( 'sha256', $payload, (string) $state['form_hmac_key'] ), $matches[3] ) );
    }

    protected function recordAttempt( string $email, string $ip_address, string $entry_id ) : bool {
        $state = $this->readState();
        $key = (string) $state['throttle_hmac_key'];
        $address_key = hash_hmac( 'sha256', $email, $key );
        $client_key = hash_hmac( 'sha256', trim( $ip_address ), $key );
        $entry_key = hash_hmac( 'sha256', $entry_id, $key );
        $now = $this->nowTimestamp();
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getFilename( 'throttle' ),
                function( array $store ) use ( $address_key, $client_key, $entry_key, $now ) : array {
                    $store = $this->requireThrottleStore( $store );
                    $store['attempts'] = array_values(
                        array_filter(
                            $store['attempts'],
                            static fn( array $attempt ) : bool => (int) $attempt['timestamp'] >= $now - 86400
                        )
                    );
                    $counts = [ 'address_hour' => 0, 'address_day' => 0, 'client_fifteen_minutes' => 0, 'client_day' => 0, 'entry_hour' => 0, 'global_hour' => 0 ];
                    foreach ( $store['attempts'] as $attempt ) {
                        $timestamp = (int) $attempt['timestamp'];
                        if ( (string) $attempt['address_key'] === $address_key ) {
                            $counts['address_day']++;
                            if ( $timestamp >= $now - 3600 ) {
                                $counts['address_hour']++;
                            }
                        }
                        if ( (string) $attempt['client_key'] === $client_key ) {
                            $counts['client_day']++;
                            if ( $timestamp >= $now - 900 ) {
                                $counts['client_fifteen_minutes']++;
                            }
                        }
                        if ( (string) $attempt['entry_key'] === $entry_key && $timestamp >= $now - 3600 ) {
                            $counts['entry_hour']++;
                        }
                        if ( $timestamp >= $now - 3600 ) {
                            $counts['global_hour']++;
                        }
                    }
                    $limits = $this->getThrottleLimits();
                    $blocked = false;
                    foreach ( $counts as $name => $count ) {
                        if ( $count >= (int) $limits[$name] ) {
                            $blocked = true;
                            break;
                        }
                    }
                    $store['attempts'][] = [
                        'address_key' => $address_key,
                        'client_key' => $client_key,
                        'entry_key' => $entry_key,
                        'timestamp' => $now,
                    ];
                    $store['attempts'] = array_slice( $store['attempts'], -self::THROTTLE_HISTORY_LIMIT );
                    return( [ 'data' => $store, 'result' => $blocked ] );
                },
                $this->defaultThrottleStore()
            )
        );
    }

    protected function enqueue( string $type, string $entry_id, string $comment_id ) : bool {
        if ( ! in_array( $type, [ 'verification', 'moderation', 'reply_notification' ], true ) ) {
            return( false );
        }
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getFilename( 'queue' ),
                function( array $store ) use ( $type, $entry_id, $comment_id ) : array {
                    $store = $this->requireQueueStore( $store );
                    foreach ( $store['items'] as $item ) {
                        if (
                            (string) $item['type'] === $type
                            && (string) $item['entry_id'] === $entry_id
                            && (string) $item['comment_id'] === $comment_id
                            && in_array( (string) $item['status'], [ 'pending', 'retrying', 'processing' ], true )
                        ) {
                            return( [ 'data' => $store, 'result' => true ] );
                        }
                    }
                    if ( count( $store['items'] ) >= self::QUEUE_LIMIT ) {
                        throw new \RuntimeException( 'comment_queue_limit' );
                    }
                    $now_utc = $this->nowUtc();
                    $store['items'][] = [
                        'id' => 'mail_' . gmdate( 'Ymd_His', $this->nowTimestamp() ) . '_' . bin2hex( random_bytes( 5 ) ),
                        'type' => $type,
                        'entry_id' => $entry_id,
                        'comment_id' => $comment_id,
                        'status' => 'pending',
                        'attempts' => 0,
                        'next_attempt_utc' => $now_utc,
                        'created_at_utc' => $now_utc,
                        'updated_at_utc' => $now_utc,
                    ];
                    return( [ 'data' => $store, 'result' => true ] );
                },
                $this->defaultQueueStore()
            )
        );
    }

    protected function claimDueItems( int $limit ) : array {
        return(
            (array) TinyMashLockedJsonFile::mutate(
                $this->getFilename( 'queue' ),
                function( array $store ) use ( $limit ) : array {
                    $store = $this->requireQueueStore( $store );
                    $now = $this->nowTimestamp();
                    $claimed = [];
                    foreach ( $store['items'] as $index => $item ) {
                        if ( count( $claimed ) >= $limit ) {
                            break;
                        }
                        $status = (string) $item['status'];
                        $updated = strtotime( (string) $item['updated_at_utc'] );
                        if ( $status === 'processing' && is_int( $updated ) && $updated < $now - 900 ) {
                            $status = 'retrying';
                        }
                        $next_attempt = strtotime( (string) $item['next_attempt_utc'] );
                        if ( ! in_array( $status, [ 'pending', 'retrying' ], true ) || ( is_int( $next_attempt ) && $next_attempt > $now ) ) {
                            continue;
                        }
                        $item['status'] = 'processing';
                        $item['attempts'] = (int) $item['attempts'] + 1;
                        $item['updated_at_utc'] = $this->nowUtc();
                        $store['items'][$index] = $item;
                        $claimed[] = $item;
                    }
                    return( [ 'data' => $store, 'result' => $claimed ] );
                },
                $this->defaultQueueStore()
            )
        );
    }

    protected function deliverQueueItem( array $item ) : string {
        if ( ! $this->mailer instanceof TinyMashMailer || ! $this->config instanceof TinyMashConfig ) {
            return( 'retry' );
        }
        $entry_id = (string) $item['entry_id'];
        $comment_id = (string) $item['comment_id'];
        $comment = $this->comments->getComment( $entry_id, $comment_id );
        if ( ! is_array( $comment ) ) {
            return( 'suppressed' );
        }

        if ( (string) $item['type'] === 'verification' ) {
            if ( (string) $comment['status'] !== 'awaiting_verification' || (string) $comment['email'] === '' ) {
                return( 'suppressed' );
            }
            $token = $this->comments->refreshGuestVerificationToken( $entry_id, $comment_id );
            if ( $token === '' ) {
                return( 'suppressed' );
            }
            $url = $this->buildVerificationUrl( $entry_id, $comment_id, $token );
            $site_name = $this->config->getSiteName();
            $message = $this->mailer->buildPublicMessage(
                'You are receiving this e-mail because a comment was submitted to ' . $site_name . ' using this address.',
                "Confirm your e-mail address before your comment is reviewed:\n\n" . $url . "\n\nIf you did not post this comment, you can ignore this message.",
                '<p>Confirm your e-mail address before your comment is reviewed.</p><p><a href="' . $this->escape( $url ) . '">Confirm comment</a></p><p>If you did not post this comment, you can ignore this message.</p>',
                $site_name,
                (string) $this->config->configGetBaseURL()
            );
            $this->mailer->sendMessage( $this->config->getSystemSettings(), (string) $comment['email'], '[' . $site_name . '] Confirm your comment', $message['text'], $message['html'], [], $site_name );
            return( 'delivered' );
        }

        if ( (string) $item['type'] === 'reply_notification' ) {
            return( $this->deliverReplyNotification( $entry_id, $comment ) );
        }

        if ( (string) $item['type'] !== 'moderation' || (string) $comment['status'] !== 'pending' ) {
            return( 'suppressed' );
        }
        $recipient = $this->getModeratorEmail( (string) $comment['scope'] );
        if ( $recipient === '' ) {
            return( 'suppressed' );
        }
        $entry = $this->content_repository instanceof TinyMashContentRepository ? $this->content_repository->getPublishedEntryById( $entry_id ) : null;
        $entry_title = is_array( $entry ) ? mb_trim( (string) ( $entry['title'] ?? '' ) ) : '';
        $entry_url = is_array( $entry ) ? $this->buildEntryUrl( $entry ) : rtrim( (string) $this->config->configGetBaseURL(), '/' );
        $moderation_url = $this->buildModerationUrl( (string) $comment['scope'] );
        $site_name = $this->config->getSiteName();
        $text = 'Comment by ' . (string) $comment['author_name'] . " is awaiting review.\n";
        if ( $entry_title !== '' ) {
            $text .= 'Entry: ' . $entry_title . "\n";
        }
        $text .= "\n" . (string) $comment['body'] . "\n\nView: " . $entry_url . "\nReview: " . $moderation_url . "\n";
        $this->mailer->sendMessage( $this->config->getSystemSettings(), $recipient, '[' . $site_name . '] Comment awaiting review', $text, '', [], $site_name );
        return( 'delivered' );
    }

    protected function deliverReplyNotification( string $entry_id, array $reply ) : string {
        if (
            (string) ( $reply['status'] ?? '' ) !== 'approved'
            || (string) ( $reply['parent_id'] ?? '' ) === ''
            || ! $this->content_repository instanceof TinyMashContentRepository
        ) {
            return( 'suppressed' );
        }
        $parent = $this->comments->getComment( $entry_id, (string) $reply['parent_id'] );
        if (
            ! is_array( $parent )
            || (string) ( $parent['status'] ?? '' ) !== 'approved'
            || empty( $parent['reply_notifications_enabled'] )
        ) {
            return( 'suppressed' );
        }
        $recipient = $this->getCommentEmail( $parent );
        if ( $recipient === '' || $recipient === $this->getCommentEmail( $reply ) ) {
            return( 'suppressed' );
        }
        if (
            (string) ( $parent['author_type'] ?? '' ) === 'account'
            && (string) ( $reply['author_type'] ?? '' ) === 'account'
            && (string) ( $parent['author_username'] ?? '' ) === (string) ( $reply['author_username'] ?? '' )
        ) {
            return( 'suppressed' );
        }
        $entry = $this->content_repository->getPublishedEntryById( $entry_id );
        if ( ! is_array( $entry ) || ! $this->entryHasNotificationAccess( $entry ) ) {
            return( 'suppressed' );
        }
        $token = $this->comments->addReplyUnsubscribeToken( $entry_id, (string) $parent['id'] );
        if ( $token === '' ) {
            return( 'suppressed' );
        }

        $site_name = $this->config instanceof TinyMashConfig ? $this->config->getSiteName() : 'tinymash';
        $entry_title = mb_trim( (string) ( $entry['title'] ?? '' ) );
        $entry_url = $this->buildEntryUrl( $entry ) . '#comment-' . str_replace( '_', '-', (string) $reply['id'] );
        $unsubscribe_url = $this->buildReplyUnsubscribeUrl( $entry_id, (string) $parent['id'], $token );
        $author_name = (string) ( $reply['author_name'] ?? 'Someone' );
        $text = $author_name . ' replied to your comment';
        if ( $entry_title !== '' ) {
            $text .= ' on ' . $entry_title;
        }
        $text .= ":\n\n" . (string) $reply['body'] . "\n\nView: " . $entry_url;
        $html = '<p>' . $this->escape( $author_name ) . ' replied to your comment';
        if ( $entry_title !== '' ) {
            $html .= ' on <strong>' . $this->escape( $entry_title ) . '</strong>';
        }
        $html .= '.</p><p>' . nl2br( $this->escape( (string) $reply['body'] ) ) . '</p><p><a href="' . $this->escape( $entry_url ) . '">View reply</a></p>';
        $reason = 'You are receiving this e-mail because you asked to be notified about replies to your comment';
        if ( $entry_title !== '' ) {
            $reason .= ' on ' . $entry_title;
        }
        $message = $this->mailer->buildPublicMessage(
            $reason . '.',
            $text,
            $html,
            $site_name,
            (string) $this->config->configGetBaseURL(),
            [ [ 'label' => 'Stop reply notifications for this comment', 'url' => $unsubscribe_url ] ]
        );
        $headers = [ 'List-Unsubscribe' => '<' . $unsubscribe_url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click' ];
        $this->mailer->sendMessage( $this->config->getSystemSettings(), $recipient, '[' . $site_name . '] New reply to your comment', $message['text'], $message['html'], $headers, $site_name );
        return( 'delivered' );
    }

    protected function submissionValidationMessage( string $code ) : string {
        return(
            match ( $code ) {
                'author_name' => 'Enter a name without emoji or smileys.',
                'email' => 'Enter a valid e-mail address.',
                'body' => 'Enter a comment of no more than 4,000 characters.',
                'body_format' => 'The comment contains unsupported formatting.',
                'links' => 'A comment may contain no more than three links.',
                'parent_comment' => 'The comment you selected for a reply is unavailable.',
                default => 'Check the comment fields and try again.',
            }
        );
    }

    protected function finishQueueItem( string $item_id, string $delivery_status ) : string {
        return(
            (string) TinyMashLockedJsonFile::mutate(
                $this->getFilename( 'queue' ),
                function( array $store ) use ( $item_id, $delivery_status ) : array {
                    $store = $this->requireQueueStore( $store );
                    foreach ( $store['items'] as $index => $item ) {
                        if ( (string) $item['id'] !== $item_id ) {
                            continue;
                        }
                        $result = $delivery_status;
                        if ( $delivery_status === 'retry' ) {
                            if ( (int) $item['attempts'] >= self::QUEUE_MAX_ATTEMPTS ) {
                                $item['status'] = 'failed';
                                $result = 'failed';
                            } else {
                                $delays = [ 60, 300, 1800, 7200 ];
                                $delay = $delays[max( 0, (int) $item['attempts'] - 1 )] ?? 21600;
                                $item['status'] = 'retrying';
                                $item['next_attempt_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() + $delay );
                                $result = 'retried';
                            }
                        } else {
                            $item['status'] = in_array( $delivery_status, [ 'delivered', 'suppressed' ], true ) ? $delivery_status : 'failed';
                            $result = $item['status'] === 'suppressed' ? 'suppressed' : $item['status'];
                        }
                        $item['updated_at_utc'] = $this->nowUtc();
                        $store['items'][$index] = $item;
                        if ( $result === 'failed' ) {
                            $this->notifyFinalFailure( $item );
                        }
                        return( [ 'data' => $store, 'result' => $result ] );
                    }
                    return( [ 'data' => $store, 'result' => 'suppressed' ] );
                },
                $this->defaultQueueStore()
            )
        );
    }

    protected function getModeratorEmail( string $scope ) : string {
        if ( str_starts_with( $scope, 'author:' ) && $this->user_repository instanceof TinyMashUserRepository ) {
            $user = $this->user_repository->getUserByAuthorSlug( substr( $scope, 7 ) );
            $email = is_array( $user ) ? mb_strtolower( mb_trim( (string) ( $user['email'] ?? '' ) ) ) : '';
            if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ) {
                return( $email );
            }
        }
        if ( ! $this->config instanceof TinyMashConfig ) {
            return( '' );
        }
        $settings = $this->config->getSystemSettings();
        $email = mb_strtolower( mb_trim( (string) ( $settings['system_notifications_email'] ?? '' ) ) );
        if ( $email === '' ) {
            $email = mb_strtolower( mb_trim( (string) ( $settings['admin_email'] ?? '' ) ) );
        }
        return( filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '' );
    }

    protected function getCommentEmail( array $comment ) : string {
        if ( (string) ( $comment['author_type'] ?? '' ) === 'guest' ) {
            $email = mb_strtolower( mb_trim( (string) ( $comment['email'] ?? '' ) ) );
        } elseif ( $this->user_repository instanceof TinyMashUserRepository ) {
            $user = $this->user_repository->getUserByUsername( (string) ( $comment['author_username'] ?? '' ) );
            $email = is_array( $user ) ? mb_strtolower( mb_trim( (string) ( $user['email'] ?? '' ) ) ) : '';
        } else {
            $email = '';
        }
        return( filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '' );
    }

    protected function entryHasNotificationAccess( array $entry ) : bool {
        if ( ! $this->config instanceof TinyMashConfig ) {
            return( false );
        }
        $author_slug = mb_strtolower( mb_trim( (string) ( $entry['author_slug'] ?? '' ) ) );
        if (
            (string) ( $entry['scope'] ?? '' ) === 'author'
            && $this->user_repository instanceof TinyMashUserRepository
            && ! $this->user_repository->isAuthorContentPublic( $author_slug )
        ) {
            return( false );
        }
        if ( $this->config->isSitePublic() ) {
            return( true );
        }
        if ( ! $this->secret_link_service instanceof TinyMashSecretLinkService || ! $this->secret_link_service->isEnabled() ) {
            return( false );
        }
        if ( (string) ( $entry['scope'] ?? '' ) !== 'author' ) {
            return( is_array( $this->secret_link_service->getSiteSecretLink() ) );
        }
        return(
            is_array( $this->secret_link_service->getSiteSecretLink() )
            || ( $author_slug !== '' && is_array( $this->secret_link_service->getAuthorSecretLink( $author_slug ) ) )
        );
    }

    protected function buildVerificationUrl( string $entry_id, string $comment_id, string $token ) : string {
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        return( $base_url . '/comments/verify?entry=' . rawurlencode( $entry_id ) . '&comment=' . rawurlencode( $comment_id ) . '&token=' . rawurlencode( $token ) );
    }

    protected function buildReplyUnsubscribeUrl( string $entry_id, string $comment_id, string $token ) : string {
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        return( $base_url . '/comments/unsubscribe?entry=' . rawurlencode( $entry_id ) . '&comment=' . rawurlencode( $comment_id ) . '&token=' . rawurlencode( $token ) );
    }

    protected function buildEntryUrl( array $entry ) : string {
        $path = $this->theme instanceof TinyMashTheme ? $this->theme->getEntryURL( $entry ) : '/';
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        return( $base_url . ( str_starts_with( $path, '/' ) ? $path : '/' . $path ) );
    }

    protected function buildModerationUrl( string $scope ) : string {
        $admin_url = $this->config instanceof TinyMashConfig ? (string) $this->config->configGetAdminURL() : '/admin';
        if ( preg_match( '#^https?://#i', $admin_url ) === 1 ) {
            return( rtrim( $admin_url, '/' ) . '/comments?scope=' . rawurlencode( $scope ) . '&status=pending' );
        }
        $base_url = $this->config instanceof TinyMashConfig ? rtrim( (string) $this->config->configGetBaseURL(), '/' ) : '';
        $admin_url = '/' . ltrim( $admin_url !== '' ? $admin_url : '/admin', '/' );
        return( $base_url . rtrim( $admin_url, '/' ) . '/comments?scope=' . rawurlencode( $scope ) . '&status=pending' );
    }

    protected function notifyFinalFailure( array $item ) : void {
        if ( ! $this->notification_service instanceof TinyMashNotificationService ) {
            return;
        }
        try {
            $this->notification_service->createNotification(
                'comment_delivery_failed',
                'Comment e-mail failed',
                'A comment e-mail could not be delivered after five attempts.',
                [
                    'severity' => 'warning',
                    'dedupe_key' => 'comment-mail-failed:' . (string) $item['id'],
                    'dedupe_window_seconds' => 86400,
                ]
            );
        } catch ( \Throwable ) {
            return;
        }
    }

    protected function notifyQueueFailure( string $comment_id ) : void {
        if ( ! $this->notification_service instanceof TinyMashNotificationService ) {
            return;
        }
        try {
            $this->notification_service->createNotification(
                'comment_delivery_failed',
                'Comment e-mail was not queued',
                'A reply was published, but its notification could not be queued.',
                [
                    'severity' => 'warning',
                    'dedupe_key' => 'comment-mail-queue-failed:' . $comment_id,
                    'dedupe_window_seconds' => 86400,
                ]
            );
        } catch ( \Throwable ) {
            return;
        }
    }

    protected function readState() : array {
        return( $this->requireState( TinyMashLockedJsonFile::readStrict( $this->getFilename( 'submission-state' ) ) ) );
    }

    protected function requireState( array $state ) : array {
        if (
            (int) ( $state['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION
            || preg_match( '/^[a-f0-9]{64}$/', (string) ( $state['form_hmac_key'] ?? '' ) ) !== 1
            || preg_match( '/^[a-f0-9]{64}$/', (string) ( $state['throttle_hmac_key'] ?? '' ) ) !== 1
        ) {
            throw new \RuntimeException( 'comment_submission_state' );
        }
        return( $state );
    }

    protected function readQueue() : array {
        return( $this->requireQueueStore( TinyMashLockedJsonFile::readStrict( $this->getFilename( 'queue' ) ) ) );
    }

    protected function readThrottle() : array {
        return( $this->requireThrottleStore( TinyMashLockedJsonFile::readStrict( $this->getFilename( 'throttle' ) ) ) );
    }

    protected function requireQueueStore( array $store ) : array {
        if ( (int) ( $store['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION || ! is_array( $store['items'] ?? null ) || count( $store['items'] ) > self::QUEUE_LIMIT ) {
            throw new \RuntimeException( 'comment_queue_store' );
        }
        $seen = [];
        foreach ( $store['items'] as $item ) {
            $id = is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '';
            if (
                preg_match( '/^mail_[0-9]{8}_[0-9]{6}_[a-f0-9]{10}$/', $id ) !== 1
                || isset( $seen[$id] )
                || ! in_array( (string) ( $item['type'] ?? '' ), [ 'verification', 'moderation', 'reply_notification' ], true )
                || preg_match( '/^entry_[a-z0-9_]{1,96}$/', (string) ( $item['entry_id'] ?? '' ) ) !== 1
                || preg_match( '/^comment_[0-9]{8}_[0-9]{6}_[a-f0-9]{16}$/', (string) ( $item['comment_id'] ?? '' ) ) !== 1
                || ! in_array( (string) ( $item['status'] ?? '' ), [ 'pending', 'processing', 'retrying', 'delivered', 'suppressed', 'failed' ], true )
                || ! is_int( $item['attempts'] ?? null )
                || (int) ( $item['attempts'] ?? -1 ) < 0
                || (int) ( $item['attempts'] ?? -1 ) > self::QUEUE_MAX_ATTEMPTS
                || ! $this->isUtcTimestamp( (string) ( $item['next_attempt_utc'] ?? '' ) )
                || ! $this->isUtcTimestamp( (string) ( $item['created_at_utc'] ?? '' ) )
                || ! $this->isUtcTimestamp( (string) ( $item['updated_at_utc'] ?? '' ) )
            ) {
                throw new \RuntimeException( 'comment_queue_store' );
            }
            $seen[$id] = true;
        }
        return( $store );
    }

    protected function requireThrottleStore( array $store ) : array {
        if ( (int) ( $store['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION || ! is_array( $store['attempts'] ?? null ) || count( $store['attempts'] ) > self::THROTTLE_HISTORY_LIMIT ) {
            throw new \RuntimeException( 'comment_throttle_store' );
        }
        foreach ( $store['attempts'] as $attempt ) {
            if (
                ! is_array( $attempt )
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $attempt['address_key'] ?? '' ) ) !== 1
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $attempt['client_key'] ?? '' ) ) !== 1
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $attempt['entry_key'] ?? '' ) ) !== 1
                || (int) ( $attempt['timestamp'] ?? 0 ) < 1
            ) {
                throw new \RuntimeException( 'comment_throttle_store' );
            }
        }
        return( $store );
    }

    protected function defaultState() : array {
        return(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'form_hmac_key' => bin2hex( random_bytes( 32 ) ),
                'throttle_hmac_key' => bin2hex( random_bytes( 32 ) ),
                'created_at_utc' => $this->nowUtc(),
            ]
        );
    }

    protected function defaultQueueStore() : array {
        return( [ 'schema_version' => self::SCHEMA_VERSION, 'items' => [] ] );
    }

    protected function defaultThrottleStore() : array {
        return( [ 'schema_version' => self::SCHEMA_VERSION, 'attempts' => [] ] );
    }

    protected function getFilename( string $name ) : string {
        if ( ! in_array( $name, [ 'submission-state', 'queue', 'throttle' ], true ) ) {
            throw new \InvalidArgumentException( 'comment_store_name' );
        }
        $filename = $this->storage_directory . DIRECTORY_SEPARATOR . $name . '.json';
        if ( is_link( $this->storage_directory ) || is_link( $filename ) || is_link( $filename . '.lock' ) ) {
            throw new \RuntimeException( 'comment_store_path' );
        }
        return( $filename );
    }

    protected function nowTimestamp() : int {
        return( $this->clock instanceof \Closure ? (int) ( $this->clock )() : time() );
    }

    protected function nowUtc() : string {
        return( gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() ) );
    }

    protected function isUtcTimestamp( string $value ) : bool {
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', trim( $value ), new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        return(
            $date instanceof \DateTimeImmutable
            && ( ! is_array( $errors ) || ( $errors['warning_count'] === 0 && $errors['error_count'] === 0 ) )
        );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
