<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\System;

use MaggyAssistant\Base\Service\Skills\AbstractSkill;

class CronStatus extends AbstractSkill
{
    public function getName(): string
    {
        return 'cron_status';
    }

    protected function getBaseDescription(): string
    {
        return 'Monitor Magento cron jobs: check running, scheduled, and failed jobs, and perform health checks.';
    }

    public function getMagentoAcl(): string
    {
        return '';
    }

    protected function getBaseInstructions(): string
    {
        return 'Cron is critical for Magento — indexers, catalog price rules, email sending, and many other '
            . 'processes depend on it. A "running" job older than 1 hour is likely stuck. '
            . 'When reporting issues, always mention the job_code, scheduled_at, and error message if available. '
            . 'If health_check shows no recent success jobs, cron is likely not configured or broken.';
    }
}
