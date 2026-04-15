=== LinkPilot - Intelligent Link Manager ===
Contributors: jeangalea
Tags: affiliate links, link management, link cloaking, redirect, link tracking
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The intelligent link manager for WordPress. Manage, cloak, track, and optimize your outbound links — no nag screens, ever.

== Description ==

LinkPilot helps you manage all your outbound links in one place. Create cloaked, trackable links for affiliate programs, partner referrals, or any URL you want to manage centrally.

**Why LinkPilot?**

* Zero nag screens, zero upsell banners, zero review popups — ever
* GDPR-compliant click tracking (no IP addresses stored by default)
* Categories and tags for organizing links
* One-click migration from ThirstyAffiliates, Pretty Links, LinkCentral, and more
* Ad-blocker safe — no trigger words in code paths
* Clean, modern admin interface

**Free Features:**

* Custom link management with cloaked URLs
* 301, 302, 307 redirects
* Click tracking with bot filtering
* Link categories and tags
* Gutenberg block and Classic Editor integration
* Link Fixer — automatically syncs link attributes
* CSV import/export
* Nofollow, sponsored, UGC rel attributes
* Query string passthrough
* Dashboard with click statistics
* External link processing — adds rel (nofollow, sponsored, noopener, noreferrer, ugc) and target attributes to raw outbound links in post content, with per-domain allowlist (replaces WP External Links)

== External Services ==

This plugin can optionally connect to an external service when you enable the **QR Code** feature. QR code generation is **disabled by default** and only runs when you opt in under LinkPilot > Settings.

**QR Server (api.qrserver.com)**

When enabled, LinkPilot sends the cloaked URL you want to encode to api.qrserver.com to generate a QR code PNG image. No personal data or visitor data is transmitted. This only runs on demand, when an administrator clicks the QR download button in the admin.

* Service provider: QR Server
* Data sent: the cloaked URL you are generating a QR code for
* Terms of service: https://goqr.me/api/
* Privacy policy: https://goqr.me/privacy/

If you do not enable the QR feature, no external service is ever contacted.

== Installation ==

1. Upload `linkpilot` to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to LinkPilot > Add New Link to create your first link

== Frequently Asked Questions ==

= Will this plugin nag me for reviews or show upgrade banners? =

No. Never. This is a core principle of LinkPilot. We believe in earning your trust through quality, not guilt.

= Is click tracking GDPR compliant? =

Yes. By default, LinkPilot does not store IP addresses. Country data is derived at click time and the IP is immediately discarded.

= Can I migrate from ThirstyAffiliates or Pretty Links? =

Yes. LinkPilot includes built-in migration from ThirstyAffiliates, Pretty Links, LinkCentral, Easy Affiliate Links, and Lasso.

== Changelog ==

= 1.0.0 =
* Initial release
