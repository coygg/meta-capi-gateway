FROM php:8.3-cli-alpine

RUN apk add --no-cache ca-certificates curl sqlite-libs \
    && apk add --no-cache --virtual .build-deps curl-dev sqlite-dev \
    && docker-php-ext-install curl pdo_sqlite \
    && apk del .build-deps

WORKDIR /app

COPY . .

RUN mkdir -p /var/data /app/storage \
    && chown -R www-data:www-data /var/data /app/storage

USER www-data

ENV APP_ENV=production \
    COOKIE_SECURE=true \
    TRUST_PROXY=true \
    DB_PATH=/var/data/gateway.sqlite \
    PORT=10000

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t public"]
