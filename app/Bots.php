<?php

declare(strict_types=1);

final class Bots
{
    /** Поведение (вероятности действий). */
    public const TYPES = [
        'bot_shy' => [
            'name' => 'Скромный одиночка',
            'lay' => 0.55,
            'bet' => 0.28,
            'loan' => 0.05,
            'voucher' => 0.00,
            'follow_pile' => 0.15,
            'p2p' => 0.05,
            'deposit' => 0.00,
            'bank_card' => 0.05,
            'team_create' => 0.00,
            'team_join' => 0.15,
        ],
        'bot_solo_active' => [
            'name' => 'Активный одиночка',
            'lay' => 0.90,
            'bet' => 0.82,
            'loan' => 0.18,
            'voucher' => 0.08,
            'follow_pile' => 0.35,
            'p2p' => 0.12,
            'deposit' => 0.10,
            'bank_card' => 0.18,
            'team_create' => 0.05,
            'team_join' => 0.20,
        ],
        'bot_leader' => [
            'name' => 'Активный лидер',
            'lay' => 0.98,
            'bet' => 0.95,
            'loan' => 0.40,
            'voucher' => 0.12,
            'follow_pile' => 0.70,
            'p2p' => 0.25,
            'deposit' => 0.20,
            'bank_card' => 0.22,
            'team_create' => 0.85,
            'team_join' => 0.40,
        ],
        'bot_team_mid' => [
            'name' => 'Командный средний',
            'lay' => 0.88,
            'bet' => 0.72,
            'loan' => 0.20,
            'voucher' => 0.08,
            'follow_pile' => 0.45,
            'p2p' => 0.20,
            'deposit' => 0.08,
            'bank_card' => 0.12,
            'team_create' => 0.10,
            'team_join' => 0.90,
        ],
        'bot_team_active' => [
            'name' => 'Активный командный',
            'lay' => 0.95,
            'bet' => 0.88,
            'loan' => 0.28,
            'voucher' => 0.10,
            'follow_pile' => 0.55,
            'p2p' => 0.30,
            'deposit' => 0.12,
            'bank_card' => 0.16,
            'team_create' => 0.55,
            'team_join' => 0.85,
        ],
        'bot_spectator' => [
            'name' => 'Пассивный зритель',
            'lay' => 0.35,
            'bet' => 0.12,
            'loan' => 0.00,
            'voucher' => 0.00,
            'follow_pile' => 0.05,
            'p2p' => 0.00,
            'deposit' => 0.00,
            'bank_card' => 0.00,
            'team_create' => 0.00,
            'team_join' => 0.05,
        ],
        'bot_schemer' => [
            'name' => 'Махинатор',
            'lay' => 0.92,
            'bet' => 0.92,
            'loan' => 0.55,
            'voucher' => 0.18,
            'follow_pile' => 0.80,
            'p2p' => 0.40,
            'deposit' => 0.35,
            'bank_card' => 0.30,
            'team_create' => 0.25,
            'team_join' => 0.30,
        ],
    ];

