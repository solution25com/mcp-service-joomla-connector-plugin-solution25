<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Gateway;

\defined('_JEXEC') or die;

final class PairingRequest
{
    public function __construct(
        public readonly string $clientName,
        public readonly array $permissions,
        public readonly ?string $startedAt,
        public readonly ?string $verifiedClient,
        public readonly ?string $returnHost,
    ) {
    }

    public function toArray(): array
    {
        return [
            'clientName' => $this->clientName,
            'permissions' => $this->permissions,
            'startedAt' => $this->startedAt,
            'verifiedClient' => $this->verifiedClient,
            'returnHost' => $this->returnHost,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['clientName'] ?? ''),
            array_values(array_filter((array) ($data['permissions'] ?? []), 'is_string')),
            isset($data['startedAt']) ? (string) $data['startedAt'] : null,
            isset($data['verifiedClient']) ? (string) $data['verifiedClient'] : null,
            isset($data['returnHost']) ? (string) $data['returnHost'] : null,
        );
    }
}
