#!/usr/bin/env python3
"""Builds 00-home.html beside this script. The page fragment is emitted twice
(1440 and 390 frames) from one source so the two can never drift."""

import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '00-home.html')

# ---------------------------------------------------------------- icons
def icon(paths, size=18):
    return (f'<svg width="{size}" height="{size}" viewBox="0 0 24 24" fill="none" '
            f'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" '
            f'stroke-linejoin="round" aria-hidden="true">{paths}</svg>')

I_CHECK = icon('<path d="M4.5 12.5 9 17 19.5 6.5"/>', 16)
I_LOCK  = icon('<rect x="4.5" y="10.5" width="15" height="10" rx="2"/>'
               '<path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>', 16)

MARK = ('<svg width="26" height="26" viewBox="0 0 26 26" fill="none" aria-hidden="true">'
        '<path d="M2 17.25h22" stroke="currentColor" stroke-width="1.5"/>'
        '<rect x="3.25" y="10.5" width="6" height="6" rx="1" fill="currentColor"/>'
        '<rect x="10.5" y="10.5" width="6" height="6" rx="1" fill="currentColor"/>'
        '<rect x="17.75" y="10.5" width="6" height="6" rx="1" fill="currentColor"/>'
        '</svg>')

# ---------------------------------------------------------------- hero roster
# Rotation, 21 days: Morning x5, Off x2, Afternoon x5, Off x2, Night x5, Off x2.
CYCLE = (['M'] * 5) + ['O'] * 2 + ['A'] * 5 + ['O'] * 2 + ['N'] * 5 + ['O'] * 2
SHIFT = {'M': ('c3', 'M'), 'A': ('c5', 'A'), 'N': ('c8', 'N'), 'S': ('c6', 'S')}
DOW = ['T', 'W', 'T', 'F', 'S', 'S', 'M', 'T', 'W', 'T', 'F',
       'S', 'S', 'M', 'T', 'W', 'T', 'F', 'S', 'S', 'M']          # 1-21 Sep 2026
WEEKEND = {4, 5, 11, 12, 18, 19}                                   # 0-based indices
TODAY = 14                                                         # 15 Sep 2026

def rotation(start_pos):
    return [CYCLE[(start_pos + i) % 21] for i in range(21)]

ROWS = [
    ('Bautista, M.',      '204-118', 'a3', 'Rotation',      rotation(0)),
    ('Cruz, J. P.',       '204-233', 'a5', 'Rotation',      rotation(14)),
    ('Delos Santos, A.',  '204-297', 'a8', 'Rotation',      rotation(7)),
    ('Reyes, K.',         '201-045', 'a6', 'Standard week',
     ['S' if DOW[i] not in ('S',) or i not in WEEKEND else 'O' for i in range(21)]),
]
# the standard week: weekdays worked, weekends off
ROWS[3] = (ROWS[3][0], ROWS[3][1], ROWS[3][2], ROWS[3][3],
           ['O' if i in WEEKEND else 'S' for i in range(21)])


def night_runs(days):
    """Start index and length of each run of night turns."""
    runs, i = [], 0
    while i < len(days):
        if days[i] == 'N':
            j = i
            while j < len(days) and days[j] == 'N':
                j += 1
            runs.append((i, j - i))
            i = j
        else:
            i += 1
    return runs


