<?php

use app\classes\TinyMashLockedJsonFile;

class TinyMashCommentsService {

    protected const SCHEMA_VERSION = 1;
    protected const MAX_COMMENTS_PER_ENTRY = 1000;
    protected const MAX_NAME_CHARACTERS = 80;
    protected const MAX_EMAIL_CHARACTERS = 254;
    protected const MAX_BODY_CHARACTERS = 4000;
    protected const MAX_LINKS = 3;
    protected const MAX_MODERATION_NOTE_CHARACTERS = 500;
    protected const MODERATION_HISTORY_LIMIT = 50;
    protected const UNSUBSCRIBE_TOKEN_LIMIT = 5;
    protected const IMPORT_SOURCE_HASH_LIMIT = 5000;
    protected const MAX_IMPORT_SOURCE_CHARACTERS = 255;
    protected const VERIFICATION_TTL_SECONDS = 172800;
    protected const RETENTION_STATUSES = [ 'awaiting_verification', 'pending', 'spam', 'trash' ];

    protected string $storage_directory;
    protected ?\Closure $clock;

    public function __construct( string $storage_directory, ?\Closure $clock = null ) {
        $storage_directory = rtrim( $storage_directory, DIRECTORY_SEPARATOR );
        if ( $storage_directory === '' ) {
            throw new \InvalidArgumentException( 'storage_directory' );
        }

        $this->storage_directory = $storage_directory;
        $this->clock = $clock;
    }

