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
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

class AuditLog
{
    public const TABLE = '#__aiconnector_audit';

    public const ACTION_CONNECT = 'connect';
    public const ACTION_PERMISSIONS = 'permissions';
    public const ACTION_DISCONNECT = 'disconnect';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_GATEWAY_NOT_TOLD = 'gateway_not_told';
    public const OUTCOME_PENDING = 'pending';

    private const RETENTION = '-180 days';

    private const TRANSACTION_LOST = [1205, 1213, 2006, 2013, 4031, 40001];

    private bool $missed = false;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public function record(string $action, string $outcome, int $userId, string $reason = '', array $details = []): void
    {
        $row = $this->row($action, $outcome, $userId, $reason, $details);

        try {
            $this->db->insertObject(self::TABLE, $row);
        } catch (\Throwable $e) {
            $this->missed = true;
            ConnectorLog::add('Recording the ' . $action . ' audit entry failed: ' . $e::class . ' ' . $e->getMessage(), Log::WARNING);

            return;
        }

        $this->pruneQuietly();
    }

    public function begin(string $action, int $userId, array $details = []): ?int
    {
        $row = $this->row($action, self::OUTCOME_PENDING, $userId, '', $details);

        try {
            $this->db->insertObject(self::TABLE, $row, 'id');
        } catch (\Throwable $e) {
            if (\in_array((int) $e->getCode(), self::TRANSACTION_LOST, true)) {
                throw $e;
            }

            ConnectorLog::add('Recording the pending ' . $action . ' audit entry failed: ' . $e::class . ' ' . $e->getMessage(), Log::WARNING);

            return null;
        }

        return isset($row->id) ? (int) $row->id : null;
    }

    public function settle(?int $entryId, string $action, string $outcome, int $userId, string $reason = '', array $details = []): void
    {
        if ($entryId === null) {
            $this->record($action, $outcome, $userId, $reason, $details);

            return;
        }

        $reason = mb_substr($reason, 0, 64);
        $query = $this->db->createQuery()
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('outcome') . ' = :outcome')
            ->set($this->db->quoteName('reason') . ' = :reason')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':outcome', $outcome)
            ->bind(':reason', $reason)
            ->bind(':id', $entryId, ParameterType::INTEGER);

        try {
            $updated = $this->db->setQuery($query)->execute() !== false && $this->db->getAffectedRows() > 0;
        } catch (\Throwable $e) {
            $this->missed = true;
            ConnectorLog::add('Recording the outcome of audit entry ' . $entryId . ' failed: ' . $e::class . ' ' . $e->getMessage(), Log::WARNING);

            return;
        }

        if (!$updated) {
            ConnectorLog::add('Audit entry ' . $entryId . ' was not found, so its outcome is recorded as a new entry.', Log::WARNING);
            $this->record($action, $outcome, $userId, $reason, $details);

            return;
        }

        $this->pruneQuietly();
    }

    public function missed(): bool
    {
        return $this->missed;
    }

    public function recent(int $limit): array
    {
        $query = $this->db->createQuery()
            ->select(['a.*', $this->db->quoteName('u.username')])
            ->from($this->db->quoteName(self::TABLE, 'a'))
            ->join('LEFT', $this->db->quoteName('#__users', 'u'), $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('a.user_id'))
            ->order($this->db->quoteName('a.id') . ' DESC')
            ->setLimit($limit);

        return $this->db->setQuery($query)->loadAssocList() ?: [];
    }

    private function row(string $action, string $outcome, int $userId, string $reason, array $details): object
    {
        return (object) [
            'created' => Factory::getDate()->toSql(),
            'action' => $action,
            'outcome' => $outcome,
            'reason' => mb_substr($reason, 0, 64),
            'user_id' => $userId,
            'details' => json_encode($details, \JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    private function pruneQuietly(): void
    {
        try {
            $this->prune();
        } catch (\Throwable $e) {
            ConnectorLog::add('Removing old audit entries failed: ' . $e::class . ' ' . $e->getMessage(), Log::WARNING);
        }
    }

    private function prune(): void
    {
        $before = Factory::getDate(self::RETENTION)->toSql();
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('created') . ' < :before')
            ->bind(':before', $before, ParameterType::STRING);
        $this->db->setQuery($query)->execute();
    }
}
