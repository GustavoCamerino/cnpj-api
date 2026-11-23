<?php

namespace App\Rules;

use App\Support\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regra de validação do Laravel para CNPJ.
 *
 * Usar uma Rule (em vez de validar dentro do controller) deixa a validação
 * reutilizável: qualquer outro formulário ou endpoint que receba CNPJ pode usar
 * `new CnpjValido` nas suas regras.
 */
class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Cnpj::valido($value)) {
            $fail('O CNPJ informado é inválido.');
        }
    }
}
