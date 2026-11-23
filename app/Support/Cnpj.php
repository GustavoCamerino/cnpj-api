<?php

namespace App\Support;

/**
 * Funções utilitárias de CNPJ: limpar a máscara, validar e formatar.
 *
 * Requisitos da Tarefa 1 atendidos aqui:
 *   - "Receber e validar o CNPJ informado."
 *   - "Remover caracteres de formatação antes de realizar a consulta."
 *
 * Por que validar aqui se a BrasilAPI já diz se o CNPJ existe?
 *   -> Para não gastar uma chamada de rede (lenta, e a BrasilAPI tem limite de
 *      requisições) com uma entrada que já dá para recusar na hora, como "123"
 *      ou um CNPJ com o dígito verificador errado. O erro volta mais rápido e
 *      fica mais claro para quem chamou.
 *
 * Suporte ao CNPJ ALFANUMÉRICO:
 *   Desde julho/2026 a Receita Federal (IN RFB nº 2.229/2024) emite CNPJs em que
 *   as 12 primeiras posições podem ter LETRAS (A-Z). Os 2 últimos caracteres
 *   (os dígitos verificadores) continuam numéricos. Ex.: 12.ABC.345/01DE-35
 *   O cálculo continua sendo o módulo 11. A diferença é que cada caractere passa
 *   a valer (código ASCII - 48): '0'..'9' continuam valendo 0..9 e 'A'..'Z'
 *   passam a valer 17..42. Assim o mesmo algoritmo serve para os CNPJs antigos
 *   e para os novos.
 *
 * Por que uma classe com métodos estáticos e não um Service?
 *   -> São funções puras (sem banco, sem HTTP, sem estado). Assim dá para usar
 *      em qualquer lugar (Form Request, Resource, testes) sem injeção de dependência.
 */
final class Cnpj
{
    /** Pesos oficiais do cálculo do 1º dígito verificador. */
    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Pesos oficiais do cálculo do 2º dígito verificador. */
    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Remove a máscara: pontos, barra, hífen e espaços. Também converte para
     * maiúsculo (o CNPJ alfanumérico "12abc..." vira "12ABC...").
     *
     * Aceita "11.222.333/0001-81" e "11222333000181" (as duas viram a segunda).
     *
     * Decisão: remove SÓ os caracteres de máscara e não "tudo que não for
     * número". Se chegar algo como "11#222...", a validação recusa em vez de a
     * gente corrigir a entrada sem avisar ninguém.
     */
    public static function limpar(?string $cnpj): string
    {
        return strtoupper(preg_replace('/[.\-\/\s]/', '', (string) $cnpj));
    }

    /**
     * Diz se o CNPJ é válido. A máscara é opcional, porque o CNPJ é limpo antes de validar.
     */
    public static function valido(?string $cnpj): bool
    {
        $c = self::limpar($cnpj);

        // 1) Tamanho e caracteres: 12 alfanuméricos + 2 dígitos numéricos.
        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c)) {
            return false;
        }

        // 2) Sequências repetidas (00000000000000, 11111111111111...) passam no
        //    cálculo dos dígitos, mas não existem de verdade. São recusadas à parte.
        if (count(array_unique(str_split($c))) === 1) {
            return false;
        }

        // 3) Confere os dígitos verificadores. O 2º dígito é calculado sobre os
        //    12 primeiros caracteres mais o 1º dígito.
        $base = substr($c, 0, 12);
        $dv1 = self::calcularDigito($base, self::PESOS_DV1);
        $dv2 = self::calcularDigito($base.$dv1, self::PESOS_DV2);

        return substr($c, 12, 2) === "{$dv1}{$dv2}";
    }

    /**
     * Aplica a máscara XX.XXX.XXX/XXXX-XX. Serve só para exibir o CNPJ, porque no
     * banco ele fica sempre sem máscara.
     */
    public static function formatar(string $cnpj): string
    {
        $c = self::limpar($cnpj);

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($c, 0, 2), substr($c, 2, 3), substr($c, 5, 3), substr($c, 8, 4), substr($c, 12, 2)
        );
    }

    /** Calcula um dígito verificador pelo módulo 11. */
    private static function calcularDigito(string $base, array $pesos): int
    {
        $soma = 0;
        foreach (str_split($base) as $i => $caractere) {
            // ord('0') = 48, então '0' vale 0, '9' vale 9 e 'A' vale 17.
            $soma += (ord($caractere) - 48) * $pesos[$i];
        }

        $resto = $soma % 11;

        // Regra oficial: se o resto for 0 ou 1, o dígito é 0. Senão, é 11 - resto.
        return $resto < 2 ? 0 : 11 - $resto;
    }
}
