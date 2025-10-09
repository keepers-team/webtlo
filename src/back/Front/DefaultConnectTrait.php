<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Front;

trait DefaultConnectTrait
{
    public function getSelectOptions(): string
    {
        $options = [];

        // Перебираем доступные варианты.
        foreach (self::validUrl as $value) {
            $selected = $value === $this->baseUrl ? 'selected' : '';

            $options[] = sprintf(Render::optionTemplate, $value, $selected, $value);
        }

        // Добавляем опцию для ручного ввода.
        $options[] = sprintf(
            Render::optionTemplate,
            'custom',
            $this->isCustom ? 'selected' : '',
            'другой'
        );

        return implode('', $options);
    }

    public function getCustomUrl(): string
    {
        return $this->isCustom ? $this->baseUrl : '';
    }
}