def roster_grid():
    h = ['<div class="rg">', '<div class="rg-in">']
    # header
    h.append('<div class="rg-hd">')
    h.append('<div class="f1">Employee</div><div class="f2">Schedule</div>')
    for i, d in enumerate(DOW):
        cls = ' we' if i in WEEKEND else ''
        num = f'<b class="today">{i + 1}</b>' if i == TODAY else f'<b>{i + 1}</b>'
        h.append(f'<div class="d{cls}"><em>{d}</em>{num}</div>')
    h.append('<div class="tot">Hours</div>')
    h.append('</div>')
    # body
    h.append('<div class="rg-bd">')
    h.append('<div class="rg-wash">')
    for i in sorted(WEEKEND):
        h.append(f'<i class="we" style="left:{i * 27}px"></i>')
    h.append('</div>')
    h.append(f'<span class="rg-now" style="left:{TODAY * 27 + 13}px"></span>')
    for name, no, av, sch, days in ROWS:
        initials = ''.join(p[0] for p in name.replace(',', '').split()[:2]).upper()
        h.append('<div class="rg-row">')
        h.append(f'<div class="emp"><span class="avatar avatar--24 {av}" aria-hidden="true">{initials}</span>'
                 f'<span class="nm">{name}</span><span class="no">{no}</span></div>')
        h.append(f'<div class="sch">{sch}</div>')
        h.append('<div class="days">')
        runs = dict((s, n) for s, n in night_runs(days))
        covered = set()
        for s, n in runs.items():
            covered |= set(range(s, s + n))
        for i, t in enumerate(days):
            if i in runs:
                # the band is absolutely placed, so its DOM slot only sets reading order
                left = i * 27 + 12
                width = runs[i] * 27 + 3
                h.append(f'<span class="nb" style="left:{left}px;width:{width}px">'
                         f'22:00&ndash;06:00<span aria-label="next day">&#8314;&#185;</span></span>')
            if t == 'O':
                h.append('<div class="c off"></div>')
            elif i in covered:
                h.append('<div class="c"></div>')
            else:
                cls, lb = SHIFT[t]
                h.append(f'<div class="c"><span class="chip {cls}">{lb}</span></div>')
        h.append('</div>')
        h.append('<div class="tot">120:00</div>')
        h.append('</div>')
    h.append('</div>')   # rg-bd
    h.append('</div></div>')
    return '\n'.join(h)


LEGEND = f'''<div class="rg-legend">
<span class="li"><span class="chip c3">M</span>Morning, 06:00 to 14:00</span>
<span class="li"><span class="chip c5">A</span>Afternoon, 14:00 to 22:00</span>
<span class="li"><span class="band"></span>Night, 22:00 to 06:00 the next day</span>
<span class="li"><span class="sq"></span>Off</span>
<span class="li"><span class="chip c6">S</span>Standard, 08:00 to 17:00</span>
</div>'''


def pct(h):
    return round(h / 24 * 100, 4)


DUTY = f'''<div class="duty">
<div class="duty-hd"><span class="k">On duty, 15 September</span>
<span class="spacer"></span><span class="n">Employees</span></div>
<div class="duty-lanes"><div class="lanes">
<div class="lane"><span class="ln">Morning</span><span class="tk">
  <span class="bar c3" style="left:{pct(6)}%;width:{pct(8)}%">06:00&ndash;14:00</span>
</span><span class="cn">12</span></div>
<div class="lane"><span class="ln">Afternoon</span><span class="tk">
  <span class="bar c5" style="left:{pct(14)}%;width:{pct(8)}%">14:00&ndash;22:00</span>
</span><span class="cn">11</span></div>
<div class="lane"><span class="ln">Night</span><span class="tk">
  <span class="bar c8" style="left:0;width:{pct(6)}%">&hellip;06:00</span>
  <span class="bar c8" style="left:{pct(22)}%;width:{pct(2)}%">22:00</span>
</span><span class="cn">12</span></div>
<div class="lane"><span class="ln"></span><span class="axis">
  <span class="base"></span>
  {''.join(f'<span class="{"H" if x % 6 == 0 else "h"}" style="left:{pct(x)}%"></span>' for x in range(0, 25))}
  {''.join(f'<span class="lb" style="left:{pct(x)}%">{x:02d}:00</span>' for x in (0, 6, 12, 18))}
  <span class="note" style="left:100%">24:00, then the next day</span>
</span><span class="cn"></span></div>
</div>
<div class="duty-ov">
  <span class="nowline" style="left:{pct(9.667)}%"></span>
  <span class="nowlbl" style="left:{pct(9.667)}%">09:40</span>
</div></div></div>'''

