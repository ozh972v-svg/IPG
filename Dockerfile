FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx postgresql-dev libpq libzip-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql zip

WORKDIR /app
COPY . /app

RUN mkdir -p /app/public/uploads \
    && chmod -R 777 /app/public/uploads \
    && chmod -R 755 /app/public \

RUN echo "upload_max_filesize = 20M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 25M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/uploads.ini

RUN mkdir -p /etc/nginx/http.d \
    && printf '%s\n' \
    'server {' \
    '    listen 8080;' \
    '    root /app/public;' \
    '    index index.html index.php;' \
    '    client_max_body_size 25M;' \
    '    location = / {' \
    '        try_files /index.html =404;' \
    '    }' \
    '    location / {' \
    '        try_files $uri $uri/ =404;' \
    '    }' \
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
