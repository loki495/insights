<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in, off by default: signs an existing account in automatically for the deployment owner so they
 * skip the login page. Works on any deployment (demo, dev or real); it only ever signs in
 * config('app.auto_login_email') - never creates an account. In demo mode that defaults to the shared
 * demo account.
 *
 * A request counts as LAN when auto_login_lan is on, it carries no Cloudflare edge header (CF-Connecting-IP/
 * CF-Ray) and its client IP is private. That IP is the direct peer unless the peer is in trusted_proxies, so
 * it's only enabled while every trusted proxy lies inside a private or loopback block: any entry reaching
 * public space ("*", REMOTE_ADDR, 0.0.0.0/0, a public range) would let a client forge a LAN address through
 * X-Forwarded-For. A request that came through Cloudflare is never auto-logged-in: anyone reaching the app
 * directly could forge its headers. Only valid when nothing but the tunnel and the LAN can reach this app.
 * Must run after StartSession (and ResolveDemoDatabase in demo mode) and before the auth gate.
 */
class AutoLoginForTrustedRequests
{
    private const array PRIVATE_BLOCKS = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', 'fc00::/7', '::1/128',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = $this->accountEmail();

        if ($email !== null && ! Auth::check() && $this->isTrusted($request)) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                Auth::login($user);
            }
        }

        return $next($request);
    }

    private function accountEmail(): ?string
    {
        $email = config('app.auto_login_email') ?: (config('app.demo_mode') ? config('app.demo_login_email') : null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function isTrusted(Request $request): bool
    {
        if (! config('app.auto_login_lan')
            || ! $this->onlyPrivateProxies(config('app.trusted_proxies'))
            || $request->headers->has('CF-Connecting-IP')
            || $request->headers->has('CF-Ray')) {
            return false;
        }

        $ip = $request->ip();

        return $ip !== null
            && filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    /**
     * A CIDR lies inside a block when its prefix is at least the block's and its address is in the block.
     *
     * @param  list<string>  $proxies
     */
    private function onlyPrivateProxies(array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            $address = strstr($proxy, '/', true) ?: $proxy;
            $bits = $address === $proxy ? (str_contains($proxy, ':') ? '128' : '32') : substr($proxy, strlen($address) + 1);

            $inside = ctype_digit($bits) && array_any(
                self::PRIVATE_BLOCKS,
                fn (string $block): bool => (int) $bits >= (int) explode('/', $block)[1] && IpUtils::checkIp($address, $block),
            );

            if (! $inside) {
                return false;
            }
        }

        return true;
    }
}
