<?php

if ( ! defined( 'WPINC' ) ) {
    die;
}

add_action( 'admin_menu', function () {
    add_management_page(
        __( '301 Migrator', 'elk-301-migrator' ),
        __( '301 Migrator', 'elk-301-migrator' ),
        'manage_options',
        'elk-301-migrator',
        'elk_301_migrator_render_page'
    );
} );

add_action( 'admin_post_elk_301_migrator_scan', 'elk_301_migrator_handle_scan' );
add_action( 'admin_post_elk_301_migrator_save_targets', 'elk_301_migrator_handle_save_targets' );
add_action( 'admin_post_elk_301_migrator_import', 'elk_301_migrator_handle_import' );
add_action( 'admin_post_elk_301_migrator_export', function () {
    $format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
    elk_301_migrator_stream_export( $format );
} );

function elk_301_migrator_handle_scan(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'elk-301-migrator' ), 403 );
    }
    check_admin_referer( 'elk_301_migrator_scan' );

    $filters = [
        'attachment_after'       => elk_301_migrator_sanitize_month( $_POST['attachment_after'] ?? '' ),
        'attachment_before'      => elk_301_migrator_sanitize_month( $_POST['attachment_before'] ?? '' ),
        'attachment_extensions'  => elk_301_migrator_parse_extensions( $_POST['attachment_extensions'] ?? '' ),
    ];

    elk_301_migrator_run_scan( $filters );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'elk-301-migrator', 'scanned' => '1' ],
        admin_url( 'tools.php' )
    ) );
    exit;
}

function elk_301_migrator_handle_save_targets(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'elk-301-migrator' ), 403 );
    }
    check_admin_referer( 'elk_301_migrator_save_targets' );

    $payload = isset( $_POST['payload'] ) ? wp_unslash( (string) $_POST['payload'] ) : '';
    $decoded = json_decode( $payload, true );
    $map     = [];

    if ( is_array( $decoded ) ) {
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['source'] ) ) {
                continue;
            }
            $source = esc_url_raw( (string) $entry['source'] );
            if ( $source === '' ) {
                continue;
            }
            $map[ $source ] = array_key_exists( 'target', $entry ) ? (string) $entry['target'] : null;
        }
    }

    $changed = elk_301_migrator_save_targets( $map );
    $submitted = count( $map );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'elk-301-migrator', 'saved' => (int) $changed, 'submitted' => (int) $submitted ],
        admin_url( 'tools.php' )
    ) );
    exit;
}

function elk_301_migrator_handle_import(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'elk-301-migrator' ), 403 );
    }
    check_admin_referer( 'elk_301_migrator_import' );

    $redirect_base = [ 'page' => 'elk-301-migrator' ];

    if ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
        wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'import_error' => 'no_file' ] ), admin_url( 'tools.php' ) ) );
        exit;
    }

    if ( ! empty( $_FILES['import_file']['error'] ) ) {
        wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'import_error' => 'upload' ] ), admin_url( 'tools.php' ) ) );
        exit;
    }

    $raw     = file_get_contents( $_FILES['import_file']['tmp_name'] );
    $decoded = json_decode( (string) $raw, true );

    if ( ! is_array( $decoded ) ) {
        wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'import_error' => 'invalid_json' ] ), admin_url( 'tools.php' ) ) );
        exit;
    }

    $scan = elk_301_migrator_get_scan();
    if ( ! $scan ) {
        wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'import_error' => 'no_scan' ] ), admin_url( 'tools.php' ) ) );
        exit;
    }

    $source_lookup = [];
    foreach ( $scan['groups'] as $items ) {
        foreach ( $items as $item ) {
            $rel                                       = elk_301_migrator_to_relative( $item['url'] );
            $source_lookup[ $rel ]                     = $item['url'];
            $source_lookup[ rawurldecode( $rel ) ]     = $item['url'];
            $source_lookup[ $item['url'] ]             = $item['url'];
            $encoded                                   = esc_url_raw( $item['url'] );
            if ( $encoded !== '' ) {
                $source_lookup[ $encoded ]             = $item['url'];
            }
        }
    }

    $overwrite   = ! empty( $_POST['overwrite'] );
    $current     = elk_301_migrator_get_targets();
    $map         = [];
    $total       = 0;
    $matched     = 0;
    $unmatched   = 0;
    $empty       = 0;
    $skipped_set = 0;

    foreach ( $decoded as $entry ) {
        if ( ! is_array( $entry ) || ! isset( $entry['source'] ) ) {
            continue;
        }
        $total++;

        $source_raw = (string) $entry['source'];
        $target     = isset( $entry['target'] ) ? (string) $entry['target'] : '';

        if ( trim( $target ) === '' ) {
            $empty++;
            continue;
        }

        $absolute = $source_lookup[ $source_raw ] ?? null;
        if ( $absolute === null ) {
            $unmatched++;
            continue;
        }

        if ( ! $overwrite && ! empty( $current[ $absolute ] ) ) {
            $skipped_set++;
            continue;
        }

        $map[ $absolute ] = $target;
        $matched++;
    }

    if ( $total === 0 ) {
        wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [ 'import_error' => 'empty_payload' ] ), admin_url( 'tools.php' ) ) );
        exit;
    }

    $changed = elk_301_migrator_save_targets( $map );

    wp_safe_redirect( add_query_arg( array_merge( $redirect_base, [
        'imported'     => (int) $changed,
        'matched'      => (int) $matched,
        'unmatched'    => (int) $unmatched,
        'empty'        => (int) $empty,
        'skipped_set'  => (int) $skipped_set,
        'total'        => (int) $total,
    ] ), admin_url( 'tools.php' ) ) );
    exit;
}

