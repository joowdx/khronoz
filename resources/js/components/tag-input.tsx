import { XIcon } from 'lucide-react';
import { type KeyboardEvent, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** StoreEmployeeRequest: `tags.max:20`, `tags.*.max:40`. Kept in step by hand — the server is still the authority. */
const MAX_TAGS = 20;
const MAX_LENGTH = 40;

/**
 * Many short labels in one field: the tags on an employee.
 *
 * Tags are free-form, agency-defined and read by no rule (01-organization.md
 * rule 5) — they exist so a timekeeper can select many employees at once, so
 * the control's job is to make an existing spelling easy to repeat and a new
 * one cheap to add. Committed on Enter or a comma, because both are what
 * people type; Backspace in an empty box takes the last one back, because
 * that is what every other tag field in the world does.
 *
 * Each tag is a §5.11 badge with a remove button, and the values travel as
 * hidden `tags[]` inputs so an Inertia `<Form>` submits them with everything
 * else and no field-level state has to be lifted into the page. An empty list
 * sends no inputs at all, which is exactly the shape the Form Requests
 * default to `[]`.
 *
 * The box carries the focus ring, not the inner input: the ring belongs to the
 * field a reader sees, and the field here is the box. Offset is -1px, the same
 * inward offset §5 gives every field so a panel edge cannot clip it.
 */
export function TagInput({
    value,
    onChange,
    id,
    invalid,
    describedBy,
    placeholder = 'Add a tag, then press Enter',
}: {
    value: string[];
    onChange: (tags: string[]) => void;
    id?: string;
    invalid?: boolean;
    describedBy?: string;
    placeholder?: string;
}) {
    const [draft, setDraft] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const full = value.length >= MAX_TAGS;

    function commit(raw: string): void {
        const tag = raw.trim().slice(0, MAX_LENGTH);

        // A duplicate is refused silently rather than with a message: the tag
        // is already visible in the row above, so nothing is missing and
        // there is nothing to fix.
        if (tag === '' || full || value.includes(tag)) {
            setDraft('');

            return;
        }

        onChange([...value, tag]);
        setDraft('');
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
        if (event.key === 'Enter' || event.key === ',') {
            // Enter inside a form submits it; here it means "this tag is done".
            event.preventDefault();
            commit(draft);

            return;
        }

        if (event.key === 'Backspace' && draft === '' && value.length > 0) {
            onChange(value.slice(0, -1));
        }
    }

    return (
        <>
            {value.map((tag) => (
                <input key={tag} type="hidden" name="tags[]" value={tag} />
            ))}
            <div
                // Clicking the box's empty space is clicking the field.
                onClick={() => input.current?.focus()}
                className={cn(
                    'border-input bg-background flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded-lg border p-1.5 transition-[color,background-color,border-color]',
                    'focus-within:outline-ring focus-within:outline-2 focus-within:-outline-offset-1',
                    'hover:border-foreground focus-within:hover:border-ring',
                    invalid && 'border-destructive focus-within:outline-destructive hover:border-destructive',
                )}
            >
                {value.map((tag) => (
                    <Badge key={tag} variant="secondary" className="pr-1 pl-2.5">
                        {tag}
                        <button
                            type="button"
                            aria-label={`Remove ${tag}`}
                            onClick={() => onChange(value.filter((held) => held !== tag))}
                            className="hover:text-foreground -mr-0.5 flex size-4 items-center justify-center rounded-full transition-[color,background-color]"
                        >
                            <XIcon aria-hidden strokeWidth={1.5} className="size-3" />
                        </button>
                    </Badge>
                ))}
                <input
                    ref={input}
                    id={id}
                    type="text"
                    value={draft}
                    maxLength={MAX_LENGTH}
                    disabled={full}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={onKeyDown}
                    // A half-typed tag left behind when someone tabs away or
                    // submits would silently vanish, so blur commits it.
                    onBlur={() => commit(draft)}
                    placeholder={full ? `${MAX_TAGS} tags is the limit` : value.length > 0 ? '' : placeholder}
                    className="placeholder:text-muted-foreground h-6 min-w-[9rem] flex-1 bg-transparent px-1.5 text-sm leading-5 outline-none disabled:cursor-not-allowed"
                />
            </div>
        </>
    );
}
