import { stamp } from '@/lib/report';

// Monthly training plans (#380): the words the plan page uses for where a plan stands.

const STATUS = {
    none: { label: 'Not written', variant: 'outline' },
    pending: { label: 'Waiting for approval', variant: 'warning' },
    approved: { label: 'Approved', variant: 'success' },
    returned: { label: 'Sent back', variant: 'danger' },
};

export function planStatus(status) {
    return STATUS[status] ?? STATUS.none;
}

// One line on who wrote the plan and who reviewed it last: "Written by SFC Hale.J Wed 11Nov26 12:00Z. Approved by
// CPT Reyes.M Thu 12Nov26 09:30Z."
export function planHistory(plan) {
    if (!plan) return null;

    const parts = [`Written by ${plan.writtenBy}${plan.writtenAt ? ` ${stamp(plan.writtenAt)}` : ''}.`];

    if (plan.reviewedBy && plan.status !== 'pending') {
        const verb = plan.status === 'approved' ? 'Approved' : 'Sent back';
        parts.push(`${verb} by ${plan.reviewedBy}${plan.reviewedAt ? ` ${stamp(plan.reviewedAt)}` : ''}.`);
    }

    return parts.join(' ');
}

// What happens next, in a sentence for the writer.
export function planNextStep(plan, enforced) {
    const reach = enforced ? 'locked on every section report' : 'filled in on every section report, which each section can change';

    if (!plan) return `Send it for approval. Once your company approves it, each week's subject is ${reach}.`;
    if (plan.status === 'approved') return `Approved. Each week's subject is ${reach}. Changing the plan sends it for approval again.`;
    if (plan.status === 'returned') return 'Your company sent this plan back. Change it and send it for approval again.';

    return `Waiting for your company. Once approved, each week's subject is ${reach}.`;
}

// Why a week's subject cannot change, or null when it can.
export function lockedNote(locked) {
    if (locked === 'sent') return "This week's report is sent, so its subject stays as it was.";
    if (locked === 'floor') return 'Too far back to change.';

    return null;
}

// The subjects as the form holds them, trimmed, with blank weeks left out: compared against the saved plan to tell
// whether anything changed.
export function subjectsOf(weeks, values) {
    return Object.fromEntries(
        weeks.map((week) => [week.start, (values[week.start] ?? '').trim()]).filter(([, subject]) => subject !== ''),
    );
}
