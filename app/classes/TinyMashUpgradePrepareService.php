<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployAuditService.php';
require_once __DIR__ . '/TinyMashDeployPolicy.php';

final class TinyMashUpgradePrepareService {

    protected const JSON_VALIDATION_LIMIT = 64 * 1024 * 1024;
    protected const FILE_COUNT_LIMIT = 500000;

    protected string $target_root;
    protected string $source_root;
    protected bool $allow_missing_extensions;

    public function __construct( string $target_root, string $source_root, bool $allow_missing_extensions = false ) {
        $this->target_root = $this->resolveRoot( $target_root, 'New release' );
        $this->source_root = $this->resolveRoot( $source_root, 'Old installation' );
        $this->allow_missing_extensions = $allow_missing_extensions;

        if ( $this->target_root === $this->source_root
            || str_starts_with( $this->target_root . DIRECTORY_SEPARATOR, $this->source_root . DIRECTORY_SEPARATOR )
            || str_starts_with( $this->source_root . DIRECTORY_SEPARATOR, $this->target_root . DIRECTORY_SEPARATOR ) ) {
            throw new \RuntimeException( 'Old and new installations must be separate non-nested directories.' );
        }
    }

    public function inspect() : array {
        $errors = [];
        $warnings = [];
        $checks = [];

        $working_directory = realpath( (string) getcwd() );
        $this->addCheck(
            $checks,
            $errors,
            'New release directory',
            is_string( $working_directory ) && $working_directory === $this->target_root,
            'Run the command from the new release root.'
        );

        $target_manifest = $this->loadDeployManifest( $this->target_root, true, $warnings );
        $this->addCheck(
            $checks,
            $errors,
            'New release manifest',
            $target_manifest !== [],
            'The new release requires a valid version-2 deploy-manifest.json.'
        );

        $target_audit = [];
        try {
            $target_audit = ( new TinyMashDeployAuditService( $this->target_root ) )->audit();
            $this->addCheck(
                $checks,
                $errors,
                'New release audit',
                ! empty( $target_audit['ok'] ),
                'The new release must pass deploy:audit before state is copied.'
            );
        } catch ( \Throwable $exception ) {
            $this->addCheck( $checks, $errors, 'New release audit', false, $exception->getMessage() );
        }

        $target_state_ok = $this->inspectEmptyTargetState( $errors );
        $checks[] = [
            'check' => 'New release state',
            'ok' => $target_state_ok,
            'detail' => $target_state_ok ? 'empty' : 'not empty or not writable',
        ];

        $source_config = $this->loadSourceConfig( $errors );
        $checks[] = [
            'check' => 'Old installation config',
            'ok' => $source_config !== [],
            'detail' => $source_config !== [] ? 'valid' : 'invalid or unreadable',
        ];

        $inventory = [];
        try {
            $inventory = $this->buildPreserveInventory();
            $checks[] = [
                'check' => 'Authoritative state',
                'ok' => true,
                'detail' => number_format( (int) $inventory['files'] ) . ' files, ' . $this->formatBytes( (int) $inventory['bytes'] ),
            ];
        } catch ( \Throwable $exception ) {
            $errors[] = $exception->getMessage();
            $checks[] = [ 'check' => 'Authoritative state', 'ok' => false, 'detail' => 'invalid or unsafe' ];
        }

        $disk_free = @ disk_free_space( $this->target_root );
        $required_bytes = (int) ( $inventory['bytes'] ?? 0 );
        $disk_ok = ! is_float( $disk_free ) && ! is_int( $disk_free )
            ? true
            : $disk_free >= $required_bytes + max( 16 * 1024 * 1024, (int) ceil( $required_bytes * 0.05 ) );
        $this->addCheck(
            $checks,
            $errors,
            'Available disk space',
            $disk_ok,
            $disk_ok ? ( is_numeric( $disk_free ) ? $this->formatBytes( (int) $disk_free ) . ' free' : 'not reported' ) : 'insufficient space'
        );

        $old_manifest = $this->loadDeployManifest( $this->source_root, false, $warnings );
        $review = $this->buildReviewReport( $old_manifest, $warnings );
        $extensions = $this->inspectExtensions( $source_config, $old_manifest );
        $missing_extensions = array_merge(
            (array) ( $extensions['missing_active_plugins'] ?? [] ),
            (array) ( $extensions['missing_selected_themes'] ?? [] )
        );
        if ( $missing_extensions !== [] && ! $this->allow_missing_extensions ) {
            $errors[] = 'Active plugins or selected themes are missing from the new release.';
        }
        $checks[] = [
            'check' => 'Required extensions',
            'ok' => $missing_extensions === [] || $this->allow_missing_extensions,
            'detail' => $missing_extensions === [] ? 'available' : count( $missing_extensions ) . ' missing',
        ];

        return(
            [
                'ok' => $errors === [],
                'source_root' => $this->source_root,
                'target_root' => $this->target_root,
                'source_version' => $this->detectVersion( $this->source_root, $old_manifest ),
                'target_version' => $this->detectVersion( $this->target_root, $target_manifest ),
                'checks' => $checks,
                'errors' => array_values( array_unique( $errors ) ),
                'warnings' => array_values( array_unique( $warnings ) ),
                'inventory' => $inventory,
                'discarded_paths' => $this->presentPolicyPaths( TinyMashDeployPolicy::UPGRADE_DISCARD_PATHS ),
                'review_paths' => $review['items'],
                'modified_managed_files' => $review['modified_managed_files'],
                'extensions' => $extensions,
                'target_audit' => $target_audit,
            ]
        );
    }

