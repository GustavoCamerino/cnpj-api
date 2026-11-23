<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\SincronizarEmpresaRequest;
use App\Http\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Services\BrasilApiService;
use App\Support\Cnpj;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de empresas.
 *
 * O controller só "orquestra": a validação fica no Form Request, a integração
 * externa no BrasilApiService, os erros nas Exceptions e o formato da resposta no
 * EmpresaResource. Cada classe cuida de uma coisa só, e assim fica mais fácil de
 * entender, testar e trocar.
 */
class EmpresaController extends Controller
{
    /**
     * O Laravel injeta o BrasilApiService sozinho (injeção de dependência).
     * Nos testes, o Http::fake() faz esse service responder dados falsos.
     */
    public function __construct(private readonly BrasilApiService $brasilApi)
    {
    }

    /**
     * TAREFA 1 - POST /api/empresas   { "cnpj": "11.222.333/0001-81" }
     *
     * Passo a passo:
     *   1. O SincronizarEmpresaRequest tira a máscara e valida o CNPJ (se for inválido: 422).
     *   2. Consulta a BrasilAPI (se não achar: 404; se o serviço falhar: 502/503/504,
     *      veja ServicoExternoException). Os erros são exceções que já sabem se
     *      transformar em JSON, por isso não há try/catch aqui.
     *   3. Estrutura os dados.
     *   4. Grava no banco: cria se o CNPJ for novo ou atualiza se já existir.
     *   5. Devolve o JSON. Status 201 quando criou e 200 quando atualizou.
     */
    public function sincronizar(SincronizarEmpresaRequest $request): JsonResponse
    {
        // Aqui o CNPJ já está limpo (só os 14 caracteres) e validado.
        $cnpj = $request->validated('cnpj');

        $dadosBrutos = $this->brasilApi->consultarCnpj($cnpj);
        $dados = $this->brasilApi->estruturar($dadosBrutos);
        $dados['sincronizado_em'] = now();

        $empresa = $this->salvar($cnpj, $dados);
        $criada = $empresa->wasRecentlyCreated;

        return (new EmpresaResource($empresa))
            // Campo extra para quem chamou saber o que aconteceu.
            ->additional(['operacao' => $criada ? 'criado' : 'atualizado'])
            ->response()
            ->setStatusCode($criada ? 201 : 200);
    }

    /**
     * REGRA DE IDEMPOTÊNCIA: se o mesmo CNPJ for enviado de novo, o registro
     * existente é ATUALIZADO e nunca duplicado.
     *
     * O updateOrCreate procura pelo CNPJ: se achar, atualiza; se não achar, cria.
     *
     * Ponto para debate (requisições ao mesmo tempo): se duas requisições com o
     * mesmo CNPJ novo chegarem juntas, as duas podem não encontrar o registro e
     * tentar criar. O índice UNIQUE do banco deixa só uma conseguir. A outra
     * recebe UniqueConstraintViolationException, e nesse caso tentamos de novo.
     * Na segunda tentativa o registro já existe, então vira uma atualização.
     * Resultado: nunca há duplicidade e nenhuma das requisições dá erro.
     */
    private function salvar(string $cnpj, array $dados): Empresa
    {
        try {
            return Empresa::updateOrCreate(['cnpj' => $cnpj], $dados);
        } catch (UniqueConstraintViolationException) {
            return Empresa::updateOrCreate(['cnpj' => $cnpj], $dados);
        }
    }

    /**
     * TAREFA 2 - GET /api/empresas
     * Lista as empresas gravadas no banco local, sem consultar a API externa.
     *
     * Usa paginação (15 por página, ?per_page= até 100) para a resposta não ficar
     * enorme quando houver muitas empresas. A resposta traz `data` (as empresas),
     * `links` e `meta` (total, página atual etc.).
     * Exemplo: GET /api/empresas?page=2&per_page=50
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $porPagina = min(max((int) $request->query('per_page', 15), 1), 100);

        $empresas = Empresa::query()
            ->orderBy('razao_social')
            ->paginate($porPagina)
            ->withQueryString(); // mantém o per_page nos links de próxima/anterior

        return EmpresaResource::collection($empresas);
    }

    /**
     * GET /api/empresas/{cnpj}
     * Busca uma empresa no banco local, sem consultar a API externa.
     *
     * O CNPJ pode vir com ou sem pontos e hífen. A barra "/" da máscara não
     * funciona na URL (ela separa as partes do caminho), então ela não deve ser
     * enviada aqui. Ex.: /api/empresas/11222333000181 ou /api/empresas/11.222.333.0001-81
     */
    public function show(string $cnpj): EmpresaResource
    {
        $cnpj = Cnpj::limpar($cnpj);

        // Os erros são lançados como exceção para sair no formato padrão da API
        // (o mesmo do POST): { "message": ..., "codigo": ... }
        if (! Cnpj::valido($cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'O CNPJ informado é inválido.']);
        }

        $empresa = Empresa::where('cnpj', $cnpj)->first();

        if (! $empresa) {
            throw new ApiException('Empresa não cadastrada no banco local.', 404, 'EMPRESA_NAO_ENCONTRADA');
        }

        return new EmpresaResource($empresa);
    }
}
