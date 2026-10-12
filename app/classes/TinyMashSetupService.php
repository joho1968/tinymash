<?php
declare(strict_types=1);

namespace app\classes;

require_once __DIR__ . '/TinyMashConfig.php';
require_once __DIR__ . '/TinyMashConfigFileReader.php';
require_once __DIR__ . '/TinyMashFilesystem.php';
require_once __DIR__ . '/TinyMashUserRepository.php';
require_once __DIR__ . '/TinyMashWriterGate.php';

class TinyMashSetupService {

    use TinyMashConfigFileReader;

    protected string $installation_root;
    protected ?array $loaded_config = null;
    protected string|false $config_snapshot = false;
    protected string $users_snapshot = '';

    public function __construct( string $installation_root ) {
        $root = realpath( $installation_root );
        if ( $root === false || ! is_dir( $root ) ) {
            throw new \InvalidArgumentException( 'The installation directory is unavailable.' );
        }
        $this->installation_root = rtrim( $root, DIRECTORY_SEPARATOR );
    }

    public function testWriteCapabilities() : array {
        $checks = [];
        $lease = ( new TinyMashWriterGate( $this->installation_root . '/data/runtime' ) )->acquireShared();
        try {
            foreach ( [ 'app/config', 'users', 'data/runtime' ] as $relative ) {
                $checks[$relative . '/ create, rename, and delete'] = false;
                $source = $this->installation_root . '/' . $relative . '/.installer-check-' . bin2hex( random_bytes( 12 ) );
                $destination = $source . '.renamed';
                $handle = null;
                $owned = null;
                $mask = umask( 0077 );
                try {
                    $this->assertSafePath( $relative );
                    $handle = @ fopen( $source, 'x+b' );
                    if ( $handle === false ) {
                        continue;
                    }
                    $identity = fstat( $handle );
                    $bytes = random_bytes( 32 );
                    $written = fwrite( $handle, $bytes );
                    $owned = [
                        'filename' => $source, 'device' => $identity['dev'], 'inode' => $identity['ino'],
                        'bytes' => $written === false ? 0 : $written, 'sha256' => hash( 'sha256', substr( $bytes, 0, $written === false ? 0 : $written ) ),
                    ];
                    if ( $written !== strlen( $bytes ) || ! fflush( $handle ) || ! $this->ownsStage( $owned )
                        || file_exists( $destination ) || is_link( $destination ) || ! @ rename( $source, $destination ) ) {
                        continue;
                    }
                    $owned['filename'] = $destination;
                    rewind( $handle );
                    if ( stream_get_contents( $handle ) !== $bytes || ! $this->ownsStage( $owned ) || ! @ unlink( $destination ) ) {
                        continue;
                    }
                    $checks[$relative . '/ create, rename, and delete'] = true;
                } finally {
                    if ( $owned !== null && $this->ownsStage( $owned ) && ! @ unlink( $owned['filename'] ) ) {
                        $checks[$relative . '/ create, rename, and delete'] = false;
                    }
                    if ( is_resource( $handle ) ) {
                        fclose( $handle );
                    }
                    umask( $mask );
                }
            }
        } finally {
            $lease->release();
        }
        return( $checks );
    }

    public function getConfigFilename() : string {
        return( $this->installation_root . '/app/config/tinymash.json' );
    }

    public function hasConfiguration() : bool {
        clearstatcache();
        return( file_exists( $this->getConfigFilename() ) || is_link( $this->getConfigFilename() ) );
    }

    /** @return array{ok: bool, checks: array<string, bool>} */
    public function preflight() : array {
        $checks = [ 'PHP 8.4.1+' => PHP_VERSION_ID >= 80401 ];
        foreach ( [ 'mbstring', 'json', 'dom', 'session', 'openssl', 'fileinfo' ] as $extension ) {
            $checks['Extension ' . $extension] = extension_loaded( $extension );
        }
        foreach ( [ 'data', 'users', 'tmp', 'data/content', 'data/drafts', 'data/media', 'data/plugins', 'data/runtime' ] as $relative_path ) {
            $checks['Directory ' . $relative_path . '/'] = $this->ensureDirectory( $relative_path );
        }
        try {
            $this->assertSafePath( 'app/config' );
            $checks['Config directory'] = is_dir( dirname( $this->getConfigFilename() ) )
                && is_writable( dirname( $this->getConfigFilename() ) );
        } catch ( \RuntimeException ) {
            $checks['Config directory'] = false;
        }
        return( [ 'ok' => ! in_array( false, $checks, true ), 'checks' => $checks ] );
    }

