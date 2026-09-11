/**
 * The attlog's own integers, labelled.
 *
 * `state` and `mode` are stored and sent as raw device integers
 * (03-terminals.md rule 6) precisely so an unfamiliar firmware's code survives
 * instead of being refused or renamed. These maps cover what
 * `docs/design/03-terminals.md` documents; anything else prints as its number,
 * which is honest and still sorts and groups.
 *
 * Never invert these into a lookup the other way. The number is the fact; the
 * label is a courtesy.
 */
const STATES: Record<number, string> = {
    0: 'Check in',
    1: 'Check out',
    2: 'Break out',
    3: 'Break in',
    4: 'Overtime in',
    5: 'Overtime out',
};

const MODES: Record<number, string> = {
    0: 'Fingerprint',
    1: 'Fingerprint',
    2: 'Card',
    3: 'Password',
    4: 'Card',
    15: 'Face',
    16: 'Face',
};

/** What the device said happened, or the bare code when it is not one we know. */
export function stateLabel(state: number): string {
    return STATES[state] ?? `State ${state}`;
}

/** How the person identified themselves, or the bare code. */
export function modeLabel(mode: number): string {
    return MODES[mode] ?? `Mode ${mode}`;
}
