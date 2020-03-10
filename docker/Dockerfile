FROM alpine:3.10
HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
  CMD sh /php-fpm-healthcheck -v || exit 1
WORKDIR /app
# See that thing in the next line? I don't fucking care. mkfifo pipe && cat pipe works, too. kek
CMD php-fpm7; nginx -q ; php7 wsserver.php
RUN apk add --update --no-cache php7 php7-json php7-sockets php7-curl php7-sqlite3 php7-fpm fcgi nginx && \
    mkdir -p /app/.bbdb && chown nginx:nginx /app/.bbdb
COPY docker/www.conf /etc/php7/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm-healthcheck /
COPY --chown=nginx . /app

VOLUME /app/.bbdb/

EXPOSE 80 47631

ENV FPM_PM=dynamic \
    FPM_PM_MAX_CHILDREN=5 \
    FPM_PM_START_SERVICE=2 \
    FPM_PM_MIN_SPARE_SERVERS=1 \
    FPM_PM_MAX_SPARE_SERVERS=3
