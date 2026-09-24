# Bundled third-party dependencies

## HTML Purifier is namespace-scoped with PHP-Scoper, not Mozart

HTML Purifier (ezyang/htmlpurifier) declares global, underscore-pseudo-namespaced classes
(`HTMLPurifier_Config`). It is bundled namespace-isolated under
`Akeeba\Component\ContactUs\Site\Dependency` in `component/frontend/src/Dependency/`, generated with
`humbug/php-scoper` from `scoper.inc.php` at the repository root. The scoped output is committed, not
regenerated at build time; `humbug/php-scoper` and the unscoped package are `require-dev` only.

**Why:** Mozart renames classes with a text regex that also matches prose like "…this class from
another…" in doc comments, then globally find-and-replaces every bogus "class name" it collected. On HTML
Purifier this corrupted PHP keywords (`if` became `AK_PREFIX_if`) and file-path strings, leaving most
files unparseable. PHP-Scoper works on a real PHP AST (`nikic/php-parser`), so it cannot misread comments
or strings as code. Legacy classes keep their short name but get a distinct FQCN, which is all isolation
needs.

**How to apply:** when updating or re-scoping HTML Purifier (or adding another global-class library), use
PHP-Scoper and redo the follow-on work, which no AST renamer does for you:

1. **Dynamic class names** built at runtime (`$class = "Prefix_$suffix"; new $class`, or
   `array('ClassName', 'method')` callables) are invisible to the renamer. Find each call site and patch it
   in the `patchers` of `scoper.inc.php` to prepend `__NAMESPACE__` (concatenation case) or use
   `__CLASS__` (own-class literal case).
2. **Serialised caches** embedding class names (HTML Purifier's `ConfigSchema/schema.ser`) break silently
   at `unserialize()` (an `__PHP_Incomplete_Class`, no error). Regenerate them from source against the
   scoped classes and commit the new blob.
3. **Autoloading:** Joomla's PSR-4 autoloader cannot map underscore class names to directories. A small
   `spl_autoload_register()` callback strips the namespace prefix and maps `_` to `/` + `.php`, mirroring
   the library's own bootstrap (`HTMLPurifier_Bootstrap::getPath()`); see
   `component/frontend/src/Helper/MailContentFilter.php`.
4. **`defined('_JEXEC') or die;`** is required in every shipped PHP file, bundled code included; the
   `patchers` callback inserts it right after the namespace declaration PHP-Scoper adds.
