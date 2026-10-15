<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Bots.php';
require_once __DIR__ . '/Rules.php';

final class Game
{
    private PDO $db;
    private array $cfg;
    private bool $runningBots = false;

    public function __construct()
    {
        $this->db = Db::pdo();
        $this->cfg = (require __DIR__ . '/config.php')['app'];
    }

    public function create(string $name, string $drink = 'steklyshki', string $packId = 'ink-yard'): array
    {
        $name = $this->cleanName($name);
        $drink = $this->drink($drink ?: 'steklyshki');
        $packId = $this->packId($packId);
        $code = $this->uniqueCode();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO game_sessions (code, status, pack_id, target_players) VALUES (?, "lobby", ?, 6)')
                ->execute([$code, $packId]);
            $sid = (int)$this->db->lastInsertId();
            $player = $this->addPlayer($sid, $name, $drink, true);
            $this->db->prepare('UPDATE game_sessions SET host_player_id = ? WHERE id = ?')->execute([$player['id'], $sid]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($player['id'], $player['secret']);
    }

    public function join(string $code, string $name, string $drink): array
    {
        $s = $this->sessionByCode($code);
        if (!$s) {
            throw new RuntimeException('Комната не найдена');
        }
        if ($s['status'] !== 'lobby') {
            throw new RuntimeException('Игра уже началась');
        }
        $n = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . (int)$s['id'])->fetchColumn();
        if ($n >= $this->cfg['max_players']) {
            throw new RuntimeException('Стол полный (24)');
        }
        $player = $this->addPlayer((int)$s['id'], $this->cleanName($name), $this->drink($drink), false);
        return $this->stateFor($player['id'], $player['secret']);
    }

