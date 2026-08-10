<?php

use app\classes\TinyMashFilesystem;

require_once dirname( __DIR__, 2 ) . '/classes/TinyMashFilesystem.php';

class TinyMashNotesRepository {

    protected string $storage_root;

    public function __construct( string $storage_root ) {
        $this->storage_root = rtrim( $storage_root, DIRECTORY_SEPARATOR );
    }

    public function getNote( string $username ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( $this->buildEmptyNote() );
        }

        return( $this->withUserLock( $username, LOCK_SH, fn() : array => $this->readNoteUnlocked( $username ) ) );
    }

    public function getDraft( string $username ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( $this->buildEmptyDraft() );
        }

        return( $this->withUserLock( $username, LOCK_SH, fn() : array => $this->readDraftUnlocked( $username ) ) );
    }

    public function saveNote( string $username, string $content, int $revision_limit = 0 ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        $content = $this->normalizeContent( $content );
        return(
            $this->withUserLock(
                $username,
                LOCK_EX,
                function() use ( $username, $content, $revision_limit ) : array {
                    $current_note = $this->readNoteUnlocked( $username );
                    $created_at_utc = trim( (string) ( $current_note['created_at_utc'] ?? '' ) );
                    if ( $created_at_utc === '' ) {
                        $created_at_utc = gmdate( 'Y-m-d H:i:s' );
                    }

                    $this->mutateUserDirectoryUnlocked(
                        $username,
                        function( string $staging_directory ) use ( $username, $content, $revision_limit, $current_note, $created_at_utc ) : void {
                            $current_revision_id = trim( (string) ( $current_note['current_revision_id'] ?? '' ) );
                            if ( $current_note['has_note'] && (string) ( $current_note['content'] ?? '' ) !== $content && $revision_limit > 0 ) {
                                $current_revision_id = $this->createRevisionSnapshotInDirectory( $staging_directory, $current_note );
                                $this->pruneRevisionsInDirectory( $staging_directory, $revision_limit );
                            }

                            $metadata = [
                                'username' => $username,
                                'created_at_utc' => $created_at_utc,
                                'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                                'current_revision_id' => $current_revision_id,
                            ];
                            $this->writeJsonFile( $staging_directory . DIRECTORY_SEPARATOR . 'note.json', $metadata, 'Unable to write note metadata.' );
                            if ( ! TinyMashFilesystem::writeFileAtomically( $staging_directory . DIRECTORY_SEPARATOR . 'note.md', $content ) ) {
                                throw new \RuntimeException( 'Unable to write note.' );
                            }
                            foreach ( [ 'draft.md', 'draft.json' ] as $draft_filename ) {
                                $draft_path = $staging_directory . DIRECTORY_SEPARATOR . $draft_filename;
                                if ( is_file( $draft_path ) && ! @ unlink( $draft_path ) ) {
                                    throw new \RuntimeException( 'Unable to remove note draft.' );
                                }
                            }
                        }
                    );

                    return( $this->readNoteUnlocked( $username ) );
                }
            )
        );
    }

    public function saveDraft( string $username, string $content ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        $content = $this->normalizeContent( $content );
        return(
            $this->withUserLock(
                $username,
                LOCK_EX,
                function() use ( $username, $content ) : array {
                    $this->mutateUserDirectoryUnlocked(
                        $username,
                        function( string $staging_directory ) use ( $username, $content ) : void {
                            $this->writeJsonFile(
                                $staging_directory . DIRECTORY_SEPARATOR . 'draft.json',
                                [ 'username' => $username, 'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ) ],
                                'Unable to write note draft metadata.'
                            );
                            if ( ! TinyMashFilesystem::writeFileAtomically( $staging_directory . DIRECTORY_SEPARATOR . 'draft.md', $content ) ) {
                                throw new \RuntimeException( 'Unable to write note draft.' );
                            }
                        }
                    );
                    return( $this->readDraftUnlocked( $username ) );
                }
            )
        );
    }

    public function deleteDraft( string $username ) : bool {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( false );
        }

        return(
            $this->withUserLock(
                $username,
                LOCK_EX,
                function() use ( $username ) : bool {
                    $draft = $this->readDraftUnlocked( $username );
                    if ( empty( $draft['has_draft'] ) ) {
                        return( false );
                    }
                    $this->mutateUserDirectoryUnlocked(
                        $username,
                        function( string $staging_directory ) : void {
                            foreach ( [ 'draft.md', 'draft.json' ] as $filename ) {
                                $path = $staging_directory . DIRECTORY_SEPARATOR . $filename;
                                if ( is_file( $path ) && ! @ unlink( $path ) ) {
                                    throw new \RuntimeException( 'Unable to remove note draft.' );
                                }
                            }
                        }
                    );
                    return( true );
                }
            )
        );
    }

    public function listRevisions( string $username, int $limit = 10 ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( [] );
        }

        return( $this->withUserLock( $username, LOCK_SH, fn() : array => $this->listRevisionsUnlocked( $username, $limit ) ) );
    }

    protected function listRevisionsUnlocked( string $username, int $limit = 10 ) : array {
        $revision_root = $this->getNoteDirectory( $username ) . DIRECTORY_SEPARATOR . 'revisions';
        if ( ! is_dir( $revision_root ) ) {
            return( [] );
        }

        $revision_directories = glob( $revision_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $revision_directories ) || empty( $revision_directories ) ) {
            return( [] );
        }

        rsort( $revision_directories, SORT_STRING );
        $revisions = [];
        foreach ( $revision_directories as $revision_directory ) {
            $metadata = $this->readJsonFile( $revision_directory . DIRECTORY_SEPARATOR . 'revision.json' );
            if ( empty( $metadata ) ) {
                continue;
            }

            $revisions[] = [
                'id' => trim( (string) ( $metadata['id'] ?? basename( $revision_directory ) ) ),
                'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
                'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
            ];
        }

        return( array_slice( $revisions, 0, max( 1, $limit ) ) );
    }

    public function getRevision( string $username, string $revision_id ) : ?array {
        $username = $this->normalizeUsername( $username );
        $revision_id = $this->normalizeRevisionId( $revision_id );
        if ( $username === '' || $revision_id === '' ) {
            return( null );
        }

        return( $this->withUserLock( $username, LOCK_SH, fn() : ?array => $this->getRevisionUnlocked( $username, $revision_id ) ) );
    }

    protected function getRevisionUnlocked( string $username, string $revision_id ) : ?array {
        $revision_directory = $this->getNoteDirectory( $username ) . DIRECTORY_SEPARATOR . 'revisions' . DIRECTORY_SEPARATOR . $revision_id;
        $metadata = $this->readJsonFile( $revision_directory . DIRECTORY_SEPARATOR . 'revision.json' );
        if ( empty( $metadata ) ) {
            return( null );
        }

        return(
            [
                'id' => trim( (string) ( $metadata['id'] ?? $revision_id ) ),
                'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
                'content' => $this->readTextFile( $revision_directory . DIRECTORY_SEPARATOR . 'note.md' ),
                'bytes' => max( 0, (int) ( $metadata['bytes'] ?? 0 ) ),
            ]
        );
    }

    public function pruneAllRevisions( int $revision_limit ) : array {
        $revision_limit = max( 0, $revision_limit );

        if ( ! is_dir( $this->storage_root ) ) {
            return(
                [
                    'users' => 0,
                    'removed' => 0,
                    'limit' => $revision_limit,
                ]
            );
        }

        $user_directories = glob( $this->storage_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $user_directories ) || empty( $user_directories ) ) {
            return(
                [
                    'users' => 0,
                    'removed' => 0,
                    'limit' => $revision_limit,
                ]
            );
        }

        $users = 0;
        $removed = 0;
        foreach ( $user_directories as $user_directory ) {
            $username = $this->normalizeUsername( basename( $user_directory ) );
            if ( $username === '' ) {
                continue;
            }

            $users++;
            $removed += $this->pruneRevisions( $username, $revision_limit );
        }

        return(
            [
                'users' => $users,
                'removed' => $removed,
                'limit' => $revision_limit,
            ]
        );
    }

    public function exportUserData( string $username, string $target_directory ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        return(
            $this->withUserLock(
                $username,
                LOCK_SH,
                function() use ( $username, $target_directory ) : array {
                    $source_directory = $this->getNoteDirectory( $username );
                    if ( ! is_dir( $source_directory ) ) {
                        return(
                            [
                                'username' => $username,
                                'exported' => false,
                                'note' => false,
                                'draft' => false,
                                'revisions' => 0,
                            ]
                        );
                    }

                    $this->copyDirectoryRecursively( $source_directory, rtrim( $target_directory, DIRECTORY_SEPARATOR ) );
                    return(
                        [
                            'username' => $username,
                            'exported' => true,
                            'note' => is_file( $source_directory . DIRECTORY_SEPARATOR . 'note.md' ) || is_file( $source_directory . DIRECTORY_SEPARATOR . 'note.json' ),
                            'draft' => is_file( $source_directory . DIRECTORY_SEPARATOR . 'draft.md' ) || is_file( $source_directory . DIRECTORY_SEPARATOR . 'draft.json' ),
                            'revisions' => count( $this->listRevisionsUnlocked( $username, 1000 ) ),
                        ]
                    );
                }
            )
        );
    }

    public function importUserData( string $username, string $source_directory, bool $replace_existing = false ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        if ( ! is_dir( $source_directory ) ) {
            return(
                [
                    'username' => $username,
                    'imported' => false,
                    'note' => false,
                    'draft' => false,
                    'revisions' => 0,
                ]
            );
        }

        return(
            $this->withUserLock(
                $username,
                LOCK_EX,
                function() use ( $username, $source_directory, $replace_existing ) : array {
                    $target_directory = $this->getNoteDirectory( $username );
                    if ( is_dir( $target_directory ) && ! $replace_existing ) {
                        throw new \RuntimeException( 'Notes data already exists for this user.' );
                    }

                    $this->mutateUserDirectoryUnlocked(
                        $username,
                        function( string $staging_directory ) use ( $source_directory ) : void {
                            $this->clearDirectory( $staging_directory );
                            $this->copyDirectoryRecursively( $source_directory, $staging_directory );
                        }
                    );

                    return(
                        [
                            'username' => $username,
                            'imported' => true,
                            'note' => is_file( $target_directory . DIRECTORY_SEPARATOR . 'note.md' ) || is_file( $target_directory . DIRECTORY_SEPARATOR . 'note.json' ),
                            'draft' => is_file( $target_directory . DIRECTORY_SEPARATOR . 'draft.md' ) || is_file( $target_directory . DIRECTORY_SEPARATOR . 'draft.json' ),
                            'revisions' => count( $this->listRevisionsUnlocked( $username, 1000 ) ),
                        ]
                    );
                }
            )
        );
    }

    public function exportAllUsersData( string $target_directory ) : array {
        if ( ! is_dir( $this->storage_root ) ) {
            return(
                [
                    'users' => 0,
                    'exported' => 0,
                ]
            );
        }

        $user_directories = glob( $this->storage_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $user_directories ) || empty( $user_directories ) ) {
            return(
                [
                    'users' => 0,
                    'exported' => 0,
                ]
            );
        }

        $users = 0;
        $exported = 0;
        foreach ( $user_directories as $user_directory ) {
            $username = $this->normalizeUsername( basename( $user_directory ) );
            if ( $username === '' ) {
                continue;
            }

            $users++;
            $result = $this->exportUserData( $username, rtrim( $target_directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $username );
            if ( ! empty( $result['exported'] ) ) {
                $exported++;
            }
        }

        return(
            [
                'users' => $users,
                'exported' => $exported,
            ]
        );
    }

    public function importAllUsersData( string $source_directory, bool $replace_existing = false ) : array {
        $source_directory = rtrim( $source_directory, DIRECTORY_SEPARATOR );
        if ( ! is_dir( $source_directory ) ) {
            return(
                [
                    'users' => 0,
                    'imported' => 0,
                ]
            );
        }

        $user_directories = glob( $source_directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $user_directories ) || empty( $user_directories ) ) {
            return(
                [
                    'users' => 0,
                    'imported' => 0,
                ]
            );
        }

        $users = 0;
        $imported = 0;
        foreach ( $user_directories as $user_directory ) {
            $username = $this->normalizeUsername( basename( $user_directory ) );
            if ( $username === '' ) {
                continue;
            }

            $users++;
            $result = $this->importUserData( $username, $user_directory, $replace_existing );
            if ( ! empty( $result['imported'] ) ) {
                $imported++;
            }
        }

        return(
            [
                'users' => $users,
                'imported' => $imported,
            ]
        );
    }

    protected function createRevisionSnapshotInDirectory( string $note_directory, array $current_note ) : string {
        $revision_id = gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
        $revision_directory = $note_directory . DIRECTORY_SEPARATOR . 'revisions' . DIRECTORY_SEPARATOR . $revision_id;
        $content = (string) ( $current_note['content'] ?? '' );

        $this->ensureDirectory( $revision_directory );
        if ( ! TinyMashFilesystem::writeFileAtomically( $revision_directory . DIRECTORY_SEPARATOR . 'note.md', $content ) ) {
            $this->deleteDirectory( $revision_directory );
            throw new \RuntimeException( 'Unable to write note revision.' );
        }
        $this->writeJsonFile(
            $revision_directory . DIRECTORY_SEPARATOR . 'revision.json',
            [
                'id' => $revision_id,
                'created_at_utc' => trim( (string) ( $current_note['updated_at_utc'] ?? $current_note['created_at_utc'] ?? gmdate( 'Y-m-d H:i:s' ) ) ),
                'bytes' => strlen( $content ),
            ],
            'Unable to write note revision metadata.'
        );

        return( $revision_id );
    }

    protected function pruneRevisions( string $username, int $revision_limit ) : int {
        return(
            $this->withUserLock(
                $username,
                LOCK_EX,
                fn() : int => $this->pruneRevisionsInDirectory( $this->getNoteDirectory( $username ), $revision_limit )
            )
        );
    }

    protected function pruneRevisionsInDirectory( string $note_directory, int $revision_limit ) : int {
        $revision_limit = max( 0, $revision_limit );

        $revision_root = $note_directory . DIRECTORY_SEPARATOR . 'revisions';
        if ( ! is_dir( $revision_root ) ) {
            return( 0 );
        }

        $revision_directories = glob( $revision_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
        if ( ! is_array( $revision_directories ) || count( $revision_directories ) <= $revision_limit ) {
            return( 0 );
        }

        rsort( $revision_directories, SORT_STRING );
        $stale_revisions = array_slice( $revision_directories, $revision_limit );
        foreach ( $stale_revisions as $stale_revision_directory ) {
            $this->deleteDirectory( $stale_revision_directory );
        }

        return( count( $stale_revisions ) );
    }

    protected function deleteDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $items = scandir( $directory );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $this->deleteDirectory( $path );
                continue;
            }
            @ unlink( $path );
        }

        @ rmdir( $directory );
    }

    protected function clearDirectory( string $directory ) : void {
        foreach ( scandir( $directory ) ?: [] as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->deleteDirectory( $path );
                continue;
            }
            if ( ! @ unlink( $path ) && file_exists( $path ) ) {
                throw new \RuntimeException( 'Unable to prepare notes import.' );
            }
        }
    }

    protected function readJsonFile( string $filename ) : array {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( [] );
        }

        $json = file_get_contents( $filename );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return( [] );
        }

        $decoded = json_decode( $json, true, 16 );
        return( is_array( $decoded ) ? $decoded : [] );
    }

    protected function readTextFile( string $filename ) : string {
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return( '' );
        }

        $content = file_get_contents( $filename );
        return( is_string( $content ) ? $content : '' );
    }

    protected function readNoteUnlocked( string $username ) : array {
        $note_directory = $this->getNoteDirectory( $username );
        $metadata = $this->readJsonFile( $note_directory . DIRECTORY_SEPARATOR . 'note.json' );
        return(
            [
                'username' => $username,
                'content' => $this->readTextFile( $note_directory . DIRECTORY_SEPARATOR . 'note.md' ),
                'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $metadata['updated_at_utc'] ?? '' ) ),
                'current_revision_id' => trim( (string) ( $metadata['current_revision_id'] ?? '' ) ),
                'has_note' => is_file( $note_directory . DIRECTORY_SEPARATOR . 'note.json' ) || is_file( $note_directory . DIRECTORY_SEPARATOR . 'note.md' ),
            ]
        );
    }

    protected function readDraftUnlocked( string $username ) : array {
        $note_directory = $this->getNoteDirectory( $username );
        $metadata = $this->readJsonFile( $note_directory . DIRECTORY_SEPARATOR . 'draft.json' );
        return(
            [
                'username' => $username,
                'content' => $this->readTextFile( $note_directory . DIRECTORY_SEPARATOR . 'draft.md' ),
                'updated_at_utc' => trim( (string) ( $metadata['updated_at_utc'] ?? '' ) ),
                'has_draft' => is_file( $note_directory . DIRECTORY_SEPARATOR . 'draft.json' ) || is_file( $note_directory . DIRECTORY_SEPARATOR . 'draft.md' ),
            ]
        );
    }

    protected function writeJsonFile( string $filename, array $data, string $error_message ) : void {
        $json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || ! TinyMashFilesystem::writeFileAtomically( $filename, $json . PHP_EOL ) ) {
            throw new \RuntimeException( $error_message );
        }
    }

    protected function withUserLock( string $username, int $operation, callable $callback ) : mixed {
        $lock_directory = $this->storage_root . DIRECTORY_SEPARATOR . '.locks';
        if ( $operation === LOCK_SH && is_dir( $this->storage_root ) && ! is_writable( $this->storage_root ) ) {
            return( $callback() );
        }

        $this->ensureDirectory( $lock_directory );
        $lock_handle = @ fopen( $lock_directory . DIRECTORY_SEPARATOR . $username . '.lock', 'c+' );
        if ( $lock_handle === false ) {
            throw new \RuntimeException( 'Unable to open note lock.' );
        }

        try {
            if ( ! @ flock( $lock_handle, $operation ) ) {
                throw new \RuntimeException( 'Unable to lock note.' );
            }
            return( $callback() );
        } finally {
            @ flock( $lock_handle, LOCK_UN );
            fclose( $lock_handle );
        }
    }

    protected function mutateUserDirectoryUnlocked( string $username, callable $callback ) : mixed {
        $note_directory = $this->getNoteDirectory( $username );
        $transaction_directory = $this->storage_root . DIRECTORY_SEPARATOR . '.transactions';
        $this->ensureDirectory( $transaction_directory );
        $staging_directory = $transaction_directory . DIRECTORY_SEPARATOR . $username . '-' . bin2hex( random_bytes( 8 ) );
        $this->ensureDirectory( $staging_directory );

        try {
            if ( is_dir( $note_directory ) ) {
                $this->copyDirectoryRecursively( $note_directory, $staging_directory );
            }
            $result = $callback( $staging_directory );
        } catch ( \Throwable $e ) {
            $this->deleteDirectory( $staging_directory );
            throw $e;
        }

        $backup_directory = $transaction_directory . DIRECTORY_SEPARATOR . $username . '-backup-' . bin2hex( random_bytes( 8 ) );
        $has_backup = false;
        if ( is_dir( $note_directory ) ) {
            if ( ! @ rename( $note_directory, $backup_directory ) ) {
                $this->deleteDirectory( $staging_directory );
                throw new \RuntimeException( 'Unable to prepare note for replacement.' );
            }
            $has_backup = true;
        }

        if ( ! @ rename( $staging_directory, $note_directory ) ) {
            $restored = ! $has_backup || @ rename( $backup_directory, $note_directory );
            $this->deleteDirectory( $staging_directory );
            if ( ! $restored ) {
                throw new \RuntimeException( 'Unable to replace note and restore its previous state.' );
            }
            throw new \RuntimeException( 'Unable to replace note.' );
        }

        if ( $has_backup ) {
            $this->deleteDirectory( $backup_directory );
        }
        @ rmdir( $transaction_directory );
        return( $result );
    }

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create notes directory.' );
        }
    }

    protected function copyDirectoryRecursively( string $source_directory, string $target_directory ) : void {
        if ( ! is_dir( $source_directory ) ) {
            return;
        }

        $this->ensureDirectory( $target_directory );
        $items = scandir( $source_directory );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }

            $source_path = $source_directory . DIRECTORY_SEPARATOR . $item;
            $target_path = $target_directory . DIRECTORY_SEPARATOR . $item;

            if ( is_link( $source_path ) ) {
                throw new \RuntimeException( 'Notes directories cannot contain symbolic links.' );
            }
            if ( is_dir( $source_path ) ) {
                $this->copyDirectoryRecursively( $source_path, $target_path );
                continue;
            }

            $this->ensureDirectory( dirname( $target_path ) );
            if ( ! is_file( $source_path ) || ! @ copy( $source_path, $target_path ) ) {
                throw new \RuntimeException( 'Unable to copy notes data.' );
            }
        }
    }

    protected function getNoteDirectory( string $username ) : string {
        return( $this->storage_root . DIRECTORY_SEPARATOR . $username );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        return( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) === 1 ? $username : '' );
    }

    protected function normalizeRevisionId( string $revision_id ) : string {
        $revision_id = trim( $revision_id );
        return( preg_match( '/^[A-Za-z0-9_]+$/', $revision_id ) === 1 ? $revision_id : '' );
    }

    protected function normalizeContent( string $content ) : string {
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );
        return( mb_rtrim( $content ) );
    }

    protected function buildEmptyNote() : array {
        return(
            [
                'username' => '',
                'content' => '',
                'created_at_utc' => '',
                'updated_at_utc' => '',
                'current_revision_id' => '',
                'has_note' => false,
            ]
        );
    }

    protected function buildEmptyDraft() : array {
        return(
            [
                'username' => '',
                'content' => '',
                'updated_at_utc' => '',
                'has_draft' => false,
            ]
        );
    }

}
