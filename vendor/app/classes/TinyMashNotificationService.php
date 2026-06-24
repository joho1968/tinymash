<?php
namespace app\classes;

class TinyMashNotificationService {

    protected string $store_filename;

    public function __construct( string $store_filename ) {
        $this->store_filename = $store_filename;
    }

    public function getStoreFilename() : string {
        return( $this->store_filename );
    }

    public function getSummary( bool $include_dismissed = false ) : array {
        $notifications = $this->getNotifications( $include_dismissed, 1000 );
        $summary = [
            'total' => count( $notifications ),
            'unread' => 0,
            'dismissed' => 0,
        ];

        foreach ( $notifications as $notification ) {
            if ( ! is_array( $notification ) ) {
                continue;
            }
            if ( ! empty( $notification['dismissed_at_utc'] ) ) {
                $summary['dismissed']++;
                continue;
            }
            if ( empty( $notification['read_at_utc'] ) ) {
                $summary['unread']++;
            }
        }

        return( $summary );
    }

    public function getNotifications( bool $include_dismissed = false, int $limit = 100 ) : array {
        $store = $this->readStore();
        $notifications = is_array( $store['notifications'] ?? null ) ? $store['notifications'] : [];
        $result = [];

        foreach ( $notifications as $notification ) {
            $normalized = $this->normalizeNotification( is_array( $notification ) ? $notification : [] );
            if ( empty( $normalized['id'] ) ) {
                continue;
            }
            if ( ! $include_dismissed && $normalized['dismissed_at_utc'] !== '' ) {
                continue;
            }

            $result[] = $normalized;
            if ( $limit > 0 && count( $result ) >= $limit ) {
                break;
            }
        }

        return( $result );
    }

