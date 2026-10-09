"""
MailFlash API client for Python 3.10+.

Requires: pip install requests

Usage:
    Copy this file into your project as mailflash_client.py, then:

    from mailflash_client import MailFlashClient

    client = MailFlashClient("https://mailflash.es", api_key=os.environ["MAILFLASH_API_KEY"])
    result = client.send(
        {
            "from": "hello@yourdomain.com",
            "to": ["you@example.com"],
            "subject": "Order confirmed",
            "html": "<p>Thanks!</p>",
        },
        idempotency_key="order-123",
    )
    if client.accepted(result):
        print(result["body"])
"""

from __future__ import annotations

from typing import Any, TypedDict, Union
from urllib.parse import quote

import requests


Recipient = Union[str, dict[str, str]]


class _AttachmentRequired(TypedDict):
    filename: str
    content: str


class Attachment(_AttachmentRequired, total=False):
    # Optional keys live here: typing.NotRequired is Python 3.11+.
    content_type: str
    # Inline images: content_id like "logo@example.com" (must contain @), referenced as cid:logo@example.com.
    content_id: str
    # "attachment" or "inline"; defaults to "inline" when content_id is set.
    disposition: str


class SendResult(TypedDict):
    status: int
    body: Any


# Every method returns the same {status, body} shape.
ApiResult = SendResult


class MailFlashError(RuntimeError):
    """Raised by raise_for_status(); carries the failed result."""

    def __init__(self, result: ApiResult) -> None:
        self.status: int = result.get("status", 0)
        self.body: Any = result.get("body", "")
        super().__init__(f"MailFlash request failed ({self.status}): {self.body}")


def normalize_recipients(recipients: list[Recipient]) -> list[dict[str, str]]:
    out: list[dict[str, str]] = []
    for recipient in recipients:
        if isinstance(recipient, str):
            out.append({"email": recipient})
        else:
            out.append({k: v for k, v in recipient.items() if v})
    return out


def normalize_payload(payload: dict[str, Any]) -> dict[str, Any]:
    body = dict(payload)
    for field in ("to", "cc", "bcc"):
        if field in body and body[field]:
            body[field] = normalize_recipients(body[field])
    return {k: v for k, v in body.items() if v is not None and v != []}


def _query(params: dict[str, Any]) -> dict[str, Any]:
    return {k: v for k, v in params.items() if v is not None and v != ""}


class MailFlashClient:
    def __init__(
        self,
        base_url: str,
        api_key: str,
        timeout_seconds: float = 30.0,
        session: requests.Session | None = None,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.timeout_seconds = timeout_seconds
        self._session = session or requests.Session()

    def send(
        self,
        payload: dict[str, Any],
        *,
        idempotency_key: str | None = None,
    ) -> SendResult:
        headers = {"Content-Type": "application/json"}
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key

        return self._request("POST", "/email/send", json=normalize_payload(payload), headers=headers)

    def get_stats(self, *, date_from: str | None = None, date_to: str | None = None) -> ApiResult:
        return self._request("GET", "/stats", params=_query({"date_from": date_from, "date_to": date_to}))

    def list_domains(self, *, verified_only: bool = False) -> ApiResult:
        return self._request("GET", "/domains", params=_query({"verified_only": "1" if verified_only else None}))

    def list_contacts(
        self,
        *,
        q: str | None = None,
        status: str | None = None,
        page: int | None = None,
    ) -> ApiResult:
        return self._request("GET", "/contacts", params=_query({"q": q, "status": status, "page": page}))

    def list_emails(
        self,
        *,
        status: str | None = None,
        tag: str | None = None,
        to: str | None = None,
        from_: str | None = None,
        date_from: str | None = None,
        date_to: str | None = None,
        page: int | None = None,
    ) -> ApiResult:
        params = _query({
            "status": status,
            "tag": tag,
            "to": to,
            "from": from_,
            "date_from": date_from,
            "date_to": date_to,
            "page": page,
        })
        return self._request("GET", "/emails", params=params)

    def get_email(self, email_id: str, *, include_body: bool = False) -> ApiResult:
        params = {"include": "body"} if include_body else None
        return self._request("GET", f"/emails/{quote(email_id, safe='')}", params=params)

    def get_email_events(self, email_id: str) -> ApiResult:
        return self._request("GET", f"/emails/{quote(email_id, safe='')}/events")

    @staticmethod
    def accepted(result: SendResult) -> bool:
        return result.get("status") == 202

    @staticmethod
    def ok(result: ApiResult) -> bool:
        return 200 <= result.get("status", 0) < 300

    def raise_for_status(self, result: SendResult) -> SendResult:
        """Return result if accepted (202), otherwise raise MailFlashError."""
        if self.accepted(result):
            return result
        raise MailFlashError(result)

    def _request(
        self,
        method: str,
        path: str,
        *,
        params: dict[str, Any] | None = None,
        json: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> ApiResult:
        all_headers = {
            "Accept": "application/json",
            "X-API-Key": self.api_key,
            **(headers or {}),
        }

        try:
            response = self._session.request(
                method,
                f"{self.base_url}/api/v1{path}",
                params=params or None,
                json=json,
                headers=all_headers,
                timeout=self.timeout_seconds,
            )
        except requests.RequestException as exc:
            return {"status": 0, "body": {"error": "transport", "message": str(exc)}}

        try:
            body: Any = response.json()
        except ValueError:
            body = response.text

        return {"status": response.status_code, "body": body}
