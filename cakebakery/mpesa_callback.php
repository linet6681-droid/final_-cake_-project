<?php
// Retired. Payments are no longer confirmed automatically: the customer submits the M-Pesa transaction code and the admin
// approves or declines it (Admin > Payments). This address does nothing so that no outside request can mark an order paid.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['ResultCode'=>1, 'ResultDesc'=>'Not used']);
