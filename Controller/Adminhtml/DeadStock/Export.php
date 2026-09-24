<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\DeadStock;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStock;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockCsv;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockReport;

/**
 * Downloads the full obsolete or slow-moving inventory list of the dead_stock tool as CSV.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = DeadStock::ACL;

    public function __construct(
        Context $context,
        private readonly DeadStockReport $report,
        private readonly DeadStockCsv $csv
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $type = (string)$this->getRequest()->getParam('type');
        if (!in_array($type, DeadStockReport::TYPES, true)) {
            $this->messageManager->addErrorMessage(__('Unknown dead stock report type.'));
            return $this->resultRedirectFactory->create()->setPath('admin/dashboard');
        }

        $months = (int)$this->getRequest()->getParam('months', DeadStockReport::DEFAULT_MONTHS);
        $minDaysOfCover = max(1, (int)$this->getRequest()->getParam(
            'min_days_of_cover',
            DeadStockReport::DEFAULT_MIN_DAYS_OF_COVER
        ));

        $fileName = sprintf('%s-inventory-%s.csv', str_replace('_', '-', $type), date('Y-m-d'));

        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/csv; charset=UTF-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents($this->csv->build($this->report->build($type, $months, $minDaysOfCover)));

        return $result;
    }
}
