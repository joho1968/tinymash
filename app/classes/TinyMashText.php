<?php
namespace app\classes;

final class TinyMashText {

    public static function compareCaseInsensitive( string $left, string $right, bool $natural = false ) : int {
        $left_folded = mb_convert_case( $left, MB_CASE_FOLD, 'UTF-8' );
        $right_folded = mb_convert_case( $right, MB_CASE_FOLD, 'UTF-8' );
        return( $natural
            ? strnatcmp( $left_folded, $right_folded )
            : strcmp( $left_folded, $right_folded ) );
    }

    public static function equalsCaseInsensitive( string $left, string $right ) : bool {
        return(
            mb_convert_case( $left, MB_CASE_FOLD, 'UTF-8' )
            === mb_convert_case( $right, MB_CASE_FOLD, 'UTF-8' )
        );
    }
}
