<?php

use app\classes\TinyMashConfig;
use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;

class TinyMashCommentsPrivacyService {

    protected const SCHEMA_VERSION = 1;
    protected const TOKEN_TTL_SECONDS = 172800;
    protected const QUEUE_BATCH_LIMIT = 20;
    protected const QUEUE_MAX_ATTEMPTS = 5;
    protected const REQUEST_LIMIT = 1000;
    protected const ATTEMPT_LIMIT = 5000;

    protected string $storage_directory;
    protected TinyMashCommentsService $comments;
    protected ?TinyMashConfig $config;
    protected ?TinyMashMailer $mailer;
    protected ?TinyMashNotificationService $notification_service;
    protected ?\Closure $clock;

    public function __construct(
        string $storage_directory,
        TinyMashCommentsService $comments,
        ?TinyMashConfig $config = null,
        ?TinyMashMailer $mailer = null,
        ?TinyMashNotificationService $notification_service = null,
        ?\Closure $clock = null
    ) {
        $this->storage_directory = rtrim( $storage_directory, DIRECTORY_SEPARATOR );
        if ( $this->storage_directory === '' ) {
            throw new \InvalidArgumentException( 'storage_directory' );
        }
        $this->comments = $comments;
        $this->config = $config;
        $this->mailer = $mailer;
        $this->notification_service = $notification_service;
        $this->clock = $clock;
    }

    public function initialize() : void {
        TinyMashLockedJsonFile::mutate(
            $this->getFilename(),
            function( array $store ) : array {
                return( $store === [] ? $this->defaultStore() : $this->requireStore( $store ) );
            },
            []
        );
    }

    public function requestAccess( string $email, string $ip_address ) : bool {
        $email = $this->normalizeEmail( $email );
        if ( $email === '' ) {
            return( true );
        }
        $store = $this->readStore();
        $hmac_key = (string) $store['hmac_key'];
        $email_key = hash_hmac( 'sha256', $email, $hmac_key );
        $client_key = hash_hmac( 'sha256', trim( $ip_address ), $hmac_key );
        if ( $this->recordAttempt( $email_key, $client_key ) ) {
            return( true );
        }

        if ( $this->comments->getPrivacyComments( $email_key, $hmac_key ) === [] ) {
            return( true );
        }

        TinyMashLockedJsonFile::mutate(
            $this->getFilename(),
            function( array $store ) use ( $email_key ) : array {
                $store = $this->requireStore( $store );
                foreach ( $store['requests'] as $index => $request ) {
                    if ( (string) $request['email_key'] !== $email_key ) {
                        continue;
                    }
                    if ( in_array( (string) $request['status'], [ 'pending', 'processing', 'retrying' ], true ) ) {
                        return( $store );
                    }
                    if ( (string) $request['status'] === 'ready' ) {
                        $now_utc = $this->nowUtc();
                        $store['requests'][$index]['status'] = 'pending';
                        $store['requests'][$index]['attempts'] = 0;
                        $store['requests'][$index]['next_attempt_utc'] = $now_utc;
                        $store['requests'][$index]['token_hash'] = '';
                        $store['requests'][$index]['token_expires_at_utc'] = '';
                        $store['requests'][$index]['updated_at_utc'] = $now_utc;
                        return( $store );
                    }
                }
                if ( count( $store['requests'] ) >= self::REQUEST_LIMIT ) {
                    throw new \RuntimeException( 'comment_privacy_request_limit' );
                }
                $now_utc = $this->nowUtc();
                $store['requests'][] = [
                    'id' => 'privacy_' . gmdate( 'Ymd_His', $this->nowTimestamp() ) . '_' . bin2hex( random_bytes( 8 ) ),
                    'email_key' => $email_key,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_utc' => $now_utc,
                    'token_hash' => '',
                    'token_expires_at_utc' => '',
                    'created_at_utc' => $now_utc,
                    'updated_at_utc' => $now_utc,
                ];
                return( $store );
            }
        );
        return( true );
    }

    public function runQueue( array $context = [] ) : array {
        $limit = min( self::QUEUE_BATCH_LIMIT, max( 1, (int) ( $context['limit'] ?? self::QUEUE_BATCH_LIMIT ) ) );
        $claimed = $this->claimDueRequests( $limit );
        $result = [ 'claimed' => count( $claimed ), 'delivered' => 0, 'retried' => 0, 'failed' => 0, 'suppressed' => 0 ];
        foreach ( $claimed as $request ) {
            try {
                $status = $this->deliverRequest( $request );
            } catch ( \Throwable ) {
                $status = 'retry';
            }
            $final_status = $this->finishRequest( (string) $request['id'], $status );
            if ( $final_status === 'failed' ) {
                $this->notifyFinalFailure( (string) $request['id'] );
            }
            if ( isset( $result[$final_status] ) ) {
                $result[$final_status]++;
            }
        }
        return( $result );
    }

