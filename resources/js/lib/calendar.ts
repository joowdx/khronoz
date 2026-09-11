/**
 * Labels for the calendar enums, shared by the exemption and overtime screens.
 *
 * Kept beside the pages rather than derived from the PHP enums, because
 * nothing can import a PHP enum into TypeScript — `EnumCheckContractTest`
 * holds the *values* to the database's CHECKs, and these are only the words
 * shown for them.
 */
const EXEMPTIONS: Record<string, string> = {
    leave: 'Leave',
    business: 'Official business',
    travel: 'Official travel',
    cto: 'Compensatory time off',
    pass: 'Pass slip',
    personal: 'Personal',
};

const OVERTIMES: Record<string, string> = {
    emergency: 'Emergency',
    pay: 'Paid overtime',
    cto: 'For compensatory time off',
};

export function exemptionLabel(type: string): string {
    return EXEMPTIONS[type] ?? type;
}

export function overtimeLabel(mode: string): string {
    return OVERTIMES[mode] ?? mode;
}

/** The options an exemption's type picker offers, in the order it offers them. */
export const EXEMPTION_TYPES = Object.entries(EXEMPTIONS).map(([value, label]) => ({
    value,
    label,
    trigger: label,
}));

/** The options an overtime's mode picker offers. */
export const OVERTIME_MODES = Object.entries(OVERTIMES).map(([value, label]) => ({
    value,
    label,
    trigger: label,
}));
