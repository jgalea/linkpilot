<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Click webhook.
 *
 * POSTs a JSON payload to a user-configured URL after every click. Delivery
 * is scheduled via Action Scheduler / WP-Cron single event to avoid adding
 * latency to the redirect response.
 *
 * Payload shape:
 *   {
 *     "link_id":    123,
 *     "slug":       "example",
 *     "destination":"https://example.com/?utm_source=...",
 *     "clicked_at": "2026-04-15T18:20:00Z",
 *     "referrer":   "https://referer.example/",
 *     "country":    "ES",
 *     "is_bot":     false,
 *     "site":       "https://example.com"
 *   }
 *
 * If a signing secret is configured, a `X-LinkPilot-Signature` header is
 * attached: "sha256=" + hex_hmac_sha256(body, secret).
 */
class LP_Webhook {

    const CRON_HOOK = 'lp_webhook_deliver';

    public static function init() {
        add_action( 'lp_after_click', array( __CLASS__, 'enqueue' ), 10, 2 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'deliver' ), 10, 1 );
    }

    /**
     * Schedule delivery for a click.
     *
     * @param LP_Link $link
     * @param string  $destination
     */
    public static function enqueue( $link, $destination ) {
        $url = trim( (string) get_option( 'lp_webhook_url', '' ) );
        if ( '' === $url ) {
            return;
        }

        $send_bots = get_option( 'lp_webhook_send_bots', 'no' ) === 'yes';
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_bot     = class_exists( 'LP_Bot_Detector' ) ? LP_Bot_Detector::is_bot( $user_agent ) : false;
        if ( $is_bot && ! $send_bots ) {
            return;
        }

        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

        $payload = array(
            'link_id'     => $link->get_id(),
            'slug'        => method_exists( $link, 'get_slug' ) ? $link->get_slug() : '',
            'destination' => (string) $destination,
            'clicked_at'  => gmdate( 'c' ),
            'referrer'    => $referrer,
            'country'     => self::detect_country_code(),
            'is_bot'      => (bool) $is_bot,
            'site'        => home_url( '/' ),
        );

        // Schedule a single event a second in the future. WP-Cron will pick it up
        // on the next request cycle; the user doesn't wait for delivery.
        wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $payload ) );
    }

    /**
     * Cron callback: actually POST the webhook.
     *
     * @param array $payload
     */
    public static function deliver( $payload ) {
        $url = trim( (string) get_option( 'lp_webhook_url', '' ) );
        if ( '' === $url || ! is_array( $payload ) ) {
            return;
        }

        $body    = wp_json_encode( $payload );
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'LinkPilot Webhook/1.0 (+https://linkpilothq.com/)',
        );

        $secret = trim( (string) get_option( 'lp_webhook_secret', '' ) );
        if ( '' !== $secret ) {
            $headers['X-LinkPilot-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
        }

        wp_remote_post( $url, array(
            'timeout' => 4,
            'headers' => $headers,
            'body'    => $body,
            // Fire-and-forget semantics — we don't retry inside the request.
            'blocking' => true,
        ) );
    }

    private static function detect_country_code() {
        $headers = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' );
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                return strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
            }
        }
        return '';
    }
}
