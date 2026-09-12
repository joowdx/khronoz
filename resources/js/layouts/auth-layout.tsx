import type { ReactNode } from 'react';
import { LegalLinks } from '@/components/legal-links';

function Mark({ className }: { className?: string }) {
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

function Chip({ slot, children }: { slot: 'c2' | 'c5' | 'c8'; children: ReactNode }) {
    const ramp = {
        c2: 'bg-c2-fill border-c2-edge text-c2-text',
        c5: 'bg-c5-fill border-c5-edge text-c5-text',
        c8: 'bg-c8-fill border-c8-edge text-c8-text',
    }[slot];

    return (
        <span
            className={`inline-flex h-[22px] w-[21px] items-center justify-center rounded-md border text-[11px] leading-[14px] font-semibold tabular-nums ${ramp}`}
        >
            {children}
        </span>
    );
}

const OFF_CELL =
    'bg-off-fill [background-image:repeating-linear-gradient(45deg,var(--off-hatch)_0_1px,transparent_1px_5px)]';

function DayCell({ off = false, children }: { off?: boolean; children?: ReactNode }) {
    return <i className={`flex w-[27px] flex-none items-center justify-center ${off ? OFF_CELL : ''}`}>{children}</i>;
}

function NightBand({ left, width, padLeft, hours }: { left: number; width: number; padLeft: number; hours?: string }) {
    return (
        <span
            style={{ left, width, paddingLeft: padLeft }}
            className="bg-c8-fill border-c8-edge text-c8-text absolute top-1.5 z-[5] flex h-[22px] items-center rounded-md border text-[11px] leading-[14px] font-semibold"
        >
            <b className="font-bold">N</b>
            {hours && <span className="ml-[7px] font-medium">{hours}</span>}
        </span>
    );
}

const DAYS = [
    { weekday: 'T', date: 1 },
    { weekday: 'W', date: 2 },
    { weekday: 'T', date: 3 },
    { weekday: 'F', date: 4 },
    { weekday: 'S', date: 5, weekend: true },
    { weekday: 'S', date: 6, weekend: true },
    { weekday: 'M', date: 7 },
    { weekday: 'T', date: 8 },
    { weekday: 'W', date: 9, today: true },
    { weekday: 'T', date: 10 },
    { weekday: 'F', date: 11 },
    { weekday: 'S', date: 12, weekend: true },
    { weekday: 'S', date: 13, weekend: true },
    { weekday: 'M', date: 14 },
];

const WEEKEND_OFFSETS = [308, 335, 497, 524];
const NOW_OFFSET = 416;

const LANES = [
    { name: 'Morning', slot: 'c2', left: 0, width: 100 / 3, hours: '06:00 – 14:00', count: 31 },
    { name: 'Afternoon', slot: 'c5', left: 100 / 3, width: 100 / 3, hours: '14:00 – 22:00', count: 28 },
    { name: 'Night', slot: 'c8', left: 200 / 3, width: 100 / 3, hours: '22:00 – 30:00', count: 26 },
] as const;

const LANE_RAMP = {
    c2: 'bg-c2-fill border-c2-edge text-c2-text',
    c5: 'bg-c5-fill border-c5-edge text-c5-text',
    c8: 'bg-c8-fill border-c8-edge text-c8-text',
} as const;

function ProductHero() {
    return (
        <div className="flex flex-col items-start gap-4" aria-hidden="true">
            <div className="border-border bg-background w-[580px] rounded-xl border">
                <div className="border-border flex h-10 items-baseline gap-3 border-b px-4">
                    <b className="text-[13px] leading-10 font-semibold">Roster</b>
                    <span className="text-muted-foreground ml-auto text-xs leading-10 tabular-nums">
                        Nursing Service, 1 to 14 September 2026
                    </span>
                </div>

                <div className="overflow-hidden [&_b]:not-italic [&_em]:not-italic [&_i]:not-italic">
                    <div className="flex h-[34px]">
                        <div className="text-muted-foreground flex w-[200px] flex-none items-end px-3 pb-1.5 text-xs leading-4 font-semibold">
                            Employee
                        </div>
                        {DAYS.map((day) => (
                            <div
                                key={day.date}
                                className={`flex w-[27px] flex-none flex-col items-center justify-end gap-px pb-1 ${
                                    day.weekend ? 'bg-weekend' : ''
                                }`}
                            >
                                <em className="text-muted-foreground text-[10px] leading-3 font-medium">
                                    {day.weekday}
                                </em>
                                {day.today ? (
                                    <b className="bg-primary text-primary-foreground -mb-0.5 flex size-[18px] items-center justify-center rounded-full text-[11px] leading-[15px] font-semibold tabular-nums">
                                        {day.date}
                                    </b>
                                ) : (
                                    <b className="text-[11px] leading-[15px] font-semibold tabular-nums">{day.date}</b>
                                )}
                            </div>
                        ))}
                    </div>

                    <div className="border-border relative border-t">
                        <div className="pointer-events-none absolute inset-0 z-0">
                            {WEEKEND_OFFSETS.map((left) => (
                                <i
                                    key={left}
                                    style={{ left }}
                                    className="bg-weekend absolute top-0 bottom-0 w-[27px]"
                                />
                            ))}
                        </div>
                        <div style={{ left: NOW_OFFSET }} className="bg-primary absolute top-0 bottom-0 z-[6] w-px" />

                        <div className="relative z-[1] flex h-[34px]">
                            <i className="flex w-[200px] flex-none items-center px-3 text-[13px] leading-[17px] font-medium whitespace-nowrap">
                                Corazon Dimaano
                            </i>
                            <i className="relative flex w-[378px] flex-none overflow-hidden">
                                <DayCell />
                                <DayCell />
                                <DayCell />
                                <DayCell />
                                <DayCell off />
                                <DayCell off />
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell off />
                                <DayCell off />
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <NightBand left={-15} width={138} padLeft={20} hours="22:00 – 06:00" />
                            </i>
                        </div>

                        <div className="border-rule relative z-[1] flex h-[34px] border-t">
                            <i className="flex w-[200px] flex-none items-center px-3 text-[13px] leading-[17px] font-medium whitespace-nowrap">
                                Jomar Padilla
                            </i>
                            <i className="relative flex w-[378px] flex-none overflow-hidden">
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell off />
                                <DayCell off />
                                <DayCell />
                                <DayCell />
                                <DayCell />
                                <DayCell />
                                <DayCell />
                                <DayCell off />
                                <DayCell off />
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <NightBand left={174} width={138} padLeft={5} hours="22:00 – 06:00" />
                            </i>
                        </div>

                        <div className="border-rule relative z-[1] flex h-[34px] border-t">
                            <i className="flex w-[200px] flex-none items-center px-3 text-[13px] leading-[17px] font-medium whitespace-nowrap">
                                Rodel Amparo
                            </i>
                            <i className="relative flex w-[378px] flex-none overflow-hidden">
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c2">M</Chip>
                                </DayCell>
                                <DayCell off />
                                <DayCell off />
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell>
                                    <Chip slot="c5">A</Chip>
                                </DayCell>
                                <DayCell off />
                                <DayCell off />
                                <DayCell />
                                <NightBand left={363} width={138} padLeft={4} />
                            </i>
                        </div>
                    </div>
                </div>
            </div>

            <div className="border-border bg-background w-[580px] rounded-xl border">
                <div className="border-border flex h-10 items-baseline gap-3 border-b px-4">
                    <b className="text-[13px] leading-10 font-semibold">On duty now</b>
                    <span className="text-muted-foreground ml-auto text-xs leading-10 tabular-nums">14:42</span>
                </div>

                <div className="relative px-4 pt-3.5 pb-3">
                    <div className="flex items-center">
                        <span className="w-[92px] flex-none" />
                        <div className="relative h-[30px] flex-1">
                            <span className="bg-edge-soft absolute top-4 right-0 left-0 h-px" />
                            {Array.from({ length: 25 }, (_, i) => i * (100 / 24)).map((left, i) => (
                                <span
                                    key={left}
                                    style={{ left: `${left}%` }}
                                    className={
                                        i % 6 === 0
                                            ? 'bg-muted-foreground absolute top-1.5 h-2.5 w-px'
                                            : 'bg-tick absolute top-[11px] h-[5px] w-px'
                                    }
                                />
                            ))}
                            {['06', '12', '18', '24', '30'].map((label, i) => (
                                <span
                                    key={label}
                                    style={{ left: `${i * 25}%` }}
                                    className="text-muted-foreground absolute top-[18px] -translate-x-1/2 text-[11px] leading-[14px] font-semibold tabular-nums"
                                >
                                    {label}
                                </span>
                            ))}
                        </div>
                        <span className="w-[52px] flex-none" />
                    </div>

                    {LANES.map((lane) => (
                        <div key={lane.name} className="flex items-center">
                            <span className="w-[92px] flex-none text-[13px] leading-8 font-medium">{lane.name}</span>
                            <div className="border-rule relative h-8 flex-1 bg-[linear-gradient(var(--rule),var(--rule))] bg-[length:100%_1px] bg-[position:0_50%] bg-no-repeat">
                                <span
                                    style={{ left: `${lane.left}%`, width: `${lane.width}%` }}
                                    className={`absolute top-1 flex h-6 items-center overflow-hidden rounded-md border px-[7px] text-[11px] leading-[14px] font-semibold tabular-nums ${LANE_RAMP[lane.slot]}`}
                                >
                                    {lane.hours}
                                </span>
                            </div>
                            <span className="w-[52px] flex-none text-right text-[13px] leading-8 font-semibold tabular-nums">
                                {lane.count}
                            </span>
                        </div>
                    ))}

                    <div className="pointer-events-none absolute top-3.5 right-[68px] left-[108px] h-[126px]">
                        <span className="absolute top-[30px] bottom-0 left-[75%] w-px bg-[linear-gradient(var(--edge-soft)_0_3px,transparent_3px_6px)] bg-[length:1px_6px] bg-repeat-y" />
                        <span className="bg-primary absolute top-[30px] bottom-0 left-[36.25%] w-px" />
                        <span className="bg-acc-soft text-acc-text absolute top-px left-[36.25%] ml-[7px] inline-flex h-[18px] items-center rounded-full px-1.5 text-[11px] leading-[14px] font-semibold whitespace-nowrap tabular-nums">
                            14:42
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-svh flex-col lg:flex-row">
            <div className="bg-muted border-border flex flex-col justify-center gap-4 border-b px-6 pt-10 pb-8 lg:w-[52%] lg:flex-none lg:gap-10 lg:border-r lg:border-b-0 lg:p-16">
                <div className="text-acc-text flex items-center gap-3.5">
                    <Mark className="size-[34px] flex-none" />
                    <span className="text-foreground text-[30px] leading-[34px] font-bold tracking-[-0.022em] lg:text-4xl lg:leading-10">
                        khronoz
                    </span>
                </div>
                <p className="text-muted-foreground max-w-[520px] text-[15px] leading-6 lg:text-base lg:leading-[26px]">
                    Daily time records for Philippine government agencies, under Civil Service Commission rules.
                </p>
                <div className="hidden lg:block">
                    <ProductHero />
                </div>
            </div>

            <main className="bg-background flex flex-1 items-center justify-center px-6 pt-7 pb-10 lg:p-12">
                <div className="w-full lg:w-[400px]">
                    <h1 className="text-2xl leading-[30px] font-semibold tracking-[-0.008em]">{title}</h1>
                    {description && <p className="text-muted-foreground pt-2 pb-7 text-sm leading-5">{description}</p>}
                    {children}
                    <div className="mt-8 border-t pt-5">
                        <LegalLinks />
                    </div>
                </div>
            </main>
        </div>
    );
}
