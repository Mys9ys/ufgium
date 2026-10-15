<?php

declare(strict_types=1);

final class Rules
{
    /** Одна новая карточка за тур с T2. Команды — не раньше T3. */
    public const DRAW = [
        2 => 'rule_loan_limit_5',
        3 => 'rule_teams_of_3',
        4 => 'rule_bet_split',
        5 => 'rule_p2p_loan',
        6 => 'rule_card_market',
        7 => 'rule_buy_cards_bank',
        8 => 'rule_deposit_5_6',
    ];

    public static function catalog(): array
    {
        static $c = null;
        if ($c !== null) {
            return $c;
        }
        $path = dirname(__DIR__) . '/data/rules.json';
        $json = json_decode((string)file_get_contents($path), true);
        $c = [];
        foreach ($json['cards'] ?? [] as $card) {
            $c[$card['id']] = $card;
        }
        return $c;
    }

    public static function card(string $id): ?array
    {
        return self::catalog()[$id] ?? null;
    }

    public static function forRound(int $num): ?string
    {
        return self::DRAW[$num] ?? null;
    }

    public static function emptyFlags(): array
    {
        return [
            'bet_split' => false,
            'bet_max_per_slot' => 1,
            'bet_total_cap' => 1,
            'p2p_loan' => false,
            'p2p_loan_max' => 1,
            'teams' => false,
            'card_market' => false,
            'card_bank' => false,
            'deposit' => false,
            'voucher_sell' => true,
            'loan_limit' => 3,
            'hide_owners' => false,
        ];
    }

    public static function flagsFromCodes(array $codes, int $sessionLoanLimit): array
    {
        $f = self::emptyFlags();
        $f['loan_limit'] = $sessionLoanLimit;
        $f['voucher_sell'] = true;
        foreach ($codes as $code) {
            $card = self::card($code);
            $flags = $card['flags'] ?? [];
            if ($code === 'rule_loan_limit_5') {
                $f['loan_limit'] = (int)($flags['new_loan_limit'] ?? 5);
            }
            if ($code === 'rule_bet_split' || $code === 'rule_bet_3_one') {
                $f['bet_split'] = true;
                $f['bet_max_per_slot'] = (int)($flags['bet_max_per_slot'] ?? 3);
                $f['bet_total_cap'] = (int)($flags['bet_total_cap'] ?? 3);
            }
            if ($code === 'rule_p2p_loan') {
                $f['p2p_loan'] = true;
                $f['p2p_loan_max'] = (int)($flags['p2p_loan_max'] ?? 1);
            }
            if ($code === 'rule_teams_of_3') {
                $f['teams'] = true;
            }
            if ($code === 'rule_card_market') {
                $f['card_market'] = true;
            }
            if ($code === 'rule_buy_cards_bank') {
                $f['card_bank'] = true;
            }
            if ($code === 'rule_deposit_5_6') {
                $f['deposit'] = true;
            }
            if ($code === 'rule_hide_owners') {
                $f['hide_owners'] = true;
            }
        }
        return $f;
    }
}
