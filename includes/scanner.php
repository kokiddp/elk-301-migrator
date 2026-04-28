<?php
// SPDX-License-Identifier: GPL-2.0-or-later

if ( ! defined( 'WPINC' ) ) {
    die;
}

const ELK_301_MIGRATOR_SCAN_OPTION    = 'elk_301_migrator_scan';
const ELK_301_MIGRATOR_TARGETS_OPTION = 'elk_301_migrator_targets';
const ELK_301_MIGRATOR_IGNORED_OPTION = 'elk_301_migrator_ignored';
const ELK_301_MIGRATOR_SCAN_BATCH     = 500;

/**
 * Collect every public URL on the site grouped by source type.
 *
 * @param array{attachment_after?: string, attachment_before?: string, attachment_extensions?: array<int, string>} $filters
 * @return array<string, array<int, array{url: string, label: string, type: string, language_code?: string, language_label?: string}>>
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
    $groups['front'] = array_merge(
        $groups['front'],
        elk_301_migrator_collect_url_variants(
            [
                'url'   => $home_url,
                'label' => __( 'Home', 'elk-301-migrator' ),
                'type'  => 'front',
            ],
            [
                'kind' => 'front_page',
            ]
        )
    );

    $blog_page_id = (int) get_option( 'page_for_posts' );
    if ( $blog_page_id > 0 ) {
        $blog_url = get_permalink( $blog_page_id );
        if ( $blog_url && $blog_url !== $home_url ) {
            $groups['front'] = array_merge(
                $groups['front'],
                elk_301_migrator_collect_url_variants(
                    [
                        'url'   => $blog_url,
                        'label' => __( 'Posts page', 'elk-301-migrator' ),
                        'type'  => 'front',
                    ],
                    [
                        'kind'      => 'page_for_posts',
                        'post_id'   => $blog_page_id,
                        'post_type' => 'page',
                    ]
                )
            );
        }
    }

    $post_types = get_post_types( [ 'public' => true ], 'objects' );

    foreach ( $post_types as $post_type ) {
        if ( $post_type->name === 'attachment' ) {
            continue;
        }

        $page = 1;
        do {
            $query = new WP_Query( [
                'post_type'              => $post_type->name,
                'post_status'            => 'publish',
                'posts_per_page'         => ELK_301_MIGRATOR_SCAN_BATCH,
                'paged'                  => $page,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'ignore_sticky_posts'    => true,
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

                $groups['post_types'] = array_merge(
                    $groups['post_types'],
                    elk_301_migrator_collect_url_variants(
                        [
                            'url'   => $permalink,
                            'label' => sprintf( '[%s] %s', $post_type->labels->singular_name, get_the_title( $post_id ) ),
                            'type'  => 'post:' . $post_type->name,
                        ],
                        [
                            'kind'      => 'post',
                            'post_id'   => (int) $post_id,
                            'post_type' => $post_type->name,
                        ]
                    )
                );
            }

            $page++;
        } while ( count( $query->posts ) === ELK_301_MIGRATOR_SCAN_BATCH );

        if ( $post_type->has_archive && $post_type->name !== 'post' ) {
            $archive_link = get_post_type_archive_link( $post_type->name );
            if ( $archive_link ) {
                $groups['archives'] = array_merge(
                    $groups['archives'],
                    elk_301_migrator_collect_url_variants(
                        [
                            'url'   => $archive_link,
                            'label' => sprintf( __( 'Archive: %s', 'elk-301-migrator' ), $post_type->labels->name ),
                            'type'  => 'archive:' . $post_type->name,
                        ],
                        [
                            'kind'      => 'post_type_archive',
                            'post_type' => $post_type->name,
                        ]
                    )
                );
            }
        }
    }

    $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

    foreach ( $taxonomies as $taxonomy ) {
        $offset = 0;
        do {
            $terms = get_terms( [
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
                'number'     => ELK_301_MIGRATOR_SCAN_BATCH,
                'offset'     => $offset,
                'orderby'    => 'term_id',
                'order'      => 'ASC',
            ] );

            if ( is_wp_error( $terms ) ) {
                continue 2;
            }

            foreach ( $terms as $term ) {
                $term_link = get_term_link( $term );
                if ( is_wp_error( $term_link ) || ! $term_link ) {
                    continue;
                }

                $groups['taxonomies'] = array_merge(
                    $groups['taxonomies'],
                    elk_301_migrator_collect_url_variants(
                        [
                            'url'   => $term_link,
                            'label' => sprintf( '[%s] %s', $taxonomy->labels->singular_name, $term->name ),
                            'type'  => 'term:' . $taxonomy->name,
                        ],
                        [
                            'kind'     => 'term',
                            'term_id'  => (int) $term->term_id,
                            'taxonomy' => $taxonomy->name,
                        ]
                    )
                );
            }

            $offset += ELK_301_MIGRATOR_SCAN_BATCH;
        } while ( count( $terms ) === ELK_301_MIGRATOR_SCAN_BATCH );
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

        $groups['authors'] = array_merge(
            $groups['authors'],
            elk_301_migrator_collect_url_variants(
                [
                    'url'   => $author_url,
                    'label' => sprintf( __( 'Author: %s', 'elk-301-migrator' ), $author->display_name ),
                    'type'  => 'author',
                ],
                [
                    'kind'      => 'author',
                    'author_id' => (int) $author->ID,
                ]
            )
        );
    }

    $groups['attachments'] = elk_301_migrator_collect_attachments( $filters );

    foreach ( $groups as $key => $items ) {
        $groups[ $key ] = elk_301_migrator_dedupe( $items );
    }

    return $groups;
}

/**
 * Expand a scanned item into translated URL variants when a multilingual plugin is active.
 *
 * @param array{url: string, label: string, type: string, language_code?: string, language_label?: string} $item
 * @param array<string, mixed>                            $context
 * @return array<int, array{url: string, label: string, type: string, language_code?: string, language_label?: string}>
 */
