"""LDAP Account Manager through nginx and php-fpm against a real OpenLDAP server."""
import json
import os

import pytest
import requests

from conftest import (LAM_PASSWORD, LAM_URL, LDAP_ADMIN, LDAP_PASSWORD, NEW_PASSWORD, OLD_PASSWORD,
                      RECONFIGURE_URL, UNCONFIGURED_URL, UPGRADE_URL, lam_login, main_login, ssha_matches)


def test_root_redirects_to_login():
    r = requests.get(LAM_URL + "/", timeout=30)
    assert r.status_code == 200
    assert "<title>LDAP Account Manager</title>" in r.text
    assert "templates/login.php" in r.text


def test_login_page_rendered_by_php():
    r = requests.get(LAM_URL + "/templates/login.php", timeout=30)
    assert r.status_code == 200
    assert "<?php" not in r.text
    assert 'name="passwd"' in r.text
    assert LDAP_ADMIN in r.text


def test_no_missing_php_module_reported():
    r = requests.get(LAM_URL + "/templates/login.php", timeout=30)
    assert "extension is missing" not in r.text.lower()
    assert "not writable" not in r.text.lower()


def test_ldap_login_with_valid_password():
    r = lam_login(LAM_URL, LDAP_ADMIN, LDAP_PASSWORD)
    assert r.status_code == 200
    assert "main.php" in r.text, r.text[:2000]
    page = r.session.get(LAM_URL + "/templates/main.php", timeout=30)
    assert page.status_code == 200
    assert "login.php" not in page.url
    assert "logout" in page.text.lower()


def test_ldap_login_with_wrong_password():
    r = lam_login(LAM_URL, LDAP_ADMIN, "not-the-password")
    assert "URL=./main.php" not in r.text
    assert 'name="passwd"' in r.text


def test_main_configuration_password_from_environment():
    r = main_login(LAM_URL, LAM_PASSWORD)
    assert "mainmanage.php" in r.text, r.text[:2000]


def test_main_configuration_wrong_password():
    r = main_login(LAM_URL, "not-the-password")
    assert "mainmanage.php" not in r.text
    assert "password is invalid" in r.text.lower()


def test_configuration_written_to_the_volumes():
    with open("/lam-config/config.cfg") as f:
        main = json.load(f)
    assert main["password"].startswith("{SSHA}")
    assert LAM_PASSWORD not in json.dumps(main)
    assert main["default"] == "lam"
    with open("/lam-data/config/lam.conf") as f:
        profile = json.load(f)
    assert profile["ServerURL"] == "ldap://openldap:389"
    assert profile["Admins"] == LDAP_ADMIN
    assert profile["defaultLanguage"] == "en_US.utf8"
    assert profile["typeSettings"]["suffix_user"] == "dc=example,dc=org"
    assert profile["typeSettings"]["suffix_group"] == "dc=example,dc=org"
    assert profile["toolSettings"]["treeViewSuffix"] == "dc=example,dc=org"
    assert profile["Passwd"].startswith("{SSHA}")


def test_defaults_restored_into_the_empty_volume():
    for path in ("/lam-data/sess", "/lam-data/tmp", "/lam-data/config/templates", "/lam-data/config/unix.sample.conf"):
        assert os.path.exists(path), path
    assert os.path.islink("/lam-data/config/config.cfg")
    assert os.readlink("/lam-data/config/config.cfg") == "/etc/ldap-account-manager/config.cfg"


@pytest.mark.parametrize("path", [
    "/config/config.cfg.sample",
    "/config/unix.sample.conf",
    "/config/lam.conf",
    "/sess/",
    "/tmp/",
    "/tmp/internal/",
    "/lib/config.inc",
    "/help/help.inc",
    "/locale/",
])
def test_internal_paths_not_served(path):
    r = requests.get(LAM_URL + path, timeout=30)
    assert r.status_code == 404, f"{path}: {r.status_code}"


def test_unknown_path_answers_404():
    r = requests.get(LAM_URL + "/no-such-page", timeout=30)
    assert r.status_code == 404


def test_without_password_answers_503_with_the_cause():
    r = requests.get(UNCONFIGURED_URL + "/templates/login.php", timeout=30)
    assert r.status_code == 503
    assert "LAM_PASSWORD" in r.text
    assert "administrator" in r.text


def test_static_page_without_password_still_served():
    r = requests.get(UNCONFIGURED_URL + "/", timeout=30)
    assert r.status_code == 200


def test_existing_installation_keeps_its_configuration():
    with open("/upgrade-config/config.cfg") as f:
        before = f.read()
    assert ssha_matches(OLD_PASSWORD, json.loads(before)["password"])
    assert main_login(UPGRADE_URL, OLD_PASSWORD).text.count("mainmanage.php") > 0
    with open("/upgrade-config/config.cfg") as f:
        assert f.read() == before


def test_existing_installation_keeps_its_ldap_server():
    with open("/upgrade-data/config/lam.conf") as f:
        profile = json.load(f)
    assert profile["ServerURL"] == "ldap://openldap:389"
    assert profile["defaultLanguage"] == "en_GB.utf8"
    assert ssha_matches(OLD_PASSWORD, profile["Passwd"])
    r = lam_login(UPGRADE_URL, LDAP_ADMIN, LDAP_PASSWORD)
    assert "main.php" in r.text, r.text[:2000]


def test_set_variables_override_the_existing_configuration():
    with open("/reconfigure-data/config/lam.conf") as f:
        profile = json.load(f)
    assert profile["ServerURL"] == "ldap://openldap:389"
    assert profile["defaultLanguage"] == "de_DE.utf8"
    assert ssha_matches(NEW_PASSWORD, profile["Passwd"])
    with open("/reconfigure-config/config.cfg") as f:
        main = json.load(f)
    assert ssha_matches(NEW_PASSWORD, main["password"])
    assert NEW_PASSWORD not in json.dumps(main) + json.dumps(profile)


def test_set_password_opens_the_main_configuration():
    assert "mainmanage.php" in main_login(RECONFIGURE_URL, NEW_PASSWORD).text
    assert "mainmanage.php" not in main_login(RECONFIGURE_URL, OLD_PASSWORD).text


def test_set_ldap_server_is_used_for_the_login():
    r = lam_login(RECONFIGURE_URL, LDAP_ADMIN, LDAP_PASSWORD)
    assert "main.php" in r.text, r.text[:2000]


def test_unset_variables_leave_the_existing_configuration_alone():
    with open("/reconfigure-data/config/lam.conf") as f:
        profile = json.load(f)
    assert profile["Admins"] == "cn=admin,dc=example,dc=org"
    assert profile["activeTypes"] == "user,group"
    assert profile["loginMethod"] == "list"
    assert profile["typeSettings"] == {
        "suffix_user": "dc=example,dc=org",
        "modules_user": "inetOrgPerson,posixAccount,shadowAccount",
        "suffix_group": "dc=example,dc=org",
        "modules_group": "posixGroup",
    }
    assert profile["toolSettings"] == {"treeViewSuffix": "dc=example,dc=org"}
    with open("/reconfigure-config/config.cfg") as f:
        main = json.load(f)
    assert set(main) == {"default", "password", "configDatabaseType"}
    assert main["configDatabaseType"] == "files"
