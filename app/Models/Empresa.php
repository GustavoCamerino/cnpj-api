<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Eloquent da tabela `empresas`.
 */
class Empresa extends Model
{
    protected $table = 'empresas';

    /**
     * Campos que podem ser preenchidos em massa (create / updateOrCreate).
     * É uma proteção do Eloquent: só o que estiver nesta lista é gravado.
     */
    protected $fillable = [
        'cnpj',
        'razao_social',
        'nome_fantasia',
        'situacao_cadastral',
        'data_situacao_cadastral',
        'data_inicio_atividade',
        'natureza_juridica',
        'porte',
        'capital_social',
        'telefone',
        'email',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'municipio',
        'uf',
        'cep',
        'cnae_principal_codigo',
        'cnae_principal_descricao',
        'atividades_secundarias',
        'socios',
        'sincronizado_em',
    ];

    /**
     * Conversões automáticas de tipo.
     * 'array': o Eloquent transforma o array PHP em JSON ao gravar e o JSON em
     * array ao ler, então não é preciso chamar json_encode/json_decode à mão.
     */
    protected function casts(): array
    {
        return [
            'data_situacao_cadastral' => 'date',
            'data_inicio_atividade' => 'date',
            'capital_social' => 'decimal:2',
            'cnae_principal_codigo' => 'integer',
            'atividades_secundarias' => 'array',
            'socios' => 'array',
            'sincronizado_em' => 'datetime',
        ];
    }
}
