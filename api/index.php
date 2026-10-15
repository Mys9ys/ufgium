<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Game.php';

header('Content-Type: application/json; charset=utf-8');

$path = trim((string)($_GET['r'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode((string)file_get_contents('php://input'), true) ?? [];

function json_out(mixed $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_pair(array $in): array
{
    $pid = (int)($in['player_id'] ?? 0);
    $secret = (string)($in['secret'] ?? '');
    if ($pid <= 0 || $secret === '') {
        json_out(['error' => 'Нет сессии игрока'], 401);
    }
    return [$pid, $secret];
}

try {
    $g = new Game();
    if ($method === 'POST' && $path === 'session/create') {
        json_out($g->create(
            (string)($input['name'] ?? ''),
            (string)($input['drink_tag'] ?? 'steklyshki'),
            (string)($input['pack_id'] ?? 'ink-yard')
        ));
    }
    if ($method === 'POST' && $path === 'session/join') {
        json_out($g->join(
            (string)($input['code'] ?? ''),
            (string)($input['name'] ?? ''),
            (string)($input['drink_tag'] ?? 'steklyshki')
        ));
    }
    if ($method === 'POST' && $path === 'player/drink') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->setDrink($pid, $secret, (string)($input['drink_tag'] ?? 'steklyshki')));
    }
    if ($method === 'POST' && $path === 'session/bots') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->fillBots($pid, $secret));
    }
    if ($method === 'POST' && $path === 'session/table-size') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->setTableSize($pid, $secret, (int)($input['size'] ?? 6)));
    }
    if ($method === 'POST' && $path === 'session/bots-auto') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->setBotsAuto($pid, $secret, !empty($input['on'])));
    }
    if ($method === 'POST' && $path === 'session/bots-tick') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->tickBots($pid, $secret));
    }
    if ($method === 'POST' && $path === 'session/rewind') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->rewindRound($pid, $secret));
    }
    if ($method === 'POST' && $path === 'session/reseat') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->reseatTable($pid, $secret));
    }
    if ($method === 'POST' && $path === 'session/start') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->start($pid, $secret));
    }
    if ($method === 'POST' && $path === 'session/end') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->hostEnd($pid, $secret));
    }
    if ($method === 'POST' && $path === 'round/lay') {
        [$pid, $secret] = auth_pair($input);
        $card = array_key_exists('card_id', $input) && $input['card_id'] !== null && $input['card_id'] !== ''
            ? (int)$input['card_id']
            : null;
        json_out($g->lay($pid, $secret, $card));
    }
    if ($method === 'POST' && $path === 'round/bet') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->bet($pid, $secret, (int)($input['slot'] ?? 0), (int)($input['amount'] ?? 1)));
    }
    if ($method === 'POST' && $path === 'round/bet-cancel') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->cancelBet($pid, $secret));
    }
    if ($method === 'POST' && $path === 'round/pass') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->passBet($pid, $secret));
    }
    if ($method === 'POST' && $path === 'round/ready') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->ready($pid, $secret));
    }
    if ($method === 'POST' && $path === 'round/settle-ack') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->ackSettle($pid, $secret, (string)($input['choice'] ?? 'like')));
    }
    if ($method === 'POST' && $path === 'wallet/loan') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->loan($pid, $secret));
    }
    if ($method === 'POST' && $path === 'wallet/voucher') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->buyVoucher($pid, $secret));
    }
    if ($method === 'POST' && $path === 'wallet/redeem') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->redeem($pid, $secret));
    }
    if ($method === 'POST' && $path === 'wallet/voucher-sell') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->sellVoucher($pid, $secret));
    }
    if ($method === 'POST' && $path === 'wallet/card-bank') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->buyBankCard($pid, $secret));
    }
    if ($method === 'POST' && $path === 'wallet/deposit') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->deposit($pid, $secret));
    }
    if ($method === 'POST' && $path === 'deal/card') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->sellCard($pid, $secret, (int)($input['to_player_id'] ?? 0), (int)($input['card_id'] ?? 0)));
    }
    if ($method === 'POST' && $path === 'wallet/p2p') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->p2pLoan($pid, $secret, (int)($input['to_player_id'] ?? 0)));
    }
    if ($method === 'POST' && $path === 'wallet/p2p-repay') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->p2pRepay($pid, $secret, (int)($input['lender_id'] ?? 0)));
    }
    if ($method === 'POST' && $path === 'team/create') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->createTeam($pid, $secret, (string)($input['name'] ?? 'Команда')));
    }
    if ($method === 'POST' && $path === 'team/invite') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->inviteTeam($pid, $secret, (int)($input['to_player_id'] ?? 0)));
    }
    if ($method === 'POST' && $path === 'team/accept') {
        [$pid, $secret] = auth_pair($input);
        json_out($g->acceptTeam($pid, $secret, (int)($input['team_id'] ?? 0)));
    }
    if ($method === 'GET' && $path === 'state') {
        $pid = (int)($_GET['player_id'] ?? 0);
        $secret = (string)($_GET['secret'] ?? '');
        json_out($g->stateFor($pid, $secret));
    }
    json_out(['error' => 'Неизвестный запрос'], 404);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
