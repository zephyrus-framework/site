<?php

declare(strict_types=1);

// Leaf indexes titles, headings, the body as `content` and an excerpt that starts
// with the title: keep the body once, as `text`, and use the page's tagline as the excerpt.
// `anchors` lists each heading id with the offset where its text starts in `text`, so a hit
// in the body can open the page at the section that holds it.
const INLINE_TAGS = ['a', 'abbr', 'b', 'code', 'del', 'em', 'i', 'img', 'kbd', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup'];
const HEADING_MARK = "\u{E000}";

function readableText(Dom\Node $node): string
{
    if ($node instanceof Dom\Text) {
        return $node->data;
    }
    $text = '';
    foreach ($node->childNodes as $child) {
        $text .= readableText($child);
    }
    $isBlock = $node instanceof Dom\Element && !in_array($node->localName, INLINE_TAGS, true);
    return $isBlock ? ' ' . $text . ' ' : $text;
}

function collapse(string $text): string
{
    return trim(preg_replace('/[ \t\r\n\f]+/u', ' ', $text));
}

/** Offsets are counted in UTF-16 code units, as JavaScript indexes the same string. */
function jsLength(string $text): int
{
    return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
}

/**
 * @return array{string, list<array{int, string}>} the readable text, and each heading id with its offset in it
 */
function textWithAnchors(Dom\Element $prose): array
{
    $ids = [];
    foreach ($prose->querySelectorAll('h2[id], h3[id], h4[id]') as $heading) {
        $ids[] = $heading->id;
        $heading->prepend(HEADING_MARK);
    }
    $parts = explode(HEADING_MARK, collapse(readableText($prose)));
    $text = array_shift($parts);
    $anchors = [];
    foreach ($parts as $index => $part) {
        $anchors[] = [jsLength($text), $ids[$index]];
        $text .= $part;
    }
    return [$text, $anchors];
}

$dist = dirname(__DIR__) . '/dist';
$indexes = [$dist . '/search.json', ...glob($dist . '/*/search.json')];
foreach ($indexes as $indexPath) {
    $entries = json_decode(file_get_contents($indexPath), true, flags: JSON_THROW_ON_ERROR);
    foreach ($entries as &$entry) {
        $pagePath = $dist . $entry['url'] . '/index.html';
        $prose = is_file($pagePath)
            ? Dom\HTMLDocument::createFromFile($pagePath, LIBXML_NOERROR)->querySelector('main .prose')
            : null;
        if ($prose === null) {
            throw new RuntimeException("No page content to index at {$pagePath}.");
        }
        foreach ($prose->querySelectorAll('h1, .heading-permalink') as $redundant) {
            $redundant->remove();
        }
        $tagline = $prose->querySelector('p');
        $excerpt = $tagline === null ? null : collapse($tagline->textContent);
        [$text, $anchors] = textWithAnchors($prose);
        $entry['excerpt'] = $excerpt ?? mb_substr($text, 0, 160);
        $entry['text'] = $text;
        $entry['anchors'] = $anchors;
        unset($entry['content']);
    }
    unset($entry);
    file_put_contents($indexPath, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    printf("Search: %d pages indexed in %s.\n", count($entries), substr($indexPath, strlen($dist)));
}
