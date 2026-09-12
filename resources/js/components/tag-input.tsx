import { XIcon } from 'lucide-react';
import { type KeyboardEvent, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const MAX_TAGS = 20;
const MAX_LENGTH = 40;

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

        if (tag === '' || full || value.includes(tag)) {
            setDraft('');

            return;
        }

        onChange([...value, tag]);
        setDraft('');
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
        if (event.key === 'Enter' || event.key === ',') {
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
                    onBlur={() => commit(draft)}
                    placeholder={full ? `${MAX_TAGS} tags is the limit` : value.length > 0 ? '' : placeholder}
                    className="placeholder:text-muted-foreground h-6 min-w-[9rem] flex-1 bg-transparent px-1.5 text-sm leading-5 outline-none disabled:cursor-not-allowed"
                />
            </div>
        </>
    );
}
