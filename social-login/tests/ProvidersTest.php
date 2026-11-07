<?php

declare(strict_types=1);

namespace Plugins\SocialLogin\Tests;

use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Contracts\Auth\AuthenticationFailed;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Jwt\Base64Url;
use Kestello\Support\Jwt\Jwt;
use Kestello\Support\Jwt\JwtKey;
use Plugins\SocialLogin\GitHub;
use Plugins\SocialLogin\Google;

require_once dirname(__DIR__) . '/src/Google.php';
require_once dirname(__DIR__) . '/src/GitHub.php';

/** Google and GitHub sign-in against fake servers. Run from a Kestello checkout: php bin/cms test /path/to/social-login/tests */
final class ProvidersTest extends TestCase
{
    private function http(FakeTransport $fake): HttpClient
    {
        return new HttpClient($fake, static fn(): array => ['140.82.112.3']);
    }

    private static function callback(string $code = 'c0de'): Request
    {
        return new Request('GET', '/auth/x/callback', '/auth/x/callback', [], ['code' => $code], [], [], [], []);
    }

    public function testGitHubUsesTheNumericIdAndOnlyAVerifiedPrimaryEmail(): void
    {
        $fake = new FakeTransport([
            'POST https://github.com/login/oauth/access_token' => ['json' => ['access_token' => 'gho_x', 'token_type' => 'bearer']],
            'GET https://api.github.com/user' => ['json' => ['id' => 583231, 'login' => 'octocat', 'name' => '', 'avatar_url' => 'https://avatars.githubusercontent.com/u/583231']],
            'GET https://api.github.com/user/emails' => ['json' => [
                ['email' => 'old@example.test', 'primary' => false, 'verified' => true],
                ['email' => 'octo@example.test', 'primary' => true, 'verified' => false],
            ]],
        ]);
        $github = new GitHub(['client_id' => 'id', 'client_secret' => 'secret'], $this->http($fake));
        $attempt = AuthAttempt::start('github', 'login', 'https://site.example/auth/github/callback');

        $url = $github->authorizationUrl($attempt);
        $this->assertTrue(str_starts_with($url, 'https://github.com/login/oauth/authorize?'));
        $this->assertContains('code_challenge_method=S256', $url);
        $this->assertContains('scope=read%3Auser%20user%3Aemail', $url);

        $identity = $github->complete(self::callback(), $attempt);
        $this->assertSame(['github', '583231', 'octo@example.test', false, 'octocat'], [$identity->provider, $identity->subject, $identity->email, $identity->emailVerified, $identity->name]);
        $this->assertSame('Bearer gho_x', $fake->sent[1]->headers['Authorization']);
        parse_str((string) $fake->sent[0]->body, $form);
        $this->assertSame([$attempt->pkceVerifier, 'id'], [$form['code_verifier'], $form['client_id']]);
    }

    public function testGoogleVerifiesTheIdTokenAndHostedDomain(): void
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($pk, $pem);
        $d = openssl_pkey_get_details($pk)['rsa'];
        $claims = [];
        $attempt = AuthAttempt::start('google', 'login', 'https://site.example/auth/google/callback');

        $fake = new FakeTransport([
            'GET https://accounts.google.com/.well-known/openid-configuration' => ['json' => [
                'issuer' => 'https://accounts.google.com',
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_endpoint' => 'https://oauth2.googleapis.com/token',
                'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            ]],
            'GET https://www.googleapis.com/oauth2/v3/certs' => ['json' => ['keys' => [
                ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'g1', 'n' => Base64Url::encode($d['n']), 'e' => Base64Url::encode($d['e'])],
            ]]],
            'POST https://oauth2.googleapis.com/token' => function () use (&$claims, $pem, $attempt) {
                return ['json' => ['access_token' => 'ya29', 'id_token' => Jwt::encode($claims + [
                    'iss' => 'https://accounts.google.com', 'aud' => 'cid', 'azp' => 'cid', 'sub' => '1100',
                    'email' => 'ana@corp.example', 'email_verified' => true, 'hd' => 'corp.example',
                    'nonce' => $attempt->nonce, 'iat' => time(), 'exp' => time() + 3600,
                ], JwtKey::fromPem($pem, 'RS256', 'g1'))]];
            },
        ]);
        $google = new Google(['client_id' => 'cid', 'client_secret' => 's', 'hosted_domain' => 'corp.example'], $this->http($fake), new ArrayStore());

        $url = $google->authorizationUrl($attempt);
        $this->assertContains('hd=corp.example', $url);
        $this->assertContains('nonce=' . $attempt->nonce, $url);

        $identity = $google->complete(self::callback(), $attempt);
        $this->assertSame(['google', '1100', 'ana@corp.example', true], [$identity->provider, $identity->subject, $identity->email, $identity->emailVerified]);

        $claims = ['hd' => 'gmail.com'];
        $e = $this->assertThrows(AuthenticationFailed::class, fn() => $google->complete(self::callback(), $attempt));
        $this->assertContains('corp.example', $e->getMessage());
        $claims = ['hd' => null];
        $this->assertThrows(AuthenticationFailed::class, fn() => $google->complete(self::callback(), $attempt), 'personal account');
    }
}
