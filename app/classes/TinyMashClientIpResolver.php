<?php
namespace app\classes;

final class TinyMashClientIpResolver {

    public static function parseTrustedRanges( mixed $value ) : array {
        if ( is_array( $value ) ) {
            $entries = $value;
        } elseif ( is_string( $value ) && strlen( $value ) <= 4096 ) {
            $entries = preg_split( '/[\s,]+/', trim( $value ) ) ?: [];
        } else {
            throw new \InvalidArgumentException( 'trusted_proxy_cidrs' );
        }
        if ( count( $entries ) > 64 ) {
            throw new \InvalidArgumentException( 'trusted_proxy_cidrs' );
        }

        $ranges = [];
        foreach ( $entries as $entry ) {
            if ( ! is_string( $entry ) || $entry === '' ) {
                continue;
            }
            $parts = explode( '/', $entry );
            $address = $parts[0];
            $packed = inet_pton( $address );
            $max_bits = $packed === false ? 0 : strlen( $packed ) * 8;
            if ( count( $parts ) > 2 || $max_bits === 0 || ( count( $parts ) === 2 && ( $parts[1] === '' || ! ctype_digit( $parts[1] ) ) ) ) {
                throw new \InvalidArgumentException( 'trusted_proxy_cidrs' );
            }
            $bits = count( $parts ) === 2 ? (int) $parts[1] : $max_bits;
            if ( $bits < 1 || $bits > $max_bits ) {
                throw new \InvalidArgumentException( 'trusted_proxy_cidrs' );
            }
            $ranges[] = $address . '/' . $bits;
        }
        return( array_values( array_unique( $ranges ) ) );
    }

    public static function isTrusted( string $address, array $ranges ) : bool {
        $packed = inet_pton( $address );
        if ( $packed === false ) {
            return( false );
        }
        foreach ( $ranges as $range ) {
            [ $network, $bits ] = explode( '/', $range, 2 );
            $packed_network = inet_pton( $network );
            if ( $packed_network === false || strlen( $packed_network ) !== strlen( $packed ) ) {
                continue;
            }
            $full_bytes = intdiv( (int) $bits, 8 );
            $remaining_bits = (int) $bits % 8;
            if ( substr( $packed, 0, $full_bytes ) !== substr( $packed_network, 0, $full_bytes ) ) {
                continue;
            }
            if ( $remaining_bits === 0 || ( ord( $packed[$full_bytes] ) & ( 0xff << ( 8 - $remaining_bits ) ) ) === ( ord( $packed_network[$full_bytes] ) & ( 0xff << ( 8 - $remaining_bits ) ) ) ) {
                return( true );
            }
        }
        return( false );
    }

    public static function resolve( array $server, string $mode, array $ranges ) : string {
        $peer = is_string( $server['REMOTE_ADDR'] ?? null ) ? trim( $server['REMOTE_ADDR'] ) : '';
        if ( filter_var( $peer, FILTER_VALIDATE_IP ) === false ) {
            return( '' );
        }
        if ( $mode === 'off' || ! self::isTrusted( $peer, $ranges ) ) {
            return( $peer );
        }
        if ( $mode === 'cf-connecting-ip' ) {
            $header = $server['HTTP_CF_CONNECTING_IP'] ?? null;
            return( is_string( $header ) && strlen( $header ) <= 45 && filter_var( $header, FILTER_VALIDATE_IP ) !== false ? $header : $peer );
        }
        if ( $mode !== 'x-forwarded-for' && $mode !== 'x-forwarded-for-first' ) {
            return( $peer );
        }
        $header = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if ( ! is_string( $header ) || $header === '' || strlen( $header ) > 2048 ) {
            return( $peer );
        }
        $hops = explode( ',', $header );
        if ( count( $hops ) > 32 ) {
            return( $peer );
        }
        foreach ( $hops as &$hop ) {
            $hop = trim( $hop );
            if ( filter_var( $hop, FILTER_VALIDATE_IP ) === false ) {
                return( $peer );
            }
        }
        unset( $hop );
        for ( $index = count( $hops ) - 1; $index >= 0; $index-- ) {
            if ( ! self::isTrusted( $hops[$index], $ranges ) ) {
                return( $hops[$index] );
            }
        }
        return( $hops[0] );
    }
}
