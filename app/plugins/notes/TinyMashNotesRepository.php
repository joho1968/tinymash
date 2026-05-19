<?php

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

        $note_directory = $this->getNoteDirectory( $username );
        $metadata = $this->readJsonFile( $note_directory . DIRECTORY_SEPARATOR . 'note.json' );
        $content = $this->readTextFile( $note_directory . DIRECTORY_SEPARATOR . 'note.md' );

        $created_at_utc = trim( (string) ( $metadata['created_at_utc'] ?? '' ) );
        $updated_at_utc = trim( (string) ( $metadata['updated_at_utc'] ?? '' ) );
        $current_revision_id = trim( (string) ( $metadata['current_revision_id'] ?? '' ) );

        return(
            [
                'username' => $username,
                'content' => $content,
                'created_at_utc' => $created_at_utc,
                'updated_at_utc' => $updated_at_utc,
                'current_revision_id' => $current_revision_id,
                'has_note' => is_file( $note_directory . DIRECTORY_SEPARATOR . 'note.json' ) || is_file( $note_directory . DIRECTORY_SEPARATOR . 'note.md' ),
            ]
        );
    }

    public function getDraft( string $username ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( $this->buildEmptyDraft() );
        }

        $note_directory = $this->getNoteDirectory( $username );
        $metadata = $this->readJsonFile( $note_directory . DIRECTORY_SEPARATOR . 'draft.json' );
        $content = $this->readTextFile( $note_directory . DIRECTORY_SEPARATOR . 'draft.md' );

        return(
            [
                'username' => $username,
                'content' => $content,
                'updated_at_utc' => trim( (string) ( $metadata['updated_at_utc'] ?? '' ) ),
                'has_draft' => is_file( $note_directory . DIRECTORY_SEPARATOR . 'draft.json' ) || is_file( $note_directory . DIRECTORY_SEPARATOR . 'draft.md' ),
            ]
        );
    }

    public function saveNote( string $username, string $content, int $revision_limit = 0 ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        $content = $this->normalizeContent( $content );
        $current_note = $this->getNote( $username );
        $note_directory = $this->getNoteDirectory( $username );
        $created_at_utc = trim( (string) ( $current_note['created_at_utc'] ?? '' ) );
        if ( $created_at_utc === '' ) {
            $created_at_utc = gmdate( 'Y-m-d H:i:s' );
        }

        $current_revision_id = trim( (string) ( $current_note['current_revision_id'] ?? '' ) );
        if ( $current_note['has_note'] && (string) ( $current_note['content'] ?? '' ) !== $content && $revision_limit > 0 ) {
            $current_revision_id = $this->createRevisionSnapshot( $username, $current_note );
            $this->pruneRevisions( $username, $revision_limit );
        }

        $updated_at_utc = gmdate( 'Y-m-d H:i:s' );
        $this->ensureDirectory( $note_directory );
        file_put_contents( $note_directory . DIRECTORY_SEPARATOR . 'note.md', $content );
        file_put_contents(
            $note_directory . DIRECTORY_SEPARATOR . 'note.json',
            json_encode(
                [
                    'username' => $username,
                    'created_at_utc' => $created_at_utc,
                    'updated_at_utc' => $updated_at_utc,
                    'current_revision_id' => $current_revision_id,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );
        $this->deleteDraft( $username );

        return( $this->getNote( $username ) );
    }

    public function saveDraft( string $username, string $content ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'notes_username' );
        }

        $content = $this->normalizeContent( $content );
        $note_directory = $this->getNoteDirectory( $username );
        $updated_at_utc = gmdate( 'Y-m-d H:i:s' );

        $this->ensureDirectory( $note_directory );
        file_put_contents( $note_directory . DIRECTORY_SEPARATOR . 'draft.md', $content );
        file_put_contents(
            $note_directory . DIRECTORY_SEPARATOR . 'draft.json',
            json_encode(
                [
                    'username' => $username,
                    'updated_at_utc' => $updated_at_utc,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );

        return( $this->getDraft( $username ) );
    }

    public function deleteDraft( string $username ) : bool {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( false );
        }

        $note_directory = $this->getNoteDirectory( $username );
        $deleted = false;
        foreach ( [ 'draft.md', 'draft.json' ] as $filename ) {
            $path = $note_directory . DIRECTORY_SEPARATOR . $filename;
            if ( is_file( $path ) && @ unlink( $path ) ) {
                $deleted = true;
            }
        }

        return( $deleted );
    }

    public function listRevisions( string $username, int $limit = 10 ) : array {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            return( [] );
        }

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
        if ( $revision_limit <= 0 ) {
            return(
                [
                    'users' => 0,
                    'removed' => 0,
                    'limit' => 0,
                ]
            );
        }

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

        $target_directory = rtrim( $target_directory, DIRECTORY_SEPARATOR );
        $this->copyDirectoryRecursively( $source_directory, $target_directory );

        return(
            [
                'username' => $username,
                'exported' => true,
                'note' => is_file( $source_directory . DIRECTORY_SEPARATOR . 'note.md' ) || is_file( $source_directory . DIRECTORY_SEPARATOR . 'note.json' ),
                'draft' => is_file( $source_directory . DIRECTORY_SEPARATOR . 'draft.md' ) || is_file( $source_directory . DIRECTORY_SEPARATOR . 'draft.json' ),
                'revisions' => count( $this->listRevisions( $username, 1000 ) ),
            ]
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

        $target_directory = $this->getNoteDirectory( $username );
        if ( is_dir( $target_directory ) && ! $replace_existing ) {
            throw new \RuntimeException( 'Notes data already exists for this user.' );
        }

        if ( is_dir( $target_directory ) && $replace_existing ) {
            $this->deleteDirectory( $target_directory );
        }

        $this->copyDirectoryRecursively( $source_directory, $target_directory );

        return(
            [
                'username' => $username,
                'imported' => true,
                'note' => is_file( $target_directory . DIRECTORY_SEPARATOR . 'note.md' ) || is_file( $target_directory . DIRECTORY_SEPARATOR . 'note.json' ),
                'draft' => is_file( $target_directory . DIRECTORY_SEPARATOR . 'draft.md' ) || is_file( $target_directory . DIRECTORY_SEPARATOR . 'draft.json' ),
                'revisions' => count( $this->listRevisions( $username, 1000 ) ),
            ]
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

    protected function createRevisionSnapshot( string $username, array $current_note ) : string {
        $revision_id = gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
        $revision_directory = $this->getNoteDirectory( $username ) . DIRECTORY_SEPARATOR . 'revisions' . DIRECTORY_SEPARATOR . $revision_id;
        $content = (string) ( $current_note['content'] ?? '' );

        $this->ensureDirectory( $revision_directory );
        file_put_contents( $revision_directory . DIRECTORY_SEPARATOR . 'note.md', $content );
        file_put_contents(
            $revision_directory . DIRECTORY_SEPARATOR . 'revision.json',
            json_encode(
                [
                    'id' => $revision_id,
                    'created_at_utc' => trim( (string) ( $current_note['updated_at_utc'] ?? $current_note['created_at_utc'] ?? gmdate( 'Y-m-d H:i:s' ) ) ),
                    'bytes' => strlen( $content ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );

        return( $revision_id );
    }

    protected function pruneRevisions( string $username, int $revision_limit ) : int {
        if ( $revision_limit <= 0 ) {
            return( 0 );
        }

        $revision_root = $this->getNoteDirectory( $username ) . DIRECTORY_SEPARATOR . 'revisions';
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

    protected function ensureDirectory( string $directory ) : void {
        if ( ! is_dir( $directory ) ) {
            mkdir( $directory, 0775, true );
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

            if ( is_dir( $source_path ) ) {
                $this->copyDirectoryRecursively( $source_path, $target_path );
                continue;
            }

            $this->ensureDirectory( dirname( $target_path ) );
            copy( $source_path, $target_path );
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
