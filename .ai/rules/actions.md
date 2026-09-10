---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions are single-verb handle() classes
An Action is a final class named for the verb it performs (CreateAgency, InviteUser), with exactly one public handle() method that a controller calls. No other public surface — a second entry point belongs in a second Action, not a second public method.

## Removal preserves only placements that began
Decision 28: employment history consists solely of deployment ranges. Rehire is MoveEmployee after a gap; no hired_at/separated_at columns or SeparateEmployee action exist. RemoveEmployee owns one transaction: use bare today(), close the open placement to today if starts <= today, delete a never-started placement if starts > today, leave closed rows alone, then soft-delete the employee. Closing real history keeps the unit's delete restriction; deleting a never-started row releases it.
