<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;
use Corrai\Utils\Http\WSException;

class Assessment extends BaseAssessment
{
    public string $subject = 'Law';
    public ?string $country = 'fr';

    public const NAMES = [
        'en' => 'Law France',
        'fr' => 'Droit France',
        'ru' => 'Право Франция',
        'uk' => 'Право Франція',
        'es' => 'Derecho Francia',
        'pt' => 'Direito Portugal',
        'ro' => 'Drept România',
        'de' => 'Recht Deutschland',
    ];

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }

    public function startCorrection(): array
    {
        if ($this->unclassifiedFileIds() !== []) {
            return $this->correctUnclassifiedFiles();
        }

        $ids = $this->pendingSubmissionIds();
        if ($ids === []) {
            throw new WSException('No copies to correct', 400);
        }
        foreach ($ids as $fileId) {
            $this->correctSubmission($fileId);
        }
        return $this->list_files();
    }
}
