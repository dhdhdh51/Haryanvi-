#!/usr/bin/env python3
"""
Mock AI provider for integration testing.

Speaks the OpenAI chat-completions protocol over HTTPS so the real
OpenAiCompatibleProvider — real cURL, real TLS, real JSON parsing, real retry
and fallback logic — can be exercised without spending money on a live API.

This is a TEST FIXTURE. It is never used by the application at runtime: the
production code has no mock driver and refuses to invent translations when no
provider is configured.

Usage:
    python3 mock-ai-server.py --port 8443 --cert-dir /tmp/mockai

Failure injection is driven by the requested model name, so a test can point a
provider row at the behaviour it needs:

    mock-ok           normal successful translation
    mock-401          invalid credentials  (permanent -> triggers fallback)
    mock-500          server error         (retryable -> triggers retry)
    mock-429          rate limited         (retryable)
    mock-malformed    HTTP 200 with a non-JSON body
    mock-empty        HTTP 200 with an empty translation
    mock-plaintext    HTTP 200 with a bare string instead of the JSON contract
    mock-slow         delays ~3s, for timeout testing
    mock-refusal      model declines to translate
"""

import argparse
import json
import os
import re
import ssl
import subprocess
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# Canned Haryanvi output. Deliberately fixed strings — this fixture asserts
# plumbing, not translation quality.
CANNED = {
    "default": ("के हाल सै भाई", "ke haal sai bhai"),
    "greeting": ("राम राम भाई, के हाल सै", "raam raam bhai, ke haal sai"),
    "coming": ("मैं आ रह्या सूं", "main aa rahya soon"),
    "unknown": ("मन्ने बेरा ना", "manne bera na"),
}

PLACEHOLDER_RE = re.compile(r"\{\{\d+\}\}")
MESSAGE_RE = re.compile(r"<<<MESSAGE_START>>>\n(.*?)\n<<<MESSAGE_END>>>", re.DOTALL)

REQUEST_LOG = []


def pick_translation(user_prompt: str):
    """Choose a canned reply and carry over any protected placeholders."""
    match = MESSAGE_RE.search(user_prompt)
    message = match.group(1) if match else user_prompt

    lowered = message.lower()
    if "नमस्ते" in message or "hello" in lowered or "कैसे हो" in message:
        key = "greeting"
    elif "आ रहा" in message or "coming" in lowered:
        key = "coming"
    elif "पता" in message or "know" in lowered:
        key = "unknown"
    else:
        key = "default"

    devanagari, roman = CANNED[key]

    # Echo placeholders back so placeholder restoration can be verified.
    placeholders = PLACEHOLDER_RE.findall(message)
    if placeholders:
        suffix = " " + " ".join(placeholders)
        devanagari += suffix
        roman += suffix

    return devanagari, roman


class MockAiHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "MockAI/1.0"

    def log_message(self, fmt, *args):  # noqa: A003 - silence default stderr spam
        pass

    def _send_json(self, status: int, payload: dict):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _send_raw(self, status: int, body: str, content_type="text/plain; charset=utf-8"):
        encoded = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):  # noqa: N802
        if self.path == "/__requests":
            self._send_json(200, {"count": len(REQUEST_LOG), "requests": REQUEST_LOG[-20:]})
            return
        if self.path == "/__reset":
            REQUEST_LOG.clear()
            self._send_json(200, {"reset": True})
            return
        self._send_json(404, {"error": {"message": "not found"}})

    def do_POST(self):  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length).decode("utf-8") if length else "{}"

        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            self._send_json(400, {"error": {"message": "invalid JSON in request"}})
            return

        model = str(payload.get("model", "mock-ok"))
        auth = self.headers.get("Authorization", "")

        REQUEST_LOG.append({
            "path": self.path,
            "model": model,
            "has_auth": auth.startswith("Bearer ") and len(auth) > 10,
            "temperature": payload.get("temperature"),
            "max_tokens": payload.get("max_tokens"),
            "at": time.time(),
        })

        # A real provider rejects a missing key; mirror that so the test suite
        # can prove the key is actually being sent.
        if not auth.startswith("Bearer ") or len(auth) <= len("Bearer "):
            self._send_json(401, {"error": {"message": "missing bearer token"}})
            return

        if model == "mock-401":
            self._send_json(401, {"error": {"message": "invalid api key"}})
            return
        if model == "mock-500":
            self._send_json(500, {"error": {"message": "internal error"}})
            return
        if model == "mock-429":
            self._send_json(429, {"error": {"message": "rate limit exceeded"}})
            return
        if model == "mock-malformed":
            self._send_raw(200, "this is not json at all")
            return
        if model == "mock-slow":
            time.sleep(3.5)

        messages = payload.get("messages") or []
        system_prompt = ""
        user_prompt = ""
        for message in messages:
            if message.get("role") == "system":
                system_prompt = str(message.get("content", ""))
            elif message.get("role") == "user":
                user_prompt = str(message.get("content", ""))

        devanagari, roman = pick_translation(user_prompt)

        if model == "mock-empty":
            content = json.dumps({"haryanvi": "", "roman": None}, ensure_ascii=False)
        elif model == "mock-plaintext":
            content = devanagari
        elif model == "mock-refusal":
            content = "I'm sorry, I cannot help with that request."
        else:
            content = json.dumps(
                {
                    "haryanvi": devanagari,
                    "roman": roman,
                    "detected_language": "hi" if re.search(r"[\u0900-\u097F]", user_prompt) else "en",
                    "notes": None,
                },
                ensure_ascii=False,
            )

        self._send_json(200, {
            "id": "mock-" + str(int(time.time() * 1000)),
            "object": "chat.completion",
            "model": model,
            "choices": [{
                "index": 0,
                "message": {"role": "assistant", "content": content},
                "finish_reason": "stop",
            }],
            "usage": {
                "prompt_tokens": max(1, len(system_prompt) // 4),
                "completion_tokens": max(1, len(content) // 4),
                "total_tokens": max(2, (len(system_prompt) + len(content)) // 4),
            },
        })


def ensure_certificate(cert_dir: str):
    """
    Return the certificate/key pair for 127.0.0.1, generating it if needed.

    Tries the openssl binary first, then falls back to generate-cert.php,
    which uses the openssl PHP extension the project already requires. That
    fallback matters on minimal images that ship PHP but no openssl CLI.
    """
    os.makedirs(cert_dir, exist_ok=True)
    cert = os.path.join(cert_dir, "mock-ai.crt")
    key = os.path.join(cert_dir, "mock-ai.key")

    if os.path.isfile(cert) and os.path.isfile(key):
        return cert, key

    try:
        subprocess.run(
            [
                "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                "-keyout", key, "-out", cert, "-days", "30",
                "-subj", "/CN=127.0.0.1",
                "-addext", "subjectAltName=IP:127.0.0.1,DNS:localhost",
            ],
            check=True,
            capture_output=True,
        )
        return cert, key
    except (FileNotFoundError, subprocess.CalledProcessError):
        pass

    helper = os.path.join(os.path.dirname(os.path.abspath(__file__)), "generate-cert.php")
    php = os.environ.get("PHP_BINARY") or "php"

    try:
        subprocess.run([php, helper, cert_dir], check=True, capture_output=True)
    except (FileNotFoundError, subprocess.CalledProcessError) as exc:
        detail = getattr(exc, "stderr", b"") or b""
        raise SystemExit(
            "Could not create a TLS certificate for the mock server.\n"
            "Install the openssl CLI, or generate one manually with:\n"
            f"  php {helper} {cert_dir}\n"
            f"{detail.decode('utf-8', 'replace')}"
        ) from exc

    if not (os.path.isfile(cert) and os.path.isfile(key)):
        raise SystemExit("Certificate generation reported success but produced no files.")

    return cert, key


def main():
    parser = argparse.ArgumentParser(description="Mock OpenAI-compatible AI provider (test fixture)")
    parser.add_argument("--port", type=int, default=8443)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--cert-dir", default="/tmp/haryanvi-mock-ai")
    args = parser.parse_args()

    cert, key = ensure_certificate(args.cert_dir)

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certfile=cert, keyfile=key)

    server = ThreadingHTTPServer((args.host, args.port), MockAiHandler)
    server.socket = context.wrap_socket(server.socket, server_side=True)

    print(f"mock-ai listening on https://{args.host}:{args.port}  (CA: {cert})", flush=True)

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    sys.exit(main())
