<?php
namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

class TinyMashExportService {

    protected const FORMAT_VERSION = 2;
    protected const SUPPORTED_FORMAT_VERSIONS = [ 1, 2 ];

    protected TinyMashConfig $config;
    protected TinyMashUserRepository $user_repository;
    protected TinyMashContentRepository $content_repository;
    protected TinyMashDraftRepository $draft_repository;
    protected string $config_filename;
    protected string $users_directory;
    protected string $content_directory;
    protected string $drafts_directory;
    protected string $media_directory;

    public function __construct(
        TinyMashConfig $config,
        TinyMashUserRepository $user_repository,
        TinyMashContentRepository $content_repository,
        TinyMashDraftRepository $draft_repository,
        string $config_filename,
        string $users_directory,
        string $content_directory,
        string $drafts_directory,
        string $media_directory
    ) {
        $this->config = $config;
        $this->user_repository = $user_repository;
        $this->content_repository = $content_repository;
        $this->draft_repository = $draft_repository;
        $this->config_filename = $config_filename;
        $this->users_directory = rtrim( $users_directory, DIRECTORY_SEPARATOR );
        $this->content_directory = rtrim( $content_directory, DIRECTORY_SEPARATOR );
        $this->drafts_directory = rtrim( $drafts_directory, DIRECTORY_SEPARATOR );
        $this->media_directory = rtrim( $media_directory, DIRECTORY_SEPARATOR );
    }

