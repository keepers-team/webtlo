<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use DOMElement;
use Psr\Log\LoggerInterface;

/**
 * Работа с авторизацией и CAPTCHA.
 */
trait CaptchaHelper
{
    use DomHelper;

    /**
     * Получение изображения CAPTCHA.
     *
     * @param string $imageLink ссылка на изображение CAPTCHA
     *
     * @return ?string изображение CAPTCHA в формате base64 или null в случае ошибки
     */
    public function fetchCaptchaImage(string $imageLink): ?string
    {
        $sourceData = $this->request(method: 'GET', url: $imageLink, validate: false);
        if ($sourceData === null) {
            return null;
        }

        // Кодируем изображение в base64 и отправляем в форму авторизации.
        return sprintf('data:image/jpg;base64, %s', base64_encode($sourceData));
    }

    /**
     * Парсинг HTML-страницы авторизации и кодов CAPTCHA.
     *
     * @param string          $authPage HTML-страница
     * @param LoggerInterface $logger   Интерфейс для записи журнала
     */
    protected static function parseCaptchaCodes(string $authPage, LoggerInterface $logger): Captcha
    {
        $xpathQuery = [
            'message'     => '//h4[contains(@class, "mrg_16")]',
            'captchaNode' => '//div[contains(@class, "mrg_16")]/table/tr[3]',
            'imageLink'   => 'td/div/img/@src',
            'codes'       => '*//input',
        ];
        $logger->debug('Failed to login. Check message from forum.');
        $dom = self::parseDOM(page: $authPage);

        // Текст ошибки.
        $message = self::getFirstNodeValue(list: $dom->query(expression: $xpathQuery['message']));

        $image = '';
        $codes = [];

        $captchaNode = $dom->query(expression: $xpathQuery['captchaNode']);
        if (!empty($captchaNode)) {
            $captchaNode = $captchaNode->item(0);

            // Ссылка на изображение с кодом.
            $image = self::getFirstNodeValue(
                list: $dom->query(
                    expression : $xpathQuery['imageLink'],
                    contextNode: $captchaNode
                )
            );

            $nodes = $dom->query(expression: $xpathQuery['codes'], contextNode: $captchaNode);
            if (!empty($nodes)) {
                foreach ($nodes as $node) {
                    if ($node instanceof DOMElement) {
                        $codes[] = $node->getAttribute('name');
                        $codes[] = $node->getAttribute('value');
                    }
                }
            }
            $logger->debug('Found captcha codes', $codes);
        }

        return new Captcha($message, $image, $codes);
    }
}
