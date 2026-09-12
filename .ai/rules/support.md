---
paths:
  - 'app/Support/**'
---

# Support

## Legal texts retain immutable deployable versions
Legal documents live in resources/legal with a manifest and SHA-256 hashes; retain every published version and its exact bytes. Change the version for revisions, including draft-to-published transitions; never resolve mutable operator/contact placeholders into old texts. Run legal:check --published before production launch. Privacy acknowledgment is not blanket processing consent.
