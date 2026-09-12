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

## M7 stores PDFs but does not digitally sign them
M7 may render, download, and store immutable canonical ledger PDFs in private S3-compatible storage, but its attestations remain user/role/timestamp records only. Certificate-backed PAdES signatures are a later milestone; do not add certificates, signature images, signing keys, or cryptographic signing to M7. Canonical and future signed PDFs follow the attendance-series retention policy and require independent backups.

## M7 attested ledger verification is independent of PDF archiving
Completing the application attestation chain always freezes a rendition and creates its public verification token. The QR resolves to the attested ledger HTML view, not to object storage. Agency PDF archiving is opt-in and defaults off: disabled agencies generate downloads from the frozen rendition and discard the bytes; enabled agencies may retain the immutable PDF through generic documents and locations. M7 does not implement cryptographic signatures.

## Employment status never drives behavior
Khronoz is employment-status agnostic across every milestone. Employee tags may be used by each agency for identification, display, and filtering only; never infer cadence, attendance calculations, policy, template, permissions, attestation, retention, or any other application behavior from employment status or tags.
