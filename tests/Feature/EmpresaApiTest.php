<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Testes de ponta a ponta dos endpoints.
 *
 * - RefreshDatabase: cada teste começa com o banco vazio (é um SQLite em memória,
 *   configurado no phpunit.xml).
 * - Http::fake(): troca a BrasilAPI real por respostas falsas. Os testes não usam
 *   a internet, são rápidos e sempre dão o mesmo resultado.
 */
class EmpresaApiTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11222333000181';

    /** Resposta falsa no mesmo formato da BrasilAPI. */
    private function respostaBrasilApi(array $sobrescrever = []): array
    {
        return array_merge([
            'cnpj' => self::CNPJ,
            'razao_social' => 'EMPRESA EXEMPLO LTDA',
            'nome_fantasia' => '',
            'descricao_situacao_cadastral' => 'ATIVA',
            'data_situacao_cadastral' => '2005-11-03',
            'data_inicio_atividade' => '2005-11-03',
            'natureza_juridica' => 'Sociedade Empresária Limitada',
            'porte' => 'DEMAIS',
            'capital_social' => 150000.5,
            'ddd_telefone_1' => '6533334444',
            'email' => null,
            'descricao_tipo_de_logradouro' => 'RUA',
            'logradouro' => 'DAS FLORES',
            'numero' => '100',
            'complemento' => '',
            'bairro' => 'CENTRO',
            'municipio' => 'BARRA DO GARCAS',
            'uf' => 'MT',
            'cep' => '78600000',
            'cnae_fiscal' => 6201501,
            'cnae_fiscal_descricao' => 'Desenvolvimento de programas de computador sob encomenda',
            'cnaes_secundarios' => [['codigo' => 0, 'descricao' => '']],
            'qsa' => [[
                'nome_socio' => 'FULANO DE TAL',
                'qualificacao_socio' => 'Sócio-Administrador',
                'data_entrada_sociedade' => '2005-11-03',
            ]],
        ], $sobrescrever);
    }

    // ---------------------------------------------------------------- Tarefa 1

    public function test_cnpj_com_mascara_cria_empresa_e_retorna_201(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $this->postJson('/api/empresas', ['cnpj' => '11.222.333/0001-81'])
            ->assertCreated()
            ->assertJsonPath('operacao', 'criado')
            ->assertJsonPath('data.cnpj', self::CNPJ)
            ->assertJsonPath('data.cnpj_formatado', '11.222.333/0001-81')
            ->assertJsonPath('data.razao_social', 'EMPRESA EXEMPLO LTDA')
            ->assertJsonPath('data.nome_fantasia', null)                  // "" virou null
            ->assertJsonPath('data.endereco.logradouro', 'RUA DAS FLORES') // tipo + nome
            ->assertJsonPath('data.endereco.uf', 'MT')
            ->assertJsonPath('data.atividades_secundarias', [])           // {codigo: 0} foi descartado
            ->assertJsonPath('data.socios.0.nome', 'FULANO DE TAL');

        // A consulta externa foi feita com o CNPJ SEM máscara.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://brasilapi.com.br/api/cnpj/v1/'.self::CNPJ);

        $this->assertDatabaseCount('empresas', 1);
    }

    public function test_reenviar_mesmo_cnpj_atualiza_sem_duplicar(): void
    {
        // 1º envio: com máscara, cria o registro.
        Http::fake(['brasilapi.com.br/*' => Http::sequence()
            ->push($this->respostaBrasilApi())
            ->push($this->respostaBrasilApi(['razao_social' => 'NOVO NOME LTDA'])),
        ]);
        $this->postJson('/api/empresas', ['cnpj' => '11.222.333/0001-81'])->assertCreated();

        // 2º envio: o mesmo CNPJ, agora sem máscara. Atualiza o registro.
        $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])
            ->assertOk()
            ->assertJsonPath('operacao', 'atualizado')
            ->assertJsonPath('data.razao_social', 'NOVO NOME LTDA');

        // Continua com um único registro, e com os dados novos.
        $this->assertDatabaseCount('empresas', 1);
        $this->assertSame('NOVO NOME LTDA', Empresa::first()->razao_social);
    }

    public function test_cnpj_invalido_retorna_422_sem_chamar_api_externa(): void
    {
        Http::fake();

        $this->postJson('/api/empresas', ['cnpj' => '11.222.333/0001-82'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cnpj');

        $this->postJson('/api/empresas', [])->assertUnprocessable();
        $this->postJson('/api/empresas', ['cnpj' => ['array']])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('empresas', 0);
    }

    public function test_cnpj_inexistente_na_receita_retorna_404(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'CNPJ não encontrado'], 404)]);

        $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])->assertNotFound();
        $this->assertDatabaseCount('empresas', 0);
    }

    public function test_falha_da_api_externa_retorna_502(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response('erro', 500)]);

        $this->postJson('/api/empresas', ['cnpj' => self::CNPJ])->assertStatus(502);
        $this->assertDatabaseCount('empresas', 0);
    }

    // ---------------------------------------------------------------- Tarefa 2

    public function test_listagem_retorna_empresas_do_banco_paginadas(): void
    {
        Http::fake(); // garante que a listagem NÃO consulta a API externa

        Empresa::create(['cnpj' => '11222333000181', 'razao_social' => 'BETA LTDA']);
        Empresa::create(['cnpj' => '19131243000197', 'razao_social' => 'ALFA SA']);

        $this->getJson('/api/empresas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.razao_social', 'ALFA SA') // ordenada por razão social
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/empresas?per_page=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 2);

        Http::assertNothingSent();
    }

    public function test_listagem_vazia(): void
    {
        $this->getJson('/api/empresas')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_busca_uma_empresa_pelo_cnpj(): void
    {
        Empresa::create(['cnpj' => self::CNPJ, 'razao_social' => 'EMPRESA EXEMPLO LTDA']);

        $this->getJson('/api/empresas/'.self::CNPJ)
            ->assertOk()
            ->assertJsonPath('data.razao_social', 'EMPRESA EXEMPLO LTDA');

        $this->getJson('/api/empresas/11.222.333.0001-81')->assertOk(); // pontos/hífen são aceitos
        $this->getJson('/api/empresas/19131243000197')->assertNotFound();
        $this->getJson('/api/empresas/123')->assertUnprocessable();
    }
}
