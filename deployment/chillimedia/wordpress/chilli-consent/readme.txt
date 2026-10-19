=== Chilli Consent ===
Contributors: chillimedia
Tags: cookie consent, cookie banner, consent management
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 2.2.4.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect this WordPress website to Chillimedia's consent dashboard.

== Description ==

Agency-maintained fork of GetConzent CMP 2.2.4 by Conzent ApS. Original GPL notices are retained in LICENSE and NOTICE. This fork is distributed by Chillimedia, not the upstream WordPress.org plugin publisher.

The service is fixed to https://consent.chillimedia.se. Old Server URL options and CONZENT_SERVER_URL do not override it. The plugin does not contact Conzent Cloud. Configure a website in the Chilli Consent dashboard, then enter that website's key in WordPress or use automatic setup.

== External service ==

The connector loads https://consent.chillimedia.se/c/consent.js for visitors once configured. It uses /api/v1/verify for manual key verification and /api/v1/plugin/claim for automatic setup. The consent service receives consent events through its banner; configure and review its policies for each customer.

When no website key is configured, automatic setup sends this site's public domain, a random challenge token and the connector version to the Chilli Consent service. A pending challenge publishes the token at /?conzent_claim=1. The site must already exist in the dashboard; no account or billable site is created by the plugin. Set CONZENT_AUTO_SETUP to false in wp-config.php to disable automatic setup.

== Installation ==

1. Deactivate the original GetConzent plugin if installed. The forks share compatibility function and option names and cannot be active together.
2. Upload chilli-consent.zip using Plugins > Add New > Upload Plugin, and activate Chilli Consent.
3. Open Chilli Consent > Settings. Enter the website key from the central dashboard, or create the matching domain there and use automatic setup.
4. Clear the site's page/cache/CDN cache and verify banner behaviour as a logged-out visitor. Check GTM, analytics, advertising and embeds individually before using it for customer traffic.

== Integrations ==

Uses WordPress Consent API consent categories when that plugin is present. Detects Google Site Kit and preserves its initial-state handling. Prints the synchronous consent loader at wp_head priority 0, before ordinary analytics tags. Caching attributes exclude the loader from common delay/defer tools. Does not inject a banner into WordPress admin or supported page builders.

The connector is not a replacement for testing a site's specific tracking configuration. No certification claim for this agency fork is made.

== Updates ==

Chillimedia owns reviewing upstream changes, testing, packaging and distributing updates. Update URI and the renamed plugin folder/file prevent this fork from being replaced by the original WordPress.org package. No automatic update service is included in this build.

== Changelog ==

= 2.2.4.2 =
* Initialize opt-in state in the head, replay saved choices and sync immediately after WordPress Consent API loads.
* Respect explicit CCPA opt-out while keeping missing configuration fail closed.
* Correct WP Consent API functional to necessary storage; deny optional categories before consent.

= 2.2.4.1 =
* Chilli Consent branding, fixed agency endpoint and independent update identity.
* Safe HTTP requests, no redirects for verification, and explicit overview permission check.
* Read banner state at consent-event time and tolerate malformed cookie encoding.
* Distinguish configured keys from successful API/domain verification; unreachable checks show a warning instead of Verified.
* Original APIs, option names, site-key field, WP Consent API and Site Kit bridge retained.
