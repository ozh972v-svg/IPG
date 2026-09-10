FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev libpq oniguruma-dev \
 && docker-php-ext-install pdo pdo_pgsql mbstring fileinfo

WORKDIR /app

COPY . /app

RUN mkdir -p /app/public/uploads && chmod -R 777 /app/public/uploads

ENV PORT=8080
EXPOSE 8080

CMD php -S 0.0.0.0:${PORT} -t /app/public
