# =============================================================================
# Imagem da aplicação Laravel (Tarefa 4)
#
# Base: PHP 8.4 + Apache oficiais. Assim um único container serve a aplicação,
# sem precisar de um container Nginx separado. Para uma API deste tamanho isso é
# mais simples de entender e de rodar (em produção, muitos
# times usam PHP-FPM + Nginx).
# =============================================================================
FROM php:8.4-apache

# -----------------------------------------------------------------------------
# 1) Dependências do sistema e extensões do PHP
#    - git e unzip: o Composer usa para baixar os pacotes
#    - pdo_mysql: driver para o Laravel falar com o MySQL
#      (pdo_sqlite já vem na imagem e é usado pelos testes automatizados)
#    - mod_rewrite: necessário para as URLs do Laravel (/api/empresas)
#      funcionarem pelo public/.htaccess
# -----------------------------------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

# Composer copiado da imagem oficial (mais rápido que instalar com script).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache servindo a pasta public/ do Laravel (veja o arquivo para detalhes).
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# 2) Dependências PHP
#    Copia PRIMEIRO só o composer.json/lock e instala. O Docker guarda essa
#    camada em cache: se só o código mudar (e não as dependências), o rebuild não
#    baixa tudo de novo. Isso deixa o "docker compose build" bem mais rápido.
#
#    As dependências de desenvolvimento (PHPUnit) são mantidas de propósito, para
#    o avaliador poder rodar os testes dentro do container.
# -----------------------------------------------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# -----------------------------------------------------------------------------
# 3) Código da aplicação (o .dockerignore evita copiar vendor/, .env, bancos locais...)
# -----------------------------------------------------------------------------
# O .env fica VAZIO de propósito: no container, toda a configuração vem das
# variáveis de ambiente do docker-compose.yml. O arquivo só existe porque o
# "php artisan test" avisa quando ele não existe.
COPY . .
RUN composer dump-autoload --optimize \
    && touch .env \
    && chown -R www-data:www-data storage bootstrap/cache

# -----------------------------------------------------------------------------
# 4) Script de inicialização (roda as migrations e depois sobe o Apache)
#    O sed remove o "\r" caso o arquivo tenha sido salvo com quebra de linha do
#    Windows (CRLF). Sem isso, o Linux não consegue executar o script.
# -----------------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
