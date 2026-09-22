# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

See `README.md` for build prerequisites and the `buildfiles` sibling-directory layout.

This project's JS-only target is `phing compile-javascript` (not `compile-js` as in most Akeeba projects).

## Tests

- Unit tests: `phpunit` from the repository root (config `phpunit.xml`, tests in `UnitTest/`; see `UnitTest/README.md`). PHPUnit is installed globally, never as a project dependency.
- End-to-end tests: `tests/integration/docker/run.sh` provisions a disposable Joomla site in Docker and runs `phpunit -c phpunit-integration.xml` against it over HTTP; `--matrix` covers every supported Joomla/PHP pair. See `tests/integration/README.md`.
- Tests that hit a known product bug skip with `Known issue #N`, referring to the git-ignored `known-issues.md`; fixing the bug makes the test pass, after which the `assertOrKnownIssue()` call becomes a plain assertion.

There is no linting configured.

## Conventions

- All PHP files begin with `defined('_JEXEC') or die;` guard
- File header: `@package contactus`, `@copyright`, `@license` block
- Brace style: Allman (opening brace on new line)
- Database queries use Joomla's query builder with named parameter binding (`:paramName` + `->bind()`)

## Security Scope

- Application-level rate limiting is intentionally out of scope. Implementing it in Joomla would require substantial database reads and writes, which can become a DoS/DDoS amplifier and defeat the purpose of rate limiting. Rate limiting should be implemented externally using suitable web server configuration or a security-focused CDN such as Cloudflare.

## Packaging

The component manifest is `component/contactus.xml` — note: NOT inside `backend/`.
