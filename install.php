<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Db.php';

header('Content-Type: text/plain; charset=utf-8');

$cfg = require __DIR__ . '/app/config.php';
$root = Db::pdoRoot();
$name = $cfg['db']['name'];
$root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->exec("USE `$name`");
$sql = file_get_contents(__DIR__ . '/sql/schema.sql');
$root->exec($sql);

$sit = $root->query('SELECT COUNT(*) FROM situation_cards')->fetchColumn();
if ((int)$sit === 0) {
    $situations = [
        'Когда ударился мизинцем о ножку кровати',
        'Когда на большой тусе выходишь на балкон и начинаешь разговаривать с малознакомым чуваком',
        'Когда пятница 13-е, а ты уже в ресторане',
        'Когда ЦБ поднимает ключевую ставку, а ты поднимаешь бокал',
        'Когда коллега говорит «это на 5 минут»',
        'Когда пароль от Wi-Fi — дата рождения директора',
        'Когда в чат кидают «надо созвониться» без темы',
        'Когда кот сел в кейс с реквизитом и не отдаёт',
        'Когда вексель обещал вернуть «после зарплаты»',
        'Когда все делают вид, что поняли ситуацию',
        'Когда твоя карта на стенде, а друзья делают вид, что не знают',
        'Когда золотых больше, чем здравого смысла',
        'Когда синие смотрят на стеклышек с жалостью и завистью одновременно',
        'Когда хост сказал «последний тур» уже третий раз',
        'Когда номер 14 кажется элитой, а 13 так и не выдали',
        'Когда ассоциация очевидна только тебе',
        'Когда в гроссбух не записали, и сделка внезапно не существует',
        'Когда команда из трёх собирается за 4 секунды до конца таймера',
        'Когда банк выдаёт монеты с лицом человека, который знает про 100 рублей',
        'Когда департамент странных синих дел открывает сверхурочную смену',
        'Когда в чат кинули голосовуху на 4 минуты, а ты в метро без наушников',
        'Когда все кладут карту «метро», потому что так написано на стикере',
        'Когда белые идут по часовой, а красные уже забыли протокол',
        'Когда рейтинг трат важнее победы в ассоциации',
        'Когда староверы ещё читают ситуацию, а махинаторы уже заняли у банка',
        'Когда QR на салфетке размазался соусом',
        'Когда удалённый игрок комментирует стол громче тех, кто за ним сидит',
        'Когда гомункул на бирке смотрит прямо в душу',
        'Когда «урок финансовой грамотности» закончился минусом в профиле',
        'Когда закрытие катки совпало с заседанием ЦБ',
    ];
    $ins = $root->prepare('INSERT INTO situation_cards (body) VALUES (?)');
    foreach ($situations as $t) {
        $ins->execute([$t]);
    }
}

$colPack = $root->query("SHOW COLUMNS FROM association_cards LIKE 'pack_id'")->fetch();
if (!$colPack) {
    $root->exec('ALTER TABLE association_cards ADD COLUMN pack_id VARCHAR(32) NULL AFTER body');
    $root->exec('ALTER TABLE association_cards ADD COLUMN art_id CHAR(2) NULL AFTER pack_id');
    $root->exec('ALTER TABLE association_cards ADD COLUMN image_path VARCHAR(255) NULL AFTER art_id');
    $root->exec('ALTER TABLE association_cards ADD KEY idx_pack (pack_id)');
    echo "migrated association_cards pack columns\n";
}
$colSessPack = $root->query("SHOW COLUMNS FROM game_sessions LIKE 'pack_id'")->fetch();
if (!$colSessPack) {
    $root->exec("ALTER TABLE game_sessions ADD COLUMN pack_id VARCHAR(32) NOT NULL DEFAULT 'ink-yard' AFTER loan_limit");
    echo "migrated game_sessions.pack_id\n";
}

