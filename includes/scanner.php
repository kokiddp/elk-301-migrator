<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

const ELK_301_MIGRATOR_SCAN_OPTION    = 'elk_301_migrator_scan';
const ELK_301_MIGRATOR_TARGETS_OPTION = 'elk_301_migrator_targets';

/**
 * Collect every public URL on the site grouped by source type.
 *
 * @param array{attachment_after?: string, attachment_before?: string, attachment_extensions?: array<int, string>} $filters
 * @return array<string, array<int, array{url: string, label: string, type: string}>>
 */
function elk_301_migrator_collect_urls( array $filters = [] ): array {
    $groups = [
        'front'        => [],
        'post_types'   => [],
        'taxonomies'   => [],
        'archives'     => [],
        'authors'      => [],
        'attachments'  => [],
    ];

    $home_url = home_url( '/' );
    $groups['front'][] = [
        'url'   => $home_url,
        'label' => __( 'Home', 'elk-301-migrator' ),
        'type'  => 'front',
    ];

    $blog_page_id = (int) get_option( 'page_for_posts' );
    if ( $blog_page_id > 0 ) {
        $blog_url = get_permalink( $blog_page_id );
        if ( $blog_url && $blog_url !== $home_url ) {
            $groups['front'][] = [
                'url'   => $blog_url,
                'label' => __( 'Posts page', 'elk-301-migrator' ),
                'type'  => 'front',
            ];
        }
    }

    $post_types = get_post_types( [ 'public' => true ], 'objects' );

    foreach ( $post_types as $post_type ) {
        if ( $post_type->name === 'attachment' ) {
            continue;
        }

        $query = new WP_Query( [
            'post_type'              => $post_type->name,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'fields'                 => 'ids',
        ] );

        foreach ( $query->posts as $post_id ) {
            $permalink = get_permalink( $post_id );
            if ( ! $permalink ) {
                continue;
            }

            $groups['post_types'][] = [
                'url'   => $permalink,
                'label' => sprintf( '[%s] %s', $post_type->labels->singular_name, get_the_title( $post_id ) ),
                'type'  => 'post:' . $post_type->name,
            ];
        }

        if ( $post_type->has_archive && $post_type->name !== 'post' ) {
            $archive_link = get_post_type_archive_link( $post_type->name );
            if ( $archive_link ) {
                $groups['archives'][] = [
                    'url'   => $archive_link,
                    'label' => sprintf( __( 'Archive: %s', 'elk-301-migrator' ), $post_type->labels->name ),
                    'type'  => 'archive:' . $post_type->name,
                ];
            }
        }
    }

    $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

    foreach ( $taxonomies as $taxonomy ) {
        $terms = get_terms( [
            'taxonomy'   => $taxonomy->name,
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            $term_link = get_term_link( $term );
            if ( is_wp_error( $term_link ) || ! $term_link ) {
                continue;
            }

            $groups['taxonomies'][] = [
                'url'   => $term_link,
                'label' => sprintf( '[%s] %s', $taxonomy->labels->singular_name, $term->name ),
                'type'  => 'term:' . $taxonomy->name,
            ];
        }
    }

    $authors = get_users( [
        'has_published_posts' => true,
        'fields'              => [ 'ID', 'display_name' ],
    ] );

    foreach ( $authors as $author ) {
        $author_url = get_author_posts_url( $author->ID );
        if ( ! $author_url ) {
            continue;
        }

        $groups['authors'][] = [
            'url'   => $author_url,
            'label' => sprintf( __( 'Author: %s', 'elk-301-migrator' ), $author->display_name ),
            'type'  => 'author',
        ];
    }

    $groups['attachments'] = elk_301_migrator_collect_attachments( $filters );

    foreach ( $groups as $key => $items ) {
        $groups[ $key ] = elk_301_migrator_dedupe( $items );
    }

    return $groups;
}

/**
 * Collect attachments with optional date and extension filters.
 *
 * @param array{attachment_after?: string, attachment_before?: string, attachment_extensions?: array<int, string>} $filters
 * @return array<int, array{url: string, label: string, type: string}>
 */
function elk_301_migrator_collect_attachments( array $filters ): array {
    $args = [
        'post_type'              => 'attachment',
        'post_status'            => 'inherit',
        'posts_per_page'         => -1,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'fields'                 => 'ids',
    ];

    $after  = $filters['attachment_after']  ?? '';
    $before = $filters['attachment_before'] ?? '';

    if ( $after !== '' || $before !== '' ) {
        $date_query = [];
        if ( $after !== '' ) {
            $date_query['after'] = $after;
        }
        if ( $before !== '' ) {
            $date_query['before']    = $before;
            $date_query['inclusive'] = true;
        }
        $args['date_query'] = [ $date_query ];
    }

    $extensions = array_map(
        function ( $ext ) {
            return strtolower( ltrim( trim( (string) $ext ), '.' ) );
        },
        $filters['attachment_extensions'] ?? []
    );
    $extensions = array_values( array_filter( $extensions ) );

    if ( $extensions ) {
        $mime_types = [];
        foreach ( $extensions as $ext ) {
            $type = wp_check_filetype( 'file.' . $ext );
            if ( ! empty( $type['type'] ) ) {
                $mime_types[] = $type['type'];
            }
        }
        if ( $mime_types ) {
            $args['post_mime_type'] = array_values( array_unique( $mime_types ) );
        }
    }

    $query  = new WP_Query( $args );
    $result = [];

    foreach ( $query->posts as $attachment_id ) {
        $attachment_url = wp_get_attachment_url( $attachment_id );
        if ( ! $attachment_url ) {
            continue;
        }

        if ( $extensions ) {
            $path      = wp_parse_url( $attachment_url, PHP_URL_PATH ) ?: '';
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( ! in_array( $extension, $extensions, true ) ) {
                continue;
            }
        }

        $result[] = [
            'url'   => $attachment_url,
            'label' => get_the_title( $attachment_id ),
            'type'  => 'attachment',
        ];
    }

    return $result;
}

/**
 * Remove duplicate URLs while preserving order.
 *
 * @param array<int, array{url: string, label: string, type: string}> $items
 * @return array<int, array{url: string, label: string, type: string}>
 */
function elk_301_migrator_dedupe( array $items ): array {
    $seen   = [];
    $result = [];

    foreach ( $items as $item ) {
        $key = $item['url'];
        if ( isset( $seen[ $key ] ) ) {
            continue;
        }
        $seen[ $key ] = true;
        $result[]     = $item;
    }

    return $result;
}

/**
 * Convert a full URL to a site-relative path.
 */
function elk_301_migrator_to_relative( string $url ): string {
    $home = home_url();
    if ( strpos( $url, $home ) === 0 ) {
        $relative = substr( $url, strlen( $home ) );
        if ( $relative === '' || $relative === false ) {
            return '/';
        }
        return $relative;
    }

    $parsed = wp_parse_url( $url );
    $path   = $parsed['path'] ?? '/';
    if ( ! empty( $parsed['query'] ) ) {
        $path .= '?' . $parsed['query'];
    }

    return $path;
}

/**
 * Run a scan and persist it alongside the filters used.
 *
 * @param array{attachment_after?: string, attachment_before?: string, attachment_extensions?: array<int, string>} $filters
 * @return array{groups: array<string, array<int, array>>, filters: array, scanned_at: int}
 */
function elk_301_migrator_run_scan( array $filters ): array {
    $groups = elk_301_migrator_collect_urls( $filters );
    $scan   = [
        'groups'     => $groups,
        'filters'    => $filters,
        'scanned_at' => time(),
    ];

    update_option( ELK_301_MIGRATOR_SCAN_OPTION, $scan, false );
    elk_301_migrator_prune_targets( $groups );

    return $scan;
}

/**
 * @return array{groups: array<string, array<int, array>>, filters: array, scanned_at: int}|null
 */
function elk_301_migrator_get_scan(): ?array {
    $scan = get_option( ELK_301_MIGRATOR_SCAN_OPTION );
    if ( ! is_array( $scan ) || empty( $scan['groups'] ) ) {
        return null;
    }
    return $scan;
}

/**
 * @return array<string, string> source URL => target URL
 */
function elk_301_migrator_get_targets(): array {
    $targets = get_option( ELK_301_MIGRATOR_TARGETS_OPTION, [] );
    return is_array( $targets ) ? $targets : [];
}

/**
 * Merge targets into the stored map. Only keys present in $incoming are touched.
 * A value of '' explicitly clears that source; a value of null leaves it alone.
 *
 * @param array<string, string|null> $incoming source URL => target URL (or null to skip)
 */
function elk_301_migrator_save_targets( array $incoming ): int {
    $current = elk_301_migrator_get_targets();
    $changed = 0;
    $scan    = elk_301_migrator_get_scan();
    $known   = elk_301_migrator_build_url_index( $scan['groups'] ?? [] );

    foreach ( $incoming as $source => $target ) {
        $source = elk_301_migrator_canonicalize_source( (string) $source, $known );
        if ( $source === '' ) {
            continue;
        }

        if ( $target === null ) {
            continue;
        }

        $target = trim( (string) $target );
        if ( $target === '' ) {
            if ( isset( $current[ $source ] ) ) {
                unset( $current[ $source ] );
                $changed++;
            }
            continue;
        }

        $sanitized = elk_301_migrator_sanitize_target( $target );
        if ( $sanitized === null ) {
            continue;
        }

        if ( ( $current[ $source ] ?? '' ) !== $sanitized ) {
            $current[ $source ] = $sanitized;
            $changed++;
        }
    }

    update_option( ELK_301_MIGRATOR_TARGETS_OPTION, $current, false );
    return $changed;
}

/**
 * Build a lookup index of known scanned URLs, matching both raw and percent-encoded forms.
 *
 * @param array<string, array<int, array{url: string, label: string, type: string}>> $groups
 * @return array<string, string> variant => canonical URL (as it appears in the scan)
 */
function elk_301_migrator_build_url_index( array $groups ): array {
    $index = [];
    foreach ( $groups as $items ) {
        foreach ( $items as $item ) {
            $url                   = $item['url'];
            $index[ $url ]         = $url;
            $encoded               = esc_url_raw( $url );
            if ( $encoded !== '' ) {
                $index[ $encoded ] = $url;
            }
        }
    }
    return $index;
}

/**
 * Resolve a submitted source to the canonical scanned URL, so targets are stored
 * under the same key the exporter uses to look them up.
 *
 * @param array<string, string> $known index from elk_301_migrator_build_url_index
 */
function elk_301_migrator_canonicalize_source( string $source, array $known ): string {
    $source = trim( $source );
    if ( $source === '' ) {
        return '';
    }
    if ( isset( $known[ $source ] ) ) {
        return $known[ $source ];
    }
    $encoded = esc_url_raw( $source );
    if ( isset( $known[ $encoded ] ) ) {
        return $known[ $encoded ];
    }
    return $encoded;
}

/**
 * Allow absolute URLs (http/https) and site-relative paths starting with "/".
 */
function elk_301_migrator_sanitize_target( string $target ): ?string {
    if ( $target === '' ) {
        return null;
    }

    if ( $target[0] === '/' ) {
        $clean = wp_kses_bad_protocol( $target, [ 'http', 'https' ] );
        return $clean !== '' ? $clean : null;
    }

    $url = esc_url_raw( $target, [ 'http', 'https' ] );
    return $url !== '' ? $url : null;
}

/**
 * Drop saved targets whose source URL is no longer in the scan, and re-key any
 * targets that were stored under a percent-encoded variant to the scan's canonical URL.
 *
 * @param array<string, array<int, array{url: string, label: string, type: string}>> $groups
 */
function elk_301_migrator_prune_targets( array $groups ): void {
    $targets = elk_301_migrator_get_targets();
    if ( ! $targets ) {
        return;
    }

    $index = elk_301_migrator_build_url_index( $groups );
    $next  = [];

    foreach ( $targets as $source => $target ) {
        if ( isset( $index[ $source ] ) ) {
            $next[ $index[ $source ] ] = $target;
        }
    }

    if ( $next !== $targets ) {
        update_option( ELK_301_MIGRATOR_TARGETS_OPTION, $next, false );
    }
}
