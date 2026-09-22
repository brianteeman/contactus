# Unit tests

Pure PHPUnit unit tests for Akeeba ContactUs. They cover what needs no Joomla at all: the version limits, the two
mixins every model and table leans on, and the shape of what the package ships. They run in milliseconds, without
Docker.

Everything that needs a booted Joomla — controllers, models, the ACL, mail, the templates — is tested end-to-end
instead; see [`tests/integration/README.md`](../tests/integration/README.md).

## Requirements

- PHP 8.5 with `dom` and `simplexml`. `composer.json` allows `>=8.1.0 <8.7`; the highest version that range allows
  and that is actually released today is 8.5, so that is the target.
- PHPUnit 11, installed **globally** with Composer — never as a project dependency:
  `composer global require phpunit/phpunit ^11`. Make sure Composer's global `vendor/bin` is on your `PATH`, so
  plain `phpunit` resolves.
- Nothing else: the bootstrap autoloads the component's classes itself, so `composer install` is not needed.

## Running

From the repository root:

```bash
phpunit                                      # the whole unit suite
phpunit --testdox                            # human-readable output
phpunit --filter VersionLimitsTest           # one class
phpunit UnitTest/Build/SqlSchemaTest.php     # one file
```

Configuration is `phpunit.xml` at the repository root; the bootstrap is `UnitTest/bootstrap.php`. It defines
`_JEXEC` and a stand-in `JVERSION`, autoloads the component's PSR-4 prefixes, and loads `UnitTest/Stubs/Factory.php`
— a stand-in for Joomla's `Factory`, reduced to the one question `VersionLimits` asks it (which application is
running), so both sides of the version-disclosure rule can be tested.

## What is here

| Test | Covers |
|---|---|
| `Helper/VersionLimitsTest` | The runtime version guard; its agreement with `composer.json`, `composer.lock` and the installer script; that only administrators are told the exact versions (8ef0aeb) |
| `Mixin/GetPropertiesAwareTraitTest` | What the tables expose as their columns — never the private and protected members |
| `Mixin/CMSObjectWorkaroundTraitTest` | The bridge between Joomla 5's `getError()` and Joomla 6's exceptions |
| `Build/SqlSchemaTest` | Update SQL vs. install SQL; MySQL vs. PostgreSQL schemas and schema versions; uninstall drops every table |
| `Build/LanguageFilesTest` | No key defined twice; every line parses the way Joomla parses it; translations keep the original's keys and `printf` placeholders; every key the code uses exists |
| `Build/PackageSurfaceTest` | `_JEXEC` guards; the manifest templates vs. the files on disk; custom form fields have their `addfieldprefix`; development files kept out of the package |

## Conventions

- Namespace `Akeeba\ContactUs\UnitTest\...`, mirroring the directory under `UnitTest/`.
- PHPUnit attributes (`#[CoversClass]`, `#[DataProvider]`), not annotations.
- Where the product is currently wrong, the test skips with `Known issue #N (see known-issues.md)` through
  `KnownIssueTrait::assertOrKnownIssue()` instead of failing or asserting the wrong behaviour; it passes once the
  issue is fixed.
