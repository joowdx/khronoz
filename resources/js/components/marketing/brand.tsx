import { cn } from '@/lib/utils';

/**
 * The mark: three squares rising off a rule, in the accent; the wordmark
 * beside it stays ink (docs/design/08-interface.md §1 rule 15). Drawn on the
 * same 34x24 viewBox the sign-in screen uses, so the two never drift.
 */
export function Mark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 34 24"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            aria-hidden="true"
        >
            <path d="M1 19.2h32" />
            <rect x="2.6" y="9.6" width="7.4" height="7.4" rx="1.6" fill="currentColor" stroke="none" />
            <rect x="13.3" y="5.2" width="7.4" height="11.8" rx="1.6" fill="currentColor" stroke="none" />
            <rect x="24" y="1.2" width="7.4" height="15.8" rx="1.6" fill="currentColor" stroke="none" />
        </svg>
    );
}

/** The mark and the wordmark as one link back to the top of the page. */
export function BrandLink({ className }: { className?: string }) {
    return (
        <a href="#top" className={cn('text-acc-text inline-flex items-center gap-[9px] rounded-lg py-px', className)}>
            <Mark className="h-[18px] w-[26px] flex-none" />
            <span className="text-foreground text-[17px] leading-[22px] font-bold tracking-[-0.008em] md:text-[19px] md:leading-6">
                khronoz
            </span>
        </a>
    );
}
