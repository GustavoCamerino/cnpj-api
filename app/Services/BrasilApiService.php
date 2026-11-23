<?php

namespace App\Services;

use App\Exceptions\CnpjNaoEncontradoException;
use App\Exceptions\ServicoExternoException;
use App\Support\Cnpj;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Integração com a API pública BrasilAPI.
 * Documentação: https://brasilapi.com.br/docs#tag/CNPJ
 *
 * Obs.: no enunciado a URL veio sem a barra antes do CNPJ ("/api/cnpj/v1{cnpj}").
 * A URL correta é:  GET https://brasilapi.com.br/api/cnpj/v1/{cnpj}
 * URL, timeouts e tentativas ficam em config/services.php (chave "brasilapi").
 *
 * Por que separar isso num Service?
 *   -> Toda a parte que "conhece" a BrasilAPI (URL, nomes dos campos, códigos de
 *      erro) fica num só lugar. Se um dia a BrasilAPI for trocada por outra API (ReceitaWS,
 *      CNPJá, que o enunciado permite), só esta classe muda. O controller e o banco
 *      continuam iguais.
 *   -> Nos testes, o Http::fake() substitui a chamada real e os testes não
 *      dependem da internet nem do limite de requisições da BrasilAPI.
 *
 * RESILIÊNCIA (Tarefa 3): nada que vem de fora é tratado como confiável. Por isso
 *   - há tempo limite para conectar e para responder (timeout);
 *   - falhas temporárias (rede, erro 5xx) são tentadas de novo automaticamente;
 *   - cada tipo de falha vira um erro com o status HTTP adequado (veja ServicoExternoException);
 *   - antes de usar a resposta, o código confere se ela é um JSON com o formato esperado;
 *   - campos com tipo inesperado (ex.: lista no lugar de texto) são ignorados, sem derrubar a requisição.
 */
class BrasilApiService
{
    /**
     * Consulta o CNPJ (já sem máscara) e devolve o JSON da BrasilAPI já conferido.
     *
     * @throws CnpjNaoEncontradoException quando a Receita não tem esse CNPJ
     * @throws ServicoExternoException em qualquer outra falha da BrasilAPI
     */
    public function consultarCnpj(string $cnpj): array
    {
        $config = config('services.brasilapi');

        try {
            $resposta = Http::baseUrl($config['url'])
                ->acceptJson()
                // Tempo máximo para ABRIR a conexão (servidor fora do ar costuma travar aqui).
                ->connectTimeout($config['connect_timeout'])
                // Tempo máximo para a requisição inteira. Sem isso, a nossa API ficaria
                // presa esperando se a BrasilAPI estivesse lenta.
                ->timeout($config['timeout'])
                // Tenta de novo só nas falhas TEMPORÁRIAS (veja deveTentarDeNovo).
                // throw: false = depois da última tentativa, devolve a resposta para
                // tratarmos aqui embaixo, em vez de lançar a exceção genérica do Laravel.
                ->retry(
                    $config['tentativas'],
                    $config['intervalo_ms'],
                    fn (Throwable $e) => $this->deveTentarDeNovo($e),
                    throw: false,
                )
                ->get("cnpj/v1/{$cnpj}");
        } catch (ConnectionException $e) {
            // Não houve resposta nenhuma: ou passou do tempo, ou nem conectou.
            throw $this->foiTimeout($e)
                ? ServicoExternoException::timeout($e)
                : ServicoExternoException::indisponivel($e);
        }

        return $this->validarResposta($resposta, $cnpj);
    }

    /**
     * Confere o status e o conteúdo da resposta. Só devolve o JSON se ele fizer sentido.
     */
    private function validarResposta(Response $resposta, string $cnpj): array
    {
        $status = $resposta->status();

        if ($status === 404) {
            throw new CnpjNaoEncontradoException;
        }

        if ($status === 400) {
            // O CNPJ é validado antes de consultar, então isso é raro. Pode acontecer,
            // por exemplo, se a BrasilAPI ainda não aceitar o CNPJ alfanumérico.
            throw ServicoExternoException::cnpjRecusado();
        }

        if ($status === 429) {
            throw ServicoExternoException::limiteExcedido($resposta->header('Retry-After') ?: null);
        }

        if (! $resposta->successful()) {
            throw ServicoExternoException::erro($status);
        }

        // Status 200, mas o conteúdo também precisa estar certo. Um proxy ou uma
        // página de manutenção pode devolver HTML com status 200, por exemplo.
        $dados = $resposta->json();

        if (! is_array($dados) || array_is_list($dados)) {
            throw ServicoExternoException::respostaInvalida('o corpo da resposta não é um objeto JSON');
        }

        if (! isset($dados['cnpj']) || ! is_scalar($dados['cnpj'])) {
            throw ServicoExternoException::respostaInvalida('o campo "cnpj" está ausente');
        }

        if (Cnpj::limpar((string) $dados['cnpj']) !== $cnpj) {
            throw ServicoExternoException::respostaInvalida('o CNPJ retornado é diferente do consultado');
        }

        if (! is_string($dados['razao_social'] ?? null) || trim($dados['razao_social']) === '') {
            throw ServicoExternoException::respostaInvalida('o campo "razao_social" está ausente ou inválido');
        }

        return $dados;
    }

