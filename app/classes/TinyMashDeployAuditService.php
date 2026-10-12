<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployPolicy.php';

final class TinyMashDeployAuditService {

    protected string $target_directory;
    protected string $manifest_filename;
    protected bool $reject_unmanaged_paths;

    public function __construct( string $target_directory, string $manifest_filename = '', bool $reject_unmanaged_paths = false ) {
        $this->target_directory = rtrim( trim( $target_directory ), DIRECTORY_SEPARATOR );
        $this->manifest_filename = trim( $manifest_filename );
        $this->reject_unmanaged_paths = $reject_unmanaged_paths;
    }

    public function audit() : array {
        $target_directory = $this->resolveTargetDirectory();
        $manifest_filename = $this->resolveManifestFilename( $target_directory );
        $manifest = $this->loadManifest( $manifest_filename );
        $inventory = $this->normalizeInventory( $manifest );
        $managed_roots = $this->buildManagedRoots( $manifest, $inventory );

        $missing_files = [];
        $missing_directories = [];
        $changed_files = [];
        $unknown_files = [];
        $unsafe_paths = [];
        $unmanaged_paths = [];
        $checked_files = 0;

        foreach ( $inventory as $relative_path => $expected ) {
            $target_file = $this->buildTargetPath( $target_directory, $relative_path );
            if ( $this->pathHasSymlink( $target_directory, $relative_path ) ) {
                $unsafe_paths[] = $relative_path;
                continue;
            }
            if ( ! file_exists( $target_file ) ) {
                $missing_files[] = $relative_path;
                continue;
            }
            if ( ! is_file( $target_file ) || ! is_readable( $target_file ) ) {
                $unsafe_paths[] = $relative_path;
                continue;
            }

            $checked_files++;
            $actual_size = @ filesize( $target_file );
            if ( ! is_int( $actual_size ) || $actual_size !== $expected['bytes'] ) {
                $changed_files[] = $relative_path;
                continue;
            }
            $actual_hash = @ hash_file( 'sha256', $target_file );
            if ( ! is_string( $actual_hash ) || ! hash_equals( $expected['sha256'], $actual_hash ) ) {
                $changed_files[] = $relative_path;
            }
        }

        foreach ( (array) ( $manifest['includes']['empty_directories'] ?? [] ) as $relative_path ) {
            $relative_path = $this->normalizeSafeRelativePath( (string) $relative_path );
            $target_path = $this->buildTargetPath( $target_directory, $relative_path );
            if ( $this->pathHasSymlink( $target_directory, $relative_path ) ) {
                $unsafe_paths[] = $relative_path;
            } elseif ( ! is_dir( $target_path ) ) {
                $missing_directories[] = $relative_path;
            }
        }

        $ignored_paths = 0;
        $directory_iterator = new \RecursiveDirectoryIterator( $target_directory, \FilesystemIterator::SKIP_DOTS );
        $filter_iterator = new \RecursiveCallbackFilterIterator(
            $directory_iterator,
            function( \SplFileInfo $item ) use ( $target_directory, $inventory, $managed_roots, &$ignored_paths, &$unmanaged_paths ) : bool {
                $relative_path = $this->relativePath( $target_directory, $item->getPathname() );
                if ( $item->isLink() ) {
                    return( true );
                }
                if ( TinyMashDeployPolicy::isAuditIgnoredPath( $relative_path ) ) {
                    $ignored_paths++;
                    return( false );
                }
                if ( ! $this->isManagedPath( $relative_path, $inventory, $managed_roots ) ) {
                    $unmanaged_paths[] = $relative_path;
                    return( false );
                }

                return( true );
            }
        );
        $iterator = new \RecursiveIteratorIterator(
            $filter_iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $iterator as $item ) {
            $relative_path = $this->relativePath( $target_directory, $item->getPathname() );
            if ( $item->isLink() ) {
                if ( $this->isManagedPath( $relative_path, $inventory, $managed_roots ) ) {
                    $unsafe_paths[] = $relative_path;
                } else {
                    $unmanaged_paths[] = $relative_path;
                }
                continue;
            }
            if ( $item->isDir() ) {
                continue;
            }
            if ( ! array_key_exists( $relative_path, $inventory ) ) {
                $unknown_files[] = $relative_path;
            }
        }

        foreach ( [ &$missing_files, &$missing_directories, &$changed_files, &$unknown_files, &$unsafe_paths, &$unmanaged_paths ] as &$paths ) {
            $paths = array_values( array_unique( $paths ) );
            sort( $paths, SORT_STRING );
        }
        unset( $paths );

        $ok = $missing_files === []
            && $missing_directories === []
            && $changed_files === []
            && $unknown_files === []
            && $unsafe_paths === []
            && ( ! $this->reject_unmanaged_paths || $unmanaged_paths === [] );

        return(
            [
                'ok' => $ok,
                'status' => $ok ? 'ok' : 'differences',
                'target_directory' => $target_directory,
                'manifest_filename' => $manifest_filename,
                'manifest_built_at_utc' => $this->normalizeBuiltAtUtc( $manifest['built_at_utc'] ?? null ),
                'expected_files' => count( $inventory ),
                'checked_files' => $checked_files,
                'ignored_paths' => $ignored_paths,
                'missing_files' => $missing_files,
                'missing_directories' => $missing_directories,
                'changed_files' => $changed_files,
                'unknown_files' => $unknown_files,
                'unsafe_paths' => $unsafe_paths,
                'unmanaged_paths' => $unmanaged_paths,
                'upgrade' => is_array( $manifest['upgrade'] ?? null ) ? $manifest['upgrade'] : [],
            ]
        );
    }

