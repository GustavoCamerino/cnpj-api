<?php

namespace Tests\Unit;

use App\Support\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes da limpeza e validação de CNPJ. São funções puras, então não precisam
 * do Laravel nem de banco e rodam bem rápido.
 */
class CnpjTest extends TestCase
{
    public function test_remove_a_mascara(): void
    {
        $this->assertSame('11222333000181', Cnpj::limpar('11.222.333/0001-81'));
        $this->assertSame('11222333000181', Cnpj::limpar(' 11222333000181 '));
        $this->assertSame('12ABC34501DE35', Cnpj::limpar('12.abc.345/01de-35'));
    }

    public function test_aplica_a_mascara(): void
    {
        $this->assertSame('11.222.333/0001-81', Cnpj::formatar('11222333000181'));
    }

    #[DataProvider('cnpjsValidos')]
    public function test_aceita_cnpjs_validos(string $cnpj): void
    {
        $this->assertTrue(Cnpj::valido($cnpj));
    }

    public static function cnpjsValidos(): array
    {
        return [
            'sem máscara' => ['11222333000181'],
            'com máscara' => ['11.222.333/0001-81'],
            'alfanumérico (novo formato)' => ['12.ABC.345/01DE-35'],
            'alfanumérico minúsculo' => ['12abc34501de35'],
        ];
    }

    #[DataProvider('cnpjsInvalidos')]
    public function test_recusa_cnpjs_invalidos(string $cnpj): void
    {
        $this->assertFalse(Cnpj::valido($cnpj));
    }

    public static function cnpjsInvalidos(): array
    {
        return [
            'vazio' => [''],
            'curto' => ['123'],
            'longo' => ['112223330001811'],
            'dígito verificador errado' => ['11222333000182'],
            'todos iguais' => ['00000000000000'],
            'letra no dígito verificador' => ['12ABC34501DE3A'],
            'caractere estranho' => ['11#222333000181'],
        ];
    }
}
