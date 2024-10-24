<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Traits;

trait DbDataSet
{
    /**
     * @param array<int|string, mixed> $dataSet
     */
    public function combineDataSet(array $dataSet, string $primaryKey = 'id'): string
    {
        foreach ($dataSet as $id => &$value) {
            $value = array_map(function($elem) {
                return is_numeric($elem)
                    ? $elem
                    : $this->db->quote((string) ($elem ?? ''));
            }, $value);

            $value = (empty($value[$primaryKey]) ? "$id," : '') . implode(',', $value);
        }

        return 'SELECT ' . implode(' UNION ALL SELECT ', $dataSet);
    }
}
