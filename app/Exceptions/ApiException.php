<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Exceção base dos erros "esperados" da API, ou seja, situações conhecidas e
 * tratadas de propósito (CNPJ não encontrado, serviço externo fora do ar...).
 *
 * Cada exceção já carrega o status HTTP, um código para programas e uma mensagem
 * para pessoas. O Laravel chama o render() sozinho, então o controller só
 * precisa de um `throw`, sem nenhum try/catch.
 */
class ApiException extends Exception
{
    public function __construct(
        string $mensagem,
        protected int $status = 500,
        protected string $codigo = 'ERRO_INTERNO',
        ?Throwable $previous = null,
        protected array $headers = [],
        protected array $contextoLog = [],
    ) {
        parent::__construct($mensagem, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    /** Monta a resposta JSON no formato padrão da API. */
    public function render(): JsonResponse
    {
        return ApiResponse::erro($this->getMessage(), $this->codigo, $this->status, headers: $this->headers);
    }

    /**
     * Controla o que vai para o log:
     *   - erros 4xx (ex.: CNPJ não encontrado) são comportamento normal e NÃO vão para o log;
     *   - erros 5xx (ex.: BrasilAPI fora do ar) vão. Retornar false manda o Laravel
     *     registrar a exceção do jeito padrão.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }

    /** Informações extras que aparecem no log, mas nunca na resposta ao consumidor. */
    public function context(): array
    {
        return ['codigo' => $this->codigo, ...$this->contextoLog];
    }
}