    public function exportSite( string $target_directory, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $target_directory = $this->prepareTargetDirectory( $target_directory );
        try {
            $warnings = [];
            $config_target = $target_directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
            $this->copyFileToPath( $this->config_filename, $config_target );

            $copied_users = $this->copyMatchingFiles(
                $this->users_directory,
                $target_directory . DIRECTORY_SEPARATOR . 'users',
                static fn( string $source_path, string $relative_path ) : bool => str_ends_with( strtolower( $relative_path ), '.json' )
            );
            $copied_content = $this->copyDirectoryRecursively(
                $this->content_directory,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content'
            );
            $copied_drafts = $this->copyDirectoryRecursively(
                $this->drafts_directory,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts'
            );
            $copied_media = $this->copyDirectoryRecursively(
                $this->media_directory,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media'
            );

            $plugin_results = [];
            if ( $include_plugins && $plugins instanceof TinyMashPlugins ) {
                $plugin_results = $plugins->runExportContributors(
                    'site',
                    [
                        'scope' => 'site',
                        'target_directory' => $target_directory,
                        'plugins_directory' => $target_directory . DIRECTORY_SEPARATOR . 'plugins',
                        'author_username' => '',
                    ]
                );
                $this->assertExportContributorsSucceeded( $plugin_results );
            } elseif ( $include_plugins ) {
                $warnings[] = 'Plugin export was requested, but the plugin runtime was not available.';
            } else {
                $warnings[] = 'Plugin data was not exported. Use --with-plugins when plugin exporters exist and should be included.';
            }

            $this->appendJsonIntegrityWarning( $target_directory, $warnings );

            $content_stats = $this->content_repository->getContentStats();
            $manifest = [
                'format' => 'tinymash-export',
                'format_version' => self::FORMAT_VERSION,
                'scope' => 'site',
                'exported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'site' => [
                    'name' => $this->config->getSiteName(),
                    'base_url' => (string) $this->config->configGetBaseURL(),
                    'public_theme' => $this->config->getPublicThemeKey(),
                ],
                'components' => [
                    'config' => is_file( $config_target ),
                    'users' => $copied_users > 0,
                    'content' => $copied_content > 0,
                    'drafts' => $copied_drafts > 0,
                    'media' => $copied_media > 0,
                    'plugin_data' => ! empty( $plugin_results ),
                ],
                'counts' => [
                    'users' => count( $this->user_repository->getAllUsers() ),
                    'entries' => (int) ( $content_stats['entries'] ?? 0 ),
                    'posts' => (int) ( $content_stats['posts'] ?? 0 ),
                    'pages' => (int) ( $content_stats['pages'] ?? 0 ),
                    'pending_review' => (int) ( $content_stats['pending_review'] ?? 0 ),
                    'scheduled' => (int) ( $content_stats['scheduled'] ?? 0 ),
                ],
                'plugin_exports' => $plugin_results,
                'warnings' => $warnings,
            ];

            $manifest['files'] = $this->buildFileInventory( $target_directory );
            $manifest['file_count'] = count( $manifest['files'] );

            $this->writeJsonFile( $target_directory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest );
            return( $manifest );
        } catch ( \Throwable $e ) {
            $this->clearDirectoryContents( $target_directory );
            throw $e;
        }
    }

    public function exportAuthor( string $username, string $target_directory, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $user = $this->user_repository->getUserByUsername( $username );
        if ( ! is_array( $user ) ) {
            throw new \InvalidArgumentException( 'username' );
        }

        $target_directory = $this->prepareTargetDirectory( $target_directory );
        try {
            $warnings = [];

            $this->writeJsonFile(
                $target_directory . DIRECTORY_SEPARATOR . 'author' . DIRECTORY_SEPARATOR . 'profile.json',
                $user
            );
            $copied_content = $this->copyDirectoryRecursively(
                $this->content_directory . DIRECTORY_SEPARATOR . $username,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . $username
            );
            $copied_drafts = $this->copyDirectoryRecursively(
                $this->drafts_directory . DIRECTORY_SEPARATOR . $username,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $username
            );
            $copied_media = $this->copyDirectoryRecursively(
                $this->media_directory . DIRECTORY_SEPARATOR . $username,
                $target_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $username
            );

            $plugin_results = [];
            if ( $include_plugins && $plugins instanceof TinyMashPlugins ) {
                $plugin_results = $plugins->runExportContributors(
                    'author',
                    [
                        'scope' => 'author',
                        'target_directory' => $target_directory,
                        'plugins_directory' => $target_directory . DIRECTORY_SEPARATOR . 'plugins',
                        'author_username' => $username,
                    ]
                );
                $this->assertExportContributorsSucceeded( $plugin_results );
            } elseif ( $include_plugins ) {
                $warnings[] = 'Plugin export was requested, but the plugin runtime was not available.';
            } else {
                $warnings[] = 'Plugin data was not exported. Use --with-plugins when plugin exporters exist and should be included.';
            }

            $this->appendJsonIntegrityWarning( $target_directory, $warnings );

            $content_stats = $this->content_repository->getContentStats( $username, false );
            $manifest = [
                'format' => 'tinymash-export',
                'format_version' => self::FORMAT_VERSION,
                'scope' => 'author',
                'author_username' => $username,
                'exported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'site' => [
                    'name' => $this->config->getSiteName(),
                    'base_url' => (string) $this->config->configGetBaseURL(),
                    'public_theme' => $this->config->getPublicThemeKey(),
                ],
                'components' => [
                    'profile' => true,
                    'content' => $copied_content > 0,
                    'drafts' => $copied_drafts > 0,
                    'media' => $copied_media > 0,
                    'plugin_data' => ! empty( $plugin_results ),
                ],
                'counts' => [
                    'entries' => (int) ( $content_stats['entries'] ?? 0 ),
                    'posts' => (int) ( $content_stats['posts'] ?? 0 ),
                    'pages' => (int) ( $content_stats['pages'] ?? 0 ),
                    'drafts' => count( $this->draft_repository->listEditorDrafts( $username ) ),
                ],
                'plugin_exports' => $plugin_results,
                'warnings' => $warnings,
            ];

            $manifest['files'] = $this->buildFileInventory( $target_directory );
            $manifest['file_count'] = count( $manifest['files'] );

            $this->writeJsonFile( $target_directory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest );
            return( $manifest );
        } catch ( \Throwable $e ) {
            $this->clearDirectoryContents( $target_directory );
            throw $e;
        }
    }

    public function inspectSiteImport( string $source_directory ) : array {
        $source_directory = $this->prepareSourceDirectory( $source_directory );
        $manifest = $this->readManifest( $source_directory );
        if ( (string) ( $manifest['scope'] ?? '' ) !== 'site' ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $this->validateImportBundle( $source_directory, $manifest );
        $source_config = $source_directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
        if ( ! is_file( $source_config ) || ! is_readable( $source_config ) ) {
            throw new \RuntimeException( 'Export bundle is missing app/config/tinymash.json.' );
        }

        $source_config_json = file_get_contents( $source_config );
        $source_config_data = is_string( $source_config_json ) && trim( $source_config_json ) !== ''
            ? json_decode( $source_config_json, true, 64, JSON_THROW_ON_ERROR )
            : null;
        if ( ! is_array( $source_config_data ) ) {
            throw new \RuntimeException( 'Export bundle site configuration is invalid.' );
        }

        $files = $this->buildFileInventory( $source_directory );
        $expanded_size_bytes = array_sum( array_map( static fn( array $file ) : int => (int) ( $file['size_bytes'] ?? 0 ), $files ) );
        $target_conflicts = $this->getSiteImportTargetConflicts();
        $site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
        $counts = is_array( $manifest['counts'] ?? null ) ? $manifest['counts'] : [];
        $components = is_array( $manifest['components'] ?? null ) ? $manifest['components'] : [];
        $login_url = trim( (string) ( $source_config_data['site']['login_url'] ?? '/login' ) );
        if ( preg_match( '#^/[A-Za-z0-9/_-]*$#', $login_url ) !== 1 ) {
            $login_url = '/login';
        }

        return(
            [
                'scope' => 'site',
                'format_version' => (int) $manifest['format_version'],
                'exported_at_utc' => trim( (string) ( $manifest['exported_at_utc'] ?? '' ) ),
                'site_name' => mb_trim( (string) ( $site['name'] ?? '' ) ),
                'site_base_url' => trim( (string) ( $site['base_url'] ?? '' ) ),
                'public_theme' => trim( (string) ( $site['public_theme'] ?? '' ) ),
                'login_url' => $login_url,
                'counts' => [
                    'users' => max( 0, (int) ( $counts['users'] ?? 0 ) ),
                    'entries' => max( 0, (int) ( $counts['entries'] ?? 0 ) ),
                    'posts' => max( 0, (int) ( $counts['posts'] ?? 0 ) ),
                    'pages' => max( 0, (int) ( $counts['pages'] ?? 0 ) ),
                    'pending_review' => max( 0, (int) ( $counts['pending_review'] ?? 0 ) ),
                    'scheduled' => max( 0, (int) ( $counts['scheduled'] ?? 0 ) ),
                ],
                'components' => [
                    'config' => ! empty( $components['config'] ),
                    'users' => ! empty( $components['users'] ),
                    'content' => ! empty( $components['content'] ),
                    'drafts' => ! empty( $components['drafts'] ),
                    'media' => ! empty( $components['media'] ),
                    'plugin_data' => ! empty( $components['plugin_data'] ),
                ],
                'file_count' => count( $files ),
                'expanded_size_bytes' => $expanded_size_bytes,
                'target_conflicts' => $target_conflicts,
                'replace_required' => ! empty( $target_conflicts ),
                'warnings' => array_values( array_filter( is_array( $manifest['warnings'] ?? null ) ? $manifest['warnings'] : [], 'is_string' ) ),
            ]
        );
    }

    public function importSite( string $source_directory, bool $replace_existing = false, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $source_directory = $this->prepareSourceDirectory( $source_directory );
        $manifest = $this->readManifest( $source_directory );
        if ( (string) ( $manifest['scope'] ?? '' ) !== 'site' ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $this->validateImportBundle( $source_directory, $manifest );
        $source_config = $source_directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
        if ( ! is_file( $source_config ) || ! is_readable( $source_config ) ) {
            throw new \RuntimeException( 'Export bundle is missing app/config/tinymash.json.' );
        }

        return( $this->withImportLock( function() use ( $source_directory, $source_config, $replace_existing, $plugins, $include_plugins ) : array {
            $this->assertSiteImportSafety( $replace_existing );
            $snapshot = $this->createImportSnapshot(
                [
                    'config' => $this->config_filename,
                    'users' => $this->users_directory,
                    'content' => $this->content_directory,
                    'drafts' => $this->drafts_directory,
                    'media' => $this->media_directory,
                ]
            );

            try {
                if ( $replace_existing ) {
                    $this->clearDirectoryContents( $this->content_directory );
                    $this->clearDirectoryContents( $this->drafts_directory );
                    $this->clearDirectoryContents( $this->media_directory );
                    $this->clearJsonFilesOnly( $this->users_directory );
                }

                $this->copyFileToPath( $source_config, $this->config_filename );
                $copied_users = $this->copyMatchingFiles(
                    $source_directory . DIRECTORY_SEPARATOR . 'users',
                    $this->users_directory,
                    static fn( string $source_path, string $relative_path ) : bool => str_ends_with( strtolower( $relative_path ), '.json' )
                );
                $copied_content = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content',
                    $this->content_directory
                );
                $copied_drafts = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts',
                    $this->drafts_directory
                );
                $copied_media = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media',
                    $this->media_directory
                );

                $warnings = [];
                $plugin_results = [];
                if ( $include_plugins && $plugins instanceof TinyMashPlugins ) {
                    $plugin_results = $plugins->runImportContributors(
                        'site',
                        [
                            'scope' => 'site',
                            'source_directory' => $source_directory,
                            'plugins_directory' => $source_directory . DIRECTORY_SEPARATOR . 'plugins',
                            'author_username' => '',
                            'replace_existing' => $replace_existing,
                        ]
                    );
                } elseif ( $include_plugins ) {
                    $warnings[] = 'Plugin import was requested, but the plugin runtime was not available.';
                } else {
                    $warnings[] = 'Plugin data was not imported. Use --with-plugins when plugin importers exist and should be included.';
                }

                $this->invalidateImportCaches();

                return(
                    [
                        'scope' => 'site',
                        'imported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                        'copied' => [
                            'config' => 1,
                            'users' => $copied_users,
                            'content' => $copied_content,
                            'drafts' => $copied_drafts,
                            'media' => $copied_media,
                        ],
                        'plugin_imports' => $plugin_results,
                        'warnings' => $warnings,
                    ]
                );
            } catch ( \Throwable $e ) {
                $this->restoreImportSnapshot( $snapshot, $e );
                throw $e;
            } finally {
                $this->deleteDirectoryRecursively( $snapshot['directory'] );
            }
        } ) );
    }

    public function importAuthor( string $source_directory, string $password, bool $replace_existing = false, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $source_directory = $this->prepareSourceDirectory( $source_directory );
        $manifest = $this->readManifest( $source_directory );
        if ( (string) ( $manifest['scope'] ?? '' ) !== 'author' ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $this->validateImportBundle( $source_directory, $manifest );
        $profile_filename = $source_directory . DIRECTORY_SEPARATOR . 'author' . DIRECTORY_SEPARATOR . 'profile.json';
        if ( ! is_file( $profile_filename ) || ! is_readable( $profile_filename ) ) {
            throw new \RuntimeException( 'Export bundle is missing author/profile.json.' );
        }

        $profile_json = file_get_contents( $profile_filename );
        $profile = is_string( $profile_json ) && trim( $profile_json ) !== ''
            ? json_decode( $profile_json, true, 32, JSON_THROW_ON_ERROR )
            : null;
        if ( ! is_array( $profile ) ) {
            throw new \RuntimeException( 'Author profile export is invalid.' );
        }

        $manifest_username = $this->normalizeUsername( (string) ( $manifest['author_username'] ?? '' ) );
        $username = $this->normalizeUsername( (string) ( $profile['username'] ?? '' ) );
        if ( $username === '' ) {
            throw new \RuntimeException( 'Author profile export is missing a valid username.' );
        }
        if ( $manifest_username === '' || $manifest_username !== $username ) {
            throw new \RuntimeException( 'Author profile username does not match the export manifest.' );
        }
        if ( trim( $password ) === '' ) {
            throw new \InvalidArgumentException( 'password' );
        }

        return( $this->withImportLock( function() use ( $source_directory, $profile, $password, $replace_existing, $plugins, $include_plugins, $username ) : array {
            $existing_user = $this->user_repository->getUserByUsername( $username );
            if ( is_array( $existing_user ) && ! $replace_existing ) {
                throw new \RuntimeException( 'Target author already exists. Use replace-existing to overwrite it.' );
            }

            $author_content = $this->content_directory . DIRECTORY_SEPARATOR . $username;
            $author_drafts = $this->drafts_directory . DIRECTORY_SEPARATOR . $username;
            $author_media = $this->media_directory . DIRECTORY_SEPARATOR . $username;
            if ( ! $replace_existing ) {
                $this->assertDirectoryIsEmptyOrMissing( $author_content, 'Author content already exists.' );
                $this->assertDirectoryIsEmptyOrMissing( $author_drafts, 'Author drafts already exist.' );
                $this->assertDirectoryIsEmptyOrMissing( $author_media, 'Author media already exists.' );
            }

            $snapshot = $this->createImportSnapshot(
                [
                    'user' => $this->users_directory . DIRECTORY_SEPARATOR . $username . '.json',
                    'content' => $author_content,
                    'drafts' => $author_drafts,
                    'media' => $author_media,
                ]
            );

            try {
                if ( $replace_existing ) {
                    $this->clearDirectoryContents( $author_content );
                    $this->clearDirectoryContents( $author_drafts );
                    $this->clearDirectoryContents( $author_media );
                }

                $saved_user = $this->user_repository->importStoredUser( $profile, $password, $replace_existing );
                $copied_content = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . $username,
                    $author_content
                );
                $copied_drafts = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $username,
                    $author_drafts
                );
                $copied_media = $this->copyDirectoryRecursively(
                    $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $username,
                    $author_media
                );

                $warnings = [];
                $plugin_results = [];
                if ( $include_plugins && $plugins instanceof TinyMashPlugins ) {
                    $plugin_results = $plugins->runImportContributors(
                        'author',
                        [
                            'scope' => 'author',
                            'source_directory' => $source_directory,
                            'plugins_directory' => $source_directory . DIRECTORY_SEPARATOR . 'plugins',
                            'author_username' => $username,
                            'replace_existing' => $replace_existing,
                        ]
                    );
                } elseif ( $include_plugins ) {
                    $warnings[] = 'Plugin import was requested, but the plugin runtime was not available.';
                } else {
                    $warnings[] = 'Plugin data was not imported. Use --with-plugins when plugin importers exist and should be included.';
                }

                $this->invalidateImportCaches();

                return(
                    [
                        'scope' => 'author',
                        'author_username' => $username,
                        'imported_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                        'saved_user' => $saved_user,
                        'copied' => [
                            'content' => $copied_content,
                            'drafts' => $copied_drafts,
                            'media' => $copied_media,
                        ],
                        'plugin_imports' => $plugin_results,
                        'warnings' => $warnings,
                    ]
                );
            } catch ( \Throwable $e ) {
                $this->restoreImportSnapshot( $snapshot, $e );
                throw $e;
            } finally {
                $this->deleteDirectoryRecursively( $snapshot['directory'] );
            }
        } ) );
    }

    protected function prepareTargetDirectory( string $target_directory ) : string {
        $target_directory = trim( $target_directory );
        if ( $target_directory === '' ) {
            throw new \InvalidArgumentException( 'target_directory' );
        }

        if ( is_link( $target_directory ) || ( file_exists( $target_directory ) && ! is_dir( $target_directory ) ) ) {
            throw new \RuntimeException( 'Target exists and is not a directory.' );
        }

        if ( is_dir( $target_directory ) ) {
            $iterator = new \FilesystemIterator( $target_directory, \FilesystemIterator::SKIP_DOTS );
            if ( $iterator->valid() ) {
                throw new \RuntimeException( 'Target directory must be empty.' );
            }
        } elseif ( ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create target directory.' );
        }

        return( rtrim( $target_directory, DIRECTORY_SEPARATOR ) );
    }

    protected function prepareSourceDirectory( string $source_directory ) : string {
        $source_directory = rtrim( trim( $source_directory ), DIRECTORY_SEPARATOR );
        if ( $source_directory === '' || is_link( $source_directory ) || ! is_dir( $source_directory ) || ! is_readable( $source_directory ) ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $this->assertImportSourceSeparated( $source_directory );
        return( $source_directory );
    }

    protected function readManifest( string $source_directory ) : array {
        $manifest_filename = $source_directory . DIRECTORY_SEPARATOR . 'manifest.json';
        if ( ! is_file( $manifest_filename ) || ! is_readable( $manifest_filename ) ) {
            throw new \RuntimeException( 'Export bundle is missing manifest.json.' );
        }

        $manifest_json = file_get_contents( $manifest_filename );
        $manifest = is_string( $manifest_json ) && trim( $manifest_json ) !== ''
            ? json_decode( $manifest_json, true, 32, JSON_THROW_ON_ERROR )
            : null;
        if ( ! is_array( $manifest ) || (string) ( $manifest['format'] ?? '' ) !== 'tinymash-export' ) {
            throw new \RuntimeException( 'Export bundle manifest is invalid.' );
        }
        $format_version = filter_var( $manifest['format_version'] ?? null, FILTER_VALIDATE_INT );
        if ( ! is_int( $format_version ) || ! in_array( $format_version, self::SUPPORTED_FORMAT_VERSIONS, true ) ) {
            throw new \RuntimeException( 'Export bundle format version is not supported.' );
        }
        $manifest['format_version'] = $format_version;

        return( $manifest );
    }

    protected function validateImportBundle( string $source_directory, array $manifest ) : void {
        $actual_files = $this->buildFileInventory( $source_directory );
        $actual_by_path = [];
        foreach ( $actual_files as $file ) {
            $actual_by_path[$file['path']] = $file;
        }

        if ( (int) $manifest['format_version'] >= 2 ) {
            $manifest_files = $manifest['files'] ?? null;
            if ( ! is_array( $manifest_files ) || (int) ( $manifest['file_count'] ?? -1 ) !== count( $manifest_files ) ) {
                throw new \RuntimeException( 'Export bundle file inventory is invalid.' );
            }

            $expected_by_path = [];
            foreach ( $manifest_files as $file ) {
                if ( ! is_array( $file ) ) {
                    throw new \RuntimeException( 'Export bundle file inventory is invalid.' );
                }

                $path = (string) ( $file['path'] ?? '' );
                $size_bytes = filter_var( $file['size_bytes'] ?? null, FILTER_VALIDATE_INT );
                $sha256 = strtolower( (string) ( $file['sha256'] ?? '' ) );
                if (
                    $path === '' ||
                    $path === 'manifest.json' ||
                    str_contains( $path, '\\' ) ||
                    str_starts_with( $path, '/' ) ||
                    preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) === 1 ||
                    ! is_int( $size_bytes ) ||
                    $size_bytes < 0 ||
                    preg_match( '/^[a-f0-9]{64}$/', $sha256 ) !== 1 ||
                    isset( $expected_by_path[$path] )
                ) {
                    throw new \RuntimeException( 'Export bundle file inventory is invalid.' );
                }

                $expected_by_path[$path] = [
                    'path' => $path,
                    'size_bytes' => $size_bytes,
                    'sha256' => $sha256,
                ];
            }

            ksort( $expected_by_path );
            ksort( $actual_by_path );
            if ( $expected_by_path !== $actual_by_path ) {
                throw new \RuntimeException( 'Export bundle file verification failed.' );
            }
        }

        foreach ( array_keys( $actual_by_path ) as $relative_path ) {
            if ( ! str_ends_with( strtolower( $relative_path ), '.json' ) ) {
                continue;
            }

            $json = file_get_contents( $source_directory . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );
            if ( ! is_string( $json ) || trim( $json ) === '' ) {
                throw new \RuntimeException( 'Export bundle contains an empty JSON file: ' . $relative_path );
            }

            try {
                json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
            } catch ( \JsonException $e ) {
                throw new \RuntimeException( 'Export bundle contains invalid JSON: ' . $relative_path, 0, $e );
            }
        }
    }

    protected function buildFileInventory( string $directory ) : array {
        if ( is_link( $directory ) || ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Export directory is invalid.' );
        }

        $directory = rtrim( $directory, DIRECTORY_SEPARATOR );
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            if ( $file_info->isLink() ) {
                throw new \RuntimeException( 'Export data must not contain symbolic links.' );
            }
            if ( $file_info->isDir() ) {
                continue;
            }
            if ( ! $file_info->isFile() || ! $file_info->isReadable() ) {
                throw new \RuntimeException( 'Export data contains an unreadable file.' );
            }

            $pathname = $file_info->getPathname();
            $relative_path = str_replace( DIRECTORY_SEPARATOR, '/', substr( $pathname, strlen( $directory ) + 1 ) );
            if ( $relative_path === 'manifest.json' ) {
                continue;
            }

            $sha256 = hash_file( 'sha256', $pathname );
            if ( ! is_string( $sha256 ) ) {
                throw new \RuntimeException( 'Unable to hash export data.' );
            }
            $files[] = [
                'path' => $relative_path,
                'size_bytes' => $file_info->getSize(),
                'sha256' => $sha256,
            ];
        }

        usort( $files, static fn( array $left, array $right ) : int => strcmp( $left['path'], $right['path'] ) );
        return( $files );
    }

    protected function appendJsonIntegrityWarning( string $directory, array &$warnings ) : void {
        $invalid_count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo || $file_info->isLink() || ! $file_info->isFile() || strtolower( $file_info->getExtension() ) !== 'json' ) {
                continue;
            }

            $json = @ file_get_contents( $file_info->getPathname() );
            if ( ! is_string( $json ) || trim( $json ) === '' ) {
                $invalid_count++;
                continue;
            }
            try {
                json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
            } catch ( \JsonException ) {
                $invalid_count++;
            }
        }

        if ( $invalid_count > 0 ) {
            $warnings[] = 'Export preserved ' . $invalid_count . ' invalid JSON file(s). Repair the source data before relying on this export for restore.';
        }
    }

    protected function copyFileToPath( string $source_file, string $target_file ) : void {
        if ( is_link( $source_file ) || ! is_file( $source_file ) || ! is_readable( $source_file ) ) {
            throw new \RuntimeException( 'Source file is not readable.' );
        }

        $target_directory = dirname( $target_file );
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create export directory.' );
        }

        if ( ! @ copy( $source_file, $target_file ) ) {
            throw new \RuntimeException( 'Unable to copy file into export.' );
        }
    }

    protected function copyDirectoryRecursively( string $source_directory, string $target_directory ) : int {
        if ( ! is_dir( $source_directory ) ) {
            return( 0 );
        }
        if ( is_link( $source_directory ) ) {
            throw new \RuntimeException( 'Source directories must not be symbolic links.' );
        }

        $copied_files = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $source_directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            if ( $file_info->isLink() ) {
                throw new \RuntimeException( 'Source data must not contain symbolic links.' );
            }
            if ( ! $file_info->isFile() ) {
                continue;
            }

            $source_path = $file_info->getPathname();
            $relative_path = ltrim( substr( $source_path, strlen( $source_directory ) ), DIRECTORY_SEPARATOR );
            $target_path = $target_directory . DIRECTORY_SEPARATOR . $relative_path;
            $this->copyFileToPath( $source_path, $target_path );
            $copied_files++;
        }

        return( $copied_files );
    }

    protected function clearDirectoryContents( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
        foreach ( $iterator as $file_info ) {
            $this->removePath( $file_info->getPathname() );
        }
    }

    protected function clearJsonFilesOnly( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
        foreach ( $iterator as $file_info ) {
            if ( strtolower( $file_info->getExtension() ) === 'json' && ( $file_info->isFile() || $file_info->isLink() ) ) {
                $path = $file_info->getPathname();
                if ( ! @ unlink( $path ) && file_exists( $path ) ) {
                    throw new \RuntimeException( 'Unable to remove existing user file.' );
                }
            }
        }
    }

    protected function assertSiteImportSafety( bool $replace_existing ) : void {
        if ( $replace_existing ) {
            return;
        }

        $this->assertDirectoryIsEmptyOrMissing( $this->content_directory, 'Content directory is not empty. Use replace-existing to overwrite it.' );
        $this->assertDirectoryIsEmptyOrMissing( $this->drafts_directory, 'Drafts directory is not empty. Use replace-existing to overwrite it.' );
        $this->assertDirectoryIsEmptyOrMissing( $this->media_directory, 'Media directory is not empty. Use replace-existing to overwrite it.' );

        foreach ( glob( $this->users_directory . DIRECTORY_SEPARATOR . '*.json' ) ?: [] as $user_file ) {
            if ( is_string( $user_file ) && is_file( $user_file ) ) {
                throw new \RuntimeException( 'Users directory is not empty. Use replace-existing to overwrite it.' );
            }
        }
    }

    protected function getSiteImportTargetConflicts() : array {
        $conflicts = [];
        foreach (
            [
                'users' => $this->users_directory,
                'content' => $this->content_directory,
                'drafts' => $this->drafts_directory,
                'media' => $this->media_directory,
            ] as $component => $directory
        ) {
            if ( ! is_dir( $directory ) ) {
                continue;
            }

            $iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
            foreach ( $iterator as $item ) {
                if ( $component === 'users' && ( ! $item->isFile() || strtolower( $item->getExtension() ) !== 'json' ) ) {
                    continue;
                }
                $conflicts[] = $component;
                break;
            }
        }

        return( $conflicts );
    }

    protected function invalidateImportCaches() : void {
        if ( class_exists( TinyMashPublicPageCache::class ) ) {
            $this->content_repository->invalidateDerivedCaches();
        } else {
            $this->content_repository->clearPersistentCache();
        }
        $rendered_content_directory = dirname( $this->content_directory ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'rendered-content';
        if ( is_dir( $rendered_content_directory ) && ! is_link( $rendered_content_directory ) ) {
            $this->clearDirectoryContents( $rendered_content_directory );
        }
    }

    protected function assertDirectoryIsEmptyOrMissing( string $directory, string $message ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
        if ( $iterator->valid() ) {
            throw new \RuntimeException( $message );
        }
    }

    protected function copyMatchingFiles( string $source_directory, string $target_directory, callable $filter ) : int {
        if ( ! is_dir( $source_directory ) ) {
            return( 0 );
        }
        if ( is_link( $source_directory ) ) {
            throw new \RuntimeException( 'Source directories must not be symbolic links.' );
        }

        $copied_files = 0;
        $iterator = new \FilesystemIterator( $source_directory, \FilesystemIterator::SKIP_DOTS );
        foreach ( $iterator as $file_info ) {
            if ( $file_info->isLink() ) {
                throw new \RuntimeException( 'Source data must not contain symbolic links.' );
            }
            if ( ! $file_info->isFile() ) {
                continue;
            }

            $source_path = $file_info->getPathname();
            $relative_path = $file_info->getBasename();
            if ( ! $filter( $source_path, $relative_path ) ) {
                continue;
            }

            $this->copyFileToPath( $source_path, $target_directory . DIRECTORY_SEPARATOR . $relative_path );
            $copied_files++;
        }

        return( $copied_files );
    }

    protected function assertImportSourceSeparated( string $source_directory ) : void {
        $source_path = realpath( $source_directory );
        if ( ! is_string( $source_path ) ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $target_paths = [
            dirname( $this->config_filename ),
            $this->users_directory,
            $this->content_directory,
            $this->drafts_directory,
            $this->media_directory,
        ];
        foreach ( $target_paths as $target_path ) {
            $real_target = realpath( $target_path );
            if ( ! is_string( $real_target ) ) {
                continue;
            }
            if ( $this->pathsOverlap( $source_path, $real_target ) ) {
                throw new \RuntimeException( 'Export bundle must be stored outside the target installation data directories.' );
            }
        }
    }

    protected function pathsOverlap( string $left, string $right ) : bool {
        $left = rtrim( $left, DIRECTORY_SEPARATOR );
        $right = rtrim( $right, DIRECTORY_SEPARATOR );
        return(
            $left === $right ||
            str_starts_with( $left . DIRECTORY_SEPARATOR, $right . DIRECTORY_SEPARATOR ) ||
            str_starts_with( $right . DIRECTORY_SEPARATOR, $left . DIRECTORY_SEPARATOR )
        );
    }

    protected function assertExportContributorsSucceeded( array $results ) : void {
        foreach ( $results as $result ) {
            if ( is_array( $result ) && (string) ( $result['status'] ?? '' ) === 'error' ) {
                throw new \RuntimeException( 'Plugin export failed for ' . (string) ( $result['plugin_key'] ?? 'a plugin' ) . '.' );
            }
        }
    }

    protected function withImportLock( callable $callback ) : mixed {
        $lock_directory = dirname( $this->content_directory ) . DIRECTORY_SEPARATOR . 'runtime';
        if ( ! is_dir( $lock_directory ) && ! @ mkdir( $lock_directory, 0775, true ) && ! is_dir( $lock_directory ) ) {
            throw new \RuntimeException( 'Unable to create import lock directory.' );
        }

        $lock_handle = @ fopen( $lock_directory . DIRECTORY_SEPARATOR . 'export-import.lock', 'c+' );
        if ( ! is_resource( $lock_handle ) ) {
            throw new \RuntimeException( 'Unable to open import lock.' );
        }
        if ( ! flock( $lock_handle, LOCK_EX ) ) {
            fclose( $lock_handle );
            throw new \RuntimeException( 'Unable to acquire import lock.' );
        }

        try {
            return( $callback() );
        } finally {
            flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function createImportSnapshot( array $targets ) : array {
        $snapshot_directory = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR )
            . DIRECTORY_SEPARATOR
            . 'tinymash-import-'
            . bin2hex( random_bytes( 8 ) );
        if ( ! @ mkdir( $snapshot_directory, 0700, true ) && ! is_dir( $snapshot_directory ) ) {
            throw new \RuntimeException( 'Unable to create import rollback workspace.' );
        }

        $state = [];
        try {
            foreach ( $targets as $key => $target ) {
                $key = (string) $key;
                $target = (string) $target;
                if ( is_link( $target ) ) {
                    throw new \RuntimeException( 'Import targets must not be symbolic links.' );
                }

                $state[$key] = [
                    'target' => $target,
                    'type' => 'missing',
                ];
                $snapshot_path = $snapshot_directory . DIRECTORY_SEPARATOR . $key;
                if ( is_file( $target ) ) {
                    $this->copyFileToPath( $target, $snapshot_path );
                    $state[$key]['type'] = 'file';
                } elseif ( is_dir( $target ) ) {
                    if ( ! @ mkdir( $snapshot_path, 0700, true ) && ! is_dir( $snapshot_path ) ) {
                        throw new \RuntimeException( 'Unable to create import rollback directory.' );
                    }
                    $this->copyDirectoryRecursively( $target, $snapshot_path );
                    $state[$key]['type'] = 'directory';
                }
            }
        } catch ( \Throwable $e ) {
            $this->deleteDirectoryRecursively( $snapshot_directory );
            throw $e;
        }

        return(
            [
                'directory' => $snapshot_directory,
                'targets' => $state,
            ]
        );
    }

    protected function restoreImportSnapshot( array $snapshot, \Throwable $import_error ) : void {
        try {
            foreach ( $snapshot['targets'] as $key => $state ) {
                $target = (string) $state['target'];
                $type = (string) $state['type'];
                $snapshot_path = $snapshot['directory'] . DIRECTORY_SEPARATOR . $key;
                $this->removePath( $target );

                if ( $type === 'file' ) {
                    $this->copyFileToPath( $snapshot_path, $target );
                } elseif ( $type === 'directory' ) {
                    if ( ! @ mkdir( $target, 0775, true ) && ! is_dir( $target ) ) {
                        throw new \RuntimeException( 'Unable to restore import directory.' );
                    }
                    $this->copyDirectoryRecursively( $snapshot_path, $target );
                }
            }
        } catch ( \Throwable $rollback_error ) {
            throw new \RuntimeException(
                'Import failed and the original files could not be fully restored: ' . $rollback_error->getMessage(),
                0,
                $import_error
            );
        }
    }

    protected function removePath( string $path ) : void {
        if ( is_link( $path ) || is_file( $path ) ) {
            if ( ! @ unlink( $path ) && file_exists( $path ) ) {
                throw new \RuntimeException( 'Unable to remove import target file.' );
            }
            return;
        }
        if ( is_dir( $path ) ) {
            $this->deleteDirectoryRecursively( $path );
            if ( is_dir( $path ) ) {
                throw new \RuntimeException( 'Unable to remove import target directory.' );
            }
        }
    }

    protected function writeJsonFile( string $filename, array $payload ) : void {
        $directory = dirname( $filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create export directory.' );
        }

        $json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || $json === '' ) {
            throw new \RuntimeException( 'Unable to encode export payload.' );
        }

        $contents = $json . PHP_EOL;
        if ( ! TinyMashFilesystem::writeFileAtomically( $filename, $contents ) ) {
            throw new \RuntimeException( 'Unable to write export payload.' );
        }
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
        if ( is_link( $directory ) ) {
            if ( ! @ unlink( $directory ) && file_exists( $directory ) ) {
                throw new \RuntimeException( 'Unable to remove symbolic link.' );
            }
            return;
        }
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            $pathname = $file_info->getPathname();
            if ( $file_info->isLink() ) {
                if ( ! @ unlink( $pathname ) && file_exists( $pathname ) ) {
                    throw new \RuntimeException( 'Unable to remove symbolic link.' );
                }
                continue;
            }
            if ( $file_info->isDir() ) {
                if ( ! @ rmdir( $pathname ) && is_dir( $pathname ) ) {
                    throw new \RuntimeException( 'Unable to remove directory.' );
                }
                continue;
            }
            if ( ! @ unlink( $pathname ) && file_exists( $pathname ) ) {
                throw new \RuntimeException( 'Unable to remove file.' );
            }
        }

        if ( ! @ rmdir( $directory ) && is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to remove directory.' );
        }
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }

}
