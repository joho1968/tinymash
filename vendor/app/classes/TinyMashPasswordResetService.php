<?php
namespace app\classes;

class TinyMashPasswordResetService {

    protected const REQUEST_TTL_SECONDS = 3600;
    protected const THROTTLE_WINDOW_SECONDS = 3600;
    protected const MAX_IP_REQUESTS = 5;
    protected const MAX_IDENTIFIER_REQUESTS = 3;

    protected string $store_filename;
    protected TinyMashUserRepository $user_repository;

    public function __construct( string $store_filename, TinyMashUserRepository $user_repository ) {
        $this->store_filename = $store_filename;
        $this->user_repository = $user_repository;
    }

    public function requestReset( string $identifier, string $ip_address, array $system_settings, TinyMashMailer $mailer, string $site_name, string $reset_base_url ) : array {
        $identifier = $this->normalizeIdentifier( $identifier );
        $ip_address = trim( $ip_address );
        $generic_result = [
            'accepted' => true,
            'message' => 'If that account exists and is allowed to reset its password, a reset link will be sent.',
            'throttled' => false,
        ];

        if ( $identifier === '' ) {
            return( $generic_result );
        }

        $throttle_state = $this->getThrottleState( $identifier, $ip_address, $system_settings );
        if ( ! empty( $throttle_state['blocked'] ) ) {
            return( $generic_result + [ 'throttled' => true ] );
        }

        $requested_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $user = $this->findResettableUser( $identifier, $system_settings );
        $this->recordAttempt( $identifier, $ip_address, $requested_at_utc );
        if ( ! is_array( $user ) || empty( $user['username'] ) || empty( $user['email'] ) ) {
            return( $generic_result );
        }

        $username = (string) $user['username'];
        $token = bin2hex( random_bytes( 24 ) );
        $token_hash = hash( 'sha256', $token );
        $expires_at_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::REQUEST_TTL_SECONDS );

