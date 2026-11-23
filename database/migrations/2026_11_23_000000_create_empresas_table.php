<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela `empresas` (requisito: "Persistir as informações no banco de dados local").
 *
 * No Docker (Tarefa 4) o banco é o MySQL. Sem Docker, é o SQLite que já vem no
 * Laravel (database/database.sqlite). O banco muda só pela variável DB_CONNECTION,
 * e esta mesma migration funciona nos dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();

            // REGRA DE IDEMPOTÊNCIA (garantida pelo banco):
            // o índice UNIQUE impede que o mesmo CNPJ seja gravado duas vezes,
            // mesmo que exista um bug no código ou que duas requisições cheguem ao
            // mesmo tempo. O CNPJ é gravado sempre SEM máscara, então
            // "11.222.333/0001-81" e "11222333000181" são o mesmo registro.
            // 14 caracteres e tipo string (e não número) para caber o CNPJ
            // alfanumérico e não perder zeros à esquerda.
            $table->string('cnpj', 14)->unique();

            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('situacao_cadastral')->nullable();
            $table->date('data_situacao_cadastral')->nullable();
            $table->date('data_inicio_atividade')->nullable();
            $table->string('natureza_juridica')->nullable();
            $table->string('porte')->nullable();
            // decimal e não float: valor em dinheiro não pode ter erro de arredondamento.
            $table->decimal('capital_social', 18, 2)->nullable();
            $table->string('telefone')->nullable();
            $table->string('email')->nullable();

            // Endereço
            $table->string('logradouro')->nullable();
            $table->string('numero')->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('municipio')->nullable();
            $table->char('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();

            // Atividade econômica principal (CNAE)
            $table->unsignedInteger('cnae_principal_codigo')->nullable();
            $table->string('cnae_principal_descricao')->nullable();

            // Listas guardadas como JSON (veja a explicação no BrasilApiService::estruturar)
            $table->json('atividades_secundarias')->nullable();
            $table->json('socios')->nullable();

            // Hora da última consulta à BrasilAPI. É diferente do updated_at: o
            // updated_at só muda quando algum dado muda, e o sincronizado_em muda
            // em toda consulta. Assim dá para saber se o cadastro está atualizado.
            $table->timestamp('sincronizado_em')->nullable();

            $table->timestamps(); // created_at e updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
