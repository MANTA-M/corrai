# pycorrai

Workers Python Corrai : OCR (Paddle / docTR / Vision / Gemini), correction LLM via OpenRouter, et tâches assessment sur Redis.

## Structure

```
pycorrai/
  cli.py              # entrée `pycorrai` / `python -m pycorrai`
  consumer.py         # `python -m pycorrai.consumer` (réexporte worker/)
  config.py           # charge server/php/.env
  worker/             # tickets Redis, OCR job, dispatch tâches
  model/              # domaine Assessment / Student / fichiers
    schemas/          # JSON schemas grilles & correction
    prompts/          # system prompts LLM
    services/         # grilles, correction élèves, OCR non classés
    publishing.py     # events Nchan
  ocr/                # backends locaux + dispatch
    providers/        # Vision, Mindee, Mistral OCR, Eden
  clients/            # clients LLM (OpenRouter, Mistral chat)
  store/              # ObjectStore S3 + keys.py (chemins)
  image/              # PDF split, resize, page zone
  scripts/            # scripts ops (`python -m pycorrai.scripts…`)
  http/               # RestClient / LlmClient
```

`server/python/cli/*.py` sont des wrappers de compatibilité vers `pycorrai.scripts`.

## Flux Redis

| Queue | Rôle |
|-------|------|
| `corrai:ocr` | Tickets OCR `{path, lang?, engine?, after_task?}` ou tâches `{task, assessment_id}` |
| `corrai:files` | Suivi PHP après OCR (`after_task`) |

Tâches assessment supportées : `create_correction_grid`, `create_strict_correction_grid`, `create_math_correction_grid`, `google_vision_ocr_unclassified`.

Démarrage worker : `python -m pycorrai.consumer` (service `corrai-python-consumer`).

## CLI

```bash
# OCR stdin (historique)
pycorrai --engine paddle --lang fr < file.pdf

# Sous-commandes
pycorrai ocr --engine doctr < file.pdf
pycorrai script rerun-gpt
pycorrai script delete-non-ind-schools -- --dry-run

# Scripts package
python -m pycorrai.scripts.rerun_mistral_corrections
```

## Tests

```bash
cd server/python/pycorrai
../.venv/bin/python -m pytest -q
```

Après modification du code Python, relancer `./bin/restart-consumers`.
