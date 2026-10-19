# Chilli Consent WordPress connector

Installable source fork of the official GetConzent WordPress connector 2.2.4.
`source.json` records the official download, original ZIP hash and per-file
hashes. Original GPLv3-or-later notices are kept in the plugin's LICENSE and
NOTICE. Do not treat the main application's Apache licence as this plugin's
licence.

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

- Keeps WordPress Consent API registration and the upstream category map:
  functional→preferences, analytics→statistics/statistics-anonymous,
  performance→functional and marketing→marketing.
- Detects Google Site Kit with WP Consent API and retains its initial-state
  handling; consent events read current banner state even when loaded later.
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
