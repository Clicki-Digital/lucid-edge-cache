=== Lucid Edge Cache ===
Contributors: lucidsolutions
Tags: cache, varnish, digitalocean, spaces, performance
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 0.7.1
License: GPLv2 or later

Safe anonymous full-page caching with Varnish purging and optional DigitalOcean Spaces HTML replication.

== Installation ==
1. Back up the test site.
2. Deactivate Breeze and remove its page-cache drop-in if WordPress reports a conflict.
3. Upload and activate this plugin.
4. Activation enables `WP_CACHE` in wp-config.php automatically when it is absent, then opens Settings > Lucid Cache Setup.
5. Enter the Spaces and Varnish details. The wizard tests both services before writing a marked configuration block to wp-config.php.
6. Open Settings > Lucid Edge Cache, review cache policy, then select Preload published content.
7. Test while logged out or in a private browser. Look for X-Lucid-Cache: MISS on the first origin request and HIT afterwards. An upstream Varnish hit may replay the original Lucid header.

== DigitalOcean configuration ==
The setup wizard writes secrets to wp-config.php and never stores them in WordPress options. If automatic configuration is unavailable, it provides a one-request manual block.

Generated objects are always namespaced using the canonical hostname from the WordPress Home URL:

lucid-edge-cache/clickidigital.com.au/index.html
lucid-edge-cache/client.example/page/index.html

Generated HTML is replicated with public-read access. Use a restricted Spaces key. The optional DigitalOcean API token and CDN endpoint ID can be entered during onboarding for immediate CDN purging; they are written to wp-config.php rather than WordPress options.

== Operations and recovery ==
* Updates are delivered from the latest stable GitHub release. WordPress.org is excluded using the plugin Update URI header.
* Version 0.7.0 must be installed manually once when upgrading from an older build; subsequent published versions can use normal WordPress updates and auto-updates.
* The GitHub release must contain `lucid-edge-cache.zip`. Downloads are accepted only from GitHub, checked against GitHub's SHA-256 asset digest, and rejected if the ZIP root is not `lucid-edge-cache`.
* WordPress auto-updates remain off until an administrator enables them on that site.
* Use Run system test after installation and after infrastructure changes. It checks the drop-in, cache directory, runtime configuration, Varnish and Spaces without displaying secrets.
* Infrastructure settings are locked after onboarding. To reconfigure deliberately, temporarily add `defined('LEC_ALLOW_RECONFIGURE') || define('LEC_ALLOW_RECONFIGURE', true);` to wp-config.php, complete setup, then remove it.
* Deactivation removes only the Lucid-owned advanced-cache.php drop-in and scheduled jobs. It preserves settings, wp-config.php constants and cached files.
* Uninstall removes WordPress options and the Lucid-owned drop-in. It intentionally leaves the marked wp-config.php block and cache directory for manual review.
* If Spaces is temporarily unavailable, uploads and deletions retry through WP-Cron up to five times. Configure a real cron runner on production sites.
* Expired local HTML and abandoned lock files are cleaned automatically each day.
* Emergency disable stops the early page cache and clears local HTML without deactivating WordPress or removing infrastructure configuration.
* URL inspector reports local state, age, queue state and path exclusions without requesting or changing the inspected page. Cookie and response-header decisions remain request-dependent.
* Download safe diagnostics creates a JSON report containing health, policy and recent activity but never credentials or API tokens.

== Important test limitations ==
* Version 0.5.0 selectively refreshes changed content, related public URLs and archive pagination. Global site changes perform a full purge.
* DigitalOcean replication is secondary; it does not redirect the public website to Spaces.
* Query-string requests, logged-in sessions, previews, search, feeds, REST, admin and common commerce/session cookies bypass cache.
* Preloading uses WP-Cron in batches of five. Configure a real cron runner for reliable production operation.
* Varnish PURGE behaviour varies by host. Confirm Cloudways accepts PURGE on the configured URL and inspect response headers during testing.
* Do not enable alongside another plugin that owns wp-content/advanced-cache.php.

== Changelog ==
= 0.7.1 =
* Added a Settings action link on the WordPress Plugins page, routing configured sites to the cache dashboard and unconfigured sites to onboarding.

= 0.7.0 =
* Added private-vendor update integration using public GitHub release assets and the standard WordPress Updates screen.
* Added strict release asset naming, trusted-host validation, SHA-256 verification and package-root validation.
* Added a GitHub Actions release workflow that validates version consistency, lints PHP and builds the canonical WordPress ZIP.
* Added release documentation and protected the plugin from similarly named WordPress.org updates with Update URI.

