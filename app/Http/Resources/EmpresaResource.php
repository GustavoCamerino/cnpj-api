<?php

namespace App\Http\Resources;

use App\Support\Cnpj;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formato do JSON devolvido pela API (requisito: "Retornar os dados processados em JSON").
 *
 * Por que usar um Resource em vez de devolver o Model direto?
 *   -> O JSON de resposta fica separado da estrutura do banco. Se uma coluna
 *      mudar, o JSON que os clientes recebem continua o mesmo.
 *   -> Dá para agrupar os dados: no banco o endereço está em colunas soltas, mas
 *      na resposta ele vem como um objeto `endereco`.
 *   -> As listagens (Tarefa 2) usam exatamente o mesmo formato.
 *
 * @mixin \App\Models\Empresa
 */
class EmpresaResource extends JsonResource
{
    /**
     * Mantém acentos e barras legíveis no JSON ("Associação", "19.131.243/0001-97")
     * em vez de "Associação" e "19.131.243\/0001-97".
     * Também vale para a listagem, porque a coleção usa as opções deste Resource.
     */
    public function jsonOptions(): int
    {
        return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'cnpj_formatado' => Cnpj::formatar($this->cnpj),
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'situacao_cadastral' => $this->situacao_cadastral,
            'data_situacao_cadastral' => $this->data_situacao_cadastral?->format('Y-m-d'),
            'data_inicio_atividade' => $this->data_inicio_atividade?->format('Y-m-d'),
            'natureza_juridica' => $this->natureza_juridica,
            'porte' => $this->porte,
            'capital_social' => $this->capital_social !== null ? (float) $this->capital_social : null,
            'telefone' => $this->telefone,
            'email' => $this->email,
            'endereco' => [
                'logradouro' => $this->logradouro,
                'numero' => $this->numero,
                'complemento' => $this->complemento,
                'bairro' => $this->bairro,
                'municipio' => $this->municipio,
                'uf' => $this->uf,
                'cep' => $this->cep,
            ],
            'atividade_principal' => [
                'codigo' => $this->cnae_principal_codigo,
                'descricao' => $this->cnae_principal_descricao,
            ],
            'atividades_secundarias' => $this->atividades_secundarias ?? [],
            'socios' => $this->socios ?? [],
            'sincronizado_em' => $this->sincronizado_em?->toIso8601String(),
            'criado_em' => $this->created_at?->toIso8601String(),
            'atualizado_em' => $this->updated_at?->toIso8601String(),
        ];
    }
}