    public function initialize() : void {
        $filename = $this->getStateFilename();
        if ( is_file( $filename ) ) {
            $state = TinyMashLockedJsonFile::readStrict( $filename );
            if ( (int) ( $state['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION ) {
                throw new \RuntimeException( 'comment_state_malformed' );
            }
            return;
        }

        TinyMashLockedJsonFile::mutate(
            $filename,
            function( array $state ) : array {
                $state['schema_version'] = self::SCHEMA_VERSION;
                if ( mb_trim( (string) ( $state['activated_at_utc'] ?? '' ) ) === '' ) {
                    $state['activated_at_utc'] = $this->nowUtc();
                }
                return( $state );
            },
            [
                'schema_version' => self::SCHEMA_VERSION,
                'activated_at_utc' => '',
            ]
        );
    }

    public function getLimits() : array {
        return(
            [
                'comments_per_entry' => self::MAX_COMMENTS_PER_ENTRY,
                'name_characters' => self::MAX_NAME_CHARACTERS,
                'email_characters' => self::MAX_EMAIL_CHARACTERS,
                'body_characters' => self::MAX_BODY_CHARACTERS,
                'links' => self::MAX_LINKS,
            ]
        );
    }

    public function assertEntryCommentsCanBeReplaced( string $entry_id, string $scope ) : void {
        $entry_id = $this->requireEntryId( $entry_id );
        $scope = $this->requireScope( $scope );
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return;
        }

        $store = $this->readEntryStore( $entry_id );
        if ( (string) $store['scope'] !== $scope ) {
            throw new \RuntimeException( 'comment_store_scope' );
        }
    }

    public function clearEntryCommentsForReplacement( string $entry_id, string $scope ) : int {
        $entry_id = $this->requireEntryId( $entry_id );
        $scope = $this->requireScope( $scope );
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return( 0 );
        }

        return(
            (int) TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( $entry_id, $scope ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    if ( (string) $store['scope'] !== $scope ) {
                        throw new \RuntimeException( 'comment_store_scope' );
                    }

                    return(
                        [
                            'data' => $this->getDefaultEntryStore( $entry_id, $scope ),
                            'result' => count( $store['comments'] ),
                        ]
                    );
                }
            )
        );
    }

    public function getRetention( array $settings = [] ) : array {
        $settings = $this->normalizeRetentionSettings( $settings );
        return(
            [
                'verification_token_seconds' => self::VERIFICATION_TTL_SECONDS,
                'verification_seconds' => (int) $settings['unverified_retention_days'] * 86400,
                'pending_seconds' => (int) $settings['pending_retention_days'] * 86400,
                'spam_seconds' => (int) $settings['spam_retention_days'] * 86400,
                'trash_seconds' => (int) $settings['trash_retention_days'] * 86400,
            ]
        );
    }

    public function normalizeRetentionSettings( array $settings ) : array {
        return(
            [
                'unverified_retention_days' => $this->normalizeRetentionDays( $settings['unverified_retention_days'] ?? 2, [ 0, 2, 7, 30, 90 ], 2 ),
                'pending_retention_days' => $this->normalizeRetentionDays( $settings['pending_retention_days'] ?? 0, [ 0, 30, 90, 180, 365 ], 0 ),
                'spam_retention_days' => $this->normalizeRetentionDays( $settings['spam_retention_days'] ?? 30, [ 7, 30, 90, 180, 365 ], 30 ),
                'trash_retention_days' => $this->normalizeRetentionDays( $settings['trash_retention_days'] ?? 30, [ 7, 30, 90, 180, 365 ], 30 ),
            ]
        );
    }

    public function normalizeSettings( array $settings ) : array {
        $access_policy = array_key_exists( 'access_policy', $settings )
            ? $this->normalizeAccessPolicy( (string) $settings['access_policy'] )
            : 'anyone';
        return(
            [
                'posts_enabled' => ! empty( $settings['posts_enabled'] ),
                'pages_enabled' => ! empty( $settings['pages_enabled'] ),
                'access_policy' => $access_policy,
            ]
        );
    }

    public function normalizeScope( string $scope ) : string {
        $scope = mb_strtolower( mb_trim( $scope ) );
        if ( $scope === 'root' ) {
            return( 'root' );
        }
        if ( preg_match( '/^author:[a-z0-9_]{1,64}$/', $scope ) === 1 ) {
            return( $scope );
        }

        return( '' );
    }

    public function resolveEntryPolicy( array $entry, array $site_settings, array $author_settings = [] ) : array {
        $entry_type = (string) ( $entry['type'] ?? 'post' );
        if ( ! in_array( $entry_type, [ 'post', 'page' ], true ) ) {
            $entry_type = 'post';
        }

        $entry_scope = (string) ( $entry['scope'] ?? '' );
        $author_slug = $this->normalizeUsername( (string) ( $entry['author_slug'] ?? '' ) );
        $scope = $entry_scope === 'root' ? 'root' : '';
        if ( $entry_scope === 'author' && $author_slug !== '' ) {
            $scope = 'author:' . $author_slug;
        }
        if ( $scope === '' ) {
            return(
                [
                    'scope' => '',
                    'entry_type' => $entry_type,
                    'entry_mode' => 'closed',
                    'open' => false,
                    'access_policy' => 'signed_in',
                ]
            );
        }
        $settings = $this->normalizeSettings( $scope === 'root' ? $site_settings : $author_settings );
        $plugin_settings = is_array( $entry['plugin_settings']['comments'] ?? null ) ? $entry['plugin_settings']['comments'] : [];
        $entry_mode = $this->normalizeEntryMode( (string) ( $plugin_settings['mode'] ?? 'inherit' ) );
        $default_open = $entry_type === 'page' ? $settings['pages_enabled'] : $settings['posts_enabled'];

        return(
            [
                'scope' => $scope,
                'entry_type' => $entry_type,
                'entry_mode' => $entry_mode,
                'open' => $entry_mode === 'open' || ( $entry_mode === 'inherit' && $default_open ),
                'access_policy' => $settings['access_policy'],
            ]
        );
    }

    public function visitorCanComment( string $access_policy, string $visitor_type ) : bool {
        $access_policy = $this->normalizeAccessPolicy( $access_policy );
        $visitor_type = strtolower( trim( $visitor_type ) );
        if ( $visitor_type === 'signed_in' ) {
            return( true );
        }
        if ( $access_policy === 'anyone' ) {
            return( in_array( $visitor_type, [ 'public', 'secret_link' ], true ) );
        }
        if ( $access_policy === 'secret_or_signed_in' ) {
            return( $visitor_type === 'secret_link' );
        }

        return( false );
    }

    public function actorCanModerateScope( string $actor_username, bool $actor_is_admin, string $scope ) : bool {
        $scope = $this->normalizeScope( $scope );
        if ( $scope === '' ) {
            return( false );
        }
        if ( $actor_is_admin ) {
            return( true );
        }

        $actor_username = $this->normalizeUsername( $actor_username );
        return( $actor_username !== '' && $scope === 'author:' . $actor_username );
    }

    public function createGuestComment(
        string $entry_id,
        string $scope,
        string $parent_id,
        string $author_name,
        string $email,
        string $body,
        string $access_type = 'public',
        bool $issue_verification_token = true,
        bool $reply_notifications_enabled = false
    ) : array {
        $entry_id = $this->requireEntryId( $entry_id );
        $scope = $this->requireScope( $scope );
        $parent_id = $this->normalizeOptionalCommentId( $parent_id );
        $author_name = $this->requireDisplayName( $author_name );
        $email = $this->requireEmail( $email );
        $body = $this->requireBody( $body );
        $access_type = strtolower( trim( $access_type ) );
        if ( ! in_array( $access_type, [ 'public', 'secret_link' ], true ) ) {
            throw new \InvalidArgumentException( 'access_type' );
        }

        $token = $issue_verification_token ? bin2hex( random_bytes( 32 ) ) : '';
        $now_utc = $this->nowUtc();
        $record = $this->storeComment(
            $entry_id,
            $scope,
            $parent_id,
            [
                'author_type' => 'guest',
                'author_name' => $author_name,
                'author_username' => '',
                'email' => $email,
                'body' => $body,
                'status' => 'awaiting_verification',
                'source' => 'public',
                'access_type' => $access_type,
                'verification_token_hash' => $token !== '' ? hash( 'sha256', $token ) : '',
                'verification_expires_at_utc' => $token !== '' ? gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() + self::VERIFICATION_TTL_SECONDS ) : '',
                'verified_at_utc' => '',
                'moderated_at_utc' => '',
                'moderated_by' => '',
                'approved_at_utc' => '',
                'trashed_at_utc' => '',
                'status_before_trash' => '',
                'created_at_utc' => $now_utc,
                'updated_at_utc' => $now_utc,
                'moderation_history' => [],
                'reply_notifications_enabled' => $reply_notifications_enabled,
            ]
        );

        return( [ 'comment' => $record, 'verification_token' => $token ] );
    }

    public function createAccountComment(
        string $entry_id,
        string $scope,
        string $parent_id,
        string $actor_username,
        string $author_name,
        string $body,
        bool $actor_is_admin,
        bool $reply_notifications_enabled = false
    ) : array {
        $entry_id = $this->requireEntryId( $entry_id );
        $scope = $this->requireScope( $scope );
        $parent_id = $this->normalizeOptionalCommentId( $parent_id );
        $actor_username = $this->requireUsername( $actor_username );
        $author_name = $this->requireDisplayName( $author_name );
        $body = $this->requireBody( $body );
        $now_utc = $this->nowUtc();
        $approved = $this->actorCanModerateScope( $actor_username, $actor_is_admin, $scope );

        return(
            $this->storeComment(
                $entry_id,
                $scope,
                $parent_id,
                [
                    'author_type' => 'account',
                    'author_name' => $author_name,
                    'author_username' => $actor_username,
                    'email' => '',
                    'body' => $body,
                    'status' => $approved ? 'approved' : 'pending',
                    'source' => $approved ? 'moderator_reply' : 'account',
                    'moderator_role' => $approved ? ( $actor_is_admin ? 'admin' : 'author' ) : '',
                    'access_type' => 'signed_in',
                    'verification_token_hash' => '',
                    'verification_expires_at_utc' => '',
                    'verified_at_utc' => '',
                    'moderated_at_utc' => $approved ? $now_utc : '',
                    'moderated_by' => $approved ? $actor_username : '',
                    'approved_at_utc' => $approved ? $now_utc : '',
                    'trashed_at_utc' => '',
                    'status_before_trash' => '',
                    'created_at_utc' => $now_utc,
                    'updated_at_utc' => $now_utc,
                    'moderation_history' => $approved
                        ? [ $this->buildModerationEvent( 'approved', $actor_username, '', $now_utc ) ]
                        : [],
                    'reply_notifications_enabled' => $reply_notifications_enabled,
                ]
            )
        );
    }

    public function previewImportedComments( string $source_key, array $records, bool $retain_email_addresses = false ) : array {
        $source_key = $this->requireImportSourceKey( $source_key );
        $counts = [
            'total' => count( $records ),
            'importable' => 0,
            'approved' => 0,
            'pending' => 0,
            'spam' => 0,
            'trash' => 0,
            'malformed' => 0,
            'orphaned' => 0,
            'duplicate_source' => 0,
            'truncated' => 0,
            'emails_retained' => 0,
            'emails_discarded' => 0,
        ];
        $issues = [];
        $normalized_by_source_id = [];
        $source_order = [];

        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                $counts['malformed']++;
                $this->appendImportIssue( $issues, '', 'Malformed comment record.' );
                continue;
            }

            $normalized = $this->normalizeImportedCommentRecord( $record, $retain_email_addresses );
            if ( ! is_array( $normalized ) ) {
                $counts['malformed']++;
                $this->appendImportIssue( $issues, (string) ( $record['source_id'] ?? '' ), 'Malformed comment record.' );
                continue;
            }

            $source_id = (string) $normalized['source_id'];
            if ( isset( $normalized_by_source_id[$source_id] ) ) {
                $counts['duplicate_source']++;
                $this->appendImportIssue( $issues, $source_id, 'Duplicate source comment.' );
                continue;
            }

            if ( ! empty( $normalized['_truncated'] ) ) {
                $counts['truncated']++;
            }
            if ( (string) ( $record['email'] ?? '' ) !== '' ) {
                if ( (string) $normalized['email'] !== '' ) {
                    $counts['emails_retained']++;
                } else {
                    $counts['emails_discarded']++;
                }
            }
            unset( $normalized['_truncated'] );
            $normalized_by_source_id[$source_id] = $normalized;
            $source_order[] = $source_id;
        }

        $thread_roots = [];
        $invalid_relationships = [];
        foreach ( $source_order as $source_id ) {
            $visiting = [];
            $thread_root = $this->resolveImportedThreadRootSourceId( $source_id, $normalized_by_source_id, $thread_roots, $visiting );
            if ( $thread_root === '' ) {
                $invalid_relationships[$source_id] = true;
            }
        }

        $normalized_records = [];
        foreach ( $source_order as $source_id ) {
            if ( isset( $invalid_relationships[$source_id] ) ) {
                $counts['orphaned']++;
                $this->appendImportIssue( $issues, $source_id, 'Missing or circular parent relationship.' );
                continue;
            }

            $record = $normalized_by_source_id[$source_id];
            $record['thread_root_source_id'] = (string) ( $thread_roots[$source_id] ?? $source_id );
            $normalized_records[] = $record;
            $counts['importable']++;
            $status = (string) $record['status'];
            $counts[$status]++;
        }

        return(
            [
                'source_key' => $source_key,
                'records' => $normalized_records,
                'counts' => $counts,
                'issues' => $issues,
                'issues_truncated' => count( $issues ) < $counts['malformed'] + $counts['orphaned'] + $counts['duplicate_source'],
            ]
        );
    }

    public function importComments(
        string $entry_id,
        string $scope,
        string $source_key,
        array $records,
        bool $preserve_approved_status = false,
        bool $retain_email_addresses = false
    ) : array {
        $entry_id = $this->requireEntryId( $entry_id );
        $scope = $this->requireScope( $scope );
        $preview = $this->previewImportedComments( $source_key, $records, $retain_email_addresses );
        $source_key = (string) $preview['source_key'];
        $prepared_records = (array) $preview['records'];
        $result = [
            'imported' => 0,
            'imported_approved' => 0,
            'imported_pending' => 0,
            'imported_spam' => 0,
            'imported_trash' => 0,
            'skipped_duplicate' => 0,
            'skipped_limit' => 0,
            'skipped_relationship' => 0,
        ];
        if ( $prepared_records === [] ) {
            return( [ 'preview' => $preview, 'counts' => $result ] );
        }

        $this->initialize();
        $result = (array) TinyMashLockedJsonFile::mutate(
            $this->getEntryFilename( $entry_id ),
            function( array $store ) use ( $entry_id, $scope, $source_key, $prepared_records, $preserve_approved_status, $result ) : array {
                if ( $store === [] ) {
                    $store = $this->getDefaultEntryStore( $entry_id, $scope );
                }
                $store = $this->requireEntryStore( $store, $entry_id );
                if ( (string) $store['scope'] !== $scope ) {
                    throw new \InvalidArgumentException( 'entry_scope' );
                }

                $counts = $result;
                $source_to_comment_id = [];
                $source_hashes = array_fill_keys( (array) $store['import_source_hashes'], true );
                foreach ( $store['comments'] as $comment ) {
                    $stored_source_key = (string) ( $comment['import_source_key'] ?? '' );
                    $stored_source_id = (string) ( $comment['import_source_id'] ?? '' );
                    if ( $stored_source_key === '' || $stored_source_id === '' ) {
                        continue;
                    }
                    $source_to_comment_id[$stored_source_key . "\0" . $stored_source_id] = (string) $comment['id'];
                    $source_hashes[$this->buildImportSourceHash( $stored_source_key, $stored_source_id )] = true;
                }

                $pending = [];
                foreach ( $prepared_records as $record ) {
                    if ( ! is_array( $record ) ) {
                        continue;
                    }
                    $source_id = (string) ( $record['source_id'] ?? '' );
                    $source_hash = $this->buildImportSourceHash( $source_key, $source_id );
                    if ( isset( $source_hashes[$source_hash] ) ) {
                        $counts['skipped_duplicate']++;
                        continue;
                    }
                    $pending[$source_id] = $record;
                }

                do {
                    $progress = false;
                    foreach ( $pending as $source_id => $record ) {
                        $source_id = (string) $source_id;
                        $parent_source_id = (string) ( $record['parent_source_id'] ?? '' );
                        $parent_id = '';
                        if ( $parent_source_id !== '' ) {
                            $parent_key = $source_key . "\0" . $parent_source_id;
                            if ( ! isset( $source_to_comment_id[$parent_key] ) ) {
                                continue;
                            }
                            $parent_id = $source_to_comment_id[$parent_key];
                        }

                        if ( count( $store['comments'] ) >= self::MAX_COMMENTS_PER_ENTRY ) {
                            $counts['skipped_limit'] += count( $pending );
                            $pending = [];
                            break 2;
                        }

                        $parent = null;
                        if ( $parent_id !== '' ) {
                            $parent_index = $this->findCommentIndex( $store['comments'], $parent_id );
                            if ( $parent_index < 0 ) {
                                continue;
                            }
                            $parent = $store['comments'][$parent_index];
                        }

                        $status = (string) $record['status'];
                        if ( $status === 'approved' && ! $preserve_approved_status ) {
                            $status = 'pending';
                        }
                        if ( $status === 'approved' && is_array( $parent ) && (string) $parent['status'] !== 'approved' ) {
                            $status = 'pending';
                        }

                        $created_at_utc = (string) $record['created_at_utc'];
                        $comment_id = $this->generateCommentId();
                        $comment = [
                            'id' => $comment_id,
                            'entry_id' => $entry_id,
                            'scope' => $scope,
                            'parent_id' => $parent_id,
                            'thread_root_id' => is_array( $parent )
                                ? ( (string) ( $parent['thread_root_id'] ?? '' ) !== '' ? (string) $parent['thread_root_id'] : (string) $parent['id'] )
                                : '',
                            'display_depth' => is_array( $parent ) ? 1 : 0,
                            'author_type' => 'guest',
                            'author_name' => (string) $record['author_name'],
                            'author_username' => '',
                            'email' => (string) $record['email'],
                            'body' => (string) $record['body'],
                            'status' => $status,
                            'source' => 'import',
                            'moderator_role' => '',
                            'access_type' => 'public',
                            'verification_token_hash' => '',
                            'verification_expires_at_utc' => '',
                            'verified_at_utc' => '',
                            'moderated_at_utc' => in_array( $status, [ 'approved', 'spam', 'trash' ], true ) ? $created_at_utc : '',
                            'moderated_by' => '',
                            'approved_at_utc' => $status === 'approved' ? $created_at_utc : '',
                            'trashed_at_utc' => $status === 'trash' ? $created_at_utc : '',
                            'status_before_trash' => $status === 'trash' ? 'pending' : '',
                            'created_at_utc' => $created_at_utc,
                            'updated_at_utc' => $created_at_utc,
                            'moderation_history' => [],
                            'import_source_key' => $source_key,
                            'import_source_id' => $source_id,
                            'import_parent_source_id' => $parent_source_id,
                            'reply_notifications_enabled' => false,
                            'reply_notification_token_hashes' => [],
                        ];
                        $store['comments'][] = $comment;
                        $source_to_comment_id[$source_key . "\0" . $source_id] = $comment_id;
                        $source_hash = $this->buildImportSourceHash( $source_key, $source_id );
                        $source_hashes[$source_hash] = true;
                        $counts['imported']++;
                        $counts['imported_' . $status]++;
                        unset( $pending[$source_id] );
                        $progress = true;
                    }
                } while ( $pending !== [] && $progress );

                if ( $pending !== [] ) {
                    $counts['skipped_relationship'] += count( $pending );
                }
                if ( $counts['imported'] > 0 ) {
                    $store['import_source_hashes'] = array_slice( array_keys( $source_hashes ), -self::IMPORT_SOURCE_HASH_LIMIT );
                    $store['updated_at_utc'] = $this->nowUtc();
                }
                return( [ 'data' => $store, 'result' => $counts ] );
            },
            []
        );

        return( [ 'preview' => $preview, 'counts' => $result ] );
    }

    public function getReplyUnsubscribeRequest( string $entry_id, string $comment_id, string $token ) : ?array {
        $token = trim( $token );
        if ( $token === '' ) {
            return( null );
        }

        $comment = $this->getComment( $entry_id, $comment_id );
        if ( ! is_array( $comment ) || empty( $comment['reply_notifications_enabled'] ) ) {
            return( null );
        }
        $token_hash = hash( 'sha256', $token );
        foreach ( (array) ( $comment['reply_notification_token_hashes'] ?? [] ) as $stored_hash ) {
            if ( is_string( $stored_hash ) && $stored_hash !== '' && hash_equals( $stored_hash, $token_hash ) ) {
                return( $comment );
            }
        }

        return( null );
    }

    public function addReplyUnsubscribeToken( string $entry_id, string $comment_id ) : string {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        return(
            (string) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $comment_id, $token ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if (
                        $index < 0
                        || (string) ( $store['comments'][$index]['status'] ?? '' ) !== 'approved'
                        || empty( $store['comments'][$index]['reply_notifications_enabled'] )
                    ) {
                        return( [ 'data' => $store, 'result' => '' ] );
                    }
                    $hashes = (array) $store['comments'][$index]['reply_notification_token_hashes'];
                    array_unshift( $hashes, hash( 'sha256', $token ) );
                    $store['comments'][$index]['reply_notification_token_hashes'] = array_slice( array_values( array_unique( $hashes ) ), 0, self::UNSUBSCRIBE_TOKEN_LIMIT );
                    $store['comments'][$index]['updated_at_utc'] = $this->nowUtc();
                    $store['updated_at_utc'] = $this->nowUtc();
                    return( [ 'data' => $store, 'result' => $token ] );
                }
            )
        );
    }

    public function unsubscribeFromReplies( string $entry_id, string $comment_id, string $token ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $token = trim( $token );
        if ( $token === '' ) {
            return( false );
        }
        $token_hash = hash( 'sha256', $token );

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $comment_id, $token_hash ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 || empty( $store['comments'][$index]['reply_notifications_enabled'] ) ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    $matched = false;
                    foreach ( (array) $store['comments'][$index]['reply_notification_token_hashes'] as $stored_hash ) {
                        if ( is_string( $stored_hash ) && $stored_hash !== '' && hash_equals( $stored_hash, $token_hash ) ) {
                            $matched = true;
                            break;
                        }
                    }
                    if ( ! $matched ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    $store['comments'][$index]['reply_notifications_enabled'] = false;
                    $store['comments'][$index]['reply_notification_token_hashes'] = [];
                    $store['comments'][$index]['updated_at_utc'] = $this->nowUtc();
                    $store['updated_at_utc'] = $this->nowUtc();
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function verifyGuestComment( string $entry_id, string $comment_id, string $token ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return( false );
        }
        $token_hash = hash( 'sha256', trim( $token ) );
        if ( trim( $token ) === '' ) {
            return( false );
        }

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( $entry_id, $comment_id, $token_hash ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }

                    $comment = $store['comments'][$index];
                    if ( ! $this->commentVerificationMatches( $comment, $token_hash ) ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }

                    $now_utc = $this->nowUtc();
                    $comment['status'] = 'pending';
                    $comment['verified_at_utc'] = $now_utc;
                    $comment['verification_token_hash'] = '';
                    $comment['verification_expires_at_utc'] = '';
                    $comment['updated_at_utc'] = $now_utc;
                    $store['comments'][$index] = $comment;
                    $store['updated_at_utc'] = $now_utc;
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function getGuestVerification( string $entry_id, string $comment_id, string $token ) : ?array {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $token = trim( $token );
        if ( $token === '' ) {
            return( null );
        }

        $comment = $this->getComment( $entry_id, $comment_id );
        return( is_array( $comment ) && $this->commentVerificationMatches( $comment, hash( 'sha256', $token ) ) ? $comment : null );
    }

    public function getGuestVerificationForReissue( string $entry_id, string $comment_id, string $token ) : ?array {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $token = trim( $token );
        if ( $token === '' ) {
            return( null );
        }

        $comment = $this->getComment( $entry_id, $comment_id );
        $stored_hash = is_array( $comment ) ? (string) ( $comment['verification_token_hash'] ?? '' ) : '';
        return(
            is_array( $comment )
            && (string) ( $comment['status'] ?? '' ) === 'awaiting_verification'
            && (string) ( $comment['email'] ?? '' ) !== ''
            && $stored_hash !== ''
            && hash_equals( $stored_hash, hash( 'sha256', $token ) )
                ? $comment
                : null
        );
    }

    public function refreshGuestVerificationToken( string $entry_id, string $comment_id ) : string {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return( '' );
        }

        $token = bin2hex( random_bytes( 32 ) );
        return(
            (string) TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( $entry_id, $comment_id, $token ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 || (string) ( $store['comments'][$index]['status'] ?? '' ) !== 'awaiting_verification' ) {
                        return( [ 'data' => $store, 'result' => '' ] );
                    }

                    $now_utc = $this->nowUtc();
                    $store['comments'][$index]['verification_token_hash'] = hash( 'sha256', $token );
                    $store['comments'][$index]['verification_expires_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() + self::VERIFICATION_TTL_SECONDS );
                    $store['comments'][$index]['updated_at_utc'] = $now_utc;
                    $store['updated_at_utc'] = $now_utc;
                    return( [ 'data' => $store, 'result' => $token ] );
                }
            )
        );
    }

    public function moderateComment(
        string $entry_id,
        string $comment_id,
        string $target_status,
        string $actor_username,
        bool $actor_is_admin,
        string $note = ''
    ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $actor_username = $this->requireUsername( $actor_username );
        $target_status = strtolower( trim( $target_status ) );
        if ( ! in_array( $target_status, [ 'pending', 'approved', 'spam', 'trash' ], true ) ) {
            throw new \InvalidArgumentException( 'status' );
        }
        $note = mb_trim( $note );
        if ( mb_strlen( $note ) > self::MAX_MODERATION_NOTE_CHARACTERS ) {
            throw new \InvalidArgumentException( 'moderation_note' );
        }

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $comment_id, $target_status, $actor_username, $actor_is_admin, $note ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    if ( ! $this->actorCanModerateScope( $actor_username, $actor_is_admin, (string) $store['scope'] ) ) {
                        throw new \RuntimeException( 'forbidden_scope' );
                    }

                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    $comment = $store['comments'][$index];
                    $current_status = (string) ( $comment['status'] ?? '' );
                    if ( $current_status === $target_status ) {
                        return( [ 'data' => $store, 'result' => true ] );
                    }
                    if ( ! $this->transitionIsAllowed( $current_status, $target_status ) ) {
                        throw new \InvalidArgumentException( 'status_transition' );
                    }
                    if (
                        $current_status === 'spam'
                        && $target_status === 'pending'
                        && (string) ( $comment['author_type'] ?? '' ) === 'guest'
                        && (string) ( $comment['verified_at_utc'] ?? '' ) === ''
                    ) {
                        throw new \InvalidArgumentException( 'status_transition' );
                    }

                    $now_utc = $this->nowUtc();
                    if ( $target_status === 'trash' ) {
                        $comment['status_before_trash'] = $current_status;
                        $comment['trashed_at_utc'] = $now_utc;
                    }
                    if ( $target_status === 'approved' ) {
                        $comment['approved_at_utc'] = $now_utc;
                    }
                    $comment['status'] = $target_status;
                    $comment['moderated_at_utc'] = $now_utc;
                    $comment['moderated_by'] = $actor_username;
                    $comment['updated_at_utc'] = $now_utc;
                    $comment['moderation_history'] = $this->appendModerationEvent(
                        is_array( $comment['moderation_history'] ?? null ) ? $comment['moderation_history'] : [],
                        $this->buildModerationEvent( $target_status, $actor_username, $note, $now_utc )
                    );
                    $store['comments'][$index] = $comment;
                    $store['updated_at_utc'] = $now_utc;
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function restoreComment( string $entry_id, string $comment_id, string $actor_username, bool $actor_is_admin ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $actor_username = $this->requireUsername( $actor_username );

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $comment_id, $actor_username, $actor_is_admin ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    if ( ! $this->actorCanModerateScope( $actor_username, $actor_is_admin, (string) $store['scope'] ) ) {
                        throw new \RuntimeException( 'forbidden_scope' );
                    }

                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 || (string) ( $store['comments'][$index]['status'] ?? '' ) !== 'trash' ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    $comment = $store['comments'][$index];
                    $restored_status = (string) ( $comment['status_before_trash'] ?? 'pending' );
                    if ( ! in_array( $restored_status, [ 'awaiting_verification', 'pending', 'approved', 'spam' ], true ) ) {
                        $restored_status = 'pending';
                    }
                    $now_utc = $this->nowUtc();
                    $comment['status'] = $restored_status;
                    $comment['status_before_trash'] = '';
                    $comment['trashed_at_utc'] = '';
                    $comment['moderated_at_utc'] = $now_utc;
                    $comment['moderated_by'] = $actor_username;
                    $comment['updated_at_utc'] = $now_utc;
                    $comment['moderation_history'] = $this->appendModerationEvent(
                        is_array( $comment['moderation_history'] ?? null ) ? $comment['moderation_history'] : [],
                        $this->buildModerationEvent( 'restored:' . $restored_status, $actor_username, '', $now_utc )
                    );
                    $store['comments'][$index] = $comment;
                    $store['updated_at_utc'] = $now_utc;
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function deleteComment( string $entry_id, string $comment_id, string $actor_username, bool $actor_is_admin ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $actor_username = $this->requireUsername( $actor_username );

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $comment_id, $actor_username, $actor_is_admin ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    if ( ! $this->actorCanModerateScope( $actor_username, $actor_is_admin, (string) $store['scope'] ) ) {
                        throw new \RuntimeException( 'forbidden_scope' );
                    }

                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    if ( (string) ( $store['comments'][$index]['status'] ?? '' ) !== 'trash' ) {
                        throw new \InvalidArgumentException( 'comment_not_trashed' );
                    }
                    foreach ( $store['comments'] as $comment ) {
                        if ( (string) ( $comment['parent_id'] ?? '' ) === $comment_id ) {
                            throw new \InvalidArgumentException( 'comment_has_replies' );
                        }
                    }

                    array_splice( $store['comments'], $index, 1 );
                    $store['updated_at_utc'] = $this->nowUtc();
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function discardUnnotifiedComment( string $entry_id, string $comment_id ) : bool {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return( false );
        }

        return(
            (bool) TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( $entry_id, $comment_id ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $index = $this->findCommentIndex( $store['comments'], $comment_id );
                    if ( $index < 0 ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    $comment = $store['comments'][$index];
                    if (
                        ! in_array( (string) ( $comment['status'] ?? '' ), [ 'awaiting_verification', 'pending' ], true )
                        || ! in_array( (string) ( $comment['source'] ?? '' ), [ 'public', 'account' ], true )
                    ) {
                        return( [ 'data' => $store, 'result' => false ] );
                    }
                    foreach ( $store['comments'] as $candidate ) {
                        if ( (string) ( $candidate['parent_id'] ?? '' ) === $comment_id ) {
                            return( [ 'data' => $store, 'result' => false ] );
                        }
                    }

                    array_splice( $store['comments'], $index, 1 );
                    $store['updated_at_utc'] = $this->nowUtc();
                    return( [ 'data' => $store, 'result' => true ] );
                }
            )
        );
    }

    public function getComment( string $entry_id, string $comment_id ) : ?array {
        $entry_id = $this->requireEntryId( $entry_id );
        $comment_id = $this->requireCommentId( $comment_id );
        $store = $this->readEntryStore( $entry_id );
        $index = $this->findCommentIndex( $store['comments'], $comment_id );
        return( $index >= 0 ? $store['comments'][$index] : null );
    }

    public function getCommentsForEntry( string $entry_id, bool $approved_only = false ) : array {
        $entry_id = $this->requireEntryId( $entry_id );
        $comments = $this->readEntryStore( $entry_id )['comments'];
        if ( $approved_only ) {
            $comments = array_values(
                array_filter(
                    $comments,
                    static fn( array $comment ) : bool => (string) ( $comment['status'] ?? '' ) === 'approved'
                )
            );
        }
        usort(
            $comments,
            static function( array $left, array $right ) : int {
                $date_compare = strcmp( (string) ( $left['created_at_utc'] ?? '' ), (string) ( $right['created_at_utc'] ?? '' ) );
                return( $date_compare !== 0 ? $date_compare : strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) ) );
            }
        );
        return( $comments );
    }

    public function getPrivacyComments( string $email_key, string $hmac_key ) : array {
        if ( preg_match( '/^[a-f0-9]{64}$/', $email_key ) !== 1 || preg_match( '/^[a-f0-9]{64}$/', $hmac_key ) !== 1 ) {
            throw new \InvalidArgumentException( 'privacy_key' );
        }

        $comments = [];
        foreach ( $this->getEntryStoreFilenames() as $filename ) {
            $entry_id = basename( $filename, '.json' );
            $store = $this->requireEntryStore( TinyMashLockedJsonFile::readStrict( $filename ), $entry_id );
            foreach ( $store['comments'] as $comment ) {
                $email = (string) ( $comment['email'] ?? '' );
                if (
                    (string) ( $comment['author_type'] ?? '' ) !== 'guest'
                    || $email === ''
                    || ! hash_equals( $email_key, hash_hmac( 'sha256', $email, $hmac_key ) )
                ) {
                    continue;
                }
                $comment['entry_id'] = $entry_id;
                $comments[] = $comment;
            }
        }

        usort(
            $comments,
            static function( array $left, array $right ) : int {
                $date_compare = strcmp( (string) ( $left['created_at_utc'] ?? '' ), (string) ( $right['created_at_utc'] ?? '' ) );
                return( $date_compare !== 0 ? $date_compare : strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) ) );
            }
        );
        return( $comments );
    }

    public function erasePrivacyComments( string $email_key, string $hmac_key ) : array {
        if ( preg_match( '/^[a-f0-9]{64}$/', $email_key ) !== 1 || preg_match( '/^[a-f0-9]{64}$/', $hmac_key ) !== 1 ) {
            throw new \InvalidArgumentException( 'privacy_key' );
        }

        $result = [ 'deleted' => 0, 'anonymized' => 0 ];
        foreach ( $this->getEntryStoreFilenames() as $filename ) {
            $entry_id = basename( $filename, '.json' );
            $entry_result = TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( $entry_id, $email_key, $hmac_key ) : array {
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $matches = [];
                    foreach ( $store['comments'] as $comment ) {
                        $email = (string) ( $comment['email'] ?? '' );
                        if (
                            (string) ( $comment['author_type'] ?? '' ) === 'guest'
                            && $email !== ''
                            && hash_equals( $email_key, hash_hmac( 'sha256', $email, $hmac_key ) )
                        ) {
                            $matches[(string) $comment['id']] = true;
                        }
                    }
                    if ( $matches === [] ) {
                        return( [ 'data' => $store, 'result' => [ 'deleted' => 0, 'anonymized' => 0 ] ] );
                    }

                    $deleted = 0;
                    do {
                        $changed = false;
                        $parent_ids = [];
                        foreach ( $store['comments'] as $comment ) {
                            $parent_id = (string) ( $comment['parent_id'] ?? '' );
                            if ( $parent_id !== '' ) {
                                $parent_ids[$parent_id] = true;
                            }
                        }
                        $kept = [];
                        foreach ( $store['comments'] as $comment ) {
                            $comment_id = (string) ( $comment['id'] ?? '' );
                            if ( isset( $matches[$comment_id] ) && ! isset( $parent_ids[$comment_id] ) ) {
                                unset( $matches[$comment_id] );
                                $deleted++;
                                $changed = true;
                                continue;
                            }
                            $kept[] = $comment;
                        }
                        $store['comments'] = $kept;
                    } while ( $changed && $matches !== [] );

                    $anonymized = 0;
                    $now_utc = $this->nowUtc();
                    foreach ( $store['comments'] as $index => $comment ) {
                        if ( ! isset( $matches[(string) ( $comment['id'] ?? '' )] ) ) {
                            continue;
                        }
                        $comment['author_name'] = 'Deleted commenter';
                        $comment['email'] = '';
                        $comment['body'] = 'This comment was removed at the commenter\'s request.';
                        $comment['verification_token_hash'] = '';
                        $comment['verification_expires_at_utc'] = '';
                        $comment['reply_notifications_enabled'] = false;
                        $comment['reply_notification_token_hashes'] = [];
                        $comment['import_source_key'] = '';
                        $comment['import_source_id'] = '';
                        $comment['import_parent_source_id'] = '';
                        $history = [];
                        foreach ( is_array( $comment['moderation_history'] ?? null ) ? $comment['moderation_history'] : [] as $event ) {
                            if ( is_array( $event ) ) {
                                $event['note'] = '';
                                $history[] = $event;
                            }
                        }
                        $comment['moderation_history'] = $history;
                        $comment['privacy_erased_at_utc'] = $now_utc;
                        $comment['updated_at_utc'] = $now_utc;
                        $store['comments'][$index] = $comment;
                        $anonymized++;
                    }
                    if ( $deleted > 0 || $anonymized > 0 ) {
                        $store['updated_at_utc'] = $now_utc;
                    }
                    return( [ 'data' => $store, 'result' => [ 'deleted' => $deleted, 'anonymized' => $anonymized ] ] );
                }
            );
            $result['deleted'] += (int) ( $entry_result['deleted'] ?? 0 );
            $result['anonymized'] += (int) ( $entry_result['anonymized'] ?? 0 );
        }
        return( $result );
    }

    public function getModerationWorkspace( string $scope, string $status = 'pending', int $limit = 200 ) : array {
        $scope = $this->requireScope( $scope );
        $status = strtolower( trim( $status ) );
        $statuses = [ 'awaiting_verification', 'pending', 'approved', 'spam', 'trash' ];
        if ( ! in_array( $status, $statuses, true ) ) {
            $status = 'pending';
        }
        $limit = min( 200, max( 1, $limit ) );
        $counts = array_fill_keys( $statuses, 0 );
        $comments = [];

        foreach ( $this->getEntryStoreFilenames() as $filename ) {
            $entry_id = basename( $filename, '.json' );
            $store = $this->requireEntryStore( TinyMashLockedJsonFile::readStrict( $filename ), $entry_id );
            if ( (string) $store['scope'] !== $scope ) {
                continue;
            }
            foreach ( $store['comments'] as $comment ) {
                $comment_status = (string) $comment['status'];
                $counts[$comment_status]++;
                if ( $comment_status === $status ) {
                    $comment['entry_id'] = $entry_id;
                    $comments[] = $comment;
                }
            }
        }

        usort(
            $comments,
            static function( array $left, array $right ) : int {
                $date_compare = strcmp( (string) $right['updated_at_utc'], (string) $left['updated_at_utc'] );
                return( $date_compare !== 0 ? $date_compare : strcmp( (string) $right['id'], (string) $left['id'] ) );
            }
        );

        return( [ 'status' => $status, 'counts' => $counts, 'comments' => array_slice( $comments, 0, $limit ) ] );
    }

    public function previewRetention( array $settings = [] ) : array {
        $result = $this->emptyRetentionResult();
        $retention = $this->getRetention( $settings );
        foreach ( $this->getEntryStoreFilenames() as $filename ) {
            $entry_id = basename( $filename, '.json' );
            $store = $this->requireEntryStore( TinyMashLockedJsonFile::readStrict( $filename ), $entry_id );
            $plan = $this->buildRetentionPlan( $store['comments'], $retention );
            $this->addRetentionPlanCounts( $result, $plan );
        }
        return( $result );
    }

    public function prune( array $settings = [] ) : array {
        $result = $this->emptyRetentionResult();
        $retention = $this->getRetention( $settings );
        foreach ( $this->getEntryStoreFilenames() as $filename ) {
            TinyMashLockedJsonFile::mutate(
                $filename,
                function( array $store ) use ( &$result, $retention ) : array {
                    $entry_id = $this->requireEntryId( (string) ( $store['entry_id'] ?? '' ) );
                    $store = $this->requireEntryStore( $store, $entry_id );
                    $plan = $this->buildRetentionPlan( $store['comments'], $retention );
                    $remove_ids = array_fill_keys( array_column( $plan['remove'], 'id' ), true );
                    $anonymize_ids = array_fill_keys( array_column( $plan['anonymize'], 'id' ), true );
                    $kept = [];
                    foreach ( $store['comments'] as $comment ) {
                        $comment_id = (string) ( $comment['id'] ?? '' );
                        if ( isset( $remove_ids[$comment_id] ) ) {
                            continue;
                        }
                        if ( isset( $anonymize_ids[$comment_id] ) ) {
                            $comment = $this->anonymizeComment( $comment, 'Comment removed by retention policy.' );
                        }
                        $kept[] = $comment;
                    }
                    if ( $plan['remove'] !== [] || $plan['anonymize'] !== [] ) {
                        $store['comments'] = $kept;
                        $store['updated_at_utc'] = $this->nowUtc();
                    }
                    $this->addRetentionPlanCounts( $result, $plan );
                    return( $store );
                }
            );
        }

        return( $result );
    }

    protected function buildRetentionPlan( array $comments, array $retention ) : array {
        $plan = [ 'remove' => [], 'anonymize' => [] ];
        $eligible = [];
        foreach ( $comments as $comment ) {
            $status = (string) ( $comment['status'] ?? '' );
            if ( ! in_array( $status, self::RETENTION_STATUSES, true ) ) {
                continue;
            }
            $seconds = match ( $status ) {
                'awaiting_verification' => (int) $retention['verification_seconds'],
                'pending' => (int) $retention['pending_seconds'],
                'spam' => (int) $retention['spam_seconds'],
                'trash' => (int) $retention['trash_seconds'],
            };
            if ( $seconds <= 0 || ! $this->timestampIsOlderThan( $this->retentionTimestamp( $comment ), $seconds ) ) {
                continue;
            }
            $eligible[(string) ( $comment['id'] ?? '' )] = [ 'comment' => $comment, 'status' => $status ];
        }

        $removed_ids = [];
        do {
            $changed = false;
            $parent_ids = [];
            foreach ( $comments as $comment ) {
                if ( isset( $removed_ids[(string) ( $comment['id'] ?? '' )] ) ) {
                    continue;
                }
                $parent_id = (string) ( $comment['parent_id'] ?? '' );
                if ( $parent_id !== '' ) {
                    $parent_ids[$parent_id] = true;
                }
            }
            foreach ( $eligible as $comment_id => $item ) {
                if ( isset( $removed_ids[$comment_id] ) || isset( $parent_ids[$comment_id] ) ) {
                    continue;
                }
                $removed_ids[$comment_id] = true;
                $plan['remove'][] = [ 'id' => $comment_id, 'status' => (string) $item['status'] ];
                $changed = true;
            }
        } while ( $changed );

        foreach ( $eligible as $comment_id => $item ) {
            if (
                isset( $removed_ids[$comment_id] )
                || (string) ( $item['comment']['privacy_erased_at_utc'] ?? '' ) !== ''
            ) {
                continue;
            }
            $plan['anonymize'][] = [ 'id' => $comment_id, 'status' => (string) $item['status'] ];
        }
        return( $plan );
    }

    protected function retentionTimestamp( array $comment ) : string {
        $created_at = (string) ( $comment['created_at_utc'] ?? '' );
        $updated_at = (string) ( $comment['updated_at_utc'] ?? '' );
        $verified_at = (string) ( $comment['verified_at_utc'] ?? '' );
        $moderated_at = (string) ( $comment['moderated_at_utc'] ?? '' );
        $trashed_at = (string) ( $comment['trashed_at_utc'] ?? '' );
        return(
            match ( (string) ( $comment['status'] ?? '' ) ) {
                'pending' => $verified_at !== '' ? $verified_at : $created_at,
                'spam' => $moderated_at !== '' ? $moderated_at : $updated_at,
                'trash' => $trashed_at !== '' ? $trashed_at : $updated_at,
                default => $created_at,
            }
        );
    }

    protected function emptyRetentionResult() : array {
        return(
            [
                'removed' => array_fill_keys( self::RETENTION_STATUSES, 0 ),
                'anonymized' => array_fill_keys( self::RETENTION_STATUSES, 0 ),
                'removed_total' => 0,
                'anonymized_total' => 0,
            ]
        );
    }

    protected function addRetentionPlanCounts( array &$result, array $plan ) : void {
        foreach ( [ 'remove' => 'removed', 'anonymize' => 'anonymized' ] as $plan_key => $result_key ) {
            foreach ( $plan[$plan_key] as $item ) {
                $status = (string) $item['status'];
                $result[$result_key][$status]++;
                $result[$result_key . '_total']++;
            }
        }
    }

    protected function anonymizeComment( array $comment, string $body ) : array {
        $now_utc = $this->nowUtc();
        $comment['author_name'] = 'Deleted commenter';
        $comment['email'] = '';
        $comment['body'] = $body;
        $comment['verification_token_hash'] = '';
        $comment['verification_expires_at_utc'] = '';
        $comment['reply_notifications_enabled'] = false;
        $comment['reply_notification_token_hashes'] = [];
        $comment['import_source_key'] = '';
        $comment['import_source_id'] = '';
        $comment['import_parent_source_id'] = '';
        $history = [];
        foreach ( is_array( $comment['moderation_history'] ?? null ) ? $comment['moderation_history'] : [] as $event ) {
            if ( is_array( $event ) ) {
                $event['note'] = '';
                $history[] = $event;
            }
        }
        $comment['moderation_history'] = $history;
        $comment['privacy_erased_at_utc'] = $now_utc;
        $comment['updated_at_utc'] = $now_utc;
        return( $comment );
    }

    protected function storeComment( string $entry_id, string $scope, string $parent_id, array $record ) : array {
        $comment_id = $this->generateCommentId();
        return(
            (array) TinyMashLockedJsonFile::mutate(
                $this->getEntryFilename( $entry_id ),
                function( array $store ) use ( $entry_id, $scope, $parent_id, $record, $comment_id ) : array {
                    if ( $store === [] ) {
                        $store = $this->getDefaultEntryStore( $entry_id, $scope );
                    }
                    $store = $this->requireEntryStore( $store, $entry_id );
                    if ( (string) $store['scope'] !== $scope ) {
                        throw new \InvalidArgumentException( 'entry_scope' );
                    }
                    if ( count( $store['comments'] ) >= self::MAX_COMMENTS_PER_ENTRY ) {
                        throw new \RuntimeException( 'comment_limit' );
                    }

                    $parent = null;
                    if ( $parent_id !== '' ) {
                        $parent_index = $this->findCommentIndex( $store['comments'], $parent_id );
                        if ( $parent_index < 0 || (string) ( $store['comments'][$parent_index]['status'] ?? '' ) !== 'approved' ) {
                            throw new \InvalidArgumentException( 'parent_comment' );
                        }
                        $parent = $store['comments'][$parent_index];
                    }

                    $comment = array_merge(
                        [
                            'id' => $comment_id,
                            'entry_id' => $entry_id,
                            'scope' => $scope,
                            'parent_id' => $parent_id,
                            'thread_root_id' => is_array( $parent )
                                ? ( (string) ( $parent['thread_root_id'] ?? '' ) !== '' ? (string) $parent['thread_root_id'] : (string) $parent['id'] )
                                : '',
                            'display_depth' => is_array( $parent ) ? 1 : 0,
                            'import_source_key' => '',
                            'import_source_id' => '',
                            'import_parent_source_id' => '',
                            'reply_notifications_enabled' => false,
                            'reply_notification_token_hashes' => [],
                            'moderator_role' => '',
                        ],
                        $record
                    );
                    $store['comments'][] = $comment;
                    $store['updated_at_utc'] = (string) $comment['updated_at_utc'];
                    return( [ 'data' => $store, 'result' => $comment ] );
                },
                []
            )
        );
    }

    protected function readEntryStore( string $entry_id ) : array {
        $filename = $this->getEntryFilename( $entry_id );
        if ( ! is_file( $filename ) ) {
            return( $this->getDefaultEntryStore( $entry_id, 'root' ) );
        }
        return( $this->requireEntryStore( TinyMashLockedJsonFile::readStrict( $filename ), $entry_id ) );
    }

    protected function requireEntryStore( array $store, string $entry_id ) : array {
        if (
            (int) ( $store['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION
            || (string) ( $store['entry_id'] ?? '' ) !== $entry_id
            || $this->normalizeScope( (string) ( $store['scope'] ?? '' ) ) === ''
            || ! is_array( $store['comments'] ?? null )
        ) {
            throw new \RuntimeException( 'comment_store_malformed' );
        }

        if ( count( $store['comments'] ) > self::MAX_COMMENTS_PER_ENTRY ) {
            throw new \RuntimeException( 'comment_store_malformed' );
        }

        if ( ! array_key_exists( 'import_source_hashes', $store ) ) {
            $store['import_source_hashes'] = [];
        }
        if ( ! is_array( $store['import_source_hashes'] ) || count( $store['import_source_hashes'] ) > self::IMPORT_SOURCE_HASH_LIMIT ) {
            throw new \RuntimeException( 'comment_store_malformed' );
        }
        $normalized_import_source_hashes = [];
        foreach ( $store['import_source_hashes'] as $source_hash ) {
            if ( ! is_string( $source_hash ) || preg_match( '/^[a-f0-9]{64}$/', $source_hash ) !== 1 ) {
                throw new \RuntimeException( 'comment_store_malformed' );
            }
            $normalized_import_source_hashes[$source_hash] = $source_hash;
        }
        $store['import_source_hashes'] = array_values( $normalized_import_source_hashes );

        $comments_by_id = [];
        foreach ( $store['comments'] as $index => $comment ) {
            if ( ! is_array( $comment ) ) {
                throw new \RuntimeException( 'comment_store_malformed' );
            }
            if ( ! array_key_exists( 'reply_notification_token_hashes', $comment ) ) {
                $comment['reply_notification_token_hashes'] = [];
                $store['comments'][$index] = $comment;
            }
            $comment_id = (string) ( $comment['id'] ?? '' );
            $parent_id = (string) ( $comment['parent_id'] ?? '' );
            $thread_root_id = (string) ( $comment['thread_root_id'] ?? '' );
            $status = (string) ( $comment['status'] ?? '' );
            if (
                $this->normalizeCommentId( $comment_id ) === ''
                || isset( $comments_by_id[$comment_id] )
                || (string) ( $comment['entry_id'] ?? '' ) !== $entry_id
                || (string) ( $comment['scope'] ?? '' ) !== (string) $store['scope']
                || ( $parent_id !== '' && $this->normalizeCommentId( $parent_id ) === '' )
                || ( $thread_root_id !== '' && $this->normalizeCommentId( $thread_root_id ) === '' )
                || ! in_array( $status, [ 'awaiting_verification', 'pending', 'approved', 'spam', 'trash' ], true )
                || ! in_array( (int) ( $comment['display_depth'] ?? -1 ), [ 0, 1 ], true )
                || ! is_string( $comment['body'] ?? null )
                || ! is_string( $comment['import_source_key'] ?? null )
                || ! is_string( $comment['import_source_id'] ?? null )
                || ! is_string( $comment['import_parent_source_id'] ?? null )
                || ! is_bool( $comment['reply_notifications_enabled'] ?? null )
                || ! is_array( $comment['reply_notification_token_hashes'] ?? null )
                || count( $comment['reply_notification_token_hashes'] ) > self::UNSUBSCRIBE_TOKEN_LIMIT
                || ! in_array( (string) ( $comment['moderator_role'] ?? '' ), [ '', 'author', 'admin' ], true )
            ) {
                throw new \RuntimeException( 'comment_store_malformed' );
            }
            foreach ( $comment['reply_notification_token_hashes'] as $token_hash ) {
                if ( ! is_string( $token_hash ) || preg_match( '/^[a-f0-9]{64}$/', $token_hash ) !== 1 ) {
                    throw new \RuntimeException( 'comment_store_malformed' );
                }
            }
            $comments_by_id[$comment_id] = $comment;
        }

        foreach ( $comments_by_id as $comment_id => $comment ) {
            $parent_id = (string) $comment['parent_id'];
            $thread_root_id = (string) $comment['thread_root_id'];
            $display_depth = (int) $comment['display_depth'];
            if ( $parent_id === '' ) {
                if ( $thread_root_id !== '' || $display_depth !== 0 ) {
                    throw new \RuntimeException( 'comment_store_malformed' );
                }
                continue;
            }
            if (
                $parent_id === $comment_id
                || ! isset( $comments_by_id[$parent_id] )
                || ! isset( $comments_by_id[$thread_root_id] )
                || (string) $comments_by_id[$thread_root_id]['parent_id'] !== ''
                || $display_depth !== 1
            ) {
                throw new \RuntimeException( 'comment_store_malformed' );
            }

            $ancestor_id = $parent_id;
            for ( $depth = 0; $depth <= count( $comments_by_id ); $depth++ ) {
                if ( $ancestor_id === $thread_root_id ) {
                    break;
                }
                $ancestor_id = (string) ( $comments_by_id[$ancestor_id]['parent_id'] ?? '' );
                if ( $ancestor_id === '' || ! isset( $comments_by_id[$ancestor_id] ) ) {
                    throw new \RuntimeException( 'comment_store_malformed' );
                }
            }
            if ( $ancestor_id !== $thread_root_id ) {
                throw new \RuntimeException( 'comment_store_malformed' );
            }
        }

        return( $store );
    }

    protected function getDefaultEntryStore( string $entry_id, string $scope ) : array {
        return(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'entry_id' => $entry_id,
                'scope' => $scope,
                'updated_at_utc' => '',
                'import_source_hashes' => [],
                'comments' => [],
            ]
        );
    }

    protected function getEntryStoreFilenames() : array {
        $directory = $this->getEntriesDirectory();
        if ( ! is_dir( $directory ) || is_link( $directory ) ) {
            return( [] );
        }

        $filenames = [];
        foreach ( scandir( $directory ) ?: [] as $name ) {
            if ( preg_match( '/^entry_[a-z0-9_]{1,96}\.json$/', $name ) !== 1 ) {
                continue;
            }
            $filename = $directory . DIRECTORY_SEPARATOR . $name;
            if ( is_file( $filename ) && ! is_link( $filename ) ) {
                $filenames[] = $filename;
            }
        }
        sort( $filenames, SORT_STRING );
        return( $filenames );
    }

    protected function getStateFilename() : string {
        $filename = $this->storage_directory . DIRECTORY_SEPARATOR . 'state.json';
        $this->assertStorePathIsSafe( $filename );
        return( $filename );
    }

    protected function getEntriesDirectory() : string {
        return( $this->storage_directory . DIRECTORY_SEPARATOR . 'entries' );
    }

    protected function getEntryFilename( string $entry_id ) : string {
        $filename = $this->getEntriesDirectory() . DIRECTORY_SEPARATOR . $entry_id . '.json';
        $this->assertStorePathIsSafe( $filename );
        return( $filename );
    }

    protected function requireEntryId( string $entry_id ) : string {
        $entry_id = trim( $entry_id );
        if ( preg_match( '/^entry_[a-z0-9_]{1,96}$/', $entry_id ) !== 1 ) {
            throw new \InvalidArgumentException( 'entry_id' );
        }
        return( $entry_id );
    }

    protected function normalizeCommentId( string $comment_id ) : string {
        $comment_id = trim( $comment_id );
        return( preg_match( '/^comment_[0-9]{8}_[0-9]{6}_[a-f0-9]{16}$/', $comment_id ) === 1 ? $comment_id : '' );
    }

    protected function requireCommentId( string $comment_id ) : string {
        $comment_id = $this->normalizeCommentId( $comment_id );
        if ( $comment_id === '' ) {
            throw new \InvalidArgumentException( 'comment_id' );
        }
        return( $comment_id );
    }

    protected function normalizeOptionalCommentId( string $comment_id ) : string {
        $comment_id = trim( $comment_id );
        return( $comment_id === '' ? '' : $this->requireCommentId( $comment_id ) );
    }

    protected function requireScope( string $scope ) : string {
        $scope = $this->normalizeScope( $scope );
        if ( $scope === '' ) {
            throw new \InvalidArgumentException( 'scope' );
        }
        return( $scope );
    }

    protected function normalizeAccessPolicy( string $access_policy ) : string {
        $access_policy = strtolower( trim( $access_policy ) );
        return( in_array( $access_policy, [ 'anyone', 'secret_or_signed_in', 'signed_in' ], true ) ? $access_policy : 'signed_in' );
    }

    protected function normalizeRetentionDays( mixed $value, array $allowed, int $default ) : int {
        if ( ! is_int( $value ) && ! is_string( $value ) ) {
            return( $default );
        }
        $value = filter_var( $value, FILTER_VALIDATE_INT );
        return( is_int( $value ) && in_array( $value, $allowed, true ) ? $value : $default );
    }

    protected function normalizeEntryMode( string $mode ) : string {
        $mode = strtolower( trim( $mode ) );
        return( in_array( $mode, [ 'inherit', 'open', 'closed' ], true ) ? $mode : 'inherit' );
    }

    protected function requireDisplayName( string $name ) : string {
        $name = mb_trim( $name );
        if (
            $name === ''
            || mb_strlen( $name ) > self::MAX_NAME_CHARACTERS
            || preg_match( '/(?:(?=\p{Extended_Pictographic})\X|\p{Regional_Indicator}{2}|[#*0-9]\x{FE0F}?\x{20E3})/u', $name ) === 1
            || preg_match( '/(?<![A-Za-z0-9]):[a-z0-9_+-]+:(?![A-Za-z0-9])/iu', $name ) === 1
            || preg_match( '/(?<![A-Za-z0-9])(?:<3|[:;8][\-~]?[)(DPpOo\/\\\\|])(?![A-Za-z0-9])/u', $name ) === 1
        ) {
            throw new \InvalidArgumentException( 'author_name' );
        }
        return( $name );
    }

    protected function requireEmail( string $email ) : string {
        $email = mb_strtolower( mb_trim( $email ) );
        if (
            $email === ''
            || mb_strlen( $email ) > self::MAX_EMAIL_CHARACTERS
            || filter_var( $email, FILTER_VALIDATE_EMAIL ) === false
        ) {
            throw new \InvalidArgumentException( 'email' );
        }
        return( $email );
    }

    protected function requireBody( string $body ) : string {
        $body = str_replace( [ "\r\n", "\r" ], "\n", mb_trim( $body ) );
        if ( $body === '' || mb_strlen( $body ) > self::MAX_BODY_CHARACTERS ) {
            throw new \InvalidArgumentException( 'body' );
        }
        if (
            preg_match( '~<[/!?]?[a-z][^>]*>~iu', $body ) === 1
            || preg_match( '~!\[[^\]]*\]\s*\(~u', $body ) === 1
            || preg_match( '~\]\(\s*(?:javascript|data|vbscript|file):~iu', $body ) === 1
        ) {
            throw new \InvalidArgumentException( 'body_format' );
        }
        if ( preg_match_all( '~https?://~iu', $body ) > self::MAX_LINKS ) {
            throw new \InvalidArgumentException( 'links' );
        }
        return( $body );
    }

    protected function requireImportSourceKey( string $source_key ) : string {
        $source_key = strtolower( trim( $source_key ) );
        if ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $source_key ) !== 1 ) {
            throw new \InvalidArgumentException( 'import_source_key' );
        }
        return( $source_key );
    }

    protected function normalizeImportedCommentRecord( array $record, bool $retain_email_addresses ) : ?array {
        $source_id = mb_trim( (string) ( $record['source_id'] ?? '' ) );
        $parent_source_id = mb_trim( (string) ( $record['parent_source_id'] ?? '' ) );
        if (
            $source_id === ''
            || mb_strlen( $source_id ) > self::MAX_IMPORT_SOURCE_CHARACTERS
            || mb_strlen( $parent_source_id ) > self::MAX_IMPORT_SOURCE_CHARACTERS
        ) {
            return( null );
        }

        $status = strtolower( trim( (string) ( $record['status'] ?? '' ) ) );
        if ( ! in_array( $status, [ 'approved', 'pending', 'spam', 'trash' ], true ) ) {
            return( null );
        }

        $created_timestamp = $this->parseUtcTimestamp( (string) ( $record['created_at_utc'] ?? '' ) );
        if ( $created_timestamp === null ) {
            return( null );
        }

        $author_name = html_entity_decode( strip_tags( (string) ( $record['author_name'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $author_name = preg_replace( '/\s+/u', ' ', mb_trim( $author_name ) ) ?? '';
        if ( filter_var( $author_name, FILTER_VALIDATE_EMAIL ) !== false ) {
            $author_name = 'Anonymous';
        }
        if ( $author_name === '' ) {
            $author_name = 'Anonymous';
        }
        $author_name = mb_substr( $author_name, 0, self::MAX_NAME_CHARACTERS );

        $email = '';
        if ( $retain_email_addresses ) {
            $candidate_email = mb_strtolower( mb_trim( (string) ( $record['email'] ?? '' ) ) );
            if ( mb_strlen( $candidate_email ) <= self::MAX_EMAIL_CHARACTERS && filter_var( $candidate_email, FILTER_VALIDATE_EMAIL ) !== false ) {
                $email = $candidate_email;
            }
        }

        $truncated = false;
        $body = $this->normalizeImportedCommentBody(
            (string) ( $record['body'] ?? '' ),
            (string) ( $record['body_format'] ?? 'html' ),
            $truncated
        );
        try {
            $body = $this->requireBody( $body );
        } catch ( \InvalidArgumentException ) {
            return( null );
        }

        return(
            [
                'source_id' => $source_id,
                'parent_source_id' => $parent_source_id,
                'author_name' => $author_name,
                'email' => $email,
                'body' => $body,
                'body_format' => 'markdown',
                'status' => $status,
                'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $created_timestamp ),
                '_truncated' => $truncated,
            ]
        );
    }

    protected function normalizeImportedCommentBody( string $body, string $format, bool &$truncated ) : string {
        $truncated = false;
        $body = str_replace( [ "\r\n", "\r" ], "\n", $body );
        if ( strtolower( trim( $format ) ) === 'html' && $body !== '' ) {
            $body = $this->renderImportedCommentHtml( $body );
        }

        $body = preg_replace( '/[\t ]+/u', ' ', $body ) ?? $body;
        $body = preg_replace( '/ *\n */u', "\n", $body ) ?? $body;
        $body = preg_replace( '/\n{3,}/u', "\n\n", $body ) ?? $body;
        $body = preg_replace( '/!\[/', '\\![', $body ) ?? $body;
        $body = preg_replace( '~\]\(\s*(?:javascript|data|vbscript|file):~iu', '](', $body ) ?? $body;
        $body = mb_trim( $body );
        if ( mb_strlen( $body ) > self::MAX_BODY_CHARACTERS ) {
            $body = mb_substr( $body, 0, self::MAX_BODY_CHARACTERS - 1 ) . '…';
            $truncated = true;
        }
        return( $body );
    }

    protected function renderImportedCommentHtml( string $html ) : string {
        if ( ! class_exists( '\DOMDocument' ) ) {
            return( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="tm-comment-import-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );
        if ( ! $loaded ) {
            return( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        $root = $document->getElementById( 'tm-comment-import-root' );
        if ( ! $root instanceof \DOMElement ) {
            return( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }

        $link_count = 0;
        $rendered = '';
        foreach ( $root->childNodes as $child ) {
            $rendered .= $this->renderImportedCommentNode( $child, $link_count );
        }
        return( $rendered );
    }

    protected function renderImportedCommentNode( \DOMNode $node, int &$link_count ) : string {
        if ( $node instanceof \DOMText ) {
            return( str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], (string) $node->nodeValue ) );
        }
        if ( ! $node instanceof \DOMElement ) {
            return( '' );
        }

        $tag = strtolower( $node->tagName );
        if ( in_array( $tag, [ 'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math' ], true ) ) {
            return( '' );
        }
        if ( $tag === 'br' ) {
            return( "\n" );
        }
        if ( $tag === 'img' ) {
            return( '' );
        }

        $content = '';
        foreach ( $node->childNodes as $child ) {
            $content .= $this->renderImportedCommentNode( $child, $link_count );
        }
        if ( in_array( $tag, [ 'strong', 'b' ], true ) && mb_trim( $content ) !== '' ) {
            return( '**' . mb_trim( $content ) . '**' );
        }
        if ( in_array( $tag, [ 'em', 'i' ], true ) && mb_trim( $content ) !== '' ) {
            return( '*' . mb_trim( $content ) . '*' );
        }
        if ( $tag === 'code' && mb_trim( $content ) !== '' ) {
            return( '`' . str_replace( '`', "'", mb_trim( $content ) ) . '`' );
        }
        if ( $tag === 'a' ) {
            $label = mb_trim( $content );
            $url = trim( $node->getAttribute( 'href' ) );
            if ( $label !== '' && $link_count < self::MAX_LINKS && $this->importedCommentUrlIsSafe( $url ) ) {
                $link_count++;
                return( '[' . $label . '](' . $url . ')' );
            }
            return( $label );
        }
        if ( in_array( $tag, [ 'p', 'div', 'blockquote', 'li', 'ul', 'ol', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
            return( "\n\n" . $content . "\n\n" );
        }
        return( $content );
    }

    protected function importedCommentUrlIsSafe( string $url ) : bool {
        if ( $url === '' || preg_match( '/[\s()]/u', $url ) === 1 || filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            return( false );
        }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || empty( $parts['host'] ) ) {
            return( false );
        }
        return( in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) );
    }

    protected function resolveImportedThreadRootSourceId( string $source_id, array $records_by_source_id, array &$memo, array &$visiting ) : string {
        if ( isset( $memo[$source_id] ) ) {
            return( (string) $memo[$source_id] );
        }
        if ( isset( $visiting[$source_id] ) || ! isset( $records_by_source_id[$source_id] ) ) {
            return( '' );
        }

        $visiting[$source_id] = true;
        $parent_source_id = (string) ( $records_by_source_id[$source_id]['parent_source_id'] ?? '' );
        if ( $parent_source_id === '' ) {
            $memo[$source_id] = $source_id;
        } elseif ( ! isset( $records_by_source_id[$parent_source_id] ) ) {
            $memo[$source_id] = '';
        } else {
            $memo[$source_id] = $this->resolveImportedThreadRootSourceId( $parent_source_id, $records_by_source_id, $memo, $visiting );
        }
        unset( $visiting[$source_id] );
        return( (string) $memo[$source_id] );
    }

    protected function appendImportIssue( array &$issues, string $source_id, string $reason ) : void {
        if ( count( $issues ) >= 25 ) {
            return;
        }
        $issues[] = [
            'source_id' => mb_substr( mb_trim( $source_id ), 0, self::MAX_IMPORT_SOURCE_CHARACTERS ),
            'reason' => $reason,
        ];
    }

    protected function buildImportSourceHash( string $source_key, string $source_id ) : string {
        return( hash( 'sha256', $source_key . "\0" . $source_id ) );
    }

    protected function normalizeUsername( string $username ) : string {
        $username = strtolower( trim( $username ) );
        return( preg_match( '/^[a-z0-9_]{1,64}$/', $username ) === 1 ? $username : '' );
    }

    protected function requireUsername( string $username ) : string {
        $username = $this->normalizeUsername( $username );
        if ( $username === '' ) {
            throw new \InvalidArgumentException( 'actor_username' );
        }
        return( $username );
    }

    protected function findCommentIndex( array $comments, string $comment_id ) : int {
        foreach ( $comments as $index => $comment ) {
            if ( is_array( $comment ) && (string) ( $comment['id'] ?? '' ) === $comment_id ) {
                return( (int) $index );
            }
        }
        return( -1 );
    }

    protected function transitionIsAllowed( string $current_status, string $target_status ) : bool {
        $allowed = [
            'awaiting_verification' => [ 'spam', 'trash' ],
            'pending' => [ 'approved', 'spam', 'trash' ],
            'approved' => [ 'pending', 'spam', 'trash' ],
            'spam' => [ 'pending', 'trash' ],
            'trash' => [],
        ];
        return( in_array( $target_status, $allowed[$current_status] ?? [], true ) );
    }

    protected function commentVerificationMatches( array $comment, string $token_hash ) : bool {
        $expires_at = $this->parseUtcTimestamp( (string) ( $comment['verification_expires_at_utc'] ?? '' ) );
        $stored_hash = (string) ( $comment['verification_token_hash'] ?? '' );
        return(
            (string) ( $comment['status'] ?? '' ) === 'awaiting_verification'
            && $expires_at !== null
            && $expires_at >= $this->nowTimestamp()
            && $stored_hash !== ''
            && hash_equals( $stored_hash, $token_hash )
        );
    }

    protected function buildModerationEvent( string $status, string $actor_username, string $note, string $at_utc ) : array {
        return(
            [
                'status' => $status,
                'actor_username' => $actor_username,
                'note' => $note,
                'at_utc' => $at_utc,
            ]
        );
    }

    protected function appendModerationEvent( array $history, array $event ) : array {
        $history[] = $event;
        return( array_slice( $history, -self::MODERATION_HISTORY_LIMIT ) );
    }

    protected function generateCommentId() : string {
        return( 'comment_' . gmdate( 'Ymd_His', $this->nowTimestamp() ) . '_' . bin2hex( random_bytes( 8 ) ) );
    }

    protected function nowTimestamp() : int {
        return( $this->clock instanceof \Closure ? (int) ( $this->clock )() : time() );
    }

    protected function nowUtc() : string {
        return( gmdate( 'Y-m-d\TH:i:s\Z', $this->nowTimestamp() ) );
    }

    protected function parseUtcTimestamp( string $value ) : ?int {
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', trim( $value ), new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
            return( null );
        }
        return( $date->getTimestamp() );
    }

    protected function timestampIsOlderThan( string $value, int $seconds ) : bool {
        $timestamp = $this->parseUtcTimestamp( $value );
        return( $timestamp !== null && $timestamp < $this->nowTimestamp() - $seconds );
    }

    protected function assertStorePathIsSafe( string $filename ) : void {
        if (
            is_link( $this->storage_directory )
            || is_link( dirname( $filename ) )
            || is_link( $filename )
            || is_link( $filename . '.lock' )
        ) {
            throw new \RuntimeException( 'comment_store_path' );
        }
    }
}
