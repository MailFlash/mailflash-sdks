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

from typing import Any, TypedDict, NotRequired, Union

import requests


Recipient = Union[str, dict[str, str]]


class Attachment(TypedDict):
    filename: str
    content: str
    content_type: NotRequired[str]


class SendResult(TypedDict):
    status: int
    body: Any


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
        url = f"{self.base_url}/api/v1/email/send"
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-API-Key": self.api_key,
        }
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key

        try:
            response = self._session.post(
                url,
                json=normalize_payload(payload),
                headers=headers,
                timeout=self.timeout_seconds,
            )
        except requests.RequestException as exc:
            return {"status": 0, "body": {"error": "transport", "message": str(exc)}}

        try:
            body: Any = response.json()
        except ValueError:
            body = response.text

        return {"status": response.status_code, "body": body}

    @staticmethod
    def accepted(result: SendResult) -> bool:
        return result.get("status") == 202

    def raise_for_status(self, result: SendResult) -> SendResult:
        if self.accepted(result):
            return result
        status = result.get("status", 0)
        body = result.get("body", "")
        raise RuntimeError(f"MailFlash request failed ({status}): {body}")
