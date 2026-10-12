import { forumDate } from './forumDate';
import { countsOf, displayName, isLocked, practiceLabel, rankOf, signatureLabel } from './rollcall';

// The rollcall forum post (Story 3.10): one fixed default template — template versions (3.5, 3.6,
// 3.11) are not built yet, so there is always exactly one, and it is always active. The portal
// never posts it (AD-20); the leader copies it into the week's rollcall thread.
//
// `rollcallPost()` is the post as data — the live preview renders it — and `rollcallBbcode()`
// writes the same data as forum BBCode, so the preview and the copied text cannot disagree.

// Forum markup in a name or rank would break the table (or smuggle tags into the post).
const plain = (text) => String(text ?? '').replace(/\[/g, '(').replace(/\]/g, ')');

export function rollcallPost({ designation, weekStart, troopers }) {
    const counts = countsOf(troopers);
    const rows = troopers.map((trooper) => ({
        id: trooper.id,
        rank: plain(rankOf(trooper) ?? ''),
        name: plain(displayName(trooper)),
        signature: isLocked(trooper) ? 'LOA' : signatureLabel(trooper.rollcall),
        practice: practiceLabel(trooper.status),
    }));

    const summary = [
        `Signed ${counts.total - counts.unsigned} of ${counts.total}.`,
        `Present ${counts.present}, missed ${counts.missed}, makeup ${counts.makeup}, attached ${counts.attached}, LOA ${counts.loa}` +
            (counts.unmarked ? `, not marked ${counts.unmarked}.` : '.'),
    ].join(' ');

    return {
        title: `${plain(designation)} Rollcall, week of ${forumDate(weekStart)}`,
        columns: ['Rank', 'Name', 'Rollcall', 'SP'],
        rows,
        summary: rows.length ? summary : 'No troopers are placed in this section.',
    };
}

// How each SP status is written in the post: a miss stands out, the rest are their label.
function practiceCell(label) {
    return label === 'Missed' ? '[color=#c0392b]Missed[/color]' : label;
}

export function rollcallBbcode(input) {
    const post = rollcallPost(input);
    const cells = (values) => `[tr]${values.map((value) => `[td]${value}[/td]`).join('')}[/tr]`;
    const lines = [`[b]${post.title}[/b]`, ''];

    if (post.rows.length) {
        lines.push(
            '[table]',
            cells(post.columns.map((column) => `[b]${column}[/b]`)),
            ...post.rows.map((row) => cells([row.rank, row.name, row.signature, practiceCell(row.practice)])),
            '[/table]',
            '',
        );
    }

    lines.push(post.summary);

    return lines.join('\n');
}
