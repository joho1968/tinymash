<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashLockedJsonFile.php';

class TinyMashPluginStateBackup {

    private const ROOT_FILES = [
        'comments' => [ 'state.json', 'submission-state.json', 'queue.json', 'throttle.json', 'privacy.json' ],
        'subscriptions' => [ 'state.json', 'subscribers.json', 'queue.json', 'history.json', 'throttle.json' ],
    ];

    public function __construct( private string $plugin_key, private string $storage_directory ) {
        if ( ! isset( self::ROOT_FILES[$plugin_key] ) ) {
            throw new \InvalidArgumentException( 'plugin_key' );
        }
        $this->storage_directory = rtrim( $storage_directory, DIRECTORY_SEPARATOR );
    }

    public function export( string $scope, string $target_directory, string $author_username = '' ) : int {
        $this->assertScope( $scope, $author_username );
        $files = $this->listFiles( $this->storage_directory, $scope, $author_username );
        $target_directory = rtrim( $target_directory, DIRECTORY_SEPARATOR );
        $this->ensureDirectory( $target_directory );
        foreach ( $files as $relative_path ) {
            $source = $this->storage_directory . DIRECTORY_SEPARATOR . $relative_path;
            $target = $target_directory . DIRECTORY_SEPARATOR . $relative_path;
            $this->ensureDirectory( dirname( $target ) );
            $bytes = @ file_get_contents( $source );
            if ( ! is_string( $bytes ) || ! TinyMashFilesystem::writeFileAtomically( $target, $bytes ) ) {
                throw new \RuntimeException( 'Unable to export plugin state.' );
            }
        }

        $manifest = [
            'format' => 'tinymash-plugin-state',
            'format_version' => 1,
            'plugin' => $this->plugin_key,
            'scope' => $scope,
            'author_username' => $author_username,
            'files' => $files,
        ];
        $encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        if ( ! TinyMashFilesystem::writeFileAtomically( $target_directory . DIRECTORY_SEPARATOR . 'backup.json', $encoded . PHP_EOL ) ) {
            throw new \RuntimeException( 'Unable to export plugin state manifest.' );
        }
        return( count( $files ) );
    }

    public function import( string $scope, string $source_directory, string $author_username = '', bool $replace_existing = false ) : int {
        $this->assertScope( $scope, $author_username );
        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        if ( is_link( $source_directory ) || is_link( dirname( $source_directory ) ) || is_link( $source_directory . DIRECTORY_SEPARATOR . 'entries' ) ) {
            throw new \RuntimeException( 'Plugin backup path is a symbolic link.' );
        }
        $manifest_filename = $source_directory . DIRECTORY_SEPARATOR . 'backup.json';
        if ( ! is_file( $manifest_filename ) ) {
            throw new \RuntimeException( 'Plugin state is missing from this backup.' );
        }
        $this->assertRegularFile( $manifest_filename );
        $manifest = TinyMashLockedJsonFile::readStrict( $manifest_filename );
        if (
            (string) ( $manifest['format'] ?? '' ) !== 'tinymash-plugin-state'
            || (int) ( $manifest['format_version'] ?? 0 ) !== 1
            || (string) ( $manifest['plugin'] ?? '' ) !== $this->plugin_key
            || (string) ( $manifest['scope'] ?? '' ) !== $scope
            || (string) ( $manifest['author_username'] ?? '' ) !== $author_username
            || ! is_array( $manifest['files'] ?? null )
        ) {
            throw new \RuntimeException( 'Plugin state manifest is invalid.' );
        }

        $files = $manifest['files'];
        foreach ( $files as $relative_path ) {
            if ( ! is_string( $relative_path ) || ! $this->isAllowedPath( $relative_path, $scope ) ) {
                throw new \RuntimeException( 'Plugin state manifest contains an invalid path.' );
            }
        }
        if ( array_values( array_unique( $files ) ) !== array_values( $files ) ) {
            throw new \RuntimeException( 'Plugin state manifest contains duplicate files.' );
        }
        $payloads = [];
        foreach ( $files as $relative_path ) {
            $source = $source_directory . DIRECTORY_SEPARATOR . $relative_path;
            $this->assertRegularFile( $source );
            $payloads[$relative_path] = TinyMashLockedJsonFile::readStrict( $source );
            $this->validatePayload( $relative_path, $payloads[$relative_path], $scope, $author_username );
        }

        $existing = $this->listFiles( $this->storage_directory, $scope, $author_username );
        if ( ! $replace_existing && $existing !== [] ) {
            throw new \RuntimeException( 'Plugin state already exists.' );
        }
        foreach ( array_keys( $payloads ) as $relative_path ) {
            $target = $this->storage_directory . DIRECTORY_SEPARATOR . $relative_path;
            $this->assertWritablePath( $target );
            if ( $scope === 'author' && is_file( $target ) ) {
                $record = TinyMashLockedJsonFile::readStrict( $target );
                if ( (string) ( $record['scope'] ?? '' ) !== 'author:' . $author_username ) {
                    throw new \RuntimeException( 'Comment entry ID belongs to another scope.' );
                }
            }
        }
        foreach ( $payloads as $relative_path => $data ) {
            $target = $this->storage_directory . DIRECTORY_SEPARATOR . $relative_path;
            if ( ! TinyMashLockedJsonFile::write( $target, $data, true ) ) {
                throw new \RuntimeException( 'Unable to restore plugin state.' );
            }
        }
        if ( $replace_existing ) {
            foreach ( array_diff( $existing, array_keys( $payloads ) ) as $relative_path ) {
                $target = $this->storage_directory . DIRECTORY_SEPARATOR . $relative_path;
                $this->assertWritablePath( $target );
                if ( ! @ unlink( $target ) ) {
                    throw new \RuntimeException( 'Unable to remove replaced plugin state.' );
                }
            }
        }
        return( count( $payloads ) );
    }

