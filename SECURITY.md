# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Email **hello@indianopinion.org** with "security" in the subject. Please do not open a public issue for a security problem.

Include what you found, the version, the steps to reproduce, and the impact you expect (for example a response for an identified visitor being marked cacheable, or a header injection).

What to expect:

- An acknowledgement within 3 working days.
- An assessment and a plan within 10 working days.
- A fix released as soon as it is ready, normally within 30 days for confirmed issues, with credit in the changelog unless you prefer otherwise.

## Scope

The most serious class of bug for this plugin is a response generated for an identified visitor (session, login cookie, `Authorization`, nonce, preview, password, cart) being marked PUBLIC. Reports of that kind are treated as urgent.
