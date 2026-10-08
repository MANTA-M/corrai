<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;
use Corrai\Model\Task\QueueItemTask;
use Throwable;

class AssTask1Affectation extends QueueItemTask
{
    protected function process(object $queue_item_data): void
    {
        $assessmentId = $queue_item_data->assessment_id ?? null;
        if ($assessmentId === null || $assessmentId === '') {
            error_log('[AssTask1Affectation] Assessment ID is missing on ticket');
            return;
        }

        try {
            $assessment = BaseAssessment::from_hash($assessmentId);
            $this->processAssessment($assessment);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTask1Affectation] Error processing assessment %s: %s', $assessmentId, $e->getMessage()));
        }
    }

    public function processAssessment(BaseAssessment $assessment): void
    {
        // Affectation of copies to students
    }
}

if (!class_exists('LawFrance\\AssTask1Affectation', false)) {
    class_alias(AssTask1Affectation::class, 'LawFrance\\AssTask1Affectation');
}
