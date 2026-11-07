# Contributing

Kestello's [contributing rules](https://github.com/kestellohq/cms/blob/main/CONTRIBUTING.md) apply here: branches, Conventional Commits, tests for every fix, the code style and the security rules. What is different in this repository:

- **Scope commits by plugin:** `fix(typesense): …`, `feat(social-login): …`.
- **One plugin per pull request** where you can, so each release stays clear.
- **Changelog per plugin:** add your entry under **Unreleased** in `<slug>/CHANGELOG.md`.
- **Checks:** `sh tools/check.sh` must pass before you open the pull request (see [README.md](README.md#develop)).
- **Only the public API.** A plugin uses what `$cms` and `Kestello\Contracts` offer. When it needs something core doesn't have, open an issue in [kestellohq/cms](https://github.com/kestellohq/cms/issues) for an extension point instead of reaching into core.

Security problems: follow [Kestello's security policy](https://github.com/kestellohq/cms/blob/main/SECURITY.md), not a public issue.
