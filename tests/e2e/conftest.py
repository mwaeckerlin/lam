"""Shared settings, helpers and the readiness wait of the LAM stack."""
import base64
import hashlib
import os
import re
import time

import pytest
import requests

LAM_URL = os.environ.get("LAM_URL", "http://lam-proxy:8080")
UNCONFIGURED_URL = os.environ.get("UNCONFIGURED_URL", "http://unconfigured-proxy:8080")
UPGRADE_URL = os.environ.get("UPGRADE_URL", "http://upgrade-proxy:8080")
RECONFIGURE_URL = os.environ.get("RECONFIGURE_URL", "http://reconfigure-proxy:8080")

LAM_PASSWORD = "lam-e2e-password"
LDAP_ADMIN = "cn=admin,dc=example,dc=org"
LDAP_PASSWORD = "ldap-test-password"
OLD_PASSWORD = "password-of-the-old-installation"
NEW_PASSWORD = "password-set-after-the-installation"


def wait_for_http(url: str, timeout: int = 120) -> None:
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        try:
            r = requests.get(url, timeout=5)
            if r.status_code not in (502, 504):
                return
            last = f"HTTP {r.status_code}"
        except requests.RequestException as exc:
            last = exc
        time.sleep(1)
    raise TimeoutError(f"{url} did not answer within {timeout}s: {last}")


def lam_login(url: str, username: str, password: str) -> requests.Response:
    """Logs in through the login page as a browser does and returns the answer to the form."""
    session = requests.Session()
    page = session.get(url + "/templates/login.php", timeout=30)
    assert page.status_code == 200, page.text[:500]
    form = {"username": username, "passwd": password, "checklogin": "Login"}
    language = re.search(r'name="language".*?<option[^>]*value="([^"]+)"[^>]*selected', page.text, re.S)
    if language:
        form["language"] = language.group(1)
    answer = session.post(url + "/templates/login.php", data=form, timeout=30)
    answer.session = session
    return answer


def ssha_matches(password: str, stored: str) -> bool:
    """True where the {SSHA} hash LAM stores belongs to the password."""
    match = re.fullmatch(r"\{SSHA\}(\S+) (\S+)", stored)
    if not match:
        return False
    salt = base64.b64decode(match.group(2))
    return base64.b64decode(match.group(1)) == hashlib.sha1(password.encode() + salt).digest()


def main_login(url: str, password: str) -> requests.Response:
    """Logs in to the main configuration with the master password."""
    session = requests.Session()
    page = session.get(url + "/templates/config/mainlogin.php", timeout=30)
    assert page.status_code == 200, page.text[:500]
    return session.post(url + "/templates/config/mainlogin.php", data={"passwd": password, "submit": "Ok"}, timeout=30)


@pytest.fixture(scope="session", autouse=True)
def wait_for_services():
    for url in (LAM_URL, UNCONFIGURED_URL, UPGRADE_URL, RECONFIGURE_URL):
        wait_for_http(url + "/templates/login.php")
