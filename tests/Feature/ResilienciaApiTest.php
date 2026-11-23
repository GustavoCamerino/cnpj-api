<?php

namespace Tests\Feature;

use App\Services\BrasilApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Tarefa 3: resiliência e tratamento de exceções.
 *
 * Simula com Http::fake() tudo o que pode dar errado na BrasilAPI e confere que a
 * nossa API sempre responde com o status certo, no formato padrão
 * { "message": ..., "codigo": ... }, sem expor detalhes internos e sem gravar nada.
 */
class ResilienciaApiTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        // Sem espera entre as tentativas, para os testes ficarem rápidos.
        config(['services.brasilapi.intervalo_ms' => 0]);
    }

    private function respostaValida(array $sobrescrever = []): array
    {
        return array_merge(['cnpj' => self::CNPJ, 'razao_social' => 'EMPRESA EXEMPLO LTDA'], $sobrescrever);
    }

    /** Envia o POST e confere o formato padrão de erro. */
    private function assertErroPadrao(int $status, string $codigo): \Illuminate\Testing\TestResponse
    {
        $resposta = $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])
            ->assertStatus($status)
            ->assertJsonPath('codigo', $codigo)
            ->assertJsonStructure(['message', 'codigo']);

        $this->assertNotEmpty($resposta->json('message'));
        $this->assertDatabaseCount('empresas', 0);

        return $resposta;
    }

    // ------------------------------------------------- falhas de comunicação

    public function test_timeout_retorna_504(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds')]);

        $this->assertErroPadrao(504, 'SERVICO_EXTERNO_TIMEOUT');
    }

    public function test_falha_de_conexao_retorna_503(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::failedConnection('cURL error 7: Failed to connect to brasilapi.com.br port 443: Connection refused')]);

        $this->assertErroPadrao(503, 'SERVICO_EXTERNO_INDISPONIVEL');
    }

    public function test_erro_5xx_tenta_de_novo_e_retorna_502_se_persistir(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response('Internal Server Error', 500)]);

        $this->assertErroPadrao(502, 'SERVICO_EXTERNO_ERRO');
        Http::assertSentCount(2); // 1 tentativa + 1 nova tentativa (configuração padrão)
    }

    public function test_erro_temporario_se_recupera_na_nova_tentativa(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::sequence()
            ->push('Service Unavailable', 503)
            ->push($this->respostaValida()),
        ]);

        $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])
            ->assertCreated()
            ->assertJsonPath('data.razao_social', 'EMPRESA EXEMPLO LTDA');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('empresas', 1);
    }

    public function test_cnpj_inexistente_nao_tenta_de_novo(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'CNPJ não encontrado'], 404)]);

        $this->assertErroPadrao(404, 'CNPJ_NAO_ENCONTRADO');
        Http::assertSentCount(1); // a resposta seria a mesma, então não vale repetir
    }

    public function test_limite_de_requisicoes_retorna_503_com_retry_after(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '30'])]);

        $this->assertErroPadrao(503, 'SERVICO_EXTERNO_LIMITE_EXCEDIDO')
            ->assertHeader('Retry-After', '30');
        Http::assertSentCount(1);
    }

    public function test_cnpj_recusado_pelo_servico_externo_retorna_422(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'CNPJ inválido'], 400)]);

        $this->assertErroPadrao(422, 'CNPJ_RECUSADO_SERVICO_EXTERNO');
    }

    // ------------------------------------------- respostas inválidas/inesperadas

    public function test_resposta_que_nao_e_json_retorna_502(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response('<html>Em manutenção</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertErroPadrao(502, 'RESPOSTA_EXTERNA_INVALIDA');
    }

    public function test_resposta_json_sem_campos_obrigatorios_retorna_502(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['status' => 'ok'])]);

        $this->assertErroPadrao(502, 'RESPOSTA_EXTERNA_INVALIDA');
    }

    public function test_resposta_de_outro_cnpj_retorna_502(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaValida(['cnpj' => '19131243000197']))]);

        $this->assertErroPadrao(502, 'RESPOSTA_EXTERNA_INVALIDA');
    }

    public function test_campos_com_tipo_inesperado_sao_ignorados_sem_quebrar(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaValida([
            'nome_fantasia' => ['não', 'é', 'texto'],
            'capital_social' => 'muito dinheiro',
            'data_inicio_atividade' => '03/11/2005',
            'qsa' => 'deveria ser uma lista',
            'cnaes_secundarios' => [123, ['codigo' => 'abc'], ['codigo' => 6204000, 'descricao' => 'Consultoria']],
        ]))]);

        $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])
            ->assertCreated()
            ->assertJsonPath('data.nome_fantasia', null)
            ->assertJsonPath('data.capital_social', null)
            ->assertJsonPath('data.data_inicio_atividade', null)
            ->assertJsonPath('data.socios', [])
            ->assertJsonPath('data.atividades_secundarias', [['codigo' => 6204000, 'descricao' => 'Consultoria']]);
    }

    // --------------------------------------------- erros inesperados e formato

    public function test_erro_inesperado_retorna_500_generico_sem_expor_detalhes(): void
    {
        $this->mock(BrasilApiService::class)
            ->shouldReceive('consultarCnpj')
            ->andThrow(new RuntimeException('SQLSTATE senha=segredo123 em /var/www/html/app/Services'));

        $resposta = $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Ocorreu um erro inesperado ao processar a requisição. Tente novamente mais tarde.',
                'codigo' => 'ERRO_INTERNO',
            ]);

        // Nada de stack trace, nome de classe, caminho de arquivo ou mensagem interna.
        $this->assertStringNotContainsString('segredo123', $resposta->getContent());
        $this->assertStringNotContainsString('RuntimeException', $resposta->getContent());
        $this->assertStringNotContainsString('/var/www', $resposta->getContent());
    }

    public function test_validacao_usa_o_formato_padrao(): void
    {
        $this->postJson('/api/empresas', ['cnpj' => '123'])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'DADOS_INVALIDOS')
            ->assertJsonPath('message', 'O CNPJ informado é inválido.')
            ->assertJsonPath('errors.cnpj.0', 'O CNPJ informado é inválido.');
    }

    public function test_rota_inexistente_retorna_404_no_formato_padrao(): void
    {
        $this->getJson('/api/nao-existe')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'ROTA_NAO_ENCONTRADA');
    }

    public function test_metodo_nao_permitido_retorna_405_no_formato_padrao(): void
    {
        $this->deleteJson('/api/empresas')
            ->assertStatus(405)
            ->assertJsonPath('codigo', 'METODO_NAO_PERMITIDO');
    }

    public function test_empresa_nao_cadastrada_usa_o_formato_padrao(): void
    {
        $this->getJson('/api/empresas/'.self::CNPJ)
            ->assertNotFound()
            ->assertJsonPath('codigo', 'EMPRESA_NAO_ENCONTRADA');
    }

    // ----------------------------------------------------------------- timeout

    public function test_timeouts_estao_configurados(): void
    {
        $this->assertGreaterThan(0, config('services.brasilapi.timeout'));
        $this->assertGreaterThan(0, config('services.brasilapi.connect_timeout'));
        $this->assertLessThanOrEqual(config('services.brasilapi.timeout'), config('services.brasilapi.connect_timeout'));
    }
}
