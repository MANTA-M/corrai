<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Model\BaseAssessment;
use Corrai\Model\S3File;
use Corrai\Model\Task\QueueItemTask;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Store\ObjectStore;
use Throwable;

class AssTaskGridCreation extends QueueItemTask
{
    public function __construct(private ?ClaudeSonnetClient $client = null)
    {
    }

    protected function process(object $queue_item_data): void
    {
        $assessmentId = $queue_item_data->assessment_id ?? null;
        if ($assessmentId === null || $assessmentId === '') {
            error_log('[AssTaskGridCreation] Assessment ID is missing on ticket');
            return;
        }

        try {
            $assessment = BaseAssessment::from_hash($assessmentId);
            $this->processAssessment($assessment);
        } catch (Throwable $e) {
            error_log(sprintf('[AssTaskGridCreation] Error processing assessment %s: %s', $assessmentId, $e->getMessage()));
        }
    }

    public function processAssessment(BaseAssessment $assessment): void
    {
        $assessment->status = 'create_correction_grid';
        $assessment->save();

        $this->storeCorrectionGrid($assessment);

        $assessment->status = 'correction_grid_generated';
        $assessment->save();
    }

    private function storeCorrectionGrid(BaseAssessment $assessment): void
    {
        if ($assessment->id === null || $assessment->id === '' || $assessment->school_id === '' || $assessment->user_id === '') {
            throw new WSException('Assessment is incomplete', 400);
        }

        $compiled = $this->compiledSubjectText($assessment);
        $instructions = $this->instructionText($assessment);
        $client = $this->client ?? new ClaudeSonnetClient();
        $client->set_system_content(self::correctionGridSystemPrompt());
        $client->set_json_response('correction_grid', self::correctionGridSchema());

        $userPrompt = '';
        if ($instructions !== '') {
            $userPrompt .= "Instructions de l'évaluation :\n" . $instructions . "\n\n";
        }
        $userPrompt .= "Sujet :\n" . $compiled;
        $client->add_text($userPrompt);
        $reply = $client->call_text();
        $decoded = json_decode($reply, true);
        if (!is_array($decoded)) {
            throw new WSException('Correction grid reply is not JSON', 500);
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new WSException('Cannot encode the correction grid', 500);
        }

        S3File::at(ObjectStore::assessmentSubjectCorrectionGridKey(
            $assessment->school_id,
            $assessment->user_id,
            $assessment->id
        ))->putContents($json . "\n", 'application/json');
    }

    private function compiledSubjectText(BaseAssessment $assessment): string
    {
        $key = ObjectStore::assessmentSubjectCompileKey(
            $assessment->school_id,
            $assessment->user_id,
            (string) $assessment->id
        );
        $store = ObjectStore::getInstance();
        if (!$store->exists($key)) {
            throw new WSException('Compiled subject is missing', 400);
        }
        return S3File::at($key)->getContents();
    }

    private function instructionText(BaseAssessment $assessment): string
    {
        $text = trim($assessment->instructionFilesText());
        if ($text !== '') {
            return $text;
        }

        $templatePath = $assessment->templateInstructionPath();
        if ($templatePath !== null && is_file($templatePath)) {
            $content = file_get_contents($templatePath);
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
        }

        return '';
    }

    private static function correctionGridSystemPrompt(): string
    {
        return "Tu es un correcteur dans une école d'avocat en France. Extrait du sujet et des instructions une grille de correction.\n"
            . "N'extrait du sujet que les informations utiles à la correction. Ignore les informations purement organisationnelles. "
            . "Pour chaque question, propose des critères permettant de donner ou d'enlever des points dans le cadre d'une correction classique. "
            . "Il doit être toujours possible d'avoir 100% des points accordés à la question.\n"
            . "Toutes les instructions utiles présentes dans le fichier d'instructions se retrouveront de manière synthétique dans le tableau \"instructions_générales\" du JSON.\n"
            . "Le contenu du sujet sera réparti sans redites entre les instructions générales et les parties.\n"
            . "Tout doit être en Français.\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function correctionGridSchema(): array
    {
        $criterion = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['critère', 'modificateur'],
            'properties' => [
                'critère' => [
                    'type' => 'string',
                    'description' => 'Critère de correction, en français.',
                ],
                'modificateur' => [
                    'type' => 'integer',
                    'description' => 'Points ajoutés si le nombre est positif, retirés s\'il est négatif.',
                ],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['instructions_générales', 'modificateurs_généraux', 'parties'],
            'properties' => [
                'instructions_générales' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Consignes utiles à la correction.',
                ],
                'modificateurs_généraux' => [
                    'type' => 'array',
                    'items' => $criterion,
                    'description' => 'Bonus ou malus qui s\'appliquent à toute la copie.',
                ],
                'parties' => [
                    'type' => 'array',
                    'description' => 'Parties du sujet.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['titre', 'questions', 'nota_bene'],
                        'properties' => [
                            'titre' => ['type' => 'string'],
                            'questions' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['titre', 'points', 'contenu', 'questions_posées', 'critères_proposés'],
                                    'properties' => [
                                        'titre' => ['type' => 'string'],
                                        'points' => [
                                            'type' => 'integer',
                                            'description' => 'Barème de la question. Les critères positifs doivent pouvoir l\'atteindre en entier.',
                                        ],
                                        'contenu' => ['type' => 'string'],
                                        'questions_posées' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'critères_proposés' => [
                                            'type' => 'array',
                                            'items' => $criterion,
                                        ],
                                    ],
                                ],
                            ],
                            'nota_bene' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

if (!class_exists('LawFrance\\AssTaskGridCreation', false)) {
    class_alias(AssTaskGridCreation::class, 'LawFrance\\AssTaskGridCreation');
}
