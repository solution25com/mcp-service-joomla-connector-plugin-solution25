<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Gateway;

use Solution25\Component\AiConnector\Administrator\Connection\PermissionMap;
use Solution25\Component\AiConnector\Administrator\Connection\SiteDetails;

\defined('_JEXEC') or die;

final class JoomlaProtocol
{
    public const PLATFORM = 'joomla';

    public function __construct(
        private readonly PermissionMap $permissionMap,
    ) {
    }

    public function isGrantable(string $permission): bool
    {
        return $this->permissionMap->isKnown($permission);
    }

    public function rights(array $permissions): array
    {
        return $this->permissionMap->rights($permissions);
    }

    public function pairingPayload(string $code, SiteDetails $site, string $apiToken, string $accessLevel, array $permissions): array
    {
        return [
            'code' => $code,
            'shop' => [
                'platform' => self::PLATFORM,
                'url' => $site->url,
                'apiUrl' => $site->apiUrl,
                'name' => $site->name,
                'joomlaVersion' => $site->joomlaVersion,
                'extensionVersion' => $site->extensionVersion,
                'hikashopVersion' => $site->hikashopVersion,
            ],
            'credentials' => [
                'apiToken' => $apiToken,
            ],
            'accessLevel' => $accessLevel,
            'permissions' => $permissions,
        ];
    }
}
