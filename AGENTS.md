# Kestello plugins: instructions for AI agents

First-party plugins for Kestello, each in its own folder with `plugin.json`, `src/`, `tests/` and `CHANGELOG.md`. People follow the same rules, in [CONTRIBUTING.md](CONTRIBUTING.md).

## Before you start

1. Run `sh tools/setup.sh`. It puts Kestello in `kestello/` (ignored by Git); read its `AGENTS.md` and load the skills in `kestello/.agents/skills/`, above all `write-plugin`, `rest-endpoint`, `testing` and `security-review`.
2. Kestello's hard rules apply here: no Composer and no PHP dependencies, security first, only the public API, every fix starts with a failing test, text people see goes through `__()`.

## Working rules

- Never push, tag or publish unless asked in so many words. Commit locally.
- Conventional Commits scoped by plugin (`fix(meilisearch): …`), with no AI assistant trailers.
- Don't change `kestello/`: it is Kestello's repository. A missing extension point is an issue there.
- Never edit a plugin's `version` or its changelog's released sections unless you are preparing that release.

## Done when

- [ ] `sh tools/check.sh` passes.
- [ ] The plugin's `CHANGELOG.md` has an entry under Unreleased when people would notice the change.
- [ ] Permissions the plugin uses are declared in its `plugin.json`.
