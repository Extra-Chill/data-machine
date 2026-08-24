# WordPress.org Submission Checklist

The WordPress.org package must be the exact Data Machine release package produced by Homeboy. Do not rebuild or edit files in SVN after release packaging.

## Candidate Gate

1. Confirm the release commit is clean, pushed, and is the intended tag commit.
2. Build through Homeboy's WordPress release package path. `.buildignore` is the authoritative package exclusion file; `.distignore` is not used by this repository-supported path.
3. Record the ZIP path, SHA256, uncompressed inventory, entry count, and version from `data-machine.php` and `readme.txt`.
4. Reject packages containing tests, fixtures, PHPUnit files, development configuration, nested archives, local paths, secrets, private endpoints, or unbuilt frontend source that is not required for license compliance.
5. Run Plugin Check against the installed ZIP on the `Tested up to` WordPress version. Resolve all errors and record every warning code, count, and disposition.
6. Run clean single-site activation, deactivation, and reactivation. Confirm missing requirements fail with WordPress's normal plugin requirement message and no fatal error.
7. Run network activation on multisite, verify Data Machine loads on at least two sites, then network-deactivate cleanly.
8. Run the full Homeboy audit, lint, build, and test gates without skipped checks.
9. Run `composer validate --strict`, `composer audit --no-dev`, and `npm audit --omit=dev`; review `THIRD-PARTY-NOTICES.txt` against the final lockfiles.
10. Confirm plugin headers and `readme.txt` agree on Requires WordPress, Requires PHP, stable tag, and license. Verify description, installation, FAQ, external services, source, support, and changelog links.

## Directory Assets

WordPress.org directory banners, icons, and screenshots belong in the SVN repository's top-level `assets/` directory and must not be added to the plugin ZIP. This Git repository does not currently contain approved directory artwork. Obtain maintainer-approved assets before submission; do not invent or generate replacements during packaging.

## SVN Staging

1. Check out the assigned WordPress.org SVN repository into a clean temporary directory.
2. Copy the exact contents of the verified ZIP's `data-machine/` directory into `trunk/`.
3. Copy the same contents into `tags/<release-version>/`; do not run Composer, npm, formatters, or text replacement in SVN.
4. Add maintainer-approved directory artwork to top-level `assets/` only.
5. Review `svn status`, reject unexpected deletions or unversioned files, and compare a deterministic file-hash inventory of `trunk/` and `tags/<release-version>/` with the extracted ZIP.
6. Re-run readme validation and inspect the local SVN diff.
7. Obtain maintainer approval for the exact diff and package hash before `svn commit`.
8. After commit, verify the public directory page, download ZIP hash/content, readme rendering, assets, and installation on a clean site.

Tagging, releasing, WordPress.org submission, SVN commit, and deployment remain explicit maintainer actions.
