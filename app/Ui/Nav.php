<?php

namespace App\Ui;

use App\Models\User;

/** The navigation shown on every page (domain §13 G3, G4). */
final class Nav
{
    private const GROUPS = [
        'Overview' => [['Home', '/']],
        'Sales' => [['Invoices', '/sales-invoices'], ['Credit notes', '/sales-credit-notes'],
            ['Customer payments', '/customer-payments'], ['Sales orders', '/sales-orders']],
        'Purchases' => [['Bills', '/purchase-bills'], ['Supplier credits', '/purchase-credit-notes'],
            ['Supplier payments', '/supplier-payments'], ['Purchase orders', '/purchase-orders']],
        'Inventory' => [['Stock movements', '/stock-movements'], ['Inventory valuation', '/inventory-valuation']],
        'Accounting' => [['Bank statements', '/bank-statements'], ['Exchange rates', '/exchange-rates'],
            ['Periods and year-end', '/periods']],
        'Reports' => [['Profit and loss', '/reports/profit-and-loss'], ['Balance sheet', '/reports/balance-sheet'],
            ['Cash flow', '/reports/cash-flow'], ['Trial balance', '/reports/trial-balance'],
            ['AR aging', '/reports/ar-aging'], ['AP aging', '/reports/ap-aging']],
        'Master data' => [['Organizations', '/organizations'], ['Customers', '/customers'], ['Suppliers', '/suppliers'],
            ['Products', '/products'], ['Chart of accounts', '/accounts'], ['Tax codes', '/tax-codes'],
            ['Payment terms', '/payment-terms'], ['Warehouses', '/warehouses']],
        'Administration' => [['Users', '/users'], ['Settings', '/settings']],
    ];

    private const ADMIN_ONLY = ['/users'];

    public static function for(User $user, string $path): array
    {
        $groups = [];
        foreach (self::GROUPS as $title => $items) {
            $links = [];
            foreach ($items as [$text, $url]) {
                if (in_array($url, self::ADMIN_ONLY, true) && ! $user->is_admin) {
                    continue;
                }
                $active = $url === '/' ? $path === '/' : ($path === $url || str_starts_with($path, "$url/"));
                $links[] = ['text' => $text, 'url' => $url, 'active' => $active];
            }
            $groups[] = ['title' => $title, 'links' => $links];
        }

        return $groups;
    }
}
