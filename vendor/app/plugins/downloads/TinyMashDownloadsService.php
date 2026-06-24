<?php

use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashUserRepository;

class TinyMashDownloadsService {

    protected const MAX_UPLOAD_BYTES = 209715200;
    protected const ALLOWED_EXTENSIONS = [
        '7z',
        'avi',
        'avif',
        'bz2',
        'csv',
        'doc',
        'docx',
        'epub',
        'gif',
        'gz',
        'heic',
        'heif',
        'jpeg',
        'jpg',
        'json',
        'm4v',
        'md',
        'mkv',
        'mov',
        'mp4',
        'mpeg',
        'mpg',
        'nfo',
        'odp',
        'ods',
        'odt',
        'pdf',
        'png',
        'ppt',
        'pptx',
        'rtf',
        'tar',
        'tif',
        'tiff',
        'tgz',
        'txt',
        'webm',
        'webp',
        'wmv',
        'xls',
        'xlsx',
        'xml',
        'xz',
        'zip',
    ];

    protected string $store_filename;
    protected string $files_directory;
    protected TinyMashUserRepository $user_repository;
    protected string $locale;

    public function __construct( string $store_filename, string $files_directory, TinyMashUserRepository $user_repository, string $locale = 'en' ) {
        $this->store_filename = $store_filename;
        $this->files_directory = rtrim( $files_directory, DIRECTORY_SEPARATOR );
        $this->user_repository = $user_repository;
        $this->locale = $this->normalizeLocale( $locale );
    }