function elk_301_migrator_collect_url_variants( array $item, array $context = [] ): array {
    $item      = elk_301_migrator_attach_default_language_context( $item );
    $variants  = [ $item ];
    $languages = elk_301_migrator_get_translation_languages();

    foreach ( $languages as $language_code => $language_label ) {
        $translated_url = elk_301_migrator_translate_url_for_language( $item['url'], $context, $language_code );
        if ( ! is_string( $translated_url ) || $translated_url === '' ) {
            continue;
        }

        $variant_item = $item;
        $variant_item['url']            = $translated_url;
        $variant_item['label']          = $translated_url === $item['url'] ? $item['label'] : elk_301_migrator_label_with_language( $item['label'], $language_label ?: $language_code );
        $variant_item['type']           = $item['type'];
        $variant_item['language_code']  = $language_code;
        $variant_item['language_label'] = $language_label ?: $language_code;

        $variants[] = $variant_item;
    }

    $filtered = apply_filters( 'elk_301_migrator_url_variants', $variants, $item, $context );

    return elk_301_migrator_normalize_url_variants( $filtered, $item );
}

/**
 * Return the active multilingual languages keyed by language code.
 *
 * @return array<string, string>
 */
function elk_301_migrator_get_translation_languages(): array {
    static $languages = null;

    if ( $languages !== null ) {
        return $languages;
    }

    $languages = [];

    $wpml_languages = apply_filters(
        'wpml_active_languages',
        null,
        [
            'skip_missing' => 0,
            'orderby'      => 'code',
        ]
    );

    if ( is_array( $wpml_languages ) ) {
        foreach ( $wpml_languages as $language ) {
            if ( ! is_array( $language ) || empty( $language['code'] ) ) {
                continue;
            }

            $code              = (string) $language['code'];
            $languages[ $code ] = ! empty( $language['native_name'] ) ? (string) $language['native_name'] : $code;
        }
    }

    if ( function_exists( 'pll_languages_list' ) ) {
        $polylang_languages = pll_languages_list( [ 'fields' => 'slug' ] );
        if ( is_array( $polylang_languages ) ) {
            foreach ( $polylang_languages as $language_code ) {
                if ( ! is_scalar( $language_code ) ) {
                    continue;
                }

                $code = (string) $language_code;
                if ( $code === '' || isset( $languages[ $code ] ) ) {
                    continue;
                }

                $languages[ $code ] = $code;
            }
        }
    }

    $filtered = apply_filters( 'elk_301_migrator_translation_languages', $languages );
    if ( is_array( $filtered ) ) {
        $languages = [];
        foreach ( $filtered as $code => $label ) {
            if ( is_int( $code ) ) {
                if ( ! is_scalar( $label ) ) {
                    continue;
                }

                $normalized_code  = trim( (string) $label );
                $normalized_label = $normalized_code;
            } else {
                if ( ! is_scalar( $code ) ) {
                    continue;
                }

                $normalized_code  = trim( (string) $code );
                $normalized_label = is_scalar( $label ) && (string) $label !== '' ? (string) $label : $normalized_code;
            }

            if ( $normalized_code === '' ) {
                continue;
            }

            $languages[ $normalized_code ] = $normalized_label;
        }
    }

    return $languages;
}

