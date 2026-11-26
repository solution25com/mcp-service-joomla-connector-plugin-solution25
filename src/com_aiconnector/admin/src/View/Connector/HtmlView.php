<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\View\Connector;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Solution25\Component\AiConnector\Administrator\Connector;
use Solution25\Component\AiConnector\Administrator\Controller\ConnectorController;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayException;
use Solution25\Component\AiConnector\Administrator\Gateway\PairingRequest;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    private const AUDIT_ROWS = 20;

    public ?array $connection = null;

    public ?string $connectedBy = null;

    public array $held = [];

    public ?PairingRequest $pending = null;

    public string $pendingReview = '';

    public string $mcpUrl = '';

    public string $sharedMcpUrl = '';

    public ?string $configurationError = null;

    public ?string $siteError = null;

    public string $siteAddress = '';

    public array $grantable = [];

    public array $audit = [];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        $admin = $app->getIdentity();

        if (!Connector::canManage($admin)) {
            throw new \Exception($app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $service = Connector::service();

        try {
            $this->mcpUrl = $service->mcpUrl();
            $this->sharedMcpUrl = $service->sharedMcpUrl();
        } catch (GatewayException $e) {
            $this->configurationError = $e->getMessage();
        }

        $this->connection = $service->connection();

        if ($this->connection !== null) {
            $connectedBy = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $this->connection['connected_by']);
            $this->connectedBy = $connectedBy->username ?: null;
            $this->held = $service->heldPermissions($this->connection);
            $permissions = $this->connection['requested_permissions'];
        } else {
            $pending = $app->getUserState(ConnectorController::PENDING_STATE);
            $this->pending = \is_array($pending) && \is_array($pending['request'] ?? null) ? PairingRequest::fromArray($pending['request']) : null;
            $this->pendingReview = \is_array($pending) && \is_string($pending['review'] ?? null) ? $pending['review'] : '';
            $entered = $app->getUserState(ConnectorController::SITE_STATE);
            $this->siteAddress = \is_array($pending) && \is_string($pending['site'] ?? null) ? $pending['site'] : (\is_string($entered) && $entered !== '' ? $entered : $service->suggestedSiteAddress());
            $this->siteError = $this->pending !== null ? $service->siteProblem($this->siteAddress) : null;
            $permissions = $this->pending?->permissions ?? [];
        }

        foreach ($permissions as $permission) {
            $this->grantable[$permission] = $service->canGrant($permission, $admin);
        }

        $this->audit = Connector::auditLog()->recent(self::AUDIT_ROWS);

        ToolbarHelper::title('AI connector', 'robot');

        if ($admin->authorise('core.admin', Connector::COMPONENT) || $admin->authorise('core.options', Connector::COMPONENT)) {
            ToolbarHelper::preferences(Connector::COMPONENT);
        }

        parent::display($tpl);
    }
}
