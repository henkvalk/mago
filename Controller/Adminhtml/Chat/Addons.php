<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Addons\AddonFeed;
use MagoAssistant\Mago\Service\Addons\FeedClient;

/**
 * The add-ons the welcome state shows.
 *
 * This exists so the feed is never fetched while an admin page is being rendered. The panel asks
 * for its add-ons over this request once the screen is already up, so a feed that is slow or down
 * delays nothing an administrator is looking at — it only means the section never appears.
 */
class Addons extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    /** The welcome state has room for two */
    private const LIMIT = 2;

    public function __construct(
        Context $context,
        private readonly AddonFeed $addonFeed,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $addons = $this->addonFeed->getLatest(self::LIMIT);

            // The overview link only goes out with add-ons: on its own it is an advertisement.
            return $result->setData([
                'addons' => $addons,
                'more_url' => $addons ? FeedClient::OVERVIEW_URL : '',
            ]);
        } catch (\Throwable) {
            // Nothing here is worth an error in the panel: no add-ons simply means no section.
            return $result->setData(['addons' => [], 'more_url' => '']);
        }
    }
}
