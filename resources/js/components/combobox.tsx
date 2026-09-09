import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
    value: string;
    /** What the trigger shows once this option is chosen, and what a screen reader hears. */
    label: string;
    /** Extra strings the search should match — an employee number, a unit code. */
    keywords?: string[];
    /** The row inside the list, when the label alone is not enough. Defaults to the label. */
    render?: ReactNode;
    /** What the trigger shows, when it should differ from the list row. Defaults to the label. */
    trigger?: ReactNode;
    disabled?: boolean;
}

/**
 * One value out of a searchable list: §5.14's popover with `cmdk` inside it,
 * which is the design's answer for a choice too long to be a `<Select>` —
 * every employee of a hospital, every unit of an agency.
 *
 * Why not `Select`: a native-shaped select has no search, and its list is
 * item-aligned over the trigger. Why not a bare `Command`: the list has to be
 * a popover so it portals out of the shell's scroller (§9.4 trap 5) and so a
 * table row can carry one without growing.
 *
 * Two geometries, because the same choice appears in two places:
 *
 * | variant  | Looks like                            | Used by                        |
 * | -------- | ------------------------------------- | ------------------------------ |
 * | `field`  | a 36px control, `--input` border      | a form field (unit, parent)    |
 * | `inline` | text until hovered, no border at rest | a table cell (a unit's head)   |
 *
 * Controlled: `value` is the chosen option's value or `null`. `name` adds a
 * hidden input so an Inertia `<Form>` submits it without the caller wiring
 * state of its own — an empty choice submits an empty string, which is what
 * `nullable` rules already accept.
 */
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
    /** The muted string the trigger shows while nothing is chosen. */
    placeholder: string;
    searchPlaceholder?: string;
    empty?: ReactNode;
    /** When given, the list offers a first row that clears the choice. */
    clearLabel?: string;
    name?: string;
    id?: string;
    variant?: 'field' | 'inline';
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    className?: string;
    /** The trigger's accessible name where no `<label>` points at it — a table cell. */
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
                {/*
                  Not `role="combobox"`, which shadcn's own recipe puts here:
                  that role owes the reader an `aria-controls` pointing at a
                  listbox, and what Radix wires on this trigger is
                  `aria-haspopup="dialog"` plus `aria-controls` to the popover.
                  Half-claiming it would announce "combobox, collapsed" and
                  then offer nothing to expand. As a plain button with a name,
                  `aria-expanded` and `aria-controls` — all three from Radix —
                  it announces truthfully, and inside the surface cmdk owns the
                  real listbox: a search input, `role="option"` rows and
                  `aria-activedescendant` as they are walked.
                */}
                <PopoverTrigger
                    id={id}
                    aria-label={label}
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
                        // A row full of visible chevrons reads as a form, so
                        // the affordance waits for the pointer to reach the
                        // row — and stays put while the list is open.
                        variant === 'inline' &&
                            '[&>svg]:opacity-0 hover:[&>svg]:opacity-100 aria-expanded:[&>svg]:opacity-100 group-hover/row:[&>svg]:opacity-100 focus-visible:[&>svg]:opacity-100',
                        className,
                    )}
                >
                    <span className={cn('min-w-0 truncate', chosen === null && 'text-muted-foreground font-normal')}>
                        {chosen ? (chosen.trigger ?? chosen.label) : placeholder}
                    </span>
                    <ChevronDownIcon aria-hidden strokeWidth={1.5} className="text-muted-foreground" />
                </PopoverTrigger>
                {/*
                  p-0: `Command` owns the 6px padding §5.14 asks for, and the
                  list needs to reach the surface's edges to scroll cleanly.

                  Width is the content's, floored at the trigger and at §4.3's
                  232 minimum, capped at 420. MEASURED: tied to the trigger it
                  inherited a 220px filter control and clipped every unit name
                  in the list to "Office of the Executiv…" — the list is where
                  a choice is read, so it may be wider than the control that
                  reports it.
                */}
                <PopoverContent
                    align="start"
                    sideOffset={6}
                    style={{ minWidth: 'max(232px, var(--radix-popover-trigger-width))' }}
                    className="w-max max-w-[min(420px,calc(100vw-2rem))] p-0"
                >
                    <Command
                        // Match the trigger's own label as well as the row's
                        // text: an employee number or a unit code is what
                        // someone types when the name is long.
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
                                        {/* Always rendered, so choosing does
                                            not shift the rows sideways. */}
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
