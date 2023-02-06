<?php

include_once dirname(__FILE__) . "/../common/storage.php";

/**
 * Class Filter
 * Класс для работы с фильтром топиков
 */
class Filter
{
    public function __construct()
    {
        $this->filterOptions = $this->readFilterFromFile();
    }

    public mixed $filterOptions;

    public function writeFilterToFile($forumID, $newFilterOptions): bool|int
    {
        $this->filterOptions[$forumID] = $newFilterOptions;
        return file_put_contents(
            getStorageDir() . DIRECTORY_SEPARATOR . 'filter.json',
            json_encode(
                $this->filterOptions
            )
        );
    }

    public function readFilterFromFile(): array
    {
        return (array) json_decode(file_get_contents(getStorageDir() . DIRECTORY_SEPARATOR . 'filter.json'));
    }

    public function getFilterPresetsList(): array
    {
        return array_keys($this->readFilterFromFile());
    }

}