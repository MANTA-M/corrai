<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\HasI18nInterface;
use Corrai\Model\HasMenuInterface;
use Corrai\Model\HasStatusInterface;
use Corrai\Model\InputFile;
use Corrai\Model\BaseStudent;
use Corrai\Model\BaseAssessment;
use Corrai\Model\InstructionFile;
use Corrai\Model\SubjectFile;
use Corrai\Model\SubmissionFile;
use Corrai\Model\Student;
use Corrai\Subject\DictationFranceCM2\Assessment as DictationFranceCM2Assessment;
use Corrai\Subject\DictationFranceCM2\Submission as DictationFranceCM2Submission;
use Corrai\Subject\Math\Assessment as MathAssessment;
use PHPUnit\Framework\TestCase;

class MenuTest extends TestCase
{
    public function testAssessmentMenuAndLabelFollowTheLocale(): void
    {
        $assessment = new DictationFranceCM2Assessment();
        $french = $assessment->to_output('fr');
        $this->assertArrayNotHasKey('menu', $french);
        $this->assertSame('Dictée CM2 France', $french['label']);
        $frenchMenu = $assessment->get_menu('fr');
        $this->assertSame(
            ['edit', 'delete', 'edit_subject', 'add_copies'],
            array_column($frenchMenu, 'key')
        );
        $this->assertSame('', $frenchMenu[0]['icon']);
        $this->assertSame('Modifier', $frenchMenu[0]['label']);
        $this->assertSame('Supprimer l\'évaluation', $frenchMenu[1]['label']);
        $this->assertSame('#c93b45', $frenchMenu[1]['color']);
        $this->assertSame('Ajouter des copies', $frenchMenu[3]['label']);

        $english = $assessment->to_output('en-US');
        $this->assertSame('Dictation CM2 France', $english['label']);
        $this->assertSame('Edit', $assessment->get_menu('en-US')[0]['label']);
    }

    public function testStudentMenuUsesIconActions(): void
    {
        $student = new Student();
        $menu = $student->get_menu('fr');
        $this->assertSame(['view', 'transcribe', 'correct', 'rename', 'delete'], array_column($menu, 'key'));
        $this->assertSame('Voir l\'élève', $menu[0]['label']);
        $this->assertSame('eye', $menu[0]['icon']);
        $this->assertSame('Transcription', $menu[1]['label']);
        $this->assertSame('text', $menu[1]['icon']);
        $this->assertSame('Corriger', $menu[2]['label']);
        $this->assertSame('check', $menu[2]['icon']);
        $this->assertSame('Supprimer', $menu[4]['label']);
        $this->assertSame('#c93b45', $menu[4]['color']);
    }

    public function testUnknownLocaleUsesFrenchLabels(): void
    {
        $assessment = new MathAssessment();
        $output = $assessment->to_output('zz');
        $this->assertSame('Math', $output['label']);
        $this->assertArrayNotHasKey('menu', $output);
        $this->assertSame('Modifier', $assessment->get_menu('zz')[0]['label']);
    }

    public function testFileMenuDependsOnTheFileType(): void
    {
        $submission = new DictationFranceCM2Submission();
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

        $subject = new SubjectFile();
        $subject->name = 'sujet.pdf';
        $subject->content_type = 'application/pdf';
        $subjectOutput = $subject->to_output('Ada', 'de');
        $this->assertSame('Ada', $subjectOutput['student_name']);
        $this->assertSame('Aufgabenstellung', $subjectOutput['label']);
        $keys = array_column($subjectOutput['menu'], 'key');
        $this->assertSame(['view', 'events', 'rename', 'delete'], $keys);
        $this->assertSame('Löschen', $subjectOutput['menu'][array_key_last($subjectOutput['menu'])]['label']);

        $instructions = new InstructionFile();
        $instructions->name = 'instructions.md';
        $instructions->content_type = 'text/markdown';
        $instructionKeys = array_column($instructions->get_menu('en'), 'key');
        $this->assertSame(['view', 'events', 'rename', 'delete'], $instructionKeys);
        $instructionOutputFr = $instructions->to_output(null, 'fr');
        $this->assertSame('Consignes particulières', $instructionOutputFr['label']);
    }

