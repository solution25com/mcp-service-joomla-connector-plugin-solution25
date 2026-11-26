<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Api\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Solution25\Component\AiConnector\Api\View\Resource\JsonapiView;
use Tobscure\JsonApi\Exception\InvalidParameterException;

\defined('_JEXEC') or die;

abstract class ResourceController extends BaseController
{
    protected $contentType = '';

    protected function item(array $attributes): static
    {
        echo $this->view()->item((object) $attributes);

        return $this;
    }

    protected function collection(array $items, array $meta = []): static
    {
        echo $this->view()->collection(array_map(static fn (array $item): object => (object) $item, $items), $meta);

        return $this;
    }

    protected function body(): array
    {
        $raw = $this->input->json->getRaw();
        $data = $raw === '' ? [] : json_decode($raw, true);

        if (!\is_array($data)) {
            throw new InvalidParameterException('The request body must be a JSON object.', 400);
        }

        return isset($data['data']['attributes']) && \is_array($data['data']['attributes']) ? $data['data']['attributes'] : $data;
    }

    private function view(): JsonapiView
    {
        $view = new JsonapiView(['name' => 'resource', 'contentType' => $this->contentType, 'base_path' => JPATH_COMPONENT]);
        $view->document = $this->app->getDocument();

        return $view;
    }
}
