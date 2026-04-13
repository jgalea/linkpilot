<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_AI_Client {

    public static function query( $system_prompt, $user_message ) {
        $provider = get_option( 'lpp_ai_provider', 'openai' );
        $api_key  = get_option( 'lpp_ai_api_key', '' );

        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', __( 'No AI API key configured. Go to LinkPilot Pro settings.', 'linkpilot-pro' ) );
        }

        switch ( $provider ) {
            case 'anthropic':
                return self::query_anthropic( $api_key, $system_prompt, $user_message );
            case 'openai':
            default:
                return self::query_openai( $api_key, $system_prompt, $user_message );
        }
    }

    private static function query_openai( $api_key, $system_prompt, $user_message ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'    => 'gpt-4o-mini',
                'messages' => array(
                    array( 'role' => 'system', 'content' => $system_prompt ),
                    array( 'role' => 'user', 'content' => $user_message ),
                ),
                'temperature' => 0.3,
                'max_tokens'  => 1024,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'API error ' . $code;
            return new WP_Error( 'openai_error', $msg );
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }

    private static function query_anthropic( $api_key, $system_prompt, $user_message ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1024,
                'system'     => $system_prompt,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $user_message ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'API error ' . $code;
            return new WP_Error( 'anthropic_error', $msg );
        }

        return $body['content'][0]['text'] ?? '';
    }
}
