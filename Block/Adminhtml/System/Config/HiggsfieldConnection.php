<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MagoAssistant\Mago\Service\Higgsfield\OAuthService;

/**
 * Shows the Higgsfield connection state with a connect or disconnect button.
 */
class HiggsfieldConnection extends Field
{
    public function __construct(
        Context $context,
        private readonly OAuthService $oauth,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $status = $this->oauth->status();
        $connectUrl = $this->escapeUrl($this->getUrl('mago/higgsfield/connect'));

        if (!$status['connected']) {
            return '<p>' . $this->escapeHtml(__('Not connected.')) . '</p>'
                . '<a class="action-default scalable" href="' . $connectUrl . '"><span>'
                . $this->escapeHtml(__('Connect with Higgsfield')) . '</span></a>';
        }

        $account = $status['email'] !== '' ? $status['email'] : (string)__('your Higgsfield account');
        $html = '<p>' . $this->escapeHtml(__('Connected as %1.', $account)) . '</p>';
        if (!$status['can_refresh'] && $status['expires_at'] > 0) {
            $html .= '<p class="note"><span>' . $this->escapeHtml(__(
                'Higgsfield gave no refresh token; reconnect after %1.',
                date('Y-m-d H:i', $status['expires_at'])
            )) . '</span></p>';
        }

        $disconnectUrl = $this->escapeJs($this->getUrl('mago/higgsfield/disconnect'));
        $formKey = $this->escapeJs($this->getFormKey());

        return $html
            . '<a class="action-default scalable" href="' . $connectUrl . '"><span>'
            . $this->escapeHtml(__('Reconnect')) . '</span></a> '
            . '<button type="button" class="action-default scalable" id="mago-higgsfield-disconnect"><span>'
            . $this->escapeHtml(__('Disconnect')) . '</span></button>'
            . '<script>
                document.getElementById("mago-higgsfield-disconnect").addEventListener("click", function () {
                    var form = document.createElement("form");
                    form.method = "post";
                    form.action = "' . $disconnectUrl . '";
                    var key = document.createElement("input");
                    key.type = "hidden";
                    key.name = "form_key";
                    key.value = "' . $formKey . '";
                    form.appendChild(key);
                    document.body.appendChild(form);
                    form.submit();
                });
            </script>';
    }
}
