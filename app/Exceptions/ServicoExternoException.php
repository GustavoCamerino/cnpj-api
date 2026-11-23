<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A API externa está fora do ar, demorou demais ou respondeu algo inesperado.
 *
 * Por que 502 (Bad Gateway) e não 500?
 *   -> O 502 diz que o erro não é da nossa aplicação nem de quem chamou: ele veio
 *      do serviço externo do qual dependemos. Isso ajuda no monitoramento e no
 *      suporte, e mostra que vale tentar de novo mais tarde.
 */
class ServicoExternoException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 502);
    }
}