function elk_301_migrator_sanitize_month( $value ): string {
    $value = sanitize_text_field( wp_unslash( (string) $value ) );
    if ( $value === '' ) {
        return '';
    }
    if ( preg_match( '/^\d{4}-\d{2}(-\d{2})?$/', $value ) ) {
        return $value;
    }
    return '';
}

/**
 * @return array<int, string>
 */
function elk_301_migrator_parse_extensions( $value ): array {
    $value = sanitize_text_field( wp_unslash( (string) $value ) );
    if ( $value === '' ) {
        return [];
    }
    $parts = preg_split( '/[\s,]+/', $value );
    $out   = [];
    foreach ( $parts as $part ) {
        $part = strtolower( ltrim( $part, '.' ) );
        if ( $part !== '' && preg_match( '/^[a-z0-9]+$/', $part ) ) {
            $out[] = $part;
        }
    }
    return array_values( array_unique( $out ) );
}

function elk_301_migrator_render_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $scan    = elk_301_migrator_get_scan();
    $targets = elk_301_migrator_get_targets();

    $filters = $scan['filters'] ?? [
        'attachment_after'      => '',
        'attachment_before'     => '',
        'attachment_extensions' => [],
    ];

    $group_labels = [
        'front'       => __( 'Front page & blog', 'elk-301-migrator' ),
        'post_types'  => __( 'Posts, pages & custom post types', 'elk-301-migrator' ),
        'taxonomies'  => __( 'Taxonomy terms', 'elk-301-migrator' ),
        'archives'    => __( 'Post type archives', 'elk-301-migrator' ),
        'authors'     => __( 'Author archives', 'elk-301-migrator' ),
        'attachments' => __( 'Attachments', 'elk-301-migrator' ),
    ];

    $post_url   = admin_url( 'admin-post.php' );
    $scan_nonce = wp_create_nonce( 'elk_301_migrator_scan' );
    $save_nonce = wp_create_nonce( 'elk_301_migrator_save_targets' );
    $export_nonce = wp_create_nonce( 'elk_301_migrator_export' );

    $total       = 0;
    $with_target = 0;
    if ( $scan ) {
        foreach ( $scan['groups'] as $items ) {
            foreach ( $items as $item ) {
                $total++;
                if ( elk_301_migrator_lookup_target( $item['url'], $targets ) !== '' ) {
                    $with_target++;
                }
            }
        }
    }

    $row_index = 0;
    ?>
    <div class="wrap">
        <style>
            .elk-301-unmapped { background-color: #fff4e5 !important; }
            .elk-301-unmapped td { border-left: 3px solid #dba617; }
            .elk-301-identity { background-color: #fcebea !important; }
            .elk-301-identity td { border-left: 3px solid #d63638; }
        </style>
        <h1><?php esc_html_e( 'ELK 301 Migrator', 'elk-301-migrator' ); ?></h1>

        <?php if ( isset( $_GET['saved'] ) ) :
            $saved     = (int) $_GET['saved'];
            $submitted = isset( $_GET['submitted'] ) ? (int) $_GET['submitted'] : $saved;
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(
                    esc_html__( '%1$d target(s) updated out of %2$d submitted.', 'elk-301-migrator' ),
                    $saved,
                    $submitted
                ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['scanned'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Scan complete.', 'elk-301-migrator' ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['imported'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(
                    esc_html__( 'Import: %1$d saved out of %2$d rows — %3$d matched, %4$d unmatched source, %5$d empty target, %6$d skipped (target already set).', 'elk-301-migrator' ),
                    (int) $_GET['imported'],
                    isset( $_GET['total'] ) ? (int) $_GET['total'] : 0,
                    isset( $_GET['matched'] ) ? (int) $_GET['matched'] : 0,
                    isset( $_GET['unmatched'] ) ? (int) $_GET['unmatched'] : 0,
                    isset( $_GET['empty'] ) ? (int) $_GET['empty'] : 0,
                    isset( $_GET['skipped_set'] ) ? (int) $_GET['skipped_set'] : 0
                ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['import_error'] ) ) :
            $messages = [
                'no_file'       => __( 'No file selected.', 'elk-301-migrator' ),
                'upload'        => __( 'Upload failed.', 'elk-301-migrator' ),
                'invalid_json'  => __( 'File is not valid JSON.', 'elk-301-migrator' ),
                'no_scan'       => __( 'Run a scan before importing.', 'elk-301-migrator' ),
                'empty_payload' => __( 'The JSON file contained no valid entries (expected an array of objects with a "source" key).', 'elk-301-migrator' ),
            ];
            $code = sanitize_key( wp_unslash( $_GET['import_error'] ) );
            $msg  = $messages[ $code ] ?? __( 'Import failed.', 'elk-301-migrator' );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( $msg ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( $post_url ); ?>">
            <input type="hidden" name="action" value="elk_301_migrator_scan" />
            <?php wp_nonce_field( 'elk_301_migrator_scan' ); ?>

            <h2><?php esc_html_e( 'Scan', 'elk-301-migrator' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="elk301_after"><?php esc_html_e( 'Attachments uploaded after', 'elk-301-migrator' ); ?></label></th>
                    <td>
                        <input type="month" id="elk301_after" name="attachment_after" value="<?php echo esc_attr( $filters['attachment_after'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave empty for no lower bound. Format: YYYY-MM.', 'elk-301-migrator' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="elk301_before"><?php esc_html_e( 'Attachments uploaded before', 'elk-301-migrator' ); ?></label></th>
                    <td>
                        <input type="month" id="elk301_before" name="attachment_before" value="<?php echo esc_attr( $filters['attachment_before'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave empty for no upper bound. Format: YYYY-MM.', 'elk-301-migrator' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="elk301_ext"><?php esc_html_e( 'Attachment extensions', 'elk-301-migrator' ); ?></label></th>
                    <td>
                        <input type="text" id="elk301_ext" name="attachment_extensions" class="regular-text" value="<?php echo esc_attr( implode( ', ', $filters['attachment_extensions'] ?? [] ) ); ?>" placeholder="pdf, jpg, png" />
                        <p class="description"><?php esc_html_e( 'Comma-separated list, e.g. pdf, jpg, png. Leave empty to include all attachment types.', 'elk-301-migrator' ); ?></p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Run scan', 'elk-301-migrator' ); ?></button>
                <?php if ( $scan ) : ?>
                    <span class="description" style="margin-left:1em;">
                        <?php printf(
                            esc_html__( 'Last scan: %s (%d URLs, %d with target).', 'elk-301-migrator' ),
                            esc_html( wp_date( 'Y-m-d H:i', (int) $scan['scanned_at'] ) ),
                            (int) $total,
                            (int) $with_target
                        ); ?>
                    </span>
                <?php endif; ?>
            </p>
        </form>

        <?php if ( ! $scan ) : ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e( 'Run a scan to populate the redirection table.', 'elk-301-migrator' ); ?></p>
            </div>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <hr />

        <h2><?php esc_html_e( 'Download', 'elk-301-migrator' ); ?></h2>
        <p><?php esc_html_e( 'Exports include whichever targets you have saved so far. Rows without a target export with an empty cell (CSV / JSON) or the NEW_URL placeholder (htaccess / nginx).', 'elk-301-migrator' ); ?></p>

        <p>
            <label style="margin-right:1.5em;">
                <input type="checkbox" id="elk-301-only-mapped" />
                <?php esc_html_e( 'Exclude rows without a target', 'elk-301-migrator' ); ?>
            </label>
            <label style="margin-right:1.5em;">
                <input type="checkbox" id="elk-301-skip-identity" checked />
                <?php esc_html_e( 'Exclude rows where source = target (avoids redirect loops)', 'elk-301-migrator' ); ?>
            </label>
            <label>
                <input type="checkbox" id="elk-301-no-comments" />
                <?php esc_html_e( 'Exclude comments (htaccess / nginx only)', 'elk-301-migrator' ); ?>
            </label>
        </p>

        <p>
            <?php foreach ( [ 'csv' => 'CSV', 'htaccess' => '.htaccess', 'nginx' => 'Nginx', 'json' => 'JSON' ] as $format => $label ) : ?>
                <a class="button elk-301-download"
                   data-base="<?php echo esc_attr( add_query_arg( [
                       'action'   => 'elk_301_migrator_export',
                       'format'   => $format,
                       '_wpnonce' => $export_nonce,
                   ], $post_url ) ); ?>"
                   href="<?php echo esc_url( add_query_arg( [
                       'action'   => 'elk_301_migrator_export',
                       'format'   => $format,
                       '_wpnonce' => $export_nonce,
                   ], $post_url ) ); ?>">
                    <?php echo esc_html( sprintf( __( 'Download %s', 'elk-301-migrator' ), $label ) ); ?>
                </a>
            <?php endforeach; ?>
        </p>

        <script>
        (function () {
            var onlyMapped   = document.getElementById('elk-301-only-mapped');
            var skipIdentity = document.getElementById('elk-301-skip-identity');
            var noComments   = document.getElementById('elk-301-no-comments');
            var buttons      = document.querySelectorAll('.elk-301-download');
            if (!onlyMapped || !skipIdentity || !noComments || !buttons.length) { return; }

            function updateLinks() {
                buttons.forEach(function (btn) {
                    var url = btn.getAttribute('data-base');
                    if (onlyMapped.checked)   { url += '&only_mapped=1'; }
                    if (skipIdentity.checked) { url += '&skip_identity=1'; }
                    if (noComments.checked)   { url += '&no_comments=1'; }
                    btn.setAttribute('href', url);
                });
            }
            [onlyMapped, skipIdentity, noComments].forEach(function (el) {
                el.addEventListener('change', updateLinks);
            });
            updateLinks();
        })();
        </script>

        <hr />

        <h2><?php esc_html_e( 'Import', 'elk-301-migrator' ); ?></h2>
        <p><?php esc_html_e( 'Upload a JSON file previously exported from this plugin. Only rows whose source URL exists in the current scan are imported.', 'elk-301-migrator' ); ?></p>

        <form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="elk_301_migrator_import" />
            <?php wp_nonce_field( 'elk_301_migrator_import' ); ?>
            <p>
                <input type="file" name="import_file" accept=".json,application/json" required />
            </p>
            <p>
                <label>
                    <input type="checkbox" name="overwrite" value="1" />
                    <?php esc_html_e( 'Overwrite existing targets (otherwise only rows with no current target are filled)', 'elk-301-migrator' ); ?>
                </label>
            </p>
            <p>
                <button type="submit" class="button"><?php esc_html_e( 'Import JSON', 'elk-301-migrator' ); ?></button>
            </p>
        </form>

        <hr />

        <h2><?php esc_html_e( 'Target URLs', 'elk-301-migrator' ); ?></h2>
        <p><?php esc_html_e( 'Fill in the target column. Site-relative paths (/new-page) or absolute URLs (https://example.com/page) are both accepted. Empty values remove the saved mapping.', 'elk-301-migrator' ); ?></p>

        <form method="post" action="<?php echo esc_url( $post_url ); ?>" id="elk-301-targets-form">
            <input type="hidden" name="action" value="elk_301_migrator_save_targets" />
            <input type="hidden" name="payload" id="elk-301-payload" value="[]" />
            <?php wp_nonce_field( 'elk_301_migrator_save_targets' ); ?>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save targets', 'elk-301-migrator' ); ?></button>
                <span class="description" style="margin-left:1em;"><?php esc_html_e( 'Only rows you actually edit are submitted — safe for large sites.', 'elk-301-migrator' ); ?></span>
            </p>

            <?php foreach ( $group_labels as $key => $heading ) :
                if ( empty( $scan['groups'][ $key ] ) ) {
                    continue;
                }
                ?>
                <h3><?php echo esc_html( $heading ); ?> <span class="count">(<?php echo count( $scan['groups'][ $key ] ); ?>)</span></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:35%"><?php esc_html_e( 'Source URL', 'elk-301-migrator' ); ?></th>
                            <th style="width:35%"><?php esc_html_e( 'Target URL', 'elk-301-migrator' ); ?></th>
                            <th style="width:15%"><?php esc_html_e( 'Type', 'elk-301-migrator' ); ?></th>
                            <th><?php esc_html_e( 'Label', 'elk-301-migrator' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $scan['groups'][ $key ] as $item ) :
                        $current   = elk_301_migrator_lookup_target( $item['url'], $targets );
                        $row_class = '';
                        if ( $current === '' ) {
                            $row_class = 'elk-301-unmapped';
                        } elseif ( elk_301_migrator_is_identity( $item['url'], $current ) ) {
                            $row_class = 'elk-301-identity';
                        }
                        ?>
                        <tr class="<?php echo esc_attr( $row_class ); ?>">
                            <td>
                                <code><?php echo esc_html( elk_301_migrator_to_relative( $item['url'] ) ); ?></code>
                            </td>
                            <td>
                                <input
                                    type="text"
                                    class="regular-text code elk-301-target"
                                    style="width:100%;"
                                    data-source="<?php echo esc_attr( $item['url'] ); ?>"
                                    data-source-rel="<?php echo esc_attr( elk_301_migrator_to_relative( $item['url'] ) ); ?>"
                                    data-initial="<?php echo esc_attr( $current ); ?>"
                                    value="<?php echo esc_attr( $current ); ?>"
                                    placeholder="/new-path or https://example.com/new-path" />
                            </td>
                            <td><?php echo esc_html( $item['type'] ); ?></td>
                            <td><?php echo esc_html( $item['label'] ); ?></td>
                        </tr>
                        <?php $row_index++; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save targets', 'elk-301-migrator' ); ?></button></p>
        </form>

        <script>
        (function () {
            var form = document.getElementById('elk-301-targets-form');
            if (!form) { return; }

            var homeUrl = <?php echo wp_json_encode( home_url() ); ?>;

            function strip(str) { return str.replace(/\/+$/, ''); }

            function toRelative(url) {
                if (!url) { return ''; }
                if (url.charAt(0) === '/') { return url; }
                if (url.indexOf(homeUrl) === 0) {
                    var rel = url.slice(homeUrl.length);
                    return rel === '' ? '/' : rel;
                }
                return null;
            }

            function classify(input) {
                var row = input.closest('tr');
                if (!row) { return; }
                row.classList.remove('elk-301-unmapped', 'elk-301-identity');

                var target = input.value.trim();
                if (target === '') {
                    row.classList.add('elk-301-unmapped');
                    return;
                }
                var sourceRel = input.getAttribute('data-source-rel') || '';
                var targetRel = toRelative(target);
                if (targetRel !== null && strip(targetRel) === strip(sourceRel)) {
                    row.classList.add('elk-301-identity');
                }
            }

            var inputs = form.querySelectorAll('.elk-301-target');
            inputs.forEach(function (input) {
                input.addEventListener('input', function () { classify(input); });
            });

            form.addEventListener('submit', function () {
                var payload = [];
                inputs.forEach(function (input) {
                    var current = input.value.trim();
                    var initial = (input.getAttribute('data-initial') || '').trim();
                    if (current === initial) { return; }
                    payload.push({
                        source: input.getAttribute('data-source'),
                        target: current
                    });
                });
                document.getElementById('elk-301-payload').value = JSON.stringify(payload);
            });
        })();
        </script>
    </div>
    <?php
}
