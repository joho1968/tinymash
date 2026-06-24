<?php
namespace app\classes;

class TinyMashDraftRepository {

    protected string $drafts_directory;
    protected string $drafts_lock_filename;

    public function __construct( string $drafts_directory ) {
        $this->drafts_directory = rtrim( $drafts_directory, DIRECTORY_SEPARATOR );
        $this->drafts_lock_filename = $this->drafts_directory . DIRECTORY_SEPARATOR . '.drafts.lock';
    }

    public function hasEditorDraft( string $username, string $draft_id = '' ) : bool {
        if ( $draft_id !== '' ) {
            return( is_array( $this->getEditorDraftById( $username, $draft_id ) ) );
        }

        return( ! empty( $this->listEditorDrafts( $username ) ) );
    }

    public function getEditorDraft( string $username, string $draft_id = '' ) : array {
        if ( $draft_id !== '' ) {
            $draft = $this->getEditorDraftById( $username, $draft_id );
            return( is_array( $draft ) ? $draft : $this->getDefaultDraft() );
        }

        $drafts = $this->listEditorDrafts( $username );
        if ( ! empty( $drafts[0] ) ) {
            return( $drafts[0] );
        }

        return( $this->getDefaultDraft() );
    }

    public function getEditorDraftById( string $username, string $draft_id ) : ?array {
        $draft_id = $this->normalizeDraftId( $draft_id );
        if ( $draft_id === '' ) {
            return( null );
        }

        $this->migrateLegacyDraftIfNeeded( $username );
        $draft_filename = $this->getDraftFilename( $username, $draft_id );
        if ( ! is_file( $draft_filename ) || ! is_readable( $draft_filename ) ) {
            return( null );
        }

        $draft = $this->readDraftFile( $draft_filename );
        return( is_array( $draft ) ? $draft : null );
    }

    public function getEditorDraftBySourceEntryId( string $username, string $source_entry_id ) : ?array {
        $source_entry_id = $this->normalizeDraftEntryId( $source_entry_id );
        if ( $source_entry_id === '' ) {
            return( null );
        }

        foreach ( $this->listEditorDrafts( $username ) as $draft ) {
            if ( (string) ( $draft['source_entry_id'] ?? '' ) === $source_entry_id ) {
                return( $draft );
            }
        }

        return( null );
    }

    public function listEditorDrafts( string $username ) : array {
        $this->migrateLegacyDraftIfNeeded( $username );

        $drafts = [];
        foreach ( $this->getDraftFiles( $username ) as $draft_file ) {
            $draft = $this->readDraftFile( $draft_file );
            if ( is_array( $draft ) ) {
                $drafts[] = $draft;
            }
        }

        usort(
            $drafts,
            function( array $left, array $right ) : int {
                $left_updated = (string) ( $left['updated_at_utc'] ?? '' );
                $right_updated = (string) ( $right['updated_at_utc'] ?? '' );
                if ( $left_updated !== $right_updated ) {
                    return( strcmp( $right_updated, $left_updated ) );
                }

                return( strcmp( (string) ( $left['draft_id'] ?? '' ), (string) ( $right['draft_id'] ?? '' ) ) );
            }
        );

        return( $drafts );
    }

