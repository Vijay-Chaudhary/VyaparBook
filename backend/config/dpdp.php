<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Current privacy notice version
    |--------------------------------------------------------------------------
    |
    | Recorded against every consent event. Bump this whenever the notice
    | materially changes: consent to a superseded policy is not consent to the
    | current one, so existing users will read as "stale" against the new
    | version rather than silently counting as having agreed to it.
    |
    | Dated rather than numbered so an audit can line a consent record up with
    | the notice that was live at the time.
    |
    */
    'policy_version' => env('DPDP_POLICY_VERSION', '2026-07-20'),
];