    public function fillBots(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост добавляет ботов');
        }
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'lobby') {
            throw new RuntimeException('Ботов можно добить только в лобби');
        }
        $this->fillSandboxBots((int)$s['id']);
        return $this->stateFor($pid, $secret);
    }

    public function setTableSize(int $pid, string $secret, int $size): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост задаёт размер стола');
        }
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'lobby') {
            throw new RuntimeException('Размер стола меняется только в лобби');
        }
        $max = (int)$this->cfg['max_players'];
        if ($size < 2 || $size > $max) {
            throw new RuntimeException("Размер стола: от 2 до {$max}");
        }
        $humans = (int)$this->db->query(
            'SELECT COUNT(*) FROM session_players WHERE session_id = ' . (int)$s['id'] . ' AND is_bot = 0'
        )->fetchColumn();
        if ($size < $humans) {
            throw new RuntimeException("Уже за столом {$humans} живых — меньше нельзя");
        }
        $this->db->prepare('UPDATE game_sessions SET target_players = ? WHERE id = ?')
            ->execute([$size, $s['id']]);
        $this->trimBotsToTarget((int)$s['id'], $size);
        return $this->stateFor($pid, $secret);
    }

    public function start(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост стартует игру');
        }
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'lobby') {
            throw new RuntimeException('Уже запущено');
        }
        $this->fillSandboxBots((int)$s['id']);
        $n = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . (int)$s['id'])->fetchColumn();
        if ($n < 2) {
            throw new RuntimeException('Нужно минимум 2 игрока (или добей ботами)');
        }
        $this->db->beginTransaction();
        try {
            $this->assignSeatTokens((int)$s['id']);
            $this->dealHands((int)$s['id']);
            $this->db->prepare('UPDATE game_sessions SET status = "playing", current_round = 1 WHERE id = ?')->execute([$s['id']]);
            $this->openRound((int)$s['id'], 1);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function lay(int $pid, string $secret, ?int $cardId): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        $this->tick($s, $r);
        $r = $this->currentRound($this->session((int)$s['id']));
        if ($r['phase'] !== 'lay') {
            throw new RuntimeException('Сейчас не фаза выкладки');
        }
        if (!$this->isSide($me, $r['laying_side'])) {
            throw new RuntimeException('В этом туре карты кладёт другая сторона');
        }
        if ($me['laid']) {
            throw new RuntimeException('Карта уже выложена');
        }
        if ($cardId !== null) {
            $has = $this->db->prepare('SELECT 1 FROM player_cards WHERE player_id = ? AND card_id = ?');
            $has->execute([$pid, $cardId]);
            if (!$has->fetch()) {
                throw new RuntimeException('Этой карты нет в руке');
            }
            $this->db->prepare('DELETE FROM player_cards WHERE player_id = ? AND card_id = ?')->execute([$pid, $cardId]);
            $slot = (int)$this->db->query('SELECT COALESCE(MAX(slot_num),0)+1 FROM board_slots WHERE round_id = ' . (int)$r['id'])->fetchColumn();
            $this->db->prepare('INSERT INTO board_slots (round_id, slot_num, card_id, player_id, pile) VALUES (?,?,?,?,0)')
                ->execute([$r['id'], $slot, $cardId, $pid]);
        }
        $this->db->prepare('UPDATE session_players SET laid = 1 WHERE id = ?')->execute([$pid]);
        $this->maybeCloseLay((int)$s['id'], (int)$r['id']);
        return $this->stateFor($pid, $secret);
    }

    public function bet(int $pid, string $secret, int $slot, int $amount = 1): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        $this->tick($s, $r);
        $r = $this->currentRound($this->session((int)$s['id']));
        if ($r['phase'] !== 'bet') {
            throw new RuntimeException('Сейчас не фаза ставок');
        }
        if (!$this->canBet($me, $r)) {
            throw new RuntimeException('Вы не ставите в этом туре');
        }
        if ($me['bet_done']) {
            throw new RuntimeException('Ставка уже сделана');
        }
        $flags = $this->flags((int)$s['id']);
        $cap = (int)$flags['bet_total_cap'];
        $slotCap = (int)$flags['bet_max_per_slot'];
        $amount = max(1, min($amount, $cap, $slotCap));
        $already = $this->betTotal((int)$r['id'], $pid);
        $left = $cap - $already;
        if ($left <= 0) {
            throw new RuntimeException('Лимит ставки за тур исчерпан');
        }
        $amount = min($amount, $left);
        $onSlot = $this->betOnSlot((int)$r['id'], $pid, $slot);
        $amount = min($amount, $slotCap - $onSlot);
        if ($amount < 1) {
            throw new RuntimeException('На эту карту больше нельзя');
        }
        if ((int)$me['balance'] < $amount) {
            throw new RuntimeException('Не хватает золотых');
        }
        $slotRow = $this->db->prepare('SELECT * FROM board_slots WHERE round_id = ? AND slot_num = ?');
        $slotRow->execute([$r['id'], $slot]);
        $slotRow = $slotRow->fetch();
        if (!$slotRow) {
            throw new RuntimeException('Нет такого слота');
        }
        if ((int)$slotRow['player_id'] === $pid) {
            throw new RuntimeException('На свою карту ставить нельзя');
        }
        $done = (!$flags['bet_split'] || ($already + $amount) >= $cap) ? 1 : 0;
        // ready только по кнопке «Готов» / пас — до этого ставку можно снять и переставить.
        $autoReady = !empty($me['is_bot']) ? $done : 0;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ?, bet_done = ?, ready = ? WHERE id = ?')
                ->execute([$amount, $amount, $done, $autoReady, $pid]);
            $this->db->prepare('UPDATE board_slots SET pile = pile + ? WHERE id = ?')->execute([$amount, $slotRow['id']]);
            $this->db->prepare('INSERT INTO bets (round_id, player_id, slot_num, amount) VALUES (?,?,?,?)')
                ->execute([$r['id'], $pid, $slot, $amount]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'bet', $amount, true, 'slot ' . $slot);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->maybeCloseBet((int)$s['id'], (int)$r['id']);
        return $this->stateFor($pid, $secret);
    }

    /** Снять свои ставки в текущем туре (пока не нажал «Готов» / фаза ещё bet). */
    public function cancelBet(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ($r['phase'] !== 'bet') {
            throw new RuntimeException('Сейчас не фаза ставок');
        }
        if (!$this->canBet($me, $r)) {
            throw new RuntimeException('Вы не ставите в этом туре');
        }
        if (!empty($me['ready'])) {
            throw new RuntimeException('Уже нажал «Готов» — ставку не меняем');
        }
        $rid = (int)$r['id'];
        $bets = $this->db->prepare('SELECT * FROM bets WHERE round_id = ? AND player_id = ?');
        $bets->execute([$rid, $pid]);
        $rows = $bets->fetchAll();
        if (!$rows) {
            throw new RuntimeException('Ставок нет');
        }
        $this->db->beginTransaction();
        try {
            foreach ($rows as $b) {
                $amt = (int)$b['amount'];
                $this->db->prepare('UPDATE board_slots SET pile = GREATEST(0, pile - ?) WHERE round_id = ? AND slot_num = ?')
                    ->execute([$amt, $rid, (int)$b['slot_num']]);
                $this->db->prepare('UPDATE session_players SET balance = balance + ?, spend = GREATEST(0, spend - ?) WHERE id = ?')
                    ->execute([$amt, $amt, $pid]);
                $this->db->prepare('DELETE FROM bets WHERE id = ?')->execute([(int)$b['id']]);
                $this->tx((int)$s['id'], $rid, $pid, 'bet_cancel', $amt, false, 'undo slot ' . $b['slot_num']);
            }
            $this->db->prepare('UPDATE session_players SET bet_done = 0, ready = 0 WHERE id = ?')->execute([$pid]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function passBet(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ($r['phase'] !== 'bet') {
            throw new RuntimeException('Сейчас не фаза ставок');
        }
        if (!$this->canBet($me, $r)) {
            throw new RuntimeException('Вы не ставите в этом туре');
        }
        if ($this->betTotal((int)$r['id'], $pid) > 0) {
            throw new RuntimeException('Сначала сними ставку, потом пас — или жми «Готов»');
        }
        $this->db->prepare('UPDATE session_players SET bet_done = 1, ready = 1 WHERE id = ?')->execute([$pid]);
        $this->maybeCloseBet((int)$s['id'], (int)$r['id']);
        return $this->stateFor($pid, $secret);
    }

    public function ready(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ($r['phase'] !== 'bet') {
            throw new RuntimeException('Готов только на ставках');
        }
        if (!$this->canBet($me, $r)) {
            throw new RuntimeException('Вы не ставите в этом туре');
        }
        $this->db->prepare('UPDATE session_players SET bet_done = 1, ready = 1 WHERE id = ?')->execute([$pid]);
        $this->maybeCloseBet((int)$s['id'], (int)$r['id']);
        return $this->stateFor($pid, $secret);
    }

    public function loan(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ((int)$s['current_round'] < 2) {
            throw new RuntimeException('Займ с 2-го тура');
        }
        if ($r['phase'] === 'settle' || $s['status'] !== 'playing') {
            throw new RuntimeException('Сейчас займ недоступен');
        }
        if ((int)$me['loans_this_round'] >= 1) {
            throw new RuntimeException('Один займ за тур');
        }
        $v = $this->db->prepare('SELECT * FROM vouchers WHERE player_id = ? AND status = "blank" ORDER BY id LIMIT 1');
        $v->execute([$pid]);
        $v = $v->fetch();
        if (!$v) {
            throw new RuntimeException('Нужен пустой вексель');
        }
        $limit = (int)$s['loan_limit'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE vouchers SET status = "used", loan_limit = ?, loaned_amount = ? WHERE id = ?')
                ->execute([$limit, $limit, $v['id']]);
            $this->db->prepare('UPDATE session_players SET balance = balance + ?, loans_this_round = 1 WHERE id = ?')
                ->execute([$limit, $pid]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'loan_bank', $limit, false, 'voucher ' . $v['id']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function buyVoucher(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ($s['status'] !== 'playing') {
            throw new RuntimeException('Недоступно');
        }
        if ((int)$me['voucher_buys_this_round'] >= 1) {
            throw new RuntimeException('Один бланк за тур');
        }
        $price = (int)$this->cfg['voucher_bank_price'];
        if ((int)$me['balance'] < $price) {
            throw new RuntimeException('Нужен 1 золотой');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ?, voucher_buys_this_round = 1 WHERE id = ?')
                ->execute([$price, $price, $pid]);
            $this->db->prepare('INSERT INTO vouchers (player_id, status, loan_limit, loaned_amount) VALUES (?, "blank", ?, 0)')
                ->execute([$pid, (int)$s['loan_limit']]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'buy_voucher_bank', $price, true, null);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function sellVoucher(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        if ((int)$me['voucher_sold_this_round'] >= 1) {
            throw new RuntimeException('Одна продажа бланка за тур');
        }
        $v = $this->db->prepare('SELECT * FROM vouchers WHERE player_id = ? AND status = "blank" ORDER BY id LIMIT 1');
        $v->execute([$pid]);
        $v = $v->fetch();
        if (!$v) {
            throw new RuntimeException('Нет пустого векселя');
        }
        $price = (int)$this->cfg['voucher_sell_price'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM vouchers WHERE id = ?')->execute([$v['id']]);
            $this->db->prepare('UPDATE session_players SET balance = balance + ?, voucher_sold_this_round = 1 WHERE id = ?')->execute([$price, $pid]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'sell_voucher_bank', $price, false, 'voucher ' . $v['id']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function buyBankCard(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        $flags = $this->flags((int)$s['id']);
        if (!$flags['card_bank']) {
            throw new RuntimeException('Ещё нет правила докупки у банка');
        }
        if ((int)$me['bank_card_this_round'] >= 1) {
            throw new RuntimeException('Одна карта из банка за тур');
        }
        $price = (int)$this->cfg['card_bank_price'];
        if ((int)$me['balance'] < $price) {
            throw new RuntimeException('Нужен 1 золотой');
        }
        $cid = $this->randomCardNotInHand($pid);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ?, bank_card_this_round = 1 WHERE id = ?')->execute([$price, $price, $pid]);
            $this->db->prepare('INSERT INTO player_cards (player_id, card_id) VALUES (?,?)')->execute([$pid, $cid]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'buy_card_bank', $price, true, 'card ' . $cid);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function sellCard(int $pid, string $secret, int $toPid, int $cardId): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        $flags = $this->flags((int)$s['id']);
        if (!$flags['card_market']) {
            throw new RuntimeException('Ещё нет рынка карт');
        }
        if ($toPid === $pid) {
            throw new RuntimeException('Себе продать нельзя');
        }
        if ((int)$me['card_sold_this_round'] >= 1) {
            throw new RuntimeException('Одна продажа карты за тур');
        }
        $buyer = $this->db->prepare('SELECT * FROM session_players WHERE id = ? AND session_id = ?');
        $buyer->execute([$toPid, $s['id']]);
        $buyer = $buyer->fetch();
        if (!$buyer) {
            throw new RuntimeException('Нет такого игрока');
        }
        $price = (int)$this->cfg['card_trade_price'];
        if ((int)$buyer['balance'] < $price) {
            throw new RuntimeException('У покупателя нет монеты');
        }
        $has = $this->db->prepare('SELECT 1 FROM player_cards WHERE player_id = ? AND card_id = ?');
        $has->execute([$pid, $cardId]);
        if (!$has->fetch()) {
            throw new RuntimeException('Этой карты нет в руке');
        }
        $dup = $this->db->prepare('SELECT 1 FROM player_cards WHERE player_id = ? AND card_id = ?');
        $dup->execute([$toPid, $cardId]);
        if ($dup->fetch()) {
            throw new RuntimeException('У покупателя уже есть такая карта');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM player_cards WHERE player_id = ? AND card_id = ?')->execute([$pid, $cardId]);
            $this->db->prepare('INSERT INTO player_cards (player_id, card_id) VALUES (?,?)')->execute([$toPid, $cardId]);
            $this->db->prepare('UPDATE session_players SET balance = balance + ?, card_sold_this_round = 1 WHERE id = ?')->execute([$price, $pid]);
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ? WHERE id = ?')->execute([$price, $price, $toPid]);
            $this->tx((int)$s['id'], (int)$r['id'], $toPid, 'buy_card_p2p', $price, true, 'from ' . $pid . ' card ' . $cardId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function p2pLoan(int $pid, string $secret, int $toPid): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        $flags = $this->flags((int)$s['id']);
        if (!$flags['p2p_loan']) {
            throw new RuntimeException('Ещё нет дружеского кредита');
        }
        if ($toPid === $pid) {
            throw new RuntimeException('Себе занять нельзя');
        }
        if ((int)$me['p2p_given_this_round'] >= 1) {
            throw new RuntimeException('Один займ другу за тур');
        }
        $amt = (int)$this->cfg['p2p_loan_amount'];
        if ((int)$me['balance'] < $amt) {
            throw new RuntimeException('Нет монеты');
        }
        $to = $this->db->prepare('SELECT id FROM session_players WHERE id = ? AND session_id = ?');
        $to->execute([$toPid, $s['id']]);
        if (!$to->fetch()) {
            throw new RuntimeException('Нет такого игрока');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, p2p_given_this_round = 1 WHERE id = ?')->execute([$amt, $pid]);
            $this->db->prepare('UPDATE session_players SET balance = balance + ? WHERE id = ?')->execute([$amt, $toPid]);
            $this->db->prepare('INSERT INTO p2p_debts (session_id, lender_id, borrower_id, amount) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)')
                ->execute([$s['id'], $pid, $toPid, $amt]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'loan_p2p', $amt, false, 'to ' . $toPid);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function p2pRepay(int $pid, string $secret, int $lenderId): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        if (!in_array($s['status'], ['playing', 'redeem'], true)) {
            throw new RuntimeException('Сейчас нельзя вернуть долг');
        }
        $d = $this->db->prepare('SELECT * FROM p2p_debts WHERE session_id = ? AND lender_id = ? AND borrower_id = ?');
        $d->execute([$s['id'], $lenderId, $pid]);
        $d = $d->fetch();
        if (!$d || (int)$d['amount'] < 1) {
            throw new RuntimeException('Нет долга этому игроку');
        }
        if ((int)$me['balance'] < 1) {
            throw new RuntimeException('Нет монеты');
        }
        $rid = null;
        if ($s['status'] === 'playing') {
            $rid = (int)$this->currentRound($s)['id'];
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - 1 WHERE id = ?')->execute([$pid]);
            $this->db->prepare('UPDATE session_players SET balance = balance + 1 WHERE id = ?')->execute([$lenderId]);
            $this->db->prepare('UPDATE p2p_debts SET amount = amount - 1 WHERE id = ?')->execute([$d['id']]);
            $this->tx((int)$s['id'], $rid, $pid, 'repay_p2p', 1, false, 'to ' . $lenderId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function deposit(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        $flags = $this->flags((int)$s['id']);
        if (!$flags['deposit']) {
            throw new RuntimeException('Ещё нет правила вклада');
        }
        if ((int)$me['deposited'] >= 1) {
            throw new RuntimeException('Вклад уже сделан');
        }
        $need = (int)$this->cfg['deposit_in'];
        if ((int)$me['balance'] < $need) {
            throw new RuntimeException('Нужно 5 золотых');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ?, deposited = 1 WHERE id = ?')->execute([$need, $need, $pid]);
            $this->tx((int)$s['id'], (int)$r['id'], $pid, 'deposit_bank', $need, true, '5→6 в конце');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    public function createTeam(int $pid, string $secret, string $name): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        $flags = $this->flags((int)$s['id']);
        if (!$flags['teams']) {
            throw new RuntimeException('Команды ещё не открыты');
        }
        if ($me['team_id']) {
            throw new RuntimeException('Ты уже в команде');
        }
        $name = $this->cleanName($name);
        $this->db->prepare('INSERT INTO teams (session_id, name, captain_id, status, created_round) VALUES (?,?,?,"forming",?)')
            ->execute([$s['id'], $name, $pid, $s['current_round']]);
        $tid = (int)$this->db->lastInsertId();
        $this->db->prepare('UPDATE session_players SET team_id = ? WHERE id = ?')->execute([$tid, $pid]);
        $this->tx((int)$s['id'], (int)$r['id'], $pid, 'team_create', 0, false, $name);
        return $this->stateFor($pid, $secret);
    }

    public function inviteTeam(int $pid, string $secret, int $toPid): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $this->thisPlayingRound($s);
        if (!$me['team_id']) {
            throw new RuntimeException('Сначала создай команду');
        }
        $t = $this->db->query('SELECT * FROM teams WHERE id = ' . (int)$me['team_id'])->fetch();
        if (!$t || (int)$t['captain_id'] !== $pid || $t['status'] !== 'forming') {
            throw new RuntimeException('Инвайт может капитан формирующейся команды');
        }
        $to = $this->db->prepare('SELECT * FROM session_players WHERE id = ? AND session_id = ?');
        $to->execute([$toPid, $s['id']]);
        $to = $to->fetch();
        if (!$to || $to['team_id']) {
            throw new RuntimeException('Игрок недоступен');
        }
        $this->db->prepare('INSERT INTO team_invites (team_id, player_id, status) VALUES (?,?, "pending") ON DUPLICATE KEY UPDATE status = "pending"')
            ->execute([$t['id'], $toPid]);
        return $this->stateFor($pid, $secret);
    }

    public function acceptTeam(int $pid, string $secret, int $teamId): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->thisPlayingRound($s);
        if ($me['team_id']) {
            throw new RuntimeException('Ты уже в команде');
        }
        $t = $this->db->prepare('SELECT * FROM teams WHERE id = ? AND session_id = ?');
        $t->execute([$teamId, $s['id']]);
        $t = $t->fetch();
        if (!$t || $t['status'] !== 'forming') {
            throw new RuntimeException('Команда недоступна');
        }
        $inv = $this->db->prepare('SELECT * FROM team_invites WHERE team_id = ? AND player_id = ? AND status = "pending"');
        $inv->execute([$teamId, $pid]);
        if (!$inv->fetch()) {
            throw new RuntimeException('Нет приглашения');
        }
        $acceptedAlready = (int)$this->db->query('SELECT COUNT(*) FROM team_invites WHERE team_id = ' . $teamId . ' AND status = "accepted"')->fetchColumn();
        $this->db->prepare('UPDATE team_invites SET status = "accepted" WHERE team_id = ? AND player_id = ?')->execute([$teamId, $pid]);
        $this->db->prepare('UPDATE session_players SET team_id = ? WHERE id = ?')->execute([$teamId, $pid]);
        if ($acceptedAlready + 1 >= 2) {
            $this->sealTeam((int)$s['id'], (int)$r['id'], $t);
        }
        return $this->stateFor($pid, $secret);
    }

    public function hostEnd(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост');
        }
        $this->enterRedeem((int)$me['session_id']);
        return $this->stateFor($pid, $secret);
    }

    public function redeem(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'redeem' && $s['status'] !== 'playing') {
            throw new RuntimeException('Выкуп недоступен');
        }
        $vs = $this->db->prepare('SELECT * FROM vouchers WHERE player_id = ? AND status = "used"');
        $vs->execute([$pid]);
        $left = (int)$me['balance'];
        foreach ($vs->fetchAll() as $v) {
            $need = (int)$v['loaned_amount'];
            $pay = min($left, $need);
            if ($pay <= 0) {
                $this->db->prepare('UPDATE vouchers SET status = "leftover" WHERE id = ?')->execute([$v['id']]);
                continue;
            }
            $left -= $pay;
            $st = $pay >= $need ? 'redeemed' : 'leftover';
            $this->db->prepare('UPDATE vouchers SET status = ?, loaned_amount = loaned_amount - ? WHERE id = ?')->execute([$st, $pay, $v['id']]);
            $this->tx((int)$s['id'], null, $pid, 'redeem_voucher', $pay, false, $st);
        }
        $this->db->prepare('UPDATE session_players SET balance = ? WHERE id = ?')->execute([$left, $pid]);
        return $this->stateFor($pid, $secret);
    }

    public function stateFor(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $this->assignBotCast((int)$s['id']);
        if ($s['status'] === 'playing') {
            $r = $this->currentRound($s);
            $this->tick($s, $r);
            $s = $this->session((int)$s['id']);
            if ($s['status'] === 'playing' && !$this->runningBots && $this->botsAuto($s)) {
                $this->runBots((int)$s['id']);
                $s = $this->session((int)$s['id']);
            }
            $me = $this->auth($pid, $secret);
        }
        $flags = $this->flags((int)$s['id']);
        $activeRules = $this->activeRules((int)$s['id']);
        $players = $this->db->prepare('SELECT sp.id, sp.name, sp.drink_tag, sp.token, sp.is_host, sp.balance, sp.spend, sp.points_top, sp.points_volume, sp.is_bot, sp.bot_type, sp.team_id, sp.deposited, t.name AS team_name FROM session_players sp LEFT JOIN teams t ON t.id = sp.team_id AND t.status = "active" WHERE sp.session_id = ? ORDER BY sp.token');
        $players->execute([$s['id']]);
        $players = $players->fetchAll();
        $tokensReady = $s['status'] !== 'lobby';
        foreach ($players as &$p) {
            $tok = (int)$p['token'];
            $p['token_ready'] = $tokensReady;
            if ($tokensReady) {
                $p['side'] = ($tok % 2 === 0) ? 'white' : 'red';
            } else {
                $p['token'] = null;
                $p['side'] = null;
            }
            $p['is_bot'] = (bool)$p['is_bot'];
            $p['deposited'] = (bool)$p['deposited'];
            $cast = $p['is_bot'] ? Bots::byName((string)$p['name']) : null;
            $p['bio'] = $cast['bio'] ?? null;
            $p['bot_archetype'] = $cast
                ? (Bots::TYPES[$cast['type']]['name'] ?? $cast['type'])
                : (($p['is_bot'] && !empty($p['bot_type']))
                    ? (Bots::profile((string)$p['bot_type'])['name'] ?? null)
                    : null);
        }
        unset($p);

        $round = null;
        $hand = [];
        $board = [];
        $situation = null;
        if ((int)$s['current_round'] > 0) {
            $round = $this->currentRound($s);
            $hs = $this->db->prepare('SELECT c.id, c.title, c.body, c.image_path AS image, c.pack_id, c.art_id FROM player_cards pc JOIN association_cards c ON c.id = pc.card_id WHERE pc.player_id = ? ORDER BY c.id');
            $hs->execute([$pid]);
            $hand = $hs->fetchAll();
            if ($round['situation_id']) {
                $st = $this->db->prepare('SELECT id, body FROM situation_cards WHERE id = ?');
                $st->execute([$round['situation_id']]);
                $situation = $st->fetch() ?: null;
            }
            $hide = !empty($flags['hide_owners']) && $round['phase'] === 'bet';
            $bs = $this->db->prepare('SELECT bs.slot_num, bs.pile, bs.card_id, bs.player_id, c.title, c.body, c.image_path AS image, c.pack_id, c.art_id, p.token, p.name FROM board_slots bs JOIN association_cards c ON c.id = bs.card_id JOIN session_players p ON p.id = bs.player_id WHERE bs.round_id = ? ORDER BY bs.slot_num');
            $bs->execute([$round['id']]);
            $bettersBySlot = [];
            $bq = $this->db->prepare('SELECT b.slot_num, b.amount, p.token, p.name, p.id AS player_id FROM bets b JOIN session_players p ON p.id = b.player_id WHERE b.round_id = ? ORDER BY b.id');
            $bq->execute([$round['id']]);
            foreach ($bq->fetchAll() as $b) {
                $sn = (int)$b['slot_num'];
                if (!isset($bettersBySlot[$sn])) {
                    $bettersBySlot[$sn] = [];
                }
                $key = (int)$b['player_id'];
                if (!isset($bettersBySlot[$sn][$key])) {
                    $bettersBySlot[$sn][$key] = [
                        'token' => (int)$b['token'],
                        'name' => $b['name'],
                        'amount' => 0,
                        'side' => ((int)$b['token'] % 2 === 0) ? 'white' : 'red',
                    ];
                }
                $bettersBySlot[$sn][$key]['amount'] += (int)$b['amount'];
            }
            foreach ($bs->fetchAll() as $row) {
                $sn = (int)$row['slot_num'];
                $item = [
                    'slot' => $sn,
                    'pile' => (int)$row['pile'],
                    'title' => $row['title'],
                    'body' => $row['body'],
                    'image' => $row['image'],
                    'mine' => (int)$row['player_id'] === $pid,
                    'betters' => array_values($bettersBySlot[$sn] ?? []),
                ];
                if (!$hide || $round['phase'] === 'settle' || (int)$row['player_id'] === $pid) {
                    $item['owner_token'] = (int)$row['token'];
                    $item['owner_name'] = $row['name'];
                }
                $board[] = $item;
            }
        }

        $tx = $this->db->prepare('SELECT type, amount, spend, meta, created_at FROM transactions WHERE session_id = ? ORDER BY id DESC LIMIT 40');
        $tx->execute([$s['id']]);

        $vouchers = $this->db->prepare('SELECT id, status, loan_limit, loaned_amount FROM vouchers WHERE player_id = ?');
        $vouchers->execute([$pid]);

        $betTotal = $round ? $this->betTotal((int)$round['id'], $pid) : 0;
        $myBets = [];
        if ($round) {
            $mb = $this->db->prepare('SELECT slot_num, amount FROM bets WHERE round_id = ? AND player_id = ? ORDER BY id');
            $mb->execute([(int)$round['id'], $pid]);
            foreach ($mb->fetchAll() as $row) {
                $myBets[] = ['slot' => (int)$row['slot_num'], 'amount' => (int)$row['amount']];
            }
        }
        $ruleCard = ($round && $round['rule_code']) ? Rules::card((string)$round['rule_code']) : null;

        $teamsOut = [];
        $tq = $this->db->prepare('SELECT * FROM teams WHERE session_id = ?');
        $tq->execute([$s['id']]);
        foreach ($tq->fetchAll() as $tm) {
            $mem = $this->db->prepare('SELECT id, name, token, spend FROM session_players WHERE team_id = ?');
            $mem->execute([$tm['id']]);
            $members = $mem->fetchAll();
            $spendSum = 0;
            foreach ($members as $m) {
                $spendSum += (int)$m['spend'];
            }
            $teamsOut[] = [
                'id' => (int)$tm['id'],
                'name' => $tm['name'],
                'status' => $tm['status'],
                'captain_id' => (int)$tm['captain_id'],
                'spend' => $spendSum,
                'members' => $members,
            ];
        }

        $iq = $this->db->prepare('SELECT i.id, i.team_id, i.status, t.name AS team_name, p.name AS captain_name FROM team_invites i JOIN teams t ON t.id = i.team_id JOIN session_players p ON p.id = t.captain_id WHERE i.player_id = ? AND i.status = "pending" AND t.status = "forming"');
        $iq->execute([$pid]);

        $owed = $this->db->prepare('SELECT d.lender_id, d.amount, p.name, p.token FROM p2p_debts d JOIN session_players p ON p.id = d.lender_id WHERE d.session_id = ? AND d.borrower_id = ? AND d.amount > 0');
        $owed->execute([$s['id'], $pid]);
        $lent = $this->db->prepare('SELECT d.borrower_id, d.amount, p.name, p.token FROM p2p_debts d JOIN session_players p ON p.id = d.borrower_id WHERE d.session_id = ? AND d.lender_id = ? AND d.amount > 0');
        $lent->execute([$s['id'], $pid]);

        return [
            'me' => [
                'id' => (int)$me['id'],
                'secret' => $me['secret'],
                'name' => $me['name'],
                'token' => $tokensReady ? (int)$me['token'] : null,
                'token_ready' => $tokensReady,
                'side' => $tokensReady ? (((int)$me['token'] % 2 === 0) ? 'white' : 'red') : null,
                'drink_tag' => $me['drink_tag'],
                'is_host' => (bool)$me['is_host'],
                'balance' => (int)$me['balance'],
                'spend' => (int)$me['spend'],
                'points_top' => (int)$me['points_top'],
                'points_volume' => (int)$me['points_volume'],
                'laid' => (bool)$me['laid'],
                'bet_done' => (bool)$me['bet_done'],
                'ready' => (bool)$me['ready'],
                'team_id' => $me['team_id'] ? (int)$me['team_id'] : null,
                'deposited' => (bool)$me['deposited'],
                'bet_total' => $betTotal,
                'bet_left' => max(0, (int)$flags['bet_total_cap'] - $betTotal),
                'my_bets' => $myBets,
                'vouchers' => $vouchers->fetchAll(),
                'invites' => $iq->fetchAll(),
                'debts_owed' => $owed->fetchAll(),
                'debts_lent' => $lent->fetchAll(),
            ],
            'session' => [
                'code' => $s['code'],
                'status' => $s['status'],
                'current_round' => (int)$s['current_round'],
                'max_rounds' => (int)$s['max_rounds'],
                'loan_limit' => (int)$s['loan_limit'],
                'pack_id' => $s['pack_id'] ?? 'ink-yard',
                'target_players' => (int)($s['target_players'] ?? 6),
                'bots_auto' => $this->botsAuto($s),
                'token_buy_rub' => (int)($this->cfg['token_buy_rub'] ?? 7000),
            ],
            'flags' => $flags,
            'rules' => $activeRules,
            'teams' => $teamsOut,
            'round' => $round ? [
                'number' => (int)$round['number'],
                'phase' => $round['phase'],
                'laying_side' => $round['laying_side'],
                'betting_side' => $round['betting_side'],
                'rule_code' => $round['rule_code'],
                'rule_title' => $ruleCard['title'] ?? null,
                'rule_body' => $ruleCard['body'] ?? null,
                'lay_ends_at' => $round['lay_ends_at'],
                'bet_ends_at' => $round['bet_ends_at'],
            ] : null,
            'situation' => $situation,
            'hand' => $hand,
            'board' => $board,
            'players' => $players,
            'ledger' => $tx->fetchAll(),
            'bots_cast' => !empty($me['is_host']) ? Bots::catalog() : null,
            'settle' => ($round && $round['phase'] === 'settle')
                ? $this->settleView((int)$round['id'], $pid)
                : null,
        ];
    }

    private function settleView(int $rid, int $pid): array
    {
        $payouts = $this->db->prepare(
            'SELECT t.amount, t.meta, p.token, p.name, p.is_bot
             FROM transactions t
             JOIN session_players p ON p.id = t.player_id
             WHERE t.round_id = ? AND t.type = "payout"
             ORDER BY t.amount DESC, p.token'
        );
        $payouts->execute([$rid]);
        $rows = [];
        foreach ($payouts->fetchAll() as $row) {
            $slot = null;
            if (preg_match('/slot\s+(\d+)/', (string)$row['meta'], $m)) {
                $slot = (int)$m[1];
            }
            $rows[] = [
                'token' => (int)$row['token'],
                'name' => $row['name'],
                'gold' => (int)$row['amount'],
                'slot' => $slot,
                'is_bot' => (bool)$row['is_bot'],
            ];
        }
        $humans = (int)$this->db->query(
            'SELECT COUNT(*) FROM session_players sp
             JOIN rounds r ON r.session_id = sp.session_id
             WHERE r.id = ' . $rid . ' AND sp.is_bot = 0'
        )->fetchColumn();
        $ackedHumans = (int)$this->db->query(
            'SELECT COUNT(*) FROM round_acks a
             JOIN session_players p ON p.id = a.player_id
             WHERE a.round_id = ' . $rid . ' AND p.is_bot = 0'
        )->fetchColumn();
        $mine = $this->db->prepare('SELECT choice FROM round_acks WHERE round_id = ? AND player_id = ?');
        $mine->execute([$rid, $pid]);
        $my = $mine->fetchColumn();
        return [
            'payouts' => $rows,
            'me_acked' => $my !== false && $my !== null,
            'me_choice' => $my ?: null,
            'acks_humans' => $ackedHumans,
            'humans' => $humans,
        ];
    }

    private function openRound(int $sid, int $num): void
    {
        $lay = $num % 2 === 1 ? 'white' : 'red';
        $bet = $lay === 'white' ? 'red' : 'white';
        $sit = (int)$this->db->query('SELECT id FROM situation_cards ORDER BY RAND() LIMIT 1')->fetchColumn();
        $rule = Rules::forRound($num);
        if ($rule) {
            $ins = $this->db->prepare('INSERT IGNORE INTO session_rules (session_id, rule_code, round_number) VALUES (?,?,?)');
            $ins->execute([$sid, $rule, $num]);
            if ($rule === 'rule_loan_limit_5') {
                $this->db->prepare('UPDATE game_sessions SET loan_limit = 5 WHERE id = ?')->execute([$sid]);
            }
        }
        $now = time();
        $this->db->prepare('INSERT INTO rounds (session_id, number, phase, laying_side, betting_side, situation_id, rule_code, lay_ends_at) VALUES (?,?, "lay", ?,?,?,?, FROM_UNIXTIME(?))')
            ->execute([$sid, $num, $lay, $bet, $sit, $rule, $now + $this->cfg['lay_seconds']]);
        $this->db->prepare('UPDATE session_players SET ready = 0, laid = 0, bet_done = 0, loans_this_round = 0, voucher_buys_this_round = 0, p2p_given_this_round = 0, card_sold_this_round = 0, bank_card_this_round = 0, voucher_sold_this_round = 0 WHERE session_id = ?')->execute([$sid]);
    }

    private function tick(array $s, array $r): void
    {
        if ($s['status'] !== 'playing') {
            return;
        }
        // Пошаговый прогон: таймеры не двигают фазу сами.
        if (!$this->botsAuto($s)) {
            return;
        }
        if ($r['phase'] === 'lay' && $r['lay_ends_at'] && strtotime((string)$r['lay_ends_at']) <= time()) {
            $this->closeLay((int)$s['id'], (int)$r['id']);
        } elseif ($r['phase'] === 'bet' && $r['bet_ends_at'] && strtotime((string)$r['bet_ends_at']) <= time()) {
            $this->settle((int)$s['id'], (int)$r['id']);
        }
    }

    private function botsAuto(array $s): bool
    {
        return !isset($s['bots_auto']) || (int)$s['bots_auto'] === 1;
    }

    /** Хост: автоботы вкл/выкл (для пошагового прогона со мной). */
    public function setBotsAuto(int $pid, string $secret, bool $on): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост');
        }
        $this->db->prepare('UPDATE game_sessions SET bots_auto = ? WHERE id = ?')
            ->execute([$on ? 1 : 0, (int)$me['session_id']]);
        return $this->stateFor($pid, $secret);
    }

    /**
     * Хост: ровно один бот делает ход (выкладка или ставка).
     * Для пошагового прогона: «следующий бот».
     */
    public function tickBots(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост');
        }
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'playing') {
            throw new RuntimeException('Игра не идёт');
        }
        $who = $this->runBots((int)$s['id'], 1, true);
        $state = $this->stateFor($pid, $secret);
        $state['bot_tick'] = $who;
        return $state;
    }

    /**
     * Хост: откат текущего тура к выкладке — карты со стенда в руки, ставки возвращены.
     * Для пошагового теста, не ломает общую схему катки.
     */
    public function rewindRound(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост');
        }
        $s = $this->session((int)$me['session_id']);
        if ($s['status'] !== 'playing') {
            throw new RuntimeException('Откат только в игре');
        }
        $r = $this->currentRound($s);
        $rid = (int)$r['id'];
        $sid = (int)$s['id'];

        $this->db->beginTransaction();
        try {
            $bets = $this->db->query('SELECT player_id, amount FROM bets WHERE round_id = ' . $rid)->fetchAll();
            foreach ($bets as $b) {
                $this->db->prepare('UPDATE session_players SET balance = balance + ?, spend = GREATEST(0, spend - ?) WHERE id = ?')
                    ->execute([(int)$b['amount'], (int)$b['amount'], (int)$b['player_id']]);
            }
            $this->db->prepare('DELETE FROM bets WHERE round_id = ?')->execute([$rid]);

            $slots = $this->db->query('SELECT card_id, player_id FROM board_slots WHERE round_id = ' . $rid)->fetchAll();
            $ins = $this->db->prepare('INSERT IGNORE INTO player_cards (player_id, card_id) VALUES (?,?)');
            foreach ($slots as $slot) {
                $ins->execute([(int)$slot['player_id'], (int)$slot['card_id']]);
            }
            $this->db->prepare('DELETE FROM board_slots WHERE round_id = ?')->execute([$rid]);

            $until = time() + 86400;
            $this->db->prepare('UPDATE rounds SET phase = "lay", bet_ends_at = NULL, lay_ends_at = FROM_UNIXTIME(?) WHERE id = ?')
                ->execute([$until, $rid]);
            $this->db->prepare('UPDATE session_players SET ready = 0, laid = 0, bet_done = 0 WHERE session_id = ?')
                ->execute([$sid]);
            $this->db->prepare('UPDATE game_sessions SET bots_auto = 0 WHERE id = ?')->execute([$sid]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    private function maybeCloseLay(int $sid, int $rid): void
    {
        $r = $this->db->query('SELECT * FROM rounds WHERE id = ' . $rid)->fetch();
        $need = $this->sidePlayers($sid, $r['laying_side']);
        $done = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid . ' AND laid = 1 AND token % 2 ' . ($r['laying_side'] === 'white' ? '= 0' : '= 1'))->fetchColumn();
        if ($done >= $need && $need > 0) {
            $this->closeLay($sid, $rid);
        }
    }

    private function closeLay(int $sid, int $rid): void
    {
        $r = $this->db->query('SELECT * FROM rounds WHERE id = ' . $rid)->fetch();
        if ($r['phase'] !== 'lay') {
            return;
        }
        $slots = $this->db->query('SELECT id FROM board_slots WHERE round_id = ' . $rid)->fetchAll(PDO::FETCH_COLUMN);
        if ($slots) {
            shuffle($slots);
            $n = 1;
            $tmp = 100;
            foreach ($slots as $id) {
                $this->db->prepare('UPDATE board_slots SET slot_num = ? WHERE id = ?')->execute([$tmp++, $id]);
            }
            foreach ($slots as $id) {
                $this->db->prepare('UPDATE board_slots SET slot_num = ? WHERE id = ?')->execute([$n++, $id]);
            }
        }
        $until = time() + $this->cfg['bet_seconds'];
        $this->db->prepare('UPDATE rounds SET phase = "bet", bet_ends_at = FROM_UNIXTIME(?) WHERE id = ?')->execute([$until, $rid]);
    }

    private function maybeCloseBet(int $sid, int $rid): void
    {
        $r = $this->db->query('SELECT * FROM rounds WHERE id = ' . $rid)->fetch();
        if ($r['phase'] !== 'bet') {
            return;
        }
        $need = $this->sidePlayers($sid, $r['betting_side']);
        $mod = $r['betting_side'] === 'white' ? '= 0' : '= 1';
        $ready = (int)$this->db->query("SELECT COUNT(*) FROM session_players WHERE session_id = $sid AND token % 2 $mod AND ready = 1")->fetchColumn();
        if ($need > 0 && $ready >= $need) {
            $this->settle($sid, $rid);
        }
    }

    private function settle(int $sid, int $rid): void
    {
        $r = $this->db->query('SELECT * FROM rounds WHERE id = ' . $rid)->fetch();
        if ($r['phase'] !== 'bet') {
            return;
        }
        $this->db->beginTransaction();
        try {
            $slots = $this->db->query('SELECT * FROM board_slots WHERE round_id = ' . $rid)->fetchAll();
            $max = 0;
            foreach ($slots as $sl) {
                $max = max($max, (int)$sl['pile']);
            }
            foreach ($slots as $sl) {
                $pile = (int)$sl['pile'];
                if ($pile > 0) {
                    $this->db->prepare('UPDATE session_players SET balance = balance + ? WHERE id = ?')->execute([$pile, $sl['player_id']]);
                    $this->tx($sid, $rid, (int)$sl['player_id'], 'payout', $pile, false, 'slot ' . $sl['slot_num']);
                }
                if ($max > 0 && $pile === $max) {
                    $this->db->prepare('UPDATE session_players SET points_top = points_top + 1 WHERE id = ?')->execute([$sl['player_id']]);
                }
            }
            $bets = $this->db->query('SELECT player_id, SUM(amount) t FROM bets WHERE round_id = ' . $rid . ' GROUP BY player_id')->fetchAll();
            foreach ($bets as $b) {
                $this->db->prepare('UPDATE session_players SET points_volume = points_volume + ? WHERE id = ?')->execute([(int)$b['t'], $b['player_id']]);
            }
            // Итоги остаются на экране, пока все живые не лайкнут/дизлайкнут.
            $this->db->prepare('UPDATE rounds SET phase = "settle" WHERE id = ?')->execute([$rid]);
            $bots = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 1')->fetchAll(PDO::FETCH_COLUMN);
            $ack = $this->db->prepare('INSERT IGNORE INTO round_acks (round_id, player_id, choice) VALUES (?,?,?)');
            foreach ($bots as $bid) {
                $ack->execute([$rid, (int)$bid, 'like']);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->maybeAdvanceAfterSettle($sid, $rid);
    }

    /** Лайк / крест на экране итогов тура. */
    public function ackSettle(int $pid, string $secret, string $choice = 'like'): array
    {
        $me = $this->auth($pid, $secret);
        $s = $this->session((int)$me['session_id']);
        $r = $this->currentRound($s);
        if ($r['phase'] !== 'settle') {
            throw new RuntimeException('Сейчас не экран итогов');
        }
        $choice = $choice === 'dislike' ? 'dislike' : 'like';
        $this->db->prepare('INSERT INTO round_acks (round_id, player_id, choice) VALUES (?,?,?) ON DUPLICATE KEY UPDATE choice = VALUES(choice)')
            ->execute([(int)$r['id'], $pid, $choice]);
        $this->maybeAdvanceAfterSettle((int)$s['id'], (int)$r['id']);
        return $this->stateFor($pid, $secret);
    }

    private function maybeAdvanceAfterSettle(int $sid, int $rid): void
    {
        $r = $this->db->query('SELECT * FROM rounds WHERE id = ' . $rid)->fetch();
        if (!$r || $r['phase'] !== 'settle') {
            return;
        }
        // Ждём только живых игроков; боты уже авто-лайкнули.
        $humans = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 0')->fetchColumn();
        $acked = (int)$this->db->query(
            'SELECT COUNT(*) FROM round_acks a
             JOIN session_players p ON p.id = a.player_id
             WHERE a.round_id = ' . $rid . ' AND p.is_bot = 0'
        )->fetchColumn();
        if ($humans > 0 && $acked < $humans) {
            return;
        }
        $s = $this->session($sid);
        $next = (int)$r['number'] + 1;
        if ($next > (int)$s['max_rounds']) {
            $this->enterRedeem($sid);
            return;
        }
        $this->db->prepare('UPDATE game_sessions SET current_round = ? WHERE id = ?')->execute([$next, $sid]);
        $this->openRound($sid, $next);
    }

    private function dealHands(int $sid): void
    {
        $s = $this->session($sid);
        $pack = $this->packId((string)($s['pack_id'] ?? 'ink-yard'));
        $st = $this->db->prepare('SELECT id FROM association_cards WHERE pack_id = ?');
        $st->execute([$pack]);
        $cards = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$cards) {
            $cards = $this->db->query('SELECT id FROM association_cards')->fetchAll(PDO::FETCH_COLUMN);
        }
        if (!$cards) {
            throw new RuntimeException('Нет карт ассоциаций');
        }
        shuffle($cards);
        $players = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN);
        $i = 0;
        $ins = $this->db->prepare('INSERT INTO player_cards (player_id, card_id) VALUES (?,?)');
        $v = $this->db->prepare('INSERT INTO vouchers (player_id, status, loan_limit, loaned_amount) VALUES (?,"blank",3,0)');
        foreach ($players as $pid) {
            $v->execute([$pid]);
            $given = [];
            $tries = 0;
            while (count($given) < $this->cfg['hand_size'] && $tries < 400) {
                $tries++;
                if (!isset($cards[$i])) {
                    $moreSt = $this->db->prepare('SELECT id FROM association_cards WHERE pack_id = ?');
                    $moreSt->execute([$pack]);
                    $more = $moreSt->fetchAll(PDO::FETCH_COLUMN);
                    if (!$more) {
                        $more = $this->db->query('SELECT id FROM association_cards')->fetchAll(PDO::FETCH_COLUMN);
                    }
                    shuffle($more);
                    $cards = array_merge($cards, $more);
                }
                $cid = (int)$cards[$i];
                $i++;
                if (isset($given[$cid])) {
                    continue;
                }
                $ins->execute([$pid, $cid]);
                $given[$cid] = true;
            }
        }
    }

    private function addPlayer(int $sid, string $name, string $drink, bool $host, bool $bot = false, ?string $botType = null): array
    {
        // В лобби — временный «посадочный» номер 1000+, боевой жетон раздаём только на старте.
        $max = (int)$this->db->query('SELECT COALESCE(MAX(token), 999) FROM session_players WHERE session_id = ' . $sid)->fetchColumn();
        $token = max(1000, $max + 1);
        $secret = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO session_players (session_id, name, drink_tag, token, secret, is_host, balance, is_bot, bot_type) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$sid, $name, $drink, $token, $secret, $host ? 1 : 0, $this->cfg['start_gold'], $bot ? 1 : 0, $botType]);
        $id = (int)$this->db->lastInsertId();
        return ['id' => $id, 'secret' => $secret];
    }

    /**
     * Рассадка: места 14..(14+N-1) подряд, игроков на места — случайно.
     * Чёт = белые, нечёт = красные → чередование по кругу.
     * Нечётное N → одна сторона на одного меньше — ок.
     * Позже: купить конкретный номер за token_buy_rub.
     */
    private function assignSeatTokens(int $sid): void
    {
        $players = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $n = count($players);
        if ($n === 0) {
            return;
        }
        $min = (int)$this->cfg['start_token'];
        $max = (int)($this->cfg['token_max'] ?? 99);
        if ($min + $n - 1 > $max) {
            throw new RuntimeException('Пул номеров слишком мал для стола');
        }
        $seats = range($min, $min + $n - 1);
        shuffle($players);

        $upd = $this->db->prepare('UPDATE session_players SET token = ? WHERE id = ?');
        foreach ($players as $i => $pid) {
            $upd->execute([2000 + $i, $pid]);
        }
        foreach ($players as $i => $pid) {
            $upd->execute([(int)$seats[$i], $pid]);
        }
    }

    /** Хост: перераспределить рассадку 14.. и подрезать руку до hand_size (пошаговый стол). */
    public function reseatTable(int $pid, string $secret): array
    {
        $me = $this->auth($pid, $secret);
        if (!$me['is_host']) {
            throw new RuntimeException('Только хост');
        }
        $sid = (int)$me['session_id'];
        $this->db->beginTransaction();
        try {
            $this->assignSeatTokens($sid);
            $handSize = (int)$this->cfg['hand_size'];
            $players = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($players as $ppid) {
                $cards = $this->db->prepare('SELECT card_id FROM player_cards WHERE player_id = ? ORDER BY card_id');
                $cards->execute([(int)$ppid]);
                $ids = $cards->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) <= $handSize) {
                    continue;
                }
                shuffle($ids);
                $drop = array_slice($ids, $handSize);
                $del = $this->db->prepare('DELETE FROM player_cards WHERE player_id = ? AND card_id = ?');
                foreach ($drop as $cid) {
                    $del->execute([(int)$ppid, (int)$cid]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->stateFor($pid, $secret);
    }

    private function sessionTarget(int $sid, int $humans): int
    {
        $s = $this->session($sid);
        $raw = isset($s['target_players']) ? (int)$s['target_players'] : 0;
        if ($raw >= 2) {
            return min((int)$this->cfg['max_players'], max($raw, $humans));
        }
        return min((int)$this->cfg['max_players'], Bots::sandboxTarget($humans));
    }

    private function trimBotsToTarget(int $sid, int $target): void
    {
        $total = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid)->fetchColumn();
        $extra = $total - $target;
        if ($extra <= 0) {
            return;
        }
        $bots = $this->db->query(
            'SELECT id FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 1 ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $del = $this->db->prepare('DELETE FROM session_players WHERE id = ? AND is_bot = 1');
        foreach ($bots as $bid) {
            if ($extra <= 0) {
                break;
            }
            $del->execute([(int)$bid]);
            $extra--;
        }
    }

    private function fillSandboxBots(int $sid): void
    {
        $humans = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 0')->fetchColumn();
        $total = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid)->fetchColumn();
        $target = $this->sessionTarget($sid, $humans);
        $this->trimBotsToTarget($sid, $target);
        $this->assignBotCast($sid);
        $total = (int)$this->db->query('SELECT COUNT(*) FROM session_players WHERE session_id = ' . $sid)->fetchColumn();
        $need = $target - $total;
        if ($need <= 0) {
            return;
        }
        $used = $this->db->query(
            'SELECT name FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $picks = Bots::pickFree($used ?: [], $need);
        foreach ($picks as $i => $ch) {
            $drink = ($i % 3 === 0) ? 'steklyshki' : 'blue';
            $this->addPlayer($sid, $ch['name'], $drink, false, true, $ch['type']);
        }
    }

    /** Старым «Бот · …» и безымянным — персонажи с карты сокровищ. */
    private function assignBotCast(int $sid): void
    {
        $bots = $this->db->query(
            'SELECT id, name, bot_type FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 1 ORDER BY id'
        )->fetchAll();
        if (!$bots) {
            return;
        }
        $kept = [];
        $need = [];
        foreach ($bots as $bot) {
            if (Bots::byName((string)$bot['name'])) {
                $kept[] = $bot['name'];
            } else {
                $need[] = $bot;
            }
        }
        if (!$need) {
            return;
        }
        $picks = Bots::pickFree($kept, count($need));
        $upd = $this->db->prepare('UPDATE session_players SET name = ?, bot_type = ? WHERE id = ? AND is_bot = 1');
        foreach ($need as $i => $bot) {
            $ch = $picks[$i] ?? null;
            if (!$ch) {
                break;
            }
            $upd->execute([$ch['name'], $ch['type'], (int)$bot['id']]);
        }
    }

    /**
     * @return array{name:?string,token:?int,action:?string}|null
     */
    private function runBots(int $sid, int $limit = 10, bool $step = false): ?array
    {
        if ($this->runningBots) {
            return null;
        }
        $this->runningBots = true;
        $last = null;
        try {
            $s = $this->session($sid);
            if ($s['status'] !== 'playing') {
                return null;
            }
            $acted = 0;
            for ($pass = 0; $pass < 2; $pass++) {
                $bots = $this->db->query('SELECT * FROM session_players WHERE session_id = ' . $sid . ' AND is_bot = 1 ORDER BY token')->fetchAll();
                foreach ($bots as $bot) {
                    if ($acted >= $limit) {
                        break 2;
                    }
                    $s = $this->session($sid);
                    if ($s['status'] !== 'playing') {
                        return $last;
                    }
                    $r = $this->currentRound($s);
                    if ($step && !$this->botCanPrimaryAct($bot, $r)) {
                        continue;
                    }
                    $before = $this->db->query('SELECT laid, bet_done, loans_this_round, voucher_buys_this_round, p2p_given_this_round, card_sold_this_round, bank_card_this_round, team_id, deposited FROM session_players WHERE id = ' . (int)$bot['id'])->fetch();
                    $action = $this->botAct($s, $r, $bot, $step);
                    $after = $this->db->query('SELECT laid, bet_done, loans_this_round, voucher_buys_this_round, p2p_given_this_round, card_sold_this_round, bank_card_this_round, team_id, deposited FROM session_players WHERE id = ' . (int)$bot['id'])->fetch();
                    if ($before && $after && $before != $after) {
                        $acted++;
                        $last = [
                            'name' => $bot['name'],
                            'token' => (int)$bot['token'],
                            'action' => $action,
                        ];
                        if ($step) {
                            break 2;
                        }
                    }
                }
            }
        } finally {
            $this->runningBots = false;
        }
        return $last;
    }

    private function botCanPrimaryAct(array $bot, array $r): bool
    {
        if ($r['phase'] === 'lay') {
            return $this->isSide($bot, $r['laying_side']) && !(int)$bot['laid'];
        }
        if ($r['phase'] === 'bet') {
            return $this->canBet($bot, $r) && !(int)$bot['bet_done'];
        }
        return false;
    }

    private function botAct(array $s, array $r, array $bot, bool $step = false): ?string
    {
        $prof = Bots::profile((string)($bot['bot_type'] ?? 'bot_shy'));
        $pid = (int)$bot['id'];
        $roundNo = (int)$s['current_round'];
        $flags = $this->flags((int)$s['id']);

        if ($r['phase'] === 'settle' || $s['status'] !== 'playing') {
            return null;
        }

        if (!$step) {
            $blanks = (int)$this->db->query('SELECT COUNT(*) FROM vouchers WHERE player_id = ' . $pid . ' AND status = "blank"')->fetchColumn();
            if ($roundNo >= 2 && (int)$bot['balance'] < 2 && (int)$bot['loans_this_round'] === 0 && $blanks > 0 && $this->chance(max($prof['loan'], $flags['teams'] ? 0.75 : 0.0))) {
                try {
                    $this->loan($pid, $bot['secret']);
                    $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                } catch (Throwable) {
                }
            }

            if ($flags['teams']) {
                $this->botTeams($s, $bot, $prof);
                $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
            }

            if ($blanks === 0 && (int)$bot['voucher_buys_this_round'] === 0 && (int)$bot['balance'] >= 3 && $this->chance($prof['voucher'] * 0.35)) {
                try {
                    $this->buyVoucher($pid, $bot['secret']);
                    $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                } catch (Throwable) {
                }
            }

            if ($flags['p2p_loan'] && (int)$bot['p2p_given_this_round'] === 0 && (int)$bot['balance'] >= 3 && $this->chance($prof['p2p'])) {
                $mate = $this->botMate((int)$s['id'], $pid, (int)($bot['team_id'] ?? 0));
                if ($mate) {
                    try {
                        $this->p2pLoan($pid, $bot['secret'], (int)$mate);
                    } catch (Throwable) {
                    }
                    $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                }
            }
            if ($flags['deposit'] && !(int)$bot['deposited'] && (int)$bot['balance'] >= 8 && $this->chance($prof['deposit'])) {
                try {
                    $this->deposit($pid, $bot['secret']);
                    $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                } catch (Throwable) {
                }
            }
            if ($flags['card_bank'] && (int)$bot['bank_card_this_round'] === 0 && (int)$bot['balance'] >= 3 && $this->chance($prof['bank_card'])) {
                try {
                    $this->buyBankCard($pid, $bot['secret']);
                    $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                } catch (Throwable) {
                }
            }
        }

        $s = $this->session((int)$s['id']);
        if ($s['status'] !== 'playing') {
            return null;
        }
        $r = $this->currentRound($s);

        if ($r['phase'] === 'lay' && $this->isSide($bot, $r['laying_side']) && !(int)$bot['laid']) {
            $cardId = null;
            if ($step || $this->chance($prof['lay'])) {
                $cardId = $this->pickHandCard($pid);
            }
            try {
                $this->lay($pid, $bot['secret'], $cardId);
                return $cardId ? 'lay' : 'pass';
            } catch (Throwable) {
            }
            return null;
        }

        if ($r['phase'] === 'bet' && $this->canBet($bot, $r) && !(int)$bot['bet_done']) {
            if ((int)$bot['balance'] >= 1 && ($step || $this->chance($prof['bet']))) {
                $slot = $this->pickBetSlot((int)$r['id'], $pid, $prof['follow_pile']);
                if ($slot !== null) {
                    $amt = 1;
                    if (!$step && $flags['bet_split'] && (int)$bot['balance'] >= 2 && $this->chance(0.45)) {
                        $amt = min(2, (int)$flags['bet_total_cap'], (int)$bot['balance']);
                    }
                    try {
                        $this->bet($pid, $bot['secret'], $slot, $amt);
                        if (!$step) {
                            $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch() ?: $bot;
                            if ($flags['bet_split'] && !(int)$bot['bet_done'] && (int)$bot['balance'] >= 1 && $this->chance(0.4)) {
                                $slot2 = $this->pickBetSlot((int)$r['id'], $pid, $prof['follow_pile']);
                                if ($slot2 !== null) {
                                    $this->bet($pid, $bot['secret'], $slot2, 1);
                                }
                            }
                        }
                        return 'bet';
                    } catch (Throwable) {
                    }
                }
            }
            try {
                $this->passBet($pid, $bot['secret']);
                return 'pass';
            } catch (Throwable) {
            }
        }
        return null;
    }

    private function botTeams(array $s, array $bot, array $prof): void
    {
        $pid = (int)$bot['id'];
        $sid = (int)$s['id'];
        $mine = $this->db->prepare('SELECT * FROM teams WHERE session_id = ? AND captain_id = ? AND status = "forming"');
        $mine->execute([$sid, $pid]);
        $forming = $mine->fetch();
        if ($forming) {
            $acc = (int)$this->db->query('SELECT COUNT(*) FROM team_invites WHERE team_id = ' . (int)$forming['id'] . ' AND status = "accepted"')->fetchColumn();
            if ($acc >= 2) {
                try {
                    $r = $this->currentRound($s);
                    $this->sealTeam($sid, (int)$r['id'], $forming);
                } catch (Throwable) {
                }
            } else {
                $free = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid . ' AND id <> ' . $pid . ' AND (team_id IS NULL OR team_id = 0) ORDER BY is_bot DESC, id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($free as $fid) {
                    try {
                        $this->inviteTeam($pid, $bot['secret'], (int)$fid);
                    } catch (Throwable) {
                    }
                }
            }
            return;
        }
        if (!$bot['team_id'] && $this->chance($prof['team_join'])) {
            $inv = $this->db->prepare('SELECT i.team_id FROM team_invites i JOIN teams t ON t.id = i.team_id WHERE i.player_id = ? AND i.status = "pending" AND t.status = "forming" LIMIT 1');
            $inv->execute([$pid]);
            $tid = $inv->fetchColumn();
            if ($tid) {
                try {
                    $this->acceptTeam($pid, $bot['secret'], (int)$tid);
                } catch (Throwable) {
                }
                return;
            }
        }
        if (!$this->chance($prof['team_create']) || (int)$bot['balance'] < 2) {
            return;
        }
        $free = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid . ' AND id <> ' . $pid . ' AND (team_id IS NULL OR team_id = 0) ORDER BY is_bot DESC, id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN);
        if (count($free) < 2) {
            return;
        }
        try {
            $this->createTeam($pid, $bot['secret'], 'Бот-звено ' . $bot['token']);
            $bot = $this->db->query('SELECT * FROM session_players WHERE id = ' . $pid)->fetch();
            $tid = (int)($bot['team_id'] ?? 0);
            if ($tid) {
                $this->inviteTeam($pid, $bot['secret'], (int)$free[0]);
                $this->inviteTeam($pid, $bot['secret'], (int)$free[1]);
            }
        } catch (Throwable) {
        }
    }

    private function botMate(int $sid, int $pid, int $teamId): ?int
    {
        if ($teamId) {
            $q = $this->db->prepare('SELECT id FROM session_players WHERE session_id = ? AND team_id = ? AND id <> ? ORDER BY RAND() LIMIT 1');
            $q->execute([$sid, $teamId, $pid]);
            $id = $q->fetchColumn();
            if ($id) {
                return (int)$id;
            }
        }
        $q = $this->db->prepare('SELECT id FROM session_players WHERE session_id = ? AND id <> ? ORDER BY RAND() LIMIT 1');
        $q->execute([$sid, $pid]);
        $id = $q->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function pickHandCard(int $pid): ?int
    {
        $q = $this->db->prepare('SELECT card_id FROM player_cards WHERE player_id = ? ORDER BY RAND() LIMIT 1');
        $q->execute([$pid]);
        $id = $q->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function pickBetSlot(int $rid, int $pid, float $followPile): ?int
    {
        $slots = $this->db->prepare('SELECT slot_num, pile, player_id FROM board_slots WHERE round_id = ? AND player_id <> ?');
        $slots->execute([$rid, $pid]);
        $slots = $slots->fetchAll();
        if (!$slots) {
            return null;
        }
        $max = 0;
        foreach ($slots as $s) {
            $max = max($max, (int)$s['pile']);
        }
        // Пока куча «холодная» (0–1) — почти случайный разброс; догон только когда уже видно лидера.
        $follow = $max >= 2 ? $followPile : ($followPile * 0.2);
        if ($this->chance($follow) && $max > 0) {
            $tops = array_values(array_filter($slots, static fn($s) => (int)$s['pile'] === $max));
            return (int)$tops[random_int(0, count($tops) - 1)]['slot_num'];
        }
        return (int)$slots[random_int(0, count($slots) - 1)]['slot_num'];
    }

    private function chance(float $p): bool
    {
        return (random_int(1, 1000) / 1000) <= $p;
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $q = $this->db->prepare('SELECT 1 FROM game_sessions WHERE code = ?');
            $q->execute([$code]);
        } while ($q->fetch());
        return $code;
    }

    private function auth(int $pid, string $secret): array
    {
        $q = $this->db->prepare('SELECT * FROM session_players WHERE id = ? AND secret = ?');
        $q->execute([$pid, $secret]);
        $row = $q->fetch();
        if (!$row) {
            throw new RuntimeException('Сессия игрока недействительна');
        }
        return $row;
    }

    private function session(int $id): array
    {
        $q = $this->db->prepare('SELECT * FROM game_sessions WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: throw new RuntimeException('Нет катки');
    }

    private function sessionByCode(string $code): ?array
    {
        $q = $this->db->prepare('SELECT * FROM game_sessions WHERE code = ?');
        $q->execute([strtoupper(trim($code))]);
        return $q->fetch() ?: null;
    }

    private function currentRound(array $s): array
    {
        $q = $this->db->prepare('SELECT * FROM rounds WHERE session_id = ? AND number = ?');
        $q->execute([$s['id'], $s['current_round']]);
        return $q->fetch() ?: throw new RuntimeException('Нет текущего тура');
    }

    private function isSide(array $me, string $side): bool
    {
        $white = ((int)$me['token'] % 2 === 0);
        return $side === 'white' ? $white : !$white;
    }

    private function canBet(array $me, array $r): bool
    {
        return $this->isSide($me, $r['betting_side']);
    }

    private function sidePlayers(int $sid, string $side): int
    {
        $mod = $side === 'white' ? '= 0' : '= 1';
        return (int)$this->db->query("SELECT COUNT(*) FROM session_players WHERE session_id = $sid AND token % 2 $mod")->fetchColumn();
    }

    private function flags(int $sid): array
    {
        $s = $this->session($sid);
        $codes = $this->db->prepare('SELECT rule_code FROM session_rules WHERE session_id = ?');
        $codes->execute([$sid]);
        return Rules::flagsFromCodes($codes->fetchAll(PDO::FETCH_COLUMN), (int)$s['loan_limit']);
    }

    private function activeRules(int $sid): array
    {
        $q = $this->db->prepare('SELECT rule_code, round_number FROM session_rules WHERE session_id = ? ORDER BY round_number');
        $q->execute([$sid]);
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $card = Rules::card($row['rule_code']);
            $out[] = [
                'id' => $row['rule_code'],
                'from_round' => (int)$row['round_number'],
                'title' => $card['title'] ?? $row['rule_code'],
                'body' => $card['body'] ?? '',
            ];
        }
        return $out;
    }

    private function betTotal(int $rid, int $pid): int
    {
        $q = $this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM bets WHERE round_id = ? AND player_id = ?');
        $q->execute([$rid, $pid]);
        return (int)$q->fetchColumn();
    }

    private function betOnSlot(int $rid, int $pid, int $slot): int
    {
        $q = $this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM bets WHERE round_id = ? AND player_id = ? AND slot_num = ?');
        $q->execute([$rid, $pid, $slot]);
        return (int)$q->fetchColumn();
    }

    private function thisPlayingRound(array $s): array
    {
        if ($s['status'] !== 'playing') {
            throw new RuntimeException('Недоступно');
        }
        $r = $this->currentRound($s);
        if ($r['phase'] === 'settle') {
            throw new RuntimeException('Сделки закрыты');
        }
        return $r;
    }

    private function enterRedeem(int $sid): void
    {
        $s = $this->session($sid);
        if ($s['status'] === 'redeem' || $s['status'] === 'finished') {
            return;
        }
        $out = (int)$this->cfg['deposit_out'];
        $ps = $this->db->query('SELECT id FROM session_players WHERE session_id = ' . $sid . ' AND deposited = 1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ps as $id) {
            $this->db->prepare('UPDATE session_players SET balance = balance + ?, deposited = 2 WHERE id = ?')->execute([$out, $id]);
            $this->tx($sid, null, (int)$id, 'deposit_payout', $out, false, 'вклад 5→6');
        }
        $this->db->prepare('UPDATE game_sessions SET status = "redeem" WHERE id = ?')->execute([$sid]);
    }

    private function sealTeam(int $sid, int $rid, array $t): void
    {
        $capFee = (int)$this->cfg['team_captain_fee'];
        $memFee = (int)$this->cfg['team_member_fee'];
        $captain = $this->db->query('SELECT * FROM session_players WHERE id = ' . (int)$t['captain_id'])->fetch();
        $members = $this->db->query('SELECT sp.* FROM session_players sp JOIN team_invites i ON i.player_id = sp.id WHERE i.team_id = ' . (int)$t['id'] . ' AND i.status = "accepted" LIMIT 2')->fetchAll();
        if (!$captain || count($members) < 2) {
            return;
        }
        if ((int)$captain['balance'] < $capFee) {
            throw new RuntimeException('Капитану нужны 2 монеты для сбора');
        }
        foreach ($members as $m) {
            if ((int)$m['balance'] < $memFee) {
                throw new RuntimeException($m['name'] . ': нужна 1 монета взноса');
            }
        }
        $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ? WHERE id = ?')->execute([$capFee, $capFee, $captain['id']]);
        $this->tx($sid, $rid, (int)$captain['id'], 'team_fee', $capFee, true, 'капитан');
        foreach ($members as $m) {
            $this->db->prepare('UPDATE session_players SET balance = balance - ?, spend = spend + ? WHERE id = ?')->execute([$memFee, $memFee, $m['id']]);
            $this->tx($sid, $rid, (int)$m['id'], 'team_fee', $memFee, true, 'участник');
        }
        $this->db->prepare('UPDATE team_invites SET status = "expired" WHERE team_id = ? AND status = "pending"')->execute([$t['id']]);
        $this->db->prepare('UPDATE teams SET status = "active" WHERE id = ?')->execute([$t['id']]);
    }

    private function randomCardNotInHand(int $pid): int
    {
        $me = $this->db->prepare('SELECT session_id FROM session_players WHERE id = ?');
        $me->execute([$pid]);
        $sid = (int)$me->fetchColumn();
        $s = $this->session($sid);
        $pack = $this->packId((string)($s['pack_id'] ?? 'ink-yard'));
        $q = $this->db->prepare('SELECT id FROM association_cards WHERE pack_id = ? AND id NOT IN (SELECT card_id FROM player_cards WHERE player_id = ?) ORDER BY RAND() LIMIT 1');
        $q->execute([$pack, $pid]);
        $id = $q->fetchColumn();
        if (!$id) {
            $q = $this->db->prepare('SELECT id FROM association_cards WHERE id NOT IN (SELECT card_id FROM player_cards WHERE player_id = ?) ORDER BY RAND() LIMIT 1');
            $q->execute([$pid]);
            $id = $q->fetchColumn();
        }
        if (!$id) {
            throw new RuntimeException('В банке не осталось новых карт');
        }
        return (int)$id;
    }

    private function packId(string $pack): string
    {
        $allowed = ['ink-yard', 'blue-dossier', 'balcony-tales'];
        return in_array($pack, $allowed, true) ? $pack : 'ink-yard';
    }

    private function tx(int $sid, ?int $rid, ?int $pid, string $type, int $amount, bool $spend, ?string $meta): void
    {
        $this->db->prepare('INSERT INTO transactions (session_id, round_id, player_id, type, amount, spend, meta) VALUES (?,?,?,?,?,?,?)')
            ->execute([$sid, $rid, $pid, $type, $amount, $spend ? 1 : 0, $meta]);
    }

    private function cleanName(string $n): string
    {
        $n = trim($n);
        if ($n === '' || mb_strlen($n) > 80) {
            throw new RuntimeException('Имя 1–80 символов');
        }
        return $n;
    }

    public function setDrink(int $pid, string $secret, string $drink): array
    {
        $me = $this->auth($pid, $secret);
        $tag = $this->drink($drink);
        $this->db->prepare('UPDATE session_players SET drink_tag = ? WHERE id = ?')->execute([$tag, $pid]);
        return $this->stateFor($pid, $secret);
    }

    private function drink(string $d): string
    {
        return $d === 'blue' ? 'blue' : 'steklyshki';
    }
}
