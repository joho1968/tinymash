<?php
namespace app\classes;

class TinyMashHelpCatalog {

    protected string $base_path;
    protected string $default_locale;
    protected array $runtime_documents = [];

    public function __construct( string $base_path, string $default_locale = 'en' ) {
        $this->base_path = rtrim( $base_path, DIRECTORY_SEPARATOR );
        $this->default_locale = trim( $default_locale ) !== '' ? trim( $default_locale ) : 'en';
    }

    public function getContexts( array $contexts, string $locale = '' ) : array {
        $locale = $this->normalizeLocale( $locale );
        $documents = [];
        $seen_contexts = [];

        foreach ( $contexts as $context ) {
            $context = $this->normalizeContext( (string) $context );
            if ( $context === '' || isset( $seen_contexts[$context] ) ) {
                continue;
            }

            $document = $this->loadContextDocument( $context, $locale );
            if ( $document === null ) {
                continue;
            }

            $documents[] = $document;
            $seen_contexts[$context] = true;
        }

        return( $documents );
    }

    public function getIndex( string $locale = '' ) : array {
        $locale = $this->normalizeLocale( $locale );
        $index = [];
        $seen_contexts = [];

        foreach ( $this->getPreferredContextOrder() as $context ) {
            $document = $this->loadContextDocument( $context, $locale );
            if ( $document === null ) {
                continue;
            }

            $index[] = [
                'context' => $context,
                'title' => (string) ( $document['title'] ?? ucfirst( str_replace( '-', ' ', $context ) ) ),
                'summary' => (string) ( $document['summary'] ?? '' ),
                'group' => (string) ( $document['group'] ?? 'Core' ),
                'roles' => is_array( $document['roles'] ?? null ) ? array_values( $document['roles'] ) : [],
            ];
            $seen_contexts[$context] = true;
        }

        foreach ( $this->getLocaleCandidates( $locale ) as $locale_candidate ) {
            $directory = $this->base_path . DIRECTORY_SEPARATOR . $locale_candidate;
            if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
                continue;
            }

            $filenames = glob( $directory . DIRECTORY_SEPARATOR . '*.json' ) ?: [];
            sort( $filenames );
            foreach ( $filenames as $filename ) {
                $context = basename( $filename, '.json' );
                if ( isset( $seen_contexts[$context] ) ) {
                    continue;
                }

                $document = $this->loadContextDocument( $context, $locale );
                if ( $document === null ) {
                    continue;
                }

                $index[] = [
                    'context' => $context,
                    'title' => (string) ( $document['title'] ?? ucfirst( str_replace( '-', ' ', $context ) ) ),
                    'summary' => (string) ( $document['summary'] ?? '' ),
                    'group' => (string) ( $document['group'] ?? 'Core' ),
                    'roles' => is_array( $document['roles'] ?? null ) ? array_values( $document['roles'] ) : [],
                ];
                $seen_contexts[$context] = true;
            }
        }

        foreach ( $this->getRuntimeIndex() as $item ) {
            $context = (string) ( $item['context'] ?? '' );
            if ( $context === '' || isset( $seen_contexts[$context] ) ) {
                continue;
            }

            $index[] = $item;
            $seen_contexts[$context] = true;
        }

