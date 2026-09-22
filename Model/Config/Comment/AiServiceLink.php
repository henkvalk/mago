<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Comment;

use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\CommentInterface;

class AiServiceLink implements CommentInterface
{
    public function __construct(
        private readonly UrlInterface $backendUrl
    ) {
    }

    /**
     * @param string $elementValue
     * @return string
     */
    public function getCommentText($elementValue): string
    {
        $url = $this->backendUrl->getUrl('adminhtml/system_config/edit', ['section' => 'mageos_ai']);

        return (string)__(
            'Which configured AI service the assistant runs on. Providers, API keys, models and '
            . 'endpoints are managed under <a href="%1">Stores &gt; Configuration &gt; Mage-OS &gt; '
            . 'AI Configuration</a>.',
            $url
        );
    }
}