    protected function resolveTargetDirectory() : string {
        if ( $this->target_directory === '' ) {
            throw new \InvalidArgumentException( 'target_directory' );
        }
        if ( is_link( $this->target_directory ) || ! is_dir( $this->target_directory ) || ! is_readable( $this->target_directory ) ) {
            throw new \RuntimeException( 'Deploy audit target must be a readable real directory.' );
        }

        $real_target = realpath( $this->target_directory );
        if ( ! is_string( $real_target ) ) {
            throw new \RuntimeException( 'Unable to resolve deploy audit target.' );
        }

        return( rtrim( $real_target, DIRECTORY_SEPARATOR ) );
    }

    protected function resolveManifestFilename( string $target_directory ) : string {
        $manifest_filename = $this->manifest_filename !== ''
            ? $this->manifest_filename
            : $target_directory . DIRECTORY_SEPARATOR . 'deploy-manifest.json';
        if ( is_link( $manifest_filename ) || ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename ) ) {
            throw new \RuntimeException( 'Deploy audit manifest is not readable.' );
        }

        $real_manifest = realpath( $manifest_filename );
        if ( ! is_string( $real_manifest ) ) {
            throw new \RuntimeException( 'Unable to resolve deploy audit manifest.' );
        }

        return( $real_manifest );
    }

    protected function loadManifest( string $manifest_filename ) : array {
        $manifest_size = @ filesize( $manifest_filename );
        if ( ! is_int( $manifest_size ) || $manifest_size < 1 || $manifest_size > 8 * 1024 * 1024 ) {
            throw new \RuntimeException( 'Deploy audit manifest size is invalid.' );
        }
        $json = @ file_get_contents( $manifest_filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            throw new \RuntimeException( 'Deploy audit manifest is empty.' );
        }

        try {
            $manifest = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
        } catch ( \JsonException ) {
            throw new \RuntimeException( 'Deploy audit manifest is invalid JSON.' );
        }

        if ( ! is_array( $manifest )
            || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-deploy'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 2 ) {
            throw new \RuntimeException( 'Deploy audit requires a version-2 tinymash deploy manifest.' );
        }

        return( $manifest );
    }

    protected function normalizeInventory( array $manifest ) : array {
        if ( (string) ( $manifest['inventory']['algorithm'] ?? '' ) !== 'sha256'
            || ! is_array( $manifest['inventory']['files'] ?? null ) ) {
            throw new \RuntimeException( 'Deploy audit manifest file inventory is invalid.' );
        }
        if ( count( $manifest['inventory']['files'] ) > 50000 ) {
            throw new \RuntimeException( 'Deploy audit manifest file inventory is too large.' );
        }

        $inventory = [];
        foreach ( $manifest['inventory']['files'] as $relative_path => $metadata ) {
            if ( ! is_string( $relative_path ) || ! is_array( $metadata ) ) {
                throw new \RuntimeException( 'Deploy audit manifest contains an invalid file record.' );
            }
            $normalized_path = $this->normalizeSafeRelativePath( $relative_path );
            if ( $normalized_path !== $relative_path || TinyMashDeployPolicy::isAuditIgnoredPath( $normalized_path ) ) {
                throw new \RuntimeException( 'Deploy audit manifest contains an unmanaged file path.' );
            }

            $bytes = $metadata['bytes'] ?? null;
            $sha256 = strtolower( trim( (string) ( $metadata['sha256'] ?? '' ) ) );
            if ( ! is_int( $bytes ) || $bytes < 0 || preg_match( '/^[a-f0-9]{64}$/', $sha256 ) !== 1 ) {
                throw new \RuntimeException( 'Deploy audit manifest contains invalid file metadata.' );
            }

            $inventory[$normalized_path] = [
                'bytes' => $bytes,
                'sha256' => $sha256,
            ];
        }
        ksort( $inventory, SORT_STRING );

        return( $inventory );
    }

    protected function buildManagedRoots( array $manifest, array $inventory ) : array {
        $roots = [];
        foreach ( array_keys( $inventory ) as $relative_path ) {
            if ( str_contains( $relative_path, '/' ) ) {
                $roots[explode( '/', $relative_path, 2 )[0]] = true;
            }
        }
        foreach ( [ 'directories', 'optional_directories', 'empty_directories' ] as $include_key ) {
            foreach ( (array) ( $manifest['includes'][$include_key] ?? [] ) as $relative_path ) {
                $relative_path = $this->normalizeSafeRelativePath( (string) $relative_path );
                $roots[explode( '/', $relative_path, 2 )[0]] = true;
            }
        }
        return( $roots );
    }

    protected function isManagedPath( string $relative_path, array $inventory, array $managed_roots ) : bool {
        if ( array_key_exists( $relative_path, $inventory ) || TinyMashDeployPolicy::isAuditManagedPath( $relative_path ) ) {
            return( true );
        }
        $root = explode( '/', $relative_path, 2 )[0];
        return( isset( $managed_roots[$root] ) );
    }

    protected function normalizeSafeRelativePath( string $relative_path ) : string {
        $relative_path = str_replace( '\\', '/', trim( $relative_path ) );
        if ( $relative_path === ''
            || strlen( $relative_path ) > 4096
            || str_starts_with( $relative_path, '/' )
            || preg_match( '/^[A-Za-z]:\//', $relative_path ) === 1
            || preg_match( '/[\x00-\x1F\x7F]/', $relative_path ) === 1 ) {
            throw new \RuntimeException( 'Deploy audit manifest contains an unsafe file path.' );
        }

        $segments = explode( '/', $relative_path );
        foreach ( $segments as $segment ) {
            if ( $segment === '' || $segment === '.' || $segment === '..' ) {
                throw new \RuntimeException( 'Deploy audit manifest contains an unsafe file path.' );
            }
        }

        return( implode( '/', $segments ) );
    }

    protected function normalizeBuiltAtUtc( mixed $value ) : string {
        if ( ! is_string( $value ) || preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value ) !== 1 ) {
            return( '' );
        }

        return( $value );
    }

    protected function buildTargetPath( string $target_directory, string $relative_path ) : string {
        return( $target_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
    }

    protected function pathHasSymlink( string $target_directory, string $relative_path ) : bool {
        $path = $target_directory;
        foreach ( explode( '/', $relative_path ) as $segment ) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if ( is_link( $path ) ) {
                return( true );
            }
            if ( ! file_exists( $path ) ) {
                return( false );
            }
        }

        return( false );
    }

    protected function relativePath( string $target_directory, string $path ) : string {
        $target_prefix = rtrim( str_replace( '\\', '/', $target_directory ), '/' ) . '/';
        $normalized_path = str_replace( '\\', '/', $path );
        if ( ! str_starts_with( $normalized_path, $target_prefix ) ) {
            throw new \RuntimeException( 'Deploy audit path escaped the target directory.' );
        }

        return( $this->normalizeSafeRelativePath( substr( $normalized_path, strlen( $target_prefix ) ) ) );
    }
}
