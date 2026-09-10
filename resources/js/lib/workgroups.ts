import type { Workgroup } from '@/types';

/** One workgroup in tree order, with how deep it sits and whether anything hangs off it. */
export interface WorkgroupNode<T extends Workgroup = Workgroup> {
    workgroup: T;
    depth: number;
    /** True while this workgroup is the last child of its parent — the elbow, not the tee. */
    last: boolean;
    children: number;
    /**
     * One flag per indent slot above this workgroup's own, outermost first: true
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
 * Flatten a flat list of workgroups into tree order: every workgroup immediately
 * followed by its own subtree, depth-first.
 *
 * The resource sends workgroups flat with `parent_id` and no nested `children`
 * (WorkgroupResource's docblock), which is what lets one query feed four screens —
 * the tree on the workgroups index, the parent picker on its forms, the workgroup
 * filter on the employees index and the move sheet. The ordering the server
 * applied (by name) is preserved inside each level, so a level's siblings
 * stay alphabetical while the nesting comes from here.
 *
 * A workgroup whose `parent_id` names a row that is not in the list is treated as
 * a root rather than dropped. That is not a hypothetical: the employees
 * index's filter is fed the whole tree, but a future scoped list (one
 * department's workgroups, say) would otherwise silently lose everything under it.
 * `workgroups_acyclic` already refuses a cycle at the database, so the walk cannot
 * loop; `seen` is there for the one case the trigger cannot catch — a list
 * assembled by hand in a test.
 */
export function flattenWorkgroups<T extends Workgroup>(workgroups: T[]): WorkgroupNode<T>[] {
    const byParent = new Map<string | null, T[]>();
    const ids = new Set(workgroups.map((workgroup) => workgroup.id));

    for (const workgroup of workgroups) {
        const key = workgroup.parent_id !== null && ids.has(workgroup.parent_id) ? workgroup.parent_id : null;
        const siblings = byParent.get(key);

        if (siblings) {
            siblings.push(workgroup);
        } else {
            byParent.set(key, [workgroup]);
        }
    }

    const flat: WorkgroupNode<T>[] = [];
    const seen = new Set<string>();

    function walk(parent: string | null, depth: number, guides: boolean[]): void {
        const siblings = byParent.get(parent) ?? [];

        siblings.forEach((workgroup, index) => {
            if (seen.has(workgroup.id)) {
                return;
            }

            const last = index === siblings.length - 1;

            seen.add(workgroup.id);
            flat.push({
                workgroup,
                depth,
                last,
                children: (byParent.get(workgroup.id) ?? []).length,
                guides,
            });
            // A root's children need no guide slot at all — the elbow is
            // their whole indent. Below that, every level inherits its
            // ancestors' guides plus one for this workgroup.
            walk(workgroup.id, depth + 1, depth === 0 ? [] : [...guides, !last]);
        });
    }

    walk(null, 0, []);

    return flat;
}

/**
 * "Administrative Division" under "Office of the Executive Director" reads as
 * "Office of the Executive Director / Administrative Division" — the path a
 * picker's option needs so two workgroups with the same name in different branches
 * are told apart, and the string a `cmdk` filter should match against.
 */
export function workgroupPath(workgroup: Workgroup, workgroups: Workgroup[]): string {
    const byId = new Map(workgroups.map((candidate) => [candidate.id, candidate]));
    // The workgroup's own name goes in first and unconditionally: an incomplete
    // list — one workgroup, or none — must still name the workgroup rather than
    // returning an empty string where a path was expected.
    const names: string[] = [workgroup.name];

    let current = workgroup.parent_id ? byId.get(workgroup.parent_id) : undefined;

    // Bounded by the list, so a hand-built cycle in a test cannot spin here;
    // `workgroups_acyclic` already refuses one in the database.
    while (current && names.length <= workgroups.length) {
        names.unshift(current.name);
        current = current.parent_id ? byId.get(current.parent_id) : undefined;
    }

    return names.join(' / ');
}

/**
 * Every workgroup at or under `id`, as a set — what a parent picker must refuse to
 * offer, because choosing one would make a cycle.
 *
 * `workgroups_parent_not_self` and the `workgroups_acyclic` trigger both refuse a cycle
 * at the database, and StoreWorkgroupRequest deliberately leaves them to it rather
 * than keeping a second copy of the rule. That is the right split, and it is
 * also why this exists: the database's refusal arrives as an unhandled
 * SQLSTATE, not as a message on a label row, so the picker's job is to make
 * the mistake unreachable rather than to validate it.
 */
export function subtreeIds(id: string, workgroups: Workgroup[]): Set<string> {
    const children = new Map<string, string[]>();

    for (const workgroup of workgroups) {
        if (workgroup.parent_id === null) {
            continue;
        }

        const siblings = children.get(workgroup.parent_id);

        if (siblings) {
            siblings.push(workgroup.id);
        } else {
            children.set(workgroup.parent_id, [workgroup.id]);
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
