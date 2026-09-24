<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Higgsfield;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Higgsfield\OAuthService;

/**
 * Starts the OAuth authorization with Higgsfield and sends the admin to its login page.
 */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::config';
    public const SESSION_KEY = 'mago_higgsfield_oauth';

    public function __construct(
        Context $context,
        private readonly OAuthService $oauth
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirectUri = $this->getUrl('mago/higgsfield/callback', ['_nosecret' => true]);
        $start = $this->oauth->startAuthorization($redirectUri);
        if (isset($start['error'])) {
            $this->messageManager->addErrorMessage(__('Connecting to Higgsfield failed: %1', $start['error']));
            return $this->resultRedirectFactory->create()
                ->setPath('adminhtml/system_config/edit', ['section' => 'mago']);
        }

        // setData() reaches the session storage through SessionManager::__call().
        // @phpstan-ignore method.notFound
        $this->_session->setData(self::SESSION_KEY, [
            'state' => $start['state'],
            'verifier' => $start['verifier'],
            'redirect_uri' => $redirectUri,
        ]);

        return $this->resultRedirectFactory->create()->setUrl($start['url']);
    }
}
