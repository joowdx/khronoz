---
paths:
  - 'app/Support/**'
---

# Support

## Legal texts retain immutable deployable versions
Legal documents live in resources/legal with a manifest and SHA-256 hashes; retain every published version and its exact bytes. Change the version for revisions, including draft-to-published transitions; never resolve mutable operator/contact placeholders into old texts. Run legal:check --published before production launch. Privacy acknowledgment is not blanket processing consent.

## Credential lookups and Apple callback isolation
Pre-authentication credential lookup is the only global Identity/Passkey lookup; settings use the authenticated owner's agency with Tenant::within. Social sign-in uses an explicitly linked provider subject, never matching email. OAuth state binds session, provider, purpose and user and is single-use; Apple POST is a sessionless encrypted short-lived relay, completed by the original session with nonce verification. Keep global CSRF and SameSite settings intact.
