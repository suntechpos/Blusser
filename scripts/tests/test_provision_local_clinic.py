import contextlib
import getpass
import io
import json
from pathlib import Path
import runpy
import secrets
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
import unittest
from unittest.mock import patch

script = runpy.run_path(str(Path(__file__).resolve().parents[1] / "provision-local-clinic"))
Api, SetupError = script["Api"], script["SetupError"]


class ProvisioningTransportTest(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.secret = secrets.token_urlsafe(24)
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                owner.calls.append((self.path, self.headers.get("X-XSRF-TOKEN")))
                if self.path == "/sanctum/csrf-cookie":
                    self.send_response(204)
                    self.send_header("Set-Cookie", "XSRF-TOKEN=first%2Btoken; Path=/")
                    self.end_headers()
                else:
                    self.send_response(200)
                    self.end_headers()
                    self.wfile.write(b'{}')

            def do_POST(self):
                self.rfile.read(int(self.headers.get("Content-Length", "0")))
                owner.calls.append((self.path, self.headers.get("X-XSRF-TOKEN")))
                if self.path == "/redirect":
                    self.send_response(307)
                    self.send_header("Location", "/stolen")
                    self.end_headers()
                    return
                if self.path == "/failure":
                    self.send_response(422)
                    self.end_headers()
                    self.wfile.write(json.dumps({"message": owner.secret}).encode())
                    return
                self.send_response(200)
                self.send_header("Set-Cookie", "XSRF-TOKEN=second%2Btoken; Path=/")
                self.end_headers()
                self.wfile.write(b'{"must_change_password":true}')

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.thread.join)
        self.addCleanup(self.server.shutdown)
        self.api = Api(f"http://127.0.0.1:{self.server.server_port}", "http://127.0.0.1:5173")

    def test_rotated_csrf_cookies_are_decoded_and_used_without_disk_storage(self):
        self.api.login("local.operator", self.secret)
        self.api.request("POST", "/follow", {})
        self.assertEqual(self.calls[-2:], [("/api/v1/auth/login", "first+token"), ("/follow", "second+token")])
        self.assertNotIn("FileCookieJar", type(self.api.cookies).__name__)

    def test_reflected_secret_is_not_reported_and_write_is_not_retried(self):
        self.api.request("GET", "/sanctum/csrf-cookie", expected=204)
        output = io.StringIO()
        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
            with self.assertRaises(SetupError) as error:
                self.api.request("POST", "/failure", {"password": self.secret})
        self.assertNotIn(self.secret, str(error.exception) + output.getvalue())
        self.assertIn("422", str(error.exception))
        self.assertEqual(sum(path == "/failure" for path, _ in self.calls), 1)

    def test_redirect_cannot_forward_credential_payload(self):
        self.api.request("GET", "/sanctum/csrf-cookie", expected=204)
        with self.assertRaises(SetupError):
            self.api.request("POST", "/redirect", {"password": self.secret})
        self.assertFalse(any(path == "/stolen" for path, _ in self.calls))


class ProvisioningInputTest(unittest.TestCase):
    def test_nonlocal_and_credential_urls_are_rejected(self):
        for url in ["https://example.com", "http://localhost@example.com", "http://name@localhost",
                    "http://localhost/path", "http://localhost/?x=1", "ftp://localhost", "http://localhost:0"]:
            with self.subTest(url=url), self.assertRaises(SetupError):
                script["local_url"](url)
        with self.assertRaises(SetupError):
            Api("http://127.0.0.1:8000", "http://localhost:5173")

    def test_unknown_secret_arguments_are_not_echoed(self):
        value = secrets.token_urlsafe(24)
        output = io.StringIO()
        with contextlib.redirect_stderr(output):
            result = script["main"](["--password", value])
        self.assertEqual(result, 2)
        self.assertNotIn(value, output.getvalue())

    def test_hidden_password_failure_never_falls_back_to_visible_input(self):
        with patch.object(getpass, "getpass", side_effect=getpass.GetPassWarning()):
            with self.assertRaises(SetupError):
                script["password"]("Parol")


if __name__ == "__main__":
    unittest.main()