    public function getAccess( string $request_id, string $token ) : ?array {
        try {
            $request = $this->findRequest( $request_id );
        } catch ( \InvalidArgumentException | \RuntimeException ) {
            return( null );
        }
        if ( ! is_array( $request ) || ! $this->accessMatches( $request, $token ) ) {
            return( null );
        }

        $store = $this->readStore();
        $comments = $this->comments->getPrivacyComments( (string) $request['email_key'], (string) $store['hmac_key'] );
        return( [ 'request_id' => $request_id, 'comment_count' => count( $comments ) ] );
    }

    public function exportData( string $request_id, string $token ) : ?array {
        $request = $this->findRequest( $request_id );
        if ( ! is_array( $request ) || ! $this->accessMatches( $request, $token ) ) {
            return( null );
        }
        $store = $this->readStore();
        $comments = $this->comments->getPrivacyComments( (string) $request['email_key'], (string) $store['hmac_key'] );
        $records = [];
        foreach ( $comments as $comment ) {
            $records[] = [
                'id' => (string) ( $comment['id'] ?? '' ),
                'entry_id' => (string) ( $comment['entry_id'] ?? '' ),
                'scope' => (string) ( $comment['scope'] ?? '' ),
                'parent_id' => (string) ( $comment['parent_id'] ?? '' ),
                'author_name' => (string) ( $comment['author_name'] ?? '' ),
                'email' => (string) ( $comment['email'] ?? '' ),
                'body' => (string) ( $comment['body'] ?? '' ),
                'status' => (string) ( $comment['status'] ?? '' ),
                'source' => (string) ( $comment['source'] ?? '' ),
                'created_at_utc' => (string) ( $comment['created_at_utc'] ?? '' ),
                'updated_at_utc' => (string) ( $comment['updated_at_utc'] ?? '' ),
                'verified_at_utc' => (string) ( $comment['verified_at_utc'] ?? '' ),
                'approved_at_utc' => (string) ( $comment['approved_at_utc'] ?? '' ),
                'reply_notifications_enabled' => ! empty( $comment['reply_notifications_enabled'] ),
            ];
        }
        if ( ! $this->consumeAccess( $request_id, $token ) ) {
            return( null );
        }
        return(
            [
                'schema_version' => 1,
                'exported_at_utc' => $this->nowUtc(),
                'comments' => $records,
            ]
        );
    }

    public function erase( string $request_id, string $token ) : ?array {
        $request = $this->findRequest( $request_id );
        if ( ! is_array( $request ) || ! $this->accessMatches( $request, $token ) ) {
            return( null );
        }
        $store = $this->readStore();
        $result = $this->comments->erasePrivacyComments( (string) $request['email_key'], (string) $store['hmac_key'] );
        if ( ! $this->consumeAccess( $request_id, $token ) ) {
            return( null );
        }
        return( $result );
    }

