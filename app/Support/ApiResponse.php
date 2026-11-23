<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Monta TODAS as respostas de erro da API no mesmo formato (Tarefa 3).
 *
 *   {
 *     "message": "Texto que o consumidor da API consegue entender.",
 *     "codigo":  "CODIGO_PARA_O_PROGRAMA_TRATAR",
 *     "errors":  { "cnpj": ["..."] }      <- só nos erros de validação (422)
 *   }
 *
 * Por que dois campos?
 *   - "message" é para pessoas: pode ser exibido direto na tela do sistema que usa a API.
 *   - "codigo" é para programas: o front-end pode fazer `if (codigo === 'CNPJ_NAO_ENCONTRADO')`
 *     sem depender do texto, que pode mudar ou ser traduzido.
 *
 * Mantive "message" e "errors" com os nomes que o Laravel já usa nos erros de
 * validação. Assim todos os erros, inclusive os do framework, ficam iguais.
 */
final class ApiResponse
{
    public static function erro(string $mensagem, string $codigo, int $status, array $extras = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['message' => $mensagem, 'codigo' => $codigo, ...$extras],
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
