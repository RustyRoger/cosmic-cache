# Security Policy

## Reporting a vulnerability

If you believe you have found a security vulnerability in Cosmic Cache, please
**do not** open a public issue or pull request.

Report it privately via the repository's **Security** tab on GitHub
(security advisories / private vulnerability reporting), or by email to the
maintainer at the address listed on the repository profile page.

Please include:

- Affected version(s) and WordPress version.
- A description of the vulnerability and its impact.
- Steps to reproduce, or a minimal proof of concept.
- Your contact details if you would like credit or follow-up.

## Response expectations

- You will receive an acknowledgement within 5 business days.
- A fix will be coordinated and released as soon as possible; until then the
  vulnerability details stay private.
- Public disclosure happens after a fix is available (or after 30 days, if a
  fix cannot be produced).

## Scope

This policy covers the plugin code in this repository, including the compiled
drop-ins generated from `dropins/`. It does **not** cover the web server
configuration that operators choose (for example, a missing `mod_xsendfile`
or Nginx `internal` location for the body-delivery feature), which is
documented as an operator responsibility.

## Safe defaults

Cosmic Cache is designed to fail open: a cache error must never take down the
frontend, and the hot path never makes network requests or executes
attacker-controlled code. Please respect these properties when proposing
changes.
