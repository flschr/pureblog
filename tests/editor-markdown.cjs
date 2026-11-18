const assert = require('node:assert/strict');
const test = require('node:test');

const { markdownEdit } = require('../admin/js/markdown-formatting.js');

test('wraps the selected text with inline formatting', () => {
  assert.deepEqual(markdownEdit('hello world', 6, 11, 'bold'), {
    replacement: '**world**',
    selectionStart: 2,
    selectionEnd: 7,
  });
  assert.equal(markdownEdit('hello', 0, 5, 'italic').replacement, '*hello*');
  assert.equal(markdownEdit('hello', 0, 5, 'strikethrough').replacement, '~~hello~~');
  assert.equal(markdownEdit('hello', 0, 5, 'mark').replacement, '==hello==');
  assert.equal(markdownEdit('hello', 0, 5, 'inline-code').replacement, '`hello`');
});

test('keeps trailing whitespace outside inline formatting', () => {
  assert.equal(markdownEdit('word\n', 0, 5, 'bold').replacement, '**word**\n');
});

test('places the cursor inside an empty wrapper', () => {
  assert.deepEqual(markdownEdit('', 0, 0, 'bold'), {
    replacement: '****',
    selectionStart: 2,
    selectionEnd: 2,
  });
});

test('uses a safe inline-code delimiter around selected backticks', () => {
  assert.deepEqual(markdownEdit('`value`', 0, 7, 'inline-code'), {
    replacement: '`` `value` ``',
    selectionStart: 3,
    selectionEnd: 10,
  });
});

test('links selected text and places the cursor in the URL', () => {
  assert.deepEqual(markdownEdit('Pure Blog', 0, 9, 'link'), {
    replacement: '[Pure Blog]()',
    selectionStart: 12,
    selectionEnd: 12,
  });
});

test('creates an empty link with the cursor in its label', () => {
  assert.deepEqual(markdownEdit('', 0, 0, 'link'), {
    replacement: '[]()',
    selectionStart: 1,
    selectionEnd: 1,
  });
});

test('creates the next numbered footnote and uses selected text as its definition', () => {
  assert.deepEqual(markdownEdit('Text[^1] note', 9, 13, 'footnote'), {
    replacement: '[^2]',
    selectionStart: 4,
    selectionEnd: 4,
    append: '\n\n[^2]: note',
    appendSelectionOffset: 8,
  });
});

test('indents multiline footnote continuations', () => {
  assert.equal(markdownEdit('first\nsecond', 0, 12, 'footnote').append, '\n\n[^1]: first\n    second');
});

test('creates fenced code blocks on block boundaries', () => {
  assert.deepEqual(markdownEdit('before code after', 7, 11, 'code-block'), {
    replacement: '\n```\ncode\n```\n',
    selectionStart: 5,
    selectionEnd: 9,
  });
});

test('uses a longer fence when the selected code contains backticks', () => {
  assert.equal(markdownEdit('```', 0, 3, 'code-block').replacement, '````\n```\n````');
});

test('creates supported admonition blocks', () => {
  assert.equal(markdownEdit('Remember this', 0, 13, 'note').replacement, '> [!NOTE]\n> Remember this');
  assert.equal(markdownEdit('Take care', 0, 9, 'warning').replacement, '> [!WARNING]\n> Take care');
  assert.equal(markdownEdit('Stop', 0, 4, 'caution').replacement, '> [!CAUTION]\n> Stop');
});

test('puts admonitions selected within a paragraph on block boundaries', () => {
  assert.equal(markdownEdit('before one\ntwo after', 7, 14, 'note').replacement, '\n> [!NOTE]\n> one\n> two\n');
});
