# Changelog

- 2026-10-22 **1.7.3**
    - Every `LAM_*` and `LDAP_*` variable that is set is applied to the existing configuration on every start, so a changed variable takes effect with a restart; variables that are not set leave what was changed on LAM's configuration pages alone
    - A set configuration password replaces the old one on the next start
    - The port of the configuration database must be a number from 1 to 65535

- 2026-10-21 **1.7.2**
    - Every build takes the latest LAM release and the latest `install-php-extensions` instead of fixed versions with fixed checksums

- 2026-10-21 **1.7.1**
    - LDAP Account Manager 9.7 on PHP 8, rebuilt headless in two images: `mwaeckerlin/lam:nginx` serves the static files on port 8080, `mwaeckerlin/lam:php-fpm` runs LAM; no shell, never root, published for amd64 and arm64 and rebuilt every week
    - The first start on an empty volume writes the main configuration and the server profile from environment variables with the names and defaults of the official LAM image; the password is required and stored only as a hash, and can come from a Docker secret
    - The volumes `/etc/ldap-account-manager` and `/var/lib/ldap-account-manager` keep their paths and layout, an existing configuration is used as it is, and missing default files are restored on every start as before
    - The configuration, sessions, temporary files and PHP sources are no longer reachable through the web server
    - The README describes the upgrade from the old image: the conversion of the configuration with LAM 9.3, the new port, the new user of the volumes

- 2018-08-31 **1.7.0**
    - Defaults are restored into existing volumes file by file on every start, without overwriting a file; smaller image

- 2018-03-15 **1.6.0**
    - Health check: the container reports itself unhealthy when the LAM start page no longer answers

- 2018-01-14 **1.5.0**
    - The PHP module imap is installed and enabled, so LAM no longer reports it as missing

- 2018-01-11 **1.4.0**
    - An empty volume, as Docker Swarm creates it, is filled with the default configuration on the first start

- 2017-06-21 **1.3.0**
    - The README links the instructions for setting up OpenLDAP with LAM in Docker

- 2016-11-18 **1.2.0**
    - The configuration in `/etc/ldap-account-manager`, with the master password, is a volume
    - Apache logs go to the container log
    - Built on Ubuntu 14.04, whose LAM release runs on its PHP 5

- 2016-07-29 **1.1.0**
    - A container that was stopped hard starts again

- 2016-03-07 **1.0.0**
    - LDAP Account Manager of Ubuntu with Apache, at the root path on port 80, with the data volume `/var/lib/ldap-account-manager`
