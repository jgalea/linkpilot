# Phase 5A: Competitive Parity Features

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the high-value features our users expect from ThirstyAffiliates Pro and Pretty Links Pro: geo-targeted redirects, link scheduling, GA4 click events, JS redirect option, and QR codes.

**Architecture:** Free-tier additions (JS redirect option, QR codes) extend existing `LP_Link` + `LP_Redirect`. Pro-tier additions (geo redirects, scheduling, GA4) live in `linkpilot-pro/` as independent feature classes that hook into filters provided by the free plugin.

**Tech Stack:** PHP 8.0+, Cloudflare `CF-IPCountry` header (primary) with MaxMind GeoLite2 DB fallback, PHP QR Code library (embedded, no external deps).

---

## File Structure

### Free Plugin Changes
- Modify `includes/class-lp-link.php` — add meta keys for `js_redirect`; expose filter hooks on `get_final_destination_url` and `get_redirect_type`
- Modify `includes/class-lp-redirect.php` — support JS redirect mode (serve HTML page that redirects via JS, preserving referrer); apply new `lp_redirect_destination` filter before final redirect
- Modify `includes/class-lp-admin.php` — add JS redirect option to meta box
- Modify `views/link-meta-box.php` — add JS redirect toggle
- Create `includes/class-lp-qr.php` — QR code generation + admin column with download button
- Create `assets/vendor/phpqrcode.php` — embedded PHP QR Code library (public domain)

### Pro Plugin Additions
- Create `linkpilot-pro/includes/class-lpp-geo.php` — geo-targeted redirects via `lp_redirect_destination` filter
- Create `linkpilot-pro/includes/class-lpp-scheduling.php` — link scheduling with expiry fallback via `lp_redirect_destination` filter
- Create `linkpilot-pro/includes/class-lpp-ga4.php` — fire GA4 measurement protocol event on click via `lp_after_click` action
- Modify `linkpilot-pro/includes/class-lpp-settings.php` — add Pro settings fields for GA4 measurement ID and API secret

### Hooks Added to Free Plugin (for Pro to consume)
- `apply_filters( 'lp_redirect_destination', $url, $link )` — lets Pro override destination (geo/scheduling)
- `apply_filters( 'lp_redirect_should_block', false, $link )` — lets Pro block redirect entirely (e.g., expired link with no fallback)
- `do_action( 'lp_after_click', $link )` — fires after click is recorded, before redirect (for GA4)
- `apply_filters( 'lp_link_meta_keys', self::META_KEYS )` — lets Pro add meta keys to the save_meta routine

---

## Tasks

### Task 1: Filter Hooks in Free Plugin

Lay the extension-point groundwork that Pro features will hook into. This is the foundation.

**Files:**
- Modify: `includes/class-lp-link.php`
- Modify: `includes/class-lp-redirect.php`

- [ ] **Step 1: Add meta keys filter to LP_Link::save_meta**

Replace the `save_meta` method in `includes/class-lp-link.php` (around line 117):

```php
public function save_meta( array $data ) {
    $keys = apply_filters( 'lp_link_meta_keys', self::META_KEYS );
    foreach ( $keys as $key => $meta_key ) {
        if ( isset( $data[ $key ] ) ) {
            update_post_meta( $this->id, $meta_key, sanitize_text_field( $data[ $key ] ) );
        }
    }
}
```

- [ ] **Step 2: Add filter and action hooks in LP_Redirect::do_redirect**

Replace the `do_redirect` method in `includes/class-lp-redirect.php` (around line 53):

