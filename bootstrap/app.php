<?php

use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |----------------------------------------------------------------------
        | Tratamento central de erros da API (Tarefa 3)
        |----------------------------------------------------------------------
        | Objetivo: NENHUMA exceção chega "crua" ao consumidor da API (stack
        | trace, nome de classe, SQL...). Todo erro em /api/* sai no mesmo
        | formato de ApiResponse::erro():  { "message": ..., "codigo": ... }
        |
        | Os nossos erros "esperados" (ApiException e as filhas dela) já sabem se
        | renderizar sozinhos. Aqui é tratado o resto: erros do próprio Laravel e
        | qualquer coisa inesperada.
        |
        | A ordem importa: o Laravel usa o primeiro callback que devolver uma resposta.
        */
        $ehApi = fn (Request $request) => $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $ehApi($request) || $request->expectsJson(),
        );

        // 422: dados de entrada inválidos (ex.: CNPJ faltando ou com dígito errado).
        $exceptions->render(function (ValidationException $e, Request $request) use ($ehApi) {
            if (! $ehApi($request)) {
                return null;
            }

            return ApiResponse::erro(
                collect($e->errors())->flatten()->first() ?? 'Os dados enviados são inválidos.',
                'DADOS_INVALIDOS',
                422,
                ['errors' => $e->errors()],
            );
        });

        // 404: rota que não existe (ex.: GET /api/nao-existe).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($ehApi) {
            return $ehApi($request)
                ? ApiResponse::erro('Recurso não encontrado. Confira a URL.', 'ROTA_NAO_ENCONTRADA', 404)
                : null;
        });

        // 405: rota existe, mas não com esse método (ex.: DELETE /api/empresas).
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($ehApi) {
            return $ehApi($request)
                ? ApiResponse::erro('Método HTTP não permitido para esta rota.', 'METODO_NAO_PERMITIDO', 405, headers: $e->getHeaders())
                : null;
        });

        // Último recurso: qualquer outra exceção.
        $exceptions->render(function (Throwable $e, Request $request) use ($ehApi) {
            // Respostas que o próprio código montou de propósito passam direto.
            if (! $ehApi($request) || $e instanceof HttpResponseException) {
                return null;
            }

            // Outros erros HTTP do framework (ex.: 429 do throttle): mantém o status.
            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::erro(
                    'Não foi possível processar a requisição.',
                    'ERRO_HTTP_'.$e->getStatusCode(),
                    $e->getStatusCode(),
                    headers: $e->getHeaders(),
                );
            }

            // Erro inesperado (bug, banco fora do ar...). O detalhe técnico vai para o
            // LOG (o Laravel registra sozinho), e o cliente recebe uma mensagem genérica.
            // Isso vale mesmo com APP_DEBUG=true: mostrar a stack trace para o
            // consumidor expõe detalhes internos da aplicação.
            return ApiResponse::erro(
                'Ocorreu um erro inesperado ao processar a requisição. Tente novamente mais tarde.',
                'ERRO_INTERNO',
                500,
            );
        });
    })->create();
