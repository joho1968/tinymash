<?php
namespace app\classes;

use flight\Engine;
use flight\net\Router;
use app\classes\TinyMashConfig;

class TinyMashSecurity {

    protected const SESSION_USER_KEY = 'tinymash.auth.username';
    protected const SESSION_ROLE_KEY = 'tinymash.auth.role';
    protected const SESSION_CSRF_KEY = 'tinymash.csrf.token';
    protected const SESSION_FLASH_KEY = 'tinymash.flash';
    protected const SESSION_VISITOR_IP_KEY = 'tinymash.visitor.last_ip';
    protected const LOGIN_FAILURE_LOOKBACK_SECONDS = 86400;
    protected const LOGIN_THROTTLE_WINDOW_SECONDS = 900;
    protected const LOGIN_THROTTLE_MAX_IP_FAILURES = 10;
    protected const LOGIN_THROTTLE_MAX_USERNAME_FAILURES = 5;
    protected const LOGIN_RECENT_FAILURE_LIMIT = 8;

    protected Engine $app;
    protected Router $router;
    protected TinyMashConfig $config;
    protected string $login_attempts_filename;
    protected array $last_login_attempt_state = [];

    public function __construct( Engine $app, Router $router, TinyMashConfig $config, string $login_attempts_filename = '' ) {
        $this->app = $app;
        $this->router = $router;
        $this->config = $config;
        $this->login_attempts_filename = $login_attempts_filename;
    }

    public function startSession() : void {
        if ( PHP_SAPI === 'cli' ) {
            return;
        }
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            $this->rememberVisitorIp();
            return;
        }

        $secure_cookie = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on';
        session_name( 'tinymash' );
        session_cache_limiter( '' );
        session_set_cookie_params(
            [
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        session_start();
        $this->rememberVisitorIp();
    }

    public function isLoggedIn() : bool {
        $this->startSession();
        return( ! empty( $_SESSION[self::SESSION_USER_KEY] ) && is_string( $_SESSION[self::SESSION_USER_KEY] ) );
    }

    public function getCurrentUsername() : ?string {
        if ( ! $this->isLoggedIn() ) {
            return( null );
        }

        return( $_SESSION[self::SESSION_USER_KEY] );
    }

    public function getCurrentRole() : string {
        if ( ! $this->isLoggedIn() ) {
            return( 'public' );
        }

        $role = $_SESSION[self::SESSION_ROLE_KEY] ?? '';
        if ( is_string( $role ) && $role !== '' ) {
            return( $role );
        }

        $username = $this->getCurrentUsername();
        if ( ! is_string( $username ) || $username === '' ) {
            return( 'creator' );
        }

        $auth_record = $this->getAuthRecord( $username );
        if ( $auth_record !== null && ! empty( $auth_record['role'] ) ) {
            $_SESSION[self::SESSION_ROLE_KEY] = (string) $auth_record['role'];
            return( (string) $auth_record['role'] );
        }

        return( 'creator' );
    }

    public function isSuperAdmin() : bool {
        return( $this->getCurrentRole() === 'superadmin' );
    }

    public function login( string $username, string $password ) : bool {
        $this->startSession();

        $login_identifier = function_exists( 'mb_trim' ) ? mb_trim( $username ) : trim( $username );
        $username = $this->normalizeUsername( $login_identifier );
        $ip_address = $this->getCurrentIpAddress();
        if ( $login_identifier === '' || ! $this->validatePassword( $password ) ) {
            $this->last_login_attempt_state = [
                'status' => 'invalid',
                'blocked' => false,
                'retry_after_seconds' => 0,
            ];
            return( false );
        }

        $throttle_state = $this->getLoginThrottleState( $username, $ip_address );
        if ( ! empty( $throttle_state['blocked'] ) ) {
            $this->last_login_attempt_state = $throttle_state + [ 'status' => 'throttled' ];
            return( false );
        }

        $auth_record = $this->getAuthRecord( $login_identifier );
        if ( $auth_record === null ) {
            $this->recordFailedLogin( $username, $ip_address );
            $updated_throttle_state = $this->getLoginThrottleState( $username, $ip_address );
            if ( empty( $throttle_state['blocked'] ) && ! empty( $updated_throttle_state['blocked'] ) ) {
                $this->dispatchLockoutNotification( $username, $ip_address, $updated_throttle_state );
            }
            $this->last_login_attempt_state = $updated_throttle_state + [ 'status' => 'invalid' ];
            return( false );
        }

        $username = $this->normalizeUsername( (string) ( $auth_record['username'] ?? $username ) );
        $throttle_state = $this->getLoginThrottleState( $username, $ip_address );
        if ( ! empty( $throttle_state['blocked'] ) ) {
            $this->last_login_attempt_state = $throttle_state + [ 'status' => 'throttled' ];
            return( false );
        }

        if ( ! $this->verifyPasswordHash( $password, $auth_record['hash'] ) ) {
            $this->recordFailedLogin( $username, $ip_address );
            $updated_throttle_state = $this->getLoginThrottleState( $username, $ip_address );
            if ( empty( $throttle_state['blocked'] ) && ! empty( $updated_throttle_state['blocked'] ) ) {
                $this->dispatchLockoutNotification( $username, $ip_address, $updated_throttle_state );
            }
            $this->last_login_attempt_state = $updated_throttle_state + [ 'status' => 'invalid' ];
            return( false );
        }

        $this->clearFailedLogins( $username, $ip_address );
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_regenerate_id( true );
        }
        $_SESSION[self::SESSION_USER_KEY] = $auth_record['username'];
        $_SESSION[self::SESSION_ROLE_KEY] = $auth_record['role'] !== '' ? $auth_record['role'] : 'creator';
        $this->last_login_attempt_state = [
            'status' => 'ok',
            'blocked' => false,
            'retry_after_seconds' => 0,
        ];
        return( true );
    }

