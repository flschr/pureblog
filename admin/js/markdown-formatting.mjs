function trimTrailingWhitespace(value) {
  const match = value.match(/\s+$/u);
  if (!match) return { content: value, trailing: '' };
  return {
    content: value.slice(0, -match[0].length),
    trailing: match[0],
  };
}

function wrappedEdit(selected, before, after) {
  const { content, trailing } = trimTrailingWhitespace(selected);
  return {
    replacement: before + content + after + trailing,
    selectionStart: before.length,
    selectionEnd: before.length + content.length,
  };
}

function blockEdit(selected, before, after) {
  const content = selected.replace(/^\n+|\n+$/gu, '');
  return {
    replacement: before + content + after,
    selectionStart: before.length,
    selectionEnd: before.length + content.length,
  };
}

function admonitionEdit(selected, type) {
  const content = selected.replace(/^\n+|\n+$/gu, '');
  const body = content === '' ? '> ' : content.split('\n').map((line) => `> ${line}`).join('\n');
  const prefix = `> [!${type}]\n`;
  return {
    replacement: `${prefix}${body}`,
    selectionStart: prefix.length + 2,
    selectionEnd: prefix.length + body.length,
  };
}

function nextFootnoteId(value) {
  const ids = [...value.matchAll(/\[\^(\d+)\]/gu)].map((match) => Number(match[1]));
  return ids.length === 0 ? 1 : Math.max(...ids) + 1;
}

export function markdownEdit(value, start, end, action) {
  const selected = value.slice(start, end);
  switch (action) {
    case 'bold':
      return wrappedEdit(selected, '**', '**');
    case 'italic':
      return wrappedEdit(selected, '*', '*');
    case 'strikethrough':
      return wrappedEdit(selected, '~~', '~~');
    case 'mark':
      return wrappedEdit(selected, '==', '==');
    case 'inline-code':
      return wrappedEdit(selected, '`', '`');
    case 'link': {
      const replacement = selected === '' ? '[]()' : `[${selected}]()`;
      return {
        replacement,
        selectionStart: selected === '' ? 1 : selected.length + 3,
        selectionEnd: selected === '' ? 1 : selected.length + 3,
      };
    }
    case 'footnote': {
      const id = nextFootnoteId(value);
      const reference = `[^${id}]`;
      return {
        replacement: reference,
        selectionStart: reference.length,
        selectionEnd: reference.length,
        append: `\n\n[^${id}]: ${selected}`,
        appendSelectionOffset: `\n\n[^${id}]: `.length,
      };
    }
    case 'code-block':
      return blockEdit(selected, '```\n', '\n```');
    case 'note':
      return admonitionEdit(selected, 'NOTE');
    case 'warning':
      return admonitionEdit(selected, 'WARNING');
    case 'caution':
      return admonitionEdit(selected, 'CAUTION');
    default:
      return null;
  }
}
