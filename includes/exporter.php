<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Flatten the grouped URL list into a single ordered list, attaching saved targets.
 *
 * @param array<string, array<int, array{url: string, label: string, type: string}>> $groups
 * @param array<string, string>                                                       $targets
 * @param array{only_mapped?: bool, skip_identity?: bool}                             $options
 * @return array<int, array{url: string, target: string, label: string, type: string}>
 */
function elk_301_migrator_flatten( array $groups, array $targets, array $options = [] ): array {
    $only_mapped   = ! empty( $options['only_mapped'] );
    $skip_identity = ! empty( $options['skip_identity'] );
    $flat          = [];

    foreach ( $groups as $items ) {
        foreach ( $items as $item ) {
            $target = elk_301_migrator_lookup_target( $item['url'], $targets );
            if ( $only_mapped && $target === '' ) {
                continue;
            }
            if ( $skip_identity && $target !== '' && elk_301_migrator_is_identity( $item['url'], $target ) ) {
                continue;
            }
            $item['target'] = $target;
            $flat[]         = $item;
        }
    }
    return $flat;
}

/**
 * Look up a target for a scanned URL, tolerating keys stored under the
 * percent-encoded form of the URL.
 *
 * @param array<string, string> $targets
 */
function elk_301_migrator_lookup_target( string $url, array $targets ): string {
    if ( isset( $targets[ $url ] ) ) {
        return (string) $targets[ $url ];
    }
    $encoded = esc_url_raw( $url );
    if ( $encoded !== '' && isset( $targets[ $encoded ] ) ) {
        return (string) $targets[ $encoded ];
    }
    return '';
}

/**
 * True when the source URL and the target resolve to the same site-relative path.
 * Compares after normalising both to the form used in exports (relative for same-host).
 */
function elk_301_migrator_is_identity( string $source, string $target ): bool {
    $source_rel = elk_301_migrator_to_relative( $source );

    if ( $target === '' ) {
        return false;
    }

    if ( $target[0] === '/' ) {
        $target_rel = $target;
    } else {
        $target_rel = elk_301_migrator_to_relative( $target );
        if ( strpos( $target, home_url() ) !== 0 && $target_rel === $target ) {
            return false;
        }
    }

    return rtrim( $source_rel, '/' ) === rtrim( $target_rel, '/' );
}

/**
 * CSV: source,target,code,type,label. Empty target cell when not yet mapped.
 *
 * @param array{only_mapped?: bool, comments?: bool} $options
 */
function elk_301_migrator_build_csv( array $groups, array $targets, array $options = [] ): string {
    $rows = [];
    $rows[] = [ 'source', 'target', 'code', 'type', 'label' ];

    foreach ( elk_301_migrator_flatten( $groups, $targets, $options ) as $item ) {
        $rows[] = [
            elk_301_migrator_to_relative( $item['url'] ),
            $item['target'],
            '301',
            $item['type'],
            $item['label'],
        ];
    }

    $output = fopen( 'php://temp', 'r+' );
    foreach ( $rows as $row ) {
        fputcsv( $output, $row );
    }
    rewind( $output );
    $csv = stream_get_contents( $output );
    fclose( $output );

    return $csv;
}

/**
 * .htaccess: uses saved target when present, else `NEW_URL` placeholder.
 *
 * @param array{only_mapped?: bool, comments?: bool} $options
 */
function elk_301_migrator_build_htaccess( array $groups, array $targets, array $options = [] ): string {
    $with_comments = $options['comments'] ?? true;
    $lines         = [];

    if ( $with_comments ) {
        $lines[] = '# ELK 301 Migrator — generated ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
        $lines[] = '# Lines without a target still use NEW_URL — replace before deploying.';
        $lines[] = '';
    }

    foreach ( elk_301_migrator_flatten( $groups, $targets, $options ) as $item ) {
        $source = elk_301_migrator_to_relative( $item['url'] );
        $target = $item['target'] !== '' ? $item['target'] : 'NEW_URL';
        if ( $with_comments ) {
            $lines[] = sprintf( '# %s', $item['label'] );
        }
        $lines[] = sprintf( 'Redirect 301 %s %s', $source, $target );
    }

    return implode( "\n", $lines ) . "\n";
}