    public function listCatalogues( ?string $scope = null, ?string $author_slug = null, bool $public_only = false ) : array {
        $store = $this->readStore();
        $catalogues = is_array( $store['catalogues'] ?? null ) ? $store['catalogues'] : [];
        $records = [];

        foreach ( $catalogues as $catalogue ) {
            if ( ! is_array( $catalogue ) ) {
                continue;
            }

            $catalogue = $this->normalizeCatalogueRecord( $catalogue );
            if ( $catalogue['id'] === '' || $catalogue['slug'] === '' ) {
                continue;
            }
            if ( $public_only && empty( $catalogue['enabled'] ) ) {
                continue;
            }
            if ( $scope !== null && $catalogue['scope'] !== $scope ) {
                continue;
            }
            if ( $author_slug !== null && $catalogue['author_slug'] !== $this->normalizeAuthorSlug( $author_slug ) ) {
                continue;
            }

            $records[] = $this->enrichCatalogue( $catalogue, $catalogues );
        }

        usort(
            $records,
            static function( array $left, array $right ) : int {
                $left_order = (int) ( $left['sort_order'] ?? 0 );
                $right_order = (int) ( $right['sort_order'] ?? 0 );
                if ( $left_order !== $right_order ) {
                    return( $left_order <=> $right_order );
                }
                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        return( $records );
    }

    public function listAccessibleCatalogues( bool $is_superadmin, string $current_username ) : array {
        if ( $is_superadmin ) {
            return( $this->listCatalogues() );
        }

        return( $this->listCatalogues( 'author', $current_username ) );
    }

    public function getAllowedExtensions() : array {
        return( self::ALLOWED_EXTENSIONS );
    }

    public function listLinkTargets( bool $is_superadmin, string $current_username ) : array {
        $catalogues = $this->listAccessibleCatalogues( $is_superadmin, $current_username );
        $targets = [];

        foreach ( $catalogues as $catalogue ) {
            if ( empty( $catalogue['enabled'] ) || (string) ( $catalogue['public_url'] ?? '' ) === '' ) {
                continue;
            }

            $scope_label = (string) ( $catalogue['scope'] ?? 'root' ) === 'author'
                ? ( (string) ( $catalogue['author_slug'] ?? '' ) . ' downloads' )
                : 'root downloads';
            $catalogue_title = (string) ( $catalogue['title'] ?? '' );
            $targets[] = [
                'id' => 'downloads:catalogue:' . (string) ( $catalogue['id'] ?? '' ),
                'title' => $catalogue_title,
                'label' => $catalogue_title . ' (download folder, ' . $scope_label . ')',
                'type' => 'download_catalogue',
                'scope' => (string) ( $catalogue['scope'] ?? 'root' ),
                'author_slug' => (string) ( $catalogue['author_slug'] ?? '' ),
                'path' => ltrim( (string) ( $catalogue['public_url'] ?? '' ), '/' ),
                'url' => (string) ( $catalogue['public_url'] ?? '' ),
                'status' => 'published',
            ];

            foreach ( $this->listFilesForCatalogue( (string) ( $catalogue['id'] ?? '' ), true ) as $file ) {
                $file_title = (string) ( $file['title'] ?? $file['original_filename'] ?? '' );
                $targets[] = [
                    'id' => 'downloads:file:' . (string) ( $file['id'] ?? '' ),
                    'title' => $file_title,
                    'label' => $file_title . ' (download file, ' . (string) ( $catalogue['public_url'] ?? '' ) . ')',
                    'type' => 'download_file',
                    'scope' => (string) ( $catalogue['scope'] ?? 'root' ),
                    'author_slug' => (string) ( $catalogue['author_slug'] ?? '' ),
                    'path' => ltrim( (string) ( $file['public_url'] ?? '' ), '/' ),
                    'url' => (string) ( $file['public_url'] ?? '' ),
                    'status' => 'published',
                ];
            }
        }

        return( $targets );
    }

    public function getCatalogueById( string $id ) : array {
        $id = trim( $id );
        if ( $id === '' ) {
            return( [] );
        }

        foreach ( $this->listCatalogues() as $catalogue ) {
            if ( (string) ( $catalogue['id'] ?? '' ) === $id ) {
                return( $catalogue );
            }
        }

        return( [] );
    }

    public function findPublicCatalogueByPath( string $scope, string $author_slug, string $path ) : array {
        $scope = $this->normalizeScope( $scope );
        $author_slug = $scope === 'author' ? $this->normalizeAuthorSlug( $author_slug ) : '';
        $path = $this->normalizePath( $path );

        foreach ( $this->listCatalogues( $scope, $author_slug, true ) as $catalogue ) {
            if ( (string) ( $catalogue['path'] ?? '' ) === $path ) {
                return( $catalogue );
            }
        }

        return( [] );
    }

    public function getPublicCatalogueTree( string $scope, string $author_slug, string $path ) : array {
        $catalogue = $this->findPublicCatalogueByPath( $scope, $author_slug, $path );
        if ( empty( $catalogue ) ) {
            return( [] );
        }

        $children = [];
        foreach ( $this->listCatalogues( (string) $catalogue['scope'], (string) $catalogue['author_slug'], true ) as $candidate ) {
            if ( (string) ( $candidate['parent_id'] ?? '' ) === (string) $catalogue['id'] ) {
                $children[] = $candidate;
            }
        }

        return(
            [
                'catalogue' => $catalogue,
                'children' => $children,
                'files' => $this->listFilesForCatalogue( (string) $catalogue['id'], true ),
                'breadcrumbs' => $this->buildBreadcrumbs( $catalogue ),
                'parent' => $this->getParentCatalogue( $catalogue ),
            ]
        );
    }

    public function getPublicRootTree( string $scope, string $author_slug ) : array {
        $scope = $this->normalizeScope( $scope );
        $author_slug = $scope === 'author' ? $this->normalizeAuthorSlug( $author_slug ) : '';
        $children = [];

        foreach ( $this->listCatalogues( $scope, $author_slug, true ) as $candidate ) {
            if ( (string) ( $candidate['parent_id'] ?? '' ) === '' ) {
                $children[] = $candidate;
            }
        }

        return(
            [
                'catalogue' => [
                    'title' => $scope === 'author' ? $this->user_repository->getDisplayLabelByAuthorSlug( $author_slug ) . ' downloads' : 'Downloads',
                    'scope' => $scope,
                    'author_slug' => $author_slug,
                    'path' => '',
                    'public_url' => $scope === 'author' ? '/' . $author_slug . '/downloads' : '/downloads',
                    'intro' => '',
                ],
                'children' => $children,
                'files' => [],
                'breadcrumbs' => [],
                'parent' => [],
            ]
        );
    }

    public function getPublicTreeByUrl( string $url ) : array {
        $parts = parse_url( trim( $url ) );
        if ( $parts === false ) {
            return( [] );
        }

        $path = trim( (string) ( $parts['path'] ?? '' ) );
        if ( $path === '' ) {
            return( [] );
        }
        if ( ! str_starts_with( $path, '/' ) ) {
            $path = '/' . ltrim( $path, '/' );
        }

        $segments = array_values(
            array_filter(
                explode( '/', trim( $path, '/' ) ),
                static function( string $segment ) : bool {
                    return( $segment !== '' );
                }
            )
        );

        if ( count( $segments ) === 1 && $segments[0] === 'downloads' ) {
            return( $this->getPublicRootTree( 'root', '' ) );
        }

        if ( count( $segments ) > 1 && $segments[0] === 'downloads' ) {
            return( $this->getPublicCatalogueTree( 'root', '', implode( '/', array_slice( $segments, 1 ) ) ) );
        }

        if ( count( $segments ) === 2 && $segments[1] === 'downloads' ) {
            $author_slug = $this->normalizeAuthorSlug( $segments[0] );
            if ( $author_slug === '' || ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
                return( [] );
            }

            return( $this->getPublicRootTree( 'author', $author_slug ) );
        }

        if ( count( $segments ) > 2 && $segments[1] === 'downloads' ) {
            $author_slug = $this->normalizeAuthorSlug( $segments[0] );
            if ( $author_slug === '' || ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
                return( [] );
            }

            return( $this->getPublicCatalogueTree( 'author', $author_slug, implode( '/', array_slice( $segments, 2 ) ) ) );
        }

        return( [] );
    }

    public function getTreeRootUrl( array $tree ) : string {
        $catalogue = is_array( $tree['catalogue'] ?? null ) ? $tree['catalogue'] : [];
        return( (string) ( $catalogue['scope'] ?? '' ) === 'author'
            ? '/' . (string) ( $catalogue['author_slug'] ?? '' ) . '/downloads'
            : '/downloads' );
    }

    public function isTreeWithinRootUrl( array $tree, string $root_url ) : bool {
        $root_tree = $this->getPublicTreeByUrl( $root_url );
        if ( empty( $root_tree ) ) {
            return( false );
        }

        $current = is_array( $tree['catalogue'] ?? null ) ? $tree['catalogue'] : [];
        $root = is_array( $root_tree['catalogue'] ?? null ) ? $root_tree['catalogue'] : [];
        $current_url = (string) ( $current['public_url'] ?? '' );
        $root_public_url = (string) ( $root['public_url'] ?? '' );
        if ( $current_url === '' || $root_public_url === '' ) {
            return( false );
        }

        return( $current_url === $root_public_url || str_starts_with( $current_url . '/', rtrim( $root_public_url, '/' ) . '/' ) );
    }

    public function trimBreadcrumbsToRootUrl( array $breadcrumbs, string $root_url ) : array {
        $root_tree = $this->getPublicTreeByUrl( $root_url );
        if ( empty( $root_tree ) ) {
            return( $breadcrumbs );
        }

        $root_public_url = (string) ( $root_tree['catalogue']['public_url'] ?? '' );
        if ( $root_public_url === '' ) {
            return( $breadcrumbs );
        }

        $trimmed = [];
        $inside_root = false;
        foreach ( $breadcrumbs as $breadcrumb ) {
            if ( ! is_array( $breadcrumb ) ) {
                continue;
            }
            if ( (string) ( $breadcrumb['url'] ?? '' ) === $root_public_url ) {
                $inside_root = true;
            }
            if ( $inside_root ) {
                $trimmed[] = $breadcrumb;
            }
        }

        return( $inside_root ? $trimmed : $breadcrumbs );
    }

    public function saveCatalogue( array $data, bool $is_superadmin, string $current_username ) : array {
        $submitted_id = trim( (string) ( $data['id'] ?? '' ) );
        $record = $this->normalizeCatalogueSubmission( $data, $is_superadmin, $current_username );

        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $submitted_id, $record, $is_superadmin, $current_username ) : array {
                    $catalogues = is_array( $store['catalogues'] ?? null ) ? $store['catalogues'] : [];
                    $updated_catalogues = [];
                    $saved_record = $record;
                    $replaced = false;

                    foreach ( $catalogues as $existing ) {
                        if ( ! is_array( $existing ) ) {
                            continue;
                        }

                        $existing = $this->normalizeCatalogueRecord( $existing );
                        if ( $existing['id'] === '' ) {
                            continue;
                        }

                        if ( $submitted_id !== '' && $existing['id'] === $submitted_id ) {
                            if ( ! $this->canManageCatalogue( $existing, $is_superadmin, $current_username ) ) {
                                throw new \InvalidArgumentException( 'permission' );
                            }
                            $saved_record['id'] = $existing['id'];
                            $saved_record['created_at_utc'] = $existing['created_at_utc'] !== '' ? $existing['created_at_utc'] : gmdate( 'Y-m-d\TH:i:s\Z' );
                            $updated_catalogues[] = $saved_record;
                            $replaced = true;
                            continue;
                        }

                        if (
                            $existing['scope'] === $saved_record['scope']
                            && $existing['author_slug'] === $saved_record['author_slug']
                            && $existing['parent_id'] === $saved_record['parent_id']
                            && $existing['slug'] === $saved_record['slug']
                        ) {
                            throw new \InvalidArgumentException( 'slug_conflict' );
                        }

                        $updated_catalogues[] = $existing;
                    }

                    if ( $saved_record['parent_id'] !== '' && ! $this->parentExistsInRecords( $saved_record, $updated_catalogues ) ) {
                        throw new \InvalidArgumentException( 'parent' );
                    }

                    if ( ! $replaced ) {
                        $saved_record['id'] = $this->buildRecordId( 'catalogue' );
                        $saved_record['created_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $updated_catalogues[] = $saved_record;
                    }

                    $store['version'] = 1;
                    $store['catalogues'] = array_values( $updated_catalogues );
                    $store['files'] = is_array( $store['files'] ?? null ) ? array_values( $store['files'] ) : [];

                    return(
                        [
                            'data' => $store,
                            'result' => $this->enrichCatalogue( $saved_record, $updated_catalogues ),
                        ]
                    );
                },
                $this->defaultStore()
            )
        );
    }

