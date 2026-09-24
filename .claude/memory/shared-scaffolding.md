# Shared scaffolding across sibling repositories

## Mixin files are copied byte-for-byte into sibling projects — fix them everywhere

At least `component/backend/src/Mixin/ViewLoadAnyTemplateTrait.php` is identical (apart from the
`namespace` line and the `@package` / `@copyright` / `@since` tags) in this repository and in at least
nine sibling Akeeba Joomla extension repositories next to it (`../admintools`, `../akeebabackup`,
`../ars`, `../ats`, `../com_datacompliance`, `../docimport`, `../onthos`, `../paddle`,
`../social-magick`).

**Why:** these are independent repositories with no shared package for this trait; it is copy-pasted
scaffolding. A bug found in one copy (e.g. the unsanitised `$view` parameter in `loadAnyTemplate()`, fixed
here in 2026-09) exists in every other copy until fixed there too. The operator expects every copy fixed
as standard practice, without being asked each time.

**How to apply:** whenever a task touches a file under `component/backend/src/Mixin/` (or any other file
that looks like generic Joomla-component scaffolding rather than ContactUs business logic):

1. Find the other copies, from the repository root:
   `find ../*/component -iname '<filename>' -not -path '*/vendor/*' -not -path '*/tests/*'`.
2. Diff a couple of them to confirm they are substantively identical, then apply the same fix to every
   copy.
3. Run each affected sibling's own test suite from its root to confirm nothing broke.
4. Match each repository's own commit-message convention (check its `git log`): `ats` and `docimport` use
   a `# [SEVERITY] …` first line tied to their `security.md`; admintools, akeebabackup, ars,
   com_datacompliance, onthos, paddle and social-magick use a plain descriptive first line.