    public function createNotification( string $type, string $title, string $message, array $options = [] ) : array {
        $type = $this->normalizeType( $type );
        $title = trim( $title );
        $message = trim( $message );
        if ( $type === '' ) {
            throw new \InvalidArgumentException( 'notification_type' );
        }
        if ( $title === '' ) {
            throw new \InvalidArgumentException( 'notification_title' );
        }
        if ( $message === '' ) {
            throw new \InvalidArgumentException( 'notification_message' );
        }

        $created_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $dedupe_key = trim( (string) ( $options['dedupe_key'] ?? '' ) );
        $dedupe_window_seconds = max( 0, (int) ( $options['dedupe_window_seconds'] ?? 0 ) );
        $severity = $this->normalizeSeverity( (string) ( $options['severity'] ?? 'info' ) );
        $actor_username = $this->normalizeUsername( (string) ( $options['actor_username'] ?? '' ) );
        $target_username = $this->normalizeUsername( (string) ( $options['target_username'] ?? '' ) );
        $context = is_array( $options['context'] ?? null ) ? $options['context'] : [];

        $result = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $type, $title, $message, $created_at_utc, $dedupe_key, $dedupe_window_seconds, $severity, $actor_username, $target_username, $context ) : array {
                $store = $this->normalizeStore( $store );
                if ( $dedupe_key !== '' && $dedupe_window_seconds > 0 ) {
                    foreach ( $store['notifications'] as $existing_notification ) {
                        $existing_notification = $this->normalizeNotification( is_array( $existing_notification ) ? $existing_notification : [] );
                        if ( $existing_notification['type'] !== $type ) {
                            continue;
                        }
                        if ( $existing_notification['dedupe_key'] !== $dedupe_key ) {
                            continue;
                        }
                        if ( $existing_notification['dismissed_at_utc'] !== '' ) {
                            continue;
                        }
                        $created_timestamp = strtotime( $existing_notification['created_at_utc'] );
                        if ( ! is_int( $created_timestamp ) || $created_timestamp <= 0 ) {
                            continue;
                        }
                        if ( $created_timestamp >= ( time() - $dedupe_window_seconds ) ) {
                            return(
                                [
                                    'data' => $store,
                                    'result' => $existing_notification,
                                ]
                            );
                        }
                    }
                }

                $notification = [
                    'id' => $this->generateNotificationId(),
                    'type' => $type,
                    'severity' => $severity,
                    'title' => mb_substr( $title, 0, 160 ),
                    'message' => mb_substr( $message, 0, 2000 ),
                    'created_at_utc' => $created_at_utc,
                    'read_at_utc' => '',
                    'read_by_username' => '',
                    'dismissed_at_utc' => '',
                    'dismissed_by_username' => '',
                    'actor_username' => $actor_username,
                    'target_username' => $target_username,
                    'context' => $context,
                    'dedupe_key' => $dedupe_key,
                ];

                array_unshift( $store['notifications'], $notification );
                $store['notifications'] = $this->normalizeNotifications( $store['notifications'] );

                return(
                    [
                        'data' => $store,
                        'result' => $notification,
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( is_array( $result ) ? $this->normalizeNotification( $result ) : [] );
    }

    public function markNotificationRead( string $notification_id, string $username = '' ) : bool {
        $notification_id = trim( $notification_id );
        if ( $notification_id === '' ) {
            return( false );
        }

        $updated = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $notification_id, $username ) : array {
                $store = $this->normalizeStore( $store );
                $did_update = false;
                foreach ( $store['notifications'] as $index => $notification ) {
                    $normalized = $this->normalizeNotification( is_array( $notification ) ? $notification : [] );
                    if ( $normalized['id'] !== $notification_id ) {
                        continue;
                    }

                    if ( $normalized['read_at_utc'] === '' ) {
                        $normalized['read_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $normalized['read_by_username'] = $this->normalizeUsername( $username );
                    }
                    $store['notifications'][$index] = $normalized;
                    $did_update = true;
                    break;
                }

                return(
                    [
                        'data' => $store,
                        'result' => $did_update,
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( $updated === true );
    }

    public function dismissNotification( string $notification_id, string $username = '' ) : bool {
        $notification_id = trim( $notification_id );
        if ( $notification_id === '' ) {
            return( false );
        }

        $updated = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $notification_id, $username ) : array {
                $store = $this->normalizeStore( $store );
                $did_update = false;
                foreach ( $store['notifications'] as $index => $notification ) {
                    $normalized = $this->normalizeNotification( is_array( $notification ) ? $notification : [] );
                    if ( $normalized['id'] !== $notification_id ) {
                        continue;
                    }

                    $dismissed_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
                    if ( $normalized['read_at_utc'] === '' ) {
                        $normalized['read_at_utc'] = $dismissed_at_utc;
                        $normalized['read_by_username'] = $this->normalizeUsername( $username );
                    }
                    $normalized['dismissed_at_utc'] = $dismissed_at_utc;
                    $normalized['dismissed_by_username'] = $this->normalizeUsername( $username );
                    $store['notifications'][$index] = $normalized;
                    $did_update = true;
                    break;
                }

                return(
                    [
                        'data' => $store,
                        'result' => $did_update,
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( $updated === true );
    }

    public function markAllActiveRead( string $username = '' ) : int {
        $updated_count = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $username ) : array {
                $store = $this->normalizeStore( $store );
                $did_update = 0;
                $read_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
                foreach ( $store['notifications'] as $index => $notification ) {
                    $normalized = $this->normalizeNotification( is_array( $notification ) ? $notification : [] );
                    if ( $normalized['dismissed_at_utc'] !== '' || $normalized['read_at_utc'] !== '' ) {
                        continue;
                    }

                    $normalized['read_at_utc'] = $read_at_utc;
                    $normalized['read_by_username'] = $this->normalizeUsername( $username );
                    $store['notifications'][$index] = $normalized;
                    $did_update++;
                }

                return(
                    [
                        'data' => $store,
                        'result' => $did_update,
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( is_int( $updated_count ) ? $updated_count : 0 );
    }

    public function pruneNotifications( int $retention_days ) : array {
        $retention_days = max( 0, $retention_days );
        $result = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $retention_days ) : array {
                $store = $this->normalizeStore( $store );
                $before_count = count( $store['notifications'] );
                if ( $retention_days > 0 ) {
                    $cutoff_timestamp = time() - ( $retention_days * 86400 );
                    $store['notifications'] = array_values(
                        array_filter(
                            $store['notifications'],
                            static function( mixed $notification ) use ( $cutoff_timestamp ) : bool {
                                if ( ! is_array( $notification ) ) {
                                    return( false );
                                }
                                $created_at_utc = trim( (string) ( $notification['created_at_utc'] ?? '' ) );
                                $created_timestamp = strtotime( $created_at_utc );
                                return( is_int( $created_timestamp ) && $created_timestamp >= $cutoff_timestamp );
                            }
                        )
                    );
                }

                return(
                    [
                        'data' => $store,
                        'result' => [
                            'removed' => max( 0, $before_count - count( $store['notifications'] ) ),
                            'retention_days' => $retention_days,
                        ],
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( is_array( $result ) ? $result : [ 'removed' => 0, 'retention_days' => $retention_days ] );
    }

    public function shouldSendEmailForType( string $type, array $system_settings ) : bool {
        $type = $this->normalizeType( $type );
        if ( $type === '' ) {
            return( false );
        }

        $email_events = is_array( $system_settings['notification_email_events'] ?? null )
            ? $system_settings['notification_email_events']
            : [];

        return( ! empty( $email_events[$type] ) );
    }

    public function sendNotificationEmail( string $type, string $subject, string $body, TinyMashMailer $mailer, array $system_settings, string $site_name ) : array {
        if ( ! $this->shouldSendEmailForType( $type, $system_settings ) ) {
            return(
                [
                    'sent' => false,
                    'status' => 'skipped',
                    'message' => 'E-mail delivery is disabled for this notification type.',
                ]
            );
        }

        $recipient_email = trim( (string) ( $system_settings['system_notifications_email'] ?? '' ) );
        if ( $recipient_email === '' ) {
            $recipient_email = trim( (string) ( $system_settings['admin_email'] ?? '' ) );
        }
        if ( $recipient_email === '' ) {
            return(
                [
                    'sent' => false,
                    'status' => 'skipped',
                    'message' => 'No notification e-mail address is configured.',
                ]
            );
        }

        try {
            $mailer->sendSystemNotificationEmail( $system_settings, $recipient_email, $subject, $body, $site_name );
        } catch ( \Throwable $e ) {
            return(
                [
                    'sent' => false,
                    'status' => 'error',
                    'message' => 'Unable to send notification e-mail: ' . $e->getMessage(),
                ]
            );
        }

        return(
            [
                'sent' => true,
                'status' => 'sent',
                'message' => 'Sent notification e-mail to ' . $recipient_email . '.',
            ]
        );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->getDefaultStore() ) ) );
    }

    protected function getDefaultStore() : array {
        return(
            [
                'format' => 'tinymash-notifications',
                'format_version' => 1,
                'notifications' => [],
            ]
        );
    }

    protected function normalizeStore( array $store ) : array {
        if ( ! isset( $store['notifications'] ) || ! is_array( $store['notifications'] ) ) {
            $store['notifications'] = [];
        }
        $store['format'] = 'tinymash-notifications';
        $store['format_version'] = 1;
        $store['notifications'] = $this->normalizeNotifications( $store['notifications'] );
        return( $store );
    }

    protected function normalizeNotifications( array $notifications ) : array {
        $normalized = [];
        foreach ( $notifications as $notification ) {
            $record = $this->normalizeNotification( is_array( $notification ) ? $notification : [] );
            if ( empty( $record['id'] ) ) {
                continue;
            }
            $normalized[] = $record;
        }

        usort(
            $normalized,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $right['created_at_utc'] ?? '' ), (string) ( $left['created_at_utc'] ?? '' ) ) );
            }
        );

        return( $normalized );
    }

    protected function normalizeNotification( array $notification ) : array {
        return(
            [
                'id' => trim( (string) ( $notification['id'] ?? '' ) ),
                'type' => $this->normalizeType( (string) ( $notification['type'] ?? '' ) ),
                'severity' => $this->normalizeSeverity( (string) ( $notification['severity'] ?? 'info' ) ),
                'title' => trim( (string) ( $notification['title'] ?? '' ) ),
                'message' => trim( (string) ( $notification['message'] ?? '' ) ),
                'created_at_utc' => trim( (string) ( $notification['created_at_utc'] ?? '' ) ),
                'read_at_utc' => trim( (string) ( $notification['read_at_utc'] ?? '' ) ),
                'read_by_username' => $this->normalizeUsername( (string) ( $notification['read_by_username'] ?? '' ) ),
                'dismissed_at_utc' => trim( (string) ( $notification['dismissed_at_utc'] ?? '' ) ),
                'dismissed_by_username' => $this->normalizeUsername( (string) ( $notification['dismissed_by_username'] ?? '' ) ),
                'actor_username' => $this->normalizeUsername( (string) ( $notification['actor_username'] ?? '' ) ),
                'target_username' => $this->normalizeUsername( (string) ( $notification['target_username'] ?? '' ) ),
                'context' => is_array( $notification['context'] ?? null ) ? $notification['context'] : [],
                'dedupe_key' => trim( (string) ( $notification['dedupe_key'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeType( string $type ) : string {
        $type = strtolower( trim( $type ) );
        return( preg_replace( '/[^a-z0-9_-]+/', '', $type ) ?? '' );
    }

    protected function normalizeSeverity( string $severity ) : string {
        $severity = strtolower( trim( $severity ) );
        return( in_array( $severity, [ 'secondary', 'info', 'success', 'warning', 'danger' ], true ) ? $severity : 'info' );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }

    protected function generateNotificationId() : string {
        return( 'notification_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }
}
