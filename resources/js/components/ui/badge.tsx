import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Slot } from 'radix-ui';

const badgeVariants = cva(
    'inline-flex h-[22px] w-fit shrink-0 items-center justify-center gap-1.5 overflow-hidden rounded-full border border-transparent px-[9px] text-xs leading-4 font-medium whitespace-nowrap [&>svg]:pointer-events-none [&>svg]:size-3',
    {
        variants: {
            variant: {
                default: 'bg-acc-soft text-acc-text',
                secondary: 'bg-rule text-muted-foreground',
                destructive: 'bg-destructive-soft text-destructive',
                attention: 'bg-attention-soft text-attention',
                positive: 'bg-positive-soft text-positive',
                outline: 'border-edge-soft text-muted-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

type StatusVariant = 'secondary' | 'destructive' | 'attention' | 'positive';

function Badge({
    className,
    variant = 'default',
    asChild = false,
    dot = false,
    children,
    ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants> & { asChild?: boolean; dot?: boolean }) {
    const Comp = asChild ? Slot.Root : 'span';

    return (
        <Comp data-slot="badge" data-variant={variant} className={cn(badgeVariants({ variant }), className)} {...props}>
            {dot && <span aria-hidden="true" className="size-[5px] shrink-0 rounded-full bg-current" />}
            {children}
        </Comp>
    );
}

function StatusPill({
    variant = 'secondary',
    ...props
}: Omit<React.ComponentProps<typeof Badge>, 'variant' | 'dot'> & { variant?: StatusVariant }) {
    return <Badge data-slot="status-pill" variant={variant} dot {...props} />;
}

export { Badge, StatusPill, badgeVariants };