    public function loadConfiguration() : array {
        $this->assertSafePath( 'app/config/tinymash.json' );
        $this->assertSafePath( 'app/config/tinymash.json.lock' );
        if ( ! $this->hasConfiguration() ) {
            $state = $this->inspectState();
            if ( $state['state'] === 'blocked' ) {
                throw new \RuntimeException( $state['reason'] );
            }
        }
        $this->loaded_config = null;
        $this->config_snapshot = false;
        if ( $this->hasConfiguration() ) {
            if ( ! is_file( $this->getConfigFilename() ) ) {
                throw new \RuntimeException( 'The existing configuration is not a readable regular file.' );
            }
            $lock = $this->lockConfiguration( LOCK_SH );
            try {
                $json = $this->readBoundedFile( $this->getConfigFilename(), 1048576 );
            } finally {
                flock( $lock, LOCK_UN );
                fclose( $lock );
            }
            $config = $this->decodeConfiguration( $json );
            $this->config_snapshot = $json;
        } else {
            $this->assertSafePath( 'app/config/tinymash.json.example' );
            $example_filename = $this->getConfigFilename() . '.example';
            if ( file_exists( $example_filename ) ) {
                $config = $this->decodeConfiguration(
                    $this->readBoundedFile( $example_filename, 1048576 )
                );
            } else {
                $config = self::buildDefaultConfig();
            }
        }
        $this->loaded_config = $config;
        $this->users_snapshot = $this->userRepository()->getStoreGeneration();
        return( $config );
    }

    public function getDefaults() : array {
        $config = $this->loaded_config ?? $this->loadConfiguration();
        $site = $config['site'] ?? [];
        $locale = $config['locale'] ?? [];
        $existing_admin = null;
        if ( is_dir( $this->installation_root . '/users' ) ) {
            foreach ( $this->userRepository()->getAllUsers() as $user ) {
                if ( (string) ( $user['role'] ?? '' ) === 'superadmin' ) {
                    $existing_admin = $user;
                    break;
                }
            }
        }
        return( [
            'site_name' => mb_trim( (string) ( $site['name'] ?? 'tinymash' ) ),
            'base_url' => trim( (string) ( $site['base_url'] ?? '' ) ),
            'language' => self::normalizeLanguage( (string) ( $site['default_language'] ?? 'en' ) ),
            'timezone' => self::normalizeTimezone( (string) ( $locale['timezone'] ?? 'UTC' ) ),
            'admin_user' => (string) ( $existing_admin['username'] ?? 'admin' ),
            'admin_email' => mb_trim( (string) ( $existing_admin['email'] ?? $site['admin_email'] ?? '' ) ),
            'is_public' => array_key_exists( 'is_public', $site ) ? (bool) $site['is_public'] : true,
        ] );
    }

    public function getExistingUser( string $username ) : ?array {
        if ( ! is_dir( $this->installation_root . '/users' ) ) {
            return( null );
        }
        return( $this->userRepository()->getUserByUsername( self::normalizeUsername( $username ) ) );
    }

    public function normalizeValues( array $values ) : array {
        $normalizers = [
            'site_name' => self::normalizeSiteName(...),
            'base_url' => self::normalizeBaseUrl(...),
            'language' => self::normalizeLanguage(...),
            'timezone' => self::normalizeTimezone(...),
            'admin_user' => self::normalizeUsername(...),
            'admin_email' => self::normalizeEmail(...),
        ];
        $normalized = [];
        foreach ( $normalizers as $field => $normalizer ) {
            if ( ! is_string( $values[$field] ?? null ) ) {
                throw new \InvalidArgumentException( 'Invalid setup field: ' . $field );
            }
            $normalized[$field] = $normalizer( $values[$field] );
        }
        if ( ! is_bool( $values['is_public'] ?? null ) ) {
            throw new \InvalidArgumentException( 'Choose whether the site is public.' );
        }
        $normalized['is_public'] = $values['is_public'];
        return( $normalized );
    }

