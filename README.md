# CurriculumPilot

CurriculumPilot generates Moodle lessons and multiple-choice questions from a book PDF and a table-of-contents file. Teachers start the import from Moodle; a FastAPI service does extraction and LLM structuring, then the Moodle plugin creates Lesson activities (and copies questions into the course question bank when available).

## Repository layout

| Path | Role |
|------|------|
| `api_service.py` | FastAPI entrypoint (`/generate-course`, `/health`, `/admin`) |
| `admin_portal.py` | Admin UI for access keys |
| `subscription_store.py` | SQLite key store |
| `text_extraction.py`, `toc_cleaning.py`, `chapter_split_fuzzy.py`, `topic_generation.py` | Pipeline |
| `templates/admin/` | Admin HTML |
| `local/bookcourse/` | Moodle plugin `local_bookcourse` |
| `.env.example` | Env template → copy to `.env` |

Ignored at runtime: `.env`, `cache/`, `data/`, `temp/`.

## Flow

1. Teacher uploads TOC + book in Moodle.
2. Moodle queues an ad-hoc task and calls `POST /generate-course` (Bearer key optional).
3. API returns chapter/topic/question JSON.
4. Plugin creates Lesson pages and question-bank MCQs.

## Requirements

**API**

- Python 3.10+
- `tesseract-ocr` and `poppler-utils` (for OCR / PDF rendering)
- OpenAI-compatible LLM (defaults assume local Ollama)

```bash
sudo apt install -y tesseract-ocr poppler-utils
```

**Moodle**

- Moodle 4.0+ (`local_bookcourse` requires ≥ 2022041900)
- Cron / ad-hoc tasks enabled

## Run the API

```bash
git clone https://github.com/Pawandadra/CurriculumPilot.git
cd CurriculumPilot

python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

cp .env.example .env
# Set CURRICULUMPILOT_ADMIN_PASSWORD and OPENAI_* as needed

uvicorn api_service:app --host 0.0.0.0 --port 8000
```

- Health: `http://localhost:8000/health`
- Admin: `http://localhost:8000/admin/`
- API schema UI: `http://localhost:8000/docs`

For a public host: set `ADMIN_SESSION_SECRET`, prefer HTTPS (`ADMIN_SESSION_HTTPS_ONLY=true`), and turn on `SUBSCRIPTION_REQUIRED=true` with keys from `/admin/`.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `CURRICULUMPILOT_ADMIN_PASSWORD` | Enables `/admin/` (alias: `ADMIN_PASSWORD`) |
| `ADMIN_SECURITY_QUESTION` / `ADMIN_SECURITY_ANSWER` | Optional login step (both or neither) |
| `ADMIN_SESSION_SECRET` | Session cookie signing |
| `ADMIN_SESSION_MAX_AGE` | Seconds (default `28800`) |
| `ADMIN_SESSION_HTTPS_ONLY` | `true` / `false` |
| `SUBSCRIPTION_REQUIRED` | Require Bearer on `/generate-course` |
| `SUBSCRIPTION_KEY_DEFAULT_VALIDITY_DAYS` | Default key lifetime |
| `BOOKCOURSE_MAX_PART_MB` | Upload part size limit |
| `OPENAI_BASE_URL` | LLM base URL |
| `OPENAI_API_KEY` | LLM API key |
| `OPENAI_MODEL` | Model name |

## Admin console

Set `CURRICULUMPILOT_ADMIN_PASSWORD`, open `/admin/login`, create keys, and copy each new key when shown (once). Active keys can be revoked or have expiry updated; expired/revoked rows can be deleted. Plaintext keys are not stored—only hashes in `data/subscriptions.sqlite`.

## Moodle plugin

Copy `local/bookcourse` to `<moodle>/local/bookcourse/`, then run Moodle notifications/upgrade.

Site settings (**Plugins → Local plugins → CurriculumPilot**):

- **Service URL** — API base, e.g. `http://localhost:8000`
- **Organisation access key** — key from `/admin/` (needed if `SUBSCRIPTION_REQUIRED=true`)

Teachers need capability `local/bookcourse:generate`. Course menu: **Create lessons from a book** and **Import status**.

Plugin table `local_bookcourse_gen` tracks each run. Uploads sit in Moodle `files` (`component=local_bookcourse`, `filearea=toc|book`). Lessons and questions use Moodle core tables (`lesson`, `lesson_pages`, `lesson_answers`, question bank).

## HTTP API

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/health` | No auth |
| `POST` | `/generate-course` | Multipart `toc_file`, `book_file`; Bearer if required |
| | `/admin/…` | Session cookie after login |

Identical uploads may hit the content-hash cache under `cache/`.
