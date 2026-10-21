# Docker Images for LDAP Account Manager

[LDAP Account Manager] (LAM) is a web interface to manage the users, groups and other entries of an LDAP directory such as [mwaeckerlin/openldap]. These images run the current LAM release headless on [mwaeckerlin/php-fpm] and [mwaeckerlin/nginx]: no shell, never root, and configured from environment variables on the first start.

The `Dockerfile` builds these images:

1. `mwaeckerlin/lam:nginx` — [mwaeckerlin/nginx] with the static files of LAM, forwards every PHP request to the php-fpm container
2. `mwaeckerlin/lam:php-fpm` — [mwaeckerlin/php-fpm] with LAM, the PHP module `gettext` LAM needs on top of the modules of the base, and the configuration volumes

All features are listed in [FEATURES.md](FEATURES.md), all tests in [TESTS.md](TESTS.md), the changes in [CHANGELOG.md](CHANGELOG.md).


## Usage

Open the address of the `lam-proxy` container, `http://localhost:8080/` in the example below. The start page leads to the LAM login; choose the administrator of your directory and log in with its LDAP password. The main configuration and the server profiles are behind *LAM configuration* on the login page; both are protected with the password set in `LAM_PASSWORD` on the first start.


## Configuration

The variables are read once, on the first start on an empty volume, and LAM writes the main configuration `config.cfg` and the server profile `lam.conf` from them. From then on both belong to LAM's own configuration pages, and a changed variable changes nothing; to apply the variables again, remove the two files from the volumes. The names and the defaults are those of the official LAM image.

- `LAM_PASSWORD`: password of the main configuration and of the server profile, required on the first start, no default; stored only as a salted hash
- `LAM_PASSWORD_FILE`: a file that holds `LAM_PASSWORD`, for Docker secrets
- `LDAP_SERVER`: address of the LDAP server, several separated by spaces, each starting with `ldap://` or `ldaps://`, default `ldap://ldap:389`
- `LDAP_DOMAIN`: domain of the directory, default `my-domain.com`
- `LDAP_BASE_DN`: base of the directory, default derived from `LDAP_DOMAIN`: `dc=my-domain,dc=com`
- `LDAP_USERS_DN`: where users are kept, default `LDAP_BASE_DN`
- `LDAP_GROUPS_DN`: where groups are kept, default `LDAP_BASE_DN`
- `LDAP_USER`: the administrator offered on the login page, default `cn=admin,` plus `LDAP_BASE_DN`; `LDAP_USER_FILE` reads it from a file
- `LAM_LANG`: language of the interface, default `en_US`, for example `de_DE`
- `LAM_CONFIGURATION_DATABASE`: where LAM keeps its configuration, `files` (default) or `mysql`
- `LAM_CONFIGURATION_HOST`, `LAM_CONFIGURATION_PORT`, `LAM_CONFIGURATION_DATABASE_NAME`, `LAM_CONFIGURATION_USER`: the database for `mysql`, empty by default
- `LAM_CONFIGURATION_PASSWORD`: password of that database, no default; `LAM_CONFIGURATION_PASSWORD_FILE` reads it from a file
- `LAM_SKIP_PRECONFIGURE`: `true` writes no configuration at all, for configuration files you provide yourself; default `false`
- `LAM_DISABLE_TLS_CHECK`: `true` accepts any certificate of the LDAP server, default `false`; see the security trade-offs below

A missing `LAM_PASSWORD` or an invalid value answers every LAM page with status 503 and names the variable; the container log carries the same line.

The nginx image takes the variables of [mwaeckerlin/nginx]; `PHP_FPM_HOST` names the php-fpm container and defaults to `lam`.


### Volumes

The php-fpm container keeps everything LAM writes in two volumes, at the same paths as the old image:

- `/etc/ldap-account-manager`: the main configuration `config.cfg`
- `/var/lib/ldap-account-manager`: the server profiles, PDF structures and account profiles under `config/`, the sessions under `sess/`, the temporary files under `tmp/`

With the first request after every start, the image copies the files of the LAM release that are missing in `/var/lib/ldap-account-manager` into it and never overwrites a file that exists; an empty volume therefore works in Docker Swarm too. The volumes must be writable for the container user `somebody`; a volume that belongs to another user, such as one of the old image, is handed over with [mwaeckerlin/allow-write-access]. The nginx container needs no volume.


### Ports

The nginx container serves on port `8080`. The php-fpm container listens on port `9000` for the nginx container only; never publish it.


### Example

```yaml
services:
  lam-proxy:
    image: mwaeckerlin/lam:nginx
    ports:
      - 8080:8080
    networks:
      - frontend

  lam:
    image: mwaeckerlin/lam:php-fpm
    environment:
      LDAP_SERVER: ldap://openldap:389
      LDAP_DOMAIN: example.org
      LAM_PASSWORD_FILE: /run/secrets/lam-password
    secrets:
      - lam-password
    volumes:
      - config:/etc/ldap-account-manager
      - data:/var/lib/ldap-account-manager
    networks:
      - frontend
      - backend

networks:
  frontend:
  backend:

secrets:
  lam-password:
    file: lam-password.txt

volumes:
  config:
  data:
```

The LDAP server joins the network `backend`. A reverse proxy that terminates TLS in front of `lam-proxy` signals it with `X-Forwarded-Proto: https`, which [mwaeckerlin/php-fpm] passes on to LAM.


