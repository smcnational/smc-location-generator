# Releasing a new version

## One-time setup

1. Create the repository `smcnational/smc-location-generator` and push the contents of this folder to it (the plugin file `smc-location-generator.php` at the top level, not inside another folder).
2. In the repository, **Settings > Actions > General > Workflow permissions**: choose **Read and write permissions** so the release Action can publish releases.
3. Publish the first release (below) as `v1.15.0`, then install that zip on each site once. After that, sites update themselves.

Sites update themselves from this repository's GitHub releases. A GitHub Action builds the plugin zip and publishes the release whenever a version tag is pushed.

## Shipping (usual way)

Releases use the shared `ship` command (the same one for every SMC plugin repo). Claude hands over `smc-location-generator-v<version>.zip`, with the repo inside a `smc-location-generator/` folder. Download it, then:

```bash
cd ~/Projects/smc-location-generator && ship
```

It pulls the latest from GitHub, applies the newest `smc-location-generator-v*.zip` in Downloads (files the zip no longer has are deleted from the repo too), stops if the version is already tagged, shows the changes and asks. Then it commits, pushes, tags, pushes the tag and deletes the zip. The GitHub Action builds the release.

`ship --bump 1.31.0` (or `1.31.0-beta.1`) sets the version and ships local changes without a zip.

## Normal release (by hand)

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

## Access

The repository is public, so sites check for updates without a token. If it's ever made private, each site needs a read-only token in `wp-config.php`:

```php
define( 'SMC_LOCATIONS_GITHUB_TOKEN', 'github_pat_...' );
```

(fine-grained token, resource owner `smcnational`, only this repository, **Contents: Read-only**).
