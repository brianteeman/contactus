# Translations

## Translation flow (GitHub issue #24)

**Source language:** en-GB is always the canonical source. Supported translations (added 2026-06-17):
el-GR, fr-FR, de-DE, es-ES, it-IT, pt-PT.

**Language files**, per language tag `{lang}`:

- `component/backend/language/{lang}/com_contactus.ini` — backend UI strings
- `component/backend/language/{lang}/com_contactus.sys.ini` — menu / installer strings
- `component/frontend/language/{lang}/com_contactus.ini` — frontend form and email strings
- `build/templates/language/{lang}/pkg_contactus.sys.ini` — package installer strings

**Glossaries:** `build/glossaries/{lang}.md` — English → translation term tables. Consult them before
translating and add any missing terms.

**Manifests to update when adding a language (all four):**

- `component/contactus.xml` — `<language tag="{lang}">` entries in both
  `<languages folder="backend/language">` and `<languages folder="frontend/language">`
- `pkg_contactus.xml` — the `<languages folder="language">` section
- `build/templates/contactus.xml` — same as `component/contactus.xml` (build template)
- `build/templates/pkg_contactus.xml` — same as `pkg_contactus.xml` (build template)

**Large files:** above roughly 10–12 KiB, process in chunks ending on whole lines.

**Why:** GitHub issue #24 established this workflow for adding machine translations.

**How to apply:** when adding translations or a new language, follow this file layout, consult and update
the glossaries first, then update all four XML manifests.
