import { Button } from '@/components/ui/button';
import { Form48Preview } from '@/components/marketing/form-48-preview';
import { HolidayCalendar } from '@/components/marketing/holiday-calendar';
import { HowItWorks } from '@/components/marketing/how-it-works';
import { RosterPreview } from '@/components/marketing/roster-preview';
import { RotationStrip } from '@/components/marketing/rotation-strip';
import { SiteFooter } from '@/components/marketing/site-footer';
import { SiteNav } from '@/components/marketing/site-nav';
import { cn } from '@/lib/utils';

/**
 * The public home page. Hierarchy comes from type and section rules, never from
 * boxes: one 1200px measure, a 1px rule between sections, and the only bordered
 * panels are the four product fragments, which group data
 * (docs/design/08-interface.md §1 rules 2 and 3).
 *
 * Two designed widths, as the artboards have them: the phone layout up to the
 * breakpoint and the 1440 layout above it. The display sizes wait for `lg`
 * because a 56px headline needs the measure, and the two multi-column grids
 * wait for `xl` because a 560px visual beside a readable column of text needs
 * about 1200px of room.
 */

const WRAP = 'mx-auto max-w-[1200px] px-5 md:px-10';
const SECTION = cn(WRAP, 'scroll-mt-16 border-t py-13 md:py-22');

const H2 =
    'max-w-[640px] text-[26px] leading-8 font-bold tracking-[-0.014em] text-balance md:text-[32px] md:leading-[38px] md:tracking-[-0.018em]';
const H3 = 'text-xl leading-[26px] font-bold tracking-[-0.008em]';
const LEAD_IN = 'max-w-[520px] pt-4 text-sm leading-[23px] md:text-[15px] md:leading-[25px]';
const FACT =
    'border-rule list-none border-b py-[11px] text-[13px] leading-5 text-muted-foreground tabular-nums [&_b]:font-semibold [&_b]:text-foreground';
const COLUMN_FACT =
    'border-rule list-none border-t py-[9px] text-[13px] leading-5 text-muted-foreground [&_b]:font-semibold [&_b]:text-foreground';
const CTA_BUTTON = 'h-11 w-full px-5 text-sm leading-5 md:w-auto';