/**
 * Nginx: uses saved target when present, else `NEW_URL` placeholder.
 *
 * @param array{only_mapped?: bool, comments?: bool} $options
 */
function elk_301_migrator_build_nginx( array $groups, array $targets, array $options = [] ): string {
    $with_comments = $options['comments'] ?? true;
    $lines         = [];

    if ( $with_comments ) {
        $lines[] = '# ELK 301 Migrator — generated ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
        $lines[] = '# Lines without a target still use NEW_URL — replace before deploying.';
        $lines[] = '';
    }

    foreach ( elk_301_migrator_flatten( $groups, $targets, $options ) as $item ) {
        $source = elk_301_migrator_to_relative( $item['url'] );
        $target = $item['target'] !== '' ? $item['target'] : 'NEW_URL';
        if ( $with_comments ) {
            $lines[] = sprintf( '# %s', $item['label'] );
        }
        $lines[] = sprintf( 'rewrite ^%s$ %s permanent;', preg_quote( $source, '/' ), $target );
    }

    return implode( "\n", $lines ) . "\n";
}

/**
 * JSON: includes the saved target verbatim (empty string when none).
 *
 * @param array{only_mapped?: bool, comments?: bool} $options
 */
function elk_301_migrator_build_json( array $groups, array $targets, array $options = [] ): string {
    $items = [];
    foreach ( elk_301_migrator_flatten( $groups, $targets, $options ) as $item ) {
        $items[] = [
            'source' => elk_301_migrator_to_relative( $item['url'] ),
            'target' => $item['target'],
            'code'   => 301,
            'type'   => $item['type'],
            'label'  => $item['label'],
        ];
    }

    return wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Stream the requested export format back to the browser.
 */
function elk_301_migrator_stream_export( string $format ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'elk-301-migrator' ), 403 );
    }

    check_admin_referer( 'elk_301_migrator_export' );

    $scan = elk_301_migrator_get_scan();
    if ( ! $scan ) {
        wp_die( esc_html__( 'Run a scan before exporting.', 'elk-301-migrator' ), 400 );
    }

    $groups    = $scan['groups'];
    $targets   = elk_301_migrator_get_targets();
    $timestamp = gmdate( 'Ymd-His' );
    $options   = [
        'only_mapped'   => ! empty( $_GET['only_mapped'] ),
        'skip_identity' => ! empty( $_GET['skip_identity'] ),
        'comments'      => empty( $_GET['no_comments'] ),
    ];

    switch ( $format ) {
        case 'csv':
            $body     = elk_301_migrator_build_csv( $groups, $targets, $options );
            $filename = "elk-301-table-{$timestamp}.csv";
            $mime     = 'text/csv; charset=utf-8';
            break;
        case 'htaccess':
            $body     = elk_301_migrator_build_htaccess( $groups, $targets, $options );
            $filename = "elk-301-htaccess-{$timestamp}.txt";
            $mime     = 'text/plain; charset=utf-8';
            break;
        case 'nginx':
            $body     = elk_301_migrator_build_nginx( $groups, $targets, $options );
            $filename = "elk-301-nginx-{$timestamp}.conf";
            $mime     = 'text/plain; charset=utf-8';
            break;
        case 'json':
            $body     = elk_301_migrator_build_json( $groups, $targets, $options );
            $filename = "elk-301-table-{$timestamp}.json";
            $mime     = 'application/json; charset=utf-8';
            break;
        default:
            wp_die( esc_html__( 'Unknown export format.', 'elk-301-migrator' ), 400 );
    }

    nocache_headers();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $body ) );

    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}
