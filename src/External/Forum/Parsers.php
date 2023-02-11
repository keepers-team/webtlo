<?php

namespace KeepersTeam\Webtlo\External\Forum;

use DOMDocument;

trait Parsers
{
    use XMLHelper;

    protected static function parseTopicIdFromList(string $page, string $fullForumName): ?int
    {
        $result = null;
        $dom = self::parseDOM($page);
        if (null === $dom) {
            return null;
        }

        $xpathQuery = (
            // Main container
            "/html/body/div[@id='body_container']/div[@id='page_container']/div[@id='page_content']"
            // Table with topic rows
            . "/table/tr[1]/td[1]/div[1]/table[contains(@class, 'forum')]/tbody"
            // Row with topic name and links
            . "/tr[contains(@class, 'tCenter')]/td[@class='tLeft']"
            // Link to topic that contains given text
            . "/div/a[contains(text(), '${fullForumName}')]/@href"
        );
        $nodes = $dom->xpath(expression: $xpathQuery);
        if (count($nodes) === 1) {
            $matches = [];
            preg_match("|viewtopic\.php\?t=(\d+)|si", (string)$nodes[0], $matches);
            if (count($matches) === 2) {
                $result = (int)$matches[1];
            }
        }
        unset($nodes);
        unset($dom);

        return $result;
    }
}
