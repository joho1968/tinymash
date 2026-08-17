<?php
namespace app\classes;

class TinyMashImportPreviewWorkspaceService {

    public const DEFAULT_RETENTION_SECONDS = 604800;

    protected const RETENTION_MARKER_FILENAME = '.keep-preview';
    protected const ACTIVITY_FILENAMES = [ 'preview.json', 'import-state.json', 'media-index.json' ];

    protected array $workspace_definitions = [];
    protected int $retention_seconds;

    public static function forProjectRoot( string $project_root, int $retention_seconds = self::DEFAULT_RETENTION_SECONDS ) : self {
        $project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
        if ( $project_root === '' || str_contains( $project_root, "\0" ) ) {
            throw new \InvalidArgumentException( 'project_root' );
        }

        $workspace_parent = $project_root . DIRECTORY_SEPARATOR . 'tmp';
        if ( is_link( $workspace_parent ) || ! is_dir( $workspace_parent ) ) {
            throw new \InvalidArgumentException( 'project_root' );
        }

        $real_workspace_parent = realpath( $workspace_parent );
        if ( ! is_string( $real_workspace_parent ) || $real_workspace_parent === '' || dirname( $real_workspace_parent ) === $real_workspace_parent ) {
            throw new \InvalidArgumentException( 'project_root' );
        }

        return(
            new self(
                [
                    'blogger-import' => [
                        'directory' => $real_workspace_parent . DIRECTORY_SEPARATOR . 'plugin-blogger-import',
                        'token_prefix' => 'blogger_',
                    ],
                    'bludit-import' => [
                        'directory' => $real_workspace_parent . DIRECTORY_SEPARATOR . 'plugin-bludit-import',
                        'token_prefix' => 'bludit_',
                    ],
                    'wordpress-import' => [
                        'directory' => $real_workspace_parent . DIRECTORY_SEPARATOR . 'plugin-wordpress-import',
                        'token_prefix' => 'wordpress_',
                    ],
                ],
                $retention_seconds
            )
        );
    }

    public function __construct( array $workspace_definitions, int $retention_seconds = self::DEFAULT_RETENTION_SECONDS ) {
        foreach ( $workspace_definitions as $importer_key => $definition ) {
            $importer_key = $this->normalizeImporterKey( (string) $importer_key );
            if ( $importer_key === '' || ! is_array( $definition ) ) {
                continue;
            }

            $directory = rtrim( trim( (string) ( $definition['directory'] ?? '' ) ), DIRECTORY_SEPARATOR );
            $token_prefix = strtolower( trim( (string) ( $definition['token_prefix'] ?? '' ) ) );
            if ( $directory === '' || preg_match( '/^[a-z0-9]+_$/D', $token_prefix ) !== 1 ) {
                continue;
            }

            $this->workspace_definitions[$importer_key] = [
                'directory' => $directory,
                'token_prefix' => $token_prefix,
            ];
        }

        $this->retention_seconds = max( 60, $retention_seconds );
    }