    public function listFilesForCatalogue( string $catalogue_id, bool $public_only = false ) : array {
        $catalogue_id = trim( $catalogue_id );
        if ( $catalogue_id === '' ) {
            return( [] );
        }

        $store = $this->readStore();
        $files = is_array( $store['files'] ?? null ) ? $store['files'] : [];
        $records = [];
        foreach ( $files as $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }

            $file = $this->normalizeFileRecord( $file );
            if ( $file['id'] === '' || $file['catalogue_id'] !== $catalogue_id ) {
                continue;
            }
            if ( $public_only && empty( $file['enabled'] ) ) {
                continue;
            }

            $catalogue = $this->getCatalogueById( $catalogue_id );
            $records[] = $this->enrichFile( $file, $catalogue );
        }

        usort(
            $records,
            static function( array $left, array $right ) : int {
                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        return( $records );
    }

    public function uploadFile( string $catalogue_id, array $file_upload, array $data, bool $is_superadmin, string $current_username ) : array {
        $uploaded_files = $this->uploadFiles( $catalogue_id, $file_upload, $data, $is_superadmin, $current_username );
        return( $uploaded_files[0] ?? [] );
    }

    public function uploadFiles( string $catalogue_id, array $file_upload, array $data, bool $is_superadmin, string $current_username ) : array {
        $catalogue = $this->getCatalogueById( $catalogue_id );
        if ( empty( $catalogue ) || ! $this->canManageCatalogue( $catalogue, $is_superadmin, $current_username ) ) {
            throw new \InvalidArgumentException( 'catalogue' );
        }

        $uploads = $this->normalizeUploadedFiles( $file_upload );
        if ( empty( $uploads ) ) {
            throw new \InvalidArgumentException( 'upload' );
        }

        $records = [];
        foreach ( $uploads as $upload ) {
            $records[] = $this->storeUploadedFile( $catalogue_id, $catalogue, $upload, $data, count( $uploads ) === 1 );
        }

        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $records ) : array {
                $store['version'] = 1;
                $store['catalogues'] = is_array( $store['catalogues'] ?? null ) ? array_values( $store['catalogues'] ) : [];
                $files = is_array( $store['files'] ?? null ) ? $store['files'] : [];
                foreach ( $records as $record ) {
                    $files[] = $record;
                }
                $store['files'] = array_values( $files );
                return( $store );
            },
            $this->defaultStore()
        );

