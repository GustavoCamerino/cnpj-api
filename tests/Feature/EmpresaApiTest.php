<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Testes de ponta a ponta dos endpoints.
 *
 * - RefreshDatabase: cada teste começa com o banco vazio (é um SQLite em memória,
 *   configurado no phpunit.xml).
 * - Http::fake(): troca a BrasilAPI real por respostas falsas. Os testes não usam
 *   a internet, são rápidos e sempre dão o mesmo resultado. Além disso, o
 *   Http::preventStrayRequests() (tests/TestCase.php) faz falhar qualquer
 *   requisição que não tenha sido simulada.
 *
 * Requisitos mínimos da Tarefa 5 e os testes que cobrem cada um:
 *   1. Sincronização bem-sucedida + persistência -> test_cnpj_com_mascara_cria_empresa_e_retorna_201
 *                                                   test_sincronizacao_persiste_os_dados_no_banco
 *   2. CNPJ inválido                             -> test_cnpj_invalido_retorna_422_sem_chamar_api_externa
 *   3. Erro externo / registro não encontrado    -> test_cnpj_inexistente_na_receita_retorna_404
 *                                                   test_falha_da_api_externa_retorna_502
 *                                                   (e os 17 cenários de ResilienciaApiTest)
 *   4. Idempotência                              -> test_reenviar_mesmo_cnpj_atualiza_sem_duplicar
 *                                                   test_varios_envios_do_mesmo_cnpj_mantem_um_unico_registro
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

    public function test_varios_envios_do_mesmo_cnpj_mantem_um_unico_registro(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        // Mesmo CNPJ escrito de 4 jeitos diferentes, enviado em momentos diferentes.
        $formatos = ['11.222.333/0001-81', '11222333000181', ' 11.222.333/0001-81 ', '11222333/000181'];
        $respostas = [];
        foreach ($formatos as $formato) {
            $respostas[] = $this->postJson('/api/empresas', ['cnpj' => $formato])->assertSuccessful();
            $this->travel(1)->minutes();
        }

        // Só o primeiro cria. Os outros atualizam o MESMO registro (mesmo id).
        $this->assertSame([201, 200, 200, 200], array_map(fn ($r) => $r->status(), $respostas));
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r->json('data.id'), $respostas)));
        $this->assertDatabaseCount('empresas', 1);
        Http::assertSentCount(4); // cada envio consultou a API de novo (sincronização)

        // A data de criação não muda. A data da última sincronização acompanha o último envio.
        $empresa = Empresa::first();
        $this->assertTrue($empresa->sincronizado_em->greaterThan($empresa->created_at));
        $this->assertSame($respostas[0]->json('data.criado_em'), $respostas[3]->json('data.criado_em'));
    }

    public function test_sincronizacao_persiste_os_dados_no_banco(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $this->postJson('/api/empresas', ['cnpj' => '11.222.333/0001-81'])->assertCreated();

        // Confere direto na tabela, e não só na resposta: o dado foi mesmo gravado,
        // já estruturado e com o CNPJ sem máscara.
        $this->assertDatabaseHas('empresas', [
            'cnpj' => self::CNPJ,
            'razao_social' => 'EMPRESA EXEMPLO LTDA',
            'nome_fantasia' => null,
            'situacao_cadastral' => 'ATIVA',
            'logradouro' => 'RUA DAS FLORES',
            'municipio' => 'BARRA DO GARCAS',
            'uf' => 'MT',
            'cep' => '78600000',
            'cnae_principal_codigo' => 6201501,
        ]);

        $empresa = Empresa::firstWhere('cnpj', self::CNPJ);
        $this->assertSame('2005-11-03', $empresa->data_inicio_atividade->format('Y-m-d'));
        $this->assertEquals(150000.50, (float) $empresa->capital_social);
        $this->assertSame([], $empresa->atividades_secundarias);
        $this->assertSame('FULANO DE TAL', $empresa->socios[0]['nome']);
        $this->assertNotNull($empresa->sincronizado_em);
    }

    /**
     * Requisito 2 da Tarefa 5. Cada caso recebe 422 no formato padrão, a API externa
     * NÃO é chamada (o Http::fake() vazio + assertNothingSent prova isso) e nada é gravado.
     */
    #[DataProvider('cnpjsInvalidos')]
    public function test_cnpj_invalido_retorna_422_sem_chamar_api_externa(array $corpo): void
    {
        Http::fake();

        $this->postJson('/api/empresas', $corpo)
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'DADOS_INVALIDOS')
            ->assertJsonValidationErrors('cnpj');

        Http::assertNothingSent();
        $this->assertDatabaseCount('empresas', 0);
    }

    public static function cnpjsInvalidos(): array
    {
        return [
            'dígito verificador errado' => [['cnpj' => '11.222.333/0001-82']],
            'curto demais' => [['cnpj' => '123']],
            'longo demais' => [['cnpj' => '112223330001811']],
            'todos os dígitos iguais' => [['cnpj' => '00.000.000/0000-00']],
            'caractere inválido' => [['cnpj' => '11.222.333/0001-8#']],
            'texto vazio' => [['cnpj' => '']],
            'campo ausente' => [[]],
            'nulo' => [['cnpj' => null]],
            'número em vez de texto' => [['cnpj' => 11222333000181]],
            'lista em vez de texto' => [['cnpj' => ['11222333000181']]],
        ];
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
