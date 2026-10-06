<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\SubmissionFile;
use Corrai\Model\Student;
use Corrai\Subject\DictationFranceCM2\Assessment as DictationFranceCM2Assessment;
use Corrai\Subject\DictationFranceCM2\File as DictationFranceCM2File;
use Corrai\Subject\Math\Assessment as MathAssessment;
use Corrai\Model\StateLocales;
use PHPUnit\Framework\TestCase;

class MenuTest extends TestCase
{
    public function testAssessmentMenuAndLabelFollowTheLocale(): void
    {
        $assessment = new DictationFranceCM2Assessment();
        $french = $assessment->to_output('fr');
        $this->assertSame('Dictée CM2 France', $french['label']);
        $this->assertSame(
            ['edit', 'delete', 'edit_subject', 'add_copies'],
            array_column($french['menu'], 'key')
        );
        $this->assertSame('', $french['menu'][0]['icon']);
        $this->assertSame('Modifier', $french['menu'][0]['label']);
        $this->assertSame('Supprimer l\'évaluation', $french['menu'][1]['label']);
        $this->assertSame('#c93b45', $french['menu'][1]['color']);
        $this->assertSame('Ajouter des copies', $french['menu'][3]['label']);

        $english = $assessment->to_output('en-US');
        $this->assertSame('Dictation CM2 France', $english['label']);
        $this->assertSame('Edit', $english['menu'][0]['label']);
    }

    public function testStudentMenuUsesIconActions(): void
    {
        $student = new Student();
        $menu = $student->get_menu('fr');
        $this->assertSame(['view', 'rename', 'delete'], array_column($menu, 'key'));
        $this->assertSame('Voir l\'élève', $menu[0]['label']);
        $this->assertSame('eye', $menu[0]['icon']);
        $this->assertSame('Supprimer', $menu[2]['label']);
        $this->assertSame('#c93b45', $menu[2]['color']);
    }

    public function testUnknownLocaleUsesFrenchLabels(): void
    {
        $assessment = new MathAssessment();
        $output = $assessment->to_output('zz');
        $this->assertSame('Math', $output['label']);
        $this->assertSame('Modifier', $output['menu'][0]['label']);
    }

    public function testFileMenuDependsOnTheFileType(): void
    {
        $submission = new DictationFranceCM2File();
        $submission->type = 'submission';
        $submission->name = 'copy.png';
        $submission->content_type = 'image/png';
        $output = $submission->to_output(null, 'fr');
        $this->assertSame('Copie', $output['label']);
        $this->assertSame(
            ['view', 'reassign', 'events', 'rename', 'delete'],
            array_column($output['menu'], 'key')
        );
        $this->assertSame('eye', $output['menu'][0]['icon']);
        $this->assertSame('Supprimer', $output['menu'][4]['label']);

        $submission->status = 'stored';
        $this->assertSame('Stocké', $submission->get_status_label('fr'));
        $this->assertSame('Stocké', $submission->to_output(null, 'fr')['status_label']);
        $submission->status = 'ocr_done';
        $this->assertSame('OCR terminé', $submission->get_status_label('fr'));
        $this->assertSame('OCR done', $submission->get_status_label('en'));
        $submission->status = 'errors_found';
        $this->assertSame('Erreurs trouvées', $submission->get_status_label('fr'));
        $submission->status = 'annotations';
        $this->assertSame('Anmerkungen', $submission->get_status_label('de'));

        $generic = new SubmissionFile();
        $generic->status = 'stored';
        $this->assertSame('Gespeichert', $generic->get_status_label('de'));
        $generic->status = 'ocr_done';
        $this->assertSame('ocr_done', $generic->get_status_label('fr'));
        $generic->status = 'corrected';
        $this->assertSame('Corrigé', $generic->get_status_label('fr'));
        $this->assertSame('Corrected', $generic->to_output(null, 'en')['status_label']);
        $generic->status = 'error';
        $this->assertSame('Erreur', $generic->get_status_label('fr'));
        $this->assertSame('Fehler', $generic->get_status_label('de'));

        $subject = new SubmissionFile();
        $subject->type = 'subject';
        $subject->name = 'sujet.pdf';
        $subject->content_type = 'application/pdf';
        $subjectOutput = $subject->to_output('Ada', 'de');
        $this->assertSame('Ada', $subjectOutput['student_name']);
        $this->assertSame('Aufgabenstellung', $subjectOutput['label']);
        $keys = array_column($subjectOutput['menu'], 'key');
        $this->assertSame(['view', 'events', 'rename', 'delete'], $keys);
        $this->assertSame('Löschen', $subjectOutput['menu'][array_key_last($subjectOutput['menu'])]['label']);

        $instructions = new SubmissionFile();
        $instructions->type = 'instructions';
        $instructions->name = 'instructions.md';
        $instructions->content_type = 'text/markdown';
        $instructionKeys = array_column($instructions->get_menu('en'), 'key');
        $this->assertSame(['view', 'events', 'rename', 'delete'], $instructionKeys);
        $instructionOutputFr = $instructions->to_output(null, 'fr');
        $this->assertSame('Consignes particulières', $instructionOutputFr['label']);
    }

    public function testStateLocalesAreKeyLabelMapsForTheQueriedLocale(): void
    {
        $dictation = new DictationFranceCM2Assessment();
        $french = StateLocales::maps($dictation, 'fr');
        $this->assertSame('Noté', $french['student_states']['graded']);
        $this->assertSame('En attente', $french['student_states']['pending']);
        $this->assertSame('Brouillon', $french['assessment_states']['draft']);
        $this->assertSame('Correction en cours', $french['assessment_states']['correcting']);
        $this->assertSame('Corrigé', $french['assessment_states']['corrected']);
        $this->assertSame('Stocké', $french['file_states']['stored']);
        $this->assertSame('OCR terminé', $french['file_states']['ocr_done']);
        $this->assertSame('Erreurs trouvées', $french['file_states']['errors_found']);
        $this->assertSame('Annotations', $french['file_states']['annotations']);

        $english = StateLocales::maps($dictation, 'en-US');
        $this->assertSame('Graded', $english['student_states']['graded']);
        $this->assertSame('Draft', $english['assessment_states']['draft']);
        $this->assertSame('OCR done', $english['file_states']['ocr_done']);
        $this->assertSame('Annotations', $english['file_states']['annotations']);

        $math = StateLocales::maps(new MathAssessment(), 'de');
        $this->assertSame('Benotet', $math['student_states']['graded']);
        $this->assertSame('Entwurf', $math['assessment_states']['draft']);
        $this->assertSame('Gespeichert', $math['file_states']['stored']);
        $this->assertSame('Transkribiert', $math['file_states']['transcribed']);
        $this->assertSame('Korrektur bereit', $math['file_states']['correction_ready']);
        $this->assertArrayNotHasKey('ocr_done', $math['file_states']);

        $generic = new SubmissionFile();
        $generic->status = 'correction_asked';
        $this->assertSame('Correction demandée', $generic->get_status_label('fr'));
        $this->assertArrayNotHasKey('ocr_done', $generic::statusLabels('fr'));
    }
}
