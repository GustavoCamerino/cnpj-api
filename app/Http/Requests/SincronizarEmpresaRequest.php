<?php

namespace App\Http\Requests;

use App\Rules\CnpjValido;
use App\Support\Cnpj;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a entrada do POST /api/empresas.
 *
 * O Form Request roda ANTES do controller. Se a validação falhar, o Laravel já
 * devolve 422 com as mensagens de erro em JSON e o controller nem é chamado.
 * Assim o controller fica só com a regra de negócio.
 *
 * Corpo esperado: { "cnpj": "11.222.333/0001-81" } (a máscara é opcional)
 */
class SincronizarEmpresaRequest extends FormRequest
{
    /** Por enquanto o endpoint é público. Se for preciso, a autenticação entra aqui. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Roda antes das regras: tira a máscara do CNPJ.
     * Daqui em diante, `$request->validated('cnpj')` já devolve o CNPJ limpo
     * (14 caracteres), que é o formato usado na consulta e no banco.
     */
    protected function prepareForValidation(): void
    {
        // is_string: se mandarem um array ou um número no campo, o valor não é
        // mexido aqui e a regra 'string' lá embaixo recusa.
        if (is_string($this->input('cnpj'))) {
            $this->merge(['cnpj' => Cnpj::limpar($this->input('cnpj'))]);
        }
    }

    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', new CnpjValido],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.required' => 'Informe o CNPJ.',
            'cnpj.string' => 'O CNPJ deve ser enviado como texto.',
        ];
    }
}
