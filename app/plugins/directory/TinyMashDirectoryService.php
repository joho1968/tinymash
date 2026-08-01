<?php

use app\classes\TinyMashLockedJsonFile;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashUserRepository;

class TinyMashDirectoryService {

    protected string $directories_filename;
    protected string $items_directory;
    protected ?TinyMashMarkdownRenderer $markdown_renderer;
    protected ?TinyMashMediaService $media_service;
    protected ?TinyMashUserRepository $user_repository;
    protected ?\Closure $template_renderer = null;

    public function __construct( string $directories_filename, string $items_directory, ?TinyMashMarkdownRenderer $markdown_renderer = null, ?TinyMashMediaService $media_service = null, ?TinyMashUserRepository $user_repository = null ) {
        $this->directories_filename = $directories_filename;
        $this->items_directory = rtrim( $items_directory, DIRECTORY_SEPARATOR );
        $this->markdown_renderer = $markdown_renderer;
        $this->media_service = $media_service;
        $this->user_repository = $user_repository;
    }

    public function setTemplateRenderer( callable $renderer ) : void {
        $this->template_renderer = \Closure::fromCallable( $renderer );
    }

    public function listTypes( bool $include_disabled = true ) : array {
        $types = $this->getBuiltInTypes();

        $records = array_values( $types );
        $records = array_values(
            array_filter(
                $records,
                static fn( array $type ) : bool => $include_disabled || ! empty( $type['enabled'] )
            )
        );
        usort(
            $records,
            static function( array $left, array $right ) : int {
                if ( ! empty( $left['built_in'] ) !== ! empty( $right['built_in'] ) ) {
                    return( ! empty( $left['built_in'] ) ? -1 : 1 );
                }
                return( strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) ) );
            }
        );

        return( $records );
    }

    public function getType( string $type_key ) : array {
        $type_key = $this->normalizeKey( $type_key );
        if ( $type_key === '' ) {
            return( [] );
        }
        foreach ( $this->listTypes() as $type ) {
            if ( (string) ( $type['key'] ?? '' ) === $type_key ) {
                return( $type );
            }
        }
        return( [] );
    }

    public function listDirectories( ?string $scope = null, ?string $author_slug = null, bool $public_only = false ) : array {
        $scope = $scope !== null ? $this->normalizeScope( $scope ) : null;
        $author_slug = $author_slug !== null ? $this->normalizeAuthorSlug( $author_slug ) : null;
        $directories = [];
        foreach ( $this->readDirectoriesStore()['directories'] as $directory ) {
            if ( ! is_array( $directory ) ) {
                continue;
            }
            $directory = $this->normalizeDirectoryRecord( $directory );
            if ( $directory['id'] === '' || $directory['type_key'] === '' || empty( $this->getType( $directory['type_key'] ) ) ) {
                continue;
            }
            if ( $scope !== null && $directory['scope'] !== $scope ) {
                continue;
            }
            if ( $author_slug !== null && $directory['author_slug'] !== $author_slug ) {
                continue;
            }
            if ( $public_only && empty( $directory['enabled'] ) ) {
                continue;
            }
            $directory['type'] = $this->getType( $directory['type_key'] );
            $directory['item_count'] = count( $this->listItems( $directory['id'], $public_only ) );
            $directories[] = $directory;
        }

        usort(
            $directories,
            static function( array $left, array $right ) : int {
                $left_order = (int) ( $left['sort_order'] ?? 0 );
                $right_order = (int) ( $right['sort_order'] ?? 0 );
                if ( $left_order !== $right_order ) {
                    return( $left_order <=> $right_order );
                }
                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        return( $directories );
    }

    public function listAccessibleDirectories( bool $is_superadmin, string $current_username ) : array {
        return( $is_superadmin ? $this->listDirectories() : $this->listDirectories( 'author', $current_username ) );
    }

    public function getDirectoryByIdOrAlias( string $identifier, bool $public_only = false ) : array {
        $identifier = $this->normalizeIdentifier( $identifier );
        if ( $identifier === '' ) {
            return( [] );
        }
        foreach ( $this->listDirectories( null, null, $public_only ) as $directory ) {
            if ( (string) ( $directory['id'] ?? '' ) === $identifier || (string) ( $directory['alias'] ?? '' ) === $identifier ) {
                return( $directory );
            }
        }
        return( [] );
    }

    public function saveDirectory( array $data, bool $is_superadmin, string $current_username ) : array {
        $current_username = $this->normalizeAuthorSlug( $current_username );
        $submitted_id = $this->normalizeId( (string) ( $data['id'] ?? '' ), 'dir' );
        $type_key = $this->normalizeKey( (string) ( $data['type_key'] ?? '' ) );
        $type = $this->getType( $type_key );
        if ( empty( $type ) || empty( $type['enabled'] ) ) {
            throw new \InvalidArgumentException( 'type' );
        }

        $title = mb_trim( (string) ( $data['title'] ?? '' ) );
        if ( $title === '' ) {
            throw new \InvalidArgumentException( 'title' );
        }
        $alias = $this->normalizeIdentifier( (string) ( $data['alias'] ?? '' ) );
        if ( $alias === '' ) {
            $alias = $this->slugify( $title );
        }
        if ( $alias === '' ) {
            throw new \InvalidArgumentException( 'alias' );
        }

        $scope = $is_superadmin ? $this->normalizeScope( (string) ( $data['scope'] ?? 'root' ) ) : 'author';
        $author_slug = $scope === 'author' ? $this->normalizeAuthorSlug( (string) ( $data['author_slug'] ?? $current_username ) ) : '';
        if ( $scope === 'author' ) {
            if ( ! $is_superadmin ) {
                $author_slug = $current_username;
            }
            if ( $author_slug === '' || ( $this->user_repository instanceof TinyMashUserRepository && ! is_array( $this->user_repository->getUserByAuthorSlug( $author_slug ) ) ) ) {
                throw new \InvalidArgumentException( 'author' );
            }
            if ( empty( $type['allow_authors'] ) && ! $is_superadmin ) {
                throw new \InvalidArgumentException( 'permission' );
            }
        }

        $now = gmdate( 'c' );
        $saved = null;
        TinyMashLockedJsonFile::mutate(
            $this->directories_filename,
            function( array $store ) use ( $submitted_id, $type_key, $title, $alias, $scope, $author_slug, $data, $now, &$saved, $is_superadmin, $current_username ) : array {
                $store = $this->normalizeDirectoriesStore( $store );
                $directories = [];
                $existing = [];
                foreach ( $store['directories'] as $directory ) {
                    if ( ! is_array( $directory ) ) {
                        continue;
                    }
                    $directory = $this->normalizeDirectoryRecord( $directory );
                    if ( $directory['id'] === $submitted_id && $submitted_id !== '' ) {
                        $existing = $directory;
                    }
                    $directories[] = $directory;
                }

                if ( ! empty( $existing ) && ! $this->canManageDirectory( $existing, $is_superadmin, $current_username ) ) {
                    throw new \InvalidArgumentException( 'permission' );
                }

                foreach ( $directories as $directory ) {
                    if ( $directory['id'] === $submitted_id ) {
                        continue;
                    }
                    if ( $directory['alias'] === $alias ) {
                        throw new \InvalidArgumentException( 'alias_conflict' );
                    }
                }

                $record = array_merge(
                    [
                        'id' => $submitted_id !== '' ? $submitted_id : $this->buildId( 'dir' ),
                        'created_at_utc' => $now,
                    ],
                    $existing
                );
                $record['type_key'] = $type_key;
                $record['title'] = $title;
                $record['alias'] = $alias;
                $record['scope'] = $scope;
                $record['author_slug'] = $author_slug;
                $record['enabled'] = ! empty( $data['enabled'] );
                $record['intro_markdown'] = mb_trim( (string) ( $data['intro_markdown'] ?? '' ) );
                $record['default_view'] = $this->normalizeView( (string) ( $data['default_view'] ?? ( $existing['default_view'] ?? '' ) ) );
                $record['sort_order'] = max( 0, (int) ( $data['sort_order'] ?? ( $existing['sort_order'] ?? 0 ) ) );
                $record['updated_at_utc'] = $now;
                $record = $this->normalizeDirectoryRecord( $record );

                $updated = [];
                $replaced = false;
                foreach ( $directories as $directory ) {
                    if ( $directory['id'] === $record['id'] ) {
                        $updated[] = $record;
                        $replaced = true;
                    } else {
                        $updated[] = $directory;
                    }
                }
                if ( ! $replaced ) {
                    $updated[] = $record;
                }
                $store['directories'] = $updated;
                $saved = $record;
                return( $store );
            },
            $this->defaultDirectoriesStore()
        );

        return( is_array( $saved ) ? $saved : [] );
    }

    public function listItems( string $directory_id, bool $public_only = false ) : array {
        $directory_id = $this->normalizeId( $directory_id, 'dir' );
        if ( $directory_id === '' ) {
            return( [] );
        }
        $items = [];
        foreach ( $this->readItemsStore( $directory_id )['items'] as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $item = $this->normalizeItemRecord( $item );
            if ( $item['id'] === '' ) {
                continue;
            }
            if ( $public_only && empty( $item['enabled'] ) ) {
                continue;
            }
            $items[] = $item;
        }
        return( $this->sortItems( $items ) );
    }

    public function saveItem( array $data, bool $is_superadmin, string $current_username ) : array {
        $directory_id = $this->normalizeId( (string) ( $data['directory_id'] ?? '' ), 'dir' );
        $directory = $this->getDirectoryByIdOrAlias( $directory_id );
        if ( empty( $directory ) || ! $this->canManageDirectory( $directory, $is_superadmin, $current_username ) ) {
            throw new \InvalidArgumentException( 'directory' );
        }
        $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : $this->getType( (string) ( $directory['type_key'] ?? '' ) );
        if ( empty( $type ) ) {
            throw new \InvalidArgumentException( 'type' );
        }

        $submitted_id = $this->normalizeId( (string) ( $data['id'] ?? '' ), 'item' );
        $title = mb_trim( (string) ( $data['title'] ?? '' ) );
        if ( $title === '' ) {
            throw new \InvalidArgumentException( 'title' );
        }
        $slug = $this->slugify( (string) ( $data['slug'] ?? '' ) );
        if ( $slug === '' ) {
            $slug = $this->slugify( $title );
        }
        if ( $slug === '' ) {
            throw new \InvalidArgumentException( 'slug' );
        }
        $parent_id = $this->normalizeId( (string) ( $data['parent_id'] ?? '' ), 'item' );
        if ( $submitted_id !== '' && $parent_id === $submitted_id ) {
            throw new \InvalidArgumentException( 'parent' );
        }

        $fields = $this->normalizeSubmittedFields( is_array( $data['fields'] ?? null ) ? $data['fields'] : [], $type );
        $now = gmdate( 'c' );
        $saved = null;

        TinyMashLockedJsonFile::mutate(
            $this->getItemsFilename( $directory_id ),
            function( array $store ) use ( $submitted_id, $title, $slug, $parent_id, $fields, $data, $now, &$saved ) : array {
                $store = $this->normalizeItemsStore( $store );
                $items = [];
                $existing = [];
                foreach ( $store['items'] as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $item = $this->normalizeItemRecord( $item );
                    if ( $submitted_id !== '' && $item['id'] === $submitted_id ) {
                        $existing = $item;
                    }
                    $items[] = $item;
                }
                if ( $parent_id !== '' && ! $this->itemExists( $items, $parent_id ) ) {
                    throw new \InvalidArgumentException( 'parent' );
                }
                foreach ( $items as $item ) {
                    if ( $item['id'] === $submitted_id ) {
                        continue;
                    }
                    if ( $item['parent_id'] === $parent_id && $item['slug'] === $slug ) {
                        throw new \InvalidArgumentException( 'slug_conflict' );
                    }
                }

                $record = array_merge(
                    [
                        'id' => $submitted_id !== '' ? $submitted_id : $this->buildId( 'item' ),
                        'created_at_utc' => $now,
                    ],
                    $existing
                );
                $record['title'] = $title;
                $record['slug'] = $slug;
                $record['parent_id'] = $parent_id;
                $record['enabled'] = ! empty( $data['enabled'] );
                $record['sort_order'] = max( 0, (int) ( $data['sort_order'] ?? ( $existing['sort_order'] ?? 0 ) ) );
                $record['fields'] = $fields;
                $record['updated_at_utc'] = $now;
                $record = $this->normalizeItemRecord( $record );

                $updated = [];
                $replaced = false;
                foreach ( $items as $item ) {
                    if ( $item['id'] === $record['id'] ) {
                        $updated[] = $record;
                        $replaced = true;
                    } else {
                        $updated[] = $item;
                    }
                }
                if ( ! $replaced ) {
                    $updated[] = $record;
                }
                $store['items'] = $updated;
                $saved = $record;
                return( $store );
            },
            $this->defaultItemsStore()
        );

        return( is_array( $saved ) ? $saved : [] );
    }

    public function duplicateItem( string $directory_id, string $item_id, bool $is_superadmin, string $current_username ) : array {
        $directory_id = $this->normalizeId( $directory_id, 'dir' );
        $item_id = $this->normalizeId( $item_id, 'item' );
        $directory = $this->getDirectoryByIdOrAlias( $directory_id );
        if ( $directory_id === '' || $item_id === '' || empty( $directory ) || ! $this->canManageDirectory( $directory, $is_superadmin, $current_username ) ) {
            throw new \InvalidArgumentException( 'directory' );
        }

        $source_item = [];
        $items = $this->listItems( $directory_id );
        foreach ( $items as $item ) {
            if ( (string) ( $item['id'] ?? '' ) === $item_id ) {
                $source_item = $item;
                break;
            }
        }
        if ( empty( $source_item ) ) {
            throw new \InvalidArgumentException( 'item' );
        }

        $base_title = mb_trim( 'Copy of ' . (string) ( $source_item['title'] ?? 'Item' ) );
        $parent_id = (string) ( $source_item['parent_id'] ?? '' );
        $title = $base_title;
        $slug = $this->slugify( $title );
        for ( $counter = 2; $counter <= 100; $counter++ ) {
            if ( $slug !== '' && ! $this->itemSlugExists( $items, $parent_id, $slug ) ) {
                break;
            }
            $title = $base_title . ' ' . $counter;
            $slug = $this->slugify( $title );
        }
        if ( $slug === '' || $this->itemSlugExists( $items, $parent_id, $slug ) ) {
            throw new \InvalidArgumentException( 'slug_conflict' );
        }

        return(
            $this->saveItem(
                [
                    'directory_id' => $directory_id,
                    'title' => $title,
                    'slug' => $slug,
                    'parent_id' => $parent_id,
                    'enabled' => ! empty( $source_item['enabled'] ) ? '1' : '',
                    'sort_order' => (string) ( (int) ( $source_item['sort_order'] ?? 0 ) + 1 ),
                    'fields' => is_array( $source_item['fields'] ?? null ) ? $source_item['fields'] : [],
                ],
                $is_superadmin,
                $current_username
            )
        );
    }

    public function deleteItem( string $directory_id, string $item_id, bool $is_superadmin, string $current_username ) : bool {
        $directory_id = $this->normalizeId( $directory_id, 'dir' );
        $item_id = $this->normalizeId( $item_id, 'item' );
        $directory = $this->getDirectoryByIdOrAlias( $directory_id );
        if ( $directory_id === '' || $item_id === '' || empty( $directory ) || ! $this->canManageDirectory( $directory, $is_superadmin, $current_username ) ) {
            return( false );
        }
        $deleted = false;
        TinyMashLockedJsonFile::mutate(
            $this->getItemsFilename( $directory_id ),
            function( array $store ) use ( $item_id, &$deleted ) : array {
                $store = $this->normalizeItemsStore( $store );
                $descendants = $this->collectDescendantIds( $store['items'], $item_id );
                $descendants[$item_id] = true;
                $items = [];
                foreach ( $store['items'] as $item ) {
                    $item = $this->normalizeItemRecord( is_array( $item ) ? $item : [] );
                    if ( isset( $descendants[$item['id']] ) ) {
                        $deleted = true;
                        continue;
                    }
                    $items[] = $item;
                }
                $store['items'] = $items;
                return( $store );
            },
            $this->defaultItemsStore()
        );

        return( $deleted );
    }

    public function buildDirectoryViewModel( array $attributes = [] ) : array {
        $identifier = $this->normalizeIdentifier( (string) ( $attributes['id'] ?? $attributes['slug'] ?? '' ) );
        if ( $identifier === '' ) {
            return( [] );
        }
        $directory = $this->getDirectoryByIdOrAlias( $identifier, true );
        if ( empty( $directory ) ) {
            return( [] );
        }
        $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : $this->getType( (string) ( $directory['type_key'] ?? '' ) );
        if ( empty( $type ) ) {
            return( [] );
        }
        $items = $this->listItems( (string) $directory['id'], true );
        $view = $this->normalizeView( (string) ( $attributes['view'] ?? $attributes['layout'] ?? $directory['default_view'] ?? $type['default_view'] ?? 'list' ) );
        if ( ! in_array( $view, (array) ( $type['views'] ?? [] ), true ) ) {
            $view = (string) ( $type['default_view'] ?? 'list' );
        }
        $expanded = $this->normalizeBoolean( $attributes['expanded'] ?? false );
        $depth = max( 0, min( 12, (int) ( $attributes['depth'] ?? 0 ) ) );
        $tree = $this->buildItemTree( $items );
        $type_key = (string) ( $type['key'] ?? '' );
        $intro_html = $this->renderMarkdown( (string) ( $directory['intro_markdown'] ?? '' ) );
        $wide_content = $this->isWideContentRequested( $attributes );

        return(
            [
                'identifier' => $identifier,
                'attributes' => $attributes,
                'directory' => $directory,
                'type' => $type,
                'type_key' => $type_key,
                'view' => $view,
                'expanded' => $expanded,
                'depth' => $depth,
                'wide_content' => $wide_content,
                'items' => $this->prepareItemViews( $items, $type ),
                'raw_items' => $items,
                'tree' => $this->prepareTreeNodeViews( $tree, $type ),
                'raw_tree' => $tree,
                'groups' => $this->prepareGroupedItemViews( $items, $type ),
                'intro_html' => $intro_html,
                'empty' => empty( $items ),
                'classes' => [
                    'root' => 'tm-directory tm-directory-type-' . $type_key . ' tm-directory-view-' . $view . ( $wide_content ? ' tm-directory-wide' : '' ),
                ],
                'template_candidates' => $this->buildTemplateCandidates( $type_key, $view ),
            ]
        );
    }

    public function renderDirectoryHtml( array $attributes = [] ) : string {
        $view_model = $this->buildDirectoryViewModel( $attributes );
        if ( empty( $view_model ) ) {
            return( '' );
        }

        if ( $this->template_renderer instanceof \Closure ) {
            try {
                $theme_html = (string) call_user_func( $this->template_renderer, $this->prepareTemplateViewModel( $view_model ) );
                if ( trim( $theme_html ) !== '' ) {
                    return( $theme_html );
                }
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Links theme renderer failed (' . $e->getMessage() . ')' );
            }
        }

        return( $this->renderDirectoryFallbackHtml( $view_model ) );
    }

    protected function prepareTemplateViewModel( array $view_model ) : array {
        unset( $view_model['raw_items'], $view_model['raw_tree'] );
        return( $view_model );
    }

    protected function renderDirectoryFallbackHtml( array $view_model ) : string {
        $directory = is_array( $view_model['directory'] ?? null ) ? $view_model['directory'] : [];
        $type = is_array( $view_model['type'] ?? null ) ? $view_model['type'] : [];
        $items = is_array( $view_model['raw_items'] ?? null ) ? $view_model['raw_items'] : [];
        $tree = is_array( $view_model['raw_tree'] ?? null ) ? $view_model['raw_tree'] : [];
        $view = (string) ( $view_model['view'] ?? 'list' );
        $expanded = ! empty( $view_model['expanded'] );
        $depth = max( 0, min( 12, (int) ( $view_model['depth'] ?? 0 ) ) );

        $root_class = (string) ( $view_model['classes']['root'] ?? '' );
        if ( $root_class === '' ) {
            $root_class = 'tm-directory tm-directory-type-' . (string) ( $type['key'] ?? '' ) . ' tm-directory-view-' . $view;
        }
        $html = '<div class="' . $this->escape( $root_class ) . '" data-tm-directory>';
        $html .= '<div class="tm-directory-header"><h2 class="tm-directory-title">' . $this->escape( (string) ( $directory['title'] ?? '' ) ) . '</h2>';
        $intro = (string) ( $view_model['intro_html'] ?? '' );
        if ( $intro !== '' ) {
            $html .= '<div class="tm-directory-intro">' . $intro . '</div>';
        }
        $html .= '</div>';
        if ( empty( $items ) ) {
            $html .= '<div class="tm-directory-empty">No links are available yet.</div></div>';
            return( $html );
        }

        if ( $view === 'tree' ) {
            $html .= $this->renderLinksTreeView( $items, $type, $expanded, $depth );
        } elseif ( $view === 'cards' ) {
            $html .= $this->renderCardsView( $items, $type, $expanded );
        } elseif ( $view === 'boxes' ) {
            $html .= $this->renderBoxesView( $items, $type, $expanded );
        } else {
            $html .= $this->renderListView( $items, $type, $expanded );
        }

        $html .= '</div>';
        return( $html );
    }

    protected function buildTemplateCandidates( string $type_key, string $view ) : array {
        $type_key = $this->normalizeKey( $type_key );
        $view = $this->normalizeView( $view );
        $candidates = [];
        if ( $type_key !== '' && $view !== '' ) {
            $candidates[] = $type_key . '/' . $view . '.latte';
        }
        if ( $type_key !== '' ) {
            $candidates[] = $type_key . '.latte';
        }
        if ( $view !== '' ) {
            $candidates[] = $view . '.latte';
        }
        $candidates[] = 'default.latte';

        return( array_values( array_unique( $candidates ) ) );
    }

    protected function prepareGroupedItemViews( array $items, array $type ) : array {
        $groups = [];
        $field_key = (string) ( $type['key'] ?? '' ) === 'links' ? 'category' : 'category';
        foreach ( $this->groupItemsByField( $items, $field_key, 'Uncategorized' ) as $label => $group_items ) {
            $groups[] = [
                'label' => (string) $label,
                'items' => $this->prepareItemViews( $group_items, $type ),
                'tree' => $this->prepareTreeNodeViews( $this->buildItemTree( $group_items ), $type ),
                'count' => count( $group_items ),
            ];
        }

        return( $groups );
    }

    protected function prepareTreeNodeViews( array $tree, array $type ) : array {
        $nodes = [];
        foreach ( $tree as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $item = is_array( $node['item'] ?? null ) ? $node['item'] : [];
            $children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
            $nodes[] = [
                'item' => $this->prepareItemView( $item, $type ),
                'children' => $this->prepareTreeNodeViews( $children, $type ),
                'has_children' => ! empty( $children ),
            ];
        }

        return( $nodes );
    }

    protected function prepareItemViews( array $items, array $type ) : array {
        $views = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $views[] = $this->prepareItemView( $item, $type );
            }
        }

        return( $views );
    }

    protected function prepareItemView( array $item, array $type ) : array {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $url = $this->getFirstUrlField( $fields, $type );
        $host = $url !== '' ? mb_trim( (string) parse_url( $url, PHP_URL_HOST ) ) : '';
        if ( $host !== '' ) {
            $host = preg_replace( '/^www\./i', '', $host ) ?? $host;
        }
        $title = (string) ( $item['title'] ?? '' );
        $description_html = $this->renderMarkdown( (string) ( $fields['description'] ?? '' ) );
        $media = $this->resolveItemMedia( $fields, $type );
        $details = $this->prepareItemDetailViews( $item, $type );

        return(
            [
                'id' => (string) ( $item['id'] ?? '' ),
                'slug' => (string) ( $item['slug'] ?? '' ),
                'parent_id' => (string) ( $item['parent_id'] ?? '' ),
                'title' => $title,
                'enabled' => ! empty( $item['enabled'] ),
                'sort_order' => max( 0, (int) ( $item['sort_order'] ?? 0 ) ),
                'url' => $url,
                'host' => $host,
                'summary' => $this->getSummaryText( $fields, $type ),
                'description_html' => $description_html,
                'category' => mb_trim( (string) ( $fields['category'] ?? $fields['group'] ?? '' ) ),
                'media' => $media,
                'image_url' => ! empty( $media ) ? (string) ( $media['thumbnail_url'] ?: ( $media['display_url'] ?: $media['url'] ) ) : '',
                'image_alt' => ! empty( $media ) ? (string) ( $media['alt_text'] ?: $title ) : '',
                'fields' => $this->prepareFieldViews( $fields, $type ),
                'details' => $details,
                'details_id' => 'tm-directory-details-' . (string) ( $item['id'] ?? md5( $title ) ),
            ]
        );
    }

    protected function prepareFieldViews( array $fields, array $type ) : array {
        $prepared = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }
            $field_type = (string) ( $field['type'] ?? 'text' );
            $value = (string) ( $fields[$key] ?? '' );
            $prepared[$key] = [
                'key' => $key,
                'label' => (string) ( $field['label'] ?? $key ),
                'type' => $field_type,
                'value' => $value,
                'text' => $field_type === 'markdown' ? mb_trim( strip_tags( $value ) ) : $value,
                'html' => $field_type === 'markdown' ? $this->renderMarkdown( $value ) : nl2br( $this->escape( $value ) ),
                'url' => in_array( $field_type, [ 'url', 'email' ], true ) && $this->isSafeUrl( $value ) ? $value : '',
            ];
        }

        return( $prepared );
    }

    protected function prepareItemDetailViews( array $item, array $type ) : array {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $details = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            $value = $fields[$key] ?? '';
            $field_type = (string) ( $field['type'] ?? '' );
            if ( $key === '' || trim( (string) $value ) === '' || $field_type === 'media' || in_array( $field_type, [ 'url', 'email' ], true ) ) {
                continue;
            }
            $details[] = [
                'key' => $key,
                'label' => (string) ( $field['label'] ?? $key ),
                'type' => $field_type,
                'value' => (string) $value,
                'html' => $field_type === 'markdown' ? $this->renderMarkdown( (string) $value ) : nl2br( $this->escape( (string) $value ) ),
            ];
        }

        return( $details );
    }

    public function collectMediaUsageReferences() : array {
        $references = [];
        foreach ( $this->listDirectories() as $directory ) {
            $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : [];
            foreach ( $this->listItems( (string) ( $directory['id'] ?? '' ) ) as $item ) {
                foreach ( (array) ( $item['fields'] ?? [] ) as $field_key => $field_value ) {
                    $field = $this->getFieldDefinition( (string) $field_key, $type );
                    if ( (string) ( $field['type'] ?? '' ) !== 'media' ) {
                        continue;
                    }
                    $media_id = trim( (string) $field_value );
                    if ( $media_id === '' ) {
                        continue;
                    }
                    $references[] = [
                        'media_id' => $media_id,
                        'category' => 'plugin',
                        'source' => 'Links',
                        'label' => (string) ( $directory['title'] ?? 'Links' ) . ': ' . (string) ( $item['title'] ?? 'Link' ),
                        'owner_filter_optional' => true,
                    ];
                }
            }
        }
        return( $references );
    }

    public function listSelectableImages( array $owner_usernames = [], int $limit = 500, array $preferred_media_ids = [] ) : array {
        if ( ! $this->media_service instanceof TinyMashMediaService ) {
            return( [] );
        }
        $limit = max( 1, min( 1000, $limit ) );
        $fetch_limit = ! empty( $preferred_media_ids ) ? max( $limit, 500 ) : $limit;
        $images = [];
        foreach ( $this->media_service->listAttachments( $owner_usernames, $fetch_limit ) as $metadata ) {
            $record = is_array( $metadata ) ? $this->normalizeImageRecord( $metadata ) : [];
            if ( ! empty( $record ) ) {
                $images[] = $record;
            }
        }
        usort( $images, static fn( array $left, array $right ) : int => strcmp( (string) $right['created_at_utc'], (string) $left['created_at_utc'] ) );
        if ( ! empty( $preferred_media_ids ) ) {
            $preferred = [];
            foreach ( $preferred_media_ids as $media_id ) {
                $media_id = trim( (string) $media_id );
                if ( $media_id !== '' && ! in_array( $media_id, $preferred, true ) ) {
                    $preferred[] = $media_id;
                }
            }
            if ( ! empty( $preferred ) ) {
                $rank = array_flip( $preferred );
                usort(
                    $images,
                    static function( array $left, array $right ) use ( $rank ) : int {
                        $left_id = (string) ( $left['media_id'] ?? '' );
                        $right_id = (string) ( $right['media_id'] ?? '' );
                        $left_rank = $rank[$left_id] ?? null;
                        $right_rank = $rank[$right_id] ?? null;
                        if ( $left_rank !== null || $right_rank !== null ) {
                            if ( $left_rank === null ) {
                                return( 1 );
                            }
                            if ( $right_rank === null ) {
                                return( -1 );
                            }
                            return( $left_rank <=> $right_rank );
                        }
                        return( strcmp( (string) $right['created_at_utc'], (string) $left['created_at_utc'] ) );
                    }
                );
            }
        }
        return( array_slice( $images, 0, $limit ) );
    }

    public function resolveSelectableImage( string $media_id, array $owner_usernames = [] ) : array {
        if ( ! $this->media_service instanceof TinyMashMediaService ) {
            return( [] );
        }
        $metadata = $this->media_service->getAttachmentMetadataByMediaId( trim( $media_id ), $owner_usernames );
        return( is_array( $metadata ) ? $this->normalizeImageRecord( $metadata ) : [] );
    }

    public function listDirectoryMediaFieldIds( string $directory_id ) : array {
        $directory = $this->getDirectoryByIdOrAlias( $directory_id );
        if ( empty( $directory ) ) {
            return( [] );
        }
        $type = is_array( $directory['type'] ?? null ) ? $directory['type'] : $this->getType( (string) ( $directory['type_key'] ?? '' ) );
        $media_field_keys = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( is_array( $field ) && (string) ( $field['type'] ?? '' ) === 'media' ) {
                $media_field_keys[] = (string) ( $field['key'] ?? '' );
            }
        }
        if ( empty( $media_field_keys ) ) {
            return( [] );
        }
        $items = $this->listItems( (string) ( $directory['id'] ?? '' ) );
        usort(
            $items,
            static function( array $left, array $right ) : int {
                $updated = strcmp( (string) ( $right['updated_at_utc'] ?? '' ), (string) ( $left['updated_at_utc'] ?? '' ) );
                if ( $updated !== 0 ) {
                    return( $updated );
                }
                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );
        $media_ids = [];
        foreach ( $items as $item ) {
            $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
            foreach ( $media_field_keys as $field_key ) {
                $media_id = trim( (string) ( $fields[$field_key] ?? '' ) );
                if ( $media_id !== '' && ! in_array( $media_id, $media_ids, true ) ) {
                    $media_ids[] = $media_id;
                }
            }
        }
        return( $media_ids );
    }

    public function buildSelectableImageRecord( array $metadata ) : array {
        return( $this->normalizeImageRecord( $metadata ) );
    }

    public function canManageDirectory( array $directory, bool $is_superadmin, string $current_username ) : bool {
        if ( $is_superadmin ) {
            return( true );
        }
        return( (string) ( $directory['scope'] ?? '' ) === 'author' && (string) ( $directory['author_slug'] ?? '' ) === $this->normalizeAuthorSlug( $current_username ) );
    }

    public function getBuiltInTypes() : array {
        $types = [
            'links' => [
                'key' => 'links',
                'label' => 'Links',
                'item_label' => 'Link',
                'built_in' => true,
                'enabled' => true,
                'allow_authors' => true,
                'hierarchy' => 'grouped',
                'default_view' => 'boxes',
                'views' => [ 'boxes', 'list', 'cards', 'tree' ],
                'fields' => [
                    [ 'key' => 'url', 'label' => 'URL', 'type' => 'url', 'required' => true ],
                    [ 'key' => 'category', 'label' => 'Category', 'type' => 'text' ],
                    [ 'key' => 'description', 'label' => 'Description', 'type' => 'markdown' ],
                    [ 'key' => 'icon_media_id', 'label' => 'Icon/image', 'type' => 'media' ],
                ],
            ],
        ];

        foreach ( $types as $key => $type ) {
            $types[$key] = $this->normalizeTypeRecord( $type, true );
        }
        return( $types );
    }

    protected function renderListView( array $items, array $type, bool $expanded ) : string {
        return( $this->renderGroupedItemsView( $items, $type, $expanded, 'list' ) );
    }

    protected function renderCardsView( array $items, array $type, bool $expanded ) : string {
        return( $this->renderGroupedItemsView( $items, $type, $expanded, 'card' ) );
    }

    protected function renderBoxesView( array $items, array $type, bool $expanded ) : string {
        return( $this->renderGroupedItemsView( $items, $type, $expanded, 'box' ) );
    }

    protected function renderGroupedItemsView( array $items, array $type, bool $expanded, string $surface ) : string {
        $groups = $this->groupItemsByField( $items, 'category', 'Uncategorized' );
        $html = '<div class="tm-directory-link-groups tm-directory-link-groups-' . $this->escape( $surface ) . '">';
        foreach ( $groups as $group => $group_items ) {
            $html .= '<div class="tm-directory-link-group tm-directory-link-group-' . $this->escape( $surface ) . '"><div class="tm-directory-group-title" role="heading" aria-level="3">' . $this->escape( (string) $group ) . '</div><div class="tm-directory-link-group-items tm-directory-link-group-items-' . $this->escape( $surface ) . '">';
            foreach ( $group_items as $item ) {
                $html .= $this->renderItemCard( $item, $type, $expanded, $surface );
            }
            $html .= '</div></div>';
        }
        return( $html . '</div>' );
    }

    protected function renderLinksTreeView( array $items, array $type, bool $expanded, int $max_depth = 0 ) : string {
        $groups = $this->groupItemsByField( $items, 'category', 'Uncategorized' );
        $html = '<div class="tm-directory-link-tree">';
        foreach ( $groups as $group => $group_items ) {
            $tree = $this->buildItemTree( $group_items );
            $html .= '<details class="tm-directory-link-tree-category"' . ( $expanded ? ' open' : '' ) . '>';
            $html .= '<summary class="tm-directory-link-tree-category-summary"><span class="tm-directory-link-tree-category-title">' . $this->escape( (string) $group ) . '</span></summary>';
            $html .= $this->renderLinksTreeNodes( $tree, $type, $expanded, $max_depth, 1 );
            $html .= '</details>';
        }
        return( $html . '</div>' );
    }

    protected function renderLinksTreeNodes( array $tree, array $type, bool $expanded, int $max_depth, int $current_depth ) : string {
        if ( empty( $tree ) || ( $max_depth > 0 && $current_depth > $max_depth ) ) {
            return( '' );
        }
        $html = '<ol class="tm-directory-link-tree-items">';
        foreach ( $tree as $node ) {
            $item = is_array( $node['item'] ?? null ) ? $node['item'] : [];
            $children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
            $has_children = ! empty( $children ) && ( $max_depth === 0 || $current_depth < $max_depth );
            $html .= '<li class="tm-directory-link-tree-node">';
            if ( $has_children ) {
                $html .= '<details class="tm-directory-link-tree-branch"' . ( $expanded ? ' open' : '' ) . '>';
                $html .= '<summary class="tm-directory-link-tree-branch-summary">' . $this->renderLinkTreeLine( $item, $type, true ) . '</summary>';
                $description = $this->renderLinkTreeDescription( $item );
                if ( $description !== '' ) {
                    $html .= '<div class="tm-directory-link-tree-branch-description"><span class="tm-directory-link-tree-expander-spacer" aria-hidden="true"></span>' . $description . '</div>';
                }
                $html .= $this->renderLinksTreeNodes( $children, $type, $expanded, $max_depth, $current_depth + 1 );
                $html .= '</details>';
            } else {
                $description = $this->renderLinkTreeDescription( $item );
                $html .= '<div class="tm-directory-link-tree-row tm-directory-link-tree-leaf"><span class="tm-directory-link-tree-expander-spacer" aria-hidden="true"></span><div class="tm-directory-link-tree-content">' . $this->renderLinkTreeLine( $item, $type, true ) . $description . '</div></div>';
            }
            $html .= '</li>';
        }
        return( $html . '</ol>' );
    }

    protected function renderLinkTreeLine( array $item, array $type, bool $title_is_link ) : string {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $url = $this->getFirstUrlField( $fields, $type );
        $title = (string) ( $item['title'] ?? '' );
        $host = $url !== '' ? mb_trim( (string) parse_url( $url, PHP_URL_HOST ) ) : '';
        $html = '<span class="tm-directory-link-tree-line">';
        if ( $title_is_link && $url !== '' ) {
            $html .= '<a class="tm-directory-link-anchor" href="' . $this->escape( $url ) . '" rel="noopener noreferrer">' . $this->escape( $title ) . '</a>';
        } else {
            $html .= '<span class="tm-directory-link-tree-title">' . $this->escape( $title ) . '</span>';
        }
        if ( $host !== '' ) {
            $html .= '<span class="tm-directory-link-host">' . $this->escape( preg_replace( '/^www\./i', '', $host ) ?? $host ) . '</span>';
        }
        return( $html . '</span>' );
    }

    protected function renderLinkTreeDescription( array $item ) : string {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $description = $this->renderMarkdown( (string) ( $fields['description'] ?? '' ) );
        if ( $description === '' ) {
            return( '' );
        }
        return( '<div class="tm-directory-link-tree-description tm-directory-markdown">' . $description . '</div>' );
    }

    protected function groupItemsByField( array $items, string $field_key, string $fallback_label ) : array {
        $groups = [];
        foreach ( $items as $item ) {
            $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
            $group = mb_trim( (string) ( $fields[$field_key] ?? $fields['group'] ?? '' ) );
            if ( $group === '' ) {
                $group = $fallback_label;
            }
            $groups[$group][] = $item;
        }
        ksort( $groups, SORT_NATURAL | SORT_FLAG_CASE );
        return( $groups );
    }

    protected function renderItemCard( array $item, array $type, bool $expanded, string $surface ) : string {
        return( $this->renderLinkItem( $item, $type, $surface ) );
    }

    protected function renderLinkItem( array $item, array $type, string $surface ) : string {
        $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : [];
        $media = $this->resolveItemMedia( $fields, $type );
        $url = $this->getFirstUrlField( $fields, $type );
        $title = (string) ( $item['title'] ?? '' );
        $description = $this->renderMarkdown( (string) ( $fields['description'] ?? '' ) );
        $host = $url !== '' ? mb_trim( (string) parse_url( $url, PHP_URL_HOST ) ) : '';

        $html = '<div class="tm-directory-item tm-directory-link-item tm-directory-item-' . $this->escape( $surface ) . '">';
        if ( ! empty( $media ) ) {
            $image_url = (string) ( $media['thumbnail_url'] ?: ( $media['display_url'] ?: $media['url'] ) );
            if ( $image_url !== '' ) {
                $html .= '<div class="tm-directory-media"><img src="' . $this->escape( $image_url ) . '" alt="' . $this->escape( (string) ( $media['alt_text'] ?: $title ) ) . '" loading="lazy"></div>';
            }
        }
        $html .= '<div class="tm-directory-item-body">';
        $html .= '<div class="tm-directory-item-title tm-directory-link-title" role="heading" aria-level="4">';
        if ( $url !== '' ) {
            $html .= '<a class="tm-directory-link-anchor" href="' . $this->escape( $url ) . '" rel="noopener noreferrer">' . $this->escape( $title ) . '</a>';
        } else {
            $html .= $this->escape( $title );
        }
        $html .= '</div>';
        if ( $host !== '' ) {
            $html .= '<div class="tm-directory-link-host">' . $this->escape( preg_replace( '/^www\./i', '', $host ) ?? $host ) . '</div>';
        }
        if ( $description !== '' ) {
            if ( $surface === 'list' ) {
                $html .= '<div class="tm-directory-link-description tm-directory-markdown"><span class="tm-directory-link-separator" aria-hidden="true"> - </span><div class="tm-directory-link-description-body">' . $description . '</div></div>';
            } else {
                $html .= '<div class="tm-directory-link-description tm-directory-markdown">' . $description . '</div>';
            }
        }
        $html .= '</div></div>';
        return( $html );
    }

    protected function buildItemTree( array $items ) : array {
        $known_ids = [];
        foreach ( $items as $item ) {
            $item_id = (string) ( $item['id'] ?? '' );
            if ( $item_id !== '' ) {
                $known_ids[$item_id] = true;
            }
        }
        $by_parent = [];
        foreach ( $items as $item ) {
            $parent = (string) ( $item['parent_id'] ?? '' );
            if ( $parent !== '' && ! isset( $known_ids[$parent] ) ) {
                $parent = '';
            }
            $by_parent[$parent][] = $item;
        }
        foreach ( $by_parent as $parent => $siblings ) {
            usort( $siblings, [ $this, 'compareSiblingItems' ] );
            $by_parent[$parent] = $siblings;
        }
        $build = function( string $parent_id ) use ( &$build, $by_parent ) : array {
            $nodes = [];
            foreach ( $by_parent[$parent_id] ?? [] as $item ) {
                $nodes[] = [
                    'item' => $item,
                    'children' => $build( (string) ( $item['id'] ?? '' ) ),
                ];
            }
            return( $nodes );
        };
        return( $build( '' ) );
    }

    protected function normalizeSubmittedFields( array $input, array $type ) : array {
        $fields = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = $this->normalizeKey( (string) ( $field['key'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }
            $value = mb_trim( (string) ( $input[$key] ?? '' ) );
            $field_type = (string) ( $field['type'] ?? 'text' );
            if ( ! empty( $field['required'] ) && $value === '' ) {
                throw new \InvalidArgumentException( 'field_' . $key );
            }
            if ( $value !== '' && $field_type === 'url' && ! $this->isSafeUrl( $value ) ) {
                throw new \InvalidArgumentException( 'field_' . $key );
            }
            if ( $value !== '' && $field_type === 'email' && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                throw new \InvalidArgumentException( 'field_' . $key );
            }
            if ( $value !== '' && $field_type === 'media' && ! preg_match( '/^media_[A-Za-z0-9_-]+$/', $value ) ) {
                throw new \InvalidArgumentException( 'field_' . $key );
            }
            if ( $field_type === 'select' && $value !== '' ) {
                $options = array_map( 'strval', (array) ( $field['options'] ?? [] ) );
                if ( ! in_array( $value, $options, true ) ) {
                    throw new \InvalidArgumentException( 'field_' . $key );
                }
            }
            $fields[$key] = $value;
        }
        return( $fields );
    }

    protected function isSafeUrl( string $url ) : bool {
        $url = trim( $url );
        if ( $url === '' ) {
            return( true );
        }
        if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
            return( true );
        }
        $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
        return( in_array( $scheme, [ 'http', 'https', 'mailto' ], true ) );
    }

    protected function resolveItemMedia( array $fields, array $type ) : array {
        if ( ! $this->media_service instanceof TinyMashMediaService ) {
            return( [] );
        }
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) || (string) ( $field['type'] ?? '' ) !== 'media' ) {
                continue;
            }
            $media_id = trim( (string) ( $fields[(string) ( $field['key'] ?? '' )] ?? '' ) );
            if ( $media_id === '' ) {
                continue;
            }
            $metadata = $this->media_service->getAttachmentMetadataByMediaId( $media_id, [] );
            if ( ! is_array( $metadata ) ) {
                continue;
            }
            $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
            $url = trim( (string) ( $metadata['url'] ?? '' ) );
            if ( $url === '' || ! str_starts_with( $mime, 'image/' ) ) {
                continue;
            }
            $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
            return(
                [
                    'media_id' => $media_id,
                    'url' => $url,
                    'display_url' => trim( (string) ( $display['url'] ?? '' ) ),
                    'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                    'alt_text' => trim( (string) ( $metadata['alt_text'] ?? '' ) ),
                ]
            );
        }
        return( [] );
    }

    protected function normalizeImageRecord( array $metadata ) : array {
        $media_id = trim( (string) ( $metadata['media_id'] ?? '' ) );
        $mime = strtolower( trim( (string) ( $metadata['mime'] ?? '' ) ) );
        $url = trim( (string) ( $metadata['url'] ?? '' ) );
        if ( $media_id === '' || $url === '' || ! str_starts_with( $mime, 'image/' ) || $mime === 'image/svg+xml' || $mime === 'image/svg' ) {
            return( [] );
        }
        $display = is_array( $metadata['display'] ?? null ) ? $metadata['display'] : [];
        return(
            [
                'media_id' => $media_id,
                'owner_username' => strtolower( trim( (string) ( $metadata['owner_username'] ?? '' ) ) ),
                'url' => $url,
                'display_url' => trim( (string) ( $display['url'] ?? '' ) ),
                'thumbnail_url' => trim( (string) ( $metadata['thumbnail']['url'] ?? '' ) ),
                'alt_text' => trim( (string) ( $metadata['alt_text'] ?? '' ) ),
                'filename' => basename( (string) ( $metadata['filename'] ?? $metadata['original_filename'] ?? '' ) ),
                'mime' => $mime,
                'width' => max( 0, (int) ( $metadata['width'] ?? 0 ) ),
                'height' => max( 0, (int) ( $metadata['height'] ?? 0 ) ),
                'created_at_utc' => trim( (string) ( $metadata['created_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function getFirstUrlField( array $fields, array $type ) : string {
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) || (string) ( $field['type'] ?? '' ) !== 'url' ) {
                continue;
            }
            $url = trim( (string) ( $fields[(string) ( $field['key'] ?? '' )] ?? '' ) );
            if ( $url !== '' && $this->isSafeUrl( $url ) ) {
                return( $url );
            }
        }
        return( '' );
    }

    protected function getSummaryText( array $fields, array $type ) : string {
        $description = mb_trim( strip_tags( (string) ( $fields['description'] ?? '' ) ) );
        return( $description !== '' ? mb_substr( preg_replace( '/\s+/u', ' ', $description ) ?? $description, 0, 180 ) : '' );
    }

    protected function renderMarkdown( string $markdown ) : string {
        $markdown = mb_trim( $markdown );
        if ( $markdown === '' ) {
            return( '' );
        }
        if ( $this->markdown_renderer instanceof TinyMashMarkdownRenderer ) {
            return( $this->markdown_renderer->render( $markdown ) );
        }
        return( nl2br( $this->escape( $markdown ) ) );
    }

    protected function getFieldDefinition( string $field_key, array $type ) : array {
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( is_array( $field ) && (string) ( $field['key'] ?? '' ) === $field_key ) {
                return( $field );
            }
        }
        return( [] );
    }

    protected function normalizeTypeRecord( array $type, bool $built_in ) : array {
        $key = $this->normalizeKey( (string) ( $type['key'] ?? '' ) );
        $fields = [];
        foreach ( (array) ( $type['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $field_key = $this->normalizeKey( (string) ( $field['key'] ?? '' ) );
            if ( $field_key === '' || $field_key === 'tags' ) {
                continue;
            }
            $field_type = strtolower( trim( (string) ( $field['type'] ?? 'text' ) ) );
            if ( ! in_array( $field_type, [ 'text', 'markdown', 'url', 'email', 'media', 'select', 'checkbox' ], true ) ) {
                $field_type = 'text';
            }
            $fields[] = [
                'key' => $field_key,
                'label' => mb_trim( (string) ( $field['label'] ?? ucfirst( str_replace( '_', ' ', $field_key ) ) ) ),
                'type' => $field_type,
                'required' => ! empty( $field['required'] ),
                'options' => array_values( array_filter( array_map( 'strval', (array) ( $field['options'] ?? [] ) ), static fn( string $value ) : bool => trim( $value ) !== '' ) ),
            ];
        }
        $views = [];
        foreach ( (array) ( $type['views'] ?? [ 'list' ] ) as $view ) {
            $view = $this->normalizeView( (string) $view );
            if ( $view !== '' && ! in_array( $view, $views, true ) ) {
                $views[] = $view;
            }
        }
        if ( empty( $views ) ) {
            $views = [ 'list' ];
        }
        $default_view = $this->normalizeView( (string) ( $type['default_view'] ?? $views[0] ) );
        if ( ! in_array( $default_view, $views, true ) ) {
            $default_view = $views[0];
        }
        $hierarchy = (string) ( $type['hierarchy'] ?? 'flat' );
        if ( ! in_array( $hierarchy, [ 'flat', 'grouped', 'tree' ], true ) ) {
            $hierarchy = 'flat';
        }
        return(
            [
                'key' => $key,
                'label' => mb_trim( (string) ( $type['label'] ?? ucfirst( $key ) ) ),
                'item_label' => mb_trim( (string) ( $type['item_label'] ?? 'Item' ) ),
                'built_in' => $built_in || ! empty( $type['built_in'] ),
                'source_type' => $this->normalizeKey( (string) ( $type['source_type'] ?? '' ) ),
                'enabled' => ! array_key_exists( 'enabled', $type ) || ! empty( $type['enabled'] ),
                'allow_authors' => ! empty( $type['allow_authors'] ),
                'hierarchy' => $hierarchy,
                'default_view' => $default_view,
                'views' => $views,
                'fields' => $fields,
                'updated_at_utc' => trim( (string) ( $type['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeDirectoryRecord( array $directory ) : array {
        $scope = $this->normalizeScope( (string) ( $directory['scope'] ?? 'root' ) );
        return(
            [
                'id' => $this->normalizeId( (string) ( $directory['id'] ?? '' ), 'dir' ),
                'type_key' => $this->normalizeKey( (string) ( $directory['type_key'] ?? '' ) ),
                'title' => mb_trim( (string) ( $directory['title'] ?? '' ) ),
                'alias' => $this->normalizeIdentifier( (string) ( $directory['alias'] ?? '' ) ),
                'scope' => $scope,
                'author_slug' => $scope === 'author' ? $this->normalizeAuthorSlug( (string) ( $directory['author_slug'] ?? '' ) ) : '',
                'enabled' => ! empty( $directory['enabled'] ),
                'intro_markdown' => mb_trim( (string) ( $directory['intro_markdown'] ?? '' ) ),
                'default_view' => $this->normalizeView( (string) ( $directory['default_view'] ?? 'list' ) ),
                'sort_order' => max( 0, (int) ( $directory['sort_order'] ?? 0 ) ),
                'created_at_utc' => trim( (string) ( $directory['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $directory['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function normalizeItemRecord( array $item ) : array {
        $fields = [];
        foreach ( (array) ( $item['fields'] ?? [] ) as $key => $value ) {
            $field_key = $this->normalizeKey( (string) $key );
            if ( $field_key !== '' && $field_key !== 'tags' ) {
                $fields[$field_key] = is_scalar( $value ) ? (string) $value : '';
            }
        }
        return(
            [
                'id' => $this->normalizeId( (string) ( $item['id'] ?? '' ), 'item' ),
                'title' => mb_trim( (string) ( $item['title'] ?? '' ) ),
                'slug' => $this->slugify( (string) ( $item['slug'] ?? '' ) ),
                'parent_id' => $this->normalizeId( (string) ( $item['parent_id'] ?? '' ), 'item' ),
                'enabled' => ! empty( $item['enabled'] ),
                'sort_order' => max( 0, (int) ( $item['sort_order'] ?? 0 ) ),
                'fields' => $fields,
                'created_at_utc' => trim( (string) ( $item['created_at_utc'] ?? '' ) ),
                'updated_at_utc' => trim( (string) ( $item['updated_at_utc'] ?? '' ) ),
            ]
        );
    }

    protected function sortItems( array $items ) : array {
        $known_ids = [];
        foreach ( $items as $item ) {
            $item_id = (string) ( $item['id'] ?? '' );
            if ( $item_id !== '' ) {
                $known_ids[$item_id] = true;
            }
        }
        $by_parent = [];
        foreach ( $items as $item ) {
            $parent_id = (string) ( $item['parent_id'] ?? '' );
            if ( $parent_id !== '' && ! isset( $known_ids[$parent_id] ) ) {
                $parent_id = '';
            }
            $by_parent[$parent_id][] = $item;
        }
        foreach ( $by_parent as $parent_id => $siblings ) {
            usort( $siblings, [ $this, 'compareSiblingItems' ] );
            $by_parent[$parent_id] = $siblings;
        }

        $sorted = [];
        $visited = [];
        $append = function( string $parent_id ) use ( &$append, &$sorted, &$visited, $by_parent ) : void {
            foreach ( $by_parent[$parent_id] ?? [] as $item ) {
                $item_id = (string) ( $item['id'] ?? '' );
                if ( $item_id !== '' && isset( $visited[$item_id] ) ) {
                    continue;
                }
                if ( $item_id !== '' ) {
                    $visited[$item_id] = true;
                }
                $sorted[] = $item;
                if ( $item_id !== '' ) {
                    $append( $item_id );
                }
            }
        };
        $append( '' );

        $remaining = [];
        foreach ( $items as $item ) {
            $item_id = (string) ( $item['id'] ?? '' );
            if ( $item_id === '' || ! isset( $visited[$item_id] ) ) {
                $remaining[] = $item;
            }
        }
        usort( $remaining, [ $this, 'compareSiblingItems' ] );
        return( [ ...$sorted, ...$remaining ] );
    }

    protected function compareSiblingItems( array $left, array $right ) : int {
        $left_order = (int) ( $left['sort_order'] ?? 0 );
        $right_order = (int) ( $right['sort_order'] ?? 0 );
        if ( $left_order !== $right_order ) {
            return( $left_order <=> $right_order );
        }
        $left_title = (string) ( $left['title'] ?? '' );
        $right_title = (string) ( $right['title'] ?? '' );
        $title_compare = strnatcasecmp( $left_title, $right_title );
        if ( $title_compare !== 0 ) {
            return( $title_compare );
        }
        return( strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) ) );
    }

    protected function itemExists( array $items, string $item_id ) : bool {
        foreach ( $items as $item ) {
            if ( (string) ( $item['id'] ?? '' ) === $item_id ) {
                return( true );
            }
        }
        return( false );
    }

    protected function itemSlugExists( array $items, string $parent_id, string $slug ) : bool {
        foreach ( $items as $item ) {
            $item = $this->normalizeItemRecord( is_array( $item ) ? $item : [] );
            if ( $item['parent_id'] === $parent_id && $item['slug'] === $slug ) {
                return( true );
            }
        }
        return( false );
    }

    protected function collectDescendantIds( array $items, string $parent_id ) : array {
        $ids = [];
        foreach ( $items as $item ) {
            $item = $this->normalizeItemRecord( is_array( $item ) ? $item : [] );
            if ( $item['parent_id'] !== $parent_id || $item['id'] === '' ) {
                continue;
            }
            $ids[$item['id']] = true;
            foreach ( $this->collectDescendantIds( $items, $item['id'] ) as $id => $enabled ) {
                $ids[$id] = $enabled;
            }
        }
        return( $ids );
    }

    protected function normalizeDirectoriesStore( array $store ) : array {
        return( [ 'directories' => array_values( array_filter( (array) ( $store['directories'] ?? [] ), 'is_array' ) ) ] );
    }

    protected function normalizeItemsStore( array $store ) : array {
        return( [ 'items' => array_values( array_filter( (array) ( $store['items'] ?? [] ), 'is_array' ) ) ] );
    }

    protected function readDirectoriesStore() : array {
        return( $this->normalizeDirectoriesStore( TinyMashLockedJsonFile::read( $this->directories_filename, $this->defaultDirectoriesStore() ) ) );
    }

    protected function readItemsStore( string $directory_id ) : array {
        return( $this->normalizeItemsStore( TinyMashLockedJsonFile::read( $this->getItemsFilename( $directory_id ), $this->defaultItemsStore() ) ) );
    }

    protected function defaultDirectoriesStore() : array {
        return( [ 'directories' => [] ] );
    }

    protected function defaultItemsStore() : array {
        return( [ 'items' => [] ] );
    }

    protected function getItemsFilename( string $directory_id ) : string {
        $directory_id = $this->normalizeId( $directory_id, 'dir' );
        return( $this->items_directory . DIRECTORY_SEPARATOR . $directory_id . '.json' );
    }

    protected function normalizeView( string $view ) : string {
        $view = strtolower( trim( $view ) );
        return( in_array( $view, [ 'list', 'boxes', 'cards', 'tree' ], true ) ? $view : 'list' );
    }

    protected function normalizeBoolean( mixed $value ) : bool {
        if ( is_bool( $value ) ) {
            return( $value );
        }
        $value = strtolower( trim( (string) $value ) );
        return( in_array( $value, [ '1', 'true', 'yes', 'on' ], true ) );
    }

    protected function isWideContentRequested( array $attributes ) : bool {
        if ( $this->normalizeBoolean( $attributes['wide'] ?? false ) ) {
            return( true );
        }

        $sidebar = strtolower( trim( (string) ( $attributes['sidebar'] ?? '' ) ) );
        return( in_array( $sidebar, [ '0', 'false', 'no', 'off', 'hide', 'hidden', 'disabled' ], true ) );
    }

    protected function normalizeScope( string $scope ) : string {
        return( strtolower( trim( $scope ) ) === 'author' ? 'author' : 'root' );
    }

    protected function normalizeAuthorSlug( string $author_slug ) : string {
        $author_slug = strtolower( trim( $author_slug ) );
        return( preg_match( '/^[a-z0-9][a-z0-9_-]{0,79}$/', $author_slug ) ? $author_slug : '' );
    }

    protected function normalizeKey( string $key ) : string {
        $key = strtolower( trim( $key ) );
        $key = preg_replace( '/[^a-z0-9_-]+/', '-', $key ) ?? '';
        return( trim( $key, '-' ) );
    }

    protected function normalizeIdentifier( string $identifier ) : string {
        $identifier = strtolower( trim( $identifier ) );
        $identifier = preg_replace( '/[^a-z0-9_-]+/', '-', $identifier ) ?? '';
        return( trim( $identifier, '-' ) );
    }

    protected function normalizeId( string $id, string $prefix ) : string {
        $id = trim( $id );
        return( preg_match( '/^' . preg_quote( $prefix, '/' ) . '_[a-z0-9_]{8,80}$/', $id ) ? $id : '' );
    }

    protected function slugify( string $value ) : string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/u', '-', $value ) ?? '';
        return( trim( $value, '-' ) );
    }

    protected function buildId( string $prefix ) : string {
        return( $prefix . '_' . gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 5 ) ) );
    }

    protected function escape( string $value ) : string {
        return( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
    }
}
