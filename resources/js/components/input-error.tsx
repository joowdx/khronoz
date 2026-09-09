/**
 * A bare fault message, for a control that is not a Field — a checkbox set, a
 * picker, anything whose label row this component cannot own. Prefer Field:
 * it reserves the line's height, so an error appearing does not shift the page.
 */
export function InputError({ message }: { message?: string }) {
    return message ? (
        <p aria-live="polite" className="text-destructive text-[13px] leading-[18px] font-medium">
            {message}
        </p>
    ) : null;
}