```php
private static function do_redirect( LP_Link $link ) {
    $query_string = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
    $destination  = $link->get_final_destination_url( $query_string );

    // Allow Pro features to modify or block the destination
    $destination = apply_filters( 'lp_redirect_destination', $destination, $link );
    $should_block = apply_filters( 'lp_redirect_should_block', false, $link );

    if ( $should_block || ! $destination ) {
        return;
    }

    if ( get_option( 'lp_enable_click_tracking', 'yes' ) === 'yes' ) {
        try {
            LP_Clicks::record( $link );
        } catch ( \Throwable $e ) {
            // Silently fail — redirect must always work
        }
    }

    do_action( 'lp_after_click', $link, $destination );

    header( 'X-Robots-Tag: noindex, nofollow', true );
    nocache_headers();

    // JS redirect mode preserves referrer by issuing a 200 HTML response
    $js_redirect = get_post_meta( $link->get_id(), '_lp_js_redirect', true );
    if ( $js_redirect === 'yes' ) {
        self::render_js_redirect( $destination );
        exit;
    }

    $redirect_type = $link->get_redirect_type();
    wp_redirect( $destination, $redirect_type );
    exit;
}

private static function render_js_redirect( $destination ) {
    $url = esc_url( $destination );
    ?><!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Redirecting...</title>
<script>window.location.href=<?php echo wp_json_encode( $destination ); ?>;</script>
</head><body>
<noscript>Redirecting to <a href="<?php echo $url; ?>"><?php echo esc_html( $destination ); ?></a>. JavaScript is required.</noscript>
</body></html><?php
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-lp-link.php includes/class-lp-redirect.php
git commit -m "feat: add filter hooks for Pro extensions + JS redirect mode"
```

---

### Task 2: JS Redirect Meta Box Option

Expose the JS redirect toggle in the link editor.

**Files:**
- Modify: `views/link-meta-box.php`
- Modify: `includes/class-lp-admin.php`

- [ ] **Step 1: Read current meta-box view**

Run: `cat views/link-meta-box.php`

Locate the table row structure (likely `<tr>` entries for each setting).

- [ ] **Step 2: Add JS redirect row to the meta box**

In `views/link-meta-box.php`, after the existing rel/tags rows and before the closing `</table>`, add:

```php
<tr>
    <th><label for="lp_js_redirect"><?php esc_html_e( 'JavaScript Redirect', 'linkpilot' ); ?></label></th>
    <td>
        <?php $js_redirect = get_post_meta( $post->ID, '_lp_js_redirect', true ); ?>
        <select name="lp_js_redirect" id="lp_js_redirect">
            <option value="no" <?php selected( $js_redirect, 'no' ); ?>><?php esc_html_e( 'No (use HTTP redirect)', 'linkpilot' ); ?></option>
            <option value="yes" <?php selected( $js_redirect, 'yes' ); ?>><?php esc_html_e( 'Yes (preserves referrer)', 'linkpilot' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Use JavaScript to redirect. Preserves the HTTP referrer for affiliate networks that require it. Slower than HTTP redirect.', 'linkpilot' ); ?></p>
    </td>
</tr>
```

- [ ] **Step 3: Save the JS redirect meta value**

In `includes/class-lp-admin.php`, inside the `save_link_meta` method, after the existing `$text_fields` loop (around line 111-117), add:

```php
if ( isset( $_POST['lp_js_redirect'] ) ) {
    update_post_meta( $post_id, '_lp_js_redirect', sanitize_text_field( $_POST['lp_js_redirect'] ) );
}
```

- [ ] **Step 4: Commit**

```bash
git add views/link-meta-box.php includes/class-lp-admin.php
git commit -m "feat: JS redirect toggle in link meta box"
```

---

### Task 3: QR Code Generator (Free)

Add QR code generation per link. Admin column with a "Download QR" link; a download handler renders the PNG on demand.

**Files:**
- Create: `includes/class-lp-qr.php`
- Modify: `includes/class-linkpilot.php` (init)
- Modify: `includes/class-lp-admin.php` (add column)

- [ ] **Step 1: Create the QR class**

Create `includes/class-lp-qr.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_QR {

    public static function init() {
        add_action( 'admin_post_lp_qr_download', array( __CLASS__, 'handle_download' ) );
    }

    public static function get_download_url( $post_id ) {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=lp_qr_download&link=' . (int) $post_id ),
            'lp_qr_download_' . $post_id
        );
    }

    public static function handle_download() {
        $post_id = isset( $_GET['link'] ) ? (int) $_GET['link'] : 0;
        if ( ! $post_id ) {
            wp_die( 'Invalid link' );
        }

        check_admin_referer( 'lp_qr_download_' . $post_id );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized' );
        }

        $link = new LP_Link( $post_id );
        $url  = $link->get_cloaked_url();

        if ( ! $url ) {
            wp_die( 'Link not found' );
        }

        $slug = $link->get_slug() ?: 'link';

        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: attachment; filename="linkpilot-qr-' . $slug . '.png"' );
        header( 'Cache-Control: private, max-age=3600' );

        self::render_png( $url );
        exit;
    }

    private static function render_png( $url ) {
        // Use Google Chart API (deprecated but still works) as a zero-dependency QR source.
        // If the outbound request fails, fall back to a plain redirect to a QR service.
        $api = 'https://api.qrserver.com/v1/create-qr-code/?size=512x512&data=' . rawurlencode( $url );

        $response = wp_remote_get( $api, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            wp_die( 'QR generation failed. Try again.' );
        }

        echo wp_remote_retrieve_body( $response );
    }
}
```