    public function testHasI18nProvidesMultilingualStatusLabels(): void
    {
        $dictation = new DictationFranceCM2Assessment();
        $student = new Student();
        $dictationFile = new DictationFranceCM2Submission();

        $this->assertSame('Noté', $student->get_i18n('graded')['fr']);
        $this->assertSame('En attente', $student->get_i18n('pending')['fr']);
        $this->assertSame('Brouillon', $dictation->get_i18n('draft')['fr']);
        $this->assertSame('Correction en cours', $dictation->get_i18n('correcting')['fr']);
        $this->assertSame('Corrigé', $dictation->get_i18n('corrected')['fr']);
        $this->assertSame('Stocké', $dictationFile->get_i18n('stored')['fr']);
        $this->assertSame('OCR terminé', $dictationFile->get_i18n('ocr_done')['fr']);
        $this->assertSame('Erreurs trouvées', $dictationFile->get_i18n('errors_found')['fr']);
        $this->assertSame('Annotations', $dictationFile->get_i18n('annotations')['fr']);

        $this->assertSame('Graded', $student->get_i18n('graded')['en']);
        $this->assertSame('Draft', $dictation->get_i18n('draft')['en']);
        $this->assertSame('OCR done', $dictationFile->get_i18n('ocr_done')['en']);
        $this->assertSame('Annotations', $dictationFile->get_i18n('annotations')['en']);

        $math = new MathAssessment();
        $mathFile = new SubmissionFile();
        $this->assertSame('Benotet', $student->get_i18n('graded')['de']);
        $this->assertSame('Entwurf', $math->get_i18n('draft')['de']);
        $this->assertSame('Gespeichert', $mathFile->get_i18n('stored')['de']);
        $this->assertSame('Transkribiert', $mathFile->get_i18n('transcribed')['de']);
        $this->assertSame('Korrektur bereit', $mathFile->get_i18n('correction_ready')['de']);
        $this->assertArrayNotHasKey('ocr_done', $mathFile->get_i18n());

        $generic = new SubmissionFile();
        $generic->status = 'correction_asked';
        $this->assertSame('Correction demandée', $generic->get_status_label('fr'));
        $this->assertArrayNotHasKey('ocr_done', $generic::statusLabels('fr'));
    }

    public function testSubjectFileDoesNotHaveStudentIdentifier(): void
    {
        $subject = SubjectFile::from_array([
            'id' => 'sub1',
            'name' => 'sujet.pdf',
            'student_identifier' => 'ID-42',
            'qualigraphy_score' => 8,
        ]);
        $this->assertFalse(property_exists($subject, 'student_identifier'));
        $this->assertFalse(property_exists($subject, 'qualigraphy_score'));
        $this->assertArrayNotHasKey('student_identifier', $subject->attributePayload());
        $this->assertArrayNotHasKey('qualigraphy_score', $subject->attributePayload());

        $submission = SubmissionFile::from_array([
            'id' => 'copy1',
            'name' => 'copie.png',
            'student_identifier' => 'ID-42',
            'qualigraphy_score' => 8,
        ]);
        $this->assertTrue(property_exists($submission, 'student_identifier'));
        $this->assertTrue(property_exists($submission, 'qualigraphy_score'));
        $this->assertSame('ID-42', $submission->student_identifier);
        $this->assertSame(8, $submission->qualigraphy_score);
        $this->assertSame('ID-42', $submission->attributePayload()['student_identifier']);
        $this->assertSame(8, $submission->attributePayload()['qualigraphy_score']);
    }

    public function testInterfacesConformance(): void
    {
        $student = new Student();
        $this->assertInstanceOf(HasStatusInterface::class, $student);
        $this->assertInstanceOf(HasI18nInterface::class, $student);
        $this->assertInstanceOf(HasMenuInterface::class, $student);
        $this->assertSame('', $student->get_status());
        $student->status = 'pending';
        $this->assertSame('pending', $student->get_status());
        $this->assertSame('En attente', $student->get_status_label('fr'));
        $this->assertIsArray($student->get_i18n());
        $this->assertSame('En attente', $student->get_i18n('pending')['fr']);
        $this->assertIsArray($student->get_menu());
        $this->assertIsArray($student->get_menu('fr'));

        $assessment = new MathAssessment();
        $this->assertInstanceOf(HasStatusInterface::class, $assessment);
        $this->assertInstanceOf(HasI18nInterface::class, $assessment);
        $this->assertInstanceOf(HasMenuInterface::class, $assessment);
        $this->assertSame('draft', $assessment->get_status());
        $this->assertSame('Brouillon', $assessment->get_status_label('fr'));
        $this->assertIsArray($assessment->get_i18n());
        $this->assertSame('Brouillon', $assessment->get_i18n('draft')['fr']);
        $this->assertIsArray($assessment->get_menu());
        $this->assertIsArray($assessment->get_menu('fr'));

        $file = new SubmissionFile();
        $this->assertInstanceOf(HasStatusInterface::class, $file);
        $this->assertInstanceOf(HasI18nInterface::class, $file);
        $file->status = 'stored';
        $this->assertSame('stored', $file->get_status());
        $this->assertSame('Stocké', $file->get_status_label('fr'));
        $this->assertIsArray($file->get_i18n());
        $this->assertSame('Stocké', $file->get_i18n('stored')['fr']);
        $this->assertIsArray($file->get_menu());
        $this->assertIsArray($file->get_menu('fr'));
    }
}
