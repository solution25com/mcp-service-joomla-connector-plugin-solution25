<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayConfiguration;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayException;

\defined('_JEXEC') or die;

final class SiteAddress
{
    public const EXTENSION_VERSION = '1.0.0';

    public const API_PATH = '/api/index.php/v1';

    private const HTTPS_SCHEME = 'https';

    private const MAX_NAME_LENGTH = 100;

    private const DEVELOPMENT_SUFFIXES = ['.ddev.site'];

    private const PRIVATE_SUFFIXES = ['.local', '.localhost', '.test', '.internal', '.lan', '.home.arpa', '.ddev.site'];

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public static function isDevelopment(): bool
    {
        return GatewayConfiguration::isOverridden() || (\defined('JDEBUG') && JDEBUG);
    }

    public function suggested(): string
    {
        return trim((string) Factory::getApplication()->get('live_site', ''));
    }

    public function details(string $address): SiteDetails
    {
        $address = trim($address);

        if ($address === '') {
            throw GatewayException::siteAddressMissing();
        }

        try {
            $root = new Uri($address);
        } catch (\RuntimeException) {
            throw GatewayException::invalidSiteUrl();
        }

        $scheme = strtolower((string) $root->getScheme());
        $host = rtrim(strtolower((string) $root->getHost()), '.');
        $port = $root->getPort();

        if ($scheme !== self::HTTPS_SCHEME || !$this->isOwnPublicHost($host)) {
            throw GatewayException::invalidSiteUrl();
        }

        $origin = self::HTTPS_SCHEME . '://' . $host . ($port === null || (int) $port === 443 ? '' : ':' . $port);
        $path = rtrim((string) $root->getPath(), '/');

        return new SiteDetails(
            $origin . $path,
            $origin . $path . self::API_PATH,
            $this->name($host),
            (new Version())->getShortVersion(),
            self::EXTENSION_VERSION,
            $this->hikashopVersion(),
        );
    }

    private function isOwnPublicHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return false;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::isDevelopment() && \in_array($suffix, self::DEVELOPMENT_SUFFIXES, true);
            }
        }

        return true;
    }

    private function name(string $host): string
    {
        $name = trim((string) Factory::getApplication()->get('sitename', ''));

        return mb_substr($name !== '' ? $name : $host, 0, self::MAX_NAME_LENGTH);
    }

    private function hikashopVersion(): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('manifest_cache'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_hikashop'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
            ->where($this->db->quoteName('enabled') . ' = 1');
        $manifest = json_decode((string) $this->db->setQuery($query)->loadResult(), true);

        return \is_array($manifest) && \is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
    }
}
