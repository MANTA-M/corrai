<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseStudent;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Http\WSException;

/**
 * Law France student model.
 */
class Student extends BaseStudent
{
    /**
     * Correct this student's submissions.
     *
     * @return list<SubmissionFile>
     */
    public function correct(): array
    {
        $submissions = $this->getSubmissions();
        if ($submissions === []) {
            throw new WSException('No submissions found for student ' . $this->name, 400);
        }

        $this->status = 'under_correction';
        $this->save();

        if ($this->id !== null && $this->id !== '') {
            RedisQueue::getInstance()->enqueueStudent((string) $this->id, StudentTask1Correcting::class);
        }

        return $submissions;
    }
}

if (!class_exists('LawFrance\Student', false)) {
    class_alias(Student::class, 'LawFrance\Student');
}
