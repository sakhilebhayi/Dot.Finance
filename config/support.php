<?php

return [

    /*
    | Where "Report this problem" on an error page sends a person. Leave empty and the link goes to InfoDot instead.
    | Set SUPPORT_EMAIL on the host once there is a monitored mailbox (R-44 polish, an owner step).
    */
    'email' => env('SUPPORT_EMAIL'),

];
