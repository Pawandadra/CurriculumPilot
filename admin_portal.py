"""
CurriculumPilot admin console: issue and revoke subscription API keys.
Not part of Moodle — served by the same FastAPI process under /admin/.

Set CURRICULUMPILOT_ADMIN_PASSWORD (or ADMIN_PASSWORD) to enable login.
Optional: ADMIN_SECURITY_QUESTION + ADMIN_SECURITY_ANSWER (both required together).
Math CAPTCHA is always enforced when login is enabled (session-backed).
Set ADMIN_SESSION_SECRET for stable sessions across restarts (recommended in production).
"""

from __future__ import annotations

import hashlib
import json
import os
import secrets
from typing import Any, Optional

from fastapi import APIRouter, Form, HTTPException, Request, status
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates

import subscription_store

router = APIRouter(prefix="/admin", tags=["admin"])

_templates_dir = os.path.join(os.path.dirname(__file__), "templates")
templates = Jinja2Templates(directory=_templates_dir)

_SESSION_CAPTCHA_SUM = "admin_login_captcha_sum"


def _admin_password() -> Optional[str]:
    return (
        os.environ.get("CURRICULUMPILOT_ADMIN_PASSWORD", "").strip()
        or os.environ.get("ADMIN_PASSWORD", "").strip()
        or None
    )


def _admin_enabled() -> bool:
    return _admin_password() is not None


def _security_qa_from_env() -> tuple[Optional[str], Optional[str]]:
    q = os.environ.get("ADMIN_SECURITY_QUESTION", "").strip()
    a = os.environ.get("ADMIN_SECURITY_ANSWER", "").strip()
    if not q or not a:
        return None, None
    return q, a


def _security_answer_matches(user_answer: str, expected_answer: str) -> bool:
    nu = hashlib.sha256(user_answer.strip().casefold().encode("utf-8")).digest()
    ne = hashlib.sha256(expected_answer.strip().casefold().encode("utf-8")).digest()
    return secrets.compare_digest(nu, ne)


def _refresh_login_captcha(request: Request) -> str:
    """Store sum in session; return human-readable expression (no answer)."""
    a = secrets.randbelow(10) + 1
    b = secrets.randbelow(10) + 1
    request.session[_SESSION_CAPTCHA_SUM] = a + b
    return f"{a} + {b}"


def _login_form_context(request: Request, error: Optional[str]) -> dict[str, Any]:
    expr = _refresh_login_captcha(request)
    sq, _ans = _security_qa_from_env()
    return {
        "request": request,
        "error": error,
        "captcha_expression": expr,
        "security_question_enabled": sq is not None,
        "security_question_text": sq or "",
    }


def _verify_login_captcha(request: Request, captcha_answer: str) -> bool:
    expected = request.session.pop(_SESSION_CAPTCHA_SUM, None)
    if expected is None:
        return False
    try:
        got = int(str(captcha_answer).strip())
    except ValueError:
        return False
    return got == int(expected)


@router.get("/login.html", response_model=None)
async def admin_login_html_alias() -> RedirectResponse:
    """Some users open /admin/login.html; the real route is /admin/login."""
    return RedirectResponse("/admin/login", status_code=301)


@router.get("/login", response_class=HTMLResponse, response_model=None)
async def admin_login_get(request: Request) -> HTMLResponse:
    if not _admin_enabled():
        return HTMLResponse(
            "<h1>Admin console disabled</h1>"
            "<p>Set environment variable <code>CURRICULUMPILOT_ADMIN_PASSWORD</code> "
            "and restart the server.</p>",
            status_code=503,
        )
    if request.session.get("admin_ok"):
        return RedirectResponse("/admin/", status_code=302)
    return templates.TemplateResponse(
        "admin/login.html",
        _login_form_context(request, None),
    )


