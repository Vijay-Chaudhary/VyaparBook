<?php

it('resolves the tenant bindings to null outside a request', function () {
    expect(app('tenant.id'))->toBeNull();
    expect(app('tenant.role'))->toBeNull();
    expect(app('tenant.user_id'))->toBeNull();
});
