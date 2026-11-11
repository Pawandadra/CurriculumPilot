from pathlib import Path

try:
    from dotenv import load_dotenv

    load_dotenv(Path(__file__).resolve().parent / ".env", override=False)
except ImportError:
    pass

import hashlib
import json
import logging
import os
import secrets
import tempfile
from collections.abc import Callable

from fastapi import Depends, FastAPI, File, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse
from fastapi.routing import APIRoute
from starlette.middleware.sessions import SessionMiddleware
from starlette.requests import Request as StarletteRequest
from starlette.responses import Response

import admin_portal
import subscription_store
from chapter_split_fuzzy import split_chapters_fuzzy
from text_extraction import industrial_pdf_extractor
from toc_cleaning import extract_simple_titles
from topic_generation import generate_strict_course

_LOG = logging.getLogger(__name__)


def _env_positive_int(name: str, default: int) -> int:
    raw = os.environ.get(name, "").strip()
    if not raw:
        return default
    try:
        v = int(raw)
    except ValueError:
        return default
    return max(1, v)


# Starlette defaults multipart parts to 1 MiB; book PDFs need a higher cap.
_MAX_PART_MB = _env_positive_int("BOOKCOURSE_MAX_PART_MB", 512)
_MAX_PART_BYTES = _MAX_PART_MB * 1024 * 1024


class LargeMultipartRequest(StarletteRequest):
    def form(
        self,
        *,
        max_files: int | float = 1000,
        max_fields: int | float = 1000,
        max_part_size: int = _MAX_PART_BYTES,
    ):
        return super().form(
            max_files=max_files, max_fields=max_fields, max_part_size=max_part_size
        )


class LargeMultipartRoute(APIRoute):
    def get_route_handler(self) -> Callable:
        original = super().get_route_handler()

        async def handler(request: StarletteRequest) -> Response:
            return await original(
                LargeMultipartRequest(request.scope, request.receive)
            )

        return handler


_session_secret = os.environ.get("ADMIN_SESSION_SECRET", "").strip()
if not _session_secret:
    _session_secret = secrets.token_hex(32)

app = FastAPI(title="CurriculumPilot API")
app.router.route_class = LargeMultipartRoute
app.add_middleware(
    SessionMiddleware,
    secret_key=_session_secret,
    max_age=_env_positive_int("ADMIN_SESSION_MAX_AGE", 28800),
    same_site="lax",
    https_only=os.environ.get("ADMIN_SESSION_HTTPS_ONLY", "").lower()
    in ("1", "true", "yes"),
)
app.include_router(admin_portal.router)


@app.on_event("startup")
def _subscription_startup() -> None:
    subscription_store.init_db()


async def require_subscription_key(request: Request) -> None:
    if not subscription_store.subscription_required():
        return
    if not subscription_store.verify_bearer_authorization(
        request.headers.get("Authorization")
    ):
        raise HTTPException(
            status_code=401,
            detail=(
                "Missing or invalid subscription API key. "
                "Send header: Authorization: Bearer <your-key> "
                "(create keys under /admin/ when enabled)."
            ),
        )


@app.post("/generate-course", dependencies=[Depends(require_subscription_key)])
async def generate_course(
    toc_file: UploadFile = File(..., description="TOC file (PDF or image)"),
    book_file: UploadFile = File(..., description="Book PDF"),
) -> JSONResponse:
    """Run pipeline for TOC + book and return chapter/topic/question JSON."""
    try:
        toc_bytes = await toc_file.read()
        book_bytes = await book_file.read()

        hasher = hashlib.sha256()
        hasher.update(toc_bytes)
        hasher.update(book_bytes)
        cache_key = hasher.hexdigest()

        cache_dir = os.path.join(os.path.dirname(__file__), "cache")
        os.makedirs(cache_dir, exist_ok=True)
        cache_path = os.path.join(cache_dir, f"{cache_key}.json")

        if os.path.exists(cache_path):
            with open(cache_path, "r", encoding="utf-8") as f:
                cached_data = json.load(f)
            return JSONResponse(content=cached_data)

        with tempfile.TemporaryDirectory() as tmpdir:
            toc_input_path = os.path.join(tmpdir, toc_file.filename or "toc_input.pdf")
            book_input_path = os.path.join(tmpdir, book_file.filename or "book_input.pdf")

            with open(toc_input_path, "wb") as f_toc:
                f_toc.write(toc_bytes)

            with open(book_input_path, "wb") as f_book:
                f_book.write(book_bytes)

            toc_txt_path = os.path.join(tmpdir, "toc_text.txt")
            book_txt_path = os.path.join(tmpdir, "book_text.txt")

            industrial_pdf_extractor(toc_input_path, toc_txt_path)
            industrial_pdf_extractor(book_input_path, book_txt_path)

            toc_json_path = os.path.join(tmpdir, "toc_clean.json")
            extract_simple_titles(toc_txt_path, toc_json_path)

            chapters_json_path = os.path.join(tmpdir, "chapters.json")
            split_chapters_fuzzy(toc_json_path, book_txt_path, chapters_json_path)

            final_course_json_path = os.path.join(tmpdir, "final_course.json")
            course_data: list[dict] = generate_strict_course(
                chapters_json_path, final_course_json_path
            )

            with open(cache_path, "w", encoding="utf-8") as f:
                json.dump(course_data, f, indent=4)

            return JSONResponse(content=course_data)

    except Exception as exc:
        _LOG.exception("generate-course failed")
        raise HTTPException(
            status_code=500,
            detail="Course generation failed. See server logs for details.",
        ) from exc


@app.get("/health")
async def health() -> dict:
    return {"status": "ok"}
