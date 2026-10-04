# Security Policy

This app connects to real bank/credit accounts via Plaid, so please report
security issues privately rather than opening a public issue.

## Reporting a Vulnerability

Use GitHub's private vulnerability reporting:

1. Go to the [Security tab](../../security) of this repository.
2. Click **Report a vulnerability**.
3. Include as much detail as you can: steps to reproduce, affected
   version/commit, and potential impact.

You should receive an acknowledgement within a few days. Please don't
disclose the issue publicly until it's been addressed.

## Scope

This is a personal-use, self-hosted application (no hosted/multi-tenant
deployment). Reports involving authentication, authorization, or Plaid
credential/token handling are especially appreciated.

## Trust model

- **Owner auto-login (`AUTO_LOGIN_*`, off by default) trusts the network path, not a
  credential.** `AUTO_LOGIN_LAN` signs in any request from a private address with no Cloudflare
  header, so anyone on that network is treated as the owner. A request that arrives through
  Cloudflare is never auto-logged-in. See the README's "Owner auto-login" section.
- **`TRUSTED_PROXIES` decides whose `X-Forwarded-*` headers are believed.** Set it to your
  reverse proxy, never `*`; blank trusts none. Owner auto-login stays off unless every entry is a
  private or loopback address or subnet.
- **The production port binds to `127.0.0.1`** unless `APP_BIND_ADDRESS` says otherwise. Docker's
  published ports bypass host firewalls such as ufw.
