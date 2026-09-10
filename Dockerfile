FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx postgresql-dev libpq \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /app
COPY . /app

RUN mkdir -p /app/public/uploads \
    && chmod -R 777 /app/public/uploads

RUN mkdir -p /etc/nginx/http.d \
    && printf '%s\n' \
    'server {' \
    '    listen 8080;' \
    '    root /app/public;' \
    '    index index.php index.html;' \
    '    location / { try_files $uri $uri/ /index.php?$query_string; }' \
    '    location ~ \.php$ {' \
    '        fastcgi_pass 127.0.0.1:9000;' \
    '        fastcgi_index index.php;' \
    '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
    '        include fastcgi_params;' \
    '    }' \
    '}' \
    > /etc/nginx/http.d/default.conf

EXPOSE 8080
CMD php-fpm -D && nginx -g 'daemon off;'