    /** Callbacks run under the setup lease; neither may perform network I/O. */
    public function save( array $values, string $password, bool $allow_update = false, ?callable $before_commit = null, ?callable $after_commit = null ) : array {
        $config = $this->loaded_config ?? $this->loadConfiguration();
        if ( $this->config_snapshot !== false && ! $allow_update ) {
            throw new \RuntimeException( 'Updating an existing configuration requires confirmation.' );
        }
        $values = $this->normalizeValues( $values );
        $this->assertSetupState( $values['admin_user'], $allow_update );
        $repository = $this->userRepository();
        $existing = $repository->getUserByUsername( $values['admin_user'] );
        if ( $password !== '' || $existing === null ) {
            self::validatePassword( $password );
        }
        $prepared = $repository->prepareUserSave( [
            'original_username' => $existing !== null ? $values['admin_user'] : '',
            'username' => $values['admin_user'],
            'display_name' => (string) ( $existing['display_name'] ?? '' ),
            'email' => $values['admin_email'],
            'role' => 'superadmin',
            'password' => $password,
            'confirm_password' => $password,
            'active' => true,
            'content_active' => $existing !== null ? ! empty( $existing['content_active'] ) : true,
        ] );
        if ( ! hash_equals( $this->users_snapshot, $prepared['generation'] ) ) {
            throw new \RuntimeException( 'User accounts changed while setup was being prepared. Run setup again.' );
        }
        $config['site']['name'] = $values['site_name'];
        $config['site']['base_url'] = $values['base_url'];
        $config['site']['default_language'] = $values['language'];
        $config['site']['admin_email'] = $values['admin_email'];
        $config['site']['is_public'] = $values['is_public'];
        $config['locale']['timezone'] = $values['timezone'];
        $payload = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL;
        if ( strlen( $payload ) > 1048576 ) {
            throw new \InvalidArgumentException( 'Setup configuration exceeds the size limit.' );
        }

        $this->assertSafePath( 'data/runtime/writer-gate.lock' );
        $this->assertSafePath( 'data/runtime/update-maintenance.json' );
        $gate = new TinyMashWriterGate( $this->installation_root . '/data/runtime' );
        $writer_lease = $gate->acquireSetup();
        $config_lock = null;
        $stage = null;
        $previous_umask = umask( 0077 );
        try {
            $config_lock = $this->lockConfiguration();
            $this->assertConfigurationUnchanged();
            $this->assertSetupState( $values['admin_user'], $allow_update, true );
            $this->userFiles();
            if ( $before_commit !== null ) {
                $before_commit();
            }
            $stage = $this->stageConfiguration( $payload );
            $this->assertNoMaintenance( $gate );
            $repository->savePreparedUser( $prepared );
            $this->assertNoMaintenance( $gate );
            $this->publishConfiguration( $stage, $payload );
            $this->config_snapshot = $payload;
            $this->loaded_config = $config;
            $this->users_snapshot = $repository->getStoreGeneration();
            if ( $after_commit !== null ) {
                $after_commit();
            }
        } finally {
            try {
                // An account without config needs host inspection; retain its staged config.
                $user_filename = $this->installation_root . '/users/' . $values['admin_user'] . '.json';
                if ( $stage !== null && ( $this->hasConfiguration() || ! file_exists( $user_filename ) ) ) {
                    $this->removeOwnedStage( $stage );
                }
            } finally {
                if ( is_resource( $config_lock ) ) {
                    flock( $config_lock, LOCK_UN );
                    fclose( $config_lock );
                }
                umask( $previous_umask );
                $writer_lease->release();
            }
        }
        return( $values );
    }

