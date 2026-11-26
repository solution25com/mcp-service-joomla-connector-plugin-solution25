<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Solution25\Component\AiConnector\Administrator\Connector;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    protected $default_view = 'connector';

    public function display($cachable = false, $urlparams = [])
    {
        if (!Connector::canManage($this->app->getIdentity())) {
            throw new \Exception($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->input->set('view', 'connector');

        return parent::display(false, $urlparams);
    }
}
