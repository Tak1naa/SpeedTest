ARG PHP_IMAGE=php:8.3-apache
FROM ${PHP_IMAGE}

# Install extensions
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    && docker-php-ext-install -j$(nproc) iconv \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql pdo_pgsql pgsql \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis

# Prepare files and folders

RUN mkdir -p /speedtest/

# Copy sources

COPY backend/ /speedtest/backend

COPY results/*.php /speedtest/results/
COPY results/*.ttf /speedtest/results/

COPY *.js /speedtest/
COPY favicon.ico /speedtest/

COPY docker/servers.json /servers.json

COPY docker/*.php /speedtest/
COPY docker/speedtest-security.conf /etc/apache2/conf-available/speedtest-security.conf
COPY docker/entrypoint.sh /

RUN a2enmod headers ssl \
    && a2dismod -f autoindex status negotiation \
    && a2enconf speedtest-security \
    && sed -i -e '/^[[:space:]]*CustomLog /d' -e 's|^[[:space:]]*ErrorLog .*|ErrorLog /dev/null|' /etc/apache2/sites-available/000-default.conf
COPY docker/speedtest.ini /usr/local/etc/php/conf.d/speedtest.ini

# Apply signed stable updates even when earlier dependency-install layers are cached.
# Do not mix sid/testing packages into this pinned stable PHP base.
RUN apt-get update && apt-get upgrade -y \
    && apt-mark manual libfreetype6 libjpeg62-turbo libpng16-16t64 \
    && apt-get purge -y $PHPIZE_DEPS linux-libc-dev \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Prepare environment variabiles defaults

ENV TITLE=LibreSpeed
ENV MODE=standalone
ENV PASSWORD=password
ENV TELEMETRY=false
ENV ENABLE_ID_OBFUSCATION=false
ENV REDACT_IP_ADDRESSES=false
ENV WEBPORT=80

# Final touches

EXPOSE 80
CMD ["bash", "/entrypoint.sh"]
