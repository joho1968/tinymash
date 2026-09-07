<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';
require_once __DIR__ . '/TinyMashUpdateErrorPolicy.php';
require_once __DIR__ . '/TinyMashUpdateManifestVerifier.php';
require_once __DIR__ . '/TinyMashUpdateReleaseConfig.php';

final class TinyMashUpdateCheckService {
    protected const CACHE_FORMAT = 'tinymash-update-check';
    protected const CACHE_SCHEMA = 1;
    protected const CACHE_TTL_SECONDS = 21600;
    protected const FAILURE_TTL_SECONDS = 900;
    protected const MAX_INDEX_BYTES = 65536;
    protected const MAX_MANIFEST_BYTES = 65536;
    protected const MAX_SIGNATURE_BYTES = 1024;
    protected const MAX_CACHE_BYTES = 262144;

    protected string $cache_filename;
    protected TinyMashUpdateManifestVerifier $verifier;
    protected $fetcher;
    protected $clock;
    protected string $release_origin;
    protected string $index_url;
    protected string $index_signature_url;
    protected array $trusted_keys;

    public function __construct( string $cache_filename, ?TinyMashUpdateManifestVerifier $verifier = null, ?callable $fetcher = null, ?callable $clock = null, array $release_config = [] ) {
        $this->cache_filename = $cache_filename;
        $this->verifier = $verifier ?? new TinyMashUpdateManifestVerifier();
        $this->fetcher = $fetcher;
        $this->clock = $clock;
        $this->release_origin = (string) ( $release_config['origin'] ?? TinyMashUpdateReleaseConfig::ORIGIN );
        $this->index_url = (string) ( $release_config['index_url'] ?? TinyMashUpdateReleaseConfig::INDEX_URL );
        $this->index_signature_url = (string) ( $release_config['index_signature_url'] ?? TinyMashUpdateReleaseConfig::INDEX_SIGNATURE_URL );
        $this->trusted_keys = is_array( $release_config['trusted_keys'] ?? null ) ? $release_config['trusted_keys'] : TinyMashUpdateReleaseConfig::TRUSTED_KEYS;
        $this->assertReleaseUrl( $this->index_url );
        $this->assertReleaseUrl( $this->index_signature_url );
    }

    public function getAvailability( string $installed_version, string $php_version, array $loaded_extensions ) : array {
        $installed_version = trim( $installed_version );
        if ( preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $installed_version ) !== 1 ) {
            return( $this->unavailable( $installed_version ) );
        }

        $directory = dirname( $this->cache_filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            return( $this->unavailable( $installed_version ) );
        }

