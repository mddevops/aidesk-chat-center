<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('aidesk:dispatch-chat-events')->everyMinute()->withoutOverlapping();
