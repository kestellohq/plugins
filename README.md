# Kestello plugins

First-party plugins for [Kestello](https://github.com/kestellohq/cms), each released on its own.

| Plugin | What it does |
| --- | --- |
| [`meilisearch`](meilisearch) | Site search on a Meilisearch server. Choose it in **System > Platform > Search**. |
| [`typesense`](typesense) | Site search on a Typesense server. Choose it in **System > Platform > Search**. |
| [`social-login`](social-login) | Sign in with Google (OpenID Connect) or GitHub (OAuth 2). |

Install one from its release: download the zip under [Releases](https://github.com/kestellohq/plugins/releases) and upload it in **Plugins > Add a plugin**. The guides are in the [Kestello documentation](https://docs.kestello.com).

## Develop

You need PHP 8.4 or newer and Git. Nothing is installed with Composer.

```sh
sh tools/setup.sh                 # Kestello in kestello/ (a link to ../cms, or a clone) and the PHP tools
sh tools/check.sh                 # syntax, code style, PHPStan, tests and plugin:check for every plugin
sh tools/check.sh typesense       # one plugin
sh tools/check.sh --fix           # format the code
```

Try a plugin on your local site by linking it into the site's `content/plugins`:

```sh
ln -s "$PWD/typesense" ../cms/content/plugins/typesense
docker exec cms_app php bin/cms plugin:activate typesense
```

Tests extend Kestello's test classes and run with Kestello's runner (`php kestello/bin/cms test "$PWD/typesense/tests"`). A test that needs core's tables uses `self::corePath('database/migrations')`.

## Release

One plugin at a time, from `main`:

1. Set the new `version` in `<slug>/plugin.json`.
2. Move the plugin's `CHANGELOG.md` entries from **Unreleased** to a `## [X.Y.Z] - YYYY-MM-DD` section.
3. Merge to `main` and tag that commit `<slug>-vX.Y.Z` (`git tag -s typesense-v1.1.0`).

The [Release workflow](.github/workflows/release.yml) runs the checks against Kestello's `main`, then Kestello's [package workflow](https://github.com/kestellohq/cms/blob/main/.github/workflows/package.yml) builds the zip, signs it with the `KESTELLO_PACKAGE_KEY` secret and publishes the release with the changelog section as its notes. Sites install signed plugins when the public key is in their `app.packages.trusted_keys`.

## License

GPL-2.0-or-later, like Kestello. See [LICENSE](LICENSE).
