<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

\defined('_JEXEC') or die;

final class SiteDetails
{
    public function __construct(
        public readonly string $url,
        public readonly string $apiUrl,
        public readonly string $name,
        public readonly string $joomlaVersion,
        public readonly string $extensionVersion,
        public readonly ?string $hikashopVersion,
    ) {
    }
}
