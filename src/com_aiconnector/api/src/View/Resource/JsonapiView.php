<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Api\View\Resource;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;
use Joomla\CMS\Uri\Uri;
use Tobscure\JsonApi\Collection;
use Tobscure\JsonApi\Resource;

\defined('_JEXEC') or die;

class JsonapiView extends BaseApiView
{
    public function item(object $item): string
    {
        $this->getDocument()->setData(new Resource($item, $this->serializer));
        $this->getDocument()->addLink('self', Uri::current());

        return $this->getDocument()->render();
    }

    public function collection(array $items, array $meta): string
    {
        $this->getDocument()->setData(new Collection($items, $this->serializer));
        $this->getDocument()->addLink('self', Uri::current());

        foreach ($meta as $key => $value) {
            $this->getDocument()->addMeta($key, $value);
        }

        return $this->getDocument()->render();
    }
}
