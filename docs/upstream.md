# Upstream workflow

Pure Blog Plus is based on [Kev Quirk's Pure Blog](https://github.com/kevquirk/pureblog).
The original license and attribution remain in [LICENSE.md](../LICENSE.md).

## Remotes and branches

- `upstream`: `https://github.com/kevquirk/pureblog.git`
- `origin`: `https://github.com/flschr/pureblog.git`
- `main`: reviewed Pure Blog Plus component changes and the base for feature branches
- feature work: a dedicated branch and pull request into `main`

Do not rewrite upstream commits or copy a new upstream tree over Plus changes. Keep Plus
features in focused commits so an upstream release can be reviewed and integrated normally.

## Updating from Pure Blog

1. Fetch `upstream` and read its release and migration notes.
2. Compare the new upstream tag or commit with the last integrated upstream commit.
3. Create a dedicated update branch from current Pure Blog Plus `main`.
4. Merge the selected upstream commit without squashing away its history.
5. Resolve conflicts at the canonical owner instead of adding compatibility branches.
6. Run configuration-gate, editor, content, feed, and Plus-module verification.
7. Open a pull request that records both upstream commits and any retained Plus differences.

An upstream change to authentication, content persistence, media handling, routing, hooks, or
updates requires an explicit review of the corresponding Plus owner before deployment.

## Plus feature policy

- Local development and recovery must not require the optional connector.
- Optional integrations default to disconnected and fail without blocking the blog.
- Persisted content remains plain Markdown; SQLite stores operational state only.
- The component contains no fischr.org infrastructure constants; a reusable boundary is
  generalized only for a real second deployment.
- A migration that replaces an older path names and removes that path; permanent dual writes
  are not accepted as an integration strategy.
- Tags used by the owning deployment retain the Pure Blog license and make the upstream
  relationship visible. They are source identities, not an in-app update channel.

Plus source identities use versions such as `3.9.4-plus.1`, which identify both the integrated
Pure Blog baseline and the component revision. Pure Blog releases are integration inputs and
are never applied directly to production. The fischr.org deployment records the exact
component commit it verifies and installs.