# ---------------------------------------------------------------- steps
STEPS = [
    ('1', 'Terminals record timelogs',
     'ZKTeco terminals push, are polled, or hand over a file. Each row is stored as '
     'the device sent it and is never edited.',
     f'''<div class="mini">
<div class="mini-r"><span class="k">Terminal 3, lobby</span><span class="spacer"></span>
<span class="pill pill--ok"><i></i>Synced</span></div>
<div class="mini-r"><span class="n"><b>07:58:12</b> &nbsp;uid 42 &nbsp;state 0 &nbsp;mode 1</span></div>
<div class="mini-r"><span class="n">412 received &nbsp;<u>398 accepted</u> &nbsp;14 duplicates</span></div>
</div>'''),
    ('2', 'Punches are matched to the shift',
     'Each timelog fills one side of one slot in that employee&rsquo;s shift for the day. '
     'A slot no timelog reaches stays blank.',
     f'''<div class="mini">
<div class="mini-r"><span class="k">Slot 1</span><span class="spacer"></span>
<span class="n">08:00&ndash;12:00</span></div>
<div class="mini-r"><span class="n">in &nbsp;expected <b>08:00</b> &nbsp;got <b>07:58</b> &nbsp;<u>&minus;2</u></span></div>
<div class="mini-r"><span class="n">out &nbsp;expected <b>12:00</b> &nbsp;got <b>12:03</b> &nbsp;<s>+3</s></span></div>
</div>'''),
    ('3', 'Workdays are computed under the rules',
     'Minutes worked, tardiness, undertime, night minutes and raw excess, measured '
     'against the shift as it stood that day.',
     f'''<div class="mini">
<div class="mini-r"><span class="k">8 Sep 2026</span><span class="spacer"></span>
<span class="pill pill--ok"><i></i>Present</span></div>
<div class="mini-r"><span class="n">worked <b>480</b> &nbsp;tardy <b>0</b> &nbsp;undertime <b>0</b></span></div>
<div class="mini-r"><span class="n">night <b>0</b> &nbsp;excess <b>5</b>, no authority</span></div>
</div>'''),
    ('4', 'Ledgers become the monthly DTR',
     'The month collects its workdays, locks once the last out has arrived, and is '
     'attested in the agency&rsquo;s own chain.',
     f'''<div class="mini">
<div class="mini-r"><span class="k">September 2026</span><span class="spacer"></span>
<span class="pill pill--acc">{I_LOCK}Locked</span></div>
<div class="mini-r">{I_CHECK}<span class="n">Employee &nbsp;<b>1 Oct, 09:14</b></span></div>
<div class="mini-r">{I_CHECK}<span class="n">Supervisor &nbsp;<b>2 Oct, 11:02</b></span></div>
</div>'''),
]


def steps_html():
    out = ['<div class="steps">']
    for n, t, p, v in STEPS:
        out.append(f'<div class="step"><span class="step-n">{n}</span>'
                   f'<h3 class="step-t">{t}</h3><p class="step-p">{p}</p>{v}</div>')
    out.append('</div>')
    return '\n'.join(out)


# ---------------------------------------------------------------- visual A
def strip_html():
    cells, ax = [], []
    for i, t in enumerate(CYCLE):
        if t == 'O':
            cells.append('<span class="strip-c off"></span>')
        else:
            cls, lb = SHIFT[t]
            cells.append(f'<span class="strip-c"><span class="chip {cls}">{lb}</span></span>')
        ax.append(f'<span>{i if i % 7 == 0 else "&nbsp;"}</span>')
    return f'''<div class="strip">
<div class="strip-hd"><span class="k">Rotation</span><span class="n">21 days</span>
<span class="spacer"></span><span class="n">3 teams, anchors 7 days apart</span></div>
<div class="strip-bd"><div class="strip-in">
<div class="strip-row">{''.join(cells)}</div>
<div class="strip-ax">{''.join(ax)}</div>
</div></div>
<div class="strip-ft">
<span class="li"><span class="chip c3">M</span>Morning</span>
<span class="li"><span class="chip c5">A</span>Afternoon</span>
<span class="li"><span class="chip c8">N</span>Night</span>
<span class="li"><span class="sq"></span>Off</span>
</div></div>'''


# ---------------------------------------------------------------- visual B
# November 2026: 1 Nov = Sunday. Sunday-first grid, 30 days.
CAL_EVENTS = {
    1:  ('hol', 'All Saints&rsquo; Day'),
    2:  ('hol', 'Special non-working'),
    12: ('sus', 'Suspension, 10:00'),
    30: ('hol', 'Bonifacio Day'),
}
CAL_WEEKEND_COLS = {0, 6}


