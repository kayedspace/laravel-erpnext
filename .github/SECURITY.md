# Security Policy

## Supported versions

Security fixes land on the latest minor release. Older minors are not backported.

## Reporting a vulnerability

Please do **not** open a public issue.

Email <3likayed@gmail.com> with a description of the problem and, if you can, the
smallest code that demonstrates it. You will get an acknowledgement within a few days,
and a fix or an explanation of why it is not one.

Because this package holds credentials for a live ERP system, anything in these areas is
worth reporting even if you are unsure it is exploitable:

- Credentials reaching a host other than the configured site.
- A cached session (`sid`) being served to the wrong tenant.
- Secrets appearing in exception messages, logs or cache keys.
