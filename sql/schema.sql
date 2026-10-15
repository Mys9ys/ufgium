-- UFGium MVP schema (MariaDB)

CREATE TABLE IF NOT EXISTS situation_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  body VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS association_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(400) NOT NULL,
  pack_id VARCHAR(32) NULL,
  art_id CHAR(2) NULL,
  image_path VARCHAR(255) NULL,
  KEY idx_pack (pack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL UNIQUE,
  status ENUM('lobby','playing','redeem','finished') NOT NULL DEFAULT 'lobby',
  current_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_rounds TINYINT UNSIGNED NOT NULL DEFAULT 10,
  loan_limit TINYINT UNSIGNED NOT NULL DEFAULT 3,
  pack_id VARCHAR(32) NOT NULL DEFAULT 'ink-yard',
  target_players TINYINT UNSIGNED NOT NULL DEFAULT 6,
  bots_auto TINYINT(1) NOT NULL DEFAULT 1,
  host_player_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS session_players (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  drink_tag ENUM('blue','steklyshki') NOT NULL,
  token SMALLINT UNSIGNED NOT NULL,
  secret CHAR(32) NOT NULL,
  is_host TINYINT(1) NOT NULL DEFAULT 0,
  balance INT NOT NULL DEFAULT 1,
  spend INT NOT NULL DEFAULT 0,
  points_top INT NOT NULL DEFAULT 0,
  points_volume INT NOT NULL DEFAULT 0,
  ready TINYINT(1) NOT NULL DEFAULT 0,
  laid TINYINT(1) NOT NULL DEFAULT 0,
  bet_done TINYINT(1) NOT NULL DEFAULT 0,
  loans_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  voucher_buys_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  p2p_given_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  card_sold_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bank_card_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  voucher_sold_this_round TINYINT UNSIGNED NOT NULL DEFAULT 0,
  team_id INT UNSIGNED NULL,
  deposited TINYINT(1) NOT NULL DEFAULT 0,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  bot_type VARCHAR(32) NULL,
  UNIQUE KEY uq_session_token (session_id, token),
  CONSTRAINT fk_sp_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_cards (
  player_id INT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (player_id, card_id),
  CONSTRAINT fk_pc_player FOREIGN KEY (player_id) REFERENCES session_players(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_card FOREIGN KEY (card_id) REFERENCES association_cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_id INT UNSIGNED NOT NULL,
  status ENUM('blank','used','redeemed','leftover') NOT NULL DEFAULT 'blank',
  loan_limit TINYINT UNSIGNED NOT NULL DEFAULT 3,
  loaned_amount INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_v_player FOREIGN KEY (player_id) REFERENCES session_players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  number TINYINT UNSIGNED NOT NULL,
  phase ENUM('rule','situation','lay','bet','settle') NOT NULL DEFAULT 'situation',
  laying_side ENUM('white','red') NOT NULL,
  betting_side ENUM('white','red') NOT NULL,
  situation_id INT UNSIGNED NULL,
  rule_code VARCHAR(64) NULL,
  lay_ends_at DATETIME NULL,
  bet_ends_at DATETIME NULL,
  UNIQUE KEY uq_round (session_id, number),
  CONSTRAINT fk_r_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS board_slots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  round_id INT UNSIGNED NOT NULL,
  slot_num TINYINT UNSIGNED NOT NULL,
  card_id INT UNSIGNED NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  pile INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slot (round_id, slot_num),
  CONSTRAINT fk_bs_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  round_id INT UNSIGNED NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  slot_num TINYINT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  CONSTRAINT fk_b_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NULL,
  player_id INT UNSIGNED NULL,
  type VARCHAR(32) NOT NULL,
  amount INT NOT NULL DEFAULT 0,
  spend TINYINT(1) NOT NULL DEFAULT 0,
  meta VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sess (session_id),
  CONSTRAINT fk_t_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
