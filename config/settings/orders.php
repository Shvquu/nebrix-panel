<?php

declare(strict_types=1);

/*
 * Settings of the cart and add-ons (Admin → Settings → General → Cart & add-ons).
 * Same format as config/settings.php.
 */
return [
    'orders.cart_max_items' => ['group' => 'orders', 'type' => 'int', 'default' => 20, 'label' => 'Maximum items in a cart', 'rules' => 'required|int|min:1|max:50'],
    'orders.cart_retention_days' => ['group' => 'orders', 'type' => 'int', 'default' => 30, 'label' => 'Delete carts not changed for … days', 'help' => 'Carts of customers who do not order are removed after this time. Prices are never stored in a cart.', 'rules' => 'required|int|min:1|max:365'],
];
