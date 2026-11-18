# Imagem de desenvolvimento do backend Laravel (PHP 8.4 CLI).
# O código-fonte é montado em /app pelo compose; a imagem contém só o runtime.

FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql intl bcmath zip pcntl opcache

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app

EXPOSE 8000

# Servidor embutido do PHP com roteador próprio (backend/server.php): serve os
# arquivos do dashboard com cabeçalhos de cache e entrega o resto ao roteador do
# Laravel (ADR-0010). Não usamos `php artisan serve` porque ele repassa ao processo
# filho só uma lista fixa de variáveis de ambiente, descartando as DB_* do compose.
# O roteador usa getcwd() como pasta pública, por isso o `cd public`.
# Sem backend/server.php (código anterior à FASE 4), usa o roteador padrão do Laravel:
# a mesma imagem serve qualquer branch.
CMD ["sh", "-c", "cd public && if [ -f ../server.php ]; then router=../server.php; else router=../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php; fi && exec php -S 0.0.0.0:8000 \"$router\""]
