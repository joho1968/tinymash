<?php

final class TinyMashMarkdownSyncRequestAuthentication {

    public static function extractToken( array $server ) : string {
        $sync_token = trim( (string) ( $server['HTTP_X_TINYMASH_MARKDOWN_SYNC_TOKEN'] ?? '' ) );
        $authorization = trim( (string) ( $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
        $has_bearer_token = preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) === 1;
        if ( $sync_token !== '' && $has_bearer_token ) {
            throw new \RuntimeException( 'authentication_failed' );
        }
        if ( $sync_token === '' ) {
            $sync_token = $has_bearer_token ? trim( (string) $matches[1] ) : '';
        }
        if ( $sync_token === '' ) {
            throw new \RuntimeException( 'authentication_failed' );
        }
        return( $sync_token );
    }
}