= 0.6.3 =
* Changed new activity entries to show same-site URL paths while stripping query strings and hiding external URLs.
* Formatted activity event and status identifiers into readable labels such as Preload, Spaces Upload and HTTP 200.

= 0.6.2 =
* Replaced the raw TTL field with quick selections for 4 hours, 6 hours, 12 hours, 1 day and 1 week.
* Marked 6 hours as the recommended default and retained an advanced custom value between 60 seconds and 7 days.
* Clarified that TTL is a maximum age while publishing still invalidates affected pages immediately.

= 0.6.1 =
* Simplified the administration page by placing queues, URL inspection, diagnostics, single-URL regeneration and recent activity in one collapsed Advanced tools and diagnostics panel below System health.

= 0.6.0 =
* Added generation locking, short wait handling and five-minute stale serving to reduce cache stampedes.
* Added daily expired HTML and abandoned lock-file cleanup.
* Added background queue counts plus retry and clear controls.
* Added a non-mutating URL inspector for cache state, age, exclusions, queue status and Spaces object keys.
* Added a secret-free downloadable diagnostics report.
* Added an emergency page-cache disable and enable control.
* Added cleanup scheduling to health reporting and a multisite compatibility warning.

= 0.5.3 =
* Renamed safety status labels to Always bypassed for clearer behaviour.
* Added X-Lucid-Cache: BYPASS and a privacy-safe X-Lucid-Cache-Reason diagnostic header.
* Added simple dashboard guidance for interpreting HIT, MISS and BYPASS responses.

= 0.5.2 =
* Added a visible Cache safety rules panel explaining every mandatory bypass.
* Made WordPress login, password and WooCommerce customer-state cookie exclusions non-removable.
* Detected the configured WooCommerce Cart, Checkout and My Account URLs instead of relying only on default slugs.
* Relabelled editable exclusions as additional site-specific rules.

= 0.5.1 =
* Retried automatic WP_CACHE configuration on every administration request until it succeeds.
* Issued the onboarding and dashboard gating fix as a distinct patch version so WordPress cannot mistake it for the earlier 0.5.0 build.

= 0.5.0 =
* Hardened locked onboarding with forced dashboard redirects and deliberate reconfiguration only.
* Hid the cache dashboard until onboarding is complete and made Setup the only available plugin page beforehand.
* Enabled WP_CACHE automatically during activation and upgrades, with a safe manual-failure notice only when wp-config.php cannot be changed.
* Added atomic runtime and HTML writes with safer permissions and commit checks.
* Added a dashboard system-health report and one-click end-to-end test.
* Added automatic Spaces retry queues with bounded attempts and locking.
* Added invalidation for relevant options, widgets, terms and archive pagination.
* Added cache-age response headers and expanded request exclusions.
* Restricted Varnish endpoints to localhost or the canonical site hostname.
* Added onboarding support for an optional prefix, CDN endpoint ID and DigitalOcean API token in wp-config.php.
* Expanded lifecycle, recovery and testing documentation.

= 0.4.1 =
* Redirect onboarding success to the main Lucid Edge Cache page.
* Hide the setup menu and fields whenever a managed configuration block exists.
* Block direct setup access unless LEC_ALLOW_RECONFIGURE is deliberately enabled.
* Lock infrastructure fields immediately without waiting for PHP to reload constants.

= 0.4.0 =
* Added a secure one-time onboarding wizard with Spaces validation.
* Added atomic wp-config.php configuration with manual fallback.
* Added constant-managed infrastructure settings and settings locking.
* Enforced canonical hostname namespaces for shared Spaces buckets.

= 0.3.0 =
* Added selective invalidation for posts, pages, archives, taxonomies and authors.
* Added configurable excluded paths and cookies.
* Added preload queue locking, deduplication and retries.
* Added scheduled-publication handling and remote deletion for deleted content.
* Added Varnish and Spaces connection tests.

= 0.2.0 =
* Added Varnish-resistant cache-busting preload requests.
* Automatically queues regeneration after published content changes.
* Added single-URL regeneration.
* Added local, preload, Varnish, Spaces and CDN activity logging.
* Added targeted Spaces CDN purging when API credentials are configured.

= 0.1.0 =
* Initial test release.
