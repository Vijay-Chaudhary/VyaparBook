<?php

return [
    // Deliberately identical for unknown-email and wrong-password: saying
    // which is wrong tells an attacker whether an account exists.
    'failed' => 'Those credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'sign_in' => 'Sign in',
    'sign_out' => 'Sign out',
    'sign_in_subtitle' => 'Sign in to your shop account',
    'email' => 'Email',
    'password' => 'Password',
    'remember_me' => 'Keep me signed in',
    'fix_errors' => 'Please fix the following:',
];
