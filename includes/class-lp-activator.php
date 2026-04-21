<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Activator {

    public static function activate() {
        LP_Clicks_DB::create_table();
        LP_Scanner_DB::create_table();
        LP_Redirects_DB::create_table();
        LP_404_Log_DB::create_table();
        self::set_default_options();
        LP_Link_Health::schedule();
        set_transient( 'lp_activation_redirect', true, 30 );
        flush_rewrite_rules();
    }

    private static function set_default_options() {
        $defaults = array(
            'lp_link_prefix'           => 'go',
            'lp_redirect_type'         => '307',
            'lp_nofollow'              => 'yes',
            'lp_sponsored'             => 'no',
            'lp_new_window'            => 'yes',
            'lp_pass_query_str'        => 'no',
            'lp_enable_click_tracking' => 'yes',
            'lp_disable_ip_collection' => 'yes',
            'lp_enable_link_fixer'     => 'yes',
            'lp_excluded_bots'         => implode( "\n", self::default_bot_list() ),
        );
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
        add_option( 'lp_db_version', '1.0' );
        add_option( 'lp_installed_version', LP_VERSION );
    }

    public static function default_bot_list() {
        return array(
            // Generic catch-alls
            'bot',
            'crawler',
            'spider',
            'scraper',
            // Search engines
            'googlebot',
            'google-extended',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'applebot',
            'amazonbot',
            'mojeekbot',
            'seznambot',
            'qwantify',
            'coccocbot',
            'naverbot',
            // SEO / backlink tools
            'ahrefs',
            'semrush',
            'mj12bot',
            'dotbot',
            'petalbot',
            'screaming frog',
            // AI / LLM crawlers
            'gptbot',
            'chatgpt-user',
            'oai-searchbot',
            'claudebot',
            'claude-web',
            'anthropic-ai',
            'perplexitybot',
            'perplexity-user',
            'bytespider',
            'ccbot',
            'cohere-ai',
            'meta-externalagent',
            'diffbot',
            'youbot',
            // Social previewers
            'facebookexternalhit',
            'facebot',
            'twitterbot',
            'linkedinbot',
            'whatsapp',
            'telegrambot',
            'discordbot',
            'slackbot',
            'redditbot',
            'pinterestbot',
            'vkshare',
            'tumblr',
            // Archivers
            'ia_archiver',
            'archive.org_bot',
            'wayback',
            // Uptime / monitoring
            'pingdom',
            'uptimerobot',
            'statuscake',
            'site24x7',
            'newrelic',
            'datadog',
            'checkly',
            'better uptime',
            // HTTP client libraries / scripts
            'python-requests',
            'python-urllib',
            'aiohttp',
            'curl/',
            'wget/',
            'go-http-client',
            'node-fetch',
            'axios',
            'got (',
            'okhttp',
            'scrapy',
            'postmanruntime',
            'insomnia',
            'libwww-perl',
            'lwp::',
            'lua-resty-http',
            'java/',
            'apache-httpclient',
            'httpx',
            'httpie',
            // Headless / automation
            'headlesschrome',
            'phantomjs',
            'puppeteer',
            'playwright',
            'selenium',
            // Link / health checkers
            'linkchecker',
            'linkwalker',
            'w3c-checklink',
            'xenu',
            'check_http',
            'wp-rocket',
        );
    }
}
