<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Token bucket rate limiter, per-host.
 *
 * Prevents the scanner from hammering a single destination host when many
 * broken URLs point to the same domain. Based on BLC's blcTokenBucketList
 * with a cleaner API and PSR-friendly names.
 *
 * Default policy: max 3 requests per host per second, with a 500 ms minimum
 * between consecutive hits to the same host.
 */
class LP_Scanner_Rate_Limiter {

    const MICROSECONDS_PER_SECOND = 1_000_000;
    const MAX_BUCKETS             = 200;

    /** Tokens per bucket (burst capacity). */
    private $capacity;

    /** Seconds to fully refill a bucket. */
    private $fill_time;

    /** Minimum seconds between two tokens from the same bucket. */
    private $min_interval;

    /** @var array<string,array{tokens:float,last_refill:float,last_take:float}> */
    private $buckets = array();

    public function __construct( $capacity = 3.0, $fill_time = 1.0, $min_interval = 0.5 ) {
        $this->capacity     = (float) $capacity;
        $this->fill_time    = (float) $fill_time;
        $this->min_interval = (float) $min_interval;
    }

    /**
     * Block until a token is available for this host, then consume it.
     *
     * @param string $host Host name (e.g. "example.com").
     */
    public function take( $host ) {
        $host = strtolower( trim( (string) $host ) );
        if ( $host === '' ) {
            return;
        }

        $this->ensure_bucket( $host );
        $this->wait_for_token( $host );
        $this->buckets[ $host ]['tokens']--;
        $this->buckets[ $host ]['last_take'] = microtime( true );
    }

    /**
     * Host extractor for a URL. Returns empty string if parsing fails.
     */
    public static function host_of( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        return $host ? strtolower( $host ) : '';
    }

    private function wait_for_token( $host ) {
        $now = microtime( true );

        $time_since_last = $now - $this->buckets[ $host ]['last_take'];
        $interval_wait   = max( $this->min_interval - $time_since_last, 0.0 );

        $needed     = max( 1.0 - $this->buckets[ $host ]['tokens'], 0.0 );
        $refill_wait = $needed / $this->get_fill_rate();

        $total_wait_us = (int) round( max( $interval_wait, $refill_wait ) * self::MICROSECONDS_PER_SECOND );
        if ( $total_wait_us > 0 ) {
            usleep( $total_wait_us );
        }

        $this->refill( $host );
    }

    private function refill( $host ) {
        $now            = microtime( true );
        $since_refill   = $now - $this->buckets[ $host ]['last_refill'];
        $this->buckets[ $host ]['tokens'] += $since_refill * $this->get_fill_rate();

        if ( $this->buckets[ $host ]['tokens'] > $this->capacity ) {
            $this->buckets[ $host ]['tokens'] = $this->capacity;
        }
        $this->buckets[ $host ]['last_refill'] = $now;
    }

    private function ensure_bucket( $host ) {
        if ( ! isset( $this->buckets[ $host ] ) ) {
            $this->buckets[ $host ] = array(
                'tokens'      => $this->capacity,
                'last_refill' => microtime( true ),
                'last_take'   => 0.0,
            );
        }

        // Discard oldest buckets if we exceed the cap.
        if ( count( $this->buckets ) > self::MAX_BUCKETS ) {
            array_shift( $this->buckets );
        }
    }

    private function get_fill_rate() {
        return $this->capacity / $this->fill_time;
    }
}
