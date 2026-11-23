<?php

use App\Http\Controllers\EmpresaController;
use Illuminate\Support\Facades\Route;

/*
| Rotas da API. Todas ganham o prefixo /api automaticamente (configurado em
| bootstrap/app.php). Para ver todas: php artisan route:list
*/

// Tarefa 1 - Sincronização e persistência cadastral
// Recebe o CNPJ, consulta a BrasilAPI e cria/atualiza o registro no banco.
Route::post('/empresas', [EmpresaController::class, 'sincronizar']);