        return( array_map( fn( array $record ) : array => $this->enrichFile( $record, $catalogue ), $records ) );
    }

    protected function storeUploadedFile( string $catalogue_id, array $catalogue, array $file_upload, array $data, bool $single_upload ) : array {
        $tmp_name = (string) ( $file_upload['tmp_name'] ?? '' );
        $original_name = basename( (string) ( $file_upload['name'] ?? '' ) );
        $error = (int) ( $file_upload['error'] ?? UPLOAD_ERR_NO_FILE );
        $size = (int) ( $file_upload['size'] ?? 0 );
        if ( $error !== UPLOAD_ERR_OK || $tmp_name === '' || ! is_file( $tmp_name ) ) {
            throw new \InvalidArgumentException( 'upload' );
        }
        if ( $size < 1 || $size > self::MAX_UPLOAD_BYTES ) {
            throw new \InvalidArgumentException( 'size' );
        }

        $extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( $extension === '' || ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
            throw new \InvalidArgumentException( 'type' );
        }

        $file_id = $this->buildRecordId( 'file' );
        $stored_filename = $file_id . '.' . $extension;
        $target_directory = $this->files_directory . DIRECTORY_SEPARATOR . $file_id;
        if ( ! is_dir( $target_directory ) && ! @ mkdir( $target_directory, 0775, true ) && ! is_dir( $target_directory ) ) {
            throw new \RuntimeException( 'Unable to create download file directory.' );
        }

        $target_filename = $target_directory . DIRECTORY_SEPARATOR . $stored_filename;
        $moved = is_uploaded_file( $tmp_name )
            ? @ move_uploaded_file( $tmp_name, $target_filename )
            : @ copy( $tmp_name, $target_filename );
        if ( ! $moved || ! is_file( $target_filename ) ) {
            throw new \RuntimeException( 'Unable to store uploaded file.' );
        }

        $record = $this->normalizeFileRecord(
            [
                'id' => $file_id,
                'catalogue_id' => $catalogue_id,
                'title' => $single_upload ? (string) ( $data['title'] ?? '' ) : '',
                'description' => (string) ( $data['description'] ?? '' ),
                'original_filename' => $original_name,
                'stored_filename' => $stored_filename,
                'size_bytes' => filesize( $target_filename ) ?: $size,
                'mime_type' => $this->detectMimeType( $target_filename ),
                'enabled' => ! empty( $data['enabled'] ),
                'file_date_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'download_count' => 0,
                'last_downloaded_at_utc' => '',
                'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ]
        );

        if ( $record['title'] === '' ) {
            $record['title'] = $record['original_filename'];
        }

        return( $record );
    }

    protected function normalizeUploadedFiles( array $file_upload ) : array {
        $names = $file_upload['name'] ?? null;
        if ( is_array( $names ) ) {
            $uploads = [];
            foreach ( $names as $index => $name ) {
                $uploads[] = [
                    'name' => $name,
                    'type' => is_array( $file_upload['type'] ?? null ) ? ( $file_upload['type'][$index] ?? '' ) : '',
                    'tmp_name' => is_array( $file_upload['tmp_name'] ?? null ) ? ( $file_upload['tmp_name'][$index] ?? '' ) : '',
                    'error' => is_array( $file_upload['error'] ?? null ) ? ( $file_upload['error'][$index] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE,
                    'size' => is_array( $file_upload['size'] ?? null ) ? ( $file_upload['size'][$index] ?? 0 ) : 0,
                ];
            }

            return(
                array_values(
                    array_filter(
                        $uploads,
                        static fn( array $upload ) : bool => (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE || trim( (string) ( $upload['name'] ?? '' ) ) !== ''
                    )
                )
            );
        }

        return( [ $file_upload ] );
    }

    public function saveFileMetadata( array $data, bool $is_superadmin, string $current_username ) : array {
        $submitted_id = trim( (string) ( $data['id'] ?? '' ) );
        if ( $submitted_id === '' ) {
            throw new \InvalidArgumentException( 'file' );
        }

        return(
            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $data, $submitted_id, $is_superadmin, $current_username ) : array {
                    $catalogues = is_array( $store['catalogues'] ?? null ) ? $store['catalogues'] : [];
                    $files = is_array( $store['files'] ?? null ) ? $store['files'] : [];
                    $updated_files = [];
                    $saved_file = [];
                    $saved_catalogue = [];

                    foreach ( $files as $file ) {
                        if ( ! is_array( $file ) ) {
                            continue;
                        }

                        $file = $this->normalizeFileRecord( $file );
                        if ( $file['id'] !== $submitted_id ) {
                            if ( $file['id'] !== '' ) {
                                $updated_files[] = $file;
                            }
                            continue;
                        }

                        $catalogue = [];
                        foreach ( $catalogues as $candidate ) {
                            if ( is_array( $candidate ) ) {
                                $candidate = $this->normalizeCatalogueRecord( $candidate );
                                if ( $candidate['id'] === (string) $file['catalogue_id'] ) {
                                    $catalogue = $candidate;
                                    break;
                                }
                            }
                        }
                        if ( empty( $catalogue ) || ! $this->canManageCatalogue( $catalogue, $is_superadmin, $current_username ) ) {
                            throw new \InvalidArgumentException( 'permission' );
                        }

                        $title = mb_substr( mb_trim( (string) ( $data['title'] ?? '' ) ), 0, 160 );
                        $file['title'] = $title !== '' ? $title : $file['original_filename'];
                        $file['description'] = mb_substr( mb_trim( (string) ( $data['description'] ?? '' ) ), 0, 5000 );
                        $file['file_date_utc'] = $this->normalizeFileDateInput( (string) ( $data['file_date'] ?? '' ), $file['file_date_utc'] );
                        $file['enabled'] = ! empty( $data['enabled'] );
                        $file['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $saved_file = $file;
                        $saved_catalogue = $catalogue;
                        $updated_files[] = $file;
                    }

                    if ( empty( $saved_file ) ) {
                        throw new \InvalidArgumentException( 'file' );
                    }

                    $store['version'] = 1;
                    $store['catalogues'] = is_array( $store['catalogues'] ?? null ) ? array_values( $store['catalogues'] ) : [];
                    $store['files'] = array_values( $updated_files );

                    return(
                        [
                            'data' => $store,
                            'result' => $this->enrichFile( $saved_file, $this->enrichCatalogue( $saved_catalogue, $catalogues ) ),
                        ]
                    );
                },
                $this->defaultStore()
            )
        );
    }

    public function deleteFile( string $id, bool $is_superadmin, string $current_username ) : bool {
        $file = $this->getFileById( $id );
        if ( empty( $file ) ) {
            return( false );
        }

        $catalogue = $this->getCatalogueById( (string) ( $file['catalogue_id'] ?? '' ) );
        if ( empty( $catalogue ) || ! $this->canManageCatalogue( $catalogue, $is_superadmin, $current_username ) ) {
            return( false );
        }

        $deleted = (bool) TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $id ) : array {
                $files = is_array( $store['files'] ?? null ) ? $store['files'] : [];
                $updated_files = [];
                $found = false;
                foreach ( $files as $file ) {
                    if ( ! is_array( $file ) ) {
                        continue;
                    }
                    $file = $this->normalizeFileRecord( $file );
                    if ( $file['id'] === $id ) {
                        $found = true;
                        continue;
                    }
                    if ( $file['id'] !== '' ) {
                        $updated_files[] = $file;
                    }
                }
                $store['version'] = 1;
                $store['catalogues'] = is_array( $store['catalogues'] ?? null ) ? array_values( $store['catalogues'] ) : [];
                $store['files'] = array_values( $updated_files );
                return( [ 'data' => $store, 'result' => $found ] );
            },
            $this->defaultStore()
        );

        if ( $deleted ) {
            $this->removeFileDirectory( $id );
        }

        return( $deleted );
    }

    public function resolveDownload( string $file_id, string $scope, string $author_slug ) : array {
        $file = $this->getFileById( $file_id );
        if ( empty( $file ) || empty( $file['enabled'] ) ) {
            return( [] );
        }

        $catalogue = $this->getCatalogueById( (string) ( $file['catalogue_id'] ?? '' ) );
        if ( empty( $catalogue ) || empty( $catalogue['enabled'] ) ) {
            return( [] );
        }

        $scope = $this->normalizeScope( $scope );
        $author_slug = $scope === 'author' ? $this->normalizeAuthorSlug( $author_slug ) : '';
        if ( (string) $catalogue['scope'] !== $scope || (string) $catalogue['author_slug'] !== $author_slug ) {
            return( [] );
        }

        if ( $scope === 'author' && ! $this->user_repository->isAuthorContentPublic( $author_slug ) ) {
            return( [] );
        }

        $filename = $this->getStoredFilePath( $file );
        if ( $filename === '' || ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( [] );
        }

        $this->recordDownload( (string) $file['id'] );
        $file = $this->enrichFile( $file, $catalogue );
        $file['storage_path'] = $filename;
        return( [ 'file' => $file, 'catalogue' => $catalogue ] );
    }

    public function canManageCatalogue( array $catalogue, bool $is_superadmin, string $current_username ) : bool {
        if ( $is_superadmin ) {
            return( true );
        }

        return(
            (string) ( $catalogue['scope'] ?? '' ) === 'author'
            && (string) ( $catalogue['author_slug'] ?? '' ) === $this->normalizeAuthorSlug( $current_username )
        );
    }

    public function normalizeSlug( string $slug ) : string {
        $slug = strtolower( mb_trim( $slug ) );
        $slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug ) ?? '';
        return( trim( $slug, '-_' ) );
    }

    public function normalizePath( string $path ) : string {
        $path = strtolower( trim( $path ) );
        $path = (string) ( parse_url( $path, PHP_URL_PATH ) ?? $path );
        $path = trim( preg_replace( '#/+#', '/', $path ) ?? $path, '/' );
        $segments = [];
        foreach ( explode( '/', $path ) as $segment ) {
            $segment = $this->normalizeSlug( $segment );
            if ( $segment !== '' ) {
                $segments[] = $segment;
            }
        }
        return( implode( '/', $segments ) );
    }

    public function formatBytes( int $bytes ) : string {
        if ( $bytes >= 1073741824 ) {
            return( $this->formatDecimal( $bytes / 1073741824, 1 ) . ' GB' );
        }
        if ( $bytes >= 1048576 ) {
            return( $this->formatDecimal( $bytes / 1048576, 1 ) . ' MB' );
        }
        if ( $bytes >= 1024 ) {
            return( $this->formatDecimal( $bytes / 1024, 1 ) . ' KB' );
        }
        return( $this->formatInteger( $bytes ) . ' B' );
    }

    protected function formatDecimal( float $value, int $fraction_digits ) : string {
        if ( class_exists( \NumberFormatter::class ) ) {
            $formatter = new \NumberFormatter( $this->locale, \NumberFormatter::DECIMAL );
            $formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $fraction_digits );
            $formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $fraction_digits );
            $formatted = $formatter->format( $value );
            if ( is_string( $formatted ) && $formatted !== '' ) {
                return( $formatted );
            }
        }

        return( number_format( $value, $fraction_digits, $this->getDecimalSeparator(), $this->getThousandsSeparator() ) );
    }

    protected function formatInteger( int $value ) : string {
        if ( class_exists( \NumberFormatter::class ) ) {
            $formatter = new \NumberFormatter( $this->locale, \NumberFormatter::DECIMAL );
            $formatter->setAttribute( \NumberFormatter::FRACTION_DIGITS, 0 );
            $formatted = $formatter->format( $value );
            if ( is_string( $formatted ) && $formatted !== '' ) {
                return( $formatted );
            }
        }

        return( number_format( $value, 0, $this->getDecimalSeparator(), $this->getThousandsSeparator() ) );
    }

    protected function getDecimalSeparator() : string {
        $language = strtolower( strtok( $this->locale, '_' ) ?: $this->locale );
        return( in_array( $language, [ 'sv', 'da', 'de', 'es', 'fi', 'fr', 'it', 'nl', 'no', 'pl', 'pt' ], true ) ? ',' : '.' );
    }

    protected function getThousandsSeparator() : string {
        $language = strtolower( strtok( $this->locale, '_' ) ?: $this->locale );
        return( in_array( $language, [ 'sv', 'fi', 'fr', 'no' ], true ) ? ' ' : ',' );
    }

    protected function normalizeLocale( string $locale ) : string {
        $locale = trim( str_replace( '-', '_', $locale ) );
        $locale = preg_replace( '/[^A-Za-z0-9_]/', '', $locale ) ?? '';
        return( $locale !== '' ? $locale : 'en' );
    }

    protected function normalizeCatalogueSubmission( array $data, bool $is_superadmin, string $current_username ) : array {
        $scope = $is_superadmin ? $this->normalizeScope( (string) ( $data['scope'] ?? 'root' ) ) : 'author';
        $author_slug = $scope === 'author'
            ? $this->normalizeAuthorSlug( $is_superadmin ? (string) ( $data['author_slug'] ?? '' ) : $current_username )
            : '';
        if ( $scope === 'author' && $author_slug === '' ) {
            throw new \InvalidArgumentException( 'author' );
        }

        $title = mb_substr( mb_trim( (string) ( $data['title'] ?? '' ) ), 0, 160 );
        if ( $title === '' ) {
            throw new \InvalidArgumentException( 'title' );
        }

        $slug = $this->normalizeSlug( (string) ( $data['slug'] ?? '' ) );
        if ( $slug === '' ) {
            $slug = $this->normalizeSlug( $title );
        }
        if ( $slug === '' ) {
            throw new \InvalidArgumentException( 'slug' );
        }

        return(
            [
                'id' => trim( (string) ( $data['id'] ?? '' ) ),
                'scope' => $scope,
                'author_slug' => $author_slug,
                'parent_id' => trim( (string) ( $data['parent_id'] ?? '' ) ),
                'title' => $title,
                'slug' => $slug,
                'intro' => mb_substr( mb_trim( (string) ( $data['intro'] ?? '' ) ), 0, 5000 ),
                'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
                'enabled' => ! empty( $data['enabled'] ),
                'created_at_utc' => '',
                'updated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            ]
        );
    }

    protected function normalizeCatalogueRecord( array $record ) : array {
        $scope = $this->normalizeScope( (string) ( $record['scope'] ?? 'root' ) );
        $author_slug = $scope === 'author' ? $this->normalizeAuthorSlug( (string) ( $record['author_slug'] ?? '' ) ) : '';

        return(
            [
                'id' => trim( (string) ( $record['id'] ?? '' ) ),
                'scope' => $scope,
                'author_slug' => $author_slug,
                'parent_id' => trim( (string) ( $record['parent_id'] ?? '' ) ),
                'title' => mb_substr( mb_trim( (string) ( $record['title'] ?? '' ) ), 0, 160 ),
                'slug' => $this->normalizeSlug( (string) ( $record['slug'] ?? '' ) ),
                'intro' => mb_substr( mb_trim( (string) ( $record['intro'] ?? '' ) ), 0, 5000 ),
                'sort_order' => (int) ( $record['sort_order'] ?? 0 ),
                'enabled' => ! empty( $record['enabled'] ),
                'created_at_utc' => trim( (string) ( $record['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $record['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeFileRecord( array $record ) : array {
        return(
            [
                'id' => trim( (string) ( $record['id'] ?? '' ) ),
                'catalogue_id' => trim( (string) ( $record['catalogue_id'] ?? '' ) ),
                'title' => mb_substr( mb_trim( (string) ( $record['title'] ?? '' ) ), 0, 160 ),
                'description' => mb_substr( mb_trim( (string) ( $record['description'] ?? '' ) ), 0, 5000 ),
                'original_filename' => basename( (string) ( $record['original_filename'] ?? '' ) ),
                'stored_filename' => basename( (string) ( $record['stored_filename'] ?? '' ) ),
                'size_bytes' => max( 0, (int) ( $record['size_bytes'] ?? 0 ) ),
                'mime_type' => trim( (string) ( $record['mime_type'] ?? 'application/octet-stream' ) ),
                'enabled' => ! empty( $record['enabled'] ),
                'file_date_utc' => trim( (string) ( $record['file_date_utc'] ?? $record['created_at_utc'] ?? '' ) ),
                'download_count' => max( 0, (int) ( $record['download_count'] ?? 0 ) ),
                'last_downloaded_at_utc' => trim( (string) ( $record['last_downloaded_at_utc'] ?? '' ) ),
                'created_at_utc' => trim( (string) ( $record['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $record['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function enrichCatalogue( array $catalogue, array $all_catalogues ) : array {
        $path = $this->buildCataloguePath( $catalogue, $all_catalogues );
        $catalogue['path'] = $path;
        $catalogue['public_url'] = $this->buildCatalogueUrl( $catalogue, $path );
        return( $catalogue );
    }

    protected function enrichFile( array $file, array $catalogue ) : array {
        $file['public_url'] = $this->buildFileUrl( $file, $catalogue );
        $file['size_label'] = $this->formatBytes( (int) ( $file['size_bytes'] ?? 0 ) );
        $file['file_date_input'] = $this->formatFileDateInput( (string) ( $file['file_date_utc'] ?? '' ) );
        return( $file );
    }

    protected function buildCataloguePath( array $catalogue, array $all_catalogues ) : string {
        $segments = [ (string) ( $catalogue['slug'] ?? '' ) ];
        $parent_id = (string) ( $catalogue['parent_id'] ?? '' );
        $guard = 0;
        while ( $parent_id !== '' && $guard < 20 ) {
            $guard++;
            $parent = null;
            foreach ( $all_catalogues as $candidate ) {
                if ( ! is_array( $candidate ) ) {
                    continue;
                }
                $candidate = $this->normalizeCatalogueRecord( $candidate );
                if ( $candidate['id'] === $parent_id ) {
                    $parent = $candidate;
                    break;
                }
            }
            if ( empty( $parent ) ) {
                break;
            }
            array_unshift( $segments, (string) $parent['slug'] );
            $parent_id = (string) $parent['parent_id'];
        }

        return( implode( '/', array_filter( $segments ) ) );
    }

    protected function buildCatalogueUrl( array $catalogue, string $path ) : string {
        $base = (string) ( $catalogue['scope'] ?? 'root' ) === 'author'
            ? '/' . (string) ( $catalogue['author_slug'] ?? '' ) . '/downloads'
            : '/downloads';
        return( rtrim( $base . '/' . ltrim( $path, '/' ), '/' ) );
    }

    protected function buildFileUrl( array $file, array $catalogue ) : string {
        $filename = rawurlencode( (string) ( $file['original_filename'] ?? 'download' ) );
        if ( (string) ( $catalogue['scope'] ?? 'root' ) === 'author' ) {
            return( '/' . (string) ( $catalogue['author_slug'] ?? '' ) . '/downloads/_file/' . rawurlencode( (string) $file['id'] ) . '/' . $filename );
        }

        return( '/downloads/_file/' . rawurlencode( (string) $file['id'] ) . '/' . $filename );
    }

    protected function buildBreadcrumbs( array $catalogue ) : array {
        $breadcrumbs = [];
        $current = $catalogue;
        $guard = 0;
        while ( ! empty( $current ) && $guard < 20 ) {
            $guard++;
            array_unshift(
                $breadcrumbs,
                [
                    'title' => (string) ( $current['title'] ?? '' ),
                    'url' => (string) ( $current['public_url'] ?? '' ),
                ]
            );
            $parent_id = (string) ( $current['parent_id'] ?? '' );
            $current = $parent_id !== '' ? $this->getCatalogueById( $parent_id ) : [];
        }

        return( $breadcrumbs );
    }

    protected function getParentCatalogue( array $catalogue ) : array {
        $parent_id = (string) ( $catalogue['parent_id'] ?? '' );
        if ( $parent_id === '' ) {
            return( [] );
        }

        $parent = $this->getCatalogueById( $parent_id );
        if ( empty( $parent ) || empty( $parent['enabled'] ) ) {
            return( [] );
        }
        if ( (string) ( $parent['scope'] ?? '' ) !== (string) ( $catalogue['scope'] ?? '' ) ) {
            return( [] );
        }
        if ( (string) ( $parent['author_slug'] ?? '' ) !== (string) ( $catalogue['author_slug'] ?? '' ) ) {
            return( [] );
        }

        return( $parent );
    }

    protected function getFileById( string $id ) : array {
        $id = trim( $id );
        if ( $id === '' ) {
            return( [] );
        }

        $store = $this->readStore();
        foreach ( (array) ( $store['files'] ?? [] ) as $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }
            $file = $this->normalizeFileRecord( $file );
            if ( $file['id'] === $id ) {
                return( $file );
            }
        }

        return( [] );
    }

    protected function parentExistsInRecords( array $record, array $catalogues ) : bool {
        foreach ( $catalogues as $catalogue ) {
            if ( ! is_array( $catalogue ) ) {
                continue;
            }
            $catalogue = $this->normalizeCatalogueRecord( $catalogue );
            if (
                $catalogue['id'] === $record['parent_id']
                && $catalogue['scope'] === $record['scope']
                && $catalogue['author_slug'] === $record['author_slug']
                && $catalogue['id'] !== $record['id']
            ) {
                return( true );
            }
        }

        return( false );
    }

    protected function normalizeScope( string $scope ) : string {
        $scope = strtolower( trim( $scope ) );
        return( $scope === 'author' ? 'author' : 'root' );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        $author_slug = preg_replace( '/[^a-z0-9_-]+/', '-', $author_slug ) ?? '';
        return( trim( $author_slug, '-_' ) );
    }

    protected function detectMimeType( string $filename ) : string {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo !== false ) {
                $mime_type = finfo_file( $finfo, $filename );
                finfo_close( $finfo );
                if ( is_string( $mime_type ) && $mime_type !== '' ) {
                    return( $mime_type );
                }
            }
        }

        return( 'application/octet-stream' );
    }

    protected function normalizeFileDateInput( string $date, string $fallback ) : string {
        $date = trim( $date );
        if ( $date === '' ) {
            return( trim( $fallback ) );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $date ) !== 1 ) {
            throw new \InvalidArgumentException( 'file_date' );
        }

        $timestamp = strtotime( $date . ':00 UTC' );
        if ( $timestamp === false ) {
            throw new \InvalidArgumentException( 'file_date' );
        }

        return( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
    }

    protected function formatFileDateInput( string $date ) : string {
        $timestamp = strtotime( $date );
        return( $timestamp !== false ? gmdate( 'Y-m-d\TH:i', $timestamp ) : '' );
    }

    protected function getStoredFilePath( array $file ) : string {
        $id = trim( (string) ( $file['id'] ?? '' ) );
        $stored_filename = basename( (string) ( $file['stored_filename'] ?? '' ) );
        if ( $id === '' || $stored_filename === '' ) {
            return( '' );
        }

        return( $this->files_directory . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . $stored_filename );
    }

    protected function recordDownload( string $id ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $id ) : array {
                $files = is_array( $store['files'] ?? null ) ? $store['files'] : [];
                $updated_files = [];
                foreach ( $files as $file ) {
                    if ( ! is_array( $file ) ) {
                        continue;
                    }
                    $file = $this->normalizeFileRecord( $file );
                    if ( $file['id'] === $id ) {
                        $file['download_count']++;
                        $file['last_downloaded_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                    }
                    if ( $file['id'] !== '' ) {
                        $updated_files[] = $file;
                    }
                }
                $store['version'] = 1;
                $store['catalogues'] = is_array( $store['catalogues'] ?? null ) ? array_values( $store['catalogues'] ) : [];
                $store['files'] = array_values( $updated_files );
                return( $store );
            },
            $this->defaultStore()
        );
    }

    protected function removeFileDirectory( string $id ) : void {
        $directory = $this->files_directory . DIRECTORY_SEPARATOR . basename( $id );
        if ( ! is_dir( $directory ) ) {
            return;
        }

        foreach ( scandir( $directory ) ?: [] as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $filename = $directory . DIRECTORY_SEPARATOR . $entry;
            if ( is_file( $filename ) ) {
                @ unlink( $filename );
            }
        }
        @ rmdir( $directory );
    }

    protected function readStore() : array {
        $store = TinyMashLockedJsonFile::read( $this->store_filename, $this->defaultStore() );
        return( is_array( $store ) ? $store : $this->defaultStore() );
    }

    protected function defaultStore() : array {
        return( [ 'version' => 1, 'catalogues' => [], 'files' => [] ] );
    }

    protected function buildRecordId( string $prefix ) : string {
        return( 'download_' . $prefix . '_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 4 ) ) );
    }

}
