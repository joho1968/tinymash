<?php

use app\classes\TinyMashContentRepository;
use app\classes\TinyMashLockedJsonFile;

class TinyMashMarkdownSyncService {

    protected const STORE_VERSION = 1;
    protected const PLAN_TTL_SECONDS = 600;
    protected const AUTH_FAILURE_LIMIT = 10;
    protected const AUTH_FAILURE_WINDOW_SECONDS = 900;
    protected ?\Closure $lifecycle_handler;

    public function __construct(
        protected string $store_filename,
        protected TinyMashContentRepository $content_repository,
        protected TinyMashMarkdownCollectionService $collection_service,
        ?callable $lifecycle_handler = null,
        protected string $public_base_path = ''
    ) {
        $this->lifecycle_handler = $lifecycle_handler !== null ? \Closure::fromCallable( $lifecycle_handler ) : null;
    }

    public function getTargetOptions() : array {
        $owned_entry_ids = [];
        foreach ( $this->readStore()['connections'] as $connection ) {
            if ( ! is_array( $connection ) ) {
                continue;
            }
            $owned_entry_ids[(string) ( $connection['target_entry_id'] ?? '' )] = true;
            foreach ( is_array( $connection['pages'] ?? null ) ? $connection['pages'] : [] as $mapping ) {
                if ( is_array( $mapping ) ) {
                    $owned_entry_ids[(string) ( $mapping['entry_id'] ?? '' )] = true;
                }
            }
        }
        $options = [];
        foreach ( $this->content_repository->getEditorEntryOptions() as $option ) {
            if (
                ! is_array( $option ) ||
                (string) ( $option['type'] ?? '' ) !== 'page' ||
                (string) ( $option['scope'] ?? '' ) !== 'root' ||
                isset( $owned_entry_ids[(string) ( $option['id'] ?? '' )] )
            ) {
                continue;
            }
            $options[] = $option;
        }
        return( $options );
    }

    public function listConnections() : array {
        $store = $this->readStore();
        $connections = [];
        foreach ( $store['connections'] as $connection ) {
            if ( ! is_array( $connection ) ) {
                continue;
            }
            $connection['target'] = $this->getValidTarget( (string) ( $connection['target_entry_id'] ?? '' ) );
            $connection['target_available'] = is_array( $connection['target'] );
            unset( $connection['token_hash'], $connection['plans'] );
            $connections[] = $connection;
        }
        usort( $connections, static fn( array $left, array $right ) : int => strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
        return( $connections );
    }

    public function createConnection( string $label, string $target_entry_id ) : array {
        $label = mb_trim( $label );
        if ( $label === '' || mb_strlen( $label ) > 120 ) {
            throw new \InvalidArgumentException( 'connection_label' );
        }
        if ( ! is_array( $this->getValidTarget( $target_entry_id ) ) ) {
            throw new \InvalidArgumentException( 'connection_target' );
        }

        $connection_id = 'mds_' . bin2hex( random_bytes( 10 ) );
        $secret = $this->generateSecret();
        $now = gmdate( 'Y-m-d\TH:i:s\Z' );
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $connection_id, $label, $target_entry_id, $secret, $now ) : array {
                $store = $this->normalizeStore( $store );
                foreach ( $store['connections'] as $existing_connection ) {
                    if ( ! is_array( $existing_connection ) ) {
                        continue;
                    }
                    if ( (string) ( $existing_connection['target_entry_id'] ?? '' ) === $target_entry_id ) {
                        throw new \InvalidArgumentException( 'connection_target_owned' );
                    }
                    foreach ( is_array( $existing_connection['pages'] ?? null ) ? $existing_connection['pages'] : [] as $mapping ) {
                        if ( is_array( $mapping ) && (string) ( $mapping['entry_id'] ?? '' ) === $target_entry_id ) {
                            throw new \InvalidArgumentException( 'connection_target_owned' );
                        }
                    }
                }
                $store['connections'][$connection_id] = [
                    'id' => $connection_id,
                    'label' => $label,
                    'target_entry_id' => trim( $target_entry_id ),
                    'collection_id' => '',
                    'enabled' => true,
                    'token_hash' => password_hash( $secret, PASSWORD_DEFAULT ),
                    'created_at_utc' => $now,
                    'updated_at_utc' => $now,
                    'last_used_at_utc' => '',
                    'last_sync_at_utc' => '',
                    'pages' => [],
                    'plans' => [],
                    'history' => [],
                ];
                return( $store );
            },
            $this->defaultStore()
        );

