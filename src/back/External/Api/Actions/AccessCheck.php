<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use GuzzleHttp\Exception\GuzzleException;

trait AccessCheck
{
    /**
     * Проверка доступности API.
     *
     * @return bool статус доступности API
     */
    public function checkAccess(): bool
    {
        try {
            $response = $this->client->get(
                uri    : 'get_client_ip',
                options: ['max_retry_attempts' => 1]
            );

            return $response->getStatusCode() === 200
                && $response->getBody()->getContents() !== '';
        } catch (GuzzleException) {
            $this->logger->error('Не удалось подключиться к API форума. Проверьте настройки доступа в настройках.');
        }

        return false;
    }
}