    public function getLastLoginAttemptState() : array {
        return( $this->last_login_attempt_state );
    }

    public function getLoginHardeningSummary() : array {
        $store = $this->readLoginAttemptStore();
        $failures = is_array( $store['failures'] ?? null ) ? $store['failures'] : [];
        $now = time();
        $last_hour_cutoff = $now - 3600;
        $last_day_cutoff = $now - self::LOGIN_FAILURE_LOOKBACK_SECONDS;
        $last_failed_at_utc = '';
        $ip_failures = [];
        $username_failures = [];
        $recent_failures = [];
        $failed_last_hour = 0;
        $failed_last_24_hours = 0;

        foreach ( $failures as $failure ) {
            if ( ! is_array( $failure ) ) {
                continue;
            }

            $failure_timestamp = strtotime( (string) ( $failure['failed_at_utc'] ?? '' ) );
            if ( ! is_int( $failure_timestamp ) || $failure_timestamp <= 0 ) {
                continue;
            }
            if ( $failure_timestamp >= $last_hour_cutoff ) {
                $failed_last_hour++;
            }
            if ( $failure_timestamp >= $last_day_cutoff ) {
                $failed_last_24_hours++;
            }

            if ( $last_failed_at_utc === '' || strcmp( (string) $failure['failed_at_utc'], $last_failed_at_utc ) > 0 ) {
                $last_failed_at_utc = (string) $failure['failed_at_utc'];
            }

            $ip = trim( (string) ( $failure['ip'] ?? '' ) );
            $username = $this->normalizeUsername( (string) ( $failure['username'] ?? '' ) );
            if ( $ip !== '' ) {
                $ip_failures[$ip][] = $failure_timestamp;
            }
            if ( $username !== '' ) {
                $username_failures[$username][] = $failure_timestamp;
            }
        }

        usort(
            $failures,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $right['failed_at_utc'] ?? '' ), (string) ( $left['failed_at_utc'] ?? '' ) ) );
            }
        );
        foreach ( array_slice( $failures, 0, self::LOGIN_RECENT_FAILURE_LIMIT ) as $failure ) {
            if ( ! is_array( $failure ) ) {
                continue;
            }
            $recent_failures[] = [
                'username' => $this->normalizeUsername( (string) ( $failure['username'] ?? '' ) ),
                'ip' => trim( (string) ( $failure['ip'] ?? '' ) ),
                'failed_at_utc' => (string) ( $failure['failed_at_utc'] ?? '' ),
            ];
        }

        $throttled_ips = 0;
        foreach ( $ip_failures as $timestamps ) {
            if ( $this->countRecentFailureTimestamps( $timestamps, self::LOGIN_THROTTLE_WINDOW_SECONDS, $now ) >= self::LOGIN_THROTTLE_MAX_IP_FAILURES ) {
                $throttled_ips++;
            }
        }

        $throttled_usernames = 0;
        foreach ( $username_failures as $timestamps ) {
            if ( $this->countRecentFailureTimestamps( $timestamps, self::LOGIN_THROTTLE_WINDOW_SECONDS, $now ) >= self::LOGIN_THROTTLE_MAX_USERNAME_FAILURES ) {
                $throttled_usernames++;
            }
        }

        return(
            [
                'failed_last_hour' => $failed_last_hour,
                'failed_last_24_hours' => $failed_last_24_hours,
                'last_failed_at_utc' => $last_failed_at_utc,
                'throttled_ips' => $throttled_ips,
                'throttled_usernames' => $throttled_usernames,
                'recent_failures' => $recent_failures,
            ]
        );
    }

    public function verifyCredentials( string $username, string $password ) : bool {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' || ! $this->validatePassword( $password ) ) {
            return( false );
        }

        $auth_record = $this->getAuthRecord( $username );
        if ( $auth_record === null ) {
            return( false );
        }

        return( $this->verifyPasswordHash( $password, $auth_record['hash'] ) );
    }

    public function logout() : void {
        $this->startSession();
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            return;
        }

        $_SESSION = [];
        if ( ini_get( 'session.use_cookies' ) ) {
            $params = session_get_cookie_params();
            setcookie( session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly'] );
        }
        session_destroy();
    }

    public function getCsrfToken() : string {
        $this->startSession();
        $csrf_token = $_SESSION[self::SESSION_CSRF_KEY] ?? '';
        if ( ! is_string( $csrf_token ) || $csrf_token === '' ) {
            $csrf_token = bin2hex( random_bytes( 32 ) );
            $_SESSION[self::SESSION_CSRF_KEY] = $csrf_token;
        }

        return( $csrf_token );
    }

    public function closeSession() : void {
        if ( PHP_SAPI === 'cli' ) {
            return;
        }

        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }
    }

    public function validateCsrfToken( ?string $csrf_token ) : bool {
        $this->startSession();
        if ( ! is_string( $csrf_token ) || $csrf_token === '' ) {
            return( false );
        }

        $expected_token = $_SESSION[self::SESSION_CSRF_KEY] ?? '';
        return( is_string( $expected_token ) && $expected_token !== '' && hash_equals( $expected_token, $csrf_token ) );
    }

    public function getCurrentIpAddress() : string {
        $forwarded_ip_mode = method_exists( $this->config, 'getForwardedIpMode' )
            ? $this->config->getForwardedIpMode()
            : 'off';
        if ( $forwarded_ip_mode === 'cf-connecting-ip' ) {
            $cloudflare_ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] )
                ? trim( $_SERVER['HTTP_CF_CONNECTING_IP'] )
                : '';
            if ( filter_var( $cloudflare_ip, FILTER_VALIDATE_IP ) !== false ) {
                return( $cloudflare_ip );
            }
        } elseif ( $forwarded_ip_mode === 'x-forwarded-for-first' && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            foreach ( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) as $forwarded_candidate ) {
                $forwarded_candidate = trim( $forwarded_candidate );
                if ( filter_var( $forwarded_candidate, FILTER_VALIDATE_IP ) !== false ) {
                    return( $forwarded_candidate );
                }
            }
        }

        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '';
        return( filter_var( $remote_addr, FILTER_VALIDATE_IP ) !== false ? $remote_addr : '' );
    }

    public function getLastSeenIpAddress() : string {
        $this->startSession();
        $last_seen_ip = $_SESSION[self::SESSION_VISITOR_IP_KEY] ?? '';
        return( is_string( $last_seen_ip ) ? $last_seen_ip : '' );
    }

    public function getVisitorContext() : array {
        $this->startSession();
        return( $this->buildVisitorContextFromSessionState() );
    }

    public function getPassiveVisitorContext() : array {
        return( $this->buildVisitorContextFromSessionState() );
    }

    public function getRequestVisitorContext() : array {
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            return( $this->buildVisitorContextFromSessionState() );
        }

        if ( ! $this->hasRequestSessionCookie() ) {
            return( $this->buildVisitorContextFromSessionState() );
        }

        $this->startSession();
        return( $this->buildVisitorContextFromSessionState() );
    }

    public function isRequestAuthenticated() : bool {
        return( ! empty( $this->getRequestVisitorContext()['logged_in'] ) );
    }

    protected function buildVisitorContextFromSessionState() : array {
        $logged_in = false;
        $username = null;
        $role = 'public';
        $last_seen_ip = '';

        if ( session_status() === PHP_SESSION_ACTIVE ) {
            $username_value = $_SESSION[self::SESSION_USER_KEY] ?? null;
            if ( is_string( $username_value ) && $username_value !== '' ) {
                $logged_in = true;
                $username = $username_value;
            }

            $role_value = $_SESSION[self::SESSION_ROLE_KEY] ?? '';
            if ( is_string( $role_value ) && $role_value !== '' ) {
                $role = $role_value;
            } elseif ( $logged_in ) {
                $role = 'creator';
            }

            $last_seen_ip_value = $_SESSION[self::SESSION_VISITOR_IP_KEY] ?? '';
            if ( is_string( $last_seen_ip_value ) ) {
                $last_seen_ip = $last_seen_ip_value;
            }
        }

        return(
            [
                'logged_in' => $logged_in,
                'username' => $username,
                'role' => $role,
                'current_ip' => $this->getCurrentIpAddress(),
                'last_seen_ip' => $last_seen_ip,
            ]
        );
    }

    protected function hasRequestSessionCookie() : bool {
        if ( PHP_SAPI === 'cli' ) {
            return( false );
        }

        $session_cookie_name = session_name();
        if ( ! is_string( $session_cookie_name ) || $session_cookie_name === '' || $session_cookie_name === 'PHPSESSID' ) {
            $session_cookie_name = 'tinymash';
        }

        return( isset( $_COOKIE[$session_cookie_name] ) && is_string( $_COOKIE[$session_cookie_name] ) && trim( $_COOKIE[$session_cookie_name] ) !== '' );
    }

    public function setFlash( string $key, mixed $value ) : void {
        $this->startSession();
        if ( empty( $_SESSION[self::SESSION_FLASH_KEY] ) || ! is_array( $_SESSION[self::SESSION_FLASH_KEY] ) ) {
            $_SESSION[self::SESSION_FLASH_KEY] = [];
        }

        $_SESSION[self::SESSION_FLASH_KEY][$key] = $value;
    }

    public function pullFlash( string $key, mixed $default = null ) : mixed {
        $this->startSession();
        if ( empty( $_SESSION[self::SESSION_FLASH_KEY] ) || ! is_array( $_SESSION[self::SESSION_FLASH_KEY] ) ) {
            return( $default );
        }

        if ( ! array_key_exists( $key, $_SESSION[self::SESSION_FLASH_KEY] ) ) {
            return( $default );
        }

        $value = $_SESSION[self::SESSION_FLASH_KEY][$key];
        unset( $_SESSION[self::SESSION_FLASH_KEY][$key] );

        return( $value );
    }

    /**
     * Checks password by comparing input with result of mb_trim/trim.
     * Conditionally use mb_trim(), PHP >= 8.4.0
     *
     * @param string $password
     * @return bool
     */
    public function validatePassword( string $password ) : bool {
        if ( empty( $password ) ) {
            return( false );
        }
        if ( function_exists( 'mb_trim' ) ) {
            $filtered_password = mb_trim( $password );
        } else {
            $filtered_password = trim( $password );
        }
        if ( $filtered_password != $password ) {
            return( false );
        }
        return( true );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( function_exists( 'mb_trim' ) ? mb_trim( $username ) : trim( $username ) );
        if ( $username === '' ) {
            return( '' );
        }
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }

    protected function getAuthRecord( string $username ) : ?array {
        if ( ! $this->app->has( 'user.repository' ) ) {
            return( null );
        }

        $user_repository = $this->app->get( 'user.repository' );
        if ( ! is_object( $user_repository ) || ! method_exists( $user_repository, 'getAuthRecordByUsername' ) ) {
            return( null );
        }

        $normalized_username = $this->normalizeUsername( $username );
        $user = $normalized_username !== '' ? $user_repository->getAuthRecordByUsername( $normalized_username ) : null;
        if ( ! is_array( $user ) && method_exists( $user_repository, 'getAuthRecordByEmail' ) ) {
            $user = $user_repository->getAuthRecordByEmail( $username );
        }
        if ( ! is_array( $user ) || empty( $user['password_hash'] ) || ! is_string( $user['password_hash'] ) ) {
            return( null );
        }
        if ( array_key_exists( 'active', $user ) && empty( $user['active'] ) ) {
            return( null );
        }

        return(
            [
                'username' => (string) ( $user['username'] ?? '' ),
                'hash' => (string) $user['password_hash'],
                'role' => ! empty( $user['role'] ) ? (string) $user['role'] : '',
            ]
        );
    }

    protected function verifyPasswordHash( string $password, string $hash ) : bool {
        if ( $hash === '' ) {
            return( false );
        }

        if ( password_verify( $password, $hash ) ) {
            return( true );
        }

        $verified_hash = crypt( $password, $hash );
        return( is_string( $verified_hash ) && hash_equals( $hash, $verified_hash ) );
    }

    protected function rememberVisitorIp() : void {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            return;
        }

        $current_ip = $this->getCurrentIpAddress();
        if ( $current_ip === '' ) {
            return;
        }

        $_SESSION[self::SESSION_VISITOR_IP_KEY] = $current_ip;
    }

    protected function dispatchLockoutNotification( string $username, string $ip_address, array $throttle_state ) : void {
        if ( ! $this->app->has( 'notification.service' ) ) {
            return;
        }

        $notification_service = $this->app->get( 'notification.service' );
        if ( ! $notification_service instanceof TinyMashNotificationService ) {
            return;
        }

        $retry_after_seconds = max( 0, (int) ( $throttle_state['retry_after_seconds'] ?? 0 ) );
        $message = 'A login target was locked out';
        if ( $username !== '' ) {
            $message .= ' for ' . $username;
        }
        if ( $ip_address !== '' ) {
            $message .= ' from ' . $ip_address;
        }
        if ( $retry_after_seconds > 0 ) {
            $message .= '. Retry after approximately ' . (int) ceil( $retry_after_seconds / 60 ) . ' minute(s).';
        } else {
            $message .= '.';
        }

        try {
            $notification = $notification_service->createNotification(
                'user_lockout',
                'User was locked out',
                $message,
                [
                    'severity' => 'danger',
                    'target_username' => $username,
                    'dedupe_key' => 'user-lockout:' . $username . ':' . $ip_address,
                    'dedupe_window_seconds' => self::LOGIN_THROTTLE_WINDOW_SECONDS,
                    'context' => [
                        'username' => $username,
                        'ip_address' => $ip_address,
                        'retry_after_seconds' => $retry_after_seconds,
                    ],
                ]
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to queue lockout notification (' . $e->getMessage() . ')' );
            return;
        }

        $system_settings = $this->config->getSystemSettings();
        if ( ! $notification_service->shouldSendEmailForType( 'user_lockout', $system_settings ) ) {
            return;
        }

        if ( ! $this->app->has( 'mailer' ) ) {
            return;
        }

        $mailer = $this->app->get( 'mailer' );
        if ( ! $mailer instanceof TinyMashMailer ) {
            return;
        }

        try {
            $notification_service->sendNotificationEmail(
                'user_lockout',
                '[' . $this->config->getSiteName() . '] User was locked out',
                $message,
                $mailer,
                $system_settings,
                $this->config->getSiteName()
            );
        } catch ( \Throwable $e ) {
            error_log( basename( __FILE__ ) . ': Unable to send lockout notification e-mail (' . $e->getMessage() . ')' );
        }
    }

    protected function getLoginThrottleState( string $username, string $ip_address ) : array {
        $store = $this->readLoginAttemptStore();
        $failures = is_array( $store['failures'] ?? null ) ? $store['failures'] : [];
        $now = time();
        $window_cutoff = $now - self::LOGIN_THROTTLE_WINDOW_SECONDS;
        $recent_ip_timestamps = [];
        $recent_username_timestamps = [];

        foreach ( $failures as $failure ) {
            if ( ! is_array( $failure ) ) {
                continue;
            }

            $failure_timestamp = strtotime( (string) ( $failure['failed_at_utc'] ?? '' ) );
            if ( ! is_int( $failure_timestamp ) || $failure_timestamp < $window_cutoff ) {
                continue;
            }

            if ( $ip_address !== '' && trim( (string) ( $failure['ip'] ?? '' ) ) === $ip_address ) {
                $recent_ip_timestamps[] = $failure_timestamp;
            }
            if ( $username !== '' && $this->normalizeUsername( (string) ( $failure['username'] ?? '' ) ) === $username ) {
                $recent_username_timestamps[] = $failure_timestamp;
            }
        }

        sort( $recent_ip_timestamps );
        sort( $recent_username_timestamps );

        $retry_after_seconds = 0;
        if ( count( $recent_ip_timestamps ) >= self::LOGIN_THROTTLE_MAX_IP_FAILURES ) {
            $threshold_timestamp = $recent_ip_timestamps[count( $recent_ip_timestamps ) - self::LOGIN_THROTTLE_MAX_IP_FAILURES];
            $retry_after_seconds = max( $retry_after_seconds, ( $threshold_timestamp + self::LOGIN_THROTTLE_WINDOW_SECONDS ) - $now );
        }
        if ( count( $recent_username_timestamps ) >= self::LOGIN_THROTTLE_MAX_USERNAME_FAILURES ) {
            $threshold_timestamp = $recent_username_timestamps[count( $recent_username_timestamps ) - self::LOGIN_THROTTLE_MAX_USERNAME_FAILURES];
            $retry_after_seconds = max( $retry_after_seconds, ( $threshold_timestamp + self::LOGIN_THROTTLE_WINDOW_SECONDS ) - $now );
        }

        return(
            [
                'blocked' => $retry_after_seconds > 0,
                'retry_after_seconds' => max( 0, $retry_after_seconds ),
                'recent_ip_failures' => count( $recent_ip_timestamps ),
                'recent_username_failures' => count( $recent_username_timestamps ),
            ]
        );
    }

    protected function recordFailedLogin( string $username, string $ip_address ) : void {
        if ( $this->login_attempts_filename === '' ) {
            return;
        }

        $this->withLockedLoginAttemptStore(
            function( array $store ) use ( $username, $ip_address ) : array {
                $failures = is_array( $store['failures'] ?? null ) ? $store['failures'] : [];
                $failures[] = [
                    'username' => $username,
                    'ip' => $ip_address,
                    'failed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                ];

                $store['failures'] = $this->pruneFailedLogins( $failures );
                return( $store );
            }
        );
    }

    protected function clearFailedLogins( string $username, string $ip_address ) : void {
        if ( $this->login_attempts_filename === '' ) {
            return;
        }

        $this->withLockedLoginAttemptStore(
            function( array $store ) use ( $username, $ip_address ) : array {
                $failures = is_array( $store['failures'] ?? null ) ? $store['failures'] : [];
                $remaining_failures = [];

                foreach ( $failures as $failure ) {
                    if ( ! is_array( $failure ) ) {
                        continue;
                    }

                    $failure_username = $this->normalizeUsername( (string) ( $failure['username'] ?? '' ) );
                    $failure_ip = trim( (string) ( $failure['ip'] ?? '' ) );
                    if ( ( $username !== '' && $failure_username === $username ) || ( $ip_address !== '' && $failure_ip === $ip_address ) ) {
                        continue;
                    }

                    $remaining_failures[] = $failure;
                }

                $store['failures'] = $this->pruneFailedLogins( $remaining_failures );
                return( $store );
            }
        );
    }

    protected function readLoginAttemptStore() : array {
        if ( $this->login_attempts_filename === '' || ! is_file( $this->login_attempts_filename ) || ! is_readable( $this->login_attempts_filename ) ) {
            return( [ 'failures' => [] ] );
        }

        $handle = @ fopen( $this->login_attempts_filename, 'rb' );
        if ( $handle === false ) {
            return( [ 'failures' => [] ] );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( [ 'failures' => [] ] );
            }
            $json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        return( $this->decodeLoginAttemptStore( is_string( $json ) ? $json : '' ) );
    }

    protected function writeLoginAttemptStore( array $store ) : void {
        if ( $this->login_attempts_filename === '' ) {
            return;
        }

        $directory = dirname( $this->login_attempts_filename );
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }

        $encoded_store = json_encode(
            [
                'failures' => $this->pruneFailedLogins( is_array( $store['failures'] ?? null ) ? $store['failures'] : [] ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ( ! is_string( $encoded_store ) || $encoded_store === '' ) {
            return;
        }

        $handle = @ fopen( $this->login_attempts_filename, 'c+' );
        if ( $handle === false ) {
            return;
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                return;
            }
            ftruncate( $handle, 0 );
            rewind( $handle );
            fwrite( $handle, $encoded_store . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function withLockedLoginAttemptStore( callable $callback ) : void {
        if ( $this->login_attempts_filename === '' ) {
            return;
        }

        $directory = dirname( $this->login_attempts_filename );
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }

        $handle = @ fopen( $this->login_attempts_filename, 'c+' );
        if ( $handle === false ) {
            return;
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                return;
            }

            $json = stream_get_contents( $handle );
            $store = $this->decodeLoginAttemptStore( is_string( $json ) ? $json : '' );
            $updated_store = $callback( $store );
            if ( ! is_array( $updated_store ) ) {
                $updated_store = $store;
            }

            $encoded_store = json_encode(
                [
                    'failures' => $this->pruneFailedLogins( is_array( $updated_store['failures'] ?? null ) ? $updated_store['failures'] : [] ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            if ( ! is_string( $encoded_store ) || $encoded_store === '' ) {
                return;
            }

            ftruncate( $handle, 0 );
            rewind( $handle );
            fwrite( $handle, $encoded_store . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function decodeLoginAttemptStore( string $json ) : array {
        if ( trim( $json ) === '' ) {
            return( [ 'failures' => [] ] );
        }

        $store = json_decode( $json, true, 16 );
        if ( ! is_array( $store ) ) {
            return( [ 'failures' => [] ] );
        }

        $store['failures'] = $this->pruneFailedLogins( is_array( $store['failures'] ?? null ) ? $store['failures'] : [] );
        return( $store );
    }

    protected function pruneFailedLogins( array $failures ) : array {
        $cutoff = time() - self::LOGIN_FAILURE_LOOKBACK_SECONDS;
        $pruned = [];

        foreach ( $failures as $failure ) {
            if ( ! is_array( $failure ) ) {
                continue;
            }

            $failure_timestamp = strtotime( (string) ( $failure['failed_at_utc'] ?? '' ) );
            if ( ! is_int( $failure_timestamp ) || $failure_timestamp < $cutoff ) {
                continue;
            }

            $pruned[] = [
                'username' => $this->normalizeUsername( (string) ( $failure['username'] ?? '' ) ),
                'ip' => trim( (string) ( $failure['ip'] ?? '' ) ),
                'failed_at_utc' => (string) ( $failure['failed_at_utc'] ?? '' ),
            ];
        }

        usort(
            $pruned,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['failed_at_utc'] ?? '' ), (string) ( $right['failed_at_utc'] ?? '' ) ) );
            }
        );

        return( $pruned );
    }

    protected function countRecentFailureTimestamps( array $timestamps, int $window_seconds, int $now ) : int {
        $window_cutoff = $now - $window_seconds;
        $count = 0;
        foreach ( $timestamps as $timestamp ) {
            if ( ! is_int( $timestamp ) || $timestamp < $window_cutoff ) {
                continue;
            }
            $count++;
        }

        return( $count );
    }

}// AdminController
