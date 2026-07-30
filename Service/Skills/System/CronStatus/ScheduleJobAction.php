<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule as ScheduleResource;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class ScheduleJobAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly ScheduleResource $scheduleResource
    ) {
    }

    public function getName(): string
    {
        return 'schedule_job';
    }

    public function getDescription(): string
    {
        return 'Schedule a cron job to run at the next cron execution';
    }

    public function getParameterSchema(): array
    {
        return [
            'job_code' => [
                'type' => 'string',
                'description' => 'The cron job code to schedule (e.g. indexer_reindex_all_invalid)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getInstructions(): string
    {
        return 'Only schedule jobs that the user explicitly requests. '
            . 'The job will be picked up by the next cron run (usually within 1 minute).';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $jobCode = $params['job_code'] ?? '';
        if (!$jobCode) {
            return ['error' => 'job_code is required'];
        }

        // Validate job_code exists by checking if it has ever appeared in cron_schedule
        $existsCollection = $this->collectionFactory->create();
        $existsCollection->addFieldToFilter('job_code', $jobCode);
        $existsCollection->setPageSize(1);
        if ($existsCollection->getSize() === 0) {
            return ['error' => 'Unknown job_code: ' . $jobCode
                . '. The job has never appeared in the cron schedule. Check the job code and try again.'];
        }

        // Check no pending/running entry exists for this job_code
        $activeCollection = $this->collectionFactory->create();
        $activeCollection->addFieldToFilter('job_code', $jobCode);
        $activeCollection->addFieldToFilter('status', ['in' => [
            Schedule::STATUS_PENDING,
            Schedule::STATUS_RUNNING,
        ]]);
        $activeCollection->setPageSize(1);

        if ($activeCollection->getSize() > 0) {
            $existing = $activeCollection->getFirstItem();
            return ['error' => 'A ' . $existing->getData('status') . ' entry already exists for job_code "'
                . $jobCode . '" (schedule_id: ' . $existing->getData('schedule_id') . '). '
                . 'Wait for it to complete before scheduling a new one.'];
        }

        // Create new schedule entry
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $schedule = $this->scheduleFactory->create();
        $schedule->setData('job_code', $jobCode);
        $schedule->setData('status', Schedule::STATUS_PENDING);
        $schedule->setData('created_at', $now);
        $schedule->setData('scheduled_at', $now);

        $this->scheduleResource->save($schedule);

        return [
            'success' => true,
            'schedule_id' => (int)$schedule->getData('schedule_id'),
            'job_code' => $jobCode,
            'scheduled_at' => $now,
            'message' => 'Job "' . $jobCode . '" has been scheduled. '
                . 'It will be picked up by the next cron run (usually within 1 minute).',
        ];
    }
}