/**
 * @return array{code: string, label: string}|null
 */
function elk_301_migrator_get_default_language(): ?array {
    static $default_language = null;
    static $resolved         = false;

    if ( $resolved ) {
        return $default_language;
    }

    $resolved = true;
    $code     = '';

    if ( function_exists( 'pll_default_language' ) ) {
        $polylang_default = pll_default_language();
        if ( is_scalar( $polylang_default ) ) {
            $code = trim( (string) $polylang_default );
        }
    }

    if ( $code === '' ) {
        $wpml_default = apply_filters( 'wpml_default_language', null );
        if ( is_scalar( $wpml_default ) ) {
            $code = trim( (string) $wpml_default );
        }
    }

    if ( $code === '' ) {
        return null;
    }

    $languages = elk_301_migrator_get_translation_languages();
    $label     = $languages[ $code ] ?? $code;

    $default_language = [
        'code'  => $code,
        'label' => $label,
    ];

    return $default_language;
}

/**
 * @param array{url: string, label: string, type: string, language_code?: string, language_label?: string} $item
 * @return array{url: string, label: string, type: string, language_code?: string, language_label?: string}
 */
function elk_301_migrator_attach_default_language_context( array $item ): array {
    if ( ! empty( $item['language_code'] ) ) {
        return $item;
    }

    $default_language = elk_301_migrator_get_default_language();
    if ( $default_language === null ) {
        return $item;
    }

    $item['language_code']  = $default_language['code'];
    $item['language_label'] = $default_language['label'];

    return $item;
}

/**
 * Translate a scanned URL for a specific language.
 *
 * @param array<string, mixed> $context
 */
