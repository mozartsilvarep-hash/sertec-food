<?php

use App\Modules\Platform\Ops\HealthCheck;
use Illuminate\Support\Facades\Schedule;

// Batimento do agendador (verificado por ops:status e /internal/health).
Schedule::call(fn () => HealthCheck::beat())->everyMinute()->name('ops:heartbeat')->onOneServer();

// Webhook é o caminho principal; a reconciliação garante que nada fique pendurado.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();

// LGPD: retenção de dados pessoais de pedidos antigos (madrugada).
Schedule::command('privacy:anonymize')->dailyAt('04:10')->withoutOverlapping()->onOneServer();
