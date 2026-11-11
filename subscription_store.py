"""SQLite-backed API keys for subscription-style access (Bearer tokens)."""

from __future__ import annotations

import hashlib
import os
import secrets
import sqlite3
import time
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator, Optional

def _env_positive_int(name: str, default: int) -> int:
    raw = os.environ.get(name, "").strip()
    if not raw:
        return default
    try:
        v = int(raw)
    except ValueError:
        return default
    return max(1, v)


DEFAULT_KEY_VALIDITY_DAYS = _env_positive_int(
    "SUBSCRIPTION_KEY_DEFAULT_VALIDITY_DAYS", 365
)
_DEFAULT_VALIDITY_SECONDS = DEFAULT_KEY_VALIDITY_DAYS * 86400


def _db_path() -> Path:
    base = Path(__file__).resolve().parent / "data"
    base.mkdir(parents=True, exist_ok=True)
    return base / "subscriptions.sqlite"


def _hash_secret(raw: str) -> str:
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


@contextmanager
def _connect() -> Iterator[sqlite3.Connection]:
    conn = sqlite3.connect(_db_path(), check_same_thread=False)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def _table_columns(conn: sqlite3.Connection, table: str) -> set[str]:
    return {r[1] for r in conn.execute(f"PRAGMA table_info({table})").fetchall()}


