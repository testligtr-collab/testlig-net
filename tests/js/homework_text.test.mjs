import assert from 'node:assert/strict';
import { test } from 'node:test';
import { codePointLength, counterText, formatDue, phpTrim } from '../../assets/homework_text.js';

test('code points are counted like PHP mb_strlen, not UTF-16 units', () => {
    assert.equal(codePointLength(''), 0);
    assert.equal(codePointLength('Çarşamba'), 8);
    assert.equal(codePointLength('😀'), 1);
    assert.equal(codePointLength('😀😀 Çarşamba'), 11);
    assert.equal(codePointLength('a\u0307'), 2);
    assert.equal(codePointLength(null), 0);
    assert.equal(codePointLength(undefined), 0);
});

test('CRLF and CR count as one line break like the server normalization', () => {
    assert.equal(codePointLength('a\r\nb'), 3);
    assert.equal(codePointLength('a\rb'), 3);
    assert.equal(codePointLength('a\nb'), 3);
});

test('phpTrim strips only the PHP default characters', () => {
    assert.equal(phpTrim('  \t\nbaşlık \r\n'), 'başlık');
    assert.equal(phpTrim('\u00a0başlık\u00a0'), '\u00a0başlık\u00a0');
    assert.equal(phpTrim('\u3000başlık'), '\u3000başlık');
});

test('counter text trims first and uses the field maximum', () => {
    assert.equal(counterText('  Kesirler  ', 150), '8 / 150');
    assert.equal(counterText('😀'.repeat(150), 150), '150 / 150');
    assert.equal(counterText('😀'.repeat(151), 150), '151 / 150');
    assert.equal(counterText('', 10000), '0 / 10000');
});

test('due summary is shown only for a complete date and time', () => {
    assert.equal(formatDue('2026-11-12', '18:30'), '12.11.2026 18:30');
    assert.equal(formatDue('', '18:30'), '—');
    assert.equal(formatDue('2026-11-12', ''), '—');
    assert.equal(formatDue('12.11.2026', '18:30'), '—');
    assert.equal(formatDue(' 2026-11-12 ', ' 09:05 '), '12.11.2026 09:05');
});