def cal_html():
    cells = []
    for lb in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']:
        cells.append(f'<span class="cal-dow">{lb}</span>')
    for d in range(1, 31):
        col = (d - 1) % 7
        kind = CAL_EVENTS.get(d)
        cls = 'cal-c'
        if kind:
            cls += ' ' + kind[0]
        elif col in CAL_WEEKEND_COLS:
            cls += ' we'
        note = f'<span class="e">{kind[1]}</span>' if kind else ''
        cells.append(f'<span class="{cls}"><span class="d">{d}</span>{note}</span>')
    for _ in range(5):          # 30 days ends on a Monday; pad the last row
        cells.append('<span class="cal-c pad"></span>')
    return f'''<div class="cal">
<div class="cal-hd"><span class="m">November 2026</span><span class="spacer"></span>
<span class="n">National, local and unit scope</span></div>
<div class="cal-g">{''.join(cells)}</div>
<div class="cal-ft">
<span class="li"><span class="sw-hol"></span>Holiday</span>
<span class="li"><span class="sw-sus"></span>Work suspension</span>
<span class="li"><span class="sw-we"></span>Rest day</span>
</div></div>'''


# ---------------------------------------------------------------- visual C
NX = '<span class="nx">&#8314;&#185;</span>'
# Delos Santos, A. on Rotation, September 2026, continuing past the hero's window.
# Afternoon and Night are two pairs each because this agency punches its breaks, so
# slot 1 prints in the AM columns and slot 2 in the PM columns, whatever the clock
# says (06-attendance.md, CS Form 48).
F48_ROWS = [
    ('25', '14:00', '18:00', '19:02', '22:00', '0:02', False),
    ('26', '13:58', '18:00', '19:00', '22:04', '', True),
    ('27', '', '', '', '', '', True),
    ('28', '', '', '', '', '', False),
    ('29', '22:00', '02:00' + NX, '03:00' + NX, '06:00' + NX, '', False),
    ('30', '22:03', '02:01' + NX, '03:00' + NX, '06:00' + NX, '0:03', False),
]


def f48_html():
    body = []
    for day, a1, a2, p1, p2, ut, we in F48_ROWS:
        cls = ' class="we"' if we else ''
        body.append(
            f'<tr{cls}><td>{day}</td><td>{a1 or "&nbsp;"}</td><td>{a2 or "&nbsp;"}</td>'
            f'<td class="gl">{p1 or "&nbsp;"}</td><td>{p2 or "&nbsp;"}</td>'
            f'<td class="gl u">{ut or "&nbsp;"}</td></tr>')
    return f'''<div class="f48">
<div class="f48-hd"><span class="k">Civil Service Form No. 48</span>
<span class="n">Delos Santos, A., September 2026</span>
<span class="spacer"></span><span class="pill pill--acc">{I_LOCK}Locked</span></div>
<div class="f48-sc"><table>
<thead>
<tr><th></th><th class="g" colspan="2">AM</th><th class="g gl" colspan="2">PM</th><th class="g gl"></th></tr>
<tr><th>Day</th><th>Arrival</th><th>Departure</th><th class="gl">Arrival</th><th>Departure</th>
<th class="gl">Undertime</th></tr>
</thead>
<tbody>
{''.join(body)}
</tbody></table></div>
<div class="f48-ft"><span>Certified by the employee, verified by the supervisor.</span>
<span class="spacer"></span><span>{NX} the punch landed the next day</span></div>
</div>'''


