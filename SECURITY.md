# Security policy

## Supported versions

Security fixes go into the latest 1.x release.

## Reporting a vulnerability

Please don't open a public issue. Report it privately through GitHub:
<https://github.com/giacomomasseron/filo/security/advisories/new>

Include the filo and PHP versions, how filo was turned on (`.filo-on` or
`FILO_ENABLED`), the server setup (Herd, Valet, PHP-FPM, `php -S`, CLI) and
the steps to reproduce. Reporters are credited in the release notes unless
they'd rather not be.

## What filo protects, and what it doesn't

filo is a development tool. Install it with `composer require --dev` and
never turn it on in production.

- **Traces and snapshots hold sensitive data.** Traces in `.filo/traces/`
  contain file paths and function names; breakpoint snapshots contain
  argument values, `$this` and local variables. Keep `.filo/` out of version
  control and off shared machines. Arguments marked `#[\SensitiveParameter]`
  are redacted from snapshots.
- **The viewer is for your machine only.** `vendor/bin/filo serve` listens on
  `127.0.0.1`, refuses requests whose `Host` isn't a loopback name (a guard
  against DNS rebinding) and requires an `X-Filo` header on every change, so
  a cross-site request can't release a paused request or rewrite
  breakpoints. Don't expose its port: no `0.0.0.0`, tunnels or port
  forwarding.
- **filo fails open.** When a file can't be instrumented, the original is
  served. filo must never change what your code does or take a request down.

In scope, for example:

- a web page open in the developer's browser that can read traces or
  snapshots, or control the viewer
- path traversal or source-code disclosure through the viewer
- filo changing what an application does, or crashing it
- a `#[\SensitiveParameter]` value reaching a snapshot

Out of scope: anything that needs filo turned on in production, the viewer
exposed on a network interface, or an attacker who can already read your
project directory.