    public function prepare() : array {
        $runtime_directory = $this->targetPath( 'data/runtime' );
        if ( ! is_dir( $runtime_directory ) || ! is_writable( $runtime_directory ) ) {
            throw new \RuntimeException( 'The new release data/runtime directory is not writable.' );
        }

        $lock_filename = $runtime_directory . DIRECTORY_SEPARATOR . 'upgrade-prepare.lock';
        $lock_handle = @ fopen( $lock_filename, 'c+b' );
        if ( $lock_handle === false || ! @ flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $lock_handle ) ) {
                fclose( $lock_handle );
            }
            throw new \RuntimeException( 'Another upgrade preparation is already running.' );
        }

        $stage_directory = '';
        try {
            $inspection = $this->inspect();
            if ( empty( $inspection['ok'] ) ) {
                throw new \RuntimeException( 'Upgrade preparation preflight failed.' );
            }
            $inventory = (array) ( $inspection['inventory'] ?? [] );

            $stage_directory = $runtime_directory . DIRECTORY_SEPARATOR . 'upgrade-stage-' . bin2hex( random_bytes( 8 ) );
            if ( ! @ mkdir( $stage_directory, 0700 ) ) {
                throw new \RuntimeException( 'Unable to create the upgrade staging directory.' );
            }

            $copied_files = 0;
            $copied_bytes = 0;
            foreach ( (array) ( $inventory['directories'] ?? [] ) as $relative_path => $mode ) {
                $stage_path = $this->stagePath( $stage_directory, (string) $relative_path );
                if ( ! is_dir( $stage_path ) && ! @ mkdir( $stage_path, 0700, true ) ) {
                    throw new \RuntimeException( 'Unable to stage directory: ' . $relative_path );
                }
            }

            foreach ( (array) ( $inventory['file_inventory'] ?? [] ) as $relative_path => $metadata ) {
                $source_path = $this->sourcePath( (string) $relative_path );
                $stage_path = $this->stagePath( $stage_directory, (string) $relative_path );
                $this->copyVerifiedFile( $source_path, $stage_path, (array) $metadata, (string) $relative_path );
                $copied_files++;
                $copied_bytes += (int) ( $metadata['bytes'] ?? 0 );
            }

            $directory_modes = (array) ( $inventory['directories'] ?? [] );
            uksort( $directory_modes, static fn( string $left, string $right ) : int => substr_count( $right, '/' ) <=> substr_count( $left, '/' ) );
            foreach ( $directory_modes as $relative_path => $mode ) {
                @ chmod( $this->stagePath( $stage_directory, (string) $relative_path ), (int) $mode );
            }

            $current_inventory = $this->buildPreserveInventory();
            if ( $this->inventorySignature( $inventory ) !== $this->inventorySignature( $current_inventory ) ) {
                throw new \RuntimeException( 'The old installation changed while it was being copied.' );
            }

            $this->commitStagedState( $stage_directory );
            $stage_directory = '';

            try {
                $audit = ( new TinyMashDeployAuditService( $this->target_root ) )->audit();
            } catch ( \Throwable $exception ) {
                $this->rollbackCommittedState();
                throw new \RuntimeException( 'The prepared release could not be audited; copied state was removed.', 0, $exception );
            }
            if ( empty( $audit['ok'] ) ) {
                $this->rollbackCommittedState();
                throw new \RuntimeException( 'The prepared release failed deploy:audit; copied state was removed.' );
            }

            return(
                [
                    'ok' => true,
                    'source_root' => $this->source_root,
                    'target_root' => $this->target_root,
                    'copied_files' => $copied_files,
                    'copied_bytes' => $copied_bytes,
                    'audit' => $audit,
                    'inspection' => $inspection,
                ]
            );
        } catch ( \Throwable $exception ) {
            if ( $stage_directory !== '' ) {
                $this->removeTree( $stage_directory );
            }
            throw $exception;
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
            @ unlink( $lock_filename );
        }
    }

    protected function resolveRoot( string $path, string $label ) : string {
        $path = rtrim( trim( $path ), DIRECTORY_SEPARATOR );
        if ( $path === '' || str_contains( $path, "\0" ) || is_link( $path ) || ! is_dir( $path ) || ! is_readable( $path ) ) {
            throw new \InvalidArgumentException( $label . ' must be a readable real directory.' );
        }
        $real_path = realpath( $path );
        if ( ! is_string( $real_path ) || $real_path === '' ) {
            throw new \RuntimeException( 'Unable to resolve ' . strtolower( $label ) . '.' );
        }
        return( rtrim( $real_path, DIRECTORY_SEPARATOR ) );
    }

    protected function addCheck( array &$checks, array &$errors, string $label, bool $ok, string $detail ) : void {
        $checks[] = [ 'check' => $label, 'ok' => $ok, 'detail' => $detail ];
        if ( ! $ok ) {
            $errors[] = $detail;
        }
    }

    protected function loadDeployManifest( string $root, bool $required, array &$warnings ) : array {
        $filename = $root . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            if ( ! $required ) {
                $warnings[] = 'The old installation has no readable version-2 deploy manifest; unmanaged-path reporting is conservative.';
            }
            return( [] );
        }
        $size = @ filesize( $filename );
        if ( ! is_int( $size ) || $size < 1 || $size > 8 * 1024 * 1024 ) {
            if ( ! $required ) {
                $warnings[] = 'The old deploy manifest is invalid; unmanaged-path reporting is conservative.';
            }
            return( [] );
        }
        try {
            $manifest = json_decode( (string) file_get_contents( $filename ), true, 64, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            $manifest = [];
        }
        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2
            || ! is_array( $manifest['inventory']['files'] ?? null ) ) {
            if ( ! $required ) {
                $warnings[] = 'The old deploy manifest is invalid; unmanaged-path reporting is conservative.';
            }
            return( [] );
        }
        return( $manifest );
    }

    protected function inspectEmptyTargetState( array &$errors ) : bool {
        $ok = true;
        $config_path = $this->targetPath( 'app/config/tinymash.json' );
        if ( file_exists( $config_path ) || is_link( $config_path ) ) {
            $errors[] = 'The new release already contains app/config/tinymash.json.';
            $ok = false;
        }

        foreach ( array_slice( TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS, 1 ) as $relative_path ) {
            $path = $this->targetPath( $relative_path );
            if ( is_link( $path ) || ! is_dir( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
                $errors[] = 'The new release state path is unavailable: ' . $relative_path;
                $ok = false;
                continue;
            }
            $iterator = new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS );
            if ( $iterator->valid() ) {
                $errors[] = 'The new release state path is not empty: ' . $relative_path;
                $ok = false;
            }
        }

        $runtime_path = $this->targetPath( 'data/runtime' );
        if ( is_link( $runtime_path ) || ! is_dir( $runtime_path ) || ! is_writable( $runtime_path ) ) {
            $errors[] = 'The new release data/runtime directory is unavailable.';
            $ok = false;
        }
        return( $ok );
    }

    protected function loadSourceConfig( array &$errors ) : array {
        $filename = $this->sourcePath( 'app/config/tinymash.json' );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            $errors[] = 'The old installation config is not a readable real file.';
            return( [] );
        }
        $size = @ filesize( $filename );
        if ( ! is_int( $size ) || $size < 2 || $size > 8 * 1024 * 1024 ) {
            $errors[] = 'The old installation config size is invalid.';
            return( [] );
        }
        $json = file_get_contents( $filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            $errors[] = 'The old installation config is empty.';
            return( [] );
        }
        try {
            $config = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
        } catch ( \Throwable ) {
            $errors[] = 'The old installation config is invalid JSON.';
            return( [] );
        }
        if ( ! is_array( $config ) ) {
            $errors[] = 'The old installation config must contain a JSON object.';
            return( [] );
        }
        return( $config );
    }

    protected function buildPreserveInventory() : array {
        $file_inventory = [];
        $directories = [];
        $bytes = 0;

        $config_relative_path = TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS[0];
        $this->addInventoryFile( $config_relative_path, $file_inventory, $bytes );

        foreach ( array_slice( TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS, 1 ) as $relative_root ) {
            $root_path = $this->sourcePath( $relative_root );
            if ( is_link( $root_path ) || ! is_dir( $root_path ) || ! is_readable( $root_path ) ) {
                throw new \RuntimeException( 'Old installation state path is unavailable: ' . $relative_root );
            }
            $root_mode = @ fileperms( $root_path );
            $directories[$relative_root] = is_int( $root_mode ) ? $root_mode & 0777 : 0750;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root_path, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $item ) {
                $relative_path = $this->relativePath( $this->source_root, $item->getPathname() );
                if ( $item->isLink() ) {
                    throw new \RuntimeException( 'Symbolic links are not allowed in authoritative state: ' . $relative_path );
                }
                if ( $item->isDir() ) {
                    $mode = $item->getPerms();
                    $directories[$relative_path] = $mode & 0777;
                    continue;
                }
                if ( ! $item->isFile() || ! $item->isReadable() ) {
                    throw new \RuntimeException( 'Authoritative state contains an unreadable or special file: ' . $relative_path );
                }
                $this->addInventoryFile( $relative_path, $file_inventory, $bytes );
                if ( count( $file_inventory ) > self::FILE_COUNT_LIMIT ) {
                    throw new \RuntimeException( 'Authoritative state contains too many files for one upgrade operation.' );
                }
            }
        }

        ksort( $file_inventory, SORT_STRING );
        ksort( $directories, SORT_STRING );
        return(
            [
                'files' => count( $file_inventory ),
                'directories_count' => count( $directories ),
                'bytes' => $bytes,
                'file_inventory' => $file_inventory,
                'directories' => $directories,
            ]
        );
    }

    protected function addInventoryFile( string $relative_path, array &$inventory, int &$bytes ) : void {
        $filename = $this->sourcePath( $relative_path );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'Authoritative state file is unavailable: ' . $relative_path );
        }
        $size = @ filesize( $filename );
        $hash = @ hash_file( 'sha256', $filename );
        $mode = @ fileperms( $filename );
        if ( ! is_int( $size ) || ! is_string( $hash ) || preg_match( '/^[a-f0-9]{64}$/', $hash ) !== 1 ) {
            throw new \RuntimeException( 'Unable to inventory authoritative state file: ' . $relative_path );
        }
        if ( str_ends_with( strtolower( $relative_path ), '.json' ) ) {
            if ( $size > self::JSON_VALIDATION_LIMIT ) {
                throw new \RuntimeException( 'Authoritative JSON is too large to validate safely: ' . $relative_path );
            }
            $json = file_get_contents( $filename );
            if ( ! is_string( $json ) || ! json_validate( $json, 128 ) ) {
                throw new \RuntimeException( 'Authoritative state contains invalid JSON: ' . $relative_path );
            }
        }
        $inventory[$relative_path] = [
            'bytes' => $size,
            'sha256' => $hash,
            'mode' => is_int( $mode ) ? $mode & 0777 : 0640,
        ];
        $bytes += $size;
    }

    protected function buildReviewReport( array $old_manifest, array &$warnings ) : array {
        $managed = [];
        $managed_metadata = [];
        foreach ( (array) ( $old_manifest['inventory']['files'] ?? [] ) as $relative_path => $metadata ) {
            if ( is_string( $relative_path ) && is_array( $metadata ) ) {
                $managed[$relative_path] = true;
                $managed_metadata[$relative_path] = $metadata;
            }
        }

        $items = [];
        $modified_managed_files = [];
        $conservative_skip_roots = [ 'app', 'bin', 'samples', 'vendor' ];
        $directory_iterator = new \RecursiveDirectoryIterator( $this->source_root, \FilesystemIterator::SKIP_DOTS );
        $filter_iterator = new \RecursiveCallbackFilterIterator(
            $directory_iterator,
            function( \SplFileInfo $item ) : bool {
                $relative_path = $this->relativePath( $this->source_root, $item->getPathname() );
                return( ! $this->isPolicyPath( $relative_path ) );
            }
        );
        $iterator = new \RecursiveIteratorIterator( $filter_iterator, \RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $iterator as $item ) {
            $relative_path = $this->relativePath( $this->source_root, $item->getPathname() );
            if ( $relative_path === 'deploy-manifest.json' ) {
                continue;
            }

            $top_level = explode( '/', $relative_path, 2 )[0];
            if ( $managed === [] && in_array( $top_level, $conservative_skip_roots, true ) ) {
                continue;
            }

            if ( $item->isLink() ) {
                $review_root = $this->reviewRootForPath( $relative_path );
                $items[$review_root] = [
                    'path' => $review_root,
                    'type' => 'symlink',
                    'files' => 0,
                    'bytes' => 0,
                    'guidance' => 'Symbolic link; target was not followed. Review manually.',
                ];
                continue;
            }

            if ( isset( $managed[$relative_path] ) ) {
                if ( $item->isFile() ) {
                    $expected = (array) ( $managed_metadata[$relative_path] ?? [] );
                    $actual_size = @ filesize( $item->getPathname() );
                    $actual_hash = @ hash_file( 'sha256', $item->getPathname() );
                    if ( ! is_int( $actual_size )
                        || ! is_string( $actual_hash )
                        || $actual_size !== (int) ( $expected['bytes'] ?? -1 )
                        || ! hash_equals( strtolower( (string) ( $expected['sha256'] ?? '' ) ), $actual_hash ) ) {
                        $modified_managed_files[] = $relative_path;
                    }
                }
                continue;
            }
            if ( $item->isDir() ) {
                $review_root = $this->reviewRootForPath( $relative_path );
                if ( $review_root === $relative_path
                    && ! file_exists( $this->targetPath( $relative_path ) )
                    && ! $this->manifestOwnsPrefix( $managed, $relative_path )
                    && ! isset( $items[$review_root] ) ) {
                    $items[$review_root] = [
                        'path' => $review_root,
                        'type' => 'directory',
                        'files' => 0,
                        'bytes' => 0,
                        'guidance' => $this->reviewGuidance( $review_root ),
                    ];
                }
                continue;
            }

            $review_root = $this->reviewRootForPath( $relative_path );
            if ( ! isset( $items[$review_root] ) ) {
                $review_source = $this->sourcePath( $review_root );
                $items[$review_root] = [
                    'path' => $review_root,
                    'type' => is_link( $review_source ) ? 'symlink' : ( is_file( $review_source ) ? 'file' : 'directory' ),
                    'files' => 0,
                    'bytes' => 0,
                    'guidance' => $this->reviewGuidance( $review_root ),
                ];
            }
            if ( $item->isFile() ) {
                $items[$review_root]['files']++;
                $size = @ filesize( $item->getPathname() );
                if ( is_int( $size ) ) {
                    $items[$review_root]['bytes'] += $size;
                }
            }
        }

        foreach ( new \FilesystemIterator( $this->source_root, \FilesystemIterator::SKIP_DOTS ) as $item ) {
            $relative_path = $this->relativePath( $this->source_root, $item->getPathname() );
            if ( $this->isPolicyPath( $relative_path ) || file_exists( $this->targetPath( $relative_path ) ) ) {
                continue;
            }
            if ( $item->isDir() && ! isset( $items[$relative_path] ) && ! $this->manifestOwnsPrefix( $managed, $relative_path ) ) {
                $items[$relative_path] = [
                    'path' => $relative_path,
                    'type' => 'directory',
                    'files' => 0,
                    'bytes' => 0,
                    'guidance' => $this->reviewGuidance( $relative_path ),
                ];
            }
        }

        ksort( $items, SORT_STRING );
        sort( $modified_managed_files, SORT_STRING );
        if ( count( $items ) > 500 ) {
            $warnings[] = 'The unmanaged-path report was limited to 500 entries.';
            $items = array_slice( $items, 0, 500, true );
        }
        return(
            [
                'items' => array_values( $items ),
                'modified_managed_files' => array_values( array_unique( $modified_managed_files ) ),
            ]
        );
    }

    protected function inspectExtensions( array $config, array $old_manifest ) : array {
        $available_plugins = $this->directoryKeys( [
            $this->targetPath( 'app/plugins' ),
            $this->targetPath( 'plugins' ),
        ] );
        $available_themes = $this->directoryKeys( [
            $this->targetPath( 'app/themes/public' ),
            $this->targetPath( 'themes' ),
        ] );

        $active_plugins = [];
        foreach ( (array) ( $config['plugins']['states'] ?? [] ) as $plugin_key => $active ) {
            $plugin_key = strtolower( trim( (string) $plugin_key ) );
            if ( $plugin_key !== '' && $active ) {
                $active_plugins[$plugin_key] = true;
            }
        }
        foreach ( (array) ( $old_manifest['includes']['active_plugins'] ?? [] ) as $plugin_key ) {
            $plugin_key = strtolower( trim( (string) $plugin_key ) );
            if ( $plugin_key !== '' ) {
                $active_plugins[$plugin_key] = true;
            }
        }
        $missing_active_plugins = [];
        foreach ( array_keys( $active_plugins ) as $plugin_key ) {
            if ( ! isset( $available_plugins[$plugin_key] ) ) {
                $missing_active_plugins[] = $plugin_key;
            }
        }

        $selected_themes = [];
        $site_theme = strtolower( trim( (string) ( $config['themes']['public']['current'] ?? '' ) ) );
        if ( $site_theme !== '' ) {
            $selected_themes[$site_theme] = true;
        }
        $users_path = $this->sourcePath( 'users' );
        if ( is_dir( $users_path ) && ! is_link( $users_path ) ) {
            foreach ( glob( $users_path . DIRECTORY_SEPARATOR . '*.json' ) ?: [] as $filename ) {
                $json = @ file_get_contents( $filename );
                $user = is_string( $json ) ? json_decode( $json, true ) : null;
                $theme_key = is_array( $user ) ? strtolower( trim( (string) ( $user['public_theme_key'] ?? '' ) ) ) : '';
                if ( $theme_key !== '' && $theme_key !== 'inherit' ) {
                    $selected_themes[$theme_key] = true;
                }
            }
        }
        $missing_selected_themes = [];
        foreach ( array_keys( $selected_themes ) as $theme_key ) {
            if ( ! isset( $available_themes[$theme_key] ) ) {
                $missing_selected_themes[] = $theme_key;
            }
        }

        sort( $missing_active_plugins, SORT_STRING );
        sort( $missing_selected_themes, SORT_STRING );
        return(
            [
                'missing_active_plugins' => $missing_active_plugins,
                'missing_selected_themes' => $missing_selected_themes,
                'old_plugin_directories' => $this->extensionDirectories( 'plugins' ),
                'old_theme_directories' => $this->extensionDirectories( 'themes' ),
            ]
        );
    }

    protected function directoryKeys( array $roots ) : array {
        $keys = [];
        foreach ( $roots as $root ) {
            if ( ! is_dir( $root ) || is_link( $root ) ) {
                continue;
            }
            foreach ( new \FilesystemIterator( $root, \FilesystemIterator::SKIP_DOTS ) as $item ) {
                if ( $item->isDir() && ! $item->isLink() ) {
                    $keys[strtolower( $item->getBasename() )] = true;
                }
            }
        }
        return( $keys );
    }

    protected function extensionDirectories( string $relative_root ) : array {
        $root = $this->sourcePath( $relative_root );
        if ( ! is_dir( $root ) || is_link( $root ) ) {
            return( [] );
        }
        $directories = [];
        foreach ( new \FilesystemIterator( $root, \FilesystemIterator::SKIP_DOTS ) as $item ) {
            if ( $item->isLink() ) {
                $directories[] = $relative_root . '/' . $item->getBasename() . ' [symlink]';
            } elseif ( $item->isDir() ) {
                $directories[] = $relative_root . '/' . $item->getBasename();
            }
        }
        sort( $directories, SORT_STRING );
        return( $directories );
    }

    protected function copyVerifiedFile( string $source, string $target, array $expected, string $relative_path ) : void {
        if ( is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) ) {
            throw new \RuntimeException( 'Source state changed before copy: ' . $relative_path );
        }
        $target_directory = dirname( $target );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0700, true ) ) {
            throw new \RuntimeException( 'Unable to create staging path for: ' . $relative_path );
        }

        $source_handle = @ fopen( $source, 'rb' );
        $target_handle = @ fopen( $target, 'xb' );
        if ( $source_handle === false || $target_handle === false ) {
            if ( is_resource( $source_handle ) ) {
                fclose( $source_handle );
            }
            if ( is_resource( $target_handle ) ) {
                fclose( $target_handle );
            }
            throw new \RuntimeException( 'Unable to stage file: ' . $relative_path );
        }

        $hash = hash_init( 'sha256' );
        $bytes = 0;
        $complete = false;
        try {
            while ( ! feof( $source_handle ) ) {
                $chunk = fread( $source_handle, 1024 * 1024 );
                if ( ! is_string( $chunk ) || ( $chunk === '' && ! feof( $source_handle ) ) ) {
                    break;
                }
                if ( $chunk === '' ) {
                    continue;
                }
                hash_update( $hash, $chunk );
                $offset = 0;
                $length = strlen( $chunk );
                while ( $offset < $length ) {
                    $written = fwrite( $target_handle, substr( $chunk, $offset ) );
                    if ( ! is_int( $written ) || $written < 1 ) {
                        break 2;
                    }
                    $offset += $written;
                    $bytes += $written;
                }
            }
            $complete = feof( $source_handle ) && fflush( $target_handle );
            if ( $complete && function_exists( 'fsync' ) ) {
                $complete = fsync( $target_handle );
            }
        } finally {
            fclose( $source_handle );
            fclose( $target_handle );
        }

        $actual_hash = hash_final( $hash );
        if ( ! $complete
            || $bytes !== (int) ( $expected['bytes'] ?? -1 )
            || ! hash_equals( (string) ( $expected['sha256'] ?? '' ), $actual_hash )
            || ! hash_equals( $actual_hash, (string) @ hash_file( 'sha256', $target ) ) ) {
            @ unlink( $target );
            throw new \RuntimeException( 'Source state changed or failed verification: ' . $relative_path );
        }
        @ chmod( $target, (int) ( $expected['mode'] ?? 0640 ) );
    }

    protected function commitStagedState( string $stage_directory ) : void {
        $committed = [];
        try {
            foreach ( array_slice( TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS, 1 ) as $relative_path ) {
                $target = $this->targetPath( $relative_path );
                $stage = $this->stagePath( $stage_directory, $relative_path );
                if ( ! @ rmdir( $target ) || ! @ rename( $stage, $target ) ) {
                    throw new \RuntimeException( 'Unable to commit staged state path: ' . $relative_path );
                }
                $committed[] = $relative_path;
            }

            $config_relative_path = TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS[0];
            $config_stage = $this->stagePath( $stage_directory, $config_relative_path );
            $config_target = $this->targetPath( $config_relative_path );
            if ( ! @ link( $config_stage, $config_target ) ) {
                throw new \RuntimeException( 'Unable to commit app/config/tinymash.json.' );
            }
            if ( ! @ unlink( $config_stage ) ) {
                @ unlink( $config_target );
                throw new \RuntimeException( 'Unable to commit app/config/tinymash.json.' );
            }
            $committed[] = $config_relative_path;
            $this->removeTree( $stage_directory );
        } catch ( \Throwable $exception ) {
            $this->rollbackCommittedState( $committed );
            throw $exception;
        }
    }

    protected function rollbackCommittedState( ?array $paths = null ) : void {
        $paths ??= TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS;
        foreach ( array_reverse( $paths ) as $relative_path ) {
            $target = $this->targetPath( $relative_path );
            if ( is_dir( $target ) && ! is_link( $target ) ) {
                $this->removeTree( $target );
                @ mkdir( $target, 0750, true );
            } elseif ( file_exists( $target ) || is_link( $target ) ) {
                @ unlink( $target );
            }
        }
    }

    protected function removeTree( string $path ) : void {
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return;
        }
        if ( is_link( $path ) || is_file( $path ) ) {
            @ unlink( $path );
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isLink() || $item->isFile() ) {
                @ unlink( $item->getPathname() );
            } else {
                @ rmdir( $item->getPathname() );
            }
        }
        @ rmdir( $path );
    }

    protected function inventorySignature( array $inventory ) : string {
        return( hash( 'sha256', serialize( [ $inventory['file_inventory'] ?? [], $inventory['directories'] ?? [] ] ) ) );
    }

    protected function presentPolicyPaths( array $paths ) : array {
        $present = [];
        foreach ( $paths as $relative_path ) {
            if ( file_exists( $this->sourcePath( $relative_path ) ) || is_link( $this->sourcePath( $relative_path ) ) ) {
                $present[] = $relative_path;
            }
        }
        return( $present );
    }

    protected function isPolicyPath( string $relative_path ) : bool {
        foreach ( array_merge( TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS, TinyMashDeployPolicy::UPGRADE_DISCARD_PATHS, TinyMashDeployPolicy::UPGRADE_REVIEW_PATHS ) as $policy_path ) {
            if ( $relative_path === $policy_path || str_starts_with( $relative_path, $policy_path . '/' ) ) {
                return( true );
            }
        }
        return( false );
    }

    protected function manifestOwnsPrefix( array $managed, string $relative_path ) : bool {
        $prefix = rtrim( $relative_path, '/' ) . '/';
        foreach ( array_keys( $managed ) as $managed_path ) {
            if ( str_starts_with( $managed_path, $prefix ) ) {
                return( true );
            }
        }
        return( false );
    }

    protected function reviewRootForPath( string $relative_path ) : string {
        $segments = explode( '/', $relative_path );
        $candidate = '';
        foreach ( $segments as $index => $segment ) {
            $candidate = $candidate === '' ? $segment : $candidate . '/' . $segment;
            if ( ! file_exists( $this->targetPath( $candidate ) ) && ! is_link( $this->targetPath( $candidate ) ) ) {
                return( $candidate );
            }
            if ( $index === count( $segments ) - 1 ) {
                return( $candidate );
            }
        }
        return( $relative_path );
    }

    protected function reviewGuidance( string $relative_path ) : string {
        $lower = strtolower( $relative_path );
        $basename = strtolower( basename( $relative_path ) );
        if ( in_array( $basename, [ 'tmp', '_tmp', 'cache', '_cache' ], true ) ) {
            return( 'Likely temporary; review before copying.' );
        }
        if ( str_contains( $basename, 'log' ) ) {
            return( 'Recreate or update service log paths.' );
        }
        if ( in_array( $basename, [ 'uploads', '_uploads' ], true ) ) {
            return( 'Operator-managed uploads; review manually.' );
        }
        if ( str_contains( $basename, 'auth' ) || str_contains( $basename, 'passwd' ) ) {
            return( 'Sensitive authentication material; handle manually.' );
        }
        if ( str_contains( $basename, 'backup' ) ) {
            return( 'Retain outside the active release and review manually.' );
        }
        if ( str_starts_with( $lower, 'public/' ) ) {
            return( 'Unmanaged public files; review exposure and copy manually if needed.' );
        }
        if ( str_starts_with( $basename, '.' ) ) {
            return( 'Hidden or host-specific path; review manually.' );
        }
        return( 'Not managed by the new release; review manually.' );
    }

    protected function detectVersion( string $root, array $manifest ) : string {
        $manifest_version = trim( (string) ( $manifest['application']['version'] ?? '' ) );
        if ( $manifest_version !== '' ) {
            return( $manifest_version );
        }
        $globals_filename = $root . DIRECTORY_SEPARATOR . 'app/config/globals.inc.php';
        if ( is_file( $globals_filename ) && is_readable( $globals_filename ) && (int) @ filesize( $globals_filename ) <= 65536 ) {
            $source = (string) @ file_get_contents( $globals_filename );
            if ( preg_match( "/const\\s+APP_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]/", $source, $matches ) === 1 ) {
                return( trim( (string) $matches[1] ) );
            }
        }
        return( 'unknown' );
    }

    protected function relativePath( string $root, string $path ) : string {
        $root_prefix = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
        $normalized_path = str_replace( '\\', '/', $path );
        if ( ! str_starts_with( $normalized_path, $root_prefix ) ) {
            throw new \RuntimeException( 'Filesystem path escaped the installation root.' );
        }
        $relative_path = substr( $normalized_path, strlen( $root_prefix ) );
        if ( $relative_path === ''
            || strlen( $relative_path ) > 4096
            || preg_match( '/[\x00-\x1F\x7F]/', $relative_path ) === 1 ) {
            throw new \RuntimeException( 'Installation contains an unsafe path.' );
        }
        foreach ( explode( '/', $relative_path ) as $segment ) {
            if ( $segment === '' || $segment === '.' || $segment === '..' ) {
                throw new \RuntimeException( 'Installation contains an unsafe path.' );
            }
        }
        return( $relative_path );
    }

    protected function sourcePath( string $relative_path ) : string {
        return( $this->source_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
    }

    protected function targetPath( string $relative_path ) : string {
        return( $this->target_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
    }

    protected function stagePath( string $stage_directory, string $relative_path ) : string {
        return( $stage_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
    }

    protected function formatBytes( int $bytes ) : string {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $value = max( 0, $bytes );
        $unit = 0;
        while ( $value >= 1024 && $unit < count( $units ) - 1 ) {
            $value /= 1024;
            $unit++;
        }
        return( $unit === 0 ? (string) (int) $value . ' B' : number_format( $value, 1 ) . ' ' . $units[$unit] );
    }
}
