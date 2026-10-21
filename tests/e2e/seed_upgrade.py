"""Writes the volumes of an existing installation: a main configuration and a
server profile in the layout of the old image, before the first request.
SEED_SERVER is the LDAP server the existing profile names."""
import base64
import hashlib
import json
import os

from conftest import OLD_PASSWORD

CONFIG = "/etc/ldap-account-manager"
DATA = "/var/lib/ldap-account-manager"


def ssha(password: str) -> str:
    salt = os.urandom(4)
    digest = hashlib.sha1(password.encode() + salt).digest()
    return "{SSHA}" + base64.b64encode(digest).decode() + " " + base64.b64encode(salt).decode()


def write(path: str, content: dict, owner: os.stat_result) -> None:
    with open(path, "w") as out:
        json.dump(content, out, indent=2)
    os.chown(path, owner.st_uid, owner.st_gid)


def main() -> None:
    owner = os.stat(DATA)
    os.makedirs(f"{DATA}/config", exist_ok=True)
    os.chown(f"{DATA}/config", owner.st_uid, owner.st_gid)
    write(f"{CONFIG}/config.cfg", {"default": "lam", "password": ssha(OLD_PASSWORD), "configDatabaseType": "files"}, owner)
    os.symlink(f"{CONFIG}/config.cfg", f"{DATA}/config/config.cfg")
    os.lchown(f"{DATA}/config/config.cfg", owner.st_uid, owner.st_gid)
    write(f"{DATA}/config/lam.conf", {
        "ServerURL": os.environ.get("SEED_SERVER", "ldap://openldap:389"),
        "Admins": "cn=admin,dc=example,dc=org",
        "Passwd": ssha(OLD_PASSWORD),
        "defaultLanguage": "en_GB.utf8",
        "activeTypes": "user,group",
        "loginMethod": "list",
        "typeSettings": {
            "suffix_user": "dc=example,dc=org",
            "modules_user": "inetOrgPerson,posixAccount,shadowAccount",
            "suffix_group": "dc=example,dc=org",
            "modules_group": "posixGroup",
        },
        "toolSettings": {"treeViewSuffix": "dc=example,dc=org"},
    }, owner)


if __name__ == "__main__":
    main()
