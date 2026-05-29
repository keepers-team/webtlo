<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\ClientException;
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
                uri    : 'info/statuses',
                options: ['max_retry_attempts' => 1]
            );

            return $response->getStatusCode() === 200
                && $response->getBody()->getContents() !== '';
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->logger->error(
                'Не удалось авторизоваться в report-api. Проверьте ключи доступа.',
                ['code' => $response->getStatusCode(), 'error' => $response->getReasonPhrase()]
            );
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Ошибка при попытке авторизации в report-api.',
                ['code' => $e->getCode(), 'error' => $e->getMessage()]
            );
        }

        return false;
    }
}
