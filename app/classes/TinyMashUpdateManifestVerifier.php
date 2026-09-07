<?php
namespace app\classes;

use RuntimeException;
use Throwable;

class TinyMashUpdateManifestVerifier {
    public const INDEX_FORMAT = 'tinymash-update-index';
    public const MANIFEST_FORMAT = 'tinymash-update-manifest';
    public const SCHEMA_VERSION = 1;
    public const CHANNEL = 'stable';
    public const MAX_ARCHIVE_BYTES = 1073741824;

    public function verifyIndex( string $bytes, string $signature, array $trusted_keys, string $release_origin, string $last_seen_published_at_utc = '' ) : array {
        $signing_key_id = $this->verifySignature( $bytes, $signature, $trusted_keys );
        $index = $this->decodeObject( $bytes, 'update index' );
        $this->assertKeys( $index, [ 'format', 'schema', 'channel', 'published_at_utc', 'latest', 'withdrawn_versions' ], 'update index' );
        $this->assertHeader( $index, self::INDEX_FORMAT, 'update index' );
        $this->assertUtcTimestamp( $index['published_at_utc'] ?? null, 'update index release time' );
        if ( $last_seen_published_at_utc !== '' ) {
            $this->assertUtcTimestamp( $last_seen_published_at_utc, 'last-seen update index time' );
            if ( strcmp( (string) $index['published_at_utc'], $last_seen_published_at_utc ) < 0 ) {
                throw new RuntimeException( 'The signed update index is older than the last verified index.' );
            }
        }

        $latest = is_array( $index['latest'] ?? null ) ? $index['latest'] : [];
        $this->assertKeys( $latest, [ 'version', 'manifest_url', 'manifest_signature_url' ], 'latest release' );
        $version = $this->requireStableVersion( $latest['version'] ?? null, 'latest release version' );
        $manifest_url = $this->requireReleaseUrl( $latest['manifest_url'] ?? null, $release_origin, 'manifest URL' );
        $signature_url = $this->requireReleaseUrl( $latest['manifest_signature_url'] ?? null, $release_origin, 'manifest signature URL' );

        $withdrawn_versions = $this->normalizeVersions( $index['withdrawn_versions'] ?? null, 'withdrawn versions' );
        if ( in_array( $version, $withdrawn_versions, true ) ) {
            throw new RuntimeException( 'The latest release is withdrawn.' );
        }

        return( [
            'format' => self::INDEX_FORMAT,
            'schema' => self::SCHEMA_VERSION,
            'channel' => self::CHANNEL,
            'published_at_utc' => (string) $index['published_at_utc'],
            'latest' => [
                'version' => $version,
                'manifest_url' => $manifest_url,
                'manifest_signature_url' => $signature_url,
            ],
            'withdrawn_versions' => $withdrawn_versions,
            'signing_key_id' => $signing_key_id,
        ] );
    }

