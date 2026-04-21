<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * WP-CLI commands for LinkPilot.
 *
 * Usage:
 *   wp linkpilot list              List all cloaked links.
 *   wp linkpilot stats [<link>]    Show click stats for a link or site-wide.
 *   wp linkpilot health            Run the health check on all links synchronously.
 *   wp linkpilot export-clicks     Export clicks CSV to stdout or a file.
 */
class LP_CLI {

    /**
     * List all cloaked links.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, csv, json, yaml. Default: table.
     *
     * [--fields=<fields>]
     * : Limit output to specific fields. Default: id,title,slug,destination,clicks.
     *
     * ## EXAMPLES
     *
     *     wp linkpilot list
     *     wp linkpilot list --format=csv > links.csv
     */
    public function list( $args, $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        $fields = isset( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : array( 'id', 'title', 'slug', 'destination', 'clicks' );

        $posts = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $rows = array();
        foreach ( $posts as $p ) {
            $link = new LP_Link( $p->ID );
            $rows[] = array(
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'slug'        => $p->post_name,
                'destination' => $link->get_destination_url(),
                'status'      => $p->post_status,
                'clicks'      => LP_Clicks_DB::get_total_clicks( $p->ID ),
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, $fields );
    }

    /**
     * Show click stats.
     *
     * ## OPTIONS
     *
     * [<link>]
     * : Optional link ID or slug. If omitted, shows site-wide stats.
     *
     * [--days=<days>]
     * : Rolling window in days. Default: 30. Ignored if --from / --to are given.
     *
     * [--from=<from>]
     * : Start date (YYYY-MM-DD) in site timezone. Requires --to.
     *
     * [--to=<to>]
     * : End date (YYYY-MM-DD, inclusive) in site timezone. Requires --from.
     *
     * ## EXAMPLES
     *
     *     wp linkpilot stats
     *     wp linkpilot stats revolut --days=90
     *     wp linkpilot stats --from=2026-01-01 --to=2026-03-31
     *     wp linkpilot stats revolut --from=2026-04-01 --to=2026-04-21
     */
    public function stats( $args, $assoc_args ) {
        list( $from_date, $to_date, $label ) = self::parse_cli_range( $assoc_args );
        list( $from_sql, $to_sql )           = self::range_to_utc_bounds( $from_date, $to_date );
        $tz_offset                           = LP_Clicks_DB::site_tz_offset();

        if ( ! empty( $args[0] ) ) {
            $target = $args[0];
            $link   = is_numeric( $target ) ? get_post( (int) $target ) : self::find_by_slug( $target );
            if ( ! $link || 'lp_link' !== $link->post_type ) {
                WP_CLI::error( "Link not found: {$target}" );
            }

            $total   = LP_Clicks_DB::get_total_clicks( $link->ID );
            $by_day  = LP_Clicks_DB::get_clicks_by_day_range( $link->ID, $from_sql, $to_sql );
            $refs    = LP_Clicks_DB::get_top_referrers_range( $link->ID, $from_sql, $to_sql, 10 );
            $window  = 0;
            foreach ( $by_day as $r ) {
                $window += (int) $r->clicks;
            }

            WP_CLI::log( "Link: {$link->post_title} (#{$link->ID})" );
            WP_CLI::log( "Total clicks (all time):  {$total}" );
            WP_CLI::log( "Clicks {$label}:          {$window}" );

            if ( ! empty( $refs ) ) {
                WP_CLI::log( "" );
                WP_CLI::log( 'Top referrers:' );
                foreach ( $refs as $r ) {
                    WP_CLI::log( sprintf( '  %6d  %s', (int) $r->clicks, $r->referrer ) );
                }
            }
            return;
        }

        $daily = LP_Clicks_DB::get_site_clicks_by_range( $from_sql, $to_sql, $tz_offset );
        $refs  = LP_Clicks_DB::get_site_top_referrers_range( $from_sql, $to_sql, 10 );

        $total = 0;
        foreach ( $daily as $row ) {
            $total += (int) $row->clicks;
        }
        WP_CLI::log( "Site-wide, {$label}" );
        WP_CLI::log( "Total clicks (humans): {$total}" );

        if ( ! empty( $refs ) ) {
            WP_CLI::log( "" );
            WP_CLI::log( 'Top referrers:' );
            foreach ( $refs as $r ) {
                WP_CLI::log( sprintf( '  %6d  %s', (int) $r->clicks, $r->referrer ) );
            }
        }
    }

    /**
     * Resolve --from/--to/--days into a normalized [from_date, to_date, label].
     *
     * @param array $assoc_args
     * @return array{0:string,1:string,2:string}
     */
    private static function parse_cli_range( $assoc_args ) {
        $from_raw = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
        $to_raw   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';
        $is_date  = static function ( $s ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
        };

        if ( $is_date( $from_raw ) && $is_date( $to_raw ) ) {
            $from_date = $from_raw <= $to_raw ? $from_raw : $to_raw;
            $to_date   = $from_raw <= $to_raw ? $to_raw : $from_raw;
            $label     = "from {$from_date} to {$to_date}";
        } else {
            $days      = isset( $assoc_args['days'] ) ? max( 1, (int) $assoc_args['days'] ) : 30;
            $today     = self::today_local();
            $from_date = self::local_date_minus_days( $today, $days - 1 );
            $to_date   = $today;
            $label     = "last {$days} days";
        }
        return array( $from_date, $to_date, $label );
    }

    private static function today_local() {
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        return ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
    }

    private static function local_date_minus_days( $date, $days ) {
        try {
            $dt = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return $date;
        }
        return $dt->modify( '-' . (int) $days . ' days' )->format( 'Y-m-d' );
    }

    private static function range_to_utc_bounds( $from_date, $to_date ) {
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        try {
            $from = new DateTimeImmutable( $from_date . ' 00:00:00', $tz );
            $to   = ( new DateTimeImmutable( $to_date . ' 00:00:00', $tz ) )->modify( '+1 day' );
        } catch ( Exception $e ) {
            return array( gmdate( 'Y-m-d H:i:s', 0 ), gmdate( 'Y-m-d H:i:s' ) );
        }
        $utc = new DateTimeZone( 'UTC' );
        return array(
            $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
            $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
        );
    }

    /**
     * Run health check on all links.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table (default), csv, json, yaml.
     *
     * ## EXAMPLES
     *
     *     wp linkpilot health
     */
    public function health( $args, $assoc_args ) {
        if ( ! class_exists( 'LP_Link_Health' ) ) {
            WP_CLI::error( 'LP_Link_Health not available.' );
        }

        $ids = LP_Link_Health::get_all_ids();
        if ( empty( $ids ) ) {
            WP_CLI::success( 'No links to check.' );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( 'Checking', count( $ids ) );
        $rows     = array();
        foreach ( $ids as $id ) {
            $r      = LP_Link_Health::check_link( (int) $id );
            $rows[] = array(
                'id'     => (int) $id,
                'title'  => get_the_title( (int) $id ),
                'status' => isset( $r['status'] ) ? $r['status'] : 'unknown',
                'code'   => isset( $r['code'] ) ? $r['code'] : '',
            );
            $progress->tick();
            // Small pause to be polite to destination hosts.
            usleep( 300000 );
        }
        $progress->finish();

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'title', 'status', 'code' ) );
    }

    /**
     * Export clicks to CSV.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Write to this file instead of stdout.
     *
     * ## EXAMPLES
     *
     *     wp linkpilot export-clicks > clicks.csv
     *     wp linkpilot export-clicks --file=/tmp/clicks.csv
     */
    public function export_clicks( $args, $assoc_args ) {
        $fh = null;
        if ( isset( $assoc_args['file'] ) ) {
            $path = $assoc_args['file'];
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI context, not frontend.
            $fh   = fopen( $path, 'w' );
            if ( ! $fh ) {
                WP_CLI::error( "Could not open {$path} for writing." );
            }
        }

        $write = function ( $row ) use ( $fh ) {
            $str = LP_CLI::csv_encode( $row );
            if ( $fh ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI context.
                fwrite( $fh, $str );
            } else {
                echo $str; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV already encoded.
            }
        };

        $write( array( 'id', 'link_id', 'link_slug', 'clicked_at', 'referrer', 'country_code', 'is_bot' ) );

        $offset = 0;
        $batch  = 1000;
        $slugs  = array();
        while ( true ) {
            $rows = LP_Clicks_DB::get_rows_batch( $offset, $batch );
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $r ) {
                $lid = (int) $r['link_id'];
                if ( ! isset( $slugs[ $lid ] ) ) {
                    $post            = get_post( $lid );
                    $slugs[ $lid ]   = $post ? $post->post_name : '';
                }
                $write( array(
                    $r['id'],
                    $r['link_id'],
                    $slugs[ $lid ],
                    $r['clicked_at'],
                    $r['referrer'],
                    $r['country_code'],
                    $r['is_bot'],
                ) );
            }
            if ( count( $rows ) < $batch ) {
                break;
            }
            $offset += $batch;
        }

        if ( $fh ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI context.
            fclose( $fh );
            WP_CLI::success( "Wrote clicks to {$assoc_args['file']}" );
        }
    }

    public static function csv_encode( $fields ) {
        $out = array();
        foreach ( $fields as $field ) {
            $str = (string) $field;
            if ( preg_match( '/[",\r\n]/', $str ) ) {
                $str = '"' . str_replace( '"', '""', $str ) . '"';
            }
            $out[] = $str;
        }
        return implode( ',', $out ) . "\n";
    }

    private static function find_by_slug( $slug ) {
        $posts = get_posts( array(
            'post_type'      => 'lp_link',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ) );
        return $posts ? $posts[0] : null;
    }
}

WP_CLI::add_command( 'linkpilot', 'LP_CLI' );
