<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashLockedJsonFile.php';

class TinyMashSecretLinkService {

    public const COOKIE_NAME = 'tinymashSecretLinks';
    public const SCOPE_SITE = 'site';
    public const SCOPE_AUTHOR = 'author';
    protected const MAX_EXPIRY_DAYS = 365;

    protected TinyMashConfig $config;
    protected string $store_filename;
    protected ?TinyMashUrlResolver $url_resolver;

    public function __construct( TinyMashConfig $config, string $store_filename, ?TinyMashUrlResolver $url_resolver = null ) {
        $this->config = $config;
        $this->store_filename = $store_filename;
        $this->url_resolver = $url_resolver;
    }

    public function isEnabled() : bool {
        return( $this->config->allowsSecretLinks() );
    }

    public function getDefaultExpiryDays() : int {
        return( $this->normalizeExpiryDays( $this->config->getSecretLinkDefaultExpiryDays() ) );
    }

    public function getSiteSecretLink() : ?array {
        $record = $this->normalizeRecord( $this->readStore()['site'] ?? null, self::SCOPE_SITE );
        return( is_array( $record ) && ! $this->isExpired( $record['expires_at_utc'] ) ? $record : null );
    }

    public function getAuthorSecretLink( string $author_slug ) : ?array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            return( null );
        }

        $record = $this->normalizeRecord( $this->readStore()['authors'][$author_slug] ?? null, self::SCOPE_AUTHOR, $author_slug );
        return( is_array( $record ) && ! $this->isExpired( $record['expires_at_utc'] ) ? $record : null );
    }

    public function getAllSecretLinks() : array {
        $store = $this->readStore();
        $records = [];
        $site_record = $this->normalizeRecord( $store['site'] ?? null, self::SCOPE_SITE );
        if ( is_array( $site_record ) && ! $this->isExpired( $site_record['expires_at_utc'] ) ) {
            $records[] = $site_record;
        }
        foreach ( (array) ( $store['authors'] ?? [] ) as $author_slug => $candidate ) {
            $record = $this->normalizeRecord( $candidate, self::SCOPE_AUTHOR, is_string( $author_slug ) ? $author_slug : '' );
            if ( is_array( $record ) && ! $this->isExpired( $record['expires_at_utc'] ) ) {
                $records[] = $record;
            }
        }

        return( $records );
    }

    public function generateSiteSecretLink( string $created_by_username = '', ?int $expiry_days = null ) : array {
        return( $this->generateSecretLink( self::SCOPE_SITE, '', $created_by_username, $expiry_days ) );
    }

    public function generateAuthorSecretLink( string $author_slug, string $created_by_username = '', ?int $expiry_days = null ) : array {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' ) {
            throw new \InvalidArgumentException( 'author_slug' );
        }

        return( $this->generateSecretLink( self::SCOPE_AUTHOR, $author_slug, $created_by_username, $expiry_days ) );
    }

    public function revokeSiteSecretLink() : bool {
        try {
            return(
                TinyMashLockedJsonFile::mutate(
                    $this->store_filename,
                    function( array $store ) : array {
                        $store = $this->normalizeStore( $store );
                        $store['site'] = null;
                        return( [ 'data' => $store, 'result' => true ] );
                    },
                    $this->getEmptyStore()
                ) === true
            );
        } catch ( \Throwable ) {
            return( false );
        }
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
                    $this->getEmptyStore()
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

        foreach ( $this->getAllSecretLinks() as $record ) {
            if ( hash_equals( (string) $record['token'], $token ) ) {
                return( $record );
            }
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

    public function currentVisitorCanAccessSite() : bool {
        if ( ! $this->isEnabled() ) {
            return( false );
        }

        foreach ( $this->getCurrentVisitorSecretLinkRecords() as $record ) {
            if ( ( $record['scope'] ?? '' ) === self::SCOPE_SITE ) {
                return( true );
            }
        }

        return( false );
    }

    public function currentVisitorCanAccessAuthor( string $author_slug ) : bool {
        $author_slug = $this->normalizeAuthorSlug( $author_slug );
        if ( $author_slug === '' || ! $this->isEnabled() ) {
            return( false );
        }

        foreach ( $this->getCurrentVisitorSecretLinkRecords() as $record ) {
            if ( ( $record['scope'] ?? '' ) === self::SCOPE_SITE ) {
                return( true );
            }
            if ( ( $record['scope'] ?? '' ) === self::SCOPE_AUTHOR && ( $record['author_slug'] ?? '' ) === $author_slug ) {
                return( true );
            }
        }

        return( false );
    }

    public function currentVisitorHasSecretLinkAccess() : bool {
        return( $this->isEnabled() && $this->getCurrentVisitorSecretLinkRecords() !== [] );
    }

    public function buildSecretLinkUrl( array $record, string $app_url ) : string {
        $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
        $app_url = rtrim( trim( $app_url ), '/' );
        if ( $token === '' ) {
            return( '' );
        }

        return( $app_url . '/s/' . rawurlencode( $token ) );
    }

    protected function generateSecretLink( string $scope, string $author_slug, string $created_by_username, ?int $expiry_days ) : array {
        if ( ! in_array( $scope, [ self::SCOPE_SITE, self::SCOPE_AUTHOR ], true ) ) {
            throw new \InvalidArgumentException( 'scope' );
        }

        $effective_expiry_days = $this->normalizeExpiryDays( $expiry_days ?? $this->getDefaultExpiryDays() );
        $created_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $expires_at_utc = $effective_expiry_days === 0 ? null : gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $effective_expiry_days * 86400 ) );
        $created_by_username = $this->normalizeAuthorSlug( $created_by_username );

        try {
            $record = TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $scope, $author_slug, $created_at_utc, $expires_at_utc, $created_by_username ) : array {
                    $store = $this->normalizeStore( $store );
                    $record = [
                        'scope' => $scope,
                        'author_slug' => $author_slug,
                        'token' => $this->generateUniqueToken( $store ),
                        'created_at_utc' => $created_at_utc,
                        'expires_at_utc' => $expires_at_utc,
                        'created_by_username' => $created_by_username,
                    ];
                    if ( $scope === self::SCOPE_SITE ) {
                        $store['site'] = $record;
                    } else {
                        $store['authors'][$author_slug] = $record;
                    }
                    return( [ 'data' => $store, 'result' => $record ] );
                },
                $this->getEmptyStore()
            );
        } catch ( \InvalidArgumentException $e ) {
            throw $e;
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'Unable to save secret link.', 0, $e );
        }
        if ( ! is_array( $record ) ) {
            throw new \RuntimeException( 'Unable to save secret link.' );
        }

        return( $record );
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
            if ( $normalized_token !== '' ) {
                $tokens[$normalized_token] = true;
            }
        }

        return( array_keys( $tokens ) );
    }

    protected function persistCookieRecords( array $records ) : void {
        $tokens = [];
        $expires_at_timestamp = 0;
        $has_indefinite_record = false;
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
            if ( $token === '' ) {
                continue;
            }
            $tokens[$token] = true;
            if ( array_key_exists( 'expires_at_utc', $record ) && $record['expires_at_utc'] === null ) {
                $has_indefinite_record = true;
                continue;
            }
            $record_expiry = $this->getUtcTimestamp( $record['expires_at_utc'] ?? null );
            if ( is_int( $record_expiry ) && $record_expiry > $expires_at_timestamp ) {
                $expires_at_timestamp = $record_expiry;
            }
        }

        $secure_cookie = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on';
        $cookie_path = $this->url_resolver instanceof TinyMashUrlResolver && $this->url_resolver->getBasePath() !== ''
            ? $this->url_resolver->getBasePath()
            : '/';
        if ( empty( $tokens ) ) {
            setcookie(
                self::COOKIE_NAME,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => $cookie_path,
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
                'expires' => $has_indefinite_record ? 0 : $expires_at_timestamp,
                'path' => $cookie_path,
                'secure' => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[self::COOKIE_NAME] = implode( ',', array_keys( $tokens ) );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->getEmptyStore() ) ) );
    }

    protected function normalizeStore( array $store ) : array {
        $site = $this->normalizeRecord( $store['site'] ?? null, self::SCOPE_SITE );
        $authors = [];
        foreach ( (array) ( $store['authors'] ?? [] ) as $author_slug => $record ) {
            $normalized_record = $this->normalizeRecord( $record, self::SCOPE_AUTHOR, is_string( $author_slug ) ? $author_slug : '' );
            if ( is_array( $normalized_record ) ) {
                $authors[$normalized_record['author_slug']] = $normalized_record;
            }
        }

        return( [ 'site' => $site, 'authors' => $authors ] );
    }

    protected function normalizeRecord( mixed $record, string $fallback_scope = self::SCOPE_AUTHOR, string $fallback_author_slug = '' ) : ?array {
        if ( ! is_array( $record ) ) {
            return( null );
        }

        $scope = strtolower( trim( (string) ( $record['scope'] ?? $fallback_scope ) ) );
        if ( ! in_array( $scope, [ self::SCOPE_SITE, self::SCOPE_AUTHOR ], true ) ) {
            return( null );
        }
        $author_slug = $scope === self::SCOPE_AUTHOR
            ? $this->normalizeAuthorSlug( (string) ( $record['author_slug'] ?? $fallback_author_slug ) )
            : '';
        $token = $this->normalizeToken( (string) ( $record['token'] ?? '' ) );
        $created_at_utc = $this->normalizeUtcDateTime( $record['created_at_utc'] ?? null );
        $created_by_username = $this->normalizeAuthorSlug( (string) ( $record['created_by_username'] ?? '' ) );
        if ( $scope === self::SCOPE_AUTHOR && $author_slug === '' ) {
            return( null );
        }
        if ( $token === '' || $created_at_utc === '' || ! array_key_exists( 'expires_at_utc', $record ) ) {
            return( null );
        }

        $expires_at_utc = null;
        if ( $record['expires_at_utc'] !== null ) {
            $expires_at_utc = $this->normalizeUtcDateTime( $record['expires_at_utc'] );
            if ( $expires_at_utc === '' ) {
                return( null );
            }
        }

        return(
            [
                'scope' => $scope,
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
        return( preg_match( '/^[A-Za-z0-9_-]{24}$/D', $token ) === 1 ? $token : '' );
    }

    protected function normalizeExpiryDays( mixed $expiry_days ) : int {
        $expiry_days = (int) $expiry_days;
        if ( $expiry_days < 0 || $expiry_days > self::MAX_EXPIRY_DAYS ) {
            throw new \InvalidArgumentException( 'expiry_days' );
        }

        return( $expiry_days );
    }

    protected function generateUniqueToken( array $store ) : string {
        $existing_tokens = [];
        $records = array_merge( [ $store['site'] ?? null ], (array) ( $store['authors'] ?? [] ) );
        foreach ( $records as $record ) {
            if ( is_array( $record ) && ! empty( $record['token'] ) ) {
                $existing_tokens[(string) $record['token']] = true;
            }
        }

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $token = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
            if ( ! isset( $existing_tokens[$token] ) ) {
                return( $token );
            }
        }

        throw new \RuntimeException( 'Unable to allocate a unique secret-link token.' );
    }

    protected function isExpired( mixed $expires_at_utc ) : bool {
        if ( $expires_at_utc === null ) {
            return( false );
        }

        $timestamp = $this->getUtcTimestamp( $expires_at_utc );
        return( ! is_int( $timestamp ) || $timestamp <= time() );
    }

    protected function normalizeUtcDateTime( mixed $value ) : string {
        $value = trim( (string) $value );
        return( is_int( $this->getUtcTimestamp( $value ) ) ? $value : '' );
    }

    protected function getUtcTimestamp( mixed $value ) : ?int {
        $value = trim( (string) $value );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value ) !== 1 ) {
            return( null );
        }

        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
            return( null );
        }
        if ( $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
            return( null );
        }

        return( $date->getTimestamp() );
    }

    protected function getEmptyStore() : array {
        return( [ 'site' => null, 'authors' => [] ] );
    }
}