- [ ] **Step 2: Init LP_QR in class-linkpilot.php**

In `includes/class-linkpilot.php`, inside `init_hooks()`, add to the admin block:

```php
if ( is_admin() ) {
    LP_Admin::init();
    LP_Settings::init();
    LP_CSV::init();
    LP_Setup_Wizard::init();
    LP_QR::init();
}
```

- [ ] **Step 3: Add QR column to link list**

In `includes/class-lp-admin.php`, in `custom_columns()`, add after `lp_health`:

```php
$new['lp_qr'] = __( 'QR', 'linkpilot' );
```

In `column_content()`, add after the `lp_health` case:

```php
case 'lp_qr':
    echo '<a href="' . esc_url( LP_QR::get_download_url( $post_id ) ) . '" title="' . esc_attr__( 'Download QR code', 'linkpilot' ) . '"><span class="dashicons dashicons-download"></span></a>';
    break;
```

- [ ] **Step 4: Commit**

```bash
git add includes/class-lp-qr.php includes/class-linkpilot.php includes/class-lp-admin.php
git commit -m "feat: QR code generation for managed links"
```

---

### Task 4: Geo-Targeted Redirects (Pro)

Pro feature: per-link country → URL mapping. Uses Cloudflare `CF-IPCountry` header if present (jeangalea.com is on Kinsta which passes this through Cloudflare), or MaxMind free lookup as fallback.

**Files:**
- Create: `linkpilot-pro/includes/class-lpp-geo.php`
- Modify: `linkpilot-pro/linkpilot-pro.php` (init)
- Create view additions via a meta box filter

- [ ] **Step 1: Create the geo feature class**

