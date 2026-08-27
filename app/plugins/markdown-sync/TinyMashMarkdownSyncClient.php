<?php

class TinyMashMarkdownSyncClient {

    public function __construct( protected TinyMashMarkdownCollectionService $collection_service ) {
    }

    public function run( string $action, string $source_directory, string $profile_filename, bool $prune = false ) : array {
        $action = strtolower( trim( $action ) );
        if ( ! in_array( $action, [ 'plan', 'push' ], true ) ) {
            throw new \InvalidArgumentException( 'The action must be plan or push.' );
        }

        $profile = $this->readProfile( $profile_filename );
        $bundle = $this->collection_service->buildBundleFromDirectory( $source_directory );
        $request = [ 'bundle' => $bundle, 'prune' => $prune ];
        $plan = $this->postJson( $profile, 'plan', $request );
        if ( $action === 'plan' ) {
            return( $plan );
        }
        if ( (int) ( $plan['counts']['conflict'] ?? 0 ) > 0 ) {
            throw new \RuntimeException( 'The plan contains conflicts. No content was changed.' );
        }

        $plan_id = trim( (string) ( $plan['plan_id'] ?? '' ) );
        if ( $plan_id === '' ) {
            throw new \RuntimeException( 'The receiver returned no plan ID.' );
        }
        $request['plan_id'] = $plan_id;
        return( $this->postJson( $profile, 'apply', $request ) );
    }

    protected function readProfile( string $profile_filename ) : array {
        $profile_filename = trim( $profile_filename );
        if ( $profile_filename === '' || ! is_file( $profile_filename ) || ! is_readable( $profile_filename ) || is_link( $profile_filename ) ) {
            throw new \RuntimeException( 'The connection profile could not be read.' );
        }
        if ( DIRECTORY_SEPARATOR === '/' ) {
            $permissions = @ fileperms( $profile_filename );
            if ( is_int( $permissions ) && ( $permissions & 0077 ) !== 0 ) {
                throw new \RuntimeException( 'The connection profile must only be readable by its owner. Run chmod 600 on the file.' );
            }
        }

        $json = @ file_get_contents( $profile_filename );
        $profile = is_string( $json ) ? json_decode( $json, true, 32 ) : null;
        if ( ! is_array( $profile ) ) {
            throw new \RuntimeException( 'The connection profile is not valid JSON.' );
        }

        $receiver_url = rtrim( trim( (string) ( $profile['receiver_url'] ?? '' ) ), '/' );
        $token = trim( (string) ( $profile['token'] ?? '' ) );
        $url_parts = parse_url( $receiver_url );
        $scheme = strtolower( (string) ( $url_parts['scheme'] ?? '' ) );
        $host = strtolower( (string) ( $url_parts['host'] ?? '' ) );
        $local_host = in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true );
        if (
            $receiver_url === '' ||
            ! is_array( $url_parts ) ||
            isset( $url_parts['user'] ) ||
            isset( $url_parts['pass'] ) ||
            isset( $url_parts['query'] ) ||
            isset( $url_parts['fragment'] ) ||
            ( $scheme !== 'https' && ! ( $scheme === 'http' && $local_host ) )
        ) {
            throw new \RuntimeException( 'The receiver URL must use HTTPS. HTTP is allowed only for localhost.' );
        }
        if ( preg_match( '/^tms1\.mds_[a-f0-9]{20}\.[A-Za-z0-9_-]{40,128}$/D', $token ) !== 1 ) {
            throw new \RuntimeException( 'The connection profile contains an invalid token.' );
        }

        $http_basic = $profile['http_basic'] ?? null;
        if ( $http_basic === null ) {
            return( [ 'receiver_url' => $receiver_url, 'token' => $token ] );
        }
        if (
            ! is_array( $http_basic ) ||
            array_diff( array_keys( $http_basic ), [ 'username', 'password' ] ) !== [] ||
            ! is_string( $http_basic['username'] ?? null ) ||
            ! is_string( $http_basic['password'] ?? null )
        ) {
            throw new \RuntimeException( 'The HTTP Basic authentication settings are invalid.' );
        }
        $http_basic_username = $http_basic['username'];
        $http_basic_password = $http_basic['password'];
        if (
            $http_basic_username === '' ||
            $http_basic_password === '' ||
            strlen( $http_basic_username ) > 255 ||
            strlen( $http_basic_password ) > 1024 ||
            preg_match( '/[\x00-\x1F\x7F:]/', $http_basic_username ) === 1 ||
            preg_match( '/[\x00-\x1F\x7F]/', $http_basic_password ) === 1
        ) {
            throw new \RuntimeException( 'The HTTP Basic authentication settings are invalid.' );
        }

        return( [
            'receiver_url' => $receiver_url,
            'token' => $token,
            'http_basic' => [
                'username' => $http_basic_username,
                'password' => $http_basic_password,
            ],
        ] );
    }

    protected function postJson( array $profile, string $endpoint, array $payload ) : array {
        if ( ! function_exists( 'curl_init' ) ) {
            throw new \RuntimeException( 'The cURL extension is required for Markdown Sync.' );
        }
        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        $url = (string) $profile['receiver_url'] . '/markdown-sync/v1/' . $endpoint;
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new \RuntimeException( 'The receiver request could not be started.' );
        }

        $http_basic_challenge = false;
        $curl_options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Expect:',
                'X-Tinymash-Markdown-Sync-Token: ' . (string) $profile['token'],
            ],
            CURLOPT_HEADERFUNCTION => static function( mixed $handle, string $header ) use ( &$http_basic_challenge ) : int {
                if ( preg_match( '/^WWW-Authenticate:\s*Basic(?:\s|$)/i', trim( $header ) ) === 1 ) {
                    $http_basic_challenge = true;
                }
                return( strlen( $header ) );
            },
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 330,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ( is_array( $profile['http_basic'] ?? null ) ) {
            $curl_options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curl_options[CURLOPT_USERPWD] = (string) $profile['http_basic']['username'] . ':' . (string) $profile['http_basic']['password'];
        }

        curl_setopt_array( $curl, $curl_options );

        try {
            $response_body = curl_exec( $curl );
            $response_code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
            if ( ! is_string( $response_body ) ) {
                throw new \RuntimeException( 'The receiver request failed: ' . curl_error( $curl ) );
            }
        } finally {
            curl_close( $curl );
        }

        if ( $response_code === 401 && $http_basic_challenge ) {
            throw new \RuntimeException(
                isset( $profile['http_basic'] )
                    ? 'The receiver rejected the HTTP Basic authentication credentials.'
                    : 'The receiver requires HTTP Basic authentication. Add http_basic credentials to the connection profile.'
            );
        }

        $response = json_decode( $response_body, true, 512 );
        if ( ! is_array( $response ) ) {
            throw new \RuntimeException( 'The receiver returned an invalid response.' );
        }
        if ( $response_code < 200 || $response_code >= 300 || empty( $response['ok'] ) ) {
            $message = mb_trim( (string) ( $response['message'] ?? 'The receiver rejected the request.' ) );
            throw new \RuntimeException( $message !== '' ? $message : 'The receiver rejected the request.' );
        }
        $result = $response['result'] ?? null;
        if ( ! is_array( $result ) ) {
            throw new \RuntimeException( 'The receiver returned no result.' );
        }
        return( $result );
    }
}
