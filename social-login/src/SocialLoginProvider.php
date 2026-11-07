<?php

declare(strict_types=1);

namespace Plugins\SocialLogin;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\PluginProvider;

/**
 * Configure in the admin (Settings → Google sign-in / GitHub sign-in), or
 * from the CLI:
 *
 *   bin/cms plugin:config social-login google.enabled=1 google.client_id=XXX.apps.googleusercontent.com
 *   bin/cms plugin:config social-login --secret=google.client_secret      (paste, Enter)
 *   bin/cms plugin:config social-login google.hosted_domain=example.com   (optional: Workspace domain only)
 *
 *   bin/cms plugin:config social-login github.enabled=1 github.client_id=Iv1.xxxx
 *   bin/cms plugin:config social-login --secret=github.client_secret
 *
 * Redirect URIs to register with the provider: <site url>/auth/google/callback
 * and <site url>/auth/github/callback.
 */
final class SocialLoginProvider extends PluginProvider
{
    public function boot(Cms $cms): void
    {
        $site = rtrim((string) $cms->config->get('app.url', ''), '/');
        foreach (['google' => 'Google', 'github' => 'GitHub'] as $id => $name) {
            $fields = [
                'enabled' => Schema::bool()->default(false)->description("Show \"Continue with {$name}\" on the sign-in page."),
                'client_id' => Schema::string()->max(255)->description("From the OAuth app you created at {$name}."),
                'client_secret' => Schema::string()->max(500),
            ];
            if ($id === 'google') {
                $fields['hosted_domain'] = Schema::string()->max(255)->pattern('[A-Za-z0-9.-]*')->description('Optional: only accept accounts from this Google Workspace domain.');
            }
            $cms->admin->settings(
                $id,
                "{$name} sign-in",
                Schema::object($fields),
                labels: ['enabled' => 'Enabled', 'client_id' => 'Client ID', 'client_secret' => 'Client secret', 'hosted_domain' => 'Workspace domain'],
                secrets: ['client_secret'],
                description: "Let people sign in with their {$name} account. Register {$site}/auth/{$id}/callback as the redirect URI.",
                capability: 'settings.manage',
            );
        }

        if ($this->enabled('google')) {
            $cms->auth->authenticator(new Google([
                'client_id' => (string) $this->setting('google.client_id', ''),
                'client_secret' => $this->secret('google.client_secret'),
                'hosted_domain' => (string) $this->setting('google.hosted_domain', ''),
            ]));
        }

        if ($this->enabled('github')) {
            $cms->auth->authenticator(new GitHub([
                'client_id' => (string) $this->setting('github.client_id', ''),
                'client_secret' => $this->secret('github.client_secret'),
            ]));
        }
    }

    private function enabled(string $provider): bool
    {
        return filter_var($this->setting($provider . '.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }
}
