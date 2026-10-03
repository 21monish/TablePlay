<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\{Device, User};
use App\Services\BroadcastChannelAccessService;

$access = fn (User|Device $actor, string $channel) => app(BroadcastChannelAccessService::class)->allows($actor, $channel);

Broadcast::channel('table.{tableId}', fn (User|Device $actor, int $tableId) => $access($actor, 'table.'.$tableId));
Broadcast::channel('counter', fn (User|Device $actor) => $access($actor, 'counter'));
Broadcast::channel('kitchen', fn (User|Device $actor) => $access($actor, 'kitchen'));
Broadcast::channel('staff.{userId}', fn (User|Device $actor, int $userId) => $access($actor, 'staff.'.$userId));