    /** @return array{state: string, admin_user: string, reason: string} */
    public function inspectState() : array {
        if ( $this->hasConfiguration() ) {
            return( [ 'state' => 'installed', 'admin_user' => '', 'reason' => '' ] );
        }
        $lease = null;
        try {
            $this->assertSafePath( 'data/runtime/writer-gate.lock' );
            $this->assertSafePath( 'data/runtime/update-maintenance.json' );
            $lease = ( new TinyMashWriterGate( $this->installation_root . '/data/runtime' ) )->acquireShared();
            return( $this->inspectStateUnlocked() );
        } catch ( \Throwable $exception ) {
            return( [ 'state' => 'blocked', 'admin_user' => '', 'reason' => $exception->getMessage() ] );
        } finally {
            $lease?->release();
        }
    }

    protected function inspectStateUnlocked() : array {
        if ( $this->hasConfiguration() ) {
            return( [ 'state' => 'installed', 'admin_user' => '', 'reason' => '' ] );
        }
        try {
            $this->assertSafePath( 'data/runtime/update-maintenance.json' );
            $this->assertNoMaintenance( new TinyMashWriterGate( $this->installation_root . '/data/runtime' ) );
            $this->checkDirectoryEntries( 'app/config', [
                'bootstrap.php', 'globals.inc.php', 'index.php', 'routes.inc.php',
                'deploy-manifest.php', 'tinymash.json.example', 'tinymash.json.lock',
            ] );
            $this->checkDirectoryEntries( 'data', [ 'content', 'drafts', 'media', 'plugins', 'runtime' ], true );
            foreach ( [ 'data/content', 'data/drafts', 'data/media', 'data/plugins' ] as $directory ) {
                $this->checkDirectoryEntries( $directory, [] );
            }
            $this->assertSafePath( 'tmp' );
            if ( file_exists( $this->installation_root . '/tmp' ) && ! is_dir( $this->installation_root . '/tmp' ) ) {
                throw new \RuntimeException( 'The setup temporary directory is unavailable.' );
            }
            $user_files = $this->userFiles();
            if ( $user_files === [] ) {
                return( [ 'state' => 'fresh', 'admin_user' => '', 'reason' => '' ] );
            }
            if ( count( $user_files ) !== 1 ) {
                throw new \RuntimeException( 'Setup found existing user accounts without configuration. Inspect the installation before proceeding.' );
            }
            $username = basename( $user_files[0], '.json' );
            $json = $this->readBoundedFile( $user_files[0], 65536 );
            $raw = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
            if ( ! is_array( $raw ) || ( $raw['username'] ?? null ) !== $username
                || ( $raw['role'] ?? null ) !== 'superadmin' || ( $raw['active'] ?? null ) !== true
                || ! is_string( $raw['password_hash'] ?? null )
                || ( password_get_info( $raw['password_hash'] )['algo'] ?? null ) === null ) {
                throw new \RuntimeException( 'The remaining user is not a valid active superadmin. Inspect the installation before proceeding.' );
            }
            foreach ( $raw as $key => $value ) {
                if ( is_array( $value ) && ! in_array( $key, [ 'public_theme_settings', 'plugin_settings' ], true ) ) {
                    throw new \RuntimeException( 'The remaining user record is invalid.' );
                }
            }
            if ( array_key_exists( 'email', $raw ) ) {
                if ( ! is_string( $raw['email'] ) ) {
                    throw new \RuntimeException( 'The remaining user record is invalid.' );
                }
                self::normalizeEmail( $raw['email'] );
            }
            $repository = new TinyMashUserRepository( $this->installation_root . '/users' );
            $user = $repository->getUserByUsername( $username );
            foreach ( $raw as $key => $value ) {
                if ( in_array( $key, [ 'password_hash', 'pending_email_change_token' ], true ) ) {
                    if ( ! is_string( $value ) ) {
                        throw new \RuntimeException( 'The remaining user record is invalid.' );
                    }
                    continue;
                }
                if ( ! is_array( $user ) || ! array_key_exists( $key, $user ) || $user[$key] !== $value ) {
                    throw new \RuntimeException( 'The remaining user record is invalid.' );
                }
            }
            return( [ 'state' => 'partial', 'admin_user' => $username, 'reason' => '' ] );
        } catch ( \Throwable $exception ) {
            return( [ 'state' => 'blocked', 'admin_user' => '', 'reason' => $exception->getMessage() ] );
        }
    }

