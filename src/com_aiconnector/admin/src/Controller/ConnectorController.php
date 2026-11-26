<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectionService;
use Solution25\Component\AiConnector\Administrator\Connector;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayException;
use Solution25\Component\AiConnector\Administrator\Gateway\PairingRequest;

\defined('_JEXEC') or die;

class ConnectorController extends BaseController
{
    public const PENDING_STATE = 'com_aiconnector.pending';

    public const SITE_STATE = 'com_aiconnector.site';

    private const PAGE = 'index.php?option=com_aiconnector&view=connector';

    private const REVIEW_BYTES = 16;

    public function check(): void
    {
        $this->guard();
        $code = $this->input->post->getString('code', '');
        $site = trim($this->input->post->getString('site', ''));
        $this->app->setUserState(self::SITE_STATE, $site);

        try {
            $service = Connector::service();
            $service->checkSiteAddress($site);
            $request = $service->request($code);
            $this->app->setUserState(self::PENDING_STATE, [
                'code' => $service->validCode($code),
                'site' => $site,
                'request' => $request->toArray(),
                'review' => bin2hex(random_bytes(self::REVIEW_BYTES)),
            ]);
        } catch (GatewayException $e) {
            $this->app->setUserState(self::PENDING_STATE, null);
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->back();
    }

    public function cancel(): void
    {
        $this->guard();
        $this->app->setUserState(self::PENDING_STATE, null);
        $this->back();
    }

    public function connect(): void
    {
        $this->guard();
        $pending = $this->app->getUserState(self::PENDING_STATE);
        $permissions = (array) $this->input->post->get('permissions', [], 'array');

        if (!\is_array($pending) || !\is_string($pending['code'] ?? null) || !\is_string($pending['site'] ?? null) || !\is_array($pending['request'] ?? null)) {
            $this->app->enqueueMessage(GatewayException::invalidCode()->getMessage(), 'error');
            $this->back();

            return;
        }

        if (!\is_string($pending['review'] ?? null) || !hash_equals($pending['review'], $this->input->post->getString('review', ''))) {
            $this->app->enqueueMessage(GatewayException::staleForm()->getMessage(), 'error');
            $this->back();

            return;
        }

        $service = null;

        try {
            $service = Connector::service();
            $service->connect($pending['code'], PairingRequest::fromArray($pending['request']), array_values($permissions), $this->app->getIdentity(), $pending['site']);
            $this->app->setUserState(self::PENDING_STATE, null);
            $this->app->enqueueMessage('Connected. Go back to your AI client to finish.', 'success');
        } catch (GatewayException $e) {
            if (\in_array($e->reason, [GatewayException::REASON_INVALID_CODE, 'unknown_code', 'unknown_or_expired', GatewayException::REASON_ALREADY_CONNECTED, GatewayException::REASON_REQUEST_CHANGED], true)) {
                $this->app->setUserState(self::PENDING_STATE, null);
            }

            $this->app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->warnIfNotAudited($service);
        $this->back();
    }

    public function permissions(): void
    {
        $this->guard();
        $permissions = (array) $this->input->post->get('permissions', [], 'array');

        $service = null;

        try {
            $service = Connector::service();
            $service->changePermissions($this->shownConnection(), array_values($permissions), $this->app->getIdentity());
            $this->app->enqueueMessage('Permissions saved.', 'success');
        } catch (GatewayException $e) {
            $this->app->enqueueMessage($e->getMessage(), $e->reason === GatewayException::REASON_PERMISSIONS_NOT_SYNCED ? 'warning' : 'error');
        }

        $this->warnIfNotAudited($service);
        $this->back();
    }

    public function disconnect(): void
    {
        $this->guard();

        $service = null;

        try {
            $service = Connector::service();
            $told = $service->disconnect($this->shownConnection(), (int) $this->app->getIdentity()->id);
            $this->app->enqueueMessage(
                $told ? 'Disconnected. The AI client has no access to this site any more.'
                    : 'Disconnected on this site: the AI client has no access any more. The connector server could not be told; it will notice on its next request.',
                $told ? 'success' : 'warning'
            );
        } catch (GatewayException $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->warnIfNotAudited($service);
        $this->back();
    }

    private function warnIfNotAudited(?ConnectionService $service): void
    {
        if ($service?->auditMissed()) {
            $this->app->enqueueMessage('This action could not be fully recorded in the audit log. Check aiconnector.php in the Joomla log folder.', 'warning');
        }
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!Connector::canManage($this->app->getIdentity())) {
            throw new \Exception($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function shownConnection(): int
    {
        return $this->input->post->getInt('connection', 0);
    }

    private function back(): void
    {
        $this->setRedirect(Route::_(self::PAGE, false));
    }
}