        return( $index );
    }

    public function registerDocument( string $context, array $document ) : bool {
        $context = $this->normalizeContext( $context );
        if ( $context === '' ) {
            return( false );
        }

        $title = trim( (string) ( $document['title'] ?? '' ) );
        if ( $title === '' ) {
            return( false );
        }

        $this->runtime_documents[$context] = [
            'context' => $context,
            'locale' => 'runtime',
            'title' => $title,
            'summary' => trim( (string) ( $document['summary'] ?? '' ) ),
            'sections' => $this->normalizeSections( $document['sections'] ?? [] ),
            'group' => trim( (string) ( $document['group'] ?? 'Plugins' ) ) !== '' ? trim( (string) ( $document['group'] ?? 'Plugins' ) ) : 'Plugins',
            'order' => (int) ( $document['order'] ?? 1000 ),
            'roles' => $this->normalizeRoles( $document['roles'] ?? [] ),
        ];

        return( true );
    }

    public function isContextVisibleToRole( string $context, string $role, string $locale = '' ) : bool {
        $context = $this->normalizeContext( $context );
        $role = $this->normalizeRole( $role );
        if ( $context === '' || $role === '' ) {
            return( false );
        }

        $document = $this->loadContextDocument( $context, $this->normalizeLocale( $locale ) );
        if ( $document === null ) {
            return( false );
        }

        $roles = is_array( $document['roles'] ?? null ) ? array_values( $document['roles'] ) : [];
        if ( empty( $roles ) ) {
            return( true );
        }

        return( in_array( $role, $roles, true ) );
    }

    protected function loadContextDocument( string $context, string $locale ) : ?array {
        if ( isset( $this->runtime_documents[$context] ) && is_array( $this->runtime_documents[$context] ) ) {
            return( $this->runtime_documents[$context] );
        }

        foreach ( $this->getLocaleCandidates( $locale ) as $locale_candidate ) {
            $filename = $this->base_path . DIRECTORY_SEPARATOR . $locale_candidate . DIRECTORY_SEPARATOR . $context . '.json';
            if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
                continue;
            }

            try {
                $data = json_decode( (string) file_get_contents( $filename ), true, 32, JSON_THROW_ON_ERROR );
            } catch ( \Throwable $e ) {
                error_log( basename( __FILE__ ) . ': Unable to read help context "' . $context . '" (' . $e->getMessage() . ')' );
                continue;
            }

            if ( ! is_array( $data ) ) {
                continue;
            }

            return(
                [
                    'context' => $context,
                    'locale' => $locale_candidate,
                    'title' => (string) ( $data['title'] ?? ucfirst( str_replace( '-', ' ', $context ) ) ),
                    'summary' => (string) ( $data['summary'] ?? '' ),
                    'sections' => $this->normalizeSections( $data['sections'] ?? [] ),
                    'group' => trim( (string) ( $data['group'] ?? 'Core' ) ) !== '' ? trim( (string) ( $data['group'] ?? 'Core' ) ) : 'Core',
                    'order' => (int) ( $data['order'] ?? 100 ),
                    'roles' => $this->normalizeRoles( $data['roles'] ?? [] ),
                ]
            );
        }

        return( null );
    }

    protected function normalizeSections( mixed $sections ) : array {
        if ( ! is_array( $sections ) ) {
            return( [] );
        }

        $normalized_sections = [];
        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }

            $normalized_sections[] = [
                'title' => (string) ( $section['title'] ?? '' ),
                'markdown' => (string) ( $section['markdown'] ?? '' ),
            ];
        }

        return( $normalized_sections );
    }

    protected function normalizeRoles( mixed $roles ) : array {
        if ( ! is_array( $roles ) ) {
            return( [] );
        }

        $normalized_roles = [];
        foreach ( $roles as $role ) {
            $role = $this->normalizeRole( (string) $role );
            if ( $role === '' || in_array( $role, $normalized_roles, true ) ) {
                continue;
            }
            $normalized_roles[] = $role;
        }

        return( $normalized_roles );
    }

    protected function normalizeRole( string $role ) : string {
        $role = strtolower( trim( $role ) );
        return( in_array( $role, [ 'author', 'admin' ], true ) ? $role : '' );
    }

    protected function getLocaleCandidates( string $locale ) : array {
        $candidates = [ $locale ];
        if ( ! in_array( $this->default_locale, $candidates, true ) ) {
            $candidates[] = $this->default_locale;
        }

        return( $candidates );
    }

    protected function normalizeLocale( string $locale ) : string {
        $locale = strtolower( trim( $locale ) );
        $locale = preg_replace( '/[^a-z0-9_-]/', '', $locale ) ?? '';

        return( $locale !== '' ? $locale : $this->default_locale );
    }

    protected function normalizeContext( string $context ) : string {
        $context = strtolower( trim( $context ) );

        return( preg_replace( '/[^a-z0-9-]/', '', $context ) ?? '' );
    }

    protected function getRuntimeIndex() : array {
        $index = [];

        foreach ( $this->runtime_documents as $context => $document ) {
            if ( ! is_array( $document ) ) {
                continue;
            }

            $index[] = [
                'context' => (string) $context,
                'title' => (string) ( $document['title'] ?? ucfirst( str_replace( '-', ' ', (string) $context ) ) ),
                'summary' => (string) ( $document['summary'] ?? '' ),
                'group' => (string) ( $document['group'] ?? 'Plugins' ),
                'roles' => is_array( $document['roles'] ?? null ) ? array_values( $document['roles'] ) : [],
                'order' => (int) ( $document['order'] ?? 1000 ),
            ];
        }

        usort(
            $index,
            static function( array $left, array $right ) : int {
                $group_compare = strcmp( (string) ( $left['group'] ?? '' ), (string) ( $right['group'] ?? '' ) );
                if ( $group_compare !== 0 ) {
                    return( $group_compare );
                }

                $order_compare = (int) ( $left['order'] ?? 1000 ) <=> (int) ( $right['order'] ?? 1000 );
                if ( $order_compare !== 0 ) {
                    return( $order_compare );
                }

                return( strcmp( (string) ( $left['title'] ?? '' ), (string) ( $right['title'] ?? '' ) ) );
            }
        );

        return( $index );
    }

    protected function getPreferredContextOrder() : array {
        return(
            [
                'admin-global',
                'admin-overview',
                'admin-content',
                'admin-editor',
                'markdown-reference',
                'admin-profile-account',
                'admin-profile-editor',
                'admin-profile-publishing',
                'admin-system',
            ]
        );
    }

}// TinyMashHelpCatalog
