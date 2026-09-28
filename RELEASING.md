# Releasing a new version

## One-time setup

1. Create the repository `smcnational/smc-location-generator` and push the contents of this folder to it (the plugin file `smc-location-generator.php` at the top level, not inside another folder).
2. In the repository, **Settings > Actions > General > Workflow permissions**: choose **Read and write permissions** so the release Action can publish releases.
3. Publish the first release (below) as `v1.15.0`, then install that zip on each site once. After that, sites update themselves.

Sites update themselves from this repository's GitHub releases. A GitHub Action builds the plugin zip and publishes the release whenever a version tag is pushed.

## Normal release

1. Change the `Version:` line at the top of `smc-location-generator.php`, e.g. `1.16.0`.
2. Commit and push.
3. Tag and push the tag (the tag must match the Version, with a `v` in front):

   ```bash
   git tag v1.16.0
   git push origin v1.16.0
   ```

4. In a minute or two the release appears under **Releases** with `smc-location-generator.zip` attached. Edit its notes if you like; they show in each site's "View details" window.

Sites see the update within 6 to 12 hours, or right away under **Dashboard > Updates > Check again**. Sites with automatic updates turned on install it by themselves.

If the tag and Version don't match, the Action stops with an error and nothing is released. Fix the Version, commit, delete the tag (`git tag -d v1.16.0 && git push origin :v1.16.0`), and tag again.

## Testing on staging first (Beta)

Tag with a suffix, e.g. Version `1.16.0-beta.1` and tag `v1.16.0-beta.1`. That's published as a **pre-release**, which only sites set to **Beta** (Locations > Settings > Updates) are offered. Once it's good, release `1.16.0` normally.

## Version numbers

- `1.16.0`: new features.
- `1.16.1`: fixes.
- Never reuse a number. A site only updates to a higher version.

## Private repository

Sites need a read-only token to see a private repo's releases. Create a **fine-grained personal access token** (or one on a machine account) with:
- Resource owner: `smcnational`
- Repository access: only this repository
- Permissions: **Contents: Read-only**

Put it in each site's `wp-config.php`, or in the plugin's settings (Locations > Settings > Updates):

```php
define( 'SMC_LOCATIONS_GITHUB_TOKEN', 'github_pat_...' );
```

Set an expiry reminder: when the token expires, sites stop seeing updates (Locations > Settings shows the error).