    public function verifyManifest(
        string $bytes,
        string $signature,
        array $trusted_keys,
        string $release_origin,
        string $expected_version,
        string $installed_version,
        string $php_version,
        array $loaded_extensions
    ) : array {
        $signing_key_id = $this->verifySignature( $bytes, $signature, $trusted_keys );
        $manifest = $this->decodeObject( $bytes, 'release manifest' );
        $manifest_keys = [
            'format', 'schema', 'channel', 'version', 'minimum_installed_version',
            'php', 'required_extensions', 'archive', 'deploy_manifest_sha256',
            'release_notes_url', 'released_at_utc',
        ];
        if ( array_key_exists( 'state_migration', $manifest ) ) {
            $manifest_keys[] = 'state_migration';
        }
        $this->assertKeys(
            $manifest,
            $manifest_keys,
            'release manifest'
        );
        $this->assertHeader( $manifest, self::MANIFEST_FORMAT, 'release manifest' );

        $state_migration = $manifest['state_migration'] ?? 'none';
        if ( ! is_string( $state_migration ) || $state_migration !== 'none' ) {
            throw new RuntimeException( 'The release requires an unsupported state migration.' );
        }

        $version = $this->requireStableVersion( $manifest['version'] ?? null, 'release version' );
        $expected_version = $this->requireStableVersion( $expected_version, 'expected release version' );
        $installed_version = $this->requireStableVersion( $installed_version, 'installed version' );
        $minimum_installed_version = $this->requireStableVersion( $manifest['minimum_installed_version'] ?? null, 'minimum installed version' );
        if ( ! hash_equals( $expected_version, $version ) ) {
            throw new RuntimeException( 'The release manifest version does not match the signed index.' );
        }
        if ( version_compare( $version, $installed_version, '<=' ) ) {
            throw new RuntimeException( 'The release does not upgrade the installed version.' );
        }
        if ( version_compare( $installed_version, $minimum_installed_version, '<' ) ) {
            throw new RuntimeException( 'The installed version is outside the supported update path.' );
        }
        if ( explode( '.', $version, 2 )[0] !== explode( '.', $installed_version, 2 )[0] ) {
            throw new RuntimeException( 'Cross-major updates are not supported.' );
        }

        $php = is_array( $manifest['php'] ?? null ) ? $manifest['php'] : [];
        $this->assertKeys( $php, [ 'minimum', 'maximum_exclusive' ], 'PHP constraint' );
        $php_minimum = $this->requirePhpVersion( $php['minimum'] ?? null, 'minimum PHP version' );
        $php_maximum = $php['maximum_exclusive'] === '' ? '' : $this->requirePhpVersion( $php['maximum_exclusive'] ?? null, 'maximum PHP version' );
        $php_version = $this->requirePhpVersion( $php_version, 'current PHP version' );
        if ( version_compare( $php_version, $php_minimum, '<' ) || ( $php_maximum !== '' && version_compare( $php_version, $php_maximum, '>=' ) ) ) {
            throw new RuntimeException( 'The PHP runtime is not compatible with this release.' );
        }

        $required_extensions = $this->normalizeExtensions( $manifest['required_extensions'] ?? null );
        $available_extensions = array_map( static fn( mixed $extension ) : string => strtolower( trim( (string) $extension ) ), $loaded_extensions );
        $missing_extensions = array_values( array_diff( $required_extensions, $available_extensions ) );
        if ( $missing_extensions !== [] ) {
            throw new RuntimeException( 'Required PHP extensions are missing: ' . implode( ', ', $missing_extensions ) . '.' );
        }

        $archive = is_array( $manifest['archive'] ?? null ) ? $manifest['archive'] : [];
        $this->assertKeys( $archive, [ 'url', 'maximum_bytes', 'sha256' ], 'release archive' );
        $archive_url = $this->requireReleaseUrl( $archive['url'] ?? null, $release_origin, 'archive URL' );
        $maximum_bytes = $archive['maximum_bytes'] ?? null;
        if ( ! is_int( $maximum_bytes ) || $maximum_bytes < 1 || $maximum_bytes > self::MAX_ARCHIVE_BYTES ) {
            throw new RuntimeException( 'The archive byte limit is invalid.' );
        }

        $archive_sha256 = $this->requireSha256( $archive['sha256'] ?? null, 'archive digest' );
        $deploy_manifest_sha256 = $this->requireSha256( $manifest['deploy_manifest_sha256'] ?? null, 'deploy manifest digest' );
        $release_notes_url = $this->requireHttpsUrl( $manifest['release_notes_url'] ?? null, 'release notes URL' );
        $this->assertUtcTimestamp( $manifest['released_at_utc'] ?? null, 'release time' );

        return( [
            'format' => self::MANIFEST_FORMAT,
            'schema' => self::SCHEMA_VERSION,
            'channel' => self::CHANNEL,
            'version' => $version,
            'minimum_installed_version' => $minimum_installed_version,
            'state_migration' => $state_migration,
            'php' => [ 'minimum' => $php_minimum, 'maximum_exclusive' => $php_maximum ],
            'required_extensions' => $required_extensions,
            'archive' => [ 'url' => $archive_url, 'maximum_bytes' => $maximum_bytes, 'sha256' => $archive_sha256 ],
            'deploy_manifest_sha256' => $deploy_manifest_sha256,
            'release_notes_url' => $release_notes_url,
            'released_at_utc' => (string) $manifest['released_at_utc'],
            'signing_key_id' => $signing_key_id,
        ] );
    }

    protected function verifySignature( string $bytes, string $signature, array $trusted_keys ) : string {
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            throw new RuntimeException( 'Ed25519 signature support is unavailable.' );
        }

        $signature_bytes = base64_decode( trim( $signature ), true );
        if ( ! is_string( $signature_bytes ) || strlen( $signature_bytes ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
            throw new RuntimeException( 'The detached signature is invalid.' );
        }

        foreach ( $trusted_keys as $key_id => $encoded_key ) {
            $key_id = trim( (string) $key_id );
            if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/i', $key_id ) !== 1 ) {
                continue;
            }
            $public_key = base64_decode( trim( (string) $encoded_key ), true );
            if ( ! is_string( $public_key ) || strlen( $public_key ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
                continue;
            }
            if ( sodium_crypto_sign_verify_detached( $signature_bytes, $bytes, $public_key ) ) {
                return( $key_id );
            }
        }

        throw new RuntimeException( 'The signature was not made by a trusted release key.' );
    }

