(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.PureblogMarkdown = api;
})(typeof window === 'undefined' ? null : window, () => {
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

  function blockBoundaries(value, start, end) {
    return {
      prefix: start > 0 && value[start - 1] !== '\n' ? '\n' : '',
      suffix: end < value.length && value[end] !== '\n' ? '\n' : '',
    };
  }

  function fencedCodeEdit(value, start, end) {
    const content = value.slice(start, end).replace(/^\n+|\n+$/gu, '');
    const runs = [...content.matchAll(/`+/gu)].map((match) => match[0].length);
    const fence = '`'.repeat(Math.max(3, ...runs.map((length) => length + 1)));
    const { prefix, suffix } = blockBoundaries(value, start, end);
    const before = `${prefix}${fence}\n`;
    return {
      replacement: `${before}${content}\n${fence}${suffix}`,
      selectionStart: before.length,
      selectionEnd: before.length + content.length,
    };
  }

  function inlineCodeEdit(selected) {
    const runs = [...selected.matchAll(/`+/gu)].map((match) => match[0].length);
    const delimiter = '`'.repeat(Math.max(1, ...runs.map((length) => length + 1)));
    const padding = selected.startsWith('`') || selected.endsWith('`') ? ' ' : '';
    const before = delimiter + padding;
    return {
      replacement: before + selected + padding + delimiter,
      selectionStart: before.length,
      selectionEnd: before.length + selected.length,
    };
  }

  function admonitionEdit(value, start, end, type) {
    const content = value.slice(start, end).replace(/^\n+|\n+$/gu, '');
    const body = content === '' ? '> ' : content.split('\n').map((line) => `> ${line}`).join('\n');
    const { prefix: boundaryPrefix, suffix } = blockBoundaries(value, start, end);
    const prefix = `${boundaryPrefix}> [!${type}]\n`;
    return {
      replacement: `${prefix}${body}${suffix}`,
      selectionStart: prefix.length + 2,
      selectionEnd: prefix.length + body.length,
    };
  }

  function nextFootnoteId(value) {
    const ids = [...value.matchAll(/\[\^(\d+)\]/gu)].map((match) => Number(match[1]));
    return ids.length === 0 ? 1 : Math.max(...ids) + 1;
  }

  function footnoteDefinition(id, selected) {
    const lines = selected.split('\n');
    const continuation = lines.slice(1).map((line) => `    ${line}`);
    return `\n\n[^${id}]: ${[lines[0], ...continuation].join('\n')}`;
  }

  function markdownEdit(value, start, end, action) {
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
        return inlineCodeEdit(selected);
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
          append: footnoteDefinition(id, selected),
          appendSelectionOffset: `\n\n[^${id}]: `.length,
        };
      }
      case 'code-block':
        return fencedCodeEdit(value, start, end);
      case 'note':
        return admonitionEdit(value, start, end, 'NOTE');
      case 'warning':
        return admonitionEdit(value, start, end, 'WARNING');
      case 'caution':
        return admonitionEdit(value, start, end, 'CAUTION');
      default:
        return null;
    }
  }

  return { markdownEdit };
});
