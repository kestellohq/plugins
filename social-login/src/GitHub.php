<?php

declare(strict_types=1);

namespace Plugins\SocialLogin;

use Kestello\Cms\Auth\External\OAuth2Authenticator;
use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Contracts\Auth\ExternalIdentity;

/**
 * GitHub speaks plain OAuth 2 (no ID token), so the identity comes from its
 * REST API: the numeric user id is the stable subject (logins can be
 * renamed), and only a primary *verified* email is reported as verified.
 */
final class GitHub extends OAuth2Authenticator
{
    private const string API = 'https://api.github.com';
    private const array HEADERS = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];

    public function id(): string
    {
        return 'github';
    }
    public function label(): string
    {
        return 'Continue with GitHub';
    }
    public function icon(): ?string
    {
        return 'github-logo';
    }
    public function trustsEmail(): bool
    {
        return true;
    }

    protected function authorizationEndpoint(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }
    protected function tokenEndpoint(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }
    protected function scopes(): array
    {
        return ['read:user', 'user:email'];
    }

    protected function mapUser(array $tokens, AuthAttempt $attempt): ExternalIdentity
    {
        $token = (string) $tokens['access_token'];
        $user = $this->getJson(self::API . '/user', $token, self::HEADERS);
        if (!isset($user['id']) || !is_int($user['id'])) {
            throw new AuthenticationFailed(detail: 'GitHub /user has no numeric id');
        }

        $email = null;
        $verified = false;
        foreach ($this->getJson(self::API . '/user/emails', $token, self::HEADERS) as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && is_string($entry['email'] ?? null)) {
                $email = $entry['email'];
                $verified = ($entry['verified'] ?? false) === true;
            }
        }

        return new ExternalIdentity(
            provider: $this->id(),
            subject: (string) $user['id'],
            email: $email,
            emailVerified: $verified,
            name: is_string($user['name'] ?? null) && $user['name'] !== '' ? $user['name'] : (string) ($user['login'] ?? ''),
            avatarUrl: is_string($user['avatar_url'] ?? null) ? $user['avatar_url'] : null,
            claims: ['login' => $user['login'] ?? null],
        );
    }
}
