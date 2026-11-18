<?php

declare(strict_types=1);

function t(string $key): string
{
    return [
        'frontend.admonition_note' => 'Note',
        'frontend.admonition_warning' => 'Warning',
        'frontend.admonition_caution' => 'Caution',
    ][$key] ?? $key;
}

function render_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

require dirname(__DIR__) . '/lib/Parsedown.php';
require dirname(__DIR__) . '/lib/ParsedownExtra.php';
require dirname(__DIR__) . '/lib/ParsedownPureblog.php';

$parser = new ParsedownPureblog();
$parser->setSafeMode(false);

$inline = $parser->text('**bold** *italic* ~~deleted~~ ==highlighted== `code`');
render_assert(str_contains($inline, '<strong>bold</strong>'), 'Bold did not render.');
render_assert(str_contains($inline, '<em>italic</em>'), 'Italic did not render.');
render_assert(str_contains($inline, '<del>deleted</del>'), 'Strikethrough did not render.');
render_assert(str_contains($inline, '<mark>highlighted</mark>'), 'Highlight did not render.');
render_assert(str_contains($inline, '<code>code</code>'), 'Inline code did not render.');

$footnote = $parser->text("Text[^1]\n\n[^1]: First\n    second");
render_assert(str_contains($footnote, 'class="footnote-ref"'), 'Footnote reference did not render.');
render_assert(str_contains($footnote, 'First'), 'Footnote body did not render.');
render_assert(str_contains($footnote, 'second'), 'Multiline footnote continuation did not render.');

$note = $parser->text("> [!NOTE]\n> Body with **formatting**.");
render_assert(str_contains($note, 'class="admonition admonition-note"'), 'Note did not render as an admonition.');
render_assert(str_contains($note, 'aria-label="Note"'), 'Default admonition title is not accessible.');
render_assert(str_contains($note, '<strong>formatting</strong>'), 'Admonition body Markdown did not render.');

$custom = $parser->text("> [!WARNING] Custom title\n> Body");
render_assert(str_contains($custom, 'class="admonition admonition-warning"'), 'Warning did not render as an admonition.');
render_assert(str_contains($custom, 'aria-label="Custom title"'), 'Custom admonition title was lost.');

echo "editor Markdown rendering tests: PASS\n";