    protected function assertSetupState( string $username, bool $allow_update, bool $lease_held = false ) : void {
        $state = $lease_held ? $this->inspectStateUnlocked() : $this->inspectState();
        if ( $state['state'] === 'blocked' ) {
            throw new \RuntimeException( $state['reason'] );
        }
        if ( $state['state'] === 'partial' && ( ! $allow_update || $state['admin_user'] !== $username ) ) {
            throw new \RuntimeException( 'An unfinished setup requires --update and the existing superadmin username.' );
        }
    }

    public static function normalizeSiteName( string $value ) : string {
        $value = mb_trim( $value );
        if ( $value === '' ) {
            throw new \InvalidArgumentException( 'Site name must not be empty.' );
        }
        return( $value );
    }

    public static function normalizeBaseUrl( string $value ) : string {
        $value = rtrim( trim( $value ), '/' );
        if ( $value === '' || filter_var( $value, FILTER_VALIDATE_URL ) === false ) {
            throw new \InvalidArgumentException( 'Enter a valid HTTP or HTTPS base URL.' );
        }
        $parts = parse_url( $value );
        if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
            || ! self::isValidUrlHost( (string) ( $parts['host'] ?? '' ) )
            || array_key_exists( 'user', $parts ) || array_key_exists( 'pass', $parts )
            || array_key_exists( 'query', $parts ) || array_key_exists( 'fragment', $parts )
            || preg_match( '/[\x00-\x20\x7F<>"\'`\\\\]/', (string) ( $parts['path'] ?? '' ) ) === 1 ) {
            throw new \InvalidArgumentException( 'Enter an HTTP or HTTPS base URL without credentials, query, or fragment.' );
        }
        return( $value );
    }

    public static function normalizeLanguage( string $value ) : string {
        $value = TinyMashConfig::normalizeHtmlLanguageTag( trim( $value ) );
        return( $value !== '' ? $value : 'en' );
    }

    public static function normalizeTimezone( string $value ) : string {
        $value = trim( $value );
        if ( $value === '' ) {
            return( 'UTC' );
        }
        if ( ! in_array( $value, timezone_identifiers_list(), true ) ) {
            throw new \InvalidArgumentException( 'Enter a valid PHP timezone such as UTC or Europe/Stockholm.' );
        }
        return( $value );
    }

    public static function normalizeUsername( string $value ) : string {
        $value = strtolower( mb_trim( $value ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/D', $value ) !== 1 ) {
            throw new \InvalidArgumentException( 'Use a lowercase username with letters, numbers, and underscores.' );
        }
        return( $value );
    }

    public static function normalizeEmail( string $value ) : string {
        $value = mb_trim( $value );
        if ( $value !== '' && filter_var( $value, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new \InvalidArgumentException( 'Enter a valid e-mail address or leave it empty.' );
        }
        return( $value );
    }

    public static function validatePassword( string $password ) : string {
        if ( $password === '' ) {
            throw new \InvalidArgumentException( 'Superadmin password must not be empty.' );
        }
        if ( mb_strlen( $password ) < 8 ) {
            throw new \InvalidArgumentException( 'Superadmin password must be at least 8 characters.' );
        }
        return( $password );
    }

    protected static function isValidUrlHost( string $host ) : bool {
        $host = trim( $host, '[]' );
        if ( $host === '' || str_ends_with( $host, '.' ) || str_contains( $host, '_' ) ) {
            return( false );
        }
        if ( strtolower( $host ) === 'localhost' || filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
            return( true );
        }
        if ( strlen( $host ) > 253 || preg_match( '/^[a-z0-9.-]+$/iD', $host ) !== 1 ) {
            return( false );
        }
        foreach ( explode( '.', $host ) as $label ) {
            if ( preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $label ) !== 1 ) {
                return( false );
            }
        }
        return( true );
    }

    protected function decodeConfiguration( string|false $json ) : array {
        if ( $json === false || trim( $json ) === '' ) {
            throw new \RuntimeException( 'Configuration is empty or unreadable. Preserve it and repair it before running setup.' );
        }
        try {
            $config = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
        } catch ( \JsonException ) {
            throw new \RuntimeException( 'Configuration contains malformed JSON. Preserve it and repair it before running setup.' );
        }
        if ( ! is_array( $config ) || $config === [] || array_is_list( $config ) ) {
            throw new \RuntimeException( 'Configuration must contain a non-empty JSON object.' );
        }
        foreach ( [ 'site', 'locale', 'editor' ] as $section ) {
            if ( array_key_exists( $section, $config ) && ! is_array( $config[$section] ) ) {
                throw new \RuntimeException( 'Configuration contains an invalid ' . $section . ' section.' );
            }
        }
        return( $config );
    }

    protected function lockConfiguration( int $operation = LOCK_EX ) : mixed {
        $this->assertSafePath( 'app/config/tinymash.json.lock' );
        $handle = @ fopen( $this->getConfigLockFilename(), 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to lock configuration.' );
        }
        try {
            $this->assertOpenedFile( $this->getConfigLockFilename(), $handle );
            if ( ! flock( $handle, $operation | LOCK_NB ) ) {
                throw new \RuntimeException( 'Configuration is busy. Run setup again in a moment.' );
            }
            return( $handle );
        } catch ( \Throwable $exception ) {
            fclose( $handle );
            throw $exception;
        }
    }

    protected function assertConfigurationUnchanged() : void {
        $this->assertSafePath( 'app/config/tinymash.json' );
        $current = $this->hasConfiguration() ? $this->readBoundedFile( $this->getConfigFilename(), 1048576 ) : false;
        if ( $current !== $this->config_snapshot ) {
            throw new \RuntimeException( 'Configuration changed while setup was being prepared. Run setup again.' );
        }
    }

    /** @return array{filename: string, device: int, inode: int, bytes: int, sha256: string} */
    protected function stageConfiguration( string $payload ) : array {
        $filename = dirname( $this->getConfigFilename() ) . '/.tinymash-setup-' . bin2hex( random_bytes( 16 ) );
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $payload ) ) {
            throw new \RuntimeException( 'Unable to stage configuration.' );
        }
        $stat = lstat( $filename );
        return( [
            'filename' => $filename, 'device' => (int) $stat['dev'], 'inode' => (int) $stat['ino'],
            'bytes' => strlen( $payload ), 'sha256' => hash( 'sha256', $payload ),
        ] );
    }

    protected function publishConfiguration( array $stage, string $payload ) : void {
        $this->assertConfigurationUnchanged();
        if ( ! $this->ownsStage( $stage ) || $this->readBoundedFile( $stage['filename'], 1048576 ) !== $payload ) {
            throw new \RuntimeException( 'The staged configuration changed during setup.' );
        }
        $permissions = @ fileperms( $this->getConfigFilename() );
        if ( is_int( $permissions ) && ! @ chmod( $stage['filename'], $permissions & 0777 ) ) {
            throw new \RuntimeException( 'Unable to preserve configuration permissions.' );
        }
        if ( ! @ rename( $stage['filename'], $this->getConfigFilename() ) ) {
            throw new \RuntimeException( 'Unable to publish configuration. Preserve the user account and inspect the staged configuration.' );
        }
        if ( $this->readBoundedFile( $this->getConfigFilename(), 1048576 ) !== $payload ) {
            throw new \RuntimeException( 'Configuration was published but could not be verified. Inspect the installation before running setup again.' );
        }
    }

    protected function ownsStage( array $stage ) : bool {
        clearstatcache();
        $stat = @ lstat( $stage['filename'] );
        return( is_array( $stat ) && ( $stat['mode'] & 0170000 ) === 0100000 && $stat['nlink'] === 1
            && $stat['dev'] === $stage['device'] && $stat['ino'] === $stage['inode'] && $stat['size'] === $stage['bytes']
            && hash_equals( $stage['sha256'], (string) @ hash_file( 'sha256', $stage['filename'] ) ) );
    }

    protected function removeOwnedStage( array $stage ) : void {
        if ( $this->ownsStage( $stage ) && ! @ unlink( $stage['filename'] ) ) {
            throw new \RuntimeException( 'Setup staging could not be removed. Inspect app/config/ before proceeding.' );
        }
    }

    protected function assertNoMaintenance( TinyMashWriterGate $gate ) : void {
        if ( $gate->getMaintenanceStatus() !== [] ) {
            throw new \RuntimeException( 'A tinymash update is in progress.' );
        }
    }

    protected function userRepository() : TinyMashUserRepository {
        $this->assertSafePath( 'users' );
        $this->userFiles();
        $config = $this->loaded_config ?? $this->loadConfiguration();
        $mode = strtolower( trim( (string) ( $config['editor']['default_mode'] ?? 'simple' ) ) );
        return( new TinyMashUserRepository( $this->installation_root . '/users', $mode ) );
    }

    protected function ensureDirectory( string $relative_path ) : bool {
        try {
            $this->assertSafePath( $relative_path );
        } catch ( \RuntimeException ) {
            return( false );
        }
        $directory = $this->installation_root . '/' . $relative_path;
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            return( false );
        }
        return( is_writable( $directory ) );
    }

    protected function assertSafePath( string $relative_path ) : void {
        clearstatcache();
        $path = $this->installation_root;
        $components = explode( '/', $relative_path );
        foreach ( $components as $index => $component ) {
            $path .= '/' . $component;
            $stat = @ lstat( $path );
            if ( $stat === false ) {
                continue;
            }
            $type = $stat['mode'] & 0170000;
            if ( ! in_array( $type, [ 0040000, 0100000 ], true )
                || ( $index < count( $components ) - 1 && $type !== 0040000 )
                || ( $type === 0100000 && $stat['nlink'] !== 1 ) ) {
                throw new \RuntimeException( 'Setup paths must be regular files or directories without links.' );
            }
        }
    }

    protected function directoryEntries( string $relative_path, int $limit = 128 ) : array {
        $this->assertSafePath( $relative_path );
        $directory = $this->installation_root . '/' . $relative_path;
        if ( ! file_exists( $directory ) ) {
            return( [] );
        }
        $handle = is_dir( $directory ) ? @ opendir( $directory ) : false;
        if ( $handle === false ) {
            throw new \RuntimeException( 'Setup cannot inspect ' . $relative_path . '/.' );
        }
        $entries = [];
        try {
            while ( ( $entry = readdir( $handle ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) {
                    continue;
                }
                if ( count( $entries ) >= $limit ) {
                    throw new \RuntimeException( 'Setup found too many entries in ' . $relative_path . '/. Inspect the installation before proceeding.' );
                }
                $this->assertSafePath( $relative_path . '/' . $entry );
                $entries[] = $entry;
            }
        } finally {
            closedir( $handle );
        }
        return( $entries );
    }

    protected function checkDirectoryEntries( string $directory, array $allowed, bool $directories = false ) : void {
        foreach ( $this->directoryEntries( $directory ) as $entry ) {
            $filename = $this->installation_root . '/' . $directory . '/' . $entry;
            if ( ! in_array( $entry, $allowed, true ) || ( $directories ? ! is_dir( $filename ) : ! is_file( $filename ) )
                || ! is_readable( $filename ) ) {
                throw new \RuntimeException( 'Setup found unexpected or unreadable state in ' . $directory . '/. Inspect the installation before proceeding.' );
            }
        }
    }

    protected function userFiles() : array {
        $files = [];
        foreach ( $this->directoryEntries( 'users', 4096 ) as $entry ) {
            $filename = $this->installation_root . '/users/' . $entry;
            if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
                throw new \RuntimeException( 'The user store contains an unreadable or unexpected entry.' );
            }
            if ( $entry === '.users.lock' && filesize( $filename ) === 0 ) {
                continue;
            }
            if ( preg_match( '/^[a-z0-9_]{1,64}\.json$/D', $entry ) !== 1 ) {
                throw new \RuntimeException( 'The user store contains an unexpected entry. Inspect it before running setup.' );
            }
            $files[] = $filename;
        }
        return( $files );
    }

    protected function readBoundedFile( string $filename, int $limit ) : string {
        $handle = @ fopen( $filename, 'rb' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Setup could not read an installation file.' );
        }
        try {
            $this->assertOpenedFile( $filename, $handle );
            $stat = fstat( $handle );
            if ( $stat['size'] > $limit ) {
                throw new \RuntimeException( 'An installation file exceeds the setup size limit.' );
            }
            $contents = stream_get_contents( $handle, $limit + 1 );
            if ( ! is_string( $contents ) || strlen( $contents ) !== $stat['size'] ) {
                throw new \RuntimeException( 'An installation file changed while setup was reading it.' );
            }
            $this->assertOpenedFile( $filename, $handle );
            return( $contents );
        } finally {
            fclose( $handle );
        }
    }

    protected function assertOpenedFile( string $filename, mixed $handle ) : void {
        clearstatcache();
        $opened = fstat( $handle );
        $current = @ lstat( $filename );
        if ( ! is_array( $opened ) || ! is_array( $current ) || ( $current['mode'] & 0170000 ) !== 0100000
            || $current['nlink'] !== 1 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] ) {
            throw new \RuntimeException( 'An installation file is unsafe or changed during setup.' );
        }
    }

    protected static function buildDefaultConfig() : array {
        return [
            'site' => [
                'name' => 'tinymash',
                'slogan' => '',
                'contact' => '',
                'base_url' => '',
                'login_url' => '/admin/login',
                'admin_url' => '/admin',
                'security_min_password' => 8,
                'default_language' => 'en',
                'allow_registrations' => false,
                'is_public' => true,
                'maintenance' => false,
                'admin_email' => '',
                'system_notifications_email' => '',
                'week_starts_on' => 'monday',
                'allow_username_change' => false,
                'allow_email_change' => true,
                'login_message' => '',
                'head_tags' => [],
                'images' => [
                    'banner' => [],
                    'favicon_png' => [],
                    'favicon_ico' => [],
                    'og' => [],
                    'background' => [],
                ],
                'background_render_mode' => 'scaled',
                'allow_secret_links' => true,
                'secret_link_default_expiry_days' => 60,
                'filesystem_umask' => '0007',
                'rendered_content_cache_enabled' => true,
                'discourage_search_indexing' => false,
                'allow_admin_password_resets' => false,
                'allow_author_password_resets' => true,
                'password_reset_throttle_window_minutes' => 60,
                'password_reset_max_ip_requests' => 5,
                'password_reset_max_identifier_requests' => 3,
            ],
            'locale' => [
                'time' => 'H:i (T)',
                'date' => 'd-M-Y',
                'timezone' => 'UTC',
            ],
            'content' => [
                'require_moderation' => false,
                'revision_retention_limit' => 20,
            ],
            'editor' => [
                'autosave_enabled' => true,
                'autosave_interval_seconds' => 120,
                'classic_smileys_enabled' => true,
                'default_mode' => 'simple',
            ],
            'media' => [
                'content_images_mode' => 'authenticated',
                'image_driver' => 'auto',
                'image_max_width' => 1920,
                'image_max_height' => 1920,
                'generate_thumbnails' => true,
                'thumbnail_max_width' => 320,
                'thumbnail_max_height' => 320,
                'image_max_upload_mb' => 10,
                'allowed_image_mimes' => [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/webp',
                    'image/avif',
                ],
            ],
            'smtp' => [
                'enabled' => false,
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_email' => '',
                'from_name' => '',
                'reply_to_email' => '',
            ],
            'themes' => [
                'public' => [
                    'current' => 'baseline',
                    'settings' => [],
                ],
            ],
            'plugins' => [
                'states' => [],
                'settings' => [],
            ],
            'housekeeping' => [
                'stale_draft_retention_days' => 7,
                'last_run_utc' => '',
                'last_trigger' => '',
                'last_mode' => '',
                'web_fallback_mode' => 'auto',
            ],
            'notifications' => [
                'retention_days' => 30,
                'email_events' => [
                    'moderation_required' => false,
                    'profile_email_changed' => false,
                    'user_lockout' => true,
                    'component_updates' => true,
                ],
            ],
            'menus' => [
                'locations' => [
                    'primary' => [],
                    'footer' => [],
                ],
            ],
        ];
    }
}
