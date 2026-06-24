<?php
namespace app\classes;

class TinyMashComponentsReportService {

    protected string $project_root;
    protected string $report_filename;
    protected ?array $composer_command = null;

    public function __construct( string $project_root, string $report_filename ) {
        $this->project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
        $this->report_filename = $report_filename;
    }

    public function getReportFilename() : string {
        return( $this->report_filename );
    }

    public function readReport() : array {
        if ( ! is_file( $this->report_filename ) || ! is_readable( $this->report_filename ) ) {
            return( [] );
        }

        $handle = @ fopen( $this->report_filename, 'rb' );
        if ( $handle === false ) {
            return( [] );
        }

        try {
            if ( ! @ flock( $handle, LOCK_SH ) ) {
                return( [] );
            }
            $json = stream_get_contents( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( [] );
        }

        $report = json_decode( $json, true, 16 );
        return( is_array( $report ) ? $report : [] );
    }

    public function runCheck() : array {
        $previous_report = $this->readReport();
        $checked_at_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        $composer_version = $this->detectComposerVersion();
        $outdated_result = $this->runComposerJsonCommand( [ 'outdated', '--format=json' ] );
        $audit_result = $this->runComposerJsonCommand( [ 'audit', '--format=json', '--locked' ] );

        $security_index = $this->buildSecurityIndex( $audit_result['json'] ?? [] );
        $fallback_security_index = [];
        if ( empty( $audit_result['ok'] ) ) {
            $fallback_security_index = $this->buildFallbackSecurityIndexFromReport( $previous_report );
        }

        $package_groups = $this->buildPackageGroups( $outdated_result['json'] ?? [], $security_index, $fallback_security_index );

        $advisory_data = [
            'source' => ! empty( $audit_result['ok'] ) ? 'current' : ( ! empty( $fallback_security_index ) ? 'previous' : 'unavailable' ),
            'checked_at_utc' => ! empty( $audit_result['ok'] )
                ? $checked_at_utc
                : (string) ( $previous_report['advisory_data']['checked_at_utc'] ?? $previous_report['checked_at_utc'] ?? '' ),
        ];

        $report = [
            'format' => 'tinymash-components-report',
            'format_version' => 1,
            'checked_at_utc' => $checked_at_utc,
            'composer_version' => $composer_version,
            'status' => $this->determineOverallStatus( $outdated_result, $audit_result ),
            'advisory_data' => $advisory_data,
            'checks' => [
                'outdated' => $this->normalizeCheckResult( $outdated_result ),
                'audit' => $this->normalizeCheckResult( $audit_result ),
            ],
            'summary' => [
                'security_updates' => count( $package_groups['security_updates'] ),
                'safe_updates' => count( $package_groups['safe_updates'] ),
                'version_updates' => count( $package_groups['version_updates'] ),
                'checked_packages' => count( $package_groups['all_packages'] ),
            ],
            'packages' => [
                'security_updates' => $package_groups['security_updates'],
                'safe_updates' => $package_groups['safe_updates'],
                'version_updates' => $package_groups['version_updates'],
            ],
        ];

        $this->writeReport( $report );
        return( $report );
    }

    public function sendNotificationIfNeeded( array $report, TinyMashMailer $mailer, array $system_settings, string $site_name, string $site_url = '', string $components_url = '' ) : array {
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

        $notification_summary = $this->buildUpdateNotificationSummary( $report );
        $security_updates = (int) $notification_summary['security_updates'];
        $safe_updates = (int) $notification_summary['safe_updates'];
        $version_updates = (int) $notification_summary['version_updates'];
        if ( empty( $notification_summary['has_updates'] ) ) {
            return(
                [
                    'sent' => false,
                    'status' => 'skipped',
                    'message' => 'No component updates were found.',
                ]
            );
        }

        $subject = '[' . $site_name . '] ' . (string) $notification_summary['title'];
        $lines = [
            (string) $notification_summary['message'],
            '',
            'Site: ' . $site_name,
            ( $site_url !== '' ? 'Site URL: ' . $site_url : '' ),
            ( $components_url !== '' ? 'Components: ' . $components_url : '' ),
            '',
            'Checked at: ' . (string) ( $report['checked_at_utc'] ?? '' ),
            'Security updates: ' . $security_updates,
            'Safe updates: ' . $safe_updates,
            'Version updates: ' . $version_updates,
            '',
            'Review them in System > Components.',
        ];
        $lines = array_values( array_filter( $lines, static fn( mixed $line ) : bool => trim( (string) $line ) !== '' ) );

        try {
            $mailer->sendSystemNotificationEmail( $system_settings, $recipient_email, $subject, implode( PHP_EOL, $lines ), $site_name );
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
                'message' => 'Sent update notification to ' . $recipient_email . '.',
            ]
        );
    }

