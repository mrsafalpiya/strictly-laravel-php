<?php

test('strings can be upper-cased', function () {
    expect(str('strictly')->upper()->toString())->toBe('STRICTLY');
});