    /**
     * Каст стола — как подписи на карте сокровищ:
     * имя — короткое «кто он», поведение = type.
     * Хватает на стол до 24 + запас.
     */
    public const CAST = [
        ['key' => 'fog_cartographer', 'name' => 'Картограф Тумана', 'bio' => 'чертит тропы, сам ходит редко', 'type' => 'bot_shy'],
        ['key' => 'quiet_cabin', 'name' => 'Юнга Молчанка', 'bio' => 'смотрит с койки, в кучу почти не лезет', 'type' => 'bot_spectator'],
        ['key' => 'lucky_hook', 'name' => 'Крюк-на-удачу', 'bio' => 'ставит от души, считает пальцами', 'type' => 'bot_solo_active'],
        ['key' => 'red_bay', 'name' => 'Штурман Красной бухты', 'bio' => 'ведёт троих, сам в авангарде', 'type' => 'bot_leader'],
        ['key' => 'leaky_purse', 'name' => 'Казначей Дырявый', 'bio' => 'займы, вклады, векселя — его стихия', 'type' => 'bot_schemer'],
        ['key' => 'echo_mate', 'name' => 'Боцман Эхо', 'bio' => 'идёт за капитаном, редко спорит', 'type' => 'bot_team_mid'],
        ['key' => 'powder_fox', 'name' => 'Лиса Пороховая', 'bio' => 'быстрая рука, любит горячую кучу', 'type' => 'bot_team_active'],
        ['key' => 'compass_orphan', 'name' => 'Сирота Компаса', 'bio' => 'один в тумане, карта близко к груди', 'type' => 'bot_shy'],
        ['key' => 'barrel_king', 'name' => 'Король Бочки', 'bio' => 'громкий, щедрый на ставки', 'type' => 'bot_solo_active'],
        ['key' => 'ink_parrot', 'name' => 'Попугай Чернильный', 'bio' => 'повторяет чужие ходы с важным видом', 'type' => 'bot_team_mid'],
        ['key' => 'moon_lender', 'name' => 'Ростовщик Луны', 'bio' => 'даст монету — потом вспомнит', 'type' => 'bot_schemer'],
        ['key' => 'sail_whisper', 'name' => 'Парус-шёпот', 'bio' => 'почти не высовывается из тени', 'type' => 'bot_spectator'],
        ['key' => 'grain_captain', 'name' => 'Капитан Зерновой', 'bio' => 'собирает команду до первого выстрела', 'type' => 'bot_leader'],
        ['key' => 'salt_sister', 'name' => 'Сестра Соли', 'bio' => 'в деле с палубной братией', 'type' => 'bot_team_active'],
        ['key' => 'cork_gambler', 'name' => 'Пробка-игрок', 'bio' => 'одна монета — и уже в азарте', 'type' => 'bot_solo_active'],
        ['key' => 'map_mend', 'name' => 'Латка Карт', 'bio' => 'чинит чужие планы, свои прячет', 'type' => 'bot_shy'],
        ['key' => 'tide_clerk', 'name' => 'Писарь Прилива', 'bio' => 'считает кучи раньше, чем кладёт', 'type' => 'bot_schemer'],
        ['key' => 'knot_brother', 'name' => 'Брат Узла', 'bio' => 'держится команды, ставка средняя', 'type' => 'bot_team_mid'],
        ['key' => 'lantern_scout', 'name' => 'Разведчик Фонаря', 'bio' => 'первый на стенде, последний молчит', 'type' => 'bot_solo_active'],
        ['key' => 'gull_idle', 'name' => 'Чайка Праздная', 'bio' => 'кружит над столом, почти не садится', 'type' => 'bot_spectator'],
        ['key' => 'anchor_boss', 'name' => 'Якорь-босс', 'bio' => 'тяжёлый голос, тянет за собой стол', 'type' => 'bot_leader'],
        ['key' => 'rum_twin', 'name' => 'Близнец Рома', 'bio' => 'всегда с кем-то в паре', 'type' => 'bot_team_active'],
        ['key' => 'silver_fin', 'name' => 'Серебряный Плавник', 'bio' => 'скользкий ход, любит докупку', 'type' => 'bot_schemer'],
        ['key' => 'dock_mouse', 'name' => 'Мышь Причала', 'bio' => 'маленькая ставка, тихий пас', 'type' => 'bot_shy'],
        ['key' => 'flag_raiser', 'name' => 'Поднявший Флаг', 'bio' => 'зовёт в команду с первой минуты', 'type' => 'bot_leader'],
        ['key' => 'deck_echo', 'name' => 'Эхо Палубы', 'bio' => 'подхватывает чужой пыл', 'type' => 'bot_team_mid'],
        ['key' => 'storm_bet', 'name' => 'Ставка Шторма', 'bio' => 'чем выше куча — тем веселее', 'type' => 'bot_solo_active'],
        ['key' => 'chest_sleeper', 'name' => 'Сторож Сундука', 'bio' => 'дремлет у банка, редко просыпается', 'type' => 'bot_spectator'],
    ];

    public static function rotation(): array
    {
        return ['bot_shy', 'bot_solo_active', 'bot_leader', 'bot_team_mid', 'bot_team_active', 'bot_spectator', 'bot_schemer'];
    }

    public static function sandboxTarget(int $humans): int
    {
        return match (true) {
            $humans <= 0 => 0,
            $humans === 1 => 6,
            $humans === 2 => 12,
            $humans === 3 => 18,
            default => 24,
        };
    }

    public static function profile(string $type): array
    {
        return self::TYPES[$type] ?? self::TYPES['bot_shy'];
    }

    /** Полный каталог для хоста / админа. */
    public static function catalog(): array
    {
        return array_map(static fn(array $c): array => [
            'key' => $c['key'],
            'name' => $c['name'],
            'bio' => $c['bio'],
            'type' => $c['type'],
            'archetype' => self::TYPES[$c['type']]['name'] ?? $c['type'],
        ], self::CAST);
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::CAST as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        return null;
    }

    public static function byName(string $name): ?array
    {
        foreach (self::CAST as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Следующие N свободных персонажей (имена, ещё не занятые за столом).
     * @param list<string> $usedNames
     * @return list<array{key:string,name:string,bio:string,type:string}>
     */
    public static function pickFree(array $usedNames, int $need): array
    {
        $used = array_fill_keys($usedNames, true);
        $pool = self::CAST;
        shuffle($pool);
        $out = [];
        foreach ($pool as $c) {
            if (isset($used[$c['name']])) {
                continue;
            }
            $out[] = $c;
            if (count($out) >= $need) {
                break;
            }
        }
        // Если каст кончился — добиваем архетипами с суффиксом.
        $i = 1;
        $rot = self::rotation();
        while (count($out) < $need) {
            $type = $rot[(count($out) + $i) % count($rot)];
            $prof = self::profile($type);
            $name = $prof['name'] . ' · ' . $i;
            if (isset($used[$name])) {
                $i++;
                continue;
            }
            $out[] = [
                'key' => $type . '_' . $i,
                'name' => $name,
                'bio' => 'запасной матрос с чужого корабля',
                'type' => $type,
            ];
            $i++;
        }
        return $out;
    }

    public static function tipLine(string $name, ?string $bio, int $balance, bool $isMe = false): string
    {
        $who = $name . ($isMe ? ' · ты' : '');
        if ($bio !== null && $bio !== '') {
            return $who . ' — ' . $bio . "\n" . 'монеты ' . $balance;
        }
        return $who . "\n" . 'монеты ' . $balance;
    }
}