    public function buildUpdateNotificationSummary( array $report ) : array {
        $security_updates = (int) ( $report['summary']['security_updates'] ?? 0 );
        $safe_updates = (int) ( $report['summary']['safe_updates'] ?? 0 );
        $version_updates = (int) ( $report['summary']['version_updates'] ?? 0 );

        $primary_class = '';
        $severity = 'info';
        $title = 'Component updates available';
        if ( $security_updates > 0 ) {
            $primary_class = 'security_updates';
            $severity = 'danger';
            $title = 'Security component updates available';
        } elseif ( $version_updates > 0 ) {
            $primary_class = 'version_updates';
            $severity = 'warning';
            $title = 'Major component updates available';
        } elseif ( $safe_updates > 0 ) {
            $primary_class = 'safe_updates';
            $severity = 'info';
            $title = 'Patch and minor component updates available';
        }

        return(
            [
                'has_updates' => ( $security_updates > 0 || $safe_updates > 0 || $version_updates > 0 ),
                'primary_class' => $primary_class,
                'severity' => $severity,
                'title' => $title,
                'message' => 'tinymash found ' . $security_updates . ' security, ' . $safe_updates . ' patch/minor, and ' . $version_updates . ' major update(s).',
                'security_updates' => $security_updates,
                'safe_updates' => $safe_updates,
                'version_updates' => $version_updates,
            ]
        );
    }

    protected function writeReport( array $report ) : void {
        $directory = dirname( $this->report_filename );
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
        }