@router.post("/login", response_class=HTMLResponse, response_model=None)
async def admin_login_post(
    request: Request,
    password: str = Form(...),
    captcha_answer: str = Form(""),
    security_answer: str = Form(""),
) -> RedirectResponse | HTMLResponse:
    if not _admin_enabled():
        raise HTTPException(status_code=503, detail="Admin disabled")
    pwd = _admin_password()
    assert pwd is not None

    if not _verify_login_captcha(request, captcha_answer):
        return templates.TemplateResponse(
            "admin/login.html",
            _login_form_context(request, "Incorrect verification answer (CAPTCHA)."),
            status_code=status.HTTP_401_UNAUTHORIZED,
        )

    sq_text, sq_expected = _security_qa_from_env()
    if sq_text is not None:
        if not _security_answer_matches(security_answer, sq_expected):
            return templates.TemplateResponse(
                "admin/login.html",
                _login_form_context(
                    request, "Incorrect security question answer."
                ),
                status_code=status.HTTP_401_UNAUTHORIZED,
            )

    p_hash = hashlib.sha256(password.encode("utf-8")).digest()
    e_hash = hashlib.sha256(pwd.encode("utf-8")).digest()
    if not secrets.compare_digest(p_hash, e_hash):
        return templates.TemplateResponse(
            "admin/login.html",
            _login_form_context(request, "Incorrect password."),
            status_code=status.HTTP_401_UNAUTHORIZED,
        )
    request.session["admin_ok"] = True
    return RedirectResponse("/admin/", status_code=302)


@router.get("/logout", response_model=None)
async def admin_logout(request: Request) -> RedirectResponse:
    request.session.clear()
    return RedirectResponse("/admin/login", status_code=302)


@router.get("/", response_class=HTMLResponse, response_model=None)
async def admin_dashboard(request: Request) -> HTMLResponse | RedirectResponse:
    if not _admin_enabled():
        return HTMLResponse(
            "<h1>Admin console disabled</h1><p>Set CURRICULUMPILOT_ADMIN_PASSWORD.</p>",
            status_code=503,
        )
    if not request.session.get("admin_ok"):
        return RedirectResponse("/admin/login", status_code=302)
    subscription_store.init_db()
    keys = subscription_store.list_keys()
    flash_key = request.session.pop("flash_new_key", None)
    chart_data = subscription_store.admin_dashboard_chart_data()
    # Safe inside <script type="application/json">: escape '<' so '</script>' cannot break out.
    chart_data_json = json.dumps(chart_data).replace("<", "\\u003c")
    return templates.TemplateResponse(
        "admin/dashboard.html",
        {
            "request": request,
            "keys": keys,
            "flash_new_key": flash_key,
            "default_expiry_date": subscription_store.default_expiry_date_input(),
            "chart_data_json": chart_data_json,
        },
    )


@router.post("/keys/create", response_model=None)
async def admin_create_key(
    request: Request,
    label: str = Form(""),
    description: str = Form(""),
    expires_on: str = Form(""),
) -> RedirectResponse:
    if not _admin_enabled() or not request.session.get("admin_ok"):
        raise HTTPException(status_code=403, detail="Forbidden")
    subscription_store.init_db()
    try:
        exp = subscription_store.resolve_expires_at_from_form(expires_on)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail="Invalid expires_on date.") from exc
    raw, _kid = subscription_store.create_key(
        label=label, description=description, expires_at=exp
    )
    request.session["flash_new_key"] = raw
    return RedirectResponse("/admin/", status_code=303)


@router.post("/keys/{key_id}/revoke", response_model=None)
async def admin_revoke_key(request: Request, key_id: int) -> RedirectResponse:
    if not _admin_enabled() or not request.session.get("admin_ok"):
        raise HTTPException(status_code=403, detail="Forbidden")
    subscription_store.init_db()
    subscription_store.revoke_key(key_id)
    return RedirectResponse("/admin/", status_code=303)


@router.post("/keys/{key_id}/expiry", response_model=None)
async def admin_set_key_expiry(
    request: Request,
    key_id: int,
    expires_on: str = Form(""),
) -> RedirectResponse:
    if not _admin_enabled() or not request.session.get("admin_ok"):
        raise HTTPException(status_code=403, detail="Forbidden")
    if not expires_on.strip():
        raise HTTPException(status_code=400, detail="Expiry date is required.")
    subscription_store.init_db()
    try:
        exp = subscription_store.expiry_eod_utc_unix_from_date_string(expires_on)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail="Invalid date.") from exc
    subscription_store.set_key_expiry(key_id, exp)
    return RedirectResponse("/admin/", status_code=303)


@router.post("/keys/{key_id}/delete", response_model=None)
async def admin_delete_key(request: Request, key_id: int) -> RedirectResponse:
    if not _admin_enabled() or not request.session.get("admin_ok"):
        raise HTTPException(status_code=403, detail="Forbidden")
    subscription_store.init_db()
    if not subscription_store.delete_key(key_id):
        raise HTTPException(
            status_code=400,
            detail="Key not found or cannot be deleted (only expired or revoked keys).",
        )
    return RedirectResponse("/admin/", status_code=303)
