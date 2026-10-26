<?php

use App\Shared\ValueObjects\Money;

test('formats positive amounts with Indian digit grouping', function () {
    // 1. User specified test cases
    expect(Money::fromRupees(2365669)->format())->toBe('₹23,65,669.00')
        ->and(Money::fromPaise(236566900)->format())->toBe('₹23,65,669.00')
        ->and(Money::fromRupees(100000)->format())->toBe('₹1,00,000.00')
        ->and(Money::fromPaise(10000000)->format())->toBe('₹1,00,000.00')
        ->and(Money::fromRupees(999)->format())->toBe('₹999.00')
        ->and(Money::fromPaise(99900)->format())->toBe('₹999.00');

    // 2. Additional grouping boundaries
    expect(Money::fromRupees(0)->format())->toBe('₹0.00')
        ->and(Money::fromRupees(10)->format())->toBe('₹10.00')
        ->and(Money::fromRupees(100)->format())->toBe('₹100.00')
        ->and(Money::fromRupees(1000)->format())->toBe('₹1,000.00')
        ->and(Money::fromRupees(10000)->format())->toBe('₹10,000.00')
        ->and(Money::fromRupees(1000000)->format())->toBe('₹10,00,000.00')     // 10 lakhs
        ->and(Money::fromRupees(10000000)->format())->toBe('₹1,00,00,000.00');  // 1 crore
});

test('formats negative amounts with minus sign before rupee symbol', function () {
    // User specified test case
    expect(Money::fromRupees(-1234)->format())->toBe('−₹1,234.00')
        ->and(Money::fromPaise(-123400)->format())->toBe('−₹1,234.00');

    // Additional negative cases
    expect(Money::fromPaise(-50)->format())->toBe('−₹0.50')
        ->and(Money::fromPaise(-10000000)->format())->toBe('−₹1,00,000.00')
        ->and(Money::fromRupees(-10000000)->format())->toBe('−₹1,00,00,000.00');
});

test('formats paise fractions accurately', function () {
    expect(Money::fromPaise(50)->format())->toBe('₹0.50')
        ->and(Money::fromPaise(5)->format())->toBe('₹0.05')
        ->and(Money::fromPaise(236566975)->format())->toBe('₹23,65,669.75')
        ->and(Money::fromPaise(-123450)->format())->toBe('−₹1,234.50');
});

test('getFormatted and toString return identical Indian formatted representation', function () {
    $money = Money::fromRupees(2365669);

    expect($money->getFormatted())->toBe('₹23,65,669.00')
        ->and((string) $money)->toBe('₹23,65,669.00');

    $negMoney = Money::fromRupees(-1234);
    expect($negMoney->getFormatted())->toBe('−₹1,234.00')
        ->and((string) $negMoney)->toBe('−₹1,234.00');
});

test('money value object integer arithmetic remains exact', function () {
    $m1 = Money::fromPaise(50000); // ₹500.00
    $m2 = Money::fromPaise(25000); // ₹250.00

    expect($m1->add($m2)->getPaise())->toBe(75000)
        ->and($m1->subtract($m2)->getPaise())->toBe(25000)
        ->and($m2->multiply(3)->getPaise())->toBe(75000)
        ->and($m1->percentageBps(1800)->getPaise())->toBe(9000) // 18% of 500 = 90
        ->and($m1->percentage(10.0)->getPaise())->toBe(5000);   // 10% of 500 = 50
});
