# clinic-rag-assistant アプリ用イメージ
# PHP 8.3 + Laravel 実行に必要な拡張(pdo_pgsql / bcmath 等)+ Composer を同梱
FROM php:8.3-cli

# 必要なシステムパッケージと PHP 拡張をインストール
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer をイメージへコピー
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 開発用途のため PHP のメモリ制限を緩める
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
