const params = new URLSearchParams(window.location.search);
const gameId = params.get("gameId");
const playerId = params.get("playerId");

if (!gameId || !playerId) {
  window.location.href = "./index.html";
}

const el = {
  gameName: document.getElementById("game-name"),
  gameId: document.getElementById("game-id"),
  round: document.getElementById("round"),
  phase: document.getElementById("phase"),
  pot: document.getElementById("pot"),
  turn: document.getElementById("turn"),
  lastRoll: document.getElementById("last-roll"),
  players: document.getElementById("players-list"),
  startBtn: document.getElementById("start-btn"),
  rollBtn: document.getElementById("roll-btn"),
  track: document.getElementById("track"),
  you: document.getElementById("you-panel"),
  logs: document.getElementById("logs"),
  fx: document.getElementById("fx-layer"),
};

const money = (cents) => `$${(Number(cents || 0) / 100).toFixed(2)}`;
const horseLabel = (n) => (Number(n) === 11 ? "J (11)" : Number(n) === 12 ? "Q (12)" : String(n));
let loading = false;
let previousGame = null;
let activeFxToast = null;

async function api(path, method = "GET", payload = null) {
  const init = { method, headers: {} };
  if (payload) {
    init.headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(payload);
  }
  const resp = await fetch(path, init);
  const data = await resp.json();
  if (!resp.ok) throw new Error(data.error || "Request failed");
  return data;
}

function startPercent() {
  return 22;
}

function finishPercent() {
  return 98;
}

function positionPercent(horseState) {
  const start = startPercent();
  const finish = finishPercent();
  const forwardWidth = finish - start;
  const p = Number(horseState.position);
  if (p >= 0) {
    const ratio = horseState.steps > 0 ? Math.min(1, p / horseState.steps) : 0;
    return start + forwardWidth * ratio;
  }
  const scratchSpacing = (start - 2) / 4;
  const clipped = Math.max(-4, p);
  return start + clipped * scratchSpacing;
}

function holePositions(steps) {
  const start = startPercent();
  const finish = finishPercent();
  const forwardWidth = finish - start;
  const points = [];
  for (let i = 1; i <= 4; i++) {
    points.push({ percent: start - ((start - 2) / 4) * i, scratch: true });
  }
  for (let i = 1; i <= steps; i++) {
    points.push({ percent: start + (forwardWidth * i) / steps, scratch: false });
  }
  return points;
}

function toast(text, bad = false) {
  if (activeFxToast) {
    activeFxToast.remove();
  }
  const t = document.createElement("div");
  t.className = `fx-toast${bad ? " bad" : ""}`;
  t.textContent = text;
  el.fx.appendChild(t);
  activeFxToast = t;
  setTimeout(() => {
    if (activeFxToast === t) {
      activeFxToast = null;
    }
    t.remove();
  }, 1300);
}