function elk_301_migrator_translate_url_for_language( string $url, array $context, string $language_code ): ?string {
    $kind = isset( $context['kind'] ) && is_string( $context['kind'] ) ? $context['kind'] : '';

    $filtered_url = apply_filters( 'elk_301_migrator_translated_url', null, $url, $context, $language_code );
    if ( is_string( $filtered_url ) && $filtered_url !== '' ) {
        return $filtered_url;
    }

    if ( $kind === 'front_page' ) {
        $translated_home = elk_301_migrator_translate_home_url( $language_code );
        if ( $translated_home !== null ) {
            return $translated_home;
        }
    }

    if ( in_array( $kind, [ 'post', 'page_for_posts' ], true ) ) {
        $post_id   = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
        $post_type = isset( $context['post_type'] ) && is_string( $context['post_type'] ) ? $context['post_type'] : 'post';
        if ( $post_id > 0 ) {
            $translated_post_id = elk_301_migrator_translate_post_id( $post_id, $post_type, $language_code );
            if ( $translated_post_id > 0 ) {
                $translated_url = get_permalink( $translated_post_id );
                if ( is_string( $translated_url ) && $translated_url !== '' ) {
                    return $translated_url;
                }
            }

            return null;
        }
    }

    if ( $kind === 'term' ) {
        $term_id   = isset( $context['term_id'] ) ? (int) $context['term_id'] : 0;
        $taxonomy  = isset( $context['taxonomy'] ) && is_string( $context['taxonomy'] ) ? $context['taxonomy'] : '';
        if ( $term_id > 0 && $taxonomy !== '' ) {
            $translated_term_id = elk_301_migrator_translate_term_id( $term_id, $taxonomy, $language_code );
            if ( $translated_term_id > 0 ) {
                $term = get_term( $translated_term_id, $taxonomy );
                if ( $term instanceof WP_Term ) {
                    $translated_url = get_term_link( $term );
                    if ( ! is_wp_error( $translated_url ) && is_string( $translated_url ) && $translated_url !== '' ) {
                        return $translated_url;
                    }
                }
            }

            return null;
        }
    }

    if ( $kind === 'attachment' ) {
        $attachment_id = isset( $context['attachment_id'] ) ? (int) $context['attachment_id'] : 0;
        if ( $attachment_id > 0 ) {
            $translated_attachment_url = elk_301_migrator_translate_attachment_url( $attachment_id, $language_code );
            if ( $translated_attachment_url !== null ) {
                return $translated_attachment_url;
            }

            return null;
        }
    }

    return elk_301_migrator_translate_raw_url( $url, $language_code );
}

function elk_301_migrator_translate_post_id( int $post_id, string $post_type, string $language_code ): int {
    if ( function_exists( 'pll_get_post' ) ) {
        $translated_post_id = pll_get_post( $post_id, $language_code );
        if ( is_numeric( $translated_post_id ) && (int) $translated_post_id > 0 ) {
            return (int) $translated_post_id;
        }
    }

    if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) ) {
        $wpml_types = [ $post_type ];
        if ( $post_type === 'attachment' ) {
            $wpml_types[] = 'post_attachment';
        }

        foreach ( array_unique( $wpml_types ) as $wpml_type ) {
            $translated_post_id = apply_filters( 'wpml_object_id', $post_id, $wpml_type, true, $language_code );
            if ( is_numeric( $translated_post_id ) && (int) $translated_post_id > 0 ) {
                return (int) $translated_post_id;
            }
        }
    }

    return 0;
}

function elk_301_migrator_translate_term_id( int $term_id, string $taxonomy, string $language_code ): int {
    if ( function_exists( 'pll_get_term' ) ) {
        $translated_term_id = pll_get_term( $term_id, $language_code );
        if ( is_numeric( $translated_term_id ) && (int) $translated_term_id > 0 ) {
            return (int) $translated_term_id;
        }
    }

    if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) ) {
        $translated_term_id = apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $language_code );
        if ( is_numeric( $translated_term_id ) && (int) $translated_term_id > 0 ) {
            return (int) $translated_term_id;
        }
    }

    return 0;
}

function elk_301_migrator_translate_attachment_url( int $attachment_id, string $language_code ): ?string {
    $translated_attachment_id = elk_301_migrator_translate_post_id( $attachment_id, 'attachment', $language_code );
    if ( $translated_attachment_id <= 0 ) {
        return null;
    }

    $translated_url = wp_get_attachment_url( $translated_attachment_id );
    if ( ! is_string( $translated_url ) || $translated_url === '' ) {
        return null;
    }

    return $translated_url;
}

