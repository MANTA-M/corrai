<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseStudent;
use Corrai\Model\SubmissionFile;
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
        $assessment = $this->getAssessment();
        $submissions = $this->getSubmissions();
        if ($submissions === []) {
            throw new WSException('No submissions found for student ' . $this->name, 400);
        }

        $this->status = 'pending';
        $this->save();

        foreach ($submissions as $submission) {
            $assessment->correctSubmission($submission->id);
        }

        return $submissions;
    }
}

if (!class_exists('LawFrance\Student', false)) {
    class_alias(Student::class, 'LawFrance\Student');
}
