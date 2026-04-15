<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * HTTP status checker using parallel HEAD requests.
 *
 * Uses WordPress's WpOrg\Requests\Requests::request_multiple() to check up to
 * N URLs concurrently. Falls back to GET for hosts that reject HEAD.
 */
class LP_Scanner_Checker {

    // Browser-ish UA. Many destinations (Cloudflare, Akamai, Tripadvisor, Statista, etc.)
    // 403/429 anything that looks like a bot. Identifying as a real browser
    // dramatically reduces false positives.
    const USER_AGENT  = 'Mozilla/5.0 (compatible; LinkPilotScanner/1.0; +https://linkpilothq.com/scanner)';
    const TIMEOUT     = 20;

    public static function check_batch( array $urls ) {
        if ( empty( $urls ) ) {
            return array();
        }

        $results = array();

        // Pass 1: hand embed-provider URLs to the specialized oEmbed checker.
        // HTTP HEAD returns 200 for removed YouTube/Vimeo videos because the
        // page still renders a "video unavailable" message; oEmbed gives the
        // correct broken/not-broken signal.
        $http_urls = array();
        foreach ( $urls as $url ) {
            if ( LP_Scanner_Embed_Checker::matches( $url ) ) {
                $r = LP_Scanner_Embed_Checker::check( $url );
                if ( is_array( $r ) ) {
                    $results[ $url ] = $r;
                    continue;
                }
            }
            $http_urls[] = $url;
        }

        if ( empty( $http_urls ) ) {
            return $results;
        }

        // Rate limit: take a token per host before queueing its request. This
        // paces us at ~3 req/sec per host with 0.5s minimum interval so we
        // don't hammer a single destination when many broken URLs share it.
        $limiter  = new LP_Scanner_Rate_Limiter();
        $requests = array();
        foreach ( $http_urls as $url ) {
            $host = LP_Scanner_Rate_Limiter::host_of( $url );
            if ( $host ) {
                $limiter->take( $host );
            }
            $requests[ $url ] = array(
                'url'     => $url,
                'type'    => 'HEAD',
                'headers' => array( 'User-Agent' => self::USER_AGENT ),
            );
        }
        $urls = $http_urls; // so the existing loops below only see HTTP URLs

        $options = array(
            'timeout'   => self::TIMEOUT,
            'redirects' => 5,
            'verify'    => false,
            'useragent' => self::USER_AGENT,
        );

        $fallback = array();

        try {
            $responses = WpOrg\Requests\Requests::request_multiple( $requests, $options );
        } catch ( \Throwable $e ) {
            foreach ( $urls as $url ) {
                $results[ $url ] = self::result_error( $e->getMessage() );
            }
            return $results;
        }

        foreach ( $responses as $url => $response ) {
            if ( $response instanceof \WpOrg\Requests\Exception ) {
                $results[ $url ] = self::result_error( $response->getMessage() );
                continue;
            }
            if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
                $results[ $url ] = self::result_error( 'Invalid response' );
                continue;
            }

            $code = (int) $response->status_code;

            // Many servers reject HEAD with these codes — retry with GET to confirm.
            if ( in_array( $code, array( 400, 403, 405, 429, 501 ), true ) ) {
                $fallback[] = $url;
                continue;
            }

            $final = isset( $response->url ) ? (string) $response->url : '';
            $redir = isset( $response->redirects ) ? (int) $response->redirects : 0;
            $results[ $url ] = self::classify( $code, $final === $url ? '' : $final, $redir );
        }

        // GET fallback pass (smaller, also parallel).
        if ( ! empty( $fallback ) ) {
            $get_requests = array();
            foreach ( $fallback as $url ) {
                $get_requests[ $url ] = array(
                    'url'     => $url,
                    'type'    => 'GET',
                    'headers' => array(
                        'User-Agent' => self::USER_AGENT,
                        'Range'      => 'bytes=0-0', // ask only for the first byte
                    ),
                );
            }
            try {
                $responses = WpOrg\Requests\Requests::request_multiple( $get_requests, $options );
                foreach ( $responses as $url => $response ) {
                    if ( $response instanceof \WpOrg\Requests\Exception ) {
                        $results[ $url ] = self::result_error( $response->getMessage() );
                    } elseif ( is_object( $response ) && isset( $response->status_code ) ) {
                        $final = isset( $response->url ) ? (string) $response->url : '';
                        $redir = isset( $response->redirects ) ? (int) $response->redirects : 0;
                        $results[ $url ] = self::classify( (int) $response->status_code, $final === $url ? '' : $final, $redir );
                    } else {
                        $results[ $url ] = self::result_error( 'Invalid response' );
                    }
                }
            } catch ( \Throwable $e ) {
                foreach ( $fallback as $url ) {
                    if ( ! isset( $results[ $url ] ) ) {
                        $results[ $url ] = self::result_error( $e->getMessage() );
                    }
                }
            }
        }

        // Anything left unchecked is an error (shouldn't happen but safe).
        foreach ( $urls as $url ) {
            if ( ! isset( $results[ $url ] ) ) {
                $results[ $url ] = self::result_error( 'No response' );
            }
        }

        return $results;
    }

    private static function classify( $code, $final_url = '', $redirect_count = 0 ) {
        $base = array(
            'final_url'      => $final_url,
            'redirect_count' => (int) $redirect_count,
        );
        if ( $code >= 200 && $code < 300 ) {
            return $base + array( 'status' => 'healthy', 'code' => $code, 'error' => '' );
        }
        if ( $code >= 300 && $code < 400 ) {
            return $base + array( 'status' => 'redirect', 'code' => $code, 'error' => '' );
        }
        // Anti-bot / refused: 401 auth required, 402/403/429 typically Cloudflare or
        // similar refusing automated requests. The link is fine for human visitors;
        // we just can't verify it. Tracked separately from real broken (404/410).
        if ( in_array( $code, array( 401, 402, 403, 429, 451, 460, 511, 520, 521, 522, 523, 524, 525, 526, 527, 999 ), true ) ) {
            return $base + array( 'status' => 'blocked', 'code' => $code, 'error' => 'Destination refused automated check' );
        }
        if ( $code >= 400 && $code < 500 ) {
            return $base + array( 'status' => 'broken', 'code' => $code, 'error' => '' );
        }
        if ( $code >= 500 ) {
            return $base + array( 'status' => 'server_error', 'code' => $code, 'error' => '' );
        }
        return $base + array( 'status' => 'unknown', 'code' => $code, 'error' => '' );
    }

    private static function result_error( $message ) {
        return array(
            'status'         => 'error',
            'code'           => 0,
            'error'          => substr( $message, 0, 500 ),
            'final_url'      => '',
            'redirect_count' => 0,
        );
    }
}