### Security trade-offs

- **The first start needs a password, and the image ships none.** The official LAM image defaults to the publicly known password `lam`; this image answers 503 until `LAM_PASSWORD` is set, so a forgotten variable never opens the configuration to everybody.
- **`LAM_DISABLE_TLS_CHECK=true` switches off the certificate check** of the LDAP connection (`LDAPTLS_REQCERT=never`): anybody between LAM and the LDAP server can then read the administrator password. Use it only for a test server with a self-signed certificate.
- **Only the static files of LAM reach nginx.** The configuration, the sessions, the temporary files and the PHP sources are not in the nginx image, so nginx answers 404 for them; LAM delivers its temporary files through PHP.
- **The PHP environment is visible to PHP**, as [mwaeckerlin/php-fpm] describes; prefer the `_FILE` variables for passwords.


### Health check

The php-fpm image reports itself healthy while php-fpm accepts connections on port 9000, checked every 60 seconds after a start period of 600 seconds, as in the old image.


## Upgrade from the old image

The old `mwaeckerlin/lam` (last built 2018) ran LAM 4.4 of Ubuntu 14.04 with Apache and PHP 5 in one container. The new images run the latest LAM release on PHP 8.

### What stays

- the volumes `/etc/ldap-account-manager` and `/var/lib/ldap-account-manager`, with the same layout inside
- the variables `CONFIG` and `DATA`, which name these two paths
- LAM at the root path, the start page with the title «LDAP Account Manager»
- missing default files are restored into the volumes on every start, existing ones are never overwritten
- the health check with the same intervals
- the logs go to the container log

### What changes

- two containers instead of one: `mwaeckerlin/lam:php-fpm` and `mwaeckerlin/lam:nginx`; the tag `latest` is no longer built
- the port is `8080` instead of `80`, on the nginx container
- the container runs as `somebody` instead of root with Apache as `www-data`: hand the old volumes over with [mwaeckerlin/allow-write-access] before the first start
- the PHP module `imap`, which the old image added for LAM's mailbox module, is gone: LAM changed its IMAP library in 7.1, and PHP 8.4 no longer contains the module
- LAM 9.4 and later read the main configuration and the server profiles only in the JSON format LAM 9.0 introduced; LAM 4.4 wrote the old text format

### Steps

1. Back up both volumes, for example with `docker run --rm -v <config-volume>:/c:ro -v <data-volume>:/d:ro alpine tar czf - /c /d > lam-backup.tgz`.
2. Convert the configuration with a LAM release between 9.0 and 9.3, which reads the old format and writes the new one on save: start the official image `ldapaccountmanager/lam:9.3` with `LAM_SKIP_PRECONFIGURE=true` on the same two volumes, open *LAM configuration*, save the main configuration, then open and save every server profile. LAM's own upgrade notes list what to check after the jump from 4.4, among them the tree view suffix (7.6), the Unix settings (6.3) and the PDF structures (9.7).
3. Stop it, run [mwaeckerlin/allow-write-access] on the two volumes, then start the new images with the same volumes. The existing configuration is used as it is; the `LAM_*` and `LDAP_*` variables are only read where `config.cfg` is missing.

Without the conversion, start the new images on new volumes with the variables above and enter the settings of the old server profiles again on the configuration pages.


## Development

```bash
npm run build
npm start
```

Then open http://localhost:8080/. `npm test` runs the docs contract (every feature has a test), the image contract (both images headless), the module contract (php-fpm and the PHP CLI load every module LAM needs), the config contract (no password in the image, the volumes, the health check) and the end-to-end suite under `tests/e2e/`, which starts both images in front of a real OpenLDAP server and logs in through nginx and php-fpm, on fresh volumes, without a password, and on the volumes of an existing installation.

The LAM release is the one download pinned in the `Dockerfile`, with its SHA-256 from the release page of LAM on GitHub; a new release changes the address and the checksum. The PHP module `gettext` is compiled on the official `php:fpm-alpine` in a build stage of its own and copied into the base image, the way [mwaeckerlin/php-fpm] describes it for images built on it.

The images are built and published for amd64 and arm64 by the reusable workflow of [mwaeckerlin/scratch].


## Internals

php-fpm runs `preconfigure.php` before every request (`auto_prepend_file`); APCu remembers for the lifetime of php-fpm that it has done its work, so it runs once per start. It restores the missing default files, links `config/config.cfg` to `/etc/ldap-account-manager/config.cfg` where the link is missing, and writes `config.cfg` and the server profile from the samples of the release with the values of the variables. A file is written to a temporary name and linked into place, so two parallel first requests never see half a file and never overwrite each other.


[LDAP Account Manager]: https://www.ldap-account-manager.org/ "LDAP Account Manager"
[mwaeckerlin/openldap]: https://github.com/mwaeckerlin/openldap "OpenLDAP server"
[mwaeckerlin/php-fpm]: https://github.com/mwaeckerlin/php-fpm "headless PHP-FPM base image"
[mwaeckerlin/nginx]: https://github.com/mwaeckerlin/nginx "headless nginx base image"
[mwaeckerlin/scratch]: https://github.com/mwaeckerlin/scratch "runtime base and the shared build workflow"
[mwaeckerlin/allow-write-access]: https://github.com/mwaeckerlin/allow-write-access "hands a volume over to the container user"
