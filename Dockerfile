FROM ushahidi/php-fpm-nginx:php-7.4
LABEL org.opencontainers.image.source="https://github.com/ushahidi/platform"

# TODO: non-root user container setup
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker-php-ext-enable /usr/local/bin/

# Staged rather than dropped straight into conf.d: it carries
# "zend_extension=xdebug", so PHP would fail to load the extension on every
# request if the ini were present without xdebug itself being built.
COPY docker-php-ext-xdebug.ini /usr/local/share/docker-php-ext-xdebug.ini

# Xdebug is a development tool and is not built into the image by default.
# Compiling it needs php-pear and the PHP dev headers, which drag in packages
# from Debian 11's security pool. Bullseye is end-of-life and those files are
# being withdrawn from the mirrors, so installing it unconditionally fails the
# build on a mirror's schedule rather than on anything in this repository.
#
# Build with xdebug for local debugging:
#   docker compose build --build-arg INSTALL_XDEBUG=true api
ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        set -eux; \
        { apt-key del B188E2B695BD4743 2>/dev/null || true; }; \
        curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb; \
        dpkg -i /tmp/debsuryorg-archive-keyring.deb; \
        rm /tmp/debsuryorg-archive-keyring.deb; \
        apt-get update; \
        apt-get install -y php-pear "php${PHP_MAJOR_VERSION}-dev"; \
        pecl channel-update pecl.php.net; \
        pecl install xdebug-3.1.6; \
        PHP_INI_DIR="/etc/php/${PHP_MAJOR_VERSION}/fpm" docker-php-ext-enable xdebug; \
        PHP_INI_DIR="/etc/php/${PHP_MAJOR_VERSION}/cli" docker-php-ext-enable xdebug; \
        cp /usr/local/share/docker-php-ext-xdebug.ini \
            "/etc/php/${PHP_MAJOR_VERSION}/fpm/conf.d/docker-php-ext-xdebug.ini"; \
        rm -rf /var/lib/apt/lists/*; \
    fi

# Set unconditionally so the image carries the same value whether or not xdebug
# was built, matching what the previous always-on install left behind.
ENV PHP_INI_DIR=/etc/php/${PHP_MAJOR_VERSION}/cli

COPY docker/php-uploads.ini /etc/php/${PHP_MAJOR_VERSION}/fpm/conf.d

WORKDIR /var/www
COPY composer.json ./
COPY composer.lock ./
COPY packages ./packages
RUN composer self-update --2
RUN composer install --no-autoloader --no-scripts
COPY docker/csv-upload.ini /etc/php/${PHP_MAJOR_VERSION}/fpm/conf.d/zz-csv-upload.ini
COPY docker/csv-upload.ini /etc/php/${PHP_MAJOR_VERSION}/cli/conf.d/zz-csv-upload.ini
RUN sed -i \
    -e 's/default .Env.PHP_UPLOAD_MAX_FILESIZE "4m"/default .Env.PHP_UPLOAD_MAX_FILESIZE "60m"/' \
    -e 's/client_max_body_size 10m/client_max_body_size {{ default .Env.PHP_UPLOAD_MAX_FILESIZE "60m" }}/' \
    /tmpl/etc/nginx/sites-available/default

COPY . .
COPY docker/utils.sh /utils.sh
COPY docker/run.tasks.conf /etc/chaperone.d/
COPY docker/run.run.sh /run.run.sh
RUN echo '#!/bin/bash\n. /utils.sh\n"$@"' > /bin/util ; chmod +x /bin/util ;

RUN $DOCKERCES_MANAGE_UTIL add /run.run.sh

ARG GIT_COMMIT_ID
ARG GIT_BUILD_REF

ENV ENABLE_PLATFORM_TASKS=true \
    DB_MIGRATIONS_HANDLED=true \
    RUN_PLATFORM_MIGRATIONS=true \
    VHOST_ROOT=/var/www/httpdocs \
    VHOST_INDEX=index.php \
    PHP_EXEC_TIME_LIMIT=3600 \
    GIT_COMMIT_ID=${GIT_COMMIT_ID} \
    GIT_BUILD_REF=${GIT_BUILD_REF}

CMD [ "start" ]