    public function prune() : int {
        return(
            (int) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) : array {
                    $store = $this->requireStore( $store );
                    $now = $this->nowTimestamp();
                    $store['attempts'] = array_values(
                        array_filter(
                            $store['attempts'],
                            static fn( array $attempt ) : bool => (int) $attempt['timestamp'] >= $now - 86400
                        )
                    );
                    $before = count( $store['requests'] );
                    $store['requests'] = array_values(
                        array_filter(
                            $store['requests'],
                            function( array $request ) use ( $now ) : bool {
                                $updated = $this->parseUtcTimestamp( (string) $request['updated_at_utc'] );
                                if ( $updated === null ) {
                                    return( true );
                                }
                                $status = (string) $request['status'];
                                if ( in_array( $status, [ 'used', 'suppressed', 'failed' ], true ) ) {
                                    return( $updated >= $now - 2592000 );
                                }
                                if ( $status === 'ready' ) {
                                    $expires = $this->parseUtcTimestamp( (string) $request['token_expires_at_utc'] );
                                    return( $expires === null || $expires >= $now - 2592000 );
                                }
                                return( true );
                            }
                        )
                    );
                    return( [ 'data' => $store, 'result' => $before - count( $store['requests'] ) ] );
                }
            )
        );
    }

    protected function recordAttempt( string $email_key, string $client_key ) : bool {
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) use ( $email_key, $client_key ) : array {
                    $store = $this->requireStore( $store );
                    $now = $this->nowTimestamp();
                    $store['attempts'] = array_values(
                        array_filter(
                            $store['attempts'],
                            static fn( array $attempt ) : bool => (int) $attempt['timestamp'] >= $now - 86400
                        )
                    );
                    $address_hour = 0;
                    $client_hour = 0;
                    $global_hour = 0;
                    foreach ( $store['attempts'] as $attempt ) {
                        if ( (int) $attempt['timestamp'] < $now - 3600 ) {
                            continue;
                        }
                        $global_hour++;
                        if ( (string) $attempt['email_key'] === $email_key ) {
                            $address_hour++;
                        }
                        if ( (string) $attempt['client_key'] === $client_key ) {
                            $client_hour++;
                        }
                    }
                    $blocked = $address_hour >= 3 || $client_hour >= 10 || $global_hour >= 30;
                    $store['attempts'][] = [ 'email_key' => $email_key, 'client_key' => $client_key, 'timestamp' => $now ];
                    $store['attempts'] = array_slice( $store['attempts'], -self::ATTEMPT_LIMIT );
                    return( [ 'data' => $store, 'result' => $blocked ] );
                }
            )
        );
    }

    protected function claimDueRequests( int $limit ) : array {
        return(
            (array) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) use ( $limit ) : array {
                    $store = $this->requireStore( $store );
                    $now = $this->nowTimestamp();
                    $claimed = [];
                    foreach ( $store['requests'] as $index => $request ) {
                        if ( count( $claimed ) >= $limit ) {
                            break;
                        }
                        $status = (string) $request['status'];
                        $updated = $this->parseUtcTimestamp( (string) $request['updated_at_utc'] );
                        if ( $status === 'processing' && $updated !== null && $updated < $now - 900 ) {
                            $status = 'retrying';
                        }
                        $next_attempt = $this->parseUtcTimestamp( (string) $request['next_attempt_utc'] );
                        if ( ! in_array( $status, [ 'pending', 'retrying' ], true ) || ( $next_attempt !== null && $next_attempt > $now ) ) {
                            continue;
                        }
                        $request['status'] = 'processing';
                        $request['attempts'] = (int) $request['attempts'] + 1;
                        $request['updated_at_utc'] = $this->nowUtc();
                        $store['requests'][$index] = $request;
                        $claimed[] = $request;
                    }
                    return( [ 'data' => $store, 'result' => $claimed ] );
                }
            )
        );
    }

    protected function deliverRequest( array $request ) : string {
        if ( ! $this->config instanceof TinyMashConfig || ! $this->mailer instanceof TinyMashMailer ) {
            return( 'retry' );
        }
        $store = $this->readStore();
        $comments = $this->comments->getPrivacyComments( (string) $request['email_key'], (string) $store['hmac_key'] );
        $email = (string) ( $comments[0]['email'] ?? '' );
        if ( $email === '' ) {
            return( 'suppressed' );
        }
        $token = bin2hex( random_bytes( 32 ) );
        if ( ! $this->setRequestToken( (string) $request['id'], $token ) ) {
            return( 'suppressed' );
        }
        $url = rtrim( (string) $this->config->configGetBaseURL(), '/' ) . '/comments/privacy?request=' . rawurlencode( (string) $request['id'] ) . '&token=' . rawurlencode( $token );
        $site_name = $this->config->getSiteName();
        $message = $this->mailer->buildPublicMessage(
            'You are receiving this e-mail because a comment privacy link was requested for this address on ' . $site_name . '.',
            "Use this link to export or erase comments associated with your e-mail address:\n\n" . $url . "\n\nIf you did not request this link, you can ignore this message.",
            '<p>Use this link to export or erase comments associated with your e-mail address.</p><p><a href="' . $this->escape( $url ) . '">Manage comments</a></p><p>If you did not request this link, you can ignore this message.</p>',
            $site_name,
            (string) $this->config->configGetBaseURL()
        );
        $this->mailer->sendMessage( $this->config->getSystemSettings(), $email, '[' . $site_name . '] Comment privacy request', $message['text'], $message['html'], [], $site_name );
        return( 'delivered' );
    }

    protected function setRequestToken( string $request_id, string $token ) : bool {
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) use ( $request_id, $token ) : array {
                    $store = $this->requireStore( $store );
                    foreach ( $store['requests'] as $index => $request ) {
                        if ( (string) $request['id'] !== $request_id || (string) $request['status'] !== 'processing' ) {
                            continue;
                        }
                        $store['requests'][$index]['token_hash'] = hash( 'sha256', $token );
                        $store['requests'][$index]['token_expires_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() + self::TOKEN_TTL_SECONDS );
                        $store['requests'][$index]['updated_at_utc'] = $this->nowUtc();
                        return( [ 'data' => $store, 'result' => true ] );
                    }
                    return( [ 'data' => $store, 'result' => false ] );
                }
            )
        );
    }

    protected function finishRequest( string $request_id, string $delivery_status ) : string {
        return(
            (string) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) use ( $request_id, $delivery_status ) : array {
                    $store = $this->requireStore( $store );
                    foreach ( $store['requests'] as $index => $request ) {
                        if ( (string) $request['id'] !== $request_id ) {
                            continue;
                        }
                        $result = $delivery_status;
                        if ( $delivery_status === 'retry' ) {
                            if ( (int) $request['attempts'] >= self::QUEUE_MAX_ATTEMPTS ) {
                                $request['status'] = 'failed';
                                $result = 'failed';
                            } else {
                                $delays = [ 60, 300, 1800, 7200 ];
                                $delay = $delays[max( 0, (int) $request['attempts'] - 1 )] ?? 21600;
                                $request['status'] = 'retrying';
                                $request['next_attempt_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() + $delay );
                                $result = 'retried';
                            }
                            $request['token_hash'] = '';
                            $request['token_expires_at_utc'] = '';
                        } elseif ( $delivery_status === 'delivered' ) {
                            $request['status'] = 'ready';
                        } else {
                            $request['status'] = 'suppressed';
                            $request['token_hash'] = '';
                            $request['token_expires_at_utc'] = '';
                            $result = 'suppressed';
                        }
                        $request['updated_at_utc'] = $this->nowUtc();
                        $store['requests'][$index] = $request;
                        return( [ 'data' => $store, 'result' => $result ] );
                    }
                    return( [ 'data' => $store, 'result' => 'suppressed' ] );
                }
            )
        );
    }

    protected function notifyFinalFailure( string $request_id ) : void {
        if ( ! $this->notification_service instanceof TinyMashNotificationService ) {
            return;
        }
        try {
            $this->notification_service->createNotification(
                'comment_privacy_delivery_failed',
                'Comment privacy e-mail failed',
                'A comment privacy e-mail could not be delivered after five attempts.',
                [
                    'severity' => 'warning',
                    'dedupe_key' => 'comment-privacy-mail-failed:' . $request_id,
                    'dedupe_window_seconds' => 86400,
                ]
            );
        } catch ( \Throwable ) {
            return;
        }
    }

    protected function findRequest( string $request_id ) : ?array {
        if ( preg_match( '/^privacy_[0-9]{8}_[0-9]{6}_[a-f0-9]{16}$/', trim( $request_id ) ) !== 1 ) {
            throw new \InvalidArgumentException( 'privacy_request' );
        }
        foreach ( $this->readStore()['requests'] as $request ) {
            if ( (string) $request['id'] === $request_id ) {
                return( $request );
            }
        }
        return( null );
    }

    protected function accessMatches( array $request, string $token ) : bool {
        $token = trim( $token );
        $expires = $this->parseUtcTimestamp( (string) ( $request['token_expires_at_utc'] ?? '' ) );
        $stored_hash = (string) ( $request['token_hash'] ?? '' );
        return(
            (string) ( $request['status'] ?? '' ) === 'ready'
            && preg_match( '/^[a-f0-9]{64}$/', $token ) === 1
            && $expires !== null
            && $expires >= $this->nowTimestamp()
            && $stored_hash !== ''
            && hash_equals( $stored_hash, hash( 'sha256', $token ) )
        );
    }

    protected function consumeAccess( string $request_id, string $token ) : bool {
        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getFilename(),
                function( array $store ) use ( $request_id, $token ) : array {
                    $store = $this->requireStore( $store );
                    foreach ( $store['requests'] as $index => $request ) {
                        if ( (string) $request['id'] !== $request_id || ! $this->accessMatches( $request, $token ) ) {
                            continue;
                        }
                        $store['requests'][$index]['status'] = 'used';
                        $store['requests'][$index]['token_hash'] = '';
                        $store['requests'][$index]['token_expires_at_utc'] = '';
                        $store['requests'][$index]['updated_at_utc'] = $this->nowUtc();
                        return( [ 'data' => $store, 'result' => true ] );
                    }
                    return( [ 'data' => $store, 'result' => false ] );
                }
            )
        );
    }

    protected function readStore() : array {
        return( $this->requireStore( TinyMashLockedJsonFile::readStrict( $this->getFilename() ) ) );
    }

    protected function requireStore( array $store ) : array {
        if (
            (int) ( $store['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION
            || preg_match( '/^[a-f0-9]{64}$/', (string) ( $store['hmac_key'] ?? '' ) ) !== 1
            || ! is_array( $store['requests'] ?? null )
            || ! is_array( $store['attempts'] ?? null )
            || count( $store['requests'] ) > self::REQUEST_LIMIT
            || count( $store['attempts'] ) > self::ATTEMPT_LIMIT
        ) {
            throw new \RuntimeException( 'comment_privacy_store' );
        }
        $seen = [];
        foreach ( $store['requests'] as $request ) {
            $id = is_array( $request ) ? (string) ( $request['id'] ?? '' ) : '';
            if (
                preg_match( '/^privacy_[0-9]{8}_[0-9]{6}_[a-f0-9]{16}$/', $id ) !== 1
                || isset( $seen[$id] )
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $request['email_key'] ?? '' ) ) !== 1
                || ! in_array( (string) ( $request['status'] ?? '' ), [ 'pending', 'processing', 'retrying', 'ready', 'used', 'suppressed', 'failed' ], true )
                || ! is_int( $request['attempts'] ?? null )
                || (int) ( $request['attempts'] ?? -1 ) < 0
                || (int) ( $request['attempts'] ?? -1 ) > self::QUEUE_MAX_ATTEMPTS
                || ! $this->isUtcTimestamp( (string) ( $request['next_attempt_utc'] ?? '' ) )
                || ! $this->isUtcTimestamp( (string) ( $request['created_at_utc'] ?? '' ) )
                || ! $this->isUtcTimestamp( (string) ( $request['updated_at_utc'] ?? '' ) )
                || ( (string) ( $request['token_hash'] ?? '' ) !== '' && preg_match( '/^[a-f0-9]{64}$/', (string) $request['token_hash'] ) !== 1 )
                || ( (string) ( $request['token_expires_at_utc'] ?? '' ) !== '' && ! $this->isUtcTimestamp( (string) $request['token_expires_at_utc'] ) )
            ) {
                throw new \RuntimeException( 'comment_privacy_store' );
            }
            $seen[$id] = true;
        }
        foreach ( $store['attempts'] as $attempt ) {
            if (
                ! is_array( $attempt )
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $attempt['email_key'] ?? '' ) ) !== 1
                || preg_match( '/^[a-f0-9]{64}$/', (string) ( $attempt['client_key'] ?? '' ) ) !== 1
                || (int) ( $attempt['timestamp'] ?? 0 ) < 1
            ) {
                throw new \RuntimeException( 'comment_privacy_store' );
            }
        }
        return( $store );
    }

    protected function defaultStore() : array {
        return(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'hmac_key' => bin2hex( random_bytes( 32 ) ),
                'requests' => [],
                'attempts' => [],
            ]
        );
    }

    protected function normalizeEmail( string $email ) : string {
        $email = mb_strtolower( mb_trim( $email ) );
        return( mb_strlen( $email ) <= 254 && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '' );
    }

    protected function getFilename() : string {
        $filename = $this->storage_directory . DIRECTORY_SEPARATOR . 'privacy.json';
        if ( is_link( $this->storage_directory ) || is_link( $filename ) || is_link( $filename . '.lock' ) ) {
            throw new \RuntimeException( 'comment_privacy_path' );
        }
        return( $filename );
    }

    protected function nowTimestamp() : int {
        return( $this->clock instanceof \Closure ? (int) ( $this->clock )() : time() );
    }

    protected function nowUtc() : string {
        return( gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() ) );
    }

    protected function parseUtcTimestamp( string $value ) : ?int {
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', trim( $value ), new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
            return( null );
        }
        return( $date->getTimestamp() );
    }

    protected function isUtcTimestamp( string $value ) : bool {
        return( $this->parseUtcTimestamp( $value ) !== null );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