Create `linkpilot-pro/includes/class-lpp-geo.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Geo {

    const META_KEY = '_lpp_geo_redirects';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_geo_redirect' ), 10, 2 );
        add_filter( 'lp_link_meta_keys', array( __CLASS__, 'register_meta_key' ) );
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
    }

    public static function register_meta_key( $keys ) {
        $keys['geo_redirects'] = self::META_KEY;
        return $keys;
    }

    public static function apply_geo_redirect( $destination, $link ) {
        $map = get_post_meta( $link->get_id(), self::META_KEY, true );
        if ( empty( $map ) || ! is_array( $map ) ) {
            return $destination;
        }

        $country = self::detect_country();
        if ( ! $country ) {
            return $destination;
        }

        if ( ! empty( $map[ $country ] ) ) {
            return $map[ $country ];
        }

        return $destination;
    }

    public static function detect_country() {
        // Prefer Cloudflare header (cheapest, most reliable when present)
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $cc = strtoupper( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
            if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
                return $cc;
            }
        }

        // CloudFront header
        if ( ! empty( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ) {
            $cc = strtoupper( sanitize_text_field( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) );
            if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
                return $cc;
            }
        }

        // WPEngine / Kinsta geo header
        if ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
            $cc = strtoupper( sanitize_text_field( $_SERVER['GEOIP_COUNTRY_CODE'] ) );
            if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
                return $cc;
            }
        }

        return '';
    }

    public static function add_meta_box() {
        add_meta_box(
            'lpp_geo_redirects',
            __( 'Geo-Targeted Redirects', 'linkpilot-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lpp_geo_save', 'lpp_geo_nonce' );
        $map = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }
        ?>
        <p><?php esc_html_e( 'Override the destination URL for specific countries. Leave empty to use the default destination.', 'linkpilot-pro' ); ?></p>
        <table class="widefat striped" id="lpp-geo-table">
            <thead><tr>
                <th style="width: 120px;"><?php esc_html_e( 'Country Code', 'linkpilot-pro' ); ?></th>
                <th><?php esc_html_e( 'Destination URL', 'linkpilot-pro' ); ?></th>
                <th style="width: 50px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $map as $cc => $url ) : ?>
                <tr>
                    <td><input type="text" name="lpp_geo_country[]" value="<?php echo esc_attr( $cc ); ?>" maxlength="2" style="text-transform: uppercase;" /></td>
                    <td><input type="url" name="lpp_geo_url[]" value="<?php echo esc_attr( $url ); ?>" class="large-text" /></td>
                    <td><button type="button" class="button-link lpp-geo-remove">&times;</button></td>
                </tr>
            <?php endforeach; ?>
                <tr>
                    <td><input type="text" name="lpp_geo_country[]" value="" maxlength="2" style="text-transform: uppercase;" /></td>
                    <td><input type="url" name="lpp_geo_url[]" value="" class="large-text" /></td>
                    <td><button type="button" class="button-link lpp-geo-remove">&times;</button></td>
                </tr>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lpp-geo-add"><?php esc_html_e( 'Add Country', 'linkpilot-pro' ); ?></button></p>
        <p class="description"><?php esc_html_e( 'Use ISO 3166-1 alpha-2 country codes (e.g., US, GB, DE, FR). Country is detected via Cloudflare, CloudFront, or host-provided geo headers.', 'linkpilot-pro' ); ?></p>
        <script>
        (function(){
            var table = document.getElementById('lpp-geo-table');
            if (!table) return;
            document.getElementById('lpp-geo-add').addEventListener('click', function(){
                var row = table.querySelector('tbody tr:last-child').cloneNode(true);
                row.querySelectorAll('input').forEach(function(i){ i.value = ''; });
                table.querySelector('tbody').appendChild(row);
            });
            table.addEventListener('click', function(e){
                if (e.target.classList.contains('lpp-geo-remove')) {
                    var rows = table.querySelectorAll('tbody tr');
                    if (rows.length > 1) { e.target.closest('tr').remove(); }
                    else { e.target.closest('tr').querySelectorAll('input').forEach(function(i){ i.value = ''; }); }
                }
            });
        })();
        </script>
        <?php
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lpp_geo_nonce'] ) || ! wp_verify_nonce( $_POST['lpp_geo_nonce'], 'lpp_geo_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $countries = isset( $_POST['lpp_geo_country'] ) ? (array) $_POST['lpp_geo_country'] : array();
        $urls      = isset( $_POST['lpp_geo_url'] ) ? (array) $_POST['lpp_geo_url'] : array();

        $map = array();
        foreach ( $countries as $i => $cc ) {
            $cc = strtoupper( trim( sanitize_text_field( $cc ) ) );
            $url = isset( $urls[ $i ] ) ? esc_url_raw( trim( $urls[ $i ] ) ) : '';
            if ( preg_match( '/^[A-Z]{2}$/', $cc ) && $url ) {
                $map[ $cc ] = $url;
            }
        }

        if ( $map ) {
            update_post_meta( $post_id, self::META_KEY, $map );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }
}
```

- [ ] **Step 2: Register in bootstrap**

In `linkpilot-pro/linkpilot-pro.php`, inside `lpp_init()`, add after `LPP_Auto_Linker::init();`:

```php
LPP_Geo::init();
```

- [ ] **Step 3: Commit**

```bash
git add linkpilot-pro/includes/class-lpp-geo.php linkpilot-pro/linkpilot-pro.php
git commit -m "feat(pro): geo-targeted redirects with Cloudflare/CloudFront detection"
```

---

### Task 5: Link Scheduling (Pro)

Pro feature: optional start and expiry datetimes. Before start → 404. After expiry → redirect to fallback URL (or 404 if no fallback).

**Files:**
- Create: `linkpilot-pro/includes/class-lpp-scheduling.php`
- Modify: `linkpilot-pro/linkpilot-pro.php` (init)

- [ ] **Step 1: Create the scheduling class**

