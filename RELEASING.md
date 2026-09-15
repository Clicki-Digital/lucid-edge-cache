# Releasing Lucid Edge Cache

The WordPress updater reads the latest stable GitHub release from
`Clicki-Digital/lucid-edge-cache`. The repository must be public unless the
updater is deliberately changed to use authentication.

Version 0.7.0 is the updater bootstrap and must be installed manually over an
older build once. Published GitHub releases can update version 0.7.0 and later.

## One-time setup

1. Create the public GitHub repository `Clicki-Digital/lucid-edge-cache`.
2. Push this directory as the repository root, including `.github/workflows/release.yml`.
3. Protect the main branch and require changes to be tested before merging.
4. In GitHub Actions settings, allow workflows to create and update releases.

If the repository moves later, change the default value of
`LEC_GITHUB_REPOSITORY` in `lucid-edge-cache.php` and release that change before
moving it. It can also be overridden in `wp-config.php`, but hard-coding the
correct public repository is simpler across a managed fleet.

## Release checklist

1. Update the plugin header version, `LEC_VERSION`, `Stable tag`, and changelog.
2. Test the build on the Clicki Digital test site.
3. Commit and push the approved source.
4. Create and push a matching tag, for example `v0.7.0`. When releases are
   managed through the connected GitHub integration instead, update
   `.github/release-version` to the matching tag.
5. GitHub Actions validates the versions and PHP syntax, creates the tag when
   the version marker was used, then publishes the release.
6. Confirm the release contains both `lucid-edge-cache.zip` and
   `lucid-edge-cache.zip.sha256`.
7. On the test site, select **Dashboard > Updates > Check again** and perform the
   update before enabling it across other sites.

WordPress only sees published stable releases. Drafts and pre-releases are
ignored. The updater also requires GitHub's SHA-256 asset digest and rejects a
ZIP whose root folder is not exactly `lucid-edge-cache`.
