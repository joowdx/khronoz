---
paths:
  - '**/*'
  - '**/*.{php,ts,tsx,js,jsx,css,scss}'
---

# General

## Credit every material author in commits
Before committing, add a `Co-Authored-By` trailer for every person or agent that materially authored the staged changes. Preserve known contributors when amending; do not credit agents that only reviewed or formatted.

## Keep source comments minimal and defer PHPDoc to Pint
Keep prose comments only for non-obvious architectural constraints, integrity or security boundaries, required ordering, framework traps, or real edge cases; remove narration, anecdotes, history, measurements, and task or milestone references. Use PHPDoc for useful descriptions and type information that Pint retains, but do not require `@param` or `@return` tags that repeat native signatures. Keep every Pint rule enabled and accept its output as authoritative; never restore anything Pint removes.