    protected function decodeObject( string $bytes, string $label ) : array {
        try {
            $decoded = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
        } catch ( Throwable ) {
            throw new RuntimeException( 'The ' . $label . ' is not valid JSON.' );
        }
        if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
            throw new RuntimeException( 'The ' . $label . ' must be a JSON object.' );
        }
        return( $decoded );
    }

    protected function assertHeader( array $document, string $format, string $label ) : void {
        if ( ( $document['format'] ?? null ) !== $format
            || ( $document['schema'] ?? null ) !== self::SCHEMA_VERSION
            || ( $document['channel'] ?? null ) !== self::CHANNEL ) {
            throw new RuntimeException( 'The ' . $label . ' format, schema, or channel is unsupported.' );
        }
    }

    protected function assertKeys( array $value, array $expected_keys, string $label ) : void {
        $actual_keys = array_keys( $value );
        sort( $actual_keys, SORT_STRING );
        sort( $expected_keys, SORT_STRING );
        if ( $actual_keys !== $expected_keys ) {
            throw new RuntimeException( 'The ' . $label . ' fields are invalid.' );
        }
    }

    protected function requireStableVersion( mixed $value, string $label ) : string {
        if ( ! is_string( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        $version = trim( $value );
        if ( preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $version ) !== 1 ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        return( $version );
    }

    protected function requirePhpVersion( mixed $value, string $label ) : string {
        if ( ! is_string( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        $version = trim( $value );
        if ( preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $version ) !== 1 ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        return( $version );
    }

    protected function normalizeVersions( mixed $value, string $label ) : array {
        if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' must be a list.' );
        }
        $versions = [];
        foreach ( $value as $version ) {
            $version = $this->requireStableVersion( $version, 'withdrawn release version' );
            if ( in_array( $version, $versions, true ) ) {
                throw new RuntimeException( 'The withdrawn release list contains duplicates.' );
            }
            $versions[] = $version;
        }
        return( $versions );
    }

    protected function normalizeExtensions( mixed $value ) : array {
        if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
            throw new RuntimeException( 'Required PHP extensions must be a list.' );
        }
        $extensions = [];
        foreach ( $value as $extension ) {
            if ( ! is_string( $extension ) ) {
                throw new RuntimeException( 'The required PHP extension list is invalid.' );
            }
            $extension = strtolower( trim( $extension ) );
            if ( preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $extension ) !== 1 || in_array( $extension, $extensions, true ) ) {
                throw new RuntimeException( 'The required PHP extension list is invalid.' );
            }
            $extensions[] = $extension;
        }
        sort( $extensions, SORT_STRING );
        return( $extensions );
    }

    protected function requireReleaseUrl( mixed $value, string $release_origin, string $label ) : string {
        $url = $this->requireHttpsUrl( $value, $label );
        $origin = $this->requireHttpsUrl( $release_origin, 'release origin' );
        if ( $this->urlOrigin( $url ) !== $this->urlOrigin( $origin ) || str_contains( $url, '#' ) ) {
            throw new RuntimeException( 'The ' . $label . ' is outside the release origin.' );
        }
        return( $url );
    }

    protected function requireHttpsUrl( mixed $value, string $label ) : string {
        if ( ! is_string( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' must be an HTTPS URL without credentials.' );
        }
        $url = trim( $value );
        $parts = parse_url( $url );
        if ( filter_var( $url, FILTER_VALIDATE_URL ) === false
            || ! is_array( $parts )
            || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
            || trim( (string) ( $parts['host'] ?? '' ) ) === ''
            || isset( $parts['user'] )
            || isset( $parts['pass'] ) ) {
            throw new RuntimeException( 'The ' . $label . ' must be an HTTPS URL without credentials.' );
        }
        return( $url );
    }

    protected function urlOrigin( string $url ) : string {
        $parts = parse_url( $url );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        return( 'https://' . $host . $port );
    }

    protected function requireSha256( mixed $value, string $label ) : string {
        if ( ! is_string( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        $digest = strtolower( trim( $value ) );
        if ( preg_match( '/^[a-f0-9]{64}$/', $digest ) !== 1 ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        return( $digest );
    }

    protected function assertUtcTimestamp( mixed $value, string $label ) : void {
        if ( ! is_string( $value ) ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
        $timestamp = trim( $value );
        $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $timestamp, new \DateTimeZone( 'UTC' ) );
        if ( ! $parsed instanceof \DateTimeImmutable || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $timestamp ) {
            throw new RuntimeException( 'The ' . $label . ' is invalid.' );
        }
    }
}
