FROM mwaeckerlin/very-base AS lam
ENV CONFIG="/etc/ldap-account-manager"
ENV DATA="/var/lib/ldap-account-manager"
RUN $PKG_INSTALL tar bzip2
ADD --checksum=sha256:b3535f91afa09ba49f59d8a7fdfbb0a4f5aae2b9ed17aac7b8bfae36e834d9e0 \
    https://github.com/LDAPAccountManager/lam/releases/download/9.7/ldap-account-manager-9.7.tar.bz2 \
    /tmp/lam.tar.bz2
WORKDIR /root/app
RUN tar xjf /tmp/lam.tar.bz2 --strip-components=1
RUN test -f VERSION && test -f templates/login.php && test -f config/config.cfg.sample && test -f config/unix.sample.conf
RUN mkdir -p "/root${DATA}.original" "/root${DATA}" "/root${CONFIG}"
RUN mv config sess tmp "/root${DATA}.original/"
RUN ln -s "${DATA}/config" config && ln -s "${DATA}/sess" sess && ln -s "${DATA}/tmp" tmp
RUN $ALLOW_USER "/root${DATA}" "/root${CONFIG}"
COPY preconfigure.php /root/usr/local/lib/lam/preconfigure.php
COPY lam.ini /root/usr/local/etc/php/conf.d/lam.ini

FROM lam AS static
RUN rm -rf /root/app/config /root/app/sess /root/app/tmp /root/app/lib /root/app/help /root/app/locale
RUN find /root/app -name '*.inc' -delete
# nginx only checks that a script exists, php-fpm runs it
RUN find /root/app -name '*.php' -exec sh -c 'for f; do : > "$f"; done' sh {} +

FROM php:fpm-alpine AS gettext
ADD --chmod=0755 --checksum=sha256:3f49c71fa66c79b8b2b96bc0ce92885dafd7946f3b5a46f545b390d6f1617e2c \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.0/install-php-extensions \
    /usr/local/bin/install-php-extensions
RUN install-php-extensions gettext
RUN mkdir -p /root/gettext \
    && tar cph \
        "$(php-config --extension-dir)/gettext.so" \
        /usr/local/etc/php/conf.d/docker-php-ext-gettext.ini \
        $(ldd "$(php-config --extension-dir)/gettext.so" | sed -n 's,.* => \([^ ]*\) .*,\1,p') \
    | tar xpC /root/gettext/

FROM mwaeckerlin/php-fpm AS php-fpm
ENV CONTAINERNAME="lam"
ENV CONFIG="/etc/ldap-account-manager"
ENV DATA="/var/lib/ldap-account-manager"
ENV LAM_SKIP_PRECONFIGURE="false"
ENV LAM_LANG="en_US"
ENV LDAP_SERVER="ldap://ldap:389"
ENV LDAP_DOMAIN="my-domain.com"
ENV LDAP_BASE_DN=""
ENV LDAP_USERS_DN=""
ENV LDAP_GROUPS_DN=""
ENV LDAP_USER=""
ENV LAM_DISABLE_TLS_CHECK="false"
ENV LAM_CONFIGURATION_DATABASE="files"
ENV LAM_CONFIGURATION_HOST=""
ENV LAM_CONFIGURATION_PORT=""
ENV LAM_CONFIGURATION_DATABASE_NAME=""
ENV LAM_CONFIGURATION_USER=""
COPY --from=gettext /root/gettext/ /
COPY --from=lam /root/ /
HEALTHCHECK --interval=60s --timeout=30s --start-period=600s --retries=3 \
    CMD ["/usr/bin/php", "-r", "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"]
VOLUME /etc/ldap-account-manager
VOLUME /var/lib/ldap-account-manager

FROM mwaeckerlin/nginx AS nginx
ENV CONTAINERNAME="lam-nginx"
ENV PHP_FPM_HOST="lam"
ENV SPA_FALLBACK="no"
COPY --from=static /root/app /app
