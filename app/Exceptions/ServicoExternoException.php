<?php

namespace App\Exceptions;

use Throwable;

/**
 * Falhas na comunicação com a BrasilAPI (Tarefa 3).
 *
 * Cada situação tem um método próprio que já define o status HTTP, o código e
 * a mensagem. Fica fácil ver todas as possibilidades num só lugar:
 *
 *  | Situação                                      | HTTP | codigo                          |
 *  |-----------------------------------------------|------|---------------------------------|
 *  | Passou do tempo limite (timeout)              | 504  | SERVICO_EXTERNO_TIMEOUT         |
 *  | Não conectou (DNS, rede, conexão recusada)    | 503  | SERVICO_EXTERNO_INDISPONIVEL    |
 *  | Limite de requisições da BrasilAPI (429)      | 503  | SERVICO_EXTERNO_LIMITE_EXCEDIDO |
 *  | BrasilAPI recusou o CNPJ (400)                | 422  | CNPJ_RECUSADO_SERVICO_EXTERNO   |
 *  | BrasilAPI deu erro (5xx ou outro status)      | 502  | SERVICO_EXTERNO_ERRO            |
 *  | Resposta sem sentido (não é JSON, sem campos) | 502  | RESPOSTA_EXTERNA_INVALIDA       |
 *
 * Por que códigos diferentes e não "500" para tudo?
 *   - 502 Bad Gateway: o serviço do qual dependemos respondeu algo errado.
 *   - 503 Service Unavailable: o serviço está fora do ar ou sobrecarregado agora. Vale tentar depois.
 *   - 504 Gateway Timeout: o serviço não respondeu a tempo.
 *   Todos dizem a mesma coisa: "o problema não é seu, cliente, nem é bug nosso".
 *   O 500 fica reservado para erro inesperado dentro da nossa aplicação.
 */
class ServicoExternoException extends ApiException
{
    public static function timeout(?Throwable $causa = null): self
    {
        return new self(
            'A BrasilAPI demorou demais para responder. Tente novamente em instantes.',
            504, 'SERVICO_EXTERNO_TIMEOUT', $causa,
        );
    }

    public static function indisponivel(?Throwable $causa = null): self
    {
        return new self(
            'Não foi possível conectar à BrasilAPI. O serviço pode estar fora do ar; tente novamente em instantes.',
            503, 'SERVICO_EXTERNO_INDISPONIVEL', $causa,
        );
    }

    /** @param string|null $retryAfter valor do cabeçalho Retry-After da BrasilAPI, repassado ao cliente */
    public static function limiteExcedido(?string $retryAfter = null): self
    {
        return new self(
            'A BrasilAPI recebeu requisições demais. Aguarde alguns instantes e tente novamente.',
            503, 'SERVICO_EXTERNO_LIMITE_EXCEDIDO',
            headers: $retryAfter ? ['Retry-After' => $retryAfter] : [],
        );
    }

    public static function cnpjRecusado(): self
    {
        return new self(
            'O serviço externo não aceitou o CNPJ informado.',
            422, 'CNPJ_RECUSADO_SERVICO_EXTERNO',
        );
    }

    public static function erro(int $statusExterno): self
    {
        return new self(
            'A BrasilAPI retornou um erro ao consultar o CNPJ. Tente novamente mais tarde.',
            502, 'SERVICO_EXTERNO_ERRO',
            contextoLog: ['status_externo' => $statusExterno],
        );
    }

    /** @param string $motivo detalhe técnico: vai só para o log, o cliente vê a mensagem genérica */
    public static function respostaInvalida(string $motivo): self
    {
        return new self(
            'A BrasilAPI retornou uma resposta em formato inesperado. Tente novamente mais tarde.',
            502, 'RESPOSTA_EXTERNA_INVALIDA',
            contextoLog: ['motivo' => $motivo],
        );
    }
}
