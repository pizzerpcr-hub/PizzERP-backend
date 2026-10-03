<?php

namespace Tests\Unit;

use App\Events\RoleAccessChanged;
use App\Events\UserAccessChanged;
use PHPUnit\Framework\TestCase;

class AccessChangedBroadcastTest extends TestCase
{
    public function test_role_event_sends_only_the_complete_access_snapshot(): void
    {
        $permissions = ['productos' => ['ver' => true]];
        $event = new RoleAccessChanged(3, 'TI', $permissions, 123);

        $this->assertSame([
            'rol_id' => 3,
            'rol' => 'TI',
            'permisos' => $permissions,
            'revision' => 123,
        ], $event->broadcastWith());
    }

    public function test_user_event_identifies_the_recipient_without_exposing_credentials(): void
    {
        $permissions = ['productos' => ['ver' => false]];
        $event = new UserAccessChanged(10, 3, 'TI', $permissions, 124);

        $this->assertSame([
            'user_id' => 10,
            'rol_id' => 3,
            'rol' => 'TI',
            'permisos' => $permissions,
            'revision' => 124,
        ], $event->broadcastWith());
    }
}
