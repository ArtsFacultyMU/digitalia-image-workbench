# Envars
ARG NAME=islandora-workbench
ARG BUILD_DATE
ARG VCS_REF
ARG VCS_URL
ARG VERSION=3.10-alpine

FROM python:${VERSION}

RUN printf "Running on ${BUILDPLATFORM:-linux/amd64}, building for ${TARGETPLATFORM:-linux/amd64}\n$(uname -a).\n"

LABEL maintainer="Jan Adler <adler@phil.muni.cz>" \
	org.label-schema.build-date=${BUILD_DATE} \
	org.label-schema.name=${NAME} \
	org.label-schema.description="Islandora workbench" \
	org.label-schema.url="" \
	org.label-schema.usage="" \
	org.label-schema.vcs-ref=${VCS_REF} \
	org.label-schema.vcs-url=${VCS_URL} \
	org.label-schema.vendor="Masaryk University - Faculty of Arts" \
	org.label-schema.schema-version="1.0"

RUN	   apk -qq update \
	&& apk -qq upgrade \
	&& apk -qq add coreutils \
	&& apk -qq add git \
	&& apk -qq add patch \
	&& apk -qq add nginx \
	&& apk -qq add vim \
	&& apk -qq add gettext-envsubst \
	&& apk -qq add php82-fpm php82-soap php82-openssl php82-gmp php82-pdo_odbc php82-json php82-dom php82-pdo php82-zip php82-mysqli php82-sqlite3 php82-apcu php82-pdo_pgsql php82-bcmath php82-gd php82-odbc php82-pdo_mysql php82-pdo_sqlite php82-gettext php82-xmlreader php82-bz2 php82-iconv php82-pdo_dblib php82-curl php82-ctype php82-pecl-yaml \
	&& rm -rf /var/cache/apk/*


RUN	   mkdir -p /var/www/html/api \
	&& cp /etc/nginx/http.d/default.conf /root/default.conf \
	&& rm /etc/nginx/http.d/default.conf

COPY nginx_default.conf /etc/nginx/http.d/default.conf
COPY php-fpm_env.conf /etc/php82/php-fpm.d/
COPY api/*.php /var/www/html/api/
COPY docker-entrypoint.sh /usr/local/bin/
COPY workbench.patch /var/lib/nginx/
COPY workbench_base.yml /var/lib/nginx/workbench/
COPY workbench_media_base.yml /var/lib/nginx/workbench/
COPY init_db_base.yml /var/lib/nginx/workbench/



RUN	   mkdir -p /var/lib/nginx/workbench/input_dir \
        && mkdir -p /var/lib/nginx/workbench/output_dir \
   	&& chown -R nginx:nginx /var/lib/nginx/workbench \
   	&& chown -R nginx:nginx /var/www/html \
	&& chown -R nginx:nginx /var/log/php82 \
	&& chown -R nginx:nginx /etc/nginx/http.d \
	&& chmod 755 /usr/local/bin/docker-entrypoint.sh

USER 100

RUN	   cd \
	&& git clone --depth 1 --branch python38eol https://github.com/mjordan/islandora_workbench \
	&& cd islandora_workbench \
	&& patch -p1 </var/lib/nginx/workbench.patch \
	&& python -m pip install --user .


ENTRYPOINT ["docker-entrypoint.sh"]
