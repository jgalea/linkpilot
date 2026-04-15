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

    const USER_AGENT  = 'LinkPilot Scanner (WordPress)';
    const TIMEOUT     = 15;

    public static function check_batch( array $urls ) {
        if ( empty( $urls ) ) {
            return array();
        }

        $requests = array();
        foreach ( $urls as $url ) {
            $requests[ $url ] = array(
                'url'     => $url,
                'type'    => 'HEAD',
                'headers' => array( 'User-Agent' => self::USER_AGENT ),
            );
        }

        $options = array(
            'timeout'   => self::TIMEOUT,
            'redirects' => 5,
            'verify'    => false,
            'useragent' => self::USER_AGENT,
        );

        $results  = array();
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

            // Many servers reject HEAD with 405/403/400 — retry with GET for those.
            if ( in_array( $code, array( 400, 403, 405, 501 ), true ) ) {
                $fallback[] = $url;
                continue;
            }

            $results[ $url ] = self::classify( $code );
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
                        $results[ $url ] = self::classify( (int) $response->status_code );
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

    private static function classify( $code ) {
        if ( $code >= 200 && $code < 300 ) {
            return array( 'status' => 'healthy', 'code' => $code, 'error' => '' );
        }
        if ( $code >= 300 && $code < 400 ) {
            return array( 'status' => 'redirect', 'code' => $code, 'error' => '' );
        }
        if ( $code >= 400 && $code < 500 ) {
            return array( 'status' => 'broken', 'code' => $code, 'error' => '' );
        }
        if ( $code >= 500 ) {
            return array( 'status' => 'server_error', 'code' => $code, 'error' => '' );
        }
        return array( 'status' => 'unknown', 'code' => $code, 'error' => '' );
    }

    private static function result_error( $message ) {
        return array(
            'status' => 'error',
            'code'   => 0,
            'error'  => substr( $message, 0, 500 ),
        );
    }
}
