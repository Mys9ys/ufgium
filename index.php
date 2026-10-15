<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>UFGium — Департамент странных синих дел</title>
  <link rel="icon" href="/assets/favicon.png" type="image/png" sizes="32x32">
  <link rel="stylesheet" href="/assets/app.css?v=table34">
  <script>
    try { if (localStorage.getItem('ufgium-theme') === 'light') document.documentElement.classList.add('light'); } catch (e) {}
  </script>
</head>
<body>
  <div class="stage">
  <header>
    <button type="button" class="theme" id="theme" aria-label="Светлая или тёмная тема">
      <span class="sun" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg></span>
      <span class="moon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M15 3a9 9 0 1 0 6 14A7 7 0 0 1 15 3z"/></svg></span>
      <i class="knob"></i>
    </button>
    <h1>UFGIUM</h1>
    <p>Департамент странных синих дел · урок финансовой грамотности</p>
  </header>
  <div class="wrap">
    <div id="err" class="err"></div>

    <section id="gate" class="card">
      <!-- Хост: создать стол -->
      <div id="gate-host">
        <div class="row">
          <div>
            <label>Имя</label>
            <input id="name" placeholder="Как звать за столом">
          </div>
          <div>
            <label>Колода</label>
            <select id="pack">
              <option value="ink-yard">Чернильный Ярд</option>
              <option value="blue-dossier">Синее досье</option>
              <option value="balcony-tales">Балконные сказки</option>
            </select>
          </div>
        </div>
        <div class="row" style="margin-top:12px">
          <button id="create">Создать катку</button>
        </div>
        <div class="invite-box" style="margin-top:14px">
          <label>Уже есть код?</label>
          <div class="row">
            <input id="join-code-host" placeholder="Код комнаты" maxlength="6" style="text-transform:uppercase" autocomplete="off">
            <button class="ghost" id="goto-join-btn" type="button">К входу</button>
            <button class="ghost" id="scan-qr-btn" type="button">Сканировать QR</button>
          </div>
          <p class="muted" style="margin-top:8px">Хост создаёт стол и кидает QR/ссылку. Гости заходят без экрана «создать».</p>
        </div>
      </div>

      <!-- Гость: только присоединение -->
      <div id="gate-guest" class="hide">
        <p class="muted" style="margin:0 0 6px">Тебя пригласили за стол</p>
        <div class="code" id="guest-code-show">————</div>
        <p class="muted" id="guest-hint" style="margin-top:8px">Введи, как звать сегодня, и жми «За стол».</p>
        <div style="margin-top:12px">
          <label>Как звать сегодня</label>
          <input id="name-guest" placeholder="Ник на этот вечер" autocomplete="nickname">
        </div>
        <input type="hidden" id="join-code" value="">
        <div class="row" style="margin-top:12px">
          <button id="join">За стол</button>
          <button class="ghost" id="scan-qr-guest-btn" type="button">Сканировать другой QR</button>
          <button class="ghost" id="goto-host-btn" type="button">Я хост — создать свою</button>
        </div>
      </div>
    </section>

    <section id="game" class="hide">
      <div class="card">
        <div id="invite-block">
          <div class="invite-head">
            <div>
              <div class="muted" style="margin-bottom:4px">Код комнаты</div>
              <div class="code" id="code"></div>
            </div>
            <div class="row invite-actions" id="invite-actions">
              <button class="ghost" id="copy-code-btn" type="button">Копировать код</button>
              <button class="ghost" id="share-invite-btn" type="button">Отправить ссылку</button>
              <button id="show-qr-btn" type="button">QR-код</button>
            </div>
          </div>
          <div class="muted" id="invite-url" style="margin-top:8px;word-break:break-all"></div>
        </div>
        <div class="muted" id="meta"></div>
        <div id="table-size-box" class="table-size-box hide" style="margin-top:12px">
          <div class="muted" style="margin-bottom:6px">Сколько за столом (добить ботами)</div>
          <div class="row" id="table-size-presets">
            <button type="button" class="ghost size-preset" data-size="6">6</button>
            <button type="button" class="ghost size-preset" data-size="12">12</button>
            <button type="button" class="ghost size-preset" data-size="24">24</button>
            <input id="table-size-custom" type="number" min="2" max="24" placeholder="своё" style="width:88px">
            <button type="button" class="ghost" id="table-size-apply">Ок</button>
          </div>
          <div class="muted" id="table-size-hint" style="margin-top:6px"></div>
        </div>
        <div class="bar-line">
          <div id="who"></div>
          <div class="kit" id="kit">
            <span id="bar-coins"></span>
            <span id="bar-blank" class="blank hide" title="Бланк векселя"></span>
          </div>
          <div class="kit marks" id="marks">
            <span id="side-dot" class="side-dot"></span>
            <button type="button" class="drink-toggle steklyshki" id="drink-toggle" title="Стеклышки / синие" aria-label="Рюмка">
              <svg class="shot" viewBox="0 0 32 32" aria-hidden="true">
                <path class="bowl" d="M6.2 5.2h19.6L23.2 16.4c-.5 2.7-3.1 4.5-7.2 4.5s-6.7-1.8-7.2-4.5L6.2 5.2z"/>
                <path class="pour" d="M9.2 10.2h13.6l-1.2 5.1c-.35 1.7-2.2 2.9-5.6 2.9s-5.25-1.2-5.6-2.9l-1.2-5.1z"/>
                <rect class="stem" x="14.5" y="20.6" width="3" height="3.8" rx=".3"/>
                <ellipse class="foot" cx="16" cy="25.6" rx="6.4" ry="1.6"/>
              </svg>
              <span class="drink-label" id="drink-label">Стеклышки</span>
            </button>
            <span class="team-plus hide" id="team-plus" hidden aria-hidden="true">+</span>
          </div>
          <div class="row" id="host-actions">
            <button id="start-btn">Начать игру</button>
            <button class="ghost" id="bots-btn">Добить ботами</button>
            <button class="ghost hide" id="bots-tick-btn" type="button" title="Ровно один бот: выкладка или ставка">След. бот</button>
            <button class="danger" id="end-btn" title="Закончить катку"><span class="long">Закончить катку</span><span class="short">Конец</span></button>
          </div>
          <button class="ghost" id="leave" title="Выйти с этого устройства" aria-label="Выйти">
            <span class="long">Выйти с этого устройства</span>
            <svg class="short door" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M7 3.5h8.2A1.6 1.6 0 0 1 16.8 5.1V20.5H7V3.5z"/>
              <circle cx="14.2" cy="12" r="1" fill="currentColor"/>
            </svg>
          </button>
        </div>
        <div id="lobby-box" class="lobby-box hide" hidden></div>
      </div>

      <div class="grid">
        <div>
          <div class="card hide" id="actions-card">
            <div class="muted" id="bet-cap"></div>
            <div class="row bet-actions" style="margin-top:8px">
              <button class="ghost hide" id="bet-pass" type="button">Пас</button>
              <button class="ghost hide" id="bet-cancel" type="button">Снять ставку</button>
              <button class="hide" id="ready-btn" type="button">Готов</button>
            </div>
          </div>
          <div class="card table-card">
            <div class="table-head" id="table-head">
              <div class="stand-row">
                <span class="round-bubble" id="round-bubble">Стол</span>
                <div class="side-frame hide" id="side-frame"></div>
                <button type="button" class="rules-seal" id="rules-open" aria-label="Правила игры">
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                    <g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M12 2.8v15.4"/>
                      <path d="M8 20.8h8"/>
                      <path d="M4.2 7.2h15.6"/>
                      <path d="M4.2 7.2v1.6c0 2.1 1.3 3.5 3 3.5s3-1.4 3-3.5V7.2"/>
                      <path d="M13.8 7.2v1.6c0 2.1 1.3 3.5 3 3.5s3-1.4 3-3.5V7.2"/>
                    </g>
                  </svg>
                </button>
              </div>
              <p class="situation-bubble hide" id="situation-bubble"><span id="situation"></span></p>
            </div>
            <div class="lights" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
            <div class="seat-plate" id="seat-plate" aria-hidden="true">
              <div class="seat-plate-top">
                <span class="seat-plate-num" id="seat-plate-num"></span>
                <span class="seat-plate-name" id="seat-plate-name"></span>
              </div>
              <hr class="seat-plate-rule">
              <div class="seat-plate-bio" id="seat-plate-bio"></div>
              <div class="seat-plate-gold" id="seat-plate-gold"></div>
            </div>
            <div class="felt-wrap">
              <div id="seat-ring" class="seat-ring"></div>
              <div class="felt-core">
                <div class="table-view">
                  <div class="felt-table">
                  <div id="empty-table" class="empty-table table-scroll" hidden>
                    <div class="empty-spread">
                      <div class="slot-ghost" aria-hidden="true"></div>
                      <div class="slot-ghost" aria-hidden="true"></div>
                      <div class="slot-ghost" aria-hidden="true"></div>
                      <div class="slot-ghost" aria-hidden="true"></div>
                    </div>
                  </div>
                  <div id="board" class="hand table-scroll" hidden></div>
                  <div id="lay-pile" class="lay-pile" hidden aria-hidden="true">
                    <img class="lay lay-1" src="/assets/card-back.jpg" alt="">
                    <img class="lay lay-2" src="/assets/card-back.jpg" alt="">
                    <img class="lay lay-3" src="/assets/card-back.jpg" alt="">
                    <img class="lay lay-4" src="/assets/card-back.jpg" alt="">
                    <b class="lay-count">24</b>
                  </div>
                  <div id="settle-overlay" class="settle-overlay hide" hidden aria-hidden="true">
                    <div class="settle-card">
                      <strong class="settle-title" id="settle-title">Итог тура</strong>
                      <div class="settle-list" id="settle-list"></div>
                      <p class="settle-hint muted" id="settle-hint"></p>
                      <div class="settle-actions" id="settle-actions">
                        <button type="button" class="settle-ack like" id="settle-like" aria-label="Нравится">👍</button>
                        <button type="button" class="settle-ack dislike" id="settle-dislike" aria-label="Не нравится">✕</button>
                      </div>
                    </div>
                  </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="card tray">
            <div class="tray-label">
              <span>Мои карты</span>
              <span class="tray-head-right">
                <span class="hand-count-pill" id="hand-count" hidden></span>
                <button type="button" class="tray-pass hide" id="lay-pass">Пас</button>
              </span>
            </div>
            <div class="tray-cue hide" id="tray-cue"></div>
            <div id="hand" class="hand"></div>
          </div>
        </div>
        <div>
          <div class="card" id="econ">
            <strong>Банк и сделки</strong>
            <div class="row" style="margin-top:8px">
              <button class="ghost" id="loan-btn">Займ по векселю</button>
              <button class="ghost" id="voucher-btn">Купить бланк за 1</button>
              <button class="ghost" id="voucher-sell-btn">Продать бланк за 1</button>
              <button class="ghost hide" id="card-bank-btn">Карта из банка за 1</button>
              <button class="ghost hide" id="deposit-btn">Вклад 5 → 6</button>
              <button class="ghost" id="redeem-btn">Выкупить векселя</button>
            </div>
            <div id="deals" class="hide" style="margin-top:10px">
              <label>Игрок для сделки</label>
              <select id="deal-player"></select>
              <div class="row" style="margin-top:8px">
                <button class="ghost hide" id="p2p-btn">Дать 1 взаймы</button>
                <button class="ghost hide" id="sell-card-btn">Продать выбранную карту за 1</button>
              </div>
              <div class="muted" id="sell-hint"></div>
            </div>
            <div id="team-box" class="hide" style="margin-top:10px">
              <strong>Команда</strong>
              <div class="row" style="margin-top:8px">
                <input id="team-name" placeholder="Имя команды" style="min-width:140px">
                <button class="ghost" id="team-create-btn">Создать (капитан)</button>
              </div>
              <div class="row" style="margin-top:8px">
                <button class="ghost" id="team-invite-btn">Пригласить выбранного</button>
              </div>
              <div id="invites"></div>
              <div id="teams-list" class="muted"></div>
            </div>
            <div id="debts" class="muted" style="margin-top:8px"></div>
          </div>
          <div class="card">
            <strong>Рейтинг</strong>
            <table>
              <thead><tr><th>№</th><th>Игрок</th><th>Команда</th><th>Зол.</th><th>Траты</th><th>Баллы</th></tr></thead>
              <tbody id="players"></tbody>
            </table>
          </div>
          <div class="card">
            <strong>Гроссбух</strong>
            <div id="ledger"></div>
          </div>
        </div>
      </div>
    </section>
  </div>
  </div>

  <div id="card-modal" class="modal hide" role="dialog" aria-modal="true" aria-label="Карта">
    <div class="modal-card card-zoom">
      <button type="button" class="card-zoom-close" id="card-zoom-close" aria-label="Закрыть"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>
      <div class="card-zoom-frame" id="card-zoom-frame">
        <img id="card-zoom-img" alt="">
      </div>
    </div>
  </div>

  <div id="card-info-modal" class="modal hide" role="dialog" aria-modal="true" aria-labelledby="card-info-title">
    <div class="modal-card card-info-sheet">
      <div class="row" style="justify-content:space-between;align-items:center;gap:8px">
        <strong id="card-info-title"></strong>
        <button type="button" class="ghost" id="card-info-close">Закрыть</button>
      </div>
      <p class="card-info-body" id="card-info-body"></p>
    </div>
  </div>

  <div id="bet-confirm-modal" class="modal hide" role="dialog" aria-modal="true" aria-labelledby="bet-confirm-title">
    <div class="modal-card bet-confirm-sheet">
      <strong id="bet-confirm-title">Ставка</strong>
      <p class="muted" id="bet-confirm-text" style="margin:10px 0 14px"></p>
      <div class="row" style="justify-content:flex-end;gap:8px">
        <button type="button" class="ghost" id="bet-confirm-no">Отмена</button>
        <button type="button" id="bet-confirm-yes">Поставить</button>
      </div>
    </div>
  </div>

  <div id="rules-modal" class="modal hide" role="dialog" aria-modal="true" aria-labelledby="rules-modal-title">
    <div class="modal-card rules-sheet">
      <div class="row" style="justify-content:space-between;align-items:center">
        <strong id="rules-modal-title">Правила</strong>
        <button class="ghost" id="rules-close" type="button">Закрыть</button>
      </div>
      <div class="rules-tabs" id="rules-tabs" role="tablist">
        <button type="button" class="rules-tab" role="tab" id="tab-rules-new" data-rules-tab="new" aria-controls="pane-rules-new">Новые</button>
        <button type="button" class="rules-tab" role="tab" id="tab-rules-base" data-rules-tab="base" aria-controls="pane-rules-base">Стандартные</button>
      </div>
      <div id="pane-rules-new" class="rules-pane hide" role="tabpanel" aria-labelledby="tab-rules-new">
        <p class="muted" id="rules-new-hint" style="margin:0 0 8px">Карточки, которые добавились за эту катку. Новое сильнее старого.</p>
        <div id="rules-cards"></div>
      </div>
      <div id="pane-rules-base" class="rules-pane hide" role="tabpanel" aria-labelledby="tab-rules-base">
        <p class="muted" style="margin:0 0 8px">База стола. Действует всю катку, пока карточка не отменит пункт.</p>
        <ol class="rules-base">
          <li>Рассадка: места с 14 подряд, игроков на места — случайно. Чётный — белый, нечётный — красный.</li>
          <li>Нечётный тур: белые кладут карту, красные ставят. Чётный тур — наоборот.</li>
          <li>На стенде ситуация. Под неё кладут карту из руки. Кто не успел — карта остаётся.</li>
          <li>Ставка — одна монета на чужую карту. На свою нельзя. Кто выложил карту, в этот тур не ставит.</li>
          <li>Монеты на карте забирает её владелец.</li>
          <li>На старте 1 золотой, вексель и 6 карт. Занять у банка можно со 2-го тура, не больше лимита.</li>
          <li>Сделки пишутся в гроссбух. Дарить монеты и карты нельзя.</li>
        </ol>
      </div>
    </div>
  </div>

  <div id="invite-modal" class="modal hide" role="dialog" aria-modal="true" aria-labelledby="invite-modal-title">
    <div class="modal-card">
      <div class="row" style="justify-content:space-between;align-items:center">
        <strong id="invite-modal-title">Приглашение</strong>
        <button class="ghost" id="invite-modal-close" type="button">Закрыть</button>
      </div>
      <div id="qr-show-pane" class="hide" style="margin-top:12px;text-align:center">
        <canvas id="qr-canvas" width="240" height="240"></canvas>
        <p class="muted" id="qr-code-label" style="margin-top:8px"></p>
        <p class="muted">Наведи камеру телефона на QR — откроется вход в комнату.</p>
        <p class="muted" id="qr-host-hint"></p>
      </div>
      <div id="qr-scan-pane" class="hide" style="margin-top:12px">
        <video id="qr-video" playsinline muted></video>
        <canvas id="qr-scan-canvas" class="hide"></canvas>
        <p class="muted" style="margin-top:8px">Наведи на QR хоста. Или загрузи фото QR:</p>
        <input type="file" id="qr-file" accept="image/*" capture="environment">
        <p class="err" id="qr-scan-err" style="margin-top:6px"></p>
      </div>
    </div>
  </div>

  <script src="/assets/vendor/qrcode.min.js"></script>
  <script src="/assets/vendor/jsQR.js"></script>
  <script src="/assets/app.js?v=table34"></script>
</body>
</html>
