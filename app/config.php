<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.1.16',
        'port' => 3306,
        'name' => 'ufgium',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'UFGium',
        'max_players' => 24,
        'max_rounds' => 10,
        'start_token' => 14,
        'token_max' => 99,
        /** Подсказка для доната: выбрать конкретный номер (руб.). Логика оплаты — позже. */
        'token_buy_rub' => 7000,
        'start_gold' => 1,
        'hand_size' => 6,
        'loan_limit' => 3,
        'loan_limit_t2_rule' => 5,
        'lay_seconds' => 180,
        'bet_seconds' => 900,
        'voucher_bank_price' => 1,
        'voucher_sell_price' => 1,
        'card_bank_price' => 1,
        'card_trade_price' => 1,
        'p2p_loan_amount' => 1,
        'deposit_in' => 5,
        'deposit_out' => 6,
        'team_captain_fee' => 2,
        'team_member_fee' => 1,
        'debt_rub_per_coin' => 100,
    ],
];
