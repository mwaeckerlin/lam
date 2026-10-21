FROM mwaeckerlin/very-base AS lam
ENV CONFIG="/etc/ldap-account-manager"
ENV DATA="/var/lib/ldap-account-manager"
RUN $PKG_INSTALL tar bzip2 curl
RUN LATEST=$(curl -fsSI https://github.com/LDAPAccountManager/lam/releases/latest \
        | sed -n 's|.*location:.*/tag/\([^[:space:]]*\).*|\1|p') \
    && test -n "$LATEST" \
    && echo "**** LAM latest release: ${LATEST}" \
    && curl -fsSL "https://github.com/LDAPAccountManager/lam/releases/download/${LATEST}/ldap-account-manager-${LATEST}.tar.bz2" -o /tmp/lam.tar.bz2
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
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
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
ENV LAM_DISABLE_TLS_CHECK="false"
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
