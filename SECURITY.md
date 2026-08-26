# Security policy

AlphaBridge MCP gives AI clients structured access to a WordPress site, so security reports are
taken seriously and handled with priority.

## Reporting a vulnerability

Please report privately — **not** through a public issue:

- **Email:** mail@cultureclub.dev
- Use the subject line `AlphaBridge MCP security`.

Helpful in a report: affected version, WordPress and PHP version, the tool or endpoint involved,
and the steps to reproduce. A proof of concept is welcome but not required.

## What to expect

- **Acknowledgement within 3 working days.**
- An assessment with a planned fix window once the report is confirmed.
- A fix released through WordPress.org, with the issue noted in the changelog.
- Credit in the release notes if you would like it — tell us the name to use, or say if you
  prefer to stay anonymous.

Please give us reasonable time to ship a fix before disclosing publicly.

## Supported versions

Fixes go into the current release on WordPress.org. Older versions are not patched separately —
please update to the latest version before reporting.

## Scope

In scope: this plugin's code — the MCP endpoint, authentication and OAuth flow, the tool layer,
capability and allowlist enforcement, the admin screens.

Out of scope: vulnerabilities in WordPress core, in other plugins or themes, in the hosting
environment, and findings that require an already-compromised administrator account.
