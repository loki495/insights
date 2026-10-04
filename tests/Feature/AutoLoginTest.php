<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * The opt-in auto-login on a normal (non-demo) deployment: it only ever signs in an existing account named
 * by auto_login_email, and only for a trusted request.
 */
beforeEach(function (): void {
    /** @var TestCase $this */
    $this->withoutVite();
    User::factory()->create(['email' => 'owner@example.com']);
});

afterEach(fn () => TrustProxies::flushState());

function trustConfiguredProxies(): void
{
    new AppServiceProvider(app())->configureProxies();
}

it('does nothing by default', function (): void {
    /** @var TestCase $this */
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');
});

it('signs in the configured account for a LAN request when opted in', function (): void {
    /** @var TestCase $this */
    config(['app.auto_login_lan' => true, 'app.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertOk();

    $this->assertAuthenticated();
    expect(auth()->user()?->email)->toBe('owner@example.com');
});

it('does not sign in without an account configured, or for a missing account', function (): void {
    /** @var TestCase $this */
    config(['app.auto_login_lan' => true]);
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');

    config(['app.auto_login_email' => 'nobody@example.com']);
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');

    $this->assertGuest();
});

it('does not sign in a public address or a Cloudflare-routed request', function (): void {
    /** @var TestCase $this */
    config(['app.auto_login_lan' => true, 'app.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '8.8.8.8'])->assertRedirect('/login');
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'])
        ->assertRedirect('/login');
});

it('never signs in a request carrying Cloudflare headers naming the account, with LAN auto-login on or off', function (bool $lan): void {
    /** @var TestCase $this */
    config(['app.auto_login_lan' => $lan, 'app.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: [
        'REMOTE_ADDR' => '192.168.1.50',
        'HTTP_CF_RAY' => 'abc123',
        'HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL' => 'owner@example.com',
    ])->assertRedirect('/login');

    $this->assertGuest();
})->with([[true], [false]]);

it('ignores a private X-Forwarded-For from a peer that is not a trusted proxy', function (): void {
    /** @var TestCase $this */
    config(['app.auto_login_lan' => true, 'app.auto_login_email' => 'owner@example.com']);

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '192.168.1.50'])
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('uses the client address a trusted proxy forwards', function (string $client, bool $signedIn): void {
    /** @var TestCase $this */
    config([
        'app.auto_login_lan' => true,
        'app.auto_login_email' => 'owner@example.com',
        'app.trusted_proxies' => ['172.18.0.2'],
    ]);
    trustConfiguredProxies();

    $response = $this->call('GET', '/', server: ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => $client]);

    $signedIn ? $response->assertOk() : $response->assertRedirect('/login');
    expect(auth()->check())->toBe($signedIn);
})->with([
    'LAN client' => ['192.168.1.50', true],
    'internet client' => ['203.0.113.7', false],
    'internet client prepending a fake LAN hop' => ['192.168.1.50, 203.0.113.7', false],
]);

it('turns LAN auto-login off when a trusted proxy entry reaches public space', function (string $proxies): void {
    /** @var TestCase $this */
    config([
        'app.auto_login_lan' => true,
        'app.auto_login_email' => 'owner@example.com',
        'app.trusted_proxies' => explode(',', $proxies),
    ]);
    trustConfiguredProxies();

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertRedirect('/login');
    $this->call('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '192.168.1.50'])
        ->assertRedirect('/login');

    $this->assertGuest();
})->with([
    'wildcard' => ['*'],
    'double wildcard' => ['**'],
    'any peer keyword' => ['REMOTE_ADDR'],
    'all of IPv4' => ['0.0.0.0/0'],
    'all of IPv6' => ['::/0'],
    'public range' => ['203.0.113.0/24'],
    'private block widened past its prefix' => ['10.0.0.0/7'],
    'one bad entry among good ones' => ['172.18.0.0/16,203.0.113.7'],
    'malformed prefix' => ['10.0.0.0/x'],
]);

it('keeps LAN auto-login on for private and loopback proxy entries', function (string $proxies): void {
    /** @var TestCase $this */
    config([
        'app.auto_login_lan' => true,
        'app.auto_login_email' => 'owner@example.com',
        'app.trusted_proxies' => explode(',', $proxies),
    ]);
    trustConfiguredProxies();

    $this->call('GET', '/', server: ['REMOTE_ADDR' => '192.168.1.50'])->assertOk();

    $this->assertAuthenticated();
})->with([
    'Docker subnet' => ['172.18.0.0/16'],
    'single LAN host and loopback' => ['192.168.1.2,127.0.0.1'],
    'IPv6 unique-local' => ['fd00::/8'],
]);