        $lock_handle = @ fopen( $this->cache_filename . '.lock', 'c+' );
        if ( $lock_handle === false ) {
            return( $this->unavailable( $installed_version ) );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX ) ) {
                return( $this->unavailable( $installed_version ) );
            }

            $cache = $this->readCache();
            $cached_availability = $this->verifyCache( $cache, $installed_version, $php_version, $loaded_extensions );
            if ( $this->isFresh( $cache ) && ( $cached_availability !== [] || ! empty( $cache['last_refresh_failed'] ) ) ) {
                return( $cached_availability !== [] ? $cached_availability : $this->unavailable( $installed_version, true ) );
            }

            return( $this->refresh( $cache, $cached_availability, $installed_version, $php_version, $loaded_extensions ) );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function refresh( array $cache, array $cached_availability, string $installed_version, string $php_version, array $loaded_extensions ) : array {
        $attempted_at_utc = gmdate( 'Y-m-d\TH:i:s\Z', $this->now() );
        try {
            $index_bytes = $this->fetch( $this->index_url, self::MAX_INDEX_BYTES );
            $index_signature = $this->fetch( $this->index_signature_url, self::MAX_SIGNATURE_BYTES );
            $last_seen = (string) ( $cached_availability['index_published_at_utc'] ?? '' );
            $verified_index = $this->verifier->verifyIndex(
                $index_bytes,
                $index_signature,
                $this->trusted_keys,
                $this->release_origin,
                $last_seen
            );

            $latest_version = (string) ( $verified_index['latest']['version'] ?? '' );
            $manifest_bytes = '';
            $manifest_signature = '';
            $verified_manifest = [];
            if ( version_compare( $latest_version, $installed_version, '>' ) ) {
                $manifest_bytes = $this->fetch( (string) $verified_index['latest']['manifest_url'], self::MAX_MANIFEST_BYTES );
                $manifest_signature = $this->fetch( (string) $verified_index['latest']['manifest_signature_url'], self::MAX_SIGNATURE_BYTES );
                $verified_manifest = $this->verifier->verifyManifest(
                    $manifest_bytes,
                    $manifest_signature,
                    $this->trusted_keys,
                    $this->release_origin,
                    $latest_version,
                    $installed_version,
                    $php_version,
                    $loaded_extensions
                );
            }

            $updated_cache = [
                'format' => self::CACHE_FORMAT,
                'schema' => self::CACHE_SCHEMA,
                'attempted_at_utc' => $attempted_at_utc,
                'verified_at_utc' => $attempted_at_utc,
                'last_refresh_failed' => false,
                'index_bytes_base64' => base64_encode( $index_bytes ),
                'index_signature' => trim( $index_signature ),
                'manifest_bytes_base64' => $manifest_bytes !== '' ? base64_encode( $manifest_bytes ) : '',
                'manifest_signature' => trim( $manifest_signature ),
            ];
            $this->writeCache( $updated_cache );
            return( $this->buildAvailability( $verified_index, $verified_manifest, $installed_version, $attempted_at_utc, false ) );
        } catch ( \Throwable $e ) {
            TinyMashUpdateErrorPolicy::logFailure( 'check', $e );
            $failed_cache = $cache;
            if ( $failed_cache === [] ) {
                $failed_cache = [
                    'format' => self::CACHE_FORMAT,
                    'schema' => self::CACHE_SCHEMA,
                    'verified_at_utc' => '',
                    'index_bytes_base64' => '',
                    'index_signature' => '',
                    'manifest_bytes_base64' => '',
                    'manifest_signature' => '',
                ];
            }
            $failed_cache['attempted_at_utc'] = $attempted_at_utc;
            $failed_cache['last_refresh_failed'] = true;
            $this->writeCache( $failed_cache );
            if ( $cached_availability !== [] ) {
                $cached_availability['stale'] = true;
                $cached_availability['refresh_failed'] = true;
                return( $cached_availability );
            }
            return( $this->unavailable( $installed_version, true ) );
        }
    }

    protected function verifyCache( array $cache, string $installed_version, string $php_version, array $loaded_extensions ) : array {
        if ( ( $cache['format'] ?? null ) !== self::CACHE_FORMAT || ( $cache['schema'] ?? null ) !== self::CACHE_SCHEMA ) {
            return( [] );
        }
        $index_bytes = base64_decode( (string) ( $cache['index_bytes_base64'] ?? '' ), true );
        if ( ! is_string( $index_bytes ) || $index_bytes === '' || strlen( $index_bytes ) > self::MAX_INDEX_BYTES ) {
            return( [] );
        }
        try {
            $verified_index = $this->verifier->verifyIndex(
                $index_bytes,
                (string) ( $cache['index_signature'] ?? '' ),
                $this->trusted_keys,
                $this->release_origin
            );
            $verified_manifest = [];
            if ( version_compare( (string) $verified_index['latest']['version'], $installed_version, '>' ) ) {
                $manifest_bytes = base64_decode( (string) ( $cache['manifest_bytes_base64'] ?? '' ), true );
                if ( ! is_string( $manifest_bytes ) || $manifest_bytes === '' || strlen( $manifest_bytes ) > self::MAX_MANIFEST_BYTES ) {
                    return( [] );
                }
                $verified_manifest = $this->verifier->verifyManifest(
                    $manifest_bytes,
                    (string) ( $cache['manifest_signature'] ?? '' ),
                    $this->trusted_keys,
                    $this->release_origin,
                    (string) $verified_index['latest']['version'],
                    $installed_version,
                    $php_version,
                    $loaded_extensions
                );
            }
            return( $this->buildAvailability( $verified_index, $verified_manifest, $installed_version, (string) ( $cache['verified_at_utc'] ?? '' ), ! empty( $cache['last_refresh_failed'] ) ) );
        } catch ( \Throwable ) {
            return( [] );
        }
    }

    protected function buildAvailability( array $index, array $manifest, string $installed_version, string $verified_at_utc, bool $refresh_failed ) : array {
        $latest_version = (string) ( $index['latest']['version'] ?? '' );
        $status = version_compare( $latest_version, $installed_version, '>' ) ? 'available' : ( $latest_version === $installed_version ? 'current' : 'ahead' );
        return( [
            'status' => $status,
            'installed_version' => $installed_version,
            'available_version' => $latest_version,
            'index_published_at_utc' => (string) ( $index['published_at_utc'] ?? '' ),
            'verified_at_utc' => $verified_at_utc,
            'signing_key_id' => (string) ( $manifest['signing_key_id'] ?? $index['signing_key_id'] ?? '' ),
            'release' => $manifest,
            'stale' => $refresh_failed,
            'refresh_failed' => $refresh_failed,
        ] );
    }

    protected function unavailable( string $installed_version, bool $refresh_failed = false ) : array {
        return( [
            'status' => 'unavailable',
            'installed_version' => $installed_version,
            'available_version' => '',
            'release' => [],
            'stale' => false,
            'refresh_failed' => $refresh_failed,
        ] );
    }

    protected function readCache() : array {
        if ( ! is_file( $this->cache_filename ) || ! is_readable( $this->cache_filename ) ) {
            return( [] );
        }
        $size = @ filesize( $this->cache_filename );
        if ( ! is_int( $size ) || $size < 1 || $size > self::MAX_CACHE_BYTES ) {
            return( [] );
        }
        $decoded = json_decode( (string) @ file_get_contents( $this->cache_filename ), true, 16 );
        return( is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : [] );
    }

    protected function writeCache( array $cache ) : bool {
        $encoded = json_encode( $cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return( is_string( $encoded ) && TinyMashFilesystem::writeFileAtomically( $this->cache_filename, $encoded . PHP_EOL ) );
    }

    protected function isFresh( array $cache ) : bool {
        if ( ( $cache['format'] ?? null ) !== self::CACHE_FORMAT || ( $cache['schema'] ?? null ) !== self::CACHE_SCHEMA ) {
            return( false );
        }
        $attempted_at = strtotime( (string) ( $cache['attempted_at_utc'] ?? '' ) );
        if ( $attempted_at === false || $attempted_at > $this->now() ) {
            return( false );
        }
        $ttl = ! empty( $cache['last_refresh_failed'] ) ? self::FAILURE_TTL_SECONDS : self::CACHE_TTL_SECONDS;
        return( $this->now() - $attempted_at < $ttl );
    }

    protected function fetch( string $url, int $maximum_bytes ) : string {
        $this->assertReleaseUrl( $url );
        $body = is_callable( $this->fetcher )
            ? ( $this->fetcher )( $url, $maximum_bytes )
            : $this->fetchRemoteDocument( $url, $maximum_bytes );
        if ( ! is_string( $body ) || $body === '' || strlen( $body ) > $maximum_bytes ) {
            throw new \RuntimeException( 'The update response is invalid or too large.' );
        }
        return( $body );
    }

    protected function fetchRemoteDocument( string $url, int $maximum_bytes ) : string {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'cURL is unavailable.' );
        }
        $body = '';
        $too_large = false;
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new \RuntimeException( 'The update request could not be started.' );
        }
        curl_setopt_array( $curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'tinymash/' . ( defined( 'APP_VERSION' ) ? APP_VERSION : 'dev' ) . ' updater',
            CURLOPT_HTTPHEADER => [ 'Accept: application/json, text/plain;q=0.9' ],
            CURLOPT_WRITEFUNCTION => static function( mixed $handle, string $chunk ) use ( &$body, &$too_large, $maximum_bytes ) : int {
                if ( strlen( $body ) + strlen( $chunk ) > $maximum_bytes ) {
                    $too_large = true;
                    return( 0 );
                }
                $body .= $chunk;
                return( strlen( $chunk ) );
            },
        ] );
        $ok = curl_exec( $curl );
        $http_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        $effective_url = (string) curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL );
        $primary_ip = (string) curl_getinfo( $curl, CURLINFO_PRIMARY_IP );
        curl_close( $curl );
        if ( $too_large ) {
            throw new \RuntimeException( 'The update response is too large.' );
        }
        if ( $ok === false || $http_code !== 200 || ! hash_equals( $url, $effective_url ) ) {
            throw new \RuntimeException( 'The update server did not return the requested file.' );
        }
        if ( filter_var( $primary_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            throw new \RuntimeException( 'The update server resolved to a private or reserved address.' );
        }
        return( $body );
    }

    protected function now() : int {
        return( is_callable( $this->clock ) ? (int) ( $this->clock )() : time() );
    }

    protected function assertReleaseUrl( string $url ) : void {
        $url_parts = parse_url( $url );
        $origin_parts = parse_url( $this->release_origin );
        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false
            || ! is_array( $url_parts )
            || ! is_array( $origin_parts )
            || strtolower( (string) ( $url_parts['scheme'] ?? '' ) ) !== 'https'
            || strtolower( (string) ( $origin_parts['scheme'] ?? '' ) ) !== 'https'
            || trim( (string) ( $origin_parts['host'] ?? '' ) ) === ''
            || strtolower( (string) ( $url_parts['host'] ?? '' ) ) !== strtolower( (string) ( $origin_parts['host'] ?? '' ) )
            || (int) ( $url_parts['port'] ?? 443 ) !== (int) ( $origin_parts['port'] ?? 443 )
            || isset( $url_parts['user'] )
            || isset( $url_parts['pass'] )
            || isset( $url_parts['query'] )
            || isset( $url_parts['fragment'] ) ) {
            throw new \InvalidArgumentException( 'The update endpoint is invalid.' );
        }
    }
}