    /**
     * Quais falhas valem uma nova tentativa?
     *   - falha de conexão ou timeout: pode ter sido uma instabilidade momentânea da rede;
     *   - erro 5xx: o servidor da BrasilAPI pode ter tido um problema passageiro.
     * Não tenta de novo:
     *   - 404 e 400: a resposta seria a mesma;
     *   - 429: tentar de novo na hora só piora o excesso de requisições.
     */
    private function deveTentarDeNovo(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && $e->response->serverError();
    }

    /** O cURL usa o erro 28 para timeout ("Operation timed out"). */
    private function foiTimeout(ConnectionException $e): bool
    {
        $mensagem = strtolower($e->getMessage());

        return str_contains($mensagem, 'curl error 28') || str_contains($mensagem, 'timed out');
    }

    /**
     * Requisito: "Estruturar os dados retornados".
     *
     * Converte o JSON da BrasilAPI para as colunas da nossa tabela `empresas`.
     * A BrasilAPI devolve mais de 50 campos "achatados", com nomes como
     * `ddd_telefone_1` e `qsa`, e textos vazios como "". Aqui fica só o
     * que interessa, com nomes mais claros e "" trocado por null.
     *
     * Para resistir a dados inesperados, um campo com tipo errado vira null (ou
     * é descartado, no caso das listas) em vez de causar erro.
     *
     * Obs.: só os campos mais usados foram mantidos. Se precisar de
     * outros (ex.: opção pelo Simples/MEI), basta incluir aqui e na migration.
     */
    public function estruturar(array $d): array
    {
        // A BrasilAPI manda o tipo do logradouro ("RUA") separado do nome ("DAS FLORES").
        // Junta os dois, mas sem repetir o tipo se o nome já começar com ele.
        $tipoLogradouro = $this->texto($d['descricao_tipo_de_logradouro'] ?? null);
        $logradouro = $this->texto($d['logradouro'] ?? null);
        if ($tipoLogradouro && $logradouro && ! str_starts_with(strtoupper($logradouro), strtoupper($tipoLogradouro))) {
            $logradouro = "{$tipoLogradouro} {$logradouro}";
        }

        return [
            'razao_social' => $this->texto($d['razao_social'] ?? null),
            'nome_fantasia' => $this->texto($d['nome_fantasia'] ?? null),
            'situacao_cadastral' => $this->texto($d['descricao_situacao_cadastral'] ?? null),
            'data_situacao_cadastral' => $this->data($d['data_situacao_cadastral'] ?? null),
            'data_inicio_atividade' => $this->data($d['data_inicio_atividade'] ?? null),
            'natureza_juridica' => $this->texto($d['natureza_juridica'] ?? null),
            'porte' => $this->texto($d['porte'] ?? null),
            'capital_social' => is_numeric($d['capital_social'] ?? null) ? (float) $d['capital_social'] : null,
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

            'cnae_principal_codigo' => $this->inteiro($d['cnae_fiscal'] ?? null),
            'cnae_principal_descricao' => $this->texto($d['cnae_fiscal_descricao'] ?? null),

            // Listas (CNAEs secundários e sócios) ficam numa coluna JSON. O mais
            // "normalizado" seria criar tabelas próprias
            // (empresa_socios, empresa_cnaes). Optei por JSON por simplicidade,
            // porque esses dados são sempre lidos junto com a empresa e não são
            // filtrados sozinhos.
            'atividades_secundarias' => $this->lista($d['cnaes_secundarios'] ?? null)
                // Sem CNAE secundário, a BrasilAPI devolve [{codigo: 0, descricao: ""}]
                // em vez de uma lista vazia. Esse item é descartado aqui.
                ->filter(fn (array $a) => $this->inteiro($a['codigo'] ?? null))
                ->map(fn (array $a) => [
                    'codigo' => $this->inteiro($a['codigo']),
                    'descricao' => $this->texto($a['descricao'] ?? null),
                ])
                ->values()
                ->all(),

            // QSA = Quadro de Sócios e Administradores.
            'socios' => $this->lista($d['qsa'] ?? null)
                ->map(fn (array $s) => [
                    'nome' => $this->texto($s['nome_socio'] ?? null),
                    'qualificacao' => $this->texto($s['qualificacao_socio'] ?? null),
                    'data_entrada' => $this->data($s['data_entrada_sociedade'] ?? null),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Tira os espaços das pontas e troca "" por null. Se não for texto ou número, vira null. */
    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /** Só aceita datas no formato AAAA-MM-DD, que é o da BrasilAPI. Qualquer outra coisa vira null. */
    private function data(mixed $valor): ?string
    {
        $valor = $this->texto($valor);

        return $valor !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;
    }

    private function inteiro(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /** Garante uma coleção só de objetos. Se vier um texto no lugar da lista, devolve uma coleção vazia. */
    private function lista(mixed $valor): \Illuminate\Support\Collection
    {
        return collect(is_array($valor) ? $valor : [])->filter(fn ($item) => is_array($item));
    }
}