function elk_301_migrator_translate_home_url( string $language_code ): ?string {
    if ( function_exists( 'pll_home_url' ) ) {
        $translated_home = pll_home_url( $language_code );
        if ( is_string( $translated_home ) && $translated_home !== '' ) {
            return $translated_home;
        }
    }

    return elk_301_migrator_translate_raw_url( home_url( '/' ), $language_code );
}

function elk_301_migrator_translate_raw_url( string $url, string $language_code ): ?string {
    $wpml_url = null;
    if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_permalink' ) ) {
        $wpml_url = apply_filters( 'wpml_permalink', $url, $language_code, true );
    }

    if ( is_string( $wpml_url ) && $wpml_url !== '' ) {
        return $wpml_url;
    }

    if ( function_exists( 'pll_home_url' ) ) {
        $relative = elk_301_migrator_to_site_relative( $url );
        if ( $relative !== null ) {
            $translated_home = pll_home_url( $language_code );
            if ( is_string( $translated_home ) && $translated_home !== '' ) {
                return elk_301_migrator_join_home_and_relative( $translated_home, $relative );
            }
        }
    }

    return null;
}

function elk_301_migrator_join_home_and_relative( string $home, string $relative ): string {
    $query = '';
    if ( strpos( $relative, '?' ) !== false ) {
        list( $relative, $query ) = explode( '?', $relative, 2 );
    }

    $home     = trailingslashit( untrailingslashit( $home ) );
    $relative = ltrim( $relative, '/' );
    $joined   = $relative === '' ? $home : $home . $relative;

    if ( $query !== '' ) {
        $joined .= '?' . $query;
    }

    return $joined;
}

function elk_301_migrator_label_with_language( string $label, string $language_label ): string {
    return sprintf( __( '%1$s (%2$s)', 'elk-301-migrator' ), $label, $language_label );
}

/**
 * @param mixed                                   $items
 * @param array{url: string, label: string, type: string, language_code?: string, language_label?: string} $fallback_item
 * @return array<int, array{url: string, label: string, type: string, language_code?: string, language_label?: string}>
 */
function elk_301_migrator_normalize_url_variants( $items, array $fallback_item ): array {
    if ( ! is_array( $items ) ) {
        return [ $fallback_item ];
    }

    $normalized = [];

    foreach ( $items as $item ) {
        if ( ! is_array( $item ) || ! isset( $item['url'] ) ) {
            continue;
        }

        $url = trim( (string) $item['url'] );
        if ( $url === '' ) {
            continue;
        }

        if ( $url[0] === '/' && substr( $url, 0, 2 ) !== '//' ) {
            $normalized_url = $url;
        } else {
            $normalized_url = esc_url_raw( $url );
        }

        if ( $normalized_url === '' ) {
            continue;
        }

        $normalized[] = [
            'url'   => $normalized_url,
            'label' => isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : $fallback_item['label'],
            'type'  => isset( $item['type'] ) && is_scalar( $item['type'] ) ? (string) $item['type'] : $fallback_item['type'],
        ];

        if ( isset( $item['language_code'] ) && is_scalar( $item['language_code'] ) && trim( (string) $item['language_code'] ) !== '' ) {
            $normalized[ count( $normalized ) - 1 ]['language_code'] = trim( (string) $item['language_code'] );
        } elseif ( isset( $fallback_item['language_code'] ) && is_scalar( $fallback_item['language_code'] ) && trim( (string) $fallback_item['language_code'] ) !== '' ) {
            $normalized[ count( $normalized ) - 1 ]['language_code'] = trim( (string) $fallback_item['language_code'] );
        }

        if ( isset( $item['language_label'] ) && is_scalar( $item['language_label'] ) && trim( (string) $item['language_label'] ) !== '' ) {
            $normalized[ count( $normalized ) - 1 ]['language_label'] = trim( (string) $item['language_label'] );
        } elseif ( isset( $fallback_item['language_label'] ) && is_scalar( $fallback_item['language_label'] ) && trim( (string) $fallback_item['language_label'] ) !== '' ) {
            $normalized[ count( $normalized ) - 1 ]['language_label'] = trim( (string) $fallback_item['language_label'] );
        }
    }

    if ( ! $normalized ) {
        return [ $fallback_item ];
    }

    return $normalized;
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
        'posts_per_page'         => ELK_301_MIGRATOR_SCAN_BATCH,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
    ];

    $after  = $filters['attachment_after']  ?? '';
    $before = $filters['attachment_before'] ?? '';

    if ( $after !== '' || $before !== '' ) {
        $date_query = [ 'inclusive' => true ];
        if ( $after !== '' ) {
            $date_query['after'] = elk_301_migrator_date_bound( $after, 'start' );
        }
        if ( $before !== '' ) {
            $date_query['before'] = elk_301_migrator_date_bound( $before, 'end' );
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

    $page   = 1;
    $result = [];

    do {
        $args['paged'] = $page;
        $query         = new WP_Query( $args );

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

            $result = array_merge(
                $result,
                elk_301_migrator_collect_url_variants(
                    [
                        'url'   => $attachment_url,
                        'label' => get_the_title( $attachment_id ),
                        'type'  => 'attachment',
                    ],
                    [
                        'kind'          => 'attachment',
                        'attachment_id' => (int) $attachment_id,
                        'post_id'       => (int) $attachment_id,
                        'post_type'     => 'attachment',
                    ]
                )
            );
        }

        $page++;
    } while ( count( $query->posts ) === ELK_301_MIGRATOR_SCAN_BATCH );

    return $result;
}

