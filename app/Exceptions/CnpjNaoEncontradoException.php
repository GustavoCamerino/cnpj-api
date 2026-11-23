<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * O CNPJ é válido no formato, mas não existe na base da Receita Federal.
 *
 * O método render() é chamado pelo Laravel automaticamente quando a exceção não
 * é tratada. Então o controller não precisa de try/catch: basta lançar a exceção
 * e a resposta 404 já sai pronta.
 */
class CnpjNaoEncontradoException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
