<?php
/**
 * Bank transfer, JazzCash, EasyPaisa and anything else paid by hand.
 *
 * The customer is shown an account to send money to and types the transaction
 * id from their receipt; an admin confirms it. No API, nothing to configure
 * beyond the account details, which live on the payment method itself.
 */
return [
    'name'  => 'Manual / bank transfer',
    'blurb' => 'Show account details, take a transaction id, confirm it yourself. '
             . 'Works for JazzCash, EasyPaisa, bank transfer, anything.',
    'kind'  => 'manual',
    'fields' => [],
];
