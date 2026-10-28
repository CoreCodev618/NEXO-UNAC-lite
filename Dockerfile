FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev unzip python3 python3-venv \
    && docker-php-ext-install intl mysqli \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY python/requirements.txt python/requirements.txt
RUN python3 -m venv python/.venv \
    && python/.venv/bin/pip install --no-cache-dir -r python/requirements.txt

# tiktoken descarga su codificación la primera vez: dejarla en la imagen.
ENV TIKTOKEN_CACHE_DIR=/app/.tiktoken
RUN python/.venv/bin/python -c "import tiktoken; tiktoken.get_encoding('cl100k_base')"

COPY . .

CMD ["docker/start.sh"]