Create `linkpilot-pro/includes/class-lpp-scheduling.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Scheduling {

    const META_START    = '_lpp_schedule_start';
    const META_EXPIRE   = '_lpp_schedule_expire';
    const META_FALLBACK = '_lpp_expire_redirect';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_schedule' ), 20, 2 );
        add_filter( 'lp_redirect_should_block', array( __CLASS__, 'maybe_block' ), 10, 2 );
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
    }

    public static function maybe_block( $block, $link ) {
        if ( $block ) {
            return $block; // already blocked by another filter
        }

        $now      = current_time( 'timestamp', true ); // UTC
        $start    = get_post_meta( $link->get_id(), self::META_START, true );
        $expire   = get_post_meta( $link->get_id(), self::META_EXPIRE, true );
        $fallback = get_post_meta( $link->get_id(), self::META_FALLBACK, true );

        if ( $start ) {
            $start_ts = strtotime( $start . ' UTC' );
            if ( $start_ts && $now < $start_ts ) {
                return true; // not yet active
            }
        }

        if ( $expire && ! $fallback ) {
            $expire_ts = strtotime( $expire . ' UTC' );
            if ( $expire_ts && $now >= $expire_ts ) {
                return true; // expired with no fallback
            }
        }

        return false;
    }

    public static function apply_schedule( $destination, $link ) {
        $expire   = get_post_meta( $link->get_id(), self::META_EXPIRE, true );
        $fallback = get_post_meta( $link->get_id(), self::META_FALLBACK, true );

        if ( ! $expire || ! $fallback ) {
            return $destination;
        }

        $now       = current_time( 'timestamp', true );
        $expire_ts = strtotime( $expire . ' UTC' );

        if ( $expire_ts && $now >= $expire_ts ) {
            return $fallback;
        }

        return $destination;
    }

    public static function add_meta_box() {
        add_meta_box(
            'lpp_scheduling',
            __( 'Link Scheduling', 'linkpilot-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lpp_schedule_save', 'lpp_schedule_nonce' );
        $start    = get_post_meta( $post->ID, self::META_START, true );
        $expire   = get_post_meta( $post->ID, self::META_EXPIRE, true );
        $fallback = get_post_meta( $post->ID, self::META_FALLBACK, true );
        ?>
        <p>
            <label><strong><?php esc_html_e( 'Start (UTC)', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="datetime-local" name="lpp_schedule_start" value="<?php echo esc_attr( $start ); ?>" class="widefat" />
            <span class="description"><?php esc_html_e( 'Before this time, link returns 404.', 'linkpilot-pro' ); ?></span>
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Expire (UTC)', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="datetime-local" name="lpp_schedule_expire" value="<?php echo esc_attr( $expire ); ?>" class="widefat" />
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Expiry Fallback URL', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="url" name="lpp_expire_redirect" value="<?php echo esc_attr( $fallback ); ?>" class="widefat" placeholder="https://..." />
            <span class="description"><?php esc_html_e( 'After expiry, redirect here instead of 404.', 'linkpilot-pro' ); ?></span>
        </p>
        <?php
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lpp_schedule_nonce'] ) || ! wp_verify_nonce( $_POST['lpp_schedule_nonce'], 'lpp_schedule_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $start    = isset( $_POST['lpp_schedule_start'] ) ? sanitize_text_field( $_POST['lpp_schedule_start'] ) : '';
        $expire   = isset( $_POST['lpp_schedule_expire'] ) ? sanitize_text_field( $_POST['lpp_schedule_expire'] ) : '';
        $fallback = isset( $_POST['lpp_expire_redirect'] ) ? esc_url_raw( $_POST['lpp_expire_redirect'] ) : '';

        $start  ? update_post_meta( $post_id, self::META_START, $start )   : delete_post_meta( $post_id, self::META_START );
        $expire ? update_post_meta( $post_id, self::META_EXPIRE, $expire ) : delete_post_meta( $post_id, self::META_EXPIRE );
        $fallback ? update_post_meta( $post_id, self::META_FALLBACK, $fallback ) : delete_post_meta( $post_id, self::META_FALLBACK );
    }
}
```

- [ ] **Step 2: Register in bootstrap**

In `linkpilot-pro/linkpilot-pro.php`, inside `lpp_init()`, add:

```php
LPP_Scheduling::init();
```

- [ ] **Step 3: Commit**

