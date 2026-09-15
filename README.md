# Lucid Edge Cache

Lucid Edge Cache is Clicki Digital's WordPress full-page caching plugin. It
stores safe anonymous HTML locally, coordinates Varnish purging and optionally
replicates generated HTML to DigitalOcean Spaces.

The plugin deliberately bypasses logged-in sessions, dynamic WordPress views,
query strings, private content and common WooCommerce session state.

## Installation

Install the `lucid-edge-cache.zip` asset from the latest stable release through
WordPress. Version 0.7.0 is the one-time updater bootstrap; installations older
than 0.7.0 must upload it manually once.

Infrastructure onboarding writes protected values to `wp-config.php` rather
than storing credentials in the WordPress database.

## Releases

Pushing a semantic version tag such as `v0.7.0` runs the release workflow. It
checks version consistency and PHP syntax, builds the canonical WordPress ZIP,
generates its SHA-256 file and publishes both files to GitHub Releases.

See [RELEASING.md](RELEASING.md) for the complete release and staged rollout
checklist.
