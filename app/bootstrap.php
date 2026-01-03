<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

hb_start_session();
hb_send_security_headers();

require_once __DIR__ . '/domain.php';

hb_require_csrf();
