<?php
namespace app\classes;

class TinyMashContentTargetPickerService {

    protected TinyMashContentRepository $content_repository;

    public function __construct( TinyMashContentRepository $content_repository ) {
        $this->content_repository = $content_repository;
    }

    public function listTargets( ?string $language = null, ?string $author_slug = null, bool $allow_root = true, array $options = [] ) : array {
        $published_only = ! array_key_exists( 'published_only', $options ) || ! empty( $options['published_only'] );
        $types = $this->normalizeTypes( is_array( $options['types'] ?? null ) ? $options['types'] : [ 'post', 'page' ] );
        $single_author_scope = ! $allow_root && is_string( $author_slug ) && trim( $author_slug ) !== '';
        $targets = [];

        foreach ( $this->content_repository->getEditorEntryOptions( $language, $author_slug, $allow_root ) as $entry_option ) {
            if ( ! is_array( $entry_option ) ) {
                continue;
            }

            $status = (string) ( $entry_option['status'] ?? '' );
            if ( $published_only && $status !== 'published' ) {
                continue;
            }

            $type = (string) ( $entry_option['type'] ?? 'post' );
            if ( ! empty( $types ) && ! in_array( $type, $types, true ) ) {
                continue;
            }

            $target = $this->buildTargetFromEntryOption( $entry_option, $single_author_scope );
            if ( empty( $target ) ) {
                continue;
            }

            $targets[] = $target;
        }

        return( $targets );
    }

    public function buildPickerOptions( ?string $language = null, ?string $author_slug = null, bool $allow_root = true, array $options = [] ) : array {
        $picker_options = [];
        foreach ( $this->listTargets( $language, $author_slug, $allow_root, $options ) as $target ) {
            $picker_options[] = [
                'value' => ltrim( (string) ( $target['url'] ?? '' ), '/' ),
                'label' => (string) ( $target['label'] ?? '' ),
                'id' => (string) ( $target['id'] ?? '' ),
                'type' => (string) ( $target['type'] ?? '' ),
                'scope' => (string) ( $target['scope'] ?? '' ),
                'author_slug' => (string) ( $target['author_slug'] ?? '' ),
                'path' => (string) ( $target['path'] ?? '' ),
                'url' => (string) ( $target['url'] ?? '' ),
            ];
        }

        return( $picker_options );
    }

    public function findTargetById( string $entry_id, ?string $language = null, ?string $author_slug = null, bool $allow_root = true, array $options = [] ) : array {
        $entry_id = trim( $entry_id );
        if ( $entry_id === '' ) {
            return( [] );
        }

        foreach ( $this->listTargets( $language, $author_slug, $allow_root, $options ) as $target ) {
            if ( (string) ( $target['id'] ?? '' ) === $entry_id ) {
                return( $target );
            }
        }

        return( [] );
    }

    public function buildTargetFromEntryOption( array $entry_option, bool $single_author_scope = false ) : array {
        $path = trim( (string) ( $entry_option['path'] ?? '' ), '/' );
        if ( $path === '' ) {
            return( [] );
        }

        $scope = (string) ( $entry_option['scope'] ?? 'root' );
        $author_slug = (string) ( $entry_option['author_slug'] ?? '' );
        $url = '/' . str_replace( '%2F', '/', rawurlencode( $path ) );
        if ( $scope === 'author' && $author_slug !== '' ) {
            $url = '/' . rawurlencode( $author_slug ) . $url;
        }

        return(
            [
                'id' => (string) ( $entry_option['id'] ?? '' ),
                'title' => (string) ( $entry_option['title'] ?? '' ),
                'label' => $this->buildTargetLabel( $entry_option, $single_author_scope ),
                'type' => (string) ( $entry_option['type'] ?? 'post' ),
                'scope' => $scope,
                'author_slug' => $author_slug,
                'path' => $path,
                'url' => $url,
                'status' => (string) ( $entry_option['status'] ?? '' ),
            ]
        );
    }

    protected function buildTargetLabel( array $entry_option, bool $single_author_scope ) : string {
        $title = mb_trim( (string) ( $entry_option['title'] ?? '' ) );
        if ( $title === '' ) {
            $title = 'Untitled content';
        }

        $label_parts = [ (string) ( $entry_option['type'] ?? 'post' ) ];
        if ( ! $single_author_scope ) {
            $label_parts[] = ( (string) ( $entry_option['scope'] ?? 'root' ) === 'author' )
                ? ( (string) ( $entry_option['author_slug'] ?? '' ) . ' space' )
                : 'root';
        }

        return( $title . ' (' . implode( ', ', $label_parts ) . ')' );
    }

    protected function normalizeTypes( array $types ) : array {
        $normalized_types = [];
        foreach ( $types as $type ) {
            $type = strtolower( trim( (string) $type ) );
            if ( in_array( $type, [ 'post', 'page' ], true ) ) {
                $normalized_types[$type] = true;
            }
        }

        return( array_keys( $normalized_types ) );
    }

}
