<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Solution25\Component\AiConnector\Administrator\Connection\PermissionMap;

$action = Route::_('index.php?option=com_aiconnector&view=connector');
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$renderPermissions = function (array $permissions, array $checked, array $held) use ($e): void {
    foreach ($permissions as $permission) {
        $id = 'perm-' . preg_replace('/[^a-z0-9]/', '-', $permission);
        $allowed = $this->grantable[$permission] ?? false;
        $kept = !$allowed && \in_array($permission, $held, true);
        ?>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="permissions[]" id="<?php echo $e($id); ?>"
                value="<?php echo $e($permission); ?>"
                <?php echo \in_array($permission, $checked, true) && ($allowed || $kept) ? 'checked' : ''; ?>
                <?php echo $allowed || $kept ? '' : 'disabled'; ?>>
            <label class="form-check-label" for="<?php echo $e($id); ?>">
                <?php echo $e(PermissionMap::label($permission)); ?>
                <code class="small ms-1"><?php echo $e($permission); ?></code>
                <?php if ($kept) : ?>
                    <span class="badge bg-secondary ms-1">you do not hold this right: you can keep or remove it, not add it</span>
                <?php elseif (!$allowed) : ?>
                    <span class="badge bg-secondary ms-1">you do not hold this right</span>
                <?php endif; ?>
            </label>
        </div>
        <?php
    }
};
?>
<div class="row">
    <div class="col-lg-8">
        <?php if ($this->configurationError !== null) : ?>
            <div class="alert alert-danger"><?php echo $e($this->configurationError); ?></div>
        <?php endif; ?>

        <?php if ($this->connection !== null) : ?>
            <div class="card mb-4">
                <div class="card-header"><h2 class="h4 mb-0">Connected</h2></div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">AI client</dt>
                        <dd class="col-sm-8"><?php echo $e($this->connection['client_name']); ?></dd>
                        <dt class="col-sm-4">Connected</dt>
                        <dd class="col-sm-8">
                            <?php echo $e(HTMLHelper::_('date', $this->connection['connected_at'], 'Y-m-d H:i')); ?>
                            <?php if ($this->connectedBy !== null) : ?> by <?php echo $e($this->connectedBy); ?><?php endif; ?>
                        </dd>
                        <dt class="col-sm-4">Site address</dt>
                        <dd class="col-sm-8"><?php echo $e($this->connection['site_url']); ?></dd>
                        <dt class="col-sm-4">Connector server</dt>
                        <dd class="col-sm-8"><?php echo $e($this->connection['gateway_url']); ?></dd>
                    </dl>

                    <form action="<?php echo $action; ?>" method="post" class="mb-3">
                        <h3 class="h5">Permissions</h3>
                        <p class="text-muted">Only what the AI client asked for when it connected can be granted.</p>
                        <?php $renderPermissions($this->connection['requested_permissions'], $this->connection['permissions'], $this->held); ?>
                        <input type="hidden" name="connection" value="<?php echo (int) $this->connection['id']; ?>">
                        <input type="hidden" name="task" value="connector.permissions">
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <button type="submit" class="btn btn-primary mt-2">Save permissions</button>
                    </form>

                    <form action="<?php echo $action; ?>" method="post"
                        onsubmit="return confirm('Disconnect the AI client? Its access to this site ends right away.');">
                        <input type="hidden" name="connection" value="<?php echo (int) $this->connection['id']; ?>">
                        <input type="hidden" name="task" value="connector.disconnect">
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <button type="submit" class="btn btn-danger">Disconnect</button>
                    </form>
                </div>
            </div>
        <?php elseif ($this->pending !== null) : ?>
            <div class="card mb-4">
                <div class="card-header"><h2 class="h4 mb-0">Review the connection request</h2></div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">AI client</dt>
                        <dd class="col-sm-8"><?php echo $e($this->pending->clientName); ?></dd>
                        <dt class="col-sm-4">Verified client</dt>
                        <dd class="col-sm-8">
                            <?php if ($this->pending->verifiedClient !== null) : ?>
                                <span class="badge bg-success"><?php echo $e($this->pending->verifiedClient); ?></span>
                            <?php else : ?>
                                <span class="badge bg-warning text-dark">not verified</span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-sm-4">Returns to</dt>
                        <dd class="col-sm-8"><?php echo $e($this->pending->returnHost ?? '-'); ?></dd>
                        <dt class="col-sm-4">Started</dt>
                        <dd class="col-sm-8"><?php echo $e($this->pending->startedAt ?? '-'); ?></dd>
                        <dt class="col-sm-4">Site address</dt>
                        <dd class="col-sm-8"><code><?php echo $e($this->siteAddress); ?></code></dd>
                    </dl>

                    <?php if ($this->siteError !== null) : ?>
                        <div class="alert alert-danger"><?php echo $e($this->siteError); ?></div>
                    <?php endif; ?>

                    <form action="<?php echo $action; ?>" method="post">
                        <h3 class="h5">What may it do on this site?</h3>
                        <?php $renderPermissions($this->pending->permissions, $this->pending->permissions, []); ?>
                        <input type="hidden" name="review" value="<?php echo $e($this->pendingReview); ?>">
                        <input type="hidden" name="task" value="connector.connect">
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <button type="submit" class="btn btn-success mt-3" <?php echo $this->siteError !== null ? 'disabled' : ''; ?>>Connect</button>
                    </form>
                    <form action="<?php echo $action; ?>" method="post" class="mt-2">
                        <input type="hidden" name="task" value="connector.cancel">
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <button type="submit" class="btn btn-link px-0">Cancel</button>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <div class="card mb-4">
                <div class="card-header"><h2 class="h4 mb-0">Connect an AI client</h2></div>
                <div class="card-body">
                    <ol>
                        <li>In your AI client (Claude, ChatGPT, Gemini, Claude Code, Codex) add the connector
                            <code><?php echo $e($this->mcpUrl); ?></code>
                            <?php if ($this->sharedMcpUrl !== $this->mcpUrl) : ?>
                                (or <code><?php echo $e($this->sharedMcpUrl); ?></code> to use shops and sites of other platforms in the same connection)
                            <?php endif; ?>
                            and sign in.</li>
                        <li>Enter this site's address and paste the code the sign-in page shows, like <code>S25-K7QM-4XTD</code>.</li>
                        <li>Check what the AI client asks for, pick what it may do and click Connect.</li>
                    </ol>
                    <?php if ($this->siteError !== null) : ?>
                        <div class="alert alert-danger"><?php echo $e($this->siteError); ?></div>
                    <?php endif; ?>
                    <form action="<?php echo $action; ?>" method="post" class="row g-2 align-items-end">
                        <div class="col-sm-5">
                            <label for="aiconnector-site" class="form-label">Site address</label>
                            <input type="url" class="form-control" id="aiconnector-site" name="site" required
                                autocomplete="off" spellcheck="false" placeholder="https://www.example.com" maxlength="255"
                                value="<?php echo $e($this->siteAddress); ?>" aria-describedby="aiconnector-site-help">
                        </div>
                        <div class="col-sm-4">
                            <label for="aiconnector-code" class="form-label">Connection code</label>
                            <input type="text" class="form-control" id="aiconnector-code" name="code" required
                                autocomplete="off" spellcheck="false" placeholder="S25-XXXX-XXXX" maxlength="40">
                        </div>
                        <div class="col-sm-auto">
                            <input type="hidden" name="task" value="connector.check">
                            <?php echo HTMLHelper::_('form.token'); ?>
                            <button type="submit" class="btn btn-primary">Check code</button>
                        </div>
                        <div class="col-12">
                            <div id="aiconnector-site-help" class="form-text">
                                The public https address of this site, exactly as visitors open it (for example
                                https://www.example.com). Make sure it is your own site's correct address: the AI client
                                will reach your site only at this address.
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h2 class="h5 mb-0">Audit log</h2></div>
            <div class="card-body p-0">
                <?php if ($this->audit === []) : ?>
                    <p class="p-3 mb-0 text-muted">Nothing yet.</p>
                <?php else : ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>When</th><th>Action</th><th>Outcome</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($this->audit as $entry) : ?>
                            <tr>
                                <td class="small"><?php echo $e(HTMLHelper::_('date', $entry['created'], 'Y-m-d H:i')); ?></td>
                                <td><?php echo $e($entry['action']); ?></td>
                                <td>
                                    <?php echo $e($entry['outcome']); ?>
                                    <?php if ($entry['reason'] !== '') : ?><br><code class="small"><?php echo $e($entry['reason']); ?></code><?php endif; ?>
                                </td>
                                <td class="small"><?php echo $e($entry['username'] ?? '#' . $entry['user_id']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
