<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

final class PeerInterval
{
    /** @var ?array{value: int, start:int, end: int}[]  */
    private ?array $intervals = null;

    public function __construct(private readonly string $pattern) {}

    /**
     * Преобразует строку из настроек в интервалы со значениями.
     *
     * @return array{value: int, start:int, end: int}[]
     */
    private function parseIntervals(): array
    {
        if (null !== $this->intervals) {
            return $this->intervals;
        }

        // Заменяем все лишние символы.
        $input = (string) preg_replace('/[^0-9:]+/', '/', $this->pattern);

        $useExternalPattern = str_contains($input, ':');

        $values    = array_filter(explode('/', $input));
        $numValues = count($values);

        // Если значений больше 24, то отбросим лишние.
        if ($numValues > 24) {
            $values = array_slice($values, 0, 24);

            $numValues = 24;
        }

        // Вычисляем целочисленную размерность интервала.
        $defaultDuration = 24 / $numValues;
        // Если значение не целое, выбрасываем лишние значения.
        while (is_float($defaultDuration)) {
            $defaultDuration = 24 / --$numValues;
        }

        $intervals = [];

        $currentHour = 0;
        foreach ($values as $value) {
            // Разделяем парные значения на раздельные величины. 5:3 = лимит 5 сидов, интервал 3 часа.
            if ($useExternalPattern) {
                [$value, $duration] = array_map('intval', explode(':', (string) $value));
            }

            // Если число одинокое, используем интервал по умолчанию.
            $duration ??= $defaultDuration;

            $intervals[] = [
                'value' => max(0, min((int) $value, 99)),
                'start' => $currentHour,
                'end'   => $currentHour + $duration,
            ];

            $currentHour += $duration;
            if ($currentHour >= 24) {
                break;
            }
        }

        return $this->intervals = $intervals;
    }

    /**
     * Проверяет, в каком интервале находится текущее время, и возвращает значение.
     */
    public function getCurrentIntervalPeerLimit(int $currentHour): ?int
    {
        $intervals = $this->parseIntervals();

        foreach ($intervals as $interval) {
            if ($currentHour >= $interval['start'] && $currentHour < $interval['end']) {
                return (int) $interval['value'];
            }
        }

        return null;
    }
}