    public function saveEditorDraft( string $username, array $draft_data ) : array {
        return( $this->withLockedDraftStore( function() use ( $username, $draft_data ) : array {
            $username = $this->normalizeUsername( $username );
            if ( $username === 'unknown' ) {
                throw new \InvalidArgumentException( 'username' );
            }

            $this->migrateLegacyDraftIfNeededUnlocked( $username );
            $normalized_draft = $this->normalizeDraft( $draft_data );
            $existing_draft = null;

            if ( $normalized_draft['source_entry_id'] !== '' ) {
                $existing_draft = $this->getEditorDraftBySourceEntryId( $username, $normalized_draft['source_entry_id'] );
            }
            if ( ! is_array( $existing_draft ) && $normalized_draft['draft_id'] !== '' ) {
                $existing_draft = $this->getEditorDraftById( $username, $normalized_draft['draft_id'] );
            }

            if ( $normalized_draft['draft_id'] === '' ) {
                if ( is_array( $existing_draft ) && ! empty( $existing_draft['draft_id'] ) ) {
                    $normalized_draft['draft_id'] = (string) $existing_draft['draft_id'];
                } else {
                    $normalized_draft['draft_id'] = $this->generateDraftId();
                }
            }

            $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
            $normalized_draft['created_at_utc'] = is_array( $existing_draft ) && ! empty( $existing_draft['created_at_utc'] )
                ? (string) $existing_draft['created_at_utc']
                : $now_utc;
            $normalized_draft['updated_at_utc'] = $now_utc;

            $draft_directory = $this->getDraftDirectory( $username, $normalized_draft['draft_id'] );
            if ( ! is_dir( $draft_directory ) && ! mkdir( $draft_directory, 0775, true ) && ! is_dir( $draft_directory ) ) {
                throw new \RuntimeException( 'Unable to create draft directory "' . $draft_directory . '"' );
            }

            $draft_json = json_encode( $normalized_draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $draft_json ) ) {
                throw new \RuntimeException( 'Unable to encode editor draft.' );
            }

            if ( file_put_contents( $this->getDraftFilename( $username, $normalized_draft['draft_id'] ), $draft_json . PHP_EOL, LOCK_EX ) === false ) {
                throw new \RuntimeException( 'Unable to write editor draft.' );
            }

            if ( $normalized_draft['source_entry_id'] !== '' ) {
                foreach ( $this->listEditorDrafts( $username ) as $draft ) {
                    if (
                        (string) ( $draft['source_entry_id'] ?? '' ) !== $normalized_draft['source_entry_id'] ||
                        (string) ( $draft['draft_id'] ?? '' ) === $normalized_draft['draft_id']
                    ) {
                        continue;
                    }
                    $this->deleteEditorDraftUnlocked( $username, (string) $draft['draft_id'] );
                }
            }

            return( $normalized_draft );
        } ) );
    }

    public function deleteEditorDraft( string $username, string $draft_id ) : bool {
        return( $this->withLockedDraftStore( function() use ( $username, $draft_id ) : bool {
            return( $this->deleteEditorDraftUnlocked( $username, $draft_id ) );
        } ) );
    }

    public function deleteStaleDrafts( int $retention_days ) : int {
        return( $this->withLockedDraftStore( function() use ( $retention_days ) : int {
            if ( $retention_days < 1 || ! is_dir( $this->drafts_directory ) ) {
                return( 0 );
            }

            $cutoff_timestamp = time() - ( $retention_days * 86400 );
            $deleted_count = 0;
            foreach ( glob( $this->drafts_directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'draft.json' ) ?: [] as $draft_filename ) {
                if ( ! is_string( $draft_filename ) || ! is_file( $draft_filename ) ) {
                    continue;
                }

                $draft = $this->readDraftFile( $draft_filename );
                $draft_timestamp = $this->resolveDraftTimestamp( $draft_filename, $draft );
                if ( $draft_timestamp === null || $draft_timestamp > $cutoff_timestamp ) {
                    continue;
                }

                $draft_directory = dirname( $draft_filename );
                $user_directory = dirname( $draft_directory );
                $this->deleteDirectoryRecursively( $draft_directory );
                $this->cleanupEmptyParentDirectories( $user_directory );
                $deleted_count++;
            }

            return( $deleted_count );
        } ) );
    }

    protected function migrateLegacyDraftIfNeeded( string $username ) : void {
        $this->migrateLegacyDraftIfNeededUnlocked( $username );
    }

    protected function migrateLegacyDraftIfNeededUnlocked( string $username ) : void {
        $legacy_filename = $this->getLegacyDraftFilename( $username );
        if ( ! is_file( $legacy_filename ) || ! is_readable( $legacy_filename ) ) {
            return;
        }
        if ( ! empty( $this->getDraftFiles( $username ) ) ) {
            @unlink( $legacy_filename );
            return;
        }

        $legacy_json = file_get_contents( $legacy_filename );
        if ( ! is_string( $legacy_json ) || trim( $legacy_json ) === '' ) {
            @unlink( $legacy_filename );
            return;
        }

        $legacy_draft = json_decode( $legacy_json, true, 16 );
        if ( ! is_array( $legacy_draft ) ) {
            @unlink( $legacy_filename );
            return;
        }

        $legacy_draft['draft_id'] = $this->generateDraftId();
        $legacy_draft['source_entry_id'] = (string) ( $legacy_draft['loaded_entry_id'] ?? '' );
        $normalized_draft = $this->normalizeDraft( $legacy_draft );
        $now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );
        if ( $normalized_draft['created_at_utc'] === '' ) {
            $normalized_draft['created_at_utc'] = $now_utc;
        }
        if ( $normalized_draft['updated_at_utc'] === '' ) {
            $normalized_draft['updated_at_utc'] = $now_utc;
        }

        $draft_directory = $this->getDraftDirectory( $username, $normalized_draft['draft_id'] );
        if ( ! is_dir( $draft_directory ) && ! mkdir( $draft_directory, 0775, true ) && ! is_dir( $draft_directory ) ) {
            throw new \RuntimeException( 'Unable to create draft directory "' . $draft_directory . '"' );
        }

        $draft_json = json_encode( $normalized_draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $draft_json ) ) {
            throw new \RuntimeException( 'Unable to encode legacy editor draft.' );
        }

        if ( file_put_contents( $this->getDraftFilename( $username, $normalized_draft['draft_id'] ), $draft_json . PHP_EOL, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'Unable to write migrated editor draft.' );
        }
        @unlink( $legacy_filename );
    }

    protected function deleteEditorDraftUnlocked( string $username, string $draft_id ) : bool {
        $draft = $this->getEditorDraftById( $username, $draft_id );
        if ( ! is_array( $draft ) ) {
            return( false );
        }

        $draft_directory = $this->getDraftDirectory( $username, (string) $draft['draft_id'] );
        $this->deleteDirectoryRecursively( $draft_directory );
        $this->cleanupEmptyParentDirectories( dirname( $draft_directory ) );
        return( true );
    }

    protected function getDraftRootDirectory( string $username ) : string {
        return( $this->drafts_directory . DIRECTORY_SEPARATOR . $this->normalizeUsername( $username ) );
    }

    protected function getDraftDirectory( string $username, string $draft_id ) : string {
        return( $this->getDraftRootDirectory( $username ) . DIRECTORY_SEPARATOR . $this->normalizeDraftId( $draft_id ) );
    }

    protected function getDraftFilename( string $username, string $draft_id ) : string {
        return( $this->getDraftDirectory( $username, $draft_id ) . DIRECTORY_SEPARATOR . 'draft.json' );
    }

    protected function getLegacyDraftFilename( string $username ) : string {
        return( $this->getDraftRootDirectory( $username ) . DIRECTORY_SEPARATOR . 'editor.json' );
    }

    protected function getDraftFiles( string $username ) : array {
        $draft_root_directory = $this->getDraftRootDirectory( $username );
        if ( ! is_dir( $draft_root_directory ) ) {
            return( [] );
        }

        $draft_files = glob( $draft_root_directory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'draft.json' );
        if ( ! is_array( $draft_files ) ) {
            return( [] );
        }

        $filtered_files = [];
        foreach ( $draft_files as $draft_file ) {
            if ( is_string( $draft_file ) && is_file( $draft_file ) ) {
                $filtered_files[] = $draft_file;
            }
        }

        sort( $filtered_files );
        return( $filtered_files );
    }

    protected function withLockedDraftStore( callable $callback ) : mixed {
        if ( ! is_dir( $this->drafts_directory ) && ! @ mkdir( $this->drafts_directory, 0775, true ) && ! is_dir( $this->drafts_directory ) ) {
            throw new \RuntimeException( 'Unable to create drafts directory.' );
        }

        $lock_handle = @ fopen( $this->drafts_lock_filename, 'c+' );
        if ( $lock_handle === false ) {
            throw new \RuntimeException( 'Unable to open drafts lock file.' );
        }

        try {
            if ( ! @ flock( $lock_handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock drafts store.' );
            }

            return( $callback() );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function readDraftFile( string $draft_filename ) : ?array {
        if ( ! is_file( $draft_filename ) || ! is_readable( $draft_filename ) ) {
            return( null );
        }

        $draft_json = file_get_contents( $draft_filename );
        if ( ! is_string( $draft_json ) || trim( $draft_json ) === '' ) {
            return( null );
        }

        $draft_data = json_decode( $draft_json, true, 16 );
        if ( ! is_array( $draft_data ) ) {
            return( null );
        }

        return( $this->normalizeDraft( $draft_data ) );
    }

    protected function getDefaultDraft() : array {
        return(
            [
                'draft_id' => '',
                'source_entry_id' => '',
                'entry_type' => 'post',
                'scope' => 'root',
                'author_slug' => '',
                'loaded_entry_id' => '',
                'status' => 'unpublished',
                'published_at_utc' => '',
                'slug' => '',
                'title' => '',
                'summary' => '',
                'tags' => [],
                'plugin_settings' => [],
                'seo_title' => '',
                'seo_description' => '',
                'seo_social_title' => '',
                'seo_social_description' => '',
                'seo_social_image_media_id' => '',
                'seo_social_image' => [],
                'seo_canonical_url' => '',
                'seo_robots' => '',
                'seo_exclude_from_sitemap' => false,
                'content' => '',
                'parent_slug' => '',
                'sort_order' => 0,
                'aggregate_to_root' => true,
                'sticky' => false,
                'featured' => false,
                'show_in_navigation' => false,
                'featured_image_media_id' => '',
                'featured_image' => [],
                'fallback_cover_color' => '',
                'created_at_utc' => '',
                'updated_at_utc' => '',
            ]
        );
    }

    protected function normalizeDraft( array $draft_data ) : array {
        $entry_type = ( ! empty( $draft_data['entry_type'] ) && in_array( $draft_data['entry_type'], [ 'post', 'page' ], true ) ) ? (string) $draft_data['entry_type'] : 'post';
        $scope = ( ! empty( $draft_data['scope'] ) && $draft_data['scope'] === 'author' ) ? 'author' : 'root';
        $show_in_navigation = array_key_exists( 'show_in_navigation', $draft_data ) ? $this->toBool( $draft_data['show_in_navigation'] ) : ( $entry_type === 'page' );
        $source_entry_id = $this->normalizeDraftEntryId( (string) ( $draft_data['source_entry_id'] ?? ( $draft_data['loaded_entry_id'] ?? '' ) ) );

        return(
            [
                'draft_id' => $this->normalizeDraftId( (string) ( $draft_data['draft_id'] ?? '' ) ),
                'source_entry_id' => $source_entry_id,
                'entry_type' => $entry_type,
                'scope' => $scope,
                'author_slug' => $scope === 'author' ? $this->normalizePathPart( (string) ( $draft_data['author_slug'] ?? '' ) ) : '',
                'loaded_entry_id' => $source_entry_id,
                'status' => $this->normalizeEntryStatus( (string) ( $draft_data['status'] ?? 'unpublished' ) ),
                'published_at_utc' => trim( (string) ( $draft_data['published_at_utc'] ?? '' ) ),
                'slug' => $this->normalizePathPart( (string) ( $draft_data['slug'] ?? '' ) ),
                'title' => trim( (string) ( $draft_data['title'] ?? '' ) ),
                'summary' => trim( (string) ( $draft_data['summary'] ?? '' ) ),
                'tags' => $this->normalizeTagList( $draft_data['tags'] ?? [] ),
                'plugin_settings' => $this->normalizePluginSettings( $draft_data['plugin_settings'] ?? [] ),
                'seo_title' => trim( (string) ( $draft_data['seo_title'] ?? '' ) ),
                'seo_description' => trim( (string) ( $draft_data['seo_description'] ?? '' ) ),
                'seo_social_title' => trim( (string) ( $draft_data['seo_social_title'] ?? '' ) ),
                'seo_social_description' => trim( (string) ( $draft_data['seo_social_description'] ?? '' ) ),
                'seo_social_image_media_id' => trim( (string) ( $draft_data['seo_social_image_media_id'] ?? '' ) ),
                'seo_social_image' => $this->normalizeMediaImage( $draft_data['seo_social_image'] ?? [] ),
                'seo_canonical_url' => trim( (string) ( $draft_data['seo_canonical_url'] ?? '' ) ),
                'seo_robots' => $this->normalizeSeoRobots( (string) ( $draft_data['seo_robots'] ?? '' ) ),
                'seo_exclude_from_sitemap' => $this->toBool( $draft_data['seo_exclude_from_sitemap'] ?? false ),
                'content' => (string) ( $draft_data['content'] ?? '' ),
                'parent_slug' => $entry_type === 'page' ? $this->normalizePathPart( basename( str_replace( '\\', '/', (string) ( $draft_data['parent_slug'] ?? '' ) ) ) ) : '',
                'sort_order' => (int) ( $draft_data['sort_order'] ?? 0 ),
                'aggregate_to_root' => $entry_type === 'post' ? $this->toBool( $draft_data['aggregate_to_root'] ?? true ) : false,
                'sticky' => $this->toBool( $draft_data['sticky'] ?? false ),
                'featured' => $entry_type === 'post' ? $this->toBool( $draft_data['featured'] ?? false ) : false,
                'show_in_navigation' => $entry_type === 'page' ? $show_in_navigation : false,
                'featured_image_media_id' => trim( (string) ( $draft_data['featured_image_media_id'] ?? '' ) ),
                'featured_image' => $this->normalizeMediaImage( $draft_data['featured_image'] ?? [] ),
                'fallback_cover_color' => $entry_type === 'post' ? $this->normalizeFallbackCoverColor( $draft_data['fallback_cover_color'] ?? '' ) : '',
                'created_at_utc' => trim( (string) ( $draft_data['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $draft_data['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeFallbackCoverColor( mixed $value ) : string {
        $value = strtolower( trim( (string) $value ) );
        if ( in_array( $value, [ '', 'slate', 'blue', 'green', 'amber', 'berry' ], true ) ) {
            return( $value );
        }

        return( preg_match( '/^#[0-9a-f]{6}$/', $value ) === 1 ? $value : '' );
    }

    protected function normalizePluginSettings( mixed $plugin_settings ) : array {
        if ( ! is_array( $plugin_settings ) ) {
            return( [] );
        }

        $normalized = [];
        foreach ( $plugin_settings as $plugin_key => $settings ) {
            if ( ! is_string( $plugin_key ) || ! is_array( $settings ) ) {
                continue;
            }

            $plugin_key = strtolower( trim( $plugin_key ) );
            $plugin_key = preg_replace( '/[^a-z0-9_-]+/', '', $plugin_key ) ?? '';
            if ( $plugin_key === '' ) {
                continue;
            }

            $normalized[$plugin_key] = [];
            foreach ( $settings as $setting_key => $setting_value ) {
                if ( ! is_string( $setting_key ) ) {
                    continue;
                }

                $setting_key = strtolower( trim( $setting_key ) );
                $setting_key = preg_replace( '/[^a-z0-9_-]+/', '', $setting_key ) ?? '';
                if ( $setting_key === '' ) {
                    continue;
                }

                if ( is_bool( $setting_value ) ) {
                    $normalized[$plugin_key][$setting_key] = $setting_value;
                } elseif ( is_scalar( $setting_value ) || $setting_value === null ) {
                    $normalized[$plugin_key][$setting_key] = trim( (string) $setting_value );
                }
            }

            if ( empty( $normalized[$plugin_key] ) ) {
                unset( $normalized[$plugin_key] );
            }
        }

        return( $normalized );
    }

    protected function normalizeMediaImage( mixed $media_image ) : array {
        if ( ! is_array( $media_image ) ) {
            return( [] );
        }

        $normalized_media_image = [
            'media_id' => trim( (string) ( $media_image['media_id'] ?? '' ) ),
            'owner_username' => $this->normalizePathPart( (string) ( $media_image['owner_username'] ?? '' ) ),
            'filename' => basename( trim( (string) ( $media_image['filename'] ?? '' ) ) ),
            'url' => trim( (string) ( $media_image['url'] ?? '' ) ),
            'alt_text' => trim( (string) ( $media_image['alt_text'] ?? '' ) ),
            'mime' => trim( (string) ( $media_image['mime'] ?? '' ) ),
            'width' => max( 0, (int) ( $media_image['width'] ?? 0 ) ),
            'height' => max( 0, (int) ( $media_image['height'] ?? 0 ) ),
            'bytes' => max( 0, (int) ( $media_image['bytes'] ?? 0 ) ),
            'derivative_key' => trim( (string) ( $media_image['derivative_key'] ?? '' ) ),
        ];

        if ( $normalized_media_image['media_id'] === '' || $normalized_media_image['url'] === '' ) {
            return( [] );
        }

        return( $normalized_media_image );
    }

    protected function normalizeTagList( mixed $values ) : array {
        if ( is_string( $values ) ) {
            $values = preg_split( '/[\s,]+/u', $values ) ?: [];
        }
        if ( ! is_array( $values ) ) {
            return( [] );
        }

        $normalized_values = [];
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }

            $value = strtolower( trim( ltrim( $value, '#' ) ) );
            $value = preg_replace( '/\s+/u', '-', $value ) ?? '';
            $value = preg_replace( '/[^a-z0-9_-]+/', '-', $value ) ?? '';
            $value = preg_replace( '/-+/', '-', $value ) ?? '';
            $value = trim( $value, '-_' );
            if ( $value !== '' ) {
                $normalized_values[] = $value;
            }
        }

        return( array_values( array_unique( $normalized_values ) ) );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        if ( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) === 1 ) {
            return( $username );
        }

        return( 'unknown' );
    }

    protected function normalizePathPart( string $value ) : string {
        return( strtolower( trim( $value, " \t\n\r\0\x0B/" ) ) );
    }

    protected function normalizeDraftId( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_-]+/', '', $value ) ?? '';
        return( $value );
    }

    protected function normalizeDraftEntryId( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_-]+/', '', $value ) ?? '';
        return( $value );
    }

    protected function normalizeEntryStatus( string $status ) : string {
        $status = strtolower( trim( $status ) );
        if ( in_array( $status, [ 'published', 'unpublished', 'pending_review' ], true ) ) {
            return( $status );
        }

        return( 'unpublished' );
    }

    protected function normalizeSeoRobots( string $value ) : string {
        $value = strtolower( trim( $value ) );
        return( in_array( $value, [ '', 'index, follow', 'index, nofollow', 'noindex, follow', 'noindex, nofollow' ], true ) ? $value : '' );
    }

    protected function generateDraftId() : string {
        return( 'draft_' . gmdate( 'Ymd_His' ) . '_' . substr( sha1( uniqid( '', true ) ), 0, 8 ) );
    }

    protected function deleteDirectoryRecursively( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof \SplFileInfo ) {
                continue;
            }
            if ( $file_info->isDir() ) {
                @rmdir( $file_info->getPathname() );
                continue;
            }
            @unlink( $file_info->getPathname() );
        }

        @rmdir( $directory );
    }

    protected function cleanupEmptyParentDirectories( string $directory ) : void {
        $root_directory = $this->drafts_directory;
        while ( $directory !== '' && str_starts_with( $directory, $root_directory ) && $directory !== $root_directory ) {
            $items = scandir( $directory );
            if ( ! is_array( $items ) || count( $items ) > 2 ) {
                break;
            }
            @rmdir( $directory );
            $directory = dirname( $directory );
        }
    }

    protected function resolveDraftTimestamp( string $draft_filename, ?array $draft ) : ?int {
        $updated_at_utc = is_array( $draft ) ? trim( (string) ( $draft['updated_at_utc'] ?? $draft['created_at_utc'] ?? '' ) ) : '';
        if ( $updated_at_utc !== '' ) {
            $timestamp = strtotime( $updated_at_utc );
            if ( is_int( $timestamp ) && $timestamp > 0 ) {
                return( $timestamp );
            }
        }

        $file_timestamp = @ filemtime( $draft_filename );
        return( is_int( $file_timestamp ) && $file_timestamp > 0 ? $file_timestamp : null );
    }

    protected function toBool( mixed $value ) : bool {
        if ( is_bool( $value ) ) {
            return( $value );
        }

        if ( is_int( $value ) ) {
            return( $value === 1 );
        }

        if ( ! is_string( $value ) ) {
            return( false );
        }

        return( in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true ) );
    }

}
