import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Slot } from 'radix-ui';

const buttonVariants = cva(
    "inline-flex shrink-0 items-center justify-center gap-[7px] rounded-lg text-[13px] leading-[18px] font-medium whitespace-nowrap transition-[color,background-color,border-color] disabled:pointer-events-none disabled:opacity-45 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default:
                    'bg-primary border border-primary text-primary-foreground font-semibold hover:bg-primary-hover hover:border-primary-hover active:bg-primary-hover',
                outline: 'bg-background border-input text-foreground border hover:bg-row-hover active:bg-side-hover',
                secondary: 'bg-secondary text-secondary-foreground border border-transparent hover:bg-side-hover',
                ghost: 'border border-transparent bg-transparent hover:bg-row-hover active:bg-side-hover',
                destructive:
                    'text-destructive hover:bg-destructive-soft active:bg-destructive-soft border border-transparent bg-transparent',
                link: 'text-acc-text h-auto p-0 font-medium underline-offset-2 hover:underline',
            },
            size: {
                sm: 'h-8 px-3',
                default: 'h-9 px-3.5',
                lg: 'h-11 px-4 text-sm leading-5',
                icon: 'size-9 p-0',
                'icon-sm': 'size-8 p-0',
                'icon-lg': 'size-11 p-0',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant = 'default',
    size = 'default',
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    }) {
    const Comp = asChild ? Slot.Root : 'button';

    return (
        <Comp
            data-slot="button"
            data-variant={variant}
            data-size={size}
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
