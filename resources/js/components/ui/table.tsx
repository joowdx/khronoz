import * as React from 'react';
import { cn } from '@/lib/utils';
import { ChevronUpIcon } from 'lucide-react';

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