```bash
git add linkpilot-pro/includes/class-lpp-scheduling.php linkpilot-pro/linkpilot-pro.php
git commit -m "feat(pro): link scheduling with expiry fallback URL"
```

---

### Task 6: GA4 Click Events (Pro)

Pro feature: fire a GA4 `click` event via Measurement Protocol when a managed link is clicked. Server-side (no JS needed; works with the HTTP redirect).

**Files:**
- Create: `linkpilot-pro/includes/class-lpp-ga4.php`
- Modify: `linkpilot-pro/includes/class-lpp-settings.php` (add fields)
- Modify: `linkpilot-pro/linkpilot-pro.php` (init)

- [ ] **Step 1: Create the GA4 class**

Create `linkpilot-pro/includes/class-lpp-ga4.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_GA4 {

    public static function init() {
        add_action( 'lp_after_click', array( __CLASS__, 'send_event' ), 10, 2 );
    }

    public static function send_event( $link, $destination ) {
        $measurement_id = get_option( 'lpp_ga4_measurement_id', '' );
        $api_secret     = get_option( 'lpp_ga4_api_secret', '' );

        if ( ! $measurement_id || ! $api_secret ) {
            return;
        }

        // GA4 requires a stable client_id; derive a per-visitor UUID-ish hash from IP+UA
        $fingerprint = ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) .
                       ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' );
        $client_id   = substr( hash( 'sha256', $fingerprint . wp_salt() ), 0, 16 ) . '.' . substr( hash( 'sha256', $fingerprint . 'b' ), 0, 16 );

        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode( $measurement_id ) .
               '&api_secret=' . rawurlencode( $api_secret );

        $payload = array(
            'client_id' => $client_id,
            'events'    => array(
                array(
                    'name'   => 'linkpilot_click',
                    'params' => array(
                        'link_id'    => $link->get_id(),
                        'link_title' => $link->get_title(),
                        'link_slug'  => $link->get_slug(),
                        'destination' => $destination,
                    ),
                ),
            ),
        );

        // Fire and forget — blocking: false so the redirect isn't delayed
        wp_remote_post( $url, array(
            'timeout'  => 2,
            'blocking' => false,
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'body'     => wp_json_encode( $payload ),
        ) );
    }
}
```

- [ ] **Step 2: Add GA4 fields to Pro settings**

In `linkpilot-pro/includes/class-lpp-settings.php`, inside `register_settings()`, add after the existing four `register_setting()` calls:

```php
register_setting( 'lpp_settings_group', 'lpp_ga4_measurement_id', array(
    'sanitize_callback' => 'sanitize_text_field',
    'default' => '',
) );
register_setting( 'lpp_settings_group', 'lpp_ga4_api_secret', array(
    'sanitize_callback' => 'sanitize_text_field',
    'default' => '',
) );
```

In `render_page()`, add two new rows to the `<table class="form-table">` block before `</table>`:

```php
<tr>
    <th><?php esc_html_e( 'GA4 Measurement ID', 'linkpilot-pro' ); ?></th>
    <td>
        <input type="text" name="lpp_ga4_measurement_id" value="<?php echo esc_attr( get_option( 'lpp_ga4_measurement_id', '' ) ); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" />
        <p class="description"><?php esc_html_e( 'Your GA4 property ID. Events fire server-side on every managed link click.', 'linkpilot-pro' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'GA4 API Secret', 'linkpilot-pro' ); ?></th>
    <td>
        <input type="password" name="lpp_ga4_api_secret" value="<?php echo esc_attr( get_option( 'lpp_ga4_api_secret', '' ) ); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e( 'Create in GA4 → Admin → Data Streams → Measurement Protocol API secrets.', 'linkpilot-pro' ); ?></p>
    </td>
</tr>
```

- [ ] **Step 3: Register in bootstrap**

In `linkpilot-pro/linkpilot-pro.php`, inside `lpp_init()`, add:

```php
LPP_GA4::init();
```

- [ ] **Step 4: Commit**

```bash
git add linkpilot-pro/includes/class-lpp-ga4.php linkpilot-pro/includes/class-lpp-settings.php linkpilot-pro/linkpilot-pro.php
git commit -m "feat(pro): GA4 click events via Measurement Protocol"
```

---

### Task 7: Deploy and Test on jeangalea.com

