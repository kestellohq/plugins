# Social Login

Let people sign in to your Kestello site with their Google or GitHub account, beside the email and password form. Google signs in with OpenID Connect, GitHub with OAuth 2.

It is also the reference for writing your own sign-in provider: it adds both through Kestello's authenticator API, with no changes to core.

## Set it up

1. Activate the plugin under **Plugins**.
2. Create an OAuth app with the provider:
   - **Google**: in the Google Cloud console, create an OAuth client ID of the type *Web application*.
   - **GitHub**: under *Settings → Developer settings → OAuth Apps*, create a new OAuth app.
3. Register the redirect URI the settings show: `https://your-site/auth/google/callback` or `https://your-site/auth/github/callback`.
4. In Kestello, open **Settings → Google sign-in** or **Settings → GitHub sign-in**, paste the client ID and secret, and tick **Enabled**.

"Continue with Google" or "Continue with GitHub" then shows on the sign-in page.

With Google you can accept only accounts from your Google Workspace domain: fill in **Workspace domain**. Kestello checks the domain Google signs into the reply, not only what the sign-in screen offered.

You can also set it up from the command line:

```sh
php bin/cms plugin:config social-login google.enabled=1 google.client_id=XXX.apps.googleusercontent.com
php bin/cms plugin:config social-login --secret=google.client_secret
```

## Who can get in

- Someone who already has an account connects Google or GitHub under **Profile → Connected sign-ins**, then signs in with it.
- Signing in with a provider whose confirmed email matches an existing account connects them only when you allow it under **Settings → Accounts**. It is off by default.
- Whether someone without an account may create one this way follows your site's sign-up setting under **Settings → Accounts**, as for email sign-up. It is closed by default.
- An email the provider has not confirmed is never used to create or connect an account. With GitHub, only the primary, verified email counts.

The client secrets are stored sealed with the site's key.

## What it may do

The plugin declares these permissions, shown before it installs:

- **Network**: `accounts.google.com`, `oauth2.googleapis.com`, `www.googleapis.com`, `openidconnect.googleapis.com`, `github.com` and `api.github.com`, and no other host.
- **Sign-in**: it adds sign-in providers.
- **Users**: it creates accounts and links provider identities to them.

## Requirements

Kestello 0.1 or newer, PHP 8.4 or newer, and an OAuth app at Google or GitHub.

Guides are at [docs.kestello.com](https://docs.kestello.com). Licensed under the GPL, version 2 or later.
