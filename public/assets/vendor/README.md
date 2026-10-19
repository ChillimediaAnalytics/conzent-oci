# Self-hosted dashboard assets

These are upstream distributions, retained without redesign or source changes.
The login and dashboard layouts load these local files instead of remote CDNs
or Google Fonts, so dashboard visits need not disclose visitor requests to
those asset providers or wait for them to respond.

`manifest.json` records exact package versions, official source URLs, npm
SHA-512 archive integrity values verified before extraction, and SHA-256 hashes
of every copied asset and licence. Registry versions were resolved on
19 October 2026; versions are deliberately pinned rather than using `latest`.

- Bulma 1.0.2: MIT, licence included.
- Font Awesome Free 6.5.1: icons CC BY 4.0, fonts OFL 1.1, code MIT; complete
  licence and all files referenced by its original CSS are included.
- Alpine.js 3.17.4: MIT, licence fetched from the npm release's exact upstream
  Git commit because its npm archive does not include the licence.
- htmx 2.0.4: BSD 2-Clause, licence included.
- Marked 18.1.1: MIT, licence included. Its original UMD build exposes
  `marked.parse` to the existing templates.
- Inter: Google's official stylesheet for weights 400, 500, 600 and 700,
  with all seven font subsets copied from fonts.gstatic.com. Stylesheet font
  URLs alone were rewritten to local relative paths. SIL OFL 1.1 is included.

Update files and hashes together, review licensing and browser compatibility,
and deploy them with the template changes. No install scripts were executed
from these packages.

Child-page assets are also served locally: ApexCharts 3.52.0, driver.js 1.3.1
and CodeMirror 5.65.18 (core, Monokai theme and the XML, JavaScript, CSS and
HTML-mixed modes), all MIT. CodeMirror uses its original npm distribution
files rather than CDN-minified equivalents. Redundant child-page Font Awesome
references reuse the same local copy.

The optional Turnstile verification API is an external service, not a vendored
library; its integration remains unchanged on the publicly disabled registration
page. User-facing external links and configured API integrations are unchanged.