function render(game) {
  el.gameName.textContent = game.name;
  el.gameId.textContent = game.id;
  el.round.textContent = game.round;
  el.phase.textContent = game.phase;
  el.pot.textContent = money(game.potCents);
  el.turn.textContent = game.turnPlayerName || "-";
  el.lastRoll.textContent = game.lastRoll ? `${game.lastRoll.playerName}: ${horseLabel(game.lastRoll.value)}` : "-";

  const prevById = new Map((previousGame?.players || []).map((p) => [p.id, p]));

  el.players.innerHTML = "";
  game.players.forEach((p) => {
    const chip = document.createElement("div");
    chip.className = "player-chip";
    if (p.id === game.turnPlayerId) chip.classList.add("turn");
    if (p.eliminated) chip.classList.add("eliminated");
    const prevPlayer = prevById.get(p.id);
    if (prevPlayer && p.balanceCents < prevPlayer.balanceCents) {
      chip.classList.add("payer");
    }
    chip.textContent = `${p.name} ${p.isNpc ? "(NPC)" : ""} | ${money(p.balanceCents)} | ${p.handCount} cards${p.eliminated ? " | OUT" : ""}`;
    el.players.appendChild(chip);
  });

  const viewer = game.viewer;
  if (!viewer) {
    el.you.textContent = "Viewer not found in this game.";
  } else if (viewer.eliminated) {
    el.you.innerHTML = `<div class="eliminated-banner">Eliminated!</div>`;
  } else {
    const cards = [...viewer.hand].sort((a, b) => a - b);
    const cardHtml = cards.map((c) => `<span class="card-pill">${horseLabel(c)}</span>`).join("");
    el.you.innerHTML = `
      <div><strong>Name:</strong> ${viewer.name}</div>
      <div><strong>Balance:</strong> ${money(viewer.balanceCents)}</div>
      <div><strong>Cards:</strong></div>
      <div class="cards">${cardHtml || "<em>No cards</em>"}</div>
    `;
  }

  el.rollBtn.disabled = !(viewer && viewer.canRoll);

  const canStart = game.status === "waiting";
  el.startBtn.style.display = canStart ? "inline-block" : "none";

  el.track.innerHTML = "";
  Object.keys(game.horses)
    .map(Number)
    .sort((a, b) => a - b)
    .forEach((horse) => {
      const row = document.createElement("div");
      row.className = "track-row";

      const h = game.horses[horse];
      const leftPct = positionPercent(h);

      const scratchTag = h.scratchedOrder ? `<span class="scratch-tag">SCR${h.scratchedOrder}</span>` : "";
      const holeHtml = holePositions(h.steps)
        .map((pt) => `<span class="hole ${pt.scratch ? "scratch" : ""}" style="left:${pt.percent}%"></span>`)
        .join("");
      const rolled = game.lastRoll && Number(game.lastRoll.value) === horse ? "rolled" : "";

      row.innerHTML = `
        <div class="track-name">${horseLabel(horse)}</div>
        <div class="lane" style="--start-line:${startPercent()}%;">
          ${holeHtml}
          <span class="token ${h.scratchedOrder ? "scratched" : ""} ${rolled}" style="left:calc(${leftPct}% - 12px)"></span>
        </div>
        <div>${scratchTag}</div>
      `;
      el.track.appendChild(row);
    });

  el.logs.textContent = (game.logs || []).join("\n");
  el.logs.scrollTop = el.logs.scrollHeight;

  if (previousGame) {
    if (game.potCents > previousGame.potCents) {
      el.pot.classList.remove("pot-pop");
      void el.pot.offsetWidth;
      el.pot.classList.add("pot-pop");
      toast(`Pot +${money(game.potCents - previousGame.potCents)}`);
    }

    const prevRoll = previousGame.lastRoll ? `${previousGame.lastRoll.playerId}|${previousGame.lastRoll.value}|${previousGame.lastRoll.phase}` : "";
    const newRoll = game.lastRoll ? `${game.lastRoll.playerId}|${game.lastRoll.value}|${game.lastRoll.phase}` : "";
    if (newRoll && newRoll !== prevRoll && game.lastRoll) {
      const scratched = Boolean(game.horses[game.lastRoll.value]?.scratchedOrder);
      const rollText = `${game.lastRoll.playerName} rolled ${horseLabel(game.lastRoll.value)}`;
      toast(rollText, scratched);
    }
  }

  previousGame = JSON.parse(JSON.stringify(game));
}

async function refresh() {
  if (loading) return;
  loading = true;
  try {
    const data = await api(`./api/games/${encodeURIComponent(gameId)}?playerId=${encodeURIComponent(playerId)}`);
    render(data.game);
  } catch (err) {
    el.logs.textContent = err.message;
  } finally {
    loading = false;
  }
}

el.rollBtn.addEventListener("click", async () => {
  try {
    await api(`./api/games/${encodeURIComponent(gameId)}/roll`, "POST", { playerId });
    await refresh();
  } catch (err) {
    alert(err.message);
  }
});

el.startBtn.addEventListener("click", async () => {
  try {
    await api(`./api/games/${encodeURIComponent(gameId)}/start`, "POST", { playerId });
    await refresh();
  } catch (err) {
    alert(err.message);
  }
});

refresh();
setInterval(refresh, 2000);
