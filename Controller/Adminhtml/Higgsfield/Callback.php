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
 * Receives the OAuth redirect from Higgsfield and stores the tokens.
 */
class Callback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::config';

    /**
     * The redirect URI is registered without a secret key; the OAuth state protects this request instead.
     *
     * @var string[]
     */
    protected $_publicActions = ['callback'];

    public function __construct(
        Context $context,
        private readonly OAuthService $oauth
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'mago']);

        $pending = $this->_session->getData(Connect::SESSION_KEY, true);
        $state = (string)$this->getRequest()->getParam('state', '');
        if (!is_array($pending) || $state === '' || !hash_equals((string)$pending['state'], $state)) {
            $this->messageManager->addErrorMessage(
                __('The Higgsfield connection could not be verified. Start it again from this page.')
            );
            return $redirect;
        }

        $error = (string)$this->getRequest()->getParam('error', '');
        $code = (string)$this->getRequest()->getParam('code', '');
        if ($error !== '' || $code === '') {
            $this->messageManager->addErrorMessage(
                __('Higgsfield did not grant access%1.', $error !== '' ? ' (' . $error . ')' : '')
            );
            return $redirect;
        }

        $failure = $this->oauth->completeAuthorization(
            $code,
            (string)$pending['verifier'],
            (string)$pending['redirect_uri']
        );
        if ($failure !== null) {
            $this->messageManager->addErrorMessage(__('Connecting to Higgsfield failed: %1', $failure));
            return $redirect;
        }

        $this->messageManager->addSuccessMessage(__('Higgsfield is connected.'));

        return $redirect;
    }
}