    private function listFiles( string $directory, string $scope, string $author_username ) : array {
        $files = [];
        if ( is_link( $directory ) || is_link( dirname( $directory ) ) ) {
            throw new \RuntimeException( 'Plugin state directory is a symbolic link.' );
        }
        if ( $scope === 'site' ) {
            foreach ( self::ROOT_FILES[$this->plugin_key] as $name ) {
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if ( file_exists( $path ) || is_link( $path ) ) {
                    $this->assertRegularFile( $path );
                    $files[] = $name;
                }
            }
        }
        if ( $this->plugin_key === 'comments' ) {
            $entries = $directory . DIRECTORY_SEPARATOR . 'entries';
            if ( is_link( $entries ) ) {
                throw new \RuntimeException( 'Plugin state directory is a symbolic link.' );
            }
            if ( is_dir( $entries ) ) {
                foreach ( scandir( $entries ) ?: [] as $name ) {
                    if ( ! $this->isAllowedPath( 'entries/' . $name, $scope ) ) {
                        continue;
                    }
                    $path = $entries . DIRECTORY_SEPARATOR . $name;
                    $this->assertRegularFile( $path );
                    if ( $scope === 'site' ) {
                        $files[] = 'entries/' . $name;
                        continue;
                    }
                    $record = TinyMashLockedJsonFile::readStrict( $path );
                    if ( (string) ( $record['entry_id'] ?? '' ) !== basename( $name, '.json' ) ) {
                        throw new \RuntimeException( 'Comment state entry ID does not match its filename.' );
                    }
                    if ( (string) ( $record['scope'] ?? '' ) === 'author:' . $author_username ) {
                        $files[] = 'entries/' . $name;
                    }
                }
            }
        }
        sort( $files, SORT_STRING );
        return( $files );
    }

    private function isAllowedPath( string $path, string $scope ) : bool {
        if ( $scope === 'site' && in_array( $path, self::ROOT_FILES[$this->plugin_key], true ) ) {
            return( true );
        }
        return( $this->plugin_key === 'comments' && preg_match( '#^entries/entry_[a-z0-9_]{1,96}\.json$#', $path ) === 1 );
    }

    private function validatePayload( string $path, array $data, string $scope, string $author_username ) : void {
        if ( $this->plugin_key === 'subscriptions' ) {
            $name = basename( $path, '.json' );
            if ( (string) ( $data['format'] ?? '' ) !== 'tinymash-subscriptions-' . $name || (int) ( $data['format_version'] ?? 0 ) !== 1 ) {
                throw new \RuntimeException( 'Subscription state format is invalid.' );
            }
            return;
        }
        if ( str_starts_with( $path, 'entries/' ) ) {
            $entry_id = basename( $path, '.json' );
            $entry_scope = (string) ( $data['scope'] ?? '' );
            if (
                (int) ( $data['schema_version'] ?? 0 ) !== 1
                || (string) ( $data['entry_id'] ?? '' ) !== $entry_id
                || ! is_array( $data['comments'] ?? null )
                || ( $entry_scope !== 'root' && preg_match( '/^author:[a-z0-9_]{1,64}$/', $entry_scope ) !== 1 )
                || ( $scope === 'author' && $entry_scope !== 'author:' . $author_username )
            ) {
                throw new \RuntimeException( 'Comment state entry is invalid.' );
            }
            return;
        }
        if ( $path === 'state.json' && (int) ( $data['schema_version'] ?? 0 ) !== 1 ) {
            throw new \RuntimeException( 'Comment state format is invalid.' );
        }
    }

    private function assertScope( string $scope, string $author_username ) : void {
        if ( $scope === 'site' && $author_username === '' ) {
            return;
        }
        if ( $scope === 'author' && $this->plugin_key === 'comments' && preg_match( '/^[a-z0-9_]{1,64}$/', $author_username ) === 1 ) {
            return;
        }
        throw new \InvalidArgumentException( 'scope' );
    }

    private function assertRegularFile( string $path ) : void {
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new \RuntimeException( 'Plugin state file is not a readable regular file.' );
        }
    }

    private function assertWritablePath( string $path ) : void {
        if ( is_link( dirname( $this->storage_directory ) ) || is_link( $this->storage_directory ) || is_link( dirname( $path ) ) || is_link( $path ) || is_link( $path . '.lock' ) ) {
            throw new \RuntimeException( 'Plugin state path is a symbolic link.' );
        }
    }

    private function ensureDirectory( string $directory ) : void {
        if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) ) {
            throw new \RuntimeException( 'Unable to create plugin state directory.' );
        }
    }
}
