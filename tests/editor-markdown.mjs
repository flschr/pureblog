import assert from 'node:assert/strict';
import test from 'node:test';

import { markdownEdit } from '../admin/js/markdown-formatting.mjs';

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

test('creates fenced code blocks', () => {
  assert.deepEqual(markdownEdit('const value = 1;', 0, 16, 'code-block'), {
    replacement: '```\nconst value = 1;\n```',
    selectionStart: 4,
    selectionEnd: 20,
  });
});

test('creates supported admonition blocks', () => {
  assert.equal(markdownEdit('Remember this', 0, 13, 'note').replacement, '> [!NOTE]\n> Remember this');
  assert.equal(markdownEdit('Take care', 0, 9, 'warning').replacement, '> [!WARNING]\n> Take care');
  assert.equal(markdownEdit('Stop', 0, 4, 'caution').replacement, '> [!CAUTION]\n> Stop');
});

test('prefixes every selected admonition line', () => {
  assert.equal(markdownEdit('one\ntwo', 0, 7, 'note').replacement, '> [!NOTE]\n> one\n> two');
});
