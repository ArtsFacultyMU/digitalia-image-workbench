#!/usr/bin/env sh
cat /etc/nginx/http.d/default.conf | envsubst '$NGINX_FASTCGI_READ_TIMEOUT' > /etc/nginx/http.d/default_subst.conf
mv /etc/nginx/http.d/default_subst.conf /etc/nginx/http.d/default.conf
nginx
php-fpm82 -F
