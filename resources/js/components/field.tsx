import { type ReactNode, useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * One field, one label row, one error.
 *
 * The label row is a baseline-aligned flex with the label on the left and the
 * fault message on the right. It holds its height whether or not there is an
 * error, so adding one moves nothing below it — the specimen sheet proves that
 * side by side, and it is the whole reason the message lives up here rather
 * than under the control.
 *
 * The right-hand slot is `aria-live="polite"`, so a message that appears after
 * a failed submit is announced without stealing focus, and the control gets
 * `aria-invalid` plus an `aria-describedby` pointing at it.
 *
 * Errors are one short line — Required, Enter a valid email, Already taken.
 * They are written server side (lang/en/validation.php and the Form Requests'
 * own messages()), because the label row can only hold one line and the server
 * is the only place that knows what actually failed.
 */
export function Field({
    label,
    error,
    hint,
    htmlFor,
    className,
    children,
}: {
    label: string;
    /** The one-line fault message, or nothing. */
    error?: string;
    /** Standing guidance, under the control. Not a place for errors. */
    hint?: string;
    /** The control's id. Generated when omitted, but pass the real one when the control has a name. */
    htmlFor?: string;
    className?: string;
    /**
     * Rendered with the wiring the control needs. Spread it onto the input:
     * `{({ id, invalid, describedBy }) => <Input id={id} aria-invalid={invalid} aria-describedby={describedBy} />}`
     */
    children: (control: { id: string; invalid: boolean | undefined; describedBy: string | undefined }) => ReactNode;
}) {
    const generated = useId();
    const id = htmlFor ?? generated;
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div data-slot="field" className={cn('min-w-0', className)}>
            <div className="flex items-baseline justify-between gap-3 pb-1.5">
                <label htmlFor={id} className="text-[13px] leading-[18px] font-medium">
                    {label}
                </label>
                {/*
                  Always rendered, so the row's height never depends on the
                  error. The zero-width space is load-bearing: an empty block
                  has no line box, so `items-baseline` falls back to its
                  bottom margin edge and the row grows ~4px taller than the
                  same row with a message in it — which is exactly the shift
                  this layout exists to prevent. It is invisible, and an
                  aria-live region does not announce its initial content.
                */}
                <p
                    id={errorId}
                    aria-live="polite"
                    className="text-destructive min-h-[18px] text-right text-[13px] leading-[18px] font-medium"
                >
                    {error || '\u200b'}
                </p>
            </div>
            {children({ id, invalid: error ? true : undefined, describedBy })}
            {hint && (
                <p id={hintId} className="text-muted-foreground pt-1.5 text-xs leading-4">
                    {hint}
                </p>
            )}
        </div>
    );
}
