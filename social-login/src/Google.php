<?php

declare(strict_types=1);

namespace Plugins\SocialLogin;

use Kestello\Cms\Auth\External\OidcAuthenticator;
use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Contracts\Auth\ExternalIdentity;

/**
 * Google is a standard OpenID Connect provider: core does discovery, PKCE,
 * the code exchange and ID-token verification. This class only names the
 * issuer and adds the optional Workspace domain restriction.
 */
final class Google extends OidcAuthenticator
{
    public function id(): string
    {
        return 'google';
    }
    public function label(): string
    {
        return 'Continue with Google';
    }
    public function icon(): ?string
    {
        return 'google-logo';
    }
    public function trustsEmail(): bool
    {
        return true;
    }

    protected function issuer(): string
    {
        return 'https://accounts.google.com';
    }

    /** @return array<string, string> */
    protected function authorizationParams(AuthAttempt $attempt): array
    {
        $params = parent::authorizationParams($attempt) + ['prompt' => 'select_account'];
        if ($this->hostedDomain() !== '') {
            $params['hd'] = $this->hostedDomain(); // only a hint for the account chooser…
        }

        return $params;
    }

    protected function identityFromClaims(array $claims): ExternalIdentity
    {
        // …so the verified "hd" claim is what actually enforces it.
        if ($this->hostedDomain() !== '' && ($claims['hd'] ?? null) !== $this->hostedDomain()) {
            throw new AuthenticationFailed('Use your ' . $this->hostedDomain() . ' Google account.', 'hd claim mismatch');
        }

        return parent::identityFromClaims($claims);
    }

    private function hostedDomain(): string
    {
        return strtolower(trim((string) ($this->config['hosted_domain'] ?? '')));
    }
}
