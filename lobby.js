const statusEl = document.getElementById("status");
const createForm = document.getElementById("create-form");
const joinForm = document.getElementById("join-form");

const setStatus = (text) => {
  statusEl.textContent = text || "";
};

const goToGame = (gameId, playerId) => {
  const url = new URL("./game.html", window.location.href);
  url.searchParams.set("gameId", gameId);
  url.searchParams.set("playerId", playerId);
  window.location.href = url.toString();
};

createForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setStatus("");
  const formData = new FormData(createForm);
  const payload = Object.fromEntries(formData.entries());

  try {
    const resp = await fetch("./api/games", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await resp.json();
    if (!resp.ok) {
      throw new Error(data.error || "Unable to create game");
    }
    goToGame(data.gameId, data.playerId);
  } catch (err) {
    setStatus(err.message);
  }
});

joinForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setStatus("");
  const formData = new FormData(joinForm);
  const gameId = String(formData.get("gameId") || "").trim();
  const payload = { playerName: formData.get("playerName") };

  try {
    const resp = await fetch(`./api/games/${encodeURIComponent(gameId)}/join`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await resp.json();
    if (!resp.ok) {
      throw new Error(data.error || "Unable to join game");
    }
    goToGame(data.gameId, data.playerId);
  } catch (err) {
    setStatus(err.message);
  }
});
