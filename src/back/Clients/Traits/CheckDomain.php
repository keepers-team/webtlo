<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait CheckDomain
{
    /**
     * Домен, по которому определять ид раздачи.
     */
    private string $defaultDomain = 'rutracker';

    /**
     * Домен, по которому определять ид раздачи.
     */
    private ?string $customDomain = null;

    /**
     * Установка своего домена трекера.
     */
    public function setDomain(?string $domain): void
    {
        $this->customDomain = $domain;
    }

    /**
     * Получить ид раздачи из комментария.
     */
    protected function getTorrentTopicId(string $comment): ?int
    {
        if (empty($comment)) {
            return null;
        }

        // Если комментарий содержит подходящий домен
        $isCustom = $this->customDomain !== null && str_contains($comment, $this->customDomain);
        if ($isCustom || str_contains($comment, $this->defaultDomain)) {
            $topicID = preg_replace('/.*?([0-9]*)$/', '$1', $comment);
            $topicID = (int) $topicID;
        }

        return $topicID ?? null;
    }
}
