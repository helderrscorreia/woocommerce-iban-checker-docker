FROM php:8.3-cli-alpine

RUN apk add --no-cache ca-certificates msmtp \
    && docker-php-ext-install pdo_mysql

WORKDIR /app
COPY src/ /app/src/
COPY tests/ /app/tests/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p /config /data

# Unhealthy when no successful check finished within three intervals.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$f = getenv("HEARTBEAT_FILE") ?: "/tmp/watchdog.heartbeat"; $i = max(5, (int) (getenv("CHECK_INTERVAL") ?: "60")); exit((is_file($f) && time() - filemtime($f) < 3 * $i) ? 0 : 1);'

ENTRYPOINT ["docker-entrypoint.sh"]
