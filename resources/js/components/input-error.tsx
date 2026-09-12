export function InputError({ message }: { message?: string }) {
    return message ? (
        <p aria-live="polite" className="text-destructive text-[13px] leading-[18px] font-medium">
            {message}
        </p>
    ) : null;
}
