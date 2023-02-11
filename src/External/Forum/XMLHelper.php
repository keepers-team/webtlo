<?php

namespace KeepersTeam\Webtlo\External\Forum;

use DOMDocument;
use SimpleXMLElement;

trait XMLHelper
{
    protected static function parseDOM(string $page): ?SimpleXMLElement
    {
        libxml_use_internal_errors(use_errors: true);
        $html = new DOMDocument();
        $html->loadHtml(source: $page);
        $dom = simplexml_import_dom($html);
        unset($html);
        return $dom;
    }
}
