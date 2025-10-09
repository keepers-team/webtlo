<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Front;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Преобразование объекта в массив через рефлектор.
 */
final class Reflection
{
    /**
     * @return array<string, mixed>
     */
    public static function reflect(object $object): array
    {
        $ref = new ReflectionClass($object);

        $data = [];
        foreach ($ref->getProperties() as $property) {
            $value = $property->getValue($object);
            $type  = $property->getType();

            if ($type instanceof ReflectionNamedType) {
                $value = self::transformValueByType($type, $value);
            }

            $data[$property->getName()] = $value;
        }

        return $data;
    }

    private static function transformValueByType(ReflectionNamedType $type, mixed $value): mixed
    {
        // Из Enum вытаскиваем значение.
        if (!$type->isBuiltin() && $value instanceof BackedEnum) {
            return $value->value;
        }

        // true превращаем в нажатый чекбокс.
        if ($type->getName() === 'bool') {
            return $value ? 'checked' : '';
        }

        // Перечисление склеиваем.
        if ($type->getName() === 'array' && is_array($value)) {
            return implode(',', $value);
        }

        return $value;
    }
}
