<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayClient;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayException;
use Solution25\Component\AiConnector\Administrator\Gateway\JoomlaProtocol;
use Solution25\Component\AiConnector\Administrator\Gateway\PairingRequest;

\defined('_JEXEC') or die;

class ConnectionService
{
    public const ACCESS_LEVEL = 'Custom';

    private const CODE_PATTERN = '/^S25-[2-9A-HJKMNP-TV-Z]{4}-[2-9A-HJKMNP-TV-Z]{4}$/';

    private const MAX_PERMISSIONS = 200;

    public function __construct(
        private readonly GatewayClient $gatewayClient,
        private readonly JoomlaProtocol $protocol,
        private readonly ConnectionStore $connections,
        private readonly ConnectorAccount $account,
        private readonly SiteAddress $siteAddress,
        private readonly AuditLog $auditLog,
        private readonly Lock $lock,
    ) {
    }

    public function connection(): ?array
    {
        return $this->connections->find();
    }


    public function suggestedSiteAddress(): string
    {
        return $this->siteAddress->suggested();
    }

    public function checkSiteAddress(string $address): void
    {
        $this->siteAddress->details($address);
    }

    public function siteProblem(string $address): ?string
    {
        try {
            $this->siteAddress->details($address);
        } catch (GatewayException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function auditMissed(): bool
    {
        return $this->auditLog->missed();
    }

    public function mcpUrl(): string
    {
        return $this->gatewayClient->mcpUrl();
    }

    public function sharedMcpUrl(): string
    {
        return $this->gatewayClient->sharedMcpUrl();
    }

    public function request(string $code): PairingRequest
    {
        return $this->ownRequest($this->gatewayClient->pairingRequest($this->validCode($code)));
    }

    public function connect(string $code, PairingRequest $reviewed, array $permissions, User $admin, string $siteAddress): void
    {
        try {
            $this->lock->exclusively(fn () => $this->link($code, $reviewed, $permissions, $admin, $siteAddress));
        } catch (GatewayException $e) {
            $this->auditLog->record(AuditLog::ACTION_CONNECT, AuditLog::OUTCOME_FAILURE, (int) $admin->id, $e->reason);

            throw $e;
        } catch (\Throwable $e) {
            throw $this->unexpectedFailure(AuditLog::ACTION_CONNECT, (int) $admin->id, $e, GatewayException::localFailure());
        }
    }

    public function changePermissions(int $connectionId, array $permissions, User $admin): void
    {
        try {
            $this->lock->exclusively(fn () => $this->applyPermissions($connectionId, $permissions, $admin));
        } catch (GatewayException $e) {
            if ($e->reason !== GatewayException::REASON_PERMISSIONS_NOT_SYNCED) {
                $this->auditLog->record(AuditLog::ACTION_PERMISSIONS, AuditLog::OUTCOME_FAILURE, (int) $admin->id, $e->reason);
            }

            throw $e;
        } catch (\Throwable $e) {
            throw $this->unexpectedFailure(AuditLog::ACTION_PERMISSIONS, (int) $admin->id, $e, GatewayException::localFailure());
        }
    }

    public function disconnect(int $connectionId, int $adminId): bool
    {
        try {
            return $this->lock->exclusively(fn () => $this->unlink($connectionId, $adminId));
        } catch (GatewayException $e) {
            $this->auditLog->record(AuditLog::ACTION_DISCONNECT, AuditLog::OUTCOME_FAILURE, $adminId, $e->reason);

            throw $e;
        } catch (\Throwable $e) {
            throw $this->unexpectedFailure(AuditLog::ACTION_DISCONNECT, $adminId, $e, GatewayException::disconnectFailure());
        }
    }

    public function canGrant(string $permission, User $admin): bool
    {
        if (!$admin->authorise(PermissionMap::API_LOGIN)) {
            return false;
        }

        foreach ($this->protocol->rights([$permission]) as [$asset, $action]) {
            if (!$admin->authorise($action, $asset === PermissionMap::ROOT_ASSET ? null : $asset)) {
                return false;
            }
        }

        return true;
    }

    private function link(string $code, PairingRequest $reviewed, array $permissions, User $admin, string $siteAddress): void
    {
        $code = $this->validCode($code);
        $permissions = $this->grantable($permissions, $admin);

        if ($this->connections->find() !== null) {
            throw GatewayException::alreadyConnected();
        }

        $site = $this->siteAddress->details($siteAddress);
        $request = $this->ownRequest($this->gatewayClient->pairingRequest($code));

        if (!$this->sameClient($request, $reviewed) || array_diff($permissions, $reviewed->permissions) !== [] || array_diff($permissions, $request->permissions) !== []) {
            throw GatewayException::requestChanged();
        }

        $gatewayUrl = $this->gatewayClient->gatewayUrl();
        $db = $this->connections->database();
        $paired = null;
        $details = ['client' => $request->clientName, 'verifiedClient' => $request->verifiedClient, 'permissions' => $permissions];

        $db->transactionStart();

        try {
            $account = $this->account->create($request->clientName, $this->protocol->rights($permissions));
            $paired = $this->gatewayClient->completePairing(
                $this->protocol->pairingPayload($code, $site, $account['token'], self::ACCESS_LEVEL, $permissions)
            );
            unset($account['token']);
            $this->connections->insert([
                'site_url' => $site->url,
                'gateway_url' => $gatewayUrl,
                'gateway_connection_id' => $paired['connectionId'],
                'connection_secret' => $paired['connectionSecret'],
                'client_name' => mb_substr($request->clientName, 0, 255),
                'access_level' => self::ACCESS_LEVEL,
                'permissions' => $permissions,
                'requested_permissions' => $request->permissions,
                'user_id' => $account['userId'],
                'group_id' => $account['groupId'],
                'connected_by' => (int) $admin->id,
                'connected_at' => Factory::getDate()->toSql(),
            ]);
            $entry = $this->auditLog->begin(AuditLog::ACTION_CONNECT, (int) $admin->id, $details);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $this->rollback($db);

            if ($paired !== null) {
                $this->tellGatewayQuietly($gatewayUrl, $paired['connectionId'], $paired['connectionSecret']);
            }

            if (!$e instanceof GatewayException) {
                ConnectorLog::add('Connecting failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);
            }

            throw $e instanceof GatewayException ? $e : GatewayException::localFailure();
        }

        $this->auditLog->settle($entry, AuditLog::ACTION_CONNECT, AuditLog::OUTCOME_SUCCESS, (int) $admin->id, '', $details);
        $this->refreshSystemCache();
    }

    private function applyPermissions(int $connectionId, array $permissions, User $admin): void
    {
        $connection = $this->shownConnection($connectionId);
        $permissions = $this->grantable($permissions, $admin, $this->heldPermissions($connection));
        $requested = $connection['requested_permissions'] !== [] ? $connection['requested_permissions'] : $connection['permissions'];

        if (array_diff($permissions, $requested) !== []) {
            throw GatewayException::invalidPermissions();
        }

        $db = $this->connections->database();
        $details = ['permissions' => $permissions];
        $db->transactionStart();

        try {
            $this->account->applyRights((int) $connection['group_id'], $this->protocol->rights($permissions));
            $this->connections->update((int) $connection['id'], ['permissions' => $permissions]);
            $entry = $this->auditLog->begin(AuditLog::ACTION_PERMISSIONS, (int) $admin->id, $details);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $this->rollback($db);
            ConnectorLog::add('Changing permissions failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);

            throw GatewayException::localFailure();
        }

        try {
            $this->gatewayClient->updatePermissions(
                (string) $connection['gateway_url'],
                (string) $connection['gateway_connection_id'],
                $this->usableSecret($this->readableSecret((int) $connection['id'])),
                (string) $connection['access_level'],
                $permissions
            );
        } catch (\Throwable $e) {
            $this->auditLog->settle($entry, AuditLog::ACTION_PERMISSIONS, AuditLog::OUTCOME_GATEWAY_NOT_TOLD, (int) $admin->id, $this->gatewayReason($e), $details);

            throw GatewayException::permissionsNotSynced();
        }

        $this->auditLog->settle($entry, AuditLog::ACTION_PERMISSIONS, AuditLog::OUTCOME_SUCCESS, (int) $admin->id, '', $details);
    }

    private function unlink(int $connectionId, int $adminId): bool
    {
        $connection = $this->shownConnection($connectionId);
        $secret = $this->readableSecret((int) $connection['id']);

        $this->account->revokeAccess((int) $connection['user_id']);

        $db = $this->connections->database();
        $details = ['client' => $connection['client_name']];
        $db->transactionStart();

        try {
            if (!$this->account->remove((int) $connection['user_id'], (int) $connection['group_id'])) {
                ConnectorLog::add('The connector user group ' . (int) $connection['group_id'] . ' was kept because it may not belong to the connector.', Log::WARNING);
            }

            $this->connections->delete((int) $connection['id']);
            $entry = $this->auditLog->begin(AuditLog::ACTION_DISCONNECT, $adminId, $details);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $this->rollback($db);
            ConnectorLog::add('Disconnecting failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);

            throw GatewayException::disconnectFailure();
        }

        $this->refreshSystemCache();

        try {
            $this->gatewayClient->disconnect(
                (string) $connection['gateway_url'],
                (string) $connection['gateway_connection_id'],
                $this->usableSecret($secret)
            );
        } catch (\Throwable $e) {
            $this->auditLog->settle($entry, AuditLog::ACTION_DISCONNECT, AuditLog::OUTCOME_GATEWAY_NOT_TOLD, $adminId, $this->gatewayReason($e), $details);

            return false;
        }

        $this->auditLog->settle($entry, AuditLog::ACTION_DISCONNECT, AuditLog::OUTCOME_SUCCESS, $adminId, '', $details);

        return true;
    }

    private function shownConnection(int $connectionId): array
    {
        $connection = $this->connections->find();

        if ($connection === null) {
            throw GatewayException::notConnected();
        }

        if ((int) $connection['id'] !== $connectionId) {
            throw GatewayException::staleForm();
        }

        return $connection;
    }

    public function heldPermissions(array $connection): array
    {
        return array_values(array_filter(
            $connection['permissions'],
            fn (mixed $permission): bool => \is_string($permission)
                && $this->account->hasRights((int) $connection['group_id'], $this->protocol->rights([$permission]))
        ));
    }

    private function gatewayReason(\Throwable $e): string
    {
        if ($e instanceof GatewayException) {
            return $e->reason;
        }

        ConnectorLog::add('Telling the connector server failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);

        return GatewayException::REASON_GATEWAY_ERROR;
    }

    private function unexpectedFailure(string $action, int $adminId, \Throwable $e, GatewayException $failure): GatewayException
    {
        ConnectorLog::add('The ' . $action . ' action failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);
        $this->auditLog->record($action, AuditLog::OUTCOME_FAILURE, $adminId, $failure->reason);

        return $failure;
    }

    private function sameClient(PairingRequest $request, PairingRequest $reviewed): bool
    {
        return $request->clientName === $reviewed->clientName
            && $request->verifiedClient === $reviewed->verifiedClient
            && $request->returnHost === $reviewed->returnHost;
    }

    private function readableSecret(int $connectionId): string
    {
        try {
            return $this->connections->secret($connectionId);
        } catch (\Throwable $e) {
            ConnectorLog::add('Reading the connection secret failed: ' . $e::class, Log::WARNING);

            return '';
        }
    }

    private function refreshSystemCache(): void
    {
        try {
            $this->account->cleanSystemCache();
        } catch (\Throwable $e) {
            ConnectorLog::add('Clearing the system cache failed: ' . $e::class . ' ' . $e->getMessage(), Log::WARNING);
        }
    }

    private function usableSecret(string $secret): string
    {
        if ($secret === '') {
            throw GatewayException::secretUnavailable();
        }

        return $secret;
    }

    private function ownRequest(PairingRequest $request): PairingRequest
    {
        $own = array_values(array_unique(array_filter($request->permissions, $this->protocol->isGrantable(...))));

        if ($own === [] && array_filter($request->permissions, PermissionMap::isShop(...)) !== []) {
            throw GatewayException::shopNotAvailable();
        }

        if ($own === []) {
            throw GatewayException::otherPlatformCode($this->gatewayClient->mcpUrl());
        }

        return new PairingRequest($request->clientName, $own, $request->startedAt, $request->verifiedClient, $request->returnHost);
    }

    private function rollback(DatabaseInterface $db): void
    {
        try {
            $db->transactionRollback();
        } catch (\Throwable $e) {
            ConnectorLog::add('Rolling back failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);
        }
    }

    private function tellGatewayQuietly(string $gatewayUrl, string $connectionId, string $connectionSecret): void
    {
        try {
            $this->gatewayClient->disconnect($gatewayUrl, $connectionId, $connectionSecret);
        } catch (GatewayException $e) {
            ConnectorLog::add('The connector server was not told about a failed connection: ' . $e->reason, Log::WARNING);
        }
    }

    private function grantable(array $permissions, User $admin, array $held = []): array
    {
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        sort($permissions);

        if ($permissions === [] || \count($permissions) > self::MAX_PERMISSIONS) {
            throw GatewayException::invalidPermissions();
        }

        foreach ($permissions as $permission) {
            if (!$this->protocol->isGrantable($permission)) {
                throw GatewayException::invalidPermissions();
            }
        }

        if (PermissionMap::missingDependency($permissions) !== null) {
            throw GatewayException::invalidPermissions();
        }

        if (!$admin->authorise(PermissionMap::API_LOGIN)) {
            throw GatewayException::notYourRights('API login');
        }

        foreach (array_diff($permissions, $held) as $permission) {
            if (!$this->canGrant($permission, $admin)) {
                throw GatewayException::notYourRights(PermissionMap::label($permission));
            }
        }

        return $permissions;
    }

    public function validCode(string $code): string
    {
        $code = strtoupper((string) preg_replace('/\s+/', '', $code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw GatewayException::invalidCode();
        }

        return $code;
    }
}