    public function retainPreview( string $importer_key, string $token ) : bool {
        $definition = $this->getDefinition( $importer_key );
        if ( ! is_array( $definition ) || ! $this->tokenMatchesDefinition( $token, $definition ) ) {
            return( false );
        }

        $workspace_root = (string) $definition['directory'];
        $workspace_directory = $workspace_root . DIRECTORY_SEPARATOR . $token;
        if ( is_link( $workspace_root ) || is_link( $workspace_directory ) || ! is_dir( $workspace_directory ) ) {
            return( false );
        }

        $real_root = realpath( $workspace_root );
        $real_workspace = realpath( $workspace_directory );
        if ( ! $this->workspaceIsContained( $real_root, $real_workspace ) ) {
            return( false );
        }

        $preview_filename = $workspace_directory . DIRECTORY_SEPARATOR . 'preview.json';
        if ( is_link( $preview_filename ) || ! is_file( $preview_filename ) ) {
            return( false );
        }

        $marker = json_encode(
            [
                'importer_key' => $this->normalizeImporterKey( $importer_key ),
                'token' => $token,
                'retained_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        return(
            is_string( $marker )
            && TinyMashFilesystem::writeFileAtomically(
                $workspace_directory . DIRECTORY_SEPARATOR . self::RETENTION_MARKER_FILENAME,
                $marker . PHP_EOL
            )
        );
    }

    public function cleanupExpiredPreviews( ?int $current_time = null ) : array {
        $current_time = $current_time ?? time();
        $cutoff = $current_time - $this->retention_seconds;
        $result = [
            'retention_seconds' => $this->retention_seconds,
            'checked_workspaces' => 0,
            'removed_workspaces' => 0,
            'preserved_fresh' => 0,
            'preserved_retained' => 0,
            'skipped_unknown' => 0,
            'skipped_symlinks' => 0,
            'errors' => 0,
        ];

        foreach ( $this->workspace_definitions as $definition ) {
            $workspace_root = (string) $definition['directory'];
            if ( is_link( $workspace_root ) ) {
                $result['skipped_symlinks']++;
                continue;
            }
            if ( ! is_dir( $workspace_root ) ) {
                continue;
            }

            $real_root = realpath( $workspace_root );
            $items = @ scandir( $workspace_root );
            if ( ! is_string( $real_root ) || ! is_array( $items ) ) {
                $result['errors']++;
                continue;
            }

            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) {
                    continue;
                }

                $workspace_directory = $workspace_root . DIRECTORY_SEPARATOR . $item;
                if ( is_link( $workspace_directory ) ) {
                    $result['skipped_symlinks']++;
                    continue;
                }
                if ( ! is_dir( $workspace_directory ) || ! $this->tokenMatchesDefinition( $item, $definition ) ) {
                    $result['skipped_unknown']++;
                    continue;
                }

                $real_workspace = realpath( $workspace_directory );
                if ( ! $this->workspaceIsContained( $real_root, $real_workspace ) ) {
                    $result['errors']++;
                    continue;
                }

                $result['checked_workspaces']++;
                $marker_filename = $workspace_directory . DIRECTORY_SEPARATOR . self::RETENTION_MARKER_FILENAME;
                if ( ! is_link( $marker_filename ) && is_file( $marker_filename ) ) {
                    $result['preserved_retained']++;
                    continue;
                }

                $activity_timestamp = $this->getWorkspaceActivityTimestamp( $workspace_directory );
                if ( $activity_timestamp === null ) {
                    $result['errors']++;
                    continue;
                }
                if ( $activity_timestamp > $cutoff ) {
                    $result['preserved_fresh']++;
                    continue;
                }

                if ( $this->deleteDirectory( $workspace_directory ) ) {
                    $result['removed_workspaces']++;
                } else {
                    $result['errors']++;
                }
            }
        }

        return( $result );
    }

    protected function getDefinition( string $importer_key ) : ?array {
        $importer_key = $this->normalizeImporterKey( $importer_key );
        $definition = $this->workspace_definitions[$importer_key] ?? null;
        return( is_array( $definition ) ? $definition : null );
    }

    protected function normalizeImporterKey( string $importer_key ) : string {
        return( preg_replace( '/[^a-z0-9-]+/', '', strtolower( trim( $importer_key ) ) ) ?? '' );
    }

    protected function tokenMatchesDefinition( string $token, array $definition ) : bool {
        $token_prefix = preg_quote( (string) ( $definition['token_prefix'] ?? '' ), '/' );
        return( $token_prefix !== '' && preg_match( '/^' . $token_prefix . '\d{8}_\d{6}_[a-f0-9]{12}$/D', $token ) === 1 );
    }

    protected function workspaceIsContained( string|false $real_root, string|false $real_workspace ) : bool {
        if ( ! is_string( $real_root ) || $real_root === '' || ! is_string( $real_workspace ) || $real_workspace === '' ) {
            return( false );
        }

        $root_prefix = rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        return( $real_workspace !== $real_root && str_starts_with( $real_workspace, $root_prefix ) );
    }

    protected function getWorkspaceActivityTimestamp( string $workspace_directory ) : ?int {
        $timestamps = [];
        foreach ( array_merge( [ '' ], self::ACTIVITY_FILENAMES ) as $filename ) {
            $path = $filename === ''
                ? $workspace_directory
                : $workspace_directory . DIRECTORY_SEPARATOR . $filename;
            if ( $filename !== '' && ( is_link( $path ) || ! is_file( $path ) ) ) {
                continue;
            }

            $stat = @ lstat( $path );
            if ( is_array( $stat ) && isset( $stat['mtime'] ) ) {
                $timestamps[] = (int) $stat['mtime'];
            }
        }

        return( $timestamps !== [] ? max( $timestamps ) : null );
    }

    protected function deleteDirectory( string $directory ) : bool {
        if ( is_link( $directory ) || ! is_dir( $directory ) ) {
            return( false );
        }

        $items = @ scandir( $directory );
        if ( ! is_array( $items ) ) {
            return( false );
        }

        $complete = true;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_link( $path ) || is_file( $path ) ) {
                $complete = @ unlink( $path ) && $complete;
                continue;
            }
            if ( is_dir( $path ) ) {
                $complete = $this->deleteDirectory( $path ) && $complete;
                continue;
            }

            $complete = false;
        }

        return( @ rmdir( $directory ) && $complete );
    }
}
