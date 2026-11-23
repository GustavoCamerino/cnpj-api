<?php

namespace App\Exceptions;

/**
 * O CNPJ é válido no formato, mas não existe na base da Receita Federal.
 * A BrasilAPI respondeu 404 e nós repassamos 404.
 */
class CnpjNaoEncontradoException extends ApiException
{
    public function __construct()
    {
        parent::__construct('CNPJ não encontrado na base da Receita Federal.', 404, 'CNPJ_NAO_ENCONTRADO');
    }
}
