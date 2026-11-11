# Security policy

## Reporting a vulnerability

Please report security problems **privately** through GitHub's security advisories:
open the repository's **Security** tab and choose **Report a vulnerability**
(https://github.com/cbagdawala/india-saas-starter/security/advisories/new). Do not open a public issue or pull request
for a vulnerability, and do not include secrets or real customer data in a report.

Include what you found, how to reproduce it (a failing Pest test is ideal), the version or commit, and the impact you
expect. You will get an acknowledgement as soon as the maintainer can respond; this is a volunteer project, so there is no
guaranteed response time. Fixes are released as soon as they are ready, and credited in the advisory unless you prefer
not to be named.

## Supported versions

Only the latest release on the default branch receives security fixes.

## Scope notes

- This is a starter kit: you deploy it, so hardening your server, secrets, mail and payment accounts is your job. See
  "Before you go live" in the README.
- The tax and legal features (GST invoicing, data protection helpers) are not professional advice.
- Issues in third-party dependencies should be reported upstream; `composer audit` and `npm audit` are part of the checks.