# ---------------------------------------------------------------- the page
def page():
    return f'''
<header class="hnav">
  <div class="hw">
    <a class="mark" href="#top">{MARK}<b>khronoz</b></a>
    <nav class="hnav-l" aria-label="Sections">
      <a href="#product">Product</a>
      <a href="#how">How it works</a>
      <a href="#agencies">For agencies</a>
    </nav>
    <span class="spacer"></span>
    <div class="hnav-cta">
      <a class="lnk" href="#signin">Sign in</a>
      <a class="btn btn--primary" href="#demo">Request a demo</a>
    </div>
  </div>
</header>

<section class="sec sec--hero" id="top">
  <div class="hw">
    <h1 class="h1">Schedules and daily time records,<br>by the rules.</h1>
    <p class="lead">Rostering, biometric timelogs and CS Form 48 for Philippine government
      agencies and private offices, computed under Civil Service Commission rules.</p>
    <div class="cta">
      <a class="btn btn--primary btn--44" href="#demo">Request a demo</a>
      <a class="btn btn--44" href="#how">See how it works</a>
    </div>

    <figure class="fig">
      <div class="fig-hd">
        <span class="m">September 2026</span>
        <span class="spacer"></span>
        <span class="n">Nursing service, 1 to 21 September</span>
      </div>
      {roster_grid()}
      {LEGEND}
      {DUTY}
    </figure>
  </div>
</section>

<section class="sec" id="how">
  <div class="hw">
    <h2 class="h2">How it works</h2>
    <p class="h2-note">One path from the device to the signed form. Every stage keeps its
      own receipts, so a number on the DTR can be traced back to the punch that produced it.</p>
    {steps_html()}
  </div>
</section>

<section class="sec" id="product">
  <div class="hw">
    <div class="feat">
      <div class="feat-t">
        <h2 class="h2">Scheduling that fits real rosters</h2>
        <p class="feat-p">A shift is a day template. A schedule is a cycle of shifts. A roster
          puts an employee on a schedule from a date, with the cycle anchored where you say.
          Fixed hours, the seven flexitime options, a compressed four-day week, a hospital
          rotation of any length, twelve-hour tours and twenty-four hour duty are all rows.</p>
        <p class="feat-p">Night shifts cross midnight without a special case. A 22:00 to 06:00
          shift stays on the day it started, and its 06:00 punch is credited and printed there.</p>
        <ul class="facts">
          <li>A <b>21</b>-day rotation with three team anchors <b>7</b> days apart covers
            every shift on every day, from one schedule.</li>
          <li>Editing a shift changes the future only. Each workday keeps a snapshot of the
            shift it was computed against.</li>
        </ul>
      </div>
      <div class="feat-v">{strip_html()}</div>
    </div>
  </div>
</section>

<section class="sec">
  <div class="hw">
    <div class="feat feat--flip">
      <div class="feat-t">
        <h2 class="h2">The calendar and the rules, built in</h2>
        <p class="feat-p">National holidays come with the product and local holidays are yours.
          A work suspension takes the rest of the day off the expectation and charges the
          absent only from shift start to the announcement. A declaration never recomputes
          hours already rendered.</p>
        <p class="feat-p">Leave, official business, travel orders, pass slips and prayer time
          are one shape with a start and an end, so a Friday exemption from 10:00 to 14:00
          crosses noon without a special case. Overtime needs a written authority; without one,
          excess minutes are recorded and never compensable.</p>
        <ul class="facts">
          <li>Tardiness and undertime are computed in <b>minutes</b> and converted to days
            only on the report, from the CSC table as printed.</li>
          <li>A holiday on the non-working day of a compressed week reverts that week to
            <b>8</b>-hour days, from the moment the holiday was declared.</li>
        </ul>
      </div>
      <div class="feat-v">{cal_html()}</div>
    </div>
  </div>
</section>

<section class="sec">
  <div class="hw">
    <div class="feat">
      <div class="feat-t">
        <h2 class="h2">From the terminal to CS Form 48</h2>
        <p class="feat-p">Terminals connect by push, by pull, or by file import. Every sync
          records what it received, accepted and skipped, and the clock drift it saw. A timelog
          whose device id matches nobody stays visible as unresolved until the enrollment is fixed.</p>
        <p class="feat-p">The month prints as CS Form 48, with AM and PM columns, an undertime
          column, and a day marker on a punch that landed later. It locks when the last out has
          arrived and is certified by the employee, then verified by the supervisor. A wrong
          timelog is voided with a reason, never deleted.</p>
        <ul class="facts">
          <li>The form has four time columns, so a shift with a break fills them in order
            and a <b>&#8314;&#185;</b> marks every punch that landed after midnight.</li>
          <li>A month cannot lock while an out is still pending, so a night shift on the
            <b>30</b>th holds September open until its 06:00 arrives.</li>
        </ul>
      </div>
      <div class="feat-v">{f48_html()}</div>
    </div>
  </div>
</section>

<section class="sec" id="agencies">
  <div class="hw">
    <h2 class="h2">For agencies, for companies</h2>
    <div class="two">
      <div class="col">
        <h3 class="h3">Government agencies</h3>
        <p>One agency to a tenant, with its own units, terminals, shifts, calendar and signing
          chain. The Civil Service Commission rules are applied as written, and the product
          keeps the issuance behind each one visible, so a timekeeper can check a computation
          against the circular rather than take it on trust.</p>
        <ul>
          <li>Units of any shape: <b>departments, divisions, sections</b>, or none.</li>
          <li>Who signs is agency data: <b>employee, supervisor, head</b> and <b>timekeeper</b>,
            in the order the agency uses.</li>
        </ul>
      </div>
      <div class="col">
        <h3 class="h3">Private organizations</h3>
        <p>The same engine with your own policies. Grace minutes, rest days, overtime approval
          and the signing chain are yours to set. The daily time record becomes a timesheet and
          the attestation chain becomes your approval chain.</p>
        <ul>
          <li>Shifts and schedules ship as defaults you copy, then change without waiting
            for a release.</li>
          <li>Minutes, occurrences and day fractions go out to payroll, which applies
            the rates.</li>
        </ul>
      </div>
    </div>
    <p class="isolation">Each agency&rsquo;s data is isolated in its own tenant. Every row
      carries the agency it belongs to, and the database enforces it rather than the
      application alone. Ask us for the current security posture and we will put it in
      writing.</p>
  </div>
</section>

<section class="sec sec--close" id="demo">
  <div class="hw">
    <h2 class="h-close">See khronoz with your own roster.</h2>
    <div class="cta">
      <a class="btn btn--primary btn--44" href="#demo">Request a demo</a>
      <a class="btn btn--44" href="#how">See how it works</a>
    </div>
    <p class="close-p">We set up a demo with your shifts, your terminals and one month of
      your data.</p>
  </div>
</section>

<footer class="foot">
  <div class="hw">
    <div class="foot-top">
      <div>
        <a class="mark" href="#top">{MARK}<b>khronoz</b></a>
        <p class="foot-d">Scheduling and daily time records for Philippine government agencies
          and private offices, under Civil Service Commission rules.</p>
      </div>
      <nav class="foot-l" aria-label="Footer">
        <a href="#product">Product</a>
        <a href="#how">How it works</a>
        <a href="#signin">Sign in</a>
        <a href="#privacy">Privacy</a>
        <a href="#contact">Contact</a>
      </nav>
    </div>
    <p class="foot-c">&copy; 2026 khronoz</p>
  </div>
</footer>
'''


HTML = f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>khronoz &mdash; home</title>
<!-- Generated by scratchpad/build-home.py: the page fragment is emitted twice, once
     per review frame, from one source. Edit the builder, not this file. -->
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800">
<link rel="stylesheet" href="tokens.css">
<link rel="stylesheet" href="ui.css">
<link rel="stylesheet" href="home.css">
<script src="theme.js"></script>
</head>
<body>
<div class="frames">

<p class="caption"><b>Home</b>, 1440. Marketing page. Becomes the server-rendered
<code>home</code> page at route <code>/</code>, replacing <code>welcome</code>.
The nav takes its rule once the page is scrolled.</p>
<div class="frame"><div class="scroll"><div class="home">{page()}</div></div></div>

<p class="caption"><b>Home</b>, 390. The same markup; the layout switches on the
container&rsquo;s own width, so both frames are live on this one document.
The roster fragment scrolls sideways inside its frame.</p>
<div class="frame frame--phone"><div class="scroll"><div class="home">{page()}</div></div></div>

</div>
</body>
</html>
'''

os.makedirs(os.path.dirname(OUT), exist_ok=True)
with open(OUT, 'w') as fh:
    fh.write(HTML)
print(f'wrote {OUT} ({len(HTML)} bytes)')
