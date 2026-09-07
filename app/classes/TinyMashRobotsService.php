<?php
namespace app\classes;

class TinyMashRobotsService {
    protected TinyMashConfig $config;

    public function __construct( TinyMashConfig $config ) {
        $this->config = $config;
    }

    public function buildRobotsTxt( array $additional_disallow_paths = [], string $sitemap_url = '', array $blocked_user_agents = [] ) : string {
        $lines = [ 'User-agent: *' ];
        if ( ! $this->config->isSitePublic() || $this->config->discouragesSearchIndexing() ) {
            $lines[] = 'Disallow: /';
            return( implode( "\n", $lines ) . "\n" );
        }

        $lines[] = 'Allow: /';
        $disallow_paths = array_merge( $this->getCoreDisallowPaths(), $additional_disallow_paths );
        $seen_paths = [];
        foreach ( $disallow_paths as $path ) {
            $path = $this->normalizeRoutePath( (string) $path );
            if ( $path === '' || isset( $seen_paths[$path] ) ) {
                continue;
            }
            $seen_paths[$path] = true;
            $lines[] = 'Disallow: ' . $path;
        }

        $sitemap_url = trim( $sitemap_url );
        if ( $this->isAbsoluteHttpUrl( $sitemap_url ) ) {
            $lines[] = 'Sitemap: ' . $sitemap_url;
        }

        $seen_user_agents = [];
        foreach ( $blocked_user_agents as $user_agent ) {
            $user_agent = trim( (string) $user_agent );
            if ( $user_agent === ''
                || mb_strlen( $user_agent ) > 100
                || preg_match( '/[\x00-\x20\x7F]/', $user_agent ) === 1
                || isset( $seen_user_agents[$user_agent] ) ) {
                continue;
            }
            $seen_user_agents[$user_agent] = true;
            $lines[] = '';
            $lines[] = 'User-agent: ' . $user_agent;
            $lines[] = 'Disallow: /';
        }

        return( implode( "\n", $lines ) . "\n" );
    }

    public function getCoreDisallowPaths() : array {
        $admin_path = $this->normalizeRoutePath( (string) $this->config->configGetAdminURL() );
        $login_path = $this->normalizeRoutePath( (string) $this->config->configGetLoginURL() );
        $paths = [ $admin_path, '/s/', '/_tinymash/' ];
        if ( $login_path !== '' && ! $this->pathIsWithin( $login_path, $admin_path ) ) {
            $paths[] = $login_path;
        }

        return( array_values( array_filter( $paths, static fn( string $path ) : bool => $path !== '' ) ) );
    }

    protected function normalizeRoutePath( string $path_or_url ) : string {
        $path_or_url = trim( $path_or_url );
        if ( $path_or_url === '' || str_starts_with( $path_or_url, '//' ) ) {
            return( '' );
        }

        $path = parse_url( $path_or_url, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) ) {
            return( '' );
        }
        $path = preg_replace( '#/+#', '/', $path ) ?? '';
        if ( $path === '' || $path === '/' || preg_match( '/[\x00-\x20\x7F]/', $path ) === 1 ) {
            return( '' );
        }

        return( $path );
    }

    protected function pathIsWithin( string $path, string $parent_path ) : bool {
        if ( $path === '' || $parent_path === '' ) {
            return( false );
        }

        return( $path === $parent_path || str_starts_with( $path, rtrim( $parent_path, '/' ) . '/' ) );
    }

    protected function isAbsoluteHttpUrl( string $url ) : bool {
        return(
            $url !== ''
            && filter_var( $url, FILTER_VALIDATE_URL ) !== false
            && preg_match( '/^https?:\/\//i', $url ) === 1
        );
    }
}
