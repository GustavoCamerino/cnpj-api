<?php

namespace App\Services;

use App\Exceptions\CnpjNaoEncontradoException;
use App\Exceptions\ServicoExternoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Integração com a API pública BrasilAPI.
 * Documentação: https://brasilapi.com.br/docs#tag/CNPJ
 *
 * Obs.: no enunciado a URL veio sem a barra antes do CNPJ ("/api/cnpj/v1{cnpj}").
 * A URL correta é:  GET https://brasilapi.com.br/api/cnpj/v1/{cnpj}
 * A URL base e o timeout ficam em config/services.php (chave "brasilapi").
 *
 * Por que separar isso num Service?
 *   -> Toda a parte que "conhece" a BrasilAPI (URL, nomes dos campos, códigos de
 *      erro) fica num só lugar. Se um dia trocarmos para outra API (ReceitaWS,
 *      CNPJá, que o enunciado permite), só esta classe muda. O controller e o banco
 *      continuam iguais.
 *   -> Nos testes, o Http::fake() substitui a chamada real e os testes não
 *      dependem da internet nem do limite de requisições da BrasilAPI.
 */
class BrasilApiService
{
    /**
     * Consulta o CNPJ (já sem máscara) e devolve o JSON bruto da BrasilAPI.
     *
     * @throws CnpjNaoEncontradoException quando a Receita não tem esse CNPJ (404)
     * @throws ServicoExternoException quando há timeout, falha de rede ou resposta inesperada
     */
    public function consultarCnpj(string $cnpj): array
    {
        try {
            $resposta = Http::baseUrl(config('services.brasilapi.url'))
                ->acceptJson()
                // Sem timeout, a nossa API ficaria travada se a BrasilAPI estivesse lenta.
                ->timeout(config('services.brasilapi.timeout'))
                ->get("cnpj/v1/{$cnpj}");
        } catch (ConnectionException $e) {
            // Falha de rede ou timeout: a culpa não é de quem chamou a nossa API.
            throw new ServicoExternoException('Não foi possível acessar a BrasilAPI.', previous: $e);
        }

        if ($resposta->notFound()) {
            throw new CnpjNaoEncontradoException('CNPJ não encontrado na base da Receita Federal.');
        }

        if (! $resposta->successful()) {
            // Ex.: 429 (limite de requisições) ou 5xx (instabilidade da BrasilAPI).
            throw new ServicoExternoException("A BrasilAPI respondeu com status {$resposta->status()}.");
        }

        return $resposta->json();
    }

    /**
     * Requisito: "Estruturar os dados retornados".
     *
     * Converte o JSON da BrasilAPI para as colunas da nossa tabela `empresas`.
     * A BrasilAPI devolve mais de 50 campos "achatados", com nomes como
     * `ddd_telefone_1` e `qsa`, e textos vazios como "". Aqui escolhemos só o
     * que interessa, renomeamos para algo mais claro e trocamos "" por null.
     *
     * Ponto para debate: ficamos só com os campos mais usados. Se precisarmos de
     * outros (ex.: opção pelo Simples/MEI), basta incluir aqui e na migration.
     */
    public function estruturar(array $d): array
    {
        // A BrasilAPI manda o tipo do logradouro ("RUA") separado do nome ("DAS FLORES").
        // Juntamos os dois, mas sem repetir o tipo se o nome já começar com ele.
        $tipoLogradouro = $this->texto($d['descricao_tipo_de_logradouro'] ?? null);
        $logradouro = $this->texto($d['logradouro'] ?? null);
        if ($tipoLogradouro && $logradouro && ! str_starts_with(strtoupper($logradouro), strtoupper($tipoLogradouro))) {
            $logradouro = "{$tipoLogradouro} {$logradouro}";
        }

        return [
            'razao_social' => $this->texto($d['razao_social'] ?? null),
            'nome_fantasia' => $this->texto($d['nome_fantasia'] ?? null),
            'situacao_cadastral' => $this->texto($d['descricao_situacao_cadastral'] ?? null),
            'data_situacao_cadastral' => $this->texto($d['data_situacao_cadastral'] ?? null),
            'data_inicio_atividade' => $this->texto($d['data_inicio_atividade'] ?? null),
            'natureza_juridica' => $this->texto($d['natureza_juridica'] ?? null),
            'porte' => $this->texto($d['porte'] ?? null),
            'capital_social' => $d['capital_social'] ?? null,
            'telefone' => $this->texto($d['ddd_telefone_1'] ?? null),
            'email' => $this->texto($d['email'] ?? null),

            // O endereço fica em colunas separadas (e não num JSON) para dar para
            // filtrar e indexar depois, ex.: "empresas de MT" ou "por município".
            'logradouro' => $logradouro,
            'numero' => $this->texto($d['numero'] ?? null),
            'complemento' => $this->texto($d['complemento'] ?? null),
            'bairro' => $this->texto($d['bairro'] ?? null),
            'municipio' => $this->texto($d['municipio'] ?? null),
            'uf' => $this->texto($d['uf'] ?? null),
            'cep' => $this->texto($d['cep'] ?? null),

            'cnae_principal_codigo' => $d['cnae_fiscal'] ?? null,
            'cnae_principal_descricao' => $this->texto($d['cnae_fiscal_descricao'] ?? null),

            // Listas (CNAEs secundários e sócios) ficam numa coluna JSON. Ponto
            // para debate: o mais "normalizado" seria criar tabelas próprias
            // (empresa_socios, empresa_cnaes). Ficamos com JSON por simplicidade,
            // porque esses dados são sempre lidos junto com a empresa e não são
            // filtrados sozinhos.
            'atividades_secundarias' => collect($d['cnaes_secundarios'] ?? [])
                // Sem CNAE secundário, a BrasilAPI devolve [{codigo: 0, descricao: ""}]
                // em vez de uma lista vazia. Esse item é descartado aqui.
                ->filter(fn ($a) => ! empty($a['codigo']))
                ->map(fn ($a) => [
                    'codigo' => $a['codigo'],
                    'descricao' => $this->texto($a['descricao'] ?? null),
                ])
                ->values()
                ->all(),

            // QSA = Quadro de Sócios e Administradores.
            'socios' => collect($d['qsa'] ?? [])
                ->map(fn ($s) => [
                    'nome' => $this->texto($s['nome_socio'] ?? null),
                    'qualificacao' => $this->texto($s['qualificacao_socio'] ?? null),
                    'data_entrada' => $this->texto($s['data_entrada_sociedade'] ?? null),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Tira os espaços das pontas e troca "" por null. */
    private function texto(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }
}
