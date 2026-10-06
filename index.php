<?php
// Root router: Automatically redirect incoming traffic to the POS application entry point
header('Location: POS/index.html', true, 302);
exit;
