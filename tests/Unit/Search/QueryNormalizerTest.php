<?php

use App\Search\Services\QueryNormalizer;

test('normalize lowercases, strips punctuation and collapses spaces', function (string $input, string $expected) {
    expect((new QueryNormalizer)->normalize($input))->toBe($expected);
})->with([
    ['  Galaxy   NOVA ', 'galaxy nova'],
    ['T-Shirt, red!', 't-shirt red'],
    ['5.5 inch -screen-', '5.5 inch screen'],
    ['100% cotton (kids)', '100 cotton kids'],
    ['Café Crème', 'café crème'],
    ['!!! ', ''],
]);