        $request_record = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $username, $identifier, $ip_address, $requested_at_utc, $expires_at_utc, $token_hash, $system_settings ) : array {
                $store = $this->normalizeStore( $store );
                $store = $this->pruneStore( $store, $system_settings );
                $store['requests'] = array_values(
                    array_filter(
                        $store['requests'],
                        static function( mixed $request ) use ( $username ) : bool {
                            if ( ! is_array( $request ) ) {
                                return( false );
                            }
                            if ( trim( (string) ( $request['username'] ?? '' ) ) !== $username ) {
                                return( true );
                            }
                            return( trim( (string) ( $request['used_at_utc'] ?? '' ) ) !== '' );
                        }
                    )
                );
                $request = [
                    'id' => 'password_reset_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ),
                    'username' => $username,
                    'identifier' => $identifier,
                    'requested_ip' => $ip_address,
                    'requested_at_utc' => $requested_at_utc,
                    'expires_at_utc' => $expires_at_utc,
                    'used_at_utc' => '',
                    'token_hash' => $token_hash,
                ];
                array_unshift( $store['requests'], $request );
                return(
                    [
                        'data' => $store,
                        'result' => $request,
                    ]
                );
            },
            $this->getDefaultStore()
        );

        $reset_url = rtrim( $reset_base_url, '/' ) . '?username=' . rawurlencode( $username ) . '&token=' . rawurlencode( $token );
        try {
            $mailer->sendPasswordResetEmail( $system_settings, (string) $user['email'], $reset_url, $site_name );
        } catch ( \Throwable $e ) {
            $this->clearActiveRequestForUser( $username );
            error_log( basename( __FILE__ ) . ': Unable to send password-reset e-mail (' . $e->getMessage() . ')' );
        }

        return( $generic_result );
    }

    public function getResetRequest( string $username, string $token ) : ?array {
        $username = $this->normalizeUsername( $username );
        $token_hash = hash( 'sha256', trim( $token ) );
        if ( $username === '' || trim( $token ) === '' ) {
            return( null );
        }

        $store = $this->pruneAndReadStore();
        foreach ( $store['requests'] as $request ) {
            if ( ! is_array( $request ) ) {
                continue;
            }
            if ( trim( (string) ( $request['username'] ?? '' ) ) !== $username ) {
                continue;
            }
            if ( trim( (string) ( $request['used_at_utc'] ?? '' ) ) !== '' ) {
                continue;
            }
            if ( trim( (string) ( $request['token_hash'] ?? '' ) ) !== $token_hash ) {
                continue;
            }
            if ( $this->isExpired( (string) ( $request['expires_at_utc'] ?? '' ) ) ) {
                continue;
            }

            return( $request );
        }

        return( null );
    }

    public function completeReset( string $username, string $token, string $new_password ) : bool {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' || trim( $token ) === '' || $new_password === '' ) {
            return( false );
        }

        $request = $this->getResetRequest( $username, $token );
        if ( ! is_array( $request ) ) {
            return( false );
        }

        if ( ! $this->user_repository->setPasswordForUsername( $username, $new_password ) ) {
            return( false );
        }
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $request, $username ) : array {
                $store = $this->normalizeStore( $store );
                foreach ( $store['requests'] as $index => $candidate_request ) {
                    if ( ! is_array( $candidate_request ) ) {
                        continue;
                    }
                    if ( trim( (string) ( $candidate_request['id'] ?? '' ) ) === trim( (string) ( $request['id'] ?? '' ) ) ) {
                        $store['requests'][$index]['used_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    } elseif ( trim( (string) ( $candidate_request['username'] ?? '' ) ) === $username ) {
                        $store['requests'][$index]['used_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    }
                }
                return( $store );
            },
            $this->getDefaultStore()
        );

        return( true );
    }

    public function pruneExpiredRequests() : array {
        $result = TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) : array {
                $store = $this->normalizeStore( $store );
                $before_attempts = count( $store['attempts'] );
                $before_requests = count( $store['requests'] );
                $store = $this->pruneStore( $store );
                return(
                    [
                        'data' => $store,
                        'result' => [
                            'removed_attempts' => max( 0, $before_attempts - count( $store['attempts'] ) ),
                            'removed_requests' => max( 0, $before_requests - count( $store['requests'] ) ),
                        ],
                    ]
                );
            },
            $this->getDefaultStore()
        );

        return( is_array( $result ) ? $result : [ 'removed_attempts' => 0, 'removed_requests' => 0 ] );
    }

    protected function findResettableUser( string $identifier, array $system_settings ) : ?array {
        $user = null;
        if ( filter_var( $identifier, FILTER_VALIDATE_EMAIL ) !== false ) {
            $user = $this->user_repository->getAuthRecordByEmail( $identifier );
        } else {
            $user = $this->user_repository->getAuthRecordByUsername( $identifier );
        }

        if ( ! is_array( $user ) || empty( $user['username'] ) ) {
            return( null );
        }

        $full_user = $this->user_repository->getUserByUsername( (string) $user['username'] );
        if ( ! is_array( $full_user ) || empty( $full_user['active'] ) || empty( $full_user['email'] ) ) {
            return( null );
        }

        $role = strtolower( trim( (string) ( $full_user['role'] ?? 'creator' ) ) );
        if ( $role === 'superadmin' && empty( $system_settings['allow_admin_password_resets'] ) ) {
            return( null );
        }
        if ( $role !== 'superadmin' && empty( $system_settings['allow_author_password_resets'] ) ) {
            return( null );
        }

        return( $full_user );
    }

    protected function getThrottleState( string $identifier, string $ip_address, array $system_settings = [] ) : array {
        $store = $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->getDefaultStore() ) );
        $store = $this->pruneStore( $store, $system_settings );
        $identifier_count = 0;
        $ip_count = 0;
        $max_identifier_requests = $this->getMaxIdentifierRequests( $system_settings );
        $max_ip_requests = $this->getMaxIpRequests( $system_settings );

        foreach ( $store['attempts'] as $attempt ) {
            if ( ! is_array( $attempt ) ) {
                continue;
            }
            if ( trim( (string) ( $attempt['identifier'] ?? '' ) ) === $identifier ) {
                $identifier_count++;
            }
            if ( $ip_address !== '' && trim( (string) ( $attempt['ip'] ?? '' ) ) === $ip_address ) {
                $ip_count++;
            }
        }

        return(
            [
                'blocked' => $identifier_count >= $max_identifier_requests || $ip_count >= $max_ip_requests,
                'identifier_count' => $identifier_count,
                'ip_count' => $ip_count,
            ]
        );
    }

    protected function recordAttempt( string $identifier, string $ip_address, string $requested_at_utc ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $identifier, $ip_address, $requested_at_utc ) : array {
                $store = $this->normalizeStore( $store );
                $store = $this->pruneStore( $store );
                array_unshift(
                    $store['attempts'],
                    [
                        'identifier' => $identifier,
                        'ip' => $ip_address,
                        'requested_at_utc' => $requested_at_utc,
                    ]
                );
                return( $store );
            },
            $this->getDefaultStore()
        );
    }

    protected function clearActiveRequestForUser( string $username ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $username ) : array {
                $store = $this->normalizeStore( $store );
                $store['requests'] = array_values(
                    array_filter(
                        $store['requests'],
                        static function( mixed $request ) use ( $username ) : bool {
                            if ( ! is_array( $request ) ) {
                                return( false );
                            }
                            return( trim( (string) ( $request['username'] ?? '' ) ) !== $username );
                        }
                    )
                );
                return( $store );
            },
            $this->getDefaultStore()
        );
    }

    protected function pruneAndReadStore() : array {
        $this->pruneExpiredRequests();
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->getDefaultStore() ) ) );
    }

    protected function pruneStore( array $store, array $system_settings = [] ) : array {
        $attempt_cutoff = time() - $this->getThrottleWindowSeconds( $system_settings );
        $store['attempts'] = array_values(
            array_filter(
                is_array( $store['attempts'] ?? null ) ? $store['attempts'] : [],
                static function( mixed $attempt ) use ( $attempt_cutoff ) : bool {
                    if ( ! is_array( $attempt ) ) {
                        return( false );
                    }
                    $timestamp = strtotime( (string) ( $attempt['requested_at_utc'] ?? '' ) );
                    return( is_int( $timestamp ) && $timestamp >= $attempt_cutoff );
                }
            )
        );
        $store['requests'] = array_values(
            array_filter(
                is_array( $store['requests'] ?? null ) ? $store['requests'] : [],
                function( mixed $request ) : bool {
                    if ( ! is_array( $request ) ) {
                        return( false );
                    }
                    if ( trim( (string) ( $request['used_at_utc'] ?? '' ) ) !== '' ) {
                        return( ! $this->isExpired( (string) ( $request['used_at_utc'] ?? '' ), self::THROTTLE_WINDOW_SECONDS ) );
                    }
                    return( ! $this->isExpired( (string) ( $request['expires_at_utc'] ?? '' ) ) );
                }
            )
        );

        return( $store );
    }

    protected function getThrottleWindowSeconds( array $system_settings ) : int {
        $minutes = (int) ( $system_settings['password_reset_throttle_window_minutes'] ?? floor( self::THROTTLE_WINDOW_SECONDS / 60 ) );
        if ( $minutes < 1 || $minutes > 1440 ) {
            $minutes = (int) floor( self::THROTTLE_WINDOW_SECONDS / 60 );
        }

        return( $minutes * 60 );
    }

    protected function getMaxIpRequests( array $system_settings ) : int {
        $count = (int) ( $system_settings['password_reset_max_ip_requests'] ?? self::MAX_IP_REQUESTS );
        if ( $count < 1 || $count > 100 ) {
            return( self::MAX_IP_REQUESTS );
        }

        return( $count );
    }

    protected function getMaxIdentifierRequests( array $system_settings ) : int {
        $count = (int) ( $system_settings['password_reset_max_identifier_requests'] ?? self::MAX_IDENTIFIER_REQUESTS );
        if ( $count < 1 || $count > 100 ) {
            return( self::MAX_IDENTIFIER_REQUESTS );
        }

        return( $count );
    }

    protected function isExpired( string $utc_datetime, int $age_seconds = 0 ) : bool {
        $timestamp = strtotime( $utc_datetime );
        if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
            return( true );
        }

        if ( $age_seconds > 0 ) {
            return( $timestamp < ( time() - $age_seconds ) );
        }

        return( $timestamp < time() );
    }

    protected function getDefaultStore() : array {
        return(
            [
                'format' => 'tinymash-password-resets',
                'format_version' => 1,
                'attempts' => [],
                'requests' => [],
            ]
        );
    }

    protected function normalizeStore( array $store ) : array {
        if ( ! isset( $store['attempts'] ) || ! is_array( $store['attempts'] ) ) {
            $store['attempts'] = [];
        }
        if ( ! isset( $store['requests'] ) || ! is_array( $store['requests'] ) ) {
            $store['requests'] = [];
        }
        $store['format'] = 'tinymash-password-resets';
        $store['format_version'] = 1;
        return( $store );
    }

    protected function normalizeIdentifier( string $identifier ) : string {
        $identifier = strtolower( trim( $identifier ) );
        if ( filter_var( $identifier, FILTER_VALIDATE_EMAIL ) !== false ) {
            return( $identifier );
        }

        return( $this->normalizeUsername( $identifier ) );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }
}
