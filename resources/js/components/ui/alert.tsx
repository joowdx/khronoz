import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/**
 * The form-level banner: a failure that belongs to no single field, so it sits
 * above the fields and no control's border changes. The icon carries the
 * family's colour and the text stays ink, because the sentence has to stay
 * readable — a whole paragraph in fault red does not.
 */
const alertVariants = cva(
    'relative flex w-full items-start gap-2.5 rounded-lg px-[13px] py-[11px] text-[13px] leading-[19px] [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-muted text-foreground [&>svg]:text-muted-foreground',
                destructive: 'bg-destructive-soft text-foreground [&>svg]:text-destructive',
                attention: 'bg-attention-soft text-foreground [&>svg]:text-attention',
                positive: 'bg-positive-soft text-foreground [&>svg]:text-positive',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Alert({ className, variant, ...props }: React.ComponentProps<'div'> & VariantProps<typeof alertVariants>) {
    return <div data-slot="alert" role="alert" className={cn(alertVariants({ variant }), className)} {...props} />;
}

function AlertTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-title" className={cn('font-semibold', className)} {...props} />;
}

function AlertDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return <div data-slot="alert-description" className={cn('min-w-0', className)} {...props} />;
}

export { Alert, AlertTitle, AlertDescription, alertVariants };
