<?php

use Illuminate\Support\Facades\Schedule;

// Webhook é o caminho principal; a reconciliação garante que nada fique pendurado.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();
