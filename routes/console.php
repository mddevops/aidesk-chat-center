<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('aidesk:dispatch-chat-events')->everyMinute()->withoutOverlapping();

Schedule::command('aidesk:route-pending')->everyMinute()->withoutOverlapping();