$packCount = (int)$root->query("SELECT COUNT(*) FROM association_cards WHERE pack_id IS NOT NULL")->fetchColumn();
if ($packCount < 288) {
    $packsPath = __DIR__ . '/data/packs.json';
    $packsJson = json_decode((string)file_get_contents($packsPath), true);
    if (!empty($packsJson['packs'])) {
        $root->exec('SET FOREIGN_KEY_CHECKS=0');
        $root->exec('DELETE FROM bets');
        $root->exec('DELETE FROM board_slots');
        $root->exec('DELETE FROM player_cards');
        $root->exec('DELETE FROM association_cards');
        $root->exec('SET FOREIGN_KEY_CHECKS=1');
        $ins = $root->prepare('INSERT INTO association_cards (title, body, pack_id, art_id, image_path) VALUES (?,?,?,?,?)');
        $n = 0;
        foreach ($packsJson['packs'] as $pack) {
            $pid = (string)$pack['id'];
            foreach ($pack['cards'] as $c) {
                $art = (string)$c['id'];
                $img = "/assets/packs/{$pid}/{$art}.jpg";
                $body = (string)($c['scene'] ?? $c['title']);
                $ins->execute([(string)$c['title'], $body, $pid, $art, $img]);
                $n++;
            }
        }
        echo "seeded pack cards: $n\n";
    }
}

$ac = $root->query('SELECT COUNT(*) FROM association_cards')->fetchColumn();
if ((int)$ac === 0) {
    $cards = [
        ['Метро', 'Подземка, турникеты, объявление «осторожно, двери закрываются»'],
        ['Кит на шариках', 'Огромный кит в ночном небе, луна, верёвки с воздушными шарами'],
        ['Департамент', 'Щит, синее свечение, люди вокруг лампы'],
    ];
    $ins = $root->prepare('INSERT INTO association_cards (title, body) VALUES (?,?)');
    foreach ($cards as $c) {
        $ins->execute($c);
    }
}

echo "OK database `$name`\n";
$col = $root->query("SHOW COLUMNS FROM session_players LIKE 'is_bot'")->fetch();
if (!$col) {
    $root->exec('ALTER TABLE session_players ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER voucher_buys_this_round');
    $root->exec('ALTER TABLE session_players ADD COLUMN bot_type VARCHAR(32) NULL AFTER is_bot');
    echo "migrated is_bot / bot_type\n";
}
$migrateCols = [
    'p2p_given_this_round' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER voucher_buys_this_round',
    'card_sold_this_round' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER p2p_given_this_round',
    'bank_card_this_round' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER card_sold_this_round',
    'voucher_sold_this_round' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER bank_card_this_round',
    'team_id' => 'INT UNSIGNED NULL AFTER voucher_sold_this_round',
    'deposited' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER team_id',
];
foreach ($migrateCols as $colName => $ddl) {
    $exists = $root->query("SHOW COLUMNS FROM session_players LIKE " . $root->quote($colName))->fetch();
    if (!$exists) {
        $root->exec("ALTER TABLE session_players ADD COLUMN `$colName` $ddl");
        echo "migrated $colName\n";
    }
}
$extraSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS session_rules (
  session_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(64) NOT NULL,
  round_number TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (session_id, rule_code),
  CONSTRAINT fk_sr_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  captain_id INT UNSIGNED NOT NULL,
  status ENUM('forming','active') NOT NULL DEFAULT 'forming',
  created_round TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_team_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS team_invites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
  UNIQUE KEY uq_invite (team_id, player_id),
  CONSTRAINT fk_ti_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS p2p_debts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  lender_id INT UNSIGNED NOT NULL,
  borrower_id INT UNSIGNED NOT NULL,
  amount INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_debt (session_id, lender_id, borrower_id),
  CONSTRAINT fk_pd_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS round_acks (
  round_id INT UNSIGNED NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  choice ENUM('like','dislike') NOT NULL DEFAULT 'like',
  PRIMARY KEY (round_id, player_id),
  CONSTRAINT fk_ra_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$root->exec($extraSql);
echo 'situations: ' . $root->query('SELECT COUNT(*) FROM situation_cards')->fetchColumn() . "\n";
echo 'cards: ' . $root->query('SELECT COUNT(*) FROM association_cards')->fetchColumn() . "\n";
