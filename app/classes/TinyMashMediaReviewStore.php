<?php
namespace app\classes;

class TinyMashMediaReviewStore {

    protected string $store_filename;

    public function __construct( string $store_filename ) {
        $this->store_filename = $store_filename;
    }

    public function getReview( string $media_id ) : array {
        $media_id = $this->normalizeMediaId( $media_id );
        if ( $media_id === '' ) {
            return( $this->buildDefaultReview( '' ) );
        }

        $store = $this->readStore();
        $record = is_array( $store['reviews'][$media_id] ?? null ) ? $store['reviews'][$media_id] : [];
        return( $this->normalizeReviewRecord( array_merge( $record, [ 'media_id' => $media_id ] ) ) );
    }

    public function getReviewsById() : array {
        $store = $this->readStore();
        $reviews = is_array( $store['reviews'] ?? null ) ? $store['reviews'] : [];
        $normalized_reviews = [];
        foreach ( $reviews as $media_id => $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $normalized = $this->normalizeReviewRecord( array_merge( $record, [ 'media_id' => (string) $media_id ] ) );
            if ( $normalized['media_id'] !== '' && $normalized['status'] !== 'unreviewed' ) {
                $normalized_reviews[$normalized['media_id']] = $normalized;
            }
        }
        ksort( $normalized_reviews, SORT_NATURAL | SORT_FLAG_CASE );
        return( $normalized_reviews );
    }

    public function setReviewStatus( string $media_id, string $status, string $reviewed_by ) : array {
        $media_id = $this->normalizeMediaId( $media_id );
        $status = $this->normalizeStatus( $status );
        $reviewed_by = $this->normalizeReviewedBy( $reviewed_by );
        if ( $media_id === '' || $status === 'unreviewed' ) {
            throw new \InvalidArgumentException( 'Invalid media review status.' );
        }

        return( TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $media_id, $status, $reviewed_by ) : array {
                $store = $this->normalizeStore( $store );
                $store['reviews'][$media_id] = [
                    'media_id' => $media_id,
                    'status' => $status,
                    'reviewed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                    'reviewed_by' => $reviewed_by,
                ];
                ksort( $store['reviews'], SORT_NATURAL | SORT_FLAG_CASE );

                return(
                    [
                        'data' => $store,
                        'result' => $this->normalizeReviewRecord( $store['reviews'][$media_id] ),
                    ]
                );
            },
            $this->buildDefaultStore()
        ) );
    }

    public function clearReview( string $media_id ) : void {
        $media_id = $this->normalizeMediaId( $media_id );
        if ( $media_id === '' ) {
            return;
        }

        TinyMashLockedJsonFile::mutate(
            $this->store_filename,
            function( array $store ) use ( $media_id ) : array {
                $store = $this->normalizeStore( $store );
                unset( $store['reviews'][$media_id] );
                ksort( $store['reviews'], SORT_NATURAL | SORT_FLAG_CASE );
                return( $store );
            },
            $this->buildDefaultStore()
        );
    }

    public function summarizeReviews( array $media_ids ) : array {
        $reviews = $this->getReviewsById();
        $summary = [
            'checked' => 0,
            'unreviewed' => 0,
            'keep' => 0,
            'cleanup_candidate' => 0,
        ];
        foreach ( $media_ids as $media_id ) {
            $media_id = $this->normalizeMediaId( (string) $media_id );
            if ( $media_id === '' ) {
                continue;
            }
            $summary['checked']++;
            $status = (string) ( $reviews[$media_id]['status'] ?? 'unreviewed' );
            if ( ! array_key_exists( $status, $summary ) ) {
                $status = 'unreviewed';
            }
            $summary[$status]++;
        }

        return( $summary );
    }

    public function normalizeReviewRecord( array $record ) : array {
        $media_id = $this->normalizeMediaId( (string) ( $record['media_id'] ?? '' ) );
        $status = $this->normalizeStatus( (string) ( $record['status'] ?? 'unreviewed' ) );

        if ( $status === 'unreviewed' ) {
            return( $this->buildDefaultReview( $media_id ) );
        }

        return(
            [
                'media_id' => $media_id,
                'status' => $status,
                'status_label' => $this->getStatusLabel( $status ),
                'reviewed_at_utc' => trim( (string) ( $record['reviewed_at_utc'] ?? '' ) ),
                'reviewed_by' => $this->normalizeReviewedBy( (string) ( $record['reviewed_by'] ?? '' ) ),
            ]
        );
    }

    public function getStatusLabel( string $status ) : string {
        return(
            match ( $this->normalizeStatus( $status ) ) {
                'keep' => 'Reviewed: keep',
                'cleanup_candidate' => 'Cleanup candidate',
                default => 'Unreviewed',
            }
        );
    }

    protected function readStore() : array {
        return( $this->normalizeStore( TinyMashLockedJsonFile::read( $this->store_filename, $this->buildDefaultStore() ) ) );
    }

    protected function normalizeStore( array $store ) : array {
        $reviews = is_array( $store['reviews'] ?? null ) ? $store['reviews'] : [];
        $normalized_reviews = [];
        foreach ( $reviews as $media_id => $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $normalized = $this->normalizeReviewRecord( array_merge( $record, [ 'media_id' => (string) $media_id ] ) );
            if ( $normalized['media_id'] !== '' && $normalized['status'] !== 'unreviewed' ) {
                $normalized_reviews[$normalized['media_id']] = $normalized;
            }
        }
        ksort( $normalized_reviews, SORT_NATURAL | SORT_FLAG_CASE );

        return(
            [
                'version' => 1,
                'reviews' => $normalized_reviews,
            ]
        );
    }

    protected function buildDefaultStore() : array {
        return(
            [
                'version' => 1,
                'reviews' => [],
            ]
        );
    }

    protected function buildDefaultReview( string $media_id ) : array {
        return(
            [
                'media_id' => $this->normalizeMediaId( $media_id ),
                'status' => 'unreviewed',
                'status_label' => 'Unreviewed',
                'reviewed_at_utc' => '',
                'reviewed_by' => '',
            ]
        );
    }

    protected function normalizeStatus( string $status ) : string {
        $status = strtolower( trim( $status ) );
        return( in_array( $status, [ 'keep', 'cleanup_candidate' ], true ) ? $status : 'unreviewed' );
    }

    protected function normalizeMediaId( string $media_id ) : string {
        $media_id = trim( $media_id );
        return( preg_match( '/^media_[a-z0-9_]{8,80}$/', $media_id ) ? $media_id : '' );
    }

    protected function normalizeReviewedBy( string $reviewed_by ) : string {
        $reviewed_by = strtolower( trim( $reviewed_by ) );
        return( preg_replace( '/[^a-z0-9_]/', '', $reviewed_by ) ?? '' );
    }
}
