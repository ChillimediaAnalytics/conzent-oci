# Chilli Consent WordPress connector

Installable source fork of the official GetConzent WordPress connector 2.2.4.
`source.json` records the official download, original ZIP hash and per-file
hashes. Original GPLv3-or-later notices are kept in the plugin's LICENSE and
NOTICE. Do not treat the main application's Apache licence as this plugin's
licence.

## Current release: 2.2.4.2

The installation ZIP is ready. Validation passed 33 PHP assertions, 11 JavaScript
bridge tests and PHP lint, plus real WordPress 7.1.3 with WP Consent API 2.1.0.
Browser checks covered initial denial, reject, accept, returning visitors,
reopening preferences, withdrawal and the real API consumer bridge. The local
analytics-cookie fixture was blocked before consent, allowed after acceptance
and removed within about one second of withdrawal. These checks used a disposable
site; customer-specific tracking still follows the rollout checks below.

The temporary WordPress container, volume, isolated database/user and scratch
credentials/logs were removed after testing. Sanitized verification and cleanup
reports remain under `tests/evidence/`.

## Installation

Install `chilli-consent.zip` through WordPress **Plugins → Add New → Upload
Plugin**, or deploy the `chilli-consent/` folder using your WordPress management
tool. Deactivate the original GetConzent connector first: compatibility names
are retained, so they cannot be active together.

Create the matching website in the central dashboard. Activate this plugin,
then enter its website key under **Chilli Consent → Settings**, or use automatic
setup. A website key is an installation identifier printed in the public page
source; it is not an admin credential.

The service is pinned in source to `https://consent.chillimedia.se`. Old database
server options and `CONZENT_SERVER_URL` are deliberately ignored. Activation
only schedules setup; later setup/verification requests use that pinned service.
`CONZENT_AUTO_SETUP=false` disables automatic setup. Compatibility WP-CLI commands
remain `wp conzent status` and `wp conzent claim --force`. No client websites have
been installed or modified as part of preparing this package.

Clear page/edge caches and test consent as a logged-out visitor before each
customer rollout. Test GTM/GA, advertising pixels, embeds, accept/reject/revoke,
subsequent navigation and the customer's own cache/optimization plugins.

## Integrations and limits

- Establishes opt-in defaults and a synchronous bridge in the head before the
  remote banner. Immediately synchronizes after WordPress Consent API loads,
  restores saved choices without waiting for a user event, and responds to late
  banner initialization. PHP API consumers receive the same fail-closed policy.
- Maps optional analytics to statistics/statistics-anonymous, marketing to
  marketing, and optional functional/preferences to preferences. WP Consent API
  functional means necessary storage and remains allowed.
- Explicit CCPA/opt-out configuration is respected; missing/unknown laws default
  to opt-in. A saved accept/reject decision takes priority over opt-out defaults.
  Browser state uses the loaded banner law. PHP consumers default to opt-in;
  sites requiring server-side opt-out must explicitly provide `optout` through
  the WordPress `wp_get_consent_type` filter. No policy network call is made on
  each server render.
- Retains compatibility with Google Site Kit consuming WordPress Consent API.
- Loads `/c/consent.js` at `wp_head` priority 0. Common cache delay/defer exclusion
  attributes are retained. A theme/plugin can still print tags before that hook;
  inspect the real customer page rather than assuming all tags are covered.
- Retains page-builder/admin request guards, public consent-ID shortcodes and
  automatic claim/backoff. Automatic claims send a public domain, one-time
  random token and connector version to the agency service.
- No independent billing, scanner or dashboard is installed in WordPress; those
  remain on the central service. This build makes no vendor certification claim.

## Security review and validation

Settings and manual retry/dismiss handlers require `manage_options` and
WordPress nonces. Rendered configuration is escaped. The service URL cannot be
supplied in a request, option or legacy constant. API requests use WordPress's
safe HTTP wrappers with TLS validation and no redirects; website keys are
URL-encoded. The pending challenge endpoint publishes only a random public
verification token and sends no-cache/noindex headers.

Manual settings retain an entered key and keep the banner loader enabled when
the API is unreachable or cannot verify it, but display **Configured — verification
pending** and a warning. Only a successful API verification or automatic domain
claim sets the separate actual-verification flag and displays **Verified with
Chilli Consent**. Legacy upstream configured flags are not inherited as verified.
This reflects the last successful check, not continuous service health.

Run `php tests/connector-smoke.php` and `node --test tests/consent-bridge.test.cjs`
from this directory. These fixture tests cover pinned API/loader destinations,
activation behaviour, permission/nonce guards and actual consent-event mapping.
All PHP files must also pass `php -l`. A real WordPress/site-specific rollout test
is still needed; these tests use WordPress API stubs and a browser VM fixture.

## Updates and packaging

Chillimedia owns reviewing future upstream releases, merging security fixes,
reviewing the changed-file diff, testing and distributing ZIPs. The renamed
folder/main file and external `Update URI` stop WordPress.org replacing the fork
with the original. No update endpoint is implemented yet.

Rebuild deterministically with `python3 build-zip.py`; its manifest records each
packaged file hash. Keep this GPL source available with every distributed ZIP.
