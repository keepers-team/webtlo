<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

/**
 * Объект содержащий сообщение, изображение и коды для верификации.
 */
final class Captcha
{
    /**
     * @param string           $message Сообщение с ошибкой авторизации
     * @param string           $image   URL изображения CAPTCHA
     * @param array{}|string[] $codes   массив строк, содержащий имена и значения кодов CAPTCHA
     */
    public function __construct(
        public readonly string $message,
        public readonly string $image,
        public readonly array  $codes,
    ) {}
}
