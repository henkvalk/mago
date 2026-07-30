<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class HealthCheckAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'health_check';
    }

    public function getDescription(): string
    {
        return 'Cron health summary: status counts, stuck jobs, last success, overall health';
    }

    public function getParameterSchema(): array
    {
        return [];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $last24h = $now->modify('-24 hours')->format('Y-m-d H:i:s');
        $lastHour = $now->modify('-1 hour')->format('Y-m-d H:i:s');

        // Count per status in last 24h
        $statusCounts = ['success' => 0, 'pending' => 0, 'running' => 0, 'missed' => 0, 'error' => 0];
        foreach (array_keys($statusCounts) as $status) {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('status', $status);
            $collection->addFieldToFilter('scheduled_at', ['gteq' => $last24h]);
            $statusCounts[$status] = (int)$collection->getSize();
        }

        // Last successful job
        $lastSuccessCollection = $this->collectionFactory->create();
        $lastSuccessCollection->addFieldToFilter('status', Schedule::STATUS_SUCCESS);
        $lastSuccessCollection->setOrder('finished_at', 'DESC');
        $lastSuccessCollection->setPageSize(1);
        $lastSuccess = $lastSuccessCollection->getFirstItem();
        $lastSuccessTime = $lastSuccess->getData('finished_at');

        // Stuck jobs (running > 1 hour)
        $stuckCollection = $this->collectionFactory->create();
        $stuckCollection->addFieldToFilter('status', Schedule::STATUS_RUNNING);
        $stuckCollection->addFieldToFilter('executed_at', ['lt' => $lastHour]);
        $stuckCollection->setPageSize(50);

        $stuckJobs = [];
        foreach ($stuckCollection as $schedule) {
            $stuckJobs[] = [
                'schedule_id' => (int)$schedule->getData('schedule_id'),
                'job_code' => $schedule->getData('job_code'),
                'executed_at' => $schedule->getData('executed_at'),
            ];
        }

        // Oldest pending job
        $oldestPendingCollection = $this->collectionFactory->create();
        $oldestPendingCollection->addFieldToFilter('status', Schedule::STATUS_PENDING);
        $oldestPendingCollection->setOrder('scheduled_at', 'ASC');
        $oldestPendingCollection->setPageSize(1);
        $oldestPending = $oldestPendingCollection->getFirstItem();
        $oldestPendingTime = $oldestPending->getData('scheduled_at');

        // Determine cron_active
        $cronActive = $lastSuccessTime !== null;

        // Determine overall status
        $status = $this->determineStatus(
            $statusCounts,
            $lastSuccessTime,
            count($stuckJobs),
            $lastHour
        );

        return [
            'cron_active' => $cronActive,
            'last_success' => $lastSuccessTime,
            'last_24h' => $statusCounts,
            'stuck_jobs' => $stuckJobs,
            'oldest_pending' => $oldestPendingTime,
            'status' => $status,
        ];
    }

    private function determineStatus(
        array $statusCounts,
        ?string $lastSuccessTime,
        int $stuckCount,
        string $lastHour
    ): string {
        // Critical: no successes ever, or no success in last hour
        if (!$lastSuccessTime) {
            return 'critical';
        }

        if ($lastSuccessTime < $lastHour) {
            return 'critical';
        }

        // Warning: stuck jobs or high error/missed rate
        if ($stuckCount > 0) {
            return 'warning';
        }

        $total = array_sum($statusCounts);
        if ($total > 0) {
            $failRate = ($statusCounts['error'] + $statusCounts['missed']) / $total;
            if ($failRate > 0.1) {
                return 'warning';
            }
        }

        return 'healthy';
    }
}
