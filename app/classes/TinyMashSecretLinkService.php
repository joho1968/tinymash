<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashLockedJsonFile.php';

class TinyMashSecretLinkService {

    public const COOKIE_NAME = 'tinymashSecretLinks';
    protected const DEFAULT_EXPIRY_DAYS = 60;
    protected const MAX_EXPIRY_DAYS = 365;

    protected TinyMashConfig $config;
    protected string $store_filename;

    public function __construct( TinyMashConfig $config, string $store_filename ) {
        $this->config = $config;
        $this->store_filename = $store_filename;
    }

    public function isEnabled() : bool {
        return( $this->config->allowsSecretLinks() );
    }

    public function getDefaultExpiryDays() : int {
        return( $this->normalizeExpiryDays( $this->config->getSecretLinkDefaultExpiryDays() ) );
    }

    public function getAuthorSecretLink( string $author_slug ) : ?array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $store = $this->readStore();
        return( $this->normalizeRecord( $store['authors'][$author_slug] ?? null, $author_slug ) );
    }

    public function generateAuthorSecretLink( string $author_slug, string $created_by_username = '', ?int $expiry_days = null ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        $created_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $effective_expiry_days = $this->normalizeExpiryDays( $expiry_days ?? $this->getDefaultExpiryDays() );
        $expires_at_utc = gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $effective_expiry_days * 86400 ) );
        $created_by_username = $this->normalizeAuthorSlug( $created_by_username );

        try {
            $record = TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $author_slug, $created_at_utc, $expires_at_utc, $created_by_username ) : array {
                    $store = $this->normalizeStore( $store );
                    $record = [
                        'author_slug' => $author_slug,
                        'token' => $this->generateUniqueToken( $store ),
                        'created_at_utc' => $created_at_utc,
                        'expires_at_utc' => $expires_at_utc,
                        'created_by_username' => $created_by_username,
                    ];
                    $store['authors'][$author_slug] = $record;
                    return( [ 'data' => $store, 'result' => $record ] );
                },
                [ 'authors' => [] ]
            );
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'Unable to save secret link.', 0, $e );
        }
        if ( ! is_array( $record ) ) {
            throw new \RuntimeException( 'Unable to save secret link.' );
        }

        return( $record );
    }

    public function revokeAuthorSecretLink( string $author_slug ) : bool {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            return( false );
        }

        try {
            return(
                TinyMashLockedJsonFile::mutate(
                    $this->store_filename,
                    function( array $store ) use ( $author_slug ) : array {
                        $store = $this->normalizeStore( $store );
                        unset( $store['authors'][$author_slug] );
                        return( [ 'data' => $store, 'result' => true ] );
                    },
                    [ 'authors' => [] ]
                ) === true
            );
        } catch ( \Throwable ) {
            return( false );
        }
    }

    public function resolveToken( string $token ) : ?array {
        if ( ! $this->isEnabled() ) {
            return( null );
        }

        $token = $this->normalizeToken( $token );
        if ( $token === '' ) {
            return( null );
        }

        $store = $this->readStore();
        foreach ( (array) ( $store['authors'] ?? [] ) as $author_slug => $candidate ) {
            $record = $this->normalizeRecord( $candidate, is_string( $author_slug ) ? $author_slug : '' );
            if ( ! is_array( $record ) ) {
                continue;
            }
            if ( ! hash_equals( $record['token'], $token ) ) {
                continue;
            }
            if ( $this->isExpired( (string) $record['expires_at_utc'] ) ) {
                return( null );
            }

            return( $record );
        }

        return( null );
    }

    public function grantCurrentVisitorToken( string $token ) : ?array {
        $record = $this->resolveToken( $token );
        $valid_records = $this->getCurrentVisitorSecretLinkRecords();
        if ( ! is_array( $record ) ) {
            $this->persistCookieRecords( $valid_records );
            return( null );
        }

        $valid_records[$record['token']] = $record;
        $this->persistCookieRecords( $valid_records );
        return( $record );
    }

    public function currentVisitorCanAccessAuthor( string $author_slug ) : bool {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' || ! $this->isEnabled() ) {
            return( false );
        }

        foreach ( $this->getCurrentVisitorSecretLinkRecords() as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            if ( ( $record['author_slug'] ?? '' ) === $author_slug ) {
                return( true );
            }
        }

        return( false );
    }

    public function buildSecretLinkUrl( array $record, string $app_url ) : string {
        $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
        $app_url = rtrim( trim( $app_url ), '/' );
        if ( $token === '' ) {
            return( '' );
        }

        return( $app_url . '/s/' . rawurlencode( $token ) );
    }

    protected function getCurrentVisitorSecretLinkRecords() : array {
        $records = [];
        $parsed_tokens = $this->parseCookieTokens();
        foreach ( $parsed_tokens as $token ) {
            $record = $this->resolveToken( $token );
            if ( ! is_array( $record ) ) {
                continue;
            }
            $records[$record['token']] = $record;
        }

        if ( count( $records ) !== count( $parsed_tokens ) ) {
            $this->persistCookieRecords( $records );
        }

        return( $records );
    }

    protected function parseCookieTokens() : array {
        $cookie_value = isset( $_COOKIE[self::COOKIE_NAME] ) ? (string) $_COOKIE[self::COOKIE_NAME] : '';
        if ( $cookie_value === '' ) {
            return( [] );
        }

        $tokens = [];
        foreach ( explode( ',', $cookie_value ) as $token ) {
            $normalized_token = $this->normalizeToken( $token );
            if ( $normalized_token === '' ) {
                continue;
            }
            $tokens[$normalized_token] = true;
        }

        return( array_keys( $tokens ) );
    }

    protected function persistCookieRecords( array $records ) : void {
        $tokens = [];
        $expires_at_timestamp = 0;
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
            if ( $token === '' ) {
                continue;
            }
            $tokens[$token] = true;
            $record_expiry = strtotime( (string) ( $record['expires_at_utc'] ?? '' ) );
            if ( is_int( $record_expiry ) && $record_expiry > $expires_at_timestamp ) {
                $expires_at_timestamp = $record_expiry;
            }
        }

        $secure_cookie = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on';
        if ( empty( $tokens ) ) {
            setcookie(
                self::COOKIE_NAME,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => $secure_cookie,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
            unset( $_COOKIE[self::COOKIE_NAME] );
            return;
        }

        setcookie(
            self::COOKIE_NAME,
            implode( ',', array_keys( $tokens ) ),
            [
                'expires' => $expires_at_timestamp > 0 ? $expires_at_timestamp : ( time() + ( 60 * 86400 ) ),
                'path' => '/',
                'secure' => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[self::COOKIE_NAME] = implode( ',', array_keys( $tokens ) );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, [ 'authors' => [] ] ) ) );
    }

    protected function normalizeStore( array $store ) : array {
        $authors = [];
        foreach ( (array) ( $store['authors'] ?? [] ) as $author_slug => $record ) {
            $normalized_record = $this->normalizeRecord( $record, is_string( $author_slug ) ? $author_slug : '' );
            if ( ! is_array( $normalized_record ) ) {
                continue;
            }
            $authors[$normalized_record['author_slug']] = $normalized_record;
        }

        return( [ 'authors' => $authors ] );
    }

    protected function normalizeRecord( mixed $record, string $fallback_author_slug = '' ) : ?array {
        if ( ! is_array( $record ) ) {
            return( null );
        }

        $author_slug = $this->normalizeAuthorSlug( (string) ( $record['author_slug'] ?? $fallback_author_slug ) );
        $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
        $created_at_utc = trim( (string) ( $record['created_at_utc'] ?? '' ) );
        $expires_at_utc = trim( (string) ( $record['expires_at_utc'] ?? '' ) );
        $created_by_username = $this->normalizeAuthorSlug( (string) ( $record['created_by_username'] ?? '' ) );
        if ( $author_slug === '' || $token === '' || $created_at_utc === '' || $expires_at_utc === '' ) {
            return( null );
        }

        return(
            [
                'author_slug' => $author_slug,
                'token' => $token,
                'created_at_utc' => $created_at_utc,
                'expires_at_utc' => $expires_at_utc,
                'created_by_username' => $created_by_username,
            ]
        );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        return( preg_replace( '/[^a-z0-9_-]/', '', $author_slug ) ?? '' );
    }

    protected function normalizeToken( string $token ) : string {
        $token = trim( $token );
        return( preg_replace( '/[^A-Za-z0-9_-]/', '', $token ) ?? '' );
    }

    protected function normalizeExpiryDays( mixed $expiry_days ) : int {
        $expiry_days = (int) $expiry_days;
        if ( $expiry_days < 1 ) {
            return( self::DEFAULT_EXPIRY_DAYS );
        }
        if ( $expiry_days > self::MAX_EXPIRY_DAYS ) {
            return( self::MAX_EXPIRY_DAYS );
        }

        return( $expiry_days );
    }

    protected function generateUniqueToken( array $store ) : string {
        $existing_tokens = [];
        foreach ( (array) ( $store['authors'] ?? [] ) as $record ) {
            if ( ! is_array( $record ) || empty( $record['token'] ) ) {
                continue;
            }
            $existing_tokens[(string) $record['token']] = true;
        }

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $token = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
            if ( ! isset( $existing_tokens[$token] ) ) {
                return( $token );
            }
        }

        throw new \RuntimeException( 'Unable to allocate a unique secret-link token.' );
    }

    protected function isExpired( string $expires_at_utc ) : bool {
        $timestamp = strtotime( $expires_at_utc );
        if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
            return( true );
        }

        return( $timestamp < time() );
    }
}
