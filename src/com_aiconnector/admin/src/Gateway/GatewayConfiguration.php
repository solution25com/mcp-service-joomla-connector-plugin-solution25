<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Gateway;

\defined('_JEXEC') or die;

final class GatewayConfiguration
{
    public const DEFAULT_MCP_URL = 'https://mcp.solution25.com/joomla';

    public const ENVIRONMENT_VARIABLE = 'AI_CONNECTOR_GATEWAY_URL';

    private const MCP_PATH = '/joomla';

    private const SHARED_MCP_PATH = '/mcp';

    private const HTTPS_SCHEME = 'https';

    private const HTTP_SCHEME = 'http';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', 'host.docker.internal'];

    public static function isOverridden(): bool
    {
        $override = getenv(self::ENVIRONMENT_VARIABLE);

        return \is_string($override) && $override !== '';
    }

    public function mcpUrl(): string
    {
        $parts = $this->parts();
        $path = rtrim($parts['path'], '/');

        return $this->origin($parts) . ($path === '' ? self::MCP_PATH : $path);
    }

    public function sharedMcpUrl(): string
    {
        return $this->origin($this->parts()) . self::SHARED_MCP_PATH;
    }

    public function gatewayUrl(): string
    {
        return $this->origin($this->parts());
    }

    private function parts(): array
    {
        $address = self::isOverridden() ? (string) getenv(self::ENVIRONMENT_VARIABLE) : self::DEFAULT_MCP_URL;
        $parts = filter_var($address, \FILTER_VALIDATE_URL) !== false ? parse_url($address) : false;

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw GatewayException::invalidGatewayUrl();
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $allowed = $scheme === self::HTTPS_SCHEME
            || ($scheme === self::HTTP_SCHEME && \in_array($host, self::LOCAL_HOSTS, true));

        if (!$allowed) {
            throw GatewayException::invalidGatewayUrl();
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
        ];
    }

    private function origin(array $parts): string
    {
        return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] !== null ? ':' . $parts['port'] : '');
    }
}