function elk_301_migrator_date_bound( string $value, string $bound ): string {
    if ( preg_match( '/^\d{4}-\d{2}$/', $value ) ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value . '-01' );
        if ( $date instanceof DateTimeImmutable && $bound === 'end' ) {
            return $date->modify( 'last day of this month' )->format( 'Y-m-d 23:59:59' );
        }
        return $value . '-01 00:00:00';
    }

    if ( $bound === 'end' ) {
        return $value . ' 23:59:59';
    }

    return $value . ' 00:00:00';
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
    $relative = elk_301_migrator_to_site_relative( $url );
    if ( $relative !== null ) {
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
 * Convert local absolute URLs or site-relative paths to a site-relative path.
 * Returns null for external absolute URLs.
 */
function elk_301_migrator_to_site_relative( string $url ): ?string {
    if ( $url === '' ) {
        return null;
    }

    if ( $url[0] === '/' && substr( $url, 0, 2 ) !== '//' ) {
        return $url;
    }

    $home = wp_parse_url( home_url() );
    $test = wp_parse_url( $url );
    if ( empty( $home['host'] ) || empty( $test['host'] ) ) {
        return null;
    }

    $home_host = strtolower( $home['host'] );
    $test_host = strtolower( $test['host'] );
    $home_port = isset( $home['port'] ) ? (int) $home['port'] : null;
    $test_port = isset( $test['port'] ) ? (int) $test['port'] : null;

    if ( $home_host !== $test_host || $home_port !== $test_port ) {
        return null;
    }

    $home_path = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
    $path      = $test['path'] ?? '/';

    if ( $home_path !== '' && strpos( $path . '/', $home_path . '/' ) === 0 ) {
        $path = substr( $path, strlen( $home_path ) );
        if ( $path === '' || $path === false ) {
            $path = '/';
        }
    }

    if ( $path === '' ) {
        $path = '/';
    }

    if ( ! empty( $test['query'] ) ) {
        $path .= '?' . $test['query'];
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
    elk_301_migrator_prune_ignored( $groups );

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
    if ( ! is_array( $targets ) ) {
        return [];
    }

    $normalized = [];
    foreach ( $targets as $source => $target ) {
        if ( is_string( $source ) && is_scalar( $target ) ) {
            $normalized[ $source ] = (string) $target;
        }
    }

    return $normalized;
}

/**
 * @return array<string, bool> source URL => ignored
 */
function elk_301_migrator_get_ignored(): array {
    $ignored = get_option( ELK_301_MIGRATOR_IGNORED_OPTION, [] );
    if ( ! is_array( $ignored ) ) {
        return [];
    }

    $normalized = [];
    foreach ( $ignored as $source => $value ) {
        if ( is_string( $source ) && ! empty( $value ) ) {
            $normalized[ $source ] = true;
        }
    }

    return $normalized;
}

/**
 * Look up an ignored marker for a scanned URL.
 *
 * @param array<string, bool> $ignored
 */
function elk_301_migrator_is_ignored( string $url, array $ignored ): bool {
    if ( ! empty( $ignored[ $url ] ) ) {
        return true;
    }

    $encoded = esc_url_raw( $url );
    return $encoded !== '' && ! empty( $ignored[ $encoded ] );
}

/**
 * Merge targets and ignored markers into the stored maps. Only keys present in
 * $incoming are touched. A target value of '' explicitly clears that source; a
 * target value of null leaves it alone.
 *
 * @param array<string, string|null> $incoming source URL => target URL (or null to skip)
 * @param array<string, bool>        $ignored  source URL => ignored marker
 */
function elk_301_migrator_save_targets( array $incoming, array $ignored = [] ): int {
    $current = elk_301_migrator_get_targets();
    $current_ignored = elk_301_migrator_get_ignored();
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

    foreach ( $ignored as $source => $ignore ) {
        $source = elk_301_migrator_canonicalize_source( (string) $source, $known );
        if ( $source === '' ) {
            continue;
        }

        if ( $ignore && ( $current[ $source ] ?? '' ) === '' ) {
            if ( empty( $current_ignored[ $source ] ) ) {
                $current_ignored[ $source ] = true;
                $changed++;
            }
            continue;
        }

        if ( isset( $current_ignored[ $source ] ) ) {
            unset( $current_ignored[ $source ] );
            $changed++;
        }
    }

    update_option( ELK_301_MIGRATOR_TARGETS_OPTION, $current, false );
    update_option( ELK_301_MIGRATOR_IGNORED_OPTION, $current_ignored, false );
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
            $url           = $item['url'];
            $index[ $url ] = $url;
            $encoded       = esc_url_raw( $url );
            if ( $encoded !== '' ) {
                $index[ $encoded ] = $url;
            }

            $relative                           = elk_301_migrator_to_relative( $url );
            $index[ $relative ]                 = $url;
            $index[ rawurldecode( $relative ) ] = $url;
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
    $target = trim( $target );
    if ( $target === '' ) {
        return null;
    }

    if ( preg_match( '/[\x00-\x20\x7f]/', $target ) ) {
        return null;
    }

    if ( $target[0] === '/' ) {
        if ( substr( $target, 0, 2 ) === '//' || strpos( $target, '\\' ) !== false ) {
            return null;
        }

        return $target;
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

/**
 * Drop ignored markers whose source URL is no longer in the scan, and re-key
 * markers stored under a percent-encoded variant to the scan's canonical URL.
 *
 * @param array<string, array<int, array{url: string, label: string, type: string}>> $groups
 */
function elk_301_migrator_prune_ignored( array $groups ): void {
    $ignored = elk_301_migrator_get_ignored();
    if ( ! $ignored ) {
        return;
    }

    $index = elk_301_migrator_build_url_index( $groups );
    $next  = [];

    foreach ( $ignored as $source => $value ) {
        if ( isset( $index[ $source ] ) && $value ) {
            $next[ $index[ $source ] ] = true;
        }
    }

    if ( $next !== $ignored ) {
        update_option( ELK_301_MIGRATOR_IGNORED_OPTION, $next, false );
    }
}
