FROM alpine
RUN apk add --no-cache openldap openldap-back-mdb
RUN mkdir -p /run/openldap /var/lib/openldap/openldap-data
RUN chown -R ldap:ldap /run/openldap /var/lib/openldap
COPY slapd.conf /etc/openldap/slapd.conf
EXPOSE 389
CMD ["/usr/sbin/slapd", "-d", "0", "-u", "ldap", "-g", "ldap", "-h", "ldap:///", "-f", "/etc/openldap/slapd.conf"]
