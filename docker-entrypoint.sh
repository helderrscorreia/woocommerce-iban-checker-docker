#!/bin/sh
set -eu

if [ -n "${SMTP_HOST:-}" ]; then
    : "${SMTP_FROM:?SMTP_FROM is required when SMTP_HOST is set}"
    : "${ALERT_EMAIL_TO:?ALERT_EMAIL_TO is required when SMTP_HOST is set}"

    encryption="${SMTP_ENCRYPTION:-starttls}"
    {
        echo "account default"
        echo "host ${SMTP_HOST}"
        echo "port ${SMTP_PORT:-587}"
        echo "from ${SMTP_FROM}"
        echo "logfile /dev/stderr"
        case "$encryption" in
            starttls) echo "tls on"; echo "tls_starttls on" ;;
            ssl)      echo "tls on"; echo "tls_starttls off" ;;
            none)     echo "tls off" ;;
            *)        echo "invalid SMTP_ENCRYPTION: $encryption" >&2; exit 1 ;;
        esac
        if [ -n "${SMTP_USER:-}" ]; then
            echo "auth on"
            echo "user ${SMTP_USER}"
            echo "password ${SMTP_PASSWORD:-}"
        else
            echo "auth off"
        fi
    } > /etc/msmtprc
    chmod 600 /etc/msmtprc
fi

exec php /app/src/watch.php
