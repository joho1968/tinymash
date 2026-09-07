<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashDeployPolicy.php';

final class TinyMashAuthoritativeStateInventory {

    protected const JSON_VALIDATION_LIMIT = 64 * 1024 * 1024;
    protected const FILE_COUNT_LIMIT = 500000;

    protected string $installation_root;

    public function __construct( string $installation_root ) {
        $installation_root = rtrim( trim( $installation_root ), DIRECTORY_SEPARATOR );
        $real_app = @ realpath( $installation_root . DIRECTORY_SEPARATOR . 'app' );
        if ( $installation_root === ''
            || str_contains( $installation_root, "\0" )
            || ! is_string( $real_app )
            || ! hash_equals( rtrim( dirname( $real_app ), DIRECTORY_SEPARATOR ), $installation_root ) ) {
            throw new \InvalidArgumentException( 'The installation root is unavailable.' );
        }
        $this->installation_root = $installation_root;
    }

    public function inspect() : array {
        $file_inventory = [];
        $directories = [];
        $bytes = 0;

        $config_path = TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS[0];
        $this->addFile( $config_path, $file_inventory, $bytes );

        foreach ( array_slice( TinyMashDeployPolicy::UPGRADE_PRESERVE_PATHS, 1 ) as $relative_root ) {
            $root = $this->path( $relative_root );
            if ( is_link( $root ) || ! is_dir( $root ) || ! is_readable( $root ) ) {
                throw new \RuntimeException( 'Authoritative state path is unavailable: ' . $relative_root );
            }
            $mode = @ fileperms( $root );
            $directories[$relative_root] = is_int( $mode ) ? $mode & 0777 : 0750;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $item ) {
                $relative_path = $this->relativePath( $item->getPathname() );
                if ( $item->isLink() ) {
                    throw new \RuntimeException( 'Symbolic links are not allowed in authoritative state: ' . $relative_path );
                }
                if ( $item->isDir() ) {
                    $directories[$relative_path] = $item->getPerms() & 0777;
                    continue;
                }
                if ( ! $item->isFile() || ! $item->isReadable() ) {
                    throw new \RuntimeException( 'Authoritative state contains an unreadable or special file: ' . $relative_path );
                }
                $this->addFile( $relative_path, $file_inventory, $bytes );
                if ( count( $file_inventory ) > self::FILE_COUNT_LIMIT ) {
                    throw new \RuntimeException( 'Authoritative state contains too many files for one update.' );
                }
            }
        }

        ksort( $file_inventory, SORT_STRING );
        ksort( $directories, SORT_STRING );
        return( [
            'sha256' => hash( 'sha256', serialize( [ $file_inventory, $directories ] ) ),
            'files' => count( $file_inventory ),
            'directories_count' => count( $directories ),
            'bytes' => $bytes,
            'file_inventory' => $file_inventory,
            'directories' => $directories,
        ] );
    }

    protected function addFile( string $relative_path, array &$inventory, int &$bytes ) : void {
        $filename = $this->path( $relative_path );
        if ( is_link( $filename ) || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            throw new \RuntimeException( 'Authoritative state file is unavailable: ' . $relative_path );
        }
        $size = @ filesize( $filename );
        $hash = @ hash_file( 'sha256', $filename );
        $mode = @ fileperms( $filename );
        if ( ! is_int( $size ) || ! is_string( $hash ) || preg_match( '/^[a-f0-9]{64}$/D', $hash ) !== 1 ) {
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

    protected function path( string $relative_path ) : string {
        return( $this->installation_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
    }

    protected function relativePath( string $filename ) : string {
        $root = str_replace( '\\', '/', $this->installation_root ) . '/';
        $filename = str_replace( '\\', '/', $filename );
        if ( ! str_starts_with( $filename, $root ) ) {
            throw new \RuntimeException( 'Authoritative state escaped the installation root.' );
        }
        return( substr( $filename, strlen( $root ) ) );
    }
}