def init_db() -> None:
    with _connect() as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS subscription_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                token_prefix TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                created_at INTEGER NOT NULL,
                revoked_at INTEGER,
                expires_at INTEGER
            )
            """
        )
        cols = _table_columns(conn, "subscription_keys")
        if "description" not in cols:
            conn.execute(
                "ALTER TABLE subscription_keys ADD COLUMN description TEXT NOT NULL DEFAULT ''"
            )
        if "expires_at" not in cols:
            conn.execute("ALTER TABLE subscription_keys ADD COLUMN expires_at INTEGER")
            conn.execute(
                "UPDATE subscription_keys SET expires_at = created_at + ? "
                "WHERE expires_at IS NULL",
                (_DEFAULT_VALIDITY_SECONDS,),
            )
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_subscription_keys_revoked "
            "ON subscription_keys (revoked_at)"
        )
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_subscription_keys_expires "
            "ON subscription_keys (expires_at)"
        )


def create_key(
    label: str = "",
    description: str = "",
    expires_at: Optional[int] = None,
) -> tuple[str, int]:
    """
    Create a new API key. Returns (plaintext_key, row_id).
    Plaintext is shown only once; only SHA-256 hash is stored.
    If expires_at is None, default is created_at + DEFAULT_KEY_VALIDITY_DAYS.
    """
    raw = "cp_sk_" + secrets.token_urlsafe(32)
    prefix = raw[:18]
    th = _hash_secret(raw)
    now = int(time.time())
    if expires_at is None:
        expires_at = now + _DEFAULT_VALIDITY_SECONDS
    with _connect() as conn:
        cur = conn.execute(
            "INSERT INTO subscription_keys "
            "(label, description, token_prefix, token_hash, created_at, expires_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (label.strip(), description.strip(), prefix, th, now, expires_at),
        )
        return raw, int(cur.lastrowid)


def default_expiry_date_input() -> str:
    """YYYY-MM-DD for a date picker: calendar day at end of the default validity window."""
    d = datetime.now(timezone.utc).date() + timedelta(days=DEFAULT_KEY_VALIDITY_DAYS)
    return d.isoformat()


def expiry_eod_utc_unix_from_date_string(s: str) -> int:
    """YYYY-MM-DD → Unix time at end of that calendar day (23:59:59 UTC)."""
    dt = datetime.strptime(s.strip(), "%Y-%m-%d").replace(tzinfo=timezone.utc)
    eod = dt.replace(hour=23, minute=59, second=59)
    return int(eod.timestamp())


def resolve_expires_at_from_form(expires_on: str) -> int:
    """
    Empty string → now + default validity (same default as create_key).
    Otherwise YYYY-MM-DD → end of that calendar day (23:59:59 UTC).
    """
    s = (expires_on or "").strip()
    if not s:
        return int(time.time()) + _DEFAULT_VALIDITY_SECONDS
    return expiry_eod_utc_unix_from_date_string(s)


def list_keys() -> list[dict]:
    init_db()
    now = int(time.time())
    with _connect() as conn:
        rows = conn.execute(
            "SELECT id, label, description, token_prefix, created_at, revoked_at, expires_at "
            "FROM subscription_keys ORDER BY id DESC"
        ).fetchall()
    out = []
    for r in rows:
        d = dict(r)
        revoked_raw = d.get("revoked_at")
        exp = d.get("expires_at")
        exp_i = int(exp) if exp is not None else None
        is_revoked = revoked_raw is not None
        d["is_expired"] = bool(
            not is_revoked and exp_i is not None and exp_i < now
        )

        d["created_at"] = datetime.fromtimestamp(
            int(d["created_at"]), tz=timezone.utc
        ).strftime("%Y-%m-%d %H:%M UTC")
        if revoked_raw is not None:
            d["revoked_at"] = datetime.fromtimestamp(
                int(revoked_raw), tz=timezone.utc
            ).strftime("%Y-%m-%d %H:%M UTC")
        if exp_i is not None:
            d["expires_at"] = datetime.fromtimestamp(exp_i, tz=timezone.utc).strftime(
                "%Y-%m-%d %H:%M UTC"
            )
            d["expires_at_eod_date"] = datetime.fromtimestamp(
                exp_i, tz=timezone.utc
            ).date().isoformat()
        else:
            d["expires_at"] = "—"
            d["expires_at_eod_date"] = ""
        out.append(d)
    return out


def revoke_key(key_id: int) -> bool:
    init_db()
    now = int(time.time())
    with _connect() as conn:
        cur = conn.execute(
            "UPDATE subscription_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL",
            (now, key_id),
        )
        return cur.rowcount > 0


def set_key_expiry(key_id: int, expires_at: int) -> bool:
    """Update expiry for an existing key (not revoked). Returns True if a row was updated."""
    init_db()
    with _connect() as conn:
        cur = conn.execute(
            "UPDATE subscription_keys SET expires_at = ? "
            "WHERE id = ? AND revoked_at IS NULL",
            (expires_at, key_id),
        )
        return cur.rowcount > 0


def delete_key(key_id: int) -> bool:
    """
    Permanently remove a revoked or expired key. Active keys are not deleted.
    Returns True if a row was removed.
    """
    init_db()
    now = int(time.time())
    with _connect() as conn:
        cur = conn.execute(
            "DELETE FROM subscription_keys WHERE id = ? AND ("
            "revoked_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at < ?)"
            ")",
            (key_id, now),
        )
        return cur.rowcount > 0


def verify_bearer_authorization(authorization: Optional[str]) -> bool:
    """
    Return True if Authorization is 'Bearer <token>' and token matches an active,
    non-revoked, non-expired key. Bearer scheme matching is case-insensitive (RFC 7235).
    """
    if not authorization or not authorization.strip():
        return False
    parts = authorization.strip().split(None, 1)
    if len(parts) != 2 or parts[0].lower() != "bearer":
        return False
    raw = parts[1].strip()
    if not raw:
        return False
    want = _hash_secret(raw)
    now = int(time.time())
    init_db()
    with _connect() as conn:
        row = conn.execute(
            "SELECT 1 FROM subscription_keys WHERE token_hash = ? "
            "AND revoked_at IS NULL "
            "AND (expires_at IS NULL OR expires_at > ?)",
            (want, now),
        ).fetchone()
        return row is not None


def subscription_required() -> bool:
    return os.environ.get("SUBSCRIPTION_REQUIRED", "").strip().lower() in (
        "1",
        "true",
        "yes",
        "on",
    )


def _month_starts_last_n_utc(n: int = 12) -> list[tuple[int, int]]:
    """Oldest → newest: (year, month) for the last n calendar months including current."""
    cur = datetime.now(timezone.utc).replace(
        day=1, hour=0, minute=0, second=0, microsecond=0
    )
    buckets: list[tuple[int, int]] = []
    for _ in range(n):
        buckets.append((cur.year, cur.month))
        if cur.month == 1:
            cur = cur.replace(year=cur.year - 1, month=12)
        else:
            cur = cur.replace(month=cur.month - 1)
    buckets.reverse()
    return buckets


def _month_range_unix(y: int, m: int) -> tuple[int, int]:
    start = int(datetime(y, m, 1, tzinfo=timezone.utc).timestamp())
    if m == 12:
        end = int(datetime(y + 1, 1, 1, tzinfo=timezone.utc).timestamp()) - 1
    else:
        end = int(datetime(y, m + 1, 1, tzinfo=timezone.utc).timestamp()) - 1
    return start, end


def _month_starts_next_n_utc(n: int = 12) -> list[tuple[int, int]]:
    """Starting at current UTC calendar month: (year, month) for n consecutive months."""
    cur = datetime.now(timezone.utc).replace(
        day=1, hour=0, minute=0, second=0, microsecond=0
    )
    buckets: list[tuple[int, int]] = []
    for _ in range(n):
        buckets.append((cur.year, cur.month))
        if cur.month == 12:
            cur = cur.replace(year=cur.year + 1, month=1)
        else:
            cur = cur.replace(month=cur.month + 1)
    return buckets


def admin_dashboard_chart_data() -> dict[str, Any]:
    """
    Aggregates for the operator dashboard charts (no secrets).
    """
    init_db()
    now = int(time.time())
    with _connect() as conn:
        rows = conn.execute(
            "SELECT revoked_at, expires_at, created_at FROM subscription_keys"
        ).fetchall()

    revoked_n = expired_n = active_n = 0
    for r in rows:
        rev = r["revoked_at"]
        exp = r["expires_at"]
        exp_i = int(exp) if exp is not None else None
        if rev is not None:
            revoked_n += 1
        elif exp_i is not None and exp_i < now:
            expired_n += 1
        else:
            active_n += 1

    months_past = _month_starts_last_n_utc(12)
    created_labels: list[str] = []
    created_counts: list[int] = []
    with _connect() as conn:
        for y, m in months_past:
            created_labels.append(f"{y:04d}-{m:02d}")
            lo, hi = _month_range_unix(y, m)
            c = conn.execute(
                "SELECT COUNT(*) FROM subscription_keys "
                "WHERE created_at >= ? AND created_at <= ?",
                (lo, hi),
            ).fetchone()
            created_counts.append(int(c[0]))

    months_future = _month_starts_next_n_utc(12)
    expiring_labels: list[str] = []
    expiring_counts: list[int] = []
    with _connect() as conn:
        for y, m in months_future:
            expiring_labels.append(f"{y:04d}-{m:02d}")
            lo, hi = _month_range_unix(y, m)
            e = conn.execute(
                "SELECT COUNT(*) FROM subscription_keys "
                "WHERE revoked_at IS NULL AND expires_at IS NOT NULL "
                "AND expires_at >= ? AND expires_at <= ?",
                (lo, hi),
            ).fetchone()
            expiring_counts.append(int(e[0]))

    return {
        "status": {
            "labels": ["Active", "Expired", "Revoked"],
            "counts": [active_n, expired_n, revoked_n],
        },
        "created_by_month": {
            "labels": created_labels,
            "counts": created_counts,
        },
        "expiring_by_month": {
            "labels": expiring_labels,
            "counts": expiring_counts,
        },
        "totals": {
            "keys": len(rows),
            "active": active_n,
            "expired": expired_n,
            "revoked": revoked_n,
        },
    }
