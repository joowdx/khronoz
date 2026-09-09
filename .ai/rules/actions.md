---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions are single-verb handle() classes
An Action is a final class named for the verb it performs (CreateAgency, InviteUser), with exactly one public handle() method that a controller calls. No other public surface — a second entry point belongs in a second Action, not a second public method.
