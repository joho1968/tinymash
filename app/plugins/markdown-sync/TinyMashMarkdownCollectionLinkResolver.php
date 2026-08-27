<?php

class TinyMashMarkdownCollectionLinkResolver {

    public function rewrite( string $markdown, string $source_path, array $collection_paths, string $target_path, string $public_base_path = '' ) : string {
        if ( $markdown === '' ) {
            return( '' );
        }

        $known_paths = [];
        foreach ( $collection_paths as $collection_path ) {
            $known_paths[trim( (string) $collection_path, '/' )] = true;
        }

        $lines = explode( "\n", $markdown );
        $fence_character = '';
        $fence_length = 0;
        foreach ( $lines as &$line ) {
            if ( $fence_character !== '' ) {
                if ( preg_match( '/^[ ]{0,3}' . preg_quote( $fence_character, '/' ) . '{' . $fence_length . ',}[ \t]*$/D', $line ) === 1 ) {
                    $fence_character = '';
                    $fence_length = 0;
                }
                continue;
            }

            if ( preg_match( '/^[ ]{0,3}(`{3,}|~{3,})/', $line, $fence_match ) === 1 ) {
                $fence_character = $fence_match[1][0];
                $fence_length = strlen( $fence_match[1] );
                continue;
            }
            if ( preg_match( '/^(?: {4}|\t)/', $line ) === 1 ) {
                continue;
            }

            $line = $this->rewriteReferenceDefinition( $line, $source_path, $known_paths, $target_path, $public_base_path );
            $line = $this->rewriteInlineLinks( $line, $source_path, $known_paths, $target_path, $public_base_path );
        }
        unset( $line );

        return( implode( "\n", $lines ) );
    }

    protected function rewriteReferenceDefinition( string $line, string $source_path, array $known_paths, string $target_path, string $public_base_path ) : string {
        if ( preg_match( '/^([ ]{0,3}\[[^]]+\]:[ \t]*)(<[^>]+>|[^ \t]+)(.*)$/D', $line, $matches ) !== 1 ) {
            return( $line );
        }

        $destination = (string) $matches[2];
        $wrapped = str_starts_with( $destination, '<' ) && str_ends_with( $destination, '>' );
        $raw_destination = $wrapped ? substr( $destination, 1, -1 ) : $destination;
        $resolved = $this->resolveDestination( $raw_destination, $source_path, $known_paths, $target_path, $public_base_path );
        if ( $resolved === $raw_destination ) {
            return( $line );
        }

        return( (string) $matches[1] . ( $wrapped ? '<' . $resolved . '>' : $resolved ) . (string) $matches[3] );
    }

    protected function rewriteInlineLinks( string $line, string $source_path, array $known_paths, string $target_path, string $public_base_path ) : string {
        $length = strlen( $line );
        for ( $offset = 0; $offset < $length; $offset++ ) {
            if ( $line[$offset] === '\\' ) {
                $offset++;
                continue;
            }
            if ( $line[$offset] === '`' ) {
                $run_length = strspn( $line, '`', $offset );
                $closing = strpos( $line, str_repeat( '`', $run_length ), $offset + $run_length );
                if ( $closing !== false ) {
                    $offset = $closing + $run_length - 1;
                }
                continue;
            }
            if ( $line[$offset] !== ']' || ( $line[$offset + 1] ?? '' ) !== '(' ) {
                continue;
            }

            $label_start = $this->findLinkLabelStart( $line, $offset );
            if ( $label_start < 0 || ( $label_start > 0 && $line[$label_start - 1] === '!' && ! $this->isEscaped( $line, $label_start - 1 ) ) ) {
                continue;
            }

            $destination_start = $offset + 2;
            while ( isset( $line[$destination_start] ) && ( $line[$destination_start] === ' ' || $line[$destination_start] === "\t" ) ) {
                $destination_start++;
            }
            if ( ! isset( $line[$destination_start] ) ) {
                continue;
            }

            $wrapped = $line[$destination_start] === '<';
            $value_start = $wrapped ? $destination_start + 1 : $destination_start;
            $value_end = $wrapped
                ? $this->findUnescapedCharacter( $line, '>', $value_start )
                : $this->findInlineDestinationEnd( $line, $value_start );
            if ( $value_end < $value_start || $this->findUnescapedCharacter( $line, ')', $value_end ) < 0 ) {
                continue;
            }

            $destination = substr( $line, $value_start, $value_end - $value_start );
            if ( $destination === '' ) {
                continue;
            }
            $resolved = $this->resolveDestination( $destination, $source_path, $known_paths, $target_path, $public_base_path );
            if ( $resolved === $destination ) {
                continue;
            }

            $line = substr( $line, 0, $value_start ) . $resolved . substr( $line, $value_end );
            $difference = strlen( $resolved ) - strlen( $destination );
            $length += $difference;
            $offset = $value_end + $difference;
        }

        return( $line );
    }

