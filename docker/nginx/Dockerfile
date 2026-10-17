# Production edge image for the Laravel API: nginx plus the document root it
# serves (index.php, favicon, robots.txt). PHP requests are proxied to the
# `backend` php-fpm service on the compose network.

FROM nginx:1.29-alpine

COPY docker/nginx/backend.conf /etc/nginx/conf.d/default.conf
COPY backend/public /var/www/html/public
