# Quality Checklist — openapi-php-docs

## Format checks
- [ ] SKILL.md has valid frontmatter (name, description, version, metadata)
- [ ] description is a decision boundary, not a capability list
- [ ] All workflow steps have Input / Action / Output / Validation
- [ ] output_contract specifies field order and what is forbidden

## Conventions checks
- [ ] Every controller example uses `ref:` for existing components, not inline duplication
- [ ] PSR-4 rule documented: filename = class name
- [ ] Numeric-only filenames (400.php) are explicitly prohibited with reason
- [ ] `composer openapi` command includes Docker wrapper
- [ ] Schema name convention (PascalCase + group prefix) documented

## Common errors to prevent
- [ ] Writing inline OAT that duplicates an existing `app/OpenApi/` component
- [ ] Creating `400.php` instead of `Http400.php` (breaks PSR-4, requires classmap)
- [ ] Running `composer openapi` without Docker prefix
- [ ] Editing `public/openapi.json` directly instead of regenerating
- [ ] Missing `declare(strict_types=1)` in new component files

## Readiness gate
- [ ] SKILL.md — PASS
- [ ] references/project-structure.md — PASS
- [ ] references/conventions.md — PASS
- [ ] assets/evals/evals.json — PASS
- [ ] Overall: PASS / FAIL

Last checked: 2026-04-04
