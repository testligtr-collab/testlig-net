/**
 * Counts Unicode code points like PHP mb_strlen($value, 'UTF-8'), not UTF-16 code units.
 * "😀" is 1; "a\u0307" (combining mark) is 2, same as the server.
 * Line breaks are counted as one character, matching the server (CRLF is normalized to LF).
 */
export function codePointLength(value) {
    const normalized = String(value ?? '').replace(/\r\n?/g, '\n');
    let count = 0;
    for (const _ of normalized) {
        count += 1;
    }

    return count;
}

/** PHP trim() default character set (JS trim() also strips Unicode spaces, PHP trim() does not). */
export function phpTrim(value) {
    return String(value ?? '').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}

/** Counter text used next to the field, e.g. "12 / 150". */
export function counterText(value, max) {
    return `${codePointLength(phpTrim(value))} / ${max}`;
}

export function formatDue(dateValue, timeValue) {
    const date = String(dateValue ?? '').trim();
    const time = String(timeValue ?? '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) {
        return '—';
    }
    const [year, month, day] = date.split('-');

    return `${day}.${month}.${year} ${time}`;
}
