#!/bin/sh
# =============================================================================
# Roda toda vez que o container "app" inicia, antes de subir o Apache.
# =============================================================================
set -e

# 1) APP_KEY: chave que o Laravel usa para criptografia. Se ela não veio do .env
#    (via docker-compose), é gerada uma chave temporária para o ambiente funcionar
#    sem nenhuma configuração manual. Em produção, a chave deve ser fixa no .env.
if [ -z "$APP_KEY" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    export APP_KEY
    echo "[entrypoint] APP_KEY não definida. Foi gerada uma chave temporária para esta execução."
fi

# 2) Migrations: cria ou atualiza as tabelas no MySQL. O docker-compose só inicia
#    este container depois que o banco passa no healthcheck, mas ainda tenta
#    algumas vezes por segurança (ex.: o MySQL demorando no primeiro start).
tentativas=0
until php artisan migrate --force; do
    tentativas=$((tentativas + 1))
    if [ "$tentativas" -ge 10 ]; then
        echo "[entrypoint] Não foi possível conectar ao banco de dados. Abortando."
        exit 1
    fi
    echo "[entrypoint] Banco ainda não está pronto. Nova tentativa em 3s ($tentativas/10)..."
    sleep 3
done

# 3) As migrations rodaram como root e podem ter criado arquivos em storage/.
#    O Apache roda como www-data e precisa conseguir escrever ali (logs, cache).
chown -R www-data:www-data storage bootstrap/cache

# 4) Passa o controle para o comando principal (apache2-foreground). O "exec"
#    faz o Apache virar o processo principal do container e receber os sinais de
#    parada do Docker corretamente.
exec "$@"
