<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('holds:expire')->everyMinute();
