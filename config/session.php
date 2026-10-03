<?php

// Laravel's own sessions are not used: the shared schema's sessions table
// backs tadmor's (app/Services/Sessions.php). This keeps the framework from
// ever touching the database for one.
return [
    'driver' => 'array',
];
