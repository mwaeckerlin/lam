# Tests

Register of all tests, grouped by kind and sorted by the [FEATURES.md](FEATURES.md) number each test covers. `npm test` runs everything; the guard `tests/docs-contract.sh` fails when a feature has no test entry here or when any test carries a skip/xfail marker.

The e2e stack (`tests/e2e/`) runs both images of this project in front of a real OpenLDAP server, built from Alpine's `openldap` package in `tests/e2e/openldap/`, three times: configured from the environment on empty volumes, started without `LAM_PASSWORD`, and started on volumes that `tests/e2e/seed_upgrade.py` fills with the configuration of an existing installation before the first request.

## End-to-end

- **F1** `tests/e2e/test_lam.py` › test_root_redirects_to_login — the start page carries the title «LDAP Account Manager» and leads to the login page.
- **F1** `tests/e2e/test_lam.py` › test_login_page_rendered_by_php — the login page is executed by php-fpm and offers the administrator.
- **F1** `tests/e2e/test_lam.py` › test_no_missing_php_module_reported — the login page reports no missing PHP extension and no unwritable directory.
- **F1, F2** `tests/e2e/test_lam.py` › test_ldap_login_with_valid_password — a login with the LDAP password of the administrator against the OpenLDAP server opens LAM.
- **F1** `tests/e2e/test_lam.py` › test_ldap_login_with_wrong_password — a wrong password shows the login page again.
- **F2** `tests/e2e/test_lam.py` › test_main_configuration_password_from_environment — `LAM_PASSWORD` opens the main configuration.
- **F2** `tests/e2e/test_lam.py` › test_main_configuration_wrong_password — another password is refused.
- **F2, F4** `tests/e2e/test_lam.py` › test_configuration_written_to_the_volumes — the main configuration in the config volume holds only the hash, the server profile in the data volume holds the server, administrator, language and suffixes from the variables.
- **F3** `tests/e2e/test_lam.py` › test_without_password_answers_503_with_the_cause — without `LAM_PASSWORD` the login page answers 503 and names the variable.
- **F3** `tests/e2e/test_lam.py` › test_static_page_without_password_still_served — the start page still answers 200.
- **F4** `tests/e2e/test_lam.py` › test_existing_installation_keeps_its_configuration — on the volumes of an existing installation the old password opens the main configuration, `LAM_PASSWORD` does not, and `config.cfg` is unchanged.
- **F4** `tests/e2e/test_lam.py` › test_existing_installation_keeps_its_ldap_server — the existing server profile is used, not `LDAP_SERVER`.
- **F5** `tests/e2e/test_lam.py` › test_defaults_restored_into_the_empty_volume — sessions, temporary files, templates and samples are restored into the empty data volume, with the link to `config.cfg`.
- **F6** `tests/e2e/test_lam.py` › test_internal_paths_not_served — configuration, sessions, temporary files, libraries, help and translations answer 404.
- **F6** `tests/e2e/test_lam.py` › test_unknown_path_answers_404 — an unknown address answers 404.
- **F8** `tests/run-e2e.sh` › healthcheck_command — the health check command of the image succeeds in the running container.

## Image contract

- **F1** `tests/module-contract.sh` › modules_of_php-fpm, modules_of_php — php-fpm and the PHP CLI load every module of `tests/expected-modules.txt`, gettext among them.
- **F2** `tests/config-contract.sh` › preconfigure_prepended, preconfigure_syntax — php-fpm runs the preparation before every request, and it parses.
- **F3** `tests/config-contract.sh` › no_default_lam_password, no_default_lam_configuration_password — the image sets no password.
- **F4** `tests/config-contract.sh` › volume__etc_ldap-account-manager, volume__var_lib_ldap-account-manager — both volumes of the old image are declared.
- **F7** `tests/image-contract.sh` › no sh, no bash, no busybox, no perl — both images are headless.
- **F7** `tests/image-contract.sh` › not_root — both images run as an unprivileged user.
- **F8** `tests/config-contract.sh` › healthcheck_declared — the php-fpm image declares its health check.

## Workflow contract

- **F9** `tests/workflow-contract.sh` of `mwaeckerlin/scratch` — the reusable workflow selects exactly the images a repository publishes; this repository calls it from `.github/workflows/docker.yml`.
