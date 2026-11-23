<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tarefa 5: "os testes não deverão realizar requisições HTTP reais".
        // Com isso, QUALQUER chamada HTTP que não tenha sido simulada com Http::fake()
        // lança uma exceção na hora. Se alguém esquecer o fake num teste novo, o teste
        // quebra em vez de chamar a BrasilAPI de verdade.
        Http::preventStrayRequests();
    }
}
