# Security audits

## Maintaining `security.md` and `known-issues.md`

`security.md` and `known-issues.md` at the repository root are git-ignored, local-only documents that
track an ongoing security audit and a test-driven bug list respectively, worked through incrementally
across sessions (since 2026-09).

Format for each finding, established by the entries already fixed:

- A summary table row with severity / ID and a one-line `Status`: `Fixed ✅`, `Open 📥`, or
  `Not valid ❌` for findings the operator has overruled (see below).
- A `### <ID> — <title>` section with a `**Status.**` line, then a `**Decision & Implementation.**`
  paragraph explaining what was actually done (or why it was rejected), followed by the original finding
  text.

While a `known-issues.md` entry is open, the matching PHPUnit test uses `assertOrKnownIssue()`; once it
is fixed, that call becomes a plain assertion so a regression fails the suite loudly instead of skipping.

**Why:** I4 was once fixed by a commit but never marked as such in `security.md`, leaving the tracking
document stale.

**How to apply:** when asked to fix or address an item by ID (M1, L5, I3, …), first read its section in
`security.md` (or `known-issues.md`) for the full context and the audit's suggested fix; when done,
update both the table row and the detail section in the format above.

## Findings the operator ruled not valid

These came from the operator overruling `security.md` items during triage. Do not raise them again, and
mark findings of the same shape invalid rather than proposing a fix:

- **I6 — "I can hack myself" is not a security issue.** Unfiltered data rendered back only to the
  submitter's own session (e.g. re-showing what they typed when resuming a failed form submission) is a
  UX feature, not a vulnerability. The operator was emphatic about this.
- **L8 — a key scoped to local / development use only**, never trusted or shipped, is not a disclosure
  risk even if it is in the Git history. Confirm the scope before treating a leaked secret as real.
- **L9 — the ZIP packaging includes everything not explicitly excluded** (not an allow-list). This is a
  deliberate build convention that causes far fewer build mistakes; it is not a hardening gap.
- **Application-level rate limiting** is out of scope (see "Security Scope" in `AGENTS.md`).

**Why:** each is technically true but either not attacker-exploitable or a deliberate decision.

**How to apply:** before writing up a finding, check whether it has self-targeting-only impact, involves
a secret confirmed to be development-only, or objects to deliberate build / packaging house style. If so,
record it as `Not valid ❌` with the reason in these terms.
