import type { ReactNode } from 'react';
import { Check, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The four stages between a device and a signed form, on one connecting rule:
 * across four columns on a wide screen, down one rule on a narrow one.
 */

const META = 'text-muted-foreground text-[11px] leading-[15px] font-medium whitespace-nowrap tabular-nums';
const STRONG = 'text-foreground font-semibold';
const GOOD = 'text-positive font-semibold';
const WATCH = 'text-attention font-semibold';
const KEY = 'text-xs leading-4 font-medium';
const PILL = 'inline-flex h-[22px] flex-none items-center gap-1.5 rounded-full px-2.5 text-xs leading-4 font-medium';

/** A row of the small flat visual under a step. */
function MiniRow({ children }: { children: ReactNode }) {
    return (
        <div className="border-rule flex min-h-8 items-center gap-2 border-t px-2.5 py-1.5 first:border-t-0">
            {children}
        </div>
    );
}

/**
 * `margin-top: auto` sits every visual on one line however the titles wrap.
 * The cap only bites between the two designed widths, where a stacked step has
 * far more room than either artboard gives it and the panel would stretch.
 */
function Mini({ children }: { children: ReactNode }) {
    return <div className="mt-auto max-w-[420px] overflow-hidden rounded-lg border">{children}</div>;
}

const STEPS: { title: string; body: string; visual: ReactNode }[] = [
    {
        title: 'Terminals record timelogs',
        body: 'ZKTeco terminals push, are polled, or hand over a file. Each row is stored as the device sent it and is never edited.',
        visual: (
            <Mini>
                <MiniRow>
                    <span className={KEY}>Terminal 3, lobby</span>
                    <span className="flex-1" />
                    <span className={cn(PILL, 'bg-positive-soft text-positive')}>
                        <i className="size-[5px] flex-none rounded-full bg-current" aria-hidden="true" />
                        Synced
                    </span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        <b className={STRONG}>07:58:12</b>
                        {'  '}uid 42{'  '}state 0{'  '}mode 1
                    </span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        412 received{'  '}
                        <span className={GOOD}>398 accepted</span>
                        {'  '}14 duplicates
                    </span>
                </MiniRow>
            </Mini>
        ),
    },
    {
        title: 'Punches are matched to the shift',
        body: 'Each timelog fills one side of one slot in that employee’s shift for the day. A slot no timelog reaches stays blank.',
        visual: (
            <Mini>
                <MiniRow>
                    <span className={KEY}>Slot 1</span>
                    <span className="flex-1" />
                    <span className={META}>08:00&ndash;12:00</span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        in{'  '}expected <b className={STRONG}>08:00</b>
                        {'  '}got <b className={STRONG}>07:58</b>
                        {'  '}
                        <span className={GOOD}>&minus;2</span>
                    </span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        out{'  '}expected <b className={STRONG}>12:00</b>
                        {'  '}got <b className={STRONG}>12:03</b>
                        {'  '}
                        <span className={WATCH}>+3</span>
                    </span>
                </MiniRow>
            </Mini>
        ),
    },
    {
        title: 'Workdays are computed under the rules',
        body: 'Minutes worked, tardiness, undertime, night minutes and raw excess, measured against the shift as it stood that day.',
        visual: (
            <Mini>
                <MiniRow>
                    <span className={KEY}>8 Sep 2026</span>
                    <span className="flex-1" />
                    <span className={cn(PILL, 'bg-positive-soft text-positive')}>
                        <i className="size-[5px] flex-none rounded-full bg-current" aria-hidden="true" />
                        Present
                    </span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        worked <b className={STRONG}>480</b>
                        {'  '}tardy <b className={STRONG}>0</b>
                        {'  '}undertime <b className={STRONG}>0</b>
                    </span>
                </MiniRow>
                <MiniRow>
                    <span className={META}>
                        night <b className={STRONG}>0</b>
                        {'  '}excess <b className={STRONG}>5</b>, no authority
                    </span>
                </MiniRow>
            </Mini>
        ),
    },
    {
        title: 'Ledgers become the monthly DTR',
        body: 'The month collects its workdays, locks once the last out has arrived, and is attested in the agency’s own chain.',
        visual: (
            <Mini>
                <MiniRow>
                    <span className={KEY}>September 2026</span>
                    <span className="flex-1" />
                    <span className={cn(PILL, 'bg-acc-soft text-acc-text')}>
                        <Lock className="size-4" aria-hidden="true" />
                        Locked
                    </span>
                </MiniRow>
                <MiniRow>
                    <Check className="text-positive size-4 flex-none" aria-hidden="true" />
                    <span className={META}>
                        Employee{'  '}
                        <b className={STRONG}>1 Oct, 09:14</b>
                    </span>
                </MiniRow>
                <MiniRow>
                    <Check className="text-positive size-4 flex-none" aria-hidden="true" />
                    <span className={META}>
                        Supervisor{'  '}
                        <b className={STRONG}>2 Oct, 11:02</b>
                    </span>
                </MiniRow>
            </Mini>
        ),
    },
];

export function HowItWorks() {
    return (
        <div className="grid grid-cols-1 gap-7 pt-8 xl:grid-cols-4 xl:items-stretch xl:gap-8 xl:pt-11">
            {STEPS.map((step, index) => (
                <div key={step.title} className="relative flex flex-col pl-9 xl:pl-0">
                    {index < STEPS.length - 1 && (
                        <span
                            aria-hidden="true"
                            className="bg-border absolute top-6 bottom-[-28px] left-[11px] w-px xl:top-3 xl:-right-8 xl:bottom-auto xl:left-[25px] xl:h-px xl:w-auto"
                        />
                    )}
                    <span className="bg-acc-soft text-acc-text absolute top-0 left-0 z-[1] flex size-6 items-center justify-center rounded-md text-xs leading-4 font-semibold tabular-nums xl:relative">
                        {index + 1}
                    </span>
                    <h3 className="pt-0.5 text-base leading-[22px] font-semibold xl:pt-4">{step.title}</h3>
                    <p className="text-muted-foreground max-w-[560px] pt-[7px] pb-[18px] text-sm leading-[21px]">
                        {step.body}
                    </p>
                    {step.visual}
                </div>
            ))}
        </div>
    );
}