        $encoded_report = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded_report ) || $encoded_report === '' ) {
            return;
        }

        $handle = @ fopen( $this->report_filename, 'c+' );
        if ( $handle === false ) {
            return;
        }

        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                return;
            }
            ftruncate( $handle, 0 );
            rewind( $handle );
            fwrite( $handle, $encoded_report . PHP_EOL );
            fflush( $handle );
            @ flock( $handle, LOCK_UN );
        } finally {
            fclose( $handle );
        }
    }

    protected function detectComposerVersion() : string {
        $composer_command = $this->getComposerCommand();
        if ( empty( $composer_command ) ) {
            return( 'Unavailable' );
        }

        $result = $this->runProcess( array_merge( $composer_command, [ '--version' ] ) );
        if ( ! empty( $result['stdout'] ) ) {
            return( trim( (string) $result['stdout'] ) );
        }

        return( 'Unavailable' );
    }

    protected function runComposerJsonCommand( array $arguments ) : array {
        $composer_command = $this->getComposerCommand();
        if ( empty( $composer_command ) ) {
            return(
                [
                    'ok' => false,
                    'command' => 'composer ' . implode( ' ', $arguments ),
                    'exit_code' => 1,
                    'stderr' => 'Composer executable was not found.',
                    'stdout' => '',
                    'json' => null,
                ]
            );
        }

        $result = $this->runProcess( array_merge( $composer_command, $arguments ) );
        $json = null;
        $stderr = $this->normalizeComposerStderr( (string) ( $result['stderr'] ?? '' ) );

        if ( ! empty( $result['stdout'] ) ) {
            $decoded = json_decode( (string) $result['stdout'], true, 32 );
            if ( is_array( $decoded ) ) {
                $json = $decoded;
            }
        }

        return(
            [
                'ok' => $json !== null,
                'command' => implode( ' ', array_merge( $composer_command, $arguments ) ),
                'exit_code' => (int) ( $result['exit_code'] ?? 1 ),
                'stderr' => $stderr,
                'stdout' => (string) ( $result['stdout'] ?? '' ),
                'json' => $json,
            ]
        );
    }

    protected function getComposerCommand() : array {
        if ( is_array( $this->composer_command ) ) {
            return( $this->composer_command );
        }

        $project_composer_phar = $this->project_root . DIRECTORY_SEPARATOR . 'composer.phar';
        if ( is_file( $project_composer_phar ) && is_readable( $project_composer_phar ) && is_executable( PHP_BINARY ) ) {
            $this->composer_command = [ PHP_BINARY, $project_composer_phar ];
            return( $this->composer_command );
        }

        $candidates = [];
        foreach ( [ 'bin/composer', 'bin/composer.phar' ] as $relative_composer_path ) {
            $candidates[] = $this->project_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_composer_path );
        }

        $path = trim( (string) getenv( 'PATH' ) );
        if ( $path !== '' ) {
            foreach ( explode( PATH_SEPARATOR, $path ) as $path_entry ) {
                $path_entry = trim( $path_entry );
                if ( $path_entry === '' ) {
                    continue;
                }

                $candidates[] = rtrim( $path_entry, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'composer';
            }
        }

        foreach ( [ dirname( PHP_BINARY ), '/usr/local/bin', '/usr/bin', '/bin' ] as $directory ) {
            $directory = trim( (string) $directory );
            if ( $directory === '' ) {
                continue;
            }

            $candidates[] = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'composer';
        }

        foreach ( array_values( array_unique( $candidates ) ) as $candidate ) {
            if ( is_file( $candidate ) && is_readable( $candidate ) && is_executable( $candidate ) ) {
                if ( $this->isPhpComposerScript( $candidate ) && is_executable( PHP_BINARY ) ) {
                    $this->composer_command = [ PHP_BINARY, $candidate ];
                    return( $this->composer_command );
                }

                $this->composer_command = [ $candidate ];
                return( $this->composer_command );
            }
        }

        $this->composer_command = [];
        return( $this->composer_command );
    }

    protected function isPhpComposerScript( string $filename ) : bool {
        $handle = @ fopen( $filename, 'rb' );
        if ( $handle === false ) {
            return( false );
        }

        try {
            $prefix = fread( $handle, 256 );
        } finally {
            fclose( $handle );
        }

        if ( ! is_string( $prefix ) || $prefix === '' ) {
            return( false );
        }

        return(
            str_starts_with( $prefix, '#!/usr/bin/env php' )
            || str_starts_with( $prefix, '#!/usr/bin/php' )
            || str_starts_with( $prefix, '<?php' )
        );
    }

    protected function runProcess( array $command ) : array {
        $composer_cache_dir = tinymash_get_runtime_temp_subdirectory( 'tinymash-composer-cache' );
        if ( ! is_dir( $composer_cache_dir ) ) {
            mkdir( $composer_cache_dir, 0775, true );
        }

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->project_root,
            [
                'HOME' => (string) getenv( 'HOME' ),
                'PATH' => (string) getenv( 'PATH' ),
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_CACHE_DIR' => $composer_cache_dir,
            ]
        );

        if ( ! is_resource( $process ) ) {
            return(
                [
                    'stdout' => '',
                    'stderr' => 'Unable to start composer process.',
                    'exit_code' => 1,
                ]
            );
        }

        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit_code = proc_close( $process );

        return(
            [
                'stdout' => is_string( $stdout ) ? $stdout : '',
                'stderr' => is_string( $stderr ) ? $stderr : '',
                'exit_code' => is_int( $exit_code ) ? $exit_code : 1,
            ]
        );
    }

    protected function normalizeComposerStderr( string $stderr ) : string {
        $stderr = trim( $stderr );
        if ( $stderr === '' ) {
            return( '' );
        }

        $filtered_lines = [];
        foreach ( preg_split( "/\\r\\n|\\n|\\r/", $stderr ) as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) {
                continue;
            }

            if ( str_contains( $line, 'Deprecation Notice: Constant E_STRICT is deprecated' )
                && str_contains( $line, 'phar://' )
                && str_contains( $line, '/composer/' ) ) {
                continue;
            }

            $filtered_lines[] = $line;
        }

        return( implode( PHP_EOL, $filtered_lines ) );
    }

    protected function buildSecurityIndex( mixed $audit_json ) : array {
        if ( ! is_array( $audit_json ) ) {
            return( [] );
        }

        $index = [];
        foreach ( (array) ( $audit_json['advisories'] ?? [] ) as $package_name => $advisories ) {
            $normalized_package_name = trim( (string) $package_name );
            if ( $normalized_package_name === '' ) {
                continue;
            }

            $index[$normalized_package_name] = [];
            foreach ( (array) $advisories as $advisory ) {
                if ( ! is_array( $advisory ) ) {
                    continue;
                }

                $index[$normalized_package_name][] = [
                    'advisory_id' => trim( (string) ( $advisory['advisoryId'] ?? $advisory['cve'] ?? '' ) ),
                    'title' => trim( (string) ( $advisory['title'] ?? '' ) ),
                    'severity' => trim( (string) ( $advisory['severity'] ?? '' ) ),
                    'link' => trim( (string) ( $advisory['link'] ?? '' ) ),
                ];
            }
        }

        return( $index );
    }

    protected function buildPackageGroups( mixed $outdated_json, array $security_index, array $fallback_security_index = [] ) : array {
        $groups = [
            'all_packages' => [],
            'security_updates' => [],
            'safe_updates' => [],
            'version_updates' => [],
        ];

        if ( ! is_array( $outdated_json ) ) {
            return( $groups );
        }

        foreach ( (array) ( $outdated_json['installed'] ?? [] ) as $package ) {
            if ( ! is_array( $package ) ) {
                continue;
            }

            $name = trim( (string) ( $package['name'] ?? '' ) );
            if ( $name === '' ) {
                continue;
            }

            $normalized_package = [
                'name' => $name,
                'version' => $this->normalizeDisplayedVersion( (string) ( $package['version'] ?? '' ) ),
                'latest' => $this->normalizeDisplayedVersion( (string) ( $package['latest'] ?? '' ) ),
                'latest_status' => trim( (string) ( $package['latest-status'] ?? '' ) ),
                'description' => trim( (string) ( $package['description'] ?? '' ) ),
                'direct_dependency' => ! empty( $package['direct-dependency'] ),
                'security_advisories' => [],
            ];

            $normalized_package['security_advisories'] = $security_index[$name] ?? [];
            if ( empty( $normalized_package['security_advisories'] ) ) {
                $normalized_package['security_advisories'] = $fallback_security_index[$name][$normalized_package['version']] ?? [];
            }

            $groups['all_packages'][] = $normalized_package;
            if ( ! empty( $normalized_package['security_advisories'] ) ) {
                $groups['security_updates'][] = $normalized_package;
                continue;
            }

            if ( $normalized_package['latest_status'] === 'semver-safe-update' ) {
                $groups['safe_updates'][] = $normalized_package;
                continue;
            }

            $groups['version_updates'][] = $normalized_package;
        }

        return( $groups );
    }

    protected function buildFallbackSecurityIndexFromReport( array $report ) : array {
        $index = [];
        $packages = is_array( $report['packages']['security_updates'] ?? null ) ? $report['packages']['security_updates'] : [];

        foreach ( $packages as $package ) {
            if ( ! is_array( $package ) ) {
                continue;
            }

            $name = trim( (string) ( $package['name'] ?? '' ) );
            $version = $this->normalizeDisplayedVersion( (string) ( $package['version'] ?? '' ) );
            $advisories = is_array( $package['security_advisories'] ?? null ) ? $package['security_advisories'] : [];
            if ( $name === '' || $version === '' || empty( $advisories ) ) {
                continue;
            }

            if ( ! isset( $index[$name] ) || ! is_array( $index[$name] ) ) {
                $index[$name] = [];
            }

            $index[$name][$version] = $advisories;
        }

        return( $index );
    }

    protected function determineOverallStatus( array $outdated_result, array $audit_result ) : string {
        if ( ! empty( $outdated_result['ok'] ) && ! empty( $audit_result['ok'] ) ) {
            return( 'ok' );
        }

        if ( ! empty( $outdated_result['ok'] ) || ! empty( $audit_result['ok'] ) ) {
            return( 'partial' );
        }

        return( 'error' );
    }

    protected function normalizeCheckResult( array $result ) : array {
        return(
            [
                'ok' => ! empty( $result['ok'] ),
                'command' => (string) ( $result['command'] ?? '' ),
                'exit_code' => (int) ( $result['exit_code'] ?? 1 ),
                'stderr' => trim( (string) ( $result['stderr'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeDisplayedVersion( string $version ) : string {
        $version = trim( $version );
        if ( $version === '' ) {
            return( '' );
        }

        if ( preg_match( '/^v(?=\d)/i', $version ) === 1 ) {
            return( substr( $version, 1 ) );
        }

        return( $version );
    }
}