export default function Home({ demo }: { demo: string }) {
    return (
        <>
            <SiteNav />

            <main>
                <section id="top" className={cn(WRAP, 'scroll-mt-16 pt-7 pb-11 md:pt-15 md:pb-19')}>
                    <h1 className="max-w-[1000px] text-[36px] leading-[39px] font-bold tracking-[-0.018em] lg:text-[56px] lg:leading-[59px] lg:tracking-[-0.02em]">
                        Schedules and daily time records,
                        <br />
                        by the rules.
                    </h1>
                    <p className="text-muted-foreground max-w-[640px] pt-4 text-base leading-[25px] md:pt-[22px] md:text-lg md:leading-7">
                        Rostering, biometric timelogs and CS Form 48 for Philippine government agencies and private
                        offices, computed under Civil Service Commission rules.
                    </p>
                    <div className="grid grid-cols-1 gap-2.5 pt-6 md:flex md:items-center md:gap-3 md:pt-[30px]">
                        <Button asChild className={CTA_BUTTON}>
                            <a href="#demo">Request a demo</a>
                        </Button>
                        <Button asChild variant="outline" className={CTA_BUTTON}>
                            <a href="#how">See how it works</a>
                        </Button>
                    </div>

                    <RosterPreview />
                </section>

                <section id="how" className={SECTION}>
                    <h2 className={H2}>How it works</h2>
                    <p className="text-muted-foreground max-w-[620px] pt-3 text-sm leading-[22px] md:pt-3.5 md:text-[15px] md:leading-6">
                        One path from the device to the signed form. Every stage keeps its own receipts, so a number on
                        the DTR can be traced back to the punch that produced it.
                    </p>
                    <HowItWorks />
                </section>

                <section id="product" className={SECTION}>
                    <div className="grid grid-cols-1 gap-7 xl:grid-cols-[minmax(0,1fr)_560px] xl:items-start xl:gap-[72px]">
                        <div className="min-w-0">
                            <h2 className={H2}>Scheduling that fits real rosters</h2>
                            <p className={LEAD_IN}>
                                A shift is a day template. A schedule is a cycle of shifts. A roster puts an employee on
                                a schedule from a date, with the cycle anchored where you say. Fixed hours, the seven
                                flexitime options, a compressed four-day week, a hospital rotation of any length,
                                twelve-hour tours and twenty-four hour duty are all rows.
                            </p>
                            <p className={cn(LEAD_IN, 'pt-3.5')}>
                                Night shifts cross midnight without a special case. A 22:00 to 06:00 shift stays on the
                                day it started, and its 06:00 punch is credited and printed there.
                            </p>
                            <ul className="mt-5 max-w-[520px] border-t pl-0 md:mt-[26px]">
                                <li className={FACT}>
                                    A <b>21</b>-day rotation with three team anchors <b>7</b> days apart covers every
                                    shift on every day, from one schedule.
                                </li>
                                <li className={FACT}>
                                    Editing a shift changes the future only. Each workday keeps a snapshot of the shift
                                    it was computed against.
                                </li>
                            </ul>
                        </div>
                        <div className="max-w-[560px] min-w-0">
                            <RotationStrip />
                        </div>
                    </div>
                </section>

                <section className={SECTION}>
                    <div className="grid grid-cols-1 gap-7 xl:grid-cols-[560px_minmax(0,1fr)] xl:items-start xl:gap-[72px]">
                        <div className="min-w-0 xl:order-2">
                            <h2 className={H2}>The calendar and the rules, built in</h2>
                            <p className={LEAD_IN}>
                                National holidays come with the product and local holidays are yours. A work suspension
                                takes the rest of the day off the expectation and charges the absent only from shift
                                start to the announcement. A declaration never recomputes hours already rendered.
                            </p>
                            <p className={cn(LEAD_IN, 'pt-3.5')}>
                                Leave, official business, travel orders, pass slips and prayer time are one shape with a
                                start and an end, so a Friday exemption from 10:00 to 14:00 crosses noon without a
                                special case. Overtime needs a written authority; without one, excess minutes are
                                recorded and never compensable.
                            </p>
                            <ul className="mt-5 max-w-[520px] border-t pl-0 md:mt-[26px]">
                                <li className={FACT}>
                                    Tardiness and undertime are computed in <b>minutes</b> and converted to days only on
                                    the report, from the CSC table as printed.
                                </li>
                                <li className={FACT}>
                                    A holiday on the non-working day of a compressed week reverts that week to <b>8</b>
                                    -hour days, from the moment the holiday was declared.
                                </li>
                            </ul>
                        </div>
                        <div className="max-w-[560px] min-w-0 xl:order-1">
                            <HolidayCalendar />
                        </div>
                    </div>
                </section>

                <section className={SECTION}>
                    <div className="grid grid-cols-1 gap-7 xl:grid-cols-[minmax(0,1fr)_560px] xl:items-start xl:gap-[72px]">
                        <div className="min-w-0">
                            <h2 className={H2}>From the terminal to CS Form 48</h2>
                            <p className={LEAD_IN}>
                                Terminals connect by push, by pull, or by file import. Every sync records what it
                                received, accepted and skipped, and the clock drift it saw. A timelog whose device id
                                matches nobody stays visible as unresolved until the enrollment is fixed.
                            </p>
                            <p className={cn(LEAD_IN, 'pt-3.5')}>
                                The month prints as CS Form 48, with AM and PM columns, an undertime column, and a day
                                marker on a punch that landed later. It locks when the last out has arrived and is
                                certified by the employee, then verified by the supervisor. A wrong timelog is voided
                                with a reason, never deleted.
                            </p>
                            <ul className="mt-5 max-w-[520px] border-t pl-0 md:mt-[26px]">
                                <li className={FACT}>
                                    The form has four time columns, so a shift with a break fills them in order and a{' '}
                                    <b>⁺¹</b> marks every punch that landed after midnight.
                                </li>
                                <li className={FACT}>
                                    A month cannot lock while an out is still pending, so a night shift on the <b>30</b>
                                    th holds September open until its 06:00 arrives.
                                </li>
                            </ul>
                        </div>
                        <div className="max-w-[560px] min-w-0">
                            <Form48Preview />
                        </div>
                    </div>
                </section>

                <section id="agencies" className={SECTION}>
                    <h2 className={H2}>For agencies, for companies</h2>
                    <div className="grid grid-cols-1 pt-7 lg:grid-cols-2 lg:pt-10">
                        <div className="lg:pr-16">
                            <h3 className={H3}>Government agencies</h3>
                            <p className="pt-3 text-sm leading-[23px] md:text-[15px] md:leading-[25px]">
                                One agency to a tenant, with its own workgroups, terminals, shifts, calendar and signing
                                chain. The Civil Service Commission rules are applied as written, and the product keeps
                                the issuance behind each one visible, so a timekeeper can check a computation against
                                the circular rather than take it on trust.
                            </p>
                            <ul className="pt-3.5 pl-0">
                                <li className={COLUMN_FACT}>
                                    Workgroups of any shape: <b>departments, divisions, sections, units</b>, or none.
                                </li>
                                <li className={COLUMN_FACT}>
                                    Who signs is agency data: <b>employee, supervisor, head</b> and <b>timekeeper</b>, in the
                                    order the agency uses.
                                </li>
                            </ul>
                        </div>
                        <div className="mt-7 border-t pt-7 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-16">
                            <h3 className={H3}>Private organizations</h3>
                            <p className="pt-3 text-sm leading-[23px] md:text-[15px] md:leading-[25px]">
                                The same engine with your own policies. Grace minutes, rest days, overtime approval and
                                the signing chain are yours to set. The daily time record becomes a timesheet and the
                                attestation chain becomes your approval chain.
                            </p>
                            <ul className="pt-3.5 pl-0">
                                <li className={COLUMN_FACT}>
                                    Shifts and schedules ship as defaults you copy, then change without waiting for a
                                    release.
                                </li>
                                <li className={COLUMN_FACT}>
                                    Minutes, occurrences and day fractions go out to payroll, which applies the rates.
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p className="text-muted-foreground mt-7 max-w-[780px] border-t pt-5 text-[13px] leading-[21px] md:mt-10 md:text-sm md:leading-[22px]">
                        Each agency’s data is isolated in its own tenant. Every row carries the agency it belongs to,
                        and the database enforces it rather than the application alone. Ask us for the current security
                        posture and we will put it in writing.
                    </p>
                </section>

                <section id="demo" className={cn(WRAP, 'scroll-mt-16 border-t py-12 md:py-20')}>
                    <h2 className="max-w-[780px] text-[28px] leading-[33px] font-bold tracking-[-0.015em] text-balance lg:text-[40px] lg:leading-[46px] lg:tracking-[-0.019em]">
                        See khronoz with your own roster.
                    </h2>
                    <div className="grid grid-cols-1 gap-2.5 pt-6 md:flex md:items-center md:gap-3 md:pt-[30px]">
                        <Button asChild className={CTA_BUTTON}>
                            {/* The accessible name is a superset of the visible label
                            (WCAG 2.5.3), so it can say what the click opens. */}
                            <a href={demo} aria-label="Request a demo by email">
                                Request a demo
                            </a>
                        </Button>
                        <Button asChild variant="outline" className={CTA_BUTTON}>
                            <a href="#how">See how it works</a>
                        </Button>
                    </div>
                    <p className="text-muted-foreground max-w-[560px] pt-[18px] text-sm leading-[22px] md:pt-6 md:text-[15px] md:leading-6">
                        We set up a demo with your shifts, your terminals and one month of your data.
                    </p>
                </section>
            </main>

            <SiteFooter demo={demo} />
        </>
    );
}
