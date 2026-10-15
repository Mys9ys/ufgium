# UFGium — архитектура v0.1

Опирается на [`01-ufg1-rules.md`](01-ufg1-rules.md) и [`10-round-state-machine.md`](10-round-state-machine.md).  
Каталог правил: [`../data/rules.json`](../data/rules.json).

## Цель продукта
Проведение вечериночных квиз/настолок с **электронным гроссбухом** в VK Mini App + браузер.  
Первая игра: **UFG-1** (ситуация + ассоциации + ставки золотыми).

## Стек (предложение)
| Слой | Выбор | Почему |
|------|--------|--------|
| Клиент | Vue 3 + Vite | VK Mini App, быстрый UI, знакомый стек |
| API | PHP 8 (Laravel или тонкий API на OSPanel) | проект уже в `OSPanel`, хостинг привычный |
| Realtime | WebSocket (Laravel Reverb / Soketi) или SSE | фазы тура, ставки, готовность |
| БД | MySQL 8 | кошелёк + журнал транзакций |
| Авторизация | email + опционально VK ID | v3: VK не обязателен |

MVP можно стартовать **без** realtime: polling 1–2 с по состоянию тура.

## Режимы (не все в MVP)
`sandbox` → `table` (QR застолье) → `ranked` / `vitrine` / `royal_cb`.

**MVP = `table` + урезанная `sandbox`:** создать комнату, номера с 14, туры, гроссбух. Без доната, без матчмейкинга, боты — позже.

## Доменная модель

```
User ──< SessionPlayer >── GameSession
                │                │
                │                ├── Round (1..10)
                │                │     ├── RoundRule
                │                │     ├── Situation
                │                │     ├── BoardSlot
                │                │     └── Bet
                │                ├── Team (0..n троек)
                ├── Wallet (balance, spend, points_*)
                ├── Voucher
                ├── HandCard
                └── Transaction (гроссбух)
```

### User / Profile
- email, vk_id?, display_name
- `debt_rub` (невыкупленные векселя × 100)
- блок при `debt_rub >= 1000`

### GameSession
- `code` / QR
- `mode`: sandbox | table | …
- `host_user_id`
- `max_players` (24, расширяемо)
- `status`: lobby | playing | redeem | finished
- `current_round`, `max_rounds` (10)

### SessionPlayer
- `token` 14+ по заходу; чёт = white, нечёт = red
- `drink_tag`: blue | steklyshki
- `team_id` nullable
- `ready` (фаза ставок)

### Round
- `number` 1..10
- `phase`: rule | announce | situation | lay | board | bet | settle
- `laying_side` / `betting_side`: white | red (или override правилом)
- `deals_open` true до settle
- таймеры: `lay_ends_at`, `bet_ends_at`

### Transaction (append-only)
| type | spend? | комментарий |
|------|--------|-------------|
| `bet` | да | ставка на слот |
| `payout` | нет | куча владельцу |
| `buy_voucher_bank` | да | бланк за 1 |
| `buy_voucher_player` | да | только покупатель |
| `buy_card_*` | да | после правила рынка |
| `loan_bank` | нет | кредит |
| `loan_p2p` | TBD | после правила |
| `team_fee` | да | 2+1+1 |
| `redeem_voucher` | нет | конец катки |

Рейтинг трат сессии = `SUM(amount) WHERE spend=1`.

### Voucher
- `status`: blank | used | redeemed | leftover
- `loan_limit_at_issue` (3 или 5 — **на момент займа**)
- `loaned_amount`, `debt_rub`

### Rule engine
Активные флаги сессии, например:
`bet_max_per_slot`, `bet_split`, `layer_can_vote`, `hide_owners`, `p2p_loan`, `teams_enabled`, `card_market`.

Карточка с T2: `new beats old` (см. `meta_new_beats_old`).

## Клиентские экраны (MVP)
1. Лобби (QR, список, синие/стеклышки)
2. Тур: правило / ситуация / рука / стенд / ставки
3. Гроссбух (лента)
4. Рейтинги: траты, баллы топ, баллы объём, команды
5. Конец: выкуп векселей

## Не в MVP
Донат VK, аукцион, боты всех типов, королевская ЦБ, лайки карт, UFG-2.
