import * as React from 'react';
import { cn } from '@/lib/utils';
import { ChevronUpIcon } from 'lucide-react';

/**
 * Rules, not boxes: a 36px head separated by --border, 44px rows separated by
 * the softer --rule, and no shadow or outer frame. The head's background sits
 * on each `th` rather than on the `thead`, because a sticky `thead` paints
 * nothing — only its cells do — and rows would show through as they scroll
 * underneath.
 */
/**
 * The container is `overflow-x-clip`, not shadcn's `overflow-x-auto`. A
 * scrollable axis makes this div the nearest scrollport, and a sticky `thead`
 * then sticks to *it* rather than to the page's scroll container, which put
 * our heads 75px above the title bar and looked like sticky was simply broken.
 * `clip` is the one overflow value that leaves the other axis `visible`, so
 * the head keeps sticking at `top: var(--bar-h)`.
 *
 * A table that genuinely needs to scroll sideways — the roster grid in
 * Milestone 3 — opts in with `overflow-x-auto` on this slot, and accepts that
 * its head then sticks inside its own scrollport.
 */
function Table({ className, ...props }: React.ComponentProps<'table'>) {
    return (
        <div data-slot="table-container" className="relative w-full overflow-x-clip">
            <table
                data-slot="table"
                className={cn('w-full border-separate border-spacing-0 text-sm', className)}
                {...props}
            />
        </div>
    );
}

/**
 * `sticky` pins the head under the app bar. It is opt-in because it only
 * works inside the shell's own scroller — on a page that scrolls in the
 * window it would pin the head to the viewport instead.
 */
function TableHeader({ className, sticky = false, ...props }: React.ComponentProps<'thead'> & { sticky?: boolean }) {
    return (
        <thead
            data-slot="table-header"
            className={cn(sticky && '[&_th]:sticky [&_th]:top-[var(--bar-h)] [&_th]:z-10', className)}
            {...props}
        />
    );
}

function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
    return <tbody data-slot="table-body" className={cn('[&_tr:last-child_td]:border-b-0', className)} {...props} />;
}

function TableFooter({ className, ...props }: React.ComponentProps<'tfoot'>) {
    return (
        <tfoot
            data-slot="table-footer"
            className={cn('[&_td]:border-border [&_td]:border-t [&_td]:font-medium', className)}
            {...props}
        />
    );
}

function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
    return (
        <tr
            data-slot="table-row"
            className={cn(
                'hover:[&>td]:bg-row-hover aria-selected:[&>td]:bg-acc-tint data-[state=selected]:[&>td]:bg-acc-tint',
                className,
            )}
            {...props}
        />
    );
}

/** `numeric` right-aligns the column and swaps the caret to the label's left. */
function TableHead({ className, numeric = false, ...props }: React.ComponentProps<'th'> & { numeric?: boolean }) {
    return (
        <th
            data-slot="table-head"
            data-numeric={numeric ? '' : undefined}
            className={cn(
                'bg-background text-muted-foreground border-border h-9 border-b px-3 text-left align-middle text-xs leading-4 font-semibold whitespace-nowrap first:pl-6 last:pr-6',
                numeric && 'text-right tabular-nums',
                className,
            )}
            {...props}
        />
    );
}

/**
 * The sortable head is a real <button> inside the <th>, so it is reachable and
 * operable from the keyboard (2.1.1) and announces its own name; the sort
 * direction belongs to the column, so `aria-sort` goes on the <th> (4.1.2).
 * The caret only appears on hover or once the column is the sorted one, and at
 * 12px it never competes with the label.
 */
function TableSortButton({ className, children, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            type="button"
            data-slot="table-sort-button"
            className={cn(
                'inline-flex h-9 items-center gap-1.5 border-0 bg-transparent p-0 text-xs leading-4 font-semibold text-inherit',
                'hover:text-foreground',
                '[[aria-sort=ascending]_&]:text-foreground [[aria-sort=descending]_&]:text-foreground',
                '[[data-numeric]_&]:flex-row-reverse',
                '[&>svg]:text-edge-soft [&>svg]:size-3 [&>svg]:shrink-0 [&>svg]:opacity-0 hover:[&>svg]:opacity-100',
                // Only the sorted column carries the glyph, so the state keys
                // off the two real directions rather than the attribute's
                // presence — an unsorted head declares `aria-sort="none"` and
                // must still look unsorted.
                '[[aria-sort=ascending]_&>svg]:text-acc-text [[aria-sort=ascending]_&>svg]:opacity-100',
                '[[aria-sort=descending]_&>svg]:text-acc-text [[aria-sort=descending]_&>svg]:opacity-100',
                '[[aria-sort=descending]_&>svg]:rotate-180',
                className,
            )}
            {...props}
        >
            {children}
            <ChevronUpIcon aria-hidden="true" />
        </button>
    );
}

function TableCell({ className, numeric = false, ...props }: React.ComponentProps<'td'> & { numeric?: boolean }) {
    return (
        <td
            data-slot="table-cell"
            className={cn(
                'border-rule h-11 border-b px-3 align-middle whitespace-nowrap first:pl-6 last:pr-6',
                numeric && 'text-right tabular-nums',
                className,
            )}
            {...props}
        />
    );
}

function TableCaption({ className, ...props }: React.ComponentProps<'caption'>) {
    return (
        <caption data-slot="table-caption" className={cn('text-muted-foreground mt-4 text-xs', className)} {...props} />
    );
}

export { Table, TableHeader, TableBody, TableFooter, TableHead, TableSortButton, TableRow, TableCell, TableCaption };
