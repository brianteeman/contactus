# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Akeeba ContactUs is a Joomla 4/5/6 component providing category-based contact forms with per-category email recipients, auto-responders, Akismet spam detection, and CAPTCHA support. Licensed under GPL v3+.

## Build Commands

All build commands run from the repository root and require the `buildfiles` sibling directory ([Akeeba Build Tools](https://github.com/akeeba/buildfiles)).

```bash
# Build installable ZIP packages into release/
phing git

# Transpile/minify JavaScript only (Babel via buildfiles/node_modules)
phing compile-javascript
```

JavaScript pipeline: `component/media/js/frontend.js` → Babel with `@babel/preset-env` + minify → `component/media/js/frontend.min.js` (with source maps).

There are no automated tests or linting configured.

## Architecture

This is a standard Joomla component using the native MVC pattern under namespace `Akeeba\Component\ContactUs`.

### Directory Layout

- `component/backend/` — Administrator section (full CRUD for categories and contact items)
- `component/frontend/` — Site section (contact form display and submission)
- `component/media/` — CSS, JS, images, and `joomla.asset.json` manifest
- `build/` — Phing build templates and properties

### Key Namespaces

| Path | Namespace |
|------|-----------|
| `component/backend/src/` | `Akeeba\Component\ContactUs\Administrator\` |
| `component/frontend/src/` | `Akeeba\Component\ContactUs\Site\` |

### MVC Flow

- **Service provider**: `component/backend/services/provider.php` — registers MVCFactory, RouterFactory, DispatcherFactory via Joomla DI container
- **Dispatchers**: `{backend,frontend}/src/Dispatcher/Dispatcher.php` — route requests; frontend defaults to controller `item`, backend defaults to `items`
- **Controllers**: `{backend,frontend}/src/Controller/` — handle actions
- **Models**: `{backend,frontend}/src/Model/` — data access; frontend `ItemModel` extends the backend `ItemModel` adding consent/CAPTCHA validation, Akismet checks, and email sending
- **Views**: `{backend,frontend}/src/View/` — HtmlView classes
- **Templates**: `{backend,frontend}/tmpl/` — PHP view templates
- **Tables**: `backend/src/Table/` — `ItemTable` and `CategoryTable` (Joomla Table pattern)

### Shared Traits (Mixins)

`component/backend/src/Mixin/` contains traits reused across admin and frontend: `TriggerEventTrait`, `RunPluginsTrait`, `ControllerEventsTrait`, `ViewToolbarTrait`, `TableCreateModifyTrait`, `CMSObjectWorkaroundTrait`, etc.

### Database

Two tables: `#__contactus_categories` (contact categories with recipient emails, auto-reply config) and `#__contactus_items` (submitted messages). Schemas and migrations in `component/backend/sql/` with MySQL and PostgreSQL support.

### Frontend Submission Flow

1. User loads form → `Site\Controller\ItemController` → `Site\View\Item\HtmlView`
2. User submits → `Site\Model\ItemModel::save()` validates consent, checks CAPTCHA, calls parent save, runs Akismet spam check, emails administrators (if not spam), sends auto-reply (if configured), redirects to `ThanksController`

## Conventions

- PHP namespaces follow `Akeeba\Component\ContactUs\{Administrator|Site}\{Layer}\{Name}`
- All PHP files begin with `defined('_JEXEC') or die;` guard
- File header: `@package contactus`, `@copyright`, `@license` block
- Forms defined as XML in `{backend,frontend}/forms/`
- Language strings in INI files under `{backend,frontend}/language/en-GB/`
- Brace style: Allman (opening brace on new line)
- Database queries use Joomla's query builder with named parameter binding (`:paramName` + `->bind()`)
- Component configuration defined in `component/backend/config.xml`

## Packaging

`pkg_contactus.xml` at root defines the installable Joomla package. The component manifest is `component/contactus.xml` (note: not inside `backend/`). Build output goes to `release/` (gitignored).
