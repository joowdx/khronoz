import type { Unit } from '@/types';

/** One unit in tree order, with how deep it sits and whether anything hangs off it. */
export interface UnitNode<T extends Unit = Unit> {
    unit: T;
    depth: number;
    /** True while this unit is the last child of its parent — the elbow, not the tee. */
    last: boolean;
    children: number;
    /**
     * One flag per indent slot above this unit's own, outermost first: true
     * where a vertical guide must be drawn because that ancestor still has
     * siblings further down the list.
     *
     * Without it a three-level tree reads as disconnected ticks. Two siblings
     * at depth 1 are almost never adjacent rows — everything under the first
     * of them comes between — so the line that should join them has to be
     * drawn by the rows in between, and only they know whether to draw it.
     * Length is always `depth - 1` (empty at depth 0 and 1): the slot for the
     * immediate parent is the elbow itself, not a guide.
     */
    guides: boolean[];
}

/**
 * Flatten a flat list of units into tree order: every unit immediately
 * followed by its own subtree, depth-first.
 *
 * The resource sends units flat with `parent_id` and no nested `children`
 * (UnitResource's docblock), which is what lets one query feed four screens —
 * the tree on the units index, the parent picker on its forms, the unit
 * filter on the employees index and the move sheet. The ordering the server
 * applied (by name) is preserved inside each level, so a level's siblings
 * stay alphabetical while the nesting comes from here.
 *
 * A unit whose `parent_id` names a row that is not in the list is treated as
 * a root rather than dropped. That is not a hypothetical: the employees
 * index's filter is fed the whole tree, but a future scoped list (one
 * department's units, say) would otherwise silently lose everything under it.
 * `units_acyclic` already refuses a cycle at the database, so the walk cannot
 * loop; `seen` is there for the one case the trigger cannot catch — a list
 * assembled by hand in a test.
 */
export function flattenUnits<T extends Unit>(units: T[]): UnitNode<T>[] {
    const byParent = new Map<string | null, T[]>();
    const ids = new Set(units.map((unit) => unit.id));

    for (const unit of units) {
        const key = unit.parent_id !== null && ids.has(unit.parent_id) ? unit.parent_id : null;
        const siblings = byParent.get(key);

        if (siblings) {
            siblings.push(unit);
        } else {
            byParent.set(key, [unit]);
        }
    }

    const flat: UnitNode<T>[] = [];
    const seen = new Set<string>();

    function walk(parent: string | null, depth: number, guides: boolean[]): void {
        const siblings = byParent.get(parent) ?? [];

        siblings.forEach((unit, index) => {
            if (seen.has(unit.id)) {
                return;
            }

            const last = index === siblings.length - 1;

            seen.add(unit.id);
            flat.push({
                unit,
                depth,
                last,
                children: (byParent.get(unit.id) ?? []).length,
                guides,
            });
            // A root's children need no guide slot at all — the elbow is
            // their whole indent. Below that, every level inherits its
            // ancestors' guides plus one for this unit.
            walk(unit.id, depth + 1, depth === 0 ? [] : [...guides, !last]);
        });
    }

    walk(null, 0, []);

    return flat;
}

/**
 * "Administrative Division" under "Office of the Executive Director" reads as
 * "Office of the Executive Director / Administrative Division" — the path a
 * picker's option needs so two units with the same name in different branches
 * are told apart, and the string a `cmdk` filter should match against.
 */
export function unitPath(unit: Unit, units: Unit[]): string {
    const byId = new Map(units.map((candidate) => [candidate.id, candidate]));
    // The unit's own name goes in first and unconditionally: an incomplete
    // list — one unit, or none — must still name the unit rather than
    // returning an empty string where a path was expected.
    const names: string[] = [unit.name];

    let current = unit.parent_id ? byId.get(unit.parent_id) : undefined;

    // Bounded by the list, so a hand-built cycle in a test cannot spin here;
    // `units_acyclic` already refuses one in the database.
    while (current && names.length <= units.length) {
        names.unshift(current.name);
        current = current.parent_id ? byId.get(current.parent_id) : undefined;
    }

    return names.join(' / ');
}

/**
 * Every unit at or under `id`, as a set — what a parent picker must refuse to
 * offer, because choosing one would make a cycle.
 *
 * `units_parent_not_self` and the `units_acyclic` trigger both refuse a cycle
 * at the database, and StoreUnitRequest deliberately leaves them to it rather
 * than keeping a second copy of the rule. That is the right split, and it is
 * also why this exists: the database's refusal arrives as an unhandled
 * SQLSTATE, not as a message on a label row, so the picker's job is to make
 * the mistake unreachable rather than to validate it.
 */
export function subtreeIds(id: string, units: Unit[]): Set<string> {
    const children = new Map<string, string[]>();

    for (const unit of units) {
        if (unit.parent_id === null) {
            continue;
        }

        const siblings = children.get(unit.parent_id);

        if (siblings) {
            siblings.push(unit.id);
        } else {
            children.set(unit.parent_id, [unit.id]);
        }
    }

    const subtree = new Set<string>([id]);
    const pending = [id];

    while (pending.length > 0) {
        for (const child of children.get(pending.pop() as string) ?? []) {
            if (! subtree.has(child)) {
                subtree.add(child);
                pending.push(child);
            }
        }
    }

    return subtree;
}
