<?php

return [
    // Only abilities starting with this prefix belong to this package.
    // Other Gates and model policies continue to be handled by Laravel.
    'gate_prefix' => 'permission:',
    // Authorization scope is explicit; it is not inferred from the auth guard.
    'scopes' => ['web', 'app'],
    // 'single' allows one role per user per scope; 'multiple' combines roles.
    'role_mode' => 'multiple',
];