- [ ] **Step 1: Zip and deploy both plugins**

```bash
cd /tmp && rm -rf lp-deploy && mkdir lp-deploy && \
  cp -R /Users/jeangalea/Library/CloudStorage/Dropbox/Development/Claude/wordpress/linkpilot/{assets,includes,views,linkpilot.php,readme.txt,uninstall.php} lp-deploy/ && \
  cd lp-deploy && zip -rq /tmp/linkpilot.zip . && \
  cd /Users/jeangalea/Library/CloudStorage/Dropbox/Development/Claude/wordpress/linkpilot/linkpilot-pro && \
  zip -rq /tmp/linkpilot-pro.zip .

scp -P 28104 /tmp/linkpilot.zip /tmp/linkpilot-pro.zip \
  jeangalea@35.204.11.13:/www/jeangalea_679/public/wp-content/plugins/

ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public/wp-content/plugins && \
  rm -rf linkpilot linkpilot-pro && mkdir linkpilot linkpilot-pro && \
  cd linkpilot && unzip -oq ../linkpilot.zip && \
  cd ../linkpilot-pro && unzip -oq ../linkpilot-pro.zip && \
  cd .. && rm linkpilot.zip linkpilot-pro.zip"
```

- [ ] **Step 2: Verify both plugins activate without PHP errors**

```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp plugin list --name=linkpilot,linkpilot-pro"
```

Expected: both plugins showing `active` status.

- [ ] **Step 3: Test QR download**

```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp post list --post_type=lp_link --fields=ID --format=ids | awk '{print \$1}' | head -1"
```

Copy the link ID from output, then open browser: `https://jeangalea.com/wp-admin/edit.php?post_type=lp_link` and click the QR download icon on any row. Verify a PNG downloads.

- [ ] **Step 4: Test geo redirect (smoke test)**

```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp eval '
\$links = get_posts(array(\"post_type\" => \"lp_link\", \"posts_per_page\" => 1));
\$id = \$links[0]->ID;
update_post_meta(\$id, \"_lpp_geo_redirects\", array(\"US\" => \"https://example.com/us\"));
echo \"Set geo redirect on link \" . \$id . \" (\" . \$links[0]->post_name . \")\\n\";
'"
```

Then curl the cloaked URL with a CF country header:
```bash
curl -sI -H "CF-IPCountry: US" "https://jeangalea.com/go/<slug>/" | grep -i location
```
Expected: `Location: https://example.com/us`

```bash
curl -sI -H "CF-IPCountry: FR" "https://jeangalea.com/go/<slug>/" | grep -i location
```
Expected: default destination URL (not example.com/us).

Clean up:
```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp eval '
\$links = get_posts(array(\"post_type\" => \"lp_link\", \"posts_per_page\" => 1));
delete_post_meta(\$links[0]->ID, \"_lpp_geo_redirects\");
'"
```

- [ ] **Step 5: Test scheduling (smoke test)**

```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp eval '
\$links = get_posts(array(\"post_type\" => \"lp_link\", \"posts_per_page\" => 1));
\$id = \$links[0]->ID;
\$future = gmdate(\"Y-m-d\\\\TH:i:s\", time() + 3600);
update_post_meta(\$id, \"_lpp_schedule_start\", \$future);
echo \"Set start to \" . \$future . \" (1h from now) on link \" . \$id . \"\\n\";
'"
```

Then curl the cloaked URL — expect no redirect (start time not reached yet):
```bash
curl -sI "https://jeangalea.com/go/<slug>/" | head -5
```
Expected: 404 or 200 (no 3xx redirect).

Clean up:
```bash
ssh -p 28104 jeangalea@35.204.11.13 "cd /www/jeangalea_679/public && wp eval '
\$links = get_posts(array(\"post_type\" => \"lp_link\", \"posts_per_page\" => 1));
delete_post_meta(\$links[0]->ID, \"_lpp_schedule_start\");
'"
```

- [ ] **Step 6: Test JS redirect**

In browser: edit any LP link in wp-admin, set JS Redirect to "Yes", save. Visit the cloaked URL → should load an HTML page that redirects via JS. View source to confirm.

- [ ] **Step 7: Push to GitHub**

```bash
git push origin main
```
