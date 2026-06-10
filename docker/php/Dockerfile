# docker/php/Dockerfile
FROM php:8.2-cli-bookworm

# 必要なツールとSQLite用ライブラリをインストール
# Debian系なので apt を使用。将来の拡張のために build-essential 等も入れやすい。
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHPの拡張機能をインストール
RUN docker-php-ext-install pdo pdo_sqlite

WORKDIR /var/www/html

# 組み込みサーバーを起動
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