    protected function findLinkLabelStart( string $line, int $closing_offset ) : int {
        $depth = 0;
        for ( $offset = $closing_offset - 1; $offset >= 0; $offset-- ) {
            if ( $this->isEscaped( $line, $offset ) ) {
                continue;
            }
            if ( $line[$offset] === ']' ) {
                $depth++;
                continue;
            }
            if ( $line[$offset] !== '[' ) {
                continue;
            }
            if ( $depth > 0 ) {
                $depth--;
                continue;
            }
            return( $offset );
        }
        return( -1 );
    }

    protected function findInlineDestinationEnd( string $line, int $start ) : int {
        $parenthesis_depth = 0;
        $length = strlen( $line );
        for ( $offset = $start; $offset < $length; $offset++ ) {
            if ( $line[$offset] === '\\' ) {
                $offset++;
                continue;
            }
            if ( $line[$offset] === '(' ) {
                $parenthesis_depth++;
                continue;
            }
            if ( $line[$offset] === ')' ) {
                if ( $parenthesis_depth === 0 ) {
                    return( $offset );
                }
                $parenthesis_depth--;
                continue;
            }
            if ( $parenthesis_depth === 0 && ( $line[$offset] === ' ' || $line[$offset] === "\t" ) ) {
                return( $offset );
            }
        }
        return( -1 );
    }

    protected function findUnescapedCharacter( string $line, string $character, int $start ) : int {
        $length = strlen( $line );
        for ( $offset = $start; $offset < $length; $offset++ ) {
            if ( $line[$offset] === $character && ! $this->isEscaped( $line, $offset ) ) {
                return( $offset );
            }
        }
        return( -1 );
    }

    protected function isEscaped( string $line, int $offset ) : bool {
        $backslashes = 0;
        for ( $offset--; $offset >= 0 && $line[$offset] === '\\'; $offset-- ) {
            $backslashes++;
        }
        return( $backslashes % 2 === 1 );
    }

    protected function resolveDestination( string $destination, string $source_path, array $known_paths, string $target_path, string $public_base_path ) : string {
        if (
            $destination === '' ||
            str_starts_with( $destination, '/' ) ||
            str_starts_with( $destination, '#' ) ||
            str_starts_with( $destination, '?' ) ||
            preg_match( '/^[a-z][a-z0-9+.-]*:/i', $destination ) === 1
        ) {
            return( $destination );
        }

        $suffix_offset = strcspn( $destination, '?#' );
        $link_path = substr( $destination, 0, $suffix_offset );
        $suffix = substr( $destination, $suffix_offset );
        $link_path = str_replace( '\\', '/', $link_path );
        $segments = trim( $source_path, '/' ) === '' ? [] : explode( '/', trim( $source_path, '/' ) );
        foreach ( explode( '/', $link_path ) as $segment ) {
            if ( $segment === '' || $segment === '.' ) {
                continue;
            }
            if ( $segment === '..' ) {
                if ( $segments === [] ) {
                    throw new \InvalidArgumentException( 'page_link' );
                }
                array_pop( $segments );
                continue;
            }
            $segments[] = $segment;
        }
        if ( end( $segments ) === 'index.md' ) {
            array_pop( $segments );
        }
        $resolved_path = implode( '/', $segments );
        if ( ! isset( $known_paths[$resolved_path] ) ) {
            throw new \InvalidArgumentException( 'page_link' );
        }

        $receiver_path = trim( trim( $public_base_path, '/' ) . '/' . trim( $target_path, '/' ), '/' );
        if ( $resolved_path !== '' ) {
            $receiver_path .= '/' . $resolved_path;
        }
        return( '/' . $receiver_path . $suffix );
    }
}
