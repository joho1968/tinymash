<?php
namespace app\classes;

class TinyMashExportService {

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
        } elseif ( $include_plugins ) {
            $warnings[] = 'Plugin export was requested, but the plugin runtime was not available.';
        } else {
            $warnings[] = 'Plugin data was not exported. Use --with-plugins when plugin exporters exist and should be included.';
        }

        $content_stats = $this->content_repository->getContentStats();
        $manifest = [
            'format' => 'tinymash-export',
            'format_version' => 1,
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
            ],
            'plugin_exports' => $plugin_results,
            'warnings' => $warnings,
        ];

        $this->writeJsonFile( $target_directory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest );
        return( $manifest );
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
        } elseif ( $include_plugins ) {
            $warnings[] = 'Plugin export was requested, but the plugin runtime was not available.';
        } else {
            $warnings[] = 'Plugin data was not exported. Use --with-plugins when plugin exporters exist and should be included.';
        }

        $content_stats = $this->content_repository->getContentStats( $username, false );
        $manifest = [
            'format' => 'tinymash-export',
            'format_version' => 1,
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

        $this->writeJsonFile( $target_directory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest );
        return( $manifest );
    }

    public function importSite( string $source_directory, bool $replace_existing = false, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $source_directory = $this->prepareSourceDirectory( $source_directory );
        $manifest = $this->readManifest( $source_directory );
        if ( (string) ( $manifest['scope'] ?? '' ) !== 'site' ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

        $warnings = [];
        $source_config = $source_directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tinymash.json';
        if ( ! is_file( $source_config ) || ! is_readable( $source_config ) ) {
            throw new \RuntimeException( 'Export bundle is missing app/config/tinymash.json.' );
        }

        $this->assertSiteImportSafety( $replace_existing );
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
    }

    public function importAuthor( string $source_directory, string $password, bool $replace_existing = false, ?TinyMashPlugins $plugins = null, bool $include_plugins = false ) : array {
        $source_directory = $this->prepareSourceDirectory( $source_directory );
        $manifest = $this->readManifest( $source_directory );
        if ( (string) ( $manifest['scope'] ?? '' ) !== 'author' ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

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

        $username = $this->normalizeUsername( (string) ( $profile['username'] ?? $manifest['author_username'] ?? '' ) );
        if ( $username === '' ) {
            throw new \RuntimeException( 'Author profile export is missing a valid username.' );
        }
        if ( trim( $password ) === '' ) {
            throw new \InvalidArgumentException( 'password' );
        }

        $existing_user = $this->user_repository->getUserByUsername( $username );
        if ( is_array( $existing_user ) && ! $replace_existing ) {
            throw new \RuntimeException( 'Target author already exists. Use replace-existing to overwrite it.' );
        }

        if ( $replace_existing ) {
            $this->clearDirectoryContents( $this->content_directory . DIRECTORY_SEPARATOR . $username );
            $this->clearDirectoryContents( $this->drafts_directory . DIRECTORY_SEPARATOR . $username );
            $this->clearDirectoryContents( $this->media_directory . DIRECTORY_SEPARATOR . $username );
        } else {
            $this->assertDirectoryIsEmptyOrMissing( $this->content_directory . DIRECTORY_SEPARATOR . $username, 'Author content already exists.' );
            $this->assertDirectoryIsEmptyOrMissing( $this->drafts_directory . DIRECTORY_SEPARATOR . $username, 'Author drafts already exist.' );
            $this->assertDirectoryIsEmptyOrMissing( $this->media_directory . DIRECTORY_SEPARATOR . $username, 'Author media already exists.' );
        }

        $saved_user = $this->user_repository->importStoredUser( $profile, $password, $replace_existing );
        $copied_content = $this->copyDirectoryRecursively(
            $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . $username,
            $this->content_directory . DIRECTORY_SEPARATOR . $username
        );
        $copied_drafts = $this->copyDirectoryRecursively(
            $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $username,
            $this->drafts_directory . DIRECTORY_SEPARATOR . $username
        );
        $copied_media = $this->copyDirectoryRecursively(
            $source_directory . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $username,
            $this->media_directory . DIRECTORY_SEPARATOR . $username
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
    }

    protected function prepareTargetDirectory( string $target_directory ) : string {
        $target_directory = trim( $target_directory );
        if ( $target_directory === '' ) {
            throw new \InvalidArgumentException( 'target_directory' );
        }

        if ( file_exists( $target_directory ) && ! is_dir( $target_directory ) ) {
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
        if ( $source_directory === '' || ! is_dir( $source_directory ) || ! is_readable( $source_directory ) ) {
            throw new \InvalidArgumentException( 'source_directory' );
        }

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

        return( $manifest );
    }

    protected function copyFileToPath( string $source_file, string $target_file ) : void {
        if ( ! is_file( $source_file ) || ! is_readable( $source_file ) ) {
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

        $copied_files = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $source_directory,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
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

        foreach ( glob( $directory . DIRECTORY_SEPARATOR . '*' ) ?: [] as $path ) {
            if ( ! is_string( $path ) ) {
                continue;
            }

            if ( is_dir( $path ) ) {
                $this->deleteDirectoryRecursively( $path );
                continue;
            }

            @ unlink( $path );
        }
    }

    protected function clearJsonFilesOnly( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        foreach ( glob( $directory . DIRECTORY_SEPARATOR . '*.json' ) ?: [] as $path ) {
            if ( is_string( $path ) && is_file( $path ) ) {
                @ unlink( $path );
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

        $copied_files = 0;
        foreach ( glob( $source_directory . DIRECTORY_SEPARATOR . '*' ) ?: [] as $source_path ) {
            if ( ! is_string( $source_path ) || ! is_file( $source_path ) ) {
                continue;
            }

            $relative_path = basename( $source_path );
            if ( ! $filter( $source_path, $relative_path ) ) {
                continue;
            }

            $this->copyFileToPath( $source_path, $target_directory . DIRECTORY_SEPARATOR . $relative_path );
            $copied_files++;
        }

        return( $copied_files );
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

        if ( file_put_contents( $filename, $json . PHP_EOL, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'Unable to write export payload.' );
        }
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
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
            if ( $file_info->isDir() ) {
                @ rmdir( $pathname );
                continue;
            }
            @ unlink( $pathname );
        }

        @ rmdir( $directory );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) !== 1 ) {
            return( '' );
        }

        return( $username );
    }

}
