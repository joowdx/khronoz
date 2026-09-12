import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
    value: string;
    label: string;
    keywords?: string[];
    render?: ReactNode;
    trigger?: ReactNode;
    disabled?: boolean;
}

export function Combobox({
    options,
    value,
    onValueChange,
    placeholder,
    searchPlaceholder = 'Search',
    empty = 'Nothing matches.',
    clearLabel,
    name,
    id,
    variant = 'field',
    disabled = false,
    invalid,
    describedBy,
    className,
    label,
}: {
    options: ComboboxOption[];
    value: string | null;
    onValueChange: (value: string | null) => void;
    placeholder: string;
    searchPlaceholder?: string;
    empty?: ReactNode;
    clearLabel?: string;
    name?: string;
    id?: string;
    variant?: 'field' | 'inline';
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    className?: string;
    label?: string;
}) {
    const [open, setOpen] = useState(false);
    const chosen = options.find((option) => option.value === value) ?? null;

    function choose(next: string | null): void {
        onValueChange(next);
        setOpen(false);
    }

    return (
        <>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger
                    id={id}
                    aria-label={label ? `${label}: ${chosen?.label ?? placeholder}` : undefined}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    disabled={disabled}
                    className={cn(
                        'flex items-center gap-2 rounded-lg text-[13px] leading-[18px] font-medium transition-[color,background-color,border-color]',
                        "[&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                        'disabled:text-muted-foreground disabled:cursor-not-allowed',
                        variant === 'field' &&
                            'border-input bg-background h-9 w-full justify-between border px-3 hover:border-foreground focus-visible:hover:border-ring aria-invalid:border-destructive aria-invalid:focus-visible:outline-destructive disabled:bg-rule disabled:border-edge-soft',
                        variant === 'inline' &&
                            'hover:bg-row-hover aria-expanded:bg-row-hover -mx-2 h-8 max-w-full px-2',
                        variant === 'inline' &&
                            '[&>svg]:opacity-0 hover:[&>svg]:opacity-100 aria-expanded:[&>svg]:opacity-100 group-hover/row:[&>svg]:opacity-100 focus-visible:[&>svg]:opacity-100',
                        variant === 'inline' &&
                            '[&_[data-offer]]:hidden hover:[&_[data-offer]]:inline aria-expanded:[&_[data-offer]]:inline group-hover/row:[&_[data-offer]]:inline focus-visible:[&_[data-offer]]:inline',
                        variant === 'inline' &&
                            'hover:[&_[data-rest]]:hidden aria-expanded:[&_[data-rest]]:hidden group-hover/row:[&_[data-rest]]:hidden focus-visible:[&_[data-rest]]:hidden',
                        className,
                    )}
                >
                    <span className={cn('min-w-0 truncate', chosen === null && 'text-muted-foreground font-normal')}>
                        {chosen ? (
                            (chosen.trigger ?? chosen.label)
                        ) : variant === 'inline' ? (
                            <>
                                <span data-rest>—</span>
                                <span data-offer>{placeholder}</span>
                            </>
                        ) : (
                            placeholder
                        )}
                    </span>
                    <ChevronDownIcon aria-hidden strokeWidth={1.5} className="text-muted-foreground" />
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    sideOffset={6}
                    style={{ minWidth: 'max(232px, var(--radix-popover-trigger-width))' }}
                    className="w-max max-w-[min(420px,calc(100vw-2rem))] p-0"
                >
                    <Command
                        filter={(itemValue, search, keywords) => {
                            const haystack = [itemValue, ...(keywords ?? [])].join(' ').toLowerCase();

                            return haystack.includes(search.toLowerCase()) ? 1 : 0;
                        }}
                    >
                        <CommandInput placeholder={searchPlaceholder} />
                        <CommandList>
                            <CommandEmpty>{empty}</CommandEmpty>
                            <CommandGroup>
                                {clearLabel && (
                                    <CommandItem
                                        value="__clear__"
                                        keywords={[clearLabel]}
                                        onSelect={() => choose(null)}
                                        className="text-muted-foreground"
                                    >
                                        <CheckIcon aria-hidden className={cn(value === null ? 'opacity-100' : 'opacity-0')} />
                                        {clearLabel}
                                    </CommandItem>
                                )}
                                {options.map((option) => (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        keywords={[option.label, ...(option.keywords ?? [])]}
                                        disabled={option.disabled}
                                        onSelect={() => choose(option.value)}
                                    >
                                        <CheckIcon
                                            aria-hidden
                                            className={cn(option.value === value ? 'opacity-100' : 'opacity-0')}
                                        />
                                        <span className="min-w-0 flex-1 truncate">{option.render ?? option.label}</span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </>
    );
}
