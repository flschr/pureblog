# Upstream workflow

Pure Blog Plus is based on [Kev Quirk's Pure Blog](https://github.com/kevquirk/pureblog).
The original license and attribution remain in [LICENSE.md](../LICENSE.md).

## Remotes and branches

- `upstream`: `https://github.com/kevquirk/pureblog.git`
- `origin`: `https://github.com/flschr/pureblog.git`
- `main`: reviewed Pure Blog Plus releases and the base for feature branches
- feature work: a dedicated branch and pull request into `main`

Do not rewrite upstream commits or copy a new upstream tree over Plus changes. Keep Plus
features in focused commits so an upstream release can be reviewed and integrated normally.

## Updating from Pure Blog

1. Fetch `upstream` and read its release and migration notes.
2. Compare the new upstream tag or commit with the last integrated upstream commit.
3. Create a dedicated update branch from current Pure Blog Plus `main`.
4. Merge the selected upstream commit without squashing away its history.
5. Resolve conflicts at the canonical owner instead of adding compatibility branches.
6. Run setup, upgrade, editor, content, feed, and Plus-module verification.
7. Open a pull request that records both upstream commits and any retained Plus differences.

An upstream change to authentication, content persistence, media handling, routing, hooks, or
updates requires an explicit review of the corresponding Plus owner before release.

## Plus feature policy

- Standalone blogging behavior must not require the optional connector.
- Optional integrations default to disconnected and fail without blocking the blog.
- Persisted content remains plain Markdown; SQLite stores operational state only.
- Product features use generic configuration and capability checks, never fischr.org constants.
- A migration that replaces an older path names and removes that path; permanent dual writes
  are not accepted as an integration strategy.
- Releases retain the Pure Blog license and make the upstream relationship visible.

Plus releases use tags such as `v3.9.2-plus.1`, which identify both the integrated Pure Blog
baseline and the Plus release. Pure Blog releases are integration inputs and are never applied
directly to a Plus installation. See [the release contract](releases.md).
