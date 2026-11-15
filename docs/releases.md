# Pure Blog Plus releases

Pure Blog Plus installs updates only from published releases in
[`flschr/pureblog`](https://github.com/flschr/pureblog/releases). A release tag identifies the
integrated Pure Blog base and the Plus release, for example `v3.9.2-plus.1`. Optional preview
releases append `-alpha.N`, `-beta.N`, or `-rc.N`.

Pure Blog releases from `kevquirk/pureblog` are shown in the updater as integration notices.
They must be reviewed and integrated into a new Plus release before an installation can apply
them. The updater never downloads an upstream package.

## Package contract

Every installable archive must contain:

- `VERSION` matching the release tag
- `plus-release.json` with product `pureblog-plus`, schema version `1`, and the integrated
  Pure Blog version
- the original `LICENSE.md`
- the complete Plus runtime under `includes/plus/`
- SHA-256 checksums for the license and protected runtime files

The updater accepts download URLs only from the GitHub API path for this repository. It rejects
unsafe archive paths, missing files, mismatched identities, unexpected license changes, and
checksum failures before creating a backup or replacing any application file.

## Preparing a release

1. Integrate and verify the intended Pure Blog base.
2. Set `VERSION` to the release version without the leading `v`.
3. Regenerate `plus-release.json` with:

   ```sh
   php bin/plus-release-manifest.php > plus-release.json
   ```

4. Run PHP syntax checks and the updater and Plus runtime contracts.
5. Commit the version and manifest together.
6. Tag that exact commit and publish a GitHub release with the matching tag.

The generated GitHub source archive is the update package. A missing release, a draft, an
invalid tag, or a package that fails validation leaves the installed application unchanged.

## Rollback

Immediately before replacing application paths, the updater writes a local backup under
`backup/`. Configuration, content, operational data, `VERSION`, and all existing `.htaccess`
files remain protected. If replacement fails after mutation starts, the updater restores the
backup. The Updates page also retains manual restoration of an available backup.
