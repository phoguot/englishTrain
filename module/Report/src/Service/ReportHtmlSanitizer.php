<?php

declare(strict_types=1);

namespace Report\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

final class ReportHtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'h3', 'h4'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'];

    public function sanitize(mixed $html): string
    {
        if (!is_string($html)) {
            return '';
        }
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="report-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('report-root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->cleanChildren($root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }

                $this->cleanChildren($node);
                if (in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($node->attributes->length > 0) {
                        $node->removeAttributeNode($node->attributes->item(0));
                    }
                } else {
                    while ($node->firstChild !== null) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
            }
            $node = $next;
        }
    }
}
