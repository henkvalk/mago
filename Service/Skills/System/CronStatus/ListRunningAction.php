<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class ListRunningAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'list_running';
    }

    public function getDescription(): string
    {
        return 'Currently running cron jobs, flags possibly stuck jobs (running > 1 hour)';
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
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', Schedule::STATUS_RUNNING);
        $collection->setOrder('executed_at', 'DESC');
        $collection->setPageSize(50);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stuckThreshold = $now->modify('-1 hour');

        $jobs = [];
        foreach ($collection as $schedule) {
            $executedAt = $schedule->getData('executed_at');
            $executedDate = $executedAt
                ? new \DateTimeImmutable($executedAt, new \DateTimeZone('UTC'))
                : null;

            $runningSince = null;
            $possiblyStuck = false;
            if ($executedDate) {
                $diff = $now->getTimestamp() - $executedDate->getTimestamp();
                $runningSince = $this->formatDuration($diff);
                $possiblyStuck = $executedDate < $stuckThreshold;
            }

            $jobs[] = [
                'schedule_id' => (int)$schedule->getData('schedule_id'),
                'job_code' => $schedule->getData('job_code'),
                'executed_at' => $executedAt,
                'running_since' => $runningSince,
                'possibly_stuck' => $possiblyStuck,
            ];
        }

        return ['running_jobs' => $jobs, 'count' => count($jobs)];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        $hours = floor($seconds / 3600);
        $minutes = round(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}
