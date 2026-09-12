import type { Workgroup } from '@/types';

export interface WorkgroupNode<T extends Workgroup = Workgroup> {
    workgroup: T;
    depth: number;
    last: boolean;
    children: number;
    guides: boolean[];
}

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
            walk(workgroup.id, depth + 1, depth === 0 ? [] : [...guides, !last]);
        });
    }

    walk(null, 0, []);

    return flat;
}

export function workgroupPath(workgroup: Workgroup, workgroups: Workgroup[]): string {
    const byId = new Map(workgroups.map((candidate) => [candidate.id, candidate]));
    const names: string[] = [workgroup.name];

    let current = workgroup.parent_id ? byId.get(workgroup.parent_id) : undefined;

    while (current && names.length <= workgroups.length) {
        names.unshift(current.name);
        current = current.parent_id ? byId.get(current.parent_id) : undefined;
    }

    return names.join(' / ');
}

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
