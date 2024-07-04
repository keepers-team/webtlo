<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Преобразование HTML-страницы в DOMXPath объект.
 */
trait DomHelper
{
    /**
     * @param string $page HTML-страница в виде строки
     * @return DOMXPath Объект DOMXPath для поиска по DOM-дереву
     */
    protected static function parseDOM(string $page): DOMXPath
    {
        libxml_use_internal_errors(use_errors: true); // Включает внутренние ошибки libxml
        $dom = new DOMDocument();
        $dom->loadHtml(source: $page); // Загружает HTML-страницу в DOM-дерево
        $xpath = new DOMXPath(document: $dom);
        unset($dom);

        return $xpath;
    }

    /**
     * Получает значение первого узла из списка узлов DOM.
     *
     * @param mixed|DOMNodeList<DOMNode> $list Список узлов DOM
     * @return string Значение первого узла в списке, или пустая строка, если узел не найден
     */
    protected static function getFirstNodeValue(mixed $list): string
    {
        return (!empty($list)) ? (string)$list->item(0)?->nodeValue : '';
    }
}