        return( [ 'connection_id' => $connection_id, 'token' => $this->formatToken( $connection_id, $secret ) ] );
    }

    public function rotateToken( string $connection_id ) : string {
        $secret = $this->generateSecret();
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $connection_id, $secret ) : array {
                $store = $this->normalizeStore( $store );
                if ( ! isset( $store['connections'][$connection_id] ) ) {
                    throw new \InvalidArgumentException( 'connection_missing' );
                }
                $store['connections'][$connection_id]['token_hash'] = password_hash( $secret, PASSWORD_DEFAULT );
                $store['connections'][$connection_id]['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $store['connections'][$connection_id]['plans'] = [];
                return( $store );
            },
            $this->defaultStore()
        );
        return( $this->formatToken( $connection_id, $secret ) );
    }

    public function setConnectionEnabled( string $connection_id, bool $enabled ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $connection_id, $enabled ) : array {
                $store = $this->normalizeStore( $store );
                if ( ! isset( $store['connections'][$connection_id] ) ) {
                    throw new \InvalidArgumentException( 'connection_missing' );
                }
                $store['connections'][$connection_id]['enabled'] = $enabled;
                $store['connections'][$connection_id]['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                $store['connections'][$connection_id]['plans'] = [];
                return( $store );
            },
            $this->defaultStore()
        );
    }

    public function deleteUnusedConnection( string $connection_id ) : bool {
        return( (bool) TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $connection_id ) : array {
                $store = $this->normalizeStore( $store );
                $connection = $store['connections'][$connection_id] ?? null;
                if ( ! is_array( $connection ) ) {
                    return( [ 'data' => $store, 'result' => false ] );
                }
                if ( ! empty( $connection['pages'] ) ) {
                    throw new \RuntimeException( 'connection_has_pages' );
                }
                unset( $store['connections'][$connection_id] );
                return( [ 'data' => $store, 'result' => true ] );
            },
            $this->defaultStore()
        ) );
    }

    public function authenticateToken( string $token, string $client_identifier = 'local' ) : array {
        $failure_key = hash( 'sha256', mb_trim( $client_identifier ) !== '' ? mb_trim( $client_identifier ) : 'unknown' );
        $this->assertAuthenticationAllowed( $failure_key );
        try {
            $parts = explode( '.', trim( $token ) );
            if ( count( $parts ) !== 3 || $parts[0] !== 'tms1' ) {
                throw new \RuntimeException( 'authentication_failed' );
            }
            $connection_id = trim( $parts[1] );
            $secret = trim( $parts[2] );
            if ( preg_match( '/^mds_[a-f0-9]{20}$/D', $connection_id ) !== 1 || preg_match( '/^[A-Za-z0-9_-]{40,128}$/D', $secret ) !== 1 ) {
                throw new \RuntimeException( 'authentication_failed' );
            }

            $store = $this->readStore();
            $connection = $store['connections'][$connection_id] ?? null;
            if (
                ! is_array( $connection ) ||
                empty( $connection['enabled'] ) ||
                ! is_string( $connection['token_hash'] ?? null ) ||
                ! password_verify( $secret, (string) $connection['token_hash'] )
            ) {
                throw new \RuntimeException( 'authentication_failed' );
            }
        } catch ( \Throwable $exception ) {
            $this->recordAuthenticationFailure( $failure_key );
            throw $exception;
        }
        $this->recordSuccessfulAuthentication( (string) $connection['id'], $failure_key );
        if ( ! is_array( $this->getValidTarget( (string) ( $connection['target_entry_id'] ?? '' ) ) ) ) {
            throw new \RuntimeException( 'target_unavailable' );
        }
        return( $connection );
    }

    public function createPlan( array $connection, array $bundle, bool $prune = false ) : array {
        return( $this->withApplyLock( function() use ( $connection, $bundle, $prune ) : array {
            $authenticated_connection = $connection;
            $connection = $this->freshConnection( (string) ( $authenticated_connection['id'] ?? '' ) );
            $this->assertCredentialIsCurrent( $authenticated_connection, $connection );
            $bundle = $this->collection_service->normalizeBundle( $bundle );
            $plan = $this->buildPlan( $connection, $bundle, $prune );
            $plan_id = 'plan_' . bin2hex( random_bytes( 12 ) );
            $expires_at = time() + self::PLAN_TTL_SECONDS;

            TinyMashLockedJsonFile::mutate(
                $this->store_filename,
                function( array $store ) use ( $connection, $plan_id, $bundle, $plan, $prune, $expires_at ) : array {
                    $store = $this->normalizeStore( $store );
                    $connection_id = (string) $connection['id'];
                    if ( ! isset( $store['connections'][$connection_id] ) ) {
                        throw new \RuntimeException( 'connection_missing' );
                    }
                    $this->assertCredentialIsCurrent( $connection, $store['connections'][$connection_id] );
                    if ( empty( $store['connections'][$connection_id]['enabled'] ) ) {
                        throw new \RuntimeException( 'connection_unavailable' );
                    }
                    $store['connections'][$connection_id]['plans'] = [
                        $plan_id => [
                            'id' => $plan_id,
                            'bundle_digest' => (string) $bundle['digest'],
                            'state_fingerprint' => (string) $plan['state_fingerprint'],
                            'prune' => $prune,
                            'expires_at' => $expires_at,
                        ],
                    ];
                    return( $store );
                },
                $this->defaultStore()
            );

            $plan['plan_id'] = $plan_id;
            $plan['expires_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z', $expires_at );
            return( $this->publicPlan( $plan ) );
        } ) );
    }

    public function applyPlan( array $connection, string $plan_id, array $bundle, bool $prune = false ) : array {
        return( $this->withApplyLock( function() use ( $connection, $plan_id, $bundle, $prune ) : array {
            $authenticated_connection = $connection;
            $connection = $this->freshConnection( (string) ( $authenticated_connection['id'] ?? '' ) );
            $this->assertCredentialIsCurrent( $authenticated_connection, $connection );
            $bundle = $this->collection_service->normalizeBundle( $bundle );
            $stored_plan = is_array( $connection['plans'][$plan_id] ?? null ) ? $connection['plans'][$plan_id] : null;
            if ( ! is_array( $stored_plan ) || (int) ( $stored_plan['expires_at'] ?? 0 ) < time() ) {
                throw new \RuntimeException( 'plan_expired' );
            }
            if (
                (string) ( $stored_plan['bundle_digest'] ?? '' ) !== (string) $bundle['digest'] ||
                ! is_bool( $stored_plan['prune'] ?? null ) ||
                (bool) $stored_plan['prune'] !== $prune
            ) {
                throw new \RuntimeException( 'plan_changed' );
            }

            $plan = $this->buildPlan( $connection, $bundle, $prune );
            if ( ! hash_equals( (string) ( $stored_plan['state_fingerprint'] ?? '' ), (string) $plan['state_fingerprint'] ) ) {
                throw new \RuntimeException( 'receiver_changed' );
            }
            if ( (int) ( $plan['counts']['conflict'] ?? 0 ) > 0 ) {
                throw new \RuntimeException( 'plan_conflicts' );
            }

            $operations = [];
            $operation_sources = [];
            $operation_receiver_hashes = [];
            foreach ( $plan['items'] as $item ) {
                if ( ! is_array( $item ) || ! in_array( (string) ( $item['action'] ?? '' ), [ 'create', 'update', 'move' ], true ) ) {
                    continue;
                }
                $operations[] = [
                    'action' => 'save',
                    'loaded_entry_id' => (string) ( $item['entry_id'] ?? '' ),
                    'editor_entry' => $item['editor_entry'],
                    'status' => (string) $item['status'],
                    'options' => [ 'write_revision' => true ],
                ];
                $operation_sources[] = (string) $item['source_id'];
                $operation_receiver_hashes[] = $this->computeEditorReceiverHash( $item['editor_entry'], (string) $item['status'] );
            }

            $stale_items = array_values( array_filter( $plan['items'], static fn( mixed $item ) : bool => is_array( $item ) && (string) ( $item['action'] ?? '' ) === 'trash' ) );
            usort( $stale_items, static fn( array $left, array $right ) : int => substr_count( (string) ( $right['relative_path'] ?? '' ), '/' ) <=> substr_count( (string) ( $left['relative_path'] ?? '' ), '/' ) );
            foreach ( $stale_items as $item ) {
                $operations[] = [ 'action' => 'trash', 'entry_id' => (string) $item['entry_id'], 'trashed_by' => 'markdown-sync' ];
                $operation_sources[] = (string) $item['source_id'];
                $operation_receiver_hashes[] = '';
            }

            $updated_pages = $this->buildUpdatedPageMap( $connection, $bundle, $plan );
            $state_writer = function( array $results ) use ( $connection, $bundle, $plan, $plan_id, $operation_sources, $operation_receiver_hashes, &$updated_pages ) : void {
                foreach ( $results as $result_index => $result ) {
                    $source_id = (string) ( $operation_sources[$result_index] ?? '' );
                    if ( $source_id === '' ) {
                        continue;
                    }
                    if ( (string) ( $result['action'] ?? '' ) === 'trash' ) {
                        unset( $updated_pages[$source_id] );
                        continue;
                    }
                    $saved_entry = is_array( $result['saved_entry'] ?? null ) ? $result['saved_entry'] : [];
                    if ( ! isset( $updated_pages[$source_id] ) || empty( $saved_entry['id'] ) ) {
                        throw new \RuntimeException( 'mapping_failed' );
                    }
                    $saved_entry_id = (string) $saved_entry['id'];
                    $receiver_hash = (string) ( $operation_receiver_hashes[$result_index] ?? '' );
                    if ( $receiver_hash === '' ) {
                        throw new \RuntimeException( 'mapping_failed' );
                    }
                    $updated_pages[$source_id]['entry_id'] = $saved_entry_id;
                    $updated_pages[$source_id]['receiver_hash'] = $receiver_hash;
                }

                TinyMashLockedJsonFile::mutate(
                    $this->store_filename,
                    function( array $store ) use ( $connection, $bundle, $plan, $plan_id, &$updated_pages ) : array {
                        $store = $this->normalizeStore( $store );
                        $connection_id = (string) $connection['id'];
                        if ( ! isset( $store['connections'][$connection_id] ) ) {
                            throw new \RuntimeException( 'connection_missing' );
                        }
                        $this->assertCredentialIsCurrent( $connection, $store['connections'][$connection_id] );
                        if ( empty( $store['connections'][$connection_id]['enabled'] ) ) {
                            throw new \RuntimeException( 'connection_unavailable' );
                        }
                        $store['connections'][$connection_id]['collection_id'] = (string) $bundle['collection_id'];
                        $store['connections'][$connection_id]['pages'] = $updated_pages;
                        $store['connections'][$connection_id]['plans'] = [];
                        $store['connections'][$connection_id]['last_sync_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $store['connections'][$connection_id]['updated_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                        $history = is_array( $store['connections'][$connection_id]['history'] ?? null ) ? $store['connections'][$connection_id]['history'] : [];
                        array_unshift(
                            $history,
                            [
                                'applied_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                                'bundle_digest' => (string) $bundle['digest'],
                                'counts' => is_array( $plan['counts'] ?? null ) ? $plan['counts'] : [],
                            ]
                        );
                        $store['connections'][$connection_id]['history'] = array_slice( $history, 0, 30 );
                        return( $store );
                    },
                    $this->defaultStore()
                );
            };

            $expected_fingerprint = (string) $plan['state_fingerprint'];
            $results = $this->content_repository->applyEditorEntryBatchAtomically(
                $operations,
                function() use ( $expected_fingerprint ) : void {
                    if ( ! hash_equals( $expected_fingerprint, $this->computeStateFingerprint() ) ) {
                        throw new \RuntimeException( 'receiver_changed' );
                    }
                },
                $state_writer
            );

            if ( $this->lifecycle_handler instanceof \Closure ) {
                foreach ( $results as $result ) {
                    if ( ! is_array( $result ) ) {
                        continue;
                    }
                    $previous_entry = is_array( $result['previous_entry'] ?? null ) ? $result['previous_entry'] : null;
                    $saved_entry = (string) ( $result['action'] ?? '' ) === 'save' && is_array( $result['saved_entry'] ?? null )
                        ? $this->content_repository->getAccessibleEntryById( (string) ( $result['saved_entry']['id'] ?? '' ) )
                        : null;
                    try {
                        ( $this->lifecycle_handler )(
                            $previous_entry,
                            $saved_entry,
                            [ 'trigger' => 'markdown_sync', 'actor_username' => 'markdown-sync' ]
                        );
                    } catch ( \Throwable $exception ) {
                        error_log( 'Markdown Sync lifecycle handler failed (' . $exception->getMessage() . ')' );
                    }
                }
            }

            $public_plan = $this->publicPlan( $plan );
            $public_plan['applied'] = true;
            $public_plan['saved'] = count( array_filter( $results, static fn( mixed $result ) : bool => is_array( $result ) && (string) ( $result['action'] ?? '' ) === 'save' ) );
            $public_plan['trashed'] = count( array_filter( $results, static fn( mixed $result ) : bool => is_array( $result ) && (string) ( $result['action'] ?? '' ) === 'trash' ) );
            return( $public_plan );
        } ) );
    }

    protected function buildPlan( array $connection, array $bundle, bool $prune ) : array {
        $target = $this->getValidTarget( (string) ( $connection['target_entry_id'] ?? '' ) );
        if ( ! is_array( $target ) ) {
            throw new \RuntimeException( 'target_unavailable' );
        }
        if ( $this->entryIsOwnedByAnotherConnection( (string) $connection['id'], (string) $target['id'] ) ) {
            throw new \RuntimeException( 'target_owned' );
        }
        $bound_collection = trim( (string) ( $connection['collection_id'] ?? '' ) );
        if ( $bound_collection !== '' && $bound_collection !== (string) $bundle['collection_id'] ) {
            throw new \RuntimeException( 'collection_mismatch' );
        }

        $current_by_id = [];
        $current_by_path = [];
        $reserved_storage_slugs = [];
        foreach ( $this->content_repository->getEditorEntryOptions() as $option ) {
            if ( ! is_array( $option ) || (string) ( $option['type'] ?? '' ) !== 'page' || (string) ( $option['scope'] ?? '' ) !== 'root' ) {
                continue;
            }
            $entry = $this->content_repository->getAccessibleEntryById( (string) $option['id'] );
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $current_by_id[(string) $entry['id']] = $entry;
            $current_by_path[(string) $entry['path']] = $entry;
            $storage_slug = (string) ( $entry['storage_slug'] ?? '' );
            $storage_slug = $storage_slug !== '' ? $storage_slug : (string) $entry['slug'];
            $reserved_storage_slugs[$storage_slug] = (string) $entry['id'];
        }

        $mappings = is_array( $connection['pages'] ?? null ) ? $connection['pages'] : [];
        $bundle_root_source_id = '';
        foreach ( $bundle['pages'] as $page ) {
            if ( is_array( $page ) && (string) ( $page['relative_path'] ?? '' ) === '' ) {
                $bundle_root_source_id = (string) ( $page['id'] ?? '' );
                break;
            }
        }
        foreach ( $mappings as $mapped_source_id => $mapping ) {
            if (
                is_array( $mapping ) &&
                (string) ( $mapping['entry_id'] ?? '' ) === (string) $target['id'] &&
                (string) $mapped_source_id !== $bundle_root_source_id
            ) {
                throw new \RuntimeException( 'collection_root_mismatch' );
            }
        }
        $managed_entry_ids = [];
        foreach ( $mappings as $mapping ) {
            if ( is_array( $mapping ) && ! empty( $mapping['entry_id'] ) ) {
                $managed_entry_ids[(string) $mapping['entry_id']] = true;
            }
        }
        $managed_entry_ids[(string) $target['id']] = true;

        $desired_storage_slugs = [];
        foreach ( $bundle['pages'] as $page ) {
            if ( ! is_array( $page ) ) {
                continue;
            }
            $source_id = (string) $page['id'];
            $mapping = is_array( $mappings[$source_id] ?? null ) ? $mappings[$source_id] : [];
            $mapped_entry_id = (string) ( $mapping['entry_id'] ?? '' );
            $mapped_entry = $mapped_entry_id !== '' && is_array( $current_by_id[$mapped_entry_id] ?? null ) ? $current_by_id[$mapped_entry_id] : null;
            if ( (string) $page['relative_path'] === '' ) {
                $storage_slug = (string) ( $target['storage_slug'] ?? '' );
                $desired_storage_slugs[$source_id] = $storage_slug !== '' ? $storage_slug : (string) $target['slug'];
                continue;
            }
            if ( is_array( $mapped_entry ) ) {
                $storage_slug = (string) ( $mapped_entry['storage_slug'] ?? '' );
                $desired_storage_slugs[$source_id] = $storage_slug !== '' ? $storage_slug : (string) $mapped_entry['slug'];
                continue;
            }

            $storage_slug = (string) $page['slug'];
            if ( isset( $reserved_storage_slugs[$storage_slug] ) ) {
                $storage_slug = $this->buildAvailableStorageSlug( $target, $page, $reserved_storage_slugs );
            }
            $desired_storage_slugs[$source_id] = $storage_slug;
            $reserved_storage_slugs[$storage_slug] = 'source:' . $source_id;
        }

        $items = [];
        $seen_source_ids = [];
        foreach ( $bundle['pages'] as $page ) {
            $source_id = (string) $page['id'];
            $seen_source_ids[$source_id] = true;
            $mapping = is_array( $mappings[$source_id] ?? null ) ? $mappings[$source_id] : [];
            $is_root = (string) $page['relative_path'] === '';
            $entry_id = $is_root ? (string) $target['id'] : (string) ( $mapping['entry_id'] ?? '' );
            $current = $entry_id !== '' && isset( $current_by_id[$entry_id] ) ? $current_by_id[$entry_id] : null;
            $desired_slug = $is_root ? (string) $target['slug'] : (string) $page['slug'];
            $desired_storage_slug = (string) ( $desired_storage_slugs[$source_id] ?? $desired_slug );
            $desired_parent_slug = $is_root ? (string) ( $target['parent_slug'] ?? '' ) : $this->desiredParentStorageSlug( $page, $desired_storage_slugs );
            $desired_path = trim( (string) ( $target['path'] ?? '' ) . '/' . (string) $page['relative_path'], '/' );
            $desired_page = $page;
            $desired_page['markdown'] = $this->collection_service->rewritePageLinks(
                $page,
                $bundle,
                (string) ( $target['path'] ?? '' ),
                $this->public_base_path
            );
            if ( $is_root ) {
                $desired_page['order'] = (int) ( $target['sort_order'] ?? 0 );
            }
            $action = 'unchanged';
            $reason = '';

            if ( ! $is_root && $entry_id !== '' && ! is_array( $current ) ) {
                $action = 'conflict';
                $reason = 'mapped_entry_missing';
            } elseif ( ! $is_root && isset( $current_by_path[$desired_path] ) && (string) $current_by_path[$desired_path]['id'] !== $entry_id ) {
                $collision_id = (string) $current_by_path[$desired_path]['id'];
                $action = 'conflict';
                $reason = isset( $managed_entry_ids[$collision_id] ) ? 'managed_path_collision' : 'unmanaged_path_collision';
            } elseif ( is_array( $current ) ) {
                $current_hash = $this->computeReceiverHash( $current );
                $mapped_receiver_hash = (string) ( $mapping['receiver_hash'] ?? '' );
                $mapped_source_hash = (string) ( $mapping['source_hash'] ?? '' );
                $editor_entry = $this->buildEditorEntry( $desired_page, $current, $desired_slug, $desired_storage_slug, $desired_parent_slug );
                $desired_hash = $this->computeEditorReceiverHash( $editor_entry, (string) $page['status'] );
                if ( hash_equals( $current_hash, $desired_hash ) || $this->managedReceiverFieldsAreEquivalent( $current, $editor_entry, (string) $page['status'] ) ) {
                    $action = 'unchanged';
                } elseif ( ! $is_root && $mapped_receiver_hash !== '' && ! hash_equals( $mapped_receiver_hash, $current_hash ) && $mapped_source_hash !== (string) $page['source_hash'] ) {
                    $action = 'conflict';
                    $reason = 'both_changed';
                } elseif ( $mapped_receiver_hash !== '' && ! hash_equals( $mapped_receiver_hash, $current_hash ) ) {
                    $action = 'conflict';
                    $reason = 'receiver_changed';
                } elseif (
                    (string) ( $current['slug'] ?? '' ) !== $desired_slug ||
                    (string) ( $current['storage_slug'] ?? '' ) !== ( $desired_storage_slug !== $desired_slug ? $desired_storage_slug : '' ) ||
                    (string) ( $current['parent_slug'] ?? '' ) !== $desired_parent_slug
                ) {
                    $action = 'move';
                } else {
                    $action = 'update';
                }
            } else {
                $editor_entry = $this->buildEditorEntry( $desired_page, [], $desired_slug, $desired_storage_slug, $desired_parent_slug );
                $action = 'create';
            }

            $items[] = [
                'source_id' => $source_id,
                'relative_path' => (string) $page['relative_path'],
                'entry_id' => $entry_id,
                'action' => $action,
                'reason' => $reason,
                'status' => (string) $page['status'],
                'source_hash' => (string) $page['source_hash'],
                'editor_entry' => $action === 'conflict' ? [] : ( $editor_entry ?? $this->buildEditorEntry( $desired_page, is_array( $current ) ? $current : [], $desired_slug, $desired_storage_slug, $desired_parent_slug ) ),
            ];
            unset( $editor_entry );
        }

        foreach ( $mappings as $source_id => $mapping ) {
            if ( isset( $seen_source_ids[$source_id] ) || ! is_array( $mapping ) ) {
                continue;
            }
            $entry_id = (string) ( $mapping['entry_id'] ?? '' );
            if ( $entry_id === (string) $target['id'] ) {
                continue;
            }
            $current = $current_by_id[$entry_id] ?? null;
            $action = $prune ? 'trash' : 'stale';
            $reason = '';
            if ( ! is_array( $current ) ) {
                $action = 'conflict';
                $reason = 'mapped_entry_missing';
            } elseif ( ! empty( $mapping['receiver_hash'] ) && ! hash_equals( (string) $mapping['receiver_hash'], $this->computeReceiverHash( $current ) ) ) {
                $action = 'conflict';
                $reason = 'receiver_changed';
            }
            $items[] = [
                'source_id' => (string) $source_id,
                'relative_path' => (string) ( $mapping['relative_path'] ?? '' ),
                'entry_id' => $entry_id,
                'action' => $action,
                'reason' => $reason,
                'status' => '',
                'source_hash' => '',
                'editor_entry' => [],
            ];
        }

        $counts = array_fill_keys( [ 'create', 'update', 'move', 'unchanged', 'stale', 'trash', 'conflict' ], 0 );
        foreach ( $items as $item ) {
            $action = (string) $item['action'];
            $counts[$action] = (int) ( $counts[$action] ?? 0 ) + 1;
        }

        return( [
            'collection_id' => (string) $bundle['collection_id'],
            'bundle_digest' => (string) $bundle['digest'],
            'target_entry_id' => (string) $target['id'],
            'target_path' => (string) ( $target['path'] ?? '' ),
            'state_fingerprint' => $this->computeStateFingerprint(),
            'prune' => $prune,
            'counts' => $counts,
            'items' => $items,
        ] );
    }

    protected function buildUpdatedPageMap( array $connection, array $bundle, array $plan ) : array {
        $current_pages = is_array( $connection['pages'] ?? null ) ? $connection['pages'] : [];
        $items_by_source = [];
        foreach ( $plan['items'] as $item ) {
            if ( is_array( $item ) ) {
                $items_by_source[(string) $item['source_id']] = $item;
            }
        }
        foreach ( $bundle['pages'] as $page ) {
            $source_id = (string) $page['id'];
            $item = $items_by_source[$source_id] ?? [];
            if ( (string) ( $item['action'] ?? '' ) === 'conflict' ) {
                continue;
            }
            $entry_id = (string) ( $item['entry_id'] ?? $current_pages[$source_id]['entry_id'] ?? '' );
            $current_pages[$source_id] = [
                'entry_id' => $entry_id,
                'relative_path' => (string) $page['relative_path'],
                'source_hash' => (string) $page['source_hash'],
                'receiver_hash' => (string) ( $current_pages[$source_id]['receiver_hash'] ?? '' ),
            ];
            if ( (string) ( $item['action'] ?? '' ) === 'unchanged' && $entry_id !== '' ) {
                $entry = $this->content_repository->getAccessibleEntryById( $entry_id );
                if ( is_array( $entry ) ) {
                    $current_pages[$source_id]['receiver_hash'] = $this->computeReceiverHash( $entry );
                }
            }
        }
        foreach ( $plan['items'] as $item ) {
            if ( is_array( $item ) && (string) ( $item['action'] ?? '' ) === 'trash' ) {
                unset( $current_pages[(string) $item['source_id']] );
            }
        }
        return( $current_pages );
    }

    protected function buildEditorEntry( array $page, array $current, string $slug, string $storage_slug, string $parent_slug ) : array {
        return( [
            'entry_type' => 'page',
            'scope' => 'root',
            'slug' => $slug,
            'storage_slug' => $storage_slug !== $slug ? $storage_slug : '',
            'title' => (string) $page['title'],
            'summary' => (string) $page['summary'],
            'content' => (string) $page['markdown'],
            'parent_slug' => $parent_slug,
            'sort_order' => (int) $page['order'],
            'show_in_navigation' => array_key_exists( 'show_in_navigation', $current ) ? ! empty( $current['show_in_navigation'] ) : true,
            'tags' => is_array( $current['tags'] ?? null ) ? $current['tags'] : [],
            'plugin_settings' => is_array( $current['plugin_settings'] ?? null ) ? $current['plugin_settings'] : [],
            'seo_title' => (string) ( $current['seo_title'] ?? '' ),
            'seo_description' => (string) ( $current['seo_description'] ?? '' ),
            'seo_social_title' => (string) ( $current['seo_social_title'] ?? '' ),
            'seo_social_description' => (string) ( $current['seo_social_description'] ?? '' ),
            'seo_social_image_media_id' => (string) ( $current['seo_social_image_media_id'] ?? '' ),
            'seo_social_image' => is_array( $current['seo_social_image'] ?? null ) ? $current['seo_social_image'] : [],
            'seo_canonical_url' => (string) ( $current['seo_canonical_url'] ?? '' ),
            'seo_robots' => (string) ( $current['seo_robots'] ?? '' ),
            'seo_exclude_from_sitemap' => ! empty( $current['seo_exclude_from_sitemap'] ),
            'featured_image_media_id' => (string) ( $current['featured_image_media_id'] ?? '' ),
            'featured_image' => is_array( $current['featured_image'] ?? null ) ? $current['featured_image'] : [],
        ] );
    }

    protected function desiredParentStorageSlug( array $page, array $desired_storage_slugs ) : string {
        $parent_source_id = (string) ( $page['parent_source_id'] ?? '' );
        if ( $parent_source_id !== '' && isset( $desired_storage_slugs[$parent_source_id] ) ) {
            return( (string) $desired_storage_slugs[$parent_source_id] );
        }
        throw new \RuntimeException( 'parent_missing' );
    }

    protected function buildAvailableStorageSlug( array $target, array $page, array $reserved_storage_slugs ) : string {
        $target_storage_slug = (string) ( $target['storage_slug'] ?? '' );
        if ( $target_storage_slug === '' ) {
            $target_storage_slug = (string) ( $target['slug'] ?? 'docs' );
        }
        $relative_slug = str_replace( '/', '-', (string) ( $page['relative_path'] ?? $page['slug'] ?? '' ) );
        $base = $this->content_repository->normalizeEditorSlug( $target_storage_slug . '-' . $relative_slug );
        if ( $base === '' ) {
            $base = $this->content_repository->normalizeEditorSlug( 'markdown-' . (string) ( $page['slug'] ?? 'page' ) );
        }
        if ( ! isset( $reserved_storage_slugs[$base] ) ) {
            return( $base );
        }

        $suffix = substr( hash( 'sha256', (string) ( $page['id'] ?? '' ) ), 0, 8 );
        $candidate = $this->content_repository->normalizeEditorSlug( $base . '-' . $suffix );
        $counter = 2;
        while ( isset( $reserved_storage_slugs[$candidate] ) ) {
            $candidate = $this->content_repository->normalizeEditorSlug( $base . '-' . $suffix . '-' . $counter );
            $counter++;
        }
        return( $candidate );
    }

    protected function computeEditorReceiverHash( array $editor_entry, string $status ) : string {
        return( $this->hashReceiverFields( $this->editorReceiverFields( $editor_entry, $status ) ) );
    }

    protected function editorReceiverFields( array $editor_entry, string $status ) : array {
        $fields = [
            'slug' => (string) ( $editor_entry['slug'] ?? '' ),
            'parent_slug' => (string) ( $editor_entry['parent_slug'] ?? '' ),
            'title' => (string) ( $editor_entry['title'] ?? '' ),
            'summary' => (string) ( $editor_entry['summary'] ?? '' ),
            'content' => (string) ( $editor_entry['content'] ?? '' ),
            'status' => $status,
            'sort_order' => (int) ( $editor_entry['sort_order'] ?? 0 ),
        ];
        if ( (string) ( $editor_entry['storage_slug'] ?? '' ) !== '' ) {
            $fields['storage_slug'] = (string) $editor_entry['storage_slug'];
        }
        return( $fields );
    }

    protected function computeReceiverHash( array $entry ) : string {
        return( $this->hashReceiverFields( $this->receiverFields( $entry ) ) );
    }

    protected function receiverFields( array $entry ) : array {
        $fields = [
            'slug' => (string) ( $entry['slug'] ?? '' ),
            'parent_slug' => (string) ( $entry['parent_slug'] ?? '' ),
            'title' => (string) ( $entry['title'] ?? '' ),
            'summary' => (string) ( $entry['summary'] ?? '' ),
            'content' => (string) ( $entry['content'] ?? '' ),
            'status' => (string) ( $entry['status'] ?? '' ),
            'sort_order' => (int) ( $entry['sort_order'] ?? 0 ),
        ];
        if ( (string) ( $entry['storage_slug'] ?? '' ) !== '' ) {
            $fields['storage_slug'] = (string) $entry['storage_slug'];
        }
        return( $fields );
    }

    protected function managedReceiverFieldsAreEquivalent( array $entry, array $editor_entry, string $status ) : bool {
        $current_fields = $this->receiverFields( $entry );
        $desired_fields = $this->editorReceiverFields( $editor_entry, $status );
        $current_content = (string) ( $current_fields['content'] ?? '' );
        $desired_content = (string) ( $desired_fields['content'] ?? '' );
        if ( str_starts_with( $current_content, "\n" ) ) {
            $current_fields['content'] = substr( $current_content, 1 );
        }
        if ( str_starts_with( $desired_content, "\n" ) ) {
            $desired_fields['content'] = substr( $desired_content, 1 );
        }
        return( $current_fields === $desired_fields );
    }

    protected function hashReceiverFields( array $fields ) : string {
        return( hash( 'sha256', json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) ) );
    }

    protected function computeStateFingerprint() : string {
        $entries = [];
        foreach ( $this->content_repository->getEditorEntryOptions() as $option ) {
            if ( ! is_array( $option ) || (string) ( $option['type'] ?? '' ) !== 'page' || (string) ( $option['scope'] ?? '' ) !== 'root' ) {
                continue;
            }
            $entry = $this->content_repository->getAccessibleEntryById( (string) $option['id'] );
            if ( is_array( $entry ) ) {
                $entries[(string) $entry['id']] = $this->computeReceiverHash( $entry );
            }
        }
        ksort( $entries, SORT_STRING );
        return( hash( 'sha256', json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) );
    }

    protected function publicPlan( array $plan ) : array {
        $public = $plan;
        unset( $public['state_fingerprint'] );
        foreach ( $public['items'] as &$item ) {
            if ( is_array( $item ) ) {
                unset( $item['editor_entry'], $item['source_hash'] );
            }
        }
        unset( $item );
        return( $public );
    }

    protected function freshConnection( string $connection_id ) : array {
        $store = $this->readStore();
        $connection = $store['connections'][$connection_id] ?? null;
        if ( ! is_array( $connection ) || empty( $connection['enabled'] ) ) {
            throw new \RuntimeException( 'connection_unavailable' );
        }
        return( $connection );
    }

    protected function assertCredentialIsCurrent( array $authenticated_connection, array $current_connection ) : void {
        $authenticated_hash = (string) ( $authenticated_connection['token_hash'] ?? '' );
        $current_hash = (string) ( $current_connection['token_hash'] ?? '' );
        if (
            $authenticated_hash === '' ||
            $current_hash === '' ||
            (string) ( $authenticated_connection['id'] ?? '' ) !== (string) ( $current_connection['id'] ?? '' ) ||
            ! hash_equals( $authenticated_hash, $current_hash )
        ) {
            throw new \RuntimeException( 'authentication_failed' );
        }
    }

    protected function getValidTarget( string $entry_id ) : ?array {
        $entry = $this->content_repository->getAccessibleEntryById( trim( $entry_id ) );
        if ( ! is_array( $entry ) || (string) ( $entry['type'] ?? '' ) !== 'page' || (string) ( $entry['scope'] ?? '' ) !== 'root' ) {
            return( null );
        }
        return( $entry );
    }

    protected function entryIsOwnedByAnotherConnection( string $connection_id, string $entry_id ) : bool {
        $store = $this->readStore();
        foreach ( $store['connections'] as $candidate_id => $candidate ) {
            if ( (string) $candidate_id === $connection_id || ! is_array( $candidate ) ) {
                continue;
            }
            if ( (string) ( $candidate['target_entry_id'] ?? '' ) === $entry_id ) {
                return( true );
            }
            foreach ( is_array( $candidate['pages'] ?? null ) ? $candidate['pages'] : [] as $mapping ) {
                if ( is_array( $mapping ) && (string) ( $mapping['entry_id'] ?? '' ) === $entry_id ) {
                    return( true );
                }
            }
        }
        return( false );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::readStrict( $this->store_filename, $this->defaultStore() ) ) );
    }

    protected function normalizeStore( array $store ) : array {
        return( [
            'version' => self::STORE_VERSION,
            'connections' => is_array( $store['connections'] ?? null ) ? $store['connections'] : [],
            'auth_failures' => is_array( $store['auth_failures'] ?? null ) ? $store['auth_failures'] : [],
        ] );
    }

    protected function defaultStore() : array {
        return( [ 'version' => self::STORE_VERSION, 'connections' => [], 'auth_failures' => [] ] );
    }

    protected function assertAuthenticationAllowed( string $failure_key ) : void {
        $store = $this->readStore();
        $record = is_array( $store['auth_failures'][$failure_key] ?? null ) ? $store['auth_failures'][$failure_key] : [];
        if ( (int) ( $record['blocked_until'] ?? 0 ) > time() ) {
            throw new \RuntimeException( 'authentication_limited' );
        }
    }

    protected function recordAuthenticationFailure( string $failure_key ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $failure_key ) : array {
                $store = $this->normalizeStore( $store );
                $now = time();
                $record = is_array( $store['auth_failures'][$failure_key] ?? null ) ? $store['auth_failures'][$failure_key] : [];
                if ( (int) ( $record['window_started_at'] ?? 0 ) < $now - self::AUTH_FAILURE_WINDOW_SECONDS ) {
                    $record = [ 'window_started_at' => $now, 'count' => 0, 'blocked_until' => 0 ];
                }
                $record['count'] = (int) ( $record['count'] ?? 0 ) + 1;
                if ( $record['count'] >= self::AUTH_FAILURE_LIMIT ) {
                    $record['blocked_until'] = $now + self::AUTH_FAILURE_WINDOW_SECONDS;
                }
                $store['auth_failures'][$failure_key] = $record;
                if ( count( $store['auth_failures'] ) > 200 ) {
                    uasort( $store['auth_failures'], static fn( array $left, array $right ) : int => (int) ( $right['window_started_at'] ?? 0 ) <=> (int) ( $left['window_started_at'] ?? 0 ) );
                    $store['auth_failures'] = array_slice( $store['auth_failures'], 0, 200, true );
                }
                return( $store );
            },
            $this->defaultStore()
        );
    }

    protected function recordSuccessfulAuthentication( string $connection_id, string $failure_key ) : void {
        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $connection_id, $failure_key ) : array {
                $store = $this->normalizeStore( $store );
                unset( $store['auth_failures'][$failure_key] );
                if ( isset( $store['connections'][$connection_id] ) ) {
                    $store['connections'][$connection_id]['last_used_at_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                }
                return( $store );
            },
            $this->defaultStore()
        );
    }

    protected function withApplyLock( callable $callback ) : mixed {
        $directory = dirname( $this->store_filename );
        if ( ! is_dir( $directory ) && ! @ mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create Markdown Sync storage.' );
        }
        $handle = @ fopen( $directory . DIRECTORY_SEPARATOR . '.apply.lock', 'c+' );
        if ( $handle === false ) {
            throw new \RuntimeException( 'Unable to open the Markdown Sync lock.' );
        }
        try {
            if ( ! @ flock( $handle, LOCK_EX ) ) {
                throw new \RuntimeException( 'Unable to lock Markdown Sync.' );
            }
            return( $callback() );
        } finally {
            @ flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    protected function generateSecret() : string {
        return( rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ) );
    }

    protected function formatToken( string $connection_id, string $secret ) : string {
        return( 'tms1.' . $connection_id . '.' . $secret );
    }
}
